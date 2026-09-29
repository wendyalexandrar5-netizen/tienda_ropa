<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\CatalogService;

/**
 * Endpoints públicos del catálogo (no requieren autenticación).
 */
final class StoreApiController extends Controller
{
    public function products(Request $request): Response
    {
        return $this->paginated($this->c->catalog()->listProducts(CatalogService::normalizeFilters($request->query)));
    }

    public function product(Request $request): Response
    {
        $catalog = $this->c->catalog();
        $product = $catalog->getProduct($request->param('id'));
        $product['relacionados'] = $catalog->related((int) $product['id'], (int) $product['categoria']['id']);
        return $this->ok($product);
    }

    public function categories(Request $request): Response
    {
        return $this->ok($this->c->catalog()->categories());
    }

    public function category(Request $request): Response
    {
        $catalog = $this->c->catalog();
        $category = $catalog->category($request->param('slug'));
        $filters = CatalogService::normalizeFilters(['categoria' => $category['slug']] + $request->query);
        $category['productos'] = $catalog->listProducts($filters);
        return $this->ok($category);
    }

    public function sizes(Request $request): Response
    {
        return $this->ok($this->c->catalog()->sizes());
    }

    public function colors(Request $request): Response
    {
        return $this->ok($this->c->catalog()->colors());
    }

    public function config(Request $request): Response
    {
        return $this->ok($this->c->catalog()->publicConfig());
    }

    public function health(Request $request): Response
    {
        $db = 'ok';
        try {
            $this->c->db()->fetchValue('select 1');
        } catch (\Throwable) {
            $db = 'error';
        }
        return Response::json(['success' => $db === 'ok', 'data' => ['api' => 'ok', 'database' => $db, 'version' => 'v1']], $db === 'ok' ? 200 : 503);
    }
}
