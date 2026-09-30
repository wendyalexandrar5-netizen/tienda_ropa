<?php defined('ROOT_PATH') || exit('Acceso directo no permitido.');
/**
 * Subida segura de imágenes:
 *  1. Verifica errores de subida y tamaño máximo.
 *  2. Detecta el MIME REAL con finfo (no se confía en la extensión ni en el navegador).
 *  3. Verifica que sea una imagen decodificable (getimagesize).
 *  4. Re-codifica la imagen con GD (elimina cualquier código incrustado).
 *  5. Guarda con un nombre aleatorio generado por el servidor.
 *  Además, uploads/.htaccess impide ejecutar PHP dentro de la carpeta.
 */

const MIME_IMAGEN_PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/**
 * @return string|null ruta relativa (p. ej. "uploads/productos/ab12.jpg") o null si no se envió archivo.
 * @throws DomainException si el archivo no es válido.
 */
function subir_imagen(?array $archivo, string $carpeta): ?string
{
    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (!in_array($carpeta, ['productos', 'categorias'], true)) {
        throw new InvalidArgumentException('Carpeta de destino inválida.');
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        throw new DomainException($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'La imagen supera el tamaño máximo permitido.' : 'No se pudo recibir la imagen. Inténtalo de nuevo.');
    }
    $max = (int) config('upload_max_bytes', 2097152);
    if ($archivo['size'] <= 0 || $archivo['size'] > $max) {
        throw new DomainException('La imagen debe pesar máximo ' . round($max / 1048576, 1) . ' MB.');
    }

    $extOriginal = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extOriginal, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new DomainException('Formato no permitido. Usa JPG, PNG o WEBP.');
    }

    $mime = function_exists('finfo_open')
        ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name'])
        : (string) (getimagesize($archivo['tmp_name'])['mime'] ?? '');
    if (!isset(MIME_IMAGEN_PERMITIDOS[$mime])) {
        throw new DomainException('El archivo no es una imagen válida (JPG, PNG o WEBP).');
    }
    $info = @getimagesize($archivo['tmp_name']);
    if ($info === false || $info[0] < 50 || $info[1] < 50 || $info[0] > 6000 || $info[1] > 6000) {
        throw new DomainException('La imagen está dañada o sus dimensiones no son válidas (50 a 6000 px).');
    }

    $ext     = MIME_IMAGEN_PERMITIDOS[$mime];
    $nombre  = bin2hex(random_bytes(16)) . '.' . $ext;
    $relativa = "uploads/$carpeta/$nombre";
    $destino = ROOT_PATH . '/' . $relativa;
    if (!is_dir(dirname($destino))) {
        mkdir(dirname($destino), 0775, true);
    }

    if (extension_loaded('gd') && recodificar_imagen($archivo['tmp_name'], $mime, $destino)) {
        return $relativa;
    }
    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        throw new DomainException('No se pudo guardar la imagen en el servidor.');
    }
    return $relativa;
}

/** Re-codifica la imagen (redimensiona a máx. 1200 px) para descartar datos no gráficos. */
function recodificar_imagen(string $origen, string $mime, string $destino): bool
{
    $img = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($origen) : false,
        'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($origen) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($origen) : false,
        default      => false,
    };
    if (!$img) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $max = 1200;
    if ($w > $max || $h > $max) {
        $escala = $max / max($w, $h);
        $nw = (int) round($w * $escala);
        $nh = (int) round($h * $escala);
        $lienzo = imagecreatetruecolor($nw, $nh);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagecopyresampled($lienzo, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $lienzo;
    } else {
        imagesavealpha($img, true);
    }
    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($img, $destino, 85),
        'image/png'  => imagepng($img, $destino, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($img, $destino, 85) : false,
        default      => false,
    };
    imagedestroy($img);
    return (bool) $ok;
}

/** Elimina una imagen subida (sólo dentro de uploads/, nunca las del catálogo base). */
function eliminar_imagen_subida(?string $ruta): void
{
    if ($ruta && preg_match('#^uploads/(productos|categorias)/[a-f0-9]{32}\.(jpg|png|webp)$#', $ruta)) {
        $archivo = ROOT_PATH . '/' . $ruta;
        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }
}
