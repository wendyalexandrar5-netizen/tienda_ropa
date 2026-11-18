<?php
/**
 * SIMULADOR LOCAL de la API sandbox de Wompi — SÓLO PARA PRUEBAS AUTOMÁTICAS.
 * No se usa en la tienda: se levanta con  php -S 127.0.0.1:8099 tests/mock_wompi/router.php
 * y se apunta 'api_url' a http://127.0.0.1:8099/v1 en un config.local.php temporal.
 * Imita las respuestas JSON que usa la tienda (merchants, bancos PSE, transacciones).
 * Endpoints extra de control:  POST /mock/transaccion  {reference, amount_in_cents, status, type}
 *                              POST /mock/estado       {id, status}
 */
$db = sys_get_temp_dir() . '/mock_wompi_tx.json';
$tx = is_file($db) ? (json_decode((string) file_get_contents($db), true) ?: []) : [];
$guardar = static function () use (&$tx, $db) { file_put_contents($db, json_encode($tx)); };
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$metodo = $_SERVER['REQUEST_METHOD'];
$cuerpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
$ok = static function ($data, int $codigo = 200) { http_response_code($codigo); echo json_encode(['data' => $data]); exit; };
$err = static function (string $m, int $codigo) { http_response_code($codigo); echo json_encode(['error' => ['type' => 'MOCK', 'reason' => $m]]); exit; };
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$nuevo = static function (array $d) use (&$tx, $guardar) {
    $id = '12345-' . substr(str_replace('.', '', (string) microtime(true)), -10) . '-' . random_int(10000, 99999);
    $tx[$id] = [
        'id' => $id, 'created_at' => gmdate('c'), 'amount_in_cents' => (int) $d['amount_in_cents'], 'currency' => $d['currency'] ?? 'COP',
        'reference' => (string) $d['reference'], 'status' => $d['status'] ?? 'PENDING', 'status_message' => null,
        'payment_method_type' => $d['type'] ?? 'CARD', 'customer_email' => $d['customer_email'] ?? 'cliente@example.com',
        'payment_method' => ['type' => $d['type'] ?? 'CARD', 'extra' => []], 'redirect_url' => $d['redirect_url'] ?? null,
    ];
    $guardar();
    return $tx[$id];
};

if ($metodo === 'GET' && preg_match('#^/v1/merchants/(pub_test_\w+)$#', $ruta)) {
    $ok(['id' => 1, 'name' => 'FIRE CAT (sandbox simulado)',
         'presigned_acceptance' => ['acceptance_token' => 'acc_tok_mock', 'permalink' => 'https://wompi.com/assets/downloadble/reglamento-Usuarios-Colombia.pdf', 'type' => 'END_USER_POLICY'],
         'presigned_personal_data_auth' => ['acceptance_token' => 'pda_tok_mock', 'permalink' => 'https://wompi.com/assets/downloadble/autorizacion-administracion-datos-personales.pdf', 'type' => 'PERSONAL_DATA_AUTH']]);
}
if ($metodo === 'GET' && $ruta === '/v1/pse/financial_institutions') {
    if (strpos($auth, 'Bearer pub_test_') !== 0) { $err('Falta llave pública', 401); }
    $ok([['financial_institution_code' => '0', 'financial_institution_name' => 'A continuación seleccione su banco'],
         ['financial_institution_code' => '1', 'financial_institution_name' => 'Banco que aprueba'],
         ['financial_institution_code' => '2', 'financial_institution_name' => 'Banco que declina']]);
}
if ($metodo === 'POST' && $ruta === '/v1/transactions') {
    if (strpos($auth, 'Bearer pub_test_') !== 0) { $err('Falta llave pública', 401); }
    if (($cuerpo['acceptance_token'] ?? '') !== 'acc_tok_mock') { $err('acceptance_token inválido', 422); }
    $esperada = hash('sha256', $cuerpo['reference'] . $cuerpo['amount_in_cents'] . $cuerpo['currency'] . 'test_integrity_mock');
    if (($cuerpo['signature'] ?? '') !== $esperada) { $err('Firma de integridad inválida', 422); }
    foreach ($tx as $t) { if ($t['reference'] === $cuerpo['reference']) { $err('Referencia ya usada', 422); } }
    $t = $nuevo(['amount_in_cents' => $cuerpo['amount_in_cents'], 'reference' => $cuerpo['reference'], 'currency' => $cuerpo['currency'],
                 'type' => $cuerpo['payment_method']['type'] ?? 'PSE', 'customer_email' => $cuerpo['customer_email'] ?? null, 'redirect_url' => $cuerpo['redirect_url'] ?? null]);
    // Como en Wompi, la URL del banco aparece en una consulta posterior (asíncrona).
    $ok($t, 201);
}
if ($metodo === 'GET' && preg_match('#^/v1/transactions/([\w\-]+)$#', $ruta, $m)) {
    if (!isset($tx[$m[1]])) { $err('No encontrada', 404); }
    $t = $tx[$m[1]];
    if ($t['payment_method_type'] === 'PSE' && empty($t['payment_method']['extra']['async_payment_url'])) {
        $tx[$m[1]]['payment_method']['extra']['async_payment_url'] = 'https://pse-banco.simulado.test/pagar?tx=' . rawurlencode($t['id']);
        $guardar();
    }
    $ok($tx[$m[1]]);
}
if ($metodo === 'GET' && $ruta === '/v1/transactions') {
    if (strpos($auth, 'Bearer prv_test_') !== 0) { $err('Falta llave privada', 401); }
    $ok(array_values(array_filter($tx, static fn($t) => $t['reference'] === ($_GET['reference'] ?? ''))));
}
if ($metodo === 'POST' && $ruta === '/mock/transaccion') {
    $ok($nuevo($cuerpo), 201);
}
if ($metodo === 'POST' && $ruta === '/mock/estado') {
    if (!isset($tx[$cuerpo['id'] ?? ''])) { $err('No encontrada', 404); }
    $tx[$cuerpo['id']]['status'] = $cuerpo['status'];
    $tx[$cuerpo['id']]['status_message'] = $cuerpo['status'] === 'DECLINED' ? 'Transacción rechazada (simulada)' : null;
    $guardar();
    $ok($tx[$cuerpo['id']]);
}
if ($ruta === '/mock/reset') { $tx = []; $guardar(); $ok([]); }
$err('Ruta no simulada: ' . $metodo . ' ' . $ruta, 404);
