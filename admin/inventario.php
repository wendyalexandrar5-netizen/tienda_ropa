<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

if (es_post()) {
    $vid = post_int('variante_id');
    try {
        $nuevo = ajustar_stock($vid, post_texto('tipo', 10), post_int('cantidad', -1), post_texto('motivo', 255), (int) $admin['id']);
        $sku = (string) valor('SELECT sku FROM variantes_producto WHERE id = ?', [$vid]);
        flash('success', "Stock de $sku actualizado: ahora hay $nuevo unidad(es).");
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
    }
    redirigir('admin/inventario.php', array_intersect_key($_GET, array_flip(['q', 'categoria', 'bajo', 'producto', 'pagina'])));
}

$umbral = umbral_stock_bajo();
$q = get_texto('q', 80);
$cat = get_int('categoria');
$bajo = get_int('bajo') === 1;
$productoId = get_int('producto');

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(p.nombre LIKE ? OR v.sku LIKE ?)';
    array_push($params, '%' . escapar_like($q) . '%', '%' . escapar_like($q) . '%');
}
if ($cat > 0) { $where[] = 'p.categoria_id = ?'; $params[] = $cat; }
if ($productoId > 0) { $where[] = 'p.id = ?'; $params[] = $productoId; }
if ($bajo) { $where[] = 'v.stock <= ?'; $params[] = $umbral; }
$sqlWhere = implode(' AND ', $where);

$base = "FROM variantes_producto v JOIN productos p ON p.id = v.producto_id JOIN categorias ca ON ca.id = p.categoria_id
         JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id WHERE $sqlWhere";
$pag = paginar((int) valor("SELECT COUNT(*) $base", $params), 20, max(1, get_int('pagina', 1)));
$variantes = filas("SELECT v.id, v.sku, v.stock, v.estado, p.id AS producto_id, p.nombre, p.imagen, p.estado AS producto_estado,
                           ca.nombre AS categoria, t.nombre AS talla, c.nombre AS color, c.codigo_hex
                    $base ORDER BY v.stock ASC, p.nombre, t.orden LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);
