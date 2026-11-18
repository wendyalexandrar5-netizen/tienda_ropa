<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Página breve para la pestaña de la pasarela (errores o redirección).
 * Variables: $titulo, $mensaje, $tipo ('info'|'danger'|'warning'), $urlExterna (opcional, ya validada),
 *            $enlaces (array de [texto, url]).
 */
$tipo = $tipo ?? 'info';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <?php if (!empty($urlExterna)): ?><meta http-equiv="refresh" content="2;url=<?= e($urlExterna) ?>"><?php endif; ?>
    <title><?= e($titulo) ?> · <?= e(ajuste('nombre_tienda', config('app_name'))) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/tienda.css') ?>">
</head>
<body class="bg-negro">
<main class="auth-pagina" style="min-height:100vh">
    <div class="auth-tarjeta text-center" <?= !empty($urlExterna) ? 'data-redirigir="' . e($urlExterna) . '"' : '' ?>>
        <img class="logo-auth" src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="<?= e(ajuste('nombre_tienda')) ?>">
        <?php if (!empty($urlExterna)): ?>
            <div class="spinner-border text-dark mb-3" role="status" aria-hidden="true"></div>
        <?php endif; ?>
        <h1 class="h4 mb-2"><?= e($titulo) ?></h1>
        <div class="alert alert-<?= e($tipo) ?> text-start small"><?= e($mensaje) ?></div>
        <div class="d-grid gap-2">
            <?php if (!empty($urlExterna)): ?>
                <a class="btn btn-fc" href="<?= e($urlExterna) ?>" rel="noopener noreferrer">Continuar a la pasarela <i class="bi bi-box-arrow-up-right ms-1"></i></a>
            <?php endif; ?>
            <?php foreach ($enlaces ?? [] as [$texto, $destino]): ?>
                <a class="btn btn-outline-fc" href="<?= e($destino) ?>"><?= e($texto) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<script src="<?= asset('assets/js/pago.js') ?>"></script>
</body>
</html>
