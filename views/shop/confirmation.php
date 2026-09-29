<?php /** @var array $order */ ?>
<section class="section">
  <div class="container" style="max-width: 760px">
    <div class="text-center mb-5">
      <div class="avatar mx-auto mb-3" style="width:76px;height:76px;font-size:2rem"><i class="bi bi-check-lg"></i></div>
      <div class="eyebrow mb-2">Pedido <?= e(numero_pedido($order['numero'])) ?></div>
      <h1 class="mb-3">¡Gracias por tu compra!</h1>
      <p class="text-muted-fc">Recibimos tu pedido y lo estamos procesando. Te avisaremos cuando cambie de estado.</p>
    </div>

    <div class="card-fc mb-4">
      <div class="card-body">
        <div class="row g-3 small">
          <div class="col-sm-4"><div class="text-muted-fc">Estado</div><?= badge_estado($order['estado_color'], $order['estado_nombre']) ?></div>
          <div class="col-sm-4"><div class="text-muted-fc">Pago</div><div class="fw-600"><?= e(metodo_pago($order['metodo_pago'])) ?></div></div>
          <div class="col-sm-4"><div class="text-muted-fc">Fecha</div><div class="fw-600"><?= e(fecha($order['created_at'], true)) ?></div></div>
        </div>
        <?php if (!empty($order['direccion_envio'])): $d = $order['direccion_envio']; ?>
          <hr>
          <div class="small"><div class="text-muted-fc mb-1">Envío a</div>
            <div class="fw-600"><?= e($d['destinatario']) ?> · <?= e($d['telefono']) ?></div>
            <div><?= e($d['direccion']) ?><?= !empty($d['detalle']) ? ', ' . e($d['detalle']) : '' ?> — <?= e($d['ciudad']) ?>, <?= e($d['departamento']) ?></div>
          </div>
        <?php endif; ?>
        <?php if ($order['metodo_pago'] === 'transferencia'): ?>
          <div class="alert alert-info mt-3 mb-0 small"><i class="bi bi-bank me-1"></i> Realiza la transferencia indicando el número <strong><?= e(numero_pedido($order['numero'])) ?></strong>. Confirmaremos tu pago en máximo 24 horas.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-fc">
      <div class="card-body">
        <?php foreach ($order['items'] as $item): ?>
          <div class="mini-line">
            <div class="thumb"><img src="<?= safe_url($item['imagen_url']) ?>" alt=""><span class="qty-badge"><?= (int) $item['cantidad'] ?></span></div>
            <div class="flex-grow-1 small"><div class="fw-600"><?= e($item['producto_nombre']) ?></div><div class="text-muted-fc">Talla <?= e($item['talla']) ?> · <?= e($item['color']) ?> · <?= money($item['precio_unitario']) ?> c/u</div></div>
            <div class="small fw-600"><?= money($item['subtotal']) ?></div>
          </div>
        <?php endforeach; ?>
        <hr>
        <div class="summary-row"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
        <div class="summary-row"><span>Envío</span><span><?= $order['costo_envio'] > 0 ? money($order['costo_envio']) : 'Gratis' ?></span></div>
        <div class="summary-total"><span>Total</span><span><?= money($order['total']) ?></span></div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
      <a class="btn btn-dark" href="/cuenta/pedidos/<?= e($order['id']) ?>">Ver estado del pedido</a>
      <a class="btn btn-light-fc" href="/catalogo">Seguir comprando</a>
    </div>
  </div>
</section>
