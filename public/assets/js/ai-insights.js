/** Page-owned AI actions: fixed same-origin routes, scoped updates, and no mutation replay. */
(function () {
  'use strict';
  if (window.SynapseAiInsights) return;
  const instances = new Map();
  function endpoint(value, basePath, mutation) {
    const url = new URL(value, location.href);
    const suffix = url.pathname.slice(basePath.length);
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password || url.search || url.hash ||
        !/\/(?:clinic|guidance|facilities)\/insights$/.test(basePath) || !url.pathname.startsWith(basePath) ||
        !(mutation ? /^\/(?:prepare|generate|[1-9][0-9]*\/review|jobs\/[1-9][0-9]*\/cancel)$/.test(suffix) : suffix === '/panel')) {
      throw new Error('Invalid analysis endpoint.');
    }
    return url.href;
  }
  function mount(root) {
    const basePath = location.pathname.replace(/\/$/, '');
    const events = new AbortController();
    const feedback = root.querySelector('[data-ai-feedback]');
    const panel = root.querySelector('[data-ai-panel]');
    const baseline = root.querySelector('[data-ai-baseline]');
    const pathUnit = basePath.match(/\/(clinic|guidance|facilities)\/insights$/)?.[1];
    const unit = pathUnit === 'facilities' ? 'bmg' : pathUnit;
    let read, write, timer, debounce, stopped = false, suspended = false, writing = false, auth = false, uncertain = false, dirty = false;
    let ready = window.SynapseRealtime?.status === 'ready';
    const active = () => !stopped && !suspended && !auth && root.isConnected && !document.hidden;
    function say(message, type = 'info', notify = false) {
      feedback.textContent = message;
      feedback.className = `alert alert-${type === 'error' ? 'danger' : type} small mb-3`;
      feedback.hidden = false;
      // The shared toast owns announcements when available; retain a styled fallback, not loose text.
      if (notify && typeof window.SynapseToast?.show === 'function') {
        try {
          window.SynapseToast.show(message, { type, key: 'ai-insights' });
          feedback.hidden = true;
        } catch (_) { /* Keep the visible status if the notification service is unavailable. */ }
      }
    }
    function schedule() {
      clearTimeout(timer);
      if (!active() || writing) return;
      const pending = panel.querySelector('[data-ai-pending="true"]');
      timer = setTimeout(() => { void refresh(); }, pending ? (ready ? 15000 : 5000) : (ready ? 60000 : 30000));
    }
    function clearPrivate() {
      auth = true; dirty = false; clearTimeout(timer); clearTimeout(debounce); panel.replaceChildren(); baseline.replaceChildren();
    }
    async function request(url, owner, body) {
      const timeout = setTimeout(() => owner.abort(), 12000);
      try {
        const response = await fetch(endpoint(url, basePath, Boolean(body)), {
          method: body ? 'POST' : 'GET', body, signal: owner.signal, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? window.SynapseCsrf?.headers() : {}) }
        });
        if (owner.signal.aborted || stopped) throw new DOMException('Cancelled', 'AbortError');
        if (window.SynapseCsrf?.fromResponse(response)) { clearPrivate(); throw new Error('Sign-in navigation is in progress.'); }
        if (response.status === 401 || response.status === 403) {
          clearPrivate(); throw new Error(response.status === 403 ? 'Access or the security token changed. Refresh before continuing.' : 'Authentication is required.');
        }
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Analysis returned an unexpected response.');
        const text = await response.text();
        if (owner.signal.aborted || stopped) throw new DOMException('Cancelled', 'AbortError');
        if (text.length > 524288) throw new Error('Analysis response is too large.');
        const payload = JSON.parse(text);
        if (!payload || typeof payload.success !== 'boolean') throw new Error('Invalid analysis response.');
        window.SynapseCsrf?.sync(payload.csrf);
        if (!response.ok || !payload.success) {
          const error = new Error(typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'This action is temporarily unavailable.');
          error.confirmed = true;
          error.feedbackType = payload.reason === 'insufficient_data' || payload.feedback_type === 'warning' ? 'warning' : 'error';
          throw error;
        }
        return payload;
      } finally { clearTimeout(timeout); }
    }
    function replace(target, html) {
      if (typeof html !== 'string' || html.length > 524288) throw new Error('Invalid analysis fragment.');
      const template = document.createElement('template'); template.innerHTML = html;
      if (template.content.querySelector('script, iframe, object, embed')) throw new Error('Invalid analysis fragment.');
      target.replaceChildren(template.content);
    }
    async function refresh() {
      if (!active() || writing || read) return;
      dirty = false; clearTimeout(timer); clearTimeout(debounce); debounce = null; const owner = new AbortController(); read = owner;
      try {
        const payload = await request(root.dataset.panelUrl, owner);
        if (!owner.signal.aborted && active() && read === owner) replace(panel, payload.html);
      } catch (error) {
        if (!stopped && !writing && !owner.signal.aborted) say(error.message || 'Status refresh failed.');
      } finally {
        if (read === owner) { read = null; if (dirty && active()) invalidate(); else schedule(); }
      }
    }
    root.addEventListener('submit', async event => {
      const form = event.target.closest('form[data-ai-action]');
      if (!form || !root.contains(form)) return;
      event.preventDefault();
      if (writing || auth || stopped) return;
      writing = true; uncertain = false; clearTimeout(timer); read?.abort(); read = null;
      const buttons = [...root.querySelectorAll('button')].map(button => [button, button.disabled]);
      buttons.forEach(([button]) => { button.disabled = true; });
      const action = new URL(form.action, location.href).pathname.split('/').pop();
      const progress = { prepare: 'Checking the data and preparing your local summary…',
        generate: 'Checking the data and requesting an AI draft…', review: 'Saving your review…', cancel: 'Cancelling the queued draft…' };
      root.setAttribute('aria-busy', 'true'); say(progress[action] || 'Saving your action…');
      const owner = new AbortController(); write = owner;
      try {
        // URLSearchParams keeps the endpoint's explicit form contract; csrf.js owns refreshed values.
        const body = new URLSearchParams(new FormData(form));
        const payload = await request(form.action, owner, body);
        if (typeof payload.baseline === 'string') replace(baseline, payload.baseline);
        const message = typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'Your action was saved.';
        say(message, payload.reused || action === 'generate' ? 'info' : 'success', true);
      } catch (error) {
        uncertain = !error.confirmed;
        if (!stopped && !auth) {
          if (error.confirmed) say(error.message, error.feedbackType, true);
          else say('We could not confirm whether your action finished. Check Refresh status before trying again. No automatic retry was made.', 'warning', true);
        }
      } finally {
        write = null; writing = false; root.removeAttribute('aria-busy');
        buttons.forEach(([button, disabled]) => { if (button.isConnected) button.disabled = disabled; });
        if (!stopped && !auth) { await refresh(); if (uncertain) schedule(); }
      }
    }, { signal: events.signal });
    root.addEventListener('click', event => {
      if (event.target.closest('[data-ai-refresh]')) { event.preventDefault(); void refresh(); }
    }, { signal: events.signal });
    function invalidate() {
      if (stopped || auth) return;
      dirty = true;
      if (!active() || writing || read || debounce) return;
      debounce = setTimeout(() => { debounce = null; void refresh(); }, 250);
    }
    function pause() {
      clearTimeout(timer); clearTimeout(debounce); debounce = null;
      read?.abort(); read = null;
    }
    document.addEventListener('synapse:realtime-event', event => {
      if (event.detail?.event === 'ai.insights.changed' && event.detail.data?.unit === unit) invalidate();
    }, { signal: events.signal });
    document.addEventListener('synapse:realtime-status', event => {
      const next = event.detail?.status === 'ready';
      if (next === ready) return;
      ready = next; schedule();
      if (ready) invalidate();
    }, { signal: events.signal });
    document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); else void refresh(); }, { signal: events.signal });
    window.addEventListener('pagehide', () => { suspended = true; pause(); }, { signal: events.signal });
    window.addEventListener('pageshow', () => {
      if (!suspended) return;
      suspended = false; ready = window.SynapseRealtime?.status === 'ready'; void refresh();
    }, { signal: events.signal });
    schedule();
    return () => { stopped = true; pause(); write?.abort(); events.abort(); };
  }
  function init(scope = document) {
    for (const [root, destroy] of instances) if (!root.isConnected) { destroy(); instances.delete(root); }
    const roots = [...scope.querySelectorAll('[data-ai-workspace]')];
    if (scope.matches?.('[data-ai-workspace]')) roots.unshift(scope);
    for (const root of roots) if (!instances.has(root)) instances.set(root, mount(root));
  }
  window.SynapseAiInsights = { init };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => {
    const scope = event.detail?.root;
    for (const [root, destroy] of instances) if (!scope || scope === root || scope.contains(root)) { destroy(); instances.delete(root); }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();
