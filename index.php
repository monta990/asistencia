<?php
declare(strict_types=1);

use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Database;
use ZkTimeNet\Core\ErrorLogger;
use ZkTimeNet\Core\Html;
use ZkTimeNet\Core\MigrationManager;
use ZkTimeNet\Core\MaintenanceGate;
use ZkTimeNet\Core\SecretVault;
use ZkTimeNet\Core\Session;
use ZkTimeNet\Core\Version;
use ZkTimeNet\Repositories\DepartmentRepository;
use ZkTimeNet\Repositories\ReportRepository;
use ZkTimeNet\Repositories\SettingsRepository;
use ZkTimeNet\Repositories\UserRepository;
use ZkTimeNet\Services\DepartmentSplitter;
use ZkTimeNet\Services\ConfigurationBackup;
use ZkTimeNet\Services\MailerService;
use ZkTimeNet\Services\SignatureImage;
use ZkTimeNet\Services\BrandLogo;
use ZkTimeNet\Services\TemplateRenderer;

if (!defined('ZKTIME_BOOTSTRAP_ALLOWED')) {
    define('ZKTIME_BOOTSTRAP_ALLOWED', true);
}
require __DIR__ . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (\ZkTimeNet\Core\Session::isHttps()) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'");

try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    http_response_code(503);
    $publicError = ErrorLogger::publicMessage($exception, 'No fue posible abrir el almacenamiento del sistema. Contacta al administrador.', 'database-connection');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>No se puede abrir el sistema</title><body><h1>Control de asistencia</h1><p>' . Html::escape($publicError) . '</p></body></html>';
    exit;
}

if ((string) ($_GET['action'] ?? '') === 'brand_logo') {
    $brandingSettings = (new SettingsRepository($pdo))->get('branding', []);
    $logoMetadata = is_array($brandingSettings) ? ($brandingSettings['logo_image'] ?? null) : null;
    $logoFile = BrandLogo::resolve($logoMetadata, (string) Config::get('storage_private'));
    if ($logoFile === null) {
        http_response_code(404);
        exit('No hay un logotipo configurado.');
    }
    header('Content-Type: ' . $logoFile['mime']);
    header('Content-Length: ' . (string) filesize($logoFile['path']));
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($logoFile['path']);
    exit;
}

if (Auth::check() && !Auth::refresh($pdo)) {
    Auth::logout();
    Html::redirect('login.php');
}

