// public/assets/js/venta_anular.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnAnularVenta");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    const ventaId = parseInt(btn.dataset.ventaId || "0", 10);
    if (!ventaId) return;

    const motivo = prompt("Motivo de anulación (opcional):", "");
    if (motivo === null) return; // cancel

    if (
      !confirm(
        `¿Confirmás anular la venta #${ventaId}? Se repondrá el stock y se ajustará la caja.`
      )
    ) return;

    // Chequeo CSRF (solo para dar un error claro si falta el meta)
    const csrf =
      (window.getCsrfToken && window.getCsrfToken()) ||
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
      "";

    if (!csrf) {
      alert("Falta CSRF token en la página (meta csrf-token).");
      return;
    }

    btn.disabled = true;

    try {
      if (!window.apiJson) {
        throw new Error("apiJson() no está disponible (¿se cargó app.js en esta página?).");
      }

      await window.apiJson("api/api.php", {
        action: "anular_venta",
        venta_id: ventaId,
        motivo: (motivo || "").trim(),
      });

      location.reload();
    } catch (e) {
      console.error(e);
      alert("No se pudo anular la venta: " + (e?.message || e));
      btn.disabled = false;
    }
  });
});
