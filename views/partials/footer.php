<?php $storeName = $storeConfig['nombre_tienda'] ?? 'FIRE CAT'; ?>
<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <a class="brand mb-3" href="/"><img src="/assets/img/brand/firecat-mark-blanco.png" alt="" width="38" height="38"><span><?= e($storeName) ?></span></a>
        <p class="mt-3 mb-4 pe-lg-5"><?= e($storeConfig['eslogan'] ?? '') ?>. Diseños urbanos, telas de calidad y envíos a toda Colombia.</p>
        <div class="social">
          <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
          <a href="#" aria-label="TikTok"><i class="bi bi-tiktok"></i></a>
          <?php if (!empty($storeConfig['whatsapp'])): ?>
            <a href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $storeConfig['whatsapp'])) ?>" aria-label="WhatsApp" rel="noopener" target="_blank"><i class="bi bi-whatsapp"></i></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Tienda</h6>
        <ul class="list-unstyled m-0">
          <?php foreach (array_slice($navCategories ?? [], 0, 6) as $cat): ?>
            <li><a href="/categoria/<?= e($cat['slug']) ?>"><?= e($cat['nombre']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Mi cuenta</h6>
        <ul class="list-unstyled m-0">
          <li><a href="/cuenta">Perfil</a></li>
          <li><a href="/cuenta/pedidos">Mis pedidos</a></li>
          <li><a href="/cuenta/direcciones">Direcciones</a></li>
          <li><a href="/carrito">Carrito</a></li>
        </ul>
      </div>
      <div class="col-lg-4">
        <h6>Ayuda</h6>
        <p class="mb-2"><i class="bi bi-truck me-2"></i>Envío gratis desde <?= money($storeConfig['envio_gratis_desde'] ?? 0) ?></p>
        <p class="mb-2"><i class="bi bi-arrow-repeat me-2"></i>Cambios hasta 30 días después de tu compra</p>
        <p class="mb-2"><i class="bi bi-envelope me-2"></i><?= e($storeConfig['email_contacto'] ?? '') ?></p>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e($storeName) ?>. Proyecto académico · Todos los derechos reservados.</span>
      <span class="pay-icons" aria-label="Medios de pago">Pagos: <i class="bi bi-cash-coin" title="Contra entrega"></i><i class="bi bi-bank" title="Transferencia"></i><i class="bi bi-credit-card" title="Tarjeta en tienda"></i></span>
    </div>
  </div>
</footer>
