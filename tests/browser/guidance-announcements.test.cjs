'use strict';

// Run only this suite: node --test --test-concurrency=1 tests/browser/guidance-announcements.test.cjs
// Actual views + local production JS/CSS, synthetic data and in-memory requests.
// No dotenv/framework bootstrap, live DB, real publications, exports or screenshots.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { startServer, renderFixture, localPlaywright, installedChromium } = require('../_support/guidance-announcements-browser-harness.cjs');
let harness, browser;
const listPath = '/guidance/announcements?reads=42&page=1&read_page=1';
const noticeBody = "Synthetic <script>alert('never execute')</script> & \"quoted\" <img src=x onerror=alert(1)>\nSecond line remains plain text.\n\n" + 'UnbrokenGuidanceBody'.repeat(75);
const region = (page, name) => page.locator(`[data-refresh-region="announcements.${name}"]`);
const rows = page => page.locator('.ga-announcement-table tbody > tr');
const modal = page => page.locator('#appFormModal');

before(async () => {
  const { chromium } = localPlaywright();
  const executablePath = installedChromium(chromium);
  harness = await startServer();
  console.log(`Guidance announcements synthetic origin: ${harness.origin}`);
  console.log(`Guidance announcements local Chromium: ${executablePath}`);
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => {
  try { await browser?.close(); } finally { await harness?.close(); }
  assert.deepEqual(harness?.violations, [], 'Server must receive only allowlisted synthetic/local requests');
});

async function open(t, { path = listPath, theme = 'light', viewport = { width: 1440, height: 1000 }, statuses = [], reducedMotion = 'reduce' } = {}) {
  const state = harness.createScenario({ theme, postStatuses: [...statuses] });
  const context = await browser.newContext({ viewport, reducedMotion, serviceWorkers: 'block', timezoneId: 'Asia/Manila', colorScheme: theme });
  await context.addCookies([{ name: 'synthetic_announcements', value: state.id, url: harness.origin }]);
  const violations = [], errors = [], failed = [], consoles = [];
  await context.route('**/*', route => {
    const request = route.request(), url = new URL(request.url());
    const allowed = url.origin === harness.origin && (request.method() === 'GET' &&
      (/^\/(guidance|clinic)\/announcements(?:\/(?:create|\d+\/edit))?$/.test(url.pathname) || url.pathname.startsWith('/assets/')) ||
      request.method() === 'POST' && /^\/(guidance|clinic)\/announcements\/(store|\d+\/(?:update|publish|archive))$/.test(url.pathname));
    if (!allowed) { violations.push(`${request.method()} ${request.url()}`); return route.abort(); }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(12000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.status() >= 400 && ![422, 409].includes(response.status())) failed.push(`${response.status()} ${response.url()}`);
  });
  page.on('console', message => {
    if (message.type() === 'error' && !/Failed to load resource: the server responded with a status of (422|409)/.test(message.text())) consoles.push(message.text());
  });
  page.on('download', () => violations.push('Unexpected export/download'));
  page.on('dialog', dialog => { violations.push(`Unexpected native ${dialog.type()}: ${dialog.message()}`); dialog.dismiss(); });
  t.after(async () => {
    await context.close();
    assert.deepEqual(violations, [], 'Browser must not leave local synthetic allowlist');
    assert.deepEqual(errors, [], 'Actual assets must not throw browser exceptions');
    assert.deepEqual(failed, [], 'Actual local assets must load successfully');
    assert.deepEqual(consoles, [], 'No unexpected console errors');
  });
  await page.clock.setFixedTime(new Date('2030-01-31T04:00:00Z'));
  await page.goto(harness.origin + path);
  await page.waitForFunction(() => document.readyState === 'complete' && window.SynapseBackgroundActions && window.SynapseNavigation && window.SynapseRefreshRegions && window.Alpine);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  if (path.includes('/create') || path.includes('/edit')) await editorReady(page);
  return { page, state };
}
async function editorReady(page) {
  await modal(page).locator('#announcementTitle').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('#appFormModal.show .modal-footer .form-actions') &&
    document.querySelector('#announcementAudience').closest('.bd-select') && document.querySelector('#announcement_starts_at_display').closest('.dtp-wrap'));
}
async function editFirst(page) { await rows(page).first().getByRole('link', { name: 'Edit draft', exact: true }).click(); await editorReady(page); }
async function newDraft(page) { await page.getByRole('link', { name: 'New draft', exact: true }).click(); await editorReady(page); }
async function chooseAudience(page, name) {
  const wrapper = modal(page).locator('#announcementAudience').locator('..');
  await wrapper.locator('.bd-select-toggle').click();
  await wrapper.getByRole('option', { name, exact: true }).click();
  if (await wrapper.locator('.bd-select-toggle').getAttribute('aria-expanded') === 'true') await page.keyboard.press('Escape');
}
async function noOverflow(page) {
  const actual = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
  assert.ok(actual.document <= actual.viewport + 1 && actual.body <= actual.viewport + 1, `Horizontal document overflow: ${JSON.stringify(actual)}`);
}
async function geometry(page, selector) {
  return page.locator(selector).first().evaluate(el => { const r = el.getBoundingClientRect(), s = getComputedStyle(el); return { x: r.x, y: r.y, width: r.width, height: r.height,
    right: r.right, bottom: r.bottom, display: s.display, columns: s.gridTemplateColumns, background: s.backgroundColor,
    padding: [s.paddingTop, s.paddingRight, s.paddingBottom, s.paddingLeft], scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }; });
}
async function csrfAll(page, value) {
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), value);
  assert.ok((await page.locator('input[name="fixture_csrf"]').evaluateAll(inputs => inputs.map(input => input.value))).every(token => token === value));
}
function sorted(fields) { return [...fields].sort((a, b) => `${a[0]}=${a[1]}`.localeCompare(`${b[0]}=${b[1]}`)); }
async function refreshed(page, count = 1) {
  await page.waitForFunction(n => window.fixtureRefreshEvents.filter(event => event.name === 'refresh_committed').length === n, count);
  assert.deepEqual(await page.evaluate(() => window.fixtureRefreshEvents.filter(event => event.name !== 'refresh_committed')), []);
  const event = await page.evaluate(() => window.fixtureRefreshEvents.filter(event => event.name === 'refresh_committed').at(-1));
  assert.deepEqual(event.detail.regions, ['announcements.list', 'announcements.read-history']);
  assert.equal(event.detail.owner, 'announcements.staff.guidance');
}

