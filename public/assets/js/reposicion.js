// FLUS - reposicion.js
// Comportamiento actual:
// - foco en el buscador de configuracion
// - prevencion de doble submit
// - seleccion y guardado masivo en configuracion

(() => {
  const onReady = (fn) => {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
      fn();
    }
  };

  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const notify = (type, message) => {
    const text = String(message || "").trim();
    if (!text) return;

    if (window.Notif) {
      if ((type === "success" || type === "ok") && typeof window.Notif.exito === "function") {
        window.Notif.exito(text);
        return;
      }
      if ((type === "warning" || type === "warn") && typeof window.Notif.advertencia === "function") {
        window.Notif.advertencia(text);
        return;
      }
      if (typeof window.Notif.error === "function") {
        window.Notif.error(text);
        return;
      }
    }

    if (typeof window.showToast === "function") {
      const fallbackType = type === "success" || type === "ok"
        ? "success"
        : (type === "warning" || type === "warn" ? "warn" : "error");
      window.showToast(text, fallbackType);
    }
  };

  onReady(() => {
    const page = qs("[data-repo-flash-ok], [data-repo-flash-error]");
    if (page) {
      const ok = String(page.dataset.repoFlashOk || "").trim();
      const error = String(page.dataset.repoFlashError || "").trim();
      if (ok || error) {
        window.setTimeout(() => {
          if (ok) notify("success", ok);
          if (error) notify("error", error);
        }, 120);
      }
    }

    const search = qs(".repo-search-input");
    if (search) {
      try {
        search.focus({ preventScroll: true });
      } catch {
        search.focus();
      }
    }

    qsa("form.config-form").forEach((form) => {
      form.addEventListener("submit", () => {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent || "Guardar";
        btn.textContent = "Guardando...";
      });
    });

    const bulkForm = qs("#bulk-config-form");
    if (!bulkForm) {
      return;
    }

    const bulkCheckboxes = qsa(".repo-bulk-checkbox");
    const bulkInputs = qsa('input[name^="bulk_"], select[name^="bulk_"]', bulkForm);
    const bulkCount = qs("[data-bulk-count]");
    const bulkSubmit = qs("[data-bulk-submit]", bulkForm);
    const selectAllBtn = qs("[data-bulk-select-all]");
    const clearBtn = qs("[data-bulk-clear]");

    const selectedCount = () => bulkCheckboxes.filter((input) => input.checked).length;
    const hasBulkChanges = () =>
      bulkInputs.some((input) => String(input.value ?? "").trim() !== "");

    const syncBulkCards = () => {
      bulkCheckboxes.forEach((input) => {
        const card = input.closest("[data-config-card]");
        if (!card) return;
        card.classList.toggle("is-selected", input.checked);
      });
    };

    const syncBulkState = () => {
      const count = selectedCount();
      const hasChanges = hasBulkChanges();
      const ready = count > 0 && hasChanges;

      if (bulkCount) {
        bulkCount.textContent = String(count);
      }

      if (bulkSubmit) {
        bulkSubmit.disabled = !ready;
        bulkSubmit.textContent = ready ? `Aplicar a ${count} seleccionados` : "Aplicar en lote";
      }

      syncBulkCards();
    };

    bulkCheckboxes.forEach((input) => {
      input.addEventListener("change", syncBulkState);
    });

    bulkInputs.forEach((input) => {
      input.addEventListener("input", syncBulkState);
      input.addEventListener("change", syncBulkState);
    });

    if (selectAllBtn) {
      selectAllBtn.addEventListener("click", () => {
        bulkCheckboxes.forEach((input) => {
          input.checked = true;
        });
        syncBulkState();
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        bulkCheckboxes.forEach((input) => {
          input.checked = false;
        });
        syncBulkState();
      });
    }

    bulkForm.addEventListener("submit", (event) => {
      const count = selectedCount();
      const hasChanges = hasBulkChanges();

      if (count === 0 || !hasChanges) {
        event.preventDefault();
        syncBulkState();
        if (count === 0 && hasChanges) {
          notify("warn", "Selecciona al menos un producto o usa 'Seleccionar pagina'.");
        } else if (count > 0 && !hasChanges) {
          notify("warn", "Completa al menos un valor para aplicar el cambio masivo.");
        } else {
          notify("warn", "Selecciona productos y completa al menos un cambio para continuar.");
        }
        bulkForm.scrollIntoView({ behavior: "smooth", block: "nearest" });
        return;
      }

      if (bulkSubmit && !bulkSubmit.disabled) {
        bulkSubmit.disabled = true;
        bulkSubmit.textContent = "Aplicando...";
      }
    });

    syncBulkState();
  });
})();
