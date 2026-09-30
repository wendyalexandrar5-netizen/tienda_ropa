# FIRE CAT · Tienda online, fabricación y finanzas

Sistema para una marca de ropa urbana que **fabrica y vende** sus propias prendas: tienda online (catálogo, variantes
por talla y color, carrito, checkout transaccional, historial de pedidos) y un panel administrativo independiente
con inventario, **materias primas, proveedores, compras, fichas técnicas con costo de fabricación, órdenes de
producción, gastos, utilidad, rentabilidad y flujo de dinero**. Proyecto final de
Ingeniería de Sistemas, construido sobre el proyecto original `proyectofinal/` (ver
[docs/AUDITORIA.md](docs/AUDITORIA.md)).

## 1. Tecnologías

| Capa | Tecnología |
|---|---|
| Servidor | Apache 2.4 (XAMPP) con `mod_rewrite` y `.htaccess` |
| Backend | PHP 8.0+ sin framework, estructura modular (`includes/`, `admin/`, `config/`) |
| Base de datos | MariaDB 10.4+ (la de XAMPP) o MySQL 8.0+, con PDO y consultas preparadas |
| Frontend | HTML5, CSS3, JavaScript sin dependencias, Bootstrap 5.3, Bootstrap Icons |
| Gráficos | Chart.js 4 (panel administrativo) |

Todas las librerías están **dentro del proyecto** (`assets/vendor/`): funciona sin internet.

## 2. Requisitos

- XAMPP 8.x (Apache + PHP 8.0 o superior + MariaDB) — Windows, macOS o Linux.
- Extensiones de PHP incluidas en XAMPP: `pdo_mysql`, `mbstring`, `fileinfo` y `gd` (para re-codificar imágenes).
  Si `gd` o `fileinfo` están comentadas en `php.ini` (`;extension=gd`), quite el `;` y reinicie Apache.

## 3. Instalación en XAMPP

1. **Copiar el proyecto** en la carpeta pública de Apache:
   - Windows: `C:\xampp\htdocs\tienda_ropa`
   - macOS: `/Applications/XAMPP/htdocs/tienda_ropa`
   - Linux: `/opt/lampp/htdocs/tienda_ropa`
2. **Iniciar Apache y MySQL** desde el *XAMPP Control Panel*.
3. **Configuración de Apache:** XAMPP ya trae `AllowOverride All` y `mod_rewrite` activos, que el proyecto necesita
   para sus `.htaccess` (bloqueo de carpetas internas y de ejecución de PHP en `uploads/`). Si su Apache no los tiene:
   en `httpd.conf` descomente `LoadModule rewrite_module …` y use `AllowOverride All` en el bloque `<Directory "…/htdocs">`.
4. **Crear la base de datos con phpMyAdmin:** abra <http://localhost/phpmyadmin> → pestaña **Importar** →
   *Seleccionar archivo* → `database.sql` → **Continuar**. El script crea la base `tienda_ropa`, las 27 tablas, la vista,
   las relaciones y los datos de prueba (no hace falta crear la base antes: el script ejecuta `CREATE DATABASE`).
   > ⚠️ El script **borra** la base `tienda_ropa` si ya existe; úselo también para restaurar la demo.
5. **Configurar la conexión** (sólo si su MySQL no usa los valores por defecto de XAMPP: `127.0.0.1`, usuario `root`,
   sin contraseña): copie `config/config.local.example.php` como `config/config.local.php` y cambie los valores.
6. **Abrir la tienda:** <http://localhost/tienda_ropa/> · **Panel:** <http://localhost/tienda_ropa/admin/>

La URL base se detecta sola, así que la carpeta puede llamarse de cualquier forma. Si usa un *VirtualHost* o alias,
puede fijarla con `'base_url' => '/tienda_ropa'` en `config/config.local.php`.

