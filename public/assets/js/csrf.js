// public/assets/js/csrf.js
// Inyecta X-CSRF-Token en cada fetch/$.ajax si hay meta[name="csrf-token"]
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';

  if (!token) return;

  // fetch()
  var _fetch = window.fetch;
  window.fetch = function(input, init) {
    init = init || {};
    var headers = new Headers(init.headers || {});
    if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
    init.headers = headers;
    return _fetch(input, init);
  };

  // jQuery.ajax
  if (window.jQuery && window.jQuery.ajaxSetup) {
    window.jQuery.ajaxSetup({
      headers: { 'X-CSRF-Token': token }
    });
  }
})();
