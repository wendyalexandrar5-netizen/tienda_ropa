<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Services\CatalogService;

/**
 * Inventario por variante y kardex. Las modificaciones de stock se hacen
 * exclusivamente con public.admin_ajustar_inventario() (transaccional y
 * auditada), nunca con UPDATE directo.
 */
final class AdminInventoryService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $q */
    public function list(array $q): array
    {
        $where = ['true'];
        $params = [];
        $search = is_string($q['q'] ?? null) ? trim($q['q']) : '';
        if ($search !== '') {
            $where[] = "(p.nombre ilike :q escape '\\' or v.sku ilike :q escape '\\')";
            $params['q'] = '%' . CatalogService::escapeLike(mb_substr($search, 0, 100)) . '%';
        }
        $filtro = $q['filtro'] ?? '';
        if ($filtro === 'bajo') {
            $where[] = 'v.activa and v.stock <= v.stock_minimo';
        } elseif ($filtro === 'agotado') {
            $where[] = 'v.activa and v.stock = 0';
        }
        $page = max(1, (int) ($q['pagina'] ?? $q['page'] ?? 1));
        $perPage = 30;
        $params['limit'] = $perPage;
        $params['offset'] = ($page - 1) * $perPage;

        $res = $this->db->fetchJson(
            "with base as (
               select v.id, v.sku, v.stock, v.stock_minimo, v.activa, coalesce(v.precio, p.precio) as precio,
                      p.id as producto_id, p.nombre as producto_nombre, p.estado as producto_estado,
                      t.codigo as talla, t.orden as talla_orden, co.nombre as color, co.hex
                 from public.variantes_producto v
                 join public.productos p on p.id = v.producto_id
                 join public.tallas t on t.id = v.talla_id
                 join public.colores co on co.id = v.color_id
                where " . implode(' and ', $where) . "
             )
             select jsonb_build_object(
               'total', (select count(*) from base),
               'items', coalesce((select jsonb_agg(to_jsonb(x) - 'talla_orden') from (
                   select * from base order by (stock <= stock_minimo) desc, producto_nombre, color, talla_orden
                   limit :limit offset :offset) x), '[]'::jsonb))",
            $params,
        );
        $total = (int) $res['total'];
        return ['items' => $res['items'], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** @param array<string, mixed> $input */
    public function adjust(array $input): array
    {
        $v = new Validator($input);
        $varianteId = $v->int('variante_id', true, 1, PHP_INT_MAX, 'variante');
        $tipo = $v->enum('tipo', ['entrada', 'salida', 'ajuste'], true, 'tipo de movimiento');
        $cantidad = $v->int('cantidad', true, -100000, 100000, 'cantidad');
        $motivo = $v->string('motivo', true, 3, 300, null, true, 'motivo');
        $v->check();
        if ($cantidad === 0) {
            throw HttpException::validation(['cantidad' => 'La cantidad no puede ser cero.']);
        }
        // Para mayor comodidad el formulario envía cantidades positivas en "salida".
        if ($tipo === 'salida' && $cantidad > 0) {
            $cantidad = -$cantidad;
        }

        $stock = (int) $this->db->fetchValue(
            'select public.admin_ajustar_inventario(:id, :cantidad, :tipo, :motivo)',
            ['id' => $varianteId, 'cantidad' => $cantidad, 'tipo' => $tipo, 'motivo' => $motivo],
        );
        return ['variante_id' => $varianteId, 'stock' => $stock];
    }

    /** @param array<string, mixed> $q */
    public function movements(array $q): array
    {
        $params = ['limit' => 50];
        $filter = '';
        if (isset($q['variante_id']) && is_string($q['variante_id']) && Validator::isPositiveInt($q['variante_id'])) {
            $filter = 'where m.variante_id = :vid';
            $params['vid'] = (int) $q['variante_id'];
        }
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.created_at desc, x.id desc), '[]') from (
               select m.id, m.tipo, m.cantidad, m.stock_anterior, m.stock_resultante, m.motivo, m.created_at,
                      m.pedido_id, (select numero from public.pedidos pe where pe.id = m.pedido_id) as pedido_numero,
                      v.sku, p.nombre as producto_nombre, t.codigo as talla, co.nombre as color,
                      nullif(trim(coalesce(pr.nombre, '') || ' ' || coalesce(pr.apellido, '')), '') as usuario
                 from public.movimientos_inventario m
                 join public.variantes_producto v on v.id = m.variante_id
                 join public.productos p on p.id = v.producto_id
                 join public.tallas t on t.id = v.talla_id
                 join public.colores co on co.id = v.color_id
                 left join public.profiles pr on pr.id = m.usuario_id
                 $filter
                order by m.created_at desc, m.id desc
                limit :limit) x",
            $params,
        );
    }

    /** Búsqueda rápida de variantes con stock para la venta en tienda (POS). */
    public function searchForPos(string $term): array
    {
        $term = trim(mb_substr($term, 0, 60));
        if ($term === '') {
            return [];
        }
        return $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x)), '[]') from (
               select v.id, v.sku, v.stock, coalesce(v.precio, p.precio) as precio, p.nombre as producto_nombre,
                      t.codigo as talla, co.nombre as color, co.hex,
                      (select i.url from public.imagenes_producto i where i.producto_id = p.id order by i.es_principal desc, i.orden limit 1) as imagen_url
                 from public.variantes_producto v
                 join public.productos p on p.id = v.producto_id
                 join public.tallas t on t.id = v.talla_id
                 join public.colores co on co.id = v.color_id
                where v.activa and p.estado = 'activo'
                  and (p.nombre ilike :q escape '\\' or v.sku ilike :q escape '\\')
                order by v.stock > 0 desc, p.nombre, co.nombre, t.orden
                limit 25) x",
            ['q' => '%' . CatalogService::escapeLike($term) . '%'],
        );
    }
}
