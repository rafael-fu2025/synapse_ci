'use strict';

// Actual MI registry and shared navigation/widgets; invented assignments stay in RAM.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test, before, after } = require('node:test');
const fixture = require('../_support/guidance-mi-registry-browser-harness.cjs');
const evidence = path.resolve(__dirname, '../../writable/guidance-mi-registry-preview');
const listPath = '/guidance/assessments/mi';
const assignPath = listPath + '/assign';
const rootSelector = 'section.gmi-workspace';
const assignForm = page => page.locator('form[data-mi-assignment-form]');
const assignmentRows = page => page.locator('.gmi-table tbody .gmi-assignment-row');
let harness, browser;

before(async () => {
  harness = await fixture.startServer();
  fs.mkdirSync(evidence, { recursive: true });
  const { chromium } = fixture.localPlaywright();
  browser = await chromium.launch({ headless: true, executablePath: fixture.installedChromium(chromium) });
});
after(async () => {
  await browser?.close();
  await harness?.close();
  if (harness) {
    console.log('MI fixture totals: ' + JSON.stringify(harness.counts));
    assert.deepEqual(harness.violations, []);
  }
});

async function screenshot(page, name) {
  const file = path.join(evidence, name + '.png');
  await page.screenshot({ path: file, animations: 'disabled', timeout: 15000 });
  console.log('MI screenshot: ' + file);
}
async function open(t, options = {}) {
  const state = harness.createScenario(options);
  const context = await browser.newContext({ viewport: options.viewport || { width: 1440, height: 1000 }, colorScheme: options.theme || 'light', reducedMotion: 'reduce', serviceWorkers: 'block' });
  await context.addCookies([{ name: fixture.cookieName, value: state.id, url: harness.origin }]);
  const errors = [], denied = [];
  await context.route('**/*', route => {
    const request = route.request();
    if (fixture.allowedRequest(harness.origin, state, request.method(), request.url())) return route.continue();
    denied.push(request.url());
    return route.abort('blockedbyclient');
  });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => { errors.push('Unexpected native dialog: ' + dialog.message()); void dialog.dismiss(); });
  t.after(async () => {
    if (!page.isClosed()) await context.close();
    assert.deepEqual(errors, [], 'Shared widgets and page script must not throw');
    assert.deepEqual(denied, [], 'No external or unrelated requests');
  });
  await page.goto(harness.origin + listPath, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await ready(page);
  return { page, state };
}
async function ready(page) {
  await page.waitForFunction(() => window.SynapseNavigation && window.SynapseMutations && window.SynapseMiRegistry && window.BalaySelect &&
    document.querySelector('#mi-filter-program')?.closest('.bd-select') &&
    (!document.querySelector('[data-mi-assignment-form]') || document.querySelector('[data-mi-assignment-form]').dataset.miAssignBound === '1'));
}
async function choose(page, id, value) {
  const source = page.locator(id);
  const label = await source.locator('option').evaluateAll((options, value) => options.find(option => option.value === value)?.label.replace(/\s+/g, ' ').trim(), String(value));
  assert.ok(label, 'Only choose options present in the real select');
  const trigger = source.locator('..').getByRole('combobox');
  await trigger.click();
  const menuId = await trigger.getAttribute('aria-controls');
  const menu = page.locator('[id="' + menuId + '"]');
  await menu.getByRole('option', { name: label, exact: true }).click();
  assert.equal(await source.inputValue(), String(value));
}
async function postOnce(page, state) {
  const before = state.posts.length;
  const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === assignPath);
  await assignForm(page).getByRole('button', { name: 'Assign MI Survey', exact: true }).click();
  const result = await response;
  const payload = await result.json();
  assert.equal(state.posts.length, before + 1);
  return { result, payload, post: state.posts.at(-1) };
}
async function saved(page, identity) {
  await page.waitForFunction(form => !form.isConnected, identity);
  await ready(page);
  assert.equal(await page.locator('#mi-selected-count').textContent(), '0');
  assert.equal(await page.locator('#mi-academic-term').inputValue(), '');
  assert.equal(await page.locator('#mi-scope-individual').isChecked(), true);
}
async function noOverflow(page) {
  const sizes = await page.locator(rootSelector).evaluate(root => ({
    viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth,
    offenders: [root, ...root.querySelectorAll('.gmi-ledger,.gmi-table,.gmi-filter-form,.gmi-assignment-form,.gmi-person-name,.gmi-check-option,.gmi-meta,.ce-tag,td')]
      .filter(node => node.clientWidth > 0 && node.scrollWidth > node.clientWidth + 1)
      .map(node => ({ className: node.className, label: node.dataset.label, width: node.clientWidth, scroll: node.scrollWidth })).slice(0, 12),
  }));
  if (sizes.offenders.length || sizes.document > sizes.viewport + 1) await screenshot(page, 'overflow-' + page.viewportSize().width + '-' + await page.locator('html').getAttribute('data-theme'));
  assert.ok(sizes.document <= sizes.viewport + 1, JSON.stringify(sizes));
  assert.deepEqual(sizes.offenders, [], JSON.stringify(sizes));
}