// Structural coverage of the renderer itself, independent of live application env.
test('Renderer is CLI-only, plain actual-view output with create/edit and fragment contracts', () => {
  const origin = 'http://127.0.0.1:12345';
  const direct = renderFixture({ origin, path: '/guidance/announcements/create' });
  const fragment = renderFixture({ origin, path: '/guidance/announcements/41/edit?fragment=1' });
  assert.match(direct, /<!doctype html>/); assert.match(direct, /data-modal-direct="true"/);
  assert.match(direct, /guidance-announcements\.css/);
  assert.doesNotMatch(fragment, /<!doctype html>|data-modal-direct|<script\b|<link\b/);
  assert.match(fragment, /data-app-form-modal/); assert.match(fragment, /name="version" value="7"/);
  assert.match(fragment, /name="fixture_csrf" value="synthetic-token-0"/);
  assert.match(fragment, /name="starts_at" value="2030-01-31T08:30"/);
  assert.match(fragment, /name="ends_at" value="2030-02-03T23:59"/);
  assert.throws(() => renderFixture({ origin: 'https://example.com', path: '/guidance/announcements' }));
});

test('Draft, published, archived, pinned, escaped multiline/unbroken preview and both regions remain correct', async t => {
  const { page, state } = await open(t);
  const owner = page.locator('[data-refresh-owner="announcements.staff.guidance"]');
  assert.equal(await owner.count(), 1);
  for (const name of ['list', 'read-history']) assert.equal(await region(page, name).count(), 1);
  assert.equal(await rows(page).count(), 25);
  assert.equal(await page.locator('.ga-read-table tbody tr').count(), 25);
  assert.equal(await rows(page).first().locator('.ga-announcement-title').textContent(), 'Draft <campus> & "advisory"');
  assert.deepEqual(await rows(page).locator('.ga-status').allTextContents().then(v => v.slice(0, 4)), ['Draft', 'Published', 'Archived', 'Draft']);
  assert.equal(await rows(page).first().locator('.ga-advisory').count(), 1);
  assert.equal(await rows(page).nth(3).locator('.ga-advisory').count(), 0);
  assert.equal(await rows(page).first().getByRole('button', { name: 'Publish', exact: true }).count(), 1);
  assert.equal(await rows(page).nth(1).getByRole('button', { name: 'Publish', exact: true }).count(), 0);
  assert.equal(await rows(page).nth(1).getByRole('link', { name: 'Edit draft', exact: true }).count(), 0);
  assert.equal(await rows(page).nth(1).getByRole('button', { name: 'Archive', exact: true }).count(), 1);
  assert.equal(await rows(page).nth(2).getByRole('button').count(), 0);
  assert.equal(await rows(page).nth(2).getByRole('link', { name: 'Read history', exact: true }).count(), 1);
  assert.match(await rows(page).first().locator('.ga-audience-cell').textContent(), /Years: 1 or 3 AND Categories: New or Graduating/);
  assert.match(await rows(page).first().locator('.ga-visibility').textContent(), /2030-01-31 08:30/);
  assert.match(await rows(page).first().locator('.ga-visibility').textContent(), /2030-02-03 23:59/);
  assert.match(await rows(page).nth(1).locator('.ga-visibility').textContent(), /On publication[\s\S]*No expiry/);
  await rows(page).first().locator('.ga-preview summary').click();
  const body = rows(page).first().locator('.ga-preview-text');
  assert.equal(await body.textContent(), noticeBody); assert.equal(await body.locator('script,img').count(), 0);
  assert.deepEqual(await body.evaluate(el => ({ whitespace: getComputedStyle(el).whiteSpace, wrap: getComputedStyle(el).overflowWrap })), { whitespace: 'pre-wrap', wrap: 'anywhere' });
  assert.ok((await geometry(page, '.ga-preview-text')).scrollWidth <= (await geometry(page, '.ga-preview-text')).clientWidth + 1);
  assert.equal(await page.locator('.ga-read-table tbody tr').first().locator('td').first().textContent(), 'Synthetic <reader> Person 1');
  assert.match(await page.locator('.ga-read-table tbody tr').first().textContent(), /2030-01-31 10:00/);
  assert.equal(await owner.locator('input[name="person_id"]').count(), 0);
  await noOverflow(page); assert.equal(state.posts.length, 0);
});

