<?php /** @var array $settings */ ?>
<div class="row g-4">
  <div class="col-xl-8">
    <div class="panel">
      <div class="panel-head"><h2>Contenido y parámetros de la tienda</h2></div>
      <div class="panel-body">
        <form data-api-form data-method="PUT" data-action="/api/admin/settings" data-reload data-success="Configuración guardada">
          <div class="row g-3">
            <?php foreach ($settings as $s): if (!isset(App\Services\Admin\SettingsService::SCHEMA[$s['clave']])) continue; $val = is_scalar($s['valor']) ? (string) $s['valor'] : ''; ?>
              <div class="col-md-6">
                <label class="form-label" for="cfg-<?= e($s['clave']) ?>"><?= e($s['etiqueta']) ?> <?= $s['publica'] ? '' : '<i class="bi bi-lock small text-muted-fc" title="Privado"></i>' ?></label>
                <input class="form-control" id="cfg-<?= e($s['clave']) ?>" name="<?= e($s['clave']) ?>" value="<?= e($s['tipo'] === 'numero' ? (string) (0 + $val) : $val) ?>" <?= $s['tipo'] === 'numero' ? 'type="number" min="0" step="1"' : 'maxlength="140"' ?>>
                <div class="form-text"><?= e($s['descripcion']) ?></div>
                <div class="invalid-feedback" data-error-for="<?= e($s['clave']) ?>"></div>
              </div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-dark mt-4" type="submit">Guardar configuración</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="panel">
      <div class="panel-head"><h2>Integraciones</h2></div>
      <div class="panel-body small">
        <p><i class="bi bi-database me-1"></i> <strong>Base de datos:</strong> PostgreSQL (Supabase) con RLS en todas las tablas.</p>
        <p><i class="bi bi-shield-lock me-1"></i> <strong>Autenticación:</strong> Supabase Auth (JWT verificado en el backend).</p>
        <p><i class="bi bi-images me-1"></i> <strong>Imágenes:</strong> <?= e($config->storageDriver === 'local' ? 'Almacenamiento local (desarrollo)' : 'Supabase Storage · bucket "' . $config->storageBucket . '"') ?>.</p>
        <p class="mb-0"><i class="bi bi-phone me-1"></i> <strong>API REST:</strong> <code>/api/v1</code> lista para la app móvil.</p>
      </div>
    </div>
  </div>
</div>
