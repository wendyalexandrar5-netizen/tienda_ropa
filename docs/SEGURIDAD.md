# Seguridad

## 1. Auditoría del proyecto original

| # | Hallazgo | Severidad | Corrección |
|---|---|---|---|
| 1 | Contraseñas en texto plano en `/usuarios/<email>/data.json`, legibles públicamente | Crítica | Supabase Auth (bcrypt, tokens firmados). La app nunca almacena ni compara contraseñas |
| 2 | Login comparado en el navegador; cualquiera podía abrir las páginas "internas" | Crítica | Sesión en servidor + JWT verificado + middleware `auth`/`admin` + RLS |
| 3 | Precios, stock y totales calculados en el navegador (`localStorage`) | Crítica | Precio y stock solo desde la BD; `crear_pedido()` atómica con bloqueo de filas |
| 4 | XSS almacenado por `innerHTML` con datos del usuario (6 páginas) | Alta | Escape obligatorio `e()` en vistas, `textContent` en JS, validación que rechaza `< >`, CSP sin scripts inline |
| 5 | Subida de cualquier archivo como "imagen" en base64 | Alta | Validación de extensión + MIME real + decodificación + re-codificación GD + tamaño + nombre aleatorio; bucket con MIME permitido y políticas admin |
| 6 | Sin CSRF, sin cabeceras de seguridad, sin límites de intentos | Media | Tokens CSRF, CSP/HSTS/X-Frame-Options/nosniff, rate limiting |
| 7 | Endpoints inexistentes y errores JS que dejaban el registro/login inutilizables | Media | Reimplementado sobre Supabase Auth |

## 2. Controles implementados

| Control | Implementación |
|---|---|
| **Autenticación** | Supabase Auth (registro, login, logout, refresh con rotación, recuperación de contraseña). `JwtVerifier` valida firma ES256/RS256 (JWKS con caché) o HS256, `exp`, `nbf`, `aud=authenticated`, `role=authenticated`, `sub`. Rechaza `alg:none` y confusión de algoritmos. |
| **Sesiones web** | Tokens guardados en el servidor; cookie `fc_session` HttpOnly, SameSite=Lax, Secure en HTTPS, `use_strict_mode`; regeneración de ID al iniciar/cerrar sesión; expiración por inactividad; renovación automática del access token. |
| **Roles** | `profiles.rol` (`cliente`/`admin`) es la fuente de verdad. El registro siempre crea `cliente` (se ignora cualquier `rol` enviado). Cambiar rol/estado: solo admin (GRANT por columna + trigger `profiles_guard`); un admin no puede cambiarse a sí mismo. |
| **Autorización** | Middleware `auth`/`admin` en el backend **y** RLS + `is_admin()` en PostgreSQL + funciones `SECURITY DEFINER` con verificación interna. |
| **RLS** | Activado en las 18 tablas de `public`; políticas separadas por SELECT/INSERT/UPDATE/DELETE (ver §3). |
| **Privilegios** | `REVOKE ALL` a `anon`/`authenticated` y `GRANT` mínimo por tabla y **por columna** (p. ej. nadie tiene `UPDATE(stock)`, un cliente solo `UPDATE(nombre, apellido, telefono)` de su perfil). `ALTER DEFAULT PRIVILEGES` evita que tablas futuras queden abiertas. |
| **SQL Injection** | 100 % sentencias preparadas PDO; ordenamientos por lista blanca; `LIKE` con escape de comodines; IDs validados (entero/UUID) antes de consultar. |
| **XSS** | `e()` (htmlspecialchars) en todas las vistas, `safe_url()` para `src`/`href`, `textContent` en JS, JSON embebido con `JSON_HEX_TAG`, validación de texto plano, CSP `script-src 'self'` sin `unsafe-inline`. |
| **CSRF** | Token sincronizado por sesión (`_csrf` en formularios, `X-CSRF-Token` en fetch) exigido en toda petición que modifica estado autenticada con cookie; SameSite=Lax. Los clientes Bearer no usan cookies. |
| **Validación servidor** | `Validator` (tipos, rangos, longitudes, patrones, enumeraciones) + CHECK constraints en la BD. |
| **Validación cliente** | HTML5 (`required`, `pattern`, `min/max`), confirmación de contraseña, medidor de fortaleza — solo UX. |
| **Precios / stock** | Nunca se aceptan del cliente. Trigger de carrito valida stock; `crear_pedido()` revalida con `FOR UPDATE`; `CHECK stock >= 0`; cantidades 1–20 (carrito) / 1–100 (POS), sin líneas negativas. |
| **Archivos** | ≤ 5 MB; extensión jpg/jpeg/png/webp; MIME real con `finfo`; `getimagesize` coherente; dimensiones 50–6000 px; re-codificación GD (elimina EXIF y payloads); nombre `AAAA/MM/<32 hex>.ext`; bucket con `allowed_mime_types` y políticas solo-admin con extensión validada; `uploads/.htaccess` sin ejecución. |
| **Rate limiting** | En PostgreSQL (compartido entre instancias): login 8/15 min por correo y 30/15 min por IP; registro 10/h por IP; recuperación 3/15 min por correo; checkout 10/10 min por usuario; cambio de contraseña 5/15 min. |
| **Errores** | Mensajes genéricos al usuario; detalles solo en el log del servidor. Errores de BD mapeados a códigos estables (`STOCK_INSUFICIENTE`, …). `APP_DEBUG=false` en producción. |
| **Cabeceras** | CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS en HTTPS, `Cache-Control: no-store` en páginas y API. |
| **Secretos** | Solo en `.env` (ignorado por git). El navegador recibe únicamente la cookie de sesión; la clave publicable solo se usa en el servidor; la secreta solo en `scripts/seed_demo.php`. Document root = `public/`; `.htaccess` raíz bloquea `src/`, `.env`, etc. |
| **Redirecciones** | Solo rutas internas (`next` validado; `Response::redirect` rechaza `//` y URLs absolutas). |
| **Enumeración de usuarios** | Recuperación de contraseña responde igual exista o no la cuenta; pedidos ajenos devuelven 404 igual que inexistentes. |
| **CORS** | Deshabilitado por defecto; lista blanca opcional sin credenciales (solo Bearer). |

