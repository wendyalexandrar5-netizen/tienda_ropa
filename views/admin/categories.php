<?php /** @var array $categories */ ?>
<div class="row g-4">
  <div class="col-xl-8">
    <div class="panel">
      <div class="panel-head"><h2>Categorías (<?= count($categories) ?>)</h2></div>
      <div class="table-responsive">
        <table class="table table-fc">
          <thead><tr><th></th><th>Nombre</th><th class="text-center">Productos</th><th class="text-center">Orden</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
              <tr>
                <td style="width:60px"><img class="table-thumb" src="<?= safe_url($c['imagen_url']) ?>" alt=""></td>
                <td><div class="fw-600"><?= e($c['nombre']) ?></div><div class="small text-muted-fc">/categoria/<?= e($c['slug']) ?></div></td>
                <td class="text-center"><?= (int) $c['productos'] ?></td>
                <td class="text-center"><?= (int) $c['orden'] ?></td>
                <td><span class="state-dot <?= $c['activa'] ? 'activo' : 'inactivo' ?>"><?= $c['activa'] ? 'Activa' : 'Oculta' ?></span></td>
                <td class="text-end text-nowrap">
                  <button class="btn btn-light-fc btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#cat-<?= (int) $c['id'] ?>" aria-label="Editar"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-light-fc btn-sm" type="button" data-api="DELETE /api/admin/categories/<?= (int) $c['id'] ?>" data-confirm="¿Eliminar la categoría <?= e($c['nombre']) ?>?" data-reload data-success="Categoría eliminada" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
                </td>
              </tr>
              <tr class="collapse" id="cat-<?= (int) $c['id'] ?>"><td colspan="6" class="bg-light">
                <form class="row g-2" data-api-form data-method="PUT" data-action="/api/admin/categories/<?= (int) $c['id'] ?>" data-reload data-success="Categoría actualizada">
                  <div class="col-md-4"><input class="form-control form-control-sm" name="nombre" value="<?= e($c['nombre']) ?>" required maxlength="60" aria-label="Nombre"></div>
                  <div class="col-md-3"><input class="form-control form-control-sm" name="slug" value="<?= e($c['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80" aria-label="Slug"></div>
                  <div class="col-md-2"><input class="form-control form-control-sm" type="number" name="orden" value="<?= (int) $c['orden'] ?>" min="0" aria-label="Orden"></div>
                  <div class="col-md-3 d-flex align-items-center"><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="activa" value="1" <?= $c['activa'] ? 'checked' : '' ?> id="ca-<?= (int) $c['id'] ?>"><label class="form-check-label small" for="ca-<?= (int) $c['id'] ?>">Visible</label></div></div>
                  <div class="col-md-6"><input class="form-control form-control-sm" name="descripcion" value="<?= e($c['descripcion'] ?? '') ?>" maxlength="500" placeholder="Descripción" aria-label="Descripción"></div>
                  <div class="col-md-4"><input class="form-control form-control-sm" name="imagen_url" value="<?= e($c['imagen_url'] ?? '') ?>" maxlength="500" placeholder="/assets/img/… o https://…" aria-label="Imagen"></div>
                  <div class="col-md-2"><button class="btn btn-dark btn-sm w-100" type="submit">Guardar</button></div>
                </form>
              </td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="panel">
      <div class="panel-head"><h2>Nueva categoría</h2></div>
      <div class="panel-body">
        <form data-api-form data-method="POST" data-action="/api/admin/categories" data-reload data-success="Categoría creada">
          <div class="mb-3"><label class="form-label">Nombre *</label><input class="form-control" name="nombre" required minlength="2" maxlength="60"><div class="invalid-feedback" data-error-for="nombre"></div></div>
          <div class="mb-3"><label class="form-label">Slug</label><input class="form-control" name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80" placeholder="automático"><div class="invalid-feedback" data-error-for="slug"></div></div>
          <div class="mb-3"><label class="form-label">Descripción</label><textarea class="form-control" name="descripcion" rows="2" maxlength="500"></textarea></div>
          <div class="mb-3"><label class="form-label">Imagen (URL)</label><input class="form-control" name="imagen_url" maxlength="500" placeholder="/assets/img/productos/1.jpg"><div class="invalid-feedback" data-error-for="imagen_url"></div></div>
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label">Orden</label><input class="form-control" type="number" name="orden" value="0" min="0"></div>
            <div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="activa" value="1" id="newActiva" checked><label class="form-check-label" for="newActiva">Visible</label></div></div>
          </div>
          <button class="btn btn-dark w-100" type="submit">Crear categoría</button>
        </form>
      </div>
    </div>
  </div>
</div>
