<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$_GET['periodo'] = get_texto('periodo', 15) ?: 'anio';
$periodo = periodo_seleccionado('anio');
$proveedor = get_int('proveedor');
$estado = get_texto('estado', 10);

$where = ['c.fecha BETWEEN ? AND ?'];
$params = [$periodo['desde'], $periodo['hasta']];
if ($proveedor > 0) { $where[] = 'c.proveedor_id = ?'; $params[] = $proveedor; }
if (in_array($estado, ['recibida', 'anulada'], true)) { $where[] = 'c.estado = ?'; $params[] = $estado; }
$sqlWhere = implode(' AND ', $where);

$pag = paginar((int) valor("SELECT COUNT(*) FROM compras_material c WHERE $sqlWhere", $params), 20, max(1, get_int('pagina', 1)));
$compras = filas("SELECT c.*, p.nombre AS proveedor, (SELECT COUNT(*) FROM compra_detalle d WHERE d.compra_id = c.id) AS lineas,
                         (SELECT GROUP_CONCAT(DISTINCT t.nombre ORDER BY t.id SEPARATOR ', ') FROM compra_detalle d
                          JOIN materiales m ON m.id = d.material_id JOIN tipos_material t ON t.id = m.tipo_id WHERE d.compra_id = c.id) AS tipos
                  FROM compras_material c JOIN proveedores p ON p.id = c.proveedor_id
                  WHERE $sqlWhere ORDER BY c.fecha DESC, c.id DESC LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);
$total = (float) valor("SELECT COALESCE(SUM(total), 0) FROM compras_material c WHERE $sqlWhere AND c.estado = 'recibida'", $params);
$porTipo = filas("SELECT t.nombre, t.icono, SUM(d.subtotal) AS total FROM compra_detalle d JOIN compras_material c ON c.id = d.compra_id
                  JOIN materiales m ON m.id = d.material_id JOIN tipos_material t ON t.id = m.tipo_id
                  WHERE $sqlWhere AND c.estado = 'recibida' GROUP BY t.id, t.nombre, t.icono ORDER BY total DESC", $params);
$proveedores = filas('SELECT id, nombre FROM proveedores ORDER BY nombre');

$titulo = 'Compras de materiales';
$seccion = 'compras';
$acciones_cabecera = '<a class="btn btn-fc" href="' . url('admin/compra_crear.php') . '"><i class="bi bi-plus-lg me-1"></i>Registrar compra</a>';
require __DIR__ . '/includes/header.php';
?>
<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/compras.php') ?>">
    <div class="col-6 col-lg-2"><label class="form-label small" for="periodo">Periodo</label>
        <select class="form-select form-select-sm" id="periodo" name="periodo">
            <?php foreach (['mes' => 'Este mes', 'mes_anterior' => 'Mes anterior', '30d' => 'Últimos 30 días', 'anio' => 'Este año', 'rango' => 'Rango…'] as $k => $t): ?>
                <option value="<?= $k ?>" <?= $periodo['clave'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="desde">Desde</label><input class="form-control form-control-sm" type="date" id="desde" name="desde" value="<?= e($periodo['desde']) ?>"></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="hasta">Hasta</label><input class="form-control form-control-sm" type="date" id="hasta" name="hasta" value="<?= e($periodo['hasta']) ?>"></div>
    <div class="col-6 col-lg-3"><label class="form-label small" for="proveedor">Proveedor</label>
        <select class="form-select form-select-sm" id="proveedor" name="proveedor"><option value="">Todos</option>
            <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $proveedor === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-lg-1"><label class="form-label small" for="estado">Estado</label>
        <select class="form-select form-select-sm" id="estado" name="estado"><option value="">Todos</option><option value="recibida" <?= $estado === 'recibida' ? 'selected' : '' ?>>Recibidas</option><option value="anulada" <?= $estado === 'anulada' ? 'selected' : '' ?>>Anuladas</option></select></div>
    <div class="col-6 col-lg-2"><button class="btn btn-sm btn-fc w-100" data-periodo-rango>Filtrar</button></div>
</form>

<div class="row g-3 mb-3">
    <div class="col-lg-4"><div class="kpi oscuro"><span class="icono"><i class="bi bi-cart-check"></i></span><div><div class="valor"><?= precio($total) ?></div><div class="etiqueta-kpi">Gastado en materias primas · <?= e($periodo['etiqueta']) ?></div></div></div></div>
    <div class="col-lg-8"><div class="tarjeta-admin h-100 p-3"><div class="d-flex flex-wrap gap-3">
        <?php foreach ($porTipo as $t): ?><div><div class="small text-muted-fc"><i class="bi <?= e($t['icono']) ?> me-1"></i><?= e($t['nombre']) ?></div><div class="fw-bold"><?= precio($t['total']) ?></div></div><?php endforeach; ?>
        <?php if (!$porTipo): ?><span class="text-muted-fc small">Sin compras en el periodo.</span><?php endif; ?>
    </div></div></div>
</div>

<div class="tarjeta-admin">
    <div class="cabecera"><h2><?= $pag['total'] ?> compra(s)</h2></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Compra</th><th>Fecha</th><th>Proveedor</th><th>Contenido</th><th>Estado</th><th class="text-end">Total</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($compras as $c): ?>
                <tr class="<?= $c['estado'] === 'anulada' ? 'opacity-50' : '' ?>">
                    <td class="fw-bold"><a href="<?= url('admin/compra_detalle.php', ['id' => $c['id']]) ?>"><?= e($c['codigo']) ?></a><div class="small text-muted-fc">Factura <?= e($c['numero_factura'] ?? '—') ?></div></td>
                    <td class="small text-nowrap"><?= fecha($c['fecha'], false) ?></td>
                    <td><?= e($c['proveedor']) ?></td>
                    <td class="small"><?= (int) $c['lineas'] ?> material(es) · <?= e($c['tipos']) ?></td>
                    <td><?= $c['estado'] === 'recibida' ? '<span class="badge text-bg-success">Recibida</span>' : '<span class="badge text-bg-secondary">Anulada</span>' ?></td>
                    <td class="text-end fw-semibold"><?= precio($c['total']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-light" href="<?= url('admin/compra_detalle.php', ['id' => $c['id']]) ?>"><i class="bi bi-chevron-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$compras): ?><tr><td colspan="7" class="text-center text-muted-fc py-5">No hay compras en el periodo.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require ROOT_PATH . '/includes/partials/paginacion.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
