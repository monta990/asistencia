<?php
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;
?>
<div class="page-heading-row"><div><div class="eyebrow">TRAZABILIDAD</div><h2>Historial de ejecuciones</h2><p class="muted">Consulta archivos generados, periodo procesado y resultado del envío a cada departamento.</p></div><a class="btn btn-primary" href="index.php?page=upload"><?= Html::icon('add') ?> Nuevo reporte</a></div>
<?php if ($selectedRun): ?>
  <?php
    $bulkFiles = [];
    foreach ($selectedFiles as $bulkFile) {
        $bulkRecipients = is_array($bulkFile['configured_recipients'] ?? null) ? $bulkFile['configured_recipients'] : [];
        if ($bulkRecipients !== [] && (int) ($bulkFile['department_enabled'] ?? 0) === 1 && (int) ($bulkFile['needs_configuration'] ?? 0) === 0) {
            $bulkFiles[] = ['id' => (int) $bulkFile['id'], 'department' => (string) $bulkFile['department_name']];
        }
    }
    $bulkFilesJson = json_encode($bulkFiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
  ?>
  <?php $sourceFilename = (string) $selectedRun['original_filename']; $sourceBaseName = pathinfo($sourceFilename, PATHINFO_FILENAME); ?>
  <section class="panel run-detail-panel"><div class="panel-header run-header-main"><div class="run-source-meta"><div class="eyebrow">GENERADO DEL ARCHIVO</div><div class="run-filename-line"><h3><?= Html::escape($sourceFilename) ?></h3><button type="button" class="filename-edit-button" data-filename-edit-toggle title="Cambiar nombre del archivo" aria-label="Cambiar nombre del archivo"><?= Html::icon('edit') ?></button></div>
    <form method="post" class="run-filename-edit" data-filename-edit-form hidden>
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="rename_run_original_filename"><input type="hidden" name="run_id" value="<?= Html::escape($selectedRun['id']) ?>">
      <label class="visually-hidden" for="run-filename-base">Nombre del archivo</label><input id="run-filename-base" type="text" name="filename_base" required maxlength="120" value="<?= Html::escape($sourceBaseName) ?>"><span class="filename-extension">.xlsx</span>
      <button type="submit" class="btn btn-primary btn-small"><?= Html::icon('save') ?> Guardar</button><button type="button" class="btn btn-outline btn-small" data-filename-edit-cancel><?= Html::icon('cancel') ?> Cancelar</button>
    </form>
    <p class="run-created-by">Realizado por: <strong><?= Html::escape($selectedRun['created_by_name'] ?? 'Usuario anterior') ?></strong></p>
    <p class="muted">Semana <?= Html::escape($selectedRun['week']) ?> · <?= Html::escape(date('d/m/Y', strtotime((string) $selectedRun['start_date']))) ?> al <?= Html::escape(date('d/m/Y', strtotime((string) $selectedRun['end_date']))) ?></p></div><div class="run-header-actions"><?php if ($bulkFiles !== []): ?><button type="button" class="btn btn-bulk-send" data-bulk-send data-run-id="<?= Html::escape($selectedRun['id']) ?>" data-token="<?= Html::escape(Csrf::token()) ?>" data-files="<?= Html::escape($bulkFilesJson) ?>"><?= Html::icon('mail') ?> Enviar correo a todos (<?= count($bulkFiles) ?>)</button><?php else: ?><span class="muted small">No hay departamentos habilitados con correo configurado.</span><?php endif; ?><a class="btn btn-primary" href="index.php?action=download&id=<?= Html::escape($selectedRun['id']) ?>"><?= Html::icon('zip') ?> Descargar ZIP</a></div></div>
    <div class="run-summary"><div><small>Archivos Excel</small><strong><?= (int) $selectedRun['departments_count'] ?></strong></div><div><small>Enviados</small><strong class="text-success"><?= (int) $selectedRun['email_sent_count'] ?></strong></div><div><small>Fallidos</small><strong class="<?= (int) $selectedRun['email_failed_count'] ? 'text-danger' : '' ?>"><?= (int) $selectedRun['email_failed_count'] ?></strong></div><div><small>Generado</small><strong><?= Html::escape(date('d/m/Y H:i', strtotime((string) $selectedRun['created_at']))) ?></strong></div></div>
    <div class="table-wrap"><table><thead><tr><th>DEPARTAMENTO</th><th>DESTINATARIOS</th><th>ESTADO DEL ENVÍO</th><th>ACCIONES</th></tr></thead><tbody><?php foreach ($selectedFiles as $file): $rcpts = is_array($file['configured_recipients'] ?? null) ? $file['configured_recipients'] : []; $attempts = (int) ($file['mail_attempt_count'] ?? 0); $departmentEnabled = (int) ($file['department_enabled'] ?? 0) === 1; $needsConfiguration = (int) ($file['needs_configuration'] ?? 0) === 1; $wasDelivered = in_array((string) $file['delivery_status'], ['sent', 'failed'], true); ?><tr><td><strong><?= Html::escape($file['department_name']) ?></strong><small class="table-sub"><?= Html::escape($file['file_name']) ?></small></td><td><?= $rcpts === [] ? '<span class="muted">Sin correo</span>' : Html::escape(implode(', ', $rcpts)) ?></td><td><?php if ($needsConfiguration): ?><span class="status-pill status-warning">Pendiente de configuración</span><?php elseif (!$departmentEnabled && !$wasDelivered): ?><span class="status-pill status-muted">Deshabilitado · correo omitido</span><?php else: ?><span class="status-pill <?= match ($file['delivery_status']) { 'sent' => 'status-success', 'failed' => 'status-danger', 'no_recipients' => 'status-warning', default => 'status-muted' } ?>"><?= Html::escape(match ($file['delivery_status']) { 'sent' => 'Enviado', 'failed' => 'Fallido', 'no_recipients' => 'Sin correo', 'disabled' => 'Deshabilitado · correo omitido', 'not_requested' => 'No enviado', default => 'Pendiente' }) ?></span><?php endif; ?><?php if ($file['delivery_error'] !== ''): ?><small class="table-sub status-text-danger"><?= Html::escape($file['delivery_error']) ?></small><?php endif; ?></td><td class="actions-cell"><div class="table-actions">
          <a class="btn btn-outline btn-small" href="index.php?action=download_file&amp;id=<?= (int) $file['id'] ?>"><?= Html::icon('excel') ?> Excel</a>
          <?php if ($needsConfiguration): ?>
            <a class="btn btn-outline btn-small" href="index.php?page=departments&amp;edit=<?= (int) $file['department_id'] ?>"><?= Html::icon('configure') ?> Configurar</a>
          <?php elseif (!$departmentEnabled): ?>
            <span class="muted small no-mail-label">Deshabilitado</span>
          <?php elseif ($rcpts === []): ?>
            <span class="muted small no-mail-label">Sin correo</span>
          <?php else: ?>
            <form method="post" action="index.php?page=history&amp;run=<?= Html::escape($selectedRun['id']) ?>" class="inline-mail-action">
              <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
              <input type="hidden" name="action" value="resend_department_file">
              <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
              <button class="btn <?= $attempts > 0 ? 'btn-outline' : 'btn-primary' ?> btn-small" type="submit"><?= Html::icon($attempts > 0 ? 'resend' : 'mail') ?> <?= $attempts > 0 ? 'Reenviar correo' : 'Enviar correo' ?></button>
            </form>
          <?php endif; ?>
        </div></td></tr><?php endforeach; ?></tbody></table></div>
    <?php if ($selectedEvents !== []): ?>
      <div class="mail-events-section"><div class="panel-header"><div><div class="eyebrow">AUDITORÍA DE ENTREGA</div><h3>Resultado por destinatario</h3></div></div>
        <div class="table-wrap"><table><thead><tr><th>DEPARTAMENTO</th><th>ENVIADOS</th><th>FALLIDOS</th><th>RESULTADO</th></tr></thead><tbody>
        <?php foreach ($selectedEvents as $event): $outcome = json_decode((string) $event['recipients'], true); $outcome = is_array($outcome) ? $outcome : []; $sentEmails = is_array($outcome['sent'] ?? null) ? $outcome['sent'] : []; $failedEmails = is_array($outcome['failed'] ?? null) ? $outcome['failed'] : []; ?>
          <tr><td><strong><?= Html::escape($event['department_name'] ?? 'Departamento') ?></strong><small class="table-sub"><?= Html::escape(date('d/m/Y H:i', strtotime((string) $event['created_at']))) ?></small></td>
          <td><?= $sentEmails === [] ? '—' : Html::escape(implode(', ', $sentEmails)) ?></td>
          <td><?php if ($failedEmails === []): ?>—<?php else: ?><?php foreach ($failedEmails as $email => $error): ?><div class="status-text-danger"><?= Html::escape((string) $email) ?><small class="table-sub"><?= Html::escape((string) $error) ?></small></div><?php endforeach; ?><?php endif; ?></td>
          <td><span class="status-pill <?= $event['status'] === 'sent' ? 'status-success' : 'status-danger' ?>"><?= $event['status'] === 'sent' ? 'Todos enviados' : 'Con fallos' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      </div>
    <?php endif; ?>
    <div class="detail-foot"><a class="text-link" href="index.php?page=history"><?= Html::icon('back') ?> Volver al historial</a><span class="muted small">Los Excel y ZIP se conservan permanentemente. No se eliminan automáticamente.</span></div>
  </section>
<?php endif; ?>
<?php if ($selectedRun && $bulkFiles !== []): ?>
<div class="bulk-modal-backdrop" id="bulk-mail-modal" hidden aria-hidden="true">
  <section class="bulk-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-mail-title">
    <div class="bulk-modal-header"><div class="eyebrow">DISTRIBUCIÓN MANUAL</div><div class="bulk-modal-title-row"><h3 id="bulk-mail-title">Enviar reporte a todos los departamentos</h3><button type="button" class="bulk-modal-x" data-bulk-close aria-label="Cerrar" disabled><?= Html::icon('close') ?></button></div><p class="muted">Cada departamento recibirá su propio archivo de Excel. El ZIP general no se adjuntará.</p></div>
    <div class="bulk-progress-head"><strong data-bulk-progress-text>0 de <?= count($bulkFiles) ?> departamentos procesados</strong><span data-bulk-progress-count>0%</span></div>
    <div class="bulk-progress-track"><div class="bulk-progress-bar" data-bulk-progress-bar></div></div>
    <div class="bulk-summary" data-bulk-summary>Listo para iniciar. Solo se enviarán departamentos habilitados con correos configurados; los nuevos y deshabilitados quedan excluidos.</div>
    <div class="bulk-results-list" data-bulk-results aria-live="polite"></div>
    <div class="bulk-modal-footer"><button type="button" class="btn btn-outline" data-bulk-close><?= Html::icon('close') ?> Cerrar</button><button type="button" class="btn btn-primary" data-bulk-start><?= Html::icon('send') ?> Iniciar envío</button><button type="button" class="btn btn-primary" data-bulk-refresh hidden><?= Html::icon('refresh') ?> Actualizar historial</button></div>
  </section>
</div>
<?php endif; ?>

<?php if (!$selectedRun): ?>
<section class="panel history-panel"><div class="panel-header"><div><div class="eyebrow">EJECUCIONES RECIENTES</div><h3>Todos los reportes</h3></div><span class="count-bubble"><?= count($recentRuns) ?></span></div>
<?php if ($recentRuns === []): ?><div class="empty-state"><div class="empty-icon">◷</div><strong>No hay ejecuciones registradas</strong><p>Los reportes procesados aparecerán en este historial.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>ARCHIVO DE ORIGEN</th><th>SEMANA / PERIODO</th><th>ARCHIVOS</th><th>ENVÍO</th><th>FECHA</th><th></th></tr></thead><tbody><?php foreach ($recentRuns as $run): ?><tr><td><strong><?= Html::escape($run['original_filename']) ?></strong><small class="table-sub"><?= Html::escape($run['archive_name']) ?></small></td><td>Semana <?= Html::escape($run['week']) ?><small class="table-sub"><?= Html::escape(date('d/m/Y', strtotime((string) $run['start_date']))) ?> – <?= Html::escape(date('d/m/Y', strtotime((string) $run['end_date']))) ?></small></td><td><?= (int) $run['departments_count'] ?></td><td><span class="delivery-count"><?= (int) $run['email_sent_count'] ?> enviados</span><?php if ((int) $run['email_failed_count'] > 0): ?><small class="status-text-danger"><?= (int) $run['email_failed_count'] ?> fallidos</small><?php endif; ?></td><td><?= Html::escape(date('d/m/Y H:i', strtotime((string) $run['created_at']))) ?></td><td class="actions-cell"><div class="table-actions"><a class="btn btn-outline btn-small" href="index.php?page=history&run=<?= Html::escape($run['id']) ?>"><?= Html::icon('detail') ?> Detalle</a><a class="btn btn-primary btn-small" href="index.php?action=download&id=<?= Html::escape($run['id']) ?>" aria-label="Descargar ZIP"><?= Html::icon('zip') ?> ZIP</a></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
