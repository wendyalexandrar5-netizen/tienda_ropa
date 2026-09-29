<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Services\CatalogService;
use App\Services\OrderService;

/**
 * Gestión de pedidos (todos los clientes) y ventas en tienda (POS).
 */
final class AdminOrderService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $q */
    public function list(array $q): array
    {
        $where = ['true'];
        $params = [];
        $estado = $q['estado'] ?? '';
        if (is_string($estado) && preg_match('/^[a-z_]{3,30}$/', $estado)) {
            $where[] = 'pe.estado = :estado';
            $params['estado'] = $estado;
        }
        $canal = $q['canal'] ?? '';
        if (in_array($canal, ['web', 'app', 'pos'], true)) {
            $where[] = 'pe.canal = :canal';
            $params['canal'] = $canal;
        }
        $search = is_string($q['q'] ?? null) ? trim($q['q']) : '';
        if ($search !== '') {
            if (ctype_digit($search)) {
                $where[] = 'pe.numero = :numero';
                $params['numero'] = (int) substr($search, 0, 15);
            } else {
                $where[] = "(pr.email ilike :q escape '\\' or (pr.nombre || ' ' || pr.apellido) ilike :q escape '\\' or pe.cliente_nombre ilike :q escape '\\')";
                $params['q'] = '%' . CatalogService::escapeLike(mb_substr($search, 0, 100)) . '%';
            }
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $key => $op) {
            $date = $q[$key] ?? '';
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date)) {
                $where[] = "(pe.created_at at time zone app_private.zona_horaria())::date $op cast(:$key as date)";
                $params[$key] = $date;
            }
        }
        $page = max(1, (int) ($q['pagina'] ?? $q['page'] ?? 1));
        $perPage = 20;
        $params['limit'] = $perPage;
        $params['offset'] = ($page - 1) * $perPage;

        $res = $this->db->fetchJson(
            "with base as (
               select pe.id, pe.numero, pe.estado, pe.canal, pe.metodo_pago, pe.total, pe.created_at,
                      coalesce(nullif(trim(pr.nombre || ' ' || pr.apellido), ''), pr.email, pe.cliente_nombre, 'Cliente en tienda') as cliente,
                      pr.email
                 from public.pedidos pe
                 left join public.profiles pr on pr.id = pe.usuario_id
                where " . implode(' and ', $where) . "
             )
             select jsonb_build_object(
               'total', (select count(*) from base),
               'items', coalesce((select jsonb_agg(to_jsonb(x)) from (
                   select b.*, e.nombre as estado_nombre, e.color as estado_color,
                          (select coalesce(sum(d.cantidad), 0) from public.pedido_detalle d where d.pedido_id = b.id) as unidades
                     from base b join public.estados_pedido e on e.codigo = b.estado
                    order by b.created_at desc
                    limit :limit offset :offset) x), '[]'::jsonb))",
            $params,
        );
        $total = (int) $res['total'];
        return ['items' => $res['items'], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function get(string $id): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Pedido no encontrado.');
        }
        $order = $this->db->fetchJson(OrderService::detailSql(), ['id' => strtolower($id)]);
        if ($order === null) {
            throw HttpException::notFound('Pedido no encontrado.');
        }
        return $order;
    }

    /** @param array<string, mixed> $input */
    public function changeStatus(string $id, array $input): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Pedido no encontrado.');
        }
        $v = new Validator($input);
        $estado = $v->string('estado', true, 3, 30, '/^[a-z_]+$/', true, 'estado');
        $comentario = $v->string('comentario', false, 0, 300, null, true, 'comentario');
        $v->check();

        $this->db->execute(
            'select public.admin_cambiar_estado_pedido(cast(:id as uuid), :estado, :comentario)',
            ['id' => $id, 'estado' => $estado, 'comentario' => $comentario],
        );
        return $this->get($id);
    }

    /** @param array<string, mixed> $input */
    public function registerPosSale(array $input): array
    {
        $v = new Validator($input);
        $pago = $v->decimal('pago_recibido', true, 0, 99_999_999, 'pago recibido');
        $metodo = $v->enum('metodo_pago', ['efectivo', 'tarjeta', 'transferencia'], false, 'método de pago') ?? 'efectivo';
        $cliente = $v->string('cliente_nombre', false, 0, 120, null, true, 'cliente');
        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > 50) {
            $v->addError('items', 'Agrega al menos un producto a la venta.');
        }
        $v->check();

        $lineas = [];
        foreach ($items as $i => $item) {
            $iv = new Validator(is_array($item) ? $item : []);
            $vid = $iv->int('variante_id', true, 1, PHP_INT_MAX);
            $cant = $iv->int('cantidad', true, 1, 100);
            if ($iv->errors() !== []) {
                throw HttpException::validation(["items.$i" => 'Producto o cantidad inválidos.']);
            }
            // Solo variante y cantidad: el precio lo pone la base de datos.
            $lineas[] = ['variante_id' => $vid, 'cantidad' => $cant];
        }

        $id = (string) $this->db->fetchValue(
            'select public.admin_registrar_venta_pos(cast(:items as jsonb), :pago, :metodo, :cliente)',
            ['items' => $lineas, 'pago' => (string) $pago, 'metodo' => $metodo, 'cliente' => $cliente],
        );
        return $this->get($id);
    }

    /** @return list<array<string, mixed>> */
    public function statuses(): array
    {
        return $this->db->fetchJson("select coalesce(jsonb_agg(to_jsonb(e) order by e.orden), '[]') from public.estados_pedido e");
    }
}
