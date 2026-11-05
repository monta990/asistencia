<?php
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;

$editing = is_array($editingDepartment) ? $editingDepartment : null;
$recipientLines = $editing ? implode("\n", array_map(static fn (array $r): string => (string) $r['email'], $editing['recipients'])) : '';
$defaultPosition = count($departmentRows) + 1;
?>
<div class="page-heading-row">
  <div>
    <div class="eyebrow">REGLAS DE DISTRIBUCIÓN</div>
    <h2>Departamentos y destinatarios</h2>
    <p class="muted">Relaciona los nombres del reporte ZKTimeNet con los departamentos internos y define a quién debe llegar cada Excel.</p>
  </div>
  <button type="button" class="btn btn-primary" data-department-create aria-haspopup="dialog" aria-controls="department-modal"><?= Html::icon('add') ?> Agregar departamento</button>
</div>

<section class="panel department-list-panel department-list-panel-wide">
  <div class="panel-header">
    <div><div class="eyebrow">DEPARTAMENTOS CONFIGURADOS</div><h3>Catálogo de distribución</h3></div>
    <span class="count-bubble"><?= count($departmentRows) ?></span>
  </div>
  <?php if ($departmentRows === []): ?>
    <div class="empty-state"><strong>No hay departamentos configurados</strong><p>Agrega un departamento para definir sus destinatarios.</p></div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>DEPARTAMENTO</th><th>RESPONSABLE</th><th>CORREOS</th><th>ESTADO</th><th>ACCIONES</th></tr></thead>
      <tbody>
      <?php foreach ($departmentRows as $department): ?>
        <tr>
          <td><strong><?= Html::escape($department['display_name']) ?></strong><small class="table-sub">ZKTimeNet: <?= Html::escape($department['report_label']) ?></small></td>
          <td><?= trim((string) ($department['responsible_email'] ?? '')) !== '' ? Html::escape($department['responsible_email']) : '<span class="text-muted">Sin responsable</span>' ?></td>
          <td><?php if ((int) $department['recipient_count'] > 0): ?><span class="delivery-count"><?= (int) $department['recipient_count'] ?> destinatario(s)</span><?php else: ?><span class="status-text-danger">Sin correos</span><?php endif; ?></td>
          <td>
            <?php if ((int) ($department['needs_configuration'] ?? 0) === 1): ?>
              <span class="status-pill status-warning">Pendiente de configuración</span>
            <?php elseif ((int) $department['enabled'] === 1): ?>
              <span class="status-pill status-success">Habilitado</span>
            <?php else: ?>
              <span class="status-pill status-muted">Deshabilitado · Excel se conserva</span>
            <?php endif; ?>
          </td>
          <td class="actions-cell"><div class="table-actions">
            <button type="button" class="btn btn-outline btn-small" data-department-edit aria-haspopup="dialog" aria-controls="department-modal"
              data-department-id="<?= (int) $department['id'] ?>"
              data-department-report-label="<?= Html::escape($department['report_label']) ?>"
              data-department-display-name="<?= Html::escape($department['display_name']) ?>"
              data-department-responsible-email="<?= Html::escape($department['responsible_email'] ?? '') ?>"
              data-department-recipients="<?= Html::escape($department['recipient_emails_json'] ?? '[]') ?>"
              data-department-position="<?= (int) $department['position'] ?>"
              data-department-enabled="<?= (int) $department['enabled'] ?>"
              data-department-needs-config="<?= (int) ($department['needs_configuration'] ?? 0) ?>"
              aria-label="Editar departamento <?= Html::escape($department['display_name']) ?>"><?= Html::icon('edit') ?> Editar</button>
            <form method="post" data-confirm-title="Eliminar departamento" data-confirm-accept="Eliminar" data-confirm-variant="danger" data-confirm="¿Eliminar este departamento y sus destinatarios? Los reportes históricos se mantienen.">
              <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
              <input type="hidden" name="action" value="delete_department">
              <input type="hidden" name="id" value="<?= (int) $department['id'] ?>">
              <button class="btn btn-danger-quiet btn-small" type="submit"><?= Html::icon('trash') ?> Eliminar</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <div class="table-footnote">Las etiquetas se comparan ignorando mayúsculas y espacios repetidos. Los departamentos deshabilitados siguen generando su Excel y solo se excluyen del correo.</div>
</section>

