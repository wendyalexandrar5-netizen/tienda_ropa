<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Funciones generales reutilizables (escape, URLs, redirecciones, formato, flash).
 */

/** Escapa texto para imprimirlo en HTML (protección XSS). */
function e($valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $clave, $defecto = null)
{
    return $GLOBALS['config'][$clave] ?? $defecto;
}

/** Ruta pública base de la aplicación, p. ej. "/tienda_ropa" (sin barra final). */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configurada = config('base_url');
    if (is_string($configurada)) {
        return $base = rtrim($configurada, '/');
    }
    $base = '';
    $script   = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $raiz     = str_replace('\\', '/', (string) realpath(ROOT_PATH));
    $nombre   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && $raiz !== '' && strpos($script, $raiz) === 0) {
        $relativo = substr($script, strlen($raiz));           // "/admin/index.php"
        if ($relativo !== '' && substr($nombre, -strlen($relativo)) === $relativo) {
            $base = rtrim(substr($nombre, 0, -strlen($relativo)), '/');
        }
    }
    return $base;
}

/** Construye una URL interna con parámetros opcionales. */
function url(string $ruta = '', array $parametros = []): string
{
    $u = base_url() . '/' . ltrim($ruta, '/');
    $parametros = array_filter($parametros, static fn($v) => $v !== null && $v !== '');
    if ($parametros) {
        $u .= '?' . http_build_query($parametros);
    }
    return $u;
}

/** URL de un recurso estático con "cache busting" por fecha de modificación. */
function asset(string $ruta): string
{
    $archivo = ROOT_PATH . '/' . ltrim($ruta, '/');
    $v = is_file($archivo) ? '?v=' . filemtime($archivo) : '';
    return url($ruta) . $v;
}

/** URL de la imagen de un producto/categoría o un marcador de posición. */
function imagen_url(?string $ruta): string
{
    if ($ruta && preg_match('#^(assets|uploads)/[A-Za-z0-9/_\-.]+$#', $ruta) && is_file(ROOT_PATH . '/' . $ruta)) {
        return url($ruta);
    }
    return url('assets/img/placeholder.svg');
}

function redirigir(string $ruta, array $parametros = []): void
{
    header('Location: ' . url($ruta, $parametros));
    exit;
}

/**
 * Sólo permite redirigir a rutas internas relativas (evita "open redirect").
 */
function ruta_retorno_segura(?string $ruta, string $defecto = 'index.php'): string
{
    $ruta = (string) $ruta;
    if ($ruta === '' || !preg_match('#^[A-Za-z0-9_\-./]+\.php(\?[A-Za-z0-9_\-=&%.+]*)?$#', $ruta)
        || strpos($ruta, '..') !== false || strpos($ruta, '//') !== false) {
        return $defecto;
    }
    return ltrim($ruta, '/');
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Lee un texto de $_POST, recortado y con longitud máxima. */
function post_texto(string $clave, int $max = 255): string
{
    $v = $_POST[$clave] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(str_replace("\0", '', $v));
    return mb_substr($v, 0, $max);
}

function post_int(string $clave, int $defecto = 0): int
{
    $v = filter_var($_POST[$clave] ?? null, FILTER_VALIDATE_INT);
    return $v === false || $v === null ? $defecto : (int) $v;
}

function get_texto(string $clave, int $max = 100): string
{
    $v = $_GET[$clave] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

function get_int(string $clave, int $defecto = 0): int
{
    $v = filter_var($_GET[$clave] ?? null, FILTER_VALIDATE_INT);
    return $v === false || $v === null ? $defecto : (int) $v;
}

/** Mensajes flash (se muestran una sola vez tras una redirección). */
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['_flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flashes(): array
{
    $m = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $m;
}

function precio(float|int|string|null $valor): string
{
    return '$' . number_format((float) $valor, 0, ',', '.');
}

function fecha(?string $valor, bool $conHora = true): string
{
    if (!$valor) {
        return '—';
    }
    $t = strtotime($valor);
    return $t ? date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '—';
}

/** Valor de la tabla `configuracion` (con caché por petición). */
function ajuste(string $clave, string $defecto = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT clave, valor FROM configuracion') as $fila) {
                $cache[$fila['clave']] = $fila['valor'];
            }
        } catch (Throwable $e) {
            registrar_log('No se pudo leer configuracion: ' . $e->getMessage());
        }
    }
    return $cache[$clave] ?? $defecto;
}

function ip_cliente(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Cabeceras HTTP de seguridad. La CSP sólo permite scripts propios (sin JS en
 * línea), lo que reduce drásticamente el impacto de un posible XSS.
 */
function enviar_cabeceras_seguridad(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
}

/**
 * Datos de paginación.
 * @return array{pagina:int,por_pagina:int,total:int,paginas:int,offset:int}
 */
function paginar(int $total, int $porPagina, int $pagina): array
{
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina  = min(max(1, $pagina), $paginas);
    return [
        'pagina'     => $pagina,
        'por_pagina' => $porPagina,
        'total'      => $total,
        'paginas'    => $paginas,
        'offset'     => ($pagina - 1) * $porPagina,
    ];
}

/** Parámetros GET actuales combinados con cambios (para enlaces de filtros/paginación). */
function url_actual(array $cambios = []): string
{
    $params = array_merge($_GET, $cambios);
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $dir    = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false ? 'admin/' : '';
    return url($dir . $script, $params);
}

function iniciales(string $nombre, string $apellido = ''): string
{
    return mb_strtoupper(mb_substr($nombre, 0, 1) . mb_substr($apellido, 0, 1));
}
