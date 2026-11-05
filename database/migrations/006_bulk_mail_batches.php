<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec("CREATE TABLE bulk_mail_batches (
        id TEXT PRIMARY KEY,
        run_id TEXT NOT NULL REFERENCES report_runs(id) ON DELETE CASCADE,
        user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
        started_at TEXT NOT NULL,
        completed_at TEXT NULL,
        summary_status TEXT NOT NULL DEFAULT 'pending' CHECK (summary_status IN ('pending', 'processing', 'sent', 'no_email', 'failed')),
        summary_error TEXT NOT NULL DEFAULT '',
        summary_sent_at TEXT NULL
    )");
    $pdo->exec('ALTER TABLE mail_events ADD COLUMN batch_id TEXT NULL REFERENCES bulk_mail_batches(id) ON DELETE SET NULL');
    $pdo->exec('CREATE INDEX idx_mail_events_batch ON mail_events(batch_id, id)');
    $pdo->exec('CREATE INDEX idx_bulk_mail_batches_user ON bulk_mail_batches(user_id, started_at DESC)');
};
