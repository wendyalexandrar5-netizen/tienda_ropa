<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Pedidos: creación transaccional desde el carrito y flujo de estados.
 */

const ESTADOS_PEDIDO = [
    'pendiente'  => ['Pendiente',  'warning',   'bi-hourglass-split'],
    'confirmado' => ['Confirmado', 'info',      'bi-check2-circle'],
    'preparado'  => ['Preparado',  'primary',   'bi-box-seam'],
    'enviado'    => ['Enviado',    'dark',      'bi-truck'],
    'entregado'  => ['Entregado',  'success',   'bi-bag-check'],
    'cancelado'  => ['Cancelado',  'danger',    'bi-x-circle'],
];

/** Flujo permitido: cada estado sólo puede avanzar a los estados listados. */
const TRANSICIONES_PEDIDO = [
    'pendiente'  => ['confirmado', 'cancelado'],
    'confirmado' => ['preparado', 'cancelado'],
    'preparado'  => ['enviado', 'cancelado'],
    'enviado'    => ['entregado'],
    'entregado'  => [],
    'cancelado'  => [],
];

const METODOS_PAGO = [
    'contra_entrega' => 'Pago contra entrega',
    'pedido_prueba'  => 'Pedido de prueba (sin pago)',
];

function estado_pedido_badge(string $estado): string
{
    [$texto, $color, $icono] = ESTADOS_PEDIDO[$estado] ?? [ucfirst($estado), 'secondary', 'bi-circle'];
    $textoClase = in_array($color, ['warning', 'info'], true) ? ' text-dark' : '';
    return '<span class="badge rounded-pill text-bg-' . $color . $textoClase . '"><i class="bi ' . $icono . ' me-1"></i>' . e($texto) . '</span>';
}

function estado_pedido_texto(string $estado): string
{
    return ESTADOS_PEDIDO[$estado][0] ?? ucfirst($estado);
}

/**
 * Crea el pedido a partir del carrito en UNA transacción:
 * valida stock con bloqueo de filas → inserta pedido y detalle con precio
 * histórico → descuenta inventario → registra historial → vacía el carrito.
 * Si algo falla se hace ROLLBACK y nada queda a medias.
 *
 * @return array{id:int, codigo:string}
 * @throws DomainException con mensaje apto para el usuario.
 */
