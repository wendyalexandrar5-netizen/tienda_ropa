<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Cabecera del panel. TODA página de admin/ la incluye, y lo primero que hace
 * es requerir_admin(): la verificación de rol ocurre en el servidor, no en el menú.
 * Variables: $titulo, $seccion, $acciones_cabecera (HTML opcional).
 */
$admin = requerir_admin();
$titulo = $titulo ?? 'Panel';
$seccion = $seccion ?? '';
$pendientesMenu = (int) valor("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'");
$stockBajoMenu  = (int) valor("SELECT COUNT(*) FROM variantes_producto v JOIN productos p ON p.id = v.producto_id
                               WHERE v.estado = 'activo' AND p.estado = 'activo' AND v.stock <= ?", [umbral_stock_bajo()]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titulo) ?> · Panel <?= e(ajuste('nombre_tienda')) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/tienda.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
</head>
<body class="admin">
<div class="admin-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="btn btn-icono btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Abrir menú">
                <i class="bi bi-list fs-4"></i>
            </button>
            <div class="flex-grow-1 min-w-0">
                <h1 class="h4 mb-0 text-truncate"><?= e($titulo) ?></h1>
            </div>
            <a class="btn btn-sm btn-outline-fc d-none d-sm-inline-flex" href="<?= url('index.php') ?>" target="_blank" rel="noopener"><i class="bi bi-shop me-1"></i>Ver tienda</a>
            <div class="dropdown">
                <button class="btn btn-link p-0 border-0" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Cuenta">
                    <span class="avatar"><?= e(iniciales($admin['nombre'], $admin['apellido'])) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                    <li class="px-3 py-2"><div class="fw-bold"><?= e($admin['nombre'] . ' ' . $admin['apellido']) ?></div><div class="small text-muted-fc">Administrador</div></li>
                    <li><a class="dropdown-item rounded" href="<?= url('admin/configuracion.php') ?>"><i class="bi bi-gear me-2"></i>Configuración</a></li>
                    <li><a class="dropdown-item rounded" href="<?= url('cambiar_password.php') ?>"><i class="bi bi-key me-2"></i>Cambiar contraseña</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><form method="post" action="<?= url('logout.php') ?>"><?= csrf_campo() ?><button class="dropdown-item rounded text-danger"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button></form></li>
                </ul>
            </div>
        </header>
        <main class="admin-contenido" id="contenido">
            <?php require ROOT_PATH . '/includes/partials/alertas.php'; ?>
            <?php if (!empty($acciones_cabecera)): ?><div class="d-flex flex-wrap justify-content-end gap-2 mb-3"><?= $acciones_cabecera ?></div><?php endif; ?>
