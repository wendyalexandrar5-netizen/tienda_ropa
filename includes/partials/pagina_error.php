<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Página de error independiente (no consulta la base de datos, por si el
 * error es justamente de conexión). Variables: $codigo_error, $titulo, $mensaje, $detalle.
 */
$enAdmin = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · <?= e(config('app_name')) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/tienda.css') ?>">
</head>
<body class="bg-crema">
<main class="container pagina-error py-5">
    <div class="row align-items-center g-5 w-100">
        <div class="col-md-5 text-center">
            <img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="" width="160" height="160" class="rounded-4 mb-3">
            <div class="codigo"><?= (int) $codigo_error ?></div>
        </div>
        <div class="col-md-7">
            <h1 class="mb-3"><?= e($titulo) ?></h1>
            <p class="lead text-muted-fc mb-4"><?= e($mensaje) ?></p>
            <?php if (!empty($detalle)): ?>
                <div class="alert alert-warning small"><strong>Detalle (sólo en modo desarrollo):</strong> <?= e($detalle) ?></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-fc" href="<?= url($enAdmin ? 'admin/index.php' : 'index.php') ?>"><i class="bi bi-house me-1"></i><?= $enAdmin ? 'Ir al panel' : 'Ir al inicio' ?></a>
                <a class="btn btn-outline-fc" href="<?= url('productos.php') ?>">Ver la tienda</a>
            </div>
        </div>
    </div>
</main>
</body>
</html>
