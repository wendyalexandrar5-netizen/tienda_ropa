<?php

declare(strict_types=1);

/*
 * =============================================================================
 * PRUEBAS DE SEGURIDAD DE LA API Y LA WEB (caja negra, vía HTTP)
 * -----------------------------------------------------------------------------
 * Requisitos: servidor en BASE_URL (por defecto http://127.0.0.1:8080) y datos
 * de prueba (php scripts/seed_demo.php).
 *   php tests/security/api_security_test.php
 * Opcional: POSTGREST_URL para probar también la Data API de Supabase
 * directamente con la clave pública (RLS sin pasar por el backend PHP).
 * =============================================================================
 */

$BASE = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8080', '/');
$PASS = getenv('DEMO_PASSWORD') ?: 'FireCat-Demo-2026';
$POSTGREST = getenv('POSTGREST_URL') ?: '';
$results = [];

function http(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $body = null;
    if (array_key_exists('json', $opts)) {
        $headers[] = 'Content-Type: application/json';
        $body = is_string($opts['json']) ? $opts['json'] : json_encode($opts['json']);
    } elseif (isset($opts['form'])) {
        $body = $opts['form'];
    }
    if (isset($opts['token'])) {
        $headers[] = 'Authorization: Bearer ' . $opts['token'];
    }
    $headers[] = 'Accept: ' . ($opts['accept'] ?? 'application/json');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (isset($opts['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['jar']);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsize);
    $text = substr($raw, $hsize);
    return ['status' => $status, 'headers' => strtolower($head), 'rawHeaders' => $head, 'body' => $text, 'json' => json_decode($text, true)];
}

function check(string $categoria, string $nombre, bool $ok, string $detalle = ''): void
{
    global $results;
    $results[] = [$categoria, $nombre, $ok, $detalle];
    printf("  %s %s%s\n", $ok ? '✔' : '✘', $nombre, $detalle !== '' ? " — $detalle" : '');
}

function login(string $email, string $pass): string
{
    global $BASE;
    $r = http('POST', "$BASE/api/auth/login", ['json' => ['email' => $email, 'password' => $pass]]);
    if (($r['json']['success'] ?? false) !== true) {
        fwrite(STDERR, "No se pudo iniciar sesión como $email: {$r['body']}\n");
        exit(2);
    }
    return $r['json']['data']['session']['access_token'];
}

function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

echo "Preparando sesiones de prueba…\n";
$camila = login('camila@firecat.test', $PASS);
$andres = login('andres@firecat.test', $PASS);
$admin = login('admin@firecat.test', $PASS);

$andresOrders = http('GET', "$BASE/api/orders", ['token' => $andres])['json']['data'];
$andresOrderId = $andresOrders[0]['id'];
$andresAddress = http('GET', "$BASE/api/addresses", ['token' => $andres])['json']['data'][0]['id'];
$camilaAddress = http('GET', "$BASE/api/addresses", ['token' => $camila])['json']['data'][0]['id'];

// ----------------------------------------------------------------------------
echo "\n1. Acceso sin autenticación\n";
foreach (['/api/cart', '/api/orders', '/api/profile', '/api/addresses', '/api/admin/dashboard', '/api/admin/users'] as $p) {
    $r = http('GET', $BASE . $p);
    check('Sin autenticación', "GET $p → 401", $r['status'] === 401, "HTTP {$r['status']}");
}
$r = http('GET', "$BASE/admin", ['accept' => 'text/html']);
check('Sin autenticación', 'GET /admin redirige a /login', $r['status'] === 303 && str_contains($r['headers'], 'location: /login'), "HTTP {$r['status']}");
$r = http('GET', "$BASE/api/products");
check('Sin autenticación', 'El catálogo público sí es accesible', $r['status'] === 200 && count($r['json']['data']) > 0);

// ----------------------------------------------------------------------------
echo "\n2. Acceso como cliente a rutas administrativas\n";
$tests = [
    ['GET', '/api/admin/dashboard', null], ['GET', '/api/admin/users', null], ['GET', '/api/admin/orders', null],
    ['POST', '/api/admin/products', ['nombre' => 'Hack', 'categoria_id' => 1, 'precio' => 1, 'estado' => 'activo']],
    ['PUT', "/api/admin/orders/$andresOrderId/status", ['estado' => 'entregado']],
    ['POST', '/api/admin/inventory/adjust', ['variante_id' => 1, 'tipo' => 'entrada', 'cantidad' => 999, 'motivo' => 'hack']],
    ['PUT', '/api/admin/settings', ['costo_envio' => 0]],
    ['GET', '/api/admin/reports/sales', null],
];
foreach ($tests as [$m, $p, $body]) {
    $opts = ['token' => $camila];
    if ($body !== null) {
        $opts['json'] = $body;
    }
    $r = http($m, $BASE . $p, $opts);
    check('Rol cliente', "$m $p → 403", $r['status'] === 403, "HTTP {$r['status']}");
}

echo "\n3. Acceso como administrador\n";
$r = http('GET', "$BASE/api/admin/dashboard", ['token' => $admin]);
check('Rol admin', 'Admin accede al dashboard', $r['status'] === 200 && isset($r['json']['data']['resumen']['ventas_mes']));
$r = http('GET', "$BASE/api/admin/users", ['token' => $admin]);
check('Rol admin', 'Admin lista clientes', $r['status'] === 200 && ($r['json']['meta']['total'] ?? 0) >= 5);

// ----------------------------------------------------------------------------
echo "\n4. Pedidos ajenos (IDOR)\n";
$r = http('GET', "$BASE/api/orders/$andresOrderId", ['token' => $camila]);
check('Pedidos ajenos', 'Cliente A no puede ver el pedido de B (404)', $r['status'] === 404, "HTTP {$r['status']}");
$r = http('POST', "$BASE/api/orders/$andresOrderId/cancel", ['token' => $camila]);
check('Pedidos ajenos', 'Cliente A no puede cancelar el pedido de B', $r['status'] === 404, "HTTP {$r['status']}");
$r = http('GET', "$BASE/api/orders", ['token' => $camila]);
$ids = array_column($r['json']['data'], 'id');
check('Pedidos ajenos', 'El listado solo contiene pedidos propios', !in_array($andresOrderId, $ids, true));
$r = http('PUT', "$BASE/api/addresses/$andresAddress", ['token' => $camila, 'json' => ['destinatario' => 'Hack', 'telefono' => '3000000000', 'direccion' => 'Calle hack 1', 'ciudad' => 'X', 'departamento' => 'X']]);
check('Pedidos ajenos', 'Cliente A no puede modificar direcciones de B', $r['status'] === 404, "HTTP {$r['status']}");

// ----------------------------------------------------------------------------
echo "\n5. Manipulación de precios, totales y propietario\n";
$product = http('GET', "$BASE/api/products/pantalon-cargo-tactical")['json']['data'];
$variant = null;
foreach ($product['variantes'] as $v) {
    if ($v['stock'] >= 2) { $variant = $v; break; }
}
http('DELETE', "$BASE/api/cart", ['token' => $camila]);
$r = http('POST', "$BASE/api/cart", ['token' => $camila, 'json' => ['variante_id' => $variant['id'], 'cantidad' => 1, 'precio' => 1, 'precio_unitario' => 1, 'subtotal' => 1]]);
$item = $r['json']['data']['items'][0] ?? [];
check('Precios', 'El carrito ignora el precio enviado por el cliente', ($item['precio_unitario'] ?? 0) == $variant['precio'], 'precio real ' . ($item['precio_unitario'] ?? '?'));
$r = http('POST', "$BASE/api/orders", ['token' => $camila, 'json' => [
    'direccion_id' => $camilaAddress, 'metodo_pago' => 'contra_entrega',
    'total' => 1, 'subtotal' => 1, 'costo_envio' => 0, 'usuario_id' => '00000000-0000-0000-0000-000000000000', 'estado' => 'entregado',
    'items' => [['variante_id' => $variant['id'], 'cantidad' => 1, 'precio_unitario' => 1]],
]]);
$order = $r['json']['data'] ?? [];
check('Precios', 'El pedido usa el precio real de la BD (no el total enviado)', $r['status'] === 201 && $order['subtotal'] == $variant['precio'] && $order['total'] > 1, 'total ' . ($order['total'] ?? '?'));
check('Precios', 'El estado inicial no puede ser manipulado', ($order['estado'] ?? '') === 'pendiente');
$mine = array_column(http('GET', "$BASE/api/orders", ['token' => $camila])['json']['data'], 'id');
check('Precios', 'El propietario del pedido es el usuario autenticado', in_array($order['id'] ?? '', $mine, true));
$r = http('POST', "$BASE/api/orders", ['token' => $camila, 'json' => ['direccion_id' => $andresAddress, 'metodo_pago' => 'contra_entrega']]);
check('Precios', 'No se puede usar la dirección de otro cliente', in_array($r['status'], [422, 409], true) && in_array($r['json']['error']['code'] ?? '', ['DIRECCION_INVALIDA', 'CARRITO_VACIO'], true), $r['json']['error']['code'] ?? '');
$r = http('PUT', "$BASE/api/admin/products/{$product['id']}", ['token' => $camila, 'json' => ['precio' => 1]]);
check('Precios', 'Un cliente no puede cambiar el precio de un producto', $r['status'] === 403);

// ----------------------------------------------------------------------------
echo "\n6. Stock y cantidades\n";
$agotada = null;
foreach (http('GET', "$BASE/api/products/hoodie-fire-street")['json']['data']['variantes'] as $v) {
    if ($v['stock'] === 0) { $agotada = $v; }
}
$r = http('POST', "$BASE/api/cart", ['token' => $camila, 'json' => ['variante_id' => $agotada['id'] ?? 0, 'cantidad' => 1]]);
check('Stock', 'No se puede agregar una variante sin stock', $r['status'] === 409 && ($r['json']['error']['code'] ?? '') === 'STOCK_INSUFICIENTE', "HTTP {$r['status']}");
foreach ([-5 => 'negativa', 0 => 'cero', 1000 => 'excesiva'] as $qty => $label) {
    $r = http('POST', "$BASE/api/cart", ['token' => $camila, 'json' => ['variante_id' => $variant['id'], 'cantidad' => $qty]]);
    check('Cantidades', "Cantidad $label ($qty) rechazada", $r['status'] === 422, "HTTP {$r['status']}");
}
$r = http('POST', "$BASE/api/cart", ['token' => $camila, 'json' => ['variante_id' => $variant['id'], 'cantidad' => '1.5']]);
check('Cantidades', 'Cantidad decimal rechazada', $r['status'] === 422);
$r = http('POST', "$BASE/api/admin/pos/sales", ['token' => $admin, 'json' => ['items' => [['variante_id' => $variant['id'], 'cantidad' => 5], ['variante_id' => $variant['id'], 'cantidad' => -5]], 'pago_recibido' => 0]]);
check('Cantidades', 'POS: líneas negativas no compensan totales', $r['status'] === 422);

// ----------------------------------------------------------------------------
echo "\n7. Inyección SQL\n";
$payloads = ["' OR 1=1 --", "'; DROP TABLE productos; --", "1' UNION SELECT email FROM auth.users --", "\\' OR '1'='1"];
foreach ($payloads as $pl) {
    $r = http('GET', "$BASE/api/products?q=" . rawurlencode($pl));
    check('SQL Injection', 'Búsqueda con ' . substr($pl, 0, 24), $r['status'] === 200 && count($r['json']['data']) === 0, "HTTP {$r['status']}, " . count($r['json']['data'] ?? []) . ' resultados');
}
$r = http('GET', "$BASE/api/products?orden=" . rawurlencode('precio_asc; DROP TABLE pedidos') . '&categoria=' . rawurlencode("x' OR '1'='1"));
check('SQL Injection', 'Parámetros de orden/categoría no inyectables', $r['status'] === 200);
$r = http('GET', "$BASE/api/orders/" . rawurlencode("' OR 1=1 --"), ['token' => $camila]);
check('SQL Injection', 'ID de pedido malicioso → 404', $r['status'] === 404);
$r = http('GET', "$BASE/api/products/" . rawurlencode("1 OR 1=1"));
check('SQL Injection', 'ID de producto malicioso → 404', $r['status'] === 404);
check('SQL Injection', 'La tabla productos sigue intacta', count(http('GET', "$BASE/api/products?per_page=48")['json']['data'] ?? []) >= 20);

// ----------------------------------------------------------------------------
echo "\n8. XSS\n";
$r = http('PUT', "$BASE/api/profile", ['token' => $camila, 'json' => ['nombre' => '<script>alert(1)</script>', 'apellido' => 'Rojas']]);
check('XSS', 'Nombre con <script> rechazado por el servidor', $r['status'] === 422);
$r = http('POST', "$BASE/api/addresses", ['token' => $camila, 'json' => ['destinatario' => '<img src=x onerror=alert(1)>', 'telefono' => '3000000000', 'direccion' => 'Calle 1 # 2-3', 'ciudad' => 'Cali', 'departamento' => 'Valle']]);
check('XSS', 'Dirección con HTML rechazada', $r['status'] === 422);
$r = http('GET', "$BASE/catalogo?q=" . rawurlencode('<script>alert("xss")</script>'), ['accept' => 'text/html']);
check('XSS', 'Término de búsqueda escapado al renderizar', !str_contains($r['body'], '<script>alert("xss")</script>') && str_contains($r['body'], '&lt;script&gt;'));
$csp = preg_match('/content-security-policy: ([^\r\n]+)/', $r['headers'], $m) ? $m[1] : '';
check('XSS', 'CSP sin scripts inline (script-src \'self\')', str_contains($csp, "script-src 'self'") && !preg_match("/script-src[^;]*unsafe-inline/", $csp));
check('XSS', 'Cabeceras X-Content-Type-Options y X-Frame-Options', str_contains($r['headers'], 'x-content-type-options: nosniff') && str_contains($r['headers'], 'x-frame-options: deny'));

// ----------------------------------------------------------------------------
echo "\n9. CSRF (sesión web con cookie)\n";
$jar = tempnam(sys_get_temp_dir(), 'fc');
$page = http('GET', "$BASE/login", ['jar' => $jar, 'accept' => 'text/html']);
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
$token = $m[1] ?? '';
$r = http('POST', "$BASE/login", ['jar' => $jar, 'accept' => 'text/html', 'form' => http_build_query(['email' => 'camila@firecat.test', 'password' => $PASS])]);
check('CSRF', 'Login web sin token CSRF rechazado', $r['status'] === 303 && !str_contains($r['headers'], 'location: /cuenta') && !str_contains($r['headers'], 'location: / '));
$r = http('POST', "$BASE/login", ['jar' => $jar, 'accept' => 'text/html', 'form' => http_build_query(['email' => 'camila@firecat.test', 'password' => $PASS, '_csrf' => $token])]);
check('CSRF', 'Login web con token válido', $r['status'] === 303 && preg_match('#location: /\r?\n#', $r['headers']) === 1);
$home = http('GET', "$BASE/", ['jar' => $jar, 'accept' => 'text/html']);
preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $home['body'], $m);
$token = $m[1] ?? '';
$r = http('POST', "$BASE/api/cart", ['jar' => $jar, 'json' => ['variante_id' => $variant['id'], 'cantidad' => 1]]);
check('CSRF', 'API con cookie de sesión y SIN X-CSRF-Token → 419', $r['status'] === 419, "HTTP {$r['status']}");
$r = http('POST', "$BASE/api/cart", ['jar' => $jar, 'json' => ['variante_id' => $variant['id'], 'cantidad' => 1], 'headers' => ['X-CSRF-Token: ' . str_repeat('a', 64)]]);
check('CSRF', 'Token CSRF falsificado → 419', $r['status'] === 419);
$r = http('POST', "$BASE/api/cart", ['jar' => $jar, 'json' => ['variante_id' => $variant['id'], 'cantidad' => 1], 'headers' => ["X-CSRF-Token: $token"]]);
check('CSRF', 'Token CSRF válido → permitido', $r['status'] === 201, "HTTP {$r['status']}");
check('CSRF', 'Cookie de sesión HttpOnly + SameSite=Lax', (bool) preg_match('/set-cookie: fc_session=[^\r\n]*httponly[^\r\n]*samesite=lax/', $page['headers']));
@unlink($jar);

// ----------------------------------------------------------------------------
echo "\n10. Manipulación de parámetros e ID de usuario\n";
$r = http('PUT', "$BASE/api/profile", ['token' => $camila, 'json' => ['nombre' => 'Camila', 'apellido' => 'Rojas', 'rol' => 'admin', 'estado' => 'activo', 'id' => '00000000-0000-0000-0000-000000000000']]);
check('Parámetros', 'Enviar rol=admin en el perfil no eleva privilegios', ($r['json']['data']['rol'] ?? '') === 'cliente');
$r = http('POST', "$BASE/api/addresses", ['token' => $camila, 'json' => ['usuario_id' => 'bbbbbbbb-0000-0000-0000-00000000000b', 'destinatario' => 'Camila', 'telefono' => '3000000000', 'direccion' => 'Calle 5 # 6-7', 'ciudad' => 'Cali', 'departamento' => 'Valle']]);
$newAddr = $r['json']['data']['id'] ?? '';
$visibleForCamila = in_array($newAddr, array_column(http('GET', "$BASE/api/addresses", ['token' => $camila])['json']['data'], 'id'), true);
check('Parámetros', 'usuario_id enviado se ignora (dirección queda del usuario autenticado)', $r['status'] === 201 && $visibleForCamila);
if ($newAddr) { http('DELETE', "$BASE/api/addresses/$newAddr", ['token' => $camila]); }
$r = http('POST', "$BASE/api/orders", ['token' => $camila, 'json' => ['direccion_id' => $camilaAddress, 'metodo_pago' => 'gratis']]);
check('Parámetros', 'Método de pago no permitido → 422', $r['status'] === 422);
$r = http('POST', "$BASE/api/register", ['json' => []]);
check('Parámetros', 'Endpoint inexistente → 404 JSON', $r['status'] === 404 && ($r['json']['success'] ?? null) === false);
$r = http('POST', "$BASE/api/cart", ['token' => $camila, 'json' => '{"variante_id": 1, "cantidad": ']);
check('Errores', 'JSON inválido → 400 sin trazas internas', $r['status'] === 400 && !str_contains($r['body'], '.php') && !str_contains($r['body'], 'SQLSTATE'));

[$h, $p, $s] = explode('.', $camila);
$claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
$claims['sub'] = json_decode(base64_decode(strtr(explode('.', $andres)[1], '-_', '+/')), true)['sub'];
$forged = "$h." . b64(json_encode($claims)) . ".$s";
$r = http('GET', "$BASE/api/orders", ['token' => $forged]);
check('ID de usuario', 'JWT con "sub" alterado (suplantación) → 401', $r['status'] === 401);
$none = b64(json_encode(['alg' => 'none', 'typ' => 'JWT'])) . '.' . b64(json_encode($claims)) . '.';
$r = http('GET', "$BASE/api/orders", ['headers' => ["Authorization: Bearer $none"]]);
check('ID de usuario', 'JWT con alg "none" → 401', $r['status'] === 401);
$claimsAdmin = $claims; $claimsAdmin['role'] = 'service_role';
$r = http('GET', "$BASE/api/admin/users", ['token' => "$h." . b64(json_encode($claimsAdmin)) . ".$s"]);
check('ID de usuario', 'JWT con role=service_role falsificado → 401', $r['status'] === 401);
$expired = $claims; $expired['exp'] = time() - 3600;
$secret = getenv('SUPABASE_JWT_SECRET') ?: 'super-secret-jwt-token-with-at-least-32-characters-long';
$hdr = b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$pl = b64(json_encode($expired));
$r = http('GET', "$BASE/api/orders", ['token' => "$hdr.$pl." . b64(hash_hmac('sha256', "$hdr.$pl", $secret, true))]);
check('ID de usuario', 'JWT expirado → 401 TOKEN_EXPIRADO', $r['status'] === 401 && ($r['json']['error']['code'] ?? '') === 'TOKEN_EXPIRADO');

// ----------------------------------------------------------------------------
echo "\n11. Información administrativa y cuentas bloqueadas\n";
$santiago = login('santiago@firecat.test', $PASS);
$santiagoId = http('GET', "$BASE/api/profile", ['token' => $santiago])['json']['data']['id'];
http('PUT', "$BASE/api/admin/users/$santiagoId", ['token' => $admin, 'json' => ['rol' => 'cliente', 'estado' => 'bloqueado']]);
$r = http('GET', "$BASE/api/orders", ['token' => $santiago]);
check('Bloqueo', 'Usuario bloqueado pierde acceso inmediato (403)', $r['status'] === 403, "HTTP {$r['status']}");
$r = http('POST', "$BASE/api/auth/login", ['json' => ['email' => 'santiago@firecat.test', 'password' => $PASS]]);
check('Bloqueo', 'Usuario bloqueado no puede iniciar sesión', $r['status'] === 403);
http('PUT', "$BASE/api/admin/users/$santiagoId", ['token' => $admin, 'json' => ['rol' => 'cliente', 'estado' => 'activo']]);
$adminId = http('GET', "$BASE/api/profile", ['token' => $admin])['json']['data']['id'];
$r = http('PUT', "$BASE/api/admin/users/$adminId", ['token' => $admin, 'json' => ['rol' => 'cliente', 'estado' => 'activo']]);
check('Roles', 'Un admin no puede quitarse su propio rol', $r['status'] === 403);
$r = http('GET', "$BASE/api/config");
check('Información', 'Configuración pública no expone claves privadas', !isset($r['json']['data']['umbral_stock_bajo']) && !isset($r['json']['data']['zona_horaria']));
$r = http('GET', "$BASE/.env");
check('Información', '.env no es accesible desde el navegador', $r['status'] === 404 && !str_contains($r['body'], 'DATABASE_URL'));
$r = http('GET', "$BASE/../src/bootstrap.php");
check('Información', 'El código fuente no es accesible', !str_contains($r['body'], 'spl_autoload_register'));

// ----------------------------------------------------------------------------
echo "\n12. Subida de archivos\n";
$pid = $product['id'];
foreach ([
    ['shell.php', '<?php echo 1; ?>', 'application/x-php', 'archivo .php'],
    ['shell.jpg', '<?php system($_GET["c"]); ?>', 'image/jpeg', 'PHP con extensión .jpg'],
    ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/svg+xml', 'SVG con JavaScript'],
    ['x.phtml.png', "\x89PNG\r\n\x1a\n<?php ?>", 'image/png', 'PNG falso con código'],
] as [$name, $content, $mime, $label]) {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $content);
    $ch = curl_init("$BASE/api/admin/products/$pid/images");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $admin", 'Accept: application/json'],
        CURLOPT_POSTFIELDS => ['imagen' => new CURLFile($tmp, $mime, $name)]]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    unlink($tmp);
    check('Archivos', "Rechaza $label", $status === 422, "HTTP $status");
}

