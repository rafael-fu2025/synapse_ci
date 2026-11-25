/** Dedicated availability workspace; mutation authority remains in the scheduling service. */
(function () {
  'use strict';
  if (window.SynapseAvailabilityWorkspace) return;
  let active = null;
  const committed = new WeakSet();
  function feedback(form, message) {
    const inlineHost = form.closest('td') ? form.closest('.appt-card') : null;
    const container = inlineHost || form;
    let box = container.querySelector('[data-availability-feedback]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'alert alert-danger';
      if (inlineHost) box.classList.add('mx-3', 'mt-3', 'mb-0');
      box.dataset.availabilityFeedback = '';
      box.setAttribute('role', 'alert');
      box.tabIndex = -1;
      const tableWrap = inlineHost?.querySelector('[data-configured-windows]') || inlineHost?.querySelector('.appt-table-wrap');
      if (tableWrap) tableWrap.before(box);
      else container.prepend(box);
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
      const action = new URL(form.getAttribute('action') || location.href, location.href);
      const paths = ['', '/remove', '/template', '/enroll', '/exceptions', '/exceptions/remove'].map(suffix => base.pathname + suffix);
      const method = (form.getAttribute('method') || 'GET').toUpperCase();
      if (method !== 'POST' || action.origin !== base.origin || !paths.includes(action.pathname) || action.search || action.hash) throw new Error('The action must stay in this department’s availability workspace.');
      const body = event.submitter
        ? new FormData(form, event.submitter)
        : new FormData(form);
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
      if (mutations) await mutations.refresh(redirect.href, payload.message, { form, descriptor: payload.refresh });
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
  function weeklyState(el) {
    let groups = [];
    try {
      const parsed = JSON.parse(el?.dataset?.weeklyGroups || '[]');
      if (Array.isArray(parsed)) {
        groups = parsed.map(item => ({
          id: String(item.id ?? ''),
          days: Array.isArray(item.days) ? item.days.map(Number) : []
        }));
      }
    } catch (_) {
      groups = [];
    }

    return {
      groups,
      dayFilter: 'all',
      page: 1,
      perPage: 10,
      openIds: groups.length === 1 ? [groups[0].id] : [],
      matchingGroupIds() {
        if (this.dayFilter === 'all') return this.groups.map(g => g.id);
        const day = Number(this.dayFilter);
        return this.groups.filter(g => g.days.includes(day)).map(g => g.id);
      },
      totalPages() {
        return Math.max(1, Math.ceil(this.matchingGroupIds().length / this.perPage));
      },
      visibleGroupIds() {
        const ids = this.matchingGroupIds();
        const total = Math.max(1, Math.ceil(ids.length / this.perPage));
        const current = Math.max(1, Math.min(this.page, total));
        const start = (current - 1) * this.perPage;
        return ids.slice(start, start + this.perPage);
      },
      isGroupVisible(id) {
        return this.visibleGroupIds().includes(String(id));
      },
      isDayVisible(day) {
        return this.dayFilter === 'all' || Number(this.dayFilter) === Number(day);
      },
      isOpen(id) {
        return this.openIds.includes(String(id));
      },
      toggle(id) {
        const key = String(id);
        const idx = this.openIds.indexOf(key);
        if (idx === -1) this.openIds.push(key);
        else this.openIds.splice(idx, 1);
      },
      setDay(day) {
        this.dayFilter = String(day);
        this.page = 1;
        if (this.dayFilter !== 'all') {
          this.openIds = [...this.visibleGroupIds()];
        } else {
          this.openIds = this.groups.length === 1 ? [this.groups[0].id] : [];
        }
      },
      allExpanded() {
        const visible = this.visibleGroupIds();
        return visible.length > 0 && visible.every(id => this.openIds.includes(id));
      },
      toggleAll() {
        if (this.allExpanded()) {
          this.openIds = [];
        } else {
          this.openIds = [...this.visibleGroupIds()];
        }
      },
      setPage(next) {
        const total = this.totalPages();
        this.page = Math.max(1, Math.min(Number(next) || 1, total));
        if (this.dayFilter !== 'all') {
          this.openIds = [...this.visibleGroupIds()];
        }
      },
      setPerPage(size) {
        this.perPage = Number(size) || 10;
        this.page = 1;
        if (this.dayFilter !== 'all') {
          this.openIds = [...this.visibleGroupIds()];
        }
      },
      pageNumbers() {
        const total = this.totalPages();
        if (total <= 7) return Array.from({ length: total }, (_, idx) => idx + 1);
        const current = Math.max(1, Math.min(this.page, total));
        let start = Math.max(1, current - 2);
        let end = Math.min(total, start + 4);
        if (end - start < 4) start = Math.max(1, end - 4);
        const pages = [];
        for (let i = start; i <= end; i++) pages.push(i);
        return pages;
      },
      rangeLabel() {
        const total = this.matchingGroupIds().length;
        if (total === 0) return '0 matching staff';
        const current = Math.max(1, Math.min(this.page, this.totalPages()));
        const from = (current - 1) * this.perPage + 1;
        const to = Math.min(total, current * this.perPage);
        return `Showing ${from}–${to} of ${total} staff`;
      }
    };
  }
  document.addEventListener('synapse:page-load', event => openSelected(event.detail?.root || document));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => openSelected());
  else openSelected();
  window.SynapseAvailabilityWorkspace = { openSelected, weeklyState };
})();
