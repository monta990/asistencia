<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

use PDO;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;
use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Version;

/** Respaldo y restauración validada de la configuración privada y los reportes permanentes. */
final class ConfigurationBackup
{
    private const FORMAT = 'zktimenet-configuration-backup';
    private const FORMAT_VERSION = 1;
    private const MAX_ARCHIVE_BYTES = 536870912; // 512 MiB
    private const MAX_EXPANDED_BYTES = 2147483648; // 2 GiB
    private const MAX_ENTRIES = 100000;

    public static function createArchive(PDO $pdo, string $purpose = 'download'): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión ZIP de PHP no está disponible.');
        }
        $backupDirectory = (string) Config::get('storage_backups');
        self::ensureDirectory($backupDirectory);
        $workDirectory = $backupDirectory . DIRECTORY_SEPARATOR . '.backup-work-' . bin2hex(random_bytes(8));
        self::ensureDirectory($workDirectory);
        $archivePath = '';

        try {
            $snapshotDirectory = $workDirectory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'private';
            self::ensureDirectory($snapshotDirectory);
            $snapshotPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'zktime.sqlite';
            $existingKey = (string) Config::get('storage_private') . DIRECTORY_SEPARATOR . 'app.key';
            if (!is_file($existingKey) || filesize($existingKey) !== 32) {
                throw new RuntimeException('No se puede generar un respaldo recuperable: app.key no existe o no contiene exactamente 32 bytes.');
            }

            $checkpoint = $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
            if (is_array($checkpoint)) {
                $busy = (int) ($checkpoint[0] ?? 0);
                $logFrames = (int) ($checkpoint[1] ?? 0);
                $checkpointed = (int) ($checkpoint[2] ?? 0);
                if ($busy !== 0 || ($logFrames >= 0 && $checkpointed < $logFrames)) {
                    throw new RuntimeException('SQLite está ocupado y no pudo completar el respaldo consistente. Cierra las operaciones activas e inténtalo nuevamente.');
                }
            }
            $databasePath = (string) Config::get('database');
            if (is_file($databasePath) && filesize($databasePath) > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException('La base de datos supera el límite de 2 GiB para este respaldo. Realiza un respaldo manual del servidor.');
            }
            $quotedSnapshot = str_replace("'", "''", $snapshotPath);
            $pdo->exec("VACUUM INTO '" . $quotedSnapshot . "'");
            if (!is_file($snapshotPath) || filesize($snapshotPath) < 100) {
                throw new RuntimeException('No se pudo generar la copia consistente de SQLite.');
            }
            @chmod($snapshotPath, 0600);
            $snapshotSchema = self::validateDatabase($snapshotPath);
            $liveSchema = (int) $pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn();
            if ($snapshotSchema !== $liveSchema) {
                throw new RuntimeException('El esquema de la instantánea SQLite no coincide con el sistema activo. No se descargó un respaldo.');
            }

            $totalPayloadBytes = (int) filesize($snapshotPath);
            $privateDirectory = (string) Config::get('storage_private');
            self::copyPrivatePayload($privateDirectory, $snapshotDirectory, $totalPayloadBytes);
            $exportsSource = (string) Config::get('storage_exports');
            $exportsTarget = $workDirectory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'exports';
            self::ensureDirectory($exportsTarget);
            self::copyDirectory($exportsSource, $exportsTarget, true, $totalPayloadBytes);

            $payload = [];
            $payloadRoot = $workDirectory . DIRECTORY_SEPARATOR . 'data';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($payloadRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }
                $absolute = $item->getPathname();
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($payloadRoot) + 1));
                $archiveName = self::safeArchivePath('data/' . $relative);
                if (!self::isAllowedPayloadPath($archiveName)) {
                    throw new RuntimeException('Se encontró un nombre de archivo no permitido dentro de los datos privados.');
                }
                $payload[] = [
                    'path' => $archiveName,
                    'size' => (int) $item->getSize(),
                    'sha256' => self::sha256File($absolute),
                ];
            }
            // Los archivos PHP de configuración se conservan como referencia para
            // reconstrucción manual; nunca se ejecutan ni sobrescriben al restaurar.
            $applicationRoot = dirname(__DIR__, 2);
            foreach ([
                'config/app.php' => 'metadata/config-app.php',
                'config/local.php' => 'metadata/config-local.php',
            ] as $relativeSource => $archiveName) {
                $sourceConfig = $applicationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeSource);
                if (!is_file($sourceConfig) || is_link($sourceConfig)) {
                    continue;
                }
                $metadataConfig = $workDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archiveName);
                self::ensureDirectory(dirname($metadataConfig));
                if (!copy($sourceConfig, $metadataConfig)) {
                    throw new RuntimeException('No se pudo incorporar un archivo de configuración de referencia al respaldo.');
                }
                $payload[] = [
                    'path' => $archiveName,
                    'size' => (int) filesize($metadataConfig),
                    'sha256' => self::sha256File($metadataConfig),
                ];
            }

            $totalPayloadBytes = array_sum(array_map(static fn (array $entry): int => (int) $entry['size'], $payload));
            if ($totalPayloadBytes > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException('El contenido del respaldo supera el límite de 2 GiB.');
            }
            $schemaVersion = (int) $pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn();
            $manifest = [
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'application_version' => Version::VERSION,
                'schema_version' => $schemaVersion,
                'created_at' => date(DATE_ATOM),
                'purpose' => preg_replace('/[^a-z0-9_-]/i', '', $purpose) ?: 'download',
                'data_root' => self::canonicalPath((string) Config::get('data_root')),
                'config_source' => Config::get('data_root_from_environment') ? 'environment:ZKTIME_DATA_DIR' : 'config/local.php-or-platform-default',
                'payload' => $payload,
                'notes' => [
                    'Includes SQLite, app.key, installation metadata, private signature assets, and permanent exports.',
                    'Excludes sessions, temporary uploads, log files, previous backups, and runtime lock files.',
                    'config/app.php and config/local.php are included as reference files and are not executed or overwritten during restore.',
                ],
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $manifestPath = $workDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
            if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo escribir el manifiesto del respaldo.');
            }

            $stamp = date('Ymd-His');
            $purposeSuffix = $purpose === 'download' ? '' : '-' . (preg_replace('/[^a-z0-9_-]/i', '', $purpose) ?: 'backup');
            $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . 'ZKTimeNet-Backup-' . $stamp . $purposeSuffix . '-' . bin2hex(random_bytes(3)) . '.zip';
            $zip = new ZipArchive();
            $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL);
            if ($opened !== true) {
                throw new RuntimeException('No se pudo crear el archivo ZIP de respaldo.');
            }
            try {
                if (!$zip->addFile($manifestPath, 'manifest.json')) {
                    throw new RuntimeException('No se pudo agregar el manifiesto al archivo ZIP.');
                }
                foreach ($payload as $entry) {
                    $archiveName = (string) $entry['path'];
                    if (str_starts_with($archiveName, 'data/')) {
                        $relative = substr($archiveName, strlen('data/'));
                        $source = $payloadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    } else {
                        $source = $workDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archiveName);
                    }
                    if (!is_file($source) || !$zip->addFile($source, $archiveName)) {
                        throw new RuntimeException('No se pudo agregar un archivo al respaldo ZIP: ' . $archiveName);
                    }
                }
                if (!$zip->close()) {
                    throw new RuntimeException('No se pudo finalizar el archivo ZIP de respaldo.');
                }
            } catch (\Throwable $exception) {
                $zip->close();
                throw $exception;
            }
            if (!is_file($archivePath) || filesize($archivePath) < 100 || filesize($archivePath) > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('El respaldo ZIP quedó incompleto o supera el límite de 512 MiB.');
            }
            @chmod($archivePath, 0600);
            return $archivePath;
        } catch (\Throwable $exception) {
            if ($archivePath !== '' && is_file($archivePath)) {
                @unlink($archivePath);
            }
            throw $exception;
        } finally {
            self::removeDirectory($workDirectory);
        }
    }

    /** Valida y extrae un respaldo a staging sin tocar los datos activos. */
    public static function prepareRestore(string $archivePath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión ZIP de PHP no está disponible.');
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || filesize($archivePath) < 100 || filesize($archivePath) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('El respaldo no existe, está vacío o supera el límite de 512 MiB.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new RuntimeException('El archivo seleccionado no es un ZIP válido.');
        }
        $stageDirectory = (string) Config::get('storage_backups') . DIRECTORY_SEPARATOR . '.restore-stage-' . bin2hex(random_bytes(8));
        try {
            self::ensureDirectory($stageDirectory);
            self::ensureDirectory($stageDirectory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'private');
            self::ensureDirectory($stageDirectory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'exports');
            if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('El respaldo contiene una cantidad de archivos no permitida.');
            }
            $manifestStream = $zip->getStream('manifest.json');
            if ($manifestStream === false) {
                throw new RuntimeException('El respaldo no contiene un manifiesto válido.');
            }
            $manifestContent = '';
            try {
                while (!feof($manifestStream)) {
                    $chunk = fread($manifestStream, 65536);
                    if ($chunk === false) {
                        throw new RuntimeException('No se pudo leer el manifiesto del respaldo.');
                    }
                    if ($chunk === '') {
                        if (feof($manifestStream)) {
                            break;
                        }
                        throw new RuntimeException('La lectura del manifiesto se detuvo inesperadamente.');
                    }
                    if (strlen($manifestContent) + strlen($chunk) > 5_242_880) {
                        throw new RuntimeException('El manifiesto del respaldo supera el tamaño permitido.');
                    }
                    $manifestContent .= $chunk;
                }
            } finally {
                fclose($manifestStream);
            }
            $manifest = json_decode($manifestContent, true);
            if (!is_array($manifest)
                || ($manifest['format'] ?? '') !== self::FORMAT
                || (int) ($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION) {
                throw new RuntimeException('El formato del respaldo no es compatible con ZKTimeNet.');
            }
            $backupSchema = (int) ($manifest['schema_version'] ?? 0);
            if (($manifest['application_version'] ?? '') !== Version::VERSION
                || $backupSchema < Version::USER_AUTH_SCHEMA
                || $backupSchema > Version::SCHEMA_TARGET) {
                throw new RuntimeException('El respaldo pertenece a otra versión de ZKTimeNet o contiene un esquema SQLite no compatible. Instala primero la misma versión que generó el respaldo.');
            }
            $currentRoot = self::canonicalPath((string) Config::get('data_root'));
            $backupRoot = self::canonicalPath((string) ($manifest['data_root'] ?? ''));
            if (!hash_equals($currentRoot, $backupRoot)) {
                throw new RuntimeException('La ruta de datos del respaldo no coincide con la instalación actual. Para evitar mezclar archivos, restaura en la misma ubicación de datos donde se generó.');
            }

            if (!is_array($manifest['payload'] ?? null) || count($manifest['payload']) > self::MAX_ENTRIES - 1) {
                throw new RuntimeException('El manifiesto no contiene una lista de archivos válida.');
            }
            $expected = [];
            foreach ($manifest['payload'] as $entry) {
                if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['sha256'] ?? null)) {
                    throw new RuntimeException('El manifiesto del respaldo contiene entradas inválidas.');
                }
                $path = self::safeArchivePath($entry['path']);
                if (isset($expected[$path]) || preg_match('/^[a-f0-9]{64}$/i', $entry['sha256']) !== 1) {
                    throw new RuntimeException('El manifiesto contiene rutas repetidas o checksums inválidos.');
                }
                if (!self::isAllowedPayloadPath($path)) {
                    throw new RuntimeException('El respaldo contiene un archivo fuera de las ubicaciones permitidas.');
                }
                $entrySize = filter_var($entry['size'] ?? null, FILTER_VALIDATE_INT);
                if ($entrySize === false || $entrySize < 0 || $entrySize > self::MAX_EXPANDED_BYTES) {
                    throw new RuntimeException('El manifiesto declara un tamaño de archivo inválido.');
                }
                $expected[$path] = ['sha256' => strtolower($entry['sha256']), 'size' => $entrySize];
            }
            if (!isset($expected['data/private/zktime.sqlite'], $expected['data/private/app.key'])) {
                throw new RuntimeException('El respaldo está incompleto: falta SQLite o la llave de cifrado app.key.');
            }

            $totalExpanded = 0;
            $actualFiles = [];
            $manifestEntries = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'])) {
                    throw new RuntimeException('No se pudo leer el inventario del archivo ZIP.');
                }
                $name = (string) $stat['name'];
                if (str_ends_with($name, '/')) {
                    $directoryName = rtrim($name, '/');
                    if ($directoryName !== '') {
                        $safeDirectory = self::safeArchivePath($directoryName);
                        $allowedDirectory = $safeDirectory === 'metadata'
                            || $safeDirectory === 'data/private'
                            || str_starts_with($safeDirectory, 'data/private/')
                            || $safeDirectory === 'data/exports'
                            || str_starts_with($safeDirectory, 'data/exports/');
                        if (!$allowedDirectory) {
                            throw new RuntimeException('El ZIP contiene una carpeta fuera de las ubicaciones permitidas.');
                        }
                    }
                    continue;
                }
                $path = self::safeArchivePath($name);
                if ($path === 'manifest.json') {
                    $manifestEntries++;
                    if ($manifestEntries > 1) {
                        throw new RuntimeException('El ZIP contiene manifiestos duplicados.');
                    }
                    continue;
                }
                if (!isset($expected[$path]) || isset($actualFiles[$path]) || !self::isAllowedPayloadPath($path)) {
                    throw new RuntimeException('El ZIP contiene rutas duplicadas o archivos no declarados en el manifiesto.');
                }
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
                    && $opsys === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('El respaldo contiene un enlace simbólico y fue rechazado.');
                }
                $size = (int) ($stat['size'] ?? 0);
                if ($size < 0 || $expected[$path]['size'] < 0 || $size !== $expected[$path]['size']) {
                    throw new RuntimeException('El tamaño de un archivo no coincide con el manifiesto.');
                }
                $totalExpanded += $size;
                if ($totalExpanded > self::MAX_EXPANDED_BYTES) {
                    throw new RuntimeException('El contenido descomprimido supera el límite de 2 GiB.');
                }
                $destination = $stageDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                self::ensureDirectory(dirname($destination));
                $input = $zip->getStream($name);
                $output = @fopen($destination, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException('No se pudo extraer un archivo del respaldo al área temporal.');
                }
                try {
                    $copied = 0;
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException('No se pudo leer un archivo del respaldo.');
                        }
                        if ($chunk === '') {
                            if (feof($input)) {
                                break;
                            }
                            throw new RuntimeException('La lectura de un archivo del respaldo se detuvo inesperadamente.');
                        }
                        $chunkLength = strlen($chunk);
                        if ($copied + $chunkLength > $size || $totalExpanded - $size + $copied + $chunkLength > self::MAX_EXPANDED_BYTES) {
                            throw new RuntimeException('El contenido descomprimido excede el tamaño permitido.');
                        }
                        $written = 0;
                        while ($written < $chunkLength) {
                            $count = fwrite($output, substr($chunk, $written));
                            if ($count === false || $count === 0) {
                                throw new RuntimeException('No se pudo escribir un archivo en el área temporal.');
                            }
                            $written += $count;
                        }
                        $copied += $chunkLength;
                    }
                    if ($copied !== $size) {
                        throw new RuntimeException('La extracción del respaldo quedó incompleta.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                @chmod($destination, 0600);
                if (!hash_equals($expected[$path]['sha256'], (string) hash_file('sha256', $destination))) {
                    throw new RuntimeException('La verificación SHA-256 falló para un archivo del respaldo.');
                }
                $actualFiles[$path] = true;
            }
            $zip->close();
            if ($manifestEntries !== 1) {
                throw new RuntimeException('El respaldo debe contener exactamente un manifiesto.');
            }
            if (count($actualFiles) !== count($expected)) {
                throw new RuntimeException('El ZIP no contiene todos los archivos declarados en el manifiesto.');
            }

            $keyPath = $stageDirectory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'app.key';
            if (filesize($keyPath) !== 32) {
                throw new RuntimeException('La llave app.key del respaldo no tiene el tamaño requerido de 32 bytes.');
            }
            $actualSchema = self::validateDatabase($stageDirectory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'zktime.sqlite');
            if ($actualSchema !== $backupSchema) {
                throw new RuntimeException('La versión SQLite no coincide con la declarada en el manifiesto del respaldo.');
            }
            return $stageDirectory;
        } catch (\Throwable $exception) {
            $zip->close();
            self::removeDirectory($stageDirectory);
            throw $exception;
        }
    }

    /** Sustituye private y exports; conserva logs, backups, sesiones, uploads, config/local.php y el inode del lock de migraciones. */
    public static function applyRestore(string $stageDirectory): void
    {
        $stageRoot = realpath($stageDirectory);
        $backupRoot = realpath((string) Config::get('storage_backups'));
        if ($stageRoot === false || $backupRoot === false || !str_starts_with($stageRoot, rtrim($backupRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('El área temporal de restauración no es válida.');
        }
        $sourcePrivate = $stageRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'private';
        $sourceExports = $stageRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'exports';
        if (!is_dir($sourcePrivate) || !is_dir($sourceExports)) {
            throw new RuntimeException('El respaldo no contiene todas las carpetas de datos requeridas.');
        }

        $targetPrivate = (string) Config::get('storage_private');
        $targetExports = (string) Config::get('storage_exports');
        self::ensureDirectory($targetPrivate);
        self::ensureDirectory($targetExports);
        self::clearDirectoryContents($targetPrivate, ['logs', 'schema.lock']);
        self::clearDirectoryContents($targetExports, []);
        self::copyDirectory($sourcePrivate, $targetPrivate, false);
        self::copyDirectory($sourceExports, $targetExports, false);

        $database = (string) Config::get('database');
        $key = $targetPrivate . DIRECTORY_SEPARATOR . 'app.key';
        if (!is_file($database) || !is_file($key) || filesize($key) !== 32) {
            throw new RuntimeException('La restauración no dejó SQLite y app.key en sus ubicaciones correctas.');
        }
        @chmod($database, 0600);
        @chmod($key, 0600);
    }

    public static function cleanupStage(string $stageDirectory): void
    {
        $backupRoot = realpath((string) Config::get('storage_backups'));
        $stageRoot = realpath($stageDirectory);
        if ($backupRoot !== false && $stageRoot !== false && str_starts_with($stageRoot, rtrim($backupRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            self::removeDirectory($stageRoot);
        }
    }

    private static function validateDatabase(string $path): int
    {
        if (!is_file($path) || filesize($path) < 100) {
            throw new RuntimeException('La base SQLite del respaldo está vacía o dañada.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if (strtolower($integrity) !== 'ok') {
            throw new RuntimeException('La comprobación de integridad SQLite falló.');
        }
        $schema = (int) $pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn();
        if ($schema < Version::USER_AUTH_SCHEMA || $schema > Version::SCHEMA_TARGET) {
            throw new RuntimeException('El esquema SQLite incluido no es compatible con esta versión de restauración.');
        }
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($adminCount < 1) {
            throw new RuntimeException('La base SQLite no contiene un administrador activo; no se puede restaurar.');
        }
        $pdo = null;
        return $schema;
    }

    private static function copyPrivatePayload(string $source, string $destination, int &$totalBytes): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('No se encontró el directorio privado de configuración.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $first = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
            if ($first === 'logs' || in_array($item->getFilename(), ['schema.lock', 'maintenance.lock', '.zktime-install.lock', 'zktime.sqlite-wal', 'zktime.sqlite-shm'], true)) {
                continue;
            }
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir() && !$item->isLink()) {
                self::ensureDirectory($target);
            } elseif ($item->isFile() && !$item->isLink() && $item->getFilename() !== 'zktime.sqlite') {
                $fileSize = (int) $item->getSize();
                if ($fileSize < 0 || $totalBytes > self::MAX_EXPANDED_BYTES - $fileSize) {
                    throw new RuntimeException('Los archivos privados superan el límite de 2 GiB para este respaldo.');
                }
                $totalBytes += $fileSize;
                self::ensureDirectory(dirname($target));
                if (!copy($item->getPathname(), $target)) {
                    throw new RuntimeException('No se pudo copiar un archivo privado al respaldo.');
                }
                @chmod($target, 0600);
            }
        }
    }

    private static function copyDirectory(string $source, string $destination, bool $skipSymlinks, ?int &$totalBytes = null): void
    {
        if (!is_dir($source)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                if ($skipSymlinks) {
                    continue;
                }
                throw new RuntimeException('Se encontró un enlace simbólico en el área de restauración.');
            }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                self::ensureDirectory($target);
            } elseif ($item->isFile()) {
                if ($totalBytes !== null) {
                    $fileSize = (int) $item->getSize();
                    if ($fileSize < 0 || $totalBytes > self::MAX_EXPANDED_BYTES - $fileSize) {
                        throw new RuntimeException('Los reportes y archivos permanentes superan el límite de 2 GiB para este respaldo.');
                    }
                    $totalBytes += $fileSize;
                }
                self::ensureDirectory(dirname($target));
                if (!copy($item->getPathname(), $target)) {
                    throw new RuntimeException('No se pudo copiar un archivo durante la restauración.');
                }
                @chmod($target, 0600);
            }
        }
    }

    private static function clearDirectoryContents(string $directory, array $preserveNames): void
    {
        $items = scandir($directory);
        if (!is_array($items)) {
            throw new RuntimeException('No se pudo inspeccionar una carpeta de datos antes de restaurar.');
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $preserveNames, true)) {
                continue;
            }
            self::removePath($directory . DIRECTORY_SEPARATOR . $name);
        }
    }

    private static function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removePath($path . DIRECTORY_SEPARATOR . $name);
            }
        }
        @rmdir($path);
    }

    private static function removeDirectory(string $path): void
    {
        self::removePath($path);
    }

    private static function safeArchivePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1) {
            throw new RuntimeException('El respaldo contiene una ruta no permitida.');
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('El respaldo contiene una ruta insegura.');
            }
        }
        return implode('/', $segments);
    }

    private static function isAllowedPayloadPath(string $path): bool
    {
        return str_starts_with($path, 'data/private/')
            || str_starts_with($path, 'data/exports/')
            || $path === 'metadata/config-app.php'
            || $path === 'metadata/config-local.php';
    }

    private static function sha256File(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('No se pudo calcular SHA-256 de un archivo del respaldo.');
        }
        return $hash;
    }

    private static function canonicalPath(string $path): string
    {
        $resolved = realpath($path);
        $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
        $normalized = rtrim($normalized, '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('No se pudo preparar una carpeta temporal del respaldo.');
        }
    }
}
