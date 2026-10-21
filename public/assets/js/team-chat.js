/** Department text chat. No browser persistence, HTML interpolation, or hidden-tab polling. */
(function () {
  'use strict';
  if (window.SynapseTeamChat) return;
  const instances = new Map();
  let cachedChat = false;
  const MAX_MESSAGES = 400;
  const POLL_MS = 5000;
  const dayFormat = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'long' });
  const timeFormat = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' });
  const dayKey = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' });
  const dateOf = value => new Date(value.replace(' ', 'T') + 'Z');
  const node = (tag, className, text) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  };
  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }
  function init(root) {
    if (instances.has(root)) return;
    let initial;
    try { initial = JSON.parse(root.dataset.initial); } catch { return; }
    root.removeAttribute('data-initial');
    const events = new AbortController();
    const state = { stopped: false, revoked: false, polling: false, reading: false, sending: false,
      timer: null, readTimer: null, pollAbort: null, messages: new Map(), pending: null,
      lastRead: initial.last_read_id, entryRead: initial.last_read_id,
      older: initial.after_mode ? initial.last_read_id > 0 : initial.has_more,
      historyPaused: initial.browsing_history, writes: Promise.resolve(), unread: initial.unread_count };
    const find = selector => root.querySelector(selector);
    const log = find('[data-chat-log]'), form = find('[data-chat-form]'), body = find('[data-chat-body]');
    const task = find('[data-chat-task]'), send = find('[data-chat-send]'), error = find('[data-chat-error]');
    const connection = find('[data-chat-connection]'), older = find('[data-chat-older]');
    const fresh = find('[data-chat-new]'), sendStatus = find('[data-chat-send-status]');
    const live = () => !state.stopped && root.isConnected;
    const visible = () => live() && !document.hidden && root.getClientRects().length > 0;
    const atBottom = () => log.scrollHeight - log.scrollTop - log.clientHeight < 48;
    const ids = () => [...state.messages.keys()].sort((a, b) => a - b);
    const maximum = () => ids().at(-1) || 0;
    const warn = message => { error.textContent = message; error.hidden = !message; };
    function summary(data) {
      if (Number.isInteger(data.last_read_id)) state.lastRead = Math.max(state.lastRead, data.last_read_id);
      if (Number.isInteger(data.unread_count)) state.unread = data.unread_count;
      find('[data-chat-unread]').textContent = String(state.unread);
      find('[data-chat-read-status]').textContent = `Unread: ${state.unread}`;
    }
    function controls() {
      older.hidden = !state.older;
      find('[data-chat-history-status]').textContent = state.historyPaused ? 'Viewing history; live updates paused. Show latest to resume.' : '40 messages per page · Asia/Manila';
      send.disabled = state.sending || state.revoked;
      body.readOnly = Boolean(state.pending) || state.revoked;
      task.readOnly = Boolean(state.pending) || state.revoked;
      send.textContent = state.sending ? 'Sending…' : state.pending ? 'Retry send' : 'Send';
      fresh.hidden = atBottom() && !state.historyPaused;
    }
    function render() {
      const fragment = document.createDocumentFragment();
      let lastDay = null, divider = false;
      for (const id of ids()) {
        const message = state.messages.get(id), stamp = dateOf(message.created_at), key = dayKey.format(stamp);
        if (key !== lastDay) { fragment.append(node('div', 'team-chat-day', dayFormat.format(stamp))); lastDay = key; }
        if (!divider && id > state.entryRead && message.sender_user_id !== Number(root.dataset.actorId)) {
          fragment.append(node('div', 'team-chat-unread-divider', 'Unread messages'));
          divider = true;
        }
        const article = node('article', 'team-chat-message' + (message.sender_user_id === Number(root.dataset.actorId) ? ' is-own' : ''));
        article.dataset.messageId = String(id);
        const bubble = node('div', 'team-chat-bubble'), header = node('div', 'team-chat-message-header');
        header.append(node('strong', '', message.sender_name));
        const time = node('time', '', timeFormat.format(stamp));
        time.dateTime = stamp.toISOString(); time.title = dayFormat.format(stamp) + ' · Asia/Manila';
        header.append(time); bubble.append(header, node('p', 'teams-preserve mb-0', message.body));
        if (message.task_id !== null) {
          const link = node('a', 'team-chat-task', `Task #${message.task_id}: ${message.task_title || 'Task'}`);
          link.href = `${root.dataset.teamsUrl}/${message.task_id}`;
          link.setAttribute('data-app-modal-open', ''); bubble.append(link);
        }
        article.append(bubble); fragment.append(article);
      }
      if (!state.messages.size) fragment.append(node('p', 'text-muted text-center p-4', 'No messages yet. Start an operational discussion.'));
      log.replaceChildren(fragment);
    }
    function merge(messages, direction) {
      messages.forEach(message => state.messages.set(message.id, message));
      const ordered = ids();
      if (ordered.length > MAX_MESSAGES) {
        const remove = direction === 'older' ? ordered.slice(MAX_MESSAGES) : ordered.slice(0, ordered.length - MAX_MESSAGES);
        remove.forEach(id => state.messages.delete(id));
        if (direction === 'older') state.historyPaused = true;
        else state.older = true;
      }
      render();
    }
    async function request(url, fields = null, polling = false) {
      const controller = new AbortController();
      const abort = () => controller.abort();
      events.signal.addEventListener('abort', abort, { once: true });
      if (polling) state.pollAbort = controller;
      const timeout = setTimeout(abort, 15000);
      try {
        const options = { credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() } };
        if (fields) {
          const data = new URLSearchParams(fields);
          const name = document.querySelector('meta[name="csrf-name"]')?.content;
          const hash = document.querySelector('meta[name="csrf-token"]')?.content;
          if (name && hash) data.set(name, hash);
          options.method = 'POST'; options.body = data;
        }
        const response = await fetch(url, options);
        window.SynapseCsrf?.fromResponse(response);
        let payload;
        try { payload = await response.json(); } catch { throw new Error('Chat response could not be read. Check your connection or sign-in session.'); }
        window.SynapseCsrf?.sync(payload.csrf);
        if (!live()) throw new DOMException('Chat closed', 'AbortError');
        if (response.status === 401 || response.status === 403 || response.redirected) {
          state.revoked = true; clearTimeout(state.timer); state.messages.clear(); log.replaceChildren();
          body.value = ''; task.value = ''; state.pending = null; controls();
        }
        if (!response.ok || payload.success !== true) {
          const failure = new Error(payload.message || 'Chat request could not be completed.');
          failure.status = response.status; throw failure;
        }
        return payload.data;
      } finally {
        clearTimeout(timeout); events.signal.removeEventListener('abort', abort);
        if (state.pollAbort === controller) state.pollAbort = null;
      }
    }
    function write(url, fields) {
      const operation = state.writes.then(() => {
        if (!live() || state.revoked) throw new DOMException('Chat closed', 'AbortError');
        return request(url, fields);
      });
      state.writes = operation.catch(() => {});
      return operation;
    }
    function schedule(delay = POLL_MS) {
      clearTimeout(state.timer);
      if (visible() && !state.revoked && !state.historyPaused) state.timer = setTimeout(poll, delay);
    }
    function scheduleRead() {
      clearTimeout(state.readTimer);
      state.readTimer = setTimeout(markVisible, 500);
    }
    async function markVisible() {
      if (!visible() || state.revoked || state.reading || state.historyPaused || document.querySelector('.modal.show')) return;
      const bounds = log.getBoundingClientRect();
      const top = Math.max(bounds.top, 0), bottom = Math.min(bounds.bottom, window.innerHeight);
      if (bottom <= top) return;
      let seen = state.lastRead;
      log.querySelectorAll('[data-message-id]').forEach(message => {
        const box = message.getBoundingClientRect();
        // Reaching the end also handles a message taller than the visible pane.
        if (box.bottom > top && box.bottom <= bottom) seen = Math.max(seen, Number(message.dataset.messageId));
      });
      if (seen <= state.lastRead) return;
      state.reading = true;
      try { summary(await write(`${root.dataset.baseUrl}/read`, { last_read_id: String(seen) })); }
      catch (failure) { if (live() && state.revoked) warn(failure.message); }
      finally { state.reading = false; }
    }
    async function poll() {
      if (!visible() || state.revoked || state.polling || state.historyPaused) return;
      if (state.messages.size >= MAX_MESSAGES && !atBottom()) {
        state.historyPaused = true; controls(); return;
      }
      state.polling = true;
      let delay = POLL_MS;
      try {
        const data = await request(`${root.dataset.baseUrl}/history?after=${maximum()}`, null, true);
        const bottom = atBottom();
        if (data.messages.length) { merge(data.messages, 'newer'); if (bottom) log.scrollTop = log.scrollHeight; }
        summary(data); connection.textContent = 'Updates every 5 seconds while this tab is visible';
        if (data.has_more) delay = 150;
        controls(); scheduleRead();
      } catch (failure) {
        if (live() && !document.hidden && failure.name !== 'AbortError') {
          connection.textContent = state.revoked ? 'Access expired. Reload or sign in again.' : 'Updates interrupted. Retrying…';
          if (state.revoked) warn(failure.message);
          delay = 10000;
        }
      } finally { state.polling = false; schedule(delay); }
    }
    async function loadOlder() {
      if (!visible() || state.polling || state.revoked || !state.messages.size) return;
      state.polling = true; older.disabled = true; clearTimeout(state.timer);
      state.historyPaused = true;
      const height = log.scrollHeight, top = log.scrollTop;
      try {
        const data = await request(`${root.dataset.baseUrl}/history?before=${ids()[0]}`);
        state.older = data.has_more; merge(data.messages, 'older');
        log.scrollTop = Math.max(0, log.scrollHeight - height + top);
        summary(data); controls();
      } catch (failure) { if (live()) warn(failure.message); }
      finally { state.polling = false; older.disabled = false; }
    }
    async function latest() {
      if (!visible() || state.polling || state.revoked) return;
      state.polling = true; clearTimeout(state.timer);
      try {
        const data = await request(`${root.dataset.baseUrl}/history`);
        state.messages.clear(); state.historyPaused = false; state.older = data.has_more;
        merge(data.messages, 'newer'); log.scrollTop = log.scrollHeight; summary(data);
        warn(''); controls(); scheduleRead();
      } catch (failure) { if (live()) warn(failure.message); }
      finally { state.polling = false; schedule(); }
    }
    async function submit(event) {
      event.preventDefault();
      if (state.sending || state.revoked || !live() || !form.reportValidity()) return;
      if (!state.pending) {
        state.pending = { body: body.value, client_nonce: uuid() };
        if (task.value) state.pending.task_id = task.value;
      }
      state.sending = true; controls(); warn(''); sendStatus.textContent = 'Sending…';
      try {
        await write(form.action, state.pending);
        if (!live()) return;
        state.pending = null; form.reset(); body.value = ''; task.value = '';
        sendStatus.textContent = 'Sent. Updates will show the message in chronological order.';
        if (state.historyPaused) await latest(); else { clearTimeout(state.timer); await poll(); }
      } catch (failure) {
        if (!live()) return;
        if ([409, 422].includes(failure.status)) state.pending = null;
        warn(failure.name === 'AbortError' ? 'The send response timed out. Retry with the same send token; it will not create a duplicate.' : failure.message);
        sendStatus.textContent = state.revoked ? 'Access expired. Reload before sending.' : state.pending
          ? 'Delivery is unconfirmed. Retry send uses the same text and token; fields stay locked until resolved.' : 'Not sent. Correct the message or task reference and try again.';
      } finally {
        state.sending = false;
        if (live()) { controls(); if (!state.pending && !state.revoked) body.focus({ preventScroll: true }); }
      }
    }
    form.addEventListener('submit', submit, { signal: events.signal });
    body.addEventListener('keydown', event => {
      if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); form.requestSubmit(); }
    }, { signal: events.signal });
    older.addEventListener('click', loadOlder, { signal: events.signal });
    find('[data-chat-latest]').addEventListener('click', latest, { signal: events.signal });
    fresh.addEventListener('click', () => { if (state.historyPaused) latest(); else { log.scrollTop = log.scrollHeight; controls(); scheduleRead(); } }, { signal: events.signal });
    log.addEventListener('scroll', () => { controls(); scheduleRead(); }, { signal: events.signal });
    window.addEventListener('scroll', scheduleRead, { signal: events.signal, passive: true });
    window.addEventListener('beforeunload', event => {
      if (state.pending || body.value.trim()) { event.preventDefault(); event.returnValue = ''; }
    }, { signal: events.signal });
    document.addEventListener('visibilitychange', () => {
      clearTimeout(state.timer); clearTimeout(state.readTimer);
      if (document.hidden) { state.pollAbort?.abort(); connection.textContent = 'Updates paused while this tab is hidden'; }
      else { connection.textContent = 'Resuming updates…'; schedule(0); scheduleRead(); }
    }, { signal: events.signal });
    instances.set(root, () => {
      state.stopped = true; clearTimeout(state.timer); clearTimeout(state.readTimer); events.abort();
      state.messages.clear(); state.pending = null; instances.delete(root);
    });
    initial.messages.forEach(message => state.messages.set(message.id, message));
    summary(initial); render();
    const unread = log.querySelector('.team-chat-unread-divider');
    if (unread) log.scrollTop = Math.max(0, unread.offsetTop - log.offsetTop - 8); else log.scrollTop = log.scrollHeight;
    connection.textContent = state.historyPaused ? 'Viewing history' : 'Updates every 5 seconds while this tab is visible';
    controls(); scheduleRead(); schedule(initial.after_mode && initial.has_more ? 150 : POLL_MS);
  }
  function start(scope) {
    if (scope?.matches?.('[data-team-chat]')) init(scope);
    scope?.querySelectorAll?.('[data-team-chat]').forEach(init);
  }
  function destroy(scope) {
    for (const [root, cleanup] of instances) if (!scope || scope === root || scope.contains(root)) cleanup();
  }
  document.addEventListener('synapse:page-load', event => start(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
  window.addEventListener('pagehide', () => { cachedChat = instances.size > 0; destroy(); });
  window.addEventListener('pageshow', event => { if (event.persisted && cachedChat) location.reload(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => start(document), { once: true });
  else start(document);
  window.SynapseTeamChat = { init: start, destroy };
})();
