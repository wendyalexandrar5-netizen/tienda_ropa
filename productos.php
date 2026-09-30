<?php
require __DIR__ . '/includes/bootstrap.php';

$filtros = [
    'q'          => get_texto('q', 80),
    'categoria'  => max(0, get_int('categoria')),
    'talla'      => max(0, get_int('talla')),
    'color'      => max(0, get_int('color')),
    'precio_min' => get_texto('precio_min', 10),
    'precio_max' => get_texto('precio_max', 10),
    'disponible' => get_texto('disponible', 1) === '1',
    'orden'      => array_key_exists(get_texto('orden', 20), ORDENES_CATALOGO) ? get_texto('orden', 20) : 'recientes',
];
foreach (['precio_min', 'precio_max'] as $k) {
    if ($filtros[$k] !== '' && (!ctype_digit($filtros[$k]))) {
        $filtros[$k] = '';
    }
}

$resultado  = buscar_productos($filtros, max(1, get_int('pagina', 1)), 12);
$productos  = $resultado['items'];
$pag        = $resultado['pag'];
$categorias = categorias_activas();
$tallas     = tallas_todas();
$colores    = colores_todos();

$categoriaActual = null;
foreach ($categorias as $c) {
    if ((int) $c['id'] === $filtros['categoria']) {
        $categoriaActual = $c;
    }
}
$hayFiltros = $filtros['q'] !== '' || $filtros['categoria'] || $filtros['talla'] || $filtros['color']
    || $filtros['precio_min'] !== '' || $filtros['precio_max'] !== '' || $filtros['disponible'];

