<?php
/**
 * Configuración general de la aplicación.
 *
 * Para personalizar la instalación SIN modificar este archivo, copie
 * config/config.local.example.php como config/config.local.php y cambie allí
 * sólo los valores necesarios (ese archivo está excluido de Git).
 */

$config = [
    // 'development' muestra el detalle de los errores; 'production' sólo muestra
    // mensajes amigables y registra el detalle en storage/logs/app.log.
    'app_env'  => 'development',
    'app_name' => 'FIRE CAT',

    // Conexión MySQL/MariaDB (valores por defecto de XAMPP).
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tienda_ropa',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Ruta pública de la aplicación (ej. '/tienda_ropa'). null = detección automática.
    'base_url' => null,

    'timezone' => 'America/Bogota',
    'moneda'   => 'COP',

    // Sesión: minutos de inactividad antes de cerrarla automáticamente.
    'session_idle_minutes' => 30,

    // Protección contra fuerza bruta: intentos fallidos permitidos por ventana.
    'login_max_intentos'  => 5,
    'login_ventana_min'   => 15,

    // Subida de imágenes.
    'upload_max_bytes' => 2 * 1024 * 1024,

    // Recuperación de contraseña: en XAMPP normalmente no hay servidor de correo,
    // así que en modo desarrollo el enlace se muestra en pantalla y se guarda en
    // storage/logs/correo.log. En producción sólo se registra en el log.
    'reset_minutos_validez' => 60,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
