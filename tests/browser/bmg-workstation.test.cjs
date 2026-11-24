'use strict';

// Actual workstation views/shared scripts against isolated synthetic UI endpoints.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const fixture = require('../_support/bmg-workstation-browser-harness.cjs');
const { chromium } = fixture.localPlaywright();
const evidenceDir = path.resolve(__dirname, '../../writable/bmg-workstation-preview');
let server, browser;
before(async () => {
  server = await fixture.startServer();
  const executablePath = fixture.installedChromium(chromium);
  assert.ok(executablePath, 'An installed browser is required; no dependencies are installed.');
  browser = await chromium.launch({ headless: true, executablePath });
}, { timeout: 30000 });
after(async () => {
  await browser?.close();
  if (server) { console.log('BMG fixture totals: ' + JSON.stringify(server.totals)); await server.close(); }
});
async function open(t, options = {}) {
  const scenario = server.createScenario(options);
  const dashboardOnly = options.start === fixture.dashboardPath || Object.hasOwn(options, 'dashboardSeriesVisits');
  const context = await browser.newContext({ viewport: { width: options.width || 1440, height: 1000 }, reducedMotion: 'reduce', colorScheme: options.theme || 'light', serviceWorkers: 'block', javaScriptEnabled: options.javaScriptEnabled !== false });
  await context.addCookies([{ name: fixture.cookieName, value: scenario.id, url: server.origin }]);
  // Environment setup fails closed if page code attempts an unsolicited hardware API.
  await context.addInitScript(() => {
    window.fixtureHardwareCalls = [];
    const serial = new EventTarget();
    serial.requestPort = async () => { window.fixtureHardwareCalls.push('requestPort'); throw new Error('No hardware in synthetic UI tests'); };
    serial.getPorts = async () => { window.fixtureHardwareCalls.push('getPorts'); return []; };
    Object.defineProperty(navigator, 'serial', { configurable: true, value: serial });
  });
  const errors = [], rejected = [], documents = [];
  await context.route('**/*', route => {
    const request = route.request();
    if ((dashboardOnly && request.method() !== 'GET') || !fixture.allowedRequest(server.origin, new URL(request.url()), request.method())) { rejected.push(request.method() + ' ' + new URL(request.url()).pathname); return route.abort(); }
    return route.continue();
  });
  const page = await context.newPage();
  await page.bringToFront();
  page.setDefaultTimeout(12000);
  page.on('pageerror', error => errors.push(error.message));
  if (dashboardOnly) page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('request', request => { if (request.isNavigationRequest() && request.resourceType() === 'document') documents.push(request.url()); });
  page.on('dialog', async dialog => { errors.push('Unexpected native dialog'); await dialog.dismiss(); });
  t.after(async () => {
    const calls = await page.evaluate(() => window.fixtureHardwareCalls || []);
    await context.close();
    assert.deepEqual(calls, [], 'No serial API may run automatically.');
    if (dashboardOnly) {
      assert.deepEqual(scenario.posts, [], 'Dashboard verification stays read-only.');
      assert.ok(scenario.requests.every(request => request.method === 'GET'));
    }
    assert.deepEqual(errors, []); assert.deepEqual(rejected, []);
  });
  await page.goto(server.origin + (options.start || fixture.listPath), { waitUntil: 'load' });
  if (new URL(page.url()).pathname === fixture.dashboardPath) await page.locator('[data-bmg-dashboard]').waitFor();
  else if (!options.start || !options.start.includes('neighbor')) await page.locator('.bmg-workstation').waitFor();
  return { page, scenario, documents };
}
async function settled(page) {
  await page.waitForFunction(() => !document.body.classList.contains('is-navigating') && !document.querySelector('[data-page-style-stage]') && !document.querySelector('#main[aria-busy="true"]'));
}
async function screenshot(page, name, { fullPage = false } = {}) {
  await settled(page);
  fs.mkdirSync(evidenceDir, { recursive: true });
  const file = path.join(evidenceDir, name + '.png');
  await page.screenshot({ path: file, fullPage, animations: 'disabled', timeout: 30000 });
  console.log('BMG screenshot: ' + file);
}
async function dashboardEvidence(page, name, { worklist = false } = {}) {
  // Keep dashboard evidence bounded even when its synthetic worklist is long.
  await screenshot(page, name);
  if (!worklist) return;
  const head = page.locator('[data-bmg-unit-table] thead');
  const target = await head.isVisible() ? head : page.locator('[data-bmg-unit-table] tbody tr').first();
  const box = await target.boundingBox();
  assert.ok(box, 'A visible worklist evidence target is required.');
  const viewport = page.viewportSize();
  if (box.width > viewport.width || box.height > viewport.height) {
    await target.scrollIntoViewIfNeeded();
    await screenshot(page, name + '-worklist');
    return;
  }
  const file = path.join(evidenceDir, name + '-worklist.png');
  await target.screenshot({ path: file, animations: 'disabled', timeout: 30000 });
  console.log('BMG worklist screenshot: ' + file);
}
async function noOverflow(page) {
  const result = await page.evaluate(() => ({ width: innerWidth, document: document.documentElement.scrollWidth,
    bad: [...document.querySelectorAll('.bmg-workstation, .bmg-drum-card, .bmg-detail-grid, .bmg-metrics, .bmg-history-grid, .bmg-command-box')].filter(node => node.scrollWidth > node.clientWidth + 2).map(node => node.className) }));
  assert.ok(result.document <= result.width + 2, JSON.stringify(result));
  assert.deepEqual(result.bad, []);
}
async function labels(page) {
  const missing = await page.locator('.bmg-workstation input:not([type="hidden"]), .bmg-workstation select, .bmg-workstation textarea').evaluateAll(nodes => nodes.filter(node => !node.getAttribute('aria-label') && !node.getAttribute('aria-labelledby') && !node.labels?.length).map(node => node.id));
  assert.deepEqual(missing, []);
}
function entries(post) { return post.entries.filter(([key]) => key !== 'csrf_test_name'); }
async function confirm(page, title, accept) {
  const dialog = page.getByRole('dialog', { name: title, exact: true });
  await dialog.waitFor({ state: 'visible' });
  await screenshot(page, 'confirm-' + title.toLowerCase().replace(/\W+/g, '-') + (accept ? '-accept' : '-cancel'));
  await dialog.locator(accept ? '[data-confirm-accept]' : '[data-confirm-cancel]').click();
  await dialog.waitFor({ state: 'hidden' });
}
async function fillUnit(page, code = 'SYN_TEST001') {
  const modal = page.getByRole('dialog', { name: 'Add drum', exact: true });
  await modal.waitFor({ state: 'visible' });
  await modal.getByLabel('Unit code', { exact: true }).fill(code);
  await modal.getByLabel('Drum name', { exact: true }).fill('Synthetic test drum');
  await modal.getByLabel('Capacity (kg)', { exact: true }).fill('125.50');
  return modal;
}

