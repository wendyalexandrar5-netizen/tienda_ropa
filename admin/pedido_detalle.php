<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$id = es_post() ? post_int('pedido_id') : get_int('id');
$pedido = fila("SELECT p.*, CONCAT(u.nombre, ' ', u.apellido) AS cliente, u.email, u.telefono AS telefono_cliente, u.id AS cliente_id
                FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?", [$id]);
if (!$pedido) {
    error_404('El pedido no existe.');
}

if (es_post()) {
    $nuevo = post_texto('estado', 20);
    try {
        cambiar_estado_pedido($id, $nuevo, (int) $admin['id'], post_texto('comentario', 255));
        flash('success', "Pedido {$pedido['codigo']} → " . estado_pedido_texto($nuevo) . ($nuevo === 'cancelado' ? '. El stock fue devuelto al inventario.' : '.'));
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
    }
    redirigir('admin/pedido_detalle.php', ['id' => $id]);
}

$items = pedido_items($id);
$historial = pedido_historial($id);
$siguientes = TRANSICIONES_PEDIDO[$pedido['estado']] ?? [];
$pedidosCliente = (int) valor('SELECT COUNT(*) FROM pedidos WHERE usuario_id = ?', [$pedido['cliente_id']]);

$titulo = 'Pedido ' . $pedido['codigo'];
$seccion = 'pedidos';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/pedidos.php') . '"><i class="bi bi-arrow-left me-1"></i>Pedidos</a>'
    . '<button class="btn btn-outline-fc" type="button" data-imprimir><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2>Productos · <?= fecha($pedido['fecha_pedido']) ?></h2><?= estado_pedido_badge($pedido['estado']) ?></div>
            <div class="table-responsive">
                <table class="table tabla-admin">
                    <thead><tr><th>Producto</th><th>Variante</th><th class="text-center">Cant.</th><th class="text-end">Precio unit. (histórico)</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr>
                            <td><div class="d-flex align-items-center gap-2"><img class="miniatura" src="<?= imagen_url($i['imagen']) ?>" alt=""><a href="<?= url('admin/producto_editar.php', ['id' => $i['producto_id']]) ?>"><?= e($i['nombre_producto']) ?></a></div></td>
                            <td><?= e($i['talla']) ?> · <?= e($i['color']) ?></td>
                            <td class="text-center"><?= (int) $i['cantidad'] ?></td>
                            <td class="text-end"><?= precio($i['precio_unitario']) ?></td>
                            <td class="text-end fw-semibold"><?= precio($i['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?= precio($pedido['subtotal']) ?></td></tr>
                        <tr><td colspan="4" class="text-end">Envío</td><td class="text-end"><?= (float) $pedido['costo_envio'] > 0 ? precio($pedido['costo_envio']) : 'Gratis' ?></td></tr>
                        <tr><td colspan="4" class="text-end fw-bold">Total</td><td class="text-end fw-bold fs-5"><?= precio($pedido['total']) ?></td></tr>
                        <?php if ($pedido['pago_con'] !== null): ?>
                            <tr><td colspan="4" class="text-end small text-muted-fc">Paga con <?= precio($pedido['pago_con']) ?> · Cambio a llevar</td><td class="text-end fw-semibold"><?= precio((float) $pedido['pago_con'] - (float) $pedido['total']) ?></td></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Historial de estados</h2></div>
            <div class="cuerpo">
                <ul class="linea-tiempo mb-0">
                    <?php foreach ($historial as $h): ?>
                        <li>
                            <div class="fw-semibold"><?= $h['estado_anterior'] ? e(estado_pedido_texto($h['estado_anterior'])) . ' → ' : '' ?><?= e(estado_pedido_texto($h['estado_nuevo'])) ?></div>
                            <div class="small text-muted-fc"><?= fecha($h['fecha']) ?> · <?= e($h['usuario'] ?? 'Sistema') ?><?= $h['rol'] === ROL_ADMIN ? ' (admin)' : '' ?></div>
                            <?php if ($h['comentario']): ?><div class="small"><?= e($h['comentario']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="tarjeta-admin mb-3 no-imprimir">
            <div class="cabecera"><h2>Cambiar estado</h2></div>
            <div class="cuerpo">
                <?php if ($siguientes): ?>
                    <form method="post" action="<?= url('admin/pedido_detalle.php') ?>" data-confirmar="¿Confirmas el cambio de estado del pedido?">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="pedido_id" value="<?= (int) $id ?>">
                        <label class="form-label" for="estado">Nuevo estado</label>
                        <select class="form-select mb-3" id="estado" name="estado" required>
                            <?php foreach ($siguientes as $s): ?><option value="<?= e($s) ?>"><?= e(estado_pedido_texto($s)) ?></option><?php endforeach; ?>
                        </select>
                        <label class="form-label" for="comentario">Comentario <span class="text-muted-fc fw-normal">(opcional)</span></label>
                        <input class="form-control mb-3" id="comentario" name="comentario" maxlength="255" placeholder="Ej.: número de guía">
                        <button class="btn btn-fc w-100" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Actualizar estado</button>
                        <?php if (in_array('cancelado', $siguientes, true)): ?><p class="small text-muted-fc mt-2 mb-0">Si cancelas, las unidades vuelven automáticamente al inventario.</p><?php endif; ?>
                    </form>
                <?php else: ?>
                    <p class="text-muted-fc mb-0">Este pedido está <strong><?= e(estado_pedido_texto($pedido['estado'])) ?></strong> y ya no admite cambios.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2>Cliente</h2></div>
            <div class="cuerpo">
                <div class="fw-bold"><a href="<?= url('admin/usuario_editar.php', ['id' => $pedido['cliente_id']]) ?>"><?= e($pedido['cliente']) ?></a></div>
                <div class="small text-muted-fc"><?= e($pedido['email']) ?> · <?= e($pedido['telefono_cliente']) ?></div>
                <div class="small mt-1"><?= $pedidosCliente ?> pedido(s) en total</div>
            </div>
        </div>
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Entrega y pago</h2></div>
            <div class="cuerpo small">
                <div class="fw-semibold"><?= e($pedido['nombre_destinatario']) ?> · <?= e($pedido['telefono_entrega']) ?></div>
                <div><?= e($pedido['direccion_entrega']) ?>, <?= e($pedido['ciudad_entrega']) ?></div>
                <?php if ($pedido['notas']): ?><div class="text-muted-fc mt-1">Nota: <?= e($pedido['notas']) ?></div><?php endif; ?>
                <hr>
                <div><i class="bi bi-wallet2 me-1"></i><?= e(METODOS_PAGO[$pedido['metodo_pago']] ?? '') ?></div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
