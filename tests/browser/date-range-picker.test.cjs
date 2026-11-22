'use strict';

// Offline production assets and synthetic markup only. No app/DB, downloads, or screenshots.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { chromium } = require(path.join(__dirname, 'node_modules/playwright'));
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const serverViolations = [];
let server, browser, origin;

function installedChromium() {
  const expected = chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const installed = fs.existsSync(cache) ? fs.readdirSync(cache)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe'].map(file => path.join(cache, name, file)))
    .find(file => fs.existsSync(file)) : undefined;
  assert.ok(installed, 'An existing Chromium is required; this suite never installs or downloads browsers');
  return installed;
}

function fixture(url) {
  const q = url.searchParams;
  const esc = value => String(value || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const bounds = ['min', 'max'].map(name => q.has(name) ? `${q.has('nativeBounds') ? name : 'data-' + name}="${esc(q.get(name))}"` : '').join(' ');
  const form = `<form id="range-form">
    <label for="range">Date range</label><input id="range" class="form-control" type="text" data-daterangepicker data-target="#from" data-range-end="#to" data-max-days="${esc(q.get('maxDays') || '31')}" data-order="${esc(q.get('order') || 'dmy')}" data-locale="${esc(q.get('locale') || 'en-GB')}" ${bounds} ${q.has('optional') ? '' : 'required'} ${q.has('readonly') ? 'readonly' : ''} ${q.has('disabled') ? 'disabled' : ''}>
    <input id="from" type="hidden" name="from" value="${esc(q.get('from'))}" data-fixture-range>
    <input id="to" type="hidden" name="to" value="${esc(q.get('to'))}" data-fixture-range>
  </form>`;
  const host = q.get('host');
  const hosted = host === 'modal' ? `<div class="modal show" id="host" style="display:block" role="dialog" aria-label="Synthetic form"><div class="modal-dialog" style="width:350px;max-width:calc(100% - 32px);margin:16px"><div class="modal-content"><div class="modal-body">${form}</div></div></div></div>`
    : host ? `<section id="host" ${host === 'container' ? 'data-daterange-container' : `class="${esc(host)}"`} style="width:350px;max-width:100%;position:relative;inset:auto;display:block;transform:none">${form}</section>` : form;
  return `<!doctype html><html lang="en-GB" data-theme="${q.get('theme') === 'dark' ? 'dark' : 'light'}"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css"><link rel="stylesheet" href="/assets/css/style.css">
    <style>main{padding:16px;width:100%;margin:0;box-sizing:border-box}form{max-width:480px}label{display:block}.fixture-controls{position:absolute;right:16px;top:16px;width:200px}#legacy{margin-top:24px;max-width:480px}@media(max-width:700px){.fixture-controls{position:static;margin-top:16px}}</style>
    </head><body><main id="main">${hosted}
    <div class="fixture-controls"><label><input id="read-only" type="checkbox" ${q.has('readonly') ? 'checked' : ''}>Read only</label><label><input id="disable-range" type="checkbox" ${q.has('disabled') ? 'checked' : ''}>Disabled</label><button id="outside" type="button">Outside</button></div>
    <section id="legacy"><label for="single">Existing date</label><input id="single" class="form-control" data-datepicker data-target="#single-iso"><input id="single-iso" type="hidden" value="2030-01-15">
      <label for="datetime">Existing date and time</label><input id="datetime" class="form-control" data-datetimepicker data-target="#datetime-iso"><input id="datetime-iso" type="hidden" value="2030-01-15T08:30">
      <label for="time">Existing time</label><input id="time" class="form-control" data-timepicker data-target="#time-iso"><input id="time-iso" type="hidden" value="08:30"></section>
    <div id="dynamic"></div></main>
    <script>window.rangeEvents=[];for(const type of ['input','change'])document.addEventListener(type,event=>{
      if(event.target.matches('#range,[data-fixture-range]'))rangeEvents.push({id:event.target.id,type,from:document.querySelector('#from').value,to:document.querySelector('#to').value});
    });document.querySelector('#read-only').addEventListener('change',event=>document.querySelector('#range').readOnly=event.target.checked);
    document.querySelector('#disable-range').addEventListener('change',event=>document.querySelector('#range').disabled=event.target.checked);</script>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="/assets/js/custom-dropdown.js"></script>
    <script src="/assets/js/datepicker.js"></script><script src="/assets/js/datetimepicker.js"></script><script src="/assets/js/date-range-picker.js"></script><script src="/assets/js/page-lifecycle.js"></script>
    </body></html>`;
}

before(async () => {
  const executablePath = installedChromium();
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method !== 'GET' || (url.pathname !== '/fixture' && !url.pathname.startsWith('/assets/'))) {
      serverViolations.push(`${req.method} ${url.pathname}`);
      return res.writeHead(405).end('Only fixtures and static assets are allowed');
    }
    if (url.pathname === '/fixture') return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(fixture(url));
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const mime = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml' };
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
  assert.deepEqual(serverViolations, [], 'Unexpected requests must be blocked before reaching the fixture server');
});

