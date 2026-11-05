<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Database;
use ZkTimeNet\Core\ErrorLogger;
use ZkTimeNet\Core\MigrationManager;
use ZkTimeNet\Core\MaintenanceGate;
use ZkTimeNet\Core\InstallationState;
use ZkTimeNet\Core\Session;
use ZkTimeNet\Core\Version;
use ZkTimeNet\Repositories\DepartmentRepository;
use ZkTimeNet\Repositories\SettingsRepository;

// Este archivo solo puede cargarse desde puntos de entrada autorizados.
if (PHP_SAPI !== 'cli' && !defined('ZKTIME_BOOTSTRAP_ALLOWED')) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

if (PHP_VERSION_ID < 80500) {
    http_response_code(500);
    exit('No fue posible iniciar el sistema. Contacta al administrador.');
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('No fue posible iniciar el sistema. Contacta al administrador.');
}
require_once $autoload;

Config::load(require __DIR__ . '/config/app.php');
date_default_timezone_set((string) Config::get('timezone', 'America/Mexico_City'));

// La primera instalación y las bases anteriores a cuentas individuales pasan por el asistente.
// La inspección no crea SQLite ni modifica datos: una instalación existente con esquema 3/4/5 continúa normal.
$installationState = InstallationState::inspect((string) Config::get('database'));
if ($installationState['needs_admin_setup']) {
    if (PHP_SAPI !== 'cli') {
        header('Cache-Control: no-store');
        header('Location: install.php', true, 303);
        exit;
    }
    throw new RuntimeException('La instalación requiere configuración inicial. Abre install.php desde el navegador.');
}

try {
    foreach (['storage_private', 'storage_uploads', 'storage_exports', 'storage_backups', 'storage_sessions'] as $key) {
        $directory = (string) Config::get($key);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de datos externo: ' . $directory . '. Concede Modify a la identidad del Application Pool sobre la carpeta de datos de ProgramData.');
        }
        @chmod($directory, 0700);
    }

    // Los errores nativos de PHP también se registran en almacenamiento privado.
    $privateLogs = (string) Config::get('storage_private') . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($privateLogs) && !mkdir($privateLogs, 0700, true) && !is_dir($privateLogs)) {
        throw new RuntimeException('No se pudo preparar el registro privado de errores.');
    }
    ini_set('error_log', $privateLogs . DIRECTORY_SEPARATOR . 'php-error.log');
    @chmod($privateLogs, 0700);

    // Toda petición HTTP conserva un bloqueo compartido. Restauración utiliza uno exclusivo
    // para impedir escrituras concurrentes mientras sustituye SQLite y archivos permanentes.
    if (PHP_SAPI !== 'cli') {
        MaintenanceGate::acquireShared();
    }

    // La comprobación de PHP/extensiones se hace después de preparar el registro privado.
    Version::assertRuntime();

    // La llave solo se crea cuando aún no existe una base SQLite. Nunca se reemplaza una llave perdida.
    $private = (string) Config::get('storage_private');
    $keyPath = $private . DIRECTORY_SEPARATOR . 'app.key';
    $databasePath = (string) Config::get('database');
    if (!is_file($keyPath)) {
        if (is_file($databasePath) && filesize($databasePath) > 0) {
            throw new RuntimeException('Falta la llave de cifrado pero ya existe SQLite. Restaura la llave respaldada desde el directorio de datos; no se generará otra para evitar perder acceso a la contraseña SMTP cifrada.');
        }
        if (file_put_contents($keyPath, random_bytes(32), LOCK_EX) !== 32) {
            throw new RuntimeException('IIS no pudo crear la llave de cifrado en la carpeta de datos. Revisa permisos Modify sobre el directorio de datos de ProgramData.');
        }
        @chmod($keyPath, 0600);
    }

    $pdo = Database::connection();
    $migrator = new MigrationManager($pdo, __DIR__ . '/database/migrations');
    // Inicializa el esquema únicamente en la instalación nueva. Las actualizaciones posteriores
    // continúan pasando por la pantalla de migraciones, con checksum y respaldo previo.
    if ($migrator->currentVersion() === 0) {
        $migrator->migrate();
    }

    $departments = new DepartmentRepository($pdo);
    $departmentCount = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
    if ($departmentCount === 0) {
        $departments->seedDefaults();
    }
    $settings = new SettingsRepository($pdo);
    // La duración por inactividad se lee desde SQLite antes de iniciar la sesión PHP,
    // de modo que el cambio administrativo aplica a todas las peticiones siguientes.
    $securitySettings = $settings->get('security', []);
    $securitySettings = is_array($securitySettings) ? $securitySettings : [];
    $defaultSessionMinutes = max(0, intdiv((int) Config::get('session_idle_timeout', 1800), 60));
    $sessionMinutes = filter_var($securitySettings['session_idle_timeout_minutes'] ?? $defaultSessionMinutes, FILTER_VALIDATE_INT);
    if ($sessionMinutes === false) {
        $sessionMinutes = $defaultSessionMinutes;
    }
    $sessionMinutes = max(0, min(10080, (int) $sessionMinutes));
    Config::set('session_idle_timeout', $sessionMinutes * 60);

    if ($settings->template()['subject_template'] === '') {
        $settings->saveTemplate(
            'Reporte de asistencia | {{departamento}} | Semana {{semana}}',
            "Buen día,\n\nAdjuntamos el reporte de asistencia correspondiente al departamento {{departamento}} para la semana {{semana}} del {{anio}} ({{fecha_inicio}} al {{fecha_fin}}).\n\nFavor de revisar la información y dar seguimiento a las incidencias que correspondan.",
            "Saludos,\n{{empresa}}\n{{aplicacion}}"
        );
    }
} catch (Throwable $exception) {
    http_response_code(503);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error de inicialización: ' . $exception->getMessage() . PHP_EOL);
    } else {
        $reference = ErrorLogger::capture($exception, 'bootstrap-initialization');
        $referenceText = $reference !== 'unavailable' ? ' Referencia: ' . $reference . '.' : '';
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Control de asistencia</title><body><h1>Control de asistencia</h1><p>No fue posible iniciar el sistema. Revisa los requisitos de PHP y la configuración del servidor o contacta al administrador.' . htmlspecialchars($referenceText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
    }
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    Session::start();
}
