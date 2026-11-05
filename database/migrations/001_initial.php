<?php
declare(strict_types=1);


return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        email TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_login_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_label TEXT NOT NULL UNIQUE COLLATE NOCASE,
        display_name TEXT NOT NULL,
        slug TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE department_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
        email TEXT NOT NULL COLLATE NOCASE,
        display_name TEXT NOT NULL DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),
        created_at TEXT NOT NULL,
        UNIQUE(department_id, email)
    )");
    $pdo->exec("CREATE TABLE settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE email_templates (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        subject_template TEXT NOT NULL,
        body_template TEXT NOT NULL,
        footer_template TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE report_runs (
        id TEXT PRIMARY KEY,
        original_filename TEXT NOT NULL,
        archive_name TEXT NOT NULL,
        archive_path TEXT NOT NULL,
        week TEXT NOT NULL,
        iso_year TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        departments_count INTEGER NOT NULL DEFAULT 0,
        email_sent_count INTEGER NOT NULL DEFAULT 0,
        email_failed_count INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL,
        created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE report_department_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        run_id TEXT NOT NULL REFERENCES report_runs(id) ON DELETE CASCADE,
        department_id INTEGER NULL REFERENCES departments(id) ON DELETE SET NULL,
        report_label TEXT NOT NULL,
        department_name TEXT NOT NULL,
        file_name TEXT NOT NULL,
        file_path TEXT NOT NULL,
        recipient_snapshot TEXT NOT NULL DEFAULT '[]',
        delivery_status TEXT NOT NULL DEFAULT 'pending',
        delivery_error TEXT NOT NULL DEFAULT '',
        delivered_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE mail_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        run_id TEXT NOT NULL REFERENCES report_runs(id) ON DELETE CASCADE,
        department_file_id INTEGER NULL REFERENCES report_department_files(id) ON DELETE SET NULL,
        recipients TEXT NOT NULL,
        status TEXT NOT NULL,
        error_text TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    )");
    $pdo->exec('CREATE INDEX idx_runs_created ON report_runs(created_at DESC)');
    $pdo->exec('CREATE INDEX idx_files_run ON report_department_files(run_id)');
    $pdo->exec('CREATE INDEX idx_recipients_department ON department_recipients(department_id, enabled)');
};
