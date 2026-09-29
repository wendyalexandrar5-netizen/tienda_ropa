<div class="empty-state">
  <div class="icon"><i class="bi <?= e($icon ?? 'bi-inbox') ?>"></i></div>
  <h3><?= e($heading ?? 'Nada por aquí') ?></h3>
  <p><?= e($text ?? '') ?></p>
  <?php if (!empty($ctaUrl)): ?>
    <a class="btn btn-dark" href="<?= e($ctaUrl) ?>"><?= e($ctaText ?? 'Ir a la tienda') ?></a>
  <?php endif; ?>
</div>
