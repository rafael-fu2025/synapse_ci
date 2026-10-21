/**
 * BalayDateTimePicker — accessible combined Date + Time picker modal.
 *
 * Inside the popup modal, provides:
 * | date picker (wider) | scrollable hour | : | scrollable minute | am/pm |
 *
 * Usage:
 *   <input type="text" data-datetimepicker data-target="#starts_at" placeholder="DD/MM/YYYY hh:mm A">
 *   <input type="hidden" name="starts_at" id="starts_at" value="2026-10-25T08:30">
  * Time-only mode uses data-timepicker and a hidden HH:mm target.
 */
(function () {
  'use strict';

  const ISO_DT_RE = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:T(\d{1,2}):(\d{1,2}))?$/;
  const DISPLAY_DT_RE = /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{1,2})(?:\s*(AM|PM))?)?$/i;

  function pad(n) { return String(n).padStart(2, '0'); }

  function toISODate(d) {
    return String(d.getFullYear()).padStart(4, '0') + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function toISODateTime(d, h24, min) {
    return toISODate(d) + 'T' + pad(h24) + ':' + pad(min);
  }

  function parseISODateTime(s) {
    if (!s || typeof s !== 'string') return null;
    const m = ISO_DT_RE.exec(s.trim());
    if (!m) return null;
    const year = Number(m[1]);
    const month = Number(m[2]) - 1;
    const day = Number(m[3]);
    const h24 = m[4] !== undefined ? Number(m[4]) : 0;
    const min = m[5] !== undefined ? Number(m[5]) : 0;
    if (year < 1 || month < 0 || month > 11 || day < 1 || day > 31 || h24 < 0 || h24 > 23 || min < 0 || min > 59) {
      return null;
    }
    const d = new Date(0);
    d.setFullYear(year, month, day);
    d.setHours(0, 0, 0, 0);
    if (isNaN(d.getTime()) || d.getFullYear() !== year || d.getMonth() !== month || d.getDate() !== day) return null;

    let h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    const ampm = h24 >= 12 ? 'PM' : 'AM';

    return { date: d, h24, min, h12, ampm };
  }

  function to24Hour(h12, ampm) {
    let h = Number(h12) || 12;
    if (h < 1) h = 12;
    if (h > 12) h = 12;
    if (ampm === 'AM') return h === 12 ? 0 : h;
    return h === 12 ? 12 : h + 12;
  }

  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }

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
    if (value === 'today') return startOfDay(new Date());
    const parsed = parseISODateTime(value);
    return parsed ? parsed.date : null;
  }

  function weekdayDate(index) { return new Date(Date.UTC(2023, 0, 1 + index)); }

  let openInstance = null;
  let nextId = 0;
  const instances = new Map();

  class DateTimePicker {
    constructor(input) {
      this.input = input;
      this.timeOnly = input.hasAttribute('data-timepicker');
      this.target = input.dataset.target ? document.querySelector(input.dataset.target) : null;
      this.locale = input.dataset.locale || document.documentElement.lang || navigator.language || 'en';
      this.weekStart = input.dataset.weekStart === undefined ? 1 : Number(input.dataset.weekStart);
      this.yearSpan = Math.max(1, Math.min(500, Number(input.dataset.yearSpan) || 100));
      this.events = new AbortController();
      this.notifying = false;
      this.id = 'synapse-dtp-' + (++nextId);
      this.min = resolveBound(input.dataset.min || input.getAttribute('min'));
      this.max = resolveBound(input.dataset.max || input.getAttribute('max'));
      this.defaultH24 = Number(input.dataset.defaultHours) || 0;
      this.defaultMin = Number(input.dataset.defaultMinutes) || 0;

      const initialValue = (this.target ? this.target.value : input.value) || '';
      const parsed = this.parseValue(initialValue);
      if (parsed) {
        this.selectedDate = parsed.date;
        this.selectedH12 = parsed.h12;
        this.selectedMin = parsed.min;
        this.selectedAmpm = parsed.ampm;
      } else {
        this.selectedDate = null;
        let defH12 = this.defaultH24 % 12;
        if (defH12 === 0) defH12 = 12;
        this.selectedH12 = defH12;
        this.selectedMin = this.defaultMin;
        this.selectedAmpm = this.defaultH24 >= 12 ? 'PM' : 'AM';
      }

      this.view = this.selectedDate ? new Date(this.selectedDate) : startOfDay(new Date());
      this.focusDate = new Date(this.view);
      this.isOpen = false;

      this.weekdayNarrowFmt = new Intl.DateTimeFormat(this.locale, { weekday: 'narrow' });
      this.weekdayLongFmt = new Intl.DateTimeFormat(this.locale, { weekday: 'long' });
      this.monthShortFmt = new Intl.DateTimeFormat(this.locale, { month: 'short' });

      this.buildShell();
      this.buildPopup();
      this.populateSelects();
      this.populateWheels();
      this.refreshDisplay();
      this.attachEvents();

      this.observer = new MutationObserver(() => {
        this.min = resolveBound(input.dataset.min || input.getAttribute('min'));
        this.max = resolveBound(input.dataset.max || input.getAttribute('max'));
        if (this.target) this.target.disabled = input.disabled;
        if (input.disabled || input.readOnly) this.closePopup(false);
      });
      this.observer.observe(input, { attributes: true, attributeFilter: ['data-min', 'data-max', 'min', 'max', 'disabled', 'readonly'] });
      if (this.target) this.target.disabled = input.disabled;
      instances.set(input, this);
    }

    buildShell() {
      this.wrap = document.createElement('div');
      this.wrap.className = 'dtp-wrap';
      this.input.parentNode.insertBefore(this.wrap, this.input);
      this.wrap.appendChild(this.input);
      const icon = document.createElement('i');
      icon.className = this.timeOnly ? 'bi bi-clock dtp-icon' : 'bi bi-calendar-event dtp-icon';
      icon.setAttribute('aria-hidden', 'true');
      this.wrap.appendChild(icon);
      this.input.setAttribute('aria-haspopup', 'dialog');
      this.input.setAttribute('aria-expanded', 'false');
      this.input.setAttribute('autocomplete', 'off');
      this.input.setAttribute('maxlength', this.timeOnly ? '8' : '19');
    }

    buildPopup() {
      const popup = document.createElement('div');
      popup.className = 'dtp-popup';
      popup.id = this.id;
      this.input.setAttribute('aria-controls', this.id);
      popup.setAttribute('role', 'dialog');
      popup.setAttribute('aria-modal', 'false');
      popup.setAttribute('aria-label', this.timeOnly ? 'Choose time' : 'Choose date and time');
      popup.hidden = true;

      popup.innerHTML =
        '<div class="dtp-body">' +
        '  <!-- Date Picker (wider) -->' +
        '  <div class="dtp-date-pane">' +
        '    <div class="dtp-head">' +
        '      <button type="button" class="dtp-nav" data-dtp-prev aria-label="Previous month"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>' +
        '      <select class="dtp-select dtp-month form-select" aria-label="Month"></select>' +
        '      <select class="dtp-select dtp-year form-select" aria-label="Year"></select>' +
        '      <button type="button" class="dtp-nav" data-dtp-next aria-label="Next month"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>' +
        '    </div>' +
        '    <table class="dtp-grid" role="grid" aria-label="Calendar"><thead><tr class="dtp-weekdays" role="row"></tr></thead><tbody></tbody></table>' +
        '  </div>' +
        '  <!-- Vertical divider -->' +
        '  <div class="dtp-divider" aria-hidden="true"></div>' +
        '  <!-- Time Picker with scrollable hour, minute and choosable am/pm -->' +
        '  <div class="dtp-time-pane">' +
        '    <div class="dtp-time-header">' +
        '      <span class="dtp-time-title"><i class="bi bi-clock me-1"></i>Time</span>' +
        '      <span class="dtp-time-readout">--:-- --</span>' +
        '    </div>' +
        '    <div class="dtp-wheels-container">' +
        '      <div class="dtp-wheels-headers">' +
        '        <span class="dtp-wheel-title">Hour</span>' +
        '        <span class="dtp-wheel-title">Min</span>' +
        '        <span class="dtp-wheel-title">AM/PM</span>' +
        '      </div>' +
        '      <div class="dtp-wheels-box">' +
        '        <div class="dtp-wheel dtp-hours-wheel" tabindex="0" role="listbox" aria-label="Hours"></div>' +
        '        <div class="dtp-wheel dtp-minutes-wheel" tabindex="0" role="listbox" aria-label="Minutes"></div>' +
        '        <div class="dtp-wheel dtp-ampm-wheel" tabindex="0" role="listbox" aria-label="AM or PM"></div>' +
        '      </div>' +
        '    </div>' +
        '  </div>' +
        '</div>' +
        '<div class="dtp-foot">' +
        '  <div class="dtp-selection-preview text-muted small"></div>' +
        '  <div class="d-flex align-items-center gap-1">' +
        '    <button type="button" class="dtp-foot-btn" data-dtp-now><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Now</button>' +
        '    <button type="button" class="dtp-foot-btn" data-dtp-clear><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</button>' +
        '    <button type="button" class="dtp-foot-btn dtp-btn-apply" data-dtp-apply><i class="bi bi-check2 me-1" aria-hidden="true"></i>Apply</button>' +
        '  </div>' +
        '</div>' +
        '<p class="dtp-help" aria-live="polite"></p>';

      (this.input.closest('.modal') || document.body).appendChild(popup);
      this.popup = popup;
      this.monthSelect = popup.querySelector('.dtp-month');
      this.yearSelect = popup.querySelector('.dtp-year');
      this.weekdaysRow = popup.querySelector('.dtp-weekdays');
      this.gridBody = popup.querySelector('tbody');
      this.timeReadout = popup.querySelector('.dtp-time-readout');
      this.hoursWheel = popup.querySelector('.dtp-hours-wheel');
      this.minutesWheel = popup.querySelector('.dtp-minutes-wheel');
      this.ampmWheel = popup.querySelector('.dtp-ampm-wheel');
      this.preview = popup.querySelector('.dtp-selection-preview');
      this.help = popup.querySelector('.dtp-help');

      if (this.timeOnly) {
        popup.classList.add('dtp-time-only');
        popup.querySelector('.dtp-date-pane').remove();
        popup.querySelector('.dtp-divider').remove();
      }

      for (let i = 0; i < 7; i++) {
        const d = weekdayDate((this.weekStart + i) % 7);
        const th = document.createElement('th');
        th.setAttribute('scope', 'col');
        th.setAttribute('abbr', this.weekdayLongFmt.format(d));
        th.textContent = this.weekdayNarrowFmt.format(d);
        this.weekdaysRow.appendChild(th);
      }
    }

    populateSelects() {
      this.monthSelect.innerHTML = '';
      for (let m = 0; m < 12; m++) {
        const opt = document.createElement('option');
        opt.value = String(m);
        opt.textContent = this.monthShortFmt.format(new Date(2023, m, 1));
        this.monthSelect.appendChild(opt);
      }

      const currentYear = new Date().getFullYear();
      const startYear = Math.max(1900, currentYear - Math.floor(this.yearSpan / 2));
      const endYear = startYear + this.yearSpan;
      this.yearSelect.innerHTML = '';
      for (let y = startYear; y <= endYear; y++) {
        const opt = document.createElement('option');
        opt.value = String(y);
        opt.textContent = String(y);
        this.yearSelect.appendChild(opt);
      }
    }

    populateWheels() {
      // 1. Hours: 01 through 12
      this.hoursWheel.innerHTML = '';
      for (let h = 1; h <= 12; h++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dtp-wheel-item';
        btn.dataset.val = String(h);
        btn.textContent = pad(h);
        btn.setAttribute('role', 'option');
        this.hoursWheel.appendChild(btn);
      }

      // 2. Minutes: 00 through 59
      this.minutesWheel.innerHTML = '';
      for (let m = 0; m <= 59; m++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dtp-wheel-item';
        btn.dataset.val = String(m);
        btn.textContent = pad(m);
        btn.setAttribute('role', 'option');
        this.minutesWheel.appendChild(btn);
      }

      // 3. AM / PM
      this.ampmWheel.innerHTML = '';
      ['AM', 'PM'].forEach(ampm => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dtp-wheel-item';
        btn.dataset.val = ampm;
        btn.textContent = ampm;
        btn.setAttribute('role', 'option');
        this.ampmWheel.appendChild(btn);
      });
    }

    attachEvents() {
      const on = (el, type, fn) => el.addEventListener(type, fn, { signal: this.events.signal });

      const acceptsInsertion = (text) => {
        const start = this.input.selectionStart ?? this.input.value.length;
        const end = this.input.selectionEnd ?? start;
        const next = this.input.value.slice(0, start) + text + this.input.value.slice(end);
        return (this.timeOnly ? /^[0-9 :ampAMP]*$/ : /^[0-9/\- :ampAMPtT]*$/).test(next) && next.length <= (this.timeOnly ? 8 : 19);
      };

      on(this.input, 'beforeinput', (e) => {
        if (e.inputType.startsWith('insert') && e.data !== null && !acceptsInsertion(e.data)) {
          e.preventDefault();
        }
      });

      on(this.input, 'paste', (e) => {
        if (e.clipboardData && !acceptsInsertion(e.clipboardData.getData('text'))) {
          e.preventDefault();
        }
      });

      on(this.input, 'click', () => this.toggle());
      on(this.input, 'keydown', (e) => {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          this.openPopup();
        } else if (e.key === 'Escape' && this.isOpen) {
          e.preventDefault();
          this.closePopup(true);
        }
      });

      on(this.input, 'input', () => {
        if (this.notifying) return;
        // Autofill / IME / un-cancelable edits: clear invalid characters
        if ((this.timeOnly ? /[^0-9 :ampAMP]/ : /[^0-9/\- :ampAMPtT]/).test(this.input.value) || this.input.value.length > (this.timeOnly ? 8 : 19)) {
          this.input.value = '';
        }
        this.parseAndCommitTyped();
      });

      on(this.input, 'blur', () => {
        if (!this.notifying) this.validateAndFormatOnBlur();
      });

      if (!this.timeOnly) {
        on(this.popup.querySelector('[data-dtp-prev]'), 'click', () => this.shiftView(-1));
        on(this.popup.querySelector('[data-dtp-next]'), 'click', () => this.shiftView(1));
      }
      on(this.monthSelect, 'change', () => {
        this.view = shiftMonth(this.view, Number(this.monthSelect.value) - this.view.getMonth());
        this.renderGrid();
      });
      on(this.yearSelect, 'change', () => {
        this.view = shiftMonth(this.view, (Number(this.yearSelect.value) - this.view.getFullYear()) * 12);
        this.renderGrid();
      });

      on(this.gridBody, 'click', (e) => {
        const cell = e.target.closest('td[data-date]');
        if (cell && !cell.classList.contains('is-disabled')) {
          const parts = cell.dataset.date.split('-');
          const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
          this.selectDate(d);
        }
      });

      // Wheel item clicks
      on(this.hoursWheel, 'click', (e) => {
        const item = e.target.closest('.dtp-wheel-item');
        if (item) {
          this.selectedH12 = Number(item.dataset.val);
          if (!this.selectedDate) this.selectedDate = startOfDay(new Date());
          this.commit();
          this.updateWheelHighlights();
        }
      });

      on(this.minutesWheel, 'click', (e) => {
        const item = e.target.closest('.dtp-wheel-item');
        if (item) {
          this.selectedMin = Number(item.dataset.val);
          if (!this.selectedDate) this.selectedDate = startOfDay(new Date());
          this.commit();
          this.updateWheelHighlights();
        }
      });

      on(this.ampmWheel, 'click', (e) => {
        const item = e.target.closest('.dtp-wheel-item');
        if (item) {
          this.selectedAmpm = item.dataset.val === 'PM' ? 'PM' : 'AM';
          if (!this.selectedDate) this.selectedDate = startOfDay(new Date());
          this.commit();
          this.updateWheelHighlights();
        }
      });

      on(this.popup.querySelector('[data-dtp-now]'), 'click', () => {
        const now = new Date();
        this.selectedDate = startOfDay(now);
        let h = now.getHours();
        this.selectedAmpm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        if (h === 0) h = 12;
        this.selectedH12 = h;
        this.selectedMin = now.getMinutes();
        this.view = new Date(this.selectedDate);
        this.updateWheelHighlights();
        this.commit();
        this.scrollToSelected();
        this.closePopup(true);
      });

      on(this.popup.querySelector('[data-dtp-clear]'), 'click', () => {
        this.selectedDate = null;
        let defH12 = this.defaultH24 % 12;
        if (defH12 === 0) defH12 = 12;
        this.selectedH12 = defH12;
        this.selectedMin = this.defaultMin;
        this.selectedAmpm = this.defaultH24 >= 12 ? 'PM' : 'AM';
        this.commit();
        this.updateWheelHighlights();
        this.closePopup(true);
      });

      on(this.popup.querySelector('[data-dtp-apply]'), 'click', () => {
        if (!this.selectedDate) this.selectedDate = startOfDay(new Date());
        this.commit();
        this.closePopup(true);
      });

      on(document, 'pointerdown', (e) => {
        if (this.isOpen && !this.popup.contains(e.target) && !this.wrap.contains(e.target)) {
          this.closePopup(false);
        }
      });

      on(window, 'scroll', () => this.isOpen && this.position(), { capture: true });
      on(window, 'resize', () => this.isOpen && this.position());

      if (this.target) {
        on(this.target, 'change', () => { if (!this.notifying) this.syncFromTarget(); });
      }
    }

    parseValue(value) {
      if (!this.timeOnly) return parseISODateTime(value);
      // A fixed internal date keeps the shared selection state; only HH:mm is submitted.
      return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value || '')
        ? parseISODateTime('2000-01-01T' + value) : null;
    }

    validateAndFormatOnBlur() {
      if (this.timeOnly) {
        this.parseAndCommitTyped();
        if (!this.input.validity.customError) this.refreshDisplay();
        return;
      }
      const text = this.input.value.trim();
      if (!text) {
        this.selectedDate = null;
        this.commit();
        return;
      }
      this.parseAndCommitTyped();
      if (!this.selectedDate) {
        this.input.value = '';
        this.commit();
      } else {
        this.refreshDisplay();
      }
    }

    parseAndCommitTyped() {
      const text = this.input.value.trim();
      if (this.timeOnly) {
        const display = /^(\d{1,2}):([0-5]\d)\s*(AM|PM)$/i.exec(text);
        const validDisplay = display && Number(display[1]) >= 1 && Number(display[1]) <= 12;
        const canonical = validDisplay
          ? pad(to24Hour(Number(display[1]), display[3].toUpperCase())) + ':' + display[2]
          : text;
        const parsed = this.parseValue(canonical);
        this.input.setCustomValidity(text && !parsed ? 'Enter a valid time, such as 08:30 AM.' : '');
        this.selectedDate = parsed ? parsed.date : null;
        if (parsed) {
          this.selectedH12 = parsed.h12;
          this.selectedMin = parsed.min;
          this.selectedAmpm = parsed.ampm;
        }
        this.commitTarget(parsed ? pad(parsed.h24) + ':' + pad(parsed.min) : '');
        this.updateWheelHighlights();
        return;
      }
      if (!text) {
        this.selectedDate = null;
        this.commitTarget('');
        return;
      }

      // Check DD/MM/YYYY hh:mm AM/PM
      let m = DISPLAY_DT_RE.exec(text);
      if (m) {
        const day = Number(m[1]);
        const month = Number(m[2]) - 1;
        const year = Number(m[3]);
        let h = m[4] !== undefined ? Number(m[4]) : this.selectedH12;
        const min = m[5] !== undefined ? Number(m[5]) : this.selectedMin;
        const ampm = m[6] ? m[6].toUpperCase() : this.selectedAmpm;
        const d = new Date(year, month, day);
        if (d.getFullYear() === year && d.getMonth() === month && d.getDate() === day) {
          this.selectedDate = d;
          this.selectedH12 = Math.max(1, Math.min(12, h));
          this.selectedMin = Math.max(0, Math.min(59, min));
          this.selectedAmpm = ampm;
          const h24 = to24Hour(this.selectedH12, this.selectedAmpm);
          this.commitTarget(toISODateTime(this.selectedDate, h24, this.selectedMin));
          return;
        }
      }

      // Check ISO YYYY-MM-DDTHH:mm
      const parsed = parseISODateTime(text);
      if (parsed) {
        this.selectedDate = parsed.date;
        this.selectedH12 = parsed.h12;
        this.selectedMin = parsed.min;
        this.selectedAmpm = parsed.ampm;
        this.commitTarget(toISODateTime(this.selectedDate, parsed.h24, this.selectedMin));
      }
    }

    selectDate(date) {
      this.selectedDate = date;
      this.commit();
      this.renderGrid();
    }

    updateWheelHighlights() {
      this.hoursWheel.querySelectorAll('.dtp-wheel-item').forEach(item => {
        item.classList.toggle('is-selected', Number(item.dataset.val) === this.selectedH12);
      });
      this.minutesWheel.querySelectorAll('.dtp-wheel-item').forEach(item => {
        item.classList.toggle('is-selected', Number(item.dataset.val) === this.selectedMin);
      });
      this.ampmWheel.querySelectorAll('.dtp-wheel-item').forEach(item => {
        item.classList.toggle('is-selected', item.dataset.val === this.selectedAmpm);
      });

      const formattedTime = pad(this.selectedH12) + ':' + pad(this.selectedMin) + ' ' + this.selectedAmpm;
      if (this.timeReadout) {
        this.timeReadout.textContent = formattedTime;
      }
    }

    scrollToSelected() {
      [this.hoursWheel, this.minutesWheel, this.ampmWheel].forEach(wheel => {
        const sel = wheel.querySelector('.dtp-wheel-item.is-selected');
        if (sel) {
          wheel.scrollTop = sel.offsetTop - wheel.offsetTop - (wheel.clientHeight / 2) + (sel.clientHeight / 2);
        }
      });
    }

    commit() {
      if (this.timeOnly) this.input.setCustomValidity('');
      this.updateWheelHighlights();
      if (!this.selectedDate) {
        this.input.value = '';
        if (this.preview) this.preview.textContent = '';
        this.commitTarget('');
        return;
      }

      const h24 = to24Hour(this.selectedH12, this.selectedAmpm);
      const iso = this.timeOnly ? pad(h24) + ':' + pad(this.selectedMin) : toISODateTime(this.selectedDate, h24, this.selectedMin);
      this.refreshDisplay();
      this.commitTarget(iso);
    }

    commitTarget(iso) {
      if (!this.target) return;
      if (this.target.value === iso) return;
      this.target.value = iso;
      this.notifying = true;
      try {
        this.target.dispatchEvent(new Event('input', { bubbles: true }));
        this.target.dispatchEvent(new Event('change', { bubbles: true }));
      } finally {
        this.notifying = false;
      }
    }

    syncFromTarget() {
      const val = this.target ? this.target.value : '';
      const parsed = this.parseValue(val);
            if (this.timeOnly) this.input.setCustomValidity('');
      if (parsed) {
        this.selectedDate = parsed.date;
        this.selectedH12 = parsed.h12;
        this.selectedMin = parsed.min;
        this.selectedAmpm = parsed.ampm;
      } else {
        this.selectedDate = null;
      }
      this.refreshDisplay();
      if (this.isOpen) {
        this.updateWheelHighlights();
        this.renderGrid();
      }
    }

    refreshDisplay() {
      if (!this.selectedDate) {
        this.input.value = '';
        if (this.preview) this.preview.textContent = '';
        return;
      }
      if (this.timeOnly) {
        const time = pad(this.selectedH12) + ':' + pad(this.selectedMin) + ' ' + this.selectedAmpm;
        this.input.value = time;
        if (this.preview) this.preview.textContent = time;
        return;
      }
      const d = pad(this.selectedDate.getDate());
      const m = pad(this.selectedDate.getMonth() + 1);
      const y = String(this.selectedDate.getFullYear()).padStart(4, '0');
      const time = pad(this.selectedH12) + ':' + pad(this.selectedMin) + ' ' + this.selectedAmpm;
      this.input.value = d + '/' + m + '/' + y + ' ' + time;
      if (this.preview) {
        this.preview.textContent = d + '/' + m + '/' + y + ' ' + time;
      }
    }

    inRange(d) {
      if (this.min && d < this.min) return false;
      if (this.max && d > this.max) return false;
      return true;
    }

    renderGrid() {
      if (this.timeOnly) return;
      this.monthSelect.value = String(this.view.getMonth());
      this.yearSelect.value = String(this.view.getFullYear());

      const year = this.view.getFullYear();
      const month = this.view.getMonth();
      const firstDay = new Date(year, month, 1);

      let offset = (firstDay.getDay() - this.weekStart + 7) % 7;
      let dateCursor = new Date(year, month, 1 - offset);

      this.gridBody.innerHTML = '';
      const today = startOfDay(new Date());

      for (let week = 0; week < 6; week++) {
        const tr = document.createElement('tr');
        tr.setAttribute('role', 'row');
        for (let day = 0; day < 7; day++) {
          const td = document.createElement('td');
          td.className = 'dtp-cell';
          const d = new Date(dateCursor);
          td.dataset.date = toISODate(d);

          const span = document.createElement('span');
          span.className = 'dtp-day';
          span.textContent = String(d.getDate());
          td.appendChild(span);

          if (d.getMonth() !== month) td.classList.add('is-outmonth');
          if (sameDay(d, today)) td.classList.add('is-today');
          if (sameDay(d, this.selectedDate)) td.classList.add('is-selected');

          if (!this.inRange(d)) {
            td.classList.add('is-disabled');
            td.setAttribute('aria-disabled', 'true');
          }

          tr.appendChild(td);
          dateCursor.setDate(dateCursor.getDate() + 1);
        }
        this.gridBody.appendChild(tr);
        if (dateCursor.getMonth() !== month && week >= 4) break;
      }
    }

    toggle() {
      this.isOpen ? this.closePopup(true) : this.openPopup();
    }

    openPopup() {
      if (this.isOpen || this.input.disabled || this.input.readOnly) return;
      if (openInstance && openInstance !== this) openInstance.closePopup(false);
      openInstance = this;
      this.isOpen = true;
      this.wrap.classList.add('dtp-open');

      if (this.selectedDate) {
        this.view = new Date(this.selectedDate);
      } else {
        this.view = startOfDay(new Date());
      }
      this.updateWheelHighlights();
      this.renderGrid();
      this.popup.hidden = false;
      this.input.setAttribute('aria-expanded', 'true');
      this.position();
      // Scroll wheels to selected time
      setTimeout(() => this.scrollToSelected(), 20);
    }

    closePopup(refocus) {
      if (!this.isOpen) return;
      this.isOpen = false;
      this.wrap.classList.remove('dtp-open');
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

    shiftView(amount) {
      this.view = shiftMonth(this.view, amount);
      this.renderGrid();
    }

    destroy() {
      this.closePopup(false);
      this.events.abort();
      if (this.observer) this.observer.disconnect();
      if (this.popup && this.popup.parentNode) this.popup.parentNode.removeChild(this.popup);
      if (this.wrap && this.wrap.parentNode) {
        this.wrap.parentNode.insertBefore(this.input, this.wrap);
        this.wrap.parentNode.removeChild(this.wrap);
      }
      instances.delete(this.input);
    }
  }

  function init(root) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('input[data-datetimepicker], input[data-timepicker]').forEach((input) => {
      if (!instances.has(input)) {
        new DateTimePicker(input);
      }
    });
  }

  const removalObserver = new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (node.nodeType !== Node.ELEMENT_NODE || !node.isConnected) return;
      if (node.matches('input[data-timepicker]') && !instances.has(node)) new DateTimePicker(node);
      init(node);
    }));
    instances.forEach((instance, input) => {
      if (!input.isConnected) instance.destroy();
    });
  });
  removalObserver.observe(document.documentElement, { childList: true, subtree: true });
  // Alpine inserts custom-hours controls after the surrounding form is initialized.
  document.addEventListener('focusin', event => {
    if (event.target.matches?.('input[data-timepicker]') && !instances.has(event.target)) {
      new DateTimePicker(event.target);
    }
  });
  document.addEventListener('hidden.bs.modal', event => {
    if (openInstance && event.target.contains(openInstance.input)) openInstance.closePopup(false);
  });

  window.BalayDateTimePicker = { DateTimePicker, init };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  } else {
    init();
  }
})();
