<?php
declare(strict_types=1);

namespace ZkTimeNet\Repositories;

use PDO;
use PDOException;
use RuntimeException;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query('SELECT id, name, username, email, role, is_active, created_at, last_login_at, failed_login_attempts, locked_until FROM users ORDER BY is_active DESC, CASE role WHEN \'admin\' THEN 0 ELSE 1 END, name COLLATE NOCASE')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, username, email, role, is_active, created_at, last_login_at, failed_login_attempts, locked_until FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function save(?int $id, string $name, string $username, string $email, string $password, string $role): int
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        $username = strtolower(trim($username));
        $role = strtolower(trim($role));
        $email = strtolower(trim($email));
        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('Escribe el nombre de la persona (máximo 120 caracteres).');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $username) !== 1) {
            throw new RuntimeException('El nombre de usuario debe tener entre 3 y 32 caracteres: letras, números, punto, guion o guion bajo.');
        }
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new RuntimeException('Escribe un correo electrónico válido (máximo 254 caracteres).');
        }
        if (!in_array($role, ['admin', 'nominista'], true)) {
            throw new RuntimeException('Selecciona un perfil válido.');
        }

        $existing = $id !== null ? $this->findWithPassword($id) : null;
        if ($id !== null && $existing === null) {
            throw new RuntimeException('No se encontró el usuario que deseas modificar.');
        }
        if ($id === null && strlen($password) < 8) {
            throw new RuntimeException('La contraseña inicial debe tener al menos 8 caracteres.');
        }
        if ($password !== '' && (strlen($password) < 8 || strlen($password) > 255)) {
            throw new RuntimeException('La contraseña debe tener al menos 8 caracteres y no superar 255 bytes.');
        }

        $willBeActiveAdmin = $role === 'admin' && ($existing === null || (int) $existing['is_active'] === 1);
        $wasActiveAdmin = $existing !== null && $existing['role'] === 'admin' && (int) $existing['is_active'] === 1;
        if ($wasActiveAdmin && !$willBeActiveAdmin && $this->activeAdminCount() <= 1) {
            throw new RuntimeException('Debe permanecer al menos un administrador activo.');
        }

        try {
            if ($existing === null) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($hash) || $hash === '') {
                    throw new RuntimeException('No se pudo proteger la contraseña del usuario.');
                }
                $statement = $this->pdo->prepare('INSERT INTO users (username, email, password_hash, created_at, last_login_at, name, role, is_active) VALUES (:username, :email, :password_hash, :created_at, NULL, :name, :role, 1)');
                $statement->execute([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => $hash,
                    'created_at' => date(DATE_ATOM),
                    'name' => $name,
                    'role' => $role,
                ]);
                return (int) $this->pdo->lastInsertId();
            }

            $parameters = ['username' => $username, 'email' => $email, 'name' => $name, 'role' => $role, 'id' => $id];
            $sql = 'UPDATE users SET username = :username, email = :email, name = :name, role = :role';
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($hash) || $hash === '') {
                    throw new RuntimeException('No se pudo proteger la nueva contraseña.');
                }
                $sql .= ', password_hash = :password_hash';
                $parameters['password_hash'] = $hash;
            }
            if ($password !== '') {
                $sql .= ', failed_login_attempts = 0, locked_until = NULL';
            }
            $sql .= ', session_version = session_version + 1 WHERE id = :id';
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return $id;
        } catch (PDOException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new RuntimeException('Ese nombre de usuario ya está ocupado. Elige otro.');
            }
            throw $exception;
        }
    }

    /** Actualiza el correo de notificación de la cuenta autenticada. Una cadena vacía desactiva el resumen por correo. */
    public function saveOwnEmail(int $id, string $email): void
    {
        $email = strtolower(trim($email));
        if ($id < 1) {
            throw new RuntimeException('No se pudo identificar tu cuenta. Cierra sesión y vuelve a ingresar.');
        }
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new RuntimeException('Escribe un correo electrónico válido (máximo 254 caracteres).');
        }
        $statement = $this->pdo->prepare('UPDATE users SET email = :email WHERE id = :id AND is_active = 1');
        $statement->execute(['email' => $email, 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            $check = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $check->execute(['id' => $id]);
            if ($check->fetchColumn() === false) {
                throw new RuntimeException('No se encontró una cuenta activa para actualizar.');
            }
        }
    }

    public function changeOwnPassword(int $id, string $currentPassword, string $newPassword, string $confirmation): int
    {
        if ($id < 1) {
            throw new RuntimeException('No se pudo identificar tu cuenta. Cierra sesión y vuelve a ingresar.');
        }
        if ($currentPassword === '' || strlen($currentPassword) > 255) {
            throw new RuntimeException('Escribe tu contraseña actual.');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 255) {
            throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres y no superar 255 bytes.');
        }
        if (!hash_equals($newPassword, $confirmation)) {
            throw new RuntimeException('La confirmación de la nueva contraseña no coincide.');
        }

        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $id]);
        $currentHash = $statement->fetchColumn();
        if (!is_string($currentHash) || $currentHash === '' || !password_verify($currentPassword, $currentHash)) {
            throw new RuntimeException('La contraseña actual no es correcta.');
        }
        if (password_verify($newPassword, $currentHash)) {
            throw new RuntimeException('La nueva contraseña debe ser diferente de la actual.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No se pudo proteger la nueva contraseña.');
        }
        $update = $this->pdo->prepare('UPDATE users SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL, session_version = session_version + 1 WHERE id = :id AND is_active = 1');
        $update->execute(['hash' => $hash, 'id' => $id]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('No se pudo actualizar la contraseña de tu cuenta.');
        }
        $version = $this->pdo->prepare('SELECT session_version FROM users WHERE id = :id');
        $version->execute(['id' => $id]);
        return max(1, (int) $version->fetchColumn());
    }

    public function setActive(int $id, bool $active, int $currentUserId): void
    {
        if ($id === $currentUserId) {
            throw new RuntimeException('No puedes habilitar ni deshabilitar la cuenta con la que tienes abierta esta sesión.');
        }
        $user = $this->find($id);
        if ($user === null) {
            throw new RuntimeException('No se encontró el usuario seleccionado.');
        }
        if (!$active && $user['role'] === 'admin' && (int) $user['is_active'] === 1 && $this->activeAdminCount() <= 1) {
            throw new RuntimeException('No puedes desactivar al último administrador activo.');
        }
        $statement = $this->pdo->prepare('UPDATE users SET is_active = :active, failed_login_attempts = 0, locked_until = NULL, session_version = session_version + 1 WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    /** Desbloquea cualquier cuenta, incluidos otros administradores. */
    public function unlock(int $id): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new RuntimeException('No se encontró el usuario seleccionado.');
        }
        $statement = $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, session_version = session_version + 1 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function activeAdminCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    }

    private function findWithPassword(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, username, role, is_active, password_hash FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
