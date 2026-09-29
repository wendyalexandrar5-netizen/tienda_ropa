<?php /** @var App\Auth\CurrentUser $currentUser */ $s = $section ?? ''; ?>
<div class="card-fc">
  <div class="card-body">
    <div class="d-flex align-items-center gap-3 mb-4">
      <div class="avatar"><?= e(mb_strtoupper(mb_substr($currentUser->displayName(), 0, 1))) ?></div>
      <div class="min-w-0">
        <div class="fw-bold text-truncate"><?= e($currentUser->displayName()) ?></div>
        <div class="small text-muted-fc text-truncate"><?= e($currentUser->email) ?></div>
      </div>
    </div>
    <ul class="account-nav">
      <li><a class="<?= $s === 'perfil' ? 'active' : '' ?>" href="/cuenta"><i class="bi bi-person"></i> Mi perfil</a></li>
      <li><a class="<?= $s === 'pedidos' ? 'active' : '' ?>" href="/cuenta/pedidos"><i class="bi bi-bag-check"></i> Mis pedidos</a></li>
      <li><a class="<?= $s === 'direcciones' ? 'active' : '' ?>" href="/cuenta/direcciones"><i class="bi bi-geo-alt"></i> Direcciones</a></li>
      <li><a class="<?= $s === 'favoritos' ? 'active' : '' ?>" href="/cuenta/favoritos"><i class="bi bi-heart"></i> Favoritos</a></li>
      <?php if ($currentUser->isAdmin()): ?>
        <li><a href="/admin"><i class="bi bi-speedometer2"></i> Panel administrativo</a></li>
      <?php endif; ?>
      <li>
        <form action="/logout" method="post"><?= csrf_field() ?><button type="submit" class="text-danger"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button></form>
      </li>
    </ul>
  </div>
</div>
