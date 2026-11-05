<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

use ZkTimeNet\Core\ErrorLogger;

use ZkTimeNet\Core\SecretVault;
use ZkTimeNet\Core\Config;
use ZkTimeNet\Repositories\SettingsRepository;

final class MailerService
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        $smtp = $this->settings->get('smtp', []);
        return is_array($smtp)
            && trim((string) ($smtp['host'] ?? '')) !== ''
            && filter_var(trim((string) ($smtp['from_email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false
            && (trim((string) ($smtp['username'] ?? '')) === '' || (string) ($smtp['password_encrypted'] ?? '') !== '');
    }

    public function test(string $recipient): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Indica una dirección válida para la prueba SMTP.');
        }
        $smtp = $this->smtpConfig();
        $branding = $this->settings->get('branding', []);
        $branding = is_array($branding) ? $branding : [];
        $company = (string) ($branding['company_name'] ?? 'CY ALIMENTOS');
        $application = (string) ($branding['application_name'] ?? 'Control de asistencia');
        $text = 'La configuración SMTP de ' . $application . ' funciona correctamente.';
        $html = '<p>' . TemplateRenderer::html($text) . '</p>';
        $signature = $this->signatureImage();
        if ($signature !== null) {
            $html .= self::signatureHtml($signature['content_id']);
        }
        (new SmtpTransport())->send($smtp, $recipient, 'Prueba SMTP | ' . $company, $html, $text, null, $signature);
    }

    /** Envía el comprobante del lote al correo de la cuenta que inició el envío masivo. */
    public function sendBulkSummary(string $recipient, string $subject, string $html, string $text): void
    {
        $recipient = strtolower(trim($recipient));
        if ($recipient === '' || strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('La cuenta no tiene un correo válido para recibir el comprobante.');
        }
        $subject = preg_replace('/[\r\n]+/', ' ', trim($subject)) ?? '';
        if ($subject === '') {
            throw new \InvalidArgumentException('El asunto del comprobante está vacío.');
        }
        (new SmtpTransport())->send($this->smtpConfig(), $recipient, $subject, $html, $text, null, null);
    }

    /** @return array{sent:list<string>,failed:array<string,string>} */
    public function sendDepartment(array $recipients, string $attachment, array $context, string $responsibleEmail = ''): array
    {
        // El responsable siempre recibe su propio Excel, incluso si no está en la lista del departamento.
        // Se deduplica con los destinatarios configurados; su dirección se excluye solo de Reply-To.
        if (trim($responsibleEmail) !== '') {
            $recipients[] = $responsibleEmail;
        }
        if (!is_file($attachment) || !is_readable($attachment)) {
            throw new \RuntimeException('El archivo adjunto no está disponible.');
        }

        $template = $this->settings->template();
        $subject = trim(TemplateRenderer::render((string) $template['subject_template'], $context));
        $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject;
        if ($subject === '') {
            throw new \RuntimeException('La plantilla de asunto está vacía.');
        }
        $body = TemplateRenderer::render((string) $template['body_template'], $context);
        $footer = TemplateRenderer::render((string) $template['footer_template'], $context);
        if ($footer !== '') {
            $body .= "\n\n" . $footer;
        }
        $uniqueRecipients = [];
        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string) $recipient));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $uniqueRecipients[$email] = $email;
            }
        }
        if ($uniqueRecipients === []) {
            throw new \RuntimeException('El departamento no tiene destinatarios ni responsable con correo válido.');
        }

        $smtp = $this->smtpConfig();
        // Agrega los correos activos del departamento a Reply-To por defecto.
        // El destinatario principal sigue siendo un correo individual por privacidad.
        $includeDepartmentRecipients = !array_key_exists('department_recipients_reply_to', $smtp)
            || (bool) $smtp['department_recipients_reply_to'];
        $smtp['reply_to'] = self::buildDepartmentReplyTo(
            is_array($smtp['reply_to'] ?? null) ? $smtp['reply_to'] : [],
            array_values($uniqueRecipients),
            $responsibleEmail,
            $includeDepartmentRecipients
        );
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#233044">' . nl2br(TemplateRenderer::html($body), false) . '</div>';
        $signature = $this->signatureImage();
        if ($signature !== null) {
            $html .= self::signatureHtml($signature['content_id']);
        }
        $transport = new SmtpTransport();
        $result = ['sent' => [], 'failed' => []];
        foreach ($uniqueRecipients as $recipient) {
            try {
                // Cada destinatario recibe una transacción independiente; no se revela la lista.
                $transport->send($smtp, $recipient, $subject, $html, $body, $attachment, $signature);
                $result['sent'][] = $recipient;
            } catch (\Throwable $exception) {
                $reference = ErrorLogger::capture($exception, 'smtp-recipient-delivery');
                $result['failed'][$recipient] = 'No se pudo enviar el correo. Revisa la configuración del servidor de correo o contacta al administrador.'
                    . ($reference !== 'unavailable' ? ' Referencia: ' . $reference . '.' : '');
            }
        }
        return $result;
    }


    /** Devuelve la firma configurada como imagen CID, siempre desde almacenamiento privado. */
    private function signatureImage(): ?array
    {
        $smtp = $this->settings->get('smtp', []);
        $metadata = is_array($smtp) ? ($smtp['signature_image'] ?? null) : null;
        $privateDirectory = (string) Config::get('storage_private', '');
        if ($privateDirectory === '') {
            return null;
        }
        $resolved = SignatureImage::resolve($metadata, $privateDirectory);
        if ($resolved === null) {
            return null;
        }
        return $resolved + ['content_id' => 'zktime-signature@local'];
    }

    private static function signatureHtml(string $contentId): string
    {
        return '<div style="margin-top:18px"><img src="cid:' . TemplateRenderer::html($contentId)
            . '" alt="Firma" style="display:block;max-width:600px;width:auto;height:auto;border:0"></div>';
    }

    /**
     * Combina el Reply-To general con las direcciones del departamento,
     * excluyendo siempre al responsable para evitar respuestas dirigidas a sí mismo.
     *
     * @param list<string> $configuredReplyTo
     * @param list<string> $departmentRecipients
     * @return list<string>
     */
    public static function buildDepartmentReplyTo(array $configuredReplyTo, array $departmentRecipients, string $responsibleEmail, bool $includeDepartmentRecipients = true): array
    {
        $responsibleKey = strtolower(trim($responsibleEmail));
        $combined = $configuredReplyTo;
        if ($includeDepartmentRecipients) {
            $combined = array_merge($combined, $departmentRecipients);
        }
        $replyTo = self::normalizeEmailList($combined);
        if ($responsibleKey !== '') {
            $replyTo = array_values(array_filter(
                $replyTo,
                static fn (string $email): bool => strtolower($email) !== $responsibleKey
            ));
        }
        return $replyTo;
    }

    private function smtpConfig(): array
    {
        $smtp = $this->settings->get('smtp', []);
        if (!is_array($smtp)) {
            throw new \RuntimeException('Configura los parámetros SMTP antes de enviar correos.');
        }
        $branding = $this->settings->get('branding', []);
        $branding = is_array($branding) ? $branding : [];
        if (trim((string) ($smtp['from_name'] ?? '')) === '') {
            $smtp['from_name'] = (string) ($branding['company_name'] ?? 'Sistema');
        }
        $host = trim((string) ($smtp['host'] ?? ''));
        $from = trim((string) ($smtp['from_email'] ?? ''));
        if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('La configuración SMTP está incompleta.');
        }
        $encrypted = (string) ($smtp['password_encrypted'] ?? '');
        $smtp['password'] = $encrypted !== '' ? SecretVault::decrypt($encrypted) : '';
        // Compatibilidad con instalaciones anteriores: Reply-To antes era una cadena simple.
        $smtp['reply_to'] = self::normalizeEmailList($smtp['reply_to'] ?? []);
        // En configuraciones existentes sin esta clave, el comportamiento nuevo queda activado.
        $smtp['department_recipients_reply_to'] = !array_key_exists('department_recipients_reply_to', $smtp)
            || (bool) $smtp['department_recipients_reply_to'];
        return $smtp;
    }

    /** @return list<string> */
    private static function normalizeEmailList(mixed $input): array
    {
        if (is_string($input)) {
            $items = preg_split('/[,;\s]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($input)) {
            $items = $input;
        } else {
            $items = [];
        }

        $emails = [];
        foreach ($items as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $email = strtolower(trim((string) $item));
            if ($email === '') {
                continue;
            }
            if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('La lista Responder a contiene una dirección no válida: ' . substr($email, 0, 100));
            }
            $emails[$email] = $email;
            if (count($emails) > 50) {
                throw new \InvalidArgumentException('Responder a admite un máximo de 50 direcciones.');
            }
        }
        return array_values($emails);
    }
}
