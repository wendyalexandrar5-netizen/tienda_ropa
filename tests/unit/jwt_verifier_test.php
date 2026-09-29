<?php

declare(strict_types=1);

/*
 * Pruebas unitarias de App\Auth\JwtVerifier (sin red ni base de datos).
 * Cubre los esquemas de firma de Supabase Auth: ES256 / RS256 (claves
 * asimétricas vía JWKS) y HS256 (secreto legacy), y ataques comunes.
 *   php tests/unit/jwt_verifier_test.php
 */

define('APP_ROOT', dirname(__DIR__, 2));
spl_autoload_register(static function (string $class): void {
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (str_starts_with($class, 'App\\') && is_file($path)) {
        require $path;
    }
});

use App\Auth\JwtVerifier;
use App\Core\Config;
use App\Core\HttpClient;
use App\Core\HttpException;

$b64 = static fn (string $s): string => JwtVerifier::b64urlEncode($s);
$cacheDir = sys_get_temp_dir() . '/fc-jwt-test-' . getmypid();
@mkdir($cacheDir);
$jwksUrl = 'https://example.invalid/auth/v1/.well-known/jwks.json';
$secret = 'test-secret-with-at-least-32-characters!!';

// --- Claves de prueba ---------------------------------------------------
$ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ecd = openssl_pkey_get_details($ec)['ec'];
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$rsad = openssl_pkey_get_details($rsa)['rsa'];
$ecOther = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

file_put_contents($cacheDir . '/jwks-' . md5($jwksUrl) . '.json', json_encode([
    ['kty' => 'EC', 'crv' => 'P-256', 'kid' => 'ec-1', 'x' => $b64(str_pad($ecd['x'], 32, "\0", STR_PAD_LEFT)), 'y' => $b64(str_pad($ecd['y'], 32, "\0", STR_PAD_LEFT)), 'alg' => 'ES256'],
    ['kty' => 'RSA', 'kid' => 'rsa-1', 'n' => $b64($rsad['n']), 'e' => $b64($rsad['e']), 'alg' => 'RS256'],
]));

$config = new Config('testing', false, 'http://localhost', 'FC', '', false, 'https://example.invalid', 'https://example.invalid/auth/v1',
    'pk', '', $secret, $jwksUrl, 'local', 'productos', 1, false, 3600, [], false, false);
$verifier = new JwtVerifier($config, new HttpClient(), $cacheDir);

$claims = static fn (array $over = []) => $over + [
    'sub' => '8a1f6f0e-1b2c-4d5e-8f90-1234567890ab', 'aud' => 'authenticated', 'role' => 'authenticated',
    'email' => 'x@firecat.test', 'exp' => time() + 600, 'iat' => time(),
];

$sign = static function (string $alg, array $payload, $key, string $kid = '') use ($b64, $secret): string {
    $header = ['alg' => $alg, 'typ' => 'JWT'] + ($kid !== '' ? ['kid' => $kid] : []);
    $input = $b64(json_encode($header)) . '.' . $b64(json_encode($payload));
    if ($alg === 'HS256') {
        $sig = hash_hmac('sha256', $input, $key ?? $secret, true);
    } else {
        openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
        $sig = $der;
        if ($alg === 'ES256') {           // DER → R||S (formato JWS)
            $offset = 3;
            $rLen = ord($der[$offset]); $r = substr($der, $offset + 1, $rLen);
            $offset += 1 + $rLen + 1;
            $sLen = ord($der[$offset]); $s = substr($der, $offset + 1, $sLen);
            $sig = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        }
    }
    return $input . '.' . $b64($sig);
};

$pass = 0; $fail = 0;
$expectValid = static function (string $name, string $jwt) use ($verifier, &$pass, &$fail): void {
    try { $verifier->verify($jwt); echo "  ✔ $name\n"; $pass++; }
    catch (HttpException $e) { echo "  ✘ $name → {$e->errorCode}\n"; $fail++; }
};
$expectInvalid = static function (string $name, string $jwt, string $code = '') use ($verifier, &$pass, &$fail): void {
    try { $verifier->verify($jwt); echo "  ✘ $name (fue aceptado)\n"; $fail++; }
    catch (HttpException $e) {
        $ok = $e->status === 401 && ($code === '' || $e->errorCode === $code);
        echo ($ok ? '  ✔ ' : '  ✘ ') . "$name → {$e->errorCode}\n";
        $ok ? $pass++ : $fail++;
    }
};

echo "JwtVerifier\n";
$expectValid('ES256 firmado con la clave del JWKS', $sign('ES256', $claims(), $ec, 'ec-1'));
$expectValid('RS256 firmado con la clave del JWKS', $sign('RS256', $claims(), $rsa, 'rsa-1'));
$expectValid('HS256 con el JWT secret legacy', $sign('HS256', $claims(), null));
$expectInvalid('ES256 firmado con otra clave', $sign('ES256', $claims(), $ecOther, 'ec-1'), 'TOKEN_INVALIDO');
$tampered = explode('.', $sign('ES256', $claims(), $ec, 'ec-1'));
$tampered[1] = $b64(json_encode($claims(['sub' => '00000000-0000-0000-0000-000000000000'])));
$expectInvalid('Payload modificado tras la firma', implode('.', $tampered), 'TOKEN_INVALIDO');
$expectInvalid('alg=none', $b64('{"alg":"none"}') . '.' . $b64(json_encode($claims())) . '.', 'TOKEN_INVALIDO');
$expectInvalid('HS256 con secreto incorrecto', $sign('HS256', $claims(), 'otro-secreto'), 'TOKEN_INVALIDO');
$expectInvalid('Confusión de algoritmo (HS256 usando la clave pública como secreto)', $sign('HS256', $claims(), openssl_pkey_get_details($rsa)['key']), 'TOKEN_INVALIDO');
$expectInvalid('Token expirado', $sign('ES256', $claims(['exp' => time() - 3600]), $ec, 'ec-1'), 'TOKEN_EXPIRADO');
$expectInvalid('Audiencia distinta', $sign('ES256', $claims(['aud' => 'otra-app']), $ec, 'ec-1'), 'TOKEN_INVALIDO');
$expectInvalid('Rol anon/service_role en lugar de authenticated', $sign('ES256', $claims(['role' => 'service_role']), $ec, 'ec-1'), 'TOKEN_INVALIDO');
$expectInvalid('Sin sub', $sign('ES256', array_diff_key($claims(), ['sub' => 1]), $ec, 'ec-1'), 'TOKEN_INVALIDO');
$expectInvalid('kid desconocido', $sign('ES256', $claims(), $ec, 'no-existe'), 'TOKEN_INVALIDO');
$expectInvalid('Formato basura', 'abc.def', 'TOKEN_INVALIDO');

array_map('unlink', glob("$cacheDir/*"));
@rmdir($cacheDir);
printf("\n%d/%d pruebas superadas.\n", $pass, $pass + $fail);
exit($fail ? 1 : 0);
