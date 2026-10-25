/** Explicit small mutations: one POST, then an authoritative GET refresh. */
(function () {
  'use strict';
  if (window.SynapseBackgroundActions) return;
  window.SynapseBackgroundActions = true;
  let pending = null;
  const saved = new WeakSet();
  const tell = (message, type = 'error') => window.SynapseToast?.show(message, { type, key: 'background-action' });

  document.addEventListener('synapse:before-navigate', event => {
    if (pending) event.preventDefault();
  });
  window.addEventListener('beforeunload', event => {
    if (!pending) return;
    event.preventDefault();
    event.returnValue = '';
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-background-submit]') || event.defaultPrevented) return;
    if (!window.fetch || !window.SynapseMutations || !window.SynapseToast) return;
    event.preventDefault();
    if (pending || saved.has(form) || document.body.classList.contains('is-navigating')) return;

    const submitter = event.submitter;
    const method = submitter?.getAttribute('formmethod') || form.method;
    const target = submitter?.getAttribute('formtarget') || form.target;
    let url;
    try { url = new URL(submitter?.getAttribute('formaction') || form.action, location.href); } catch (_) { return; }
    if (method.toUpperCase() !== 'POST' || (target && target !== '_self') || url.origin !== location.origin || url.username || url.password) {
      tell('This action must be submitted to a valid page on this site.');
      return;
    }
    const body = submitter ? new FormData(form, submitter) : new FormData(form);
    const controls = [...form.querySelectorAll('button, input, select, textarea')].map(control => [control, control.disabled]);
    const main = document.getElementById('main');
    const wasInert = main?.inert;
    const job = { controller: new AbortController(), timedOut: false };
    pending = job;
    const timeout = setTimeout(() => { job.timedOut = true; job.controller.abort(); }, 45000);
    const finish = window.SynapseLoading?.begin(form, { label: 'Saving…', button: submitter }) || (() => {});
    form.setAttribute('aria-busy', 'true');
    controls.forEach(([control]) => { control.disabled = true; });
    if (main) main.inert = true;
    let confirmed = false;
    function unlock() {
      finish();
      form.removeAttribute('aria-busy');
      controls.forEach(([control, disabled]) => { control.disabled = disabled; });
      if (main) main.inert = wasInert;
      if (pending === job) pending = null;
    }
    try {
      const response = await fetch(url.href, {
        method: 'POST', body, credentials: 'same-origin', redirect: 'error', signal: job.controller.signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          'X-Synapse-Feedback': 'toast', ...window.SynapseCsrf?.headers() }
      });
      window.SynapseCsrf?.fromResponse(response);
      if (!(response.headers.get('Content-Type') || '').toLowerCase().includes('application/json')) {
        throw new Error('The save response could not be verified. Check your session and the record before retrying.');
      }
      const payload = await response.json();
      window.SynapseCsrf?.sync(payload.csrf);
      if (!response.ok || payload.success !== true) {
        throw new Error(typeof payload.message === 'string' ? payload.message : 'The change could not be saved. Review the record and try again.');
      }
      confirmed = true;
      saved.add(form);
      unlock();
      // Row actions stay in the current folder/date/filter/page. Never replay a POST
      // if the following read fails: the shared helper offers only a GET retry.
      await window.SynapseMutations.refresh(location.href, payload.message, { form });
    } catch (error) {
      tell(confirmed ? 'Saved, but refreshing the page failed. Do not submit again; reload to review the result.'
        : job.timedOut ? 'The request timed out; its outcome is unknown. Review the record before retrying.'
          : error instanceof TypeError || error.name === 'AbortError'
            ? 'The response was interrupted; the change may have been saved. Review the record before retrying.' : error.message);
    } finally {
      clearTimeout(timeout);
      if (!confirmed) unlock();
    }
  });
})();
