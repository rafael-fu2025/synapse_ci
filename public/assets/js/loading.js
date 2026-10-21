/** Explicit request-scoped loading; never patches fetch or invents progress. */
(function () {
  'use strict';
  const active = new Map();

  function begin(target, options = {}) {
    if (!target) return () => {};
    if (active.has(target)) active.get(target)();
    const previousBusy = target.getAttribute('aria-busy');
    const label = options.label || 'Loading…';
    const indicator = document.createElement('div');
    indicator.className = options.skeleton ? 'app-loading-overlay' : 'app-loading-status';
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');
    const status = document.createElement('span');
    const spinner = document.createElement('span');
    spinner.className = 'app-loading-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    const buttonOnly = !options.skeleton && options.button?.tagName === 'BUTTON';
    if (buttonOnly) {
      // The button already shows a spinner; announce status without a second
      // visible indicator or extra space below the form.
      indicator.classList.add('visually-hidden');
      status.textContent = label;
    } else {
      status.append(spinner, document.createTextNode(label));
    }
    indicator.append(status);
    if (options.skeleton) {
      const skeleton = document.createElement('div');
      skeleton.className = 'app-loading-skeleton';
      skeleton.setAttribute('aria-hidden', 'true');
      for (let i = 0; i < 6; i++) skeleton.append(document.createElement('span'));
      indicator.append(skeleton);
    }
    target.classList.add('app-loading-region');
    target.setAttribute('aria-busy', 'true');
    target.append(indicator);

    const button = options.button;
    const disabled = button?.disabled;
    const buttonBusy = button?.getAttribute('aria-busy');
    const buttonSpinner = document.createElement('span');
    buttonSpinner.className = 'app-loading-spinner';
    buttonSpinner.setAttribute('aria-hidden', 'true');
    // Keep labels, icons, event handlers and submitter name/value intact.
    if (button) {
      button.setAttribute('aria-busy', 'true');
      button.classList.add('app-loading-button');
      if (button.tagName === 'BUTTON') button.prepend(buttonSpinner);
      if (options.disableButton !== false) button.disabled = true;
    }
    let finished = false;
    function finish() {
      if (finished) return;
      finished = true;
      indicator.remove();
      target.classList.remove('app-loading-region');
      if (previousBusy === null) target.removeAttribute('aria-busy');
      else target.setAttribute('aria-busy', previousBusy);
      if (button) {
        buttonSpinner.remove();
        button.classList.remove('app-loading-button');
        if (buttonBusy === null) button.removeAttribute('aria-busy');
        else button.setAttribute('aria-busy', buttonBusy);
        if (options.disableButton !== false) button.disabled = disabled;
      }
      if (active.get(target) === finish) active.delete(target);
    }
    active.set(target, finish);
    return finish;
  }
  function clear(root) {
    for (const [target, finish] of active) {
      if (!root || root === target || root.contains(target)) finish();
    }
  }
  // HTMX owns queue actions and date-filter requests. Quiet polling does not
  // obscure the board every 15 seconds; only user-triggered controls show work.
  const htmxRequests = new WeakMap();
  document.addEventListener('htmx:beforeSend', event => {
    const { elt, target, xhr } = event.detail || {};
    if (event.defaultPrevented || !xhr || !elt?.matches('button, input, select, form') || elt.dataset.loading === 'false') return;
    htmxRequests.set(xhr, begin(elt.closest('form') || target || elt.parentElement, {
      label: 'Updating…', button: elt.matches('button, input[type="submit"]') ? elt : null
    }));
  });
  document.addEventListener('htmx:afterRequest', event => {
    const xhr = event.detail?.xhr;
    if (!xhr) return;
    htmxRequests.get(xhr)?.();
    htmxRequests.delete(xhr);
  });
  document.addEventListener('synapse:before-swap', event => clear(event.detail?.root));
  window.addEventListener('pageshow', () => clear());
  window.SynapseLoading = { begin, clear };
})();
