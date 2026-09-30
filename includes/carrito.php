<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Carrito persistente en base de datos (uno por usuario).
 * Nunca se confía en precios o stock enviados por el navegador: el precio se
 * lee de `productos` y el stock de `variantes_producto` en cada operación.
 */

const MAX_UNIDADES_POR_ITEM = 20;

function carrito_id(int $usuarioId, bool $crear = true): ?int
{
    $id = valor('SELECT id FROM carritos WHERE usuario_id = ?', [$usuarioId]);
    if ($id === null && $crear) {
        consulta('INSERT IGNORE INTO carritos (usuario_id) VALUES (?)', [$usuarioId]);
        $id = valor('SELECT id FROM carritos WHERE usuario_id = ?', [$usuarioId]);
    }
    return $id === null ? null : (int) $id;
}

/** Ítems del carrito con precio y stock ACTUALES desde la base de datos. */
function carrito_items(int $usuarioId): array
{
    $items = filas("SELECT cd.id, cd.cantidad, cd.variante_id, cd.producto_id,
                           p.nombre, p.imagen, p.precio, p.estado AS producto_estado,
                           c.estado AS categoria_estado, c.nombre AS categoria,
                           v.stock, v.estado AS variante_estado, v.sku,
                           t.nombre AS talla, co.nombre AS color, co.codigo_hex
                    FROM carritos ca
                    JOIN carrito_detalle cd   ON cd.carrito_id = ca.id
                    JOIN productos p          ON p.id = cd.producto_id
                    JOIN categorias c         ON c.id = p.categoria_id
                    JOIN variantes_producto v ON v.id = cd.variante_id
                    JOIN tallas t             ON t.id = v.talla_id
                    JOIN colores co           ON co.id = v.color_id
                    WHERE ca.usuario_id = ?
                    ORDER BY cd.fecha_agregado, cd.id", [$usuarioId]);
    foreach ($items as &$i) {
        $i['disponible'] = $i['producto_estado'] === 'activo' && $i['variante_estado'] === 'activo'
            && $i['categoria_estado'] === 'activa' && (int) $i['stock'] > 0;
        $i['subtotal'] = (float) $i['precio'] * (int) $i['cantidad'];
    }
    unset($i);
    return $items;
}

function calcular_envio(float $subtotal): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }
    $gratisDesde = (float) ajuste('envio_gratis_desde', '0');
    if ($gratisDesde > 0 && $subtotal >= $gratisDesde) {
        return 0.0;
    }
    return (float) ajuste('costo_envio', '0');
}

/** @return array{subtotal: float, envio: float, total: float, unidades: int} */
function carrito_resumen(array $items): array
{
    $subtotal = 0.0;
    $unidades = 0;
    foreach ($items as $i) {
        if ($i['disponible']) {
            $subtotal += $i['subtotal'];
            $unidades += (int) $i['cantidad'];
        }
    }
    $envio = calcular_envio($subtotal);
    return ['subtotal' => $subtotal, 'envio' => $envio, 'total' => $subtotal + $envio, 'unidades' => $unidades];
}

function carrito_contar(int $usuarioId): int
{
    return (int) valor('SELECT COALESCE(SUM(cd.cantidad), 0) FROM carritos ca JOIN carrito_detalle cd ON cd.carrito_id = ca.id WHERE ca.usuario_id = ?', [$usuarioId]);
}

/**
 * Agrega una variante al carrito validando en el servidor que el producto esté
 * activo y que haya stock suficiente (incluyendo lo que ya estaba en el carrito).
 * @throws DomainException con un mensaje apto para el usuario.
 */