async function open(t, options = {}, contextOptions = {}) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block', timezoneId: 'Asia/Manila', ...contextOptions });
  const violations = [], errors = [];
  await context.route('**/*', route => {
    const request = route.request(), url = new URL(request.url());
    if (request.method() !== 'GET' || url.origin !== origin || (url.pathname !== '/fixture' && !url.pathname.startsWith('/assets/'))) {
      violations.push(`${request.method()} ${request.url()}`);
      return route.abort();
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000); page.setDefaultNavigationTimeout(10000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 400) errors.push(`HTTP ${response.status()} ${response.url()}`); });
  page.on('download', () => violations.push('Unexpected browser download'));
  t.after(async () => {
    await context.close();
    assert.deepEqual(violations, [], 'The range picker must not fetch, mutate, export, or contact an external origin');
    assert.deepEqual(errors, [], 'Production assets must initialize and clean up without browser errors');
  });
  await page.clock.setFixedTime(new Date('2030-01-31T04:00:00Z'));
  await navigate(page, options);
  return page;
}
async function navigate(page, options) {
  await page.goto(`${origin}/fixture?${new URLSearchParams(options)}`);
  await page.waitForFunction(() => window.BalayDateRangePicker && document.querySelector('#range').closest('.drp-wrap') && document.querySelector('#single').closest('.dp-wrap') && document.querySelector('#datetime').closest('.dtp-wrap'));
}
async function popupFor(page, selector = '#range') {
  const id = await page.locator(selector).getAttribute('aria-controls');
  assert.ok(id, 'The range input must identify its popup');
  return page.locator(`[id="${id}"]`);
}
async function openRange(page, selector = '#range') {
  await page.locator(selector).click();
  const popup = await popupFor(page, selector);
  await popup.waitFor({ state: 'visible' });
  return popup;
}
const day = (popup, iso) => popup.locator(`td.drp-cell[data-date="${iso}"]`);
const events = page => page.evaluate(() => window.rangeEvents);
async function state(page) {
  return page.locator('#range').evaluate(input => ({ value: input.value, from: document.querySelector('#from').value, to: document.querySelector('#to').value, valid: input.validity.valid }));
}
async function assertRange(page, from, to, value, valid = true) {
  assert.deepEqual(await state(page), { from, to, value, valid });
}
async function assertEmptyMirrors(page, valid = false) {
  const actual = await state(page);
  assert.equal(actual.from, ''); assert.equal(actual.to, ''); assert.equal(actual.valid, valid);
}
async function assertFocusedDay(page, iso) {
  assert.equal(await page.locator('td.drp-cell:focus').getAttribute('data-date'), iso);
  assert.equal(await page.locator('.drp-popup:visible .drp-cell[tabindex="0"]').count(), 1);
}

