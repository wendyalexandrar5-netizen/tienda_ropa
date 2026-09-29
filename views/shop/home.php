<?php
/** @var App\Core\View $view */
$fotos = range(1, 20);
$filas = [
    [1, 16, 2, 3, 11, 19, 10, 13],
    [17, 3, 1, 10, 12, 15, 9, 18],
    [7, 4, 6, 14, 9, 20, 5, 8],
];
?>
<section class="hero">
  <div class="container">
    <div class="hero-inner">
      <div class="hero-copy">
        <div class="eyebrow text-dark mb-3">Nueva colección · Streetwear</div>
        <h1>Ropa con <em>actitud</em> urbana</h1>
        <p class="mb-4"><?= e($storeConfig['eslogan'] ?? '') ?>. Hoodies, conjuntos y básicos diseñados para destacar, con envíos a todo el país.</p>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-dark btn-lg" href="/catalogo">Comprar ahora <i class="bi bi-arrow-right ms-1"></i></a>
          <a class="btn btn-outline-dark btn-lg" href="/catalogo?ofertas=1">Ver ofertas</a>
        </div>
        <div class="hero-stats">
          <div><strong>+20</strong><span>diseños exclusivos</span></div>
          <div><strong>24–72 h</strong><span>tiempo de envío</span></div>
          <div><strong>30 días</strong><span>para cambios</span></div>
        </div>
      </div>
      <div class="hero-gallery" aria-hidden="true">
        <?php foreach ($filas as $i => $fila): ?>
          <div class="gallery-row <?= $i % 2 ? 'reverse' : '' ?>">
            <?php foreach (array_merge($fila, $fila) as $n): ?>
              <img src="/assets/img/productos/<?= (int) $n ?>.jpg" alt="" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section class="perks">
  <div class="container">
    <div class="row">
      <div class="col-6 col-lg-3"><div class="perk"><i class="bi bi-truck"></i><div><strong>Envío gratis</strong><span>Desde <?= money($storeConfig['envio_gratis_desde'] ?? 0) ?></span></div></div></div>
      <div class="col-6 col-lg-3"><div class="perk"><i class="bi bi-cash-coin"></i><div><strong>Pago contra entrega</strong><span>Paga al recibir</span></div></div></div>
      <div class="col-6 col-lg-3"><div class="perk"><i class="bi bi-arrow-repeat"></i><div><strong>Cambios fáciles</strong><span>Hasta 30 días</span></div></div></div>
      <div class="col-6 col-lg-3"><div class="perk"><i class="bi bi-shield-check"></i><div><strong>Compra segura</strong><span>Datos protegidos</span></div></div></div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head">
      <div><div class="eyebrow mb-2">Explora</div><h2 class="section-title">Compra por categoría</h2></div>
      <a class="link-arrow" href="/catalogo">Ver todo <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-3 g-lg-4">
      <?php foreach ($categories as $cat): ?>
        <div class="col-6 col-md-4 col-xl-2">
          <a class="cat-tile" href="/categoria/<?= e($cat['slug']) ?>">
            <img src="<?= safe_url($cat['imagen_url']) ?>" alt="" loading="lazy">
            <div class="cat-label">
              <div><h3><?= e($cat['nombre']) ?></h3><small><?= (int) $cat['productos'] ?> productos</small></div>
              <i class="bi bi-arrow-up-right"></i>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($featured): ?>
<section class="section pt-0">
  <div class="container">
    <div class="section-head">
      <div><div class="eyebrow mb-2">Lo más buscado</div><h2 class="section-title">Destacados</h2></div>
      <a class="link-arrow" href="/catalogo?destacado=1">Ver destacados <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-3 g-lg-4 row-cols-2 row-cols-md-3 row-cols-lg-4">
      <?php foreach ($featured as $p): ?>
        <div class="col"><?= $view->partial('product-card', ['p' => $p, 'favIds' => $favIds]) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section pt-0">
  <div class="container">
    <div class="promo">
      <div class="promo-copy">
        <div class="eyebrow mb-3" style="color:var(--fc-accent)">Edición limitada</div>
        <h2>Hecho para <span>destacar</span></h2>
        <p class="my-4 text-white-50">Prendas con estampados propios, algodón perchado y cortes oversize. Cuando se agotan, no vuelven.</p>
        <div><a class="btn btn-accent btn-lg" href="/categoria/sudaderas">Ver hoodies</a></div>
      </div>
      <div class="promo-img"><img src="/assets/img/productos/10.jpg" alt="Hoodie Fire Street" loading="lazy"></div>
    </div>
  </div>
</section>

<?php if ($sale): ?>
<section class="section pt-0">
  <div class="container">
    <div class="section-head">
      <div><div class="eyebrow mb-2">Precios especiales</div><h2 class="section-title">En oferta</h2></div>
      <a class="link-arrow" href="/catalogo?ofertas=1">Todas las ofertas <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-3 g-lg-4 row-cols-2 row-cols-lg-4">
      <?php foreach ($sale as $p): ?>
        <div class="col"><?= $view->partial('product-card', ['p' => $p, 'favIds' => $favIds]) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section pt-0">
  <div class="container">
    <div class="section-head">
      <div><div class="eyebrow mb-2">Recién llegados</div><h2 class="section-title">Nuevo en la tienda</h2></div>
      <a class="link-arrow" href="/catalogo?orden=recientes">Ver novedades <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-3 g-lg-4 row-cols-2 row-cols-lg-4">
      <?php foreach ($newest as $p): ?>
        <div class="col"><?= $view->partial('product-card', ['p' => $p, 'favIds' => $favIds]) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
