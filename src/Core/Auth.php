<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

use PDO;

final class Auth
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_LOCKOUT_MINUTES = 10;

    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Autentica cuentas individuales y aplica bloqueo persistente a partir del umbral configurado. */
    public static function attempt(PDO $pdo, string $username, string $password): bool
    {
        $username = strtolower(trim($username));
        if ($username === '' || strlen($username) > 128 || strlen($password) > 1024) {
            usleep(250000);
            return false;
        }

        $schemaVersion = self::schemaVersion($pdo);
        if ($schemaVersion < Version::USER_AUTH_SCHEMA) {
            $legacyHash = (string) Config::get('admin_password_hash', '');
            $validLegacy = $username === 'administrador'
                && $legacyHash !== ''
                && password_verify($password, $legacyHash);
            if ($validLegacy) {
                self::establishSession([
                    'id' => null,
                    'name' => 'Administrador',
                    'username' => 'administrador',
                    'role' => 'admin',
                    'legacy' => true,
                    'session_version' => 0,
                ]);
                return true;
            }
            usleep(250000);
            return false;
        }

        // Durante la transición 1.2.16 -> 1.2.17, el administrador aún puede entrar
        // para aplicar la migración 005. El bloqueo persistente comienza al completar 005.
        if ($schemaVersion < Version::LOCKOUT_SCHEMA) {
            $statement = $pdo->prepare('SELECT id, name, username, password_hash, role, is_active FROM users WHERE username = :username COLLATE NOCASE LIMIT 1');
            $statement->execute(['username' => $username]);
            $record = $statement->fetch();
            if (!is_array($record) || (int) $record['is_active'] !== 1 || !password_verify($password, (string) $record['password_hash'])) {
                usleep(250000);
                return false;
            }
            self::touchLogin($pdo, (int) $record['id']);
            self::establishSession([
                'id' => (int) $record['id'],
                'name' => (string) $record['name'],
                'username' => (string) $record['username'],
                'role' => (string) $record['role'],
                'legacy' => false,
                'session_version' => 1,
            ]);
            return true;
        }

        $statement = $pdo->prepare('SELECT id, name, username, password_hash, role, is_active, failed_login_attempts, locked_until, session_version FROM users WHERE username = :username COLLATE NOCASE LIMIT 1');
        $statement->execute(['username' => $username]);
        $record = $statement->fetch();
        if (!is_array($record) || (int) $record['is_active'] !== 1) {
            usleep(250000);
            return false;
        }

        $now = time();
        $lockedUntil = self::timestamp((string) ($record['locked_until'] ?? ''));
        if ($lockedUntil > $now) {
            usleep(150000);
            return false;
        }
        if ($lockedUntil > 0 && $lockedUntil <= $now) {
            $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id AND locked_until IS NOT NULL AND locked_until <= :now')
                ->execute(['id' => $record['id'], 'now' => date(DATE_ATOM, $now)]);
            $record['failed_login_attempts'] = 0;
        }

        if (!password_verify($password, (string) $record['password_hash'])) {
            $policy = self::lockoutPolicy($pdo);
            $maxAttempts = $policy['max_attempts'];
            $lockUntil = date(DATE_ATOM, $now + ($policy['lockout_minutes'] * 60));
            // Una sola sentencia atómica evita perder incrementos cuando llegan dos intentos simultáneos.
            $update = $pdo->prepare('UPDATE users SET
                locked_until = CASE WHEN (SELECT failed_login_attempts + 1 FROM users AS current_user WHERE current_user.id = users.id) >= :lockout_threshold THEN :locked_until ELSE locked_until END,
                session_version = CASE WHEN (SELECT failed_login_attempts + 1 FROM users AS current_user WHERE current_user.id = users.id) >= :session_threshold THEN session_version + 1 ELSE session_version END,
                failed_login_attempts = failed_login_attempts + 1
                WHERE id = :id AND is_active = 1 AND (locked_until IS NULL OR locked_until <= :now)');
            $update->execute([
                'lockout_threshold' => $maxAttempts,
                'session_threshold' => $maxAttempts,
                'locked_until' => $lockUntil,
                'id' => $record['id'],
                'now' => date(DATE_ATOM, $now),
            ]);
            usleep(250000);
            return false;
        }

        if (password_needs_rehash((string) $record['password_hash'], PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($hash) && $hash !== '') {
                $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id AND password_hash = :current_hash AND session_version = :session_version')->execute([
                    'hash' => $hash,
                    'id' => $record['id'],
                    'current_hash' => $record['password_hash'],
                    'session_version' => (int) $record['session_version'],
                ]);
            }
        }
        // El desbloqueo por expiración/inicio correcto debe competir de forma atómica
        // con el quinto fallo y con cambios de cuenta hechos por un administrador.
        $successfulLogin = $pdo->prepare('UPDATE users
            SET failed_login_attempts = 0, locked_until = NULL, last_login_at = :last_login
            WHERE id = :id AND is_active = 1 AND session_version = :session_version
                AND (locked_until IS NULL OR locked_until <= :now)');
        $successfulLogin->execute([
            'last_login' => date(DATE_ATOM),
            'id' => $record['id'],
            'session_version' => (int) $record['session_version'],
            'now' => date(DATE_ATOM, time()),
        ]);
        if ($successfulLogin->rowCount() !== 1) {
            usleep(150000);
            return false;
        }
        self::establishSession([
            'id' => (int) $record['id'],
            'name' => (string) $record['name'],
            'username' => (string) $record['username'],
            'role' => (string) $record['role'],
            'legacy' => false,
            'session_version' => (int) $record['session_version'],
        ]);
        return true;
    }

    /** Refresca rol, estado y versión de sesión desde SQLite en cada petición autenticada. */
    public static function refresh(PDO $pdo): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        $id = (int) ($user['id'] ?? 0);
        $schemaVersion = self::schemaVersion($pdo);
        if ($id < 1) {
            return $schemaVersion < Version::SCHEMA_TARGET && !empty($user['legacy']) && ($user['role'] ?? '') === 'admin';
        }

        if ($schemaVersion >= Version::LOCKOUT_SCHEMA) {
            $statement = $pdo->prepare('SELECT id, name, username, role, is_active, locked_until, session_version FROM users WHERE id = :id LIMIT 1');
        } else {
            $statement = $pdo->prepare('SELECT id, name, username, role, is_active FROM users WHERE id = :id LIMIT 1');
        }
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();
        if (!is_array($record) || (int) $record['is_active'] !== 1) {
            return false;
        }
        $sessionVersion = (int) ($record['session_version'] ?? 1);
        if (isset($record['locked_until']) && self::timestamp((string) $record['locked_until']) > time()) {
            return false;
        }
        $knownSessionVersion = (int) ($user['session_version'] ?? $sessionVersion);
        if ($knownSessionVersion !== $sessionVersion) {
            return false;
        }
        $_SESSION['user'] = [
            'id' => (int) $record['id'],
            'name' => (string) $record['name'],
            'username' => (string) $record['username'],
            'role' => (string) $record['role'],
            'legacy' => false,
            'session_version' => $sessionVersion,
        ];
        return true;
    }

    /** Convierte la sesión de acceso legado en la cuenta administrador recién migrada. */
    public static function upgradeLegacySession(PDO $pdo): void
    {
        $user = self::user();
        if ($user === null || (int) ($user['id'] ?? 0) > 0) {
            return;
        }
        $statement = $pdo->query("SELECT id, name, username, role, session_version FROM users WHERE username = 'administrador' COLLATE NOCASE AND is_active = 1 LIMIT 1");
        $record = $statement->fetch();
        if (!is_array($record)) {
            self::logout();
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $record['id'],
            'name' => (string) $record['name'],
            'username' => (string) $record['username'],
            'role' => (string) $record['role'],
            'legacy' => false,
            'session_version' => (int) ($record['session_version'] ?? 1),
        ];
        $_SESSION['_last_activity'] = time();
    }

    public static function updateCurrentSessionVersion(int $version): void
    {
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['session_version'] = max(1, $version);
        }
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function canAccessPage(string $page): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        return in_array($page, ['upload', 'dashboard', 'history', 'departments', 'account'], true)
            && (self::user()['role'] ?? '') === 'nominista';
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    private static function lockoutPolicy(PDO $pdo): array
    {
        try {
            $value = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'security'")->fetchColumn();
            $settings = is_string($value) ? json_decode($value, true) : null;
        } catch (\Throwable) {
            $settings = null;
        }
        $maxAttempts = is_array($settings) ? (int) ($settings['max_login_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS) : self::DEFAULT_MAX_ATTEMPTS;
        $minutes = is_array($settings) ? (int) ($settings['lockout_minutes'] ?? self::DEFAULT_LOCKOUT_MINUTES) : self::DEFAULT_LOCKOUT_MINUTES;
        return [
            'max_attempts' => max(3, min(20, $maxAttempts)),
            'lockout_minutes' => max(1, min(1440, $minutes)),
        ];
    }

    private static function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $parsed = strtotime($value);
        return $parsed === false ? 0 : $parsed;
    }

    private static function schemaVersion(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function touchLogin(PDO $pdo, int $id): void
    {
        $pdo->prepare('UPDATE users SET last_login_at = :last_login WHERE id = :id')->execute(['last_login' => date(DATE_ATOM), 'id' => $id]);
    }

    private static function establishSession(array $user): void
    {
        unset($_SESSION['_csrf'], $_SESSION['_login_window'], $_SESSION['_login_attempts']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['_last_activity'] = time();
    }
}
