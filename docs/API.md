# Diseño de la API y guía para la app móvil

> Referencia completa de endpoints (método, autenticación, parámetros,
> respuestas y errores): **[../API_DOCUMENTATION.md](../API_DOCUMENTATION.md)**.

## 1. Principios

* **Una sola API** para web, Android, iOS, Flutter, React Native u otros clientes.
  La web usa los mismos servicios internos; la app móvil consume `/api/v1`.
* **REST + JSON**, recursos en inglés (`/products`, `/cart`, `/orders`…) y
  campos en español `snake_case`, idénticos a las columnas de la BD.
* **Envoltorio uniforme** `{success, data, meta}` / `{success:false, error:{code, message, details}}`
  con `code` estables para que la app muestre mensajes o traduzca.
* **Sin estado** para clientes Bearer (no hay cookies ni sesiones de servidor).
* **Versionado**: `/api/v1/...` (alias de `/api/...`). Cambios incompatibles irían en `/api/v2`.
* **Seguridad en el servidor**: la API nunca acepta precios, totales, propietario
  ni rol del cliente. Los mismos controles aplican aunque alguien use la Data
  API de Supabase directamente (RLS + funciones).

## 2. Mapa de recursos

| Recurso | Endpoints | Acceso |
|---|---|---|
| Auth | `POST /auth/register`, `/auth/login`, `/auth/refresh`, `/auth/recover`, `/auth/logout`, `GET /auth/me` | público / 🔒 |
| Catálogo | `GET /products`, `/products/{id|slug}`, `/categories`, `/categories/{slug}`, `/sizes`, `/colors`, `/config` | público |
| Carrito | `GET/POST/DELETE /cart`, `PUT/DELETE /cart/{id}` | 🔒 |
| Pedidos | `GET/POST /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel` | 🔒 |
| Perfil | `GET/PUT /profile`, `PUT /profile/password` | 🔒 |
| Direcciones | `GET/POST /addresses`, `GET/PUT/DELETE /addresses/{id}` | 🔒 |
| Favoritos | `GET/POST /favorites`, `DELETE /favorites/{id}` | 🔒 |
| Admin | `/admin/dashboard`, `/admin/products`, `/admin/variants`, `/admin/categories`, `/admin/sizes`, `/admin/colors`, `/admin/inventory`, `/admin/orders`, `/admin/pos`, `/admin/users`, `/admin/reports/{tipo}`, `/admin/settings` | 🛡️ |

## 3. ¿API propia o Data API de Supabase?

| Operación | Canal recomendado | Motivo |
|---|---|---|
| Leer catálogo | API propia (o Data API: RLS lo permite de forma segura) | Operación sencilla, pública |
| Carrito | API propia | Validación de cantidades y respuesta con precios calculados |
| Checkout / cancelar / estados / inventario / POS | API propia → funciones SQL | Lógica crítica y transaccional |
| Login / registro / refresh | API propia (envuelve Supabase Auth) o SDK de Supabase Auth | Ambos emiten el mismo JWT, válido en esta API |
| Imágenes | API propia (valida y sube a Storage) | Validación de archivos en el servidor |

La app puede autenticarse con el **SDK oficial de Supabase Auth** si se
prefiere (login social, MFA…): el `access_token` que emite es un JWT del
mismo proyecto y esta API lo acepta tal cual en `Authorization: Bearer`.

## 4. Cómo conectar una app móvil (paso a paso)

1. **Configurar la URL base** de la API (`https://tu-dominio.com/api/v1`).
   La app **no** necesita la URL de PostgreSQL ni ninguna clave secreta.
2. **Login**: `POST /auth/login` → guardar `refresh_token` en almacenamiento
   seguro (Keychain/Keystore; `flutter_secure_storage`, `expo-secure-store`) y
   el `access_token` en memoria.
3. **Peticiones autenticadas**: cabecera `Authorization: Bearer <access_token>`.
4. **Renovación**: ante `401` con `code = TOKEN_EXPIRADO` → `POST /auth/refresh`
   (el refresh token rota: guardar el nuevo) y reintentar una vez.
5. **Catálogo**: `GET /products?categoria=…&talla=M&color=1&orden=precio_asc&page=2`
   (paginación con `meta.paginas`).
6. **Producto**: `GET /products/{slug}` → usar `variantes[]` para construir los
   selectores de talla/color y deshabilitar combinaciones con `stock = 0`.
7. **Carrito**: `POST /cart {variante_id, cantidad}`; mostrar `resumen`.
8. **Checkout**: `GET /addresses` (o `POST /addresses`), luego
   `POST /orders {direccion_id, metodo_pago, clave_idempotencia}`.
   Generar un `clave_idempotencia` (UUID) por intento de compra: si la red
   falla y se reintenta, no se duplica el pedido. El pedido queda con `canal = "app"`.
9. **Historial**: `GET /orders`, `GET /orders/{id}` (estado, `historial`, línea de tiempo).
10. **Errores**: mostrar `error.message` (ya en español) y marcar campos con
    `error.details.campos`.

### Esqueleto sugerido (Flutter)

```
lib/
├── core/api_client.dart        // base URL, Bearer, refresh automático, envoltorio {success,data,error}
├── core/secure_store.dart
├── features/auth/…             // login, registro, recuperar
├── features/catalog/…          // listado, filtros, detalle con variantes
├── features/cart/…
├── features/checkout/…
└── features/account/…          // perfil, direcciones, pedidos
```

No se requieren cambios en la base de datos para la app: canal `app`,
idempotencia, carrito persistente y direcciones ya existen.

## 5. Pruebas rápidas con cURL

```bash
API=http://127.0.0.1:8080/api/v1
TOKEN=$(curl -s -X POST $API/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"camila@firecat.test","password":"FireCat-Demo-2026"}' | jq -r .data.session.access_token)

curl -s "$API/products?categoria=sudaderas&talla=M" | jq '.meta'
curl -s -X POST $API/cart -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"variante_id":57,"cantidad":1}' | jq '.data.resumen'
ADDR=$(curl -s $API/addresses -H "Authorization: Bearer $TOKEN" | jq -r '.data[0].id')
curl -s -X POST $API/orders -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d "{\"direccion_id\":\"$ADDR\",\"metodo_pago\":\"contra_entrega\",\"clave_idempotencia\":\"$(uuidgen)\"}" | jq '.data | {numero,total,estado}'
```
