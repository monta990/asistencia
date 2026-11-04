<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\ErrorLogger;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime-error-log-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
Config::load(['storage_private' => $root]);

$exception = new RuntimeException('SQLSTATE[HY000] /var/private/database.php password=secret-value');
$message = ErrorLogger::publicMessage($exception, 'No fue posible completar la operación.', 'test-context');
if (!str_starts_with($message, 'No fue posible completar la operación.')) {
    throw new RuntimeException('Technical exception text was not hidden.');
}
$log = $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'application.log';
if (!is_file($log)) {
    throw new RuntimeException('Private application log was not created.');
}
$contents = file_get_contents($log);
if (!is_string($contents) || str_contains($contents, 'secret-value') || !str_contains($contents, '[REDACTED]')) {
    throw new RuntimeException('Private error log did not redact the test secret.');
}

$validation = new InvalidArgumentException('El nombre de usuario es obligatorio.');
if (ErrorLogger::publicMessage($validation, 'Generic error.', 'validation-test') !== 'El nombre de usuario es obligatorio.') {
    throw new RuntimeException('Friendly validation message was not preserved.');
}

@unlink($log);
@unlink($log . '.1');
@rmdir(dirname($log));
@rmdir($root);
echo "PASS: private logging, redaction and safe public messages\n";
