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
  // Vista previa del logotipo antes de guardar la identidad visual.
  const brandLogoInput = document.querySelector('[data-brand-logo-input]');
  const brandLogoPreview = document.querySelector('[data-brand-logo-preview]');
  const brandLogoFallback = document.querySelector('[data-brand-logo-fallback]');
  const brandLogoRemove = document.querySelector('[data-brand-logo-remove]');
  const savedBrandLogoSrc = brandLogoPreview ? (brandLogoPreview.getAttribute('src') || '') : '';
  let brandLogoObjectUrl = null;
  const updateBrandLogoPreview = () => {
    if (brandLogoObjectUrl) {
      URL.revokeObjectURL(brandLogoObjectUrl);
      brandLogoObjectUrl = null;
    }
    const selectedLogo = brandLogoInput && brandLogoInput.files ? brandLogoInput.files[0] : null;
    if (selectedLogo && brandLogoPreview && typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function') {
      brandLogoObjectUrl = URL.createObjectURL(selectedLogo);
      brandLogoPreview.src = brandLogoObjectUrl;
      brandLogoPreview.hidden = false;
      if (brandLogoFallback) brandLogoFallback.hidden = true;
      if (brandLogoRemove) brandLogoRemove.checked = false;
      return;
    }
    if (brandLogoRemove && brandLogoRemove.checked) {
      if (brandLogoPreview) brandLogoPreview.hidden = true;
      if (brandLogoFallback) brandLogoFallback.hidden = false;
      return;
    }
    if (brandLogoPreview) {
      if (savedBrandLogoSrc) {
        brandLogoPreview.src = savedBrandLogoSrc;
        brandLogoPreview.hidden = false;
        if (brandLogoFallback) brandLogoFallback.hidden = true;
      } else {
        brandLogoPreview.hidden = true;
        if (brandLogoFallback) brandLogoFallback.hidden = false;
      }
    }
  };
  if (brandLogoInput) brandLogoInput.addEventListener('change', updateBrandLogoPreview);
  if (brandLogoRemove) brandLogoRemove.addEventListener('change', () => {
    if (brandLogoRemove.checked && brandLogoInput) brandLogoInput.value = '';
    updateBrandLogoPreview();
  });

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
  // Modal de confirmación único para todas las acciones marcadas con data-confirm.
  const confirmModal = document.querySelector('#confirm-modal');
  const confirmMessage = confirmModal?.querySelector('[data-confirm-message]');
  const confirmTitle = confirmModal?.querySelector('#confirm-modal-title');
  const confirmCancel = confirmModal?.querySelector('[data-confirm-cancel]');
  const confirmAccept = confirmModal?.querySelector('[data-confirm-accept]');
  const operationOverlay = document.querySelector('#operation-overlay');
  const approvedSubmissions = new WeakSet();
  let pendingConfirmation = null;
  const setConfirmOpen = (open) => {
    if (!confirmModal) return;
    confirmModal.hidden = !open;
    confirmModal.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('modal-open', open);
    if (open) confirmAccept?.focus();
  };
  const closeConfirmation = () => {
    const previous = pendingConfirmation?.submitter;
    pendingConfirmation = null;
    setConfirmOpen(false);
    previous?.focus?.();
  };
  const openConfirmation = (form, submitter) => {
    if (!confirmModal || !confirmMessage || !confirmAccept || !confirmTitle) {
      // El submit ya fue cancelado por el listener: sin modal no se ejecuta la acción.
      return;
    }
    pendingConfirmation = {form, submitter};
    confirmTitle.textContent = form.getAttribute('data-confirm-title') || 'Confirmar operación';
    confirmMessage.textContent = form.getAttribute('data-confirm') || '¿Deseas continuar con esta operación?';
    setButtonText(confirmAccept, form.getAttribute('data-confirm-accept') || 'Confirmar');
    confirmAccept.classList.toggle('btn-danger', form.getAttribute('data-confirm-variant') === 'danger');
    setConfirmOpen(true);
  };
  confirmCancel?.addEventListener('click', closeConfirmation);
  confirmModal?.addEventListener('click', (event) => { if (event.target === confirmModal) closeConfirmation(); });
  confirmAccept?.addEventListener('click', () => {
    if (!pendingConfirmation) return;
    const {form, submitter} = pendingConfirmation;
    pendingConfirmation = null;
    setConfirmOpen(false);
    approvedSubmissions.add(form);
    try {
      if (submitter && submitter.form === form && typeof form.requestSubmit === 'function') form.requestSubmit(submitter);
      else if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else {
        approvedSubmissions.delete(form);
        showOperationOverlay(form);
        form.submit();
      }
    } finally {
      // requestSubmit() dispara el evento de forma síncrona; limpia también si la validación nativa lo cancela.
      approvedSubmissions.delete(form);
    }
  });
  document.addEventListener('keydown', (event) => {
    if (!confirmModal || confirmModal.hidden) return;
    if (event.key === 'Escape') {
      closeConfirmation();
      return;
    }
    if (event.key === 'Tab') {
      const focusable = [confirmCancel, confirmAccept].filter((element) => element instanceof HTMLElement && !element.disabled);
      if (focusable.length < 2) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  const showOperationOverlay = (form) => {
    if (!operationOverlay) return;
    const title = operationOverlay.querySelector('[data-operation-title]');
    const message = operationOverlay.querySelector('[data-operation-message]');
    const detail = operationOverlay.querySelector('[data-operation-detail]');
    if (title) title.textContent = form.getAttribute('data-operation-title') || 'Procesando operación';
    if (message) message.textContent = form.getAttribute('data-operation-message') || 'Estamos trabajando con tus datos. Por favor, espera.';
    if (detail) detail.textContent = form.getAttribute('data-operation-detail') || 'No cierres ni actualices esta ventana hasta que termine.';
    operationOverlay.hidden = false;
    operationOverlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    form.dataset.operationActive = '1';
    const submitter = form.__zkSubmitter;
    if (submitter instanceof HTMLButtonElement) {
      submitter.disabled = true;
      if (submitter.hasAttribute('data-submit-loading')) setButtonText(submitter, 'Procesando…');
    }
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.operationActive === '1') {
      event.preventDefault();
      return;
    }
    if (event.submitter) form.__zkSubmitter = event.submitter;
    if (form.hasAttribute('data-confirm') && !approvedSubmissions.has(form)) {
      event.preventDefault();
      openConfirmation(form, event.submitter || document.activeElement);
      return;
    }
    if (approvedSubmissions.has(form)) approvedSubmissions.delete(form);
    if (form.hasAttribute('data-operation') || form.hasAttribute('data-confirm')) showOperationOverlay(form);
  }, true);
  document.addEventListener('click', (event) => {
    const submitter = event.target instanceof Element ? event.target.closest('button[type="submit"],input[type="submit"]') : null;
    if (submitter?.form) submitter.form.__zkSubmitter = submitter;
  }, true);
  window.addEventListener('pageshow', () => {
    if (operationOverlay) {
      operationOverlay.hidden = true;
      operationOverlay.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('modal-open');
  });
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
    const postBulkAction = async (parameters) => {
      const body = new URLSearchParams({
        _token: bulkTrigger.getAttribute('data-token') || '',
        ...parameters,
      });
      const response = await fetch(`index.php?page=history&run=${encodeURIComponent(bulkTrigger.getAttribute('data-run-id') || '')}`, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body,
      });
      let payload;
      try { payload = await response.json(); } catch (_) { throw new Error('El servidor devolvió una respuesta no válida.'); }
      if (!response.ok || !payload || payload.ok !== true) throw new Error(payload?.error || `Error HTTP ${response.status}`);
      return payload;
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
      let batchId = '';
      const clientIssues = [];
      summary.textContent = 'El envío está en curso. No cierres esta ventana.';
      try {
        const started = await postBulkAction({action: 'bulk_send_start'});
        batchId = String(started.batch_id || '');
        if (!/^[a-f0-9]{32}$/.test(batchId)) throw new Error('El servidor no generó un identificador válido para el envío masivo.');

        for (const file of bulkFiles) {
          file.ui.state.textContent = 'Enviando…'; file.ui.row.classList.add('is-sending');
          try {
            const payload = await postBulkAction({
              action: 'bulk_send_one',
              batch_id: batchId,
              file_id: String(file.id),
            });
            const result = payload.result || {};
            if (result.skipped || result.status === 'no_recipients' || result.status === 'disabled') {
              file.ui.state.textContent = 'Omitido';
              file.ui.detail.textContent = result.message || 'Se omitió porque ya no tiene destinatarios o está deshabilitado.';
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
            const reason = error?.message || 'Error de comunicación; no se recibió confirmación del servidor.';
            clientIssues.push({file_id: Number(file.id), reason});
            file.ui.state.textContent = 'Fallido / sin confirmar';
            file.ui.detail.textContent = reason;
            file.ui.row.classList.add('is-failed');
          }
          done++; setProgress(done, bulkFiles.length);
          summary.textContent = `Enviados: ${sentDepartments} · Fallidos: ${failedDepartments} · Omitidos: ${skippedDepartments} · Pendientes: ${bulkFiles.length - done}`;
        }

        try {
          const receipt = await postBulkAction({
            action: 'bulk_send_summary',
            batch_id: batchId,
            client_issues: JSON.stringify(clientIssues),
          });
          if (receipt.status === 'sent' && receipt.email) {
            const stats = receipt.stats || {};
            summary.textContent = `Proceso terminado. ${sentDepartments} departamento(s) enviados, ${failedDepartments} con fallos y ${skippedDepartments} omitidos. Se envió el comprobante a ${receipt.email}: ${Number(stats.emails_sent || 0)} correo(s) aceptados por SMTP y ${Number(stats.emails_failed || 0)} fallidos.`;
          } else if (receipt.status === 'no_email') {
            summary.textContent = `Proceso terminado. ${sentDepartments} departamento(s) enviados, ${failedDepartments} con fallos y ${skippedDepartments} omitidos. No se envió el comprobante porque tu cuenta no tiene correo configurado. Agrégalo en Mi cuenta.`;
          } else if (receipt.status === 'failed') {
            summary.textContent = `Proceso terminado. Los resultados quedaron registrados, pero no se pudo enviar el comprobante a tu cuenta: ${receipt.error || receipt.message || 'Error SMTP.'}`;
          } else {
            summary.textContent = `Proceso terminado. Estado del comprobante: ${receipt.status || 'sin confirmar'}. Revisa el correo de tu cuenta y la auditoría del historial.`;
          }
        } catch (summaryError) {
          summary.textContent = `Los envíos terminaron (${sentDepartments} enviados, ${failedDepartments} con fallos y ${skippedDepartments} omitidos), pero no se pudo confirmar el envío del comprobante: ${summaryError?.message || 'error de comunicación'}.`;
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

  // Alta y edición de usuarios comparten un único formulario modal.
  const userModal = document.querySelector('#user-modal');
  if (userModal) {
    const userDialog = userModal.querySelector('[role="dialog"]');
    const userForm = userModal.querySelector('form');
    const createUserTrigger = document.querySelector('[data-user-create]');
    const closeUserButtons = userModal.querySelectorAll('[data-user-modal-close]');
    const userId = userModal.querySelector('[data-user-id-field]');
    const personName = userModal.querySelector('[data-user-person-name]');
    const username = userModal.querySelector('[data-user-username]');
    const email = userModal.querySelector('[data-user-email]');
    const password = userModal.querySelector('[data-user-password]');
    const passwordMode = userModal.querySelector('[data-user-password-mode]');
    const passwordHelp = userModal.querySelector('[data-user-password-help]');
    const role = userModal.querySelector('[data-user-role]');
    const modalTitle = userModal.querySelector('#user-modal-title');
    const modalDescription = userModal.querySelector('.user-modal-header p');
    const modalStatus = userModal.querySelector('[data-user-modal-status]');
    const submitLabel = userModal.querySelector('[data-user-submit-label]');
    let userModalReturnFocus = null;

    const setUserModalOpen = (open) => {
      userModal.hidden = !open;
      userModal.setAttribute('aria-hidden', open ? 'false' : 'true');
      document.body.classList.toggle('modal-open', open);
      if (open) personName?.focus();
      else {
        const previous = userModalReturnFocus;
        userModalReturnFocus = null;
        if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
      }
    };

    const setUserModalMode = (user = null) => {
      if (!userForm || !userId || !personName || !username || !email || !password || !role) return;
      userForm.reset();
      const isEditing = user !== null;
      userId.value = isEditing ? String(user.id || '') : '';
      personName.value = isEditing ? String(user.name || '') : '';
      username.value = isEditing ? String(user.username || '') : '';
      email.value = isEditing ? String(user.email || '') : '';
      role.value = isEditing && user.role === 'admin' ? 'admin' : 'nominista';
      password.value = '';
      password.required = !isEditing;
      password.placeholder = isEditing ? 'Vacío conserva la contraseña actual' : 'Mínimo 8 caracteres';
      if (passwordMode) passwordMode.textContent = isEditing ? '(opcional al editar)' : '';
      if (passwordHelp) passwordHelp.textContent = isEditing
        ? 'Al establecer una nueva contraseña se desbloquea la cuenta e invalidan sus sesiones anteriores.'
        : 'Debe tener al menos 8 caracteres.';
      if (modalTitle) modalTitle.textContent = isEditing ? 'Editar usuario' : 'Agregar usuario';
      if (modalDescription) modalDescription.textContent = isEditing
        ? 'Actualiza los datos de acceso y notificación de esta cuenta.'
        : 'Registra una persona y define cómo podrá acceder a la plataforma.';
      if (modalStatus) modalStatus.textContent = isEditing ? 'Edición' : 'Nuevo';
      if (submitLabel) submitLabel.textContent = isEditing ? 'Guardar cambios' : 'Crear usuario';
    };

    const openUserModal = (trigger, user = null, preserveServerValues = false) => {
      userModalReturnFocus = trigger instanceof HTMLElement ? trigger : createUserTrigger;
      if (!preserveServerValues) setUserModalMode(user);
      setUserModalOpen(true);
    };

    const closeUserModal = () => setUserModalOpen(false);
    createUserTrigger?.addEventListener('click', () => openUserModal(createUserTrigger));
    document.querySelectorAll('[data-user-edit]').forEach((button) => {
      button.addEventListener('click', () => openUserModal(button, {
        id: button.dataset.userId || '',
        name: button.dataset.userName || '',
        username: button.dataset.userUsername || '',
        email: button.dataset.userEmail || '',
        role: button.dataset.userRole || 'nominista',
      }));
    });
    closeUserButtons.forEach((button) => button.addEventListener('click', closeUserModal));
    userModal.addEventListener('click', (event) => {
      if (event.target === userModal) closeUserModal();
    });
    document.addEventListener('keydown', (event) => {
      if (userModal.hidden) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeUserModal();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...userModal.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
      if (focusable.length === 0) {
        event.preventDefault();
        userDialog?.focus();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || !userModal.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !userModal.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    });

    if (userModal.dataset.openOnLoad === 'true') openUserModal(createUserTrigger, null, true);
  }


  // Alta y edición de departamentos comparten un único formulario modal.
  const departmentModal = document.querySelector('#department-modal');
  if (departmentModal) {
    const departmentDialog = departmentModal.querySelector('[role="dialog"]');
    const departmentForm = departmentModal.querySelector('form');
    const createDepartmentTrigger = document.querySelector('[data-department-create]');
    const closeDepartmentButtons = departmentModal.querySelectorAll('[data-department-modal-close]');
    const departmentId = departmentModal.querySelector('[data-department-id-field]');
    const reportLabel = departmentModal.querySelector('[data-department-report-label]');
    const displayName = departmentModal.querySelector('[data-department-display-name]');
    const responsibleEmail = departmentModal.querySelector('[data-department-responsible-email]');
    const recipients = departmentModal.querySelector('[data-department-recipients]');
    const position = departmentModal.querySelector('[data-department-position]');
    const enabled = departmentModal.querySelector('[data-department-enabled]');
    const discoveryWarning = departmentModal.querySelector('[data-department-discovery-warning]');
    const modalTitle = departmentModal.querySelector('#department-modal-title');
    const modalDescription = departmentModal.querySelector('.user-modal-header p');
    const modalStatus = departmentModal.querySelector('[data-department-modal-status]');
    const submitLabel = departmentModal.querySelector('[data-department-submit-label]');
    const defaultPosition = departmentModal.dataset.defaultPosition || '1';
    let departmentModalReturnFocus = null;

    const setDepartmentModalOpen = (open) => {
      departmentModal.hidden = !open;
      departmentModal.setAttribute('aria-hidden', open ? 'false' : 'true');
      document.body.classList.toggle('modal-open', open);
      if (open) reportLabel?.focus();
      else {
        const previous = departmentModalReturnFocus;
        departmentModalReturnFocus = null;
        if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
      }
    };

    const setDepartmentModalMode = (department = null) => {
      if (!departmentForm || !departmentId || !reportLabel || !displayName || !responsibleEmail || !recipients || !position || !enabled) return;
      departmentForm.reset();
      const isEditing = department !== null;
      departmentId.value = isEditing ? String(department.id || '') : '';
      reportLabel.value = isEditing ? String(department.reportLabel || '') : '';
      displayName.value = isEditing ? String(department.displayName || '') : '';
      responsibleEmail.value = isEditing ? String(department.responsibleEmail || '') : '';
      recipients.value = isEditing ? String(department.recipients || '') : '';
      position.value = isEditing ? String(department.position ?? defaultPosition) : defaultPosition;
      enabled.checked = !isEditing || String(department.enabled ?? '1') === '1';
      if (discoveryWarning) discoveryWarning.hidden = !isEditing || String(department.needsConfig || '0') !== '1';
      if (modalTitle) modalTitle.textContent = isEditing ? 'Editar departamento' : 'Agregar departamento';
      if (modalDescription) modalDescription.textContent = isEditing
        ? 'Actualiza la etiqueta de origen, los datos del departamento y sus destinatarios.'
        : 'Registra el departamento y define quién recibirá su archivo de asistencia.';
      if (modalStatus) modalStatus.textContent = isEditing ? 'Edición' : 'Nuevo';
      if (submitLabel) submitLabel.textContent = isEditing ? 'Guardar cambios' : 'Agregar departamento';
    };

    const openDepartmentModal = (trigger, department = null, preserveServerValues = false) => {
      departmentModalReturnFocus = trigger instanceof HTMLElement ? trigger : createDepartmentTrigger;
      if (!preserveServerValues) setDepartmentModalMode(department);
      setDepartmentModalOpen(true);
    };

    const closeDepartmentModal = () => setDepartmentModalOpen(false);
    createDepartmentTrigger?.addEventListener('click', () => openDepartmentModal(createDepartmentTrigger));
    document.querySelectorAll('[data-department-edit]').forEach((button) => {
      button.addEventListener('click', () => openDepartmentModal(button, {
        id: button.dataset.departmentId || '',
        reportLabel: button.dataset.departmentReportLabel || '',
        displayName: button.dataset.departmentDisplayName || '',
        responsibleEmail: button.dataset.departmentResponsibleEmail || '',
        recipients: (() => {
          try {
            const storedRecipients = JSON.parse(button.dataset.departmentRecipients || '[]');
            return Array.isArray(storedRecipients) ? storedRecipients.map(String).join('\n') : '';
          } catch (_) {
            return '';
          }
        })(),
        position: button.dataset.departmentPosition || defaultPosition,
        enabled: button.dataset.departmentEnabled || '0',
        needsConfig: button.dataset.departmentNeedsConfig || '0',
      }));
    });
    closeDepartmentButtons.forEach((button) => button.addEventListener('click', closeDepartmentModal));
    departmentModal.addEventListener('click', (event) => {
      if (event.target === departmentModal) closeDepartmentModal();
    });
    document.addEventListener('keydown', (event) => {
      if (departmentModal.hidden) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeDepartmentModal();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...departmentModal.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
      if (focusable.length === 0) {
        event.preventDefault();
        departmentDialog?.focus();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || !departmentModal.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !departmentModal.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    });

    if (departmentModal.dataset.openOnLoad === 'true') openDepartmentModal(createDepartmentTrigger, null, true);
  }

  const menuToggle = document.querySelector('[data-menu-toggle]'); const sidebar = document.querySelector('#sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => sidebar.classList.toggle('is-open'));
    document.addEventListener('click', (event) => { if (window.innerWidth <= 850 && sidebar.classList.contains('is-open') && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) sidebar.classList.remove('is-open'); });
  }
})();
