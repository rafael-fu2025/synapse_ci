'use strict';

// Original-view baseline and redesign gates; production data and network are not
// available. Only installed Playwright/Chromium and the per-case local fixture.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { startServer, localPlaywright, installedChromium, message } = require('../_support/teams-workspace-browser-harness.cjs');
let browser, harness;
before(async () => {
  harness = await startServer({ port: 0 });
  const { chromium } = localPlaywright();
  browser = await chromium.launch({ headless: true, executablePath: installedChromium(chromium), timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); } finally { await harness?.close(); }
  assert.deepEqual(harness?.violations, [], 'No unexpected loopback requests or renderer failures');
});
async function pageFor(t, suffix = '', options = {}) {
  const state = harness.createScenario(options.state || {});
  const context = await browser.newContext({ viewport: { width: options.width || 1280, height: 900 },
    timezoneId: 'America/Los_Angeles', serviceWorkers: 'block', acceptDownloads: false });
  await context.addCookies([{ name: 'synthetic_teams', value: state.id, url: harness.origin }]);
  const denied = [], errors = [], consoleErrors = [], failed = [], expectedResponses = [];
  const responseAllowances = (options.expectedResponses || []).map(response => ({ ...response, remaining: response.count || 1 }));
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin === harness.origin) return route.continue();
    denied.push(route.request().url()); return route.abort('blockedbyclient');
  });
  const page = await context.newPage();
  page.setDefaultTimeout(7000); page.setDefaultNavigationTimeout(15000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', log => { if (log.type() === 'error') consoleErrors.push(log.text()); });
  page.on('response', response => {
    if (response.status() < 400) return;
    const url = new URL(response.url());
    const allowance = url.origin === harness.origin && responseAllowances.find(item => item.remaining > 0 && item.status === response.status()
      && item.path === url.pathname && item.method === response.request().method());
    if (allowance) { allowance.remaining--; expectedResponses.push(response.status()); }
    else failed.push(`${response.status()} ${response.url()}`);
  });
  page.on('dialog', async dialog => { errors.push('Unexpected dialog: ' + dialog.message()); await dialog.dismiss(); });
  page.on('download', () => errors.push('Unexpected download'));
  t.after(async () => {
    await context.close();
    const allowances = [...expectedResponses];
    const unexpectedConsole = consoleErrors.filter(text => {
      const match = /^Failed to load resource: the server responded with a status of (\d+) \(/.exec(text);
      const index = match ? allowances.indexOf(Number(match[1])) : -1;
      if (index < 0) return true;
      allowances.splice(index, 1); return false;
    });
    assert.deepEqual(denied, [], 'No outside-loopback requests'); assert.deepEqual(errors, [], 'No page errors');
    assert.deepEqual(unexpectedConsole, [], 'No unaccounted console errors'); assert.deepEqual(failed, [], 'No unaccounted HTTP errors');
  });
  await page.goto(harness.origin + '/' + (options.unit || 'guidance') + '/teams' + suffix);
  return { page, state, context };
}

test('render smoke: all route keys render actual board/list/forms/details/chat/calendar views', { timeout: 40000 }, async () => {
  for (const unit of ['guidance', 'clinic', 'facilities']) {
    for (const suffix of ['', '?view=list', '/create?fragment=1', '/31?fragment=1', '/31/edit?fragment=1', '/chat',
      '/calendar?date=2030-01-31', '/calendar?date=2030-01-31&view=week', '/calendar?date=2030-01-31&view=agenda',
      '/calendar/events/create?date=2030-01-31&fragment=1', '/calendar/events/41?fragment=1', '/calendar/events/41/edit?fragment=1']) {
      const response = await fetch(harness.origin + '/' + unit + '/teams' + suffix);
      assert.equal(response.status, 200, unit + suffix);
      const html = await response.text();
      assert.match(html, /teams-workspace|teamTaskTitle|teams-detail|teamEventTitle|Monthly coordination/);
      assert.doesNotMatch(html, /Fatal error|Warning:|Notice:|Parse error/);
      if (suffix.includes('fragment=1')) assert.doesNotMatch(html, /<!doctype|<html|<script\b/i, 'Modal fragment must stay a real fragment');
    }
  }
});

async function selectValue(page, id, value) {
  const select = page.locator('#' + id);
  const label = await select.locator('option').evaluateAll((options, target) => options.find(option => option.value === target)?.label, value);
  assert.ok(label !== undefined, `Existing option ${id}=${value}`);
  const wrap = select.locator('..');
  await wrap.getByRole('combobox').click();
  await wrap.getByRole('option', { name: label, exact: true }).click();
  assert.equal(await select.inputValue(), value);
}
async function modalReady(page) {
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.getAttribute('aria-modal') === 'true' && modal.contains(document.activeElement) && !modal.querySelector('[data-modal-loading]')
      && bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  });
}
async function modalClosed(page) { await page.waitForFunction(() => !document.querySelector('#appFormModal,.modal-backdrop')); }
function field(post, name) { return post.fields.filter(([key]) => key === name).map(([, value]) => value); }
async function regionalUpdate(page, action, pathname) {
  const old = await page.locator('[data-filter-region="results"]').evaluateHandle(el => el.firstElementChild);
  const response = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === pathname
    && response.request().headers()['x-requested-with'] === 'XMLHttpRequest');
  await action();
  assert.equal((await response).status(), 200);
  await page.waitForFunction(previous => !previous.isConnected && !document.querySelector('[data-live-filters][aria-busy="true"]'), old);
  await old.dispose();
}
async function submitAndRefresh(page, button, pathname, postPath) {
  const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === postPath);
  await regionalUpdate(page, () => page.getByRole('button', { name: button, exact: true }).click(), pathname);
  assert.equal((await response).status(), 200);
  await modalClosed(page);
}

for (const unit of ['guidance', 'clinic', 'facilities']) {
  test(`tasks presentation ${unit}: retained route destinations, read-only card, populated and empty board/list`, { timeout: 25000 }, async t => {
    const { page, state } = await pageFor(t, '', { unit });
    await page.waitForFunction(() => !!window.SynapseTeamBoard && !!window.SynapseLiveFilters);
    const nav = page.getByRole('navigation', { name: 'Teams views', exact: true });
    const links = nav.locator('a');
    const navText = (await links.allTextContents()).map(text => text.replace(/\s+/g, ' ').trim());
    assert.deepEqual(navText.slice(0, 4), ['Team board', 'My tasks', 'Team chat', 'Team calendar']);
    for (const link of await links.all()) assert.equal(new URL(await link.getAttribute('href')).pathname.startsWith('/' + unit + '/teams'), true);
    assert.equal(await page.locator('.teams-task[data-task-id="34"] [data-task-drag-handle]').count(), 0);
    const retained = await page.locator('[data-filter-form]').evaluateHandle(form => [form, form.querySelector('#teamSearch')]);
    await regionalUpdate(page, () => page.getByRole('link', { name: 'List view', exact: true }).click(), '/' + unit + '/teams');
    assert.equal(await page.locator('[data-filter-region="results"] table tbody tr').count(), 100);
    assert.equal(new URL(page.url()).searchParams.get('view'), 'list');
    await regionalUpdate(page, () => page.locator('#teamSearch').fill('no such operational task'), '/' + unit + '/teams');
    assert.match(await page.locator('[data-filter-region="results"]').innerText(), /No matching tasks/);
    await regionalUpdate(page, () => page.getByRole('link', { name: 'Board view', exact: true }).click(), '/' + unit + '/teams');
    assert.equal(await page.locator('.teams-column-empty').count(), 4);
    assert.equal(await page.locator('.teams-task').count(), 0);
    assert.equal(await page.evaluate(nodes => nodes[0] === document.querySelector('[data-filter-form]') && nodes[1] === document.querySelector('#teamSearch'), retained), true);
    await retained.dispose();
    assert.equal(state.posts.length, 0, 'All task presentation changes are GET-only');
  });
}

