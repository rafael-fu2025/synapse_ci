'use strict';

// Actual survey view/shell with synthetic CLI-rendered data and local assets only.
// Native submit is captured before report-download.js; no HTTP exports or screenshots.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const { chromium } = require(path.join(__dirname, 'node_modules/playwright'));
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(root, 'tests/_support/render-survey-analytics-fixture.php');
const responsesPath = '/guidance/surveys/1/responses';
const exportPath = responsesPath + '/export';
const cache = new Map(), serverViolations = [];
let server, browser, origin;

function installedChromium() {
  const expected = chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const installed = fs.existsSync(dir) ? fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe'].map(file => path.join(dir, name, file)))
    .find(file => fs.existsSync(file)) : undefined;
  assert.ok(installed, 'Use an existing Chromium; this offline suite never installs or downloads a browser');
  return installed;
}
function render(exportEnabled) {
  const key = String(exportEnabled);
  if (!cache.has(key)) {
    const fixture = { origin };
    if (exportEnabled !== undefined) fixture.exportEnabled = exportEnabled;
    const result = spawnSync(process.env.PHP_BINARY || 'php', [renderer], { cwd: root, input: JSON.stringify(fixture), encoding: 'utf8', timeout: 10000, maxBuffer: 2 * 1024 * 1024 });
    assert.equal(result.status, 0, result.stderr || result.error?.message);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
    assert.match(result.stdout, /class="app-shell"/);
    assert.match(result.stdout, /id="tab-visualizations"/);
    cache.set(key, result.stdout);
  }
  return cache.get(key);
}
const isStatic = pathname => pathname.startsWith('/assets/') || ['/favicon.ico', '/favicon-192.png', '/favicon-180.png'].includes(pathname);
before(async () => {
  const executablePath = installedChromium();
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method !== 'GET' || (url.pathname !== responsesPath && !isStatic(url.pathname))) {
      serverViolations.push(`${req.method} ${url.pathname}`);
      return res.writeHead(405).end('No application endpoints or mutations');
    }
    if (url.pathname === responsesPath) {
      const enabled = url.searchParams.has('export') ? url.searchParams.get('export') === '1' : undefined;
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(enabled)); }
      catch (error) { return res.writeHead(500).end(error.stack); }
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const mime = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }).end(data);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
  assert.deepEqual(serverViolations, [], 'No unexpected request or mutation may reach the local asset server');
});

async function open(t, enabled = true, theme = 'light', width = 1280) {
  const context = await browser.newContext({ viewport: { width, height: 950 }, reducedMotion: 'reduce', colorScheme: theme, timezoneId: 'Asia/Manila', serviceWorkers: 'block', acceptDownloads: false });
  const violations = [], errors = [];
  await context.addInitScript(theme => {
    localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
    window.surveySubmissions = [];
    // Browser constraint validation runs before submit. Capture only a valid native
    // submit and prevent the production download handler from making an export request.
    document.addEventListener('submit', event => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.matches('.gs-export-form[data-report-download]')) return;
      event.preventDefault();
      window.surveySubmissions.push({ method: form.method, action: new URL(form.action).pathname, valid: form.checkValidity(), fields: [...new FormData(form, event.submitter).entries()] });
    }, true);
  }, theme);
  await context.route('**/*', route => {
    const request = route.request(), url = new URL(request.url());
    if (request.method() !== 'GET') {
      violations.push(`${request.method()} ${request.url()}`); return route.abort();
    }
    // The full production shell references Google Fonts; replace that known stylesheet
    // locally rather than contacting it. Every other external request is a failure.
    if (url.origin === 'https://fonts.googleapis.com' && url.pathname === '/css2') return route.fulfill({ contentType: 'text/css', body: '' });
    if (url.origin !== origin) { violations.push(`External ${request.url()}`); return route.abort(); }
    // The synthetic shell's notification reader receives an empty synthetic reply;
    // it never reaches an app endpoint or asks for real notification data.
    if (url.pathname === '/notifications') return route.fulfill({ json: { items: [], unread_count: 0 } });
    if (url.pathname !== responsesPath && !isStatic(url.pathname)) {
      violations.push(`${request.method()} ${request.url()}`); return route.abort();
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(15000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 400) errors.push(`HTTP ${response.status()} ${response.url()}`); });
  page.on('download', () => violations.push('Unexpected browser download'));
  t.after(async () => {
    await context.close();
    assert.deepEqual(violations, [], 'No export, mutation, AI, or unexpected external/application request is allowed');
    assert.deepEqual(errors, [], 'The production survey shell must initialize and swap without browser errors');
  });
  await page.clock.setFixedTime(new Date('2030-01-31T04:00:00Z'));
  await page.goto(origin + responsesPath + (enabled === 'default' ? '' : `?export=${enabled ? '1' : '0'}`));
  await page.waitForFunction(() => window.SynapsePageLifecycle && window.BalayDateRangePicker && window.SynapseReportDownloads);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  if (enabled === true) await page.locator('#response-export-range').locator('..').locator('.drp-icon').waitFor();
  return page;
}
const formFor = page => page.locator('form.gs-export-form[data-report-download]');
async function rangeState(page) {
  return page.locator('#response-export-range').evaluate(input => ({ from: document.querySelector('#response-export-from').value, to: document.querySelector('#response-export-to').value, value: input.value, valid: input.validity.valid }));
}
async function openRange(page) {
  await page.locator('#response-export-range').click();
  const id = await page.locator('#response-export-range').getAttribute('aria-controls');
  const popup = page.locator(`[id="${id}"]`); await popup.waitFor({ state: 'visible' }); return popup;
}
async function submitCount(page) { return page.evaluate(() => window.surveySubmissions.length); }
async function assertSubmission(page, index, from, to, csrf) {
  const submissions = await page.evaluate(() => window.surveySubmissions);
  assert.equal(submissions.length, index + 1, 'Each valid native submission must be captured exactly once');
  assert.deepEqual(submissions[index], { method: 'post', action: exportPath, valid: true, fields: [[csrf.name, csrf.value], ['from', from], ['to', to], ['confirm_identified', '1']] });
  assert.equal(await page.locator('.report-download-panel').count(), 0, 'Submit interception must stop the real HTTP download flow');
}
async function csrfFor(page) {
  const token = await formFor(page).locator('input[type="hidden"]').evaluateAll(inputs => inputs.filter(input => !['from', 'to'].includes(input.name)).map(input => ({ name: input.name, value: input.value })));
  assert.equal(token.length, 1); assert.ok(token[0].name && token[0].value);
  assert.equal(token[0].name, await page.locator('meta[name="csrf-name"]').getAttribute('content'));
  assert.equal(token[0].value, await page.locator('meta[name="csrf-token"]').getAttribute('content'));
  return token[0];
}