test('Contract: directory/detail preserve BMG fields, history cursors, safety text and native credential delivery', () => {
  const origin = 'http://127.0.0.1:54444';
  const csrfHash = crypto.randomBytes(32).toString('hex');
  const directory = fixture.renderFixture(origin, new URL(origin + fixture.listPath), { csrfHash });
  const detail = fixture.renderFixture(origin, new URL(origin + fixture.detailPath), { csrfHash, variant: 'zero-version' });
  assert.ok(directory.styles.includes('pages/bmg-workstation.css'));
  assert.ok(detail.styles.includes('pages/bmg-workstation.css'));
  assert.ok(directory.content.includes('data-refresh-owner="facilities.list"'));
  assert.ok(detail.content.includes('data-refresh-owner="facilities.unit.98001"'));
  for (const name of ['expected_version', 'times[]', 'rotations', 'pwm', 'enabled', 'command', 'chip_mac', 'device_id', 'input_weight_kg', 'retirement_confirmed']) assert.ok(detail.content.includes('name="' + name + '"'), name);
  assert.ok(detail.content.includes('name="expected_version" value="0"'));
  assert.ok(detail.content.includes('Web stop is not an emergency stop.'));
  assert.ok(detail.content.includes('Device-reported motor events'));
  assert.ok(detail.content.includes('freshness not verified'));
  const deviceForm = detail.content.match(/<form[^>]+action="[^\"]+\/device"[^>]*>/)?.[0];
  assert.ok(deviceForm && !deviceForm.includes('data-bmg-submit'), 'One-time credential delivery stays native POST.');
  assert.ok(!detail.content.includes('data-bmg-token'));
  for (const prefix of ['commands', 'runs', 'batches']) assert.ok(detail.content.includes(prefix + '_page='));
  assert.throws(() => fixture.renderFixture('https://example.com', new URL('https://example.com/facilities')));
});

test('Directory: current/archive links, page sizes and real totals retain their GET contracts', { timeout: 60000 }, async t => {
  const { page } = await open(t);
  assert.equal(await page.locator('.bmg-drum-card').count(), 25);
  assert.equal(await page.locator('.bmg-stat-number').first().textContent(), '30');
  assert.ok((await page.locator('.bmg-page-size-form .bd-select').boundingBox()).width <= 110, 'Keep page size control compact');
  await screenshot(page, 'directory-current-1440-light');
  const pagination = page.getByRole('navigation', { name: 'Current drum pages', exact: true });
  await pagination.getByRole('link', { name: /Next/ }).click();
  await page.waitForURL(url => url.searchParams.get('page') === '2');
  assert.equal(await page.locator('.bmg-drum-card').count(), 5);
  await page.getByRole('link', { name: 'Archived drums', exact: true }).click();
  await page.waitForURL(server.origin + '/facilities?view=archived');
  assert.equal(await page.getByRole('link', { name: /Manage drum/ }).count(), 0);
  assert.equal(await page.getByRole('button', { name: /Restore drum/ }).count(), 25);
  assert.ok((await page.locator('.bmg-directory-section').textContent()).includes('offline'));
  await screenshot(page, 'directory-archived-1440-light');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('view') === 'archived' && url.searchParams.get('per_page') === '25');
  assert.equal(await page.locator('#drums-per-page').inputValue(), '25');
});

test('Forms: Add drum validation, 422 retry, CSRF rotation and duplicate-submit protection use explicit synthetic POSTs', { timeout: 60000 }, async t => {
  const { page, scenario } = await open(t, { variant: 'empty', postStatuses: [422, 200], postDelayMs: 350 });
  await page.getByRole('button', { name: 'Add drum', exact: true }).click();
  const modal = await fillUnit(page);
  await modal.getByRole('button', { name: 'Save drum', exact: true }).click();
  await modal.locator('[data-bmg-submit-error]').waitFor({ state: 'visible' });
  assert.equal(await modal.getByLabel('Drum name', { exact: true }).inputValue(), 'Synthetic test drum');
  assert.equal(scenario.posts.length, 1);
  await screenshot(page, 'add-drum-validation-retry');
  await modal.getByRole('button', { name: 'Save drum', exact: true }).click({ clickCount: 2 });
  await page.waitForURL(server.origin + fixture.detailPath);
  await settled(page);
  assert.equal(scenario.posts.length, 2);
  assert.deepEqual(entries(scenario.posts[1]), [['unit_code', 'SYN_TEST001'], ['name', 'Synthetic test drum'], ['drum_capacity_kg', '125.50'], ['status', 'active']]);
  assert.notEqual(scenario.posts[0].entries.find(([key]) => key === 'csrf_test_name')[1], scenario.posts[1].entries.find(([key]) => key === 'csrf_test_name')[1]);
  assert.equal(await page.locator('.modal.show').count(), 0);
  await screenshot(page, 'add-drum-authoritative-refresh');
});

test('Permissions: gate-off, reader, operator, config-only and unbound states retain the five-step safety checklist', { timeout: 60000 }, async t => {
  for (const variant of ['gate-off', 'reader', 'operator', 'config-only', 'unbound']) {
    const { page, scenario } = await open(t, { start: fixture.detailPath, variant, width: 390 });
    assert.equal(await page.locator('#bmg-setup ol > li').count(), 5);
    const runAllowed = variant === 'operator';
    assert.equal(await page.getByRole('button', { name: 'Request run now', exact: true }).isEnabled(), runAllowed);
    assert.equal(await page.getByRole('button', { name: 'Request web stop', exact: true }).isEnabled(), runAllowed);
    const canEditSchedule = variant === 'gate-off' || variant === 'config-only' || variant === 'unbound';
    assert.equal(await page.locator('form[data-bmg-schedule]').count(), canEditSchedule ? 1 : 0);
    if (variant === 'unbound') assert.equal(await page.getByLabel('Schedule enabled', { exact: true }).isEnabled(), false);
    assert.equal(scenario.posts.length, 0);
    await noOverflow(page); await labels(page);
    await screenshot(page, 'permission-' + variant + '-390-light');
  }
});