test('tasks live filters: scope100 pagination, my tasks, debounce, persistent controls and restored history', { timeout: 40000 }, async t => {
  const { page, state } = await pageFor(t);
  await page.waitForFunction(() => !!window.SynapseNavigation && !!window.SynapseLiveFilters);
  const identities = await page.locator('[data-filter-form]').evaluateHandle(form => [form, form.querySelector('#teamSearch'), form.querySelector('#teamStatus'), form.querySelector('#teamPriority')]);
  assert.equal(await page.locator('[data-table-pagination]').getAttribute('data-per-page'), '100');
  assert.equal(await page.locator('[data-table-pagination]').getAttribute('data-total'), '125');
  await regionalUpdate(page, () => page.getByRole('link', { name: 'Next', exact: true }).click(), '/guidance/teams');
  assert.equal(await page.locator('.teams-task').count(), 25);
  assert.equal(new URL(page.url()).searchParams.get('page'), '2');
  await regionalUpdate(page, () => page.getByRole('link', { name: 'My tasks', exact: true }).click(), '/guidance/teams');
  assert.equal(await page.locator('table tbody tr').count(), 42);
  assert.equal(new URL(page.url()).searchParams.get('mine'), '1');
  const start = state.requests.length;
  await regionalUpdate(page, async () => { await page.locator('#teamSearch').fill('Review'); await page.locator('#teamSearch').fill('Review workstation'); }, '/guidance/teams');
  assert.equal(state.requests.slice(start).filter(request => request.method === 'GET' && request.headers['x-requested-with'] === 'XMLHttpRequest').length, 1, 'Rapid search input is debounced');
  assert.equal(await page.locator('table tbody tr').count(), 1);
  await regionalUpdate(page, () => selectValue(page, 'teamStatus', 'todo'), '/guidance/teams');
  await regionalUpdate(page, () => selectValue(page, 'teamPriority', 'normal'), '/guidance/teams');
  for (const [key, value] of [['q', 'Review workstation'], ['mine', '1'], ['status', 'todo'], ['priority', 'normal']]) assert.equal(new URL(page.url()).searchParams.get(key), value);
  await regionalUpdate(page, () => page.getByRole('link', { name: 'Board view', exact: true }).click(), '/guidance/teams');
  assert.equal(await page.locator('.teams-task').count(), 1);
  await regionalUpdate(page, () => page.locator('[data-filter-reset]').click(), '/guidance/teams');
  assert.equal(await page.locator('.teams-task').count(), 100);
  await regionalUpdate(page, () => page.goBack(), '/guidance/teams');
  assert.equal(await page.locator('#teamSearch').inputValue(), 'Review workstation');
  assert.equal(await page.locator('#teamStatus').inputValue(), 'todo');
  assert.equal(await page.locator('#teamPriority').inputValue(), 'normal');
  assert.equal(await page.locator('[data-filter-form] [name="mine"]').inputValue(), '1');
  assert.equal(await page.evaluate(nodes => nodes.every(node => node.isConnected) && nodes[0] === document.querySelector('[data-filter-form]'), identities), true);
  await identities.dispose();
  assert.equal(state.posts.length, 0);
});

test('task create: real checklist20, date and assignee widgets, CSRF fields, retained validation and one refreshed save', { timeout: 50000 }, async t => {
  const { page, state } = await pageFor(t, '', { unit: 'clinic', expectedResponses: [{ method: 'POST', path: '/clinic/teams/store', status: 422 }] });
  await page.getByRole('link', { name: 'New task', exact: true }).click(); await modalReady(page);
  const modal = page.locator('#appFormModal');
  assert.equal(await modal.locator('input[name="version"]').count(), 0);
  await page.getByLabel('Title *', { exact: true }).fill('Created local task <literal>');
  await page.getByLabel('Description', { exact: true }).fill('Synthetic operational note only.');
  await selectValue(page, 'teamTaskStatus', 'blocked');
  await selectValue(page, 'teamTaskPriority', 'high');
  await selectValue(page, 'teamTaskAssignee', '2');
  await page.locator('#teamTaskDueDisplay').fill('02/02/2030'); await page.locator('#teamTaskTitle').click();
  assert.equal(await page.locator('#teamTaskDue').inputValue(), '2030-02-02');
  const add = page.getByRole('button', { name: 'Add checklist item', exact: true });
  for (let i = 1; i <= 20; i++) { await add.click(); await page.getByRole('textbox', { name: 'Checklist item ' + i, exact: true }).fill('Step ' + i + ' <literal>'); }
  assert.equal(await add.isDisabled(), true);
  await page.getByRole('checkbox', { name: 'Complete checklist item 1', exact: true }).check();
  assert.equal(JSON.parse(await modal.locator('[name="checklist"]').inputValue()).length, 20);
  state.postStatuses.push(422);
  const rejected = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/clinic/teams/store');
  await page.getByRole('button', { name: 'Create task', exact: true }).click(); assert.equal((await rejected).status(), 422);
  await modal.locator('[data-modal-error]:not(.d-none)').waitFor();
  assert.equal(await page.locator('#teamTaskTitle').inputValue(), 'Created local task <literal>');
  assert.equal(await modal.locator('[name="fixture_csrf"]').inputValue(), state.csrf);
  const posted = state.posts[0];
  assert.deepEqual(posted.fields.map(([name]) => name).sort(), ['fixture_csrf', 'title', 'description', 'status', 'priority', 'assignee_user_id', 'due_date', 'checklist'].sort());
  assert.deepEqual(field(posted, 'fixture_csrf'), ['teams-token-0']);
  assert.equal(posted.headers['x-fixture-csrf'], 'teams-token-0');
  assert.equal(posted.headers['x-synapse-refresh-owner'], 'teams.tasks.clinic');
  assert.deepEqual(field(posted, 'due_date'), ['2030-02-02']); assert.deepEqual(field(posted, 'assignee_user_id'), ['2']);
  const checklist = JSON.parse(field(posted, 'checklist')[0]); assert.equal(checklist.length, 20); assert.deepEqual(checklist[0], { text: 'Step 1 <literal>', done: true });
  await submitAndRefresh(page, 'Create task', '/clinic/teams', '/clinic/teams/store');
  assert.equal(state.posts.length, 2);
  const created = [...state.tasks.values()].find(task => task.title === 'Created local task <literal>');
  assert.ok(created); assert.deepEqual(created.checklist, checklist); assert.equal(created.assignee_user_id, 2); assert.equal(created.due_date, '2030-02-02');
  assert.equal(await page.locator('.teams-task').count(), 100, 'Scope remains capped to the first 100 on page one');
});

