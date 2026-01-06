/**
 * FLUS - Rol Permisos COMPACT
 * Con secciones colapsables para mejor vista general
 */

(function() {
  'use strict';

  // =========================================================================
  // EXPONER FUNCIONES GLOBALMENTE PRIMERO (antes de DOMContentLoaded)
  // =========================================================================
  
  window.selectAll = function() {
    const checkboxes = document.querySelectorAll('.permiso-checkbox:not(:checked)');
    checkboxes.forEach(checkbox => {
      const card = checkbox.closest('.permiso-card');
      if (card && card.style.display !== 'none') {
        checkbox.checked = true;
        updateCategoryCount(checkbox);
      }
    });
    updateTotalCount();
  };

  window.deselectAll = function() {
    const checkboxes = document.querySelectorAll('.permiso-checkbox:checked');
    checkboxes.forEach(checkbox => {
      const card = checkbox.closest('.permiso-card');
      if (card && card.style.display !== 'none') {
        checkbox.checked = false;
        updateCategoryCount(checkbox);
      }
    });
    updateTotalCount();
  };

  window.expandAll = function() {
    const sections = document.querySelectorAll('.permisos-section');
    sections.forEach(section => {
      section.classList.remove('collapsed');
      
      const sectionId = section.dataset.categoria || section.querySelector('.permisos-section-title')?.textContent;
      if (sectionId) {
        localStorage.setItem(`section_${sectionId}_collapsed`, false);
      }
    });
  };

  window.collapseAll = function() {
    const sections = document.querySelectorAll('.permisos-section');
    sections.forEach(section => {
      section.classList.add('collapsed');
      
      const sectionId = section.dataset.categoria || section.querySelector('.permisos-section-title')?.textContent;
      if (sectionId) {
        localStorage.setItem(`section_${sectionId}_collapsed`, true);
      }
    });
  };

  window.updateCategoryCount = function(checkbox) {
    const section = checkbox.closest('.permisos-section');
    if (!section) return;

    const allCheckboxes = section.querySelectorAll('.permiso-checkbox');
    const checkedCheckboxes = section.querySelectorAll('.permiso-checkbox:checked');
    
    const countSpan = section.querySelector('.permisos-count-selected');
    if (countSpan) {
      countSpan.textContent = checkedCheckboxes.length;
    }
    
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
  // COLAPSAR/EXPANDIR SECCIONES
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.permisos-section');
    
    sections.forEach(section => {
      const header = section.querySelector('.permisos-section-header');
      if (!header) return;

      // Agregar icono de colapso si no existe
      if (!header.querySelector('.collapse-icon')) {
        const collapseIcon = document.createElement('div');
        collapseIcon.className = 'collapse-icon';
        collapseIcon.innerHTML = `
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        `;
        header.appendChild(collapseIcon);
      }

      // Toggle al hacer clic
      header.addEventListener('click', function(e) {
        // No colapsar si se hace clic en un checkbox
        if (e.target.closest('.permiso-checkbox')) return;
        
        section.classList.toggle('collapsed');
        
        // Guardar estado en localStorage
        const sectionId = section.dataset.categoria || section.querySelector('.permisos-section-title')?.textContent;
        if (sectionId) {
          const collapsed = section.classList.contains('collapsed');
          localStorage.setItem(`section_${sectionId}_collapsed`, collapsed);
        }
      });

      // Restaurar estado desde localStorage
      const sectionId = section.dataset.categoria || section.querySelector('.permisos-section-title')?.textContent;
      if (sectionId) {
        const wasCollapsed = localStorage.getItem(`section_${sectionId}_collapsed`) === 'true';
        if (wasCollapsed) {
          section.classList.add('collapsed');
        }
      }
    });
  });

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
        
        // Si hay búsqueda activa, expandir secciones con resultados
        if (searchTerm !== '') {
          if (visibleCount > 0) {
            section.classList.remove('hidden');
            section.classList.remove('collapsed'); // Auto-expandir
          } else {
            section.classList.add('hidden');
          }
        } else {
          section.classList.remove('hidden');
          // Restaurar estado de colapso
          const sectionId = section.dataset.categoria || section.querySelector('.permisos-section-title')?.textContent;
          if (sectionId) {
            const wasCollapsed = localStorage.getItem(`section_${sectionId}_collapsed`) === 'true';
            if (wasCollapsed) {
              section.classList.add('collapsed');
            }
          }
        }
      });
      
      updateTotalCount();
    });
  });

  // =========================================================================
  // INICIALIZACIÓN DE CONTADORES
  // =========================================================================
  
  document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.permisos-section');
    sections.forEach(section => {
      const checkedCheckboxes = section.querySelectorAll('.permiso-checkbox:checked');
      const countSpan = section.querySelector('.permisos-count-selected');
      if (countSpan) {
        countSpan.textContent = checkedCheckboxes.length;
      }
    });
    
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

    form.addEventListener('change', function() {
      const currentState = getFormState();
      hasChanges = originalState !== currentState;
    });

    window.addEventListener('beforeunload', function(e) {
      if (hasChanges) {
        e.preventDefault();
        e.returnValue = '¿Está seguro que desea salir? Los cambios no guardados se perderán.';
        return e.returnValue;
      }
    });

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
        return;
      } else if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        if (typeof selectAll === 'function') selectAll();
      }

      // Ctrl/Cmd + E para expandir todas
      if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        if (typeof expandAll === 'function') expandAll();
      }

      // Ctrl/Cmd + Q para colapsar todas
      if ((e.ctrlKey || e.metaKey) && e.key === 'q') {
        e.preventDefault();
        if (typeof collapseAll === 'function') collapseAll();
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
          card.style.animation = 'checkboxPulse 0.25s ease';
          setTimeout(() => {
            card.style.animation = '';
          }, 250);
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
        transform: scale(1.015);
      }
    }
    
    .permisos-section.hidden {
      display: none;
    }
    
    #permisosSearch:not(:placeholder-shown) {
      background: rgba(34, 211, 238, 0.05);
      border-color: var(--accent-cyan);
    }
    
    .form-footer-actions .v-btn--primary:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    /* Transición suave para colapso */
    .permisos-grid {
      transition: all 0.2s ease;
    }
    
    .permisos-section.collapsed .permisos-grid {
      opacity: 0;
      max-height: 0;
      overflow: hidden;
    }
    
    /* Ocultar texto en mobile para botones más compactos */
    @media (max-width: 768px) {
      .toolbar-actions .btn-text {
        display: none;
      }
    }
  `;
  document.head.appendChild(style);

  // =========================================================================
  // LOG PARA DEBUGGING
  // =========================================================================
  
  console.log('✓ Rol Permisos COMPACT JS cargado correctamente');
  console.log('Funciones disponibles:');
  console.log('  - selectAll()');
  console.log('  - deselectAll()');
  console.log('  - expandAll()');
  console.log('  - collapseAll()');
  console.log('');
  console.log('Shortcuts:');
  console.log('  Ctrl+S: Guardar');
  console.log('  Ctrl+A: Marcar todos');
  console.log('  Ctrl+E: Abrir categorías');
  console.log('  Ctrl+Q: Cerrar categorías');

})();