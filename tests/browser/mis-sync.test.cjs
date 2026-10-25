'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/mis-sync.js'), 'utf8');

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function element() {
  const listeners = new Map(), attributes = new Map(), classes = new Set(), children = new Map();
  return {
    listeners, children, hidden: false, disabled: false, textContent: '', style: {}, isConnected: true,
    addEventListener(name, callback) {
      if (!listeners.has(name)) listeners.set(name, new Set());
      listeners.get(name).add(callback);
    },
    removeEventListener(name, callback) { listeners.get(name)?.delete(callback); },
    dispatchEvent(event) {
      for (const callback of [...(listeners.get(event.type) || [])]) callback(event);
      return !event.defaultPrevented;
    },
    querySelector: selector => children.get(selector) || null,
    setAttribute: (name, value) => attributes.set(name, value),
    removeAttribute: name => attributes.delete(name),
    getAttribute: name => attributes.get(name) ?? null,
    classList: {
      toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
      contains: name => classes.has(name)
    }
  };
}
function event(type, detail) {
  return { type, detail, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
}
function harness({ loading = false, realtime = 'ready', action = '/admin/mis-sync/run', statusUrl = '/admin/mis-sync' } = {}) {
  const root = element(), form = element(), button = element();
  const nodes = {};
  root.matches = selector => selector === '[data-mis-sync]';
  root.contains = node => node === root;
  root.dataset = { statusUrl };
  root.children.set('[data-mis-sync-form]', form);
  form.action = new URL(action, 'https://app.test').href;
  form.children.set('button[type="submit"]', button);
  for (const name of ['progress', 'bar', 'percent', 'message', 'result', 'refresh', 'last']) {
    nodes[name] = element();
    root.children.set(`[data-sync-${name}]`, nodes[name]);
  }
  nodes.progress.hidden = nodes.result.hidden = nodes.refresh.hidden = true;
  nodes.bar.style.width = '0%';
  nodes.bar.setAttribute('role', 'progressbar');
  nodes.bar.setAttribute('aria-valuemin', '0');
  nodes.bar.setAttribute('aria-valuemax', '100');
  nodes.bar.setAttribute('aria-label', 'Sync progress');
  nodes.percent.textContent = '0%';
  nodes.refresh.href = 'https://app.test/admin/mis-sync';
  const rows = {};
  for (const catalog of ['departments', 'programs', 'year_levels']) {
    const row = rows[catalog] = element();
    row.children.set('[data-sync-total]', element());
    row.children.set('[data-sync-synced]', element());
    root.children.set(`[data-sync-catalog="${catalog}"]`, row);
  }
  const document = element();
  document.readyState = loading ? 'loading' : 'complete';
  document.children.set('[data-mis-sync]', root);
  document.contains = node => node === root;
  let nextId = 0;
  const requests = [], responses = [], synced = [];
  class FormData extends Map { constructor(value) { super([['csrf', 'initial']]); assert.equal(value, form); } }
  const window = Object.assign(element(), {
    location: new URL('https://app.test/admin/mis-sync'),
    FormData, crypto: { randomUUID: () => `12345678-1234-4000-8000-${String(++nextId).padStart(12, '0')}` },
    SynapseRealtime: { status: realtime },
    SynapseCsrf: {
      headers: () => ({ 'X-CSRF-TOKEN': 'current' }),
      fromResponse: response => responses.push(response),
      sync: csrf => synced.push(csrf)
    },
    fetch(url, options) {
      const pending = deferred();
      requests.push({ url, options, ...pending });
      return pending.promise;
    }
  });
  const timers = new Map();
  let nextTimer = 0;
  const context = vm.createContext({ window, document, URL, AbortController,
    setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id)
  });
  const load = () => vm.runInContext(source, context);
  const dispatch = (type, detail, target = document) => {
    const e = event(type, detail); target.dispatchEvent(e); return e;
  };
  const flush = async () => { for (let i = 0; i < 16; i++) await Promise.resolve(); };
  async function respond(index, body, status = 200, extra = {}) {
    requests[index].resolve({ ok: status >= 200 && status < 300, status, url: requests[index].url,
      json: async () => body, ...extra });
    await flush();
  }
  const submit = () => dispatch('submit', undefined, form);
  function progress(percent, message = 'Working', progressId = requests[0]?.options.body.get('progress_id'), extra = {}) {
    dispatch('synapse:realtime-event', { event: 'mis.sync.progress', channel: 'user:7', data: { progress_id: progressId, percent, message }, ...extra });
  }
  load();
  return { window, document, root, form, button, nodes, rows, requests, responses, synced, timers,
    load, dispatch, flush, respond, submit, progress, api: window.SynapseMisSync };
}
function statusPayload() {
  return { catalogs: Object.fromEntries(['departments', 'programs', 'year_levels'].map((key, index) =>
    [key, { total: index + 1, last_synced_at: index ? null : '<b>timestamp</b>' }])),
  lastSync: { occurred_at: '<script>timestamp</script>' } };
}

