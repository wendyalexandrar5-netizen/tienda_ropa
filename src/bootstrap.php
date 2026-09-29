<?php

declare(strict_types=1);

/*
 * Arranque de la aplicación: autoload PSR-4 (App\ → src/), variables de
 * entorno, configuración, manejo de errores y contenedor de servicios.
 * No requiere Composer.
 */

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/helpers.php';

App\Core\Env::load(APP_ROOT . '/.env');

$config = App\Core\Config::fromEnv();

App\Core\ErrorHandler::register($config);

return new App\Core\Container($config);