## 3. Row Level Security

```sql
-- Helpers (SECURITY DEFINER, search_path vacío para evitar secuestro)
public.is_admin()        -- rol = admin y estado = activo
public.is_active_user()  -- estado = activo
```

Ejemplos de políticas (completas en `supabase/migrations/005_rls.sql`):

```sql
-- Un cliente solo ve sus pedidos; el admin ve todos
create policy pedidos_select_propios_o_admin on public.pedidos
  for select to authenticated
  using (usuario_id = (select auth.uid()) or (select public.is_admin()));

-- Productos activos visibles públicamente; solo admin crea/modifica
create policy productos_select_activos_o_admin on public.productos
  for select to anon, authenticated
  using ((estado = 'activo' and exists (…categoría activa…)) or (select public.is_admin()));
create policy productos_update_admin on public.productos
  for update to authenticated using ((select public.is_admin())) with check ((select public.is_admin()));

-- Un cliente actualiza su propio perfil (solo columnas permitidas por GRANT)
create policy profiles_update_propio on public.profiles
  for update to authenticated
  using (id = (select auth.uid())) with check (id = (select auth.uid()));
```

**Inventario solo por procesos autorizados:** ningún rol tiene `UPDATE(stock)`;
el stock solo cambia dentro de `crear_pedido`, `cancelar_mi_pedido`,
`admin_cambiar_estado_pedido`, `admin_ajustar_inventario` y
`admin_registrar_venta_pos`, que escriben además el kardex.

**Storage:** lectura pública del bucket `productos`; `INSERT/UPDATE/DELETE`
solo si `is_admin()` y la extensión es jpg/jpeg/png/webp. El backend sube las
imágenes **con el token del administrador** (no con la service key), así que
las políticas de Storage también se aplican.