// Group related paths instead of creating a catalog entry for every date or viewport.
test('January 31 to February is one continuous interaction; previews never publish and commits are atomic', { timeout: 20000 }, async t => {
  const page = await open(t);
  const popup = await openRange(page);
  assert.equal(await popup.getAttribute('role'), 'dialog');
  assert.equal(await popup.getAttribute('aria-label'), 'Choose date range');
  assert.equal(await popup.locator('.drp-calendar:visible').count(), 2);
  assert.deepEqual(await popup.locator('.drp-calendar:visible h3').allTextContents(), ['January 2030', 'February 2030']);
  assert.deepEqual(await popup.locator('.drp-calendar:visible').evaluateAll(panels => panels.map(panel => panel.dataset.drpPanel)), ['0', '1']);
  assert.equal(await popup.locator('table.drp-grid[role="grid"]:visible').count(), 2);
  const dates = await popup.locator('.drp-cell[data-date]').evaluateAll(cells => cells.map(cell => cell.dataset.date));
  assert.equal(new Set(dates).size, dates.length, 'Adjacent months must not duplicate selectable dates');
  assert.equal(await popup.locator('.drp-cell .drp-day').count(), dates.length);
  assert.ok((await popup.locator('tbody td:not([data-date])').allTextContents()).every(text => text.trim() === ''), 'Adjacent-month cells must be blank');
  assert.equal(await day(popup, '2030-01-31').getAttribute('role'), 'gridcell');
  await assertFocusedDay(page, '2030-01-31');
  await page.locator('#range').click();
  assert.equal(await popup.isVisible(), true, 'Clicking the input again must not toggle the popup closed');
  await day(popup, '2030-01-31').click();
  assert.equal(await popup.isVisible(), true); await assertEmptyMirrors(page);
  assert.equal(await popup.getByRole('button', { name: 'End date', exact: true }).getAttribute('aria-pressed'), 'true');
  const beforePreview = await events(page);
  await day(popup, '2030-02-04').hover();
  assert.equal(await popup.locator('.is-preview').count(), 5);
  assert.equal(await day(popup, '2030-01-31').getAttribute('class').then(c => c.includes('is-preview-start')), true);
  assert.equal(await day(popup, '2030-02-04').getAttribute('class').then(c => c.includes('is-preview-end')), true);
  assert.equal(await popup.locator('.is-in-range, .is-range-end').count(), 0);
  await day(popup, '2030-02-03').focus();
  assert.equal(await popup.locator('.is-preview-end').getAttribute('data-date'), '2030-02-03');
  await assertEmptyMirrors(page); assert.deepEqual(await events(page), beforePreview);
  await popup.locator('h2').hover();
  assert.equal(await popup.locator('.is-preview').count(), 0, 'Leaving the calendars must clear the visual preview');
  await day(popup, '2030-02-04').focus(); await page.keyboard.press('Tab');
  assert.equal(await popup.locator('.is-preview').count(), 0, 'Tabbing out of the grid must clear the preview');
  assert.deepEqual(await events(page), beforePreview);
  await day(popup, '2030-02-04').click();
  await assertRange(page, '2030-01-31', '2030-02-04', '31/01/2030 – 04/02/2030');
  assert.equal(await popup.isVisible(), false);
  const committed = (await events(page)).slice(beforePreview.length);
  const hiddenInputs = committed.filter(event => event.type === 'input' && ['from', 'to'].includes(event.id));
  assert.deepEqual(hiddenInputs.map(event => event.id), ['from', 'to']);
  assert.ok(committed.every(event => event.from === '2030-01-31' && event.to === '2030-02-04'), JSON.stringify(committed));
  assert.deepEqual(committed.filter(event => event.type === 'change').map(event => event.id), ['from', 'to', 'range']);
  assert.ok(committed.findIndex(event => event.type === 'change') > committed.findIndex(event => event.id === 'to' && event.type === 'input'));
  assert.equal(await page.locator('#range').evaluate(input => input === document.activeElement), true);
});

