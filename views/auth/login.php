<?php /** @var App\Core\View $view */ ?>
<div class="auth-page">
  <?= $view->partial('auth-visual', get_defined_vars()) ?>
  <div class="auth-form">
    <div class="auth-card">
      <div class="eyebrow mb-2">Mi cuenta</div>
      <h1 class="mb-2">Iniciar sesión</h1>
      <p class="text-muted-fc mb-4">Accede para comprar, ver tus pedidos y guardar favoritos.</p>

      <form method="post" action="/login" novalidate data-validate>
        <?= csrf_field() ?>
        <?php if (!empty($next)): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="email">Correo electrónico</label>
          <input class="form-control <?= field_error('email') ? 'is-invalid' : '' ?>" type="email" id="email" name="email" required maxlength="254" autocomplete="email" value="<?= e(old('email')) ?>" autofocus>
          <div class="invalid-feedback"><?= e(field_error('email') ?? 'Ingresa un correo válido.') ?></div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="password">Contraseña</label>
          <div class="position-relative">
            <input class="form-control pe-5" type="password" id="password" name="password" required maxlength="72" autocomplete="current-password">
            <button class="password-toggle" type="button" data-toggle-password="#password" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
            <div class="invalid-feedback">Ingresa tu contraseña.</div>
          </div>
        </div>
        <div class="text-end mb-4"><a class="small fw-600 text-decoration-underline" href="/recuperar">¿Olvidaste tu contraseña?</a></div>
        <button class="btn btn-dark btn-lg w-100" type="submit">Ingresar</button>
      </form>

      <div class="divider-text">¿Eres nuevo?</div>
      <a class="btn btn-outline-dark w-100" href="/registro">Crear una cuenta</a>
      <p class="small text-muted-fc text-center mt-4 mb-0"><i class="bi bi-shield-lock"></i> Autenticación segura con Supabase Auth.</p>
    </div>
  </div>
</div>
