<?php
/**
 * Cierre de sesión. Sólo por POST con token CSRF (un enlace GET permitiría que
 * otro sitio cerrara la sesión del usuario). Por GET se muestra una confirmación.
 */
require __DIR__ . '/includes/bootstrap.php';

if (es_post()) {
    $eraAdmin = es_admin();
    cerrar_sesion_usuario();
    iniciar_sesion_segura();
    flash('success', 'Cerraste sesión correctamente. ¡Vuelve pronto!');
    redirigir($eraAdmin ? 'admin/login.php' : 'index.php');
}

if (!esta_autenticado()) {
    redirigir('index.php');
}

$titulo = 'Cerrar sesión';
$sin_contenedor = true;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/partials/auth_inicio.php';
?>
<h1 class="h4 text-center mb-2">¿Deseas cerrar sesión?</h1>
<p class="text-center text-muted-fc mb-4">Tu carrito quedará guardado para la próxima vez.</p>
<form method="post" action="<?= url('logout.php') ?>" class="d-grid gap-2">
    <?= csrf_campo() ?>
    <button class="btn btn-fc btn-lg" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Sí, cerrar sesión</button>
    <a class="btn btn-outline-fc" href="<?= url('index.php') ?>">Cancelar</a>
</form>
<?php
require __DIR__ . '/includes/partials/auth_fin.php';
require __DIR__ . '/includes/footer.php';
