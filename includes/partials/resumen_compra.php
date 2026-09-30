<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/** Resumen de totales. Requiere $resumen (carrito_resumen) y opcionalmente $items para listar. */
$gratisDesde = (float) ajuste('envio_gratis_desde', '0');
?>
<div class="resumen-compra">
    <h2 class="h5 fw-bold mb-3">Resumen</h2>
    <?php if (!empty($mostrarItems)): ?>
        <ul class="list-unstyled small mb-3">
            <?php foreach ($items as $i): ?>
                <li class="d-flex justify-content-between gap-2 mb-2">
                    <span><?= (int) $i['cantidad'] ?> × <?= e($i['nombre']) ?> <span class="text-muted-fc">(<?= e($i['talla']) ?> / <?= e($i['color']) ?>)</span></span>
                    <span class="text-nowrap"><?= precio($i['subtotal']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <hr>
    <?php endif; ?>
    <div class="d-flex justify-content-between mb-2"><span>Subtotal (<?= (int) $resumen['unidades'] ?> unid.)</span><span><?= precio($resumen['subtotal']) ?></span></div>
    <div class="d-flex justify-content-between mb-2">
        <span>Envío</span>
        <span><?= $resumen['envio'] > 0 ? precio($resumen['envio']) : '<span class="text-success fw-semibold">Gratis</span>' ?></span>
    </div>
    <?php if ($gratisDesde > 0 && $resumen['subtotal'] < $gratisDesde): ?>
        <div class="small text-muted-fc mb-2"><i class="bi bi-truck me-1"></i>Te faltan <strong><?= precio($gratisDesde - $resumen['subtotal']) ?></strong> para envío gratis.</div>
    <?php endif; ?>
    <hr>
    <div class="d-flex justify-content-between fs-5 fw-bold"><span>Total</span><span><?= precio($resumen['total']) ?></span></div>
    <p class="small text-muted-fc mb-0 mt-2"><i class="bi bi-shield-lock me-1"></i>Los precios y el stock se verifican al confirmar.</p>
</div>
