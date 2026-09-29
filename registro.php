<?php
require __DIR__ . '/includes/bootstrap.php';

if (esta_autenticado()) {
    redirigir('index.php');
}
$retorno = ruta_retorno_segura($_POST['retorno'] ?? $_GET['retorno'] ?? '', '');

$d = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => '', 'direccion' => '', 'ciudad' => ''];
$errores = [];

if (es_post()) {
    $d = [
        'nombre'    => post_texto('nombre', 60),
        'apellido'  => post_texto('apellido', 60),
        'email'     => mb_strtolower(post_texto('email', 120)),
        'telefono'  => post_texto('telefono', 20),
        'direccion' => post_texto('direccion', 160),
        'ciudad'    => post_texto('ciudad', 80),
    ];
    $password  = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirmacion'] ?? '');

    $v = (new Validador())
        ->requerido('nombre', $d['nombre'], 'El nombre')->longitud('nombre', $d['nombre'], 2, 60, 'El nombre')->nombrePersona('nombre', $d['nombre'], 'El nombre')
        ->requerido('apellido', $d['apellido'], 'El apellido')->longitud('apellido', $d['apellido'], 2, 60, 'El apellido')->nombrePersona('apellido', $d['apellido'], 'El apellido')
        ->requerido('email', $d['email'], 'El correo')->email('email', $d['email'])
        ->requerido('telefono', $d['telefono'], 'El teléfono')->telefono('telefono', $d['telefono'])
        ->requerido('direccion', $d['direccion'], 'La dirección')->longitud('direccion', $d['direccion'], 5, 160, 'La dirección')
        ->longitud('ciudad', $d['ciudad'], 2, 80, 'La ciudad')
        ->requerido('password', $password, 'La contraseña')->password('password', $password)
        ->coincide('password_confirmacion', $password, $password2, 'Las contraseñas no coinciden.');
    if (!isset($_POST['acepto'])) {
        $v->agregar('acepto', 'Debes aceptar el tratamiento de datos.');
    }

    if ($v->ok()) {
        try {
            consulta('INSERT INTO usuarios (nombre, apellido, email, password_hash, telefono, direccion, ciudad, rol)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $d['nombre'], $d['apellido'], $d['email'], password_hash($password, PASSWORD_DEFAULT),
                $d['telefono'], $d['direccion'], $d['ciudad'] ?: null, ROL_CLIENTE,   // el rol NUNCA viene del formulario
            ]);
            $nuevo = fila('SELECT id, rol FROM usuarios WHERE id = ?', [db()->lastInsertId()]);
            iniciar_sesion_usuario($nuevo);
            flash('success', '¡Bienvenido a ' . ajuste('nombre_tienda') . ', ' . $d['nombre'] . '! Tu cuenta fue creada.');
            redirigir($retorno !== '' ? $retorno : 'productos.php');
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $v->agregar('email', 'No fue posible registrar este correo. Si ya tienes cuenta, inicia sesión o recupera tu contraseña.');
        }
    }
    $errores = $v->errores();
}

$titulo = 'Crear cuenta';
$sin_contenedor = true;
$auth_ancha = true;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/partials/auth_inicio.php';
?>
<h1 class="h3 text-center mb-1">Crea tu cuenta</h1>
<p class="text-center text-muted-fc mb-4">Guarda tu carrito, compra más rápido y sigue tus pedidos.</p>
<?php if ($errores): ?>
    <div class="alert alert-danger small" role="alert"><i class="bi bi-exclamation-octagon-fill me-1"></i>Revisa los campos marcados.</div>
