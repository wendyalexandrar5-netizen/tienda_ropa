<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sesión PHP endurecida para la tienda web.
 * Guarda los tokens de Supabase Auth en el SERVIDOR: el navegador solo
 * recibe una cookie de sesión opaca HttpOnly + SameSite=Lax, por lo que el
 * access token no es accesible desde JavaScript (mitiga robo por XSS).
 */
final class Session
{
    private const NAME = 'fc_session';
    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function hasCookie(Request $request): bool
    {
        return isset($request->cookies[self::NAME]);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli' && !defined('APP_TESTING_SESSION')) {
            $this->started = true;
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string) $this->config->sessionLifetime);
            session_name(self::NAME);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $this->config->cookieSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $this->started = true;

        // Expiración absoluta por inactividad.
        $now = time();
        if (isset($_SESSION['_last']) && $now - (int) $_SESSION['_last'] > $this->config->sessionLifetime) {
            $this->destroy();
            session_start();
        }
        $_SESSION['_last'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /** Evita fijación de sesión al cambiar de privilegios (login/logout). */
    public function regenerate(): void
    {
        $this->start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(self::NAME, '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
        $this->started = false;
    }

    public function flash(string $type, string $message): void
    {
        $messages = $this->get('_flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $messages);
    }

    /** @return list<array{type: string, message: string}> */
    public function pullFlash(): array
    {
        $messages = $this->get('_flash', []);
        $this->remove('_flash');
        return is_array($messages) ? $messages : [];
    }

    /** Conserva datos de un formulario tras un error de validación. */
    public function flashOld(array $data): void
    {
        unset($data['password'], $data['password_confirmacion'], $data['password_actual'], $data['_csrf']);
        $this->set('_old', $data);
    }

    public function pullOld(): array
    {
        $old = $this->get('_old', []);
        $this->remove('_old');
        return is_array($old) ? $old : [];
    }
}
