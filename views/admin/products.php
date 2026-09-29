<?php /** @var App\Core\View $view  @var array $result */ ?>
<div class="page-actions">
  <form class="filter-bar" method="get">
    <div class="input-icon"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="Nombre o SKU" maxlength="100"></div>
    <select class="form-select" name="categoria_id" aria-label="Categoría">
      <option value="">Todas las categorías</option>
      <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) ($q['categoria_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select" name="estado" aria-label="Estado">
      <option value="">Todos los estados</option>
      <?php foreach (['activo' => 'Activo', 'borrador' => 'Borrador', 'inactivo' => 'Inactivo'] as $k => $l): ?><option value="<?= $k ?>" <?= ($q['estado'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-light-fc btn-sm" type="submit">Filtrar</button>
  </form>
  <a class="btn btn-dark" href="/admin/productos/nuevo"><i class="bi bi-plus-lg me-1"></i>Nuevo producto</a>
</div>

<div class="panel">
  <?php if ($result['items'] === []): ?>
    <?= $view->partial('empty', ['icon' => 'bi-bag', 'heading' => 'Sin productos', 'text' => 'No hay productos con esos filtros.', 'ctaUrl' => '/admin/productos/nuevo', 'ctaText' => 'Crear producto']) ?>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-fc">
        <thead><tr><th></th><th>Producto</th><th>Categoría</th><th class="text-end">Precio</th><th class="text-center">Variantes</th><th class="text-center">Stock</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($result['items'] as $p): ?>
            <tr>
              <td style="width:60px"><img class="table-thumb" src="<?= safe_url($p['imagen_url']) ?>" alt=""></td>
              <td><a class="fw-600" href="/admin/productos/<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></a><?= $p['destacado'] ? ' <i class="bi bi-star-fill text-warning small" title="Destacado"></i>' : '' ?><div class="small text-muted-fc">/<?= e($p['slug']) ?></div></td>
              <td><?= e($p['categoria_nombre']) ?></td>
              <td class="text-end fw-600"><?= money($p['precio']) ?><?php if ($p['precio_anterior']): ?><div class="small text-muted-fc"><del><?= money($p['precio_anterior']) ?></del></div><?php endif; ?></td>
              <td class="text-center"><?= (int) $p['variantes'] ?><?= $p['variantes_stock_bajo'] ? '<div class="small" style="color:var(--fc-warning)">' . (int) $p['variantes_stock_bajo'] . ' bajas</div>' : '' ?></td>
              <td class="text-center"><span class="stock-pill <?= $p['stock_total'] == 0 ? 'out' : ($p['variantes_stock_bajo'] ? 'low' : '') ?>"><?= (int) $p['stock_total'] ?></span></td>
              <td><span class="state-dot <?= e($p['estado']) ?>"><?= e(ucfirst($p['estado'])) ?></span></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-light-fc btn-sm" href="/admin/productos/<?= (int) $p['id'] ?>" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                <a class="btn btn-light-fc btn-sm" href="/producto/<?= e($p['slug']) ?>" target="_blank" rel="noopener" aria-label="Ver en tienda"><i class="bi bi-box-arrow-up-right"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?= $view->partial('pagination', ['page' => $result['page'], 'pages' => $result['pages']]) ?>
