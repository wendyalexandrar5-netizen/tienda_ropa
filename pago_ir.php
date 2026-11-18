<?php
/**
 * PESTAÑA 1 · Lleva al cliente a la pasarela externa REAL (Wompi Web Checkout o flujo PSE).
 * La URL se toma SIEMPRE de la base de datos (generada por el servidor), nunca de la petición.
 */
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$pago = fila('SELECT pg.*, p.codigo, p.token_checkout, p.estado_pago FROM pagos pg JOIN pedidos p ON p.id = pg.pedido_id
              WHERE pg.referencia = ? AND p.usuario_id = ?', [get_texto('ref', 60), $usuario['id']]);
if (!$pago) {
    error_404('No encontramos ese intento de pago en tu cuenta.');
}
$comprobante = url('pago_comprobante.php', ['token' => $pago['token_checkout']]);
$destino = (string) $pago['url_pago'];
$valida = $destino !== '' && preg_match('#^https://#i', $destino);
$titulo = $valida ? 'Te estamos llevando a ' . ($pago['metodo'] === 'pse' ? 'PSE' : 'Wompi') : 'Intento de pago no disponible';
$mensaje = $valida
    ? 'Pedido ' . $pago['codigo'] . '. Serás redirigido a la pasarela segura (ambiente de pruebas). Puedes volver a la otra pestaña para ver tu comprobante en cualquier momento.'
    : 'Este intento de pago no tiene una pasarela disponible. Revisa el comprobante para reintentar.';
$tipo = $valida ? 'info' : 'warning';
$urlExterna = $valida ? $destino : null;
$enlaces = [['Ver comprobante del pedido', $comprobante]];
header('Cache-Control: no-store');
require ROOT_PATH . '/includes/partials/pago_aviso.php';
