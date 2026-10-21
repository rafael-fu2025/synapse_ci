/** Live availability reads. Keep the filter controls mounted and never silently discard drafts. */
(function () {
  'use strict';
  if (window.SynapseAvailabilityFilters) return;
  const instances = new Map();

  function init(root) {
    if (instances.has(root)) return;
    const form = root.querySelector('[data-availability-filters]');
    const results = root.querySelector('[data-availability-results]');
    const status = root.querySelector('[data-availability-filter-status]');
    const errorBox = root.querySelector('[data-availability-filter-error]');
    const errorText = root.querySelector('[data-availability-filter-error-text]');
    const apply = form?.querySelector('[data-availability-apply]');
    if (!form || !results || !status || !errorBox || !errorText || !apply) return;
    const endpoint = new URL(root.dataset.workspaceUrl, location.href);
    if (endpoint.origin !== location.origin) return;
    const events = new AbortController();
    const dirty = new Set();
    let sequence = 0, edits = 0, timer = null, pending = null, finish = null, composing = false, asking = false;
    const options = { signal: events.signal };
    apply.hidden = true;
    status.textContent = 'Search updates as you type. Provider changes apply immediately.';

    function invalidate() {
      clearTimeout(timer); timer = null; ++sequence;
      pending?.abort(); pending = null;
      finish?.(); finish = null;
    }
    function hasDrafts() { return Array.from(dirty).some(item => item.isConnected && root.contains(item)); }
    function hasOpenDialog() { return Boolean(results.querySelector('.modal.show')); }
    function paused(message) {
      apply.hidden = false;
      status.textContent = message || 'Unsaved edits or staff selections are kept. Use Apply filters to review before replacing them.';
    }
    function queryUrl() {
      const url = new URL(endpoint);
      for (const name of ['q', 'provider', 'day', 'section']) {
        const value = form.elements.namedItem(name)?.value || '';
        url.searchParams.set(name, name === 'q' ? value.trim() : value);
      }
      return url;
    }
    function safeContent(container) {
      if (container.querySelector('script, iframe, object, embed, base')) return false;
      return Array.from(container.querySelectorAll('template')).every(template => safeContent(template.content));
    }
    function announce(container) {
      const table = container.querySelector('[data-department-staff], [data-configured-windows]');
      const count = table ? Array.from(table.tBodies[0]?.rows || []).filter(row => !row.querySelector('td[colspan]')).length
        : container.querySelectorAll('.av-exception-row').length;
      const label = table?.matches('[data-department-staff]') ? 'staff members' : table ? 'weekly windows' : 'exceptions';
      return `Showing ${count.toLocaleString()} matching ${label}.`;
    }
    async function load(manual = false, historyMode = 'replace') {
      invalidate();
      if (!form.reportValidity()) { paused('Correct the filters before searching.'); return; }
      if (root.querySelector('[data-availability-save][aria-busy="true"]')) { paused('Wait for the current save before filtering.'); return; }
      if (hasOpenDialog()) { paused('Finish or close the editor before filtering. Your values have been kept.'); return; }
      if (hasDrafts()) {
        if (!manual) { paused(); return; }
        if (asking) return;
        asking = true;
        const version = edits;
        let approved = false;
        try {
          const message = 'Filtering will replace this section and discard unsaved availability edits and selected staff. Continue?';
          approved = window.SynapseConfirm ? await window.SynapseConfirm.confirm({ title: 'Unsaved availability changes', message, confirmLabel: 'Discard and filter', tone: 'warning' }) : window.confirm(message);
        } finally { asking = false; }
        if (!approved || !root.isConnected || version !== edits) { paused('Your edits and selections were kept. Filters were not applied.'); return; }
      }
      const version = edits;
      const token = sequence;
      const url = queryUrl();
      const controller = new AbortController(); pending = controller;
      let timedOut = false;
      const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 20000);
      errorBox.hidden = true; apply.hidden = true;
      status.textContent = 'Updating availability…';
      finish = window.SynapseLoading?.begin(results, { label: 'Updating availability…', skeleton: true }) || null;
      try {
        const response = await fetch(url.href, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
          headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() } });
        window.SynapseCsrf?.fromResponse(response);
        if (!response.ok) throw new Error([401, 403, 419].includes(response.status) ? 'Your session or access changed. Reload this page before continuing.' : 'Unable to update availability. Retry or reload this page.');
        if (!response.headers.get('Content-Type')?.includes('text/html')) throw new Error('Unexpected response. Reload this workspace.');
        const html = await response.text();
        if (token !== sequence || controller.signal.aborted || !root.isConnected) return;
        const page = new DOMParser().parseFromString(html, 'text/html');
        const incoming = page.querySelector('[data-availability-workspace]');
        const content = incoming?.querySelector('[data-availability-results]');
        const incomingFilters = incoming?.querySelector('[data-availability-filters]');
        if (!incoming || !content || !incomingFilters || !safeContent(content) || page.querySelector('base')
            || new URL(incoming.dataset.workspaceUrl, url).href !== endpoint.href
            || page.body.dataset.navigationScope !== document.body.dataset.navigationScope) {
          throw new Error('The workspace or your access changed. Reload this page before continuing.');
        }
        if (version !== edits || hasOpenDialog()) { paused('New edits were kept. Filters were not applied.'); return; }
        const canonical = new URL(endpoint);
        for (const name of ['q', 'provider', 'day', 'section']) canonical.searchParams.set(name, incomingFilters.elements.namedItem(name)?.value || '');
        if (window.SynapseNavigation) {
          if (!window.SynapseNavigation.updateHistory(canonical.href, historyMode)) throw new Error('Another navigation interrupted filtering. Please retry.');
        } else history[historyMode === 'push' ? 'pushState' : 'replaceState'](history.state, '', canonical.href);
        finish?.(); finish = null;
        window.SynapsePageLifecycle?.destroy(results);
        results.querySelectorAll('.modal').forEach(modal => window.bootstrap?.Modal.getInstance(modal)?.dispose());
        const replace = () => {
          window.Alpine?.destroyTree(results);
          results.replaceChildren(...Array.from(content.childNodes, node => document.importNode(node, true)));
          window.Alpine?.initTree(results);
        };
        if (window.Alpine?.mutateDom) window.Alpine.mutateDom(replace); else replace();
        dirty.clear();
        const select = form.elements.namedItem('provider');
        const freshSelect = incomingFilters.elements.namedItem('provider');
        select.replaceChildren(...Array.from(freshSelect.options, option => document.importNode(option, true)));
        select.value = freshSelect.value;
        select.dispatchEvent(new Event('bd-select:sync'));
        for (const name of ['day', 'section']) form.elements.namedItem(name).value = incomingFilters.elements.namedItem(name).value;
        const name = page.querySelector('meta[name="csrf-name"]')?.content;
        const hash = page.querySelector('meta[name="csrf-token"]')?.content;
        if (name && hash) window.SynapseCsrf?.sync({ name, hash, header: page.querySelector('meta[name="csrf-header"]')?.content });
        window.SynapseCsrf?.fromResponse(response);
        window.SynapsePageLifecycle?.init(results);
        window.htmx?.process(results);
        status.textContent = announce(results);
      } catch (error) {
        if (token !== sequence || (!timedOut && controller.signal.aborted)) return;
        errorText.textContent = timedOut ? 'Filtering timed out. Your previous results are still displayed.' : error instanceof TypeError ? 'Connection or sign-in changed. Retry or reload this page.' : error.message;
        errorBox.hidden = false; apply.hidden = false;
        status.textContent = 'Filters were not applied. Previous results and edits are still displayed.';
      } finally {
        clearTimeout(timeout);
        if (token === sequence) { finish?.(); finish = null; pending = null; }
      }
    }
    function schedule() {
      invalidate();
      if (composing) return;
      if (hasDrafts() || hasOpenDialog()) { paused(); return; }
      timer = setTimeout(() => { void load(false); }, 300);
    }
    form.addEventListener('input', event => { if (event.target.name === 'q') schedule(); }, options);
    form.addEventListener('change', event => { if (event.target.name === 'provider') void load(false, 'push'); }, options);
    form.addEventListener('compositionstart', () => { composing = true; invalidate(); }, options);
    form.addEventListener('compositionend', () => { composing = false; schedule(); }, options);
    form.addEventListener('submit', event => { event.preventDefault(); void load(true, 'push'); }, options);
    root.querySelector('[data-availability-reset]').addEventListener('click', event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); form.elements.namedItem('q').value = '';
      form.elements.namedItem('provider').value = '';
      form.elements.namedItem('provider').dispatchEvent(new Event('bd-select:sync'));
      void load(true, 'push');
    }, options);
    root.querySelector('[data-availability-filter-retry]').addEventListener('click', () => { void load(true); }, options);
    for (const type of ['input', 'change']) root.addEventListener(type, event => {
      const owner = event.target.form;
      if (owner?.matches('[data-availability-save]')) {
        dirty.add(owner); ++edits;
        if (pending || timer) { invalidate(); paused('Your new edits were kept. Automatic filtering is paused.'); }
      }
    }, options);
    root.addEventListener('submit', event => {
      if (event.target.matches('[data-availability-save]')) invalidate();
    }, { ...options, capture: true });
    root.addEventListener('show.bs.modal', () => { if (pending || timer) { invalidate(); paused('Finish the editor before filtering.'); } }, options);
    root.addEventListener('click', event => {
      const link = event.target.closest?.('.av-section-nav a');
      if (!link) return;
      const url = new URL(link.href, location.href);
      for (const name of ['q', 'provider', 'day']) url.searchParams.set(name, form.elements.namedItem(name).value.trim());
      link.href = url.href;
    }, options);
    instances.set(root, { invalidate, destroy() { invalidate(); events.abort(); instances.delete(root); } });
  }
  function scan(root = document) {
    if (root.matches?.('[data-availability-workspace]')) init(root);
    root.querySelectorAll('[data-availability-workspace]').forEach(init);
  }
  document.addEventListener('synapse:before-navigate', () => instances.forEach(instance => instance.invalidate()));
  document.addEventListener('synapse:before-swap', event => {
    for (const [root, instance] of instances) if (event.detail?.root?.contains(root)) instance.destroy();
  });
  window.addEventListener('pagehide', () => instances.forEach(instance => instance.destroy()));
  window.addEventListener('pageshow', () => scan());
  document.addEventListener('synapse:page-load', event => scan(event.detail?.root || document));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan()); else scan();
  window.SynapseAvailabilityFilters = { init: scan };
})();
