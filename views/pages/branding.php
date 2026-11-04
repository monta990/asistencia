<?php
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;
?>
<div class="page-heading-row">
  <div>
    <div class="eyebrow">CONFIGURACIÓN DEL SISTEMA</div>
    <h2>Identidad visual</h2>
    <p class="muted">Personaliza el nombre, logotipo de texto y etiquetas visibles en el acceso, navegación, encabezados, pie de página y mensajes de correo.</p>
  </div>
</div>
<div class="content-grid branding-layout">
  <section class="panel branding-form-panel">
    <form method="post" class="stack-form">
      <input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_branding">
      <div class="panel-header">
        <div><div class="eyebrow">MARCA</div><h3>Datos de identidad</h3></div>
        <span class="status-pill status-info">Editable</span>
      </div>
      <div class="form-grid two-cols">
        <label class="span-2">Nombre de la empresa
          <input type="text" name="company_name" required maxlength="100" value="<?= Html::escape($branding['company_name']) ?>" placeholder="Nombre de la empresa">
        </label>
        <label>Texto del logotipo / siglas
          <input type="text" name="brand_mark" required maxlength="8" value="<?= Html::escape($branding['brand_mark']) ?>" placeholder="ABC">
          <small>Se muestra dentro del cuadro de marca. Admite hasta 8 caracteres.</small>
        </label>
        <label>Lema o descripción
          <input type="text" name="brand_tagline" required maxlength="120" value="<?= Html::escape($branding['brand_tagline']) ?>" placeholder="Descripción corta">
        </label>
        <label>Etiqueta de plataforma
          <input type="text" name="platform_label" required maxlength="80" value="<?= Html::escape($branding['platform_label']) ?>" placeholder="PLATAFORMA INTERNA">
          <small>Se muestra arriba del menú y en el acceso.</small>
        </label>
        <label>Nombre del módulo
          <input type="text" name="module_label" required maxlength="80" value="<?= Html::escape($branding['module_label']) ?>" placeholder="NÓMINAS">
          <small>Aparece junto al nombre de la empresa en el encabezado.</small>
        </label>
        <label class="span-2">Nombre de la aplicación
          <input type="text" name="application_name" required maxlength="100" value="<?= Html::escape($branding['application_name']) ?>" placeholder="Control de asistencia">
          <small>Se utiliza en el título de las pestañas y como contexto de los mensajes de correo.</small>
        </label>
        <label class="span-2">Texto del pie de página
          <input type="text" name="footer_text" required maxlength="160" value="<?= Html::escape($branding['footer_text']) ?>" placeholder="Gestión automatizada de reportes">
        </label>
      </div>
      <div class="branding-preview-wrap">
        <div class="eyebrow">VISTA PREVIA</div>
        <div class="branding-preview">
          <span class="brand-mark"><?= Html::escape($branding['brand_mark']) ?></span>
          <span><strong><?= Html::escape($branding['company_name']) ?></strong><small><?= Html::escape($branding['brand_tagline']) ?></small></span>
          <span class="branding-preview-platform"><?= Html::escape($branding['platform_label']) ?></span>
        </div>
        <p class="muted small">La vista previa muestra cómo aparecerá la marca en el menú lateral. Los cambios se aplican en la siguiente página que abras.</p>
      </div>
      <div class="form-footer">
        <span class="muted">Los cambios se guardan en SQLite y sobreviven a las actualizaciones.</span>
        <button type="submit" class="btn btn-primary"><?= Html::icon('save') ?> Guardar identidad visual</button>
      </div>
    </form>
  </section>
  <aside class="panel branding-help-panel">
    <div class="eyebrow">PERSONALIZACIÓN</div>
    <h3>¿Dónde se aplican los cambios?</h3>
    <div class="branding-use-list">
      <div><span>01</span><section><strong>Menú lateral</strong><p>Nombre de empresa, siglas y lema.</p></section></div>
      <div><span>02</span><section><strong>Encabezado</strong><p>Empresa y nombre del módulo.</p></section></div>
      <div><span>03</span><section><strong>Acceso al sistema</strong><p>Marca, lema y etiqueta de plataforma.</p></section></div>
      <div><span>04</span><section><strong>Correos y pie de página</strong><p>Nombre de empresa y descripción del sistema.</p></section></div>
    </div>
    <div class="info-note"><strong>Los datos operativos no cambian</strong><p>Editar la identidad visual no modifica departamentos, destinatarios, configuraciones SMTP, reportes históricos ni rutas de almacenamiento. Tampoco requiere una migración de SQLite.</p></div>
  </aside>
</div>
