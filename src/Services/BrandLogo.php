<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

/**
 * Valida y almacena el logotipo de la empresa fuera del DocumentRoot.
 * Solo se admiten formatos raster para evitar contenido activo como SVG.
 */
final class BrandLogo
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_WIDTH = 2400;
    private const MAX_HEIGHT = 1200;
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** @return array{filename:string,mime:string,original_name:string,width:int,height:int} */
    public static function store(array $upload, string $privateDirectory): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El logotipo supera el tamaño máximo de 2 MB.',
                UPLOAD_ERR_PARTIAL => 'La carga del logotipo quedó incompleta.',
                UPLOAD_ERR_NO_FILE => 'Selecciona una imagen para el logotipo.',
                UPLOAD_ERR_NO_TMP_DIR => 'PHP no tiene disponible su carpeta temporal de carga.',
                UPLOAD_ERR_CANT_WRITE => 'IIS no pudo guardar temporalmente el logotipo.',
                default => 'No se pudo recibir el logotipo.',
            });
        }

        $temporary = (string) ($upload['tmp_name'] ?? '');
        $size = is_file($temporary) ? (int) filesize($temporary) : 0;
        if ($temporary === '' || !is_uploaded_file($temporary) || $size < 1 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('El logotipo no es válido o supera el máximo de 2 MB.');
        }

        $image = @getimagesize($temporary);
        if (!is_array($image) || !isset($image['mime'], $image[0], $image[1])) {
            throw new \RuntimeException('El archivo seleccionado no contiene una imagen válida.');
        }
        $mime = strtolower((string) $image['mime']);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new \RuntimeException('Formato no permitido. Utiliza PNG, JPG/JPEG, GIF o WebP. No se admite SVG.');
        }

        $width = (int) $image[0];
        $height = (int) $image[1];
        if ($width < 1 || $height < 1 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT || ($width * $height) > 2_500_000) {
            throw new \RuntimeException('El logotipo es demasiado grande. El máximo es 2400 × 1200 píxeles y 2.5 megapíxeles.');
        }
        if (!is_dir($privateDirectory) || !is_writable($privateDirectory)) {
            throw new \RuntimeException('No se puede guardar el logotipo en el directorio privado de datos.');
        }

        $filename = 'brand-logo-' . bin2hex(random_bytes(12)) . '.' . self::MIME_EXTENSIONS[$mime];
        $destination = rtrim($privateDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($temporary, $destination)) {
            throw new \RuntimeException('No se pudo guardar el logotipo en el directorio privado.');
        }
        @chmod($destination, 0600);

        $originalName = basename((string) ($upload['name'] ?? 'logotipo.' . self::MIME_EXTENSIONS[$mime]));
        $originalName = preg_replace('/[^\pL\pN._ -]/u', '_', $originalName) ?: 'logotipo.' . self::MIME_EXTENSIONS[$mime];

        return ['filename' => $filename, 'mime' => $mime, 'original_name' => $originalName, 'width' => $width, 'height' => $height];
    }

    /** @return array{path:string,mime:string,filename:string}|null */
    public static function resolve(mixed $metadata, string $privateDirectory): ?array
    {
        if (!is_array($metadata)) {
            return null;
        }
        $filename = (string) ($metadata['filename'] ?? '');
        $mime = strtolower((string) ($metadata['mime'] ?? ''));
        if (preg_match('/^brand-logo-[a-f0-9]{24}\.(png|jpg|gif|webp)$/', $filename) !== 1
            || !isset(self::MIME_EXTENSIONS[$mime])
            || pathinfo($filename, PATHINFO_EXTENSION) !== self::MIME_EXTENSIONS[$mime]) {
            return null;
        }

        $root = realpath($privateDirectory);
        $path = realpath(rtrim($privateDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename);
        if ($root === false || $path === false || dirname($path) !== $root || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $image = @getimagesize($path);
        if (!is_array($image) || strtolower((string) ($image['mime'] ?? '')) !== $mime) {
            return null;
        }

        return ['path' => $path, 'mime' => $mime, 'filename' => (string) ($metadata['original_name'] ?? 'logotipo.' . self::MIME_EXTENSIONS[$mime])];
    }

    public static function delete(mixed $metadata, string $privateDirectory): void
    {
        $resolved = self::resolve($metadata, $privateDirectory);
        if ($resolved !== null) {
            @unlink($resolved['path']);
        }
    }
}
