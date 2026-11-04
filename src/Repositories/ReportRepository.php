<?php
declare(strict_types=1);

namespace ZkTimeNet\Repositories;

use PDO;

final class ReportRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createRun(array $run, array $files): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('INSERT INTO report_runs (id, original_filename, archive_name, archive_path, week, iso_year, start_date, end_date, departments_count, status, created_by, created_at) VALUES (:id, :original, :archive_name, :archive_path, :week, :year, :start_date, :end_date, :count, :status, :user, :created_at)');
            $statement->execute([
                'id' => $run['id'], 'original' => $run['original_filename'], 'archive_name' => $run['archive_name'],
                'archive_path' => $run['archive_path'], 'week' => $run['week'], 'year' => $run['year'],
                'start_date' => $run['start_date'], 'end_date' => $run['end_date'], 'count' => count($files),
                'status' => $run['status'], 'user' => $run['created_by'], 'created_at' => date(DATE_ATOM),
            ]);
            $insert = $this->pdo->prepare('INSERT INTO report_department_files (run_id, department_id, report_label, department_name, file_name, file_path, recipient_snapshot, delivery_status) VALUES (:run_id, :department_id, :label, :name, :file_name, :file_path, :recipients, :status)');
            foreach ($files as $file) {
                $insert->execute([
                    'run_id' => $run['id'], 'department_id' => $file['department_id'], 'label' => $file['report_label'],
                    'name' => $file['department_name'], 'file_name' => $file['file_name'], 'file_path' => $file['relative_path'],
                    'recipients' => json_encode($file['recipients'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status' => $file['delivery_status'] ?? 'pending',
                ]);
                $file['db_id'] = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function departmentFiles(string $runId): array
    {
        $statement = $this->pdo->prepare('SELECT f.*, COALESCE(d.enabled, 0) AS department_enabled, COALESCE(d.needs_configuration, 0) AS needs_configuration, (SELECT COUNT(*) FROM mail_events e WHERE e.department_file_id = f.id) AS mail_attempt_count FROM report_department_files f LEFT JOIN departments d ON d.id = f.department_id WHERE f.run_id = :id ORDER BY f.id');
        $statement->execute(['id' => $runId]);
        return $statement->fetchAll();
    }

    /** Obtiene los datos históricos necesarios para reenviar un archivo de departamento. */
    public function findDepartmentFileForDelivery(int $fileId): ?array
    {
        $statement = $this->pdo->prepare("SELECT f.*, COALESCE(d.enabled, 0) AS department_enabled, COALESCE(d.needs_configuration, 0) AS needs_configuration, r.original_filename, r.week, r.iso_year, r.start_date, r.end_date, r.created_at AS run_created_at FROM report_department_files f INNER JOIN report_runs r ON r.id = f.run_id LEFT JOIN departments d ON d.id = f.department_id WHERE f.id = :id LIMIT 1");
        $statement->execute(['id' => $fileId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function updateDelivery(int $fileId, string $status, string $error, array $recipientResults): void
    {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE report_department_files SET delivery_status = :status, delivery_error = :error, delivered_at = :delivered WHERE id = :id');
            $update->execute(['status' => $status, 'error' => $error, 'delivered' => $status === 'sent' ? date(DATE_ATOM) : null, 'id' => $fileId]);
            $runIdStatement = $this->pdo->prepare('SELECT run_id FROM report_department_files WHERE id = :id');
            $runIdStatement->execute(['id' => $fileId]);
            $runId = (string) $runIdStatement->fetchColumn();
            $log = $this->pdo->prepare('INSERT INTO mail_events (run_id, department_file_id, recipients, status, error_text, created_at) VALUES (:run_id, :file_id, :recipients, :status, :error, :now)');
            $log->execute(['run_id' => $runId, 'file_id' => $fileId, 'recipients' => json_encode($recipientResults, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => $status, 'error' => $error, 'now' => date(DATE_ATOM)]);
            $this->updateRunCounters($runId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateRunCounters(string $runId): void
    {
        $statement = $this->pdo->prepare("UPDATE report_runs SET email_sent_count = (SELECT COUNT(*) FROM report_department_files WHERE run_id = :sent_id AND delivery_status = 'sent'), email_failed_count = (SELECT COUNT(*) FROM report_department_files WHERE run_id = :failed_id AND delivery_status = 'failed'), status = CASE WHEN EXISTS (SELECT 1 FROM report_department_files WHERE run_id = :error_id AND delivery_status = 'failed') THEN 'completed_with_errors' WHEN EXISTS (SELECT 1 FROM report_department_files WHERE run_id = :done_id AND delivery_status = 'sent') THEN 'completed_sent' ELSE 'completed' END WHERE id = :run_id");
        $statement->execute(['sent_id' => $runId, 'failed_id' => $runId, 'error_id' => $runId, 'done_id' => $runId, 'run_id' => $runId]);
    }

    public function mailEvents(string $runId): array
    {
        $statement = $this->pdo->prepare('SELECT e.*, f.department_name FROM mail_events e LEFT JOIN report_department_files f ON f.id = e.department_file_id WHERE e.run_id = :run_id ORDER BY e.id');
        $statement->execute(['run_id' => $runId]);
        return $statement->fetchAll();
    }

    public function all(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->pdo->query("SELECT r.*, COALESCE(u.name, u.username, 'Usuario anterior') AS created_by_name FROM report_runs r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.created_at DESC LIMIT " . $limit)->fetchAll();
    }

    public function findRun(string $id): ?array
    {
        $statement = $this->pdo->prepare("SELECT r.*, COALESCE(u.name, u.username, 'Usuario anterior') AS created_by_name FROM report_runs r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = :id");
        $statement->execute(['id' => $id]);
        $run = $statement->fetch();
        return is_array($run) ? $run : null;
    }


    /** Renombra únicamente el nombre base del archivo fuente, conservando su extensión .xlsx. */
    public function renameRunOriginalFilename(string $id, string $baseName): string
    {
        $run = $this->findRun($id);
        if ($run === null) {
            throw new \RuntimeException('No se encontró la ejecución que deseas renombrar.');
        }
        $extension = strtolower((string) pathinfo((string) $run['original_filename'], PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            throw new \RuntimeException('La extensión del archivo original no es válida.');
        }

        $baseName = trim($baseName);
        if (preg_match('/\\.xlsx$/i', $baseName) === 1) {
            $baseName = substr($baseName, 0, -5);
        }
        if ($baseName === '' || strlen($baseName) > 120
            || preg_match('/[\\\\\\/:*?"<>|\\x00-\\x1F\\x7F]/u', $baseName) === 1
            || $baseName === '.' || $baseName === '..') {
            throw new \InvalidArgumentException('El nombre debe tener entre 1 y 120 caracteres y no incluir caracteres reservados de archivo.');
        }
        $newName = $baseName . '.xlsx';
        $statement = $this->pdo->prepare('UPDATE report_runs SET original_filename = :name WHERE id = :id');
        $statement->execute(['name' => $newName, 'id' => $id]);
        return $newName;
    }

    public function stats(): array
    {
        return [
            'runs' => (int) $this->pdo->query('SELECT COUNT(*) FROM report_runs')->fetchColumn(),
            'departments' => (int) $this->pdo->query('SELECT COUNT(*) FROM departments WHERE enabled = 1')->fetchColumn(),
            'sent' => (int) $this->pdo->query("SELECT COUNT(*) FROM report_department_files WHERE delivery_status = 'sent'")->fetchColumn(),
            'pending_recipients' => (int) $this->pdo->query("SELECT COUNT(*) FROM departments d WHERE d.enabled = 1 AND d.needs_configuration = 0 AND trim(coalesce(d.responsible_email, '')) = '' AND NOT EXISTS (SELECT 1 FROM department_recipients r WHERE r.department_id = d.id AND r.enabled = 1)")->fetchColumn(),
            'pending_configuration' => (int) $this->pdo->query('SELECT COUNT(*) FROM departments WHERE needs_configuration = 1')->fetchColumn(),
        ];
    }
}
