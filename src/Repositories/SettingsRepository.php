<?php
declare(strict_types=1);

namespace ZkTimeNet\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            return $default;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :now) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at');
        $statement->execute(['key' => $key, 'value' => $json, 'now' => date(DATE_ATOM)]);
    }

    public function template(): array
    {
        $row = $this->pdo->query('SELECT subject_template, body_template, footer_template FROM email_templates WHERE id = 1')->fetch();
        return is_array($row) ? $row : ['subject_template' => '', 'body_template' => '', 'footer_template' => ''];
    }

    public function saveTemplate(string $subject, string $body, string $footer): void
    {
        $statement = $this->pdo->prepare('INSERT INTO email_templates (id, subject_template, body_template, footer_template, updated_at) VALUES (1, :subject, :body, :footer, :now) ON CONFLICT(id) DO UPDATE SET subject_template = excluded.subject_template, body_template = excluded.body_template, footer_template = excluded.footer_template, updated_at = excluded.updated_at');
        $statement->execute(['subject' => $subject, 'body' => $body, 'footer' => $footer, 'now' => date(DATE_ATOM)]);
    }
}
