<?php /** @var App\Core\View $view  @var array $result */ ?>
<section class="section-sm">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-3"><?= $view->partial('account-nav', get_defined_vars()) ?></div>
      <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-2">
          <div><div class="eyebrow mb-1">Historial</div><h1 class="h2 m-0">Mis pedidos</h1></div>
          <form method="get" data-autosubmit>
            <select class="form-select form-select-sm" name="estado" aria-label="Filtrar por estado">
              <option value="">Todos los estados</option>
              <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s['codigo']) ?>" <?= $estado === $s['codigo'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <?php if ($result['items'] === []): ?>
          <div class="card-fc"><?= $view->partial('empty', ['icon' => 'bi-bag', 'heading' => 'Aún no tienes pedidos', 'text' => 'Cuando compres, aquí podrás seguir el estado de tus pedidos.', 'ctaUrl' => '/catalogo', 'ctaText' => 'Empezar a comprar']) ?></div>
        <?php else: ?>
          <div class="card-fc overflow-hidden">
            <?php foreach ($result['items'] as $o): ?>
              <a class="order-row" href="/cuenta/pedidos/<?= e($o['id']) ?>">
                <div class="d-flex gap-3 align-items-center">
                  <div class="thumbs-stack d-none d-sm-flex">
                    <?php foreach ($o['miniaturas'] as $img): ?><img src="<?= safe_url($img) ?>" alt=""><?php endforeach; ?>
                  </div>
                  <div>
                    <div class="fw-bold"><?= e(numero_pedido($o['numero'])) ?></div>
                    <div class="small text-muted-fc"><?= e(fecha($o['created_at'], true)) ?> · <?= (int) $o['unidades'] ?> artículo(s) · <?= e(metodo_pago($o['metodo_pago'])) ?></div>
                  </div>
                </div>
                <div class="text-sm-end"><?= badge_estado($o['estado_color'], $o['estado_nombre']) ?><div class="fw-bold mt-1"><?= money($o['total']) ?></div></div>
              </a>
            <?php endforeach; ?>
          </div>
          <?= $view->partial('pagination', ['page' => $result['page'], 'pages' => $result['pages']]) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
