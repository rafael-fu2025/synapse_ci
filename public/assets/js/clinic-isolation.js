/** Isolation & Outbreak Monitoring: zero-reload month/filter navigation, day inspector, and modal wiring. */
(function () {
  'use strict';

  const instances = new Map();

  function routeOf(pathname) {
    return String(pathname || '').replace(/^\/index\.php(?=\/)/, '');
  }

  function statusMeta(status) {
    if (status === 'cleared') return { label: 'Cleared', tone: 'cleared' };
    if (status === 'hospitalized') return { label: 'Hospitalized', tone: 'hospitalized' };
    return { label: 'Active Isolation', tone: 'active' };
  }

  /** Alpine factory for #logIsolationModal */
  window.isolationLogForm = function isolationLogForm(cfg) {
    return {
      searchTerm: '',
      results: [],
      selectedPerson: null,
      loading: false,
      searchComplete: false,
      searchError: '',
      condition: 'chickenpox',
      days: cfg.defaultDays?.chickenpox ?? 7,
      startDate: cfg.today || '',
      notes: '',
      applyDefaultDays() {
        if (cfg.defaultDays && cfg.defaultDays[this.condition]) {
          this.days = cfg.defaultDays[this.condition];
        }
      },
      previewEndDate() {
        const hiddenEl = document.getElementById('isoStartDateIso');
        const raw = this.startDate || (hiddenEl ? hiddenEl.value : '');
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw || '');
        if (!m) return '—';
        const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        if (Number.isNaN(d.getTime())) return '—';
        const offset = Math.max(1, Number(this.days || 1)) - 1;
        d.setDate(d.getDate() + offset);
        return [
          d.getFullYear(),
          String(d.getMonth() + 1).padStart(2, '0'),
          String(d.getDate()).padStart(2, '0'),
        ].join('-');
      },
      async lookupPatient() {
        const q = this.searchTerm.trim();
        this.searchError = '';
        this.searchComplete = false;
        this.results = [];
        if (q.length < 2) return;
        this.loading = true;
        try {
          const res = await fetch(cfg.searchUrl + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          });
          if (!res.ok) throw new Error();
          const data = await res.json();
          this.results = Array.isArray(data.results) ? data.results : [];
          this.searchComplete = true;
        } catch {
          this.searchError = 'Unable to query local directory.';
        } finally {
          this.loading = false;
        }
      },
      pick(p) {
        this.selectedPerson = p;
        this.results = [];
        this.searchComplete = false;
        this.searchTerm = '';
      },
      clearPick() {
        this.selectedPerson = null;
        this.searchComplete = false;
      },
    };
  };

  function openClearModal(trigger) {
    const modalEl = document.getElementById('clearIsolationModal');
    if (!modalEl) return;
    const form = modalEl.querySelector('[data-iso-clear-form]');
    const patientEl = modalEl.querySelector('[data-iso-clear-patient]');
    const conditionEl = modalEl.querySelector('[data-iso-clear-condition]');
    const windowEl = modalEl.querySelector('[data-iso-clear-window]');
    const notesEl = modalEl.querySelector('#clearIsolationNotes');
    if (form) form.action = trigger.dataset.clearUrl || '';
    if (patientEl) patientEl.textContent = trigger.dataset.patientName || '—';
    if (conditionEl) conditionEl.textContent = trigger.dataset.conditionLabel || '—';
    if (windowEl) windowEl.textContent = trigger.dataset.windowLabel || '—';
    if (notesEl) notesEl.value = '';
    modalEl.querySelectorAll('[data-iso-choice-card]').forEach(card => {
      const radio = card.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = radio.value === 'cleared';
        card.classList.toggle('is-selected', radio.checked);
      }
    });
    if (window.bootstrap?.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  }

  function bindCalendar(calEl, signal) {
    const inspector = calEl.querySelector('[data-iso-day-inspector]');
    const dateEl = calEl.querySelector('[data-iso-inspector-date]');
    const countEl = calEl.querySelector('[data-iso-inspector-count]');
    const listEl = calEl.querySelector('[data-iso-inspector-list]');
    const closeBtn = calEl.querySelector('[data-iso-inspector-close]');
    if (!inspector || !listEl) return;

    let payload;
    try { payload = JSON.parse(calEl.dataset.calendarPayload || '{}'); } catch (_) { return; }
    const casesMap = payload.cases || {};
    const byDay = payload.byDay || {};
    const canWrite = Boolean(payload.canWrite);
    let selectedIso = null;

    function selectDay(iso, labelText) {
      const buttons = calEl.querySelectorAll('[data-iso-day]');
      if (!iso) {
        selectedIso = null;
        buttons.forEach(btn => {
          btn.classList.remove('is-selected');
          btn.setAttribute('aria-pressed', 'false');
        });
        inspector.hidden = true;
        return;
      }
      selectedIso = iso;
      let resolvedLabel = labelText || iso;
      buttons.forEach(btn => {
        const match = btn.dataset.isoDay === iso;
        btn.classList.toggle('is-selected', match);
        btn.setAttribute('aria-pressed', String(match));
        if (match && btn.dataset.isoDayLabel) resolvedLabel = btn.dataset.isoDayLabel;
      });

      const ids = Array.isArray(byDay[iso]) ? byDay[iso] : [];
      if (dateEl) dateEl.textContent = resolvedLabel;
      if (countEl) {
        countEl.textContent = ids.length === 0
          ? 'No cases scheduled'
          : `${ids.length} ${ids.length === 1 ? 'overlapping case' : 'overlapping cases'}`;
      }

      listEl.replaceChildren();
      if (!ids.length) {
        const empty = document.createElement('div');
        empty.className = 'iso-inspector-empty';
        empty.textContent = 'No communicable isolation windows overlap this date under the active filters.';
        listEl.appendChild(empty);
      } else {
        ids.forEach(id => {
          const item = casesMap[String(id)] || casesMap[id];
          if (!item) return;
          const row = document.createElement('div');
          row.className = 'iso-inspector-item';
          row.dataset.tone = item.tone || 'zinc';

          const main = document.createElement('div');
          main.className = 'iso-inspector-main';

          const top = document.createElement('div');
          top.className = 'iso-inspector-top';
          const nameLink = document.createElement('a');
          nameLink.href = item.patient_url || '#';
          nameLink.className = 'iso-patient-link';
          nameLink.textContent = item.name;
          const cond = document.createElement('span');
          cond.className = 'iso-inspector-cond';
          const dot = document.createElement('span');
          dot.className = 'iso-cond-dot';
          dot.dataset.tone = item.tone || 'zinc';
          dot.setAttribute('aria-hidden', 'true');
          cond.append(dot, document.createTextNode(item.condition + (item.condition_sub ? ` (${item.condition_sub})` : '')));
          top.append(nameLink, cond);

          const meta = document.createElement('div');
          meta.className = 'iso-inspector-meta';
          meta.textContent = `${item.mis_id} · ${item.cohort} · ${item.start} → ${item.end} (${item.days}d)`;

          main.append(top, meta);
          if (item.notes) {
            const note = document.createElement('div');
            note.className = 'iso-inspector-note';
            note.textContent = item.notes;
            main.appendChild(note);
          }

          const side = document.createElement('div');
          side.className = 'iso-inspector-side';
          const st = statusMeta(item.status);
          const statusSpan = document.createElement('span');
          statusSpan.className = 'iso-status-text';
          statusSpan.dataset.tone = st.tone;
          const sDot = document.createElement('span');
          sDot.className = 'iso-dot';
          sDot.setAttribute('aria-hidden', 'true');
          statusSpan.append(sDot, document.createTextNode(st.label));
          side.appendChild(statusSpan);

          if (canWrite && item.status === 'active_isolation') {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-secondary btn-sm iso-clear-btn';
            btn.dataset.isoClearTrigger = '';
            btn.dataset.clearUrl = item.clear_url || '';
            btn.dataset.patientName = item.name || '';
            btn.dataset.conditionLabel = item.condition || '';
            btn.dataset.windowLabel = `${item.start} → ${item.end} (${item.days}d)`;
            btn.textContent = 'Update / Clear';
            side.appendChild(btn);
          }

          row.append(main, side);
          listEl.appendChild(row);
        });
      }
      inspector.hidden = false;
    }

    calEl.addEventListener('click', event => {
      const cell = event.target.closest('[data-iso-day]');
      if (!cell || !calEl.contains(cell)) return;
      const iso = cell.dataset.isoDay;
      if (selectedIso === iso && window.innerWidth >= 768) {
        selectDay(null);
      } else {
        selectDay(iso, cell.dataset.isoDayLabel);
      }
    }, { signal });

    closeBtn?.addEventListener('click', () => selectDay(null), { signal });

    // Auto-select default day on mobile compact calendar so cases are immediately legible without scrolling
    if (window.innerWidth < 768 && calEl.dataset.defaultDay) {
      selectDay(calEl.dataset.defaultDay);
    }
  }

  function mount(root) {
    const endpoint = new URL(root.dataset.endpoint, location.href);
    if (endpoint.origin !== location.origin) return () => {};

    const bodyEl = root.querySelector('[data-iso-body]');
    const prevLink = root.querySelector('[data-iso-month-nav="prev"]');
    const nextLink = root.querySelector('[data-iso-month-nav="next"]');
    const todayBtn = root.querySelector('[data-iso-month-nav="today"]');
    const monthLabelEl = root.querySelector('[data-iso-month-label]');
    const condSelect = root.querySelector('[data-iso-condition-select]');
    const resetLink = root.querySelector('[data-iso-reset]');
    const errorBox = root.querySelector('[data-iso-error]');
    const errorMsg = root.querySelector('[data-iso-error-message]');
    const currentMonth = root.dataset.currentMonth || '';
    if (!bodyEl) return () => {};

    const events = new AbortController();
    const { signal } = events;
    let bodyEvents = null;
    let fetchController = null;
    let sequence = 0;
    let failedUrl = null;

    function currentState() {
      const content = bodyEl.querySelector('[data-iso-content]');
      return {
        month: content?.dataset.month || currentMonth,
        monthLabel: content?.dataset.monthLabel || monthLabelEl?.textContent || '',
        prevMonth: content?.dataset.prevMonth || prevLink?.dataset.monthTarget || '',
        nextMonth: content?.dataset.nextMonth || nextLink?.dataset.monthTarget || '',
        condition: content?.dataset.condition || 'all',
        status: content?.dataset.status || 'all',
      };
    }

    let committed = currentState();

    function buildUrl(nextState) {
      const url = new URL(endpoint);
      url.search = '';
      if (nextState.month) url.searchParams.set('month', nextState.month);
      if (nextState.condition && nextState.condition !== 'all') url.searchParams.set('condition', nextState.condition);
      if (nextState.status && nextState.status !== 'all') url.searchParams.set('status', nextState.status);
      return url;
    }

    function syncToolbar(state) {
      if (monthLabelEl && state.monthLabel) monthLabelEl.textContent = state.monthLabel;
      if (prevLink && state.prevMonth) {
        prevLink.dataset.monthTarget = state.prevMonth;
        prevLink.href = buildUrl({ ...state, month: state.prevMonth }).href;
      }
      if (nextLink && state.nextMonth) {
        nextLink.dataset.monthTarget = state.nextMonth;
        nextLink.href = buildUrl({ ...state, month: state.nextMonth }).href;
      }
      if (todayBtn) todayBtn.hidden = state.month === currentMonth;
      root.querySelectorAll('[data-iso-status-tab]').forEach(tab => {
        const active = tab.dataset.isoStatusTab === state.status;
        tab.classList.toggle('is-active', active);
        if (active) tab.setAttribute('aria-current', 'page');
        else tab.removeAttribute('aria-current');
        tab.href = buildUrl({ ...state, status: tab.dataset.isoStatusTab }).href;
      });
      if (condSelect && condSelect.value !== state.condition) {
        condSelect.value = state.condition;
        condSelect.dispatchEvent(new Event('bd-select:sync'));
      }
      if (resetLink) {
        const filtered = state.month !== currentMonth || state.condition !== 'all' || state.status !== 'all';
        resetLink.hidden = !filtered;
      }
    }

    function initBody() {
      bodyEvents?.abort();
      bodyEvents = new AbortController();
      bodyEl.querySelectorAll('[data-iso-calendar]').forEach(el => bindCalendar(el, bodyEvents.signal));
    }

    function setBusy(busy) {
      bodyEl.setAttribute('aria-busy', String(busy));
      bodyEl.classList.toggle('is-loading', busy);
    }

    async function load(destination, historyMode = 'push') {
      fetchController?.abort();
      const token = ++sequence;
      const controller = new AbortController();
      fetchController = controller;

      const clean = new URL(destination, location.href);
      if (clean.origin !== endpoint.origin) return false;
      clean.pathname = endpoint.pathname;
      clean.searchParams.delete('fragment');
      clean.hash = '';
      failedUrl = new URL(clean);

      if (errorBox) errorBox.hidden = true;
      setBusy(true);

      const reqUrl = new URL(clean);
      reqUrl.searchParams.set('fragment', '1');

      try {
        const res = await fetch(reqUrl.href, {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (token !== sequence || controller.signal.aborted) return false;
        if (window.SynapseCsrf?.fromResponse(res)) return false;
        if (!res.ok) {
          const msg = await res.text();
          throw new Error(msg && msg.length < 240 ? msg : 'Unable to load isolation calendar.');
        }
        const html = await res.text();
        if (token !== sequence || controller.signal.aborted) return false;

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const content = doc.body.firstElementChild;
        if (!content?.matches('[data-iso-content]') || content.querySelector('script, iframe, object, embed')) {
          throw new Error('Unexpected response received. Reload this page.');
        }

        bodyEl.replaceChildren(content);
        committed = currentState();
        syncToolbar(committed);
        initBody();

        if (historyMode !== 'none') {
          if (window.SynapseNavigation?.updateHistory) {
            window.SynapseNavigation.updateHistory(clean.href, historyMode === 'push' ? 'push' : 'replace');
          } else {
            history[historyMode === 'push' ? 'pushState' : 'replaceState'](history.state, '', clean.href);
          }
        }
        failedUrl = null;
        setBusy(false);
        return true;
      } catch (err) {
        if (token !== sequence || controller.signal.aborted || err.name === 'AbortError') return false;
        syncToolbar(committed);
        if (errorMsg) errorMsg.textContent = err.message || 'Unable to load isolation calendar.';
        if (errorBox) errorBox.hidden = false;
        setBusy(false);
        return false;
      } finally {
        if (token === sequence) {
          fetchController = null;
          setBusy(false);
        }
      }
    }

    root.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

      const clearBtn = event.target.closest('[data-iso-clear-trigger]');
      if (clearBtn && root.contains(clearBtn)) {
        event.preventDefault();
        openClearModal(clearBtn);
        return;
      }

      const monthNav = event.target.closest('[data-iso-month-nav]');
      if (monthNav && root.contains(monthNav)) {
        event.preventDefault();
        const targetMonth = monthNav.dataset.monthTarget;
        if (!targetMonth) return;
        void load(buildUrl({ ...committed, month: targetMonth }), 'push');
        return;
      }

      const statusTab = event.target.closest('[data-iso-status-tab]');
      if (statusTab && root.contains(statusTab)) {
        event.preventDefault();
        const nextStatus = statusTab.dataset.isoStatusTab || 'all';
        syncToolbar({ ...committed, status: nextStatus });
        void load(buildUrl({ ...committed, status: nextStatus }), 'push');
        return;
      }

      const quickCond = event.target.closest('[data-iso-quick-condition]');
      if (quickCond && root.contains(quickCond)) {
        event.preventDefault();
        const code = quickCond.dataset.isoQuickCondition || 'all';
        const nextCond = committed.condition === code ? 'all' : code;
        syncToolbar({ ...committed, condition: nextCond });
        void load(buildUrl({ ...committed, condition: nextCond }), 'push');
        return;
      }

      if (event.target.closest('[data-iso-reset]')) {
        event.preventDefault();
        const resetState = { ...committed, month: currentMonth, condition: 'all', status: 'all' };
        syncToolbar(resetState);
        void load(buildUrl(resetState), 'push');
        return;
      }

      if (event.target.closest('[data-iso-retry]') && failedUrl) {
        event.preventDefault();
        void load(failedUrl, 'replace');
      }
    }, { signal });

    condSelect?.addEventListener('change', () => {
      const nextCond = condSelect.value || 'all';
      syncToolbar({ ...committed, condition: nextCond });
      void load(buildUrl({ ...committed, condition: nextCond }), 'push');
    }, { signal });

    // Sync interactive radio cards inside #clearIsolationModal
    const clearModal = document.getElementById('clearIsolationModal');
    clearModal?.addEventListener('change', event => {
      if (!event.target.matches('input[name="clearance_status"]')) return;
      clearModal.querySelectorAll('[data-iso-choice-card]').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        card.classList.toggle('is-selected', Boolean(radio?.checked));
      });
    }, { signal });

    window.addEventListener('popstate', () => {
      if (routeOf(location.pathname) !== routeOf(endpoint.pathname) || !root.isConnected) return;
      void load(location.href, 'none');
    }, { signal });

    initBody();

    return () => {
      fetchController?.abort();
      bodyEvents?.abort();
      events.abort();
    };
  }

  function init(scope = document) {
    for (const [root, destroy] of instances) {
      if (!root.isConnected) {
        destroy();
        instances.delete(root);
      }
    }
    const roots = [...scope.querySelectorAll('[data-iso-workspace]')];
    if (scope.matches?.('[data-iso-workspace]')) roots.unshift(scope);
    roots.forEach(root => {
      if (!instances.has(root)) instances.set(root, mount(root));
    });
  }

  document.addEventListener('synapse:page-load', e => init(e.detail?.root || document));
  document.addEventListener('synapse:before-swap', e => {
    const scope = e.detail?.root;
    for (const [root, destroy] of instances) {
      if (!scope || scope === root || scope.contains(root)) {
        destroy();
        instances.delete(root);
      }
    }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(), { once: true });
  else init();
})();
