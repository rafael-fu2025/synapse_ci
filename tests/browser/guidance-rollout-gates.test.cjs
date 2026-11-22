'use strict';

// Run this file only: node --test --test-concurrency=1 tests/browser/guidance-rollout-gates.test.cjs
// Isolated actual-view/asset regression; no live application GET, DB, sign-offs,
// package/browser installs, screenshots, state injection, or production edits.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
  startServer, renderFixture, localPlaywright, installedChromium, fixtureData, allowedRequest,
  gatesPath, owner, hostile, longText, limits,
} = require('../_support/guidance-rollout-gates-browser-harness.cjs');
let harness, browser;
const audit = { contexts: 0, expectedHttpFailures: 0, pageErrors: 0, unexpectedConsoleErrors: 0, unexpectedResponses: 0, deniedRequests: 0, downloads: 0 };
const labels = { guidance_head: 'Guidance Head', qa_office: 'QA Office', dpo: 'Data Protection Officer (DPO)' };
const workspace = page => page.locator('.guidance-ui.gg-workspace.guidance-governance-gates');
const region = page => page.locator('[data-refresh-region="workspace"]');
const articles = page => page.locator('.gg-gate');
const formOf = article => article.locator('form.gg-signoff-form');
const rowsOf = article => article.locator('table.gg-signoff-table tbody > tr');
const confirm = page => page.locator('#synapseConfirmModal');
const sorted = fields => [...fields].sort((a, b) => a[0].localeCompare(b[0]));
const requiredOf = (row, state) => row.required_signoffs ?? state.authorityRoles;

before(async () => {
  const { chromium } = localPlaywright();
  const executablePath = installedChromium(chromium);
  harness = await startServer({ port: 0 });
  console.log(`Rollout Gates isolated origin: ${harness.origin}`);
  console.log(`Rollout Gates installed Chromium: ${executablePath}`);
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000,
    args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run'] });
}, { timeout: 30000 });
after(async () => {
  try { await browser?.close(); } finally { await harness?.close(); }
  console.log(`Rollout Gates request counts: ${JSON.stringify(harness?.counts)}`);
  console.log(`Rollout Gates browser audit: ${JSON.stringify(audit)}`);
  console.log(`Rollout Gates bounded ledgers: ${JSON.stringify(limits)}; refresh events <=20/context; browser diagnostics <=20/category/context; no screenshot files.`);
  assert.deepEqual(harness?.violations, [], 'Server must receive only allowlisted synthetic gates/local asset traffic');
  assert.equal(harness?.counts.rejected, 0);
});

