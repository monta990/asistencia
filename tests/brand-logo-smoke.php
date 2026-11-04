<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZkTimeNet\\Services\\BrandLogo;

function brandLogoCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_brand_logo_' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true)) {
    throw new RuntimeException('No se pudo crear el directorio privado de prueba.');
}
try {
    $filename = 'brand-logo-' . bin2hex(random_bytes(12)) . '.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
    if (!is_string($png)) {
        throw new RuntimeException('No se pudo decodificar el PNG fixture.');
    }
    file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $png);

    $metadata = ['filename' => $filename, 'mime' => 'image/png', 'original_name' => 'logo.png'];
    $resolved = BrandLogo::resolve($metadata, $directory);
    brandLogoCheck(is_array($resolved) && $resolved['mime'] === 'image/png' && is_file($resolved['path']), 'Debe resolver un logo raster guardado dentro del directorio privado.');
    brandLogoCheck(BrandLogo::resolve(['filename' => '../outside.png', 'mime' => 'image/png'], $directory) === null, 'No debe aceptar traversal de rutas.');
    brandLogoCheck(BrandLogo::resolve(['filename' => $filename, 'mime' => 'image/svg+xml'], $directory) === null, 'No debe permitir SVG ni formatos fuera de la allowlist.');
    brandLogoCheck(BrandLogo::resolve(['filename' => $filename, 'mime' => 'image/jpeg'], $directory) === null, 'La extensión y el MIME deben coincidir.');
    brandLogoCheck(BrandLogo::resolve(['filename' => 'brand-logo-' . str_repeat('a', 24) . '.png', 'mime' => 'image/png'], $directory) === null, 'No debe resolver archivos ausentes.');

    BrandLogo::delete($metadata, $directory);
    brandLogoCheck(!is_file($directory . DIRECTORY_SEPARATOR . $filename), 'Debe poder eliminar un logo gestionado.');
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

echo "OK: almacenamiento privado, MIME raster, integridad, traversal y eliminación de logotipo.\\n";
