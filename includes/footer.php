<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.'); $nombreTienda = ajuste('nombre_tienda', config('app_name')); ?>
</main>

<footer class="pie pt-5 pb-4">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="marca mb-3" href="<?= url('index.php') ?>">
                    <img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="" width="40" height="40">
                    <span><?= e($nombreTienda) ?></span>
                </a>
                <p class="small mb-0"><?= e(ajuste('eslogan', 'Ropa urbana con actitud.')) ?></p>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="mb-3">Tienda</h6>
                <ul class="list-unstyled small d-grid gap-2">
                    <li><a href="<?= url('productos.php') ?>">Todos los productos</a></li>
                    <li><a href="<?= url('categorias.php') ?>">Categorías</a></li>
                    <li><a href="<?= url('productos.php', ['orden' => 'recientes']) ?>">Novedades</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="mb-3">Mi cuenta</h6>
                <ul class="list-unstyled small d-grid gap-2">
                    <li><a href="<?= url('perfil.php') ?>">Mi perfil</a></li>
                    <li><a href="<?= url('mis_pedidos.php') ?>">Mis pedidos</a></li>
                    <li><a href="<?= url('carrito.php') ?>">Carrito</a></li>
                </ul>
            </div>
            <div class="col-lg-4">
                <h6 class="mb-3">Contacto</h6>
                <ul class="list-unstyled small d-grid gap-2">
                    <li><i class="bi bi-envelope me-2"></i><?= e(ajuste('email_contacto')) ?></li>
                    <li><i class="bi bi-whatsapp me-2"></i><?= e(ajuste('telefono_contacto')) ?></li>
                    <li><i class="bi bi-cash-coin me-2"></i>Pago contra entrega</li>
                </ul>
            </div>
        </div>
        <hr class="border-secondary my-4">
        <div class="d-flex flex-column flex-md-row justify-content-between small gap-2">
            <span>© <?= date('Y') ?> <?= e($nombreTienda) ?>. Proyecto académico · Ingeniería de Sistemas.</span>
            <span>Hecho con PHP, MySQL y Bootstrap</span>
        </div>
    </div>
</footer>

<script src="<?= asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
