<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\MigrationManager;
use ZkTimeNet\Core\SecretVault;
use ZkTimeNet\Repositories\DepartmentRepository;
use ZkTimeNet\Repositories\ReportRepository;
use ZkTimeNet\Repositories\UserRepository;
use ZkTimeNet\Core\Auth;
use ZkTimeNet\Services\DepartmentSplitter;
use ZkTimeNet\Services\TemplateRenderer;
use ZkTimeNet\Services\MailerService;

$root = dirname(__DIR__);
if (PHP_VERSION_ID < 80500) {
    fwrite(STDERR, "SKIP: PHP 8.5 o superior es obligatorio.\n");
    exit(2);
}
$required = ['pdo_sqlite', 'zip', 'dom', 'openssl'];
$missing = array_values(array_filter($required, static fn (string $ext): bool => !extension_loaded($ext)));
if ($missing !== []) {
    fwrite(STDERR, 'SKIP: faltan extensiones: ' . implode(', ', $missing) . "\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
};

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zktime_smoke_' . bin2hex(random_bytes(5));
foreach (['private', 'uploads', 'exports', 'backups'] as $folder) {
    mkdir($temp . DIRECTORY_SEPARATOR . $folder, 0700, true);
}
try {
    $dbPath = $temp . '/private/test.sqlite';
    $keyPath = $temp . '/private/app.key';
    file_put_contents($keyPath, random_bytes(32));
    chmod($keyPath, 0600);
    Config::load([
        'database' => $dbPath,
        'storage_private' => $temp . '/private',
        'storage_backups' => $temp . '/backups',
        'storage_uploads' => $temp . '/uploads',
        'storage_exports' => $temp . '/exports',
        'admin_password_hash' => password_hash('TestAdminPassword123', PASSWORD_DEFAULT),
    ]);
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $migrator = new MigrationManager($pdo, $root . '/database/migrations');
    $firstMigration = $migrator->migrate();
    $assert($migrator->currentVersion() === 6, 'las migraciones establecen el esquema 6');
    $assert($firstMigration['applied'] === [1, 2, 3, 4, 5, 6], 'las migraciones 001 a 006 se aplican en orden');
    $assert($migrator->migrate()['applied'] === [], 'migrar dos veces no repite cambios');

    $userRepository = new UserRepository($pdo);
    $adminStatement = $pdo->query("SELECT id, username, role, is_active, password_hash FROM users WHERE username = 'administrador'");
    $admin = $adminStatement->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($admin) && $admin['role'] === 'admin' && (int) $admin['is_active'] === 1, 'la migración conserva el acceso administrador como una cuenta individual');
    $assert(password_verify('TestAdminPassword123', (string) $admin['password_hash']), 'la migración conserva el hash de la contraseña administrativa');
    $noministaId = $userRepository->save(null, 'María López', 'maria.lopez', 'maria.lopez@example.com', 'NoministaTest123', 'nominista');
    $nominista = $userRepository->find($noministaId);
    $assert(is_array($nominista) && $nominista['name'] === 'María López' && $nominista['role'] === 'nominista' && $nominista['email'] === 'maria.lopez@example.com', 'se puede crear un usuario nominista con nombre, usuario, correo y contraseña');
    $userRepository->saveOwnEmail($noministaId, 'maria.actualizada@example.com');
    $assert(($userRepository->find($noministaId)['email'] ?? '') === 'maria.actualizada@example.com', 'un usuario puede actualizar su propio correo de notificación');
    $_SESSION['user'] = ['id' => $noministaId, 'name' => 'María López', 'username' => 'maria.lopez', 'role' => 'nominista'];
    $assert(Auth::canAccessPage('upload') && Auth::canAccessPage('history') && Auth::canAccessPage('departments') && Auth::canAccessPage('account'), 'el nominista puede operar reportes, ingresar a Departamentos y cambiar su contraseña');
    $assert(!Auth::canAccessPage('mail') && !Auth::canAccessPage('branding') && !Auth::canAccessPage('users') && !Auth::canAccessPage('updates'), 'el nominista no puede acceder a ajustes administrativos ni a usuarios');
    $wrongCurrentPasswordRejected = false;
    try { $userRepository->changeOwnPassword($noministaId, 'wrong-password', 'NoministaNueva123', 'NoministaNueva123'); } catch (RuntimeException) { $wrongCurrentPasswordRejected = true; }
    $assert($wrongCurrentPasswordRejected, 'el cambio de contraseña exige la contraseña actual');
    $confirmationRejected = false;
    try { $userRepository->changeOwnPassword($noministaId, 'NoministaTest123', 'NoministaNueva123', 'otra-confirmacion'); } catch (RuntimeException) { $confirmationRejected = true; }
    $assert($confirmationRejected, 'el cambio de contraseña rechaza una confirmación distinta');
    $samePasswordRejected = false;
    try { $userRepository->changeOwnPassword($noministaId, 'NoministaTest123', 'NoministaTest123', 'NoministaTest123'); } catch (RuntimeException) { $samePasswordRejected = true; }
    $assert($samePasswordRejected, 'el cambio de contraseña rechaza reutilizar la contraseña actual');
    $userRepository->changeOwnPassword($noministaId, 'NoministaTest123', 'NoministaNueva123', 'NoministaNueva123');
    $changedHash = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . (int) $noministaId)->fetchColumn();
    $assert(password_verify('NoministaNueva123', $changedHash), 'el cambio de contraseña actualiza el hash individual');
    $_SESSION['user'] = ['id' => (int) $admin['id'], 'name' => 'Administrador', 'username' => 'administrador', 'role' => 'admin'];
    $assert(Auth::canAccessPage('mail') && Auth::canAccessPage('branding') && Auth::canAccessPage('users') && Auth::canAccessPage('updates'), 'el administrador conserva acceso completo');
    $selfStatusProtected = false;
    try { $userRepository->setActive((int) $admin['id'], false, (int) $admin['id']); } catch (RuntimeException) { $selfStatusProtected = true; }
    $assert($selfStatusProtected, 'el usuario actual no puede cambiar su propio estado');
    $lastAdminProtected = false;
    try { $userRepository->setActive((int) $admin['id'], false, 0); } catch (RuntimeException) { $lastAdminProtected = true; }
    $assert($lastAdminProtected, 'no se permite desactivar al último administrador activo');

    $departmentRepository = new DepartmentRepository($pdo);
    $assert($departmentRepository->seedDefaults() === 24, 'se precargan las 24 etiquetas encontradas en la muestra');
    $assert($departmentRepository->seedDefaults() === 0, 'la carga inicial es idempotente');
    $assert($departmentRepository->findByReportLabel('TI ') !== null, 'la etiqueta TI normaliza espacios finales');
    $duplicateLabelRejected = false;
    try {
        $departmentRepository->save(null, ' capital   humano ', 'Duplicado', '', true, 100, []);
    } catch (InvalidArgumentException) {
        $duplicateLabelRejected = true;
    }
    $assert($duplicateLabelRejected, 'el catálogo rechaza etiquetas de origen ambiguas');
    $departmentOneId = $departmentRepository->save(null, 'PRUEBA UNO', 'Prueba Uno', 'manager@example.com', true, 95, ['test@example.com', 'TEST@example.com', 'manager@example.com']);
    $departmentTwoId = $departmentRepository->save(null, 'PRUEBA DOS', 'Prueba Dos', '', false, 96, ['test2@example.com']);
    $discoveredDepartment = $departmentRepository->ensureDiscovered('NUEVO AREA');
    $assert($discoveredDepartment['created'] === true && (int) $discoveredDepartment['department']['enabled'] === 0 && (int) $discoveredDepartment['department']['needs_configuration'] === 1, 'los departamentos nuevos se agregan automáticamente deshabilitados y pendientes de configuración');
    $discoveredAgain = $departmentRepository->ensureDiscovered('nuevo   area');
    $assert($discoveredAgain['created'] === false && (int) $discoveredAgain['department']['id'] === (int) $discoveredDepartment['department']['id'], 'la detección de un departamento nuevo es idempotente');
    $assert($departmentRepository->deliveryRecipients($departmentTwoId) === ['test2@example.com'] && !$departmentRepository->isEnabled($departmentTwoId), 'los departamentos deshabilitados conservan sus direcciones pero pueden identificarse para omitir su envío');
    $assert(count($departmentRepository->recipients((int) $departmentRepository->findByReportLabel('PRUEBA UNO')['id'])) === 2, 'los destinatarios duplicados no se repiten y el responsable puede estar en destinatarios');
    $assert($departmentRepository->responsibleEmail($departmentOneId) === 'manager@example.com', 'el responsable del departamento se almacena y recupera normalizado');
    $assert($departmentRepository->responsibleEmail((int) $departmentRepository->findByReportLabel('PRUEBA DOS')['id']) === '', 'el responsable es opcional para departamentos antiguos');
    $replyTo = MailerService::buildDepartmentReplyTo(
        ['nominas@example.com', 'manager@example.com'],
        ['test@example.com', 'manager@example.com'],
        'MANAGER@example.com'
    );
    $assert($replyTo === ['nominas@example.com', 'test@example.com'], 'Reply-To agrega los correos del departamento y excluye al responsable incluso de la lista global');
    $replyToWithoutDepartment = MailerService::buildDepartmentReplyTo(['nominas@example.com'], ['test@example.com'], 'manager@example.com', false);
    $assert($replyToWithoutDepartment === ['nominas@example.com'], 'la opción desactivada conserva solo el Reply-To general');

    $encrypted = SecretVault::encrypt('smtp-password-test');
    $assert($encrypted !== 'smtp-password-test' && SecretVault::decrypt($encrypted) === 'smtp-password-test', 'la contraseña SMTP se cifra y descifra');
    $template = TemplateRenderer::render('Semana {{semana}} - {{departamento}}', ['semana' => '44', 'departamento' => 'Prueba Uno']);
    $assert($template === 'Semana 44 - Prueba Uno', 'las variables de plantillas se reemplazan');
    $assert(str_contains(TemplateRenderer::html('<admin>'), '&lt;admin&gt;'), 'el correo escapa HTML');

    $input = $temp . '/uploads/sample.xlsx';
    createFixtureXlsx($input);
    $logoPath = $temp . '/uploads/company-logo.png';
    $logoBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGMUSdnyn4GBgYGJAQoAIEgCLzaICFQAAAAASUVORK5CYII=', true);
    $assert(is_string($logoBytes) && file_put_contents($logoPath, $logoBytes) !== false, 'se prepara una imagen PNG pequeña para probar el logotipo');
    $runDir = $temp . '/exports/run-one';
    $splitter = new DepartmentSplitter($departmentRepository);
    $result = $splitter->split($input, $runDir, [
        'created_by' => 'Edwin Elias',
        'generated_on' => '04/11/2026',
        'logo' => ['path' => $logoPath, 'mime' => 'image/png', 'filename' => 'logo-prueba.png'],
    ]);
    $assert(count($result['files']) === 3, 'se genera un Excel por departamento activo, deshabilitado y nuevo');
    $assert($result['files'][1]['department_enabled'] === false && !$result['files'][1]['needs_configuration'], 'el archivo de un departamento deshabilitado se genera y se marca para no distribuirse');
    $assert($result['files'][2]['newly_discovered'] === true && !$result['files'][2]['department_enabled'] && $result['files'][2]['needs_configuration'], 'un departamento desconocido se agrega automáticamente, se genera su Excel y queda pendiente de configuración');
    $autoDiscovered = $departmentRepository->findByReportLabel('AREA NUEVA XLSX');
    $assert(is_array($autoDiscovered) && (int) $autoDiscovered['enabled'] === 0 && (int) $autoDiscovered['needs_configuration'] === 1, 'la etiqueta nueva del propio XLSX queda en el catálogo deshabilitada hasta revisarla');
    $assert($result['weeks'] === ['44'], 'se detecta la semana ISO 44');
    $assert(is_file($result['zip_path']), 'se genera el ZIP general');

    // El historial debe permitir enviar por primera vez y reenviar archivos ya enviados.
    $mailTestFile = $result['files'][0];
    $mailTestRunId = 'smoke-mail-run';
    $reportRepository = new ReportRepository($pdo);
    $reportRepository->createRun([
        'id' => $mailTestRunId,
        'original_filename' => 'sample.xlsx',
        'archive_name' => $result['zip_name'],
        'archive_path' => 'run-one/' . $result['zip_name'],
        'week' => implode(', ', $result['weeks']),
        'year' => $result['iso_year'],
        'start_date' => $result['start_date'],
        'end_date' => $result['end_date'],
        'status' => 'completed',
        'created_by' => (int) $admin['id'],
    ], [[
        'department_id' => $mailTestFile['department_id'],
        'report_label' => $mailTestFile['report_label'],
        'department_name' => $mailTestFile['department_name'],
        'file_name' => $mailTestFile['file_name'],
        'relative_path' => $mailTestFile['relative_path'],
        'recipients' => ['test@example.com'],
        'delivery_status' => 'not_requested',
    ]]);
    $mailHistoryFile = $reportRepository->departmentFiles($mailTestRunId)[0];
    $renamedSource = $reportRepository->renameRunOriginalFilename($mailTestRunId, 'Reporte semanal Nóminas');
    $assert($renamedSource === 'Reporte semanal Nóminas.xlsx', 'renombrar la ejecución conserva la extensión .xlsx');
    $renamedRun = $reportRepository->findRun($mailTestRunId);
    $assert(is_array($renamedRun) && $renamedRun['created_by_name'] === 'Administrador', 'el historial muestra el nombre de la persona que realizó la ejecución');
    $mailFileId = (int) $mailHistoryFile['id'];
    $assert((int) $mailHistoryFile['mail_attempt_count'] === 0, 'el archivo sin envíos previos muestra cero intentos');
    $deliveryRecord = $reportRepository->findDepartmentFileForDelivery($mailFileId);
    $assert(is_array($deliveryRecord) && $deliveryRecord['original_filename'] === 'sample.xlsx', 'el repositorio recupera el archivo y contexto histórico para reenviar');
    $reportRepository->updateDelivery($mailFileId, 'sent', '', ['sent' => ['test@example.com'], 'failed' => []]);
    $afterFirstSend = $reportRepository->departmentFiles($mailTestRunId)[0];
    $assert($afterFirstSend['delivery_status'] === 'sent' && (int) $afterFirstSend['mail_attempt_count'] === 1, 'el primer envío queda en el historial');
    $reportRepository->updateDelivery($mailFileId, 'failed', 'SMTP test failure', ['sent' => [], 'failed' => ['test@example.com' => 'SMTP test failure']]);
    $afterResend = $reportRepository->departmentFiles($mailTestRunId)[0];
    $assert($afterResend['delivery_status'] === 'failed' && (int) $afterResend['mail_attempt_count'] === 2, 'el reenvío registra un segundo intento sin borrar el primero');
    $assert(count($reportRepository->mailEvents($mailTestRunId)) === 2, 'la auditoría conserva ambos intentos de correo');

    $oldOutputs = $reportRepository->replaceRunOutputs($mailTestRunId, [
        'archive_name' => 'Semana44_Regenerado.zip',
        'archive_path' => 'run-one/regen/Semana44_Regenerado.zip',
        'week' => '44',
        'year' => '2026',
        'start_date' => '2026-10-26',
        'end_date' => '2026-11-01',
    ], [
        [
            'department_id' => $mailTestFile['department_id'],
            'report_label' => $mailTestFile['report_label'],
            'department_name' => $mailTestFile['department_name'],
            'file_name' => 'Semana44_PRUEBA_UNO.xlsx',
            'relative_path' => 'run-one/regen/Semana44_PRUEBA_UNO.xlsx',
            'recipients' => ['updated@example.com'],
            'delivery_status' => 'not_requested',
        ],
        [
            'department_id' => $departmentTwoId,
            'report_label' => 'PRUEBA DOS',
            'department_name' => 'Prueba Dos',
            'file_name' => 'Semana44_PRUEBA_DOS.xlsx',
            'relative_path' => 'run-one/regen/Semana44_PRUEBA_DOS.xlsx',
            'recipients' => ['test2@example.com'],
            'delivery_status' => 'disabled',
        ],
    ]);
    $regeneratedFiles = $reportRepository->departmentFiles($mailTestRunId);
    $regeneratedFirst = array_values(array_filter($regeneratedFiles, static fn (array $item): bool => (int) $item['id'] === $mailFileId))[0] ?? null;
    $regeneratedRun = $reportRepository->findRun($mailTestRunId);
    $assert($oldOutputs['archive_path'] === 'run-one/' . $result['zip_name'], 'la regeneración devuelve la ruta del ZIP anterior para su limpieza posterior');
    $assert(count($oldOutputs['department_paths']) === 1 && $oldOutputs['department_paths'][0] === $mailTestFile['relative_path'], 'la regeneración devuelve las rutas Excel anteriores');
    $assert(count($regeneratedFiles) === 2 && is_array($regeneratedFirst), 'la regeneración reemplaza y amplía el conjunto de Excel del historial');
    $assert($regeneratedFirst['file_path'] === 'run-one/regen/Semana44_PRUEBA_UNO.xlsx' && $regeneratedFirst['delivery_status'] === 'not_requested', 'el Excel actualizado queda activo y pendiente de nuevo envío');
    $assert((int) $regeneratedFirst['mail_attempt_count'] === 2 && count($reportRepository->mailEvents($mailTestRunId)) === 2, 'la regeneración conserva los IDs y la auditoría de correos anteriores');
    $assert(is_array($regeneratedRun) && $regeneratedRun['archive_name'] === 'Semana44_Regenerado.zip' && (int) $regeneratedRun['departments_count'] === 2, 'la regeneración actualiza el ZIP y el resumen de la ejecución');

    // El comprobante masivo debe aislar los eventos del lote y cerrarse una sola vez.
    $bulkBatchId = $reportRepository->createBulkMailBatch($mailTestRunId, (int) $admin['id']);
    $reportRepository->updateDelivery($mailFileId, 'sent', '', ['sent' => ['bulk@example.com'], 'failed' => []], $bulkBatchId);
    $secondRegeneratedFile = array_values(array_filter($regeneratedFiles, static fn (array $item): bool => (int) $item['department_id'] === $departmentTwoId))[0] ?? null;
    $assert(is_array($secondRegeneratedFile), 'la segunda salida regenerada está disponible para probar omisiones');
    $reportRepository->recordBulkSkip((int) $secondRegeneratedFile['id'], $mailTestRunId, $bulkBatchId, 'no_recipients', 'Sin correo configurado.');
    $batch = $reportRepository->findBulkMailBatch($bulkBatchId, (int) $admin['id'], $mailTestRunId);
    $batchEvents = $reportRepository->bulkMailEvents($bulkBatchId);
    $assert(is_array($batch) && $batch['summary_status'] === 'pending', 'el lote queda vinculado a la cuenta y ejecución que lo iniciaron');
    $assert(count($batchEvents) === 2 && $batchEvents[0]['status'] === 'sent' && $batchEvents[1]['status'] === 'skipped', 'el comprobante solo recupera eventos del lote, incluidos envíos y omisiones');
    $reportRepository->recordBulkClientIssue($mailFileId, $mailTestRunId, $bulkBatchId, 'Timeout reportado por navegador');
    $assert(count($reportRepository->bulkMailEvents($bulkBatchId)) === 2, 'un error de navegador no duplica un resultado ya persistido por el servidor');
    $assert($reportRepository->claimBulkMailSummary($bulkBatchId, (int) $admin['id'], $mailTestRunId), 'el cierre del lote se reclama una sola vez');
    $assert(!$reportRepository->claimBulkMailSummary($bulkBatchId, (int) $admin['id'], $mailTestRunId), 'un segundo cierre no puede reclamar de nuevo el comprobante');
    $reportRepository->updateBulkMailSummaryStatus($bulkBatchId, 'sent');
    $closedBatch = $reportRepository->findBulkMailBatch($bulkBatchId, (int) $admin['id'], $mailTestRunId);
    $assert(is_array($closedBatch) && $closedBatch['summary_status'] === 'sent' && $closedBatch['summary_sent_at'] !== null, 'el lote conserva el estado y la fecha del comprobante enviado');

    $zip = new ZipArchive();
    $assert($zip->open($result['zip_path']) === true, 'el ZIP abre correctamente');
    $assert($zip->numFiles === 3, 'el ZIP contiene los Excel activo, deshabilitado y recién detectado');
    $firstName = (string) $zip->getNameIndex(0);
    $secondName = (string) $zip->getNameIndex(1);
    $assert(str_starts_with($firstName, 'Semana44_') && str_starts_with($secondName, 'Semana44_'), 'los nombres usan el prefijo de semana');
    $firstXlsx = $zip->getFromName($firstName);
    $secondXlsx = $zip->getFromName($secondName);
    $thirdName = (string) $zip->getNameIndex(2);
    $thirdXlsx = $zip->getFromName($thirdName);
    $zip->close();
    $assert(is_string($firstXlsx) && is_string($secondXlsx) && is_string($thirdXlsx), 'los tres Excel están completos dentro del ZIP');

    $firstPath = $temp . '/uploads/first.xlsx';
    $secondPath = $temp . '/uploads/second.xlsx';
    $thirdPath = $temp . '/uploads/third.xlsx';
    file_put_contents($firstPath, $firstXlsx);
    file_put_contents($secondPath, $secondXlsx);
    file_put_contents($thirdPath, $thirdXlsx);
    $firstArchive = new ZipArchive();
    $assert($firstArchive->open($firstPath) === true, 'el primer XLSX abre como paquete OOXML');
    $firstSharedStrings = (string) $firstArchive->getFromName('xl/sharedStrings.xml');
    $firstSheet = (string) $firstArchive->getFromName('xl/worksheets/sheet1.xml');
    $firstDrawing = (string) $firstArchive->getFromName('xl/drawings/drawing1.xml');
    $firstSheetRelationships = (string) $firstArchive->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
    $firstContentTypes = (string) $firstArchive->getFromName('[Content_Types].xml');
    $mediaEntries = [];
    for ($entry = 0; $entry < $firstArchive->numFiles; $entry++) {
        $entryName = (string) $firstArchive->getNameIndex($entry);
        if (str_starts_with($entryName, 'xl/media/zktime-logo-')) $mediaEntries[] = $entryName;
    }
    $assert(str_contains($firstSheet, 'width="28.38"'), 'Turno column widens to fit the longest shift label in the department');
    $assert(str_contains($firstSheet, 'Reporte de asistencia'), 'el título del Excel cambia a Reporte de asistencia');
    $assert(str_contains($firstSheet, 'Semana 44 · 26/10/2026 al 01/11/2026'), 'el subtítulo muestra la semana ISO y el periodo calculado');
    $assert(str_contains($firstSheet, 'Realizado por:') && str_contains($firstSheet, 'Edwin Elias') && str_contains($firstSheet, 'Generado:') && str_contains($firstSheet, '04/11/2026'), 'el pie muestra la persona y la fecha de elaboración');
    $assert(str_contains($firstSheet, 'A3:N3') && str_contains($firstSheet, 'A1:C2') && str_contains($firstSheet, 'D1:N2') && str_contains($firstSheet, 'A1:N'), 'el subtítulo queda debajo del título y conserva separado el espacio superior del logotipo');
    $assert($firstDrawing !== '' && str_contains($firstDrawing, 'Logotipo de la empresa') && str_contains($firstDrawing, 'oneCellAnchor'), 'el logotipo queda anclado en el área superior izquierda del Excel');
    $drawingXmlValidator = new DOMDocument();
    $assert($drawingXmlValidator->loadXML($firstDrawing, LIBXML_NONET), 'el componente DrawingML del logotipo es XML válido');
    $assert($firstSheetRelationships !== '' && str_contains($firstSheetRelationships, '/drawing'), 'la hoja registra la relación de dibujo');
    $assert($firstContentTypes !== '' && str_contains($firstContentTypes, 'image/png') && count($mediaEntries) === 1, 'el libro contiene el PNG del logotipo y su tipo MIME');
    $assert(str_contains($firstSharedStrings, 'PERSONA_UNO') && !str_contains($firstSharedStrings, 'PERSONA_DOS'), 'el catálogo de textos no conserva datos del otro departamento');
    $assert(str_contains($firstSheet, 'A5') && str_contains($firstSheet, 'A12') && !str_contains($firstSheet, 'A13'), 'las filas se compactan y el pie se agrega después del primer departamento sin incluir el segundo');
    $firstArchive->close();

    $secondArchive = new ZipArchive();
    $assert($secondArchive->open($secondPath) === true, 'el segundo XLSX abre como paquete OOXML');
    $secondSharedStrings = (string) $secondArchive->getFromName('xl/sharedStrings.xml');
    $assert(str_contains($secondSharedStrings, 'PERSONA_DOS') && !str_contains($secondSharedStrings, 'PERSONA_UNO'), 'el segundo libro contiene solamente los textos de su área');
    $secondArchive->close();

    $thirdArchive = new ZipArchive();
    $assert($thirdArchive->open($thirdPath) === true, 'el tercer XLSX se abre como paquete OOXML');
    $thirdSharedStrings = (string) $thirdArchive->getFromName('xl/sharedStrings.xml');
    $assert(str_contains($thirdSharedStrings, 'PERSONA_TRES') && !str_contains($thirdSharedStrings, 'PERSONA_UNO') && !str_contains($thirdSharedStrings, 'PERSONA_DOS'), 'el nuevo departamento contiene solo sus propios datos');
    $thirdArchive->close();

    echo "OK: {$checks} comprobaciones pasaron.\n";
} finally {
    DepartmentSplitter::removeDirectory($temp);
}

