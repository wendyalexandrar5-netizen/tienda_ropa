<?php
/** @var int $page  @var int $pages */
if (($pages ?? 1) <= 1) {
    return;
}
$window = 2;
$start = max(1, $page - $window);
$end = min($pages, $page + $window);
?>
<nav aria-label="Paginación" class="mt-5">
  <ul class="pagination justify-content-center flex-wrap">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(query_url(['pagina' => $page - 1])) ?>" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a>
    </li>
    <?php if ($start > 1): ?>
      <li class="page-item"><a class="page-link" href="<?= e(query_url(['pagina' => 1])) ?>">1</a></li>
      <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
    <?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>>
        <a class="page-link" href="<?= e(query_url(['pagina' => $i])) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
    <?php if ($end < $pages): ?>
      <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <li class="page-item"><a class="page-link" href="<?= e(query_url(['pagina' => $pages])) ?>"><?= $pages ?></a></li>
    <?php endif; ?>
    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(query_url(['pagina' => $page + 1])) ?>" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></a>
    </li>
  </ul>
</nav>
