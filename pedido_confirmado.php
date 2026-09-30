<?php
require __DIR__ . '/includes/bootstrap.php';

$usuario = requerir_login();
$pedido = fila('SELECT * FROM pedidos WHERE codigo = ? AND usuario_id = ?', [get_texto('codigo', 20), $usuario['id']]);
if (!$pedido) {
    error_404('No encontramos ese pedido en tu cuenta.');
}
$items = pedido_items((int) $pedido['id']);

$titulo = 'Pedido confirmado';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <ol class="pasos-checkout" aria-label="Proceso de compra">
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span>Carrito</li>
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span>Entrega</li>
        <li class="hecho"><span class="num"><i class="bi bi-check"></i></span>Resumen</li>
        <li class="actual"><span class="num">4</span>Confirmación</li>
    </ol>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="caja text-center p-4 p-lg-5 mb-4">
                <div class="estado-vacio bg-transparent p-0 mb-3"><div class="icono bg-warning-subtle"><i class="bi bi-bag-check-fill"></i></div></div>
                <h1 class="h2">¡Gracias por tu compra, <?= e($usuario['nombre']) ?>!</h1>
                <p class="text-muted-fc mb-1">Tu pedido <strong class="text-dark"><?= e($pedido['codigo']) ?></strong> fue registrado correctamente.</p>
                <p class="text-muted-fc">Te avisaremos cuando cambie de estado. Total a pagar: <strong class="text-dark"><?= precio($pedido['total']) ?></strong>
                    <?php if ($pedido['pago_con'] !== null): ?> · Cambio: <strong class="text-dark"><?= precio((float) $pedido['pago_con'] - (float) $pedido['total']) ?></strong><?php endif; ?></p>
                <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                    <a class="btn btn-fc" href="<?= url('pedido.php', ['id' => $pedido['id']]) ?>">Ver detalle del pedido</a>
                    <a class="btn btn-outline-fc" href="<?= url('productos.php') ?>">Seguir comprando</a>
                </div>
            </div>
            <div class="caja">
                <div class="caja-titulo">Resumen</div>
                <?php foreach ($items as $i): ?>
                    <div class="d-flex justify-content-between small mb-2">
                        <span><?= (int) $i['cantidad'] ?> × <?= e($i['nombre_producto']) ?> (<?= e($i['talla']) ?> / <?= e($i['color']) ?>)</span>
                        <span><?= precio($i['subtotal']) ?></span>
                    </div>
                <?php endforeach; ?>
                <hr>
                <div class="d-flex justify-content-between small"><span>Envío</span><span><?= (float) $pedido['costo_envio'] > 0 ? precio($pedido['costo_envio']) : 'Gratis' ?></span></div>
                <div class="d-flex justify-content-between fw-bold mt-1"><span>Total</span><span><?= precio($pedido['total']) ?></span></div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
