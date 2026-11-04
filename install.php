<?php
declare(strict_types=1);

use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Database;
use ZkTimeNet\Core\Html;
use ZkTimeNet\Core\InstallationState;
use ZkTimeNet\Core\MigrationManager;
use ZkTimeNet\Core\Session;
use ZkTimeNet\Core\Version;

final class InstallerException extends RuntimeException {}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/vendor/autoload.php';

$baseConfig = require __DIR__ . '/config/app.php';
Config::load($baseConfig);
Session::start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; style-src 'self'; script-src 'self'");

$state = InstallationState::inspect((string) Config::get('database'));
if (!$state['readable']) {
    http_response_code(503);
    $fatalMessage = 'No se pudo leer la base de datos existente. No se modificó ningún archivo. Revisa el archivo SQLite y sus permisos antes de continuar.';
} elseif ($state['database_exists'] && (int) ($state['schema_version'] ?? 0) >= Version::USER_AUTH_SCHEMA) {
    Html::redirect('login.php');
} elseif ($state['database_exists'] && (int) ($state['schema_version'] ?? 0) === 0 && !$state['fresh_database']) {
    http_response_code(409);
    $fatalMessage = 'Se encontró una base de datos existente sin una versión de esquema reconocible. Por seguridad, el asistente no la sobrescribirá ni intentará inicializarla. Restaura un respaldo válido o revisa su historial de migraciones.';
} else {
    $fatalMessage = null;
}

$isLegacyUpgrade = $state['database_exists'] && (int) ($state['schema_version'] ?? 0) > 0;
$isResumableInstall = $state['database_exists'] && $state['fresh_database'];
$environmentDataRoot = (bool) Config::get('data_root_from_environment', false);
$defaultDataRoot = (string) Config::get('data_root');
$errors = [];
$submittedRoot = $defaultDataRoot;
$submittedName = 'Administrador';
$submittedPassword = '';
$submittedPasswordConfirmation = '';
$installationLock = null;

$runtimeChecks = [
    'PHP 8.5 o superior' => PHP_VERSION_ID >= Version::MINIMUM_PHP,
    'PDO_SQLite' => extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true),
    'ZIP' => extension_loaded('zip'),
    'DOM' => extension_loaded('dom'),
    'OpenSSL' => extension_loaded('openssl'),
];
$runtimeReady = !in_array(false, $runtimeChecks, true);

$serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
if (str_contains($serverSoftware, 'microsoft-iis') || str_contains($serverSoftware, 'iis')) {
    $serverType = 'Microsoft IIS';
} elseif (str_contains($serverSoftware, 'apache')) {
    $serverType = 'Apache';
} elseif (str_contains($serverSoftware, 'nginx')) {
    $serverType = 'Nginx';
} elseif (PHP_SAPI === 'cli') {
    $serverType = 'Línea de comandos';
} else {
    $serverType = $serverSoftware !== '' ? (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Servidor web') : 'Servidor web no identificado';
}
$serverOs = match (PHP_OS_FAMILY) {
    'Windows' => 'Windows',
    'Linux' => 'Linux',
    'Darwin' => 'macOS',
    default => PHP_OS_FAMILY,
};
$phpInterface = match (PHP_SAPI) {
    'cgi-fcgi' => 'FastCGI',
    'apache2handler' => 'Módulo de Apache',
    'fpm-fcgi' => 'PHP-FPM',
    'cli' => 'CLI',
    default => PHP_SAPI,
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $fatalMessage === null) {
    $submittedRoot = trim((string) ($_POST['data_root'] ?? $defaultDataRoot));
    $submittedName = trim((string) ($_POST['admin_name'] ?? 'Administrador'));
    $submittedPassword = (string) ($_POST['admin_password'] ?? '');
    $submittedPasswordConfirmation = (string) ($_POST['admin_password_confirmation'] ?? '');

    try {
        Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        if (!$runtimeReady) {
            throw new InstallerException('El servidor todavía no cumple todos los requisitos de PHP. Corrige los elementos marcados antes de continuar.');
        }
        if ($submittedName === '' || strlen($submittedName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $submittedName) === 1) {
            throw new InstallerException('Escribe el nombre del administrador (máximo 120 caracteres).');
        }
        if (strlen($submittedPassword) < 8 || strlen($submittedPassword) > 255) {
            throw new InstallerException('La contraseña debe tener al menos 8 caracteres y no superar 255 bytes.');
        }
        if (!hash_equals($submittedPassword, $submittedPasswordConfirmation)) {
            throw new InstallerException('La confirmación de la contraseña no coincide.');
        }

        if ($isLegacyUpgrade || $isResumableInstall) {
            // No se permite cambiar el almacenamiento al actualizar o reanudar una instalación parcial.
            $submittedRoot = $defaultDataRoot;
        } elseif ($environmentDataRoot) {
            if (!samePath($submittedRoot, $defaultDataRoot)) {
                throw new InstallerException('ZKTIME_DATA_DIR está definido en el servidor y controla la ubicación de datos. Cambia esa variable en IIS/PHP para elegir otra ruta.');
            }
            $submittedRoot = $defaultDataRoot;
        }

        $canonicalDataRoot = prepareDataRoot($submittedRoot, __DIR__, (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $installationLockPath = $canonicalDataRoot . DIRECTORY_SEPARATOR . '.zktime-install.lock';
        $installationLock = @fopen($installationLockPath, 'c');
        if ($installationLock === false) {
            $installationLock = null;
            throw new InstallerException('No se pudo abrir el bloqueo de instalación en la carpeta de datos.');
        }
        @chmod($installationLockPath, 0600);
        if (!flock($installationLock, LOCK_EX)) {
            fclose($installationLock);
            $installationLock = null;
            throw new InstallerException('No se pudo obtener el bloqueo exclusivo de instalación.');
        }
        if (!$isLegacyUpgrade && !$isResumableInstall) {
            $prospectiveDatabase = $canonicalDataRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'zktime.sqlite';
            $prospectiveState = InstallationState::inspect($prospectiveDatabase);
            if ($prospectiveState['database_exists']) {
                throw new InstallerException('La ruta seleccionada ya contiene una base de datos. No se sobrescribió ni se cambió la configuración; selecciona una carpeta vacía.');
            }
        }
        if (!$isLegacyUpgrade && !$isResumableInstall && !$environmentDataRoot) {
            persistDataRoot($canonicalDataRoot, __DIR__ . '/config/local.php');
        }

        // Recalcula las rutas después de persistir la ubicación. No se escribe ninguna credencial en config/local.php.
        $config = require __DIR__ . '/config/app.php';
        Config::load($config);
        foreach (['storage_private', 'storage_uploads', 'storage_exports', 'storage_backups', 'storage_sessions'] as $key) {
            ensureDirectory((string) Config::get($key));
        }
        $privateLogs = (string) Config::get('storage_private') . DIRECTORY_SEPARATOR . 'logs';
        ensureDirectory($privateLogs);

        $databasePath = (string) Config::get('database');
        $currentState = InstallationState::inspect($databasePath);
        if (!$isLegacyUpgrade && !$isResumableInstall && $currentState['database_exists']) {
            throw new InstallerException('La ruta seleccionada ya contiene una base de datos. No se sobrescribió; selecciona una carpeta vacía.');
        }
        if ($isLegacyUpgrade && (!$currentState['database_exists'] || (int) ($currentState['schema_version'] ?? 0) !== (int) $state['schema_version'])) {
            throw new InstallerException('La base de datos cambió mientras el asistente estaba abierto. Recarga la página y vuelve a revisar el estado.');
        }

        $private = (string) Config::get('storage_private');
        $keyPath = $private . DIRECTORY_SEPARATOR . 'app.key';
        if (is_file($keyPath)) {
            if ((int) filesize($keyPath) !== 32) {
                throw new InstallerException('La llave privada app.key existe pero no tiene 32 bytes. No se reemplazó para evitar perder acceso a los secretos cifrados.');
            }
        } elseif ($isLegacyUpgrade) {
            throw new InstallerException('La instalación existente no tiene app.key. Restaura la llave respaldada antes de actualizar; el asistente no generará una llave nueva.');
        } else {
            $keyHandle = @fopen($keyPath, 'xb');
            if ($keyHandle === false) {
                throw new InstallerException('No se pudo crear app.key. Revisa los permisos de escritura sobre el directorio privado.');
            }
            try {
                $key = random_bytes(32);
                if (fwrite($keyHandle, $key) !== 32) {
                    throw new InstallerException('No se pudo escribir la llave privada completa.');
                }
                fflush($keyHandle);
            } finally {
                fclose($keyHandle);
            }
            @chmod($keyPath, 0600);
        }

        $adminHash = password_hash($submittedPassword, PASSWORD_DEFAULT);
        if (!is_string($adminHash) || $adminHash === '') {
            throw new InstallerException('No se pudo proteger la contraseña del administrador.');
        }

        // La migración histórica 003 recibe el hash solamente en memoria durante esta petición.
        $config['admin_password_hash'] = $adminHash;
        Config::load($config);
        date_default_timezone_set((string) Config::get('timezone', 'America/Mexico_City'));
        $pdo = Database::connection();
        $migrator = new MigrationManager($pdo, __DIR__ . '/database/migrations');
        $migrationStatus = $migrator->status();
        if ($migrationStatus['future'] || $migrationStatus['drift'] !== []) {
            throw new InstallerException('La base de datos presenta una versión futura o inconsistencias de migración. No se aplicaron cambios.');
        }
        $migrator->migrate();

        $pdo->beginTransaction();
        try {
            $adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'administrador' COLLATE NOCASE LIMIT 1")->fetchColumn();
            if ($adminId < 1) {
                throw new InstallerException('Las migraciones terminaron pero no se encontró la cuenta administrativa inicial.');
            }
            $updateAdmin = $pdo->prepare("UPDATE users SET name = :name, password_hash = :password_hash, role = 'admin', is_active = 1 WHERE id = :id");
            $updateAdmin->execute(['name' => $submittedName, 'password_hash' => $adminHash, 'id' => $adminId]);
            if ($updateAdmin->rowCount() !== 1) {
                throw new InstallerException('No se pudo guardar la cuenta administrativa inicial.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $installationRecord = [
            'installed_at' => date(DATE_ATOM),
            'application_version' => Version::VERSION,
            'schema_version' => $migrator->currentVersion(),
            'mode' => $isLegacyUpgrade ? 'legacy-upgrade' : 'new-installation',
            'admin_username' => 'administrador',
        ];
        $recordPath = $private . DIRECTORY_SEPARATOR . 'installation.json';
        $recordJson = json_encode($installationRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($recordPath, $recordJson, LOCK_EX) === false) {
            throw new InstallerException('La cuenta fue creada, pero no se pudo escribir el registro de instalación. No vuelvas a ejecutar el asistente; intenta iniciar sesión.');
        }
        @chmod($recordPath, 0600);
        if (is_resource($installationLock)) {
            flock($installationLock, LOCK_UN);
            fclose($installationLock);
            $installationLock = null;
        }
        Html::redirect('login.php?installed=1');
    } catch (Throwable $exception) {
        if (is_resource($installationLock)) {
            flock($installationLock, LOCK_UN);
            fclose($installationLock);
            $installationLock = null;
        }
        $errors[] = publicInstallerError($exception);
    }
}

function samePath(string $left, string $right): bool
{
    $normalize = static function (string $path): string {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    };
    return $normalize($left) === $normalize($right);
}

function pathIsWithin(string $candidate, string $base): bool
{
    $normalize = static function (string $path): string {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    };
    $candidate = $normalize($candidate);
    $base = $normalize($base);
    return $candidate === $base || str_starts_with($candidate, $base . DIRECTORY_SEPARATOR);
}

function prepareDataRoot(string $path, string $applicationRoot, string $documentRoot): string
{
    $path = trim($path);
    if ($path === '' || strlen($path) > 512 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        throw new InstallerException('Escribe una ruta de datos válida (máximo 512 caracteres).');
    }
    $absolute = DIRECTORY_SEPARATOR === '\\'
        ? (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1)
        : str_starts_with($path, DIRECTORY_SEPARATOR);
    if (!$absolute) {
        throw new InstallerException('La ruta de datos debe ser absoluta; no se aceptan rutas relativas al sitio web.');
    }
    if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:[\\\\\/]?$/', $path) === 1) {
        throw new InstallerException('Selecciona una carpeta dentro de la unidad, no la raíz completa del disco.');
    }
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new InstallerException('No se pudo crear la ruta de datos indicada. Revisa la ruta y los permisos de la identidad del servidor web.');
    }
    $canonical = realpath($path);
    if ($canonical === false || !is_dir($canonical)) {
        throw new InstallerException('No se pudo resolver la ruta real del directorio de datos.');
    }
    $appReal = realpath($applicationRoot);
    if ($appReal !== false && pathIsWithin($canonical, $appReal)) {
        throw new InstallerException('Por seguridad, los datos deben quedar fuera de la carpeta de la aplicación.');
    }
    $documentReal = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($documentReal !== false && pathIsWithin($canonical, $documentReal)) {
        throw new InstallerException('Por seguridad, los datos no pueden quedar dentro del DocumentRoot público del servidor web.');
    }

    $probe = $canonical . DIRECTORY_SEPARATOR . '.zktime-write-check-' . bin2hex(random_bytes(6));
    $handle = @fopen($probe, 'xb');
    if ($handle === false) {
        throw new InstallerException('La identidad del servidor web no tiene permiso de escritura en la ruta seleccionada. Concede Modify/Escritura y vuelve a intentarlo.');
    }
    fclose($handle);
    @unlink($probe);
    return $canonical;
}

function persistDataRoot(string $dataRoot, string $configPath): void
{
    $directory = dirname($configPath);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new InstallerException('No se puede guardar la ubicación de datos. Concede temporalmente permiso de escritura a la identidad del servidor sobre la carpeta config; después de instalar, puedes retirarlo.');
    }
    $contents = "<?php\ndeclare(strict_types=1);\n\n// Generado por el asistente de instalación. Contiene únicamente la ruta de datos.\nreturn [\n    'data_root' => " . var_export($dataRoot, true) . ",\n];\n";
    $temporary = $configPath . '.tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new InstallerException('No se pudo guardar config/local.php. Revisa los permisos de la carpeta config.');
    }
    @chmod($temporary, 0600);
    $previousConfig = null;
    if (is_file($configPath)) {
        $previousConfig = $configPath . '.previous-' . bin2hex(random_bytes(4));
        if (!@rename($configPath, $previousConfig)) {
            @unlink($temporary);
            throw new InstallerException('No se pudo respaldar la configuración local anterior.');
        }
    }
    if (!@rename($temporary, $configPath)) {
        @unlink($temporary);
        if (is_string($previousConfig) && is_file($previousConfig)) {
            @rename($previousConfig, $configPath);
        }
        throw new InstallerException('No se pudo activar la configuración local de datos. No se modificó la base de datos.');
    }
    if (is_string($previousConfig) && is_file($previousConfig)) {
        @unlink($previousConfig);
    }
    @chmod($configPath, 0600);
}

function ensureDirectory(string $directory): void
{
    if ($directory === '') {
        throw new InstallerException('La configuración contiene una ruta de almacenamiento vacía.');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new InstallerException('No se pudo crear un directorio de almacenamiento. Revisa los permisos.');
    }
    if (!is_writable($directory)) {
        throw new InstallerException('El servidor web no tiene permisos de escritura en uno de los directorios de almacenamiento.');
    }
    @chmod($directory, 0700);
}

function publicInstallerError(Throwable $exception): string
{
    // Los mensajes de validación/configuración son intencionalmente operativos; no se imprimen trazas.
    return $exception instanceof InstallerException
        ? $exception->getMessage()
        : 'No se pudo completar la instalación. Revisa los permisos y el registro privado del servidor.';
}

$heading = $isLegacyUpgrade ? 'Preparar actualización de cuentas' : 'Configurar primera instalación';
$intro = $isLegacyUpgrade
    ? 'Se detectó una base de datos anterior al esquema de cuentas individuales. Se conservarán los datos y se creará un respaldo antes de actualizar. Para retirar la contraseña administrativa predefinida, establece una nueva contraseña para la cuenta administrador.'
    : 'Antes de iniciar, confirma dónde se guardarán los datos y crea las credenciales del primer administrador. La aplicación no incluye una contraseña administrativa predeterminada.';
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#006ECA"><meta name="robots" content="noindex,nofollow">
  <title>Instalación | Control de asistencia</title>
  <link rel="stylesheet" href="assets/app.css?v=1.2.19">
  <link rel="stylesheet" href="assets/theme.css?v=1.2.19">
  <link rel="stylesheet" href="assets/install.css?v=1.2.19">
  <script src="assets/theme.js?v=1.2.19"></script>
</head>
<body class="login-page install-page">
  <main class="install-shell">
    <header class="install-header">
      <div class="login-brand"><span class="brand-mark">CY</span><div><strong>CY ALIMENTOS</strong><small>Control de asistencia</small></div></div>
      <div class="install-meta"><span class="eyebrow">ASISTENTE DE CONFIGURACIÓN</span><span class="install-version">Versión <?= Html::escape(Version::VERSION) ?></span></div>
    </header>

    <section class="install-card">
      <div class="eyebrow"><?= $isLegacyUpgrade ? 'ACTUALIZACIÓN SEGURA' : 'BIENVENIDO' ?></div>
      <h1><?= Html::escape($heading) ?></h1>
      <p class="muted install-intro"><?= Html::escape($fatalMessage ?? $intro) ?></p>

      <?php if ($fatalMessage !== null): ?>
        <div class="alert alert-error"><?= Html::escape($fatalMessage) ?></div>
        <p class="install-footnote">No se realizaron cambios en la base de datos ni en las llaves existentes.</p>
      <?php else: ?>
        <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= Html::escape(implode(' ', $errors)) ?></div><?php endif; ?>
        <section class="install-environment" aria-labelledby="environment-heading">
          <div class="install-section-heading"><span class="install-step">1</span><div><h2 id="environment-heading">Entorno detectado</h2><p>Información obtenida directamente del servidor que ejecuta PHP.</p></div></div>
          <div class="install-facts">
            <div><small>Sistema operativo</small><strong><?= Html::escape($serverOs) ?></strong></div>
            <div><small>Servidor web</small><strong><?= Html::escape($serverType) ?></strong></div>
            <div><small>Interfaz PHP</small><strong><?= Html::escape($phpInterface) ?></strong></div>
            <div><small>Versión PHP</small><strong><?= Html::escape(PHP_VERSION) ?></strong></div>
          </div>
          <div class="install-checks">
            <?php foreach ($runtimeChecks as $label => $passed): ?>
              <span class="install-check <?= $passed ? 'is-ready' : 'is-missing' ?>"><b><?= $passed ? '✓' : '!' ?></b><?= Html::escape($label) ?></span>
            <?php endforeach; ?>
          </div>
        </section>

        <form method="post" class="install-form" autocomplete="off">
          <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
          <section class="install-section">
            <div class="install-section-heading"><span class="install-step">2</span><div><h2>Ubicación de los datos</h2><p>SQLite, llaves, reportes, respaldos, archivos temporales y sesiones se guardarán fuera del directorio público.</p></div></div>
            <label class="install-field">Carpeta de almacenamiento
              <input type="text" name="data_root" value="<?= Html::escape($submittedRoot) ?>" maxlength="512" <?= ($isLegacyUpgrade || $isResumableInstall || $environmentDataRoot) ? 'readonly' : '' ?> required>
            </label>
            <?php if ($environmentDataRoot): ?><p class="install-hint">La ruta está controlada por la variable de entorno <code>ZKTIME_DATA_DIR</code>; para cambiarla, modifícala en la configuración de IIS/PHP.</p>
            <?php elseif ($isLegacyUpgrade || $isResumableInstall): ?><p class="install-hint">La ruta se bloquea durante una actualización para impedir que la aplicación abra una base nueva por error.</p>
            <?php else: ?><p class="install-hint">La ruta debe ser absoluta, escribible y estar fuera de la carpeta del sitio web. En Windows se sugiere ProgramData; en Linux, /var/lib/zktimenet.</p><?php endif; ?>
          </section>

          <section class="install-section">
            <div class="install-section-heading"><span class="install-step">3</span><div><h2>Cuenta del administrador</h2><p>La contraseña se procesa en memoria y se guarda en SQLite únicamente como hash seguro.</p></div></div>
            <label class="install-field">Nombre que se mostrará
              <input type="text" name="admin_name" value="<?= Html::escape($submittedName) ?>" maxlength="120" autocomplete="name" required>
            </label>
            <label class="install-field">Nombre de usuario
              <input type="text" value="administrador" readonly aria-readonly="true">
              <small>Podrás cambiarlo después desde Administración → Usuarios.</small>
            </label>
            <div class="install-password-grid">
              <label class="install-field">Contraseña inicial
                <input type="password" name="admin_password" minlength="8" maxlength="255" autocomplete="new-password" required>
                <small>Mínimo 8 caracteres. Usa una contraseña única y difícil de adivinar.</small>
              </label>
              <label class="install-field">Confirmar contraseña
                <input type="password" name="admin_password_confirmation" minlength="8" maxlength="255" autocomplete="new-password" required>
              </label>
            </div>
          </section>

          <?php if (!$runtimeReady): ?><div class="alert alert-warning">No se puede continuar hasta habilitar los requisitos marcados arriba en el PHP que utiliza el servidor web.</div><?php endif; ?>
          <?php if ($isLegacyUpgrade): ?><div class="alert alert-warning">Antes de migrar se generará un respaldo automático de SQLite. La contraseña de administrador se establecerá con el valor que escribas aquí; los demás usuarios, reportes, departamentos y ajustes se conservarán.</div><?php else: ?><div class="alert alert-info">Al continuar se crearán la llave privada, la base SQLite, las migraciones y la cuenta administrador. La ruta seleccionada quedará guardada en <code>config/local.php</code>; después de instalar puedes retirar el permiso de escritura sobre la carpeta <code>config</code>.</div><?php endif; ?>
          <button class="btn btn-primary btn-wide" type="submit" <?= !$runtimeReady ? 'disabled' : '' ?>><?= $isLegacyUpgrade ? 'Respaldar y actualizar cuentas' : 'Instalar y crear administrador' ?></button>
        </form>
      <?php endif; ?>
    </section>
    <p class="install-footer">Configuración local · No se requiere Composer ni conexión a Internet</p>
  </main>
</body>
</html>
