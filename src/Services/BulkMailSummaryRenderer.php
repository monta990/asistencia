<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

/** Construye el comprobante legible del resultado de un lote de envío masivo. */
final class BulkMailSummaryRenderer
{
    /**
     * @param list<array<string,mixed>> $events Eventos de correo asociados exclusivamente al lote.
     * @return array{subject:string,html:string,text:string,stats:array<string,int>}
     */
    public static function render(array $run, array $user, array $events, string $company, string $application): array
    {
        $sentDepartments = 0;
        $failedDepartments = 0;
        $skippedDepartments = 0;
        $sentEmails = 0;
        $failedEmails = 0;
        $htmlRows = '';
        $textRows = [];

        foreach ($events as $event) {
            $name = trim((string) ($event['department_name'] ?? 'Departamento')) ?: 'Departamento';
            $status = strtolower((string) ($event['status'] ?? 'failed'));
            $decoded = json_decode((string) ($event['recipients'] ?? ''), true);
            $result = is_array($decoded) ? $decoded : [];
            $sent = is_array($result['sent'] ?? null) ? array_values(array_filter($result['sent'], 'is_string')) : [];
            $failed = [];
            if (is_array($result['failed'] ?? null)) {
                foreach ($result['failed'] as $email => $reason) {
                    $email = is_string($email) || is_numeric($email) ? (string) $email : 'Destinatario';
                    $failed[$email] = is_scalar($reason) ? (string) $reason : 'Error sin detalle proporcionado.';
                }
            }
            $errorValue = $result['error'] ?? $event['error_text'] ?? '';
            $skipValue = $result['message'] ?? $event['error_text'] ?? '';
            $error = is_scalar($errorValue) ? trim((string) $errorValue) : 'Error sin detalle proporcionado.';
            $skipMessage = is_scalar($skipValue) ? trim((string) $skipValue) : 'Omisión sin detalle proporcionado.';

            $sentEmails += count($sent);
            $failedEmails += count($failed);
            if ($status === 'skipped') {
                $skippedDepartments++;
                $label = 'Omitido';
                $details = $skipMessage !== '' ? $skipMessage : 'El departamento no se procesó.';
            } elseif ($status === 'sent' && $failed === [] && $error === '') {
                $sentDepartments++;
                $label = 'Enviado';
                $details = $sent === [] ? 'El servidor SMTP aceptó el envío.' : 'Destinatarios: ' . implode(', ', $sent);
            } else {
                $failedDepartments++;
                $label = 'Con fallos';
                $parts = [];
                if ($sent !== []) {
                    $parts[] = 'Enviados: ' . implode(', ', $sent);
                }
                foreach ($failed as $email => $reason) {
                    $parts[] = (string) $email . ': ' . (string) $reason;
                }
                if ($error !== '' && $failed === []) {
                    $parts[] = $error;
                }
                $details = $parts === [] ? 'No se obtuvo un resultado SMTP confirmado.' : implode(' | ', $parts);
            }

            $htmlRows .= '<tr><td style="padding:9px;border-bottom:1px solid #e5eaf0;vertical-align:top">' . self::escape($name) . '</td>'
                . '<td style="padding:9px;border-bottom:1px solid #e5eaf0;vertical-align:top;font-weight:700">' . self::escape($label) . '</td>'
                . '<td style="padding:9px;border-bottom:1px solid #e5eaf0;vertical-align:top;overflow-wrap:anywhere">' . self::escape($details) . '</td></tr>';
            $textRows[] = $name . ' — ' . $label . ': ' . $details;
        }

        $stats = [
            'departments_total' => count($events),
            'departments_sent' => $sentDepartments,
            'departments_failed' => $failedDepartments,
            'departments_skipped' => $skippedDepartments,
            'emails_attempted' => $sentEmails + $failedEmails,
            'emails_sent' => $sentEmails,
            'emails_failed' => $failedEmails,
        ];
        $filename = (string) ($run['original_filename'] ?? 'Reporte de asistencia');
        $week = (string) ($run['week'] ?? '');
        $start = self::formatDate((string) ($run['start_date'] ?? ''));
        $end = self::formatDate((string) ($run['end_date'] ?? ''));
        $userName = (string) ($user['name'] ?? $user['username'] ?? 'Usuario');
        $subject = 'Resumen de envío masivo | ' . ($week !== '' ? 'Semana ' . $week . ' | ' : '') . $filename;
        $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? 'Resumen de envío masivo';

        $statsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:14px 0"><tr>'
            . self::statCell('Correos procesados', $sentEmails + $failedEmails, '#334155')
            . self::statCell('Aceptados por SMTP', $sentEmails, '#087448')
            . self::statCell('Correos fallidos', $failedEmails, '#B4232F')
            . self::statCell('Departamentos omitidos', $skippedDepartments, '#64748b')
            . '</tr></table>';
        $summaryHtml = '<p style="margin:0 0 8px"><strong>Archivo:</strong> ' . self::escape($filename) . '</p>'
            . '<p style="margin:0 0 8px"><strong>Periodo:</strong> Semana ' . self::escape($week) . ' · ' . self::escape($start) . ' al ' . self::escape($end) . '</p>'
            . '<p style="margin:0 0 8px"><strong>Realizado por:</strong> ' . self::escape($userName) . '</p>'
            . '<p style="margin:0 0 8px"><strong>Departamentos procesados:</strong> ' . (int) $stats['departments_total'] . ' · <strong>Enviados:</strong> ' . (int) $sentDepartments . ' · <strong>Con fallos:</strong> ' . (int) $failedDepartments . '</p>'
            . $statsHtml;
        $tableHtml = $events === []
            ? '<p style="padding:12px;background:#f5f7fa;border-radius:6px">No se registraron intentos de envío en este lote.</p>'
            : '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e5eaf0"><thead><tr style="background:#f5f7fa;text-align:left"><th style="padding:9px">Departamento</th><th style="padding:9px">Estado</th><th style="padding:9px">Detalle / destinatarios / motivo</th></tr></thead><tbody>' . $htmlRows . '</tbody></table>';
        $html = '<!doctype html><html lang="es"><meta charset="UTF-8"><body style="margin:0;padding:22px;background:#f3f6fa;color:#233044;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55">'
            . '<div style="max-width:900px;margin:0 auto;padding:24px;background:#fff;border:1px solid #e1e8f0;border-radius:10px">'
            . '<p style="margin:0 0 4px;color:#718096;font-size:11px;font-weight:700;letter-spacing:1px">' . self::escape($company) . ' · ' . self::escape($application) . '</p>'
            . '<h1 style="margin:0 0 18px;font-size:22px;color:#17283e">Comprobante de envío masivo</h1>'
            . $summaryHtml . '<h2 style="margin:22px 0 10px;font-size:16px;color:#17283e">Detalle por departamento</h2>' . $tableHtml
            . '<p style="margin:18px 0 0;color:#718096;font-size:11px">Los correos enviados representan mensajes aceptados por el servidor SMTP; este comprobante no confirma su lectura ni su llegada a la bandeja de entrada. Generado el ' . self::escape(date('d/m/Y H:i:s')) . '.</p>'
            . '</div></body></html>';

        $text = $company . ' · ' . $application . "\nCOMPROBANTE DE ENVÍO MASIVO\n\n"
            . 'Archivo: ' . $filename . "\nPeriodo: Semana " . $week . ' · ' . $start . ' al ' . $end . "\nRealizado por: " . $userName . "\n"
            . 'Departamentos procesados: ' . $stats['departments_total'] . "\nDepartamentos enviados: " . $sentDepartments
            . "\nDepartamentos con fallos: " . $failedDepartments . "\nDepartamentos omitidos: " . $skippedDepartments
            . "\nCorreos procesados: " . ($sentEmails + $failedEmails) . "\nAceptados por SMTP: " . $sentEmails . "\nCorreos fallidos: " . $failedEmails . "\n\nDETALLE POR DEPARTAMENTO\n"
            . ($textRows === [] ? 'No se registraron intentos de envío.' : implode("\n", $textRows))
            . "\n\nLos correos enviados representan mensajes aceptados por el servidor SMTP; este comprobante no confirma su lectura ni su llegada a la bandeja de entrada.\n";

        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'stats' => $stats];
    }

    private static function statCell(string $label, int $value, string $color): string
    {
        return '<td style="width:25%;padding:10px;border:1px solid #e5eaf0;border-radius:6px"><span style="display:block;color:#718096;font-size:11px">' . self::escape($label)
            . '</span><strong style="display:block;color:' . self::escape($color) . ';font-size:22px">' . $value . '</strong></td>';
    }

    private static function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? '—' : date('d/m/Y', $timestamp);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
