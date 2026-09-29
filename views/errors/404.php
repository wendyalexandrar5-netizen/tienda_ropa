<section class="section">
  <div class="container">
    <div class="empty-state">
      <div class="icon"><i class="bi bi-signpost-split"></i></div>
      <div class="eyebrow mb-2">Error <?= (int) ($status ?? 404) ?></div>
      <h1 class="h2">Página no encontrada</h1>
      <p><?= e($message ?? '') ?></p>
      <div class="d-flex gap-2 justify-content-center">
        <a class="btn btn-dark" href="/catalogo">Ir a la tienda</a>
        <?php if (($status ?? 404) === 403 && empty($currentUser)): ?><a class="btn btn-outline-dark" href="/login">Iniciar sesión</a><?php endif; ?>
      </div>
    </div>
  </div>
</section>
