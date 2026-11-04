<?php
declare(strict_types=1);

namespace ZkTimeNet\Repositories;

use PDO;

final class DepartmentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(bool $includeDisabled = true): array
    {
        $sql = "SELECT d.*, (SELECT COUNT(*) FROM department_recipients r WHERE r.department_id = d.id AND r.enabled = 1 AND lower(trim(r.email)) <> lower(trim(coalesce(d.responsible_email, '')))) + CASE WHEN trim(coalesce(d.responsible_email, '')) <> '' THEN 1 ELSE 0 END AS recipient_count FROM departments d";
        if (!$includeDisabled) {
            $sql .= ' WHERE d.enabled = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY d.position, d.display_name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM departments WHERE id = :id');
        $statement->execute(['id' => $id]);
        $department = $statement->fetch();
        if (!is_array($department)) {
            return null;
        }
        $recipients = $this->pdo->prepare('SELECT email, display_name, enabled FROM department_recipients WHERE department_id = :id ORDER BY email');
        $recipients->execute(['id' => $id]);
        $department['recipients'] = $recipients->fetchAll();
        return $department;
    }

    public function findByReportLabel(string $label): ?array
    {
        $normalized = self::normalize($label);
        foreach ($this->all(true) as $department) {
            if (self::normalize((string) $department['report_label']) === $normalized) {
                return $department;
            }
        }
        return null;
    }

    /**
     * Registra una etiqueta nueva que se encontró en un XLSX.
     * La crea deshabilitada y pendiente para que no se distribuya hasta revisarla.
     * @return array{department:array,created:bool}
     */
    public function ensureDiscovered(string $reportLabel): array
    {
        $reportLabel = trim(preg_replace('/\s+/u', ' ', $reportLabel) ?? $reportLabel);
        if ($reportLabel === '' || strlen($reportLabel) > 160) {
            throw new \RuntimeException('La etiqueta de un departamento nuevo está vacía o supera 160 caracteres.');
        }
        $existing = $this->findByReportLabel($reportLabel);
        if ($existing !== null) {
            return ['department' => $existing, 'created' => false];
        }

        $position = (int) $this->pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM departments')->fetchColumn();
        $now = date(DATE_ATOM);
        try {
            $statement = $this->pdo->prepare('INSERT INTO departments (report_label, display_name, slug, enabled, needs_configuration, position, created_at, updated_at) VALUES (:label, :name, :slug, 0, 1, :position, :created_at, :updated_at)');
            $statement->execute([
                'label' => $reportLabel,
                'name' => $reportLabel,
                'slug' => self::slug($reportLabel),
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created = true;
        } catch (\PDOException $exception) {
            // Una petición paralela pudo insertar la misma etiqueta entre la búsqueda y el INSERT.
            $existing = $this->findByReportLabel($reportLabel);
            if ($existing === null) {
                throw $exception;
            }
            return ['department' => $existing, 'created' => false];
        }

        $department = $this->findByReportLabel($reportLabel);
        if ($department === null) {
            throw new \RuntimeException('No fue posible registrar el departamento detectado: ' . $reportLabel . '.');
        }
        return ['department' => $department, 'created' => $created];
    }

    public function isEnabled(int $departmentId): bool
    {
        $statement = $this->pdo->prepare('SELECT enabled FROM departments WHERE id = :id');
        $statement->execute(['id' => $departmentId]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function save(?int $id, string $reportLabel, string $displayName, string $responsibleEmail, bool $enabled, int $position, array $emails): int
    {
        $reportLabel = trim(preg_replace('/\s+/u', ' ', $reportLabel) ?? $reportLabel);
        $displayName = trim($displayName);
        if ($reportLabel === '' || $displayName === '') {
            throw new \InvalidArgumentException('La etiqueta de origen y el nombre del departamento son obligatorios.');
        }
        if (strlen($reportLabel) > 160 || strlen($displayName) > 120) {
            throw new \InvalidArgumentException('El nombre del departamento supera la longitud permitida.');
        }
        $responsibleEmail = strtolower(trim($responsibleEmail));
        if ($responsibleEmail !== '' && (strlen($responsibleEmail) > 254 || filter_var($responsibleEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('El correo del responsable no es válido.');
        }
        $normalizedLabel = self::normalize($reportLabel);
        foreach ($this->all(true) as $existing) {
            if ((int) $existing['id'] !== (int) ($id ?? 0) && self::normalize((string) $existing['report_label']) === $normalizedLabel) {
                throw new \InvalidArgumentException('Ya existe otro departamento con esa misma etiqueta de origen.');
            }
        }
        $cleanEmails = [];
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                throw new \InvalidArgumentException('Correo electrónico no válido: ' . $email);
            }
            $emailKey = strtolower($email);
            if (isset($cleanEmails[$emailKey])) {
                continue;
            }
            if (count($cleanEmails) >= 50) {
                throw new \InvalidArgumentException('Cada departamento admite un máximo de 50 destinatarios.');
            }
            $cleanEmails[$emailKey] = $email;
        }
        $slug = self::slug($displayName);
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $statement = $this->pdo->prepare('INSERT INTO departments (report_label, display_name, slug, responsible_email, enabled, position, created_at, updated_at) VALUES (:label, :name, :slug, :responsible, :enabled, :position, :created_at, :updated_at)');
                $statement->execute(['label' => $reportLabel, 'name' => $displayName, 'slug' => $slug, 'responsible' => $responsibleEmail, 'enabled' => (int) $enabled, 'position' => $position, 'created_at' => date(DATE_ATOM), 'updated_at' => date(DATE_ATOM)]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $statement = $this->pdo->prepare('UPDATE departments SET report_label = :label, display_name = :name, slug = :slug, responsible_email = :responsible, enabled = :enabled, needs_configuration = 0, position = :position, updated_at = :now WHERE id = :id');
                $statement->execute(['label' => $reportLabel, 'name' => $displayName, 'slug' => $slug, 'responsible' => $responsibleEmail, 'enabled' => (int) $enabled, 'position' => $position, 'now' => date(DATE_ATOM), 'id' => $id]);
            }
            $delete = $this->pdo->prepare('DELETE FROM department_recipients WHERE department_id = :id');
            $delete->execute(['id' => $id]);
            $insert = $this->pdo->prepare('INSERT INTO department_recipients (department_id, email, display_name, enabled, created_at) VALUES (:id, :email, :display_name, 1, :now)');
            foreach ($cleanEmails as $email) {
                $insert->execute(['id' => $id, 'email' => $email, 'display_name' => '', 'now' => date(DATE_ATOM)]);
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM departments WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function seedDefaults(): int
    {
        $seed = require dirname(__DIR__, 2) . '/database/seeds/departments.php';
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO departments (report_label, display_name, slug, enabled, position, created_at, updated_at) VALUES (:label, :name, :slug, 1, :position, :created_at, :updated_at)');
        $inserted = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($seed as $index => $name) {
                $statement->execute(['label' => $name, 'name' => $name, 'slug' => self::slug($name), 'position' => $index + 1, 'created_at' => date(DATE_ATOM), 'updated_at' => date(DATE_ATOM)]);
                $inserted += $statement->rowCount();
            }
            $this->pdo->commit();
            return $inserted;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function responsibleEmail(int $departmentId): string
    {
        $statement = $this->pdo->prepare('SELECT responsible_email FROM departments WHERE id = :id');
        $statement->execute(['id' => $departmentId]);
        return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
    }

    public function recipients(int $departmentId): array
    {
        $statement = $this->pdo->prepare('SELECT email FROM department_recipients WHERE department_id = :id AND enabled = 1 ORDER BY email');
        $statement->execute(['id' => $departmentId]);
        return array_map(static fn (array $row): string => (string) $row['email'], $statement->fetchAll());
    }

    /**
     * Direcciones que deben recibir el Excel: destinatarios activos más el responsable.
     * El responsable recibe siempre su copia aunque no esté en la lista de destinatarios.
     * @return list<string>
     */
    public function deliveryRecipients(int $departmentId): array
    {
        $emails = $this->recipients($departmentId);
        $responsible = $this->responsibleEmail($departmentId);
        if ($responsible !== '') {
            $emails[] = $responsible;
        }

        $unique = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $unique[$email] = $email;
            }
        }
        return array_values($unique);
    }

    public static function normalize(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return strtolower($value);
    }

    public static function slug(string $value): string
    {
        $ascii = strtr(trim($value), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $slug = strtolower($ascii);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'departamento';
    }
}
