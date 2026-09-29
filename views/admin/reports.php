<?php
$totVentas = array_sum(array_column($sales, 'total'));
$totPedidos = array_sum(array_column($sales, 'pedidos'));
$totUnidades = array_sum(array_column($sales, 'unidades'));
$totPos = array_sum(array_column($sales, 'pos'));
?>
<div class="page-actions">
  <form class="filter-bar" method="get">
    <label class="small text-muted-fc">Desde</label><input class="form-control" type="date" name="desde" value="<?= e($desde) ?>" required>
    <label class="small text-muted-fc">Hasta</label><input class="form-control" type="date" name="hasta" value="<?= e($hasta) ?>" required>
    <select class="form-select" name="agrupacion" aria-label="Agrupar por">
      <?php foreach (['day' => 'Por día', 'week' => 'Por semana', 'month' => 'Por mes'] as $k => $l): ?><option value="<?= $k ?>" <?= $agrupacion === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-dark btn-sm" type="submit">Actualizar</button>
  </form>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><div class="kpi kpi--accent"><div class="kpi-label">Ventas del período</div><div class="kpi-value"><?= money($totVentas) ?></div><div class="kpi-sub">Excluye cancelados</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Pedidos</div><div class="kpi-value"><?= (int) $totPedidos ?></div><div class="kpi-sub">Ticket: <?= money($totPedidos ? $totVentas / $totPedidos : 0) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Unidades vendidas</div><div class="kpi-value"><?= (int) $totUnidades ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi kpi--dark"><div class="kpi-label">Caja (tienda física)</div><div class="kpi-value"><?= money($totPos) ?></div><div class="kpi-sub">Online: <?= money($totVentas - $totPos) ?></div></div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-xl-8"><div class="panel h-100"><div class="panel-head"><h2>Ventas por período</h2></div><div class="panel-body"><div class="chart-box"><canvas data-chart="sales-stacked" data-series="<?= json_attr($sales) ?>" aria-label="Ventas por período"></canvas></div></div></div></div>
  <div class="col-xl-4"><div class="panel h-100"><div class="panel-head"><h2>Pedidos por estado</h2></div><div class="panel-body"><div class="chart-box"><canvas data-chart="status" data-series="<?= json_attr($byStatus) ?>" aria-label="Pedidos por estado"></canvas></div></div></div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-xl-6">
    <div class="panel h-100"><div class="panel-head"><h2>Productos más vendidos</h2></div>
      <div class="table-responsive"><table class="table table-fc">
        <thead><tr><th>#</th><th>Producto</th><th class="text-center">Unidades</th><th class="text-end">Ingresos</th></tr></thead>
        <tbody><?php foreach ($topProducts as $i => $p): ?><tr><td><?= $i + 1 ?></td><td><?= e($p['producto_nombre']) ?></td><td class="text-center"><?= (int) $p['unidades'] ?></td><td class="text-end fw-600"><?= money($p['total']) ?></td></tr><?php endforeach; ?>
        <?php if (!$topProducts): ?><tr><td colspan="4" class="text-muted-fc small">Sin ventas en el período.</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel h-100"><div class="panel-head"><h2>Categorías más vendidas</h2></div>
      <div class="panel-body bar-list">
        <?php $max = max(array_column($topCategories, 'total') ?: [1]); foreach ($topCategories as $c): ?>
          <div class="bar-row"><div class="d-flex justify-content-between"><span><?= e($c['categoria_nombre']) ?> <span class="text-muted-fc">(<?= (int) $c['unidades'] ?> u.)</span></span><strong><?= money($c['total']) ?></strong></div><div class="progress-fc"><div style="width: <?= (int) round($c['total'] / $max * 100) ?>%"></div></div></div>
        <?php endforeach; ?>
        <?php if (!$topCategories): ?><p class="text-muted-fc small m-0">Sin ventas en el período.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-6"><div class="panel h-100"><div class="panel-head"><h2>Clientes registrados (12 meses)</h2></div><div class="panel-body"><div class="chart-box"><canvas data-chart="customers" data-series="<?= json_attr($customers) ?>" aria-label="Clientes registrados"></canvas></div></div></div></div>
  <div class="col-xl-6">
    <div class="panel h-100"><div class="panel-head"><h2>Inventario bajo (<?= count($lowStock) ?>)</h2><a class="small fw-600" href="/admin/inventario?filtro=bajo">Reabastecer</a></div>
      <div class="table-responsive" style="max-height:340px"><table class="table table-fc">
        <thead><tr><th>Producto</th><th>Variante</th><th class="text-center">Stock</th><th class="text-center">Mín.</th></tr></thead>
        <tbody><?php foreach ($lowStock as $v): ?><tr><td><?= e($v['producto_nombre']) ?></td><td class="small"><?= e($v['talla']) ?> · <?= e($v['color']) ?></td><td class="text-center"><span class="stock-pill <?= $v['stock'] == 0 ? 'out' : 'low' ?>"><?= (int) $v['stock'] ?></span></td><td class="text-center"><?= (int) $v['stock_minimo'] ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
    </div>
  </div>
</div>
