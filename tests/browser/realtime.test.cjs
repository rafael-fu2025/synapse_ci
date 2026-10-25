'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/realtime.js'), 'utf8');

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function target() {
  const listeners = new Map();
  return {
    addEventListener(name, callback) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(callback);
    },
    dispatchEvent(event) { for (const callback of listeners.get(event.type) || []) callback(event); },
    listeners
  };
}
function harness({ meta = '/realtime/ticket', hidden = false, origin = 'https://app.test' } = {}) {
  let now = 1700000000000;
  let nextTimer = 0;
  const timers = new Map();
  const requests = [], sockets = [], statuses = [], events = [], synced = [], responses = [];
  const document = Object.assign(target(), {
    visibilityState: hidden ? 'hidden' : 'visible',
    querySelector(selector) {
      assert.equal(selector, 'meta[name="synapse-realtime-ticket-url"]');
      return meta === null ? null : { content: meta };
    }
  });
  document.addEventListener('synapse:realtime-status', event => statuses.push(event.detail.status));
  document.addEventListener('synapse:realtime-event', event => events.push(event.detail));
  class WebSocket {
    constructor(url) {
      this.url = url;
      this.sent = [];
      this.closed = false;
      assert.equal(sockets.filter(socket => !socket.closed).length, 0, 'at most one socket');
      sockets.push(this);
    }
    send(value) { this.sent.push(JSON.parse(value)); }
    close() { this.closed = true; }
    open() { this.onopen?.(); }
    message(value) { this.onmessage?.({ data: typeof value === 'string' ? value : JSON.stringify(value) }); }
    disconnect() { this.closed = true; this.onclose?.({ code: 1008 }); }
  }
  const window = Object.assign(target(), {
    location: new URL(origin + '/workspace'), WebSocket,
    fetch(url, options) {
      const pending = deferred();
      requests.push({ url, options, ...pending });
      // Intentionally ignore abort here: stale completions must still be harmless.
      return pending.promise;
    },
    SynapseCsrf: {
      headers: () => ({ 'X-CSRF-TOKEN': 'current' }),
      fromResponse: response => responses.push(response),
      sync: csrf => synced.push(csrf)
    }
  });
  class Clock extends Date { static now() { return now; } }
  const context = vm.createContext({
    window, document, URL, AbortController, Date: Clock,
    Math: Object.assign(Object.create(Math), { random: () => 0.5 }),
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    setTimeout(callback, delay) {
      const id = ++nextTimer;
      timers.set(id, { callback, at: now + delay });
      return id;
    },
    clearTimeout: id => timers.delete(id)
  });
  const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
  function load() { vm.runInContext(source, context); }
  async function advance(ms) {
    const until = now + ms;
    for (let count = 0; ; count++) {
      assert.ok(count < 100, 'bounded fake timer execution');
      const next = [...timers].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
      if (!next) break;
      now = next[1].at;
      timers.delete(next[0]);
      next[1].callback();
      await flush();
    }
    now = until;
    await flush();
  }
  function payload(overrides = {}) {
    return { enabled: true, url: 'wss://socket.test/events', ticket: 'secret-ticket', expires_at: Math.floor(now / 1000) + 60, csrf: { name: 'csrf', hash: 'rotated' }, ...overrides };
  }
  async function respond(index = requests.length - 1, body = payload(), status = 200, extra = {}) {
    requests[index].resolve({ ok: status >= 200 && status < 300, status, url: requests[index].url,
      json: async () => body, ...extra });
    await flush();
  }
  async function ready(body = payload()) {
    await respond(requests.length - 1, body);
    const ws = sockets.at(-1);
    ws.open();
    ws.message({ type: 'ready' });
    return ws;
  }
  function visibility(value) { document.visibilityState = value; document.dispatchEvent({ type: 'visibilitychange' }); }
  function page(type) { window.dispatchEvent({ type, persisted: true }); }
  load();
  return { window, document, requests, sockets, statuses, events, timers, synced, responses,
    advance, flush, respond, ready, payload, visibility, page, load,
    api: window.SynapseRealtime,
    nextDelay: () => Math.min(...[...timers.values()].map(timer => timer.at - now)) };
}
const plain = value => JSON.parse(JSON.stringify(value));

test('no opt-in meta means no I/O, even on lifecycle events or explicit reconnect', async () => {
  const h = harness({ meta: null });
  h.page('pageshow'); h.visibility('hidden'); h.visibility('visible'); h.api.reconnect();
  await h.advance(100000);
  assert.equal(h.requests.length, 0);
  assert.equal(h.sockets.length, 0);
  assert.equal(h.api.status, 'disabled');
  assert.equal(h.timers.size, 0);
});

