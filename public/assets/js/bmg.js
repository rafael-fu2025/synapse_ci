/** Presentation only; the server owns authorization, validation and hardware gates. */
(function () {
  'use strict';
  if (window.SynapseBmg) return;
  window.SynapseBmg = true;
  let timeId = 0;
  let pendingWrite = null;
  const committed = new WeakSet();
  const confirmationHandoffs = new WeakSet();

  function facilitiesUrl(value) {
    if (typeof value !== 'string' || !value) return null;
    try {
      const configured = document.querySelector('[data-bmg-base-url]')?.dataset.bmgBaseUrl;
      if (!configured) return null;
      const base = new URL(configured, location.href);
      if (base.origin !== location.origin || base.username || base.password || base.search || base.hash ||
          !/\/facilities\/?$/.test(base.pathname)) return null;
      const url = new URL(value, location.href);
      const prefix = base.pathname.replace(/\/$/, '');
      const unsafeSegment = url.pathname.split('/').some(segment => {
        const decoded = decodeURIComponent(segment);
        return decoded === '.' || decoded === '..' || /[/\\]/.test(decoded);
      });
      return url.origin === location.origin && !url.username && !url.password && !unsafeSegment &&
        (url.pathname === prefix || url.pathname.startsWith(prefix + '/')) ? url : null;
    } catch (_) { return null; }
  }
  function errorBox(form, message) {
    let box = form.querySelector('[data-bmg-submit-error]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'alert alert-danger small';
      box.setAttribute('data-bmg-submit-error', '');
      box.setAttribute('role', 'alert');
      box.tabIndex = -1;
      (form.querySelector('.modal-body') || form).prepend(box);
    }
    box.textContent = message;
    box.hidden = false;
    const modal = form.closest('[data-bmg-modal]');
    if (modal?.classList.contains('show') && modal.getAttribute('aria-modal') !== 'true') {
      modal.addEventListener('shown.bs.modal', () => box.focus(), { once: true });
    } else box.focus();
  }
  function isAjaxForm(form) {
    // Only explicit facilities-owned forms participate. Registration is deliberately
    // unmarked: its direct POST is the sole delivery path for the one-time token.
    return form instanceof HTMLFormElement && form.matches('[data-bmg-submit]') &&
      (form.getAttribute('method') || 'GET').toUpperCase() === 'POST';
  }
  async function save(form, submitter) {
    const url = facilitiesUrl(form.getAttribute('action') || location.href);
    if (!url) { errorBox(form, 'The form action must stay within this site’s facilities area.'); return; }
    const mutations = window.SynapseMutations;
    if (committed.has(form)) return;

    const refreshRoot = form.closest('[data-refresh-owner]');
    const refreshUrl = refreshRoot && facilitiesUrl(refreshRoot.dataset.refreshUrl);
    const data = new FormData(form);
    if (submitter?.name) data.append(submitter.name, submitter.value);
    const modal = form.closest('[data-bmg-modal]');
    const controls = [...new Set([...form.querySelectorAll('input, select, textarea, button'),
      ...(modal?.querySelectorAll('[data-bs-dismiss="modal"]') || [])])];
    const disabled = controls.map(control => control.disabled);
    const oldError = form.querySelector('[data-bmg-submit-error]');
    if (oldError) oldError.hidden = true;
    pendingWrite = form;
    let finishLoading = window.SynapseLoading?.begin(form, { label: 'Saving…', button: submitter });
    form.setAttribute('aria-busy', 'true');
    controls.forEach(control => { control.disabled = true; });
    // Confirmed modal forms were hidden before asking for shared consent. Restore
    // the editor now, retaining all inputs and showing that the write is pending.
    if (modal && !modal.classList.contains('show')) bootstrap.Modal.getOrCreateInstance(modal).show();
    let saved = false;
    try {
      const response = await fetch(url.href, {
        method: 'POST', body: data, credentials: 'same-origin', redirect: 'error',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers(), ...(mutations ? { 'X-Synapse-Feedback': 'toast' } : {}),
          ...(refreshRoot?.dataset.refreshOwner
            ? { 'X-Synapse-Refresh-Owner': refreshRoot.dataset.refreshOwner,
              ...(refreshUrl ? { 'X-Synapse-Refresh-Url': refreshUrl.href } : {}) } : {}) }
      });
      window.SynapseCsrf?.fromResponse(response);
      const payload = await response.json();
      window.SynapseCsrf?.sync(payload.csrf);
      if (!response.ok || payload.success !== true) {
        errorBox(form, typeof payload.message === 'string' && payload.message ? payload.message : `The form could not be saved (HTTP ${response.status}).`);
        return;
      }
      saved = true;
      committed.add(form);
      const redirect = facilitiesUrl(payload.redirect);
      if (!redirect) {
        errorBox(form, 'The save succeeded, but the server returned an invalid facilities redirect. Do not resubmit; reload the facilities page to review the result.');
        return;
      }
      finishLoading?.();
      finishLoading = null;
      controls.forEach((control, i) => { control.disabled = form.contains(control) ? true : disabled[i]; });
      pendingWrite = null;
      form.removeAttribute('aria-busy');
      if (modal && (modal.classList.contains('show') || modal.getAttribute('aria-modal') === 'true')) {
        const hidden = new Promise(resolve => modal.addEventListener('hidden.bs.modal', resolve, { once: true }));
        const instance = bootstrap.Modal.getInstance(modal);
        const hide = () => instance.hide();
        modal.addEventListener('shown.bs.modal', hide, { once: true });
        hide();
        await hidden;
        modal.removeEventListener('shown.bs.modal', hide);
      }
      if (mutations) {
        const descriptor = window.SynapseRefreshRegions?.descriptor?.(payload.refresh, redirect.href) || null;
        if (descriptor?.mode === 'regions') {
          const root = document.querySelector(`[data-refresh-owner="${CSS.escape(descriptor.owner)}"]`);
          if (!root) location.assign(redirect.href);
          else await mutations.refresh(payload.refresh?.url ? descriptor.url : location.href, payload.message,
            { form, descriptor: payload.refresh });
        } else await mutations.refresh(redirect.href, payload.message, { form, descriptor: payload.refresh });
      } else location.assign(redirect.href);
    } catch (_) {
      errorBox(form, 'The save response could not be received. Your fields are retained. The write may have completed; check the facilities record before retrying.');
    } finally {
      finishLoading?.();
      pendingWrite = null;
      form.removeAttribute('aria-busy');
      controls.forEach((control, i) => { control.disabled = saved && form.contains(control) ? true : disabled[i]; });
    }
  }

  function formatTime12(hhmm) {
    const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(hhmm || '');
    if (!match) return '';
    const hour = Number(match[1]);
    return `${String(hour % 12 || 12).padStart(2, '0')}:${match[2]} ${hour >= 12 ? 'PM' : 'AM'}`;
  }
  function createTimeRow(initialTime = '') {
    const row = document.createElement('div');
    row.className = 'd-flex align-items-end gap-2 mb-2';
    row.setAttribute('data-bmg-time-row', '');
    const id = `rotationTimeAdded${++timeId}`;
    const display = formatTime12(initialTime);
    row.innerHTML = `<div class="flex-grow-1"><label class="form-label" for="${id}" data-bmg-session-label>Start time</label><input id="${id}" class="form-control" type="text" data-timepicker data-target="#${id}Value" value="${display}" placeholder="hh:mm AM/PM" aria-label="Start time" autocomplete="off" required><input id="${id}Value" type="hidden" name="times[]" value="${initialTime}"></div><button class="btn btn-outline-secondary" type="button" data-bmg-remove-time aria-label="Remove start time">Remove</button>`;
    return row;
  }
  function defaultSpacedTimes(count) {
    const presets = {
      1: ['08:00'],
      2: ['08:00', '16:00'],
      3: ['08:00', '12:00', '16:00'],
      4: ['07:00', '11:00', '15:00', '19:00'],
      5: ['06:00', '09:00', '12:00', '15:00', '18:00'],
      6: ['06:00', '09:00', '12:00', '15:00', '18:00', '21:00'],
      7: ['06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00'],
      8: ['06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'],
    };
    return presets[count] || presets[3];
  }
  function refresh(form) {
    const rows = Array.from(form.querySelectorAll('[data-bmg-time-row]'));
    const count = rows.length;
    rows.forEach((row, index) => {
      const label = row.querySelector('[data-bmg-session-label]');
      if (label) label.textContent = `Session ${index + 1} start time`;
    });
    const sessionSelect = form.querySelector('[data-bmg-session-count]');
    if (sessionSelect && count >= 1 && count <= 8 && sessionSelect.value !== String(count)) {
      sessionSelect.dataset.bmgSyncing = '1';
      sessionSelect.value = String(count);
      sessionSelect.dispatchEvent(new Event('change', { bubbles: false }));
      delete sessionSelect.dataset.bmgSyncing;
    }
    const addBtn = form.querySelector('[data-bmg-add-time]');
    if (addBtn) addBtn.disabled = count >= 8;
    const countEl = form.querySelector('[data-bmg-time-count]');
    if (countEl) countEl.textContent = `${count} of 8 times`;
    const panel = form.closest('#bmg-schedule') || form.parentElement;
    const summaryEl = panel?.querySelector('[data-bmg-daily-summary]');
    const targetEl = panel?.querySelector('[data-bmg-daily-target]');
    const rotInput = form.querySelector('[name="rotations"]');
    const rotations = Math.max(0, parseInt(rotInput?.value || '0', 10) || 0);
    const total = count * rotations;
    if (summaryEl) {
      summaryEl.textContent = `${count} ${count === 1 ? 'session' : 'sessions'} × ${rotations} ${rotations === 1 ? 'rotation' : 'rotations'} = ${total} rotations / day`;
    }
    if (targetEl) {
      targetEl.textContent = total === 21 ? '21 / 21 · Daily target met' : `${total} / 21 · Target 3 × 7`;
      targetEl.classList.toggle('ce-status-emerald', total === 21);
      targetEl.classList.toggle('ce-status-amber', total !== 21);
    }
  }
  function validate(form) {
    const times = Array.from(form.querySelectorAll('[name="times[]"]'), input => input.value);
    const enabled = form.querySelector('[name="enabled"]');
    const rotations = Number(form.querySelector('[name="rotations"]').value);
    const windowSeconds = rotations * 6;
    const minutes = times.map(time => Number(time.slice(0, 2)) * 60 + Number(time.slice(3))).sort((a, b) => a - b);
    let error = '';
    if (times.length > 8 || new Set(times).size !== times.length) error = 'Use up to eight distinct start times.';
    else if (enabled.checked && !enabled.disabled && times.length === 0) error = 'Add at least one time to enable daily rotation.';
    else if (!Number.isInteger(rotations) || rotations < 1 || rotations > 100) error = 'Rotations per run must be a whole number between 1 and 100.';
    else if (minutes.length > 1 && minutes.some((minute, i) => ((minutes[(i + 1) % minutes.length] - minute + 1440) % 1440) * 60 < windowSeconds)) error = 'Scheduled runs must not overlap, including across midnight.';
    const box = form.querySelector('[data-bmg-schedule-error]');
    box.textContent = error;
    box.classList.toggle('d-none', !error);
    if (error) { box.tabIndex = -1; box.focus(); }
    return !error;
  }
  function clearTokens() {
    document.querySelectorAll('[data-bmg-token]').forEach(field => { field.value = ''; field.textContent = ''; });
  }
  function init() {
    document.querySelectorAll('[data-bmg-schedule]').forEach(refresh);
    const requested = document.querySelector('[data-bmg-open-create]');
    const modal = document.getElementById('unitModal');
    if (requested && modal && window.bootstrap?.Modal) {
      requested.removeAttribute('data-bmg-open-create');
      bootstrap.Modal.getOrCreateInstance(modal).show();
    }
  }
  document.addEventListener('input', event => {
    const form = event.target?.closest?.('[data-bmg-schedule]');
    if (form && event.target.matches('[name="rotations"]')) refresh(form);
  });
  document.addEventListener('change', event => {
    const select = event.target?.closest?.('[data-bmg-session-count]');
    if (!select || select.dataset.bmgSyncing === '1') return;
    const form = select.closest('[data-bmg-schedule]');
    if (!form) return;
    const desired = Math.min(8, Math.max(1, parseInt(select.value || '3', 10) || 3));
    const container = form.querySelector('[data-bmg-times]');
    const existing = Array.from(container.querySelectorAll('[name="times[]"]'), input => input.value);
    const defaults = defaultSpacedTimes(desired);
    container.replaceChildren();
    for (let i = 0; i < desired; i++) {
      const value = existing[i] && !defaults.slice(0, i).includes(existing[i]) ? existing[i] : defaults[i];
      const row = createTimeRow(value);
      container.append(row);
      window.BalayDateTimePicker?.init(row);
    }
    const rotInput = form.querySelector('[name="rotations"]');
    if (rotInput) {
      rotInput.value = String(Math.max(1, Math.round(21 / desired)));
    }
    refresh(form);
  });
  document.addEventListener('click', event => {
    const preset = event.target.closest('[data-bmg-preset]');
    if (preset) {
      const form = preset.closest('[data-bmg-schedule]');
      if (!form) return;
      const times = (preset.getAttribute('data-bmg-preset') || '').split(',').map(s => s.trim()).filter(Boolean);
      const container = form.querySelector('[data-bmg-times]');
      container.replaceChildren();
      times.slice(0, 8).forEach(time => {
        const row = createTimeRow(time);
        container.append(row);
        window.BalayDateTimePicker?.init(row);
      });
      const rotInput = form.querySelector('[name="rotations"]');
      const presetRot = preset.getAttribute('data-bmg-preset-rotations');
      if (rotInput && presetRot) rotInput.value = presetRot;
      refresh(form);
      return;
    }
    const add = event.target.closest('[data-bmg-add-time]');
    const remove = event.target.closest('[data-bmg-remove-time]');
    if (!add && !remove) return;
    const form = (add || remove).closest('[data-bmg-schedule]');
    if (!form) return;
    if (remove) {
      remove.closest('[data-bmg-time-row]').remove();
      form.querySelector('[data-bmg-add-time]').focus();
    } else if (form.querySelectorAll('[data-bmg-time-row]').length < 8) {
      const row = createTimeRow('');
      form.querySelector('[data-bmg-times]').append(row);
      window.BalayDateTimePicker?.init(row);
      row.querySelector('input').focus();
    }
    refresh(form);
  });
  // Run before the document-level shared confirmation listener. Never stack two
  // Bootstrap focus traps; close the editor before asking for shared consent.
  window.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((pendingWrite || confirmationHandoffs.has(form)) && (isAjaxForm(form) || form.closest('[data-bmg-modal]'))) {
      event.preventDefault(); event.stopImmediatePropagation(); return;
    }
    if (form.matches('[data-bmg-schedule]') && !validate(form)) {
      event.preventDefault(); event.stopImmediatePropagation(); return;
    }
    const modal = form.closest('[data-bmg-modal].show');
    if (!modal || !form.hasAttribute('data-confirm') || !window.bootstrap?.Modal) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const submitter = event.submitter;
    confirmationHandoffs.add(form);
    modal.addEventListener('hidden.bs.modal', () => {
      confirmationHandoffs.delete(form);
      if (form.isConnected && (!submitter || !submitter.disabled)) form.requestSubmit(submitter || undefined);
    }, { once: true });
    bootstrap.Modal.getInstance(modal)?.hide();
  }, true);
  // Shared confirmation owns capture-phase consent. Only approved resubmissions
  // reach this bubble listener; registration and all other application forms pass through.
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!isAjaxForm(form) || event.defaultPrevented) return;
    event.preventDefault();
    if (!pendingWrite) save(form, event.submitter);
  });
  document.addEventListener('hide.bs.modal', event => {
    if (event.target.matches('[data-bmg-modal]') && pendingWrite?.closest('[data-bmg-modal]') === event.target) event.preventDefault();
  });
  document.addEventListener('hidden.bs.modal', event => {
    const form = event.target.querySelector?.('form[data-bmg-create]');
    if (!form || pendingWrite === form) return;
    form.reset();
    form.querySelectorAll('[data-bmg-submit-error]').forEach(box => { box.hidden = true; });
  });
  document.addEventListener('shown.bs.modal', event => {
    if (!event.target.matches('[data-bmg-modal]')) return;
    const error = event.target.querySelector('[data-bmg-submit-error]:not([hidden])');
    (error || event.target.querySelector('input:not([type="hidden"]):not(:disabled), textarea:not(:disabled), button[type="submit"]:not(:disabled)'))?.focus();
  });
  document.addEventListener('synapse:page-load', init);
  document.addEventListener('synapse:region-load', event => {
    if (event.detail?.owner?.startsWith('facilities.')) init();
  });
  document.addEventListener('synapse:before-swap', clearTokens);
  window.addEventListener('pagehide', clearTokens);
  init();
})();
