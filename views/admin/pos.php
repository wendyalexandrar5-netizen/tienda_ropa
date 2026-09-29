<?php /* Venta en tienda física: evolución de carrito.html + caja.html del proyecto original. */ ?>
<div class="row g-4" data-pos>
  <div class="col-xl-7">
    <div class="panel">
      <div class="panel-head"><h2><i class="bi bi-upc-scan me-1"></i> Buscar producto</h2></div>
      <div class="panel-body">
        <div class="input-icon mb-3"><i class="bi bi-search"></i><input class="form-control form-control-lg" type="search" data-pos-search placeholder="Nombre del producto o SKU" autocomplete="off" maxlength="60" autofocus></div>
        <div class="pos-results" data-pos-results><p class="text-muted-fc small m-0">Escribe al menos 2 letras para buscar.</p></div>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="panel">
      <div class="panel-head"><h2><i class="bi bi-cart3 me-1"></i> Venta actual</h2><button class="btn btn-light-fc btn-sm" type="button" data-pos-clear>Limpiar</button></div>
      <div class="panel-body">
        <div data-pos-lines><p class="text-muted-fc small">Agrega productos desde el buscador.</p></div>
        <hr>
        <div class="d-flex justify-content-between align-items-end mb-3"><span class="text-muted-fc">Total estimado</span><span class="pos-total" data-pos-total>$ 0</span></div>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small">Pago recibido</label><input class="form-control" type="number" min="0" step="100" data-pos-paid placeholder="0"></div>
          <div class="col-6"><label class="form-label small">Método</label><select class="form-select" data-pos-method><option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option></select></div>
          <div class="col-12"><label class="form-label small">Cliente (opcional)</label><input class="form-control" maxlength="120" data-pos-customer placeholder="Nombre del cliente"></div>
        </div>
        <div class="change-box d-flex justify-content-between mb-3"><span class="fw-600">Cambio a devolver</span><strong data-pos-change>$ 0</strong></div>
        <button class="btn btn-dark btn-lg w-100" type="button" data-pos-submit disabled>Finalizar venta</button>
        <p class="small text-muted-fc mt-2 mb-0"><i class="bi bi-shield-check"></i> El servidor recalcula precios, valida stock y descuenta el inventario en una sola transacción.</p>
      </div>
    </div>
  </div>
</div>
