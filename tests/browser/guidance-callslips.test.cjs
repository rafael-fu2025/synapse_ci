'use strict';

// Run only this file: node --test --test-concurrency=1 tests/browser/guidance-callslips.test.cjs
// Actual view/assets, synthetic directory and RAM-only captures. No application
// boot, dotenv, DB, official PDF issuance, external traffic or package installs.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const evidenceDir = path.resolve(__dirname, '../../writable/guidance-callslips-preview');
async function screenshot(page, name) {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const file = path.join(evidenceDir, name + '.png');
  await page.screenshot({ path: file, animations: 'disabled' });
  console.log(`Call Slips screenshot: ${file}`);
}
const {
  startServer, renderFixture, localPlaywright, installedChromium, allowedRequest, fixtureData,
  listPath, lookupPath, owner, statuses, purposes, people, limits,
} = require('../_support/guidance-callslips-browser-harness.cjs');
let harness, browser;
const rootSelector = 'section.guidance-ui.ce-workspace[data-live-filters][data-filter-key="guidance-call-slips"]';
const workspace = page => page.locator(rootSelector);
const region = (page, name) => workspace(page).locator(`[data-filter-region="${name}"]`);
const table = page => workspace(page).locator('table.ce-table-mobile.gcs-callslips-table');
const rows = page => table(page).locator('tbody > tr').filter({ has: page.locator('form[data-background-submit]') });
const modal = page => page.locator('#issueCallSlipModal');
const issueForm = page => modal(page).locator('form[data-background-submit]');
const submitIssue = page => issueForm(page).getByRole('button', { name: 'Issue Controlled Call Slip', exact: true });
const slipNumber = id => `CS-2026-${String(id).padStart(4, '0')}`;
const statusForm = (page, id = 41) => rows(page).locator(`form[action$="/call-slips/${id}/status"]`);
const optionText = key => key === '' ? 'All' : key.charAt(0).toUpperCase() + key.slice(1);
const sorted = fields => [...fields].sort((a, b) => a[0].localeCompare(b[0]));

