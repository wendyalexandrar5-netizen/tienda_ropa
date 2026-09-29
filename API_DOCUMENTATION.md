# FIRE CAT · Documentación de la API REST

API única para la **tienda web**, la **futura app móvil** (Flutter / React
Native / Android / iOS) y cualquier otro cliente. La app móvil **nunca** se
conecta a PostgreSQL: consume esta API.

* **Base URL:** `https://tu-dominio.com/api` · alias versionado: `/api/v1`
* **Formato:** JSON UTF-8 (`Content-Type: application/json`), salvo la subida de imágenes (`multipart/form-data`).
* **Nombres de campos:** `snake_case` en español, iguales a las columnas de la BD.
* **Dinero:** números en pesos colombianos (COP). **Fechas:** ISO 8601 con zona horaria.

## 1. Autenticación

La autenticación la gestiona **Supabase Auth**. Hay dos formas de autenticarse:

| Cliente | Cómo | CSRF |
|---|---|---|
| App móvil / otros clientes | `Authorization: Bearer <access_token>` | No aplica (no usa cookies) |
| Tienda web (navegador) | Cookie de sesión `fc_session` (HttpOnly) | Obligatorio: cabecera `X-CSRF-Token` en POST/PUT/DELETE |

El `access_token` es un JWT de Supabase (≈1 h). Cuando expira, se renueva con
`POST /api/auth/refresh`. El backend verifica la firma (JWKS ES256/RS256 o
secreto HS256), la expiración y la audiencia, y **toma el rol desde la tabla
`profiles`**, nunca desde el cliente.

Niveles de acceso usados en esta guía: 🌐 público · 🔒 cliente autenticado · 🛡️ administrador.

## 2. Formato de respuesta

```json
// Éxito
{ "success": true, "data": { ... }, "meta": { "total": 20, "pagina": 1, "por_pagina": 12, "paginas": 2 } }

// Error
{ "success": false, "error": { "code": "STOCK_INSUFICIENTE", "message": "Solo hay 2 unidad(es) disponibles…", "details": { } } }
```

`meta` solo aparece en listados paginados. Los errores de validación incluyen
`details.campos` con el mensaje por campo:

```json
{ "success": false, "error": { "code": "VALIDACION", "message": "Revisa los datos enviados.",
  "details": { "campos": { "cantidad": "El campo cantidad debe ser mayor o igual a 1." } } } }
```

### Códigos de error

| HTTP | `code` | Significado |
|---|---|---|
| 400 | `JSON_INVALIDO` | Cuerpo JSON mal formado |
| 401 | `NO_AUTENTICADO`, `TOKEN_INVALIDO`, `TOKEN_EXPIRADO`, `CREDENCIALES_INVALIDAS`, `SESION_INVALIDA` | Falta sesión, token inválido/expirado o credenciales incorrectas |
| 403 | `ACCESO_DENEGADO`, `USUARIO_BLOQUEADO`, `OPERACION_NO_PERMITIDA`, `EMAIL_NO_CONFIRMADO` | Sin permisos / cuenta bloqueada |
| 404 | `NO_ENCONTRADO`, `PEDIDO_NO_ENCONTRADO` | No existe **o no te pertenece** (no se revela la diferencia) |
| 405 | `METODO_NO_PERMITIDO` | Método HTTP no soportado en la ruta |
| 409 | `STOCK_INSUFICIENTE`, `PRODUCTO_NO_DISPONIBLE`, `TRANSICION_INVALIDA`, `DUPLICADO`, `EN_USO`, `EMAIL_REGISTRADO` | Conflicto con el estado actual |
| 419 | `CSRF_INVALIDO` | Token CSRF ausente o inválido (solo sesiones web) |
| 422 | `VALIDACION`, `DATOS_INVALIDOS`, `CANTIDAD_INVALIDA`, `CARRITO_VACIO`, `DIRECCION_INVALIDA`, `METODO_PAGO_INVALIDO`, `PAGO_INSUFICIENTE`, `IMAGEN_INVALIDA`, `CONTRASENA_DEBIL` | Datos inválidos o regla de negocio incumplida |
| 429 | `DEMASIADAS_SOLICITUDES` | Rate limiting (login, registro, recuperación, checkout) |
| 500/503 | `ERROR_INTERNO`, `SERVICIO_NO_DISPONIBLE` | Error del servidor (sin detalles internos) |

---

## 3. Autenticación (`/api/auth`)

### POST /api/auth/register 🌐
Registra un cliente en Supabase Auth. El rol siempre es `cliente` (un campo `rol` enviado se ignora).

