<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/funciones_admin.php';
$admin = requerir_admin();

$categorias = categorias_todas();
$tallas = tallas_todas();
$colores = colores_todos();
$d = ['categoria_id' => get_int('categoria'), 'nombre' => '', 'descripcion' => '', 'precio' => '', 'destacado' => 0, 'estado' => 'activo'];
$errores = [];
$tallasSel = $coloresSel = [];
$stockInicial = 10;

if (es_post()) {
    [$d, $errores] = validar_producto_post();
    $tallasSel = array_values(array_filter(array_map('intval', (array) ($_POST['tallas'] ?? []))));
    $coloresSel = array_values(array_filter(array_map('intval', (array) ($_POST['colores'] ?? []))));
    $stockInicial = post_int('stock_inicial', 0);
    if ($stockInicial < 0 || $stockInicial > 100000) {
        $errores['stock_inicial'][] = 'El stock inicial debe estar entre 0 y 100.000.';
    }
    if (($tallasSel && !$coloresSel) || (!$tallasSel && $coloresSel)) {
        $errores['variantes'][] = 'Para generar variantes selecciona al menos una talla y un color.';
    }

    $imagen = null;
    if (!$errores) {
        try {
            $imagen = subir_imagen($_FILES['imagen'] ?? null, 'productos');
        } catch (DomainException $e) {
            $errores['imagen'][] = $e->getMessage();
        }
    }

    if (!$errores) {
        try {
            $id = transaccion(static function () use ($d, $imagen, $tallasSel, $coloresSel, $stockInicial, $admin) {
                consulta('INSERT INTO productos (categoria_id, nombre, descripcion, precio, imagen, destacado, estado) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$d['categoria_id'], $d['nombre'], $d['descripcion'] ?: null, (float) $d['precio'], $imagen, $d['destacado'], $d['estado']]);
                $id = (int) db()->lastInsertId();
                foreach ($tallasSel as $t) {
                    foreach ($coloresSel as $c) {
                        crear_variante($id, $t, $c, $stockInicial, (int) $admin['id']);
                    }
                }
                return $id;
            });
            $n = count($tallasSel) * count($coloresSel);
            flash('success', "Producto «{$d['nombre']}» creado" . ($n ? " con $n variante(s)." : '. Ahora agrégale variantes (talla/color/stock).'));
            redirigir('admin/producto_editar.php', ['id' => $id]);
        } catch (DomainException $e) {
            eliminar_imagen_subida($imagen);
            $errores['variantes'][] = $e->getMessage();
        } catch (Throwable $e) {
            eliminar_imagen_subida($imagen);
            throw $e;
        }
    }
    flash('danger', 'Revisa los campos marcados.');
}

$titulo = 'Nuevo producto';
$seccion = 'productos';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/productos.php') . '"><i class="bi bi-arrow-left me-1"></i>Volver</a>';
require __DIR__ . '/includes/header.php';
?>
<form method="post" action="<?= url('admin/producto_crear.php') ?>" enctype="multipart/form-data" class="needs-validation" novalidate data-cargando>
    <?= csrf_campo() ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="tarjeta-admin"><div class="cabecera"><h2>Información del producto</h2></div>
                <div class="cuerpo"><?php $producto = null; require __DIR__ . '/includes/producto_form.php'; ?></div></div>
        </div>
        <div class="col-xl-4">
            <div class="tarjeta-admin"><div class="cabecera"><h2>Generar variantes</h2></div>
                <div class="cuerpo">
                    <p class="small text-muted-fc">Se creará una variante por cada combinación talla × color con el stock inicial indicado. Podrás ajustarlas después.</p>
                    <div class="d-flex justify-content-between align-items-center"><span class="form-label mb-1">Tallas</span><button type="button" class="btn btn-link btn-sm p-0" data-marcar-todos="tallas[]">Todas</button></div>
                    <div class="mb-3">
                        <?php foreach ($tallas as $t): ?>
                            <label class="filtro-opcion"><input type="checkbox" name="tallas[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $tallasSel, true) ? 'checked' : '' ?>><span><?= e($t['nombre']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center"><span class="form-label mb-1">Colores</span><button type="button" class="btn btn-link btn-sm p-0" data-marcar-todos="colores[]">Todos</button></div>
                    <div class="mb-3">
                        <?php foreach ($colores as $c): ?>
                            <label class="filtro-opcion"><input type="checkbox" name="colores[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $coloresSel, true) ? 'checked' : '' ?>><span><i class="muestra" style="background: <?= e($c['codigo_hex']) ?>"></i><?= e($c['nombre']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <?= error_campo($errores, 'variantes') ?>
                    <label class="form-label" for="stock_inicial">Stock inicial por variante</label>
                    <input class="form-control<?= clase_invalida($errores, 'stock_inicial') ?>" type="number" id="stock_inicial" name="stock_inicial" min="0" max="100000" value="<?= (int) $stockInicial ?>">
                    <?= error_campo($errores, 'stock_inicial') ?>
                </div>
            </div>
            <button class="btn btn-fc btn-lg w-100 mt-3" type="submit"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><i class="bi bi-check2 me-1"></i>Crear producto</button>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