test('POST carries correlation, FormData and CSRF; committed result refreshes local text safely', async () => {
  const h = harness();
  assert.equal(h.submit().defaultPrevented, true);
  const { options, url } = h.requests[0];
  assert.equal(url, 'https://app.test/admin/mis-sync/run');
  assert.equal(options.method, 'POST');
  assert.equal(options.credentials, 'same-origin');
  assert.equal(options.redirect, 'error');
  assert.equal(options.cache, 'no-store');
  assert.equal(options.signal, undefined, 'write has no abort deadline');
  assert.equal(options.headers.Accept, 'application/json');
  assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(options.headers['X-CSRF-TOKEN'], 'current');
  assert.equal(options.body.get('csrf'), 'initial');
  assert.match(options.body.get('progress_id'), /^[a-f0-9-]{36}$/);
  assert.equal(h.nodes.progress.hidden, false);
  assert.equal(h.nodes.percent.textContent, 'Waiting…');
  assert.equal(h.nodes.bar.getAttribute('aria-valuenow'), null);
  assert.equal(h.nodes.bar.classList.contains('progress-bar-animated'), true);
  await h.respond(0, { success: true, message: '<img onerror=bad>', csrf: { name: 'csrf', hash: 'rotated' } });
  assert.equal(h.nodes.percent.textContent, '100%');
  assert.match(h.nodes.result.textContent, /successfully/);
  assert.equal(h.requests[1].options.method, 'GET');
  assert.equal(h.requests[1].options.signal.aborted, false);
  assert.equal(h.requests[1].url, 'https://app.test/admin/mis-sync');
  await h.respond(1, statusPayload());
  assert.equal(h.responses.length, 2);
  assert.equal(h.synced[0].hash, 'rotated');
  assert.equal(h.rows.departments.querySelector('[data-sync-total]').textContent, '1');
  assert.equal(h.rows.departments.querySelector('[data-sync-synced]').textContent, '<b>timestamp</b>');
  assert.equal(h.rows.programs.querySelector('[data-sync-synced]').textContent, 'Never');
  assert.equal(h.nodes.last.textContent, '<script>timestamp</script>');
  assert.equal(h.nodes.refresh.hidden, true);
  assert.equal(h.button.disabled, false);
  assert.equal(h.nodes.bar.classList.contains('progress-bar-animated'), false);
});

test('only current-run numeric monotonic progress is accepted; websocket 100 is not final', async () => {
  const h = harness();
  h.progress(60);
  assert.equal(h.nodes.percent.textContent, '0%');
  h.submit();
  h.progress(25, '<b>valid text</b>');
  assert.equal(h.nodes.message.textContent, '<b>valid text</b>');
  for (const value of [-1, 101, NaN, Infinity, '90', null, 10]) h.progress(value);
  h.progress(80, 'wrong run', 'other-run');
  h.progress(80, 'wrong event', undefined, { event: 'other.event' });
  assert.equal(h.nodes.percent.textContent, '25%');
  h.progress(100);
  assert.equal(h.nodes.percent.textContent, '100%');
  assert.match(h.nodes.message.textContent, /committed.*awaiting confirmation/);
  assert.equal(h.button.disabled, true);
  assert.equal(h.nodes.result.hidden, true);
  h.submit(); h.submit();
  assert.equal(h.requests.length, 1);
  await h.respond(0, { success: true });
  await h.respond(1, statusPayload());
  const text = h.nodes.message.textContent;
  h.progress(100, 'late');
  assert.equal(h.nodes.message.textContent, text);
});

