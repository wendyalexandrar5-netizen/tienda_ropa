<?php
/** @var array $order */
$flow = ['pendiente' => ['Recibido', 'bi-receipt'], 'confirmado' => ['Confirmado', 'bi-check2'], 'preparando' => ['Preparando', 'bi-box-seam'], 'enviado' => ['Enviado', 'bi-truck'], 'entregado' => ['Entregado', 'bi-house-check']];
$keys = array_keys($flow);
$currentIdx = array_search($order['estado'], $keys, true);
?>
<?php if ($order['estado'] === 'cancelado'): ?>
  <div class="alert alert-danger d-flex gap-2 align-items-center mb-0"><i class="bi bi-x-circle"></i> Este pedido fue cancelado y el inventario fue repuesto.</div>
<?php else: ?>
  <div class="timeline" role="list" aria-label="Estado del pedido">
    <?php foreach ($flow as $code => [$label, $icon]): $idx = array_search($code, $keys, true); ?>
      <div class="tl-step <?= $currentIdx !== false && $idx <= $currentIdx ? 'done' : '' ?> <?= $idx === $currentIdx ? 'current' : '' ?>" role="listitem">
        <div class="tl-dot"><i class="bi <?= $icon ?>"></i></div><span><?= e($label) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
