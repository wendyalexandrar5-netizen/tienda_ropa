<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Fabricación: materias primas, compras a proveedores, fichas técnicas (recetas)
 * y órdenes de producción.
 *
 * Reglas principales:
 *  - Todo cambio de stock de un material pasa por mover_material(), dentro de una
 *    transacción, con bloqueo de fila (FOR UPDATE) y registro en el kardex.
 *  - El costo de cada material es un PROMEDIO PONDERADO: al comprar,
 *      nuevo = (stock × costo_actual + cantidad × costo_compra) / (stock + cantidad)
 *  - Costo de fabricar una prenda (ficha técnica) =
 *      Σ cantidad × (1 + merma%) × costo_promedio  +  mano de obra  +  costos indirectos
 */

const UNIDADES_MATERIAL = [
    'metro' => 'm', 'unidad' => 'und', 'cono' => 'cono', 'kilogramo' => 'kg',
    'litro' => 'L', 'rollo' => 'rollo', 'par' => 'par',
];

const ESTADOS_PRODUCCION = [
    'planificada' => ['Planificada', 'secondary', 'bi-calendar-check'],
    'en_proceso'  => ['En proceso', 'warning', 'bi-gear-wide-connected'],
    'terminada'   => ['Terminada', 'success', 'bi-check2-all'],
    'cancelada'   => ['Cancelada', 'danger', 'bi-x-circle'],
];

const TIPOS_MOV_MATERIAL = [
    'compra' => 'Compra', 'consumo' => 'Consumo', 'ajuste' => 'Ajuste',
    'devolucion' => 'Devolución', 'anulacion' => 'Anulación',
];

/** Lee un decimal de $_POST aceptando coma o punto ("1,5" o "1.5"). Devuelve null si no es válido. */
function post_decimal(string $clave): ?float
{
    $v = $_POST[$clave] ?? '';
    return is_string($v) ? a_decimal($v) : null;
}

function a_decimal(string $v): ?float
{
    $v = str_replace([' ', '$'], '', trim($v));
    if (substr_count($v, ',') === 1 && strpos($v, '.') === false) {
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float) $v : null;
}

/** Cantidad legible: 1.5 m, 800 und, 0,04 cono. */
function cantidad_fmt(float|string|null $valor, string $unidad = ''): string
{
    $n = (float) $valor;
    $dec = abs($n - round($n)) < 0.0005 ? 0 : (abs($n * 100 - round($n * 100)) < 0.05 ? 2 : 3);
    return number_format($n, $dec, ',', '.') . ($unidad !== '' ? ' ' . (UNIDADES_MATERIAL[$unidad] ?? $unidad) : '');
}

function estado_produccion_badge(string $estado): string
{
    [$texto, $color, $icono] = ESTADOS_PRODUCCION[$estado] ?? [ucfirst($estado), 'secondary', 'bi-circle'];
    $extra = $color === 'warning' ? ' text-dark' : '';
    return '<span class="badge rounded-pill text-bg-' . $color . $extra . '"><i class="bi ' . $icono . ' me-1"></i>' . e($texto) . '</span>';
}

// ---------------------------------------------------------------------------
// Inventario de materias primas
// ---------------------------------------------------------------------------

/**
 * Mueve el stock de un material. Debe llamarse DENTRO de una transacción.
 * Si $costoEntrada no es null y la cantidad es positiva, recalcula el costo promedio.
 * @return float stock resultante
 */