## 4. Pruebas de seguridad realizadas

Todas se ejecutan con `scripts/local/run-tests.sh` contra PostgreSQL 16 +
Supabase Auth (GoTrue v2.180) + PostgREST v12 reales en local.

| Suite | Archivo | Resultado |
|---|---|---|
| RLS y reglas de negocio en la BD | `supabase/tests/rls_test.sql` | **39/39** |
| Verificación de JWT (ES256/RS256/HS256 y ataques) | `tests/unit/jwt_verifier_test.php` | **14/14** |
| Seguridad de API, web y Data API de Supabase | `tests/security/api_security_test.php` | **84/84** |
| Flujos E2E en Chromium (cliente y admin) | `tests/e2e/flujo_completo.js` | **26/26** |

Además se verificó manualmente el flujo de **recuperación de contraseña** con
un servidor SMTP de pruebas: correo real de Supabase Auth → enlace →
`/restablecer` → nueva contraseña → login con la nueva contraseña.

### 4.1 Pruebas RLS / negocio (SQL)

Anónimo: no lee pedidos, perfiles ni kardex; no crea categorías; no ejecuta
`crear_pedido`; solo ve productos activos. Cliente: solo su perfil; no puede
autoasignarse `admin`; no edita perfiles ajenos; no crea/modifica/elimina
productos ni precios; no modifica stock ni kardex; no usa funciones
administrativas ni estadísticas; no agrega más que el stock, variantes
agotadas ni cantidades ≤ 0; no suplanta `usuario_id`; no inserta pedidos
directamente ni modifica totales. Checkout: precio real, stock descontado,
carrito vaciado, envío calculado, idempotente; revalida stock al confirmar
(venta concurrente simulada) sin dejar registros parciales. Cliente B no ve
ni cancela pedidos de A. Usuario bloqueado no compra. Admin: ve todo, precio
histórico conservado ($80.000 → $95.000), transiciones inválidas rechazadas,
cancelación repone stock, kardex, nunca stock negativo, POS con cambio y pago
insuficiente, líneas negativas no compensan, admin no se degrada a sí mismo,
reportes disponibles, funciones internas no invocables.

### 4.2 Pruebas de API / web (HTTP)

