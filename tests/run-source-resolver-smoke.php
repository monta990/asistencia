<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZkTimeNet\Services\RunSourceResolver;

function runSourceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_run_source_' . bin2hex(random_bytes(6));
$exports = $root . DIRECTORY_SEPARATOR . 'exports';
$uploads = $root . DIRECTORY_SEPARATOR . 'uploads';
$id = '0123456789abcdef0123456789abcdef';
$runDirectory = $exports . DIRECTORY_SEPARATOR . $id;
foreach ([$exports, $uploads, $runDirectory] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el directorio de prueba.');
    }
}

try {
    $legacyUpload = $uploads . DIRECTORY_SEPARATOR . $id . '.xlsx';
    file_put_contents($legacyUpload, 'legacy-xlsx-placeholder');
    $legacy = RunSourceResolver::resolve($id, $exports, $uploads);
    runSourceCheck(is_array($legacy) && $legacy['storage'] === 'uploads', 'Debe resolver la carga antigua por el mismo ID de ejecución.');
    runSourceCheck($legacy['path'] === realpath($legacyUpload), 'Debe devolver la ruta real dentro de uploads.');

    $currentSource = $runDirectory . DIRECTORY_SEPARATOR . 'source.xlsx';
    file_put_contents($currentSource, 'run-local-xlsx-placeholder');
    $current = RunSourceResolver::resolve($id, $exports, $uploads);
    runSourceCheck(is_array($current) && $current['storage'] === 'run', 'Debe preferir source.xlsx de la carpeta privada de ejecución cuando existe.');
    runSourceCheck($current['path'] === realpath($currentSource), 'Debe devolver la copia privada actual.');

    runSourceCheck(RunSourceResolver::resolve('../' . $id, $exports, $uploads) === null, 'Debe rechazar IDs con traversal.');
    runSourceCheck(RunSourceResolver::resolve(str_repeat('a', 32), $exports, $uploads) === null, 'Debe rechazar ejecuciones sin su carpeta propia o fuente asociada.');

    unlink($currentSource);
    runSourceCheck(RunSourceResolver::resolve($id, $exports, $uploads)['storage'] === 'uploads', 'Debe seguir usando la carga histórica si la copia source.xlsx no está presente.');

    echo "OK: fuente histórica uploads/<run-id>.xlsx, copia nueva exports/<run-id>/source.xlsx, prioridad y validación de rutas.\n";
} finally {
    foreach ([$currentSource ?? '', $legacyUpload ?? ''] as $file) {
        if ($file !== '' && is_file($file)) @unlink($file);
    }
    if (is_dir($runDirectory)) @rmdir($runDirectory);
    if (is_dir($uploads)) @rmdir($uploads);
    if (is_dir($exports)) @rmdir($exports);
    if (is_dir($root)) @rmdir($root);
}
