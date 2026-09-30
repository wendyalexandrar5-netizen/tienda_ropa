<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Manejo centralizado de errores: en producción nunca se muestran rutas,
 * consultas SQL, credenciales ni trazas; todo se registra en storage/logs/app.log.
 */

function es_desarrollo(): bool
{
    return ($GLOBALS['config']['app_env'] ?? 'production') === 'development';
}

function registrar_log(string $mensaje, string $archivo = 'app.log'): void
{
    $dir = ROOT_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $linea = sprintf("[%s] %s%s", date('Y-m-d H:i:s'), $mensaje, PHP_EOL);
    @file_put_contents($dir . '/' . $archivo, $linea, FILE_APPEND | LOCK_EX);
}

function configurar_errores(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', es_desarrollo() && PHP_SAPI === 'cli' ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT_PATH . '/storage/logs/php_errors.log');

    set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
        if (!(error_reporting() & $nivel)) {
            return false;
        }
        throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
    });

    set_exception_handler(static function (Throwable $e): void {
        registrar_log(get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine()
            . PHP_EOL . $e->getTraceAsString());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, (string) $e . PHP_EOL);
            exit(1);
        }
        $detalle = es_desarrollo() ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : null;
        if ($e instanceof PDOException) {
            $detalle = es_desarrollo() ? $e->getMessage() : null;
        }
        mostrar_error(500, 'Ocurrió un error inesperado', 'Estamos trabajando para solucionarlo. Intenta nuevamente en unos minutos.', $detalle);
    });
}

/**
 * Muestra una página de error amigable y termina la ejecución.
 */
function mostrar_error(int $codigo, string $titulo, string $mensaje, ?string $detalle = null): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $codigo_error = $codigo;
    require ROOT_PATH . '/includes/partials/pagina_error.php';
    exit;
}

function error_404(string $mensaje = 'La página que buscas no existe o fue movida.'): void
{
    mostrar_error(404, 'Página no encontrada', $mensaje);
}

function error_403(string $mensaje = 'No tienes permisos para acceder a este recurso.'): void
{
    mostrar_error(403, 'Acceso denegado', $mensaje);
}
