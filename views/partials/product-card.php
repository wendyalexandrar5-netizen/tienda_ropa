<?php
/** @var array<string, mixed> $p */
$favIds = $favIds ?? [];
$isFav = in_array((int) $p['id'], $favIds, true);
$descuento = !empty($p['precio_anterior']) ? (int) round((1 - $p['precio'] / $p['precio_anterior']) * 100) : 0;
$stock = (int) ($p['stock_total'] ?? 0);
?>
<article class="product-card">
  <div class="product-media">
    <img src="<?= safe_url($p['imagen_url'] ?? null) ?>" alt="<?= e($p['nombre']) ?>" loading="lazy" width="600" height="800">
    <?php if (!empty($p['imagen_secundaria_url'])): ?>
      <img class="img-alt" src="<?= safe_url($p['imagen_secundaria_url']) ?>" alt="" loading="lazy">
    <?php endif; ?>
    <div class="product-badges">
      <?php if ($stock <= 0): ?>
        <span class="p-badge p-badge--muted">Agotado</span>
      <?php elseif ($descuento > 0): ?>
        <span class="p-badge p-badge--sale">-<?= $descuento ?>%</span>
      <?php elseif (!empty($p['destacado'])): ?>
        <span class="p-badge p-badge--dark">Top</span>
      <?php endif; ?>
    </div>
    <button type="button" class="fav-btn <?= $isFav ? 'is-active' : '' ?>" data-fav="<?= (int) $p['id'] ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="<?= $isFav ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>">
      <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
    </button>
    <?php if ($stock > 0): ?>
      <div class="quick-add"><span class="btn btn-light-fc btn-sm w-100">Ver tallas y colores</span></div>
    <?php endif; ?>
  </div>
  <div class="product-info">
    <div class="p-cat"><?= e($p['categoria_nombre'] ?? '') ?></div>
    <h3><a class="stretched-link" href="/producto/<?= e($p['slug']) ?>"><?= e($p['nombre']) ?></a></h3>
    <div class="price">
      <?php if (isset($p['precio_desde']) && (float) $p['precio_desde'] < (float) $p['precio']): ?><span class="from">Desde</span><?= money($p['precio_desde']) ?>
      <?php else: ?><?= money($p['precio']) ?><?php endif; ?>
      <?php if (!empty($p['precio_anterior'])): ?><del><?= money($p['precio_anterior']) ?></del><?php endif; ?>
    </div>
    <?php if (!empty($p['colores'])): ?>
      <div class="swatches" aria-label="Colores disponibles">
        <?php foreach (array_slice($p['colores'], 0, 5) as $col): ?>
          <span class="swatch-dot" style="background: <?= e(preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $col['hex']) ? $col['hex'] : '#ccc') ?>" title="<?= e($col['nombre']) ?>"></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</article>
