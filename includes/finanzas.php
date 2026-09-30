<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Finanzas: ventas, costos, gastos, utilidad, rentabilidad y flujo de dinero.
 *
 * Conceptos (explicados también en la pantalla de Finanzas):
 *  - Ventas: total de los pedidos no cancelados (incluye envío), por fecha del pedido.
 *  - Costo de lo vendido: unidades vendidas × costo de fabricación congelado en cada venta.
 *  - Utilidad bruta = Ventas − Costo de lo vendido.
 *  - Utilidad estimada = Utilidad bruta − Gastos (operativos + mantenimiento + otros).
 *    La mano de obra directa ya está dentro del costo de cada prenda (ficha técnica),
 *    por eso no se registra otra vez como gasto.
 *  - Flujo de dinero = Dinero que entró (pedidos entregados/cobrados) − Dinero que salió
 *    (compras de materiales + gastos pagados). Comprar tela es una SALIDA de dinero pero
 *    no es todavía un costo: se vuelve costo cuando la prenda fabricada se vende.
 */

const GRUPOS_GASTO = [
    'operativo'     => 'Gastos operativos',
    'mantenimiento' => 'Mantenimiento',
    'otro'          => 'Otros gastos',
];

const METODOS_PAGO_GASTO = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'tarjeta' => 'Tarjeta'];

const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

function es_fecha_valida(string $f): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4));
}

