<?php
/**
 * Recuperación de contraseña (paso 1): genera un token aleatorio de un solo uso.
 * En la BD sólo se guarda su hash SHA-256; el token viaja únicamente en el enlace.
 * La respuesta es SIEMPRE la misma, exista o no el correo (evita enumerar usuarios).
 */
require __DIR__ . '/includes/bootstrap.php';

if (esta_autenticado()) {
    redirigir('perfil.php');
}

$enviado = false;
$enlaceDev = null;
$email = '';
if (es_post()) {
    $email = mb_strtolower(post_texto('email', 120));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $u = fila("SELECT id, nombre FROM usuarios WHERE email = ? AND estado = 'activo'", [$email]);
        $recientes = $u ? (int) valor('SELECT COUNT(*) FROM password_resets WHERE usuario_id = ? AND fecha_creacion > (NOW() - INTERVAL 1 HOUR)', [$u['id']]) : 0;
        if ($u && $recientes < 3) {
            $token = bin2hex(random_bytes(32));
            $minutos = (int) config('reset_minutos_validez', 60);
            consulta("INSERT INTO password_resets (usuario_id, token_hash, expira) VALUES (?, ?, NOW() + INTERVAL $minutos MINUTE)",
                [$u['id'], hash('sha256', $token)]);
            $enlace = (es_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('restablecer.php', ['token' => $token]);
            // Simulación de envío de correo (XAMPP no trae servidor SMTP configurado).
            registrar_log("Para: $email | Asunto: Recupera tu contraseña | Enlace: $enlace", 'correo.log');
            if (es_desarrollo()) {
                $enlaceDev = $enlace;
            }
        }
        $enviado = true;
    }
    if (!$enviado) {
        flash('danger', 'Ingresa un correo electrónico válido.');
        redirigir('recuperar.php');
    }
}

$titulo = 'Recuperar contraseña';
$sin_contenedor = true;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/partials/auth_inicio.php';
?>
<h1 class="h3 text-center mb-1">Recuperar contraseña</h1>
<?php if ($enviado): ?>
    <div class="alert alert-success mt-3"><i class="bi bi-envelope-check me-1"></i>
        Si el correo está registrado, recibirás un enlace para restablecer tu contraseña. El enlace vence en <?= (int) config('reset_minutos_validez', 60) ?> minutos.</div>
    <?php if ($enlaceDev): ?>
        <div class="alert alert-warning small">
            <strong>Modo desarrollo:</strong> como XAMPP no envía correos, este es el enlace que se habría enviado
            (también queda en <code>storage/logs/correo.log</code>):<br>
            <a class="text-break" href="<?= e($enlaceDev) ?>"><?= e($enlaceDev) ?></a>
        </div>
    <?php endif; ?>
    <a class="btn btn-outline-fc w-100" href="<?= url('login.php') ?>">Volver a iniciar sesión</a>
<?php else: ?>
    <p class="text-center text-muted-fc mb-4">Escribe el correo de tu cuenta y te enviaremos un enlace para crear una nueva contraseña.</p>
    <form method="post" action="<?= url('recuperar.php') ?>" class="needs-validation" novalidate data-cargando>
        <?= csrf_campo() ?>
        <div class="mb-4">
            <label class="form-label" for="email">Correo electrónico</label>
            <input class="form-control" type="email" id="email" name="email" required maxlength="120" value="<?= e($email) ?>" autocomplete="email">
            <div class="invalid-feedback">Ingresa un correo válido.</div>
        </div>
        <button class="btn btn-fc w-100 btn-lg" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Enviar enlace</button>
    </form>
    <p class="text-center small mt-4 mb-0"><a href="<?= url('login.php') ?>"><i class="bi bi-arrow-left"></i> Volver</a></p>
<?php endif; ?>
<?php
require __DIR__ . '/includes/partials/auth_fin.php';
require __DIR__ . '/includes/footer.php';
