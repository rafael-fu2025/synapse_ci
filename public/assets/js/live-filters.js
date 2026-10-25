/** Full-page GET filtering with persistent controls and guarded, keyed result replacement. */
(function () {
  'use strict';
  if (window.SynapseLiveFilters) return;
  const instances = new Map();
  const selector = '[data-live-filters]';

  function safeContent(container) {
    if (container.matches?.('script, iframe, object, embed, base') || container.querySelector('script, iframe, object, embed, base')) return false;
    if (container.matches?.('template')) return safeContent(container.content);
    return Array.from(container.querySelectorAll('template')).every(item => safeContent(item.content));
  }
  function hasBase(container) {
    return Boolean(container.querySelector('base')) || Array.from(container.querySelectorAll('template')).some(item => hasBase(item.content));
  }
  function regions(root, form) {
    if (!form) return null;
    const found = new Map();
    for (const region of root.querySelectorAll('[data-filter-region]')) {
      const name = region.dataset.filterRegion;
      if (!name || found.has(name) || region.contains(form) || region.parentElement.closest('[data-filter-region]')) return null;
      found.set(name, region);
    }
    return found.size ? found : null;
  }
  function init(root) {
    if (instances.has(root)) return;
    const form = root.querySelector('[data-filter-form]');
    const status = root.querySelector('[data-filter-status]');
    const errorBox = root.querySelector('[data-filter-error]');
    const errorText = root.querySelector('[data-filter-error-text]');
    const currentRegions = regions(root, form);
    const key = root.dataset.filterKey;
    const scope = document.body.dataset.navigationScope;
    let endpoint;
    try { endpoint = new URL(root.dataset.filterUrl, location.href); } catch (_) { return; }
    if (!form || form.method.toLowerCase() !== 'get' || !status || !errorBox || !errorText || !currentRegions || !key || !scope
        || !root.dataset.filterUrl || endpoint.origin !== location.origin) return;
    endpoint.hash = '';
    const events = new AbortController();
    const options = { signal: events.signal };
    const dirty = new Set();
    let sequence = 0, edits = 0, timer = null, pending = null, composing = false, asking = false, syncing = false, retry = null, live = null;

    function queryUrl(source = form) {
      const url = new URL(endpoint);
      const data = new FormData(source);
      for (const name of new Set(data.keys())) {
        url.searchParams.delete(name);
        for (const value of data.getAll(name)) if (typeof value === 'string') url.searchParams.append(name, value.trim());
      }
      return url;
    }
    function filterUrl() { const url = queryUrl(); url.searchParams.delete('page'); return url; }
    let applied = filterUrl();
    let observed = applied.href;
    function invalidate() {
      clearTimeout(timer); timer = null; ++sequence;
      pending?.abort(); pending = null;
      root.removeAttribute('aria-busy');
    }
    function paused(message = 'Unsaved edits are kept. Submit the filters to review before replacing them.') { status.textContent = message; }
    function hasDrafts() { return Array.from(dirty).some(item => item.isConnected && root.contains(item)); }
    function editorOpen() {
      if (document.querySelector('#appFormModal.show') || root.querySelector('.modal.show, [role="dialog"][aria-modal="true"]')) return true;
      const active = document.activeElement;
      return Array.from(currentRegions.values()).some(region => region.contains(active))
        && Boolean(active?.isContentEditable || (active?.form && active.form.method.toLowerCase() !== 'get' && active.matches('input, textarea, select')));
    }
    function validUrl(url) { return url.origin === endpoint.origin && url.pathname === endpoint.pathname && !url.hash; }
    function linkUrl(link) {
      const url = filterUrl();
      const textNames = new Set(Array.from(form.elements)
        .filter(item => item.name && item.matches('textarea, input[type="search"], input[type="text"], input:not([type])'))
        .map(item => item.name));
      const newerText = Array.from(textNames).some(name => JSON.stringify(url.searchParams.getAll(name)) !== JSON.stringify(applied.searchParams.getAll(name)));
      for (const name of new Set(link.searchParams.keys())) {
        if (textNames.has(name)) continue;
        url.searchParams.delete(name);
        for (const value of link.searchParams.getAll(name)) url.searchParams.append(name, value);
      }
      if (newerText) url.searchParams.delete('page');
      return url;
    }
    function syncControls(source, all, restoreText = false) {
      syncing = true;
      try {
        const names = new Set(Array.from(source.elements, item => item.name).filter(Boolean));
        for (const name of names) {
          const fresh = Array.from(source.elements).filter(item => item.name === name);
          const controls = Array.from(form.elements).filter(item => item.name === name);
          controls.forEach((control, index) => {
            const incoming = fresh[index];
            if (!incoming || (!all && control.type !== 'hidden')) return;
            if (all && !restoreText && control.matches('textarea, input[type="search"], input[type="text"], input:not([type])')) return;
            if (control.type === 'file' || control.matches('button, [type="submit"]')) return;
            if (control.type === 'checkbox' || control.type === 'radio') control.checked = incoming.checked;
            else if (control.tagName === 'SELECT') {
              control.replaceChildren(...Array.from(incoming.childNodes, node => document.importNode(node, true)));
              Array.from(control.options).forEach((option, i) => { option.selected = incoming.options[i].selected; });
            } else control.value = incoming.value;
            control.dispatchEvent(new Event('bd-select:sync'));
            control.dispatchEvent(new Event('dp:sync'));
          });
        }
        const resets = Array.from(form.querySelectorAll('a[data-filter-reset]'));
        const freshResets = Array.from(source.querySelectorAll('a[data-filter-reset]'));
        resets.forEach((anchor, index) => {
          if (!freshResets[index]) return;
          const destination = new URL(freshResets[index].getAttribute('href'), endpoint);
          if (validUrl(destination)) anchor.href = destination.href;
        });
      } finally { syncing = false; observed = filterUrl().href; }
    }
    // `silent` is a background refresh: it never interrupts or discards user work and reports no errors except lost access.
    async function load(url = filterUrl(), manual = false, mode = 'replace', link = false, restoreText = false, silent = false) {
      if (root.querySelector('[data-team-board][aria-busy="true"], [data-task-dragging]')) return;
      if (silent && (pending || timer !== null || composing || asking || syncing || editorOpen() || hasDrafts()
          || root.querySelector('form[aria-busy="true"]'))) return;
      invalidate();
      const token = sequence;
      if (!validUrl(url) || !form.reportValidity()) return;
      if (!silent) retry = { url, mode, link, restoreText };
      if (editorOpen()) { paused('Finish or close the editor before filtering. Your values have been kept.'); return; }
      if (root.querySelector('form[aria-busy="true"]')) { paused('Wait for the current save before filtering.'); return; }
      if (hasDrafts()) {
        if (!manual || asking) { paused(); return; }
        if (!window.SynapseConfirm?.confirm) { paused('Shared confirmation is unavailable. Your edits were kept.'); return; }
        asking = true;
        const version = edits;
        let approved = false;
        try {
          approved = await window.SynapseConfirm.confirm({ title: 'Unsaved changes', message: 'Filtering will discard unsaved edits in these results. Continue?', confirmLabel: 'Discard and filter', tone: 'warning' });
        } finally { asking = false; }
        if (!approved || token !== sequence || version !== edits || !root.isConnected || editorOpen()) { paused(); return; }
      }
      const version = edits;
      const controller = new AbortController(); pending = controller;
      let timedOut = false;
      const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 20000);
      let denied = false;
      if (!silent) {
        errorBox.hidden = true;
        root.setAttribute('aria-busy', 'true');
        status.textContent = 'Updating results…';
      }
      try {
        const response = await fetch(url.href, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
          headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() } });
        if (token !== sequence || controller.signal.aborted || !root.isConnected) return;
        window.SynapseCsrf?.fromResponse(response);
        denied = [401, 403, 419].includes(response.status);
        if (!response.ok) throw new Error(denied ? 'Your session or access changed. Retry or reload this page.' : 'Unable to update results. Please retry.');
        if (response.redirected || (response.url && new URL(response.url).href !== url.href)
            || !response.headers.get('Content-Type')?.toLowerCase().includes('text/html')) throw new Error('Unexpected response. Retry or reload this page.');
        const html = await response.text();
        if (token !== sequence || controller.signal.aborted || !root.isConnected) return;
        const page = new DOMParser().parseFromString(html, 'text/html');
        const matches = Array.from(page.querySelectorAll(selector)).filter(item => item.dataset.filterKey === key);
        const incoming = matches.length === 1 ? matches[0] : null;
        const incomingForm = incoming?.querySelector('[data-filter-form]');
        const incomingRegions = incoming && regions(incoming, incomingForm);
        if (!incomingForm || incomingForm.method.toLowerCase() !== 'get' || !incomingRegions || hasBase(page)
            || page.body.dataset.navigationScope !== scope || new URL(incoming.dataset.filterUrl, url).href !== endpoint.href
            || incomingRegions.size !== currentRegions.size
            || Array.from(currentRegions.keys()).some(name => !incomingRegions.has(name))
            || Array.from(incomingRegions.values()).some(region => !safeContent(region))) {
          throw new Error('The page or your access changed. Reload this page before continuing.');
        }
        if (version !== edits || editorOpen() || root.querySelector('[data-team-board][aria-busy="true"], [data-task-dragging]')) { if (!silent) paused('New edits were kept. Filters were not applied.'); return; }
        const contextLinks = Array.from(document.querySelectorAll('a[data-filter-context-link]'))
          .filter(anchor => anchor.dataset.filterContextLink === key).map(anchor => {
            const fresh = Array.from(page.querySelectorAll('a[data-filter-context-link]')).filter(item => item.dataset.filterContextLink === key);
            if (fresh.length !== 1) throw new Error('The page actions changed. Reload this page before continuing.');
            const destination = new URL(fresh[0].getAttribute('href'), url);
            if (destination.origin !== endpoint.origin || destination.username || destination.password) throw new Error('Unexpected page action. Reload this page.');
            return [anchor, destination.href];
          });
        const canonical = queryUrl(incomingForm);
        if (url.searchParams.has('page') && !incomingForm.elements.namedItem('page')) canonical.searchParams.set('page', url.searchParams.get('page'));
        if (window.SynapseNavigation) {
          if (!window.SynapseNavigation.updateHistory(canonical.href, mode)) throw new Error('Another navigation interrupted filtering. Please retry.');
        } else history[mode === 'push' ? 'pushState' : 'replaceState'](history.state, '', canonical.href);
        for (const [name, region] of currentRegions) {
          window.SynapsePageLifecycle?.destroy(region);
          region.querySelectorAll('.modal').forEach(modal => window.bootstrap?.Modal.getInstance(modal)?.dispose());
          const replace = () => {
            window.Alpine?.destroyTree(region);
            region.replaceChildren(...Array.from(incomingRegions.get(name).childNodes, node => document.importNode(node, true)));
            window.Alpine?.initTree(region);
          };
          if (window.Alpine?.mutateDom) window.Alpine.mutateDom(replace); else replace();
        }
        dirty.clear();
        for (const [anchor, href] of contextLinks) anchor.href = href;
        // Ordinary reads preserve typed text; Reset and history restore it only on success.
        syncControls(incomingForm, link, restoreText);
        applied = queryUrl(incomingForm);
        applied.searchParams.delete('page');
        const name = page.querySelector('meta[name="csrf-name"]')?.content;
        const hash = page.querySelector('meta[name="csrf-token"]')?.content;
        if (name && hash) window.SynapseCsrf?.sync({ name, hash, header: page.querySelector('meta[name="csrf-header"]')?.content });
        window.SynapseCsrf?.fromResponse(response);
        for (const region of currentRegions.values()) { window.SynapsePageLifecycle?.init(region); window.htmx?.process(region); }
        status.textContent = incoming.querySelector('[data-filter-status]')?.textContent.trim() || 'Results updated.';
        document.dispatchEvent(new CustomEvent('synapse:filter-updated', { detail: { root } }));
        return true;
      } catch (error) {
        if (token !== sequence || (!timedOut && controller.signal.aborted)) return;
        if (silent && !denied) return; // Keep the last good results; the next refresh retries.
        errorText.textContent = timedOut ? 'Filtering timed out. Your previous results are still displayed.' : error instanceof TypeError ? 'Connection or sign-in changed. Retry or reload this page.' : error.message;
        errorBox.hidden = false;
        status.textContent = 'Filters were not applied. Previous results and edits are still displayed.';
      } finally {
        clearTimeout(timeout);
        if (token === sequence) { pending = null; root.removeAttribute('aria-busy'); }
      }
    }
    function changed(event) {
      if (syncing || !event.target.name || event.target.form !== form) return;
      const url = filterUrl();
      if (url.href === observed) return; // Widgets emit input/change pairs, including on initialization.
      observed = url.href;
      invalidate();
      if (composing || event.isComposing) return;
      if (hasDrafts() || editorOpen()) { paused(); return; }
      const text = event.target.matches('textarea, input[type="search"], input[type="text"], input:not([type])');
      if (text) timer = setTimeout(() => { void load(); }, 300);
      else void load(url, false, 'push');
    }
    form.addEventListener('input', changed, options);
    form.addEventListener('change', changed, options);
    form.addEventListener('compositionstart', () => { composing = true; invalidate(); }, options);
    form.addEventListener('compositionend', () => {
      composing = false; observed = filterUrl().href; invalidate(); timer = setTimeout(() => { void load(); }, 300);
    }, options);
    form.addEventListener('submit', event => { event.preventDefault(); observed = filterUrl().href; void load(filterUrl(), true, 'push'); }, options);
    root.addEventListener('click', event => {
      const target = event.target.closest?.('[data-filter-retry], [data-filter-reset], [data-filter-link]');
      if (!target || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
          || target.hasAttribute('download') || (target.target && target.target !== '_self')) return;
      if (target.matches('[data-filter-retry]')) {
        event.preventDefault(); if (retry) void load(retry.url, false, retry.mode, retry.link, retry.restoreText); return;
      }
      let url;
      try { url = new URL(target.href, location.href); } catch (_) { return; }
      if (!validUrl(url)) return;
      event.preventDefault();
      if (target.matches('[data-filter-reset]')) void load(url, false, 'push', true, true);
      else void load(linkUrl(url), false, 'push', true);
    }, options);
    for (const type of ['input', 'change']) root.addEventListener(type, event => {
      const owner = event.target.form;
      if ((owner && owner !== form && owner.method.toLowerCase() !== 'get') || event.target.isContentEditable) {
        dirty.add(owner || event.target); ++edits; invalidate(); paused();
      }
    }, options);
    root.addEventListener('submit', event => {
      if (event.target !== form && event.target.method?.toLowerCase() !== 'get') { dirty.add(event.target); ++edits; invalidate(); }
    }, { ...options, capture: true });
    document.addEventListener('show.bs.modal', event => {
      if (root.contains(event.target) || event.target.id === 'appFormModal') { invalidate(); paused('Finish or close the editor before filtering.'); }
    }, options);
    if (!window.SynapseNavigation) window.addEventListener('popstate', () => {
      const url = new URL(location.href);
      if (validUrl(url)) void load(url, false, 'replace', true, true);
    }, options);
    // Opt-in live refresh: data-live-events="event.name other.event" refetches this region on those realtime hints.
    // Hints are best-effort, so a visible-tab poll reconciles missed ones (slower while the socket is connected).
    const liveEvents = new Set((root.dataset.liveEvents || '').split(/\s+/).filter(name => /^[a-z][a-z0-9_.]{0,63}$/.test(name)));
    if (liveEvents.size) {
      let ready = window.SynapseRealtime?.status === 'ready';
      const active = () => root.isConnected && !document.hidden;
      const arm = () => {
        clearTimeout(live); live = null;
        if (active()) live = setTimeout(refresh, (ready ? 120000 : 30000) * (0.9 + Math.random() * 0.2));
      };
      function refresh() {
        clearTimeout(live); live = null;
        if (!active()) return;
        const url = new URL(location.href); url.hash = '';
        void load(validUrl(url) ? url : filterUrl(), false, 'replace', false, false, true).finally(arm);
      }
      document.addEventListener('synapse:realtime-event', event => {
        if (!liveEvents.has(event.detail?.event)) return;
        clearTimeout(live); live = setTimeout(refresh, 250);
      }, options);
      document.addEventListener('synapse:realtime-status', event => {
        const next = event.detail?.status === 'ready';
        if (next === ready) return;
        ready = next;
        if (ready) refresh(); else arm();
      }, options);
      document.addEventListener('visibilitychange', () => { if (document.hidden) { clearTimeout(live); live = null; } else refresh(); }, options);
      arm();
    }
    instances.set(root, { invalidate,
      async refresh() {
        if (pending || timer !== null || composing || asking || syncing || editorOpen() || hasDrafts()) return false;
        const url = new URL(location.href); url.hash = '';
        return await load(validUrl(url) ? url : filterUrl()) === true;
      },
      destroy() { invalidate(); clearTimeout(live); events.abort(); instances.delete(root); } });
  }
  function scan(root = document) {
    for (const [element, instance] of instances) if (!element.isConnected) instance.destroy();
    if (root.matches?.(selector)) init(root);
    root.querySelectorAll(selector).forEach(init);
  }
  document.addEventListener('synapse:before-navigate', () => instances.forEach(instance => instance.invalidate()));
  document.addEventListener('synapse:before-swap', event => {
    for (const [root, instance] of instances) if (event.detail?.root === root || event.detail?.root?.contains(root)) instance.destroy();
  });
  document.addEventListener('synapse:page-load', event => scan(event.detail?.root || document));
  window.addEventListener('pagehide', () => instances.forEach(instance => instance.destroy()));
  window.addEventListener('pageshow', () => scan());
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan()); else scan();
  window.SynapseLiveFilters = { init: scan,
    // null means no opted-in region owns this destination; false means its GET failed (do not navigate).
    async refresh(value) {
      const url = new URL(value, location.href);
      const current = new URL(location.href);
      if (url.origin !== current.origin || url.pathname !== current.pathname) return null;
      const owners = [...instances].filter(([root]) => root.isConnected && root.hasAttribute('data-mutation-refresh'));
      if (owners.length !== 1) return null;
      return owners[0][1].refresh();
    }
  };
})();