$titulo = $categoriaActual ? $categoriaActual['nombre'] : ($filtros['q'] !== '' ? 'Buscar: ' . $filtros['q'] : 'Tienda');
$pagina = 'productos';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('index.php') ?>">Inicio</a></li>
            <li class="breadcrumb-item<?= $categoriaActual ? '' : ' active' ?>"><?php if ($categoriaActual): ?><a href="<?= url('productos.php') ?>">Tienda</a><?php else: ?>Tienda<?php endif; ?></li>
            <?php if ($categoriaActual): ?><li class="breadcrumb-item active" aria-current="page"><?= e($categoriaActual['nombre']) ?></li><?php endif; ?>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="mb-1"><?= e($categoriaActual['nombre'] ?? 'Toda la tienda') ?></h1>
            <p class="text-muted-fc mb-0">
                <?= $pag['total'] ?> producto<?= $pag['total'] === 1 ? '' : 's' ?>
                <?php if ($filtros['q'] !== ''): ?> para «<strong><?= e($filtros['q']) ?></strong>»<?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-fc d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filtros" aria-controls="filtros">
                <i class="bi bi-sliders me-1"></i> Filtros
            </button>
            <form method="get" action="<?= url('productos.php') ?>" class="d-flex align-items-center gap-2">
                <?php foreach ($filtros as $k => $v): if ($k === 'orden' || $v === '' || $v === 0 || $v === false) continue; ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v === true ? '1' : $v) ?>">
                <?php endforeach; ?>
                <label for="orden" class="small text-muted-fc text-nowrap d-none d-sm-inline">Ordenar por</label>
                <select id="orden" name="orden" class="form-select form-select-sm" data-autoenviar>
                    <option value="recientes" <?= $filtros['orden'] === 'recientes' ? 'selected' : '' ?>>Más recientes</option>
                    <option value="precio_asc" <?= $filtros['orden'] === 'precio_asc' ? 'selected' : '' ?>>Precio: menor a mayor</option>
                    <option value="precio_desc" <?= $filtros['orden'] === 'precio_desc' ? 'selected' : '' ?>>Precio: mayor a menor</option>
                    <option value="nombre" <?= $filtros['orden'] === 'nombre' ? 'selected' : '' ?>>Nombre A-Z</option>
                </select>
                <noscript><button class="btn btn-sm btn-fc">Aplicar</button></noscript>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <aside class="col-lg-3">
            <div class="offcanvas-lg offcanvas-start" tabindex="-1" id="filtros" aria-labelledby="filtrosTitulo">
                <div class="offcanvas-header">
                    <h5 class="offcanvas-title" id="filtrosTitulo">Filtrar productos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#filtros" aria-label="Cerrar"></button>
                </div>
                <div class="offcanvas-body d-block">
                    <form class="panel-filtros w-100" method="get" action="<?= url('productos.php') ?>">
                        <input type="hidden" name="orden" value="<?= e($filtros['orden']) ?>">
                        <div class="mb-4">
                            <h6>Buscar</h6>
                            <input type="search" class="form-control" name="q" maxlength="80" value="<?= e($filtros['q']) ?>" placeholder="Nombre o descripción">
                        </div>
                        <div class="mb-4">
                            <h6>Categoría</h6>
                            <label class="filtro-opcion"><input type="radio" name="categoria" value="" <?= !$filtros['categoria'] ? 'checked' : '' ?>><span>Todas</span></label>
                            <?php foreach ($categorias as $c): ?>
                                <label class="filtro-opcion"><input type="radio" name="categoria" value="<?= (int) $c['id'] ?>" <?= $filtros['categoria'] === (int) $c['id'] ? 'checked' : '' ?>><span><?= e($c['nombre']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-4">
                            <h6>Talla</h6>
                            <label class="filtro-opcion"><input type="radio" name="talla" value="" <?= !$filtros['talla'] ? 'checked' : '' ?>><span>Todas</span></label>
                            <?php foreach ($tallas as $t): ?>
                                <label class="filtro-opcion"><input type="radio" name="talla" value="<?= (int) $t['id'] ?>" <?= $filtros['talla'] === (int) $t['id'] ? 'checked' : '' ?>><span><?= e($t['nombre']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-4">
                            <h6>Color</h6>
                            <label class="filtro-opcion"><input type="radio" name="color" value="" <?= !$filtros['color'] ? 'checked' : '' ?>><span>Todos</span></label>
                            <?php foreach ($colores as $c): ?>
                                <label class="filtro-opcion"><input type="radio" name="color" value="<?= (int) $c['id'] ?>" <?= $filtros['color'] === (int) $c['id'] ? 'checked' : '' ?>>
                                    <span><i class="muestra" style="background: <?= e($c['codigo_hex']) ?>"></i><?= e($c['nombre']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-4">
                            <h6>Precio (COP)</h6>
                            <div class="d-flex gap-2">
                                <input type="number" class="form-control" name="precio_min" min="0" step="1000" placeholder="Mín." value="<?= e($filtros['precio_min']) ?>" aria-label="Precio mínimo">
                                <input type="number" class="form-control" name="precio_max" min="0" step="1000" placeholder="Máx." value="<?= e($filtros['precio_max']) ?>" aria-label="Precio máximo">
                            </div>
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="disponible" name="disponible" value="1" <?= $filtros['disponible'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="disponible">Sólo con stock</label>
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn btn-fc" type="submit"><i class="bi bi-funnel me-1"></i>Aplicar filtros</button>
                            <?php if ($hayFiltros): ?><a class="btn btn-link text-muted-fc" href="<?= url('productos.php') ?>">Limpiar filtros</a><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </aside>

        <section class="col-lg-9">
            <?php if ($productos): ?>
                <div class="row g-4">
                    <?php foreach ($productos as $p): ?>
                        <div class="col-6 col-md-4"><?php require __DIR__ . '/includes/partials/producto_card.php'; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php require __DIR__ . '/includes/partials/paginacion.php'; ?>
            <?php else: ?>
                <div class="estado-vacio">
                    <div class="icono"><i class="bi bi-search"></i></div>
                    <h2 class="h4">No encontramos productos</h2>
                    <p class="text-muted-fc">Prueba con otras palabras o quita algunos filtros.</p>
                    <a class="btn btn-fc" href="<?= url('productos.php') ?>">Ver todo el catálogo</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
