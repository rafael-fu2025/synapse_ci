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
const { chromium } = require('playwright');
const publicRoot = path.join(root, 'public');
const rendered = new Map();
const requests = [], serverErrors = [];
const scope = '[data-filter-key="personal-visit-history"]';
const rows = `${scope} [data-visit-row]`;
const history = `${scope} [data-filter-region="history"]`;
const columns = ['Visit date', 'Service', 'Attending staff', 'Attendance', 'Summary'];
const workspacePermissions = ['portal.records.read', 'portal.appointments.read'];
const diagnostics = new WeakMap();
let browser, server, origin, contextSequence = 0;

// The tracked test has no dependency on writable preview tools. Reuse both existing
// fixtures; only workspace visits are re-rendered with the exact /portal actor.
// Real hydration and live filters compare the full serialized navigation scope.
const workspaceAdapter = String.raw`
  $root = $argv[1]; $mode = $argv[2];
  error_reporting(E_ALL); ini_set('display_errors', '1');
  ob_start();
  require $root . '/tests/_support/render-' . ($mode === 'portal' ? 'portal-workspace' : 'visit-history') . '-fixture.php';
  $html = (string) ob_get_clean();
  if ($mode === 'visits') {
    $data = array_intersect_key(service('renderer')->getData(), array_flip([
      'title', 'pageTitle', 'baseUrl', 'breadcrumbs', 'visits', 'filters', 'pagination',
    ]));
    session()->set(['user' => $fixture['workspaceActor'], 'tenant_id' => $fixture['workspaceActor']['tenant_id']]);
    $html = view('portal/visit_history', $data);
  }
  echo json_encode(['html' => $html, 'actor' => session()->get('user')], JSON_THROW_ON_ERROR);
`;

function render(fixture, mode = 'standalone') {
  const key = JSON.stringify({ mode, ...fixture });
  if (!rendered.has(key)) {
    const args = mode === 'standalone' ? [path.join(root, 'tests/_support/render-visit-history-fixture.php')]
      : ['-r', workspaceAdapter, root, mode];
    const result = spawnSync('php', args, { cwd: root, input: JSON.stringify(fixture), encoding: 'utf8', timeout: 20_000, maxBuffer: 4 * 1024 * 1024 });
    assert.equal(result.error, undefined, String(result.error));
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(result.stderr.trim(), '', 'Fixture PHP must not emit diagnostics');
    const payload = mode === 'standalone' ? { html: result.stdout } : JSON.parse(result.stdout);
    assert.match(payload.html, /<!doctype html>/i, 'Render the complete production layout');
    assert.doesNotMatch(payload.html, /(?:Fatal error|Parse error|Warning|Deprecated|Notice):/);
    rendered.set(key, payload);
  }
  return rendered.get(key);
}