async function open(t, { kind = 'mixed', theme = 'light', viewport = { width: 1280, height: 900 },
  pathname = gatesPath, postStatuses = [], getStatuses = [], ...options } = {}) {
  const state = harness.createScenario({ kind, theme, postStatuses, getStatuses, ...options });
  const context = await browser.newContext({ viewport, colorScheme: theme, reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
  audit.contexts++;
  await context.addCookies([{ name: 'synthetic_rollout_gates', value: state.id, url: harness.origin }]);
  const violations = [], errors = [], consoleErrors = [], responses = [];
  const append = (list, value) => { if (list.length < limits.diagnostics) list.push(value); else if (list.length === limits.diagnostics) list.push('Diagnostic limit exceeded'); };
  const expected = new Set([...postStatuses, ...getStatuses].filter(status => status >= 400));
  await context.route('**/*', route => {
    const request = route.request();
    if (!allowedRequest(harness.origin, state, request.method(), request.url())) {
      audit.deniedRequests++; append(violations, `${request.method()} ${request.url()}`); return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  await context.routeWebSocket('**/*', socket => { audit.deniedRequests++; append(violations, `Unexpected WebSocket ${socket.url()}`); socket.close(); });
  const page = await context.newPage();
  page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(12000);
  page.on('pageerror', error => { audit.pageErrors++; append(errors, error.message); });
  page.on('console', message => {
    if (message.type() !== 'error') return;
    const status = Number(/Failed to load resource: the server responded with a status of (\d+)/.exec(message.text())?.[1]);
    if (expected.has(status)) return;
    audit.unexpectedConsoleErrors++; append(consoleErrors, message.text());
  });
  page.on('response', response => {
    if (response.status() < 400) return;
    const url = new URL(response.url()), request = response.request();
    const known = expected.has(response.status()) && url.origin === harness.origin &&
      (request.method() === 'POST' && /\/signoff$/.test(url.pathname) || request.method() === 'GET' && url.pathname === gatesPath && request.headers()['x-synapse-refresh'] === '1');
    if (known) audit.expectedHttpFailures++;
    else { audit.unexpectedResponses++; append(responses, `${response.status()} ${request.method()} ${response.url()}`); }
  });
  page.on('dialog', dialog => { append(violations, `Unexpected native ${dialog.type()}: ${dialog.message()}`); void dialog.dismiss(); });
  page.on('download', download => { audit.downloads++; append(violations, 'Unexpected download'); void download.cancel(); });
  page.on('popup', popup => { append(violations, 'Unexpected popup'); void popup.close(); });
  let closed = false;
  async function close() {
    if (closed) return;
    closed = true;
    try {
      const overflow = await page.evaluate(() => window.fixtureRefreshEventOverflow || 0);
      assert.equal(overflow, 0, 'Refresh observation ledger must stay bounded');
    } finally { await context.close(); }
    assert.deepEqual(violations, [], 'Browser must not leave the strict local synthetic allowlist');
    assert.deepEqual(errors, [], 'Actual production assets must not throw page exceptions');
    assert.deepEqual(consoleErrors, [], 'No unexpected console errors');
    assert.deepEqual(responses, [], 'All actual local assets must load successfully');
  }
  t.after(close);
  await page.goto(harness.origin + pathname);
  await page.waitForFunction(() => document.readyState === 'complete' && window.Alpine && window.SynapseBackgroundActions &&
    window.SynapseNavigation && window.SynapseRefreshRegions && window.SynapsePageLifecycle && window.SynapseCsrf && window.SynapseMutations);
  await page.waitForFunction(() => [...document.querySelectorAll('.gg-signoff-form select')].every(select => select.closest('.bd-select')));
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return { page, state, close };
}
async function openAuthorityMenu(form) {
  const button = form.getByRole('combobox');
  const id = await button.getAttribute('aria-controls');
  assert.ok(id, 'The actual local widget must identify its listbox');
  const menu = form.page().locator(`[id="${id}"]`);
  await button.scrollIntoViewIfNeeded();
  await form.page().waitForTimeout(80); // Settle the normal scroll before the widget's scroll-dismiss handler can observe it.
  await button.click();
  await menu.waitFor({ state: 'visible' });
  assert.equal(await menu.getAttribute('role'), 'listbox');
  assert.equal(await button.getAttribute('aria-expanded'), 'true');
  return menu;
}
async function chooseAuthority(form, value) {
  const text = await form.locator('select[name="authority_role"] option').evaluateAll((options, role) =>
    options.find(option => option.value === role)?.textContent.trim(), value);
  assert.ok(text, `Rendered required authority option is missing: ${value}`);
  const menu = await openAuthorityMenu(form);
  await menu.getByRole('option', { name: text, exact: true }).click();
  if (await form.getByRole('combobox').getAttribute('aria-expanded') === 'true') await form.page().keyboard.press('Escape');
  assert.equal(await form.locator('select[name="authority_role"]').inputValue(), value);
}
async function requestConfirmation(page, article) {
  await formOf(article).getByRole('button', { name: 'Record Sign-Off', exact: true }).click();
  await confirm(page).waitFor({ state: 'visible' });
  assert.equal(await confirm(page).locator('[data-confirm-title-text]').textContent(), 'Confirm Activation Gate Sign-off');
}
async function accept(page) {
  await confirm(page).getByRole('button', { name: 'Record Sign-off', exact: true }).click();
  await confirm(page).waitFor({ state: 'hidden' });
}
async function refreshed(page, expectedCount = 1) {
  await page.waitForFunction(count => window.fixtureRefreshEvents.filter(event => event.name === 'refresh_committed').length === count, expectedCount);
  const event = await page.evaluate(() => window.fixtureRefreshEvents.filter(event => event.name === 'refresh_committed').at(-1));
  assert.equal(event.detail.owner, owner);
  assert.deepEqual(event.detail.regions, ['workspace']);
  assert.equal(event.detail.status, 'refreshed');
  await page.waitForFunction(() => [...document.querySelectorAll('.gg-signoff-form select')].every(select => select.closest('.bd-select')));
  assert.equal(await page.locator('.gg-signoff-form select').count(), await page.locator('.gg-signoff-form .bd-select').count(), 'Lifecycle must upgrade each incoming native select exactly once');
}
async function csrfAll(page, hash) {
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), hash);
  const tokens = await page.locator('input[name="fixture_csrf"]').evaluateAll(inputs => inputs.map(input => input.value));
  assert.ok(tokens.every(value => value === hash), 'Every actual form must share the current CSRF owner');
}
async function summary(page, state) {
  const all = [...state.gates.values()];
  const enabled = all.filter(row => Number(row.is_enabled || 0) === 1).length;
  assert.deepEqual(await page.locator('.gg-summary-label').allTextContents(), ['Configured gates', 'Enabled gates', 'Pending gates']);
  assert.deepEqual(await page.locator('.gg-summary-value').allTextContents(), [String(all.length), String(enabled), String(all.length - enabled)]);
}
async function noOverflow(page) {
  const sizes = await page.evaluate(() => {
    const root = document.querySelector('.gg-workspace'), sheet = document.querySelector('.gg-sheet'), box = sheet.getBoundingClientRect();
    const offenders = [...sheet.querySelectorAll('.gg-gate-title,.gg-gate-key,.gg-gate-description,.gg-table,.gg-table tbody tr,.gg-notes-text,.gg-signer-name,.gg-signoff-form,.bd-select-toggle,input[name="notes"],button[type="submit"]')]
      .filter(el => { const r = el.getBoundingClientRect(); return r.width && (r.x < box.x - 1 || r.right > box.right + 1 || el.scrollWidth > el.clientWidth + 1); })
      .map(el => ({ className: el.className, width: el.clientWidth, scrollWidth: el.scrollWidth, right: el.getBoundingClientRect().right }));
    return { viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
      root: root.clientWidth, rootScroll: root.scrollWidth, sheet: sheet.clientWidth, sheetScroll: sheet.scrollWidth, offenders };
  });
  assert.ok(sizes.document <= sizes.viewport + 1 && sizes.body <= sizes.viewport + 1 && sizes.rootScroll <= sizes.root + 1 && sizes.sheetScroll <= sizes.sheet + 1,
    `Horizontal overflow: ${JSON.stringify(sizes)}`);
  assert.deepEqual(sizes.offenders, [], 'Long literal evidence and form widgets must stay inside the sheet without cell overflow');
}
function assertPayload(post, key, role, notes, csrf) {
  assert.equal(post.path, `${gatesPath}/${encodeURIComponent(key)}/signoff`);
  assert.deepEqual(sorted(post.fields), sorted([['fixture_csrf', csrf], ['confirmed', '1'], ['authority_role', role], ['notes', notes]]));
  assert.equal(post.headers['x-fixture-csrf'], csrf);
  assert.equal(post.headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(post.headers['x-synapse-feedback'], 'toast');
  assert.match(post.headers.accept, /application\/json/);
  assert.equal(post.status, 200);
}

// Ten bounded cases, with state/size matrices in cases rather than broad suites.
test('CLI renderer is isolated, actual-view/real-confirm/styles-only and rejects missing/foreign source dependencies', { timeout: 15000 }, () => {
  const data = fixtureData();
  const html = renderFixture({ origin: 'http://127.0.0.1:12345', path: gatesPath, csrf: 'synthetic-explicit-token', ...data });
  assert.match(html, /^<!doctype html>/);
  assert.match(html, /class="guidance-ui guidance-governance-gates gg-workspace"/);
  assert.match(html, /data-refresh-owner="guidance\.governance\.gates"/);
  assert.match(html, /id="page-styles" hidden>[\s\S]*assets\/css\/pages\/guidance-gates\.css/);
  assert.match(html, /id="synapseConfirmModal"[\s\S]*data-confirm-message[\s\S]*data-confirm-accept/);
  assert.match(html, /name="fixture_csrf" value="synthetic-explicit-token"/);
  assert.doesNotMatch(html, /name="version"|data-fixture-alpine|https?:\/\/(?!127\.0\.0\.1)[^\s"]+"\s*(?:src|href)=/);
  assert.throws(() => renderFixture({ origin: 'https://example.com', ...data }));
  assert.throws(() => renderFixture({ origin: 'http://localhost:12345', ...data }));
  assert.throws(() => renderFixture({ origin: 'http://127.0.0.1:12345', path: '/guidance/governance/practitioners', ...data }));
  const source = fs.readFileSync(path.join(__dirname, '../_support/render-guidance-rollout-gates-fixture.php'), 'utf8');
  assert.match(source, /PHP_SAPI !== 'cli'/);
  assert.match(source, /Unexpected view dependency/);
  assert.doesNotMatch(source, /require\s+.*(?:vendor|bootstrap|Controllers|Models)|session_start\s*\(|Database::|DotEnv/);
});

test('Pending/partial/enabled/read-only/empty/custom gate/custom authority and exact activation flags render dynamically', { timeout: 45000 }, async t => {
  for (const kind of ['pending', 'partial', 'enabled', 'read-only', 'empty', 'customgate', 'customauthority', 'flags']) {
    const { page, state, close } = await open(t, { kind });
    await summary(page, state);
    assert.equal(await workspace(page).count(), 1, kind);
    assert.equal(await workspace(page).getAttribute('data-refresh-owner'), owner);
    assert.equal(await workspace(page).getAttribute('data-refresh-url'), page.url());
    assert.equal(await workspace(page).locator('.gg-sheet > [data-refresh-region="workspace"][data-refresh-heading][tabindex="-1"]').count(), 1);
    assert.equal(await articles(page).count(), state.gates.size);
    assert.equal(await page.locator('.gg-signoff-form').count(), state.canSignOff ? state.gates.size : 0);
    if (!state.gates.size) {
      assert.match(await workspace(page).getByRole('status').textContent(), /No activation gates found for this tenant\./);
      assert.equal(await page.locator('.gg-signoff-table').count(), 0);
    }
    for (const [index, row] of [...state.gates.values()].entries()) {
      const article = articles(page).nth(index), required = requiredOf(row, state);
      const signed = required.filter(role => Object.hasOwn(row.recorded_signoffs, role)).length;
      assert.equal(await article.locator('.gg-gate-title').textContent(), row.title);
      assert.equal(await article.locator('.gg-gate-key').textContent(), row.gate_key);
      assert.equal(await article.locator('.gg-gate-head .gg-status').textContent(), Number(row.is_enabled || 0) === 1 ? 'Enabled' : 'Gated');
      assert.equal(await article.locator('.gg-progress').textContent(), `${signed}/${required.length} required sign-offs`);
      assert.equal(await article.getByRole('table').count(), 1);
      assert.equal(await article.locator('.gg-table-wrap').getAttribute('data-mobile-cards'), 'false');
      assert.equal(await article.locator('caption').textContent(), `Current authority sign-offs for ${row.title}`);
      assert.deepEqual(await article.locator('thead th').allTextContents(), ['Authority', 'Status', 'Recorded by', 'Notes / reference']);
      assert.ok((await article.locator('thead th').evaluateAll(ths => ths.map(th => th.getAttribute('scope')))).every(scope => scope === 'col'));
      assert.equal(await rowsOf(article).count(), required.length);
      const displayLabel = role => labels[role] || role.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
      assert.deepEqual(await rowsOf(article).locator('.gg-authority-name').allTextContents(), required.map(displayLabel));
      if (state.canSignOff) {
        const form = formOf(article);
        assert.equal(await form.getAttribute('action'), `${harness.origin}${gatesPath}/${encodeURIComponent(row.gate_key)}/signoff`);
        assert.deepEqual(await form.locator('select option').evaluateAll(options => options.map(option => ({ value: option.value, text: option.textContent.trim() }))),
          [{ value: '', text: 'Select authority...' }, ...required.map(role => ({ value: role, text: (labels[role] || role) + (row.recorded_signoffs[role] ? ' (Update Sign-off)' : '') }))]);
      }
    }
    assert.equal(state.posts.length, 0);
    assert.equal(await page.locator('.gg-workspace script,.gg-workspace img').count(), 0, 'Hostile fixture content must remain literal');
    await close();
  }
});

test('Recorded evidence preserves escaped/long notes, resolved and fallback names, and original date-call strings', { timeout: 25000 }, async t => {
  const { page, state } = await open(t);
  const partial = articles(page).nth(1), enabled = articles(page).nth(2);
  assert.equal(await rowsOf(partial).first().locator('.gg-signer-name').textContent(), state.signerNames[93001]);
  assert.equal(await rowsOf(partial).first().locator('.gg-notes-text').textContent(), hostile + '\n' + longText);
  assert.deepEqual(await rowsOf(partial).first().locator('.gg-notes-text').evaluate(el => ({ whitespace: getComputedStyle(el).whiteSpace, wrap: getComputedStyle(el).overflowWrap })),
    { whitespace: 'pre-wrap', wrap: 'anywhere' });
  assert.equal(await rowsOf(enabled).nth(1).locator('.gg-signer-name').textContent(), 'User #93002');
  assert.equal(await rowsOf(enabled).nth(2).locator('.gg-signer-name').textContent(), 'Authorized Officer');
  assert.equal(await rowsOf(enabled).nth(1).locator('.gg-notes-cell .gg-muted').textContent(), '—');
  assert.equal(await rowsOf(enabled).nth(2).locator('.gg-timestamp').count(), 0, 'An absent signed_at does not invent a date');
  assert.equal(await enabled.locator('.gg-approved .gg-timestamp').textContent(), 'Jan 31, 2030 12:30 AM');
  assert.equal(await rowsOf(partial).first().locator('.gg-timestamp').textContent(), 'Jan 31, 2030 12:30 AM');
  assert.equal(await articles(page).first().locator('.gg-gate-description').textContent(), hostile + '\n' + longText);
  assert.equal(await page.locator('.gg-workspace script,.gg-workspace img,[onerror]').count(), 0);
  await noOverflow(page);
  assert.equal(state.posts.length, 0);
});

test('Native form IDs, required choices, POST/background/confirmed/CSRF and 1000-character notes survive real widget validation', { timeout: 25000 }, async t => {
  const { page, state } = await open(t, { kind: 'pending' });
  const article = articles(page).first(), form = formOf(article), row = [...state.gates.values()][0];
  assert.equal(await form.getAttribute('method'), 'POST');
  assert.equal(await form.getAttribute('data-background-submit'), '');
  assert.equal(await form.locator('select').getAttribute('id'), `authority-${row.gate_key}`);
  assert.equal(await form.locator('input[name="notes"]').getAttribute('id'), `notes-${row.gate_key}`);
  assert.deepEqual(await form.locator('label').evaluateAll(labels => labels.map(label => label.htmlFor)), [`authority-${row.gate_key}`, `notes-${row.gate_key}`]);
  assert.equal(await form.locator('select').getAttribute('required'), '');
  assert.equal(await form.locator('input[name="notes"]').getAttribute('maxlength'), '1000');
  assert.equal(await form.locator('input[name="confirmed"]').inputValue(), '1');
  assert.equal(await form.locator('[name="version"]').count(), 0);
  await csrfAll(page, 'gates-token-0');
  await form.getByRole('button', { name: 'Record Sign-Off', exact: true }).click();
  await form.getByRole('combobox').locator('..').getByRole('alert').waitFor({ state: 'visible' });
  assert.equal(await form.getByRole('combobox').getAttribute('aria-invalid'), 'true');
  assert.equal(await confirm(page).isVisible(), false, 'Native required validation precedes confirmation');
  assert.equal(state.posts.length, 0);
  await chooseAuthority(form, 'dpo');
  assert.equal(await form.getByRole('combobox').getAttribute('aria-invalid'), 'false');
  await form.getByLabel('Sign-off notes / reference', { exact: true }).fill('N');
  await form.getByLabel('Sign-off notes / reference', { exact: true }).press('End');
  await page.keyboard.insertText('x'.repeat(1100));
  assert.equal((await form.locator('input[name="notes"]').inputValue()).length, 1000, 'Real user input is capped by the native maxlength');
  await requestConfirmation(page, article);
  await confirm(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await confirm(page).waitFor({ state: 'hidden' });
  assert.equal(state.posts.length, 0);
});

test('Confirmation cancel sends zero POST; acceptance sends one exact payload and refreshes actual records without replacing owner/URL', { timeout: 25000 }, async t => {
  const { page, state } = await open(t, { kind: 'pending', pathname: gatesPath + '?fixture_case=confirmation' });
  const currentUrl = page.url(), main = await page.locator('#main').elementHandle(), root = await workspace(page).elementHandle();
  const boundary = await region(page).elementHandle(), outgoing = await region(page).locator('> :first-child').elementHandle();
  const article = articles(page).first(), row = [...state.gates.values()][0], notes = 'Synthetic QA "reference" <literal> & pending evidence';
  await chooseAuthority(formOf(article), 'qa_office');
  await formOf(article).getByLabel('Sign-off notes / reference', { exact: true }).fill(notes);
  await requestConfirmation(page, article);
  assert.equal(await confirm(page).locator('[data-confirm-message]').textContent(),
    `Record official authority sign-off for '${row.title}'? Once all required authorities sign off, this gate becomes enabled.`);
  assert.equal(await confirm(page).locator('[data-confirm-message] script,[data-confirm-message] img').count(), 0);
  assert.equal(state.posts.length, 0);
  await confirm(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await confirm(page).waitFor({ state: 'hidden' });
  assert.equal(state.posts.length, 0);
  assert.equal(await formOf(article).locator('input[name="notes"]').inputValue(), notes);
  await requestConfirmation(page, article); await accept(page); await refreshed(page);
  assert.equal(state.posts.length, 1); assertPayload(state.posts[0], row.gate_key, 'qa_office', notes, 'gates-token-0');
  assert.equal(await rowsOf(articles(page).first()).nth(1).locator('.gg-status').textContent(), 'Signed');
  assert.equal(await rowsOf(articles(page).first()).nth(1).locator('.gg-signer-name').textContent(), 'Synthetic fixture actor');
  assert.equal(await rowsOf(articles(page).first()).nth(1).locator('.gg-notes-text').textContent(), notes);
  assert.equal(await rowsOf(articles(page).first()).nth(1).locator('.gg-timestamp').textContent(), 'Feb 1, 2030 4:05 AM');
  assert.equal(await articles(page).first().locator('.gg-progress').textContent(), '1/3 required sign-offs');
  assert.equal(page.url(), currentUrl);
  assert.equal(await workspace(page).getAttribute('data-refresh-url'), currentUrl);
  assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);
  assert.equal(await root.evaluate(el => el === document.querySelector('.gg-workspace')), true);
  assert.equal(await boundary.evaluate(el => el === document.querySelector('[data-refresh-region="workspace"]')), true);
  assert.equal(await outgoing.evaluate(el => el.isConnected), false);
  assert.deepEqual(state.requests.map(request => [request.method, request.path]), [['GET', gatesPath + '?fixture_case=confirmation'], ['POST', `${gatesPath}/${encodeURIComponent(row.gate_key)}/signoff`], ['GET', gatesPath]]);
  assert.equal(state.requests.at(-1).headers['x-synapse-refresh'], '1');
  await csrfAll(page, 'gates-token-1');
  assert.equal(await page.evaluate(() => window.fixtureRefreshEvents.filter(event => event.name !== 'refresh_committed').length), 0);
});

test('Updating an existing authority replaces its row; all required choices remain and the last explicit sign-off enables the gate', { timeout: 30000 }, async t => {
  const { page, state } = await open(t, { kind: 'partial' });
  const row = [...state.gates.values()][0], roles = ['guidance_head', 'qa_office', 'dpo'];
  for (const [index, role] of roles.entries()) {
    const article = articles(page).first(), form = formOf(article), notes = `Synthetic explicit ${role} reference ${index}`;
    const optionTexts = await form.locator('select option').allTextContents();
    assert.match(optionTexts[1], /Guidance Head \(Update Sign-off\)/);
    assert.equal(await form.locator('select option').count(), 4, 'Recorded authorities remain selectable for updates');
    await chooseAuthority(form, role);
    await form.getByLabel('Sign-off notes / reference', { exact: true }).fill(notes);
    await requestConfirmation(page, article); await accept(page); await refreshed(page, index + 1);
    assert.equal(state.posts.length, index + 1); assertPayload(state.posts[index], row.gate_key, role, notes, `gates-token-${index}`);
    assert.equal(await rowsOf(articles(page).first()).count(), 3, 'An update is not an append-only history row');
    assert.equal(await rowsOf(articles(page).first()).nth(index).locator('.gg-notes-text').textContent(), notes);
    assert.equal(await rowsOf(articles(page).first()).nth(index).locator('.gg-signer-name').textContent(), 'Synthetic fixture actor');
    await csrfAll(page, `gates-token-${index + 1}`);
    await summary(page, state);
  }
  assert.equal(await articles(page).first().locator('.gg-gate-head .gg-status').textContent(), 'Enabled');
  assert.equal(await articles(page).first().locator('.gg-progress').textContent(), '3/3 required sign-offs');
  assert.equal(await articles(page).first().locator('.gg-approved .gg-timestamp').textContent(), 'Feb 1, 2030 4:05 AM');
  assert.deepEqual(await formOf(articles(page).first()).locator('select option').allTextContents(),
    ['Select authority...', ...roles.map(role => labels[role] + ' (Update Sign-off)')]);
  assert.equal(state.requests.filter(request => request.method === 'GET').length, 4);
});

test('Known 422/403 retain authority/notes and rotate CSRF without auto-post; only a new explicit confirmation retries', { timeout: 35000 }, async t => {
  for (const status of [422, 403]) {
    const { page, state, close } = await open(t, { kind: 'pending', postStatuses: [status, 200] });
    const article = articles(page).first(), form = formOf(article), row = [...state.gates.values()][0], notes = `Synthetic retained ${status} <literal> notes`;
    await chooseAuthority(form, 'dpo'); await form.getByLabel('Sign-off notes / reference', { exact: true }).fill(notes);
    await requestConfirmation(page, article); await accept(page);
    await page.locator('.synapse-toast-message').filter({ hasText: status === 422 ? 'Synthetic validation:' : 'Synthetic authorization:' }).waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('.gg-signoff-form').hasAttribute('aria-busy'));
    assert.equal(await form.locator('select').inputValue(), 'dpo');
    assert.equal(await form.locator('input[name="notes"]').inputValue(), notes);
    assert.equal(await form.getByRole('button', { name: 'Record Sign-Off', exact: true }).isDisabled(), false);
    assert.equal(await page.locator('#main').evaluate(el => el.inert), false);
    await csrfAll(page, 'gates-token-1');
    assert.equal(state.posts.length, 1); assert.equal(state.posts[0].status, status);
    assert.deepEqual(sorted(state.posts[0].fields), sorted([['fixture_csrf', 'gates-token-0'], ['confirmed', '1'], ['authority_role', 'dpo'], ['notes', notes]]));
    assert.equal(state.requests.filter(request => request.method === 'GET').length, 1);
    assert.equal(await page.evaluate(() => window.fixtureRefreshEvents.length), 0);
    assert.equal(Object.keys(row.recorded_signoffs).length, 0);
    await page.waitForTimeout(150); // Bounded guard interval for unintended automatic resubmission.
    assert.equal(state.posts.length, 1);
    await requestConfirmation(page, article); assert.equal(state.posts.length, 1);
    await accept(page); await refreshed(page);
    assert.equal(state.posts.length, 2); assertPayload(state.posts[1], row.gate_key, 'dpo', notes, 'gates-token-1');
    await csrfAll(page, 'gates-token-2'); await close();
  }
});

test('A failed GET after a committed sign-off keeps the saved form locked and Retry refresh performs GET only', { timeout: 25000 }, async t => {
  const { page, state } = await open(t, { kind: 'pending', getStatuses: [503, 200] });
  const row = [...state.gates.values()][0], article = articles(page).first(), form = formOf(article), currentUrl = page.url();
  await chooseAuthority(form, 'guidance_head');
  await form.getByLabel('Sign-off notes / reference', { exact: true }).fill('Synthetic committed evidence');
  await requestConfirmation(page, article); await accept(page);
  const retry = page.getByRole('button', { name: 'Retry refresh', exact: true });
  await retry.waitFor({ state: 'visible' });
  assert.match(await page.locator('.synapse-toast-message').filter({ hasText: 'Saved, but the page could not be refreshed.' }).textContent(), /Do not submit again/);
  assert.equal(state.posts.length, 1); assertPayload(state.posts[0], row.gate_key, 'guidance_head', 'Synthetic committed evidence', 'gates-token-0');
  assert.equal(row.recorded_signoffs.guidance_head.notes, 'Synthetic committed evidence');
  assert.equal(await rowsOf(article).first().locator('.gg-status').textContent(), 'Pending', 'A failed refresh does not fabricate visible evidence');
  assert.equal(await form.getByRole('button', { name: 'Record Sign-Off', exact: true }).isDisabled(), true);
  assert.equal(await form.locator('select').isDisabled(), true);
  await csrfAll(page, 'gates-token-1');
  assert.deepEqual(state.requests.map(request => [request.method, request.status]), [['GET', 200], ['POST', 200], ['GET', 503]]);
  await retry.click(); await refreshed(page);
  assert.equal(state.posts.length, 1, 'Retry refresh must never resubmit the confirmed write');
  assert.deepEqual(state.requests.map(request => [request.method, request.status]), [['GET', 200], ['POST', 200], ['GET', 503], ['GET', 200]]);
  assert.equal(await rowsOf(articles(page).first()).first().locator('.gg-status').textContent(), 'Signed');
  assert.equal(await rowsOf(articles(page).first()).first().locator('.gg-notes-text').textContent(), 'Synthetic committed evidence');
  assert.equal(page.url(), currentUrl);
  assert.deepEqual(await page.evaluate(() => window.fixtureRefreshEvents.map(event => event.name)), ['refresh_failed', 'refresh_committed']);
  assert.equal(await formOf(articles(page).first()).getByRole('button', { name: 'Record Sign-Off', exact: true }).isDisabled(), false);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: 1280 desktop, 390/320 mobile and 350 desktop container use actual zebra/cell surfaces and stay contained`, { timeout: 45000 }, async t => {
    for (const [layout, viewport, pathname, columns] of [
      ['desktop', { width: 1280, height: 900 }, gatesPath, 3],
      ['mobile390', { width: 390, height: 844 }, gatesPath, 2],
      ['mobile320', { width: 320, height: 780 }, gatesPath, 1],
      ['container350', { width: 1280, height: 900 }, gatesPath + '?container=1', 1],
    ]) {
      const { page, state, close } = await open(t, { theme, viewport, pathname });
      assert.equal(await page.locator('link[href$="/assets/css/pages/guidance-gates.css"]').count(), 1, 'New CSS must arrive through the actual styles section');
      const surface = await page.locator('.gg-sheet').evaluate(el => getComputedStyle(el).backgroundColor);
      assert.equal(surface, theme === 'dark' ? 'rgb(28, 28, 28)' : 'rgb(255, 255, 255)');
      const evidence = await articles(page).nth(1).locator('.gg-signoff-table').evaluate(table => {
        const rows = [...table.tBodies[0].rows];
        const effective = element => {
          for (let node = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            if (style.backgroundColor !== 'rgba(0, 0, 0, 0)' && style.backgroundColor !== 'transparent') return style.backgroundColor;
          }
          return 'transparent';
        };
        return rows.map(row => ({ display: getComputedStyle(row).display, columns: getComputedStyle(row).gridTemplateColumns,
          background: getComputedStyle(row).backgroundColor, effective: effective(row),
          cells: [...row.cells].map(cell => ({ background: getComputedStyle(cell).backgroundColor, effective: effective(cell), shadow: getComputedStyle(cell).boxShadow })) }));
      });
      assert.equal(evidence[0].display, layout === 'desktop' ? 'table-row' : 'grid', layout);
      if (layout !== 'desktop') assert.equal(evidence[0].columns.trim().split(/\s+/).length, columns);
      const stripe = theme === 'dark' ? 'rgb(34, 34, 34)' : 'rgb(250, 250, 250)';
      const stripeToken = await workspace(page).evaluate(el => getComputedStyle(el).getPropertyValue('--table-stripe-bg').trim());
      assert.equal(stripeToken, theme === 'dark' ? '#222222' : '#fafafa', 'Use the production inherited zebra token, not the workspace fallback');
      assert.equal(evidence[0].background, stripe, 'Odd evidence rows use the real computed zebra surface');
      assert.equal(evidence[2].background, stripe);
      assert.equal(evidence[1].effective, surface, 'Even rows expose the sheet rather than a global table fill');
      for (const [index, row] of evidence.entries()) {
        assert.ok(row.cells.every(cell => cell.effective === (index % 2 === 0 ? stripe : surface)), 'Cells must not cover the actual row stripe with white/global backgrounds');
        assert.ok(row.cells.every(cell => cell.shadow === 'none'), 'Global Bootstrap table stripe shadows must not cover the local sheet');
      }
      const formColumns = await formOf(articles(page).first()).evaluate(form => getComputedStyle(form).gridTemplateColumns.trim().split(/\s+/).length);
      assert.equal(formColumns, layout === 'desktop' ? 3 : 1);
      await noOverflow(page);
      const form = formOf(articles(page).first());
      const listbox = await openAuthorityMenu(form);
      const menu = await listbox.boundingBox();
      assert.ok(menu && menu.x >= 0 && menu.x + menu.width <= viewport.width + 1, 'The production local dropdown remains inside the viewport');
      await listbox.getByRole('option', { name: 'Guidance Head', exact: true }).click();
      if (await form.getByRole('combobox').getAttribute('aria-expanded') === 'true') await page.keyboard.press('Escape');
      assert.equal(await form.locator('select').inputValue(), 'guidance_head');
      await noOverflow(page);
      assert.equal(state.posts.length, 0);
      await close();
    }
  });
}
