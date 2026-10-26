'use strict';

// Production PHP view, full app shell and real public assets; no controller or database.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const publicRoot = path.join(root, 'public');
const rendered = new Map();
const requests = [];
const scope = '[data-filter-key="personal-visit-history"]';
const rows = `${scope} [data-visit-row]`;
let browser, server, origin;

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) && fs.readdirSync(cache).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(cache, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath, 'Install Playwright Chromium before running this test');
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/portal/visits') {
      requests.push({ url: req.url, ajax: req.headers['x-requested-with'] });
      const scenario = /(?:^|;\s*)visit-scenario=([a-z-]+)/.exec(req.headers.cookie || '')?.[1] || 'normal';
      const key = JSON.stringify({ origin, path: url.pathname + url.search, scenario });
      if (!rendered.has(key)) {
        const result = spawnSync('php', ['tests/_support/render-visit-history-fixture.php'], {
          cwd: root, input: key, encoding: 'utf8', timeout: 15_000
        });
        if (result.error || result.status !== 0 || /(?:Fatal error|Warning|Deprecated):/.test(result.stdout)) {
          res.writeHead(500, { 'Content-Type': 'text/plain' });
          res.end(`PHP fixture failed: ${result.error || result.stderr}\n${result.stdout}`);
          return;
        }
        rendered.set(key, result.stdout);
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'private, no-store' });
      res.end(rendered.get(key));
      return;
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml',
        '.png': 'image/png', '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath });
}, { timeout: 30_000 });

after(async () => {
  try { if (browser) await browser.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
});

async function open(t, { width = 1280, theme = 'light', scenario = 'normal', query = '', timezoneId = 'America/Los_Angeles' } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 800 }, colorScheme: theme,
    reducedMotion: 'reduce', timezoneId });
  t.after(() => context.close());
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  await context.addCookies([{ name: 'visit-scenario', value: scenario, url: origin }]);
  await context.addInitScript(dark => localStorage.setItem('na-dark-mode', dark ? '1' : '0'), theme === 'dark');
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(() => assert.deepEqual(errors, [], 'Production scripts initialize and filter without errors'));
  const response = await page.goto(`${origin}/portal/visits${query}`);
  assert.equal(response.status(), 200, await response.text());
  await page.evaluate(() => document.fonts.ready);
  assert.equal(await page.locator('#main h1').textContent(), 'My Visit History');
  assert.equal(await page.locator('body.app-shell #app-sidebar').count(), 1);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  assert.equal(await page.locator('script[src$="/live-filters.js"]').count(), 1);
  await page.waitForFunction(() => Boolean(window.SynapseLiveFilters));
  await page.evaluate(() => {
    window.visitDocument = document;
    window.visitFilterForm = document.querySelector('[data-filter-form]');
    window.visitUpdates = 0;
    document.addEventListener('synapse:filter-updated', () => window.visitUpdates++);
  });
  return page;
}

async function update(page, action) {
  const before = await page.evaluate(() => window.visitUpdates);
  const response = page.waitForResponse(r => new URL(r.url()).pathname === '/portal/visits' && r.request().resourceType() === 'fetch');
  await action();
  const result = await response;
  assert.equal(result.status(), 200);
  assert.match(await result.text(), /<!DOCTYPE html>/i, 'AJAX returns the complete production layout');
  await page.waitForFunction(n => window.visitUpdates > n, before);
  assert.equal(await page.evaluate(() => document === window.visitDocument && document.querySelector('[data-filter-form]') === window.visitFilterForm), true,
    'Filtering preserves both document and filter controls');
  assert.equal(await page.locator(`${scope} [data-filter-error]`).isVisible(), false);
  assert.equal(requests.at(-1).ajax, 'XMLHttpRequest', 'Real shared live filters fetch the view');
}

async function count(page, expected) {
  assert.equal(await page.locator(rows).count(), expected);
}

function currentQuery(page) { return new URL(page.url()).searchParams; }