test('Completed ranges reopen intact, replace on the first pick, and support backward, same-day and endpoint reselection', { timeout: 20000 }, async t => {
  const page = await open(t, { from: '2030-01-10', to: '2030-01-20' });
  let popup = await openRange(page);
  assert.equal(await popup.locator('.is-in-range').count(), 11);
  assert.equal(await popup.locator('.is-selected').count(), 2);
  assert.match(await popup.locator('[data-drp-start]').textContent(), /10\/01\/2030/);
  assert.match(await popup.locator('[data-drp-end]').textContent(), /20\/01\/2030/);
  await day(popup, '2030-01-20').click(); await assertEmptyMirrors(page);
  await day(popup, '2030-01-10').click();
  await assertRange(page, '2030-01-10', '2030-01-20', '10/01/2030 – 20/01/2030');
  popup = await openRange(page);
  await popup.getByRole('button', { name: 'End date', exact: true }).click();
  await assertEmptyMirrors(page);
  assert.equal(await popup.locator('[data-drp-end]').getAttribute('aria-pressed'), 'true');
  await day(popup, '2030-01-15').click();
  await assertRange(page, '2030-01-10', '2030-01-15', '10/01/2030 – 15/01/2030');
  popup = await openRange(page);
  await popup.getByRole('button', { name: 'Start date', exact: true }).click();
  await assertEmptyMirrors(page);
  assert.equal(await popup.locator('[data-drp-start]').getAttribute('aria-pressed'), 'true');
  await day(popup, '2030-01-12').click(); await day(popup, '2030-01-12').click();
  await assertRange(page, '2030-01-12', '2030-01-12', '12/01/2030 – 12/01/2030');
  popup = await openRange(page);
  assert.equal(await popup.locator('.is-range-start.is-range-end.is-selected.is-in-range').count(), 1);
});

test('The inclusive 31-day limit rejects 32 without losing the partial selection, then accepts 31', { timeout: 15000 }, async t => {
  const page = await open(t);
  const popup = await openRange(page);
  await day(popup, '2030-01-01').click();
  const partialEvents = await events(page);
  await day(popup, '2030-02-01').click();
  assert.equal(await popup.isVisible(), true); await assertEmptyMirrors(page);
  assert.match(await popup.locator('.drp-status').textContent(), /31/);
  assert.equal(await popup.locator('.drp-status').isVisible(), true);
  assert.equal(await popup.locator('.is-range-start').getAttribute('data-date'), '2030-01-01');
  assert.deepEqual(await events(page), partialEvents, 'A rejected end must not commit or restore stale mirrors');
  await day(popup, '2030-01-31').click();
  await assertRange(page, '2030-01-01', '2030-01-31', '01/01/2030 – 31/01/2030');
  assert.equal(await popup.isVisible(), false);
});

test('Typing synchronizes immediately; impossible, partial, reversed and oversized ranges clear stale ISO values; correction and Clear recover', { timeout: 25000 }, async t => {
  const page = await open(t, { from: '2030-01-10', to: '2030-01-20' });
  const input = page.locator('#range');
  for (const invalid of ['31/02/2030 – 01/03/2030', '29/02/2031 – 01/03/2031', '20/01/2030 – 10/01/2030', '01/01/2030 – 01/02/2030', '10/01/2030', '10/01/2030 – 2']) {
    await input.fill('10/01/2030 – 20/01/2030');
    await assertRange(page, '2030-01-10', '2030-01-20', '10/01/2030 – 20/01/2030');
    await input.fill(invalid); await assertEmptyMirrors(page);
    await input.press('Tab'); await assertEmptyMirrors(page);
    assert.equal(await input.getAttribute('aria-invalid'), 'true', invalid);
  }
  await input.fill('31/01/2030 – 0'); await assertEmptyMirrors(page);
  await input.pressSequentially('4/02/2030');
  await assertRange(page, '2030-01-31', '2030-02-04', '31/01/2030 – 04/02/2030');
  await page.locator('#outside').click();
  await assertRange(page, '2030-01-31', '2030-02-04', '31/01/2030 – 04/02/2030');
  assert.equal(await input.getAttribute('aria-invalid'), 'false');
  await (await openRange(page)).locator('[data-drp-clear]').click();
  await assertRange(page, '', '', '', false);
  const optional = await open(t, { optional: '1' });
  await assertRange(optional, '', '', '');
  await optional.locator('#range').fill('01/01/2030 – 01/01/2030');
  await (await openRange(optional)).locator('[data-drp-clear]').click();
  await assertRange(optional, '', '', '');
  const mdy = await open(t, { order: 'mdy', locale: 'en-US' });
  await mdy.locator('#range').fill('01/31/2030 – 02/04/2030');
  await mdy.locator('#range').press('Tab');
  await assertRange(mdy, '2030-01-31', '2030-02-04', '01/31/2030 – 02/04/2030');
});

