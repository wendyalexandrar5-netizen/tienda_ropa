<?php

declare(strict_types=1);

namespace App\Storage;

use App\Core\HttpException;

/**
 * Validación estricta de imágenes subidas:
 *   * tamaño máximo configurable (5 MB por defecto)
 *   * extensión permitida (jpg, jpeg, png, webp)
 *   * tipo MIME REAL detectado por contenido (finfo), no el enviado por el navegador
 *   * la imagen debe poder decodificarse (getimagesize) y tener dimensiones razonables
 *   * se re-codifica con GD cuando está disponible → elimina metadatos EXIF y
 *     cualquier contenido embebido (p. ej. código PHP oculto en la imagen)
 *   * nombre final aleatorio generado por el servidor (nunca el del usuario)
 */
final class ImageValidator
{
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * @param array<string, mixed> $file entrada de $_FILES
     * @return array{contents: string, mime: string, extension: string, width: int, height: int}
     */
    public function validate(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw $this->fail('La imagen supera el tamaño máximo permitido.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw $this->fail('No se recibió ninguna imagen válida.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            throw $this->fail('Archivo inválido.');
        }

        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw $this->fail(sprintf('La imagen debe pesar como máximo %d MB.', intdiv($this->maxBytes, 1024 * 1024)));
        }

        $originalExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($originalExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw $this->fail('Formato no permitido. Usa JPG, PNG o WEBP.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw $this->fail('El contenido del archivo no es una imagen JPG, PNG o WEBP válida.');
        }

        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw $this->fail('La imagen está dañada o no es válida.');
        }
        [$width, $height] = $info;
        if ($width < 50 || $height < 50 || $width > 6000 || $height > 6000) {
            throw $this->fail('Las dimensiones de la imagen deben estar entre 50 y 6000 píxeles.');
        }

        $contents = $this->reencode($tmp, $mime) ?? (string) file_get_contents($tmp);

        return [
            'contents' => $contents,
            'mime' => $mime,
            'extension' => self::ALLOWED[$mime],
            'width' => (int) $width,
            'height' => (int) $height,
        ];
    }

    public static function randomName(string $extension): string
    {
        return date('Y/m/') . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    private function reencode(string $path, string $mime): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw $this->fail('La imagen está dañada o no es válida.');
        }
        ob_start();
        $ok = match ($mime) {
            'image/png' => (static function ($img) { imagesavealpha($img, true); return imagepng($img, null, 6); })($image),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, 85) : false,
            default => imagejpeg($image, null, 88),
        };
        $data = (string) ob_get_clean();
        imagedestroy($image);
        return $ok && $data !== '' ? $data : null;
    }

    private function fail(string $message): HttpException
    {
        return new HttpException(422, 'IMAGEN_INVALIDA', $message);
    }
}
