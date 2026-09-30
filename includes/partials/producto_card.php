<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/** Tarjeta de producto. Requiere $p (producto con variantes adjuntas). */
$agotado  = (int) $p['stock_total'] <= 0;
$umbral   = umbral_stock_bajo();
$ultimas  = !$agotado && (int) $p['stock_total'] <= $umbral * 2;
$urlDetalle = url('producto.php', ['id' => $p['id']]);
?>
<article class="tarjeta-producto">
    <a class="media" href="<?= $urlDetalle ?>">
        <img src="<?= imagen_url($p['imagen']) ?>" alt="<?= e($p['nombre']) ?>" loading="lazy">
        <span class="etiquetas">
            <?php if ($agotado): ?><span class="etiqueta etiqueta-negra">Agotado</span><?php endif; ?>
            <?php if ($ultimas): ?><span class="etiqueta etiqueta-roja">Últimas unidades</span><?php endif; ?>
            <?php if ((int) $p['destacado'] === 1): ?><span class="etiqueta etiqueta-acento">Top</span><?php endif; ?>
        </span>
    </a>
    <div class="cuerpo">
        <span class="eyebrow mb-1"><?= e($p['categoria']) ?></span>
        <a class="nombre mb-1" href="<?= $urlDetalle ?>"><?= e($p['nombre']) ?></a>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="precio"><?= precio($p['precio']) ?></span>
            <span class="muestras" aria-label="Colores disponibles">
                <?php foreach ($p['colores'] as $nombreColor => $hex): ?>
                    <span class="muestra" style="background: <?= e($hex) ?>" title="<?= e($nombreColor) ?>"></span>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="d-flex flex-wrap gap-1 mb-1" aria-label="Tallas">
            <?php foreach ($p['tallas'] as $t): ?><span class="chip-talla"><?= e($t) ?></span><?php endforeach; ?>
        </div>
        <div class="small <?= $agotado ? 'text-danger' : 'text-success' ?> fw-semibold">
            <i class="bi <?= $agotado ? 'bi-x-circle' : 'bi-check-circle' ?> me-1"></i><?= $agotado ? 'Sin stock' : 'Disponible' ?>
        </div>

        <?php if (!$agotado): ?>
            <form class="agregar-rapido" method="post" action="<?= url('carrito.php') ?>" data-cargando>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="agregar">
                <input type="hidden" name="cantidad" value="1">
                <input type="hidden" name="retorno" value="<?= e(ruta_retorno_segura(ltrim(substr($_SERVER['REQUEST_URI'] ?? '', strlen(base_url())), '/'), 'productos.php')) ?>">
                <select class="form-select" name="variante_id" required aria-label="Talla y color de <?= e($p['nombre']) ?>">
                    <option value="">Talla / color</option>
                    <?php foreach ($p['variantes'] as $v): ?>
                        <option value="<?= (int) $v['id'] ?>" <?= (int) $v['stock'] <= 0 ? 'disabled' : '' ?>>
                            <?= e($v['talla'] . ' · ' . $v['color']) ?><?= (int) $v['stock'] <= 0 ? ' (agotado)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-fc btn-sm text-nowrap" type="submit" aria-label="Agregar al carrito">
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span><i class="bi bi-bag-plus"></i> Agregar
                </button>
            </form>
        <?php endif; ?>
        <a class="btn btn-outline-fc btn-sm mt-2" href="<?= $urlDetalle ?>">Ver detalles</a>
    </div>
</article>