test('Contract: actual registry preserves assignment fields, policy, pagination and results-only links', () => {
  const data = fixture.fixtureData({ miGateEnabled: false });
  const html = fixture.renderFixture({ ...data, origin: 'http://127.0.0.1:12345' });
  for (const contract of ['data-mi-assignment-form', 'data-background-submit', 'mi_reproduction_signoff', 'mi-selected-count', 'mi-student-checklist-search', 'mi-select-visible-btn', 'mi-clear-selected-btn', 'name="person_ids[]"', 'name="target_programs[]"', 'name="target_year_levels[]"', 'name="assign_all_students"', 'name="assign_scope"', 'name="academic_term"', 'name="academic_year"', 'name="due_at"', 'name="per_page"', 'on this page', '8-Domain Profile']) assert.ok(html.includes(contract), contract);
  assert.ok(!html.includes('data-refresh-owner'));
  assert.ok(!html.includes('position: sticky'));
  const readonly = fixture.renderFixture({ ...fixture.fixtureData({ canAssign: false }), origin: 'http://127.0.0.1:12345' });
  assert.ok(!readonly.includes('data-mi-assignment-form'));
  assert.ok(readonly.includes('Read-only registry'));
  const empty = fixture.renderFixture({ ...fixture.fixtureData({ empty: true }), origin: 'http://127.0.0.1:12345' });
  assert.ok(empty.includes('No Multiple Intelligence survey assignments found.'));
  assert.throws(() => fixture.renderFixture({ origin: 'https://example.com' }));
});

