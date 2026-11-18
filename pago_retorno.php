<?php
/**
 * URL de retorno configurada en Wompi (llega con ?id=TRANSACCION&env=test).
 * NO se confía en esos parámetros: el id sólo se usa para CONSULTAR la transacción en la API
 * de Wompi desde el servidor; el estado se acepta sólo si la referencia, el monto y la moneda
 * de la respuesta coinciden con el intento de pago guardado.
 */
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$pago = fila('SELECT pg.*, p.token_checkout FROM pagos pg JOIN pedidos p ON p.id = pg.pedido_id WHERE pg.referencia = ? AND p.usuario_id = ?',
    [get_texto('ref', 60), $usuario['id']]);
if (!$pago) {
    error_404('No encontramos ese intento de pago en tu cuenta.');
}
$destino = $pago['token_checkout'] ? ['token' => $pago['token_checkout']] : ['pedido' => (int) $pago['pedido_id']];
verificar_pago($pago, get_texto('id', 64) ?: null, true);
session_write_close();
redirigir('pago_comprobante.php', $destino + ['retorno' => 1]);
