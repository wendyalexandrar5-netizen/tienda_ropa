<?php
/** Recuperación de contraseña (paso 2): valida el token y define la nueva contraseña. */
require __DIR__ . '/includes/bootstrap.php';

$token = es_post() ? post_texto('token', 64) : get_texto('token', 64);
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $reset = fila("SELECT r.id, r.usuario_id FROM password_resets r JOIN usuarios u ON u.id = r.usuario_id
                   WHERE r.token_hash = ? AND r.usado = 0 AND r.expira > NOW() AND u.estado = 'activo'", [hash('sha256', $token)]);
}

$errores = [];
if ($reset && es_post()) {
    $password  = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirmacion'] ?? '');
    $v = (new Validador())->requerido('password', $password, 'La contraseña')->password('password', $password)
        ->coincide('password_confirmacion', $password, $password2, 'Las contraseñas no coinciden.');
    if ($v->ok()) {
        actualizar_password((int) $reset['usuario_id'], $password);
        consulta('DELETE FROM intentos_login WHERE email = (SELECT email FROM usuarios WHERE id = ?)', [$reset['usuario_id']]);
        flash('success', 'Tu contraseña fue actualizada. Ya puedes iniciar sesión.');
        redirigir('login.php');
    }
    $errores = $v->errores();
}

$titulo = 'Nueva contraseña';
$sin_contenedor = true;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/partials/auth_inicio.php';
?>
<h1 class="h3 text-center mb-3">Crea una nueva contraseña</h1>
<?php if (!$reset): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i>El enlace no es válido o ya expiró. Solicita uno nuevo.</div>
    <a class="btn btn-fc w-100" href="<?= url('recuperar.php') ?>">Solicitar nuevo enlace</a>
<?php else: ?>
    <form method="post" action="<?= url('restablecer.php') ?>" class="needs-validation" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3">
            <label class="form-label" for="password">Nueva contraseña</label>
            <input class="form-control<?= clase_invalida($errores, 'password') ?>" type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-medidor="medidor">
            <div class="medidor-password mt-2" id="medidor"><div></div></div>
            <div class="form-text">Mín. 8 caracteres, con mayúsculas, minúsculas y números.</div>
            <?= error_campo($errores, 'password') ?>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmacion">Confirmar contraseña</label>
            <input class="form-control<?= clase_invalida($errores, 'password_confirmacion') ?>" type="password" id="password_confirmacion" name="password_confirmacion" required maxlength="72" autocomplete="new-password" data-igual-a="password">
            <?= error_campo($errores, 'password_confirmacion') ?: '<div class="invalid-feedback">Las contraseñas no coinciden.</div>' ?>
        </div>
        <button class="btn btn-fc w-100 btn-lg" type="submit">Guardar contraseña</button>
    </form>
<?php endif; ?>
<?php
require __DIR__ . '/includes/partials/auth_fin.php';
require __DIR__ . '/includes/footer.php';