test('Schedule: duplicate/midnight overlap checks, add/remove limits and confirmation cancellation do not submit', { timeout: 60000 }, async t => {
  const { page, scenario } = await open(t, { start: fixture.detailPath });
  const form = page.locator('[data-bmg-schedule]');
  const times = form.locator('[data-timepicker]');
  await times.nth(1).fill('06:00 AM');
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  await form.locator('[data-bmg-schedule-error]:not(.d-none)').waitFor();
  assert.match(await form.locator('[data-bmg-schedule-error]').textContent(), /distinct/);
  assert.equal(scenario.posts.length, 0);
  await times.nth(0).fill('11:59 PM');
  await times.nth(1).fill('12:00 AM');
  await form.getByLabel('Rotations per run', { exact: true }).fill('20');
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  assert.match(await form.locator('[data-bmg-schedule-error]').textContent(), /overlap/);
  await screenshot(page, 'schedule-overlap-validation');
  for (let i = 0; i < 6; i++) await form.getByRole('button', { name: 'Add time', exact: true }).click();
  assert.equal(await form.locator('[data-bmg-time-row]').count(), 8);
  assert.equal(await form.getByRole('button', { name: 'Add time', exact: true }).isEnabled(), false);
  for (let i = 0; i < 6; i++) await form.getByRole('button', { name: 'Remove start time', exact: true }).last().click();
  await times.nth(0).fill('06:00 AM'); await times.nth(1).fill('06:00 PM');
  await form.getByLabel('Rotations per run', { exact: true }).fill('7');
  await form.getByRole('button', { name: 'Save schedule', exact: true }).click();
  await confirm(page, 'Save rotation schedule', false);
  assert.equal(scenario.posts.length, 0);
  assert.equal(await form.locator('[data-bmg-time-row]').count(), 2);
});

test('Schedule: confirmed save retains version and time payload; gate-off still allows dynamic 3x7 schedule edits', { timeout: 60000 }, async t => {
  for (const variant of ['full', 'gate-off']) {
    const { page, scenario } = await open(t, { start: fixture.detailPath, variant });
    if (variant === 'gate-off') {
      await page.locator('[data-bmg-preset="08:00,12:00,16:00"]').click();
      assert.equal(await page.locator('[data-bmg-time-row]').count(), 3);
      assert.match(await page.locator('[data-bmg-daily-summary]').textContent(), /3 sessions × 7 rotations = 21 rotations \/ day/);
      assert.match(await page.locator('[data-bmg-daily-target]').textContent(), /21 \/ 21/);
    }
    await page.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await confirm(page, 'Save rotation schedule', true);
    await page.waitForFunction(() => !document.querySelector('form[aria-busy="true"]'));
    await settled(page);
    assert.equal(scenario.posts.length, 1);
    const payload = entries(scenario.posts[0]);
    assert.ok(payload.some(([key, value]) => key === 'expected_version' && value === '3'));
    if (variant === 'full') {
      assert.deepEqual(payload.filter(([key]) => key === 'times[]'), [['times[]', '06:00'], ['times[]', '18:00']]);
    } else {
      assert.deepEqual(payload.filter(([key]) => key === 'times[]'), [['times[]', '08:00'], ['times[]', '12:00'], ['times[]', '16:00']]);
    }
    assert.ok(payload.some(([key, value]) => key === 'rotations' && value === '7'));
    await screenshot(page, 'schedule-' + variant + '-confirmed');
  }
});

test('Commands: run/stop confirmations keep explicit intent and never claim physical movement', { timeout: 60000 }, async t => {
  const { page, scenario } = await open(t, { start: fixture.detailPath });
  await page.getByRole('button', { name: 'Request run now', exact: true }).click();
  await confirm(page, 'Request rotation', false);
  assert.equal(scenario.posts.length, 0);
  await page.getByRole('button', { name: 'Request run now', exact: true }).click();
  await confirm(page, 'Request rotation', true);
  await settled(page);
  await page.waitForFunction(() => !document.querySelector('form[aria-busy="true"]'));
  assert.equal(scenario.posts.length, 1);
  assert.ok(entries(scenario.posts[0]).some(([key, value]) => key === 'command' && value === 'run_now'));
  await page.getByRole('button', { name: 'Request web stop', exact: true }).click();
  await confirm(page, 'Request web stop', true);
  await settled(page);
  await page.waitForFunction(() => !document.querySelector('form[aria-busy="true"]'));
  assert.equal(scenario.posts.length, 2);
  assert.ok(entries(scenario.posts[1]).some(([key, value]) => key === 'command' && value === 'stop'));
  assert.match(await page.locator('.bmg-safety-note').textContent(), /not an emergency stop/);
  await screenshot(page, 'commands-explicit-request-only');
});

test('History: all three independent pagers retain sibling cursors and progressive shell ownership', { timeout: 60000 }, async t => {
  const { page, documents } = await open(t, { start: fixture.detailPath });
  const header = await page.locator('#header').elementHandle();
  for (const [name, key] of [['Drum command history pages', 'commands_page'], ['Drum motor event pages', 'runs_page'], ['Drum past batch pages', 'batches_page']]) {
    await page.getByRole('navigation', { name, exact: true }).getByRole('link', { name: /Next/ }).click();
    await page.waitForURL(url => url.searchParams.get(key) === '2');
    await settled(page);
  }
  const query = new URL(page.url());
  for (const key of ['commands_page', 'runs_page', 'batches_page']) assert.equal(query.searchParams.get(key), '2');
  assert.equal(query.hash, '#bmg-history');
  assert.equal(documents.length, 1);
  assert.equal(await header.evaluate(node => node === document.querySelector('#header')), true);
  assert.equal(await page.locator('#bmg-history section').count(), 3);
  await screenshot(page, 'independent-history-cursors');
});

test('Lifecycle: cached scripts rebind times once across directory/detail/history navigation', { timeout: 60000 }, async t => {
  const { page, documents } = await open(t, { start: '/bmg-fixture-neighbor' });
  await page.getByRole('link', { name: 'Open BMG workstation', exact: true }).click();
  await page.waitForURL(server.origin + fixture.listPath);
  for (let i = 0; i < 2; i++) {
    await page.getByRole('link', { name: 'Manage drum SYN_D001', exact: true }).click();
    await page.waitForURL(server.origin + fixture.detailPath);
    await settled(page);
    const form = page.locator('[data-bmg-schedule]');
    await form.getByRole('button', { name: 'Add time', exact: true }).click();
    assert.equal(await form.locator('[data-bmg-time-row]').count(), 3);
    await form.getByRole('button', { name: 'Remove start time', exact: true }).last().click();
    await page.getByRole('link', { name: 'All drums', exact: true }).click();
    await page.waitForURL(server.origin + fixture.listPath);
    await settled(page);
  }
  assert.equal(documents.length, 1);
  await screenshot(page, 'lifecycle-directory-return');
});

test('Device registration remains native and emits no usable credential in synthetic verification', { timeout: 60000 }, async t => {
  const { page, scenario, documents } = await open(t, { start: fixture.detailPath });
  await page.getByRole('button', { name: 'Rotate device credential', exact: true }).click();
  const modal = page.getByRole('dialog', { name: 'Rotate device credential', exact: true });
  await modal.getByRole('button', { name: 'Issue credential', exact: true }).click();
  await confirm(page, 'Issue device credential', false);
  assert.equal(scenario.posts.length, 0);
  await page.getByRole('button', { name: 'Rotate device credential', exact: true }).click();
  await modal.getByRole('button', { name: 'Issue credential', exact: true }).click();
  await confirm(page, 'Issue device credential', true);
  await page.waitForURL(server.origin + fixture.detailPath + '/device');
  assert.equal(scenario.posts.length, 1);
  assert.equal(scenario.posts[0].headers['x-requested-with'], undefined);
  assert.ok(entries(scenario.posts[0]).some(([key, value]) => key === 'device_id' && value === '99001'));
  assert.match(await page.getByRole('heading').textContent(), /Synthetic registration intent only/);
  assert.equal(await page.locator('[data-bmg-token]').count(), 0);
  assert.equal(documents.length, 2);
});