before(async () => {
  const { chromium } = localPlaywright();
  harness = await startServer();
  browser = await chromium.launch({ headless: true, executablePath: installedChromium(chromium), timeout: 20000,
    args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run'] });
}, { timeout: 30000 });
after(async () => {
  try { await browser?.close(); } finally { await harness?.close(); }
  assert.deepEqual(harness?.violations, [], 'Server must receive only allowlisted synthetic Call Slips and local assets');
  assert.equal(harness?.counts.rejected, 0);
});

async function open(t, { pathname = listPath, theme = 'light', viewport = { width: 1440, height: 1000 }, ...options } = {}) {
  const state = harness.createScenario({ theme, ...options });
  const context = await browser.newContext({ viewport, colorScheme: theme, reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
  await context.addCookies([{ name: 'synthetic_callslips', value: state.id, url: harness.origin }]);
  const denied = [], errors = [], consoles = [], failures = [];
  const record = (list, value) => { if (list.length < limits.diagnostics) list.push(value); else if (list.length === limits.diagnostics) list.push('Diagnostic bound exceeded'); };
  const expectedStatuses = new Set([...(options.postStatuses || []), ...(options.getStatuses || []), ...(options.lookupStatuses || [])].filter(status => status >= 400));
  await context.route('**/*', route => {
    const request = route.request();
    if (!allowedRequest(harness.origin, state, request.method(), request.url())) {
      record(denied, `${request.method()} ${request.url()}`); return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  await context.routeWebSocket('**/*', socket => { record(denied, `Unexpected WebSocket ${socket.url()}`); socket.close(); });
  const page = await context.newPage();
  page.setDefaultTimeout(20000); page.setDefaultNavigationTimeout(45000);
  page.on('pageerror', error => { record(errors, error.message); console.error(`Call Slips pageerror: ${error.stack || error.message}`); });
  page.on('console', message => {
    if (message.type() !== 'error') return;
    const status = Number(/Failed to load resource: the server responded with a status of (\d+)/.exec(message.text())?.[1]);
    if (!expectedStatuses.has(status)) record(consoles, message.text());
  });
  page.on('response', response => {
    if (response.status() < 400) return;
    const url = new URL(response.url()), request = response.request();
    const expected = url.origin === harness.origin && expectedStatuses.has(response.status()) &&
      (request.method() === 'POST' && url.pathname.startsWith(listPath + '/') || request.method() === 'GET' && [listPath, lookupPath].includes(url.pathname));
    if (!expected) record(failures, `${response.status()} ${request.method()} ${response.url()}`);
  });
  page.on('download', download => { record(denied, 'Unexpected PDF/export download'); void download.cancel(); });
  page.on('popup', popup => {
    if (options.pdfFixtures === true) return;
    record(denied, 'Unexpected PDF/issuance popup'); void popup.close();
  });
  page.on('dialog', dialog => { record(denied, `Unexpected native ${dialog.type()}: ${dialog.message()}`); void dialog.dismiss(); });
  let closed = false;
  async function close() {
    if (closed) return;
    closed = true;
    try { assert.equal(await page.evaluate(() => window.fixtureEventOverflow || 0), 0); }
    finally { await context.close(); }
    assert.deepEqual(denied, [], 'No external traffic, PDF route, export or unrelated app route is permitted');
    assert.deepEqual(errors, [], 'Actual view/widgets must not throw browser exceptions');
    assert.deepEqual(consoles, [], 'No unexpected console errors');
    assert.deepEqual(failures, [], 'Every actual local asset must load');
  }
  t.after(close);
  await page.goto(harness.origin + pathname, { waitUntil: 'domcontentloaded' });
  await ready(page);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return { page, state, close };
}
async function ready(page) {
  await page.waitForFunction(() => document.readyState === 'complete' && window.Alpine && window.SynapseLiveFilters &&
    window.SynapseBackgroundActions && window.SynapseMutations && window.SynapseNavigation && window.SynapseRefreshRegions &&
    document.querySelector('#callSlipPurpose')?.closest('.bd-select'));
}
async function openIssue(page) {
  await page.getByRole('button', { name: 'Issue Call Slip', exact: true }).click();
  await modal(page).waitFor({ state: 'visible' });
  await modal(page).locator('.modal-body').waitFor({ state: 'visible' });
  assert.match(await issueForm(page).getAttribute('x-data'), /^callSlipPersonPicker\(/);
  assert.equal(await modal(page).locator('.dp-wrap #callSlipDateDisplay').count(), 1);
  assert.equal(await modal(page).locator('.dtp-wrap #callSlipTimeDisplay').count(), 1);
}
async function choosePurpose(page, value) {
  const source = issueForm(page).locator('#callSlipPurpose');
  const label = await source.locator('option').evaluateAll((options, key) => options.find(option => option.value === key)?.label, value);
  assert.ok(label, `Rendered valid purpose is missing: ${value}`);
  const wrap = source.locator('..');
  const trigger = wrap.getByRole('combobox');
  await trigger.scrollIntoViewIfNeeded();
  await trigger.click();
  const menuId = await trigger.getAttribute('aria-controls');
  assert.ok(menuId);
  const menu = page.locator(`[id="${menuId}"]`);
  await menu.waitFor({ state: 'visible' });
  await menu.getByRole('option', { name: label, exact: true }).click();
  assert.equal(await source.inputValue(), value);
}
async function assertRows(page, state, status = '') {
  const expected = [...state.slips.values()].filter(slip => !status || slip.attendance_status === status);
  assert.equal(await rows(page).count(), expected.length);
  assert.deepEqual(await rows(page).locator('.gcs-slip-number').allTextContents(), expected.map(slip => slip.call_slip_number));
  assert.equal(await workspace(page).locator('[data-filter-form] input[name="status"]').inputValue(), status);
  assert.equal(await region(page, 'tabs').locator('[aria-current="page"]').textContent().then(text => text.trim()), optionText(status));
  assert.equal(await region(page, 'tabs').locator('.active').count(), 1);
  if (expected.length === 0) {
    assert.equal(await table(page).locator('tbody > tr').count(), 1);
    assert.equal(await table(page).locator('td[colspan="6"]').count(), 1);
    await table(page).getByText(/No call slips found/).waitFor({ state: 'visible' });
  }
}
async function filter(page, state, status) {
  const count = await page.evaluate(() => window.fixtureFilterEvents.length);
  await region(page, 'tabs').getByRole('link', { name: optionText(status), exact: true }).click();
  await page.waitForFunction(expected => window.fixtureFilterEvents.length === expected, count + 1);
  assert.equal(new URL(page.url()).searchParams.get('status') || '', status);
  await assertRows(page, state, status);
}
async function shellHandles(page) {
  const selectors = ['#main', '#header', '#sidebar', rootSelector, '[data-filter-form]', '#issueCallSlipModal', '#callSlipPurpose'];
  return Promise.all(selectors.map(async selector => [selector, await page.locator(selector).elementHandle()]));
}
async function assertIdentity(handles) {
  for (const [selector, handle] of handles) {
    assert.ok(handle, `Expected actual shell/modal anchor: ${selector}`);
    assert.equal(await handle.evaluate((node, selector) => node === document.querySelector(selector), selector), true,
      `Live filtering/history must retain ${selector}`);
  }
}
async function csrfAll(page, value) {
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), value);
  const values = await page.locator('input[name="fixture_csrf"]').evaluateAll(inputs => inputs.map(input => input.value));
  assert.ok(values.length && values.every(token => token === value), 'All real forms must use the rotated synthetic CSRF token');
}
async function sendAttendance(page, state, id, value) {
  const form = statusForm(page, id);
  const source = form.getByLabel(`Attendance status for ${slipNumber(id)}`, { exact: true });
  const options = await source.locator('option').evaluateAll(options => options.map(option => option.value));
  assert.deepEqual(options, statuses);
  assert.equal(await source.getAttribute('data-native-select'), '');
  assert.equal(await form.locator('.bd-select').count(), 0, 'Attendance remains a small native control');
  await source.selectOption(value);
  const postCount = state.posts.length;
  const getResponse = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === listPath);
  await form.getByRole('button', { name: `Save attendance for ${slipNumber(id)}`, exact: true }).click();
  await getResponse;
  const currentStatus = new URL(page.url()).searchParams.get('status') || '';
  await page.waitForFunction(({ id, value, filteredOut }) => {
    const form = document.querySelector(`form[action$="/call-slips/${id}/status"]`);
    return !document.body.classList.contains('is-navigating') && !document.querySelector('#main').inert &&
      (filteredOut ? !form : form?.querySelector('select')?.value === value && !form.querySelector('select').disabled);
  }, { id, value, filteredOut: !!currentStatus && currentStatus !== value });
  await ready(page);
  assert.equal(state.posts.length, postCount + 1, 'One explicit attendance save sends exactly one POST');
  assert.deepEqual(sorted(state.posts.at(-1).fields), sorted([['fixture_csrf', `callslips-token-${postCount}`], ['attendance_status', value]]));
  assert.equal(state.posts.at(-1).headers['x-fixture-csrf'], `callslips-token-${postCount}`);
  assert.equal(state.posts.at(-1).headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(state.posts.at(-1).headers['x-synapse-feedback'], 'toast');
  assert.equal(state.posts.at(-1).status, 200);
  assert.equal(state.slips.get(id).attendance_status, value);
  await csrfAll(page, state.csrf);
}
async function fillSchedule(page) {
  const date = issueForm(page).getByLabel('Call Date', { exact: false });
  await date.fill('31/01/2030');
  await issueForm(page).locator('#callSlipDeliveryNotes').click();
  assert.equal(await issueForm(page).locator('[name="call_date"]').inputValue(), '2030-01-31');
  await date.click();
  const calendar = page.getByRole('dialog', { name: 'Choose date', exact: true });
  await calendar.waitFor({ state: 'visible' });
  await calendar.getByRole('button', { name: 'Next month', exact: true }).click();
  await calendar.locator('[data-date="2030-02-01"]').click();
  await calendar.waitFor({ state: 'hidden' });
  assert.equal(await date.inputValue(), '01/02/2030');
  assert.equal(await issueForm(page).locator('[name="call_date"]').inputValue(), '2030-02-01');
  await issueForm(page).getByLabel('Call Time', { exact: false }).click();
  const clock = page.getByRole('dialog', { name: 'Choose time', exact: true });
  await clock.waitFor({ state: 'visible' });
  await clock.getByRole('listbox', { name: 'Hours', exact: true }).getByRole('option', { name: '02', exact: true }).click();
  await clock.getByRole('listbox', { name: 'Minutes', exact: true }).getByRole('option', { name: '45', exact: true }).click();
  await clock.getByRole('listbox', { name: 'AM or PM', exact: true }).getByRole('option', { name: 'PM', exact: true }).click();
  await clock.getByRole('button', { name: 'Apply', exact: true }).click();
  await clock.waitFor({ state: 'hidden' });
  assert.equal(await issueForm(page).locator('[name="call_time"]').inputValue(), '14:45');
}

// Structural checks render only the dedicated no-bootstrap fixture, never the
// ordinary shared Guidance renderer used by the mixed legacy test file.
test('Isolated renderer exposes the six-column contract and canonical purposes, and refuses foreign origins/views', () => {
  const html = renderFixture({ origin: 'http://127.0.0.1:12345', path: listPath, callSlips: fixtureData() });
  assert.match(html, /^<!doctype html>/);
  assert.match(html, /data-filter-key="guidance-call-slips"/);
  assert.match(html, /data-refresh-owner="guidance\.call-slips"/);
  assert.match(html, /data-refresh-regions="tabs list"/);
  assert.match(html, /form[^>]+x-data="callSlipPersonPicker\(/);
  for (const purpose of purposes) assert.ok(html.includes(`value="${purpose}"`));
  assert.doesNotMatch(html, /value="(?:counseling|intake|evaluation|guardian_conference|other)"/);
  assert.throws(() => renderFixture({ origin: 'https://example.com', path: listPath }));
  assert.throws(() => renderFixture({ origin: 'http://127.0.0.1:12345', path: '/portal' }));
});

test('All and five live status filters plus Back/Forward replace only tabs/list and retain shell/modal identity and prefill', { timeout: 90000 }, async t => {
  const pathname = `${listPath}?person_id=${people[0].id}&episode_id=914`;
  const { page, state } = await open(t, { pathname });
  const handles = await shellHandles(page);
  assert.equal(await workspace(page).locator('[data-filter-form]').isVisible(), true);
  assert.equal(await workspace(page).locator('[data-filter-error]').isVisible(), false);
  assert.equal(await workspace(page).getAttribute('data-mutation-refresh'), null);
  assert.deepEqual(await table(page).locator('thead th').allTextContents(), ['Call Slip', 'Student / Client', 'Schedule', 'Purpose', 'Attendance', 'Print Options']);
  assert.deepEqual(await region(page, 'tabs').getByRole('link').allTextContents().then(values => values.map(value => value.trim())), ['All', ...statuses.map(optionText)]);
  assert.equal(await region(page, 'tabs').locator('.badge,.ge-folder-count').count(), 0, 'Status tabs are labels, not pill counters');
  assert.equal(await workspace(page).locator('[data-filter-region]').count(), 2);
  assert.equal(await workspace(page).locator('[data-filter-region] .modal,[data-filter-region] script').count(), 0);
  await assertRows(page, state);
  for (const status of [...statuses, '']) {
    await filter(page, state, status);
    await assertIdentity(handles);
    assert.equal(new URL(page.url()).searchParams.get('person_id'), String(people[0].id));
    assert.equal(new URL(page.url()).searchParams.get('episode_id'), '914');
  }
  let count = await page.evaluate(() => window.fixtureFilterEvents.length);
  await page.goBack();
  await page.waitForFunction(expected => window.fixtureFilterEvents.length === expected, ++count);
  await assertRows(page, state, 'cancelled'); await assertIdentity(handles);
  await page.goForward();
  await page.waitForFunction(expected => window.fixtureFilterEvents.length === expected, ++count);
  await assertRows(page, state); await assertIdentity(handles);
  assert.equal(state.posts.length, 0);
  assert.equal(state.requests.filter(request => request.method === 'GET' && request.path.startsWith(listPath)).length, 9);
  assert.ok(state.requests.slice(1).every(request => request.headers['x-requested-with'] === 'XMLHttpRequest'));
  await openIssue(page);
  assert.equal(await issueForm(page).locator('[name="person_id"]').inputValue(), String(people[0].id));
  assert.equal(await issueForm(page).getByLabel(/Episode ID/).inputValue(), '914');
  assert.equal(await submitIssue(page).isEnabled(), true);
  await modal(page).getByRole('button', { name: 'Close', exact: true }).click();
});

test('Closing an edited Issue modal leaves live filters operable and retains the persistent modal draft without a write', { timeout: 90000 }, async t => {
  const { page, state } = await open(t, { pathname: `${listPath}?person_id=${people[0].id}&episode_id=914` });
  const handles = await shellHandles(page);
  await openIssue(page);
  const notes = 'Synthetic canceled modal draft remains local';
  await issueForm(page).getByLabel('Delivery / Routing Notes', { exact: false }).fill(notes);
  await modal(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal(page).waitFor({ state: 'hidden' });
  assert.equal(state.posts.length, 0);
  await filter(page, state, 'acknowledged');
  await assertIdentity(handles);
  assert.equal(await issueForm(page).getByLabel('Delivery / Routing Notes', { exact: false }).inputValue(), notes);
  assert.equal(await issueForm(page).locator('[name="person_id"]').inputValue(), String(people[0].id));
  assert.equal(await issueForm(page).getByLabel(/Episode ID/).inputValue(), '914');
  assert.equal(state.posts.length, 0, 'Cancel and filter must never issue even a synthetic Call Slip');
});

test('Filtered and globally empty states retain the six-column empty row; failed GET keeps old results and Retry is read-only', { timeout: 90000 }, async t => {
  const partial = await open(t, { callSlips: fixtureData().slice(0, 2) });
  await filter(partial.page, partial.state, 'cancelled');
  await filter(partial.page, partial.state, '');
  await partial.close();
  const empty = await open(t, { empty: true });
  await assertRows(empty.page, empty.state);
  await screenshot(empty.page, 'empty-1440-light');
  await filter(empty.page, empty.state, 'missed');
  assert.equal(empty.state.posts.length, 0);
  await empty.close();
  const { page, state } = await open(t, { getStatuses: [503, 200] });
  const handles = await shellHandles(page), previousUrl = page.url();
  await region(page, 'tabs').getByRole('link', { name: 'Pending', exact: true }).click();
  await workspace(page).locator('[data-filter-error]').waitFor({ state: 'visible' });
  await workspace(page).getByText(/Unable to update results/).waitFor({ state: 'visible' });
  assert.equal(page.url(), previousUrl);
  await assertRows(page, state); await assertIdentity(handles);
  await workspace(page).getByRole('button', { name: 'Retry', exact: true }).click();
  await page.waitForFunction(() => window.fixtureFilterEvents.length === 1);
  await assertRows(page, state, 'pending'); await assertIdentity(handles);
  assert.equal(await workspace(page).locator('[data-filter-error]').isVisible(), false);
  assert.equal(state.posts.length, 0);
  assert.equal(state.requests.length, 3);
});

test('Attendance captures every valid native status once, preserves the current filter, and supports enabled regions and legacy navigation', { timeout: 120000 }, async t => {
  for (const regionalEnabled of [true, false]) {
    const { page, state, close } = await open(t, { pathname: listPath + '?status=', regionalEnabled });
    const handles = await shellHandles(page);
    for (const value of statuses) {
      const beforeUrl = page.url();
      await sendAttendance(page, state, 41, value);
      assert.equal(new URL(page.url()).searchParams.get('status') || '', '');
      assert.equal(new URL(beforeUrl).pathname, new URL(page.url()).pathname);
      if (regionalEnabled) await assertIdentity(handles);
    }
    if (regionalEnabled) {
      const events = await page.evaluate(() => window.fixtureRefreshEvents);
      assert.equal(events.length, 5);
      assert.ok(events.every(event => event.name === 'refresh_committed' && event.owner === owner && event.status === 'refreshed'));
      assert.ok(events.every(event => JSON.stringify(event.regions) === JSON.stringify(['tabs', 'list'])));
    }
    await filter(page, state, 'pending');
    const filteredUrl = page.url();
    await sendAttendance(page, state, 36, 'cancelled');
    assert.equal(page.url(), filteredUrl, 'Saving attendance must keep the requested filter even when its last row leaves');
    await assertRows(page, state, 'pending');
    await close();
  }
});

test('Attendance 422 unlocks and retains the edited status and CSRF; only an explicit retry writes again', { timeout: 90000 }, async t => {
  const { page, state } = await open(t, { postStatuses: [422, 200], postDelayMs: 250 });
  const form = statusForm(page), source = form.getByLabel(`Attendance status for ${slipNumber(41)}`);
  await screenshot(page, 'attendance-1440-light-before');
  await source.selectOption('missed');
  const save = form.getByRole('button', { name: `Save attendance for ${slipNumber(41)}` });
  await save.click();
  await page.waitForFunction(() => document.querySelector('form[action$="/call-slips/41/status"]').getAttribute('aria-busy') === 'true');
  assert.equal(await source.isEnabled(), false);
  assert.equal(await save.isEnabled(), false);
  await page.locator('.synapse-toast-message').filter({ hasText: 'Synthetic validation:' }).waitFor({ state: 'visible' });
  await page.waitForFunction(() => !document.querySelector('form[action$="/call-slips/41/status"]').hasAttribute('aria-busy'));
  assert.equal(await source.inputValue(), 'missed'); assert.equal(await source.isEnabled(), true); assert.equal(await save.isEnabled(), true);
  assert.equal(state.posts.length, 1); assert.equal(state.slips.get(41).attendance_status, 'pending');
  await csrfAll(page, 'callslips-token-1');
  await sendAttendance(page, state, 41, 'missed');
  assert.equal(state.posts.length, 2);
});

test('Issue lookup/error/empty/pick, Others required validation, real date/time controls and 422/retry serialize client, episode, guardian and notes', { timeout: 90000 }, async t => {
  const { page, state } = await open(t, { pathname: listPath + '?status=pending', viewport: { width: 390, height: 844 },
    lookupStatuses: [503, 200, 200], postStatuses: [422, 200], postDelayMs: 250 });
  await openIssue(page);
  assert.equal(await submitIssue(page).isEnabled(), false);
  assert.equal(await issueForm(page).locator('#callSlipPurpose').inputValue(), 'personal_counseling');
  assert.deepEqual(await issueForm(page).locator('#callSlipPurpose option').evaluateAll(options => options.map(option => option.value)), purposes);
  const other = issueForm(page).getByLabel('Specify Other Purpose', { exact: false });
  assert.equal(await other.isVisible(), false); assert.equal(await other.isEnabled(), false);
  const search = issueForm(page).getByLabel('Student / Client', { exact: false });
  assert.equal(await search.getAttribute('id'), 'callSlipPersonSearch');
  await search.fill('M'); await page.waitForTimeout(400); // Observe the actual 300ms minimum-length debounce.
  assert.equal(state.requests.filter(request => request.path.startsWith(lookupPath)).length, 0);
  await search.fill('NoSyntheticMatch');
  await issueForm(page).getByText('Directory lookup could not be completed. Try again.', { exact: true }).waitFor({ state: 'visible' });
  await issueForm(page).getByRole('button', { name: 'Retry lookup', exact: true }).click();
  await issueForm(page).getByText('No matching clients. Try another name or MIS ID.', { exact: true }).waitFor({ state: 'visible' });
  assert.equal(await submitIssue(page).isEnabled(), false);
  await search.fill(people[0].mis_id);
  await issueForm(page).getByRole('button', { name: new RegExp(people[0].first_name + ' ' + people[0].last_name) }).click();
  assert.equal(await issueForm(page).locator('[name="person_id"]').inputValue(), String(people[0].id));
  assert.equal(await search.isVisible(), false); assert.equal(await submitIssue(page).isEnabled(), true);
  await choosePurpose(page, 'others');
  await other.waitFor({ state: 'visible' });
  assert.equal(await other.isEnabled(), true); assert.equal(await other.evaluate(input => input.required && input.validity.valueMissing), true);
  await submitIssue(page).click();
  assert.equal(state.posts.length, 0, 'Blank Others explanation must not reach even the synthetic POST endpoint');
  const explanation = 'Synthetic adviser coordination <literal> & "quoted"';
  const notes = 'Synthetic guardian delivery only; no clinical notes.';
  await other.fill(explanation);
  await issueForm(page).getByLabel(/Episode ID/).fill('914');
  await fillSchedule(page);
  await issueForm(page).getByLabel('Flag for Parent / Guardian Delivery', { exact: true }).check();
  await issueForm(page).getByLabel('Delivery / Routing Notes', { exact: false }).fill(notes);
  await submitIssue(page).click();
  await page.locator('.synapse-toast-message').filter({ hasText: 'Synthetic validation:' }).waitFor({ state: 'visible' });
  await page.waitForFunction(() => !document.querySelector('#issueCallSlipModal form').hasAttribute('aria-busy'));
  await screenshot(page, 'issue-422-390-light');
  assert.equal(await modal(page).isVisible(), true); assert.equal(await submitIssue(page).isEnabled(), true);
  assert.equal(await other.inputValue(), explanation); assert.equal(await other.isEnabled(), true);
  assert.equal(await issueForm(page).getByLabel('Delivery / Routing Notes', { exact: false }).inputValue(), notes);
  assert.equal(state.posts.length, 1); assert.equal(state.slips.size, 6);
  await csrfAll(page, 'callslips-token-1');
  const nextGet = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === listPath);
  await submitIssue(page).click(); await nextGet; await ready(page);
  await page.waitForFunction(() => !document.body.classList.contains('is-navigating') && !document.querySelector('#issueCallSlipModal').classList.contains('show'));
  const expected = [['person_id', String(people[0].id)], ['episode_id', '914'], ['call_date', '2030-02-01'], ['call_time', '14:45'],
    ['purpose', 'others'], ['purpose_other', explanation], ['parent_guardian_delivery', '1'], ['delivery_notes', notes]];
  assert.equal(state.posts.length, 2); assert.equal(state.slips.size, 7);
  for (const [index, post] of state.posts.entries()) {
    assert.equal(post.path, listPath + '/issue');
    assert.deepEqual(sorted(post.fields), sorted([['fixture_csrf', `callslips-token-${index}`], ...expected]));
    assert.equal(post.headers['x-fixture-csrf'], `callslips-token-${index}`);
  }
  assert.equal(new URL(page.url()).searchParams.get('status'), 'pending');
  assert.equal(await page.locator('.modal-backdrop').count(), 0);
  assert.equal(await page.locator('body').evaluate(body => body.classList.contains('modal-open')), false);
  await csrfAll(page, 'callslips-token-2');
  assert.equal(await issueForm(page).isVisible(), false);
  assert.equal(await issueForm(page).locator('[name="person_id"]').inputValue(), '');
  assert.equal(await issueForm(page).locator('#callSlipPurpose').inputValue(), 'personal_counseling');
  assert.equal(await issueForm(page).getByLabel('Delivery / Routing Notes', { exact: false }).inputValue(), '');
  assert.equal(await issueForm(page).locator('button[type="submit"]').isEnabled(), false, 'Successful navigation must not retain a previously issued client selection');
});

test('Episode/client prefill and valid default survive Change lookup; hidden stale Others and unchecked guardian are omitted from the Issue payload', { timeout: 90000 }, async t => {
  const { page, state } = await open(t, { pathname: `${listPath}?person_id=${people[0].id}&episode_id=914&status=missed` });
  await openIssue(page);
  assert.equal(await issueForm(page).locator('[name="person_id"]').inputValue(), String(people[0].id));
  assert.equal(await issueForm(page).getByLabel(/Episode ID/).inputValue(), '914');
  assert.equal(await submitIssue(page).isEnabled(), true);
  await issueForm(page).getByRole('button', { name: 'Change', exact: true }).click();
  assert.equal(await submitIssue(page).isEnabled(), false);
  const search = issueForm(page).getByLabel('Student / Client', { exact: false });
  await search.fill(people[1].mis_id);
  await issueForm(page).getByRole('button', { name: new RegExp(people[1].first_name + ' ' + people[1].last_name) }).click();
  assert.equal(await issueForm(page).locator('[name="person_id"]').inputValue(), String(people[1].id));
  await choosePurpose(page, 'others');
  await issueForm(page).getByLabel('Specify Other Purpose', { exact: false }).fill('Synthetic stale explanation must not serialize');
  await choosePurpose(page, 'personal_counseling');
  const other = issueForm(page).getByLabel('Specify Other Purpose', { exact: false });
  assert.equal(await other.isVisible(), false); assert.equal(await other.isEnabled(), false);
  assert.equal(await other.evaluate(input => input.required), false);
  await fillSchedule(page);
  const previousUrl = page.url(), nextGet = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === listPath);
  await submitIssue(page).click(); await nextGet; await ready(page);
  await page.waitForFunction(() => !document.body.classList.contains('is-navigating') && !document.querySelector('#issueCallSlipModal').classList.contains('show'));
  assert.equal(page.url(), previousUrl, 'Issue navigation retains current status and episode context');
  assert.equal(state.posts.length, 1);
  assert.deepEqual(sorted(state.posts[0].fields), sorted([['fixture_csrf', 'callslips-token-0'], ['person_id', String(people[1].id)],
    ['episode_id', '914'], ['call_date', '2030-02-01'], ['call_time', '14:45'], ['purpose', 'personal_counseling'], ['delivery_notes', '']]));
  assert.equal(state.posts[0].status, 200);
  assert.equal(await page.locator('.modal-backdrop').count(), 0);
  await assertRows(page, state, 'missed');
});

test('Print buttons reach all four PDF endpoints through isolated popup landing pages and leave attendance intact', { timeout: 120000 }, async t => {
  const { page, state } = await open(t, { pdfFixtures: true });
  // Exercise the real anchors, but substitute a clearly synthetic landing page
  // for Chromium's PDF plugin (its popup loading blocks automation). Validate
  // the raw application/pdf response separately after each actual click.
  await page.context().route('**/guidance/call-slips/*/pdf?*', async route => {
    const request = route.request();
    if (!allowedRequest(harness.origin, state, request.method(), request.url())) return route.abort('blockedbyclient');
    const pdf = await route.fetch();
    assert.equal(pdf.status(), 200);
    assert.match(pdf.headers()['content-type'], /^application\/pdf/);
    await route.fulfill({ response: pdf, contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><title>Synthetic PDF button destination</title><h1>Synthetic PDF button destination</h1><p>Test-only landing page; raw PDF is validated separately.</p>' });
  });
  const sourceUrl = page.url(), sourceMain = await page.locator('#main').elementHandle();
  const row = rows(page).filter({ has: page.locator('.gcs-slip-number', { hasText: 'CS-2026-0041' }) });
  assert.equal(await row.count(), 1);
  const actions = [
    { name: 'Preview Single A4 for CS-2026-0041', layout: 'single', mode: 'preview' },
    { name: 'Preview 4-Up Tear-Off for CS-2026-0041', layout: 'quad', mode: 'preview' },
    { name: 'Issue Official — Single A4', layout: 'single', mode: 'issue' },
    { name: 'Issue Official — 4-Up Tear-Off Sheet', layout: 'quad', mode: 'issue' },
  ];
  for (const action of actions) {
    if (action.mode === 'issue') {
      await row.getByRole('button', { name: 'Official print options for CS-2026-0041', exact: true }).click();
      await row.locator('.dropdown-menu.show').waitFor({ state: 'visible' });
    }
    const responsePromise = page.context().waitForEvent('response', response => {
      const url = new URL(response.url());
      return response.request().method() === 'GET' && url.pathname === listPath + '/41/pdf'
        && url.searchParams.get('layout') === action.layout && url.searchParams.get('mode') === action.mode;
    });
    const popupPromise = page.waitForEvent('popup');
    await row.getByRole('link', { name: action.name, exact: true }).click({ noWaitAfter: true });
    const [response, popup] = await Promise.all([responsePromise, popupPromise]);
    assert.equal(response.status(), 200);
    assert.match(response.headers()['content-type'], /^text\/html/);
    assert.match(response.headers()['content-disposition'], /inline; filename="synthetic-call-slip-41-/);
    assert.equal(response.headers()['x-synthetic-pdf'], '1');
    assert.equal(state.requests.filter(request => request.method === 'GET'
      && request.path === `${listPath}/41/pdf?layout=${action.layout}&mode=${action.mode}`).length, 1);
    // Chromium exposes its PDF viewer wrapper as response.body(); verify the
    // actual document separately using the URL reached by the real link click.
    const rawPdf = await fetch(response.url());
    assert.equal(rawPdf.status, 200);
    assert.match(rawPdf.headers.get('content-type'), /^application\/pdf/);
    const bytes = Buffer.from(await rawPdf.arrayBuffer());
    assert.equal(bytes.subarray(0, 8).toString('ascii'), '%PDF-1.4');
    const text = bytes.toString('ascii');
    assert.match(text, /TEST ONLY: no official document, signature, or issuance record is created/);
    assert.match(text, /CS-2026-0041/);
    assert.match(text, action.layout === 'quad' ? /Tear-off copy 4 of 4/ : /Single A4 sample/);
    assert.equal(page.url(), sourceUrl);
    assert.equal(await sourceMain.evaluate(node => node === document.querySelector('#main')), true);
    await popup.close();
    if (action.mode === 'issue') {
      await page.keyboard.press('Escape');
      await row.locator('.dropdown-menu.show').waitFor({ state: 'hidden' });
    }
  }
  assert.equal(state.posts.length, 0, 'Opening sample PDFs never writes or issues an official document');
  assert.equal(state.slips.get(41).attendance_status, 'pending');
  await screenshot(page, 'print-buttons-1440-light');
});

async function noOverflow(page) {
  const metrics = await page.evaluate(rootSelector => {
    const root = document.querySelector(rootSelector), ledger = root.querySelector('[data-filter-region="list"]'), table = ledger.querySelector('table');
    return { viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
      root: root.clientWidth, rootScroll: root.scrollWidth, ledger: ledger.clientWidth, ledgerScroll: ledger.scrollWidth, table: table.clientWidth, tableScroll: table.scrollWidth,
      controlOffenders: [...document.querySelectorAll('.gcs-status-form,.gcs-preview-links,.gcs-official-print,.modal.show .modal-content,.modal.show .modal-body')]
        .filter(element => { const r = element.getBoundingClientRect(); return r.width && (r.left < -1 || r.right > document.documentElement.clientWidth + 1 || element.scrollWidth > element.clientWidth + 1); })
        .map(element => ({ className: element.className, width: element.clientWidth, scroll: element.scrollWidth })),
      textOffenders: [...table.querySelectorAll('td, td > div, td .fw-semibold,.ce-patient-sub')]
        .filter(element => element.clientWidth && element.scrollWidth > element.clientWidth + 1)
        .map(element => ({ className: element.className, label: element.closest('td')?.dataset.label, width: element.clientWidth, scroll: element.scrollWidth,
          wrap: getComputedStyle(element).overflowWrap, whitespace: getComputedStyle(element).whiteSpace, text: element.textContent.slice(0, 100) })).slice(0, 10) };
  }, rootSelector);
  assert.ok(metrics.document <= metrics.viewport + 1 && metrics.body <= metrics.viewport + 1 && metrics.rootScroll <= metrics.root + 1 &&
    metrics.ledgerScroll <= metrics.ledger + 1 && metrics.tableScroll <= metrics.table + 1, `Call Slips overflow: ${JSON.stringify(metrics)}`);
  assert.deepEqual(metrics.controlOffenders, [], 'Actual attendance, print and modal controls must fit the available shell width');
}
async function assertPrint(page, evidenceName = null) {
  const row = rows(page).first(), id = 41;
  const links = row.locator('a[href*="/pdf?"]');
  assert.equal(await links.count(), 4);
  const urls = await links.evaluateAll(links => links.map(link => ({ href: link.href, target: link.target, rel: link.rel, navigation: link.dataset.navigation })));
  assert.deepEqual(urls.map(link => {
    const url = new URL(link.href); assert.equal(url.origin, harness.origin); assert.equal(url.pathname, `${listPath}/${id}/pdf`);
    assert.equal(link.target, '_blank'); assert.match(link.rel, /noopener/); assert.equal(link.navigation, 'false');
    return [url.searchParams.get('layout'), url.searchParams.get('mode')];
  }).sort(), [['quad', 'issue'], ['quad', 'preview'], ['single', 'issue'], ['single', 'preview']]);
  await row.getByRole('link', { name: `Preview Single A4 for ${slipNumber(id)}`, exact: true }).waitFor({ state: 'visible' });
  await row.getByRole('link', { name: `Preview 4-Up Tear-Off for ${slipNumber(id)}`, exact: true }).waitFor({ state: 'visible' });
  const trigger = row.getByRole('button', { name: `Official print options for ${slipNumber(id)}`, exact: true });
  assert.equal(await trigger.getAttribute('data-bs-popper-config'), '{"strategy":"fixed"}');
  await trigger.click();
  const menu = row.locator('.dropdown-menu.show');
  await menu.waitFor({ state: 'visible' });
  assert.equal(await menu.getByRole('link').count(), 2);
  const geometry = await menu.evaluate(menu => {
    const r = menu.getBoundingClientRect();
    const main = document.querySelector('#main').getBoundingClientRect();
    return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, viewportWidth: innerWidth, viewportHeight: innerHeight,
      mainLeft: main.left, mainRight: main.right,
      position: getComputedStyle(menu).position,
      links: [...menu.querySelectorAll('a')].map(link => { const b = link.getBoundingClientRect();
        const samples = [b.left + 4, b.left + b.width / 2, b.right - 4].map(x => {
          const hit = document.elementFromPoint(x, b.top + b.height / 2);
          return hit === link || link.contains(hit);
        });
        return { hit: samples.every(Boolean), width: link.clientWidth, scroll: link.scrollWidth }; }) };
  });
  if (evidenceName) await screenshot(page, evidenceName + '-print');
  assert.ok(geometry.left >= Math.max(0, geometry.mainLeft) - 1 && geometry.right <= Math.min(geometry.viewportWidth, geometry.mainRight) + 1 &&
    geometry.top >= -1 && geometry.bottom <= geometry.viewportHeight + 1,
    `Official print dropdown is clipped/outside shell: ${JSON.stringify(geometry)}`);
  assert.equal(geometry.position, 'fixed');
  assert.ok(geometry.links.every(link => link.hit && link.scroll <= link.width + 1), 'Both issue links remain fully readable and hit-testable at left/center/right without following them');
  await page.keyboard.press('Escape');
  await menu.waitFor({ state: 'hidden' });
}

for (const theme of ['light', 'dark']) {
  for (const width of [375, 390, 768, 1024, 1280, 1440]) {
    test(`Responsive ${width}px ${theme}: actual shell, maroon/neutral surfaces, labeled cards or six-column table, modal and unclipped print menu`, { timeout: 90000 }, async t => {
      const { page, state } = await open(t, { theme, viewport: { width, height: width < 576 ? 844 : 1000 }, long: true });
      const evidence = [375, 1024, 1440].includes(width) ? `${width}-${theme}` : null;
      if (evidence) await screenshot(page, evidence + '-ledger');
      await noOverflow(page);
      const styles = await workspace(page).evaluate(root => {
        const active = root.querySelector('[data-filter-region="tabs"] .active'), idle = root.querySelector('[data-filter-region="tabs"] a:not(.active)'), ledger = root.querySelector('[data-filter-region="list"]');
        return { active: getComputedStyle(active).backgroundColor, activeColor: getComputedStyle(active).color, idle: getComputedStyle(idle).backgroundColor,
          tabsSurface: getComputedStyle(active.closest('nav')).backgroundColor,
          ledger: getComputedStyle(ledger).backgroundColor, head: getComputedStyle(root.querySelector('thead')).display,
          row: getComputedStyle(root.querySelector('tbody > tr')).display, radius: getComputedStyle(active).borderRadius };
      });
      assert.equal(styles.active, 'rgb(128, 0, 0)', 'The selected status uses solid maroon in both themes');
      assert.equal(styles.activeColor, 'rgb(255, 255, 255)');
      const neutral = theme === 'dark' ? 'rgb(22, 22, 24)' : 'rgb(255, 255, 255)';
      assert.equal(styles.ledger, neutral);
      assert.equal(styles.idle, 'rgba(0, 0, 0, 0)', 'Inactive status labels inherit the neutral tab rail without a color wash');
      assert.equal(styles.tabsSurface, theme === 'dark' ? 'rgb(36, 36, 40)' : 'rgb(241, 242, 245)');
      assert.notEqual(styles.radius, '50rem', 'Status tabs are architectural rather than pill-shaped');
      if (width < 1200) {
        assert.equal(styles.head, 'none'); assert.equal(styles.row, 'block');
        assert.deepEqual(await rows(page).first().locator('td').evaluateAll(cells => cells.map(cell => cell.dataset.label)),
          ['Call Slip', 'Student / Client', 'Schedule', 'Purpose', 'Attendance', 'Print Options']);
      } else { assert.equal(styles.head, 'table-header-group'); assert.equal(styles.row, 'table-row'); }
      const main = await page.locator('#main').boundingBox();
      assert.ok(main.y >= 64, 'Use actual header clearance, not a test full-width override');
      if (width >= 992) assert.ok(main.x >= 280, 'Use actual expanded sidebar width on desktop');
      await assertPrint(page, evidence);
      assert.equal(state.posts.length, 0);
      await openIssue(page);
      await noOverflow(page);
      if (evidence) await screenshot(page, evidence + '-modal');
      assert.equal(await issueForm(page).evaluate(form => getComputedStyle(form).backgroundColor), neutral);
      assert.equal(await submitIssue(page).evaluate(button => getComputedStyle(button).backgroundColor), 'rgb(128, 0, 0)');
      await choosePurpose(page, 'others');
      await issueForm(page).getByLabel('Specify Other Purpose', { exact: false }).waitFor({ state: 'visible' });
      await noOverflow(page);
      if (evidence) await screenshot(page, evidence + '-others');
      await modal(page).getByRole('button', { name: 'Close', exact: true }).click();
      assert.equal(state.posts.length, 0);
    });
  }
}
