<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Version;
use ZkTimeNet\Services\ConfigurationBackup;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true) || !extension_loaded('zip')) {
    fwrite(STDOUT, "SKIP: se requieren PDO_SQLite y ZIP para probar respaldos/restauración.\n");
    exit(2);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_backup_' . bin2hex(random_bytes(5));
foreach (['private', 'private/logs', 'private/signatures', 'exports', 'backups', 'sessions', 'uploads'] as $directory) {
    if (!mkdir($temporary . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory), 0700, true) && !is_dir($temporary . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory))) {
        throw new RuntimeException('No se pudo crear la estructura temporal.');
    }
}
$database = $temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'zktime.sqlite';
$backupPath = null;
$stage = null;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $assertions++;
};

try {
    Config::load([
        'data_root' => $temporary,
        'database' => $database,
        'storage_private' => $temporary . DIRECTORY_SEPARATOR . 'private',
        'storage_exports' => $temporary . DIRECTORY_SEPARATOR . 'exports',
        'storage_backups' => $temporary . DIRECTORY_SEPARATOR . 'backups',
    ]);
    file_put_contents($temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'app.key', random_bytes(32));
    file_put_contents($temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . 'signature.svg', '<svg></svg>');
    file_put_contents($temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'private.log', 'no debe incluirse');
    file_put_contents($temporary . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'report.zip', 'reporte permanente');

    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE schema_version (id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL)');
    $pdo->exec('INSERT INTO schema_version (id, version) VALUES (1, ' . Version::SCHEMA_TARGET . ')');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_active INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO users (id, role, is_active) VALUES (1, 'admin', 1)");

    $backupPath = ConfigurationBackup::createArchive($pdo, 'download');
    $assert((bool) preg_match('/ZKTimeNet-Backup-\d{8}-\d{6}-[a-f0-9]+\.zip$/', basename($backupPath)), 'el nombre del ZIP incluye fecha, hora e identificador');
    $zip = new ZipArchive();
    $assert($zip->open($backupPath, ZipArchive::RDONLY) === true, 'el respaldo se puede abrir como ZIP');
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) $names[] = (string) $zip->getNameIndex($index);
    $assert(in_array('data/private/zktime.sqlite', $names, true), 'el respaldo incluye SQLite');
    $assert(in_array('data/private/app.key', $names, true), 'el respaldo incluye la llave SMTP');
    $assert(in_array('data/private/signatures/signature.svg', $names, true), 'el respaldo incluye la firma privada');
    $assert(in_array('data/exports/report.zip', $names, true), 'el respaldo incluye reportes permanentes');
    $assert(in_array('metadata/config-app.php', $names, true), 'el respaldo incluye config/app.php como referencia');
    $assert(!in_array('data/private/logs/private.log', $names, true), 'el respaldo excluye logs');
    $zip->close();

    $stage = ConfigurationBackup::prepareRestore($backupPath);
    $pdo = null;
    file_put_contents($temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'app.key', str_repeat('x', 32));
    file_put_contents($temporary . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'new-file.txt', 'debe desaparecer');
    ConfigurationBackup::applyRestore($stage);
    $assert(file_get_contents($temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'app.key') !== str_repeat('x', 32), 'la restauración repone la llave del respaldo');
    $assert(!is_file($temporary . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'new-file.txt'), 'la restauración reemplaza los reportes actuales');
    $assert(is_file($temporary . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'private.log'), 'los logs locales se conservan fuera del payload restaurado');
    echo "OK: {$assertions} comprobaciones funcionales de ZIP, validación, respaldo y restauración.\n";
} finally {
    if (is_string($stage)) ConfigurationBackup::cleanupStage($stage);
    if (is_string($backupPath) && is_file($backupPath)) @unlink($backupPath);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($temporary);
}
