<?php
/** @var ?App\Auth\CurrentUser $currentUser */
$storeName = $storeConfig['nombre_tienda'] ?? 'FIRE CAT';
$q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
?>
<header class="site-header" data-header>
  <nav class="navbar navbar-expand-lg" aria-label="Navegación principal">
    <div class="container">
      <button class="btn btn-icon d-lg-none me-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuMovil" aria-controls="menuMovil" aria-label="Abrir menú">
        <i class="bi bi-list fs-4"></i>
      </button>

      <a class="brand me-lg-4" href="/" aria-label="<?= e($storeName) ?> · inicio">
        <img src="/assets/img/brand/firecat-mark.png" alt="" width="38" height="38">
        <span><?= e($storeName) ?></span>
      </a>

      <ul class="navbar-nav nav-main d-none d-lg-flex me-auto">
        <li class="nav-item"><a class="nav-link <?= is_active_path('/catalogo', true) && empty($_GET['orden']) ? 'active' : '' ?>" href="/catalogo">Tienda</a></li>
        <li class="nav-item"><a class="nav-link" href="/catalogo?orden=recientes">Nuevo</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= is_active_path('/categoria') ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Categorías</a>
          <ul class="dropdown-menu">
            <?php foreach ($navCategories ?? [] as $cat): ?>
              <li><a class="dropdown-item d-flex justify-content-between gap-4" href="/categoria/<?= e($cat['slug']) ?>"><?= e($cat['nombre']) ?> <small class="text-muted-fc"><?= (int) $cat['productos'] ?></small></a></li>
            <?php endforeach; ?>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="/catalogo?ofertas=1">Ofertas</a></li>
      </ul>

      <form class="search-inline d-none d-xl-block me-2" action="/catalogo" method="get" role="search">
        <div class="input-icon">
          <i class="bi bi-search"></i>
          <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar prendas…" aria-label="Buscar productos" maxlength="100">
        </div>
      </form>

      <div class="nav-actions ms-auto ms-lg-0">
        <a class="btn btn-icon d-xl-none" href="/catalogo" aria-label="Buscar"><i class="bi bi-search"></i></a>
        <?php if ($currentUser): ?>
          <a class="btn btn-icon d-none d-sm-inline-flex" href="/cuenta/favoritos" aria-label="Favoritos"><i class="bi bi-heart"></i></a>
          <div class="dropdown">
            <button class="btn btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mi cuenta">
              <i class="bi bi-person"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li class="px-3 py-2">
                <div class="fw-600"><?= e($currentUser->displayName()) ?></div>
                <small class="text-muted-fc"><?= e($currentUser->email) ?></small>
              </li>
              <li><hr class="dropdown-divider"></li>
              <?php if ($currentUser->isAdmin()): ?>
                <li><a class="dropdown-item" href="/admin"><i class="bi bi-speedometer2 me-2"></i>Panel administrativo</a></li>
              <?php endif; ?>
              <li><a class="dropdown-item" href="/cuenta"><i class="bi bi-person-circle me-2"></i>Mi perfil</a></li>
              <li><a class="dropdown-item" href="/cuenta/pedidos"><i class="bi bi-bag-check me-2"></i>Mis pedidos</a></li>
              <li><a class="dropdown-item" href="/cuenta/direcciones"><i class="bi bi-geo-alt me-2"></i>Direcciones</a></li>
              <li><a class="dropdown-item" href="/cuenta/favoritos"><i class="bi bi-heart me-2"></i>Favoritos</a></li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form action="/logout" method="post"><?= csrf_field() ?>
                  <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
                </form>
              </li>
            </ul>
          </div>
        <?php else: ?>
          <a class="btn btn-icon" href="/login" aria-label="Iniciar sesión"><i class="bi bi-person"></i></a>
        <?php endif; ?>
        <a class="btn btn-icon" href="/carrito" aria-label="Carrito de compras">
          <i class="bi bi-bag"></i>
          <span class="cart-count" data-cart-count data-count="<?= (int) ($cartCount ?? 0) ?>"><?= (int) ($cartCount ?? 0) ?: '' ?></span>
        </a>
      </div>
    </div>
  </nav>
</header>

<div class="offcanvas offcanvas-start" tabindex="-1" id="menuMovil" aria-labelledby="menuMovilLabel">
  <div class="offcanvas-header border-bottom">
    <a class="brand" href="/" id="menuMovilLabel"><img src="/assets/img/brand/firecat-mark.png" alt="" width="34" height="34"><span><?= e($storeName) ?></span></a>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
  </div>
  <div class="offcanvas-body">
    <form action="/catalogo" method="get" role="search" class="mb-3">
      <div class="input-icon"><i class="bi bi-search"></i>
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar prendas…" aria-label="Buscar productos" maxlength="100">
      </div>
    </form>
    <nav class="offcanvas-nav">
      <a href="/catalogo">Toda la tienda <i class="bi bi-arrow-right"></i></a>
      <a href="/catalogo?orden=recientes">Nuevo <i class="bi bi-arrow-right"></i></a>
      <a href="/catalogo?ofertas=1">Ofertas <i class="bi bi-arrow-right"></i></a>
      <?php foreach ($navCategories ?? [] as $cat): ?>
        <a href="/categoria/<?= e($cat['slug']) ?>"><?= e($cat['nombre']) ?> <i class="bi bi-arrow-right"></i></a>
      <?php endforeach; ?>
    </nav>
    <div class="mt-4 d-grid gap-2">
      <?php if ($currentUser): ?>
        <a class="btn btn-outline-dark" href="/cuenta">Mi cuenta</a>
        <?php if ($currentUser->isAdmin()): ?><a class="btn btn-dark" href="/admin">Panel administrativo</a><?php endif; ?>
      <?php else: ?>
        <a class="btn btn-dark" href="/login">Iniciar sesión</a>
        <a class="btn btn-outline-dark" href="/registro">Crear cuenta</a>
      <?php endif; ?>
    </div>
  </div>
</div>
