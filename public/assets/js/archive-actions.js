/**
 * Silent server-confirmed archive/restore row actions with live counter sync and hover-paused Undo toast.
 */
(function () {
  'use strict';

  if (window.SynapseArchiveActions) return;
  window.SynapseArchiveActions = true;

  const lockedForms = new WeakSet();
  const lockedRows = new WeakSet();
  const NO_UNDO_ENTITIES = new Set(['bmg_unit']);

  function csrfPair() {
    const name = document.querySelector('meta[name="csrf-name"]')?.content || '';
    const hash = document.querySelector('meta[name="csrf-token"]')?.content || '';
    return [name, hash];
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/([^\w-])/g, '\\$1');
  }

  function sameOriginPostUrl(rawUrl) {
    try {
      const parsed = new URL(rawUrl || location.href, location.href);
      if (parsed.origin !== location.origin || parsed.username || parsed.password) return null;
      return parsed;
    } catch (_) {
      return null;
    }
  }

  function findTargetRow(form, modalEntityType, modalEntityId) {
    if (form.dataset.archiveModal) {
      const candidate = window.__synapseArchiveActiveRow;
      const expectedKey = modalEntityType && modalEntityId ? `${modalEntityType}:${modalEntityId}` : '';
      if (candidate instanceof Element && candidate.isConnected) {
        const key = candidate.getAttribute('data-arc-row-key') || '';
        if (!key || !expectedKey || key === expectedKey) {
          return candidate;
        }
      }
      if (expectedKey) {
        return document.querySelector(`[data-arc-row-key="${cssEscape(expectedKey)}"]`);
      }
      return null;
    }

    if (form.dataset.archiveRow) {
      return form.closest(form.dataset.archiveRow);
    }
    return form.closest('[data-archive-item], tr, li.slide-row, article');
  }

  function applyCounterDelta(form, sign) {
    const snapshots = [];
    const seen = new Set();

    function updateGroup(selectorList, delta) {
      if (!selectorList) return;
      selectorList.split(',').map(s => s.trim()).filter(Boolean).forEach(selector => {
        document.querySelectorAll(selector).forEach(el => {
          if (seen.has(el)) return;
          seen.add(el);
          const prevText = el.textContent || '0';
          const parsed = parseInt(prevText.replace(/[^0-9-]/g, ''), 10);
          if (!Number.isFinite(parsed)) return;
          snapshots.push({ el, prevText });
          const next = Math.max(0, parsed + delta);
          el.textContent = prevText.includes(',') || next >= 1000 ? next.toLocaleString() : String(next);
        });
      });
    }

    updateGroup(form.dataset.archiveCounterDec || '', -1 * sign);
    updateGroup(form.dataset.archiveCounterInc || '', 1 * sign);

    return {
      revert() {
        snapshots.forEach(({ el, prevText }) => {
          if (el.isConnected) {
            el.textContent = prevText;
          }
        });
      }
    };
  }

  function hideTooltips(root) {
    if (!root || !window.bootstrap?.Tooltip) return;
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      try {
        window.bootstrap.Tooltip.getInstance(el)?.hide();
      } catch (_) {}
    });
  }

  function hideBootstrapModal(modalId) {
    if (!modalId || !window.bootstrap?.Modal) return;
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;
    window.bootstrap.Modal.getInstance(modalEl)?.hide();
  }

  function resolveUndoLabel(actionKind, explicitLabel, modalEntityType) {
    if (modalEntityType) {
      if (NO_UNDO_ENTITIES.has(modalEntityType)) return '';
      if (actionKind === 'archive') {
        if (modalEntityType === 'announcement') return 'Restore as draft';
        if (modalEntityType === 'carousel_slide') return 'Restore (hidden)';
      }
    }
    return explicitLabel || (actionKind === 'restore' ? 'Re-archive' : 'Undo');
  }

  async function removeRowSmoothly(row) {
    if (!row || !row.parentNode) return null;

    hideTooltips(row);
    const parent = row.parentNode;
    const nextSibling = row.nextSibling;
    const tableBody = row.closest('tbody');
    const reduceMotion = Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches);

    row.classList.remove('syn-archive-pending', 'syn-archive-restored');
    row.classList.add('syn-archive-leaving');
    if (!reduceMotion) {
      await new Promise(resolve => setTimeout(resolve, 180));
    }

    row.classList.remove('syn-archive-leaving');
    if (row.parentNode === parent) {
      row.remove();
    }

    let placeholderTr = null;
    if (tableBody) {
      const remaining = [...tableBody.querySelectorAll('tr')].filter(
        tr => !tr.hasAttribute('data-archive-empty-placeholder')
      );
      if (remaining.length === 0) {
        const colCount = tableBody.closest('table')?.querySelector('thead tr')?.children.length
          || row.children.length
          || 1;
        placeholderTr = document.createElement('tr');
        placeholderTr.setAttribute('data-archive-empty-placeholder', '1');
        const td = document.createElement('td');
        td.colSpan = Math.max(1, colCount);
        td.className = 'text-center text-muted py-4 small';
        td.textContent = 'No records remaining in this view.';
        placeholderTr.appendChild(td);
        tableBody.appendChild(placeholderTr);
      }
    }

    return { parent, nextSibling, placeholderTr };
  }

  function restoreKeptRecordRow(row) {
    const pill = row.querySelector('.arc-pill.is-archived');
    const restoreBtn = row.querySelector('[data-arc-open-restore-modal]');
    const state = {
      pill,
      prevPillClass: pill ? pill.className : '',
      prevPillText: pill ? pill.textContent : '',
      restoreBtn,
      btnParent: restoreBtn ? restoreBtn.parentNode : null,
      btnNext: restoreBtn ? restoreBtn.nextSibling : null,
    };

    if (pill) {
      pill.className = 'arc-pill is-restored';
      pill.textContent = 'Restored';
    }
    if (restoreBtn) {
      restoreBtn.remove();
    }
    row.classList.remove('syn-archive-pending');
    row.classList.add('syn-archive-restored');
    setTimeout(() => row.classList.remove('syn-archive-restored'), 1000);

    return {
      revert() {
        if (state.pill) {
          state.pill.className = state.prevPillClass;
          state.pill.textContent = state.prevPillText;
        }
        if (state.restoreBtn && state.btnParent && state.btnParent.isConnected) {
          if (state.btnNext && state.btnNext.parentNode === state.btnParent) {
            state.btnParent.insertBefore(state.restoreBtn, state.btnNext);
          } else {
            state.btnParent.appendChild(state.restoreBtn);
          }
        }
        row.classList.add('syn-archive-restored');
        setTimeout(() => row.classList.remove('syn-archive-restored'), 1000);
      }
    };
  }

  async function postJsonAction(url, formData) {
    const [csrfName, csrfHash] = csrfPair();
    if (csrfName && csrfHash && !formData.has(csrfName)) {
      formData.set(csrfName, csrfHash);
    }

    const response = await fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      redirect: 'error',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Synapse-Feedback': 'toast',
        ...window.SynapseCsrf?.headers(),
      },
    });
    window.SynapseCsrf?.fromResponse(response);

    const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
    if (!contentType.includes('application/json')) {
      throw new Error('The server returned an unexpected response. Refresh and check the record before retrying.');
    }

    const payload = await response.json();
    window.SynapseCsrf?.sync(payload?.csrf);

    if (!response.ok || payload?.success !== true) {
      throw new Error(typeof payload?.message === 'string' && payload.message
        ? payload.message
        : 'Unable to complete the archive update. Please try again.');
    }

    return payload;
  }

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-archive-async]') || event.defaultPrevented) {
      return;
    }
    if (!window.fetch || !window.SynapseToast) {
      return;
    }

    const submitter = event.submitter || null;
    const rawAction = submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href;
    const actionUrl = sameOriginPostUrl(rawAction);
    if (!actionUrl || (form.method || 'POST').toUpperCase() !== 'POST') {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const isModal = Boolean(form.dataset.archiveModal);
    const modalEntityType = isModal ? String(form.querySelector('[name="entity_type"]')?.value || '').trim() : '';
    const modalEntityId = isModal ? String(form.querySelector('[name="entity_id"]')?.value || '').trim() : '';
    const row = findTargetRow(form, modalEntityType, modalEntityId);

    if (lockedForms.has(form) || (row && lockedRows.has(row))) {
      return;
    }

    const controls = [...form.querySelectorAll('button, input[type="submit"]')].map(btn => [btn, btn.disabled]);
    const formData = submitter ? new FormData(form, submitter) : new FormData(form);
    const versionStep = form.dataset.archiveVersionStep === '1';
    const origVersion = versionStep
      ? parseInt(String(form.querySelector('input[name="version"]')?.value || '0'), 10)
      : 0;
    const undoVersion = origVersion > 0 ? origVersion + 1 : 0;
    const actionKind = form.dataset.archiveAsync || 'archive';

    lockedForms.add(form);
    if (row) {
      lockedRows.add(row);
      row.classList.add('syn-archive-pending');
    }
    controls.forEach(([btn]) => { btn.disabled = true; });

    function releaseLocks() {
      lockedForms.delete(form);
      if (row) {
        lockedRows.delete(row);
        row.classList.remove('syn-archive-pending');
      }
      controls.forEach(([btn, disabled]) => { btn.disabled = disabled; });
    }

    try {
      const payload = await postJsonAction(actionUrl.href, formData);

      if (isModal) {
        hideBootstrapModal(form.dataset.archiveModal);
        window.__synapseArchiveActiveRow = null;
      }

      const counterOps = applyCounterDelta(form, 1);
      const keepRow = Boolean(row && row.closest('[data-arc-keep-row-on-restore="1"]') && actionKind === 'restore');
      let keptHandle = null;
      let removedBookmark = null;

      if (row) {
        if (keepRow) {
          keptHandle = restoreKeptRecordRow(row);
        } else {
          removedBookmark = await removeRowSmoothly(row);
        }
      }

      releaseLocks();

      const rawUndoUrl = form.dataset.archiveUndoUrl || '';
      const undoUrlObj = rawUndoUrl ? sameOriginPostUrl(rawUndoUrl) : null;
      const undoLabel = resolveUndoLabel(actionKind, form.dataset.archiveUndoLabel || '', modalEntityType);
      const defaultUndoReason = actionKind === 'restore'
        ? 'Re-archived from notification toast'
        : 'Undo archive from notification toast';
      const undoReason = form.dataset.archiveUndoReason || defaultUndoReason;

      let toastHandle = null;
      const toastOptions = {
        type: 'success',
        duration: undoUrlObj && undoLabel ? 8000 : 5000,
      };

      if (undoUrlObj && undoLabel) {
        toastOptions.action = {
          label: undoLabel,
          run: async () => {
            try {
              const undoData = new FormData();
              if (isModal && modalEntityType && modalEntityId) {
                undoData.set('entity_type', modalEntityType);
                undoData.set('entity_id', modalEntityId);
              }
              undoData.set('reason', undoReason);
              if (versionStep && undoVersion > 0) {
                undoData.set('version', String(undoVersion));
              }

              const undoPayload = await postJsonAction(undoUrlObj.href, undoData);

              if (keptHandle) {
                keptHandle.revert();
              } else if (row && removedBookmark) {
                if (removedBookmark.placeholderTr?.isConnected) {
                  removedBookmark.placeholderTr.remove();
                }
                if (versionStep && undoVersion > 0) {
                  const nextVersion = String(undoVersion + 1);
                  row.querySelectorAll('input[name="version"]').forEach(inp => {
                    inp.value = nextVersion;
                  });
                }
                const { parent, nextSibling } = removedBookmark;
                if (parent && parent.isConnected) {
                  if (nextSibling && nextSibling.parentNode === parent) {
                    parent.insertBefore(row, nextSibling);
                  } else {
                    parent.appendChild(row);
                  }
                  row.classList.add('syn-archive-restored');
                  setTimeout(() => row.classList.remove('syn-archive-restored'), 1000);
                }
              }

              counterOps.revert();
              toastHandle?.dismiss?.();
              window.SynapseToast.show(
                typeof undoPayload.message === 'string' && undoPayload.message ? undoPayload.message : 'Action undone.',
                { type: 'info', duration: 4000 }
              );
            } catch (undoErr) {
              window.SynapseToast.show(
                undoErr instanceof Error ? undoErr.message : 'Unable to undo this archive action.',
                { type: 'error', key: 'archive-undo-error' }
              );
            }
          },
        };
      }

      toastHandle = window.SynapseToast.show(
        typeof payload.message === 'string' && payload.message ? payload.message : 'Updated.',
        toastOptions
      );
    } catch (err) {
      releaseLocks();
      window.SynapseToast.show(
        err instanceof Error ? err.message : 'Unable to complete the archive request.',
        { type: 'error', key: 'archive-action-error' }
      );
    }
  });
})();