before(async () => {
  let executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || chromium.executablePath();
  if (!fs.existsSync(executablePath) && !process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) {
    const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || path.join(process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData/Local'), 'ms-playwright');
    executablePath = fs.existsSync(cache) && fs.readdirSync(cache).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .flatMap(n => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe', 'chrome-linux/chrome']
        .map(relative => path.join(cache, n, relative))).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath && fs.existsSync(executablePath), 'Install Playwright Chromium before running this test; no runtime downloads');
  server = http.createServer((req, res) => {
    try {
      const url = new URL(req.url, origin);
      const cookie = name => (req.headers.cookie || '').split(';').map(item => item.trim())
        .find(item => item.startsWith(name + '='))?.slice(name.length + 1);
      const scenario = cookie('visit-scenario') || 'normal';
      const workspace = cookie('visit-workspace') === '1';
      const entry = { url: req.url, pathname: url.pathname, method: req.method, headers: req.headers,
        ajax: req.headers['x-requested-with'], context: cookie('visit-context'), workspace };
      requests.push(entry);
      const send = (status, type, body) => {
        entry.status = status;
        res.writeHead(status, { 'Content-Type': type, 'Cache-Control': 'private, no-store' }).end(body);
      };
      if (req.method === 'POST' && url.pathname === '/realtime/ticket') {
        send(200, 'application/json', JSON.stringify({ enabled: false })); return;
      }
      if (req.method !== 'GET') { send(405, 'text/plain', 'Synthetic receiver is read-only'); return; }
      if (url.pathname === '/portal/visits' || url.pathname === '/portal') {
        if (url.pathname === '/portal' && !workspace) {
          send(403, 'text/html; charset=utf-8', '<!doctype html><title>Access denied</title><h1>Access denied</h1><p>Permission required: portal.appointments.read</p>');
          return;
        }
        const shell = workspace ? render({ origin, path: '/portal', kind: 'student',
          permissions: workspacePermissions, csrfHash: '6ea9b507c8d140acbf90275d3318ce60' }, 'portal') : null;
        const payload = url.pathname === '/portal' ? shell : render({ origin, path: url.pathname + url.search, scenario,
          ...(shell ? { workspaceActor: shell.actor } : {}) }, workspace ? 'visits' : 'standalone');
        if (payload.actor) entry.permissions = payload.actor.permissions;
        send(200, 'text/html; charset=utf-8', payload.html); return;
      }
      if (url.pathname === '/notifications') {
        send(200, 'application/json', JSON.stringify({ items: [], unread_count: 0 })); return;
      }
      if (url.pathname === '/portal/queue' && workspace) {
        send(200, 'application/json', JSON.stringify({ date: '2026-11-21', tickets: [] })); return;
      }
      if (!url.pathname.startsWith('/assets/') && !/^\/favicon(?:-\d+\.png|\.ico)$/.test(url.pathname)) {
        send(404, 'text/plain', 'Unexpected synthetic route'); return;
      }
      const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        send(404, 'text/plain', 'Static asset not found'); return;
      }
      const mime = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml',
        '.png': 'image/png', '.webp': 'image/webp', '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2' };
      send(200, mime[path.extname(file)] || 'application/octet-stream', fs.readFileSync(file));
    } catch (error) {
      serverErrors.push(error.stack || String(error));
      if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end(String(error));
    }
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
  assert.deepEqual(serverErrors, [], 'Only isolated fixture rendering and explicit synthetic routes are used');
});

async function open(t, { width = 1280, theme = 'light', scenario = 'normal', query = '', timezoneId = 'America/Los_Angeles', workspace = false } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 800 }, colorScheme: theme,
    reducedMotion: 'reduce', timezoneId, serviceWorkers: 'block' });
  const id = String(++contextSequence);
  // Only the known remote-font asset is fulfilled locally. Any other off-origin request
  // is a test failure, never a broad 200/empty fallback that could hide missing APIs.
  const errors = [], consoleErrors = [], httpErrors = [], failedRequests = [], externalRequests = [], documents = [];
  await context.route('**/*', route => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.origin === origin) return route.continue();
    if (requestUrl.origin === 'https://fonts.googleapis.com' && requestUrl.pathname === '/css2' && route.request().resourceType() === 'stylesheet') {
      return route.fulfill({ status: 200, contentType: 'text/css', body: '/* Offline fixture: production local fallback fonts. */' });
    }
    externalRequests.push(requestUrl.href);
    return route.abort();
  });
  await context.addCookies([
    { name: 'visit-scenario', value: scenario, url: origin },
    { name: 'visit-workspace', value: workspace ? '1' : '0', url: origin },
    { name: 'visit-context', value: id, url: origin },
  ]);
  await context.addInitScript(dark => {
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    window.visitUpdates = 0;
    document.addEventListener('synapse:filter-updated', event => {
      if (event.detail?.root?.dataset.filterKey === 'personal-visit-history') ++window.visitUpdates;
    });
  }, theme === 'dark');
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  page.setDefaultNavigationTimeout(20_000);
  const expectedShellDenial = (status, value) => !workspace && status === 403 && new URL(value).origin === origin && new URL(value).pathname === '/portal';
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() !== 'error') return;
    const location = message.location().url;
    if (location && expectedShellDenial(403, location) && /Failed to load resource:.*\b403\b/.test(message.text())) return;
    consoleErrors.push({ text: message.text(), url: location });
  });
  page.on('response', response => {
    if (response.status() >= 400 && !expectedShellDenial(response.status(), response.url())) httpErrors.push({ status: response.status(), url: response.url() });
  });
  page.on('requestfailed', request => failedRequests.push({ url: request.url(), failure: request.failure()?.errorText }));
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
  });
  diagnostics.set(page, { id, documents });
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, [], 'Production scripts must have no uncaught page errors');
    assert.deepEqual(consoleErrors, [], 'No console errors except the explicit independently authorized optional-shell 403');
    assert.deepEqual(httpErrors, [], 'Every local HTTP response is successful except the explicit standalone shell denial');
    assert.deepEqual(failedRequests, [], 'No requests fail in the synthetic browser context');
    assert.deepEqual(externalRequests, [], 'No external network calls or broad route fallbacks');
  });
  const response = await page.goto(`${origin}/portal/visits${query}`);
  assert.equal(response.status(), 200, await response.text());
  await page.waitForFunction(workspace => {
    const main = document.getElementById('main'), pane = document.getElementById('portal-workspace-pane');
    return window.SynapseLiveFilters && window.SynapsePortalWorkspace &&
      !document.documentElement.classList.contains('portal-shell-hydrating') &&
      main?.isConnected && getComputedStyle(main).opacity === '1' &&
      !document.body.classList.contains('is-navigating') &&
      (workspace ? pane?.dataset.activePane === 'visits' && pane.dataset.currentPaneUrl === location.href && pane.getAttribute('aria-busy') !== 'true' : !pane);
  }, workspace);
  await page.evaluate(() => document.fonts.ready);
  const title = workspace ? '#portal-workspace-pane .portal-workspace-title' : '#main h1';
  assert.equal((await page.locator(title).textContent()).trim(), 'My Visit History');
  assert.equal(await page.locator('body.app-shell #app-sidebar').count(), 1);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  assert.equal(await page.locator('script[src$="/live-filters.js"]').count(), 1);
  assert.equal(await page.locator(`${scope}.pv-workspace.portal-engagement .pv-sheet`).count(), 1);
  if (!workspace) {
    const shellRead = requests.find(read => read.context === id && read.pathname === '/portal');
    assert.equal(shellRead?.status, 403, 'Records-only visit access must not accidentally gain the appointments shell');
    assert.equal(await page.evaluate(() => window.SynapsePortalWorkspace.isActive()), false);
  }
  await page.evaluate(selector => {
    window.visitDocument = document;
    window.visitFilterForm = document.querySelector(selector + ' [data-filter-form]');
  }, scope);
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
  assert.equal(await page.evaluate(selector => document === window.visitDocument && document.querySelector(selector + ' [data-filter-form]') === window.visitFilterForm, scope), true,
    'Filtering preserves both document and filter controls');
  assert.equal(await page.locator(`${scope} [data-filter-error]`).isVisible(), false);
  assert.equal(result.request().method(), 'GET', 'Filter, page and Reset remain GET-only reads');
  assert.equal((await result.request().allHeaders())['x-requested-with'], 'XMLHttpRequest', 'Real shared live filters fetch the view');
  const visitReads = requests.filter(read => read.context === diagnostics.get(page).id && read.pathname === '/portal/visits');
  assert.equal(visitReads.at(-1).ajax, 'XMLHttpRequest');
  assert.equal(diagnostics.get(page).documents.length, 1, 'Live filtering must not replace the document with full navigation');
}

