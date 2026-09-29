<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\CatalogService;

/**
 * Páginas públicas de la tienda. Usan los mismos servicios que la API REST,
 * por lo que las reglas de negocio son idénticas para web y app móvil.
 */
final class ShopController extends Controller
{
    public function home(Request $request): Response
    {
        $catalog = $this->c->catalog();
        return $this->view('shop/home', [
            'categories' => $catalog->categories(),
            'featured' => $catalog->listProducts(CatalogService::normalizeFilters(['destacado' => '1', 'per_page' => 4]))['items'],
            'newest' => $catalog->listProducts(CatalogService::normalizeFilters(['orden' => 'recientes', 'per_page' => 4]))['items'],
            'sale' => $catalog->listProducts(CatalogService::normalizeFilters(['ofertas' => '1', 'per_page' => 4]))['items'],
            'favIds' => $this->favIds(),
        ]);
    }

    public function catalog(Request $request, ?array $category = null): Response
    {
        $catalog = $this->c->catalog();
        $query = $request->query;
        if ($category !== null) {
            $query['categoria'] = $category['slug'];
        }
        $filters = CatalogService::normalizeFilters($query);
        $result = $catalog->listProducts($filters);

        return $this->view('shop/catalog', [
            'title' => $category['nombre'] ?? ($filters['q'] !== '' ? 'Resultados para “' . $filters['q'] . '”' : 'Tienda'),
            'category' => $category,
            'filters' => $filters,
            'result' => $result,
            'categories' => $catalog->categories(),
            'sizes' => $catalog->sizes(),
            'colors' => $catalog->colors(),
            'favIds' => $this->favIds(),
        ]);
    }

    public function category(Request $request): Response
    {
        return $this->catalog($request, $this->c->catalog()->category($request->param('slug')));
    }

    public function product(Request $request): Response
    {
        $catalog = $this->c->catalog();
        $product = $catalog->getProduct($request->param('slug'));
        return $this->view('shop/product', [
            'title' => $product['nombre'],
            'description' => mb_substr((string) $product['descripcion'], 0, 160),
            'product' => $product,
            'related' => $catalog->related((int) $product['id'], (int) $product['categoria']['id']),
            'favIds' => $this->favIds(),
        ]);
    }

    public function cart(Request $request): Response
    {
        return $this->view('shop/cart', [
            'title' => 'Carrito',
            'cart' => $this->c->user ? $this->c->cart()->get() : null,
        ]);
    }

    public function checkout(Request $request): Response
    {
        $cart = $this->c->cart()->get();
        if ($cart['items'] === []) {
            return $this->redirect('/carrito', 'info', 'Tu carrito está vacío.');
        }
        return $this->view('shop/checkout', [
            'title' => 'Finalizar compra',
            'cart' => $cart,
            'addresses' => $this->c->account()->addresses(),
            'profile' => $this->c->account()->profile(),
            'idempotencyKey' => bin2hex(random_bytes(12)),
            'hideFooter' => true,
        ]);
    }

    public function confirmation(Request $request): Response
    {
        $order = $this->c->orders()->get($request->param('id'));
        return $this->view('shop/confirmation', ['title' => 'Pedido confirmado', 'order' => $order]);
    }

    /** @return list<int> */
    private function favIds(): array
    {
        if ($this->c->user === null) {
            return [];
        }
        try {
            return $this->c->account()->favoriteIds();
        } catch (HttpException) {
            return [];
        }
    }
}