| Parámetro (body) | Tipo | Requerido | Reglas |
|---|---|---|---|
| `nombre`, `apellido` | string | sí | 2–80 letras |
| `email` | string | sí | correo válido |
| `password` | string | sí | 8–72 caracteres, letras y números |
| `telefono` | string | no | 7–20 dígitos |

**201** – con confirmación de correo desactivada devuelve la sesión (igual que login).
Con confirmación activada: `{ "success": true, "data": { "requiere_confirmacion": true, "mensaje": "…" } }`.
Errores: 409 `EMAIL_REGISTRADO`, 422 `VALIDACION`/`CONTRASENA_DEBIL`, 429.

### POST /api/auth/login 🌐
```http
POST /api/auth/login
Content-Type: application/json

{ "email": "camila@firecat.test", "password": "FireCat-Demo-2026" }
```
**200**
```json
{
  "success": true,
  "data": {
    "user": { "id": "9b0c…", "email": "camila@firecat.test", "nombre": "Camila", "apellido": "Rojas", "rol": "cliente", "estado": "activo" },
    "session": { "access_token": "eyJhbGciOi…", "refresh_token": "v1.Mr5…", "token_type": "bearer", "expires_in": 3600, "expires_at": 1790712555 }
  }
}
```
Errores: 401 `CREDENCIALES_INVALIDAS`, 403 `USUARIO_BLOQUEADO`/`EMAIL_NO_CONFIRMADO`, 429 (8 intentos/15 min por correo, 30 por IP).

### POST /api/auth/refresh 🌐
Body: `{ "refresh_token": "…" }` → **200** misma estructura que login (el refresh token rota). 401 `SESION_INVALIDA`.

### POST /api/auth/logout 🔒
Revoca la sesión en Supabase Auth. **200** `{ "mensaje": "Sesión cerrada." }`

### POST /api/auth/recover 🌐
Body: `{ "email": "…" }`. Envía el correo de recuperación de Supabase Auth.
Responde **200** siempre igual (no revela si el correo existe). 429 si se abusa (3/15 min por correo).

### GET /api/auth/me 🔒
Perfil del usuario autenticado (igual que `GET /api/profile`).

---

## 4. Catálogo público

### GET /api/products 🌐
Lista paginada de productos **activos**.

| Query | Tipo | Descripción |
|---|---|---|
| `q` | string | Búsqueda de texto (nombre/descripción, español) |
| `categoria` | slug | p. ej. `sudaderas` |
| `talla` | lista | Códigos: `talla=M,L` o `talla[]=M` (solo variantes con stock) |
| `color` | lista | IDs de color: `color=1,5` |
| `precio_min`, `precio_max` | número | Rango sobre el precio "desde" |
| `disponible` | `1` | Solo con stock |
| `ofertas` | `1` | Solo con `precio_anterior` |
| `destacado` | `1` | Solo destacados |
| `orden` | enum | `destacados` (defecto), `recientes`, `precio_asc`, `precio_desc`, `nombre` |
| `page`/`pagina`, `per_page`/`por_pagina` | int | Por defecto 1 y 12 (máx. 48) |

**200**
```json
{
  "success": true,
  "data": [
    {
      "id": 10, "slug": "hoodie-fire-street", "nombre": "Hoodie Fire Street",
      "precio": 169900.0, "precio_anterior": 199900.0, "precio_desde": 169900.0,
      "destacado": true, "categoria_id": 3, "categoria_nombre": "Sudaderas", "categoria_slug": "sudaderas",
      "imagen_url": "/assets/img/productos/10.jpg", "imagen_secundaria_url": null,
      "stock_total": 57, "disponible": true,
      "colores": [{ "id": 5, "nombre": "Rojo", "hex": "#C62828" }]
    }
  ],
  "meta": { "total": 20, "pagina": 1, "por_pagina": 12, "paginas": 2 }
}
```

### GET /api/products/{id|slug} 🌐
Detalle con imágenes, **variantes (talla × color con stock y precio)**, tallas, colores y `relacionados`.
```json
{
  "success": true,
  "data": {
    "id": 10, "slug": "hoodie-fire-street", "nombre": "Hoodie Fire Street", "descripcion": "…",
    "precio": 169900.0, "precio_anterior": 199900.0, "destacado": true, "stock_total": 57,
    "categoria": { "id": 3, "nombre": "Sudaderas", "slug": "sudaderas" },
    "imagenes": [{ "id": 10, "url": "/assets/img/productos/10.jpg", "alt": "…", "color_id": null, "es_principal": true }],
    "variantes": [
      { "id": 57, "sku": "FC-010-S-ROJ", "talla_id": 2, "talla": "S", "color_id": 5, "color": "Rojo", "hex": "#C62828", "precio": 169900.0, "stock": 20, "disponible": true },
      { "id": 58, "sku": "FC-010-M-ROJ", "talla_id": 3, "talla": "M", "color_id": 5, "color": "Rojo", "hex": "#C62828", "precio": 169900.0, "stock": 0, "disponible": false }
    ],
    "tallas": [{ "id": 2, "codigo": "S", "nombre": "Pequeña" }],
    "colores": [{ "id": 5, "nombre": "Rojo", "hex": "#C62828" }],
    "relacionados": [ … ]
  }
}
```
404 si no existe o no está activo.

