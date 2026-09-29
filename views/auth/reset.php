<?php /** @var App\Core\View $view */ ?>
<div class="auth-page">
  <?= $view->partial('auth-visual', get_defined_vars()) ?>
  <div class="auth-form">
    <div class="auth-card">
      <h1 class="mb-2">Nueva contraseña</h1>
      <?php if ($ready): ?>
        <p class="text-muted-fc mb-4">Elige una contraseña segura que no uses en otros sitios.</p>
        <form method="post" action="/restablecer" novalidate data-validate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="password">Nueva contraseña</label>
            <input class="form-control" type="password" id="password" name="password" required minlength="8" maxlength="72" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" autocomplete="new-password" data-strength="#pwStrength">
            <div class="invalid-feedback">Mínimo 8 caracteres, con letras y números.</div>
            <div class="pw-strength" id="pwStrength"><div></div></div>
          </div>
          <div class="mb-4">
            <label class="form-label" for="password_confirmacion">Confirmar contraseña</label>
            <input class="form-control" type="password" id="password_confirmacion" name="password_confirmacion" required maxlength="72" autocomplete="new-password" data-match="#password">
            <div class="invalid-feedback">Las contraseñas no coinciden.</div>
          </div>
          <button class="btn btn-dark btn-lg w-100" type="submit">Guardar contraseña</button>
        </form>
      <?php else: ?>
        <div data-recovery-handler>
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger">El enlace no es válido o expiró. Solicita uno nuevo.</div>
          <?php endif; ?>
          <div class="d-flex align-items-center gap-2 text-muted-fc" data-recovery-loading>
            <div class="spinner-border spinner-border-sm" role="status"></div> Verificando enlace…
          </div>
          <div class="d-none" data-recovery-invalid>
            <p class="text-muted-fc">Abre el enlace que te enviamos por correo o solicita uno nuevo.</p>
            <a class="btn btn-dark" href="/recuperar">Solicitar enlace</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
