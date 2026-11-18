<?php
/**
 * Webhook de eventos de Wompi (configurar en el panel de Wompi → "URL de eventos" en modo Pruebas):
 *   https://TU-URL-PUBLICA/tienda_ropa/webhooks/wompi.php
 *
 * Autenticación: firma del evento = SHA256(valores de signature.properties + timestamp + secreto de eventos).
 * No usa sesión ni token CSRF (es una llamada servidor a servidor). Todo evento queda en `pagos_eventos`.
 */
define('SIN_SESION', true);
require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = static function (int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $responder(405, ['ok' => false, 'error' => 'Método no permitido']);
}
$crudo = (string) file_get_contents('php://input', false, null, 0, 65536);
$evento = json_decode($crudo, true);
$tx = is_array($evento) ? ($evento['data']['transaction'] ?? null) : null;
$valida = is_array($evento) && is_array($tx) && wompi_firma_evento_valida($evento);
$pagoId = is_array($tx) ? valor('SELECT id FROM pagos WHERE referencia = ?', [(string) ($tx['reference'] ?? '')]) : null;

$resultado = ['ok' => false, 'motivo' => 'Firma inválida o evento mal formado.'];
if ($valida && ($evento['event'] ?? '') === 'transaction.updated') {
    // Defensa adicional: si la API responde, se usa su versión de la transacción (misma referencia).
    try {
        $api = wompi_api('GET', '/transactions/' . rawurlencode((string) $tx['id']));
        if (($api['reference'] ?? null) === ($tx['reference'] ?? null)) {
            $tx = $api;
        }
    } catch (RuntimeException $e) {
        // Sin acceso a la API se aplica el evento, cuya firma ya fue verificada.
    }
    $resultado = aplicar_transaccion_wompi($tx, 'webhook');
} elseif ($valida) {
    $resultado = ['ok' => true, 'motivo' => 'Evento ignorado: ' . ($evento['event'] ?? '?')];
}

consulta('INSERT INTO pagos_eventos (pago_id, tipo_evento, transaccion_id, estado, firma_valida, resultado, payload, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
    $pagoId, is_array($evento) ? mb_substr((string) ($evento['event'] ?? ''), 0, 60) : null,
    is_array($tx) ? mb_substr((string) ($tx['id'] ?? ''), 0, 64) : null, is_array($tx) ? mb_substr((string) ($tx['status'] ?? ''), 0, 20) : null,
    $valida ? 1 : 0, mb_substr($resultado['motivo'], 0, 255), mb_substr($crudo, 0, 60000), ip_cliente(),
]);
if (!$valida) {
    registrar_log('Webhook de Wompi con firma inválida desde ' . ip_cliente(), 'seguridad.log');
    $responder(401, ['ok' => false]);
}
$responder(200, ['ok' => true, 'resultado' => $resultado['motivo']]);
