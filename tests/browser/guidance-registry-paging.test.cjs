'use strict';

// Focused actual-view GUI only: normal links/selects/Back, no application boot,
// state injection, forced actions, database, installed packages or external traffic.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const callSlips = require('../_support/guidance-callslips-browser-harness.cjs');
const practitioners = require('../_support/guidance-practitioners-browser-harness.cjs');
const evidenceDir = path.resolve(__dirname, '../../writable/pagination-preview');
let callServer, practitionerServer, browser;

before(async () => {
  [callServer, practitionerServer] = await Promise.all([callSlips.startServer(), practitioners.startServer()]);
  const { chromium } = callSlips.localPlaywright();
  browser = await chromium.launch({ headless: true, executablePath: callSlips.installedChromium(chromium), timeout: 20000,
    args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run'] });
  fs.mkdirSync(evidenceDir, { recursive: true });
}, { timeout: 30000 });
after(async () => {
  try { await browser?.close(); } finally { await Promise.all([callServer?.close(), practitionerServer?.close()]); }
  for (const [name, server] of [['call-slips', callServer], ['practitioners', practitionerServer]]) {
    console.log(`Registry paging ${name} fixture totals: ${JSON.stringify(server?.counts)}`);
    assert.deepEqual(server?.violations, [], 'Only named loopback fixture routes/assets are permitted');
    assert.equal(server?.counts.rejected, 0);
    assert.equal(server?.counts.posts, 0, 'Paging must never mutate even synthetic records');
  }
});

const footer = (page, parameter) => page.locator(`[data-table-pagination][data-page-param="${parameter}"]`);
const callRows = page => page.locator('table.gcs-callslips-table tbody > tr').filter({ has: page.locator('form.gcs-status-form') });
const profileRows = page => page.locator('table.gpr-profiles-table tbody > tr').filter({ has: page.locator('td[data-label="Practitioner"]') });
const supervisionRows = page => page.locator('table.gpr-supervisions-table tbody > tr').filter({ has: page.locator('td[data-label="Associate"]') });

async function open(t, kind, { pathname, ...options } = {}) {
  const api = kind === 'call-slips' ? callSlips : practitioners;
  const server = kind === 'call-slips' ? callServer : practitionerServer;
  const state = server.createScenario(options);
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
  // Preparation selects invented RAM data, never pre-triggers paging or editing.
  await context.addCookies([{ name: kind === 'call-slips' ? 'synthetic_callslips' : practitioners.cookieName, value: state.id, url: server.origin }]);
  const diagnostics = { denied: [], pageErrors: [], consoleErrors: [], failedResponses: [] };
  const record = (name, value) => { if (diagnostics[name].length < 24) diagnostics[name].push(value); };
  await context.route('**/*', route => {
    const request = route.request();
    if (!api.allowedRequest(server.origin, state, request.method(), request.url())) {
      record('denied', `${request.method()} ${request.url()}`);
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  await context.routeWebSocket('**/*', socket => { record('denied', `WebSocket ${socket.url()}`); socket.close(); });
  const page = await context.newPage();
  page.setDefaultTimeout(12000);
  page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', error => record('pageErrors', error.message));
  page.on('console', message => { if (message.type() === 'error') record('consoleErrors', message.text()); });
  page.on('response', response => { if (response.status() >= 400) record('failedResponses', `${response.status()} ${response.url()}`); });
  page.on('download', download => { record('denied', 'Unexpected download'); void download.cancel(); });
  page.on('popup', popup => { record('denied', 'Unexpected popup'); void popup.close(); });
  page.on('dialog', dialog => { record('denied', `Unexpected ${dialog.type()}`); void dialog.dismiss(); });
  t.after(async () => {
    await context.close();
    console.log(`Registry paging ${kind} browser diagnostics: ${JSON.stringify(diagnostics)}`);
    for (const values of Object.values(diagnostics)) assert.deepEqual(values, []);
    assert.equal(state.posts.length, 0);
  });
  await page.goto(server.origin + (pathname || api.listPath), { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.readyState === 'complete' && window.SynapseLiveFilters && window.SynapseNavigation
    && !document.querySelector('#main')?.inert);
  await footer(page, kind === 'call-slips' ? 'page' : 'profiles_page').waitFor({ state: 'visible' });
  return { page, state };
}

async function observe(page, parameter, name) {
  const region = footer(page, parameter);
  await region.scrollIntoViewIfNeeded();
  assert.equal(await region.isVisible(), true);
  const file = path.join(evidenceDir, `registry-${name}.png`);
  await page.screenshot({ path: file, animations: 'disabled', timeout: 12000 });
  console.log(`Registry paging screenshot: ${file}`);
}
async function waitPage(page, parameter, expected) {
  try {
    await page.waitForFunction(({ parameter, expected }) => {
      const root = document.querySelector('[data-live-filters]');
      const result = root?.querySelector(`[data-table-pagination][data-page-param="${parameter}"]`);
      return result?.dataset.page === String(expected) && !root.hasAttribute('aria-busy') && !document.querySelector('#main')?.inert;
    }, { parameter, expected });
  } catch (error) {
    await page.screenshot({ path: path.join(evidenceDir, `registry-failed-${parameter}-${expected}.png`) });
    console.log('Registry paging retained state: ' + JSON.stringify(await page.locator('[data-live-filters]').evaluate(root => ({
      status: root.querySelector('[data-filter-status]')?.textContent,
      error: root.querySelector('[data-filter-error-text]')?.textContent,
      fields: Array.from(new FormData(root.querySelector('[data-filter-form]'))),
      active: document.activeElement?.outerHTML, busy: root.getAttribute('aria-busy'),
      events: window.fixtureFilterEvents, refresh: window.fixtureRefreshEvents,
    }))));
    throw error;
  }
}
async function clickPage(page, parameter, label, expected) {
  const link = footer(page, parameter).getByRole('link', { name: label, exact: true });
  assert.equal(await link.getAttribute('data-filter-link'), '');
  await link.click();
  await waitPage(page, parameter, expected);
}
async function assertFooter(page, parameter, { page: current, total, filtered = total, size = 25, from, to }) {
  const result = footer(page, parameter);
  assert.equal(await result.getAttribute('data-page'), String(current));
  assert.equal(await result.getAttribute('data-total'), String(total));
  assert.equal(await result.getAttribute('data-filtered'), String(filtered));
  assert.equal(await result.getAttribute('data-per-page'), String(size));
  assert.match((await result.locator('p').textContent()).replace(/\s+/g, ' ').trim(),
    new RegExp(`^Showing ${from}–${to} of ${filtered}(?: |$)`));
}

// Tied synthetic dates/times make the controller's ID tie-break observable.
test('Call slips: full totals beyond 100, stable page rows, Next/Last and browser Back', { timeout: 45000 }, async t => {
  const { page } = await open(t, 'call-slips', { paging: true });
  await assertFooter(page, 'page', { page: 1, total: 113, from: 1, to: 25 });
  assert.equal(await callRows(page).count(), 25);
  assert.equal(await callRows(page).first().locator('.gcs-slip-number').textContent(), 'CS-2026-93200');
  assert.equal(await callRows(page).last().locator('.gcs-slip-number').textContent(), 'CS-2026-93176');
  assert.equal(await page.locator('table.gcs-callslips-table thead th').count(), 6);
  assert.equal(await page.locator('table.gcs-callslips-table').getAttribute('data-mobile-cards'), 'false');
  assert.match(await page.locator('[data-filter-status]').textContent(), /113 matching call slips/);
  await observe(page, 'page', 'callslips-first');
  await clickPage(page, 'page', 'Next', 2);
  await assertFooter(page, 'page', { page: 2, total: 113, from: 26, to: 50 });
  assert.equal(await callRows(page).first().locator('form.gcs-status-form').getAttribute('action').then(value => new URL(value).pathname), '/guidance/call-slips/93175/status');
  await observe(page, 'page', 'callslips-next');
  await clickPage(page, 'page', 'Last', 5);
  await assertFooter(page, 'page', { page: 5, total: 113, from: 101, to: 113 });
  assert.equal(await callRows(page).count(), 13);
  assert.equal(await callRows(page).last().locator('.gcs-slip-number').textContent(), 'CS-2026-93088');
  assert.equal(await footer(page, 'page').getByRole('link', { name: 'Next', exact: true }).count(), 0);
  await observe(page, 'page', 'callslips-last');
  // Back is the behavior under test, not a workaround for a failed operation.
  await page.goBack();
  await waitPage(page, 'page', 2);
  assert.equal(new URL(page.url()).searchParams.get('page'), '2');
  assert.equal(await callRows(page).count(), 25);
  await observe(page, 'page', 'callslips-back');
});

test('Call slips: page-size and attendance changes reset paging, true filtered totals and zero matches', { timeout: 45000 }, async t => {
  const records = callSlips.fixtureData({ paging: true }).map((row, index) => ({ ...row,
    attendance_status: index < 45 ? 'pending' : 'missed', acknowledged_at: null }));
  const { page } = await open(t, 'call-slips', { callSlips: records, pathname: callSlips.listPath + '?page=2&per_page=25' });
  await assertFooter(page, 'page', { page: 2, total: 113, from: 26, to: 50 });
  await observe(page, 'page', 'callslips-filter-before');
  const sizeControl = page.locator('#callSlipsPerPage').locator('..').locator('.bd-select-toggle');
  await sizeControl.scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(evidenceDir, 'registry-callslips-page-size-anchor.png'), animations: 'disabled' });
  await sizeControl.click();
  const sizeMenu = page.locator('#' + await sizeControl.getAttribute('aria-controls'));
  await sizeMenu.waitFor({ state: 'visible' });
  await sizeMenu.getByRole('option', { name: '20', exact: true }).click();
  await waitPage(page, 'page', 1);
  await assertFooter(page, 'page', { page: 1, total: 113, size: 20, from: 1, to: 20 });
  assert.equal(await callRows(page).count(), 20);
  await observe(page, 'page', 'callslips-size-reset');
  await clickPage(page, 'page', 'Last', 6);
  await assertFooter(page, 'page', { page: 6, total: 113, size: 20, from: 101, to: 113 });
  await observe(page, 'page', 'callslips-size-last');
  await page.getByRole('navigation', { name: 'Filter call slips by attendance status', exact: true }).getByRole('link', { name: 'Pending', exact: true }).click();
  await waitPage(page, 'page', 1);
  await assertFooter(page, 'page', { page: 1, total: 113, filtered: 45, size: 20, from: 1, to: 20 });
  assert.equal(await callRows(page).count(), 20);
  assert.match(await page.locator('[data-filter-status]').textContent(), /45 matching call slips/);
  await observe(page, 'page', 'callslips-filter-matching');
  await clickPage(page, 'page', 'Last', 3);
  await assertFooter(page, 'page', { page: 3, total: 113, filtered: 45, size: 20, from: 41, to: 45 });
  assert.equal(await callRows(page).count(), 5);
  await observe(page, 'page', 'callslips-filter-last');
  await page.getByRole('navigation', { name: 'Filter call slips by attendance status', exact: true }).getByRole('link', { name: 'Attended', exact: true }).click();
  await waitPage(page, 'page', 1);
  await assertFooter(page, 'page', { page: 1, total: 113, filtered: 0, size: 20, from: 0, to: 0 });
  assert.equal(new URL(page.url()).searchParams.get('status'), 'attended');
  assert.equal(await callRows(page).count(), 0);
  assert.match(await footer(page, 'page').textContent(), /113 total records/);
  await page.getByText('No call slips found with attended attendance.', { exact: true }).waitFor({ state: 'visible' });
  await observe(page, 'page', 'callslips-filter-empty');
  await page.getByRole('navigation', { name: 'Filter call slips by attendance status', exact: true }).getByRole('link', { name: 'All', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('[data-live-filters]')?.hasAttribute('aria-busy')
    && document.querySelector('[data-filter-form] input[name="status"]')?.value === '');
  await assertFooter(page, 'page', { page: 1, total: 113, size: 20, from: 1, to: 20 });
  assert.equal(await callRows(page).count(), 20);
  await observe(page, 'page', 'callslips-filter-reset');
});

test('Empty registries: real table empty rows, zero counts and inaccessible Next/Last links', { timeout: 45000 }, async t => {
  const call = await open(t, 'call-slips', { empty: true });
  await assertFooter(call.page, 'page', { page: 1, total: 0, from: 0, to: 0 });
  assert.equal(await callRows(call.page).count(), 0);
  assert.equal(await call.page.locator('table.gcs-callslips-table td[colspan="6"]').count(), 1);
  assert.equal(await footer(call.page, 'page').locator('a').count(), 0);
  assert.equal(await footer(call.page, 'page').locator('[aria-disabled="true"]').count(), 4);
  await observe(call.page, 'page', 'callslips-empty');
  const registry = await open(t, 'practitioners', { empty: true, permission: 'read-only' });
  for (const parameter of ['profiles_page', 'supervision_page']) {
    await assertFooter(registry.page, parameter, { page: 1, total: 0, from: 0, to: 0 });
    assert.equal(await footer(registry.page, parameter).locator('a').count(), 0);
    assert.equal(await footer(registry.page, parameter).locator('[aria-disabled="true"]').count(), 4);
  }
  assert.equal(await registry.page.locator('table.gpr-profiles-table td[colspan="5"]').count(), 1);
  assert.equal(await registry.page.locator('table.gpr-supervisions-table td[colspan="4"]').count(), 1);
  await observe(registry.page, 'supervision_page', 'practitioners-empty');
});

test('Practitioners: independent profile/supervision cursors, full counts, stable rows and browser Back', { timeout: 45000 }, async t => {
  const { page, state } = await open(t, 'practitioners', { paging: true, permission: 'read-only' });
  const expectedProfiles = practitioners.fixtureData({ paging: true, permission: 'read-only' }).profiles.map(row => '@' + row.username);
  await assertFooter(page, 'profiles_page', { page: 1, total: 32, from: 1, to: 25 });
  await assertFooter(page, 'supervision_page', { page: 1, total: 32, from: 1, to: 25 });
  assert.equal(await profileRows(page).count(), 25);
  assert.equal(await supervisionRows(page).count(), 25);
  assert.equal(state.guidanceUsers.length, 34, 'Picker authority input is not truncated to either visible page');
  assert.deepEqual(await profileRows(page).locator('.gpr-person-meta .ce-mono').allTextContents(), expectedProfiles.slice(0, 25));
  await observe(page, 'profiles_page', 'practitioners-first');
  await clickPage(page, 'profiles_page', 'Next', 2);
  await assertFooter(page, 'profiles_page', { page: 2, total: 32, from: 26, to: 32 });
  await assertFooter(page, 'supervision_page', { page: 1, total: 32, from: 1, to: 25 });
  assert.deepEqual(await profileRows(page).locator('.gpr-person-meta .ce-mono').allTextContents(), expectedProfiles.slice(25));
  const next = new URL(await footer(page, 'supervision_page').getByRole('link', { name: 'Next', exact: true }).getAttribute('href'));
  assert.equal(next.searchParams.get('profiles_page'), '2');
  await observe(page, 'profiles_page', 'practitioners-profile-next');
  await clickPage(page, 'supervision_page', 'Next', 2);
  await assertFooter(page, 'profiles_page', { page: 2, total: 32, from: 26, to: 32 });
  await assertFooter(page, 'supervision_page', { page: 2, total: 32, from: 26, to: 32 });
  assert.equal(await profileRows(page).count(), 7);
  assert.equal(await supervisionRows(page).count(), 7);
  await observe(page, 'supervision_page', 'practitioners-supervision-next');
  await page.goBack();
  await waitPage(page, 'supervision_page', 1);
  assert.equal(new URL(page.url()).searchParams.get('profiles_page'), '2');
  assert.equal(new URL(page.url()).searchParams.get('supervision_page'), '1');
  assert.equal(await profileRows(page).count(), 7);
  assert.equal(await supervisionRows(page).count(), 25);
  await observe(page, 'profiles_page', 'practitioners-back');
});

test('Practitioners: deep-linked cursors clamp, row-size resets both and supervision Last preserves profiles', { timeout: 45000 }, async t => {
  const { page } = await open(t, 'practitioners', { paging: true, permission: 'read-only',
    pathname: practitioners.listPath + '?profiles_page=1000000&supervision_page=1000000&per_page=25' });
  for (const parameter of ['profiles_page', 'supervision_page']) await assertFooter(page, parameter, { page: 2, total: 32, from: 26, to: 32 });
  assert.equal(await profileRows(page).count(), 7);
  assert.equal(await supervisionRows(page).count(), 7);
  await observe(page, 'supervision_page', 'practitioners-clamped');
  const sizeControl = page.locator('#practitionersPerPage').locator('..').locator('.bd-select-toggle');
  await sizeControl.scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(evidenceDir, 'registry-practitioners-page-size-anchor.png'), animations: 'disabled' });
  await sizeControl.click();
  const sizeMenu = page.locator('#' + await sizeControl.getAttribute('aria-controls'));
  await sizeMenu.waitFor({ state: 'visible' });
  await sizeMenu.getByRole('option', { name: '20', exact: true }).click();
  await waitPage(page, 'profiles_page', 1);
  for (const parameter of ['profiles_page', 'supervision_page']) await assertFooter(page, parameter, { page: 1, total: 32, size: 20, from: 1, to: 20 });
  assert.equal(await profileRows(page).count(), 20);
  assert.equal(await supervisionRows(page).count(), 20);
  await observe(page, 'profiles_page', 'practitioners-size-reset');
  await clickPage(page, 'supervision_page', 'Last', 2);
  await assertFooter(page, 'profiles_page', { page: 1, total: 32, size: 20, from: 1, to: 20 });
  await assertFooter(page, 'supervision_page', { page: 2, total: 32, size: 20, from: 21, to: 32 });
  assert.equal(await supervisionRows(page).count(), 12);
  assert.equal(new URL(page.url()).searchParams.get('profiles_page'), '1');
  await observe(page, 'supervision_page', 'practitioners-size-last');
});
