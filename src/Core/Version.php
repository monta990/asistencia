<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

use PDO;

final class Version
{
    public const VERSION = '1.2.31';
    public const SCHEMA_TARGET = 6;
    public const LOCKOUT_SCHEMA = 5;
    public const USER_AUTH_SCHEMA = 3;
    public const MINIMUM_PHP = 80500;

    /** Solo se requieren las extensiones imprescindibles del procesamiento XLSX, SQLite y SMTP seguro. */
    public static function assertRuntime(): void
    {
        $required = ['pdo_sqlite', 'zip', 'dom', 'openssl'];
        $missing = array_values(array_filter($required, static fn (string $ext): bool => !extension_loaded($ext)));
        if ($missing !== []) {
            throw new \RuntimeException('Faltan extensiones PHP imprescindibles: ' . implode(', ', $missing));
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('El controlador PDO_SQLite no está disponible. Habilita pdo_sqlite en php.ini.');
        }
    }
}
