<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$periodo = periodo_seleccionado('mes');
[$desde, $hasta] = [$periodo['desde'], $periodo['hasta']];
$r = resumen_financiero($desde, $hasta);
$egresos = egresos_detallados($desde, $hasta);
$rentabilidad = rentabilidad_productos($desde, $hasta);
$costos = costos_por_producto();
$inventarioMat = inventario_materiales_resumen();
$valorMateriales = array_sum(array_map('floatval', array_column($inventarioMat, 'valor')));
$flujo = flujo_mensual(6);

$comprasTipo = array_column($egresos['compras_tipo'], 'total', 'nombre');
$costoCategoria = static fn(string $nombre) => $costos['categorias'][$nombre]['costo_promedio'] ?? null;

if (get_texto('exportar', 10) === 'excel') {
    require ROOT_PATH . '/includes/xlsx.php';
    $resultado = [
        ['Ventas de productos', $r['ventas_productos']], ['Ingresos por envíos', $r['envios']], ['VENTAS TOTALES', $r['ventas']],
        ['(-) Costo de lo vendido (fabricación)', -$r['costo_ventas']], ['UTILIDAD BRUTA', $r['utilidad_bruta']],
        ['(-) Gastos operativos', -$r['gastos_grupo']['operativo']], ['(-) Mantenimiento', -$r['gastos_grupo']['mantenimiento']],
        ['(-) Otros gastos', -$r['gastos_grupo']['otro']], ['UTILIDAD ESTIMADA', $r['utilidad']],
        ['', null], ['FLUJO DE DINERO', null], ['Dinero que entró (pedidos cobrados)', $r['cobrado']],
        ['(-) Compras de materias primas', -$r['compras']], ['(-) Gastos pagados', -$r['gastos']], ['FLUJO NETO', $r['flujo_neto']],
    ];
    $egr = [];
    foreach ($egresos['compras_tipo'] as $c) { $egr[] = ['Compra de materias primas', $c['nombre'], (float) $c['total']]; }
    foreach ($egresos['gastos_categoria'] as $g) { $egr[] = [GRUPOS_GASTO[$g['grupo']], $g['nombre'], (float) $g['total']]; }
    enviar_xlsx("finanzas_{$desde}_{$hasta}.xlsx", [
        ['nombre' => 'Estado de resultados', 'columnas' => [['Concepto (' . $periodo['etiqueta'] . ')', 'texto', 42], ['Valor', 'moneda', 16]], 'filas' => $resultado],
        ['nombre' => 'En qué se gastó', 'columnas' => [['Tipo de egreso', 'texto', 26], ['Concepto', 'texto', 32], ['Total', 'moneda', 15]], 'filas' => $egr,
         'totales' => ['Total egresos', null, array_sum(array_column($egr, 2))]],
        ['nombre' => 'Flujo mensual', 'columnas' => [['Mes', 'texto', 10], ['Ventas', 'moneda', 14], ['Costo de lo vendido', 'moneda', 18], ['Gastos', 'moneda', 14],
            ['Utilidad estimada', 'moneda', 17], ['Dinero que entró', 'moneda', 16], ['Compras materiales', 'moneda', 18], ['Flujo neto', 'moneda', 14]],
         'filas' => array_map(static fn($m) => [$m['mes'], $m['ventas'], $m['costo_ventas'], $m['gastos'], $m['utilidad'], $m['cobrado'], $m['compras'], $m['neto']], $flujo)],
        ['nombre' => 'Rentabilidad por producto', 'columnas' => [['Producto', 'texto', 28], ['Categoría', 'texto', 14], ['Unidades', 'entero', 10], ['Ingresos', 'moneda', 14],
            ['Costo', 'moneda', 14], ['Utilidad', 'moneda', 14], ['Margen %', 'texto', 10]],
         'filas' => array_map(static fn($p) => [$p['nombre'], $p['categoria'], (int) $p['unidades'], (float) $p['ingresos'], (float) $p['costo'], (float) $p['utilidad'],
            (float) $p['ingresos'] > 0 ? porcentaje($p['utilidad'] / $p['ingresos'] * 100) : '—'], $rentabilidad),
         'totales' => ['Total', null, array_sum(array_column($rentabilidad, 'unidades')), array_sum(array_column($rentabilidad, 'ingresos')),
            array_sum(array_column($rentabilidad, 'costo')), array_sum(array_column($rentabilidad, 'utilidad')), null]],
        ['nombre' => 'Costo de fabricación', 'columnas' => [['Producto', 'texto', 28], ['Categoría', 'texto', 14], ['Costo por prenda', 'moneda', 16],
            ['Precio de venta', 'moneda', 15], ['Margen', 'moneda', 13], ['Margen %', 'texto', 10]],
         'filas' => array_map(static fn($p) => [$p['nombre'], $p['categoria'], $p['costo'], (float) $p['precio'], $p['margen'], porcentaje($p['margen_pct'])], $costos['productos'])],
        ['nombre' => 'Inventario de materiales', 'columnas' => [['Código', 'texto', 11], ['Material', 'texto', 36], ['Tipo', 'texto', 18], ['Stock', 'texto', 14],
            ['Costo promedio', 'moneda', 15], ['Valor', 'moneda', 15]],
         'filas' => array_map(static fn($m) => [$m['codigo'], $m['nombre'], $m['tipo'], cantidad_fmt($m['stock'], $m['unidad']), (float) $m['costo_promedio'], (float) $m['valor']],
            filas("SELECT m.codigo, m.nombre, t.nombre AS tipo, m.stock, m.unidad, m.costo_promedio, m.stock * m.costo_promedio AS valor
                   FROM materiales m JOIN tipos_material t ON t.id = m.tipo_id WHERE m.estado = 'activo' ORDER BY t.id, m.nombre")),
         'totales' => ['Total', null, null, null, null, $valorMateriales]],
    ]);
}

$graficoFlujo = [
    'etiquetas' => array_column($flujo, 'mes'),
    'series' => [
        ['titulo' => 'Ventas', 'valores' => array_column($flujo, 'ventas'), 'color' => '#0e0e0e', 'tipo' => 'bar'],
        ['titulo' => 'Compras + gastos', 'valores' => array_map(static fn($m) => $m['compras'] + $m['gastos'], $flujo), 'color' => '#c9c4b9', 'tipo' => 'bar'],
        ['titulo' => 'Utilidad estimada', 'valores' => array_column($flujo, 'utilidad'), 'color' => '#e8a200', 'tipo' => 'line'],
    ],
];
$graficoEgresos = ['etiquetas' => [], 'valores' => []];
foreach ($egresos['compras_tipo'] as $c) { $graficoEgresos['etiquetas'][] = $c['nombre']; $graficoEgresos['valores'][] = (float) $c['total']; }
foreach (GRUPOS_GASTO as $k => $t) { if ($r['gastos_grupo'][$k] > 0) { $graficoEgresos['etiquetas'][] = $t; $graficoEgresos['valores'][] = $r['gastos_grupo'][$k]; } }

$claseCifra = static fn(float $v) => $v < 0 ? 'cifra-negativa' : 'cifra-positiva';

$titulo = 'Finanzas: utilidad y flujo de dinero';
$seccion = 'finanzas';
$usar_graficos = true;
require __DIR__ . '/includes/header.php';
?>
<form class="barra-filtros row g-2 align-items-end mb-3 no-imprimir" method="get" action="<?= url('admin/finanzas.php') ?>">
    <div class="col-6 col-lg-3"><label class="form-label small" for="periodo">Periodo</label>
        <select class="form-select form-select-sm" id="periodo" name="periodo">
            <?php foreach (['mes' => 'Este mes', 'mes_anterior' => 'Mes anterior', '30d' => 'Últimos 30 días', 'anio' => 'Este año', 'rango' => 'Rango personalizado'] as $k => $t): ?>
                <option value="<?= $k ?>" <?= $periodo['clave'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="desde">Desde</label><input class="form-control form-control-sm" type="date" id="desde" name="desde" value="<?= e($desde) ?>"></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="hasta">Hasta</label><input class="form-control form-control-sm" type="date" id="hasta" name="hasta" value="<?= e($hasta) ?>"></div>
    <div class="col-6 col-lg-2"><button class="btn btn-sm btn-fc w-100">Aplicar</button></div>
    <div class="col-lg-3 d-flex gap-2 justify-content-lg-end">
        <a class="btn btn-sm btn-success" href="<?= url('admin/finanzas.php', ['periodo' => $periodo['clave'], 'desde' => $desde, 'hasta' => $hasta, 'exportar' => 'excel']) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Exportar a Excel</a>
        <button class="btn btn-sm btn-light" type="button" data-imprimir aria-label="Imprimir"><i class="bi bi-printer"></i></button>
    </div>
</form>

<h2 class="h5 fw-bold mb-3"><?= e($periodo['etiqueta']) ?> <span class="text-muted-fc fw-normal small">· <?= fecha($desde, false) ?> al <?= fecha($hasta, false) ?></span></h2>

<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3"><div class="tarjeta-admin pregunta p-3 h-100"><div class="p">¿Cuánto dinero ingresó por ventas?</div><div class="r"><?= precio($r['ventas']) ?></div>
        <div class="small text-muted-fc"><?= $r['pedidos'] ?> pedidos · <?= $r['unidades'] ?> prendas · cobrado (entregados): <?= precio($r['cobrado']) ?></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="tarjeta-admin pregunta p-3 h-100"><div class="p">¿Cuánto gastamos en materias primas?</div><div class="r"><?= precio($r['compras']) ?></div>
        <div class="small text-muted-fc">Telas: <strong><?= precio($comprasTipo['Telas'] ?? 0) ?></strong> · otros materiales: <?= precio($r['compras'] - (float) ($comprasTipo['Telas'] ?? 0)) ?></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="tarjeta-admin pregunta p-3 h-100"><div class="p">¿Y en mantenimiento y otros gastos?</div><div class="r"><?= precio($r['gastos']) ?></div>
        <div class="small text-muted-fc">Mantenimiento <?= precio($r['gastos_grupo']['mantenimiento']) ?> · operativos <?= precio($r['gastos_grupo']['operativo']) ?> · otros <?= precio($r['gastos_grupo']['otro']) ?></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="tarjeta-admin pregunta p-3 h-100" style="border-left-color:<?= $r['utilidad'] < 0 ? '#c62828' : '#198754' ?>"><div class="p">¿Cuál fue la utilidad aproximada?</div>
        <div class="r <?= $claseCifra($r['utilidad']) ?>"><?= precio($r['utilidad']) ?></div>
        <div class="small text-muted-fc">Margen neto <?= porcentaje($r['margen_neto']) ?> · margen bruto <?= porcentaje($r['margen_bruto']) ?></div></div></div>
    <div class="col-md-6 col-xl-3"><a class="tarjeta-admin pregunta p-3 h-100 d-block text-reset text-decoration-none" href="<?= url('admin/fichas.php', ['categoria' => 'Camisetas']) ?>"><div class="p">¿Cuánto cuesta fabricar una camiseta?</div>
        <div class="r"><?= $costoCategoria('Camisetas') !== null ? precio($costoCategoria('Camisetas')) : '—' ?></div><div class="small text-muted-fc">Promedio de <?= (int) ($costos['categorias']['Camisetas']['productos'] ?? 0) ?> referencias · ver detalle</div></a></div>
    <div class="col-md-6 col-xl-3"><a class="tarjeta-admin pregunta p-3 h-100 d-block text-reset text-decoration-none" href="<?= url('admin/fichas.php', ['categoria' => 'Pantalones']) ?>"><div class="p">¿Cuánto cuesta fabricar un pantalón?</div>
        <div class="r"><?= $costoCategoria('Pantalones') !== null ? precio($costoCategoria('Pantalones')) : '—' ?></div><div class="small text-muted-fc">Promedio de <?= (int) ($costos['categorias']['Pantalones']['productos'] ?? 0) ?> referencias · ver detalle</div></a></div>
    <div class="col-md-6 col-xl-3"><a class="tarjeta-admin pregunta p-3 h-100 d-block text-reset text-decoration-none" href="<?= url('admin/materiales.php') ?>"><div class="p">¿Qué materiales tenemos en inventario?</div>
        <div class="r"><?= precio($valorMateriales) ?></div><div class="small text-muted-fc"><?= array_sum(array_column($inventarioMat, 'materiales')) ?> materiales · <?= array_sum(array_column($inventarioMat, 'bajos')) ?> bajo el mínimo · ver inventario</div></a></div>
    <div class="col-md-6 col-xl-3"><div class="tarjeta-admin pregunta p-3 h-100"><div class="p">¿Cuánto fabricamos?</div><div class="r"><?= (int) $r['produccion']['prendas'] ?> prendas</div>
        <div class="small text-muted-fc">Costo <?= precio($r['produccion']['costo']) ?> · materiales consumidos <?= precio($r['consumo']) ?></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Estado de resultados (utilidad)</h2><span class="small text-muted-fc">Lo que se ganó con lo que se vendió</span></div>
            <div class="table-responsive"><table class="table tabla-admin mb-0">
                <tbody>
                    <tr><td>Ventas de productos</td><td class="text-end"><?= precio($r['ventas_productos']) ?></td></tr>
                    <tr><td>Ingresos por envíos</td><td class="text-end"><?= precio($r['envios']) ?></td></tr>
                    <tr class="fw-bold"><td>Ventas totales</td><td class="text-end"><?= precio($r['ventas']) ?></td></tr>
                    <tr><td>(−) Costo de lo vendido <span class="small text-muted-fc">(materiales + mano de obra + indirectos de las <?= $r['unidades'] ?> prendas vendidas)</span></td><td class="text-end cifra-negativa"><?= precio(-$r['costo_ventas']) ?></td></tr>
                    <tr class="fw-bold table-light"><td>Utilidad bruta <span class="small fw-normal text-muted-fc">(margen <?= porcentaje($r['margen_bruto']) ?>)</span></td><td class="text-end"><?= precio($r['utilidad_bruta']) ?></td></tr>
                    <?php foreach (GRUPOS_GASTO as $k => $t): ?><tr><td>(−) <?= e($t) ?></td><td class="text-end cifra-negativa"><?= precio(-$r['gastos_grupo'][$k]) ?></td></tr><?php endforeach; ?>
                    <tr class="fw-bold fs-5"><td>Utilidad estimada <span class="small fw-normal text-muted-fc">(margen <?= porcentaje($r['margen_neto']) ?>)</span></td><td class="text-end <?= $claseCifra($r['utilidad']) ?>"><?= precio($r['utilidad']) ?></td></tr>
                </tbody>
            </table></div>
            <?php if ($r['lineas_sin_costo'] > 0): ?><div class="alert alert-warning small m-3 mb-0"><?= $r['lineas_sin_costo'] ?> línea(s) vendidas no tienen costo (producto sin ficha técnica): la utilidad real es menor.</div><?php endif; ?>
            <div class="cuerpo pt-2"><p class="small text-muted-fc mb-0"><i class="bi bi-info-circle me-1"></i>La mano de obra de confección va dentro del costo de cada prenda (ficha técnica), por eso no se resta otra vez como gasto.</p></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Flujo de dinero (caja)</h2><span class="small text-muted-fc">Lo que entró y salió de la cuenta</span></div>
            <div class="table-responsive"><table class="table tabla-admin mb-0">
                <tbody>
                    <tr><td>Dinero que entró <span class="small text-muted-fc">(pedidos entregados y cobrados)</span></td><td class="text-end cifra-positiva"><?= precio($r['cobrado']) ?></td></tr>
                    <tr><td>(−) Compras de materias primas</td><td class="text-end cifra-negativa"><?= precio(-$r['compras']) ?></td></tr>
                    <tr><td>(−) Gastos pagados</td><td class="text-end cifra-negativa"><?= precio(-$r['gastos']) ?></td></tr>
                    <tr class="fw-bold fs-5"><td>Flujo neto del periodo</td><td class="text-end <?= $claseCifra($r['flujo_neto']) ?>"><?= precio($r['flujo_neto']) ?></td></tr>
                </tbody>
            </table></div>
            <div class="cuerpo">
                <div class="grafico" style="height:220px"><canvas data-grafico="dona" data-fuente="g-egresos" role="img" aria-label="Distribución de egresos"></canvas></div>
                <script type="application/json" id="g-egresos"><?= json_encode($graficoEgresos, JSON_HEX_TAG) ?></script>
                <p class="small text-muted-fc mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Comprar tela es una salida de dinero, pero todavía no es un costo: se vuelve costo cuando la prenda fabricada se vende. Por eso la utilidad y el flujo de caja no dan lo mismo.</p>
            </div>
        </div>
    </div>
</div>

<div class="tarjeta-admin mb-3">
    <div class="cabecera"><h2>Evolución mensual</h2></div>
    <div class="cuerpo"><div class="grafico"><canvas data-grafico="combinado" data-fuente="g-flujo" role="img" aria-label="Ventas, egresos y utilidad por mes"></canvas></div></div>
    <script type="application/json" id="g-flujo"><?= json_encode($graficoFlujo, JSON_HEX_TAG) ?></script>
    <div class="table-responsive"><table class="table tabla-admin mb-0">
        <thead><tr><th>Mes</th><th class="text-end">Ventas</th><th class="text-end">Costo vendido</th><th class="text-end">Gastos</th><th class="text-end">Utilidad estimada</th><th class="text-end">Entró</th><th class="text-end">Compras</th><th class="text-end">Flujo neto</th></tr></thead>
        <tbody><?php foreach ($flujo as $m): ?>
            <tr><td class="fw-semibold"><?= e($m['mes']) ?></td><td class="text-end"><?= precio($m['ventas']) ?></td><td class="text-end"><?= precio($m['costo_ventas']) ?></td><td class="text-end"><?= precio($m['gastos']) ?></td>
                <td class="text-end fw-bold <?= $claseCifra($m['utilidad']) ?>"><?= precio($m['utilidad']) ?></td><td class="text-end"><?= precio($m['cobrado']) ?></td><td class="text-end"><?= precio($m['compras']) ?></td>
                <td class="text-end fw-bold <?= $claseCifra($m['neto']) ?>"><?= precio($m['neto']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>¿En qué se gastó el dinero?</h2></div>
            <div class="table-responsive"><table class="table tabla-admin mb-0">
                <thead><tr><th>Concepto</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
                <tbody>
                <?php $totEgr = max(1, $r['egresos']); ?>
                <?php foreach ($egresos['compras_tipo'] as $c): ?>
                    <tr><td><i class="bi <?= e($c['icono']) ?> me-1"></i>Compra de <?= e(mb_strtolower($c['nombre'])) ?></td><td class="text-end"><?= precio($c['total']) ?></td><td class="text-end small"><?= porcentaje($c['total'] / $totEgr * 100) ?></td></tr>
                <?php endforeach; ?>
                <?php foreach ($egresos['gastos_categoria'] as $g): ?>
                    <tr><td><?= e($g['nombre']) ?> <span class="small text-muted-fc">· <?= e(GRUPOS_GASTO[$g['grupo']]) ?></span></td><td class="text-end"><?= precio($g['total']) ?></td><td class="text-end small"><?= porcentaje($g['total'] / $totEgr * 100) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$egresos['compras_tipo'] && !$egresos['gastos_categoria']): ?><tr><td colspan="3" class="text-center text-muted-fc py-4">Sin egresos en el periodo.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr><td>Total egresos</td><td class="text-end"><?= precio($r['egresos']) ?></td><td></td></tr></tfoot>
            </table></div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Rentabilidad por producto vendido</h2><span class="small text-muted-fc">Precio de venta y costo congelados en cada pedido</span></div>
            <div class="table-responsive" style="max-height:460px"><table class="table tabla-admin mb-0">
                <thead><tr><th>Producto</th><th class="text-center">Uds.</th><th class="text-end">Ingresos</th><th class="text-end">Costo</th><th class="text-end">Utilidad</th><th class="text-end">Margen</th></tr></thead>
                <tbody>
                <?php foreach ($rentabilidad as $p): $m = (float) $p['ingresos'] > 0 ? $p['utilidad'] / $p['ingresos'] * 100 : 0; ?>
                    <tr><td><?= e($p['nombre']) ?><div class="small text-muted-fc"><?= e($p['categoria']) ?><?= (int) $p['sin_costo'] ? ' · <span class="text-warning-emphasis">sin costo</span>' : '' ?></div></td>
                        <td class="text-center"><?= (int) $p['unidades'] ?></td><td class="text-end"><?= precio($p['ingresos']) ?></td><td class="text-end"><?= precio($p['costo']) ?></td>
                        <td class="text-end fw-semibold <?= $claseCifra((float) $p['utilidad']) ?>"><?= precio($p['utilidad']) ?></td><td class="text-end small"><?= porcentaje($m) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$rentabilidad): ?><tr><td colspan="6" class="text-center text-muted-fc py-4">Sin ventas en el periodo.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Costo de fabricación por tipo de prenda</h2><a class="small" href="<?= url('admin/fichas.php') ?>">Ver fichas técnicas</a></div>
            <div class="table-responsive"><table class="table tabla-admin mb-0">
                <thead><tr><th>Categoría</th><th class="text-end">Cuesta fabricar</th><th class="text-end">Se vende a</th><th class="text-end">Margen</th></tr></thead>
                <tbody><?php foreach ($costos['categorias'] as $nombre => $c): ?>
                    <tr><td><?= e($nombre) ?> <span class="small text-muted-fc">(<?= (int) $c['productos'] ?>)</span></td><td class="text-end fw-semibold"><?= precio($c['costo_promedio']) ?></td>
                        <td class="text-end"><?= precio($c['precio_promedio']) ?></td><td class="text-end"><?= porcentaje($c['margen_pct']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Inventario de materias primas</h2><a class="small" href="<?= url('admin/materiales.php') ?>">Ver materiales</a></div>
            <div class="table-responsive"><table class="table tabla-admin mb-0">
                <thead><tr><th>Tipo</th><th class="text-center">Ítems</th><th class="text-center">Bajo mínimo</th><th class="text-end">Valor</th></tr></thead>
                <tbody><?php foreach ($inventarioMat as $t): ?>
                    <tr><td><i class="bi <?= e($t['icono']) ?> me-1"></i><?= e($t['nombre']) ?></td><td class="text-center"><?= (int) $t['materiales'] ?></td>
                        <td class="text-center"><?= (int) $t['bajos'] ? '<span class="badge text-bg-warning">' . (int) $t['bajos'] . '</span>' : '—' ?></td><td class="text-end"><?= precio($t['valor']) ?></td></tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><td colspan="3">Total</td><td class="text-end"><?= precio($valorMateriales) ?></td></tr></tfoot>
            </table></div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
