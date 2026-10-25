/** Server-owned filtering and paging; only the current successful request may replace results. */
(function () {
  'use strict';
  const instances = new Map();

  function init(root) {
    if (instances.has(root)) return;
    const form = root.querySelector('[data-table-filters]');
    const results = root.querySelector('[data-table-results]');
    const status = root.querySelector('[data-table-status]');
    const errorBox = root.querySelector('[data-table-error]');
    const errorText = root.querySelector('[data-table-error-message]');
    if (!form || !results || !status || !errorBox || !errorText) return;
    const endpoint = new URL(root.dataset.endpoint, location.href);
    if (endpoint.origin !== location.origin) return;
    const initialPath = location.pathname;
    let sequence = 0, controller = null, timer = null, failedUrl = null, failedHistoryMode = 'replace', composing = false;
    const events = new AbortController();
    const options = { signal: events.signal };

    function invalidate() {
      clearTimeout(timer);
      timer = null;
      sequence++;
      controller?.abort();
      controller = null;
    }
    let finishLoading = null;
    function busy(value, message) {
      finishLoading?.();
      finishLoading = null;
      if (value) finishLoading = window.SynapseLoading?.begin(results, { label: message || 'Loading results…', skeleton: true });
      results.setAttribute('aria-busy', String(value));
      if (message !== undefined) status.textContent = message;
    }
    function queryUrl(page = 1) {
      const url = new URL(endpoint);
      url.search = '';
      for (const [name, value] of new FormData(form)) {
        if (['q', 'kind', 'status', 'per_page', 'tab'].includes(name) && typeof value === 'string') {
          url.searchParams.set(name, name === 'q' ? value.trim() : value);
        }
      }
      url.searchParams.set('page', String(page));
      return url;
    }
    function cleanUrl(url) {
      const clean = new URL(url);
      if (clean.origin !== endpoint.origin || ![endpoint.pathname, initialPath].includes(clean.pathname)) throw new Error('Invalid table destination.');
      // Initial links may use the equivalent route without index.php. Fetch the
      // canonical endpoint, while Back/Forward retains its original history URL.
      clean.pathname = endpoint.pathname;
      clean.searchParams.delete('fragment');
      clean.hash = '';
      return clean;
    }
    function metadata(container) {
      const meta = container.querySelector('[data-table-pagination]');
      if (!meta) throw new Error('The table response is incomplete. Reload this page.');
      const values = {};
      for (const name of ['page', 'perPage', 'filtered', 'total']) {
        const raw = meta.dataset[name];
        if (!raw || !/^\d+$/.test(raw) || !Number.isSafeInteger(Number(raw))) throw new Error('Invalid table pagination response.');
        values[name] = Number(raw);
      }
      if (values.page < 1 || values.page > 1000000 || ![25, 50, 100].includes(values.perPage)) throw new Error('Invalid table pagination response.');
      return values;
    }
    function announce(meta) {
      if (!meta.filtered) return 'No matching records.';
      const from = (meta.page - 1) * meta.perPage + 1;
      const to = Math.min(meta.page * meta.perPage, meta.filtered);
      return `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${meta.filtered.toLocaleString()} matching records.`;
    }
    function syncFilters(url) {
      for (const name of ['q', 'kind', 'status', 'per_page', 'tab']) {
        const field = form.elements.namedItem(name);
        if (!field) continue;
        const fallback = name === 'tab' ? (endpoint.searchParams.get('tab') ?? 'incoming') : (name === 'per_page' ? '25' : (name === 'q' ? '' : 'all'));
        field.value = url.searchParams.get(name) ?? fallback;
        if (field instanceof HTMLSelectElement) field.dispatchEvent(new Event('bd-select:sync'));
      }
    }
    function remember(url, mode) {
      if (mode === 'none') return;
      const method = mode === 'push' && url.href !== location.href ? 'pushState' : 'replaceState';
      if (window.SynapseNavigation) {
        window.SynapseNavigation.updateHistory(url.href, method === 'pushState' ? 'push' : 'replace');
      } else history[method](history.state, '', url.href);
    }
    async function load(destination, historyMode = 'replace') {
      invalidate();
      const token = sequence;
      const requestController = new AbortController();
      controller = requestController;
      const url = cleanUrl(destination);
      failedUrl = new URL(url);
      failedHistoryMode = historyMode;
      errorBox.hidden = true;
      busy(true, 'Loading results…');
      const requestUrl = new URL(url);
      requestUrl.searchParams.set('fragment', '1');
      try {
        const response = await fetch(requestUrl, {
          credentials: 'same-origin', cache: 'no-store', signal: requestController.signal,
          headers: { 'Accept': 'text/html, application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (token !== sequence || requestController.signal.aborted) return;
        if (window.SynapseCsrf?.fromResponse(response)) return;
        if (response.redirected) throw new Error('Your session or access changed. Reload this page to sign in or check access.');
        if (!response.ok) {
          let message = response.status === 403 ? 'Your session or access changed. Reload this page to check access.' : 'Unable to load results. Please retry.';
          if ((response.headers.get('Content-Type') || '').includes('application/json')) {
            const payload = await response.json();
            if (typeof payload.message === 'string') message = payload.message;
          }
          throw new Error(message);
        }
        if (!(response.headers.get('Content-Type') || '').includes('text/html')) throw new Error('Unexpected table response. Reload this page.');
        const html = await response.text();
        if (token !== sequence || requestController.signal.aborted) return;
        const document = new DOMParser().parseFromString(html, 'text/html');
        const content = document.body.firstElementChild;
        if (document.body.children.length !== 1 || !content?.matches('[data-table-content]') || content.querySelector('script, iframe, object, embed')) {
          throw new Error('The table response could not be loaded. Reload this page.');
        }
        const meta = metadata(content);
        url.searchParams.set('page', String(meta.page));
        url.searchParams.set('per_page', String(meta.perPage));
        results.replaceChildren(...content.childNodes);
        syncFilters(url);
        remember(url, historyMode);
        failedUrl = null;
        busy(false, announce(meta));
      } catch (error) {
        if (token !== sequence || requestController.signal.aborted || error.name === 'AbortError') return;
        errorText.textContent = error.message || 'Unable to load results. Please retry.';
        errorBox.hidden = false;
        busy(false, 'Results were not updated. The previous results are still displayed.');
      } finally {
        if (token === sequence) {
          controller = null;
          finishLoading?.();
          finishLoading = null;
          results.setAttribute('aria-busy', 'false');
        }
      }
    }
    function search(mode) {
      if (form.reportValidity()) load(queryUrl(), mode);
      else { invalidate(); busy(false, 'Please correct the filters before searching.'); }
    }
    form.addEventListener('submit', event => {
      event.preventDefault();
      search('push');
    }, options);
    form.addEventListener('input', event => {
      if (event.target.name !== 'q') return;
      // Invalidate immediately, not after the debounce, so an old in-flight
      // response cannot overwrite the table while a newer term is being typed.
      invalidate();
      busy(true, 'Waiting for search…');
      if (!composing && !event.isComposing) timer = setTimeout(() => search('replace'), 300);
    }, options);
    form.addEventListener('compositionstart', () => { composing = true; invalidate(); }, options);
    form.addEventListener('compositionend', () => {
      composing = false;
      invalidate();
      timer = setTimeout(() => search('replace'), 300);
    }, options);
    form.addEventListener('change', event => {
      if (event.target.matches('select')) search('push');
    }, options);
    root.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const page = event.target.closest('a[data-table-page]');
      const reset = event.target.closest('a[data-table-reset]');
      if (page && results.contains(page)) {
        event.preventDefault();
        if (!form.reportValidity()) { invalidate(); busy(false, 'Please correct the filters before searching.'); return; }
        const destination = cleanUrl(page.href);
        const current = queryUrl();
        const defaults = { q: '', kind: 'all', status: 'all', per_page: '25', tab: 'incoming' };
        const changed = Object.entries(defaults).some(([name, fallback]) =>
          (destination.searchParams.get(name) ?? fallback) !== (current.searchParams.get(name) ?? fallback));
        // Pagination belongs to the rendered filters. A newly typed term must
        // start at page one, not disappear when an older page link is clicked.
        load(changed ? current : destination, 'push');
      } else if (reset && root.contains(reset)) {
        event.preventDefault();
        syncFilters(endpoint);
        load(queryUrl(), 'push');
      } else if (event.target.closest('[data-table-retry]') && failedUrl) {
        event.preventDefault();
        load(failedUrl, failedHistoryMode);
      }
    }, options);
    window.addEventListener('popstate', () => {
      if (![endpoint.pathname, initialPath].includes(location.pathname) || !root.isConnected) return;
      const url = cleanUrl(location.href);
      syncFilters(url);
      load(url, 'none');
    }, options);
    document.addEventListener('synapse:before-navigate', () => {
      invalidate();
      busy(false);
    }, options);
    window.addEventListener('pagehide', () => {
      invalidate();
      busy(false);
    }, options);
    try { busy(false, announce(metadata(results))); }
    catch (_) { /* The GET fallback remains usable if server markup is incomplete. */ }
    instances.set(root, { destroy() { invalidate(); busy(false); events.abort(); instances.delete(root); } });
  }

  function start(event) {
    for (const [root, state] of instances) if (!root.isConnected) state.destroy();
    const scope = event?.detail?.root || event?.detail?.elt || document;
    if (scope.matches?.('[data-live-table]')) init(scope);
    scope.querySelectorAll('[data-live-table]').forEach(init);
  }
  document.addEventListener('synapse:before-swap', event => {
    const scope = event.detail?.root;
    if (!scope) return;
    for (const [root, state] of instances) {
      if (root === scope || scope.contains(root) || !root.isConnected) state.destroy();
    }
  });
  document.addEventListener('synapse:page-load', start);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
  document.addEventListener('htmx:load', start);
})();
