<?php

declare(strict_types=1);

/*
 * Front controller único: tienda web (/), panel (/admin) y API REST (/api).
 * El document root del servidor debe ser esta carpeta public/: el código
 * fuente, las vistas y el .env quedan fuera del alcance del navegador.
 */

// Servidor embebido de PHP: servir archivos estáticos existentes.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

/** @var App\Core\Container $container */
$container = require dirname(__DIR__) . '/src/bootstrap.php';
app($container);

$router = require dirname(__DIR__) . '/src/routes.php';
$kernel = new App\Core\Kernel($container, $router);

$kernel->handle(App\Core\Request::fromGlobals($container->config))->send();
