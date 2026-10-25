/** Dedicated availability workspace; mutation authority remains in the scheduling service. */
(function () {
  'use strict';
  if (window.SynapseAvailabilityWorkspace) return;
  let active = null;
  const committed = new WeakSet();
  function feedback(form, message) {
    let box = form.querySelector('[data-availability-feedback]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'alert alert-danger';
      box.dataset.availabilityFeedback = '';
      box.setAttribute('role', 'alert');
      box.tabIndex = -1;
      form.prepend(box);
    }
    box.textContent = message;
    box.hidden = !message;
    if (message) box.focus({ preventScroll: true });
  }
  function workspaceUrl(root) {
    const url = new URL(root.dataset.workspaceUrl, location.href);
    if (url.origin !== location.origin || url.search || url.hash) throw new Error('Invalid availability workspace. Refresh the page.');
    return url;
  }
  function openSelected(root = document) {
    if (location.hash !== '#availability-individual-modal') return;
    const modal = root.querySelector('#availability-individual-modal');
    if (modal && !modal.classList.contains('show')) window.bootstrap?.Modal.getOrCreateInstance(modal).show();
  }
  document.addEventListener('click', event => {
    const link = event.target.closest?.('[data-availability-edit-link]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const url = new URL(link.href, location.href);
    if (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search) {
      event.preventDefault();
      const modal = document.getElementById('availability-individual-modal');
      if (modal) window.bootstrap?.Modal.getOrCreateInstance(modal).show();
    }
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    const root = form.closest?.('[data-availability-workspace]');
    if (!root || !form.matches('form[data-availability-save]') || event.defaultPrevented || !window.fetch) return;
    event.preventDefault();
    if (active || committed.has(form)) return;
    const mutations = window.SynapseMutations;

    feedback(form, '');
    const job = { form, root, controller: new AbortController(), disposed: false, timedOut: false };
    active = job;
    const controls = Array.from(root.querySelectorAll('input, select, textarea, button'))
      .map(button => [button, button.disabled]);
    let finish = () => {};
    let saved = false;
    const timeout = setTimeout(() => { job.timedOut = true; job.controller.abort(); }, 90000);
    try {
      const base = workspaceUrl(root);
      const action = new URL(form.action, location.href);
      const paths = ['', '/template', '/enroll', '/exceptions', '/exceptions/remove'].map(suffix => base.pathname + suffix);
      if (form.method.toUpperCase() !== 'POST' || action.origin !== base.origin || !paths.includes(action.pathname) || action.search || action.hash) throw new Error('The action must stay in this department’s availability workspace.');
      const body = event.submitter ? new FormData(form, event.submitter) : new FormData(form);
      finish = window.SynapseLoading?.begin(form, { label: 'Saving availability…', button: event.submitter, disableButton: false }) || finish;
      controls.forEach(([button]) => { button.disabled = true; });
      const response = await fetch(action.href, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: job.controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers(), ...(mutations ? { 'X-Synapse-Feedback': 'toast' } : {}) } });
      window.SynapseCsrf?.fromResponse(response);
      if (!response.headers.get('Content-Type')?.toLowerCase().includes('application/json')) throw new Error('Your session may have expired. Refresh and sign in again before retrying.');
      const payload = await response.json();
      window.SynapseCsrf?.sync(payload.csrf);
      if (!response.ok || payload.success !== true) throw new Error(typeof payload.message === 'string' ? payload.message.slice(0, 1500) : 'Unable to save this change. Review the form and try again.');
      saved = true;
      committed.add(form);
      clearTimeout(timeout);
      if (typeof payload.redirect !== 'string' || !payload.redirect) throw new Error('The change was saved, but the return address was missing. Do not resubmit; refresh this workspace.');
      const redirect = new URL(payload.redirect, location.href);
      if (redirect.origin !== base.origin || redirect.pathname !== base.pathname || redirect.hash) throw new Error('The change was saved, but the return address was invalid. Refresh this workspace before making another change.');
      finish();
      finish = () => {};
      controls.forEach(([button, disabled]) => { button.disabled = form.contains(button) ? true : disabled; });
      form.removeAttribute('aria-busy');
      if (active === job) active = null;
      const modal = form.closest('.modal');
      if (modal && (modal.classList.contains('show') || modal.getAttribute('aria-modal') === 'true')) {
        const hidden = new Promise(resolve => modal.addEventListener('hidden.bs.modal', resolve, { once: true }));
        const instance = bootstrap.Modal.getInstance(modal);
        const hide = () => instance.hide();
        // If still opening, Bootstrap will ignore the first hide.
        modal.addEventListener('shown.bs.modal', hide, { once: true });
        hide();
        await hidden;
        modal.removeEventListener('shown.bs.modal', hide);
      }
      if (mutations) await mutations.refresh(redirect.href, payload.message, { form });
      else location.assign(redirect.href);
    } catch (error) {
      if (!job.disposed) feedback(form, job.timedOut ? 'The request timed out; its outcome is unknown. Refresh and review the schedule before retrying.'
        : error.name === 'AbortError' ? 'The request was interrupted. Refresh and review the schedule before retrying.'
          : error instanceof TypeError ? 'Connection failed; the outcome may be unknown. Refresh and review the schedule before retrying.' : error.message);
    } finally {
      clearTimeout(timeout);
      finish();
      controls.forEach(([button, disabled]) => { button.disabled = saved && form.contains(button) ? true : disabled; });
      if (active === job) active = null;
    }
  });
  document.addEventListener('hide.bs.modal', event => {
    if (active?.form.closest('.modal') === event.target && !active.disposed) event.preventDefault();
  });
  function dispose(root) {
    if (active && (!root || root.contains(active.form))) {
      active.disposed = true;
      active.controller.abort();
      active = null;
    }
  }
  document.addEventListener('synapse:before-swap', event => dispose(event.detail?.root));
  window.addEventListener('pagehide', () => dispose());
  document.addEventListener('synapse:page-load', event => openSelected(event.detail?.root || document));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => openSelected());
  else openSelected();
  window.SynapseAvailabilityWorkspace = { openSelected };
})();