### GET /api/categories 🌐
Categorías activas con número de productos: `[{ "id", "nombre", "slug", "descripcion", "imagen_url", "orden", "parent_id", "productos" }]`.

### GET /api/categories/{slug} 🌐
Categoría + `productos` (mismo formato paginado y filtros que `/api/products`).

### GET /api/sizes · GET /api/colors · GET /api/config 🌐
Tallas `[{id, codigo, nombre, orden}]`, colores `[{id, nombre, hex}]` y configuración pública
(`nombre_tienda`, `costo_envio`, `envio_gratis_desde`, `moneda`, `mensaje_banner`, …).

### GET /api/health 🌐
`{ "success": true, "data": { "api": "ok", "database": "ok", "version": "v1" } }`

---

## 5. Carrito 🔒

El carrito se guarda en la BD (sincronizado entre web y app). **No almacena
precios**: cada respuesta los calcula desde la base de datos.

Respuesta común (`GET/POST/PUT/DELETE /api/cart…`):
```json
{
  "success": true,
  "data": {
    "items": [{
      "id": 31, "cantidad": 2, "variante_id": 57, "sku": "FC-010-S-ROJ", "stock": 20,
      "talla": "S", "color": "Rojo", "hex": "#C62828",
      "producto_id": 10, "producto_nombre": "Hoodie Fire Street", "slug": "hoodie-fire-street",
      "precio_unitario": 169900.0, "subtotal": 339800.0, "disponible": true,
      "imagen_url": "/assets/img/productos/10.jpg"
    }],
    "resumen": {
      "lineas": 1, "unidades": 2, "subtotal": 339800.0, "costo_envio": 0.0, "total": 339800.0,
      "envio_gratis_desde": 250000.0, "falta_para_envio_gratis": 0, "todo_disponible": true
    }
  }
}
```

### GET /api/cart
Carrito actual.

### POST /api/cart
| Body | Tipo | Requerido | Reglas |
|---|---|---|---|
| `variante_id` | int | sí | variante activa de un producto activo |
| `cantidad` | int | no (1) | 1–20; la suma con lo ya agregado no puede superar el stock ni 20 |

Cualquier campo de precio enviado se **ignora**. **201** carrito + `item_id`.
Errores: 409 `STOCK_INSUFICIENTE` / `PRODUCTO_NO_DISPONIBLE`, 422 `VALIDACION`/`CANTIDAD_INVALIDA`.

### PUT /api/cart/{id}
Body `{ "cantidad": 3 }` → **200** carrito. 404 si la línea no es tuya. 409 si supera el stock.

### DELETE /api/cart/{id}
Elimina una línea → **200** carrito. · **DELETE /api/cart** vacía el carrito.

---

## 6. Pedidos 🔒

### POST /api/orders
Crea el pedido a partir del carrito, de forma **atómica** (función `crear_pedido`):
valida dirección, bloquea las variantes (`SELECT … FOR UPDATE`), **revalida el
stock**, toma el **precio real** de la BD, crea `pedidos` + `pedido_detalle`
(precio histórico), descuenta inventario, registra el kardex y vacía el carrito.

| Body | Tipo | Requerido | Reglas |
|---|---|---|---|
| `direccion_id` | uuid | sí | debe pertenecer al usuario |
| `metodo_pago` | enum | sí | `contra_entrega` \| `transferencia` |
| `notas` | string | no | máx. 500 |
| `clave_idempotencia` | string | no (recomendado en móvil) | 8–64 `[A-Za-z0-9_-]`; reenviar la misma clave devuelve el mismo pedido |

`total`, `subtotal`, `precio`, `usuario_id`, `estado`, `items`… **se ignoran**.
El canal se registra como `app` (Bearer) o `web` (sesión).