| # | Categoría | Prueba | Resultado |
|---|---|---|---|
| 1 | Sin autenticación | GET /api/cart → 401 | ✅ OK |
| 2 | Sin autenticación | GET /api/orders → 401 | ✅ OK |
| 3 | Sin autenticación | GET /api/profile → 401 | ✅ OK |
| 4 | Sin autenticación | GET /api/addresses → 401 | ✅ OK |
| 5 | Sin autenticación | GET /api/admin/dashboard → 401 | ✅ OK |
| 6 | Sin autenticación | GET /api/admin/users → 401 | ✅ OK |
| 7 | Sin autenticación | GET /admin redirige a /login | ✅ OK |
| 8 | Sin autenticación | El catálogo público sí es accesible | ✅ OK |
| 9 | Rol cliente | GET /api/admin/dashboard → 403 | ✅ OK |
| 10 | Rol cliente | GET /api/admin/users → 403 | ✅ OK |
| 11 | Rol cliente | GET /api/admin/orders → 403 | ✅ OK |
| 12 | Rol cliente | POST /api/admin/products → 403 | ✅ OK |
| 13 | Rol cliente | PUT /api/admin/orders/f57ce529-1b7e-47c8-99a3-3a54f830f8c0/status → 403 | ✅ OK |
| 14 | Rol cliente | POST /api/admin/inventory/adjust → 403 | ✅ OK |
| 15 | Rol cliente | PUT /api/admin/settings → 403 | ✅ OK |
| 16 | Rol cliente | GET /api/admin/reports/sales → 403 | ✅ OK |
| 17 | Rol admin | Admin accede al dashboard | ✅ OK |
| 18 | Rol admin | Admin lista clientes | ✅ OK |
| 19 | Pedidos ajenos | Cliente A no puede ver el pedido de B (404) | ✅ OK |
| 20 | Pedidos ajenos | Cliente A no puede cancelar el pedido de B | ✅ OK |
| 21 | Pedidos ajenos | El listado solo contiene pedidos propios | ✅ OK |
| 22 | Pedidos ajenos | Cliente A no puede modificar direcciones de B | ✅ OK |
| 23 | Precios | El carrito ignora el precio enviado por el cliente | ✅ OK |
| 24 | Precios | El pedido usa el precio real de la BD (no el total enviado) | ✅ OK |
| 25 | Precios | El estado inicial no puede ser manipulado | ✅ OK |
| 26 | Precios | El propietario del pedido es el usuario autenticado | ✅ OK |
| 27 | Precios | No se puede usar la dirección de otro cliente | ✅ OK |
| 28 | Precios | Un cliente no puede cambiar el precio de un producto | ✅ OK |
| 29 | Stock | No se puede agregar una variante sin stock | ✅ OK |
| 30 | Cantidades | Cantidad negativa (-5) rechazada | ✅ OK |
| 31 | Cantidades | Cantidad cero (0) rechazada | ✅ OK |
| 32 | Cantidades | Cantidad excesiva (1000) rechazada | ✅ OK |
| 33 | Cantidades | Cantidad decimal rechazada | ✅ OK |
| 34 | Cantidades | POS: líneas negativas no compensan totales | ✅ OK |
| 35 | SQL Injection | Búsqueda con ' OR 1=1 -- | ✅ OK |
| 36 | SQL Injection | Búsqueda con '; DROP TABLE productos; | ✅ OK |
| 37 | SQL Injection | Búsqueda con 1' UNION SELECT email FR | ✅ OK |
| 38 | SQL Injection | Búsqueda con \' OR '1'='1 | ✅ OK |
| 39 | SQL Injection | Parámetros de orden/categoría no inyectables | ✅ OK |
| 40 | SQL Injection | ID de pedido malicioso → 404 | ✅ OK |
| 41 | SQL Injection | ID de producto malicioso → 404 | ✅ OK |
| 42 | SQL Injection | La tabla productos sigue intacta | ✅ OK |
| 43 | XSS | Nombre con <script> rechazado por el servidor | ✅ OK |
| 44 | XSS | Dirección con HTML rechazada | ✅ OK |
| 45 | XSS | Término de búsqueda escapado al renderizar | ✅ OK |
| 46 | XSS | CSP sin scripts inline (script-src 'self') | ✅ OK |
| 47 | XSS | Cabeceras X-Content-Type-Options y X-Frame-Options | ✅ OK |
| 48 | CSRF | Login web sin token CSRF rechazado | ✅ OK |
| 49 | CSRF | Login web con token válido | ✅ OK |
| 50 | CSRF | API con cookie de sesión y SIN X-CSRF-Token → 419 | ✅ OK |
| 51 | CSRF | Token CSRF falsificado → 419 | ✅ OK |
| 52 | CSRF | Token CSRF válido → permitido | ✅ OK |
| 53 | CSRF | Cookie de sesión HttpOnly + SameSite=Lax | ✅ OK |
| 54 | Parámetros | Enviar rol=admin en el perfil no eleva privilegios | ✅ OK |
| 55 | Parámetros | usuario_id enviado se ignora (dirección queda del usuario autenticado) | ✅ OK |
| 56 | Parámetros | Método de pago no permitido → 422 | ✅ OK |
| 57 | Parámetros | Endpoint inexistente → 404 JSON | ✅ OK |
| 58 | Errores | JSON inválido → 400 sin trazas internas | ✅ OK |
| 59 | ID de usuario | JWT con "sub" alterado (suplantación) → 401 | ✅ OK |
| 60 | ID de usuario | JWT con alg "none" → 401 | ✅ OK |
| 61 | ID de usuario | JWT con role=service_role falsificado → 401 | ✅ OK |
| 62 | ID de usuario | JWT expirado → 401 TOKEN_EXPIRADO | ✅ OK |
| 63 | Bloqueo | Usuario bloqueado pierde acceso inmediato (403) | ✅ OK |
| 64 | Bloqueo | Usuario bloqueado no puede iniciar sesión | ✅ OK |
| 65 | Roles | Un admin no puede quitarse su propio rol | ✅ OK |
| 66 | Información | Configuración pública no expone claves privadas | ✅ OK |
| 67 | Información | .env no es accesible desde el navegador | ✅ OK |
| 68 | Información | El código fuente no es accesible | ✅ OK |
| 69 | Archivos | Rechaza archivo .php | ✅ OK |
| 70 | Archivos | Rechaza PHP con extensión .jpg | ✅ OK |
| 71 | Archivos | Rechaza SVG con JavaScript | ✅ OK |
| 72 | Archivos | Rechaza PNG falso con código | ✅ OK |
| 73 | Rate limiting | Fuerza bruta de login bloqueada con 429 | ✅ OK |
| 74 | Redirección | next=//evil.example.com no redirige fuera del sitio | ✅ OK |
| 75 | Redirección | _back=//evil.example.com tras error de validación no sale del sitio | ✅ OK |
| 76 | Redirección | Error de validación vuelve al formulario aunque no haya Referer | ✅ OK |
| 77 | Data API | anon no puede leer pedidos | ✅ OK |
| 78 | Data API | anon no puede leer perfiles | ✅ OK |
| 79 | Data API | Cliente solo recibe sus pedidos | ✅ OK |
| 80 | Data API | Cliente no puede modificar precios (PATCH → 0 filas por RLS) | ✅ OK |
| 81 | Data API | Cliente no puede modificar stock (PATCH) | ✅ OK |
| 82 | Data API | Cliente no puede modificar pedidos | ✅ OK |
| 83 | Data API | Cliente no puede invocar RPC administrativas | ✅ OK |
| 84 | Data API | anon no ve productos en borrador | ✅ OK |

