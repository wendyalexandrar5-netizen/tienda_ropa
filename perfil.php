<?php
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$errores = [];
$d = $usuario;

if (es_post()) {
    $d = array_merge($usuario, [
        'nombre'    => post_texto('nombre', 60),
        'apellido'  => post_texto('apellido', 60),
        'email'     => mb_strtolower(post_texto('email', 120)),
        'telefono'  => post_texto('telefono', 20),
        'direccion' => post_texto('direccion', 160),
        'ciudad'    => post_texto('ciudad', 80),
    ]);
    $v = (new Validador())
        ->requerido('nombre', $d['nombre'], 'El nombre')->longitud('nombre', $d['nombre'], 2, 60, 'El nombre')->nombrePersona('nombre', $d['nombre'], 'El nombre')
        ->requerido('apellido', $d['apellido'], 'El apellido')->longitud('apellido', $d['apellido'], 2, 60, 'El apellido')->nombrePersona('apellido', $d['apellido'], 'El apellido')
        ->requerido('email', $d['email'], 'El correo')->email('email', $d['email'])
        ->requerido('telefono', $d['telefono'], 'El teléfono')->telefono('telefono', $d['telefono'])
        ->requerido('direccion', $d['direccion'], 'La dirección')->longitud('direccion', $d['direccion'], 5, 160, 'La dirección')
        ->longitud('ciudad', $d['ciudad'], 2, 80, 'La ciudad');

    // Cambiar el correo exige confirmar la contraseña actual.
    if ($d['email'] !== $usuario['email']) {
        $hash = (string) valor('SELECT password_hash FROM usuarios WHERE id = ?', [$usuario['id']]);
        if (!password_verify((string) ($_POST['password_actual'] ?? ''), $hash)) {
            $v->agregar('password_actual', 'Para cambiar el correo confirma tu contraseña actual.');
        } elseif (valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$d['email'], $usuario['id']])) {
            $v->agregar('email', 'Ese correo no está disponible.');
        }
    }

    if ($v->ok()) {
        consulta('UPDATE usuarios SET nombre = ?, apellido = ?, email = ?, telefono = ?, direccion = ?, ciudad = ? WHERE id = ?',
            [$d['nombre'], $d['apellido'], $d['email'], $d['telefono'], $d['direccion'], $d['ciudad'] ?: null, $usuario['id']]);
        flash('success', 'Tus datos fueron actualizados.');
        redirigir('perfil.php');
    }
    $errores = $v->errores();
}

$stats = fila("SELECT COUNT(*) AS pedidos, COALESCE(SUM(CASE WHEN estado <> 'cancelado' THEN total END), 0) AS gastado
               FROM pedidos WHERE usuario_id = ?", [$usuario['id']]);

$titulo = 'Mi perfil';
$seccion = 'perfil';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= url('index.php') ?>">Inicio</a></li><li class="breadcrumb-item active">Mi perfil</li></ol></nav>
    <div class="row g-4">
        <aside class="col-lg-3"><?php require __DIR__ . '/includes/partials/menu_cuenta.php'; ?></aside>
        <section class="col-lg-9">
            <h1 class="h2 mb-4">Mi perfil</h1>
            <div class="row g-3 mb-4">
                <div class="col-sm-4"><div class="caja"><div class="eyebrow">Pedidos</div><div class="fs-3 fw-bold"><?= (int) $stats['pedidos'] ?></div></div></div>
                <div class="col-sm-4"><div class="caja"><div class="eyebrow">Total comprado</div><div class="fs-3 fw-bold"><?= precio($stats['gastado']) ?></div></div></div>
                <div class="col-sm-4"><div class="caja"><div class="eyebrow">Cliente desde</div><div class="fs-3 fw-bold"><?= fecha($usuario['fecha_registro'], false) ?></div></div></div>
            </div>
            <form method="post" action="<?= url('perfil.php') ?>" class="caja needs-validation" novalidate>
                <?= csrf_campo() ?>
                <div class="caja-titulo">Datos personales</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="nombre">Nombre *</label>
                        <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required minlength="2" maxlength="60" value="<?= e($d['nombre']) ?>">
                        <?= error_campo($errores, 'nombre') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="apellido">Apellido *</label>
                        <input class="form-control<?= clase_invalida($errores, 'apellido') ?>" id="apellido" name="apellido" required minlength="2" maxlength="60" value="<?= e($d['apellido']) ?>">
                        <?= error_campo($errores, 'apellido') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Correo electrónico *</label>
                        <input class="form-control<?= clase_invalida($errores, 'email') ?>" type="email" id="email" name="email" required maxlength="120" value="<?= e($d['email']) ?>">
                        <?= error_campo($errores, 'email') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="telefono">Teléfono *</label>
                        <input class="form-control<?= clase_invalida($errores, 'telefono') ?>" type="tel" id="telefono" name="telefono" required pattern="\+?[0-9 ]{7,20}" maxlength="20" value="<?= e($d['telefono']) ?>">
                        <?= error_campo($errores, 'telefono') ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="direccion">Dirección *</label>
                        <input class="form-control<?= clase_invalida($errores, 'direccion') ?>" id="direccion" name="direccion" required minlength="5" maxlength="160" value="<?= e($d['direccion']) ?>">
                        <?= error_campo($errores, 'direccion') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ciudad">Ciudad</label>
                        <input class="form-control<?= clase_invalida($errores, 'ciudad') ?>" id="ciudad" name="ciudad" maxlength="80" value="<?= e($d['ciudad']) ?>">
                        <?= error_campo($errores, 'ciudad') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password_actual">Contraseña actual <span class="text-muted-fc fw-normal">(sólo si cambias el correo)</span></label>
                        <input class="form-control<?= clase_invalida($errores, 'password_actual') ?>" type="password" id="password_actual" name="password_actual" maxlength="72" autocomplete="current-password">
                        <?= error_campo($errores, 'password_actual') ?>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-4"><button class="btn btn-fc" type="submit"><i class="bi bi-check2 me-1"></i>Guardar cambios</button></div>
            </form>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
