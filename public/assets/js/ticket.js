// public/assets/js/ticket-print.js
// Script opcional para manejo de impresión de tickets
document.addEventListener("DOMContentLoaded", () => {
  const auto = document.body?.dataset?.autoprint === "1";

  const btn = document.getElementById("btnPrint");
  if (btn) {
    btn.addEventListener("click", () => {
      window.focus();
      window.print();
    });
  }

  // Auto-print si se solicitó
  if (auto) {
    window.addEventListener("load", () => {
      setTimeout(() => {
        window.focus();
        window.print();
        
        // Cerrar después de imprimir (opcional)
        setTimeout(() => {
          window.close();
        }, 300);
      }, 250);
    });
  }
});