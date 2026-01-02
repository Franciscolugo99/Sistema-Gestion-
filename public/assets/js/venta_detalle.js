// public/assets/js/venta_detalle.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnAnularVenta");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    if (!confirm("¿Anular esta venta? Se repondrá stock y se ajustará la caja.")) return;

    const ventaId = Number(btn.dataset.ventaId || 0);
    if (!ventaId) return alert("No se detectó el ID de venta.");

    const motivo = (prompt("Motivo (opcional):", "") || "").trim();

    // Chequeo CSRF para mensaje claro si falta el meta
    const csrf =
      (window.getCsrfToken && window.getCsrfToken()) ||
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
      "";

    if (!csrf) return alert("Falta CSRF token en la página (meta csrf-token).");

    btn.disabled = true;

    try {
      if (!window.apiJson) {
        throw new Error("apiJson() no está disponible (¿se cargó app.js en esta página?).");
      }

      await window.apiJson("api/index.php", {
        action: "anular_venta",
        venta_id: ventaId,
        motivo,
      });

      location.reload();
    } catch (e) {
      console.error(e);
      btn.disabled = false;
      alert("No se pudo anular la venta: " + (e?.message || e));
    }
  });
});
