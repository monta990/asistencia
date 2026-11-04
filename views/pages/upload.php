<?php
use ZkTimeNet\Core\Config;
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;
$maxMb = (int) Config::get('max_upload_bytes', 26214400) / 1024 / 1024;
?>
<div class="page-heading-row"><div><div class="eyebrow">NUEVA EJECUCIÓN</div><h2>Procesar reporte de asistencia</h2><p class="muted">Selecciona el archivo de Excel que te entregó el sistema de asistencia y pulsa Procesar reporte.</p></div><a href="index.php?page=history" class="btn btn-outline"><?= Html::icon('history') ?> Ver historial</a></div>
<?php
$activeDepartments = array_values(array_filter($departmentRows, static fn (array $department): bool => (int) ($department['enabled'] ?? 0) === 1 && (int) ($department['needs_configuration'] ?? 0) === 0));
$missingResponsibleCount = count(array_filter($activeDepartments, static fn (array $department): bool => trim((string) ($department['responsible_email'] ?? '')) === ''));
$missingDeliveryCount = count(array_filter($activeDepartments, static fn (array $department): bool => (int) ($department['recipient_count'] ?? 0) === 0));
$pendingConfigurationCount = count(array_filter($departmentRows, static fn (array $department): bool => (int) ($department['needs_configuration'] ?? 0) === 1));
$disabledDepartmentCount = count(array_filter($departmentRows, static fn (array $department): bool => (int) ($department['enabled'] ?? 0) === 0 && (int) ($department['needs_configuration'] ?? 0) === 0));
?>
<?php if ($missingResponsibleCount > 0 || $missingDeliveryCount > 0 || $pendingConfigurationCount > 0 || $disabledDepartmentCount > 0): ?>
  <div class="alert alert-warning department-readiness-alert" role="status">
    <strong>Revisión de departamentos.</strong>
    <?php if ($pendingConfigurationCount > 0): ?>Hay <?= $pendingConfigurationCount ?> departamento(s) nuevo(s) detectado(s) pendiente(s) de configuración. Sus Excel se generarán y quedarán en el ZIP, pero no se enviarán hasta que los revises y habilites.<?php endif; ?>
    <?php if ($missingResponsibleCount > 0): ?> <?= $pendingConfigurationCount > 0 ? 'Además, ' : 'Prioridad: ' ?>asigna correo al responsable de <?= $missingResponsibleCount ?> departamento(s) habilitado(s). El responsable siempre recibirá el Excel de su área.<?php endif; ?>
    <?php if ($missingDeliveryCount > 0): ?> <?= $missingDeliveryCount ?> departamento(s) habilitado(s) no tienen correo efectivo configurado y no recibirán su Excel.<?php endif; ?>
    <?php if ($disabledDepartmentCount > 0): ?> <?= $disabledDepartmentCount ?> departamento(s) están deshabilitados para correo; su información seguirá procesándose y sus Excel se conservarán, pero no se enviarán.<?php endif; ?>
    <a href="index.php?page=departments"><?= Html::icon('configure') ?> Revisar departamentos</a>.
  </div>
<?php endif; ?>
<div class="content-grid upload-grid">
  <section class="panel upload-panel">
    <div class="step-heading"><span class="step-number">01</span><div><h3>Archivo de origen</h3><p>Reporte exportado desde ZKTimeNet en formato XLSX.</p></div></div>
    <form method="post" enctype="multipart/form-data" class="upload-form">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="process_report">
      <label class="dropzone" for="report-file"><span class="upload-cloud"><?= Html::icon('upload') ?></span><strong>Selecciona tu archivo de asistencia</strong><span class="muted">Archivo Excel (.xlsx) · máximo <?= number_format($maxMb, 0) ?> MB</span><span class="file-selected" data-file-name>Ningún archivo seleccionado</span></label>
      <input class="visually-hidden" id="report-file" type="file" name="report" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <div class="upload-actions"><button type="submit" class="btn btn-primary btn-large" data-submit-loading><?= Html::icon('process') ?> Procesar reporte</button></div>
    </form>
  </section>
  <aside class="panel side-info-panel">
    <div class="eyebrow">INSTRUCCIONES</div><h3>¿Qué tienes que hacer?</h3>
    <ol class="process-list"><li><span>1</span><div><strong>Selecciona el archivo</strong><small>Busca el Excel que descargaste del sistema de asistencia.</small></div></li><li><span>2</span><div><strong>Pulsa Procesar reporte</strong><small>El sistema separará la información automáticamente.</small></div></li><li><span>3</span><div><strong>Descarga tus archivos</strong><small>Al terminar, abre el historial para descargar los archivos de Excel por departamento, un ZIP con todos o enviarlos a los responsables de departamento, uno por uno o de manera masiva.</small></div></li></ol>
    <div class="info-note"><strong>¿Necesitas enviar los reportes?</strong><p>Abre el historial y selecciona el envío por correo. Solo se enviarán los departamentos que tengan direcciones configuradas.</p></div>
  </aside>
</div>
