<?php /** @var array $sizes  @var array $colors */ ?>
<p class="text-muted-fc">Las tallas y colores son catálogos maestros. Cada <strong>variante</strong> de producto combina una talla y un color con su propio stock (se gestionan desde la ficha de cada producto).</p>
<div class="row g-4">
  <div class="col-xl-6">
    <div class="panel">
      <div class="panel-head"><h2><i class="bi bi-rulers me-1"></i> Tallas</h2></div>
      <div class="table-responsive">
        <table class="table table-fc">
          <thead><tr><th>Código</th><th>Nombre</th><th>Orden</th><th class="text-center">Uso</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($sizes as $s): ?>
              <tr><td colspan="5" class="p-0">
                <form class="d-flex gap-2 align-items-center px-3 py-2" data-api-form data-method="PUT" data-action="/api/admin/sizes/<?= (int) $s['id'] ?>" data-reload data-success="Talla actualizada">
                  <input class="form-control form-control-sm fw-600" name="codigo" value="<?= e($s['codigo']) ?>" required maxlength="10" style="max-width:80px" aria-label="Código">
                  <input class="form-control form-control-sm" name="nombre" value="<?= e($s['nombre']) ?>" required maxlength="40" aria-label="Nombre">
                  <input class="form-control form-control-sm" type="number" name="orden" value="<?= (int) $s['orden'] ?>" min="0" style="max-width:70px" aria-label="Orden">
                  <span class="small text-muted-fc text-nowrap" style="min-width:70px"><?= (int) $s['variantes'] ?> var.</span>
                  <button class="btn btn-light-fc btn-sm" type="submit" aria-label="Guardar"><i class="bi bi-check-lg"></i></button>
                  <button class="btn btn-light-fc btn-sm" type="button" data-api="DELETE /api/admin/sizes/<?= (int) $s['id'] ?>" data-confirm="¿Eliminar la talla <?= e($s['codigo']) ?>?" data-reload aria-label="Eliminar" <?= $s['variantes'] ? 'disabled title="En uso"' : '' ?>><i class="bi bi-trash"></i></button>
                </form>
              </td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="panel-body border-top">
        <form class="d-flex gap-2" data-api-form data-method="POST" data-action="/api/admin/sizes" data-reload data-success="Talla creada">
          <input class="form-control form-control-sm" name="codigo" required maxlength="10" placeholder="Código (ej. 3XL)" style="max-width:140px">
          <input class="form-control form-control-sm" name="nombre" required maxlength="40" placeholder="Nombre">
          <input class="form-control form-control-sm" type="number" name="orden" min="0" value="7" style="max-width:80px" aria-label="Orden">
          <button class="btn btn-dark btn-sm text-nowrap" type="submit"><i class="bi bi-plus-lg"></i> Agregar</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="panel">
      <div class="panel-head"><h2><i class="bi bi-palette me-1"></i> Colores</h2></div>
      <div class="table-responsive">
        <table class="table table-fc">
          <tbody>
            <?php foreach ($colors as $c): ?>
              <tr><td class="p-0">
                <form class="d-flex gap-2 align-items-center px-3 py-2" data-api-form data-method="PUT" data-action="/api/admin/colors/<?= (int) $c['id'] ?>" data-reload data-success="Color actualizado">
                  <input class="form-control form-control-color" type="color" name="hex" value="<?= e($c['hex']) ?>" aria-label="Color">
                  <input class="form-control form-control-sm" name="nombre" value="<?= e($c['nombre']) ?>" required maxlength="40" aria-label="Nombre">
                  <span class="small text-muted-fc text-nowrap" style="min-width:70px"><?= (int) $c['variantes'] ?> var.</span>
                  <button class="btn btn-light-fc btn-sm" type="submit" aria-label="Guardar"><i class="bi bi-check-lg"></i></button>
                  <button class="btn btn-light-fc btn-sm" type="button" data-api="DELETE /api/admin/colors/<?= (int) $c['id'] ?>" data-confirm="¿Eliminar el color <?= e($c['nombre']) ?>?" data-reload aria-label="Eliminar" <?= $c['variantes'] ? 'disabled title="En uso"' : '' ?>><i class="bi bi-trash"></i></button>
                </form>
              </td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="panel-body border-top">
        <form class="d-flex gap-2" data-api-form data-method="POST" data-action="/api/admin/colors" data-reload data-success="Color creado">
          <input class="form-control form-control-color" type="color" name="hex" value="#111111" aria-label="Color">
          <input class="form-control form-control-sm" name="nombre" required maxlength="40" placeholder="Nombre del color">
          <button class="btn btn-dark btn-sm text-nowrap" type="submit"><i class="bi bi-plus-lg"></i> Agregar</button>
        </form>
      </div>
    </div>
  </div>
</div>
