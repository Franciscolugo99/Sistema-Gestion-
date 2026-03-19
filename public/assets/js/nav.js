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
    if (toast) {
      return toast;
    }

    toast = document.createElement('div');
    toast.className = 'nav-shortcut-toast';
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
    return toast;
  }

  function getShortcutItems(shortcuts) {
    return Object.keys(shortcuts)
      .map((shortcut) => {
        const el = document.querySelector(`[data-shortcut="${shortcut}"]`);
        const label =
          (el && el.querySelector('.nav-vender-label') && el.querySelector('.nav-vender-label').textContent) ||
          (el && el.textContent ? el.textContent.replace(/alt\+\S+/gi, '').trim() : '') ||
          shortcuts[shortcut];

        return {
          label: label.trim(),
          shortcut: shortcut.toUpperCase(),
        };
      })
      .sort((a, b) => a.shortcut.localeCompare(b.shortcut));
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function getShortcutHelp(shortcuts) {
    let help = document.getElementById('navShortcutHelp');
    if (help) {
      return help;
    }

    help = document.createElement('div');
    help.id = 'navShortcutHelp';
    help.className = 'nav-shortcut-help';
    help.innerHTML = `
      <div class="nav-shortcut-help-backdrop" data-nav-shortcut-close="1"></div>
      <div class="nav-shortcut-help-card" role="dialog" aria-modal="true" aria-labelledby="navShortcutHelpTitle">
        <div class="nav-shortcut-help-head">
          <div class="nav-shortcut-help-title">
            <strong id="navShortcutHelpTitle">Atajos del teclado</strong>
            <span>Accesos rapidos del menu principal.</span>
          </div>
          <button type="button" class="nav-shortcut-help-close" data-nav-shortcut-close="1" aria-label="Cerrar ayuda de atajos">×</button>
        </div>
        <div class="nav-shortcut-help-body">
          <div class="nav-shortcut-help-list"></div>
        </div>
      </div>
    `;

    const list = help.querySelector('.nav-shortcut-help-list');
    const items = getShortcutItems(shortcuts);
    if (items.length === 0) {
      list.innerHTML = '<div class="nav-shortcut-help-empty">No hay atajos configurados en este modulo.</div>';
    } else {
      list.innerHTML = items
        .map(
          (item) => `
            <div class="nav-shortcut-help-item">
              <span class="nav-shortcut-help-label">${escapeHtml(item.label)}</span>
              <span class="nav-shortcut-help-key">${escapeHtml(item.shortcut)}</span>
            </div>
          `
        )
        .join('');
    }

    help.addEventListener('click', (event) => {
      if (event.target.closest('[data-nav-shortcut-close="1"]')) {
        help.classList.remove('is-open');
      }
    });

    document.body.appendChild(help);
    return help;
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
    if (!hamburger || !navMenu) {
      return;
    }

    hamburger.setAttribute('aria-expanded', open ? 'true' : 'false');
    hamburger.classList.toggle('active', open);
    navMenu.classList.toggle('open', open);

    if (open) {
      lockBodyScroll();
    } else {
      unlockBodyScroll();
    }
  }

  function closeShortcutHelp() {
    const help = document.getElementById('navShortcutHelp');
    if (help) {
      help.classList.remove('is-open');
    }
  }

  function setDropdownOpen(wrapper, open) {
    const btn = wrapper.querySelector('.nav-dropdown-btn, .nav-group-btn, .nav-bell-btn');
    const menu = wrapper.querySelector('.nav-dropdown-menu');
    if (!btn || !menu) {
      return;
    }

    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.classList.toggle('open', open);
    wrapper.classList.toggle('open', open);
  }

  function closeOthers(current) {
    dropdowns.forEach((wrapper) => {
      if (wrapper !== current) {
        setDropdownOpen(wrapper, false);
      }
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
    if (!btn) {
      return;
    }

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
      if (!wrapper.contains(event.target)) {
        setDropdownOpen(wrapper, false);
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setNavOpen(false);
      dropdowns.forEach((wrapper) => setDropdownOpen(wrapper, false));
      closeShortcutHelp();
    }
  });

  const shortcuts = parseShortcuts();
  const toast = getToast();
  let toastTimer = null;

  function showToast(message) {
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.add('show');
    toastTimer = window.setTimeout(() => {
      toast.classList.remove('show');
    }, 1200);
  }

  function openShortcutHelp() {
    const help = getShortcutHelp(shortcuts);
    help.classList.add('is-open');
  }

  if (shortcutHelpBtn) {
    shortcutHelpBtn.addEventListener('click', () => {
      openShortcutHelp();
    });
  }

  document.addEventListener('keydown', (event) => {
    const tag = (event.target && event.target.tagName ? event.target.tagName : '').toLowerCase();
    if (['input', 'textarea', 'select'].includes(tag)) {
      return;
    }
    if (event.target && event.target.isContentEditable) {
      return;
    }
    if (event.key === '?') {
      event.preventDefault();
      openShortcutHelp();
      return;
    }
    if (!event.altKey) {
      return;
    }

    const key = `alt+${event.key.toLowerCase()}`;
    if (!(key in shortcuts)) {
      return;
    }

    event.preventDefault();

    const url = shortcuts[key];
    const item = getShortcutItems(shortcuts).find((entry) => entry.shortcut === key.toUpperCase());
    const label = item ? item.label : url;

    showToast(`-> ${label.trim()}`);
    window.location.href = url;
  });
})();
