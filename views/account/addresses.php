<?php /** @var App\Core\View $view  @var array $addresses */ ?>
<section class="section-sm">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-3"><?= $view->partial('account-nav', get_defined_vars()) ?></div>
      <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-2">
          <div><div class="eyebrow mb-1">Envíos</div><h1 class="h2 m-0">Mis direcciones</h1></div>
          <?php if (count($addresses) < 10): ?>
            <button class="btn btn-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addrNew"><i class="bi bi-plus-lg me-1"></i>Agregar dirección</button>
          <?php endif; ?>
        </div>

        <div class="collapse <?= $addresses ? '' : 'show' ?> mb-4" id="addrNew">
          <div class="card-fc"><div class="card-body">
            <h2 class="h5 mb-3">Nueva dirección</h2>
            <form method="post" action="/cuenta/direcciones" novalidate data-validate>
              <?= csrf_field() ?>
              <?= $view->partial('address-fields', ['a' => ['destinatario' => $currentUser->displayName()]]) ?>
              <button class="btn btn-dark mt-3" type="submit">Guardar dirección</button>
            </form>
          </div></div>
        </div>

        <?php if ($addresses === []): ?>
          <div class="card-fc"><?= $view->partial('empty', ['icon' => 'bi-geo-alt', 'heading' => 'Sin direcciones guardadas', 'text' => 'Agrega una dirección para agilizar tus compras.']) ?></div>
        <?php else: ?>
          <div class="row g-3">
            <?php foreach ($addresses as $a): ?>
              <div class="col-md-6">
                <div class="card-fc address-card">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                      <div class="fw-bold"><i class="bi bi-geo-alt me-1"></i><?= e($a['alias']) ?></div>
                      <?php if ($a['es_principal']): ?><span class="badge rounded-pill">Principal</span><?php endif; ?>
                    </div>
                    <div class="small">
                      <div class="fw-600"><?= e($a['destinatario']) ?></div>
                      <div><?= e($a['direccion']) ?><?= $a['detalle'] ? ', ' . e($a['detalle']) : '' ?></div>
                      <div><?= e($a['ciudad']) ?>, <?= e($a['departamento']) ?> <?= e($a['codigo_postal'] ?? '') ?></div>
                      <div class="text-muted-fc"><?= e($a['telefono']) ?></div>
                    </div>
                    <div class="d-flex gap-3 mt-3">
                      <button class="link-remove" type="button" data-bs-toggle="collapse" data-bs-target="#edit-<?= e($a['id']) ?>">Editar</button>
                      <form method="post" action="/cuenta/direcciones/<?= e($a['id']) ?>/eliminar" data-confirm="¿Eliminar esta dirección?">
                        <?= csrf_field() ?><button class="link-remove" type="submit">Eliminar</button>
                      </form>
                    </div>
                    <div class="collapse mt-3" id="edit-<?= e($a['id']) ?>">
                      <form method="post" action="/cuenta/direcciones" novalidate data-validate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($a['id']) ?>">
                        <?= $view->partial('address-fields', ['a' => $a]) ?>
                        <button class="btn btn-dark btn-sm mt-3" type="submit">Actualizar</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