test('task edit: former assignee, stored checklist/date/version, conflict retention, regional shell identity and no duplicate bindings', { timeout: 35000 }, async t => {
  const { page, state } = await pageFor(t, '?q=Review&mine=1', { expectedResponses: [{ method: 'POST', path: '/guidance/teams/31/update', status: 409 }], state: { taskOverrides: { 31: { assignee_user_id: 1 } } } });
  const identity = await page.locator('.teams-workspace').evaluateHandle(root => [root, root.querySelector('[data-filter-form]'), document.querySelector('#main')]);
  for (let iteration = 0; iteration < 2; iteration++) {
    await page.locator('.teams-task[data-task-id="31"]').getByRole('link', { name: 'Edit / move', exact: true }).click(); await modalReady(page);
    assert.equal(await page.locator('#appFormModal [name="version"]').inputValue(), '7');
    assert.equal(await page.locator('#teamTaskDue').inputValue(), '2030-01-31');
    assert.equal(await page.getByRole('textbox', { name: 'Checklist item 2', exact: true }).inputValue(), 'Record count <literal>');
    assert.equal(await page.getByRole('checkbox', { name: 'Complete checklist item 1', exact: true }).isChecked(), true);
    if (iteration === 0) { await page.locator('#appFormModal a[data-app-modal-close]').click(); await modalClosed(page); }
  }
  await page.locator('#teamTaskTitle').fill('Review edited operational task');
  await page.getByRole('textbox', { name: 'Checklist item 2', exact: true }).fill('Recount stored supplies');
  await selectValue(page, 'teamTaskAssignee', '2');
  await page.locator('#teamTaskDueDisplay').fill('03/02/2030'); await page.locator('#teamTaskTitle').click();
  state.postStatuses.push(409);
  const rejected = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/guidance/teams/31/update');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click(); assert.equal((await rejected).status(), 409);
  await page.locator('[data-modal-error]:not(.d-none)').waitFor();
  assert.equal(await page.locator('#teamTaskTitle').inputValue(), 'Review edited operational task');
  assert.deepEqual(field(state.posts[0], 'version'), ['7']); assert.deepEqual(field(state.posts[0], 'fixture_csrf'), ['teams-token-0']);
  assert.deepEqual(field(state.posts[0], 'due_date'), ['2030-02-03']); assert.deepEqual(field(state.posts[0], 'assignee_user_id'), ['2']);
  await submitAndRefresh(page, 'Save changes', '/guidance/teams', '/guidance/teams/31/update');
  assert.equal(state.tasks.get(31).version, 8); assert.equal(state.posts.length, 2);
  assert.equal(await page.locator('.teams-task').count(), 0, 'Saved task no longer belongs to the My tasks filter');
  assert.equal(new URL(page.url()).searchParams.get('q'), 'Review'); assert.equal(new URL(page.url()).searchParams.get('mine'), '1');
  assert.equal(await page.evaluate(nodes => nodes.every(node => node.isConnected) && nodes[0] === document.querySelector('.teams-workspace'), identity), true);
  await identity.dispose();
  const direct = await pageFor(t, '/31/edit', { unit: 'facilities', width: 390, state: { taskOverrides: { 31: { assignee_user_id: 999 } } } });
  await modalReady(direct.page);
  assert.equal(await direct.page.locator('#teamTaskAssignee').inputValue(), '999');
  assert.match(await direct.page.locator('#teamTaskAssignee option[value="999"]').textContent(), /Former member/);
  assert.equal(await direct.page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true');
  await direct.page.locator('#appFormModal a[data-app-modal-close]').click();
  await direct.page.waitForURL(harness.origin + '/facilities/teams');
});

test('task detail: move and comment are independent versioned/CSRF forms with refreshed authoritative results', { timeout: 35000 }, async t => {
  const { page, state } = await pageFor(t, '?q=Review', { unit: 'clinic' });
  const open = async () => { await page.locator('.teams-task[data-task-id="31"] .teams-task-title a').click(); await modalReady(page); };
  await open();
  const modal = page.locator('#appFormModal');
  assert.equal(await modal.locator('form[data-modal-submit]').count(), 2);
  const forms = await modal.locator('form[data-modal-submit]').evaluateAll(forms => forms.map(form => ({ path: new URL(form.action).pathname,
    names: Array.from(form.elements).filter(control => control.name).map(control => control.name) })));
  assert.deepEqual(forms, [{ path: '/clinic/teams/31/move', names: ['fixture_csrf', 'version', 'status'] }, { path: '/clinic/teams/31/comments', names: ['fixture_csrf', 'body'] }]);
  await selectValue(page, 'taskMoveStatus', 'done');
  await submitAndRefresh(page, 'Update status', '/clinic/teams', '/clinic/teams/31/move');
  assert.equal(state.posts.length, 1); assert.equal(state.tasks.get(31).status, 'done'); assert.equal(state.tasks.get(31).version, 8);
  assert.deepEqual(field(state.posts[0], 'version'), ['7']); assert.deepEqual(field(state.posts[0], 'status'), ['done']); assert.deepEqual(field(state.posts[0], 'body'), []);
  await open();
  await page.locator('#taskComment').fill('Operational update <img src=x onerror="window.teamsXss=1">');
  await submitAndRefresh(page, 'Post comment', '/clinic/teams', '/clinic/teams/31/comments');
  assert.equal(state.posts.length, 2); assert.deepEqual(field(state.posts[1], 'version'), []); assert.deepEqual(field(state.posts[1], 'status'), []);
  assert.deepEqual(field(state.posts[1], 'fixture_csrf'), ['teams-token-1']); assert.equal(state.posts[1].headers['x-fixture-csrf'], 'teams-token-1');
  await open();
  assert.match(await page.locator('.teams-comment').first().innerText(), /Operational update <img/);
  assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0); assert.equal(await page.evaluate(() => window.teamsXss), undefined);
  await page.locator('#appFormModal a[data-app-modal-close]').click(); await modalClosed(page);
});