test('Leap-year and spring/fall DST ranges count inclusive calendar days rather than elapsed hours', { timeout: 25000 }, async t => {
  for (const [from, to, invalidTo, display, invalidDisplay] of [
    ['2032-02-28', '2032-03-01', '2032-03-02', '28/02/2032 – 01/03/2032', '28/02/2032 – 02/03/2032'],
    ['2030-03-09', '2030-03-11', '2030-03-12', '09/03/2030 – 11/03/2030', '09/03/2030 – 12/03/2030'],
    ['2030-11-02', '2030-11-04', '2030-11-05', '02/11/2030 – 04/11/2030', '02/11/2030 – 05/11/2030'],
  ]) {
    const page = await open(t, { maxDays: '3' }, { timezoneId: 'America/New_York' });
    const input = page.locator('#range');
    await input.fill(display); await assertRange(page, from, to, display);
    const popup = await openRange(page);
    assert.equal(await popup.locator('.is-in-range').count(), 3);
    if (from.startsWith('2032')) assert.equal(await day(popup, '2032-02-29').count(), 1);
    else assert.notEqual(...await page.evaluate(([start, end]) => [new Date(start + 'T12:00:00').getTimezoneOffset(), new Date(end + 'T12:00:00').getTimezoneOffset()], [from, to]), 'The fixture must actually cross a DST transition');
    await popup.press('Escape');
    await input.fill(invalidDisplay); await assertEmptyMirrors(page);
    await input.fill(display); await input.press('Tab');
    await assertRange(page, from, to, display);
    assert.notEqual(await page.locator('#to').inputValue(), invalidTo);
  }
});

test('Min/max constrain clicks, typing and keyboard focus; readonly/disabled states cannot open or publish', { timeout: 25000 }, async t => {
  for (const source of ['data', 'native']) {
    const page = await open(t, { min: '2030-01-10', max: '2030-01-20', ...(source === 'native' ? { nativeBounds: '1' } : {}) });
    const popup = await openRange(page);
    for (const iso of ['2030-01-09', '2030-01-21']) {
      const cell = day(popup, iso);
      assert.equal(await cell.getAttribute('aria-disabled'), 'true');
      assert.match(await cell.getAttribute('class'), /is-disabled/);
      assert.equal(await cell.getAttribute('tabindex'), '-1');
      const box = await cell.boundingBox(); await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
      await assertEmptyMirrors(page);
    }
    assert.equal(await popup.locator('[data-drp-today]').isDisabled(), true);
    await day(popup, '2030-01-10').focus(); await page.keyboard.press('ArrowLeft'); await assertFocusedDay(page, '2030-01-10');
    await day(popup, '2030-01-20').focus(); await page.keyboard.press('ArrowRight'); await assertFocusedDay(page, '2030-01-20');
    await day(popup, '2030-01-10').click(); await day(popup, '2030-01-20').click();
    await assertRange(page, '2030-01-10', '2030-01-20', '10/01/2030 – 20/01/2030');
    for (const invalid of ['09/01/2030 – 20/01/2030', '10/01/2030 – 21/01/2030']) {
      await page.locator('#range').fill(invalid); await assertEmptyMirrors(page);
    }
  }
  const page = await open(t, { readonly: '1', from: '2030-01-10', to: '2030-01-20' });
  await page.locator('#range').click(); await page.locator('#range').press('ArrowDown');
  assert.equal(await (await popupFor(page)).isVisible(), false);
  await assertRange(page, '2030-01-10', '2030-01-20', '10/01/2030 – 20/01/2030');
  await page.locator('#read-only').uncheck(); await openRange(page);
  await page.locator('#disable-range').check();
  await page.waitForFunction(() => document.querySelector('#from').disabled && document.querySelector('#to').disabled);
  assert.equal(await (await popupFor(page)).isVisible(), false);
  assert.deepEqual(await page.locator('#range-form').evaluate(form => [...new FormData(form).entries()]), []);
  const box = await page.locator('#range').boundingBox(); await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  assert.equal(await (await popupFor(page)).isVisible(), false);
  await page.locator('#disable-range').uncheck(); await openRange(page);
  await assertRange(page, '2030-01-10', '2030-01-20', '10/01/2030 – 20/01/2030');
});

