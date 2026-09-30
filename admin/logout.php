<?php
// El cierre de sesión es común a tienda y panel (POST + CSRF en /logout.php).
require __DIR__ . '/../includes/bootstrap.php';
redirigir('logout.php');
