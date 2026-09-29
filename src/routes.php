<?php

declare(strict_types=1);

use App\Controllers\Api\AdminApiController as AdminApi;
use App\Controllers\Api\AuthApiController as AuthApi;
use App\Controllers\Api\CustomerApiController as CustomerApi;
use App\Controllers\Api\StoreApiController as StoreApi;
use App\Controllers\Web\AccountController as Account;
use App\Controllers\Web\AdminController as Admin;
use App\Controllers\Web\AuthController as Auth;
use App\Controllers\Web\ShopController as Shop;
use App\Core\Router;

$r = new Router();

/*
|--------------------------------------------------------------------------
| API REST  (/api/...  y alias versionado /api/v1/...)
|--------------------------------------------------------------------------
| Autenticación: Authorization: Bearer <access_token de Supabase>  (apps)
|                o cookie de sesión + X-CSRF-Token                   (web)
*/
foreach (['/api', '/api/v1'] as $p) {
    $r->get("$p/health", [StoreApi::class, 'health']);

    // Autenticación (Supabase Auth)
    $r->post("$p/auth/register", [AuthApi::class, 'register']);
    $r->post("$p/auth/login", [AuthApi::class, 'login']);
    $r->post("$p/auth/refresh", [AuthApi::class, 'refresh']);
    $r->post("$p/auth/recover", [AuthApi::class, 'recover']);
    $r->post("$p/auth/logout", [AuthApi::class, 'logout'], ['auth']);
    $r->get("$p/auth/me", [AuthApi::class, 'me'], ['auth']);

    // Catálogo público
    $r->get("$p/products", [StoreApi::class, 'products']);
    $r->get("$p/products/{id}", [StoreApi::class, 'product']);
    $r->get("$p/categories", [StoreApi::class, 'categories']);
    $r->get("$p/categories/{slug}", [StoreApi::class, 'category']);
    $r->get("$p/sizes", [StoreApi::class, 'sizes']);
    $r->get("$p/colors", [StoreApi::class, 'colors']);
    $r->get("$p/config", [StoreApi::class, 'config']);

    // Cliente autenticado
    $r->get("$p/cart", [CustomerApi::class, 'cart'], ['auth']);
    $r->post("$p/cart", [CustomerApi::class, 'cartAdd'], ['auth']);
    $r->delete("$p/cart", [CustomerApi::class, 'cartClear'], ['auth']);
    $r->put("$p/cart/{id}", [CustomerApi::class, 'cartUpdate'], ['auth']);
    $r->delete("$p/cart/{id}", [CustomerApi::class, 'cartRemove'], ['auth']);

    $r->get("$p/orders", [CustomerApi::class, 'orders'], ['auth']);
    $r->post("$p/orders", [CustomerApi::class, 'orderCreate'], ['auth']);
    $r->get("$p/orders/{id}", [CustomerApi::class, 'order'], ['auth']);
    $r->post("$p/orders/{id}/cancel", [CustomerApi::class, 'orderCancel'], ['auth']);

    $r->get("$p/profile", [CustomerApi::class, 'profile'], ['auth']);
    $r->put("$p/profile", [CustomerApi::class, 'profileUpdate'], ['auth']);
    $r->put("$p/profile/password", [CustomerApi::class, 'passwordUpdate'], ['auth']);

    $r->get("$p/addresses", [CustomerApi::class, 'addresses'], ['auth']);
    $r->post("$p/addresses", [CustomerApi::class, 'addressCreate'], ['auth']);
    $r->get("$p/addresses/{id}", [CustomerApi::class, 'address'], ['auth']);
    $r->put("$p/addresses/{id}", [CustomerApi::class, 'addressUpdate'], ['auth']);
    $r->delete("$p/addresses/{id}", [CustomerApi::class, 'addressDelete'], ['auth']);

    $r->get("$p/favorites", [CustomerApi::class, 'favorites'], ['auth']);
    $r->post("$p/favorites", [CustomerApi::class, 'favoriteAdd'], ['auth']);
    $r->delete("$p/favorites/{id}", [CustomerApi::class, 'favoriteRemove'], ['auth']);

    // Administración
    $a = "$p/admin";
    $r->get("$a/dashboard", [AdminApi::class, 'dashboard'], ['admin']);

    $r->get("$a/products", [AdminApi::class, 'products'], ['admin']);
    $r->post("$a/products", [AdminApi::class, 'productCreate'], ['admin']);
    $r->get("$a/products/{id}", [AdminApi::class, 'product'], ['admin']);
    $r->put("$a/products/{id}", [AdminApi::class, 'productUpdate'], ['admin']);
    $r->delete("$a/products/{id}", [AdminApi::class, 'productDelete'], ['admin']);
    $r->post("$a/products/{id}/variants", [AdminApi::class, 'variantCreate'], ['admin']);
    $r->post("$a/products/{id}/images", [AdminApi::class, 'imageUpload'], ['admin']);
    $r->put("$a/products/{id}/images/{image}", [AdminApi::class, 'imageMain'], ['admin']);
    $r->delete("$a/products/{id}/images/{image}", [AdminApi::class, 'imageDelete'], ['admin']);
    $r->put("$a/variants/{id}", [AdminApi::class, 'variantUpdate'], ['admin']);
    $r->delete("$a/variants/{id}", [AdminApi::class, 'variantDelete'], ['admin']);

    $r->get("$a/categories", [AdminApi::class, 'categories'], ['admin']);
    $r->post("$a/categories", [AdminApi::class, 'categoryCreate'], ['admin']);
    $r->put("$a/categories/{id}", [AdminApi::class, 'categoryUpdate'], ['admin']);
    $r->delete("$a/categories/{id}", [AdminApi::class, 'categoryDelete'], ['admin']);

    $r->get("$a/sizes", [AdminApi::class, 'sizes'], ['admin']);
    $r->post("$a/sizes", [AdminApi::class, 'sizeCreate'], ['admin']);
    $r->put("$a/sizes/{id}", [AdminApi::class, 'sizeUpdate'], ['admin']);
    $r->delete("$a/sizes/{id}", [AdminApi::class, 'sizeDelete'], ['admin']);

    $r->get("$a/colors", [AdminApi::class, 'colors'], ['admin']);
    $r->post("$a/colors", [AdminApi::class, 'colorCreate'], ['admin']);
    $r->put("$a/colors/{id}", [AdminApi::class, 'colorUpdate'], ['admin']);
    $r->delete("$a/colors/{id}", [AdminApi::class, 'colorDelete'], ['admin']);

    $r->get("$a/inventory", [AdminApi::class, 'inventory'], ['admin']);
    $r->post("$a/inventory/adjust", [AdminApi::class, 'inventoryAdjust'], ['admin']);
    $r->get("$a/inventory/movements", [AdminApi::class, 'inventoryMovements'], ['admin']);

    $r->get("$a/orders", [AdminApi::class, 'orders'], ['admin']);
    $r->get("$a/orders/{id}", [AdminApi::class, 'order'], ['admin']);
    $r->put("$a/orders/{id}/status", [AdminApi::class, 'orderStatus'], ['admin']);

    $r->get("$a/pos/search", [AdminApi::class, 'posSearch'], ['admin']);
    $r->post("$a/pos/sales", [AdminApi::class, 'posSale'], ['admin']);

    $r->get("$a/users", [AdminApi::class, 'users'], ['admin']);
    $r->get("$a/users/{id}", [AdminApi::class, 'userShow'], ['admin']);
    $r->put("$a/users/{id}", [AdminApi::class, 'userUpdate'], ['admin']);

    $r->get("$a/reports/{type}", [AdminApi::class, 'report'], ['admin']);
    $r->get("$a/settings", [AdminApi::class, 'settings'], ['admin']);
    $r->put("$a/settings", [AdminApi::class, 'settingsUpdate'], ['admin']);
}

