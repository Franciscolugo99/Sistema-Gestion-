// public/assets/js/caja_ventas_recientes.js
document.addEventListener("DOMContentLoaded", () => {
  const API_BASE = "api/index.php";
  const PAPER_KEY = "kiosco-ticket-paper";
  const PRINT_DEFAULTS = window.FLUS_PRINT_DEFAULTS || {};
  const PRINT_GLOBAL_DEFAULTS = PRINT_DEFAULTS.global || {};
  const PRINT_TERMINAL_DEFAULTS = PRINT_DEFAULTS.terminal || {};

  const btnVentasRecientes = document.getElementById("btnVentasRecientes");
  const modal = document.getElementById("ventasRecientesModal");
  const list = document.getElementById("ventasRecientesList");
  const status = document.getElementById("ventasRecientesStatus");
  const ticketPreviewModal = document.getElementById("ticketPreviewModal");
  const ticketPreviewFrame = document.getElementById("ticketPreviewFrame");
  const ticketPreviewVentaId = document.getElementById("ticketPreviewVentaId");
  const ticketPreviewOpen = document.getElementById("ticketPreviewOpen");
  const ticketPreviewPrint = document.getElementById("ticketPreviewPrint");

  if (!btnVentasRecientes || !modal || !list) return;

  let state = { ventas: [], permissions: {} };

  const moneyFmt = new Intl.NumberFormat("es-AR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  function getPaper() {
    const fallbackPaper =
      (PRINT_TERMINAL_DEFAULTS.ticket_paper &&
      PRINT_TERMINAL_DEFAULTS.ticket_paper !== "inherit"
        ? PRINT_TERMINAL_DEFAULTS.ticket_paper
        : PRINT_GLOBAL_DEFAULTS.ticket_paper) || "80";
    const value = String(localStorage.getItem(PAPER_KEY) || fallbackPaper).trim();
    return value === "58" ? "58" : "80";
  }

  function buildTicketUrl(ventaId, opts = {}) {
    const params = new URLSearchParams({
      venta_id: String(ventaId),
      paper: getPaper(),
    });
    if (opts.autoprint) params.set("autoprint", "1");
    return `ticket.php?${params.toString()}`;
  }

  function focusCajaInput() {
    setTimeout(() => document.getElementById("codigo")?.focus?.(), 0);
  }

  function closeTicketPreview() {
    if (!ticketPreviewModal) return;
    ticketPreviewModal.classList.add("hidden");
    ticketPreviewModal.setAttribute("aria-hidden", "true");
    if (ticketPreviewFrame) ticketPreviewFrame.src = "about:blank";
    if (ticketPreviewOpen) ticketPreviewOpen.href = "#";
    if (ticketPreviewVentaId) ticketPreviewVentaId.textContent = "0";
    focusCajaInput();
  }

  function openTicketPreview(ventaId) {
    if (!ticketPreviewModal || !ticketPreviewFrame) {
      window.open(buildTicketUrl(ventaId), "_blank", "noopener");
      return;
    }

    const url = buildTicketUrl(ventaId);
    ticketPreviewModal.classList.remove("hidden");
    ticketPreviewModal.setAttribute("aria-hidden", "false");
    if (ticketPreviewVentaId) ticketPreviewVentaId.textContent = String(ventaId);
    if (ticketPreviewOpen) ticketPreviewOpen.href = url;
    ticketPreviewFrame.src = url;
  }

  function printTicketSilently(ventaId) {
    const iframe = document.createElement("iframe");
    iframe.style.display = "none";
    iframe.src = buildTicketUrl(ventaId, { autoprint: true });
    iframe.onload = () => window.setTimeout(() => iframe.remove(), 4000);
    document.body.appendChild(iframe);
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[char]);
  }

  function money(value) {
    return "$" + moneyFmt.format(Number(value) || 0);
  }

  function setStatus(message, kind = "info") {
    if (!status) return;
    status.textContent = message || "";
    status.dataset.kind = kind;
    status.classList.toggle("hidden", !message);
  }

  function closeModal() {
    modal.classList.add("hidden");
    modal.setAttribute("aria-hidden", "true");
    focusCajaInput();
  }

  function formatFecha(value) {
    const raw = String(value || "").trim();
    if (!raw) return "-";
    const date = new Date(raw.replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleString("es-AR", {
      day: "2-digit",
      month: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  function formatCantidad(value) {
    const number = Number(value) || 0;
    return Number.isInteger(number)
      ? String(number)
      : number.toFixed(3).replace(/\.?0+$/, "");
  }

  function ventaAnulada(venta) {
    return String(venta?.estado || "").toUpperCase() === "ANULADA";
  }

  function ventaParcial(venta) {
    return String(venta?.estado || "").toUpperCase().includes("PARCIAL");
  }

  function tieneItemsDisponibles(venta) {
    return Array.isArray(venta?.items) && venta.items.some((item) => Number(item.cantidad_disponible) > 0);
  }

  function badge(venta) {
    if (venta.facturada) return '<span class="ventas-recientes-badge ventas-recientes-badge--info">Fiscal</span>';
    if (ventaAnulada(venta)) return '<span class="ventas-recientes-badge ventas-recientes-badge--danger">Anulada</span>';
    if (ventaParcial(venta)) return '<span class="ventas-recientes-badge ventas-recientes-badge--warn">Parcial</span>';
    return '<span class="ventas-recientes-badge ventas-recientes-badge--ok">Activa</span>';
  }

  function skeleton() {
    list.innerHTML = Array.from({ length: 4 }, () => `
      <div class="ventas-recientes-row ventas-recientes-row--skeleton">
        <div class="ventas-recientes-row__main">
          <span></span><strong></strong><em></em>
        </div>
        <div class="ventas-recientes-row__actions"><span></span><span></span></div>
      </div>
    `).join("");
  }

  async function loadVentas() {
    skeleton();
    setStatus("Cargando ultimas ventas...", "info");

    try {
      const data = await window.apiJson(`${API_BASE}?action=caja_ventas_recientes&limit=12`, {}, { method: "GET" });
      state = {
        ventas: Array.isArray(data.ventas) ? data.ventas : [],
        permissions: data.permissions || {},
      };
      renderVentas();
    } catch (error) {
      console.error(error);
      list.innerHTML = "";
      setStatus(`No se pudieron cargar las ventas recientes: ${error?.message || error}`, "error");
    }
  }

  function renderVentas() {
    const ventas = state.ventas || [];
    const perms = state.permissions || {};

    if (!ventas.length) {
      list.innerHTML = `
        <div class="ventas-recientes-empty">
          <strong>No hay ventas en esta caja.</strong>
          <span>Cuando cobres un ticket, va a aparecer aca para reimprimir o revisar.</span>
        </div>
      `;
      setStatus("", "info");
      return;
    }

    setStatus("Solo se muestran ventas de la apertura activa en esta terminal.", "info");
    list.innerHTML = ventas.map((venta) => {
      const id = Number(venta.id) || 0;
      const resumen = String(venta.productos_resumen || "").trim() || `${Number(venta.items_count || 0)} item(s)`;
      const canAnularTotal = !!perms.can_anular_venta && !!venta.can_anular;
      const canAnularItems = !!perms.can_anular_items && !!venta.can_anular_items && tieneItemsDisponibles(venta);
      const totalLabel = ventaParcial(venta) ? "Anular restante" : "Anular total";

      return `
        <section class="ventas-recientes-row" data-venta-id="${id}">
          <div class="ventas-recientes-row__main">
            <div class="ventas-recientes-row__top">
              <strong>Venta #${id}</strong>
              ${badge(venta)}
            </div>
            <div class="ventas-recientes-row__meta">
              <span>${escapeHtml(formatFecha(venta.fecha))}</span>
              <span>${escapeHtml(String(venta.medio_pago || "Sin medio"))}</span>
              <span>${escapeHtml(resumen)}</span>
            </div>
          </div>
          <div class="ventas-recientes-row__total">${escapeHtml(money(venta.total || 0))}</div>
          <div class="ventas-recientes-row__actions">
            <button type="button" class="btn btn-secondary btn-sm" data-vr-action="preview" data-venta-id="${id}">Ver ticket</button>
            <button type="button" class="btn btn-secondary btn-sm" data-vr-action="print" data-venta-id="${id}">Imprimir</button>
            ${canAnularItems ? `<button type="button" class="btn btn-secondary btn-sm" data-vr-action="items" data-venta-id="${id}">Anular items</button>` : ""}
            ${canAnularTotal ? `<button type="button" class="btn btn-danger btn-sm" data-vr-action="total" data-venta-id="${id}">${totalLabel}</button>` : ""}
          </div>
        </section>
      `;
    }).join("");
  }

  function findVenta(ventaId) {
    return (state.ventas || []).find((venta) => Number(venta.id) === Number(ventaId));
  }

  function closeItemPanels() {
    list.querySelectorAll(".ventas-recientes-items-panel").forEach((panel) => panel.remove());
  }

  function renderItemsPanel(venta) {
    const ventaId = Number(venta?.id || 0);
    const row = list.querySelector(`.ventas-recientes-row[data-venta-id="${ventaId}"]`);
    if (!row) return;

    closeItemPanels();
    const items = (venta.items || []).filter((item) => Number(item.cantidad_disponible) > 0);
    if (!items.length) {
      setStatus("Esta venta ya no tiene items disponibles para anular.", "warn");
      return;
    }

    const panel = document.createElement("div");
    panel.className = "ventas-recientes-items-panel";
    panel.innerHTML = `
      <div class="ventas-recientes-items-panel__head">
        <strong>Anular items de venta #${ventaId}</strong>
        <button type="button" class="ventas-recientes-items-panel__close" data-vr-panel-close>Cerrar</button>
      </div>
      <div class="ventas-recientes-items-panel__table">
        ${items.map((item) => {
          const itemId = Number(item.id) || 0;
          const disponible = Number(item.cantidad_disponible) || 0;
          const step = Number.isInteger(disponible) ? "1" : "0.001";
          return `
            <label class="ventas-recientes-item" data-item-id="${itemId}">
              <input type="checkbox" class="js-vr-item-check" data-item-id="${itemId}">
              <span class="ventas-recientes-item__name">${escapeHtml(item.nombre || `Item #${itemId}`)}</span>
              <span class="ventas-recientes-item__available">${formatCantidad(disponible)} disp.</span>
              <input type="number" class="js-vr-item-qty" data-item-id="${itemId}" min="${step}" max="${disponible}" step="${step}" value="${disponible}" disabled>
            </label>
          `;
        }).join("")}
      </div>
      <div class="ventas-recientes-items-panel__reason">
        <label for="ventasRecientesMotivo${ventaId}">Motivo</label>
        <input type="text" id="ventasRecientesMotivo${ventaId}" maxlength="255" placeholder="Ej: devolucion, error de carga">
      </div>
      <div class="ventas-recientes-items-panel__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-vr-panel-close>Cancelar</button>
        <button type="button" class="btn btn-danger btn-sm" data-vr-confirm-items data-venta-id="${ventaId}">Confirmar devolucion</button>
      </div>
    `;
    row.insertAdjacentElement("afterend", panel);
  }

  async function anularTotal(ventaId) {
    const venta = findVenta(ventaId);
    if (!venta) return;

    const motivo = await Notif.prompt(
      ventaParcial(venta) ? "Anular restante" : "Anular venta",
      `Venta #${ventaId}. Se repone stock y se actualiza el cierre de caja.`,
      { placeholder: "Motivo opcional", confirmText: "Confirmar anulacion", cancelText: "Volver" },
    );
    if (motivo === null) return;

    try {
      await window.apiJson("api/index.php?action=anular_venta", {
        venta_id: ventaId,
        motivo: String(motivo || "").trim(),
      });
      window.location.reload();
    } catch (error) {
      console.error(error);
      Notif.error("No se pudo anular la venta: " + (error?.message || error));
    }
  }

  async function confirmarItems(button) {
    const ventaId = Number(button?.dataset?.ventaId || 0);
    const panel = button?.closest(".ventas-recientes-items-panel");
    if (!ventaId || !panel) return;

    const payloadItems = [];
    panel.querySelectorAll(".js-vr-item-check:checked").forEach((checkbox) => {
      const itemId = Number(checkbox.dataset.itemId || 0);
      const input = panel.querySelector(`.js-vr-item-qty[data-item-id="${itemId}"]`);
      const cantidad = Number(input?.value || 0);
      const max = Number(input?.max || 0);
      if (itemId > 0 && cantidad > 0) {
        payloadItems.push({ item_id: itemId, cantidad: Math.min(cantidad, max) });
      }
    });

    if (!payloadItems.length) {
      Notif.error("Selecciona al menos un item para anular.");
      return;
    }

    button.disabled = true;
    try {
      await window.apiJson("api/index.php?action=anular_items_venta", {
        venta_id: ventaId,
        motivo: String(panel.querySelector("input[id^='ventasRecientesMotivo']")?.value || "").trim(),
        items: payloadItems,
      });
      window.location.reload();
    } catch (error) {
      console.error(error);
      Notif.error("No se pudo procesar la devolucion: " + (error?.message || error));
      button.disabled = false;
    }
  }

  btnVentasRecientes.addEventListener("click", () => {
    modal.classList.remove("hidden");
    modal.setAttribute("aria-hidden", "false");
    loadVentas();
  });

  modal.querySelectorAll("[data-ventas-recientes-close]").forEach((el) => {
    el.addEventListener("click", closeModal);
  });

  list.addEventListener("change", (event) => {
    const checkbox = event.target.closest(".js-vr-item-check");
    if (!checkbox) return;
    const itemId = checkbox.dataset.itemId;
    const input = list.querySelector(`.js-vr-item-qty[data-item-id="${itemId}"]`);
    if (!input) return;
    input.disabled = !checkbox.checked;
    if (!checkbox.checked) input.value = input.max;
  });

  list.addEventListener("click", (event) => {
    const panelClose = event.target.closest("[data-vr-panel-close]");
    if (panelClose) {
      closeItemPanels();
      return;
    }

    const confirmButton = event.target.closest("[data-vr-confirm-items]");
    if (confirmButton) {
      confirmarItems(confirmButton);
      return;
    }

    const actionButton = event.target.closest("[data-vr-action]");
    if (!actionButton) return;
    const ventaId = Number(actionButton.dataset.ventaId || 0);
    if (!ventaId) return;

    const action = actionButton.dataset.vrAction;
    if (action === "preview") openTicketPreview(ventaId);
    if (action === "print") {
      printTicketSilently(ventaId);
      setStatus(`Enviando ticket de venta #${ventaId} a impresion.`, "info");
    }
    if (action === "items") renderItemsPanel(findVenta(ventaId));
    if (action === "total") anularTotal(ventaId);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (ticketPreviewModal && !ticketPreviewModal.classList.contains("hidden")) {
      closeTicketPreview();
      return;
    }
    if (!modal.classList.contains("hidden")) closeModal();
  });
});
