/** One token owner for ordinary forms, HTMX, modal saves and upload responses. */
(function () {
  'use strict';
  if (window.SynapseCsrf) return;
  const meta = name => document.querySelector(`meta[name="${name}"]`);
  function sync(csrf) {
    if (!csrf || typeof csrf.name !== 'string' || typeof csrf.hash !== 'string' || !csrf.name || !csrf.hash) return;
    const oldName = meta('csrf-name')?.content;
    document.querySelectorAll('input[type="hidden"]').forEach(input => {
      if (input.name === csrf.name || (oldName && input.name === oldName)) {
        input.name = csrf.name;
        input.value = csrf.hash;
      }
    });
    if (meta('csrf-name')) meta('csrf-name').content = csrf.name;
    if (meta('csrf-token')) meta('csrf-token').content = csrf.hash;
    if (csrf.header && meta('csrf-header')) meta('csrf-header').content = csrf.header;
  }
  function fromResponse(response) {
    const get = name => response?.headers?.get ? response.headers.get(name) : response?.getResponseHeader?.(name);
    const hash = get('X-CSRF-TOKEN');
    const name = get('X-CSRF-NAME') || meta('csrf-name')?.content;
    if (hash && name) sync({ name, hash, header: get('X-CSRF-HEADER') });
  }
  function headers() {
    const name = meta('csrf-header')?.content;
    const hash = meta('csrf-token')?.content;
    return name && hash ? { [name]: hash } : {};
  }
  document.addEventListener('formdata', event => {
    if (event.target.method?.toUpperCase() === 'GET') return;
    const name = meta('csrf-name')?.content;
    const hash = meta('csrf-token')?.content;
    if (name && hash) event.formData.set(name, hash);
  }, true);
  document.addEventListener('htmx:configRequest', event => Object.assign(event.detail.headers, headers()));
  document.addEventListener('htmx:afterRequest', event => fromResponse(event.detail.xhr));
  window.SynapseCsrf = { sync, fromResponse, headers };
})();
