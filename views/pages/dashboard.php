<?php
use ZkTimeNet\Core\Auth;
use ZkTimeNet\Core\Html;
?>
<?php
$isAdmin = Auth::isAdmin();
$setupComplete = (int) $stats['pending_recipients'] === 0 && (!$isAdmin || $mailer->isConfigured());
?>
<div class="page-heading-row">
  <div><div class="eyebrow">CENTRO DE OPERACIÓN</div><h2>Panel de control</h2><p class="muted">Carga el reporte general de ZKTimeNet, separa la información y distribuye cada archivo al departamento que corresponde.</p></div>
  <a class="btn btn-primary" href="index.php?page=upload"><?= Html::icon('process') ?> Procesar reporte</a>
</div>
<div class="metric-grid">
  <article class="metric-card"><div class="metric-icon blue">▤</div><div class="metric-label">Reportes procesados</div><div class="metric-value"><?= number_format((int) $stats['runs']) ?></div><div class="metric-note">Historial acumulado</div></article>
  <article class="metric-card"><div class="metric-icon red">▦</div><div class="metric-label">Departamentos activos</div><div class="metric-value"><?= number_format((int) $stats['departments']) ?></div><div class="metric-note">En el catálogo de distribución</div></article>
  <article class="metric-card"><div class="metric-icon green">✉</div><div class="metric-label">Archivos enviados</div><div class="metric-value"><?= number_format((int) $stats['sent']) ?></div><div class="metric-note">Entregas confirmadas por servidor de correo</div></article>
  <article class="metric-card"><div class="metric-icon amber">!</div><div class="metric-label">Sin destinatarios</div><div class="metric-value"><?= number_format((int) $stats['pending_recipients']) ?></div><div class="metric-note"><a href="index.php?page=departments">Completar configuración →</a></div></article>
</div>
<div class="content-grid dashboard-grid">
  <section class="panel dashboard-guide-panel" aria-labelledby="dashboard-guide-title">
    <div class="eyebrow">ANTES DE COMENZAR</div>
    <h3 id="dashboard-guide-title">Dos cosas que debes saber</h3>
    <div class="dashboard-guide-list">
      <article class="dashboard-guide-item">
        <span class="dashboard-guide-icon" aria-hidden="true"><?= Html::icon('excel') ?></span>
        <div>
          <strong>Archivo de Excel requerido (.xlsx)</strong>
          <p>Selecciona el archivo exportado desde ZKTimeNet que termine en <strong>.xlsx</strong>. No se pueden procesar archivos PDF, CSV ni el formato antiguo .xls.</p>
        </div>
      </article>
      <article class="dashboard-guide-item">
        <span class="dashboard-guide-icon" aria-hidden="true"><?= Html::icon('calendar') ?></span>
        <div>
          <strong>¿Cómo se calcula la semana?</strong>
          <p>El sistema obtiene el número de semana y el año a partir de las fechas del reporte. Usa el calendario ISO: cada semana empieza el lunes y termina el domingo. Por ejemplo, del 26 de octubre al 1 de noviembre de 2026 corresponde a la semana 44 de 2026.</p>
        </div>
      </article>
    </div>
  </section>
  <section class="panel setup-panel">
    <div class="panel-header"><div><div class="eyebrow">CONFIGURACIÓN</div><h3>Preparación del sistema</h3></div><span class="status-pill <?= $setupComplete ? 'status-success' : 'status-warning' ?>"><?= $setupComplete ? 'Configurado' : 'Pendiente' ?></span></div>
    <div class="setup-item"><span class="setup-check">✓</span><div><strong>Catálogo de departamentos</strong><small><?= (int) $stats['departments'] ?> departamentos registrados desde el reporte de muestra.</small></div><a href="index.php?page=departments">Abrir</a></div>
    <div class="setup-item"><span class="setup-check <?= $stats['pending_recipients'] > 0 ? 'is-pending' : '' ?>"><?= $stats['pending_recipients'] > 0 ? '!' : '✓' ?></span><div><strong>Destinatarios por departamento</strong><small><?= (int) $stats['pending_recipients'] ?> departamentos sin una lista de correo activa.</small></div><a href="index.php?page=departments">Configurar</a></div>
    <?php if ($isAdmin): ?>
      <div class="setup-item"><span class="setup-check <?= !$mailer->isConfigured() ? 'is-pending' : '' ?>"><?= $mailer->isConfigured() ? '✓' : '!' ?></span><div><strong>Conexión SMTP</strong><small><?= $mailer->isConfigured() ? 'Parámetros configurados.' : 'Configura el servidor para habilitar los envíos.' ?></small></div><a href="index.php?page=mail">Configurar</a></div>
      <div class="setup-item"><span class="setup-check">✓</span><div><strong>Base de datos local</strong><small>SQLite con migraciones controladas y respaldo previo a cambios.</small></div><a href="index.php?page=updates">Ver esquema</a></div>
    <?php endif; ?>
  </section>
</div>
<section class="panel recent-panel">
  <div class="panel-header"><div><div class="eyebrow">ACTIVIDAD</div><h3>Reportes recientes</h3></div><a class="text-link" href="index.php?page=history">Ver historial completo →</a></div>
  <?php if ($recentRuns === []): ?><div class="empty-state"><div class="empty-icon">▤</div><strong>Aún no hay reportes procesados</strong><p>Cuando se genere la primera ejecución aparecerá aquí.</p><a class="btn btn-primary btn-small" href="index.php?page=upload"><?= Html::icon('process') ?> Procesar primer reporte</a></div>
  <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>REPORTE</th><th>PERIODO</th><th>DEPARTAMENTOS</th><th>ENVÍOS</th><th>FECHA</th><th></th></tr></thead><tbody>
      <?php foreach ($recentRuns as $run): ?><tr><td><strong><?= Html::escape($run['original_filename']) ?></strong><small class="table-sub">ZIP: <?= Html::escape($run['archive_name']) ?></small></td><td>Semana <?= Html::escape($run['week']) ?> / <?= Html::escape($run['iso_year']) ?></td><td><?= (int) $run['departments_count'] ?></td><td><span class="delivery-count"><?= (int) $run['email_sent_count'] ?> enviados</span><?php if ((int) $run['email_failed_count']): ?><small class="status-text-danger"><?= (int) $run['email_failed_count'] ?> fallidos</small><?php endif; ?></td><td><?= Html::escape(date('d/m/Y H:i', strtotime((string) $run['created_at']))) ?></td><td><a class="btn btn-outline btn-small" href="index.php?page=history&run=<?= Html::escape($run['id']) ?>"><?= Html::icon('detail') ?> Detalle</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
