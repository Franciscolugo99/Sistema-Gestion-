document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnAnularItems");
  if (!btn) return;

  btn.addEventListener("click", () => {
    const ventaId = parseInt(btn.dataset.ventaId || "0", 10);
    if (!ventaId) return;

    abrirModalAnularItems(ventaId);
  });
});

function abrirModalAnularItems(ventaId) {
  const rows = Array.from(document.querySelectorAll("#tabla-items-venta tbody tr[data-item-id]"));
  const items = rows
    .map((row) => ({
      itemId: parseInt(row.dataset.itemId || "0", 10),
      nombre: String(row.dataset.nombre || "").trim(),
      cantidadDisponible: parseFloat(row.dataset.cantidadDisp || "0"),
    }))
    .filter((item) => item.itemId > 0 && item.cantidadDisponible > 0);

  if (!items.length) {
    Notif.info("No quedan items disponibles para devolver en esta venta.");
    return;
  }

  const existing = document.getElementById("modal-anular-items");
  if (existing) existing.remove();

  const modal = document.createElement("div");
  modal.id = "modal-anular-items";
  modal.style.cssText = [
    "position:fixed",
    "inset:0",
    "background:rgba(15,23,42,.55)",
    "display:flex",
    "align-items:center",
    "justify-content:center",
    "padding:20px",
    "z-index:9999",
  ].join(";");

  const panel = document.createElement("div");
  panel.style.cssText = [
    "width:min(680px,100%)",
    "max-height:85vh",
    "overflow:auto",
    "background:var(--panel,#111827)",
    "border:1px solid var(--border-color,rgba(148,163,184,.22))",
    "border-radius:16px",
    "box-shadow:0 24px 64px rgba(15,23,42,.35)",
    "padding:20px",
    "color:var(--text,#e5e7eb)",
  ].join(";");

  panel.innerHTML = `
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px;">
      <div>
        <h3 style="margin:0 0 6px;font-size:1.1rem;">Anular items de la venta #${ventaId}</h3>
        <p style="margin:0;color:var(--muted,#94a3b8);font-size:.92rem;">
          Selecciona las cantidades a devolver. Se repondra solo el stock afectado.
        </p>
      </div>
      <button type="button" id="btnCerrarModalAnularItems"
        style="border:none;background:transparent;color:inherit;font-size:1.2rem;cursor:pointer;line-height:1;">
        ×
      </button>
    </div>

    <div style="border:1px solid var(--border-color,rgba(148,163,184,.22));border-radius:12px;overflow:hidden;">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="background:rgba(148,163,184,.08);">
            <th style="text-align:left;padding:10px 12px;">Item</th>
            <th style="text-align:right;padding:10px 12px;">Disponible</th>
            <th style="text-align:right;padding:10px 12px;">Cantidad</th>
          </tr>
        </thead>
        <tbody>
          ${items.map((item) => `
            <tr data-modal-item-id="${item.itemId}" style="border-top:1px solid var(--border-color,rgba(148,163,184,.14));">
              <td style="padding:10px 12px;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                  <input type="checkbox" class="js-anular-item-check" data-item-id="${item.itemId}">
                  <span>${escapeHtml(item.nombre || ("Item #" + item.itemId))}</span>
                </label>
              </td>
              <td style="padding:10px 12px;text-align:right;color:var(--muted,#94a3b8);">${formatQty(item.cantidadDisponible)}</td>
              <td style="padding:10px 12px;text-align:right;">
                <input
                  type="number"
                  class="js-anular-item-cantidad"
                  data-item-id="${item.itemId}"
                  min="0.001"
                  max="${item.cantidadDisponible}"
                  step="${Number.isInteger(item.cantidadDisponible) ? "1" : "0.001"}"
                  value="${item.cantidadDisponible}"
                  disabled
                  style="width:92px;padding:7px 8px;border-radius:8px;border:1px solid var(--border-color,rgba(148,163,184,.24));background:transparent;color:inherit;text-align:right;"
                >
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>

    <div style="margin-top:14px;">
      <label for="motivoAnularItems" style="display:block;font-size:.92rem;margin-bottom:6px;">Motivo</label>
      <input
        id="motivoAnularItems"
        type="text"
        maxlength="255"
        placeholder="Opcional"
        style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:10px;border:1px solid var(--border-color,rgba(148,163,184,.24));background:transparent;color:inherit;"
      >
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
      <button type="button" id="btnCancelarAnularItems" class="btn btn-secondary">Cancelar</button>
      <button type="button" id="btnConfirmarAnularItems" class="btn btn-danger">Confirmar devolucion</button>
    </div>
  `;

  modal.appendChild(panel);
  document.body.appendChild(modal);

  const close = () => modal.remove();

  modal.addEventListener("click", (event) => {
    if (event.target === modal) close();
  });

  panel.querySelector("#btnCerrarModalAnularItems")?.addEventListener("click", close);
  panel.querySelector("#btnCancelarAnularItems")?.addEventListener("click", close);

  panel.querySelectorAll(".js-anular-item-check").forEach((checkbox) => {
    checkbox.addEventListener("change", () => {
      const itemId = checkbox.dataset.itemId;
      const input = panel.querySelector(`.js-anular-item-cantidad[data-item-id="${itemId}"]`);
      if (!input) return;
      input.disabled = !checkbox.checked;
      if (!checkbox.checked) {
        input.value = input.max;
      }
    });
  });

  panel.querySelector("#btnConfirmarAnularItems")?.addEventListener("click", async (event) => {
    const trigger = event.currentTarget;
    if (trigger.dataset.loading === "1") return;

    const payloadItems = [];

    panel.querySelectorAll(".js-anular-item-check:checked").forEach((checkbox) => {
      const itemId = parseInt(checkbox.dataset.itemId || "0", 10);
      const input = panel.querySelector(`.js-anular-item-cantidad[data-item-id="${itemId}"]`);
      const cantidad = parseFloat(input?.value || "0");
      const cantidadMax = parseFloat(input?.max || "0");

      if (itemId > 0 && cantidad > 0) {
        payloadItems.push({
          item_id: itemId,
          cantidad: Math.min(cantidad, cantidadMax),
        });
      }
    });

    if (!payloadItems.length) {
      Notif.error("Selecciona al menos un item para devolver.");
      return;
    }

    const motivo = String(panel.querySelector("#motivoAnularItems")?.value || "").trim();
    trigger.dataset.loading = "1";
    trigger.disabled = true;

    try {
      await window.apiJson("api/index.php?action=anular_items_venta", {
        venta_id: ventaId,
        motivo,
        items: payloadItems,
      });

      close();
      try {
        if (window.Notif && typeof Notif.exito === "function") {
          Notif.exito("La devolucion se proceso correctamente.");
        }
      } catch (notifError) {
        console.warn("No se pudo mostrar la notificacion de exito", notifError);
      }

      window.location.reload();
    } catch (error) {
      console.error(error);
      Notif.error("No se pudo procesar la devolucion: " + (error?.message || error));
      delete trigger.dataset.loading;
      trigger.disabled = false;
    }
  });

  document.addEventListener("keydown", function onKeyDown(event) {
    if (event.key !== "Escape") return;
    document.removeEventListener("keydown", onKeyDown);
    if (document.getElementById("modal-anular-items")) close();
  }, { once: true });
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function formatQty(value) {
  const number = parseFloat(value || "0");
  return Number.isInteger(number) ? String(number) : number.toFixed(3).replace(/\.?0+$/, "");
}
