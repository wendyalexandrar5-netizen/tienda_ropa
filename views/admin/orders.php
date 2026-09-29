<?php /** @var App\Core\View $view  @var array $result  @var array $statuses */ ?>
<div class="page-actions">
  <form class="filter-bar" method="get">
    <div class="input-icon"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="N.º, cliente o correo" maxlength="100"></div>
    <select class="form-select" name="estado" aria-label="Estado">
      <option value="">Todos los estados</option>
      <?php foreach ($statuses as $s): ?><option value="<?= e($s['codigo']) ?>" <?= ($q['estado'] ?? '') === $s['codigo'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select" name="canal" aria-label="Canal">
      <option value="">Todos los canales</option>
      <?php foreach (['web' => 'Tienda web', 'app' => 'App móvil', 'pos' => 'Tienda física'] as $k => $l): ?><option value="<?= $k ?>" <?= ($q['canal'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <input class="form-control" type="date" name="desde" value="<?= e($q['desde'] ?? '') ?>" aria-label="Desde">
    <input class="form-control" type="date" name="hasta" value="<?= e($q['hasta'] ?? '') ?>" aria-label="Hasta">
    <button class="btn btn-light-fc btn-sm" type="submit">Filtrar</button>
  </form>
  <a class="btn btn-dark" href="/admin/caja"><i class="bi bi-cash-stack me-1"></i>Nueva venta en tienda</a>
</div>

<div class="panel">
  <?php if ($result['items'] === []): ?>
    <?= $view->partial('empty', ['icon' => 'bi-receipt', 'heading' => 'Sin pedidos', 'text' => 'No hay pedidos con esos filtros.']) ?>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-fc">
        <thead><tr><th>Pedido</th><th>Cliente</th><th>Fecha</th><th>Canal</th><th class="text-center">Uds.</th><th class="text-end">Total</th><th>Estado</th></tr></thead>
        <tbody>
          <?php foreach ($result['items'] as $o): ?>
            <tr>
              <td><a class="fw-600" href="/admin/pedidos/<?= e($o['id']) ?>"><?= e(numero_pedido($o['numero'])) ?></a></td>
              <td><?= e($o['cliente']) ?><?php if ($o['email']): ?><div class="small text-muted-fc"><?= e($o['email']) ?></div><?php endif; ?></td>
              <td class="small"><?= e(fecha($o['created_at'], true)) ?></td>
              <td class="small"><?= ['web' => '<i class="bi bi-globe"></i> Web', 'app' => '<i class="bi bi-phone"></i> App', 'pos' => '<i class="bi bi-shop"></i> Tienda'][$o['canal']] ?? '' ?></td>
              <td class="text-center"><?= (int) $o['unidades'] ?></td>
              <td class="text-end fw-600"><?= money($o['total']) ?></td>
              <td><?= badge_estado($o['estado_color'], $o['estado_nombre']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?= $view->partial('pagination', ['page' => $result['page'], 'pages' => $result['pages']]) ?>
