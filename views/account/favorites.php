<?php /** @var App\Core\View $view  @var array $favorites */ ?>
<section class="section-sm">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-3"><?= $view->partial('account-nav', get_defined_vars()) ?></div>
      <div class="col-lg-9">
        <div class="mb-4"><div class="eyebrow mb-1">Lista de deseos</div><h1 class="h2 m-0">Favoritos</h1></div>
        <?php if ($favorites === []): ?>
          <div class="card-fc"><?= $view->partial('empty', ['icon' => 'bi-heart', 'heading' => 'Aún no tienes favoritos', 'text' => 'Toca el corazón en cualquier prenda para guardarla aquí.', 'ctaUrl' => '/catalogo', 'ctaText' => 'Explorar la tienda']) ?></div>
        <?php else: ?>
          <div class="row g-3 g-lg-4 row-cols-2 row-cols-md-3">
            <?php foreach ($favorites as $p): ?>
              <div class="col"><?= $view->partial('product-card', ['p' => $p, 'favIds' => $favIds]) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
