// public/assets/js/venta_anular.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnAnularVenta");
  if (!btn) return;

  function getCsrf() {
    return (
      document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") || ""
    );
  }

  btn.addEventListener("click", async () => {
    const ventaId = parseInt(btn.dataset.ventaId || "0", 10);
    if (!ventaId) return;

    const motivo = prompt("Motivo de anulación (opcional):", "");
    if (motivo === null) return; // cancel

    if (
      !confirm(
        `¿Confirmás anular la venta #${ventaId}? Se repondrá el stock y se ajustará la caja.`
      )
    )
      return;

    const csrf = getCsrf();
    if (!csrf) {
      alert("Falta CSRF token en la página (meta csrf-token).");
      return;
    }

    btn.disabled = true;

    try {
      const r = await fetch("api/api.php?action=anular_venta", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json; charset=utf-8",
          Accept: "application/json",
          "X-CSRF-Token": csrf,
        },
        body: JSON.stringify({
          venta_id: ventaId,
          motivo: (motivo || "").trim(),
        }),
      });

      const text = await r.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch {
        console.error("Respuesta NO JSON:", text);
        alert("La API no devolvió JSON válido.");
        btn.disabled = false;
        return;
      }

      if (!r.ok || !data?.ok) {
        const msg =
          data?.error || `No se pudo anular la venta (HTTP ${r.status})`;

        if (r.status === 401)
          alert("No autenticado (401). Volvé a iniciar sesión.");
        else if (r.status === 403)
          alert("No autorizado o CSRF inválido (403).");
        else alert(msg);

        btn.disabled = false;
        return;
      }

      location.reload();
    } catch (e) {
      console.error(e);
      alert("Error de red/anulación: " + (e?.message || e));
      btn.disabled = false;
    }
  });
});
