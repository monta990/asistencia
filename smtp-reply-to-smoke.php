<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZkTimeNet\Services\SmtpTransport;
use ZkTimeNet\Services\MailerService;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$replyToForDepartment = MailerService::buildDepartmentReplyTo(
    ['nominas@example.com', 'manager@example.com'],
    ['rh@example.com', 'manager@example.com'],
    'MANAGER@example.com'
);
check($replyToForDepartment === ['nominas@example.com', 'rh@example.com'], 'Reply-To debe excluir siempre al responsable aunque figure en la lista del departamento y en Reply-To global.');
$replyToWhenDepartmentOptionOff = MailerService::buildDepartmentReplyTo(
    ['nominas@example.com', 'manager@example.com'],
    ['rh@example.com'],
    'manager@example.com',
    false
);
check($replyToWhenDepartmentOptionOff === ['nominas@example.com'], 'la opción desactivada debe conservar Reply-To global sin incluir al responsable.');

$transport = new SmtpTransport();
$reflection = new ReflectionClass($transport);
$normalizer = $reflection->getMethod('normalizeReplyTo');
$addresses = $normalizer->invoke($transport, "Nominas@example.com, rh@example.com; jefe@example.com\nnominas@example.com");
check($addresses === ['nominas@example.com', 'rh@example.com', 'jefe@example.com'], 'Reply-To debe separar, normalizar y quitar duplicados.');

$messageBuilder = $reflection->getMethod('buildMessage');
$message = $messageBuilder->invoke(
    $transport,
    'sender@example.com',
    'Nóminas',
    'employee@example.com',
    ['nominas@example.com', 'rh@example.com', 'manager@example.com'],
    'Reporte de asistencia',
    '<p>Adjunto.</p>',
    'Adjunto.',
    null,
    'smtp.example.com'
);
check(str_contains($message, "Reply-To: <nominas@example.com>,\r\n <rh@example.com>,\r\n <manager@example.com>"), 'El encabezado Reply-To debe incluir las tres direcciones y usar plegado RFC.');
check(str_contains($message, 'To: <employee@example.com>'), 'El destinatario principal debe mantenerse individual.');

$rejected = false;
try {
    $normalizer->invoke($transport, "valid@example.com\r\nBcc:attacker@example.com");
} catch (Throwable) {
    $rejected = true;
}
check($rejected, 'Debe rechazar cualquier intento de inyección de encabezados.');