### 4.3 Errores encontrados y corregidos durante las pruebas

| Hallazgo | Corrección |
|---|---|
| `Database::fetchValue()` confundía un booleano `false` de PostgreSQL con "sin filas" → el rate limiting **nunca bloqueaba** | Lectura con `FETCH_NUM`; comparación estricta en `RateLimiter` (prueba 13 ahora bloquea al 9.º intento) |
| `sum()` devuelve `bigint` y no coincidía con la firma de `mover_stock(integer)` | Conversión explícita en `procesar_lineas_pedido` |
| Script de pruebas SQL: una operación "de sistema" heredaba los claims del usuario anterior y el trigger `profiles_guard` la bloqueó (comportamiento correcto del trigger) | Limpieza de claims en el script de prueba |
| Inputs `min="1" step="100"` invalidaban precios como 80.000 en el navegador | `step="1"` |
| En escritorio el fondo del menú móvil ocupaba una celda del grid del panel | `display:none` fuera de móvil |
| `ini_set('session.sid_length')` deprecado en PHP 8.4 rompía la API | Eliminado |
| (Revisión final) Si el navegador no enviaba `Referer`, un error de validación en un formulario web redirigía al inicio y se perdía el formulario | Campo oculto `_back` (ruta interna validada) añadido por `csrf_field()`; se rechazan `//…` y `/\…` (pruebas 83–84) |

## 5. Recomendaciones para producción

* Servir solo por **HTTPS** (`COOKIE_SECURE=true`) y apuntar el document root a `public/`.
* En Supabase: activar confirmación de correo, SMTP propio, **claves de firma asimétricas**
  (JWKS), protección contra contraseñas filtradas y CAPTCHA en Auth.
* Rotar la `SUPABASE_SECRET_KEY` si alguna vez se expone; nunca usarla en el navegador ni en la app.
* Usar el *Session pooler* (5432) o activar `DB_EMULATE_PREPARES=true` con el *Transaction pooler*.
* Ejecutar el *Security Advisor* de Supabase tras cada migración.
* Pasarela de pagos: si se integra, confirmar pagos por **webhook firmado** en el backend, nunca desde el cliente.
