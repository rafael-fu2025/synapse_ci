/** Act only on an explicit server authentication denial, never on an inferred timeout. */
(function () {
  'use strict';
  if (window.SynapseAuth) return;
  const reasons = new Set(['session_expired', 'reauthentication_required', 'authentication_required']);
  let redirecting = false;

  function handle(response) {
    if (!response || response.status !== 401) return false;
    const header = name => response.headers?.get?.(name) ?? response.getResponseHeader?.(name);
    const reason = header('X-Synapse-Auth');
    if (!reasons.has(reason)) return false;
    let login;
    try {
      const configured = document.querySelector('meta[name="synapse-login-url"]')?.content;
      const supplied = header('X-Synapse-Login');
      if (!configured || !supplied) return false;
      const expected = new URL(configured, location.href);
      login = new URL(supplied, location.href);
      if (!/^https?:$/.test(login.protocol) || login.origin !== location.origin || login.username || login.password
          || login.search || login.hash || login.href !== expected.href) return false;
      const source = response.url || response.responseURL;
      if (source && new URL(source, location.href).origin !== location.origin) return false;
    } catch (_) { return false; }
    if (redirecting) return true;
    if (location.pathname === login.pathname) return false;
    redirecting = true;
    // Login renders the server-owned notice; don't invent a reason in a URL or replay a failed POST.
    document.dispatchEvent(new CustomEvent('synapse:auth-required', { detail: { reason } }));
    location.replace(login.href);
    return true;
  }

  window.SynapseAuth = Object.freeze({ handle, get redirecting() { return redirecting; } });
})();
