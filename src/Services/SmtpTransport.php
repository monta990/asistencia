<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

/** Transporte SMTP nativo para evitar dependencias externas en producción. */
final class SmtpTransport
{
    public function send(
        array $smtp,
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody,
        ?string $attachmentPath = null,
        ?array $inlineImage = null
    ): void {
        $host = trim((string) ($smtp['host'] ?? ''));
        $port = max(1, min(65535, (int) ($smtp['port'] ?? 587)));
        $timeout = max(5, min(60, (int) ($smtp['timeout'] ?? 20)));
        $username = (string) ($smtp['username'] ?? '');
        $password = (string) ($smtp['password'] ?? '');
        $fromEmail = trim((string) ($smtp['from_email'] ?? ''));
        $fromName = trim((string) ($smtp['from_name'] ?? $smtp['from_email'] ?? 'Sistema'));
        $replyTo = $this->normalizeReplyTo($smtp['reply_to'] ?? []);
        $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));

        foreach ([$host, $recipient, $fromEmail, $subject] as $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new \InvalidArgumentException('La configuración SMTP contiene saltos de línea no permitidos.');
            }
        }
        if ($host === '' || preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Host SMTP, remitente o destinatario no válido.');
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new \InvalidArgumentException('El modo de cifrado SMTP no es válido.');
        }
        if ($attachmentPath !== null && (!is_file($attachmentPath) || !is_readable($attachmentPath))) {
            throw new \RuntimeException('El adjunto no está disponible.');
        }
        if ($inlineImage !== null) {
            $inlinePath = (string) ($inlineImage['path'] ?? '');
            $inlineMime = strtolower((string) ($inlineImage['mime'] ?? ''));
            if ($inlinePath === '' || !is_file($inlinePath) || !is_readable($inlinePath)
                || !in_array($inlineMime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                throw new \RuntimeException('La imagen de firma no está disponible o su formato no es válido.');
            }
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client($remote, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new \RuntimeException('No se pudo conectar al servidor SMTP: ' . $this->safeError($errorMessage));
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, [220]);
            $helo = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
            $this->command($socket, 'EHLO ' . $helo, [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('El servidor SMTP no pudo establecer TLS seguro.');
                }
                $this->command($socket, 'EHLO ' . $helo, [250]);
            }
            if ($username !== '') {
                if (str_contains($username, "\r") || str_contains($username, "\n") || str_contains($password, "\r") || str_contains($password, "\n")) {
                    throw new \InvalidArgumentException('Las credenciales SMTP contienen caracteres no permitidos.');
                }
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $message = $this->buildMessage($fromEmail, $fromName, $recipient, $replyTo, $subject, $htmlBody, $textBody, $attachmentPath, $host, $inlineImage);
            // Normalizar en una sola pasada: str_replace secuencial sobre CRLF
            // convierte cada salto en CRCRLF/CRLFCRLF y rompe los encabezados MIME.
            $message = $this->normalizeLineEndings($message);
            // SMTP DATA requiere duplicar puntos al inicio de cada línea.
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            $this->write($socket, rtrim($message, "\r\n") . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(string $from, string $fromName, string $to, array $replyTo, string $subject, string $html, string $text, ?string $attachment, string $host, ?array $inlineImage = null): string
    {
        $mixed = '=_zktime_mixed_' . bin2hex(random_bytes(12));
        $related = '=_zktime_related_' . bin2hex(random_bytes(12));
        $alternative = '=_zktime_alt_' . bin2hex(random_bytes(12));
        $fromHeader = $this->formatAddress($from, $fromName);
        $toHeader = $this->formatAddress($to, '');
        $domain = preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromHeader,
            'To: ' . $toHeader,
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'X-Mailer: ZKTimeNet Personal Framework',
            'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
        ];
        if ($replyTo !== []) {
            $replyAddresses = array_map(static fn (string $email): string => '<' . $email . '>', $replyTo);
            $headers[] = 'Reply-To: ' . implode(",\r\n ", $replyAddresses);
        }
        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= '--' . $mixed . "\r\n";
        if ($inlineImage !== null) {
            // multipart/related mantiene la imagen CID asociada al HTML y no la muestra
            // como un adjunto normal; multipart/mixed contiene además el XLSX descargable.
            $body .= 'Content-Type: multipart/related; boundary="' . $related . '"' . "\r\n\r\n";
            $body .= '--' . $related . "\r\n";
        }
        $body .= 'Content-Type: multipart/alternative; boundary="' . $alternative . '"' . "\r\n\r\n";
        $body .= '--' . $alternative . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text), 76, "\r\n");
        $body .= '--' . $alternative . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html), 76, "\r\n");
        $body .= '--' . $alternative . "--\r\n";

        if ($inlineImage !== null) {
            $imagePath = (string) $inlineImage['path'];
            $imageContents = file_get_contents($imagePath);
            if (!is_string($imageContents)) {
                throw new \RuntimeException('No se pudo leer la imagen de firma.');
            }
            $imageMime = strtolower((string) $inlineImage['mime']);
            $imageName = basename((string) ($inlineImage['filename'] ?? 'firma'));
            $asciiImageName = preg_replace('/[^A-Za-z0-9._-]/', '_', $imageName) ?: 'firma';
            $contentId = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($inlineImage['content_id'] ?? 'zktime-signature@local')) ?: 'zktime-signature@local';
            $body .= '--' . $related . "\r\n";
            $body .= 'Content-Type: ' . $imageMime . '; name="' . $asciiImageName . '"' . "\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-ID: <' . $contentId . ">\r\n";
            $body .= 'Content-Disposition: inline; filename="' . $asciiImageName . '"' . "\r\n\r\n";
            $body .= chunk_split(base64_encode($imageContents), 76, "\r\n");
            $body .= '--' . $related . "--\r\n";
        }

        if ($attachment !== null) {
            $fileName = basename($attachment);
            $fileContents = file_get_contents($attachment);
            if (!is_string($fileContents)) {
                throw new \RuntimeException('No se pudo leer el Excel para adjuntarlo.');
            }
            $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'reporte.xlsx';
            $body .= '--' . $mixed . "\r\n";
            $body .= "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; name=\"{$asciiName}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName) . "\r\n\r\n";
            $body .= chunk_split(base64_encode($fileContents), 76, "\r\n");
        }
        $body .= '--' . $mixed . "--\r\n";
        return $body;
    }

    /**
     * Canonicaliza saltos LF, CRLF y CR a CRLF en una sola pasada.
     * El bloque de encabezados debe conservar una sola línea vacía antes del MIME body.
     */
    private function normalizeLineEndings(string $message): string
    {
        $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        if (!is_string($normalized)) {
            throw new \RuntimeException('No se pudieron normalizar los saltos de línea del correo.');
        }
        return $normalized;
    }

    /** @return list<string> */
    private function normalizeReplyTo(mixed $input): array
    {
        if (is_string($input)) {
            $items = preg_split('/[,;\\s]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
            if (str_contains($email, "\r") || str_contains($email, "\n")
                || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('La lista Responder a contiene una dirección no válida.');
            }
            $emails[$email] = $email;
            if (count($emails) > 50) {
                throw new \InvalidArgumentException('Responder a admite un máximo de 50 direcciones.');
            }
        }
        return array_values($emails);
    }

    private function formatAddress(string $email, string $name): string
    {
        if ($name === '') {
            return '<' . $email . '>';
        }
        if (str_contains($name, "\r") || str_contains($name, "\n")) {
            throw new \InvalidArgumentException('El nombre del remitente contiene caracteres no permitidos.');
        }
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            throw new \RuntimeException('No se pudo codificar un encabezado UTF-8.');
        }
        $chunks = [];
        $buffer = '';
        foreach ($characters as $character) {
            if ($buffer !== '' && strlen($buffer . $character) > 42) {
                $chunks[] = '=?UTF-8?B?' . base64_encode($buffer) . '?=';
                $buffer = '';
            }
            $buffer .= $character;
        }
        if ($buffer !== '') {
            $chunks[] = '=?UTF-8?B?' . base64_encode($buffer) . '?=';
        }
        return implode("\r\n ", $chunks);
    }

    private function command($socket, string $command, array $expected): string
    {
        $this->write($socket, $command . "\r\n");
        return $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 8192);
            if (!is_string($line)) {
                $meta = stream_get_meta_data($socket);
                throw new \RuntimeException(!empty($meta['timed_out']) ? 'Tiempo de espera agotado durante la comunicación SMTP.' : 'El servidor cerró la conexión SMTP inesperadamente.');
            }
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        } while (strlen($response) < 65536);

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('El servidor SMTP respondió ' . $code . ': ' . $this->safeError(trim($response)));
        }
        return $response;
    }

    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($socket, substr($data, $written));
            if (!is_int($count) || $count < 1) {
                throw new \RuntimeException('Se interrumpió la escritura hacia el servidor SMTP.');
            }
            $written += $count;
        }
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/[\r\n\x00-\x1F]+/', ' ', $message) ?? '';
        return substr(trim($message), 0, 400);
    }
}
