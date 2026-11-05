<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

use PDO;

final class MigrationManager
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL)');
        $this->pdo->exec('INSERT OR IGNORE INTO schema_version (id, version) VALUES (1, 0)');
    }

    /** @return list<array{version:int,name:string,checksum:string}> */
    public function available(): array
    {
        $items = [];
        foreach (glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (!preg_match('/^(\d{3,})_(.+)\.php$/', basename($file), $match)) {
                continue;
            }
            $items[] = ['version' => (int) $match[1], 'name' => $match[2], 'checksum' => hash_file('sha256', $file) ?: ''];
        }
        usort($items, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);
        return $items;
    }

    public function currentVersion(): int
    {
        return (int) $this->pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn();
    }

    public function status(): array
    {
        $applied = $this->pdo->query('SELECT version, name, checksum FROM schema_migrations ORDER BY version')->fetchAll();
        $available = $this->available();
        $availableByVersion = [];
        foreach ($available as $migration) {
            $availableByVersion[$migration['version']] = $migration;
        }
        $byVersion = [];
        foreach ($applied as $row) {
            $version = (int) $row['version'];
            $byVersion[$version] = $row;
        }
        $pending = [];
        $drift = [];
        $expectedVersion = 1;
        foreach ($available as $migration) {
            if ($migration['version'] !== $expectedVersion) {
                $drift[] = 'sequence';
                break;
            }
            $expectedVersion++;
        }
        foreach ($applied as $row) {
            $version = (int) $row['version'];
            if (!isset($availableByVersion[$version])) {
                $drift[] = $version;
                continue;
            }
            if ((string) $row['name'] !== $availableByVersion[$version]['name']
                || !hash_equals((string) $row['checksum'], $availableByVersion[$version]['checksum'])) {
                $drift[] = $version;
            }
        }
        $current = $this->currentVersion();
        foreach ($available as $migration) {
            if (!isset($byVersion[$migration['version']])) {
                if ($migration['version'] <= $current) {
                    $drift[] = $migration['version'];
                } else {
                    $pending[] = $migration;
                }
            }
        }
        $maxApplied = $byVersion === [] ? 0 : max(array_keys($byVersion));
        if ($current !== $maxApplied) {
            $drift[] = 'metadata';
        }
        $latest = $available;
        $target = $latest === [] ? 0 : (int) $latest[array_key_last($latest)]['version'];
        return ['current' => $current, 'target' => $target, 'pending' => $pending, 'drift' => array_values(array_unique($drift)), 'future' => $current > $target];
    }

    public function migrate(): array
    {
        $lockPath = rtrim((string) Config::get('storage_private'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'schema.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('No se pudo abrir el bloqueo de migraciones SQLite.');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('No se pudo obtener el bloqueo exclusivo de migraciones.');
        }

        try {
            // Recalcula el estado después de adquirir el bloqueo para evitar carreras entre procesos.
            $status = $this->status();
            if ($status['future']) {
                throw new \RuntimeException('La base de datos es más nueva que este código. No se ejecutarán migraciones hacia atrás.');
            }
            if ($status['drift'] !== []) {
                throw new \RuntimeException('Se detectaron inconsistencias en el historial de migraciones: ' . implode(', ', $status['drift']) . '. Restaura el archivo original y crea una migración nueva.');
            }
            if ($status['pending'] === []) {
                return ['applied' => [], 'backup' => null];
            }

            $backup = $this->backupDatabase();
            $appliedNow = [];
            foreach ($status['pending'] as $migration) {
                $file = $this->directory . DIRECTORY_SEPARATOR . sprintf('%03d_%s.php', $migration['version'], $migration['name']);
                $callback = require $file;
                if (!is_callable($callback)) {
                    throw new \RuntimeException('La migración ' . basename($file) . ' debe devolver una función.');
                }
                $this->pdo->beginTransaction();
                try {
                    $callback($this->pdo);
                    $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (:version, :name, :checksum, :applied_at)');
                    $insert->execute(['version' => $migration['version'], 'name' => $migration['name'], 'checksum' => $migration['checksum'], 'applied_at' => date(DATE_ATOM)]);
                    $update = $this->pdo->prepare('UPDATE schema_version SET version = :version WHERE id = 1');
                    $update->execute(['version' => $migration['version']]);
                    $this->pdo->commit();
                    $appliedNow[] = $migration['version'];
                } catch (\Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $exception;
                }
            }
            return ['applied' => $appliedNow, 'backup' => $backup];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function backupDatabase(): ?string
    {
        $path = (string) Config::get('database');
        if (!is_file($path) || filesize($path) === 0) {
            return null;
        }
        $checkpoint = $this->pdo->query('PRAGMA wal_checkpoint(FULL)')->fetch(PDO::FETCH_NUM);
        if (is_array($checkpoint)) {
            $busy = (int) ($checkpoint[0] ?? 0);
            $logFrames = (int) ($checkpoint[1] ?? 0);
            $checkpointed = (int) ($checkpoint[2] ?? 0);
            if ($busy !== 0 || ($logFrames >= 0 && $checkpointed < $logFrames)) {
                throw new \RuntimeException('SQLite no pudo completar el checkpoint WAL; no se aplicarán migraciones.');
            }
        }
        $directory = (string) Config::get('storage_backups');
        $target = $directory . DIRECTORY_SEPARATOR . 'before-migration-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        if (!copy($path, $target)) {
            throw new \RuntimeException('No se pudo crear respaldo de SQLite antes de migrar.');
        }
        @chmod($target, 0600);
        return $target;
    }
}