$page = (string) ($_GET['page'] ?? 'upload');
$action = (string) ($_GET['action'] ?? '');
if ($action === 'download') {
    if (!Auth::check()) {
        Html::redirect('login.php');
    }
    $id = (string) ($_GET['id'] ?? '');
    $statement = $pdo->prepare('SELECT archive_name, archive_path FROM report_runs WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        http_response_code(404);
        exit('No se encontró el reporte.');
    }
    $storedArchivePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['archive_path']);
    $legacyPrefix = 'storage' . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR;
    $candidatePaths = [];
    $allowedRoots = [realpath((string) Config::get('storage_exports'))];
    if (str_starts_with($storedArchivePath, $legacyPrefix)) {
        // Tras migrar storage/exports a ProgramData, las rutas 1.0.2 se resuelven
        // primero contra el nuevo directorio; se conserva fallback al directorio antiguo.
        $legacyRelative = substr($storedArchivePath, strlen($legacyPrefix));
        $candidatePaths[] = (string) Config::get('storage_exports') . DIRECTORY_SEPARATOR . $legacyRelative;
        $candidatePaths[] = __DIR__ . DIRECTORY_SEPARATOR . $storedArchivePath;
        $allowedRoots[] = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'exports');
    } else {
        $candidatePaths[] = (string) Config::get('storage_exports') . DIRECTORY_SEPARATOR . $storedArchivePath;
    }
    $path = false;
    $allowed = false;
    foreach ($candidatePaths as $candidate) {
        $resolved = realpath($candidate);
        foreach ($allowedRoots as $allowedRoot) {
            if ($resolved !== false && $allowedRoot !== false && str_starts_with($resolved, rtrim($allowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                $path = $resolved;
                $allowed = true;
                break 2;
            }
        }
    }
    if ($path === false || !$allowed || !is_file($path)) {
        http_response_code(404);
        exit('El archivo no está disponible en el almacenamiento permanente.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename((string) $row['archive_name']) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
if ($action === 'download_file') {
    if (!Auth::check()) {
        Html::redirect('login.php');
    }
    $fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $statement = $pdo->prepare('SELECT file_name, file_path FROM report_department_files WHERE id = :id');
    $statement->execute(['id' => $fileId ?: 0]);
    $row = $statement->fetch();
    $exports = realpath((string) Config::get('storage_exports'));
    $path = is_array($row) ? realpath((string) Config::get('storage_exports') . DIRECTORY_SEPARATOR . (string) $row['file_path']) : false;
    if (!is_array($row) || $path === false || $exports === false || !str_starts_with($path, $exports . DIRECTORY_SEPARATOR) || !is_file($path)) {
        http_response_code(404);
        exit('El Excel de departamento no está disponible.');
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename((string) $row['file_name']) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (!Auth::check()) {
    Html::redirect('login.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'download_config_backup') {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    $archivePath = null;
    try {
        Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        @set_time_limit(0);
        // Libera el bloqueo de archivo de sesión antes de esperar por el bloqueo
        // exclusivo; evita interbloqueos con otras pestañas que comparten cookie.
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            throw new RuntimeException('No se pudo cerrar temporalmente la sesión para preparar el respaldo.');
        }
        MaintenanceGate::switchToExclusive(60);
        $archivePath = ConfigurationBackup::createArchive($pdo, 'download');
        // El ZIP ya es una instantánea cerrada: no es necesario bloquear el sistema
        // durante todo el tiempo que tarde el navegador en descargarlo.
        MaintenanceGate::release();
        $downloadName = basename($archivePath);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($archivePath));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($archivePath);
    } catch (Throwable $exception) {
        http_response_code(503);
        $message = ErrorLogger::publicMessage($exception, 'No fue posible generar el respaldo de configuración.', 'configuration-backup');
        echo Html::escape($message);
    } finally {
        if (is_string($archivePath) && is_file($archivePath)) {
            @unlink($archivePath);
        }
    }
    exit;
}

if ($action === 'signature_preview') {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    $smtpSettings = (new SettingsRepository($pdo))->get('smtp', []);
    $signatureMeta = is_array($smtpSettings) ? ($smtpSettings['signature_image'] ?? null) : null;
    $signatureFile = SignatureImage::resolve($signatureMeta, (string) Config::get('storage_private'));
    if ($signatureFile === null) {
        http_response_code(404);
        exit('No hay una imagen de firma configurada.');
    }
    header('Content-Type: ' . $signatureFile['mime']);
    header('Content-Length: ' . (string) filesize($signatureFile['path']));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($signatureFile['path']);
    exit;
}

$migrator = new MigrationManager($pdo, __DIR__ . '/database/migrations');
$migrationStatus = $migrator->status();
if ($migrationStatus['future']) {
    http_response_code(503);
    exit('La base de datos pertenece a una versión más nueva del sistema. Actualiza el código o restaura una copia compatible.');
}
if ($migrationStatus['drift'] !== []) {
    http_response_code(503);
    exit('El historial de migraciones no coincide con el código instalado. No se inició la aplicación para evitar daños al esquema; restaura los archivos originales y revisa el respaldo de SQLite.');
}

if ($migrationStatus['current'] < $migrationStatus['target'] && !Auth::isAdmin()) {
    http_response_code(503);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Actualización pendiente</title><body><h1>Actualización pendiente</h1><p>El administrador debe ingresar al sistema y aplicar la actualización antes de que el personal pueda continuar. No se han modificado tus datos.</p><p><a href="login.php">Volver al acceso</a></p></body></html>';
    exit;
}
if ($migrationStatus['current'] < $migrationStatus['target'] && $page !== 'updates') {
    Html::redirect('index.php?page=updates');
}
if (!Auth::canAccessPage($page)) {
    Html::flash('error', 'Tu perfil no tiene permiso para abrir esa sección.');
    Html::redirect('index.php?page=dashboard');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'restore_config_backup') {
    @set_time_limit(0);
    $uploadedStage = null;
    $rollbackStage = null;
    $rollbackArchive = null;
    try {
        if (!Auth::isAdmin()) {
            throw new RuntimeException('Solo un administrador puede restaurar un respaldo.');
        }
        Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        $currentAdminId = (int) (Auth::user()['id'] ?? 0);
        $passwordStatement = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND role = 'admin' AND is_active = 1 LIMIT 1");
        $passwordStatement->execute(['id' => $currentAdminId]);
        $currentAdminHash = $passwordStatement->fetchColumn();
        $confirmationPassword = (string) ($_POST['confirm_password'] ?? '');
        if (!is_string($currentAdminHash) || $currentAdminHash === '' || !password_verify($confirmationPassword, $currentAdminHash)) {
            throw new RuntimeException('La contraseña del administrador no es correcta. No se modificó ningún archivo.');
        }

        $upload = $_FILES['backup_file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            throw new RuntimeException('Selecciona un archivo ZIP de respaldo válido.');
        }
        $uploadedStage = ConfigurationBackup::prepareRestore((string) $upload['tmp_name']);

        // Libera el bloqueo PHP de sesión antes de esperar el lock exclusivo: otras
        // pestañas pueden finalizar sus peticiones y liberar el lock compartido.
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            throw new RuntimeException('No se pudo cerrar temporalmente la sesión para restaurar el respaldo.');
        }
        MaintenanceGate::switchToExclusive(60);
        Session::start();
        if (!Auth::refresh($pdo) || !Auth::isAdmin() || (int) (Auth::user()['id'] ?? 0) !== $currentAdminId) {
            throw new RuntimeException('La sesión administrativa cambió mientras se validaba el respaldo. Inicia sesión nuevamente e inténtalo otra vez.');
        }
        $passwordStatement->execute(['id' => $currentAdminId]);
        $currentAdminHash = $passwordStatement->fetchColumn();
        if (!is_string($currentAdminHash) || $currentAdminHash === '' || !password_verify($confirmationPassword, $currentAdminHash)) {
            throw new RuntimeException('La contraseña del administrador cambió o ya no es válida. No se modificó ningún archivo.');
        }

        $rollbackArchive = ConfigurationBackup::createArchive($pdo, 'pre-restore');
        $rollbackStage = ConfigurationBackup::prepareRestore($rollbackArchive);

        // Libera todas las referencias SQLite antes de sustituir el archivo en Windows/IIS.
        $migrator = null;
        unset($passwordStatement);
        $pdo = null;
        Database::disconnect();
        gc_collect_cycles();

        try {
            ConfigurationBackup::applyRestore($uploadedStage);
            $restoredPdo = Database::connection();
            $restoredMigrator = new MigrationManager($restoredPdo, __DIR__ . '/database/migrations');
            $restoredStatus = $restoredMigrator->status();
            if ($restoredStatus['future'] || $restoredStatus['drift'] !== []) {
                throw new RuntimeException('El respaldo restaurado presenta una versión futura o inconsistencias de migración.');
            }
            if ($restoredStatus['pending'] !== []) {
                $restoredMigrator->migrate();
            }
            if ($restoredMigrator->currentVersion() !== Version::SCHEMA_TARGET) {
                throw new RuntimeException('La base restaurada no alcanzó el esquema requerido por esta versión.');
            }
            // Los archivos de sesión se excluyen del ZIP, pero podrían seguir en el
            // servidor. Revoca todas las sesiones previas, incluidas las del respaldo.
            $restoredPdo->exec('UPDATE users SET session_version = session_version + 1');
            $restoredMigrator = null;
            $restoredPdo = null;
            Database::disconnect();
            gc_collect_cycles();
        } catch (Throwable $restoreFailure) {
            unset($restoredMigrator, $restoredPdo);
            Database::disconnect();
            gc_collect_cycles();
            try {
                ConfigurationBackup::applyRestore($rollbackStage);
                $rollbackPdo = Database::connection();
                $rollbackMigrator = new MigrationManager($rollbackPdo, __DIR__ . '/database/migrations');
                $rollbackStatus = $rollbackMigrator->status();
                if ($rollbackStatus['future'] || $rollbackStatus['drift'] !== []) {
                    throw new RuntimeException('El respaldo de seguridad previo no pudo validarse completamente.');
                }
                $rollbackMigrator = null;
                $rollbackPdo = null;
                Database::disconnect();
            } catch (Throwable $rollbackFailure) {
                ErrorLogger::capture($rollbackFailure, 'configuration-restore-rollback');
                http_response_code(503);
                echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Recuperación requerida</title><body><h1>La restauración y la recuperación automática requieren atención</h1><p>No continúes utilizando el sistema. Conserva el respaldo previo generado en la carpeta privada de backups y contacta al administrador del servidor.</p><p>Archivo de recuperación: ' . Html::escape((string) $rollbackArchive) . '</p></body></html>';
                exit;
            }
            throw new RuntimeException('No se aplicó el respaldo seleccionado. El sistema recuperó automáticamente los datos anteriores. Detalle: ' . $restoreFailure->getMessage(), 0, $restoreFailure);
        }

        ConfigurationBackup::cleanupStage($uploadedStage);
        $uploadedStage = null;
        ConfigurationBackup::cleanupStage($rollbackStage);
        $rollbackStage = null;
        Auth::logout();
        header('Location: login.php?restored=1', true, 303);
        exit;
    } catch (Throwable $exception) {
        if (is_string($uploadedStage)) {
            ConfigurationBackup::cleanupStage($uploadedStage);
        }
        if (is_string($rollbackStage)) {
            ConfigurationBackup::cleanupStage($rollbackStage);
        }
        $message = ErrorLogger::publicMessage($exception, 'No fue posible restaurar el respaldo. No continúes si no estás seguro de que los datos siguen íntegros.', 'configuration-restore');
        Html::flash('error', $message);
        Html::redirect('index.php?page=updates');
    }
}

$departments = new DepartmentRepository($pdo);
$settings = new SettingsRepository($pdo);
$brandingDefaults = (array) Config::get('branding_defaults', []);
$brandingSaved = $settings->get('branding', []);
$branding = array_replace($brandingDefaults, is_array($brandingSaved) ? $brandingSaved : []);
$logoFilename = is_array($branding['logo_image'] ?? null) ? (string) ($branding['logo_image']['filename'] ?? '') : '';
$brandLogoAvailable = BrandLogo::resolve($branding['logo_image'] ?? null, (string) Config::get('storage_private')) !== null;
$brandLogoUrl = 'index.php?action=brand_logo&v=' . substr(hash('sha256', $logoFilename), 0, 12);
$reports = new ReportRepository($pdo);
$users = new UserRepository($pdo);
$mailer = new MailerService($settings);

/** Envía un Excel histórico a los destinatarios actuales y registra cada intento. */
$deliverDepartmentFile = static function (int $fileId, string $expectedRunId = '') use ($reports, $departments, $mailer, $branding): array {
    if ($fileId < 1) {
        throw new InvalidArgumentException('El archivo de departamento seleccionado no es válido.');
    }
    $deliveryFile = $reports->findDepartmentFileForDelivery($fileId);
    if (!is_array($deliveryFile)) {
        throw new RuntimeException('No se encontró el archivo histórico de ese departamento.');
    }
    if ($expectedRunId !== '' && !hash_equals((string) $deliveryFile['run_id'], $expectedRunId)) {
        throw new RuntimeException('El archivo seleccionado no pertenece a la ejecución abierta. Recarga el historial.');
    }
    $departmentId = (int) ($deliveryFile['department_id'] ?? 0);
    if ($departmentId < 1) {
        throw new RuntimeException('El departamento ya no está vinculado al catálogo.');
    }
    if ((int) ($deliveryFile['department_enabled'] ?? 0) !== 1 || (int) ($deliveryFile['needs_configuration'] ?? 0) === 1) {
        return [
            'status' => 'disabled',
            'department' => (string) $deliveryFile['department_name'],
            'sent' => 0,
            'failed' => 0,
            'skipped' => true,
            'message' => (int) ($deliveryFile['needs_configuration'] ?? 0) === 1
                ? 'Pendiente de configuración; el Excel se conserva, pero no se distribuye.'
                : 'Departamento deshabilitado; el Excel se conserva, pero no se distribuye.',
        ];
    }
    $recipients = $departments->deliveryRecipients($departmentId);
    if ($recipients === []) {
        return [
            'status' => 'no_recipients',
            'department' => (string) $deliveryFile['department_name'],
            'sent' => 0,
            'failed' => 0,
            'skipped' => true,
            'message' => 'Sin correo configurado.',
        ];
    }

    $exportsRoot = realpath((string) Config::get('storage_exports'));
    $relativeFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $deliveryFile['file_path']);
    $attachment = $exportsRoot !== false ? realpath($exportsRoot . DIRECTORY_SEPARATOR . $relativeFile) : false;
    $pathIsSafe = $attachment !== false && $exportsRoot !== false
        && str_starts_with($attachment, rtrim($exportsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && is_file($attachment) && is_readable($attachment);

    $delivery = ['sent' => [], 'failed' => []];
    if (!$mailer->isConfigured()) {
        $error = 'Configura los parámetros SMTP antes de enviar correos.';
        $delivery['failed'] = array_fill_keys($recipients, $error);
    } elseif (!$pathIsSafe) {
        $error = 'El Excel histórico no está disponible en el almacenamiento permanente.';
        $delivery['failed'] = array_fill_keys($recipients, $error);
    } else {
        $context = [
            'departamento' => (string) $deliveryFile['department_name'],
            'etiqueta_departamento' => (string) $deliveryFile['report_label'],
            'semana' => (string) $deliveryFile['week'],
            'anio' => (string) $deliveryFile['iso_year'],
            'fecha_inicio' => date('d/m/Y', strtotime((string) $deliveryFile['start_date'])),
            'fecha_fin' => date('d/m/Y', strtotime((string) $deliveryFile['end_date'])),
            'archivo' => (string) $deliveryFile['file_name'],
            'reporte' => (string) $deliveryFile['original_filename'],
            'empresa' => (string) $branding['company_name'],
            'aplicacion' => (string) $branding['application_name'],
            'plataforma' => (string) $branding['platform_label'],
            'lema' => (string) $branding['brand_tagline'],
            'fecha_proceso' => date('d/m/Y H:i'),
            'usuario' => (string) (Auth::user()['name'] ?? Auth::user()['username'] ?? 'Administrador'),
            'total_departamentos' => (string) count($reports->departmentFiles((string) $deliveryFile['run_id'])),
        ];
        try {
            $delivery = $mailer->sendDepartment(
                $recipients,
                (string) $attachment,
                $context,
                $departments->responsibleEmail($departmentId)
            );
        } catch (Throwable $mailException) {
            $reference = ErrorLogger::capture($mailException, 'individual-mail-send');
            $safeFailure = 'No se pudo preparar el envío de correo. Contacta al administrador.'
                . ($reference !== 'unavailable' ? ' Referencia: ' . $reference . '.' : '');
            $delivery['failed'] = array_fill_keys($recipients, $safeFailure);
        }
    }

    $errorText = '';
    if ($delivery['failed'] !== []) {
        $errorText = implode(' | ', array_map(
            static fn (string $email, string $message): string => $email . ': ' . $message,
            array_keys($delivery['failed']),
            array_values($delivery['failed'])
        ));
    }
    $status = $delivery['failed'] === [] ? 'sent' : 'failed';
    $reports->updateDelivery($fileId, $status, substr($errorText, 0, 1000), $delivery);

    return [
        'status' => $status,
        'department' => (string) $deliveryFile['department_name'],
        'sent' => count($delivery['sent']),
        'failed' => count($delivery['failed']),
        'sent_recipients' => array_values($delivery['sent']),
        'failed_recipients' => $delivery['failed'],
        'skipped' => false,
        'message' => $status === 'sent' ? 'Todos los correos se enviaron correctamente.' : 'El envío terminó con uno o más fallos.',
    ];
};

$allowedPages = ['dashboard', 'upload', 'departments', 'mail', 'branding', 'history', 'updates', 'users', 'account'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'bulk_send_one') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    try {
        Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        set_time_limit(300);
        $fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
        $expectedRunId = (string) ($_GET['run'] ?? '');
        if ($expectedRunId === '') {
            throw new InvalidArgumentException('No se indicó la ejecución del historial.');
        }
        $result = $deliverDepartmentFile((int) ($fileId ?: 0), $expectedRunId);
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        http_response_code($exception instanceof \InvalidArgumentException ? 422 : 500);
        $publicError = ErrorLogger::publicMessage($exception, 'No fue posible completar el envío. Inténtalo nuevamente o contacta al administrador.', 'bulk-mail-endpoint');
        echo json_encode(['ok' => false, 'error' => substr($publicError, 0, 500)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        $postAction = (string) ($_POST['action'] ?? '');
        $administratorActions = ['save_branding', 'save_mail', 'smtp_test', 'run_migrations', 'save_user', 'set_user_active', 'save_login_policy', 'unlock_user'];
        if (in_array($postAction, $administratorActions, true) && !Auth::isAdmin()) {
            throw new RuntimeException('Tu perfil no tiene permiso para realizar esta operación.');
        }
        switch ($postAction) {
            case 'save_user':
                $userId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
                $users->save(
                    $userId,
                    (string) ($_POST['person_name'] ?? ''),
                    (string) ($_POST['username'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['role'] ?? 'nominista')
                );
                Html::flash('success', $userId === null ? 'Usuario creado correctamente.' : 'Datos del usuario actualizados.');
                Html::redirect('index.php?page=users');

            case 'save_login_policy':
                $maximumAttempts = filter_var($_POST['max_login_attempts'] ?? null, FILTER_VALIDATE_INT);
                $lockoutMinutes = filter_var($_POST['lockout_minutes'] ?? null, FILTER_VALIDATE_INT);
                if ($maximumAttempts === false || $maximumAttempts < 3 || $maximumAttempts > 20) {
                    throw new RuntimeException('Los intentos permitidos deben estar entre 3 y 20.');
                }
                if ($lockoutMinutes === false || $lockoutMinutes < 1 || $lockoutMinutes > 1440) {
                    throw new RuntimeException('Los minutos de bloqueo deben estar entre 1 y 1440.');
                }
                $settings->set('security', [
                    'max_login_attempts' => $maximumAttempts,
                    'lockout_minutes' => $lockoutMinutes,
                ]);
                Html::flash('success', 'La política de bloqueo de acceso se actualizó correctamente.');
                Html::redirect('index.php?page=users');

            case 'unlock_user':
                $users->unlock((int) ($_POST['id'] ?? 0));
                Html::flash('success', 'La cuenta se desbloqueó. Se invalidaron las sesiones anteriores de ese usuario.');
                Html::redirect('index.php?page=users');

            case 'set_user_active':
                $userId = (int) ($_POST['id'] ?? 0);
                if ($userId === (int) (Auth::user()['id'] ?? 0)) {
                    throw new RuntimeException('No puedes desactivar la cuenta con la que tienes abierta esta sesión.');
                }
                $active = (string) ($_POST['active'] ?? '0') === '1';
                $users->setActive($userId, $active, (int) (Auth::user()['id'] ?? 0));
                Html::flash('success', $active ? 'Usuario activado.' : 'Usuario desactivado.');
                Html::redirect('index.php?page=users');

            case 'change_own_password':
                $currentUserId = (int) (Auth::user()['id'] ?? 0);
                $newSessionVersion = $users->changeOwnPassword(
                    $currentUserId,
                    (string) ($_POST['current_password'] ?? ''),
                    (string) ($_POST['new_password'] ?? ''),
                    (string) ($_POST['confirm_password'] ?? '')
                );
                session_regenerate_id(true);
                Auth::updateCurrentSessionVersion($newSessionVersion);
                Html::flash('success', 'Tu contraseña se actualizó correctamente.');
                Html::redirect('index.php?page=account');

            case 'rename_run_original_filename':
                $runId = trim((string) ($_POST['run_id'] ?? ''));
                $newFilename = $reports->renameRunOriginalFilename($runId, (string) ($_POST['filename_base'] ?? ''));
                Html::flash('success', 'Nombre del archivo actualizado: ' . $newFilename . '.');
                Html::redirect('index.php?page=history&run=' . rawurlencode($runId));

            case 'save_department':
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
                $emails = preg_split('/[\r\n,;]+/', (string) ($_POST['recipients'] ?? '')) ?: [];
                $departments->save(
                    $id,
                    (string) ($_POST['report_label'] ?? ''),
                    (string) ($_POST['display_name'] ?? ''),
                    (string) ($_POST['responsible_email'] ?? ''),
                    isset($_POST['enabled']),
                    (int) ($_POST['position'] ?? 0),
                    $emails
                );
                Html::flash('success', 'Departamento y destinatarios guardados.');
                Html::redirect('index.php?page=departments');

            case 'delete_department':
                $departments->delete((int) ($_POST['id'] ?? 0));
                Html::flash('success', 'Departamento eliminado del catálogo. Los reportes históricos se conservan.');
                Html::redirect('index.php?page=departments');

            case 'save_branding':
                $fields = [
                    'company_name' => ['Nombre de la empresa', 100],
                    'brand_mark' => ['Texto del logotipo', 8],
                    'brand_tagline' => ['Lema o descripción', 120],
                    'platform_label' => ['Etiqueta de plataforma', 80],
                    'module_label' => ['Nombre del módulo', 80],
                    'application_name' => ['Nombre de la aplicación', 100],
                    'footer_text' => ['Texto del pie de página', 160],
                ];
                $newBranding = [];
                foreach ($fields as $key => [$label, $maxLength]) {
                    $value = trim((string) ($_POST[$key] ?? ''));
                    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
                    if ($value === '' || strlen($value) > $maxLength) {
                        throw new InvalidArgumentException($label . ' es obligatorio y admite hasta ' . $maxLength . ' caracteres.');
                    }
                    $newBranding[$key] = $value;
                }

                $previousBranding = $branding;
                $oldLogo = $previousBranding['logo_image'] ?? null;
                $newLogo = null;
                $logoMetadata = $oldLogo;
                $logoUpload = $_FILES['brand_logo'] ?? null;
                if (is_array($logoUpload) && (int) ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newLogo = BrandLogo::store($logoUpload, (string) Config::get('storage_private'));
                    $logoMetadata = $newLogo;
                } elseif (isset($_POST['remove_brand_logo'])) {
                    $logoMetadata = null;
                }
                $newBranding['logo_image'] = $logoMetadata;

                try {
                    $pdo->beginTransaction();
                    $settings->set('branding', $newBranding);
                    $savedSmtp = $settings->get('smtp', []);
                    if (is_array($savedSmtp) && trim((string) ($savedSmtp['from_name'] ?? '')) !== ''
                        && strcasecmp(trim((string) $savedSmtp['from_name']), (string) ($previousBranding['company_name'] ?? '')) === 0) {
                        $savedSmtp['from_name'] = $newBranding['company_name'];
                        $settings->set('smtp', $savedSmtp);
                    }
                    // Si el pie sigue siendo el valor de fábrica, convertirlo a una variable dinámica.
                    // Los pies personalizados por el usuario se conservan sin modificaciones.
                    $savedTemplate = $settings->template();
                    $legacyDefaultFooter = "Saludos,\n{{empresa}}\nControl de asistencia";
                    if (trim((string) ($savedTemplate['footer_template'] ?? '')) === $legacyDefaultFooter) {
                        $settings->saveTemplate(
                            (string) $savedTemplate['subject_template'],
                            (string) $savedTemplate['body_template'],
                            "Saludos,\n{{empresa}}\n{{aplicacion}}"
                        );
                    }
                    $pdo->commit();
                } catch (Throwable $saveException) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($newLogo !== null) {
                        BrandLogo::delete($newLogo, (string) Config::get('storage_private'));
                    }
                    throw $saveException;
                }

                if ($oldLogo !== $logoMetadata) {
                    BrandLogo::delete($oldLogo, (string) Config::get('storage_private'));
                }
                $branding = array_replace($brandingDefaults, $newBranding);
                Html::flash('success', 'Identidad visual guardada. Se aplicará en el panel y el acceso.');
                Html::redirect('index.php?page=branding');

            case 'save_mail':
                $smtp = $settings->get('smtp', []);
                if (!is_array($smtp)) {
                    $smtp = [];
                }
                $host = trim((string) ($_POST['host'] ?? ''));
                $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $port = (int) ($_POST['port'] ?? 587);
                $encryption = (string) ($_POST['encryption'] ?? 'tls');
                if ($host === '' || strlen($host) > 255 || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || $port < 1 || $port > 65535 || !in_array($encryption, ['none', 'tls', 'ssl'], true)) {
                    throw new InvalidArgumentException('Revisa el host, puerto, cifrado y correo remitente SMTP.');
                }
                $password = (string) ($_POST['password'] ?? '');
                if (strlen($password) > 4096) {
                    throw new InvalidArgumentException('La contraseña SMTP supera el tamaño permitido.');
                }
                $replyToRaw = trim((string) ($_POST['reply_to'] ?? ''));
                $replyToItems = preg_split('/[,;\\s]+/', $replyToRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $replyTo = [];
                foreach ($replyToItems as $replyAddress) {
                    $replyAddress = strtolower(trim($replyAddress));
                    if (strlen($replyAddress) > 254 || !filter_var($replyAddress, FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException('Responder a contiene una dirección no válida: ' . substr($replyAddress, 0, 100));
                    }
                    $replyTo[$replyAddress] = $replyAddress;
                    if (count($replyTo) > 50) {
                        throw new InvalidArgumentException('Responder a admite un máximo de 50 direcciones.');
                    }
                }
                $replyTo = array_values($replyTo);
                $subjectTemplate = trim((string) ($_POST['subject_template'] ?? ''));
                $bodyTemplate = (string) ($_POST['body_template'] ?? '');
                $footerTemplate = (string) ($_POST['footer_template'] ?? '');
                if ($subjectTemplate === '' || strlen($subjectTemplate) > 250 || strlen($bodyTemplate) > 20000 || strlen($footerTemplate) > 10000) {
                    throw new InvalidArgumentException('Revisa las plantillas: asunto máximo 250 caracteres, cuerpo 20,000 y pie 10,000.');
                }
                $encrypted = (string) ($smtp['password_encrypted'] ?? '');
                if (isset($_POST['clear_password'])) {
                    $encrypted = '';
                } elseif ($password !== '') {
                    $encrypted = SecretVault::encrypt($password);
                }
                $oldSignature = $smtp['signature_image'] ?? null;
                $newSignature = null;
                $signatureMetadata = $oldSignature;
                $signatureUpload = $_FILES['signature_image'] ?? null;
                if (is_array($signatureUpload) && (int) ($signatureUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newSignature = SignatureImage::store($signatureUpload, (string) Config::get('storage_private'));
                    $signatureMetadata = $newSignature;
                } elseif (isset($_POST['remove_signature_image'])) {
                    $signatureMetadata = null;
                }
                try {
                    $pdo->beginTransaction();
                    $settings->set('smtp', [
                        'host' => $host,
                        'port' => $port,
                        'encryption' => $encryption,
                        'username' => $username,
                        'password_encrypted' => $encrypted,
                        'from_email' => $fromEmail,
                        'from_name' => trim((string) ($_POST['from_name'] ?? $branding['company_name'])),
                        'reply_to' => $replyTo,
                        'department_recipients_reply_to' => isset($_POST['department_recipients_reply_to']),
                        'signature_image' => $signatureMetadata,
                        'timeout' => max(5, min(60, (int) ($_POST['timeout'] ?? 20))),
                    ]);
                    $settings->saveTemplate($subjectTemplate, $bodyTemplate, $footerTemplate);
                    $pdo->commit();
                } catch (Throwable $saveException) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($newSignature !== null) {
                        SignatureImage::delete($newSignature, (string) Config::get('storage_private'));
                    }
                    throw $saveException;
                }
                if ($oldSignature !== $signatureMetadata) {
                    SignatureImage::delete($oldSignature, (string) Config::get('storage_private'));
                }
                Html::flash('success', 'Configuración SMTP, firma y plantillas guardadas.');
                Html::redirect('index.php?page=mail');

            case 'smtp_test':
                $recipient = trim((string) ($_POST['test_recipient'] ?? ''));
                $mailer->test($recipient);
                Html::flash('success', 'Correo de prueba enviado a ' . $recipient . '.');
                Html::redirect('index.php?page=mail');

            case 'resend_department_file':
                $fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
                $delivery = $deliverDepartmentFile((int) ($fileId ?: 0), (string) ($_GET['run'] ?? ''));
                if (($delivery['status'] ?? '') === 'disabled') {
                    Html::flash('warning', $delivery['message'] . ' No se envió ningún correo.');
                } elseif (($delivery['status'] ?? '') === 'no_recipients') {
                    Html::flash('error', 'Este departamento no tiene correos configurados.');
                } elseif (($delivery['status'] ?? '') === 'sent') {
                    Html::flash('success', 'Correo enviado correctamente a ' . (int) $delivery['sent'] . ' destinatario(s) de ' . $delivery['department'] . '.');
                } else {
                    Html::flash('error', 'Envío finalizado con incidencias: ' . (int) $delivery['sent'] . ' enviado(s), ' . (int) $delivery['failed'] . ' fallido(s). Revisa la auditoría de entrega.');
                }
                $deliveryFile = $reports->findDepartmentFileForDelivery((int) ($fileId ?: 0));
                Html::redirect('index.php?page=history&run=' . rawurlencode((string) ($deliveryFile['run_id'] ?? '')));

            case 'process_report':
                set_time_limit(300);
                if (!isset($_FILES['report']) || !is_array($_FILES['report'])) {
                    throw new RuntimeException('Selecciona el reporte Excel de ZKTimeNet.');
                }
                $upload = $_FILES['report'];
                $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $messages = [UPLOAD_ERR_INI_SIZE => 'El reporte excede upload_max_filesize.', UPLOAD_ERR_FORM_SIZE => 'El reporte excede el tamaño permitido.', UPLOAD_ERR_PARTIAL => 'La carga quedó incompleta.', UPLOAD_ERR_NO_FILE => 'Selecciona un archivo XLSX.', UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal de PHP.', UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo cargado.', UPLOAD_ERR_EXTENSION => 'Una extensión de PHP bloqueó la carga.'];
                    throw new RuntimeException($messages[$uploadError] ?? 'No se pudo recibir el archivo.');
                }
                $originalName = basename((string) ($upload['name'] ?? 'reporte.xlsx'));
                $temporary = (string) ($upload['tmp_name'] ?? '');
                $maxBytes = (int) Config::get('max_upload_bytes', 26214400);
                if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx' || $temporary === '' || !is_uploaded_file($temporary)) {
                    throw new RuntimeException('La carga debe ser un archivo XLSX recibido por el formulario.');
                }
                if ((int) ($upload['size'] ?? 0) < 1 || (int) ($upload['size'] ?? 0) > $maxBytes) {
                    throw new RuntimeException('El archivo está vacío o supera el límite de ' . number_format($maxBytes / 1024 / 1024, 0) . ' MB.');
                }
                $runId = bin2hex(random_bytes(16));
                $runDirectory = rtrim((string) Config::get('storage_exports'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $runId;
                if (!mkdir($runDirectory, 0700, true) && !is_dir($runDirectory)) {
                    throw new RuntimeException('No se pudo crear el directorio de la ejecución.');
                }
                $uploadedPath = rtrim((string) Config::get('storage_uploads'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $runId . '.xlsx';
                if (!move_uploaded_file($temporary, $uploadedPath)) {
                    DepartmentSplitter::removeDirectory($runDirectory);
                    throw new RuntimeException('No se pudo mover el reporte al almacenamiento temporal privado.');
                }
                $runPersisted = false;
                try {
                    $splitter = new DepartmentSplitter($departments);
                    $result = $splitter->split($uploadedPath, $runDirectory);
                    $sendEmails = false; // Los correos se envían manualmente desde el historial.
                    $fileRows = [];
                    foreach ($result['files'] as $file) {
                        $recipients = $departments->deliveryRecipients((int) $file['department_id']);
                        $isEnabledForDelivery = !empty($file['department_enabled']) && empty($file['needs_configuration']);
                        $initialDeliveryStatus = !$isEnabledForDelivery
                            ? 'disabled'
                            : ($recipients === [] ? 'no_recipients' : ($sendEmails ? 'pending' : 'not_requested'));
                        $fileRows[] = [
                            'department_id' => $file['department_id'],
                            'report_label' => $file['report_label'],
                            'department_name' => $file['department_name'],
                            'file_name' => $file['file_name'],
                            'relative_path' => $runId . '/' . str_replace($runDirectory . DIRECTORY_SEPARATOR, '', $file['absolute_path']),
                            'recipients' => $recipients,
                            'delivery_status' => $initialDeliveryStatus,
                            'absolute_path' => $file['absolute_path'],
                            'week' => $file['week'],
                            'iso_year' => $file['iso_year'],
                            'start_date' => $file['start_date'],
                            'end_date' => $file['end_date'],
                        ];
                    }
                    $archiveRelative = $runId . DIRECTORY_SEPARATOR . $result['zip_name'];
                    $reports->createRun([
                        'id' => $runId,
                        'original_filename' => $originalName,
                        'archive_name' => $result['zip_name'],
                        'archive_path' => $archiveRelative,
                        'week' => implode(', ', $result['weeks']),
                        'year' => $result['iso_year'],
                        'start_date' => $result['start_date'],
                        'end_date' => $result['end_date'],
                        'status' => 'completed',
                        'created_by' => (int) (Auth::user()['id'] ?? 0) ?: null,
                    ], $fileRows);
                    $runPersisted = true;

                    if ($sendEmails) {
                        $savedFiles = $reports->departmentFiles($runId);
                        foreach ($savedFiles as $index => $saved) {
                            $file = $fileRows[$index];
                            if ($file['recipients'] === [] || empty($file['department_enabled']) || !empty($file['needs_configuration'])) {
                                continue;
                            }
                            $context = [
                                'departamento' => $file['department_name'],
                                'etiqueta_departamento' => $file['report_label'],
                                'semana' => $file['week'],
                                'anio' => $file['iso_year'],
                                'fecha_inicio' => date('d/m/Y', strtotime($file['start_date'])),
                                'fecha_fin' => date('d/m/Y', strtotime($file['end_date'])),
                                'archivo' => $file['file_name'],
                                'reporte' => $originalName,
                                'empresa' => (string) $branding['company_name'],
                                'aplicacion' => (string) $branding['application_name'],
                                'plataforma' => (string) $branding['platform_label'],
                                'lema' => (string) $branding['brand_tagline'],
                                'fecha_proceso' => date('d/m/Y H:i'),
                                'usuario' => (string) (Auth::user()['name'] ?? Auth::user()['username']),
                                'total_departamentos' => (string) count($fileRows),
                            ];
                            try {
                                $delivery = $mailer->sendDepartment($file['recipients'], $file['absolute_path'], $context, $departments->responsibleEmail((int) $file['department_id']));
                                $errorText = '';
                                if ($delivery['failed'] !== []) {
                                    $errorText = implode(' | ', array_map(
                                        static fn (string $email, string $error): string => $email . ': ' . $error,
                                        array_keys($delivery['failed']),
                                        array_values($delivery['failed'])
                                    ));
                                }
                                $deliveryStatus = $delivery['failed'] === [] ? 'sent' : 'failed';
                                $reports->updateDelivery((int) $saved['id'], $deliveryStatus, substr($errorText, 0, 1000), $delivery);
                            } catch (Throwable $mailException) {
                                $reference = ErrorLogger::capture($mailException, 'report-mail-send');
                                $safeFailure = 'No se pudo completar el envío de este departamento. Contacta al administrador.'
                                    . ($reference !== 'unavailable' ? ' Referencia: ' . $reference . '.' : '');
                                $failedResults = ['sent' => [], 'failed' => array_fill_keys($file['recipients'], $safeFailure)];
                                $reports->updateDelivery((int) $saved['id'], 'failed', $safeFailure, $failedResults);
                            }
                        }
                    }
                    $reports->updateRunCounters($runId);
                    $newDepartmentNames = array_values(array_unique(array_map(
                        static fn (array $file): string => (string) $file['report_label'],
                        array_filter($result['files'], static fn (array $file): bool => !empty($file['newly_discovered']))
                    )));
                    $flashMessage = 'Reporte procesado: ' . count($fileRows) . ' archivos Excel generados. ' . ($sendEmails ? 'Se ejecutó el envío por departamento.' : 'Puedes enviar los reportes por correo desde el historial cuando lo necesites.');
                    if ($newDepartmentNames !== []) {
                        $flashMessage .= ' Se detectaron y agregaron al catálogo como pendientes de configuración: ' . implode(', ', $newDepartmentNames) . '. No se enviarán hasta que los habilites y configures.';
                    }
                    Html::flash('success', $flashMessage);
                    Html::redirect('index.php?page=history&run=' . rawurlencode($runId));
                } catch (Throwable $processingException) {
                    if (!$runPersisted) {
                        DepartmentSplitter::removeDirectory($runDirectory);
                    }
                    throw $processingException;
                } finally {
                    if (is_file($uploadedPath)) {
                        @unlink($uploadedPath);
                    }
                }

            case 'run_migrations':
                $result = $migrator->migrate();
                Auth::upgradeLegacySession($pdo);
                Html::flash('success', 'Esquema actualizado. Migraciones aplicadas: ' . (count($result['applied']) ? implode(', ', $result['applied']) : 'ninguna') . '. El acceso ahora utiliza usuario y contraseña.');
                Html::redirect('index.php?page=dashboard');

            default:
                throw new RuntimeException('La acción solicitada no está disponible.');
        }
    } catch (Throwable $exception) {
        $publicError = ErrorLogger::publicMessage($exception, 'No fue posible completar la operación. Inténtalo nuevamente o contacta al administrador.', 'post-action-' . $page);
        Html::flash('error', $publicError);
        $returnUrl = 'index.php?page=' . rawurlencode($page);
        if ($page === 'history' && isset($_GET['run'])) {
            $returnUrl .= '&run=' . rawurlencode((string) $_GET['run']);
        }
        Html::redirect($returnUrl);
    }
}

$flash = Html::takeFlash();
$pageTitle = match ($page) {
    'upload' => 'Procesar reporte',
    'departments' => 'Departamentos y destinatarios',
    'mail' => 'Correo y plantillas',
    'branding' => 'Identidad visual',
    'history' => 'Historial de ejecuciones',
    'updates' => 'Actualizaciones del sistema',
    'users' => 'Gestión de usuarios',
    'account' => 'Mi cuenta',
    default => 'Resumen',
};
$user = Auth::user() ?? [];
$schemaCurrent = (int) $migrationStatus['current'];
$stats = $schemaCurrent >= Version::SCHEMA_TARGET ? $reports->stats() : ['runs' => 0, 'departments' => 0, 'sent' => 0, 'pending_recipients' => 0, 'pending_configuration' => 0];
$recentRuns = $schemaCurrent >= Version::SCHEMA_TARGET ? $reports->all(8) : [];
$departmentRows = $schemaCurrent >= 2 ? $departments->all(true) : [];
$smtp = $settings->get('smtp', []);
$smtp = is_array($smtp) ? $smtp : [];
$signaturePreview = SignatureImage::resolve($smtp['signature_image'] ?? null, (string) Config::get('storage_private'));
$template = $settings->template();
$editingDepartment = $schemaCurrent >= 2 && isset($_GET['edit']) ? $departments->find((int) $_GET['edit']) : null;
$userRows = $schemaCurrent >= Version::SCHEMA_TARGET && Auth::isAdmin() ? $users->all() : [];
$securitySettings = $settings->get('security', []);
$securitySettings = is_array($securitySettings) ? $securitySettings : [];
$loginPolicy = [
    'max_login_attempts' => max(3, min(20, (int) ($securitySettings['max_login_attempts'] ?? 5))),
    'lockout_minutes' => max(1, min(1440, (int) ($securitySettings['lockout_minutes'] ?? 10))),
];
$editingUser = $schemaCurrent >= Version::SCHEMA_TARGET && Auth::isAdmin() && isset($_GET['edit_user']) ? $users->find((int) $_GET['edit_user']) : null;
$selectedRun = $schemaCurrent >= Version::SCHEMA_TARGET && isset($_GET['run']) ? $reports->findRun((string) $_GET['run']) : null;
$selectedFiles = $selectedRun ? $reports->departmentFiles((string) $selectedRun['id']) : [];
foreach ($selectedFiles as &$selectedFile) {
    $departmentId = (int) ($selectedFile['department_id'] ?? 0);
    $selectedFile['configured_recipients'] = $departmentId > 0 ? $departments->deliveryRecipients($departmentId) : [];
}
unset($selectedFile);
$selectedEvents = $selectedRun ? $reports->mailEvents((string) $selectedRun['id']) : [];

require __DIR__ . '/views/layout.php';
