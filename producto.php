<?php
require __DIR__ . '/includes/bootstrap.php';

$producto = producto_publico(get_int('id'));
if (!$producto) {
    error_404('El producto que buscas no existe o ya no está disponible.');
}

// Tallas y colores presentes en las variantes activas del producto
$tallas = $colores = [];
foreach ($producto['variantes'] as $v) {
    $tallas[$v['talla_id']] ??= ['id' => (int) $v['talla_id'], 'nombre' => $v['talla'], 'orden' => (int) $v['orden'], 'stock' => 0];
    $tallas[$v['talla_id']]['stock'] += (int) $v['stock'];
    $colores[$v['color_id']] ??= ['id' => (int) $v['color_id'], 'nombre' => $v['color'], 'hex' => $v['codigo_hex'], 'stock' => 0];
    $colores[$v['color_id']]['stock'] += (int) $v['stock'];
}
uasort($tallas, static fn($a, $b) => $a['orden'] <=> $b['orden']);

// Selección inicial: la primera variante con stock
$inicial = null;
foreach ($producto['variantes'] as $v) {
    if ((int) $v['stock'] > 0) {
        $inicial = $v;
        break;
    }
}
$datosVariantes = array_map(static fn($v) => [
    'id' => (int) $v['id'], 'talla' => (int) $v['talla_id'], 'color' => (int) $v['color_id'], 'stock' => (int) $v['stock'],
], $producto['variantes']);

$agotado      = (int) $producto['stock_total'] <= 0;
$relacionados = productos_relacionados($producto, 4);
$umbral       = umbral_stock_bajo();

$titulo = $producto['nombre'];
$pagina = 'productos';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('index.php') ?>">Inicio</a></li>
            <li class="breadcrumb-item"><a href="<?= url('productos.php') ?>">Tienda</a></li>
            <li class="breadcrumb-item"><a href="<?= url('productos.php', ['categoria' => $producto['categoria_id']]) ?>"><?= e($producto['categoria']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($producto['nombre']) ?></li>
        </ol>
    </nav>

    <div class="row g-4 g-lg-5 mb-5">
        <div class="col-md-6">
            <div class="galeria-producto">
                <img src="<?= imagen_url($producto['imagen']) ?>" alt="<?= e($producto['nombre']) ?>">
            </div>
        </div>
        <div class="col-md-6">
            <span class="eyebrow"><?= e($producto['categoria']) ?></span>
            <h1 class="mt-1 mb-2"><?= e($producto['nombre']) ?></h1>
            <div class="precio-grande mb-3"><?= precio($producto['precio']) ?></div>
            <p class="text-muted-fc mb-4"><?= nl2br(e($producto['descripcion'])) ?></p>

            <?php if (!$producto['variantes'] || $agotado): ?>
                <div class="alert alert-secondary"><i class="bi bi-x-circle me-2"></i>Este producto está agotado por ahora. Vuelve pronto.</div>
            <?php else: ?>
                <script type="application/json" id="datos-variantes"><?= json_encode($datosVariantes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                <form method="post" action="<?= url('carrito.php') ?>" id="form-agregar" data-umbral="<?= $umbral ?>" data-cargando novalidate>
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="agregar">
                    <input type="hidden" name="producto_id" value="<?= (int) $producto['id'] ?>">
                    <input type="hidden" name="retorno" value="producto.php?id=<?= (int) $producto['id'] ?>">

                    <fieldset class="mb-3">
                        <legend class="form-label mb-2">Talla</legend>
                        <?php foreach ($tallas as $t): ?>
                            <label class="selector-opcion">
                                <input type="radio" name="talla_id" value="<?= $t['id'] ?>" required
                                    <?= $inicial && (int) $inicial['talla_id'] === $t['id'] ? 'checked' : '' ?> <?= $t['stock'] <= 0 ? 'disabled' : '' ?>>
                                <span><?= e($t['nombre']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <fieldset class="mb-3">
                        <legend class="form-label mb-2">Color</legend>
                        <?php foreach ($colores as $c): ?>
                            <label class="selector-opcion">
                                <input type="radio" name="color_id" value="<?= $c['id'] ?>" required
                                    <?= $inicial && (int) $inicial['color_id'] === $c['id'] ? 'checked' : '' ?> <?= $c['stock'] <= 0 ? 'disabled' : '' ?>>
                                <span><i class="muestra" style="background: <?= e($c['hex']) ?>"></i><?= e($c['nombre']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <p class="estado-stock mb-3" id="estado-stock" aria-live="polite">
                        <?php if ($inicial): ?><span class="text-success"><i class="bi bi-check-circle me-1"></i><?= (int) $inicial['stock'] ?> disponibles</span><?php endif; ?>
                    </p>

                    <div class="d-flex flex-wrap align-items-end gap-3 mb-4">
                        <div>
                            <label class="form-label" for="cantidad">Cantidad</label>
                            <div class="input-group cantidad-control">
                                <button class="btn btn-outline-secondary" type="button" data-cantidad="-1" aria-label="Restar"><i class="bi bi-dash"></i></button>
                                <input type="number" class="form-control" id="cantidad" name="cantidad" value="1" min="1"
                                       max="<?= min(MAX_UNIDADES_POR_ITEM, (int) ($inicial['stock'] ?? 1)) ?>" required>
                                <button class="btn btn-outline-secondary" type="button" data-cantidad="1" aria-label="Sumar"><i class="bi bi-plus"></i></button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-fc btn-lg flex-grow-1" id="btn-agregar">
                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                            <i class="bi bi-bag-plus me-1"></i> Agregar al carrito
                        </button>
                    </div>
                    <?php if (!esta_autenticado()): ?>
                        <p class="small text-muted-fc"><i class="bi bi-info-circle me-1"></i>Te pediremos iniciar sesión para guardar tu carrito.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <ul class="list-unstyled beneficios small border-top pt-3 mt-2">
                <li><i class="bi bi-truck"></i><span>Envío a todo el país. Gratis desde <?= precio(ajuste('envio_gratis_desde', '0')) ?>.</span></li>
                <li><i class="bi bi-cash-coin"></i><span>Paga en efectivo al recibir tu pedido.</span></li>
                <li><i class="bi bi-arrow-repeat"></i><span>Cambios de talla dentro de los 30 días.</span></li>
            </ul>
        </div>
    </div>

    <?php if ($relacionados): ?>
        <section>
            <h2 class="h3 mb-3">También te puede gustar</h2>
            <div class="row g-4">
                <?php foreach ($relacionados as $p): ?>
                    <div class="col-6 col-md-3"><?php require __DIR__ . '/includes/partials/producto_card.php'; ?></div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
