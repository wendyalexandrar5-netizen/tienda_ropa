<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\CurrentUser;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Acceso a PostgreSQL (Supabase) mediante PDO.
 *
 * Cada operación se ejecuta en una transacción donde se adopta el rol del
 * usuario de la petición, exactamente como lo hace la Data API de Supabase:
 *
 *   set_config('role', 'authenticated' | 'anon', true)
 *   set_config('request.jwt.claims', '<claims del JWT verificado>', true)
 *
 * De esta forma las políticas RLS y auth.uid() se aplican también a las
 * consultas del backend PHP (defensa en profundidad): aunque existiera un
 * error en un controlador, la base de datos no devolvería datos ajenos.
 *
 * Todas las consultas usan sentencias preparadas (sin concatenar entradas).
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $depth = 0;
    private ?CurrentUser $user = null;
    private bool $system = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function setUser(?CurrentUser $user): void
    {
        $this->user = $user;
    }

    public function user(): ?CurrentUser
    {
        return $this->user;
    }

    /**
     * Ejecuta $fn dentro de una transacción con el contexto de seguridad del
     * usuario actual. Las llamadas anidadas reutilizan la misma transacción.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            return $fn($this);
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $this->depth++;
        try {
            if (!$this->system) {
                $this->applySecurityContext($pdo);
            }
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    /**
     * Ejecuta $fn con el rol del propietario de la conexión (sin RLS).
     * USO RESTRINGIDO: rate limiting y scripts de mantenimiento. Nunca con
     * datos de la petición sin validar.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function asSystem(callable $fn): mixed
    {
        if ($this->depth > 0) {
            throw new \LogicException('asSystem() no puede anidarse dentro de una transacción de usuario.');
        }
        $this->system = true;
        try {
            return $this->transaction($fn);
        } finally {
            $this->system = false;
        }
    }

    /** @param array<string, mixed> $params */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->transaction(fn () => $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $params */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->transaction(function () use ($sql, $params) {
            $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        });
    }

    /** @param array<string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->transaction(function () use ($sql, $params) {
            // fetch(FETCH_NUM) distingue "sin filas" de un booleano false real.
            $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
            return $row === false ? null : $row[0];
        });
    }

    /**
     * Ejecuta una consulta cuya primera columna es JSON (json/jsonb) y la
     * devuelve decodificada. Útil para respuestas de API con tipos correctos
     * (numeric → número, arrays anidados) generadas por PostgreSQL.
     *
     * @param array<string, mixed> $params
     */
    public function fetchJson(string $sql, array $params = []): mixed
    {
        $value = $this->fetchValue($sql, $params);
        return $value === null ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->transaction(fn () => $this->run($sql, $params)->rowCount());
    }

    /** @param array<string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
                [$bound, $type] = match (true) {
                    $value === null => [null, PDO::PARAM_NULL],
                    is_bool($value) => [$value, PDO::PARAM_BOOL],
                    is_int($value) => [$value, PDO::PARAM_INT],
                    is_array($value) => [json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR],
                    default => [(string) $value, PDO::PARAM_STR],
                };
                $stmt->bindValue($name, $bound, $type);
            }
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            throw DbErrorMapper::map($e, $this->config->debug);
        }
    }

    private function applySecurityContext(PDO $pdo): void
    {
        $user = $this->user;
        $role = $user ? 'authenticated' : 'anon';
        $claims = $user ? $user->claims : ['role' => 'anon'];

        $stmt = $pdo->prepare(
            "select set_config('role', :role, true),
                    set_config('request.jwt.claims', :claims, true),
                    set_config('request.jwt.claim.sub', :sub, true),
                    set_config('request.jwt.claim.role', :role2, true)"
        );
        $stmt->execute([
            'role' => $role,
            'claims' => json_encode($claims, JSON_THROW_ON_ERROR),
            'sub' => $user?->id ?? '',
            'role2' => $role,
        ]);
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->config->databaseUrl === '') {
            throw new \RuntimeException('DATABASE_URL no está configurada.');
        }

        [$dsn, $user, $pass] = self::parseUrl($this->config->databaseUrl);

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => $this->config->dbEmulatePrepares,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            error_log('[db] No se pudo conectar: ' . $e->getMessage());
            throw new HttpException(503, 'SERVICIO_NO_DISPONIBLE', 'El servicio no está disponible en este momento.');
        }

        return $this->pdo;
    }

    /** @return array{0: string, 1: ?string, 2: ?string} */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new \RuntimeException('DATABASE_URL inválida (se espera postgresql://usuario:clave@host:puerto/bd).');
        }
        parse_str($parts['query'] ?? '', $query);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'] ?? 'localhost',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '/postgres', '/') ?: 'postgres',
        );
        if (isset($query['sslmode']) && is_string($query['sslmode'])) {
            $dsn .= ';sslmode=' . preg_replace('/[^a-z-]/', '', $query['sslmode']);
        }

        return [
            $dsn,
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
    }
}