test('Default/denied fixtures retain no export form; only the opt-in renderer flag enables the real range markup', { timeout: 20000 }, async t => {
  for (const enabled of ['default', false]) {
    const page = await open(t, enabled);
    assert.equal(await formFor(page).count(), 0);
    assert.equal(await page.locator('#response-export-range, #response-export-from, #response-export-to, .drp-wrap, .drp-popup').count(), 0);
    assert.match(await page.locator('.gs-export-section').textContent(), /reports\.export/);
    assert.equal(await page.locator('.survey-q-card').count(), 4);
  }
  const page = await open(t);
  const input = page.locator('#response-export-range'), form = formFor(page);
  assert.equal(await form.count(), 1); assert.equal(await form.getAttribute('method'), 'post');
  assert.equal(new URL(await form.getAttribute('action'), origin).pathname, exportPath);
  assert.equal(await input.getAttribute('data-daterangepicker'), ''); assert.equal(await input.getAttribute('data-datepicker'), null);
  assert.equal(await input.getAttribute('data-target'), '#response-export-from');
  assert.equal(await input.getAttribute('data-range-end'), '#response-export-to');
  assert.equal(await input.getAttribute('data-max-days'), '31'); assert.equal(await input.getAttribute('required'), '');
  assert.equal(await input.evaluate(el => Boolean(el.closest('.drp-wrap')) && !el.closest('.dp-wrap')), true);
  assert.equal(await page.locator('#response-export-from').getAttribute('type'), 'hidden');
  assert.equal(await page.locator('#response-export-to').getAttribute('type'), 'hidden');
  assert.equal(await form.locator('[name="confirm_identified"]').getAttribute('required'), '');
  assert.equal(await form.locator('[name="confirm_identified"]').getAttribute('value'), '1');
  assert.match(await page.locator('#response-export-date-help').textContent(), /Asia\/Manila/);
  assert.match(await page.locator('.gs-export-note').first().textContent(), /31 calendar days, inclusive/);
  await csrfFor(page);
});

