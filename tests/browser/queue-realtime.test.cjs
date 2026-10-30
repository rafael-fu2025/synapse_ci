'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/queue-realtime.js'), 'utf8');

function fixture(status, unit = 'clinic', visibility = 'visible') {
  const handlers = new Map();
  const timers = new Map();
  const calls = [];
  let sequence = 0;
  let now = 0;
  const count = { dataset: { queueWaitingCount: '0' } };
  const button = { disabled: false };
  const board = { id: 'queue-board-container', dataset: { queueUnit: unit }, querySelector: () => count };
  const date = { value: '2026-10-22' };
  const document = {
    visibilityState: visibility,
    getElementById: id => id === board.id ? board : id === 'queueDateFilter_iso' ? date : id === 'queue-call-next' ? button : null,
    addEventListener: (name, callback, options) => {
      handlers.set(name, callback);
      options?.signal.addEventListener('abort', () => {
        if (handlers.get(name) === callback) handlers.delete(name);
      });
    },
  };
  const window = {
    addEventListener: document.addEventListener,
    htmx: { trigger: (target, name) => calls.push({ target, name, date: date.value }) },
  };
  if (status) window.SynapseRealtime = { status };
  const context = vm.createContext({ document, window, AbortController,
    setTimeout: (callback, delay) => { const id = ++sequence; timers.set(id, { callback, at: now + delay }); return id; },
    clearTimeout: id => timers.delete(id),
  });
  vm.runInContext(source, context);
  function tick(ms) {
    const end = now + ms;
    while (true) {
      const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!next) break;
      now = next[1].at;
      timers.delete(next[0]);
      next[1].callback();
    }
    now = end;
  }
  const fire = (name, detail) => handlers.get(name)?.({ detail });
  const hint = (eventUnit = unit, eventDate = date.value, event = 'queue.invalidated') => fire('synapse:realtime-event', {
    event, data: { unit: eventUnit, date: eventDate },
  });
  return { calls, board, count, button, date, document, window, timers, tick, fire, hint, context };
}

test('call-next follows waiting tickets on initial load and every board swap in both departments', () => {
  for (const unit of ['clinic', 'guidance']) {
    const f = fixture(undefined, unit);
    assert.equal(f.button.disabled, true);
    for (const [count, disabled] of [['3', false], ['1', false], ['0', true], [undefined, true], ['invalid', true]]) {
      f.count.dataset.queueWaitingCount = count;
      f.fire('htmx:afterSwap', { target: f.board });
      assert.equal(f.button.disabled, disabled, `${unit}: waiting count ${count}`);
    }
    f.count.dataset.queueWaitingCount = '2';
    f.fire('htmx:afterSwap', { target: { id: 'unrelated' } });
    assert.equal(f.button.disabled, true, 'Unrelated swaps do not enable the action');
    f.fire('htmx:afterSwap', { target: f.board });
    assert.equal(f.button.disabled, false);
  }
});

test('no WS, disabled and retrying retain 15s fallback; ready reconciles at 60s', () => {
  for (const status of [undefined, 'disabled', 'retrying', 'unauthorized', 'stopped']) {
    const f = fixture(status);
    f.tick(15099);
    assert.equal(f.calls.length, 0);
    f.tick(1);
    assert.equal(f.calls.length, 1);
  }
  const f = fixture('ready');
  f.tick(100);
  assert.equal(f.calls.length, 1); // Ready before queue script loaded.
  f.tick(59999);
  assert.equal(f.calls.length, 1);
  f.tick(1);
  assert.equal(f.calls.length, 2);
});

test('both departments filter unrelated/malformed hints and coalesce bursts', () => {
  for (const unit of ['clinic', 'guidance']) {
    const f = fixture(undefined, unit);
    f.fire('synapse:realtime-event', null);
    f.hint(unit === 'clinic' ? 'guidance' : 'clinic');
    f.hint(unit, '2026-10-21');
    f.hint(unit, f.date.value, 'notification.created');
    f.tick(100);
    assert.equal(f.calls.length, 0);
    f.hint(); f.hint(); f.hint();
    f.tick(100);
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].name, 'queue:refresh');
  }
});

test('ready/reconnect refetches selected date and loss restores fallback', () => {
  const f = fixture();
  f.date.value = '2026-10-19';
  f.fire('synapse:realtime-status', { status: 'ready' });
  f.tick(100);
  assert.equal(f.calls[0].date, '2026-10-19');
  f.fire('synapse:realtime-status', { status: 'retrying' });
  f.tick(15100);
  assert.equal(f.calls.length, 2);
  f.fire('synapse:realtime-status', { status: 'ready' });
  f.tick(100);
  assert.equal(f.calls.length, 3);
  f.fire('synapse:realtime-status', { status: 'ready' });
  f.tick(100);
  assert.equal(f.calls.length, 3);
});

