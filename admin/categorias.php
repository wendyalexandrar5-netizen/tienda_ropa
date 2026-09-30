<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$editar = null;
$errores = [];
$d = ['nombre' => '', 'descripcion' => '', 'estado' => 'activa'];

if (es_post()) {
    $accion = post_texto('accion', 20);
    $id = post_int('categoria_id');
    $cat = $id ? fila('SELECT * FROM categorias WHERE id = ?', [$id]) : null;

    if ($accion === 'guardar') {
        $d = ['nombre' => post_texto('nombre', 60), 'descripcion' => post_texto('descripcion', 255), 'estado' => post_texto('estado', 10)];
        $v = (new Validador())->requerido('nombre', $d['nombre'], 'El nombre')->longitud('nombre', $d['nombre'], 2, 60, 'El nombre')
            ->longitud('descripcion', $d['descripcion'], 0, 255, 'La descripción')->enLista('estado', $d['estado'], ['activa', 'inactiva'], 'El estado');
        if (valor('SELECT id FROM categorias WHERE nombre = ? AND id <> ?', [$d['nombre'], $id])) {
            $v->agregar('nombre', 'Ya existe una categoría con ese nombre.');
        }
        $imagen = null;
        if ($v->ok()) {
            try {
                $imagen = subir_imagen($_FILES['imagen'] ?? null, 'categorias');
            } catch (DomainException $e) {
                $v->agregar('imagen', $e->getMessage());
            }
        }
        if ($v->ok()) {
            if ($cat) {
                consulta('UPDATE categorias SET nombre = ?, descripcion = ?, estado = ?, imagen = ? WHERE id = ?',
                    [$d['nombre'], $d['descripcion'] ?: null, $d['estado'], $imagen ?? $cat['imagen'], $id]);
                if ($imagen) {
                    eliminar_imagen_subida($cat['imagen']);
                }
                flash('success', "Categoría «{$d['nombre']}» actualizada.");
            } else {
                consulta('INSERT INTO categorias (nombre, descripcion, estado, imagen) VALUES (?, ?, ?, ?)',
                    [$d['nombre'], $d['descripcion'] ?: null, $d['estado'], $imagen]);
                flash('success', "Categoría «{$d['nombre']}» creada.");
            }
            redirigir('admin/categorias.php');
        }
        $errores = $v->errores();
        $editar = $cat;
        flash('danger', 'Revisa los campos marcados.');
    } elseif ($cat && $accion === 'estado') {
        consulta('UPDATE categorias SET estado = ? WHERE id = ?', [$cat['estado'] === 'activa' ? 'inactiva' : 'activa', $id]);
        flash('success', $cat['estado'] === 'activa' ? "«{$cat['nombre']}» se ocultó de la tienda (sus productos también)." : "«{$cat['nombre']}» vuelve a estar visible.");
        redirigir('admin/categorias.php');
    } elseif ($cat && $accion === 'eliminar') {
        $n = (int) valor('SELECT COUNT(*) FROM productos WHERE categoria_id = ?', [$id]);
        if ($n > 0) {
            flash('warning', "No se puede eliminar «{$cat['nombre']}»: tiene $n producto(s). Muévelos o desactiva la categoría.");
        } else {
            consulta('DELETE FROM categorias WHERE id = ?', [$id]);
            eliminar_imagen_subida($cat['imagen']);
            flash('success', "Categoría «{$cat['nombre']}» eliminada.");
        }
        redirigir('admin/categorias.php');
    } else {
        flash('danger', 'Acción no válida.');
        redirigir('admin/categorias.php');
    }
} elseif (get_int('editar')) {
    $editar = fila('SELECT * FROM categorias WHERE id = ?', [get_int('editar')]);
    if ($editar) {
        $d = $editar;
    }
}

$categorias = filas("SELECT c.*, COUNT(p.id) AS productos, SUM(p.estado = 'activo') AS activos
                     FROM categorias c LEFT JOIN productos p ON p.categoria_id = c.id GROUP BY c.id ORDER BY c.nombre");

$titulo = 'Categorías';
$seccion = 'categorias';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2><?= count($categorias) ?> categoría(s)</h2></div>
            <div class="table-responsive">
                <table class="table tabla-admin table-hover">
                    <thead><tr><th>Categoría</th><th class="text-center">Productos</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($categorias as $c): ?>
                        <tr>
                            <td><div class="d-flex align-items-center gap-3"><img class="miniatura" src="<?= imagen_url($c['imagen']) ?>" alt="">
                                <div><div class="fw-bold"><?= e($c['nombre']) ?></div><div class="small text-muted-fc"><?= e($c['descripcion']) ?></div></div></div></td>
                            <td class="text-center"><?= (int) $c['activos'] ?> / <?= (int) $c['productos'] ?></td>
                            <td><?= $c['estado'] === 'activa' ? '<span class="badge text-bg-success">Activa</span>' : '<span class="badge text-bg-secondary">Inactiva</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-light" href="<?= url('admin/categorias.php', ['editar' => $c['id']]) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                                <a class="btn btn-sm btn-light" href="<?= url('admin/productos.php', ['categoria' => $c['id']]) ?>" title="Ver productos"><i class="bi bi-tags"></i></a>
                                <form class="d-inline" method="post" action="<?= url('admin/categorias.php') ?>">
                                    <?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="categoria_id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-light" title="<?= $c['estado'] === 'activa' ? 'Desactivar' : 'Activar' ?>"><i class="bi <?= $c['estado'] === 'activa' ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                                </form>
                                <form class="d-inline" method="post" action="<?= url('admin/categorias.php') ?>" data-confirmar="¿Eliminar la categoría «<?= e($c['nombre']) ?>»?">
                                    <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="categoria_id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-light text-danger" title="Eliminar" <?= (int) $c['productos'] > 0 ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <form method="post" action="<?= url('admin/categorias.php') ?>" enctype="multipart/form-data" class="tarjeta-admin needs-validation" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="categoria_id" value="<?= (int) ($editar['id'] ?? 0) ?>">
            <div class="cabecera"><h2><?= $editar ? 'Editar categoría' : 'Nueva categoría' ?></h2><?php if ($editar): ?><a class="small" href="<?= url('admin/categorias.php') ?>">Cancelar</a><?php endif; ?></div>
            <div class="cuerpo">
                <div class="mb-3"><label class="form-label" for="nombre">Nombre *</label>
                    <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required minlength="2" maxlength="60" value="<?= e($d['nombre']) ?>"><?= error_campo($errores, 'nombre') ?></div>
                <div class="mb-3"><label class="form-label" for="descripcion">Descripción</label>
                    <textarea class="form-control" id="descripcion" name="descripcion" rows="2" maxlength="255"><?= e($d['descripcion']) ?></textarea></div>
                <div class="mb-3"><label class="form-label" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado"><option value="activa" <?= $d['estado'] === 'activa' ? 'selected' : '' ?>>Activa</option><option value="inactiva" <?= $d['estado'] === 'inactiva' ? 'selected' : '' ?>>Inactiva</option></select></div>
                <div class="mb-3"><label class="form-label" for="imagen">Imagen</label>
                    <img id="prev-cat" class="vista-previa d-block mb-2" style="max-width:140px" src="<?= imagen_url($editar['imagen'] ?? null) ?>" alt="">
                    <input class="form-control<?= clase_invalida($errores, 'imagen') ?>" type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp" data-vista-previa="prev-cat"><?= error_campo($errores, 'imagen') ?></div>
                <button class="btn btn-fc w-100" type="submit"><i class="bi bi-check2 me-1"></i><?= $editar ? 'Guardar cambios' : 'Crear categoría' ?></button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
