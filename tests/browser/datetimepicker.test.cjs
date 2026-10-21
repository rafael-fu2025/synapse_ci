'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const tools = path.join(root, 'writable/widget-test-tools/node_modules/playwright');
let playwright;
try { playwright = require(tools); }
catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  playwright = require('playwright');
}
const publicRoot = path.join(root, 'public');
let server, browser, origin;

function executablePath() {
  const expected = playwright.chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const cached = fs.existsSync(cache) ? fs.readdirSync(cache)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(name => path.join(cache, name, 'chrome-win64/chrome.exe'))
    .find(file => fs.existsSync(file)) : undefined;
  if (cached) return cached;
  const packageFile = require.resolve('playwright/package.json', { paths: [path.dirname(tools), root] });
  execFileSync(process.execPath, [path.join(path.dirname(packageFile), 'cli.js'), 'install', 'chromium'], {
    stdio: 'inherit', timeout: 90_000
  });
  return expected;
}

function fixture(url) {
  const initial = url.searchParams.has('initial') ? url.searchParams.get('initial') : '2030-01-15T08:30';
  return `<!doctype html><html lang="en-GB" data-theme="light"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>main{max-width:640px;margin:32px;padding:16px}label{display:block}</style>
    <script defer src="/assets/vendor/alpine/alpine.min.js"></script>
  </head><body><main>
    <form id="form" x-data="{ startsAt: '${initial}' }">
      <label for="surveyStartDisplay">Starts at</label>
      <input type="text"
             id="surveyStartDisplay"
             class="form-control"
             data-datetimepicker
             data-target="#surveyStart"
             placeholder="DD/MM/YYYY hh:mm A"
             autocomplete="off">
      <input type="hidden"
             id="surveyStart"
             name="starts_at"
             value="${initial}"
             x-model="startsAt">
      <output id="model" x-text="startsAt"></output>
    </form>
    ${url.searchParams.has('time') ? `<form id="scheduleForm">
      <label for="scheduleTime">Start time</label>
      <input id="scheduleTime" type="text" class="form-control" data-timepicker data-target="#scheduleValue" required>
      <input id="scheduleValue" type="hidden" name="start_time" value="08:30">
    </form>` : ''}
    <button id="outside" type="button">Outside</button>
  </main>
  <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="/assets/js/custom-dropdown.js"></script>
  <script src="/assets/js/datepicker.js"></script>
  <script src="/assets/js/datetimepicker.js"></script>
  </body></html>`;
}

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(fixture(url));
      return;
    }
    const safePath = path.normalize(path.join(publicRoot, url.pathname));
    if (safePath.startsWith(publicRoot) && fs.existsSync(safePath) && fs.statSync(safePath).isFile()) {
      const ext = path.extname(safePath);
      const mime = ext === '.js' ? 'application/javascript' : (ext === '.css' ? 'text/css' : 'application/octet-stream');
      res.writeHead(200, { 'Content-Type': mime });
      fs.createReadStream(safePath).pipe(res);
      return;
    }
    res.writeHead(404);
    res.end();
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await playwright.chromium.launch({
    executablePath: executablePath(),
    headless: true,
  });
});

after(async () => {
  await browser?.close();
  await new Promise(resolve => server?.close(resolve));
});

