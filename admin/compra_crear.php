<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$proveedores = filas("SELECT id, nombre FROM proveedores WHERE estado = 'activo' ORDER BY nombre");
$materiales = filas("SELECT m.id, m.codigo, m.nombre, m.unidad, m.costo_promedio, m.stock, m.proveedor_preferido_id, t.nombre AS tipo
                     FROM materiales m JOIN tipos_material t ON t.id = m.tipo_id WHERE m.estado = 'activo' ORDER BY t.id, m.nombre");
$d = ['proveedor_id' => 0, 'fecha' => date('Y-m-d'), 'numero_factura' => '', 'notas' => ''];
$lineas = [];
$materialInicial = get_int('material');
if ($materialInicial) {
    foreach ($materiales as $m) {
        if ((int) $m['id'] === $materialInicial) {
            $lineas[] = ['material_id' => $materialInicial, 'cantidad' => '', 'costo' => (string) (float) $m['costo_promedio']];
            $d['proveedor_id'] = (int) $m['proveedor_preferido_id'];
        }
    }
}
$error = null;

if (es_post()) {
    $d = ['proveedor_id' => post_int('proveedor_id'), 'fecha' => post_texto('fecha', 10), 'numero_factura' => post_texto('numero_factura', 40), 'notas' => post_texto('notas', 255)];
    $ids = (array) ($_POST['material_id'] ?? []);
    $cants = (array) ($_POST['cantidad'] ?? []);
    $costos = (array) ($_POST['costo'] ?? []);
    $datosLineas = [];
    $lineas = [];
    foreach ($ids as $i => $mid) {
        $mid = (int) $mid;
        $cant = is_string($cants[$i] ?? null) && trim($cants[$i]) !== '' ? a_decimal($cants[$i]) : null;
        $costo = is_string($costos[$i] ?? null) && trim($costos[$i]) !== '' ? a_decimal($costos[$i]) : null;
        $datosLineas[] = [$mid, $cant, $costo];
        $lineas[] = ['material_id' => $mid, 'cantidad' => (string) ($cants[$i] ?? ''), 'costo' => (string) ($costos[$i] ?? '')];
        if (count($datosLineas) > 60) {
            break;
        }
    }
    try {
        $compra = registrar_compra($d['proveedor_id'], $d['fecha'], $d['numero_factura'], $d['notas'], $datosLineas, (int) $admin['id']);
        flash('success', "Compra {$compra['codigo']} registrada por " . precio($compra['total']) . '. El inventario y el costo promedio de los materiales se actualizaron.');
        redirigir('admin/compra_detalle.php', ['id' => $compra['id']]);
    } catch (DomainException $e) {
        $error = $e->getMessage();
    }
}
if (!$lineas) {
    $lineas = [['material_id' => 0, 'cantidad' => '', 'costo' => '']];
}

$titulo = 'Registrar compra de materiales';
$seccion = 'compras';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/compras.php') . '"><i class="bi bi-arrow-left me-1"></i>Compras</a>';
require __DIR__ . '/includes/header.php';

/** Opciones del selector de material (el costo y la unidad viajan en data-* para el cálculo en vivo). */
$opciones = static function (int $seleccionado) use ($materiales): string {
    $h = '<option value="">Selecciona…</option>';
    $grupo = null;
    foreach ($materiales as $m) {
        if ($m['tipo'] !== $grupo) {
            $h .= ($grupo !== null ? '</optgroup>' : '') . '<optgroup label="' . e($m['tipo']) . '">';
            $grupo = $m['tipo'];
        }
        $h .= '<option value="' . (int) $m['id'] . '" data-costo="' . (float) $m['costo_promedio'] . '" data-unidad="' . e(UNIDADES_MATERIAL[$m['unidad']]) . '"'
            . ((int) $m['id'] === $seleccionado ? ' selected' : '') . '>' . e($m['codigo'] . ' · ' . $m['nombre']) . '</option>';
    }
    return $h . ($grupo !== null ? '</optgroup>' : '');
};
?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i><?= e($error) ?></div><?php endif; ?>
<?php if (!$proveedores): ?><div class="alert alert-warning">Primero crea un proveedor en <a href="<?= url('admin/proveedores.php') ?>">Proveedores</a>.</div><?php endif; ?>
<form method="post" action="<?= url('admin/compra_crear.php') ?>" class="needs-validation" novalidate data-cargando>
    <?= csrf_campo() ?>
    <div class="tarjeta-admin mb-3">
        <div class="cabecera"><h2>Datos de la compra</h2></div>
        <div class="cuerpo row g-2">
            <div class="col-md-4"><label class="form-label small" for="proveedor_id">Proveedor *</label>
                <select class="form-select" id="proveedor_id" name="proveedor_id" required><option value="">Selecciona…</option>
                    <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $d['proveedor_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small" for="fecha">Fecha *</label>
                <input class="form-control" type="date" id="fecha" name="fecha" required max="<?= date('Y-m-d') ?>" value="<?= e($d['fecha']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small" for="numero_factura">N.º de factura</label>
                <input class="form-control" id="numero_factura" name="numero_factura" maxlength="40" value="<?= e($d['numero_factura']) ?>"></div>
            <div class="col-md-4"><label class="form-label small" for="notas">Notas</label>
                <input class="form-control" id="notas" name="notas" maxlength="255" value="<?= e($d['notas']) ?>"></div>
        </div>
    </div>
    <div class="tarjeta-admin">
        <div class="cabecera"><h2>Materiales recibidos</h2><span class="small text-muted-fc">El costo sugerido es el costo promedio actual</span></div>
        <div class="table-responsive">
            <table class="table tabla-admin mb-0" data-lineas="compra">
                <thead><tr><th style="min-width:280px">Material</th><th style="width:150px">Cantidad</th><th style="width:170px">Costo unitario ($)</th><th class="text-end" style="width:150px">Subtotal</th><th style="width:50px"></th></tr></thead>
                <tbody>
                <?php foreach ($lineas as $l): ?>
                    <tr class="fila-linea">
                        <td><select class="form-select form-select-sm" name="material_id[]" data-material><?= $opciones((int) $l['material_id']) ?></select></td>
                        <td><div class="input-group input-group-sm"><input class="form-control" name="cantidad[]" inputmode="decimal" value="<?= e($l['cantidad']) ?>" data-cantidad-linea><span class="input-group-text" data-unidad-linea>—</span></div></td>
                        <td><input class="form-control form-control-sm" name="costo[]" inputmode="decimal" value="<?= e($l['costo']) ?>" data-costo-linea></td>
                        <td class="text-end fw-semibold" data-subtotal-linea>$0</td>
                        <td><button class="btn btn-sm btn-light text-danger" type="button" data-quitar-linea aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="3"><button class="btn btn-sm btn-outline-fc" type="button" data-agregar-linea="compra"><i class="bi bi-plus-lg me-1"></i>Agregar material</button></td>
                        <td class="text-end fs-5" data-total-lineas>$0</td><td></td></tr>
                </tfoot>
            </table>
        </div>
        <template id="plantilla-compra">
            <tr class="fila-linea">
                <td><select class="form-select form-select-sm" name="material_id[]" data-material><?= $opciones(0) ?></select></td>
                <td><div class="input-group input-group-sm"><input class="form-control" name="cantidad[]" inputmode="decimal" data-cantidad-linea><span class="input-group-text" data-unidad-linea>—</span></div></td>
                <td><input class="form-control form-control-sm" name="costo[]" inputmode="decimal" data-costo-linea></td>
                <td class="text-end fw-semibold" data-subtotal-linea>$0</td>
                <td><button class="btn btn-sm btn-light text-danger" type="button" data-quitar-linea aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>
            </tr>
        </template>
    </div>
    <div class="d-flex justify-content-end mt-3">
        <button class="btn btn-fc btn-lg" type="submit" <?= !$proveedores ? 'disabled' : '' ?>><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-check2 me-1"></i>Registrar compra y actualizar inventario</button>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
