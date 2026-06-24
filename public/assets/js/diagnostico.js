(() => {
  const notify = (type, message) => {
    if (!window.Notif) return;
    if ((type === "success" || type === "ok") && typeof window.Notif.exito === "function") {
      window.Notif.exito(message);
      return;
    }
    if ((type === "warning" || type === "warn") && typeof window.Notif.advertencia === "function") {
      window.Notif.advertencia(message);
      return;
    }
    if (type === "error" && typeof window.Notif.error === "function") {
      window.Notif.error(message);
      return;
    }
    if (typeof window.Notif.info === "function") {
      window.Notif.info(message);
    }
  };

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-copy-log]");
    if (!button) return;

    const targetId = String(button.dataset.copyLog || "").trim();
    const target = targetId ? document.getElementById(targetId) : null;
    if (!target) {
      notify("error", "No se encontró el log para copiar.");
      return;
    }

    const text = target.innerText || target.textContent || "";
    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
        await navigator.clipboard.writeText(text);
      } else {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.setAttribute("readonly", "readonly");
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand("copy");
        document.body.removeChild(textArea);
      }
      notify("success", "Log copiado.");
    } catch (_) {
      notify("error", "No se pudo copiar el log.");
    }
  });

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form.js-diag-confirm");
    if (!form) return;

    event.preventDefault();
    if (form.dataset.confirmPending === "1") return;
    if (!window.Notif || typeof window.Notif.confirmar !== "function") return;

    form.dataset.confirmPending = "1";
    const submitter = event.submitter;
    if (submitter) submitter.disabled = true;

    try {
      const confirmed = await window.Notif.confirmar(
        form.dataset.confirmTitle || "Confirmar acción",
        form.dataset.confirmMessage || "¿Querés continuar?",
        {
          icon: "warning",
          confirmText: form.dataset.confirmText || "Confirmar",
          cancelText: "Cancelar",
          useText: true,
          danger: form.dataset.confirmDanger === "true",
        }
      );

      if (confirmed) HTMLFormElement.prototype.submit.call(form);
    } finally {
      form.dataset.confirmPending = "0";
      if (submitter) submitter.disabled = false;
    }
  });

  const configEl = document.getElementById("diagSessionsConfig");
  if (!configEl) return;

  const endpoint = String(configEl.dataset.endpoint || "").trim();
  const currentSessionId = String(configEl.dataset.currentSessionId || "").trim();
  const sessionsBody = document.getElementById("diagSessionsBody");
  const sessionsEmpty = document.getElementById("diagSessionsEmpty");
  const sessionsMeta = document.getElementById("diagSessionsMeta");
  const actionsWrap = document.getElementById("diagAdminActions");

  if (!endpoint || !sessionsMeta || !actionsWrap) return;

  let timer = null;
  let inFlight = false;

  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  }[char]));

  const shortSession = (sid) => {
    const value = String(sid || "").trim();
    return value ? `${value.slice(0, 12)}...` : "-";
  };

  const actionLabel = (action) => {
    const normalized = String(action || "").toUpperCase();
    if (normalized === "SESSION_REVOKE") return "Forzó salida";
    if (normalized === "TERMINAL_FORCE_RELEASE") return "Liberó terminal";
    return normalized || "Acción admin";
  };

  const actionTarget = (meta) => {
    const parts = [];
    const targetName = String(meta?.target_user_name || "").trim();
    const targetUsername = String(meta?.target_username || "").trim();
    const terminalName = String(meta?.locked_terminal_name || meta?.selected_terminal_name || "").trim();
    const sessionIdShort = String(meta?.session_id_short || "").trim();

    if (targetName) parts.push(targetName);
    else if (targetUsername) parts.push(`@${targetUsername.replace(/^@+/, "")}`);
    if (terminalName) parts.push(terminalName);
    if (sessionIdShort) parts.push(sessionIdShort);

    return parts.length ? parts.join(" · ") : "Sin detalle";
  };

  const setMeta = () => {
    const now = new Date();
    sessionsMeta.textContent = `Actualizado ${now.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}`;
  };

  const ensureSessionsEmpty = () => {
    if (sessionsEmpty) return sessionsEmpty;
    if (!sessionsBody || !sessionsBody.parentElement || !sessionsBody.parentElement.parentElement) return null;

    const empty = document.createElement("p");
    empty.id = "diagSessionsEmpty";
    empty.className = "muted";
    empty.textContent = "No hay sesiones activas registradas en las ultimas 2 horas.";
    sessionsBody.parentElement.parentElement.insertAdjacentElement("afterend", empty);
    return empty;
  };

  const renderSessions = (sessions) => {
    const rows = Array.isArray(sessions) ? sessions : [];
    if (!sessionsBody) {
      if (sessionsEmpty) {
        sessionsEmpty.style.display = rows.length ? "none" : "";
      }
      return;
    }

    if (!rows.length) {
      sessionsBody.innerHTML = "";
      const empty = ensureSessionsEmpty();
      if (empty) empty.style.display = "";
      return;
    }

    const empty = ensureSessionsEmpty();
    if (empty) empty.style.display = "none";

    sessionsBody.innerHTML = rows.map((sessionRow) => {
      const sid = String(sessionRow.session_id || "");
      const displayName = String(sessionRow.display_name || sessionRow.username || "Usuario").trim();
      const selectedTerminal = String(sessionRow.selected_terminal_nombre || "").trim() || ((Number(sessionRow.selected_terminal_id || 0) > 0) ? `Caja #${Number(sessionRow.selected_terminal_id)}` : "Sin terminal");
      const lockedTerminal = String(sessionRow.locked_terminal_nombre || "").trim() || ((Number(sessionRow.locked_terminal_id || 0) > 0) ? `Caja #${Number(sessionRow.locked_terminal_id)}` : "Libre");
      const currentBadge = sid && sid === currentSessionId ? '<span class="chip ok ml-05">actual</span>' : "";

      return `
        <tr>
          <td>
            <strong>${escapeHtml(displayName)}</strong><br>
            <span class="muted">@${escapeHtml(String(sessionRow.username || ""))}</span>
            ${currentBadge}
          </td>
          <td><code>${escapeHtml(shortSession(sid))}</code></td>
          <td>${escapeHtml(String(sessionRow.last_seen_at || ""))}</td>
          <td>${escapeHtml(selectedTerminal)}</td>
          <td>${escapeHtml(lockedTerminal)}</td>
          <td>${escapeHtml(String(sessionRow.ip_address || "-"))}</td>
          <td><code>${escapeHtml(String(sessionRow.last_path || "-"))}</code></td>
          <td>
            <div class="pkg-actions">
              <form method="post" class="inline-form js-diag-confirm"
                    data-confirm-title="Liberar terminal"
                    data-confirm-message="Se liberará la terminal asociada a la sesión de ${escapeHtml(displayName)}. Usalo solo si la caja quedó tomada o trabada."
                    data-confirm-text="Liberar terminal">
                <input type="hidden" name="csrf_token" value="${escapeHtml(window.getCsrfToken ? window.getCsrfToken() : "")}">
                <input type="hidden" name="accion" value="liberar_terminal_sesion">
                <input type="hidden" name="session_id" value="${escapeHtml(sid)}">
                <button type="submit" class="btn btn-sm btn-secondary">Liberar terminal</button>
              </form>
              <form method="post" class="inline-form js-diag-confirm"
                    data-confirm-title="Forzar salida"
                    data-confirm-message="La sesión de ${escapeHtml(displayName)} se cerrará de inmediato. ¿Querés continuar?"
                    data-confirm-text="Forzar salida"
                    data-confirm-danger="true">
                <input type="hidden" name="csrf_token" value="${escapeHtml(window.getCsrfToken ? window.getCsrfToken() : "")}">
                <input type="hidden" name="accion" value="revocar_sesion">
                <input type="hidden" name="session_id" value="${escapeHtml(sid)}">
                <button type="submit" class="btn btn-sm btn-danger">Forzar salida</button>
              </form>
            </div>
          </td>
        </tr>
      `;
    }).join("");
  };

  const renderActions = (actions) => {
    const rows = Array.isArray(actions) ? actions : [];
    if (!rows.length) {
      actionsWrap.innerHTML = '<p class="muted">Todavia no hay acciones administrativas recientes sobre sesiones o terminales.</p>';
      return;
    }

    actionsWrap.innerHTML = rows.map((row) => {
      const actorName = String(row.actor_nombre || row.actor_username || "Sistema").trim();
      return `
        <div class="package-item">
          <div class="package-info">
            <div>
              <div><strong>${escapeHtml(actionLabel(row.action))}</strong></div>
              <div class="package-meta">
                ${escapeHtml(actorName)} · ${escapeHtml(actionTarget(row.meta || {}))} · ${escapeHtml(String(row.created_at || ""))}
              </div>
            </div>
          </div>
        </div>
      `;
    }).join("");
  };

  const run = async () => {
    if (inFlight) return;
    inFlight = true;

    try {
      const response = await fetch(endpoint, {
        headers: {
          Accept: "application/json",
        },
        credentials: "same-origin",
        cache: "no-store",
      });

      if (!response.ok) return;

      const data = await response.json();
      renderSessions(data.sessions || []);
      renderActions(data.actions || []);
      setMeta();
    } catch (_) {
      // Best effort: no interrumpimos la UI por una actualización fallida.
    } finally {
      inFlight = false;
      schedule();
    }
  };

  const nextDelay = () => document.visibilityState === "visible" ? 10000 : 30000;

  const schedule = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, nextDelay());
  };

  const requestFastRefresh = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, 250);
  };

  window.addEventListener("focus", requestFastRefresh);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") requestFastRefresh();
  });
  window.addEventListener("pageshow", requestFastRefresh);

  schedule();
})();
