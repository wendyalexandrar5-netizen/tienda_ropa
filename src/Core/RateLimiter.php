<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiting de ventana fija persistido en PostgreSQL
 * (app_private.rate_limit_hit), compartido entre instancias del backend.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /**
     * @throws HttpException 429 si se excede el límite
     */
    public function hit(string $action, string $identifier, int $max, int $windowSeconds): void
    {
        if (!$this->config->rateLimitEnabled) {
            return;
        }
        $key = $action . ':' . hash('sha256', $identifier);

        $allowed = $this->db->asSystem(fn (Database $db) => $db->fetchValue(
            'select app_private.rate_limit_hit(:clave, :max, :ventana)',
            ['clave' => $key, 'max' => $max, 'ventana' => $windowSeconds],
        ));

        if ($allowed !== true && $allowed !== 't') {
            throw HttpException::tooManyRequests();
        }
    }
}
