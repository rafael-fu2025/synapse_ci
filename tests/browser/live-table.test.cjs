'use strict';

// Real CI views, app shell, widgets, Bootstrap, live-table.js and form-modal.js.
// The local server mocks read responses only; SQL and capability enforcement belong
// to backend tests. No controllers, database, app writes or source-module imports.
const { describe, test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const literal = '\\"><img src=x onerror="window.liveTableXss=1"><script>window.liveTableXss=1</script>&';
const tables = [
  { kind: 'patients', url: '/clinic/patients', search: '#patientSearch', filter: '#patientKind',
    field: 'kind', choice: 'student', label: 'Student', all: 'All patients', size: '#patientPerPage' },
  { kind: 'inventory', url: '/clinic/inventory', search: '#inventorySearch', filter: '#inventoryFilter',
    field: 'status', choice: 'low_stock', label: 'Low stock', all: 'All medicines', size: '#inventoryPerPage' }
];
let browser, server, origin, nextResponse, held;
const cache = new Map(), requests = [];
function gate() {
  let release;
  return { promise: new Promise(resolve => { release = resolve; }), release: () => release() };
}
function render(kind, requestPath, profile = 'manager', id) {
  const key = JSON.stringify([kind, requestPath, profile, id]);
  if (!cache.has(key)) {
    const result = spawnSync('php', ['tests/_support/render-live-table-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: requestPath, kind, profile, id }), encoding: 'utf8', timeout: 6000
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.ok(!/Fatal error|Warning:|Parse error|"code"\s*:\s*500/.test(result.stdout), result.stdout);
    assert.match(result.stdout, /<div/);
    cache.set(key, result.stdout);
  }
  return cache.get(key);
}
function params(table, values = {}, fragment = true) {
  return Object.fromEntries(Object.entries({ q: '', [table.field]: 'all', per_page: '25', page: '1',
    ...(fragment ? { fragment: '1' } : {}), ...values }).filter(([, value]) => value !== undefined));
}
function assertQuery(record, table, values = {}, fragment = true) {
  assert.equal(record.method, 'GET');
  assert.equal(record.url.pathname, table.url);
  assert.deepEqual([...record.url.searchParams.keys()].sort(), Object.keys(params(table, values, fragment)).sort());
  assert.deepEqual(Object.fromEntries(record.url.searchParams), params(table, values, fragment));
  if (fragment) {
    assert.equal(record.ajax, 'XMLHttpRequest');
    assert.match(record.accept, /text\/html/);
  } else assert.equal(record.ajax, undefined);
}
async function pageFor(t, table, { query = '', theme = 'light', profile = 'manager', javaScriptEnabled = true } = {}) {
  nextResponse = null;
  held = null;
  const context = await browser.newContext({ javaScriptEnabled, viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  if (javaScriptEnabled) {
    await context.addInitScript(theme => {
      localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
      window.tableFetches = [];
      const nativeFetch = window.fetch;
      window.fetch = function (url, options) {
        if (String(url).includes('fragment=1') && !String(url).includes('/receive')) {
          const item = { url: String(url), aborted: false, at: performance.now() };
          window.tableFetches.push(item);
          options?.signal?.addEventListener('abort', () => { item.aborted = true; }, { once: true });
        }
        return nativeFetch.call(this, url, options);
      };
    }, theme);
  }
  await context.route('**/*', route => {
    if (!route.request().url().startsWith(origin + '/')) return route.fulfill({ status: 200, body: '' });
    return route.continue({ headers: { ...route.request().headers(), 'x-fixture-profile': profile } });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(3000);
  page.setDefaultNavigationTimeout(15000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.text().includes('Alpine Expression Error')) errors.push(message.text()); });
  t.after(async () => { held?.release(); nextResponse = null; await context.close(); assert.deepEqual(errors, []); });
  const response = await page.goto(origin + table.url + query);
  assert.equal(response.status(), 200, await response.text());
  if (javaScriptEnabled) {
    await page.waitForFunction(() => document.readyState === 'complete' && window.bootstrap && window.Alpine && window.BalaySelect && window.BalayDatepicker);
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
    for (const id of [table.filter, table.size]) assert.equal(await page.locator(id).locator('..').locator('.bd-select-toggle').count(), 1);
    await settled(page);
  }
  return page;
}
async function settled(page) {
  await page.waitForFunction(() => document.querySelector('[data-table-results]')?.getAttribute('aria-busy') === 'false');
}
async function update(page, action) {
  const response = page.waitForResponse(response => {
    const url = new URL(response.url());
    return tables.some(table => table.url === url.pathname.replace(/^\/index\.php(?=\/)/, '')) && url.searchParams.get('fragment') === '1';
  });
  await action();
  await response;
  await settled(page);
}
async function choose(page, id, label) {
  const wrap = page.locator(id).locator('..');
  await wrap.locator('.bd-select-toggle').click();
  await wrap.getByRole('option', { name: label, exact: true }).click();
}
async function meta(page, expected) {
  const data = await page.locator('[data-table-pagination]').evaluate(el => ({ ...el.dataset }));
  for (const [key, value] of Object.entries(expected)) assert.equal(data[key], String(value), key);
}
async function rows(page) {
  return page.locator('[data-table-results] tbody tr').filter({ hasNot: page.locator('td[colspan]') }).count();
}
async function label(page, id, value) {
  assert.equal((await page.locator(id).locator('..').locator('.bd-select-label').innerText()).trim(), value);
}
async function next(page) {
  await update(page, () => page.getByRole('link', { name: 'Next', exact: true }).click());
}
async function alerts(page, values) {
  assert.deepEqual(await page.locator('[data-table-results] .fs-3').allTextContents(), values.map(String));
}

describe('live tables: real views with read-only response fixtures', { timeout: 115_000 }, () => {
  before(async () => {
    let executablePath = chromium.executablePath();
    if (!fs.existsSync(executablePath)) {
      const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
      executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
        .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
        .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
      assert.ok(executablePath, 'Install Chromium for the existing browser-test tools');
    }
    server = http.createServer(async (req, res) => {
      try {
        const url = new URL(req.url, 'http://localhost');
        if (req.method !== 'GET') { res.writeHead(405).end('Read-only fixture'); return; }
        const routePath = url.pathname.replace(/^\/index\.php(?=\/)/, '');
        const table = tables.find(table => table.url === routePath);
        const receive = routePath.match(/^\/clinic\/inventory\/(\d+)\/receive$/);
        if (url.pathname === '/login') { res.writeHead(200, { 'Content-Type': 'text/html' }).end('<html><body>Sign in</body></html>'); return; }
        if (table || receive) {
          const record = { url, method: req.method, ajax: req.headers['x-requested-with'], accept: req.headers.accept, at: Date.now() };
          requests.push(record);
          const mode = table && url.searchParams.get('fragment') === '1' ? nextResponse : null;
          if (mode) nextResponse = null;
          if (mode?.gate) await mode.gate.promise;
          if (res.destroyed) return;
          if (mode?.type === 'redirect') { res.writeHead(302, { Location: '/login' }).end(); return; }
          if (mode?.type === 'wrong') { res.writeHead(200, { 'Content-Type': 'text/html' }).end('<html><body><h1>Wrong full page</h1></body></html>'); return; }
          if (mode?.status) {
            res.writeHead(mode.status, { 'Content-Type': 'application/json' }).end(JSON.stringify({ message: `Fixture ${mode.status}: results unavailable.` }));
            return;
          }
          const html = render(receive ? 'receive' : table.kind, url.pathname + url.search,
            req.headers['x-fixture-profile'] || 'manager', receive ? Number(receive[1]) : undefined);
          res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(html);
          return;
        }
        const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
        if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
        fs.readFile(file, (error, content) => {
          if (error) { res.writeHead(404).end(); return; }
          res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' }).end(content);
        });
      } catch (error) { res.writeHead(500, { 'Content-Type': 'text/plain' }).end(error.stack); }
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    origin = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ headless: true, executablePath, timeout: 15_000 });
  }, { timeout: 20_000 });
  after(async () => {
    held?.release();
    try { await browser?.close(); }
    finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
  }, { timeout: 10_000 });

  for (const table of tables) {
    test(`${table.kind}: Next during debounce keeps the pending query and starts page one`, async t => {
      const page = await pageFor(t, table);
      const oldNext = new URL(await page.getByRole('link', { name: 'Next', exact: true }).getAttribute('href'));
      assert.equal(oldNext.searchParams.get('q'), '');
      assert.equal(oldNext.searchParams.get('page'), '2');
      const count = requests.length;
      await page.locator(table.search).evaluate(input => input.addEventListener('input', () => {
        window.pendingSearchAt = performance.now();
      }, { once: true }));
      await update(page, async () => {
        await page.locator(table.search).fill('record60');
        // Click the actual old pagination link immediately, without actionability waits.
        await page.getByRole('link', { name: 'Next', exact: true }).evaluate(link => link.click());
      });
      const elapsed = await page.evaluate(() => window.tableFetches.at(-1).at - window.pendingSearchAt);
      assert.ok(elapsed >= 0 && elapsed < 300, `pagination must beat the debounce (${elapsed}ms)`);
      assertQuery(requests.at(-1), table, { q: 'record60' });
      await meta(page, { page: 1, filtered: 1, perPage: 25, total: 63 });
      assert.equal(await rows(page), 1);
      assert.match(await page.locator('tbody').innerText(), /record60/);
      assert.equal(await page.locator(table.search).inputValue(), 'record60');
      assert.equal(new URL(page.url()).searchParams.get('q'), 'record60');
      assert.equal(new URL(page.url()).searchParams.get('page'), '1');
      await page.waitForTimeout(350);
      assert.equal(requests.length, count + 1, 'pagination cancels the pending debounce');
      await meta(page, { page: 1, filtered: 1 });
    });

    test(`${table.kind}: retry preserves pagination push history so Back returns to page two`, async t => {
      const page = await pageFor(t, table);
      await next(page);
      await meta(page, { page: 2 });
      const pageTwoUrl = page.url();
      const pageTwoRows = await page.locator('tbody').innerText();
      nextResponse = { status: 503 };
      await next(page);
      assertQuery(requests.at(-1), table, { page: '3' });
      assert.equal(await page.locator('[data-table-error]').isVisible(), true);
      await meta(page, { page: 2 });
      assert.equal(await page.locator('tbody').innerText(), pageTwoRows);
      assert.equal(page.url(), pageTwoUrl, 'failed navigation must not alter history');
      await update(page, () => page.getByRole('button', { name: 'Retry', exact: true }).click());
      assertQuery(requests.at(-1), table, { page: '3' });
      await meta(page, { page: 3 });
      assert.equal(new URL(page.url()).searchParams.get('page'), '3');
      assert.equal(await rows(page), 13);
      assert.equal(await page.locator('[data-table-error]').isVisible(), false);
      await update(page, () => page.goBack());
      assertQuery(requests.at(-1), table, { page: '2' });
      await meta(page, { page: 2 });
      assert.equal(page.url(), pageTwoUrl);
      assert.equal(await page.locator('tbody').innerText(), pageTwoRows);
    });

    test(`${table.kind}: Back to initial alias restores rows and widget labels via canonical fetch`, async t => {
      const page = await pageFor(t, table, { query: '?index=1' });
      const canonical = { ...table, url: '/index.php' + table.url };
      const aliasUrl = page.url();
      assert.equal(new URL(aliasUrl).pathname, table.url);
      assert.equal(await page.locator('[data-live-table]').getAttribute('data-endpoint'), origin + canonical.url);
      assert.equal(await page.locator('[data-table-filters]').getAttribute('action'), origin + canonical.url);
      assert.equal(await page.locator('script[src$="/assets/js/live-table.js"]').getAttribute('src'), origin + '/assets/js/live-table.js');
      await meta(page, { page: 1, perPage: 25, filtered: 63 });
      await label(page, table.size, '25');
      const initialRows = await page.locator('tbody').innerText();
      await update(page, () => choose(page, table.size, '50'));
      assertQuery(requests.at(-1), canonical, { per_page: '50' });
      await meta(page, { page: 1, perPage: 50 });
      assert.equal(await rows(page), 50);
      await label(page, table.size, '50');
      const canonicalUrl = page.url();
      assert.equal(new URL(canonicalUrl).pathname, canonical.url);
      assert.equal(new URL(canonicalUrl).searchParams.has('fragment'), false);
      const historyLength = await page.evaluate(() => history.length);
      await update(page, () => page.goBack());
      assert.equal(requests.at(-1).url.pathname, canonical.url, 'alias popstate fetch uses the canonical endpoint');
      assert.deepEqual(Object.fromEntries(requests.at(-1).url.searchParams), { index: '1', fragment: '1' });
      await meta(page, { page: 1, perPage: 25, filtered: 63 });
      assert.equal(page.url(), aliasUrl, 'popstate must not replace the original alias history URL');
      assert.equal(await page.locator(table.size).inputValue(), '25');
      await label(page, table.size, '25');
      await label(page, table.filter, table.all);
      assert.equal(await rows(page), 25);
      assert.equal(await page.locator('tbody').innerText(), initialRows);
      assert.equal(await page.evaluate(() => history.length), historyLength);
      await update(page, () => page.goForward());
      assertQuery(requests.at(-1), canonical, { per_page: '50' });
      assert.equal(page.url(), canonicalUrl);
      await meta(page, { page: 1, perPage: 50 });
      await label(page, table.size, '50');
      assert.equal(await rows(page), 50);
      assert.equal(await page.evaluate(() => history.length), historyLength);
    });

    for (const theme of ['light', 'dark']) {
      test(`${table.kind} ${theme}: bounded pages, all-page counts, canonical links and debounce`, async t => {
        const page = await pageFor(t, table, { theme });
        assert.equal(await rows(page), 25);
        await meta(page, { page: 1, perPage: 25, filtered: 63, total: 63 });
        if (table.kind === 'inventory') await alerts(page, [25, 9, 7, 15]);
        const href = new URL(await page.getByRole('link', { name: 'Next', exact: true }).getAttribute('href'));
        assert.equal(href.searchParams.has('fragment'), false);
        assert.deepEqual(Object.fromEntries(href.searchParams), params(table, { page: '2' }, false));
        await next(page);
        assertQuery(requests.at(-1), table, { page: '2' });
        await next(page);
        assert.equal(await rows(page), 13);
        await meta(page, { page: 3, filtered: 63 });
        assert.match(await page.locator('[data-table-results] tbody').innerText(), /record60/);
        if (table.kind === 'inventory') await alerts(page, [25, 9, 7, 15]);
        for (const size of [50, 100, 25]) {
          await update(page, () => choose(page, table.size, String(size)));
          await meta(page, { page: 1, perPage: size });
          assert.equal(await rows(page), Math.min(size, 63));
          assertQuery(requests.at(-1), table, { per_page: String(size) });
          await label(page, table.size, String(size));
        }
        await next(page);
        await meta(page, { page: 2 });
        const count = requests.length;
        await page.locator(table.search).fill('rec');
        await page.waitForTimeout(100);
        await page.locator(table.search).fill('record60');
        await page.waitForTimeout(180);
        assert.equal(requests.length, count, 'no request before the 300ms debounce');
        await page.waitForFunction(() => document.querySelector('[data-table-pagination]').dataset.filtered === '1');
        await settled(page);
        assert.equal(requests.length, count + 1);
        assertQuery(requests.at(-1), table, { q: 'record60' });
        await meta(page, { page: 1 });
        assert.equal(await rows(page), 1, 'search covers records beyond the original 50');
        assert.match(await page.locator('tbody').innerText(), /record60/);
        assert.equal(new URL(page.url()).searchParams.has('fragment'), false);
      });
    }

    test(`${table.kind}: filters preserve query, reset page, sync history labels and refresh`, async t => {
      const page = await pageFor(t, table, { query: '?q=record&' + table.field + '=all&page=2&per_page=25' });
      assert.equal(await page.locator(table.search).inputValue(), 'record');
      await meta(page, { page: 2, filtered: 62 });
      await update(page, () => choose(page, table.filter, table.label));
      const filtered = table.kind === 'patients' ? 21 : 25;
      await meta(page, { page: 1, filtered, total: 63 });
      assertQuery(requests.at(-1), table, { q: 'record', [table.field]: table.choice });
      if (table.kind === 'inventory') await alerts(page, [25, 4, 3, 12]);
      const filteredRows = await page.locator('tbody').innerText();
      await update(page, () => choose(page, table.size, '50'));
      assertQuery(requests.at(-1), table, { q: 'record', [table.field]: table.choice, per_page: '50' });
      await update(page, () => page.goBack());
      await label(page, table.filter, table.label);
      await label(page, table.size, '25');
      assert.equal(await page.locator('tbody').innerText(), filteredRows);
      await update(page, () => page.goBack());
      await meta(page, { page: 2, perPage: 25, filtered: 62 });
      await label(page, table.filter, table.all);
      assert.equal(await page.locator(table.search).inputValue(), 'record');
      await update(page, () => page.goForward());
      await label(page, table.filter, table.label);
      assert.equal(await page.locator('tbody').innerText(), filteredRows);
      await page.reload();
      await settled(page);
      assert.equal(await page.locator(table.search).inputValue(), 'record');
      await label(page, table.filter, table.label);
      assert.equal(await page.locator('tbody').innerText(), filteredRows);
      await update(page, () => page.locator(table.search).fill('record60'));
      assertQuery(requests.at(-1), table, { q: 'record60', [table.field]: table.choice });
      await meta(page, { page: 1, filtered: 0 });
      assert.equal(await rows(page), 0);
      assert.match(await page.locator('tbody').innerText(), /No (patients|medicines)/);
      assert.match(await page.locator('[data-table-status]').innerText(), /No matching records/);
      await update(page, () => page.locator('[data-table-reset]').click());
      await meta(page, { page: 1, filtered: 63 });
      await label(page, table.filter, table.all);
      assert.equal(await page.locator(table.search).inputValue(), '');
      if (table.kind === 'inventory') {
        await update(page, () => page.locator(table.search).fill('record'));
        await update(page, () => choose(page, table.filter, 'Active'));
        const href = new URL(await page.getByRole('link', { name: 'Next', exact: true }).getAttribute('href'));
        assert.deepEqual(Object.fromEntries(href.searchParams), params(table, { q: 'record', status: 'active', page: '2' }, false));
        await next(page);
        assertQuery(requests.at(-1), table, { q: 'record', status: 'active', page: '2' });
        await meta(page, { page: 2, filtered: 50, total: 63 });
      }
    });

    test(`${table.kind}: clamp requested page, show busy state and leave modified links native`, async t => {
      const page = await pageFor(t, table, { query: '?q=record&' + table.field + '=all&page=999&per_page=25' });
      await meta(page, { page: 3, filtered: 62 });
      assert.equal(new URL(page.url()).searchParams.get('page'), '999', 'GET metadata reflects clamped server state');
      await page.getByRole('link', { name: 'Previous', exact: true }).evaluate(link => {
        const url = new URL(link.href); url.searchParams.set('page', '999'); link.href = url;
      });
      held = gate(); nextResponse = { gate: held };
      const request = page.waitForRequest(req => req.url().includes('fragment=1'));
      await page.getByRole('link', { name: 'Previous', exact: true }).click();
      await request;
      assert.equal(await page.locator('[data-table-results]').getAttribute('aria-busy'), 'true');
      assert.match(await page.locator('[data-table-status]').innerText(), /Loading/);
      held.release(); await settled(page);
      assert.equal(new URL(page.url()).searchParams.get('page'), '3', 'AJAX history uses returned clamped page');
      assertQuery(requests.at(-1), table, { q: 'record', page: '999' });
      const count = requests.length;
      const prevented = await page.locator('a[data-table-page]').first().evaluate(link => {
        const results = [];
        for (const options of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
          let intercepted;
          const observe = event => { intercepted = event.defaultPrevented; event.preventDefault(); };
          document.addEventListener('click', observe, { once: true });
          link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ...options }));
          results.push(intercepted);
        }
        return results;
      });
      assert.deepEqual(prevented, [false, false, false, false, false]);
      assert.equal(requests.length, count);
    });

    test(`${table.kind}: errors preserve rows, expose explicit retry and recover`, async t => {
      const page = await pageFor(t, table);
      const original = await page.locator('[data-table-results]').innerText();
      for (const type of [422, 403, 503, 'network', 'redirect', 'wrong']) {
        const retained = await page.locator('[data-table-results]').innerHTML();
        if (type === 'network') await page.route('**/*fragment=1*', route => route.abort('failed'), { times: 1 });
        else nextResponse = typeof type === 'number' ? { status: type } : { type };
        await page.locator(table.search).fill('record60');
        await page.locator('[data-table-error]:not([hidden])').waitFor();
        await settled(page);
        assert.equal(await page.locator('[data-table-results]').innerHTML(), retained, String(type));
        assert.ok((await page.locator('[data-table-error-message]').innerText()).length > 10);
        if (typeof type === 'number') assert.match(await page.locator('[data-table-error-message]').innerText(), new RegExp(String(type)));
        if (type === 'redirect') assert.match(await page.locator('[data-table-error-message]').innerText(), /session|sign in/i);
        if (type === 'wrong') assert.match(await page.locator('[data-table-error-message]').innerText(), /response|reload/i);
        assert.match(await page.locator('[data-table-status]').innerText(), /previous results/i);
        assert.equal(new URL(page.url()).searchParams.has('q'), false, 'failed destination is not recorded in history');
        await update(page, () => page.getByRole('button', { name: 'Retry', exact: true }).click());
        assertQuery(requests.at(-1), table, { q: 'record60' });
        await meta(page, { filtered: 1 });
        assert.equal(await page.locator('[data-table-error]').isVisible(), false);
        await update(page, () => page.locator('[data-table-reset]').click());
        assert.equal(await page.locator('[data-table-results]').innerText(), original);
        // Reset writes canonical defaults, but the next failure must not change them.
        await page.evaluate(() => SynapseNavigation?.updateHistory(location.pathname, 'replace') ?? history.replaceState(null, '', location.pathname));
      }
    });

    test(`${table.kind}: abort before debounce, stale success/failure cannot win, IME waits`, async t => {
      const page = await pageFor(t, table);
      for (const status of [undefined, 503]) {
        const original = await page.locator('tbody').innerText();
        held = gate(); nextResponse = { gate: held, status };
        const before = requests.length;
        const request = page.waitForRequest(req => new URL(req.url()).searchParams.get('q') === 'record59');
        await page.locator(table.search).fill('record59');
        await request;
        for (let attempt = 0; requests.length === before && attempt < 100; attempt++) {
          await new Promise(resolve => setTimeout(resolve, 10));
        }
        assert.equal(requests.length, before + 1, 'held request reached the fixture server');
        const count = requests.length;
        await page.locator(table.search).fill('record60');
        assert.equal(await page.evaluate(() => window.tableFetches.at(-1).aborted), true, 'abort occurs at input, not 300ms later');
        held.release();
        await page.waitForTimeout(100);
        assert.equal(requests.length, count, 'new debounce has not fired yet');
        assert.equal(await page.locator('tbody').innerText(), original);
        assert.equal(await page.locator('[data-table-error]').isVisible(), false);
        await page.waitForFunction(() => document.querySelector('[data-table-pagination]').dataset.filtered === '1' && document.querySelector('tbody').textContent.includes('record60'));
        await settled(page);
        assertQuery(requests.at(-1), table, { q: 'record60' });
        await update(page, () => page.locator('[data-table-reset]').click());
      }
      const count = requests.length;
      await page.locator(table.search).evaluate(input => {
        input.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
        input.value = 'record60';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true, data: 'record60' }));
      });
      await page.waitForTimeout(360);
      assert.equal(requests.length, count, 'IME composition must not issue partial searches');
      await update(page, () => page.locator(table.search).dispatchEvent('compositionend'));
      assert.equal(requests.length, count + 1);
      assertQuery(requests.at(-1), table, { q: 'record60' });
    });

    test(`${table.kind}: literal query and stored markup remain text through GET, fragment and refresh`, async t => {
      const page = await pageFor(t, table, { query: '?' + new URLSearchParams({ q: literal, [table.field]: 'all', page: '1', per_page: '25' }) });
      const safe = async () => {
        assert.equal(await page.locator(table.search).inputValue(), literal);
        assert.ok((await page.locator('tbody').innerText()).includes(literal));
        assert.equal(await page.locator('[data-live-table] img, [data-live-table] iframe, [data-table-results] script').count(), 0);
        assert.equal(await page.evaluate(() => window.liveTableXss), undefined);
        await meta(page, { filtered: 1, total: 63 });
      };
      await safe();
      await update(page, () => page.locator('[data-table-filters] button[type=submit]').click());
      assertQuery(requests.at(-1), table, { q: literal });
      await safe();
      await page.reload(); await settled(page); await safe();
      const sqlLiteral = "' OR 1=1 --";
      await update(page, () => page.locator(table.search).fill(sqlLiteral));
      assertQuery(requests.at(-1), table, { q: sqlLiteral });
      await meta(page, { filtered: 0 }); // Literal fixture matching, not proof of SQL safety.
    });

    test(`${table.kind}: native GET submit and pagination work without JavaScript`, async t => {
      const page = await pageFor(t, table, { javaScriptEnabled: false });
      assert.equal(await page.locator('script[src$="/live-table.js"]').count(), 1);
      await page.locator(table.search).fill('record');
      await page.locator(table.size).selectOption('25');
      await Promise.all([page.waitForNavigation(), page.locator('[data-table-filters] button[type=submit]').click()]);
      assertQuery(requests.at(-1), table, { q: 'record', page: undefined }, false);
      await meta(page, { page: 1, filtered: 62 });
      for (const expected of [2, 3]) {
        await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: 'Next', exact: true }).click()]);
        assertQuery(requests.at(-1), table, { q: 'record', page: String(expected) }, false);
      }
      assert.equal(await rows(page), 12);
      assert.match(await page.locator('tbody').innerText(), /record60/);
      await page.locator(table.filter).selectOption(table.choice);
      await Promise.all([page.waitForNavigation(), page.locator('[data-table-filters] button[type=submit]').click()]);
      assertQuery(requests.at(-1), table, { q: 'record', [table.field]: table.choice, page: undefined }, false);
      await meta(page, { page: 1, filtered: table.kind === 'patients' ? 21 : 25 });
      const nativeRows = await page.locator('tbody').textContent();
      const response = await page.request.get(origin + table.url + '?' + new URLSearchParams(params(table, { q: 'record', [table.field]: table.choice })));
      assert.equal(response.status(), 200);
      const html = await response.text();
      assert.match(html, /^<div data-table-content>/);
      assert.doesNotMatch(html, /<!DOCTYPE|<html|<header|<script/i);
      const fragmentText = await page.evaluate(html => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.querySelector('tbody').textContent.replace(/\s+/g, ' ').trim();
      }, html);
      assert.equal(fragmentText, nativeRows.replace(/\s+/g, ' ').trim());
    });
  }

  test('inventory: delegated real modal links survive result replacement; capabilities remain gated', async t => {
    const table = tables[1];
    const page = await pageFor(t, table);
    await next(page);
    const link = page.locator('[data-table-results] a[data-app-modal-open]').filter({ hasText: 'Receive' }).first();
    const destination = await link.getAttribute('href');
    const responsePromise = page.waitForResponse(response => response.url().startsWith(destination + '?') && response.url().includes('fragment=1'));
    await link.click();
    const response = await responsePromise;
    const html = await response.text();
    assert.doesNotMatch(html, /<!DOCTYPE|<html|<header|<script/i);
    await page.waitForFunction(() => {
      const modal = document.querySelector('#appFormModal');
      return modal?.querySelector('form') && modal.getAttribute('aria-modal') === 'true' &&
        bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
    });
    assert.match(await page.locator('#appFormModalTitle').innerText(), /Receive Medicine/);
    assert.equal(await page.locator('#appFormModal input[name=batch_number]').count(), 1);
    await page.locator('#appFormModal button[data-app-modal-close]').click();
    await page.locator('#appFormModal').waitFor({ state: 'detached' });
    assert.equal(new URL(page.url()).searchParams.get('page'), '2');
    for (const profile of ['staff', 'reader']) {
      const inventory = await pageFor(t, table, { profile });
      await next(inventory);
      assert.equal(await inventory.locator('[data-table-results] a[data-app-modal-open]').filter({ hasText: 'Edit' }).count(), 0);
      assert.equal(await inventory.locator('[data-table-results] a[data-app-modal-open]').filter({ hasText: 'Receive' }).count() > 0, profile === 'staff');
      const patients = await pageFor(t, tables[0], { profile });
      await next(patients);
      assert.equal(await patients.getByRole('button', { name: /Start Visit/ }).count(), profile === 'staff' ? 25 : 0);
    }
  });
});
