<?php
use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;

$nav = [
    'upload' => ['Cargar reporte', 'upload', 'Operación'],
    'dashboard' => ['Resumen', 'home', 'Operación'],
    'history' => ['Historial', 'history', 'Operación'],
    'departments' => ['Departamentos', 'grid', 'Configuración'],
];
if (Auth::isAdmin()) {
    $nav['mail'] = ['Correo y plantillas', 'mail', 'Configuración'];
    $nav['branding'] = ['Identidad visual', 'palette', 'Configuración'];
    $nav['users'] = ['Usuarios', 'users', 'Administración'];
    $nav['updates'] = ['Actualizaciones', 'refresh', 'Sistema'];
}
$userName = (string) ($user['name'] ?? 'Usuario');
$initial = 'U';
if (preg_match('/^./us', $userName, $initialMatch) === 1) {
    $initial = strtoupper($initialMatch[0]);
}
$roleLabel = ($user['role'] ?? '') === 'admin' ? 'Administrador' : 'Nominista';
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#006ECA">
  <title><?= Html::escape($pageTitle) ?> | <?= Html::escape($branding['company_name']) ?></title>
  <script src="assets/theme.js?v=1.2.19"></script>
  <link rel="stylesheet" href="assets/app.css?v=1.2.19">
  <link rel="stylesheet" href="assets/theme.css?v=1.2.19">
  <script src="assets/app.js?v=1.2.19" defer></script>
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php" aria-label="Inicio">
      <span class="brand-mark"><?= Html::escape($branding['brand_mark']) ?></span>
      <span class="brand-copy"><strong><?= Html::escape($branding['company_name']) ?></strong><small><?= Html::escape($branding['brand_tagline']) ?></small></span>
    </a>
    <div class="side-caption"><?= Html::escape($branding['platform_label']) ?></div>
    <nav class="side-nav" aria-label="Navegación principal">
      <?php $section = ''; foreach ($nav as $key => [$label, $icon, $group]): ?>
        <?php if ($section !== $group): $section = $group; ?><div class="nav-group-label"><?= Html::escape($group) ?></div><?php endif; ?>
        <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= Html::escape($key) ?>">
          <span class="nav-icon" aria-hidden="true"><?= Html::icon($icon) ?></span><span><?= Html::escape($label) ?></span>
          <?php if ($key === 'departments' && ((int) ($stats['pending_recipients'] ?? 0) + (int) ($stats['pending_configuration'] ?? 0)) > 0): ?><span class="nav-count"><?= (int) ($stats['pending_recipients'] ?? 0) + (int) ($stats['pending_configuration'] ?? 0) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-help"><button type="button" data-help-open aria-haspopup="dialog" aria-controls="help-modal"><?= Html::icon('help') ?> Ayuda</button></div>
    <div class="sidebar-bottom">
      <span class="online-dot"></span><span>Servicio disponible</span>
      
    </div>
  </aside>

  <main class="main-area">
    <header class="topbar">
      <div class="topbar-left">
        <button class="mobile-menu" type="button" data-menu-toggle aria-label="Abrir menú"><?= Html::icon('menu') ?></button>
        <div><span class="top-eyebrow"><?= Html::escape($branding['company_name']) ?> / <?= Html::escape($branding['module_label']) ?></span><h1><?= Html::escape($pageTitle) ?></h1></div>
      </div>
      <div class="topbar-right">
        <div class="theme-control" aria-label="Preferencia de apariencia">
          <span class="theme-control-label">Tema</span>
          <button class="btn btn-quiet btn-small theme-toggle" type="button" data-theme-toggle aria-label="Cambiar tema" title="Tema: sistema">
            <span class="theme-toggle-icon" data-theme-icon aria-hidden="true"><?= Html::icon('theme') ?></span><span data-theme-label>Sistema</span>
          </button>
        </div>
        <a class="user-chip" href="index.php?page=account" title="Abrir mi cuenta y cambiar contraseña" aria-label="Mi cuenta: <?= Html::escape($userName) ?>. Cambiar contraseña"><span class="avatar"><?= Html::escape($initial) ?></span><span><strong><?= Html::escape($userName) ?></strong><small><?= Html::escape($user['username'] ?? '') ?> · <?= Html::escape($roleLabel) ?></small></span><span class="user-chip-caret" aria-hidden="true">⌄</span></a>
        <form method="post" action="logout.php" class="logout-form"><input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>"><button class="btn btn-quiet btn-small" type="submit"><?= Html::icon('logout') ?> Salir</button></form>
      </div>
    </header>

    <section class="page-content">
      <?php if ($flash !== null): ?><div class="alert alert-<?= Html::escape($flash['type']) ?>" role="status"><span><?= Html::escape($flash['message']) ?></span><button type="button" class="alert-close" data-dismiss-alert aria-label="Cerrar"><?= Html::icon('close') ?></button></div><?php endif; ?>
      <?php if ($migrationStatus['current'] < $migrationStatus['target'] && $page === 'updates'): ?>
        <?php require __DIR__ . '/pages/updates.php'; ?>
      <?php else: ?>
        <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
      <?php endif; ?>
    </section>
    <footer class="page-footer"><span>© <?= date('Y') ?> <?= Html::escape($branding['company_name']) ?></span><span><?= Html::escape($branding['footer_text']) ?></span></footer>
  </main>
