<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuración tipada de la aplicación (solo lectura), construida a partir
 * de variables de entorno. Ninguna credencial se escribe en el código.
 */
final class Config
{
    public function __construct(
        public readonly string $appEnv,
        public readonly bool $debug,
        public readonly string $appUrl,
        public readonly string $appName,
        public readonly string $databaseUrl,
        public readonly bool $dbEmulatePrepares,
        public readonly string $supabaseUrl,
        public readonly string $supabaseAuthUrl,
        public readonly string $supabasePublishableKey,
        public readonly string $supabaseSecretKey,
        public readonly string $supabaseJwtSecret,
        public readonly string $supabaseJwksUrl,
        public readonly string $storageDriver,
        public readonly string $storageBucket,
        public readonly int $uploadMaxBytes,
        public readonly bool $cookieSecure,
        public readonly int $sessionLifetime,
        /** @var list<string> */
        public readonly array $corsOrigins,
        public readonly bool $rateLimitEnabled,
        public readonly bool $trustProxy,
    ) {
    }

    public static function fromEnv(): self
    {
        $appUrl = rtrim(Env::get('APP_URL', 'http://127.0.0.1:8080'), '/');
        $supabaseUrl = rtrim(Env::get('SUPABASE_URL', ''), '/');
        $authUrl = rtrim(Env::get('SUPABASE_AUTH_URL', $supabaseUrl !== '' ? $supabaseUrl . '/auth/v1' : ''), '/');

        return new self(
            appEnv: Env::get('APP_ENV', 'production'),
            debug: Env::bool('APP_DEBUG', false),
            appUrl: $appUrl,
            appName: Env::get('APP_NAME', 'FIRE CAT'),
            databaseUrl: Env::get('DATABASE_URL', ''),
            dbEmulatePrepares: Env::bool('DB_EMULATE_PREPARES', false),
            supabaseUrl: $supabaseUrl,
            supabaseAuthUrl: $authUrl,
            supabasePublishableKey: Env::get('SUPABASE_PUBLISHABLE_KEY', ''),
            supabaseSecretKey: Env::get('SUPABASE_SECRET_KEY', ''),
            supabaseJwtSecret: Env::get('SUPABASE_JWT_SECRET', ''),
            supabaseJwksUrl: Env::get('SUPABASE_JWKS_URL', $authUrl !== '' ? $authUrl . '/.well-known/jwks.json' : ''),
            storageDriver: Env::get('STORAGE_DRIVER', 'supabase'),
            storageBucket: Env::get('STORAGE_BUCKET', 'productos'),
            uploadMaxBytes: Env::int('UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
            cookieSecure: Env::bool('COOKIE_SECURE', str_starts_with($appUrl, 'https://')),
            sessionLifetime: Env::int('SESSION_LIFETIME', 60 * 60 * 24 * 7),
            corsOrigins: array_values(array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS', '')))))
                ?: [],
            rateLimitEnabled: Env::bool('RATE_LIMIT_ENABLED', true),
            trustProxy: Env::bool('TRUST_PROXY', false),
        );
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }
}
