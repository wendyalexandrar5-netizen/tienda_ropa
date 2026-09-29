# Arquitectura

## 1. Vista general

```
┌──────────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐
│  Tienda web (PHP +   │   │  Panel administrativo│   │  Futura app móvil    │
│  Bootstrap + JS)     │   │  (/admin)            │   │  Flutter / RN / iOS  │
└─────────┬────────────┘   └──────────┬───────────┘   └──────────┬───────────┘
          │ cookie HttpOnly + CSRF    │                          │ Bearer JWT
          ▼                           ▼                          ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                BACKEND PHP  (public/index.php → Kernel → Router)             │
│  Middleware: cabeceras de seguridad · CORS · sesión · verificación JWT ·     │
│              CSRF · auth / admin / guest · rate limiting                     │
│  Controladores Web (HTML)          Controladores API REST (/api, /api/v1)    │
│                 └──────────┬──────────────────┘                              │
│                    SERVICIOS (lógica de negocio compartida)                  │
│  CatalogService · CartService · OrderService · AccountService ·              │
│  AdminCatalog/Inventory/Order/User · ReportService · SettingsService         │
│                    Database (PDO + contexto RLS por petición)                │
└──────────────┬───────────────────────────────────────────┬───────────────────┘
               │ SQL parametrizado, rol anon/authenticated │ REST
               ▼                                           ▼
┌───────────────────────────────────────┐   ┌──────────────────────────────────┐
│ PostgreSQL (Supabase)                  │   │ Supabase Auth (GoTrue)           │
│ · Tablas normalizadas + RLS            │   │  registro, login, logout, JWT,   │
│ · Funciones transaccionales            │   │  refresh, recuperación contraseña│
│   (crear_pedido, venta POS, kardex…)   │   │ Supabase Storage (bucket         │
│ · Reportes calculados al vuelo         │   │  "productos", políticas admin)   │
└───────────────────────────────────────┘   └──────────────────────────────────┘
```

**Separación de capas** (lo que pedía el enunciado):

| Capa | Dónde | Responsabilidad |
|---|---|---|
| Presentación | `views/`, `public/assets/` | HTML/CSS/JS. Sin reglas de negocio: solo mejora la experiencia |
| API | `src/Controllers/Api`, `src/routes.php` | Contrato REST JSON estable, versionado (`/api/v1`) |
| Lógica de negocio | `src/Services`, funciones SQL | Validación, reglas, orquestación |
| Datos | `supabase/migrations` | Modelo normalizado, integridad, RLS, transacciones |

