<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Login, registro, cierre de sesión y recuperación de contraseña de la
 * tienda web. Reemplaza ingresar.html / registrar.html, que guardaban la
 * contraseña en texto plano en archivos JSON y la comparaban en el navegador.
 */
final class AuthController extends Controller
{
    public function loginForm(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Iniciar sesión', 'next' => $this->next($request->queryString('next')), 'hideFooter' => true]);
    }

    public function login(Request $request): Response
    {
        $v = new Validator($request->post);
        $email = $v->email('email');
        $password = $v->password('password', false);
        $v->check();

        try {
            $result = $this->c->auth()->login((string) $email, (string) $password, $request, true);
        } catch (HttpException $e) {
            if (in_array($e->status, [401, 403], true)) {
                $this->c->session()->flashOld(['email' => $email]);
                $next = $this->next((string) ($request->post['next'] ?? ''));
                return $this->redirect('/login' . ($next !== null ? '?next=' . rawurlencode($next) : ''), 'danger', $e->getMessage());
            }
            throw $e;
        }

        $user = $result['user'];
        $next = $this->next((string) ($request->post['next'] ?? ''));
        $default = $user->isAdmin() ? '/admin' : '/';
        return $this->redirect($next ?? $default, 'success', '¡Hola, ' . $user->displayName() . '! Iniciaste sesión correctamente.');
    }

    public function registerForm(Request $request): Response
    {
        return $this->view('auth/register', ['title' => 'Crear cuenta', 'hideFooter' => true]);
    }

    public function register(Request $request): Response
    {
        $input = $request->post;
        $input['acepta_terminos'] = $input['acepta_terminos'] ?? '0';
        $result = $this->c->auth()->register($input, $request, true);

        if ($result['requires_confirmation']) {
            return $this->redirect('/login', 'success', 'Cuenta creada. Revisa tu correo para confirmarla y luego inicia sesión.');
        }
        return $this->redirect('/', 'success', '¡Bienvenido a FIRE CAT! Tu cuenta fue creada.');
    }

    public function logout(Request $request): Response
    {
        $this->c->auth()->logout($this->c->user);
        $this->c->session()->start();
        return $this->redirect('/', 'success', 'Cerraste sesión. ¡Vuelve pronto!');
    }

    public function forgotForm(Request $request): Response
    {
        return $this->view('auth/forgot', ['title' => 'Recuperar contraseña', 'hideFooter' => true]);
    }

    public function forgot(Request $request): Response
    {
        $v = new Validator($request->post);
        $email = $v->email('email');
        $v->check();
        $this->c->auth()->requestPasswordReset((string) $email, $request);
        return $this->redirect('/recuperar', 'success', 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.');
    }

    /**
     * Destino del enlace del correo de recuperación. Soporta:
     *   ?token_hash=...&type=recovery      (plantilla recomendada para SSR)
     *   #access_token=...&type=recovery     (plantilla por defecto; lo procesa JS)
     */
    public function resetForm(Request $request): Response
    {
        $tokenHash = $request->queryString('token_hash');
        if ($tokenHash !== '' && $request->queryString('type', 'recovery') === 'recovery') {
            if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $tokenHash)) {
                return $this->redirect('/recuperar', 'danger', 'El enlace no es válido.');
            }
            try {
                $this->c->auth()->startRecoveryFromTokenHash($tokenHash);
            } catch (HttpException $e) {
                return $this->redirect('/recuperar', 'danger', 'El enlace no es válido o expiró. Solicita uno nuevo.');
            }
            return $this->redirect('/restablecer');
        }

        $ready = $this->c->user !== null && $this->c->auth()->isRecoveryPending();
        return $this->view('auth/reset', [
            'title' => 'Nueva contraseña',
            'ready' => $ready,
            'error' => $request->queryString('error_description'),
            'hideFooter' => true,
        ]);
    }

    public function resetSession(Request $request): Response
    {
        $access = $request->get('access_token');
        $refresh = $request->get('refresh_token');
        if (!is_string($access) || !is_string($refresh) || strlen($access) > 4096 || strlen($refresh) > 512) {
            throw new HttpException(422, 'ENLACE_INVALIDO', 'El enlace no es válido.');
        }
        $this->c->limiter()->hit('reset-session', $request->ip, 10, 900);
        $this->c->auth()->startRecoveryFromTokens($access, $refresh);
        return $this->ok(['ok' => true]);
    }

    public function reset(Request $request): Response
    {
        if (!$this->c->auth()->isRecoveryPending()) {
            return $this->redirect('/cuenta', 'info', 'Para cambiar tu contraseña usa la sección de seguridad de tu perfil.');
        }
        $v = new Validator($request->post);
        $password = $v->password('password');
        if (($request->post['password_confirmacion'] ?? null) !== $password) {
            $v->addError('password_confirmacion', 'Las contraseñas no coinciden.');
        }
        $v->check();
        $this->c->auth()->completeRecovery($this->user(), (string) $password);
        return $this->redirect('/cuenta', 'success', 'Tu contraseña fue actualizada.');
    }

    /** Solo rutas internas (previene open redirect). */
    private function next(string $next): ?string
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&.%]*$#', $next) && !str_starts_with($next, '/login') ? $next : null;
    }
}
