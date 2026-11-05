<?php
declare(strict_types=1);

use ZkTimeNet\Services\BulkMailSummaryRenderer;

require dirname(__DIR__) . '/vendor/autoload.php';

$events = [
    [
        'department_name' => 'CAPITAL HUMANO',
        'status' => 'sent',
        'recipients' => json_encode(['sent' => ['nominas@example.com', 'rrhh@example.com'], 'failed' => []], JSON_THROW_ON_ERROR),
        'error_text' => '',
    ],
    [
        'department_name' => 'ADMINISTRACIÓN <script>alert(1)</script>',
        'status' => 'failed',
        'recipients' => json_encode(['sent' => ['admin@example.com'], 'failed' => ['bad@example.com' => '550 <rejected>']], JSON_THROW_ON_ERROR),
        'error_text' => 'partial failure',
    ],
    [
        'department_name' => 'CULIACÁN',
        'status' => 'skipped',
        'recipients' => json_encode(['sent' => [], 'failed' => [], 'skipped' => true, 'message' => 'Departamento sin destinatarios'], JSON_THROW_ON_ERROR),
        'error_text' => 'Departamento sin destinatarios',
    ],
    [
        'department_name' => 'MAZATLÁN',
        'status' => 'failed',
        'recipients' => json_encode(['sent' => [], 'failed' => [], 'error' => 'Timeout de conexión'], JSON_THROW_ON_ERROR),
        'error_text' => 'Timeout de conexión',
    ],
];

$summary = BulkMailSummaryRenderer::render(
    ['original_filename' => 'Asistencia <semana>.xlsx', 'week' => '44', 'start_date' => '2026-10-26', 'end_date' => '2026-11-01'],
    ['name' => 'Usuario de prueba'],
    $events,
    'CY Alimentos',
    'Control de asistencia'
);

$expected = [
    'departments_total' => 4,
    'departments_sent' => 1,
    'departments_failed' => 2,
    'departments_skipped' => 1,
    'emails_attempted' => 4,
    'emails_sent' => 3,
    'emails_failed' => 1,
];
if ($summary['stats'] !== $expected) {
    throw new RuntimeException('Unexpected summary stats: ' . json_encode($summary['stats']));
}
foreach (['nominas@example.com', 'rrhh@example.com', 'admin@example.com', 'bad@example.com', '550 &lt;rejected&gt;', 'Timeout de conexión', 'Departamento sin destinatarios'] as $needle) {
    if (!str_contains($summary['html'], $needle) && !str_contains($summary['text'], $needle)) {
        throw new RuntimeException('Missing receipt detail: ' . $needle);
    }
}
if (str_contains($summary['html'], '<script>alert(1)</script>') || !str_contains($summary['html'], '&lt;script&gt;')) {
    throw new RuntimeException('HTML output must escape untrusted department names.');
}
if (!str_contains($summary['subject'], 'Semana 44')) {
    throw new RuntimeException('Receipt subject should include the ISO week.');
}
echo "Bulk mail summary renderer: OK\n";
