// public/assets/js/nav.js
(function () {
  'use strict';

  const nav = document.querySelector('.nav-container');
  if (!nav || nav.dataset.navReady === '1') {
    return;
  }
  nav.dataset.navReady = '1';

  const hamburger = document.getElementById('navHamburger');
  const navMenu = document.getElementById('navMenu');
  const dropdowns = Array.from(document.querySelectorAll('.js-nav-dropdown'));
  const shortcutHelpBtn = document.getElementById('navShortcutHelpBtn');
  const logoutBtn = nav.querySelector('[data-logout-link="1"]');
  let scrollY = 0;

  function parseShortcuts() {
    try {
      return JSON.parse(nav.dataset.shortcuts || '{}');
    } catch (error) {
      return {};
    }
  }

  function getToast() {
    let toast = document.querySelector('.nav-shortcut-toast');
    if (toast) return toast;
    toast = document.createElement('div');
    toast.className = 'nav-shortcut-toast';
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
    return toast;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  // ─── Recolecta todos los módulos navegables del DOM ───────────────────────
  function collectNavItems(shortcuts) {
    const items = [];
    const seen = new Set();

    const shortcutByHref = {};
    Object.entries(shortcuts).forEach(([key, href]) => {
      shortcutByHref[href] = key.toUpperCase();
    });

    function addItem(el, labelOverride) {
      const href = el.getAttribute('href');
      if (!href || href === '#' || href.startsWith('logout') || seen.has(href)) return;
      seen.add(href);
      const rawText = labelOverride || el.textContent || '';
      const label = rawText.replace(/ALT\+[\S]+/gi, '').replace(/\s+/g, ' ').trim();
      if (!label) return;
      items.push({
        label,
        href,
        shortcut: el.dataset.shortcut
          ? el.dataset.shortcut.toUpperCase()
          : (shortcutByHref[href] || ''),
        active: el.classList.contains('active'),
      });
    }

    // Botón Vender (prioritario)
    const vender = nav.querySelector('.nav-vender[href]');
    if (vender) addItem(vender, 'Vender');

    // Pills directos (Panel, Ventas, Compras)
    nav.querySelectorAll('.nav-left .nav-pill:not(.nav-group-btn)[href]').forEach(el => addItem(el));

    // Ítems de los grupos dropdown (Catálogo, Inventario, etc.)
    nav.querySelectorAll('.nav-group-menu a[role="menuitem"][href]').forEach(el => addItem(el));

    // Ítems del menú admin
    const adminMenu = document.getElementById('adminMenu');
    if (adminMenu) adminMenu.querySelectorAll('a[role="menuitem"][href]').forEach(el => addItem(el));

    return items;
  }

  // ─── Panel de búsqueda rápida ──────────────────────────────────────────────
  function buildQuickNav(allItems) {
    const panel = document.createElement('div');
    panel.id = 'navShortcutHelp';
    panel.className = 'nav-shortcut-help';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'Navegación rápida');

    panel.innerHTML = `
      <div class="nav-shortcut-help-backdrop" data-nav-shortcut-close="1"></div>
      <div class="nav-shortcut-help-card">
        <div class="nav-shortcut-help-head">
          <div class="nav-shortcut-help-title">
            <strong id="navShortcutHelpTitle">Ir a módulo</strong>
            <span>Escribí para filtrar &middot; ↑↓ para mover &middot; Enter para ir</span>
          </div>
          <button type="button" class="nav-shortcut-help-close" data-nav-shortcut-close="1" aria-label="Cerrar">×</button>
        </div>
        <div class="nav-quick-search-wrap">
          <input type="text"
                 class="nav-quick-search"
                 placeholder="Buscar módulo..."
                 autocomplete="off"
                 spellcheck="false"
                 aria-label="Buscar módulo"
                 aria-controls="navQuickList">
        </div>
        <div class="nav-shortcut-help-body">
          <div class="nav-shortcut-help-list" id="navQuickList" role="listbox" aria-label="Módulos disponibles"></div>
        </div>
      </div>`;

    const input = panel.querySelector('.nav-quick-search');
    const list  = panel.querySelector('#navQuickList');

    function renderItems(query) {
      const q = query.trim().toLowerCase();
      const filtered = q
        ? allItems.filter(item => item.label.toLowerCase().includes(q))
        : allItems;

      if (filtered.length === 0) {
        list.innerHTML = `<div class="nav-shortcut-help-empty">Sin resultados para "${escapeHtml(query)}"</div>`;
        return;
      }

      list.innerHTML = filtered
        .map((item, i) => `
          <a class="nav-shortcut-help-item nav-quick-item${item.active ? ' is-current' : ''}"
             href="${escapeHtml(item.href)}"
             role="option"
             data-idx="${i}"
             tabindex="-1"
             aria-selected="${item.active ? 'true' : 'false'}">
            <span class="nav-shortcut-help-label">
              ${item.active ? '<span class="nav-quick-dot" aria-hidden="true"></span>' : ''}${escapeHtml(item.label)}
            </span>
            ${item.shortcut ? `<span class="nav-shortcut-help-key">${escapeHtml(item.shortcut)}</span>` : ''}
          </a>`)
        .join('');
    }

    renderItems('');

    // Re-render on search
    input.addEventListener('input', () => {
      renderItems(input.value);
    });

    // Teclado dentro del input
    input.addEventListener('keydown', (e) => {
      const els = Array.from(list.querySelectorAll('.nav-quick-item'));
      if (!els.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        els[0].focus();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        window.location.href = els[0].getAttribute('href');
      }
    });

    // Teclado dentro de la lista
    list.addEventListener('keydown', (e) => {
      const els = Array.from(list.querySelectorAll('.nav-quick-item'));
      const cur = e.target.closest('.nav-quick-item');
      const idx = cur ? parseInt(cur.dataset.idx, 10) : 0;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        const next = els.find(el => parseInt(el.dataset.idx, 10) > idx);
        if (next) next.focus(); else els[0]?.focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = [...els].reverse().find(el => parseInt(el.dataset.idx, 10) < idx);
        if (prev) prev.focus(); else input.focus();
      }
    });

    // Cerrar
    panel.addEventListener('click', (e) => {
      if (e.target.closest('[data-nav-shortcut-close="1"]')) {
        panel.classList.remove('is-open');
      }
    });

    document.body.appendChild(panel);
    return panel;
  }

  function lockBodyScroll() {
    scrollY = window.scrollY || 0;
    document.documentElement.classList.add('nav-open');
    document.body.style.cssText = `position:fixed;top:-${scrollY}px;left:0;right:0;width:100%`;
  }

  function unlockBodyScroll() {
    document.documentElement.classList.remove('nav-open');
    document.body.style.cssText = '';
    window.scrollTo(0, scrollY);
  }

  function setNavOpen(open) {
    if (!hamburger || !navMenu) return;
    hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
    hamburger.classList.toggle('active', open);
    navMenu.classList.toggle('open', open);
    if (open) lockBodyScroll(); else unlockBodyScroll();
  }

  function closeShortcutHelp() {
    const help = document.getElementById('navShortcutHelp');
    if (help) help.classList.remove('is-open');
  }

  function setDropdownOpen(wrapper, open) {
    const btn  = wrapper.querySelector('.nav-dropdown-btn, .nav-group-btn, .nav-bell-btn');
    const menu = wrapper.querySelector('.nav-dropdown-menu');
    if (!btn || !menu) return;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.classList.toggle('open', open);
    wrapper.classList.toggle('open', open);
  }

  function closeOthers(current) {
    dropdowns.forEach((wrapper) => {
      if (wrapper !== current) setDropdownOpen(wrapper, false);
    });
  }

  if (hamburger && navMenu) {
    hamburger.addEventListener('click', () => {
      setNavOpen(hamburger.getAttribute('aria-expanded') !== 'true');
    });

    navMenu.addEventListener('click', (event) => {
      if (event.target.closest('a.nav-pill, .nav-dropdown-menu a, .nav-vender')) {
        setNavOpen(false);
      }
    });
  }

  dropdowns.forEach((wrapper) => {
    const btn = wrapper.querySelector('.nav-dropdown-btn, .nav-group-btn, .nav-bell-btn');
    if (!btn) return;

    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      closeOthers(wrapper);
      setDropdownOpen(wrapper, !isOpen);
    });
  });

  document.addEventListener('click', (event) => {
    if (
      hamburger &&
      hamburger.getAttribute('aria-expanded') === 'true' &&
      !hamburger.contains(event.target) &&
      navMenu &&
      !navMenu.contains(event.target)
    ) {
      setNavOpen(false);
    }

    dropdowns.forEach((wrapper) => {
      if (!wrapper.contains(event.target)) setDropdownOpen(wrapper, false);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setNavOpen(false);
      dropdowns.forEach((wrapper) => setDropdownOpen(wrapper, false));
      closeShortcutHelp();
    }
  });

  // ─── Atajos de teclado ─────────────────────────────────────────────────────
  const shortcuts = parseShortcuts();
  const toast = getToast();
  let toastTimer = null;

  function showToast(message) {
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.add('show');
    toastTimer = window.setTimeout(() => toast.classList.remove('show'), 1200);
  }

  function openQuickNav() {
    // Reconstruye siempre para reflejar estado activo actual
    const existing = document.getElementById('navShortcutHelp');
    if (existing) existing.remove();

    const allItems = collectNavItems(shortcuts);
    const panel = buildQuickNav(allItems);
    panel.classList.add('is-open');

    // Scroll al ítem activo si existe
    setTimeout(() => {
      const searchInput = panel.querySelector('.nav-quick-search');
      searchInput?.focus();
      panel.querySelector('.nav-quick-item.is-current')?.scrollIntoView({ block: 'nearest' });
    }, 40);
  }

  if (shortcutHelpBtn) {
    shortcutHelpBtn.addEventListener('click', () => openQuickNav());
  }

  if (logoutBtn && logoutBtn.dataset.cajaOpen === '1') {
    logoutBtn.addEventListener('click', async (event) => {
      event.preventDefault();

      const href = logoutBtn.getAttribute('href') || 'logout.php';
      const title = 'Caja abierta';
      const body =
        'Hay una caja abierta en esta terminal. Si salís ahora, la sesión se cerrará igual y tendrás que volver para cerrar la caja correctamente.';

      if (!window.Notif || typeof window.Notif.confirmar !== 'function') return;
      const confirmed = await window.Notif.confirmar(title, body, {
        icon: 'warning',
        confirmText: 'Salir igual',
        cancelText: 'Quedarme',
        useText: true,
        danger: true,
      });

      if (confirmed) window.location.href = href;
    });
  }

  document.addEventListener('keydown', (event) => {
    const tag = (event.target?.tagName ?? '').toLowerCase();
    if (['input', 'textarea', 'select'].includes(tag)) return;
    if (event.target?.isContentEditable) return;

    if (event.key === '?') {
      event.preventDefault();
      openQuickNav();
      return;
    }
    if (!event.altKey) return;

    const key = `alt+${event.key.toLowerCase()}`;
    if (!(key in shortcuts)) return;

    event.preventDefault();
    const url = shortcuts[key];
    const label = collectNavItems(shortcuts).find(i => i.href === url)?.label || url;
    showToast(`-> ${label}`);
    window.location.href = url;
  });
})();
