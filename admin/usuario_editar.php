<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$id = es_post() ? post_int('usuario_id') : get_int('id');
$usuario = $id ? fila('SELECT * FROM usuarios WHERE id = ?', [$id]) : null;
if ($id && !$usuario) {
    error_404('El usuario no existe.');
}
$esNuevo = $usuario === null;
$esYo = !$esNuevo && (int) $usuario['id'] === (int) $admin['id'];
$d = $usuario ?? ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => '', 'direccion' => '', 'ciudad' => '', 'rol' => ROL_CLIENTE, 'estado' => 'activo'];
$errores = [];
$passwordTemporal = null;

/** Contraseña temporal que cumple la política (se muestra una sola vez). */
function generar_password_temporal(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 9; $i++) {
        $p .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return 'Fc' . $p . random_int(10, 99) . '*';
}

if (es_post() && post_texto('accion', 20) === 'password_temporal' && !$esNuevo) {
    $passwordTemporal = generar_password_temporal();
    consulta('UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 1 WHERE id = ?', [password_hash($passwordTemporal, PASSWORD_DEFAULT), $id]);
    registrar_log("Admin {$admin['id']} generó contraseña temporal para usuario $id", 'seguridad.log');
    flash('success', 'Contraseña temporal generada. Compártela de forma segura: el usuario deberá cambiarla al ingresar.');
} elseif (es_post()) {
    $d = [
        'nombre'    => post_texto('nombre', 60),
        'apellido'  => post_texto('apellido', 60),
        'email'     => mb_strtolower(post_texto('email', 120)),
        'telefono'  => post_texto('telefono', 20),
        'direccion' => post_texto('direccion', 160),
        'ciudad'    => post_texto('ciudad', 80),
        'rol'       => post_texto('rol', 20),
        'estado'    => post_texto('estado', 10),
    ];
    $v = (new Validador())
        ->requerido('nombre', $d['nombre'], 'El nombre')->longitud('nombre', $d['nombre'], 2, 60, 'El nombre')->nombrePersona('nombre', $d['nombre'], 'El nombre')
        ->requerido('apellido', $d['apellido'], 'El apellido')->longitud('apellido', $d['apellido'], 2, 60, 'El apellido')->nombrePersona('apellido', $d['apellido'], 'El apellido')
        ->requerido('email', $d['email'], 'El correo')->email('email', $d['email'])
        ->telefono('telefono', $d['telefono'])
        ->longitud('direccion', $d['direccion'], 0, 160, 'La dirección')->longitud('ciudad', $d['ciudad'], 0, 80, 'La ciudad')
        ->enLista('rol', $d['rol'], [ROL_ADMIN, ROL_CLIENTE], 'El rol')->enLista('estado', $d['estado'], ['activo', 'inactivo'], 'El estado');
    if (valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$d['email'], $id])) {
        $v->agregar('email', 'Ya existe una cuenta con ese correo.');
    }
    if ($esYo && ($d['rol'] !== ROL_ADMIN || $d['estado'] !== 'activo')) {
        $v->agregar('rol', 'No puedes quitarte el rol de administrador ni desactivar tu propia cuenta.');
    }
    if (!$esNuevo && $usuario['rol'] === ROL_ADMIN && ($d['rol'] !== ROL_ADMIN || $d['estado'] !== 'activo')
        && (int) valor("SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador' AND estado = 'activo' AND id <> ?", [$id]) === 0) {
        $v->agregar('rol', 'Debe existir al menos un administrador activo.');
    }

    if ($v->ok()) {
        $campos = [$d['nombre'], $d['apellido'], $d['email'], $d['telefono'] ?: null, $d['direccion'] ?: null, $d['ciudad'] ?: null, $d['rol'], $d['estado']];
        if ($esNuevo) {
            $passwordTemporal = generar_password_temporal();
            consulta('INSERT INTO usuarios (nombre, apellido, email, telefono, direccion, ciudad, rol, estado, password_hash, debe_cambiar_password)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)', array_merge($campos, [password_hash($passwordTemporal, PASSWORD_DEFAULT)]));
            $id = (int) db()->lastInsertId();
            $usuario = fila('SELECT * FROM usuarios WHERE id = ?', [$id]);
            $esNuevo = false;
            flash('success', "Usuario creado. Contraseña temporal generada (deberá cambiarla al ingresar).");
        } else {
            consulta('UPDATE usuarios SET nombre = ?, apellido = ?, email = ?, telefono = ?, direccion = ?, ciudad = ?, rol = ?, estado = ? WHERE id = ?',
                array_merge($campos, [$id]));
            flash('success', 'Datos del usuario actualizados.');
            redirigir('admin/usuario_editar.php', ['id' => $id]);
        }
    } else {
        $errores = $v->errores();
        flash('danger', 'Revisa los campos marcados.');
    }
}

$pedidos = $usuario ? filas('SELECT id, codigo, total, estado, fecha_pedido FROM pedidos WHERE usuario_id = ? ORDER BY fecha_pedido DESC LIMIT 10', [$usuario['id']]) : [];

