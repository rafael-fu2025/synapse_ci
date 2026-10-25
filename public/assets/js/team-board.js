/** Status moves only: server versions and the refreshed board remain authoritative. */
(function () {
  'use strict';
  if (window.SynapseTeamBoard) return;
  const instances = new Map();
  const cardSelector = 'article[data-task-id]';
  const interactive = 'a, button, input, select, textarea, [contenteditable], [role="button"]';

  function init(board) {
    if (instances.has(board)) return;
    const events = new AbortController();
    const signal = events.signal;
    let selection = null, pointer = null, origin = null, posting = false, stale = false, stopped = false;
    let request = null, timeout = null;
    const busy = board.getAttribute('aria-busy');
    const wasInert = board.inert;
    const handles = [...board.querySelectorAll('[data-task-drag-handle]')];
    const styles = handles.map(handle => [handle, handle.style.touchAction]);
    styles.forEach(([handle]) => { handle.style.touchAction = 'none'; });
    const live = document.createElement('div');
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    Object.assign(live.style, { position: 'absolute', width: '1px', height: '1px', overflow: 'hidden', clipPath: 'inset(50%)' });
    board.append(live);
    const own = element => element?.closest('[data-team-board]') === board;
    const columns = () => [...board.querySelectorAll('[data-task-column]')].filter(own);
    const columnOf = target => {
      const column = target?.closest?.('[data-task-column]');
      return own(column) ? column : null;
    };
    const cardOf = target => {
      const card = target?.closest?.(cardSelector);
      return own(card) && card.getAttribute('draggable') === 'true' ? card : null;
    };
    const available = () => !stopped && !posting && !stale && !document.body.classList.contains('is-navigating');
    function tell(message, type = 'error') {
      live.textContent = message;
      window.SynapseToast?.show(message, { type, key: 'team-task-move' });
    }
    function target(column) {
      if (!selection) return;
      selection.column?.removeAttribute('data-task-drop-target');
      selection.column = column;
      column?.setAttribute('data-task-drop-target', 'true');
      if (column) live.textContent = `Destination: ${column.getAttribute('aria-label') || column.dataset.taskColumn}. Enter to drop, Escape to cancel.`;
    }
    function cancel() {
      if (selection) {
        selection.card.removeAttribute('data-task-dragging');
        selection.column?.removeAttribute('data-task-drop-target');
        selection.handle?.removeAttribute('aria-pressed');
      }
      const held = pointer;
      pointer = null;
      if (held?.handle.hasPointerCapture?.(held.id)) held.handle.releasePointerCapture(held.id);
      selection = null;
    }
    function pickup(card, mode, handle) {
      if (!available()) return false;
      cancel();
      selection = { card, mode, handle, column: null };
      card.setAttribute('data-task-dragging', 'true');
      handle?.setAttribute('aria-pressed', 'true');
      target(columnOf(card));
      return true;
    }
    function lock(value) {
      if (value) board.setAttribute('aria-busy', 'true');
      else if (busy === null) board.removeAttribute('aria-busy');
      else board.setAttribute('aria-busy', busy);
      board.inert = value || wasInert;
    }
    async function move(card, column) {
      cancel();
      if (!available() || !column || column.dataset.taskColumn === card.dataset.taskStatus) return;
      const mutations = window.SynapseMutations;
      if (!window.fetch || !window.SynapseCsrf || !mutations?.refresh || !mutations?.canRefresh()) {
        tell('Finish or save other edits before moving a task.', 'warning');
        return;
      }
      let url;
      try {
        url = new URL(card.dataset.taskMoveUrl, location.href);
        if (!card.dataset.taskMoveUrl || !/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password ||
            !/^[1-9]\d*$/.test(card.dataset.taskVersion || '') || !card.dataset.taskId || !columns().includes(column)) throw new Error();
      } catch (_) { tell('This task cannot be moved. Refresh the board and try again.'); return; }
      const body = new URLSearchParams({ version: card.dataset.taskVersion, status: column.dataset.taskColumn });
      const name = document.querySelector('meta[name="csrf-name"]')?.content;
      const hash = document.querySelector('meta[name="csrf-token"]')?.content;
      if (name && hash) body.set(name, hash);
      posting = true;
      lock(true);
      request = new AbortController();
      timeout = setTimeout(() => request?.abort(), 45000);
      let confirmed = false, knownFailure = false;
      try {
        const response = await fetch(url.href, {
          method: 'POST', body, credentials: 'same-origin', redirect: 'error', signal: request.signal,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            'X-Synapse-Feedback': 'toast', ...window.SynapseCsrf.headers() }
        });
        if (stopped) return;
        window.SynapseCsrf.fromResponse(response);
        if (!(response.headers.get('Content-Type') || '').toLowerCase().includes('application/json')) throw new Error();
        const payload = await response.json();
        if (stopped) return;
        window.SynapseCsrf.sync(payload?.csrf);
        if (!response.ok || payload?.success !== true) {
          knownFailure = payload?.success === false;
          stale = response.status === 409 || !knownFailure;
          tell((typeof payload?.message === 'string' && payload.message) || 'The task could not be moved. Refresh the board before trying again.');
          return;
        }
        confirmed = true;
        stale = true;
        // Release the navigation guard for the helper's GET, never for a second POST.
        posting = false;
        lock(false);
        clearTimeout(timeout);
        await mutations.refresh(location.href, payload.message || 'Task status updated.', { key: 'team-task-move' });
      } catch (_) {
        if (stopped) return;
        stale = true;
        tell(confirmed ? 'Task moved, but refreshing failed. Refresh the board; do not submit again.'
          : 'The move could not be verified and may have been saved. Refresh the board before trying again.');
      } finally {
        clearTimeout(timeout);
        request = null;
        posting = false;
        lock(false);
      }
    }
    board.addEventListener('pointerdown', event => {
      origin = event.target;
      if (event.pointerType === 'mouse' || event.button !== 0 || !event.isPrimary || !available()) return;
      const handle = event.target.closest('[data-task-drag-handle]');
      const card = cardOf(handle);
      if (!handle || !card) return;
      event.preventDefault();
      if (!pickup(card, 'pointer', handle)) return;
      handle.focus({ preventScroll: true });
      pointer = { id: event.pointerId, handle, x: event.clientX, y: event.clientY, moved: false };
      handle.setPointerCapture(event.pointerId);
    }, { signal });
    board.addEventListener('pointermove', event => {
      if (!pointer || pointer.id !== event.pointerId) return;
      event.preventDefault();
      if (Math.hypot(event.clientX - pointer.x, event.clientY - pointer.y) > 8) pointer.moved = true;
      if (pointer.moved) target(columnOf(document.elementFromPoint(event.clientX, event.clientY)));
    }, { signal });
    board.addEventListener('pointerup', event => {
      if (!pointer || pointer.id !== event.pointerId) return;
      event.preventDefault();
      const card = selection?.card;
      const column = pointer.moved ? columnOf(document.elementFromPoint(event.clientX, event.clientY)) : null;
      cancel();
      if (card && column) void move(card, column);
    }, { signal });
    ['pointercancel', 'lostpointercapture'].forEach(type => board.addEventListener(type, () => {
      if (pointer) cancel();
    }, { signal }));
    board.addEventListener('dragstart', event => {
      const card = cardOf(event.target);
      const source = origin?.isConnected ? origin : event.target;
      const control = source.closest(interactive);
      if (!card || !event.dataTransfer || (control && !control.matches('[data-task-drag-handle]')) || !pickup(card, 'native')) {
        event.preventDefault(); return;
      }
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', card.dataset.taskId);
    }, { signal });
    board.addEventListener('dragover', event => {
      if (selection?.mode !== 'native' || !available()) return;
      const column = columnOf(event.target);
      if (!column) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      target(column);
    }, { signal });
    board.addEventListener('drop', event => {
      if (selection?.mode !== 'native') return;
      event.preventDefault();
      void move(selection.card, columnOf(event.target));
    }, { signal });
    board.addEventListener('dragend', cancel, { signal });
    board.addEventListener('click', event => {
      if (!event.target.closest('[data-task-drag-handle]')) return;
      event.preventDefault(); // Handles must never submit an enclosing form.
    }, { signal });
    board.addEventListener('keydown', event => {
      const handle = event.target.closest('[data-task-drag-handle]');
      const card = cardOf(handle);
      if (!card || !handle) return;
      if (event.key === 'Escape' && selection) {
        event.preventDefault(); cancel(); live.textContent = 'Move cancelled.'; return;
      }
      if (!available()) return;
      if (event.key === ' ' || event.key === 'Spacebar') {
        event.preventDefault();
        if (!event.repeat && !selection) pickup(card, 'keyboard', handle);
        return;
      }
      if (selection?.mode !== 'keyboard' || selection.card !== card) return;
      if (event.key === 'Enter') { event.preventDefault(); void move(card, selection.column); }
      else if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
        event.preventDefault();
        const list = columns();
        const delta = ['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 1;
        target(list[Math.max(0, Math.min(list.length - 1, list.indexOf(selection.column) + delta))]);
      }
    }, { signal });
    board.addEventListener('focusout', event => {
      if (selection?.mode === 'keyboard' && event.target === selection.handle && !selection.handle.contains(event.relatedTarget)) cancel();
    }, { signal });
    document.addEventListener('synapse:before-navigate', event => {
      if (posting) event.preventDefault();
      else cancel();
    }, { signal });
    window.addEventListener('beforeunload', event => {
      if (posting) { event.preventDefault(); event.returnValue = ''; }
    }, { signal });
    const cleanup = () => {
      stopped = true;
      cancel(); events.abort(); request?.abort(); clearTimeout(timeout);
      lock(false); live.remove();
      styles.forEach(([handle, value]) => { handle.style.touchAction = value; });
      unregister?.(); instances.delete(board);
    };
    const unregister = window.SynapseNavigation?.registerCleanup(cleanup);
    instances.set(board, cleanup);
  }
  function start(scope = document) {
    for (const [board, cleanup] of instances) if (!board.isConnected) cleanup();
    if (scope.matches?.('[data-team-board]')) init(scope);
    scope.querySelectorAll?.('[data-team-board]').forEach(init);
  }
  function destroy(scope) {
    for (const [board, cleanup] of instances) if (!scope || scope === board || scope.contains(board)) cleanup();
  }
  window.SynapseTeamBoard = { init: start, destroy };
  document.addEventListener('synapse:page-load', event => start(event.detail?.root || document));
  document.addEventListener('synapse:filter-updated', event => start(event.detail?.root || document));
  document.addEventListener('synapse:before-swap', event => destroy(event.detail?.root));
  window.addEventListener('pagehide', () => destroy());
  window.addEventListener('pageshow', event => { if (event.persisted) start(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => start(), { once: true });
  else start();
})();