test('actual view exposes GET filters, safe attendance, escaped staff and portal navigation', { timeout: 25_000 }, async t => {
  const page = await open(t);
  assert.match(await page.title(), /^My Visit History - /);
  const form = page.locator(`${scope} [data-filter-form]`);
  assert.equal(await form.getAttribute('method'), 'get');
  assert.equal(new URL(await form.getAttribute('action')).pathname, '/portal/visits');
  assert.equal(await page.locator(`${scope}[data-live-filters] [data-filter-region="history"]`).count(), 1);
  await count(page, 25);
  assert.match(await page.locator(scope).textContent(), /Showing 1–25 of 30 visits/);
  const first = page.locator(rows).first();
  assert.equal(await first.locator('td').nth(2).textContent(), '<img src=x onerror="window.visitHistoryXss=1"> Dr. A & B <script>window.visitHistoryXss=1</script>');
  assert.equal(await first.locator('img, script').count(), 0);
  assert.equal(await page.evaluate(() => window.visitHistoryXss), undefined);
  assert.match(await first.textContent(), /Walk-in/);
  assert.match(await page.locator(rows).nth(1).textContent(), /By appointment/);
  assert.equal(await page.locator(`${rows} a`).count(), 0, 'Attendance entries never link to staff charts or clinical records');
  assert.match(await page.locator(scope).textContent(), /Confidential medical and counseling notes are not displayed/);
  const links = page.locator('#main a').filter({ hasText: 'My Health Portal' });
  assert.ok(await links.count() >= 2, 'Page action and breadcrumb return to personal portal');
  for (const link of await links.all()) assert.equal(new URL(await link.getAttribute('href')).pathname, '/portal');
  const sidebar = page.locator('#app-sidebar a').filter({ hasText: 'My Visit History' });
  assert.equal(await sidebar.count(), 1);
  assert.match(await sidebar.getAttribute('class'), /active/);
  assert.equal(new URL(await sidebar.getAttribute('href')).pathname, '/portal/visits');
});

test('live service changes, pagination, submit and Reset preserve document and correct query', { timeout: 30_000 }, async t => {
  const page = await open(t);
  await update(page, () => page.locator('#visitHistoryService').selectOption('clinic'));
  await count(page, 25);
  assert.equal(currentQuery(page).get('service'), 'clinic');
  assert.equal(currentQuery(page).has('page'), false);
  const next = page.locator(`${scope} a[data-filter-link]`).filter({ hasText: 'Next' });
  const nextUrl = new URL(await next.getAttribute('href'));
  assert.equal(nextUrl.searchParams.get('service'), 'clinic');
  assert.equal(nextUrl.searchParams.get('page'), '2');
  await update(page, () => next.click());
  await count(page, 2);
  assert.match(await page.locator(scope).textContent(), /Showing 26–27 of 27 visits/);
  assert.equal(currentQuery(page).get('service'), 'clinic');
  assert.equal(currentQuery(page).get('page'), '2');
  const previous = page.locator(`${scope} a[data-filter-link]`).filter({ hasText: 'Previous' });
  assert.equal(new URL(await previous.getAttribute('href')).searchParams.get('service'), 'clinic');
  await update(page, () => previous.click());
  await count(page, 25);
  await update(page, () => next.click());
  await update(page, () => page.locator('#visitHistoryService').selectOption('guidance'));
  await count(page, 3);
  assert.equal(currentQuery(page).has('page'), false, 'Changing service resets page two');
  for (const cell of await page.locator(`${rows} td:nth-child(2)`).all()) assert.equal(await cell.textContent(), 'Guidance & Counseling');
  await update(page, () => page.getByRole('button', { name: 'Filter', exact: true }).click());
  await count(page, 3);
  await update(page, () => page.locator(`${scope} [data-filter-reset]`).click());
  assert.equal(await page.locator('#visitHistoryService').inputValue(), 'all');
  assert.equal(currentQuery(page).has('page'), false);
  await count(page, 25);
  assert.equal(await page.locator(`${rows} a`).count(), 0);
});

