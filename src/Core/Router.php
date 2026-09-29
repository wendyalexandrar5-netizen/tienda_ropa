<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador simple con parámetros ({id}, {slug}) y middlewares declarativos:
 *   'auth'  → requiere sesión/token válido
 *   'admin' → requiere rol administrador (verificado en backend + RLS)
 *   'guest' → solo visitantes sin sesión (login, registro)
 */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, rtrim($pattern, '/') ?: '/');

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @param list<string> $mw */
    public function get(string $p, array $h, array $mw = []): void { $this->add('GET', $p, $h, $mw); }
    /** @param list<string> $mw */
    public function post(string $p, array $h, array $mw = []): void { $this->add('POST', $p, $h, $mw); }
    /** @param list<string> $mw */
    public function put(string $p, array $h, array $mw = []): void { $this->add('PUT', $p, $h, $mw); }
    /** @param list<string> $mw */
    public function delete(string $p, array $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    /**
     * @return array{handler: array{0: class-string, 1: string}, middleware: list<string>, params: array<string, string>}|null
     *         null si no hay ruta; lanza 405 si la ruta existe con otro método.
     */
    public function match(string $method, string $path): ?array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            array_shift($m);
            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => array_combine($route['params'], $m) ?: [],
            ];
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'METODO_NO_PERMITIDO', 'Método HTTP no permitido para este recurso.', ['permitidos' => array_values(array_unique($allowed))]);
        }
        return null;
    }
}
