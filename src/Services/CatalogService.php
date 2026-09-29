<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Catálogo público: productos, categorías, tallas, colores y configuración.
 * Las consultas corren con el rol del usuario (anon/authenticated) y RLS
 * garantiza que solo se vean productos y categorías activos.
 */
final class CatalogService
{
    public const SORTS = [
        'destacados' => 'destacado desc, created_at desc, id desc',
        'recientes' => 'created_at desc, id desc',
        'precio_asc' => 'precio_desde asc, id',
        'precio_desc' => 'precio_desde desc, id',
        'nombre' => 'nombre asc, id',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $f filtros ya normalizados (ver normalizeFilters)
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listProducts(array $f): array
    {
        $where = ["c.estado = 'activo'"];
        $params = [];

        if ($f['q'] !== '') {
            $where[] = "c.id in (select p.id from public.productos p
                                  where p.busqueda @@ websearch_to_tsquery('spanish', :q)
                                     or p.nombre ilike :q_like escape '\\'
                                     or p.descripcion ilike :q_like escape '\\')";
            $params['q'] = $f['q'];
            $params['q_like'] = '%' . self::escapeLike($f['q']) . '%';
        }
        if ($f['categoria'] !== '') {
            $where[] = 'c.categoria_slug = :categoria';
            $params['categoria'] = $f['categoria'];
        }
        if ($f['tallas'] !== []) {
            $where[] = "exists (select 1 from public.variantes_producto v join public.tallas t on t.id = v.talla_id
                                 where v.producto_id = c.id and v.activa and v.stock > 0
                                   and t.codigo in (select jsonb_array_elements_text(cast(:tallas as jsonb))))";
            $params['tallas'] = $f['tallas'];
        }
        if ($f['colores'] !== []) {
            $where[] = "exists (select 1 from public.variantes_producto v
                                 where v.producto_id = c.id and v.activa and v.stock > 0
                                   and v.color_id in (select (jsonb_array_elements_text(cast(:colores as jsonb)))::bigint))";
            $params['colores'] = $f['colores'];
        }
        if ($f['precio_min'] !== null) {
            $where[] = 'c.precio_desde >= :precio_min';
            $params['precio_min'] = (string) $f['precio_min'];
        }
        if ($f['precio_max'] !== null) {
            $where[] = 'c.precio_desde <= :precio_max';
            $params['precio_max'] = (string) $f['precio_max'];
        }
        if ($f['disponible']) {
            $where[] = 'c.stock_total > 0';
        }
        if ($f['destacado']) {
            $where[] = 'c.destacado';
        }
        if ($f['ofertas']) {
            $where[] = 'c.precio_anterior is not null';
        }

        $order = self::SORTS[$f['orden']] ?? self::SORTS['destacados'];
        $params['limit'] = $f['per_page'];
        $params['offset'] = ($f['page'] - 1) * $f['per_page'];

        $sql = '
            with base as (
              select c.* from public.v_catalogo_productos c
               where ' . implode(' and ', $where) . '
            )
            select jsonb_build_object(
              \'total\', (select count(*) from base),
              \'items\', coalesce((
                select jsonb_agg(to_jsonb(x)) from (
                  select b.id, b.slug, b.nombre, b.precio, b.precio_anterior, b.precio_desde,
                         b.destacado, b.categoria_id, b.categoria_nombre, b.categoria_slug,
                         b.imagen_url, b.imagen_secundaria_url, b.stock_total,
                         (b.stock_total > 0) as disponible,
                         (select coalesce(jsonb_agg(distinct jsonb_build_object(\'id\', co.id, \'nombre\', co.nombre, \'hex\', co.hex)), \'[]\')
                            from public.variantes_producto v join public.colores co on co.id = v.color_id
                           where v.producto_id = b.id and v.activa) as colores
                    from base b
                   order by ' . $order . '
                   limit :limit offset :offset
                ) x
              ), \'[]\'::jsonb)
            )';

        $result = $this->db->fetchJson($sql, $params);
        $total = (int) $result['total'];

        return [
            'items' => $result['items'],
            'total' => $total,
            'page' => $f['page'],
            'per_page' => $f['per_page'],
            'pages' => max(1, (int) ceil($total / $f['per_page'])),
        ];
    }

    /**
     * Normaliza y valida filtros recibidos por query string (web o API).
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public static function normalizeFilters(array $q, int $defaultPerPage = 12): array
    {
        $str = static fn (string $k): string => is_string($q[$k] ?? null) ? trim((string) $q[$k]) : '';
        $list = static function (string $k) use ($q): array {
            $v = $q[$k] ?? [];
            if (is_string($v)) {
                $v = $v === '' ? [] : explode(',', $v);
            }
            return is_array($v) ? array_values(array_slice(array_filter(array_map(static fn ($i) => is_scalar($i) ? trim((string) $i) : '', $v), static fn ($i) => $i !== ''), 0, 20)) : [];
        };
        $money = static function (string $k) use ($q): ?float {
            $v = $q[$k] ?? null;
            return (is_scalar($v) && is_numeric($v) && (float) $v >= 0 && (float) $v < 1e10) ? (float) $v : null;
        };

        $orden = $str('orden');
        $tallas = array_values(array_filter($list('talla') ?: $list('tallas'), static fn ($t) => (bool) preg_match('/^[A-Za-z0-9]{1,10}$/', $t)));
        $colores = array_values(array_map('intval', array_filter($list('color') ?: $list('colores'), static fn ($c) => ctype_digit($c))));
        $page = filter_var($q['pagina'] ?? $q['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) ?: 1;
        $perPage = filter_var($q['por_pagina'] ?? $q['per_page'] ?? $defaultPerPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 48]]) ?: $defaultPerPage;
        $categoria = $str('categoria');

        return [
            'q' => mb_substr($str('q'), 0, 100),
            'categoria' => preg_match('/^[a-z0-9-]{1,80}$/', $categoria) ? $categoria : '',
            'tallas' => $tallas,
            'colores' => $colores,
            'precio_min' => $money('precio_min'),
            'precio_max' => $money('precio_max'),
            'disponible' => in_array($q['disponible'] ?? null, ['1', 'true', 1, true], true),
            'destacado' => in_array($q['destacado'] ?? null, ['1', 'true', 1, true], true),
            'ofertas' => in_array($q['ofertas'] ?? null, ['1', 'true', 1, true], true),
            'orden' => isset(self::SORTS[$orden]) ? $orden : 'destacados',
            'page' => (int) $page,
            'per_page' => (int) $perPage,
        ];
    }

    /** @return array<string, mixed> */
    public function getProduct(string $key): array
    {
        if (!preg_match('/^([a-z0-9-]{1,140})$/', $key)) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        $product = $this->db->fetchJson(
            "select jsonb_build_object(
               'id', p.id, 'slug', p.slug, 'nombre', p.nombre, 'descripcion', p.descripcion,
               'precio', p.precio, 'precio_anterior', p.precio_anterior, 'destacado', p.destacado,
               'categoria', jsonb_build_object('id', c.id, 'nombre', c.nombre, 'slug', c.slug),
               'imagenes', (select coalesce(jsonb_agg(jsonb_build_object('id', i.id, 'url', i.url, 'alt', i.alt,
                                   'color_id', i.color_id, 'es_principal', i.es_principal)
                                   order by i.es_principal desc, i.orden, i.id), '[]')
                              from public.imagenes_producto i where i.producto_id = p.id),
               'variantes', (select coalesce(jsonb_agg(jsonb_build_object(
                                   'id', v.id, 'sku', v.sku, 'talla_id', t.id, 'talla', t.codigo,
                                   'color_id', co.id, 'color', co.nombre, 'hex', co.hex,
                                   'precio', coalesce(v.precio, p.precio), 'stock', v.stock,
                                   'disponible', v.stock > 0)
                                   order by co.nombre, t.orden), '[]')
                               from public.variantes_producto v
                               join public.tallas t on t.id = v.talla_id
                               join public.colores co on co.id = v.color_id
                              where v.producto_id = p.id and v.activa),
               'tallas', (select coalesce(jsonb_agg(x order by x->>'orden_n'), '[]') from (
                            select distinct jsonb_build_object('id', t.id, 'codigo', t.codigo, 'nombre', t.nombre,
                                   'orden_n', lpad(t.orden::text, 4, '0')) as x
                              from public.variantes_producto v join public.tallas t on t.id = v.talla_id
                             where v.producto_id = p.id and v.activa) s),
               'colores', (select coalesce(jsonb_agg(x order by x->>'nombre'), '[]') from (
                            select distinct jsonb_build_object('id', co.id, 'nombre', co.nombre, 'hex', co.hex) as x
                              from public.variantes_producto v join public.colores co on co.id = v.color_id
                             where v.producto_id = p.id and v.activa) s),
               'stock_total', (select coalesce(sum(v.stock), 0) from public.variantes_producto v
                                where v.producto_id = p.id and v.activa)
             )
             from public.productos p
             join public.categorias c on c.id = p.categoria_id
            where p.estado = 'activo' and (p.slug = :key or p.id::text = :key)",
            ['key' => $key],
        );
        if ($product === null) {
            throw HttpException::notFound('Producto no encontrado.');
        }
        foreach ($product['tallas'] as &$t) {
            unset($t['orden_n']);
        }
        return $product;
    }

    /** @return list<array<string, mixed>> */
    public function related(int $productId, int $categoryId, int $limit = 4): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x)), '[]') from (
               select id, slug, nombre, precio, precio_anterior, precio_desde, imagen_url, imagen_secundaria_url,
                      categoria_nombre, categoria_slug, stock_total, stock_total > 0 as disponible, '[]'::jsonb as colores
                 from public.v_catalogo_productos
                where estado = 'activo' and categoria_id = :cat and id <> :id
                order by destacado desc, stock_total > 0 desc, created_at desc
                limit :limit) x",
            ['cat' => $categoryId, 'id' => $productId, 'limit' => $limit],
        );
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.orden, x.nombre), '[]') from (
               select c.id, c.nombre, c.slug, c.descripcion, c.imagen_url, c.orden, c.parent_id,
                      (select count(*) from public.productos p where p.categoria_id = c.id and p.estado = 'activo') as productos
                 from public.categorias c
                where c.activa) x"
        );
    }

    /** @return array<string, mixed> */
    public function category(string $slug): array
    {
        foreach ($this->categories() as $cat) {
            if ($cat['slug'] === $slug) {
                return $cat;
            }
        }
        throw HttpException::notFound('Categoría no encontrada.');
    }

    /** @return list<array<string, mixed>> */
    public function sizes(): array
    {
        return $this->db->fetchJson("select coalesce(jsonb_agg(to_jsonb(t) order by t.orden, t.codigo), '[]') from public.tallas t");
    }

    /** @return list<array<string, mixed>> */
    public function colors(): array
    {
        return $this->db->fetchJson("select coalesce(jsonb_agg(to_jsonb(c) order by c.nombre), '[]') from public.colores c");
    }

    /** @return array<string, mixed> */
    public function publicConfig(): array
    {
        static $cache = null;
        return $cache ??= $this->db->fetchJson(
            "select coalesce(jsonb_object_agg(clave, valor), '{}') from public.configuracion_tienda where publica"
        );
    }

    public static function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
