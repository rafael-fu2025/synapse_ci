'use strict';

// Focused only: node --test --test-concurrency=1 tests/browser/guidance-practitioners.test.cjs
// Bounded batches: --test-name-pattern='^(Contract|Workflow):' or '^Responsive:'
// Actual PHP view/shared widgets; synthetic staff and RAM-only writes. No app,
// Composer/dotenv/session/DB, package installation or external requests.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
  startServer, renderFixture, localPlaywright, installedChromium, allowedRequest, fixtureData,
  validPayload, applyMutation, responsePayload, listPath, registerPath, supervisionPath, owner, cookieName,
  tiers, scopes, staff, permissions, limits, hostileText,
} = require('../_support/guidance-practitioners-browser-harness.cjs');
const evidenceDir = path.resolve(__dirname, '../../writable/guidance-practitioners-preview');
const rootSelector = 'section.guidance-ui.guidance-practitioners-workspace.ce-workspace.gpr-workspace';
const workspace = page => page.locator(rootSelector);
const region = page => workspace(page).locator('[data-refresh-region="workspace"]');
const profilesTable = page => workspace(page).locator('table.gpr-profiles-table');
const supervisionsTable = page => workspace(page).locator('table.gpr-supervisions-table');
const profileRows = page => profilesTable(page).locator('tbody > tr').filter({ has: page.locator('td[data-label="Practitioner"]') });
const supervisionRows = page => supervisionsTable(page).locator('tbody > tr').filter({ has: page.locator('td[data-label="Associate"]') });
const profileForm = page => workspace(page).locator(`form[action$="${registerPath}"]`);
const supervisionForm = page => workspace(page).locator(`form[action$="${supervisionPath}"]`);
const saveProfile = page => profileForm(page).getByRole('button', { name: 'Save Practitioner Profile', exact: true });
const saveSupervision = page => supervisionForm(page).getByRole('button', { name: 'Assign Supervisor', exact: true });
const confirmationText = 'Assign this supervisor to the selected associate? Any previous active supervision for the associate will be closed.';
const sorted = fields => [...fields].sort((a, b) => a[0].localeCompare(b[0]));
const tierLabel = value => ({ counselor_associate: 'Counselor Associate', licensed_counselor: 'Licensed Counselor', guidance_supervisor: 'Guidance Supervisor', qualified_assessor: 'Qualified Assessor' }[value] || value.replaceAll('_', ' ').replace(/\b\w/g, value => value.toUpperCase()));
let harness, browser;

