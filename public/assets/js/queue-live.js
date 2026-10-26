/** Small, authorized queue projections; never refresh a whole dashboard or patient chart. */
(function () {
  'use strict';
  if (window.SynapseQueueLive) return;
  window.SynapseQueueLive = true;
  document.addEventListener('synapse:page-load', init);
  init();

  function node(tag, className, text) {
    const el = document.createElement(tag);
    el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  }
  function validDate(value) { return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value); }
  function init() {
    document.querySelectorAll('[data-personal-queue], [data-queue-dashboard]').forEach(bind);
  }
  function bind(root) {
    if (root.dataset.queueLiveBound) return;
    const personal = root.hasAttribute('data-personal-queue');
    const unit = root.dataset.queueDashboard;
    const feedback = root.querySelector('[data-queue-feedback]');
    const tickets = root.querySelector('[data-personal-queue-tickets]');
    let url;
    try {
      url = new URL(personal ? root.dataset.queueUrl : root.dataset.queueCountsUrl, location.href);
      if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password || url.hash || url.search) return;
      if (!personal && !['clinic', 'guidance'].includes(unit)) return;
    } catch (_) { return; }
    root.dataset.queueLiveBound = 'true';
    const events = new AbortController();
    const options = { signal: events.signal };
    let poll = null, debounce = null, request = null, dirty = false, suspended = false, stopped = false;
    let ready = window.SynapseRealtime?.status === 'ready';
    let rendered = null;
    const visible = () => !stopped && !suspended && root.isConnected && !document.hidden;
    function notice(text) {
      if (!feedback) return;
      feedback.textContent = text;
      feedback.hidden = !text;
    }
    function schedule() {
      clearTimeout(poll);
      poll = null;
      // Private hints only cover the caller's ticket, not other patients moving ahead.
      if (visible()) poll = setTimeout(invalidate, personal || !ready ? 15000 : 60000);
    }
    function invalidate() {
      dirty = true;
      if (!visible() || request || debounce !== null) return;
      clearTimeout(poll); poll = null;
      debounce = setTimeout(refresh, 100);
    }
    function validate(payload) {
      if (!payload || !validDate(payload.date)) throw new Error('Invalid queue response');
      if (personal) {
        if (!Array.isArray(payload.tickets) || payload.tickets.length > 2) throw new Error('Invalid tickets');
        for (const ticket of payload.tickets) {
          if (!ticket || !['clinic', 'guidance'].includes(ticket.unit)
              || !['waiting', 'called', 'in_consultation', 'skipped'].includes(ticket.status)
              || !['routine', 'urgent', 'emergency'].includes(ticket.priority)
              || typeof ticket.ticket_code !== 'string' || !ticket.ticket_code || ticket.ticket_code.length > 64) throw new Error('Invalid ticket');
          const nonnegative = value => value === null || Number.isSafeInteger(value) && value >= 0;
          if (!(ticket.position === null || Number.isSafeInteger(ticket.position) && ticket.position > 0)
              || !nonnegative(ticket.people_ahead) || !nonnegative(ticket.estimated_wait_minutes)) throw new Error('Invalid queue progress');
          if (ticket.status === 'waiting') {
            if ((ticket.position === null) !== (ticket.people_ahead === null)
                || ticket.position !== null && ticket.position !== ticket.people_ahead + 1
                || ticket.people_ahead === null && ticket.estimated_wait_minutes !== null) throw new Error('Inconsistent queue progress');
          } else if (ticket.status === 'skipped') {
            if (ticket.position !== null || ticket.people_ahead !== null || ticket.estimated_wait_minutes !== null) throw new Error('Invalid held ticket progress');
          } else if (ticket.position !== null || ticket.people_ahead !== 0 || ticket.estimated_wait_minutes !== 0) throw new Error('Invalid active ticket progress');
        }
      } else {
        const keys = unit === 'clinic' ? ['queueToday', 'queueWaiting', 'queueCalled', 'queueCompleted'] : ['queueToday'];
        if (payload.unit !== unit || !payload.counts || keys.some(key => !Number.isSafeInteger(payload.counts[key]) || payload.counts[key] < 0)) throw new Error('Invalid counts');
      }
    }
    function render(payload) {
      validate(payload);
      const signature = JSON.stringify(payload);
      if (rendered === signature) return;
      if (personal) {
        const fragment = document.createDocumentFragment();
        for (const ticket of payload.tickets) {
          const card = node('div', 'card border-0 shadow-sm bg-primary text-white p-4 mb-2');
          const row = node('div', 'd-flex flex-wrap justify-content-between align-items-center gap-3');
          const copy = node('div', '');
          copy.append(node('span', 'badge bg-white text-primary text-uppercase mb-2', 'Live Waiting Room Ticket'));
          copy.append(node('h3', 'fw-bold mb-1 display-6 text-white', ticket.ticket_code));
          const line = node('p', 'mb-0 opacity-75', (ticket.unit === 'clinic' ? 'University Clinic' : 'Guidance & Counseling Center') + ' · Status: ');
          const status = { waiting: 'Waiting', called: 'Called', in_consultation: 'In Consultation', skipped: 'Skipped' }[ticket.status];
          line.append(node('strong', '', status));
          copy.append(line);
          row.append(copy, node('span', 'badge bg-light text-dark px-3 py-2 fw-semibold', 'Priority: ' + ticket.priority[0].toUpperCase() + ticket.priority.slice(1)));
          card.append(row);
          const progress = node('dl', 'row g-3 mt-2 mb-0'); progress.setAttribute('data-personal-queue-progress', '');
          const positionLabel = ticket.status === 'called' ? 'Your turn' : ticket.status === 'in_consultation' ? 'In progress'
            : ticket.status === 'skipped' ? 'On hold' : ticket.position === null ? '—' : `#${ticket.position}`;
          const aheadLabel = ticket.people_ahead === null ? '—' : String(ticket.people_ahead);
          const waitLabel = ticket.status === 'called' ? 'Now' : ticket.status === 'in_consultation' ? 'In progress'
            : ticket.status === 'skipped' ? 'Ask reception' : ticket.estimated_wait_minutes === null ? 'Not enough recent data'
            : ticket.estimated_wait_minutes === 0 ? 'You’re next' : `~${ticket.estimated_wait_minutes} min`;
          for (const [label, value, hook] of [['Your place in line', positionLabel, 'data-queue-position'], ['People ahead', aheadLabel, 'data-queue-ahead'], ['Estimated wait', waitLabel, 'data-queue-wait']]) {
            const metric = node('div', 'col-12 col-sm-4'), result = node('dd', 'fs-5 fw-bold mb-0', value);
            result.setAttribute(hook, ''); metric.append(node('dt', 'small fw-normal opacity-75', label), result); progress.append(metric);
          }
          card.append(progress);
          if (ticket.status === 'waiting') card.append(node('p', 'small opacity-75 mt-3 mb-0', 'Position includes people already called or being seen ahead of you. Wait estimates use recent consultations; urgent cases and staff availability may change the order and waiting time.'));
          else if (ticket.status === 'skipped') card.append(node('p', 'small opacity-75 mt-3 mb-0', 'Your ticket is on hold. Please speak to reception to rejoin the waiting line.'));
          fragment.append(card);
        }
        if (!payload.tickets.length) fragment.append(node('div', 'alert alert-light border mb-0', 'No active waiting room tickets today.'));
        tickets?.replaceChildren(fragment);
      } else {
        root.querySelectorAll('[data-queue-count]').forEach(el => {
          const value = payload.counts[el.dataset.queueCount];
          if (value !== undefined) el.textContent = el.dataset.queueFormat === 'plain' ? String(value) : value.toLocaleString('en-US');
        });
        root.querySelectorAll('[data-queue-trend]').forEach(el => el.classList.toggle('up', payload.counts[el.dataset.queueTrend] > 0));
        root.dataset.queueDate = payload.date;
      }
      rendered = signature;
    }
    function clearPrivateView() {
      if (personal) tickets?.replaceChildren();
      else root.querySelectorAll('[data-queue-count]').forEach(el => { el.textContent = '—'; });
      rendered = null;
    }
    async function refresh() {
      debounce = null;
      if (!visible() || request) return;
      dirty = false;
      const owner = new AbortController();
      request = owner;
      let deadline;
      const live = () => visible() && request === owner && !owner.signal.aborted;
      try {
        const payload = await Promise.race([
          (async () => {
            const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: owner.signal,
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!live()) throw new DOMException('Queue view closed', 'AbortError');
            if ([401, 403].includes(response.status)) {
              stopped = true; dirty = false; clearPrivateView();
              notice('Your session or access changed. Reload or sign in again.');
              throw new DOMException('Access changed', 'AbortError');
            }
            if (!response.ok || response.redirected) throw new Error('Queue refresh unavailable');
            const data = await response.json();
            if (!live()) throw new DOMException('Queue view closed', 'AbortError');
            window.SynapseCsrf?.fromResponse(response);
            return data;
          })(),
          new Promise((_, reject) => { deadline = setTimeout(() => { owner.abort(); reject(new Error('Queue refresh timed out')); }, 10000); })
        ]);
        if (live()) { render(payload); notice(''); }
      } catch (failure) {
        if (!stopped && !suspended && root.isConnected && !document.hidden && failure.name !== 'AbortError') notice('Queue updates are temporarily unavailable. The displayed status may be out of date.');
      } finally {
        clearTimeout(deadline);
        if (request === owner) request = null;
        if (dirty) invalidate(); else schedule();
      }
    }
    document.addEventListener('synapse:realtime-event', event => {
      const hint = event.detail;
      if (personal ? hint?.event === 'portal.queue.changed' : hint?.event === 'queue.invalidated' && hint.data?.unit === unit && validDate(hint.data.date)) invalidate();
    }, options);
    document.addEventListener('synapse:realtime-status', event => {
      const next = event.detail?.status === 'ready';
      if (next === ready) return;
      ready = next;
      if (ready) invalidate(); else schedule();
    }, options);
    function pause() {
      clearTimeout(poll); clearTimeout(debounce); poll = debounce = null;
      request?.abort();
    }
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) pause(); else { ready = window.SynapseRealtime?.status === 'ready'; invalidate(); }
    }, options);
    window.addEventListener('pagehide', () => { suspended = true; pause(); }, options);
    window.addEventListener('pageshow', () => { suspended = false; ready = window.SynapseRealtime?.status === 'ready'; invalidate(); }, options);
    document.addEventListener('synapse:before-swap', event => {
      if (!event.detail?.root?.contains(root)) return;
      stopped = true; pause(); events.abort(); delete root.dataset.queueLiveBound;
    }, options);
    invalidate();
  }
})();
