(() => {
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
              <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="${escapeHtml(window.getCsrfToken ? window.getCsrfToken() : "")}">
                <input type="hidden" name="accion" value="liberar_terminal_sesion">
                <input type="hidden" name="session_id" value="${escapeHtml(sid)}">
                <button type="submit" class="btn btn-sm btn-secondary">Liberar terminal</button>
              </form>
              <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="${escapeHtml(window.getCsrfToken ? window.getCsrfToken() : "")}">
                <input type="hidden" name="accion" value="revocar_sesion">
                <input type="hidden" name="session_id" value="${escapeHtml(sid)}">
                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Forzar cierre de sesion para este usuario?');">Forzar salida</button>
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