// ----------------------------------------------------------------------------
echo "\n13. Rate limiting\n";
$victim = 'ratelimit.' . time() . '@firecat.test';
$codes = [];
for ($i = 0; $i < 10; $i++) {
    $codes[] = http('POST', "$BASE/api/auth/login", ['json' => ['email' => $victim, 'password' => 'incorrecta1']])['status'];
}
check('Rate limiting', 'Fuerza bruta de login bloqueada con 429', in_array(429, $codes, true), implode(',', $codes));

// ----------------------------------------------------------------------------
echo "\n14. Redirecciones abiertas\n";
$jar = tempnam(sys_get_temp_dir(), 'fc');
$page = http('GET', "$BASE/login", ['jar' => $jar, 'accept' => 'text/html']);
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
$r = http('POST', "$BASE/login", ['jar' => $jar, 'accept' => 'text/html', 'form' => http_build_query(['email' => 'andres@firecat.test', 'password' => $PASS, '_csrf' => $m[1] ?? '', 'next' => '//evil.example.com/phish'])]);
check('Redirección', 'next=//evil.example.com no redirige fuera del sitio', !str_contains($r['headers'], 'evil.example.com'));
@unlink($jar);

// ----------------------------------------------------------------------------
if ($POSTGREST !== '') {
    echo "\n15. Data API de Supabase (PostgREST) directa con clave pública\n";
    $r = http('GET', "$POSTGREST/pedidos?select=id");
    check('Data API', 'anon no puede leer pedidos', $r['status'] === 401 || $r['status'] === 403 || $r['json'] === []);
    $r = http('GET', "$POSTGREST/profiles?select=email");
    check('Data API', 'anon no puede leer perfiles', in_array($r['status'], [401, 403], true));
    $r = http('GET', "$POSTGREST/pedidos?select=id,usuario_id", ['token' => $camila]);
    $owners = array_unique(array_column($r['json'] ?? [], 'usuario_id'));
    check('Data API', 'Cliente solo recibe sus pedidos', $r['status'] === 200 && count($owners) === 1);
    $r = http('PATCH', "$POSTGREST/productos?id=eq.$pid", ['token' => $camila, 'json' => ['precio' => 1], 'headers' => ['Prefer: return=representation']]);
    $precioActual = http('GET', "$BASE/api/products/$pid")['json']['data']['precio'] ?? null;
    check('Data API', 'Cliente no puede modificar precios (PATCH → 0 filas por RLS)', $r['json'] === [] && $precioActual == $product['precio'], "HTTP {$r['status']}, precio {$precioActual}");
    $r = http('PATCH', "$POSTGREST/variantes_producto?id=eq.{$variant['id']}", ['token' => $camila, 'json' => ['stock' => 999]]);
    check('Data API', 'Cliente no puede modificar stock (PATCH)', in_array($r['status'], [401, 403], true));
    $r = http('PATCH', "$POSTGREST/pedidos?id=eq.$andresOrderId", ['token' => $camila, 'json' => ['total' => 1]]);
    check('Data API', 'Cliente no puede modificar pedidos', in_array($r['status'], [401, 403], true));
    $r = http('POST', "$POSTGREST/rpc/admin_ajustar_inventario", ['token' => $camila, 'json' => ['p_variante_id' => $variant['id'], 'p_cantidad' => 50, 'p_tipo' => 'entrada', 'p_motivo' => 'x']]);
    check('Data API', 'Cliente no puede invocar RPC administrativas', in_array($r['status'], [401, 403], true));
    $r = http('GET', "$POSTGREST/productos?select=id&estado=neq.activo");
    check('Data API', 'anon no ve productos en borrador', $r['status'] === 200 && $r['json'] === []);
}

// ----------------------------------------------------------------------------
$failed = array_filter($results, static fn ($r) => !$r[2]);
printf("\nResultado: %d/%d pruebas de seguridad superadas.\n", count($results) - count($failed), count($results));
if (getenv('REPORT_FILE')) {
    $md = "| # | Categoría | Prueba | Resultado |\n|---|---|---|---|\n";
    foreach ($results as $i => [$cat, $name, $ok, $det]) {
        $md .= sprintf("| %d | %s | %s | %s |\n", $i + 1, $cat, str_replace('|', '\\|', $name), $ok ? '✅ OK' : '❌ FALLO');
    }
    file_put_contents(getenv('REPORT_FILE'), $md);
}
exit($failed ? 1 : 0);
