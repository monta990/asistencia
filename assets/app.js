'use strict';

(() => {
  // El selector de tema se gestiona desde theme.js mediante delegación global.
  const setButtonText = (button, label) => {
    const icon = button.querySelector('.ui-icon');
    const children = [];
    if (icon) children.push(icon.cloneNode(true));
    children.push(document.createTextNode(`${icon ? ' ' : ''}${label}`));
    button.replaceChildren(...children);
  };
  if (window.ZkTheme && typeof window.ZkTheme.syncButtons === 'function') window.ZkTheme.syncButtons();

  const fileInput = document.querySelector('#report-file');
  const dropzone = document.querySelector('.dropzone');
  const fileName = document.querySelector('[data-file-name]');
  const updateFileName = () => {
    if (!fileInput || !fileName) return;
    const file = fileInput.files && fileInput.files[0];
    fileName.textContent = file ? `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MB` : 'Ningún archivo seleccionado';
  };
  if (fileInput) fileInput.addEventListener('change', updateFileName);
  if (dropzone && fileInput) {
    ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => { event.preventDefault(); dropzone.classList.add('is-dragover'); }));
    ['dragleave', 'drop'].forEach((name) => dropzone.addEventListener(name, (event) => { event.preventDefault(); dropzone.classList.remove('is-dragover'); }));
    dropzone.addEventListener('drop', (event) => {
      const files = event.dataTransfer && event.dataTransfer.files;
      if (files && files.length === 1 && files[0].name.toLowerCase().endsWith('.xlsx')) {
        const transfer = new DataTransfer(); transfer.items.add(files[0]); fileInput.files = transfer.files; updateFileName();
      } else if (files && files.length > 0 && fileName) fileName.textContent = 'Selecciona un solo archivo con extensión .xlsx';
    });
  }
  document.querySelectorAll('[data-submit-loading]').forEach((button) => {
    const form = button.closest('form');
    if (form) form.addEventListener('submit', () => { button.disabled = true; setButtonText(button, 'Procesando reporte…'); });
  });
  let lastTemplateField = null;
  document.querySelectorAll('input[name="subject_template"],textarea[name="body_template"],textarea[name="footer_template"]').forEach((field) => field.addEventListener('focus', () => { lastTemplateField = field; }));
  document.querySelectorAll('[data-variable]').forEach((button) => button.addEventListener('click', async () => {
    const token = button.getAttribute('data-variable') || ''; if (!token) return;
    if (lastTemplateField) {
      const start = Number.isInteger(lastTemplateField.selectionStart) ? lastTemplateField.selectionStart : lastTemplateField.value.length;
      const end = Number.isInteger(lastTemplateField.selectionEnd) ? lastTemplateField.selectionEnd : start;
      lastTemplateField.setRangeText(token, start, end, 'end'); lastTemplateField.focus();
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
      try { await navigator.clipboard.writeText(token); } catch (_) { /* Clipboard unavailable on plain HTTP. */ }
    }
  }));
  document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.getAttribute('data-confirm') || '¿Confirmar esta operación?')) event.preventDefault();
  }));
  document.querySelectorAll('[data-dismiss-alert]').forEach((button) => button.addEventListener('click', () => button.closest('.alert')?.remove()));

  const filenameEditForm = document.querySelector('[data-filename-edit-form]');
  const filenameEditToggle = document.querySelector('[data-filename-edit-toggle]');
  const filenameEditCancel = document.querySelector('[data-filename-edit-cancel]');
  if (filenameEditForm && filenameEditToggle) {
    filenameEditToggle.addEventListener('click', () => {
      filenameEditForm.hidden = false;
      filenameEditToggle.hidden = true;
      filenameEditForm.querySelector('input[name="filename_base"]')?.focus();
      filenameEditForm.querySelector('input[name="filename_base"]')?.select();
    });
    filenameEditCancel?.addEventListener('click', () => {
      filenameEditForm.hidden = true;
      filenameEditToggle.hidden = false;
    });
  }

  // Envío manual masivo: una petición independiente por departamento para mostrar avance y fallos.
  const bulkTrigger = document.querySelector('[data-bulk-send]');
  const bulkModal = document.querySelector('#bulk-mail-modal');
  if (bulkTrigger && bulkModal) {
    const closeButtons = bulkModal.querySelectorAll('[data-bulk-close]');
    const startButton = bulkModal.querySelector('[data-bulk-start]');
    const refreshButton = bulkModal.querySelector('[data-bulk-refresh]');
    const resultsNode = bulkModal.querySelector('[data-bulk-results]');
    const progressText = bulkModal.querySelector('[data-bulk-progress-text]');
    const progressCount = bulkModal.querySelector('[data-bulk-progress-count]');
    const progressBar = bulkModal.querySelector('[data-bulk-progress-bar]');
    const summary = bulkModal.querySelector('[data-bulk-summary]');
    let bulkRunning = false;
    let bulkFiles = [];
    const setOpen = (open) => {
      bulkModal.hidden = !open;
      bulkModal.setAttribute('aria-hidden', open ? 'false' : 'true');
      document.body.classList.toggle('modal-open', open);
      if (open) startButton?.focus(); else bulkTrigger.focus();
    };
    const setCloseEnabled = (enabled) => closeButtons.forEach((button) => { button.disabled = !enabled; });
    const setProgress = (done, total) => {
      const percent = total ? Math.round((done / total) * 100) : 100;
      if (progressText) progressText.textContent = `${done} de ${total} departamentos procesados`;
      if (progressCount) progressCount.textContent = `${percent}%`;
      if (progressBar) progressBar.style.width = `${percent}%`;
    };
    const addResult = (department) => {
      const row = document.createElement('div'); row.className = 'bulk-result-row';
      const copy = document.createElement('div'); copy.className = 'bulk-result-copy';
      const name = document.createElement('strong'); name.textContent = department;
      const detail = document.createElement('small'); detail.className = 'bulk-result-detail'; detail.textContent = 'En espera';
      copy.append(name, detail);
      const state = document.createElement('span'); state.className = 'bulk-result-state'; state.textContent = 'En cola';
      row.append(copy, state); resultsNode?.append(row);
      return {row, state, detail};
    };
    bulkTrigger.addEventListener('click', () => {
      try { bulkFiles = JSON.parse(bulkTrigger.getAttribute('data-files') || '[]'); } catch (_) { bulkFiles = []; }
      if (!Array.isArray(bulkFiles) || bulkFiles.length === 0) return;
      if (resultsNode) resultsNode.replaceChildren();
      bulkFiles.forEach((file) => { file.ui = addResult(String(file.department || 'Departamento')); });
      setProgress(0, bulkFiles.length);
      summary.textContent = `Vas a enviar ${bulkFiles.length} Excel individuales, uno por cada departamento de la lista. El ZIP general no se adjuntará. Revisa los departamentos y pulsa «Iniciar envío» para continuar.`;
      startButton.hidden = false; refreshButton.hidden = true; setCloseEnabled(true);
      setOpen(true);
    });
    closeButtons.forEach((button) => button.addEventListener('click', () => { if (!bulkRunning) setOpen(false); }));
    startButton?.addEventListener('click', async () => {
      if (bulkRunning || bulkFiles.length === 0) return;
      bulkRunning = true; startButton.disabled = true; setButtonText(startButton, 'Enviando…'); setCloseEnabled(false);
      let sentDepartments = 0; let failedDepartments = 0; let skippedDepartments = 0; let done = 0;
      summary.textContent = 'El envío está en curso. No cierres esta ventana.';
      try {
      for (const file of bulkFiles) {
        file.ui.state.textContent = 'Enviando…'; file.ui.row.classList.add('is-sending');
        try {
          const body = new URLSearchParams({
            _token: bulkTrigger.getAttribute('data-token') || '',
            action: 'bulk_send_one',
            file_id: String(file.id)
          });
          const response = await fetch(`index.php?page=history&run=${encodeURIComponent(bulkTrigger.getAttribute('data-run-id') || '')}`, {
            method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body
          });
          let payload;
          try { payload = await response.json(); } catch (_) { throw new Error('El servidor devolvió una respuesta no válida.'); }
          if (!response.ok || !payload || payload.ok !== true) throw new Error(payload?.error || `Error HTTP ${response.status}`);
          const result = payload.result || {};
          if (result.skipped || result.status === 'no_recipients') {
            file.ui.state.textContent = 'Sin correo';
            file.ui.detail.textContent = result.message || 'Se omitió porque ya no tiene destinatarios.';
            file.ui.row.classList.add('is-skipped');
            skippedDepartments++;
          } else if (result.status === 'sent') {
            sentDepartments++;
            file.ui.state.textContent = `Enviado · ${Number(result.sent || 0)} correo(s)`;
            file.ui.detail.textContent = (result.sent_recipients || []).join(', ');
            file.ui.row.classList.add('is-sent');
          } else {
            failedDepartments++;
            file.ui.state.textContent = `Fallido · ${Number(result.failed || 0)} error(es)`;
            const failures = Object.entries(result.failed_recipients || {}).map(([email, reason]) => `${email}: ${reason}`);
            file.ui.detail.textContent = failures.join(' · ') || result.message || 'Revisa la auditoría del envío.';
            file.ui.row.classList.add('is-failed');
            if (result.message) file.ui.state.title = result.message;
          }
        } catch (error) {
          failedDepartments++;
          file.ui.state.textContent = 'Fallido';
          file.ui.detail.textContent = error?.message || 'Error de conexión';
          file.ui.row.classList.add('is-failed');
        }
        done++; setProgress(done, bulkFiles.length);
        summary.textContent = `Enviados: ${sentDepartments} · Fallidos: ${failedDepartments} · Sin correo: ${skippedDepartments} · Pendientes: ${bulkFiles.length - done}`;
      }
      } catch (error) {
        summary.textContent = `El envío se interrumpió: ${error?.message || 'error inesperado'}. Revisa el resultado de cada departamento antes de volver a intentarlo.`;
      } finally {
        bulkRunning = false;
        startButton.hidden = true;
        startButton.disabled = false;
        setButtonText(startButton, 'Iniciar envío');
        refreshButton.hidden = false;
        setCloseEnabled(true);
      }
      if (summary.textContent.startsWith('El envío se interrumpió:')) return;
      summary.textContent = `Proceso terminado. ${sentDepartments} departamento(s) enviados, ${failedDepartments} con fallos y ${skippedDepartments} omitidos por no tener correo. Revisa el detalle de auditoría en el historial.`;
    });
    refreshButton?.addEventListener('click', () => window.location.reload());
    bulkModal.addEventListener('click', (event) => { if (event.target === bulkModal && !bulkRunning) setOpen(false); });
  }

  // Ayuda contextual: muestra únicamente las instrucciones del perfil autenticado.
  const helpModal = document.querySelector('#help-modal');
  const helpTrigger = document.querySelector('[data-help-open]');
  if (helpModal && helpTrigger) {
    const closeHelpButtons = helpModal.querySelectorAll('[data-help-close]');
    const setHelpOpen = (open) => {
      helpModal.hidden = !open;
      helpModal.setAttribute('aria-hidden', open ? 'false' : 'true');
      document.body.classList.toggle('modal-open', open);
      if (open) helpModal.querySelector('.help-modal-close')?.focus();
      else helpTrigger.focus();
    };
    helpTrigger.addEventListener('click', () => setHelpOpen(true));
    closeHelpButtons.forEach((button) => button.addEventListener('click', () => setHelpOpen(false)));
    helpModal.addEventListener('click', (event) => { if (event.target === helpModal) setHelpOpen(false); });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !helpModal.hidden) setHelpOpen(false);
    });
  }

  const menuToggle = document.querySelector('[data-menu-toggle]'); const sidebar = document.querySelector('#sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => sidebar.classList.toggle('is-open'));
    document.addEventListener('click', (event) => { if (window.innerWidth <= 850 && sidebar.classList.contains('is-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) sidebar.classList.remove('is-open'); });
  }
})();
