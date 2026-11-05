<?php
use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;
use ZkTimeNet\Core\Version;

$appRoot = dirname(__DIR__, 2);
$dataRoot = (string) Config::get('data_root', '');
$databasePath = (string) Config::get('database', '');
$privatePath = (string) Config::get('storage_private', '');
$exportsPath = (string) Config::get('storage_exports', '');
$backupsPath = (string) Config::get('storage_backups', '');
$appConfigPath = $appRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
$localConfigPath = $appRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'local.php';
$dataRootFromEnvironment = (bool) Config::get('data_root_from_environment', false);
$hasMigrationDrift = $migrationStatus['drift'] !== [] || (bool) $migrationStatus['future'];
$hasPendingMigrations = $migrationStatus['pending'] !== [];
$systemStatusClass = $hasMigrationDrift ? 'status-danger' : ($hasPendingMigrations ? 'status-warning' : 'status-success');
$systemStatusLabel = $hasMigrationDrift ? 'Revisión requerida' : ($hasPendingMigrations ? 'Actualización pendiente' : 'Sistema actualizado');
$requiredExtensions = ['pdo_sqlite', 'zip', 'dom', 'openssl'];
?>
<div class="updates-page">
  <div class="page-heading-row">
    <div>
      <div class="eyebrow">MANTENIMIENTO Y RECUPERACIÓN</div>
      <h2>Esquema, configuración y respaldos</h2>
      <p class="muted">Consulta el estado de la aplicación, identifica dónde se guardan sus archivos y administra las copias de seguridad.</p>
    </div>
    <span class="status-pill <?= $systemStatusClass ?>"><?= Html::escape($systemStatusLabel) ?></span>
  </div>

  <div class="updates-grid">
    <section class="panel update-panel">
      <div class="panel-header">
        <div><div class="eyebrow">CONTROL DE VERSIÓN</div><h3>Estado de la base de datos</h3></div>
        <span class="status-pill <?= $systemStatusClass ?>">Esquema <?= (int) $migrationStatus['current'] ?>/<?= (int) $migrationStatus['target'] ?></span>
      </div>

      <div class="run-summary update-summary">
        <div><small>Versión aplicación</small><strong>v<?= Html::escape(Version::VERSION) ?></strong></div>
        <div><small>Esquema actual</small><strong><?= (int) $migrationStatus['current'] ?></strong></div>
        <div><small>Esquema objetivo</small><strong><?= (int) $migrationStatus['target'] ?></strong></div>
        <div><small>Migraciones pendientes</small><strong><?= count($migrationStatus['pending']) ?></strong></div>
      </div>

      <?php if ($migrationStatus['drift'] !== []): ?>
        <div class="alert alert-error">Se detectaron inconsistencias en migraciones o metadatos: <?= Html::escape(implode(', ', $migrationStatus['drift'])) ?>. No ejecutes la actualización; restaura la versión original y crea una nueva migración.</div>
      <?php elseif ($migrationStatus['future']): ?>
        <div class="alert alert-error">La base de datos es más nueva que este código. Se detuvo el proceso para evitar degradación.</div>
      <?php elseif ($hasPendingMigrations): ?>
        <div class="alert alert-warning"><strong>Hay actualizaciones pendientes. Se creará un respaldo SQLite antes de ejecutar los cambios.</strong> La actualización es obligatoria para poder seguir usando la plataforma.</div>
        <form method="post" class="update-migration-form" data-operation data-operation-title="Aplicando actualizaciones" data-operation-message="Se está generando un respaldo y aplicando las migraciones pendientes." data-operation-detail="No cierres ni actualices esta ventana hasta que el sistema confirme que terminó.">
          <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
          <input type="hidden" name="action" value="run_migrations">
          <button type="submit" class="btn btn-primary"><?= Html::icon('refresh') ?> Aplicar actualizaciones</button>
        </form>
      <?php else: ?>
        <div class="success-state"><span>✓</span><div><strong>El esquema está actualizado.</strong><small>No hay migraciones pendientes ni cambios de checksum.</small></div></div>
      <?php endif; ?>

      <div class="update-guidance-grid">
        <div class="info-note"><strong>Regla de compatibilidad</strong><p>No edites archivos de migración que ya se hayan aplicado. Para ampliar el esquema, agrega una migración nueva con el siguiente número y conserva el checksum histórico.</p></div>
        <div class="info-note"><strong>Acceso y bloqueo de cuentas</strong><p>La política de intentos y duración se configura en <strong>Administración → Usuarios</strong>. Los valores iniciales son 5 intentos y 10 minutos; un administrador puede desbloquear otras cuentas, incluidos otros administradores.</p></div>
      </div>

      <div class="runtime-strip">
        <div class="runtime-strip-heading"><span class="eyebrow">ENTORNO DE EJECUCIÓN</span><span class="muted">Diagnóstico del servidor</span></div>
        <div class="technical-grid">
          <div><small>Plataforma</small><strong><?= Html::escape(PHP_OS_FAMILY) ?> · <?= Html::escape(PHP_SAPI) ?></strong></div>
          <div><small>Versión de PHP</small><strong><?= Html::escape(PHP_VERSION) ?></strong></div>
          <div><small>Extensiones requeridas</small><strong><?= implode(' · ', array_map(static fn (string $extension): string => extension_loaded($extension) ? $extension . ' ✓' : $extension . ' ✕', $requiredExtensions)) ?></strong></div>
        </div>
      </div>
    </section>

    <section class="panel backup-panel">
      <div class="panel-header">
        <div><div class="eyebrow">RESPALDO COMPLETO</div><h3>Ubicación real de los archivos</h3></div>
        <span class="status-pill status-success">Datos fuera del sitio web</span>
      </div>
      <p class="muted backup-intro">La configuración SMTP, los usuarios, departamentos, identidad visual y plantillas se guardan en SQLite. La llave <code>app.key</code> es indispensable para recuperar las contraseñas SMTP cifradas.</p>

      <div class="path-section">
        <div class="path-section-heading"><strong>Datos persistentes</strong><span>Respaldar juntos</span></div>
        <dl class="path-list">
          <div><dt>Carpeta raíz de datos</dt><dd><code><?= Html::escape($dataRoot) ?></code></dd></div>
          <div><dt>Base de datos SQLite</dt><dd><code><?= Html::escape($databasePath) ?></code></dd></div>
          <div><dt>Configuración privada y llave app.key</dt><dd><code><?= Html::escape($privatePath) ?></code></dd></div>
          <div><dt>Reportes Excel y ZIP permanentes</dt><dd><code><?= Html::escape($exportsPath) ?></code></dd></div>
          <div><dt>Respaldos automáticos y recuperación</dt><dd><code><?= Html::escape($backupsPath) ?></code></dd></div>
        </dl>
      </div>

      <div class="path-section path-section-app">
        <div class="path-section-heading"><strong>Archivos de configuración del sitio</strong><span>Parte de la instalación</span></div>
        <dl class="path-list">
          <div><dt>Carpeta raíz de la aplicación</dt><dd><code><?= Html::escape($appRoot) ?></code></dd></div>
          <div><dt>Configuración principal</dt><dd><code><?= Html::escape($appConfigPath) ?></code></dd></div>
          <div><dt>Archivo de ubicación local</dt><dd><code><?= Html::escape($localConfigPath) ?></code></dd></div>
        </dl>
      </div>

      <div class="info-note backup-manual-note">
        <strong>¿Qué debes respaldar manualmente?</strong>
        <p>Conserva la carpeta raíz de datos y el directorio de aplicación. <code>config/local.php</code> solo guarda la ruta cuando no se usa <code>ZKTIME_DATA_DIR</code>; no contiene contraseñas. Nunca separes SQLite de <code>app.key</code>.</p>
        <p><?= $dataRootFromEnvironment ? 'La ubicación de datos está controlada por ZKTIME_DATA_DIR configurada en IIS/PHP.' : (is_file($localConfigPath) ? 'La ubicación de datos se obtiene de config/local.php.' : 'Se utiliza la ruta predeterminada de la plataforma definida en config/app.php.') ?></p>
      </div>

      <form method="post" class="backup-download-form">
        <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
        <input type="hidden" name="action" value="download_config_backup">
        <button type="submit" class="btn btn-primary btn-large backup-download"><?= Html::icon('download') ?> Descargar respaldo completo ZIP</button>
      </form>
      <small class="muted backup-footnote">El ZIP se descarga con fecha, hora e identificador. Incluye SQLite, app.key, datos privados, firma de correo, reportes permanentes y archivos de configuración como referencia; excluye sesiones, temporales y logs. La copia temporal del servidor se elimina al completar la descarga.</small>
    </section>
  </div>

  <div class="alert alert-warning backup-confidentiality"><strong>Protege el archivo ZIP.</strong> Contiene la base de datos, hashes de contraseñas y la llave que descifra el secreto SMTP. El ZIP no lleva contraseña propia; guárdalo en una ubicación cifrada y compártelo únicamente con personal autorizado.</div>

  <section class="panel restore-panel">
    <div class="panel-header">
      <div><div class="eyebrow">RECUPERACIÓN</div><h3>Restaurar una copia de seguridad</h3></div>
      <span class="status-pill status-warning">Operación destructiva</span>
    </div>
    <div class="alert alert-warning"><strong>Atención: se sobrescribirán los datos actuales.</strong> Al restaurar, se reemplazarán SQLite, usuarios, contraseñas, SMTP, departamentos, identidad visual, firmas y reportes por los contenidos del ZIP. Se cerrará tu sesión y tendrás que ingresar con las credenciales que existían cuando se creó el respaldo.</div>

    <div class="restore-guidance-grid">
      <p class="muted">Antes de sustituir los archivos, el sistema valida el formato, versión, ruta de datos, checksums SHA-256, llave de cifrado e integridad de SQLite. También crea un respaldo automático del estado actual para poder revertir si la operación falla.</p>
      <div class="info-note restore-emergency-note"><strong>¿No puedes entrar al panel?</strong><p>La restauración requiere que SQLite permita autenticar a un administrador. Si la base está dañada, utiliza el procedimiento de recuperación manual de <code>INSTALL.md</code> con IIS detenido.</p></div>
    </div>

    <form method="post" enctype="multipart/form-data" class="restore-form" data-confirm-title="Restaurar respaldo" data-confirm-accept="Validar y restaurar" data-confirm-variant="danger" data-operation data-operation-title="Restaurando respaldo" data-operation-message="Se está validando el ZIP y sustituyendo los datos actuales por la copia seleccionada." data-operation-detail="El proceso crea una copia de seguridad de reversión. No cierres ni actualices esta ventana." data-confirm="ATENCIÓN: se sobrescribirán los datos actuales con los del respaldo. Se reemplazarán usuarios, contraseñas, configuración, SQLite y reportes; se cerrará tu sesión y deberás ingresar con las credenciales del respaldo. Antes se generará una copia automática de seguridad. ¿Deseas continuar?">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
      <input type="hidden" name="action" value="restore_config_backup">
      <label>Archivo ZIP de respaldo
        <input type="file" name="backup_file" accept=".zip,application/zip" required>
        <small>Máximo 512 MiB. Solo se aceptan respaldos creados por esta misma versión de ZKTimeNet y para esta misma ruta de datos.</small>
      </label>
      <label>Confirma tu contraseña de administrador
        <input type="password" name="confirm_password" autocomplete="current-password" required maxlength="255">
        <small>Se solicita nuevamente para autorizar la sustitución completa de los datos.</small>
      </label>
      <button type="submit" class="btn btn-danger"><?= Html::icon('refresh') ?> Validar y restaurar respaldo</button>
    </form>
  </section>
</div>