function taskGeometry(page) {
  return page.evaluate(() => {
    const root = document.querySelector('.teams-workspace'), bounds = root.getBoundingClientRect(), board = root.querySelector('.teams-board'), table = root.querySelector('table');
    const within = Array.from(root.querySelectorAll('a,button,input,textarea,[role="combobox"]')).filter(el => el.getClientRects().length && !el.closest('.teams-board') && !el.closest('.dropdown-menu:not(.show)'))
      .filter(el => { const r = el.getBoundingClientRect(); return r.left < bounds.left - 1 || r.right > bounds.right + 1; }).map(el => el.id || el.getAttribute('aria-label') || el.textContent.trim().slice(0, 40));
    return { document: document.documentElement.scrollWidth, viewport: innerWidth, workspace: { x: bounds.x, width: bounds.width, right: bounds.right }, overflow: within,
      board: board ? { client: board.clientWidth, scroll: board.scrollWidth, columns: root.querySelectorAll('.teams-column').length } : null,
      table: table ? { role: table.getAttribute('role'), rows: table.querySelectorAll('tbody tr').length, mobileCards: root.querySelectorAll('.mobile-card-list').length } : null,
      sheetBackground: getComputedStyle(root.querySelector('.tws-sheet') || root).backgroundColor };
  });
}
for (const theme of ['light', 'dark']) {
  for (const [width, container] of [[1280, false], [768, false], [390, false], [320, false], [1280, true]]) {
    test(`tasks geometry ${theme} ${container ? '350 container' : width}: local overflow and modal actions stay usable`, { timeout: 30000 }, async t => {
      const { page } = await pageFor(t, '?q=Review&theme=' + theme + (container ? '&container=350' : ''), { width });
      await page.waitForFunction(() => !!window.SynapseTeamBoard);
      assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
      const board = await taskGeometry(page);
      assert.ok(board.document <= board.viewport, JSON.stringify(board)); assert.deepEqual(board.overflow, [], JSON.stringify(board));
      if (container) assert.ok(board.workspace.width <= 350);
      assert.equal(board.board.columns, 4);
      await regionalUpdate(page, () => page.getByRole('link', { name: 'List view', exact: true }).click(), '/guidance/teams');
      const list = await taskGeometry(page);
      assert.ok(list.document <= list.viewport, JSON.stringify(list)); assert.deepEqual(list.overflow, [], JSON.stringify(list)); assert.equal(list.table.rows, 1);
      assert.equal(list.table.role, 'table'); assert.equal(list.table.mobileCards, 0);
      await page.getByRole('link', { name: 'Edit', exact: true }).click(); await modalReady(page);
      const modal = await page.locator('#appFormModal').evaluate(root => {
        const body = root.querySelector('.modal-body'), footer = root.querySelector('.modal-footer'), dialog = root.querySelector('.modal-dialog').getBoundingClientRect();
        const save = root.querySelector('button[type="submit"]').getBoundingClientRect();
        return { viewport: innerWidth, dialog: { x: dialog.x, right: dialog.right }, footer: !!footer, footerInBody: footer ? body.contains(footer) : null,
          actions: root.querySelectorAll('.tws-form-actions').length, submitRight: save.right, focus: root.contains(document.activeElement) };
      });
      assert.ok(modal.dialog.x >= 0 && modal.dialog.right <= modal.viewport + 1, JSON.stringify(modal));
      assert.equal(modal.footer, true); assert.equal(modal.footerInBody, false); assert.equal(modal.actions, 1); assert.equal(modal.focus, true);
      assert.ok(modal.submitRight <= modal.viewport + 1, JSON.stringify(modal));
      await page.locator('#appFormModal a[data-app-modal-close]').click(); await modalClosed(page);
      t.diagnostic(`task geometry ${theme}/${width}${container ? '/container350' : ''} ${JSON.stringify({ board, list, modal })}`);
    });
  }
}

for (const theme of ['light', 'dark']) {
  test(`tasks zebra ${theme}: rendered rows and their transparent cells expose alternating surfaces`, { timeout: 20000 }, async t => {
    const { page } = await pageFor(t, '?view=list&theme=' + theme, { state: { taskCount: 3 } });
    const stripes = await page.locator('.tws-task-table tbody tr').evaluateAll(rows => {
      const effective = el => {
        for (let node = el; node; node = node.parentElement) {
          const color = getComputedStyle(node).backgroundColor;
          if (color !== 'rgba(0, 0, 0, 0)' && color !== 'transparent') return color;
        }
        return 'transparent';
      };
      return rows.map(row => ({ row: getComputedStyle(row).backgroundColor, surface: effective(row),
        cellSurfaces: [...row.cells].map(cell => effective(cell)), cellBackgrounds: [...row.cells].map(cell => getComputedStyle(cell).backgroundColor),
        cellShadows: [...row.cells].map(cell => getComputedStyle(cell).boxShadow) }));
    });
    assert.equal(stripes.length, 3); assert.notEqual(stripes[0].surface, stripes[1].surface, JSON.stringify(stripes)); assert.equal(stripes[0].surface, stripes[2].surface);
    for (const stripe of stripes) {
      assert.ok(stripe.cellBackgrounds.every(color => color === 'rgba(0, 0, 0, 0)' || color === 'transparent'), JSON.stringify(stripes));
      assert.ok(stripe.cellSurfaces.every(color => color === stripe.surface), JSON.stringify(stripes));
      assert.ok(stripe.cellShadows.every(shadow => shadow === 'none'), JSON.stringify(stripes));
    }
    t.diagnostic(`rendered zebra ${theme} ${JSON.stringify(stripes.map(stripe => stripe.surface))}`);
  });
}