test('Keyboard navigation, roving focus, themed month/year dropdowns, Today and Escape preserve the range contract', { timeout: 25000 }, async t => {
  const page = await open(t);
  const input = page.locator('#range');
  await input.press('ArrowDown'); const popup = await popupFor(page);
  await assertFocusedDay(page, '2030-01-31');
  for (const [key, iso] of [['ArrowRight', '2030-02-01'], ['ArrowLeft', '2030-01-31'], ['ArrowUp', '2030-01-24'], ['ArrowDown', '2030-01-31'], ['Home', '2030-01-28'], ['End', '2030-02-03']]) {
    await page.keyboard.press(key); await assertFocusedDay(page, iso);
  }
  await day(popup, '2030-01-31').focus();
  for (const [key, iso] of [['PageDown', '2030-02-28'], ['PageUp', '2030-01-28'], ['Shift+PageDown', '2031-01-28'], ['Shift+PageUp', '2030-01-28']]) {
    await page.keyboard.press(key); await assertFocusedDay(page, iso);
  }
  await page.keyboard.press('Escape');
  assert.equal(await popup.isVisible(), false);
  assert.equal(await input.evaluate(el => el === document.activeElement), true); await assertEmptyMirrors(page);
  await input.press('ArrowDown'); await page.keyboard.press('Enter');
  const partialEvents = await events(page);
  await page.keyboard.press('ArrowRight'); await assertFocusedDay(page, '2030-02-01');
  assert.equal(await popup.locator('.is-preview-end').getAttribute('data-date'), '2030-02-01');
  assert.deepEqual(await events(page), partialEvents); await assertEmptyMirrors(page);
  await page.keyboard.press('Enter'); await assertRange(page, '2030-01-31', '2030-02-01', '31/01/2030 – 01/02/2030');
  await openRange(page); const beforeNavigation = await events(page);
  for (const [attribute, label, option] of [['month', 'Month', 'March'], ['year', 'Year', '2031']]) {
    const select = popup.locator(`select[data-drp-${attribute}]`);
    assert.equal(await select.getAttribute('aria-label'), label);
    const wrapper = popup.locator('.bd-select').filter({ has: page.locator(`select[data-drp-${attribute}]`) });
    assert.equal(await wrapper.locator('.bd-select-toggle').count(), 1);
    await wrapper.locator('.bd-select-toggle').click(); await wrapper.getByRole('option', { name: option, exact: true }).click();
  }
  assert.deepEqual(await popup.locator('.drp-calendar:visible h3').allTextContents(), ['March 2031', 'April 2031']);
  assert.equal(await popup.locator('[data-drp-prev]').getAttribute('aria-label'), 'Previous month');
  assert.equal(await popup.locator('[data-drp-next]').getAttribute('aria-label'), 'Next month');
  await popup.locator('[data-drp-prev]').click(); await popup.locator('[data-drp-next]').click();
  await popup.locator('[data-drp-today]').click();
  assert.deepEqual(await popup.locator('.drp-calendar:visible h3').allTextContents(), ['January 2030', 'February 2030']);
  assert.equal(await popup.isVisible(), true); assert.deepEqual(await events(page), beforeNavigation);
  await assertRange(page, '2030-01-31', '2030-02-01', '31/01/2030 – 01/02/2030');
  const monthToggle = popup.locator('.bd-select').filter({ has: page.locator('select[data-drp-month]') }).locator('.bd-select-toggle');
  await monthToggle.click(); await page.keyboard.press('Escape');
  assert.equal(await monthToggle.getAttribute('aria-expanded'), 'false'); assert.equal(await popup.isVisible(), true);
  await page.keyboard.press('Escape'); assert.equal(await popup.isVisible(), false);
  assert.equal(await input.evaluate(el => el === document.activeElement), true);
  await openRange(page); await popup.locator('[data-drp-close]').click(); assert.equal(await popup.isVisible(), false);
});

