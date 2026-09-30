<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = requerir_admin();

$id = es_post() ? post_int('material_id') : get_int('id');
$material = $id ? fila('SELECT * FROM materiales WHERE id = ?', [$id]) : null;
if ($id && !$material) {
    error_404('El material no existe.');
}
$esNuevo = $material === null;
$tipos = filas('SELECT id, nombre FROM tipos_material ORDER BY id');
$proveedores = filas("SELECT id, nombre FROM proveedores WHERE estado = 'activo' OR id = ? ORDER BY nombre", [(int) ($material['proveedor_preferido_id'] ?? 0)]);
$d = $material ?? ['tipo_id' => get_int('tipo') ?: 1, 'codigo' => '', 'nombre' => '', 'descripcion' => '', 'unidad' => 'metro',
                   'stock_minimo' => '0', 'proveedor_preferido_id' => null, 'estado' => 'activo'];
$errores = [];

if (es_post()) {
    $accion = post_texto('accion', 20);
    try {
        if ($accion === 'guardar') {
            $d = [
                'tipo_id' => post_int('tipo_id'), 'codigo' => strtoupper(post_texto('codigo', 20)), 'nombre' => post_texto('nombre', 120),
                'descripcion' => post_texto('descripcion', 255), 'unidad' => post_texto('unidad', 15),
                'stock_minimo' => post_texto('stock_minimo', 15), 'proveedor_preferido_id' => post_int('proveedor_preferido_id') ?: null,
                'estado' => post_texto('estado', 10),
            ];
            $minimo = a_decimal((string) $d['stock_minimo']);
            $v = (new Validador())
                ->requerido('codigo', $d['codigo'], 'El código')->requerido('nombre', $d['nombre'], 'El nombre')
                ->longitud('nombre', $d['nombre'], 3, 120, 'El nombre')
                ->enLista('unidad', $d['unidad'], array_keys(UNIDADES_MATERIAL), 'La unidad')
                ->enLista('estado', $d['estado'], ['activo', 'inactivo'], 'El estado');
            if (!preg_match('/^[A-Z0-9\-]{2,20}$/', $d['codigo'])) {
                $v->agregar('codigo', 'Usa letras, números y guiones (ej.: TEL-009).');
            }
            if ($minimo === null || $minimo < 0 || $minimo > 1000000) {
                $v->agregar('stock_minimo', 'Ingresa un stock mínimo válido.');
            }
            if (!valor('SELECT id FROM tipos_material WHERE id = ?', [$d['tipo_id']])) {
                $v->agregar('tipo_id', 'Selecciona un tipo.');
            }
            if ($d['proveedor_preferido_id'] && !valor('SELECT id FROM proveedores WHERE id = ?', [$d['proveedor_preferido_id']])) {
                $v->agregar('proveedor_preferido_id', 'Proveedor inválido.');
            }
            if (valor('SELECT id FROM materiales WHERE codigo = ? AND id <> ?', [$d['codigo'], $id])) {
                $v->agregar('codigo', 'Ya existe un material con ese código.');
            }
            if (valor('SELECT id FROM materiales WHERE nombre = ? AND id <> ?', [$d['nombre'], $id])) {
                $v->agregar('nombre', 'Ya existe un material con ese nombre.');
            }
            $stockIni = $costoIni = 0.0;
            if ($esNuevo) {
                $stockIni = a_decimal(post_texto('stock_inicial', 15) ?: '0');
                $costoIni = a_decimal(post_texto('costo_inicial', 15) ?: '0');
                if ($stockIni === null || $stockIni < 0 || $costoIni === null || $costoIni < 0) {
                    $v->agregar('stock_inicial', 'Revisa el stock y el costo inicial.');
                } elseif ($stockIni > 0 && $costoIni <= 0) {
                    $v->agregar('costo_inicial', 'Indica el costo unitario del inventario inicial.');
                }
            }
            if ($v->ok()) {
                $campos = [$d['tipo_id'], $d['codigo'], $d['nombre'], $d['descripcion'] ?: null, $d['unidad'], $minimo, $d['proveedor_preferido_id'], $d['estado']];
                if ($esNuevo) {
                    $id = transaccion(static function () use ($campos, $stockIni, $costoIni, $admin) {
                        consulta('INSERT INTO materiales (tipo_id, codigo, nombre, descripcion, unidad, stock_minimo, proveedor_preferido_id, estado, costo_promedio)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($campos, [$costoIni]));
                        $nuevoId = (int) db()->lastInsertId();
                        if ($stockIni > 0) {
                            mover_material($nuevoId, 'ajuste', $stockIni, $costoIni, 'Inventario inicial', (int) $admin['id']);
                        }
                        return $nuevoId;
                    });
                    flash('success', "Material «{$d['nombre']}» creado.");
                } else {
                    consulta('UPDATE materiales SET tipo_id = ?, codigo = ?, nombre = ?, descripcion = ?, unidad = ?, stock_minimo = ?, proveedor_preferido_id = ?, estado = ? WHERE id = ?',
                        array_merge($campos, [$id]));
                    flash('success', 'Material actualizado.');
                }
                redirigir('admin/material_editar.php', ['id' => $id]);
            }
            $errores = $v->errores();
            flash('danger', 'Revisa los campos marcados.');
        } elseif (!$esNuevo && $accion === 'ajuste') {
            $cant = post_decimal('cantidad');
            $nuevo = ajustar_material($id, post_texto('tipo', 10), $cant ?? -1, post_texto('motivo', 200), (int) $admin['id']);
            flash('success', 'Inventario ajustado: ahora hay ' . cantidad_fmt($nuevo, $material['unidad']) . '.');
            redirigir('admin/material_editar.php', ['id' => $id]);
        } elseif (!$esNuevo && $accion === 'eliminar') {
            $usos = (int) valor('SELECT (SELECT COUNT(*) FROM compra_detalle WHERE material_id = ?) + (SELECT COUNT(*) FROM ficha_materiales WHERE material_id = ?)
                                      + (SELECT COUNT(*) FROM produccion_consumos WHERE material_id = ?)', [$id, $id, $id]);
            if ($usos > 0) {
                throw new DomainException('El material tiene compras, fichas o consumos asociados; desactívalo en lugar de eliminarlo.');
            }
            consulta('DELETE FROM materiales WHERE id = ?', [$id]);
            flash('success', 'Material eliminado.');
            redirigir('admin/materiales.php');
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirigir('admin/material_editar.php', $id ? ['id' => $id] : []);
    }
}

$kardex = $material ? filas("SELECT mm.*, CONCAT(u.nombre, ' ', u.apellido) AS usuario, c.codigo AS compra, o.codigo AS orden
                             FROM movimientos_material mm LEFT JOIN usuarios u ON u.id = mm.usuario_id
                             LEFT JOIN compras_material c ON c.id = mm.compra_id LEFT JOIN ordenes_produccion o ON o.id = mm.orden_id
                             WHERE mm.material_id = ? ORDER BY mm.fecha DESC, mm.id DESC LIMIT 40", [$id]) : [];
$fichas = $material ? filas('SELECT p.id, p.nombre, fm.cantidad, fm.merma_pct FROM ficha_materiales fm JOIN fichas_tecnicas f ON f.id = fm.ficha_id
                             JOIN productos p ON p.id = f.producto_id WHERE fm.material_id = ? ORDER BY p.nombre', [$id]) : [];

$titulo = $esNuevo ? 'Nuevo material' : $material['nombre'];
$seccion = 'materiales';
$acciones_cabecera = '<a class="btn btn-light" href="' . url('admin/materiales.php') . '"><i class="bi bi-arrow-left me-1"></i>Materias primas</a>';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-5">
        <?php if (!$esNuevo): ?>
            <div class="row g-2 mb-3">
                <div class="col-6"><div class="kpi oscuro"><div><div class="etiqueta-kpi">Stock actual</div><div class="valor"><?= cantidad_fmt($material['stock'], $material['unidad']) ?></div></div></div></div>
                <div class="col-6"><div class="kpi"><div><div class="etiqueta-kpi">Costo promedio</div><div class="valor"><?= precio($material['costo_promedio']) ?></div><div class="small text-muted-fc">Valor: <?= precio((float) $material['stock'] * (float) $material['costo_promedio']) ?></div></div></div></div>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= url('admin/material_editar.php') ?>" class="tarjeta-admin needs-validation mb-3" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="material_id" value="<?= (int) $id ?>">
            <div class="cabecera"><h2>Datos del material</h2></div>
            <div class="cuerpo row g-2">
                <div class="col-sm-5"><label class="form-label small" for="codigo">Código *</label>
                    <input class="form-control<?= clase_invalida($errores, 'codigo') ?>" id="codigo" name="codigo" required maxlength="20" value="<?= e($d['codigo']) ?>" placeholder="TEL-009"><?= error_campo($errores, 'codigo') ?></div>
                <div class="col-sm-7"><label class="form-label small" for="tipo_id">Tipo *</label>
                    <select class="form-select<?= clase_invalida($errores, 'tipo_id') ?>" id="tipo_id" name="tipo_id"><?php foreach ($tipos as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $d['tipo_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label small" for="nombre">Nombre *</label>
                    <input class="form-control<?= clase_invalida($errores, 'nombre') ?>" id="nombre" name="nombre" required maxlength="120" value="<?= e($d['nombre']) ?>"><?= error_campo($errores, 'nombre') ?></div>
                <div class="col-12"><label class="form-label small" for="descripcion">Descripción</label>
                    <input class="form-control" id="descripcion" name="descripcion" maxlength="255" value="<?= e($d['descripcion']) ?>" placeholder="Composición, ancho, calibre…"></div>
                <div class="col-sm-6"><label class="form-label small" for="unidad">Unidad de medida *</label>
                    <select class="form-select" id="unidad" name="unidad" <?= !$esNuevo && (float) $material['stock'] > 0 ? 'title="Cambiar la unidad con stock existente puede descuadrar el inventario"' : '' ?>>
                        <?php foreach (UNIDADES_MATERIAL as $u => $abr): ?><option value="<?= e($u) ?>" <?= $d['unidad'] === $u ? 'selected' : '' ?>><?= e(ucfirst($u)) ?> (<?= e($abr) ?>)</option><?php endforeach; ?></select></div>
                <div class="col-sm-6"><label class="form-label small" for="stock_minimo">Stock mínimo (alerta)</label>
                    <input class="form-control<?= clase_invalida($errores, 'stock_minimo') ?>" id="stock_minimo" name="stock_minimo" inputmode="decimal" value="<?= e(rtrim(rtrim((string) $d['stock_minimo'], '0'), '.') ?: '0') ?>"><?= error_campo($errores, 'stock_minimo') ?></div>
                <?php if ($esNuevo): ?>
                    <div class="col-sm-6"><label class="form-label small" for="stock_inicial">Stock inicial</label>
                        <input class="form-control<?= clase_invalida($errores, 'stock_inicial') ?>" id="stock_inicial" name="stock_inicial" inputmode="decimal" value="0"><?= error_campo($errores, 'stock_inicial') ?></div>
                    <div class="col-sm-6"><label class="form-label small" for="costo_inicial">Costo unitario inicial ($)</label>
                        <input class="form-control<?= clase_invalida($errores, 'costo_inicial') ?>" id="costo_inicial" name="costo_inicial" inputmode="decimal" value="0"><?= error_campo($errores, 'costo_inicial') ?></div>
                    <div class="col-12"><div class="form-text">Lo normal es dejar el stock en 0 y cargarlo con una <strong>compra</strong>, que actualiza el costo promedio.</div></div>
                <?php endif; ?>
                <div class="col-sm-7"><label class="form-label small" for="proveedor_preferido_id">Proveedor habitual</label>
                    <select class="form-select" id="proveedor_preferido_id" name="proveedor_preferido_id"><option value="">—</option>
                        <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $d['proveedor_preferido_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-5"><label class="form-label small" for="estado">Estado</label>
                    <select class="form-select" id="estado" name="estado"><option value="activo" <?= $d['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option><option value="inactivo" <?= $d['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo</option></select></div>
                <div class="col-12 d-flex justify-content-between mt-3">
                    <?php if (!$esNuevo && !$fichas): ?>
                        <button class="btn btn-link text-danger px-0" form="form-eliminar" type="submit">Eliminar</button>
                    <?php else: ?><span></span><?php endif; ?>
                    <button class="btn btn-fc"><i class="bi bi-check2 me-1"></i><?= $esNuevo ? 'Crear material' : 'Guardar cambios' ?></button>
                </div>
            </div>
        </form>
        <?php if (!$esNuevo): ?>
            <form id="form-eliminar" method="post" action="<?= url('admin/material_editar.php') ?>" data-confirmar="¿Eliminar este material? Sólo es posible si nunca se usó."><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="material_id" value="<?= (int) $id ?>"></form>
            <form method="post" action="<?= url('admin/material_editar.php') ?>" class="tarjeta-admin mb-3">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="ajuste">
                <input type="hidden" name="material_id" value="<?= (int) $id ?>">
                <div class="cabecera"><h2>Ajustar inventario</h2><a class="small" href="<?= url('admin/compra_crear.php', ['material' => $id]) ?>">¿Llegó mercancía? Registra una compra</a></div>
                <div class="cuerpo row g-2 align-items-end">
                    <div class="col-sm-4"><label class="form-label small" for="tipo">Tipo</label>
                        <select class="form-select form-select-sm" id="tipo" name="tipo"><option value="salida">− Salida / merma</option><option value="entrada">+ Entrada</option><option value="conteo">= Conteo físico</option></select></div>
                    <div class="col-sm-3"><label class="form-label small" for="cantidad">Cantidad (<?= e(UNIDADES_MATERIAL[$material['unidad']]) ?>)</label>
                        <input class="form-control form-control-sm" id="cantidad" name="cantidad" inputmode="decimal" required></div>
                    <div class="col-sm-5"><label class="form-label small" for="motivo">Motivo</label>
                        <input class="form-control form-control-sm" id="motivo" name="motivo" maxlength="200" placeholder="Ej.: tela manchada"></div>
                    <div class="col-12"><button class="btn btn-sm btn-fc w-100">Aplicar ajuste</button></div>
                </div>
            </form>
            <div class="tarjeta-admin">
                <div class="cabecera"><h2>Se usa en <?= count($fichas) ?> ficha(s) técnica(s)</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($fichas as $f): ?>
                        <li class="list-group-item d-flex justify-content-between px-3 small"><a href="<?= url('admin/ficha_editar.php', ['producto' => $f['id']]) ?>"><?= e($f['nombre']) ?></a>
                            <span><?= cantidad_fmt($f['cantidad'], $material['unidad']) ?> por prenda<?= (float) $f['merma_pct'] > 0 ? ' + ' . porcentaje($f['merma_pct'], 0) . ' merma' : '' ?></span></li>
                    <?php endforeach; ?>
                    <?php if (!$fichas): ?><li class="list-group-item text-muted-fc small px-3">Todavía no se usa en ninguna ficha.</li><?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$esNuevo): ?>
        <div class="col-xl-7">
            <div class="tarjeta-admin">
                <div class="cabecera"><h2>Kardex (últimos movimientos)</h2><span class="small text-muted-fc">El costo promedio se recalcula con cada compra</span></div>
                <div class="table-responsive">
                    <table class="table tabla-admin">
                        <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-end">Cantidad</th><th class="text-end">Saldo</th><th class="text-end">Costo unit.</th><th>Referencia</th></tr></thead>
                        <tbody>
                        <?php foreach ($kardex as $k): ?>
                            <tr>
                                <td class="small text-nowrap"><?= fecha($k['fecha']) ?></td>
                                <td><span class="badge text-bg-light"><?= e(TIPOS_MOV_MATERIAL[$k['tipo']] ?? $k['tipo']) ?></span></td>
                                <td class="text-end fw-bold <?= (float) $k['cantidad'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (float) $k['cantidad'] > 0 ? '+' : '' ?><?= cantidad_fmt($k['cantidad']) ?></td>
                                <td class="text-end"><?= cantidad_fmt($k['stock_resultante']) ?></td>
                                <td class="text-end small"><?= precio($k['costo_unitario']) ?></td>
                                <td class="small"><?php if ($k['compra']): ?><a href="<?= url('admin/compra_detalle.php', ['id' => $k['compra_id']]) ?>"><?= e($k['compra']) ?></a>
                                    <?php elseif ($k['orden']): ?><a href="<?= url('admin/produccion_detalle.php', ['id' => $k['orden_id']]) ?>"><?= e($k['orden']) ?></a>
                                    <?php else: ?><?= e($k['motivo']) ?><?php endif; ?>
                                    <div class="text-muted-fc"><?= e($k['usuario'] ?? '') ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$kardex): ?><tr><td colspan="6" class="text-center text-muted-fc py-4">Sin movimientos.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
