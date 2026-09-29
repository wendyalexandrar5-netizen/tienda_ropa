<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * Páginas del panel administrativo. Las acciones (crear, editar, ajustar
 * stock, cambiar estados…) se envían a /api/admin/*, que vuelve a
 * verificar el rol y está protegido además por RLS.
 */
final class AdminController extends Controller
{
    private function page(string $template, array $data): Response
    {
        return $this->view('admin/' . $template, $data, 'admin');
    }

    public function dashboard(Request $request): Response
    {
        return $this->page('dashboard', ['title' => 'Dashboard', 'data' => $this->c->reports()->dashboard(), 'useCharts' => true]);
    }

    public function products(Request $request): Response
    {
        return $this->page('products', [
            'title' => 'Productos',
            'result' => $this->c->adminCatalog()->listProducts($request->query),
            'categories' => $this->c->adminCatalog()->categories(),
            'q' => $request->query,
        ]);
    }

    public function productNew(Request $request): Response
    {
        return $this->page('product-form', [
            'title' => 'Nuevo producto',
            'product' => null,
            'categories' => $this->c->adminCatalog()->categories(),
        ]);
    }

    public function productStore(Request $request): Response
    {
        $product = $this->c->adminCatalog()->createProduct($request->post);
        return $this->redirect('/admin/productos/' . $product['id'], 'success', 'Producto creado. Ahora agrega imágenes y variantes.');
    }

    public function productEdit(Request $request): Response
    {
        $catalog = $this->c->adminCatalog();
        return $this->page('product-form', [
            'title' => 'Editar producto',
            'product' => $catalog->getProduct($request->param('id')),
            'categories' => $catalog->categories(),
            'sizes' => $catalog->sizes(),
            'colors' => $catalog->colors(),
        ]);
    }

    public function productUpdate(Request $request): Response
    {
        $product = $this->c->adminCatalog()->updateProduct($request->param('id'), $request->post);
        return $this->redirect('/admin/productos/' . $product['id'], 'success', 'Producto actualizado.');
    }

    public function categories(Request $request): Response
    {
        return $this->page('categories', ['title' => 'Categorías', 'categories' => $this->c->adminCatalog()->categories()]);
    }

    public function attributes(Request $request): Response
    {
        return $this->page('attributes', [
            'title' => 'Tallas y colores',
            'sizes' => $this->c->adminCatalog()->sizes(),
            'colors' => $this->c->adminCatalog()->colors(),
        ]);
    }

    public function inventory(Request $request): Response
    {
        return $this->page('inventory', [
            'title' => 'Inventario',
            'result' => $this->c->adminInventory()->list($request->query),
            'movements' => $this->c->adminInventory()->movements([]),
            'q' => $request->query,
        ]);
    }

    public function orders(Request $request): Response
    {
        return $this->page('orders', [
            'title' => 'Pedidos',
            'result' => $this->c->adminOrders()->list($request->query),
            'statuses' => $this->c->adminOrders()->statuses(),
            'q' => $request->query,
        ]);
    }

    public function order(Request $request): Response
    {
        $order = $this->c->adminOrders()->get($request->param('id'));
        return $this->page('order', ['title' => 'Pedido ' . numero_pedido($order['numero']), 'order' => $order]);
    }

    public function receipt(Request $request): Response
    {
        $order = $this->c->adminOrders()->get($request->param('id'));
        return $this->view('account/receipt', ['title' => 'Comprobante', 'order' => $order, 'hideFooter' => true]);
    }

    public function pos(Request $request): Response
    {
        return $this->page('pos', ['title' => 'Caja · Venta en tienda']);
    }

    public function customers(Request $request): Response
    {
        return $this->page('customers', ['title' => 'Clientes', 'result' => $this->c->adminUsers()->list($request->query), 'q' => $request->query]);
    }

    public function customer(Request $request): Response
    {
        return $this->page('customer', ['title' => 'Cliente', 'customer' => $this->c->adminUsers()->get($request->param('id'))]);
    }

    public function reports(Request $request): Response
    {
        $r = $this->c->reports();
        $desde = $request->queryString('desde', date('Y-m-d', strtotime('-89 days')));
        $hasta = $request->queryString('hasta', date('Y-m-d'));
        $agrupacion = in_array($request->queryString('agrupacion'), ['day', 'week', 'month'], true) ? $request->queryString('agrupacion') : 'week';
        return $this->page('reports', [
            'title' => 'Reportes',
            'useCharts' => true,
            'desde' => $desde, 'hasta' => $hasta, 'agrupacion' => $agrupacion,
            'sales' => $r->sales($desde, $hasta, $agrupacion),
            'topProducts' => $r->topProducts($desde, $hasta, 10),
            'topCategories' => $r->topCategories($desde, $hasta),
            'byStatus' => $r->ordersByStatus($desde, $hasta),
            'lowStock' => $r->lowStock(),
            'customers' => $r->customers(date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01')))), $hasta, 'month'),
        ]);
    }

    public function settings(Request $request): Response
    {
        return $this->page('settings', ['title' => 'Configuración', 'settings' => $this->c->settings()->all()]);
    }
}
