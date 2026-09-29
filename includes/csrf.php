<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Protección CSRF con token sincronizado por sesión.
 * El bootstrap verifica automáticamente TODAS las peticiones POST, así que
 * ningún formulario puede olvidar la validación en el servidor.
 */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valido(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

function csrf_verificar_peticion(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_valido(is_string($token) ? $token : null)) {
        registrar_log('CSRF rechazado: ' . ($_SERVER['REQUEST_URI'] ?? '') . ' IP ' . ip_cliente(), 'seguridad.log');
        mostrar_error(403, 'La solicitud expiró',
            'Por seguridad no pudimos procesar el formulario (token inválido o sesión expirada). Vuelve atrás, recarga la página e inténtalo de nuevo.');
    }
}
