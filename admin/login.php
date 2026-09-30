<?php
/** Acceso independiente al panel: sólo acepta cuentas con rol administrador. */
require __DIR__ . '/../includes/bootstrap.php';

if (es_admin()) {
    redirigir('admin/index.php');
}

$email = '';
$error = null;
if (es_post()) {
    $email = mb_strtolower(post_texto('email', 120));
    $r = intentar_login($email, (string) ($_POST['password'] ?? ''), ROL_ADMIN);
    if ($r['ok']) {
        iniciar_sesion_usuario($r['usuario']);
        flash('success', 'Bienvenido al panel administrativo.');
        redirigir('admin/index.php');
    }
    $error = $r['error'] === 'Correo o contraseña incorrectos.' ? 'Credenciales inválidas o sin permisos de administrador.' : $r['error'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso administrativo · <?= e(ajuste('nombre_tienda')) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/tienda.css') ?>">
</head>
<body class="bg-negro">
<main class="auth-pagina" style="min-height:100vh">
    <div class="auth-tarjeta">
        <div class="text-center">
            <img class="logo-auth" src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="<?= e(ajuste('nombre_tienda')) ?>">
            <span class="eyebrow d-block">Panel administrativo</span>
            <h1 class="h3 mb-4">Acceso restringido</h1>
        </div>
        <?php require ROOT_PATH . '/includes/partials/alertas.php'; ?>
        <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-shield-x me-1"></i><?= e($error) ?></div><?php endif; ?>
        <?php if (esta_autenticado()): ?>
            <div class="alert alert-warning small">Tu sesión actual no tiene permisos de administrador.</div>
        <?php endif; ?>
        <form method="post" action="<?= url('admin/login.php') ?>" class="needs-validation" novalidate data-cargando>
            <?= csrf_campo() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Correo</label>
                <input class="form-control" type="email" id="email" name="email" required maxlength="120" value="<?= e($email) ?>" autocomplete="username" autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Contraseña</label>
                <input class="form-control" type="password" id="password" name="password" required maxlength="72" autocomplete="current-password">
            </div>
            <button class="btn btn-fc btn-lg w-100" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-shield-lock me-1"></i>Ingresar al panel</button>
        </form>
        <p class="text-center small mt-4 mb-0"><a href="<?= url('index.php') ?>"><i class="bi bi-arrow-left"></i> Volver a la tienda</a></p>
    </div>
</main>
<script src="<?= asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
