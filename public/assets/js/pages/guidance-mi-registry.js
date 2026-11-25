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

  function tokenize(raw) {
    return String(raw || '')
      .trim()
      .toLowerCase()
      .split(/[\s,]+/)
      .filter(Boolean);
  }

  function matchesTokens(haystack, tokens) {
    if (tokens.length === 0) return true;
    return tokens.every(tok => haystack.includes(tok));
  }

  function formatCount(n) {
    return Number(n || 0).toLocaleString();
  }

  function init(root) {
    formsWithin(root).forEach(form => {
      if (instances.has(form)) return;
      const events = new AbortController();
      let searchTimer = null;
      let activeFetch = null;
      let lastServerQuery = '';
      let lastServerIds = null;

      const controller = {
        destroy() {
          if (searchTimer !== null) {
            clearTimeout(searchTimer);
            searchTimer = null;
          }
          if (activeFetch) {
            activeFetch.abort();
            activeFetch = null;
          }
          events.abort();
        }
      };
      instances.set(form, controller);
      form.dataset.miAssignBound = '1';

      const radios = [...form.querySelectorAll('input[name="assign_scope"]')];
      const individual = form.querySelector('#mi-assign-individual-panel');
      const cohort = form.querySelector('#mi-assign-cohort-panel');
      const search = form.querySelector('#mi-student-checklist-search');
      const searchStatus = form.querySelector('#mi-student-search-status');
      const checklist = form.querySelector('.gmi-student-checklist');
      const empty = form.querySelector('#mi-student-checklist-empty');
      const count = form.querySelector('#mi-selected-count');
      const selectVisible = form.querySelector('#mi-select-visible-btn');
      const clear = form.querySelector('#mi-clear-selected-btn');
      const allStudents = form.querySelector('#mi-assign-all-students');
      const cohortFilters = [...form.querySelectorAll('.mi-cohort-filter-input')];

      const searchUrl = (form.dataset.studentSearchUrl || '').trim();
      const totalStudents = Number.parseInt(form.dataset.totalStudents || '0', 10) || 0;
      const rowsById = new Map();
      const on = (element, name, callback) => element?.addEventListener(name, callback, { signal: events.signal });

      function allRows() {
        return [...rowsById.values()];
      }

      function allCheckboxes() {
        return allRows()
          .map(row => row.querySelector('.mi-student-checkbox'))
          .filter(Boolean);
      }

      function isCohortMode() {
        return radios.some(radio => radio.checked && radio.value === 'cohort');
      }

      function setStatus(message) {
        if (!searchStatus) return;
        const text = String(message || '').trim();
        searchStatus.textContent = text;
        searchStatus.classList.toggle('d-none', text === '');
      }

      function updateCount() {
        if (count) {
          count.textContent = String(allCheckboxes().filter(checkbox => checkbox.checked).length);
        }
      }

      function buildSearchIndex(item) {
        const altName = [item.first_name || '', item.last_name || ''].filter(Boolean).join(' ').trim();
        return [item.name || '', altName, item.meta || '', item.mis_id || '', item.program || '', item.year_level ? 'y' + item.year_level : '']
          .join(' ')
          .toLowerCase();
      }

      function ensureStudentRow(item) {
        const personId = String(item.id || '').trim();
        if (!personId) return null;
        if (rowsById.has(personId)) {
          return rowsById.get(personId);
        }
        if (!checklist) return null;

        const row = document.createElement('div');
        row.className = 'form-check mi-student-check-row gmi-check-option';
        row.dataset.personId = personId;
        row.dataset.preloaded = '0';
        row.dataset.search = buildSearchIndex(item);

        const checkbox = document.createElement('input');
        checkbox.className = 'form-check-input mi-student-checkbox';
        checkbox.type = 'checkbox';
        checkbox.name = 'person_ids[]';
        checkbox.value = personId;
        checkbox.id = 'mi-stu-check-' + personId;
        checkbox.disabled = isCohortMode();
        on(checkbox, 'change', updateCount);

        const label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = checkbox.id;

        const nameSpan = document.createElement('span');
        nameSpan.className = 'gmi-person-name';
        nameSpan.textContent = String(item.name || '');

        const metaSpan = document.createElement('span');
        metaSpan.className = 'gmi-meta';
        metaSpan.textContent = String(item.meta || '');

        label.append(nameSpan, metaSpan);

        if (item.has_active) {
          const activeTag = document.createElement('span');
          activeTag.className = 'ce-tag gmi-active-assignment';
          const statusDot = document.createElement('span');
          statusDot.className = 'ce-status-dot ce-status-sky';
          statusDot.textContent = 'Active assignment';
          activeTag.append(statusDot);
          label.append(activeTag);
        }

        row.append(checkbox, label);
        if (empty && empty.parentElement === checklist) {
          checklist.insertBefore(row, empty);
        } else {
          checklist.append(row);
        }
        rowsById.set(personId, row);
        return row;
      }

      form.querySelectorAll('.mi-student-check-row').forEach(row => {
        const checkbox = row.querySelector('.mi-student-checkbox');
        const personId = String(row.dataset.personId || checkbox?.value || '').trim();
        if (!personId || !checkbox) return;
        row.dataset.personId = personId;
        if (!row.dataset.preloaded) row.dataset.preloaded = '1';
        rowsById.set(personId, row);
        on(checkbox, 'change', updateCount);
      });

      const preloadedCount = allRows().filter(row => row.dataset.preloaded === '1').length;

      function syncCohort() {
        const isCohort = isCohortMode();
        const allChecked = Boolean(allStudents?.checked);
        if (allStudents) allStudents.disabled = !isCohort;
        cohortFilters.forEach(control => { control.disabled = !isCohort || allChecked; });
      }

      function syncMode() {
        const isCohort = isCohortMode();
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

      function applyVisibility(matchedIdSet = null) {
        const term = search?.value.trim() || '';
        const tokens = tokenize(term);
        let visibleCount = 0;

        rowsById.forEach(row => {
          const checkbox = row.querySelector('.mi-student-checkbox');
          let visible;
          if (!term) {
            visible = row.dataset.preloaded === '1' || Boolean(checkbox?.checked);
          } else if (matchedIdSet !== null) {
            visible = matchedIdSet.has(row.dataset.personId || '');
          } else {
            visible = matchesTokens(row.dataset.search || '', tokens);
          }
          row.classList.toggle('d-none', !visible);
          if (visible) visibleCount++;
        });

        empty?.classList.toggle('d-none', visibleCount > 0 || rowsById.size === 0);
      }

      async function runServerSearch(query) {
        if (!searchUrl || events.signal.aborted) return;
        if (activeFetch) {
          activeFetch.abort();
        }
        activeFetch = new AbortController();
        const requestUrl = searchUrl + (searchUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);

        try {
          const response = await fetch(requestUrl, {
            method: 'GET',
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            signal: activeFetch.signal
          });
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          const payload = await response.json();
          if (!payload || payload.success !== true) {
            throw new Error(payload?.message || 'Invalid search response');
          }
          if ((search?.value.trim() || '') !== query || events.signal.aborted) {
            return;
          }

          const results = Array.isArray(payload.results) ? payload.results : [];
          const matchedIds = new Set();
          results.forEach(item => {
            const row = ensureStudentRow(item);
            if (row?.dataset.personId) {
              matchedIds.add(row.dataset.personId);
            }
          });

          lastServerQuery = query;
          lastServerIds = matchedIds;
          applyVisibility(matchedIds);
          updateCount();

          const totalMatches = Number.parseInt(String(payload.total_matches ?? results.length), 10) || 0;
          if (totalMatches === 0) {
            setStatus('');
          } else if (totalMatches > results.length) {
            setStatus(`Showing first ${formatCount(results.length)} of ${formatCount(totalMatches)} matches — refine your search to narrow down.`);
          } else {
            setStatus(`${formatCount(totalMatches)} matching ${totalMatches === 1 ? 'student' : 'students'}`);
          }
        } catch (error) {
          if (error?.name === 'AbortError' || events.signal.aborted) {
            return;
          }
          setStatus('Live search unavailable — showing loaded students only.');
        } finally {
          activeFetch = null;
        }
      }

      function handleSearchInput() {
        if (searchTimer !== null) {
          clearTimeout(searchTimer);
          searchTimer = null;
        }
        if (activeFetch) {
          activeFetch.abort();
          activeFetch = null;
        }

        const term = search?.value.trim() || '';
        if (term === '' || term !== lastServerQuery) {
          lastServerQuery = '';
          lastServerIds = null;
        }

        applyVisibility(lastServerIds);

        if (!term) {
          setStatus('');
          return;
        }
        if (term.length < 2) {
          if (totalStudents > preloadedCount) {
            setStatus(`Type at least 2 characters to search all ${formatCount(totalStudents)} students.`);
          } else {
            setStatus('');
          }
          return;
        }
        if (!searchUrl) {
          setStatus('');
          return;
        }

        setStatus('Searching all students…');
        searchTimer = setTimeout(() => {
          searchTimer = null;
          runServerSearch(term);
        }, 220);
      }

      function setChecked(checkbox, checked) {
        if (checkbox.checked === checked || checkbox.disabled) return;
        checkbox.checked = checked;
        // Bulk selection must also participate in the shared unsaved-edit guard.
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      }

      radios.forEach(radio => on(radio, 'change', syncMode));
      on(search, 'input', handleSearchInput);
      on(allStudents, 'change', syncCohort);
      on(selectVisible, 'click', () => {
        allRows().filter(row => !row.classList.contains('d-none')).forEach(row => {
          const checkbox = row.querySelector('.mi-student-checkbox');
          if (checkbox) setChecked(checkbox, true);
        });
        updateCount();
      });
      on(clear, 'click', () => {
        allCheckboxes().forEach(checkbox => setChecked(checkbox, false));
        applyVisibility(search?.value.trim() ? lastServerIds : null);
        updateCount();
      });
      on(form, 'reset', () => {
        queueMicrotask(() => {
          if (!form.isConnected || events.signal.aborted) return;
          if (searchTimer !== null) {
            clearTimeout(searchTimer);
            searchTimer = null;
          }
          if (activeFetch) {
            activeFetch.abort();
            activeFetch = null;
          }
          lastServerQuery = '';
          lastServerIds = null;
          setStatus('');
          syncMode();
          applyVisibility(null);
          updateCount();
        });
      });

      syncMode();
      applyVisibility(null);
      updateCount();
    });
  }

  function destroy(root) {
    for (const [form, controller] of instances) {
      if (form.isConnected && root !== form && !root?.contains(form)) continue;
      controller.destroy();
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
