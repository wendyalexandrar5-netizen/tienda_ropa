<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Validator;

/**
 * Pedidos del cliente. La creación delega en la función transaccional
 * public.crear_pedido(): el cliente solo envía la dirección, el método de
 * pago y notas. Precio, total, propietario y stock los determina la BD.
 */
final class OrderService
{
    public const METODOS_PAGO = ['contra_entrega', 'transferencia'];

    public function __construct(
        private readonly Database $db,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** @param array<string, mixed> $input  @return array<string, mixed> */
    public function create(array $input, string $userId, string $canal = 'web'): array
    {
        // Campos que un cliente malicioso podría intentar enviar se ignoran:
        // precio, total, subtotal, usuario_id, estado, items...
        $v = new Validator($input);
        $direccionId = $v->uuid('direccion_id', true, 'dirección de envío');
        $metodo = $v->enum('metodo_pago', self::METODOS_PAGO, true, 'método de pago');
        $notas = $v->string('notas', false, 0, 500, null, true, 'notas');
        $clave = $v->string('clave_idempotencia', false, 8, 64, '/^[A-Za-z0-9_-]+$/', true, 'clave de idempotencia');
        $v->check();

        $this->limiter->hit('checkout', $userId, 10, 600);

        $pedidoId = (string) $this->db->fetchValue(
            'select public.crear_pedido(:direccion, :metodo, :notas, :clave, :canal)',
            ['direccion' => $direccionId, 'metodo' => $metodo, 'notas' => $notas, 'clave' => $clave, 'canal' => $canal === 'app' ? 'app' : 'web'],
        );
        return $this->get($pedidoId);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int} */
    public function list(int $page = 1, int $perPage = 10, ?string $estado = null): array
    {
        $page = max(1, min($page, 1000));
        $perPage = max(1, min($perPage, 50));
        $params = ['limit' => $perPage, 'offset' => ($page - 1) * $perPage];
        $filter = '';
        if ($estado !== null && $estado !== '') {
            if (!preg_match('/^[a-z_]{3,30}$/', $estado)) {
                throw HttpException::validation(['estado' => 'Estado inválido.']);
            }
            $filter = 'and pe.estado = :estado';
            $params['estado'] = $estado;
        }

        $res = $this->db->fetchJson(
            "with base as (
               select pe.* from public.pedidos pe
                where pe.usuario_id = (select auth.uid()) $filter
             )
             select jsonb_build_object(
               'total', (select count(*) from base),
               'items', coalesce((select jsonb_agg(to_jsonb(x) order by x.created_at desc) from (
                   select b.id, b.numero, b.estado, e.nombre as estado_nombre, e.color as estado_color,
                          b.canal, b.metodo_pago, b.subtotal, b.costo_envio, b.total, b.created_at,
                          (select coalesce(sum(d.cantidad), 0) from public.pedido_detalle d where d.pedido_id = b.id) as unidades,
                          (select coalesce(jsonb_agg(d.imagen_url) filter (where d.imagen_url is not null), '[]')
                             from (select imagen_url from public.pedido_detalle where pedido_id = b.id order by id limit 4) d) as miniaturas
                     from base b join public.estados_pedido e on e.codigo = b.estado
                    order by b.created_at desc
                    limit :limit offset :offset) x), '[]'::jsonb))",
            $params,
        );
        $total = (int) $res['total'];
        return ['items' => $res['items'], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Detalle de un pedido. RLS impide leer pedidos de otros usuarios; para
     * un id ajeno el resultado es idéntico a uno inexistente (404).
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Pedido no encontrado.');
        }
        $order = $this->db->fetchJson(self::detailSql(), ['id' => strtolower($id)]);
        if ($order === null) {
            throw HttpException::notFound('Pedido no encontrado.');
        }
        return $order;
    }

    public function cancel(string $id): array
    {
        if (!Validator::isUuid($id)) {
            throw HttpException::notFound('Pedido no encontrado.');
        }
        $this->db->execute('select public.cancelar_mi_pedido(:id)', ['id' => strtolower($id)]);
        return $this->get($id);
    }

    /** SQL de detalle reutilizado por el panel administrativo. */
    public static function detailSql(): string
    {
        return "select jsonb_build_object(
                  'id', pe.id, 'numero', pe.numero, 'estado', pe.estado,
                  'estado_nombre', e.nombre, 'estado_color', e.color, 'estado_final', e.es_final,
                  'canal', pe.canal, 'metodo_pago', pe.metodo_pago,
                  'subtotal', pe.subtotal, 'costo_envio', pe.costo_envio, 'descuento', pe.descuento, 'total', pe.total,
                  'pago_recibido', pe.pago_recibido, 'cambio', pe.cambio,
                  'direccion_envio', pe.direccion_envio, 'notas', pe.notas, 'cliente_nombre', pe.cliente_nombre,
                  'usuario_id', pe.usuario_id,
                  'cliente', (select jsonb_build_object('id', pr.id, 'email', pr.email, 'nombre', pr.nombre,
                                     'apellido', pr.apellido, 'telefono', pr.telefono)
                                from public.profiles pr where pr.id = pe.usuario_id),
                  'created_at', pe.created_at, 'updated_at', pe.updated_at,
                  'items', (select coalesce(jsonb_agg(jsonb_build_object(
                                'id', d.id, 'variante_id', d.variante_id, 'producto_id', d.producto_id,
                                'producto_nombre', d.producto_nombre, 'talla', d.talla, 'color', d.color,
                                'sku', d.sku, 'imagen_url', d.imagen_url, 'precio_unitario', d.precio_unitario,
                                'cantidad', d.cantidad, 'subtotal', d.subtotal,
                                'slug', (select p.slug from public.productos p where p.id = d.producto_id)) order by d.id), '[]')
                              from public.pedido_detalle d where d.pedido_id = pe.id),
                  'historial', (select coalesce(jsonb_agg(jsonb_build_object(
                                    'estado_anterior', h.estado_anterior, 'estado_nuevo', h.estado_nuevo,
                                    'estado_nombre', eh.nombre, 'comentario', h.comentario, 'fecha', h.created_at)
                                    order by h.created_at, h.id), '[]')
                                  from public.historial_estados_pedido h
                                  join public.estados_pedido eh on eh.codigo = h.estado_nuevo
                                 where h.pedido_id = pe.id),
                  'siguientes_estados', (select coalesce(jsonb_agg(jsonb_build_object('codigo', es.codigo, 'nombre', es.nombre) order by es.orden), '[]')
                                           from public.transiciones_estado_pedido tr
                                           join public.estados_pedido es on es.codigo = tr.hasta
                                          where tr.desde = pe.estado),
                  'puede_cancelar', pe.estado = 'pendiente'
                )
                from public.pedidos pe
                join public.estados_pedido e on e.codigo = pe.estado
               where pe.id = cast(:id as uuid)";
    }
}
