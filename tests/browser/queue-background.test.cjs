'use strict';

// Exercise the production Alpine factory in isolation: no server, DB, clinical records or devices.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../app/Views/queue/index.php'), 'utf8');
const script = [...source.matchAll(/<script\b[^>]*>([^]*?)<\/script>/g)]
  .find(match => match[1].includes('function walkinSearch()'))[1]
  .replace(/<\?=[^]*?\?>/g, '/api/patients/search?q=');

function fixture({ helpers = true, refreshFails = false, refreshFalse = false } = {}) {
  const calls = [], notices = [], synced = [], order = [];
  const root = new EventTarget();
  let shown = true;
  root.classList = { contains: () => shown };
  const controls = [{ disabled: false }, { disabled: true }];
  const fields = new Map([['person_id', '11'], ['priority', 'urgent'], ['csrf', 'latest']]);
  const form = {
    action: 'https://queue.test/clinic/queue/walkin', method: 'post', target: '',
    querySelectorAll: () => controls, setAttribute() {}, removeAttribute() {}, reset() { order.push('reset'); }
  };
  const document = new EventTarget();
  const window = new EventTarget();
  window.fetch = true;
  if (helpers) {
    window.SynapseToast = { show: message => notices.push(message) };
    window.SynapseMutations = { refresh: async (url, message, options) => {
      assert.equal(shown, false, 'Refresh waits for Bootstrap hidden, not merely hide');
      assert.equal(state.submitting, false, 'Hide gate released before refresh');
      assert.equal(options.form, form);
      order.push(['refresh', url, message]);
      if (refreshFails) throw new Error('GET failed');
      return !refreshFalse;
    } };
  }
  window.SynapseCsrf = {
    headers: () => ({ 'X-CSRF-TOKEN': 'latest' }), fromResponse: () => order.push('csrf-header'),
    sync: csrf => synced.push(csrf)
  };
  let resolveResponse, rejectResponse;
  const pending = new Promise((resolve, reject) => { resolveResponse = resolve; rejectResponse = reject; });
  const context = vm.createContext({ window, document, location: { href: 'https://queue.test/clinic/queue?date=2026-10-25', origin: 'https://queue.test' },
    URL, AbortController, setTimeout, clearTimeout, console, TypeError,
    FormData: class extends Map { constructor() { super(fields); } },
    fetch: async (url, options) => { calls.push({ url, options }); return pending; },
    bootstrap: { Modal: { getOrCreateInstance: () => ({ hide: () => {
      assert.equal(state.submitting, false);
      order.push('hide');
      setTimeout(() => { shown = false; order.push('hidden'); root.dispatchEvent(new Event('hidden.bs.modal')); }, 5);
    } }) } }
  });
  vm.runInContext(script, context);
  const state = context.walkinSearch();
  Object.assign(state, { $root: root, $refs: { form }, $nextTick: async () => {} });
  state.init();
  state.selectedPerson = { id: 11 };
  state.searchTerm = 'draft';
  const event = () => ({ defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } });
  const respond = (payload = { success: true, message: 'Ticket C001 issued successfully.', csrf: { name: 'csrf', hash: 'rotated' } }, ok = true, type = 'application/json') =>
    resolveResponse({ ok, headers: { get: () => type }, json: async () => payload });
  return { state, form, controls, calls, notices, synced, order, event, respond, rejectResponse, document, root };
}

