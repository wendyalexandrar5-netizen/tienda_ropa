<?php
require __DIR__ . '/includes/bootstrap.php';

$categorias = categorias_activas();
$destacados = productos_destacados(4);
$recientes  = productos_recientes(4);
$collage    = array_slice(array_column(productos_recientes(12), 'imagen'), 0, 6);

$titulo = 'Inicio';
$pagina = 'inicio';
$sin_contenedor = true;
require __DIR__ . '/includes/header.php';
?>
<section class="hero pb-5 pt-4 pt-lg-5 mb-5">
    <div class="container pt-2">
        <?php require __DIR__ . '/includes/partials/alertas.php'; ?>
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="hero-sello mb-3"><i class="bi bi-fire"></i> Nueva colección urbana</span>
                <h1 class="mb-3">Viste con <span class="text-acento">actitud</span>.<br>Enciende tu estilo.</h1>
                <p class="lead mb-4"><?= e(ajuste('eslogan')) ?>. Sudaderas, cargos y conjuntos pensados para la calle.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-acento btn-lg" href="<?= url('productos.php') ?>">Comprar ahora <i class="bi bi-arrow-right ms-1"></i></a>
                    <a class="btn btn-outline-light btn-lg" href="<?= url('categorias.php') ?>">Ver categorías</a>
                </div>
                <div class="d-flex gap-4 mt-4 small text-white-50">
                    <span><i class="bi bi-cash-coin me-1 text-acento"></i>Pago contra entrega</span>
                    <span><i class="bi bi-arrow-repeat me-1 text-acento"></i>Cambios en 30 días</span>
                </div>
            </div>
            <div class="col-lg-6 d-none d-md-block">
                <div class="hero-collage">
                    <?php foreach ($collage as $img): ?>
                        <img src="<?= imagen_url($img) ?>" alt="" loading="lazy">
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container">
    <section class="mb-5">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <span class="eyebrow">Explora</span>
                <h2 class="h1 mb-0">Categorías</h2>
            </div>
            <a class="btn btn-outline-fc btn-sm" href="<?= url('categorias.php') ?>">Ver todas</a>
        </div>
        <div class="row g-3">
            <?php foreach (array_slice($categorias, 0, 6) as $c): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <a class="tarjeta-categoria" href="<?= url('productos.php', ['categoria' => $c['id']]) ?>">
                        <img src="<?= imagen_url($c['imagen']) ?>" alt="" loading="lazy">
                        <div class="info">
                            <h3><?= e($c['nombre']) ?></h3>
                            <small><?= (int) $c['total_productos'] ?> productos</small>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mb-5">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <span class="eyebrow">Lo más buscado</span>
                <h2 class="h1 mb-0">Destacados</h2>
            </div>
            <a class="btn btn-outline-fc btn-sm" href="<?= url('productos.php') ?>">Ver catálogo</a>
        </div>
        <?php if ($destacados): ?>
            <div class="row g-4">
                <?php foreach ($destacados as $p): ?>
                    <div class="col-6 col-md-4 col-lg-3"><?php require __DIR__ . '/includes/partials/producto_card.php'; ?></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="estado-vacio"><div class="icono"><i class="bi bi-stars"></i></div><p class="mb-0">Pronto tendremos productos destacados.</p></div>
        <?php endif; ?>
    </section>

    <section class="valores py-4 mb-5">
        <div class="row g-4 text-center text-md-start">
            <div class="col-6 col-md-3 d-md-flex gap-3"><i class="bi bi-truck"></i><div><strong class="d-block">Envíos nacionales</strong><small class="text-muted-fc">Gratis desde <?= precio(ajuste('envio_gratis_desde', '0')) ?></small></div></div>
            <div class="col-6 col-md-3 d-md-flex gap-3"><i class="bi bi-cash-stack"></i><div><strong class="d-block">Contra entrega</strong><small class="text-muted-fc">Paga al recibir</small></div></div>
            <div class="col-6 col-md-3 d-md-flex gap-3"><i class="bi bi-shield-check"></i><div><strong class="d-block">Compra segura</strong><small class="text-muted-fc">Tus datos protegidos</small></div></div>
            <div class="col-6 col-md-3 d-md-flex gap-3"><i class="bi bi-arrow-left-right"></i><div><strong class="d-block">Cambios fáciles</strong><small class="text-muted-fc">Hasta 30 días</small></div></div>
        </div>
    </section>

    <section class="mb-5">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <span class="eyebrow">Recién llegados</span>
                <h2 class="h1 mb-0">Novedades</h2>
            </div>
        </div>
        <div class="row g-4">
            <?php foreach ($recientes as $p): ?>
                <div class="col-6 col-md-4 col-lg-3"><?php require __DIR__ . '/includes/partials/producto_card.php'; ?></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="bg-negro rounded-4 p-4 p-lg-5 d-lg-flex align-items-center justify-content-between gap-4">
        <div class="d-flex align-items-center gap-3 mb-3 mb-lg-0">
            <img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="" width="72" height="72" class="rounded-3">
            <div>
                <h2 class="h3 mb-1">Únete a la familia <?= e(ajuste('nombre_tienda')) ?></h2>
                <p class="mb-0 text-white-50">Crea tu cuenta, guarda tu carrito y sigue tus pedidos en tiempo real.</p>
            </div>
        </div>
        <?php if (!esta_autenticado()): ?>
            <a class="btn btn-acento btn-lg" href="<?= url('registro.php') ?>">Crear cuenta gratis</a>
        <?php else: ?>
            <a class="btn btn-acento btn-lg" href="<?= url('mis_pedidos.php') ?>">Ver mis pedidos</a>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
