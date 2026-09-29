<section class="section">
  <div class="container">
    <div class="empty-state">
      <div class="icon"><i class="bi bi-cone-striped"></i></div>
      <div class="eyebrow mb-2">Error <?= (int) ($status ?? 500) ?></div>
      <h1 class="h2">Algo salió mal</h1>
      <p><?= e($message ?? '') ?></p>
      <div class="d-flex gap-2 justify-content-center">
        <a class="btn btn-dark" href="/">Volver al inicio</a>
        <?php if (($status ?? 500) === 403 && empty($currentUser)): ?><a class="btn btn-outline-dark" href="/login">Iniciar sesión</a><?php endif; ?>
      </div>
    </div>
  </div>
</section>
