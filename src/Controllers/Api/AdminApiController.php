<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * API administrativa (/api/admin/*). Todas las rutas usan el middleware
 * 'admin' y, además, la base de datos verifica is_admin() en cada escritura.
 */
final class AdminApiController extends Controller
{
    // ------------------------------------------------------------ Dashboard

    public function dashboard(Request $request): Response
    {
        return $this->ok($this->c->reports()->dashboard());
    }

    // ------------------------------------------------------------ Productos

    public function products(Request $request): Response
    {
        return $this->paginated($this->c->adminCatalog()->listProducts($request->query));
    }

    public function product(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->getProduct($request->param('id')));
    }

    public function productCreate(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->createProduct($request->input()), 201);
    }

    public function productUpdate(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->updateProduct($request->param('id'), $request->input()));
    }

    public function productDelete(Request $request): Response
    {
        $this->c->adminCatalog()->deleteProduct($request->param('id'), $this->user());
        return $this->ok(['eliminado' => true]);
    }

    public function variantCreate(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->createVariant($request->param('id'), $request->input()), 201);
    }

    public function variantUpdate(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->updateVariant($request->param('id'), $request->input()));
    }

    public function variantDelete(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->deleteVariant($request->param('id')));
    }

    public function imageUpload(Request $request): Response
    {
        $file = $request->files['imagen'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw HttpException::validation(['imagen' => 'Selecciona una imagen.']);
        }
        return $this->ok($this->c->adminCatalog()->uploadImage($request->param('id'), $file, $request->post, $this->user()), 201);
    }

    public function imageMain(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->setMainImage($request->param('id'), $request->param('image')));
    }

    public function imageDelete(Request $request): Response
    {
        return $this->ok($this->c->adminCatalog()->deleteImage($request->param('id'), $request->param('image'), $this->user()));
    }

    // ----------------------------------------------- Categorías, tallas, colores

    public function categories(Request $request): Response { return $this->ok($this->c->adminCatalog()->categories()); }
    public function categoryCreate(Request $request): Response { return $this->ok($this->c->adminCatalog()->saveCategory(null, $request->input()), 201); }
    public function categoryUpdate(Request $request): Response { return $this->ok($this->c->adminCatalog()->saveCategory($request->param('id'), $request->input())); }

    public function categoryDelete(Request $request): Response
    {
        $this->c->adminCatalog()->deleteCategory($request->param('id'));
        return $this->ok(['eliminado' => true]);
    }

    public function sizes(Request $request): Response { return $this->ok($this->c->adminCatalog()->sizes()); }
    public function sizeCreate(Request $request): Response { return $this->ok($this->c->adminCatalog()->saveSize(null, $request->input()), 201); }
    public function sizeUpdate(Request $request): Response { return $this->ok($this->c->adminCatalog()->saveSize($request->param('id'), $request->input())); }

    public function sizeDelete(Request $request): Response
    {
        $this->c->adminCatalog()->deleteSize($request->param('id'));
        return $this->ok(['eliminado' => true]);
    }

    public function colors(Request $request): Response { return $this->ok($this->c->adminCatalog()->colors()); }
    public function colorCreate(Request $request): Response { return $this->ok($this->c->adminCatalog()->saveColor(null, $request->input()), 201); }
    public function colorUpdate(Request $request): Response { return $this->ok($this->c->adminCatalog()->saveColor($request->param('id'), $request->input())); }

    public function colorDelete(Request $request): Response
    {
        $this->c->adminCatalog()->deleteColor($request->param('id'));
        return $this->ok(['eliminado' => true]);
    }

    // ----------------------------------------------------------- Inventario

    public function inventory(Request $request): Response
    {
        return $this->paginated($this->c->adminInventory()->list($request->query));
    }

    public function inventoryAdjust(Request $request): Response
    {
        return $this->ok($this->c->adminInventory()->adjust($request->input()));
    }

    public function inventoryMovements(Request $request): Response
    {
        return $this->ok($this->c->adminInventory()->movements($request->query));
    }

    public function posSearch(Request $request): Response
    {
        return $this->ok($this->c->adminInventory()->searchForPos($request->queryString('q')));
    }

    // -------------------------------------------------------------- Pedidos

    public function orders(Request $request): Response
    {
        return $this->paginated($this->c->adminOrders()->list($request->query));
    }

    public function order(Request $request): Response
    {
        return $this->ok($this->c->adminOrders()->get($request->param('id')));
    }

    public function orderStatus(Request $request): Response
    {
        return $this->ok($this->c->adminOrders()->changeStatus($request->param('id'), $request->input()));
    }

    public function posSale(Request $request): Response
    {
        return $this->ok($this->c->adminOrders()->registerPosSale($request->input()), 201);
    }

    // ------------------------------------------------------------- Usuarios

    public function users(Request $request): Response
    {
        return $this->paginated($this->c->adminUsers()->list($request->query));
    }

    public function userShow(Request $request): Response
    {
        return $this->ok($this->c->adminUsers()->get($request->param('id')));
    }

    public function userUpdate(Request $request): Response
    {
        return $this->ok($this->c->adminUsers()->update($request->param('id'), $request->input(), $this->user()->id));
    }

    // ------------------------------------------------------------- Reportes

    public function report(Request $request): Response
    {
        $r = $this->c->reports();
        $desde = $request->queryString('desde', date('Y-m-d', strtotime('-29 days')));
        $hasta = $request->queryString('hasta', date('Y-m-d'));
        $agrupacion = $request->queryString('agrupacion', 'day');

        $data = match ($request->param('type')) {
            'sales' => $r->sales($desde, $hasta, $agrupacion),
            'top-products' => $r->topProducts($desde, $hasta, (int) $request->queryString('limite', '10')),
            'top-categories' => $r->topCategories($desde, $hasta),
            'orders-by-status' => $r->ordersByStatus($desde, $hasta),
            'low-stock' => $r->lowStock(ctype_digit($request->queryString('umbral')) ? (int) $request->queryString('umbral') : null),
            'customers' => $r->customers($desde, $hasta, $request->queryString('agrupacion', 'month')),
            default => throw HttpException::notFound('Reporte no encontrado.'),
        };
        return $this->ok($data, 200, ['desde' => $desde, 'hasta' => $hasta]);
    }

    // -------------------------------------------------------- Configuración

    public function settings(Request $request): Response
    {
        return $this->ok($this->c->settings()->all());
    }

    public function settingsUpdate(Request $request): Response
    {
        return $this->ok($this->c->settings()->update($request->input()));
    }
}