La web y la API usan **los mismos servicios**: una regla (p. ej. "no vender sin
stock") existe una sola vez y aplica a todos los clientes.

## 2. Defensa en profundidad

Cada operación pasa por tres controles independientes:

1. **Backend PHP** – middleware `auth`/`admin`, validación de entrada (`Validator`),
   CSRF, rate limiting.
2. **Rol de PostgreSQL** – el backend ejecuta cada consulta con
   `set_config('role', 'authenticated'|'anon')` y los *claims* del JWT
   verificado (igual que PostgREST). Los `GRANT` por tabla/columna limitan qué
   se puede tocar (p. ej. nadie puede `UPDATE stock` directamente).
3. **Row Level Security** – las políticas filtran filas por `auth.uid()` y
   `is_admin()`. Aunque un controlador tuviera un error, la BD no devolvería
   ni modificaría datos ajenos.

Las operaciones críticas (checkout, cancelación, cambio de estado, ajuste de
inventario, venta POS) son **funciones `SECURITY DEFINER`** con validaciones
internas y `SELECT … FOR UPDATE`, por lo que son atómicas y seguras incluso si
se invocan directamente desde la Data API de Supabase.

## 3. Flujo de una compra

```
Producto ─► POST /api/cart ─► trigger valida stock/estado (no guarda precio)
         ─► GET /checkout     (precios recalculados desde la BD)
         ─► POST /api/orders ─► public.crear_pedido()  ── UNA transacción ──┐
                                 1. valida usuario activo y dirección propia │
                                 2. bloquea variantes (FOR UPDATE, orden fijo)│
                                 3. revalida stock y estado                  │
                                 4. inserta pedido + pedido_detalle          │
                                    (precio_unitario histórico)              │
                                 5. descuenta stock + kardex                 │
                                 6. historial de estados, vacía carrito      │
                                 ──────────── COMMIT o ROLLBACK total ───────┘
         ─► /pedido/{id}/confirmacion
```

## 4. Autenticación

```
Web:   formulario /login ─► PHP ─► Supabase Auth /token ─► JWT
       PHP verifica firma + claims, guarda tokens EN EL SERVIDOR (sesión),
       el navegador solo recibe cookie opaca HttpOnly SameSite=Lax.
       Renovación automática con refresh_token antes de expirar.

Móvil: POST /api/auth/login ─► {access_token, refresh_token}
       Cada petición: Authorization: Bearer <access_token>
       PHP verifica la firma (JWKS ES256/RS256 o HS256), exp, aud, role, sub
       y carga rol/estado desde public.profiles.
```

## 5. Estructura del proyecto

```
tienda_ropa/
├── public/                  ← document root (lo único accesible por HTTP)
│   ├── index.php            front controller (web + API)
│   ├── .htaccess
│   ├── assets/css|js|img|vendor   (Bootstrap, iconos y Chart.js locales)
│   └── uploads/             solo con STORAGE_DRIVER=local (sin ejecución de scripts)
├── src/
│   ├── bootstrap.php, routes.php, helpers.php
│   ├── Core/                Kernel, Router, Request/Response, Database, Session,
│   │                        Csrf, Validator, RateLimiter, HttpClient, View, …
│   ├── Auth/                JwtVerifier, SupabaseAuthClient, AuthService, CurrentUser
│   ├── Services/            lógica de negocio (+ Admin/)
│   ├── Storage/             ImageValidator, SupabaseImageStorage, LocalImageStorage
│   └── Controllers/Web|Api  controladores HTML y REST
├── views/                   layouts, partials, shop, auth, account, admin, errors
├── supabase/
│   ├── migrations/          001…007 (esquema, perfiles, catálogo, pedidos, RLS, storage, reportes)
│   ├── seed.sql             catálogo de prueba
│   ├── tests/rls_test.sql   39 pruebas de RLS y reglas de negocio
│   ├── local/               shim para PostgreSQL sin Supabase (solo desarrollo)
│   └── config.toml          Supabase CLI
├── scripts/                 seed_demo.php, local/dev-env.sh, local/run-tests.sh
├── tests/                   unit/, security/, e2e/
├── docs/                    documentación
├── storage/                 caché (JWKS) y logs — no público
├── API_DOCUMENTATION.md
├── .env.example
└── README.md
```

## 6. Decisiones de diseño

| Decisión | Motivo |
|---|---|
| PHP sin framework ni Composer | Requisito académico (PHP), cero dependencias, fácil de desplegar en hosting compartido. Autoload PSR-4 propio. |
| PDO directo a PostgreSQL con RLS en lugar de solo la Data API | Transacciones, SQL expresivo, rendimiento; y RLS sigue aplicando porque se adopta el rol del usuario. |
| Reglas críticas en funciones SQL | Atomicidad real y mismas reglas para cualquier cliente (API PHP, Data API, futuros servicios). |
| Stock por variante + kardex (`movimientos_inventario`) | Una sola fuente de verdad del stock y trazabilidad completa. |
| Precio histórico en `pedido_detalle` | Cambiar precios no altera pedidos antiguos. |
| Tokens de la web en el servidor | El JS del navegador nunca ve el access token (mitiga robo por XSS). |
| Vendor local (Bootstrap/Chart.js) | CSP estricta `script-src 'self'` sin CDNs de scripts. |
| API en español y `snake_case` | Coherencia 1:1 con la BD y con el dominio del negocio. |

## 7. Escalabilidad

* Backend **sin estado** salvo la sesión web → escalado horizontal (sesiones
  en Redis/BD si se usan varias instancias). La API móvil es 100 % stateless.
* Rate limiting en PostgreSQL → compartido entre instancias.
* Índices en claves foráneas, búsqueda `tsvector` (GIN) y trigramas.
* Reportes calculados con SQL agregado (no tablas de estadísticas duplicadas);
  si el volumen crece, se pueden convertir en vistas materializadas.
* Categorías con `parent_id` (subcategorías), `configuracion_tienda` clave/valor,
  canal de venta (`web`/`app`/`pos`) y `clave_idempotencia` preparados para crecer.
