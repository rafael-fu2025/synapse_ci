/** Queue board hints only: all patient data continues to come from authorized HTMX GETs. */
(function () {
  'use strict';
  if (window.SynapseQueueRealtime) return;
  window.SynapseQueueRealtime = true;
  document.addEventListener('synapse:page-load', init);
  init();

  function init() {
    const board = document.getElementById('queue-board-container');
    if (!board || board.dataset.queueRealtime) return;
    const owner = `queue.board.${board.dataset.queueUnit || ''}`;
    const regional = window.SynapseRefreshRegions;
    const regionalEnabled = Boolean(regional?.enabled?.(owner));
    board.dataset.queueRealtime = 'true';
    const events = new AbortController();
    const options = { signal: events.signal };
    const requests = new Set();
    let ready = window.SynapseRealtime?.status === 'ready';
    let paused = false;
    let pending = false;
    let poll = null;
    let debounce = null;

    function syncCallNext() {
      const button = document.getElementById('queue-call-next');
      const count = board.querySelector('[data-queue-waiting-count]')?.dataset.queueWaitingCount;
      if (button) button.disabled = !/^\d+$/.test(count || '') || Number(count) === 0;
    }
    const historyDrawer = document.getElementById('queueNoShowHistory');
    let historyMarkup = null;
    function syncHistory() {
      if (!historyDrawer) return;
      const template = board.querySelector('template[data-queue-no-show-history]');
      const list = historyDrawer.querySelector('[data-queue-no-show-list]');
      if (!template || !list) return;
      document.querySelectorAll('[data-queue-no-show-count]').forEach(el => { el.textContent = template.dataset.count; });
      historyDrawer.querySelector('[data-queue-no-show-date]').textContent = template.dataset.date;
      if (historyMarkup === template.innerHTML) return;
      historyMarkup = template.innerHTML;
      const scroll = list.scrollTop;
      list.replaceChildren(template.content.cloneNode(true));
      list.scrollTop = scroll;
    }
    syncCallNext();
    syncHistory();
    document.addEventListener('htmx:afterSwap', event => {
      if (event.detail?.target !== board) return;
      syncCallNext();
      syncHistory();
    }, options);
    document.addEventListener('synapse:region-load', event => {
      if (event.detail?.owner !== owner || !event.detail?.regions?.includes('board')) return;
      syncCallNext();
      syncHistory();
    }, options);
    historyDrawer?.addEventListener('show.bs.offcanvas', event => {
      if (document.body.classList.contains('is-navigating')) event.preventDefault();
    }, options);
    document.addEventListener('synapse:before-navigate', event => {
      // Close the drawer first so navigation cannot leave a backdrop or scroll lock behind.
      if (historyDrawer?.matches('.show, .showing, .hiding')) event.preventDefault();
    }, options);

    let unregisterRegional = null;
    if (regionalEnabled) {
      unregisterRegional = regional.register({
        owner,
        root: board,
        owns(request) {
          return request.regions.length === 1 && request.regions[0] === 'board';
        },
        canRefresh() {
          return active() && ![...requests].some(request => request !== owner);
        },
        refresh(request) {
          return regionalBoardGet().then(refreshed => refreshed
            ? { status: 'refreshed', owner, regions: ['board'], roots: [board] }
            : { status: 'failed', owner, regions: ['board'], reason: 'board-get' });
        }
      });
    }
    function attached() {
      return document.getElementById('queue-board-container') === board;
    }
    function active() {
      return !paused && document.visibilityState === 'visible' && attached();
    }
    function cancelTimers() {
      clearTimeout(poll);
      clearTimeout(debounce);
      poll = debounce = null;
    }
    function triggerBoardGet() {
      if (!window.htmx || !active()) return Promise.resolve(false);
      return new Promise(resolve => {
        let settled = false;
        const finish = value => {
          if (settled) return;
          settled = true;
          clearTimeout(timeout);
          document.removeEventListener('htmx:afterRequest', complete);
          document.removeEventListener('htmx:sendError', failed);
          resolve(value);
        };
        const matches = event => event.detail?.target === board
          && String(event.detail?.requestConfig?.verb || '').toLowerCase() === 'get';
        const complete = event => { if (matches(event)) finish(event.detail.successful === true); };
        const failed = event => { if (matches(event)) finish(false); };
        const timeout = setTimeout(() => finish(false), 20000);
        document.addEventListener('htmx:afterRequest', complete);
        document.addEventListener('htmx:sendError', failed);
        events.signal.addEventListener('abort', () => finish(false), { once: true });
        // hx-include reads the currently selected ISO date, never the event's date.
        window.htmx.trigger(board, 'queue:refresh');
      });
    }
    function boardUrl() {
      try {
        const url = new URL(board.getAttribute('hx-get') || location.href, location.href);
        const date = document.getElementById('queueDateFilter_iso')?.value;
        if (typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date)) url.searchParams.set('date', date);
        return url.href;
      } catch (_) { return location.href; }
    }
    function regionalBoardGet() {
      return triggerBoardGet();
    }
    function refresh() {
      debounce = null;
      if (!active()) return;
      if (requests.size) { pending = true; return; }
      pending = false;
      if (regionalEnabled) {
        requests.add(owner);
        regional.refresh({ owner, regions: ['board'], url: boardUrl(), cause: 'queue', history: 'none' }).finally(() => {
          requests.delete(owner);
          if (pending) invalidate();
        });
        return;
      }
      if (!window.htmx) { pending = true; return; }
      // hx-include reads the currently selected ISO date, never the event's date.
      window.htmx.trigger(board, 'queue:refresh');
    }
    function invalidate() {
      pending = true;
      if (active() && debounce === null) debounce = setTimeout(refresh, 100);
    }
    function reconcile() {
      clearTimeout(poll);
      if (!active()) return;
      poll = setTimeout(() => { invalidate(); reconcile(); }, ready ? 60000 : 15000);
    }
    document.addEventListener('synapse:realtime-event', event => {
      const message = event.detail;
      const date = document.getElementById('queueDateFilter_iso')?.value;
      if (message?.event === 'queue.invalidated' && message.data?.unit === board.dataset.queueUnit
          && typeof date === 'string' && message.data.date === date) invalidate();
    }, options);
    document.addEventListener('synapse:realtime-status', event => {
      const next = event.detail?.status === 'ready';
      if (next === ready) return;
      ready = next;
      reconcile();
      if (ready) invalidate(); // Also covers first connection and reconnect gaps.
    }, options);
    document.addEventListener('htmx:beforeRequest', event => {
      if (event.detail?.target?.id === board.id) requests.add(event.detail.xhr);
    }, options);
    document.addEventListener('htmx:afterRequest', event => {
      if (event.detail?.target?.id !== board.id) return;
      requests.delete(event.detail.xhr);
      if (pending) invalidate();
    }, options);
    document.addEventListener('visibilitychange', () => {
      cancelTimers();
      if (!active()) return;
      ready = window.SynapseRealtime?.status === 'ready';
      reconcile();
      invalidate();
    }, options);
    window.addEventListener('pagehide', () => {
      paused = true;
      cancelTimers();
    }, options);
    window.addEventListener('pageshow', () => {
      paused = false;
      ready = window.SynapseRealtime?.status === 'ready';
      reconcile();
      invalidate();
    }, options);
    document.addEventListener('synapse:before-swap', event => {
      if (!event.detail?.root?.contains(board)) return;
      paused = true;
      cancelTimers();
      events.abort();
      unregisterRegional?.();
      unregisterRegional = null;
      requests.clear();
      delete board.dataset.queueRealtime;
    }, options);
    reconcile();
    if (ready) invalidate();
  }
})();
