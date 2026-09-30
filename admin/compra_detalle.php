<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$id = es_post() ? post_int('compra_id') : get_int('id');
$compra = fila("SELECT c.*, p.nombre AS proveedor, p.nit, p.telefono, p.email, CONCAT(u.nombre, ' ', u.apellido) AS usuario
                FROM compras_material c JOIN proveedores p ON p.id = c.proveedor_id LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ?", [$id]);
if (!$compra) {
    error_404('La compra no existe.');
}
if (es_post() && post_texto('accion', 20) === 'anular') {
    try {
        anular_compra($id, (int) $admin['id']);
        flash('success', "Compra {$compra['codigo']} anulada: se retiraron los materiales del inventario.");
    } catch (DomainException $e) {
        flash('danger', 'No se pudo anular: ' . $e->getMessage() . ' Es posible que el material ya se haya usado en producción.');
    }
    redirigir('admin/compra_detalle.php', ['id' => $id]);
}
$lineas = filas('SELECT d.*, m.codigo, m.nombre, m.unidad, m.stock, t.nombre AS tipo FROM compra_detalle d
                 JOIN materiales m ON m.id = d.material_id JOIN tipos_material t ON t.id = m.tipo_id WHERE d.compra_id = ? ORDER BY d.id', [$id]);

$titulo = 'Compra ' . $compra['codigo'];
$seccion = 'compras';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/compras.php') . '"><i class="bi bi-arrow-left me-1"></i>Compras</a>'
    . '<button class="btn btn-outline-fc" type="button" data-imprimir><i class="bi bi-printer me-1"></i>Imprimir</button>';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Materiales · <?= fecha($compra['fecha'], false) ?></h2>
                <?= $compra['estado'] === 'recibida' ? '<span class="badge text-bg-success">Recibida</span>' : '<span class="badge text-bg-secondary">Anulada</span>' ?></div>
            <div class="table-responsive">
                <table class="table tabla-admin">
                    <thead><tr><th>Material</th><th>Tipo</th><th class="text-end">Cantidad</th><th class="text-end">Costo unitario</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($lineas as $l): ?>
                        <tr><td><a href="<?= url('admin/material_editar.php', ['id' => $l['material_id']]) ?>"><?= e($l['nombre']) ?></a><div class="small text-muted-fc"><?= e($l['codigo']) ?></div></td>
                            <td class="small"><?= e($l['tipo']) ?></td>
                            <td class="text-end"><?= cantidad_fmt($l['cantidad'], $l['unidad']) ?></td>
                            <td class="text-end"><?= precio($l['costo_unitario']) ?></td>
                            <td class="text-end fw-semibold"><?= precio($l['subtotal']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="4" class="text-end">Total</td><td class="text-end fs-5"><?= precio($compra['total']) ?></td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2>Proveedor</h2></div>
            <div class="cuerpo small">
                <div class="fw-bold fs-6"><?= e($compra['proveedor']) ?></div>
                <?php if ($compra['nit']): ?><div>NIT <?= e($compra['nit']) ?></div><?php endif; ?>
                <div class="text-muted-fc"><?= e($compra['telefono']) ?> · <?= e($compra['email']) ?></div>
                <hr>
                <div>Factura: <strong><?= e($compra['numero_factura'] ?? '—') ?></strong></div>
                <div>Registrada por <?= e($compra['usuario'] ?? '—') ?> el <?= fecha($compra['fecha_creacion']) ?></div>
                <?php if ($compra['notas']): ?><div class="mt-2">Notas: <?= e($compra['notas']) ?></div><?php endif; ?>
            </div>
        </div>
        <?php if ($compra['estado'] === 'recibida'): ?>
            <form method="post" action="<?= url('admin/compra_detalle.php') ?>" class="tarjeta-admin no-imprimir" data-confirmar="¿Anular esta compra? Los materiales se retirarán del inventario.">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="compra_id" value="<?= (int) $id ?>">
                <div class="cabecera"><h2>Anular compra</h2></div>
                <div class="cuerpo small">
                    <p class="text-muted-fc">Úsalo si la compra se registró por error o la mercancía se devolvió. Sólo es posible si todavía hay stock suficiente de cada material.</p>
                    <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i>Anular compra</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
