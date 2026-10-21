'use strict';

// Isolated production-state tests: no browser, server, database or shared globals.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/survey-builder.js'), 'utf8');
const item = id => ({ id, revision: 1, category: 'Feedback', audience: 'all',
  question: { type: 'single', label: 'Question ' + id, required: true, options: ['A', 'B'] } });
const payload = (id = 1, page = 1) => ({ success: true, items: [item(id)], page, pages: 3 });
const response = data => ({ ok: true, headers: new Headers({ 'Content-Type': 'application/json' }),
  json: async () => data });
const input = (value, isComposing = false) => ({ target: { value }, isComposing });
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

function harness(catalog = '/index.php/guidance/question-bank/catalog') {
  const timers = new Map();
  const calls = [];
  let now = 0;
  let next = 0;
  const context = { window: {}, URL, URLSearchParams, AbortController,
    location: new URL('http://localhost/index.php/guidance/surveys'),
    setTimeout(fn, delay) { const id = ++next; timers.set(id, { fn, at: now + delay }); return id; },
    clearTimeout(id) { timers.delete(id); },
    fetch(url, options) {
      return new Promise((resolve, reject) => calls.push({ url, options, resolve, reject }));
    } };
  vm.runInNewContext(source, context);
  const state = context.window.SynapseSurveyBuilder([{ type: 'text', label: '', required: true, options: [] }], 'all', catalog);
  return { state, calls, timers, async tick(ms) {
    const end = now + ms;
    while (true) {
      const entry = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!entry) break;
      now = entry[1].at;
      timers.delete(entry[0]);
      entry[1].fn();
      await flush();
    }
    now = end;
    await flush();
  } };
}

test('typing invalidates immediately, debounces 300ms and ignores responses from an abort-ignoring fetch', async () => {
  const { state, calls, tick } = harness();
  state.openBank();
  const draft = JSON.stringify(state.questions);
  state.toggleBankSelection(item(9), true);
  state.bankPage = 3;
  state.bankFilterInput('bankQuery', input('old'));
  assert.equal(calls[0].options.signal.aborted, true);
  assert.equal(state.bankPage, 1);
  calls[0].resolve(response(payload(99)));
  await flush();
  assert.equal(state.bankItems.length, 0);
  await tick(200);
  state.bankFilterInput('bankQuery', input('latest'));
  await tick(299);
  assert.equal(calls.length, 1);
  await tick(1);
  assert.equal(calls.length, 2);
  assert.equal(calls[1].url.searchParams.get('q'), 'latest');
  assert.equal(calls[1].url.searchParams.get('page'), '1');
  calls[1].resolve(response(payload(2)));
  await flush();
  assert.equal(state.bankItems[0].id, 2);
  assert.equal(state.bankBusy, false);
  assert.equal(state.bankSelected[0].id, 9);
  assert.equal(JSON.stringify(state.questions), draft);
  state.destroy();
});

test('category is debounced, audience is immediate and explicit Search cancels pending debounce', async () => {
  const { state, calls, tick } = harness();
  state.bankOpen = true;
  state.bankFilterInput('bankCategory', input('Feedback'));
  await tick(299);
  assert.equal(calls.length, 0);
  state.bankFilterInput('bankAudience', input('students'), true);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url.searchParams.get('category'), 'Feedback');
  assert.equal(calls[0].url.searchParams.get('audience'), 'students');
  await tick(1);
  assert.equal(calls.length, 1);
  state.bankFilterInput('bankQuery', input('manual'));
  state.searchBank(1);
  assert.equal(calls.length, 2);
  await tick(300);
  assert.equal(calls.length, 2);
  state.destroy();
});

test('IME never searches mid-composition or treats confirmation Enter as Search', async () => {
  const { state, calls, tick } = harness();
  state.bankOpen = true;
  state.bankComposition('bankQuery', input(''), true);
  state.bankFilterInput('bankQuery', input('質問', true));
  let prevented = false;
  state.bankSearchEnter({ isComposing: true, preventDefault() { prevented = true; } });
  state.bankFilterInput('bankAudience', input('all'), true);
  await tick(400);
  assert.equal(calls.length, 0);
  assert.equal(prevented, false);
  state.bankComposition('bankQuery', input('質問'), false);
  await tick(299);
  assert.equal(calls.length, 0);
  await tick(1);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url.searchParams.get('q'), '質問');
  state.destroy();
});

