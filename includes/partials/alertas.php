<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
$iconosAlerta = ['success' => 'bi-check-circle-fill', 'danger' => 'bi-exclamation-octagon-fill',
                 'warning' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];
foreach (flashes() as $f):
    $tipo = isset($iconosAlerta[$f['tipo']]) ? $f['tipo'] : 'info'; ?>
    <div class="alert alert-<?= $tipo ?> alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
        <i class="bi <?= $iconosAlerta[$tipo] ?> mt-1"></i>
        <div><?= e($f['mensaje']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
<?php endforeach; ?>
