<?php /** @var array $data */ $r = $data['resumen']; ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><div class="kpi kpi--accent"><div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div><div class="kpi-label">Ventas del mes</div><div class="kpi-value"><?= money($r['ventas_mes']) ?></div><div class="kpi-sub">Hoy: <?= money($r['ventas_hoy']) ?></div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi kpi--dark"><div class="kpi-icon"><i class="bi bi-receipt"></i></div><div class="kpi-label">Pedidos pendientes</div><div class="kpi-value"><?= (int) $r['pedidos_pendientes'] ?></div><div class="kpi-sub"><?= (int) $r['pedidos_en_proceso'] ?> en proceso · <?= (int) $r['pedidos_mes'] ?> este mes</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-icon"><i class="bi bi-people"></i></div><div class="kpi-label">Clientes</div><div class="kpi-value"><?= (int) $r['clientes_totales'] ?></div><div class="kpi-sub">+<?= (int) $r['clientes_nuevos_mes'] ?> nuevos este mes</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-icon"><i class="bi bi-box-seam"></i></div><div class="kpi-label">Inventario</div><div class="kpi-value"><?= number_format((int) $r['unidades_inventario'], 0, ',', '.') ?></div><div class="kpi-sub"><?= (int) $r['productos_activos'] ?> productos activos · <?= money($r['valor_inventario']) ?></div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-xl-8">
    <div class="panel h-100">
      <div class="panel-head"><h2>Ventas de los últimos 30 días</h2><span class="small text-muted-fc">Ticket promedio: <strong class="text-dark"><?= money($r['ticket_promedio']) ?></strong></span></div>
      <div class="panel-body"><div class="chart-box"><canvas data-chart="sales" data-series="<?= json_attr($data['ventas_30_dias']) ?>" aria-label="Gráfico de ventas diarias"></canvas></div></div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="panel h-100">
      <div class="panel-head"><h2>Más vendidos (30 días)</h2><a class="small fw-600" href="/admin/reportes">Reportes</a></div>
      <div class="panel-body bar-list">
        <?php $max = max(array_column($data['top_productos'], 'unidades') ?: [1]); ?>
        <?php foreach ($data['top_productos'] as $p): ?>
          <div class="bar-row"><div class="d-flex justify-content-between"><span class="text-truncate me-2"><?= e($p['producto_nombre']) ?></span><strong><?= (int) $p['unidades'] ?> u.</strong></div><div class="progress-fc"><div style="width: <?= (int) round($p['unidades'] / $max * 100) ?>%"></div></div></div>
        <?php endforeach; ?>
        <?php if (!$data['top_productos']): ?><p class="text-muted-fc small m-0">Aún no hay ventas en este período.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-6">
    <div class="panel h-100">
      <div class="panel-head"><h2><i class="bi bi-hourglass-split me-1"></i> Pedidos pendientes</h2><a class="small fw-600" href="/admin/pedidos?estado=pendiente">Ver todos</a></div>
      <?php if ($data['pedidos_pendientes']): ?>
        <div class="table-responsive"><table class="table table-fc">
          <thead><tr><th>Pedido</th><th>Cliente</th><th>Fecha</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach ($data['pedidos_pendientes'] as $o): ?>
              <tr><td><a class="fw-600" href="/admin/pedidos/<?= e($o['id']) ?>"><?= e(numero_pedido($o['numero'])) ?></a></td><td><?= e($o['cliente']) ?></td><td class="small text-muted-fc"><?= e(fecha($o['created_at'], true)) ?></td><td class="text-end fw-600"><?= money($o['total']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="panel-body text-muted-fc small"><i class="bi bi-check2-circle me-1"></i>No hay pedidos pendientes. ¡Todo al día!</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel h-100">
      <div class="panel-head"><h2><i class="bi bi-exclamation-triangle me-1"></i> Stock bajo (<?= (int) $r['variantes_stock_bajo'] ?>)</h2><a class="small fw-600" href="/admin/inventario?filtro=bajo">Gestionar</a></div>
      <?php if ($data['stock_bajo']): ?>
        <div class="table-responsive"><table class="table table-fc">
          <thead><tr><th>Producto</th><th>Variante</th><th class="text-end">Stock</th></tr></thead>
          <tbody>
            <?php foreach ($data['stock_bajo'] as $v): ?>
              <tr><td><a href="/admin/productos/<?= (int) $v['producto_id'] ?>"><?= e($v['producto_nombre']) ?></a></td><td class="small"><?= e($v['talla']) ?> · <?= e($v['color']) ?></td><td class="text-end"><span class="stock-pill <?= $v['stock'] == 0 ? 'out' : 'low' ?>"><?= (int) $v['stock'] ?></span></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="panel-body text-muted-fc small">Todas las variantes tienen stock suficiente.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
