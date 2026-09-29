<?php
/** @var App\Core\View $view */
/** @var string $content */
$title = isset($title) ? $title . ' · ' . ($storeConfig['nombre_tienda'] ?? 'FIRE CAT') : ($storeConfig['nombre_tienda'] ?? 'FIRE CAT') . ' · Ropa urbana con actitud';
$description = $description ?? ($storeConfig['eslogan'] ?? 'Tienda online de ropa urbana');
$bodyClass = $bodyClass ?? '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="theme-color" content="#FFE600">
  <link rel="icon" type="image/png" href="/favicon.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="<?= e($bodyClass) ?>" data-auth="<?= $currentUser ? '1' : '0' ?>">
  <a class="visually-hidden-focusable position-absolute p-2 bg-white" href="#contenido">Saltar al contenido</a>

  <?php if (!empty($storeConfig['mensaje_banner'])): ?>
    <div class="announcement"><?= e($storeConfig['mensaje_banner']) ?></div>
  <?php endif; ?>

  <?= $view->partial('header', get_defined_vars()) ?>

  <main id="contenido">
    <?php if (!empty($flash)): ?>
      <div class="container pt-3" data-flash>
        <?php foreach ($flash as $f): ?>
          <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'info', 'warning'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show d-flex gap-2 align-items-center" role="alert">
            <i class="bi <?= $f['type'] === 'success' ? 'bi-check-circle' : ($f['type'] === 'danger' ? 'bi-exclamation-octagon' : 'bi-info-circle') ?>"></i>
            <div><?= e($f['message']) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?= $content ?>
  </main>

  <?php if (empty($hideFooter)): ?>
    <?= $view->partial('footer', get_defined_vars()) ?>
  <?php endif; ?>

  <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts" aria-live="polite" aria-atomic="true"></div>

  <script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