$resumen = fila("SELECT COUNT(*) AS variantes, COALESCE(SUM(v.stock), 0) AS unidades,
                        COALESCE(SUM(v.stock * p.precio), 0) AS valor, SUM(v.stock = 0) AS agotadas, SUM(v.stock > 0 AND v.stock <= ?) AS bajas
                 FROM variantes_producto v JOIN productos p ON p.id = v.producto_id WHERE v.estado = 'activo'", [$umbral]);
$movimientos = filas("SELECT m.*, v.sku, p.nombre, CONCAT(u.nombre, ' ', u.apellido) AS usuario, pe.codigo
                      FROM movimientos_inventario m JOIN variantes_producto v ON v.id = m.variante_id JOIN productos p ON p.id = v.producto_id
                      LEFT JOIN usuarios u ON u.id = m.usuario_id LEFT JOIN pedidos pe ON pe.id = m.pedido_id
                      " . ($productoId ? 'WHERE p.id = ' . (int) $productoId : '') . "
                      ORDER BY m.fecha DESC, m.id DESC LIMIT 12");
$categorias = filas('SELECT id, nombre FROM categorias ORDER BY nombre');

$titulo = 'Inventario';
$seccion = 'inventario';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="kpi"><span class="icono"><i class="bi bi-boxes"></i></span><div><div class="valor"><?= number_format((int) $resumen['unidades'], 0, ',', '.') ?></div><div class="etiqueta-kpi">Unidades en <?= (int) $resumen['variantes'] ?> variantes</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="kpi oscuro"><span class="icono"><i class="bi bi-currency-dollar"></i></span><div><div class="valor"><?= precio($resumen['valor']) ?></div><div class="etiqueta-kpi">Valor del inventario (precio venta)</div></div></div></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none text-reset" href="<?= url('admin/inventario.php', ['bajo' => 1]) ?>"><div class="kpi acento"><span class="icono"><i class="bi bi-exclamation-triangle"></i></span><div><div class="valor"><?= (int) $resumen['bajas'] ?></div><div class="etiqueta-kpi">Con poco stock (≤ <?= $umbral ?>)</div></div></div></a></div>
    <div class="col-6 col-lg-3"><div class="kpi"><span class="icono text-danger"><i class="bi bi-x-octagon"></i></span><div><div class="valor"><?= (int) $resumen['agotadas'] ?></div><div class="etiqueta-kpi">Variantes agotadas</div></div></div></div>
</div>

<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/inventario.php') ?>">
    <div class="col-md-5"><label class="form-label small" for="q">Buscar</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Producto o SKU"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="categoria">Categoría</label>
        <select class="form-select form-select-sm" id="categoria" name="categoria"><option value="">Todas</option><?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="bajo" name="bajo" value="1" <?= $bajo ? 'checked' : '' ?>><label class="form-check-label small fw-semibold" for="bajo">Sólo poco stock</label></div></div>
    <?php if ($productoId): ?><input type="hidden" name="producto" value="<?= $productoId ?>"><?php endif; ?>
    <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-fc flex-fill">Filtrar</button><a class="btn btn-sm btn-light" href="<?= url('admin/inventario.php') ?>" aria-label="Limpiar"><i class="bi bi-x-lg"></i></a></div>
</form>

<div class="tarjeta-admin mb-3">
    <div class="cabecera"><h2><?= $pag['total'] ?> variante(s)</h2><span class="small text-muted-fc">Entrada suma · Salida resta · Conteo fija el stock exacto</span></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Producto / variante</th><th>SKU</th><th class="text-center">Stock</th><th style="min-width:420px">Ajuste</th></tr></thead>
            <tbody>
            <?php foreach ($variantes as $v): ?>
                <tr class="<?= $v['estado'] !== 'activo' || $v['producto_estado'] !== 'activo' ? 'opacity-50' : '' ?>">
                    <td><div class="d-flex align-items-center gap-2"><img class="miniatura" src="<?= imagen_url($v['imagen']) ?>" alt="">
                        <div><a class="fw-semibold text-decoration-none" href="<?= url('admin/producto_editar.php', ['id' => $v['producto_id']]) ?>"><?= e($v['nombre']) ?></a>
                            <div class="small text-muted-fc"><i class="muestra align-middle" style="background: <?= e($v['codigo_hex']) ?>;width:12px;height:12px"></i> <?= e($v['talla']) ?> · <?= e($v['color']) ?> · <?= e($v['categoria']) ?></div></div></div></td>
                    <td class="small text-muted-fc"><?= e($v['sku']) ?></td>
                    <td class="text-center"><span class="badge fs-6 <?= (int) $v['stock'] === 0 ? 'text-bg-danger' : ((int) $v['stock'] <= $umbral ? 'text-bg-warning' : 'text-bg-light') ?>"><?= (int) $v['stock'] ?></span></td>
                    <td>
                        <form class="d-flex gap-1" method="post" action="<?= e(url_actual()) ?>">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="variante_id" value="<?= (int) $v['id'] ?>">
                            <select class="form-select form-select-sm" name="tipo" style="max-width:120px" aria-label="Tipo de movimiento">
                                <option value="entrada">+ Entrada</option><option value="salida">− Salida</option><option value="ajuste">= Conteo</option>
                            </select>
                            <input class="form-control form-control-sm" type="number" name="cantidad" min="0" max="100000" required style="max-width:90px" placeholder="Cant." aria-label="Cantidad">
                            <input class="form-control form-control-sm" name="motivo" maxlength="255" placeholder="Motivo (opcional)" aria-label="Motivo">
                            <button class="btn btn-sm btn-fc" type="submit" title="Aplicar"><i class="bi bi-check2"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$variantes): ?><tr><td colspan="4" class="text-center text-muted-fc py-5">No hay variantes con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require ROOT_PATH . '/includes/partials/paginacion.php'; ?>

<div class="tarjeta-admin mt-3">
    <div class="cabecera"><h2>Últimos movimientos (kardex)</h2></div>
    <div class="table-responsive">
        <table class="table tabla-admin">
            <thead><tr><th>Fecha</th><th>Variante</th><th>Tipo</th><th class="text-end">Cantidad</th><th class="text-end">Stock final</th><th>Motivo</th><th>Usuario</th></tr></thead>
            <tbody>
            <?php foreach ($movimientos as $m): ?>
                <tr>
                    <td class="small text-nowrap"><?= fecha($m['fecha']) ?></td>
                    <td class="small"><?= e($m['nombre']) ?><div class="text-muted-fc"><?= e($m['sku']) ?></div></td>
                    <td><span class="badge text-bg-light"><?= e(TIPOS_MOVIMIENTO[$m['tipo']] ?? $m['tipo']) ?></span></td>
                    <td class="text-end fw-bold <?= (int) $m['cantidad'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['cantidad'] > 0 ? '+' : '' ?><?= (int) $m['cantidad'] ?></td>
                    <td class="text-end"><?= (int) $m['stock_resultante'] ?></td>
                    <td class="small"><?= e($m['motivo']) ?><?php if ($m['codigo']): ?> <a href="<?= url('admin/pedido_detalle.php', ['id' => $m['pedido_id']]) ?>"><?= e($m['codigo']) ?></a><?php endif; ?></td>
                    <td class="small text-muted-fc"><?= e($m['usuario'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