async function count(page, expected) {
  assert.equal(await page.locator(rows).count(), expected);
}

async function pagination(page, { from, to, total, pageNumber = 1 }) {
  const pagination = page.locator(`${history} [data-table-pagination]`);
  assert.equal(await pagination.count(), 1);
  assert.equal((await pagination.locator(':scope > p').textContent()).replace(/\s+/g, ' ').trim(), `Showing ${from}–${to} of ${total}`,
    'The shared numeric pagination contract has no view-specific visits suffix');
  for (const [attribute, expected] of Object.entries({ 'data-page': pageNumber, 'data-per-page': 25, 'data-filtered': total, 'data-total': total })) {
    assert.equal(await pagination.getAttribute(attribute), String(expected));
  }
  assert.equal((await page.locator(`${history} [data-visit-count]`).textContent()).trim(), `${total} completed visits`,
    'The refreshed result header uses the total, not the current page row count');
}

async function ledger(page) {
  const table = page.locator(`${history} .pv-table-wrap > table.pv-table`);
  assert.equal(await table.count(), 1);
  assert.deepEqual((await table.locator('thead th').allTextContents()).map(text => text.trim()), columns);
  for (const header of await table.locator('thead th').all()) assert.equal(await header.getAttribute('scope'), 'col');
  assert.match(await table.locator('caption').textContent(), /completed visits, newest first/i);
  const first = page.locator(rows).first();
  assert.equal(await first.locator(':scope > td').count(), 5, 'Keep the original five-column semantic table order');
  assert.deepEqual(await first.locator(':scope > td').evaluateAll(cells => cells.map(cell => cell.dataset.label)), columns);
  assert.deepEqual((await first.locator('.pv-cell-label').allTextContents()).map(text => text.trim()), columns);
  assert.equal(await page.locator(`${rows} .pv-cell-label:not([aria-hidden="true"])`).count(), 0,
    'Narrow-container repeated labels do not duplicate accessible column names');
  for (const selector of ['td.pv-visit-date', 'td.pv-service', 'td .pv-staff-name', 'td.pv-attendance', 'td.pv-summary']) {
    assert.equal(await first.locator(selector).count(), 1);
  }
  assert.equal(await first.locator('.pv-service svg[aria-hidden="true"]').count(), 1, 'The service icon is decorative SVG');
  assert.equal(await page.locator(`${rows} a`).count(), 0, 'Attendance entries never link to clinical records');
}

