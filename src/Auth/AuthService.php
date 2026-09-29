<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;

/**
 * Casos de uso de autenticación sobre Supabase Auth, compartidos por la
 * tienda web (sesión PHP) y por clientes de la API (Bearer token).
 */
final class AuthService
{
    private const SESSION_KEY = 'auth';

    public function __construct(
        private readonly Config $config,
        private readonly SupabaseAuthClient $client,
        private readonly JwtVerifier $jwt,
        private readonly Database $db,
        private readonly Session $session,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * Identifica al usuario de la petición:
     *   1) Authorization: Bearer <access_token>   (app móvil / API)
     *   2) Sesión PHP de la tienda web (refresca el token si está por expirar)
     */
    public function resolve(Request $request): ?CurrentUser
    {
        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            $claims = $this->jwt->verify($bearer);
            $user = $this->makeUser($claims, $bearer, 'bearer');
            $this->loadProfile($user);
            if (!$user->isActive()) {
                throw new HttpException(403, 'USUARIO_BLOQUEADO', 'Tu cuenta está bloqueada. Contacta a la tienda.');
            }
            return $user;
        }

        if ($request->header('authorization') !== null && $request->isApi()) {
            throw new HttpException(401, 'TOKEN_INVALIDO', 'Formato de Authorization inválido (se espera Bearer <token>).');
        }

        if (!$this->session->isStarted()) {
            return null;   // cliente de API sin cookie de sesión
        }
        $auth = $this->session->get(self::SESSION_KEY);
        if (!is_array($auth) || empty($auth['access_token'])) {
            return null;
        }

        try {
            if ((int) ($auth['expires_at'] ?? 0) - 60 <= time()) {
                $auth = $this->storeSession($this->client->refresh((string) $auth['refresh_token']));
            }
            $claims = $this->jwt->verify((string) $auth['access_token']);
        } catch (HttpException $e) {
            if ($e->status >= 500) {
                throw $e;
            }
            $this->session->remove(self::SESSION_KEY);
            return null;
        }

        $user = $this->makeUser($claims, (string) $auth['access_token'], 'session');
        $this->loadProfile($user);
        if (!$user->isActive()) {
            $this->logout($user);
            $this->session->start();
            $this->session->flash('danger', 'Tu cuenta está bloqueada. Contacta a la tienda.');
            return null;
        }
        return $user;
    }

    /** @return array{user: CurrentUser, session: array<string, mixed>} */
    public function login(string $email, string $password, Request $request, bool $web): array
    {
        $this->limiter->hit('login-ip', $request->ip, 30, 900);
        $this->limiter->hit('login-email', $email, 8, 900);

        $session = $this->client->signInWithPassword($email, $password);
        return $this->startSession($session, $web);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{requires_confirmation: bool, user: ?CurrentUser, session: ?array<string, mixed>}
     */
    public function register(array $input, Request $request, bool $web): array
    {
        $v = new Validator($input);
        $nombre = $v->string('nombre', true, 2, 80, "/^[\\p{L}' .-]+$/u", true, 'nombre');
        $apellido = $v->string('apellido', true, 2, 80, "/^[\\p{L}' .-]+$/u", true, 'apellido');
        $email = $v->email('email');
        $telefono = $v->string('telefono', false, 7, 20, '/^[0-9+() -]+$/', true, 'teléfono');
        $password = $v->password('password');
        if (($input['password_confirmacion'] ?? null) !== null && $input['password_confirmacion'] !== $password) {
            $v->addError('password_confirmacion', 'Las contraseñas no coinciden.');
        }
        if (array_key_exists('acepta_terminos', $input) && !$v->bool('acepta_terminos')) {
            $v->addError('acepta_terminos', 'Debes aceptar los términos y la política de datos.');
        }
        $v->check();

        $this->limiter->hit('register-ip', $request->ip, 10, 3600);

        $result = $this->client->signUp((string) $email, (string) $password, array_filter([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'telefono' => $telefono,
        ]), $this->config->appUrl . '/login');

        if (!empty($result['access_token'])) {
            $started = $this->startSession($result, $web);
            return ['requires_confirmation' => false] + $started;
        }
        return ['requires_confirmation' => true, 'user' => null, 'session' => null];
    }

    /** @return array{user: CurrentUser, session: array<string, mixed>} */
    public function refresh(string $refreshToken): array
    {
        return $this->startSession($this->client->refresh($refreshToken), false);
    }

    public function logout(?CurrentUser $user): void
    {
        if ($user !== null) {
            $this->client->signOut($user->accessToken);
        }
        $this->session->remove(self::SESSION_KEY);
        $this->session->destroy();
    }

    /** Siempre responde igual exista o no la cuenta (evita enumeración de usuarios). */
    public function requestPasswordReset(string $email, Request $request): void
    {
        $this->limiter->hit('recover-ip', $request->ip, 10, 900);
        $this->limiter->hit('recover-email', $email, 3, 900);
        try {
            $this->client->recover($email, $this->config->appUrl . '/restablecer');
        } catch (HttpException $e) {
            if ($e->status === 429) {
                throw $e;
            }
            error_log('[auth] recover: ' . $e->getMessage());
        }
    }

    /** Enlace de recuperación con token_hash (plantilla de correo SSR de Supabase). */
    public function startRecoveryFromTokenHash(string $tokenHash): void
    {
        $session = $this->client->verifyRecovery($tokenHash);
        $this->startSession($session, true);
        $this->session->set('recovery', time());
    }

    /** Enlace de recuperación por defecto (tokens en el fragmento #, enviados por JS). */
    public function startRecoveryFromTokens(string $accessToken, string $refreshToken): void
    {
        $claims = $this->jwt->verify($accessToken);
        $this->startSession([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => (int) ($claims['exp'] ?? time() + 3600),
        ], true);
        $this->session->set('recovery', time());
    }

    public function isRecoveryPending(): bool
    {
        $at = (int) $this->session->get('recovery', 0);
        return $at > time() - 1800;
    }

    public function completeRecovery(CurrentUser $user, string $newPassword): void
    {
        $this->client->updatePassword($user->accessToken, $newPassword);
        $this->session->remove('recovery');
    }

    public function changePassword(CurrentUser $user, string $current, string $new, Request $request): void
    {
        $this->limiter->hit('change-password', $user->id, 5, 900);
        // Reautenticación: confirma la contraseña actual con Supabase Auth.
        try {
            $session = $this->client->signInWithPassword($user->email, $current);
        } catch (HttpException $e) {
            if ($e->errorCode === 'CREDENCIALES_INVALIDAS') {
                throw HttpException::validation(['password_actual' => 'La contraseña actual no es correcta.']);
            }
            throw $e;
        }
        $this->client->updatePassword((string) $session['access_token'], $new);
        if ($user->via === 'session') {
            $this->storeSession($session);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $session respuesta de Supabase Auth
     * @return array{user: CurrentUser, session: array<string, mixed>}
     */
    private function startSession(array $session, bool $web): array
    {
        $claims = $this->jwt->verify((string) ($session['access_token'] ?? ''));
        $user = $this->makeUser($claims, (string) $session['access_token'], $web ? 'session' : 'bearer');
        $this->loadProfile($user);

        if (!$user->isActive()) {
            $this->client->signOut($user->accessToken);
            throw new HttpException(403, 'USUARIO_BLOQUEADO', 'Tu cuenta está bloqueada. Contacta a la tienda.');
        }

        if ($web) {
            $this->session->regenerate();
            $this->storeSession($session);
        }

        return [
            'user' => $user,
            'session' => [
                'access_token' => $session['access_token'],
                'refresh_token' => $session['refresh_token'] ?? null,
                'token_type' => 'bearer',
                'expires_in' => (int) ($session['expires_in'] ?? max(0, (int) $claims['exp'] - time())),
                'expires_at' => (int) ($session['expires_at'] ?? $claims['exp']),
            ],
        ];
    }

    /** @param array<string, mixed> $session  @return array<string, mixed> */
    private function storeSession(array $session): array
    {
        $data = [
            'access_token' => (string) $session['access_token'],
            'refresh_token' => (string) ($session['refresh_token'] ?? ''),
            'expires_at' => (int) ($session['expires_at'] ?? (time() + (int) ($session['expires_in'] ?? 3600))),
        ];
        $this->session->set(self::SESSION_KEY, $data);
        return $data;
    }

    /** @param array<string, mixed> $claims */
    private function makeUser(array $claims, string $token, string $via): CurrentUser
    {
        return new CurrentUser(
            id: (string) $claims['sub'],
            email: (string) ($claims['email'] ?? ''),
            claims: $claims,
            accessToken: $token,
            via: $via,
        );
    }

    private function loadProfile(CurrentUser $user): void
    {
        $this->db->setUser($user);
        $row = $this->db->fetchOne(
            'select email, nombre, apellido, rol, estado from public.profiles where id = (select auth.uid())'
        );
        if ($row === null) {
            throw new HttpException(401, 'PERFIL_NO_ENCONTRADO', 'No se encontró el perfil del usuario.');
        }
        $user->rol = (string) $row['rol'];
        $user->estado = (string) $row['estado'];
        $user->nombre = (string) $row['nombre'];
        $user->apellido = (string) $row['apellido'];
    }
}