```http
POST /api/orders
Authorization: Bearer eyJhbGciOi…
Content-Type: application/json

{ "direccion_id": "15cfbc7b-59cc-4433-9088-295e33e11b23", "metodo_pago": "contra_entrega", "clave_idempotencia": "app-7f3c9a21e0" }
```
**201** – detalle del pedido (ver `GET /api/orders/{id}`).
Errores: 409 `STOCK_INSUFICIENTE`/`PRODUCTO_NO_DISPONIBLE`, 422 `CARRITO_VACIO`/`DIRECCION_INVALIDA`/`METODO_PAGO_INVALIDO`, 403 `USUARIO_BLOQUEADO`, 429 (10 pedidos/10 min).

### GET /api/orders
Historial propio, paginado. Query: `page`, `estado` (`pendiente`, `confirmado`, `preparando`, `enviado`, `entregado`, `cancelado`).
```json
{ "success": true, "data": [{
    "id": "d3415f69-…", "numero": 1008, "estado": "pendiente", "estado_nombre": "Pendiente", "estado_color": "warning",
    "canal": "app", "metodo_pago": "contra_entrega", "subtotal": 434700.0, "costo_envio": 0.0, "total": 434700.0,
    "unidades": 3, "miniaturas": ["/assets/img/productos/19.jpg"], "created_at": "2026-09-29T19:09:35+00:00" }],
  "meta": { "total": 1, "pagina": 1, "por_pagina": 10, "paginas": 1 } }
```

### GET /api/orders/{id}
Detalle: `items` (con `precio_unitario` histórico, talla, color, SKU), `historial` de estados,
`direccion_envio` (copia al momento de la compra), `siguientes_estados`, `puede_cancelar`.
**404** si no existe **o es de otro usuario**.

### POST /api/orders/{id}/cancel
Cancela un pedido propio en estado `pendiente` y **repone el inventario**. 409 `TRANSICION_INVALIDA` en otro estado.

---

## 7. Perfil y direcciones 🔒

| Método | Endpoint | Body | Respuesta |
|---|---|---|---|
| GET | `/api/profile` | – | `{id, email, nombre, apellido, telefono, rol, estado, fecha_registro}` |
| PUT | `/api/profile` | `nombre`, `apellido`, `telefono?` | Perfil actualizado (`rol`/`estado`/`email` se ignoran) |
| PUT | `/api/profile/password` | `password_actual`, `password` | `{mensaje}` · 422 si la actual no coincide |
| GET | `/api/addresses` | – | Lista (principal primero) |
| POST | `/api/addresses` | ver abajo | **201** dirección (máx. 10) |
| GET | `/api/addresses/{id}` | – | Dirección · 404 si no es tuya |
| PUT | `/api/addresses/{id}` | ver abajo | Dirección |
| DELETE | `/api/addresses/{id}` | – | `{eliminado: true}` |

Campos de dirección: `destinatario`*, `telefono`*, `direccion`*, `ciudad`*, `departamento`*,
`alias`, `detalle`, `codigo_postal`, `pais` (defecto Colombia), `es_principal` (bool).
Un `usuario_id` enviado se ignora: la dirección siempre pertenece al usuario autenticado.

## 8. Favoritos 🔒

| Método | Endpoint | Body |
|---|---|---|
| GET | `/api/favorites` | – (lista de productos) |
| POST | `/api/favorites` | `{ "producto_id": 10 }` → 201 |
| DELETE | `/api/favorites/{producto_id}` | – |

---

## 9. Administración 🛡️ (`/api/admin`)

