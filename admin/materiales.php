<?php
require __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$tipos = filas('SELECT id, nombre, icono FROM tipos_material ORDER BY id');
$tipo = get_int('tipo');
$q = get_texto('q', 80);
$bajo = get_int('bajo') === 1;
$verInactivos = get_int('inactivos') === 1;

$where = [$verInactivos ? '1 = 1' : "m.estado = 'activo'"];
$params = [];
if ($tipo > 0) { $where[] = 'm.tipo_id = ?'; $params[] = $tipo; }
if ($q !== '') {
    $where[] = '(m.nombre LIKE ? OR m.codigo LIKE ?)';
    array_push($params, '%' . escapar_like($q) . '%', '%' . escapar_like($q) . '%');
}
if ($bajo) { $where[] = 'm.stock <= m.stock_minimo'; }
$sqlWhere = implode(' AND ', $where);

$materiales = filas("SELECT m.*, t.nombre AS tipo, t.icono, p.nombre AS proveedor, m.stock * m.costo_promedio AS valor,
                            (SELECT COUNT(*) FROM ficha_materiales fm WHERE fm.material_id = m.id) AS fichas
                     FROM materiales m JOIN tipos_material t ON t.id = m.tipo_id LEFT JOIN proveedores p ON p.id = m.proveedor_preferido_id
                     WHERE $sqlWhere ORDER BY (m.stock <= m.stock_minimo) DESC, t.id, m.nombre", $params);
$resumenTipos = inventario_materiales_resumen();
$valorTotal = array_sum(array_map('floatval', array_column($resumenTipos, 'valor')));
$bajos = (int) valor("SELECT COUNT(*) FROM materiales WHERE estado = 'activo' AND stock <= stock_minimo");

$titulo = 'Materias primas';
$seccion = 'materiales';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/compra_crear.php') . '"><i class="bi bi-cart-plus me-1"></i>Registrar compra</a>'
    . '<a class="btn btn-fc" href="' . url('admin/material_editar.php') . '"><i class="bi bi-plus-lg me-1"></i>Nuevo material</a>';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="kpi oscuro"><span class="icono"><i class="bi bi-safe2"></i></span><div><div class="valor"><?= precio($valorTotal) ?></div><div class="etiqueta-kpi">Valor del inventario de materiales (costo promedio)</div></div></div></div>
    <div class="col-6 col-md-4"><div class="kpi"><span class="icono"><i class="bi bi-layers"></i></span><div><div class="valor"><?= array_sum(array_column($resumenTipos, 'materiales')) ?></div><div class="etiqueta-kpi">Materiales activos</div></div></div></div>
    <div class="col-6 col-md-4"><a class="text-reset text-decoration-none" href="<?= url('admin/materiales.php', ['bajo' => 1]) ?>"><div class="kpi acento"><span class="icono"><i class="bi bi-exclamation-triangle"></i></span><div><div class="valor"><?= $bajos ?></div><div class="etiqueta-kpi">En o bajo el stock mínimo</div></div></div></a></div>
</div>

<div class="row g-2 mb-3">
    <?php foreach ($resumenTipos as $t): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <a class="tarjeta-admin d-block p-3 text-reset text-decoration-none h-100<?= $tipo === (int) $t['id'] ? ' border-dark' : '' ?>" href="<?= url('admin/materiales.php', ['tipo' => $t['id']]) ?>">
                <div class="d-flex justify-content-between"><i class="bi <?= e($t['icono']) ?> fs-4"></i><?php if ((int) $t['bajos'] > 0): ?><span class="badge text-bg-warning"><?= (int) $t['bajos'] ?> bajo</span><?php endif; ?></div>
                <div class="fw-bold mt-2"><?= e($t['nombre']) ?></div>
                <div class="small text-muted-fc"><?= (int) $t['materiales'] ?> ítems · <?= precio($t['valor']) ?></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/materiales.php') ?>">
    <div class="col-md-4"><label class="form-label small" for="q">Buscar</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre o código"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="tipo">Tipo</label>
        <select class="form-select form-select-sm" id="tipo" name="tipo"><option value="">Todos</option>
            <?php foreach ($tipos as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $tipo === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3 d-flex flex-column gap-1 pb-1">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="bajo" name="bajo" value="1" <?= $bajo ? 'checked' : '' ?>><label class="form-check-label small fw-semibold" for="bajo">Sólo bajo mínimo</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="inactivos" name="inactivos" value="1" <?= $verInactivos ? 'checked' : '' ?>><label class="form-check-label small" for="inactivos">Incluir inactivos</label></div>
    </div>
    <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-fc flex-fill">Filtrar</button><a class="btn btn-sm btn-light" href="<?= url('admin/materiales.php') ?>" aria-label="Limpiar"><i class="bi bi-x-lg"></i></a></div>
</form>

<div class="tarjeta-admin">
    <div class="cabecera"><h2>¿Qué materiales tenemos en inventario? (<?= count($materiales) ?>)</h2>
        <a class="small" href="<?= url('admin/finanzas.php', ['exportar' => 'excel']) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Exportar con finanzas</a></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Material</th><th>Tipo</th><th class="text-end">Stock</th><th class="text-end">Mínimo</th><th class="text-end">Costo promedio</th><th class="text-end">Valor</th><th>Proveedor habitual</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($materiales as $m): $esBajo = (float) $m['stock'] <= (float) $m['stock_minimo']; ?>
                <tr class="<?= $m['estado'] !== 'activo' ? 'opacity-50' : '' ?>">
                    <td><a class="fw-bold text-decoration-none" href="<?= url('admin/material_editar.php', ['id' => $m['id']]) ?>"><?= e($m['nombre']) ?></a>
                        <div class="small text-muted-fc"><?= e($m['codigo']) ?> · usado en <?= (int) $m['fichas'] ?> ficha(s)</div></td>
                    <td class="small"><i class="bi <?= e($m['icono']) ?> me-1"></i><?= e($m['tipo']) ?></td>
                    <td class="text-end"><span class="badge fs-6 <?= (float) $m['stock'] <= 0 ? 'text-bg-danger' : ($esBajo ? 'text-bg-warning' : 'text-bg-light') ?>"><?= cantidad_fmt($m['stock'], $m['unidad']) ?></span></td>
                    <td class="text-end small text-muted-fc"><?= cantidad_fmt($m['stock_minimo'], $m['unidad']) ?></td>
                    <td class="text-end"><?= precio($m['costo_promedio']) ?> <span class="small text-muted-fc">/ <?= e(UNIDADES_MATERIAL[$m['unidad']]) ?></span></td>
                    <td class="text-end fw-semibold"><?= precio($m['valor']) ?></td>
                    <td class="small"><?= e($m['proveedor'] ?? '—') ?></td>
                    <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= url('admin/material_editar.php', ['id' => $m['id']]) ?>" title="Editar, ajustar y ver kardex"><i class="bi bi-pencil"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$materiales): ?><tr><td colspan="8" class="text-center text-muted-fc py-5">No hay materiales con esos filtros.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($materiales): ?><tfoot><tr><td colspan="5" class="text-end">Valor de los materiales listados</td><td class="text-end"><?= precio(array_sum(array_map('floatval', array_column($materiales, 'valor')))) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
