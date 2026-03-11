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

  onReady(() => {
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
      const ready = count > 0 && hasBulkChanges();

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
      if (count === 0 || !hasBulkChanges()) {
        event.preventDefault();
        syncBulkState();
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