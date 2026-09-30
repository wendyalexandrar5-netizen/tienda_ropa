<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$categorias = filas('SELECT c.*, (SELECT COUNT(*) FROM gastos g WHERE g.categoria_id = c.id) AS usos FROM categorias_gasto c ORDER BY FIELD(c.grupo, \'operativo\', \'mantenimiento\', \'otro\'), c.nombre');
$proveedores = filas("SELECT id, nombre FROM proveedores WHERE estado = 'activo' ORDER BY nombre");
$vacio = ['categoria_id' => 0, 'descripcion' => '', 'monto' => '', 'fecha' => date('Y-m-d'), 'metodo_pago' => 'transferencia', 'proveedor_id' => 0, 'comprobante' => ''];
$d = $vacio;
$editar = null;
$errores = [];
$volver = array_intersect_key($_GET, array_flip(['periodo', 'desde', 'hasta', 'categoria', 'grupo']));

if (es_post()) {
    $accion = post_texto('accion', 20);
    $id = post_int('gasto_id');
    $gasto = $id ? fila('SELECT * FROM gastos WHERE id = ?', [$id]) : null;
    if ($accion === 'guardar') {
        $d = ['categoria_id' => post_int('categoria_id'), 'descripcion' => post_texto('descripcion', 160), 'monto' => post_texto('monto', 15),
              'fecha' => post_texto('fecha', 10), 'metodo_pago' => post_texto('metodo_pago', 15), 'proveedor_id' => post_int('proveedor_id'),
              'comprobante' => post_texto('comprobante', 60)];
        $monto = a_decimal($d['monto']);
        $v = (new Validador())->requerido('descripcion', $d['descripcion'], 'La descripción')->longitud('descripcion', $d['descripcion'], 3, 160, 'La descripción')
            ->enLista('metodo_pago', $d['metodo_pago'], array_keys(METODOS_PAGO_GASTO), 'El método de pago');
        if ($monto === null || $monto <= 0 || $monto > 1000000000) {
            $v->agregar('monto', 'Ingresa un monto mayor que cero.');
        }
        if (!es_fecha_valida($d['fecha']) || $d['fecha'] > date('Y-m-d')) {
            $v->agregar('fecha', 'La fecha no es válida (no puede ser futura).');
        }
        if (!valor('SELECT id FROM categorias_gasto WHERE id = ?', [$d['categoria_id']])) {
            $v->agregar('categoria_id', 'Selecciona una categoría.');
        }
        if ($d['proveedor_id'] && !valor('SELECT id FROM proveedores WHERE id = ?', [$d['proveedor_id']])) {
            $v->agregar('proveedor_id', 'Proveedor inválido.');
        }
        if ($v->ok()) {
            $campos = [$d['categoria_id'], $d['descripcion'], $monto, $d['fecha'], $d['metodo_pago'], $d['proveedor_id'] ?: null, $d['comprobante'] ?: null];
            if ($gasto) {
                consulta('UPDATE gastos SET categoria_id = ?, descripcion = ?, monto = ?, fecha = ?, metodo_pago = ?, proveedor_id = ?, comprobante = ? WHERE id = ?', array_merge($campos, [$id]));
                flash('success', 'Gasto actualizado.');
            } else {
                consulta('INSERT INTO gastos (categoria_id, descripcion, monto, fecha, metodo_pago, proveedor_id, comprobante, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($campos, [(int) $admin['id']]));
                flash('success', 'Gasto de ' . precio($monto) . ' registrado.');
            }
            redirigir('admin/gastos.php', $volver);
        }
        $errores = $v->errores();
        $editar = $gasto;
        flash('danger', 'Revisa los campos marcados.');
    } elseif ($gasto && $accion === 'eliminar') {
        consulta('DELETE FROM gastos WHERE id = ?', [$id]);
        flash('success', "Gasto «{$gasto['descripcion']}» eliminado.");
        redirigir('admin/gastos.php', $volver);
    } elseif ($accion === 'categoria') {
        $nombre = post_texto('nombre', 60);
        $grupo = post_texto('grupo', 15);
        if (mb_strlen($nombre) < 3 || !isset(GRUPOS_GASTO[$grupo]) || valor('SELECT id FROM categorias_gasto WHERE nombre = ?', [$nombre])) {
            flash('danger', 'Escribe una categoría nueva (mín. 3 letras) y elige su grupo.');
        } else {
            consulta('INSERT INTO categorias_gasto (nombre, grupo) VALUES (?, ?)', [$nombre, $grupo]);
            flash('success', "Categoría «{$nombre}» creada.");
        }
        redirigir('admin/gastos.php', $volver);
    } elseif ($accion === 'eliminar_categoria') {
        $cid = post_int('categoria_id');
        if ((int) valor('SELECT COUNT(*) FROM gastos WHERE categoria_id = ?', [$cid]) > 0) {
            flash('warning', 'La categoría tiene gastos registrados y no puede eliminarse.');
        } else {
            consulta('DELETE FROM categorias_gasto WHERE id = ?', [$cid]);
            flash('success', 'Categoría eliminada.');
        }
        redirigir('admin/gastos.php', $volver);
    } else {
        flash('danger', 'Acción no válida.');
        redirigir('admin/gastos.php', $volver);
    }
} elseif (get_int('editar')) {
    $editar = fila('SELECT * FROM gastos WHERE id = ?', [get_int('editar')]);
    if ($editar) {
        $d = array_merge($vacio, array_map(static fn($x) => $x ?? '', $editar));
        $d['monto'] = (string) (float) $editar['monto'];
    }
}

$periodo = periodo_seleccionado('mes');
$catFiltro = get_int('categoria');
$grupoFiltro = get_texto('grupo', 15);
$where = ['g.fecha BETWEEN ? AND ?'];
$params = [$periodo['desde'], $periodo['hasta']];
if ($catFiltro) { $where[] = 'g.categoria_id = ?'; $params[] = $catFiltro; }
if (isset(GRUPOS_GASTO[$grupoFiltro])) { $where[] = 'c.grupo = ?'; $params[] = $grupoFiltro; }
$sqlWhere = implode(' AND ', $where);
$gastos = filas("SELECT g.*, c.nombre AS categoria, c.grupo, p.nombre AS proveedor, CONCAT(u.nombre, ' ', u.apellido) AS usuario
                 FROM gastos g JOIN categorias_gasto c ON c.id = g.categoria_id LEFT JOIN proveedores p ON p.id = g.proveedor_id
                 LEFT JOIN usuarios u ON u.id = g.usuario_id WHERE $sqlWhere ORDER BY g.fecha DESC, g.id DESC LIMIT 300", $params);
$porGrupo = array_fill_keys(array_keys(GRUPOS_GASTO), 0.0);
foreach (filas("SELECT c.grupo, SUM(g.monto) AS total FROM gastos g JOIN categorias_gasto c ON c.id = g.categoria_id WHERE $sqlWhere GROUP BY c.grupo", $params) as $f) {
    $porGrupo[$f['grupo']] = (float) $f['total'];
}

$titulo = 'Gastos';
$seccion = 'gastos';
require __DIR__ . '/includes/header.php';
?>
<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/gastos.php') ?>">
    <div class="col-6 col-lg-2"><label class="form-label small" for="periodo">Periodo</label>
        <select class="form-select form-select-sm" id="periodo" name="periodo">
            <?php foreach (['mes' => 'Este mes', 'mes_anterior' => 'Mes anterior', '30d' => 'Últimos 30 días', 'anio' => 'Este año', 'rango' => 'Rango…'] as $k => $t): ?>
                <option value="<?= $k ?>" <?= $periodo['clave'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="desde">Desde</label><input class="form-control form-control-sm" type="date" id="desde" name="desde" value="<?= e($periodo['desde']) ?>"></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="hasta">Hasta</label><input class="form-control form-control-sm" type="date" id="hasta" name="hasta" value="<?= e($periodo['hasta']) ?>"></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="grupo">Grupo</label>
        <select class="form-select form-select-sm" id="grupo" name="grupo"><option value="">Todos</option>
            <?php foreach (GRUPOS_GASTO as $k => $t): ?><option value="<?= $k ?>" <?= $grupoFiltro === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-lg-2"><label class="form-label small" for="categoria">Categoría</label>
        <select class="form-select form-select-sm" id="categoria" name="categoria"><option value="">Todas</option>
            <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $catFiltro === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-lg-2"><button class="btn btn-sm btn-fc w-100">Filtrar</button></div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="kpi oscuro"><span class="icono"><i class="bi bi-wallet2"></i></span><div><div class="valor"><?= precio(array_sum($porGrupo)) ?></div><div class="etiqueta-kpi">Total gastos · <?= e($periodo['etiqueta']) ?></div></div></div></div>
    <?php foreach (GRUPOS_GASTO as $k => $t): ?>
        <div class="col-6 col-xl-3"><a class="text-reset text-decoration-none" href="<?= e(url_actual(['grupo' => $k, 'categoria' => null])) ?>"><div class="kpi<?= $grupoFiltro === $k ? ' acento' : '' ?>"><span class="icono"><i class="bi <?= ['operativo' => 'bi-shop', 'mantenimiento' => 'bi-tools', 'otro' => 'bi-three-dots'][$k] ?>"></i></span><div><div class="valor"><?= precio($porGrupo[$k]) ?></div><div class="etiqueta-kpi"><?= e($t) ?></div></div></div></a></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="tarjeta-admin">
            <div class="cabecera"><h2><?= count($gastos) ?> gasto(s)</h2><a class="small" href="<?= url('admin/finanzas.php', ['periodo' => $periodo['clave'], 'desde' => $periodo['desde'], 'hasta' => $periodo['hasta']]) ?>">Ver impacto en la utilidad <i class="bi bi-arrow-right"></i></a></div>
            <div class="table-responsive">
                <table class="table tabla-admin table-hover">
                    <thead><tr><th>Fecha</th><th>Descripción</th><th>Categoría</th><th>Pago</th><th class="text-end">Monto</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($gastos as $g): ?>
                        <tr>
                            <td class="small text-nowrap"><?= fecha($g['fecha'], false) ?></td>
                            <td><?= e($g['descripcion']) ?><div class="small text-muted-fc"><?= e($g['proveedor'] ?? '') ?><?= $g['comprobante'] ? ' · Comp. ' . e($g['comprobante']) : '' ?></div></td>
                            <td class="small"><?= e($g['categoria']) ?><div class="text-muted-fc"><?= e(GRUPOS_GASTO[$g['grupo']]) ?></div></td>
                            <td class="small"><?= e(METODOS_PAGO_GASTO[$g['metodo_pago']] ?? '') ?></td>
                            <td class="text-end fw-semibold"><?= precio($g['monto']) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-light" href="<?= e(url_actual(['editar' => $g['id']])) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                                <form class="d-inline" method="post" action="<?= e(url_actual(['editar' => null])) ?>" data-confirmar="¿Eliminar el gasto «<?= e($g['descripcion']) ?>»?">
                                    <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
                                    <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$gastos): ?><tr><td colspan="6" class="text-center text-muted-fc py-5">No hay gastos en el periodo.</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($gastos): ?><tfoot><tr><td colspan="4" class="text-end">Total</td><td class="text-end"><?= precio(array_sum(array_map('floatval', array_column($gastos, 'monto')))) ?></td><td></td></tr></tfoot><?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <form method="post" action="<?= e(url_actual(['editar' => null])) ?>" class="tarjeta-admin needs-validation mb-3" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="gasto_id" value="<?= (int) ($editar['id'] ?? 0) ?>">
            <div class="cabecera"><h2><?= $editar ? 'Editar gasto' : 'Registrar gasto' ?></h2><?php if ($editar): ?><a class="small" href="<?= e(url_actual(['editar' => null])) ?>">Cancelar</a><?php endif; ?></div>
            <div class="cuerpo row g-2">
                <div class="col-12"><label class="form-label small" for="categoria_id">Categoría *</label>
                    <select class="form-select<?= clase_invalida($errores, 'categoria_id') ?>" id="categoria_id" name="categoria_id" required><option value="">Selecciona…</option>
                        <?php foreach (GRUPOS_GASTO as $gk => $gt): ?><optgroup label="<?= e($gt) ?>">
                            <?php foreach ($categorias as $c): if ($c['grupo'] !== $gk) continue; ?><option value="<?= (int) $c['id'] ?>" <?= (int) $d['categoria_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
                        </optgroup><?php endforeach; ?></select><?= error_campo($errores, 'categoria_id') ?></div>
                <div class="col-12"><label class="form-label small" for="descripcion">Descripción *</label>
                    <input class="form-control<?= clase_invalida($errores, 'descripcion') ?>" id="descripcion" name="descripcion" required minlength="3" maxlength="160" value="<?= e($d['descripcion']) ?>" placeholder="Ej.: mantenimiento fileteadora"><?= error_campo($errores, 'descripcion') ?></div>
                <div class="col-6"><label class="form-label small" for="monto">Monto ($) *</label>
                    <input class="form-control<?= clase_invalida($errores, 'monto') ?>" id="monto" name="monto" required inputmode="decimal" value="<?= e($d['monto']) ?>"><?= error_campo($errores, 'monto') ?></div>
                <div class="col-6"><label class="form-label small" for="fecha">Fecha *</label>
                    <input class="form-control<?= clase_invalida($errores, 'fecha') ?>" type="date" id="fecha" name="fecha" required max="<?= date('Y-m-d') ?>" value="<?= e($d['fecha']) ?>"><?= error_campo($errores, 'fecha') ?></div>
                <div class="col-6"><label class="form-label small" for="metodo_pago">Pagado con</label>
                    <select class="form-select" id="metodo_pago" name="metodo_pago"><?php foreach (METODOS_PAGO_GASTO as $k => $t): ?><option value="<?= $k ?>" <?= $d['metodo_pago'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label small" for="comprobante">Comprobante</label>
                    <input class="form-control" id="comprobante" name="comprobante" maxlength="60" value="<?= e($d['comprobante']) ?>" placeholder="N.º factura/recibo"></div>
                <div class="col-12"><label class="form-label small" for="proveedor_id">Proveedor (opcional)</label>
                    <select class="form-select" id="proveedor_id" name="proveedor_id"><option value="">—</option>
                        <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $d['proveedor_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 mt-2"><button class="btn btn-fc w-100"><i class="bi bi-check2 me-1"></i><?= $editar ? 'Guardar cambios' : 'Registrar gasto' ?></button></div>
                <div class="col-12"><div class="form-text">Las compras de telas y materiales se registran en <a href="<?= url('admin/compras.php') ?>">Compras</a>, no aquí. La mano de obra de confección ya está en el costo de cada prenda.</div></div>
            </div>
        </form>
        <div class="tarjeta-admin">
            <div class="cabecera"><h2>Categorías de gasto</h2></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($categorias as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3 small">
                        <span><?= e($c['nombre']) ?> <span class="text-muted-fc">· <?= e(GRUPOS_GASTO[$c['grupo']]) ?></span></span>
                        <?php if ((int) $c['usos'] === 0): ?>
                            <form method="post" action="<?= e(url_actual(['editar' => null])) ?>" data-confirmar="¿Eliminar la categoría <?= e($c['nombre']) ?>?"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar_categoria"><input type="hidden" name="categoria_id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0" aria-label="Eliminar"><i class="bi bi-trash"></i></button></form>
                        <?php else: ?><span class="text-muted-fc"><?= (int) $c['usos'] ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?= e(url_actual(['editar' => null])) ?>" class="cuerpo d-flex gap-2">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="categoria">
                <input class="form-control form-control-sm" name="nombre" maxlength="60" placeholder="Nueva categoría" required aria-label="Nueva categoría">
                <select class="form-select form-select-sm" name="grupo" style="max-width:140px" aria-label="Grupo"><?php foreach (GRUPOS_GASTO as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-fc" aria-label="Agregar categoría"><i class="bi bi-plus-lg"></i></button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