test('Month and year menus stay anchored to their controls in desktop/mobile light and dark', { timeout: 30000 }, async t => {
  for (const theme of ['light', 'dark']) for (const width of [1280, 320]) {
    const page = await open(t, { theme }, { viewport: { width, height: 900 } });
    const popup = await openRange(page);
    for (const attribute of ['month', 'year']) {
      const wrapper = popup.locator('.bd-select').filter({ has: page.locator(`select[data-drp-${attribute}]`) });
      const toggle = wrapper.locator('.bd-select-toggle'), menu = wrapper.locator('.bd-select-menu');
      await toggle.click(); await menu.waitFor({ state: 'visible' });
      const measured = await menu.evaluate(menu => {
        const rect = el => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width }; };
        return { menu: rect(menu), toggle: rect(menu.parentElement.querySelector('.bd-select-toggle')),
          popup: rect(menu.closest('.drp-popup')), overflow: menu.scrollWidth > menu.clientWidth + 1 };
      });
      const message = `${theme} ${width}px ${attribute}: ${JSON.stringify(measured)}`;
      t.diagnostic(message);
      assert.ok(Math.abs(measured.menu.width - measured.toggle.width) <= 1, 'Menu width matches its control, not the viewport. ' + message);
      assert.ok(measured.menu.left >= measured.popup.left - 1 && measured.menu.right <= measured.popup.right + 1, 'Menu stays inside the picker. ' + message);
      assert.equal(measured.overflow, false, 'Options do not overflow horizontally. ' + message);
      await page.keyboard.press('Escape');
      assert.equal(await popup.isVisible(), true, 'Closing a menu keeps range selection open.');
    }
  }
});

test('Roomy desktop has two consecutive months; 320px and narrow modal/workspace/container hosts have one in light and dark', { timeout: 40000 }, async t => {
  const colors = {};
  for (const theme of ['light', 'dark']) {
    const page = await open(t, { theme });
    let popup = await openRange(page);
    assert.match(await popup.getAttribute('class'), /is-dual/);
    colors[theme] = await popup.evaluate(el => ({ background: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color }));
    await day(popup, '2030-02-03').focus();
    await page.setViewportSize({ width: 320, height: 800 });
    await page.waitForFunction(() => document.querySelectorAll('.drp-calendar:not([hidden])').length === 1);
    await assertFocusedDay(page, '2030-02-03');
    assert.deepEqual(await popup.locator('.drp-calendar:visible h3').allTextContents(), ['February 2030']);
    assert.equal((await popup.getAttribute('class')).includes('is-dual'), false);
    const bounds = await popup.evaluate(el => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, overflow: el.scrollWidth > el.clientWidth + 1 }; });
    assert.ok(bounds.left >= 0 && bounds.right <= 321 && !bounds.overflow, JSON.stringify(bounds));
    for (const selector of ['[data-drp-start]', '[data-drp-end]', '[data-drp-close]', '[data-drp-today]', '[data-drp-clear]', '.drp-status']) assert.equal(await popup.locator(selector).isVisible(), true);
    await page.setViewportSize({ width: 1280, height: 900 });
    for (const host of ['modal', 'app-modal', 'gs-workspace', 'container']) {
      await navigate(page, { theme, host }); popup = await openRange(page);
      assert.equal(await popup.locator('.drp-calendar:visible').count(), 1, `${theme} narrow ${host}`);
      assert.equal((await popup.getAttribute('class')).includes('is-dual'), false);
      const geometry = await popup.evaluate(el => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, overflow: el.scrollWidth > el.clientWidth + 1 }; });
      assert.ok(geometry.left >= 0 && geometry.right <= 1281 && !geometry.overflow, `${host}: ${JSON.stringify(geometry)}`);
    }
  }
  assert.notEqual(colors.light.background, colors.dark.background, 'The popup must follow the selected theme');
  assert.notEqual(colors.light.color, colors.light.background); assert.notEqual(colors.dark.color, colors.dark.background);
});