test('Announcement and read-history pagers preserve independent current pages and native read-history URLs', async t => {
  const { page, state } = await open(t, { path: '/guidance/announcements?reads=42&page=2&read_page=2' });
  const listNav = page.getByRole('navigation', { name: 'Announcement pages', exact: true });
  const readsNav = page.getByRole('navigation', { name: 'Read history pages', exact: true });
  const listNext = new URL(await listNav.getByRole('link', { name: 'Next', exact: true }).getAttribute('href'));
  assert.equal(listNext.searchParams.get('page'), '3'); assert.equal(listNext.searchParams.get('read_page'), '2'); assert.equal(listNext.searchParams.get('reads'), '42');
  const readPrevious = new URL(await readsNav.getByRole('link', { name: 'Previous', exact: true }).getAttribute('href'));
  assert.equal(readPrevious.searchParams.get('page'), '2'); assert.equal(readPrevious.searchParams.get('read_page'), '1');
  await readsNav.getByRole('link', { name: 'Previous', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('read_page') === '1');
  assert.equal(new URL(page.url()).searchParams.get('page'), '2');
  assert.equal(await region(page, 'list').locator('[data-table-pagination]').getAttribute('data-page'), '2');
  assert.equal(await region(page, 'read-history').locator('[data-table-pagination]').getAttribute('data-page'), '1');
  const updatedListNext = new URL(await listNav.getByRole('link', { name: 'Next', exact: true }).getAttribute('href'));
  assert.equal(updatedListNext.searchParams.get('page'), '3');
  assert.equal(updatedListNext.searchParams.get('read_page'), '1');
  assert.equal(updatedListNext.searchParams.get('reads'), '42');
  await page.goto(updatedListNext.href);
  await page.waitForFunction(() => document.readyState === 'complete' && window.SynapseRefreshRegions);
  assert.equal(await region(page, 'list').locator('[data-table-pagination]').getAttribute('data-page'), '3');
  assert.equal(await region(page, 'read-history').locator('[data-table-pagination]').getAttribute('data-page'), '1');
  const historyHref = new URL(await rows(page).first().getByRole('link', { name: 'Read history', exact: true }).getAttribute('href'));
  assert.equal(historyHref.searchParams.get('page'), '3');
  assert.equal(historyHref.searchParams.get('reads'), '91');
  assert.equal(state.posts.length, 0);
});

for (const theme of ['light', 'dark']) {
  for (const [layout, viewport, path] of [
    ['desktop', { width: 1440, height: 1000 }, listPath],
    ['narrow320', { width: 320, height: 780 }, listPath],
    ['desktop narrow container', { width: 1440, height: 1000 }, listPath + '&container=1'],
  ]) {
    test(`${theme} ${layout}: responsive rows, read ledger and expanded preview stay contained`, async t => {
      const { page } = await open(t, { theme, viewport, path });
      const stacked = layout !== 'desktop';
      const row = await geometry(page, '.ga-announcement-table tbody tr');
      assert.equal(row.display, stacked ? 'grid' : 'table-row');
      if (stacked) assert.equal(row.columns.trim().split(/\s+/).length, 1, '320px/350px containers use one column');
      assert.equal((await geometry(page, '.ga-read-table tbody tr')).display, stacked ? 'grid' : 'table-row');
      const sheet = await geometry(page, '.ga-sheet');
      assert.equal(sheet.background, theme === 'dark' ? 'rgb(28, 28, 28)' : 'rgb(255, 255, 255)');
      const drawer = await geometry(page, '.ga-read-drawer');
      assert.ok(drawer.x >= -1 && drawer.right <= viewport.width + 1, `Drawer fits horizontally: ${JSON.stringify(drawer)}`);
      assert.ok(Math.abs(drawer.y) <= 1);
      await noOverflow(page);
      await page.locator('.ga-read-close').click();
      await page.locator('#ga-read-history').waitFor({ state: 'detached' });
      await rows(page).first().locator('.ga-preview summary').click(); await noOverflow(page);
      const preview = await geometry(page, '.ga-preview-text');
      assert.ok(preview.scrollWidth <= preview.clientWidth + 1);
      assert.ok(row.right <= viewport.width + 1);
    });
  }
  test(`${theme}: empty list and empty read history retain both refresh boundaries and creation`, async t => {
    const { page, state } = await open(t, { theme, viewport: { width: 320, height: 780 }, path: '/guidance/announcements?empty=1&reads=42' });
    assert.match(await region(page, 'list').textContent(), /No announcements yet\./);
    assert.match(await region(page, 'read-history').textContent(), /No read markers yet\./);
    assert.equal(await rows(page).count(), 0);
    await newDraft(page); assert.equal(await modal(page).locator('#announcementTitle').inputValue(), '');
    await noOverflow(page); assert.equal(state.posts.length, 0);
  });
}

for (const theme of ['light', 'dark']) {
  test(`${theme}: fragment modal keeps action association, inset padding, focus trap, cancel/reopen and field limits`, async t => {
    const { page, state } = await open(t, { theme, viewport: { width: 320, height: 780 } });
    const initialUrl = page.url();
    await newDraft(page); assert.equal(page.url(), initialUrl);
    const form = modal(page).locator('form[data-modal-submit]');
    assert.equal(await modal(page).locator('.ga-editor').count(), 1);
    assert.equal(await modal(page).locator('.modal-body .form-actions').count(), 0);
    const save = modal(page).getByRole('button', { name: 'Save draft', exact: true });
    assert.equal(await save.getAttribute('form'), await form.getAttribute('id'));
    assert.equal(await save.evaluate(button => button.form?.matches('form[data-modal-submit]')), true);
    assert.equal(await page.locator('#announcementTitle').evaluate(el => document.activeElement === el), true);
    assert.equal(await page.locator('#announcementTitle').getAttribute('maxlength'), '160');
    assert.equal(await page.locator('#announcementBody').getAttribute('maxlength'), '10000');
    assert.equal(await page.locator('#announcementTitle').getAttribute('required'), '');
    assert.equal(await page.locator('#announcementBody').getAttribute('required'), '');
    assert.equal(await page.locator('#announcementAudience').getAttribute('required'), '');
    assert.equal(await page.locator('#announcementBody').getAttribute('rows'), '8');
    assert.match(await modal(page).textContent(), /Saving creates a draft only/);
    const footer = await geometry(page, '#appFormModal .ga-form-actions');
    const rem = await page.locator('html').evaluate(el => Number.parseFloat(getComputedStyle(el).fontSize));
    assert.ok(footer.padding.every(value => Number.parseFloat(value) >= rem), `Reparented action padding: ${JSON.stringify(footer.padding)}`);
    const content = await geometry(page, '#appFormModal .modal-content');
    assert.ok(footer.x >= content.x + 1 && footer.right <= content.right + 1);
    assert.ok(footer.bottom <= 780 && footer.y >= 0, 'Actions remain visible while the body scrolls');
    const titleRect = await geometry(page, '#announcementTitle');
    assert.ok(titleRect.x >= content.x + rem, 'Zero body padding must retain editor section inset');
    await page.locator('#announcementTitle').fill('Unsaved synthetic title');
    await page.locator('#announcementBody').fill('Unsaved\nplain text');
    await page.keyboard.press('Escape'); assert.equal(await modal(page).isVisible(), true, 'Escape must not dismiss the draft');
    await save.focus(); await page.keyboard.press('Tab');
    assert.equal(await modal(page).getByRole('button', { name: 'Close', exact: true }).evaluate(el => el === document.activeElement), true);
    await page.keyboard.press('Shift+Tab'); assert.equal(await save.evaluate(el => el === document.activeElement), true);
    await modal(page).getByRole('link', { name: 'Cancel', exact: true }).click();
    await modal(page).waitFor({ state: 'detached' });
    assert.equal(await page.getByRole('link', { name: 'New draft', exact: true }).evaluate(el => el === document.activeElement), true);
    await newDraft(page);
    assert.equal(await page.locator('#announcementTitle').inputValue(), ''); assert.equal(await page.locator('#announcementBody').inputValue(), '');
    assert.equal(await page.locator('#announcementTitle').count(), 1);
    assert.equal(await page.locator('.modal-footer .ga-form-actions').count(), 1);
    assert.equal((await geometry(page, '.ga-date-fields')).columns.trim().split(/\s+/).length, 1);
    await noOverflow(page); assert.equal(state.posts.length, 0);
    assert.ok(state.requests.some(request => request.path === '/guidance/announcements/create?fragment=1'));
  });
}

test('Create/edit exact CSRF/version/pinned/audience targeting and Manila hidden timestamp payloads', async t => {
  const { page, state } = await open(t);
  await editFirst(page);
  assert.equal(await page.locator('#announcementTitle').inputValue(), 'Draft <campus> & "advisory"');
  assert.equal(await page.locator('#announcementBody').inputValue(), noticeBody);
  assert.equal(await page.locator('#announcementPinned').isChecked(), true);
  assert.equal(await modal(page).locator('input[name="version"]').inputValue(), '7');
  assert.equal(await page.locator('#announcement_starts_at').getAttribute('type'), 'hidden');
  assert.equal(await page.locator('#announcement_ends_at').getAttribute('type'), 'hidden');
  assert.equal(await page.locator('#announcement_starts_at').inputValue(), '2030-01-31T08:30');
  assert.equal(await page.locator('#announcement_ends_at').inputValue(), '2030-02-03T23:59');
  assert.match(await page.locator('#announcement_starts_at_display').inputValue(), /31\/01\/2030.*08:30.*AM/i);
  assert.match(await page.locator('#announcement_ends_at_display').inputValue(), /03\/02\/2030.*11:59.*PM/i);
  for (const field of ['starts_at', 'ends_at']) assert.equal(await page.locator(`#announcement_${field}_display`).getAttribute('name'), null);
  assert.equal(await page.locator('#announcement_ends_at_display').getAttribute('data-default-hours'), '23');
  assert.equal(await page.locator('#announcement_ends_at_display').getAttribute('data-default-minutes'), '59');
  const targets = modal(page).locator('input[name="target_year_levels[]"],input[name="target_student_categories[]"]');
  assert.equal(await targets.count(), 10);
  assert.ok((await targets.evaluateAll(inputs => inputs.map(input => input.disabled))).every(disabled => !disabled));
  await chooseAudience(page, 'Employees (including staff self-service)');
  assert.ok((await targets.evaluateAll(inputs => inputs.map(input => input.disabled))).every(Boolean));
  await chooseAudience(page, 'Students');
  assert.equal(await page.locator('#announcement_target_year_levels_1').isChecked(), true);
  assert.equal(await page.locator('#announcement_target_student_categories_graduating').isChecked(), true);
  await page.locator('#announcementTitle').fill('Edited synthetic draft');
  await page.locator('#announcementBody').fill('Edited <body> & text\nSecond line');
  await modal(page).getByRole('button', { name: 'Save draft', exact: true }).click();
  await refreshed(page);
  assert.equal(state.posts.length, 1); assert.equal(state.posts[0].path, '/guidance/announcements/41/update');
  assert.equal(state.posts[0].headers['x-synapse-refresh-owner'], 'announcements.staff.guidance');
  assert.deepEqual(sorted(state.posts[0].fields), sorted([
    ['fixture_csrf', 'synthetic-token-0'], ['version', '7'], ['title', 'Edited synthetic draft'], ['body', 'Edited <body> & text\r\nSecond line'],
    ['audience', 'students'], ['is_pinned_advisory', '1'], ['target_year_levels[]', '1'], ['target_year_levels[]', '3'],
    ['target_student_categories[]', 'new'], ['target_student_categories[]', 'graduating'], ['starts_at', '2030-01-31T08:30'], ['ends_at', '2030-02-03T23:59'],
  ]));
  await newDraft(page);
  assert.equal(await modal(page).locator('input[name="version"]').count(), 0);
  assert.equal(await page.locator('#announcementPinned').isChecked(), false);
  assert.ok((await targets.evaluateAll(inputs => inputs.map(input => input.disabled))).every(Boolean));
  await chooseAudience(page, 'Students');
  await page.locator('#announcement_target_year_levels_6').check();
  await page.locator('#announcement_target_student_categories_graduating').check();
  await chooseAudience(page, 'All students and employees');
  await page.locator('#announcementTitle').fill('New synthetic draft'); await page.locator('#announcementBody').fill('New plain text');
  await modal(page).getByRole('button', { name: 'Save draft', exact: true }).click();
  await refreshed(page, 2); assert.equal(state.posts.length, 2); assert.equal(state.posts[1].path, '/guidance/announcements/store');
  assert.deepEqual(sorted(state.posts[1].fields), sorted([
    ['fixture_csrf', 'synthetic-token-1'], ['title', 'New synthetic draft'], ['body', 'New plain text'], ['audience', 'all'], ['starts_at', ''], ['ends_at', ''],
  ]));
  await csrfAll(page, 'synthetic-token-2');
});

test('Synthetic 422 then 409 retain unsaved edits, targeting, version and rotated CSRF before one successful save', async t => {
  const { page, state } = await open(t, { statuses: [422, 409, 200] });
  const currentUrl = page.url();
  await editFirst(page);
  await page.locator('#announcementTitle').fill('Retain my edits'); await page.locator('#announcementBody').fill('My <plain>\nmultiline edits');
  for (const [index, message] of ['Synthetic validation:', 'Synthetic conflict:'].entries()) {
    await modal(page).getByRole('button', { name: 'Save draft', exact: true }).click();
    await modal(page).locator('[data-modal-error]:not(.d-none)').waitFor();
    await page.waitForFunction(() => !document.querySelector('#appFormModal form').hasAttribute('aria-busy'));
    assert.match(await modal(page).locator('[data-modal-error]').textContent(), new RegExp(message));
    assert.equal(await page.locator('#announcementTitle').inputValue(), 'Retain my edits');
    assert.equal(await page.locator('#announcementBody').inputValue(), 'My <plain>\nmultiline edits');
    assert.equal(await modal(page).locator('input[name="version"]').inputValue(), '7');
    assert.equal(await page.locator('#announcementPinned').isChecked(), true);
    assert.equal(await page.locator('#announcement_target_year_levels_3').isChecked(), true);
    assert.equal(await page.locator('#announcement_target_year_levels_3').isDisabled(), false);
    assert.equal(await modal(page).getByRole('button', { name: 'Save draft', exact: true }).isDisabled(), false);
    assert.equal(await modal(page).locator('form').evaluate(form => form.inert), false);
    await csrfAll(page, `synthetic-token-${index + 1}`);
    assert.equal(state.posts.length, index + 1); assert.equal(page.url(), currentUrl);
    assert.equal(await page.evaluate(() => window.fixtureRefreshEvents.length), 0);
  }
  await modal(page).getByRole('button', { name: 'Save draft', exact: true }).click(); await refreshed(page);
  assert.equal(state.posts.length, 3); assert.deepEqual(state.posts.map(post => post.fields.find(([name]) => name === 'fixture_csrf')[1]), ['synthetic-token-0', 'synthetic-token-1', 'synthetic-token-2']);
  assert.equal(page.url(), currentUrl); assert.equal(await modal(page).count(), 0);
  assert.equal(await rows(page).first().locator('.ga-announcement-title').textContent(), 'Retain my edits');
});

test('Confirmation cancel sends no POST; publish/archive acceptance refresh both regions without replacing current URL/owner', async t => {
  const { page, state } = await open(t, { path: '/guidance/announcements?reads=42&page=1&read_page=2' });
  const currentUrl = page.url();
  const main = await page.locator('#main').elementHandle();
  const owner = await page.locator('.ga-workspace').elementHandle();
  const list = await region(page, 'list').elementHandle();
  const reads = await region(page, 'read-history').elementHandle();
  const oldListChild = await region(page, 'list').locator('> :first-child').elementHandle();
  const oldReadChild = await region(page, 'read-history').locator('> :first-child').elementHandle();
  await rows(page).first().getByRole('button', { name: 'Publish', exact: true }).click();
  const confirm = page.locator('#synapseConfirmModal');
  await confirm.waitFor({ state: 'visible' });
  assert.match(await confirm.locator('[data-confirm-message]').textContent(), /cannot be edited afterward/);
  assert.equal(state.posts.length, 0);
  await confirm.getByRole('button', { name: 'Cancel', exact: true }).click(); await confirm.waitFor({ state: 'hidden' });
  assert.equal(state.posts.length, 0);
  await rows(page).first().getByRole('button', { name: 'Publish', exact: true }).click();
  await confirm.getByRole('button', { name: 'Publish announcement', exact: true }).click(); await refreshed(page);
  assert.equal(state.posts.length, 1); assert.equal(state.posts[0].path, '/guidance/announcements/41/publish');
  assert.deepEqual(sorted(state.posts[0].fields), sorted([['fixture_csrf', 'synthetic-token-0'], ['version', '7']]));
  assert.equal(await rows(page).first().locator('.ga-status').textContent(), 'Published');
  assert.equal(page.url(), currentUrl);
  assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);
  assert.equal(await owner.evaluate(el => el === document.querySelector('.ga-workspace')), true);
  assert.equal(await list.evaluate(el => el === document.querySelector('[data-refresh-region="announcements.list"]')), true);
  assert.equal(await reads.evaluate(el => el === document.querySelector('[data-refresh-region="announcements.read-history"]')), true);
  assert.equal(await oldListChild.evaluate(el => el.isConnected), false); assert.equal(await oldReadChild.evaluate(el => el.isConnected), false);
  assert.equal(await region(page, 'read-history').locator('[data-table-pagination]').getAttribute('data-page'), '2');
  const gets = state.requests.filter(request => request.method === 'GET' && !request.path.includes('fragment=1'));
  assert.equal(gets.length, 2); assert.equal(gets[1].path, new URL(currentUrl).pathname + new URL(currentUrl).search);
  await rows(page).first().getByRole('button', { name: 'Archive', exact: true }).click();
  await confirm.getByRole('button', { name: 'Archive announcement', exact: true }).click(); await refreshed(page, 2);
  assert.equal(state.posts.length, 2); assert.equal(state.posts[1].path, '/guidance/announcements/41/archive');
  assert.deepEqual(sorted(state.posts[1].fields), sorted([['fixture_csrf', 'synthetic-token-1'], ['version', '8']]));
  assert.equal(await rows(page).first().locator('.ga-status').textContent(), 'Archived');
  assert.equal(await rows(page).first().getByRole('button').count(), 0);
  assert.equal(page.url(), currentUrl); await csrfAll(page, 'synthetic-token-2');
});

