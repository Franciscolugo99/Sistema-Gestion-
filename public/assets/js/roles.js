/**
 * FLUS - Rol Permisos
 * Gestión de permisos con búsqueda y select all/deselect all
 */

(function() {
  'use strict';

  // =========================================================================
  // BÚSQUEDA DE PERMISOS
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('permisosSearch');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
      const searchTerm = this.value.toLowerCase().trim();
      const sections = document.querySelectorAll('.permisos-section');
      
      sections.forEach(section => {
        const cards = section.querySelectorAll('.permiso-card');
        let visibleCount = 0;
        
        cards.forEach(card => {
          const nombre = card.querySelector('.permiso-nombre')?.textContent.toLowerCase() || '';
          const slug = card.querySelector('.permiso-slug')?.textContent.toLowerCase() || '';
          
          if (nombre.includes(searchTerm) || slug.includes(searchTerm)) {
            card.style.display = 'flex';
            visibleCount++;
          } else {
            card.style.display = 'none';
          }
        });
        
        // Ocultar sección si no tiene cards visibles
        if (visibleCount === 0 && searchTerm !== '') {
          section.classList.add('hidden');
        } else {
          section.classList.remove('hidden');
        }
      });
      
      // Actualizar contador total
      updateTotalCount();
    });
  });

  // =========================================================================
  // SELECT ALL / DESELECT ALL
  // =========================================================================
  
  window.selectAll = function() {
    const checkboxes = document.querySelectorAll('.permiso-checkbox:not(:checked)');
    checkboxes.forEach(checkbox => {
      if (checkbox.closest('.permiso-card').style.display !== 'none') {
        checkbox.checked = true;
        updateCategoryCount(checkbox);
      }
    });
    updateTotalCount();
  };

  window.deselectAll = function() {
    const checkboxes = document.querySelectorAll('.permiso-checkbox:checked');
    checkboxes.forEach(checkbox => {
      if (checkbox.closest('.permiso-card').style.display !== 'none') {
        checkbox.checked = false;
        updateCategoryCount(checkbox);
      }
    });
    updateTotalCount();
  };

  // =========================================================================
  // ACTUALIZAR CONTADORES
  // =========================================================================
  
  window.updateCategoryCount = function(checkbox) {
    const section = checkbox.closest('.permisos-section');
    if (!section) return;

    const allCheckboxes = section.querySelectorAll('.permiso-checkbox');
    const checkedCheckboxes = section.querySelectorAll('.permiso-checkbox:checked');
    
    const countSpan = section.querySelector('.permisos-count-selected');
    if (countSpan) {
      countSpan.textContent = checkedCheckboxes.length;
    }
    
    // Actualizar contador total
    updateTotalCount();
  };

  function updateTotalCount() {
    const totalChecked = document.querySelectorAll('.permiso-checkbox:checked').length;
    const totalCountSpan = document.getElementById('permisosSelectedCount');
    
    if (totalCountSpan) {
      totalCountSpan.textContent = totalChecked;
    }
  }

  // =========================================================================
  // INICIALIZACIÓN DE CONTADORES
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    // Actualizar contador inicial de cada categoría
    const sections = document.querySelectorAll('.permisos-section');
    sections.forEach(section => {
      const checkedCheckboxes = section.querySelectorAll('.permiso-checkbox:checked');
      const countSpan = section.querySelector('.permisos-count-selected');
      if (countSpan) {
        countSpan.textContent = checkedCheckboxes.length;
      }
    });
    
    // Actualizar contador total inicial
    updateTotalCount();
  });

  // =========================================================================
  // PREVENIR SUBMIT SIN CAMBIOS
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('permisosForm');
    if (!form) return;

    let originalState = getFormState();
    let hasChanges = false;

    // Detectar cambios
    form.addEventListener('change', function() {
      const currentState = getFormState();
      hasChanges = originalState !== currentState;
    });

    // Advertir antes de salir si hay cambios
    window.addEventListener('beforeunload', function(e) {
      if (hasChanges) {
        e.preventDefault();
        e.returnValue = '¿Está seguro que desea salir? Los cambios no guardados se perderán.';
        return e.returnValue;
      }
    });

    // Limpiar al enviar
    form.addEventListener('submit', function() {
      hasChanges = false;
    });
  });

  function getFormState() {
    const checkboxes = Array.from(document.querySelectorAll('.permiso-checkbox'));
    return checkboxes.map(cb => cb.checked ? cb.value : '').join(',');
  }

  // =========================================================================
  // CONFIRMACIÓN SI MUCHOS CAMBIOS
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('permisosForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
      const totalChecked = document.querySelectorAll('.permiso-checkbox:checked').length;
      const totalCheckboxes = document.querySelectorAll('.permiso-checkbox').length;
      
      // Si tiene todos o ninguno seleccionado, confirmar
      if (totalChecked === 0) {
        e.preventDefault();
        if (!confirm('¿Está seguro que desea quitar TODOS los permisos de este rol?\n\nLos usuarios con este rol no tendrán acceso a ninguna funcionalidad.')) {
          return false;
        }
        this.submit();
      } else if (totalChecked === totalCheckboxes) {
        e.preventDefault();
        if (!confirm('¿Está seguro que desea asignar TODOS los permisos a este rol?\n\nEsto le dará acceso completo al sistema.')) {
          return false;
        }
        this.submit();
      }
    });
  });

  // =========================================================================
  // KEYBOARD SHORTCUTS
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('keydown', function(e) {
      // Ctrl/Cmd + S para guardar
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const form = document.getElementById('permisosForm');
        if (form) form.submit();
      }
      
      // Ctrl/Cmd + A para seleccionar todos
      if ((e.ctrlKey || e.metaKey) && e.key === 'a' && e.target.id === 'permisosSearch') {
        return; // Permitir select all en input de búsqueda
      } else if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        selectAll();
      }
    });
  });

  // =========================================================================
  // ANIMACIÓN AL CAMBIAR CHECKBOX
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.permiso-checkbox');
    
    checkboxes.forEach(checkbox => {
      checkbox.addEventListener('change', function() {
        const card = this.closest('.permiso-card');
        if (card) {
          // Animación visual al cambiar
          card.style.animation = 'checkboxPulse 0.3s ease';
          setTimeout(() => {
            card.style.animation = '';
          }, 300);
        }
      });
    });
  });

  // =========================================================================
  // ESTILOS ADICIONALES
  // =========================================================================
  
  const style = document.createElement('style');
  style.textContent = `
    @keyframes checkboxPulse {
      0%, 100% {
        transform: scale(1);
      }
      50% {
        transform: scale(1.02);
      }
    }
    
    .permisos-section.hidden {
      display: none;
    }
    
    /* Highlight del input de búsqueda cuando tiene texto */
    #permisosSearch:not(:placeholder-shown) {
      background: rgba(34, 211, 238, 0.05);
      border-color: var(--accent-cyan);
    }
    
    /* Loading state para el botón de guardar */
    .form-footer-actions .v-btn--primary:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    /* Feedback visual para permisos cambiados */
    .permiso-checkbox.changed + .permiso-content {
      box-shadow: 0 0 0 2px rgba(34, 211, 238, 0.3);
    }
  `;
  document.head.appendChild(style);

  // =========================================================================
  // LOG PARA DEBUGGING
  // =========================================================================
  
  console.log('✓ Rol Permisos JS cargado correctamente');

})();