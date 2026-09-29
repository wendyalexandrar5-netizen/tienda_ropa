<?php
/** @var ?array $product  @var array $categories */
$p = $product ?? [];
$isEdit = $product !== null;
$val = static fn (string $k, $def = '') => old($k, (string) ($p[$k] ?? $def));
?>
<div class="page-actions">
  <a class="small fw-600 d-inline-flex align-items-center gap-1" href="/admin/productos"><i class="bi bi-arrow-left"></i> Productos</a>
  <?php if ($isEdit): ?>
    <div class="d-flex gap-2">
      <a class="btn btn-light-fc btn-sm" href="/producto/<?= e($p['slug']) ?>" target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i>Ver en tienda</a>
      <button class="btn btn-outline-danger btn-sm rounded-pill" type="button" data-api="DELETE /api/admin/products/<?= (int) $p['id'] ?>" data-confirm="¿Eliminar definitivamente este producto? Los pedidos antiguos conservan su información." data-redirect="/admin/productos" data-success="Producto eliminado"><i class="bi bi-trash me-1"></i>Eliminar</button>
    </div>
  <?php endif; ?>
</div>

<div class="row g-4">
  <div class="col-xl-7">
    <div class="panel mb-4">
      <div class="panel-head"><h2>Información del producto</h2><?php if ($isEdit): ?><span class="small text-muted-fc"><?= (int) $p['unidades_vendidas'] ?> unidades vendidas</span><?php endif; ?></div>
      <div class="panel-body">
        <form method="post" action="<?= $isEdit ? '/admin/productos/' . (int) $p['id'] : '/admin/productos' ?>" novalidate data-validate>
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label" for="nombre">Nombre *</label>
              <input class="form-control <?= field_error('nombre') ? 'is-invalid' : '' ?>" id="nombre" name="nombre" required minlength="2" maxlength="120" value="<?= e($val('nombre')) ?>">
              <div class="invalid-feedback"><?= e(field_error('nombre') ?? 'Nombre obligatorio (2–120 caracteres).') ?></div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="categoria_id">Categoría *</label>
              <select class="form-select <?= field_error('categoria_id') ? 'is-invalid' : '' ?>" id="categoria_id" name="categoria_id" required>
                <option value="">Selecciona…</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $val('categoria_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Selecciona una categoría.</div>
            </div>
            <div class="col-12">
              <label class="form-label" for="descripcion">Descripción</label>
              <textarea class="form-control" id="descripcion" name="descripcion" rows="4" maxlength="4000"><?= e($val('descripcion')) ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="precio">Precio (COP) *</label>
              <input class="form-control <?= field_error('precio') ? 'is-invalid' : '' ?>" type="number" id="precio" name="precio" required min="1" step="1" value="<?= e($val('precio') !== '' ? (string) (int) (float) $val('precio') : '') ?>">
              <div class="invalid-feedback"><?= e(field_error('precio') ?? 'Precio mayor a 0.') ?></div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="precio_anterior">Precio anterior <span class="text-muted-fc fw-normal">(oferta)</span></label>
              <input class="form-control <?= field_error('precio_anterior') ? 'is-invalid' : '' ?>" type="number" id="precio_anterior" name="precio_anterior" min="1" step="1" value="<?= e($val('precio_anterior') !== '' ? (string) (int) (float) $val('precio_anterior') : '') ?>">
              <div class="invalid-feedback"><?= e(field_error('precio_anterior') ?? 'Debe ser mayor al precio.') ?></div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="estado">Estado</label>
              <select class="form-select" id="estado" name="estado">
                <?php foreach (['borrador' => 'Borrador (oculto)', 'activo' => 'Activo (visible)', 'inactivo' => 'Inactivo'] as $k => $l): ?>
                  <option value="<?= $k ?>" <?= $val('estado', 'borrador') === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label" for="slug">URL amigable (slug)</label>
              <div class="input-group"><span class="input-group-text">/producto/</span><input class="form-control <?= field_error('slug') ? 'is-invalid' : '' ?>" id="slug" name="slug" maxlength="140" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e($val('slug')) ?>" placeholder="se genera automáticamente"></div>
              <div class="form-text">Solo minúsculas, números y guiones.</div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="destacado" name="destacado" value="1" <?= !empty($p['destacado']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="destacado">Destacado en inicio</label>
              </div>
            </div>
          </div>
          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-dark" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear producto' ?></button>
            <a class="btn btn-light-fc" href="/admin/productos">Cancelar</a>
          </div>
          <p class="small text-muted-fc mt-3 mb-0"><i class="bi bi-info-circle"></i> Cambiar el precio no afecta pedidos anteriores: cada pedido guarda su precio histórico.</p>
        </form>
      </div>
    </div>

    <?php if ($isEdit): ?>
    <div class="panel">
      <div class="panel-head"><h2>Variantes e inventario</h2><span class="small text-muted-fc"><?= count($p['variantes']) ?> variantes</span></div>
      <div class="variant-list">
        <?php foreach ($p['variantes'] as $v): ?>
          <form class="variant-row" data-api-form data-method="PUT" data-action="/api/admin/variants/<?= (int) $v['id'] ?>" data-reload data-success="Variante actualizada">
            <div class="variant-id">
              <span class="swatch-dot" style="background: <?= e($v['hex']) ?>"></span>
              <div><strong><?= e($v['talla']) ?></strong> · <?= e($v['color']) ?><div class="small text-muted-fc">Stock <span class="stock-pill <?= $v['stock'] == 0 ? 'out' : ($v['stock'] <= $v['stock_minimo'] ? 'low' : '') ?>" title="Se modifica desde Inventario"><?= (int) $v['stock'] ?></span></div></div>
            </div>
            <label class="variant-field"><span>SKU</span><input class="form-control form-control-sm" name="sku" value="<?= e($v['sku']) ?>" required maxlength="40"></label>
            <label class="variant-field"><span>Precio propio</span><input class="form-control form-control-sm" type="number" name="precio" min="1" step="1" value="<?= $v['precio'] !== null ? (int) $v['precio'] : '' ?>" placeholder="<?= (int) $p['precio'] ?>"></label>
            <label class="variant-field variant-field--sm"><span>Stock mín.</span><input class="form-control form-control-sm" type="number" name="stock_minimo" min="0" value="<?= (int) $v['stock_minimo'] ?>"></label>
            <label class="variant-field variant-field--sm"><span>Activa</span><span class="form-check form-switch m-0 pt-1"><input class="form-check-input" type="checkbox" name="activa" value="1" <?= $v['activa'] ? 'checked' : '' ?>></span></label>
            <div class="d-flex gap-1 ms-auto">
              <button class="btn btn-light-fc btn-sm" type="submit" aria-label="Guardar variante"><i class="bi bi-check-lg"></i></button>
              <button class="btn btn-light-fc btn-sm" type="button" data-api="DELETE /api/admin/variants/<?= (int) $v['id'] ?>" data-confirm="¿Eliminar la variante <?= e($v['talla'] . ' / ' . $v['color']) ?>?" data-reload aria-label="Eliminar variante"><i class="bi bi-trash"></i></button>
            </div>
          </form>
        <?php endforeach; ?>
        <?php if (!$p['variantes']): ?><p class="panel-body small text-muted-fc m-0">Este producto aún no tiene variantes. Agrega al menos una para poder venderlo.</p><?php endif; ?>
      </div>
      <div class="panel-body border-top">
        <h3 class="h6 mb-3">Agregar variante</h3>
        <form class="row g-2 align-items-end" data-api-form data-method="POST" data-action="/api/admin/products/<?= (int) $p['id'] ?>/variants" data-reload data-success="Variante creada">
          <div class="col-6 col-md-2"><label class="form-label small">Talla</label><select class="form-select form-select-sm" name="talla_id" required><?php foreach ($sizes as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['codigo']) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-3"><label class="form-label small">Color</label><select class="form-select form-select-sm" name="color_id" required><?php foreach ($colors as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-2"><label class="form-label small">Stock inicial</label><input class="form-control form-control-sm" type="number" name="stock_inicial" min="0" value="0"></div>
          <div class="col-6 col-md-2"><label class="form-label small">Precio (opc.)</label><input class="form-control form-control-sm" type="number" name="precio" min="1" step="1"></div>
          <div class="col-8 col-md-2"><label class="form-label small">SKU (opc.)</label><input class="form-control form-control-sm" name="sku" maxlength="40" placeholder="auto"></div>
          <div class="col-4 col-md-1"><button class="btn btn-dark btn-sm w-100" type="submit" aria-label="Agregar"><i class="bi bi-plus-lg"></i></button></div>
        </form>
        <p class="small text-muted-fc mt-2 mb-0">Para entradas/salidas posteriores usa <a class="fw-600" href="/admin/inventario?q=<?= e(rawurlencode($p['nombre'])) ?>">Inventario</a> (queda registrado en el kardex).</p>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-5">
    <?php if ($isEdit): ?>
      <div class="panel">
        <div class="panel-head"><h2>Imágenes</h2><span class="small text-muted-fc"><?= count($p['imagenes']) ?>/12</span></div>
        <div class="panel-body">
          <?php if ($p['imagenes']): ?>
            <div class="image-grid mb-3">
              <?php foreach ($p['imagenes'] as $img): ?>
                <div class="image-tile <?= $img['es_principal'] ? 'is-main' : '' ?>">
                  <img src="<?= safe_url($img['url']) ?>" alt="<?= e($img['alt']) ?>">
                  <?php if ($img['es_principal']): ?><span class="p-badge p-badge--dark main-flag">Principal</span><?php endif; ?>
                  <div class="tile-actions">
                    <?php if (!$img['es_principal']): ?><button type="button" data-api="PUT /api/admin/products/<?= (int) $p['id'] ?>/images/<?= (int) $img['id'] ?>" data-reload title="Marcar como principal" aria-label="Marcar como principal"><i class="bi bi-star"></i></button><?php endif; ?>
                    <button type="button" data-api="DELETE /api/admin/products/<?= (int) $p['id'] ?>/images/<?= (int) $img['id'] ?>" data-confirm="¿Eliminar esta imagen?" data-reload title="Eliminar" aria-label="Eliminar imagen"><i class="bi bi-trash"></i></button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form class="dropzone" enctype="multipart/form-data" data-api-form data-method="POST" data-action="/api/admin/products/<?= (int) $p['id'] ?>/images" data-reload data-success="Imagen subida">
            <i class="bi bi-cloud-arrow-up fs-2 d-block mb-2"></i>
            <input class="form-control form-control-sm mb-2" type="file" name="imagen" accept="image/jpeg,image/png,image/webp" required aria-label="Imagen">
            <input class="form-control form-control-sm mb-2" name="alt" maxlength="200" placeholder="Texto alternativo (accesibilidad)">
            <button class="btn btn-dark btn-sm" type="submit">Subir imagen</button>
            <div class="small text-muted-fc mt-2">JPG, PNG o WEBP · máx. 5 MB · se valida el contenido real del archivo.</div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="panel"><div class="panel-body small text-muted-fc"><i class="bi bi-info-circle me-1"></i> Guarda el producto para poder subir imágenes y crear sus variantes (talla × color con stock propio). Queda en <strong>borrador</strong> hasta que lo actives.</div></div>
    <?php endif; ?>
  </div>
</div>
