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
    board.dataset.queueRealtime = 'true';
    const events = new AbortController();
    const options = { signal: events.signal };
    const requests = new Set();
    let ready = window.SynapseRealtime?.status === 'ready';
    let paused = false;
    let pending = false;
    let poll = null;
    let debounce = null;

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
    function refresh() {
      debounce = null;
      if (!active()) return;
      if (requests.size || !window.htmx) { pending = true; return; }
      pending = false;
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
      requests.clear();
      delete board.dataset.queueRealtime;
    }, options);
    reconcile();
    if (ready) invalidate();
  }
})();
