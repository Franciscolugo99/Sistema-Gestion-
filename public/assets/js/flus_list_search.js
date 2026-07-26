(() => {
  'use strict';

  const STORAGE_KEY = 'flus_list_search_focus_v1';
  const MAX_AGE_MS = 20000;
  const FIELD_NAMES = new Set(['q', 'search', 'buscar', 'busqueda']);

  const safeSessionGet = () => {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  };

  const safeSessionSet = (value) => {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch (_) {}
  };

  const safeSessionRemove = () => {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (_) {}
  };

  const inputKey = (input, index) => [
    window.location.pathname,
    input.form?.getAttribute('action') || '',
    input.name || '',
    input.id || String(index),
  ].join('|');

  const listSearchInputs = () => Array.from(document.querySelectorAll('form input[name]')).filter((input) => {
    const form = input.form;
    if (!form || String(form.method || 'get').toLowerCase() !== 'get') return false;
    if (!FIELD_NAMES.has(String(input.name || '').toLowerCase())) return false;
    return input.type !== 'hidden' && input.dataset.flusSearchSpecialized !== 'true';
  });

  const isVisible = (input) => Boolean(input.offsetWidth || input.offsetHeight || input.getClientRects().length);

  const markForRestore = (input, key) => {
    safeSessionSet({ key, at: Date.now(), cursor: String(input.value || '').length });
  };

  document.addEventListener('DOMContentLoaded', () => {
    const inputs = listSearchInputs();
    if (!inputs.length) return;

    inputs.forEach((input, index) => {
      const key = inputKey(input, index);
      let edited = false;

      input.classList.add('flus-list-search');
      input.autocomplete = 'off';
      input.setAttribute('enterkeyhint', 'search');
      if (index === 0) input.setAttribute('aria-keyshortcuts', 'Control+K Meta+K');

      input.addEventListener('input', () => {
        edited = true;
      });

      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') markForRestore(input, key);
      });

      input.form?.addEventListener('submit', () => {
        if (edited || document.activeElement === input) markForRestore(input, key);
      });

      window.addEventListener('beforeunload', () => {
        if (edited && document.activeElement === input) markForRestore(input, key);
      });
    });

    const saved = safeSessionGet();
    if (saved && Date.now() - Number(saved.at || 0) <= MAX_AGE_MS) {
      const targetIndex = inputs.findIndex((input, index) => inputKey(input, index) === saved.key);
      const target = targetIndex >= 0 ? inputs[targetIndex] : null;
      if (target && isVisible(target)) {
        requestAnimationFrame(() => {
          target.focus({ preventScroll: true });
          const cursor = Math.min(Number(saved.cursor ?? target.value.length), target.value.length);
          target.setSelectionRange?.(cursor, cursor);
        });
      }
    }
    safeSessionRemove();

    document.addEventListener('keydown', (event) => {
      if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k') return;
      const target = inputs.find(isVisible);
      if (!target) return;
      event.preventDefault();
      target.focus({ preventScroll: true });
      target.select?.();
    });
  });
})();
