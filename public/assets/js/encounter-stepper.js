/** Clinical drafts and sealing are separate from inventory operations. No patient data is stored in the browser. */
(function () {
  'use strict';
  if (window.SynapseEncounterStepper) return;

  window.encounterStepper = function () {
    return {
      init() {
        this.$nextTick(() => { this.stopDraftSaving = window.SynapseEncounterStepper.init(this.$el); });
      },
      destroy() { this.stopDraftSaving?.(); },
      bpSys: null,
      bpDia: null,
      tempC: null,
      weight: null,
      height: null,
      calcBmi() {
        if (this.weight && this.height && this.height > 0) {
          const hMeter = this.height / 100;
          return (this.weight / (hMeter * hMeter)).toFixed(2);
        }
        return null;
      },
      bmiCategory() {
        const bmi = parseFloat(this.calcBmi());
        if (!bmi) return '';
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25) return 'Normal';
        if (bmi < 30) return 'Overweight';
        return 'Obese';
      },
      bmiBadgeClass() {
        const category = this.bmiCategory();
        if (category === 'Normal') return 'bg-success';
        if (category === 'Overweight') return 'bg-warning text-dark';
        if (category === 'Obese' || category === 'Underweight') return 'bg-danger';
        return 'd-none';
      }
    };
  };

  function init(root) {
    const events = new AbortController();
    const on = (element, type, handler, capture = false) => element.addEventListener(type, handler, { signal: events.signal, capture });
    let forms = Array.from(root.querySelectorAll('[data-clinical-form]'));
    let fields = forms.flatMap(form => Array.from(form.querySelectorAll('[name]')))
      .filter(field => field.type !== 'hidden' || ['follow_up_date', 'visited_at'].includes(field.name));
    const status = root.querySelector('[data-encounter-save-status]');
    const retry = root.querySelector('[data-save-draft]');
    const review = root.querySelector('[data-review-completion]');
    const backupBanner = root.querySelector('[data-local-backup-banner]');
    const discardBackupBtn = root.querySelector('[data-discard-local-backup]');
    const saveDraftManualBtn = root.querySelector('[data-save-draft-manual]');
    const encounterId = root.dataset.encounterId || '';
    const storageKey = encounterId ? 'synapse.encounter.draft.' + encounterId : null;
    let complaintCatalog = {};
    try {
      complaintCatalog = JSON.parse(root.dataset.complaintCatalog || '{}');
    } catch { complaintCatalog = {}; }
    let medicine = root.querySelector('#step-dispense form');
    let medicineFields = medicine ? ['medicine_id', 'quantity', 'instructions'].map(name => medicine.elements.namedItem(name)) : [];
    let medicineDefaults = medicineFields.map(field => field.value);
    let saved = snapshot(), busy = false, leaving = false, allowedTab = null, dispensed = false;
    let autosaveTimer = null;

    function persistLocalBackup() {
      if (!storageKey) return;
      try {
        const diff = patch();
        if (Object.keys(diff).length) window.localStorage.setItem(storageKey, JSON.stringify(diff));
        else window.localStorage.removeItem(storageKey);
      } catch {}
    }

    function clearLocalBackup() {
      if (!storageKey) return;
      try { window.localStorage.removeItem(storageKey); } catch {}
      if (backupBanner) backupBanner.hidden = true;
    }

    function syncQualifierOptions(preserveExisting = true) {
      const catEl = root.querySelector('[data-complaint-category]');
      const qualEl = root.querySelector('[data-complaint-qualifier]');
      const chiefEl = root.querySelector('[data-chief-complaint-input]');
      if (!catEl || !qualEl) return;
      const meta = complaintCatalog[catEl.value] || null;
      const prevValue = preserveExisting ? (qualEl.value || qualEl.dataset.initialValue || '') : '';
      const options = Array.isArray(meta?.qualifiers) ? meta.qualifiers : [];
      qualEl.innerHTML = '';
      const emptyOpt = document.createElement('option');
      emptyOpt.value = '';
      emptyOpt.textContent = '-- Optional qualifier --';
      qualEl.appendChild(emptyOpt);
      for (const label of options) {
        const opt = document.createElement('option');
        opt.value = label;
        opt.textContent = label;
        if (label === prevValue) opt.selected = true;
        qualEl.appendChild(opt);
      }
      if (prevValue && !options.includes(prevValue) && preserveExisting) {
        const extra = document.createElement('option');
        extra.value = prevValue;
        extra.textContent = prevValue;
        extra.selected = true;
        qualEl.appendChild(extra);
      }
      qualEl.dataset.initialValue = '';
      if (chiefEl && meta && (!chiefEl.value.trim() || chiefEl.dataset.autoComposed === '1')) {
        chiefEl.value = qualEl.value ? `${meta.label} (${qualEl.value})` : meta.label;
        chiefEl.dataset.autoComposed = '1';
      }
    }

    const alpineData = window.Alpine?.$data?.(root);
    if (alpineData) {
      for (const [name, property] of Object.entries({
        bp_systolic: 'bpSys', bp_diastolic: 'bpDia', temp_c: 'tempC', weight_kg: 'weight', height_cm: 'height'
      })) {
        const field = forms.flatMap(form => Array.from(form.elements)).find(control => control.name === name);
        if (field && field.value !== '') alpineData[property] = Number(field.value);
      }
    }

    // Recover unsaved draft after sudden brownout/reload if localStorage holds fresher edits.
    if (storageKey) {
      try {
        const raw = window.localStorage.getItem(storageKey);
        if (raw) {
          const cached = JSON.parse(raw);
          let restored = false;
          for (const [name, value] of Object.entries(cached || {})) {
            const field = fields.find(f => f.name === name);
            if (field && typeof value === 'string' && field.value !== value) {
              field.value = value;
              restored = true;
            }
          }
          if (restored && backupBanner) backupBanner.hidden = false;
        }
      } catch {}
    }
    syncQualifierOptions(true);

    function snapshot() { return Object.fromEntries(fields.map(field => [field.name, field.value])); }
    function patch() {
      return Object.fromEntries(Object.entries(snapshot()).filter(([name, value]) => value !== saved[name]));
    }
    function dirty() { return Object.keys(patch()).length > 0; }
    function pendingMedicine() { return !dispensed && medicineFields.some((field, index) => field.value !== medicineDefaults[index]); }
    function announce(message, error = false, retryDraft = error) {
      status.textContent = message;
      status.classList.toggle('text-danger', error);
      retry.hidden = !retryDraft;
    }
    function showStep(selector) {
      const tab = Array.from(root.querySelectorAll('[data-bs-toggle="tab"]')).find(tab => tab.dataset.bsTarget === selector);
      if (tab) {
        allowedTab = tab;
        window.bootstrap.Tab.getOrCreateInstance(tab).show();
        allowedTab = null;
      }
    }
    function medicineReady() {
      if (!pendingMedicine()) return true;
      showStep('#step-dispense');
      announce('Medicine has not been dispensed. Dispense it explicitly or discard the unfinished entry before continuing.', true);
      return false;
    }
    function validateAll() {
      const complaint = fields.find(field => field.name === 'chief_complaint');
      if (complaint && !complaint.value.trim()) {
        showStep('#step-assessment');
        complaint.focus();
        announce('Chief complaint is required to complete this encounter.', true);
        return false;
      }
      for (const field of forms.flatMap(form => Array.from(form.elements))) {
        if (!field.checkValidity()) {
          showStep('#' + field.closest('.tab-pane').id);
          field.reportValidity();
          return false;
        }
      }
      return true;
    }
    async function post(url, values, background = false) {
      const body = new FormData();
      for (const [name, value] of Object.entries(values)) body.set(name, value);
      const token = forms[0].querySelector('input[type="hidden"]');
      if (token) body.set(token.name, token.value);
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(url, {
          method: 'POST', body, credentials: 'same-origin', signal: controller.signal,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...window.SynapseCsrf?.headers(),
                      ...(background ? { 'X-Synapse-Feedback': 'toast' } : {}) }
        });
        window.SynapseCsrf?.fromResponse(response);
        const result = await response.json();
        window.SynapseCsrf?.sync(result.csrf);
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save the encounter.');
        return result;
      } catch (error) {
        if (error.name === 'AbortError') throw new Error('Saving timed out. Your entries are still here; retry saving before leaving.');
        if (error instanceof SyntaxError) throw new Error('The server did not confirm saving. Your entries are still here; please retry.');
        throw error;
      } finally { clearTimeout(timer); }
    }
    async function save(complete = false) {
      if (busy) return false;
      if (complete && (!medicineReady() || !validateAll())) return false;
      if (!complete) {
        // Drafts may be incomplete, but invalid values must never turn into silent clears.
        for (const field of forms.flatMap(form => Array.from(form.elements))) {
          if (field.validity && !field.validity.valid && !field.validity.valueMissing) {
            showStep('#' + field.closest('.tab-pane').id);
            field.reportValidity();
            announce('Correct the highlighted field before saving or leaving this step.', true);
            return false;
          }
        }
      }
      const values = complete ? snapshot() : patch();
      if (!complete && !Object.keys(values).length) return true;
      clearTimeout(autosaveTimer);
      busy = true;
      root.inert = true;
      root.setAttribute('aria-busy', 'true');
      announce(complete ? 'Saving and completing encounter…' : 'Saving…');
      try {
        const result = await post(complete ? root.querySelector('[data-complete-encounter]').action : root.dataset.draftUrl, values);
        Object.assign(saved, values);
        clearLocalBackup();
        forms.forEach(form => {
          const owned = fields.filter(field => field.form === form);
          if (owned.some(field => Object.hasOwn(values, field.name)) && owned.every(field => field.value === saved[field.name])) {
            window.SynapseNavigation?.markSaved(form);
          }
        });
        announce('Saved');
        if (complete) {
          leaving = true;
          window.location.assign(result.redirect);
        }
        return true;
      } catch (error) {
        const message = error.message || 'Saving failed. Your entries are still here; retry before leaving.';
        announce(complete ? 'Completion could not be confirmed. Check the saved encounter in a new tab before retrying. ' + message : message, true);
        if (complete && review) review.hidden = false;
        if (complete) retry.hidden = true;
        return false;
      } finally {
        busy = false;
        root.inert = false;
        root.removeAttribute('inert');
        root.removeAttribute('aria-busy');
      }
    }

    function scheduleAutosave() {
      persistLocalBackup();
      clearTimeout(autosaveTimer);
      if (!dirty()) {
        announce('Saved');
        return;
      }
      announce('Unsaved changes (auto-saving…)');
      autosaveTimer = setTimeout(() => {
        if (!busy && dirty()) void save(false);
      }, 3500);
    }

    on(root, 'input', event => {
      if (event.target.matches?.('[data-chief-complaint-input]')) {
        event.target.dataset.autoComposed = '0';
      }
      scheduleAutosave();
    });
    on(root, 'change', event => {
      if (event.target.matches?.('[data-complaint-category]')) {
        syncQualifierOptions(false);
      } else if (event.target.matches?.('[data-complaint-qualifier]')) {
        const catEl = root.querySelector('[data-complaint-category]');
        const chiefEl = root.querySelector('[data-chief-complaint-input]');
        const meta = catEl ? complaintCatalog[catEl.value] : null;
        if (chiefEl && meta && (!chiefEl.value.trim() || chiefEl.dataset.autoComposed === '1')) {
          chiefEl.value = event.target.value ? `${meta.label} (${event.target.value})` : meta.label;
          chiefEl.dataset.autoComposed = '1';
        }
      }
      scheduleAutosave();
    });
    if (discardBackupBtn && backupBanner) {
      on(discardBackupBtn, 'click', () => { clearLocalBackup(); });
    }
    if (saveDraftManualBtn) {
      on(saveDraftManualBtn, 'click', () => { void save(false); });
    }
    on(root, 'show.bs.tab', event => {
      if (event.target === allowedTab) return;
      if (busy) { event.preventDefault(); return; }
      if (!dirty()) return;
      event.preventDefault();
      const target = event.target.dataset.bsTarget;
      void save().then(ok => { if (ok) showStep(target); });
    });
    on(root, 'submit', event => {
      if (!forms.includes(event.target)) return;
      event.preventDefault();
      const form = event.target;
      void save(form.hasAttribute('data-complete-encounter')).then(ok => {
        if (ok && form.dataset.nextStep) showStep(form.dataset.nextStep);
      });
    });
    // Runs after the shared confirmation controller has granted dispensing consent.
    on(root, 'submit', event => {
      if (!medicine || event.target !== medicine) return;
      event.preventDefault();
      if (busy || dispensed) return;
      void save().then(async ok => {
        if (!ok || busy || dispensed) return;
        const mutations = window.SynapseMutations;

        busy = true;
        root.inert = true;
        announce('Dispensing medicine…');
        try {
          const values = Object.fromEntries(new FormData(medicine));
          const result = await post(medicine.action, values, Boolean(mutations));
          dispensed = true;
          if (mutations) {
            // Release the private navigation veto only after stock deduction is confirmed.
            busy = false;
            root.inert = false;
            root.removeAttribute('inert');
            medicine.inert = true;
            announce('Medicine dispensed');
            await mutations.refresh(location.href, result.message, { form: medicine, descriptor: result.refresh });
          } else {
            leaving = true;
            window.location.assign(result.redirect);
          }
        } catch (error) {
          // Inventory feedback is not a clinical draft-save failure and must not offer its retry.
          announce(dispensed ? 'Medicine was dispensed, but refreshing failed. Do not dispense again; reload to review.' : error.message, !dispensed, false);
        }
        finally { busy = false; root.inert = false; root.removeAttribute('inert'); }
      });
    });
    on(retry, 'click', () => { void save(); });
    on(root.querySelector('[data-continue-outcome]'), 'click', () => {
      if (medicineReady()) void save().then(ok => { if (ok) showStep('#step-outcome'); });
    });
    on(root.querySelector('[data-discard-medicine]'), 'click', () => {
      if (pendingMedicine() && !window.confirm('Discard this unfinished medicine entry? Already dispensed medicines are not affected.')) return;
      medicineFields.forEach((field, index) => {
        field.value = medicineDefaults[index];
        field.dispatchEvent(new Event('change', { bubbles: true }));
      });
      announce(dirty() ? 'Unsaved changes' : 'Saved');
    });
    async function leave(url) {
      if (busy || !medicineReady()) return;
      if (await save()) { leaving = true; window.location.assign(url); }
    }
    on(document, 'synapse:before-region-swap', event => {
      if (event.detail?.root !== root || !event.detail.regions?.includes('dispense-form')) return;
      if (busy && !dispensed) event.preventDefault();
      else { root.inert = false; root.removeAttribute('inert'); }
    });
    on(document, 'synapse:region-load', event => {
      if (event.detail?.root !== root || !event.detail.regions?.includes('dispense-form')) return;
      medicine = root.querySelector('#step-dispense form');
      medicineFields = medicine ? ['medicine_id', 'quantity', 'instructions'].map(name => medicine.elements.namedItem(name)) : [];
      medicineDefaults = medicineFields.map(field => field.value);
      dispensed = false;
      announce('Medicine dispensed');
    });
    on(document, 'synapse:before-swap', event => {
      if (!root.isConnected || !event.detail.root.contains(root) || event.detail.url !== location.href) return;
      const next = event.detail.incoming?.querySelector('[data-encounter-stepper]');
      if (!next) return;
      const target = root.querySelector('[data-bs-toggle="tab"].active')?.dataset.bsTarget;
      const tabs = [...next.querySelectorAll('[data-bs-toggle="tab"]')];
      if (!tabs.some(tab => tab.dataset.bsTarget === target)) return;
      tabs.forEach(tab => {
        const active = tab.dataset.bsTarget === target;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', String(active));
        const pane = next.querySelector(tab.dataset.bsTarget);
        pane?.classList.toggle('active', active);
        pane?.classList.toggle('show', active);
      });
    });
    on(document, 'synapse:before-navigate', event => {
      if (leaving || (!dirty() && !pendingMedicine() && !busy)) return;
      event.preventDefault();
      void leave(event.detail.url);
    });
    on(document, 'click', event => {
      const link = event.target.closest('a[href]');
      if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
          link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-app-modal-open') || link.getAttribute('href').startsWith('#')) return;
      if (!dirty() && !pendingMedicine() && !busy) return;
      const url = new URL(link.href, location.href);
      if (!/^https?:$/.test(url.protocol)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      void leave(url.href);
    }, true);
    on(window, 'beforeunload', event => {
      if (!leaving && (dirty() || pendingMedicine() || busy)) { event.preventDefault(); event.returnValue = ''; }
    });
    return () => {
      clearTimeout(autosaveTimer);
      events.abort();
    };
  }
  window.SynapseEncounterStepper = { init };
})();
