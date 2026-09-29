<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Error controlado que se traduce en una respuesta HTTP con un código de
 * error estable (para clientes web y móviles) y un mensaje seguro.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function notFound(string $message = 'Recurso no encontrado.'): self
    {
        return new self(404, 'NO_ENCONTRADO', $message);
    }

    public static function unauthorized(string $message = 'Debes iniciar sesión.'): self
    {
        return new self(401, 'NO_AUTENTICADO', $message);
    }

    public static function forbidden(string $message = 'No tienes permiso para realizar esta acción.'): self
    {
        return new self(403, 'ACCESO_DENEGADO', $message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors, string $message = 'Revisa los datos enviados.'): self
    {
        return new self(422, 'VALIDACION', $message, ['campos' => $errors]);
    }

    public static function tooManyRequests(string $message = 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.'): self
    {
        return new self(429, 'DEMASIADAS_SOLICITUDES', $message);
    }
}
