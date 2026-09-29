<?php /** @var App\Core\View $view  @var array $cart  @var array $addresses */ $r = $cart['resumen']; ?>
<section class="page-hero">
  <div class="container">
    <div class="steps mb-2"><a href="/carrito">Carrito</a><i class="bi bi-chevron-right"></i><span class="on">Envío y pago</span><i class="bi bi-chevron-right"></i><span>Confirmación</span></div>
    <h1>Finalizar compra</h1>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="row g-4 g-xl-5">
      <div class="col-lg-7">
        <?php if (!$r['todo_disponible']): ?>
          <div class="alert alert-warning">Algunos productos de tu carrito ya no tienen stock suficiente. <a class="fw-600 text-decoration-underline" href="/carrito">Revisa tu carrito</a>.</div>
        <?php endif; ?>

        <div class="card-fc mb-4">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h5 m-0"><span class="badge text-bg-dark rounded-pill me-2">1</span>Dirección de envío</h2>
              <button class="btn btn-light-fc btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaDireccion" aria-expanded="<?= $addresses ? 'false' : 'true' ?>">
                <i class="bi bi-plus-lg me-1"></i>Nueva
              </button>
            </div>

            <div class="collapse <?= $addresses ? '' : 'show' ?> mb-3" id="nuevaDireccion">
              <form class="border rounded-3 p-3" data-api-form data-method="POST" data-action="/api/addresses" data-reload data-success="Dirección guardada">
                <?= $view->partial('address-fields', ['a' => ['destinatario' => trim(($profile['nombre'] ?? '') . ' ' . ($profile['apellido'] ?? '')), 'telefono' => $profile['telefono'] ?? '']]) ?>
                <button class="btn btn-dark btn-sm mt-3" type="submit">Guardar dirección</button>
              </form>
            </div>

            <?php if ($addresses): ?>
              <div class="row g-2" role="radiogroup" aria-label="Dirección de envío">
                <?php foreach ($addresses as $i => $a): ?>
                  <div class="col-md-6">
                    <label class="option-card h-100">
                      <input type="radio" name="direccion_id" value="<?= e($a['id']) ?>" form="checkoutForm" <?= $a['es_principal'] || $i === 0 ? 'checked' : '' ?> required>
                      <div class="option-body">
                        <div class="fw-600"><?= e($a['alias']) ?> <?= $a['es_principal'] ? '<span class="p-badge p-badge--sale ms-1">Principal</span>' : '' ?></div>
                        <div class="small text-muted-fc mt-1">
                          <?= e($a['destinatario']) ?> · <?= e($a['telefono']) ?><br>
                          <?= e($a['direccion']) ?><?= $a['detalle'] ? ', ' . e($a['detalle']) : '' ?><br>
                          <?= e($a['ciudad']) ?>, <?= e($a['departamento']) ?>
                        </div>
                      </div>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <form id="checkoutForm" class="card-fc" data-api-form data-method="POST" data-action="/api/orders" data-redirect="/pedido/{id}/confirmacion" data-success="¡Pedido creado!">
          <div class="card-body">
            <h2 class="h5 mb-3"><span class="badge text-bg-dark rounded-pill me-2">2</span>Método de pago</h2>
            <div class="row g-2 mb-4">
              <div class="col-md-6">
                <label class="option-card"><input type="radio" name="metodo_pago" value="contra_entrega" checked>
                  <div class="option-body"><div class="fw-600"><i class="bi bi-cash-coin me-1"></i> Contra entrega</div><div class="small text-muted-fc">Pagas en efectivo al recibir.</div></div>
                </label>
              </div>
              <div class="col-md-6">
                <label class="option-card"><input type="radio" name="metodo_pago" value="transferencia">
                  <div class="option-body"><div class="fw-600"><i class="bi bi-bank me-1"></i> Transferencia</div><div class="small text-muted-fc">Te enviamos los datos por correo.</div></div>
                </label>
              </div>
            </div>
            <label class="form-label" for="notas">Notas para el pedido <span class="text-muted-fc fw-normal">(opcional)</span></label>
            <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="500" placeholder="Ej.: entregar en portería"></textarea>
            <input type="hidden" name="clave_idempotencia" value="<?= e($idempotencyKey) ?>">
            <div class="invalid-feedback" data-error-for="direccion_id"></div>
          </div>
        </form>
      </div>

      <div class="col-lg-5">
        <div class="card-fc summary-card">
          <div class="card-body">
            <h2 class="h5 mb-4">Tu pedido</h2>
            <?php foreach ($cart['items'] as $item): ?>
              <div class="mini-line">
                <div class="thumb"><img src="<?= safe_url($item['imagen_url']) ?>" alt=""><span class="qty-badge"><?= (int) $item['cantidad'] ?></span></div>
                <div class="flex-grow-1 small">
                  <div class="fw-600"><?= e($item['producto_nombre']) ?></div>
                  <div class="text-muted-fc">Talla <?= e($item['talla']) ?> · <?= e($item['color']) ?></div>
                </div>
                <div class="small fw-600"><?= money($item['subtotal']) ?></div>
              </div>
            <?php endforeach; ?>
            <hr>
            <div class="summary-row"><span>Subtotal</span><span><?= money($r['subtotal']) ?></span></div>
            <div class="summary-row"><span>Envío</span><span><?= $r['costo_envio'] > 0 ? money($r['costo_envio']) : 'Gratis' ?></span></div>
            <div class="summary-total"><span>Total</span><span><?= money($r['total']) ?></span></div>
            <button class="btn btn-dark btn-lg w-100 mt-4" type="submit" form="checkoutForm" <?= ($addresses && $r['todo_disponible']) ? '' : 'disabled' ?>>
              Confirmar pedido
            </button>
            <?php if (!$addresses): ?><p class="small text-center text-muted-fc mt-2 mb-0">Agrega una dirección de envío para continuar.</p><?php endif; ?>
            <p class="small text-muted-fc text-center mt-3 mb-0"><i class="bi bi-shield-check"></i> El precio y el stock se verifican de nuevo al confirmar.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
