/** Shared confirmation UI. Native validation, submitters and CSRF stay form-owned. */
(function () {
  'use strict';

  const pendingForms = new WeakSet();
  const permits = new WeakMap();
  const tones = {
    danger: ['btn-danger', 'bi-exclamation-triangle-fill text-danger'],
    warning: ['btn-warning', 'bi-exclamation-triangle-fill text-warning'],
    primary: ['btn-primary', 'bi-question-circle-fill text-primary']
  };
  let element, modal, parts;
  let phase = 'idle'; // idle -> opening -> open -> closing -> idle
  let current = null;
  let next = null;
  let suspended = [];
  let bodyStyles = null;

  function paint(request) {
    const options = request.options;
    const tone = tones[options.tone] || tones.danger;
    parts.title.textContent = options.title || 'Please confirm';
    parts.message.textContent = options.message || 'Are you sure?';
    parts.accept.textContent = options.confirmLabel || 'Yes, continue';
    parts.cancel.textContent = options.cancelLabel || 'Cancel';
    parts.accept.className = 'btn ' + tone[0];
    parts.accept.disabled = false;
    parts.icons.forEach(icon => {
      icon.className = 'bi ' + tone[1] + (icon.hasAttribute('data-confirm-icon-body') ? ' fs-1 opacity-75' : ' me-2 fs-5');
    });
  }

  function decide(approved) {
    if (!current || current.result !== null) return;
    current.result = approved;
    parts.accept.disabled = true;
    // Bootstrap ignores hide() during opening. shown.bs.modal finishes it.
    if (phase === 'open') modal.hide();
  }

  function build() {
    if (modal) return true;
    element = document.getElementById('synapseConfirmModal');
    if (!element || !window.bootstrap?.Modal) return false;
    parts = {
      title: element.querySelector('[data-confirm-title-text]'),
      message: element.querySelector('[data-confirm-message]'),
      icons: element.querySelectorAll('[data-confirm-icon]'),
      accept: element.querySelector('[data-confirm-accept]'),
      cancel: element.querySelector('[data-confirm-cancel]')
    };
    if (!parts.title || !parts.message || !parts.accept || !parts.cancel) return false;
    modal = bootstrap.Modal.getOrCreateInstance(element, { backdrop: true, keyboard: true });
    element.setAttribute('data-synapse-confirm-ready', '');
    parts.accept.addEventListener('click', () => decide(true));
    parts.cancel.addEventListener('click', () => decide(false));
    element.querySelector('.btn-close')?.addEventListener('click', () => decide(false));
    element.addEventListener('keydown', event => {
      if (event.key === 'Escape' && phase === 'opening') decide(false);
    });
    element.addEventListener('shown.bs.modal', () => {
      phase = 'open';
      if (current?.result !== null) modal.hide();
      else parts.cancel.focus();
    });
    element.addEventListener('hide.bs.modal', () => {
      phase = 'closing';
      if (current && current.result === null) current.result = false;
    });
    element.addEventListener('hidden.bs.modal', () => {
      const finished = current;
      current = null;
      phase = 'idle';
      parts.accept.disabled = false;
      suspended.forEach(({ root, inert }) => { if (root.isConnected) root.inert = inert; });
      suspended = [];
      if (document.querySelector('.modal.show')) {
        document.body.classList.add('modal-open');
        if (bodyStyles) {
          document.body.style.overflow = bodyStyles.overflow;
          document.body.style.paddingRight = bodyStyles.paddingRight;
        }
      }
      bodyStyles = null;
      // Resolve only after the backdrop and focus trap have been removed.
      if (finished) {
        if (!next && finished.opener?.isConnected) finished.opener.focus({ preventScroll: true });
        finished.resolve(finished.result === true);
      }
      if (next) {
        const request = next;
        next = null;
        present(request);
      }
    });
    return true;
  }

  function present(request) {
    current = request;
    paint(request);
    phase = 'opening';
    const underlying = [...document.querySelectorAll('.modal.show')].filter(root => root !== element);
    bodyStyles = underlying.length ? { overflow: document.body.style.overflow, paddingRight: document.body.style.paddingRight } : null;
    suspended = underlying.map(root => ({ root, inert: root.inert }));
    suspended.forEach(({ root }) => { root.inert = true; });
    const base = Math.max(1055, ...underlying.map(root => Number.parseInt(getComputedStyle(root).zIndex, 10) || 1055));
    element.style.zIndex = String(base + 20);
    modal.show(request.opener);
    // Bootstrap does not assign stacking levels for nested dialogs.
    const backdrop = [...document.querySelectorAll('.modal-backdrop')].at(-1);
    if (underlying.length && backdrop) backdrop.style.zIndex = String(base + 10);
  }

  function ask(options = {}) {
    if (!build()) return Promise.resolve(window.confirm(options.message || 'Are you sure?'));
    return new Promise(resolve => {
      const request = { options, resolve, result: null, opener: document.activeElement };
      if (phase === 'closing' || (current && current.result !== null)) {
        // Latest queued question wins; an already decided question must finish closing.
        if (next) next.resolve(false);
        next = request;
      } else if (current) {
        // Replace an unanswered question without restarting Bootstrap's transition.
        current.resolve(false);
        current = request;
        paint(request);
        if (phase === 'open') parts.cancel.focus();
      } else {
        present(request);
      }
    });
  }

  function submitApproved(form, submitter) {
    // Defer native-confirm retries until the original submit dispatch has ended.
    setTimeout(() => {
      try {
        if (!form.isConnected || (submitter && (submitter.form !== form || submitter.matches(':disabled')))) return;
        permits.set(form, submitter);
        // Preserve name/value, formaction, formmethod and native constraint validation.
        if (submitter) HTMLFormElement.prototype.requestSubmit.call(form, submitter);
        else HTMLFormElement.prototype.requestSubmit.call(form);
      } finally {
        // Validation may prevent a submit event. Consent MUST NOT survive that case.
        permits.delete(form);
        pendingForms.delete(form);
      }
    }, 0);
  }

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
    const submitter = event.submitter || null;
    if (permits.has(form) && permits.get(form) === submitter) {
      permits.delete(form);
      return;
    }
    // Capture before form/HTMX/Alpine handlers: no asynchronous action before consent.
    event.preventDefault();
    event.stopImmediatePropagation();
    if (pendingForms.has(form)) return;
    pendingForms.add(form);
    ask({
      message: form.getAttribute('data-confirm'),
      title: form.getAttribute('data-confirm-title'),
      confirmLabel: form.getAttribute('data-confirm-label'),
      tone: form.getAttribute('data-confirm-tone')
    }).then(approved => {
      if (approved) submitApproved(form, submitter);
      else pendingForms.delete(form);
    }).catch(() => {
      // Fail closed without permanently locking the form if the UI fails.
      pendingForms.delete(form);
    });
  }, true);

  window.SynapseConfirm = { confirm: ask };
})();
