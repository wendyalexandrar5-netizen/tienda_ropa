<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/funciones_admin.php';
$admin = requerir_admin();

$id = es_post() ? post_int('producto_id') : get_int('id');
$producto = fila('SELECT * FROM productos WHERE id = ?', [$id]);
if (!$producto) {
    error_404('El producto no existe.');
}
$d = $producto;
$errores = [];

if (es_post()) {
    $accion = post_texto('accion', 30);
    try {
        switch ($accion) {
            case 'guardar':
                [$d, $errores] = validar_producto_post();
                if (!$errores) {
                    try {
                        $nueva = subir_imagen($_FILES['imagen'] ?? null, 'productos');
                    } catch (DomainException $e) {
                        $errores['imagen'][] = $e->getMessage();
                        $nueva = null;
                    }
                }
                if (!$errores) {
                    consulta('UPDATE productos SET categoria_id = ?, nombre = ?, descripcion = ?, precio = ?, destacado = ?, estado = ?, imagen = ? WHERE id = ?',
                        [$d['categoria_id'], $d['nombre'], $d['descripcion'] ?: null, (float) $d['precio'], $d['destacado'], $d['estado'], $nueva ?? $producto['imagen'], $id]);
                    if ($nueva) {
                        eliminar_imagen_subida($producto['imagen']);
                    }
                    flash('success', 'Producto actualizado. Los pedidos anteriores conservan su precio histórico.');
                    redirigir('admin/producto_editar.php', ['id' => $id]);
                }
                flash('danger', 'Revisa los campos marcados.');
                break;

            case 'agregar_variante':
                transaccion(static fn() => crear_variante($id, post_int('talla_id'), post_int('color_id'), post_int('stock', 0), (int) $admin['id']));
                flash('success', 'Variante agregada.');
                redirigir('admin/producto_editar.php', ['id' => $id]);
                break;

            case 'estado_variante':
                $v = fila('SELECT id, estado FROM variantes_producto WHERE id = ? AND producto_id = ?', [post_int('variante_id'), $id]);
                if (!$v) {
                    throw new DomainException('La variante no existe.');
                }
                consulta('UPDATE variantes_producto SET estado = ? WHERE id = ?', [$v['estado'] === 'activo' ? 'inactivo' : 'activo', $v['id']]);
                flash('success', $v['estado'] === 'activo' ? 'Variante desactivada (ya no se puede comprar).' : 'Variante activada.');
                redirigir('admin/producto_editar.php', ['id' => $id]);
                break;

            case 'eliminar_variante':
                $vid = post_int('variante_id');
                if (!valor('SELECT id FROM variantes_producto WHERE id = ? AND producto_id = ?', [$vid, $id])) {
                    throw new DomainException('La variante no existe.');
                }
                if ((int) valor('SELECT COUNT(*) FROM pedido_detalle WHERE variante_id = ?', [$vid]) > 0) {
                    throw new DomainException('La variante tiene ventas registradas; desactívala en lugar de eliminarla.');
                }
                consulta('DELETE FROM variantes_producto WHERE id = ?', [$vid]);
                flash('success', 'Variante eliminada.');
                redirigir('admin/producto_editar.php', ['id' => $id]);
                break;

            default:
                throw new DomainException('Acción no válida.');
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirigir('admin/producto_editar.php', ['id' => $id]);
    }
}

$categorias = categorias_todas();
$tallas = tallas_todas();
$colores = colores_todos();
$variantes = filas('SELECT v.*, t.nombre AS talla, c.nombre AS color, c.codigo_hex,
                           (SELECT COUNT(*) FROM pedido_detalle d WHERE d.variante_id = v.id) AS ventas
                    FROM variantes_producto v JOIN tallas t ON t.id = v.talla_id JOIN colores c ON c.id = v.color_id
                    WHERE v.producto_id = ? ORDER BY c.nombre, t.orden', [$id]);
$umbral = umbral_stock_bajo();

$titulo = 'Editar: ' . $producto['nombre'];
$seccion = 'productos';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/productos.php') . '"><i class="bi bi-arrow-left me-1"></i>Productos</a>'
    . '<a class="btn btn-outline-fc" href="' . url('producto.php', ['id' => $id]) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver en tienda</a>';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-7">
        <form method="post" action="<?= url('admin/producto_editar.php') ?>" enctype="multipart/form-data" class="tarjeta-admin needs-validation" novalidate data-cargando>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="producto_id" value="<?= (int) $id ?>">
            <div class="cabecera"><h2>Información del producto</h2><span class="small text-muted-fc">Actualizado: <?= fecha($producto['fecha_actualizacion']) ?></span></div>
            <div class="cuerpo">
                <?php require __DIR__ . '/includes/producto_form.php'; ?>
                <div class="text-end mt-4"><button class="btn btn-fc" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-check2 me-1"></i>Guardar cambios</button></div>
            </div>
        </form>
    </div>
    <div class="col-xl-5">
        <div class="tarjeta-admin mb-3">
            <div class="cabecera"><h2>Variantes (<?= count($variantes) ?>)</h2><a class="small" href="<?= url('admin/inventario.php', ['producto' => $id]) ?>"><i class="bi bi-boxes me-1"></i>Ajustar stock</a></div>
            <div class="table-responsive">
                <table class="table tabla-admin">
                    <thead><tr><th>Variante</th><th>SKU</th><th class="text-center">Stock</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($variantes as $v): ?>
                        <tr class="<?= $v['estado'] !== 'activo' ? 'opacity-50' : '' ?>">
                            <td class="text-nowrap"><i class="muestra align-middle" style="background: <?= e($v['codigo_hex']) ?>"></i> <?= e($v['talla']) ?> · <?= e($v['color']) ?></td>
                            <td class="small text-muted-fc"><?= e($v['sku']) ?></td>
                            <td class="text-center"><span class="badge <?= (int) $v['stock'] === 0 ? 'text-bg-danger' : ((int) $v['stock'] <= $umbral ? 'text-bg-warning' : 'text-bg-light') ?>"><?= (int) $v['stock'] ?></span></td>
                            <td class="text-end text-nowrap">
                                <form class="d-inline" method="post" action="<?= url('admin/producto_editar.php') ?>">
                                    <?= csrf_campo() ?><input type="hidden" name="producto_id" value="<?= (int) $id ?>"><input type="hidden" name="variante_id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="accion" value="estado_variante">
                                    <button class="btn btn-sm btn-light" title="<?= $v['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>"><i class="bi <?= $v['estado'] === 'activo' ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                                </form>
                                <?php if ((int) $v['ventas'] === 0): ?>
                                    <form class="d-inline" method="post" action="<?= url('admin/producto_editar.php') ?>" data-confirmar="¿Eliminar la variante <?= e($v['talla'] . ' / ' . $v['color']) ?>?">
                                        <?= csrf_campo() ?><input type="hidden" name="producto_id" value="<?= (int) $id ?>"><input type="hidden" name="variante_id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="accion" value="eliminar_variante">
                                        <button class="btn btn-sm btn-light text-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$variantes): ?><tr><td colspan="4" class="text-center text-muted-fc py-4">Sin variantes: el producto no se puede comprar todavía.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <form method="post" action="<?= url('admin/producto_editar.php') ?>" class="tarjeta-admin">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="agregar_variante">
            <input type="hidden" name="producto_id" value="<?= (int) $id ?>">
            <div class="cabecera"><h2>Agregar variante</h2></div>
            <div class="cuerpo row g-2 align-items-end">
                <div class="col-4"><label class="form-label small" for="talla_id">Talla</label>
                    <select class="form-select form-select-sm" id="talla_id" name="talla_id" required><?php foreach ($tallas as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-4"><label class="form-label small" for="color_id">Color</label>
                    <select class="form-select form-select-sm" id="color_id" name="color_id" required><?php foreach ($colores as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-4"><label class="form-label small" for="stock">Stock inicial</label>
                    <input class="form-control form-control-sm" type="number" id="stock" name="stock" min="0" max="100000" value="10" required></div>
                <div class="col-12"><button class="btn btn-sm btn-fc w-100 mt-2" type="submit"><i class="bi bi-plus-lg me-1"></i>Agregar variante</button></div>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
