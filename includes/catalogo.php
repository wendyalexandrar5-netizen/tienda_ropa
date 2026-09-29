<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Consultas del catálogo público. Todos los filtros usan parámetros
 * preparados; el orden sólo acepta valores de una lista blanca.
 */

const ORDENES_CATALOGO = [
    'recientes'   => 'p.fecha_creacion DESC, p.id DESC',
    'precio_asc'  => 'p.precio ASC, p.id',
    'precio_desc' => 'p.precio DESC, p.id',
    'nombre'      => 'p.nombre ASC',
];

function escapar_like(string $texto): string
{
    return strtr($texto, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
}

function categorias_activas(): array
{
    return filas("SELECT c.id, c.nombre, c.descripcion, c.imagen,
                         (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id AND p.estado = 'activo') AS total_productos
                  FROM categorias c WHERE c.estado = 'activa' ORDER BY c.nombre");
}

function tallas_todas(): array
{
    return filas('SELECT id, nombre, orden FROM tallas ORDER BY orden, nombre');
}

function colores_todos(): array
{
    return filas('SELECT id, nombre, codigo_hex FROM colores ORDER BY nombre');
}

/**
 * Búsqueda paginada del catálogo.
 * @return array{items: array, pag: array}
 */
function buscar_productos(array $f, int $pagina = 1, int $porPagina = 12): array
{
    $where  = ["p.estado = 'activo'", "c.estado = 'activa'"];
    $params = [];

    if (($f['q'] ?? '') !== '') {
        $like = '%' . escapar_like($f['q']) . '%';
        $where[] = '(p.nombre LIKE ? OR p.descripcion LIKE ? OR c.nombre LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($f['categoria'])) {
        $where[]  = 'p.categoria_id = ?';
        $params[] = (int) $f['categoria'];
    }
    if (!empty($f['talla']) || !empty($f['color'])) {
        $sub = "EXISTS (SELECT 1 FROM variantes_producto v WHERE v.producto_id = p.id AND v.estado = 'activo' AND v.stock > 0";
        if (!empty($f['talla'])) {
            $sub .= ' AND v.talla_id = ?';
            $params[] = (int) $f['talla'];
        }
        if (!empty($f['color'])) {
            $sub .= ' AND v.color_id = ?';
            $params[] = (int) $f['color'];
        }
        $where[] = $sub . ')';
    }
    if (isset($f['precio_min']) && $f['precio_min'] !== '' && is_numeric($f['precio_min'])) {
        $where[]  = 'p.precio >= ?';
        $params[] = (float) $f['precio_min'];
    }
    if (isset($f['precio_max']) && $f['precio_max'] !== '' && is_numeric($f['precio_max'])) {
        $where[]  = 'p.precio <= ?';
        $params[] = (float) $f['precio_max'];
    }
    if (!empty($f['disponible'])) {
        $where[] = "EXISTS (SELECT 1 FROM variantes_producto v2 WHERE v2.producto_id = p.id AND v2.estado = 'activo' AND v2.stock > 0)";
    }

    $orden = ORDENES_CATALOGO[$f['orden'] ?? ''] ?? ORDENES_CATALOGO['recientes'];
    $sqlWhere = implode(' AND ', $where);

    $total = (int) valor("SELECT COUNT(*) FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE $sqlWhere", $params);
    $pag = paginar($total, $porPagina, $pagina);

    $items = filas("SELECT p.id, p.nombre, p.precio, p.imagen, p.destacado, p.categoria_id, c.nombre AS categoria
                    FROM productos p JOIN categorias c ON c.id = p.categoria_id
                    WHERE $sqlWhere ORDER BY $orden LIMIT {$pag['por_pagina']} OFFSET {$pag['offset']}", $params);

    return ['items' => adjuntar_variantes($items), 'pag' => $pag];
}

/**
 * Agrega a cada producto sus variantes activas, tallas, colores y stock total
 * (una sola consulta para toda la página, evitando el problema N+1).
 */
function adjuntar_variantes(array $productos): array
{
    if (!$productos) {
        return [];
    }
    $ids = array_column($productos, 'id');
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $vars = filas("SELECT v.id, v.producto_id, v.stock, t.id AS talla_id, t.nombre AS talla, t.orden,
                          co.id AS color_id, co.nombre AS color, co.codigo_hex
                   FROM variantes_producto v
                   JOIN tallas t   ON t.id = v.talla_id
                   JOIN colores co ON co.id = v.color_id
                   WHERE v.estado = 'activo' AND v.producto_id IN ($marcas)
                   ORDER BY t.orden, co.nombre", $ids);

    $porProducto = [];
    foreach ($vars as $v) {
        $porProducto[$v['producto_id']][] = $v;
    }
    foreach ($productos as &$p) {
        $lista = $porProducto[$p['id']] ?? [];
        $p['variantes']   = $lista;
        $p['stock_total'] = array_sum(array_column($lista, 'stock'));
        $p['tallas']  = array_values(array_unique(array_column($lista, 'talla')));
        $colores = [];
        foreach ($lista as $v) {
            $colores[$v['color']] = $v['codigo_hex'];
        }
        $p['colores'] = $colores;
    }
    unset($p);
    return $productos;
}

function producto_publico(int $id): ?array
{
    $p = fila("SELECT p.*, c.nombre AS categoria
               FROM productos p JOIN categorias c ON c.id = p.categoria_id
               WHERE p.id = ? AND p.estado = 'activo' AND c.estado = 'activa'", [$id]);
    if (!$p) {
        return null;
    }
    return adjuntar_variantes([$p])[0];
}

function productos_destacados(int $limite = 8): array
{
    return adjuntar_variantes(filas("SELECT p.id, p.nombre, p.precio, p.imagen, p.destacado, p.categoria_id, c.nombre AS categoria
        FROM productos p JOIN categorias c ON c.id = p.categoria_id
        WHERE p.estado = 'activo' AND c.estado = 'activa' AND p.destacado = 1
        ORDER BY p.fecha_creacion DESC LIMIT " . (int) $limite));
}

function productos_recientes(int $limite = 8): array
{
    return adjuntar_variantes(filas("SELECT p.id, p.nombre, p.precio, p.imagen, p.destacado, p.categoria_id, c.nombre AS categoria
        FROM productos p JOIN categorias c ON c.id = p.categoria_id
        WHERE p.estado = 'activo' AND c.estado = 'activa'
        ORDER BY p.fecha_creacion DESC, p.id DESC LIMIT " . (int) $limite));
}

function productos_relacionados(array $producto, int $limite = 4): array
{
    return adjuntar_variantes(filas("SELECT p.id, p.nombre, p.precio, p.imagen, p.destacado, p.categoria_id, c.nombre AS categoria
        FROM productos p JOIN categorias c ON c.id = p.categoria_id
        WHERE p.estado = 'activo' AND c.estado = 'activa' AND p.categoria_id = ? AND p.id <> ?
        ORDER BY RAND() LIMIT " . (int) $limite, [$producto['categoria_id'], $producto['id']]));
}
