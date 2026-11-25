/**
 * BalayDateRangePicker — an independent, range-first calendar component.
 *
 * <input data-daterangepicker data-target="#from" data-range-end="#to" data-max-days="31">
 * <input type="hidden" id="from" name="from"><input type="hidden" id="to" name="to">
 *
 * Two linked months are shown when the viewport and containing workspace allow
 * it; a narrow workspace uses one. The first pick keeps the calendar open and
 * clears both ISO mirrors until a valid end date is selected. Hover/focus is
 * preview-only. Calendar arithmetic uses UTC dates, not elapsed local hours.
 *
 * Optional data-min/data-max (ISO or "today"), data-order (dmy/mdy), data-locale,
 * data-week-start (Monday by default), and data-year-span match the date picker.
 */
(function () {
  'use strict';

  const DAY = 86400000;
  const instances = new Map();
  let openInstance = null;
  let nextId = 0;

  function pad(value) { return String(value).padStart(2, '0'); }
  function dateFor(year, month, day) {
    const date = new Date(0);
    date.setUTCFullYear(year, month, day);
    date.setUTCHours(0, 0, 0, 0);
    return date;
  }
  function toISO(date) {
    return String(date.getUTCFullYear()).padStart(4, '0') + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate());
  }
  function parseISO(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec((value || '').trim());
    if (!match) return null;
    const year = Number(match[1]), month = Number(match[2]) - 1, day = Number(match[3]);
    if (year < 1 || year > 9999 || month < 0 || month > 11 || day < 1) return null;
    const date = dateFor(year, month, day);
    return date.getUTCFullYear() === year && date.getUTCMonth() === month && date.getUTCDate() === day ? date : null;
  }
  function today() {
    const now = new Date();
    return dateFor(now.getFullYear(), now.getMonth(), now.getDate());
  }
  function monthOf(date) { return dateFor(date.getUTCFullYear(), date.getUTCMonth(), 1); }
  function addDays(date, days) { return new Date(date.getTime() + days * DAY); }
  function shiftMonth(date, months) {
    const first = dateFor(date.getUTCFullYear(), date.getUTCMonth() + months, 1);
    const last = dateFor(first.getUTCFullYear(), first.getUTCMonth() + 1, 0).getUTCDate();
    return dateFor(first.getUTCFullYear(), first.getUTCMonth(), Math.min(date.getUTCDate(), last));
  }
  function sameDay(a, b) { return Boolean(a && b && a.getTime() === b.getTime()); }
  function inclusiveDays(start, end) { return Math.round((end - start) / DAY) + 1; }
  function bound(value) { return value === 'today' ? today() : parseISO(value); }
  function targetFor(selector) {
    if (!selector) return null;
    try {
      const matches = document.querySelectorAll(selector);
      return matches.length === 1 && matches[0].matches('input[type="hidden"]') ? matches[0] : null;
    } catch { return null; }
  }

  class DateRangePicker {
    constructor(input, startTarget, endTarget) {
      this.input = input;
      this.startTarget = startTarget;
      this.endTarget = endTarget;
      this.id = 'synapse-range-' + (++nextId);
      this.events = new AbortController();
      this.locale = input.dataset.locale || document.documentElement.lang || navigator.language || 'en';
      this.order = input.dataset.order === 'mdy' ? 'mdy' : 'dmy';
      this.weekStart = input.dataset.weekStart === '0' ? 0 : 1;
      this.yearSpan = Math.max(1, Math.min(500, Number(input.dataset.yearSpan) || 100));
      this.dateFormat = new Intl.DateTimeFormat(this.locale, { dateStyle: 'full', timeZone: 'UTC' });
      this.monthFormat = new Intl.DateTimeFormat(this.locale, { month: 'long', timeZone: 'UTC' });
      this.titleFormat = new Intl.DateTimeFormat(this.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' });
      this.weekdayFormat = new Intl.DateTimeFormat(this.locale, { weekday: 'short', timeZone: 'UTC' });
      this.weekdayLongFormat = new Intl.DateTimeFormat(this.locale, { weekday: 'long', timeZone: 'UTC' });
      this.initialValues = [startTarget.value, endTarget.value];
      this.initialDisabled = [startTarget.disabled, endTarget.disabled];
      this.originalAttributes = new Map(['aria-haspopup', 'aria-controls', 'aria-expanded', 'aria-describedby', 'aria-invalid', 'autocomplete', 'inputmode', 'maxlength', 'placeholder']
        .map(name => [name, input.getAttribute(name)]));
      this.originalInvalid = input.classList.contains('is-invalid');
      this.originalValidity = input.validity.customError ? input.validationMessage : '';
      this.view = monthOf(today());
      this.focusDate = today();
      this.phase = 'start';
      this.preview = null;
      this.isOpen = false;
      this.dual = false;
      this.notifying = false;
      this.frame = null;
      this.resetTimer = null;
      this.container = input.closest('[data-daterange-container], .modal-dialog, .gs-workspace, .portal-engagement, .app-modal, main') || document.documentElement;
      this.readOptions();
      this.build();
      this.bind();
      this.syncFromTargets();
      this.lastNotified = this.valueKey();
      this.observe();
      instances.set(input, this);
    }

    readOptions() {
      this.min = bound(this.input.dataset.min || this.input.getAttribute('min'));
      this.max = bound(this.input.dataset.max || this.input.getAttribute('max'));
      const days = Number(this.input.dataset.maxDays);
      this.maxDays = Number.isSafeInteger(days) && days > 0 ? days : Infinity;
    }

    /* ---------- construction ---------- */

    build() {
      this.wrap = document.createElement('div');
      this.wrap.className = 'drp-wrap';
      this.input.before(this.wrap);
      this.wrap.append(this.input);
      const icon = document.createElement('i');
      icon.className = 'bi bi-calendar-range drp-icon';
      icon.setAttribute('aria-hidden', 'true');
      this.wrap.append(icon);
      this.input.setAttribute('aria-haspopup', 'dialog');
      this.input.setAttribute('aria-controls', this.id);
      this.input.setAttribute('aria-expanded', 'false');
      this.input.setAttribute('autocomplete', 'off');
      this.input.setAttribute('inputmode', 'numeric');
      this.input.setAttribute('maxlength', '23');
      if (!this.input.placeholder) this.input.placeholder = this.order === 'mdy' ? 'MM/DD/YYYY – MM/DD/YYYY' : 'DD/MM/YYYY – DD/MM/YYYY';

      this.popup = document.createElement('div');
      this.popup.className = 'drp-popup';
      this.popup.id = this.id;
      this.popup.hidden = true;
      this.popup.setAttribute('role', 'dialog');
      this.popup.setAttribute('aria-modal', 'false');
      this.popup.setAttribute('aria-label', 'Choose date range');
      this.popup.innerHTML =
        '<div class="drp-head"><h2 class="drp-heading">Choose date range</h2>' +
        '<button type="button" class="drp-close drp-control" data-drp-close aria-label="Close date range picker"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>' +
        '<div class="drp-summary">' +
        '<button type="button" class="drp-endpoint" data-drp-start aria-label="Start date" aria-pressed="true"><span class="drp-label">Start date</span><strong class="drp-value">Not selected</strong></button>' +
        '<button type="button" class="drp-endpoint" data-drp-end aria-label="End date" aria-pressed="false"><span class="drp-label">End date</span><strong class="drp-value">Not selected</strong></button></div>' +
        '<p class="drp-status" id="' + this.id + '-status" role="status" aria-live="polite" aria-atomic="true"></p>' +
        '<div class="drp-navigation">' +
        '<button type="button" class="drp-nav drp-control" data-drp-prev aria-label="Previous month"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>' +
        '<select id="' + this.id + '-month-select" class="drp-month form-select" data-drp-month data-no-search aria-label="Month"></select>' +
        '<select id="' + this.id + '-year-select" class="drp-year form-select" data-drp-year data-no-search aria-label="Year"></select>' +
        '<button type="button" class="drp-nav drp-control" data-drp-next aria-label="Next month"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div>' +
        '<div class="drp-calendars"></div>' +
        '<div class="drp-foot"><button type="button" class="drp-today drp-control" data-drp-today><i class="bi bi-crosshair2" aria-hidden="true"></i>Today</button>' +
        '<span class="drp-selection-count"></span><button type="button" class="drp-clear drp-control" data-drp-clear><i class="bi bi-x-lg" aria-hidden="true"></i>Clear</button></div>' +
        '<p class="drp-instructions" id="' + this.id + '-instructions">Select a start date, then an end date. Arrow keys move by day or week. Home and End move within a week. Page Up and Page Down change month; hold Shift to change year. Enter or Space selects a date. Escape closes the calendar.</p>';
      (this.input.closest('.modal') || document.body).append(this.popup);
      const describedBy = this.originalAttributes.get('aria-describedby');
      this.input.setAttribute('aria-describedby', [describedBy, this.id + '-instructions'].filter(Boolean).join(' '));
      this.popup.setAttribute('aria-describedby', this.id + '-instructions');
      this.status = this.popup.querySelector('.drp-status');
      this.startButton = this.popup.querySelector('[data-drp-start]');
      this.endButton = this.popup.querySelector('[data-drp-end]');
      this.monthSelect = this.popup.querySelector('[data-drp-month]');
      this.yearSelect = this.popup.querySelector('[data-drp-year]');
      this.calendars = this.popup.querySelector('.drp-calendars');
      this.count = this.popup.querySelector('.drp-selection-count');
      this.panels = [];
      for (let index = 0; index < 2; index++) {
        const panel = document.createElement('section');
        panel.className = 'drp-calendar';
        panel.dataset.drpPanel = String(index);
        panel.hidden = index === 1;
        panel.innerHTML = '<h3 class="drp-month-title" id="' + this.id + '-month-' + index + '"></h3>' +
          '<table class="drp-grid" role="grid" aria-multiselectable="true" aria-labelledby="' + this.id + '-month-' + index + '"><thead><tr role="row"></tr></thead><tbody></tbody></table>';
        const weekdays = panel.querySelector('thead tr');
        for (let day = 0; day < 7; day++) {
          const reference = dateFor(2023, 0, 1 + (day + this.weekStart) % 7);
          const heading = document.createElement('th');
          heading.scope = 'col';
          heading.setAttribute('abbr', this.weekdayLongFormat.format(reference));
          heading.textContent = this.weekdayFormat.format(reference).replace('.', '');
          weekdays.append(heading);
        }
        this.calendars.append(panel);
        this.panels.push(panel);
      }
      for (let month = 0; month < 12; month++) {
        const option = document.createElement('option');
        option.value = String(month);
        option.textContent = this.monthFormat.format(dateFor(2023, month, 1));
        this.monthSelect.append(option);
      }
      this.ensureYears();
      window.BalaySelect?.init(this.popup);
    }

    /* ---------- event and lifecycle ownership ---------- */

    bind() {
      const on = (element, event, handler, options = {}) => element.addEventListener(event, handler, { ...options, signal: this.events.signal });
      on(this.input, 'click', () => this.open());
      on(this.input, 'keydown', event => {
        if (event.key === 'ArrowDown') { event.preventDefault(); this.open(); }
        else if (event.key === 'Escape' && this.isOpen) { event.preventDefault(); event.stopPropagation(); this.close(false); }
      });
      on(this.input, 'input', () => {
        if (this.notifying) return;
        this.preview = null;
        this.validateText();
        this.phase = this.allowed(this.start) && !this.end ? 'end' : 'start';
        if (this.isOpen) { this.render(); this.updateStatus(); }
      });
      on(this.input, 'change', () => { if (!this.notifying) this.commitText(); });
      on(this.input, 'blur', () => { if (!this.notifying) this.commitText(); });
      on(this.popup.querySelector('[data-drp-close]'), 'click', () => this.close(true));
      on(this.popup.querySelector('[data-drp-clear]'), 'click', () => this.clear());
      on(this.popup.querySelector('[data-drp-today]'), 'click', () => {
        const date = today();
        if (!this.allowed(date)) return;
        this.preview = null;
        this.focusDate = date;
        this.view = monthOf(date);
        this.render();
        this.position();
        this.focusCell();
      });
      on(this.startButton, 'click', () => {
        if (this.disabled()) return;
        this.start = this.end = this.preview = null;
        this.phase = 'start';
        this.commitSelection();
        this.render();
        this.updateStatus();
      });
      on(this.endButton, 'click', () => {
        if (this.disabled() || !this.start) return;
        this.end = this.preview = null;
        this.phase = 'end';
        this.commitSelection();
        this.render();
        this.updateStatus();
      });
      on(this.popup.querySelector('[data-drp-prev]'), 'click', () => this.moveView(-1));
      on(this.popup.querySelector('[data-drp-next]'), 'click', () => this.moveView(1));
      on(this.monthSelect, 'change', () => {
        this.view = dateFor(this.view.getUTCFullYear(), Number(this.monthSelect.value), 1);
        this.moveView(0);
      });
      on(this.yearSelect, 'change', () => {
        this.view = dateFor(Number(this.yearSelect.value), this.view.getUTCMonth(), 1);
        this.moveView(0);
      });
      on(this.calendars, 'click', event => {
        const cell = event.target.closest('.drp-cell[data-date]');
        if (cell) this.select(parseISO(cell.dataset.date));
      });
      on(this.calendars, 'pointerover', event => {
        const cell = event.target.closest('.drp-cell[data-date]');
        if (cell) this.previewDate(parseISO(cell.dataset.date));
        else { this.preview = null; this.paint(); this.updateStatus(); }
      });
      on(this.calendars, 'pointerleave', () => { this.preview = null; this.paint(); this.updateStatus(); });
      on(this.calendars, 'focusin', event => {
        const cell = event.target.closest('.drp-cell[data-date]');
        if (cell) {
          this.focusDate = parseISO(cell.dataset.date);
          this.previewDate(this.focusDate);
        }
      });
      on(this.calendars, 'focusout', event => {
        if (!event.relatedTarget?.closest('.drp-cell[data-date]')) { this.preview = null; this.paint(); this.updateStatus(); }
      });
      on(this.calendars, 'keydown', event => this.gridKeydown(event));
      on(this.popup, 'keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        const toggle = this.popup.querySelector('.bd-select-toggle[aria-expanded="true"]');
        if (toggle && window.bootstrap) {
          window.bootstrap.Dropdown.getInstance(toggle)?.hide();
          toggle.focus({ preventScroll: true });
        } else this.close(true);
      });
      on(document, 'pointerdown', event => {
        if (this.isOpen && !this.wrap.contains(event.target) && !this.popup.contains(event.target)) this.close(false);
      });
      on(document, 'focusin', event => {
        if (this.isOpen && !this.wrap.contains(event.target) && !this.popup.contains(event.target)) this.close(false);
      });
      on(window, 'resize', () => this.queuePosition());
      on(window, 'scroll', event => {
        // Keep the first pick open even if focusing a day scrolls its workspace.
        if (event.target === document || event.target.contains?.(this.wrap)) this.queuePosition();
      }, { capture: true, passive: true });
      on(document, 'synapse:before-navigate', () => this.close(false));
      on(window, 'pagehide', () => this.close(false));
      const modal = this.input.closest('.modal');
      if (modal) on(modal, 'hide.bs.modal', () => this.close(false));
      [this.startTarget, this.endTarget].forEach(target => {
        on(target, 'change', () => { if (!this.notifying) this.syncFromTargets(); });
        on(target, 'dp:sync', () => { if (!this.notifying) this.syncFromTargets(); });
        on(target, 'drp:sync', () => { if (!this.notifying) this.syncFromTargets(); });
      });
      if (this.input.form) on(this.input.form, 'reset', event => {
        clearTimeout(this.resetTimer);
        this.resetTimer = setTimeout(() => {
          this.resetTimer = null;
          if (event.defaultPrevented || !this.input.isConnected) return;
          this.startTarget.value = this.initialValues[0];
          this.endTarget.value = this.initialValues[1];
          this.syncFromTargets();
          this.notifying = true;
          try { [this.startTarget, this.endTarget].forEach(target => target.dispatchEvent(new Event('input', { bubbles: true }))); }
          finally { this.notifying = false; }
          this.lastNotified = this.valueKey();
          this.close(false);
        }, 0);
      });
    }

    observe() {
      this.observer = new MutationObserver(() => {
        this.readOptions();
        this.syncDisabled();
        this.validateText();
        if (this.disabled()) this.close(false);
        else if (this.isOpen) { this.render(); this.position(); }
      });
      this.observer.observe(this.input, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'data-min', 'data-max', 'min', 'max', 'data-max-days'] });
      for (let fieldset = this.input.closest('fieldset'); fieldset; fieldset = fieldset.parentElement?.closest('fieldset')) {
        this.observer.observe(fieldset, { attributes: true, attributeFilter: ['disabled'] });
      }
      if (window.ResizeObserver) {
        this.resizeObserver = new ResizeObserver(() => this.queuePosition());
        this.resizeObserver.observe(this.container);
      }
      this.syncDisabled();
    }

    disabled() { return this.input.matches(':disabled') || this.input.readOnly; }
    syncDisabled() {
      [this.startTarget, this.endTarget].forEach((target, index) => { target.disabled = this.input.matches(':disabled') || this.initialDisabled[index]; });
    }

    /* ---------- values: previews never publish a date ---------- */

    format(date) {
      if (!date) return '';
      const day = pad(date.getUTCDate()), month = pad(date.getUTCMonth() + 1), year = String(date.getUTCFullYear()).padStart(4, '0');
      return (this.order === 'mdy' ? month + '/' + day : day + '/' + month) + '/' + year;
    }
    parseTextDate(text) {
      if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return parseISO(text);
      const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text);
      const digits = /^\d{8}$/.test(text) ? [null, text.slice(0, 2), text.slice(2, 4), text.slice(4)] : match;
      if (!digits) return null;
      const day = this.order === 'mdy' ? digits[2] : digits[1], month = this.order === 'mdy' ? digits[1] : digits[2];
      return parseISO(digits[3] + '-' + pad(month) + '-' + pad(day));
    }
    allowed(date) {
      return Boolean(date && date.getUTCFullYear() >= 1 && date.getUTCFullYear() <= 9999 && (!this.min || date >= this.min) && (!this.max || date <= this.max));
    }
    rangeError(start, end) {
      if (end < start || !this.allowed(start) || !this.allowed(end)) return 'Choose an ordered range within the allowed dates.';
      if (inclusiveDays(start, end) > this.maxDays) return 'Choose at most ' + this.maxDays + ' calendar days, including both dates.';
      return '';
    }
    validateText() {
      this.readOptions();
      const text = this.input.value.trim(), parts = text.split(/\s*–\s*|\s+-\s+/);
      this.start = this.parseTextDate(parts[0] || '');
      this.end = parts.length === 2 ? this.parseTextDate(parts[1] || '') : null;
      let error = '';
      if (!text && this.input.required) error = 'Choose a start and end date.';
      else if (text && (parts.length !== 2 || !this.start || !this.end)) error = 'Enter both dates in ' + (this.order === 'mdy' ? 'MM/DD/YYYY – MM/DD/YYYY' : 'DD/MM/YYYY – DD/MM/YYYY') + ' format.';
      else if (this.start && this.end) error = this.rangeError(this.start, this.end);
      this.complete = Boolean(this.start && this.end && !error);
      this.input.setCustomValidity(error);
      this.input.classList.toggle('is-invalid', Boolean(error));
      this.input.setAttribute('aria-invalid', String(Boolean(error)));
      this.updateTargets();
      return !error;
    }
    updateTargets() {
      const values = [this.complete ? toISO(this.start) : '', this.complete ? toISO(this.end) : ''];
      const changed = [this.startTarget, this.endTarget].filter((target, index) => target.value !== values[index]);
      // Both values must be current before Alpine, HTMX or form listeners read them.
      this.startTarget.value = values[0];
      this.endTarget.value = values[1];
      this.notifying = true;
      try { changed.forEach(target => target.dispatchEvent(new Event('input', { bubbles: true }))); }
      finally { this.notifying = false; }
    }
    valueKey() { return this.startTarget.value + '|' + this.endTarget.value; }
    notifyChange(visible) {
      const value = this.valueKey();
      if (value === this.lastNotified) return;
      this.lastNotified = value;
      this.notifying = true;
      try {
        [this.startTarget, this.endTarget].forEach(target => target.dispatchEvent(new Event('change', { bubbles: true })));
        if (visible) {
          this.input.dispatchEvent(new Event('input', { bubbles: true }));
          this.input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      } finally { this.notifying = false; }
    }
    syncFromTargets() {
      this.preview = null;
      const values = [this.startTarget.value, this.endTarget.value];
      this.input.value = values.some(Boolean) ? values.map(value => this.format(parseISO(value)) || value).join(' – ') : '';
      this.validateText();
      this.phase = this.allowed(this.start) && !this.end ? 'end' : 'start';
      if (this.isOpen) { this.render(); this.updateStatus(); }
    }
    refreshDisplay() { this.input.value = this.start || this.end ? this.format(this.start) + ' – ' + this.format(this.end) : ''; }
    commitText() {
      if (this.validateText()) this.refreshDisplay();
      this.notifyChange(false);
    }
    commitSelection() {
      this.refreshDisplay();
      this.validateText();
      this.notifyChange(true);
    }
    clear() {
      if (this.disabled()) return;
      this.start = this.end = this.preview = null;
      this.phase = 'start';
      this.commitSelection();
      this.render();
      this.close(true);
    }
    select(date) {
      this.readOptions();
      if (this.disabled() || !this.allowed(date)) return;
      this.preview = null;
      if (this.phase === 'end' && this.start) {
        const start = date < this.start ? date : this.start, end = date < this.start ? this.start : date;
        const error = this.rangeError(start, end);
        if (error) { this.updateStatus(error); return; }
        this.start = start;
        this.end = end;
        this.phase = 'start';
        this.commitSelection();
        this.paint();
        this.close(true);
      } else {
        this.start = date;
        this.end = null;
        this.phase = 'end';
        this.focusDate = date;
        this.commitSelection();
        this.render();
        this.updateStatus();
        this.focusCell();
      }
    }
    previewDate(date) {
      if (this.phase !== 'end' || !this.start || !this.allowed(date) || sameDay(date, this.start)) {
        this.preview = null;
        this.paint();
        this.updateStatus();
        return;
      }
      const start = date < this.start ? date : this.start, end = date < this.start ? this.start : date;
      const error = this.rangeError(start, end);
      this.preview = error ? null : date;
      this.paint();
      this.updateStatus(error || 'Preview: ' + this.format(start) + ' – ' + this.format(end) + ' · ' + inclusiveDays(start, end) + ' days. Select to confirm.');
    }

    /* ---------- linked month rendering ---------- */

    ensureYears() {
      const current = today().getUTCFullYear(), viewed = this.view.getUTCFullYear();
      const min = this.min ? this.min.getUTCFullYear() : Math.max(1, Math.min(current - this.yearSpan + 1, viewed));
      const max = this.max ? this.max.getUTCFullYear() : Math.min(9999, Math.max(current + 10, viewed));
      if (min === this.minYear && max === this.maxYear) return;
      this.minYear = min;
      this.maxYear = max;
      this.yearSelect.replaceChildren();
      for (let year = max; year >= min; year--) {
        const option = document.createElement('option');
        option.value = String(year);
        option.textContent = String(year);
        this.yearSelect.append(option);
      }
    }
    clampView() {
      const lower = this.min ? monthOf(this.min) : dateFor(1, 0, 1);
      const upper = this.max ? monthOf(this.max) : dateFor(9999, this.dual ? 10 : 11, 1);
      if (this.view < lower) this.view = lower;
      if (this.view > upper) this.view = upper;
      if (this.dual && this.view > dateFor(9999, 10, 1)) this.view = dateFor(9999, 10, 1);
    }
    render() {
      const restoreFocus = this.isOpen && this.calendars.contains(document.activeElement);
      this.clampView();
      this.ensureYears();
      this.monthSelect.value = String(this.view.getUTCMonth());
      this.yearSelect.value = String(this.view.getUTCFullYear());
      [this.monthSelect, this.yearSelect].forEach(select => select.dispatchEvent(new CustomEvent('bd-select:sync', { bubbles: true })));
      const current = today();
      this.panels.forEach((panel, index) => {
        panel.hidden = index === 1 && !this.dual;
        const month = shiftMonth(this.view, index), year = month.getUTCFullYear(), number = month.getUTCMonth();
        panel.querySelector('.drp-month-title').textContent = this.titleFormat.format(month);
        const body = panel.querySelector('tbody');
        body.replaceChildren();
        if (panel.hidden) return;
        const firstDay = (month.getUTCDay() - this.weekStart + 7) % 7;
        const lastDay = dateFor(year, number + 1, 0).getUTCDate();
        for (let week = 0; week < 6; week++) {
          const row = document.createElement('tr');
          row.setAttribute('role', 'row');
          for (let day = 0; day < 7; day++) {
            const value = week * 7 + day - firstDay + 1, cell = document.createElement('td');
            cell.setAttribute('role', 'gridcell');
            if (value < 1 || value > lastDay) {
              cell.className = 'drp-empty';
              cell.setAttribute('aria-disabled', 'true');
            } else {
              const date = dateFor(year, number, value);
              cell.className = 'drp-cell';
              cell.dataset.date = toISO(date);
              cell.setAttribute('aria-label', this.dateFormat.format(date));
              cell.tabIndex = -1;
              if (sameDay(date, current)) { cell.classList.add('is-today'); cell.setAttribute('aria-current', 'date'); }
              if (!this.allowed(date)) { cell.classList.add('is-disabled'); cell.setAttribute('aria-disabled', 'true'); }
              const label = document.createElement('span');
              label.className = 'drp-day';
              label.textContent = String(value);
              cell.append(label);
            }
            row.append(cell);
          }
          body.append(row);
        }
      });
      const cells = [...this.calendars.querySelectorAll('.drp-cell:not(.is-disabled)')];
      const focused = cells.find(cell => cell.dataset.date === toISO(this.focusDate)) || cells[0];
      if (focused) focused.tabIndex = 0;
      this.popup.querySelector('[data-drp-prev]').disabled = this.min ? this.view <= monthOf(this.min) : this.view <= dateFor(1, 0, 1);
      this.popup.querySelector('[data-drp-next]').disabled = this.max ? this.view >= monthOf(this.max) : this.view >= dateFor(9999, this.dual ? 10 : 11, 1);
      this.popup.querySelector('[data-drp-today]').disabled = !this.allowed(current);
      this.paint();
      this.updateStatus();
      if (restoreFocus) focused?.focus({ preventScroll: true });
    }
    paint() {
      const previewStart = this.preview && this.start ? (this.preview < this.start ? this.preview : this.start) : null;
      const previewEnd = this.preview && this.start ? (this.preview < this.start ? this.start : this.preview) : null;
      this.calendars.querySelectorAll('.drp-cell').forEach(cell => {
        const date = parseISO(cell.dataset.date), start = sameDay(date, this.start), end = this.complete && sameDay(date, this.end);
        const selected = Boolean((this.complete && date >= this.start && date <= this.end) || (start && this.allowed(this.start)));
        const preview = Boolean(previewStart && date >= previewStart && date <= previewEnd);
        cell.classList.toggle('is-selected', start || Boolean(end));
        cell.classList.toggle('is-range-start', start);
        cell.classList.toggle('is-range-end', Boolean(end));
        cell.classList.toggle('is-in-range', Boolean(this.complete && date >= this.start && date <= this.end));
        cell.classList.toggle('is-preview', preview);
        cell.classList.toggle('is-preview-start', preview && sameDay(date, previewStart));
        cell.classList.toggle('is-preview-end', preview && sameDay(date, previewEnd));
        cell.setAttribute('aria-selected', String(selected));
      });
      this.startButton.querySelector('.drp-value').textContent = this.format(this.start) || 'Not selected';
      this.endButton.querySelector('.drp-value').textContent = this.format(this.end) || 'Not selected';
      this.startButton.classList.toggle('is-active', this.phase === 'start');
      this.endButton.classList.toggle('is-active', this.phase === 'end');
      this.startButton.setAttribute('aria-pressed', String(this.phase === 'start'));
      this.endButton.setAttribute('aria-pressed', String(this.phase === 'end'));
      this.endButton.disabled = !this.start;
      this.count.textContent = this.complete ? inclusiveDays(this.start, this.end) + ' days inclusive' : '';
    }
    updateStatus(message) {
      if (!message) {
        message = this.phase === 'end' && this.start ? 'Select end date.' : this.complete ? 'Range selected. Select a start date to choose a new range.' : 'Select start date.';
        if (Number.isFinite(this.maxDays)) message += ' Maximum ' + this.maxDays + ' days, inclusive.';
      }
      if (this.status.textContent !== message) this.status.textContent = message;
    }

    /* ---------- focus, position and dismissal ---------- */

    open() {
      if (this.disabled() || this.isOpen) return;
      this.readOptions();
      this.validateText();
      if (openInstance && openInstance !== this) openInstance.close(false);
      openInstance = this;
      this.isOpen = true;
      this.preview = null;
      this.phase = this.allowed(this.start) && !this.end ? 'end' : 'start';
      this.focusDate = this.allowed(this.start) ? this.start : today();
      if (this.min && this.focusDate < this.min) this.focusDate = this.min;
      if (this.max && this.focusDate > this.max) this.focusDate = this.max;
      this.view = monthOf(this.focusDate);
      this.popup.hidden = false;
      this.wrap.classList.add('drp-open');
      this.input.setAttribute('aria-expanded', 'true');
      this.position();
      this.render();
      this.position();
      this.focusCell();
    }
    close(refocus) {
      if (!this.isOpen) return;
      this.isOpen = false;
      this.preview = null;
      this.popup.querySelectorAll('.bd-select-toggle').forEach(toggle => window.bootstrap?.Dropdown?.getInstance(toggle)?.hide());
      this.popup.hidden = true;
      this.wrap.classList.remove('drp-open');
      this.input.setAttribute('aria-expanded', 'false');
      if (openInstance === this) openInstance = null;
      if (this.frame !== null) cancelAnimationFrame(this.frame);
      this.frame = null;
      if (refocus && this.input.isConnected) this.input.focus({ preventScroll: true });
    }
    queuePosition() {
      if (!this.isOpen || this.frame !== null) return;
      this.frame = requestAnimationFrame(() => { this.frame = null; if (this.isOpen) this.position(); });
    }
    position() {
      if (!this.isOpen) return;
      const width = document.documentElement.clientWidth || window.innerWidth;
      const available = this.container.getBoundingClientRect().width;
      const dual = width >= 680 && available >= 648;
      if (dual !== this.dual) {
        this.dual = dual;
        this.popup.classList.toggle('is-dual', dual);
        if (!dual && this.calendars.contains(document.activeElement)) this.view = monthOf(this.focusDate);
        this.render();
      }
      this.popup.style.width = Math.min(dual ? 632 : 320, width - 16) + 'px';
      this.popup.style.maxHeight = Math.max(160, window.innerHeight - 16) + 'px';
      const anchor = this.input.getBoundingClientRect(), popup = this.popup.getBoundingClientRect();
      const below = window.innerHeight - anchor.bottom - 6, above = anchor.top - 6;
      let top = below >= popup.height || below >= above ? anchor.bottom + 6 : anchor.top - popup.height - 6;
      top = Math.max(8, Math.min(top, window.innerHeight - popup.height - 8));
      const left = Math.max(8, Math.min(anchor.left, width - popup.width - 8));
      this.popup.style.top = Math.round(top) + 'px';
      this.popup.style.left = Math.round(left) + 'px';
    }
    focusCell() {
      const cell = this.calendars.querySelector('.drp-cell[tabindex="0"]');
      cell?.focus({ preventScroll: true });
    }
    moveView(amount) {
      this.preview = null;
      this.view = monthOf(shiftMonth(this.view, amount));
      this.clampView();
      this.focusDate = shiftMonth(this.focusDate, (this.view.getUTCFullYear() - this.focusDate.getUTCFullYear()) * 12 + this.view.getUTCMonth() - this.focusDate.getUTCMonth());
      this.render();
      this.position();
    }
    moveFocus(date) {
      if (this.min && date < this.min) date = this.min;
      if (this.max && date > this.max) date = this.max;
      if (!this.allowed(date)) return;
      this.focusDate = date;
      const month = monthOf(date), last = shiftMonth(this.view, this.dual ? 1 : 0);
      if (month < this.view) this.view = month;
      else if (month > last) this.view = monthOf(shiftMonth(month, this.dual ? -1 : 0));
      this.render();
      this.position();
      this.focusCell();
    }
    gridKeydown(event) {
      const cell = event.target.closest('.drp-cell[data-date]');
      if (!cell) return;
      const date = parseISO(cell.dataset.date);
      let next;
      switch (event.key) {
        case 'ArrowLeft': next = addDays(date, -1); break;
        case 'ArrowRight': next = addDays(date, 1); break;
        case 'ArrowUp': next = addDays(date, -7); break;
        case 'ArrowDown': next = addDays(date, 7); break;
        case 'Home': next = addDays(date, -((date.getUTCDay() - this.weekStart + 7) % 7)); break;
        case 'End': next = addDays(date, 6 - (date.getUTCDay() - this.weekStart + 7) % 7); break;
        case 'PageUp': next = shiftMonth(date, event.shiftKey ? -12 : -1); break;
        case 'PageDown': next = shiftMonth(date, event.shiftKey ? 12 : 1); break;
        case 'Enter':
        case ' ': event.preventDefault(); this.select(date); return;
        default: return;
      }
      event.preventDefault();
      this.moveFocus(next);
    }

    destroy() {
      this.close(false);
      this.events.abort();
      this.observer.disconnect();
      this.resizeObserver?.disconnect();
      clearTimeout(this.resetTimer);
      window.BalaySelect?.destroy(this.popup);
      this.popup.remove();
      this.wrap.replaceWith(this.input);
      this.originalAttributes.forEach((value, name) => value === null ? this.input.removeAttribute(name) : this.input.setAttribute(name, value));
      this.input.classList.toggle('is-invalid', this.originalInvalid);
      this.input.setCustomValidity(this.originalValidity);
      [this.startTarget, this.endTarget].forEach((target, index) => { target.disabled = this.initialDisabled[index]; });
      instances.delete(this.input);
    }
  }

  function init(root = document) {
    const fields = [...root.querySelectorAll('input[data-daterangepicker]')];
    if (root.matches?.('input[data-daterangepicker]')) fields.unshift(root);
    fields.forEach(input => {
      if (instances.has(input) || input.hasAttribute('data-datepicker')) return;
      const start = targetFor(input.dataset.target), end = targetFor(input.dataset.rangeEnd);
      if (start && end && start !== end) new DateRangePicker(input, start, end);
    });
  }
  function destroy(root = document) {
    instances.forEach((instance, input) => { if (root === input || root.contains(input)) instance.destroy(); });
  }
  window.BalayDateRangePicker = { DateRangePicker, init, destroy };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
  document.addEventListener('htmx:load', event => init(event.detail?.elt || event.target));
  document.addEventListener('htmx:beforeCleanupElement', event => destroy(event.detail?.elt || event.target));
  new MutationObserver(() => {
    instances.forEach((instance, input) => { if (!input.isConnected) instance.destroy(); });
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
