// public/assets/js/terminal_select.js
(() => {
  const configEl = document.getElementById("terminalSelectConfig");
  const script = document.currentScript;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
  const basePath = String(configEl?.dataset?.base || "").replace(/\/+$/g, "");

  const rawNext = configEl?.dataset?.next || script?.dataset?.next || "";
  const nextUrl = (() => {
    // Normaliza \caja.php, slashes dobles y paths con /public repetido.
    let n = String(rawNext || "").trim();
    if (!n) n = "/caja.php";
    n = n.replace(/\\/g, "/");

    try {
      const u = new URL(n, window.location.origin);
      u.pathname = u.pathname.replace(/\/{2,}/g, "/");

      if (basePath) {
        const baseLeaf = basePath.split("/").filter(Boolean).pop() || "";
        if (baseLeaf) {
          const duplicateBasePrefix = `${basePath}/${baseLeaf}/`;
          while (u.pathname.startsWith(duplicateBasePrefix)) {
            u.pathname = `${basePath}/${u.pathname.slice(duplicateBasePrefix.length)}`;
          }
        }
      }

      if (u.origin !== window.location.origin) return new URL("/caja.php", window.location.origin).toString();
      return u.toString();
    } catch {
      return new URL("/caja.php", window.location.origin).toString();
    }
  })();

  const $grid = document.getElementById("grid");
  const $msg = document.getElementById("msg");

  let selecting = false;

  // Endpoint robusto (localhost:8080 / IP:8080 / subcarpeta)
  const API_ENDPOINT = new URL("api/index.php", window.location.href).toString();

  function toast(msg, type = "info", ms = 2400) {
    if (typeof window.showToast === "function") return window.showToast(msg, type, ms);
    const t = document.createElement("div");
    t.className = "ts-toast";
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add("show"));
    setTimeout(() => t.classList.remove("show"), ms);
    setTimeout(() => t.remove(), ms + 250);
  }

  function setMsg(html) {
    if ($msg) $msg.innerHTML = html;
  }

  async function api(action, payload, retries = 1, timeout = 10000) {
    for (let attempt = 0; attempt <= retries; attempt++) {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const url = `${API_ENDPOINT}?action=${encodeURIComponent(action)}`;

        const r = await fetch(url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf,
          },
          body: JSON.stringify(payload || {}),
          credentials: "same-origin",
          cache: "no-store",
          signal: controller.signal,
        });

        clearTimeout(timeoutId);

        let j = null;
        try { j = await r.json(); } catch (_) {}

        return { status: r.status, json: j };
      } catch (e) {
        if (attempt === retries) throw e;
        await new Promise(res => setTimeout(res, 600));
      }
    }
  }

  function formatDate(dateStr) {
    if (!dateStr) return "";
    try {
      const norm = String(dateStr).includes("T") ? String(dateStr) : String(dateStr).replace(" ", "T");
      const d = new Date(norm);
      if (isNaN(d.getTime())) return "";

      const today = new Date();
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);

      const time = d.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit" });

      if (d.toDateString() === today.toDateString()) return `Hoy ${time}`;
      if (d.toDateString() === yesterday.toDateString()) return `Ayer ${time}`;

      return d.toLocaleDateString("es-AR", { day: "2-digit", month: "2-digit" });
    } catch (_) {
      return "";
    }
  }

  function render(terminales, current) {
    if (!$grid) return;
    $grid.innerHTML = "";

    if (!Array.isArray(terminales) || terminales.length === 0) {
      setMsg("No hay terminales disponibles.");
      return;
    }

    setMsg("Elegí una terminal para seleccionarla y continuar.");

    terminales.forEach(t => {
      const id = Number(t.id || t.terminal_id || 0);
      const nombre = String(t.nombre || t.name || (`Caja #${id}`));
      const activo = Number(t.activo ?? 1) === 1;
      const isCurrent = id === Number(current || 0);
      const isLocked = Boolean(t.locked) || String(t.status || "") === "locked";
      const lockedBy = String(t.lockedBy || t.locked_by_name || "Otro usuario");
      const selectable = activo && (!isLocked || isCurrent);

      const ultimoUso = t.ultimo_uso || t.last_used || t.last_seen_at || null;

      const card = document.createElement("div");
      card.className = "ts-card";
      card.tabIndex = 0;

      if (isCurrent) card.classList.add("current");
      if (!selectable) card.classList.add("disabled");

      const metaLeft = `ID: ${id}`;
      const metaRight = isCurrent
        ? "Seleccionada ahora"
        : (!activo ? "Inactiva" : (isLocked ? `Ocupada por ${lockedBy}` : "Click para seleccionar"));
      const uso = ultimoUso ? formatDate(ultimoUso) : "";
      const metaMid = uso ? `Uso: ${uso}` : "";

      const row = document.createElement("div");
      row.className = "ts-row";

      const nameEl = document.createElement("div");
      nameEl.className = "ts-name";
      nameEl.textContent = nombre;

      const badge = document.createElement("span");
      badge.className = "ts-badge";

      const dot = document.createElement("span");
      dot.className = activo ? "ts-status-dot" : "ts-status-dot ts-dot-off";
      badge.appendChild(dot);
      badge.appendChild(document.createTextNode(` ${isCurrent ? "Seleccionada" : (!activo ? "Inactiva" : (isLocked ? "Ocupada" : "Activa"))}`));

      row.appendChild(nameEl);
      row.appendChild(badge);

      const meta = document.createElement("div");
      meta.className = "ts-meta";

      const left = document.createElement("span");
      left.textContent = metaLeft;

      const mid = document.createElement("span");
      mid.title = ultimoUso || "";
      mid.textContent = metaMid;

      const right = document.createElement("span");
      right.style.opacity = ".85";
      right.textContent = metaRight;

      meta.appendChild(left);
      meta.appendChild(mid);
      meta.appendChild(right);

      card.appendChild(row);
      card.appendChild(meta);

      const handleSelect = async () => {
        if (!activo) return toast("Esta terminal está inactiva.", "warn");
        if (isLocked && !isCurrent) return toast(`Esa terminal está ocupada por ${lockedBy}.`, "warn", 3200);
        if (selecting || !id) return;

        selecting = true;
        card.classList.add("selecting");
        setMsg('<span class="ts-loader"></span> Seleccionando terminal…');

        try {
          const { status, json } = await api("terminal_select", { csrf, terminal_id: id });

          // Errores conocidos
          const err = String(json?.error || "");
          if (status === 409 && err === "CAJA_ABIERTA") {
            toast("No podés cambiar: hay una caja abierta en la terminal actual.", "warn", 3200);
            setMsg("Cerrá la caja o elegí otra terminal.");
            return;
          }

          if (status === 409 && err === "LOCKED") {
            toast("Esa terminal está en uso por otro usuario. Probá otra.", "warn", 3200);
            setMsg("Elegí otra terminal (está bloqueada).");
            return;
          }

          if (err === "LOCK_SCHEMA" || err === "DB_ERROR" || status === 503) {
            toast("No se pudo validar la terminal en este momento. Reintentá.", "error", 4200);
            setMsg("Hubo un problema al validar las terminales. Reintentá en unos segundos.");
            return;
          }

          if (status === 403) {
            toast("No tenés permiso para usar esta terminal.", "error", 2800);
            setMsg("Elegí otra terminal.");
            return;
          }

          if (!json || json.ok !== true) {
            console.error("terminal_select invalid response", { status, json });
            throw new Error("Respuesta inválida del servidor");
          }

          toast("Terminal seleccionada. Redirigiendo…", "ok", 1200);
          setTimeout(() => { window.location.href = nextUrl; }, 450);

        } catch (e) {
          console.error("terminal_select select error", e);
          toast("No se pudo seleccionar la terminal. Reintentá.", "error", 2800);
          setMsg('Error al seleccionar. <button id="tsRetry" class="btn-mini" type="button">Reintentar</button>');
          const btn = document.getElementById("tsRetry");
          if (btn) btn.addEventListener("click", () => window.location.reload());
        } finally {
          card.classList.remove("selecting");
          selecting = false;
        }
      };

      card.addEventListener("click", handleSelect);
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          handleSelect();
        }
      });

      $grid.appendChild(card);
    });
  }

  (async () => {
    try {
      // Sin terminal_id => backend devuelve lista (tu index.php ya lo hace)
      const { json } = await api("terminal_select", { csrf });

      if (!json || json.ok !== true) throw new Error("Error al cargar terminales");

      render(
        json.terminales || json.terminals || [],
        Number(json.current || json.current_terminal_id || 0)
      );

    } catch (e) {
      console.error("terminal_select load error", e);
      setMsg('No se pudo cargar la lista de terminales. <button id="tsRetry" class="btn-mini" type="button">Reintentar</button>');
      toast("Error cargando terminales. Recargá o reintentá.", "error", 3500);
      const btn = document.getElementById("tsRetry");
      if (btn) btn.addEventListener("click", () => window.location.reload());
    }
  })();
})();
