// public/assets/js/caja_terminal_modal.js
document.addEventListener("DOMContentLoaded", () => {
  const btnOpen = document.getElementById("btnCambiarTerminal");
  const modal = document.getElementById("terminalModal");
  if (!modal) return;

  const listEl = document.getElementById("terminalModalList");
  const errEl = document.getElementById("terminalModalError");
  const form = document.getElementById("terminalModalForm");

  const closeEls = modal.querySelectorAll("[data-close]");

  const getCsrf = () =>
    (window.getCsrfToken && window.getCsrfToken()) ||
    document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
    "";

  const openModal = () => {
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    loadList();
  };

  const closeModal = () => {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    hideError();
  };

  function showError(msg) {
    if (!errEl) return;
    errEl.textContent = msg;
    errEl.classList.remove("is-hidden");
  }
  function hideError() {
    if (!errEl) return;
    errEl.textContent = "";
    errEl.classList.add("is-hidden");
  }

  async function api(body) {
    const csrf = getCsrf();
    if (!csrf) {
      // Esto evita requests inútiles que van a devolver 403
      return { r: { ok: false, status: 403 }, j: { ok: false, error: "CSRF_MISSING" } };
    }

    const r = await fetch("api/index.php?action=terminal_select", {
      method: "POST",
      headers: {
        "X-CSRF-Token": csrf,
        "Content-Type": "application/json; charset=utf-8",
        "Accept": "application/json",
      },
      credentials: "same-origin",
      cache: "no-store",
      body: JSON.stringify(body || {}),
    });

    let j = null;
    try {
      j = await r.json();
    } catch (_) {
      j = null;
    }

    return { r, j };
  }

  function escapeHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function renderList(terminals, currentId) {
    if (!listEl) return;
    listEl.innerHTML = "";

    (terminals || []).forEach((t) => {
      const isLocked = t.status === "locked";
      const checked = Number(t.id) === Number(currentId);

      const item = document.createElement("label");
      item.className = "terminal-item";

      item.innerHTML = `
        <input class="terminal-radio" type="radio" name="terminal_id" value="${t.id}"
          ${checked ? "checked" : ""} ${isLocked ? "disabled" : ""}>
        <div class="terminal-main">
          <div class="terminal-name">${escapeHtml(t.nombre || "Caja #" + t.id)}</div>
          ${
            t.codigo
              ? `<div class="terminal-code">Código: ${escapeHtml(t.codigo)}</div>`
              : ""
          }
        </div>
        <div class="terminal-pill ${isLocked ? "is-locked" : "is-free"}">
          ${isLocked ? `Ocupada · ${escapeHtml(t.lockedBy || "Otro")}` : "Libre"}
        </div>
      `;

      listEl.appendChild(item);
    });
  }

  async function loadList() {
    hideError();

    const { r, j } = await api({}); // lista
    if (!r.ok || !j || !j.ok) {
      if (j?.error === "CSRF_MISSING") {
        showError("Falta CSRF token en la página (meta csrf-token).");
      } else {
        showError("No se pudo cargar la lista de terminales. Reintentá.");
      }
      return;
    }

    renderList(j.terminals || [], j.current_terminal_id || 0);
  }

  // abrir/cerrar
  if (btnOpen) btnOpen.addEventListener("click", openModal);
  closeEls.forEach((el) => el.addEventListener("click", closeModal));
  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && modal.classList.contains("is-open")) closeModal();
  });

  // submit
  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      hideError();

      const fd = new FormData(form);
      const tid = Number(fd.get("terminal_id") || 0);
      if (!tid) {
        showError("Elegí una terminal.");
        return;
      }

      const { r, j } = await api({ terminal_id: tid });

      if (r.status === 409 && j && j.error === "CAJA_ABIERTA") {
        showError("Cerrá la caja actual antes de cambiar de terminal.");
        return;
      }

      if (!r.ok || !j || !j.ok) {
        if (j && (j.error === "LOCKED" || j.error === "LOCK_LOST")) {
          showError("Esa terminal está ocupada.");
          await loadList();
          return;
        }
        if (j?.error === "CSRF_MISSING") {
          showError("Falta CSRF token en la página (meta csrf-token).");
          return;
        }
        showError("No se pudo cambiar la terminal. Reintentá.");
        return;
      }

      // actualizar texto en barra (y recargar para asegurar estado)
      document.querySelectorAll(".pos-terminal-name").forEach((el) => {
        el.textContent = j.terminal_nombre || "Caja #" + tid;
      });

      closeModal();
      window.location.reload();
    });
  }
});