// Valida el mensaje MIME completo, no solamente el encabezado Reply-To.
$normalizer = $reflection->getMethod('normalizeLineEndings');
$attachmentDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_mime_' . bin2hex(random_bytes(6));
if (!mkdir($attachmentDirectory, 0700, true)) {
    throw new RuntimeException('No se pudo crear el directorio XLSX de prueba.');
}
$attachmentPath = $attachmentDirectory . DIRECTORY_SEPARATOR . 'Semana44_TI.xlsx';
$attachmentBytes = "PK\x03\x04ZKTimeNet MIME attachment fixture";
file_put_contents($attachmentPath, $attachmentBytes);
try {
    $mime = $messageBuilder->invoke(
        $transport,
        'sender@example.com',
        'Nóminas CY',
        'employee@example.com',
        ['nominas@example.com', 'rh@example.com'],
        'Reporte de asistencia | TI | Semana 44',
        '<p>Adjuntamos el reporte.</p>',
        'Adjuntamos el reporte.',
        $attachmentPath,
        'smtp.example.com'
    );
    $mime = $normalizer->invoke($transport, $mime);
    check(str_starts_with($mime, 'Date: '), 'el mensaje debe comenzar con los encabezados, sin CRLF inicial');
    check(!str_contains($mime, "\r\r\n"), 'no debe contener CRCRLF');
    check(preg_match('/(?<!\r)\n/', $mime) !== 1, 'todos los LF deben estar precedidos por CR');
    check(preg_match('/\r(?!\n)/', $mime) !== 1, 'todos los CR deben estar seguidos por LF');
    $separator = strpos($mime, "\r\n\r\n");
    check($separator !== false, 'debe existir el separador único entre encabezados y cuerpo MIME');
    $headerBlock = substr($mime, 0, $separator);
    $mimeBody = substr($mime, $separator + 4);
    check(str_contains($headerBlock, 'Subject: Reporte de asistencia | TI | Semana 44'), 'Subject debe estar en los encabezados SMTP');
    check(str_contains($headerBlock, 'From: =?UTF-8?B?'), 'From debe codificar correctamente el nombre UTF-8');
    check(str_contains($headerBlock, 'To: <employee@example.com>'), 'To debe permanecer como encabezado');
    check(str_contains($headerBlock, 'Content-Type: multipart/mixed; boundary='), 'Content-Type multipart/mixed debe estar en encabezados');
    check(str_contains($mimeBody, 'Content-Type: multipart/alternative; boundary='), 'el cuerpo debe contener multipart/alternative');
    check(str_contains($mimeBody, 'Content-Disposition: attachment; filename="Semana44_TI.xlsx"'), 'el adjunto debe conservar el nombre XLSX del departamento');
    check(str_contains($mimeBody, base64_encode($attachmentBytes)), 'el adjunto debe contener el payload Base64 íntegro');
    check(str_contains($mimeBody, "\r\n--") && str_contains($mimeBody, '--'), 'las partes MIME deben separarse con sus boundaries');

    $signaturePath = $attachmentDirectory . DIRECTORY_SEPARATOR . 'firma.png';
    $signatureBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
    if (!is_string($signatureBytes)) {
        throw new RuntimeException('No se pudo crear la imagen PNG de prueba.');
    }
    file_put_contents($signaturePath, $signatureBytes);
    $htmlWithSignature = '<p>Adjuntamos el reporte.</p><img src="cid:zktime-signature@local" alt="Firma">';
    $mimeWithSignature = $messageBuilder->invoke(
        $transport,
        'sender@example.com',
        'Nóminas CY',
        'employee@example.com',
        ['nominas@example.com'],
        'Reporte de asistencia | TI | Semana 44',
        $htmlWithSignature,
        'Adjuntamos el reporte.',
        $attachmentPath,
        'smtp.example.com',
        ['path' => $signaturePath, 'mime' => 'image/png', 'filename' => 'firma.png', 'content_id' => 'zktime-signature@local']
    );
    $mimeWithSignature = $normalizer->invoke($transport, $mimeWithSignature);
    check(str_contains($mimeWithSignature, 'Content-Type: multipart/related; boundary='), 'la firma debe viajar dentro de multipart/related');
    check(str_contains($mimeWithSignature, 'Content-ID: <zktime-signature@local>'), 'la imagen de firma debe tener Content-ID');
    check(str_contains($mimeWithSignature, 'Content-Disposition: inline; filename="firma.png"'), 'la imagen de firma debe ir inline, no como adjunto normal');
    check(str_contains($mimeWithSignature, chunk_split(base64_encode($htmlWithSignature), 76, "\r\n")), 'el HTML debe apuntar al CID de la imagen');
    check(str_contains($mimeWithSignature, 'Content-Disposition: attachment; filename="Semana44_TI.xlsx"'), 'la firma no debe romper el adjunto XLSX normal');
    check(str_contains($mimeWithSignature, chunk_split(base64_encode($signatureBytes), 76, "\r\n")), 'la imagen debe viajar íntegra en Base64');

    $validationOutput = getenv('ZKTIME_SMTP_TEST_OUTPUT');
    if (is_string($validationOutput) && $validationOutput !== '') {
        file_put_contents($validationOutput, $mimeWithSignature ?? $mime);
    }
} finally {
    @unlink($attachmentPath);
    @unlink($signaturePath ?? '');
    @rmdir($attachmentDirectory);
}

echo "OK: Reply-To, MIME, firma CID inline, adjunto XLSX y protección CRLF.\n";