test('Root-inclusive idempotent init/destroy, HTMX/page swaps and removal dispose popups/dropdowns without touching existing widgets', { timeout: 30000 }, async t => {
  const page = await open(t);
  const legacy = () => page.locator('#legacy').evaluate(el => ({ html: el.innerHTML, popups: document.querySelectorAll('.dp-popup, .dtp-popup').length }));
  const originalLegacy = await legacy();
  assert.equal(await page.locator('#single').inputValue(), '15/01/2030');
  assert.equal(await page.locator('#datetime').inputValue(), '15/01/2030 08:30 AM');
  assert.equal(await page.locator('#time').inputValue(), '08:30 AM');
  await page.evaluate(() => {
    const input = document.querySelector('#range');
    BalayDateRangePicker.destroy(input); BalayDateRangePicker.destroy(input);
    for (let i = 0; i < 3; i++) { BalayDateRangePicker.init(input); BalayDateRangePicker.init(document.querySelector('#main')); }
  });
  assert.equal(await page.locator('#range-form .drp-wrap').count(), 1); assert.equal(await page.locator('.drp-popup').count(), 1);
  for (const lifecycle of ['htmx', 'page', 'observer']) {
    await page.evaluate(lifecycle => {
      const fragment = document.createElement('section'); fragment.id = 'fragment';
      fragment.innerHTML = '<input id="dynamic-range" class="form-control" data-daterangepicker data-target="#dynamic-from" data-range-end="#dynamic-to" required><input id="dynamic-from" type="hidden"><input id="dynamic-to" type="hidden">';
      document.querySelector('#dynamic').append(fragment);
      const input = fragment.querySelector('input');
      for (let i = 0; i < 3; i++) {
        if (lifecycle === 'page') document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: fragment } }));
        else input.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: input } }));
        BalayDateRangePicker.init(input);
      }
    }, lifecycle);
    assert.equal(await page.locator('#fragment .drp-wrap').count(), 1); assert.equal(await page.locator('.drp-popup').count(), 2);
    const popup = await openRange(page, '#dynamic-range');
    const toggle = popup.locator('.bd-select').filter({ has: page.locator('select[data-drp-month]') }).locator('.bd-select-toggle');
    await toggle.click();
    await page.evaluate(lifecycle => {
      const fragment = document.querySelector('#fragment'), input = fragment.querySelector('#dynamic-range');
      const popup = document.getElementById(input.getAttribute('aria-controls'));
      window.retiredRange = { input, popup, toggle: popup.querySelector('.bd-select-toggle'), select: popup.querySelector('select') };
      if (lifecycle === 'page') document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: fragment } }));
      if (lifecycle === 'htmx') input.dispatchEvent(new CustomEvent('htmx:beforeCleanupElement', { bubbles: true, detail: { elt: input } }));
      fragment.remove();
    }, lifecycle);
    await page.waitForFunction(() => document.querySelectorAll('.drp-popup').length === 1);
    assert.deepEqual(await page.evaluate(() => ({ popupRemoved: !retiredRange.popup.isConnected, dropdownDisposed: bootstrap.Dropdown.getInstance(retiredRange.toggle) === null, selectRestored: !retiredRange.select.hasAttribute('data-bd-select'), inputRestored: !retiredRange.input.hasAttribute('aria-controls') })), { popupRemoved: true, dropdownDisposed: true, selectRestored: true, inputRestored: true });
  }
  assert.deepEqual(await legacy(), originalLegacy, 'The independent range component must not rewrap or rewrite existing single/date/time widgets');
  const popup = await openRange(page);
  const baseline = (await events(page)).length;
  await day(popup, '2030-01-31').click(); await day(popup, '2030-02-01').click();
  assert.equal((await events(page)).slice(baseline).filter(event => event.id === 'range' && event.type === 'change').length, 1, 'Reinitialization must not duplicate commit listeners');
  await page.setViewportSize({ width: 900, height: 700 });
});
