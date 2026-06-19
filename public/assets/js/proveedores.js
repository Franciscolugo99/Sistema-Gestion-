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
  const submitBtn = document.getElementById('provSubmitBtn');

  if (!drawer) return; // Solo ejecutar si existe el drawer

  let formChanged = false;
  let closeDrawerOpen = false;

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

  async function closeDrawer(e) {
    e?.preventDefault?.();
    e?.stopPropagation?.();

    if (window.Swal && typeof window.Swal.isVisible === 'function' && window.Swal.isVisible()) return;
    if (closeDrawerOpen) return;

    closeDrawerOpen = true;
    try {
      if (formChanged && window.Notif && typeof window.Notif.confirmar === 'function') {
        const ok = await window.Notif.confirmar(
          'Cambios sin guardar',
          'Tenés cambios sin guardar. ¿Querés salir igual?',
          { icon: 'warning', confirmText: 'Salir igual', cancelText: 'Quedarme', useText: true }
        );
        if (!ok) return;
      } else if (formChanged) {
        return;
      }

      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      overlay.classList.remove('is-open');
      document.body.style.overflow = '';
      formChanged = false;

      // Limpiar URL si tiene parametros de edicion
      const url = new URL(window.location.href);
      if (url.searchParams.has('new') || url.searchParams.has('editar')) {
        url.searchParams.delete('new');
        url.searchParams.delete('editar');
        window.history.replaceState({}, '', url.toString());
      }
    } finally {
      closeDrawerOpen = false;
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
    if (e.key !== 'Escape') return;

    if (productsModal && !productsModal.hidden) {
      closeProductsModal();
      return;
    }

    if (purchasedProductsModal && !purchasedProductsModal.hidden) {
      closePurchasedProductsModal();
      return;
    }

    if (comprasModal && !comprasModal.hidden) {
      closeComprasModal();
      return;
    }

    if (drawer.classList.contains('is-open')) {
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
        formChanged = false;
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
    cuitInput.addEventListener('input', function() {
      // Solo dígitos, máximo 11
      const digits = this.value.replace(/\D/g, '').slice(0, 11);

      // Formato: XX-XXXXXXXX-X
      if (digits.length <= 2) {
        this.value = digits;
      } else if (digits.length <= 10) {
        this.value = `${digits.slice(0, 2)}-${digits.slice(2)}`;
      } else {
        this.value = `${digits.slice(0, 2)}-${digits.slice(2, 10)}-${digits.slice(10)}`;
      }
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
    form.addEventListener('input', () => {
      formChanged = true;
    });

    form.addEventListener('submit', function(e) {
      const nombre = form.querySelector('input[name="nombre"]');

      if (!nombre || nombre.value.trim() === '') {
        e.preventDefault();
        nombre?.focus();
        if (window.Notif && typeof window.Notif.advertencia === 'function') {
          window.Notif.advertencia('El nombre del proveedor es obligatorio.');
        } else if (window.showToast) {
          window.showToast('El nombre del proveedor es obligatorio', 'error');
        }
        return;
      }

      // Deshabilitar botón para evitar doble submit
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Guardando...';
      }
      formChanged = false;
    });
  }

  window.addEventListener('beforeunload', (e) => {
    if (formChanged && drawer.classList.contains('is-open')) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // ========== Toggle Forms (activar/desactivar) ==========

  document.querySelectorAll('.toggle-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const _form = this;
      const valor = _form.querySelector('input[name="valor"]').value;
      const action = valor === '0' ? 'desactivar' : 'activar';
      Notif.confirmar(
        `${action.charAt(0).toUpperCase() + action.slice(1)} proveedor`,
        `¿Seguro que querés ${action} este proveedor?`,
        {
          icon: 'warning',
          confirmText: `Sí, ${action}`,
          cancelText: 'Cancelar',
          useText: true,
          danger: action === 'desactivar'
        }
      ).then(ok => { if (ok) _form.submit(); });
    });
  });

  document.querySelectorAll('.relink-all-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const _form = this;
      Notif.confirmar(
        'Re-vincular todos los proveedores',
        '<p>Se intentara actualizar el nombre visible y vincular productos legacy para <strong>todos</strong> los proveedores.</p><p>Es seguro, pero puede tardar unos segundos si hay muchos productos.</p>',
        { icon: 'warning', confirmText: 'Si, re-vincular todo', cancelText: 'Cancelar' }
      ).then(ok => { if (ok) _form.submit(); });
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

  // ========== Compras Modal ==========

  const comprasModal = document.getElementById('provComprasModal');
  const comprasModalOverlay = document.getElementById('provComprasModalOverlay');
  let lastComprasTrigger = null;

  function openComprasModal(trigger) {
    if (!comprasModal || !comprasModalOverlay) return;
    lastComprasTrigger = trigger || null;
    comprasModal.hidden = false;
    comprasModalOverlay.hidden = false;
    comprasModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeComprasModal() {
    if (!comprasModal || !comprasModalOverlay) return;
    comprasModal.hidden = true;
    comprasModalOverlay.hidden = true;
    comprasModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = drawer?.classList.contains('is-open') ? 'hidden' : '';
    if (lastComprasTrigger) {
      lastComprasTrigger.focus();
    }
  }

  // ========== Products Modal ==========

  const productsModal = document.getElementById('provProductsModal');
  const productsModalOverlay = document.getElementById('provProductsModalOverlay');
  let lastProductsTrigger = null;

  function openProductsModal(trigger) {
    if (!productsModal || !productsModalOverlay) return;
    lastProductsTrigger = trigger || null;
    productsModal.hidden = false;
    productsModalOverlay.hidden = false;
    productsModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeProductsModal() {
    if (!productsModal || !productsModalOverlay) return;
    productsModal.hidden = true;
    productsModalOverlay.hidden = true;
    productsModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = drawer?.classList.contains('is-open') ? 'hidden' : '';
    if (lastProductsTrigger) {
      lastProductsTrigger.focus();
    }
  }

  document.querySelectorAll('[data-open-products-modal]').forEach(button => {
    button.addEventListener('click', () => openProductsModal(button));
  });

  document.querySelectorAll('[data-close-products-modal]').forEach(button => {
    button.addEventListener('click', closeProductsModal);
  });

  if (productsModalOverlay) {
    productsModalOverlay.addEventListener('click', closeProductsModal);
  }

  const purchasedProductsModal = document.getElementById('provPurchasedProductsModal');
  const purchasedProductsModalOverlay = document.getElementById('provPurchasedProductsModalOverlay');
  let lastPurchasedProductsTrigger = null;

  function openPurchasedProductsModal(trigger) {
    if (!purchasedProductsModal || !purchasedProductsModalOverlay) return;
    lastPurchasedProductsTrigger = trigger || null;
    purchasedProductsModal.hidden = false;
    purchasedProductsModalOverlay.hidden = false;
    purchasedProductsModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closePurchasedProductsModal() {
    if (!purchasedProductsModal || !purchasedProductsModalOverlay) return;
    purchasedProductsModal.hidden = true;
    purchasedProductsModalOverlay.hidden = true;
    purchasedProductsModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = drawer?.classList.contains('is-open') ? 'hidden' : '';
    if (lastPurchasedProductsTrigger) {
      lastPurchasedProductsTrigger.focus();
    }
  }

  document.querySelectorAll('[data-open-purchased-products-modal]').forEach(button => {
    button.addEventListener('click', () => openPurchasedProductsModal(button));
  });

  document.querySelectorAll('[data-close-purchased-products-modal]').forEach(button => {
    button.addEventListener('click', closePurchasedProductsModal);
  });

  if (purchasedProductsModalOverlay) {
    purchasedProductsModalOverlay.addEventListener('click', closePurchasedProductsModal);
  }

  document.querySelectorAll('[data-open-compras-modal]').forEach(button => {
    button.addEventListener('click', () => openComprasModal(button));
  });

  document.querySelectorAll('[data-close-compras-modal]').forEach(button => {
    button.addEventListener('click', closeComprasModal);
  });

  if (comprasModalOverlay) {
    comprasModalOverlay.addEventListener('click', closeComprasModal);
  }

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
