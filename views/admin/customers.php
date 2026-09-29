<?php /** @var App\Core\View $view  @var array $result */ ?>
<div class="page-actions">
  <form class="filter-bar" method="get">
    <div class="input-icon"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="Nombre, correo o teléfono" maxlength="100"></div>
    <select class="form-select" name="rol" aria-label="Rol"><option value="">Todos los roles</option><option value="cliente" <?= ($q['rol'] ?? '') === 'cliente' ? 'selected' : '' ?>>Clientes</option><option value="admin" <?= ($q['rol'] ?? '') === 'admin' ? 'selected' : '' ?>>Administradores</option></select>
    <select class="form-select" name="estado" aria-label="Estado"><option value="">Todos</option><option value="activo" <?= ($q['estado'] ?? '') === 'activo' ? 'selected' : '' ?>>Activos</option><option value="bloqueado" <?= ($q['estado'] ?? '') === 'bloqueado' ? 'selected' : '' ?>>Bloqueados</option></select>
    <button class="btn btn-light-fc btn-sm" type="submit">Filtrar</button>
  </form>
  <span class="small text-muted-fc"><?= (int) $result['total'] ?> usuarios</span>
</div>
<div class="panel">
  <div class="table-responsive">
    <table class="table table-fc">
      <thead><tr><th>Usuario</th><th>Teléfono</th><th>Registro</th><th class="text-center">Pedidos</th><th class="text-end">Total comprado</th><th>Rol</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($result['items'] as $u): ?>
          <tr>
            <td><a class="fw-600" href="/admin/clientes/<?= e($u['id']) ?>"><?= e(trim($u['nombre'] . ' ' . $u['apellido']) ?: '—') ?></a><div class="small text-muted-fc"><?= e($u['email']) ?></div></td>
            <td class="small"><?= e($u['telefono'] ?? '') ?></td>
            <td class="small"><?= e(fecha($u['fecha_registro'])) ?></td>
            <td class="text-center"><?= (int) $u['pedidos'] ?></td>
            <td class="text-end fw-600"><?= money($u['total_comprado']) ?></td>
            <td><?= $u['rol'] === 'admin' ? '<span class="p-badge p-badge--dark">Admin</span>' : '<span class="small">Cliente</span>' ?></td>
            <td><span class="state-dot <?= e($u['estado']) ?>"><?= e(ucfirst($u['estado'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?= $view->partial('pagination', ['page' => $result['page'], 'pages' => $result['pages']]) ?>
