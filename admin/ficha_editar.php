<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$productoId = es_post() ? post_int('producto_id') : get_int('producto');
$producto = fila('SELECT p.*, c.nombre AS categoria FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ?', [$productoId]);
if (!$producto) {
    error_404('El producto no existe.');
}
$materiales = filas("SELECT m.id, m.codigo, m.nombre, m.unidad, m.costo_promedio, t.nombre AS tipo, t.id AS tipo_id
                     FROM materiales m JOIN tipos_material t ON t.id = m.tipo_id WHERE m.estado = 'activo' ORDER BY t.id, m.nombre");
$ficha = ficha_producto($productoId);
$d = [
    'costo_mano_obra'   => (string) (float) ($ficha['costo_mano_obra'] ?? 0),
    'costos_indirectos' => (string) (float) ($ficha['costos_indirectos'] ?? 0),
    'tiempo_minutos'    => (string) (int) ($ficha['tiempo_minutos'] ?? 0),
    'notas'             => (string) ($ficha['notas'] ?? ''),
];
$lineas = array_map(static fn($l) => ['material_id' => (int) $l['material_id'], 'cantidad' => (string) (float) $l['cantidad'],
                                      'merma' => (string) (float) $l['merma_pct']], $ficha['lineas'] ?? []);
$error = null;

if (es_post()) {
    $d = ['costo_mano_obra' => post_texto('costo_mano_obra', 15), 'costos_indirectos' => post_texto('costos_indirectos', 15),
          'tiempo_minutos' => post_texto('tiempo_minutos', 6), 'notas' => post_texto('notas', 500)];
    $ids = (array) ($_POST['material_id'] ?? []);
    $cants = (array) ($_POST['cantidad'] ?? []);
    $mermas = (array) ($_POST['merma'] ?? []);
    $lineas = $datos = [];
    foreach ($ids as $i => $mid) {
        $cant = is_string($cants[$i] ?? null) && trim($cants[$i]) !== '' ? a_decimal($cants[$i]) : null;
        $merma = is_string($mermas[$i] ?? null) && trim($mermas[$i]) !== '' ? a_decimal($mermas[$i]) : 0.0;
        $datos[] = [(int) $mid, $cant, $merma];
        $lineas[] = ['material_id' => (int) $mid, 'cantidad' => (string) ($cants[$i] ?? ''), 'merma' => (string) ($mermas[$i] ?? '0')];
        if (count($datos) > 40) {
            break;
        }
    }
    try {
        $mo = a_decimal($d['costo_mano_obra'] ?: '0');
        $ind = a_decimal($d['costos_indirectos'] ?: '0');
        $min = filter_var($d['tiempo_minutos'] ?: '0', FILTER_VALIDATE_INT);
        if ($mo === null || $ind === null || $min === false) {
            throw new DomainException('La mano de obra, los indirectos y el tiempo deben ser números.');
        }
        guardar_ficha($productoId, $mo, $ind, (int) $min, $d['notas'], $datos);
        $nuevo = costos_fabricacion([$productoId])[$productoId] ?? 0;
        flash('success', "Ficha técnica guardada. Costo de fabricar «{$producto['nombre']}»: " . precio($nuevo) . ' por prenda.');
        redirigir('admin/ficha_editar.php', ['producto' => $productoId]);
    } catch (DomainException $e) {
        $error = $e->getMessage();
    }
}
if (!$lineas) {
    $lineas = [['material_id' => 0, 'cantidad' => '', 'merma' => '0']];
}
$ordenes = filas("SELECT o.id, o.codigo, o.cantidad, o.estado, o.costo_unitario, o.fecha_fin, t.nombre AS talla, c.nombre AS color
                  FROM ordenes_produccion o JOIN variantes_producto v ON v.id = o.variante_id JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id
                  WHERE o.producto_id = ? ORDER BY o.id DESC LIMIT 8", [$productoId]);

$titulo = 'Ficha técnica: ' . $producto['nombre'];
$seccion = 'fichas';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/fichas.php') . '"><i class="bi bi-arrow-left me-1"></i>Fichas y costos</a>'
    . ($ficha ? '<a class="btn btn-fc" href="' . url('admin/produccion.php', ['producto' => $productoId]) . '"><i class="bi bi-gear-wide-connected me-1"></i>Programar producción</a>' : '');
require __DIR__ . '/includes/header.php';

$opciones = static function (int $sel) use ($materiales): string {
    $h = '<option value="">Selecciona…</option>';
    $grupo = null;
    foreach ($materiales as $m) {
        if ($m['tipo'] !== $grupo) {
            $h .= ($grupo !== null ? '</optgroup>' : '') . '<optgroup label="' . e($m['tipo']) . '">';
            $grupo = $m['tipo'];
        }
        $h .= '<option value="' . (int) $m['id'] . '" data-costo="' . (float) $m['costo_promedio'] . '" data-unidad="' . e(UNIDADES_MATERIAL[$m['unidad']]) . '"'
            . ' data-merma="' . ((int) $m['tipo_id'] === 1 ? 8 : ((int) $m['tipo_id'] === 2 ? 5 : 0)) . '"'
            . ((int) $m['id'] === $sel ? ' selected' : '') . '>' . e($m['codigo'] . ' · ' . $m['nombre']) . ' — ' . precio($m['costo_promedio']) . '</option>';
    }
    return $h . ($grupo !== null ? '</optgroup>' : '');
};
?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= url('admin/ficha_editar.php') ?>" data-cargando data-ficha>
    <?= csrf_campo() ?>
    <input type="hidden" name="producto_id" value="<?= (int) $productoId ?>">
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="tarjeta-admin mb-3">
                <div class="cabecera"><h2>Materiales por prenda</h2><span class="small text-muted-fc">La merma es el desperdicio al cortar/coser (telas ≈ 8 %)</span></div>
                <div class="table-responsive">
                    <table class="table tabla-admin mb-0" data-lineas="ficha">
                        <thead><tr><th style="min-width:280px">Material</th><th style="width:150px">Cantidad / prenda</th><th style="width:110px">Merma %</th><th class="text-end" style="width:140px">Costo</th><th style="width:50px"></th></tr></thead>
                        <tbody>
                        <?php foreach ($lineas as $l): ?>
                            <tr class="fila-linea">
                                <td><select class="form-select form-select-sm" name="material_id[]" data-material><?= $opciones($l['material_id']) ?></select></td>
                                <td><div class="input-group input-group-sm"><input class="form-control" name="cantidad[]" inputmode="decimal" value="<?= e($l['cantidad']) ?>" data-cantidad-linea><span class="input-group-text" data-unidad-linea>—</span></div></td>
                                <td><input class="form-control form-control-sm" name="merma[]" inputmode="decimal" value="<?= e($l['merma']) ?>" data-merma-linea></td>
                                <td class="text-end fw-semibold" data-subtotal-linea>$0</td>
                                <td><button class="btn btn-sm btn-light text-danger" type="button" data-quitar-linea aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td colspan="3"><button class="btn btn-sm btn-outline-fc" type="button" data-agregar-linea="ficha"><i class="bi bi-plus-lg me-1"></i>Agregar material</button></td>
                            <td class="text-end" data-total-lineas>$0</td><td></td></tr></tfoot>
                    </table>
                </div>
                <template id="plantilla-ficha">
                    <tr class="fila-linea">
                        <td><select class="form-select form-select-sm" name="material_id[]" data-material><?= $opciones(0) ?></select></td>
                        <td><div class="input-group input-group-sm"><input class="form-control" name="cantidad[]" inputmode="decimal" data-cantidad-linea><span class="input-group-text" data-unidad-linea>—</span></div></td>
                        <td><input class="form-control form-control-sm" name="merma[]" inputmode="decimal" value="0" data-merma-linea></td>
                        <td class="text-end fw-semibold" data-subtotal-linea>$0</td>
                        <td><button class="btn btn-sm btn-light text-danger" type="button" data-quitar-linea aria-label="Quitar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                </template>
            </div>
            <div class="tarjeta-admin">
                <div class="cabecera"><h2>Últimas órdenes de producción</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($ordenes as $o): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-3 small">
                            <span><a class="fw-bold" href="<?= url('admin/produccion_detalle.php', ['id' => $o['id']]) ?>"><?= e($o['codigo']) ?></a> · <?= (int) $o['cantidad'] ?> × <?= e($o['talla']) ?>/<?= e($o['color']) ?></span>
                            <span><?= estado_produccion_badge($o['estado']) ?> <?= (float) $o['costo_unitario'] > 0 ? precio($o['costo_unitario']) . ' c/u' : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$ordenes): ?><li class="list-group-item text-muted-fc small px-3">Todavía no se ha fabricado este producto.</li><?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="tarjeta-admin mb-3">
                <div class="cabecera"><h2>Otros costos por prenda</h2></div>
                <div class="cuerpo row g-2">
                    <div class="col-12"><label class="form-label small" for="costo_mano_obra">Mano de obra directa ($)</label>
                        <input class="form-control" id="costo_mano_obra" name="costo_mano_obra" inputmode="decimal" value="<?= e($d['costo_mano_obra']) ?>" data-costo-fijo>
                        <div class="form-text">Corte, confección y terminación de UNA prenda.</div></div>
                    <div class="col-12"><label class="form-label small" for="costos_indirectos">Costos indirectos ($)</label>
                        <input class="form-control" id="costos_indirectos" name="costos_indirectos" inputmode="decimal" value="<?= e($d['costos_indirectos']) ?>" data-costo-fijo>
                        <div class="form-text">Energía de las máquinas, agujas, depreciación… por prenda.</div></div>
                    <div class="col-12"><label class="form-label small" for="tiempo_minutos">Tiempo de fabricación (minutos)</label>
                        <input class="form-control" type="number" min="0" max="10000" id="tiempo_minutos" name="tiempo_minutos" value="<?= e($d['tiempo_minutos']) ?>"></div>
                    <div class="col-12"><label class="form-label small" for="notas">Notas de confección</label>
                        <textarea class="form-control" id="notas" name="notas" rows="3" maxlength="500"><?= e($d['notas']) ?></textarea></div>
                </div>
            </div>
            <div class="tarjeta-admin pregunta" data-precio="<?= (float) $producto['precio'] ?>">
                <div class="cuerpo">
                    <div class="p">¿Cuánto cuesta fabricar «<?= e($producto['nombre']) ?>»?</div>
                    <div class="r" data-costo-total>$0</div>
                    <div class="small mt-2 d-flex justify-content-between"><span>Precio de venta</span><strong><?= precio($producto['precio']) ?></strong></div>
                    <div class="small d-flex justify-content-between"><span>Margen por prenda</span><strong data-margen>$0</strong></div>
                    <button class="btn btn-fc w-100 mt-3" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-check2 me-1"></i>Guardar ficha técnica</button>
                </div>
            </div>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
