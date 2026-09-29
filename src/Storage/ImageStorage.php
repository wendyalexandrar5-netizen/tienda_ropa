<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Almacenamiento de imágenes de productos. Implementaciones:
 *   * SupabaseImageStorage – Supabase Storage (producción)
 *   * LocalImageStorage    – carpeta public/uploads (desarrollo sin Supabase)
 */
interface ImageStorage
{
    /** @return array{url: string, path: string} */
    public function put(string $path, string $contents, string $mime, string $accessToken): array;

    public function delete(string $path, string $accessToken): void;
}