<?php endif; ?>
<form method="post" action="<?= url('registro.php') ?>" class="needs-validation" novalidate data-cargando>
    <?= csrf_campo() ?>
    <input type="hidden" name="retorno" value="<?= e($retorno) ?>">
    <div class="row g-3">
        <div class="col-sm-6">
            <label class="form-label" for="nombre">Nombre *</label>
            <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required minlength="2" maxlength="60" value="<?= e($d['nombre']) ?>" autocomplete="given-name">
            <?= error_campo($errores, 'nombre') ?: '<div class="invalid-feedback">Ingresa tu nombre.</div>' ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="apellido">Apellido *</label>
            <input class="form-control<?= clase_invalida($errores, 'apellido') ?>" id="apellido" name="apellido" required minlength="2" maxlength="60" value="<?= e($d['apellido']) ?>" autocomplete="family-name">
            <?= error_campo($errores, 'apellido') ?: '<div class="invalid-feedback">Ingresa tu apellido.</div>' ?>
        </div>
        <div class="col-sm-7">
            <label class="form-label" for="email">Correo electrónico *</label>
            <input class="form-control<?= clase_invalida($errores, 'email') ?>" type="email" id="email" name="email" required maxlength="120" value="<?= e($d['email']) ?>" autocomplete="email">
            <?= error_campo($errores, 'email') ?: '<div class="invalid-feedback">Ingresa un correo válido.</div>' ?>
        </div>
        <div class="col-sm-5">
            <label class="form-label" for="telefono">Teléfono *</label>
            <input class="form-control<?= clase_invalida($errores, 'telefono') ?>" type="tel" id="telefono" name="telefono" required pattern="\+?[0-9 ]{7,20}" maxlength="20" value="<?= e($d['telefono']) ?>" autocomplete="tel">
            <?= error_campo($errores, 'telefono') ?: '<div class="invalid-feedback">7 a 20 dígitos.</div>' ?>
        </div>
        <div class="col-sm-8">
            <label class="form-label" for="direccion">Dirección *</label>
            <input class="form-control<?= clase_invalida($errores, 'direccion') ?>" id="direccion" name="direccion" required minlength="5" maxlength="160" value="<?= e($d['direccion']) ?>" autocomplete="street-address">
            <?= error_campo($errores, 'direccion') ?: '<div class="invalid-feedback">Ingresa tu dirección.</div>' ?>
        </div>
        <div class="col-sm-4">
            <label class="form-label" for="ciudad">Ciudad</label>
            <input class="form-control<?= clase_invalida($errores, 'ciudad') ?>" id="ciudad" name="ciudad" maxlength="80" value="<?= e($d['ciudad']) ?>" autocomplete="address-level2">
            <?= error_campo($errores, 'ciudad') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="password">Contraseña *</label>
            <div class="input-group">
                <input class="form-control<?= clase_invalida($errores, 'password') ?>" type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-medidor="medidor">
                <button class="btn btn-outline-secondary" type="button" data-ver-password="password" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
            </div>
            <div class="medidor-password mt-2" id="medidor"><div></div></div>
            <div class="form-text">Mín. 8 caracteres, con mayúsculas, minúsculas y números.</div>
            <?= error_campo($errores, 'password') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="password_confirmacion">Confirmar contraseña *</label>
            <input class="form-control<?= clase_invalida($errores, 'password_confirmacion') ?>" type="password" id="password_confirmacion" name="password_confirmacion" required maxlength="72" autocomplete="new-password" data-igual-a="password">
            <?= error_campo($errores, 'password_confirmacion') ?: '<div class="invalid-feedback">Las contraseñas no coinciden.</div>' ?>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input<?= clase_invalida($errores, 'acepto') ?>" type="checkbox" id="acepto" name="acepto" value="1" required <?= isset($_POST['acepto']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="acepto">Acepto el tratamiento de mis datos personales para gestionar mis compras.</label>
                <?= error_campo($errores, 'acepto') ?>
            </div>
        </div>
    </div>
    <button class="btn btn-fc w-100 btn-lg mt-4" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Crear cuenta</button>
</form>
<p class="text-center small mt-4 mb-0">¿Ya tienes cuenta? <a class="fw-bold" href="<?= url('login.php', ['retorno' => $retorno]) ?>">Inicia sesión</a></p>
<?php
require __DIR__ . '/includes/partials/auth_fin.php';
require __DIR__ . '/includes/footer.php';
