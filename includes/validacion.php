<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Validación del lado del servidor. Acumula los errores por campo para
 * mostrarlos junto a cada input del formulario.
 */
class Validador
{
    private array $errores = [];

    public function requerido(string $campo, $valor, string $etiqueta): self
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            $this->agregar($campo, "$etiqueta es obligatorio.");
        }
        return $this;
    }

    public function longitud(string $campo, string $valor, int $min, int $max, string $etiqueta): self
    {
        $n = mb_strlen($valor);
        if ($valor !== '' && ($n < $min || $n > $max)) {
            $this->agregar($campo, "$etiqueta debe tener entre $min y $max caracteres.");
        }
        return $this;
    }

    public function nombrePersona(string $campo, string $valor, string $etiqueta): self
    {
        if ($valor !== '' && !preg_match("/^[\p{L}][\p{L} '\-.]*$/u", $valor)) {
            $this->agregar($campo, "$etiqueta sólo puede contener letras, espacios y guiones.");
        }
        return $this;
    }

    public function email(string $campo, string $valor): self
    {
        if ($valor !== '' && (filter_var($valor, FILTER_VALIDATE_EMAIL) === false || mb_strlen($valor) > 120)) {
            $this->agregar($campo, 'Ingresa un correo electrónico válido.');
        }
        return $this;
    }

    public function telefono(string $campo, string $valor): self
    {
        if ($valor !== '' && !preg_match('/^\+?[0-9 ]{7,20}$/', $valor)) {
            $this->agregar($campo, 'Ingresa un teléfono válido (7 a 20 dígitos).');
        }
        return $this;
    }

    public function password(string $campo, string $valor): self
    {
        foreach (errores_politica_password($valor) as $msg) {
            $this->agregar($campo, $msg);
        }
        return $this;
    }

    public function coincide(string $campo, string $a, string $b, string $mensaje): self
    {
        if ($a !== $b) {
            $this->agregar($campo, $mensaje);
        }
        return $this;
    }

    public function numero(string $campo, $valor, float $min, float $max, string $etiqueta): self
    {
        if ($valor === '' || $valor === null) {
            return $this;
        }
        if (!is_numeric($valor) || (float) $valor < $min || (float) $valor > $max) {
            $this->agregar($campo, "$etiqueta debe ser un número entre " . number_format($min, 0, ',', '.') . ' y ' . number_format($max, 0, ',', '.') . '.');
        }
        return $this;
    }

    public function enLista(string $campo, $valor, array $permitidos, string $etiqueta): self
    {
        if (!in_array($valor, $permitidos, true)) {
            $this->agregar($campo, "$etiqueta no es válido.");
        }
        return $this;
    }

    public function agregar(string $campo, string $mensaje): self
    {
        $this->errores[$campo][] = $mensaje;
        return $this;
    }

    public function ok(): bool
    {
        return $this->errores === [];
    }

    public function errores(): array
    {
        return $this->errores;
    }
}

/** Política mínima de contraseñas. Devuelve la lista de requisitos incumplidos. */
function errores_politica_password(string $password): array
{
    $e = [];
    if (strlen($password) < 8) {
        $e[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (strlen($password) > 72) {
        $e[] = 'La contraseña no puede superar 72 caracteres.';
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
        $e[] = 'La contraseña debe combinar mayúsculas y minúsculas.';
    }
    if (!preg_match('/\d/', $password)) {
        $e[] = 'La contraseña debe incluir al menos un número.';
    }
    return $e;
}

/** HTML de los errores de un campo (para usar bajo cada input). */
function error_campo(array $errores, string $campo): string
{
    if (empty($errores[$campo])) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . e(implode(' ', $errores[$campo])) . '</div>';
}

function clase_invalida(array $errores, string $campo): string
{
    return empty($errores[$campo]) ? '' : ' is-invalid';
}
