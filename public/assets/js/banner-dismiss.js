(() => {
  'use strict';

  if (window.SynapseBannerDismiss) return;

  // One storage namespace per account, so dismissals never leak across users
  // sharing a browser. Contents stay small: { bannerHash: dismissedAt }.
  const MAX_ENTRIES = 200;

  let store = null;

  /** Per-account storage key; falls back to a shared bucket for anonymous pages. */
  function storageKey() {
    const body = document.body;
    const userId = body?.getAttribute('data-user-id') || '';
    const tenantId = body?.getAttribute('data-tenant-id') || '';
    const scope = tenantId ? `${tenantId}:` : '';
    return `synapse-banner-dismissals.${scope}${userId}`;
  }

  function loadStore() {
    if (store) return store;
    try {
      const raw = window.localStorage.getItem(storageKey());
      const parsed = raw ? JSON.parse(raw) : null;
      store = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
      store = {};
    }
    return store;
  }

  function saveStore() {
    const entries = Object.entries(store).sort((a, b) => a[1] - b[1]);
    while (entries.length > MAX_ENTRIES) entries.shift();
    store = Object.fromEntries(entries);
    try {
      window.localStorage.setItem(storageKey(), JSON.stringify(store));
    } catch {
      // Storage unavailable (private mode, quota): banners just reappear.
    }
  }

  /** FNV-1a over the banner text, so edited banners reappear automatically. */
  function contentHash(text) {
    let hash = 0x811c9dc5;
    for (let i = 0; i < text.length; i += 1) {
      hash ^= text.charCodeAt(i);
      hash = Math.imul(hash, 0x01000193) >>> 0;
    }
    return hash.toString(16);
  }

  function bannerKey(alert) {
    const explicit = alert.getAttribute('data-banner-id');
    if (explicit) return explicit;
    const text = (alert.textContent || '').replace(/\s+/g, ' ').trim();
    return contentHash(text);
  }

  /** Only persistent, visible, informational banners participate. */
  function isDismissTarget(alert) {
    if (!alert.closest('main')) return false;
    if (alert.classList.contains('login-banner')) return false;
    if (alert.classList.contains('alert-dismissible')) return false; // flash alerts are transient already
    if (alert.closest('.modal') || alert.closest('noscript')) return false;
    if (alert.closest('[x-data]')) return false; // Alpine-driven feedback is dynamic
    if (alert.hasAttribute('data-banner-dismiss') && alert.getAttribute('data-banner-dismiss') === 'off') return false;
    if (alert.hidden || alert.classList.contains('d-none')) return false;
    if (alert.offsetParent === null) return false; // inside a hidden container
    return (alert.textContent || '').trim().length > 0;
  }

  function remember(key) {
    loadStore()[key] = Date.now();
    saveStore();
  }

  function arm(alert) {
    const key = bannerKey(alert);
    if (loadStore()[key]) {
      alert.remove();
      return;
    }
    alert.classList.add('alert-dismissible', 'fade', 'show');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.setAttribute('data-bs-dismiss', 'alert');
    close.setAttribute('aria-label', 'Close');
    close.addEventListener('click', () => remember(key));
    alert.append(close);
  }

  function init(root) {
    const scope = root || document;
    const alerts = scope.matches?.('.alert') ? [scope] : Array.from(scope.querySelectorAll('.alert'));
    alerts.forEach(alert => {
      if (isDismissTarget(alert)) arm(alert);
    });
  }

  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));

  window.SynapseBannerDismiss = { init };
})();
