/**
 * Proveedores Module - FLUS v1.0
 * Maneja el drawer y funcionalidad del módulo de proveedores
 */

(function() {
  'use strict';

  // Elements
  const drawer = document.getElementById('provDrawer');
  const overlay = document.getElementById('provDrawerOverlay');
  const closeBtn = document.getElementById('provDrawerClose');
  const cancelBtn = document.getElementById('provCancelBtn');
  const form = document.getElementById('provForm');

  if (!drawer) return; // Solo ejecutar si existe el drawer

  // ========== Drawer Functions ==========
  
  function openDrawer() {
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    
    // Focus en primer input
    setTimeout(() => {
      const firstInput = form?.querySelector('input:not([type="hidden"])');
      if (firstInput) firstInput.focus();
    }, 100);
  }

  function closeDrawer() {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    overlay.classList.remove('is-open');
    document.body.style.overflow = '';
    
    // Limpiar URL si tiene parámetros de edición
    const url = new URL(window.location.href);
    if (url.searchParams.has('new') || url.searchParams.has('editar')) {
      url.searchParams.delete('new');
      url.searchParams.delete('editar');
      window.history.replaceState({}, '', url.toString());
    }
  }

  // ========== Event Listeners ==========

  // Cerrar drawer
  if (closeBtn) {
    closeBtn.addEventListener('click', closeDrawer);
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', closeDrawer);
  }

  if (overlay) {
    overlay.addEventListener('click', closeDrawer);
  }

  // Cerrar con Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
      closeDrawer();
    }
  });

  // Abrir drawer con botón nuevo
  document.querySelectorAll('a[href*="new=1"]').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      
      // Limpiar form
      if (form) {
        form.reset();
        const idInput = form.querySelector('input[name="id"]');
        if (idInput) idInput.remove();
        
        // Marcar activo por defecto
        const activoCheck = form.querySelector('input[name="activo"]');
        if (activoCheck) activoCheck.checked = true;
      }
      
      // Actualizar título
      const title = drawer.querySelector('.drawer-title');
      if (title) title.textContent = 'Nuevo proveedor';
      
      // Ocultar stats
      const stats = drawer.querySelector('.edit-stats');
      if (stats) stats.style.display = 'none';
      
      // Actualizar URL
      const url = new URL(window.location.href);
      url.searchParams.set('new', '1');
      url.searchParams.delete('editar');
      window.history.replaceState({}, '', url.toString());
      
      openDrawer();
    });
  });

  // ========== CUIT Formatting ==========
  
  const cuitInput = form?.querySelector('input[name="cuit"]');
  if (cuitInput) {
    cuitInput.addEventListener('input', function(e) {
      let value = this.value.replace(/\D/g, '');
      
      if (value.length > 11) {
        value = value.slice(0, 11);
      }
      
      // Formatear XX-XXXXXXXX-X
      if (value.length > 2) {
        value = value.slice(0, 2) + '-' + value.slice(2);
      }
      if (value.length > 11) {
        value = value.slice(0, 11) + '-' + value.slice(11);
      }
      
      this.value = value;
    });
  }

  // ========== WhatsApp Formatting ==========
  
  const waInput = form?.querySelector('input[name="whatsapp"]');
  if (waInput) {
    waInput.addEventListener('input', function() {
      // Solo números
      this.value = this.value.replace(/\D/g, '');
    });
  }

  // ========== Form Validation ==========
  
  if (form) {
    form.addEventListener('submit', function(e) {
      const nombre = form.querySelector('input[name="nombre"]');
      
      if (!nombre || nombre.value.trim() === '') {
        e.preventDefault();
        nombre?.focus();
        showToast?.('El nombre del proveedor es obligatorio', 'error');
        return;
      }
      
      // Deshabilitar botón para evitar doble submit
      const submitBtn = document.getElementById('provSubmitBtn');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Guardando...';
      }
    });
  }

  // ========== Toggle Forms (activar/desactivar) ==========
  
  document.querySelectorAll('.toggle-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      const valor = this.querySelector('input[name="valor"]').value;
      const action = valor === '0' ? 'desactivar' : 'activar';
      
      if (!confirm(`¿Estás seguro de ${action} este proveedor?`)) {
        e.preventDefault();
      }
    });
  });

  // ========== Search on Enter ==========
  
  const searchInput = document.querySelector('.search-input');
  if (searchInput) {
    searchInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.target.form?.submit();
      }
    });
  }

  // ========== Auto-open drawer if URL has params ==========
  
  const url = new URL(window.location.href);
  if (url.searchParams.has('new') || url.searchParams.has('editar')) {
    // El drawer ya viene abierto desde PHP, solo asegurar estado correcto
    if (!drawer.classList.contains('is-open')) {
      openDrawer();
    }
  }

  // ========== Keyboard Shortcuts ==========
  
  document.addEventListener('keydown', (e) => {
    // Ctrl+N o Alt+N = Nuevo proveedor
    if ((e.ctrlKey || e.altKey) && e.key === 'n' && !drawer.classList.contains('is-open')) {
      e.preventDefault();
      const newBtn = document.querySelector('a[href*="new=1"]');
      if (newBtn) newBtn.click();
    }
    
    // Ctrl+F o / = Focus en búsqueda
    if ((e.ctrlKey && e.key === 'f') || (e.key === '/' && !e.target.matches('input, textarea'))) {
      if (!drawer.classList.contains('is-open')) {
        e.preventDefault();
        searchInput?.focus();
      }
    }
  });

  // ========== Row Click to Edit ==========
  
  document.querySelectorAll('.prov-table tbody tr').forEach(row => {
    row.style.cursor = 'pointer';
    
    row.addEventListener('click', (e) => {
      // Ignorar si se hizo click en acciones
      if (e.target.closest('.col-actions')) return;
      if (e.target.closest('button')) return;
      if (e.target.closest('a')) return;
      
      // Buscar link de editar en la fila
      const editLink = row.querySelector('a[href*="editar="]');
      if (editLink) {
        window.location.href = editLink.href;
      }
    });
  });

  console.log('✅ Proveedores module loaded');

})();
