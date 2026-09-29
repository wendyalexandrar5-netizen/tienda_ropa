<?php
require __DIR__ . '/includes/bootstrap.php';

$usuario = usuario_actual();

if (es_post()) {
    $accion  = post_texto('accion', 20);
    $retorno = ruta_retorno_segura(post_texto('retorno', 300), 'carrito.php');

    if (!$usuario) {
        flash('info', 'Inicia sesión o crea una cuenta para agregar productos a tu carrito.');
        redirigir('login.php', ['retorno' => $retorno]);
    }
    $uid = (int) $usuario['id'];

    try {
        switch ($accion) {
            case 'agregar':
                $varianteId = post_int('variante_id');
                if ($varianteId <= 0) {
                    // Desde la ficha del producto llegan talla y color: el servidor resuelve la variante.
                    $varianteId = (int) valor('SELECT id FROM variantes_producto WHERE producto_id = ? AND talla_id = ? AND color_id = ?',
                        [post_int('producto_id'), post_int('talla_id'), post_int('color_id')]);
                }
                if ($varianteId <= 0) {
                    throw new DomainException('Selecciona una talla y un color disponibles.');
                }
                carrito_agregar($uid, $varianteId, post_int('cantidad', 1));
                $info = fila('SELECT p.nombre, t.nombre AS talla, c.nombre AS color FROM variantes_producto v
                              JOIN productos p ON p.id = v.producto_id JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id
                              WHERE v.id = ?', [$varianteId]);
                flash('success', "«{$info['nombre']}» ({$info['talla']} / {$info['color']}) se agregó a tu carrito.");
                redirigir($retorno);
                break;
            case 'actualizar':
                carrito_actualizar($uid, post_int('item_id'), post_int('cantidad', 1));
                flash('success', 'Cantidad actualizada.');
                break;
            case 'eliminar':
                carrito_eliminar($uid, post_int('item_id'));
                flash('success', 'Producto eliminado del carrito.');
                break;
            case 'vaciar':
                carrito_vaciar($uid);
                flash('success', 'Tu carrito quedó vacío.');
                break;
            default:
                flash('danger', 'Acción no válida.');
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        if ($accion === 'agregar') {
            redirigir($retorno);
        }
    }
    redirigir('carrito.php');
}

$items = [];
$resumen = ['subtotal' => 0, 'envio' => 0, 'total' => 0, 'unidades' => 0];
if ($usuario) {
    foreach (carrito_revisar((int) $usuario['id']) as $aviso) {
        flash('warning', $aviso);
    }
    $items   = carrito_items((int) $usuario['id']);
    $resumen = carrito_resumen($items);
}
$gratisDesde = (float) ajuste('envio_gratis_desde', '0');

$titulo = 'Carrito';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
    <ol class="pasos-checkout" aria-label="Proceso de compra">
        <li class="actual"><span class="num">1</span>Carrito</li>
        <li><span class="num">2</span>Entrega</li>
        <li><span class="num">3</span>Resumen</li>
        <li><span class="num">4</span>Confirmación</li>
    </ol>

    <h1 class="mb-4">Tu carrito</h1>

    <?php if (!$usuario): ?>
        <div class="estado-vacio">
            <div class="icono"><i class="bi bi-person-lock"></i></div>
            <h2 class="h4">Inicia sesión para ver tu carrito</h2>
            <p class="text-muted-fc">Tu carrito se guarda en tu cuenta para que no pierdas nada.</p>
            <div class="d-flex justify-content-center gap-2">
                <a class="btn btn-fc" href="<?= url('login.php', ['retorno' => 'carrito.php']) ?>">Iniciar sesión</a>
                <a class="btn btn-outline-fc" href="<?= url('registro.php') ?>">Crear cuenta</a>
            </div>
        </div>
    <?php elseif (!$items): ?>
        <div class="estado-vacio">
            <div class="icono"><i class="bi bi-bag"></i></div>
            <h2 class="h4">Tu carrito está vacío</h2>
            <p class="text-muted-fc">Descubre nuestras sudaderas, cargos y conjuntos.</p>
            <a class="btn btn-fc" href="<?= url('productos.php') ?>">Ir a la tienda</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="caja">
                    <?php foreach ($items as $i): ?>
                        <div class="item-carrito d-flex gap-3">
                            <a href="<?= url('producto.php', ['id' => $i['producto_id']]) ?>"><img src="<?= imagen_url($i['imagen']) ?>" alt="<?= e($i['nombre']) ?>"></a>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <a class="fw-bold text-decoration-none" href="<?= url('producto.php', ['id' => $i['producto_id']]) ?>"><?= e($i['nombre']) ?></a>
                                        <div class="small text-muted-fc">
                                            Talla <strong><?= e($i['talla']) ?></strong> ·
                                            <i class="muestra align-middle" style="background: <?= e($i['codigo_hex']) ?>; width:12px; height:12px"></i> <?= e($i['color']) ?>
                                        </div>
                                        <div class="small text-muted-fc">Precio unitario: <?= precio($i['precio']) ?></div>
                                    </div>
                                    <div class="fw-bold text-nowrap"><?= precio($i['subtotal']) ?></div>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <form method="post" action="<?= url('carrito.php') ?>" class="d-flex align-items-center gap-2">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="accion" value="actualizar">
                                        <input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
                                        <label class="small text-muted-fc" for="cant-<?= (int) $i['id'] ?>">Cant.</label>
                                        <input class="form-control form-control-sm" style="width: 80px" type="number" id="cant-<?= (int) $i['id'] ?>" name="cantidad"
                                               value="<?= (int) $i['cantidad'] ?>" min="1" max="<?= min(MAX_UNIDADES_POR_ITEM, (int) $i['stock']) ?>" data-autoenviar>
                                        <button class="btn btn-sm btn-outline-fc" type="submit">Actualizar</button>
                                    </form>
                                    <form method="post" action="<?= url('carrito.php') ?>">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
                                        <button class="btn btn-sm btn-link text-danger" type="submit"><i class="bi bi-trash me-1"></i>Eliminar</button>
                                    </form>
                                    <?php if ((int) $i['stock'] <= umbral_stock_bajo()): ?>
                                        <span class="badge text-bg-warning">Quedan <?= (int) $i['stock'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between mt-3">
                    <a class="btn btn-outline-fc" href="<?= url('productos.php') ?>"><i class="bi bi-arrow-left me-1"></i>Seguir comprando</a>
                    <form method="post" action="<?= url('carrito.php') ?>" data-confirmar="¿Seguro que deseas vaciar el carrito?">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="vaciar">
                        <button class="btn btn-link text-muted-fc" type="submit">Vaciar carrito</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-4">
                <?php require __DIR__ . '/includes/partials/resumen_compra.php'; ?>
                <a class="btn btn-fc btn-lg w-100 mt-3" href="<?= url('checkout.php') ?>">Continuar compra <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
