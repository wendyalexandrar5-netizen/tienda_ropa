<?php /** @var App\Core\View $view  @var array $order */ $cli = $order['cliente']; $d = $order['direccion_envio']; ?>
<div class="page-actions">
  <a class="small fw-600 d-inline-flex align-items-center gap-1" href="/admin/pedidos"><i class="bi bi-arrow-left"></i> Pedidos</a>
  <div class="d-flex gap-2 align-items-center">
    <?= badge_estado($order['estado_color'], $order['estado_nombre']) ?>
    <a class="btn btn-light-fc btn-sm" href="/admin/pedidos/<?= e($order['id']) ?>/comprobante" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Comprobante</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="panel mb-4"><div class="panel-body"><?= $view->partial('order-timeline', ['order' => $order]) ?></div></div>
    <div class="panel">
      <div class="panel-head"><h2>Productos</h2><span class="small text-muted-fc"><?= e(fecha($order['created_at'], true)) ?></span></div>
      <div class="table-responsive">
        <table class="table table-fc">
          <thead><tr><th></th><th>Producto</th><th>SKU</th><th class="text-end">Precio histórico</th><th class="text-center">Cant.</th><th class="text-end">Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($order['items'] as $i): ?>
              <tr>
                <td style="width:60px"><img class="table-thumb" src="<?= safe_url($i['imagen_url']) ?>" alt=""></td>
                <td><div class="fw-600"><?= e($i['producto_nombre']) ?></div><div class="small text-muted-fc">Talla <?= e($i['talla']) ?> · <?= e($i['color']) ?></div></td>
                <td class="small"><?= e($i['sku']) ?></td>
                <td class="text-end"><?= money($i['precio_unitario']) ?></td>
                <td class="text-center"><?= (int) $i['cantidad'] ?></td>
                <td class="text-end fw-600"><?= money($i['subtotal']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="panel-body border-top ms-auto" style="max-width:340px">
        <div class="summary-row"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
        <div class="summary-row"><span>Envío</span><span><?= money($order['costo_envio']) ?></span></div>
        <div class="summary-total"><span>Total</span><span><?= money($order['total']) ?></span></div>
        <?php if ($order['pago_recibido'] !== null): ?>
          <div class="summary-row mt-2 small"><span>Recibido</span><span><?= money($order['pago_recibido']) ?></span></div>
          <div class="summary-row small"><span>Cambio</span><span><?= money($order['cambio']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <?php if ($order['siguientes_estados']): ?>
      <div class="panel mb-4">
        <div class="panel-head"><h2>Actualizar estado</h2></div>
        <div class="panel-body">
          <form data-api-form data-method="PUT" data-action="/api/admin/orders/<?= e($order['id']) ?>/status" data-reload data-success="Estado actualizado">
            <div class="d-grid gap-2 mb-3">
              <?php foreach ($order['siguientes_estados'] as $i => $s): ?>
                <label class="option-card"><input type="radio" name="estado" value="<?= e($s['codigo']) ?>" <?= $i === 0 ? 'checked' : '' ?>><div class="option-body py-2"><?= e($s['nombre']) ?><?= $s['codigo'] === 'cancelado' ? ' <span class="small text-muted-fc">(repone inventario)</span>' : '' ?></div></label>
              <?php endforeach; ?>
            </div>
            <input class="form-control mb-3" name="comentario" maxlength="300" placeholder="Comentario (opcional)">
            <button class="btn btn-dark w-100" type="submit">Guardar estado</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel mb-4">
      <div class="panel-head"><h2>Cliente</h2></div>
      <div class="panel-body small">
        <?php if ($cli): ?>
          <a class="fw-600" href="/admin/clientes/<?= e($cli['id']) ?>"><?= e(trim($cli['nombre'] . ' ' . $cli['apellido']) ?: $cli['email']) ?></a>
          <div><?= e($cli['email']) ?></div><div class="text-muted-fc"><?= e($cli['telefono'] ?? '') ?></div>
        <?php else: ?>
          <div class="fw-600"><?= e($order['cliente_nombre'] ?: 'Cliente en tienda') ?></div><div class="text-muted-fc">Venta en tienda física</div>
        <?php endif; ?>
        <div class="mt-3"><span class="text-muted-fc">Pago:</span> <?= e(metodo_pago($order['metodo_pago'])) ?> · <span class="text-muted-fc">Canal:</span> <?= e(strtoupper($order['canal'])) ?></div>
        <?php if ($d): ?>
          <hr><div class="text-muted-fc mb-1">Envío</div>
          <div class="fw-600"><?= e($d['destinatario']) ?> · <?= e($d['telefono']) ?></div>
          <div><?= e($d['direccion']) ?><?= !empty($d['detalle']) ? ', ' . e($d['detalle']) : '' ?></div>
          <div><?= e($d['ciudad']) ?>, <?= e($d['departamento']) ?></div>
        <?php endif; ?>
        <?php if ($order['notas']): ?><hr><div class="text-muted-fc mb-1">Notas del cliente</div><div><?= e($order['notas']) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>Historial</h2></div>
      <div class="panel-body small">
        <?php foreach (array_reverse($order['historial']) as $h): ?>
          <div class="mb-3"><div class="fw-600"><?= e($h['estado_nombre']) ?></div><div class="text-muted-fc"><?= e(fecha($h['fecha'], true)) ?><?= $h['comentario'] ? ' · ' . e($h['comentario']) : '' ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
