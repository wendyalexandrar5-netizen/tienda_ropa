<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.'); /** Menú lateral de la cuenta del cliente. Requiere $seccion y $usuario. */ ?>
<div class="caja mb-3 d-flex align-items-center gap-3 no-imprimir">
    <span class="avatar" style="width:48px;height:48px;font-size:1rem"><?= e(iniciales($usuario['nombre'], $usuario['apellido'])) ?></span>
    <div class="overflow-hidden">
        <div class="fw-bold text-truncate"><?= e($usuario['nombre'] . ' ' . $usuario['apellido']) ?></div>
        <div class="small text-muted-fc text-truncate"><?= e($usuario['email']) ?></div>
    </div>
</div>
<div class="list-group menu-cuenta no-imprimir">
    <a class="list-group-item list-group-item-action<?= $seccion === 'pedidos' ? ' active' : '' ?>" href="<?= url('mis_pedidos.php') ?>"><i class="bi bi-bag me-2"></i>Mis pedidos</a>
    <a class="list-group-item list-group-item-action<?= $seccion === 'perfil' ? ' active' : '' ?>" href="<?= url('perfil.php') ?>"><i class="bi bi-person me-2"></i>Mi perfil</a>
    <a class="list-group-item list-group-item-action" href="<?= url('cambiar_password.php') ?>"><i class="bi bi-key me-2"></i>Cambiar contraseña</a>
    <a class="list-group-item list-group-item-action" href="<?= url('carrito.php') ?>"><i class="bi bi-cart me-2"></i>Mi carrito</a>
    <?php if ($usuario['rol'] === ROL_ADMIN): ?>
        <a class="list-group-item list-group-item-action" href="<?= url('admin/index.php') ?>"><i class="bi bi-speedometer2 me-2"></i>Panel administrativo</a>
    <?php endif; ?>
    <form method="post" action="<?= url('logout.php') ?>">
        <?= csrf_campo() ?>
        <button class="list-group-item list-group-item-action text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
    </form>
</div>
