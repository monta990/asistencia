<?php
declare(strict_types=1);

use ZkTimeNet\Core\InstallationState;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: se requiere PDO_SQLite.\n");
    exit(2);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_install_state_' . bin2hex(random_bytes(5));
if (!mkdir($temporary, 0700, true)) {
    throw new RuntimeException('No se pudo crear el directorio temporal de prueba.');
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $assertions++;
};

try {
    $database = $temporary . DIRECTORY_SEPARATOR . 'test.sqlite';
    $newState = InstallationState::inspect($database);
    $assert(!$newState['database_exists'] && $newState['needs_admin_setup'] && $newState['fresh_database'], 'una ruta sin SQLite requiere primera instalación');

    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE schema_version (id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL)');
    $pdo->exec('INSERT INTO schema_version (id, version) VALUES (1, 0)');
    unset($pdo);
    $partialState = InstallationState::inspect($database);
    $assert($partialState['database_exists'] && $partialState['fresh_database'] && $partialState['needs_admin_setup'], 'una instalación parcial sin migraciones aplicadas se puede reanudar');

    $unknownDatabase = $temporary . DIRECTORY_SEPARATOR . 'unknown.sqlite';
    $pdo = new PDO('sqlite:' . $unknownDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE business_data (id INTEGER PRIMARY KEY, value TEXT)');
    unset($pdo);
    $unknownState = InstallationState::inspect($unknownDatabase);
    $assert($unknownState['database_exists'] && !$unknownState['fresh_database'] && $unknownState['needs_admin_setup'], 'un esquema 0 con tablas desconocidas se deriva al bloqueo seguro del instalador');

    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('UPDATE schema_version SET version = 2 WHERE id = 1');
    $pdo->exec("INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (1, 'initial', 'a', '2026-01-01')");
    unset($pdo);
    $legacyState = InstallationState::inspect($database);
    $assert($legacyState['database_exists'] && $legacyState['schema_version'] === 2 && $legacyState['needs_admin_setup'] && !$legacyState['fresh_database'], 'el esquema 2 requiere la transición de cuentas');

    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('UPDATE schema_version SET version = 3 WHERE id = 1');
    unset($pdo);
    $currentState = InstallationState::inspect($database);
    $assert($currentState['schema_version'] === 3 && !$currentState['needs_admin_setup'], 'el esquema 3 conserva el acceso individual existente');

    echo "OK: {$assertions} comprobaciones de estado de instalación.\n";
} finally {
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($temporary);
}
