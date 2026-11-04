<?php
use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;

$editing = is_array($editingUser) ? $editingUser : null;
$currentUserId = (int) (Auth::user()['id'] ?? 0);
$maxAttempts = (int) ($loginPolicy['max_login_attempts'] ?? 5);
$lockoutMinutes = (int) ($loginPolicy['lockout_minutes'] ?? 10);
?>
<div class="page-heading-row">
  <div>
    <div class="eyebrow">ACCESOS Y PERMISOS</div>
    <h2>Gestión de usuarios</h2>
    <p class="muted">Crea cuentas, asigna perfiles y administra la seguridad de acceso del personal.</p>
  </div>
  <?php if ($editing): ?><a class="btn btn-outline" href="index.php?page=users"><?= Html::icon('cancel') ?> Cancelar edición</a><?php endif; ?>
</div>
<div class="content-grid users-layout">
  <section class="panel users-form-panel">
    <div class="panel-header">
      <div><div class="eyebrow">CUENTA</div><h3><?= $editing ? 'Editar usuario' : 'Agregar usuario' ?></h3></div>
      <span class="status-pill status-info"><?= $editing ? 'Edición' : 'Nuevo' ?></span>
    </div>
    <form method="post" class="stack-form" autocomplete="off">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_user">
      <input type="hidden" name="id" value="<?= Html::escape($editing['id'] ?? '') ?>">
      <label>Nombre de la persona
        <input type="text" name="person_name" required maxlength="120" value="<?= Html::escape($editing['name'] ?? '') ?>" placeholder="Ej. María López">
        <small>Este nombre aparecerá en el historial como la persona que procesó el reporte.</small>
      </label>
      <label>Nombre de usuario
        <input type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,31}" autocomplete="off" value="<?= Html::escape($editing['username'] ?? '') ?>" placeholder="Ej. maria.lopez">
        <small>Se utiliza para iniciar sesión. No admite espacios.</small>
      </label>
      <label>Contraseña <?= $editing ? '(opcional al editar)' : '' ?>
        <input type="password" name="password" <?= $editing ? '' : 'required' ?> minlength="8" maxlength="255" autocomplete="new-password" placeholder="<?= $editing ? 'Vacío conserva la contraseña actual' : 'Mínimo 8 caracteres' ?>">
        <small><?= $editing ? 'Al establecer una nueva contraseña se desbloquea la cuenta e invalidan sus sesiones anteriores.' : 'Debe tener al menos 8 caracteres.' ?></small>
      </label>
      <label>Perfil de permisos
        <select name="role" required>
          <option value="nominista" <?= ($editing['role'] ?? 'nominista') === 'nominista' ? 'selected' : '' ?>>Nominista</option>
          <option value="admin" <?= ($editing['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador</option>
        </select>
        <small>Administrador: acceso completo. Nominista: Operación, Departamentos y su propia cuenta.</small>
      </label>
      <button type="submit" class="btn btn-primary btn-wide"><?= Html::icon($editing ? 'save' : 'user') ?> <?= $editing ? 'Guardar cambios' : 'Crear usuario' ?></button>
    </form>
  </section>

  <section class="panel users-list-panel">
    <div class="panel-header"><div><div class="eyebrow">PERSONAL AUTORIZADO</div><h3>Usuarios registrados</h3></div><span class="count-bubble"><?= count($userRows) ?></span></div>
    <?php if ($userRows === []): ?>
      <div class="empty-state"><strong>No hay usuarios registrados</strong><p>Agrega una cuenta para comenzar.</p></div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>PERSONA</th><th>USUARIO</th><th>PERFIL</th><th>ESTADO DE ACCESO</th><th>ACCIONES</th></tr></thead>
        <tbody>
        <?php foreach ($userRows as $row): ?>
          <?php $lockedTimestamp = strtotime((string) ($row['locked_until'] ?? '')) ?: 0; $isLocked = $lockedTimestamp > time(); ?>
          <tr>
            <td><strong><?= Html::escape($row['name']) ?></strong><?php if ((int) $row['id'] === $currentUserId): ?><small class="table-sub">Tu cuenta</small><?php endif; ?></td>
            <td><?= Html::escape($row['username']) ?></td>
            <td><span class="status-pill <?= $row['role'] === 'admin' ? 'status-info' : 'status-muted' ?>"><?= $row['role'] === 'admin' ? 'Administrador' : 'Nominista' ?></span></td>
            <td>
              <?php if ((int) $row['is_active'] !== 1): ?>
                <span class="status-pill status-warning">Inactivo</span>
              <?php elseif ($isLocked): ?>
                <span class="status-pill status-danger">Bloqueado</span>
                <small class="table-sub">Hasta <?= Html::escape(date('d/m/Y H:i', $lockedTimestamp)) ?> · Intentos: <?= (int) ($row['failed_login_attempts'] ?? 0) ?></small>
              <?php else: ?>
                <span class="status-pill status-success">Activo</span>
                <?php if ((int) ($row['failed_login_attempts'] ?? 0) > 0): ?><small class="table-sub">Intentos fallidos: <?= (int) $row['failed_login_attempts'] ?></small><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="actions-cell"><div class="table-actions">
              <a class="btn btn-outline btn-small" href="index.php?page=users&amp;edit_user=<?= (int) $row['id'] ?>"><?= Html::icon('edit') ?> Editar</a>
              <?php if ($isLocked): ?>
                <form method="post" class="inline-user-action" data-confirm="¿Desbloquear la cuenta de <?= Html::escape($row['name']) ?>? Esto restablecerá sus intentos e invalidará las sesiones anteriores.">
                  <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="unlock_user">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button type="submit" class="btn btn-small btn-primary"><?= Html::icon('lock') ?> Desbloquear</button>
                </form>
              <?php endif; ?>
              <?php if ((int) $row['id'] !== $currentUserId): ?>
                <form method="post" class="inline-user-action" data-confirm="<?= (int) $row['is_active'] === 1 ? '¿Deshabilitar este usuario? Ya no podrá iniciar sesión.' : '¿Habilitar este usuario?' ?>">
                  <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="set_user_active">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <input type="hidden" name="active" value="<?= (int) $row['is_active'] === 1 ? '0' : '1' ?>">
                  <button type="submit" class="btn btn-small <?= (int) $row['is_active'] === 1 ? 'btn-outline' : 'btn-primary' ?>"><?= Html::icon((int) $row['is_active'] === 1 ? 'toggle-off' : 'toggle-on') ?> <?= (int) $row['is_active'] === 1 ? 'Deshabilitar' : 'Habilitar' ?></button>
                </form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <div class="users-permission-help">
      <div><strong>Administrador</strong><p>Puede procesar reportes, consultar el historial, gestionar departamentos, SMTP, identidad visual, actualizaciones y usuarios.</p></div>
      <div><strong>Nominista</strong><p>Puede cargar reportes, consultar el resumen e historial, descargar Excel/ZIP, enviar reportes y administrar el catálogo de departamentos. Puede cambiar su propia contraseña desde su nombre en el encabezado. No puede modificar SMTP ni gestionar usuarios.</p></div>
    </div>
  </section>
</div>

<section class="panel user-security-panel">
  <div class="panel-header"><div><div class="eyebrow">PROTECCIÓN DE CUENTAS</div><h3>Política de bloqueo por intentos fallidos</h3></div><span class="status-pill status-info">Aplica a todos los usuarios</span></div>
  <p class="muted">Cuando una cuenta alcanza el número de intentos fallidos, se bloquea temporalmente. El bloqueo se aplica en el servidor y no se elimina al cerrar el navegador o cambiar de equipo.</p>
  <form method="post" class="login-policy-form">
    <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
    <input type="hidden" name="action" value="save_login_policy">
    <label>Intentos fallidos permitidos
      <input type="number" name="max_login_attempts" required min="3" max="20" step="1" value="<?= $maxAttempts ?>">
      <small>Al llegar a este número se bloquea la cuenta. Predeterminado: 5.</small>
    </label>
    <label>Minutos de bloqueo
      <input type="number" name="lockout_minutes" required min="1" max="1440" step="1" value="<?= $lockoutMinutes ?>">
      <small>Duración del bloqueo automático. Predeterminado: 10 minutos.</small>
    </label>
    <button type="submit" class="btn btn-primary"><?= Html::icon('save') ?> Guardar política</button>
  </form>
  <div class="info-note"><strong>Desbloqueo de cuentas</strong><p>Un administrador puede desbloquear desde esta lista a cualquier cuenta bloqueada, incluidos otros administradores. El desbloqueo restablece el contador y cierra las sesiones anteriores de esa cuenta. Si no hay otro administrador disponible, espera a que expire el bloqueo automático.</p></div>
</section>