test('known JSON failures never invent completion and allow only explicit fresh retries', async () => {
  for (const complete of [false, true]) {
    const h = harness();
    h.submit(); h.progress(45);
    if (complete) h.progress(100);
    const firstId = h.requests[0].options.body.get('progress_id');
    await h.respond(0, { success: false, message: '<script>failure</script>', csrf: { hash: 'failure', name: 'csrf' } }, 503);
    assert.equal(h.nodes.percent.textContent, '45%');
    assert.equal(h.nodes.result.textContent, '<script>failure</script>');
    assert.equal(h.synced[0].hash, 'failure');
    assert.equal(h.button.disabled, false);
    assert.equal(h.requests.length, 1);
    h.submit();
    assert.notEqual(h.requests[1].options.body.get('progress_id'), firstId);
    h.progress(90, 'old', firstId);
    assert.equal(h.nodes.percent.textContent, 'Waiting…');
  }
  const h = harness(); h.submit();
  await h.respond(0, { message: 'Denied' }, 403);
  assert.equal(h.nodes.percent.textContent, '0%');
  assert.equal(h.button.disabled, false);
});

test('unavailable realtime stays indeterminate without blocking submission or implying failure', async () => {
  const h = harness({ realtime: 'disabled' });
  h.submit();
  assert.equal(h.requests.length, 1);
  h.dispatch('synapse:realtime-status', { status: 'retrying' });
  await h.flush();
  assert.equal(h.nodes.percent.textContent, 'Waiting…');
  assert.equal(h.nodes.bar.getAttribute('aria-valuenow'), null);
  assert.equal(h.nodes.bar.classList.contains('progress-bar-striped'), true);
  assert.match(h.nodes.message.textContent, /Live progress is unavailable.*still pending/);
  assert.equal(h.nodes.result.hidden, true);
  assert.equal(h.button.disabled, true);
  h.progress(65);
  h.dispatch('synapse:realtime-status', { status: 'paused' });
  assert.equal(h.nodes.percent.textContent, '65%');
  await h.respond(0, { success: true });
  await h.respond(1, statusPayload());
  assert.equal(h.nodes.percent.textContent, '100%');
});

test('status GET failure cannot turn a committed write into failure', async () => {
  for (const failure of ['fetch', 'json', 'shape']) {
    const h = harness(); h.submit();
    await h.respond(0, { success: true });
    if (failure === 'fetch') { h.requests[1].reject(new Error('offline')); await h.flush(); }
    else if (failure === 'json') await h.respond(1, null, 200, { json: async () => { throw new Error('bad JSON'); } });
    else await h.respond(1, { catalogs: {} });
    assert.equal(h.nodes.percent.textContent, '100%');
    assert.match(h.nodes.result.textContent, /succeeded.*status could not be refreshed/);
    assert.equal(h.nodes.refresh.hidden, false);
    assert.equal(h.button.disabled, false);
    assert.equal(h.requests.length, 2);
  }
});

test('lost or invalid POST responses lock retries and require reloading status', async () => {
  for (const failure of ['fetch', 'parse', 'shape', 'redirect']) {
    const h = harness(); h.submit(); h.progress(65);
    if (failure === 'fetch') { h.requests[0].reject(new Error('offline')); await h.flush(); }
    if (failure === 'parse') await h.respond(0, null, 502, { json: async () => { throw new Error('HTML'); } });
    if (failure === 'shape') await h.respond(0, { unrelated: true });
    if (failure === 'redirect') await h.respond(0, { success: true }, 200, { redirected: true });
    assert.match(h.nodes.result.textContent, /outcome is unconfirmed.*Reload.*before retrying/);
    assert.equal(h.nodes.percent.textContent, '65%');
    assert.equal(h.nodes.refresh.hidden, false);
    assert.equal(h.button.disabled, true);
    h.submit(); h.load(); h.api.destroy(); h.api.init(); h.submit();
    assert.equal(h.requests.length, 1);
    assert.equal(h.button.disabled, true);
    assert.equal(h.dispatch('synapse:before-navigate').defaultPrevented, false);
  }
});

