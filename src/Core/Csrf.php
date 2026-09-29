<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Protección CSRF (patrón "synchronizer token").
 * Aplica a toda petición que modifica estado y se autentica con la cookie de
 * sesión. Las peticiones con "Authorization: Bearer" (apps móviles) no usan
 * cookies y por tanto no son vulnerables a CSRF.
 */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    public function validate(Request $request): void
    {
        $sent = $request->header('x-csrf-token') ?? ($request->post['_csrf'] ?? null);
        $expected = $this->session->get('_csrf');

        if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419, 'CSRF_INVALIDO', 'La sesión del formulario expiró o no es válida. Recarga la página e inténtalo de nuevo.');
        }
    }
}