test('Batch and archival dialogs preserve record-only semantics and required local retirement checks', { timeout: 60000 }, async t => {
  const active = await open(t, { start: fixture.detailPath, variant: 'active-batch' });
  await active.page.getByRole('button', { name: 'Archive drum', exact: true }).click();
  const archive = active.page.getByRole('dialog', { name: 'Archive drum', exact: true });
  assert.equal(await archive.getByRole('button', { name: 'Archive drum', exact: true }).isEnabled(), false);
  assert.equal(await archive.getByLabel(/I have checked safe retirement/).isEnabled(), false);
  await screenshot(active.page, 'archive-active-batch-blocked');
  await archive.getByRole('button', { name: 'Cancel', exact: true }).click();
  await active.page.getByRole('button', { name: 'Complete batch', exact: true }).click();
  const complete = active.page.getByRole('dialog', { name: 'Complete batch', exact: true });
  assert.equal(await complete.getByLabel('Output weight (kg, optional)', { exact: true }).getAttribute('max'), '25.00');
  await complete.getByLabel('Output weight (kg, optional)', { exact: true }).fill('20');
  await complete.getByLabel('Completion notes (optional)', { exact: true }).fill('Synthetic completion notes');
  await complete.getByRole('button', { name: 'Complete batch', exact: true }).click();
  await settled(active.page);
  await active.page.waitForFunction(() => !document.querySelector('form[aria-busy="true"]'));
  assert.equal(active.scenario.posts.length, 1);
  assert.deepEqual(entries(active.scenario.posts[0]), [['output_weight_kg', '20'], ['release_notes', 'Synthetic completion notes']]);
  const full = await open(t, { start: fixture.detailPath });
  await full.page.getByRole('button', { name: 'Archive drum', exact: true }).click();
  const allowed = full.page.getByRole('dialog', { name: 'Archive drum', exact: true });
  await allowed.getByLabel(/I have checked safe retirement/).check();
  await allowed.getByRole('button', { name: 'Archive drum', exact: true }).click();
  await confirm(full.page, 'Archive drum', false);
  assert.equal(full.scenario.posts.length, 0);
});

for (const theme of ['light', 'dark']) for (const width of [375, 768, 1024, 1440]) {
  test(`Responsive: ${width}px ${theme} directory/detail, long text, forms and history cards`, { timeout: 60000 }, async t => {
    const { page } = await open(t, { theme, width, long: true });
    await noOverflow(page); await labels(page);
    await screenshot(page, `directory-${width}-${theme}`);
    await page.getByRole('link', { name: 'Manage drum SYN_D001', exact: true }).click();
    await page.waitForURL(server.origin + fixture.detailPath);
    await settled(page);
    await noOverflow(page); await labels(page);
    await screenshot(page, `detail-${width}-${theme}`);
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await settled(page);
    assert.equal(await page.locator('#bmg-history table').count(), 3);
    assert.equal(await page.locator('#bmg-history [data-label]').count() > 0, true);
    await screenshot(page, `history-${width}-${theme}`);
  });
}