test('walk-in sends one JSON POST with fields/latest CSRF and refreshes current URL only after hidden', async () => {
  const f = fixture();
  const event = f.event();
  const job = f.state.submitTicket(event);
  assert.equal(event.defaultPrevented, true);
  assert.equal(f.state.submitting, true);
  assert.ok(f.controls.every(control => control.disabled));
  const hide = new Event('hide.bs.modal', { cancelable: true });
  f.root.dispatchEvent(hide);
  assert.equal(hide.defaultPrevented, true);
  const navigation = new Event('synapse:before-navigate', { cancelable: true });
  f.document.dispatchEvent(navigation);
  assert.equal(navigation.defaultPrevented, true);
  await f.state.submitTicket(f.event());
  assert.equal(f.calls.length, 1);
  const { options } = f.calls[0];
  assert.equal(options.method, 'POST');
  assert.equal(options.credentials, 'same-origin');
  assert.equal(options.redirect, 'error');
  assert.equal(options.headers['X-CSRF-TOKEN'], 'latest');
  assert.equal(options.headers['X-Synapse-Feedback'], 'toast');
  assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(options.headers['HX-Request'], undefined);
  assert.deepEqual([...options.body], [['person_id', '11'], ['priority', 'urgent'], ['csrf', 'latest']]);
  f.respond();
  await job;
  assert.equal(f.state.committed, true);
  assert.equal(f.state.selectedPerson.id, 11, 'Committed hidden must not reset form before refresh');
  assert.ok(!f.order.includes('reset'));
  assert.deepEqual(f.order.at(-1), ['refresh', 'https://queue.test/clinic/queue?date=2026-10-25', 'Ticket C001 issued successfully.']);
  assert.equal(f.synced[0].hash, 'rotated');
  assert.deepEqual(f.notices, []);
  f.state.destroy();
});

for (const failure of ['validation', 'html', 'network']) {
  test(`${failure} preserves walk-in draft, releases controls and does not hide/refresh`, async () => {
    const f = fixture();
    const job = f.state.submitTicket(f.event());
    if (failure === 'validation') f.respond({ success: false, message: 'Already queued.', csrf: { hash: 'new' } }, false);
    if (failure === 'html') f.respond({}, true, 'text/html');
    if (failure === 'network') f.rejectResponse(new TypeError('offline'));
    await job;
    assert.equal(f.state.submitting, false);
    assert.equal(f.state.committed, false);
    assert.equal(f.state.selectedPerson.id, 11);
    assert.equal(f.state.searchTerm, 'draft');
    assert.deepEqual(f.controls.map(control => control.disabled), [false, true]);
    assert.ok(!f.order.includes('hide'));
    assert.equal(f.notices.length, 1);
    assert.equal(f.state.feedback, f.notices[0]);
    if (failure === 'validation') assert.equal(f.synced[0].hash, 'new');
    f.state.destroy();
  });
}

for (const refreshFails of [true, false]) {
  test(`confirmed write locks replay when refresh ${refreshFails ? 'throws' : 'returns false'}`, async () => {
    const f = fixture({ refreshFails, refreshFalse: !refreshFails });
    const job = f.state.submitTicket(f.event());
    f.respond();
    await job;
    await f.state.submitTicket(f.event());
    f.state.changePerson();
    assert.equal(f.calls.length, 1);
    assert.equal(f.state.committed, true);
    assert.equal(f.state.selectedPerson.id, 11);
    f.state.destroy();
  });
}

test('no shared helper retains native submit without a fetch or special header', async () => {
  const f = fixture({ helpers: false });
  const event = f.event();
  await f.state.submitTicket(event);
  assert.equal(event.defaultPrevented, false);
  assert.equal(f.state.submitting, true);
  event.preventDefault();
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(f.state.submitting, false);
  assert.equal(f.calls.length, 0);
  f.state.destroy();
});

for (const action of ['https://other.test/write', 'https://user:secret@queue.test/write']) {
  test(`rejects unsafe action ${new URL(action).origin} without sending fields`, async () => {
    const f = fixture();
    f.form.action = action;
    await f.state.submitTicket(f.event());
    assert.equal(f.calls.length, 0);
    assert.equal(f.state.selectedPerson.id, 11);
    assert.equal(f.notices.length, 1);
    f.state.destroy();
  });
}

test('only no-show opts into generic background submit; walk-in has a dedicated owner', () => {
  const noShow = fs.readFileSync(path.join(__dirname, '../../app/Views/queue/no_show_action.php'), 'utf8');
  assert.match(noShow, /data-background-submit/);
  assert.match(noShow, /data-confirm=/);
  assert.match(noShow, /csrf_field\(\)/);
  assert.doesNotMatch(source.match(/<form[^]*?<\/form>/)[0], /data-background-submit/);
  assert.match(source, /:inert="submitting \|\| committed"/);
});
