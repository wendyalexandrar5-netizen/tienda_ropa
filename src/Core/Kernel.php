<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Núcleo HTTP: aplica cabeceras de seguridad, CORS, identifica al usuario,
 * verifica CSRF, ejecuta middlewares de autorización y despacha la ruta.
 */
final class Kernel
{
    public function __construct(
        private readonly Container $c,
        private readonly Router $router,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->renderError($request, $e);
        } catch (Throwable $e) {
            error_log('[app] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $message = $this->c->config->debug ? $e->getMessage() : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
            $response = $this->renderError($request, new HttpException(500, 'ERROR_INTERNO', $message));
        }
        return $this->withSecurityHeaders($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        if ($request->isApi()) {
            $cors = $this->cors($request);
            if ($request->method === 'OPTIONS') {
                return $cors ?? Response::noContent();
            }
        }

        // La tienda web siempre usa sesión (CSRF, flash). La API solo si el
        // navegador ya trae la cookie; los clientes móviles no crean sesiones.
        $session = $this->c->session();
        $usesSession = !$request->isApi() || ($session->hasCookie($request) && $request->bearerToken() === null);
        if ($usesSession) {
            $session->start();
        }

        $route = $this->router->match($request->method, $request->path);
        if ($route === null) {
            throw HttpException::notFound($request->isApi() ? 'Endpoint no encontrado.' : 'La página que buscas no existe.');
        }
        $request->routeParams = $route['params'];

        $user = $this->c->auth()->resolve($request);
        $this->c->user = $user;
        $this->c->db()->setUser($user);

        // CSRF: toda petición que modifica estado autenticada por cookie.
        if (!$request->isSafeMethod() && $usesSession && $user?->via !== 'bearer') {
            $this->c->csrf()->validate($request);
        }

        foreach ($route['middleware'] as $mw) {
            $blocked = match ($mw) {
                'auth' => $user === null ? $this->unauthenticated($request) : null,
                'admin' => $user === null ? $this->unauthenticated($request) : (!$user->isAdmin() ? throw HttpException::forbidden('Esta sección es solo para administradores.') : null),
                'guest' => $user !== null ? Response::redirect($user->isAdmin() ? '/admin' : '/cuenta') : null,
                default => null,
            };
            if ($blocked instanceof Response) {
                return $blocked;
            }
        }

        [$class, $method] = $route['handler'];
        $controller = new $class($this->c);
        $response = $controller->$method($request);

        if (isset($cors)) {
            foreach ($cors->headers as $k => $v) {
                $response->withHeader($k, $v);
            }
        }
        return $response;
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->wantsJson()) {
            throw HttpException::unauthorized();
        }
        $this->c->session()->flash('info', 'Inicia sesión para continuar.');
        return Response::redirect('/login?next=' . rawurlencode($request->path));
    }

    private function cors(Request $request): ?Response
    {
        $origin = $request->header('origin');
        if ($origin === null || !in_array($origin, $this->c->config->corsOrigins, true)) {
            return null;
        }
        // Solo se permite Authorization (Bearer); nunca cookies entre orígenes.
        return (new Response('', 204))
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
            ->withHeader('Access-Control-Max-Age', '600');
    }

    private function renderError(Request $request, HttpException $e): Response
    {
        if ($e->status >= 500) {
            error_log("[http] {$e->status} {$e->errorCode}: {$e->getMessage()}");
        }
        if ($request->wantsJson()) {
            return Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details);
        }

        // Formularios web: errores de CSRF/validación vuelven a la página anterior.
        if (in_array($e->status, [419, 422, 409, 429], true) && !$request->isSafeMethod()) {
            $this->c->session()->start();
            $this->c->session()->flash('danger', $e->getMessage());
            if (isset($e->details['campos'])) {
                $this->c->session()->set('_errors', $e->details['campos']);
            }
            $this->c->session()->flashOld($request->post);
            return Response::redirect($this->backUrl($request));
        }

        $view = $this->c->view();
        $template = match ($e->status) {
            403 => 'errors/403',
            404, 405 => 'errors/404',
            default => 'errors/500',
        };
        try {
            $this->c->shareViewGlobals();
            $html = $view->render($template, ['title' => 'Error', 'message' => $e->getMessage(), 'status' => $e->status], 'shop');
        } catch (Throwable) {
            $html = '<h1>Error ' . $e->status . '</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        return Response::html($html, $e->status);
    }

    /**
     * Página a la que se vuelve tras un error en un formulario web:
     *   1) campo oculto _back (lo añade csrf_field(), no depende del Referer)
     *   2) cabecera Referer del mismo sitio
     *   3) la página de inicio
     * Solo se aceptan rutas internas.
     */
    private function backUrl(Request $request): string
    {
        $isInternal = static fn (string $p): bool => (bool) preg_match('#^/(?![/\\\\])[^\s]*$#', $p);

        $back = $request->post['_back'] ?? null;
        if (is_string($back) && $back !== '' && $isInternal($back)) {
            return $back;
        }

        $referer = $request->header('referer') ?? '';
        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $host = parse_url($referer, PHP_URL_HOST);
            $sameHost = $host === null
                || $host === parse_url($this->c->config->appUrl, PHP_URL_HOST)
                || $host === explode(':', (string) $request->header('host'))[0];
            if (is_string($path) && $path !== '' && $sameHost && $isInternal($path)) {
                $query = parse_url($referer, PHP_URL_QUERY);
                return $path . (is_string($query) ? '?' . $query : '');
            }
        }
        return '/';
    }

    private function withSecurityHeaders(Request $request, Response $response): Response
    {
        $supabase = $this->c->config->supabaseUrl;
        $imgSrc = "'self' data: blob:" . ($supabase !== '' ? ' ' . $supabase : '') . ' https://*.supabase.co';

        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');

        if (!$request->isApi()) {
            $response->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src $imgSrc",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
            ]));
            if (!isset($response->headers['Cache-Control'])) {
                $response->withHeader('Cache-Control', 'no-store, private');
            }
        } else {
            $response->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if ($request->secure || $this->c->config->cookieSecure) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
