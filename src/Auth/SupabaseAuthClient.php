<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\HttpClient;
use App\Core\HttpException;

/**
 * Cliente REST de Supabase Auth (GoTrue). Supabase Auth gestiona el registro,
 * login, logout, recuperación de contraseña, sesiones y tokens; esta
 * aplicación NUNCA almacena ni compara contraseñas.
 */
final class SupabaseAuthClient
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
    ) {
    }

    /** @param array<string, mixed> $metadata  @return array<string, mixed> */
    public function signUp(string $email, string $password, array $metadata, ?string $redirectTo = null): array
    {
        return $this->call('POST', '/signup' . $this->redirect($redirectTo), [
            'email' => $email,
            'password' => $password,
            'data' => $metadata,
        ]);
    }

    /** @return array<string, mixed> sesión {access_token, refresh_token, expires_in, user} */
    public function signInWithPassword(string $email, string $password): array
    {
        return $this->call('POST', '/token?grant_type=password', ['email' => $email, 'password' => $password]);
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken): array
    {
        return $this->call('POST', '/token?grant_type=refresh_token', ['refresh_token' => $refreshToken]);
    }

    public function signOut(string $accessToken): void
    {
        try {
            $this->call('POST', '/logout?scope=local', null, $accessToken);
        } catch (HttpException) {
            // El token ya puede estar expirado/revocado: la sesión local se destruye igual.
        }
    }

    public function recover(string $email, ?string $redirectTo = null): void
    {
        $this->call('POST', '/recover' . $this->redirect($redirectTo), ['email' => $email]);
    }

    /** Verifica el token_hash del correo de recuperación. @return array<string, mixed> sesión */
    public function verifyRecovery(string $tokenHash): array
    {
        return $this->call('POST', '/verify', ['type' => 'recovery', 'token_hash' => $tokenHash]);
    }

    /** @return array<string, mixed> */
    public function updatePassword(string $accessToken, string $newPassword): array
    {
        return $this->call('PUT', '/user', ['password' => $newPassword], $accessToken);
    }

    /** @return array<string, mixed> */
    public function getUser(string $accessToken): array
    {
        return $this->call('GET', '/user', null, $accessToken);
    }

    // ---- Administración (solo scripts/backend con SUPABASE_SECRET_KEY) ----

    /** @param array<string, mixed> $metadata  @return array<string, mixed> */
    public function adminCreateUser(string $email, string $password, array $metadata = []): array
    {
        return $this->call('POST', '/admin/users', [
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
            'user_metadata' => $metadata,
        ], null, true);
    }

    /** @return array<string, mixed> */
    public function adminListUsers(int $page = 1, int $perPage = 200): array
    {
        return $this->call('GET', "/admin/users?page=$page&per_page=$perPage", null, null, true);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $payload, ?string $accessToken = null, bool $admin = false): array
    {
        if ($this->config->supabaseAuthUrl === '') {
            throw new HttpException(503, 'SERVICIO_NO_DISPONIBLE', 'Supabase Auth no está configurado (SUPABASE_URL).');
        }

        $headers = ['apikey' => $this->config->supabasePublishableKey];
        if ($admin) {
            $secret = $this->config->supabaseSecretKey;
            if ($secret === '') {
                throw new \RuntimeException('SUPABASE_SECRET_KEY es necesaria para operaciones administrativas de Auth.');
            }
            // Claves nuevas (sb_secret_...) van en "apikey"; la clave legacy
            // service_role es un JWT y además se envía como Bearer.
            $headers['apikey'] = $secret;
            if (substr_count($secret, '.') === 2) {
                $headers['Authorization'] = 'Bearer ' . $secret;
            }
        } elseif ($accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        $res = $this->http->json($method, $this->config->supabaseAuthUrl . $path, $headers, $payload);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return is_array($res['json']) ? $res['json'] : [];
        }
        throw $this->mapError($res['status'], is_array($res['json']) ? $res['json'] : []);
    }

    private function redirect(?string $redirectTo): string
    {
        return $redirectTo ? '?redirect_to=' . rawurlencode($redirectTo) : '';
    }

    /** @param array<string, mixed> $body */
    private function mapError(int $status, array $body): HttpException
    {
        $code = (string) ($body['error_code'] ?? $body['error'] ?? '');
        $msg = (string) ($body['msg'] ?? $body['error_description'] ?? $body['message'] ?? '');

        return match (true) {
            str_starts_with($code, 'refresh_token') || (in_array($code, ['invalid_credentials', 'invalid_grant'], true) && str_contains(strtolower($msg), 'refresh'))
                => new HttpException(401, 'SESION_INVALIDA', 'La sesión expiró. Inicia sesión nuevamente.'),
            in_array($code, ['invalid_credentials', 'invalid_grant'], true)
                => new HttpException(401, 'CREDENCIALES_INVALIDAS', 'Correo o contraseña incorrectos.'),
            $code === 'email_not_confirmed'
                => new HttpException(403, 'EMAIL_NO_CONFIRMADO', 'Debes confirmar tu correo antes de iniciar sesión. Revisa tu bandeja de entrada.'),
            in_array($code, ['user_already_exists', 'email_exists'], true)
                => new HttpException(409, 'EMAIL_REGISTRADO', 'Ya existe una cuenta con ese correo. Inicia sesión o recupera tu contraseña.'),
            $code === 'weak_password'
                => new HttpException(422, 'CONTRASENA_DEBIL', 'La contraseña es demasiado débil. Usa al menos 8 caracteres con letras y números.'),
            $code === 'same_password'
                => new HttpException(422, 'CONTRASENA_REPETIDA', 'La nueva contraseña debe ser diferente a la actual.'),
            in_array($code, ['otp_expired', 'bad_jwt', 'session_not_found', 'flow_state_expired'], true) || $status === 403 && $msg !== ''
                => new HttpException(401, 'ENLACE_INVALIDO', 'El enlace no es válido o expiró. Solicita uno nuevo.'),
            $status === 429 || str_starts_with($code, 'over_')
                => HttpException::tooManyRequests(),
            $code === 'signup_disabled'
                => new HttpException(403, 'REGISTRO_DESHABILITADO', 'El registro de nuevas cuentas está deshabilitado.'),
            $status === 422 || $status === 400
                => new HttpException(422, 'AUTH_DATOS_INVALIDOS', 'No fue posible procesar la solicitud con los datos enviados.'),
            default => (function () use ($status, $code, $msg) {
                error_log("[auth] Error inesperado de Supabase Auth ($status $code): $msg");
                return new HttpException(502, 'ERROR_AUTENTICACION', 'El servicio de autenticación respondió con un error. Inténtalo más tarde.');
            })(),
        };
    }
}
