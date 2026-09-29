<?php

declare(strict_types=1);

namespace App\Storage;

use App\Core\HttpException;

/**
 * Almacenamiento local para desarrollo. Los archivos se guardan en
 * public/uploads/productos con nombre aleatorio y extensión de imagen; la
 * carpeta incluye un .htaccess que impide ejecutar scripts.
 */
final class LocalImageStorage implements ImageStorage
{
    public function __construct(private readonly string $publicDir)
    {
    }

    public function put(string $path, string $contents, string $mime, string $accessToken): array
    {
        $relative = 'uploads/productos/' . $path;
        $target = $this->publicDir . '/' . $relative;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'ERROR_ALMACENAMIENTO', 'No fue posible guardar la imagen.');
        }
        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new HttpException(500, 'ERROR_ALMACENAMIENTO', 'No fue posible guardar la imagen.');
        }
        @chmod($target, 0644);
        return ['url' => '/' . $relative, 'path' => $relative];
    }

    public function delete(string $path, string $accessToken): void
    {
        if (!preg_match('#^uploads/productos/[0-9a-z/]+\.(jpg|png|webp)$#', $path)) {
            return;
        }
        $file = $this->publicDir . '/' . $path;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
