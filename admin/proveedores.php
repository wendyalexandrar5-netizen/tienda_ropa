<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$errores = [];
$vacio = ['nombre' => '', 'nit' => '', 'contacto' => '', 'telefono' => '', 'email' => '', 'direccion' => '', 'ciudad' => '', 'notas' => '', 'estado' => 'activo'];
$d = $vacio;
$editar = null;

if (es_post()) {
    $accion = post_texto('accion', 20);
    $id = post_int('proveedor_id');
    $prov = $id ? fila('SELECT * FROM proveedores WHERE id = ?', [$id]) : null;

    if ($accion === 'guardar') {
        $d = [
            'nombre' => post_texto('nombre', 120), 'nit' => post_texto('nit', 20), 'contacto' => post_texto('contacto', 80),
            'telefono' => post_texto('telefono', 20), 'email' => mb_strtolower(post_texto('email', 120)),
            'direccion' => post_texto('direccion', 160), 'ciudad' => post_texto('ciudad', 80), 'notas' => post_texto('notas', 255),
            'estado' => post_texto('estado', 10),
        ];
        $v = (new Validador())
            ->requerido('nombre', $d['nombre'], 'El nombre')->longitud('nombre', $d['nombre'], 3, 120, 'El nombre')
            ->email('email', $d['email'])->telefono('telefono', $d['telefono'])
            ->enLista('estado', $d['estado'], ['activo', 'inactivo'], 'El estado');
        if ($d['nit'] !== '' && !preg_match('/^[0-9.\-]{5,20}$/', $d['nit'])) {
            $v->agregar('nit', 'El NIT sólo puede tener números, puntos y guion.');
        }
        if (valor('SELECT id FROM proveedores WHERE nombre = ? AND id <> ?', [$d['nombre'], $id])) {
            $v->agregar('nombre', 'Ya existe un proveedor con ese nombre.');
        }
        if ($d['nit'] !== '' && valor('SELECT id FROM proveedores WHERE nit = ? AND id <> ?', [$d['nit'], $id])) {
            $v->agregar('nit', 'Ya existe un proveedor con ese NIT.');
        }
        if ($v->ok()) {
            $campos = [$d['nombre'], $d['nit'] ?: null, $d['contacto'] ?: null, $d['telefono'] ?: null, $d['email'] ?: null,
                       $d['direccion'] ?: null, $d['ciudad'] ?: null, $d['notas'] ?: null, $d['estado']];
            if ($prov) {
                consulta('UPDATE proveedores SET nombre = ?, nit = ?, contacto = ?, telefono = ?, email = ?, direccion = ?, ciudad = ?, notas = ?, estado = ? WHERE id = ?',
                    array_merge($campos, [$id]));
                flash('success', "Proveedor «{$d['nombre']}» actualizado.");
            } else {
                consulta('INSERT INTO proveedores (nombre, nit, contacto, telefono, email, direccion, ciudad, notas, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $campos);
                flash('success', "Proveedor «{$d['nombre']}» creado.");
            }
            redirigir('admin/proveedores.php');
        }
        $errores = $v->errores();
        $editar = $prov;
        flash('danger', 'Revisa los campos marcados.');
    } elseif ($prov && $accion === 'estado') {
        consulta('UPDATE proveedores SET estado = ? WHERE id = ?', [$prov['estado'] === 'activo' ? 'inactivo' : 'activo', $id]);
        flash('success', "«{$prov['nombre']}» ahora está " . ($prov['estado'] === 'activo' ? 'inactivo.' : 'activo.'));
        redirigir('admin/proveedores.php');
    } elseif ($prov && $accion === 'eliminar') {
        if ((int) valor('SELECT COUNT(*) FROM compras_material WHERE proveedor_id = ?', [$id]) > 0) {
            flash('warning', "«{$prov['nombre']}» tiene compras registradas y no puede eliminarse; desactívalo.");
        } else {
            consulta('DELETE FROM proveedores WHERE id = ?', [$id]);
            flash('success', "Proveedor «{$prov['nombre']}» eliminado.");
        }
        redirigir('admin/proveedores.php');
    } else {
        flash('danger', 'Acción no válida.');
        redirigir('admin/proveedores.php');
    }
} elseif (get_int('editar')) {
    $editar = fila('SELECT * FROM proveedores WHERE id = ?', [get_int('editar')]);
    if ($editar) {
        $d = array_merge($vacio, array_map(static fn($x) => $x ?? '', $editar));
    }
}

$proveedores = filas("SELECT p.*, COUNT(c.id) AS compras, COALESCE(SUM(CASE WHEN c.estado = 'recibida' THEN c.total END), 0) AS comprado,
                             MAX(c.fecha) AS ultima_compra,
                             (SELECT COUNT(*) FROM materiales m WHERE m.proveedor_preferido_id = p.id) AS materiales
                      FROM proveedores p LEFT JOIN compras_material c ON c.proveedor_id = p.id
                      GROUP BY p.id ORDER BY p.estado, p.nombre");

$titulo = 'Proveedores';
$seccion = 'proveedores';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2><?= count($proveedores) ?> proveedor(es)</h2>
                <a class="btn btn-sm btn-fc" href="<?= url('admin/compra_crear.php') ?>"><i class="bi bi-cart-plus me-1"></i>Registrar compra</a></div>
            <div class="table-responsive">
                <table class="table tabla-admin table-hover">
                    <thead><tr><th>Proveedor</th><th>Contacto</th><th class="text-center">Compras</th><th class="text-end">Total comprado</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($proveedores as $p): ?>
                        <tr class="<?= $p['estado'] !== 'activo' ? 'opacity-50' : '' ?>">
                            <td><div class="fw-bold"><?= e($p['nombre']) ?></div>
                                <div class="small text-muted-fc"><?= $p['nit'] ? 'NIT ' . e($p['nit']) . ' · ' : '' ?><?= e($p['notas']) ?></div></td>
                            <td class="small"><?= e($p['contacto']) ?><div class="text-muted-fc"><?= e($p['telefono']) ?> <?= e($p['email']) ?></div><div class="text-muted-fc"><?= e($p['ciudad']) ?></div></td>
                            <td class="text-center"><?= (int) $p['compras'] ?><div class="small text-muted-fc"><?= $p['ultima_compra'] ? 'Última: ' . fecha($p['ultima_compra'], false) : '' ?></div></td>
                            <td class="text-end fw-semibold"><?= precio($p['comprado']) ?></td>
                            <td><?= $p['estado'] === 'activo' ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-light" href="<?= url('admin/proveedores.php', ['editar' => $p['id']]) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                                <a class="btn btn-sm btn-light" href="<?= url('admin/compras.php', ['proveedor' => $p['id']]) ?>" title="Ver compras"><i class="bi bi-receipt"></i></a>
                                <form class="d-inline" method="post" action="<?= url('admin/proveedores.php') ?>">
                                    <?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="proveedor_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn btn-sm btn-light" title="<?= $p['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>"><i class="bi <?= $p['estado'] === 'activo' ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                                </form>
                                <?php if ((int) $p['compras'] === 0): ?>
                                    <form class="d-inline" method="post" action="<?= url('admin/proveedores.php') ?>" data-confirmar="¿Eliminar el proveedor «<?= e($p['nombre']) ?>»?">
                                        <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="proveedor_id" value="<?= (int) $p['id'] ?>">
                                        <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$proveedores): ?><tr><td colspan="6" class="text-center text-muted-fc py-5">Aún no hay proveedores.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <form method="post" action="<?= url('admin/proveedores.php') ?>" class="tarjeta-admin needs-validation" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="proveedor_id" value="<?= (int) ($editar['id'] ?? 0) ?>">
            <div class="cabecera"><h2><?= $editar ? 'Editar proveedor' : 'Nuevo proveedor' ?></h2><?php if ($editar): ?><a class="small" href="<?= url('admin/proveedores.php') ?>">Cancelar</a><?php endif; ?></div>
            <div class="cuerpo row g-2">
                <div class="col-12"><label class="form-label small" for="nombre">Razón social / nombre *</label>
                    <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required minlength="3" maxlength="120" value="<?= e($d['nombre']) ?>"><?= error_campo($errores, 'nombre') ?></div>
                <div class="col-sm-6"><label class="form-label small" for="nit">NIT</label>
                    <input class="form-control<?= clase_invalida($errores, 'nit') ?>" id="nit" name="nit" maxlength="20" value="<?= e($d['nit']) ?>"><?= error_campo($errores, 'nit') ?></div>
                <div class="col-sm-6"><label class="form-label small" for="contacto">Persona de contacto</label>
                    <input class="form-control" id="contacto" name="contacto" maxlength="80" value="<?= e($d['contacto']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="telefono">Teléfono</label>
                    <input class="form-control<?= clase_invalida($errores, 'telefono') ?>" id="telefono" name="telefono" maxlength="20" value="<?= e($d['telefono']) ?>"><?= error_campo($errores, 'telefono') ?></div>
                <div class="col-sm-6"><label class="form-label small" for="email">Correo</label>
                    <input class="form-control<?= clase_invalida($errores, 'email') ?>" type="email" id="email" name="email" maxlength="120" value="<?= e($d['email']) ?>"><?= error_campo($errores, 'email') ?></div>
                <div class="col-sm-8"><label class="form-label small" for="direccion">Dirección</label>
                    <input class="form-control" id="direccion" name="direccion" maxlength="160" value="<?= e($d['direccion']) ?>"></div>
                <div class="col-sm-4"><label class="form-label small" for="ciudad">Ciudad</label>
                    <input class="form-control" id="ciudad" name="ciudad" maxlength="80" value="<?= e($d['ciudad']) ?>"></div>
                <div class="col-12"><label class="form-label small" for="notas">¿Qué le compramos?</label>
                    <input class="form-control" id="notas" name="notas" maxlength="255" value="<?= e($d['notas']) ?>" placeholder="Ej.: telas de algodón y felpa"></div>
                <div class="col-12"><label class="form-label small" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado"><option value="activo" <?= $d['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option><option value="inactivo" <?= $d['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo</option></select></div>
                <div class="col-12 mt-3"><button class="btn btn-fc w-100"><i class="bi bi-check2 me-1"></i><?= $editar ? 'Guardar cambios' : 'Crear proveedor' ?></button></div>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
