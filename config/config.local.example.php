<?php
// Copie este archivo como config.local.php y ajuste sólo lo que necesite.
return [
    'app_env' => 'development',
    'db' => [
        'host' => '127.0.0.1',
        'user' => 'root',
        'pass' => '',
    ],
    // 'base_url' => '/tienda_ropa',

    // Pagos en línea con Wompi (SÓLO llaves de PRUEBAS de https://comercios.wompi.co)
    // Por defecto los pagos en línea funcionan en 'enlace_demo' (abren login.wompi.co / registro.pse.com.co
    // sin cobro). Para la integración real con el sandbox de Wompi descomente y use 'modo' => 'sandbox':
    // 'pagos' => [
    //     'modo' => 'sandbox',
    //     'wompi' => [
    //         'llave_publica'      => 'pub_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    //         'llave_privada'      => 'prv_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    //         'secreto_integridad' => 'test_integrity_xxxxxxxxxxxxxxxxxxxxxxxx',
    //         'secreto_eventos'    => 'test_events_xxxxxxxxxxxxxxxxxxxxxxxxxxx',
    //     ],
    //     'pse_proveedor' => 'wompi',
    //     'url_publica'   => 'https://xxxx.ngrok-free.app/tienda_ropa',
    // ],
];
