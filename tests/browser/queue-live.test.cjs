'use strict';
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const script = fs.readFileSync(path.join(root, 'public/assets/js/queue-live.js'), 'utf8');
let browser, server, origin;
const date = '2026-10-22';
const packet = (kind, count = 2) => kind === 'portal'
  ? { date, tickets: count ? [{ unit: 'clinic', ticket_code: 'C-001', status: 'called', priority: 'routine' }] : [] }
  : { unit: kind, date, counts: { queueToday: count, queueWaiting: count - 1, queueCalled: 1, queueCompleted: 5 } };
const hint = kind => kind === 'portal' ? { event: 'portal.queue.changed', data: [] } : { event: 'queue.invalidated', data: { unit: kind, date } };
before(async () => {
  server = http.createServer((req, res) => {
    if (req.url === '/queue-live.js') return res.writeHead(200, { 'Content-Type': 'text/javascript' }).end(script);
    const kind = new URL(req.url, 'http://local').searchParams.get('kind') || 'portal';
    const attrs = kind === 'portal' ? 'data-personal-queue data-queue-url="/index.php/portal/queue"' : `data-queue-dashboard="${kind}" data-queue-counts-url="/index.php/${kind}/dashboard/queue-counts" data-queue-date="${date}"`;
    res.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><html><head><meta charset="utf-8"></head><body><main id="main"><section ${attrs}>
      ${kind === 'portal' ? '<div data-personal-queue-tickets>Fixture ticket</div>' : '<strong data-queue-count="queueToday">0</strong><strong data-queue-count="queueToday">0</strong><span data-queue-count="queueWaiting" data-queue-format="plain">0</span><span data-queue-count="queueCalled">0</span><span data-queue-count="queueCompleted">0</span><span data-queue-trend="queueWaiting"></span>'}
      <p data-queue-feedback hidden></p></section><input id="draft" value="unsaved notes"></main><script src="/queue-live.js"></script></body></html>`);
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath });
});
after(async () => {
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
async function fixture(t, kind = 'portal', ready = true) {
  const context = await browser.newContext();
  await context.addInitScript(ready => {
    window.SynapseRealtime = { status: ready ? 'ready' : 'disabled' };
    let hidden = false;
    Object.defineProperty(document, 'hidden', { get: () => hidden });
    window.visibility = value => { hidden = value; document.dispatchEvent(new Event('visibilitychange')); };
    window.hint = detail => document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail }));
    window.emitStatus = status => { window.SynapseRealtime.status = status; document.dispatchEvent(new CustomEvent('synapse:realtime-status', { detail: { status } })); };
    const set = window.setTimeout.bind(window), clear = window.clearTimeout.bind(window);
    let next = -1;
    window.timers = new Map();
    window.setTimeout = (fn, ms) => { if (![10000, 15000, 60000].includes(ms)) return set(fn, ms); const id = next--; window.timers.set(id, { fn, ms }); return id; };
    window.clearTimeout = id => { if (!window.timers.delete(id)) clear(id); };
    window.expire = ms => { const item = [...window.timers].find(([, t]) => t.ms === ms); if (!item) throw Error('Missing timer ' + ms); window.timers.delete(item[0]); item[1].fn(); };
  }, ready);
  const page = await context.newPage();
  const state = { page, calls: [], response: packet(kind), status: 200, wait: null };
  await page.route('**/index.php/**', async route => {
    state.calls.push(route.request());
    const body = state.response, status = state.status, wait = state.wait;
    if (wait) await wait;
    try { await route.fulfill({ status, json: body }); } catch (_) { /* A hidden/disposed view may abort its GET. */ }
  });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/?kind=' + kind);
  await page.waitForFunction(() => [...window.timers.values()].some(t => t.ms === 15000 || t.ms === 60000));
  return state;
}
async function waitCalls(s, count) {
  const end = Date.now() + 5000;
  while (s.calls.length < count && Date.now() < end) await new Promise(resolve => setTimeout(resolve, 20));
  assert.equal(s.calls.length, count);
}
for (const kind of ['portal', 'clinic', 'guidance']) {
  test(`${kind}: scoped bursts refresh only the queue projection, adapt polling, reconnect`, async t => {
    const s = await fixture(t, kind); const { page } = s;
    assert.equal(s.calls.length, 1);
    assert.ok(s.calls.every(r => r.method() === 'GET'));
    assert.deepEqual(await page.evaluate(() => [...window.timers.values()].map(t => t.ms)), [60000]);
    await page.evaluate(() => window.hint({ event: 'unrelated', data: {} }));
    if (kind !== 'portal') await page.evaluate(() => window.hint({ event: 'queue.invalidated', data: { unit: 'other', date: '2026-10-22' } }));
    await page.waitForTimeout(150); assert.equal(s.calls.length, 1);
    s.response = packet(kind, 4);
    await page.evaluate(event => { for (let i = 0; i < 10; ++i) window.hint(event); }, hint(kind));
    await waitCalls(s, 2); await page.waitForTimeout(150);
    assert.equal(s.calls.length, 2);
    assert.equal(await page.locator('#draft').inputValue(), 'unsaved notes');
    if (kind !== 'portal') assert.deepEqual(await page.locator('[data-queue-count="queueToday"]').allTextContents(), ['4', '4']);
    await page.evaluate(() => window.emitStatus('retrying'));
    assert.deepEqual(await page.evaluate(() => [...window.timers.values()].map(t => t.ms)), [15000]);
    await page.evaluate(() => window.emitStatus('ready')); await waitCalls(s, 3);
    await page.waitForFunction(() => [...window.timers.values()].some(t => t.ms === 60000));
  });
}
test('personal: tickets appear, change status, show skipped, and disappear without page reload; values stay literal', async t => {
  const s = await fixture(t); const { page } = s;
  assert.match(await page.locator('[data-personal-queue-tickets]').textContent(), /Called/);
  s.response = { date, tickets: [{ unit: 'guidance', ticket_code: '<img src=x onerror=alert(1)>', status: 'skipped', priority: 'urgent' }] };
  await page.evaluate(event => window.hint(event), hint('portal')); await waitCalls(s, 2);
  await page.waitForFunction(() => document.querySelector('[data-personal-queue-tickets]').textContent.includes('Skipped'));
  assert.equal(await page.locator('[data-personal-queue-tickets] img').count(), 0);
  s.response = packet('portal', 0);
  await page.evaluate(event => window.hint(event), hint('portal')); await waitCalls(s, 3);
  await page.getByText('No active waiting room tickets today.').waitFor();
  s.response = packet('portal', 2);
  await page.evaluate(event => window.hint(event), hint('portal')); await waitCalls(s, 4);
  await page.getByText('C-001').waitFor();
});
test('hidden pages pause work; restore coalesces; navigation removes all handlers and timers', async t => {
  const s = await fixture(t); const { page } = s;
  await page.evaluate(() => window.visibility(true));
  await page.evaluate(event => { window.emitStatus('paused'); window.hint(event); }, hint('portal'));
  await page.waitForTimeout(150); assert.equal(s.calls.length, 1);
  assert.equal(await page.evaluate(() => window.timers.size), 0);
  await page.evaluate(() => { window.visibility(false); window.emitStatus('ready'); }); await waitCalls(s, 2);
  await page.waitForFunction(() => [...window.timers.values()].some(t => t.ms === 60000));
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('#main') } })));
  await page.evaluate(event => { window.hint(event); window.emitStatus('ready'); }, hint('portal'));
  await page.waitForTimeout(150); assert.equal(s.calls.length, 2);
  assert.equal(await page.evaluate(() => window.timers.size), 0);
});
test('hint during GET triggers one trailing refresh; repeated module initialization does not duplicate', async t => {
  const s = await fixture(t, 'clinic'); const { page } = s;
  let release; s.wait = new Promise(resolve => { release = resolve; }); t.after(() => release());
  await page.evaluate(event => window.hint(event), hint('clinic')); await waitCalls(s, 2);
  await page.addScriptTag({ content: script });
  await page.evaluate(event => { window.hint(event); window.hint(event); }, hint('clinic'));
  s.wait = null; release(); await waitCalls(s, 3); await page.waitForTimeout(200);
  assert.equal(s.calls.length, 3);
});
for (const kind of ['portal', 'clinic']) {
  test(`${kind}: denied access clears old data and stops all background requests`, async t => {
    const s = await fixture(t, kind); const { page } = s;
    s.status = 403; s.response = { message: 'Access changed' };
    await page.evaluate(event => window.hint(event), hint(kind)); await waitCalls(s, 2);
    await page.getByText('Your session or access changed. Reload or sign in again.').waitFor();
    if (kind === 'portal') assert.equal(await page.locator('[data-personal-queue-tickets]').textContent(), '');
    else assert.deepEqual(await page.locator('[data-queue-count="queueToday"]').allTextContents(), ['—', '—']);
    await page.evaluate(event => { window.hint(event); window.emitStatus('ready'); }, hint(kind));
    await page.waitForTimeout(150); assert.equal(s.calls.length, 2);
    assert.equal(await page.evaluate(() => window.timers.size), 0);
  });
}
test('disabled realtime still refreshes at 15s and dashboard day rollover uses authoritative date', async t => {
  const s = await fixture(t, 'clinic', false); const { page } = s;
  assert.deepEqual(await page.evaluate(() => [...window.timers.values()].map(t => t.ms)), [15000]);
  s.response = { ...packet('clinic', 3), date: '2026-10-23' };
  await page.evaluate(() => window.expire(15000)); await waitCalls(s, 2);
  await page.waitForFunction(() => document.querySelector('[data-queue-dashboard]').dataset.queueDate === '2026-10-23');
  s.response = { ...packet('clinic', 9), counts: { queueToday: '<script>bad</script>' } };
  await page.evaluate(event => window.hint(event), hint('clinic')); await waitCalls(s, 3);
  await page.locator('[data-queue-feedback]').waitFor({ state: 'visible' });
  assert.deepEqual(await page.locator('[data-queue-count="queueToday"]').allTextContents(), ['3', '3'], 'Malformed counts never partially overwrite the snapshot');
});
test('a full-response deadline releases hung GETs and ignores late response data', async t => {
  const s = await fixture(t); const { page } = s;
  await page.evaluate(() => {
    const original = window.fetch.bind(window); let hang = true;
    window.fetch = (url, options) => {
      if (!hang) return original(url, options);
      hang = false;
      return new Promise(resolve => { window.releaseOldQueue = () => resolve(new Response(JSON.stringify({ date: '2026-10-22', tickets: [] }), { status: 200 })); });
    };
  });
  await page.evaluate(event => window.hint(event), hint('portal'));
  await page.waitForFunction(() => typeof window.releaseOldQueue === 'function');
  await page.evaluate(() => window.expire(10000));
  await page.getByText('Queue updates are temporarily unavailable. The displayed status may be out of date.').waitFor();
  await page.evaluate(() => window.expire(60000)); await waitCalls(s, 2);
  await page.waitForFunction(() => document.querySelector('[data-queue-feedback]').hidden);
  await page.evaluate(() => window.releaseOldQueue()); await page.waitForTimeout(100);
  assert.match(await page.locator('[data-personal-queue-tickets]').textContent(), /C-001/);
});
