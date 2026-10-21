/** Real modal forms over the current screen; only X/Cancel dismiss unsaved work. */
(function () {
  'use strict';
  let active = null;

  // This includes the loading shell and in-flight writes. A write may commit
  // server-side even if its fetch is aborted, so only X/Cancel can leave here.
  document.addEventListener('synapse:before-navigate', event => {
    if (active) event.preventDefault();
  });

  function initialize(root) {
    // Direct-route forms were initialized by Alpine's document startup already.
    // Reinitializing x-for templates registers duplicate destructors in Alpine.
    if (window.Alpine && root.dataset.modalDirect !== 'true') Alpine.mutateDom(() => Alpine.initTree(root));
    window.BalaySelect?.init(root);
    window.BalayDatepicker?.init(root);
    window.BalayDateTimePicker?.init(root);
    window.SynapseCarouselForm?.init(root);
    window.SynapseProfileSettings?.init(root);
  }
  function focusField(root) {
    const field = root.querySelector('[data-profile-settings] input[type="file"]') || root.querySelector('.modal-body input:not([type="hidden"]):not([type="file"]):not(:disabled), .modal-body textarea, .modal-body .bd-select-toggle');
    (field || root.querySelector('[data-app-modal-close]'))?.focus({ preventScroll: true });
  }
  function error(root, message) {
    const box = root.querySelector('[data-modal-error]');
    box.textContent = message;
    box.classList.remove('d-none');
  }
  function busy(root) { return Boolean(root.querySelector('form[aria-busy="true"]')); }
  function updateCsrf(csrf) {
    if (!csrf) return;
    if (window.SynapseCsrf) { window.SynapseCsrf.sync(csrf); return; }
    document.querySelectorAll('input[type="hidden"]').forEach(input => {
      if (input.name === csrf.name) input.value = csrf.hash;
    });
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.content = csrf.hash;
  }
  function attach(root, opener) {
    const record = { root, opener, controller: new AbortController() };
    active = record;
    const modal = bootstrap.Modal.getOrCreateInstance(root, { backdrop: 'static', keyboard: false });
    record.modal = modal;
    root.addEventListener('keydown', event => {
      if (event.key !== 'Tab') return;
      const fields = [...root.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')]
        .filter(field => field.tabIndex >= 0 && !field.matches(':disabled') && field.getClientRects().length &&
          getComputedStyle(field).visibility !== 'hidden');
      if (!fields.length) { event.preventDefault(); root.focus(); return; }
      const first = fields[0], last = fields[fields.length - 1];
      // Trap the boundary explicitly: tabbing after a body-end dialog can move
      // focus into browser chrome without firing Bootstrap's focusin handler.
      if ((event.shiftKey && document.activeElement === first) ||
          (!event.shiftKey && document.activeElement === last) || document.activeElement === root) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
    });
    root.addEventListener('shown.bs.modal', () => {
      if (record.closeRequested) modal.hide(); else focusField(root);
    });
    root.addEventListener('hide.bs.modal', event => {
      // Do not imply that an in-flight write was cancelled when it may commit.
      if (busy(root)) event.preventDefault();
    });
    root.addEventListener('hidden.bs.modal', () => {
      record.controller.abort();
      window.SynapseCarouselForm?.destroy(root);
      window.SynapseProfileSettings?.destroy(root);
      window.BalaySelect?.destroy(root);
      if (window.Alpine) Alpine.mutateDom(() => Alpine.destroyTree(root));
      modal.dispose();
      root.remove(); // Datepicker's lifecycle observer disposes its removed fields.
      if (active === record) active = null;
      if (root.dataset.modalDirect === 'true') location.assign(root.dataset.returnUrl);
      else if (opener?.isConnected) {
        const target = opener.getClientRects().length ? opener : opener.closest('.user-dropdown')?.querySelector('.user-trigger');
        target?.focus({ preventScroll: true });
      }
    }, { once: true });
    initialize(root);
    modal.show(opener);
    return record;
  }
  function loadingRoot() {
    const root = document.createElement('div');
    root.className = 'modal fade';
    root.id = 'appFormModal';
    root.tabIndex = -1;
    root.setAttribute('aria-labelledby', 'appFormModalTitle');
    root.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">' +
      '<div class="modal-header"><h5 id="appFormModalTitle" class="modal-title fs-6 fw-bold">Loading form…</h5>' +
      '<button type="button" class="btn-close" aria-label="Close" data-app-modal-close></button></div>' +
      '<div class="modal-body"><div class="alert alert-danger d-none" role="alert" data-modal-error></div>' +
      '<div data-modal-loading><p role="status"><span class="app-loading-spinner" aria-hidden="true"></span>Loading form…</p>' +
      '<div class="app-loading-skeleton mb-3" aria-hidden="true"><span></span><span></span><span></span><span></span></div></div>' +
      '<button type="button" class="btn btn-outline-secondary" data-app-modal-close>Cancel</button></div></div></div>';
    document.body.appendChild(root);
    return root;
  }
  async function open(link) {
    if (active || document.body.classList.contains('is-navigating')) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin) { location.assign(url); return; }
    url.searchParams.set('fragment', '1');
    const record = attach(loadingRoot(), link);
    try {
      const response = await fetch(url, { credentials: 'same-origin', signal: record.controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      window.SynapseCsrf?.fromResponse(response);
      if (!response.ok || response.redirected) throw new Error('Unable to open the form. Check your access or sign in again.');
      const html = await response.text();
      if (active !== record || record.controller.signal.aborted) return;
      const source = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-app-form-modal]');
      if (!source?.querySelector('.modal-content .modal-body')) throw new Error('The form response could not be loaded.');
      const target = record.root.querySelector('.modal-content');
      const insert = () => {
        target.innerHTML = source.querySelector('.modal-content').innerHTML;
        // Components are globally registered. Never execute scripts fetched as HTML.
        target.querySelectorAll('script').forEach(script => script.remove());
        if (window.Alpine) Alpine.initTree(target);
      };
      if (window.Alpine) Alpine.mutateDom(insert); else insert();
      record.root.dataset.returnUrl = source.dataset.returnUrl;
      const dialog = record.root.querySelector('.modal-dialog');
      for (const size of ['modal-lg', 'modal-xl']) dialog.classList.toggle(size, source.querySelector('.modal-dialog').classList.contains(size));
      window.BalaySelect?.init(record.root);
      window.BalayDatepicker?.init(record.root);
      window.BalayDateTimePicker?.init(record.root);
      window.SynapseCarouselForm?.init(record.root);
      window.SynapseProfileSettings?.init(record.root);
      if (record.root.classList.contains('show')) focusField(record.root);
    } catch (failure) {
      if (failure.name !== 'AbortError' && active === record) {
        record.root.querySelector('[data-modal-loading]')?.remove();
        error(record.root, failure.message);
      }
    }
  }

  // Read-only detail dialogs may explicitly link to a different modal route.
  // Ordinary navigation remains blocked, especially during an in-flight write.
  document.addEventListener('click', event => {
    const route = event.target.closest('a[data-app-modal-route]');
    if (route && active?.root.contains(route) && event.button === 0 &&
        !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
      event.preventDefault();
      const url = new URL(route.href, location.href);
      if (!busy(active.root) && url.origin === location.origin) location.assign(url.href);
      return;
    }
    const close = event.target.closest('[data-app-modal-close]');
    if (close && active?.root.contains(close)) {
      event.preventDefault();
      if (!busy(active.root)) {
        active.closeRequested = true;
        active.modal.hide();
      }
      return;
    }
    const link = event.target.closest('a[data-app-modal-open]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    open(link);
  });

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!active || !form.matches('form[data-modal-submit]') || !active.root.contains(form)) return;
    event.preventDefault();
    const record = active;
    if (busy(record.root)) return;
    const data = new FormData(form);
    if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
    const buttons = [...form.querySelectorAll('[type="submit"]')];
    const disabled = buttons.map(button => button.disabled);
    let saved = false;
    const finish = window.SynapseLoading?.begin(form, { label: 'Saving…', button: event.submitter }) || (() => {});
    form.setAttribute('aria-busy', 'true');
    buttons.forEach(button => { button.disabled = true; });
    record.root.querySelector('[data-modal-error]').classList.add('d-none');
    try {
      const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers() } });
      window.SynapseCsrf?.fromResponse(response);
      const payload = await response.json();
      updateCsrf(payload.csrf);
      if (response.ok && payload.success === true) {
        saved = true;
        location.assign(payload.redirect);
      } else {
        error(record.root, payload.message || 'The form could not be saved.');
      }
    } catch (_) {
      error(record.root, 'The save response could not be received. Check your connection or sign-in session.');
    } finally {
      if (!saved) {
        finish();
        form.removeAttribute('aria-busy');
        buttons.forEach((button, i) => { button.disabled = disabled[i]; });
      }
    }
  });

  // A secondary confirmation removes Bootstrap's document focus trap on hide.
  // Keep the still-open form contained without depending on Bootstrap internals.
  document.addEventListener('focusin', event => {
    if (!active?.root.classList.contains('show') || active.root.inert || active.root.contains(event.target)) return;
    if ([...document.querySelectorAll('.modal.show')].some(root => root !== active.root)) return;
    active.root.focus({ preventScroll: true });
  });

  function start(event) {
    const scope = event?.detail?.root || document;
    const direct = scope.querySelector('[data-app-form-modal][data-modal-direct]');
    if (direct && !active) attach(direct, null);
  }
  document.addEventListener('synapse:page-load', start);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
