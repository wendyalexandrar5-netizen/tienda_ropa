<?php
/**
 * Cambio de contraseña. Es obligatorio para cuentas marcadas con
 * debe_cambiar_password (p. ej. el administrador inicial del script SQL).
 */
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$obligatorio = (int) $usuario['debe_cambiar_password'] === 1;
$errores = [];

if (es_post()) {
    $actual    = (string) ($_POST['password_actual'] ?? '');
    $nueva     = (string) ($_POST['password'] ?? '');
    $nueva2    = (string) ($_POST['password_confirmacion'] ?? '');
    $hash      = (string) valor('SELECT password_hash FROM usuarios WHERE id = ?', [$usuario['id']]);

    $v = new Validador();
    if (!password_verify($actual, $hash)) {
        $v->agregar('password_actual', 'La contraseña actual no es correcta.');
    }
    $v->requerido('password', $nueva, 'La nueva contraseña')->password('password', $nueva)
      ->coincide('password_confirmacion', $nueva, $nueva2, 'Las contraseñas no coinciden.');
    if ($nueva !== '' && hash_equals($actual, $nueva)) {
        $v->agregar('password', 'La nueva contraseña debe ser diferente a la actual.');
    }
    if ($v->ok()) {
        actualizar_password((int) $usuario['id'], $nueva);
        session_regenerate_id(true);
        flash('success', 'Tu contraseña fue actualizada correctamente.');
        redirigir($usuario['rol'] === ROL_ADMIN ? 'admin/index.php' : 'perfil.php');
    }
    $errores = $v->errores();
}

$titulo = 'Cambiar contraseña';
$sin_contenedor = true;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/partials/auth_inicio.php';
?>
<h1 class="h3 text-center mb-1">Cambiar contraseña</h1>
<?php if ($obligatorio): ?>
    <div class="alert alert-warning small mt-3"><i class="bi bi-shield-exclamation me-1"></i>Estás usando una contraseña inicial de desarrollo. Debes cambiarla para continuar.</div>
<?php else: ?>
    <p class="text-center text-muted-fc mb-4">Usa una contraseña segura que no uses en otros sitios.</p>
<?php endif; ?>
<form method="post" action="<?= url('cambiar_password.php') ?>" class="needs-validation" novalidate>
    <?= csrf_campo() ?>
    <div class="mb-3">
        <label class="form-label" for="password_actual">Contraseña actual</label>
        <input class="form-control<?= clase_invalida($errores, 'password_actual') ?>" type="password" id="password_actual" name="password_actual" required maxlength="72" autocomplete="current-password">
        <?= error_campo($errores, 'password_actual') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Nueva contraseña</label>
        <input class="form-control<?= clase_invalida($errores, 'password') ?>" type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-medidor="medidor">
        <div class="medidor-password mt-2" id="medidor"><div></div></div>
        <div class="form-text">Mín. 8 caracteres, con mayúsculas, minúsculas y números.</div>
        <?= error_campo($errores, 'password') ?>
    </div>
    <div class="mb-4">
        <label class="form-label" for="password_confirmacion">Confirmar nueva contraseña</label>
        <input class="form-control<?= clase_invalida($errores, 'password_confirmacion') ?>" type="password" id="password_confirmacion" name="password_confirmacion" required maxlength="72" autocomplete="new-password" data-igual-a="password">
        <?= error_campo($errores, 'password_confirmacion') ?: '<div class="invalid-feedback">Las contraseñas no coinciden.</div>' ?>
    </div>
    <button class="btn btn-fc w-100 btn-lg" type="submit">Actualizar contraseña</button>
</form>
<?php if (!$obligatorio): ?>
    <p class="text-center small mt-3 mb-0"><a href="<?= url('perfil.php') ?>">Cancelar</a></p>
<?php else: ?>
    <form method="post" action="<?= url('logout.php') ?>" class="text-center mt-3"><?= csrf_campo() ?><button class="btn btn-link btn-sm text-muted-fc">Cerrar sesión</button></form>
<?php endif; ?>
<?php
require __DIR__ . '/includes/partials/auth_fin.php';
require __DIR__ . '/includes/footer.php';