</div>

<div class="help-modal-backdrop" id="help-modal" hidden aria-hidden="true">
  <section class="help-modal" role="dialog" aria-modal="true" aria-labelledby="help-modal-title" tabindex="-1">
    <header class="help-modal-header">
      <div><div class="eyebrow">GUÍA RÁPIDA</div><h2 id="help-modal-title">Ayuda de Control de asistencia</h2><p>Instrucciones sencillas para las tareas más comunes.</p></div>
      <button type="button" class="help-modal-close" data-help-close aria-label="Cerrar ayuda"><?= Html::icon('close') ?></button>
    </header>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <div class="help-role-label">Tu perfil: Administrador</div>
      <div class="help-topic"><h3>Procesar un reporte</h3><p>En <strong>Cargar reporte</strong>, selecciona el Excel general exportado desde ZKTimeNet y pulsa <strong>Procesar reporte</strong>. El sistema crea un Excel por departamento y un ZIP con todos los archivos.</p></div>
      <div class="help-topic"><h3>Revisar y enviar reportes</h3><p>En <strong>Historial</strong>, abre una ejecución para descargar el ZIP o un Excel individual. Puedes enviar un departamento o usar <strong>Enviar a todos</strong>. Los departamentos deshabilitados, pendientes o sin correo se conservan en el ZIP, pero no reciben correo.</p></div>
      <div class="help-topic"><h3>Configurar departamentos</h3><p>En <strong>Departamentos</strong>, revisa la etiqueta exacta de ZKTimeNet, el nombre, responsable y correos destinatarios. El responsable también recibe su Excel; su correo se excluye de «Responder a».</p></div>
      <div class="help-topic"><h3>Correo e identidad</h3><p>En <strong>Correo y plantillas</strong> configura SMTP, el asunto, mensaje, pie e imagen de firma. En <strong>Identidad visual</strong> puedes cambiar el nombre y los textos de la aplicación.</p></div>
      <div class="help-topic"><h3>Usuarios y actualizaciones</h3><p>En <strong>Usuarios</strong> crea cuentas y asigna Administrador o Nominista. Usa <strong>Actualizaciones</strong> para aplicar migraciones del sistema; respalda SQLite y la llave privada antes de actualizar.</p></div>
      <div class="help-topic"><h3>Tu cuenta</h3><p>Pulsa tu nombre en la parte superior para cambiar tu propia contraseña. No compartas tus credenciales.</p></div>
    <?php else: ?>
      <div class="help-role-label">Tu perfil: Nominista</div>
      <div class="help-topic"><h3>Procesar un reporte</h3><p>En <strong>Cargar reporte</strong>, selecciona el Excel general exportado desde ZKTimeNet y pulsa <strong>Procesar reporte</strong>. Espera el mensaje de confirmación antes de salir de la página.</p></div>
      <div class="help-topic"><h3>Consultar resultados</h3><p>En <strong>Resumen</strong> puedes revisar la actividad reciente. En <strong>Historial</strong> abre una ejecución para descargar el ZIP o los Excel individuales y consultar el resultado de los envíos.</p></div>
      <div class="help-topic"><h3>Administrar departamentos</h3><p>En <strong>Departamentos</strong> puedes actualizar responsable y correos destinatarios. El responsable recibe el Excel aunque no esté repetido en la lista de destinatarios.</p></div>
      <div class="help-topic"><h3>Departamentos nuevos o deshabilitados</h3><p>Si el reporte contiene un departamento nuevo, se agrega como pendiente y se incluye su Excel en el ZIP, pero no se envía hasta configurarlo y habilitarlo. Los departamentos deshabilitados también se conservan, sin envío de correo.</p></div>
      <div class="help-topic"><h3>Cambiar tu contraseña</h3><p>Pulsa tu nombre en la parte superior, abre <strong>Mi cuenta</strong> y registra tu contraseña actual y la nueva. Si tienes problemas de acceso, solicita apoyo al Administrador.</p></div>
    <?php endif; ?>
    <footer class="help-modal-footer"><span>Si tienes dudas que no aparecen aquí, consulta con el Administrador.</span><button type="button" class="btn btn-primary" data-help-close><?= Html::icon('check') ?> Entendido</button></footer>
  </section>
</div>

</body>
</html>
