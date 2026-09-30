<?php
/**
 * Punto de arranque común. Todas las páginas lo incluyen en la primera línea:
 *   - carga la configuración y los módulos reutilizables,
 *   - configura el manejo de errores y las cabeceras de seguridad,
 *   - inicia la sesión segura,
 *   - valida el token CSRF de TODAS las peticiones POST.
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$GLOBALS['config'] = require ROOT_PATH . '/config/config.php';

date_default_timezone_set($GLOBALS['config']['timezone']);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/errores.php';
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validacion.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/catalogo.php';
require_once __DIR__ . '/carrito.php';
require_once __DIR__ . '/inventario.php';
require_once __DIR__ . '/pedidos.php';
require_once __DIR__ . '/fabricacion.php';
require_once __DIR__ . '/finanzas.php';
require_once __DIR__ . '/subidas.php';

configurar_errores();

if (PHP_SAPI !== 'cli') {
    enviar_cabeceras_seguridad();
    iniciar_sesion_segura();

    if (es_post()) {
        csrf_verificar_peticion();
    }

    forzar_cambio_password_si_corresponde();
}
