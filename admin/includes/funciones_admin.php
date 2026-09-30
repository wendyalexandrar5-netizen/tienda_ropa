<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/** Utilidades exclusivas del panel. */

function generar_sku(int $productoId, int $tallaId, int $colorId): string
{
    $talla = (string) valor('SELECT nombre FROM tallas WHERE id = ?', [$tallaId]);
    $color = (string) valor('SELECT nombre FROM colores WHERE id = ?', [$colorId]);
    $limpiar = static function (string $t, int $n): string {
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t)), 0, $n) ?: 'X';
    };
    $base = sprintf('FC-%03d-%s-%s', $productoId, $limpiar($talla, 3), $limpiar($color, 3));
    $sku = $base;
    $n = 2;
    while (valor('SELECT id FROM variantes_producto WHERE sku = ?', [$sku])) {
        $sku = $base . '-' . $n++;
    }
    return $sku;
}

/**
 * Valida y normaliza los datos del formulario de producto.
 * @return array{0: array, 1: array} [datos, errores]
 */
function validar_producto_post(): array
{
    $d = [
        'categoria_id' => post_int('categoria_id'),
        'nombre'       => post_texto('nombre', 120),
        'descripcion'  => post_texto('descripcion', 2000),
        'precio'       => post_texto('precio', 12),
        'destacado'    => isset($_POST['destacado']) ? 1 : 0,
        'estado'       => post_texto('estado', 10),
    ];
    $v = (new Validador())
        ->requerido('nombre', $d['nombre'], 'El nombre')->longitud('nombre', $d['nombre'], 3, 120, 'El nombre')
        ->requerido('precio', $d['precio'], 'El precio')->numero('precio', $d['precio'], 100, 100000000, 'El precio')
        ->longitud('descripcion', $d['descripcion'], 0, 2000, 'La descripción')
        ->enLista('estado', $d['estado'], ['activo', 'inactivo'], 'El estado');
    if (!valor('SELECT id FROM categorias WHERE id = ?', [$d['categoria_id']])) {
        $v->agregar('categoria_id', 'Selecciona una categoría válida.');
    }
    return [$d, $v->errores()];
}

function categorias_todas(): array
{
    return filas('SELECT id, nombre, estado FROM categorias ORDER BY nombre');
}

/** Crea una variante con su movimiento de "inventario inicial" (dentro de una transacción). */
function crear_variante(int $productoId, int $tallaId, int $colorId, int $stock, int $usuarioId): int
{
    if ($stock < 0 || $stock > 100000) {
        throw new DomainException('El stock inicial no es válido.');
    }
    if (!valor('SELECT id FROM tallas WHERE id = ?', [$tallaId]) || !valor('SELECT id FROM colores WHERE id = ?', [$colorId])) {
        throw new DomainException('Talla o color inválido.');
    }
    if (valor('SELECT id FROM variantes_producto WHERE producto_id = ? AND talla_id = ? AND color_id = ?', [$productoId, $tallaId, $colorId])) {
        throw new DomainException('Esa combinación de talla y color ya existe para este producto.');
    }
    consulta('INSERT INTO variantes_producto (producto_id, talla_id, color_id, sku, stock) VALUES (?, ?, ?, ?, 0)',
        [$productoId, $tallaId, $colorId, generar_sku($productoId, $tallaId, $colorId)]);
    $id = (int) db()->lastInsertId();
    if ($stock > 0) {
        mover_stock($id, 'entrada', $stock, 'Inventario inicial', $usuarioId);
    }
    return $id;
}