test('timeout bounds busy state even when abort is ignored; late rejection cannot overwrite retry', async () => {
  const { state, calls, tick, timers } = harness();
  state.bankOpen = true;
  state.searchBank();
  await tick(10000);
  assert.equal(state.bankBusy, false);
  assert.match(state.bankError, /timed out/);
  assert.equal(calls[0].options.signal.aborted, true);
  state.searchBank();
  calls[1].resolve(response(payload(2)));
  await flush();
  calls[0].reject(new Error('stale error'));
  await flush();
  assert.equal(state.bankError, '');
  assert.equal(state.bankItems[0].id, 2);
  assert.equal(timers.size, 0);
  state.destroy();
});

test('closed and destroyed pickers cancel all work while retaining selections across reopen and pages', async () => {
  const { state, calls, tick, timers } = harness();
  state.toggleBankSelection(item(7), true);
  await state.searchBank();
  assert.equal(calls.length, 0);
  state.openBank();
  await state.openBank();
  calls[0].resolve(response(payload(99)));
  await flush();
  assert.equal(state.bankItems.length, 0);
  state.bankFilterInput('bankQuery', input('closed'));
  await tick(300);
  assert.equal(calls.length, 1);
  state.openBank();
  calls[1].resolve(response(payload(7)));
  await flush();
  assert.equal(state.isBankSelected(state.bankItems[0]), true);
  state.searchBank(2);
  calls[2].resolve(response(payload(8, 2)));
  await flush();
  assert.equal(state.bankSelected[0].id, 7);
  assert.equal(state.bankPage, 2);
  state.bankFilterInput('bankCategory', input('pending'));
  state.destroy();
  await tick(20000);
  await state.openBank();
  assert.equal(calls.length, 3);
  assert.equal(timers.size, 0);
});

test('guards catalog destination, redirects, content type and malformed JSON shape', async () => {
  for (const catalog of ['https://other.test/guidance/question-bank/catalog', '/untrusted/catalog',
    '/guidance/question-bank/catalog?override=1', '/guidance/question-bank/catalog#fragment']) {
    const { state, calls } = harness(catalog);
    await state.openBank();
    assert.equal(calls.length, 0);
    assert.match(state.bankError, /address/);
    state.destroy();
  }
  const malformed = [null, { success: true, items: null, page: 1, pages: 1 },
    { ...payload(), items: [null] }, { ...payload(), items: [{ ...item(1), question: { options: null } }] },
    { ...payload(), page: '1' }, { ...payload(), pages: 0 }];
  for (const data of malformed) {
    const { state, calls } = harness();
    state.openBank();
    calls[0].resolve(response(data));
    await flush();
    assert.notEqual(state.bankError, '');
    assert.equal(state.bankItems.length, 0);
    assert.equal(state.bankBusy, false);
    state.destroy();
  }
  for (const overrides of [{ redirected: true }, { url: 'http://localhost/login' },
    { headers: new Headers({ 'Content-Type': 'text/html' }) }, { json: async () => { throw new SyntaxError('Invalid JSON'); } }]) {
    const { state, calls } = harness();
    state.openBank();
    calls[0].resolve({ ...response(payload()), ...overrides });
    await flush();
    assert.notEqual(state.bankError, '');
    assert.equal(state.bankItems.length, 0);
    state.destroy();
  }
});

test('a newer request owns busy/error state while an older JSON body is still pending', async () => {
  const { state, calls } = harness();
  state.openBank();
  let resolveBody;
  calls[0].resolve({ ...response(null), json: () => new Promise(resolve => { resolveBody = resolve; }) });
  await flush();
  state.searchBank(2);
  resolveBody(payload(99));
  await flush();
  assert.equal(state.bankBusy, true);
  assert.equal(state.bankItems.length, 0);
  calls[1].resolve(response(payload(2, 2)));
  await flush();
  assert.equal(state.bankPage, 2);
  assert.equal(state.bankItems[0].id, 2);
  state.destroy();
});
