'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../../app/Views/queue/index.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1];

function fixture() {
  const handlers = new Map();
  const notice = { hidden: true, textContent: '' };
  const document = {
    addEventListener: (name, handler) => handlers.set(name, handler),
    getElementById: id => id === 'queue-action-error' ? notice : null,
  };
  vm.runInNewContext(script, { document });
  return { notice, fire: (name, detail) => handlers.get(name)({ detail }) };
}

test('stale queue actions display JSON errors as text without replacing the board', () => {
  const { notice, fire } = fixture();
  const message = '<img src=x onerror=alert(1)> Queue ticket changed.';
  fire('htmx:responseError', {
    target: { id: 'queue-board-container' }, xhr: { responseText: JSON.stringify({ error: message }) },
  });
  assert.equal(notice.hidden, false);
  assert.equal(notice.textContent, message);
  assert.equal(Object.hasOwn(notice, 'innerHTML'), false);
});

test('unrelated errors are ignored and malformed responses use a safe fallback', () => {
  const { notice, fire } = fixture();
  fire('htmx:responseError', { target: { id: 'another-panel' } });
  assert.equal(notice.hidden, true);
  fire('htmx:responseError', { target: { id: 'queue-board-container' }, xhr: { responseText: '<html>Error</html>' } });
  assert.equal(notice.hidden, false);
  assert.equal(notice.textContent, 'Queue action failed. Reload the board before trying again.');
});

test('polling does not dismiss a conflict, but a successful queue mutation does', () => {
  const { notice, fire } = fixture();
  notice.hidden = false;
  const target = { id: 'queue-board-container' };
  fire('htmx:afterRequest', { target, successful: true, requestConfig: { verb: 'get' } });
  assert.equal(notice.hidden, false);
  fire('htmx:afterRequest', { target, successful: false, requestConfig: { verb: 'post' } });
  assert.equal(notice.hidden, false);
  fire('htmx:afterRequest', { target, successful: true, requestConfig: { verb: 'post' } });
  assert.equal(notice.hidden, true);
});

function searchFixture(fetch) {
  const context = { document: { addEventListener() {} }, window: {}, AbortController, fetch };
  vm.createContext(context);
  vm.runInContext(script, context);
  const state = context.walkinSearch();
  state.$root = { classList: { contains: () => true } };
  state.$refs = { form: { reset() {} } };
  return state;
}

const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};

test('walk-in input invalidates results immediately before the bounded debounce', () => {
  assert.match(view, /maxlength="150"\s+@input="cancelSearch\(\)"\s+@input\.debounce\.300ms="searchPatient\(\)"/);
  assert.match(view, /Searching local directory/);
  assert.doesNotMatch(view, /Searching MIS directory/);
});

test('walk-in rejects an old same-term response even inside the debounce gap', async () => {
  const body = deferred();
  let signal;
  const state = searchFixture(async (_, options) => {
    signal = options.signal;
    return { ok: true, json: () => body.promise };
  });
  state.searchTerm = 'Ada';
  const pending = state.searchPatient();
  await Promise.resolve();
  state.results = [{ id: 1 }];
  state.searchTerm = 'Grace';
  state.cancelSearch();
  state.searchTerm = 'Ada';
  state.cancelSearch();
  assert.equal(signal.aborted, true);
  assert.equal(state.results.length, 0);
  assert.equal(state.loading, false);
  body.resolve({ results: [{ id: 11 }] });
  await pending;
  assert.equal(state.results.length, 0);
  assert.equal(state.searchComplete, false);
});

test('walk-in failures are safe, empty success is distinct, and lifecycle clears feedback', async () => {
  for (const response of [
    () => Promise.reject(new Error('private exception')),
    async () => ({ ok: false }),
    async () => ({ ok: true, json: async () => { throw new Error('private JSON'); } }),
    async () => ({ ok: true, json: async () => ({ results: null }) }),
  ]) {
    const state = searchFixture(response);
    state.searchTerm = 'Ada';
    await state.searchPatient();
    assert.equal(state.searchError, 'Unable to search the local directory. Please try again.');
    assert.equal(state.loading, false);
    assert.equal(state.searchComplete, false);
    state.changePerson();
    assert.equal(state.searchError, '');
  }
  const state = searchFixture(async () => ({ ok: true, json: async () => ({ results: [] }) }));
  state.searchTerm = 'Nobody';
  await state.searchPatient();
  assert.equal(state.searchComplete, true);
  assert.equal(state.searchError, '');
  state.selectPerson({ id: 11 });
  assert.equal(state.searchComplete, false);
  state.discardDraft();
  assert.equal(state.selectedPerson, null);
  assert.equal(state.searchTerm, '');
});

test('walk-in ignores aborted errors and overlong programmatic searches', async () => {
  const failure = deferred();
  let calls = 0;
  const state = searchFixture(() => { calls++; return failure.promise; });
  state.searchTerm = 'x'.repeat(151);
  await state.searchPatient();
  assert.equal(calls, 0);
  state.searchTerm = 'Ada';
  const pending = state.searchPatient();
  state.cancelSearch();
  failure.reject(new Error('private delayed failure'));
  await pending;
  assert.equal(state.searchError, '');
  assert.equal(state.loading, false);
});

test('call-next and board polling include the selected queue date', () => {
  assert.match(view, /queue\/call-next[\s\S]*?hx-include="#queueDateFilter_iso"/);
  const markup = view.replace(/<\?[\s\S]*?\?>/g, '');
  assert.match(markup, /id="queue-board-container"[^>]*hx-include="#queueDateFilter_iso"/);
  assert.match(view, /id="queue-action-error"[^>]*role="alert"[^>]*hidden/);
});
