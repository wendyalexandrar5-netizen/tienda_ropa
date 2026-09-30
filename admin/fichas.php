<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$datos = costos_por_producto();
$productos = $datos['productos'];
$categorias = $datos['categorias'];
$cat = get_texto('categoria', 60);
if ($cat !== '') {
    $productos = array_values(array_filter($productos, static fn($p) => $p['categoria'] === $cat));
}
$sinFicha = count(array_filter($datos['productos'], static fn($p) => $p['costo'] === null));

$titulo = 'Fichas técnicas y costos de fabricación';
$seccion = 'fichas';
require __DIR__ . '/includes/header.php';
?>
<div class="tarjeta-admin mb-3">
    <div class="cabecera"><h2>¿Cuánto cuesta fabricar cada tipo de prenda?</h2><span class="small text-muted-fc">Promedio por categoría, con los costos actuales de los materiales</span></div>
    <div class="cuerpo">
        <div class="row g-3">
            <?php foreach ($categorias as $nombre => $c): ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <a class="tarjeta-admin pregunta d-block p-3 h-100 text-reset text-decoration-none" href="<?= url('admin/fichas.php', ['categoria' => $nombre]) ?>">
                        <div class="p"><?= e($nombre) ?> (<?= (int) $c['productos'] ?>)</div>
                        <div class="r"><?= precio($c['costo_promedio']) ?></div>
                        <div class="small text-muted-fc">Se venden a <?= precio($c['precio_promedio']) ?> · margen <?= porcentaje($c['margen_pct'], 0) ?></div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="formula mt-3 mb-0">Costo por prenda = Σ (cantidad × (1 + % merma) × costo promedio del material) + mano de obra directa + costos indirectos</p>
    </div>
</div>

<?php if ($sinFicha): ?><div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= $sinFicha ?> producto(s) no tienen ficha técnica: sus ventas no tendrán costo registrado y la utilidad aparecerá sobreestimada.</div><?php endif; ?>

<div class="tarjeta-admin">
    <div class="cabecera"><h2>Costo por producto<?= $cat !== '' ? ' · ' . e($cat) : '' ?></h2><?php if ($cat !== ''): ?><a class="small" href="<?= url('admin/fichas.php') ?>">Ver todos</a><?php endif; ?></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Producto</th><th class="text-end">Materiales</th><th class="text-end">Mano de obra</th><th class="text-end">Indirectos</th><th class="text-end">Costo total</th><th class="text-end">Costo real (producción)</th><th class="text-end">Precio de venta</th><th class="text-end">Margen</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($productos as $p): $mat = $p['costo'] !== null ? $p['costo'] - (float) $p['costo_mano_obra'] - (float) $p['costos_indirectos'] : null; ?>
                <tr>
                    <td><a class="fw-bold text-decoration-none" href="<?= url('admin/ficha_editar.php', ['producto' => $p['id']]) ?>"><?= e($p['nombre']) ?></a>
                        <div class="small text-muted-fc"><?= e($p['categoria']) ?><?= $p['tiempo_minutos'] ? ' · ' . (int) $p['tiempo_minutos'] . ' min' : '' ?><?= $p['estado'] !== 'activo' ? ' · inactivo' : '' ?></div></td>
                    <?php if ($p['costo'] === null): ?>
                        <td colspan="5" class="text-center"><span class="badge text-bg-warning">Sin ficha técnica</span></td>
                        <td class="text-end"><?= precio($p['precio']) ?></td><td></td>
                        <td class="text-end"><a class="btn btn-sm btn-fc" href="<?= url('admin/ficha_editar.php', ['producto' => $p['id']]) ?>">Crear ficha</a></td>
                    <?php else: ?>
                        <td class="text-end"><?= precio($mat) ?></td>
                        <td class="text-end"><?= precio($p['costo_mano_obra']) ?></td>
                        <td class="text-end"><?= precio($p['costos_indirectos']) ?></td>
                        <td class="text-end fw-bold"><?= precio($p['costo']) ?></td>
                        <td class="text-end small"><?= $p['costo_real'] !== null ? precio($p['costo_real']) : '<span class="text-muted-fc">—</span>' ?></td>
                        <td class="text-end"><?= precio($p['precio']) ?></td>
                        <td class="text-end fw-semibold <?= $p['margen'] < 0 ? 'cifra-negativa' : 'cifra-positiva' ?>"><?= precio($p['margen']) ?><div class="small"><?= porcentaje($p['margen_pct']) ?></div></td>
                        <td class="text-end"><a class="btn btn-sm btn-light" href="<?= url('admin/ficha_editar.php', ['producto' => $p['id']]) ?>" title="Editar ficha"><i class="bi bi-pencil"></i></a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
