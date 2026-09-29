<?php /** @var array $customer  @var App\Auth\CurrentUser $currentUser */ $c = $customer; $self = $c['id'] === $currentUser->id; ?>
<div class="page-actions"><a class="small fw-600 d-inline-flex align-items-center gap-1" href="/admin/clientes"><i class="bi bi-arrow-left"></i> Clientes</a></div>
<div class="row g-4">
  <div class="col-xl-4">
    <div class="panel mb-4">
      <div class="panel-body">
        <div class="d-flex gap-3 align-items-center mb-3">
          <div class="avatar"><?= e(mb_strtoupper(mb_substr($c['nombre'] ?: $c['email'], 0, 1))) ?></div>
          <div><div class="fw-bold"><?= e(trim($c['nombre'] . ' ' . $c['apellido'])) ?></div><div class="small text-muted-fc"><?= e($c['email']) ?></div></div>
        </div>
        <div class="small">
          <div class="d-flex justify-content-between py-1"><span class="text-muted-fc">Teléfono</span><span><?= e($c['telefono'] ?? '—') ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted-fc">Registro</span><span><?= e(fecha($c['fecha_registro'])) ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted-fc">Pedidos</span><span><?= count($c['pedidos']) ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted-fc">Total comprado</span><strong><?= money($c['total_comprado']) ?></strong></div>
        </div>
      </div>
    </div>
    <div class="panel mb-4">
      <div class="panel-head"><h2>Rol y acceso</h2></div>
      <div class="panel-body">
        <?php if ($self): ?>
          <p class="small text-muted-fc m-0">No puedes cambiar tu propio rol o estado.</p>
        <?php else: ?>
          <form data-api-form data-method="PUT" data-action="/api/admin/users/<?= e($c['id']) ?>" data-reload data-success="Usuario actualizado" data-confirm="¿Confirmas el cambio de rol/estado de este usuario?">
            <label class="form-label">Rol</label>
            <select class="form-select mb-3" name="rol"><option value="cliente" <?= $c['rol'] === 'cliente' ? 'selected' : '' ?>>Cliente</option><option value="admin" <?= $c['rol'] === 'admin' ? 'selected' : '' ?>>Administrador</option></select>
            <label class="form-label">Estado</label>
            <select class="form-select mb-3" name="estado"><option value="activo" <?= $c['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option><option value="bloqueado" <?= $c['estado'] === 'bloqueado' ? 'selected' : '' ?>>Bloqueado (no puede comprar ni iniciar sesión)</option></select>
            <button class="btn btn-dark w-100" type="submit">Guardar</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>Direcciones</h2></div>
      <div class="panel-body small">
        <?php foreach ($c['direcciones'] as $a): ?>
          <div class="mb-3"><div class="fw-600"><?= e($a['alias']) ?><?= $a['es_principal'] ? ' · principal' : '' ?></div><div><?= e($a['direccion']) ?>, <?= e($a['ciudad']) ?></div><div class="text-muted-fc"><?= e($a['telefono']) ?></div></div>
        <?php endforeach; ?>
        <?php if (!$c['direcciones']): ?><span class="text-muted-fc">Sin direcciones.</span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-8">
    <div class="panel">
      <div class="panel-head"><h2>Pedidos del cliente</h2></div>
      <div class="table-responsive">
        <table class="table table-fc">
          <thead><tr><th>Pedido</th><th>Fecha</th><th class="text-end">Total</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($c['pedidos'] as $o): ?>
              <tr><td><a class="fw-600" href="/admin/pedidos/<?= e($o['id']) ?>"><?= e(numero_pedido($o['numero'])) ?></a></td><td class="small"><?= e(fecha($o['created_at'], true)) ?></td><td class="text-end fw-600"><?= money($o['total']) ?></td><td><?= badge_estado($o['estado_color'], $o['estado_nombre']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$c['pedidos']): ?><tr><td colspan="4" class="text-muted-fc small">Este cliente aún no tiene pedidos.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