function nombre_mes(string $fecha): string
{
    $t = strtotime($fecha);
    return MESES[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/**
 * Periodo seleccionado en la URL: ?periodo=mes|mes_anterior|30d|anio|rango (&desde=&hasta=).
 * @return array{clave:string, desde:string, hasta:string, etiqueta:string}
 */
function periodo_seleccionado(string $defecto = 'mes'): array
{
    $clave = get_texto('periodo', 15) ?: $defecto;
    $hoy = date('Y-m-d');
    switch ($clave) {
        case 'mes_anterior':
            $desde = date('Y-m-01', strtotime('first day of last month'));
            $hasta = date('Y-m-t', strtotime('first day of last month'));
            $etiqueta = ucfirst(nombre_mes($desde));
            break;
        case '30d':
            $desde = date('Y-m-d', strtotime('-29 days'));
            $hasta = $hoy;
            $etiqueta = 'Últimos 30 días';
            break;
        case 'anio':
            $desde = date('Y-01-01');
            $hasta = $hoy;
            $etiqueta = 'Año ' . date('Y');
            break;
        case 'rango':
            $desde = get_texto('desde', 10);
            $hasta = get_texto('hasta', 10);
            if (es_fecha_valida($desde) && es_fecha_valida($hasta)) {
                if ($desde > $hasta) {
                    [$desde, $hasta] = [$hasta, $desde];
                }
                $etiqueta = fecha($desde, false) . ' – ' . fecha($hasta, false);
                break;
            }
            // rango inválido: se usa el mes actual
        default:
            $clave = 'mes';
            $desde = date('Y-m-01');
            $hasta = $hoy;
            $etiqueta = ucfirst(nombre_mes($desde));
    }
    return ['clave' => $clave, 'desde' => $desde, 'hasta' => $hasta, 'etiqueta' => $etiqueta];
}

/** Indicadores principales del periodo. */
function resumen_financiero(string $desde, string $hasta): array
{
    $r = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

    $ventas = fila("SELECT COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(subtotal), 0) AS productos,
                           COALESCE(SUM(costo_envio), 0) AS envios
                    FROM pedidos WHERE estado <> 'cancelado' AND fecha_pedido BETWEEN ? AND ?", $r);
    $costo = fila("SELECT COALESCE(SUM(d.cantidad * COALESCE(d.costo_unitario, 0)), 0) AS costo, COALESCE(SUM(d.cantidad), 0) AS unidades,
                          SUM(d.costo_unitario IS NULL) AS sin_costo
                   FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
                   WHERE p.estado <> 'cancelado' AND p.fecha_pedido BETWEEN ? AND ?", $r);

    $gastosGrupo = array_fill_keys(array_keys(GRUPOS_GASTO), 0.0);
    foreach (filas('SELECT c.grupo, SUM(g.monto) AS total FROM gastos g JOIN categorias_gasto c ON c.id = g.categoria_id
                    WHERE g.fecha BETWEEN ? AND ? GROUP BY c.grupo', [$desde, $hasta]) as $g) {
        $gastosGrupo[$g['grupo']] = (float) $g['total'];
    }
    $gastos = array_sum($gastosGrupo);

    $compras = (float) valor("SELECT COALESCE(SUM(total), 0) FROM compras_material WHERE estado = 'recibida' AND fecha BETWEEN ? AND ?", [$desde, $hasta]);
    $consumo = fila("SELECT COALESCE(SUM(c.subtotal), 0) AS valor FROM produccion_consumos c JOIN ordenes_produccion o ON o.id = c.orden_id
                     WHERE o.estado IN ('en_proceso', 'terminada') AND o.fecha_inicio BETWEEN ? AND ?", $r);
    $produccion = fila("SELECT COUNT(*) AS ordenes, COALESCE(SUM(cantidad), 0) AS prendas, COALESCE(SUM(costo_total), 0) AS costo
                        FROM ordenes_produccion WHERE estado = 'terminada' AND fecha_fin BETWEEN ? AND ?", $r);

    // Dinero que entró: pedidos cuya entrega (cobro contra entrega) ocurrió en el periodo
    $cobrado = (float) valor("SELECT COALESCE(SUM(p.total), 0) FROM pedidos p
                              JOIN pedido_historial h ON h.pedido_id = p.id AND h.estado_nuevo = 'entregado'
                              WHERE p.estado = 'entregado' AND h.fecha BETWEEN ? AND ?", $r);

    $totalVentas = (float) $ventas['total'];
    $costoVentas = (float) $costo['costo'];
    $utilidadBruta = $totalVentas - $costoVentas;
    $utilidad = $utilidadBruta - $gastos;

    return [
        'pedidos'          => (int) $ventas['pedidos'],
        'ventas'           => $totalVentas,
        'ventas_productos' => (float) $ventas['productos'],
        'envios'           => (float) $ventas['envios'],
        'unidades'         => (int) $costo['unidades'],
        'costo_ventas'     => $costoVentas,
        'lineas_sin_costo' => (int) $costo['sin_costo'],
        'utilidad_bruta'   => $utilidadBruta,
        'margen_bruto'     => $totalVentas > 0 ? $utilidadBruta / $totalVentas * 100 : 0.0,
        'gastos_grupo'     => $gastosGrupo,
        'gastos'           => $gastos,
        'utilidad'         => $utilidad,
        'margen_neto'      => $totalVentas > 0 ? $utilidad / $totalVentas * 100 : 0.0,
        'compras'          => $compras,
        'consumo'          => (float) $consumo['valor'],
        'produccion'       => $produccion,
        'cobrado'          => $cobrado,
        'egresos'          => $compras + $gastos,
        'flujo_neto'       => $cobrado - $compras - $gastos,
    ];
}

/** ¿En qué se gastó? Compras por tipo de material y gastos por categoría. */
function egresos_detallados(string $desde, string $hasta): array
{
    return [
        'compras_tipo' => filas("SELECT t.nombre, t.icono, SUM(d.subtotal) AS total
                                 FROM compra_detalle d JOIN compras_material c ON c.id = d.compra_id
                                 JOIN materiales m ON m.id = d.material_id JOIN tipos_material t ON t.id = m.tipo_id
                                 WHERE c.estado = 'recibida' AND c.fecha BETWEEN ? AND ?
                                 GROUP BY t.id, t.nombre, t.icono ORDER BY total DESC", [$desde, $hasta]),
        'gastos_categoria' => filas('SELECT c.nombre, c.grupo, SUM(g.monto) AS total, COUNT(*) AS registros
                                     FROM gastos g JOIN categorias_gasto c ON c.id = g.categoria_id
                                     WHERE g.fecha BETWEEN ? AND ? GROUP BY c.id, c.nombre, c.grupo ORDER BY total DESC', [$desde, $hasta]),
    ];
}

/** Rentabilidad por producto vendido en el periodo. */
function rentabilidad_productos(string $desde, string $hasta): array
{
    return filas("SELECT d.producto_id, pr.nombre, c.nombre AS categoria, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS ingresos,
                         SUM(d.cantidad * COALESCE(d.costo_unitario, 0)) AS costo, SUM(d.costo_unitario IS NULL) AS sin_costo,
                         SUM(d.subtotal) - SUM(d.cantidad * COALESCE(d.costo_unitario, 0)) AS utilidad
                  FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
                  JOIN productos pr ON pr.id = d.producto_id JOIN categorias c ON c.id = pr.categoria_id
                  WHERE p.estado <> 'cancelado' AND p.fecha_pedido BETWEEN ? AND ?
                  GROUP BY d.producto_id, pr.nombre, c.nombre ORDER BY utilidad DESC",
        [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
}

/** Costo de fabricación por producto (fichas) y promedio por categoría: "¿cuánto cuesta fabricar un pantalón?". */
function costos_por_producto(): array
{
    $costos = costos_fabricacion();
    $productos = filas('SELECT p.id, p.nombre, p.precio, p.estado, c.id AS categoria_id, c.nombre AS categoria,
                               f.costo_mano_obra, f.costos_indirectos, f.tiempo_minutos,
                               (SELECT AVG(o.costo_unitario) FROM ordenes_produccion o WHERE o.producto_id = p.id AND o.estado = \'terminada\') AS costo_real
                        FROM productos p JOIN categorias c ON c.id = p.categoria_id
                        LEFT JOIN fichas_tecnicas f ON f.producto_id = p.id
                        ORDER BY c.nombre, p.nombre');
    $porCategoria = [];
    foreach ($productos as &$p) {
        $p['costo'] = $costos[(int) $p['id']] ?? null;
        $p['margen'] = $p['costo'] !== null ? (float) $p['precio'] - $p['costo'] : null;
        $p['margen_pct'] = $p['costo'] !== null && (float) $p['precio'] > 0 ? $p['margen'] / (float) $p['precio'] * 100 : null;
        if ($p['costo'] !== null) {
            $c = &$porCategoria[$p['categoria']];
            $c['productos'] = ($c['productos'] ?? 0) + 1;
            $c['costo'] = ($c['costo'] ?? 0) + $p['costo'];
            $c['precio'] = ($c['precio'] ?? 0) + (float) $p['precio'];
            unset($c);
        }
    }
    unset($p);
    foreach ($porCategoria as $nombre => $c) {
        $porCategoria[$nombre]['costo_promedio'] = $c['costo'] / $c['productos'];
        $porCategoria[$nombre]['precio_promedio'] = $c['precio'] / $c['productos'];
        $porCategoria[$nombre]['margen_pct'] = $c['precio'] > 0 ? ($c['precio'] - $c['costo']) / $c['precio'] * 100 : 0;
    }
    return ['productos' => $productos, 'categorias' => $porCategoria];
}

/** Inventario de materias primas valorizado por tipo. */
function inventario_materiales_resumen(): array
{
    return filas("SELECT t.id, t.nombre, t.icono, COUNT(m.id) AS materiales, COALESCE(SUM(m.stock * m.costo_promedio), 0) AS valor,
                         SUM(m.stock <= m.stock_minimo) AS bajos
                  FROM tipos_material t LEFT JOIN materiales m ON m.tipo_id = t.id AND m.estado = 'activo'
                  GROUP BY t.id, t.nombre, t.icono ORDER BY t.id");
}

/** Flujo de dinero de los últimos $meses meses (incluido el actual). */
function flujo_mensual(int $meses = 6): array
{
    $datos = [];
    for ($i = $meses - 1; $i >= 0; $i--) {
        $ini = date('Y-m-01', strtotime("first day of -$i month"));
        $fin = date('Y-m-t', strtotime($ini));
        $datos[date('Y-m', strtotime($ini))] = ['mes' => ucfirst(MESES[(int) date('n', strtotime($ini))]) . ' ' . date('y', strtotime($ini)),
            'desde' => $ini, 'hasta' => $fin, 'ventas' => 0.0, 'cobrado' => 0.0, 'compras' => 0.0, 'gastos' => 0.0, 'costo_ventas' => 0.0];
    }
    $primero = reset($datos)['desde'];
    $agrupar = static function (string $sql, string $campo, array $params) use (&$datos) {
        foreach (filas($sql, $params) as $f) {
            if (isset($datos[$f['mes']])) {
                $datos[$f['mes']][$campo] = (float) $f['total'];
            }
        }
    };
    $agrupar("SELECT DATE_FORMAT(fecha_pedido, '%Y-%m') AS mes, SUM(total) AS total FROM pedidos
              WHERE estado <> 'cancelado' AND fecha_pedido >= ? GROUP BY mes", 'ventas', [$primero]);
    $agrupar("SELECT DATE_FORMAT(p.fecha_pedido, '%Y-%m') AS mes, SUM(d.cantidad * COALESCE(d.costo_unitario, 0)) AS total
              FROM pedido_detalle d JOIN pedidos p ON p.id = d.pedido_id
              WHERE p.estado <> 'cancelado' AND p.fecha_pedido >= ? GROUP BY mes", 'costo_ventas', [$primero]);
    $agrupar("SELECT DATE_FORMAT(h.fecha, '%Y-%m') AS mes, SUM(p.total) AS total FROM pedidos p
              JOIN pedido_historial h ON h.pedido_id = p.id AND h.estado_nuevo = 'entregado'
              WHERE p.estado = 'entregado' AND h.fecha >= ? GROUP BY mes", 'cobrado', [$primero]);
    $agrupar("SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(total) AS total FROM compras_material
              WHERE estado = 'recibida' AND fecha >= ? GROUP BY mes", 'compras', [$primero]);
    $agrupar("SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(monto) AS total FROM gastos WHERE fecha >= ? GROUP BY mes", 'gastos', [$primero]);
    foreach ($datos as &$d) {
        $d['neto'] = $d['cobrado'] - $d['compras'] - $d['gastos'];
        $d['utilidad'] = $d['ventas'] - $d['costo_ventas'] - $d['gastos'];
    }
    unset($d);
    // Se omiten los primeros meses sin ningún movimiento (p. ej. antes de empezar a usar el sistema)
    $datos = array_values($datos);
    while (count($datos) > 1 && $datos[0]['ventas'] == 0 && $datos[0]['compras'] == 0 && $datos[0]['gastos'] == 0 && $datos[0]['cobrado'] == 0) {
        array_shift($datos);
    }
    return $datos;
}

function porcentaje(float|int|null $valor, int $decimales = 1): string
{
    return $valor === null ? '—' : number_format((float) $valor, $decimales, ',', '.') . "\u{00A0}%";
}
