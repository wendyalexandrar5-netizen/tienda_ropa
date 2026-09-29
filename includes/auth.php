<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Autenticación (login/logout) y autorización por roles.
 * El rol SIEMPRE se lee de la base de datos en cada petición: si un
 * administrador desactiva una cuenta o cambia su rol, el efecto es inmediato.
 */

const ROL_ADMIN   = 'administrador';
const ROL_CLIENTE = 'cliente';

function usuario_actual(bool $refrescar = false): ?array
{
    static $cargado = false, $usuario = null;
    if ($cargado && !$refrescar) {
        return $usuario;
    }
    $cargado = true;
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $usuario = fila('SELECT id, nombre, apellido, email, telefono, direccion, ciudad, rol, estado, debe_cambiar_password, fecha_registro
                     FROM usuarios WHERE id = ?', [$id]);
    if (!$usuario || $usuario['estado'] !== 'activo') {
        $usuario = null;
        destruir_sesion();
        iniciar_sesion_segura();
        flash('warning', 'Tu sesión fue cerrada. Si crees que es un error, contacta a la tienda.');
    }
    return $usuario;
}

function esta_autenticado(): bool
{
    return usuario_actual() !== null;
}

function es_admin(): bool
{
    $u = usuario_actual();
    return $u !== null && $u['rol'] === ROL_ADMIN;
}

/** Exige sesión iniciada; si no, redirige al login y luego regresa a la página. */
function requerir_login(): array
{
    $u = usuario_actual();
    if ($u === null) {
        flash('info', 'Inicia sesión para continuar.');
        $retorno = ltrim(substr($_SERVER['REQUEST_URI'] ?? '', strlen(base_url())), '/');
        redirigir('login.php', ['retorno' => ruta_retorno_segura($retorno, '')]);
    }
    return $u;
}

/** Exige rol administrador. Un cliente autenticado recibe 403 aunque conozca la URL. */
function requerir_admin(): array
{
    $u = usuario_actual();
    if ($u === null) {
        redirigir('admin/login.php');
    }
    if ($u['rol'] !== ROL_ADMIN) {
        registrar_log("Acceso denegado al panel: usuario {$u['id']} → " . ($_SERVER['REQUEST_URI'] ?? ''), 'seguridad.log');
        error_403('Esta sección es exclusiva para administradores.');
    }
    return $u;
}

function iniciar_sesion_usuario(array $usuario): void
{
    session_regenerate_id(true);           // evita fijación de sesión
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['_creada']    = time();
    unset($_SESSION['_csrf'], $_SESSION['checkout']);
    consulta('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$usuario['id']]);
    usuario_actual(true);
}

function cerrar_sesion_usuario(): void
{
    destruir_sesion();
}

function login_bloqueado(string $email, string $ip): bool
{
    $ventana = (int) config('login_ventana_min', 15);
    $max     = (int) config('login_max_intentos', 5);
    $porEmail = (int) valor("SELECT COUNT(*) FROM intentos_login
                             WHERE email = ? AND ip = ? AND exito = 0 AND fecha > (NOW() - INTERVAL $ventana MINUTE)", [$email, $ip]);
    $porIp    = (int) valor("SELECT COUNT(*) FROM intentos_login
                             WHERE ip = ? AND exito = 0 AND fecha > (NOW() - INTERVAL $ventana MINUTE)", [$ip]);
    return $porEmail >= $max || $porIp >= $max * 4;
}

function registrar_intento_login(string $email, string $ip, bool $exito): void
{
    consulta('INSERT INTO intentos_login (email, ip, exito) VALUES (?, ?, ?)', [$email, $ip, $exito ? 1 : 0]);
    if ($exito) {
        consulta('DELETE FROM intentos_login WHERE email = ? AND ip = ? AND exito = 0', [$email, $ip]);
    }
}

/**
 * Verifica credenciales. Siempre devuelve el MISMO mensaje genérico ante un
 * fallo (correo inexistente, contraseña errónea, cuenta inactiva) para no
 * permitir la enumeración de usuarios.
 *
 * @return array{ok:bool, usuario:?array, error:?string}
 */
function intentar_login(string $email, string $password, ?string $rolRequerido = null): array
{
    $email = mb_strtolower(trim($email));
    $ip    = ip_cliente();
    $generico = 'Correo o contraseña incorrectos.';

    if (login_bloqueado($email, $ip)) {
        return ['ok' => false, 'usuario' => null,
                'error' => 'Demasiados intentos fallidos. Espera ' . (int) config('login_ventana_min', 15) . ' minutos e inténtalo de nuevo.'];
    }

    $u = fila('SELECT id, email, password_hash, rol, estado FROM usuarios WHERE email = ?', [$email]);

    // Hash ficticio: iguala el tiempo de respuesta cuando el correo no existe.
    $hash = $u['password_hash'] ?? '$2y$12$dGpG2i9iMteDO8ebze58W..OsMy9hy2PDSdEzcYNRBWm4.iERNNLe';
    $passwordOk = password_verify($password, $hash);

    $valido = $u && $passwordOk && $u['estado'] === 'activo'
        && ($rolRequerido === null || $u['rol'] === $rolRequerido);

    registrar_intento_login($email, $ip, $valido);

    if (!$valido) {
        if ($u && $passwordOk && $rolRequerido !== null && $u['rol'] !== $rolRequerido) {
            registrar_log("Intento de acceso al panel con cuenta sin rol admin: {$u['id']}", 'seguridad.log');
        }
        return ['ok' => false, 'usuario' => null, 'error' => $generico];
    }

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        consulta('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    return ['ok' => true, 'usuario' => $u, 'error' => null];
}

/** Si la cuenta tiene la marca debe_cambiar_password, sólo puede ir a cambiar_password.php o salir. */
function forzar_cambio_password_si_corresponde(): void
{
    if (empty($_SESSION['usuario_id'])) {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['cambiar_password.php', 'logout.php'], true)) {
        return;
    }
    $u = usuario_actual();
    if ($u && (int) $u['debe_cambiar_password'] === 1) {
        flash('warning', 'Por seguridad debes cambiar la contraseña inicial antes de continuar.');
        redirigir('cambiar_password.php');
    }
}

function actualizar_password(int $usuarioId, string $nueva): void
{
    consulta('UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 0 WHERE id = ?',
        [password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);
    consulta('UPDATE password_resets SET usado = 1 WHERE usuario_id = ? AND usado = 0', [$usuarioId]);
}
