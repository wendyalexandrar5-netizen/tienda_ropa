<?php
/**
 * PESTAÑA 2 · Estado del pedido y del pago (JSON) para el comprobante.
 * Si el pago en línea sigue pendiente, el SERVIDOR consulta a Wompi (con límite de frecuencia).
 * Nunca devuelve datos sensibles (documento, llaves) y sólo responde al dueño del pedido.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$usuario = usuario_actual();
if (!$usuario) {
    $responder(['encontrado' => false, 'error' => 'Tu sesión expiró. Inicia sesión para ver el comprobante.'], 401);
}
$uid = (int) $usuario['id'];
session_write_close();   // no bloquear la otra pestaña mientras se consulta al proveedor

$token = get_texto('token', 32);
$pedido = preg_match('/^[a-f0-9]{32}$/', $token)
    ? fila('SELECT * FROM pedidos WHERE token_checkout = ? AND usuario_id = ?', [$token, $uid])
    : fila('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?', [get_int('pedido'), $uid]);
if (!$pedido) {
    $fallo = $token !== '' ? cache_leer('fallo_compra_' . preg_replace('/[^a-f0-9]/', '', $token), 3600) : null;
    $responder(['encontrado' => false, 'error' => ($fallo && (int) $fallo['usuario'] === $uid) ? $fallo['mensaje'] : null]);
}

$pago = es_metodo_en_linea($pedido['metodo_pago']) ? pago_actual((int) $pedido['id']) : null;
if ($pago && $pedido['estado_pago'] === 'pendiente') {
    $pago = verificar_pago($pago);
    $pedido = fila('SELECT * FROM pedidos WHERE id = ?', [$pedido['id']]);
}
$info = info_estado_pago($pedido);
$items = filas('SELECT nombre_producto, talla, color, cantidad, precio_unitario, subtotal FROM pedido_detalle WHERE pedido_id = ? ORDER BY id', [$pedido['id']]);
$aprobado = $pago ? fila("SELECT * FROM pagos WHERE pedido_id = ? AND estado = 'APPROVED' ORDER BY id DESC LIMIT 1", [$pedido['id']]) : null;
$pagoMostrar = $aprobado ?: $pago;

$responder([
    'encontrado' => true,
    'pedido' => [
        'id'        => (int) $pedido['id'],
        'codigo'    => $pedido['codigo'],
        'fecha'     => fecha($pedido['fecha_pedido']),
        'estado'    => estado_pedido_texto($pedido['estado']),
        'metodo'    => METODOS_PAGO[$pedido['metodo_pago']] ?? $pedido['metodo_pago'],
        'subtotal'  => precio($pedido['subtotal']),
        'envio'     => (float) $pedido['costo_envio'] > 0 ? precio($pedido['costo_envio']) : 'Gratis',
        'total'     => precio($pedido['total']),
        'cliente'   => [
            'nombre'    => $pedido['nombre_destinatario'],
            'email'     => $usuario['email'],
            'telefono'  => $pedido['telefono_entrega'],
            'direccion' => $pedido['direccion_entrega'] . ', ' . $pedido['ciudad_entrega'],
        ],
        'items' => array_map(static fn($i) => [
            'nombre' => $i['nombre_producto'], 'variante' => $i['talla'] . ' / ' . $i['color'], 'cantidad' => (int) $i['cantidad'],
            'precio' => precio($i['precio_unitario']), 'subtotal' => precio($i['subtotal']),
        ], $items),
        'url_detalle' => url('pedido.php', ['id' => $pedido['id']]),
        'token'       => $pedido['token_checkout'],
    ],
    'pago' => $info + [
        'en_linea'        => es_metodo_en_linea($pedido['metodo_pago']),
        'proveedor'       => $pagoMostrar ? (pagos_modo_demo()
            ? ($pagoMostrar['metodo'] === 'pse' ? 'PSE' : 'Wompi')
            : ($pagoMostrar['metodo'] === 'pse' ? 'PSE vía Wompi' : 'Wompi Web Checkout')) : null,
        'ambiente'        => $pagoMostrar && !pagos_modo_demo() ? 'Sandbox de Wompi (pruebas, sin dinero real)' : null,
        'referencia'      => $pagoMostrar['referencia'] ?? null,
        'transaccion_id'  => $pagoMostrar['transaccion_id'] ?? null,
        'medio'           => $pagoMostrar['medio_wompi'] ?? null,
        'banco'           => $pagoMostrar['banco_pse'] ?? null,
        'detalle'         => $pagoMostrar['mensaje'] ?? null,
        'verificado_en'   => !empty($pagoMostrar['verificado_en']) && !pagos_modo_demo() ? fecha($pagoMostrar['verificado_en']) . ' (' . ($pagoMostrar['fuente_verificacion'] === 'webhook' ? 'notificación firmada de Wompi' : 'consulta del servidor a Wompi') . ')' : null,
        'intentos'        => $pago ? (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ?', [$pedido['id']]) : 0,
    ],
    'acciones' => [
        'reintentar'  => $pago !== null && in_array($info['clave'], ['rechazado', 'anulado', 'error'], true) && $pedido['estado'] !== 'cancelado',
        'ir_pasarela' => $pago && $info['clave'] === 'pendiente' && $pago['url_pago'] ? url('pago_ir.php', ['ref' => $pago['referencia']]) : null,
    ],
    'consultado_en' => date('H:i:s'),
]);
