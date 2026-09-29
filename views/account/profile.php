<?php /** @var App\Core\View $view  @var array $profile */ ?>
<section class="section-sm">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-3"><?= $view->partial('account-nav', get_defined_vars()) ?></div>
      <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-2">
          <div><div class="eyebrow mb-1">Mi cuenta</div><h1 class="h2 m-0">Hola, <?= e($profile['nombre'] ?: 'cliente') ?></h1></div>
          <span class="small text-muted-fc">Cliente desde <?= e(fecha($profile['fecha_registro'])) ?></span>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-sm-4"><a class="card-fc d-block p-3 h-100" href="/cuenta/pedidos"><div class="text-muted-fc small">Pedidos</div><div class="h3 m-0"><?= (int) $ordersCount ?></div></a></div>
          <div class="col-sm-4"><a class="card-fc d-block p-3 h-100" href="/cuenta/direcciones"><div class="text-muted-fc small">Direcciones</div><div class="h3 m-0"><?= (int) $addressesCount ?></div></a></div>
          <div class="col-sm-4"><a class="card-fc d-block p-3 h-100" href="/carrito"><div class="text-muted-fc small">En el carrito</div><div class="h3 m-0"><?= (int) ($cartCount ?? 0) ?></div></a></div>
        </div>

        <?php if ($recentOrders): ?>
          <div class="card-fc mb-4">
            <div class="card-body pb-2 d-flex justify-content-between align-items-center"><h2 class="h5 m-0">Pedidos recientes</h2><a class="small fw-600" href="/cuenta/pedidos">Ver todos</a></div>
            <?php foreach ($recentOrders as $o): ?>
              <a class="order-row" href="/cuenta/pedidos/<?= e($o['id']) ?>">
                <div><div class="fw-bold"><?= e(numero_pedido($o['numero'])) ?></div><div class="small text-muted-fc"><?= e(fecha($o['created_at'])) ?> · <?= (int) $o['unidades'] ?> artículo(s)</div></div>
                <div class="text-end"><?= badge_estado($o['estado_color'], $o['estado_nombre']) ?><div class="fw-bold mt-1"><?= money($o['total']) ?></div></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="row g-4">
          <div class="col-xl-7">
            <div class="card-fc h-100">
              <div class="card-body">
                <h2 class="h5 mb-4">Datos personales</h2>
                <form method="post" action="/cuenta/perfil" novalidate data-validate>
                  <?= csrf_field() ?>
                  <div class="row g-3">
                    <div class="col-sm-6">
                      <label class="form-label" for="nombre">Nombre</label>
                      <input class="form-control <?= field_error('nombre') ? 'is-invalid' : '' ?>" id="nombre" name="nombre" required minlength="2" maxlength="80" value="<?= e(old('nombre', $profile['nombre'])) ?>">
                      <div class="invalid-feedback"><?= e(field_error('nombre') ?? 'Ingresa tu nombre.') ?></div>
                    </div>
                    <div class="col-sm-6">
                      <label class="form-label" for="apellido">Apellido</label>
                      <input class="form-control <?= field_error('apellido') ? 'is-invalid' : '' ?>" id="apellido" name="apellido" required minlength="2" maxlength="80" value="<?= e(old('apellido', $profile['apellido'])) ?>">
                      <div class="invalid-feedback"><?= e(field_error('apellido') ?? 'Ingresa tu apellido.') ?></div>
                    </div>
                    <div class="col-sm-6">
                      <label class="form-label" for="email">Correo</label>
                      <input class="form-control" id="email" value="<?= e($profile['email']) ?>" disabled>
                    </div>
                    <div class="col-sm-6">
                      <label class="form-label" for="telefono">Celular</label>
                      <input class="form-control <?= field_error('telefono') ? 'is-invalid' : '' ?>" id="telefono" name="telefono" maxlength="20" pattern="[0-9+() \-]{7,20}" inputmode="tel" value="<?= e(old('telefono', $profile['telefono'] ?? '')) ?>">
                      <div class="invalid-feedback"><?= e(field_error('telefono') ?? 'Número inválido.') ?></div>
                    </div>
                  </div>
                  <button class="btn btn-dark mt-4" type="submit">Guardar cambios</button>
                </form>
              </div>
            </div>
          </div>
          <div class="col-xl-5">
            <div class="card-fc h-100">
              <div class="card-body">
                <h2 class="h5 mb-4">Seguridad</h2>
                <form method="post" action="/cuenta/password" novalidate data-validate>
                  <?= csrf_field() ?>
                  <div class="mb-3">
                    <label class="form-label" for="password_actual">Contraseña actual</label>
                    <input class="form-control <?= field_error('password_actual') ? 'is-invalid' : '' ?>" type="password" id="password_actual" name="password_actual" required maxlength="72" autocomplete="current-password">
                    <div class="invalid-feedback"><?= e(field_error('password_actual') ?? 'Ingresa tu contraseña actual.') ?></div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label" for="password">Nueva contraseña</label>
                    <input class="form-control <?= field_error('password') ? 'is-invalid' : '' ?>" type="password" id="password" name="password" required minlength="8" maxlength="72" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" autocomplete="new-password">
                    <div class="invalid-feedback"><?= e(field_error('password') ?? 'Mínimo 8 caracteres, con letras y números.') ?></div>
                  </div>
                  <div class="mb-4">
                    <label class="form-label" for="password_confirmacion">Confirmar nueva contraseña</label>
                    <input class="form-control" type="password" id="password_confirmacion" name="password_confirmacion" required maxlength="72" autocomplete="new-password" data-match="#password">
                    <div class="invalid-feedback">Las contraseñas no coinciden.</div>
                  </div>
                  <button class="btn btn-outline-dark" type="submit">Cambiar contraseña</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
