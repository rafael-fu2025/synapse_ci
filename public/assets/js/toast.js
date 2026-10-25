(() => {
  'use strict';

  if (window.SynapseToast) return;

  const tones = {
    success: { icon: 'bi-check-circle-fill', label: 'Success' },
    error: { icon: 'bi-x-circle-fill', label: 'Error' },
    info: { icon: 'bi-info-circle-fill', label: 'Information' },
    warning: { icon: 'bi-exclamation-triangle-fill', label: 'Warning' }
  };
  const entries = [];
  const keyed = new Map();
  let container;

  function host() {
    if (!container) {
      container = document.createElement('div');
      container.id = 'synapse-toast-container';
      container.className = 'synapse-toast-container';
      document.body.append(container);
    }
    return container;
  }

  function stop(entry) {
    if (entry.timer !== null) {
      clearTimeout(entry.timer);
      entry.timer = null;
      entry.remaining = Math.max(0, entry.remaining - (performance.now() - entry.started));
    }
  }

  function schedule(entry) {
    stop(entry);
    if (entry.removed || entry.closing || !entry.ready || !entry.duration ||
        entry.hovered || entry.element.contains(document.activeElement) || entry.running) return;
    entry.started = performance.now();
    entry.timer = setTimeout(() => dismiss(entry), entry.remaining);
  }

  function cleanup(entry) {
    if (entry.removed) return;
    entry.removed = true;
    stop(entry);
    cancelAnimationFrame(entry.announcement);
    if (keyed.get(entry.key) === entry) keyed.delete(entry.key);
    entries.splice(entries.indexOf(entry), 1);
    entry.instance.dispose();
    entry.element.remove();
    entry.action = null;
  }

  function dismiss(entry) {
    if (entry.removed || entry.closing) return;
    entry.closing = true;
    stop(entry);
    entry.instance.hide();
  }

  function finish(entry) {
    // Finish Bootstrap's pending transition before disposal (including stack eviction).
    dismiss(entry);
    entry.element.dispatchEvent(new Event('transitionend'));
  }

  function update(entry, message, options, type) {
    stop(entry);
    entry.version += 1;
    entry.duration = typeof options.duration === 'number' && Number.isFinite(options.duration) && options.duration >= 0
      ? Math.min(options.duration, 2147483647) : (type === 'success' ? 5000 : 0);
    entry.remaining = entry.duration;
    entry.element.dataset.type = type;
    entry.icon.className = `synapse-toast-icon bi ${tones[type].icon}`;
    entry.label.textContent = `${tones[type].label}: `;
    const urgent = type === 'error' || type === 'warning';
    entry.message.setAttribute('role', urgent ? 'alert' : 'status');
    entry.message.setAttribute('aria-live', urgent ? 'assertive' : 'polite');
    cancelAnimationFrame(entry.announcement);
    entry.text.textContent = '';
    entry.announcement = requestAnimationFrame(() => {
      if (!entry.removed) entry.text.textContent = String(message ?? '');
    });
    const action = options.action;
    entry.action = action && typeof action.run === 'function' && typeof action.label === 'string' && action.label.trim()
      ? { label: action.label, run: action.run } : null;
    entry.running = false;
    entry.actionButton.disabled = false;
    entry.actionButton.hidden = !entry.action;
    entry.actionButton.textContent = entry.action ? entry.action.label : '';
    schedule(entry);
  }

  function show(message, options = {}) {
    options = options && typeof options === 'object' ? options : {};
    const type = Object.prototype.hasOwnProperty.call(tones, options.type) ? options.type : 'info';
    const key = typeof options.key === 'string' ? options.key : undefined;
    let entry = key === undefined ? undefined : keyed.get(key);
    if (entry && entry.closing) {
      finish(entry);
      entry = undefined;
    }
    if (entry) {
      update(entry, message, options, type);
      return entry.handle;
    }
    while (entries.length >= 3) finish(entries[0]);

    const element = document.createElement('div');
    element.className = 'toast synapse-toast';
    const body = document.createElement('div');
    body.className = 'toast-body';
    const icon = document.createElement('i');
    icon.setAttribute('aria-hidden', 'true');
    const content = document.createElement('div');
    content.className = 'synapse-toast-content';
    const live = document.createElement('div');
    live.setAttribute('aria-atomic', 'true');
    const label = document.createElement('span');
    label.className = 'visually-hidden';
    const text = document.createElement('span');
    text.className = 'synapse-toast-message';
    live.append(label, text);
    const actionButton = document.createElement('button');
    actionButton.type = 'button';
    actionButton.className = 'synapse-toast-action';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'synapse-toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';
    content.append(live, actionButton);
    body.append(icon, content, close);
    element.append(body);
    host().append(element);
    entry = {
      element, icon, label, text, message: live, actionButton, key,
      timer: null, remaining: 0, ready: false, hovered: false,
      closing: false, removed: false, running: false, version: 0,
      instance: new bootstrap.Toast(element, { autohide: false })
    };
    entry.handle = { dismiss: () => dismiss(entry) };
    entries.push(entry);
    if (key !== undefined) keyed.set(key, entry);
    element.addEventListener('shown.bs.toast', () => {
      entry.ready = true;
      schedule(entry);
    });
    element.addEventListener('hidden.bs.toast', () => cleanup(entry), { once: true });
    element.addEventListener('mouseenter', () => { entry.hovered = true; stop(entry); });
    element.addEventListener('mouseleave', () => { entry.hovered = false; schedule(entry); });
    element.addEventListener('focusin', () => stop(entry));
    element.addEventListener('focusout', () => schedule(entry));
    close.addEventListener('click', () => dismiss(entry));
    actionButton.addEventListener('click', async () => {
      if (!entry.action || entry.running || entry.removed || entry.closing) return;
      const action = entry.action;
      const version = entry.version;
      entry.running = true;
      actionButton.disabled = true;
      stop(entry);
      try {
        await action.run();
      } catch (error) {
        // Retry callbacks own their feedback; failures must not become unhandled rejections.
        console.error('SynapseToast action failed:', error);
      } finally {
        if (!entry.removed && entry.version === version) {
          entry.running = false;
          actionButton.disabled = false;
          schedule(entry);
        }
      }
    });
    update(entry, message, options, type);
    entry.instance.show();
    return entry.handle;
  }

  window.SynapseToast = { show };
})();