test('invalidation waits for HTMX actions, then reads current selected date', () => {
  const f = fixture();
  const xhr = {};
  f.fire('htmx:beforeRequest', { target: f.board, xhr });
  f.hint();
  f.tick(100);
  assert.equal(f.calls.length, 0);
  f.date.value = '2026-10-20';
  f.fire('htmx:afterRequest', { target: f.board, xhr });
  f.tick(100);
  assert.equal(f.calls.length, 1);
  assert.equal(f.calls[0].date, '2026-10-20');
});

test('pagehide stops work, pageshow refetches, duplicate initialization is harmless', () => {
  const f = fixture('ready');
  vm.runInContext(source, f.context);
  f.fire('pagehide');
  f.tick(120000);
  assert.equal(f.calls.length, 0);
  f.fire('pageshow');
  f.tick(100);
  assert.equal(f.calls.length, 1);
});

test('hiding cancels debounce and polling, including shared transport paused status', () => {
  for (const status of [undefined, 'disabled', 'ready']) {
    const f = fixture(status);
    f.hint();
    assert.ok(f.timers.size > 0);
    f.document.visibilityState = 'hidden';
    f.fire('visibilitychange');
    f.fire('synapse:realtime-status', { status: 'paused' });
    f.hint();
    f.fire('htmx:afterRequest', { target: f.board, xhr: {} });
    f.tick(180000);
    assert.equal(f.calls.length, 0);
    assert.equal(f.timers.size, 0);
    f.date.value = '2026-10-18';
    if (f.window.SynapseRealtime) f.window.SynapseRealtime.status = 'paused';
    f.document.visibilityState = 'visible';
    f.fire('visibilitychange');
    f.tick(100);
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].date, '2026-10-18');
    f.tick(14999);
    assert.equal(f.calls.length, 1);
    f.tick(1);
    assert.equal(f.calls.length, 2);
  }
});

test('initially hidden pages and hidden pageshow do not poll; restore coalesces ready/hints', () => {
  const f = fixture('ready', 'guidance', 'hidden');
  f.fire('pageshow');
  f.fire('synapse:realtime-status', { status: 'paused' });
  f.hint();
  f.tick(180000);
  assert.equal(f.calls.length, 0);
  assert.equal(f.timers.size, 0);
  f.document.visibilityState = 'visible';
  f.fire('visibilitychange');
  f.fire('synapse:realtime-status', { status: 'ready' });
  f.hint();
  f.tick(100);
  assert.equal(f.calls.length, 1);
  f.tick(59999);
  assert.equal(f.calls.length, 1);
  f.tick(1);
  assert.equal(f.calls.length, 2);
});

test('timer callbacks check visibility even before visibilitychange is delivered', () => {
  const f = fixture('ready');
  f.document.visibilityState = 'hidden';
  f.tick(180000);
  assert.equal(f.calls.length, 0);
  assert.equal(f.timers.size, 0);
});

test('visible restoration waits for an in-flight HTMX action and uses the latest date', () => {
  const f = fixture();
  const xhr = {};
  f.fire('htmx:beforeRequest', { target: f.board, xhr });
  f.document.visibilityState = 'hidden';
  f.fire('visibilitychange');
  f.document.visibilityState = 'visible';
  f.fire('visibilitychange');
  f.tick(100);
  assert.equal(f.calls.length, 0);
  f.date.value = '2026-10-17';
  f.fire('htmx:afterRequest', { target: f.board, xhr });
  f.tick(100);
  assert.equal(f.calls.length, 1);
  assert.equal(f.calls[0].date, '2026-10-17');
});

test('module navigation cleans up listeners and reinitializes a cached queue script', () => {
  const f = fixture('ready');
  f.fire('synapse:before-swap', { root: { contains: board => board === f.board } });
  f.tick(120000);
  assert.equal(f.calls.length, 0);
  f.hint();
  f.tick(100);
  assert.equal(f.calls.length, 0);
  f.fire('synapse:page-load');
  f.tick(100);
  assert.equal(f.calls.length, 1);
  f.fire('synapse:page-load');
  f.hint();
  f.tick(100);
  assert.equal(f.calls.length, 2);
});

test('queue view keeps date and action wiring and loads only queue-specific integration', () => {
  const view = fs.readFileSync(path.join(__dirname, '../../app/Views/queue/index.php'), 'utf8');
  assert.match(view, /hx-trigger="queue:refresh"/);
  assert.match(view, /assets\/js\/queue-realtime\.js/);
  assert.match(view, /id="queue-board-container"[\s\S]*?hx-include="#queueDateFilter_iso"/);
  assert.match(view, /queue\/call-next[\s\S]*?hx-include="#queueDateFilter_iso"/);
  assert.doesNotMatch(view, /hx-trigger="every 15s"/);
});
