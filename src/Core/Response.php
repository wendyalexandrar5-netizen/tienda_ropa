<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Respuesta HTTP. Formato JSON estándar de la API:
 *   éxito: {"success": true,  "data": ..., "meta": {...}}
 *   error: {"success": false, "error": {"code": "...", "message": "...", "details": {...}}}
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    /** @param array<string, mixed>|null $meta */
    public static function success(mixed $data = null, int $status = 200, ?array $meta = null): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return self::json(['success' => false, 'error' => $error], $status);
    }

    public static function json(mixed $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Solo redirecciones internas (evita open redirect).
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || str_contains($location, '\\')) {
            $location = '/';
        }
        return new self('', $status, ['Location' => $location]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