function carrito_agregar(int $usuarioId, int $varianteId, int $cantidad): void
{
    if ($cantidad < 1 || $cantidad > MAX_UNIDADES_POR_ITEM) {
        throw new DomainException('La cantidad debe estar entre 1 y ' . MAX_UNIDADES_POR_ITEM . '.');
    }
    $v = fila("SELECT v.id, v.producto_id, v.stock, p.precio, p.nombre
               FROM variantes_producto v
               JOIN productos p  ON p.id = v.producto_id
               JOIN categorias c ON c.id = p.categoria_id
               WHERE v.id = ? AND v.estado = 'activo' AND p.estado = 'activo' AND c.estado = 'activa'", [$varianteId]);
    if (!$v) {
        throw new DomainException('La combinación de talla y color seleccionada no está disponible.');
    }
    $carritoId = carrito_id($usuarioId);
    $enCarrito = (int) valor('SELECT cantidad FROM carrito_detalle WHERE carrito_id = ? AND variante_id = ?', [$carritoId, $varianteId]);
    $nueva = $enCarrito + $cantidad;

    if ((int) $v['stock'] <= 0) {
        throw new DomainException('Lo sentimos, esta variante está agotada.');
    }
    if ($nueva > (int) $v['stock']) {
        throw new DomainException("Sólo hay {$v['stock']} unidad(es) disponibles" . ($enCarrito ? " y ya tienes $enCarrito en tu carrito." : '.'));
    }
    if ($nueva > MAX_UNIDADES_POR_ITEM) {
        throw new DomainException('Puedes llevar máximo ' . MAX_UNIDADES_POR_ITEM . ' unidades por variante.');
    }
    consulta('INSERT INTO carrito_detalle (carrito_id, producto_id, variante_id, cantidad, precio_unitario)
              VALUES (?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad), precio_unitario = VALUES(precio_unitario)',
        [$carritoId, $v['producto_id'], $varianteId, $nueva, $v['precio']]);
}

function carrito_actualizar(int $usuarioId, int $itemId, int $cantidad): void
{
    $item = fila('SELECT cd.id, v.stock, v.estado FROM carrito_detalle cd
                  JOIN carritos ca ON ca.id = cd.carrito_id
                  JOIN variantes_producto v ON v.id = cd.variante_id
                  WHERE cd.id = ? AND ca.usuario_id = ?', [$itemId, $usuarioId]);
    if (!$item) {
        throw new DomainException('El producto ya no está en tu carrito.');
    }
    if ($cantidad <= 0) {
        carrito_eliminar($usuarioId, $itemId);
        return;
    }
    if ($cantidad > MAX_UNIDADES_POR_ITEM) {
        throw new DomainException('Puedes llevar máximo ' . MAX_UNIDADES_POR_ITEM . ' unidades por variante.');
    }
    if ($cantidad > (int) $item['stock']) {
        throw new DomainException("Sólo hay {$item['stock']} unidad(es) disponibles de esa variante.");
    }
    consulta('UPDATE carrito_detalle SET cantidad = ? WHERE id = ?', [$cantidad, $itemId]);
}

function carrito_eliminar(int $usuarioId, int $itemId): void
{
    consulta('DELETE cd FROM carrito_detalle cd JOIN carritos ca ON ca.id = cd.carrito_id
              WHERE cd.id = ? AND ca.usuario_id = ?', [$itemId, $usuarioId]);
}

function carrito_vaciar(int $usuarioId): void
{
    consulta('DELETE cd FROM carrito_detalle cd JOIN carritos ca ON ca.id = cd.carrito_id WHERE ca.usuario_id = ?', [$usuarioId]);
}

/**
 * Ajusta el carrito a la realidad del inventario: elimina ítems no disponibles
 * y reduce cantidades que superan el stock. Devuelve avisos para el usuario.
 */
function carrito_revisar(int $usuarioId): array
{
    $avisos = [];
    foreach (carrito_items($usuarioId) as $i) {
        $nombre = "{$i['nombre']} ({$i['talla']} / {$i['color']})";
        if (!$i['disponible']) {
            carrito_eliminar($usuarioId, (int) $i['id']);
            $avisos[] = "$nombre ya no está disponible y se retiró de tu carrito.";
        } elseif ((int) $i['cantidad'] > (int) $i['stock']) {
            consulta('UPDATE carrito_detalle SET cantidad = ? WHERE id = ?', [(int) $i['stock'], $i['id']]);
            $avisos[] = "Ajustamos $nombre a {$i['stock']} unidad(es) por disponibilidad de inventario.";
        }
    }
    return $avisos;
}
