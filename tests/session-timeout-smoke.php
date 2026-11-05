<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktimenet-session-timeout-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('No se pudo crear el directorio temporal de prueba.');
}
ini_set('session.gc_probability', '0');
Config::load([
    'session_idle_timeout' => 600,
    'storage_sessions' => $directory,
    'session_name' => 'ZKTIME_TEST_SESSION',
]);

try {
    Session::start();
    $_SESSION['timeout_probe'] = 'must-expire';
    $_SESSION['_last_activity'] = time() - 601;
    $expiredSessionId = session_id();
    session_write_close();

    session_id($expiredSessionId);
    Config::set('session_idle_timeout', 600);
    Session::start();
    if (isset($_SESSION['timeout_probe'])) {
        throw new RuntimeException('La sesión no expiró después de superar los 10 minutos configurados.');
    }
    $regeneratedSessionId = session_id();
    if ($regeneratedSessionId === $expiredSessionId) {
        throw new RuntimeException('La sesión expirada no regeneró su identificador.');
    }
    session_write_close();

    session_id($regeneratedSessionId);
    Session::start();
    $_SESSION['timeout_probe'] = 'must-remain';
    $_SESSION['_last_activity'] = time() - 604800;
    session_write_close();

    session_id($regeneratedSessionId);
    Config::set('session_idle_timeout', 0);
    Session::start();
    if (($_SESSION['timeout_probe'] ?? '') !== 'must-remain') {
        throw new RuntimeException('El valor 0 no conservó la sesión inactiva.');
    }
    if ((int) ini_get('session.gc_maxlifetime') < 2147483647) {
        throw new RuntimeException('La recolección PHP podría eliminar anticipadamente una sesión sin vencimiento por inactividad.');
    }
    session_destroy();

    echo "OK: timeout por inactividad y valor 0 sin expiración probados con sesiones PHP reales.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}
