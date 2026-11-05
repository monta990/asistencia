<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        $path = (string) Config::get('database');
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('El directorio privado de SQLite no existe o no tiene permisos de escritura para IIS.');
        }
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            self::$connection = $pdo;
            return $pdo;
        } catch (PDOException $exception) {
            throw new \RuntimeException('No se pudo abrir la base de datos SQLite.', 0, $exception);
        }
    }
}
