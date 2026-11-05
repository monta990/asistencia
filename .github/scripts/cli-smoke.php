<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\MigrationManager;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (PHP_VERSION_ID < 80500 || !extension_loaded('pdo_sqlite') || !extension_loaded('zip') || !extension_loaded('dom') || !extension_loaded('openssl') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('CLI smoke requires PHP 8.5+ and PDO_SQLite, ZIP, DOM, OpenSSL.');
}
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktimenet-cli-' . bin2hex(random_bytes(5));
$data = $temp . DIRECTORY_SEPARATOR . 'data';
$previous = getenv('ZKTIME_DATA_DIR');
$delete = static function (string $path) use (&$delete): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) @unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        $child = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) $delete($child); else @unlink($child);
    }
    @rmdir($path);
};
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException('CLI smoke failed: ' . $message); };
try {
    foreach (['private','uploads','exports','backups','sessions'] as $dir) {
        $path = $data . DIRECTORY_SEPARATOR . $dir;
        if (!mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Cannot create isolated data directory.');
    }
    putenv('ZKTIME_DATA_DIR=' . $data);
    Config::load(require $root . '/config/app.php');
    Config::set('admin_password_hash', password_hash('temporary-cli-smoke-password', PASSWORD_DEFAULT));
    $pdo = new PDO('sqlite:' . Config::get('database'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $migrations = new MigrationManager($pdo, $root . '/database/migrations');
    $result = $migrations->migrate();
    $assert($migrations->currentVersion() === 6 && count($result['applied']) === 6, 'fresh database must apply migrations 001-006');
    $historicalRunId = bin2hex(random_bytes(16));
    $runInsert = $pdo->prepare('INSERT INTO report_runs (id, original_filename, archive_name, archive_path, week, iso_year, start_date, end_date, status, created_by, created_at) VALUES (:id, :original, :archive_name, :archive_path, :week, :year, :start, :end, :status, NULL, :created)');
    $runInsert->execute([
        ':id' => $historicalRunId, ':original' => 'historical.xlsx', ':archive_name' => 'historical.zip',
        ':archive_path' => $historicalRunId . '/historical.zip', ':week' => '44', ':year' => '2026',
        ':start' => '2026-10-26', ':end' => '2026-11-01', ':status' => 'completed',
        ':created' => date('Y-m-d H:i:s'),
    ]);
    $historicalXlsx = (string) Config::get('storage_uploads') . DIRECTORY_SEPARATOR . $historicalRunId . '.xlsx';
    $orphanXlsx = (string) Config::get('storage_uploads') . DIRECTORY_SEPARATOR . 'temporary-orphan.xlsx';
    file_put_contents($historicalXlsx, 'historical execution source', LOCK_EX);
    file_put_contents($orphanXlsx, 'temporary orphan', LOCK_EX);
    touch($historicalXlsx, time() - 3 * 86400);
    touch($orphanXlsx, time() - 3 * 86400);
    file_put_contents((string) Config::get('storage_private') . DIRECTORY_SEPARATOR . 'app.key', random_bytes(32), LOCK_EX);
    unset($migrations, $pdo);

    $run = static function (string $command) use ($root): array {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $root . '/bin/zknet', $command], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
        if (!is_resource($process)) throw new RuntimeException('Cannot start bin/zknet ' . $command);
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['code'=>proc_close($process), 'output'=>(string)$out . (string)$err];
    };
    $help = $run('help');
    $assert($help['code'] === 0 && str_contains($help['output'], 'php bin/zknet db:status'), 'help lists CLI commands');
    $version = $run('version');
    $assert($version['code'] === 0 && str_contains($version['output'], 'Aplicación 1.2.31') && str_contains($version['output'], 'esquema 6/6'), 'version reports app/schema');
    $status = $run('db:status'); $json = json_decode($status['output'], true);
    $assert($status['code'] === 0 && is_array($json) && ($json['current'] ?? null) === 6 && ($json['target'] ?? null) === 6 && ($json['pending'] ?? null) === [] && ($json['drift'] ?? null) === [], 'db:status returns clean schema JSON');
    $migrate = $run('db:migrate');
    $assert($migrate['code'] === 0 && str_contains($migrate['output'], 'Migraciones aplicadas: ninguna'), 'db:migrate is idempotent');
    $cleanup = $run('cleanup');
    $assert($cleanup['code'] === 0 && str_contains($cleanup['output'], 'permanentes'), 'cleanup completes');
    $assert(is_file($historicalXlsx), 'cleanup must preserve old XLSX files referenced by report_runs');
    $assert(!is_file($orphanXlsx), 'cleanup must remove stale unreferenced XLSX uploads');
    $assert(str_contains($cleanup['output'], 'XLSX originales históricos conservados por su ejecución: 1'), 'cleanup must report preserved historical sources');
    $unknown = $run('invalid-cli-command');
    $assert($unknown['code'] === 2, 'unknown command returns exit code 2');
    echo "OK: bin/zknet help, version, db:status, db:migrate, cleanup and historical XLSX retention.\n";
} finally {
    if ($previous === false) putenv('ZKTIME_DATA_DIR'); else putenv('ZKTIME_DATA_DIR=' . $previous);
    $delete($temp);
}
