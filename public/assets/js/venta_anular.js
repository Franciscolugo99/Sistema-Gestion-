// public/assets/js/venta_anular.js
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnAnularVenta');
  if (!btn) return;

  btn.addEventListener('click', async () => {
    const ventaId = parseInt(btn.dataset.ventaId || '0', 10);
    if (!ventaId) return;

    const motivo = prompt('Motivo de anulación (opcional):', '');
    if (motivo === null) return; // cancel

    if (!confirm('¿Confirmás anular la venta #' + ventaId + '? Se repondrá el stock.')) return;

    btn.disabled = true;
    try {
      const r = await fetch('api/api.php?action=anular_venta', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({venta_id: ventaId, motivo: motivo || ''})
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok || !data.ok) {
        alert((data && data.error) ? data.error : 'No se pudo anular la venta.');
        btn.disabled = false;
        return;
      }
      // recargar para ver estado actualizado
      location.reload();
    } catch (e) {
      console.error(e);
      alert('Error de red/anulación.');
      btn.disabled = false;
    }
  });
});
