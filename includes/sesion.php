<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Sesión segura: cookie HttpOnly + SameSite=Lax (+ Secure bajo HTTPS), modo
 * estricto, expiración por inactividad y regeneración periódica del ID.
 */

function es_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function iniciar_sesion_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');

    session_name('FIRECATSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url() === '' ? '/' : base_url() . '/',
        'secure'   => es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $ahora = time();
    $limite = (int) config('session_idle_minutes', 30) * 60;

    if (isset($_SESSION['_ultima_actividad']) && ($ahora - $_SESSION['_ultima_actividad']) > $limite) {
        $habiaUsuario = isset($_SESSION['usuario_id']);
        destruir_sesion();
        session_start();
        if ($habiaUsuario) {
            flash('warning', 'Tu sesión expiró por inactividad. Inicia sesión nuevamente.');
        }
    }
    $_SESSION['_ultima_actividad'] = $ahora;

    if (!isset($_SESSION['_creada'])) {
        $_SESSION['_creada'] = $ahora;
    } elseif ($ahora - $_SESSION['_creada'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_creada'] = $ahora;
    }
}

function destruir_sesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}
