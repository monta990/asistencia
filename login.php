<?php
declare(strict_types=1);

use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Database;
use ZkTimeNet\Core\ErrorLogger;
use ZkTimeNet\Core\Html;
use ZkTimeNet\Core\MigrationManager;
use ZkTimeNet\Core\Version;
use ZkTimeNet\Repositories\SettingsRepository;
use ZkTimeNet\Services\BrandLogo;

if (!defined('ZKTIME_BOOTSTRAP_ALLOWED')) {
    define('ZKTIME_BOOTSTRAP_ALLOWED', true);
}
require __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
if (Auth::check() && Auth::refresh($pdo)) {
    Html::redirect('index.php');
}
$brandingDefaults = (array) Config::get('branding_defaults', []);
$brandingSaved = (new SettingsRepository($pdo))->get('branding', []);
$branding = array_replace($brandingDefaults, is_array($brandingSaved) ? $brandingSaved : []);
$logoFilename = is_array($branding['logo_image'] ?? null) ? (string) ($branding['logo_image']['filename'] ?? '') : '';
$brandLogoAvailable = BrandLogo::resolve($branding['logo_image'] ?? null, (string) Config::get('storage_private')) !== null;
$brandLogoUrl = 'index.php?action=brand_logo&v=' . substr(hash('sha256', $logoFilename), 0, 12);
$migrationStatus = (new MigrationManager($pdo, __DIR__ . '/database/migrations'))->status();
$legacyUpdatePending = $migrationStatus['current'] < Version::USER_AUTH_SCHEMA;
$systemUpdatePending = $migrationStatus['current'] >= Version::USER_AUTH_SCHEMA && $migrationStatus['current'] < Version::SCHEMA_TARGET;
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (\ZkTimeNet\Core\Session::isHttps()) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; style-src 'self'; script-src 'self'");

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::verify(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        if (Auth::attempt($pdo, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            Html::redirect('index.php');
        }
        $error = 'Usuario o contraseña incorrectos, o cuenta temporalmente bloqueada. Si el problema continúa, contacta al administrador.';
    } catch (Throwable $exception) {
        $error = ErrorLogger::publicMessage($exception, 'No fue posible completar el acceso. Inténtalo nuevamente.', 'login');
    }
}
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#006ECA"><title>Acceso | <?= Html::escape($branding['application_name']) ?></title>
  <script src="assets/theme.js?v=1.2.31"></script>
  <link rel="stylesheet" href="assets/app.css?v=1.2.31">
  <link rel="stylesheet" href="assets/theme.css?v=1.2.31">
  <script src="assets/app.js?v=1.2.31" defer></script>
</head>
<body class="login-page">
  <div class="theme-control theme-control-login" aria-label="Preferencia de apariencia">
    <span class="theme-control-label">Tema</span>
    <button class="btn btn-quiet btn-small theme-toggle" type="button" data-theme-toggle aria-label="Cambiar tema" title="Tema: sistema"><span class="theme-toggle-icon" data-theme-icon aria-hidden="true"><?= Html::icon('theme') ?></span><span data-theme-label>Sistema</span></button>
  </div>
  <main class="login-shell">
    <div class="login-brand"><?php if ($brandLogoAvailable): ?><img class="brand-logo-image" src="<?= Html::escape($brandLogoUrl) ?>" alt="Logotipo de <?= Html::escape($branding['company_name']) ?>"><?php else: ?><span class="brand-mark"><?= Html::escape($branding['brand_mark']) ?></span><?php endif; ?><div><strong><?= Html::escape($branding['company_name']) ?></strong><small><?= Html::escape($branding['brand_tagline']) ?></small></div></div>
    <section class="login-card">
      <div class="eyebrow"><?= Html::escape($branding['platform_label']) ?></div>
      <h1>Iniciar sesión</h1>
      <p class="muted">Ingresa tu nombre de usuario y contraseña para acceder a <?= Html::escape($branding['module_label']) ?>.</p>
      <?php if (($_GET['installed'] ?? '') === '1'): ?><div class="alert alert-success">La configuración terminó correctamente. Inicia sesión con la cuenta administrador que acabas de crear.</div><?php endif; ?>
      <?php if (($_GET['restored'] ?? '') === '1'): ?><div class="alert alert-success">El respaldo se restauró correctamente. Inicia sesión con las credenciales que estaban guardadas en la fecha de ese respaldo.</div><?php endif; ?>
      <?php if ($legacyUpdatePending): ?><div class="alert alert-warning">Esta actualización requiere preparar las cuentas de usuario. Continúa con el asistente de instalación para establecer la contraseña administrativa.</div><?php elseif ($systemUpdatePending): ?><div class="alert alert-warning">Hay una actualización pendiente. El administrador debe ingresar y aplicarla antes de que el personal pueda continuar.</div><?php endif; ?>
      <?php if ($error !== null): ?><div class="alert alert-error"><?= Html::escape($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
        <label>Nombre de usuario<input type="text" name="username" autocomplete="username" required autofocus maxlength="32"></label>
        <label>Contraseña<input type="password" name="password" autocomplete="current-password" required maxlength="255"></label>
        <button class="btn btn-primary btn-wide" type="submit"><?= Html::icon('login') ?> Entrar al sistema</button>
      </form>
    </section>
    <p class="login-foot"><?= Html::escape($branding['module_label']) ?></p>
  </main>
</body>
</html>
