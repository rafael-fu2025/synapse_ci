/** Personal active alerts: page-owned state, exact destinations, explicit POST-only read controls. */
(function () {
  'use strict';
  if (window.SynapseNotificationHistory) return;
  const instances = new Map();
  function httpDestination(value) {
    try {
      if (typeof value !== 'string' || !value) return null;
      const url = new URL(value, location.href);
      return /^https?:$/.test(url.protocol) && !url.username && !url.password ? url : null;
    } catch (_) { return null; }
  }
  function mount(root) {
    const events = new AbortController();
    const on = (target, type, callback) => target.addEventListener(type, callback, { signal: events.signal });
    const find = name => root.querySelector(`[data-history-${name}]`);
    const list = find('list'), status = find('status'), feedback = find('error');
    const markAll = find('mark-all'), previous = find('previous'), next = find('next');
    const filters = [...root.querySelectorAll('[data-history-filter]')];
    let configured = {};
    try { configured = JSON.parse(root.dataset.destinations); } catch (_) { /* No untrusted fallback. */ }
    const destinations = new Set(Object.values(configured).map(value => httpDestination(value)?.href).filter(Boolean));
    const chatDestinations = [configured.clinicTeamChat, configured.guidanceTeamChat, configured.facilitiesTeamChat]
      .map(httpDestination).filter(url => url && !url.search && !url.hash);
    const portal = httpDestination(configured.portal)?.href;
    function destination(value) {
      const url = httpDestination(value);
      if (url && destinations.has(url.href)) return url.href;
      if (url) {
        const message = url.searchParams.get('message');
        if (/^[1-9][0-9]*$/.test(message || '') && Number.isSafeInteger(Number(message))
            && chatDestinations.some(base => url.href === `${base.href}?message=${message}#chat-message-${message}`)) return url.href;
      }
      return portal;
    }
    let filter = 'all', page = 1, pages = 1, unread = 0, loaded = false;
    let controller, timer, debounce, sequence = 0, busy = false, writing = false, dirty = false, stopped = false, auth = false;
    let ready = window.SynapseRealtime?.status === 'ready';
    let unconfirmed = false;
    function controls() {
      markAll.disabled = writing || auth || !loaded || unread === 0;
      previous.disabled = busy || auth || !loaded || page <= 1;
      next.disabled = busy || auth || !loaded || page >= pages;
      filters.forEach(button => {
        button.disabled = writing;
        const active = button.dataset.historyFilter === filter;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', String(active));
      });
      root.querySelectorAll('[data-history-read]').forEach(button => { button.disabled = writing || auth; });
      find('refresh').disabled = writing;
      find('retry').disabled = writing;
      root.setAttribute('aria-busy', String(busy));
    }
    function error(message) {
      find('error-text').textContent = message;
      feedback.classList.remove('d-none');
    }
    function clearPrivate() {
      list.replaceChildren(); loaded = false; unread = 0;
      find('unread').textContent = 'Sign in again to check notifications.';
      find('page').textContent = 'Page unavailable';
      status.textContent = 'Your session or permissions changed. Refresh the page or sign in again.';
    }
    function validate(payload) {
      if (!payload || !Array.isArray(payload.items) || payload.items.length > 20 ||
          !Number.isSafeInteger(payload.unread_count) || payload.unread_count < 0 || payload.unread_count > 100 ||
          !Number.isSafeInteger(payload.total) || payload.total < 0 ||
          !Number.isSafeInteger(payload.page) || payload.page < 1 ||
          !Number.isSafeInteger(payload.pages) || payload.pages < 0 || payload.per_page !== 20 || payload.filter !== filter) {
        throw new Error('Invalid notification history response.');
      }
    }
    function render(payload) {
      page = payload.page; pages = Math.max(1, payload.pages); unread = payload.unread_count; loaded = true;
      const fragment = document.createDocumentFragment();
      for (const item of payload.items) {
        if (!Number.isSafeInteger(item?.id) || item.id <= 0 || typeof item.title !== 'string' || typeof item.message !== 'string') continue;
        const row = document.createElement('li');
        row.className = 'notification-history-item d-flex flex-wrap gap-3 align-items-start';
        const copy = document.createElement('a'); copy.className = 'notification-history-copy';
        const title = document.createElement('span'); title.className = 'notification-history-title fw-semibold';
        title.textContent = item.title;
        const target = destination(item.url);
        if (target) {
          copy.href = target; copy.dataset.historyDestination = 'true';
          if (new URL(target).origin !== location.origin) copy.dataset.navigation = 'false';
        }
        const message = document.createElement('p'); message.className = 'notification-history-message mb-1 mt-1';
        message.textContent = item.title === 'Team chat mention' ? 'You were mentioned in your team chat. Open the conversation to view the message.' : item.message;
        const time = document.createElement('time'); time.className = 'small text-muted';
        time.textContent = typeof item.created_at === 'string' ? item.created_at.slice(0, 16).replace('T', ' ') : '';
        if (typeof item.created_at === 'string') time.dateTime = item.created_at;
        time.title = 'Philippine time (Asia/Manila, UTC+08:00)';
        copy.append(title, message, time);
        const actions = document.createElement('div'); actions.className = 'notification-history-actions d-flex align-items-center gap-2';
        const state = document.createElement('span'); state.className = item.read_at ? 'small text-muted' : 'badge bg-primary';
        state.textContent = item.read_at ? 'Read' : 'Unread'; actions.append(state);
        if (!item.read_at) {
          const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline-primary btn-sm';
          button.dataset.historyRead = String(item.id); button.textContent = 'Mark read';
          button.setAttribute('aria-label', `Mark read: ${item.title}`); actions.append(button);
        }
        row.append(copy, actions); fragment.append(row);
      }
      if (!fragment.childNodes.length) {
        const empty = document.createElement('li'); empty.className = 'py-4 text-center text-muted';
        empty.textContent = filter === 'unread' ? 'You’re all caught up. No unread notifications.' : 'No current notifications.';
        fragment.append(empty);
      }
      list.replaceChildren(fragment);
      find('unread').textContent = `${unread >= 100 ? '99+' : unread} unread`;
      find('page').textContent = `Page ${page} of ${pages}`;
      const start = payload.total ? (page - 1) * 20 + 1 : 0;
      const end = Math.min(page * 20, payload.total);
      status.textContent = `${start}–${end} of ${payload.total} ${filter === 'unread' ? 'unread ' : ''}notifications`;
      feedback.classList.add('d-none');
    }
    async function request(url, write, owner) {
      const endpoint = new URL(url, location.href);
      if (endpoint.origin !== location.origin || !/^https?:$/.test(endpoint.protocol) || endpoint.username || endpoint.password) throw new Error('Invalid endpoint.');
      let timeout;
      try {
        // Bound response parsing as well as fetch; aborted/late responses cannot rotate CSRF.
        return await Promise.race([
          (async () => {
            const response = await fetch(endpoint.href, { method: write ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
              signal: owner.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(write ? window.SynapseCsrf?.headers() : {}) } });
            if (owner.signal.aborted) throw new DOMException('Cancelled', 'AbortError');
            window.SynapseCsrf?.fromResponse(response);
            if (response.status === 401 || response.status === 403 || response.redirected) {
              const failure = new Error('Your session or security token changed. Refresh the page or sign in again.');
              failure.authentication = true; throw failure;
            }
            const payload = await response.json();
            if (owner.signal.aborted) throw new DOMException('Cancelled', 'AbortError');
            window.SynapseCsrf?.sync(payload.csrf);
            if (!response.ok) throw new Error('Notifications are temporarily unavailable.');
            return payload;
          })(),
          new Promise((_, reject) => { timeout = setTimeout(() => { owner.abort(); reject(new Error('Notifications request timed out.')); }, 10000); }),
        ]);
      } finally { clearTimeout(timeout); }
    }
    function invalidate() {
      dirty = true;
      if (stopped || auth || document.hidden || busy || debounce) return;
      debounce = setTimeout(() => { debounce = null; void refresh(); }, 100);
    }
    async function refresh() {
      if (stopped || writing || document.hidden || busy) return;
      clearTimeout(debounce); debounce = null; dirty = false; busy = true;
      const current = ++sequence; controller = new AbortController(); const owner = controller;
      controls(); status.textContent = loaded ? 'Refreshing notifications…' : 'Loading notifications…';
      try {
        const url = new URL(root.dataset.historyUrl, location.href);
        url.searchParams.set('filter', filter); url.searchParams.set('page', String(page));
        const payload = await request(url.href, false, owner);
        if (current !== sequence || stopped) return;
        validate(payload);
        // Removal/read state can empty the final page. Reconcile once at the new last page.
        if (payload.page > Math.max(1, payload.pages)) {
          page = Math.max(1, payload.pages); dirty = true;
        } else { render(payload); auth = false; unconfirmed = false; }
      } catch (failure) {
        if (current !== sequence || stopped || failure.name === 'AbortError') return;
        if (failure.authentication) { auth = true; clearPrivate(); }
        else status.textContent = loaded ? 'Refresh failed. Showing the last loaded page.' : 'Notifications could not be loaded.';
        error(unconfirmed ? 'Could not confirm read state. Refresh to check; the write was not retried.' : failure.message + ' Please retry refresh.');
      } finally {
        if (current === sequence && !stopped) { busy = false; controls(); if (dirty) invalidate(); }
      }
    }
    async function read(id) {
      if (stopped || writing || auth || !loaded) return;
      controller?.abort(); ++sequence; busy = writing = true;
      clearTimeout(debounce); debounce = null;
      const current = sequence; controller = new AbortController(); const owner = controller;
      controls(); status.textContent = 'Updating read state…';
      try {
        const url = id ? root.dataset.readBase.replace(/\/$/, '') + '/' + id + '/read' : root.dataset.readAllUrl;
        await request(url, true, owner);
        if (current !== sequence || stopped) return;
        document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail: { event: 'notifications.changed' } }));
      } catch (failure) {
        if (current !== sequence || stopped) return;
        unconfirmed = true;
        if (failure.authentication) { auth = true; clearPrivate(); }
        error('Could not confirm read state. Refresh to check; the write was not retried.');
      } finally {
        if (current === sequence && !stopped) {
          busy = writing = false; controls();
          // POST returns the bell payload, not history. Never render it or replay a write.
          if (!auth) invalidate();
        }
      }
    }
    function select(nextFilter, nextPage) {
      if (writing) return;
      controller?.abort(); ++sequence; busy = false;
      filter = nextFilter; page = nextPage; loaded = false;
      list.replaceChildren(); find('page').textContent = `Page ${page}`;
      void refresh();
    }
    on(root, 'click', event => {
      const target = event.target.closest('button, a');
      if (!target || !root.contains(target)) return;
      if (target.matches('[data-history-destination]')) {
        const safe = destination(target.getAttribute('href'));
        if (!safe) { event.preventDefault(); return; }
        target.href = safe;
        if (new URL(safe).origin !== location.origin) target.dataset.navigation = 'false';
        else delete target.dataset.navigation;
        return; // Destination navigation alone never writes read state.
      }
      if (target.disabled) return;
      if (target.matches('[data-history-filter]')) select(target.dataset.historyFilter, 1);
      else if (target === previous) select(filter, page - 1);
      else if (target === next) select(filter, page + 1);
      else if (target.matches('[data-history-read]')) {
        const id = Number(target.dataset.historyRead);
        if (Number.isSafeInteger(id) && id > 0) void read(id);
      } else if (target === markAll) void read(null);
      else if (target.matches('[data-history-refresh], [data-history-retry]')) { auth = false; void refresh(); }
    });
    function schedule() {
      clearTimeout(timer);
      if (stopped || document.hidden) return;
      timer = setTimeout(() => { if (!auth) void refresh(); schedule(); }, ready ? 60000 : 30000);
    }
    on(document, 'synapse:realtime-event', event => { if (event.detail?.event === 'notifications.changed') invalidate(); });
    on(document, 'synapse:realtime-status', event => {
      const nextReady = event.detail?.status === 'ready';
      if (nextReady !== ready) { ready = nextReady; schedule(); if (ready) invalidate(); }
    });
    on(document, 'visibilitychange', () => {
      clearTimeout(timer); clearTimeout(debounce); debounce = null;
      if (document.hidden) {
        if (!writing) { controller?.abort(); ++sequence; busy = false; controls(); }
      } else { if (!auth) void refresh(); schedule(); }
    });
    const destroy = () => {
      stopped = true; ++sequence; controller?.abort(); events.abort();
      clearTimeout(timer); clearTimeout(debounce); root.removeAttribute('aria-busy'); instances.delete(root);
    };
    instances.set(root, destroy);
    void refresh(); schedule();
  }
  function init(scope = document) {
    for (const [root, destroy] of instances) if (!root.isConnected) destroy();
    scope.querySelectorAll('[data-notification-history]').forEach(root => { if (!instances.has(root)) mount(root); });
  }
  function destroy(scope) {
    for (const [root, cleanup] of instances) if (!scope || scope === root || scope.contains(root)) cleanup();
  }
  window.SynapseNotificationHistory = { init, destroy };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
  window.addEventListener('pagehide', () => destroy());
  window.addEventListener('pageshow', () => init());
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();
