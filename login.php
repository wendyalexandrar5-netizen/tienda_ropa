<?php
require __DIR__ . '/includes/bootstrap.php';

$retorno = ruta_retorno_segura($_POST['retorno'] ?? $_GET['retorno'] ?? '', '');

if (esta_autenticado()) {
    redirigir($retorno !== '' ? $retorno : 'index.php');
}

$email = '';
$error = null;
if (es_post()) {
    $email    = mb_strtolower(post_texto('email', 120));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Ingresa tu correo y tu contraseña.';
    } else {
        $r = intentar_login($email, $password);
        if ($r['ok']) {
            iniciar_sesion_usuario($r['usuario']);
            $u = usuario_actual();
            flash('success', '¡Hola de nuevo, ' . $u['nombre'] . '!');
            if ($retorno === '' && $r['usuario']['rol'] === ROL_ADMIN) {
                redirigir('admin/index.php');
            }
            redirigir($retorno !== '' ? $retorno : 'index.php');
        }
        $error = $r['error'];
    }
}

$titulo = 'Iniciar sesión';
$sin_contenedor = true;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/partials/auth_inicio.php';
?>
<h1 class="h3 text-center mb-1">Bienvenido</h1>
<p class="text-center text-muted-fc mb-4">Ingresa a tu cuenta para comprar y seguir tus pedidos.</p>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill mt-1"></i><div><?= e($error) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= url('login.php') ?>" class="needs-validation" novalidate data-cargando>
    <?= csrf_campo() ?>
    <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
    <div class="mb-3">
        <label class="form-label" for="email">Correo electrónico</label>
        <input class="form-control" type="email" id="email" name="email" required maxlength="120" autocomplete="email" value="<?= e($email) ?>" autofocus>
        <div class="invalid-feedback">Ingresa un correo válido.</div>
    </div>
    <div class="mb-2">
        <label class="form-label" for="password">Contraseña</label>
        <div class="input-group">
            <input class="form-control" type="password" id="password" name="password" required maxlength="72" autocomplete="current-password">
            <button class="btn btn-outline-secondary" type="button" data-ver-password="password" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
            <div class="invalid-feedback">Ingresa tu contraseña.</div>
        </div>
    </div>
    <div class="text-end mb-4"><a class="small" href="<?= url('recuperar.php') ?>">¿Olvidaste tu contraseña?</a></div>
    <button class="btn btn-fc w-100 btn-lg" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Ingresar</button>
</form>
<p class="text-center small mt-4 mb-0">¿No tienes cuenta? <a class="fw-bold" href="<?= url('registro.php', ['retorno' => $retorno]) ?>">Regístrate</a></p>
<?php
require __DIR__ . '/includes/partials/auth_fin.php';
require __DIR__ . '/includes/footer.php';
