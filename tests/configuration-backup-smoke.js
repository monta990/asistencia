'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const backup = read('src/Services/ConfigurationBackup.php');
const updates = read('views/pages/updates.php');
const index = read('index.php');
const layout = read('views/layout.php');
const css = read('assets/app.css');
const web = read('web.config');

for (const item of ['VACUUM INTO', 'PRAGMA integrity_check', 'hash_file(\'sha256\'', 'app.key', 'config/app.php', 'config/local.php', 'MAX_ARCHIVE_BYTES', 'MAX_EXPANDED_BYTES', 'safeArchivePath', 'applyRestore', 'cleanupStage']) {
  assert(backup.includes(item), `backup/restore control missing: ${item}`);
}
assert(backup.includes("$path === 'metadata/config-app.php'") && backup.includes("$path === 'metadata/config-local.php'"), 'application configuration references must be included in backup');
assert(backup.includes("$zip->getStream('manifest.json')") && backup.includes('5_242_880'), 'manifest must be read with a hard streaming size limit');
assert(backup.includes('MAX_EXPANDED_BYTES - $fileSize'), 'backup copy must enforce expanded-size limits before copying each file');
assert(backup.includes('copyDirectory($sourcePrivate, $targetPrivate, false)') && backup.includes('copyDirectory($sourceExports, $targetExports, false)'), 'restore must replace private data and permanent exports');
assert(backup.includes('copyLegacyRunSources') && backup.includes("SELECT id FROM report_runs") && backup.includes("'source.xlsx'"), 'full backups must retain legacy uploads that belong to report runs');
assert(backup.includes('The local data-root configuration is included for reference') || backup.includes('config/app.php and config/local.php are included as reference files'), 'manifest must make configuration reference-only behavior explicit');
assert(updates.includes('Carpeta raíz de la aplicación') && updates.includes('Carpeta raíz de datos') && updates.includes('$appConfigPath') && updates.includes('$localConfigPath'), 'updates page must show real paths');
assert(updates.includes('Descargar respaldo completo ZIP') && updates.includes('restore_config_backup'), 'updates page must offer both backup and restore');
assert(updates.includes('se sobrescribirán los datos actuales') && updates.includes('Se cerrará tu sesión'), 'restore page must warn about overwrite and logout');
assert(updates.includes('El ZIP no lleva contraseña propia'), 'backup confidentiality warning must be visible');
assert(index.includes('password_verify($confirmationPassword, $currentAdminHash)'), 'restore must require current administrator password');
assert(index.includes('MaintenanceGate::switchToExclusive(60)') && index.includes("ConfigurationBackup::createArchive($pdo, 'pre-restore')"), 'restore must take exclusive maintenance lock and create pre-restore backup');
assert(index.includes('session_write_close()') && index.includes('Session::start()'), 'backup/restore must release and reacquire PHP session locks around exclusive maintenance');
assert(index.includes("UPDATE users SET session_version = session_version + 1"), 'restore must invalidate every session from both current and restored data');
assert(index.includes('MaintenanceGate::release();') && index.includes('readfile($archivePath)'), 'download must release maintenance lock after the ZIP snapshot is complete');
assert(index.includes("header('Location: login.php?restored=1'"), 'successful restore must close session and return to login');
assert(layout.includes('<section class="page-content">') && css.includes('.page-content{width:100%;max-width:none;flex:1;margin:0;'), 'all authenticated pages must use the full available width');
assert(web.includes('maxAllowedContentLength="550502400"'), 'IIS request limit must permit a 512 MiB backup upload');
assert(index.includes("'download_config_backup'") && index.includes("Csrf::verify(isset($_POST['_token'])"), 'backup downloads must use POST plus CSRF');
console.log('OK: full configuration backup, validated restore, rollback, warnings, paths and wide Updates layout.');