function crear_pedido(int $usuarioId, array $entrega): array
{
    return transaccion(static function (PDO $pdo) use ($usuarioId, $entrega) {
        // FOR UPDATE bloquea las variantes: dos compras simultáneas no pueden
        // vender la misma última unidad.
        $items = filas("SELECT cd.id, cd.cantidad, cd.variante_id, cd.producto_id,
                               p.nombre, p.precio, p.estado AS producto_estado, c.estado AS categoria_estado,
                               v.stock, v.estado AS variante_estado, t.nombre AS talla, co.nombre AS color
                        FROM carritos ca
                        JOIN carrito_detalle cd   ON cd.carrito_id = ca.id
                        JOIN productos p          ON p.id = cd.producto_id
                        JOIN categorias c         ON c.id = p.categoria_id
                        JOIN variantes_producto v ON v.id = cd.variante_id
                        JOIN tallas t             ON t.id = v.talla_id
                        JOIN colores co           ON co.id = v.color_id
                        WHERE ca.usuario_id = ?
                        ORDER BY cd.id
                        FOR UPDATE", [$usuarioId]);

        if (!$items) {
            throw new DomainException('Tu carrito está vacío.');
        }

        $problemas = [];
        $subtotal  = 0.0;
        foreach ($items as $i) {
            $nombre = "{$i['nombre']} ({$i['talla']} / {$i['color']})";
            if ($i['producto_estado'] !== 'activo' || $i['variante_estado'] !== 'activo' || $i['categoria_estado'] !== 'activa') {
                $problemas[] = "$nombre ya no está disponible.";
            } elseif ((int) $i['cantidad'] > (int) $i['stock']) {
                $problemas[] = "$nombre: sólo quedan {$i['stock']} unidad(es).";
            }
            $subtotal += (float) $i['precio'] * (int) $i['cantidad'];
        }
        if ($problemas) {
            throw new DomainException('No pudimos confirmar el pedido: ' . implode(' ', $problemas) . ' Revisa tu carrito.');
        }

        $envio = calcular_envio($subtotal);
        $total = $subtotal + $envio;
        $pagoCon = $entrega['pago_con'] ?? null;
        if ($pagoCon !== null && $pagoCon < $total) {
            throw new DomainException('El valor con el que pagarás no cubre el total del pedido.');
        }

        consulta('INSERT INTO pedidos (usuario_id, subtotal, costo_envio, total, estado, metodo_pago, pago_con,
                                       nombre_destinatario, telefono_entrega, direccion_entrega, ciudad_entrega, notas)
                  VALUES (?, ?, ?, ?, \'pendiente\', ?, ?, ?, ?, ?, ?, ?)', [
            $usuarioId, $subtotal, $envio, $total, $entrega['metodo_pago'], $pagoCon,
            $entrega['nombre'], $entrega['telefono'], $entrega['direccion'], $entrega['ciudad'], $entrega['notas'] ?: null,
        ]);
        $pedidoId = (int) $pdo->lastInsertId();
        $codigo   = sprintf('FC-%06d', $pedidoId);
        consulta('UPDATE pedidos SET codigo = ? WHERE id = ?', [$codigo, $pedidoId]);

        // Costo de fabricación vigente (ficha técnica): se congela junto al precio para calcular la utilidad.
        $costos = costos_fabricacion(array_column($items, 'producto_id'));
        $insDetalle = $pdo->prepare('INSERT INTO pedido_detalle
            (pedido_id, producto_id, variante_id, nombre_producto, talla, color, cantidad, precio_unitario, costo_unitario, subtotal)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $i) {
            $insDetalle->execute([
                $pedidoId, $i['producto_id'], $i['variante_id'], $i['nombre'], $i['talla'], $i['color'],
                $i['cantidad'], $i['precio'], $costos[(int) $i['producto_id']] ?? null, (float) $i['precio'] * (int) $i['cantidad'],
            ]);
            mover_stock((int) $i['variante_id'], 'venta', -(int) $i['cantidad'], "Venta pedido $codigo", $usuarioId, $pedidoId);
        }

        consulta('INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario)
                  VALUES (?, NULL, \'pendiente\', ?, \'Pedido creado por el cliente\')', [$pedidoId, $usuarioId]);

        consulta('DELETE cd FROM carrito_detalle cd JOIN carritos ca ON ca.id = cd.carrito_id WHERE ca.usuario_id = ?', [$usuarioId]);

        return ['id' => $pedidoId, 'codigo' => $codigo];
    });
}

/**
 * Cambia el estado respetando el flujo permitido. Al cancelar, el stock
 * vendido se devuelve al inventario dentro de la misma transacción.
 */
function cambiar_estado_pedido(int $pedidoId, string $nuevo, int $usuarioId, ?string $comentario = null): void
{
    transaccion(static function () use ($pedidoId, $nuevo, $usuarioId, $comentario) {
        $pedido = fila('SELECT id, codigo, estado FROM pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
        if (!$pedido) {
            throw new DomainException('El pedido no existe.');
        }
        $actual = $pedido['estado'];
        if (!in_array($nuevo, TRANSICIONES_PEDIDO[$actual] ?? [], true)) {
            throw new DomainException('No es posible pasar un pedido de "' . estado_pedido_texto($actual) . '" a "' . estado_pedido_texto($nuevo) . '".');
        }
        if ($nuevo === 'cancelado') {
            foreach (filas('SELECT variante_id, cantidad FROM pedido_detalle WHERE pedido_id = ?', [$pedidoId]) as $d) {
                mover_stock((int) $d['variante_id'], 'devolucion', (int) $d['cantidad'], "Cancelación pedido {$pedido['codigo']}", $usuarioId, $pedidoId);
            }
        }
        consulta('UPDATE pedidos SET estado = ? WHERE id = ?', [$nuevo, $pedidoId]);
        consulta('INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario) VALUES (?, ?, ?, ?, ?)',
            [$pedidoId, $actual, $nuevo, $usuarioId, $comentario !== '' ? $comentario : null]);
    });
}

function pedido_items(int $pedidoId): array
{
    return filas('SELECT d.*, p.imagen FROM pedido_detalle d JOIN productos p ON p.id = d.producto_id WHERE d.pedido_id = ? ORDER BY d.id', [$pedidoId]);
}

function pedido_historial(int $pedidoId): array
{
    return filas("SELECT h.*, CONCAT(u.nombre, ' ', u.apellido) AS usuario, u.rol
                  FROM pedido_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
                  WHERE h.pedido_id = ? ORDER BY h.fecha, h.id", [$pedidoId]);
}