before(async () => { harness = await startServer(); }, { timeout: 10000 });
after(async () => {
  try { await browser?.close(); } finally { await harness?.close(); }
  console.log('Practitioner fixture totals: ' + JSON.stringify(harness?.counts));
  assert.deepEqual(harness?.violations, [], 'Unexpected server routes/assets must fail this focused run');
  assert.equal(harness?.counts.rejected, 0);
});
async function screenshot(page, name) {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const file = path.join(evidenceDir, name + '.png');
  await page.screenshot({ path: file, animations: 'disabled', timeout: 15000 });
  console.log('Practitioners screenshot: ' + file);
  return file;
}
async function open(t, { theme = 'light', viewport = { width: 1440, height: 1000 }, expectedStatuses = [], ...options } = {}) {
  if (!browser) {
    const { chromium } = localPlaywright();
    browser = await chromium.launch({ headless: true, executablePath: installedChromium(chromium), timeout: 20000,
      args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run'] });
  }
  const state = harness.createScenario({ theme, ...options });
  const context = await browser.newContext({ viewport, colorScheme: theme, reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
  await context.addCookies([{ name: cookieName, value: state.id, url: harness.origin }]); // Scenario setup, never feature state.
  const denied = [], errors = [], consoles = [], failures = [];
  const record = (items, value) => { if (items.length < limits.diagnostics) items.push(value); else if (items.length === limits.diagnostics) items.push('Diagnostic bound exceeded'); };
  const expected = new Set([...expectedStatuses, ...(options.postStatuses || []), ...(options.getStatuses || [])].filter(status => status >= 400));
  await context.route('**/*', route => {
    const request = route.request();
    if (!allowedRequest(harness.origin, state, request.method(), request.url())) {
      record(denied, `${request.method()} ${request.url()}`);
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  await context.routeWebSocket('**/*', socket => { record(denied, 'Unexpected WebSocket ' + socket.url()); socket.close(); });
  const page = await context.newPage();
  page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(45000);
  page.on('pageerror', error => record(errors, error.message));
  page.on('console', message => {
    if (message.type() !== 'error') return;
    const status = Number(/server responded with a status of (\d+)/.exec(message.text())?.[1]);
    if (!expected.has(status)) record(consoles, message.text());
  });
  page.on('response', response => {
    if (response.status() < 400) return;
    const url = new URL(response.url());
    if (!(url.origin === harness.origin && expected.has(response.status()) && [listPath, registerPath, supervisionPath].includes(url.pathname))) {
      record(failures, `${response.status()} ${response.request().method()} ${response.url()}`);
    }
  });
  page.on('download', download => { record(denied, 'Unexpected download'); void download.cancel(); });
  page.on('popup', popup => { record(denied, 'Unexpected popup'); void popup.close(); });
  page.on('dialog', dialog => { record(denied, `Unexpected native ${dialog.type()}: ${dialog.message()}`); void dialog.dismiss(); });
  let closed = false;
  async function close() {
    if (closed) return;
    closed = true;
    try { if (!page.isClosed()) assert.equal(await page.evaluate(() => window.fixtureEventOverflow || 0), 0); }
    finally { await context.close(); }
    assert.deepEqual(denied, [], 'No external/unrelated traffic or native confirmation is permitted');
    assert.deepEqual(errors, [], 'Actual shared widgets must not throw');
    assert.deepEqual(consoles, [], 'No unexpected browser console errors');
    assert.deepEqual(failures, [], 'All named local assets must load successfully');
  }
  t.after(close);
  const started = Date.now();
  await page.goto(harness.origin + listPath, { waitUntil: 'domcontentloaded' });
  await ready(page);
  console.log(`Practitioners scenario ${state.id} ready in ${Date.now() - started}ms`);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  await page.locator('#synthetic-practitioners-notice').getByText('SYNTHETIC TEST ONLY.', { exact: true }).waitFor({ state: 'visible' });
  return { page, state, close };
}
async function ready(page) {
  await page.waitForFunction(() => document.readyState === 'complete' && window.BalaySelect && window.BalayDatepicker &&
    window.SynapseBackgroundActions && window.SynapseMutations && window.SynapseNavigation && window.SynapseRefreshRegions && window.SynapseConfirm &&
    [...document.querySelectorAll('#prac-user-id,#prac-tier,#sup-associate,#sup-supervisor')].every(source => source.closest('.bd-select')));
}
async function openSelect(page, id) {
  const source = page.locator(id), trigger = source.locator('..').getByRole('combobox');
  await trigger.scrollIntoViewIfNeeded();
  await trigger.click();
  const menuId = await trigger.getAttribute('aria-controls');
  assert.ok(menuId, 'Actual BalaySelect must own its rendered listbox');
  const menu = page.locator(`[id="${menuId}"]`);
  await menu.waitFor({ state: 'visible' });
  return { source, trigger, menu };
}
async function choose(page, id, value, search = null) {
  const { source, trigger, menu } = await openSelect(page, id);
  const label = await source.locator('option').evaluateAll((options, value) => options.find(option => option.value === value)?.label.replace(/\s+/g, ' ').trim(), String(value));
  assert.ok(label, 'Option must come from the actual supplied staff/tier list');
  if (search) await menu.getByRole('searchbox', { name: 'Filter options', exact: true }).fill(search);
  await menu.getByRole('option', { name: label, exact: true }).click();
  assert.equal(await source.inputValue(), String(value));
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
}
async function csrfAll(page, expected) {
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), expected);
  const values = await workspace(page).locator('input[name="fixture_csrf"]').evaluateAll(inputs => inputs.map(input => input.value));
  assert.ok(values.length && values.every(value => value === expected), 'Both forms receive authoritative rotating synthetic CSRF');
}
async function fillExpiry(page) {
  const input = page.locator('#prac-expiry-display');
  await input.fill('31/01/2031');
  await page.locator('#prac-license').click();
  assert.equal(await page.locator('#prac-expiry').inputValue(), '2031-01-31');
  await input.click();
  const calendar = page.getByRole('dialog', { name: 'Choose date', exact: true });
  await calendar.waitFor({ state: 'visible' });
  await calendar.getByRole('button', { name: 'Next month', exact: true }).click();
  await calendar.locator('[data-date="2031-02-01"]').click();
  await calendar.waitFor({ state: 'hidden' });
  assert.equal(await input.inputValue(), '01/02/2031');
  assert.equal(await page.locator('#prac-expiry').inputValue(), '2031-02-01');
}
async function assertTables(page, state) {
  assert.equal(await profileRows(page).count(), state.profiles.size);
  for (const profile of state.profiles.values()) {
    const row = profileRows(page).filter({ has: page.locator('.gpr-person-meta .ce-mono', { hasText: '@' + profile.username }) });
    assert.equal(await row.count(), 1);
    assert.equal((await row.locator('.gpr-person-name').textContent()).trim(), `${profile.first_name} ${profile.last_name}`.trim() || profile.username);
    assert.equal((await row.locator('.gpr-tier').textContent()).trim(), tierLabel(profile.credential_tier));
    assert.equal((await row.locator('.gpr-standing .gpr-meta').textContent()).trim(), profile.is_active === 1 ? 'Profile active' : 'Profile inactive');
    assert.deepEqual(await row.locator('.gpr-scope-tags .ce-tag').evaluateAll(tags => tags.map(tag => tag.title)), profile.service_scope);
  }
  const active = [...state.assignments.values()].filter(row => row.status === 'active');
  assert.equal(await supervisionRows(page).count(), active.length);
  for (const assignment of active) {
    const row = supervisionRows(page).filter({ has: page.locator('td[data-label="Associate"] .gpr-meta', { hasText: '@' + assignment.associate_username }) });
    assert.equal(await row.count(), 1);
    assert.equal((await row.locator('td[data-label="Assigned Supervisor"] .gpr-meta').textContent()).trim(), '@' + assignment.supervisor_username);
    assert.equal((await row.locator('td[data-label="Co-Sign Policy"]').textContent()).trim(), assignment.co_sign_required === 1 ? 'Co-Sign Required' : 'Consultative Only');
  }
}
async function captureIdentity(page) {
  return { shell: await Promise.all(['#main', '#header', '#sidebar', rootSelector].map(async selector => [selector, await page.locator(selector).elementHandle()])),
    region: await region(page).elementHandle(), content: await region(page).locator('.gpr-grid').elementHandle() };
}
async function refreshed(page, state, identity) {
  // Named-region refresh retains the region element and replaces its children.
  try { await page.waitForFunction(node => !node.isConnected, identity.content); }
  catch (error) {
    await screenshot(page, 'profile-refresh-diagnostic');
    console.log('Practitioner refresh diagnostic: ' + JSON.stringify(await page.locator(rootSelector).evaluate(root => ({
      active: document.activeElement?.outerHTML, status: root.querySelector('[data-filter-status]')?.textContent,
      error: root.querySelector('[data-filter-error-text]')?.textContent,
      forms: [...root.querySelectorAll('form')].map(form => ({ action: form.getAttribute('action'), busy: form.getAttribute('aria-busy') })),
      refreshEvents: window.fixturePractitionerEvents,
    }))));
    throw error;
  }
  await ready(page);
  await page.waitForFunction(() => !document.getElementById('main').inert && !document.body.classList.contains('is-navigating'));
  await assertTables(page, state);
  await csrfAll(page, state.csrf);
  if (state.regionalEnabled) {
    for (const [selector, handle] of identity.shell) assert.equal(await handle.evaluate((node, selector) => node === document.querySelector(selector), selector), true, 'Named-region saves preserve ' + selector);
    const events = await page.evaluate(() => window.fixturePractitionerEvents.filter(event => event.name === 'refresh_committed'));
    assert.ok(events.length && events.every(event => event.owner === owner && event.status === 'refreshed' && JSON.stringify(event.regions) === '["workspace"]'));
  } else {
    assert.equal(await identity.shell.at(-1)[1].evaluate(node => node.isConnected), false, 'Rollout-off uses the actual shared navigation fallback');
  }
}
function assertPost(state, index, pathname, fields, status = 200) {
  const post = state.posts[index];
  assert.equal(post.path, pathname); assert.equal(post.status, status);
  assert.deepEqual(sorted(post.fields), sorted([['fixture_csrf', `practitioners-token-${index}`], ...fields]));
  assert.equal(post.headers['x-fixture-csrf'], `practitioners-token-${index}`);
  assert.equal(post.headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(post.headers['x-synapse-feedback'], 'toast');
  assert.equal(post.payload.csrf.hash, `practitioners-token-${index + 1}`);
  if (status === 200 && state.regionalEnabled) assert.deepEqual(post.payload.refresh, { mode: 'regions', owner, regions: ['workspace'], url: harness.origin + listPath });
  else assert.equal(Object.hasOwn(post.payload, 'refresh'), false);
}
async function confirmAssignment(page, accept, name) {
  await saveSupervision(page).click();
  const dialog = page.getByRole('dialog', { name: 'Assign Supervision', exact: true });
  await dialog.waitFor({ state: 'visible' });
  assert.equal((await dialog.locator('[data-confirm-message]').textContent()).trim(), confirmationText);
  assert.equal(await dialog.locator('[data-confirm-accept]').textContent(), 'Save Assignment');
  if (name) await screenshot(page, name);
  await dialog.getByRole('button', { name: accept ? 'Save Assignment' : 'Cancel', exact: true }).click();
  await dialog.waitFor({ state: 'hidden' });
}

// Contract checks use only the isolated renderer and the bounded RAM model.
test('Contract: actual renderer retains owners, field IDs/actions, four tiers, standings/scopes, empty and permission fixtures', () => {
  for (const permission of Object.keys(permissions)) {
    const data = fixtureData({ permission });
    const html = renderFixture({ ...data, origin: 'http://127.0.0.1:12345' });
    assert.match(html, /^<!doctype html>/);
    assert.match(html, /section class="guidance-ui guidance-practitioners-workspace ce-workspace gpr-workspace"/);
    assert.match(html, /data-refresh-owner="guidance\.governance\.practitioners"/);
    assert.match(html, /data-refresh-region="workspace" data-refresh-heading tabindex="-1"/);
    assert.match(html, /SYNTHETIC TEST ONLY/);
    assert.match(html, /assets\/css\/pages\/guidance-practitioners\.css/);
    assert.match(html, /assets\/js\/form-modal\.js/);
    assert.doesNotMatch(html, /assets\/js\/pages\/|assets\/js\/core\/main\.js|<script[^>]*src="https?:\/\/(?!127\.0\.0\.1)/);
    for (const tier of tiers) assert.ok(html.includes(tierLabel(tier)));
    for (const label of ['Active standing', 'Suspended', 'Lapsed', 'Profile inactive', 'Expired', 'Inactive', 'Synthetic Unknown Standing', 'Expiry unavailable', 'None assigned']) assert.ok(html.includes(label), label);
    for (const id of ['prac-user-id', 'prac-tier', 'prac-license', 'prac-expiry-display', 'prac-expiry', ...Object.keys(scopes).map(key => 'scope-' + key)]) {
      assert.equal(html.includes('id="' + id + '"'), data.canManagePractitioners, id + ' follows its permission');
    }
    for (const id of ['sup-associate', 'sup-supervisor', 'sup-cosign']) assert.equal(html.includes('id="' + id + '"'), data.canReassign, id + ' follows its permission');
    if (data.canReassign) { assert.ok(html.includes('data-confirm="' + confirmationText + '"')); assert.match(html, /data-confirm-title="Assign Supervision"/); }
    assert.equal(html.includes('gpr-readonly-note'), permission === 'read-only');
  }
  const html = renderFixture({ ...fixtureData({ long: true }), origin: 'http://127.0.0.1:12345' });
  assert.ok(html.includes('&lt;script&gt;alert(')); assert.equal(html.includes(hostileText), false);
  const empty = renderFixture({ ...fixtureData({ empty: true, noStaff: true }) });
  assert.match(empty, /colspan="5"/); assert.match(empty, /colspan="4"/);
  assert.match(empty, /No practitioner profiles registered/); assert.match(empty, /No active supervision assignments/);
  for (const invalid of [{ origin: 'https://example.com' }, { origin: 'http://127.0.0.1:65536' }, { path: '/portal' }, { csrf: 'real-token' }, { appBootstrap: true }]) assert.throws(() => renderFixture(invalid));
});

test('Contract: allowlist, RAM replacement/closure semantics and rollout response shape fail closed', async () => {
  const state = harness.createScenario(), origin = harness.origin;
  for (const [method, url] of [['GET', 'https://example.com/'], ['GET', origin + '/.env'], ['GET', origin + '/assets/js/core/main.js'],
    ['GET', origin + '/assets/css/pages/not-allowlisted.css'], ['GET', origin + '/assets/%2e%2e/.env'], ['GET', origin + listPath + '?unexpected=1'], ['DELETE', origin + registerPath]]) {
    assert.equal(allowedRequest(origin, state, method, url), false);
  }
  const fields = [['fixture_csrf', state.csrf], ['user_id', '93006'], ['credential_tier', tiers[1]], ['license_number', 'SYN-MODEL'], ['credential_expiry', '2099-12-31'], ['service_scope[]', 'intake']];
  assert.equal(validPayload(state, registerPath, fields), true);
  assert.equal(validPayload(state, registerPath, [...fields, ['professional_standing', 'suspended']]), false);
  applyMutation(state, registerPath, fields);
  assert.equal(state.profiles.get(93006).professional_standing, 'active'); assert.equal(state.profiles.get(93006).is_active, 1);
  assert.deepEqual(state.profiles.get(93006).service_scope, ['intake']);
  applyMutation(state, supervisionPath, [['associate_user_id', '93001'], ['supervisor_user_id', '93008'], ['co_sign_required', '0'], ['co_sign_required', '1']]);
  assert.equal(state.assignments.get(95001).status, 'completed'); assert.ok(state.assignments.get(95001).ends_at);
  assert.equal([...state.assignments.values()].filter(row => row.associate_user_id === 93001 && row.status === 'active').length, 1);
  assert.deepEqual(responsePayload(state, 200, registerPath, origin).refresh, { mode: 'regions', owner, regions: ['workspace'], url: origin + listPath });
  assert.equal(Object.hasOwn(responsePayload({ ...state, regionalEnabled: false }, 200, registerPath, origin), 'refresh'), false);
  // A separate loopback server makes intentional rejected payloads observable,
  // without disguising unexpected requests in the GUI server's clean ledger.
  const server = await startServer();
  try {
    const readonly = server.createScenario({ permission: 'read-only' }), protectedState = server.createScenario();
    for (const scenario of [readonly, protectedState]) {
      const response = await fetch(server.origin + registerPath, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded',
        Cookie: `${cookieName}=${scenario.id}`, Origin: server.origin, 'X-Fixture-CSRF': 'practitioners-token-9' },
      body: new URLSearchParams(fields).toString() });
      assert.equal(response.status, 403);
      const payload = await response.json(); assert.equal(payload.success, false); assert.equal(payload.csrf.hash, 'practitioners-token-1');
      assert.equal(scenario.revision, 0); assert.equal(scenario.profiles.get(93006).is_active, 0);
    }
    assert.deepEqual(server.violations, []);
  } finally { await server.close(); }
});

test('Workflow: permission fixtures and empty data keep authoritative ledgers and only authorized real forms', { timeout: 90000 }, async t => {
  for (const permission of Object.keys(permissions)) {
    const { page, state, close } = await open(t, { permission, empty: true, viewport: { width: 390, height: 844 } });
    assert.equal(await profileForm(page).count(), permissions[permission][0] ? 1 : 0);
    assert.equal(await supervisionForm(page).count(), permissions[permission][1] ? 1 : 0);
    assert.equal(await workspace(page).locator('.gpr-readonly-note').count(), permission === 'read-only' ? 1 : 0);
    await profilesTable(page).getByText('No practitioner profiles registered', { exact: true }).waitFor({ state: 'visible' });
    await supervisionsTable(page).getByText('No active supervision assignments', { exact: true }).waitFor({ state: 'visible' });
    assert.equal(await profilesTable(page).locator('td[colspan="5"]').count(), 1);
    assert.equal(await supervisionsTable(page).locator('td[colspan="4"]').count(), 1);
    for (const id of ['#prac-user-id', '#sup-associate', '#sup-supervisor']) if (await page.locator(id).count()) {
      assert.deepEqual(await page.locator(id).locator('option').evaluateAll(options => options.map(option => option.value)), ['', ...staff.map(user => String(user.id))], 'All supplied staff remain options, without tier filtering');
    }
    await screenshot(page, 'permissions-' + permission + '-390-light');
    assert.equal(state.posts.length, 0); await close();
  }
});

test('Workflow: profile required/date widgets, 422 retry, CSRF rotation, duplicate prevention and authoritative replacement', { timeout: 90000 }, async t => {
  const { page, state } = await open(t, { postStatuses: [422, 200], postDelayMs: 800 });
  await saveProfile(page).click();
  assert.equal(state.posts.length, 0);
  assert.equal(await page.locator('#prac-user-id').evaluate(source => source.required && source.validity.valueMissing), true);
  await choose(page, '#prac-user-id', 93006, 'synthetic_staff_6');
  assert.equal(await page.locator('#prac-tier').inputValue(), tiers[0], 'Selecting a staff account does not autofill a profile');
  assert.equal(await page.locator('#prac-license').inputValue(), '');
  assert.equal(await page.locator('#prac-expiry').inputValue(), '');
  assert.equal(await profileForm(page).locator('input[name="service_scope[]"]:checked').count(), 0);
  await choose(page, '#prac-tier', tiers[1]);
  await page.locator('#prac-license').fill('SYN-GUI-UPDATE');
  await page.locator('#prac-expiry-display').fill('31/02/2031');
  await page.locator('#prac-license').click();
  await saveProfile(page).click();
  assert.equal(state.posts.length, 0, 'Invalid typed dates do not send a stale ISO expiry');
  assert.equal(await page.locator('#prac-expiry-display').evaluate(input => input.validity.valid), false);
  await fillExpiry(page);
  await page.locator('#scope-intake').check(); await page.locator('#scope-safety_triage').check();
  const original = structuredClone(state.profiles.get(93006)), identity = await captureIdentity(page);
  const firstResponse = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === registerPath);
  await saveProfile(page).click({ clickCount: 2 }); // A real user double-click, never a forced submission.
  await profileForm(page).locator('[disabled]').first().waitFor({ state: 'attached' });
  assert.equal(await profileForm(page).getAttribute('aria-busy'), 'true');
  assert.equal(await page.locator('#main').evaluate(main => main.inert), true);
  await screenshot(page, 'profile-saving-1440-light');
  await firstResponse;
  await page.locator('.synapse-toast-message').filter({ hasText: 'Synthetic validation:' }).waitFor({ state: 'visible' });
  await saveProfile(page).waitFor({ state: 'visible' });
  await page.waitForFunction(() => !document.querySelector('form[action$="/register"]').hasAttribute('aria-busy'));
  assert.equal(state.posts.length, 1, 'A double-click produces only one failed POST');
  assert.deepEqual(state.profiles.get(93006), original);
  assert.equal(state.requests.filter(request => request.method === 'GET').length, 1, 'A known failed write does not refresh the table');
  assert.equal(await page.locator('#prac-license').inputValue(), 'SYN-GUI-UPDATE');
  assert.equal(await page.locator('#prac-expiry').inputValue(), '2031-02-01');
  await csrfAll(page, 'practitioners-token-1');
  await screenshot(page, 'profile-422-retry-1440-light');
  const fields = [['user_id', '93006'], ['credential_tier', tiers[1]], ['license_number', 'SYN-GUI-UPDATE'], ['credential_expiry', '2031-02-01'], ['service_scope[]', 'intake'], ['service_scope[]', 'safety_triage']];
  assertPost(state, 0, registerPath, fields, 422);
  await saveProfile(page).click();
  await refreshed(page, state, identity);
  assert.equal(state.posts.length, 2); assertPost(state, 1, registerPath, fields);
  const saved = state.profiles.get(93006);
  assert.equal(saved.professional_standing, 'active'); assert.equal(saved.is_active, 1);
  assert.deepEqual(saved.service_scope, ['intake', 'safety_triage'], 'Submitted scopes replace, not merge with, previous values');
  assert.equal(await page.locator('#prac-user-id').inputValue(), '');
  await profileRows(page).filter({ hasText: '@synthetic_staff_6' }).scrollIntoViewIfNeeded();
  await screenshot(page, 'profile-authoritative-1440-light');
});

test('Workflow: optional profile creation and rollout-off use real navigation fallback, all tiers and empty scopes', { timeout: 60000 }, async t => {
  const { page, state } = await open(t, { regionalEnabled: false, permission: 'credentials-only' });
  assert.deepEqual(await page.locator('#prac-tier option').evaluateAll(options => options.map(option => option.value)), tiers);
  await choose(page, '#prac-user-id', 93008, 'synthetic_staff_8');
  await choose(page, '#prac-tier', tiers[3]);
  const identity = await captureIdentity(page);
  await saveProfile(page).click(); await refreshed(page, state, identity);
  assert.equal(state.posts.length, 1);
  assertPost(state, 0, registerPath, [['user_id', '93008'], ['credential_tier', tiers[3]], ['license_number', ''], ['credential_expiry', '']]);
  assert.deepEqual(state.profiles.get(93008).service_scope, []);
  assert.equal(state.profiles.get(93008).license_number, null); assert.equal(state.profiles.get(93008).credential_expiry, null);
  assert.equal(state.requests.filter(request => request.method === 'GET').at(-1).headers['x-synapse-navigation'], '1');
  assert.equal((await page.evaluate(() => window.fixturePractitionerEvents.filter(event => event.name === 'refresh_committed'))).length, 0);
  await profileRows(page).filter({ hasText: '@synthetic_staff_8' }).scrollIntoViewIfNeeded();
  await screenshot(page, 'profile-navigation-fallback-1440-light');
});

test('Workflow: supervision required, confirmation cancel/accept, co-sign 1/0 and prior-active closure are exact', { timeout: 90000 }, async t => {
  const { page, state } = await open(t, { permission: 'supervision-only' });
  const form = supervisionForm(page);
  assert.equal(await form.getAttribute('data-confirm'), confirmationText);
  assert.equal(await form.getAttribute('data-confirm-title'), 'Assign Supervision');
  assert.equal(await form.getAttribute('data-confirm-label'), 'Save Assignment');
  assert.equal(await form.getAttribute('data-confirm-tone'), 'primary');
  await saveSupervision(page).click();
  assert.equal(state.posts.length, 0); assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false);
  assert.equal(await page.locator('#sup-associate').evaluate(source => source.required && source.validity.valueMissing), true);
  await choose(page, '#sup-associate', 93004, 'synthetic_staff_4'); // Not an associate tier; actual service does not tier-filter.
  await choose(page, '#sup-supervisor', 93008, 'synthetic_staff_8'); // Unprofiled supplied staff also remain options.
  assert.equal(await page.locator('#sup-cosign').isChecked(), true);
  const before = structuredClone([...state.assignments.values()]);
  await confirmAssignment(page, false, 'supervision-cancel-1440-light');
  assert.equal(state.posts.length, 0); assert.deepEqual([...state.assignments.values()], before);
  assert.equal(await page.locator('#sup-associate').inputValue(), '93004');
  let identity = await captureIdentity(page);
  await confirmAssignment(page, true, 'supervision-accept-required-1440-light');
  await refreshed(page, state, identity);
  assert.equal(state.posts.length, 1);
  assertPost(state, 0, supervisionPath, [['associate_user_id', '93004'], ['supervisor_user_id', '93008'], ['co_sign_required', '0'], ['co_sign_required', '1']]);
  assert.equal(state.assignments.get(95002).status, 'completed'); assert.ok(state.assignments.get(95002).ends_at);
  assert.equal(state.assignments.get(95011).co_sign_required, 1);
  await choose(page, '#sup-associate', 93004, 'synthetic_staff_4');
  await choose(page, '#sup-supervisor', 93002, 'synthetic_staff_2');
  await page.locator('#sup-cosign').uncheck();
  identity = await captureIdentity(page);
  await confirmAssignment(page, true, 'supervision-accept-consultative-1440-light');
  await refreshed(page, state, identity);
  assert.equal(state.posts.length, 2);
  assertPost(state, 1, supervisionPath, [['associate_user_id', '93004'], ['supervisor_user_id', '93002'], ['co_sign_required', '0']]);
  assert.equal(state.assignments.get(95011).status, 'completed'); assert.ok(state.assignments.get(95011).ends_at);
  assert.equal(state.assignments.get(95012).co_sign_required, 0);
  assert.equal([...state.assignments.values()].filter(row => row.associate_user_id === 93004 && row.status === 'active').length, 1);
  await supervisionsTable(page).scrollIntoViewIfNeeded();
  await screenshot(page, 'supervision-authoritative-1440-light');
});

test('Workflow: self-supervision 422 retains real selections, rotates CSRF and demands a fresh explicit confirmation', { timeout: 60000 }, async t => {
  const { page, state } = await open(t, { expectedStatuses: [422] });
  await choose(page, '#sup-associate', 93001, 'synthetic_staff_1'); await choose(page, '#sup-supervisor', 93001, 'synthetic_staff_1');
  const original = structuredClone([...state.assignments.values()]);
  await confirmAssignment(page, true, null);
  await page.locator('.synapse-toast-message').filter({ hasText: 'Synthetic validation:' }).waitFor({ state: 'visible' });
  await page.waitForFunction(() => !document.querySelector('form[action$="/supervision"]').hasAttribute('aria-busy'));
  assert.equal(state.posts.length, 1); assert.deepEqual([...state.assignments.values()], original);
  assert.equal(await page.locator('#sup-associate').inputValue(), '93001'); assert.equal(await page.locator('#sup-supervisor').inputValue(), '93001');
  await csrfAll(page, 'practitioners-token-1'); await screenshot(page, 'supervision-422-1440-light');
  await choose(page, '#sup-supervisor', 93002, 'synthetic_staff_2');
  const identity = await captureIdentity(page);
  await confirmAssignment(page, true, 'supervision-reconfirm-1440-light'); await refreshed(page, state, identity);
  assert.equal(state.posts.length, 2);
  assertPost(state, 1, supervisionPath, [['associate_user_id', '93001'], ['supervisor_user_id', '93002'], ['co_sign_required', '0'], ['co_sign_required', '1']]);
  await supervisionsTable(page).scrollIntoViewIfNeeded(); await screenshot(page, 'supervision-retry-authoritative-1440-light');
});

test('Workflow: a confirmed save with failed GET locks the old form; Retry refresh is read-only and authoritative', { timeout: 60000 }, async t => {
  const { page, state } = await open(t, { getStatuses: [503, 200] });
  await choose(page, '#prac-user-id', 93008, 'synthetic_staff_8');
  const identity = await captureIdentity(page);
  await saveProfile(page).click();
  await page.locator('.synapse-toast-message').filter({ hasText: 'Saved, but the page could not be refreshed.' }).waitFor({ state: 'visible' });
  assert.equal(state.posts.length, 1); assert.equal(state.profiles.size, 8);
  assert.equal(await profileRows(page).count(), 7, 'Failed authoritative GET keeps old rows, never optimistic synthetic results');
  assert.equal(await saveProfile(page).isEnabled(), false, 'Saved form cannot repeat an already confirmed mutation');
  await screenshot(page, 'profile-saved-refresh-failure-1440-light');
  await page.getByRole('button', { name: 'Retry refresh', exact: true }).click();
  await refreshed(page, state, identity);
  assert.equal(state.posts.length, 1); assert.equal(state.requests.filter(request => request.method === 'GET').length, 3);
  assert.equal(state.requests.filter(request => request.method === 'GET').at(-1).headers['x-requested-with'], 'XMLHttpRequest');
  await profileRows(page).filter({ hasText: '@synthetic_staff_8' }).scrollIntoViewIfNeeded();
  await screenshot(page, 'profile-readonly-refresh-retry-1440-light');
});

async function noOverflow(page) {
  const metrics = await workspace(page).evaluate(root => {
    const vw = document.documentElement.clientWidth;
    const tested = [root, ...root.querySelectorAll('.gpr-ledgers,.gpr-management,.gpr-table-wrap,.gpr-table,.gpr-form,.gpr-scope-grid,.gpr-person-name,.gpr-license,.gpr-person-meta,.ce-tag,td')];
    return { viewport: vw, document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
      offenders: tested.filter(node => node.clientWidth && node.scrollWidth > node.clientWidth + 1)
        .map(node => ({ className: node.className, label: node.dataset.label, width: node.clientWidth, scroll: node.scrollWidth,
          display: getComputedStyle(node).display, whiteSpace: getComputedStyle(node).whiteSpace, wrap: getComputedStyle(node).overflowWrap,
          minWidth: getComputedStyle(node).minWidth })).slice(0, 12) };
  });
  if (metrics.document > metrics.viewport + 1 || metrics.body > metrics.viewport + 1 || metrics.offenders.length) {
    await screenshot(page, 'overflow-' + await page.locator('html').getAttribute('data-theme') + '-' + page.viewportSize().width);
  }
  assert.ok(metrics.document <= metrics.viewport + 1 && metrics.body <= metrics.viewport + 1, 'Horizontal page overflow: ' + JSON.stringify(metrics));
  assert.deepEqual(metrics.offenders, [], 'Long synthetic content must wrap inside ledgers and controls');
}
async function hitTestMenu(page, menu) {
  const metrics = await menu.evaluate(menu => {
    const box = menu.getBoundingClientRect();
    return { left: box.left, right: box.right, top: box.top, bottom: box.bottom, width: innerWidth, height: innerHeight,
      options: [...menu.querySelectorAll('[role="option"]')].filter(option => !option.hidden).map(option => {
        const r = option.getBoundingClientRect();
        return { width: option.clientWidth, scroll: option.scrollWidth, hit: [r.left + 4, r.left + r.width / 2, r.right - 4].every(x => {
          const hit = document.elementFromPoint(x, r.top + r.height / 2); return hit === option || option.contains(hit);
        }) };
      }) };
  });
  assert.ok(metrics.left >= -1 && metrics.right <= metrics.width + 1 && metrics.top >= -1 && metrics.bottom <= metrics.height + 1,
    'Actual dropdown remains inside the viewport: ' + JSON.stringify(metrics));
  assert.ok(metrics.options.length && metrics.options.every(option => option.hit && option.scroll <= option.width + 1), 'Filtered long option must be readable and hit-testable at left/center/right');
}
for (const theme of ['light', 'dark']) for (const width of [375, 390, 768, 1024, 1280, 1440]) {
  test(`Responsive: ${width}px ${theme}, actual shell/containers, neutral cards, wrapped long content and operable dropdown`, { timeout: 60000 }, async t => {
    const { page, state } = await open(t, { theme, long: true, viewport: { width, height: width < 576 ? 844 : 1000 } });
    await noOverflow(page);
    const metrics = await workspace(page).evaluate(root => {
      const main = document.querySelector('#main').getBoundingClientRect(), sidebar = document.querySelector('#sidebar').getBoundingClientRect(), header = document.querySelector('#header').getBoundingClientRect();
      const ledgers = root.querySelector('.gpr-ledgers'), card = root.querySelector('.gpr-ledger');
      return { rootWidth: root.getBoundingClientRect().width, ledgerWidth: ledgers.getBoundingClientRect().width, container: getComputedStyle(ledgers).containerName,
        rowDisplays: [...root.querySelectorAll('.gpr-table tbody > tr')].map(row => getComputedStyle(row).display),
        cardBackground: getComputedStyle(card).backgroundColor, cellBackgrounds: [...root.querySelectorAll('.gpr-table td')].map(td => getComputedStyle(td).backgroundColor),
        mainLeft: main.left, mainTop: main.top, sidebarWidth: sidebar.width, sidebarRight: sidebar.right, headerHeight: header.height };
    });
    assert.equal(metrics.container, 'gpr-ledgers');
    assert.equal(metrics.sidebarWidth, 280, 'Use actual expanded shell sidebar dimensions'); assert.equal(metrics.headerHeight, 64);
    assert.ok(metrics.mainTop >= 64);
    assert.ok(width >= 992 ? metrics.mainLeft >= 280 : Math.abs(metrics.mainLeft) <= 1 && metrics.sidebarRight <= 1);
    assert.ok(metrics.rowDisplays.every(display => display === (metrics.ledgerWidth <= 720 ? 'grid' : 'table-row')), 'Cards follow actual ledger width, not viewport width');
    assert.ok(metrics.cellBackgrounds.every(color => color === 'rgba(0, 0, 0, 0)' || color === metrics.cardBackground), 'Cells do not paint competing gray patches in a neutral ledger');
    assert.deepEqual(await profileRows(page).first().locator('td').evaluateAll(cells => cells.map(cell => cell.dataset.label)), ['Practitioner', 'Credential Tier', 'License & Expiry', 'Standing', 'Service Scopes']);
    assert.deepEqual(await supervisionRows(page).first().locator('td').evaluateAll(cells => cells.map(cell => cell.dataset.label)), ['Associate', 'Assigned Supervisor', 'Co-Sign Policy', 'Starts At']);
    assert.equal(await workspace(page).locator('[data-mobile-cards="false"]').count(), 2, 'Shared mobile table renderer cannot compete with scoped cards');
    const prefix = `responsive-${width}-${theme}`;
    await screenshot(page, prefix + '-ledgers');
    const { source, menu } = await openSelect(page, '#prac-user-id');
    await menu.getByRole('searchbox', { name: 'Filter options', exact: true }).fill('Synthetic Forest');
    await hitTestMenu(page, menu);
    await screenshot(page, prefix + '-dropdown');
    const label = await source.locator('option[value="93006"]').evaluate(option => option.label.replace(/\s+/g, ' ').trim());
    await menu.getByRole('option', { name: label, exact: true }).click();
    assert.equal(await source.inputValue(), '93006');
    await noOverflow(page);
    if ([375, 1440].includes(width)) {
      await fillExpiry(page);
      await page.locator('#prac-expiry-display').click();
      const calendar = page.getByRole('dialog', { name: 'Choose date', exact: true });
      await calendar.waitFor({ state: 'visible' });
      const box = await calendar.boundingBox();
      assert.ok(box.x >= -1 && box.x + box.width <= width + 1);
      await screenshot(page, prefix + '-datepicker');
      await calendar.getByRole('button', { name: 'Clear', exact: true }).click();
      assert.equal(await page.locator('#prac-expiry').inputValue(), '');
    }
    assert.equal(state.posts.length, 0, 'Responsive checks never mutate records');
  });
}
