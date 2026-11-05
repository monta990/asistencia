<?php
use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;

$currentUser = is_array($accountUser ?? null) ? $accountUser : (Auth::user() ?? []);
$roleLabel = ($currentUser['role'] ?? '') === 'admin' ? 'Administrador' : 'Nominista';
?>
<div class="page-heading-row">
  <div><div class="eyebrow">MI PERFIL</div><h2>Mi cuenta</h2><p class="muted">Administra tu correo de notificación y cambia tu contraseña de acceso.</p></div>
</div>
<div class="content-grid account-grid">
  <section class="panel account-summary-panel">
    <div class="panel-header"><div><div class="eyebrow">USUARIO ACTUAL</div><h3>Datos de la cuenta</h3></div><span class="status-pill status-success">Activa</span></div>
    <dl class="account-details">
      <div><dt>Nombre de la persona</dt><dd><?= Html::escape($currentUser['name'] ?? '') ?></dd></div>
      <div><dt>Nombre de usuario</dt><dd><?= Html::escape($currentUser['username'] ?? '') ?></dd></div>
      <div><dt>Correo electrónico</dt><dd><?= trim((string) ($currentUser['email'] ?? '')) !== '' ? Html::escape($currentUser['email']) : 'Sin configurar' ?></dd></div>
      <div><dt>Perfil</dt><dd><?= Html::escape($roleLabel) ?></dd></div>
    </dl>
    <form method="post" class="stack-form" autocomplete="off">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_own_email">
      <label>Correo para comprobantes de envío masivo
        <input type="email" name="email" maxlength="254" autocomplete="email" value="<?= Html::escape($currentUser['email'] ?? '') ?>" placeholder="nombre@empresa.com">
        <small>Al finalizar un envío masivo, recibirás aquí un resumen con los correos enviados, los fallidos y sus motivos. Déjalo vacío para no recibirlo.</small>
      </label>
      <button type="submit" class="btn btn-primary btn-wide"><?= Html::icon('save') ?> Guardar correo</button>
    </form>
    <div class="info-note"><strong>¿Necesitas cambiar tu contraseña?</strong><p>Usa el formulario. Por seguridad te pediremos tu contraseña actual.</p></div>
  </section>
  <section class="panel account-password-panel">
    <div class="panel-header"><div><div class="eyebrow">SEGURIDAD</div><h3>Cambiar contraseña</h3></div></div>
    <form method="post" class="stack-form" autocomplete="off">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
      <input type="hidden" name="action" value="change_own_password">
      <label>Contraseña actual
        <input type="password" name="current_password" required maxlength="255" autocomplete="current-password">
      </label>
      <label>Nueva contraseña
        <input type="password" name="new_password" required minlength="8" maxlength="255" autocomplete="new-password">
        <small>Debe tener al menos 8 caracteres y ser diferente de la actual.</small>
      </label>
      <label>Confirmar nueva contraseña
        <input type="password" name="confirm_password" required minlength="8" maxlength="255" autocomplete="new-password">
      </label>
      <button type="submit" class="btn btn-primary btn-wide"><?= Html::icon('lock') ?> Guardar nueva contraseña</button>
    </form>
  </section>
</div>
