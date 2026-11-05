<?php
use ZkTimeNet\Core\Csrf;
use ZkTimeNet\Core\Html;
use ZkTimeNet\Services\TemplateRenderer;
?>
<div class="page-heading-row"><div><div class="eyebrow">DISTRIBUCIÓN AUTOMÁTICA</div><h2>Correo y plantillas</h2><p class="muted">Configura el servidor SMTP y personaliza el mensaje que recibirán los responsables de cada departamento.</p></div><span class="status-pill <?= $mailer->isConfigured() ? 'status-success' : 'status-warning' ?>"><?= $mailer->isConfigured() ? 'SMTP configurado' : 'Falta configuración' ?></span></div>
<div class="content-grid mail-layout">
  <section class="panel mail-form-panel">
    <form method="post" enctype="multipart/form-data" class="stack-form"><input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="save_mail">
      <div class="panel-header"><div><div class="eyebrow">TRANSPORTE DE CORREO</div><h3>Servidor SMTP</h3></div><span class="secure-label">🔒 Credenciales cifradas</span></div>
      <div class="form-grid two-cols">
        <label class="span-2">Servidor SMTP (host)<input type="text" name="host" required maxlength="255" value="<?= Html::escape($smtp['host'] ?? '') ?>" placeholder="smtp.empresa.com"></label>
        <label>Puerto<input type="number" name="port" min="1" max="65535" required value="<?= Html::escape($smtp['port'] ?? 587) ?>"></label>
        <label>Cifrado<select name="encryption"><option value="tls" <?= ($smtp['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS / TLS</option><option value="ssl" <?= ($smtp['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="none" <?= ($smtp['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Sin cifrado (no recomendado)</option></select></label>
        <label>Usuario SMTP<input type="text" name="username" autocomplete="off" value="<?= Html::escape($smtp['username'] ?? '') ?>" placeholder="usuario@empresa.com"></label>
        <label>Contraseña SMTP<input type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($smtp['password_encrypted']) ? 'Configurada; vacío conserva la actual' : 'Escribe la contraseña SMTP' ?>"></label>
        <label class="span-2 check-inline"><input type="checkbox" name="clear_password" value="1"> Eliminar la contraseña SMTP almacenada</label>
        <label>Correo remitente<input type="email" name="from_email" required value="<?= Html::escape($smtp['from_email'] ?? '') ?>" placeholder="nominas@empresa.com"></label>
        <label>Nombre remitente<input type="text" name="from_name" value="<?= Html::escape($smtp['from_name'] ?? $branding['company_name']) ?>"></label>
        <label class="span-2">Responder a (opcional, varios correos)<textarea name="reply_to" rows="3" placeholder="nominas@empresa.com, rh@empresa.com"><?php $replyToValue = $smtp['reply_to'] ?? []; echo Html::escape(is_array($replyToValue) ? implode("\n", $replyToValue) : (string) $replyToValue); ?></textarea><small>Admite varios correos separados por coma, punto y coma o salto de línea. Máximo 50 direcciones.</small></label>
        <label class="span-2 check-inline"><input type="checkbox" name="department_recipients_reply_to" value="1" <?= !array_key_exists('department_recipients_reply_to', $smtp) || !empty($smtp['department_recipients_reply_to']) ? 'checked' : '' ?>> Agregar los correos del departamento a «Responder a»<small>Al enviar el Excel de asistencia, incluye en Reply-To los correos activos del departamento, excepto el Responsable del departamento. Los destinatarios principales siguen recibiendo mensajes individuales. Activado por defecto.</small></label>
        <label>Tiempo de espera (segundos)<input type="number" name="timeout" min="5" max="60" value="<?= Html::escape($smtp['timeout'] ?? 20) ?>"></label>
      </div>
      <div class="form-divider"></div>
      <div class="panel-header"><div><div class="eyebrow">CONTENIDO DEL MENSAJE</div><h3>Plantilla del correo</h3></div></div>
      <label>Asunto del correo<input type="text" name="subject_template" required maxlength="250" value="<?= Html::escape($template['subject_template']) ?>" placeholder="Reporte de asistencia | {{departamento}} | Semana {{semana}}"></label>
      <label>Cuerpo del correo<textarea name="body_template" rows="7" required><?= Html::escape($template['body_template']) ?></textarea></label>
      <label>Pie de correo<textarea name="footer_template" rows="4"><?= Html::escape($template['footer_template']) ?></textarea><small>El pie se agrega al final del cuerpo y puede contener las mismas variables.</small></label>
      <div class="signature-image-panel">
        <div class="signature-image-heading"><div><strong>Imagen de firma</strong><small>Se insertará al final del correo como imagen integrada, similar a una firma de Outlook. No aparecerá como adjunto separado.</small></div><span class="status-pill status-info">Firma visual</span></div>
        <?php if ($signaturePreview !== null): ?>
          <div class="signature-image-current"><img src="index.php?action=signature_preview&amp;v=<?= Html::escape($smtp['signature_image']['filename'] ?? 'current') ?>" alt="Vista previa de la firma configurada"><div><strong><?= Html::escape($signaturePreview['filename']) ?></strong><small>Imagen de firma activa</small><label class="check-inline signature-remove"><input type="checkbox" name="remove_signature_image" value="1"> Quitar imagen actual</label></div></div>
        <?php else: ?>
          <p class="muted small signature-empty">Todavía no hay una imagen de firma configurada.</p>
        <?php endif; ?>
        <label>Seleccionar imagen (opcional)<input type="file" name="signature_image" accept="image/png,image/jpeg,image/gif,image/webp"><small>Formatos PNG, JPG, GIF o WebP. Máximo 2 MB, 2400 × 1200 píxeles y 2.5 megapíxeles. La imagen se guarda en el área privada del servidor.</small></label>
      </div>
      <div class="form-footer"><span class="muted">El cuerpo se entrega en HTML sencillo y texto alternativo.</span><button type="submit" class="btn btn-primary"><?= Html::icon('save') ?> Guardar configuración</button></div>
    </form>
    <form method="post" class="smtp-test-form" data-operation data-operation-title="Probando conexión SMTP" data-operation-message="Estamos conectando con el servidor de correo y enviando el mensaje de prueba." data-operation-detail="La prueba puede tardar según la respuesta del servidor SMTP. No cierres esta ventana."><input type="hidden" name="_token" value="<?= Html::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="smtp_test"><div><strong>Probar conexión y envío</strong><small>Envía un mensaje de prueba utilizando los parámetros guardados.</small></div><input type="email" name="test_recipient" placeholder="destino@empresa.com" required aria-label="Correo de prueba"><button type="submit" class="btn btn-outline"><?= Html::icon('send') ?> Enviar prueba</button></form>
  </section>
  <aside class="panel variables-panel"><div class="eyebrow">PERSONALIZACIÓN</div><h3>Variables disponibles</h3><p class="muted small">Selecciona una variable para copiarla e insertarla en el campo de plantilla activo.</p>
    <div class="variable-list"><?php foreach (TemplateRenderer::VARIABLES as $token => $description): ?><button type="button" class="variable-item" data-variable="<?= Html::escape($token) ?>"><code><?= Html::escape($token) ?></code><span><?= Html::escape($description) ?></span><i><?= Html::icon('copy') ?></i></button><?php endforeach; ?></div>
    <div class="info-note"><strong>Privacidad de credenciales</strong><p>La contraseña SMTP se cifra con AES-256-GCM y una llave independiente almacenada en el directorio privado de datos del servidor. La imagen de firma también permanece fuera del sitio web. Incluye SQLite, la llave y los datos privados en tus respaldos cifrados; no los publiques ni los pierdas.</p></div>
  </aside>
</div>
