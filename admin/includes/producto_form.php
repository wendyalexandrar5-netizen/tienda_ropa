<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/** Formulario de producto (crear/editar). Requiere $d, $errores, $categorias y opcional $producto. */
?>
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label" for="nombre">Nombre *</label>
        <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required minlength="3" maxlength="120" value="<?= e($d['nombre']) ?>">
        <?= error_campo($errores, 'nombre') ?>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="categoria_id">Categoría *</label>
        <select class="form-select<?= clase_invalida($errores, 'categoria_id') ?>" id="categoria_id" name="categoria_id" required>
            <option value="">Selecciona…</option>
            <?php foreach ($categorias as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $d['categoria_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['estado'] !== 'activa' ? ' (inactiva)' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <?= error_campo($errores, 'categoria_id') ?>
    </div>
    <div class="col-12">
        <label class="form-label" for="descripcion">Descripción</label>
        <textarea class="form-control<?= clase_invalida($errores, 'descripcion') ?>" id="descripcion" name="descripcion" rows="4" maxlength="2000"><?= e($d['descripcion']) ?></textarea>
        <?= error_campo($errores, 'descripcion') ?>
    </div>
    <div class="col-sm-4">
        <label class="form-label" for="precio">Precio (COP) *</label>
        <div class="input-group">
            <span class="input-group-text">$</span>
            <input class="form-control<?= clase_invalida($errores, 'precio') ?>" type="number" id="precio" name="precio" required min="100" max="100000000" step="100" value="<?= e($d['precio'] !== '' ? (string) (float) $d['precio'] : '') ?>">
        </div>
        <?= error_campo($errores, 'precio') ?>
    </div>
    <div class="col-sm-4">
        <label class="form-label" for="estado">Estado</label>
        <select class="form-select" id="estado" name="estado">
            <option value="activo" <?= $d['estado'] === 'activo' ? 'selected' : '' ?>>Activo (visible en la tienda)</option>
            <option value="inactivo" <?= $d['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo (oculto)</option>
        </select>
    </div>
    <div class="col-sm-4 d-flex align-items-end">
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="destacado" name="destacado" value="1" <?= (int) $d['destacado'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="destacado">Destacado en portada</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label" for="imagen">Imagen <span class="text-muted-fc fw-normal">(JPG, PNG o WEBP · máx. 2 MB)</span></label>
        <div class="d-flex flex-wrap gap-3 align-items-start">
            <img id="preview-imagen" class="vista-previa" src="<?= imagen_url($producto['imagen'] ?? null) ?>" alt="Vista previa">
            <div class="flex-grow-1">
                <input class="form-control<?= clase_invalida($errores, 'imagen') ?>" type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp" data-vista-previa="preview-imagen">
                <?= error_campo($errores, 'imagen') ?>
                <div class="form-text">La imagen se valida en el servidor (tipo real, tamaño y dimensiones), se re-codifica y se guarda con un nombre aleatorio.</div>
            </div>
        </div>
    </div>
</div>
