/** Opt-in transport only; existing polling remains the fallback. */
(function () {
  'use strict';
  if (window.SynapseRealtime) return;

  const FETCH_TIMEOUT = 10000;
  const AUTH_TIMEOUT = 10000;
  const MAX_RETRY = 30000;
  let status = 'idle';
  let generation = 0;
  let request = null;
  let socket = null;
  let deadline = null;
  let retryTimer = null;
  let failures = 0;
  let retryAt = 0;
  let readyAt = null;
  let stopped = false;
  let suspended = false;
  let blocked = false;

  function report(value) {
    if (status === value) return;
    status = value;
    document.dispatchEvent(new CustomEvent('synapse:realtime-status', { detail: { status } }));
  }
  function visible() {
    return !suspended && document.visibilityState === 'visible';
  }
  function clearWork() {
    generation++;
    clearTimeout(deadline);
    clearTimeout(retryTimer);
    deadline = retryTimer = null;
    const pending = request;
    request = null;
    pending?.abort();
    const old = socket;
    socket = null;
    if (old) {
      old.onopen = old.onmessage = old.onerror = old.onclose = null;
      old.close();
    }
    readyAt = null;
  }
  function halt(value) {
    blocked = true;
    clearWork();
    report(value);
  }
  function failed() {
    // A fleeting ready/close cycle must not reset exponential backoff.
    if (readyAt !== null && Date.now() - readyAt >= MAX_RETRY) failures = 0;
    clearWork();
    const ceiling = Math.min(MAX_RETRY, 1000 * Math.pow(2, Math.min(failures++, 5)));
    retryAt = Date.now() + Math.round(ceiling * (0.5 + Math.random() * 0.5));
    resume();
  }
  function ticketUrl() {
    const content = document.querySelector('meta[name="synapse-realtime-ticket-url"]')?.content;
    if (!content) return null;
    const url = new URL(content, window.location.href);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin
        || url.username || url.password || url.hash) throw new Error('Invalid ticket endpoint');
    return url.href;
  }
  function socketUrl(value) {
    if (typeof value !== 'string' || !/^wss?:\/\//.test(value) || /[\s\\]/.test(value)) throw new Error('Invalid socket endpoint');
    const url = new URL(value);
    if (!['ws:', 'wss:'].includes(url.protocol) || !url.hostname || url.username || url.password
        || url.hash || url.search || (window.location.protocol === 'https:' && url.protocol !== 'wss:')) {
      throw new Error('Invalid socket endpoint');
    }
    // Deliberately permit a separate socket host, but never put credentials in its URL.
    return url.href;
  }
  async function connect(url) {
    const token = ++generation;
    const controller = new AbortController();
    request = controller;
    const current = () => token === generation && !stopped && visible();
    let unauthorized = false;
    deadline = setTimeout(() => {
      if (current()) { if (unauthorized) halt('unauthorized'); else failed(); }
    }, FETCH_TIMEOUT);
    report('connecting');
    if (!current()) return;
    try {
      const response = await window.fetch(url, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        signal: controller.signal,
        headers: { ...window.SynapseCsrf?.headers(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!current()) return;
      if (response.redirected || (response.url && response.url !== url)) throw new Error('Unexpected response');
      window.SynapseCsrf?.fromResponse(response);
      unauthorized = response.status === 401 || response.status === 403;
      if (unauthorized) {
        blocked = true;
        report('unauthorized');
        if (!current()) return;
      }
      const payload = await response.json();
      if (!current()) return;
      window.SynapseCsrf?.sync(payload?.csrf);
      if (unauthorized) { halt('unauthorized'); return; }
      if (!response.ok) throw new Error('Ticket unavailable');
      if (payload?.enabled === false) { halt('disabled'); return; }
      if (payload?.enabled !== true || typeof payload.ticket !== 'string' || !payload.ticket
          || payload.ticket.length > 8192 || !Number.isFinite(payload.expires_at)
          || payload.expires_at * 1000 <= Date.now()) throw new Error('Invalid ticket');
      const endpoint = socketUrl(payload.url);
      clearTimeout(deadline);
      request = null;
      const ws = new window.WebSocket(endpoint);
      socket = ws;
      let ticket = payload.ticket;
      let ready = false;
      deadline = setTimeout(() => { if (current()) failed(); }, AUTH_TIMEOUT);
      ws.onopen = () => {
        if (!current() || socket !== ws) return;
        try { ws.send(JSON.stringify({ type: 'authenticate', ticket })); ticket = null; }
        catch (_) { failed(); }
      };
      ws.onmessage = event => {
        if (!current() || socket !== ws || typeof event.data !== 'string') return;
        let message;
        try { message = JSON.parse(event.data); } catch (_) { return; }
        if (!message || typeof message !== 'object' || Array.isArray(message)) return;
        if (message.type === 'ready' && !ready) {
          ready = true;
          readyAt = Date.now();
          clearTimeout(deadline);
          deadline = null;
          report('ready');
        } else if (ready && message.type === 'event' && typeof message.channel === 'string'
            && message.channel && typeof message.event === 'string' && message.event
            && Object.prototype.hasOwnProperty.call(message, 'data')) {
          document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail: message }));
        }
        // Pong and unknown messages have no application side effects.
      };
      ws.onclose = ws.onerror = () => { if (current() && socket === ws) failed(); };
    } catch (_) {
      if (current()) { if (unauthorized) halt('unauthorized'); else failed(); }
    }
  }
  function resume() {
    if (stopped || blocked) return;
    if (!visible()) { clearWork(); report('paused'); return; }
    if (request || socket || retryTimer !== null) return;
    let url;
    try { url = ticketUrl(); } catch (_) { halt('disabled'); return; }
    if (!url || !window.fetch || !window.WebSocket) { report('disabled'); return; }
    const delay = retryAt - Date.now();
    if (delay > 0) {
      retryTimer = setTimeout(() => { retryTimer = null; resume(); }, delay);
      report('retrying');
      return;
    }
    connect(url);
  }
  window.SynapseRealtime = Object.freeze({
    reconnect() {
      clearWork();
      stopped = blocked = false;
      failures = retryAt = 0;
      resume();
    },
    stop() {
      stopped = true;
      clearWork();
      report('stopped');
    },
    get status() { return status; }
  });
  function pause() {
    clearWork();
    if (!stopped && !blocked) report('paused');
  }
  document.addEventListener('visibilitychange', () => { if (visible()) resume(); else pause(); });
  window.addEventListener('pagehide', () => { suspended = true; pause(); });
  window.addEventListener('pageshow', () => { suspended = false; resume(); });
  resume();
})();