test('Direct create/edit route initializes the actual modal, loads Guidance CSS and cancels back to the list', async t => {
  const { page, state } = await open(t, { theme: 'dark', path: '/guidance/announcements/41/edit', viewport: { width: 320, height: 780 } });
  assert.equal(await modal(page).getAttribute('data-modal-direct'), 'true');
  assert.equal(await page.locator('link[href$="guidance-announcements.css"]').count(), 1);
  assert.equal(await modal(page).locator('#announcementTitle').inputValue(), 'Draft <campus> & "advisory"');
  assert.equal(await page.locator('.modal-footer .ga-form-actions').count(), 1);
  await page.locator('#announcementTitle').fill('Direct-route unsaved edits');
  await modal(page).getByRole('link', { name: 'Cancel', exact: true }).click();
  await page.waitForURL(harness.origin + '/guidance/announcements');
  await newDraft(page);
  assert.equal(await page.locator('#announcementTitle').inputValue(), '');
  assert.equal(state.posts.length, 0); await noOverflow(page);
});

test('Clinic uses the shared redesigned announcement workspace and modal editor with Clinic copy and action contracts', async t => {
  const { page, state } = await open(t, { path: '/clinic/announcements?reads=42', theme: 'dark' });
  assert.equal(await page.locator('[data-refresh-owner="announcements.staff.clinic"]').count(), 1);
  assert.equal(await page.locator('.ga-workspace').count(), 1);
  assert.equal(await page.locator('.ga-announcement-table').count(), 1);
  assert.equal(await page.locator('link[href$="guidance-announcements.css"]').count(), 1);
  assert.match(await page.locator('.ga-intro .ga-intro-desc').textContent(), /Prepare Clinic notices/);
  assert.match(await page.locator('.ga-read-history').textContent(), /Published campus notice/);
  const firstRow = rows(page).first();
  assert.equal(await firstRow.getByRole('button', { name: 'Publish', exact: true }).count(), 1);
  assert.equal(await firstRow.locator('form[action$="/publish"] input[name="version"]').inputValue(), '7');
  await firstRow.getByRole('link', { name: 'Edit draft', exact: true }).click(); await editorReady(page);
  assert.equal(await modal(page).locator('.ga-editor').count(), 1);
  assert.match(await modal(page).locator('.ga-editor-intro').textContent(), /Clinic announcement/);
  assert.match(await modal(page).locator('#announcementPinnedHelp').textContent(), /Clinic medical exam schedules/);
  assert.equal(await page.locator('#announcementTitle').getAttribute('maxlength'), '160');
  assert.equal(await page.locator('#announcementBody').getAttribute('maxlength'), '10000');
  assert.equal(await page.locator('#announcement_starts_at').inputValue(), '2030-01-31T08:30');
  assert.equal(await page.locator('#announcementPinned').isChecked(), true);
  assert.equal(await modal(page).locator('form').getAttribute('action'), harness.origin + '/clinic/announcements/41/update');
  assert.equal(await modal(page).locator('.modal-footer .ga-form-actions').count(), 1);
  await modal(page).getByRole('link', { name: 'Cancel', exact: true }).click(); await modal(page).waitFor({ state: 'detached' });
  await noOverflow(page);
  assert.equal(state.posts.length, 0);
});

