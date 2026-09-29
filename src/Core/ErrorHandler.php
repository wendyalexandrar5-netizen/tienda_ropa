<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Manejo global de errores: nunca se muestran trazas, rutas ni SQL al
 * usuario en producción; los detalles quedan en el log del servidor.
 */
final class ErrorHandler
{
    public static function register(Config $config): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $config->debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($config): void {
            error_log('[app] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $config->debug ? (string) $e : 'Error interno del servidor.';
        });
    }
}
