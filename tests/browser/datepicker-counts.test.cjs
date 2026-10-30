'use strict';
// Production scripts/CSS in Chromium; no backend or database writes.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') return res.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><html lang="en-GB"><head>
      <meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,">
      <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
      <link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/datepicker.css">
      </head><body><main style="padding:16px"><section data-live-filters id="owner"><form class="d-flex flex-wrap gap-2" data-filter-form>
      <input id="appointmentDateFilter" class="form-control form-control-sm" data-datepicker data-target="#date" data-datepicker-counts-url="/${url.searchParams.get('unit') === 'guidance' ? 'guidance' : 'clinic'}/appointments/calendar-counts">
      <input type="hidden" id="date" name="date" value="2030-01-15"><button type="submit">Apply</button></form></section>
      <section id="other"></section><input id="generic" data-datepicker value="2030-01-15"><button id="outside">Outside</button></main>
      <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="/assets/js/custom-dropdown.js"></script><script src="/assets/js/datepicker.js"></script></body></html>`);
    const file = path.join(root, 'public', url.pathname);
    if (file.startsWith(path.join(root, 'public')) && fs.existsSync(file) && fs.statSync(file).isFile()) {
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : 'text/css' });
      return fs.createReadStream(file).pipe(res);
    }
    res.writeHead(404).end();
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(fs.existsSync);
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => {
  await browser?.close();
  server?.closeAllConnections();
  await new Promise(resolve => server.close(resolve));
});
const popup = page => page.locator('.dp-has-counts');
async function ready(page) {
  await page.waitForFunction(() => document.querySelector('.dp-counts-status')?.textContent === 'Scheduled appointment counts shown.');
}
async function hint(page, id = 'owner') {
  await page.evaluate(id => document.dispatchEvent(new CustomEvent('synapse:filter-updated', { detail: { root: document.getElementById(id) } })), id);
}

for (const unit of ['clinic', 'guidance']) test(`${unit}: opt-in scope, request contract, counts, deduplication and reopening`, { timeout: 15000 }, async () => {
  const page = await browser.newPage({ reducedMotion: 'reduce' });
  try {
    const requests = [];
    await page.addInitScript(() => {
      window.fetchCalls = [];
      const original = window.fetch;
      window.fetch = (url, options) => { window.fetchCalls.push({ url, cache: options.cache, credentials: options.credentials }); return original(url, options); };
    });
    await page.route('**/calendar-counts?*', route => {
      requests.push(new URL(route.request().url()));
      return route.fulfill({ json: { success: true, data: { counts: { '2030-01-15': 123, '2030-01-16': 1 } } } });
    });
    await page.goto(`${origin}/fixture?unit=${unit}`);
    await page.click('#generic');
    assert.equal(requests.length, 0);
    assert.equal(await page.locator('.dp-popup:not([hidden]) .dp-counts-status').count(), 0);
    await page.click('#appointmentDateFilter');
    await ready(page);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].pathname, `/${unit}/appointments/calendar-counts`);
    assert.equal(requests[0].searchParams.get('from'), '2029-12-31');
    assert.equal(requests[0].searchParams.get('to'), '2030-02-10');
    assert.deepEqual(await page.evaluate(() => ({ cache: fetchCalls[0].cache, credentials: fetchCalls[0].credentials })), { cache: 'no-store', credentials: 'same-origin' });
    const day = popup(page).locator('[data-date="2030-01-15"]');
    assert.equal(await day.locator('.dp-count-badge').textContent(), '99+');
    assert.match(await day.getAttribute('aria-label'), /123 scheduled appointments/);
    assert.match(await popup(page).locator('[data-date="2030-01-16"]').getAttribute('aria-label'), /1 scheduled appointment$/);
    assert.equal(await popup(page).locator('.dp-count-badge').count(), 2);
    assert.match(await popup(page).locator('[data-date="2030-01-17"]').getAttribute('aria-label'), /0 scheduled appointments/);
    await day.press('ArrowRight');
    await hint(page, 'other');
    await page.waitForTimeout(300);
    assert.equal(requests.length, 1);
    await hint(page); await hint(page); await hint(page);
    await page.waitForTimeout(400); await ready(page);
    assert.equal(requests.length, 2);
    await popup(page).press('Escape');
    await page.click('#appointmentDateFilter'); await ready(page);
    assert.equal(requests.length, 3);
  } finally { await page.close(); }
});

test('Navigation rejects stale responses, failures do not block selecting, teardown aborts', { timeout: 20000 }, async () => {
  const page = await browser.newPage({ reducedMotion: 'reduce' });
  try {
    // Model a transport that completes even after AbortController.abort().
    await page.addInitScript(() => {
      window.pendingCounts = [];
      window.fetch = (url, options) => new Promise(resolve => pendingCounts.push({ url, signal: options.signal, resolve: (body, ok = true) => resolve({ ok, json: async () => body }) }));
    });
    await page.goto(`${origin}/fixture`); await page.click('#appointmentDateFilter');
    assert.match(await popup(page).locator('[data-date="2030-01-15"]').getAttribute('aria-label'), /unavailable/);
    await popup(page).locator('[data-dp-next]').click();
    assert.equal(await page.evaluate(() => pendingCounts[0].signal.aborted), true);
    await page.evaluate(() => pendingCounts[1].resolve({ success: true, data: { counts: { '2030-02-15': 7 } } }));
    await ready(page);
    await page.evaluate(() => pendingCounts[0].resolve({ success: true, data: { counts: { '2030-02-15': 99 } } }));
    assert.equal(await popup(page).locator('.dp-count-badge').textContent(), '7');
    await popup(page).locator('select.dp-month').selectOption('2', { force: true });
    await popup(page).locator('select.dp-year').selectOption('2031', { force: true });
    assert.equal(await page.evaluate(() => pendingCounts.length), 4);
    assert.equal(await page.evaluate(() => pendingCounts[2].signal.aborted), true);
    await page.evaluate(() => pendingCounts[3].resolve({}, false));
    await page.waitForFunction(() => document.querySelector('.dp-counts-status').textContent.includes('You can still'));
    assert.equal(await popup(page).locator('.dp-count-badge').count(), 0);
    assert.match(await popup(page).locator('[data-date="2031-03-15"]').getAttribute('aria-label'), /unavailable/);
    await popup(page).locator('[data-date="2031-03-15"]').click();
    assert.equal(await page.locator('#date').inputValue(), '2031-03-15');
    await page.click('#appointmentDateFilter');
    await page.evaluate(() => document.querySelector('#owner').remove());
    await page.waitForFunction(() => !document.querySelector('.dp-has-counts'));
    assert.equal(await page.evaluate(() => pendingCounts[4].signal.aborted), true);
    await page.evaluate(() => pendingCounts[4].resolve({ success: true, data: { counts: {} } }));
    assert.equal(await page.locator('.dp-has-counts').count(), 0);
  } finally { await page.close(); }
});

for (const theme of ['light', 'dark']) test(`Mobile ${theme}: badges, dates and popup stay in bounds`, { timeout: 15000 }, async () => {
  const page = await browser.newPage({ viewport: { width: 320, height: 640 }, reducedMotion: 'reduce' });
  try {
    await page.route('**/calendar-counts?*', route => route.fulfill({ json: { success: true, data: { counts: { '2030-01-15': 200 } } } }));
    await page.goto(`${origin}/fixture`);
    await page.locator('html').evaluate((el, theme) => el.dataset.theme = theme, theme);
    await page.click('#appointmentDateFilter'); await ready(page);
    const geometry = await popup(page).evaluate(el => {
      const r = el.getBoundingClientRect();
      return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, overflow: el.scrollWidth > el.clientWidth,
        badgeFits: [...el.querySelectorAll('.dp-count-badge')].every(badge => {
          const b = badge.getBoundingClientRect(), c = badge.parentNode.getBoundingClientRect(), day = badge.parentNode.querySelector('.dp-day').getBoundingClientRect();
          return b.left >= c.left && b.right <= c.right && b.bottom <= c.bottom && b.top >= c.top && b.top < day.bottom;
        }) };
    });
    assert.ok(geometry.left >= 7 && geometry.right <= 313 && geometry.top >= 7 && geometry.bottom <= 633, JSON.stringify(geometry));
    assert.equal(geometry.overflow, false); assert.equal(geometry.badgeFits, true);
    assert.equal(await page.locator('#appointmentDateFilter').evaluate(el => el.scrollWidth <= el.clientWidth), true);
    assert.deepEqual(await popup(page).locator('.dp-count-badge').evaluate(el => ({ color: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor })), { color: 'rgb(255, 255, 255)', background: 'rgb(231, 76, 99)' });
  } finally { await page.close(); }
});

test('Appointment view configures the unit endpoint and preserves filter controls', () => {
  const view = fs.readFileSync(path.join(root, 'app/Views/appointments/index.php'), 'utf8');
  assert.match(view, /data-datepicker-counts-url="<\?= esc\(site_url\('\/' \. \$unit \. '\/appointments\/calendar-counts'\)\) \?>"/);
  assert.match(view, /badge bg-light text-muted border/);
  for (const name of ['date', 'pending_page', 'scheduled_page', 'per_page']) assert.ok(view.includes(`name="${name}"`));
});
