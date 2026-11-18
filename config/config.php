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

    // Pagos en línea. SÓLO se acepta el ambiente de PRUEBAS (sandbox) de Wompi: las
    // llaves deben empezar por pub_test_, prv_test_, test_integrity_ y test_events_.
    // Las llaves se obtienen en https://comercios.wompi.co (modo "Pruebas") y se
    // escriben en config/config.local.php, que NO se sube a Git.
    'pagos' => [
        'wompi' => [
            'llave_publica'      => '',   // pub_test_...
            'llave_privada'      => '',   // prv_test_... (consultar transacciones por referencia)
            'secreto_integridad' => '',   // test_integrity_... (firma del Web Checkout)
            'secreto_eventos'    => '',   // test_events_... (verificación de webhooks)
            'checkout_url'       => 'https://checkout.wompi.co/p/',
            'api_url'            => 'https://sandbox.wompi.co/v1',
        ],
        // Proveedor que procesa PSE. Vacío = PSE deshabilitado (se informa al cliente).
        // 'wompi' = PSE a través de la API de Wompi (requiere las llaves anteriores).
        'pse_proveedor' => '',
        // URL pública absoluta de la tienda (p. ej. un túnel https://xxxx.ngrok-free.app/tienda_ropa)
        // para la URL de retorno y el webhook. Vacío = se usa la URL con la que se navega.
        'url_publica' => '',
        // Segundos mínimos entre consultas a la API del proveedor para un mismo pago.
        'intervalo_consulta' => 6,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
