<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Inventario: toda modificación de stock pasa por mover_stock(), que bloquea
 * la fila (SELECT ... FOR UPDATE), impide stock negativo y registra el
 * movimiento en el kardex (movimientos_inventario).
 */

const TIPOS_MOVIMIENTO = [
    'entrada'    => 'Entrada',
    'salida'     => 'Salida',
    'ajuste'     => 'Ajuste',
    'venta'      => 'Venta',
    'devolucion' => 'Devolución',
];

function umbral_stock_bajo(): int
{
    return max(0, (int) ajuste('umbral_stock_bajo', '5'));
}

/**
 * Debe llamarse DENTRO de una transacción.
 * @return int stock resultante
 * @throws DomainException si el stock quedaría negativo.
 */
function mover_stock(int $varianteId, string $tipo, int $delta, ?string $motivo, ?int $usuarioId, ?int $pedidoId = null): int
{
    if (!db()->inTransaction()) {
        throw new LogicException('mover_stock() requiere una transacción activa.');
    }
    if (!isset(TIPOS_MOVIMIENTO[$tipo])) {
        throw new InvalidArgumentException('Tipo de movimiento inválido.');
    }
    $actual = valor('SELECT stock FROM variantes_producto WHERE id = ? FOR UPDATE', [$varianteId]);
    if ($actual === null) {
        throw new DomainException('La variante no existe.');
    }
    $nuevo = (int) $actual + $delta;
    if ($nuevo < 0) {
        throw new DomainException('Stock insuficiente: hay ' . (int) $actual . ' unidad(es) disponibles.');
    }
    consulta('UPDATE variantes_producto SET stock = ? WHERE id = ?', [$nuevo, $varianteId]);
    consulta('INSERT INTO movimientos_inventario (variante_id, tipo, cantidad, stock_resultante, motivo, usuario_id, pedido_id)
              VALUES (?, ?, ?, ?, ?, ?, ?)', [$varianteId, $tipo, $delta, $nuevo, $motivo, $usuarioId, $pedidoId]);
    return $nuevo;
}

/**
 * Ajuste manual desde el panel.
 *  - entrada: suma unidades · salida: resta unidades · ajuste: fija el stock exacto (conteo físico).
 */
function ajustar_stock(int $varianteId, string $tipo, int $cantidad, string $motivo, int $usuarioId): int
{
    if (!in_array($tipo, ['entrada', 'salida', 'ajuste'], true) || $cantidad < 0 || $cantidad > 100000) {
        throw new DomainException('Datos de ajuste inválidos.');
    }
    return transaccion(static function () use ($varianteId, $tipo, $cantidad, $motivo, $usuarioId) {
        if ($tipo === 'ajuste') {
            $actual = valor('SELECT stock FROM variantes_producto WHERE id = ? FOR UPDATE', [$varianteId]);
            if ($actual === null) {
                throw new DomainException('La variante no existe.');
            }
            $delta = $cantidad - (int) $actual;
        } else {
            if ($cantidad === 0) {
                throw new DomainException('La cantidad debe ser mayor que cero.');
            }
            $delta = $tipo === 'entrada' ? $cantidad : -$cantidad;
        }
        return mover_stock($varianteId, $tipo, $delta, $motivo !== '' ? $motivo : null, $usuarioId);
    });
}
