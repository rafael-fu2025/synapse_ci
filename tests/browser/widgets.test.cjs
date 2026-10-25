'use strict';

// Run from the repository: node --test tests/browser/widgets.test.cjs
// No application server, authentication, external requests, or generated files.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { once } = require('node:events');

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
let fixtureId = 0;
const requests = [];

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
  assert.ok(fs.existsSync(expected), `Chromium installation did not create ${expected}`);
  return expected;
}

function fixture(url) {
  const initial = url.searchParams.get('initial') || '2030-01-15';
  assert.match(initial, /^\d{4}-\d{2}-\d{2}$/);
  const required = url.searchParams.has('required') ? 'required' : '';
  const bounds = url.searchParams.has('bounded') ? 'data-min="2026-01-01" data-max="2032-12-31"' : '';
  const htmx = url.searchParams.has('htmx') ?
    `hx-get="/slots?fixture=${url.searchParams.get('id')}" hx-trigger="change" hx-swap="none"` : '';
  return `<!doctype html><html lang="en-GB" data-theme="light"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>main{max-width:640px;margin:32px;padding:16px}label{display:block}#outside{margin:16px}#select-help{display:block}</style>
    <script defer src="/assets/vendor/alpine/alpine.min.js"></script>
    <script defer src="/assets/vendor/htmx/htmx.min.js"></script>
  </head><body><main>
    <form id="form" x-data="{ date: '${initial}', fetchSlots() { window.slotCalls.push(this.date) } }">
      <label for="date">Appointment date</label>
      <input id="date" class="form-control" data-datepicker data-target="#iso" ${bounds} ${required}
        @change="fetchSlots()">
      <input id="iso" type="hidden" name="date" value="${initial}" x-model="date" ${htmx}>
      <output id="model" x-text="date"></output>
      <label id="select-label" for="choice">Appointment type</label>
      <small id="select-help">Choose an available type</small>
      <select id="choice" name="choice" class="form-select form-select-sm mb-2" style="width:240px;border-radius:9px"
        required aria-labelledby="select-label" aria-describedby="select-help">
        <option value="" selected>Choose type</option>
        <option value="blocked" disabled>Blocked option</option>
        <optgroup label="Unavailable group" disabled><option value="group-blocked">Blocked group option</option></optgroup>
        <optgroup label="Available group"><option value="a">Available A</option><option value="b">Available B</option></optgroup>
      </select>
      <button id="reset" type="reset">Reset</button>
    </form>
    <div id="dynamic"></div>
    <button id="outside" type="button">Outside</button>
  </main>
  <script>
    window.slotCalls = []; window.widgetEvents = []; window.htmxCompleted = 0;
    for (const type of ['input', 'change']) document.addEventListener(type, event => {
      if (['date', 'iso', 'choice'].includes(event.target.id)) window.widgetEvents.push({
        id: event.target.id, type, value: event.target.value, iso: document.querySelector('#iso').value
      });
    });
    document.addEventListener('htmx:afterRequest', () => window.htmxCompleted++);
  </script>
  <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="/assets/js/custom-dropdown.js"></script>
  <script src="/assets/js/datepicker.js"></script>
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
    if (url.pathname === '/slots') {
      requests.push(url);
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<span>Slots refreshed</span>');
      return;
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  const executable = executablePath();
  console.log(`Widget tests Chromium: ${executable}`);
  browser = await playwright.chromium.launch({ headless: true, executablePath: executable, timeout: 20_000 });
}, { timeout: 120_000 });

after(async () => {
  try { if (browser) await browser.close(); }
  finally {
    if (server?.listening) await new Promise((resolve, reject) => {
      server.close(error => error ? reject(error) : resolve());
      server.closeAllConnections();
    });
  }
});

async function open(t, options = {}) {
  const context = await browser.newContext({ viewport: { width: 1000, height: 800 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  page.setDefaultTimeout(4_000);
  page.setDefaultNavigationTimeout(8_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.status() >= 400) errors.push(`HTTP ${response.status()}: ${response.url()}`);
  });
  await context.route('**/*', route => {
    const url = route.request().url();
    return url.startsWith(origin + '/') ? route.continue() : route.abort();
  });
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, [], 'Fixture scripts/assets must load without browser errors');
  });
  const id = String(++fixtureId);
  const query = new URLSearchParams({ id, ...options });
  await page.goto(`${origin}/fixture?${query}`);
  await page.waitForFunction(() => window.Alpine && window.htmx &&
    document.querySelector('#date').closest('.dp-wrap') && document.querySelector('#choice').dataset.bdSelect);
  await page.locator('#model').waitFor({ state: 'visible' });
  await settle(page);
  await page.evaluate(() => { window.widgetEvents = []; window.slotCalls = []; window.htmxCompleted = 0; });
  return { page, id };
}

async function settle(page) {
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(resolve, 0)))));
}
const source = (page, select) => page.locator('select').and(page.locator(select));
const toggle = (page, select = '#choice') => source(page, select).locator('..').locator('.bd-select-toggle');
const menu = (page, select = '#choice') => source(page, select).locator('..').locator('.bd-select-menu');
async function choose(page, select, label) {
  if (await toggle(page, select).getAttribute('aria-expanded') !== 'true') await toggle(page, select).click();
  await menu(page, select).getByRole('option', { name: label, exact: true }).click();
  // Isolate value/reset tests from auto-close, asserted separately below.
  if (await toggle(page, select).getAttribute('aria-expanded') === 'true') {
    await toggle(page, select).focus();
    await page.keyboard.press('Escape');
  }
}
async function events(page, id, type = 'change') {
  return page.evaluate(({ id, type }) => window.widgetEvents.filter(event => event.id === id && event.type === type), { id, type });
}
async function validDate(page) {
  return page.locator('#date').evaluate(input => input.checkValidity());
}
async function openCalendar(page) {
  await page.locator('#date').focus();
  await page.keyboard.press('ArrowDown');
  await page.locator('.dp-popup').waitFor({ state: 'visible' });
}
function check(name, fn) { test(name, { timeout: 15_000 }, fn); }

check('calendar click preserves numeric display, ISO and target-before-visible event order; same date is silent', async t => {
  const { page } = await open(t);
  await openCalendar(page);
  assert.equal(await page.locator('.dp-weekdays th').first().getAttribute('abbr'), 'Monday');
  await page.locator('td[data-date="2030-01-22"]').click();
  assert.equal(await page.locator('#date').inputValue(), '22/01/2030');
  assert.equal(await page.locator('#iso').inputValue(), '2030-01-22');
  const log = await page.evaluate(() => window.widgetEvents);
  assert.deepEqual(log.map(e => `${e.id}:${e.type}`), ['iso:input', 'iso:change', 'date:input', 'date:change']);
  assert.ok(log.every(e => e.iso === '2030-01-22'), JSON.stringify(log));
  assert.equal(await page.locator('#date').getAttribute('aria-expanded'), 'false');
  await openCalendar(page);
  await page.locator('td[data-date="2030-01-22"]').click();
  assert.deepEqual(await page.evaluate(() => window.widgetEvents), log, 'Re-selecting the same ISO emits no events');
});

check('actual Alpine hidden x-model is new before the calendar visible @change runs, exactly once', async t => {
  const { page } = await open(t);
  await openCalendar(page);
  await page.locator('td[data-date="2030-01-23"]').click();
  await settle(page);
  assert.equal(await page.locator('#model').textContent(), '2030-01-23');
  assert.deepEqual(await page.evaluate(() => window.slotCalls), ['2030-01-23']);
});

check('typing updates ISO and Alpine immediately; native change plus blur causes one HTMX request', async t => {
  const { page, id } = await open(t, { htmx: '' });
  await page.locator('#date').fill('');
  await page.locator('#date').pressSequentially('24/01/2030');
  await settle(page);
  assert.equal(await page.locator('#iso').inputValue(), '2030-01-24');
  assert.equal(await page.locator('#model').textContent(), '2030-01-24');
  assert.deepEqual(await page.evaluate(() => window.slotCalls), [], 'Editing must not commit prematurely');
  const response = page.waitForResponse(r => r.url().includes('/slots?') && r.status() === 200);
  await page.locator('#outside').click();
  await response;
  await page.waitForFunction(() => window.htmxCompleted === 1);
  await page.locator('#date').focus();
  await page.locator('#outside').click();
  await settle(page);
  assert.equal((await events(page, 'iso')).length, 1);
  assert.equal((await events(page, 'date')).length, 1);
  assert.deepEqual(await page.evaluate(() => window.slotCalls), ['2030-01-24']);
  const hits = requests.filter(url => url.searchParams.get('fixture') === id);
  assert.equal(hits.length, 1, 'HTMX hidden change must issue exactly one request');
  assert.equal(hits[0].searchParams.get('date'), '2030-01-24');
});

check('date entry blocks letters, symbols, decimals and overlong pastes without changing the date', async t => {
  const { page } = await open(t);
  const input = page.locator('#date');
  await input.focus();
  await page.keyboard.press('End');
  await input.pressSequentially('abc!@#.$%');
  assert.equal(await input.inputValue(), '15/01/2030');
  for (const text of ['abc', '24.01.2030', '24/01/2030!', '24012030000']) {
    const prevented = await input.evaluate((element, text) => {
      element.select();
      const clipboardData = new DataTransfer();
      clipboardData.setData('text/plain', text);
      return !element.dispatchEvent(new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true }));
    }, text);
    assert.equal(prevented, true, `Reject paste: ${text}`);
    assert.equal(await input.inputValue(), '15/01/2030');
    assert.equal(await page.locator('#iso').inputValue(), '2030-01-15');
  }
  await input.fill('24012030');
  await page.locator('#outside').click();
  assert.equal(await input.inputValue(), '24/01/2030');
  assert.equal(await page.locator('#iso').inputValue(), '2030-01-24');
});

check('non-cancelable invalid input clears the date rather than coercing it', async t => {
  const { page } = await open(t, { required: '' });
  await page.locator('#date').evaluate(input => {
    input.value = '24.01.2030';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  assert.equal(await page.locator('#date').inputValue(), '');
  assert.equal(await page.locator('#iso').inputValue(), '');
  assert.equal(await validDate(page), false);
});

for (const [name, text] of [
  ['partial', '12/'], ['non-leap February', '29/02/2030'], ['impossible day', '31/04/2030'],
  ['below minimum', '31/12/2025'], ['above maximum', '01/01/2033']
]) {
  check(`invalid ${name} text blocks submission and clears stale ISO/model`, async t => {
    const { page } = await open(t, { bounded: '' });
    await page.locator('#date').fill(text);
    assert.equal(await page.locator('#iso').inputValue(), '', 'Invalid editing must clear the previous ISO immediately');
    await page.locator('#outside').click();
    await settle(page);
    assert.equal(await page.locator('#model').textContent(), '');
    assert.equal(await validDate(page), false);
    assert.equal(await page.locator('#date').getAttribute('aria-invalid'), 'true');
    assert.ok(await page.locator('#date').evaluate(input => input.validationMessage.length > 0));
    // Make the unrelated required select valid so this tests date form validity.
    await choose(page, '#choice', 'Available A');
    assert.equal(await page.locator('#form').evaluate(form => form.checkValidity()), false);
  });
}

check('required whitespace-only date stays invalid after blur and clears stale ISO/model', async t => {
  const { page } = await open(t, { required: '' });
  await choose(page, '#choice', 'Available A');
  await page.locator('#date').evaluate(input => {
    input.value = '   ';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await page.locator('#outside').click();
  await settle(page);
  assert.equal(await page.locator('#iso').inputValue(), '');
  assert.equal(await page.locator('#model').textContent(), '');
  assert.equal(await validDate(page), false);
  assert.equal(await page.locator('#date').evaluate(input => input.validity.customError), true,
    'Whitespace must be rejected by widget validation, not just native valueMissing');
  assert.equal(await page.locator('#date').getAttribute('aria-invalid'), 'true');
  assert.ok(await page.locator('#date').evaluate(input => input.validationMessage.length > 0));
  assert.equal(await page.locator('#form').evaluate(form => form.checkValidity()), false);
});

for (const required of [false, true]) {
  check(`calendar Clear empties ISO/model and ${required ? 'blocks required' : 'allows optional'} date`, async t => {
    const { page } = await open(t, required ? { required: '' } : {});
    await openCalendar(page);
    await page.locator('[data-dp-clear]').click();
    await settle(page);
    assert.equal(await page.locator('#date').inputValue(), '');
    assert.equal(await page.locator('#iso').inputValue(), '');
    assert.equal(await page.locator('#model').textContent(), '');
    assert.equal(await validDate(page), !required);
    assert.equal((await events(page, 'iso')).length, 1);
    await openCalendar(page);
    await page.locator('[data-dp-clear]').click();
    assert.equal((await events(page, 'iso')).length, 1, 'Repeated Clear is silent');
  });
}

check('month dropdown clamps January 31 to February 28 without rolling into March', async t => {
  const { page } = await open(t, { initial: '2030-01-31' });
  await openCalendar(page);
  await choose(page, '.dp-month', 'February');
  assert.equal(await source(page, '.dp-month').inputValue(), '1');
  assert.equal(await source(page, '.dp-year').inputValue(), '2030');
  assert.equal(await page.locator('td[tabindex="0"]').getAttribute('data-date'), '2030-02-28');
  await page.locator('td[data-date="2030-02-28"]').click();
  assert.equal(await page.locator('#date').inputValue(), '28/02/2030');
  assert.equal(await page.locator('#iso').inputValue(), '2030-02-28');
});

check('year dropdown clamps leap day and permits a default future year through current + 10', async t => {
  const { page } = await open(t, { initial: '2028-02-29' });
  await openCalendar(page);
  await choose(page, '.dp-year', '2029');
  assert.equal(await page.locator('td[tabindex="0"]').getAttribute('data-date'), '2029-02-28');
  const future = await page.evaluate(() => new Date().getFullYear() + 10);
  await choose(page, '.dp-year', String(future));
  assert.equal(await source(page, '.dp-year').inputValue(), String(future));
  await page.locator(`td[data-date="${future}-02-28"]`).click();
  assert.equal(await page.locator('#iso').inputValue(), `${future}-02-28`);
});

for (const scenario of [
  { label: 'Required empty choice', required: true, placeholder: true },
  { label: 'Provider prompt', disabled: true, placeholder: true },
  { label: 'Provider prompt', hidden: true, placeholder: true },
  { label: '-- Choose provider --', placeholder: true },
  { label: 'Select provider', placeholder: true },
  { label: 'Pick provider', placeholder: true },
  { label: 'Provider prompt', marked: true, placeholder: true },
  { label: 'All providers', placeholder: false },
  { label: 'None / unassigned', placeholder: false }
]) {
  check(`dropdown placeholder classification: ${scenario.label}${scenario.required ? ' (required)' : ''}`, async t => {
    const { page } = await open(t);
    await source(page, '#choice').evaluate((select, scenario) => {
      select.required = !!scenario.required;
      const option = new Option(scenario.label, '', true, true);
      option.disabled = !!scenario.disabled;
      option.hidden = !!scenario.hidden;
      if (scenario.marked) option.setAttribute('data-placeholder', '');
      select.replaceChildren(option, new Option('Provider A', 'a'));
    }, scenario);
    await settle(page);
    assert.equal(await toggle(page).locator('.bd-select-label').textContent(), scenario.label);
    assert.equal(await toggle(page).locator('.bd-placeholder').count(), Number(scenario.placeholder));
    assert.equal(await source(page, '#choice').locator('option').count(), 2, 'Native placeholder must remain');
    assert.equal(await source(page, '#choice').evaluate(el => el.validity.valueMissing), !!scenario.required);
    assert.deepEqual(await menu(page).locator('[role="option"]').allTextContents(),
      scenario.placeholder ? ['Provider A'] : [scenario.label, 'Provider A']);
    await choose(page, '#choice', 'Provider A');
    assert.equal(await source(page, '#choice').inputValue(), 'a');
    if (!scenario.placeholder) {
      await choose(page, '#choice', scenario.label);
      assert.equal(await source(page, '#choice').inputValue(), '');
      assert.equal(await source(page, '#choice').evaluate(el => el.validity.valid), true);
      assert.equal(await page.locator('#form').evaluate(el => new FormData(el).get('choice')), '');
    }
    const beforeReset = await events(page, 'choice');
    await page.locator('#reset').click();
    await settle(page);
    assert.equal(await source(page, '#choice').inputValue(), '');
    assert.equal(await toggle(page).locator('.bd-select-label').textContent(), scenario.label);
    assert.deepEqual(await events(page, 'choice'), beforeReset);
    assert.equal(await menu(page).locator('[role="option"]').count(), scenario.placeholder ? 1 : 2);
  });
}

check('dropdown rebuilds placeholder classification without hiding grouped or nonempty choices', async t => {
  const { page } = await open(t);
  await source(page, '#choice').evaluate(select => {
    select.required = false;
    select.innerHTML = `<option value="" selected>All providers</option>
      <option value="choose">-- Choose provider --</option>
      <optgroup label="Providers"><option value="">None / unassigned</option>
        <option value="a">Provider A</option></optgroup>`;
  });
  await settle(page);
  const labels = () => menu(page).locator('[role="option"]').allTextContents();
  assert.deepEqual(await labels(), ['All providers', '-- Choose provider --', 'None / unassigned', 'Provider A']);
  assert.equal(await menu(page).locator('[role="group"]').getAttribute('aria-label'), 'Providers');
  await choose(page, '#choice', '-- Choose provider --');
  assert.equal(await source(page, '#choice').inputValue(), 'choose', 'Nonempty prompt text is a real choice');
  await choose(page, '#choice', 'None / unassigned');
  assert.equal(await source(page, '#choice').evaluate(el => el.selectedIndex), 2);

  await source(page, '#choice').evaluate(select => { select.options[0].textContent = '-- Choose provider --'; });
  await settle(page);
  assert.deepEqual(await labels(), ['-- Choose provider --', 'None / unassigned', 'Provider A']);
  await source(page, '#choice').evaluate(select => { select.options[0].label = 'All providers'; });
  await settle(page);
  assert.equal((await labels())[0], 'All providers');
  await source(page, '#choice').evaluate(select => { select.required = true; });
  await settle(page);
  assert.deepEqual(await labels(), ['-- Choose provider --', 'None / unassigned', 'Provider A']);
  await source(page, '#choice').evaluate(select => {
    select.required = false;
    select.options[0].setAttribute('data-placeholder', '');
  });
  await settle(page);
  assert.deepEqual(await labels(), ['-- Choose provider --', 'None / unassigned', 'Provider A']);
  await source(page, '#choice').evaluate(select => { select.options[0].removeAttribute('data-placeholder'); });
  await settle(page);
  assert.equal((await labels())[0], 'All providers');
  await source(page, '#choice').evaluate(select => {
    select.innerHTML = '<option value="" selected>-- Pick provider --</option><optgroup label="New providers"><option value="b">Provider B</option></optgroup>';
  });
  await settle(page);
  assert.deepEqual(await labels(), ['Provider B']);
  assert.equal(await toggle(page).locator('.bd-select-label').textContent(), '-- Pick provider --');
  await choose(page, '#choice', 'Provider B');
  assert.equal(await source(page, '#choice').inputValue(), 'b');
});

check('dropdown real clicks respect disabled options/groups, mirror small width/style and ARIA links', async t => {
  const { page } = await open(t);
  const button = toggle(page);
  assert.equal(await button.getAttribute('aria-required'), 'true');
  assert.equal(await button.getAttribute('aria-describedby'), 'select-help');
  assert.match(await button.getAttribute('aria-label'), /Appointment type/);
  assert.match(await button.getAttribute('class'), /form-select-sm/);
  assert.equal(await button.evaluate(el => getComputedStyle(el).borderRadius), '9px');
  assert.ok(Math.abs((await button.boundingBox()).width - 240) < 1);
  const controls = await button.getAttribute('aria-controls');
  assert.equal(await menu(page).getAttribute('id'), controls);
  assert.equal(await menu(page).getAttribute('aria-required'), 'true');
  assert.equal(await toggle(page).locator('.bd-select-label').textContent(), 'Choose type');
  assert.equal(await menu(page).locator('[role="option"]').filter({ hasText: 'Choose type' }).count(), 0);
  await button.click();
  assert.equal(await button.getAttribute('aria-expanded'), 'true');
  for (const name of ['Blocked option', 'Blocked group option']) {
    if (await button.getAttribute('aria-expanded') !== 'true') await button.click();
    const option = menu(page).getByRole('option', { name, exact: true });
    assert.equal(await option.isDisabled(), true);
    assert.equal(await option.getAttribute('aria-disabled'), 'true');
    const box = await option.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    assert.equal(await page.locator('#choice').inputValue(), '');
  }
  assert.equal((await events(page, 'choice')).length, 0);
  if (await button.getAttribute('aria-expanded') !== 'true') await button.click();
  await menu(page).getByRole('option', { name: 'Available B', exact: true }).click();
  assert.equal(await page.locator('#choice').inputValue(), 'b');
  assert.equal((await events(page, 'choice')).length, 1);
  assert.equal(await menu(page).getByRole('option', { name: 'Available B', exact: true, includeHidden: true }).getAttribute('aria-selected'), 'true');
  assert.equal(await button.getAttribute('aria-expanded'), 'false', 'Selecting an enabled option should close the dropdown');
});

check('required select checkValidity/reportValidity expose a linked error and focus the visible trigger', async t => {
  const { page } = await open(t);
  assert.equal(await page.locator('#choice').evaluate(el => el.checkValidity()), false);
  assert.equal(await page.locator('#form').evaluate(el => el.reportValidity()), false);
  await settle(page);
  const button = toggle(page);
  assert.equal(await button.evaluate(el => el === document.activeElement), true);
  assert.equal(await button.getAttribute('aria-invalid'), 'true');
  const errorId = await button.getAttribute('aria-errormessage');
  assert.ok(errorId);
  assert.ok((await button.getAttribute('aria-describedby')).split(' ').includes(errorId));
  const error = page.locator(`[id="${errorId}"]`);
  assert.equal(await error.isVisible(), true);
  assert.ok((await error.textContent()).length > 0);
  await choose(page, '#choice', 'Available A');
  assert.equal(await page.locator('#form').evaluate(el => el.checkValidity()), true);
  assert.equal(await button.getAttribute('aria-invalid'), 'false');
  assert.equal(await error.isVisible(), false);
});

check('appended options, option/group/fieldset disabling and form reset synchronize without synthetic changes', async t => {
  const { page } = await open(t);
  await page.locator('#choice').evaluate(select => {
    select.add(new Option('Appended', 'new'));
    select.querySelector('option[value="blocked"]').disabled = false;
    select.querySelector('optgroup[disabled]').disabled = false;
  });
  await settle(page);
  await choose(page, '#choice', 'Appended');
  assert.equal(await page.locator('#choice').inputValue(), 'new');
  await choose(page, '#choice', 'Blocked group option');
  assert.equal(await page.locator('#choice').inputValue(), 'group-blocked');
  await page.locator('#choice').evaluate(select => { select.disabled = true; });
  await settle(page);
  assert.equal(await toggle(page).isDisabled(), true);
  await page.locator('#choice').evaluate(select => {
    select.disabled = false;
    const fieldset = document.createElement('fieldset');
    select.parentElement.before(fieldset);
    fieldset.append(select.parentElement);
    fieldset.disabled = true;
  });
  await settle(page);
  assert.equal(await toggle(page).isDisabled(), true, 'Inherited disabled fieldset must disable the trigger');
  await page.locator('#choice').evaluate(select => { select.closest('fieldset').disabled = false; });
  await settle(page);
  assert.equal(await toggle(page).isDisabled(), false);
  const beforeReset = await events(page, 'choice');
  await page.locator('#reset').click();
  await settle(page);
  assert.equal(await page.locator('#choice').inputValue(), '');
  assert.equal(await toggle(page).locator('.bd-select-label').textContent(), 'Choose type');
  assert.deepEqual(await events(page, 'choice'), beforeReset, 'Native reset must not synthesize change');
});

check('date form reset restores ISO/display/model with one hidden input, no change or fetchSlots', async t => {
  const initialISO = '2030-01-15';
  const { page } = await open(t);
  await page.locator('#date').fill('25/01/2030');
  await page.locator('#outside').click();
  await page.evaluate(() => { window.widgetEvents = []; window.slotCalls = []; });
  await page.locator('#reset').click();
  await settle(page);
  assert.deepEqual(await page.evaluate(() => ({
    display: document.querySelector('#date').value,
    iso: document.querySelector('#iso').value,
    model: document.querySelector('#model').textContent
  })), { display: '15/01/2030', iso: initialISO, model: initialISO }, 'Reset must restore the original date, not the edited hidden defaultValue');
  assert.deepEqual(await page.evaluate(() => window.widgetEvents), [
    { id: 'iso', type: 'input', value: initialISO, iso: initialISO }
  ], 'Reset emits exactly one hidden input for model sync, with no synthetic change');
  assert.deepEqual(await page.evaluate(() => window.slotCalls), [], 'Reset must not fetch slots');
});

check('repeated htmx:load/init upgrades dynamic roots once; removed controls dispose popup and Bootstrap instances', async t => {
  const { page } = await open(t);
  const originalPopups = await page.locator('.dp-popup').count();
  await page.evaluate(() => {
    const root = document.querySelector('#dynamic');
    root.innerHTML = '<section id="fragment"><select id="dynamic-select" class="form-select"><option>Dynamic</option></select>' +
      '<input id="dynamic-date" class="form-control" data-datepicker data-target="#dynamic-iso">' +
      '<input type="hidden" id="dynamic-iso" value="2030-03-01"></section>';
    for (let i = 0; i < 3; i++) {
      htmx.trigger(root, 'htmx:load', { elt: root });
      BalaySelect.init(root); BalayDatepicker.init(root);
      BalaySelect.init(document.querySelector('#dynamic-select'));
      BalayDatepicker.init(document.querySelector('#dynamic-date'));
    }
    window.removedWidgets = {
      select: document.querySelector('#dynamic-select'), date: document.querySelector('#dynamic-date'),
      button: document.querySelector('#dynamic-select').parentElement.querySelector('.bd-select-toggle')
    };
  });
  assert.equal(await page.locator('#fragment .dp-wrap').count(), 1);
  assert.equal(await page.locator('#fragment .bd-select-toggle').count(), 1);
  assert.equal(await page.locator('.dp-popup').count(), originalPopups + 1);
  await toggle(page, '#dynamic-select').click();
  await page.keyboard.press('Escape');
  await page.locator('#dynamic-date').focus();
  await page.keyboard.press('ArrowDown');
  assert.equal(await page.locator('#dynamic-date').getAttribute('aria-expanded'), 'true');
  await page.locator('#fragment').evaluate(el => el.remove());
  await page.waitForFunction(count => document.querySelectorAll('.dp-popup').length === count, originalPopups);
  assert.deepEqual(await page.evaluate(() => ({
    dropdownDisposed: bootstrap.Dropdown.getInstance(window.removedWidgets.button) === null,
    selectRestored: !window.removedWidgets.select.hasAttribute('data-bd-select') && !window.removedWidgets.select.classList.contains('bd-select-source'),
    dateRestored: !window.removedWidgets.date.hasAttribute('aria-controls')
  })), { dropdownDisposed: true, selectRestored: true, dateRestored: true });
  // Explicit select destroy is idempotent, restores its source, and allows re-init.
  await page.evaluate(() => { BalaySelect.destroy(document.querySelector('#choice')); BalaySelect.destroy(document.querySelector('#choice')); });
  assert.equal(await page.locator('#choice').getAttribute('aria-hidden'), null);
  await page.evaluate(() => BalaySelect.init(document.querySelector('#choice')));
  assert.equal(await page.locator('#choice').locator('..').locator('.bd-select-toggle').count(), 1);
});

check('keyboard navigation and Escape dismiss menus/calendar without changing values', async t => {
  const { page } = await open(t);
  await toggle(page).focus();
  await page.keyboard.press('ArrowDown');
  assert.equal(await menu(page).isVisible(), true);
  await page.keyboard.press('Escape');
  assert.equal(await toggle(page).getAttribute('aria-expanded'), 'false');
  assert.equal(await toggle(page).evaluate(el => el === document.activeElement), true);
  await openCalendar(page);
  await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('td:focus').getAttribute('data-date'), '2030-01-16');
  await toggle(page, '.dp-month').click();
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('.dp-popup').isVisible(), true, 'First Escape only closes the month dropdown');
  assert.equal(await toggle(page, '.dp-month').getAttribute('aria-expanded'), 'false');
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('.dp-popup').isVisible(), false);
  assert.equal(await page.locator('#date').evaluate(el => el === document.activeElement), true);
  assert.equal(await page.locator('#iso').inputValue(), '2030-01-15');
  assert.equal((await events(page, 'iso')).length, 0);
});

check('Escape closes nested month menu then calendar without closing a real Bootstrap modal or trapping focus', async t => {
  const { page } = await open(t);
  await page.evaluate(async () => {
    document.body.insertAdjacentHTML('beforeend', '<div id="widget-modal" class="modal" tabindex="-1" aria-labelledby="modal-title">' +
      '<div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 id="modal-title">Appointment</h2></div>' +
      '<div class="modal-body"><label for="modal-date">Modal date</label>' +
      '<input id="modal-date" class="form-control" data-datepicker data-target="#modal-iso">' +
      '<input id="modal-iso" type="hidden" value="2030-01-15">' +
      '<label for="modal-note">Note</label><input id="modal-note" class="form-control">' +
      '<button id="modal-action" type="button">Apply</button></div></div></div></div>');
    const modal = document.querySelector('#widget-modal');
    window.modalHideCount = 0; window.modalActionCount = 0;
    modal.addEventListener('hide.bs.modal', () => window.modalHideCount++);
    modal.querySelector('#modal-action').addEventListener('click', () => window.modalActionCount++);
    BalaySelect.init(modal); BalayDatepicker.init(modal);
    const shown = new Promise(resolve => modal.addEventListener('shown.bs.modal', resolve, { once: true }));
    new bootstrap.Modal(modal).show();
    await shown;
  });
  const modal = page.locator('#widget-modal');
  const popup = modal.locator('.dp-popup');
  const month = '#widget-modal select.dp-month';
  await page.locator('#modal-date').focus();
  await page.keyboard.press('ArrowDown');
  assert.equal(await popup.isVisible(), true);
  await toggle(page, month).click();
  await page.keyboard.press('ArrowDown');
  assert.equal(await menu(page, month).isVisible(), true);
  assert.equal(await menu(page, month).evaluate(el => el.contains(document.activeElement)), true);
  await page.keyboard.press('Escape');
  await settle(page);
  assert.equal(await toggle(page, month).getAttribute('aria-expanded'), 'false');
  assert.equal(await menu(page, month).isVisible(), false);
  assert.equal(await popup.isVisible(), true, 'First Escape must only close the month dropdown');
  assert.equal(await modal.isVisible(), true);
  assert.equal(await modal.evaluate(el => el.classList.contains('show') && el.contains(document.activeElement)), true);
  assert.equal(await toggle(page, month).evaluate(el => el === document.activeElement), true);
  assert.equal(await page.evaluate(() => window.modalHideCount), 0);
  await page.keyboard.press('Escape');
  await settle(page);
  assert.equal(await popup.isVisible(), false, 'Second Escape must only close the calendar');
  assert.equal(await page.locator('#modal-date').getAttribute('aria-expanded'), 'false');
  assert.equal(await modal.isVisible(), true);
  assert.equal(await modal.evaluate(el => el.classList.contains('show')), true);
  assert.equal(await page.locator('#modal-date').evaluate(el => el === document.activeElement), true);
  assert.equal(await page.evaluate(() => window.modalHideCount), 0, 'Neither Escape may start hiding the modal');
  assert.equal(await page.locator('#modal-iso').inputValue(), '2030-01-15');
  await page.keyboard.press('Tab');
  assert.equal(await page.locator('#modal-note').evaluate(el => el === document.activeElement), true);
  await page.keyboard.type('Focus still works');
  assert.equal(await page.locator('#modal-note').inputValue(), 'Focus still works');
  await page.locator('#modal-action').click();
  assert.equal(await page.evaluate(() => window.modalActionCount), 1);
  assert.equal(await modal.evaluate(el => el.contains(document.activeElement)), true);
  await page.evaluate(async () => {
    const modal = document.querySelector('#widget-modal');
    const instance = bootstrap.Modal.getInstance(modal);
    const hidden = new Promise(resolve => modal.addEventListener('hidden.bs.modal', resolve, { once: true }));
    instance.hide(); await hidden; instance.dispose();
  });
});

async function addRange(page) {
  await page.evaluate(() => {
    const form = document.createElement('form');
    form.id = 'range-form';
    form.innerHTML = '<label for="range">Export date range</label>' +
      '<input id="range" class="form-control" data-datepicker data-target="#range-start" data-range-end="#range-end" data-max-days="31" required>' +
      '<input id="range-start" type="hidden" name="from" value="2030-01-15">' +
      '<input id="range-end" type="hidden" name="to" value="2030-01-20">' +
      '<button type="reset" id="range-reset">Reset range</button>';
    document.querySelector('#dynamic').append(form);
    BalayDatepicker.init(form);
  });
}

check('date range selects endpoints, highlights days, clears and resets both ISO values', async t => {
  const { page } = await open(t);
  await addRange(page);
  const input = page.locator('#range');
  const popup = page.locator('.dp-range');
  assert.equal(await input.inputValue(), '15/01/2030 – 20/01/2030');
  await input.press('ArrowDown');
  await popup.locator('[data-date="2030-01-10"]').click();
  assert.equal(await popup.isVisible(), true);
  assert.equal(await page.locator('#range-start').inputValue(), '');
  assert.equal(await page.locator('#range-end').inputValue(), '');
  assert.equal(await input.evaluate(el => el.checkValidity()), false);
  await popup.locator('[data-date="2030-01-14"]').click();
  assert.equal(await input.inputValue(), '10/01/2030 – 14/01/2030');
  assert.deepEqual(await page.locator('#range-form').evaluate(el => [...new FormData(el).entries()]), [['from', '2030-01-10'], ['to', '2030-01-14']]);
  await input.press('ArrowDown');
  assert.equal(await popup.locator('.is-in-range').count(), 5);
  assert.equal(await popup.locator('.is-selected').count(), 2);
  await popup.locator('[data-dp-clear]').click();
  assert.equal(await input.inputValue(), '');
  assert.equal(await page.locator('#range-end').inputValue(), '');
  await page.locator('#range-reset').click();
  await settle(page);
  assert.equal(await input.inputValue(), '15/01/2030 – 20/01/2030');
  assert.equal(await page.locator('#range-start').inputValue(), '2030-01-15');
  assert.equal(await page.locator('#range-end').inputValue(), '2030-01-20');
});

check('date range validates typed dates, inclusive limits, reversed ranges and keyboard selection', async t => {
  const { page } = await open(t);
  await addRange(page);
  const input = page.locator('#range');
  for (const value of ['01/01/2030 – 01/02/2030', '20/01/2030 – 10/01/2030', '31/02/2030 – 01/03/2030', '01/01/2030']) {
    await input.fill(value);
    await input.press('Tab');
    assert.equal(await input.evaluate(el => el.checkValidity()), false, value);
    assert.equal(await page.locator('#range-start').inputValue(), '');
    assert.equal(await page.locator('#range-end').inputValue(), '');
  }
  await input.fill('01/01/2030 – 31/01/2030');
  await input.press('Tab');
  assert.equal(await input.evaluate(el => el.checkValidity()), true);
  assert.equal(await page.locator('#range-end').inputValue(), '2030-01-31');
  await input.press('ArrowDown');
  await page.keyboard.press('Enter');
  assert.equal(await page.locator('.dp-range').isVisible(), true);
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('Enter');
  assert.equal(await input.inputValue(), '01/01/2030 – 02/01/2030');
  assert.equal(await page.locator('.dp-range').isVisible(), false);
});

for (const theme of ['light', 'dark']) {
  check(`${theme} date range fits mobile and handles reverse, same-day and oversized selections`, async t => {
    const { page } = await open(t);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
    await addRange(page);
    const input = page.locator('#range');
    const popup = page.locator('.dp-range');
    await input.press('ArrowDown');
    const box = await popup.boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 375);
    assert.ok(box.y >= 0 && box.y + box.height <= 812);
    await popup.locator('[data-date="2030-01-20"]').click();
    await popup.locator('[data-date="2030-01-10"]').click();
    assert.equal(await input.inputValue(), '10/01/2030 – 20/01/2030');
    await input.press('ArrowDown');
    await popup.locator('[data-date="2030-01-15"]').click();
    await popup.locator('[data-date="2030-01-15"]').click();
    assert.equal(await input.inputValue(), '15/01/2030 – 15/01/2030');
    await input.press('ArrowDown');
    await popup.locator('[data-date="2030-01-01"]').click();
    await popup.locator('[data-dp-next]').click();
    await popup.locator('[data-date="2030-02-01"]').click();
    assert.equal(await popup.isVisible(), true);
    assert.match(await popup.locator('.dp-help').textContent(), /at most 31 days/);
    assert.equal(await page.locator('#range-start').inputValue(), '');
    assert.equal(await page.locator('#range-end').inputValue(), '');
    await page.keyboard.press('Escape');
    assert.equal(await popup.isVisible(), false);
  });
  check(`${theme} theme has readable computed colors and fixed popup/menu within a narrow viewport`, async t => {
    const { page } = await open(t);
    await page.setViewportSize({ width: 390, height: 700 });
    await page.evaluate(theme => {
      document.documentElement.dataset.theme = theme;
      const main = document.querySelector('main');
      main.style.margin = '0'; main.style.padding = '16px';
      const wrap = document.querySelector('#date').closest('.dp-wrap');
      Object.assign(wrap.style, { position: 'fixed', right: '8px', bottom: '8px', width: '240px' });
    }, theme);
    await openCalendar(page);
    const accent = await page.locator('.dp-popup').evaluate((el, theme) => {
      const style = getComputedStyle(el);
      const token = theme === 'light' ? '--fu-maroon' : '--fu-maroon-accent';
      return { actual: style.getPropertyValue('--accent-color').trim(), expected: style.getPropertyValue(token).trim(), token };
    }, theme);
    assert.ok(accent.expected, `${theme} ${accent.token} must be defined`);
    assert.equal(accent.actual, accent.expected, `${theme} popup accent must match ${accent.token}`);
    const popup = await page.locator('.dp-popup').boundingBox();
    assert.ok(popup && popup.width > 200 && popup.height > 200, JSON.stringify(popup));
    assert.ok(popup.x >= 0 && popup.y >= 0 && popup.x + popup.width <= 391 && popup.y + popup.height <= 701, JSON.stringify(popup));
    await toggle(page, '.dp-month').click();
    await settle(page);
    const box = await menu(page, '.dp-month').boundingBox();
    assert.ok(box && box.x >= -1 && box.y >= -1 && box.x + box.width <= 391 && box.y + box.height <= 701, JSON.stringify(box));
    assert.equal(await menu(page, '.dp-month').evaluate(el => getComputedStyle(el).position), 'fixed');
    const samples = await page.evaluate(() => {
      function color(value) {
        const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d'); ctx.fillStyle = value; ctx.fillRect(0, 0, 1, 1);
        return Array.from(ctx.getImageData(0, 0, 1, 1).data);
      }
      function luminance(rgb) {
        const c = rgb.slice(0, 3).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
        return c[0] * .2126 + c[1] * .7152 + c[2] * .0722;
      }
      return ['.dp-month + .bd-select-toggle', '.dp-popup .dropdown-item.active',
        '.dp-popup .dropdown-item:not(.active):not(:disabled)', '.dp-cell:not(.is-outmonth):not(.is-selected) .dp-day',
        '.dp-cell.is-selected .dp-day'].map(selector => {
        const el = document.querySelector(selector); const style = getComputedStyle(el);
        let parent = el, bg;
        do { bg = color(getComputedStyle(parent).backgroundColor); parent = parent.parentElement; } while (bg[3] === 0 && parent);
        const fg = color(style.color); const a = luminance(fg), b = luminance(bg);
        return { selector, foreground: style.color, foregroundRGBA: fg, background: bg,
          ownBackground: color(style.backgroundColor), backgroundImage: style.backgroundImage,
          contrast: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
      });
    });
    const selected = samples.find(sample => sample.selector === '.dp-cell.is-selected .dp-day');
    assert.deepEqual(selected.foregroundRGBA, [255, 255, 255, 255], `${theme} selected day text must be white`);
    assert.equal(selected.ownBackground[3], 255, `${theme} selected day background must be opaque`);
    assert.equal(selected.backgroundImage, 'none', `${theme} selected day background must be solid`);
    for (const sample of samples) assert.ok(sample.contrast >= 4.5, `${theme} text contrast below 4.5:1: ${JSON.stringify(sample)}`);
  });
}
