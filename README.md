# FIRE CAT · Tienda online de ropa

Tienda de ropa urbana con **tienda web**, **panel administrativo**, **API REST**
y base de datos **PostgreSQL en Supabase** con **Row Level Security**,
preparada para una futura **app móvil** (Flutter / React Native).

Es la evolución del proyecto académico original *FIRE CAT* (páginas HTML con
`localStorage`), conservando su identidad visual (logo, amarillo/negro,
fotografías y el fondo animado de prendas) y sus funciones de vendedor
(carrito con pago y cambio, historial de ventas y caja), ahora reimplementadas
de forma segura sobre un backend real.

![Inicio](docs/img/tienda-inicio.jpg)

| Detalle de producto | Panel administrativo | Móvil |
|---|---|---|
| ![Producto](docs/img/tienda-producto.jpg) | ![Dashboard](docs/img/admin-dashboard.jpg) | ![Móvil](docs/img/movil-producto.jpg) |

---

## Contenido

1. [Descripción y objetivo](#1-descripción-y-objetivo)
2. [Tecnologías](#2-tecnologías)
3. [Arquitectura](#3-arquitectura)
4. [Base de datos](#4-base-de-datos)
5. [Autenticación y roles](#5-autenticación-y-roles)
6. [Seguridad](#6-seguridad)
7. [API](#7-api)
8. [Instalación](#8-instalación)
9. [Configuración de Supabase](#9-configuración-de-supabase)
10. [Ejecución local](#10-ejecución-local)
11. [Datos de prueba](#11-datos-de-prueba)
12. [Panel administrativo](#12-panel-administrativo)
13. [Pruebas](#13-pruebas)
14. [Futuro desarrollo móvil](#14-futuro-desarrollo-móvil)
15. [Documentación adicional](#15-documentación-adicional)

---

## 1. Descripción y objetivo

**Objetivo:** construir una tienda online de ropa funcional, segura y escalable
que separe **presentación → lógica de negocio → API → base de datos**, y que
cualquier cliente (web o móvil) pueda usar la misma API.

**Clientes pueden:** registrarse, iniciar/cerrar sesión, recuperar la
contraseña, buscar y filtrar productos (categoría, talla, color, precio,
disponibilidad, ofertas), ver el detalle, elegir talla y color con
disponibilidad en tiempo real, gestionar el carrito, comprar (checkout con
direcciones y método de pago), consultar historial y estado de pedidos,
cancelar pedidos pendientes, imprimir comprobantes, administrar perfil,
contraseña, direcciones y favoritos.

**Administradores pueden:** ver el dashboard (ventas, pedidos, clientes,
inventario, stock bajo, pedidos pendientes), gestionar productos, categorías,
tallas, colores, variantes, imágenes e inventario (con kardex), gestionar
pedidos y sus estados, registrar ventas en tienda física (caja), gestionar
clientes (rol y bloqueo), consultar reportes y editar la configuración y
contenidos de la tienda.

## 2. Tecnologías

| Capa | Tecnología |
|---|---|
| Frontend | HTML5, CSS3, JavaScript (vanilla, sin scripts inline), Bootstrap 5.3, Bootstrap Icons, Chart.js 4 (servidos localmente) |
| Backend | PHP 8.1+ (probado en 8.4) sin framework: front controller, router, servicios, PDO |
| Base de datos | PostgreSQL 15+ en **Supabase** (RLS, funciones PL/pgSQL, `tsvector`, `pg_trgm`) |
| Autenticación | **Supabase Auth** (GoTrue): JWT ES256/RS256/HS256, refresh tokens, recuperación de contraseña |
| Archivos | **Supabase Storage** (bucket `productos`) · driver local para desarrollo |
| Pruebas | SQL (psql), PHP CLI, Playwright + Chromium |

## 3. Arquitectura

```
 Tienda web ─┐                         ┌─► PostgreSQL (Supabase) · RLS · funciones transaccionales
 Panel admin ─┼─► Backend PHP / API ───┤
 App móvil ──┘   (/api/v1, Bearer JWT) └─► Supabase Auth · Supabase Storage
```

* Web y API comparten los **mismos servicios** de negocio (`src/Services`).
* Cada consulta del backend se ejecuta con el **rol y los claims del usuario**
  (`anon`/`authenticated` + `auth.uid()`), así que **RLS aplica también al backend**.
* Operaciones críticas (checkout, inventario, estados, POS) son **funciones
  SQL atómicas** con bloqueo de filas.
* La app móvil **no** accede a PostgreSQL: consume la API.

Diagramas, flujos y estructura de carpetas: **[docs/ARQUITECTURA.md](docs/ARQUITECTURA.md)**.

## 4. Base de datos

18 tablas normalizadas en `public` (todas con RLS) + esquema privado `app_private`:

`profiles`, `direcciones`, `categorias`, `tallas`, `colores`, `productos`,
`variantes_producto` (talla × color con stock propio), `imagenes_producto`,
`movimientos_inventario` (kardex), `favoritos`, `configuracion_tienda`,
`estados_pedido`, `transiciones_estado_pedido`, `carritos`, `carrito_detalle`,
`pedidos`, `pedido_detalle` (precio histórico), `historial_estados_pedido`.

Diagrama lógico:

```
auth.users 1─1 profiles 1─* direcciones
                  │ 1─1 carritos 1─* carrito_detalle *─1 variantes_producto
                  │ 1─* pedidos 1─* pedido_detalle (precio_unitario histórico)
                  │        └─* historial_estados_pedido   estados_pedido ─ transiciones
                  └ 1─* favoritos *─1 productos
categorias 1─* productos 1─* variantes_producto *─1 tallas / colores
                     └─1─* imagenes_producto        variantes 1─* movimientos_inventario
```

Migraciones en `supabase/migrations/001…007`. Modelo, restricciones, funciones
y diagrama ER (Mermaid): **[docs/BASE_DE_DATOS.md](docs/BASE_DE_DATOS.md)**.

## 5. Autenticación y roles

* **Supabase Auth** gestiona registro, login, logout, sesiones, tokens y
  recuperación de contraseña. **No hay contraseñas en tablas propias.**
* `profiles` (1:1 con `auth.users`) guarda nombre, apellido, teléfono,
  fecha de registro, **rol** (`cliente` | `admin`) y **estado** (`activo` | `bloqueado`).
  Se crea automáticamente por trigger al registrarse, **siempre como cliente**.
* Web: el backend guarda los tokens en la sesión del servidor (cookie HttpOnly).
  API/móvil: `Authorization: Bearer <access_token>`.
* El rol se controla en **tres niveles**: middleware del backend, privilegios de
  PostgreSQL (GRANT por columna) y políticas RLS / `is_admin()`.
* Para convertir un usuario en administrador: Panel → Clientes → Rol, o en SQL
  `update public.profiles set rol = 'admin' where email = '…';`.

## 6. Seguridad

RLS en todas las tablas, validación en servidor y cliente, sentencias
preparadas, escape de salida + CSP estricta, CSRF, sesiones endurecidas,
verificación de JWT, validación de imágenes (MIME real + re-codificación),
precios y stock siempre desde la BD, rate limiting, manejo seguro de errores,
secretos solo en `.env`.

Auditoría del proyecto original, controles y **resultados de las 161 pruebas**:
**[docs/SEGURIDAD.md](docs/SEGURIDAD.md)**.

## 7. API

REST JSON en `/api` (alias `/api/v1`):

```
GET  /api/products            GET  /api/cart           GET  /api/orders
GET  /api/products/{slug}     POST /api/cart           POST /api/orders
GET  /api/categories          PUT  /api/cart/{id}      GET  /api/orders/{id}
POST /api/auth/login          DELETE /api/cart/{id}    GET|PUT /api/profile
GET|POST /api/addresses       /api/admin/products · orders · users · inventory · reports …
```

```json
GET /api/products  →  { "success": true, "data": [ … ], "meta": { "total": 20, "pagina": 1, "por_pagina": 12, "paginas": 2 } }
```

Referencia completa: **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)** · Diseño y guía móvil: **[docs/API.md](docs/API.md)**.

## 8. Instalación

Requisitos: **PHP ≥ 8.1** con extensiones `pdo_pgsql`, `curl`, `mbstring`,
`fileinfo`, `openssl` (y `gd` recomendado); un proyecto de **Supabase** (o
Supabase CLI con Docker para local).

```bash
git clone https://github.com/wendyalexandrar5-netizen/tienda_ropa.git
cd tienda_ropa
cp .env.example .env        # completar con los datos de Supabase
```

No hay dependencias que instalar (sin Composer ni npm para ejecutar la app).

## 9. Configuración de Supabase

1. Crear un proyecto en [supabase.com](https://supabase.com).
2. **Aplicar las migraciones** (elige una opción):
   * Supabase CLI: `npx supabase link --project-ref <ref>` y `npx supabase db push`
     (y `psql "$DATABASE_URL" -f supabase/seed.sql` para el catálogo de prueba).
   * SQL Editor: ejecutar en orden `supabase/migrations/001…007_*.sql` y luego `supabase/seed.sql`.
3. **Variables** (*Project Settings → API / Database*) en `.env`:
   `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, `SUPABASE_SECRET_KEY` (solo para
   el script de datos de prueba), `DATABASE_URL` (Session pooler, puerto 5432),
   y `SUPABASE_JWT_SECRET` solo si el proyecto usa el secreto JWT legacy.
4. **Auth → URL Configuration**: *Site URL* = `APP_URL`; *Redirect URLs*:
   `APP_URL/restablecer` y `APP_URL/login`.
5. (Recomendado) **Auth → Email templates → Reset password**: usar
   `{{ .SiteURL }}/restablecer?token_hash={{ .TokenHash }}&type=recovery`
   (la plantilla por defecto también funciona).
6. **Storage**: la migración 006 crea el bucket `productos` (público, 5 MB,
   JPG/PNG/WEBP) y sus políticas. Usar `STORAGE_DRIVER=supabase`.
7. Crear usuarios de prueba: `php scripts/seed_demo.php`.

## 10. Ejecución local

**Opción A – Supabase CLI (Docker):**
```bash
npx supabase start          # aplica migrations + seed.sql
# copiar URL/keys que imprime el comando a .env (DATABASE_URL=postgresql://postgres:postgres@127.0.0.1:54322/postgres)
php scripts/seed_demo.php
php -S 127.0.0.1:8080 -t public public/index.php
```

**Opción B – sin Docker** (PostgreSQL local + binario de Supabase Auth; así se
desarrolló y probó este proyecto):
```bash
# descargar https://github.com/supabase/auth/releases (auth-vX-x86.tar.gz) en .local/gotrue/
scripts/local/dev-env.sh start     # crea el cluster, aplica shim + migraciones + seed, arranca Auth
php scripts/seed_demo.php
php -S 127.0.0.1:8080 -t public public/index.php
```
`.env` para esta opción: `SUPABASE_URL=http://127.0.0.1:9999`,
`SUPABASE_AUTH_URL=http://127.0.0.1:9999`, `SUPABASE_JWT_SECRET=` (el del script),
`DATABASE_URL=postgresql://postgres@127.0.0.1:54322/postgres`, `STORAGE_DRIVER=local`,
`APP_ENV=local`, `COOKIE_SECURE=false`.

Abrir <http://127.0.0.1:8080> (tienda) y <http://127.0.0.1:8080/admin> (panel).

**Producción:** Apache/Nginx con el *document root* en `public/`
(ver `public/.htaccess` y `docs/deploy/nginx.conf.example`), HTTPS,
`APP_ENV=production`, `APP_DEBUG=false`.

## 11. Datos de prueba

* **Catálogo** (`supabase/seed.sql`): Camisetas, Pantalones, Sudaderas,
  Chaquetas, Conjuntos y Accesorios; 20 productos con las fotos originales;
  98 variantes (tallas × colores) con cantidades variadas, incluidas agotadas y con stock bajo.
* **Usuarios y pedidos** (`php scripts/seed_demo.php`):

| Correo | Rol |
|---|---|
| `admin@firecat.test` | Administrador |
| `camila@firecat.test`, `andres@firecat.test`, `valentina@firecat.test`, `santiago@firecat.test` | Cliente |

Contraseña de demostración: `FireCat-Demo-2026` (cámbiala con `DEMO_PASSWORD`).
Son cuentas ficticias (dominio `.test`); **no usar en producción**. El script
genera además direcciones, carritos, 18 pedidos en distintos estados y 6 ventas
de caja repartidas en los últimos 90 días.

## 12. Panel administrativo

`/admin` (solo rol admin):

| Sección | Funciones |
|---|---|
| **Dashboard** | Ventas del mes/hoy, pedidos pendientes y en proceso, clientes, inventario (unidades y valor), gráfico de ventas 30 días, más vendidos, pendientes, stock bajo |
| **Caja / Venta en tienda** | Búsqueda por nombre/SKU, venta con pago recibido y **cambio calculado en el servidor**, comprobante (evolución de `carrito.html` + `caja.html`) |
| **Productos** | Listado con filtros, crear/editar/eliminar, estado (borrador/activo/inactivo), destacado, precio de oferta, **variantes** talla × color, **imágenes** (Storage) |
| **Categorías** | CRUD, visibilidad, orden, imagen |
| **Tallas y colores** | Catálogos maestros para las variantes |
| **Inventario** | Stock por variante, filtros de stock bajo/agotado, entradas/salidas/ajustes con motivo, **kardex** |
| **Pedidos** | Filtros por estado, canal, fechas y cliente; detalle; cambio de estado con transiciones válidas; comprobante |
| **Clientes** | Búsqueda, pedidos y total comprado, direcciones, cambio de rol y bloqueo |
| **Reportes** | Ventas por día/semana/mes (online vs tienda), más vendidos, categorías, pedidos por estado, inventario bajo, clientes registrados |
| **Configuración** | Nombre, eslogan, banner, contacto, costo de envío, envío gratis, umbral de stock, zona horaria |

## 13. Pruebas

```bash
scripts/local/run-tests.sh --reset     # reconstruye la BD y ejecuta todo
```

| Suite | Resultado |
|---|---|
| RLS y reglas de negocio (`supabase/tests/rls_test.sql`) | 39/39 |
| JWT ES256/RS256/HS256 (`tests/unit/jwt_verifier_test.php`) | 14/14 |
| Seguridad API/web/Data API (`tests/security/api_security_test.php`) | 82/82 |
| E2E en navegador (`tests/e2e/flujo_completo.js`) | 26/26 |

## 14. Futuro desarrollo móvil

La base de datos y la API ya soportan la app sin cambios estructurales:
carrito persistente en la BD, canal de venta `app`, idempotencia en pedidos,
direcciones, favoritos, paginación y errores con códigos estables.

```
APP MÓVIL ──► Supabase Auth (JWT) ──► API /api/v1 (Bearer) ──► PostgreSQL (RLS)
```

1. `POST /api/v1/auth/login` → guardar `refresh_token` en almacenamiento seguro.
2. Enviar `Authorization: Bearer <access_token>` en cada petición.
3. Renovar con `POST /api/v1/auth/refresh` ante `401 TOKEN_EXPIRADO`.
4. Consumir `/products`, `/categories`, `/cart`, `/orders`, `/profile`, `/addresses`.

Guía paso a paso y ejemplos Flutter / React Native: **[docs/API.md](docs/API.md)**.

## 15. Documentación adicional

* [docs/ANALISIS_PROYECTO_ORIGINAL.md](docs/ANALISIS_PROYECTO_ORIGINAL.md) – análisis previo, problemas y propuesta de migración
* [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) – capas, flujos y decisiones
* [docs/BASE_DE_DATOS.md](docs/BASE_DE_DATOS.md) – modelo, ER, funciones y RLS
* [docs/SEGURIDAD.md](docs/SEGURIDAD.md) – auditoría, controles y resultados de pruebas
* [docs/API.md](docs/API.md) y [API_DOCUMENTATION.md](API_DOCUMENTATION.md) – API REST y app móvil