test('Time-only mode shares wheels, submits HH:mm, and validates typed input without a calendar', async () => {
  const page = await browser.newPage({ viewport: { width: 375, height: 700 } });
  await page.goto(`${origin}/fixture?time=1`);
  assert.equal(await page.locator('#scheduleTime').inputValue(), '08:30 AM');
  await page.click('#scheduleTime');
  const popup = page.locator('.dtp-time-only');
  assert.equal(await popup.locator('.dtp-date-pane').count(), 0);
  assert.equal(await popup.locator('.dtp-wheel-item').count(), 74);
  await popup.locator('.dtp-hours-wheel [data-val="12"]').click();
  await popup.locator('.dtp-minutes-wheel [data-val="0"]').click();
  await popup.locator('.dtp-ampm-wheel [data-val="AM"]').click();
  assert.equal(await page.locator('#scheduleValue').inputValue(), '00:00');
  await popup.locator('.dtp-ampm-wheel [data-val="PM"]').click();
  assert.equal(await page.locator('#scheduleValue').inputValue(), '12:00');
  const bounds = await popup.boundingBox();
  assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 375);
  await popup.locator('[data-dtp-apply]').click();
  assert.equal(await page.locator('#scheduleTime').inputValue(), '12:00 PM');
  assert.equal(await popup.isVisible(), false);
  await page.locator('#scheduleTime').fill('13:10 PM');
  await page.click('#outside');
  assert.equal(await page.locator('#scheduleValue').inputValue(), '');
  assert.equal(await page.locator('#scheduleForm').evaluate(form => form.checkValidity()), false);
  await page.locator('#scheduleTime').fill('23:59');
  await page.click('#outside');
  assert.equal(await page.locator('#scheduleTime').inputValue(), '11:59 PM');
  assert.equal(await page.locator('#scheduleValue').inputValue(), '23:59');
  assert.equal(await page.locator('#scheduleForm').evaluate(form => form.checkValidity()), true);
  await page.locator('#scheduleTime').fill('');
  await page.click('#outside');
  assert.equal(await page.locator('#scheduleForm').evaluate(form => form.checkValidity()), false);
  await page.close();
});

test('BalayDateTimePicker modal displays date picker and scrollable wheels for hours, minutes, and AM/PM', async () => {
  const page = await browser.newPage();
  await page.goto(`${origin}/fixture`);
  await page.waitForSelector('.dtp-wrap');

  const initialDisplay = await page.locator('#surveyStartDisplay').inputValue();
  assert.equal(initialDisplay, '15/01/2030 08:30 AM');

  await page.click('#surveyStartDisplay');
  const popup = page.locator('.dtp-popup');
  assert.equal(await popup.isVisible(), true);

  // Date picker (wider) pane
  const datePane = popup.locator('.dtp-date-pane');
  assert.equal(await datePane.isVisible(), true);
  assert.equal(await popup.locator('.dtp-grid').isVisible(), true);

  // Divider
  assert.equal(await popup.locator('.dtp-divider').isVisible(), true);

  // Time pane with scrollable wheels
  const timePane = popup.locator('.dtp-time-pane');
  assert.equal(await timePane.isVisible(), true);

  // Verify preset buttons are removed
  const presets = await popup.locator('.dtp-preset-btn').count();
  assert.equal(presets, 0);

  // Verify scrollable wheels exist
  const hoursWheel = popup.locator('.dtp-hours-wheel');
  const minutesWheel = popup.locator('.dtp-minutes-wheel');
  const ampmWheel = popup.locator('.dtp-ampm-wheel');

  assert.equal(await hoursWheel.isVisible(), true);
  assert.equal(await minutesWheel.isVisible(), true);
  assert.equal(await ampmWheel.isVisible(), true);

  // 12 hour items, 60 minute items, 2 am/pm items
  assert.equal(await hoursWheel.locator('.dtp-wheel-item').count(), 12);
  assert.equal(await minutesWheel.locator('.dtp-wheel-item').count(), 60);
  assert.equal(await ampmWheel.locator('.dtp-wheel-item').count(), 2);

  // Active items highlighted
  const selHour = await hoursWheel.locator('.dtp-wheel-item.is-selected').textContent();
  const selMin = await minutesWheel.locator('.dtp-wheel-item.is-selected').textContent();
  const selAmpm = await ampmWheel.locator('.dtp-wheel-item.is-selected').textContent();

  assert.equal(selHour, '08');
  assert.equal(selMin, '30');
  assert.equal(selAmpm, 'AM');

  await page.close();
});

