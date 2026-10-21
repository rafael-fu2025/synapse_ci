/** Native submissions only; AJAX handlers own their loading and completion. */
(function () {
  'use strict';

  const pending = new Map();

  function navigatesHere(form, submitter) {
    const target = (submitter?.getAttribute('formtarget') ?? form.getAttribute('target') ??
      document.querySelector('base[target]')?.getAttribute('target') ?? '').trim();
    if (!target || target.toLowerCase() === '_self') return true;
    if (target.toLowerCase() === '_parent') return window.parent === window;
    if (target.toLowerCase() === '_top') return window.top === window;
    return target !== '_blank' && target === window.name;
  }

  document.addEventListener('submit', event => {
    const form = event.target;
    const submitter = event.submitter;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented ||
        form.dataset.loading === 'false' || submitter?.dataset.loading === 'false') return;
    const method = submitter?.getAttribute('formmethod') ?? form.method;
    if (method.toLowerCase() === 'dialog' || !navigatesHere(form, submitter)) return;

    if (pending.has(form)) {
      event.preventDefault();
      return;
    }

    // A microtask can run between browser-dispatched event listeners. Wait for
    // the entire submit dispatch so later AJAX/confirmation owners can claim it.
    setTimeout(() => {
      if (event.defaultPrevented || pending.has(form) || !form.isConnected || !window.SynapseLoading) return;
      // Native serialization still needs the enabled submitter's name/value.
      const finish = window.SynapseLoading.begin(form, {
        label: 'Submitting…', button: submitter, disableButton: false
      });
      pending.set(form, finish);
    }, 0);
  });

  // Restore the original controls when returning through the back/forward cache.
  window.addEventListener('pageshow', () => {
    pending.forEach(finish => finish());
    pending.clear();
  });
})();
