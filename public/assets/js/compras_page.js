(() => {
  let detalleTrigger = null;
  let anularTrigger = null;

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function estadoClase(value) {
    const estado = String(value || "").toUpperCase();
    return ["BORRADOR", "CONFIRMADA", "ANULADA"].includes(estado)
      ? estado
      : "BORRADOR";
  }

  function cerrarDetalle() {
    const modal = document.getElementById("modalDetalle");
    if (!modal) return;
    modal.classList.remove("active");
    modal.hidden = true;
    document.body.classList.remove("modal-open");
    detalleTrigger?.focus?.();
  }

  function verDetalle(id) {
    const compraId = Number(id) || 0;
    const modal = document.getElementById("modalDetalle");
    const content = document.getElementById("modalContent");
    const title = document.getElementById("modalTitle");
    if (!compraId || !modal || !content || !title) return;

    detalleTrigger = document.activeElement;
    modal.hidden = false;
    modal.classList.add("active");
    document.body.classList.add("modal-open");
    content.innerHTML = '<div class="loading">Cargando...</div>';
    modal.querySelector(".btn-close")?.focus();

    fetch(`api/compra_detalle.php?id=${compraId}`)
      .then((response) => {
        if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
        return response.json();
      })
      .then((data) => {
        if (data.error) {
          content.innerHTML = `<div class="msg msg-error">${escapeHtml(data.error)}</div>`;
          return;
        }

        const estado = estadoClase(data.estado);
        const comprobante = `${data.tipo_comp || ""} ${data.nro_comp || ""}`.trim();
        title.textContent = `Compra #${Number(data.id) || compraId} - ${String(data.proveedor || "Sin proveedor")}`;

        const items = Array.isArray(data.items) ? data.items : [];
        const rows = items.map((item) => `
          <tr>
            <td>
              <div class="detalle-producto-nombre">${escapeHtml(item.nombre)}</div>
              <div class="detalle-producto-codigo">${escapeHtml(item.codigo)}</div>
            </td>
            <td class="right">${escapeHtml(item.cantidad_fmt)}</td>
            <td class="right">${escapeHtml(item.costo_fmt)}</td>
            <td class="right">${escapeHtml(item.desc_item_fmt)}</td>
            <td class="right"><strong>${escapeHtml(item.subtotal_fmt)}</strong></td>
          </tr>
        `).join("");

        content.innerHTML = `
          <div class="detalle-info">
            <div><strong>Fecha</strong> ${escapeHtml(data.fecha)}</div>
            <div><strong>Estado</strong> <span class="estado-badge estado-${estado}">${escapeHtml(estado)}</span></div>
            <div><strong>Comprobante</strong> ${escapeHtml(comprobante || "Sin comprobante")}</div>
            ${data.obs ? `<div class="detalle-info-wide"><strong>Observacion</strong> ${escapeHtml(data.obs)}</div>` : ""}
          </div>
          <div class="table-wrapper detalle-table-wrapper">
            <table class="compras-table">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th class="right">Cantidad</th>
                  <th class="right">Costo unit.</th>
                  <th class="right">Desc. item</th>
                  <th class="right">Subtotal</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
              <tfoot>
                <tr><td colspan="4" class="right"><strong>BRUTO</strong></td><td class="right"><strong>${escapeHtml(data.total_bruto_fmt)}</strong></td></tr>
                <tr><td colspan="4" class="right"><strong>DESC. ITEMS</strong></td><td class="right"><strong>-${escapeHtml(data.descuento_items_total_fmt)}</strong></td></tr>
                <tr><td colspan="4" class="right"><strong>DESCUENTO GLOBAL</strong></td><td class="right"><strong>-${escapeHtml(data.descuento_total_fmt)}</strong></td></tr>
                <tr><td colspan="4" class="right"><strong>TOTAL</strong></td><td class="right"><strong>${escapeHtml(data.total_fmt)}</strong></td></tr>
              </tfoot>
            </table>
          </div>
        `;
      })
      .catch((error) => {
        content.innerHTML = `
          <div class="msg msg-error">
            <strong>No pudimos cargar el detalle.</strong>
            <span>${escapeHtml(error.message)}</span>
            <button class="btn btn-secondary detalle-retry" type="button">Reintentar</button>
          </div>
        `;
        content.querySelector(".detalle-retry")?.addEventListener("click", () => verDetalle(compraId));
      });
  }

  function anularCompra(id) {
    const compraId = Number(id) || 0;
    const csrf = document.querySelector('input[name="csrf_token"]');
    if (!compraId || !csrf) {
      window.Notif?.error?.("No se pudo preparar la anulacion. Recarga la pagina.");
      return;
    }

    anularTrigger = document.activeElement;
    const modal = document.createElement("div");
    modal.className = "modal-overlay compras-modal-overlay";
    modal.innerHTML = `
      <div class="modal-box modal-anular" role="dialog" aria-modal="true" aria-labelledby="comprasAnularTitle">
        <div class="modal-header">
          <div>
            <span class="modal-kicker">Accion sensible</span>
            <h3 id="comprasAnularTitle">Anular compra #${compraId}</h3>
          </div>
          <button type="button" class="btn-close js-close" aria-label="Cerrar">&times;</button>
        </div>
        <p class="modal-message">La compra quedara anulada y seguira visible en el historial.</p>
        <form method="post" id="formAnular">
          ${csrf.outerHTML}
          <input type="hidden" name="accion" value="anular_confirmada">
          <input type="hidden" name="compra_id" value="${compraId}">
          <label class="stock-revert-option">
            <input type="checkbox" name="revertir_stock" value="1">
            <span>
              <strong>Descontar del stock lo ingresado por esta compra</strong>
              <small>Activalo solo si la mercaderia tambien se devolvio o nunca ingreso fisicamente.</small>
            </span>
          </label>
          <p class="modal-note">Si no marcas la opcion, FLUS anula el registro pero conserva el stock actual.</p>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary js-close">Cancelar</button>
            <button type="submit" class="btn btn-danger">Anular compra</button>
          </div>
        </form>
      </div>
    `;

    const close = () => {
      modal.remove();
      document.body.classList.remove("modal-open");
      anularTrigger?.focus?.();
    };

    document.body.appendChild(modal);
    document.body.classList.add("modal-open");
    requestAnimationFrame(() => modal.classList.add("active"));
    modal.querySelector(".btn-close")?.focus();
    modal.addEventListener("click", (event) => {
      if (event.target === modal) close();
    });
    modal.querySelectorAll(".js-close").forEach((button) => {
      button.addEventListener("click", close);
    });
  }

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    const detalle = document.getElementById("modalDetalle");
    if (detalle && !detalle.hidden) cerrarDetalle();
    document.querySelector(".compras-modal-overlay .js-close")?.click();
  });

  document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("click", (event) => {
      const trigger = event.target.closest("[data-compra-action]");
      if (!trigger) return;

      const action = trigger.dataset.compraAction;
      if (action === "detalle") {
        verDetalle(trigger.dataset.compraId);
      } else if (action === "anular") {
        anularCompra(trigger.dataset.compraId);
      } else if (action === "cerrar-detalle") {
        cerrarDetalle();
      }
    });

    const modal = document.getElementById("modalDetalle");
    modal?.addEventListener("click", (event) => {
      if (event.target === event.currentTarget) cerrarDetalle();
    });

    const detalleId = Number(modal?.dataset.detalleId || 0);
    if (detalleId > 0) {
      verDetalle(detalleId);
      if (window.history && typeof window.history.replaceState === "function") {
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.delete("detalle");
        nextUrl.searchParams.delete("origen");
        window.history.replaceState({}, document.title, nextUrl.toString());
      }
    }
  });
})();
