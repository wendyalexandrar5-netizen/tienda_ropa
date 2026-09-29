<?php /** @var App\Core\View $view  @var array $result  @var array $movements */ ?>
<div class="page-actions">
  <form class="filter-bar" method="get">
    <div class="input-icon"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="Producto o SKU" maxlength="100"></div>
    <select class="form-select" name="filtro" aria-label="Filtro">
      <option value="">Todas las variantes</option>
      <option value="bajo" <?= ($q['filtro'] ?? '') === 'bajo' ? 'selected' : '' ?>>Stock bajo</option>
      <option value="agotado" <?= ($q['filtro'] ?? '') === 'agotado' ? 'selected' : '' ?>>Agotadas</option>
    </select>
    <button class="btn btn-light-fc btn-sm" type="submit">Filtrar</button>
  </form>
  <span class="small text-muted-fc"><?= (int) $result['total'] ?> variantes</span>
</div>

<div class="row g-4">
  <div class="col-xxl-8">
    <div class="panel">
      <div class="table-responsive">
        <table class="table table-fc">
          <thead><tr><th>Producto</th><th>Variante</th><th>SKU</th><th class="text-center">Stock</th><th>Movimiento</th></tr></thead>
          <tbody>
            <?php foreach ($result['items'] as $v): ?>
              <tr>
                <td><a class="fw-600" href="/admin/productos/<?= (int) $v['producto_id'] ?>"><?= e($v['producto_nombre']) ?></a><?= $v['producto_estado'] !== 'activo' ? ' <span class="small text-muted-fc">(' . e($v['producto_estado']) . ')</span>' : '' ?></td>
                <td class="small"><span class="swatch-dot align-middle me-1" style="background: <?= e($v['hex']) ?>"></span><?= e($v['color']) ?> · <strong><?= e($v['talla']) ?></strong></td>
                <td class="small text-muted-fc"><?= e($v['sku']) ?></td>
                <td class="text-center"><span class="stock-pill <?= $v['stock'] == 0 ? 'out' : ($v['stock'] <= $v['stock_minimo'] ? 'low' : '') ?>"><?= (int) $v['stock'] ?></span></td>
                <td>
                  <form class="d-flex gap-1" data-api-form data-method="POST" data-action="/api/admin/inventory/adjust" data-reload data-success="Inventario actualizado">
                    <input type="hidden" name="variante_id" value="<?= (int) $v['id'] ?>">
                    <select class="form-select form-select-sm" name="tipo" style="max-width:110px" aria-label="Tipo">
                      <option value="entrada">Entrada +</option><option value="salida">Salida −</option><option value="ajuste">Ajuste ±</option>
                    </select>
                    <input class="form-control form-control-sm" type="number" name="cantidad" required style="max-width:80px" placeholder="Cant." aria-label="Cantidad">
                    <input class="form-control form-control-sm" name="motivo" required minlength="3" maxlength="300" placeholder="Motivo" style="min-width:120px" aria-label="Motivo">
                    <button class="btn btn-dark btn-sm" type="submit" aria-label="Aplicar"><i class="bi bi-check-lg"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?= $view->partial('pagination', ['page' => $result['page'], 'pages' => $result['pages']]) ?>
  </div>
  <div class="col-xxl-4">
    <div class="panel">
      <div class="panel-head"><h2><i class="bi bi-journal-text me-1"></i> Kardex · últimos movimientos</h2></div>
      <div class="panel-body p-0">
        <ul class="list-unstyled m-0 small">
          <?php foreach ($movements as $m): ?>
            <li class="d-flex justify-content-between gap-2 px-3 py-2 border-bottom">
              <div>
                <div class="fw-600"><?= e($m['producto_nombre']) ?> <span class="text-muted-fc fw-normal"><?= e($m['talla']) ?>/<?= e($m['color']) ?></span></div>
                <div class="text-muted-fc"><?= e(ucfirst(str_replace('_', ' ', $m['tipo']))) ?><?= $m['pedido_numero'] ? ' · ' . e(numero_pedido($m['pedido_numero'])) : '' ?><?= $m['motivo'] && !$m['pedido_numero'] ? ' · ' . e($m['motivo']) : '' ?></div>
                <div class="text-muted-fc" style="font-size:.72rem"><?= e(fecha($m['created_at'], true)) ?><?= $m['usuario'] ? ' · ' . e($m['usuario']) : '' ?></div>
              </div>
              <div class="text-end text-nowrap"><strong class="<?= $m['cantidad'] > 0 ? 'text-success' : 'text-danger' ?>"><?= $m['cantidad'] > 0 ? '+' : '' ?><?= (int) $m['cantidad'] ?></strong><div class="text-muted-fc">→ <?= (int) $m['stock_resultante'] ?></div></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$movements): ?><li class="p-3 text-muted-fc">Sin movimientos.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
