<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$umbral = umbral_stock_bajo();
$kpi = fila("SELECT
    (SELECT COUNT(*) FROM productos) AS productos,
    (SELECT COUNT(*) FROM productos WHERE estado = 'activo') AS productos_activos,
    (SELECT COUNT(*) FROM usuarios WHERE rol = 'cliente') AS clientes,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente') AS pendientes,
    (SELECT COUNT(*) FROM pedidos WHERE estado = 'entregado') AS entregados,
    (SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE estado <> 'cancelado') AS ventas,
    (SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE estado = 'entregado') AS ventas_entregadas,
    (SELECT COALESCE(AVG(total), 0) FROM pedidos WHERE estado <> 'cancelado') AS ticket,
    (SELECT COALESCE(SUM(stock), 0) FROM variantes_producto WHERE estado = 'activo') AS unidades_stock");

// Ventas de los últimos 14 días (se completan los días sin ventas con 0)
$dias = 14;
$ventasDia = [];
foreach (filas("SELECT DATE(fecha_pedido) AS dia, SUM(total) AS total, COUNT(*) AS pedidos FROM pedidos
                WHERE estado <> 'cancelado' AND fecha_pedido >= CURDATE() - INTERVAL " . ($dias - 1) . " DAY
                GROUP BY DATE(fecha_pedido)") as $f) {
    $ventasDia[$f['dia']] = (float) $f['total'];
}
$serieVentas = ['etiquetas' => [], 'valores' => []];
for ($i = $dias - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $serieVentas['etiquetas'][] = date('d/m', strtotime($d));
    $serieVentas['valores'][] = $ventasDia[$d] ?? 0;
}

$porEstado = ['etiquetas' => [], 'valores' => []];
$conteoEstados = [];
foreach (filas('SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado') as $f) {
    $conteoEstados[$f['estado']] = (int) $f['n'];
}
foreach (ESTADOS_PEDIDO as $clave => [$texto]) {
    $porEstado['etiquetas'][] = $texto;
    $porEstado['valores'][] = $conteoEstados[$clave] ?? 0;
}

$top = filas("SELECT d.nombre_producto AS nombre, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS ingresos
              FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
              WHERE p.estado <> 'cancelado' GROUP BY d.producto_id, d.nombre_producto ORDER BY unidades DESC, ingresos DESC LIMIT 5");

$stockBajo = filas("SELECT v.id, v.sku, v.stock, p.id AS producto_id, p.nombre, p.imagen, t.nombre AS talla, c.nombre AS color
                    FROM variantes_producto v JOIN productos p ON p.id = v.producto_id
                    JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id
                    WHERE v.estado = 'activo' AND p.estado = 'activo' AND v.stock <= ?
                    ORDER BY v.stock, p.nombre LIMIT 8", [$umbral]);

$ultimos = filas("SELECT p.id, p.codigo, p.total, p.estado, p.fecha_pedido, CONCAT(u.nombre, ' ', u.apellido) AS cliente
                  FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id ORDER BY p.fecha_pedido DESC LIMIT 6");

$finMes = resumen_financiero(date('Y-m-01'), date('Y-m-d'));
$fabrica = fila("SELECT (SELECT COUNT(*) FROM materiales WHERE estado = 'activo' AND stock <= stock_minimo) AS bajos,
                        (SELECT COALESCE(SUM(stock * costo_promedio), 0) FROM materiales WHERE estado = 'activo') AS valor,
                        (SELECT COUNT(*) FROM ordenes_produccion WHERE estado IN ('planificada', 'en_proceso')) AS activas");

$titulo = 'Dashboard';
$seccion = 'dashboard';
$usar_graficos = true;
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="kpi oscuro"><span class="icono"><i class="bi bi-cash-stack"></i></span><div><div class="valor"><?= precio($kpi['ventas']) ?></div><div class="etiqueta-kpi">Ventas totales (sin cancelados)</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi acento"><span class="icono"><i class="bi bi-hourglass-split"></i></span><div><div class="valor"><?= (int) $kpi['pendientes'] ?></div><div class="etiqueta-kpi">Pedidos pendientes</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-bag-check"></i></span><div><div class="valor"><?= (int) $kpi['entregados'] ?></div><div class="etiqueta-kpi">Pedidos entregados · <?= precio($kpi['ventas_entregadas']) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-people"></i></span><div><div class="valor"><?= (int) $kpi['clientes'] ?></div><div class="etiqueta-kpi">Clientes registrados</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-tags"></i></span><div><div class="valor"><?= (int) $kpi['productos'] ?></div><div class="etiqueta-kpi">Productos totales</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-eye"></i></span><div><div class="valor"><?= (int) $kpi['productos_activos'] ?></div><div class="etiqueta-kpi">Productos activos</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-receipt"></i></span><div><div class="valor"><?= precio($kpi['ticket']) ?></div><div class="etiqueta-kpi">Ticket promedio</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="kpi"><span class="icono"><i class="bi bi-boxes"></i></span><div><div class="valor"><?= number_format((int) $kpi['unidades_stock'], 0, ',', '.') ?></div><div class="etiqueta-kpi">Unidades en inventario</div></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3"><a class="text-reset text-decoration-none" href="<?= url('admin/finanzas.php') ?>"><div class="kpi<?= $finMes['utilidad'] >= 0 ? ' acento' : '' ?>"><span class="icono"><i class="bi bi-graph-up-arrow"></i></span><div><div class="valor <?= $finMes['utilidad'] < 0 ? 'cifra-negativa' : '' ?>"><?= precio($finMes['utilidad']) ?></div><div class="etiqueta-kpi">Utilidad estimada del mes · margen <?= porcentaje($finMes['margen_neto'], 0) ?></div></div></div></a></div>
    <div class="col-md-6 col-xl-3"><a class="text-reset text-decoration-none" href="<?= url('admin/gastos.php') ?>"><div class="kpi"><span class="icono"><i class="bi bi-wallet2"></i></span><div><div class="valor"><?= precio($finMes['compras'] + $finMes['gastos']) ?></div><div class="etiqueta-kpi">Compras + gastos del mes</div></div></div></a></div>
    <div class="col-md-6 col-xl-3"><a class="text-reset text-decoration-none" href="<?= url('admin/materiales.php', ['bajo' => 1]) ?>"><div class="kpi"><span class="icono"><i class="bi bi-layers"></i></span><div><div class="valor"><?= precio($fabrica['valor']) ?></div><div class="etiqueta-kpi">Materias primas · <?= (int) $fabrica['bajos'] ?> bajo el mínimo</div></div></div></a></div>
    <div class="col-md-6 col-xl-3"><a class="text-reset text-decoration-none" href="<?= url('admin/produccion.php') ?>"><div class="kpi"><span class="icono"><i class="bi bi-gear-wide-connected"></i></span><div><div class="valor"><?= (int) $fabrica['activas'] ?></div><div class="etiqueta-kpi">Órdenes de producción activas</div></div></div></a></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Ventas de los últimos <?= $dias ?> días</h2><a class="small" href="<?= url('admin/reportes.php') ?>">Ver reportes</a></div>
            <div class="cuerpo"><div class="grafico"><canvas data-grafico="linea" data-fuente="g-ventas" aria-label="Gráfico de ventas diarias" role="img"></canvas></div></div>
            <script type="application/json" id="g-ventas"><?= json_encode($serieVentas, JSON_HEX_TAG) ?></script>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="tarjeta-admin h-100">
            <div class="cabecera"><h2>Pedidos por estado</h2></div>
            <div class="cuerpo"><div class="grafico"><canvas data-grafico="dona" data-fuente="g-estados" aria-label="Gráfico de pedidos por estado" role="img"></canvas></div></div>
            <script type="application/json" id="g-estados"><?= json_encode($porEstado, JSON_HEX_TAG) ?></script>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2>Últimos pedidos</h2><a class="small" href="<?= url('admin/pedidos.php') ?>">Ver todos</a></div>
            <div class="table-responsive">
                <table class="table tabla-admin table-hover">
                    <thead><tr><th>Pedido</th><th>Cliente</th><th>Fecha</th><th>Estado</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($ultimos as $p): ?>
                        <tr>
                            <td><a class="fw-bold" href="<?= url('admin/pedido_detalle.php', ['id' => $p['id']]) ?>"><?= e($p['codigo']) ?></a></td>
                            <td><?= e($p['cliente']) ?></td>
                            <td class="small text-muted-fc text-nowrap"><?= fecha($p['fecha_pedido']) ?></td>
                            <td><?= estado_pedido_badge($p['estado']) ?></td>
                            <td class="text-end fw-semibold"><?= precio($p['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ultimos): ?><tr><td colspan="5" class="text-center text-muted-fc py-4">Aún no hay pedidos.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Productos más vendidos</h2></div>
            <div class="cuerpo">
                <?php if ($top): $maxU = max(array_column($top, 'unidades')); ?>
                    <?php foreach ($top as $t): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1"><strong><?= e($t['nombre']) ?></strong><span><?= (int) $t['unidades'] ?> uds · <?= precio($t['ingresos']) ?></span></div>
                            <div class="progress" style="height:8px"><div class="progress-bar bg-dark" style="width: <?= round($t['unidades'] / $maxU * 100) ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?><p class="text-muted-fc mb-0">Sin ventas registradas.</p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2><i class="bi bi-exclamation-triangle text-warning me-1"></i>Poco inventario (≤ <?= $umbral ?>)</h2><a class="small" href="<?= url('admin/inventario.php', ['bajo' => 1]) ?>">Gestionar</a></div>
            <?php if ($stockBajo): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($stockBajo as $s): ?>
                        <li class="list-group-item d-flex align-items-center gap-3 px-3">
                            <img class="miniatura" src="<?= imagen_url($s['imagen']) ?>" alt="">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate"><?= e($s['nombre']) ?></div>
                                <div class="small text-muted-fc"><?= e($s['talla']) ?> · <?= e($s['color']) ?> · <?= e($s['sku']) ?></div>
                            </div>
                            <span class="badge <?= (int) $s['stock'] === 0 ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= (int) $s['stock'] === 0 ? 'Agotado' : $s['stock'] . ' uds' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="cuerpo text-muted-fc">Todo el inventario está por encima del umbral. 👌</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
