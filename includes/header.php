<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Cabecera común de la tienda.
 * Variables opcionales: $titulo (string), $pagina (clave del menú activo), $sin_contenedor (bool).
 */
$usuario = usuario_actual();
$titulo  = $titulo ?? 'Tienda';
$pagina  = $pagina ?? '';
$totalCarrito = $usuario ? carrito_contar((int) $usuario['id']) : 0;
$nombreTienda = ajuste('nombre_tienda', config('app_name'));
$envioGratis  = (float) ajuste('envio_gratis_desde', '0');
$enlacesMenu = [
    'inicio'     => ['index.php', 'Inicio'],
    'productos'  => ['productos.php', 'Tienda'],
    'categorias' => ['categorias.php', 'Categorías'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($nombreTienda) ?> · Tienda online de ropa urbana: camisetas, sudaderas, chaquetas, pantalones y conjuntos.">
    <title><?= e($titulo) ?> · <?= e($nombreTienda) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/tienda.css') ?>">
</head>
<body>
<a class="visually-hidden-focusable position-absolute p-2 bg-white" href="#contenido">Saltar al contenido</a>

<div class="barra-anuncio py-2 text-center no-imprimir">
    <div class="container">
        <i class="bi bi-truck me-1"></i>
        <?php if ($envioGratis > 0): ?>
            Envío <strong>gratis</strong> desde <?= precio($envioGratis) ?> ·
        <?php endif; ?>
        Paga <strong>contra entrega</strong> en todo el país
    </div>
</div>

<nav class="navbar navbar-expand-lg navbar-fc sticky-top py-2" aria-label="Navegación principal">
    <div class="container">
        <a class="marca me-lg-4" href="<?= url('index.php') ?>">
            <img src="<?= asset('assets/img/brand/firecat-mark.png') ?>" alt="" width="40" height="40">
            <span><?= e($nombreTienda) ?></span>
        </a>

        <div class="d-flex align-items-center gap-1 order-lg-3 ms-auto ms-lg-0">
            <?php if ($usuario): ?>
                <div class="dropdown">
                    <button class="btn btn-link p-0 border-0 ms-1" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mi cuenta">
                        <span class="avatar"><?= e(iniciales($usuario['nombre'], $usuario['apellido'])) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                        <li class="px-3 py-2">
                            <div class="fw-bold"><?= e($usuario['nombre'] . ' ' . $usuario['apellido']) ?></div>
                            <div class="small text-muted-fc"><?= e($usuario['email']) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if ($usuario['rol'] === ROL_ADMIN): ?>
                            <li><a class="dropdown-item rounded" href="<?= url('admin/index.php') ?>"><i class="bi bi-speedometer2 me-2"></i>Panel administrativo</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item rounded" href="<?= url('perfil.php') ?>"><i class="bi bi-person me-2"></i>Mi perfil</a></li>
                        <li><a class="dropdown-item rounded" href="<?= url('mis_pedidos.php') ?>"><i class="bi bi-bag me-2"></i>Mis pedidos</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= url('logout.php') ?>">
                                <?= csrf_campo() ?>
                                <button type="submit" class="dropdown-item rounded text-danger"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
                            </form>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <a class="btn btn-icono btn-light d-lg-none" href="<?= url('login.php') ?>" aria-label="Iniciar sesión"><i class="bi bi-person"></i></a>
                <a class="btn btn-sm btn-outline-fc d-none d-lg-inline-flex" href="<?= url('login.php') ?>">Ingresar</a>
                <a class="btn btn-sm btn-fc d-none d-lg-inline-flex" href="<?= url('registro.php') ?>">Crear cuenta</a>
            <?php endif; ?>
            <a class="btn btn-icono btn-light position-relative ms-1" href="<?= url('carrito.php') ?>" aria-label="Carrito (<?= $totalCarrito ?> productos)">
                <i class="bi bi-bag fs-5"></i>
                <?php if ($totalCarrito > 0): ?><span class="contador-carrito"><?= $totalCarrito ?></span><?php endif; ?>
            </a>
            <button class="navbar-toggler border-0 ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#menuPrincipal"
                    aria-controls="menuPrincipal" aria-expanded="false" aria-label="Abrir menú">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse order-lg-2" id="menuPrincipal">
            <ul class="navbar-nav me-lg-3 gap-lg-1">
                <?php foreach ($enlacesMenu as $clave => [$ruta, $texto]): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $pagina === $clave ? ' active' : '' ?>" <?= $pagina === $clave ? 'aria-current="page"' : '' ?> href="<?= url($ruta) ?>"><?= e($texto) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form class="buscador flex-grow-1 me-lg-3 mt-2 mt-lg-0" action="<?= url('productos.php') ?>" method="get" role="search">
                <i class="bi bi-search"></i>
                <input class="form-control" type="search" name="q" maxlength="80" placeholder="Buscar camisetas, sudaderas, cargo…"
                       value="<?= e(get_texto('q', 80)) ?>" aria-label="Buscar productos">
            </form>
            <?php if (!$usuario): ?>
                <div class="d-flex gap-2 d-lg-none mt-3">
                    <a class="btn btn-outline-fc flex-fill" href="<?= url('login.php') ?>">Ingresar</a>
                    <a class="btn btn-fc flex-fill" href="<?= url('registro.php') ?>">Crear cuenta</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main id="contenido">
<?php if (empty($sin_contenedor)): ?>
<div class="container pt-3">
    <?php require __DIR__ . '/partials/alertas.php'; ?>
</div>
<?php endif; ?>
