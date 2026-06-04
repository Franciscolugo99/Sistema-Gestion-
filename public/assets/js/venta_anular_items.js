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
  modal.className = "venta-anular-items-modal";

  const panel = document.createElement("div");
  panel.className = "venta-anular-items-modal__panel";

  panel.innerHTML = `
    <div class="venta-anular-items-modal__head">
      <div>
        <h3 class="venta-anular-items-modal__title">Anular items de la venta #${ventaId}</h3>
        <p class="venta-anular-items-modal__copy">
          Selecciona las cantidades a devolver. Se repondra solo el stock afectado.
        </p>
      </div>
      <button type="button" id="btnCerrarModalAnularItems" class="venta-anular-items-modal__close" aria-label="Cerrar">
        &times;
      </button>
    </div>

    <div class="venta-anular-items-modal__table-wrap">
      <table class="venta-anular-items-modal__table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="right">Disponible</th>
            <th class="right">Cantidad</th>
          </tr>
        </thead>
        <tbody>
          ${items.map((item) => `
            <tr data-modal-item-id="${item.itemId}">
              <td>
                <label class="venta-anular-items-modal__item-label">
                  <input type="checkbox" class="js-anular-item-check" data-item-id="${item.itemId}">
                  <span>${escapeHtml(item.nombre || ("Item #" + item.itemId))}</span>
                </label>
              </td>
              <td class="right muted">${formatQty(item.cantidadDisponible)}</td>
              <td class="right">
                <input
                  type="number"
                  class="js-anular-item-cantidad venta-anular-items-modal__qty"
                  data-item-id="${item.itemId}"
                  min="0.001"
                  max="${item.cantidadDisponible}"
                  step="${Number.isInteger(item.cantidadDisponible) ? "1" : "0.001"}"
                  value="${item.cantidadDisponible}"
                  disabled
                >
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>

    <div class="venta-anular-items-modal__field">
      <label for="motivoAnularItems">Motivo</label>
      <input
        id="motivoAnularItems"
        type="text"
        maxlength="255"
        placeholder="Opcional"
      >
    </div>

    <div class="venta-anular-items-modal__actions">
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
