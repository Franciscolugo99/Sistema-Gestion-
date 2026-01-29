// public/assets/js/ventas-enhanced.js
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("ventasFilters") || document.getElementById("ventasForm");

  // =============================================
  // MEJORA 1: Loading states en toda la app
  // =============================================
  const showLoader = () => {
    const existing = document.getElementById("globalLoader");
    if (existing) return;

    const loader = document.createElement("div");
    loader.id = "globalLoader";
    loader.className = "global-loader";
    loader.innerHTML = `
      <div class="loader-content">
        <div class="spinner"></div>
        <p>Cargando...</p>
      </div>
    `;
    document.body.appendChild(loader);
  };

  const hideLoader = () => {
    const loader = document.getElementById("globalLoader");
    if (loader) loader.remove();
  };

  // Mostrar loader en submit de formularios
  if (form) {
    form.addEventListener("submit", () => showLoader());
  }

  // =============================================
  // MEJORA 2: Búsqueda de cliente con autocomplete
  // =============================================
  const clienteInput = document.getElementById("cliente");
  if (clienteInput) {
    let timeout;
    let currentResults = [];

    const createAutocomplete = () => {
      let div = document.getElementById("clienteAutocomplete");
      if (!div) {
        div = document.createElement("div");
        div.id = "clienteAutocomplete";
        div.className = "autocomplete-dropdown hidden";
        clienteInput.parentElement.appendChild(div);
      }
      return div;
    };

    clienteInput.addEventListener("input", (e) => {
      clearTimeout(timeout);
      const query = e.target.value.trim();

      if (query.length < 2) {
        createAutocomplete().classList.add("hidden");
        return;
      }

      timeout = setTimeout(async () => {
        try {
          const res = await fetch(
            `api/index.php?action=buscar_clientes&q=${encodeURIComponent(query)}`
          );
          const data = await res.json();

          if (data.success && data.clientes) {
            currentResults = data.clientes;
            showAutocompleteResults(data.clientes);
          }
        } catch (err) {
          console.error("Error buscando clientes:", err);
        }
      }, 300);
    });

    const showAutocompleteResults = (clientes) => {
      const dropdown = createAutocomplete();

      if (!clientes.length) {
        dropdown.innerHTML = '<div class="autocomplete-item empty">Sin resultados</div>';
        dropdown.classList.remove("hidden");
        return;
      }

      dropdown.innerHTML = clientes
        .slice(0, 8)
        .map(
          (c) => `
        <div class="autocomplete-item" data-cliente-id="${c.id}">
          <div class="ac-name">${c.nombre}</div>
          <div class="ac-meta">${c.documento || ""} ${c.email || ""}</div>
        </div>
      `
        )
        .join("");

      dropdown.classList.remove("hidden");

      // Click en resultado
      dropdown.querySelectorAll(".autocomplete-item[data-cliente-id]").forEach((item) => {
        item.addEventListener("click", () => {
          const clienteId = item.dataset.clienteId;
          const cliente = currentResults.find((c) => c.id == clienteId);
          if (cliente) {
            clienteInput.value = cliente.nombre;
            dropdown.classList.add("hidden");
          }
        });
      });
    };

    // Cerrar dropdown al hacer click fuera
    document.addEventListener("click", (e) => {
      if (!clienteInput.contains(e.target)) {
        const dropdown = document.getElementById("clienteAutocomplete");
        if (dropdown) dropdown.classList.add("hidden");
      }
    });
  }

  // =============================================
  // MEJORA 3: Acciones masivas (checkboxes)
  // =============================================
  const setupBulkActions = () => {
    const checkAll = document.getElementById("checkAll");
    const checkboxes = document.querySelectorAll(".row-checkbox");
    const bulkBar = document.getElementById("bulkActionsBar");

    if (!checkAll || !checkboxes.length) return;

    checkAll.addEventListener("change", () => {
      checkboxes.forEach((cb) => (cb.checked = checkAll.checked));
      updateBulkBar();
    });

    checkboxes.forEach((cb) => {
      cb.addEventListener("change", updateBulkBar);
    });

    const updateBulkBar = () => {
      const checked = Array.from(checkboxes).filter((cb) => cb.checked);
      const count = checked.length;

      if (!bulkBar) return;

      if (count > 0) {
        bulkBar.classList.remove("hidden");
        bulkBar.querySelector(".bulk-count").textContent = count;
      } else {
        bulkBar.classList.add("hidden");
      }
    };

    // Acción: Reimprimir tickets
    const btnReimprimir = document.getElementById("btnBulkReimprimir");
    if (btnReimprimir) {
      btnReimprimir.addEventListener("click", () => {
        const ids = Array.from(checkboxes)
          .filter((cb) => cb.checked)
          .map((cb) => cb.value);

        if (!ids.length) return;

        if (confirm(`¿Reimprimir ${ids.length} ticket(s)?`)) {
          window.open(`ticket_multiple.php?ids=${ids.join(",")}`, "_blank");
        }
      });
    }

    // Acción: Exportar seleccionadas
    const btnExportar = document.getElementById("btnBulkExportar");
    if (btnExportar) {
      btnExportar.addEventListener("click", () => {
        const ids = Array.from(checkboxes)
          .filter((cb) => cb.checked)
          .map((cb) => cb.value);

        if (!ids.length) return;

        window.location.href = `ventas.php?export=csv&ids=${ids.join(",")}`;
      });
    }
  };

  setupBulkActions();

  // =============================================
  // MEJORA 4: Shortcuts de teclado
  // =============================================
  document.addEventListener("keydown", (e) => {
    // Ctrl/Cmd + K: Focus en búsqueda
    if ((e.ctrlKey || e.metaKey) && e.key === "k") {
      e.preventDefault();
      const ventaIdInput = document.getElementById("venta_id");
      if (ventaIdInput) ventaIdInput.focus();
    }

    // Ctrl/Cmd + F: Focus en búsqueda de cliente
    if ((e.ctrlKey || e.metaKey) && e.key === "f" && clienteInput) {
      e.preventDefault();
      clienteInput.focus();
    }

    // Ctrl/Cmd + L: Limpiar filtros
    if ((e.ctrlKey || e.metaKey) && e.key === "l") {
      e.preventDefault();
      const clearLink = document.getElementById("ventasClear");
      if (clearLink) clearLink.click();
    }
  });

  // =============================================
  // MEJORA 5: Vista compacta/expandida
  // =============================================
  const VIEW_KEY = "flus-ventas-view-mode";
  const btnToggleView = document.getElementById("btnToggleView");
  const table = document.querySelector(".ventas-table");

  const getViewMode = () => {
    try {
      return localStorage.getItem(VIEW_KEY) || "normal";
    } catch {
      return "normal";
    }
  };

  const setViewMode = (mode) => {
    try {
      localStorage.setItem(VIEW_KEY, mode);
    } catch {}

    if (table) {
      table.classList.toggle("compact-view", mode === "compact");
    }

    if (btnToggleView) {
      const icon = btnToggleView.querySelector("i");
      if (icon) {
        icon.className = mode === "compact" ? "icon-expand" : "icon-compress";
      }
      btnToggleView.title = mode === "compact" ? "Vista expandida" : "Vista compacta";
    }
  };

  if (btnToggleView) {
    setViewMode(getViewMode());

    btnToggleView.addEventListener("click", () => {
      const current = getViewMode();
      setViewMode(current === "compact" ? "normal" : "compact");
    });
  }

  // =============================================
  // MEJORA 6: Notificaciones toast
  // =============================================
  window.showToast = (message, type = "info") => {
    const toast = document.createElement("div");
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
      <div class="toast-content">
        <span class="toast-icon">${getToastIcon(type)}</span>
        <span class="toast-message">${message}</span>
      </div>
    `;

    document.body.appendChild(toast);

    setTimeout(() => toast.classList.add("show"), 10);
    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  };

  const getToastIcon = (type) => {
    const icons = {
      success: "✓",
      error: "✕",
      warning: "⚠",
      info: "ℹ",
    };
    return icons[type] || icons.info;
  };

  // =============================================
  // MEJORA 7: Previsualización rápida de venta
  // =============================================
  const setupQuickPreview = () => {
    document.querySelectorAll(".row-quick-preview").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.preventDefault();
        const ventaId = btn.dataset.ventaId;

        showLoader();

        try {
          const res = await fetch(`api/index.php?action=venta_preview&id=${ventaId}`);
          const data = await res.json();

          if (data.ok || data.success) {
            showPreviewModal(data.venta);
          } else {
            showToast(data.message || "Error al cargar", "error");
          }
        } catch (err) {
          showToast("Error de conexión", "error");
        } finally {
          hideLoader();
        }
      });
    });
  };

  const showPreviewModal = (venta) => {
    const modal = document.getElementById("quickPreviewModal");
    if (!modal) return;

    // Rellenar datos
    modal.querySelector(".preview-id").textContent = `#${venta.id}`;
    modal.querySelector(".preview-fecha").textContent = venta.fecha;
    modal.querySelector(".preview-cliente").textContent = venta.cliente || "Consumidor Final";
    modal.querySelector(".preview-total").textContent = formatMoney(venta.total);

    // Items
    const itemsList = modal.querySelector(".preview-items");
    itemsList.innerHTML = venta.items
      .map(
        (item) => `
      <div class="preview-item">
        <span>${item.cantidad}x ${item.nombre}</span>
        <span>${formatMoney(item.subtotal)}</span>
      </div>
    `
      )
      .join("");

    modal.classList.remove("hidden");
  };

  setupQuickPreview();

  // Cerrar modal
  const closePreview = document.getElementById("closePreviewModal");
  if (closePreview) {
    closePreview.addEventListener("click", () => {
      document.getElementById("quickPreviewModal")?.classList.add("hidden");
    });
  }

  // =============================================
  // MEJORA 8: Enviar ticket por WhatsApp/Email
  // =============================================
  document.querySelectorAll(".btn-send-ticket").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const ventaId = btn.dataset.ventaId;
      const method = btn.dataset.method; // 'whatsapp' o 'email'

      if (method === "whatsapp") {
        const phone = prompt("Número de teléfono (con código de país):");
        if (!phone) return;

        showLoader();

        try {
          const res = await fetch("api/index.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              action: "send_ticket_whatsapp",
              venta_id: ventaId,
              phone,
            }),
          });

          const data = await res.json();

          if (data.ok || data.success) {
            showToast("Mensaje enviado por WhatsApp", "success");
          } else {
            showToast(data.message || "Error al enviar", "error");
          }
        } catch (err) {
          showToast("Error de conexión", "error");
        } finally {
          hideLoader();
        }
      } else if (method === "email") {
        const email = prompt("Email del cliente:");
        if (!email) return;

        showLoader();

        try {
          const res = await fetch("api/index.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              action: "send_ticket_email",
              venta_id: ventaId,
              email,
            }),
          });

          const data = await res.json();

          if (data.ok || data.success) {
            showToast("Ticket enviado por email", "success");
          } else {
            showToast(data.message || "Error al enviar", "error");
          }
        } catch (err) {
          showToast("Error de conexión", "error");
        } finally {
          hideLoader();
        }
      }
    });
  });

  // =============================================
  // Helper: formatear moneda
  // =============================================
  const formatMoney = (value) => {
    return new Intl.NumberFormat("es-AR", {
      style: "currency",
      currency: "ARS",
    }).format(value);
  };

  // =============================================
  // CÓDIGO EXISTENTE (papel, filtros, etc.)
  // =============================================
  const btnScrollTop = document.getElementById("btnScrollTop");
  if (btnScrollTop) {
    btnScrollTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  // [... resto del código original de ventas.js ...]
});