/*
|--------------------------------------------------------------------------
| Tienda web
|--------------------------------------------------------------------------
*/
$r->get('/', [Shop::class, 'home']);
$r->get('/catalogo', [Shop::class, 'catalog']);
$r->get('/categoria/{slug}', [Shop::class, 'category']);
$r->get('/producto/{slug}', [Shop::class, 'product']);
$r->get('/carrito', [Shop::class, 'cart']);
$r->get('/checkout', [Shop::class, 'checkout'], ['auth']);
$r->get('/pedido/{id}/confirmacion', [Shop::class, 'confirmation'], ['auth']);

$r->get('/login', [Auth::class, 'loginForm'], ['guest']);
$r->post('/login', [Auth::class, 'login'], ['guest']);
$r->get('/registro', [Auth::class, 'registerForm'], ['guest']);
$r->post('/registro', [Auth::class, 'register'], ['guest']);
$r->post('/logout', [Auth::class, 'logout']);
$r->get('/recuperar', [Auth::class, 'forgotForm'], ['guest']);
$r->post('/recuperar', [Auth::class, 'forgot'], ['guest']);
$r->get('/restablecer', [Auth::class, 'resetForm']);
$r->post('/restablecer/sesion', [Auth::class, 'resetSession']);
$r->post('/restablecer', [Auth::class, 'reset'], ['auth']);