test('Selecting hour, minute, and PM in scrollable wheels updates live readout and ISO output', async () => {
  const page = await browser.newPage();
  await page.goto(`${origin}/fixture`);
  await page.waitForSelector('.dtp-wrap');

  await page.click('#surveyStartDisplay');
  const popup = page.locator('.dtp-popup');

  // Click day 20 in calendar
  await page.click('.dtp-popup td[data-date="2030-01-20"]');

  // Click hour 02 in hours wheel
  await page.click('.dtp-hours-wheel .dtp-wheel-item[data-val="2"]');

  // Click minute 45 in minutes wheel
  await page.click('.dtp-minutes-wheel .dtp-wheel-item[data-val="45"]');

  // Click PM in ampm wheel
  await page.click('.dtp-ampm-wheel .dtp-wheel-item[data-val="PM"]');

  // Verify live readout inside modal
  const readout = await popup.locator('.dtp-time-readout').textContent();
  assert.equal(readout, '02:45 PM');

  // Click Apply
  await page.click('[data-dtp-apply]');

  const display = await page.locator('#surveyStartDisplay').inputValue();
  assert.equal(display, '20/01/2030 02:45 PM');

  const iso = await page.locator('#surveyStart').inputValue();
  assert.equal(iso, '2030-01-20T14:45');

  await page.close();
});

test('Clear button in modal resets date and time', async () => {
  const page = await browser.newPage();
  await page.goto(`${origin}/fixture`);
  await page.waitForSelector('.dtp-wrap');

  await page.click('#surveyStartDisplay');
  await page.click('[data-dtp-clear]');

  const display = await page.locator('#surveyStartDisplay').inputValue();
  assert.equal(display, '');

  const iso = await page.locator('#surveyStart').inputValue();
  assert.equal(iso, '');

  await page.close();
});

test('Typing in datetime field rejects forbidden letters, decimals, and special characters', async () => {
  const page = await browser.newPage();
  await page.goto(`${origin}/fixture?initial=`);
  await page.waitForSelector('.dtp-wrap');

  const input = page.locator('#surveyStartDisplay');
  await input.focus();

  // Type forbidden letters (e.g. b, c, x, y, z), decimals, symbols
  await page.keyboard.type('abc.xyz@#$12/05/2030 08:30 AM');

  const val = await input.inputValue();
  // 'b', 'c', '.', 'x', 'y', 'z', '@', '#', '$' must be blocked
  // only allowed characters [0-9/\- :ampAMPtT] should have entered
  assert.match(val, /^[0-9/\- :ampAMPtT]*$/);
  assert.equal(val.includes('b'), false);
  assert.equal(val.includes('c'), false);
  assert.equal(val.includes('.'), false);
  assert.equal(val.includes('@'), false);
  assert.equal(val.includes('#'), false);
  assert.equal(val.includes('$'), false);

  await page.close();
});

test('Pasting text with letters or decimals is blocked', async () => {
  const page = await browser.newPage();
  await page.goto(`${origin}/fixture?initial=`);
  await page.waitForSelector('.dtp-wrap');

  const input = page.locator('#surveyStartDisplay');
  await input.focus();

  // Try to paste an invalid string containing letters and decimals
  await page.evaluate(() => {
    const el = document.querySelector('#surveyStartDisplay');
    const dt = new DataTransfer();
    dt.setData('text/plain', 'hello.world 123');
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  });

  const val = await input.inputValue();
  assert.equal(val, '');

  await page.close();
});

test('Partial or invalid text on blur is cleared, preventing invalid form submissions', async () => {
  const page = await browser.newPage();
  await page.goto(`${origin}/fixture?initial=`);
  await page.waitForSelector('.dtp-wrap');

  const input = page.locator('#surveyStartDisplay');
  await input.focus();
  await page.keyboard.type('12/05');
  await page.click('#outside');

  const val = await input.inputValue();
  assert.equal(val, '');

  const iso = await page.locator('#surveyStart').inputValue();
  assert.equal(iso, '');

  await page.close();
});
