// public/assets/js/venta_detalle.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnAnularVenta");
  if (!btn) return;

  btn.addEventListener("click", async () => {
    if (!confirm("¿Anular esta venta? Se repondrá stock y se ajustará la caja.")) return;

    const ventaId = Number(btn.dataset.ventaId || 0);
    if (!ventaId) return alert("No se detectó el ID de venta.");

    const motivo = (prompt("Motivo (opcional):", "") || "").trim();

    btn.disabled = true;

    try {
      // 👇 acción va por querystring (tu API toma action desde $_GET)
      const res = await fetch("api/api.php?action=anular_venta", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          venta_id: ventaId,
          motivo: motivo
        })
      });

      const data = await res.json().catch(() => null);

      if (!res.ok || !data || data.ok !== true) {
        btn.disabled = false;
        return alert((data && data.error) ? data.error : "No se pudo anular la venta.");
      }

      location.reload();
    } catch (e) {
      btn.disabled = false;
      alert("Error de red o servidor: " + (e?.message || e));
    }
  });
});
