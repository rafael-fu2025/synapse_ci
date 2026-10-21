'use strict';

// Presentation tests only: real PHP templates/assets, fixture responses to writes.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const cache = new Map();
const posts = [];
const responses = [];
const deployments = [
  { basePath: '/', indexPage: '', facilities: '/facilities' },
  { basePath: '/', indexPage: 'index.php', facilities: '/index.php/facilities' },
  { basePath: '/campus/', indexPage: '', facilities: '/campus/facilities' },
  { basePath: '/campus/', indexPage: 'index.php', facilities: '/campus/index.php/facilities' }
];
function deploymentFor(pathname) {
  return deployments.find(deployment => pathname === deployment.facilities || pathname.startsWith(deployment.facilities + '/')) || deployments[0];
}
function render(kind, url) {
  const key = kind + url;
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', ['tests/_support/render-bmg-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: url, kind, ...deploymentFor(new URL(url, origin).pathname) }), encoding: 'utf8', timeout: 10000
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
  assert.match(result.stdout, /<!DOCTYPE html>/);
  cache.set(key, result.stdout);
  return result.stdout;
}
before(async () => {
  server = http.createServer(async (req, res) => {
    try {
      if (req.method === 'POST') {
        let body = ''; for await (const chunk of req) body += chunk;
        const data = await new Request(origin + req.url, { method: 'POST', body,
          headers: { 'Content-Type': req.headers['content-type'] } }).formData();
        posts.push({ url: req.url, fields: Object.fromEntries(data), times: data.getAll('times[]'), headers: req.headers });
        if (req.headers['x-requested-with'] !== 'XMLHttpRequest') {
          res.writeHead(200, { 'Content-Type': 'text/html' }); res.end('<p>Fixture direct write accepted</p>'); return;
        }
        const reply = responses.shift() || {};
        if (reply.wait) await reply.wait;
        const csrf = { name: 'csrf_test_name', hash: reply.hash || 'fixture-refreshed-token', header: 'X-CSRF-TOKEN' };
        res.writeHead(reply.status || 200, { 'Content-Type': 'application/json',
          'X-CSRF-NAME': csrf.name, 'X-CSRF-TOKEN': csrf.hash, 'X-CSRF-HEADER': csrf.header });
        res.end(JSON.stringify({ success: !reply.status || reply.status === 200,
          message: reply.message || 'Fixture write accepted', redirect: reply.redirect || req.url,
          ...(reply.headerOnly ? {} : { csrf }) })); return;
      }
      const url = new URL(req.url, origin);
      const assetPath = url.pathname.replace(/^\/campus\/assets\//, '/assets/');
      if (assetPath.startsWith('/assets/')) {
        const file = path.resolve(root, 'public', '.' + assetPath);
        if (!file.startsWith(path.join(root, 'public', 'assets') + path.sep) || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
        const ext = path.extname(file);
        res.setHeader('Content-Type', ext === '.js' ? 'text/javascript' : ext === '.css' ? 'text/css' : 'application/octet-stream');
        res.end(fs.readFileSync(file)); return;
      }
      const kind = url.pathname === deploymentFor(url.pathname).facilities ? 'index' : url.pathname === '/token' ? 'device_token' : 'unit_detail';
      res.setHeader('Content-Type', 'text/html'); res.end(render(kind, req.url));
    } catch (error) { res.writeHead(500); res.end(String(error.stack)); }
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install Chromium for the existing browser-test tools');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
});
after(async () => { await browser?.close(); server?.close(); });
async function pageFor(t, url = '/facilities/unit/11', theme = 'light', mobile = false) {
  const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  await context.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.fulfill({ status: 200, body: '' }));
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  page.setDefaultNavigationTimeout(20000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  const response = await page.goto(origin + url); assert.equal(response.status(), 200, await response.text());
  await page.waitForFunction(() => window.SynapseBmg && window.bootstrap);
  return page;
}
async function fillScheduleTime(input, display) {
  await input.fill(display);
  await input.blur();
}
async function openScheduleTime(page, input) {
  assert.equal(await input.getAttribute('type'), 'text');
  assert.equal(await input.getAttribute('data-timepicker'), '');
  assert.equal(await input.getAttribute('name'), null, 'Only the hidden HH:mm value is submitted');
  const id = await input.getAttribute('id');
  assert.equal(await input.getAttribute('data-target'), '#' + id + 'Value');
  const target = page.locator('#' + id + 'Value');
  assert.equal(await target.getAttribute('type'), 'hidden');
  assert.equal(await target.getAttribute('name'), 'times[]');
  await input.click();
  const popupId = await input.getAttribute('aria-controls');
  assert.ok(popupId, 'Each time input controls its own shared picker');
  const popup = page.locator('.dtp-popup.dtp-time-only').and(page.locator('#' + popupId));
  await popup.waitFor({ state: 'visible' });
  assert.equal(await popup.locator('.dtp-date-pane').count(), 0);
  return popup;
}
async function chooseScheduleTime(page, input, hour, minute, period) {
  const popup = await openScheduleTime(page, input);
  await popup.locator(`.dtp-hours-wheel button[data-val="${hour}"]`).click();
  await popup.locator(`.dtp-minutes-wheel button[data-val="${minute}"]`).click();
  await popup.locator(`.dtp-ampm-wheel button[data-val="${period}"]`).click();
  await popup.locator('[data-dtp-apply]').click();
  await popup.waitFor({ state: 'hidden' });
}
test('overview, empty states and permitted create modal', async t => {
  const page = await pageFor(t, '/facilities?variant=empty');
  await page.getByRole('heading', { name: 'No drums registered' }).waitFor();
  await page.getByRole('button', { name: 'Add drum', exact: true }).click();
  const form = page.locator('#unitModal form');
  assert.equal(await form.getAttribute('action'), origin + '/facilities/units/store');
  assert.equal(await form.locator('[name="unit_code"]').count(), 1);
  assert.equal(await form.locator('[name="expected_version"]').count(), 0, 'New drums do not have a current version');
  assert.equal(await form.locator('[name="status"] option[value="active"]').count(), 1);
  assert.equal(await page.locator('form[method="POST"]').evaluateAll(forms => forms.every(form => !!form.querySelector('input[name="csrf_test_name"]'))), true);
});
test('capacity is required, bounded and blank on create; create and edit submit exact values', async t => {
  const page = await pageFor(t, '/facilities');
  assert.match(await page.locator('article').textContent(), /275\.50 kg/);
  await page.getByRole('button', { name: 'Add drum', exact: true }).click();
  const capacity = page.getByLabel('Capacity (kg)', { exact: true });
  assert.equal(await capacity.inputValue(), '');
  assert.equal(await capacity.getAttribute('required'), '');
  assert.equal(await capacity.getAttribute('step'), '0.01');
  assert.equal(await capacity.getAttribute('max'), '999999.99');
  for (const value of ['', '0', '-1', '0.001', '1000000']) {
    await capacity.fill(value);
    assert.equal(await capacity.evaluate(input => input.checkValidity()), false, value);
  }
  await capacity.fill('999999.99');
  assert.equal(await capacity.evaluate(input => input.checkValidity()), true);
  await capacity.fill('125.25');
  await page.locator('#unitCode').fill('DRUM-NEW'); await page.locator('#unitName').fill('New drum');
  await page.locator('#unitModal button[type="submit"]').click(); await page.waitForURL('**/facilities/units/store');
  assert.equal(posts.at(-1).fields.drum_capacity_kg, '125.25');
  const edit = await pageFor(t);
  assert.match(await edit.locator('main').textContent(), /Capacity:\s*275\.50 kg/);
  await edit.getByRole('button', { name: 'Edit drum', exact: true }).click();
  assert.equal(await edit.getByLabel('Capacity (kg)', { exact: true }).inputValue(), '275.50');
  await edit.getByLabel('Capacity (kg)', { exact: true }).fill('300.75');
  await edit.locator('#unitModal button[type="submit"]').click(); await edit.waitForURL('**/facilities/unit/11/update');
  assert.equal(posts.at(-1).fields.drum_capacity_kg, '300.75');
  assert.equal(posts.at(-1).fields.expected_version, '3');
});
test('operators can manage batches but cannot configure drums or use hardware controls', async t => {
  const overview = await pageFor(t, '/facilities?variant=operator');
  assert.equal(await overview.locator('[data-bs-target="#unitModal"], #unitModal').count(), 0);
  const page = await pageFor(t, '/facilities/unit/11?variant=operator');
  assert.equal(await page.locator('[data-bmg-schedule], #unitModal, #deviceModal').count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Edit drum', exact: true }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Revoke device', exact: true }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Request run now', exact: true }).isDisabled(), true);
  assert.equal(await page.getByRole('button', { name: 'Request web stop', exact: true }).isDisabled(), true);
  assert.equal(await page.getByRole('button', { name: 'Complete batch', exact: true }).first().isEnabled(), true);
  await page.getByRole('button', { name: 'Cancel batch', exact: true }).click();
  await page.locator('#batchCancelReason').fill('Operator cancellation');
  await page.locator('#cancelBatchModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/batch/31/cancel');
  assert.equal(posts.at(-1).fields.reason, 'Operator cancellation');
  const idle = await pageFor(t, '/facilities/unit/11?variant=operator-idle');
  await idle.getByRole('button', { name: 'Start batch', exact: true }).click();
  await idle.locator('#batchInput').fill('100.25');
  await idle.locator('#startBatchModal button[type="submit"]').click(); await idle.waitForURL('**/facilities/unit/11/batch');
  assert.equal(posts.at(-1).fields.input_weight_kg, '100.25');
});
test('configuration access does not require batch management access', async t => {
  const overview = await pageFor(t, '/facilities?variant=config-only');
  assert.equal(await overview.getByRole('button', { name: 'Add drum', exact: true }).isEnabled(), true);
  const page = await pageFor(t, '/facilities/unit/11?variant=config-only');
  assert.equal(await page.getByRole('button', { name: 'Edit drum', exact: true }).isEnabled(), true);
  assert.equal(await page.locator('[data-bmg-schedule], #unitModal, #deviceModal').count(), 3);
  assert.equal(await page.getByRole('button', { name: 'Revoke device', exact: true }).isEnabled(), true);
  assert.equal(await page.getByRole('button', { name: 'Request run now', exact: true }).isEnabled(), true);
  assert.equal(await page.locator('#startBatchModal, #completeBatchModal, #cancelBatchModal').count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Complete batch', exact: true }).count(), 0);
});
test('hardware and explicit permission gates fail closed', async t => {
  for (const variant of ['gate-off', 'controller-gate-off', 'detail-gate-off', 'no-hardware', 'reader', 'maintenance', 'unbound']) {
    const page = await pageFor(t, '/facilities/unit/11?variant=' + variant);
    assert.equal(await page.getByRole('button', { name: 'Request run now', exact: true }).isDisabled(), true);
    assert.equal(await page.getByRole('button', { name: 'Request web stop', exact: true }).isDisabled(), true);
    if (variant === 'reader') assert.equal(await page.locator('[data-bmg-schedule], #unitModal, #deviceModal, #cancelBatchModal').count(), 0);
    else {
      assert.equal(await page.locator('#scheduleEnabled').isDisabled(), true);
      assert.equal(await page.locator('[data-bs-target="#deviceModal"]').isEnabled(), true);
      assert.equal(await page.locator('#deviceModal button[type="submit"]').isEnabled(), true);
      if (variant !== 'unbound') assert.equal(await page.getByRole('button', { name: 'Revoke device', exact: true }).isEnabled(), true);
      assert.match(await page.locator('#deviceGateHelp').textContent(), /firmware and safety verification/);
    }
  }
});
test('every versioned form carries the current schedule version including zero', async t => {
  for (const [variant, expected] of [['', '3'], ['zero-version', '0']]) {
    const page = await pageFor(t, '/facilities/unit/11?variant=' + variant);
    const forms = page.locator('main form').filter({ has: page.locator('[name="expected_version"]') });
    assert.equal(await forms.count(), 6);
    const versions = await forms.evaluateAll(forms => forms.map(form => {
      const fields = [...form.querySelectorAll('[name="expected_version"]')];
      return [fields.length, fields[0].type, new FormData(form).get('expected_version')];
    }));
    assert.deepEqual(versions, Array.from({ length: 6 }, () => [1, 'hidden', expected]));
    assert.equal(await page.locator('#startBatchModal [name="expected_version"], #completeBatchModal [name="expected_version"], #cancelBatchModal [name="expected_version"]').count(), 0);
  }
});
test('registration posts version after shared confirmation with gate off; safe revoke works with gate off', async t => {
  const page = await pageFor(t, '/facilities/unit/11?variant=gate-off');
  await page.locator('[data-bs-target="#deviceModal"]').click();
  await page.locator('#deviceModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/unit/11/device');
  assert.equal(posts.at(-1).fields.expected_version, '3');
  assert.equal(posts.at(-1).fields.device_id, '21');
  assert.equal(posts.at(-1).headers['x-requested-with'], undefined, 'Registration must remain a direct POST');
  const gated = await pageFor(t, '/facilities/unit/11?variant=gate-off');
  await gated.getByRole('button', { name: 'Revoke device', exact: true }).click();
  await gated.locator('#synapseConfirmModal.show').waitFor();
  await gated.locator('[data-confirm-accept]').click(); await gated.waitForURL('**/facilities/device/21/revoke');
  assert.equal(posts.at(-1).fields.expected_version, '3');
});
test('telemetry uses exact per-field receipt timestamps without inferred freshness', async t => {
  for (const variant of ['reported', 'stale', 'untimestamped', 'partial']) {
    const page = await pageFor(t, '/facilities/unit/11?variant=' + variant);
    const telemetry = page.locator('[data-bmg-telemetry]');
    const text = await telemetry.textContent();
    assert.match(text, /Applied schedule version/);
    assert.match(await page.locator('main').textContent(), /server receipt times/);
    assert.match(await page.locator('main').textContent(), /Last contact means communication only, not motor state/);
    if (variant === 'untimestamped') {
      assert.equal(await telemetry.getByText('Unknown — no timestamped report', { exact: true }).count(), 3);
      assert.doesNotMatch(text, /Reported invalid|Last received/);
    } else if (variant === 'stale') {
      assert.equal(await telemetry.getByText('Not updated at last contact; may be stale.', { exact: true }).count(), 3);
      assert.match(text, /2026-10-17 08:00:00/);
    } else if (variant === 'reported') {
      assert.equal(await telemetry.getByText('Historical report; freshness not verified.', { exact: true }).count(), 3);
      assert.match(text, /Reported invalid/);
      assert.match(await telemetry.locator('dd').nth(2).textContent(), /0/);
      assert.match(text, /2026-10-17 10:00:00/);
    } else {
      assert.match(await telemetry.locator('dd').nth(0).textContent(), /may be stale/);
      assert.match(await telemetry.locator('dd').nth(1).textContent(), /Unknown/);
      assert.match(await telemetry.locator('dd').nth(2).textContent(), /Historical report/);
    }
  }
});
test('schedule enforces eight times, uniqueness and midnight overlap before confirmation', async t => {
  const page = await pageFor(t);
  const form = page.locator('[data-bmg-schedule]');
  for (let i = 0; i < 6; i++) await form.getByRole('button', { name: 'Add time', exact: true }).click();
  assert.equal(await form.locator('input[type="hidden"][name="times[]"]').count(), 8);
  assert.equal(await form.getByRole('button', { name: 'Add time', exact: true }).isDisabled(), true);
  const inputs = form.locator('input[type="text"][data-timepicker]');
  assert.equal(await inputs.count(), 8);
  for (let i = 0; i < 8; i++) await fillScheduleTime(inputs.nth(i), '08:00 AM');
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  assert.match(await form.locator('[role="alert"]').textContent(), /distinct/);
  for (let i = 0; i < 6; i++) await form.locator('[data-bmg-remove-time]').last().click();
  assert.equal(await inputs.count(), 2);
  assert.equal(await form.getByRole('button', { name: 'Add time', exact: true }).isEnabled(), true);
  await fillScheduleTime(inputs.nth(0), '11:59 PM');
  await fillScheduleTime(inputs.nth(1), '12:00 AM');
  await form.locator('[name="duration_seconds"]').fill('120');
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  assert.match(await form.locator('[role="alert"]').textContent(), /overlap/);
  await form.locator('[name="duration_seconds"]').fill('60');
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/unit/11/schedule');
  assert.equal(posts.at(-1).fields.expected_version, '3');
  assert.deepEqual(posts.at(-1).times, ['23:59', '00:00']);
});
test('shared schedule pickers preserve midnight/noon and dynamic rows submit only HH:mm', async t => {
  const page = await pageFor(t);
  const form = page.locator('[data-bmg-schedule]');
  const values = form.locator('input[type="hidden"][name="times[]"]');
  assert.deepEqual(await values.evaluateAll(inputs => inputs.map(input => input.value)), ['08:00', '16:00']);
  assert.equal(await form.locator('#rotationTime0').inputValue(), '08:00 AM');
  assert.equal(await form.locator('#rotationTime1').inputValue(), '04:00 PM');
  await chooseScheduleTime(page, form.locator('#rotationTime0'), 12, 0, 'AM');
  await chooseScheduleTime(page, form.locator('#rotationTime1'), 12, 0, 'PM');
  assert.equal(await form.locator('#rotationTime0').inputValue(), '12:00 AM');
  assert.equal(await form.locator('#rotationTime1').inputValue(), '12:00 PM');
  assert.deepEqual(await values.evaluateAll(inputs => inputs.map(input => input.value)), ['00:00', '12:00']);

  await form.getByRole('button', { name: 'Add time', exact: true }).click();
  const added = form.locator('input[data-timepicker][id^="rotationTimeAdded"]').last();
  const removedId = await added.getAttribute('id');
  assert.match(removedId, /^rotationTimeAdded\d+$/);
  await chooseScheduleTime(page, added, 10, 7, 'PM');
  assert.equal(await added.inputValue(), '10:07 PM');
  assert.equal(await form.locator('#' + removedId + 'Value').inputValue(), '22:07');
  await form.locator('[data-bmg-remove-time]').last().click();
  assert.equal(await form.locator('#' + removedId).count(), 0);
  assert.equal(await form.locator('#' + removedId + 'Value').count(), 0);
  assert.equal(await values.count(), 2);

  await form.getByRole('button', { name: 'Add time', exact: true }).click();
  await chooseScheduleTime(page, added, 10, 0, 'AM');
  assert.equal(await added.inputValue(), '10:00 AM');
  assert.deepEqual(await form.evaluate(form => new FormData(form).getAll('times[]')), ['00:00', '12:00', '10:00']);
  const count = posts.length;
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(posts.length, count, 'No schedule is submitted before shared confirmation');
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL('**/facilities/unit/11/schedule');
  assert.equal(posts.length, count + 1);
  assert.deepEqual(posts.at(-1).times, ['00:00', '12:00', '10:00']);
  assert.equal(posts.at(-1).fields.expected_version, '3');
});
test('invalid or blank schedule typing clears the previous hidden time and blocks submission', async t => {
  const page = await pageFor(t);
  const form = page.locator('[data-bmg-schedule]');
  const input = form.locator('#rotationTime0');
  const target = form.locator('#rotationTime0Value');
  const count = posts.length;
  for (const invalid of ['13:10 PM', '25:00', '10:', '']) {
    await fillScheduleTime(input, '10:00 AM');
    assert.equal(await target.inputValue(), '10:00');
    await fillScheduleTime(input, invalid);
    assert.equal(await target.inputValue(), '', `No stale HH:mm value after ${JSON.stringify(invalid)}`);
    assert.equal(await form.evaluate(form => new FormData(form).getAll('times[]')[0]), '');
    assert.equal(await form.evaluate(form => form.checkValidity()), false);
    await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
    assert.equal(await page.locator('#synapseConfirmModal.show').count(), 0);
    assert.equal(posts.length, count);
  }
  await fillScheduleTime(input, '10:00 AM');
  assert.equal(await target.inputValue(), '10:00');
  assert.equal(await form.evaluate(form => form.checkValidity()), true);
  await confirmSchedule(page);
  await page.waitForURL('**/facilities/unit/11/schedule');
  assert.deepEqual(posts.at(-1).times, ['10:00', '16:00']);
});
for (const theme of ['light', 'dark']) {
  for (const mobile of [false, true]) {
    test(`shared schedule picker works in ${theme} ${mobile ? 'mobile' : 'desktop'} layout`, async t => {
      const page = await pageFor(t, '/facilities/unit/11', theme, mobile);
      assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
      const form = page.locator('[data-bmg-schedule]');
      await form.getByRole('button', { name: 'Add time', exact: true }).click();
      const input = form.locator('input[data-timepicker][id^="rotationTimeAdded"]').last();
      const popup = await openScheduleTime(page, input);
      assert.equal(await popup.locator('.dtp-hours-wheel button[data-val]').count(), 12);
      assert.equal(await popup.locator('.dtp-minutes-wheel button[data-val]').count(), 60);
      assert.equal(await popup.locator('.dtp-ampm-wheel button[data-val]').count(), 2);
      const bounds = await popup.boundingBox();
      const viewport = page.viewportSize();
      assert.ok(bounds && bounds.x >= 0 && bounds.x + bounds.width <= viewport.width + 1, 'Time popup fits the viewport');
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await popup.locator('.dtp-hours-wheel button[data-val="11"]').click();
      await popup.locator('.dtp-minutes-wheel button[data-val="59"]').click();
      await popup.locator('.dtp-ampm-wheel button[data-val="PM"]').click();
      await popup.locator('[data-dtp-apply]').click();
      await popup.waitFor({ state: 'hidden' });
      assert.equal(await input.inputValue(), '11:59 PM');
      assert.deepEqual(await form.evaluate(form => new FormData(form).getAll('times[]')), ['08:00', '16:00', '23:59']);
    });
  }
}
test('stop confirms and submits no run duration', async t => {
  const page = await pageFor(t); const count = posts.length;
  await page.getByRole('button', { name: 'Request web stop', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor(); assert.equal(posts.length, count);
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/unit/11/command');
  assert.equal(posts.at(-1).fields.expected_version, '3');
  assert.equal(posts.at(-1).fields.command, 'stop'); assert.equal('duration_seconds' in posts.at(-1).fields, false);
  assert.ok(posts.at(-1).fields.csrf_test_name);
});
test('all detail writes use CSRF and exact registration, cancellation and edit fields', async t => {
  const page = await pageFor(t);
  assert.equal(await page.locator('main form[method="POST"]').evaluateAll(forms => forms.every(form => !!form.querySelector('input[name="csrf_test_name"]'))), true);
  const device = page.locator('#deviceModal form');
  assert.equal(await device.getAttribute('action'), origin + '/facilities/unit/11/device');
  assert.equal(await device.locator('[name="device_id"]').inputValue(), '21');
  assert.equal(await device.locator('[name="chip_mac"]').inputValue(), 'AA:BB:CC:DD:EE:FF');
  assert.equal(await page.locator('#unitModal form').getAttribute('action'), origin + '/facilities/unit/11/update');
  await page.getByRole('button', { name: 'Cancel batch', exact: true }).click();
  await page.locator('#batchCancelReason').fill('Incorrect batch entry');
  const count = posts.length;
  await page.locator('#cancelBatchModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(posts.length, count);
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/batch/31/cancel');
  assert.equal(posts.at(-1).fields.reason, 'Incorrect batch entry');
});
test('run request posts duration only after shared consent', async t => {
  const page = await pageFor(t); const count = posts.length;
  await page.locator('#manualDuration').fill('90');
  await page.getByRole('button', { name: 'Request run now', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor(); assert.equal(posts.length, count);
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/unit/11/command');
  assert.equal(posts.at(-1).fields.expected_version, '3');
  assert.equal(posts.at(-1).fields.command, 'run_now'); assert.equal(posts.at(-1).fields.duration_seconds, '90');
  assert.equal('pwm' in posts.at(-1).fields, false);
});
test('batch completion hands off to shared confirmation without stacked modals', async t => {
  const page = await pageFor(t);
  await page.getByRole('button', { name: 'Complete batch', exact: true }).first().click();
  await page.locator('#completeBatchModal.show').waitFor();
  assert.equal(await page.locator('#batchOutput').getAttribute('required'), null);
  await page.locator('#completeBatchModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(await page.locator('.modal.show').count(), 1);
  await page.locator('[data-confirm-cancel]').click();
});
test('idle batch modal posts only input and notes; detail has clear empty histories', async t => {
  const page = await pageFor(t, '/facilities/unit/11?variant=idle');
  await page.getByRole('button', { name: 'Start batch', exact: true }).click();
  assert.equal(await page.locator('#startBatchModal form').getAttribute('action'), origin + '/facilities/unit/11/batch');
  assert.equal(await page.locator('#startBatchModal [name="notes"]').count(), 1);
  const empty = await pageFor(t, '/facilities/unit/11?variant=empty');
  assert.match(await empty.locator('main').textContent(), /No commands requested yet/);
  assert.match(await empty.locator('main').textContent(), /No motor events reported yet/);
  assert.match(await empty.locator('main').textContent(), /No past batch records yet/);
});
test('escaping, dark/mobile layout and rotation-only fields', async t => {
  const page = await pageFor(t, '/facilities/unit/11?variant=malicious', 'dark', true);
  assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
  assert.equal(await page.evaluate(() => window.bmgXss), undefined);
  assert.equal(await page.locator('main img').count(), 0);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  const names = await page.locator('main [name]').evaluateAll(items => items.map(item => item.name));
  assert.equal(names.some(name => /temperature|moisture|oxygen|quality_grade|pwm|sanitation/i.test(name)), false);
  assert.match(await page.locator('main').textContent(), /not verified drum rotation/);
});
test('AJAX schedule errors retain fields, render escaped messages and refresh CSRF before retry', async t => {
  const page = await pageFor(t);
  const form = page.locator('[data-bmg-schedule]');
  const originalUrl = page.url();
  const literal = '<img src=x onerror="window.bmgXss=1">Rejected & retained';
  await form.locator('[name="duration_seconds"]').fill('125');
  for (const status of [422, 409, 403, 503]) {
    responses.push({ status, message: literal, hash: `token-${status}`, headerOnly: status === 403 });
    await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await page.locator('#synapseConfirmModal.show').waitFor();
    const response = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/schedule'));
    await page.locator('[data-confirm-accept]').click();
    assert.equal((await response).status(), status);
    await page.waitForFunction(() => !document.querySelector('[data-bmg-schedule]').hasAttribute('aria-busy'));
    assert.equal(page.url(), originalUrl);
    assert.equal(await form.locator('[data-bmg-submit-error]').textContent(), literal);
    assert.equal(await form.locator('[data-bmg-submit-error] img').count(), 0);
    assert.equal(await page.evaluate(() => window.bmgXss), undefined);
    assert.equal(await form.locator('[name="duration_seconds"]').inputValue(), '125');
    assert.deepEqual(await form.locator('[name="times[]"]').evaluateAll(inputs => inputs.map(input => input.value)), ['08:00', '16:00']);
    assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), `token-${status}`);
    assert.equal(await page.locator('main input[name="csrf_test_name"]').evaluateAll(inputs => inputs.every(input => input.value === document.querySelector('meta[name="csrf-token"]').content)), true);
  }
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/unit/11/schedule');
  assert.equal(posts.at(-1).fields.duration_seconds, '125');
  assert.equal(posts.at(-1).fields.csrf_test_name, 'token-503');
  assert.equal(posts.at(-1).headers['x-csrf-token'], 'token-503');
});
test('pending modal writes block duplicate submits and dismissal; failure reopens confirmed editor with fields intact', async t => {
  const page = await pageFor(t);
  await page.getByRole('button', { name: 'Complete batch', exact: true }).first().click();
  await page.locator('#batchOutput').fill('98.50');
  await page.locator('#batchReleaseNotes').fill('Keep these notes');
  let release;
  const wait = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  responses.push({ status: 422, message: 'Review output weight', wait });
  const count = posts.length;
  await page.locator('#completeBatchModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(posts.length, count);
  await page.locator('[data-confirm-accept]').click();
  await page.waitForFunction(() => document.querySelector('#completeBatchModal form').getAttribute('aria-busy') === 'true');
  const modal = page.locator('#completeBatchModal');
  await page.waitForFunction(() => document.querySelector('#completeBatchModal').getAttribute('aria-modal') === 'true');
  assert.equal(await modal.locator('.btn-close').isDisabled(), true);
  assert.equal(await modal.locator('[data-bs-dismiss="modal"]').last().isDisabled(), true);
  assert.equal(await modal.locator('button[type="submit"]').isDisabled(), true);
  await page.evaluate(() => {
    const modal = document.querySelector('#completeBatchModal');
    modal.querySelector('form').requestSubmit();
    bootstrap.Modal.getInstance(modal).hide();
  });
  await page.keyboard.press('Escape');
  assert.equal(await modal.getAttribute('aria-modal'), 'true');
  assert.equal(posts.length, count + 1);
  release();
  await modal.locator('[data-bmg-submit-error]').waitFor();
  await page.waitForFunction(() => !document.querySelector('#completeBatchModal form').hasAttribute('aria-busy'));
  assert.equal(await page.locator('#batchOutput').inputValue(), '98.50');
  assert.equal(await page.locator('#batchReleaseNotes').inputValue(), 'Keep these notes');
  assert.equal(await modal.locator('.btn-close').isEnabled(), true);
  await modal.locator('button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL('**/facilities/batch/31/release');
  assert.equal(posts.at(-1).fields.release_notes, 'Keep these notes');
});
async function confirmSchedule(page) {
  await page.getByRole('button', { name: 'Save schedule', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await Promise.all([
    page.evaluate(() => new Promise(resolve => {
      document.querySelector('#synapseConfirmModal').addEventListener('hidden.bs.modal', () => resolve(), { once: true });
    })),
    page.locator('[data-confirm-accept]').click()
  ]);
}
function rejectedFacilitiesUrls(base) {
  return [...new Set([
    'https://example.com' + base,
    '//example.com' + base,
    origin.replace('://', '://fixture@') + base,
    origin.replace('://', '://fixture:secret@') + base,
    '/clinic/inventory',
    base + '-evil',
    '/other' + base,
    ...deployments.map(deployment => deployment.facilities).filter(pathname => pathname !== base),
    'http://['
  ])];
}
for (const { facilities: base } of deployments) {
  test(`facilities URL audit: real overview and detail accept ${base} actions and redirects`, async t => {
    t.after(() => { responses.length = 0; });
    const overview = await pageFor(t, base);
    assert.equal(await overview.locator('.bmg-workspace').getAttribute('data-bmg-base-url'), origin + base);
    await overview.getByRole('button', { name: 'Add drum', exact: true }).click();
    const create = overview.locator('#unitModal form');
    assert.equal(await create.getAttribute('action'), origin + base + '/units/store');
    await overview.locator('#unitCode').fill('DRUM-AUDIT');
    await overview.locator('#unitName').fill('URL audit fixture');
    await overview.getByLabel('Capacity (kg)', { exact: true }).fill('125.25');
    const count = posts.length;
    responses.push({ redirect: base });
    await Promise.all([
      overview.waitForEvent('framenavigated', frame => frame === overview.mainFrame()),
      overview.locator('#unitModal button[type="submit"]').click()
    ]);
    assert.equal(overview.url(), origin + base);
    await overview.locator('#unitModal.show').waitFor({ state: 'hidden' });
    assert.equal(posts.length, count + 1);
    assert.equal(posts.at(-1).url, base + '/units/store');
    assert.equal(posts.at(-1).headers['x-requested-with'], 'XMLHttpRequest');
    assert.equal(posts.at(-1).fields.drum_capacity_kg, '125.25');

    // Exercise both exact-base and descendant actions, not just redirects.
    for (const action of [base, base + '/unit/11/schedule']) {
      const page = await pageFor(t, base + '/unit/11');
      assert.equal(await page.locator('.bmg-workspace').getAttribute('data-bmg-base-url'), origin + base);
      const form = page.locator('[data-bmg-schedule]');
      assert.equal(await form.getAttribute('action'), origin + base + '/unit/11/schedule');
      await form.evaluate((form, action) => { form.action = action; }, origin + action);
      const redirect = action === base ? base + '/unit/11?audit=saved' : base;
      const before = posts.length;
      responses.push({ redirect: origin + redirect });
      await confirmSchedule(page);
      await page.waitForURL(origin + redirect);
      assert.equal(posts.length, before + 1);
      assert.equal(posts.at(-1).url, action);
      assert.equal(posts.at(-1).headers['x-requested-with'], 'XMLHttpRequest');
      assert.equal(posts.at(-1).headers['x-csrf-token'], posts.at(-1).fields.csrf_test_name);
      assert.equal(posts.at(-1).fields.expected_version, '3');
    }
  });
  test(`facilities URL audit: bad ${base} actions never POST across areas`, async t => {
    const page = await pageFor(t, base + '/unit/11');
    const form = page.locator('[data-bmg-schedule]');
    const originalUrl = page.url();
    const attemptedPosts = [];
    page.on('request', request => { if (request.method() === 'POST') attemptedPosts.push(request.url()); });
    for (const action of rejectedFacilitiesUrls(base)) {
      const count = posts.length;
      await form.evaluate((form, action) => { form.action = action; }, action);
      await confirmSchedule(page);
      await form.locator('[data-bmg-submit-error]').waitFor();
      assert.match(await form.locator('[data-bmg-submit-error]').textContent(), /form action must stay within.*facilities area/, action);
      assert.equal(page.url(), originalUrl, action);
      assert.equal(posts.length, count, action);
      assert.deepEqual(attemptedPosts, [], action);
    }
  });
  test(`facilities URL audit: bad ${base} success redirects never navigate`, async t => {
    t.after(() => { responses.length = 0; });
    const page = await pageFor(t, base + '/unit/11');
    const form = page.locator('[data-bmg-schedule]');
    const originalUrl = page.url();
    const navigations = [];
    page.on('request', request => { if (request.isNavigationRequest()) navigations.push(request.url()); });
    for (const redirect of rejectedFacilitiesUrls(base)) {
      const count = posts.length;
      responses.push({ redirect });
      await confirmSchedule(page);
      await form.locator('[data-bmg-submit-error]').waitFor();
      await page.waitForFunction(() => !document.querySelector('[data-bmg-schedule]').hasAttribute('aria-busy'));
      assert.match(await form.locator('[data-bmg-submit-error]').textContent(), /invalid facilities redirect/, redirect);
      assert.equal(page.url(), originalUrl, redirect);
      assert.equal(posts.length, count + 1, redirect);
      assert.equal(posts.at(-1).url, base + '/unit/11/schedule', redirect);
      assert.deepEqual(navigations, [], redirect);
    }
  });
}
test('unmarked forms are not intercepted by the facilities AJAX handler', async t => {
  const page = await pageFor(t);
  await page.evaluate(origin => {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = origin + '/unrelated-write'; form.id = 'unrelatedWrite';
    const button = document.createElement('button'); button.type = 'submit'; button.textContent = 'Unrelated submit';
    form.append(button); document.querySelector('main').append(form);
  }, origin);
  await page.locator('#unrelatedWrite button').click(); await page.waitForURL('**/unrelated-write');
  assert.equal(posts.at(-1).headers['x-requested-with'], undefined);
});
test('overview and drum layout adapt to both themes and narrow screens', async t => {
  for (const theme of ['light', 'dark']) {
    for (const mobile of [false, true]) {
      const suffix = `${theme}-${mobile ? 'mobile' : 'desktop'}`;
      const overview = await pageFor(t, '/facilities', theme, mobile);
      assert.equal(await overview.locator('.bmg-stat').count(), 4);
      assert.equal(await overview.locator('.bmg-drum-card').count(), 1);
      assert.equal(await overview.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      const contrast = await overview.locator('.bmg-stat-label').first().evaluate(element => {
        const luminance = color => {
          const rgb = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(value => {
            const channel = value / 255;
            return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
          });
          return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
        };
        const foreground = luminance(getComputedStyle(element).color);
        const background = luminance(getComputedStyle(element.closest('.bmg-stat')).backgroundColor);
        return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
      });
      assert.ok(contrast >= 4.5, `Summary label contrast ${contrast} in ${theme}`);
      const previews = path.join(root, 'writable/bmg-layout-previews');
      fs.mkdirSync(previews, { recursive: true });
      await overview.screenshot({ path: path.join(previews, `overview-${suffix}.png`), fullPage: true });
      const detail = await pageFor(t, '/facilities/unit/11', theme, mobile);
      assert.equal(await detail.locator('.bmg-stat').count(), 4);
      assert.equal(await detail.locator('.bmg-section-nav a').count(), 5);
      assert.equal(await detail.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      assert.equal(await detail.locator('.bmg-aside > section').first().getAttribute('id'), 'bmg-batch');
      const columns = await detail.locator('.bmg-detail-grid').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length);
      assert.equal(columns, mobile ? 1 : 2);
      await detail.screenshot({ path: path.join(previews, `drum-${suffix}.png`), fullPage: true });
      await detail.getByRole('link', { name: 'Current batch', exact: true }).click();
      assert.equal(new URL(detail.url()).hash, '#bmg-batch');
      assert.equal(await detail.getByRole('button', { name: 'Complete batch', exact: true }).isVisible(), true);
    }
  }
});

test('token is direct result only, escaped and cleared before page cache', async t => {
  const page = await pageFor(t, '/token');
  assert.equal(await page.locator('#deviceToken').inputValue(), 'fixture-secret-not-a-real-token');
  assert.equal(await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage })).then(value => value.includes('fixture-secret')), false);
  await page.evaluate(() => dispatchEvent(new Event('pagehide')));
  assert.equal(await page.locator('#deviceToken').inputValue(), '');
  assert.equal(await page.locator('#deviceToken').textContent(), '');
  const malicious = await pageFor(t, '/token?variant=malicious');
  assert.equal(await malicious.evaluate(() => window.bmgXss), undefined);
  const detail = render('unit_detail', '/facilities/unit/11');
  assert.equal(detail.includes('fixture-secret-not-a-real-token'), false);
});
