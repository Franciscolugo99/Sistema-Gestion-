// public/assets/js/terminal_select.js
(() => {
  const script = document.currentScript;
  const base = script?.dataset?.base || "";
  const nextUrl = script?.dataset?.next || "";
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";

  const $grid = document.getElementById('grid');
  const $msg  = document.getElementById('msg');

  let selecting = false;

  function toast(msg, type = "info", ms = 2400) {
    // âœ… si FLUS ya tiene showToast, lo usamos (look consistente)
    if (typeof window.showToast === "function") {
      return window.showToast(msg, type, ms);
    }
    const t = document.createElement('div');
    t.className = 'ts-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => t.classList.remove('show'), ms);
    setTimeout(() => t.remove(), ms + 250);
  }

  async function api(action, payload, retries = 2, timeout = 10000) {
    for (let attempt = 0; attempt <= retries; attempt++) {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const r = await fetch(`${base}/api/index.php?action=${encodeURIComponent(action)}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
          },
          body: JSON.stringify(payload || {}),
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        let j = null;
        try { j = await r.json(); } catch (_) {}

        return { status: r.status, json: j };
      } catch (e) {
        const isLast = attempt === retries;
        if (isLast) throw e;
        await new Promise(res => setTimeout(res, 1000 * (attempt + 1)));
      }
    }
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
      // âœ… MySQL "YYYY-MM-DD HH:MM:SS" â†’ ISO "YYYY-MM-DDTHH:MM:SS"
      const norm = String(dateStr).includes('T') ? String(dateStr) : String(dateStr).replace(' ', 'T');
      const d = new Date(norm);
      if (isNaN(d.getTime())) return '';

      const today = new Date();
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);

      const time = d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });

      if (d.toDateString() === today.toDateString()) return `Hoy ${time}`;
      if (d.toDateString() === yesterday.toDateString()) return `Ayer ${time}`;

      return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
    } catch (_) {
      return '';
    }
  }

  function setMsg(html) {
    if ($msg) $msg.innerHTML = html;
  }

  function render(terminales, current) {
    $grid.innerHTML = '';

    if (!Array.isArray(terminales) || terminales.length === 0) {
      setMsg('No hay terminales disponibles.');
      return;
    }

    setMsg('ElegÃ­ una terminal para bloquearla y continuar.');

    terminales.forEach(t => {
      const id = Number(t.id || t.terminal_id || 0);
      const nombre = String(t.nombre || t.name || (`Caja #${id}`));
      const activo = Number(t.activo ?? 1) === 1;
      const isCurrent = id === Number(current || 0);
      const ultimoUso = t.ultimo_uso || t.last_used || null;

      const card = document.createElement('div');
      card.className = 'ts-card';
      card.tabIndex = 0;

      if (isCurrent) card.classList.add('current');
      if (!activo) card.classList.add('disabled');

      let metaLeft = `ID: ${id}`;
      let metaRight = isCurrent ? 'En uso ahora' : (activo ? 'Click para seleccionar' : 'Inactiva');

      const uso = ultimoUso ? formatDate(ultimoUso) : '';
      const metaMid = uso ? `Uso: ${uso}` : '';

      card.innerHTML = `
        <div class="ts-row">
          <div class="ts-name">${nombre}</div>
          <span class="ts-badge">
            <span class="${activo ? 'ts-status-dot' : 'ts-status-dot ts-dot-off'}"></span>
            ${isCurrent ? 'Actual' : (activo ? 'Activa' : 'Inactiva')}
          </span>
        </div>

        <div class="ts-meta">
          <span>${metaLeft}</span>
          <span title="${ultimoUso || ''}">${metaMid}</span>
          <span style="opacity:.85;">${metaRight}</span>
        </div>
      `;

      const handleSelect = async () => {
        if (!activo) return toast('Esta terminal estÃ¡ inactiva.', 'warn');
        if (isCurrent) return toast('Ya estÃ¡s usando esta terminal.', 'info');
        if (selecting || !id) return;

        selecting = true;
        card.classList.add('selecting');
        setMsg('<span class="ts-loader"></span> Seleccionando terminalâ€¦');

        try {
          const { status, json } = await api('terminal_select', { csrf, terminal_id: id });

          if (status === 409 && json?.error === 'CAJA_ABIERTA') {
            toast('No podÃ©s cambiar: hay una caja abierta en la terminal actual.', 'warn', 3200);
            setMsg('ElegÃ­ otra terminal o cerrÃ¡ la caja abierta.');
            card.classList.remove('selecting');
            selecting = false;
            return;
          }

          if (status === 403) {
            toast('No tenÃ©s permiso para usar esta terminal.', 'error', 2800);
            setMsg('ElegÃ­ otra terminal.');
            card.classList.remove('selecting');
            selecting = false;
            return;
          }

          if (!json || json.ok !== true) {
            throw new Error('Respuesta invÃ¡lida del servidor');
          }

          toast('Terminal seleccionada. Redirigiendoâ€¦', 'ok', 1500);
          setTimeout(() => { window.location.href = nextUrl; }, 800);

        } catch (e) {
          console.error(e);
          toast('No se pudo seleccionar la terminal. ReintentÃ¡.', 'error', 2800);
          setMsg('Error al seleccionar. IntentÃ¡ de nuevo.');
          card.classList.remove('selecting');
          selecting = false;
        }
      };

      card.addEventListener('click', handleSelect);
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleSelect();
        }
      });

      $grid.appendChild(card);
    });
  }

  (async () => {
    try {
      const { json } = await api('terminal_select', { csrf });

      if (!json || json.ok !== true) {
        throw new Error('Error al cargar terminales');
      }

      render(
        json.terminales || json.terminals || [],
        Number(json.current || json.current_terminal_id || 0)
      );

    } catch (e) {
      console.error(e);
      setMsg('No se pudo cargar la lista de terminales. <button id="tsRetry" class="btn-mini" type="button">Reintentar</button>');
      toast('Error cargando terminales. RecargÃ¡ o reintentÃ¡.', 'error', 3500);

      const btn = document.getElementById('tsRetry');
      if (btn) btn.addEventListener('click', () => window.location.reload());
    }
  })();
})();

