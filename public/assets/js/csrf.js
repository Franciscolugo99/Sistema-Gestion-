// public/assets/js/csrf.js
// Patch CSRF: asegura que fetch/XHR incluyan X-CSRF-Token de forma robusta.
(function () {
  'use strict';

  if (window.__FLUS_CSRF_JS_LOADED__) return;
  window.__FLUS_CSRF_JS_LOADED__ = true;

  function readMetaToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta && meta.content ? String(meta.content) : '';
  }

  function getToken() {
    return String(window.CSRF_TOKEN || readMetaToken() || '').trim();
  }

  function setHeader(headers, key, value) {
    if (!value) return;

    try {
      // Headers instance
      if (headers && typeof headers.set === 'function') {
        if (!headers.has(key)) headers.set(key, value);
        return;
      }

      // Array of pairs
      if (Array.isArray(headers)) {
        var exists = headers.some(function (p) { return p && String(p[0]).toLowerCase() === key.toLowerCase(); });
        if (!exists) headers.push([key, value]);
        return;
      }

      // Plain object
      headers = headers || {};
      var has = Object.prototype.hasOwnProperty.call(headers, key);
      if (!has) headers[key] = value;
    } catch (e) {
      // no-op
    }
  }

  function patchFetch() {
    if (!window.fetch || window.__FLUS_CSRF_FETCH_PATCHED__) return;
    window.__FLUS_CSRF_FETCH_PATCHED__ = true;

    var _fetch = window.fetch;
    window.fetch = function (input, init) {
      init = init || {};
      init.headers = init.headers || {};

      var t = getToken();
      setHeader(init.headers, 'X-CSRF-Token', t);

      return _fetch(input, init);
    };
  }

  function patchXHR() {
    if (!window.XMLHttpRequest || window.__FLUS_CSRF_XHR_PATCHED__) return;
    window.__FLUS_CSRF_XHR_PATCHED__ = true;

    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
      this.__flus_csrf_url__ = url;
      return open.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
      try {
        var t = getToken();
        if (t) {
          // Solo misma origin o rutas relativas
          var u = String(this.__flus_csrf_url__ || '');
          var sameOrigin = (u.indexOf('http://') !== 0 && u.indexOf('https://') !== 0) || u.indexOf(location.origin) === 0;
          if (sameOrigin) {
            this.setRequestHeader('X-CSRF-Token', t);
          }
        }
      } catch (e) {
        // ignore
      }
      return send.apply(this, arguments);
    };
  }

  // Exponer helper
  window.flusCsrfToken = getToken;
  // Alias esperado por módulos viejos
  if (!window.getCsrfToken) window.getCsrfToken = getToken;
  // Mantener un token global si falta meta
  if (!window.CSRF_TOKEN) window.CSRF_TOKEN = getToken();

  patchFetch();
  patchXHR();
})();
