<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/** Paginación. Requiere $pag (resultado de paginar()). */
if ($pag['paginas'] > 1): ?>
<nav aria-label="Paginación" class="mt-4">
    <ul class="pagination justify-content-center flex-wrap">
        <li class="page-item <?= $pag['pagina'] <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e(url_actual(['pagina' => $pag['pagina'] - 1])) ?>" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a>
        </li>
        <?php for ($i = 1; $i <= $pag['paginas']; $i++): ?>
            <?php if ($i === 1 || $i === $pag['paginas'] || abs($i - $pag['pagina']) <= 2): ?>
                <li class="page-item <?= $i === $pag['pagina'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(url_actual(['pagina' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php elseif (abs($i - $pag['pagina']) === 3): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>
        <?php endfor; ?>
        <li class="page-item <?= $pag['pagina'] >= $pag['paginas'] ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e(url_actual(['pagina' => $pag['pagina'] + 1])) ?>" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></a>
        </li>
    </ul>
</nav>
<?php endif; ?>
