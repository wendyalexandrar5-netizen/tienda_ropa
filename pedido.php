<?php
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$id = es_post() ? post_int('pedido_id') : get_int('id');
// El pedido se busca SIEMPRE junto con el usuario de la sesión: un cliente no puede ver pedidos ajenos cambiando el id.
$pedido = fila('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?', [$id, $usuario['id']]);
if (!$pedido) {
    error_404('No encontramos ese pedido en tu cuenta.');
}

if (es_post() && post_texto('accion', 20) === 'cancelar') {
    try {
        if ($pedido['estado'] !== 'pendiente') {
            throw new DomainException('Sólo puedes cancelar pedidos que estén pendientes. Contáctanos para ayudarte.');
        }
        cambiar_estado_pedido((int) $pedido['id'], 'cancelado', (int) $usuario['id'], 'Cancelado por el cliente');
        flash('success', "El pedido {$pedido['codigo']} fue cancelado.");
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
    }
    redirigir('pedido.php', ['id' => $pedido['id']]);
}

$items = pedido_items((int) $pedido['id']);
$historial = pedido_historial((int) $pedido['id']);
$flujo = ['pendiente', 'confirmado', 'preparado', 'enviado', 'entregado'];
$posicion = array_search($pedido['estado'], $flujo, true);

$titulo = 'Pedido ' . $pedido['codigo'];
$seccion = 'pedidos';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <nav aria-label="breadcrumb" class="no-imprimir"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= url('index.php') ?>">Inicio</a></li>
        <li class="breadcrumb-item"><a href="<?= url('mis_pedidos.php') ?>">Mis pedidos</a></li>
        <li class="breadcrumb-item active"><?= e($pedido['codigo']) ?></li>
    </ol></nav>
    <div class="row g-4">
        <aside class="col-lg-3 no-imprimir"><?php require __DIR__ . '/includes/partials/menu_cuenta.php'; ?></aside>
        <section class="col-lg-9">
            <div class="solo-imprimir mb-3"><strong><?= e(ajuste('nombre_tienda')) ?></strong> · Comprobante de pedido</div>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <h1 class="h2 mb-1">Pedido <?= e($pedido['codigo']) ?></h1>
                    <div class="text-muted-fc"><?= fecha($pedido['fecha_pedido']) ?> · <?= estado_pedido_badge($pedido['estado']) ?></div>
                </div>
                <div class="d-flex gap-2 no-imprimir">
                    <button class="btn btn-outline-fc" type="button" data-imprimir><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
                    <?php if ($pedido['estado'] === 'pendiente'): ?>
                        <form method="post" action="<?= url('pedido.php') ?>" data-confirmar="¿Seguro que deseas cancelar este pedido? Esta acción no se puede deshacer.">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">
                            <input type="hidden" name="accion" value="cancelar">
                            <button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i>Cancelar pedido</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($pedido['estado'] !== 'cancelado'): ?>
                <div class="caja mb-4 no-imprimir">
                    <div class="row text-center g-2">
                        <?php foreach ($flujo as $n => $estado): [$texto, , $icono] = ESTADOS_PEDIDO[$estado]; $hecho = $posicion !== false && $n <= $posicion; ?>
                            <div class="col">
                                <div class="mx-auto mb-1 rounded-circle d-flex align-items-center justify-content-center <?= $hecho ? 'bg-dark text-warning' : 'bg-light text-muted' ?>" style="width:44px;height:44px"><i class="bi <?= $icono ?>"></i></div>
                                <div class="small fw-semibold <?= $hecho ? '' : 'text-muted' ?>"><?= e($texto) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-md-7">
                    <div class="caja">
                        <div class="caja-titulo">Productos</div>
                        <?php foreach ($items as $i): ?>
                            <div class="item-carrito d-flex gap-3 align-items-center">
                                <img src="<?= imagen_url($i['imagen']) ?>" alt="" class="no-imprimir">
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= e($i['nombre_producto']) ?></div>
                                    <div class="small text-muted-fc">Talla <?= e($i['talla']) ?> · <?= e($i['color']) ?> · <?= (int) $i['cantidad'] ?> × <?= precio($i['precio_unitario']) ?></div>
                                </div>
                                <div class="fw-bold"><?= precio($i['subtotal']) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <hr>
                        <div class="d-flex justify-content-between mb-1"><span>Subtotal</span><span><?= precio($pedido['subtotal']) ?></span></div>
                        <div class="d-flex justify-content-between mb-1"><span>Envío</span><span><?= (float) $pedido['costo_envio'] > 0 ? precio($pedido['costo_envio']) : 'Gratis' ?></span></div>
                        <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span><?= precio($pedido['total']) ?></span></div>
                        <?php if ($pedido['pago_con'] !== null): ?>
                            <div class="d-flex justify-content-between small text-muted-fc mt-1"><span>Pagas con <?= precio($pedido['pago_con']) ?></span><span>Cambio: <?= precio((float) $pedido['pago_con'] - (float) $pedido['total']) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="caja mb-3">
                        <div class="caja-titulo"><i class="bi bi-geo-alt me-1"></i>Entrega</div>
                        <div class="fw-semibold"><?= e($pedido['nombre_destinatario']) ?></div>
                        <div class="small text-muted-fc"><?= e($pedido['telefono_entrega']) ?></div>
                        <div class="small"><?= e($pedido['direccion_entrega']) ?>, <?= e($pedido['ciudad_entrega']) ?></div>
                        <?php if ($pedido['notas']): ?><div class="small text-muted-fc mt-2">Nota: <?= e($pedido['notas']) ?></div><?php endif; ?>
                        <hr>
                        <div class="small"><i class="bi bi-wallet2 me-1"></i><?= e(METODOS_PAGO[$pedido['metodo_pago']] ?? '') ?></div>
                    </div>
                    <div class="caja">
                        <div class="caja-titulo"><i class="bi bi-clock-history me-1"></i>Seguimiento</div>
                        <ul class="linea-tiempo mb-0">
                            <?php foreach ($historial as $h): ?>
                                <li>
                                    <div class="fw-semibold"><?= e(estado_pedido_texto($h['estado_nuevo'])) ?></div>
                                    <div class="small text-muted-fc"><?= fecha($h['fecha']) ?></div>
                                    <?php if ($h['comentario']): ?><div class="small"><?= e($h['comentario']) ?></div><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
