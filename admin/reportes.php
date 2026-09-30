<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$esFecha = static fn(string $f) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;
$desde = get_texto('desde', 10);
$hasta = get_texto('hasta', 10);
$desde = $esFecha($desde) ? $desde : date('Y-m-d', strtotime('-29 days'));
$hasta = $esFecha($hasta) ? $hasta : date('Y-m-d');
if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}
$rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

// "Caja": resumen por día (evolución del módulo caja.html del proyecto original)
$caja = filas("SELECT DATE(fecha_pedido) AS dia, COUNT(*) AS pedidos, SUM(total) AS total,
                      SUM(CASE WHEN estado = 'entregado' THEN total ELSE 0 END) AS cobrado,
                      SUM(COALESCE(pago_con, 0)) AS efectivo, SUM(CASE WHEN pago_con IS NOT NULL THEN pago_con - total ELSE 0 END) AS cambio
               FROM pedidos WHERE estado <> 'cancelado' AND fecha_pedido BETWEEN ? AND ?
               GROUP BY DATE(fecha_pedido) ORDER BY dia DESC", $rango);

$porCategoria = filas("SELECT c.nombre, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS ingresos
                       FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id JOIN productos pr ON pr.id = d.producto_id
                       JOIN categorias c ON c.id = pr.categoria_id
                       WHERE p.estado <> 'cancelado' AND p.fecha_pedido BETWEEN ? AND ?
                       GROUP BY c.id, c.nombre ORDER BY ingresos DESC", $rango);

$top = filas("SELECT d.nombre_producto, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS ingresos, AVG(d.precio_unitario) AS precio_medio
              FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
              WHERE p.estado <> 'cancelado' AND p.fecha_pedido BETWEEN ? AND ?
              GROUP BY d.producto_id, d.nombre_producto ORDER BY unidades DESC, ingresos DESC LIMIT 10", $rango);

$resumen = fila("SELECT COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS ventas, COALESCE(AVG(total), 0) AS ticket,
                        COALESCE(SUM(costo_envio), 0) AS envios, COUNT(DISTINCT usuario_id) AS compradores
                 FROM pedidos WHERE estado <> 'cancelado' AND fecha_pedido BETWEEN ? AND ?", $rango);
$cancelados = (int) valor("SELECT COUNT(*) FROM pedidos WHERE estado = 'cancelado' AND fecha_pedido BETWEEN ? AND ?", $rango);
$unidades = (int) valor("SELECT COALESCE(SUM(d.cantidad), 0) FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
                        WHERE p.estado <> 'cancelado' AND p.fecha_pedido BETWEEN ? AND ?", $rango);

$export = get_texto('exportar', 10);
if ($export === 'pedidos' || $export === 'ventas') {
    require ROOT_PATH . '/includes/xlsx.php';
    $sufijo = "{$desde}_{$hasta}.xlsx";

    if ($export === 'pedidos') {
        $pedidosX = filas("SELECT p.codigo, p.fecha_pedido, CONCAT(u.nombre, ' ', u.apellido) AS cliente, u.email, p.telefono_entrega,
                                  p.ciudad_entrega, p.estado, p.metodo_pago, p.subtotal, p.costo_envio, p.total
                           FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id
                           WHERE p.fecha_pedido BETWEEN ? AND ? ORDER BY p.fecha_pedido", $rango);
        $detalleX = filas("SELECT p.codigo, p.fecha_pedido, d.nombre_producto, d.talla, d.color, d.cantidad, d.precio_unitario, d.subtotal, p.estado
                           FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
                           WHERE p.fecha_pedido BETWEEN ? AND ? ORDER BY p.fecha_pedido, d.id", $rango);
        $noCancelados = array_filter($pedidosX, static fn($f) => $f['estado'] !== 'cancelado');
        enviar_xlsx("pedidos_$sufijo", [
            [
                'nombre'   => 'Pedidos',
                'columnas' => [['Código', 'texto', 13], ['Fecha', 'fechahora', 17], ['Cliente', 'texto', 24], ['Correo', 'texto', 28],
                               ['Teléfono', 'texto', 14], ['Ciudad', 'texto', 14], ['Estado', 'texto', 12], ['Método de pago', 'texto', 24],
                               ['Subtotal', 'moneda', 13], ['Envío', 'moneda', 11], ['Total', 'moneda', 13]],
                'filas'    => array_map(static fn($f) => [
                    $f['codigo'], $f['fecha_pedido'], $f['cliente'], $f['email'], $f['telefono_entrega'], $f['ciudad_entrega'],
                    estado_pedido_texto($f['estado']), METODOS_PAGO[$f['metodo_pago']] ?? $f['metodo_pago'],
                    (float) $f['subtotal'], (float) $f['costo_envio'], (float) $f['total'],
                ], $pedidosX),
                'totales'  => ['Total (sin cancelados)', null, null, null, null, null, null, null,
                               array_sum(array_column($noCancelados, 'subtotal')), array_sum(array_column($noCancelados, 'costo_envio')),
                               array_sum(array_column($noCancelados, 'total'))],
            ],
            [
                'nombre'   => 'Detalle de productos',
                'columnas' => [['Pedido', 'texto', 13], ['Fecha', 'fechahora', 17], ['Producto', 'texto', 28], ['Talla', 'texto', 8],
                               ['Color', 'texto', 12], ['Cantidad', 'entero', 10], ['Precio unitario', 'moneda', 15],
                               ['Subtotal', 'moneda', 13], ['Estado del pedido', 'texto', 16]],
                'filas'    => array_map(static fn($f) => [
                    $f['codigo'], $f['fecha_pedido'], $f['nombre_producto'], $f['talla'], $f['color'], (int) $f['cantidad'],
                    (float) $f['precio_unitario'], (float) $f['subtotal'], estado_pedido_texto($f['estado']),
                ], $detalleX),
            ],
        ]);
    }

    // Reporte de ventas: caja por día, top de productos e ingresos por categoría
    enviar_xlsx("reporte_ventas_$sufijo", [
        [
            'nombre'   => 'Caja por día',
            'columnas' => [['Fecha', 'fecha', 12], ['Pedidos', 'entero', 10], ['Total vendido', 'moneda', 15],
                           ['Cobrado (entregados)', 'moneda', 20], ['Efectivo declarado', 'moneda', 18], ['Cambio', 'moneda', 12]],
            'filas'    => array_map(static fn($c) => [$c['dia'], (int) $c['pedidos'], (float) $c['total'], (float) $c['cobrado'],
                                                     (float) $c['efectivo'], (float) $c['cambio']], $caja),
            'totales'  => ['Total', array_sum(array_column($caja, 'pedidos')), array_sum(array_column($caja, 'total')),
                           array_sum(array_column($caja, 'cobrado')), array_sum(array_column($caja, 'efectivo')), array_sum(array_column($caja, 'cambio'))],
        ],
        [
            'nombre'   => 'Top productos',
            'columnas' => [['Producto', 'texto', 30], ['Unidades', 'entero', 10], ['Ingresos', 'moneda', 14], ['Precio medio', 'moneda', 14]],
            'filas'    => array_map(static fn($t) => [$t['nombre_producto'], (int) $t['unidades'], (float) $t['ingresos'], (float) $t['precio_medio']], $top),
        ],
        [
            'nombre'   => 'Por categoría',
            'columnas' => [['Categoría', 'texto', 18], ['Unidades', 'entero', 10], ['Ingresos', 'moneda', 14]],
            'filas'    => array_map(static fn($c) => [$c['nombre'], (int) $c['unidades'], (float) $c['ingresos']], $porCategoria),
            'totales'  => ['Total', array_sum(array_column($porCategoria, 'unidades')), array_sum(array_column($porCategoria, 'ingresos'))],
        ],
        [
            'nombre'   => 'Resumen',
            'columnas' => [['Indicador', 'texto', 30], ['Valor', 'texto', 22]],
            'filas'    => [
                ['Periodo', fecha($desde, false) . ' - ' . fecha($hasta, false)],
                ['Ventas (sin cancelados)', precio($resumen['ventas'])],
                ['Pedidos', (string) (int) $resumen['pedidos']],
                ['Pedidos cancelados', (string) $cancelados],
                ['Ticket promedio', precio($resumen['ticket'])],
                ['Unidades vendidas', (string) $unidades],
                ['Clientes que compraron', (string) (int) $resumen['compradores']],
                ['Generado', date('d/m/Y H:i')],
            ],
        ],
    ]);
}

// Serie diaria completa para el gráfico
$porDia = array_column($caja, 'total', 'dia');
$serie = ['etiquetas' => [], 'valores' => []];
for ($t = strtotime($desde); $t <= strtotime($hasta); $t += 86400) {
    $serie['etiquetas'][] = date('d/m', $t);
    $serie['valores'][] = (float) ($porDia[date('Y-m-d', $t)] ?? 0);
}
$serieCat = ['etiquetas' => array_column($porCategoria, 'nombre'), 'valores' => array_map('floatval', array_column($porCategoria, 'ingresos'))];

$titulo = 'Reportes';
$seccion = 'reportes';
$usar_graficos = true;
require __DIR__ . '/includes/header.php';
?>
<form class="barra-filtros row g-2 align-items-end mb-3 no-imprimir" method="get" action="<?= url('admin/reportes.php') ?>">
    <div class="col-6 col-lg-2"><label class="form-label small" for="desde">Desde</label><input class="form-control form-control-sm" type="date" id="desde" name="desde" value="<?= e($desde) ?>"></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="hasta">Hasta</label><input class="form-control form-control-sm" type="date" id="hasta" name="hasta" value="<?= e($hasta) ?>"></div>
    <div class="col-lg-2"><button class="btn btn-sm btn-fc w-100">Aplicar</button></div>
    <div class="col-lg-6 d-flex flex-wrap gap-2 justify-content-lg-end">
        <a class="btn btn-sm btn-success" href="<?= url('admin/reportes.php', ['desde' => $desde, 'hasta' => $hasta, 'exportar' => 'pedidos']) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Pedidos (Excel)</a>
        <a class="btn btn-sm btn-success" href="<?= url('admin/reportes.php', ['desde' => $desde, 'hasta' => $hasta, 'exportar' => 'ventas']) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Reporte de ventas (Excel)</a>
        <button class="btn btn-sm btn-light" type="button" data-imprimir><i class="bi bi-printer"></i></button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl"><div class="kpi oscuro"><span class="icono"><i class="bi bi-cash-stack"></i></span><div><div class="valor"><?= precio($resumen['ventas']) ?></div><div class="etiqueta-kpi">Ventas del periodo</div></div></div></div>
    <div class="col-6 col-xl"><div class="kpi"><span class="icono"><i class="bi bi-receipt"></i></span><div><div class="valor"><?= (int) $resumen['pedidos'] ?></div><div class="etiqueta-kpi">Pedidos (<?= $cancelados ?> cancelados)</div></div></div></div>
    <div class="col-6 col-xl"><div class="kpi"><span class="icono"><i class="bi bi-graph-up"></i></span><div><div class="valor"><?= precio($resumen['ticket']) ?></div><div class="etiqueta-kpi">Ticket promedio</div></div></div></div>
    <div class="col-6 col-xl"><div class="kpi"><span class="icono"><i class="bi bi-box"></i></span><div><div class="valor"><?= $unidades ?></div><div class="etiqueta-kpi">Unidades vendidas</div></div></div></div>
    <div class="col-12 col-xl"><div class="kpi acento"><span class="icono"><i class="bi bi-people"></i></span><div><div class="valor"><?= (int) $resumen['compradores'] ?></div><div class="etiqueta-kpi">Clientes que compraron</div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-8"><div class="tarjeta-admin h-100"><div class="cabecera"><h2>Ventas por día</h2><span class="small text-muted-fc"><?= fecha($desde, false) ?> – <?= fecha($hasta, false) ?></span></div>
        <div class="cuerpo"><div class="grafico"><canvas data-grafico="barras" data-fuente="g-dias" role="img" aria-label="Ventas por día"></canvas></div></div>
        <script type="application/json" id="g-dias"><?= json_encode($serie, JSON_HEX_TAG) ?></script></div></div>
    <div class="col-xl-4"><div class="tarjeta-admin h-100"><div class="cabecera"><h2>Ingresos por categoría</h2></div>
        <div class="cuerpo"><?php if ($porCategoria): ?><div class="grafico"><canvas data-grafico="dona" data-fuente="g-cat" role="img" aria-label="Ingresos por categoría"></canvas></div><?php else: ?><p class="text-muted-fc">Sin ventas en el periodo.</p><?php endif; ?></div>
        <script type="application/json" id="g-cat"><?= json_encode($serieCat, JSON_HEX_TAG) ?></script></div></div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2><i class="bi bi-safe me-1"></i>Resumen de caja por día</h2></div>
            <div class="table-responsive"><table class="table tabla-admin">
                <thead><tr><th>Fecha</th><th class="text-center">Pedidos</th><th class="text-end">Vendido</th><th class="text-end">Cobrado</th><th class="text-end">Cambio</th></tr></thead>
                <tbody>
                <?php foreach ($caja as $c): ?>
                    <tr><td><?= fecha($c['dia'], false) ?></td><td class="text-center"><?= (int) $c['pedidos'] ?></td><td class="text-end fw-semibold"><?= precio($c['total']) ?></td><td class="text-end"><?= precio($c['cobrado']) ?></td><td class="text-end text-muted-fc"><?= precio($c['cambio']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$caja): ?><tr><td colspan="5" class="text-center text-muted-fc py-4">No hay ventas registradas en el periodo.</td></tr><?php endif; ?>
                </tbody>
                <?php if ($caja): ?><tfoot><tr class="fw-bold"><td>Total</td><td class="text-center"><?= array_sum(array_column($caja, 'pedidos')) ?></td><td class="text-end"><?= precio(array_sum(array_column($caja, 'total'))) ?></td><td class="text-end"><?= precio(array_sum(array_column($caja, 'cobrado'))) ?></td><td></td></tr></tfoot><?php endif; ?>
            </table></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Top 10 productos</h2></div>
            <div class="table-responsive"><table class="table tabla-admin">
                <thead><tr><th>#</th><th>Producto</th><th class="text-center">Uds.</th><th class="text-end">Ingresos</th></tr></thead>
                <tbody>
                <?php foreach ($top as $n => $t): ?>
                    <tr><td class="text-muted-fc"><?= $n + 1 ?></td><td><?= e($t['nombre_producto']) ?><div class="small text-muted-fc">Precio medio <?= precio($t['precio_medio']) ?></div></td><td class="text-center"><?= (int) $t['unidades'] ?></td><td class="text-end fw-semibold"><?= precio($t['ingresos']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$top): ?><tr><td colspan="4" class="text-center text-muted-fc py-4">Sin ventas en el periodo.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