test('Workflow: program/status/page-size GET filtering and pagination preserve query contracts', { timeout: 60000 }, async t => {
  const { page, state } = await open(t);
  assert.equal(await assignmentRows(page).count(), 25);
  await choose(page, '#mi-filter-per-page', 20);
  await page.getByRole('button', { name: 'Filter', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('per_page') === '20');
  await ready(page);
  assert.equal(await assignmentRows(page).count(), 20);
  const paginator = page.getByRole('navigation', { name: 'Multiple Intelligence assignment pages' });
  await paginator.getByRole('link', { name: 'Next', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('page') === '2');
  await ready(page);
  assert.equal(await page.locator('#mi-filter-per-page').inputValue(), '20');
  await choose(page, '#mi-filter-program', 'BS Nursing');
  await choose(page, '#mi-filter-status', 'completed');
  await page.getByRole('button', { name: 'Filter', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('program') === 'BS Nursing' && url.searchParams.get('status') === 'completed' && !url.searchParams.has('page'));
  await ready(page);
  assert.ok(await assignmentRows(page).count() > 0);
  for (const row of await assignmentRows(page).all()) {
    assert.ok((await row.textContent()).includes('BS Nursing'));
    assert.equal((await row.locator('td[data-label="Status"]').textContent()).trim(), 'Completed');
    assert.equal(await row.getByRole('link', { name: '8-Domain Profile', exact: true }).count(), 1);
  }
  assert.equal(await page.locator('#header').isVisible(), true);
  assert.equal(await page.locator('#mi-filter-program').inputValue(), 'BS Nursing');
  assert.equal(await page.locator('#mi-filter-status').inputValue(), 'completed');
  const pagination = await page.locator('[data-table-pagination]').getAttribute('data-filtered');
  assert.equal(Number(pagination), await assignmentRows(page).count());
  const grouped = await page.locator('.gmi-program-group').textContent();
  assert.ok(grouped.includes(await assignmentRows(page).count() + ' on this page'));
  assert.equal(state.posts.length, 0);
  await screenshot(page, 'filters-completed-nursing-1440-light');
});

test('Workflow: checklist search/select-visible/clear, mode preservation and dirty-navigation guard', { timeout: 60000 }, async t => {
  const { page, state } = await open(t);
  const search = page.locator('#mi-student-checklist-search');
  await search.fill('no-synthetic-match');
  await page.getByText('No students match your filter.', { exact: true }).waitFor({ state: 'visible' });
  await search.fill('BS Nursing');
  const visible = page.locator('.mi-student-check-row:not(.d-none) .mi-student-checkbox');
  const count = await visible.count();
  assert.ok(count > 0);
  await page.getByRole('button', { name: 'Select all visible', exact: true }).click();
  assert.equal(await page.locator('#mi-selected-count').textContent(), String(count));
  assert.equal(await assignForm(page).locator('.mi-student-checkbox:checked').count(), count);
  await page.getByRole('link', { name: 'Guidance Hub', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Unsaved changes', exact: true });
  await dialog.waitFor({ state: 'visible' });
  await screenshot(page, 'selection-unsaved-guard-1440-light');
  await dialog.getByRole('button', { name: 'Stay on page', exact: true }).click();
  await dialog.waitFor({ state: 'hidden' });
  await page.locator('label[for="mi-scope-cohort"]').click();
  assert.equal(await search.isEnabled(), false);
  assert.equal(await page.locator('#mi-assign-all-students').isEnabled(), true);
  assert.equal(await assignForm(page).locator('.mi-student-checkbox:checked:enabled').count(), 0);
  await page.locator('label[for="mi-scope-individual"]').click();
  assert.equal(await search.isEnabled(), true);
  assert.equal(await page.locator('#mi-selected-count').textContent(), String(count));
  await page.getByRole('button', { name: 'Clear', exact: true }).click();
  assert.equal(await page.locator('#mi-selected-count').textContent(), '0');
  assert.equal(state.posts.length, 0);
  await screenshot(page, 'checklist-filter-clear-1440-light');
});

test('Workflow: individual assignment retry, due-date ISO, field limits, CSRF and one POST', { timeout: 70000 }, async t => {
  const { page, state } = await open(t, { postStatuses: [422, 201], postDelayMs: 600 });
  const candidate = page.locator('.mi-student-check-row').filter({ hasNot: page.locator('.gmi-active-assignment') }).first();
  const checkbox = candidate.locator('.mi-student-checkbox');
  const personId = await checkbox.inputValue();
  await checkbox.check();
  await page.locator('#mi-academic-term').fill('SYN-TERM-REGISTRY');
  await page.locator('#mi-academic-year').fill('SYN-2031-2032');
  assert.equal(await page.locator('#mi-academic-term').getAttribute('maxlength'), '64');
  assert.equal(await page.locator('#mi-academic-year').getAttribute('maxlength'), '32');
  await page.locator('#mi-due-at-display').fill('31/01/2099');
  await page.locator('#mi-academic-term').click();
  assert.equal(await page.locator('#mi-due-at').inputValue(), '2099-01-31');
  const first = await postOnce(page, state);
  assert.equal(first.result.status(), 422);
  await page.waitForFunction(() => !document.querySelector('[data-mi-assignment-form]').hasAttribute('aria-busy'));
  assert.equal(await checkbox.isChecked(), true);
  assert.equal(await page.locator('#mi-academic-term').inputValue(), 'SYN-TERM-REGISTRY');
  assert.equal(await assignForm(page).locator('input[name="fixture_csrf"]').inputValue(), first.payload.csrf.hash);
  await screenshot(page, 'individual-422-retry-1440-light');
  const original = await assignForm(page).elementHandle();
  const second = await postOnce(page, state);
  assert.equal(second.result.status(), 201);
  assert.equal(second.payload.assigned_count, 1);
  assert.equal(second.payload.skipped_active_count, 0);
  const fields = new URLSearchParams(second.post.fields);
  assert.equal(fields.get('fixture_csrf'), first.payload.csrf.hash);
  assert.deepEqual(fields.getAll('person_ids[]'), [personId]);
  assert.equal(fields.get('due_at'), '2099-01-31');
  assert.equal(fields.get('academic_term'), 'SYN-TERM-REGISTRY');
  assert.equal(fields.get('academic_year'), 'SYN-2031-2032');
  assert.equal(fields.has('target_programs[]'), false);
  assert.equal(fields.has('assign_all_students'), false);
  await saved(page, original);
  assert.equal(state.posts.length, 2);
  await screenshot(page, 'individual-assigned-1440-light');
});

test('Workflow: single-active conflict, multi-student active skips and gate-pending assignments', { timeout: 70000 }, async t => {
  const { page, state } = await open(t, { miGateEnabled: false });
  await page.getByText('mi_reproduction_signoff', { exact: true }).waitFor({ state: 'visible' });
  const active = page.locator('.mi-student-check-row').filter({ has: page.locator('.gmi-active-assignment') }).first();
  await active.locator('.mi-student-checkbox').check();
  let result = await postOnce(page, state);
  assert.equal(result.result.status(), 409);
  await page.waitForFunction(() => !document.querySelector('[data-mi-assignment-form]').hasAttribute('aria-busy'));
  const available = page.locator('.mi-student-check-row').filter({ hasNot: page.locator('.gmi-active-assignment') }).first();
  await available.locator('.mi-student-checkbox').check();
  const original = await assignForm(page).elementHandle();
  result = await postOnce(page, state);
  assert.equal(result.result.status(), 201);
  assert.equal(result.payload.assigned_count, 1);
  assert.equal(result.payload.skipped_active_count, 1);
  await saved(page, original);
  await screenshot(page, 'gate-pending-batch-skips-1440-light');
});

test('Workflow: program/year cohort intersection, All Active overrides and disabled serialization', { timeout: 70000 }, async t => {
  const { page, state } = await open(t);
  await page.locator('label[for="mi-scope-cohort"]').click();
  await page.locator('#mi-assign-cohort-panel').getByRole('checkbox', { name: 'BS Nursing', exact: true }).check();
  await page.locator('#mi-assign-cohort-panel').getByRole('checkbox', { name: 'Year 2', exact: true }).check();
  let original = await assignForm(page).elementHandle();
  let result = await postOnce(page, state);
  assert.equal(result.result.status(), 201);
  let fields = new URLSearchParams(result.post.fields);
  assert.deepEqual(fields.getAll('target_programs[]'), ['BS Nursing']);
  assert.deepEqual(fields.getAll('target_year_levels[]'), ['2']);
  assert.equal(fields.has('person_ids[]'), false);
  assert.equal(fields.has('assign_all_students'), false);
  assert.ok(result.payload.assigned_count + result.payload.skipped_active_count > 0);
  await saved(page, original);
  await page.locator('label[for="mi-scope-cohort"]').click();
  await page.locator('#mi-assign-cohort-panel').getByRole('checkbox', { name: 'BS Nursing', exact: true }).check();
  await page.locator('#mi-assign-all-students').check();
  assert.equal(await page.locator('.mi-cohort-filter-input:enabled').count(), 0);
  original = await assignForm(page).elementHandle();
  result = await postOnce(page, state);
  assert.equal(result.result.status(), 201);
  fields = new URLSearchParams(result.post.fields);
  assert.equal(fields.get('assign_scope'), 'cohort');
  assert.equal(fields.get('assign_all_students'), '1');
  assert.equal(fields.has('target_programs[]'), false);
  assert.equal(fields.has('target_year_levels[]'), false);
  assert.equal(fields.has('person_ids[]'), false);
  assert.ok(result.payload.assigned_count > 0);
  await saved(page, original);
  await screenshot(page, 'cohort-all-students-assigned-1440-light');
});

test('Workflow: results and PDF anchors reach only completed synthetic destinations', { timeout: 60000 }, async t => {
  const { page, state } = await open(t);
  const row = assignmentRows(page).filter({ has: page.getByRole('link', { name: '8-Domain Profile', exact: true }) }).first();
  const profile = row.getByRole('link', { name: '8-Domain Profile', exact: true });
  const pdf = row.getByRole('link', { name: 'PDF', exact: true });
  const pdfUrl = await pdf.getAttribute('href');
  assert.equal(await pdf.getAttribute('target'), '_blank');
  assert.equal(await pdf.getAttribute('rel'), 'noopener');
  assert.equal(await pdf.getAttribute('data-navigation'), 'false');
  const popupPromise = page.waitForEvent('popup');
  await pdf.click();
  const popup = await popupPromise;
  await popup.waitForLoadState('domcontentloaded');
  assert.equal(popup.url(), pdfUrl);
  assert.ok((await popup.locator('body').textContent()).toLowerCase().includes('synthetic'));
  await screenshot(popup, 'pdf-synthetic-destination');
  await popup.close();
  const profileUrl = await profile.getAttribute('href');
  await profile.click();
  await page.waitForURL(profileUrl);
  assert.ok((await page.locator('body').textContent()).toLowerCase().includes('synthetic'));
  await screenshot(page, 'profile-synthetic-destination');
  await page.getByRole('link', { name: /Back.*Registry/i }).click();
  await page.waitForURL(harness.origin + listPath);
  await ready(page);
  assert.equal(state.posts.length, 0);
});

test('Workflow: read-only, empty and enabled-gate states keep the correct controls', { timeout: 60000 }, async t => {
  for (const options of [
    { canAssign: false, empty: true, miGateEnabled: false },
    { canAssign: true, empty: true, miGateEnabled: true },
  ]) {
    const { page, state } = await open(t, { ...options, viewport: { width: 390, height: 844 } });
    assert.equal(await assignForm(page).count(), options.canAssign ? 1 : 0);
    assert.equal(await page.locator('.gmi-readonly').count(), options.canAssign ? 0 : 1);
    assert.equal(await page.getByText('mi_reproduction_signoff', { exact: true }).count(), options.miGateEnabled ? 0 : 1);
    assert.equal(await assignmentRows(page).count(), 0);
    await page.getByText('No Multiple Intelligence survey assignments found.', { exact: true }).waitFor({ state: 'visible' });
    assert.equal(await page.locator('[data-table-pagination]').getAttribute('data-total'), '0');
    if (options.canAssign) {
      const failed = await postOnce(page, state);
      assert.equal(failed.result.status(), 422);
      assert.equal(state.revision, 0);
      await page.waitForFunction(() => !document.querySelector('[data-mi-assignment-form]').hasAttribute('aria-busy'));
      assert.equal(await assignForm(page).locator('input[name="fixture_csrf"]').inputValue(), failed.payload.csrf.hash);
    }
    await noOverflow(page);
    await screenshot(page, options.canAssign ? 'empty-enabled-gate-390-light' : 'read-only-pending-gate-390-light');
  }
});

test('Workflow: double-click submits once and preserves the filtered registry URL', { timeout: 60000 }, async t => {
  const { page, state } = await open(t, { postDelayMs: 1000 });
  await choose(page, '#mi-filter-program', 'BS Nursing');
  await choose(page, '#mi-filter-status', 'cancelled');
  await page.getByRole('button', { name: 'Filter', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('program') === 'BS Nursing' && url.searchParams.get('status') === 'cancelled');
  await ready(page);
  const filteredUrl = page.url();
  const original = await assignForm(page).elementHandle();
  const candidate = page.locator('.mi-student-check-row').filter({ hasNot: page.locator('.gmi-active-assignment') }).first();
  await candidate.locator('.mi-student-checkbox').check();
  await page.locator('#mi-academic-term').fill('SYN-DOUBLE-SAVE');
  const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === assignPath);
  await assignForm(page).getByRole('button', { name: 'Assign MI Survey', exact: true }).click({ clickCount: 2 });
  assert.equal(await assignForm(page).getAttribute('aria-busy'), 'true');
  assert.equal(await page.locator('#main').evaluate(main => main.inert), true);
  await screenshot(page, 'double-click-saving-1440-light');
  const result = await response;
  assert.equal(result.status(), 201);
  await saved(page, original);
  assert.equal(state.posts.length, 1);
  assert.equal([...state.assignments.values()].filter(row => row.academic_term === 'SYN-DOUBLE-SAVE').length, 1);
  assert.equal(page.url(), filteredUrl);
  assert.equal(await page.locator('#mi-filter-status').inputValue(), 'cancelled');
  await screenshot(page, 'double-click-filtered-refresh-1440-light');
});

for (const theme of ['light', 'dark']) for (const width of [375, 390, 768, 1024, 1280, 1440]) {
  test(`Responsive: ${width}px ${theme}, scoped ledgers, long names, program dropdown and datepicker`, { timeout: 60000 }, async t => {
    const { page, state } = await open(t, { theme, long: true, viewport: { width, height: width < 576 ? 844 : 1000 } });
    await noOverflow(page);
    const table = await page.locator('.gmi-assignment-row').first().evaluate(row => ({
      display: getComputedStyle(row).display,
      ledger: row.closest('.gmi-ledger-area').getBoundingClientRect().width,
    }));
    assert.equal(table.display === 'grid', table.ledger <= 720);
    const rail = await page.locator('.gmi-assignment-rail').evaluate(element => {
      const styles = getComputedStyle(element);
      return {
        position: styles.position,
        overflowX: styles.overflowX,
        overflowY: styles.overflowY,
        top: styles.top,
        railRight: element.getBoundingClientRect().right,
        ledgerLeft: element.nextElementSibling.getBoundingClientRect().left,
      };
    });
    if (width >= 1440) {
      assert.equal(rail.position, 'sticky');
      assert.equal(rail.overflowX, 'hidden');
      assert.equal(rail.overflowY, 'auto');
      const stickyTop = parseFloat(rail.top);
      assert.ok(stickyTop > 0);
      assert.ok(rail.railRight <= rail.ledgerLeft, 'Assignment rail must remain outside the independently scrolling ledger column');
      const beforeScroll = await page.locator('.gmi-grid-managed').evaluate(grid => ({
        railTop: grid.querySelector('.gmi-assignment-rail').getBoundingClientRect().top,
        ledgerTop: grid.querySelector('.gmi-ledger-area').getBoundingClientRect().top,
      }));
      await page.evaluate(() => window.scrollTo(0, Math.min(700, document.documentElement.scrollHeight - innerHeight)));
      await page.waitForFunction(expected => window.scrollY >= expected, Math.min(700, await page.evaluate(() => document.documentElement.scrollHeight - innerHeight)));
      const afterScroll = await page.locator('.gmi-grid-managed').evaluate(grid => ({
        railTop: grid.querySelector('.gmi-assignment-rail').getBoundingClientRect().top,
        ledgerTop: grid.querySelector('.gmi-ledger-area').getBoundingClientRect().top,
      }));
      assert.ok(Math.abs(afterScroll.railTop - stickyTop) <= 1, 'Assignment rail must remain pinned below the app header');
      assert.ok(afterScroll.ledgerTop < beforeScroll.ledgerTop, 'Registry ledger must continue with normal page scrolling');
      assert.ok(afterScroll.railTop - afterScroll.ledgerTop > beforeScroll.railTop - beforeScroll.ledgerTop,
        'Assignment rail and registry ledger must scroll independently');
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForFunction(() => window.scrollY === 0);
    } else {
      assert.equal(rail.position, 'static');
    }
    await screenshot(page, `responsive-${width}-${theme}-registry`);
    const source = page.locator('#mi-filter-program');
    const trigger = source.locator('..').getByRole('combobox');
    await trigger.scrollIntoViewIfNeeded();
    await screenshot(page, `responsive-${width}-${theme}-filter-anchor`);
    await trigger.click();
    const menuId = await trigger.getAttribute('aria-controls');
    const menu = page.locator('[id="' + menuId + '"]');
    await menu.waitFor({ state: 'visible' });
    const hit = await menu.evaluate(menu => {
      const r = menu.getBoundingClientRect();
      const y = Math.min(r.bottom - 5, r.top + 55);
      return [r.left + 5, r.left + r.width / 2, r.right - 5].map(x => menu.contains(document.elementFromPoint(x, y)));
    });
    assert.deepEqual(hit, [true, true, true]);
    await screenshot(page, `responsive-${width}-${theme}-program-menu`);
    await trigger.click();
    await menu.waitFor({ state: 'hidden' });
    if (width === 375 || width === 1440) {
      const dueInput = page.locator('#mi-due-at-display');
      await dueInput.scrollIntoViewIfNeeded();
      await screenshot(page, `responsive-${width}-${theme}-due-anchor`);
      await dueInput.click();
      const calendar = page.getByRole('dialog', { name: 'Choose date', exact: true });
      await calendar.waitFor({ state: 'visible' });
      const box = await calendar.boundingBox();
      assert.ok(box.x >= -1 && box.x + box.width <= width + 1);
      await screenshot(page, `responsive-${width}-${theme}-due-picker`);
    }
    assert.equal(state.posts.length, 0);
  });
}
