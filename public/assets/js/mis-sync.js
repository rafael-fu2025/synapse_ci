/** Progressive enhancement for the MIS reference synchronization form. */
(function () {
  'use strict';
  if (window.SynapseMisSync) return;
  let current = null;
  const locked = new WeakSet();

  function endpoint(value) {
    const url = new URL(value, window.location.href);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin
        || url.username || url.password || url.hash) throw new Error('Invalid synchronization endpoint');
    return url.href;
  }
  function init(scope = document) {
    const root = scope.matches?.('[data-mis-sync]') ? scope : scope.querySelector('[data-mis-sync]');
    if (!root || current?.root === root) return;
    destroy();
    const find = selector => root.querySelector(selector);
    const form = find('[data-mis-sync-form]');
    const button = form?.querySelector('button[type="submit"]');
    const panel = find('[data-sync-progress]');
    const bar = find('[data-sync-bar]');
    const percent = find('[data-sync-percent]');
    const message = find('[data-sync-message]');
    const result = find('[data-sync-result]');
    const refresh = find('[data-sync-refresh]');
    if (!form || !button || !panel || !bar || !percent || !message || !result || !refresh
        || !window.fetch || !window.crypto?.randomUUID || !window.FormData) return;
    let action, statusUrl;
    try { action = endpoint(form.action); statusUrl = endpoint(root.dataset.statusUrl); }
    catch (_) { return; }
    const owner = { root, dispose: null };
    current = owner;
    let pending = false;
    let run = null;
    let value = 0;
    let belowComplete = 0;
    let seen = false;
    let statusRead = null;
    const alive = () => current === owner && root.isConnected;
    const headers = () => ({ ...window.SynapseCsrf?.headers(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' });
    function motion(active) {
      bar.classList.toggle('progress-bar-striped', active);
      bar.classList.toggle('progress-bar-animated', active);
    }
    function progress(number) {
      value = number;
      bar.style.width = `${number}%`;
      bar.setAttribute('aria-valuenow', String(number));
      percent.textContent = `${number}%`;
    }
    function feedback(text) {
      result.textContent = text;
      result.hidden = false;
      message.textContent = text;
    }
    function realtime(event) {
      const detail = event.detail;
      const data = detail?.data;
      if (!alive() || !pending || detail?.event !== 'mis.sync.progress' || !data || data.progress_id !== run
          || typeof data.percent !== 'number' || !Number.isFinite(data.percent)
          || data.percent < 0 || data.percent > 100 || data.percent < value) return;
      seen = true;
      if (data.percent < 100) belowComplete = data.percent;
      progress(data.percent);
      motion(false);
      message.textContent = data.percent === 100
        ? 'Synchronization committed; awaiting confirmation from the server.'
        : typeof data.message === 'string' ? data.message : 'Synchronization is in progress.';
    }
    function connection(event) {
      if (!alive() || !pending) return;
      if (event.detail?.status !== 'ready') {
        message.textContent = 'Live progress is unavailable. Synchronization is still pending; waiting for the server response.';
      } else {
        message.textContent = value === 100 ? 'Synchronization committed; awaiting confirmation from the server.'
          : 'Synchronization is in progress; waiting for the server response.';
      }
    }
    function guard(event) {
      if (!pending) return;
      event.preventDefault();
      message.textContent = 'Synchronization is still pending. Wait for the server response before navigating.';
    }
    function unload(event) {
      if (!pending) return;
      event.preventDefault();
      event.returnValue = '';
    }
    async function json(response, url, valid = alive) {
      if (!valid()) return null;
      if (response.redirected || (response.url && response.url !== url)) throw new Error('Unexpected response');
      window.SynapseCsrf?.fromResponse(response);
      const payload = await response.json();
      if (!valid()) return null;
      if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('Invalid response');
      window.SynapseCsrf?.sync(payload.csrf);
      return payload;
    }
    async function refreshStatus() {
      const controller = new AbortController();
      statusRead = controller;
      const valid = () => alive() && !controller.signal.aborted;
      // Bound both headers and JSON parsing, even if a transport ignores abort.
      const cancelled = new Promise((_, reject) => {
        controller.signal.addEventListener('abort', () => reject(new Error('Status read cancelled')), { once: true });
      });
      const timer = setTimeout(() => controller.abort(), 10000);
      try {
        const { response, payload } = await Promise.race([
          (async () => {
            const response = await window.fetch(statusUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: headers(), signal: controller.signal });
            const payload = await json(response, statusUrl, valid);
            return { response, payload };
          })(),
          cancelled
        ]);
        if (!valid()) return;
        if (!response.ok || !payload?.catalogs || !Object.hasOwn(payload, 'lastSync')) throw new Error('Status unavailable');
        const catalogs = ['departments', 'programs', 'year_levels'];
        for (const catalog of catalogs) {
          const entry = payload.catalogs[catalog];
          if (!entry || !Number.isInteger(entry.total) || entry.total < 0
              || !(entry.last_synced_at === null || typeof entry.last_synced_at === 'string')) throw new Error('Invalid status');
        }
        if (payload.lastSync !== null && typeof payload.lastSync?.occurred_at !== 'string') throw new Error('Invalid status');
        for (const catalog of catalogs) {
          const row = find(`[data-sync-catalog="${catalog}"]`);
          const entry = payload.catalogs[catalog];
          if (row) {
            row.querySelector('[data-sync-total]').textContent = String(entry.total);
            row.querySelector('[data-sync-synced]').textContent = entry.last_synced_at ?? 'Never';
          }
        }
        find('[data-sync-last]').textContent = payload.lastSync?.occurred_at ?? 'None recorded';
        const actor = find('[data-sync-actor]');
        if (actor) actor.hidden = true;
      } catch (_) {
        if (!alive()) return;
        feedback('Synchronization succeeded, but local status could not be refreshed. Reload to view the latest status.');
        refresh.hidden = false;
      } finally {
        clearTimeout(timer);
        if (statusRead === controller) statusRead = null;
      }
    }
    async function submit(event) {
      event.preventDefault();
      if (pending || locked.has(root)) return;
      const body = new window.FormData(form);
      run = window.crypto.randomUUID();
      body.set('progress_id', run);
      pending = true;
      seen = false;
      value = belowComplete = 0;
      button.disabled = true;
      panel.hidden = false;
      result.hidden = refresh.hidden = true;
      bar.style.width = '100%';
      bar.removeAttribute('aria-valuenow');
      percent.textContent = 'Waiting…';
      motion(true);
      message.textContent = window.SynapseRealtime?.status === 'ready'
        ? 'Synchronization is in progress; waiting for live progress.'
        : 'Live progress is unavailable. Synchronization is still pending; waiting for the server response.';
      try {
        // Never abort or retry a write: losing its response does not roll it back.
        const response = await window.fetch(action, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: headers() });
        const payload = await json(response, action);
        if (!alive()) return;
        if (!response.ok || payload?.success === false) {
          if (!seen || value === 100) progress(belowComplete);
          feedback(typeof payload?.message === 'string' && payload.message ? payload.message : 'Synchronization failed. Review the status before trying again.');
        } else {
          if (payload?.success !== true) throw new Error('Unconfirmed outcome');
          progress(100);
          feedback('MIS reference catalogs synchronized successfully.');
          await refreshStatus();
        }
      } catch (_) {
        if (!alive()) return;
        locked.add(root);
        if (!seen || value === 100) progress(belowComplete);
        feedback('Synchronization outcome is unconfirmed. Reload to check local status before retrying; this request will not be resubmitted automatically.');
        refresh.hidden = false;
      } finally {
        if (alive()) {
          pending = false;
          run = null;
          motion(false);
          button.disabled = locked.has(root);
        }
      }
    }
    button.disabled = locked.has(root);
    form.addEventListener('submit', submit);
    document.addEventListener('synapse:realtime-event', realtime);
    document.addEventListener('synapse:realtime-status', connection);
    document.addEventListener('synapse:before-navigate', guard);
    window.addEventListener('beforeunload', unload);
    owner.dispose = () => {
      if (pending) locked.add(root);
      statusRead?.abort();
      form.removeEventListener('submit', submit);
      document.removeEventListener('synapse:realtime-event', realtime);
      document.removeEventListener('synapse:realtime-status', connection);
      document.removeEventListener('synapse:before-navigate', guard);
      window.removeEventListener('beforeunload', unload);
    };
  }
  function destroy(scope) {
    if (!current || (scope && scope !== current.root && !scope.contains?.(current.root))) return;
    current.dispose();
    current = null;
  }
  window.SynapseMisSync = Object.freeze({ init, destroy });
  document.addEventListener('synapse:page-load', event => init(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
  else init();
})();
