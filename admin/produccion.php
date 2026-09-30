<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

if (es_post() && post_texto('accion', 20) === 'crear') {
    try {
        $o = crear_orden_produccion(post_int('producto_id'), post_int('variante_id'), post_int('cantidad'), post_texto('notas', 255), (int) $admin['id']);
        flash('success', "Orden {$o['codigo']} creada. Cuando empieces a fabricar, pulsa «Iniciar producción» para descontar los materiales.");
        redirigir('admin/produccion_detalle.php', ['id' => $o['id']]);
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirigir('admin/produccion.php', ['producto' => post_int('producto_id'), 'variante' => post_int('variante_id'), 'cantidad' => post_int('cantidad')]);
    }
}

// Planificador: producto → variante → cantidad (vista previa de materiales)
$productosConFicha = filas('SELECT p.id, p.nombre, c.nombre AS categoria FROM productos p JOIN categorias c ON c.id = p.categoria_id
                            JOIN fichas_tecnicas f ON f.producto_id = p.id ORDER BY c.nombre, p.nombre');
$pid = get_int('producto');
$vid = get_int('variante');
$cant = max(1, min(10000, get_int('cantidad', 10)));
$variantes = $pid ? filas('SELECT v.id, v.stock, t.nombre AS talla, c.nombre AS color FROM variantes_producto v JOIN tallas t ON t.id = v.talla_id
                           JOIN colores c ON c.id = v.color_id WHERE v.producto_id = ? ORDER BY v.stock, t.orden', [$pid]) : [];
$requerimientos = $pid ? requerimientos_produccion($pid, $cant) : [];
$costoEstimado = array_sum(array_column($requerimientos, 'costo_requerido'));
$ficha = $pid ? ficha_producto($pid) : null;
$faltan = array_filter($requerimientos, static fn($r) => $r['faltante'] > 0);

$estado = get_texto('estado', 15);
$where = isset(ESTADOS_PRODUCCION[$estado]) ? 'WHERE o.estado = ?' : '';
$ordenes = filas("SELECT o.*, p.nombre AS producto, t.nombre AS talla, c.nombre AS color
                  FROM ordenes_produccion o JOIN productos p ON p.id = o.producto_id JOIN variantes_producto v ON v.id = o.variante_id
                  JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id $where
                  ORDER BY FIELD(o.estado, 'en_proceso', 'planificada', 'terminada', 'cancelada'), o.id DESC LIMIT 60", $where ? [$estado] : []);
$mes = [date('Y-m-01 00:00:00'), date('Y-m-d 23:59:59')];
$kpi = fila("SELECT SUM(estado = 'en_proceso') AS proceso, SUM(estado = 'planificada') AS planificadas,
                    COALESCE(SUM(CASE WHEN estado = 'terminada' AND fecha_fin BETWEEN ? AND ? THEN cantidad END), 0) AS prendas_mes,
                    COALESCE(SUM(CASE WHEN estado IN ('en_proceso', 'terminada') AND fecha_inicio BETWEEN ? AND ? THEN costo_total END), 0) AS costo_mes
             FROM ordenes_produccion", array_merge($mes, $mes));
// Sugerencias: variantes fabricables con poco stock
$sugeridas = filas("SELECT v.id, v.stock, p.id AS producto_id, p.nombre, t.nombre AS talla, c.nombre AS color
                    FROM variantes_producto v JOIN productos p ON p.id = v.producto_id JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id
                    JOIN fichas_tecnicas f ON f.producto_id = p.id
                    WHERE v.estado = 'activo' AND p.estado = 'activo' AND v.stock <= ?
                      AND NOT EXISTS (SELECT 1 FROM ordenes_produccion o WHERE o.variante_id = v.id AND o.estado IN ('planificada', 'en_proceso'))
                    ORDER BY v.stock, p.nombre LIMIT 6", [umbral_stock_bajo()]);

$titulo = 'Producción';
$seccion = 'produccion';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="kpi acento"><span class="icono"><i class="bi bi-gear-wide-connected"></i></span><div><div class="valor"><?= (int) $kpi['proceso'] ?></div><div class="etiqueta-kpi">Órdenes en proceso</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-calendar-check"></i></span><div><div class="valor"><?= (int) $kpi['planificadas'] ?></div><div class="etiqueta-kpi">Planificadas</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-check2-all"></i></span><div><div class="valor"><?= (int) $kpi['prendas_mes'] ?></div><div class="etiqueta-kpi">Prendas fabricadas este mes</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi oscuro"><span class="icono"><i class="bi bi-cash-coin"></i></span><div><div class="valor"><?= precio($kpi['costo_mes']) ?></div><div class="etiqueta-kpi">Costo de producción iniciada este mes</div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <a class="btn btn-sm <?= $estado === '' ? 'btn-fc' : 'btn-light' ?>" href="<?= url('admin/produccion.php') ?>">Todas</a>
            <?php foreach (ESTADOS_PRODUCCION as $k => [$t]): ?><a class="btn btn-sm <?= $estado === $k ? 'btn-fc' : 'btn-light' ?>" href="<?= url('admin/produccion.php', ['estado' => $k]) ?>"><?= e($t) ?></a><?php endforeach; ?>
        </div>
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Órdenes de producción</h2></div>
            <div class="table-responsive">
                <table class="table tabla-admin table-hover">
                    <thead><tr><th>Orden</th><th>Prenda</th><th class="text-center">Cant.</th><th>Estado</th><th class="text-end">Costo unit.</th><th class="text-end">Costo total</th></tr></thead>
                    <tbody>
                    <?php foreach ($ordenes as $o): ?>
                        <tr>
                            <td class="fw-bold"><a href="<?= url('admin/produccion_detalle.php', ['id' => $o['id']]) ?>"><?= e($o['codigo']) ?></a><div class="small text-muted-fc fw-normal"><?= fecha($o['fecha_creacion'], false) ?></div></td>
                            <td><?= e($o['producto']) ?><div class="small text-muted-fc"><?= e($o['talla']) ?> / <?= e($o['color']) ?></div></td>
                            <td class="text-center"><?= (int) $o['cantidad'] ?></td>
                            <td><?= estado_produccion_badge($o['estado']) ?></td>
                            <td class="text-end"><?= precio($o['costo_unitario']) ?></td>
                            <td class="text-end fw-semibold"><?= precio($o['costo_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ordenes): ?><tr><td colspan="6" class="text-center text-muted-fc py-5">No hay órdenes.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2>Nueva orden de producción</h2></div>
            <div class="cuerpo">
                <form method="get" action="<?= url('admin/produccion.php') ?>" class="row g-2 align-items-end">
                    <div class="col-12"><label class="form-label small" for="producto">Producto (con ficha técnica)</label>
                        <select class="form-select" id="producto" name="producto" data-autoenviar><option value="">Selecciona…</option>
                            <?php foreach ($productosConFicha as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $pid === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['categoria'] . ' · ' . $p['nombre']) ?></option><?php endforeach; ?></select></div>
                    <?php if ($pid): ?>
                        <div class="col-7"><label class="form-label small" for="variante">Talla / color</label>
                            <select class="form-select" id="variante" name="variante">
                                <?php foreach ($variantes as $v): ?><option value="<?= (int) $v['id'] ?>" <?= $vid === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['talla'] . ' / ' . $v['color']) ?> — stock <?= (int) $v['stock'] ?></option><?php endforeach; ?></select></div>
                        <div class="col-3"><label class="form-label small" for="cantidad">Cantidad</label>
                            <input class="form-control" type="number" id="cantidad" name="cantidad" min="1" max="10000" value="<?= $cant ?>"></div>
                        <div class="col-2"><button class="btn btn-light w-100" title="Calcular materiales"><i class="bi bi-calculator"></i></button></div>
                    <?php endif; ?>
                </form>
                <?php if ($pid && $requerimientos): ?>
                    <div class="table-responsive mt-3">
                        <table class="table tabla-admin table-sm">
                            <thead><tr><th>Material necesario</th><th class="text-end">Requiere</th><th class="text-end">Hay</th><th class="text-end">Costo</th></tr></thead>
                            <tbody>
                            <?php foreach ($requerimientos as $r): ?>
                                <tr class="<?= $r['faltante'] > 0 ? 'table-danger' : '' ?>">
                                    <td class="small"><?= e($r['nombre']) ?></td>
                                    <td class="text-end small"><?= cantidad_fmt($r['requerido'], $r['unidad']) ?></td>
                                    <td class="text-end small"><?= cantidad_fmt($r['stock'], $r['unidad']) ?><?= $r['faltante'] > 0 ? '<div class="text-danger fw-bold">faltan ' . cantidad_fmt($r['faltante']) . '</div>' : '' ?></td>
                                    <td class="text-end small"><?= precio($r['costo_requerido']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr><td colspan="3" class="text-end">Materiales</td><td class="text-end"><?= precio($costoEstimado) ?></td></tr>
                                <tr><td colspan="3" class="text-end">Mano de obra + indirectos</td><td class="text-end"><?= precio($cant * ((float) $ficha['costo_mano_obra'] + (float) $ficha['costos_indirectos'])) ?></td></tr>
                                <tr><td colspan="3" class="text-end">Costo estimado de la orden</td><td class="text-end"><?= precio($cant * $ficha['costo_total']) ?></td></tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if ($faltan): ?>
                        <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Hoy no alcanzan los materiales marcados. Puedes crear la orden como <strong>planificada</strong> y registrar la compra antes de iniciarla.</div>
                    <?php endif; ?>
                    <form method="post" action="<?= url('admin/produccion.php') ?>" data-cargando>
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="crear">
                        <input type="hidden" name="producto_id" value="<?= $pid ?>">
                        <input type="hidden" name="variante_id" value="<?= $vid ?: (int) ($variantes[0]['id'] ?? 0) ?>">
                        <input type="hidden" name="cantidad" value="<?= $cant ?>">
                        <input class="form-control form-control-sm mb-2" name="notas" maxlength="255" placeholder="Notas (opcional)">
                        <button class="btn btn-fc w-100" <?= !$variantes ? 'disabled' : '' ?>><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-plus-lg me-1"></i>Crear orden de <?= $cant ?> prenda(s)</button>
                    </form>
                <?php elseif (!$productosConFicha): ?>
                    <p class="text-muted-fc small mt-3 mb-0">Ningún producto tiene ficha técnica todavía. <a href="<?= url('admin/fichas.php') ?>">Crear fichas</a>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <?php if ($sugeridas): ?>
            <div class="tarjeta-admin">
                <div class="cabecera"><h2><i class="bi bi-lightbulb me-1 text-warning"></i>Sugerido fabricar (poco stock)</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($sugeridas as $s): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-3 small">
                            <span><?= e($s['nombre']) ?> · <?= e($s['talla']) ?>/<?= e($s['color']) ?> <span class="badge <?= (int) $s['stock'] === 0 ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= (int) $s['stock'] ?> uds</span></span>
                            <a class="btn btn-sm btn-light" href="<?= url('admin/produccion.php', ['producto' => $s['producto_id'], 'variante' => $s['id'], 'cantidad' => 10]) ?>">Planificar</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
