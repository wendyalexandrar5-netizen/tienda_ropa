<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;

/**
 * Traduce errores de PostgreSQL a HttpException con mensajes seguros.
 *
 * Las funciones de la BD lanzan  RAISE EXCEPTION 'CODIGO' USING DETAIL = '…'
 * El código se usa como identificador estable para la API y el DETAIL como
 * mensaje para el usuario. Cualquier otro error se registra en el log y se
 * responde con un mensaje genérico (sin filtrar SQL ni estructura interna).
 */
final class DbErrorMapper
{
    private const BUSINESS_CODES = [
        'NO_AUTENTICADO' => 401,
        'ACCESO_DENEGADO' => 403,
        'USUARIO_BLOQUEADO' => 403,
        'OPERACION_NO_PERMITIDA' => 403,
        'PEDIDO_NO_ENCONTRADO' => 404,
        'VARIANTE_NO_ENCONTRADA' => 404,
        'STOCK_INSUFICIENTE' => 409,
        'PRODUCTO_NO_DISPONIBLE' => 409,
        'TRANSICION_INVALIDA' => 409,
        'CARRITO_VACIO' => 422,
        'CANTIDAD_INVALIDA' => 422,
        'DIRECCION_INVALIDA' => 422,
        'METODO_PAGO_INVALIDO' => 422,
        'PAGO_INSUFICIENTE' => 422,
        'DATOS_INVALIDOS' => 422,
    ];

    /** Mensajes amigables para restricciones conocidas. */
    private const CONSTRAINTS = [
        'categorias_nombre_unico' => 'Ya existe una categoría con ese nombre.',
        'categorias_slug_key' => 'Ya existe una categoría con esa URL (slug).',
        'productos_slug_key' => 'Ya existe un producto con esa URL (slug).',
        'variantes_producto_sku_key' => 'Ya existe una variante con ese SKU.',
        'variantes_producto_combinacion_unica' => 'Ese producto ya tiene una variante con esa talla y color.',
        'tallas_codigo_key' => 'Ya existe una talla con ese código.',
        'colores_nombre_key' => 'Ya existe un color con ese nombre.',
        'carrito_detalle_cantidad_check' => 'La cantidad debe estar entre 1 y 20 unidades por producto.',
        'favoritos_pkey' => 'El producto ya está en tus favoritos.',
        'productos_precio_anterior_check' => 'El precio anterior debe ser mayor que el precio actual.',
    ];

    public static function map(PDOException $e, bool $debug = false): HttpException
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $raw = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if (preg_match('/ERROR:\s+([A-Z_]{4,40})\s*(?:\n|$)/', $raw, $m) && isset(self::BUSINESS_CODES[$m[1]])) {
            $detail = preg_match('/DETAIL:\s+(.+)/', $raw, $d) ? trim($d[1]) : 'No se pudo completar la operación.';
            return new HttpException(self::BUSINESS_CODES[$m[1]], $m[1], $detail);
        }

        $constraint = preg_match('/constraint "([a-z0-9_]+)"/', $raw, $c) ? $c[1] : null;

        return match ($sqlState) {
            '42501' => new HttpException(403, 'ACCESO_DENEGADO', 'No tienes permiso para realizar esta acción.'),
            '23505' => new HttpException(409, 'DUPLICADO', self::CONSTRAINTS[$constraint] ?? 'Ya existe un registro con esos datos.'),
            '23503' => str_contains($raw, 'still referenced')
                ? new HttpException(409, 'EN_USO', 'No se puede eliminar porque está siendo utilizado por otros registros.')
                : new HttpException(422, 'REFERENCIA_INVALIDA', 'Uno de los elementos referenciados no existe.'),
            '23514' => new HttpException(422, 'DATOS_INVALIDOS', self::CONSTRAINTS[$constraint] ?? 'Algún dato no cumple las reglas permitidas.'),
            '23502' => new HttpException(422, 'DATOS_INVALIDOS', 'Falta un dato obligatorio.'),
            '22P02', '22003', '22001', '22007', '22008' => new HttpException(422, 'DATOS_INVALIDOS', 'Formato de datos inválido.'),
            default => self::internal($e, $debug),
        };
    }

    private static function internal(PDOException $e, bool $debug): HttpException
    {
        error_log('[db] ' . $e->getMessage());
        return new HttpException(500, 'ERROR_INTERNO', $debug ? $e->getMessage() : 'Ocurrió un error inesperado. Inténtalo de nuevo.', [], $e);
    }
}
