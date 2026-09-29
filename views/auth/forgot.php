<?php /** @var App\Core\View $view */ ?>
<div class="auth-page">
  <?= $view->partial('auth-visual', get_defined_vars()) ?>
  <div class="auth-form">
    <div class="auth-card">
      <a class="small fw-600 d-inline-flex align-items-center gap-1 mb-4" href="/login"><i class="bi bi-arrow-left"></i> Volver</a>
      <h1 class="mb-2">¿Olvidaste tu contraseña?</h1>
      <p class="text-muted-fc mb-4">Escribe tu correo y te enviaremos un enlace seguro para crear una nueva.</p>
      <form method="post" action="/recuperar" novalidate data-validate>
        <?= csrf_field() ?>
        <div class="mb-4">
          <label class="form-label" for="email">Correo electrónico</label>
          <input class="form-control" type="email" id="email" name="email" required maxlength="254" autocomplete="email" value="<?= e(old('email')) ?>" autofocus>
          <div class="invalid-feedback">Ingresa un correo válido.</div>
        </div>
        <button class="btn btn-dark btn-lg w-100" type="submit">Enviar enlace</button>
      </form>
    </div>
  </div>
</div>
