<?php /** @var App\Core\View $view  @var array $order */ ?>
<section class="section-sm">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-3 d-none d-lg-block"><?= $view->partial('account-nav', get_defined_vars()) ?></div>
      <div class="col-lg-9">
        <a class="small fw-600 d-inline-flex align-items-center gap-1 mb-3" href="/cuenta/pedidos"><i class="bi bi-arrow-left"></i> Mis pedidos</a>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
          <div>
            <h1 class="h2 mb-1">Pedido <?= e(numero_pedido($order['numero'])) ?></h1>
            <div class="text-muted-fc small">Realizado el <?= e(fecha($order['created_at'], true)) ?></div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-light-fc btn-sm" href="/cuenta/pedidos/<?= e($order['id']) ?>/comprobante" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Comprobante</a>
            <?php if ($order['puede_cancelar']): ?>
              <form method="post" action="/cuenta/pedidos/<?= e($order['id']) ?>/cancelar" data-confirm="¿Seguro que deseas cancelar este pedido?">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger btn-sm rounded-pill" type="submit"><i class="bi bi-x-circle me-1"></i>Cancelar pedido</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card-fc mb-4"><div class="card-body"><?= $view->partial('order-timeline', ['order' => $order]) ?></div></div>

        <div class="row g-4">
          <div class="col-xl-7">
            <div class="card-fc">
              <div class="card-body">
                <h2 class="h5 mb-3">Productos</h2>
                <?php foreach ($order['items'] as $item): ?>
                  <div class="mini-line">
                    <div class="thumb"><img src="<?= safe_url($item['imagen_url']) ?>" alt=""><span class="qty-badge"><?= (int) $item['cantidad'] ?></span></div>
                    <div class="flex-grow-1 small">
                      <div class="fw-600"><?php if ($item['slug']): ?><a href="/producto/<?= e($item['slug']) ?>"><?= e($item['producto_nombre']) ?></a><?php else: ?><?= e($item['producto_nombre']) ?><?php endif; ?></div>
                      <div class="text-muted-fc">Talla <?= e($item['talla']) ?> · <?= e($item['color']) ?> · <?= money($item['precio_unitario']) ?> c/u</div>
                    </div>
                    <div class="small fw-600"><?= money($item['subtotal']) ?></div>
                  </div>
                <?php endforeach; ?>
                <hr>
                <div class="summary-row"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
                <div class="summary-row"><span>Envío</span><span><?= $order['costo_envio'] > 0 ? money($order['costo_envio']) : 'Gratis' ?></span></div>
                <div class="summary-total"><span>Total</span><span><?= money($order['total']) ?></span></div>
              </div>
            </div>
          </div>
          <div class="col-xl-5">
            <div class="card-fc mb-4">
              <div class="card-body small">
                <h2 class="h6 text-uppercase mb-3">Estado actual</h2>
                <?= badge_estado($order['estado_color'], $order['estado_nombre']) ?>
                <h2 class="h6 text-uppercase mt-4 mb-2">Pago</h2>
                <div><?= e(metodo_pago($order['metodo_pago'])) ?></div>
                <?php if (!empty($order['direccion_envio'])): $d = $order['direccion_envio']; ?>
                  <h2 class="h6 text-uppercase mt-4 mb-2">Envío a</h2>
                  <div class="fw-600"><?= e($d['destinatario']) ?></div>
                  <div><?= e($d['direccion']) ?><?= !empty($d['detalle']) ? ', ' . e($d['detalle']) : '' ?></div>
                  <div><?= e($d['ciudad']) ?>, <?= e($d['departamento']) ?></div>
                  <div class="text-muted-fc"><?= e($d['telefono']) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['notas'])): ?><h2 class="h6 text-uppercase mt-4 mb-2">Notas</h2><div><?= e($order['notas']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="card-fc">
              <div class="card-body small">
                <h2 class="h6 text-uppercase mb-3">Historial</h2>
                <ul class="list-unstyled m-0">
                  <?php foreach (array_reverse($order['historial']) as $h): ?>
                    <li class="d-flex gap-2 mb-3"><i class="bi bi-circle-fill" style="font-size:.5rem;margin-top:.45rem"></i>
                      <div><div class="fw-600"><?= e($h['estado_nombre']) ?></div><div class="text-muted-fc"><?= e(fecha($h['fecha'], true)) ?><?= $h['comentario'] ? ' · ' . e($h['comentario']) : '' ?></div></div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
