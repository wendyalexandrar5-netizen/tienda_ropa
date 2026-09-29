<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Usuario autenticado de la petición actual (JWT verificado + perfil).
 * El rol se toma SIEMPRE de la tabla profiles (fuente de verdad del
 * servidor), nunca de datos enviados por el cliente.
 */
final class CurrentUser
{
    /** @param array<string, mixed> $claims */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly array $claims,
        public readonly string $accessToken,
        public readonly string $via,           // 'session' (web) | 'bearer' (app móvil / API)
        public string $rol = 'cliente',
        public string $estado = 'activo',
        public string $nombre = '',
        public string $apellido = '',
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->rol === 'admin' && $this->estado === 'activo';
    }

    public function isActive(): bool
    {
        return $this->estado === 'activo';
    }

    public function displayName(): string
    {
        $name = trim($this->nombre . ' ' . $this->apellido);
        return $name !== '' ? $name : explode('@', $this->email)[0];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'rol' => $this->rol,
            'estado' => $this->estado,
        ];
    }
}
