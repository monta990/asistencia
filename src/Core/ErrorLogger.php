<?php
declare(strict_types=1);

namespace ZkTimeNet\Core;

use Throwable;

/** Registro privado de errores y mensajes seguros para respuestas HTTP. */
final class ErrorLogger
{
    private const MAX_LOG_BYTES = 5_242_880; // 5 MiB

    /** Registra el detalle técnico fuera de la raíz web y devuelve una referencia corta. */
    public static function capture(Throwable $exception, string $context): string
    {
        try {
            $reference = bin2hex(random_bytes(6));
            $privateRoot = (string) Config::get('storage_private', '');
            if ($privateRoot === '') {
                throw new \RuntimeException('Private logging directory is not configured.');
            }

            $resolvedRoot = realpath($privateRoot);
            $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
            if ($resolvedRoot === false || self::isInsideDocumentRoot($resolvedRoot, $documentRoot)) {
                throw new \RuntimeException('Private logging directory is unavailable or inside the document root.');
            }

            $logDirectory = $resolvedRoot . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
                throw new \RuntimeException('Unable to create private log directory.');
            }

            $logPath = $logDirectory . DIRECTORY_SEPARATOR . 'application.log';
            if (is_file($logPath) && (int) @filesize($logPath) >= self::MAX_LOG_BYTES) {
                $previous = $logPath . '.1';
                if (is_file($previous)) {
                    @unlink($previous);
                }
                @rename($logPath, $previous);
            }

            $entry = [
                'time' => date(DATE_ATOM),
                'reference' => $reference,
                'context' => self::safeContext($context),
                'type' => get_class($exception),
                'message' => self::redact($exception->getMessage()),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => self::redactTrace($exception->getTraceAsString()),
            ];
            $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json)) {
                throw new \RuntimeException('Unable to encode application log entry.');
            }

            $handle = @fopen($logPath, 'ab');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open private application log.');
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('Unable to lock private application log.');
                }
                fwrite($handle, $json . PHP_EOL);
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
            @chmod($logPath, 0600);
            @chmod($logDirectory, 0700);
            return $reference;
        } catch (Throwable $loggingFailure) {
            // No se muestra el detalle del fallo de logging ni se escribe dentro de la raíz web.
            error_log('[ZKTimeNet] Error logging failed; original error type: ' . get_class($exception)
                . '; logging failure type: ' . get_class($loggingFailure));
            return 'unavailable';
        }
    }

    /** Muestra errores de validación deliberados; oculta y registra detalles de infraestructura. */
    public static function publicMessage(Throwable $exception, string $fallback, string $context): string
    {
        $reference = self::capture($exception, $context);
        $message = trim($exception->getMessage());
        $technical = preg_match(
            '~(?:SQLSTATE|PDOException|SQLite|\\bSMTP\\b|stream_socket|openssl|\\.php|vendor[\\\\/]|[A-Za-z]:[\\\\]|/var/|/ProgramData/|\\b(?:xl|_rels|docProps)/|\\.xlsx?\\b|stack trace|fatal error|uncaught|permission denied|access is denied)~i',
            $message
        ) === 1;

        if (($exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException)
            && $exception->getPrevious() === null
            && !$technical
            && $message !== '') {
            return $message;
        }

        return rtrim($fallback) . ($reference !== 'unavailable' ? ' Referencia: ' . $reference . '.' : '');
    }

    private static function isInsideDocumentRoot(string $path, string $documentRoot): bool
    {
        if ($documentRoot === '') {
            return false;
        }
        $root = realpath($documentRoot);
        if ($root === false) {
            return false;
        }
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return strncasecmp($path, $root, strlen($root)) === 0;
    }

    private static function safeContext(string $context): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', substr($context, 0, 64)) ?: 'application';
    }

    private static function redact(string $value): string
    {
        $value = preg_replace('/(?i)(password|passwd|smtp_password|authorization|secret|token)(\s*[=:]\s*)[^\s,;]+/', '$1$2[REDACTED]', $value) ?? $value;
        return substr($value, 0, 4000);
    }

    private static function redactTrace(string $trace): string
    {
        // getTraceAsString() no incluye argumentos, evitando volcar contraseñas o tokens.
        return substr($trace, 0, 12000);
    }
}
