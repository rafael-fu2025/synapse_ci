/** Department text chat. No browser persistence, HTML interpolation, or hidden-tab polling. */
(function () {
  'use strict';
  if (window.SynapseTeamChat) return;
  const instances = new Map();
  let cachedChat = false;
  const MAX_MESSAGES = 400;
  const POLL_MS = 5000;
  const RECONCILE_MS = 60000;
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
      timer: null, readTimer: null, hintTimer: null, dirty: false,
      ready: window.SynapseRealtime?.status === 'ready',
      pollAbort: null, messages: new Map(), pending: null,
      lastRead: initial.last_read_id, entryRead: initial.last_read_id,
      older: initial.after_mode ? initial.last_read_id > 0 : initial.has_more,
      historyPaused: initial.browsing_history, writes: Promise.resolve(), unread: initial.unread_count };
    const find = selector => root.querySelector(selector);
    const log = find('[data-chat-log]'), form = find('[data-chat-form]'), body = find('[data-chat-body]');
    const send = find('[data-chat-send]'), error = find('[data-chat-error]');
        const taskPicker = find('[data-chat-task-picker]'), taskResults = find('[data-chat-task-results]');
        const taskSearch = find('[data-chat-task-search]'), addTask = find('[data-chat-add-task]');
        const mentionPicker = find('[data-chat-mentions]');
        let selectedTasks = new Map(), mentions = [], replyTo = null, previousBody = body.value;
        let mentionMatch = null, mentionOptions = [], mentionIndex = 0, taskSearchVersion = 0, taskSearchTimer;
        const editable = () => !state.pending && !state.revoked;
        function resizeBody() {
          body.style.height = 'auto';
          const style = getComputedStyle(body);
          const maximumHeight = parseFloat(style.lineHeight) * 4 + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) + parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
          body.style.height = `${Math.min(body.scrollHeight + 2, maximumHeight)}px`;
          body.style.overflowY = body.scrollHeight > maximumHeight - 2 ? 'auto' : 'hidden';
        }
        function closeMentions() {
          mentionPicker.hidden = true; mentionMatch = null;
          body.setAttribute('aria-expanded', 'false'); body.removeAttribute('aria-activedescendant');
        }
        function chooseMention(index) {
          if (!editable() || !mentionMatch || !mentionOptions[index]) return;
          const member = mentionOptions[index], start = mentionMatch.start, end = body.selectionStart;
          const text = `@${member.name}`;
          body.setRangeText(text + ' ', start, end, 'end');
          updateMentionRanges();
          mentions.push({ user_id: member.id, start, length: text.length });
          closeMentions(); resizeBody(); body.focus();
        }
        function showMentions() {
          if (!editable() || body.selectionStart !== body.selectionEnd) { closeMentions(); return; }
          const prefix = body.value.slice(0, body.selectionStart), match = /(?:^|\s)@([^@\n]{0,80})$/.exec(prefix);
          if (!match) { closeMentions(); return; }
          const start = prefix.length - match[1].length - 1;
          if (mentions.some(item => start >= item.start && start < item.start + item.length)) { closeMentions(); return; }
          mentionOptions = (initial.members || []).filter(member => member.name.toLocaleLowerCase().includes(match[1].toLocaleLowerCase()));
          if (!mentionOptions.length) { closeMentions(); return; }
          mentionMatch = { start }; mentionIndex = 0;
          mentionPicker.replaceChildren(); mentionPicker.hidden = false; body.setAttribute('aria-expanded', 'true');
          mentionOptions.forEach((member, index) => {
            const option = node('button', 'team-chat-mention-option', `@${member.name}`);
            option.type = 'button'; option.role = 'option'; option.id = `teamChatMention${index}`;
            option.setAttribute('aria-selected', String(index === mentionIndex));
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => chooseMention(index)); mentionPicker.append(option);
          });
          body.setAttribute('aria-activedescendant', 'teamChatMention0');
        }
        function updateMentionRanges() {
          const next = body.value;
          let start = 0;
          while (start < previousBody.length && start < next.length && previousBody[start] === next[start]) start++;
          let oldEnd = previousBody.length, newEnd = next.length;
          while (oldEnd > start && newEnd > start && previousBody[oldEnd - 1] === next[newEnd - 1]) { oldEnd--; newEnd--; }
          const delta = newEnd - oldEnd;
          mentions = mentions.filter(item => {
            if (item.start + item.length <= start) return true;
            if (item.start >= oldEnd) { item.start += delta; return true; }
            return false;
          });
          previousBody = next;
        }
        function renderReferences() {
          find('[data-chat-task-count]').textContent = `${selectedTasks.size} of 20 selected`;
          const selected = find('[data-chat-selected-tasks]'); selected.replaceChildren();
          selectedTasks.forEach(task => {
            const chip = node('button', 'btn btn-sm btn-outline-secondary team-chat-task-chip', `#${task.id}: ${task.title} ×`);
            chip.type = 'button'; chip.disabled = !editable(); chip.setAttribute('aria-label', `Remove task #${task.id}: ${task.title}`);
            chip.addEventListener('click', () => { if (editable()) { selectedTasks.delete(task.id); renderReferences(); if (!taskPicker.hidden) loadTasks(); } });
            selected.append(chip);
          });
          const preview = find('[data-chat-reply-preview]'); preview.replaceChildren(); preview.hidden = !replyTo;
          if (replyTo) {
            const quote = node('div'); quote.append(node('strong', '', `Replying to ${replyTo.sender_name}`), node('p', '', replyTo.body));
            const cancel = node('button', 'btn btn-sm btn-outline-secondary', '×'); cancel.type = 'button'; cancel.disabled = !editable();
            cancel.setAttribute('aria-label', 'Cancel reply'); cancel.addEventListener('click', () => { if (editable()) { replyTo = null; renderReferences(); } });
            preview.append(quote, cancel);
          }
        }
        function closeTasks() {
          if (taskPicker.open) taskPicker.close();
          taskPicker.hidden = true; addTask.setAttribute('aria-expanded', 'false');
          clearTimeout(taskSearchTimer); taskSearchVersion++;
        }
        async function loadTasks() {
          const version = ++taskSearchVersion;
          taskResults.textContent = 'Loading tasks…';
          try {
            const data = await request(`${root.dataset.baseUrl}/tasks?q=${encodeURIComponent(taskSearch.value)}`);
            if (!live() || version !== taskSearchVersion || taskPicker.hidden) return;
            taskResults.replaceChildren();
            if (!data.tasks.length) taskResults.textContent = 'No active tasks match your search.';
            else {
              taskResults.append(node('p', 'small text-muted mt-2 mb-0', 'Showing up to 50 active tasks. Search to narrow the list.'));
              data.tasks.forEach(task => {
                const label = node('label', 'team-chat-task-option'), checkbox = document.createElement('input');
                checkbox.type = 'checkbox'; checkbox.checked = selectedTasks.has(task.id); checkbox.disabled = !editable();
                checkbox.addEventListener('change', () => {
                  if (!editable()) return;
                  const taskError = find('[data-chat-task-error]'); taskError.hidden = true;
                  if (checkbox.checked) {
                    if (selectedTasks.size >= 20) { checkbox.checked = false; taskError.textContent = 'Choose up to 20 task references.'; taskError.hidden = false; return; }
                    selectedTasks.set(task.id, task);
                  } else selectedTasks.delete(task.id);
                  renderReferences();
                });
                label.append(checkbox, node('span', '', `#${task.id}: ${task.title}`)); taskResults.append(label);
              });
            }
          } catch (failure) { if (live() && version === taskSearchVersion) taskResults.textContent = failure.message; }
        }
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
      addTask.disabled = !editable(); taskSearch.disabled = !editable();
      send.setAttribute('aria-label', state.sending ? 'Sending…' : state.pending ? 'Retry send' : 'Send');
      send.title = send.getAttribute('aria-label');
      send.setAttribute('aria-busy', String(state.sending));
      root.querySelectorAll('[data-chat-reply]').forEach(button => { button.disabled = !editable(); });
      if (!editable()) { closeMentions(); closeTasks(); }
      renderReferences();
      fresh.hidden = atBottom() && !state.historyPaused;
    }
    function disposeReplyTooltips() {
      log.querySelectorAll('[data-chat-reply]').forEach(button => window.bootstrap?.Tooltip?.getInstance(button)?.dispose());
    }
    function render() {
      disposeReplyTooltips();
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
        article.dataset.messageId = String(id); article.id = `chat-message-${id}`; article.tabIndex = -1;
        article.classList.toggle('is-targeted', id === initial.target_message_id);
        const bubble = node('div', 'team-chat-bubble'), header = node('div', 'team-chat-message-header');
        header.append(node('strong', '', message.sender_name));
        const time = node('time', '', timeFormat.format(stamp));
        time.dateTime = stamp.toISOString(); time.title = dayFormat.format(stamp) + ' · Asia/Manila';
        header.append(time); bubble.append(header);
        if (message.reply_to) {
          const quote = node('div', 'team-chat-reply-quote');
          quote.append(node('strong', '', `Reply to ${message.reply_to.sender_name}`), node('p', '', message.reply_to.body)); bubble.append(quote);
        }
        const text = node('p', 'teams-preserve mb-0');
        let offset = 0;
        for (const mention of [...(message.mentions || [])].sort((a, b) => a.start - b.start)) {
          if (!Number.isInteger(mention.start) || !Number.isInteger(mention.length) || mention.start < offset || mention.length < 1 || mention.start + mention.length > message.body.length) continue;
          text.append(document.createTextNode(message.body.slice(offset, mention.start)));
          const mark = node('span', 'team-chat-mention', message.body.slice(mention.start, mention.start + mention.length));
          mark.title = mention.name || 'Team member'; text.append(mark); offset = mention.start + mention.length;
        }
        text.append(document.createTextNode(message.body.slice(offset))); bubble.append(text);
        const tasks = message.tasks || (message.task_id ? [{ id: message.task_id, title: message.task_title }] : []);
        for (const task of tasks) {
          const link = node('a', 'team-chat-task', `Task #${task.id}: ${task.title || 'Task'}`);
          link.href = `${root.dataset.teamsUrl}/${task.id}`;
          link.setAttribute('data-app-modal-open', ''); bubble.append(link);
        }
        const reply = node('button', 'team-chat-reply-button');
        const replyIcon = node('i', 'bi bi-reply'); replyIcon.setAttribute('aria-hidden', 'true'); reply.append(replyIcon);
        reply.type = 'button'; reply.dataset.chatReply = ''; reply.disabled = !editable();
        reply.setAttribute('aria-label', `Reply to ${message.sender_name}`);
        reply.dataset.bsToggle = 'tooltip'; reply.dataset.bsTitle = 'Reply'; reply.dataset.bsPlacement = 'top';
        reply.addEventListener('click', () => {
          window.bootstrap?.Tooltip?.getInstance(reply)?.hide();
          if (editable()) { replyTo = message; renderReferences(); body.focus(); }
        });
        article.append(bubble, reply); fragment.append(article);
      }
      if (!state.messages.size) fragment.append(node('p', 'text-muted text-center p-4', 'No messages yet. Start an operational discussion.'));
      log.replaceChildren(fragment);
      log.querySelectorAll('[data-chat-reply]').forEach(button => {
        if (window.bootstrap?.Tooltip) window.bootstrap.Tooltip.getOrCreateInstance(button, { trigger: 'hover', container: 'body', placement: 'top', animation: false });
      });
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
          const data = new URLSearchParams();
          Object.entries(fields).forEach(([key, value]) => {
            if (Array.isArray(value)) value.forEach((item, index) => {
              if (item && typeof item === 'object') Object.entries(item).forEach(([name, field]) => data.append(`${key}[${index}][${name}]`, String(field)));
              else data.append(`${key}[]`, String(item));
            });
            else data.set(key, String(value));
          });
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
          state.revoked = true; clearTimeout(state.timer); state.messages.clear(); disposeReplyTooltips(); log.replaceChildren();
          body.value = ''; previousBody = ''; selectedTasks.clear(); mentions = []; replyTo = null; state.pending = null; resizeBody(); controls();
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
    const cadence = () => state.ready ? RECONCILE_MS : POLL_MS;
    const connectionText = () => state.ready ? '' : 'Updates every 5 seconds while this tab is visible';
    function invalidate() {
      if (!visible() || state.revoked || state.historyPaused) return;
      state.dirty = true;
      if (state.polling || state.hintTimer !== null) return;
      state.hintTimer = setTimeout(() => { state.hintTimer = null; poll(); }, 100);
    }
    function schedule(delay = cadence()) {
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
      clearTimeout(state.timer); clearTimeout(state.hintTimer); state.hintTimer = null;
      state.polling = true; state.dirty = false;
      let delay = null;
      try {
        const data = await request(`${root.dataset.baseUrl}/history?after=${maximum()}`, null, true);
        if (!visible() || state.historyPaused) return;
        const bottom = atBottom();
        if (data.messages.length) { merge(data.messages, 'newer'); if (bottom) log.scrollTop = log.scrollHeight; }
        summary(data); connection.textContent = connectionText();
        if (data.has_more) delay = 150;
        controls(); scheduleRead();
      } catch (failure) {
        if (live() && !document.hidden && failure.name !== 'AbortError') {
          connection.textContent = state.revoked ? 'Access expired. Reload or sign in again.' : 'Updates interrupted. Retrying…';
          if (state.revoked) warn(failure.message);
          delay = POLL_MS;
        }
      } finally { state.polling = false; schedule(state.dirty ? 100 : (delay ?? cadence())); }
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
      finally { state.polling = false; schedule(state.dirty ? 100 : cadence()); }
    }
    async function submit(event) {
      event.preventDefault();
      if (state.sending || state.revoked || !live() || !form.reportValidity()) return;
      if (!state.pending) {
        updateMentionRanges();
        state.pending = { body: body.value, client_nonce: uuid(), task_ids: [...selectedTasks.keys()], mentions: mentions.map(item => ({ ...item })) };
        if (replyTo) state.pending.reply_to_id = replyTo.id;
      }
      state.sending = true; controls(); warn(''); sendStatus.textContent = 'Sending…';
      try {
        await write(form.action, state.pending);
        if (!live()) return;
        state.pending = null; form.reset(); body.value = ''; previousBody = ''; selectedTasks.clear(); mentions = []; replyTo = null; closeMentions(); closeTasks(); resizeBody();
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
    body.addEventListener('input', () => { updateMentionRanges(); resizeBody(); showMentions(); }, { signal: events.signal });
    body.addEventListener('click', showMentions, { signal: events.signal });
    body.addEventListener('keyup', event => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) showMentions(); }, { signal: events.signal });
    body.addEventListener('keydown', event => {
      if (event.isComposing || event.keyCode === 229) return;
      if (!mentionPicker.hidden && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
        event.preventDefault(); mentionIndex = (mentionIndex + (event.key === 'ArrowDown' ? 1 : -1) + mentionOptions.length) % mentionOptions.length;
        [...mentionPicker.children].forEach((option, index) => option.setAttribute('aria-selected', String(index === mentionIndex)));
        body.setAttribute('aria-activedescendant', `teamChatMention${mentionIndex}`); mentionPicker.children[mentionIndex].scrollIntoView({ block: 'nearest' });
      } else if (!mentionPicker.hidden && ['Enter', 'Tab'].includes(event.key)) { event.preventDefault(); chooseMention(mentionIndex); }
      else if (event.key === 'Escape') { closeMentions(); closeTasks(); }
      else if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
    }, { signal: events.signal });
    addTask.addEventListener('click', () => {
      if (!editable()) return;
      if (!taskPicker.hidden) { closeTasks(); body.focus(); return; }
      closeMentions(); find('[data-chat-task-error]').hidden = true; taskPicker.hidden = false; taskPicker.showModal(); addTask.setAttribute('aria-expanded', 'true'); taskSearch.focus(); loadTasks();
    }, { signal: events.signal });
    find('[data-chat-task-close]').addEventListener('click', () => { closeTasks(); body.focus(); }, { signal: events.signal });
    find('[data-chat-task-dismiss]').addEventListener('click', () => { closeTasks(); addTask.focus(); }, { signal: events.signal });
    taskPicker.addEventListener('cancel', event => { event.preventDefault(); closeTasks(); addTask.focus(); }, { signal: events.signal });
    taskPicker.addEventListener('click', event => {
      if (event.target !== taskPicker) return;
      const bounds = taskPicker.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
        closeTasks(); addTask.focus();
      }
    }, { signal: events.signal });
    taskSearch.addEventListener('keydown', event => {
      if (event.key === 'Enter') event.preventDefault();
    }, { signal: events.signal });
    taskSearch.addEventListener('input', () => {
      clearTimeout(taskSearchTimer); taskSearchVersion++; taskSearchTimer = setTimeout(loadTasks, 250);
    }, { signal: events.signal });
    document.addEventListener('click', event => {
      if (!mentionPicker.contains(event.target) && event.target !== body) closeMentions();
    }, { signal: events.signal });
    older.addEventListener('click', loadOlder, { signal: events.signal });
    find('[data-chat-latest]').addEventListener('click', latest, { signal: events.signal });
    fresh.addEventListener('click', () => { if (state.historyPaused) latest(); else { log.scrollTop = log.scrollHeight; controls(); scheduleRead(); } }, { signal: events.signal });
    log.addEventListener('scroll', () => { controls(); scheduleRead(); }, { signal: events.signal });
    window.addEventListener('scroll', scheduleRead, { signal: events.signal, passive: true });
    window.addEventListener('beforeunload', event => {
      if (state.pending || body.value.trim() || selectedTasks.size || replyTo) { event.preventDefault(); event.returnValue = ''; }
    }, { signal: events.signal });
    const infoBtn = find('.team-chat-info-btn');
    if (infoBtn && window.bootstrap?.Popover) {
      const popover = window.bootstrap.Popover.getOrCreateInstance(infoBtn, {
        trigger: 'manual',
        placement: 'bottom',
        container: 'body',
        customClass: 'team-chat-info-popover',
        title: '',
        html: false
      });
      infoBtn.addEventListener('mouseenter', () => {
        if (window.innerWidth >= 768) popover.show();
      }, { signal: events.signal });
      infoBtn.addEventListener('mouseleave', () => {
        if (window.innerWidth >= 768) popover.hide();
      }, { signal: events.signal });
      infoBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        popover.toggle();
      }, { signal: events.signal });
      document.addEventListener('click', (e) => {
        if (!infoBtn.contains(e.target) && !e.target.closest('.team-chat-info-popover')) {
          popover.hide();
        }
      }, { signal: events.signal });
    }
    const unit = new URL(root.dataset.baseUrl, location.href).pathname.split('/').filter(Boolean).at(-3);
    document.addEventListener('synapse:realtime-event', event => {
      if (event.detail?.event === 'team.chat.changed' && event.detail?.data?.unit === unit) invalidate();
    }, { signal: events.signal });
    document.addEventListener('synapse:realtime-status', event => {
      const ready = event.detail?.status === 'ready';
      if (ready === state.ready) return;
      state.ready = ready;
      if (visible() && !state.historyPaused) connection.textContent = connectionText();
      if (ready) invalidate();
      else schedule();
    }, { signal: events.signal });
    document.addEventListener('visibilitychange', () => {
      clearTimeout(state.timer); clearTimeout(state.readTimer); clearTimeout(state.hintTimer); state.hintTimer = null;
      if (document.hidden) { state.pollAbort?.abort(); connection.textContent = 'Updates paused while this tab is hidden'; }
      else { connection.textContent = 'Resuming updates…'; schedule(0); scheduleRead(); }
    }, { signal: events.signal });
    instances.set(root, () => {
      closeTasks();
      state.stopped = true; clearTimeout(state.timer); clearTimeout(state.readTimer); clearTimeout(state.hintTimer); clearTimeout(taskSearchTimer); events.abort();
      disposeReplyTooltips();
      if (infoBtn) window.bootstrap?.Popover?.getInstance(infoBtn)?.dispose();
      state.messages.clear(); state.pending = null; instances.delete(root);
    });
    initial.messages.forEach(message => state.messages.set(message.id, message));
    summary(initial); render();
    const target = Number.isSafeInteger(initial.target_message_id) ? log.querySelector(`[data-message-id="${initial.target_message_id}"]`) : null;
    const unread = log.querySelector('.team-chat-unread-divider');
    if (target) {
      log.scrollTop = Math.max(0, target.offsetTop - log.offsetTop - (log.clientHeight - target.offsetHeight) / 2);
      target.focus({ preventScroll: true });
    } else if (unread) log.scrollTop = Math.max(0, unread.offsetTop - log.offsetTop - 8); else log.scrollTop = log.scrollHeight;
    connection.textContent = state.historyPaused ? 'Viewing history' : connectionText();
    resizeBody(); controls(); scheduleRead(); schedule(initial.after_mode && initial.has_more ? 150 : cadence());
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
