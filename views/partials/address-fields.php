<?php $a = $a ?? []; $uid = substr(md5((string) mt_rand()), 0, 6); ?>
<div class="row g-3">
  <div class="col-sm-4">
    <label class="form-label" for="alias-<?= $uid ?>">Alias</label>
    <input class="form-control" id="alias-<?= $uid ?>" name="alias" maxlength="40" value="<?= e($a['alias'] ?? 'Casa') ?>" placeholder="Casa, Oficina…">
    <div class="invalid-feedback" data-error-for="alias"></div>
  </div>
  <div class="col-sm-8">
    <label class="form-label" for="dest-<?= $uid ?>">Nombre de quien recibe *</label>
    <input class="form-control" id="dest-<?= $uid ?>" name="destinatario" required minlength="2" maxlength="120" value="<?= e($a['destinatario'] ?? '') ?>" autocomplete="name">
    <div class="invalid-feedback" data-error-for="destinatario"></div>
  </div>
  <div class="col-sm-6">
    <label class="form-label" for="tel-<?= $uid ?>">Teléfono *</label>
    <input class="form-control" id="tel-<?= $uid ?>" name="telefono" required pattern="[0-9+() \-]{7,20}" maxlength="20" value="<?= e($a['telefono'] ?? '') ?>" autocomplete="tel" inputmode="tel">
    <div class="invalid-feedback" data-error-for="telefono"></div>
  </div>
  <div class="col-sm-6">
    <label class="form-label" for="cp-<?= $uid ?>">Código postal</label>
    <input class="form-control" id="cp-<?= $uid ?>" name="codigo_postal" maxlength="12" value="<?= e($a['codigo_postal'] ?? '') ?>" autocomplete="postal-code">
    <div class="invalid-feedback" data-error-for="codigo_postal"></div>
  </div>
  <div class="col-12">
    <label class="form-label" for="dir-<?= $uid ?>">Dirección *</label>
    <input class="form-control" id="dir-<?= $uid ?>" name="direccion" required minlength="5" maxlength="200" value="<?= e($a['direccion'] ?? '') ?>" placeholder="Calle 10 # 20-30" autocomplete="address-line1">
    <div class="invalid-feedback" data-error-for="direccion"></div>
  </div>
  <div class="col-12">
    <label class="form-label" for="det-<?= $uid ?>">Apartamento, torre, barrio</label>
    <input class="form-control" id="det-<?= $uid ?>" name="detalle" maxlength="200" value="<?= e($a['detalle'] ?? '') ?>" autocomplete="address-line2">
  </div>
  <div class="col-sm-6">
    <label class="form-label" for="ciu-<?= $uid ?>">Ciudad *</label>
    <input class="form-control" id="ciu-<?= $uid ?>" name="ciudad" required minlength="2" maxlength="80" value="<?= e($a['ciudad'] ?? '') ?>" autocomplete="address-level2">
    <div class="invalid-feedback" data-error-for="ciudad"></div>
  </div>
  <div class="col-sm-6">
    <label class="form-label" for="dep-<?= $uid ?>">Departamento *</label>
    <input class="form-control" id="dep-<?= $uid ?>" name="departamento" required minlength="2" maxlength="80" value="<?= e($a['departamento'] ?? '') ?>" autocomplete="address-level1">
    <div class="invalid-feedback" data-error-for="departamento"></div>
  </div>
  <div class="col-12">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="es_principal" value="1" id="pri-<?= $uid ?>" <?= !empty($a['es_principal']) ? 'checked' : '' ?>>
      <label class="form-check-label" for="pri-<?= $uid ?>">Usar como dirección principal</label>
    </div>
  </div>
</div>
