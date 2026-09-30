<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$id = es_post() ? post_int('orden_id') : get_int('id');
$orden = fila("SELECT o.*, p.nombre AS producto, p.precio, t.nombre AS talla, c.nombre AS color, v.stock AS stock_variante, v.sku,
                      CONCAT(u.nombre, ' ', u.apellido) AS usuario
               FROM ordenes_produccion o JOIN productos p ON p.id = o.producto_id JOIN variantes_producto v ON v.id = o.variante_id
               JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id LEFT JOIN usuarios u ON u.id = o.usuario_id
               WHERE o.id = ?", [$id]);
if (!$orden) {
    error_404('La orden de producción no existe.');
}

if (es_post()) {
    $accion = post_texto('accion', 20);
    try {
        if ($accion === 'iniciar') {
            iniciar_orden_produccion($id, (int) $admin['id']);
            flash('success', "Producción iniciada: se descontaron del inventario los materiales de {$orden['codigo']}.");
        } elseif ($accion === 'terminar') {
            terminar_orden_produccion($id, (int) $admin['id']);
            flash('success', "¡Producción terminada! Entraron {$orden['cantidad']} prenda(s) de «{$orden['producto']}» ({$orden['talla']}/{$orden['color']}) al inventario de la tienda.");
        } elseif ($accion === 'cancelar') {
            cancelar_orden_produccion($id, (int) $admin['id']);
            flash('success', 'Orden cancelada.' . ($orden['estado'] === 'en_proceso' ? ' Los materiales volvieron al inventario.' : ''));
        } else {
            throw new DomainException('Acción no válida.');
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
    }
    redirigir('admin/produccion_detalle.php', ['id' => $id]);
}

$consumos = filas('SELECT pc.*, m.nombre, m.codigo, m.unidad FROM produccion_consumos pc JOIN materiales m ON m.id = pc.material_id WHERE pc.orden_id = ? ORDER BY pc.id', [$id]);
$requerimientos = $orden['estado'] === 'planificada' ? requerimientos_produccion((int) $orden['producto_id'], (int) $orden['cantidad']) : [];
$faltan = array_filter($requerimientos, static fn($r) => $r['faltante'] > 0);
$margenUnit = (float) $orden['precio'] - (float) $orden['costo_unitario'];

$titulo = 'Orden ' . $orden['codigo'];
$seccion = 'produccion';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/produccion.php') . '"><i class="bi bi-arrow-left me-1"></i>Producción</a>'
    . '<button class="btn btn-outline-fc" type="button" data-imprimir><i class="bi bi-printer me-1"></i>Imprimir orden</button>';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2><?= e($orden['producto']) ?> · <?= e($orden['talla']) ?> / <?= e($orden['color']) ?> × <?= (int) $orden['cantidad'] ?></h2><?= estado_produccion_badge($orden['estado']) ?></div>
            <div class="cuerpo">
                <div class="row text-center g-2">
                    <?php $pasos = ['planificada' => 'Planificada', 'en_proceso' => 'En proceso', 'terminada' => 'Terminada'];
                    $orden_pasos = array_keys($pasos); $pos = array_search($orden['estado'], $orden_pasos, true);
                    foreach ($pasos as $k => $t): $hecho = $pos !== false && array_search($k, $orden_pasos, true) <= $pos; ?>
                        <div class="col"><div class="mx-auto mb-1 rounded-circle d-flex align-items-center justify-content-center <?= $hecho ? 'bg-dark text-warning' : 'bg-light text-muted' ?>" style="width:44px;height:44px"><i class="bi <?= ESTADOS_PRODUCCION[$k][2] ?>"></i></div>
                            <div class="small fw-semibold <?= $hecho ? '' : 'text-muted' ?>"><?= $t ?></div></div>
                    <?php endforeach; ?>
                </div>
                <div class="row small mt-3 g-2 text-muted-fc">
                    <div class="col-sm-4">Creada: <strong class="text-dark"><?= fecha($orden['fecha_creacion']) ?></strong></div>
                    <div class="col-sm-4">Inicio: <strong class="text-dark"><?= fecha($orden['fecha_inicio']) ?></strong></div>
                    <div class="col-sm-4">Fin: <strong class="text-dark"><?= fecha($orden['fecha_fin']) ?></strong></div>
                </div>
                <?php if ($orden['notas']): ?><div class="small mt-2">Notas: <?= e($orden['notas']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="tarjeta-admin">
            <?php if ($consumos): ?>
                <div class="cabecera"><h2>Consumo real de materiales</h2><span class="small text-muted-fc">Valorizado al costo promedio del día de inicio</span></div>
                <div class="table-responsive"><table class="table tabla-admin">
                    <thead><tr><th>Material</th><th class="text-end">Cantidad</th><th class="text-end">Costo unit.</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody><?php foreach ($consumos as $c): ?>
                        <tr><td><a href="<?= url('admin/material_editar.php', ['id' => $c['material_id']]) ?>"><?= e($c['nombre']) ?></a></td>
                            <td class="text-end"><?= cantidad_fmt($c['cantidad'], $c['unidad']) ?></td><td class="text-end"><?= precio($c['costo_unitario']) ?></td><td class="text-end fw-semibold"><?= precio($c['subtotal']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                    <tfoot><tr><td colspan="3" class="text-end">Total materiales</td><td class="text-end"><?= precio($orden['costo_materiales']) ?></td></tr></tfoot>
                </table></div>
            <?php elseif ($requerimientos): ?>
                <div class="cabecera"><h2>Materiales que se van a consumir</h2><span class="small text-muted-fc">Se descuentan al iniciar la producción</span></div>
                <div class="table-responsive"><table class="table tabla-admin">
                    <thead><tr><th>Material</th><th class="text-end">Requiere</th><th class="text-end">Disponible</th><th class="text-end">Costo estimado</th></tr></thead>
                    <tbody><?php foreach ($requerimientos as $r): ?>
                        <tr class="<?= $r['faltante'] > 0 ? 'table-danger' : '' ?>"><td><?= e($r['nombre']) ?></td><td class="text-end"><?= cantidad_fmt($r['requerido'], $r['unidad']) ?></td>
                            <td class="text-end"><?= cantidad_fmt($r['stock'], $r['unidad']) ?><?= $r['faltante'] > 0 ? '<div class="small text-danger fw-bold">faltan ' . cantidad_fmt($r['faltante'], $r['unidad']) . '</div>' : '' ?></td>
                            <td class="text-end"><?= precio($r['costo_requerido']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <div class="cuerpo text-muted-fc small">La orden fue cancelada antes de consumir materiales.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="tarjeta-admin pregunta mb-3">
            <div class="cuerpo">
                <div class="p">Costo por prenda <?= $orden['estado'] === 'planificada' ? '(estimado)' : '(real)' ?></div>
                <div class="r"><?= precio($orden['costo_unitario']) ?></div>
                <ul class="list-unstyled small mt-2 mb-0">
                    <li class="d-flex justify-content-between"><span>Materiales</span><span><?= precio($orden['costo_materiales']) ?></span></li>
                    <li class="d-flex justify-content-between"><span>Mano de obra</span><span><?= precio($orden['costo_mano_obra']) ?></span></li>
                    <li class="d-flex justify-content-between"><span>Indirectos</span><span><?= precio($orden['costo_indirecto']) ?></span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-1 mt-1"><span>Costo total de la orden</span><span><?= precio($orden['costo_total']) ?></span></li>
                    <li class="d-flex justify-content-between mt-2"><span>Precio de venta</span><span><?= precio($orden['precio']) ?></span></li>
                    <li class="d-flex justify-content-between fw-bold <?= $margenUnit < 0 ? 'cifra-negativa' : 'cifra-positiva' ?>"><span>Margen por prenda</span><span><?= precio($margenUnit) ?></span></li>
                </ul>
            </div>
        </div>
        <div class="tarjeta-admin no-imprimir">
            <div class="cabecera"><h2>Acciones</h2></div>
            <div class="cuerpo d-grid gap-2">
                <?php if ($orden['estado'] === 'planificada'): ?>
                    <form method="post" action="<?= url('admin/produccion_detalle.php') ?>" data-confirmar="¿Iniciar la producción? Se descontarán los materiales del inventario." data-cargando>
                        <?= csrf_campo() ?><input type="hidden" name="orden_id" value="<?= (int) $id ?>"><input type="hidden" name="accion" value="iniciar">
                        <button class="btn btn-fc w-100" <?= $faltan ? 'disabled' : '' ?>><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-play-fill me-1"></i>Iniciar producción</button>
                    </form>
                    <?php if ($faltan): ?><a class="btn btn-outline-fc" href="<?= url('admin/compra_crear.php', ['material' => reset($faltan)['material_id']]) ?>"><i class="bi bi-cart-plus me-1"></i>Comprar materiales faltantes</a><?php endif; ?>
                <?php elseif ($orden['estado'] === 'en_proceso'): ?>
                    <form method="post" action="<?= url('admin/produccion_detalle.php') ?>" data-confirmar="¿Terminar la producción? Las <?= (int) $orden['cantidad'] ?> prendas entrarán al inventario de la tienda." data-cargando>
                        <?= csrf_campo() ?><input type="hidden" name="orden_id" value="<?= (int) $id ?>"><input type="hidden" name="accion" value="terminar">
                        <button class="btn btn-success w-100"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-check2-all me-1"></i>Terminar y enviar a la tienda</button>
                    </form>
                <?php else: ?>
                    <p class="small text-muted-fc mb-0">Esta orden está <?= e(ESTADOS_PRODUCCION[$orden['estado']][0]) ?> y ya no admite cambios.
                        <?php if ($orden['estado'] === 'terminada'): ?>Stock actual de la variante (<?= e($orden['sku']) ?>): <strong><?= (int) $orden['stock_variante'] ?></strong>.<?php endif; ?></p>
                <?php endif; ?>
                <?php if (in_array($orden['estado'], ['planificada', 'en_proceso'], true)): ?>
                    <form method="post" action="<?= url('admin/produccion_detalle.php') ?>" data-confirmar="¿Cancelar la orden?<?= $orden['estado'] === 'en_proceso' ? ' Los materiales volverán al inventario.' : '' ?>">
                        <?= csrf_campo() ?><input type="hidden" name="orden_id" value="<?= (int) $id ?>"><input type="hidden" name="accion" value="cancelar">
                        <button class="btn btn-link text-danger w-100">Cancelar orden</button>
                    </form>
                <?php endif; ?>
                <div class="small text-muted-fc mt-2">Creada por <?= e($orden['usuario'] ?? '—') ?></div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
