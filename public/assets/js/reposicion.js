// FLUS — reposicion.js
// Funcionalidades:
//   • Quick Order: checkboxes por proveedor + seleccionar críticos
//   • Controles de cantidad +/−
//   • Totales por proveedor y total global en tiempo real
//   • Generar orden de compra (submit a form oculto con anti-doble-click)
//   • Prevención de doble submit en forms de config

(() => {
  /* ── Helpers ───────────────────────────────────────────────────────── */

  const fmt = (n) =>
    n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  /* ── Recalcular total de un proveedor y total global ────────────────── */

  const recalcProveedor = (section) => {
    let total     = 0;
    let selected  = 0;

    section.querySelectorAll('.repo-item').forEach((row) => {
      const cb  = row.querySelector('.repo-check-item');
      if (!cb?.checked) return;

      const qty   = parseFloat(row.querySelector('.qty-input')?.value) || 0;
      const cost  = parseFloat(row.dataset.costo) || 0;
      const sub   = qty * cost;
      total      += sub;
      selected++;

      const subtotalEl = row.querySelector('.subtotal-item');
      if (subtotalEl) subtotalEl.textContent = '$' + fmt(sub);
    });

    // Actualizar label total proveedor
    const totalEl = section.querySelector('.total-proveedor');
    if (totalEl) totalEl.textContent = fmt(total);

    // Habilitar/deshabilitar botón de generar
    const btnGen = section.querySelector('.btn-generar-orden');
    if (btnGen) {
      const provId = parseInt(btnGen.dataset.proveedor || section.dataset.proveedorId || '0', 10) || 0;

      // Si el grupo es "Sin proveedor" (0), exigir que se elija proveedor destino
      let canGenerate = (selected > 0);
      if (provId === 0) {
        const sel = section.querySelector('.repo-proveedor-destino');
        const dest = parseInt(sel?.value || '0', 10) || 0;
        canGenerate = canGenerate && (dest > 0);
      }

      btnGen.disabled = !canGenerate;
    }

    recalcGlobal();
  };

  const recalcGlobal = () => {
    let global = 0;

    document.querySelectorAll('.proveedor-section').forEach((section) => {
      section.querySelectorAll('.repo-item').forEach((row) => {
        const cb = row.querySelector('.repo-check-item');
        if (!cb?.checked) return;
        const qty  = parseFloat(row.querySelector('.qty-input')?.value) || 0;
        const cost = parseFloat(row.dataset.costo) || 0;
        global    += qty * cost;
      });
    });

    const el = document.getElementById('total-global');
    if (el) el.textContent = fmt(global);
  };

  /* ── Sincronizar check-all de un proveedor ──────────────────────────── */

  const syncCheckAll = (section) => {
    const all     = section.querySelectorAll('.repo-check-item');
    const checked = section.querySelectorAll('.repo-check-item:checked');
    const ca      = section.querySelector('.repo-check-all');
    if (!ca) return;
    ca.checked       = all.length > 0 && all.length === checked.length;
    ca.indeterminate = checked.length > 0 && checked.length < all.length;
  };

  /* ── Inicializar sección de proveedor ───────────────────────────────── */

  const initSection = (section) => {
    // ── Check-all ────────────────────────────────────────────────────────
    section.querySelector('.repo-check-all')?.addEventListener('change', (e) => {
      section.querySelectorAll('.repo-check-item').forEach((cb) => {
        cb.checked = e.currentTarget.checked;
      });
      recalcProveedor(section);
    });

    // ── Checkboxes individuales ──────────────────────────────────────────
    section.querySelectorAll('.repo-check-item').forEach((cb) => {
      cb.addEventListener('change', () => {
        syncCheckAll(section);
        recalcProveedor(section);
      });
    });

    // ── Botones +/− ──────────────────────────────────────────────────────
    section.querySelectorAll('.qty-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const pid   = btn.dataset.producto;
        const input = section.querySelector(`.qty-input[data-producto="${pid}"]`);
        if (!input) return;

        const step = parseFloat(input.step) || 1;
        let val    = parseFloat(input.value) || 0;

        if (btn.classList.contains('qty-minus')) {
          val = Math.max(0, val - step);
        } else {
          val = val + step;
        }

        input.value = val;
        recalcProveedor(section);
      });
    });

    // ── Inputs de cantidad ───────────────────────────────────────────────
    section.querySelectorAll('.qty-input').forEach((input) => {
      const sanitize = () => {
        let v = parseFloat(input.value);
        if (isNaN(v) || v < 0) { v = 0; input.value = 0; }
      };

      input.addEventListener('change', () => { sanitize(); recalcProveedor(section); });
      input.addEventListener('input',  () => recalcProveedor(section));
    });

    // ── Botón "Seleccionar críticos" ─────────────────────────────────────
    section.querySelector('.btn-select-criticos')?.addEventListener('click', () => {
      section.querySelectorAll('.repo-item').forEach((row) => {
        const cb = row.querySelector('.repo-check-item');
        if (!cb) return;
        cb.checked = row.classList.contains('repo-item--critico');
      });
      syncCheckAll(section);
      recalcProveedor(section);
    });

    // ── Botón "Generar orden de compra" ──────────────────────────────────
    section.querySelector('.btn-generar-orden')?.addEventListener('click', (e) => {
      const btn        = e.currentTarget;
      let proveedorId = parseInt(btn.dataset.proveedor || '0', 10) || 0;
      const grupoOriginal = proveedorId; // 0 si es "Sin proveedor"

      // Si es grupo sin proveedor, usar proveedor destino
      if (proveedorId === 0) {
        const sel = section.querySelector('.repo-proveedor-destino');
        proveedorId = parseInt(sel?.value || '0', 10) || 0;
        if (!proveedorId) {
          alert('Seleccioná un proveedor destino para generar la orden de compra.');
          return;
        }
      }

      // Recopilar productos marcados con cantidad > 0
      const productos = [];
      section.querySelectorAll('.repo-item').forEach((row) => {
        const cb = row.querySelector('.repo-check-item');
        if (!cb?.checked) return;

        const id      = parseInt(row.dataset.productoId);
        const cantidad = parseFloat(row.querySelector('.qty-input')?.value) || 0;
        const costo    = parseFloat(row.dataset.costo) || 0;

        if (id > 0 && cantidad > 0) {
          productos.push({ id, cantidad, costo });
        }
      });

      if (productos.length === 0) {
        alert('No hay productos seleccionados con cantidad mayor a 0.');
        return;
      }

      const total = productos.reduce((s, p) => s + p.cantidad * p.costo, 0);
      const n     = productos.length;
      const msg   = `¿Generar orden BORRADOR para ${n} producto${n !== 1 ? 's' : ''}?\n\nTotal estimado: $${fmt(total)}`;

      if (!confirm(msg)) return;

      // Poblar form oculto y enviar
      document.getElementById('input-proveedor-id').value = String(proveedorId);
      document.getElementById('input-productos').value    = JSON.stringify(productos);

      // Si el grupo era "Sin proveedor", opcionalmente persistir el proveedor elegido
      let asignarProveedor = 0;
      if (grupoOriginal === 0) {
        const chk = section.querySelector('.repo-asignar-proveedor');
        asignarProveedor = chk && chk.checked ? 1 : 0;
      }
      const asignarEl = document.getElementById('input-asignar-proveedor');
      if (asignarEl) asignarEl.value = String(asignarProveedor);

      // Anti-doble-click
      btn.disabled    = true;
      btn.textContent = 'Generando…';

      document.getElementById('form-generar-orden').submit();
    });

    // Calcular totales iniciales (sin items seleccionados por defecto → $0,00)
    recalcProveedor(section);

    // Si existe selector de proveedor destino, recalcular al cambiar
    section.querySelector('.repo-proveedor-destino')?.addEventListener('change', () => {
      recalcProveedor(section);
    });
  };

  /* ── Prevención de doble submit en forms de config ──────────────────── */

  const initConfigForms = () => {
    document.querySelectorAll('form.config-form').forEach((form) => {
      form.addEventListener('submit', () => {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.disabled) return;
        btn.disabled            = true;
        btn.dataset.originalText = btn.textContent;
        btn.textContent         = 'Guardando…';
      });
    });
  };

  /* ── Auto-focus en buscador (vista config) ──────────────────────────── */

  const initSearch = () => {
    const s = document.querySelector('.repo-search-input');
    if (s) {
      try { s.focus({ preventScroll: true }); } catch { s.focus(); }
    }
  };

  /* ── Boot ───────────────────────────────────────────────────────────── */

  const boot = () => {
    document.querySelectorAll('.proveedor-section').forEach(initSection);
    initConfigForms();
    initSearch();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