test('singleton lifecycle and navigation guards; forced teardown ignores stale writes without aborting', async () => {
  const h = harness({ loading: true });
  h.submit(); assert.equal(h.requests.length, 0);
  h.dispatch('DOMContentLoaded');
  h.load(); h.api.init();
  h.dispatch('synapse:page-load', { root: h.root });
  assert.equal(h.form.listeners.get('submit').size, 1);
  assert.equal(h.document.listeners.get('synapse:realtime-event').size, 1);
  h.submit();
  assert.equal(h.dispatch('synapse:before-navigate').defaultPrevented, true);
  assert.match(h.nodes.message.textContent, /Wait for the server response/);
  const unload = h.dispatch('beforeunload', undefined, h.window);
  assert.equal(unload.defaultPrevented, true);
  assert.equal(unload.returnValue, '');
  const unrelated = { contains: () => false };
  h.dispatch('synapse:before-swap', { root: unrelated });
  assert.equal(h.dispatch('synapse:before-navigate').defaultPrevented, true);
  h.dispatch('synapse:before-swap', { root: h.root });
  assert.equal(h.form.listeners.get('submit').size, 0);
  assert.equal(h.document.listeners.get('synapse:realtime-event').size, 0);
  assert.equal(h.document.listeners.get('synapse:realtime-status').size, 0);
  assert.equal(h.document.listeners.get('synapse:before-navigate').size, 0);
  assert.equal(h.window.listeners.get('beforeunload').size, 0);
  assert.equal(h.requests[0].options.signal, undefined);
  await h.respond(0, { success: true, csrf: { hash: 'stale' } });
  assert.equal(h.requests.length, 1);
  assert.equal(h.responses.length, 0);
  assert.equal(h.synced.length, 0);
  assert.equal(h.nodes.result.hidden, true);
  h.dispatch('synapse:page-load', { root: h.root }); h.submit();
  assert.equal(h.requests.length, 1, 'detached in-flight root cannot resubmit');
});

test('teardown during response parsing and status refresh ignores stale completions', async () => {
  for (const phase of ['post', 'status']) {
    const h = harness(); h.submit();
    if (phase === 'status') await h.respond(0, { success: true });
    const body = deferred();
    await h.respond(phase === 'status' ? 1 : 0, null, 200, { json: () => body.promise });
    const synced = h.synced.length;
    h.api.destroy();
    if (phase === 'status') assert.equal(h.requests[1].options.signal.aborted, true);
    assert.equal(h.requests[0].options.signal, undefined);
    body.resolve(phase === 'status' ? statusPayload() : { success: true, csrf: { hash: 'stale' } });
    await h.flush();
    assert.equal(h.synced.length, synced);
    assert.equal(h.nodes.last.textContent, '');
    assert.equal(h.requests.length, phase === 'status' ? 2 : 1);
  }
});

test('status deadline bounds fetch and JSON, ignores late results, and never times out POST', async () => {
  for (const phase of ['fetch', 'json']) {
    const h = harness();
    h.submit();
    assert.equal(h.timers.size, 0, 'POST has no timeout');
    await h.respond(0, { success: true });
    const body = deferred();
    if (phase === 'json') await h.respond(1, null, 200, { json: () => body.promise });
    assert.equal(h.timers.size, 1);
    const timer = [...h.timers.values()][0];
    assert.equal(timer.delay, 10000);
    timer.callback();
    await h.flush();
    assert.equal(h.requests[1].options.signal.aborted, true);
    assert.equal(h.requests[0].options.signal, undefined);
    assert.equal(h.timers.size, 0);
    assert.equal(h.button.disabled, false);
    assert.equal(h.nodes.percent.textContent, '100%');
    assert.equal(h.nodes.refresh.hidden, false);
    assert.match(h.nodes.result.textContent, /succeeded.*status could not be refreshed/);
    const count = h.synced.length;
    if (phase === 'json') { body.resolve(statusPayload()); await h.flush(); }
    else await h.respond(1, statusPayload());
    assert.equal(h.synced.length, count, 'timed-out read cannot rotate CSRF');
    assert.equal(h.nodes.last.textContent, '');
    h.submit();
    assert.equal(h.requests.length, 3, 'explicit retry is not locked after a committed write');
  }
});

test('successful status refresh hides optional stale actor; failed refresh preserves it', async () => {
  for (const success of [true, false]) {
    const h = harness();
    const actor = element();
    h.root.children.set('[data-sync-actor]', actor);
    h.submit();
    await h.respond(0, { success: true });
    await h.respond(1, success ? statusPayload() : {}, success ? 200 : 503);
    assert.equal(actor.hidden, success);
    assert.equal(h.timers.size, 0);
  }
});

test('unsafe endpoints leave native form behavior untouched', () => {
  for (const options of [{ action: 'https://evil.test/run' }, { statusUrl: '//evil.test/status' }]) {
    const h = harness(options);
    assert.equal(h.submit().defaultPrevented, false);
    assert.equal(h.requests.length, 0);
  }
});
