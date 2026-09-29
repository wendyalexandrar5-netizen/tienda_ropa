<?php /** @var App\Core\View $view  @var ?array $cart */ ?>
<section class="page-hero">
  <div class="container">
    <div class="steps mb-2"><span class="on">Carrito</span><i class="bi bi-chevron-right"></i><span>Envío y pago</span><i class="bi bi-chevron-right"></i><span>Confirmación</span></div>
    <h1>Tu carrito</h1>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <?php if ($cart === null): ?>
      <?= $view->partial('empty', [
          'icon' => 'bi-person-lock', 'heading' => 'Inicia sesión para ver tu carrito',
          'text' => 'Tu carrito se guarda en tu cuenta para que lo encuentres en cualquier dispositivo.',
          'ctaUrl' => '/login?next=/carrito', 'ctaText' => 'Iniciar sesión',
      ]) ?>
      <p class="text-center small">¿No tienes cuenta? <a class="fw-600 text-decoration-underline" href="/registro">Regístrate gratis</a></p>
    <?php elseif ($cart['items'] === []): ?>
      <?= $view->partial('empty', [
          'icon' => 'bi-bag', 'heading' => 'Tu carrito está vacío',
          'text' => 'Descubre nuestras prendas y agrega tus favoritas.',
          'ctaUrl' => '/catalogo', 'ctaText' => 'Explorar la tienda',
      ]) ?>
    <?php else: $r = $cart['resumen']; ?>
      <div class="row g-4 g-xl-5" data-cart-page>
        <div class="col-lg-8">
          <?php if (!$r['todo_disponible']): ?>
            <div class="alert alert-warning d-flex gap-2"><i class="bi bi-exclamation-triangle"></i><div>Algunos productos ya no tienen stock suficiente. Ajusta la cantidad o retíralos para continuar.</div></div>
          <?php endif; ?>
          <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
            <strong><?= (int) $r['unidades'] ?> artículo<?= $r['unidades'] === 1 ? '' : 's' ?></strong>
            <button class="link-remove" type="button" data-cart-clear>Vaciar carrito</button>
          </div>
          <?php foreach ($cart['items'] as $item): ?>
            <div class="cart-item <?= $item['disponible'] ? '' : 'is-unavailable' ?>" data-item="<?= (int) $item['id'] ?>">
              <a href="/producto/<?= e($item['slug']) ?>"><img src="<?= safe_url($item['imagen_url']) ?>" alt="<?= e($item['producto_nombre']) ?>"></a>
              <div>
                <h3><a href="/producto/<?= e($item['slug']) ?>"><?= e($item['producto_nombre']) ?></a></h3>
                <div class="meta mb-2">
                  Talla <strong><?= e($item['talla']) ?></strong> · <span class="swatch-dot align-middle" style="background: <?= e($item['hex']) ?>"></span> <?= e($item['color']) ?> · <?= money($item['precio_unitario']) ?>
                </div>
                <?php if (!$item['disponible']): ?>
                  <div class="small text-danger fw-600 mb-2"><i class="bi bi-exclamation-circle"></i> Solo quedan <?= (int) $item['stock'] ?> unidades</div>
                <?php elseif ((int) $item['stock'] <= 3): ?>
                  <div class="small fw-600 mb-2" style="color:var(--fc-warning)">¡Últimas <?= (int) $item['stock'] ?> unidades!</div>
                <?php endif; ?>
                <div class="d-flex align-items-center gap-3 cart-item-actions">
                  <div class="qty qty-sm" data-qty data-cart-qty="<?= (int) $item['id'] ?>">
                    <button type="button" data-step="-1" aria-label="Disminuir"><i class="bi bi-dash"></i></button>
                    <input type="number" value="<?= (int) $item['cantidad'] ?>" min="1" max="<?= max(1, min(20, (int) $item['stock'])) ?>" aria-label="Cantidad">
                    <button type="button" data-step="1" aria-label="Aumentar"><i class="bi bi-plus"></i></button>
                  </div>
                  <button class="link-remove" type="button" data-cart-remove="<?= (int) $item['id'] ?>">Eliminar</button>
                </div>
              </div>
              <div class="fw-bold text-end d-none d-sm-block"><?= money($item['subtotal']) ?></div>
            </div>
          <?php endforeach; ?>
          <a class="link-arrow mt-4" href="/catalogo"><i class="bi bi-arrow-left"></i> Seguir comprando</a>
        </div>

        <div class="col-lg-4">
          <div class="card-fc summary-card">
            <div class="card-body">
              <h2 class="h5 mb-4">Resumen del pedido</h2>
              <?php if ($r['envio_gratis_desde'] > 0): ?>
                <div class="mb-4">
                  <?php if ($r['falta_para_envio_gratis'] > 0): ?>
                    <p class="small mb-2">Te faltan <strong><?= money($r['falta_para_envio_gratis']) ?></strong> para el <strong>envío gratis</strong>.</p>
                  <?php else: ?>
                    <p class="small mb-2"><i class="bi bi-check-circle-fill text-success"></i> ¡Tu pedido tiene <strong>envío gratis</strong>!</p>
                  <?php endif; ?>
                  <div class="progress-fc"><div style="width: <?= (int) min(100, $r['subtotal'] / $r['envio_gratis_desde'] * 100) ?>%"></div></div>
                </div>
              <?php endif; ?>
              <div class="summary-row"><span>Subtotal</span><span><?= money($r['subtotal']) ?></span></div>
              <div class="summary-row"><span>Envío</span><span><?= $r['costo_envio'] > 0 ? money($r['costo_envio']) : 'Gratis' ?></span></div>
              <div class="summary-total"><span>Total</span><span><?= money($r['total']) ?></span></div>
              <a class="btn btn-dark btn-lg w-100 mt-4 <?= $r['todo_disponible'] ? '' : 'disabled' ?>" href="/checkout">Continuar al pago <i class="bi bi-lock ms-1"></i></a>
              <p class="small text-muted-fc text-center mt-3 mb-0"><i class="bi bi-shield-lock"></i> Precios y disponibilidad verificados al confirmar.</p>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
