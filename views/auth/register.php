<?php /** @var App\Core\View $view */ ?>
<div class="auth-page">
  <?= $view->partial('auth-visual', get_defined_vars()) ?>
  <div class="auth-form">
    <div class="auth-card">
      <div class="eyebrow mb-2">Únete</div>
      <h1 class="mb-2">Crear cuenta</h1>
      <p class="text-muted-fc mb-4">Compra más rápido y sigue el estado de tus pedidos.</p>

      <form method="post" action="/registro" novalidate data-validate>
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="nombre">Nombre</label>
            <input class="form-control <?= field_error('nombre') ? 'is-invalid' : '' ?>" id="nombre" name="nombre" required minlength="2" maxlength="80" autocomplete="given-name" value="<?= e(old('nombre')) ?>">
            <div class="invalid-feedback"><?= e(field_error('nombre') ?? 'Ingresa tu nombre.') ?></div>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="apellido">Apellido</label>
            <input class="form-control <?= field_error('apellido') ? 'is-invalid' : '' ?>" id="apellido" name="apellido" required minlength="2" maxlength="80" autocomplete="family-name" value="<?= e(old('apellido')) ?>">
            <div class="invalid-feedback"><?= e(field_error('apellido') ?? 'Ingresa tu apellido.') ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="email">Correo electrónico</label>
            <input class="form-control <?= field_error('email') ? 'is-invalid' : '' ?>" type="email" id="email" name="email" required maxlength="254" autocomplete="email" value="<?= e(old('email')) ?>">
            <div class="invalid-feedback"><?= e(field_error('email') ?? 'Ingresa un correo válido.') ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="telefono">Celular <span class="text-muted-fc fw-normal">(opcional)</span></label>
            <input class="form-control <?= field_error('telefono') ? 'is-invalid' : '' ?>" id="telefono" name="telefono" maxlength="20" pattern="[0-9+() \-]{7,20}" inputmode="tel" autocomplete="tel" value="<?= e(old('telefono')) ?>">
            <div class="invalid-feedback"><?= e(field_error('telefono') ?? 'Número inválido.') ?></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="password">Contraseña</label>
            <div class="position-relative">
              <input class="form-control pe-5 <?= field_error('password') ? 'is-invalid' : '' ?>" type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" data-strength="#pwStrength">
              <button class="password-toggle" type="button" data-toggle-password="#password" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
              <div class="invalid-feedback"><?= e(field_error('password') ?? 'Mínimo 8 caracteres, con letras y números.') ?></div>
            </div>
            <div class="pw-strength" id="pwStrength"><div></div></div>
            <div class="form-text">Mínimo 8 caracteres, con letras y números.</div>
          </div>
          <div class="col-12">
            <label class="form-label" for="password_confirmacion">Confirmar contraseña</label>
            <input class="form-control <?= field_error('password_confirmacion') ? 'is-invalid' : '' ?>" type="password" id="password_confirmacion" name="password_confirmacion" required maxlength="72" autocomplete="new-password" data-match="#password">
            <div class="invalid-feedback"><?= e(field_error('password_confirmacion') ?? 'Las contraseñas no coinciden.') ?></div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input <?= field_error('acepta_terminos') ? 'is-invalid' : '' ?>" type="checkbox" value="1" id="acepta_terminos" name="acepta_terminos" required>
              <label class="form-check-label small" for="acepta_terminos">Acepto los términos y el tratamiento de mis datos personales.</label>
              <div class="invalid-feedback">Debes aceptar para continuar.</div>
            </div>
          </div>
        </div>
        <button class="btn btn-dark btn-lg w-100 mt-4" type="submit">Crear cuenta</button>
      </form>
      <p class="text-center small mt-4 mb-0">¿Ya tienes cuenta? <a class="fw-600 text-decoration-underline" href="/login">Inicia sesión</a></p>
    </div>
  </div>
</div>
