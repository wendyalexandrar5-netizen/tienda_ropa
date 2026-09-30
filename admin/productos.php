<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/funciones_admin.php';
$admin = requerir_admin();

if (es_post()) {
    $id = post_int('producto_id');
    $p = fila('SELECT id, nombre, estado, imagen FROM productos WHERE id = ?', [$id]);
    if (!$p) {
        flash('danger', 'El producto no existe.');
    } elseif (post_texto('accion', 20) === 'estado') {
        $nuevo = $p['estado'] === 'activo' ? 'inactivo' : 'activo';
        consulta('UPDATE productos SET estado = ? WHERE id = ?', [$nuevo, $id]);
        flash('success', "«{$p['nombre']}» ahora está " . ($nuevo === 'activo' ? 'activo y visible en la tienda.' : 'inactivo (oculto en la tienda).'));
    } elseif (post_texto('accion', 20) === 'eliminar') {
        // Integridad referencial: un producto con ventas no se borra (se conserva el historial), se desactiva.
        if ((int) valor('SELECT COUNT(*) FROM pedido_detalle WHERE producto_id = ?', [$id]) > 0) {
            flash('warning', "«{$p['nombre']}» tiene pedidos asociados y no puede eliminarse. Se recomienda desactivarlo.");
        } else {
            consulta('DELETE FROM productos WHERE id = ?', [$id]);
            eliminar_imagen_subida($p['imagen']);
            flash('success', "«{$p['nombre']}» fue eliminado junto con sus variantes.");
        }
    }
    redirigir('admin/productos.php', array_intersect_key($_GET, array_flip(['q', 'categoria', 'estado', 'pagina'])));
}

$q = get_texto('q', 80);
$cat = get_int('categoria');
$estado = get_texto('estado', 10);
$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(p.nombre LIKE ? OR p.id = ?)';
    $params[] = '%' . escapar_like($q) . '%';
    $params[] = ctype_digit($q) ? (int) $q : 0;
}
if ($cat > 0) {
    $where[] = 'p.categoria_id = ?';
    $params[] = $cat;
}
if (in_array($estado, ['activo', 'inactivo'], true)) {
    $where[] = 'p.estado = ?';
    $params[] = $estado;
}
$sqlWhere = implode(' AND ', $where);
$pag = paginar((int) valor("SELECT COUNT(*) FROM productos p WHERE $sqlWhere", $params), 15, max(1, get_int('pagina', 1)));
$productos = filas("SELECT p.id, p.nombre, p.precio, p.imagen, p.estado, p.destacado, p.fecha_actualizacion, c.nombre AS categoria,
                           COUNT(v.id) AS variantes, COALESCE(SUM(v.stock), 0) AS stock,
                           (SELECT COUNT(*) FROM pedido_detalle d WHERE d.producto_id = p.id) AS ventas
                    FROM productos p JOIN categorias c ON c.id = p.categoria_id
                    LEFT JOIN variantes_producto v ON v.producto_id = p.id AND v.estado = 'activo'
                    WHERE $sqlWhere GROUP BY p.id ORDER BY p.id DESC
                    LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);
$categorias = categorias_todas();

$titulo = 'Productos';
$seccion = 'productos';
$acciones_cabecera = '<a class="btn btn-fc" href="' . url('admin/producto_crear.php') . '"><i class="bi bi-plus-lg me-1"></i>Nuevo producto</a>';
require __DIR__ . '/includes/header.php';
?>
<form class="barra-filtros row g-2 align-items-end mb-3" method="get" action="<?= url('admin/productos.php') ?>">
    <div class="col-md-5"><label class="form-label small" for="q">Buscar</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre o ID"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="categoria">Categoría</label>
        <select class="form-select form-select-sm" id="categoria" name="categoria"><option value="">Todas</option>
            <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="estado">Estado</label>
        <select class="form-select form-select-sm" id="estado" name="estado"><option value="">Todos</option>
            <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activos</option><option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
        </select></div>
    <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-fc flex-fill">Filtrar</button><a class="btn btn-sm btn-light" href="<?= url('admin/productos.php') ?>" aria-label="Limpiar"><i class="bi bi-x-lg"></i></a></div>
</form>

<div class="tarjeta-admin">
    <div class="cabecera"><h2><?= $pag['total'] ?> producto(s)</h2></div>
    <div class="table-responsive">
        <table class="table tabla-admin table-hover">
            <thead><tr><th>Producto</th><th>Categoría</th><th class="text-end">Precio</th><th class="text-center">Variantes</th><th class="text-center">Stock</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($productos as $p): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <img class="miniatura" src="<?= imagen_url($p['imagen']) ?>" alt="">
                            <div>
                                <a class="fw-bold text-decoration-none" href="<?= url('admin/producto_editar.php', ['id' => $p['id']]) ?>"><?= e($p['nombre']) ?></a>
                                <?php if ((int) $p['destacado']): ?><i class="bi bi-star-fill text-warning ms-1" title="Destacado"></i><?php endif; ?>
                                <div class="small text-muted-fc">#<?= (int) $p['id'] ?> · <?= (int) $p['ventas'] ?> línea(s) vendidas</div>
                            </div>
                        </div>
                    </td>
                    <td><?= e($p['categoria']) ?></td>
                    <td class="text-end fw-semibold"><?= precio($p['precio']) ?></td>
                    <td class="text-center"><?= (int) $p['variantes'] ?></td>
                    <td class="text-center"><span class="badge <?= (int) $p['stock'] === 0 ? 'text-bg-danger' : ((int) $p['stock'] <= umbral_stock_bajo() * 2 ? 'text-bg-warning' : 'text-bg-light') ?>"><?= (int) $p['stock'] ?></span></td>
                    <td><?= $p['estado'] === 'activo' ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= url('admin/producto_editar.php', ['id' => $p['id']]) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                        <a class="btn btn-sm btn-light" href="<?= url('producto.php', ['id' => $p['id']]) ?>" target="_blank" rel="noopener" title="Ver en tienda"><i class="bi bi-box-arrow-up-right"></i></a>
                        <form class="d-inline" method="post" action="<?= e(url_actual()) ?>">
                            <?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-light" title="<?= $p['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>"><i class="bi <?= $p['estado'] === 'activo' ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                        </form>
                        <form class="d-inline" method="post" action="<?= e(url_actual()) ?>" data-confirmar="¿Eliminar definitivamente «<?= e($p['nombre']) ?>» y sus variantes?">
                            <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$productos): ?><tr><td colspan="7" class="text-center py-5 text-muted-fc">No hay productos con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require ROOT_PATH . '/includes/partials/paginacion.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
