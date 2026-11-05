<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

use PDO;
use Throwable;

/** Inspecciona SQLite sin crear una base de datos ni modificar una instalación existente. */
final class InstallationState
{
    /**
     * @return array{database_exists:bool,schema_version:int|null,needs_admin_setup:bool,readable:bool,fresh_database:bool}
     */
    public static function inspect(string $databasePath): array
    {
        if (!is_file($databasePath) || (int) (@filesize($databasePath) ?: 0) === 0) {
            return [
                'database_exists' => false,
                'schema_version' => 0,
                'needs_admin_setup' => true,
                'readable' => true,
                'fresh_database' => true,
            ];
        }

        try {
            $pdo = new PDO('sqlite:' . $databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $hasVersionTable = in_array('schema_version', $tables, true);
            $version = $hasVersionTable ? (int) $pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn() : 0;
            $nonMetadataTables = array_values(array_diff($tables, ['schema_migrations', 'schema_version']));
            $hasAppliedMigrations = false;
            if (in_array('schema_migrations', $tables, true)) {
                $hasAppliedMigrations = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() > 0;
            }
            $freshDatabase = $version === 0 && $nonMetadataTables === [] && !$hasAppliedMigrations;
            return [
                'database_exists' => true,
                'schema_version' => $version,
                // Cualquier esquema 0 se deriva al instalador: allí se distingue una BD vacía/reanudable
                // de una BD desconocida con tablas, que se bloquea sin intentar migrarla.
                'needs_admin_setup' => $version < Version::USER_AUTH_SCHEMA,
                'readable' => true,
                'fresh_database' => $freshDatabase,
            ];
        } catch (Throwable) {
            return [
                'database_exists' => true,
                'schema_version' => null,
                'needs_admin_setup' => false,
                'readable' => false,
                'fresh_database' => false,
            ];
        }
    }
}
