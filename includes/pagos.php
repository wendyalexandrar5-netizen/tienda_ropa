<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Pagos en línea con Wompi (AMBIENTE DE PRUEBAS / SANDBOX).
 *
 *  - Wompi: Web Checkout real (https://checkout.wompi.co/p/) con firma de integridad.
 *  - PSE: transacción PSE creada en la API de Wompi; el cliente es enviado a la URL
 *    del flujo PSE/banco que devuelve Wompi (async_payment_url). Nunca se inventan URLs.
 *
 * Regla de oro: un pedido sólo queda PAGADO cuando el BACKEND verifica la aprobación del
 * proveedor, por una de estas dos vías:
 *   1. Webhook (evento transaction.updated) con firma SHA-256 válida.
 *   2. Consulta del servidor a la API de Wompi (GET /transactions/{id} o búsqueda por referencia).
 * En ambos casos se exige que referencia, monto y moneda coincidan con el intento de pago guardado.
 * Los parámetros de la URL de retorno (?id=...) sólo se usan como pista para consultar la API.
 */

const METODOS_EN_LINEA = ['wompi', 'pse'];

/** Estado de pago del pedido → [etiqueta para el cliente, color Bootstrap, icono] */
const ESTADOS_PAGO = [
    'aprobado'  => ['PAGADO', 'success', 'bi-patch-check-fill'],
    'pendiente' => ['PAGO PENDIENTE', 'warning', 'bi-hourglass-split'],
    'rechazado' => ['RECHAZADO', 'danger', 'bi-x-octagon-fill'],
    'anulado'   => ['ANULADO', 'danger', 'bi-slash-circle'],
    'error'     => ['ERROR EN EL PAGO', 'danger', 'bi-exclamation-octagon-fill'],
];

/** Estado de la transacción en Wompi → estado de pago del pedido */
const WOMPI_A_ESTADO_PAGO = [
    'CREADO' => 'pendiente', 'PENDING' => 'pendiente', 'APPROVED' => 'aprobado',
    'DECLINED' => 'rechazado', 'VOIDED' => 'anulado', 'ERROR' => 'error',
];

const PSE_TIPOS_PERSONA = [0 => 'Persona natural', 1 => 'Persona jurídica'];
const PSE_TIPOS_DOCUMENTO = ['CC' => 'Cédula de ciudadanía', 'CE' => 'Cédula de extranjería', 'NIT' => 'NIT',
                             'PP' => 'Pasaporte', 'TI' => 'Tarjeta de identidad'];

/**
 * Crea (una sola vez) las columnas y tablas de pagos si la base de datos aún no las tiene:
 * así un hosting donde no se ejecutó migraciones/002_pagos_en_linea.sql sigue funcionando.
 * El resultado se recuerda en storage/cache para no consultar el esquema en cada petición.
 */
function asegurar_esquema_pagos(): void
{
    if (cache_leer('esquema_pagos_v1', 86400 * 365)) {
        return;
    }
    try {
        $col = static fn(string $c): bool => (bool) valor("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = ?", [$c]);
        $tabla = static fn(string $t): bool => (bool) valor('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
        $tipo = (string) valor("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'metodo_pago'");
        if ($tipo !== '' && strpos($tipo, "'pse'") === false) {
            consulta("ALTER TABLE pedidos MODIFY metodo_pago ENUM('contra_entrega','pedido_prueba','wompi','pse') NOT NULL DEFAULT 'contra_entrega'");
        }
        if (!$col('estado_pago')) {
            consulta("ALTER TABLE pedidos ADD COLUMN estado_pago ENUM('no_aplica','pendiente','aprobado','rechazado','anulado','error') NOT NULL DEFAULT 'no_aplica' AFTER metodo_pago");
        }
        if (!$col('token_checkout')) {
            consulta('ALTER TABLE pedidos ADD COLUMN token_checkout CHAR(32) NULL AFTER estado_pago, ADD UNIQUE KEY uq_pedidos_token_checkout (token_checkout)');
        }
        if (!$tabla('pagos')) {
            consulta("CREATE TABLE pagos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT, pedido_id INT UNSIGNED NOT NULL,
                proveedor ENUM('wompi') NOT NULL DEFAULT 'wompi', metodo ENUM('wompi','pse') NOT NULL,
                ambiente ENUM('sandbox') NOT NULL DEFAULT 'sandbox', referencia VARCHAR(60) NOT NULL,
                monto_centavos BIGINT UNSIGNED NOT NULL, moneda CHAR(3) NOT NULL DEFAULT 'COP',
                estado ENUM('CREADO','PENDING','APPROVED','DECLINED','VOIDED','ERROR') NOT NULL DEFAULT 'CREADO',
                transaccion_id VARCHAR(64) NULL, medio_wompi VARCHAR(40) NULL, banco_pse VARCHAR(120) NULL,
                url_pago VARCHAR(1500) NULL, mensaje VARCHAR(255) NULL, verificado_en DATETIME NULL,
                fuente_verificacion ENUM('webhook','api') NULL, ultima_consulta DATETIME NULL,
                fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uq_pagos_referencia (referencia), UNIQUE KEY uq_pagos_transaccion (transaccion_id),
                KEY idx_pagos_pedido (pedido_id, id),
                CONSTRAINT fk_pagos_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$tabla('pagos_eventos')) {
            consulta("CREATE TABLE pagos_eventos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT, pago_id INT UNSIGNED NULL, proveedor VARCHAR(20) NOT NULL DEFAULT 'wompi',
                tipo_evento VARCHAR(60) NULL, transaccion_id VARCHAR(64) NULL, estado VARCHAR(20) NULL,
                firma_valida TINYINT(1) NOT NULL DEFAULT 0, resultado VARCHAR(255) NULL, payload MEDIUMTEXT NULL,
                ip VARCHAR(45) NULL, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_pagos_eventos_pago (pago_id),
                CONSTRAINT fk_pagos_eventos_pago FOREIGN KEY (pago_id) REFERENCES pagos (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        cache_escribir('esquema_pagos_v1', ['ok' => true, 'fecha' => date('c')]);
    } catch (Throwable $e) {
        registrar_log('No se pudo preparar el esquema de pagos: ' . $e->getMessage(), 'app.log');
    }
}

/**
 * Modo enlace: registra el pago del pedido como PAGADO (abre la página configurada de Wompi/PSE).
 * El pedido pasa a «confirmado»; los estados siguientes los actualiza el administrador.
 */
function registrar_pago_enlace(array $pedido): array
{
    return transaccion(static function () use ($pedido) {
        $p = fila('SELECT * FROM pedidos WHERE id = ? FOR UPDATE', [$pedido['id']]);
        if ($p['estado_pago'] === 'aprobado' && ($ultimo = pago_actual((int) $p['id'])) && $ultimo['url_pago']) {
            return $ultimo;
        }
        $intento = crear_intento_pago($p, $p['metodo_pago']);
        consulta("UPDATE pagos SET url_pago = ?, estado = 'APPROVED', medio_wompi = ?, verificado_en = NOW(), mensaje = NULL WHERE id = ?",
            [url_demo_pago($p['metodo_pago']), strtoupper($p['metodo_pago']), $intento['id']]);
        consulta("UPDATE pedidos SET estado_pago = 'aprobado' WHERE id = ?", [$p['id']]);
        $nombre = $p['metodo_pago'] === 'pse' ? 'PSE' : 'Wompi';
        if ($p['estado'] === 'pendiente') {
            consulta("UPDATE pedidos SET estado = 'confirmado' WHERE id = ?", [$p['id']]);
            consulta("INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario) VALUES (?, 'pendiente', 'confirmado', NULL, ?)",
                [$p['id'], "Pago registrado con $nombre (referencia {$intento['referencia']})"]);
        }
        return fila('SELECT * FROM pagos WHERE id = ?', [$intento['id']]);
    });
}

/** ¿Los pagos en línea abren los enlaces configurados (modo 'enlace_demo') en lugar de la API de Wompi? */
function pagos_modo_demo(): bool
{
    // Si config.php no define el modo (versión antigua en el servidor), se usan los enlaces.
    return (config_pagos()['modo'] ?? 'enlace_demo') === 'enlace_demo';
}

/** URL de demostración configurada para el método (sólo https). */
function url_demo_pago(string $metodo): ?string
{
    $u = trim((string) (config_pagos()['enlaces_demo'][$metodo] ?? ''));
    return preg_match('#^https://[^\s<>"]+$#i', $u) ? $u : null;
}

function es_metodo_en_linea(?string $metodo): bool
{
    return in_array($metodo, METODOS_EN_LINEA, true);
}

/** Valores por defecto en el código: la tienda funciona aunque el config.php del servidor sea antiguo. */
const PAGOS_POR_DEFECTO = [
    'modo' => 'enlace_demo',
    'enlaces_demo' => [
        'wompi' => 'https://login.wompi.co/',
        'pse'   => 'https://registro.pse.com.co/PSEUserRegister/',
    ],
    'wompi' => [
        'llave_publica' => '', 'llave_privada' => '', 'secreto_integridad' => '', 'secreto_eventos' => '',
        'checkout_url' => 'https://checkout.wompi.co/p/', 'api_url' => 'https://sandbox.wompi.co/v1',
    ],
    'pse_proveedor' => '',
    'url_publica' => '',
    'intervalo_consulta' => 6,
];

function config_pagos(): array
{
    return array_replace_recursive(PAGOS_POR_DEFECTO, (array) config('pagos', []));
}

function wompi_cfg(): array
{
    return (array) (config_pagos()['wompi'] ?? []);
}

/**
 * Problemas de configuración de Wompi (lista vacía = listo). Sólo se aceptan llaves de PRUEBAS.
 */
function wompi_problemas(): array
{
    $c = wompi_cfg();
    $prefijos = ['llave_publica' => 'pub_test_', 'llave_privada' => 'prv_test_',
                 'secreto_integridad' => 'test_integrity_', 'secreto_eventos' => 'test_events_'];
    $p = [];
    foreach (['llave_publica', 'secreto_integridad'] as $clave) {
        if (trim((string) ($c[$clave] ?? '')) === '') {
            $p[] = "Falta configurar «{$clave}» de Wompi.";
        }
    }
    foreach ($prefijos as $clave => $prefijo) {
        $v = trim((string) ($c[$clave] ?? ''));
        if ($v !== '' && strpos($v, $prefijo) !== 0) {
            $p[] = "«{$clave}» no es una llave de PRUEBAS de Wompi (debe empezar por {$prefijo}). Esta tienda sólo opera en sandbox.";
        }
    }
    if (strpos((string) ($c['api_url'] ?? ''), 'production') !== false) {
        $p[] = 'La API configurada es la de producción; esta tienda sólo usa el sandbox de Wompi.';
    }
    return $p;
}

function wompi_configurado(): bool
{
    return wompi_problemas() === [];
}

/** Mensaje para el cliente si PSE no puede usarse; null si está disponible. */
function pse_problema(): ?string
{
    if (pagos_modo_demo()) {
        return url_demo_pago('pse') ? null : 'Falta configurar el enlace de pago de PSE.';
    }
    $proveedor = (string) (config_pagos()['pse_proveedor'] ?? '');
    if ($proveedor === '') {
        return 'PSE no está disponible: la tienda necesita configurar un proveedor compatible con PSE (por ejemplo, Wompi).';
    }
    if ($proveedor !== 'wompi') {
        return "El proveedor de PSE «{$proveedor}» no está soportado. Configura 'wompi'.";
    }
    if (!wompi_configurado()) {
        return 'PSE no está disponible: falta configurar las llaves de pruebas de Wompi.';
    }
    return null;
}

/** Disponibilidad de cada método en línea: null = disponible, string = motivo. */
function problema_metodo(string $metodo): ?string
{
    if (pagos_modo_demo() && es_metodo_en_linea($metodo)) {
        return url_demo_pago($metodo) ? null : 'Falta configurar el enlace de pago de este método.';
    }
    if ($metodo === 'wompi') {
        return wompi_configurado() ? null : 'Wompi no está configurado en esta tienda (faltan las llaves de pruebas).';
    }
    if ($metodo === 'pse') {
        return pse_problema();
    }
    return null;
}

/** URL absoluta (para la URL de retorno y el webhook que necesita el proveedor). */
function url_absoluta(string $ruta, array $params = []): string
{
    $publica = rtrim((string) (config_pagos()['url_publica'] ?? ''), '/');
    if ($publica !== '') {
        $u = $publica . '/' . ltrim($ruta, '/');
        return $params ? $u . '?' . http_build_query($params) : $u;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    return (es_https() ? 'https' : 'http') . '://' . $host . url($ruta, $params);
}

// ---------------------------------------------------------------------------
// Cliente HTTP y API de Wompi
// ---------------------------------------------------------------------------

/**
 * Petición HTTP JSON. Usa cURL (incluido en XAMPP) o, si no está, file_get_contents.
 * @return array{0:int, 1:?array} [código HTTP, cuerpo decodificado]
 */
function http_json(string $metodo, string $url, ?array $cuerpo = null, array $cabeceras = [], int $timeout = 12): array
{
    $cabeceras[] = 'Accept: application/json';
    $datos = null;
    if ($cuerpo !== null) {
        $datos = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cabeceras[] = 'Content-Type: application/json';
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($datos !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $datos);
        }
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($respuesta === false) {
            throw new RuntimeException('No fue posible comunicarse con el proveedor de pagos: ' . $error);
        }
    } else {
        $ctx = stream_context_create(['http' => ['method' => $metodo, 'header' => implode("\r\n", $cabeceras),
            'content' => $datos ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $respuesta = @file_get_contents($url, false, $ctx);
        if ($respuesta === false) {
            throw new RuntimeException('No fue posible comunicarse con el proveedor de pagos.');
        }
        $codigo = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
    }
    $json = json_decode((string) $respuesta, true);
    return [$codigo, is_array($json) ? $json : null];
}

/**
 * Llama a la API de Wompi. $llave: null (sin autenticación), 'publica' o 'privada'.
 * @return array el campo "data" de la respuesta
 */
function wompi_api(string $metodo, string $ruta, ?array $cuerpo = null, ?string $llave = null, int $timeout = 12): array
{
    $c = wompi_cfg();
    $cabeceras = [];
    if ($llave !== null) {
        $valor = (string) ($c[$llave === 'privada' ? 'llave_privada' : 'llave_publica'] ?? '');
        if ($valor === '') {
            throw new RuntimeException('Falta la llave ' . $llave . ' de Wompi.');
        }
        $cabeceras[] = 'Authorization: Bearer ' . $valor;
    }
    [$codigo, $json] = http_json($metodo, rtrim((string) $c['api_url'], '/') . $ruta, $cuerpo, $cabeceras, $timeout);
    if ($codigo < 200 || $codigo >= 300 || !is_array($json) || !array_key_exists('data', $json)) {
        $detalle = is_array($json) ? json_encode($json['error'] ?? $json, JSON_UNESCAPED_UNICODE) : 'respuesta vacía';
        registrar_log("Wompi $metodo $ruta → HTTP $codigo: " . mb_substr((string) $detalle, 0, 500), 'pagos.log');
        throw new RuntimeException("Wompi respondió con un error (HTTP $codigo).");
    }
    return (array) $json['data'];
}

function cache_leer(string $clave, int $segundos): ?array
{
    $f = ROOT_PATH . '/storage/cache/' . preg_replace('/[^a-z0-9_]/', '', $clave) . '.json';
    if (is_file($f) && filemtime($f) > time() - $segundos) {
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

function cache_escribir(string $clave, array $datos): void
{
    $dir = ROOT_PATH . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/' . preg_replace('/[^a-z0-9_]/', '', $clave) . '.json', json_encode($datos), LOCK_EX);
}

/** Datos del comercio: tokens de aceptación (términos y tratamiento de datos) requeridos por la API. */
function wompi_comercio(int $timeout = 12): array
{
    $clave = 'wompi_comercio_' . substr(hash('sha256', (string) (wompi_cfg()['llave_publica'] ?? '')), 0, 12);
    if ($c = cache_leer($clave, 600)) {
        return $c;
    }
    $d = wompi_api('GET', '/merchants/' . rawurlencode((string) wompi_cfg()['llave_publica']), null, null, $timeout);
    $r = [
        'nombre'                => (string) ($d['name'] ?? ''),
        'acceptance_token'      => (string) ($d['presigned_acceptance']['acceptance_token'] ?? ''),
        'terminos_url'          => (string) ($d['presigned_acceptance']['permalink'] ?? ''),
        'datos_token'           => (string) ($d['presigned_personal_data_auth']['acceptance_token'] ?? ''),
        'datos_url'             => (string) ($d['presigned_personal_data_auth']['permalink'] ?? ''),
    ];
    if ($r['acceptance_token'] === '') {
        throw new RuntimeException('Wompi no entregó el token de aceptación del comercio.');
    }
    cache_escribir($clave, $r);
    return $r;
}

/** Lista de bancos PSE reportada por Wompi: [código => nombre]. */
function wompi_bancos_pse(int $timeout = 12): array
{
    if ($c = cache_leer('wompi_bancos_pse', 3600)) {
        return $c;
    }
    $bancos = [];
    foreach (wompi_api('GET', '/pse/financial_institutions', null, 'publica', $timeout) as $b) {
        $cod = (string) ($b['financial_institution_code'] ?? '');
        if ($cod !== '' && $cod !== '0') {
            $bancos[$cod] = (string) ($b['financial_institution_name'] ?? $cod);
        }
    }
    if (!$bancos) {
        throw new RuntimeException('Wompi no devolvió bancos PSE.');
    }
    cache_escribir('wompi_bancos_pse', $bancos);
    return $bancos;
}

/** Firma de integridad: SHA256(referencia + monto_en_centavos + moneda + secreto_de_integridad). */
function wompi_firma_integridad(string $referencia, int $centavos, string $moneda): string
{
    return hash('sha256', $referencia . $centavos . $moneda . (string) wompi_cfg()['secreto_integridad']);
}

// ---------------------------------------------------------------------------
// Intentos de pago
// ---------------------------------------------------------------------------

function pago_actual(int $pedidoId): ?array
{
    return fila('SELECT * FROM pagos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1', [$pedidoId]);
}

/** Crea un intento de pago con referencia única (incluye un sufijo aleatorio para no chocar con pruebas anteriores). */
function crear_intento_pago(array $pedido, string $metodo): array
{
    if (!es_metodo_en_linea($metodo)) {
        throw new DomainException('Método de pago en línea inválido.');
    }
    $n = (int) valor('SELECT COUNT(*) FROM pagos WHERE pedido_id = ?', [$pedido['id']]) + 1;
    $referencia = sprintf('%s-P%d-%s', $pedido['codigo'], $n, bin2hex(random_bytes(3)));
    $centavos = (int) round((float) $pedido['total'] * 100);
    consulta('INSERT INTO pagos (pedido_id, metodo, referencia, monto_centavos, moneda) VALUES (?, ?, ?, ?, ?)',
        [$pedido['id'], $metodo, $referencia, $centavos, 'COP']);
    consulta("UPDATE pedidos SET estado_pago = 'pendiente' WHERE id = ? AND estado_pago <> 'aprobado'", [$pedido['id']]);
    return fila('SELECT * FROM pagos WHERE referencia = ?', [$referencia]);
}

/** URL real del Web Checkout de Wompi, firmada en el servidor. */
function wompi_url_checkout(array $pago, array $pedido, array $cliente): string
{
    $c = wompi_cfg();
    $parametros = [
        'public-key'              => $c['llave_publica'],
        'currency'                => $pago['moneda'],
        'amount-in-cents'         => (int) $pago['monto_centavos'],
        'reference'               => $pago['referencia'],
        'signature:integrity'     => wompi_firma_integridad($pago['referencia'], (int) $pago['monto_centavos'], $pago['moneda']),
        'redirect-url'            => url_absoluta('pago_retorno.php', ['ref' => $pago['referencia']]),
        'customer-data:email'     => $cliente['email'],
        'customer-data:full-name' => mb_substr($pedido['nombre_destinatario'], 0, 60),
    ];
    $tel = preg_replace('/\D/', '', (string) $pedido['telefono_entrega']);
    if (strlen($tel) >= 7) {
        $parametros['customer-data:phone-number'] = $tel;
        $parametros['customer-data:phone-number-prefix'] = '+57';
    }
    return rtrim((string) $c['checkout_url'], '/') . '/?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Crea la transacción PSE en Wompi y obtiene la URL del flujo PSE (async_payment_url).
 * $pse = ['tipo_persona' => 0|1, 'tipo_documento' => 'CC', 'documento' => '...', 'banco' => '1']
 */
function wompi_iniciar_pse(array $pago, array $pedido, array $cliente, array $pse): array
{
    $comercio = wompi_comercio();
    $tel = preg_replace('/\D/', '', (string) $pedido['telefono_entrega']);
    $cuerpo = [
        'acceptance_token' => $comercio['acceptance_token'],
        'amount_in_cents'  => (int) $pago['monto_centavos'],
        'currency'         => $pago['moneda'],
        'signature'        => wompi_firma_integridad($pago['referencia'], (int) $pago['monto_centavos'], $pago['moneda']),
        'customer_email'   => $cliente['email'],
        'reference'        => $pago['referencia'],
        'redirect_url'     => url_absoluta('pago_retorno.php', ['ref' => $pago['referencia']]),
        'payment_method'   => [
            'type'                       => 'PSE',
            'user_type'                  => (int) $pse['tipo_persona'],
            'user_legal_id_type'         => $pse['tipo_documento'],
            'user_legal_id'              => $pse['documento'],
            'financial_institution_code' => $pse['banco'],
            'payment_description'        => mb_substr('Pedido ' . $pedido['codigo'] . ' ' . ajuste('nombre_tienda', 'FIRE CAT'), 0, 60),
        ],
        'customer_data'    => ['phone_number' => strlen($tel) === 10 ? '57' . $tel : $tel, 'full_name' => mb_substr($pedido['nombre_destinatario'], 0, 60)],
    ];
    if ($comercio['datos_token'] !== '') {
        $cuerpo['accept_personal_auth'] = $comercio['datos_token'];
    }
    $tx = wompi_api('POST', '/transactions', $cuerpo, 'publica');
    $txId = (string) ($tx['id'] ?? '');
    if ($txId === '') {
        throw new RuntimeException('Wompi no devolvió el identificador de la transacción PSE.');
    }
    $banco = wompi_bancos_pse()[$pse['banco']] ?? $pse['banco'];
    consulta("UPDATE pagos SET transaccion_id = ?, estado = 'PENDING', banco_pse = ?, medio_wompi = 'PSE' WHERE id = ?", [$txId, $banco, $pago['id']]);

    // La URL del flujo PSE se genera de forma asíncrona: se consulta unos segundos.
    $url = (string) ($tx['payment_method']['extra']['async_payment_url'] ?? '');
    for ($i = 0; $url === '' && $i < 8; $i++) {
        usleep(900000);
        $d = wompi_api('GET', '/transactions/' . rawurlencode($txId));
        $url = (string) ($d['payment_method']['extra']['async_payment_url'] ?? '');
        if (in_array($d['status'] ?? '', ['DECLINED', 'ERROR', 'VOIDED'], true)) {
            aplicar_transaccion_wompi($d, 'api');
            throw new RuntimeException('Wompi rechazó la solicitud PSE: ' . ($d['status_message'] ?? $d['status']));
        }
    }
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        throw new RuntimeException('Wompi todavía no entregó la URL del flujo PSE. Intenta de nuevo en unos segundos.');
    }
    consulta('UPDATE pagos SET url_pago = ? WHERE id = ?', [$url, $pago['id']]);
    return fila('SELECT * FROM pagos WHERE id = ?', [$pago['id']]);
}

// ---------------------------------------------------------------------------
// Verificación y aplicación de estados (webhook / API)
// ---------------------------------------------------------------------------

/**
 * Aplica el estado de una transacción de Wompi YA VERIFICADA (firma de webhook o respuesta de la API).
 * Exige que la referencia exista y que monto y moneda coincidan. Es idempotente y no retrocede
 * estados finales (p. ej. un PENDING tardío no deshace un APPROVED).
 * @return array{ok:bool, motivo:string, pago_id:?int}
 */
function aplicar_transaccion_wompi(array $tx, string $fuente): array
{
    $referencia = (string) ($tx['reference'] ?? '');
    $estado = strtoupper((string) ($tx['status'] ?? ''));
    $txId = (string) ($tx['id'] ?? '');
    if ($referencia === '' || $txId === '' || !isset(WOMPI_A_ESTADO_PAGO[$estado]) || $estado === 'CREADO') {
        return ['ok' => false, 'motivo' => 'Transacción incompleta o con estado desconocido.', 'pago_id' => null];
    }
    return transaccion(static function () use ($tx, $fuente, $referencia, $estado, $txId) {
        $pago = fila('SELECT * FROM pagos WHERE referencia = ? FOR UPDATE', [$referencia]);
        if (!$pago) {
            return ['ok' => false, 'motivo' => 'Referencia desconocida para esta tienda.', 'pago_id' => null];
        }
        if ((int) ($tx['amount_in_cents'] ?? -1) !== (int) $pago['monto_centavos'] || strtoupper((string) ($tx['currency'] ?? '')) !== $pago['moneda']) {
            registrar_log("Pago {$referencia}: monto/moneda no coinciden (tx {$txId})", 'seguridad.log');
            return ['ok' => false, 'motivo' => 'El monto o la moneda no coinciden con el pedido.', 'pago_id' => (int) $pago['id']];
        }
        if ($pago['transaccion_id'] !== null && $pago['transaccion_id'] !== $txId) {
            return ['ok' => false, 'motivo' => 'La transacción no corresponde a este intento de pago.', 'pago_id' => (int) $pago['id']];
        }
        $actual = $pago['estado'];
        $finales = ['APPROVED', 'DECLINED', 'VOIDED', 'ERROR'];
        $permitido = !in_array($actual, $finales, true) || ($actual === 'APPROVED' && $estado === 'VOIDED') || $actual === $estado;
        if (!$permitido) {
            return ['ok' => true, 'motivo' => "Se conserva el estado final {$actual}.", 'pago_id' => (int) $pago['id']];
        }
        consulta('UPDATE pagos SET estado = ?, transaccion_id = ?, medio_wompi = COALESCE(?, medio_wompi), mensaje = ?,
                         verificado_en = NOW(), fuente_verificacion = ? WHERE id = ?', [
            $estado, $txId, isset($tx['payment_method_type']) ? (string) $tx['payment_method_type'] : null,
            isset($tx['status_message']) ? mb_substr((string) $tx['status_message'], 0, 255) : null, $fuente, $pago['id'],
        ]);
        // Un intento antiguo sólo cambia el estado de pago del pedido si quedó APROBADO
        // (un rechazo tardío de un intento viejo no debe pisar un reintento en curso).
        $esUltimo = (int) valor('SELECT MAX(id) FROM pagos WHERE pedido_id = ?', [$pago['pedido_id']]) === (int) $pago['id'];
        if ($actual !== $estado && ($esUltimo || $estado === 'APPROVED' || $estado === 'VOIDED' && $actual === 'APPROVED')) {
            actualizar_estado_pago_pedido((int) $pago['pedido_id'], $estado, $txId);
        }
        return ['ok' => true, 'motivo' => "Estado {$estado} aplicado.", 'pago_id' => (int) $pago['id']];
    });
}

/** Refleja el resultado del pago en el pedido (dentro de la transacción de aplicar_transaccion_wompi). */
function actualizar_estado_pago_pedido(int $pedidoId, string $estadoWompi, string $txId): void
{
    $pedido = fila('SELECT id, estado, estado_pago FROM pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
    if (!$pedido || $pedido['estado_pago'] === 'aprobado' && $estadoWompi !== 'VOIDED') {
        return; // un pedido ya pagado no cambia por un intento posterior
    }
    $nuevo = WOMPI_A_ESTADO_PAGO[$estadoWompi];
    consulta('UPDATE pedidos SET estado_pago = ? WHERE id = ?', [$nuevo, $pedidoId]);
    $comentario = [
        'aprobado'  => "Pago aprobado y verificado con Wompi (transacción $txId)",
        'rechazado' => "Pago rechazado por Wompi (transacción $txId)",
        'anulado'   => "Pago anulado en Wompi (transacción $txId)",
        'error'     => "Error en el pago reportado por Wompi (transacción $txId)",
        'pendiente' => null,
    ][$nuevo];
    if ($nuevo === 'aprobado' && $pedido['estado'] === 'pendiente') {
        consulta("UPDATE pedidos SET estado = 'confirmado' WHERE id = ?", [$pedidoId]);
        consulta("INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario) VALUES (?, 'pendiente', 'confirmado', NULL, ?)",
            [$pedidoId, $comentario]);
    } elseif ($comentario !== null) {
        if ($nuevo === 'aprobado' && $pedido['estado'] === 'cancelado') {
            $comentario .= ' · ATENCIÓN: el pedido estaba cancelado, gestionar reembolso';
        }
        consulta('INSERT INTO pedido_historial (pedido_id, estado_anterior, estado_nuevo, usuario_id, comentario) VALUES (?, ?, ?, NULL, ?)',
            [$pedidoId, $pedido['estado'], $pedido['estado'], $comentario]);
    }
}

/**
 * Consulta el estado REAL en la API de Wompi (servidor a servidor) y lo aplica.
 * $idCandidato: id de transacción recibido en la URL de retorno; sólo se usa para consultar,
 * y la respuesta se acepta únicamente si su referencia coincide con este intento de pago.
 */
function verificar_pago(array $pago, ?string $idCandidato = null, bool $forzar = false): array
{
    if (pagos_modo_demo() || !wompi_configurado() || in_array($pago['estado'], ['APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true) && !$forzar) {
        return $pago;
    }
    $intervalo = max(2, (int) (config_pagos()['intervalo_consulta'] ?? 6));
    if (!$forzar && $pago['ultima_consulta'] !== null && strtotime((string) $pago['ultima_consulta']) > time() - $intervalo) {
        return $pago;
    }
    consulta('UPDATE pagos SET ultima_consulta = NOW() WHERE id = ?', [$pago['id']]);
    try {
        $txId = $pago['transaccion_id'] ?? null;
        if (!$txId && $idCandidato !== null && preg_match('/^[A-Za-z0-9\-]{5,64}$/', $idCandidato)) {
            $txId = $idCandidato;
        }
        $tx = null;
        if ($txId) {
            $tx = wompi_api('GET', '/transactions/' . rawurlencode($txId));
        } elseif (trim((string) (wompi_cfg()['llave_privada'] ?? '')) !== '') {
            $desde = date('Y-m-d', strtotime((string) $pago['fecha_creacion'] . ' -1 day'));
            $hasta = date('Y-m-d', strtotime('+1 day'));
            $lista = wompi_api('GET', '/transactions?' . http_build_query(['reference' => $pago['referencia'], 'from_date' => $desde, 'until_date' => $hasta]), null, 'privada');
            $tx = $lista[0] ?? null;
            foreach ($lista as $t) {
                if (($t['status'] ?? '') === 'APPROVED') {
                    $tx = $t;
                }
            }
        }
        if (is_array($tx) && ($tx['reference'] ?? '') === $pago['referencia']) {
            aplicar_transaccion_wompi($tx, 'api');
        }
    } catch (RuntimeException $e) {
        registrar_log("Verificación de {$pago['referencia']}: " . $e->getMessage(), 'pagos.log');
    }
    return fila('SELECT * FROM pagos WHERE id = ?', [$pago['id']]) ?? $pago;
}

/** Valida la firma de un evento de Wompi: SHA256(valores de signature.properties + timestamp + secreto de eventos). */
function wompi_firma_evento_valida(array $evento): bool
{
    $secreto = (string) (wompi_cfg()['secreto_eventos'] ?? '');
    $propiedades = $evento['signature']['properties'] ?? null;
    $checksum = strtolower((string) ($evento['signature']['checksum'] ?? ''));
    if ($secreto === '' || !is_array($propiedades) || !$propiedades || $checksum === '' || !isset($evento['timestamp'])) {
        return false;
    }
    $cadena = '';
    foreach ($propiedades as $ruta) {
        $valor = $evento['data'] ?? [];
        foreach (explode('.', (string) $ruta) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return false;
            }
            $valor = $valor[$parte];
        }
        if (is_array($valor)) {
            return false;
        }
        $cadena .= is_bool($valor) ? ($valor ? 'true' : 'false') : (string) $valor;
    }
    return hash_equals(hash('sha256', $cadena . $evento['timestamp'] . $secreto), $checksum);
}

/**
 * Estado de pago para mostrar al cliente.
 * @return array{clave:string, etiqueta:string, color:string, icono:string, mensaje:string, final:bool}
 */
function info_estado_pago(array $pedido): array
{
    $metodo = $pedido['metodo_pago'];
    if ($metodo === 'pedido_prueba') {
        return ['clave' => 'prueba', 'etiqueta' => 'PEDIDO DE PRUEBA', 'color' => 'secondary', 'icono' => 'bi-bag',
                'mensaje' => 'Pedido de prueba sin pago.', 'final' => true];
    }
    if ($metodo === 'contra_entrega') {
        return ['clave' => 'contra_entrega', 'etiqueta' => 'PAGO CONTRA ENTREGA', 'color' => 'secondary', 'icono' => 'bi-cash-coin',
                'mensaje' => 'El pago se realiza en efectivo al recibir el pedido.', 'final' => true];
    }
    $clave = $pedido['estado_pago'] === 'no_aplica' ? 'aprobado' : $pedido['estado_pago'];
    [$etiqueta, $color, $icono] = ESTADOS_PAGO[$clave] ?? ESTADOS_PAGO['pendiente'];
    $mensaje = [
        'aprobado'  => '¡Pago confirmado exitosamente!',
        'pendiente' => 'Tu solicitud de pago fue recibida y está pendiente de confirmación.',
        'rechazado' => 'No fue posible completar el pago.',
        'anulado'   => 'No fue posible completar el pago.',
        'error'     => 'No fue posible completar el pago.',
    ][$clave] ?? 'Tu solicitud de pago fue recibida y está pendiente de confirmación.';
    return ['clave' => $clave, 'etiqueta' => $etiqueta, 'color' => $color, 'icono' => $icono, 'mensaje' => $mensaje,
            'final' => $clave !== 'pendiente'];
}

function estado_pago_badge(array $pedido): string
{
    $i = info_estado_pago($pedido);
    $extra = $i['color'] === 'warning' ? ' text-dark' : '';
    return '<span class="badge rounded-pill text-bg-' . $i['color'] . $extra . '"><i class="bi ' . $i['icono'] . ' me-1"></i>' . e($i['etiqueta']) . '</span>';
}

/**
 * Datos para el formulario de PSE (bancos y enlaces de términos de Wompi).
 * Si Wompi no responde, se recuerda el fallo 60 s para no bloquear cada carga de página.
 * @return array{bancos: array, terminos_url: string, datos_url: string, error: ?string}
 */
function pse_datos_formulario(): array
{
    $vacio = ['bancos' => [], 'terminos_url' => '', 'datos_url' => '', 'error' => null];
    if (pagos_modo_demo()) {
        return ['error' => problema_metodo('pse')] + $vacio;   // en demostración no se piden bancos
    }
    if (($p = pse_problema()) !== null) {
        return ['error' => $p] + $vacio;
    }
    if (cache_leer('wompi_fallo_reciente', 60)) {
        return ['error' => 'No fue posible contactar a Wompi para cargar los bancos PSE. Intenta de nuevo en un minuto.'] + $vacio;
    }
    try {
        // Tiempos cortos: si el hosting no alcanza a Wompi, la página de checkout no debe quedarse esperando.
        $comercio = wompi_comercio(5);
        return ['bancos' => wompi_bancos_pse(5), 'terminos_url' => $comercio['terminos_url'], 'datos_url' => $comercio['datos_url'], 'error' => null];
    } catch (Throwable $e) {
        cache_escribir('wompi_fallo_reciente', ['t' => time()]);
        registrar_log('Formulario PSE: ' . $e->getMessage(), 'pagos.log');
        return ['error' => 'No fue posible contactar a Wompi para cargar los bancos PSE. Intenta de nuevo en un minuto.'] + $vacio;
    }
}
