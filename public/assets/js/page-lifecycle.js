/** Page-owned plugins only; load once after core/main.js and before initial page-load. */
(function () {
  'use strict';
  if (window.SynapsePageLifecycle) return;
  const scopes = new WeakMap();
  const editors = new WeakMap();
  const editorSelector = '.quill-editor-default, .quill-editor-bubble, .quill-editor-full';

  function each(root, selector, callback) {
    if (root.matches?.(selector)) callback(root);
    root.querySelectorAll(selector).forEach(callback);
  }
  function quillFor(element) {
    return editors.get(element) || element.__quill || window.Quill?.find?.(element);
  }
  function init(root) {
    if (!root || scopes.has(root)) return;
    const events = new AbortController();
    scopes.set(root, events);
    each(root, '[data-bs-toggle="tooltip"]', element => {
      const Tooltip = window.bootstrap?.Tooltip;
      if (!Tooltip || Tooltip.getInstance(element)) return; // core/main.js owns initial instances.
      const tooltip = new Tooltip(element, { trigger: 'hover', animation: false, delay: 0 });
      element.addEventListener('click', () => tooltip.hide(), { signal: events.signal });
    });
    each(root, '[data-bs-toggle="popover"]', element => {
      const Popover = window.bootstrap?.Popover;
      if (Popover && !Popover.getInstance(element)) new Popover(element);
    });
    each(root, 'img[data-avatar-fallback]', image => {
      image.addEventListener('error', () => {
        const fallback = image.dataset.avatarFallback;
        if (!fallback || image.src === fallback) return;
        image.removeAttribute('data-avatar-fallback');
        image.src = fallback;
      }, { signal: events.signal });
      if (image.complete && image.naturalWidth === 0) image.dispatchEvent(new Event('error'));
    });
    each(root, '[data-auto-submit]', control => {
      control.addEventListener('change', () => control.form?.requestSubmit(), { signal: events.signal });
    });
    each(root, '[data-reset-pages]', control => {
      control.addEventListener('change', () => {
        const form = control.form;
        if (!form) return;
        const names = (control.dataset.resetPages || 'pending_page scheduled_page').split(/\s+/)
          .filter(name => /^[a-z][a-z0-9_]{0,63}$/.test(name));
        for (const name of names) {
          const field = form.elements.namedItem(name);
          if (field) field.value = '1';
        }
      }, { signal: events.signal });
    });
    if (window.Quill) each(root, editorSelector, element => {
      // Older Quill versions expose __quill; the container marker also protects
      // initial editors created by core/main.js when no lookup API is available.
      if (quillFor(element) || element.classList.contains('ql-container')) return;
      const options = { theme: element.matches('.quill-editor-bubble') ? 'bubble' : 'snow' };
      if (element.matches('.quill-editor-full')) options.modules = { toolbar: [
        [{ font: [] }, { size: [] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ script: 'super' }, { script: 'sub' }],
        [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
        ['direction', { align: [] }], ['link', 'image', 'video'], ['clean']
      ] };
      editors.set(element, new window.Quill(element, options));
    });
    const clockHosts = [];
    each(root, '[data-live-clock]', host => clockHosts.push(host));
    if (clockHosts.length) {
      const formatters = new Map();
      const formatterFor = tz => {
        const key = tz || 'Asia/Manila';
        if (formatters.has(key)) return formatters.get(key);
        let fmt;
        try {
          fmt = new Intl.DateTimeFormat('en-US', {
            timeZone: key, hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true,
          });
        } catch (_) {
          fmt = new Intl.DateTimeFormat('en-US', {
            hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true,
          });
        }
        formatters.set(key, fmt);
        return fmt;
      };
      const updateClocks = () => {
        const now = new Date();
        const iso = now.toISOString();
        for (const host of clockHosts) {
          if (!host.isConnected) continue;
          const timeEl = host.querySelector('[data-live-clock-time]') || host;
          timeEl.textContent = formatterFor(host.dataset.clockTz).format(now);
          if (timeEl.tagName === 'TIME') timeEl.dateTime = iso;
        }
      };
      updateClocks();
      let timeoutId = null;
      let intervalId = null;
      timeoutId = setTimeout(() => {
        timeoutId = null;
        updateClocks();
        intervalId = setInterval(updateClocks, 1000);
      }, Math.max(50, 1000 - (Date.now() % 1000)));
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) updateClocks();
      }, { signal: events.signal });
      events.signal.addEventListener('abort', () => {
        if (timeoutId !== null) clearTimeout(timeoutId);
        if (intervalId !== null) clearInterval(intervalId);
      }, { once: true });
    }
    window.BalaySelect?.init(root);
    window.BalayDatepicker?.init(root);
    window.BalayDateTimePicker?.init(root);
    window.BalayDateRangePicker?.init(root);
  }
  function destroy(root) {
    if (!root) return;
    scopes.get(root)?.abort();
    scopes.delete(root);
    // Navigation disposes all Bootstrap types next. Instance lookups make this
    // safe regardless of listener order, without touching persistent shell UI.
    each(root, '[data-bs-toggle="tooltip"], [data-bs-toggle="popover"]', element => {
      window.bootstrap?.Tooltip?.getInstance(element)?.dispose();
      window.bootstrap?.Popover?.getInstance(element)?.dispose();
    });
    each(root, editorSelector, element => {
      const editor = quillFor(element);
      // Quill has no public destroy API. Release focus/editing, not its internals;
      // removal releases the editor and its page-local toolbar DOM together.
      editor?.blur?.();
      editor?.disable?.();
      editors.delete(element);
    });
    window.BalayDateRangePicker?.destroy(root);
    window.BalaySelect?.destroy(root);
    // Datepicker owns removal cleanup; Alpine/HTMX own their respective trees.
  }
  window.SynapsePageLifecycle = { init, destroy };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
  document.addEventListener('click', event => {
    document.querySelectorAll('details.tws-roster-details[open]').forEach(details => {
      if (!details.contains(event.target)) details.open = false;
    });
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (!document.querySelector('.modal.show')) {
      const readClose = document.querySelector('#ga-read-history .ga-read-close');
      if (readClose) {
        event.preventDefault();
        readClose.click();
        return;
      }
    }
    document.querySelectorAll('details.tws-roster-details[open]').forEach(details => {
      details.open = false;
      details.querySelector('summary')?.focus();
    });
  });
})();
