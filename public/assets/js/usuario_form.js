// public/assets/js/usuario_form.js
// Validación liviana + toggle de password (usuario_nuevo / usuario_editar)
(() => {
  if (window.__flusUsuarioFormBound) return;
  window.__flusUsuarioFormBound = true;

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
      btn.setAttribute('aria-label', (nextType === 'text') ? 'Ocultar contraseña' : 'Mostrar contraseña');
    }
  };

  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function connectFieldError(field, err) {
    if (!field || !err) return;
    if (!err.id) err.id = `${field.id || field.name}-error`;
    err.setAttribute('aria-live', 'polite');

    const describedBy = new Set(String(field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    describedBy.add(err.id);
    field.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
  }

  function setFieldError(field, message) {
    const name = field?.name;
    if (!name) return;

    const err = qs(`[data-error-for="${CSS.escape(name)}"]`);
    if (err) {
      connectFieldError(field, err);
      err.textContent = message || '';
      err.classList.toggle('visible', Boolean(message));
    }

    field.classList.toggle('is-invalid', Boolean(message));
    field.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  function clearAllErrors(form) {
    qsa('.form-error[data-error-for]', form).forEach((el) => {
      el.textContent = '';
      el.classList.remove('visible');
    });
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

  function validateField(field) {
    if (!field || field.disabled) return true;

    const value = String(field.value || '');
    const trimmedValue = value.trim();

    if (field.name === 'password') {
      if (!field.required && trimmedValue === '') {
        setFieldError(field, '');
        return true;
      }

      if (trimmedValue !== '' && value.length < 6) {
        setFieldError(field, 'Debe tener al menos 6 caracteres');
        return false;
      }
    }

    if (!field.checkValidity()) {
      setFieldError(field, humanMessage(field));
      return false;
    }

    setFieldError(field, '');
    return true;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const form = qs('#usuarioForm');
    if (!form) return;

    qsa('.form-error[data-error-for]', form).forEach((err) => {
      const field = form.elements.namedItem(err.dataset.errorFor || '');
      if (!(field instanceof HTMLElement)) return;
      connectFieldError(field, err);
      const hasMessage = String(err.textContent || '').trim() !== '';
      err.classList.toggle('visible', hasMessage);
      if (hasMessage) field.setAttribute('aria-invalid', 'true');
    });

    qsa('[data-password-toggle]', form).forEach((button) => {
      button.addEventListener('click', () => {
        window.togglePassword(button.dataset.passwordToggle || 'password');
      });
    });

    // Validación al submit
    form.addEventListener('submit', (e) => {
      clearAllErrors(form);

      // Si el navegador soporta constraint validation, úsalo
      const fields = qsa('input, select, textarea', form).filter(el => !el.disabled);
      let firstInvalid = null;

      for (const field of fields) {
        if (!validateField(field) && !firstInvalid) {
          firstInvalid = field;
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
        validateField(field);
      });
      field.addEventListener('change', () => {
        validateField(field);
      });
    });
  });
})();