Todas requieren rol `admin` (verificado en el backend **y** en PostgreSQL con `is_admin()`).
Un cliente recibe **403**.

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/api/admin/dashboard` | KPIs (ventas hoy/mes, pedidos pendientes, clientes, inventario, stock bajo), ventas 30 días, top productos |
| GET | `/api/admin/products` | Todos los productos (cualquier estado). Query: `q`, `estado`, `categoria_id`, `page` |
| POST | `/api/admin/products` | Crear: `nombre`*, `categoria_id`*, `precio`*, `descripcion`, `precio_anterior`, `estado` (`borrador`\|`activo`\|`inactivo`), `destacado`, `slug` |
| GET/PUT/DELETE | `/api/admin/products/{id}` | Detalle (con variantes e imágenes) / actualizar / eliminar |
| POST | `/api/admin/products/{id}/variants` | `talla_id`*, `color_id`*, `stock_inicial`, `precio`, `sku`, `stock_minimo` |
| PUT/DELETE | `/api/admin/variants/{id}` | `sku`, `precio`, `stock_minimo`, `activa` (**el stock no se edita aquí**) |
| POST | `/api/admin/products/{id}/images` | `multipart/form-data`: `imagen` (JPG/PNG/WEBP, ≤ 5 MB), `alt`, `color_id` |
| PUT | `/api/admin/products/{id}/images/{imageId}` | Marcar como principal |
| DELETE | `/api/admin/products/{id}/images/{imageId}` | Eliminar imagen (también de Storage) |
| GET/POST | `/api/admin/categories` | Listar / crear (`nombre`*, `slug`, `descripcion`, `imagen_url`, `activa`, `orden`) |
| PUT/DELETE | `/api/admin/categories/{id}` | Actualizar / eliminar (409 si tiene productos) |
| GET/POST, PUT/DELETE | `/api/admin/sizes[/{id}]` | Tallas: `codigo`*, `nombre`*, `orden` |
| GET/POST, PUT/DELETE | `/api/admin/colors[/{id}]` | Colores: `nombre`*, `hex`* (`#RRGGBB`) |
| GET | `/api/admin/inventory` | Stock por variante. Query: `q`, `filtro` (`bajo`\|`agotado`) |
| POST | `/api/admin/inventory/adjust` | `variante_id`*, `tipo`* (`entrada`\|`salida`\|`ajuste`), `cantidad`* (≠0), `motivo`* → `{variante_id, stock}` |
| GET | `/api/admin/inventory/movements` | Kardex (últimos 50). Query: `variante_id` |
| GET | `/api/admin/orders` | Todos los pedidos. Query: `estado`, `canal` (`web`\|`app`\|`pos`), `q` (número/cliente), `desde`, `hasta`, `page` |
| GET | `/api/admin/orders/{id}` | Detalle completo con cliente |
| PUT | `/api/admin/orders/{id}/status` | `estado`*, `comentario`. Solo transiciones válidas; `cancelado` repone stock |
| GET | `/api/admin/pos/search?q=` | Buscar variantes para venta en tienda |
| POST | `/api/admin/pos/sales` | Venta en tienda: `items`* `[{variante_id, cantidad}]`, `pago_recibido`*, `metodo_pago`, `cliente_nombre` → pedido con `cambio` |
| GET | `/api/admin/users` | Usuarios con nº de pedidos y total comprado. Query: `q`, `rol`, `estado` |
| GET/PUT | `/api/admin/users/{id}` | Detalle / cambiar `rol` y `estado` (no aplica a uno mismo) |
| GET | `/api/admin/reports/{tipo}` | `sales` (`agrupacion`=day\|week\|month), `top-products` (`limite`), `top-categories`, `orders-by-status`, `low-stock` (`umbral`), `customers`. Query: `desde`, `hasta` (AAAA-MM-DD) |
| GET/PUT | `/api/admin/settings` | Configuración de la tienda (lista blanca de claves) |

Ejemplo – cambio de estado:
```http
PUT /api/admin/orders/049ca9df-338d-4a0f-90ef-1579a58ae488/status
Authorization: Bearer <token de admin>
Content-Type: application/json

{ "estado": "confirmado", "comentario": "Pago verificado" }
```
Transiciones permitidas: `pendiente → confirmado | cancelado`, `confirmado → preparando | cancelado`,
`preparando → enviado | cancelado`, `enviado → entregado`. Otra transición → 409 `TRANSICION_INVALIDA`.

Ejemplo – venta en tienda (caja):
```json
POST /api/admin/pos/sales
{ "items": [{ "variante_id": 57, "cantidad": 1 }], "pago_recibido": 200000, "metodo_pago": "efectivo" }
→ 201 { "success": true, "data": { "numero": 1025, "canal": "pos", "estado": "entregado", "total": 169900.0, "pago_recibido": 200000.0, "cambio": 30100.0, … } }
```

---

## 10. Ejemplo de consumo desde una app móvil

```dart
// Flutter (paquete http)
final login = await http.post(Uri.parse('$api/auth/login'),
  headers: {'Content-Type': 'application/json'},
  body: jsonEncode({'email': email, 'password': password}));
final session = jsonDecode(login.body)['data']['session'];
await secureStorage.write(key: 'refresh_token', value: session['refresh_token']);

final res = await http.get(Uri.parse('$api/orders'),
  headers: {'Authorization': 'Bearer ${session['access_token']}'});
```

```js
// React Native (fetch)
const res = await fetch(`${API}/cart`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${accessToken}`, 'Content-Type': 'application/json' },
  body: JSON.stringify({ variante_id: 57, cantidad: 1 }),
});
const json = await res.json();
if (!json.success) Alert.alert('Error', json.error.message);
```

Guarda el `refresh_token` en almacenamiento seguro (Keychain / Keystore) y
renueva el `access_token` con `POST /api/auth/refresh` al recibir `401 TOKEN_EXPIRADO`.
