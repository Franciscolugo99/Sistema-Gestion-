/**
 * FLUS - Validación de Formulario de Usuario
 * Validación en tiempo real con feedback visual
 */

(function() {
  'use strict';

  // =========================================================================
  // MENSAJES DE ERROR PERSONALIZADOS
  // =========================================================================
  
  const errorMessages = {
    nombre: {
      valueMissing: 'El nombre es obligatorio',
      tooShort: 'El nombre debe tener al menos 3 caracteres',
      tooLong: 'El nombre es demasiado largo'
    },
    email: {
      valueMissing: 'El email es obligatorio',
      typeMismatch: 'Ingrese un email válido (ej: usuario@ejemplo.com)',
      tooLong: 'El email es demasiado largo'
    },
    username: {
      valueMissing: 'El usuario es obligatorio',
      tooShort: 'El usuario debe tener al menos 3 caracteres',
      tooLong: 'El usuario es demasiado largo',
      patternMismatch: 'Solo se permiten letras, números y guion bajo (_)'
    },
    password: {
      valueMissing: 'La contraseña es obligatoria',
      tooShort: 'La contraseña debe tener al menos 6 caracteres',
      tooLong: 'La contraseña es demasiado larga'
    },
    role_id: {
      valueMissing: 'Debe seleccionar un rol'
    }
  };

  // =========================================================================
  // FUNCIONES DE VALIDACIÓN
  // =========================================================================
  
  /**
   * Validar un campo individual
   */
  function validateField(field) {
    const fieldName = field.name;
    const errorSpan = document.querySelector(`[data-error-for="${fieldName}"]`);
    
    if (!errorSpan) return true;

    // Limpiar error previo
    errorSpan.textContent = '';
    errorSpan.classList.remove('visible');
    field.classList.remove('error');

    // Verificar validez
    if (!field.validity.valid) {
      // Obtener el primer error
      const validity = field.validity;
      let errorMessage = 'Campo inválido';

      if (errorMessages[fieldName]) {
        if (validity.valueMissing && errorMessages[fieldName].valueMissing) {
          errorMessage = errorMessages[fieldName].valueMissing;
        } else if (validity.typeMismatch && errorMessages[fieldName].typeMismatch) {
          errorMessage = errorMessages[fieldName].typeMismatch;
        } else if (validity.tooShort && errorMessages[fieldName].tooShort) {
          errorMessage = errorMessages[fieldName].tooShort;
        } else if (validity.tooLong && errorMessages[fieldName].tooLong) {
          errorMessage = errorMessages[fieldName].tooLong;
        } else if (validity.patternMismatch && errorMessages[fieldName].patternMismatch) {
          errorMessage = errorMessages[fieldName].patternMismatch;
        }
      }

      // Mostrar error
      errorSpan.textContent = errorMessage;
      errorSpan.classList.add('visible');
      field.classList.add('error');
      
      return false;
    }

    // Si es válido, agregar clase de éxito
    if (field.value.trim() !== '') {
      field.classList.add('success');
      setTimeout(() => field.classList.remove('success'), 600);
    }

    return true;
  }

  /**
   * Validar todo el formulario
   */
  function validateForm(form) {
    let isValid = true;
    const fields = form.querySelectorAll('input[required], select[required]');
    
    fields.forEach(field => {
      if (!validateField(field)) {
        isValid = false;
      }
    });

    return isValid;
  }

  // =========================================================================
  // TOGGLE PASSWORD VISIBILITY
  // =========================================================================
  
  window.togglePassword = function(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling;
    
    if (field.type === 'password') {
      field.type = 'text';
      button.innerHTML = `
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
          <line x1="1" y1="1" x2="23" y2="23"/>
        </svg>
      `;
    } else {
      field.type = 'password';
      button.innerHTML = `
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
          <circle cx="12" cy="12" r="3"/>
        </svg>
      `;
    }
  };

  // =========================================================================
  // VALIDACIÓN DE USERNAME (solo alfanumérico + _)
  // =========================================================================
  
  function setupUsernameValidation() {
    const usernameField = document.getElementById('username');
    if (!usernameField) return;

    usernameField.addEventListener('input', function(e) {
      // Remover caracteres no permitidos
      this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
    });
  }

  // =========================================================================
  // VALIDACIÓN DE EMAIL EN TIEMPO REAL
  // =========================================================================
  
  function setupEmailValidation() {
    const emailField = document.getElementById('email');
    if (!emailField) return;

    let debounceTimer;
    
    emailField.addEventListener('input', function(e) {
      clearTimeout(debounceTimer);
      
      // Validar después de 500ms de no escribir
      debounceTimer = setTimeout(() => {
        if (this.value.trim() !== '') {
          validateField(this);
        }
      }, 500);
    });
  }

  // =========================================================================
  // PREVENIR DOBLE SUBMIT
  // =========================================================================
  
  function setupSubmitPrevention() {
    const form = document.getElementById('usuarioForm');
    if (!form) return;

    let isSubmitting = false;

    form.addEventListener('submit', function(e) {
      if (isSubmitting) {
        e.preventDefault();
        return false;
      }

      // Validar formulario
      if (!validateForm(this)) {
        e.preventDefault();
        
        // Scroll al primer error
        const firstError = this.querySelector('.form-input.error, .form-select.error');
        if (firstError) {
          firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
          firstError.focus();
        }
        
        return false;
      }

      // Marcar como enviando
      isSubmitting = true;
      
      // Agregar estado de loading
      const submitBtn = this.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
      }
      
      this.classList.add('form-loading');
    });
  }

  // =========================================================================
  // VALIDACIÓN EN BLUR (al salir del campo)
  // =========================================================================
  
  function setupBlurValidation() {
    const form = document.getElementById('usuarioForm');
    if (!form) return;

    const fields = form.querySelectorAll('input[required], select[required]');
    
    fields.forEach(field => {
      field.addEventListener('blur', function() {
        if (this.value.trim() !== '' || this.hasAttribute('data-touched')) {
          validateField(this);
          this.setAttribute('data-touched', 'true');
        }
      });
      
      // También validar en cambio (para select)
      if (field.tagName === 'SELECT') {
        field.addEventListener('change', function() {
          validateField(this);
          this.setAttribute('data-touched', 'true');
        });
      }
    });
  }

  // =========================================================================
  // AUTO-FOCO EN PRIMER CAMPO
  // =========================================================================
  
  function setupAutoFocus() {
    const firstField = document.getElementById('nombre');
    if (firstField) {
      setTimeout(() => {
        firstField.focus();
      }, 100);
    }
  }

  // =========================================================================
  // INDICADOR DE FORTALEZA DE CONTRASEÑA (OPCIONAL)
  // =========================================================================
  
  function setupPasswordStrength() {
    const passwordField = document.getElementById('password');
    if (!passwordField) return;

    // Crear indicador
    const strengthIndicator = document.createElement('div');
    strengthIndicator.className = 'password-strength';
    strengthIndicator.innerHTML = `
      <div class="password-strength-bar">
        <div class="password-strength-fill"></div>
      </div>
      <span class="password-strength-text"></span>
    `;
    
    // Insertar después del input
    const passwordWrap = passwordField.closest('.password-input-wrap');
    if (passwordWrap) {
      passwordWrap.parentNode.insertBefore(strengthIndicator, passwordWrap.nextSibling);
    }

    passwordField.addEventListener('input', function() {
      const password = this.value;
      let strength = 0;
      let text = '';
      let color = '';

      if (password.length === 0) {
        strengthIndicator.style.display = 'none';
        return;
      }

      strengthIndicator.style.display = 'block';

      // Calcular fortaleza
      if (password.length >= 6) strength++;
      if (password.length >= 10) strength++;
      if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
      if (/\d/.test(password)) strength++;
      if (/[^a-zA-Z0-9]/.test(password)) strength++;

      // Determinar texto y color
      if (strength <= 2) {
        text = 'Débil';
        color = '#ef4444';
      } else if (strength <= 3) {
        text = 'Media';
        color = '#f59e0b';
      } else {
        text = 'Fuerte';
        color = '#22c55e';
      }

      const fill = strengthIndicator.querySelector('.password-strength-fill');
      const textSpan = strengthIndicator.querySelector('.password-strength-text');
      
      fill.style.width = `${(strength / 5) * 100}%`;
      fill.style.backgroundColor = color;
      textSpan.textContent = text;
      textSpan.style.color = color;
    });
  }

  // =========================================================================
  // CONFIRMAR SALIDA SI HAY CAMBIOS
  // =========================================================================
  
  function setupUnsavedWarning() {
    const form = document.getElementById('usuarioForm');
    if (!form) return;

    let hasChanges = false;

    // Detectar cambios
    form.addEventListener('input', function() {
      hasChanges = true;
    });

    // Limpiar al enviar
    form.addEventListener('submit', function() {
      hasChanges = false;
    });

    // Advertir antes de salir
    window.addEventListener('beforeunload', function(e) {
      if (hasChanges) {
        e.preventDefault();
        e.returnValue = '¿Está seguro que desea salir? Los cambios no guardados se perderán.';
        return e.returnValue;
      }
    });
  }

  // =========================================================================
  // INICIALIZACIÓN
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    setupUsernameValidation();
    setupEmailValidation();
    setupBlurValidation();
    setupSubmitPrevention();
    setupAutoFocus();
    setupPasswordStrength();
    setupUnsavedWarning();

    // Log para debugging
    console.log('✓ Validación de formulario inicializada');
  });

  // =========================================================================
  // ESTILOS ADICIONALES INYECTADOS
  // =========================================================================
  
  const style = document.createElement('style');
  style.textContent = `
    .password-strength {
      margin-top: 8px;
      display: none;
    }
    
    .password-strength-bar {
      width: 100%;
      height: 4px;
      background: var(--border);
      border-radius: 2px;
      overflow: hidden;
      margin-bottom: 4px;
    }
    
    .password-strength-fill {
      height: 100%;
      width: 0;
      transition: all 0.3s ease;
      border-radius: 2px;
    }
    
    .password-strength-text {
      font-size: 0.8rem;
      font-weight: 500;
    }
  `;
  document.head.appendChild(style);

})();