async function overflow(page) {
  const documentBox = await page.evaluate(() => ({ width: document.documentElement.clientWidth,
    scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) }));
  assert.ok(documentBox.scrollWidth <= documentBox.width, `Document horizontal overflow: ${JSON.stringify(documentBox)}`);
  for (const selector of [`${scope}.pv-workspace`, `${scope} .pv-sheet`, `${scope} .pv-table-wrap`]) {
    const box = await page.locator(selector).evaluate(el => ({ width: el.clientWidth, scrollWidth: el.scrollWidth }));
    assert.ok(box.scrollWidth <= box.width + 1, `Ledger horizontal overflow at ${selector}: ${JSON.stringify(box)}`);
  }
}

async function responsiveLedger(page, expectedDisplay) {
  await ledger(page);
  const width = await page.locator(`${scope}.pv-workspace`).evaluate(el => el.clientWidth);
  assert.ok(expectedDisplay === 'grid' ? width < 800 : width >= 800, `Test must exercise a ${expectedDisplay} container, measured ${width}px`);
  assert.deepEqual(await page.locator(rows).evaluateAll(items => [...new Set(items.map(el => getComputedStyle(el).display))]), [expectedDisplay],
    `All visit rows use ${expectedDisplay} at a ${width}px container`);
  await overflow(page);
  return width;
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
  await pagination(page, { from: 1, to: 25, total: 30 });
  await ledger(page);
  const first = page.locator(rows).first();
  assert.equal((await first.locator('.pv-staff-name').textContent()).trim(), '<img src=x onerror="window.visitHistoryXss=1"> Dr. A & B <script>window.visitHistoryXss=1</script>');
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
  await pagination(page, { from: 26, to: 27, total: 27, pageNumber: 2 });
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
  for (const cell of await page.locator(`${rows} .pv-service-name`).all()) assert.equal((await cell.textContent()).trim(), 'Guidance & Counseling');
  await pagination(page, { from: 1, to: 3, total: 3 });
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
    await pagination(page, { from: 0, to: 0, total: 0 });
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
    assert.equal((await page.locator(rows).first().locator('.pv-started').textContent()).trim(), 'Sep 30, 2026 · 12:15 AM');
    assert.equal((await page.locator(rows).first().locator('.pv-finished').textContent()).trim(), 'Completed: Sep 30, 2026 · 12:45 AM');
    for (const index of [1, 2]) {
      assert.equal((await page.locator(rows).nth(index).locator('.pv-started').textContent()).trim(), 'Not recorded');
      assert.equal((await page.locator(rows).nth(index).locator('.pv-finished').textContent()).trim(), 'Completed: Not recorded');
    }
    assert.match(await page.locator(`${history} .pv-timezone`).textContent(), /Asia\/Manila/);
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
      await responsiveLedger(page, 'grid');
      await update(page, () => page.locator('#visitHistoryService').selectOption('guidance'));
      await responsiveLedger(page, 'grid');
      await page.locator(`${scope} [data-filter-reset]`).scrollIntoViewIfNeeded();
      await update(page, () => page.locator(`${scope} [data-filter-reset]`).click());
      await responsiveLedger(page, 'grid');
    });
  }
}

