// public/assets/js/clientes.js
// Toggle del formulario de clientes (Agregar/Cerrar)

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("toggleCliFormBtn");
  const block = document.querySelector(".cli-form-block");
  if (!btn || !block) return;

  const isCollapsed = () => block.classList.contains("is-collapsed");

  const sync = () => {
    btn.textContent = isCollapsed() ? "Agregar cliente" : "Cerrar formulario";
  };

  sync();

  btn.addEventListener("click", () => {
    block.classList.toggle("is-collapsed");
    sync();

    // Si lo abrimos, llevamos el foco al primer input
    if (!isCollapsed()) {
      const first = block.querySelector('input[name="nombre"]');
      if (first) {
        first.focus({ preventScroll: true });
        // scroll suave al panel
        try {
          block.scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (_) {}
      }
    }
  });
});
document.addEventListener("DOMContentLoaded", () => {
  const overlay = document.getElementById("cliDrawerOverlay");
  const drawer = document.getElementById("cliDrawer");
  const btnNew = document.getElementById("toggleCliFormBtn");
  const btnClose = document.getElementById("cliDrawerClose");

  if (!overlay || !drawer) return;

  function openDrawer() {
    overlay.classList.add("is-open");
    drawer.classList.add("is-open");
    document.body.classList.add("no-scroll");
  }

  function closeDrawer() {
    overlay.classList.remove("is-open");
    drawer.classList.remove("is-open");
    document.body.classList.remove("no-scroll");
  }

  overlay.addEventListener("click", closeDrawer);
  btnClose?.addEventListener("click", closeDrawer);

  // Botón "Agregar cliente" abre drawer vacío
  btnNew?.addEventListener("click", () => {
    // Si estás editando (hay ?editar=), mejor limpiar la URL para que sea alta
    const url = new URL(window.location.href);
    url.searchParams.delete("editar");
    window.history.replaceState({}, "", url.toString());

    // Reset form si existe
    const form = drawer.querySelector("form");
    if (form) form.reset();

    // Borrar hidden id si existe (modo alta)
    const idInput = drawer.querySelector('input[name="id"]');
    if (idInput) idInput.remove();

    openDrawer();
  });

  // Si la página viene con ?editar=... (modo edición), abrimos automáticamente
  const url = new URL(window.location.href);
  if (url.searchParams.get("editar")) {
    openDrawer();
  }

  // ESC cierra
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeDrawer();
  });
});
