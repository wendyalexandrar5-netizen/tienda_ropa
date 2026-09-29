# Análisis del proyecto original y propuesta de migración

> El proyecto original se conserva **sin modificaciones** en el historial de git,
> commit `d7a94b3` ("Importa el proyecto original (proyectofinal) sin
> modificaciones"). Para verlo: `git show d7a94b3 --stat` o
> `git checkout d7a94b3 -- proyectofinal`.

## 1. Inventario de archivos

| Archivo original | Función | Estado encontrado |
|---|---|---|
| `login.html` | Pantalla de bienvenida (logo FIRE CAT, fondo animado de fotos, botones Ingresar / Registrarse) | Funcional visualmente. HTML mal cerrado (`<div class="fondo-animado">` sin cerrar). |
| `ingresar.html` | Formulario de login | **No funcionaba**: `const email = email.value` lanza `ReferenceError` (variable usada antes de declararse). Leía `/usuarios/<email>/data.json` y comparaba la contraseña **en el navegador**. |
| `registrar.html` | Formulario de registro | **No funcionaba**: mismo `ReferenceError`. Enviaba la contraseña en texto plano a `/crearUsuario.php`, **archivo que no existe** en el proyecto. |
| `principal.html` | Menú del "panel" (Usuarios, Productos, Carrito, Ventas, Caja) | Nombre de usuario fijo "Juan Pérez" por defecto; `usuarios.html` **no existe** (enlace roto); sin control de acceso. |
| `producto.html` | Listado de productos | Lee `localStorage`. Imprime datos con `innerHTML` (XSS). |
| `agregar.html` | Alta de producto | El usuario escribe el ID manualmente; imagen guardada como **base64 en localStorage** (límite ~5 MB total); acepta cualquier archivo "image/*". |
| `editar.html` / `eliminar.html` | Edición / eliminación | Operan sobre `localStorage`; cantidades y precios se guardan como texto; sin validación. |
| `carrito.html` | Punto de venta (vendedor): elegir producto, cantidad, pago recibido y cambio | Descuenta stock en el navegador; precio tomado del propio navegador; `Principal.html` con mayúscula rompe el enlace en servidores Linux. |
| `ventas.html` | Historial de ventas + PDF (html2pdf) | Solo local; `innerHTML` sin escapar. |
| `caja.html` | Ganancia por día | Agrupa por `toLocaleString()` (fecha+hora → casi nunca agrupa por día). |
| `estilo/1.css`, `estilo/2.css` | Estilos de formularios (Uiverse.io) | `1.css` no se usa. |
| `IR/1..20.jpg` | 20 fotografías de prendas | **Reutilizadas** como catálogo inicial. |
| `imagenes/firecatW.jpg`, `firecatY.jpg` | Logo FIRE CAT (blanco / amarillo) | **Reutilizados** como identidad de marca (logo, favicon, paleta). |

## 2. Arquitectura actual (antes)

```
Navegador ──► HTML estático + JS inline ──► localStorage del navegador
                         └──► fetch('/usuarios/<email>/data.json')  (inexistente)
                         └──► fetch('/crearUsuario.php')            (inexistente)
```

* **Sin backend, sin API, sin base de datos.** Todo vive en el `localStorage`
  de un solo navegador: si se borra la caché o se abre en otro equipo, no hay datos.
* No hay MySQL/MariaDB: **no existía estructura de BD que migrar**. La
  "estructura" implícita en `localStorage` era:

```js
productos: [{ id, imagen /* base64 */, cantidad, talla, color, precio }]   // strings
ventas:    [{ productos: [...], total, pago, cambio, fecha /* toLocaleString */ }]
nombre, apellido                                                         // strings sueltos
usuarios/<email>/data.json: { nombre, apellido, correo, password /* texto plano */ }
```

## 3. Funcionalidades existentes

| Funcionalidad | ¿Funcionaba? | Dónde quedó ahora |
|---|---|---|
| Bienvenida con fondo animado de fotos | Sí | Hero de la página de inicio y panel lateral de login/registro/recuperación (`views/shop/home.php`, `views/partials/auth-visual.php`) |
| Registro / login | **No** | Supabase Auth (`/registro`, `/login`, `/api/auth/*`) |
| CRUD de productos | Parcial (local) | Panel `/admin/productos` + `/api/admin/products` |
| Carrito de vendedor con pago y cambio | Parcial (local) | **Caja / Venta en tienda** `/admin/caja` + `admin_registrar_venta_pos()` (atómico, precio del servidor) |
| Historial de ventas + PDF | Parcial (local) | `/admin/pedidos` + comprobante imprimible / "Guardar como PDF" |
| Caja (ganancia por día) | Con errores | Reportes: ventas por día/semana/mes separando **tienda física vs online** |
| Usuarios | **No existía** (enlace roto) | `/admin/clientes` (roles y bloqueo) |

## 4. Problemas identificados

**Seguridad**
1. Contraseñas en texto plano en archivos JSON públicos y comparadas en el navegador.
2. Sin autenticación real: cualquiera podía abrir `principal.html` o `agregar.html`.
3. XSS almacenado: datos del usuario insertados con `innerHTML` en 6 páginas.
4. Precios, stock y totales controlados por el navegador (manipulables desde DevTools).
5. Subida de "imágenes" sin validar tipo real ni tamaño.

**Funcionalidad**
6. `ReferenceError` en login y registro (nunca funcionaron).
7. Endpoint `/crearUsuario.php` y página `usuarios.html` inexistentes.
8. Enlaces a `Principal.html` (mayúscula) rotos en Linux.
9. Datos solo en un navegador; sin concurrencia (dos vendedores = stock inconsistente).
10. Sin tallas/colores como variantes: cada combinación era un "producto" distinto con ID manual.
11. Ventas sin precio histórico estructurado ni estados.

**Estructura / diseño**
12. CSS y JS duplicados inline en cada página; sin layout común ni responsive.
13. Aspecto de formulario básico; sin tienda para clientes (el proyecto era solo un POS).

## 5. Dependencias originales

* `html2pdf.js 0.10.1` (CDN) – reemplazado por una vista de comprobante
  imprimible (`window.print()` → "Guardar como PDF"), sin dependencias.
* Fuentes del sistema (Arial). Estilos de Uiverse.io.

## 6. Propuesta de migración (ejecutada)

1. **Conservar la identidad**: logo, paleta amarillo/negro, fotos y el fondo
   animado de fotografías pasan al nuevo diseño.
2. **Normalizar el modelo**: `producto` (nombre, descripción, precio, categoría,
   estado) → `variantes_producto` (talla × color con stock y SKU) →
   `imagenes_producto` (Storage). Las 20 fotos y sus tallas/colores se cargan
   en `supabase/seed.sql` (no había datos reales que migrar: todo estaba en
   el `localStorage` de un navegador).
3. **Autenticación** con Supabase Auth; perfiles en `profiles` sin contraseñas.
4. **Backend PHP** con API REST y las reglas de negocio en PostgreSQL
   (funciones transaccionales + RLS).
5. **Funcionalidad de vendedor (carrito/caja/ventas)** → módulo **Caja** del
   panel, ahora atómico y con cálculo de cambio en el servidor.
6. **Nueva tienda para clientes**: catálogo, detalle, carrito, checkout, cuenta.
7. **Pruebas** automatizadas de RLS, seguridad y E2E.

## 7. Correspondencia de archivos

| Antes | Después |
|---|---|
| `login.html` | `views/shop/home.php` (hero) + `views/partials/auth-visual.php` |
| `ingresar.html` | `views/auth/login.php` + `src/Controllers/Web/AuthController.php` |
| `registrar.html` | `views/auth/register.php` + `AuthService::register()` |
| `principal.html` | `views/layouts/admin.php` (menú lateral) + `views/admin/dashboard.php` |
| `producto.html` / `agregar.html` / `editar.html` / `eliminar.html` | `views/admin/products.php`, `views/admin/product-form.php`, `AdminCatalogService` |
| `carrito.html` | `views/admin/pos.php` + `public.admin_registrar_venta_pos()` |
| `ventas.html` | `views/admin/orders.php`, `views/admin/order.php`, `views/account/receipt.php` |
| `caja.html` | `views/admin/reports.php` + `public.admin_reporte_ventas()` |
| `estilo/*.css` | `public/assets/css/app.css`, `public/assets/css/admin.css` |
| `IR/*.jpg` | `public/assets/img/productos/*.jpg` |
| `imagenes/*.jpg` | `public/assets/img/brand/*` + `public/favicon.png` |
