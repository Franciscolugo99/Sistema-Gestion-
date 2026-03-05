// public/assets/js/venta_detalle.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnAnularVenta");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    if (!await Notif.confirmar(
      "🗑️ Anular venta",
      "<p>Se repondrá el stock y se ajustará la caja.</p><p style='color:var(--muted,#94a3b8);font-size:.88rem'>Esta acción no se puede deshacer.</p>",
      { icon: "warning", confirmText: "✅ Anular", cancelText: "❌ Cancelar" }
    )) return;

    const ventaId = Number(btn.dataset.ventaId || 0);
    if (!ventaId) return Notif.error("No se detectó el ID de venta.");

    const _motivoRaw = await Notif.prompt("Motivo de anulación", "", { placeholder: "Opcional...", confirmText: "✅ Continuar" });
    if (_motivoRaw === null) { btn.disabled = false; return; }
    const motivo = (_motivoRaw || "").trim();

    // Chequeo CSRF para mensaje claro si falta el meta
    const csrf =
      (window.getCsrfToken && window.getCsrfToken()) ||
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
      "";

    if (!csrf) return Notif.error("Falta CSRF token en la página (meta csrf-token).");

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
      Notif.error("No se pudo anular la venta: " + (e?.message || e));
    }
  });
});
