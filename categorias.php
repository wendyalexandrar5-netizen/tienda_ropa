<?php
require __DIR__ . '/includes/bootstrap.php';

$categorias = categorias_activas();

$titulo = 'Categorías';
$pagina = 'categorias';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('index.php') ?>">Inicio</a></li>
            <li class="breadcrumb-item active" aria-current="page">Categorías</li>
        </ol>
    </nav>
    <div class="mb-4">
        <h1 class="mb-1">Categorías</h1>
        <p class="text-muted-fc mb-0">Encuentra tu estilo por tipo de prenda.</p>
    </div>

    <?php if ($categorias): ?>
        <div class="row g-4">
            <?php foreach ($categorias as $c): ?>
                <div class="col-6 col-md-4">
                    <a class="tarjeta-categoria" href="<?= url('productos.php', ['categoria' => $c['id']]) ?>">
                        <img src="<?= imagen_url($c['imagen']) ?>" alt="" loading="lazy">
                        <div class="info">
                            <h3><?= e($c['nombre']) ?></h3>
                            <p class="small mb-1 d-none d-sm-block opacity-75"><?= e($c['descripcion']) ?></p>
                            <small class="fw-semibold"><?= (int) $c['total_productos'] ?> productos <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="estado-vacio"><div class="icono"><i class="bi bi-grid"></i></div><p class="mb-0">Aún no hay categorías disponibles.</p></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
