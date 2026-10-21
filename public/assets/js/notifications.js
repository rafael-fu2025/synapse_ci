/** NiceAdmin v3 personal notification menu; private, bounded polling, POST-only read state. */
(function () {
  'use strict';
  const root = document.querySelector('[data-notifications]');
  if (!root || root.dataset.notificationsBound === 'true') return;
  root.dataset.notificationsBound = 'true';
  const bell = root.querySelector('#notificationBell');
  const badge = root.querySelector('[data-notification-badge]');
  const count = root.querySelector('[data-notification-count]');
  const list = root.querySelector('[data-notification-list]');
  const markAll = root.querySelector('[data-notification-mark-all]');
  const feedback = root.querySelector('[data-notification-error]');
  let controller, timer, pollGeneration = 0, generation = 0, writing = false, unread = 0, stopped = false;

  function httpDestination(value) {
    try {
      if (typeof value !== 'string' || !value) return null;
      const url = new URL(value, location.href);
      return /^https?:$/.test(url.protocol) && !url.username && !url.password ? url : null;
    } catch (_) { return null; }
  }
  const destinations = new Set([root.dataset.portalUrl, root.dataset.clinicReferralsUrl, root.dataset.guidanceReferralsUrl,
      root.dataset.announcementsUrl, root.dataset.surveysUrl]
    .map(value => httpDestination(value)?.href).filter(Boolean));
  const portalUrl = httpDestination(root.dataset.portalUrl)?.href;
  function destination(item) {
    try {
      const url = httpDestination(item.url);
      if (url && destinations.has(url.href)) return url.href;
    } catch (_) { /* Untrusted or missing destinations fall back to the personal portal. */ }
    return portalUrl;
  }
  function error(message) {
    root.querySelector('[data-notification-error-text]').textContent = message;
    feedback.classList.remove('d-none');
  }
  function clear() {
    list.replaceChildren();
    badge.classList.add('d-none');
    markAll.disabled = true;
    unread = 0;
    count.textContent = 'Sign in again to check notifications.';
    bell.setAttribute('aria-label', 'Notifications');
  }
  function render(payload) {
    if (!payload || !Array.isArray(payload.items) || !Number.isInteger(payload.unread_count) || payload.unread_count < 0) {
      throw new Error('Invalid notifications response.');
    }
    unread = payload.unread_count;
    const label = unread >= 100 ? '99+' : String(unread);
    badge.textContent = label;
    badge.classList.toggle('d-none', unread === 0);
    count.textContent = `${label} unread`;
    bell.setAttribute('aria-label', unread ? `Notifications, ${label} unread` : 'Notifications, no unread items');
    const fragment = document.createDocumentFragment();
    for (const item of payload.items.slice(0, 10)) {
      if (!Number.isSafeInteger(item.id) || item.id <= 0 || typeof item.title !== 'string' || typeof item.message !== 'string') continue;
      const li = document.createElement('li');
      const link = document.createElement('a');
      link.className = 'notification-item' + (item.read_at ? '' : ' unread');
      const target = destination(item); // Exact server-configured destinations, never an origin wildcard.
      if (!target) continue;
      link.href = target;
      if (new URL(target).origin !== location.origin) link.dataset.navigation = 'false';
      link.dataset.notificationId = String(item.id);
      const dot = document.createElement('span');
      dot.className = 'notification-dot';
      dot.setAttribute('aria-hidden', 'true');
      const icon = document.createElement('span');
      icon.className = 'notification-icon success';
      icon.setAttribute('aria-hidden', 'true');
      const glyph = document.createElement('i');
      glyph.className = ({ 'Incoming referral': 'bi bi-arrow-left-right', 'New announcement': 'bi bi-megaphone',
              'New survey': 'bi bi-clipboard-check' })[item.title] || 'bi bi-calendar-check';
      icon.append(glyph);
      const content = document.createElement('span');
      content.className = 'notification-content';
      const title = document.createElement('span');
      title.className = 'notification-title';
      title.textContent = item.title;
      const text = document.createElement('span');
      text.className = 'notification-text';
      text.textContent = item.message;
      const time = document.createElement('span');
      time.className = 'notification-time';
      time.textContent = typeof item.created_at === 'string' ? item.created_at.slice(0, 16).replace('T', ' ') : '';
      time.title = 'Application local time';
      content.append(title, text, time);
      link.append(dot, icon, content);
      if (!item.read_at) link.setAttribute('aria-label', `Unread: ${item.title}. ${item.message}`);
      li.append(link);
      fragment.append(li);
    }
    if (!fragment.childNodes.length) {
      const empty = document.createElement('li');
      empty.className = 'p-3 small text-muted';
      empty.textContent = 'No notifications yet. Appointments, announcements, and surveys will appear here.';
      fragment.append(empty);
    }
    list.replaceChildren(fragment);
    markAll.disabled = writing || unread === 0;
    feedback.classList.add('d-none');
  }
  async function request(url, write) {
    const options = { credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (write) {
      options.method = 'POST';
      options.headers = { ...options.headers, ...window.SynapseCsrf?.headers() };
    }
    const response = await fetch(url, options);
    window.SynapseCsrf?.fromResponse(response);
    const payload = await response.json();
    window.SynapseCsrf?.sync(payload.csrf);
    if (response.status === 401 || response.status === 403) {
      const failure = new Error('Your session or security token changed. Refresh the page or sign in again.');
      failure.authentication = true;
      throw failure;
    }
    if (!response.ok || response.redirected) throw new Error('Notifications are temporarily unavailable. Please retry.');
    return payload;
  }
  async function refresh() {
    if (writing || stopped || document.hidden) return;
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    try {
      const payload = await request(root.dataset.listUrl, false);
      if (current === generation && !stopped) render(payload);
    } catch (failure) {
      if (failure.name !== 'AbortError' && current === generation && !stopped) {
        if (failure.authentication) clear();
        error('Unable to refresh notifications. Please retry or sign in again.');
      }
    }
  }
  async function read(id, target = portalUrl) {
    if (writing || stopped) return;
    // Recheck the clicked href as well as the API payload, including after DOM changes.
    const targetUrl = destination({ url: target });
    writing = true;
    root.setAttribute('aria-busy', 'true');
    markAll.disabled = true;
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    let navigate = false;
    try {
      const url = id ? root.dataset.readBase.replace(/\/$/, '') + '/' + id + '/read' : root.dataset.readAllUrl;
      const payload = await request(url, true);
      if (current === generation && !stopped) {
        render(payload);
        navigate = Boolean(id);
      }
    } catch (failure) {
      if (failure.name !== 'AbortError' && current === generation && !stopped) {
        if (failure.authentication) clear();
        error('Could not mark notifications read. Your unread state has not been cleared locally; retry or refresh.');
      }
    } finally {
      writing = false;
      root.removeAttribute('aria-busy');
      markAll.disabled = unread === 0;
    }
    if (navigate && targetUrl) {
      if (new URL(targetUrl).origin === location.origin && window.SynapseNavigation) {
        void window.SynapseNavigation.navigate(targetUrl);
      } else {
        // Cross-host sessions are intentionally host-only. Do not fetch or forward CSRF.
        location.assign(targetUrl);
      }
    }
  }
  markAll.addEventListener('click', () => read(null));
  list.addEventListener('click', event => {
    const link = event.target.closest('[data-notification-id]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    read(Number(link.dataset.notificationId), link.href);
  });
  root.querySelector('[data-notification-retry]').addEventListener('click', refresh);
  // The hover tooltip must never cover or intercept actions in the open menu.
  root.addEventListener('show.bs.dropdown', () => {
    const tooltip = window.bootstrap?.Tooltip.getInstance(root);
    tooltip?.hide();
    tooltip?.disable();
  });
  root.addEventListener('hidden.bs.dropdown', () => window.bootstrap?.Tooltip.getInstance(root)?.enable());
  root.addEventListener('shown.bs.dropdown', refresh);
  function schedule() {
    clearTimeout(timer);
    const poll = ++pollGeneration;
    if (stopped || document.hidden) return;
    timer = setTimeout(async () => {
      await refresh();
      if (poll === pollGeneration) schedule();
    }, 30000);
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      ++pollGeneration;
      clearTimeout(timer);
      if (!writing) { ++generation; controller?.abort(); }
    } else { refresh(); schedule(); }
  });
  window.addEventListener('pagehide', () => { stopped = true; ++generation; ++pollGeneration; clearTimeout(timer); controller?.abort(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; refresh(); schedule(); } });
  // The shell persists: refresh its data without starting another poll loop.
  document.addEventListener('synapse:page-load', refresh);
  if (!window.SynapseNavigation || document.readyState !== 'loading') refresh();
  schedule();
})();