for (const unit of ['guidance', 'clinic']) {
  test(`${unit}: live search, maroon status tabs, custom audience/pinned dropdowns and reset update in-place without reloading #main`, async t => {
    const { page, state } = await open(t, { path: `/${unit}/announcements`, theme: unit === 'clinic' ? 'dark' : 'light' });
    const main = await page.locator('#main').elementHandle();
    const form = await page.locator('[data-filter-form]').elementHandle();

    // Custom BalaySelect upgraded both filter selects
    assert.equal(await page.locator('#ga-announcement-audience').getAttribute('data-bd-select'), '1');
    assert.equal(await page.locator('#ga-announcement-pinned').getAttribute('data-bd-select'), '1');

    // Active status tab is solid maroon (#800000 -> rgb(128, 0, 0)) with white text
    const activeTabStyle = await page.locator('.ga-status-tab.is-active').evaluate(el => {
      const s = getComputedStyle(el);
      return { bg: s.backgroundColor, color: s.color };
    });
    assert.deepEqual(activeTabStyle, { bg: 'rgb(128, 0, 0)', color: 'rgb(255, 255, 255)' });

    // Switch status tab in-place to Published
    await page.locator('.ga-status-tabs').getByRole('link', { name: /Published/ }).click();
    await page.waitForFunction(() => new URL(location.href).searchParams.get('status') === 'published' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);
    assert.equal(await form.evaluate(el => el === document.querySelector('[data-filter-form]')), true);
    assert.ok((await rows(page).locator('.ga-status').allTextContents()).every(text => text === 'Published'));

    // Filter by Pinned advisories only via custom dropdown after resetting status to All
    await page.locator('.ga-status-tabs').getByRole('link', { name: /All/ }).click();
    await page.waitForFunction(() => new URL(location.href).searchParams.get('status') === 'all' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    const pinnedWrap = page.locator('#ga-announcement-pinned').locator('..');
    await pinnedWrap.locator('.bd-select-toggle').click();
    await pinnedWrap.getByRole('option', { name: 'Pinned advisories only', exact: true }).click();
    await page.waitForFunction(() => new URL(location.href).searchParams.get('pinned') === '1' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await rows(page).count(), 1);
    assert.equal(await rows(page).first().locator('.ga-announcement-title').textContent(), 'Draft <campus> & "advisory"');

    // Search with no matches shows filtered empty state + Reset filters restores full library
    await page.locator('#ga-announcement-search').fill('NoSuchAnnouncementTerm999');
    await page.waitForFunction(() => new URL(location.href).searchParams.get('q') === 'NoSuchAnnouncementTerm999' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.match(await region(page, 'list').textContent(), /No matching announcements\./);
    await region(page, 'list').getByRole('link', { name: 'Reset filters', exact: true }).click();
    await page.waitForFunction(() => !new URL(location.href).searchParams.get('q') &&
      !new URL(location.href).searchParams.get('pinned') &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await rows(page).count(), 25);
    assert.equal(await page.locator('#ga-announcement-search').inputValue(), '');
    assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);
    assert.equal(state.posts.length, 0);
    await noOverflow(page);
  });

  test(`${unit}: Read history opens as right slide-over drawer without shrinking list, focuses heading, and closes via close button, backdrop, and Escape`, async t => {
    const { page, state } = await open(t, { path: `/${unit}/announcements`, theme: unit === 'clinic' ? 'dark' : 'light' });
    const main = await page.locator('#main').elementHandle();
    const form = await page.locator('[data-filter-form]').elementHandle();
    const listBefore = await page.locator('[data-refresh-region="announcements.list"]').boundingBox();
    assert.equal(await page.locator('.ga-read-history-slot').evaluate(el => getComputedStyle(el).display), 'none');

    const publishedRow = rows(page).nth(1);
    const readLink = publishedRow.getByRole('link', { name: 'Read history', exact: true });
    await readLink.click();
    await page.waitForFunction(() => new URL(location.href).searchParams.get('reads') === '42' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);
    assert.equal(await form.evaluate(el => el === document.querySelector('[data-filter-form]')), true);
    assert.equal(await page.locator('#ga-read-history').count(), 1);
    assert.equal(await page.locator('.ga-read-backdrop').isVisible(), true);
    assert.equal(await page.locator('.ga-read-count-tag').textContent(), '55');
    assert.match(await page.locator('#ga-read-history').textContent(), /Published campus notice/);
    assert.equal(await publishedRow.getByRole('link', { name: 'Read history', exact: true }).getAttribute('aria-current'), 'true');
    assert.equal(await publishedRow.getByRole('link', { name: 'Read history', exact: true }).evaluate(el => el.classList.contains('is-active')), true);
    assert.equal(await page.locator('#ga-read-title').evaluate(el => document.activeElement === el), true);

    const listAfter = await page.locator('[data-refresh-region="announcements.list"]').boundingBox();
    assert.ok(Math.abs(listAfter.width - listBefore.width) <= 2, 'List width does not shrink when slide-over drawer opens');
    const drawerState = await page.locator('#ga-read-history').evaluate(el => ({
      position: getComputedStyle(el).position,
      rect: el.getBoundingClientRect(),
      viewportWidth: window.innerWidth,
    }));
    assert.equal(drawerState.position, 'fixed');
    assert.ok(Math.abs((drawerState.rect.x + drawerState.rect.width) - drawerState.viewportWidth) <= 2, 'Drawer aligns to right edge');
    assert.ok(Math.abs(drawerState.rect.y) <= 2, 'Drawer spans from top edge');
    assert.ok(drawerState.rect.width >= 430 && drawerState.rect.width <= 445, `Expected ~440px drawer, got ${drawerState.rect.width}`);
    await noOverflow(page);

    // 1. Close via close button
    await page.locator('.ga-read-close').click();
    await page.waitForFunction(() => !new URL(location.href).searchParams.has('reads') &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await page.locator('#ga-read-history').count(), 0);
    assert.equal(await page.locator('.ga-read-backdrop').count(), 0);
    assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);

    // 2. Reopen and close via backdrop click
    await rows(page).nth(1).getByRole('link', { name: 'Read history', exact: true }).click();
    await page.waitForFunction(() => new URL(location.href).searchParams.get('reads') === '42' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    await page.locator('.ga-read-backdrop').click({ position: { x: 100, y: 200 } });
    await page.waitForFunction(() => !new URL(location.href).searchParams.has('reads') &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await page.locator('#ga-read-history').count(), 0);

    // 3. Reopen and close via Escape
    await rows(page).nth(1).getByRole('link', { name: 'Read history', exact: true }).click();
    await page.waitForFunction(() => new URL(location.href).searchParams.get('reads') === '42' &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !new URL(location.href).searchParams.has('reads') &&
      !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
    assert.equal(await page.locator('#ga-read-history').count(), 0);
    assert.equal(await main.evaluate(el => el === document.querySelector('#main')), true);
    assert.equal(state.posts.length, 0);
  });
}

test('Read history drawer plays slide-out closing animation before unmounting', async t => {
  const { page } = await open(t, { path: '/guidance/announcements', reducedMotion: 'no-preference' });
  await rows(page).nth(1).getByRole('link', { name: 'Read history', exact: true }).click();
  await page.waitForFunction(() => new URL(location.href).searchParams.get('reads') === '42' &&
    !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));

  await page.evaluate(() => {
    const slot = document.querySelector('.ga-read-history-slot');
    window.closingObserved = null;
    const observer = new MutationObserver(() => {
      if (slot.classList.contains('is-closing') && !window.closingObserved) {
        const drawer = document.querySelector('#ga-read-history');
        const backdrop = document.querySelector('.ga-read-backdrop');
        window.closingObserved = {
          drawerAnim: drawer ? getComputedStyle(drawer).animationName : null,
          backdropAnim: backdrop ? getComputedStyle(backdrop).animationName : null,
        };
        observer.disconnect();
      }
    });
    observer.observe(slot, { attributes: true, attributeFilter: ['class'] });
  });

  await page.locator('.ga-read-close').click();
  await page.waitForFunction(() => !new URL(location.href).searchParams.has('reads') &&
    !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));

  const closing = await page.evaluate(() => ({
    observed: window.closingObserved,
    slotClosing: document.querySelector('.ga-read-history-slot').classList.contains('is-closing'),
    drawerCount: document.querySelectorAll('#ga-read-history').length,
  }));
  assert.equal(closing.observed?.drawerAnim, 'gaReadDrawerOut');
  assert.equal(closing.observed?.backdropAnim, 'gaReadBackdropOut');
  assert.equal(closing.slotClosing, false);
  assert.equal(closing.drawerCount, 0);

  await rows(page).nth(1).getByRole('link', { name: 'Read history', exact: true }).click();
  await page.waitForFunction(() => new URL(location.href).searchParams.get('reads') === '42' &&
    !document.querySelector('[data-live-filters]').hasAttribute('aria-busy'));
  assert.equal(await page.locator('#ga-read-history').isVisible(), true);
});
