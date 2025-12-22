// public/assets/js/venta_detalle.js
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
    if (
      !confirm("¿Anular esta venta? Se repondrá stock y se ajustará la caja.")
    )
      return;

    const ventaId = Number(btn.dataset.ventaId || 0);
    if (!ventaId) return alert("No se detectó el ID de venta.");

    const motivo = (prompt("Motivo (opcional):", "") || "").trim();

    const csrf = getCsrf();
    if (!csrf) return alert("Falta CSRF token en la página (meta csrf-token).");

    btn.disabled = true;

    try {
      const res = await fetch("api/api.php?action=anular_venta", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json; charset=utf-8",
          Accept: "application/json",
          "X-CSRF-Token": csrf,
        },
        body: JSON.stringify({ venta_id: ventaId, motivo }),
      });

      const text = await res.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch {
        console.error("Respuesta NO JSON:", text);
        btn.disabled = false;
        return alert("La API no devolvió JSON válido.");
      }

      if (!res.ok || data?.ok !== true) {
        const msg =
          data?.error || `No se pudo anular la venta (HTTP ${res.status})`;
        btn.disabled = false;

        if (res.status === 401)
          return alert("No autenticado (401). Volvé a iniciar sesión.");
        if (res.status === 403)
          return alert("No autorizado o CSRF inválido (403).");
        return alert(msg);
      }

      location.reload();
    } catch (e) {
      btn.disabled = false;
      alert("Error de red o servidor: " + (e?.message || e));
    }
  });
});