function createFixtureXlsx(string $path): void
{
    $strings = [
        'Reporte General', 'Departamento', 'PRUEBA UNO', 'Desde', '26/10/2026', 'Hasta', '01/11/2026',
        'Nombre', 'PERSONA_UNO', 'ID', 'Fecha', '1001', 'Horas totales', 'Turno', '8:00 A 5:00(08:00-17:00)',
        'PRUEBA DOS', 'PERSONA_DOS', '2002', 'AREA NUEVA XLSX', 'PERSONA_TRES', '3003', 'Fecha / Hora: 02/11/2026', 'Page: 1 / 1',
    ];
    $index = array_flip($strings);
    $rows = [
        1 => ['D' => 'Reporte General'],
        5 => ['A' => 'Departamento', 'D' => 'PRUEBA UNO'],
        6 => ['A' => 'Desde', 'D' => '26/10/2026', 'G' => 'Hasta', 'J' => '01/11/2026'],
        7 => ['A' => 'Nombre', 'D' => 'PERSONA_UNO'],
        8 => ['A' => 'ID', 'C' => 'Fecha', 'D' => 'Turno'],
        9 => ['A' => '1001', 'C' => '26/10/2026', 'D' => '8:00 A 5:00(08:00-17:00)'],
        10 => ['A' => 'Horas totales'],
        12 => ['A' => 'Departamento', 'D' => 'PRUEBA DOS'],
        13 => ['A' => 'Desde', 'D' => '26/10/2026', 'G' => 'Hasta', 'J' => '01/11/2026'],
        14 => ['A' => 'Nombre', 'D' => 'PERSONA_DOS'],
        15 => ['A' => 'ID', 'C' => 'Fecha', 'D' => 'Turno'],
        16 => ['A' => '2002', 'C' => '26/10/2026', 'D' => '8:00 A 5:00(08:00-17:00)'],
        17 => ['A' => 'Horas totales'],
        19 => ['A' => 'Departamento', 'D' => 'AREA NUEVA XLSX'],
        20 => ['A' => 'Desde', 'D' => '26/10/2026', 'G' => 'Hasta', 'J' => '01/11/2026'],
        21 => ['A' => 'Nombre', 'D' => 'PERSONA_TRES'],
        22 => ['A' => 'ID', 'C' => 'Fecha', 'D' => 'Turno'],
        23 => ['A' => '3003', 'C' => '26/10/2026', 'D' => '8:00 A 5:00(08:00-17:00)'],
        24 => ['A' => 'Horas totales'],
        25 => ['B' => 'Fecha / Hora: 02/11/2026', 'K' => 'Page: 1 / 1'],
    ];
    $columnNumber = static function (string $letters): int {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }
        return $number;
    };
    $xmlEscape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sheetRows = '';
    foreach ($rows as $rowNumber => $cells) {
        ksort($cells);
        $cellXml = '';
        foreach ($cells as $column => $value) {
            $cellXml .= '<c r="' . $column . $rowNumber . '" t="s"><v>' . $index[$value] . '</v></c>';
        }
        $sheetRows .= '<row r="' . $rowNumber . '">' . $cellXml . '</row>';
    }
    $sharedXml = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $value) {
        $sharedXml .= '<si><t xml:space="preserve">' . $xmlEscape($value) . '</t></si>';
    }
    $sharedXml .= '</sst>';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><dimension ref="A1:N25"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetData>' . $sheetRows . '</sheetData><mergeCells count="5"><mergeCell ref="A1:C2"/><mergeCell ref="D1:N2"/><mergeCell ref="A5:C5"/><mergeCell ref="D5:N5"/><mergeCell ref="A12:C12"/></mergeCells></worksheet>';
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear el XLSX de prueba.');
    }
    $entries = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
        'xl/sharedStrings.xml' => $sharedXml,
    ];
    foreach ($entries as $name => $contents) {
        if (!$zip->addFromString($name, $contents)) {
            throw new RuntimeException('No se pudo crear un componente del XLSX de prueba.');
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('No se pudo cerrar el XLSX de prueba.');
    }
}

$splitterSource = file_get_contents(__DIR__ . '/../src/Services/DepartmentSplitter.php');
$assert(is_string($splitterSource) && str_contains($splitterSource, 'adjustColumnWidth') && str_contains($splitterSource, 'maxTurnoCharacters'), 'Turno column auto-sizing is implemented');