for (const theme of ['light', 'dark']) {
  test(`${theme}: wide standalone table and true hydrated desktop narrow pane keep working GET filters`, { timeout: 45_000 }, async t => {
    const standalone = await open(t, { width: 1440, theme });
    const wideWidth = await responsiveLedger(standalone, 'table-row');
    const page = await open(t, { width: 1440, theme, workspace: true });
    const pane = page.locator('#portal-workspace-pane[data-portal-workspace-pane]');
    assert.equal(await pane.count(), 1, 'The production hydration path must mount the real portal shell');
    assert.equal(await pane.getAttribute('data-active-pane'), 'visits');
    assert.equal(await pane.getAttribute('data-current-pane-url'), page.url());
    assert.equal(await page.locator('#main .portal-left-rail .portal-profile-card').count(), 1, 'A real shell profile rail exists alongside the visit pane');
    assert.equal(await page.locator(`${rows} a`).count(), 0);
    const narrowWidth = await responsiveLedger(page, 'grid');
    assert.ok(narrowWidth < wideWidth, 'Same desktop viewport exercises container width, not a viewport-only breakpoint');
    await pagination(page, { from: 1, to: 25, total: 30 });
    const contextId = diagnostics.get(page).id;
    const shellReads = requests.filter(read => read.context === contextId && read.pathname === '/portal');
    assert.equal(shellReads[0]?.status, 200, 'Optional shell is authorized only in the workspace scenario');
    assert.equal(shellReads[0]?.headers['x-synapse-portal-badge-sync'], '1', 'The production shell prefetch/hydration made the request');
    assert.deepEqual(shellReads[0]?.permissions, workspacePermissions);
    const visitRead = requests.find(read => read.context === contextId && read.pathname === '/portal/visits');
    assert.deepEqual(visitRead?.permissions, workspacePermissions, 'Hydration actor and shell actor carry only the two required synthetic grants');
    assert.equal(diagnostics.get(page).documents.length, 1, 'Hydration retains the original visit document and URL');
    await page.waitForFunction(() => document.querySelector('[data-personal-queue]')?.dataset.queueLiveBound === 'true');
    if (!requests.some(read => read.context === contextId && read.pathname === '/portal/queue' && read.status === 200)) {
      await page.waitForResponse(response => new URL(response.url()).pathname === '/portal/queue' && response.status() === 200);
    }
    assert.equal(await page.locator('[data-personal-queue] [data-queue-empty]').count(), 1);
    assert.equal(await page.locator('[data-personal-queue] [data-queue-feedback]').isVisible(), false);
    await page.waitForFunction(() => document.querySelector('[data-notification-count]')?.textContent === '0 unread');
    assert.equal(await page.locator('[data-notification-count]').textContent(), '0 unread', 'The shell consumes a valid empty notification payload');
    await page.evaluate(() => { window.visitShell = [document.getElementById('main'), document.getElementById('portal-workspace-pane'), document.querySelector('.portal-profile-card')]; });
    for (const [action, expected] of [
      [() => page.locator('#visitHistoryService').selectOption('clinic'), { from: 1, to: 25, total: 27 }],
      [() => page.locator(`${scope} a[data-filter-link]`).filter({ hasText: 'Next' }).click(), { from: 26, to: 27, total: 27, pageNumber: 2 }],
      [() => page.locator('#visitHistoryService').selectOption('guidance'), { from: 1, to: 3, total: 3 }],
      [() => page.locator(`${scope} [data-filter-form]`).getByRole('button', { name: 'Filter', exact: true }).click(), { from: 1, to: 3, total: 3 }],
      [() => page.locator(`${scope} [data-filter-reset]`).click(), { from: 1, to: 25, total: 30 }],
    ]) {
      await update(page, action);
      await pagination(page, expected);
      await count(page, expected.to - expected.from + 1);
      await responsiveLedger(page, 'grid');
      assert.equal(await page.evaluate(() => window.visitShell.every((el, index) => el === [document.getElementById('main'), document.getElementById('portal-workspace-pane'), document.querySelector('.portal-profile-card')][index])), true,
        'Filtering never replaces the mounted shell, pane, or profile rail');
    }
    assert.equal(await page.locator('#visitHistoryService').inputValue(), 'all');
    assert.equal(currentQuery(page).has('page'), false);
  });
}
