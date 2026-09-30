<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Conexión única a MySQL mediante PDO.
 * - Excepciones ante cualquier error.
 * - Consultas preparadas reales (EMULATE_PREPARES = false) → protección SQL Injection.
 * - utf8mb4 para soportar tildes, ñ y emojis.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) $c['port'], $c['name'], $c['charset']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $e) {
        registrar_log('Error de conexión a la base de datos: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            throw $e;
        }
        mostrar_error(503, 'Servicio no disponible',
            'No fue posible conectar con la base de datos. Verifica que MySQL esté iniciado en XAMPP y que la base "tienda_ropa" haya sido importada.',
            es_desarrollo() ? $e->getMessage() : null);
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve el statement. */
function consulta(string $sql, array $parametros = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($parametros);
    return $st;
}

function fila(string $sql, array $parametros = []): ?array
{
    $r = consulta($sql, $parametros)->fetch();
    return $r === false ? null : $r;
}

function filas(string $sql, array $parametros = []): array
{
    return consulta($sql, $parametros)->fetchAll();
}

function valor(string $sql, array $parametros = [])
{
    $v = consulta($sql, $parametros)->fetchColumn();
    return $v === false ? null : $v;
}

/**
 * Ejecuta $fn dentro de una transacción: COMMIT si todo sale bien, ROLLBACK
 * ante cualquier excepción (que se vuelve a lanzar).
 */
function transaccion(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $resultado = $fn($pdo);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
