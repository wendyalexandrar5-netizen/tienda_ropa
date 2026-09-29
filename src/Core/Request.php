<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Petición HTTP entrante (inmutable salvo por los parámetros de ruta).
 */
final class Request
{
    /** @var array<string, string> */
    public array $routeParams = [];
    private ?array $jsonBody = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $rawBody,
        public readonly string $ip,
        public readonly bool $secure,
    ) {
    }

    public static function fromGlobals(Config $config): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Formularios HTML: permite PUT/DELETE mediante campo oculto _method.
        if ($method === 'POST' && isset($_POST['_method']) && in_array(strtoupper((string) $_POST['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $_POST['_method']);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($config->trustProxy && isset($headers['x-forwarded-for'])) {
            $candidate = trim(explode(',', $headers['x-forwarded-for'])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($config->trustProxy && ($headers['x-forwarded-proto'] ?? '') === 'https');

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $headers,
            $_COOKIE,
            (string) file_get_contents('php://input'),
            $ip,
            $secure,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type') ?? ''), 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($this->header('accept') ?? '', 'application/json');
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization') ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+)$/', trim($auth), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Cuerpo de la petición: JSON si Content-Type es application/json, en
     * otro caso los campos del formulario.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        if ($this->isJson()) {
            if ($this->jsonBody === null) {
                if ($this->rawBody === '') {
                    $this->jsonBody = [];
                } else {
                    try {
                        $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new HttpException(400, 'JSON_INVALIDO', 'El cuerpo de la petición no es JSON válido.');
                    }
                    if (!is_array($decoded)) {
                        throw new HttpException(400, 'JSON_INVALIDO', 'Se esperaba un objeto JSON.');
                    }
                    $this->jsonBody = $decoded;
                }
            }
            return $this->jsonBody;
        }
        $data = $this->post;
        unset($data['_csrf'], $data['_method']);
        return $data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input()[$key] ?? $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function param(string $key): string
    {
        return $this->routeParams[$key] ?? '';
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
