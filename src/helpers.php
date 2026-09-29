<?php

declare(strict_types=1);

/*
 * Funciones auxiliares para las vistas.
 * REGLA: todo dato dinámico que se imprime en HTML pasa por e().
 */

/** Escapa texto para HTML (previene XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Codifica datos para atributos data-* (JSON escapado). */
function json_attr(mixed $value): string
{
    return e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
}

/** Formato de moneda colombiana: $ 80.000 */
function money(mixed $value): string
{
    // Pesos colombianos: sin centavos.
    return '$ ' . number_format(round((float) ($value ?? 0)), 0, ',', '.');
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/public/assets/' . $path;
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return '/assets/' . $path . '?v=' . $v;
}

/** Solo URLs internas o https; evita javascript:, data:, etc. en atributos src/href. */
function safe_url(?string $url, string $fallback = '/assets/img/placeholder.svg'): string
{
    $url = (string) $url;
    if ($url !== '' && (str_starts_with($url, '/') && !str_starts_with($url, '//') || str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1') || str_starts_with($url, 'http://localhost'))) {
        return e($url);
    }
    return e($fallback);
}

function csrf_token(): string
{
    return app()->csrf()->token();
}

function csrf_field(): string
{
    // _back: ruta actual, para volver al formulario si el servidor rechaza los datos.
    $back = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="_back" value="' . e($back . ($query ? '?' . $query : '')) . '">';
}

function app(?App\Core\Container $set = null): App\Core\Container
{
    static $container = null;
    if ($set !== null) {
        $container = $set;
    }
    return $container;
}

function old(string $key, mixed $default = ''): string
{
    static $old = null;
    $old ??= app()->session()->pullOld();
    $value = $old[$key] ?? $default;
    return is_scalar($value) ? (string) $value : '';
}

/** Mensaje de error de validación de un campo (tras redirección). */
function field_error(string $key): ?string
{
    static $errors = null;
    if ($errors === null) {
        $errors = app()->session()->get('_errors', []);
        app()->session()->remove('_errors');
    }
    return isset($errors[$key]) ? (string) $errors[$key] : null;
}

function fecha(?string $iso, bool $withTime = false): string
{
    if (!$iso) {
        return '';
    }
    try {
        $tz = new DateTimeZone('America/Bogota');
        $d = (new DateTimeImmutable($iso))->setTimezone($tz);
    } catch (Exception) {
        return '';
    }
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $out = $d->format('j') . ' ' . $meses[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    return $withTime ? $out . ', ' . $d->format('g:i a') : $out;
}

function numero_pedido(int|string|null $numero): string
{
    return 'FC-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
}

function metodo_pago(?string $metodo): string
{
    return [
        'contra_entrega' => 'Pago contra entrega',
        'transferencia' => 'Transferencia bancaria',
        'efectivo' => 'Efectivo',
        'tarjeta' => 'Tarjeta',
    ][$metodo ?? ''] ?? (string) $metodo;
}

function badge_estado(?string $color, ?string $nombre): string
{
    $allowed = ['warning', 'info', 'primary', 'success', 'danger', 'secondary'];
    $color = in_array($color, $allowed, true) ? $color : 'secondary';
    return '<span class="badge-estado badge-estado--' . $color . '">' . e($nombre) . '</span>';
}

/** Construye la URL actual con parámetros modificados (filtros y paginación). */
function query_url(array $params, ?string $path = null): string
{
    $query = array_merge($_GET, $params);
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    $path ??= parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function is_active_path(string $prefix, bool $exact = false): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return $exact ? $path === $prefix : ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/'));
}
