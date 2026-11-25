/**
 * CSP-safe interactions for Student Intern Placement assignment and unit-supervisor filtering.
 * Uses delegated listeners so both dynamically loaded modals and direct page views work cleanly.
 */
(function () {
  'use strict';

  const timers = new WeakMap();
  const controllers = new WeakMap();

  function syncSupervisorOptions(modal) {
    const unitSelect = modal.querySelector('[data-intern-unit-select]');
    const supervisorSelect = modal.querySelector('[data-intern-supervisor-select]');
    if (!supervisorSelect) return;

    const selectedUnit = unitSelect
      ? unitSelect.value
      : (modal.querySelector('input[name="unit"]')?.value ?? '');

    let currentValid = false;
    for (const option of supervisorSelect.options) {
      if (option.value === '') continue;
      const optUnit = option.dataset.unit || '';
      const matches = selectedUnit !== '' && optUnit === selectedUnit;
      option.hidden = !matches;
      option.disabled = !matches;
      if (matches && option.selected) {
        currentValid = true;
      }
    }

    if (!currentValid) {
      supervisorSelect.value = '';
    }
  }

  function clearSelectedStudent(picker) {
    const modal = picker.closest('[data-intern-form-modal]');
    const hiddenInput = modal?.querySelector('#intern-selected-user-id');
    const summary = picker.querySelector('[data-intern-selected-summary]');
    if (hiddenInput) hiddenInput.value = '';
    if (summary) {
      summary.hidden = true;
      summary.replaceChildren();
    }
  }

  function selectStudent(picker, student) {
    const modal = picker.closest('[data-intern-form-modal]');
    const hiddenInput = modal?.querySelector('#intern-selected-user-id');
    const input = picker.querySelector('#intern-student-lookup');
    const results = picker.querySelector('[data-intern-picker-results]');
    const summary = picker.querySelector('[data-intern-selected-summary]');
    if (!hiddenInput || !summary) return;

    hiddenInput.value = String(student.user_id);
    if (input) input.value = student.name;
    if (results) {
      results.hidden = true;
      results.replaceChildren();
    }

    const label = document.createElement('span');
    label.textContent = `${student.name} (${student.mis_id})${student.program ? ' · ' + student.program : ''}`;
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'btn-close adm-intern-clear-student';
    clearBtn.setAttribute('aria-label', 'Clear selected student');
    clearBtn.addEventListener('click', () => {
      clearSelectedStudent(picker);
      if (input) {
        input.value = '';
        input.focus();
      }
    });

    summary.replaceChildren(label, clearBtn);
    summary.hidden = false;
  }

  function renderStudentOptions(picker, students, emptyMessage) {
    const results = picker.querySelector('[data-intern-picker-results]');
    if (!results) return;
    results.replaceChildren();

    if (!Array.isArray(students) || students.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'adm-intern-picker-empty';
      empty.textContent = emptyMessage || 'No eligible active student accounts found.';
      results.appendChild(empty);
      results.hidden = false;
      return;
    }

    for (const student of students) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'adm-intern-picker-item';
      btn.setAttribute('role', 'option');

      const nameEl = document.createElement('strong');
      nameEl.textContent = student.name;
      const metaEl = document.createElement('small');
      const parts = [student.mis_id];
      if (student.program) parts.push(student.program);
      if (student.year_level) parts.push(`Year ${student.year_level}`);
      metaEl.textContent = parts.join(' · ');

      btn.append(nameEl, metaEl);
      btn.addEventListener('click', () => selectStudent(picker, student));
      results.appendChild(btn);
    }

    results.hidden = false;
  }

  async function runStudentSearch(picker, query) {
    const endpoint = picker.dataset.endpoint;
    if (!endpoint) return;

    controllers.get(picker)?.abort();
    const abortController = new AbortController();
    controllers.set(picker, abortController);

    try {
      const url = new URL(endpoint, location.href);
      url.searchParams.set('q', query);
      const response = await fetch(url.toString(), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: abortController.signal,
      });
      const data = await response.json();
      if (!response.ok || !data?.success) {
        renderStudentOptions(picker, [], data?.message || 'Unable to search students.');
        return;
      }
      renderStudentOptions(picker, data.students || []);
    } catch (error) {
      if (error?.name !== 'AbortError') {
        renderStudentOptions(picker, [], 'Student directory search failed. Retry.');
      }
    }
  }

  document.addEventListener('change', event => {
    const unitSelect = event.target?.closest?.('[data-intern-unit-select]');
    if (unitSelect) {
      const modal = unitSelect.closest('[data-intern-form-modal]');
      if (modal) syncSupervisorOptions(modal);
    }
  });

  document.addEventListener('input', event => {
    const input = event.target?.closest?.('#intern-student-lookup');
    if (!input) return;
    const picker = input.closest('[data-intern-student-picker]');
    if (!picker) return;

    clearSelectedStudent(picker);
    clearTimeout(timers.get(picker));

    const q = input.value.trim();
    const results = picker.querySelector('[data-intern-picker-results]');
    if (q.length < 2) {
      controllers.get(picker)?.abort();
      if (results) {
        results.hidden = true;
        results.replaceChildren();
      }
      return;
    }

    const timer = setTimeout(() => runStudentSearch(picker, q), 220);
    timers.set(picker, timer);
  });

  document.addEventListener('submit', event => {
    const form = event.target?.closest?.('[data-intern-form-modal] form');
    if (!form) return;
    const userIdField = form.querySelector('#intern-selected-user-id');
    const lookup = form.querySelector('#intern-student-lookup');
    if (userIdField && !userIdField.value) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (lookup) {
        lookup.setCustomValidity('Select an enrolled student from the search results.');
        lookup.reportValidity();
        lookup.setCustomValidity('');
      }
    }
  }, true);

  document.addEventListener('synapse:page-load', () => {
    document.querySelectorAll('[data-intern-form-modal]').forEach(syncSupervisorOptions);
  });

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-intern-form-modal]').forEach(syncSupervisorOptions);
  });
})();