test('Real survey form enforces required range/consent and 31 days, then serializes only valid ISO dates with CSRF without HTTP exports', { timeout: 30000 }, async t => {
  const page = await open(t), form = formFor(page), input = page.locator('#response-export-range');
  const consent = form.locator('[name="confirm_identified"]'), submit = form.getByRole('button', { name: 'Download identified response CSV', exact: true });
  const csrf = await csrfFor(page);
  await submit.click(); assert.equal(await submitCount(page), 0);
  await input.fill('31/01/2030 – 04/02/2030');
  assert.deepEqual(await rangeState(page), { from: '2030-01-31', to: '2030-02-04', value: '31/01/2030 – 04/02/2030', valid: true });
  await submit.click(); assert.equal(await submitCount(page), 0, 'A valid range must not bypass identified-data consent');
  await consent.check(); await submit.click(); await assertSubmission(page, 0, '2030-01-31', '2030-02-04', csrf);
  for (const invalid of ['01/01/2030 – 01/02/2030', '31/02/2030 – 01/03/2030', '20/01/2030 – 10/01/2030', '10/01/2030 – ']) {
    await input.fill(invalid);
    const state = await rangeState(page); assert.equal(state.from, ''); assert.equal(state.to, ''); assert.equal(state.valid, false);
    await submit.click(); assert.equal(await submitCount(page), 1, invalid);
  }
  await input.fill('01/01/2030 – 31/01/2030'); await input.press('Tab');
  await submit.click(); await assertSubmission(page, 1, '2030-01-01', '2030-01-31', csrf);
  let popup = await openRange(page);
  await popup.locator('.drp-cell[data-date="2030-01-31"]').click();
  assert.equal(await popup.isVisible(), true);
  let state = await rangeState(page); assert.equal(state.from, ''); assert.equal(state.to, ''); assert.equal(state.valid, false);
  await popup.locator('.drp-cell[data-date="2030-02-04"]').hover();
  state = await rangeState(page); assert.equal(state.from, ''); assert.equal(state.to, '');
  await popup.locator('.drp-cell[data-date="2030-02-04"]').click();
  await submit.click(); await assertSubmission(page, 2, '2030-01-31', '2030-02-04', csrf);
  popup = await openRange(page); await popup.locator('[data-drp-clear]').click();
  assert.deepEqual(await rangeState(page), { from: '', to: '', value: '', valid: false });
  await submit.click(); assert.equal(await submitCount(page), 3, 'Clear must not submit the previous range');
});

test('Repeated production page-lifecycle swaps clean up range/dropdown ownership and initialize each fresh survey once in both themes', { timeout: 40000 }, async t => {
  for (const theme of ['light', 'dark']) {
    const page = await open(t, true, theme);
    const raw = render(true);
    for (let cycle = 0; cycle < 3; cycle++) {
      await page.locator('#response-export-range').fill('31/01/2030 – 04/02/2030');
      const popup = await openRange(page);
      const toggle = popup.locator('.bd-select').filter({ has: page.locator('select[data-drp-month]') }).locator('.bd-select-toggle');
      await toggle.click();
      const oldId = await popup.getAttribute('id');
      // This models the navigation owner's documented event sequence using a fresh
      // copy of the actual PHP-rendered workspace, not a generated replacement form.
      await page.evaluate(html => {
        const root = document.querySelector('#main'), input = root.querySelector('#response-export-range');
        const popup = document.getElementById(input.getAttribute('aria-controls'));
        window.retiredSurveyRange = { input, popup, toggle: popup.querySelector('.bd-select-toggle') };
        document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root } }));
        const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-refresh-owner="survey-responses.1"]');
        root.querySelector('[data-refresh-owner="survey-responses.1"]').replaceWith(document.importNode(fresh, true));
        for (let i = 0; i < 3; i++) document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root } }));
      }, raw);
      await page.waitForFunction(() => document.querySelectorAll('.drp-popup').length === 1 && Boolean(document.querySelector('#response-export-range').closest('.drp-wrap')));
      assert.deepEqual(await page.evaluate(() => ({ popupRemoved: !retiredSurveyRange.popup.isConnected, dropdownDisposed: bootstrap.Dropdown.getInstance(retiredSurveyRange.toggle) === null, triggerRestored: !retiredSurveyRange.input.hasAttribute('aria-controls') })), { popupRemoved: true, dropdownDisposed: true, triggerRestored: true });
      assert.equal(await page.locator('.drp-wrap').count(), 1); assert.equal(await page.locator('.dp-wrap').count(), 0);
      assert.equal(await page.locator('#response-export-range').evaluate(el => el.parentElement.querySelectorAll('.drp-wrap').length), 0, 'Repeated init must not nest wrappers');
      assert.notEqual(await page.locator('#response-export-range').getAttribute('aria-controls'), oldId);
      assert.deepEqual(await rangeState(page), { from: '', to: '', value: '', valid: false });
      assert.equal(await page.locator('.survey-q-card').count(), 4); assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
      const freshPopup = await openRange(page);
      await freshPopup.locator('.drp-cell[data-date="2030-01-31"]').click();
      assert.equal(await freshPopup.isVisible(), true);
      await freshPopup.locator('.drp-cell[data-date="2030-02-01"]').click();
      await formFor(page).locator('[name="confirm_identified"]').check();
      await formFor(page).getByRole('button', { name: 'Download identified response CSV', exact: true }).click();
      await assertSubmission(page, cycle, '2030-01-31', '2030-02-01', await csrfFor(page));
    }
  }
});
