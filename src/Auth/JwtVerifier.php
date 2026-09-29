<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\HttpClient;
use App\Core\HttpException;

/**
 * Verificación de los access tokens (JWT) emitidos por Supabase Auth.
 *
 * Soporta los dos esquemas de firma de Supabase:
 *   * Claves asimétricas (ES256 / RS256) – recomendado. La clave pública se
 *     obtiene del endpoint JWKS del proyecto y se guarda en caché.
 *   * Secreto compartido HS256 (legacy JWT secret).
 *
 * Rechaza "alg: none", algoritmos no permitidos, tokens expirados, audiencia
 * distinta de "authenticated" y tokens sin "sub".
 */
final class JwtVerifier
{
    private const LEEWAY = 30;
    private const JWKS_TTL = 600;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly string $cacheDir,
    ) {
    }

    /** @return array<string, mixed> claims verificados */
    public function verify(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw $this->invalid();
        }
        [$h64, $p64, $s64] = $parts;

        $header = json_decode(self::b64urlDecode($h64), true);
        $payload = json_decode(self::b64urlDecode($p64), true);
        $signature = self::b64urlDecode($s64);
        if (!is_array($header) || !is_array($payload) || $signature === '') {
            throw $this->invalid();
        }

        $alg = $header['alg'] ?? '';
        $signed = $h64 . '.' . $p64;

        $valid = match ($alg) {
            'HS256' => $this->verifyHs256($signed, $signature),
            'ES256', 'RS256' => $this->verifyAsymmetric($alg, (string) ($header['kid'] ?? ''), $signed, $signature),
            default => false,
        };
        if (!$valid) {
            throw $this->invalid();
        }

        $now = time();
        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int) $payload['exp'] < $now - self::LEEWAY) {
            throw new HttpException(401, 'TOKEN_EXPIRADO', 'La sesión expiró. Inicia sesión nuevamente.');
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > $now + self::LEEWAY) {
            throw $this->invalid();
        }
        $aud = $payload['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array('authenticated', $audiences, true) || ($payload['role'] ?? '') !== 'authenticated') {
            throw $this->invalid();
        }
        if (!is_string($payload['sub'] ?? null) || !preg_match('/^[0-9a-f-]{36}$/i', $payload['sub'])) {
            throw $this->invalid();
        }

        return $payload;
    }

    private function verifyHs256(string $signed, string $signature): bool
    {
        if ($this->config->supabaseJwtSecret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $signed, $this->config->supabaseJwtSecret, true), $signature);
    }

    private function verifyAsymmetric(string $alg, string $kid, string $signed, string $signature): bool
    {
        $jwk = $this->findKey($kid, false) ?? $this->findKey($kid, true);
        if ($jwk === null) {
            return false;
        }
        $expectedKty = $alg === 'ES256' ? 'EC' : 'RSA';
        if (($jwk['kty'] ?? '') !== $expectedKty) {
            return false;
        }

        $pem = $alg === 'ES256' ? self::ecJwkToPem($jwk) : self::rsaJwkToPem($jwk);
        if ($pem === null) {
            return false;
        }
        if ($alg === 'ES256') {
            if (strlen($signature) !== 64) {
                return false;
            }
            $signature = self::rawEcdsaToDer($signature);
        }
        $key = openssl_pkey_get_public($pem);
        return $key !== false && openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return array<string, mixed>|null */
    private function findKey(string $kid, bool $forceRefresh): ?array
    {
        foreach ($this->jwks($forceRefresh) as $key) {
            if (is_array($key) && ($kid === '' || ($key['kid'] ?? '') === $kid)) {
                return $key;
            }
        }
        return null;
    }

    /** @return list<mixed> */
    private function jwks(bool $forceRefresh): array
    {
        static $memory = null;
        if ($memory !== null && !$forceRefresh) {
            return $memory;
        }
        if ($this->config->supabaseJwksUrl === '') {
            return [];
        }

        $cacheFile = $this->cacheDir . '/jwks-' . md5($this->config->supabaseJwksUrl) . '.json';
        if (!$forceRefresh && is_file($cacheFile) && filemtime($cacheFile) > time() - self::JWKS_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $memory = array_values($cached);
            }
        }

        // Evita consultar el JWKS en bucle ante tokens con kid desconocido.
        if ($forceRefresh && is_file($cacheFile) && filemtime($cacheFile) > time() - 30) {
            return $memory ?? [];
        }

        try {
            $res = $this->http->request('GET', $this->config->supabaseJwksUrl, ['apikey' => $this->config->supabasePublishableKey], null, 5);
        } catch (HttpException) {
            return $memory ?? [];
        }
        $keys = is_array($res['json']['keys'] ?? null) ? array_values($res['json']['keys']) : [];
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0770, true);
        }
        @file_put_contents($cacheFile, json_encode($keys), LOCK_EX);
        return $memory = $keys;
    }

    private function invalid(): HttpException
    {
        return new HttpException(401, 'TOKEN_INVALIDO', 'Token de acceso inválido.');
    }

    // ------------------------------------------------------------------
    // Utilidades criptográficas (JWK → PEM, firma ECDSA raw → DER)
    // ------------------------------------------------------------------

    public static function b64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }

    public static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $jwk */
    private static function ecJwkToPem(array $jwk): ?string
    {
        if (($jwk['crv'] ?? '') !== 'P-256') {
            return null;
        }
        $x = self::b64urlDecode((string) ($jwk['x'] ?? ''));
        $y = self::b64urlDecode((string) ($jwk['y'] ?? ''));
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        // SubjectPublicKeyInfo para id-ecPublicKey + prime256v1
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** @param array<string, mixed> $jwk */
    private static function rsaJwkToPem(array $jwk): ?string
    {
        $n = self::b64urlDecode((string) ($jwk['n'] ?? ''));
        $e = self::b64urlDecode((string) ($jwk['e'] ?? ''));
        if ($n === '' || $e === '') {
            return null;
        }
        $rsaKey = self::asn1(0x30, self::asn1Int($n) . self::asn1Int($e));
        $algo = hex2bin('300d06092a864886f70d0101010500');
        $der = self::asn1(0x30, $algo . self::asn1(0x03, "\x00" . $rsaKey));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function rawEcdsaToDer(string $raw): string
    {
        $r = substr($raw, 0, 32);
        $s = substr($raw, 32, 32);
        return self::asn1(0x30, self::asn1Int($r) . self::asn1Int($s));
    }

    private static function asn1Int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return self::asn1(0x02, $bytes);
    }

    private static function asn1(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) {
            $lenBytes = chr($len);
        } else {
            $tmp = ltrim(pack('N', $len), "\x00");
            $lenBytes = chr(0x80 | strlen($tmp)) . $tmp;
        }
        return chr($tag) . $lenBytes . $content;
    }
}