async function chatReady(page) { await page.waitForFunction(() => !!window.SynapseTeamChat && !document.querySelector('[data-team-chat]').hasAttribute('data-initial')); }
const sends = state => state.posts.filter(post => post.path.endsWith('/chat/send'));
const reads = state => state.posts.filter(post => post.path.endsWith('/chat/read'));
async function pickChatTasks(page, ids) {
  await page.getByRole('button', { name: 'Add task references', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Reference tasks', exact: true });
  await dialog.waitFor(); assert.equal(await dialog.evaluate(el => el.matches(':modal')), true);
  assert.equal(await page.locator('#teamChatTaskSearch').evaluate(el => el === document.activeElement), true);
  for (const id of ids) await dialog.getByRole('checkbox', { name: new RegExp('^#' + id + ':') }).check();
  await dialog.getByRole('button', { name: 'Done', exact: true }).click();
  assert.equal(await dialog.isVisible(), false);
}

test('chat references, mention and reply: real native picker and escaped chronological send contract', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t, '/chat', { unit: 'clinic' }); await chatReady(page);
  assert.equal(await page.locator('[data-chat-log] img[src="x"]').count(), 0);
  const avatars = await page.locator('.team-chat-sidebar img.avatar').evaluateAll(images => images.map(image => image.getAttribute('src')));
  assert.ok(avatars.length >= 4 && avatars.every(url => url.startsWith('data:image/svg+xml;base64,')), 'Actual pure ProfileAvatar produces local data images; no photo endpoint');
  await pickChatTasks(page, [31, 32]);
  assert.equal(await page.locator('.team-chat-task-chip').count(), 2);
  await page.getByRole('button', { name: 'Add task references', exact: true }).click();
  const tasks = page.getByRole('dialog', { name: 'Reference tasks', exact: true });
  assert.equal(await tasks.getByRole('checkbox', { name: /^#31:/ }).isChecked(), true);
  const query = page.waitForResponse(response => new URL(response.url()).pathname === '/clinic/teams/chat/tasks' && new URL(response.url()).searchParams.get('q') === 'Workspace task 32');
  await page.locator('#teamChatTaskSearch').fill('Workspace task 32'); assert.equal((await query).status(), 200);
  assert.equal(await tasks.getByRole('checkbox').count(), 1);
  await page.getByRole('button', { name: 'Close task references', exact: true }).click();
  const body = page.getByRole('textbox', { name: 'Message', exact: true });
  await body.fill('@Other'); await page.getByRole('option', { name: '@Other Member', exact: true }).waitFor();
  await body.press('Enter'); assert.equal(await body.inputValue(), '@Other Member '); assert.equal(sends(state).length, 0, 'Enter selects a mention, not a send');
  await page.keyboard.insertText('please review <literal>');
  const reply = page.locator('[data-message-id="1"]').getByRole('button', { name: 'Reply to Other Member', exact: true });
  await reply.click(); assert.equal(await page.locator('[data-chat-reply-preview]').isVisible(), true);
  assert.match(await page.locator('[data-chat-reply-preview] p').textContent(), /<img/); assert.equal(await page.locator('[data-chat-reply-preview] img').count(), 0);
  await page.getByRole('button', { name: 'Cancel reply', exact: true }).click(); assert.equal(await page.locator('[data-chat-reply-preview]').isHidden(), true);
  await reply.click();
  const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/clinic/teams/chat/send');
  await page.getByRole('button', { name: 'Send', exact: true }).click(); assert.equal((await response).status(), 200);
  await page.locator('[data-message-id="4"]').waitFor();
  const post = sends(state)[0];
  assert.equal(sends(state).length, 1); assert.deepEqual(field(post, 'body'), ['@Other Member please review <literal>']);
  assert.deepEqual(field(post, 'task_ids[]'), ['31', '32']); assert.deepEqual(field(post, 'reply_to_id'), ['1']);
  assert.deepEqual(field(post, 'mentions[0][user_id]'), ['2']); assert.deepEqual(field(post, 'mentions[0][start]'), ['0']); assert.deepEqual(field(post, 'mentions[0][length]'), ['13']);
  assert.equal(field(post, 'fixture_csrf')[0], post.headers['x-fixture-csrf']);
  assert.match(field(post, 'client_nonce')[0], /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  assert.equal(post.headers['x-synapse-refresh-owner'], undefined);
  assert.equal(await page.locator('[data-message-id="4"] .team-chat-mention').textContent(), '@Other Member');
  assert.deepEqual(await page.locator('[data-message-id="4"] .team-chat-task').evaluateAll(links => links.map(link => new URL(link.href).pathname)), ['/clinic/teams/31', '/clinic/teams/32']);
  assert.match(await page.locator('[data-message-id="4"] .team-chat-reply-quote p').textContent(), /<img/);
  assert.equal(await body.inputValue(), ''); assert.equal(await page.locator('.team-chat-task-chip').count(), 0); assert.equal(await page.locator('[data-chat-reply-preview]').isHidden(), true);
  assert.equal(await page.evaluate(() => window.teamsXss), undefined);
});

test('chat history and unread:40-message pages deduplicate, preserve draft, pause history, acknowledge visible latest only', { timeout: 25000 }, async t => {
  const { page, state } = await pageFor(t, '/chat', { unit: 'guidance', state: { messages: Array.from({ length: 85 }, (_, i) => message(i + 1)), overlap: true, browsingHistory: true } });
  await chatReady(page); assert.equal(await page.locator('[data-message-id]').count(), 40);
  assert.equal(await page.locator('[data-message-id]').first().getAttribute('data-message-id'), '46');
  await page.locator('#teamChatBody').fill('Keep this local history draft');
  const held = await page.locator('[data-message-id="60"]').elementHandle();
  await page.getByRole('button', { name: 'Load older messages', exact: true }).click(); await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 80);
  assert.equal(await held.evaluate(el => el.isConnected && el === document.querySelector('[data-message-id="60"]')), true);
  await page.getByRole('button', { name: 'Load older messages', exact: true }).click(); await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 85);
  assert.deepEqual(await page.locator('[data-message-id]').evaluateAll(nodes => nodes.map(node => Number(node.dataset.messageId))), Array.from({ length: 85 }, (_, i) => i + 1));
  assert.match(await page.locator('[data-chat-history-status]').textContent(), /paused/);
  await page.waitForTimeout(650); assert.equal(reads(state).length, 0, 'Paused history never acknowledges messages');
  assert.equal(await page.locator('#teamChatBody').inputValue(), 'Keep this local history draft');
  const read = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/guidance/teams/chat/read');
  await page.getByRole('button', { name: 'Show latest', exact: true }).click(); await read;
  await page.waitForFunction(() => document.querySelector('[data-chat-unread]').textContent === '0');
  assert.equal(await page.locator('[data-message-id]').count(), 40); assert.equal(state.lastRead, 85); assert.deepEqual(field(reads(state).at(-1), 'last_read_id'), ['85']);
  assert.equal(field(reads(state).at(-1), 'fixture_csrf')[0], reads(state).at(-1).headers['x-fixture-csrf']);
  assert.equal(sends(state).length, 0); assert.equal(await page.locator('#teamChatBody').inputValue(), 'Keep this local history draft');
});

test('chat nonce retry: synthetic committed503 keeps locked draft and retries one logical send with fresh CSRF', { timeout: 25000 }, async t => {
  const { page, state } = await pageFor(t, '/chat', { unit: 'facilities', state: { messages: [], loseSendResponses: 1 },
    expectedResponses: [{ method: 'POST', path: '/facilities/teams/chat/send', status: 503 }] });
  await chatReady(page); await page.locator('#teamChatBody').fill('Preserve this exact text\nand newline'); await pickChatTasks(page, [31]);
  await page.getByRole('button', { name: 'Send', exact: true }).click();
  await page.getByRole('button', { name: 'Retry send', exact: true }).waitFor();
  assert.equal(await page.locator('#teamChatBody').isEditable(), false); assert.equal(await page.getByRole('button', { name: 'Add task references', exact: true }).isDisabled(), true);
  assert.equal(await page.locator('#teamChatBody').inputValue(), 'Preserve this exact text\nand newline'); assert.equal(state.messages.length, 1);
  await page.getByRole('button', { name: 'Retry send', exact: true }).click(); await page.locator('[data-message-id="1"]').waitFor();
  assert.equal(sends(state).length, 2); assert.equal(state.messages.length, 1);
  for (const name of ['body', 'client_nonce', 'task_ids[]']) assert.deepEqual(field(sends(state)[0], name), field(sends(state)[1], name));
  assert.deepEqual(field(sends(state)[0], 'fixture_csrf'), ['teams-token-0']); assert.deepEqual(field(sends(state)[1], 'fixture_csrf'), ['teams-token-1']);
  assert.equal(await page.locator('[data-message-id]').count(), 1); assert.equal(await page.locator('#teamChatBody').inputValue(), ''); assert.equal(await page.locator('#teamChatBody').isEditable(), true);
});

for (const theme of ['light', 'dark']) {
  test(`chat and calendar geometry ${theme}: desktop768390320 and350 container with native/modal containment`, { timeout: 55000 }, async t => {
    for (const [width, container] of [[1280, false], [768, false], [390, false], [320, false], [1280, true]]) {
      const query = '?theme=' + theme + (container ? '&container=350' : '');
      const chat = await pageFor(t, '/chat' + query, { unit: 'facilities', width }); await chatReady(chat.page);
      const chatBounds = await workspaceGeometry(chat.page);
      assert.ok(chatBounds.document <= chatBounds.viewport, JSON.stringify(chatBounds)); assert.deepEqual(chatBounds.overflow, [], JSON.stringify(chatBounds));
      if (container) assert.ok(chatBounds.width <= 350);
      await chat.page.getByRole('button', { name: 'Add task references', exact: true }).click();
      const picker = chat.page.getByRole('dialog', { name: 'Reference tasks', exact: true }); await picker.waitFor();
      const bounds = await picker.boundingBox(); assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, JSON.stringify(bounds));
      assert.equal(await picker.evaluate(el => el.matches(':modal') && el.contains(document.activeElement)), true);
      await picker.getByRole('button', { name: 'Close task references', exact: true }).click();
      const calendar = await pageFor(t, '/calendar' + query + '&date=2030-01-31', { unit: 'clinic', width });
      await calendar.page.waitForFunction(() => !!window.SynapseLiveFilters && !!window.BalayDatepicker);
      for (const mode of ['month', 'week', 'agenda']) {
        if (mode !== 'month') await regionalUpdate(calendar.page, () => calendar.page.getByRole('link', { name: mode === 'week' ? 'Week' : 'Agenda', exact: true }).click(), '/clinic/teams/calendar');
        const geometry = await workspaceGeometry(calendar.page);
        assert.ok(geometry.document <= geometry.viewport, JSON.stringify({ mode, ...geometry })); assert.deepEqual(geometry.overflow, [], JSON.stringify({ mode, ...geometry }));
        if (mode !== 'agenda') {
          const scroll = await calendar.page.locator('.team-calendar-scroll').evaluate(el => ({ client: el.clientWidth, scroll: el.scrollWidth,
            overflow: getComputedStyle(el).overflowX, gridMinimum: parseFloat(getComputedStyle(el.querySelector('table')).minWidth) }));
          assert.equal(scroll.overflow, 'auto');
          if (scroll.client < scroll.gridMinimum) assert.ok(scroll.scroll > scroll.client, JSON.stringify(scroll));
        }
      }
      await calendar.page.getByRole('link', { name: 'New event', exact: true }).click(); await modalReady(calendar.page);
      const modal = await calendar.page.locator('#appFormModal').evaluate(root => {
        const bounds = root.querySelector('.modal-dialog').getBoundingClientRect(), footer = root.querySelector('.modal-footer');
        return { x: bounds.x, right: bounds.right, viewport: innerWidth, footer: !!footer, footerInBody: footer ? root.querySelector('.modal-body').contains(footer) : null,
          widgets: root.querySelectorAll('.dtp-wrap').length, scrollWidth: document.documentElement.scrollWidth, focus: root.contains(document.activeElement) };
      });
      assert.ok(modal.x >= 0 && modal.right <= width + 1 && modal.scrollWidth <= width, JSON.stringify(modal)); assert.equal(modal.widgets, 2); assert.equal(modal.footer, true); assert.equal(modal.footerInBody, false); assert.equal(modal.focus, true);
      await calendar.page.locator('#appFormModal a[data-app-modal-close]').click(); await modalClosed(calendar.page);
      t.diagnostic(`workspace geometry ${theme}/${width}${container ? '/container350' : ''} ${JSON.stringify({ chat: chatBounds, picker: bounds, eventModal: modal })}`);
      await chat.context.close(); await calendar.context.close();
    }
  });
}
async function workspaceGeometry(page) {
  return page.locator('.teams-workspace').evaluate(root => {
    const bounds = root.getBoundingClientRect();
    const overflow = [...root.querySelectorAll('a,button,input,textarea,[role="combobox"]')].filter(el => el.getClientRects().length
      && !el.closest('.team-calendar-scroll,dialog,[data-chat-mentions],.dropdown-menu:not(.show)'))
      .filter(el => { const box = el.getBoundingClientRect(); return box.left < bounds.left - 1 || box.right > bounds.right + 1; }).map(el => el.id || el.getAttribute('aria-label') || el.textContent.trim().slice(0, 30));
    return { document: document.documentElement.scrollWidth, viewport: innerWidth, x: bounds.x, width: bounds.width, right: bounds.right, overflow,
      theme: document.documentElement.dataset.theme, background: getComputedStyle(root.querySelector('.tws-sheet')).backgroundColor };
  });
}

test('calendar views and live filters: actual date/scope/status controls persist and midnight does not occupy next day', { timeout: 35000 }, async t => {
  const { page, state } = await pageFor(t, '/calendar?date=2030-01-31', { unit: 'guidance' });
  await page.waitForFunction(() => !!window.SynapseLiveFilters && !!window.BalayDatepicker);
  const identities = await page.locator('[data-filter-form]').evaluateHandle(form => [form, form.querySelector('#calendarDateDisplay'), form.querySelector('#calendarScope'), form.querySelector('#calendarEventStatus')]);
  assert.equal(await page.locator('[data-filter-region="summary"]').textContent(), '2 matching events3 task deadlines6 active members');
  const event41 = page.locator('.team-calendar-item.is-event[href$="/events/41"]');
  assert.equal(await event41.count(), 2, 'The stored event ends at Feb2 midnight and belongs only to Jan31/Feb1');
  const feb2 = page.locator('.team-calendar-grid td').filter({ has: page.locator('time[datetime="2030-02-02"]') });
  assert.equal(await feb2.locator('.team-calendar-item[href$="/events/41"]').count(), 0);
  await regionalUpdate(page, () => page.getByRole('link', { name: 'Week', exact: true }).click(), '/guidance/teams/calendar');
  assert.equal(await page.locator('.team-calendar-grid.is-week tbody td').count(), 7);
  await regionalUpdate(page, () => page.getByRole('link', { name: 'Agenda', exact: true }).click(), '/guidance/teams/calendar');
  assert.equal(await page.locator('.team-calendar-grid').count(), 0); assert.equal(await page.locator('.team-calendar-agenda').count(), 1);
  await regionalUpdate(page, () => selectValue(page, 'calendarScope', '1'), '/guidance/teams/calendar');
  assert.match(await page.locator('[data-filter-region="summary"]').textContent(), /^1 matching events1 task deadlines/);
  await regionalUpdate(page, () => selectValue(page, 'calendarEventStatus', 'all'), '/guidance/teams/calendar');
  assert.match(await page.locator('[data-filter-region="summary"]').textContent(), /^2 matching events1 task deadlines/);
  await regionalUpdate(page, () => selectValue(page, 'calendarEventStatus', 'cancelled'), '/guidance/teams/calendar');
  assert.equal(await page.locator('.team-calendar-item.is-event').count(), 1);
  assert.match(await page.locator('.team-calendar-item.is-event').textContent(), /Cancelled planning event/);
  await regionalUpdate(page, async () => { await page.locator('#calendarDateDisplay').fill('04/02/2030'); await page.locator('#calendarDateDisplay').blur(); }, '/guidance/teams/calendar');
  assert.equal(await page.locator('#calendarDate').inputValue(), '2030-02-04');
  assert.match(await page.locator('.team-calendar-agenda').textContent(), /No events or task deadlines/);
  assert.equal(new URL(await page.getByRole('link', { name: 'New event', exact: true }).getAttribute('href')).searchParams.get('date'), '2030-02-04');
  for (const [key, value] of [['date', '2030-02-04'], ['view', 'agenda'], ['mine', '1'], ['status', 'cancelled']]) assert.equal(new URL(page.url()).searchParams.get(key), value);
  assert.equal(await page.evaluate(nodes => nodes.every(node => node.isConnected) && nodes[0] === document.querySelector('[data-filter-form]'), identities), true);
  await identities.dispose(); assert.equal(state.posts.length, 0);
});

async function eventTime(page, id, display, expected) {
  await page.locator('#' + id + 'Display').fill(display); await page.locator('#teamEventTitle').click();
  assert.equal(await page.locator('#' + id).inputValue(), expected);
}
test('calendar create:2 real datetime widgets, participants, exact CSRF and retained synthetic31-day validation then refresh', { timeout: 35000 }, async t => {
  const { page, state } = await pageFor(t, '/calendar?date=2030-01-31&view=week&status=all&mine=1', { unit: 'clinic',
    expectedResponses: [{ method: 'POST', path: '/clinic/teams/calendar/events/store', status: 422 }] });
  const identities = await page.locator('[data-filter-form]').evaluateHandle(form => [form, document.querySelector('.teams-workspace')]);
  await page.getByRole('link', { name: 'New event', exact: true }).click(); await modalReady(page);
  assert.equal(await page.locator('#appFormModal .dtp-wrap').count(), 2); assert.equal(await page.locator('#appFormModal [name="version"]').count(), 0);
  assert.equal(await page.locator('#teamEventStart').inputValue(), '2030-01-31T09:00'); assert.equal(await page.locator('#teamEventEnd').inputValue(), '2030-01-31T10:00');
  await page.locator('#teamEventTitle').fill('31-day local coordination event'); await page.locator('#teamEventDescription').fill('Literal <img src=x> operational context');
  await page.locator('#teamEventLocation').fill('Room 2'); await page.locator('#eventMember2').check(); await page.locator('#eventMember3').check();
  await eventTime(page, 'teamEventEnd', '04/03/2030 09:00 AM', '2030-03-04T09:00');
  const rejected = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/clinic/teams/calendar/events/store');
  await page.getByRole('button', { name: 'Create event', exact: true }).click(); assert.equal((await rejected).status(), 422);
  await page.locator('[data-modal-error]:not(.d-none)').waitFor();
  assert.equal(await page.locator('#teamEventTitle').inputValue(), '31-day local coordination event'); assert.equal(await page.locator('#eventMember2').isChecked(), true);
  assert.equal(await page.locator('#teamEventEnd').inputValue(), '2030-03-04T09:00'); assert.equal(await page.locator('#appFormModal [name="fixture_csrf"]').inputValue(), 'teams-token-1');
  const post = state.posts[0];
  assert.deepEqual(post.fields.map(([name]) => name).sort(), ['fixture_csrf', 'title', 'description', 'starts_at', 'ends_at', 'location', 'participant_ids[]', 'participant_ids[]'].sort());
  assert.deepEqual(field(post, 'participant_ids[]'), ['2', '3']); assert.deepEqual(field(post, 'starts_at'), ['2030-01-31T09:00']); assert.deepEqual(field(post, 'ends_at'), ['2030-03-04T09:00']);
  assert.deepEqual(field(post, 'fixture_csrf'), ['teams-token-0']); assert.equal(post.headers['x-fixture-csrf'], 'teams-token-0'); assert.equal(post.headers['x-synapse-refresh-owner'], 'teams.calendar.clinic');
  await eventTime(page, 'teamEventEnd', '03/03/2030 09:00 AM', '2030-03-03T09:00');
  await submitAndRefresh(page, 'Create event', '/clinic/teams/calendar', '/clinic/teams/calendar/events/store');
  assert.equal(state.posts.length, 2); const created = [...state.events.values()].find(event => event.title === '31-day local coordination event'); assert.ok(created);
  assert.equal(Date.parse(created.ends_at.replace(' ', 'T') + 'Z') - Date.parse(created.starts_at.replace(' ', 'T') + 'Z'), 31 * 86400000);
  assert.deepEqual(created.participant_ids, [2, 3]); assert.deepEqual(field(state.posts[1], 'fixture_csrf'), ['teams-token-1']);
  for (const [key, value] of [['date', '2030-01-31'], ['view', 'week'], ['status', 'all'], ['mine', '1']]) assert.equal(new URL(page.url()).searchParams.get(key), value);
  assert.equal(await page.evaluate(nodes => nodes.every(node => node.isConnected) && nodes[0] === document.querySelector('[data-filter-form]'), identities), true); await identities.dispose();
});

test('calendar event detail: decline makes no write, accepted cancellation submits one version/CSRF and refreshes history', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t, '/calendar?date=2030-01-31&view=agenda&status=all&mine=1', { unit: 'guidance' });
  const open = async () => { await page.locator('.team-calendar-item.is-event[href$="/events/41"]').first().click(); await modalReady(page); };
  await open(); assert.match(await page.locator('#appFormModal').innerText(), /Participants \(1\)/); assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0);
  assert.equal(await page.getByRole('link', { name: 'Edit event', exact: true }).getAttribute('href'), harness.origin + '/guidance/teams/calendar/events/41/edit');
  await page.locator('#appFormModal').getByRole('button', { name: 'Cancel event', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor(); assert.equal(state.posts.length, 0); assert.equal(await page.locator('[data-confirm-title-text]').textContent(), 'Cancel team event');
  await page.locator('[data-confirm-cancel]').click(); await page.waitForFunction(() => document.querySelector('#synapseConfirmModal')?.getAttribute('aria-hidden') === 'true' && !document.querySelector('#appFormModal')?.inert);
  assert.equal(state.posts.length, 0); assert.equal(await page.locator('#appFormModal').evaluate(el => el.contains(document.activeElement)), true);
  await page.locator('#appFormModal').getByRole('button', { name: 'Cancel event', exact: true }).click(); await page.locator('#synapseConfirmModal.show').waitFor();
  const cancellation = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/guidance/teams/calendar/events/41/cancel');
  await regionalUpdate(page, () => page.locator('[data-confirm-accept]').click(), '/guidance/teams/calendar');
  assert.equal((await cancellation).status(), 200); await modalClosed(page);
  assert.equal(state.posts.length, 1); assert.deepEqual(state.posts[0].fields, [['fixture_csrf', 'teams-token-0'], ['version', '3']]);
  assert.equal(state.posts[0].headers['x-synapse-refresh-owner'], 'teams.calendar.guidance'); assert.equal(state.events.get(41).status, 'cancelled'); assert.equal(state.events.get(41).version, 4);
  assert.equal(new URL(page.url()).searchParams.get('status'), 'all'); assert.equal(new URL(page.url()).searchParams.get('mine'), '1');
  await open(); assert.match(await page.locator('#appFormModal').innerText(), /This event has been cancelled/);
  assert.equal(await page.getByRole('link', { name: 'Edit event', exact: true }).count(), 0); assert.equal(await page.locator('#appFormModal form[data-modal-submit]').count(), 0);
  await page.locator('#appFormModal a[data-app-modal-close]').click(); await modalClosed(page);
});

