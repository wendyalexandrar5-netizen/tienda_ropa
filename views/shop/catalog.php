<?php
/** @var App\Core\View $view */
/** @var array $filters @var array $result */
$baseUrl = $category ? '/categoria/' . $category['slug'] : '/catalogo';
$orderLabels = ['destacados' => 'Destacados', 'recientes' => 'Más recientes', 'precio_asc' => 'Precio: menor a mayor', 'precio_desc' => 'Precio: mayor a menor', 'nombre' => 'Nombre (A–Z)'];
$activeCount = count($filters['tallas']) + count($filters['colores']) + ($filters['precio_min'] !== null ? 1 : 0) + ($filters['precio_max'] !== null ? 1 : 0) + ($filters['disponible'] ? 1 : 0) + ($filters['ofertas'] ? 1 : 0);
?>
<section class="page-hero">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/">Inicio</a></li>
        <?php if ($category): ?>
          <li class="breadcrumb-item"><a href="/catalogo">Tienda</a></li>
          <li class="breadcrumb-item active" aria-current="page"><?= e($category['nombre']) ?></li>
        <?php else: ?>
          <li class="breadcrumb-item active" aria-current="page">Tienda</li>
        <?php endif; ?>
      </ol>
    </nav>
    <h1><?= e($category['nombre'] ?? ($filters['q'] !== '' ? 'Búsqueda' : ($filters['ofertas'] ? 'Ofertas' : 'Toda la tienda'))) ?></h1>
    <p class="text-muted-fc mb-0">
      <?php if ($filters['q'] !== ''): ?>Resultados para “<strong class="text-dark"><?= e($filters['q']) ?></strong>” · <?php endif; ?>
      <?= $category ? e($category['descripcion'] ?? '') . ' · ' : '' ?><?= (int) $result['total'] ?> producto<?= $result['total'] === 1 ? '' : 's' ?>
    </p>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="row g-4">
      <aside class="col-lg-3">
        <div class="offcanvas-lg offcanvas-start" tabindex="-1" id="filtros" aria-labelledby="filtrosLabel">
          <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title" id="filtrosLabel">Filtros</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#filtros" aria-label="Cerrar"></button>
          </div>
          <div class="offcanvas-body d-block">
            <form class="filters" method="get" action="<?= e($baseUrl) ?>" data-filters>
              <?php if ($filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>
              <input type="hidden" name="orden" value="<?= e($filters['orden']) ?>">

              <div class="filter-group">
                <h6>Categorías</h6>
                <div class="cat-list">
                  <a href="/catalogo<?= $filters['q'] !== '' ? '?q=' . e(rawurlencode($filters['q'])) : '' ?>" class="<?= !$category ? 'active' : '' ?>">Todas</a>
                  <?php foreach ($categories as $cat): ?>
                    <a href="/categoria/<?= e($cat['slug']) ?>" class="<?= ($category['slug'] ?? '') === $cat['slug'] ? 'active' : '' ?>"><?= e($cat['nombre']) ?> <small><?= (int) $cat['productos'] ?></small></a>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="filter-group">
                <h6>Talla</h6>
                <?php foreach ($sizes as $s): ?>
                  <label class="chip-check">
                    <input type="checkbox" name="talla[]" value="<?= e($s['codigo']) ?>" <?= in_array($s['codigo'], $filters['tallas'], true) ? 'checked' : '' ?>>
                    <span><?= e($s['codigo']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

              <div class="filter-group">
                <h6>Color</h6>
                <?php foreach ($colors as $col): ?>
                  <label class="chip-check">
                    <input type="checkbox" name="color[]" value="<?= (int) $col['id'] ?>" <?= in_array((int) $col['id'], $filters['colores'], true) ? 'checked' : '' ?>>
                    <span><span class="swatch-dot" style="background: <?= e($col['hex']) ?>"></span><?= e($col['nombre']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

              <div class="filter-group">
                <h6>Precio (COP)</h6>
                <div class="d-flex gap-2 align-items-center">
                  <input class="form-control form-control-sm" type="number" name="precio_min" min="0" step="1000" placeholder="Mín" value="<?= e($filters['precio_min'] ?? '') ?>" aria-label="Precio mínimo">
                  <span class="text-muted-fc">–</span>
                  <input class="form-control form-control-sm" type="number" name="precio_max" min="0" step="1000" placeholder="Máx" value="<?= e($filters['precio_max'] ?? '') ?>" aria-label="Precio máximo">
                </div>
              </div>

              <div class="filter-group">
                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" role="switch" id="fDisp" name="disponible" value="1" <?= $filters['disponible'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="fDisp">Solo disponibles</label>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch" id="fOferta" name="ofertas" value="1" <?= $filters['ofertas'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="fOferta">Solo ofertas</label>
                </div>
              </div>

              <div class="d-grid gap-2 pt-3">
                <button class="btn btn-dark" type="submit">Aplicar filtros</button>
                <?php if ($activeCount > 0): ?><a class="btn btn-light-fc" href="<?= e($baseUrl) ?>">Limpiar filtros</a><?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </aside>

      <div class="col-lg-9">
        <div class="toolbar">
          <button class="btn btn-light-fc btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filtros" aria-controls="filtros">
            <i class="bi bi-sliders me-1"></i> Filtros <?= $activeCount ? '(' . $activeCount . ')' : '' ?>
          </button>
          <div class="active-filters d-none d-md-flex gap-2 flex-wrap">
            <?php foreach ($filters['tallas'] as $t): ?><span class="badge">Talla <?= e($t) ?></span><?php endforeach; ?>
            <?php foreach ($colors as $col): if (in_array((int) $col['id'], $filters['colores'], true)): ?><span class="badge"><?= e($col['nombre']) ?></span><?php endif; endforeach; ?>
            <?php if ($filters['ofertas']): ?><span class="badge">Ofertas</span><?php endif; ?>
          </div>
          <form method="get" action="<?= e($baseUrl) ?>" class="ms-auto d-flex align-items-center gap-2" data-autosubmit>
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['orden', 'pagina'], true)) continue; ?>
              <?php if (is_array($v)): foreach ($v as $vv): if (is_scalar($vv)): ?><input type="hidden" name="<?= e($k) ?>[]" value="<?= e($vv) ?>"><?php endif; endforeach; elseif (is_scalar($v)): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
              <?php endif; ?>
            <?php endforeach; ?>
            <label for="orden" class="small text-muted-fc text-nowrap">Ordenar por</label>
            <select class="form-select form-select-sm" id="orden" name="orden">
              <?php foreach ($orderLabels as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $filters['orden'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>

        <?php if ($result['items'] === []): ?>
          <?= $view->partial('empty', [
              'icon' => 'bi-search', 'heading' => 'No encontramos productos',
              'text' => 'Prueba con otros filtros o una búsqueda diferente.',
              'ctaUrl' => '/catalogo', 'ctaText' => 'Ver toda la tienda',
          ]) ?>
        <?php else: ?>
          <div class="row g-3 g-lg-4 row-cols-2 row-cols-md-3">
            <?php foreach ($result['items'] as $p): ?>
              <div class="col"><?= $view->partial('product-card', ['p' => $p, 'favIds' => $favIds]) ?></div>
            <?php endforeach; ?>
          </div>
          <?= $view->partial('pagination', ['page' => $result['page'], 'pages' => $result['pages']]) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
