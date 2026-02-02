// FLUS - reposicion.js
// - evita doble submit en forms de configuración
// - mejora UX (autofocus en buscador de config)

(() => {
  const onReady = (fn) => {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
      fn();
    }
  };

  onReady(() => {
    // Autofocus en el buscador (vista config)
    const search = document.querySelector(".repo-search-input");
    if (search) {
      try {
        search.focus({ preventScroll: true });
      } catch {
        search.focus();
      }
    }

    // Evitar doble submit (config)
    document.querySelectorAll("form.config-form").forEach((form) => {
      form.addEventListener("submit", () => {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn) return;

        // Si ya está deshabilitado, no hacemos nada (doble click)
        if (btn.disabled) return;

        btn.disabled = true;
        btn.dataset.originalText = btn.textContent || "";
        btn.textContent = "Guardando…";
      });
    });
  });
})();
