'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const screenshots = path.join(root, 'writable/pagination-preview');
let server, browser, origin;
const cache = new Map();

function render(url) {
  const key = url.pathname + url.search;
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', [path.join(root, 'tests/_support/render-clinic-portal-pagination-fixture.php')], {
    cwd: root, env: { ...process.env, PAGINATION_ISOLATED_TESTS: '1' },
    input: JSON.stringify({ origin, path: key, theme: url.searchParams.get('theme') }), encoding: 'utf8', timeout: 15000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error/);
  cache.set(key, result.stdout);
  return result.stdout;
}
before(async () => {
  fs.mkdirSync(screenshots, { recursive: true });
  server = http.createServer((req, res) => {
    if (req.method !== 'GET') return res.writeHead(405).end('Read-only fixture');
    const url = new URL(req.url, origin);
    if (['/clinic/patients/88', '/portal/surveys', '/portal/call-slips'].includes(url.pathname)) {
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url)); }
      catch (error) { return res.writeHead(500, { 'Content-Type': 'text/plain' }).end(error.stack); }
    }
    if (!url.pathname.startsWith('/assets/')) return res.writeHead(404).end();
    const file = path.resolve(root, 'public', '.' + url.pathname);
    if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return res.writeHead(404).end();
    res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const parent = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(parent).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(parent, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Installed Chromium is required.');
  browser = await chromium.launch({ headless: true, executablePath });
});
after(async () => {
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
async function open(t, route, width = 1440, theme = 'light') {
  const errors = [], denied = [], writes = [], dialogs = [];
  const context = await browser.newContext({ viewport: { width, height: 1000 }, serviceWorkers: 'block' });
  await context.route('**/*', route => {
    const req = route.request();
    if (req.method() !== 'GET') { writes.push(req.method()); return route.abort(); }
    if (new URL(req.url()).origin !== origin) { denied.push(req.url()); return route.abort(); }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); assert.deepEqual(writes, []); });
  const response = await page.goto(origin + route + (route.includes('?') ? '&' : '?') + 'theme=' + theme);
  assert.equal(response.status(), 200, await response.text());
  await image(page, route.includes('patients') ? 'chart-initial-' + width : route.includes('surveys') ? 'portal-surveys-initial-' + width : 'portal-slips-initial-' + width);
  return { page, context, denied, dialogs };
}
async function image(page, name) {
  const file = path.join(screenshots, name + '.png'); await page.screenshot({ path: file }); console.log('Pagination screenshot: ' + file);
}

test('patient chart pages preserve full summary, selected tab and independent histories', async t => {
  const { page } = await open(t, '/clinic/patients/88');
  assert.equal(await page.locator('.cp-snapshot-value').filter({ hasText: '61 total' }).count(), 1);
  await page.getByRole('tab', { name: /^Labs/ }).click();
  await page.locator('#tab-labs.show.active').waitFor();
  await image(page, 'chart-labs-initial');
  await page.getByRole('navigation', { name: 'Labs history pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('labs_page') === '2');
  await page.locator('#tab-labs.show.active').waitFor();
  assert.equal(await page.locator('#tab-labs tbody tr').count(), 25);
  assert.equal(await page.locator('#tab-labs').getByText('Synthetic lab 26', { exact: true }).count(), 1);
  assert.equal(new URL(page.url()).searchParams.get('visits_page'), '1');
  assert.equal(await page.locator('.cp-snapshot-value').filter({ hasText: '61 total' }).count(), 1);
  await image(page, 'chart-labs-page2');
  await page.getByRole('navigation', { name: 'Isolation history pages', exact: true }).getByRole('link', { name: 'Last', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('isolation_page') === '3');
  assert.equal(new URL(page.url()).searchParams.get('labs_page'), '2');
  assert.equal(await page.locator('.cp-iso-item').count(), 11);
  assert.equal(await page.locator('#tab-labs.show.active').count(), 1);
  await image(page, 'chart-isolation-last');
});

test('history page links respect the existing unsaved health-profile navigation guard', async t => {
  const { page, dialogs } = await open(t, '/clinic/patients/88');
  await page.getByRole('tab', { name: 'Health Profile', exact: true }).click();
  await page.getByRole('textbox', { name: 'Drug & Food Allergies', exact: true }).fill('Synthetic unsaved entry');
  await image(page, 'chart-unsaved-health');
  const before = page.url();
  await page.getByRole('navigation', { name: 'Isolation history pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  assert.equal(dialogs.length, 1);
  assert.match(dialogs[0], /unsaved changes/i);
  assert.equal(page.url(), before);
  assert.equal(await page.getByRole('textbox', { name: 'Drug & Food Allergies', exact: true }).inputValue(), 'Synthetic unsaved entry');
  await image(page, 'chart-unsaved-page-cancelled');
});

test('full-chart print link loads complete histories without page limits', async t => {
  const { page } = await open(t, '/clinic/patients/88?visits_page=2&labs_page=2');
  const popupWait = page.waitForEvent('popup');
  await page.getByRole('link', { name: 'Print Full Chart', exact: true }).click();
  const popup = await popupWait;
  await popup.locator('[data-chart-print="1"]').waitFor();
  assert.equal(new URL(popup.url()).searchParams.get('print'), '1');
  assert.equal(await popup.locator('.cp-visit-card').count(), 61);
  assert.equal(await popup.locator('#tab-labs tbody tr').count(), 61);
  assert.equal(await popup.locator('#tab-clearances tbody tr').count(), 61);
  assert.equal(await popup.locator('[data-table-pagination]').count(), 0);
  await image(popup, 'chart-full-print-history');
  await popup.close();
});

test('portal survey pagers stay independent and never generate page size 26', async t => {
  const { page } = await open(t, '/portal/surveys', 390, 'dark');
  assert.equal(await page.locator('[data-general-survey-section] .pe-content-card').count(), 25);
  assert.equal(await page.locator('[data-mi-assignment-section] .pe-content-card').count(), 25);
  await page.getByRole('navigation', { name: 'General survey pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('page') === '2');
  assert.equal(new URL(page.url()).searchParams.get('per_page'), '25');
  assert.equal(await page.locator('[data-general-survey-section]').getByText('Synthetic general survey 26', { exact: true }).count(), 1);
  await image(page, 'portal-general-surveys-page2');
  await page.getByRole('navigation', { name: 'MI assignment pages', exact: true }).getByRole('link', { name: 'Last', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('mi_page') === '3');
  assert.equal(new URL(page.url()).searchParams.get('page'), '2');
  assert.equal(new URL(page.url()).searchParams.get('mi_per_page'), '25');
  assert.equal(await page.locator('[data-mi-assignment-section] .pe-content-card').count(), 2);
  assert.equal(await page.locator('[data-general-survey-section] .pe-content-card').count(), 25);
  await image(page, 'portal-mi-last-independent');
  await page.goBack();
  await page.waitForURL(url => url.searchParams.get('page') === '2' && url.searchParams.get('mi_page') === '1');
  await page.locator('[data-mi-assignment-section] [data-table-pagination][data-page="1"]').waitFor();
  assert.equal(await page.locator('[data-mi-assignment-section] .pe-content-card').count(), 25);
  await image(page, 'portal-surveys-back');
});

test('portal call-slip footer reaches the last record beyond five hundred', async t => {
  const { page } = await open(t, '/portal/call-slips');
  await page.getByRole('navigation', { name: 'Guidance call slip pages', exact: true }).getByRole('link', { name: 'Last', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('page') === '27');
  assert.equal(await page.locator('.pa-cs-row').count(), 1);
  assert.equal(await page.getByText('SYN-SLIP-521', { exact: true }).count(), 1);
  assert.equal(await page.getByText('Showing 521–521 of 521', { exact: true }).count(), 1);
  await image(page, 'portal-slips-last-521');
});