function mover_material(int $materialId, string $tipo, float $delta, ?float $costoEntrada, ?string $motivo, ?int $usuarioId,
                        ?int $compraId = null, ?int $ordenId = null): float
{
    if (!db()->inTransaction()) {
        throw new LogicException('mover_material() requiere una transacción activa.');
    }
    if (!isset(TIPOS_MOV_MATERIAL[$tipo])) {
        throw new InvalidArgumentException('Tipo de movimiento de material inválido.');
    }
    $m = fila('SELECT id, nombre, stock, costo_promedio, unidad FROM materiales WHERE id = ? FOR UPDATE', [$materialId]);
    if (!$m) {
        throw new DomainException('El material no existe.');
    }
    $stock = (float) $m['stock'];
    $prom  = (float) $m['costo_promedio'];
    $nuevo = round($stock + $delta, 3);
    if ($nuevo < -0.0005) {
        throw new DomainException("Stock insuficiente de «{$m['nombre']}»: hay " . cantidad_fmt($stock, $m['unidad']) . '.');
    }
    $nuevo = max(0.0, $nuevo);

    if ($costoEntrada !== null && $delta > 0) {
        // Promedio ponderado
        $prom = $nuevo > 0 ? round(($stock * $prom + $delta * $costoEntrada) / $nuevo, 2) : $costoEntrada;
    } elseif ($costoEntrada !== null && $delta < 0 && $tipo === 'anulacion') {
        // Revertir una compra: se retira la cantidad al costo con el que entró
        $prom = $nuevo > 0 ? max(0.0, round(($stock * $prom + $delta * $costoEntrada) / $nuevo, 2)) : $prom;
    }
    consulta('UPDATE materiales SET stock = ?, costo_promedio = ? WHERE id = ?', [$nuevo, $prom, $materialId]);
    consulta('INSERT INTO movimientos_material (material_id, tipo, cantidad, stock_resultante, costo_unitario, motivo, compra_id, orden_id, usuario_id)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$materialId, $tipo, $delta, $nuevo, $costoEntrada ?? $prom, $motivo, $compraId, $ordenId, $usuarioId]);
    return $nuevo;
}

/** Ajuste manual: entrada (suma), salida (resta: merma, daño) o conteo (fija el stock exacto). */
function ajustar_material(int $materialId, string $tipo, float $cantidad, string $motivo, int $usuarioId): float
{
    if (!in_array($tipo, ['entrada', 'salida', 'conteo'], true) || $cantidad < 0 || $cantidad > 1000000) {
        throw new DomainException('Datos de ajuste inválidos.');
    }
    if ($tipo !== 'conteo' && $cantidad <= 0) {
        throw new DomainException('La cantidad debe ser mayor que cero.');
    }
    return transaccion(static function () use ($materialId, $tipo, $cantidad, $motivo, $usuarioId) {
        $actual = valor('SELECT stock FROM materiales WHERE id = ? FOR UPDATE', [$materialId]);
        if ($actual === null) {
            throw new DomainException('El material no existe.');
        }
        $delta = match ($tipo) {
            'entrada' => $cantidad,
            'salida'  => -$cantidad,
            'conteo'  => $cantidad - (float) $actual,
        };
        $etiqueta = ['entrada' => 'Entrada manual', 'salida' => 'Salida / merma', 'conteo' => 'Conteo físico'][$tipo];
        return mover_material($materialId, 'ajuste', $delta, null, $etiqueta . ($motivo !== '' ? ": $motivo" : ''), $usuarioId);
    });
}

// ---------------------------------------------------------------------------
// Compras de materiales
// ---------------------------------------------------------------------------

/**
 * Registra una compra: suma stock y recalcula el costo promedio de cada material.
 * @param array $lineas [[material_id, cantidad, costo_unitario], ...]
 * @return array{id:int, codigo:string, total:float}
 */
function registrar_compra(int $proveedorId, string $fecha, string $factura, string $notas, array $lineas, int $usuarioId): array
{
    if (!valor("SELECT id FROM proveedores WHERE id = ? AND estado = 'activo'", [$proveedorId])) {
        throw new DomainException('Selecciona un proveedor activo.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || strtotime($fecha) === false || $fecha > date('Y-m-d')) {
        throw new DomainException('La fecha de la compra no es válida (no puede ser futura).');
    }
    $limpias = [];
    foreach ($lineas as [$mid, $cant, $costo]) {
        if ($mid <= 0 && $cant === null && $costo === null) {
            continue; // fila vacía del formulario
        }
        if ($mid <= 0 || $cant === null || $cant <= 0 || $cant > 1000000 || $costo === null || $costo < 0 || $costo > 100000000) {
            throw new DomainException('Revisa las líneas: cada una necesita material, cantidad mayor que cero y costo unitario válido.');
        }
        if (isset($limpias[$mid])) {
            throw new DomainException('Un material aparece dos veces en la compra; únelo en una sola línea.');
        }
        $limpias[$mid] = [$mid, round($cant, 3), round($costo, 2)];
    }
    if (!$limpias) {
        throw new DomainException('Agrega al menos un material a la compra.');
    }

    return transaccion(static function (PDO $pdo) use ($proveedorId, $fecha, $factura, $notas, $limpias, $usuarioId) {
        consulta('INSERT INTO compras_material (proveedor_id, fecha, numero_factura, notas, usuario_id) VALUES (?, ?, ?, ?, ?)',
            [$proveedorId, $fecha, $factura !== '' ? $factura : null, $notas !== '' ? $notas : null, $usuarioId]);
        $id = (int) $pdo->lastInsertId();
        $codigo = sprintf('CM-%06d', $id);
        $total = 0.0;
        foreach ($limpias as [$mid, $cant, $costo]) {
            if (!valor("SELECT id FROM materiales WHERE id = ?", [$mid])) {
                throw new DomainException('Uno de los materiales no existe.');
            }
            $sub = round($cant * $costo, 2);
            $total += $sub;
            consulta('INSERT INTO compra_detalle (compra_id, material_id, cantidad, costo_unitario, subtotal) VALUES (?, ?, ?, ?, ?)',
                [$id, $mid, $cant, $costo, $sub]);
            mover_material($mid, 'compra', $cant, $costo, "Compra $codigo", $usuarioId, $id);
        }
        consulta('UPDATE compras_material SET codigo = ?, total = ? WHERE id = ?', [$codigo, $total, $id]);
        return ['id' => $id, 'codigo' => $codigo, 'total' => $total];
    });
}

/** Anula una compra devolviendo el stock (sólo si el material no se ha consumido). */
function anular_compra(int $compraId, int $usuarioId): void
{
    transaccion(static function () use ($compraId, $usuarioId) {
        $c = fila('SELECT id, codigo, estado FROM compras_material WHERE id = ? FOR UPDATE', [$compraId]);
        if (!$c || $c['estado'] !== 'recibida') {
            throw new DomainException('La compra no existe o ya fue anulada.');
        }
        foreach (filas('SELECT material_id, cantidad, costo_unitario FROM compra_detalle WHERE compra_id = ?', [$compraId]) as $d) {
            mover_material((int) $d['material_id'], 'anulacion', -(float) $d['cantidad'], (float) $d['costo_unitario'],
                "Anulación {$c['codigo']}", $usuarioId, $compraId);
        }
        consulta("UPDATE compras_material SET estado = 'anulada' WHERE id = ?", [$compraId]);
    });
}

// ---------------------------------------------------------------------------
// Fichas técnicas y costos de fabricación
// ---------------------------------------------------------------------------

/** Ficha de un producto con sus materiales y el costo calculado con los costos promedio actuales. */
function ficha_producto(int $productoId): ?array
{
    $f = fila('SELECT * FROM fichas_tecnicas WHERE producto_id = ?', [$productoId]);
    if (!$f) {
        return null;
    }
    $f['lineas'] = filas('SELECT fm.id, fm.material_id, fm.cantidad, fm.merma_pct, m.codigo, m.nombre, m.unidad, m.costo_promedio, m.stock,
                                 t.nombre AS tipo,
                                 ROUND(fm.cantidad * (1 + fm.merma_pct / 100), 3) AS cantidad_real,
                                 ROUND(fm.cantidad * (1 + fm.merma_pct / 100) * m.costo_promedio, 2) AS costo
                          FROM ficha_materiales fm JOIN materiales m ON m.id = fm.material_id JOIN tipos_material t ON t.id = m.tipo_id
                          WHERE fm.ficha_id = ? ORDER BY t.id, m.nombre', [$f['id']]);
    $f['costo_materiales'] = array_sum(array_map('floatval', array_column($f['lineas'], 'costo')));
    $f['costo_total'] = round($f['costo_materiales'] + (float) $f['costo_mano_obra'] + (float) $f['costos_indirectos'], 2);
    return $f;
}

/**
 * Costo estándar por prenda de varios productos (una consulta).
 * @return array<int, float> producto_id => costo (sólo productos con ficha)
 */
function costos_fabricacion(array $productoIds = []): array
{
    $where = '';
    $params = [];
    if ($productoIds) {
        $where = 'WHERE f.producto_id IN (' . implode(',', array_fill(0, count($productoIds), '?')) . ')';
        $params = array_values(array_map('intval', $productoIds));
    }
    $r = [];
    foreach (filas("SELECT f.producto_id, f.costo_mano_obra + f.costos_indirectos
                           + COALESCE(SUM(fm.cantidad * (1 + fm.merma_pct / 100) * m.costo_promedio), 0) AS costo
                    FROM fichas_tecnicas f
                    LEFT JOIN ficha_materiales fm ON fm.ficha_id = f.id
                    LEFT JOIN materiales m ON m.id = fm.material_id
                    $where GROUP BY f.id, f.producto_id, f.costo_mano_obra, f.costos_indirectos", $params) as $fila) {
        $r[(int) $fila['producto_id']] = round((float) $fila['costo'], 2);
    }
    return $r;
}

/** Guarda (crea o reemplaza) la ficha técnica de un producto. $lineas = [[material_id, cantidad, merma_pct], ...] */
function guardar_ficha(int $productoId, float $manoObra, float $indirectos, int $minutos, string $notas, array $lineas): void
{
    if (!valor('SELECT id FROM productos WHERE id = ?', [$productoId])) {
        throw new DomainException('El producto no existe.');
    }
    if ($manoObra < 0 || $manoObra > 10000000 || $indirectos < 0 || $indirectos > 10000000 || $minutos < 0 || $minutos > 10000) {
        throw new DomainException('Revisa la mano de obra, los costos indirectos y el tiempo.');
    }
    $limpias = [];
    foreach ($lineas as [$mid, $cant, $merma]) {
        if ($mid <= 0 && $cant === null) {
            continue;
        }
        if ($mid <= 0 || $cant === null || $cant <= 0 || $cant > 10000 || $merma === null || $merma < 0 || $merma > 100) {
            throw new DomainException('Cada material necesita una cantidad por prenda mayor que cero y una merma entre 0 y 100 %.');
        }
        if (isset($limpias[$mid])) {
            throw new DomainException('Un material aparece dos veces en la ficha.');
        }
        if (!valor('SELECT id FROM materiales WHERE id = ?', [$mid])) {
            throw new DomainException('Uno de los materiales no existe.');
        }
        $limpias[$mid] = [$mid, round($cant, 3), round($merma, 2)];
    }
    if (!$limpias) {
        throw new DomainException('La ficha técnica debe tener al menos un material.');
    }
    transaccion(static function () use ($productoId, $manoObra, $indirectos, $minutos, $notas, $limpias) {
        consulta('INSERT INTO fichas_tecnicas (producto_id, costo_mano_obra, costos_indirectos, tiempo_minutos, notas) VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE costo_mano_obra = VALUES(costo_mano_obra), costos_indirectos = VALUES(costos_indirectos),
                                          tiempo_minutos = VALUES(tiempo_minutos), notas = VALUES(notas)',
            [$productoId, $manoObra, $indirectos, $minutos, $notas !== '' ? $notas : null]);
        $fichaId = (int) valor('SELECT id FROM fichas_tecnicas WHERE producto_id = ?', [$productoId]);
        consulta('DELETE FROM ficha_materiales WHERE ficha_id = ?', [$fichaId]);
        foreach ($limpias as [$mid, $cant, $merma]) {
            consulta('INSERT INTO ficha_materiales (ficha_id, material_id, cantidad, merma_pct) VALUES (?, ?, ?, ?)', [$fichaId, $mid, $cant, $merma]);
        }
    });
}

// ---------------------------------------------------------------------------
// Órdenes de producción
// ---------------------------------------------------------------------------

/** Materiales necesarios para fabricar $cantidad prendas, comparados con el stock actual. */
function requerimientos_produccion(int $productoId, int $cantidad): array
{
    $ficha = ficha_producto($productoId);
    if (!$ficha) {
        return [];
    }
    $r = [];
    foreach ($ficha['lineas'] as $l) {
        $req = round($cantidad * (float) $l['cantidad_real'], 3);
        $r[] = $l + ['requerido' => $req, 'faltante' => max(0, round($req - (float) $l['stock'], 3)),
                     'costo_requerido' => round($req * (float) $l['costo_promedio'], 2)];
    }
    return $r;
}

function crear_orden_produccion(int $productoId, int $varianteId, int $cantidad, string $notas, int $usuarioId): array
{
    if ($cantidad < 1 || $cantidad > 10000) {
        throw new DomainException('La cantidad a fabricar debe estar entre 1 y 10.000 prendas.');
    }
    if (!valor('SELECT id FROM variantes_producto WHERE id = ? AND producto_id = ?', [$varianteId, $productoId])) {
        throw new DomainException('Selecciona una talla/color del producto.');
    }
    $ficha = ficha_producto($productoId);
    if (!$ficha || !$ficha['lineas']) {
        throw new DomainException('El producto no tiene ficha técnica. Créala antes de programar la producción.');
    }
    consulta('INSERT INTO ordenes_produccion (producto_id, variante_id, cantidad, notas, usuario_id,
                                              costo_materiales, costo_mano_obra, costo_indirecto, costo_total, costo_unitario)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $productoId, $varianteId, $cantidad, $notas !== '' ? $notas : null, $usuarioId,
        round($ficha['costo_materiales'] * $cantidad, 2), round((float) $ficha['costo_mano_obra'] * $cantidad, 2),
        round((float) $ficha['costos_indirectos'] * $cantidad, 2), round($ficha['costo_total'] * $cantidad, 2), $ficha['costo_total'],
    ]);
    $id = (int) db()->lastInsertId();
    $codigo = sprintf('OP-%06d', $id);
    consulta('UPDATE ordenes_produccion SET codigo = ? WHERE id = ?', [$codigo, $id]);
    return ['id' => $id, 'codigo' => $codigo];
}

/**
 * Inicia la producción: descuenta TODOS los materiales de la ficha en una transacción.
 * Si falta cualquier material, no se descuenta nada (ROLLBACK) y se informa qué falta.
 */
function iniciar_orden_produccion(int $ordenId, int $usuarioId): void
{
    transaccion(static function () use ($ordenId, $usuarioId) {
        $o = fila('SELECT * FROM ordenes_produccion WHERE id = ? FOR UPDATE', [$ordenId]);
        if (!$o || $o['estado'] !== 'planificada') {
            throw new DomainException('Sólo se pueden iniciar órdenes planificadas.');
        }
        $ficha = ficha_producto((int) $o['producto_id']);
        if (!$ficha || !$ficha['lineas']) {
            throw new DomainException('El producto ya no tiene ficha técnica.');
        }
        $faltantes = [];
        foreach ($ficha['lineas'] as $l) {
            $stock = (float) valor('SELECT stock FROM materiales WHERE id = ? FOR UPDATE', [$l['material_id']]);
            $req = round((int) $o['cantidad'] * (float) $l['cantidad_real'], 3);
            if ($stock + 0.0005 < $req) {
                $faltantes[] = "{$l['nombre']}: faltan " . cantidad_fmt($req - $stock, $l['unidad']);
            }
        }
        if ($faltantes) {
            throw new DomainException('Materiales insuficientes. ' . implode(' · ', $faltantes) . '. Registra una compra antes de iniciar.');
        }
        $costoMat = 0.0;
        foreach ($ficha['lineas'] as $l) {
            $req = round((int) $o['cantidad'] * (float) $l['cantidad_real'], 3);
            $costoUnit = (float) valor('SELECT costo_promedio FROM materiales WHERE id = ?', [$l['material_id']]);
            $sub = round($req * $costoUnit, 2);
            $costoMat += $sub;
            consulta('INSERT INTO produccion_consumos (orden_id, material_id, cantidad, costo_unitario, subtotal) VALUES (?, ?, ?, ?, ?)',
                [$ordenId, $l['material_id'], $req, $costoUnit, $sub]);
            mover_material((int) $l['material_id'], 'consumo', -$req, null, "Consumo {$o['codigo']}", $usuarioId, null, $ordenId);
        }
        $mo  = round((float) $ficha['costo_mano_obra'] * (int) $o['cantidad'], 2);
        $ind = round((float) $ficha['costos_indirectos'] * (int) $o['cantidad'], 2);
        $tot = round($costoMat + $mo + $ind, 2);
        consulta("UPDATE ordenes_produccion SET estado = 'en_proceso', fecha_inicio = NOW(), costo_materiales = ?, costo_mano_obra = ?,
                  costo_indirecto = ?, costo_total = ?, costo_unitario = ? WHERE id = ?",
            [$costoMat, $mo, $ind, $tot, round($tot / (int) $o['cantidad'], 2), $ordenId]);
    });
}

/** Termina la producción: las prendas entran al inventario de la tienda. */
function terminar_orden_produccion(int $ordenId, int $usuarioId): void
{
    transaccion(static function () use ($ordenId, $usuarioId) {
        $o = fila('SELECT * FROM ordenes_produccion WHERE id = ? FOR UPDATE', [$ordenId]);
        if (!$o || $o['estado'] !== 'en_proceso') {
            throw new DomainException('Sólo se pueden terminar órdenes en proceso.');
        }
        mover_stock((int) $o['variante_id'], 'produccion', (int) $o['cantidad'], "Producción {$o['codigo']}", $usuarioId, null, $ordenId);
        consulta("UPDATE ordenes_produccion SET estado = 'terminada', fecha_fin = NOW() WHERE id = ?", [$ordenId]);
    });
}

/** Cancela una orden. Si estaba en proceso, los materiales vuelven al inventario. */
function cancelar_orden_produccion(int $ordenId, int $usuarioId): void
{
    transaccion(static function () use ($ordenId, $usuarioId) {
        $o = fila('SELECT * FROM ordenes_produccion WHERE id = ? FOR UPDATE', [$ordenId]);
        if (!$o || !in_array($o['estado'], ['planificada', 'en_proceso'], true)) {
            throw new DomainException('Sólo se pueden cancelar órdenes planificadas o en proceso.');
        }
        if ($o['estado'] === 'en_proceso') {
            foreach (filas('SELECT material_id, cantidad, costo_unitario FROM produccion_consumos WHERE orden_id = ?', [$ordenId]) as $c) {
                mover_material((int) $c['material_id'], 'devolucion', (float) $c['cantidad'], (float) $c['costo_unitario'],
                    "Cancelación {$o['codigo']}", $usuarioId, null, $ordenId);
            }
        }
        consulta("UPDATE ordenes_produccion SET estado = 'cancelada', fecha_fin = NOW() WHERE id = ?", [$ordenId]);
    });
}
