<?php
declare(strict_types=1);

$environmentDataRoot = getenv('ZKTIME_DATA_DIR');
$environmentDataRoot = is_string($environmentDataRoot) ? trim($environmentDataRoot) : '';
$localConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
$localDataRoot = is_array($localConfig) ? (string) ($localConfig['data_root'] ?? '') : '';

if ($environmentDataRoot !== '') {
    // Una variable de entorno administrada por el servidor tiene prioridad sobre la configuración local.
    $dataRoot = $environmentDataRoot;
} elseif (trim($localDataRoot) !== '') {
    $dataRoot = trim($localDataRoot);
} elseif (PHP_OS_FAMILY === 'Windows') {
    $programData = getenv('ProgramData');
    if (!is_string($programData) || trim($programData) === '') {
        $programData = 'C:\\ProgramData';
    }
    $dataRoot = rtrim($programData, "\\/") . DIRECTORY_SEPARATOR . 'CYAlimentos' . DIRECTORY_SEPARATOR . 'ZKTimeNet';
} elseif (PHP_OS_FAMILY === 'Darwin') {
    $dataRoot = '/Library/Application Support/ZKTimeNet';
} else {
    $dataRoot = '/var/lib/zktimenet';
}

$dataRoot = rtrim($dataRoot, "\\/");
if ($dataRoot === '') {
    $dataRoot = DIRECTORY_SEPARATOR;
}

return [
    'name' => 'Control de asistencia',
    'company' => 'CY Alimentos',
    'branding_defaults' => [
        'company_name' => 'CY ALIMENTOS',
        'brand_mark' => 'CY',
        'brand_tagline' => 'Control de asistencia',
        'platform_label' => 'PLATAFORMA INTERNA',
        'module_label' => 'NÓMINAS',
        'application_name' => 'Control de asistencia',
        'footer_text' => 'Gestión automatizada de reportes ZKTimeNet',
    ],
    'timezone' => 'America/Mexico_City',
    'session_name' => 'ZKTIMESESSID120',
    'session_idle_timeout' => 1800,
    // La contraseña administrativa solo se recibe durante la instalación y se entrega a la migración en memoria.
    'max_upload_bytes' => 25 * 1024 * 1024,
    'export_retention_days' => 0, // 0 = report XLSX/ZIP permanent; deleted only by explicit storage maintenance.
    'temporary_upload_retention_days' => 1,
    'data_root' => $dataRoot,
    'data_root_from_environment' => $environmentDataRoot !== '',
    'database' => $dataRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'zktime.sqlite',
    'storage_private' => $dataRoot . DIRECTORY_SEPARATOR . 'private',
    'storage_uploads' => $dataRoot . DIRECTORY_SEPARATOR . 'uploads',
    'storage_exports' => $dataRoot . DIRECTORY_SEPARATOR . 'exports',
    'storage_backups' => $dataRoot . DIRECTORY_SEPARATOR . 'backups',
    'storage_sessions' => $dataRoot . DIRECTORY_SEPARATOR . 'sessions',
];