test('calendar direct edit context: stored version/participants/datetimes submit without owner then navigate canonical calendar', { timeout: 25000 }, async t => {
  const { page, state } = await pageFor(t, '/calendar/events/41', { unit: 'facilities', width: 390 }); await modalReady(page);
  await page.getByRole('link', { name: 'Edit event', exact: true }).click(); await page.waitForURL(harness.origin + '/facilities/teams/calendar/events/41/edit'); await modalReady(page);
  assert.equal(await page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true'); assert.equal(await page.locator('#appFormModal [name="version"]').inputValue(), '3');
  assert.equal(await page.locator('#teamEventStart').inputValue(), '2030-01-31T09:15'); assert.equal(await page.locator('#teamEventEnd').inputValue(), '2030-02-02T00:00');
  assert.equal(await page.locator('#eventMember2').isChecked(), true); await page.locator('#eventMember2').uncheck(); await page.locator('#eventMember1').check();
  await page.locator('#teamEventTitle').fill('Updated direct event'); await eventTime(page, 'teamEventStart', '31/01/2030 09:30 AM', '2030-01-31T09:30');
  const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/facilities/teams/calendar/events/41/update');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click(); const received = await response; assert.equal(received.status(), 200);
  const payload = await received.json(); assert.deepEqual(payload.refresh, { mode: 'navigate', url: harness.origin + '/facilities/teams/calendar?date=2030-01-31', history: 'push' });
  await page.waitForURL(harness.origin + '/facilities/teams/calendar?date=2030-01-31'); await modalClosed(page); await page.locator('.team-calendar-grid').waitFor();
  assert.equal(state.posts.length, 1); assert.deepEqual(field(state.posts[0], 'version'), ['3']); assert.deepEqual(field(state.posts[0], 'participant_ids[]'), ['1']);
  assert.equal(state.posts[0].headers['x-synapse-refresh-owner'], undefined); assert.equal(state.events.get(41).version, 4); assert.deepEqual(state.events.get(41).participant_ids, [1]);
});

for (const context of ['chat', 'calendar', 'direct']) {
  test(`task modal ${context} context: absent/foreign owner uses navigation descriptor and exact canonical destination`, { timeout: 25000 }, async t => {
    const suffix = context === 'chat' ? '/chat' : context === 'calendar' ? '/calendar?date=2030-01-31&view=agenda' : '/31/edit';
    const { page, state } = await pageFor(t, suffix, { unit: 'clinic', state: { taskCount: 1 } });
    if (context === 'chat') { await chatReady(page); await page.locator('[data-message-id="1"] .team-chat-task').click(); await modalReady(page); await page.locator('#taskComment').fill('Context comment'); }
    else if (context === 'calendar') { await page.locator('.team-calendar-item.is-task').click(); await modalReady(page); await selectValue(page, 'taskMoveStatus', 'blocked'); }
    else { await modalReady(page); await page.locator('#teamTaskTitle').fill('Direct context edit'); }
    const comment = context === 'chat'; const endpoint = '/clinic/teams/31/' + (comment ? 'comments' : context === 'calendar' ? 'move' : 'update');
    const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === endpoint);
    await page.getByRole('button', { name: comment ? 'Post comment' : context === 'calendar' ? 'Update status' : 'Save changes', exact: true }).click();
    const received = await response; assert.equal(received.status(), 200); const payload = await received.json();
    const expectedUrl = harness.origin + '/clinic/teams' + (comment ? '/31' : '');
    assert.deepEqual(payload.refresh, { mode: 'navigate', url: expectedUrl, history: 'push' });
    await page.waitForURL(expectedUrl);
    if (comment) { await modalReady(page); assert.equal(await page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true'); assert.match(await page.locator('.teams-comment').first().textContent(), /Context comment/); }
    else { await modalClosed(page); await page.locator('.teams-board').waitFor(); }
    const post = state.posts.filter(post => new URL(post.path, harness.origin).pathname === endpoint)[0]; assert.ok(post);
    assert.equal(post.headers['x-synapse-refresh-owner'], context === 'calendar' ? 'teams.calendar.clinic' : undefined);
    assert.equal(field(post, 'fixture_csrf')[0], post.headers['x-fixture-csrf']);
    if (!comment) assert.deepEqual(field(post, 'version'), ['7']);
  });
}

for (const [name, suffix, selector] of [['tasks', '', '.teams-board'], ['chat', '/chat', '[data-chat-log]'], ['calendar', '/calendar?date=2030-01-31', '.team-calendar-grid']]) {
  test(`browser smoke: actual ${name} loads real assets and frontend without external traffic`, { timeout: 25000 }, async t => {
    const { page, state } = await pageFor(t, suffix);
    await page.locator(selector).waitFor();
    await page.waitForFunction(() => !!window.SynapseNavigation && !!window.BalaySelect && !!window.SynapsePageLifecycle);
    if (name === 'tasks') { assert.equal(await page.locator('.teams-column').count(), 4); assert.equal(await page.locator('.teams-task').count(), 100); await page.waitForFunction(() => !!window.SynapseTeamBoard); }
    if (name === 'chat') { await page.waitForFunction(() => !!window.SynapseTeamChat && !document.querySelector('[data-team-chat]').hasAttribute('data-initial')); assert.equal(await page.locator('[data-message-id]').count(), 3); }
    if (name === 'calendar') assert.equal(await page.locator('.team-calendar-grid th').count(), 7);
    assert.equal(await page.getByRole('navigation', { name: 'Teams views', exact: true }).count(), 1);
    assert.ok(state.requests.some(request => request.method === 'GET'));
    const geometry = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth,
      workspace: (() => { const box = document.querySelector('.teams-workspace').getBoundingClientRect(); return { x: box.x, width: box.width }; })() }));
    t.diagnostic(`${name} current geometry ${JSON.stringify(geometry)}`);
  });
}
