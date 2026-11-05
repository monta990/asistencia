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

    /**
     * Reemplaza el conjunto de salidas de una ejecución conservando sus IDs cuando
     * el departamento sigue presente. Así las referencias de auditoría de correo
     * continúan asociadas al mismo registro y los intentos anteriores no se borran.
     *
     * @return array{archive_path:string,department_paths:list<string>}
     */
    public function replaceRunOutputs(string $runId, array $run, array $files): array
    {
        $existingRun = $this->findRun($runId);
        if ($existingRun === null) {
            throw new \RuntimeException('No se encontró la ejecución que deseas regenerar.');
        }
        if ($files === []) {
            throw new \RuntimeException('La regeneración no produjo archivos de departamento.');
        }

        $existingFiles = $this->departmentFiles($runId);
        $oldDepartmentPaths = [];
        $oldArchivePath = trim((string) ($existingRun['archive_path'] ?? ''));
        foreach ($existingFiles as $existingFile) {
            $path = trim((string) ($existingFile['file_path'] ?? ''));
            if ($path !== '') {
                $oldDepartmentPaths[] = $path;
            }
        }

        $existingByKey = [];
        foreach ($existingFiles as $existingFile) {
            $departmentId = (int) ($existingFile['department_id'] ?? 0);
            $key = $departmentId > 0
                ? 'id:' . $departmentId
                : 'label:' . self::normalizeDepartmentKey((string) $existingFile['report_label']);
            $existingByKey[$key] = $existingFile;
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE report_department_files
                SET department_id = :department_id,
                    report_label = :label,
                    department_name = :name,
                    file_name = :file_name,
                    file_path = :file_path,
                    recipient_snapshot = :recipients,
                    delivery_status = :status,
                    delivery_error = \'\',
                    delivered_at = NULL
                WHERE id = :id');
            $insert = $this->pdo->prepare('INSERT INTO report_department_files
                (run_id, department_id, report_label, department_name, file_name, file_path, recipient_snapshot, delivery_status)
                VALUES (:run_id, :department_id, :label, :name, :file_name, :file_path, :recipients, :status)');

            foreach ($files as $file) {
                $departmentId = isset($file['department_id']) && (int) $file['department_id'] > 0
                    ? (int) $file['department_id']
                    : null;
                $label = trim((string) ($file['report_label'] ?? ''));
                $name = trim((string) ($file['department_name'] ?? ''));
                $fileName = trim((string) ($file['file_name'] ?? ''));
                $filePath = trim((string) ($file['relative_path'] ?? ''));
                $recipients = is_array($file['recipients'] ?? null) ? $file['recipients'] : [];
                $status = (string) ($file['delivery_status'] ?? 'not_requested');
                if ($label === '' || $name === '' || $fileName === '' || $filePath === ''
                    || !in_array($status, ['disabled', 'no_recipients', 'not_requested', 'pending'], true)) {
                    throw new \RuntimeException('La regeneración produjo información incompleta para un departamento.');
                }
                $key = $departmentId !== null
                    ? 'id:' . $departmentId
                    : 'label:' . self::normalizeDepartmentKey($label);
                $params = [
                    'department_id' => $departmentId,
                    'label' => $label,
                    'name' => $name,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'recipients' => json_encode(array_values($recipients), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status' => $status,
                ];

                if (isset($existingByKey[$key])) {
                    $existingId = (int) $existingByKey[$key]['id'];
                    $update->execute($params + ['id' => $existingId]);
                    unset($existingByKey[$key]);
                } else {
                    $insert->execute(['run_id' => $runId] + $params);
                }
            }

            foreach ($existingByKey as $removed) {
                $this->pdo->prepare('DELETE FROM report_department_files WHERE id = :id AND run_id = :run_id')
                    ->execute(['id' => (int) $removed['id'], 'run_id' => $runId]);
            }

            $updateRun = $this->pdo->prepare('UPDATE report_runs
                SET archive_name = :archive_name,
                    archive_path = :archive_path,
                    week = :week,
                    iso_year = :year,
                    start_date = :start_date,
                    end_date = :end_date,
                    departments_count = :count,
                    email_sent_count = 0,
                    email_failed_count = 0,
                    status = \'completed\'
                WHERE id = :id');
            $updateRun->execute([
                'archive_name' => (string) ($run['archive_name'] ?? ''),
                'archive_path' => (string) ($run['archive_path'] ?? ''),
                'week' => (string) ($run['week'] ?? ''),
                'year' => (string) ($run['year'] ?? ''),
                'start_date' => (string) ($run['start_date'] ?? ''),
                'end_date' => (string) ($run['end_date'] ?? ''),
                'count' => count($files),
                'id' => $runId,
            ]);
            if ($updateRun->rowCount() !== 1) {
                throw new \RuntimeException('No se pudo actualizar el resumen de la ejecución regenerada.');
            }
            $this->updateRunCounters($runId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['archive_path' => $oldArchivePath, 'department_paths' => array_values(array_unique($oldDepartmentPaths))];
    }

    private static function normalizeDepartmentKey(string $label): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));
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

    public function updateDelivery(int $fileId, string $status, string $error, array $recipientResults, ?string $batchId = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE report_department_files SET delivery_status = :status, delivery_error = :error, delivered_at = :delivered WHERE id = :id');
            $update->execute(['status' => $status, 'error' => $error, 'delivered' => $status === 'sent' ? date(DATE_ATOM) : null, 'id' => $fileId]);
            $runIdStatement = $this->pdo->prepare('SELECT run_id FROM report_department_files WHERE id = :id');
            $runIdStatement->execute(['id' => $fileId]);
            $runId = (string) $runIdStatement->fetchColumn();
            $log = $this->pdo->prepare('INSERT INTO mail_events (run_id, department_file_id, recipients, status, error_text, created_at, batch_id) VALUES (:run_id, :file_id, :recipients, :status, :error, :now, :batch_id)');
            $log->execute(['run_id' => $runId, 'file_id' => $fileId, 'recipients' => json_encode($recipientResults, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => $status, 'error' => $error, 'now' => date(DATE_ATOM), 'batch_id' => $batchId]);
            $eventId = (int) $this->pdo->lastInsertId();
            $this->updateRunCounters($runId);
            $this->pdo->commit();
            return $eventId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Crea una sesión de envío masivo ligada al usuario y a una ejecución concreta. */
    public function createBulkMailBatch(string $runId, int $userId): string
    {
        if ($userId < 1 || $this->findRun($runId) === null) {
            throw new \RuntimeException('No se pudo validar la ejecución o el usuario que inicia el envío masivo.');
        }
        $batchId = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare("INSERT INTO bulk_mail_batches (id, run_id, user_id, started_at, summary_status) VALUES (:id, :run_id, :user_id, :started_at, 'pending')");
        $statement->execute(['id' => $batchId, 'run_id' => $runId, 'user_id' => $userId, 'started_at' => date(DATE_ATOM)]);
        return $batchId;
    }

    public function findBulkMailBatch(string $batchId, int $userId, string $runId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bulk_mail_batches WHERE id = :id AND user_id = :user_id AND run_id = :run_id LIMIT 1');
        $statement->execute(['id' => $batchId, 'user_id' => $userId, 'run_id' => $runId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Reclama el cierre una sola vez para evitar enviar dos resúmenes al usuario. */
    public function claimBulkMailSummary(string $batchId, int $userId, string $runId): bool
    {
        $statement = $this->pdo->prepare("UPDATE bulk_mail_batches SET completed_at = COALESCE(completed_at, :now), summary_status = 'processing', summary_error = '' WHERE id = :id AND user_id = :user_id AND run_id = :run_id AND summary_status = 'pending'");
        $statement->execute(['now' => date(DATE_ATOM), 'id' => $batchId, 'user_id' => $userId, 'run_id' => $runId]);
        return $statement->rowCount() === 1;
    }

    public function updateBulkMailSummaryStatus(string $batchId, string $status, string $error = ''): void
    {
        if (!in_array($status, ['sent', 'no_email', 'failed'], true)) {
            throw new \InvalidArgumentException('El estado del resumen de envío masivo no es válido.');
        }
        $statement = $this->pdo->prepare("UPDATE bulk_mail_batches SET summary_status = :status, summary_error = :error, summary_sent_at = CASE WHEN :status_sent = 'sent' THEN :sent_at ELSE summary_sent_at END WHERE id = :id");
        $statement->execute(['status' => $status, 'error' => substr($error, 0, 1000), 'status_sent' => $status, 'sent_at' => date(DATE_ATOM), 'id' => $batchId]);
    }

    /** @return list<array<string,mixed>> */
    public function bulkMailEvents(string $batchId): array
    {
        $statement = $this->pdo->prepare('SELECT e.*, f.department_name FROM mail_events e LEFT JOIN report_department_files f ON f.id = e.department_file_id WHERE e.batch_id = :batch_id ORDER BY e.id');
        $statement->execute(['batch_id' => $batchId]);
        return $statement->fetchAll();
    }

    /** Registra un departamento omitido sin confundirlo con un fallo SMTP. */
    public function recordBulkSkip(int $fileId, string $runId, string $batchId, string $status, string $reason): void
    {
        if (!in_array($status, ['disabled', 'no_recipients'], true)) {
            throw new \InvalidArgumentException('El motivo de omisión no es válido.');
        }
        $this->recordBulkEvent($fileId, $runId, $batchId, $status, 'skipped', [
            'sent' => [], 'failed' => [], 'skipped' => true, 'message' => $reason,
        ], $reason);
    }

    /** Registra un fallo de preparación o de comunicación que no produjo un resultado SMTP normal. */
    public function recordBulkFailure(int $fileId, string $runId, string $batchId, string $reason): void
    {
        $this->recordBulkEvent($fileId, $runId, $batchId, 'failed', 'failed', [
            'sent' => [], 'failed' => [], 'error' => $reason,
        ], $reason);
    }

    /** Registra un error de red comunicado por el navegador solo si el servidor no guardó ya el intento. */
    public function recordBulkClientIssue(int $fileId, string $runId, string $batchId, string $reason): void
    {
        $reason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $reason) ?? '';
        $reason = trim(substr($reason, 0, 500));
        if ($reason === '') {
            $reason = 'No se recibió confirmación del navegador.';
        }
        $this->recordBulkEvent($fileId, $runId, $batchId, 'failed', 'failed', [
            'sent' => [], 'failed' => [], 'error' => 'No se recibió confirmación del servidor: ' . $reason,
        ], 'No se recibió confirmación del servidor: ' . $reason, true);
    }

    private function recordBulkEvent(int $fileId, string $runId, string $batchId, string $fileStatus, string $eventStatus, array $result, string $error, bool $onlyWhenMissing = false): void
    {
        $this->pdo->beginTransaction();
        try {
            $file = $this->pdo->prepare('SELECT run_id FROM report_department_files WHERE id = :id LIMIT 1');
            $file->execute(['id' => $fileId]);
            if ((string) $file->fetchColumn() !== $runId) {
                throw new \RuntimeException('El archivo no pertenece a la ejecución del envío masivo.');
            }
            $batch = $this->pdo->prepare('SELECT 1 FROM bulk_mail_batches WHERE id = :id AND run_id = :run_id LIMIT 1');
            $batch->execute(['id' => $batchId, 'run_id' => $runId]);
            if ($batch->fetchColumn() === false) {
                throw new \RuntimeException('No se encontró el lote de envío masivo.');
            }
            if ($onlyWhenMissing) {
                $existing = $this->pdo->prepare('SELECT 1 FROM mail_events WHERE batch_id = :batch_id AND department_file_id = :file_id LIMIT 1');
                $existing->execute(['batch_id' => $batchId, 'file_id' => $fileId]);
                if ($existing->fetchColumn() !== false) {
                    $this->pdo->commit();
                    return;
                }
            }
            $update = $this->pdo->prepare('UPDATE report_department_files SET delivery_status = :status, delivery_error = :error, delivered_at = NULL WHERE id = :id');
            $update->execute(['status' => $fileStatus, 'error' => substr($error, 0, 1000), 'id' => $fileId]);
            $insert = $this->pdo->prepare('INSERT INTO mail_events (run_id, department_file_id, recipients, status, error_text, created_at, batch_id) VALUES (:run_id, :file_id, :recipients, :status, :error, :now, :batch_id)');
            $insert->execute([
                'run_id' => $runId, 'file_id' => $fileId,
                'recipients' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => $eventStatus, 'error' => substr($error, 0, 1000),
                'now' => date(DATE_ATOM), 'batch_id' => $batchId,
            ]);
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