function dashboardData(sections) {
  const attribute = /\bdata-bmg-chart-data=(["'])([\s\S]*?)\1/.exec(sections.content)?.[2];
  assert.ok(attribute, 'The connected dashboard owns its seven-day chart seed.');
  return JSON.parse(attribute.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
}
async function expectDashboard(page, series = fixture.dashboardSeries, { empty = false } = {}) {
  await settled(page);
  const root = page.locator('section[data-bmg-dashboard].bmg-dashboard.cdc-dashboard.ce-workspace');
  await root.waitFor();
  await page.waitForFunction(({ days, series, empty }) => {
    const root = document.querySelector('[data-bmg-dashboard]');
    const activityTarget = document.querySelector('#bmgActivityChart');
    const statusTarget = document.querySelector('#bmgStatusChart');
    const activity = window.ApexCharts?.getChartByID('bmgActivityChart');
    const status = window.ApexCharts?.getChartByID('bmgStatusChart');
    const ready = (chart, target) => chart?.el === target && target?.isConnected && (!chart.w.config.chart.animations.enabled || chart.w.globals.animationEnded);
    return root?.dataset.bmgDashboardBound === 'true' && ready(activity, activityTarget)
      && JSON.stringify(activity.w.config.series[0].data) === JSON.stringify(series)
      && JSON.stringify(activity.w.globals.categoryLabels.length ? activity.w.globals.categoryLabels : activity.w.globals.labels) === JSON.stringify(days)
      && (empty ? !status && !statusTarget.querySelector('.apexcharts-canvas') : ready(status, statusTarget));
  }, { days: fixture.dashboardDays, series, empty }).catch(async error => {
    const diagnostic = await page.evaluate(() => ({
      url: location.pathname,
      bound: document.querySelector('[data-bmg-dashboard]')?.dataset.bmgDashboardBound,
      seed: document.querySelector('[data-bmg-dashboard]')?.getAttribute('data-bmg-chart-data'),
      targets: ['bmgActivityChart', 'bmgStatusChart'].map(id => {
        const target = document.getElementById(id);
        const rect = target?.getBoundingClientRect();
        return { id, connected: target?.isConnected, hidden: target?.hidden, rendered: target?.dataset.chartThemeRendered,
          canvases: target?.querySelectorAll('.apexcharts-canvas').length, svgs: target?.querySelectorAll('svg').length,
          width: rect?.width, height: rect?.height };
      }),
      registered: (window.Apex?._chartInstances || []).map(entry => ({ id: entry.id, target: entry.chart?.el?.id,
        series: entry.chart?.w.config.series, days: entry.chart?.w.config.xaxis.categories,
        categoryLabels: entry.chart?.w.globals.categoryLabels, labels: entry.chart?.w.globals.labels,
        animationEnded: entry.chart?.w.globals.animationEnded }))
    }));
    console.log('BMG dashboard readiness diagnostic: ' + JSON.stringify(diagnostic));
    fs.mkdirSync(evidenceDir, { recursive: true });
    const file = path.join(evidenceDir, `dashboard-readiness-${diagnostic.url.replace(/\W+/g, '-')}-${page.viewportSize().width}-${await page.locator('html').getAttribute('data-theme')}-failed.png`);
    await page.screenshot({ path: file, animations: 'disabled', timeout: 30000 });
    console.log('BMG dashboard readiness screenshot: ' + file);
    throw error;
  });
  const data = JSON.parse(await root.getAttribute('data-bmg-chart-data'));
  assert.deepEqual(data, { days: fixture.dashboardDays, series, status: empty ? [0, 0, 0] : [10, 10, 10] });
  // Apex normalizes category axes to numeric; retain the actual formatted/rendered days.
  const retainedDays = await page.evaluate(() => {
    const globals = window.ApexCharts.getChartByID('bmgActivityChart').w.globals;
    return globals.categoryLabels.length ? globals.categoryLabels : globals.labels;
  });
  assert.deepEqual(retainedDays, fixture.dashboardDays);
  const renderedDays = await root.locator('#bmgActivityChart .apexcharts-xaxis-texts-g text').evaluateAll(nodes => nodes.filter(node => {
    const style = getComputedStyle(node);
    return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
  }).map(node => (node.querySelector('tspan') || node).textContent.trim()).filter(Boolean));
  assert.ok(renderedDays.length > 0, 'The actual SVG axis must contain the current day labels.');
  assert.deepEqual(renderedDays, fixture.dashboardDays.filter(day => renderedDays.includes(day)), 'Visible labels are an ordered current-day subset, never stale or numeric placeholders.');
  if (page.viewportSize().width >= 768) assert.deepEqual(renderedDays, fixture.dashboardDays);
  assert.equal((await root.locator('[data-bmg-period-total]').textContent()).trim(), series.reduce((sum, value) => sum + value, 0).toLocaleString('en-US'));
  assert.equal(await root.locator('#bmgActivityChart .apexcharts-canvas').count(), 1);
  assert.equal(await root.locator('#bmgStatusChart .apexcharts-canvas').count(), empty ? 0 : 1);
  if (empty) {
    assert.equal(await root.locator('[data-bmg-status-empty]').isVisible(), true);
    assert.equal(await root.locator('#bmgStatusChart').isVisible(), false);
    assert.equal(await root.locator('#bmgStatusChart').getAttribute('data-chart-theme-rendered'), null);
  } else {
    assert.equal(await root.locator('[data-bmg-status-empty]:visible').count(), 0);
    const status = await page.evaluate(() => window.ApexCharts.getChartByID('bmgStatusChart')?.w.config.series);
    assert.deepEqual(status, [10, 10, 10]);
  }
  return root.locator('#bmgActivityChart .apexcharts-area').first().getAttribute('d');
}
async function expectDashboardHistory(page, series) {
  const path = await expectDashboard(page, series);
  await dashboardStyleOwnership(page);
  await dashboardHeaderPaint(page);
  await noDashboardOverflow(page);
  const typography = await page.evaluate(() => ({ family: getComputedStyle(document.body).fontFamily,
    metrics: [...document.querySelectorAll('[data-bmg-metric]')].map(node => {
      const style = getComputedStyle(node);
      return { family: style.fontFamily, numeric: style.fontVariantNumeric };
    }) }));
  assert.equal(typography.metrics.length, 4);
  for (const metric of typography.metrics) {
    assert.equal(metric.family, typography.family, 'Every connected history state keeps the inherited metric font.');
    assert.ok(metric.numeric.includes('tabular-nums'));
    assert.ok(metric.numeric.includes('lining-nums'));
  }
  return path;
}
async function dashboardStyleOwnership(page) {
  const ownership = await page.evaluate(() => {
    const owner = document.getElementById('page-styles');
    const expected = new URL('/assets/css/pages/bmg-workstation.css', location.href).href;
    const owned = window.SynapsePageStyles.nodes(owner);
    const imports = owned.flatMap(node => {
      if (node.matches('link')) return node.href === expected ? [{ connected: node.isConnected, parent: node.parentNode?.nodeName, enabled: !node.sheet?.disabled, loaded: !!node.sheet }] : [];
      return Array.from(node.sheet?.cssRules || []).filter(rule => rule.type === 3 && rule.href === expected).map(rule => ({
        connected: node.isConnected, parent: node.parentNode?.nodeName, enabled: !node.sheet.disabled,
        loaded: !!rule.styleSheet && rule.styleSheet.cssRules.length > 0,
        media: node.getAttribute('media')
      }));
    });
    return { parent: owner.parentNode?.nodeName, boundary: owner.getAttribute('data-page-style-owner'), imports,
      dependencies: Array.from(owner.content.querySelectorAll('link[rel~="stylesheet"]')).filter(node => node.href === expected).length };
  });
  assert.equal(ownership.parent, 'HEAD');
  assert.equal(ownership.boundary, 'head');
  assert.equal(ownership.dependencies, 1, 'Retain one original workstation dependency for later page loads.');
  assert.equal(ownership.imports.length, 1, 'Keep one live page-owned workstation stylesheet after history.');
  const asset = ownership.imports[0];
  assert.equal(asset.parent, 'HEAD');
  assert.equal(asset.connected, true);
  assert.equal(asset.enabled, true);
  assert.equal(asset.loaded, true);
  assert.ok(!asset.media || asset.media === 'all', 'Committed stylesheet must not remain in its inactive stage.');
}
async function dashboardHeaderPaint(page) {
  const cells = await page.locator('[data-bmg-unit-table] thead th').evaluateAll(nodes => nodes.map(node => {
    const style = getComputedStyle(node);
    return { label: node.textContent.trim(), background: style.backgroundColor, foreground: style.color,
      subtle: style.getPropertyValue('--ce-subtle').trim(), muted: style.getPropertyValue('--ce-muted-fg').trim() };
  }));
  const tokenRgb = token => {
    assert.match(token, /^#[0-9a-f]{6}$/i, 'Use the inherited, confirmed neutral dashboard color token.');
    return 'rgb(' + [1, 3, 5].map(offset => parseInt(token.slice(offset, offset + 2), 16)).join(',') + ')';
  };
  assert.equal(cells.length, 6);
  for (const cell of cells) {
    assert.equal(cell.background.replace(/\s+/g, ''), tokenRgb(cell.subtle), cell.label + ' neutral header background');
    assert.equal(cell.foreground.replace(/\s+/g, ''), tokenRgb(cell.muted), cell.label + ' neutral header foreground');
  }
}
async function dashboardSemantics(page, { empty = false, zeroBatches = false, unbound = false } = {}) {
  const root = page.locator('[data-bmg-dashboard]');
  for (const label of ['Configured active drums', 'Batch records', 'Bound controllers', 'Released batch input', 'Batch records created', 'Configured drum status', 'Current drum worklist']) assert.equal(await root.getByText(label, { exact: true }).count(), 1, label);
  const expectations = { 'active-units': empty ? /\b0\b/ : /\b10\b/, 'total-batches': empty || zeroBatches ? /\b0\b/ : /\b73\b/, 'bound-devices': empty || unbound ? /\b0\b/ : /\b20\b/, 'released-input': empty || zeroBatches ? /\b0(?:\.0)?\b/ : /\b1,325\.0\b/ };
  for (const [metric, expected] of Object.entries(expectations)) assert.match(await root.locator('[data-bmg-metric="' + metric + '"]').textContent(), expected, metric);
  const text = await root.innerText();
  assert.match(text, /input/i);
  assert.match(text, /bind|bound/i);
  assert.match(text, /not.*liveness|liveness.*not/i);
  assert.equal(/Batches Processed|Treated Waste|Status & Telemetry|Drum Operational Status/.test(text), false, 'Do not rename recorded state as verified physical activity.');
  assert.equal(await root.locator('[data-bmg-unit-table]').count(), 1);
  assert.equal(await root.locator('.bmg-dashboard-row').count(), empty ? 0 : 30);
  await dashboardHeaderPaint(page);
}
async function noDashboardOverflow(page) {
  const result = await page.evaluate(() => ({ width: innerWidth, document: document.documentElement.scrollWidth,
    bad: [...document.querySelectorAll('.bmg-dashboard, [data-bmg-metric], [data-bmg-unit-table], .bmg-dashboard-row, #bmgActivityChart, #bmgStatusChart, .pagetitle .page-actions')].filter(node => node.getClientRects().length && node.scrollWidth > node.clientWidth + 2).map(node => node.id || node.className) }));
  assert.ok(result.document <= result.width + 2, JSON.stringify(result));
  assert.deepEqual(result.bad, [], JSON.stringify(result));
}
async function finiteDashboardSvg(page, { empty = false } = {}) {
  const result = await page.evaluate(() => {
    const roots = [...document.querySelectorAll('#bmgActivityChart svg, #bmgStatusChart svg')];
    const bad = [];
    for (const svg of roots) {
      const rect = svg.getBoundingClientRect();
      if (![rect.x, rect.y, rect.width, rect.height].every(Number.isFinite) || rect.width <= 0 || rect.height <= 0) bad.push('svg-bounds');
      for (const node of [svg, ...svg.querySelectorAll('*')]) {
        for (const attribute of node.attributes) {
          if (/^(?:d|points|transform|viewBox|[xy][12]?|c[xy]|r[xy]?|width|height)$/.test(attribute.name)) {
            if (/NaN|Infinity|undefined/.test(attribute.value)) bad.push(attribute.name + '=' + attribute.value);
            for (const number of attribute.value.matchAll(/[-+]?(?:\d*\.\d+|\d+\.?\d*)(?:e[-+]?\d+)?/gi)) if (!Number.isFinite(Number(number[0]))) bad.push('non-finite-' + attribute.name);
          }
        }
        if (node.localName === 'path' && node.getAttribute('d')) {
          const box = node.getBBox();
          if (![box.x, box.y, box.width, box.height].every(Number.isFinite)) bad.push('path-bounds');
        }
      }
    }
    return { roots: roots.length, paths: roots.reduce((sum, svg) => sum + svg.querySelectorAll('path[d]').length, 0), bad };
  });
  assert.equal(result.roots, empty ? 1 : 2);
  assert.ok(result.paths > 0);
  assert.deepEqual(result.bad, []);
}

test('Dashboard contract: explicit GET route, local styles/assets, seven Manila days and recorded-state labels stay bounded', () => {
  const origin = 'http://127.0.0.1:54444';
  const dashboard = fixture.renderFixture(origin, new URL(origin + fixture.dashboardPath));
  assert.deepEqual(Object.keys(dashboard).sort(), ['content', 'page_actions', 'scripts', 'styles']);
  assert.deepEqual(dashboardData(dashboard), { days: fixture.dashboardDays, series: fixture.dashboardSeries, status: [10, 10, 10] });
  assert.equal((dashboard.styles.match(/pages\/bmg-workstation\.css/g) || []).length, 1);
  assert.equal((dashboard.content.match(/data-bmg-metric=/g) || []).length, 4);
  for (const metric of ['active-units', 'total-batches', 'bound-devices', 'released-input']) assert.ok(dashboard.content.includes('data-bmg-metric="' + metric + '"'));
  assert.ok(dashboard.page_actions.includes(origin + fixture.listPath + '?create=1'));
  assert.ok(dashboard.page_actions.includes(origin + fixture.listPath + '"'));
  assert.equal(fixture.allowedRequest(origin, new URL(origin + fixture.dashboardPath)), true);
  for (const file of ['vendor/apexcharts/apexcharts.min.js', 'js/chart-theme.js']) assert.equal(fixture.allowedRequest(origin, new URL(origin + '/assets/' + file)), true);
  for (const suffix of ['?page=1', '?create=1', '?range=7d', '/series', '/units/store']) assert.equal(fixture.allowedRequest(origin, new URL(origin + fixture.dashboardPath + suffix)), false);
  for (const method of ['POST', 'PUT', 'DELETE', 'HEAD']) assert.equal(fixture.allowedRequest(origin, new URL(origin + fixture.dashboardPath), method), false);
  for (const url of ['https://example.invalid/facilities/dashboard', 'http://user:pass@127.0.0.1:54444/facilities/dashboard', origin + '/assets/js/chart-theme.js?fixture=1', origin + '/assets/vendor/apexcharts/other.js', origin + '/facilities/unit/98001/usb']) assert.equal(fixture.allowedRequest(origin, new URL(url)), false);
  for (const values of [[], [1, 2, 3, 4, 5, 6], [1, 2, 3, 4, 5, 6, 7, 8], [1, 2, 3, 4, 5, 6, -1], [1, 2, 3, 4, 5, 6, 1001], [1, 2, 3, 4, 5, 6, 1.5], [1, 2, 3, 4, 5, 6, '7']]) assert.throws(() => fixture.renderFixture(origin, new URL(origin + fixture.dashboardPath), { dashboardSeries: values }));
  assert.throws(() => fixture.renderFixture(origin, new URL(origin + fixture.listPath), { dashboardSeries: fixture.dashboardSeries }));
  assert.throws(() => fixture.renderFixture(origin, new URL(origin + fixture.dashboardPath + '?page=1')));
});

test('Dashboard direct entry: current metrics, chart geometry and head-owned styles hydrate once', { timeout: 60000 }, async t => {
  const { page, scenario, documents } = await open(t, { start: fixture.dashboardPath });
  await dashboardEvidence(page, 'dashboard-direct-entry-1440-light');
  await expectDashboard(page);
  await dashboardEvidence(page, 'dashboard-direct-1440-light', { worklist: true });
  await dashboardSemantics(page);
  await noDashboardOverflow(page);
  await finiteDashboardSvg(page);
  assert.equal(await page.locator('head link[href$="/pages/bmg-workstation.css"]').count(), 1);
  assert.equal(await page.locator('#main .page-actions .cdc-actions.ce-page-actions').count(), 1, 'Use the real layout action wrapper.');
  assert.equal(await page.locator('.page-actions a[href="' + server.origin + fixture.listPath + '"]').count(), 1);
  assert.equal(await page.locator('.page-actions a[href="' + server.origin + fixture.listPath + '?create=1"]').count(), 1);
  assert.equal(documents.length, 1);
  assert.deepEqual(scenario.requests, [{ method: 'GET', url: fixture.dashboardPath }]);
});

for (const theme of ['light', 'dark']) {
  test(`Dashboard lifecycle: ${theme} progressive entry, re-entry and history use each connected seven-day seed`, { timeout: 120000 }, async t => {
    const visits = [fixture.dashboardSeries, [8, 1, 0, 6, 2, 4, 9], [0, 5, 1, 0, 12, 2, 3], [7, 6, 0, 2, 1, 3, 4], [1, 3, 10, 0, 8, 2, 1]];
    const { page, scenario, documents } = await open(t, { start: '/bmg-fixture-neighbor', theme, dashboardSeriesVisits: visits });
    const header = await page.locator('#header').elementHandle();
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-neighbor`);
    await page.getByRole('link', { name: 'Open BMG dashboard', exact: true }).click();
    await page.waitForURL(server.origin + fixture.dashboardPath);
    const paths = [await expectDashboardHistory(page, visits[0])];
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-entry`, { worklist: true });
    const outgoing = await page.locator('#bmgActivityChart').elementHandle();
    await page.locator('.page-actions').getByRole('link', { name: 'Drum Rotation', exact: true }).click();
    await page.waitForURL(server.origin + fixture.listPath);
    await settled(page);
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-directory`);
    assert.equal(await page.locator('#bmgActivityChart, #bmgStatusChart, #main .apexcharts-canvas').count(), 0);
    assert.equal(await outgoing.evaluate(node => node.isConnected), false);
    assert.equal(await outgoing.getAttribute('data-chart-theme-rendered'), null, 'Destroy detached ChartTheme targets before swapping.');
    await page.locator('.sidebar-nav a[href="' + server.origin + fixture.dashboardPath + '"]').click();
    await page.waitForURL(server.origin + fixture.dashboardPath);
    paths.push(await expectDashboardHistory(page, visits[1]));
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-reentry`, { worklist: true });
    await page.locator('.bmg-dashboard-operations a[href="' + server.origin + fixture.detailPath + '"]').click();
    await page.waitForURL(server.origin + fixture.detailPath);
    await settled(page);
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-detail`);
    assert.equal(await page.locator('#main .apexcharts-canvas').count(), 0);
    await page.goBack();
    await page.waitForURL(server.origin + fixture.dashboardPath);
    paths.push(await expectDashboardHistory(page, visits[2]));
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-history-back`, { worklist: true });
    await page.goForward();
    await page.waitForURL(server.origin + fixture.detailPath);
    await settled(page);
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-history-forward`);
    await page.goBack();
    await page.waitForURL(server.origin + fixture.dashboardPath);
    paths.push(await expectDashboardHistory(page, visits[3]));
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-history-reentry`, { worklist: true });
    await page.locator('.sidebar-nav a[href$="/bmg-fixture-neighbor"]').click();
    await page.waitForURL(server.origin + '/bmg-fixture-neighbor');
    await settled(page);
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-leave`);
    assert.equal(await page.locator('#main .apexcharts-canvas').count(), 0);
    await page.goBack();
    await page.waitForURL(server.origin + fixture.dashboardPath);
    paths.push(await expectDashboardHistory(page, visits[4]));
    await dashboardEvidence(page, `dashboard-lifecycle-${theme}-neighbor-back`, { worklist: true });
    for (let i = 1; i < paths.length; ++i) assert.notEqual(paths[i], paths[i - 1], 'Actual SVG paths must not reuse an outgoing page\'s seed.');
    assert.equal(new Set(paths).size, visits.length);
    assert.equal(scenario.dashboardVisits, visits.length);
    assert.equal(await header.evaluate(node => node === document.querySelector('#header')), true);
    assert.equal(documents.length, 1, 'All dashboard navigation and history retain the shell document.');
    await dashboardStyleOwnership(page);
    await dashboardHeaderPaint(page);
    await finiteDashboardSvg(page);
  });
}

async function openDashboardDailyCounts(page, name) {
  const summary = page.getByText('View daily counts', { exact: true });
  try {
    await summary.click();
  } catch (error) {
    const diagnostic = await page.evaluate(() => {
      const summary = document.querySelector('.bmg-dashboard-chart-data summary');
      const details = summary?.closest('details');
      function snapshot(node) {
        if (!node) return null;
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        const keys = ['display', 'visibility', 'opacity', 'pointerEvents', 'position', 'transform', 'animationName', 'animationDuration', 'animationPlayState', 'transitionProperty', 'transitionDuration'];
        return { tag: node.tagName, rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height, top: rect.top, bottom: rect.bottom },
          style: Object.fromEntries(keys.map(key => [key, style[key]])) };
      }
      const rect = summary?.getBoundingClientRect();
      const point = rect ? { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 } : null;
      const inViewport = point && point.x >= 0 && point.x < innerWidth && point.y >= 0 && point.y < innerHeight;
      const hit = inViewport ? document.elementFromPoint(point.x, point.y) : null;
      return { visibilityState: document.visibilityState, focused: document.hasFocus(), viewport: { width: innerWidth, height: innerHeight, scrollY },
        summary: snapshot(summary), details: snapshot(details), open: details?.open, chartStage: snapshot(summary?.closest('.cdc-chart-stage')),
        point, hit: snapshot(hit), hitSummary: !!hit && (hit === summary || summary.contains(hit)),
        animations: document.getAnimations().slice(0, 20).map(animation => ({ playState: animation.playState, pending: animation.pending,
          currentTime: String(animation.currentTime), target: animation.effect?.target?.tagName, duration: animation.effect?.getTiming().duration })) };
    });
    console.log('BMG native summary diagnostic: ' + JSON.stringify(diagnostic));
    const file = path.join(evidenceDir, name + '-failed.png');
    fs.mkdirSync(evidenceDir, { recursive: true });
    try {
      await page.screenshot({ path: file, animations: 'disabled', timeout: 5000 });
      console.log('BMG native summary screenshot: ' + file);
    } catch (captureError) {
      console.log('BMG native summary capture error: ' + captureError.message);
    }
    throw error;
  }
  assert.equal(await page.locator('.bmg-dashboard-chart-data').evaluate(node => node.open), true, 'Only the genuine native summary click may open the daily values.');
  assert.equal(await page.locator('.bmg-dashboard-chart-data dl').isVisible(), true);
}

for (const theme of ['light', 'dark']) {
  test(`Dashboard no-JS: ${theme} metrics, daily counts, configured status and navigation remain readable`, { timeout: 60000 }, async t => {
    const { page, documents } = await open(t, { start: fixture.dashboardPath, theme, width: 375, javaScriptEnabled: false });
    await openDashboardDailyCounts(page, `dashboard-no-js-375-${theme}-summary`);
    await dashboardEvidence(page, `dashboard-no-js-375-${theme}`, { worklist: true });
    await dashboardSemantics(page);
    const days = await page.locator('.bmg-dashboard-chart-data dl > div').evaluateAll(nodes => nodes.map(node => ({ day: node.querySelector('dt').textContent.trim(), count: node.querySelector('dd').textContent.trim() })));
    assert.deepEqual(days, fixture.dashboardDays.map((day, i) => ({ day, count: String(fixture.dashboardSeries[i]) })));
    assert.match(await page.locator('[data-bmg-dashboard] noscript').innerText(), /Charts require JavaScript/);
    assert.equal(await page.locator('[data-bmg-dashboard]').getAttribute('data-bmg-dashboard-bound'), null);
    assert.equal(await page.locator('#main .apexcharts-canvas').count(), 0);
    await noDashboardOverflow(page);
    assert.equal(await page.locator('.bmg-dashboard-operations a[href="' + server.origin + fixture.detailPath + '"]').isVisible(), true);
    assert.equal(await page.locator('.bmg-dashboard-operations a[href="' + server.origin + fixture.detailPath + '/usb"]').isVisible(), true);
    await page.locator('.page-actions a[href="' + server.origin + fixture.listPath + '"]').click();
    await page.waitForURL(server.origin + fixture.listPath);
    await dashboardEvidence(page, `dashboard-no-js-${theme}-directory`);
    assert.equal(await page.locator('.bmg-drum-card').count(), 25);
    await page.goBack();
    await page.waitForURL(server.origin + fixture.dashboardPath);
    await dashboardEvidence(page, `dashboard-no-js-${theme}-return`, { worklist: true });
    await dashboardSemantics(page);
    assert.equal(await page.locator('#main .apexcharts-canvas').count(), 0);
    assert.ok(documents.length >= 2, 'Without JavaScript, links retain native GET navigation.');
  });
}

for (const variant of ['empty', 'zero-batches']) {
  test(`Dashboard ${variant}: honest zero summaries and finite charts never invent one active drum`, { timeout: 60000 }, async t => {
    const empty = variant === 'empty';
    const zero = [0, 0, 0, 0, 0, 0, 0];
    const { page } = await open(t, { start: fixture.dashboardPath, variant, width: 320, theme: 'dark' });
    await expectDashboard(page, zero, { empty });
    await dashboardEvidence(page, `dashboard-${variant}-320-dark`, { worklist: true });
    await dashboardSemantics(page, { empty, zeroBatches: true });
    await noDashboardOverflow(page);
    await finiteDashboardSvg(page, { empty });
    assert.match(await page.locator('.bmg-dashboard-activity').innerText(), /No batch records were created in this seven-day period/);
    if (empty) {
      assert.match(await page.locator('.bmg-dashboard-aside').innerText(), /No current drums registered\. Configured status will appear after setup\./);
      assert.equal(await page.locator('.bmg-dashboard-operations a[href*="/unit/"]').count(), 0);
      assert.match(await page.locator('[data-bmg-unit-table]').innerText(), /No drum units registered yet/);
    }
  });
}

test('Dashboard permissions: configure and USB gates are independent, while first-drum reading remains available', { timeout: 120000 }, async t => {
  for (const [variant, configure, usb] of [['full', true, true], ['reader', false, false], ['operator', false, true], ['config-only', true, false], ['unbound', true, true]]) {
    const { page } = await open(t, { start: fixture.dashboardPath, variant, width: 375 });
    await expectDashboard(page);
    await dashboardEvidence(page, `dashboard-permission-${variant}-375-light`, { worklist: true });
    await dashboardSemantics(page, { unbound: variant === 'unbound' });
    assert.equal(await page.locator('.page-actions a[href="' + server.origin + fixture.listPath + '"]').count(), 1);
    assert.equal(await page.locator('.page-actions a[href="' + server.origin + fixture.listPath + '?create=1"]').count(), configure ? 1 : 0);
    assert.equal(await page.locator('.bmg-dashboard-operations a[href="' + server.origin + fixture.detailPath + '"]').isVisible(), true);
    assert.equal(await page.locator('.bmg-dashboard-operations a[href="' + server.origin + fixture.detailPath + '/usb"]').count(), usb ? 1 : 0);
    assert.equal(await page.locator('[data-bmg-dashboard] form, [data-bmg-token]').count(), 0);
    await noDashboardOverflow(page);
  }
});

test('Dashboard escaping: invented operator, drum and binding strings remain text even when long', { timeout: 60000 }, async t => {
  const { page } = await open(t, { start: fixture.dashboardPath, long: true, width: 320 });
  await expectDashboard(page);
  await dashboardEvidence(page, 'dashboard-escaped-long-320-light', { worklist: true });
  const root = page.locator('[data-bmg-dashboard]');
  assert.match(await root.locator('.cdc-shift-identity').textContent(), /Synthetic <operator> & "layout" 'only'/);
  assert.match(await root.locator('.bmg-dashboard-row').first().textContent(), /<svg data-bmg-fixture-injection onload="window.fixtureEscapeRan=1"><\/svg>/);
  assert.match(await root.locator('.bmg-dashboard-row [data-label="Controller binding"]').first().textContent(), /<synthetic> & "layout" 'only'/);
  assert.equal(await root.locator('[data-bmg-fixture-injection], operator, synthetic').count(), 0);
  assert.equal(await page.evaluate(() => window.fixtureEscapeRan), undefined);
  const html = await root.innerHTML();
  assert.ok(html.includes('&lt;operator&gt;'));
  assert.ok(html.includes('&lt;svg data-bmg-fixture-injection'));
  await noDashboardOverflow(page);
  await finiteDashboardSvg(page);
});

for (const theme of ['light', 'dark']) for (const width of [320, 375, 768, 1024, 1440]) {
  test(`Dashboard responsive: ${width}px ${theme} long content, readable worklist and finite SVG`, { timeout: 60000 }, async t => {
    const { page } = await open(t, { start: fixture.dashboardPath, theme, width, long: true });
    await expectDashboard(page);
    await dashboardEvidence(page, `dashboard-${width}-${theme}`, { worklist: true });
    await dashboardSemantics(page);
    await noDashboardOverflow(page);
    await finiteDashboardSvg(page);
    const rows = page.locator('.bmg-dashboard-row');
    assert.equal(await rows.first().locator('[data-label]').count(), 6);
    assert.equal(await page.locator('[data-bmg-unit-table]').getAttribute('data-mobile-cards'), 'false', 'Only the scoped dashboard stylesheet owns mobile cards.');
    assert.equal(await page.locator('.page-actions').getByRole('link', { name: 'Add drum', exact: true }).isVisible(), true);
  });
}
