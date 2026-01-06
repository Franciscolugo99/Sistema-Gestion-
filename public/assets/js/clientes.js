// public/assets/js/clientes.js
// Manejo completo del drawer de clientes con validación y UX mejorada

(function () {
  "use strict";

  // ========== Variables ==========
  const overlay = document.getElementById("cliDrawerOverlay");
  const drawer = document.getElementById("cliDrawer");
  const form = document.getElementById("cliForm");
  const btnClose = document.getElementById("cliDrawerClose");
  const btnCancel = document.getElementById("cliCancelBtn");
  const btnSubmit = document.getElementById("cliSubmitBtn");

  if (!overlay || !drawer || !form) return;

  let formChanged = false;
  const originalSubmitText = btnSubmit?.textContent || "Guardar cliente";

  // ========== Funciones de drawer ==========
  function openDrawer() {
    overlay.classList.add("is-open");
    drawer.classList.add("is-open");
    document.body.classList.add("no-scroll");
    drawer.setAttribute("aria-hidden", "false");

    // ✅ Focus automático en primer input
    setTimeout(() => {
      const firstInput = drawer.querySelector('input:not([type="hidden"])');
      if (firstInput) {
        firstInput.focus();
      }
    }, 100);
  }

  function closeDrawer() {
    // ✅ Confirmar si hay cambios sin guardar
    if (formChanged) {
      if (!confirm("Tenés cambios sin guardar. ¿Querés salir igual?")) {
        return;
      }
    }

    overlay.classList.remove("is-open");
    drawer.classList.remove("is-open");
    document.body.classList.remove("no-scroll");
    drawer.setAttribute("aria-hidden", "true");

    // ✅ Limpiar URL sin recargar (quitar ?editar o ?new)
    const url = new URL(window.location.href);
    url.searchParams.delete("editar");
    url.searchParams.delete("new");
    window.history.replaceState({}, "", url.toString());

    formChanged = false;
  }

  // ========== Eventos del drawer ==========
  overlay.addEventListener("click", closeDrawer);
  btnClose?.addEventListener("click", closeDrawer);
  btnCancel?.addEventListener("click", closeDrawer);

  // ✅ ESC para cerrar
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && drawer.classList.contains("is-open")) {
      closeDrawer();
    }
  });

  // ========== Track cambios en form ==========
  form.addEventListener("input", () => {
    formChanged = true;
  });

  // ========== Validación frontend ==========
  form.addEventListener("submit", (e) => {
    const nombreInput = form.querySelector('[name="nombre"]');
    const emailInput = form.querySelector('[name="email"]');

    const nombre = nombreInput?.value.trim() || "";
    const email = emailInput?.value.trim() || "";

    // Validar nombre
    if (!nombre) {
      e.preventDefault();
      alert("El nombre es obligatorio.");
      nombreInput?.focus();
      return;
    }

    // Validar email si está presente
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      e.preventDefault();
      alert("El email no tiene un formato válido.");
      emailInput?.focus();
      return;
    }

    // ✅ Loading state
    formChanged = false; // no preguntar al salir
    if (btnSubmit) {
      btnSubmit.disabled = true;
      btnSubmit.textContent = "Guardando...";
    }

    // El form se submitea normalmente después de esto
  });

  // ========== Prevenir navegación con cambios ==========
  window.addEventListener("beforeunload", (e) => {
    if (formChanged && drawer.classList.contains("is-open")) {
      e.preventDefault();
      e.returnValue = ""; // Chrome requiere esto
    }
  });

  // ========== Auto-abrir si viene con ?editar o ?new ==========
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get("editar") || urlParams.get("new")) {
    openDrawer();
  }

  // ========== Búsqueda con debounce (opcional) ==========
  const searchInput = document.querySelector('.filters input[name="q"]');
  if (searchInput) {
    let searchTimeout;
    searchInput.addEventListener("input", (e) => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        // Auto-submit después de 600ms sin escribir
        e.target.form.submit();
      }, 600);
    });
  }
})();