$titulo = $esNuevo ? 'Nuevo usuario' : 'Usuario: ' . $usuario['nombre'] . ' ' . $usuario['apellido'];
$seccion = 'usuarios';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/usuarios.php', ['rol' => $d['rol']]) . '"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>';
require __DIR__ . '/includes/header.php';
?>
<?php if ($passwordTemporal): ?>
    <div class="alert alert-warning d-flex gap-3 align-items-center">
        <i class="bi bi-key fs-3"></i>
        <div>Contraseña temporal (se muestra sólo esta vez): <code class="fs-5 user-select-all"><?= e($passwordTemporal) ?></code><br>
            <small>No se guarda en texto plano: en la base de datos sólo queda su hash bcrypt.</small></div>
    </div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-xl-7">
        <form method="post" action="<?= url('admin/usuario_editar.php') ?>" class="tarjeta-admin needs-validation" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="usuario_id" value="<?= (int) ($usuario['id'] ?? 0) ?>">
            <div class="cabecera"><h2>Datos de la cuenta</h2><?php if (!$esNuevo): ?><span class="small text-muted-fc">Registro: <?= fecha($usuario['fecha_registro']) ?></span><?php endif; ?></div>
            <div class="cuerpo row g-3">
                <div class="col-md-6"><label class="form-label" for="nombre">Nombre *</label><input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required maxlength="60" value="<?= e($d['nombre']) ?>"><?= error_campo($errores, 'nombre') ?></div>
                <div class="col-md-6"><label class="form-label" for="apellido">Apellido *</label><input class="form-control<?= clase_invalida($errores, 'apellido') ?>" id="apellido" name="apellido" required maxlength="60" value="<?= e($d['apellido']) ?>"><?= error_campo($errores, 'apellido') ?></div>
                <div class="col-md-7"><label class="form-label" for="email">Correo *</label><input class="form-control<?= clase_invalida($errores, 'email') ?>" type="email" id="email" name="email" required maxlength="120" value="<?= e($d['email']) ?>"><?= error_campo($errores, 'email') ?></div>
                <div class="col-md-5"><label class="form-label" for="telefono">Teléfono</label><input class="form-control<?= clase_invalida($errores, 'telefono') ?>" id="telefono" name="telefono" maxlength="20" value="<?= e($d['telefono']) ?>"><?= error_campo($errores, 'telefono') ?></div>
                <div class="col-md-8"><label class="form-label" for="direccion">Dirección</label><input class="form-control" id="direccion" name="direccion" maxlength="160" value="<?= e($d['direccion']) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="ciudad">Ciudad</label><input class="form-control" id="ciudad" name="ciudad" maxlength="80" value="<?= e($d['ciudad']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="rol">Rol</label>
                    <select class="form-select<?= clase_invalida($errores, 'rol') ?>" id="rol" name="rol" <?= $esYo ? 'disabled' : '' ?>>
                        <option value="cliente" <?= $d['rol'] === ROL_CLIENTE ? 'selected' : '' ?>>Cliente</option>
                        <option value="administrador" <?= $d['rol'] === ROL_ADMIN ? 'selected' : '' ?>>Administrador</option>
                    </select>
                    <?php if ($esYo): ?><input type="hidden" name="rol" value="<?= e($d['rol']) ?>"><?php endif; ?>
                    <?= error_campo($errores, 'rol') ?></div>
                <div class="col-md-6"><label class="form-label" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado" <?= $esYo ? 'disabled' : '' ?>>
                        <option value="activo" <?= $d['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option>
                        <option value="inactivo" <?= $d['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo (no puede ingresar)</option>
                    </select>
                    <?php if ($esYo): ?><input type="hidden" name="estado" value="<?= e($d['estado']) ?>"><?php endif; ?></div>
                <?php if ($esNuevo): ?><div class="col-12"><div class="form-text"><i class="bi bi-info-circle me-1"></i>Se generará una contraseña temporal que el usuario deberá cambiar en su primer ingreso.</div></div><?php endif; ?>
                <div class="col-12 text-end"><button class="btn btn-fc" type="submit"><i class="bi bi-check2 me-1"></i><?= $esNuevo ? 'Crear usuario' : 'Guardar cambios' ?></button></div>
            </div>
        </form>
    </div>
    <div class="col-xl-5">
        <?php if (!$esNuevo): ?>
            <div class="tarjeta-admin mb-3">
                <div class="cabecera"><h2>Seguridad</h2></div>
                <div class="cuerpo">
                    <p class="small text-muted-fc">Las contraseñas nunca se pueden ver. Puedes generar una temporal: el usuario deberá cambiarla al iniciar sesión.</p>
                    <?php if ((int) $usuario['debe_cambiar_password']): ?><p class="small"><span class="badge text-bg-warning">Cambio de contraseña pendiente</span></p><?php endif; ?>
                    <form method="post" action="<?= url('admin/usuario_editar.php') ?>" data-confirmar="¿Generar una nueva contraseña temporal para este usuario? La actual dejará de funcionar.">
                        <?= csrf_campo() ?><input type="hidden" name="usuario_id" value="<?= (int) $usuario['id'] ?>"><input type="hidden" name="accion" value="password_temporal">
                        <button class="btn btn-outline-fc btn-sm" <?= $esYo ? 'disabled' : '' ?>><i class="bi bi-key me-1"></i>Generar contraseña temporal</button>
                    </form>
                </div>
            </div>
            <div class="tarjeta-admin">
                <div class="cabecera"><h2>Últimos pedidos</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($pedidos as $p): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-3">
                            <div><a class="fw-bold" href="<?= url('admin/pedido_detalle.php', ['id' => $p['id']]) ?>"><?= e($p['codigo']) ?></a><div class="small text-muted-fc"><?= fecha($p['fecha_pedido']) ?></div></div>
                            <div class="text-end"><?= estado_pedido_badge($p['estado']) ?><div class="small fw-semibold"><?= precio($p['total']) ?></div></div>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$pedidos): ?><li class="list-group-item text-muted-fc px-3">Sin pedidos.</li><?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
