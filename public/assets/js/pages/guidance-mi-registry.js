/** MI registry assignment controls; native fields remain authoritative. */
(function () {
  'use strict';
  if (window.SynapseMiRegistry) return;
  const instances = new Map();

  function formsWithin(root) {
    if (!root) return [];
    return [
      ...(root.matches?.('[data-mi-assignment-form]') ? [root] : []),
      ...root.querySelectorAll('[data-mi-assignment-form]')
    ];
  }

  function init(root) {
    formsWithin(root).forEach(form => {
      if (instances.has(form)) return;
      const events = new AbortController();
      instances.set(form, events);
      form.dataset.miAssignBound = '1';
      const radios = [...form.querySelectorAll('input[name="assign_scope"]')];
      const individual = form.querySelector('#mi-assign-individual-panel');
      const cohort = form.querySelector('#mi-assign-cohort-panel');
      const search = form.querySelector('#mi-student-checklist-search');
      const rows = [...form.querySelectorAll('.mi-student-check-row')];
      const checkboxes = [...form.querySelectorAll('.mi-student-checkbox')];
      const empty = form.querySelector('#mi-student-checklist-empty');
      const count = form.querySelector('#mi-selected-count');
      const selectVisible = form.querySelector('#mi-select-visible-btn');
      const clear = form.querySelector('#mi-clear-selected-btn');
      const allStudents = form.querySelector('#mi-assign-all-students');
      const cohortFilters = [...form.querySelectorAll('.mi-cohort-filter-input')];
      const on = (element, name, callback) => element?.addEventListener(name, callback, { signal: events.signal });

      function updateCount() {
        if (count) count.textContent = String(checkboxes.filter(checkbox => checkbox.checked).length);
      }
      function syncCohort() {
        const isCohort = radios.some(radio => radio.checked && radio.value === 'cohort');
        const allChecked = Boolean(allStudents?.checked);
        if (allStudents) allStudents.disabled = !isCohort;
        cohortFilters.forEach(control => { control.disabled = !isCohort || allChecked; });
      }
      function syncMode() {
        const isCohort = radios.some(radio => radio.checked && radio.value === 'cohort');
        radios.forEach(radio => {
          const label = form.querySelector('label[for="' + radio.id + '"]');
          const active = radio.checked;
          label?.classList.toggle('btn-primary', active);
          label?.classList.toggle('btn-outline-secondary', !active);
        });
        individual?.classList.toggle('d-none', isCohort);
        individual?.querySelectorAll('input, select, button').forEach(control => { control.disabled = isCohort; });
        cohort?.classList.toggle('d-none', !isCohort);
        syncCohort();
      }
      function filterChecklist() {
        const term = search?.value.trim().toLowerCase() || '';
        let visibleCount = 0;
        rows.forEach(row => {
          const visible = !term || (row.dataset.search || '').includes(term);
          row.classList.toggle('d-none', !visible);
          if (visible) visibleCount++;
        });
        empty?.classList.toggle('d-none', visibleCount > 0 || rows.length === 0);
      }
      function setChecked(checkbox, checked) {
        if (checkbox.checked === checked || checkbox.disabled) return;
        checkbox.checked = checked;
        // Bulk selection must also participate in the shared unsaved-edit guard.
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      }

      radios.forEach(radio => on(radio, 'change', syncMode));
      checkboxes.forEach(checkbox => on(checkbox, 'change', updateCount));
      on(search, 'input', filterChecklist);
      on(allStudents, 'change', syncCohort);
      on(selectVisible, 'click', () => {
        rows.filter(row => !row.classList.contains('d-none')).forEach(row => {
          const checkbox = row.querySelector('.mi-student-checkbox');
          if (checkbox) setChecked(checkbox, true);
        });
        updateCount();
      });
      on(clear, 'click', () => {
        checkboxes.forEach(checkbox => setChecked(checkbox, false));
        updateCount();
      });
      on(form, 'reset', () => {
        queueMicrotask(() => {
          if (!form.isConnected || events.signal.aborted) return;
          syncMode();
          filterChecklist();
          updateCount();
        });
      });
      syncMode();
      filterChecklist();
      updateCount();
    });
  }

  function destroy(root) {
    for (const [form, events] of instances) {
      if (form.isConnected && root !== form && !root?.contains(form)) continue;
      events.abort();
      instances.delete(form);
      delete form.dataset.miAssignBound;
    }
  }

  window.SynapseMiRegistry = { init, destroy };
  document.addEventListener('synapse:page-load', event => init(event.detail?.root));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
  if (!document.body.classList.contains('is-navigating')) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init(document), { once: true });
    else init(document);
  }
})();
