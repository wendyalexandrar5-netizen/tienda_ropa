<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Carrito persistente en PostgreSQL (compartido entre web y app móvil).
 *
 * El carrito NO almacena precios: cada lectura calcula precio, subtotal y
 * disponibilidad desde la BD. La validación de stock se aplica en el
 * trigger carrito_detalle_validar y se repite al confirmar el pedido.
 */
final class CartService
{
    public const MAX_POR_ITEM = 20;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $items = $this->db->fetchJson(
            "select coalesce(jsonb_agg(to_jsonb(x) order by x.agregado desc), '[]') from (
               select d.id, d.cantidad, d.created_at as agregado,
                      v.id as variante_id, v.sku, v.stock,
                      t.codigo as talla, co.nombre as color, co.hex,
                      p.id as producto_id, p.nombre as producto_nombre, p.slug,
                      coalesce(v.precio, p.precio) as precio_unitario,
                      coalesce(v.precio, p.precio) * d.cantidad as subtotal,
                      (v.activa and p.estado = 'activo' and v.stock >= d.cantidad) as disponible,
                      (select i.url from public.imagenes_producto i
                        where i.producto_id = p.id and (i.color_id is null or i.color_id = v.color_id)
                        order by (i.color_id = v.color_id) desc nulls last, i.es_principal desc, i.orden, i.id
                        limit 1) as imagen_url
                 from public.carrito_detalle d
                 join public.carritos c on c.id = d.carrito_id and c.usuario_id = (select auth.uid())
                 join public.variantes_producto v on v.id = d.variante_id
                 join public.productos p on p.id = v.producto_id
                 join public.tallas t on t.id = v.talla_id
                 join public.colores co on co.id = v.color_id
             ) x"
        ) ?? [];

        // Si una variante dejó de ser visible (producto desactivado), el JOIN
        // la oculta; se informa como no disponible para que el cliente la retire.
        $subtotal = 0.0;
        $unidades = 0;
        $todoDisponible = true;
        foreach ($items as $item) {
            $subtotal += (float) $item['subtotal'];
            $unidades += (int) $item['cantidad'];
            $todoDisponible = $todoDisponible && $item['disponible'];
        }

        $config = $this->db->fetchJson(
            "select jsonb_build_object(
               'costo_envio', coalesce((select (valor #>> '{}')::numeric from public.configuracion_tienda where clave = 'costo_envio'), 0),
               'envio_gratis_desde', coalesce((select (valor #>> '{}')::numeric from public.configuracion_tienda where clave = 'envio_gratis_desde'), 0))"
        );
        $gratisDesde = (float) $config['envio_gratis_desde'];
        $envio = $items === [] ? 0.0 : (($gratisDesde > 0 && $subtotal >= $gratisDesde) ? 0.0 : (float) $config['costo_envio']);

        return [
            'items' => $items,
            'resumen' => [
                'lineas' => count($items),
                'unidades' => $unidades,
                'subtotal' => round($subtotal, 2),
                'costo_envio' => $envio,
                'total' => round($subtotal + $envio, 2),
                'envio_gratis_desde' => $gratisDesde,
                'falta_para_envio_gratis' => $gratisDesde > 0 ? max(0, round($gratisDesde - $subtotal, 2)) : null,
                'todo_disponible' => $todoDisponible,
            ],
        ];
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue(
            'select coalesce(sum(d.cantidad), 0) from public.carrito_detalle d
               join public.carritos c on c.id = d.carrito_id and c.usuario_id = (select auth.uid())'
        );
    }

    /** @param array<string, mixed> $input */
    public function add(array $input): array
    {
        $v = new Validator($input);
        $varianteId = $v->int('variante_id', true, 1, PHP_INT_MAX, 'variante');
        $cantidad = $v->int('cantidad', false, 1, self::MAX_POR_ITEM, 'cantidad') ?? 1;
        $v->check();

        return $this->db->transaction(function (Database $db) use ($varianteId, $cantidad) {
            $db->execute('insert into public.carritos (usuario_id) values ((select auth.uid())) on conflict (usuario_id) do nothing');

            $actual = (int) $db->fetchValue(
                'select coalesce(max(d.cantidad), 0) from public.carrito_detalle d
                   join public.carritos c on c.id = d.carrito_id and c.usuario_id = (select auth.uid())
                  where d.variante_id = :v',
                ['v' => $varianteId],
            );
            if ($actual + $cantidad > self::MAX_POR_ITEM) {
                throw new HttpException(422, 'CANTIDAD_INVALIDA', 'Puedes llevar máximo ' . self::MAX_POR_ITEM . ' unidades de cada variante.');
            }

            $id = $db->fetchValue(
                'insert into public.carrito_detalle (carrito_id, variante_id, cantidad)
                 values ((select id from public.carritos where usuario_id = (select auth.uid())), :v, :c)
                 on conflict (carrito_id, variante_id)
                 do update set cantidad = public.carrito_detalle.cantidad + excluded.cantidad
                 returning id',
                ['v' => $varianteId, 'c' => $cantidad],
            );
            return ['item_id' => (int) $id] + $this->get();
        });
    }

    /** @param array<string, mixed> $input */
    public function update(string $itemId, array $input): array
    {
        $id = $this->itemId($itemId);
        $v = new Validator($input);
        $cantidad = $v->int('cantidad', true, 1, self::MAX_POR_ITEM, 'cantidad');
        $v->check();

        return $this->db->transaction(function (Database $db) use ($id, $cantidad) {
            // RLS garantiza que solo se actualicen líneas del carrito propio.
            $n = $db->execute('update public.carrito_detalle set cantidad = :c where id = :id', ['c' => $cantidad, 'id' => $id]);
            if ($n === 0) {
                throw HttpException::notFound('El producto no está en tu carrito.');
            }
            return $this->get();
        });
    }

    public function remove(string $itemId): array
    {
        $id = $this->itemId($itemId);
        return $this->db->transaction(function (Database $db) use ($id) {
            $n = $db->execute('delete from public.carrito_detalle where id = :id', ['id' => $id]);
            if ($n === 0) {
                throw HttpException::notFound('El producto no está en tu carrito.');
            }
            return $this->get();
        });
    }

    public function clear(): array
    {
        return $this->db->transaction(function (Database $db) {
            $db->execute('delete from public.carrito_detalle where carrito_id in (select id from public.carritos where usuario_id = (select auth.uid()))');
            return $this->get();
        });
    }

    private function itemId(string $raw): int
    {
        if (!Validator::isPositiveInt($raw)) {
            throw HttpException::notFound('El producto no está en tu carrito.');
        }
        return (int) $raw;
    }
}
