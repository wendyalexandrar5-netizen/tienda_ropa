<?php
/** @var App\Core\View $view  @var string $content  @var App\Auth\CurrentUser $currentUser */
$nav = [
    ['Principal', null, null],
    ['Dashboard', '/admin', 'bi-grid-1x2'],
    ['Caja / Venta en tienda', '/admin/caja', 'bi-cash-stack'],
    ['Catálogo', null, null],
    ['Productos', '/admin/productos', 'bi-bag'],
    ['Categorías', '/admin/categorias', 'bi-tags'],
    ['Tallas y colores', '/admin/variantes', 'bi-palette'],
    ['Inventario', '/admin/inventario', 'bi-box-seam'],
    ['Ventas', null, null],
    ['Pedidos', '/admin/pedidos', 'bi-receipt'],
    ['Clientes', '/admin/clientes', 'bi-people'],
    ['Reportes', '/admin/reportes', 'bi-graph-up-arrow'],
    ['Tienda', null, null],
    ['Configuración', '/admin/configuracion', 'bi-gear'],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e(($title ?? 'Panel') . ' · Admin FIRE CAT') ?></title>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <link rel="icon" type="image/png" href="/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
</head>
<body class="admin-body" data-auth="1">
<div class="admin-shell">
  <aside class="admin-sidebar" id="adminSidebar" aria-label="Menú administrativo">
    <a class="brand" href="/admin"><img src="/assets/img/brand/firecat-mark-blanco.png" alt="" width="36" height="36"><span>FIRE CAT<small>ADMINISTRACIÓN</small></span></a>
    <ul class="admin-nav">
      <?php foreach ($nav as [$label, $url, $icon]): ?>
        <?php if ($url === null): ?>
          <li class="nav-section"><?= e($label) ?></li>
        <?php else: ?>
          <li><a href="<?= e($url) ?>" class="<?= is_active_path($url, $url === '/admin') ? 'active' : '' ?>"><i class="bi <?= e($icon) ?>"></i><?= e($label) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <div class="sidebar-foot">
      <ul class="admin-nav">
        <li><a href="/" target="_blank" rel="noopener"><i class="bi bi-shop"></i>Ver tienda</a></li>
        <li>
          <form action="/logout" method="post"><?= csrf_field() ?><button type="submit"><i class="bi bi-box-arrow-right"></i>Cerrar sesión</button></form>
        </li>
      </ul>
    </div>
  </aside>
  <div class="admin-backdrop" data-sidebar-close></div>

  <div class="admin-main">
    <header class="admin-topbar">
      <button class="btn btn-icon btn-light-fc admin-mobile-toggle" type="button" data-sidebar-open aria-label="Abrir menú"><i class="bi bi-list"></i></button>
      <h1><?= e($title ?? 'Panel') ?></h1>
      <div class="ms-auto d-flex align-items-center gap-2">
        <div class="text-end d-none d-sm-block lh-sm">
          <div class="fw-600 small"><?= e($currentUser->displayName()) ?></div>
          <div class="text-muted-fc" style="font-size:.75rem">Administrador</div>
        </div>
        <div class="avatar" style="width:40px;height:40px;font-size:1rem"><?= e(mb_strtoupper(mb_substr($currentUser->displayName(), 0, 1))) ?></div>
      </div>
    </header>
    <main class="admin-content" id="contenido">
      <?php foreach ($flash ?? [] as $f): ?>
        <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'info', 'warning'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="alert">
          <?= e($f['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts" aria-live="polite"></div>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<?php if (!empty($useCharts)): ?><script src="<?= asset('vendor/chartjs/chart.umd.min.js') ?>"></script><?php endif; ?>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/admin.js') ?>"></script>
</body>
</html>