$r->get('/cuenta', [Account::class, 'profile'], ['auth']);
$r->post('/cuenta/perfil', [Account::class, 'profileUpdate'], ['auth']);
$r->post('/cuenta/password', [Account::class, 'passwordUpdate'], ['auth']);
$r->get('/cuenta/pedidos', [Account::class, 'orders'], ['auth']);
$r->get('/cuenta/pedidos/{id}', [Account::class, 'order'], ['auth']);
$r->get('/cuenta/pedidos/{id}/comprobante', [Account::class, 'receipt'], ['auth']);
$r->post('/cuenta/pedidos/{id}/cancelar', [Account::class, 'cancelOrder'], ['auth']);
$r->get('/cuenta/direcciones', [Account::class, 'addresses'], ['auth']);
$r->post('/cuenta/direcciones', [Account::class, 'addressSave'], ['auth']);
$r->post('/cuenta/direcciones/{id}/eliminar', [Account::class, 'addressDelete'], ['auth']);
$r->get('/cuenta/favoritos', [Account::class, 'favorites'], ['auth']);

$r->get('/admin', [Admin::class, 'dashboard'], ['admin']);
$r->get('/admin/productos', [Admin::class, 'products'], ['admin']);
$r->get('/admin/productos/nuevo', [Admin::class, 'productNew'], ['admin']);
$r->post('/admin/productos', [Admin::class, 'productStore'], ['admin']);
$r->get('/admin/productos/{id}', [Admin::class, 'productEdit'], ['admin']);
$r->post('/admin/productos/{id}', [Admin::class, 'productUpdate'], ['admin']);
$r->get('/admin/categorias', [Admin::class, 'categories'], ['admin']);
$r->get('/admin/variantes', [Admin::class, 'attributes'], ['admin']);
$r->get('/admin/inventario', [Admin::class, 'inventory'], ['admin']);
$r->get('/admin/pedidos', [Admin::class, 'orders'], ['admin']);
$r->get('/admin/pedidos/{id}', [Admin::class, 'order'], ['admin']);
$r->get('/admin/pedidos/{id}/comprobante', [Admin::class, 'receipt'], ['admin']);
$r->get('/admin/caja', [Admin::class, 'pos'], ['admin']);
$r->get('/admin/clientes', [Admin::class, 'customers'], ['admin']);
$r->get('/admin/clientes/{id}', [Admin::class, 'customer'], ['admin']);
$r->get('/admin/reportes', [Admin::class, 'reports'], ['admin']);
$r->get('/admin/configuracion', [Admin::class, 'settings'], ['admin']);

return $r;
