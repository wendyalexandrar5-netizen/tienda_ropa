<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lector mínimo de archivos .env (KEY=valor). Las variables ya definidas en
 * el entorno del sistema tienen prioridad sobre el archivo.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                continue;
            }
            if (preg_match('/^(["\'])(.*)\1$/s', $value, $m)) {
                $value = $m[2];
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === '') ? $default : (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value === null || !is_numeric($value)) ? $default : (int) $value;
    }
}