## 4. Usuarios de prueba (sólo desarrollo)

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@firecat.com` | `Admin123*` → **se obliga a cambiarla en el primer ingreso** |
| Cliente | `cliente@firecat.com` | `Cliente123*` |
| Clientes extra | `andres@example.com`, `camila@example.com` y 12 clientes más (`…@example.com`) | `Cliente123*` |

Las contraseñas **no** están en el código PHP: en `database.sql` sólo existe su hash bcrypt generado con
`password_hash()`. Cambie o elimine estas cuentas antes de publicar la tienda.

## 5. Funcionalidades

**Tienda (cliente)**
- Portada con categorías, destacados y novedades.
- Catálogo con buscador, filtros por categoría, talla, color, rango de precio y disponibilidad, orden y paginación.
- Ficha de producto: selector de talla y color que deshabilita combinaciones agotadas, stock en vivo, cantidad, relacionados.
- Carrito persistente en BD: agregar (también desde la tarjeta del catálogo), cambiar cantidades, eliminar, vaciar,
  subtotal, envío (gratis desde un monto configurable) y total.
- Checkout en 4 pasos: Carrito → Entrega → Resumen → Confirmación. *Pago contra entrega* (con cálculo del cambio,
  heredado del módulo de caja original) o *Pedido de prueba*.
- Registro, login, logout, recuperación de contraseña por enlace con token de un solo uso, cambio de contraseña.
- Perfil editable, historial de pedidos con filtro por estado, detalle con línea de tiempo, cancelación de pedidos
  pendientes y comprobante imprimible / PDF.

**Panel administrativo (`/admin`)**
- **Dashboard:** ventas totales, pedidos pendientes y entregados, clientes, productos totales y activos, ticket
  promedio, unidades en inventario, gráfico de ventas de 14 días, pedidos por estado, últimos pedidos, más vendidos
  y alertas de poco inventario.
- **Productos:** CRUD completo con subida de imagen, generador de variantes (tallas × colores), activar/desactivar,
  eliminar sólo si no tiene ventas.
- **Categorías:** CRUD con imagen, activar/desactivar, eliminar sólo si está vacía.
- **Inventario:** entradas, salidas y conteo físico por variante, valor del inventario y kardex de movimientos.
- **Pedidos:** filtros por estado, texto y fechas; detalle con cambio de estado según el flujo permitido; al cancelar
  se devuelve el stock.
- **Clientes:** búsqueda, activar/desactivar, editar datos y rol, crear usuarios y generar contraseñas temporales.
- **Reportes:** ventas por día, ingresos por categoría, top 10, **resumen de caja por día** y exportación a **Excel (.xlsx)**: libro de pedidos (pedidos + detalle de productos) y reporte de ventas (caja por día, top de productos, por categoría y resumen).
- **Configuración:** datos de la tienda, costos de envío, umbral de stock bajo, tallas y colores.

**Fabricación (`/admin`, sección *Fabricación*)**
- **Materias primas:** telas, hilos, botones, cierres, etiquetas y empaque, otros insumos; unidad de medida, stock,
  stock mínimo con alertas, **costo promedio ponderado**, valor del inventario por tipo y kardex por material.
  Ajustes por merma, entrada manual o conteo físico.
- **Proveedores:** CRUD con NIT, contacto y total comprado; no se eliminan si tienen compras.
- **Compras de materiales:** factura con varias líneas (subtotal y total en vivo) que suma stock y recalcula el costo
  promedio; anulación que devuelve el stock si el material no se ha consumido.
- **Fichas técnicas (recetas):** materiales por prenda con % de merma, mano de obra directa, costos indirectos y tiempo;
  el sistema calcula **cuánto cuesta fabricar cada prenda** y su margen, y el promedio por categoría (camisetas,
  pantalones, sudaderas…).
- **Producción:** órdenes *planificada → en proceso → terminada* (o cancelada). El planificador muestra los materiales
  necesarios frente al stock; al **iniciar** se descuentan todos los materiales en una transacción (si falta uno, no
  se descuenta ninguno); al **terminar** las prendas entran al inventario de la tienda. El costo real se guarda en la orden.

**Finanzas (`/admin`, sección *Finanzas*)**
- **Gastos:** operativos (arriendo, servicios, nómina administrativa, publicidad, transporte…), mantenimiento
  (maquinaria, instalaciones) y otros, con categorías configurables.
- **Utilidad y flujo:** para el mes, el mes anterior, los últimos 30 días, el año o un rango: estado de resultados
  (ventas − costo de lo vendido = utilidad bruta − gastos = **utilidad estimada**), flujo de dinero (lo que entró −
  compras − gastos), "¿en qué se gastó?", rentabilidad por producto, evolución mensual, costo de fabricación por
  tipo de prenda, inventario de materias primas y exportación a **Excel** (6 hojas).

### ¿Dónde responde el sistema cada pregunta?

| Pregunta | Dónde verla |
|---|---|
| "Vendimos $X este mes, ¿cuánto gastamos en telas, materiales, mantenimiento y otros gastos?" | *Utilidad y flujo* → tarjetas superiores y tabla **¿En qué se gastó el dinero?** |
| "¿Cuánto cuesta realmente fabricar una camiseta / un pantalón?" | *Utilidad y flujo* → tarjetas de costo; detalle por prenda en *Fichas y costos* |
| "¿Cuánto gastamos en materias primas este mes?" | *Utilidad y flujo* → **¿Cuánto gastamos en materias primas?**; detalle en *Compras* |
| "¿Cuánto dinero ingresó por ventas?" | *Utilidad y flujo* → **¿Cuánto dinero ingresó por ventas?** (vendido y cobrado) |
| "¿Cuál fue la utilidad aproximada?" | *Utilidad y flujo* → **Estado de resultados** |
| "¿Qué materiales tenemos actualmente en inventario?" | *Materias primas* (con stock, costo y valor) |

> **Cómo se calcula la utilidad.** Cada venta guarda ("congela") el costo de fabricación de la prenda según su ficha
> técnica, igual que guarda el precio. Utilidad estimada = ventas − costo de lo vendido − gastos. La mano de obra
> directa ya está dentro del costo de cada prenda, por eso no se registra otra vez como gasto. Comprar tela es una
> **salida de dinero**, pero todavía no es un costo: se vuelve costo cuando la prenda fabricada se vende. Por eso el
> *flujo de dinero* y la *utilidad* no dan lo mismo, y el sistema muestra ambos.

## 6. Estructura del proyecto

```
tienda_ropa/
├── index.php, productos.php, producto.php, categorias.php     ← tienda
├── carrito.php, checkout.php, pedido_confirmado.php
├── login.php, registro.php, logout.php, recuperar.php, restablecer.php, cambiar_password.php
├── perfil.php, mis_pedidos.php, pedido.php                     ← cuenta del cliente
├── admin/
│   ├── index.php (dashboard), login.php, logout.php
│   ├── productos.php, producto_crear.php, producto_editar.php
│   ├── categorias.php, inventario.php, pedidos.php, pedido_detalle.php
│   ├── usuarios.php, usuario_editar.php, reportes.php, configuracion.php
│   ├── materiales.php, material_editar.php, proveedores.php                 ← fabricación
│   ├── compras.php, compra_crear.php, compra_detalle.php
│   ├── fichas.php, ficha_editar.php, produccion.php, produccion_detalle.php
│   ├── gastos.php, finanzas.php                                             ← finanzas
│   └── includes/ header.php, sidebar.php, footer.php, producto_form.php, funciones_admin.php
├── includes/
│   ├── bootstrap.php     ← se incluye en cada página: config, errores, sesión, CSRF
│   ├── db.php            ← conexión PDO + helpers + transacciones
│   ├── sesion.php, csrf.php, auth.php (autenticación y roles), validacion.php, errores.php, funciones.php
│   ├── catalogo.php, carrito.php, inventario.php, pedidos.php, subidas.php   ← lógica de negocio
│   ├── fabricacion.php, finanzas.php, xlsx.php (generador de Excel)
│   ├── header.php, footer.php                                                 ← plantilla de la tienda
│   └── partials/ (tarjeta de producto, alertas, paginación, error, auth…)
├── config/ config.php, config.local.example.php
├── assets/ css/, js/, img/ (marca y productos), vendor/ (Bootstrap, Icons, Chart.js)
├── uploads/              ← imágenes subidas (PHP deshabilitado por .htaccess)
├── storage/logs/         ← registros de errores, seguridad y "correos" simulados
├── tests/pruebas_integracion.php
├── docs/ AUDITORIA.md, BASE_DE_DATOS.md
└── database.sql
```

## 7. Base de datos

27 tablas normalizadas + 1 vista. Diagrama entidad-relación, diccionario de datos y reglas de integridad en
[docs/BASE_DE_DATOS.md](docs/BASE_DE_DATOS.md). Resumen:

| Tabla | Descripción |
|---|---|
| `usuarios` | Clientes y administradores (rol, estado, hash de contraseña) |
| `categorias`, `tallas`, `colores` | Catálogos |
| `productos` | Prenda con categoría, precio, imagen y estado |
| `variantes_producto` | Producto + talla + color con SKU y **stock** propio |
| `carritos`, `carrito_detalle` | Carrito persistente por usuario |
| `pedidos`, `pedido_detalle` | Pedido con datos de entrega y líneas con **precio histórico** |
| `pedido_historial` | Cambios de estado (quién, cuándo, comentario) |
| `movimientos_inventario` | Kardex: entradas, salidas, ajustes, ventas y devoluciones |
| `password_resets`, `intentos_login` | Recuperación de contraseña y protección de fuerza bruta |
| `configuracion` | Ajustes de la tienda |
| `proveedores`, `tipos_material`, `materiales` | Proveedores y materias primas con stock y costo promedio |
| `compras_material`, `compra_detalle` | Compras a proveedores |
| `movimientos_material` | Kardex de materias primas: compras, consumos, ajustes, devoluciones y anulaciones |
| `fichas_tecnicas`, `ficha_materiales` | Receta de cada prenda: materiales con merma, mano de obra e indirectos |
| `ordenes_produccion`, `produccion_consumos` | Órdenes de producción y consumo real de materiales (con su costo) |
| `categorias_gasto`, `gastos` | Gastos operativos, de mantenimiento y otros |

## 8. Seguridad implementada

| Riesgo | Medida |
|---|---|
| SQL Injection | PDO con consultas preparadas reales (`ATTR_EMULATE_PREPARES = false`); `ORDER BY` por lista blanca; `LIKE` escapado |
| Contraseñas | `password_hash()` (bcrypt) + `password_verify()`, re-hash automático, política mínima (8+, mayúsculas, minúsculas, número) |
| Enumeración de usuarios | Mensaje único "Correo o contraseña incorrectos." y hash ficticio para igualar tiempos; la recuperación responde igual exista o no el correo |
| Fuerza bruta | Bloqueo de 15 min tras 5 intentos fallidos por correo + IP (tabla `intentos_login`) |
| XSS | Toda salida pasa por `e()` = `htmlspecialchars()`; Content-Security-Policy sin JavaScript en línea |
| CSRF | Token por sesión en **todos** los formularios; el `bootstrap.php` rechaza cualquier POST sin token válido (403). El logout también es POST |
| Sesiones | `session_regenerate_id(true)` al iniciar sesión y cada 15 min, cookies `HttpOnly` + `SameSite=Lax` (+ `Secure` bajo HTTPS), modo estricto, expiración por 30 min de inactividad |
| Control de acceso | `requerir_login()` / `requerir_admin()` en el servidor; el rol se lee de la BD en cada petición; un cliente recibe 403 en `/admin/*`; los pedidos se consultan siempre con `usuario_id` de la sesión (sin IDOR) |
| Manipulación de precios / cantidades | El precio se lee de la BD en carrito, resumen y confirmación; stock validado al agregar, actualizar y dentro de la transacción con `SELECT … FOR UPDATE` |
| Subida de imágenes | Extensión y MIME real (finfo), `getimagesize`, máximo 2 MB, re-codificación con GD, nombre aleatorio; `uploads/.htaccess` desactiva PHP |
| Archivos internos | `.htaccess` bloquea `config/`, `includes/`, `storage/`, `tests/`, `docs/`, `database.sql`, `.md`; cada fragmento PHP aborta si se abre directamente |
| Open redirect | El parámetro `retorno` sólo acepta rutas internas `*.php` |
| Errores | En producción no se muestran rutas, SQL ni trazas: página amigable + registro en `storage/logs/app.log` |
| Cabeceras | CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| Exportación a Excel | Generador `.xlsx` propio (`includes/xlsx.php`, sin librerías externas); los textos se escriben como texto, nunca como fórmula, evitando la inyección de fórmulas; sólo lo descargan administradores |

**Recordar sesión:** no se implementó a propósito; un "recordarme" seguro requiere tokens rotativos
selector/validador, y la sesión actual ya dura mientras haya actividad. Se deja como mejora futura.

### Modo desarrollo vs. producción

`config/config.php` trae `'app_env' => 'development'`, que muestra el detalle técnico de los errores y, como XAMPP no
envía correos, **muestra en pantalla el enlace de recuperación de contraseña** (también queda en
`storage/logs/correo.log`). Para publicar, cree `config/config.local.php` con `'app_env' => 'production'`.

## 9. Cómo ejecutar las pruebas

Con Apache y MySQL encendidos y la base recién importada:

```bash
php tests/pruebas_integracion.php http://localhost/tienda_ropa
```

La suite ejecuta **211 comprobaciones por HTTP real** y verifica el estado de la base de datos: registro, login,
logout, login incorrecto, búsqueda, filtros, carrito, cambio de cantidades, checkout, creación del pedido, historial,
perfil, recuperación de contraseña, CRUD del panel, inventario, flujo de estados, usuarios, reportes y exportación a Excel, proveedores, compras con costo promedio ponderado, fichas técnicas, producción
(consumo exacto de materiales, entrada a la tienda, ROLLBACK si faltan materiales, cancelación con devolución),
gastos y cálculo de utilidad, SQL Injection,
XSS, CSRF, IDOR, acceso de clientes al panel, manipulación de precios y cantidades, compra sin stock, `ROLLBACK`
cuando el stock cambia durante el checkout, subida de un PHP disfrazado de imagen y fuerza bruta.
Las pruebas modifican datos: **vuelva a importar `database.sql`** al terminar.

## 10. Guía rápida de demostración

1. Entre como `cliente@firecat.com`, filtre por talla **M**, agregue una *Sudadera clásica* y finalice la compra
   con *Pago contra entrega* indicando con cuánto pagará.
2. En *Mis pedidos* vea la línea de tiempo y el comprobante imprimible.
3. Entre al panel como `admin@firecat.com` (le pedirá cambiar la contraseña), revise el dashboard, avance el pedido
   a *Confirmado* → *Preparado* y observe el kardex en *Inventario*.
4. Cambie el precio de la *Camiseta básica* y compruebe que el pedido `FC-000201` conserva su precio histórico ($49.900).
5. **Fabricación:** en *Fichas y costos* abra la *Sudadera clásica* y vea cuánto cuesta fabricarla. En *Producción*
   planifique 10 unidades de *Chaqueta urbana M/Beige* (tiene poco stock): el sistema muestra los materiales necesarios.
   Inicie la orden (se descuentan los materiales), termínela (entran las prendas a la tienda) y revise el kardex de la tela.
6. **Compras:** registre una compra de *Cierre de nylon 18 cm* (está bajo el mínimo) y observe cómo cambian el stock
   y el costo promedio.
7. **Finanzas:** registre un gasto de mantenimiento y abra *Utilidad y flujo*: la utilidad estimada baja exactamente
   en ese valor. Exporte el reporte a Excel.

Los datos de demostración incluyen ~3 meses de historia: 208 pedidos (FC-000001 a FC-000208), 5 proveedores,
19 materias primas, 6 compras, 20 fichas técnicas, 5 órdenes de producción y 24 gastos, todos coherentes entre sí
(los kardex cuadran exactamente con el stock).

## 11. Mejoras futuras

- Envío real de correos (PHPMailer + SMTP) para recuperación de contraseña y avisos de estado.
- Pasarela de pagos (PSE, tarjetas) con webhooks.
- Galería de varias imágenes por producto y precios de oferta con vigencia.
- Fichas técnicas con consumo distinto por talla, cuentas por pagar a proveedores y conciliación bancaria.
- "Recordarme" seguro con tokens rotativos y verificación de correo al registrarse.
- Carrito de invitado que se fusione al iniciar sesión.
- Autenticación de dos factores para administradores y registro de auditoría de acciones del panel.
- Pruebas unitarias con PHPUnit e integración continua.
