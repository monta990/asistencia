<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec("ALTER TABLE departments ADD COLUMN responsible_email TEXT NOT NULL DEFAULT ''");
};