test('disabled API and unavailable service do not open sockets', async () => {
  const h = harness();
  await h.respond(0, { enabled: false });
  assert.equal(h.api.status, 'disabled');
  await h.advance(100000);
  assert.equal(h.requests.length, 1);
  h.api.reconnect();
  await h.respond(1, { enabled: false, csrf: { name: 'csrf', hash: 'failure' } }, 503);
  assert.equal(h.api.status, 'retrying');
  assert.equal(h.sockets.length, 0);
  assert.equal(h.synced.at(-1).hash, 'failure');
  await h.advance(750);
  await h.ready();
  assert.equal(h.api.status, 'ready');
  h.api.stop();
});

test('same-origin POST and CSRF rotation; ticket only in first socket message; ready gates events', async () => {
  const h = harness();
  const request = h.requests[0];
  assert.equal(request.url, 'https://app.test/realtime/ticket');
  assert.equal(request.options.method, 'POST');
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(request.options.redirect, 'error');
  assert.equal(request.options.cache, 'no-store');
  assert.deepEqual(plain(request.options.headers), { 'X-CSRF-TOKEN': 'current', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' });
  await h.respond();
  assert.equal(h.responses.length, 1);
  assert.equal(h.synced[0].hash, 'rotated');
  const ws = h.sockets[0];
  assert.equal(ws.url, 'wss://socket.test/events');
  assert.equal(ws.sent.length, 0);
  const envelope = { type: 'event', channel: 'user:7', event: 'changed', data: { count: 2 } };
  ws.message(envelope);
  assert.equal(h.events.length, 0);
  ws.open();
  assert.deepEqual(plain(ws.sent), [{ type: 'authenticate', ticket: 'secret-ticket' }]);
  assert.equal(h.api.status, 'connecting');
  ws.message({ type: 'ready' });
  ws.message('{bad json'); ws.message(null); ws.message([]); ws.message({ type: 'pong' });
  ws.message({ type: 'event', channel: 'user:7', event: 'changed' });
  ws.message(envelope);
  assert.deepEqual(plain(h.events), [envelope]);
  assert.deepEqual(h.statuses, ['connecting', 'ready']);
  assert.equal(ws.sent.length, 1);
  assert.deepEqual(Object.keys(h.api).sort(), ['reconnect', 'status', 'stop']);
  assert.throws(() => { h.api.status = 'fake'; }, TypeError);
  h.api.stop();
});

test('401 and 403 block automatic recovery until explicit reconnect', async () => {
  for (const code of [401, 403]) {
    const h = harness();
    await h.respond(0, { csrf: { name: 'csrf', hash: 'denied' } }, code);
    assert.equal(h.api.status, 'unauthorized');
    assert.equal(h.synced[0].hash, 'denied');
    h.visibility('hidden'); h.visibility('visible'); h.page('pagehide'); h.page('pageshow'); h.load();
    await h.advance(100000);
    assert.equal(h.requests.length, 1);
    h.api.reconnect();
    await h.ready();
    assert.equal(h.api.status, 'ready');
    h.api.stop();
  }
});

test('authorization denial remains latched even while JSON is stalled or malformed', async () => {
  for (const json of [() => new Promise(() => {}), async () => { throw new Error('not JSON'); }]) {
    const h = harness();
    await h.respond(0, null, 403, { json });
    assert.equal(h.api.status, 'unauthorized');
    await h.advance(10000);
    assert.equal(h.requests[0].options.signal.aborted, true);
    h.visibility('hidden'); h.visibility('visible'); h.page('pageshow');
    await h.advance(100000);
    assert.equal(h.requests.length, 1);
    h.api.stop();
  }
  const h = harness();
  await h.respond(0, null, 401, { json: () => new Promise(() => {}) });
  h.visibility('hidden'); h.visibility('visible');
  assert.equal(h.requests[0].options.signal.aborted, true);
  assert.equal(h.requests.length, 1);
  assert.equal(h.api.status, 'unauthorized');
  h.api.stop();
});

test('only visible pages connect; BFCache and duplicate script/page events do not duplicate owners', async () => {
  const h = harness({ hidden: true });
  assert.equal(h.api.status, 'paused');
  assert.equal(h.requests.length, 0);
  h.visibility('visible');
  const first = await h.ready();
  const staleClose = first.onclose;
  h.page('pagehide');
  assert.equal(first.closed, true);
  assert.equal(h.api.status, 'paused');
  h.page('pageshow'); h.page('pageshow'); h.load();
  h.document.dispatchEvent({ type: 'synapse:page-load' });
  assert.equal(h.requests.length, 2);
  const second = await h.ready();
  staleClose();
  assert.equal(h.api.status, 'ready');
  h.visibility('hidden');
  assert.equal(second.closed, true);
  h.page('pageshow');
  assert.equal(h.requests.length, 2);
  h.visibility('visible');
  assert.equal(h.requests.length, 3);
  h.api.stop();
  assert.equal(h.requests[2].options.signal.aborted, true);
  h.visibility('hidden'); h.visibility('visible'); h.page('pageshow');
  await h.advance(100000);
  assert.equal(h.api.status, 'stopped');
  assert.equal(h.requests.length, 3);
});

test('pause and reconnect abort stale ticket work without stale socket or CSRF writes', async () => {
  const h = harness();
  h.visibility('hidden');
  assert.equal(h.requests[0].options.signal.aborted, true);
  h.visibility('visible');
  await h.respond(0);
  assert.equal(h.sockets.length, 0);
  assert.equal(h.responses.length, 0);
  const body = deferred();
  await h.respond(1, null, 200, { json: () => body.promise });
  h.api.reconnect();
  assert.equal(h.requests[1].options.signal.aborted, true);
  body.resolve(h.payload({ ticket: 'stale' }));
  await h.flush();
  assert.equal(h.sockets.length, 0);
  assert.equal(h.synced.length, 0);
  const ws = await h.ready(h.payload({ ticket: 'fresh' }));
  assert.equal(ws.sent[0].ticket, 'fresh');
  h.api.stop();
});

test('fetch including JSON parsing and socket authentication have bounded timeouts', async () => {
  const h = harness();
  await h.respond(0, null, 200, { json: () => new Promise(() => {}) });
  await h.advance(10000);
  assert.equal(h.requests[0].options.signal.aborted, true);
  assert.equal(h.api.status, 'retrying');
  await h.advance(750);
  await h.respond();
  const ws = h.sockets[0];
  ws.open();
  await h.advance(10000);
  assert.equal(ws.closed, true);
  assert.equal(h.api.status, 'retrying');
  assert.equal(h.nextDelay(), 1500);
  await h.advance(1500);
  await h.ready();
  await h.advance(11000);
  assert.equal(h.api.status, 'ready', 'ready cancels authentication timeout');
  h.api.stop();
});

test('exponential jitter is capped, survives pause, and never resets for fleeting ready', async () => {
  const h = harness();
  for (const delay of [750, 1500, 3000, 6000, 12000, 22500, 22500]) {
    const ws = await h.ready();
    ws.disconnect();
    assert.equal(h.nextDelay(), delay);
    const count = h.requests.length;
    h.visibility('hidden'); h.visibility('visible');
    assert.equal(h.nextDelay(), delay);
    await h.advance(delay - 1);
    assert.equal(h.requests.length, count);
    await h.advance(1);
    assert.equal(h.requests.length, count + 1);
  }
  const ws = await h.ready();
  await h.advance(30000);
  ws.disconnect();
  assert.equal(h.nextDelay(), 750, 'stable connection resets failure count');
  h.api.stop();
});

test('server expiry reconnect obtains fresh ticket; ticket failure backs off then recovers', async () => {
  const h = harness();
  const first = await h.ready(h.payload({ ticket: 'first', expires_at: 1700000001 }));
  await h.advance(1000);
  first.disconnect();
  assert.equal(h.api.status, 'retrying');
  await h.advance(750);
  h.requests[1].reject(new Error('offline'));
  await h.flush();
  assert.equal(h.api.status, 'retrying');
  assert.equal(h.nextDelay(), 1500);
  await h.advance(1500);
  const second = await h.ready(h.payload({ ticket: 'replacement' }));
  assert.equal(second.sent[0].ticket, 'replacement');
  assert.equal(h.requests.length, 3);
  assert.equal(h.api.status, 'ready');
  h.api.stop();
});

test('ticket URL cannot forward HTTP credentials to another host or follow redirects', async () => {
  for (const meta of ['https://evil.test/ticket', '//evil.test/ticket', 'http://app.test/ticket', 'https://user:pass@app.test/ticket', 'javascript:alert(1)', '/ticket#fragment']) {
    const h = harness({ meta });
    assert.equal(h.requests.length, 0, meta);
    assert.equal(h.api.status, 'disabled');
  }
  const h = harness();
  await h.respond(0, h.payload(), 200, { redirected: true, url: 'https://evil.test/ticket' });
  assert.equal(h.sockets.length, 0);
  assert.equal(h.synced.length, 0);
  h.api.stop();
});

test('reject malformed, credential-bearing, query-bearing and mixed-content socket URLs and expired tickets', async () => {
  for (const url of ['ws://socket.test/events', 'https://socket.test/events', '/events', 'wss://user:pass@socket.test', 'wss://socket.test/#fragment', 'wss://socket.test/?ticket=secret', 'wss://', 'wss://socket.test/ bad', 'wss://socket.test/\\bad']) {
    const h = harness();
    await h.respond(0, h.payload({ url }));
    assert.equal(h.sockets.length, 0, url);
    assert.equal(h.api.status, 'retrying');
    h.api.stop();
  }
  for (const override of [{ expires_at: 1 }, { expires_at: '1700000060' }, { ticket: '' }, { enabled: 'true' }]) {
    const h = harness();
    await h.respond(0, h.payload(override));
    assert.equal(h.sockets.length, 0);
    h.api.stop();
  }
  const h = harness({ origin: 'http://app.test' });
  await h.ready(h.payload({ url: 'ws://socket.test/events' }));
  assert.equal(h.api.status, 'ready');
  h.api.stop();
});
