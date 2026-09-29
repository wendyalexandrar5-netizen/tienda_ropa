<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validación del lado del servidor. Nunca se confía en la validación del
 * navegador: todo dato de entrada pasa por aquí antes de llegar a la BD.
 *
 *   $v = new Validator($request->input());
 *   $nombre = $v->string('nombre', min: 2, max: 80);
 *   $cantidad = $v->int('cantidad', min: 1, max: 20);
 *   $v->check();   // lanza HttpException 422 con los errores por campo
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data) && $this->data[$field] !== null && $this->data[$field] !== '';
    }

    public function string(string $field, bool $required = true, int $min = 0, int $max = 255, ?string $pattern = null, bool $plain = true, string $label = ''): ?string
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $this->errors[$field] = "El campo $label es obligatorio.";
            }
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $this->errors[$field] = "El campo $label no es válido.";
            return null;
        }
        // Normaliza: quita caracteres de control (excepto saltos de línea) y espacios extremos.
        $value = trim((string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value));
        if (!mb_check_encoding($value, 'UTF-8')) {
            $this->errors[$field] = "El campo $label contiene caracteres inválidos.";
            return null;
        }
        $len = mb_strlen($value);
        if ($len < $min) {
            $this->errors[$field] = "El campo $label debe tener al menos $min caracteres.";
        } elseif ($len > $max) {
            $this->errors[$field] = "El campo $label no puede superar $max caracteres.";
        } elseif ($plain && preg_match('/[<>]/', $value)) {
            $this->errors[$field] = "El campo $label no puede contener los caracteres < o >.";
        } elseif ($pattern !== null && !preg_match($pattern, $value)) {
            $this->errors[$field] = "El campo $label tiene un formato inválido.";
        }
        return $value;
    }

    public function email(string $field = 'email', bool $required = true): ?string
    {
        $value = $this->string($field, $required, 3, 254, null, true, 'correo electrónico');
        if ($value !== null && !isset($this->errors[$field]) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'El correo electrónico no es válido.';
        }
        return $value !== null ? mb_strtolower($value) : null;
    }

    public function password(string $field = 'password', bool $checkStrength = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value) || $value === '') {
            $this->errors[$field] = 'La contraseña es obligatoria.';
            return null;
        }
        if (strlen($value) > 72) {
            $this->errors[$field] = 'La contraseña no puede superar 72 caracteres.';
        } elseif ($checkStrength && (strlen($value) < 8 || !preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value))) {
            $this->errors[$field] = 'La contraseña debe tener al menos 8 caracteres e incluir letras y números.';
        }
        return $value;
    }

    public function int(string $field, bool $required = true, ?int $min = null, ?int $max = null, string $label = ''): ?int
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "El campo $label es obligatorio.";
            }
            return null;
        }
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->errors[$field] = "El campo $label debe ser un número entero.";
            return null;
        }
        $int = (int) $value;
        if ($min !== null && $int < $min) {
            $this->errors[$field] = "El campo $label debe ser mayor o igual a $min.";
        } elseif ($max !== null && $int > $max) {
            $this->errors[$field] = "El campo $label debe ser menor o igual a $max.";
        }
        return $int;
    }

    public function decimal(string $field, bool $required = true, float $min = 0, float $max = 9_999_999_999, string $label = ''): ?float
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "El campo $label es obligatorio.";
            }
            return null;
        }
        if (is_bool($value) || !is_numeric($value) || !preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
            $this->errors[$field] = "El campo $label debe ser un valor numérico positivo con máximo 2 decimales.";
            return null;
        }
        $num = (float) $value;
        if ($num < $min || $num > $max) {
            $this->errors[$field] = "El campo $label está fuera del rango permitido.";
        }
        return $num;
    }

    /** @param list<string> $allowed */
    public function enum(string $field, array $allowed, bool $required = true, string $label = ''): ?string
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "El campo $label es obligatorio.";
            }
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->errors[$field] = "El valor de $label no es válido.";
            return null;
        }
        return $value;
    }

    public function uuid(string $field, bool $required = true, string $label = ''): ?string
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "El campo $label es obligatorio.";
            }
            return null;
        }
        if (!is_string($value) || !self::isUuid($value)) {
            $this->errors[$field] = "El campo $label no es válido.";
            return null;
        }
        return strtolower($value);
    }

    public function bool(string $field, bool $default = false): bool
    {
        if (!array_key_exists($field, $this->data)) {
            return $default;
        }
        $value = $this->data[$field];
        return $value === true || in_array($value, [1, '1', 'true', 'on', 'yes', 'si'], true);
    }

    /** @return list<int> */
    public function intList(string $field, int $maxItems = 50): array
    {
        $value = $this->data[$field] ?? [];
        if (!is_array($value)) {
            $this->errors[$field] = "El campo $field debe ser una lista.";
            return [];
        }
        if (count($value) > $maxItems) {
            $this->errors[$field] = "Demasiados elementos en $field.";
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (filter_var($item, FILTER_VALIDATE_INT) === false) {
                $this->errors[$field] = "El campo $field contiene valores inválidos.";
                return [];
            }
            $out[] = (int) $item;
        }
        return $out;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function check(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    public static function isPositiveInt(string $value): bool
    {
        return (bool) preg_match('/^[1-9]\d{0,17}$/', $value);
    }
}
