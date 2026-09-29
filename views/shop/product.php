<?php
/** @var App\Core\View $view  @var array $product */
$images = $product['imagenes'] ?: [['url' => null, 'alt' => $product['nombre']]];
$isFav = in_array((int) $product['id'], $favIds, true);
$descuento = !empty($product['precio_anterior']) ? (int) round((1 - $product['precio'] / $product['precio_anterior']) * 100) : 0;
$jsonData = [
    'id' => $product['id'],
    'variantes' => array_map(static fn ($v) => [
        'id' => $v['id'], 'talla_id' => $v['talla_id'], 'talla' => $v['talla'], 'color_id' => $v['color_id'],
        'color' => $v['color'], 'precio' => $v['precio'], 'stock' => $v['stock'],
    ], $product['variantes']),
];
?>
<section class="section-sm">
  <div class="container">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/">Inicio</a></li>
        <li class="breadcrumb-item"><a href="/categoria/<?= e($product['categoria']['slug']) ?>"><?= e($product['categoria']['nombre']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($product['nombre']) ?></li>
      </ol>
    </nav>

    <div class="row g-4 g-xl-5">
      <div class="col-lg-7">
        <div class="pdp-gallery" data-gallery>
          <div class="pdp-thumbs">
            <?php foreach ($images as $i => $img): ?>
              <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-src="<?= safe_url($img['url']) ?>" aria-label="Ver imagen <?= $i + 1 ?>">
                <img src="<?= safe_url($img['url']) ?>" alt="">
              </button>
            <?php endforeach; ?>
          </div>
          <div class="pdp-main"><img src="<?= safe_url($images[0]['url']) ?>" alt="<?= e($images[0]['alt'] ?: $product['nombre']) ?>" data-main-image></div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="pdp-info">
          <div class="d-flex justify-content-between align-items-start">
            <a class="eyebrow" href="/categoria/<?= e($product['categoria']['slug']) ?>"><?= e($product['categoria']['nombre']) ?></a>
            <button type="button" class="btn btn-light-fc btn-icon <?= $isFav ? 'text-danger' : '' ?>" data-fav="<?= (int) $product['id'] ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="Favorito">
              <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            </button>
          </div>
          <h1><?= e($product['nombre']) ?></h1>
          <div class="pdp-price mb-2">
            <span data-price><?= money($product['precio']) ?></span>
            <?php if (!empty($product['precio_anterior'])): ?>
              <del><?= money($product['precio_anterior']) ?></del>
              <span class="p-badge p-badge--sale ms-2 align-middle">-<?= $descuento ?>%</span>
            <?php endif; ?>
          </div>
          <p class="small text-muted-fc mb-4">Impuestos incluidos. Envío calculado al finalizar la compra.</p>

          <form data-add-to-cart novalidate>
            <script type="application/json" id="product-data"><?= json_encode($jsonData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
            <input type="hidden" name="variante_id" value="">

            <?php if (count($product['colores']) > 0): ?>
            <div class="mb-4">
              <div class="option-label">Color <span data-color-name><?= count($product['colores']) === 1 ? e($product['colores'][0]['nombre']) : 'Selecciona un color' ?></span></div>
              <div>
                <?php foreach ($product['colores'] as $col): ?>
                  <button type="button" class="color-btn" style="background: <?= e($col['hex']) ?>" data-color="<?= (int) $col['id'] ?>" data-name="<?= e($col['nombre']) ?>" aria-label="Color <?= e($col['nombre']) ?>" title="<?= e($col['nombre']) ?>"></button>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="mb-3">
              <div class="option-label">Talla <span data-size-name>Selecciona una talla</span></div>
              <div>
                <?php foreach ($product['tallas'] as $t): ?>
                  <button type="button" class="size-btn" data-size="<?= (int) $t['id'] ?>" data-name="<?= e($t['codigo']) ?>" aria-label="Talla <?= e($t['codigo']) ?>"><?= e($t['codigo']) ?></button>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="stock-note mb-4" data-stock-note aria-live="polite">
              <?php if ((int) $product['stock_total'] === 0): ?><span class="dot" style="background:var(--fc-danger)"></span>Producto agotado temporalmente<?php endif; ?>
            </div>

            <div class="d-flex gap-2 align-items-stretch mb-3">
              <div class="qty" data-qty>
                <button type="button" data-step="-1" aria-label="Disminuir cantidad"><i class="bi bi-dash"></i></button>
                <input type="number" name="cantidad" value="1" min="1" max="20" aria-label="Cantidad" inputmode="numeric">
                <button type="button" data-step="1" aria-label="Aumentar cantidad"><i class="bi bi-plus"></i></button>
              </div>
              <button type="submit" class="btn btn-dark btn-lg flex-grow-1" data-add-btn <?= (int) $product['stock_total'] === 0 ? 'disabled' : '' ?>>
                <i class="bi bi-bag-plus me-1"></i> Agregar al carrito
              </button>
            </div>
            <div class="invalid-feedback d-block mb-3" data-add-error></div>
          </form>

          <div class="d-flex gap-4 small text-muted-fc py-3 border-top border-bottom mb-2">
            <span><i class="bi bi-truck me-1"></i>Envío 24–72 h</span>
            <span><i class="bi bi-arrow-repeat me-1"></i>Cambios 30 días</span>
            <span><i class="bi bi-cash me-1"></i>Contra entrega</span>
          </div>

          <div class="accordion accordion-fc" id="pdpInfo">
            <div class="accordion-item">
              <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#desc" aria-expanded="true">Descripción</button></h2>
              <div id="desc" class="accordion-collapse collapse show" data-bs-parent="#pdpInfo"><div class="accordion-body"><?= nl2br(e($product['descripcion'])) ?></div></div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#envios">Envíos y pagos</button></h2>
              <div id="envios" class="accordion-collapse collapse" data-bs-parent="#pdpInfo"><div class="accordion-body">Envío estándar de <?= money($storeConfig['costo_envio'] ?? 0) ?>, gratis en compras desde <?= money($storeConfig['envio_gratis_desde'] ?? 0) ?>. Paga contra entrega o por transferencia bancaria.</div></div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cambios">Cambios y devoluciones</button></h2>
              <div id="cambios" class="accordion-collapse collapse" data-bs-parent="#pdpInfo"><div class="accordion-body">Tienes 30 días para cambiar tu prenda por talla o color, siempre que conserve etiquetas y no haya sido usada.</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="section pt-4">
  <div class="container">
    <div class="section-head"><h2 class="section-title">También te puede gustar</h2></div>
    <div class="row g-3 g-lg-4 row-cols-2 row-cols-lg-4">
      <?php foreach ($related as $p): ?>
        <div class="col"><?= $view->partial('product-card', ['p' => $p, 'favIds' => $favIds]) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
