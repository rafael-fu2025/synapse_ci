/**
 * BalayDatepicker — dependency-free accessible date picker.
 *
 * Implements the WAI-ARIA APG "Date Picker Dialog" pattern as a non-modal
 * popup attached to a text input:
 *  - grid with roving tabindex; Arrow keys move by day/week, Home/End jump
 *    to the first/last day of the week, PageUp/PageDown (Shift = year)
 *    change month/year with day-of-month clamping, Enter/Space select,
 *    Escape closes and returns focus to the input.
 *  - `aria-selected` on the chosen date, `aria-current="date"` on today,
 *    disabled dates marked `aria-disabled`, polite live region for hints.
 *
 * Value model: the visible input holds a locale-formatted date; the real
 * form value (YYYY-MM-DD) is mirrored to a hidden input, so the server
 * contract never changes.
 *
 * Usage:
 *   <input type="text" data-datepicker data-target="#birthday" data-max="today">
 *   <input type="hidden" name="birthday" id="birthday" value="">
 *
 * Options (data attributes on the trigger input):
 *   data-target      selector of the hidden ISO input    (optional)
 *   data-range-end   hidden end-date selector; enables range mode (requires data-target)
 *   data-max-days    maximum inclusive days in range mode (optional)
 *
 * Range example:
 *   <input data-datepicker data-target="#from" data-range-end="#to" data-max-days="31">
 *   <input type="hidden" id="from" name="from"><input type="hidden" id="to" name="to">
 * Select a start and end in one calendar, or type DD/MM/YYYY – DD/MM/YYYY.
 * Incomplete/invalid ranges clear both mirrors and block required form submission.
 *
 *   data-min         "today" or YYYY-MM-DD               (optional)
 *   data-max         "today" or YYYY-MM-DD               (optional)
 *   data-week-start  0 (Sunday) or 1 (Monday, default)   (optional)
 *   data-order       typed entry order: "dmy" (default) or "mdy"
 *   data-locale      BCP-47 locale (default: page lang)  (optional)
 *   data-year-span   number of years listed (default 100)
 *
 * SYNAPSE defaults: Monday-first weeks and DD/MM/YYYY entry. The ISO mirror
 * receives input before any change notification, so Alpine reads the new value.
 * Native text changes and calendar picks each commit once; invalid text blocks
 * form submission and never submits a stale ISO date.
 */
