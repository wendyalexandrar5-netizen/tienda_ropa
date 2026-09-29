<?php

declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;
use App\Core\HttpClient;
use App\Core\HttpException;

/**
 * Sube imágenes a Supabase Storage usando el token del ADMINISTRADOR que
 * realiza la operación (no la service_role key): así las políticas de
 * storage.objects (006_storage.sql) también validan que sea admin.
 */
final class SupabaseImageStorage implements ImageStorage
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
    ) {
    }

    public function put(string $path, string $contents, string $mime, string $accessToken): array
    {
        $objectPath = 'productos/' . $path;
        $res = $this->http->request(
            'POST',
            $this->base() . '/object/' . $this->config->storageBucket . '/' . $objectPath,
            [
                'apikey' => $this->config->supabasePublishableKey,
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => $mime,
                'Cache-Control' => 'max-age=31536000',
                'x-upsert' => 'false',
            ],
            $contents,
            30,
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            error_log('[storage] Error subiendo imagen: ' . $res['status'] . ' ' . substr($res['body'], 0, 300));
            throw new HttpException(502, 'ERROR_ALMACENAMIENTO', 'No fue posible guardar la imagen. Inténtalo de nuevo.');
        }

        return [
            'url' => $this->base() . '/object/public/' . $this->config->storageBucket . '/' . $objectPath,
            'path' => $objectPath,
        ];
    }

    public function delete(string $path, string $accessToken): void
    {
        if (!preg_match('#^productos/[0-9a-z/]+\.(jpg|png|webp)$#', $path)) {
            return;
        }
        $this->http->json(
            'DELETE',
            $this->base() . '/object/' . $this->config->storageBucket,
            ['apikey' => $this->config->supabasePublishableKey, 'Authorization' => 'Bearer ' . $accessToken],
            ['prefixes' => [$path]],
        );
    }

    private function base(): string
    {
        return $this->config->supabaseUrl . '/storage/v1';
    }
}
