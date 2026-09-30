# Auditoría del proyecto original (`proyectofinal/`)

Fecha de la auditoría: septiembre de 2026. El proyecto original se conserva sin cambios
en el historial de Git (commit *"Importa el proyecto original (proyectofinal) sin modificaciones"*).

## 1. Qué había

| Archivo | Función | Estado |
|---|---|---|
| `login.html` | Pantalla de bienvenida con botones *Ingresar* / *Registrarse* y fondo animado de fotos | Funcionaba, pero tenía un `<div>` sin cerrar |
| `ingresar.html` | Formulario de login | **No funcionaba**: `const email = email.value` lanza `ReferenceError` (zona muerta temporal) y consultaba `/usuarios/{id}/data.json`, que no existe |
| `registrar.html` | Formulario de registro | **No funcionaba**: mismo error de variables; llamaba a `/crearUsuario.php`, que no existe |
| `principal.html` | Menú principal (Usuarios, Productos, Carrito, Ventas, Caja) | Mostraba siempre "Juan Pérez"; enlazaba `usuarios.html`, que no existe |
| `producto.html` | Lista de productos (desde `localStorage`) | Funcionaba sólo en el navegador local |
| `agregar.html` | Alta de producto (ID manual, imagen, cantidad, talla, color, precio) | Funcionaba en `localStorage`; imagen en base64 |
| `editar.html` / `eliminar.html` | Edición / borrado de producto por ID | Funcionaban en `localStorage` |
| `carrito.html` | Venta tipo POS: elegir producto, cantidad, pago recibido, cambio | Funcionaba; descontaba stock |
| `ventas.html` | Historial de ventas + PDF (html2pdf por CDN) | "Volver" iba al carrito; `new Date(fechaLocalizada)` → *Invalid Date* |
| `caja.html` | Total ganado por día | Agrupaba por fecha **y hora** (`toLocaleString()`), así que cada venta era un "día" |
| `estilo/1.css`, `estilo/2.css` | Estilos de formularios (uiverse.io) | `1.css` no se usaba; `2.css` casi duplicado |
| `IR/1-20.jpg`, `imagenes/firecat*.jpg` | Fotos de producto y logos de la marca FIRE CAT | Reutilizables |

**No existía:** PHP, base de datos, sesiones, roles, categorías, variantes (cada "producto" era una sola talla/color),
nombre o descripción del producto, pedidos de clientes, perfil, panel separado.

## 2. Problemas encontrados

### Seguridad
1. Contraseñas en **texto plano** dentro de archivos JSON públicos y comparadas en el navegador.
2. **Enumeración de usuarios**: "Usuario no existe" vs. "Contraseña incorrecta".
3. **XSS**: todo se pintaba con `innerHTML` usando datos escritos por el usuario (talla, color…).
4. Ningún control de acceso: cualquiera podía abrir `agregar.html`/`eliminar.html`; no había sesión ni roles.
5. Precios y stock modificables desde las DevTools (todo vivía en `localStorage`).
6. Sin CSRF, sin validación del lado servidor (no había servidor), ID de producto elegido por el usuario.
7. Dependencia de un CDN externo (html2pdf) sin integridad (SRI).

### Lógica
- Cantidades y precios guardados como texto (`"10"`), sumas que podían concatenar cadenas.
- Rutas con mayúsculas (`Principal.html`) que fallan en servidores Linux.
- Stock negativo posible al editar manualmente; nada impedía vender dos veces la última unidad desde dos pestañas.
- Imágenes en base64 dentro de `localStorage` (límite ≈ 5 MB → la app deja de guardar).

### Diseño / organización
- Sin `<meta name="viewport">`: **no era responsive**. `body{height:100vh; overflow:hidden}` cortaba los formularios en móvil.
- CSS repetido dentro de cada HTML, JavaScript mezclado con el marcado, estilos inconsistentes entre páginas.

## 3. Decisiones

| Se conservó | Cómo |
|---|---|
| Marca FIRE CAT (logos, amarillo + negro) | Paleta del nuevo diseño y logotipos derivados en `assets/img/brand/` |
| Las 20 fotos | `assets/img/productos/` (movidas con `git mv`), asignadas a 20 productos reales |
| Fondo animado de fotos del login/registro | `includes/partials/auth_inicio.php` + CSS (respeta `prefers-reduced-motion`) |
| CRUD de productos | `admin/productos.php`, `producto_crear.php`, `producto_editar.php` (con variantes) |
| Validar stock y descontarlo al vender | `mover_stock()` + transacción de `crear_pedido()` |
| Pago recibido y cambio (POS) | Campo *"¿Con cuánto pagarás?"* en el checkout contra entrega; el cambio se muestra al cliente y al repartidor |
| Historial de ventas | `admin/pedidos.php` + `admin/pedido_detalle.php` |
| Descargar venta en PDF | Comprobante con estilos de impresión (**Imprimir / PDF**) sin depender de CDN |
| Resumen de caja por día | `admin/reportes.php` → *Resumen de caja por día* (agrupando por fecha real) + exportación a Excel (.xlsx) |

### Correspondencia de archivos (eliminados → reemplazo)

| Original | Nuevo |
|---|---|
| `login.html` | `index.php` (portada) y `login.php` |
| `ingresar.html` | `login.php` / `admin/login.php` |
| `registrar.html` | `registro.php` |
| `principal.html` | `admin/index.php` (dashboard) y navegación de la tienda |
| `producto.html` | `admin/productos.php` (gestión) y `productos.php` (catálogo) |
| `agregar.html` | `admin/producto_crear.php` |
| `editar.html` | `admin/producto_editar.php` |
| `eliminar.html` | Acciones *Desactivar* / *Eliminar* en `admin/productos.php` |
| `carrito.html` | `carrito.php` + `checkout.php` |
| `ventas.html` | `admin/pedidos.php`, `admin/pedido_detalle.php`, `mis_pedidos.php` |
| `caja.html` | `admin/reportes.php` |
| `estilo/1.css`, `estilo/2.css` | `assets/css/tienda.css`, `assets/css/admin.css` |

Los HTML y CSS originales se eliminaron porque **toda** su funcionalidad quedó migrada y mejorada; mantenerlos
habría dejado páginas rotas (login/registro no funcionaban) y un CRUD sin control de acceso publicado en el servidor.
Siguen disponibles en el historial de Git.
