<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE users ADD COLUMN failed_login_attempts INTEGER NOT NULL DEFAULT 0 CHECK (failed_login_attempts >= 0)');
    $pdo->exec('ALTER TABLE users ADD COLUMN locked_until TEXT NULL');
    $pdo->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1 CHECK (session_version >= 1)');
    $pdo->exec('CREATE INDEX idx_users_locked_until ON users(locked_until)');
};
