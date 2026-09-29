<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Autenticación para clientes de la API (apps móviles, otros frontends).
 * Devuelve los tokens de Supabase Auth; el cliente los envía después en
 * "Authorization: Bearer <access_token>".
 */
final class AuthApiController extends Controller
{
    public function register(Request $request): Response
    {
        $result = $this->c->auth()->register($request->input(), $request, false);
        if ($result['requires_confirmation']) {
            return $this->ok([
                'requiere_confirmacion' => true,
                'mensaje' => 'Te enviamos un correo para confirmar tu cuenta.',
            ], 201);
        }
        return $this->ok($this->payload($result), 201);
    }

    public function login(Request $request): Response
    {
        $v = new Validator($request->input());
        $email = $v->email('email');
        $password = $v->password('password', false);
        $v->check();

        $result = $this->c->auth()->login((string) $email, (string) $password, $request, false);
        return $this->ok($this->payload($result));
    }

    public function refresh(Request $request): Response
    {
        $token = $request->get('refresh_token');
        if (!is_string($token) || $token === '' || strlen($token) > 512) {
            throw HttpException::validation(['refresh_token' => 'El refresh_token es obligatorio.']);
        }
        $this->c->limiter()->hit('refresh-ip', $request->ip, 60, 600);
        return $this->ok($this->payload($this->c->auth()->refresh($token)));
    }

    public function logout(Request $request): Response
    {
        $this->c->auth()->logout($this->c->user);
        return $this->ok(['mensaje' => 'Sesión cerrada.']);
    }

    public function recover(Request $request): Response
    {
        $v = new Validator($request->input());
        $email = $v->email('email');
        $v->check();
        $this->c->auth()->requestPasswordReset((string) $email, $request);
        return $this->ok(['mensaje' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.']);
    }

    public function me(Request $request): Response
    {
        return $this->ok($this->c->account()->profile());
    }

    /** @param array{user: \App\Auth\CurrentUser, session: array<string, mixed>} $result */
    private function payload(array $result): array
    {
        return [
            'user' => $result['user']->toArray(),
            'session' => $result['session'],
        ];
    }
}
