<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;

return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE users ADD COLUMN name TEXT NOT NULL DEFAULT 'Usuario'");
    $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'nominista' CHECK (role IN ('admin', 'nominista'))");
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1))');
    $pdo->exec('CREATE INDEX idx_users_role_active ON users(role, is_active)');

    $legacyAdminHash = (string) Config::get('admin_password_hash', '');
    if ($legacyAdminHash === '') {
        throw new RuntimeException('No se encontró el hash administrativo anterior; no se puede crear el acceso administrador.');
    }

    $insert = $pdo->prepare("INSERT OR IGNORE INTO users (username, email, password_hash, created_at, last_login_at, name, role, is_active) VALUES ('administrador', '', :password_hash, :created_at, NULL, 'Administrador', 'admin', 1)");
    $insert->execute(['password_hash' => $legacyAdminHash, 'created_at' => date(DATE_ATOM)]);

    $adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'administrador' COLLATE NOCASE LIMIT 1")->fetchColumn();
    if ($adminId < 1) {
        throw new RuntimeException('No fue posible inicializar el usuario administrador.');
    }
    $pdo->prepare("UPDATE users SET name = CASE WHEN trim(name) = '' OR name = 'Usuario' THEN 'Administrador' ELSE name END, role = 'admin', is_active = 1 WHERE id = :id")->execute(['id' => $adminId]);

    // Antes de esta versión el sistema solo tenía un acceso administrativo compartido.
    // Las ejecuciones históricas sin autor se atribuyen a ese único administrador existente.
    $pdo->prepare('UPDATE report_runs SET created_by = :admin_id WHERE created_by IS NULL')->execute(['admin_id' => $adminId]);
};
