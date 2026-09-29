<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
$menuAdmin = [
    'dashboard'     => ['index.php', 'bi-speedometer2', 'Dashboard', 0],
    'productos'     => ['productos.php', 'bi-tags', 'Productos', 0],
    'categorias'    => ['categorias.php', 'bi-grid', 'Categorías', 0],
    'inventario'    => ['inventario.php', 'bi-boxes', 'Inventario', $stockBajoMenu],
    'pedidos'       => ['pedidos.php', 'bi-receipt', 'Pedidos', $pendientesMenu],
    'usuarios'      => ['usuarios.php', 'bi-people', 'Clientes', 0],
    'reportes'      => ['reportes.php', 'bi-bar-chart', 'Reportes', 0],
    'configuracion' => ['configuracion.php', 'bi-gear', 'Configuración', 0],
];
?>
<aside class="admin-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Menú del panel">
    <div class="offcanvas-header d-lg-none">
        <span class="marca text-white"><img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="" width="36" height="36"> Panel</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Cerrar"></button>
    </div>
    <div class="d-flex flex-column h-100 p-3">
        <a class="marca text-white mb-4 px-2 d-none d-lg-inline-flex" href="<?= url('admin/index.php') ?>">
            <img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="" width="40" height="40">
            <span><?= e(ajuste('nombre_tienda')) ?><small class="d-block fw-semibold text-white-50" style="font-size:.7rem;letter-spacing:.14em">ADMIN</small></span>
        </a>
        <nav class="nav flex-column gap-1">
            <?php foreach ($menuAdmin as $clave => [$ruta, $icono, $texto, $contador]): ?>
                <a class="nav-link<?= $seccion === $clave ? ' active' : '' ?>" href="<?= url('admin/' . $ruta) ?>" <?= $seccion === $clave ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= $icono ?>"></i><span class="flex-grow-1"><?= e($texto) ?></span>
                    <?php if ($contador > 0): ?><span class="badge rounded-pill text-bg-warning"><?= $contador ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="mt-auto pt-4">
            <a class="nav-link" href="<?= url('index.php') ?>"><i class="bi bi-shop"></i><span>Ir a la tienda</span></a>
            <form method="post" action="<?= url('logout.php') ?>">
                <?= csrf_campo() ?>
                <button class="nav-link w-100 text-start" type="submit"><i class="bi bi-box-arrow-right"></i><span>Cerrar sesión</span></button>
            </form>
        </div>
    </div>
</aside>
