<?php
/** @var array $order */
// Comprobante imprimible / "Guardar como PDF": reemplaza la descarga con
// html2pdf de ventas.html sin depender de librerías externas.
$d = $order['direccion_envio'] ?? null;
$cliente = $order['cliente'] ?? null;
?>
<div class="receipt">
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div class="brand"><img src="/assets/img/brand/firecat-mark.png" alt="" width="44" height="44"><span><?= e($storeConfig['nombre_tienda'] ?? 'FIRE CAT') ?></span></div>
    <div class="text-end">
      <div class="h4 m-0"><?= e(numero_pedido($order['numero'])) ?></div>
      <div class="small text-muted-fc"><?= e(fecha($order['created_at'], true)) ?></div>
      <div class="mt-1"><?= badge_estado($order['estado_color'], $order['estado_nombre']) ?></div>
    </div>
  </div>
  <div class="row small mb-4">
    <div class="col-6">
      <div class="text-muted-fc text-uppercase fw-600 mb-1">Cliente</div>
      <?php if ($cliente): ?><div class="fw-600"><?= e(trim($cliente['nombre'] . ' ' . $cliente['apellido'])) ?></div><div><?= e($cliente['email']) ?></div>
      <?php else: ?><div class="fw-600"><?= e($order['cliente_nombre'] ?: 'Cliente en tienda') ?></div><?php endif; ?>
    </div>
    <div class="col-6 text-end">
      <div class="text-muted-fc text-uppercase fw-600 mb-1"><?= $d ? 'Envío' : 'Canal' ?></div>
      <?php if ($d): ?><div><?= e($d['destinatario']) ?></div><div><?= e($d['direccion']) ?>, <?= e($d['ciudad']) ?></div>
      <?php else: ?><div>Venta en tienda física</div><?php endif; ?>
      <div><?= e(metodo_pago($order['metodo_pago'])) ?></div>
    </div>
  </div>
  <table>
    <thead><tr><th>Producto</th><th>SKU</th><th class="text-center">Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($order['items'] as $item): ?>
        <tr>
          <td><strong><?= e($item['producto_nombre']) ?></strong><br><span class="text-muted-fc small">Talla <?= e($item['talla']) ?> · <?= e($item['color']) ?></span></td>
          <td class="small"><?= e($item['sku']) ?></td>
          <td class="text-center"><?= (int) $item['cantidad'] ?></td>
          <td class="text-end"><?= money($item['precio_unitario']) ?></td>
          <td class="text-end"><?= money($item['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="ms-auto mt-3" style="max-width: 300px">
    <div class="summary-row"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
    <div class="summary-row"><span>Envío</span><span><?= money($order['costo_envio']) ?></span></div>
    <div class="summary-total"><span>Total</span><span><?= money($order['total']) ?></span></div>
    <?php if ($order['pago_recibido'] !== null): ?>
      <div class="summary-row mt-2"><span>Pago recibido</span><span><?= money($order['pago_recibido']) ?></span></div>
      <div class="summary-row"><span>Cambio</span><span><?= money($order['cambio']) ?></span></div>
    <?php endif; ?>
  </div>
  <p class="small text-muted-fc mt-4 mb-0">Gracias por comprar en <?= e($storeConfig['nombre_tienda'] ?? 'FIRE CAT') ?>. Precios expresados en pesos colombianos (COP).</p>
  <div class="text-center mt-4 no-print">
    <button class="btn btn-dark" type="button" data-print><i class="bi bi-printer me-1"></i> Imprimir / Guardar PDF</button>
  </div>
</div>