for (const scenario of ['empty', 'clinic-only']) {
  test(`${scenario}: useful empty state and zero counts`, { timeout: 25_000 }, async t => {
    const page = await open(t, { scenario });
    if (scenario === 'clinic-only') await update(page, () => page.locator('#visitHistoryService').selectOption('guidance'));
    await count(page, 0);
    const history = page.locator(`${scope} [data-filter-region="history"]`);
    assert.match(await history.textContent(), scenario === 'empty' ? /No completed visits are recorded yet/ : /No completed visits for this service are recorded yet/);
    assert.match(await history.textContent(), /Appointment requests, cancellations, and visits still in progress are not included/);
    assert.match(await history.textContent(), /Showing 0–0 of 0 visits/);
    assert.equal(await history.locator('[data-filter-link], table').count(), 0);
    if (scenario === 'clinic-only') {
      await update(page, () => page.locator(`${scope} [data-filter-reset]`).click());
      await count(page, 25);
    }
  });
}

for (const timezoneId of ['America/Los_Angeles', 'Asia/Manila']) {
  test(`${timezoneId}: Manila-local DATETIME values do not shift and bad dates stay unrecorded`, { timeout: 25_000 }, async t => {
    const page = await open(t, { scenario: 'timestamps', timezoneId });
    assert.equal((await page.locator(rows).first().locator('td').first().textContent()).trim(), 'Sep 30, 2026 · 12:15 AMCompleted: Sep 30, 2026 · 12:45 AM');
    for (const index of [1, 2]) assert.equal((await page.locator(rows).nth(index).locator('td').first().textContent()).trim(), 'Not recordedCompleted: Not recorded');
    assert.match(await page.locator(scope).textContent(), /Asia\/Manila/);
  });
}

for (const width of [320, 390, 1024, 1280]) {
  test(`${width}px: service menu fits all options without horizontal scrolling`, async t => {
    const page = await open(t, { width });
    const dropdown = page.locator('#visitHistoryService').locator('..');
    const toggle = dropdown.locator('.bd-select-toggle');
    const menu = dropdown.locator('.bd-select-menu');
    await toggle.click();
    await menu.waitFor({ state: 'visible' });
    const geometry = await menu.evaluate(el => {
      const box = el.getBoundingClientRect();
      return { width: el.clientWidth, scrollWidth: el.scrollWidth, left: box.left, right: box.right, viewport: innerWidth };
    });
    assert.ok(geometry.scrollWidth <= geometry.width, `Menu horizontal overflow: ${JSON.stringify(geometry)}`);
    assert.ok(geometry.left >= 0 && geometry.right <= geometry.viewport, 'Menu stays in viewport');
    const option = menu.getByRole('option', { name: 'Guidance & Counseling', exact: true });
    const optionFits = await option.evaluate(el => el.scrollWidth <= el.clientWidth);
    assert.equal(optionFits, true, 'Full option label fits rather than being clipped');
    await update(page, () => option.click());
    assert.equal(currentQuery(page).get('service'), 'guidance');
    assert.equal(await menu.isVisible(), false);
    await toggle.click();
    await menu.waitFor({ state: 'visible' });
    assert.equal(await menu.evaluate(el => el.scrollWidth <= el.clientWidth), true, 'Selected label does not reintroduce scrolling');
    await page.keyboard.press('Escape');
  });
}

for (const width of [320, 390]) {
  for (const theme of ['light', 'dark']) {
    test(`${width}px ${theme}: full layout has no document horizontal overflow`, { timeout: 25_000 }, async t => {
      const page = await open(t, { width, theme });
      async function checkOverflow() {
        const box = await page.evaluate(() => ({ width: document.documentElement.clientWidth,
          scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) }));
        assert.ok(box.scrollWidth <= box.width, `Document horizontal overflow: ${JSON.stringify(box)}`);
        const table = page.locator(`${scope} .table-responsive`);
        if (await table.count()) assert.ok(['auto', 'scroll'].includes(await table.evaluate(el => getComputedStyle(el).overflowX)), 'Wide table scrolls inside its own container');
      }
      await checkOverflow();
      await update(page, () => page.locator('#visitHistoryService').selectOption('guidance'));
      await checkOverflow();
      await page.locator(`${scope} [data-filter-reset]`).scrollIntoViewIfNeeded();
      await update(page, () => page.locator(`${scope} [data-filter-reset]`).click());
      await checkOverflow();
    });
  }
}
