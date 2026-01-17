// public/assets/js/usuario_form.js
// Validación liviana + toggle de password (usuario_nuevo / usuario_editar)
(() => {
  if (window.__flusUsuarioFormBound) return;
  window.__flusUsuarioFormBound = true;

  // Expuesto porque el HTML usa onclick="togglePassword('password')"
  window.togglePassword = function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const wrap = input.closest('.password-input-wrap') || input.parentElement;
    const btn = wrap ? wrap.querySelector('.password-toggle') : null;

    const nextType = input.type === 'password' ? 'text' : 'password';
    input.type = nextType;

    // Actualizar label visual si existe
    if (btn) {
      const label = btn.querySelector('[data-label]');
      if (label) label.textContent = (nextType === 'text') ? 'Ocultar' : 'Mostrar';
      btn.setAttribute('aria-pressed', String(nextType === 'text'));
    }
  };

  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function setFieldError(field, message) {
    const name = field?.name;
    if (!name) return;

    const err = qs(`[data-error-for="${CSS.escape(name)}"]`);
    if (err) err.textContent = message || '';

    field.classList.toggle('is-invalid', Boolean(message));
    field.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  function clearAllErrors(form) {
    qsa('.form-error[data-error-for]', form).forEach(el => (el.textContent = ''));
    qsa('.is-invalid', form).forEach(el => el.classList.remove('is-invalid'));
    qsa('[aria-invalid="true"]', form).forEach(el => el.setAttribute('aria-invalid', 'false'));
  }

  function humanMessage(field) {
    if (!field) return 'Campo inválido';
    const v = field.validity;
    if (v.valueMissing) return 'Este campo es obligatorio';
    if (v.typeMismatch) return 'Formato inválido';
    if (v.tooShort) return `Debe tener al menos ${field.minLength} caracteres`;
    if (v.tooLong) return `No puede superar ${field.maxLength} caracteres`;
    if (v.patternMismatch) return 'Formato inválido';
    return 'Campo inválido';
  }

  document.addEventListener('DOMContentLoaded', () => {
    const form = qs('#usuarioForm');
    if (!form) return;

    // Validación al submit
    form.addEventListener('submit', (e) => {
      clearAllErrors(form);

      // Si el navegador soporta constraint validation, úsalo
      const fields = qsa('input, select, textarea', form).filter(el => !el.disabled);
      let firstInvalid = null;

      for (const field of fields) {
        // No forzar password en editar si está vacío y no es required
        if (field.name === 'password' && !field.required && String(field.value || '').trim() === '') {
          continue;
        }

        if (!field.checkValidity()) {
          const msg = humanMessage(field);
          setFieldError(field, msg);
          if (!firstInvalid) firstInvalid = field;
        }
      }

      if (firstInvalid) {
        e.preventDefault();
        firstInvalid.focus({ preventScroll: false });
      }
    });

    // Limpieza en vivo
    qsa('input, select, textarea', form).forEach((field) => {
      field.addEventListener('input', () => {
        if (field.checkValidity()) setFieldError(field, '');
      });
      field.addEventListener('change', () => {
        if (field.checkValidity()) setFieldError(field, '');
      });
    });
  });
})();
