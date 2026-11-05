<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZkTimeNet\Services\SignatureImage;

function signatureCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_signature_' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true)) {
    throw new RuntimeException('No se pudo crear el directorio de firma de prueba.');
}
try {
    $filename = 'mail-signature-' . bin2hex(random_bytes(12)) . '.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
    if (!is_string($png)) {
        throw new RuntimeException('No se pudo decodificar el PNG fixture.');
    }
    file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $png);
    $resolved = SignatureImage::resolve([
        'filename' => $filename,
        'mime' => 'image/png',
        'original_name' => 'firma.png',
    ], $directory);
    signatureCheck(is_array($resolved) && $resolved['mime'] === 'image/png' && is_file($resolved['path']), 'Debe resolver la imagen guardada dentro del directorio privado.');
    signatureCheck(SignatureImage::resolve(['filename' => '../outside.png', 'mime' => 'image/png'], $directory) === null, 'No debe aceptar traversal de rutas.');
    signatureCheck(SignatureImage::resolve(['filename' => $filename, 'mime' => 'image/svg+xml'], $directory) === null, 'No debe permitir SVG/formatos no soportados.');
    SignatureImage::delete(['filename' => $filename, 'mime' => 'image/png'], $directory);
    signatureCheck(!is_file($directory . DIRECTORY_SEPARATOR . $filename), 'Debe poder eliminar una firma gestionada.');
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

echo "OK: validación de almacenamiento privado, allowlist MIME, traversal y eliminación de firma.\n";