<div class="user-modal-backdrop department-modal-backdrop" id="department-modal" hidden aria-hidden="true" data-open-on-load="<?= $editing ? 'true' : 'false' ?>" data-default-position="<?= $defaultPosition ?>">
  <section class="user-modal department-modal" role="dialog" aria-modal="true" aria-labelledby="department-modal-title" tabindex="-1">
    <header class="user-modal-header">
      <div>
        <div class="eyebrow">CATÁLOGO Y DISTRIBUCIÓN</div>
        <h2 id="department-modal-title"><?= $editing ? 'Editar departamento' : 'Agregar departamento' ?></h2>
        <p><?= $editing ? 'Actualiza la etiqueta de origen, los datos del departamento y sus destinatarios.' : 'Registra el departamento y define quién recibirá su archivo de asistencia.' ?></p>
      </div>
      <span class="status-pill status-info" data-department-modal-status><?= $editing ? 'Edición' : 'Nuevo' ?></span>
      <button type="button" class="btn btn-outline btn-small user-modal-close" data-department-modal-close aria-label="Cerrar ventana"><?= Html::icon('close') ?></button>
    </header>

    <div class="alert alert-warning department-discovery-warning" data-department-discovery-warning <?= $editing && (int) ($editing['needs_configuration'] ?? 0) === 1 ? '' : 'hidden' ?>>Este departamento se detectó automáticamente en un reporte. Revisa su responsable y correos; al guardar quedará registrado como revisado.</div>

    <form method="post" class="stack-form user-modal-form" data-operation data-operation-title="Guardando departamento" data-operation-message="Estamos guardando el departamento y actualizando sus destinatarios." data-operation-detail="No cierres ni actualices esta ventana hasta que termine el proceso.">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_department">
      <input type="hidden" name="id" value="<?= Html::escape($editing['id'] ?? '') ?>" data-department-id-field>

      <div class="user-modal-fields department-modal-fields">
        <label>Etiqueta de departamento en ZKTimeNet
          <input type="text" name="report_label" required maxlength="160" value="<?= Html::escape($editing['report_label'] ?? '') ?>" placeholder="Ej. CAPITAL HUMANO" data-department-report-label>
          <small>Debe corresponder al nombre encontrado en la columna D de la fila Departamento.</small>
        </label>
        <label>Nombre para mostrar y nombrar el Excel
          <input type="text" name="display_name" required maxlength="120" value="<?= Html::escape($editing['display_name'] ?? '') ?>" placeholder="Ej. Capital Humano" data-department-display-name>
        </label>
        <label>Responsable del departamento
          <input type="email" name="responsible_email" maxlength="254" value="<?= Html::escape($editing['responsible_email'] ?? '') ?>" placeholder="responsable@empresa.com" data-department-responsible-email>
          <small>El responsable recibe siempre el Excel de su departamento, aunque no aparezca en Correos destinatarios. Su dirección se excluye únicamente de «Responder a».</small>
        </label>
        <label>Orden de presentación
          <input type="number" name="position" min="0" max="9999" value="<?= Html::escape($editing['position'] ?? $defaultPosition) ?>" data-department-position>
          <small>Determina el orden en el catálogo y en los reportes generados.</small>
        </label>
        <label class="user-modal-field-wide department-recipients-field">Correos destinatarios
          <textarea name="recipients" rows="5" placeholder="nominas@empresa.com&#10;gerencia.rh@empresa.com" data-department-recipients><?= Html::escape($recipientLines) ?></textarea>
          <small>Escribe un correo por línea. También se aceptan comas y punto y coma. Máximo 50 direcciones. El responsable también recibirá su Excel, con un solo mensaje por dirección.</small>
        </label>
        <label class="check-inline user-modal-field-wide department-enabled-field">
          <input type="checkbox" name="enabled" value="1" <?= !$editing || (int) $editing['enabled'] === 1 ? 'checked' : '' ?> data-department-enabled>
          Habilitado para envío de correo
          <small>Si lo deshabilitas, el sistema seguirá generando su Excel y lo incluirá en el ZIP; solamente omitirá el envío de correo.</small>
        </label>
      </div>

      <footer class="user-modal-footer">
        <button type="button" class="btn btn-outline" data-department-modal-close><?= Html::icon('cancel') ?> Cancelar</button>
        <button type="submit" class="btn btn-primary"><?= Html::icon('save') ?> <span data-department-submit-label><?= $editing ? 'Guardar cambios' : 'Agregar departamento' ?></span></button>
      </footer>
    </form>
  </section>
</div>