(function () {
  'use strict';

  const ISO_RE = /^(\d{4})-(\d{1,2})-(\d{1,2})$/;

  function pad(n) { return String(n).padStart(2, '0'); }

  function toISO(d) { return String(d.getFullYear()).padStart(4, '0') + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

  function parseISO(s) {
    const m = ISO_RE.exec((s || '').trim());
    if (!m) return null;
    const year = Number(m[1]);
    if (year < 1) return null;
    const d = new Date(0);
    d.setFullYear(year, Number(m[2]) - 1, Number(m[3]));
    d.setHours(0, 0, 0, 0);
    if (isNaN(d.getTime()) || d.getFullYear() !== year || d.getMonth() !== Number(m[2]) - 1 || d.getDate() !== Number(m[3])) return null;
    return d;
  }

  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }

  function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }

  function shiftMonth(d, n) {
    const lastOfTarget = new Date(d.getFullYear(), d.getMonth() + n + 1, 0).getDate();
    return new Date(d.getFullYear(), d.getMonth() + n, Math.min(d.getDate(), lastOfTarget));
  }

  function sameDay(a, b) {
    return !!a && !!b && a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }

  function resolveBound(value) {
    if (!value) return null;
    return value === 'today' ? startOfDay(new Date()) : parseISO(value);
  }

  // Fixed reference week (Jan 1 2023 is a Sunday) for locale weekday names.
  function weekdayDate(index) { return new Date(Date.UTC(2023, 0, 1 + index)); }

  let openInstance = null;
  let nextId = 0;
  const instances = new Map();

  class Datepicker {
    constructor(input) {
      this.input = input;
      this.target = input.dataset.target ? document.querySelector(input.dataset.target) : null;
      this.range = input.hasAttribute('data-range-end');
      this.endTarget = this.range ? document.querySelector(input.dataset.rangeEnd) : null;
      this.maxDays = Math.max(1, Number(input.dataset.maxDays) || Infinity);
      this.initialEndISO = this.endTarget ? this.endTarget.value : '';
      this.rangeEnd = parseISO(this.initialEndISO);
      this.locale = input.dataset.locale || document.documentElement.lang || navigator.language || 'en';
      this.weekStart = input.dataset.weekStart === undefined ? 1 : Number(input.dataset.weekStart);
      this.order = (input.dataset.order || 'dmy').toLowerCase() === 'mdy' ? 'mdy' : 'dmy';
      this.yearSpan = Math.max(1, Math.min(500, Number(input.dataset.yearSpan) || 100));
      this.events = new AbortController();
      this.countsUrl = input.dataset.datepickerCountsUrl || '';
      this.countsRequest = null;
      this.countsRange = '';
      this.counts = null;
      this.countsTimer = null;
      this.notifying = false;
      this.id = 'synapse-date-' + (++nextId);
      this.min = resolveBound(input.dataset.min || input.getAttribute('min'));
      this.max = resolveBound(input.dataset.max || input.getAttribute('max'));

      this.initialISO = this.target ? this.target.value : input.value;
      this.selected = parseISO(this.initialISO);
      this.view = this.selected ? new Date(this.selected) : startOfDay(new Date());
      this.focusDate = this.selected ? new Date(this.selected) : startOfDay(new Date());
      this.isOpen = false;

      this.monthFmt = new Intl.DateTimeFormat(this.locale, { month: 'long', timeZone: 'UTC' });
      this.weekdayFmt = new Intl.DateTimeFormat(this.locale, { weekday: 'short', timeZone: 'UTC' });
      this.weekdayLongFmt = new Intl.DateTimeFormat(this.locale, { weekday: 'long', timeZone: 'UTC' });
      if (!this.input.placeholder) {
        const format = this.order === 'mdy' ? 'MM/DD/YYYY' : 'DD/MM/YYYY';
        this.input.placeholder = this.range ? format + ' – ' + format : format;
      }

      this.buildShell();
      this.buildPopup();
      this.bind();
      this.syncFromTarget();
      this.lastNotifiedISO = this.range ? this.rangeValue() : (this.target ? this.target.value : '');
      this.observer = new MutationObserver(() => {
        this.min = resolveBound(input.dataset.min || input.getAttribute('min'));
        this.max = resolveBound(input.dataset.max || input.getAttribute('max'));
        if (this.target) this.target.disabled = input.disabled;
        if (this.endTarget) this.endTarget.disabled = input.disabled;
        this.validateText();
        if (input.disabled || input.readOnly) this.closePopup(false);
      });
      this.observer.observe(input, { attributes: true, attributeFilter: ['data-min', 'data-max', 'min', 'max', 'required', 'disabled', 'readonly'] });
      if (this.target) this.target.disabled = input.disabled;
      if (this.endTarget) this.endTarget.disabled = input.disabled;
      instances.set(input, this);
    }

    /* ---------- DOM construction ---------- */

    buildShell() {
      this.wrap = document.createElement('div');
      this.wrap.className = 'dp-wrap';
      this.input.parentNode.insertBefore(this.wrap, this.input);
      this.wrap.appendChild(this.input);
      const icon = document.createElement('i');
      icon.className = 'bi bi-calendar3 dp-icon';
      icon.setAttribute('aria-hidden', 'true');
      this.wrap.appendChild(icon);
      this.input.setAttribute('aria-haspopup', 'dialog');
      this.input.setAttribute('aria-expanded', 'false');
      this.input.setAttribute('autocomplete', 'off');
      this.input.setAttribute('inputmode', 'numeric');
      this.input.setAttribute('maxlength', this.range ? '23' : '10');
    }

    buildPopup() {
      const popup = document.createElement('div');
      popup.className = 'dp-popup';
      popup.id = this.id;
      this.input.setAttribute('aria-controls', this.id);
      popup.setAttribute('role', 'dialog');
      popup.setAttribute('aria-modal', 'false');
      popup.setAttribute('aria-label', this.range ? 'Choose date range' : 'Choose date');
      if (this.range) popup.classList.add('dp-range');
      popup.hidden = true;
      popup.innerHTML =
        '<div class="dp-head">' +
        '  <button type="button" class="dp-nav" data-dp-prev aria-label="Previous month"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>' +
        '  <select class="dp-select dp-month form-select" aria-label="Month"></select>' +
        '  <select class="dp-select dp-year form-select" aria-label="Year"></select>' +
        '  <button type="button" class="dp-nav" data-dp-next aria-label="Next month"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>' +
        '</div>' +
        '<table class="dp-grid" role="grid" aria-label="Calendar"><thead><tr class="dp-weekdays" role="row"></tr></thead><tbody></tbody></table>' +
        '<div class="dp-foot">' +
        '  <button type="button" class="dp-foot-btn" data-dp-today><i class="bi bi-crosshair2 me-1" aria-hidden="true"></i>Today</button>' +
        '  <button type="button" class="dp-foot-btn" data-dp-clear><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</button>' +
        '</div>' +
        '<p class="dp-help" aria-live="polite"></p>';
      // Keep the calendar inside a modal's focus boundary when applicable.
      (this.input.closest('.modal') || document.body).appendChild(popup);
      this.popup = popup;
      this.monthSelect = popup.querySelector('.dp-month');
      this.yearSelect = popup.querySelector('.dp-year');
      this.weekdaysRow = popup.querySelector('.dp-weekdays');
      this.gridBody = popup.querySelector('tbody');
      this.help = popup.querySelector('.dp-help');
      if (this.countsUrl) {
        popup.classList.add('dp-has-counts');
        this.countsStatus = document.createElement('p');
        this.countsStatus.className = 'dp-counts-status';
        this.countsStatus.setAttribute('role', 'status');
        this.countsStatus.setAttribute('aria-live', 'polite');
        this.countsStatus.setAttribute('aria-atomic', 'true');
        this.gridBody.parentNode.after(this.countsStatus);
      }

      // Weekday headers, respecting the configured first day of week.
      for (let i = 0; i < 7; i++) {
        const d = weekdayDate((this.weekStart + i) % 7);
        const th = document.createElement('th');
        th.setAttribute('scope', 'col');
        th.setAttribute('abbr', this.weekdayLongFmt.format(d));
        th.textContent = this.weekdayFmt.format(d).replace('.', '');
        this.weekdaysRow.appendChild(th);
      }

      // Month options (long names).
      for (let m = 0; m < 12; m++) {
        const opt = document.createElement('option');
        opt.value = String(m);
        opt.textContent = this.monthFmt.format(new Date(Date.UTC(2023, m, 1)));
        this.monthSelect.appendChild(opt);
      }

      this.ensureYears();

      // Upgrade the header selects to the shared custom dropdown. This runs
      // after BalaySelect's own DOMContentLoaded pass, so init explicitly.
      window.BalaySelect && window.BalaySelect.init(popup);
    }

    /* ---------- events ---------- */

    bind() {
      const on = (element, event, handler, options = {}) => element.addEventListener(event, handler, { ...options, signal: this.events.signal });
      on(this.input, 'click', () => this.toggle());
      on(this.input, 'keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); this.openPopup(); }
        if (e.key === 'Escape') { e.preventDefault(); this.closePopup(false); }
      });
      const acceptsInsertion = (text) => {
        const start = this.input.selectionStart ?? this.input.value.length;
        const end = this.input.selectionEnd ?? start;
        const next = this.input.value.slice(0, start) + text + this.input.value.slice(end);
        return (this.range ? /^[0-9/–\s-]*$/ : /^[0-9/-]*$/).test(next) && next.length <= (this.range ? 23 : 10);
      };
      on(this.input, 'beforeinput', (e) => {
        if (e.inputType.startsWith('insert') && e.data !== null && !acceptsInsertion(e.data)) e.preventDefault();
      });
      on(this.input, 'paste', (e) => {
        if (e.clipboardData && !acceptsInsertion(e.clipboardData.getData('text'))) e.preventDefault();
      });
      on(this.input, 'input', () => {
        if (this.notifying) return;
        // Some autofill/IME edits cannot be canceled. Clear the whole invalid
        // entry rather than turning a decimal or mixed string into a valid date.
        if ((this.range ? /[^0-9/–\s-]/ : /[^0-9/-]/).test(this.input.value) || this.input.value.length > (this.range ? 23 : 10)) this.input.value = '';
        // Synchronize ISO/model immediately, but keep valid editing text untouched.
        this.validateText();
        if (this.isOpen) this.renderGrid();
      });
      on(this.input, 'change', () => {
        if (!this.notifying) this.commitText();
      });
      on(this.input, 'blur', () => {
        // Native change precedes blur; this fallback is idempotent.
        if (!this.notifying) this.commitText();
      });
      if (this.range) this.popup.querySelector('[role="grid"]').setAttribute('aria-multiselectable', 'true');
      on(this.popup, 'keydown', (e) => {
        if (e.key !== 'Escape') return;
        e.preventDefault();
        e.stopPropagation();
        // Handle the nested menu here, before a containing Bootstrap modal
        // receives Escape. Close one layer at a time, not the entire form.
        const toggle = this.popup.querySelector('.bd-select-toggle[aria-expanded="true"]');
        if (toggle && window.bootstrap) {
          window.bootstrap.Dropdown.getInstance(toggle)?.hide();
          toggle.focus();
          return;
        }
        this.closePopup(true);
      });
      on(this.popup.querySelector('[data-dp-prev]'), 'click', () => this.shiftView(-1));
      on(this.popup.querySelector('[data-dp-next]'), 'click', () => this.shiftView(1));
      on(this.monthSelect, 'change', () => {
        this.view = shiftMonth(this.view, Number(this.monthSelect.value) - this.view.getMonth());
        this.alignFocusToView();
        this.renderGrid();
      });
      on(this.yearSelect, 'change', () => {
        this.view = shiftMonth(this.view, (Number(this.yearSelect.value) - this.view.getFullYear()) * 12);
        this.alignFocusToView();
        this.renderGrid();
      });
      on(this.gridBody, 'click', (e) => {
        const cell = e.target.closest('td[data-date]');
        if (cell && !cell.classList.contains('is-disabled')) this.select(parseISO(cell.dataset.date));
      });
      on(this.gridBody, 'keydown', (e) => this.gridKeydown(e));
      on(this.popup.querySelector('[data-dp-today]'), 'click', () => {
        const today = startOfDay(new Date());
        if (this.inRange(today)) this.select(today);
      });
      on(this.popup.querySelector('[data-dp-clear]'), 'click', () => {
        if (this.input.disabled || this.input.readOnly) return;
        this.selected = null;
        this.rangeEnd = null;
        this.commit();
        this.closePopup(true);
      });
      on(document, 'pointerdown', (e) => {
        if (this.isOpen && !this.popup.contains(e.target) && !this.wrap.contains(e.target)) this.closePopup(false);
      });
      on(window, 'scroll', (e) => {
        if (!this.isOpen) return;
        // Scrolling the page (or the main content) invalidates the fixed popup's anchor.
        if (e.target === document || e.target.contains?.(this.wrap)) this.closePopup(false);
      }, { capture: true, passive: true });
      on(window, 'resize', () => this.isOpen && this.position());
      if (this.countsUrl) {
        on(document, 'synapse:filter-updated', (event) => {
          if (!event.detail?.root?.contains(this.input) || !this.isOpen) return;
          clearTimeout(this.countsTimer);
          this.countsTimer = setTimeout(() => {
            this.countsTimer = null;
            if (this.isOpen && this.input.isConnected) this.loadCounts(true);
          }, 200);
        });
        on(document, 'synapse:before-navigate', () => this.closePopup(false));
        on(document, 'synapse:before-swap', (event) => {
          if (event.detail?.root?.contains(this.input)) this.closePopup(false);
        });
        on(window, 'pagehide', () => this.closePopup(false));
      }
      if (this.target) {
        on(this.target, 'change', () => { if (!this.notifying) this.syncFromTarget(); });
        on(this.target, 'dp:sync', () => this.syncFromTarget());
      }
      if (this.endTarget) {
        on(this.endTarget, 'change', () => { if (!this.notifying) this.syncFromTarget(); });
        on(this.endTarget, 'dp:sync', () => this.syncFromTarget());
      }
      if (this.input.form) {
        on(this.input.form, 'reset', (e) => setTimeout(() => {
          if (e.defaultPrevented || !this.input.isConnected) return;
          // Setting .value on a hidden input also changes its reset default.
          // Retain the original ISO explicitly rather than trusting native reset.
          if (this.target) this.target.value = this.initialISO;
          if (this.endTarget) this.endTarget.value = this.initialEndISO;
          this.syncFromTarget();
          this.notifying = true;
          try {
            [this.target, this.endTarget].filter(Boolean).forEach(target => target.dispatchEvent(new Event('input', { bubbles: true })));
          } finally { this.notifying = false; }
          this.lastNotifiedISO = this.range ? this.rangeValue() : (this.target ? this.target.value : '');
          this.closePopup(false);
        }, 0));
      }
    }

    /* ---------- value handling ---------- */

    syncFromTarget() {
      if (this.range) this.rangeEnd = parseISO(this.endTarget?.value);
      this.selected = parseISO(this.target ? this.target.value : this.input.value);
      this.refreshDisplay();
      this.validateText();
    }

    refreshDisplay() {
      if (!this.selected) { this.input.value = ''; return; }
      const d = pad(this.selected.getDate());
      const m = pad(this.selected.getMonth() + 1);
      const y = String(this.selected.getFullYear()).padStart(4, '0');
      this.input.value = this.order === 'mdy' ? m + '/' + d + '/' + y : d + '/' + m + '/' + y;
      if (this.range) {
        const end = this.rangeEnd;
        const formatted = end ? (this.order === 'mdy'
          ? pad(end.getMonth() + 1) + '/' + pad(end.getDate())
          : pad(end.getDate()) + '/' + pad(end.getMonth() + 1)) + '/' + String(end.getFullYear()).padStart(4, '0') : '';
        this.input.value += ' – ' + formatted;
      }
    }

    readText() {
      const text = this.input.value.trim();
      return this.parseDateText(text);
    }

    parseDateText(text) {
      if (ISO_RE.test(text)) return parseISO(text);
      if (/^\d{8}$/.test(text)) return this.parseTyped(text);
      const parts = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text);
      return parts ? this.parseTyped(pad(parts[1]) + pad(parts[2]) + parts[3]) : null;
    }

    updateTarget() {
      if (this.range) {
        const complete = this.selected && this.rangeEnd && !this.input.validationMessage;
        const values = [[this.target, complete ? toISO(this.selected) : ''], [this.endTarget, complete ? toISO(this.rangeEnd) : '']];
        const changed = values.filter(([target, value]) => target && target.value !== value);
        // Write both endpoints before notifying any listener (e.g. Alpine or HTMX).
        changed.forEach(([target, value]) => { target.value = value; });
        this.notifying = true;
        try { changed.forEach(([target]) => target.dispatchEvent(new Event('input', { bubbles: true }))); }
        finally { this.notifying = false; }
        return;
      }
      if (!this.target) return;
      const iso = this.selected ? toISO(this.selected) : '';
      if (this.target.value === iso) return;
      this.target.value = iso;
      // Alpine's x-model must be current BEFORE a visible change runs fetchSlots.
      this.notifying = true;
      try { this.target.dispatchEvent(new Event('input', { bubbles: true })); }
      finally { this.notifying = false; }
    }

    validateText() {
      if (this.range) return this.validateRangeText();
      const text = this.input.value.trim();
      const date = this.readText();
      let error = '';
      if (!text && this.input.required) error = 'Choose a date.';
      else if (text && !date) error = 'Enter a valid date in ' + (this.order === 'mdy' ? 'MM/DD/YYYY' : 'DD/MM/YYYY') + ' format.';
      else if (date && !this.inRange(date)) error = 'Choose a date within the allowed range.';
      this.selected = error ? null : date;
      this.input.setCustomValidity(error);
      this.input.classList.toggle('is-invalid', Boolean(error));
      this.input.setAttribute('aria-invalid', String(Boolean(error)));
      this.updateTarget();
      return !error;
    }

    rangeDays(start, end) {
      // Calendar-day arithmetic must not change across DST boundaries.
      const day = date => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
      return Math.round((day(end) - day(start)) / 86400000) + 1;
    }

    rangeValue() {
      return (this.target?.value || '') + '|' + (this.endTarget?.value || '');
    }

    validateRangeText() {
      const text = this.input.value.trim();
      const parts = text.split(/\s*[–]\s*|\s+-\s+/);
      const start = this.parseDateText(parts[0] || '');
      const end = parts.length === 2 ? this.parseDateText(parts[1]) : null;
      let error = '';
      if (!text && this.input.required) error = 'Choose a date range.';
      else if (text && (!start || !end || parts.length !== 2)) error = 'Enter a start and end date in ' + (this.order === 'mdy' ? 'MM/DD/YYYY – MM/DD/YYYY' : 'DD/MM/YYYY – DD/MM/YYYY') + ' format.';
      else if (start && end && (end < start || !this.inRange(start) || !this.inRange(end))) error = 'Choose an ordered date range within the allowed dates.';
      else if (start && end && this.rangeDays(start, end) > this.maxDays) error = 'Choose a range of at most ' + this.maxDays + ' days, including both dates.';
      this.selected = start;
      this.rangeEnd = end;
      this.input.setCustomValidity(error);
      this.input.classList.toggle('is-invalid', Boolean(error));
      this.input.setAttribute('aria-invalid', String(Boolean(error)));
      this.updateTarget();
      return !error;
    }

    notifyChange(visible) {
      const iso = this.range ? this.rangeValue() : (this.selected ? toISO(this.selected) : '');
      if (iso === this.lastNotifiedISO) return;
      this.lastNotifiedISO = iso;
      this.notifying = true;
      try {
        if (this.target) this.target.dispatchEvent(new Event('change', { bubbles: true }));
        if (this.endTarget) this.endTarget.dispatchEvent(new Event('change', { bubbles: true }));
        if (visible) {
          this.input.dispatchEvent(new Event('input', { bubbles: true }));
          this.input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      } finally { this.notifying = false; }
    }

    commitText() {
      if (!this.validateText()) return;
      this.refreshDisplay();
      // The text field already emitted its native change; only notify the mirror.
      this.notifyChange(false);
    }

    commit() {
      this.refreshDisplay();
      this.validateText();
      this.notifyChange(true);
    }

    select(date) {
      if (this.input.matches(':disabled') || this.input.readOnly || !date || !this.inRange(date)) return;
      if (this.range) {
        if (!this.selected || this.rangeEnd) {
          this.selected = date;
          this.rangeEnd = null;
        } else {
          const start = date < this.selected ? date : this.selected;
          const end = date < this.selected ? this.selected : date;
          if (this.rangeDays(start, end) > this.maxDays) {
            this.help.textContent = 'Choose a range of at most ' + this.maxDays + ' days, including both dates.';
            return;
          }
          this.selected = start;
          this.rangeEnd = end;
        }
        this.commit();
        this.focusDate = new Date(date);
        const cell = this.renderGrid();
        this.help.textContent = this.rangeEnd ? 'Date range selected.' : 'Start date selected. Choose the end date.';
        if (this.rangeEnd) this.closePopup(true);
        else cell?.focus();
        return;
      }
      this.selected = date;
      this.commit();
      this.closePopup(true);
    }

    inRange(date) {
      if (this.min && date < this.min) return false;
      if (this.max && date > this.max) return false;
      return true;
    }

    parseTyped(digits) {
      const first = Number(digits.slice(0, 2));
      const second = Number(digits.slice(2, 4));
      const yyyy = Number(digits.slice(4, 8));
      const dd = this.order === 'mdy' ? second : first;
      const mm = this.order === 'mdy' ? first : second;
      if (mm < 1 || mm > 12) return null;
      return parseISO(String(yyyy).padStart(4, '0') + '-' + pad(mm) + '-' + pad(dd));
    }

    /* ---------- rendering ---------- */

    ensureYears() {
      const year = new Date().getFullYear();
      const viewed = this.view.getFullYear();
      const min = this.min ? this.min.getFullYear() : Math.max(1, Math.min(year - this.yearSpan + 1, viewed));
      const max = this.max ? this.max.getFullYear() : Math.min(9999, Math.max(year + 10, viewed));
      if (min === this.minYear && max === this.maxYear) return;
      this.minYear = min;
      this.maxYear = max;
      this.yearSelect.replaceChildren();
      for (let y = max; y >= min; y--) {
        const option = document.createElement('option');
        option.value = String(y);
        option.textContent = String(y);
        this.yearSelect.appendChild(option);
      }
    }

    renderGrid() {
      this.ensureYears();
      const y = this.view.getFullYear();
      const m = this.view.getMonth();
      this.monthSelect.value = String(m);
      this.yearSelect.value = String(y);
      // Property changes don't fire `change`; nudge the custom dropdowns.
      this.monthSelect.dispatchEvent(new CustomEvent('bd-select:sync', { bubbles: true }));
      this.yearSelect.dispatchEvent(new CustomEvent('bd-select:sync', { bubbles: true }));

      this.gridBody.textContent = '';
      const first = new Date(y, m, 1);
      const offset = (first.getDay() - this.weekStart + 7) % 7;
      const gridStart = new Date(y, m, 1 - offset);
      const today = startOfDay(new Date());

      for (let w = 0; w < 6; w++) {
        const tr = document.createElement('tr');
        tr.setAttribute('role', 'row');
        for (let i = 0; i < 7; i++) {
          const date = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + w * 7 + i);
          const td = document.createElement('td');
          td.dataset.date = toISO(date);
          td.setAttribute('role', 'gridcell');
          td.setAttribute('aria-label', new Intl.DateTimeFormat(this.locale, { dateStyle: 'full' }).format(date));
          const inSelectedRange = this.range && this.selected && this.rangeEnd && date >= this.selected && date <= this.rangeEnd;
          td.setAttribute('aria-selected', String(Boolean(inSelectedRange) || sameDay(date, this.selected)));
          td.className = 'dp-cell';
          const span = document.createElement('span');
          span.className = 'dp-day';
          span.textContent = String(date.getDate());
          td.appendChild(span);
          if (date.getMonth() !== m) td.classList.add('is-outmonth');
          if (sameDay(date, today)) {
            td.classList.add('is-today');
            td.setAttribute('aria-current', 'date');
          }
          if (inSelectedRange) td.classList.add('is-in-range');
          if (sameDay(date, this.selected)) td.classList.add('is-range-start');
          if (this.range && sameDay(date, this.rangeEnd)) td.classList.add('is-range-end');
          if (sameDay(date, this.selected) || (this.range && sameDay(date, this.rangeEnd))) {
            td.classList.add('is-selected');
            td.setAttribute('aria-selected', 'true');
          }
          if (!this.inRange(date)) {
            td.classList.add('is-disabled');
            td.setAttribute('aria-disabled', 'true');
          }
          tr.appendChild(td);
        }
        this.gridBody.appendChild(tr);
      }

      // Roving tabindex: the focusDate cell (when rendered and enabled) is
      // the only tab stop; fall back to the 1st of the viewed month.
      const cells = this.gridBody.querySelectorAll('td[data-date]');
      let focusable = null;
      cells.forEach((cell) => {
        const hit = cell.dataset.date === toISO(this.focusDate) && !cell.classList.contains('is-disabled');
        cell.tabIndex = hit ? 0 : -1;
        if (hit) focusable = cell;
      });
      if (!focusable) {
        focusable = this.gridBody.querySelector('td[data-date]:not(.is-disabled):not(.is-outmonth)');
        if (focusable) focusable.tabIndex = 0;
      }
      if (this.countsUrl && this.isOpen) this.loadCounts();
      return focusable;
    }

    /* Counts are advisory: never change date validity or the selection model. */
    paintCounts() {
      this.gridBody.querySelectorAll('[data-date]').forEach(cell => {
        cell.querySelector('.dp-count-badge')?.remove();
        const dateLabel = new Intl.DateTimeFormat(this.locale, { dateStyle: 'full' }).format(parseISO(cell.dataset.date));
        const count = this.counts ? (this.counts[cell.dataset.date] || 0) : null;
        cell.setAttribute('aria-label', dateLabel + (count === null ? ', appointment counts unavailable' : ', ' + count + ' scheduled appointment' + (count === 1 ? '' : 's')));
        if (count > 0) {
          const badge = document.createElement('span');
          badge.className = 'dp-count-badge';
          badge.setAttribute('aria-hidden', 'true');
          badge.textContent = count > 99 ? '99+' : String(count);
          cell.appendChild(badge);
        }
      });
    }

    cancelCounts() {
      clearTimeout(this.countsTimer);
      this.countsTimer = null;
      this.countsRequest?.abort();
      this.countsRequest = null;
      this.countsRange = '';
      this.counts = null;
    }

    async loadCounts(force = false) {
      if (!this.isOpen || !this.input.isConnected) return;
      const cells = this.gridBody.querySelectorAll('[data-date]');
      const from = cells[0].dataset.date;
      const to = cells[cells.length - 1].dataset.date;
      const range = from + '/' + to;
      if (!force && this.countsRange === range) { this.paintCounts(); return; }
      this.cancelCounts();
      this.countsRange = range;
      const request = new AbortController();
      this.countsRequest = request;
      this.countsStatus.textContent = 'Loading appointment counts…';
      this.paintCounts();
      try {
        const url = new URL(this.countsUrl, location.href);
        if (url.origin !== location.origin) throw new Error('Counts must be same-origin');
        url.searchParams.set('from', from);
        url.searchParams.set('to', to);
        const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', signal: request.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Counts request failed');
        const payload = await response.json();
        const counts = payload?.data?.counts;
        if (payload?.success !== true || !counts || typeof counts !== 'object' || Array.isArray(counts) ||
            Object.entries(counts).some(([date, count]) => !parseISO(date) || !Number.isSafeInteger(count) || count <= 0)) throw new Error('Invalid counts');
        if (this.countsRequest !== request || !this.isOpen || !this.input.isConnected) return;
        this.counts = counts;
        this.countsStatus.textContent = 'Scheduled appointment counts shown.';
        this.paintCounts();
      } catch (error) {
        if (this.countsRequest !== request || !this.isOpen || !this.input.isConnected) return;
        this.countsStatus.textContent = 'Appointment counts unavailable. You can still choose a date.';
        this.paintCounts();
      } finally {
        if (this.countsRequest === request) this.countsRequest = null;
      }
    }

    /* ---------- open / close / position ---------- */

    toggle() { this.isOpen ? this.closePopup(true) : this.openPopup(); }

    openPopup() {
      if (this.isOpen || this.input.matches(':disabled') || this.input.readOnly) return;
      if (openInstance && openInstance !== this) openInstance.closePopup(false);
      openInstance = this;
      this.isOpen = true;
      this.wrap.classList.add('dp-open');
      if (this.selected) {
        this.view = new Date(this.selected);
        this.focusDate = new Date(this.selected);
      } else {
        this.view = startOfDay(new Date());
        this.focusDate = new Date(this.view);
      }
      if (this.min && this.view < this.min) this.view = new Date(this.min);
      if (this.max && this.view > this.max) this.view = new Date(this.max);
      this.focusDate = new Date(this.view);
      this.renderGrid();
      this.popup.hidden = false;
      this.input.setAttribute('aria-expanded', 'true');
      this.position();
      this.help.textContent = (this.range ? 'Choose a start date, then an end date. ' : '') + 'Arrow keys move by day, Home and End jump the week, Page Up and Page Down change month, Shift with them changes year, Enter selects, Escape closes.';
      const cell = this.gridBody.querySelector('td[tabindex="0"]');
      if (cell) cell.focus();
    }

    closePopup(refocus) {
      if (!this.isOpen) return;
      this.isOpen = false;
      if (this.countsUrl) this.cancelCounts();
      this.wrap.classList.remove('dp-open');
      this.popup.hidden = true;
      this.input.setAttribute('aria-expanded', 'false');
      if (openInstance === this) openInstance = null;
      if (refocus) this.input.focus();
    }

    position() {
      const rect = this.input.getBoundingClientRect();
      const popupRect = this.popup.getBoundingClientRect();
      const margin = 8;
      let top = rect.bottom + 6;
      if (top + popupRect.height > window.innerHeight - margin &&
          rect.top - popupRect.height - 6 >= margin) {
        top = rect.top - popupRect.height - 6;
      }
      top = Math.max(margin, Math.min(top, window.innerHeight - popupRect.height - margin));
      let left = Math.max(margin, Math.min(rect.left, window.innerWidth - popupRect.width - margin));
      this.popup.style.top = Math.round(top) + 'px';
      this.popup.style.left = Math.round(left) + 'px';
    }

    /* ---------- navigation ---------- */

    shiftView(amount) {
      this.view = shiftMonth(this.view, amount);
      this.alignFocusToView();
      this.renderGrid();
    }

    alignFocusToView() {
      const last = new Date(this.view.getFullYear(), this.view.getMonth() + 1, 0).getDate();
      this.focusDate = new Date(this.view.getFullYear(), this.view.getMonth(), Math.min(this.focusDate.getDate(), last));
    }

    focusCell(date) {
      this.focusDate = date;
      this.view = new Date(date.getFullYear(), date.getMonth(), 1);
      const cell = this.renderGrid();
      if (cell) cell.focus();
    }

    gridKeydown(e) {
      const cell = e.target.closest('td[data-date]');
      if (!cell) return;
      const current = parseISO(cell.dataset.date);
      let target = null;

      switch (e.key) {
        case 'ArrowLeft': target = addDays(current, -1); break;
        case 'ArrowRight': target = addDays(current, 1); break;
        case 'ArrowUp': target = addDays(current, -7); break;
        case 'ArrowDown': target = addDays(current, 7); break;
        case 'Home': target = addDays(current, -((current.getDay() - this.weekStart + 7) % 7)); break;
        case 'End': target = addDays(current, (6 - (current.getDay() - this.weekStart + 7) % 7)); break;
        case 'PageUp': target = shiftMonth(current, e.shiftKey ? -12 : -1); break;
        case 'PageDown': target = shiftMonth(current, e.shiftKey ? 12 : 1); break;
        case 'Enter':
        case ' ':
          e.preventDefault();
          if (!cell.classList.contains('is-disabled')) this.select(current);
          return;
        default:
          return; // let other keys (Tab, etc.) behave normally
      }
      e.preventDefault();
      if (this.min && target < this.min) target = new Date(this.min);
      if (this.max && target > this.max) target = new Date(this.max);
      this.focusCell(target);
    }

    destroy() {
      this.closePopup(false);
      this.events.abort();
      this.observer.disconnect();
      if (window.BalaySelect) window.BalaySelect.destroy(this.popup);
      this.popup.remove();
      this.wrap.replaceWith(this.input);
      this.input.removeAttribute('aria-controls');
      this.input.removeAttribute('aria-haspopup');
      this.input.removeAttribute('aria-expanded');
      this.input.setCustomValidity('');
      instances.delete(this.input);
    }
  }

  function init(root = document) {
    const fields = root.querySelectorAll('input[data-datepicker]');
    if (root.matches?.('input[data-datepicker]') && !instances.has(root)) new Datepicker(root);
    fields.forEach(input => { if (!instances.has(input)) new Datepicker(input); });
  }

  window.BalayDatepicker = { Datepicker, init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
  document.addEventListener('htmx:load', event => init(event.detail?.elt || event.target));
  new MutationObserver(() => {
    instances.forEach((instance, input) => { if (!input.isConnected) instance.destroy(); });
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
