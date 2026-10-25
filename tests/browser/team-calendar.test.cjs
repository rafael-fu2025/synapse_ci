'use strict';

// Production templates, styles and scripts; fixture API only, no DB or new packages.
// Run: node --test tests/browser/team-calendar.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const cache = new Map();
const posts = [], allPosts = [];
const isTeamsPath = pathname => /^\/(?:clinic|guidance|facilities)\/teams(?:\/|$)/.test(pathname);
let rejectSave = false;

function render(requestPath) {
  if (!cache.has(requestPath)) {
    const result = spawnSync('php', ['tests/_support/render-team-calendar-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: requestPath }), encoding: 'utf8', timeout: 10000
    });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /team-calendar|appFormModal/);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error/);
    cache.set(requestPath, result.stdout);
  }
  return cache.get(requestPath);
}

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath, 'Use the existing Playwright Chromium installation');
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method === 'POST') {
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const post = { path: url.pathname, body: Buffer.concat(chunks).toString('utf8'), headers: req.headers };
      allPosts.push(post);
      // Layout startup requests (notably /realtime/ticket) are not Teams mutations.
      if (!isTeamsPath(url.pathname)) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, message: 'Not provided by this calendar fixture.' }));
        return;
      }
      posts.push(post);
      res.writeHead(rejectSave ? 422 : 200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: !rejectSave, message: rejectSave ? 'Fixture validation error' : 'Saved',
        redirect: origin + '/' + url.pathname.split('/')[1] + '/teams/calendar?date=2026-10-01',
        csrf: { name: 'csrf_test_name', hash: 'calendar-refreshed-token' } }));
      return;
    }
    if (/^\/(clinic|guidance|facilities)\/teams\/calendar(?:\/|$)/.test(url.pathname)) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(render(url.pathname + url.search));
      return;
    }
    const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + url.pathname);
    if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });

after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
  assert.deepEqual(allPosts.filter(post => !isTeamsPath(post.path) && post.path !== '/realtime/ticket').map(post => post.path), [],
    'No unexpected writes outside Teams; only realtime ticket acquisition is background traffic');
});

async function pageFor(t, url, { width = 1280, dark = false } = {}) {
  rejectSave = false;
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  await context.addInitScript(dark => localStorage.setItem('na-dark-mode', dark ? '1' : '0'), dark);
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  page.on('dialog', async dialog => { errors.push('Unexpected native dialog: ' + dialog.message()); await dialog.dismiss(); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + url);
  return page;
}

async function modalReady(page) {
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.getAttribute('aria-modal') === 'true' && modal.contains(document.activeElement) &&
      bootstrap.Modal.getInstance(modal)?._focustrap?._isActive &&
      modal.querySelector('form[data-modal-submit]');
  });
}

async function pickers(page, edit = false) {
  await page.locator('#appFormModal .dtp-wrap').nth(1).waitFor();
  assert.equal(await page.locator('#appFormModal .dtp-wrap').count(), 2);
  assert.equal(await page.locator('#teamEventStart').inputValue(), edit ? '2026-10-01T09:15' : '2026-10-01T09:00');
  assert.equal(await page.locator('#teamEventEnd').inputValue(), edit ? '2026-10-03T00:00' : '2026-10-01T10:00');
  assert.equal(await page.locator('#teamEventStartDisplay').inputValue(), edit ? '01/10/2026 09:15 AM' : '01/10/2026 09:00 AM');
  assert.equal(await page.locator('#teamEventEndDisplay').inputValue(), edit ? '03/10/2026 12:00 AM' : '01/10/2026 10:00 AM');
}

function field(post, name) {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return [...post.body.matchAll(new RegExp(`name="${escaped}"\\r\\n\\r\\n([^\\r]*)`, 'g'))].map(match => match[1]);
}

async function noOverflow(page) {
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true,
    'The grid may scroll locally, but the document must not overflow');
}

test('month/week/agenda links and filters retain date, mine, status and item destinations', async t => {
  const page = await pageFor(t, '/guidance/teams/calendar?date=2026-10-01&mine=1&status=all');
  assert.equal(await page.locator('.team-calendar-grid').count(), 1);
  assert.equal(await page.getByRole('link', { name: 'Team board', exact: true }).getAttribute('href'), origin + '/guidance/teams');
  assert.equal(await page.getByRole('link', { name: 'My tasks', exact: true }).getAttribute('href'), origin + '/guidance/teams?mine=1&view=list');
  for (const label of ['Month', 'Week', 'Agenda', 'Previous calendar period', 'Next calendar period', 'Today']) {
    const link = page.getByRole('link', { name: label, exact: true });
    const url = new URL(await link.getAttribute('href'));
    assert.equal(url.searchParams.get('mine'), '1');
    assert.equal(url.searchParams.get('status'), 'all');
    assert.ok(url.searchParams.get('date'));
    if (['Month', 'Week', 'Agenda'].includes(label)) assert.equal(url.searchParams.get('date'), '2026-10-01');
  }
  await page.getByRole('link', { name: 'Week', exact: true }).click();
  await page.locator('.team-calendar-grid.is-week').waitFor();
  await page.getByRole('link', { name: 'Agenda', exact: true }).click();
  await page.locator('.team-calendar-agenda').waitFor();
  assert.equal(await page.locator('.team-calendar-grid').count(), 0);
  assert.equal(await page.locator('.team-calendar-item.is-event').first().getAttribute('href'), origin + '/guidance/teams/calendar/events/41');
  assert.equal(await page.locator('.team-calendar-item.is-task').getAttribute('href'), origin + '/guidance/teams/31');
  await page.locator('#calendarScope').selectOption('');
  await page.locator('#calendarEventStatus').selectOption('cancelled');
  await page.waitForURL(url => url.searchParams.get('status') === 'cancelled');
  assert.equal(new URL(page.url()).searchParams.get('view'), 'agenda');
  assert.equal(new URL(page.url()).searchParams.get('mine'), '');
  assert.equal(new URL(page.url()).searchParams.get('date'), '2026-10-01');
});

for (const dark of [false, true]) {
  test(`${dark ? 'dark mobile' : 'light desktop'} calendar filters update live and retain selected-date actions`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, '/guidance/teams/calendar?date=2026-10-01', { width: dark ? 390 : 1280, dark });
    await page.waitForFunction(() => !!window.SynapseNavigation);
    const original = await page.locator('[data-filter-form]').evaluateHandle(form => [form, form.querySelector('#calendarDateDisplay')]);
    const postCount = posts.length;
    async function update(action) {
      const old = await page.locator('#calendarHeading').evaluateHandle(element => element);
      const response = page.waitForResponse(response => response.request().method() === 'GET' && response.request().headers()['x-requested-with'] === 'XMLHttpRequest' && new URL(response.url()).pathname === '/guidance/teams/calendar');
      await action();
      assert.equal((await response).status(), 200);
      await page.waitForFunction(element => !element.isConnected && !document.querySelector('[data-live-filters][aria-busy="true"]'), old);
      await old.dispose();
    }
    await update(() => page.locator('#calendarScope').selectOption('1'));
    assert.equal(new URL(page.url()).searchParams.get('mine'), '1');
    await update(() => page.locator('#calendarEventStatus').selectOption('all'));
    assert.equal(new URL(page.url()).searchParams.get('status'), 'all');
    await update(async () => {
      await page.locator('#calendarDateDisplay').fill('02/10/2026');
      await page.locator('#calendarDateDisplay').blur();
    });
    assert.equal(await page.locator('#calendarDate').inputValue(), '2026-10-02');
    assert.equal(new URL(page.url()).searchParams.get('date'), '2026-10-02');
    assert.equal(new URL(await page.getByRole('link', { name: 'New event', exact: true }).getAttribute('href')).searchParams.get('date'), '2026-10-02');
    await update(() => page.getByRole('link', { name: 'Week', exact: true }).click());
    assert.equal(await page.locator('.team-calendar-grid.is-week').count(), 1);
    assert.equal(await page.locator('[data-filter-form] [name="view"]').inputValue(), 'week');
    assert.equal(await page.evaluate(nodes => nodes[0] === document.querySelector('[data-filter-form]') && nodes[1] === document.querySelector('#calendarDateDisplay'), original), true);
    await original.dispose();
    assert.equal(posts.length, postCount, 'Calendar filtering never sends a write; observed POST paths: ' + JSON.stringify(posts.slice(postCount).map(post => post.path)));
  });
}

test('fragment create initializes both pickers; selected participants submit and 422 retains all input', async t => {
  const page = await pageFor(t, '/clinic/teams/calendar?date=2026-10-01');
  const fragment = page.waitForResponse(r => r.url().includes('/events/create') && r.url().includes('fragment=1'));
  await page.getByRole('link', { name: 'New event', exact: true }).click();
  assert.doesNotMatch(await (await fragment).text(), /<html|<script/);
  await modalReady(page);
  await pickers(page);
  await page.locator('#teamEventStartDisplay').click();
  await page.locator('.dtp-popup:not([hidden]) .dtp-minutes-wheel [data-val="30"]').click();
  await page.locator('.dtp-popup:not([hidden]) [data-dtp-apply]').click();
  await page.locator('#teamEventEndDisplay').click();
  await page.locator('.dtp-popup:not([hidden]) .dtp-hours-wheel [data-val="11"]').click();
  await page.locator('.dtp-popup:not([hidden]) [data-dtp-apply]').click();
  await page.locator('#teamEventTitle').fill('Browser internal event');
  await page.locator('#teamEventDescription').fill('Keep description <script>literal</script>');
  await page.locator('#teamEventLocation').fill('Room 2');
  await page.locator('#eventMember2').check();
  rejectSave = true;
  const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/clinic/teams/calendar/events/store');
  await page.getByRole('button', { name: 'Create event', exact: true }).click();
  assert.equal((await response).status(), 422);
  await page.locator('[data-modal-error]:not(.d-none)').waitFor();
  const post = posts.at(-1);
  assert.equal(post.path, '/clinic/teams/calendar/events/store');
  assert.deepEqual(field(post, 'participant_ids[]'), ['2']);
  assert.deepEqual(field(post, 'starts_at'), ['2026-10-01T09:30']);
  assert.deepEqual(field(post, 'ends_at'), ['2026-10-01T11:00']);
  assert.deepEqual(field(post, 'title'), ['Browser internal event']);
  assert.ok(field(post, 'csrf_test_name')[0]);
  assert.equal(post.headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(await page.locator('[data-modal-error]').innerText(), 'Fixture validation error');
  assert.equal(await page.locator('#teamEventTitle').inputValue(), 'Browser internal event');
  assert.equal(await page.locator('#teamEventDescription').inputValue(), 'Keep description <script>literal</script>');
  assert.equal(await page.locator('#teamEventLocation').inputValue(), 'Room 2');
  assert.equal(await page.locator('#eventMember2').isChecked(), true);
  assert.equal(await page.locator('#teamEventStart').inputValue(), '2026-10-01T09:30');
  assert.equal(await page.locator('#teamEventEnd').inputValue(), '2026-10-01T11:00');
  assert.equal(await page.locator('#appFormModal [name="csrf_test_name"]').inputValue(), 'calendar-refreshed-token');
  assert.equal(await page.getByRole('button', { name: 'Create event', exact: true }).isEnabled(), true);
  rejectSave = false;
  await page.getByRole('button', { name: 'Create event', exact: true }).click();
  await page.waitForURL(origin + '/clinic/teams/calendar?date=2026-10-01');
  await page.waitForFunction(() => !document.querySelector('#appFormModal'));
});

test('repeated edit fragments initialize stored values once and submit version and changed participants', async t => {
  const page = await pageFor(t, '/guidance/teams/calendar?date=2026-10-01');
  for (let iteration = 0; iteration < 2; iteration++) {
    // Exercise edit fragments through the real loader; the production detail's
    // Edit link intentionally navigates directly (covered separately below).
    await page.locator('.team-calendar-item.is-event').first().evaluate(el => { el.href += '/edit'; });
    await page.locator('.team-calendar-item.is-event').first().click();
    await modalReady(page);
    await pickers(page, true);
    assert.equal(await page.locator('#appFormModal').count(), 1);
    assert.equal(await page.locator('#appFormModal [name="version"]').inputValue(), '3');
    assert.equal(await page.locator('#eventMember2').isChecked(), true);
    assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0);
    assert.equal(await page.evaluate(() => window.calendarXss), undefined);
    if (!iteration) {
      await page.locator('#appFormModal a[data-app-modal-close]').click();
      await page.waitForFunction(() => !document.querySelector('#appFormModal'));
      await page.locator('.team-calendar-item.is-event').first().evaluate(el => { el.href = el.href.replace(/\/edit$/, ''); });
    } else {
      await page.locator('#eventMember2').uncheck();
      await page.locator('#eventMember1').check();
      rejectSave = true;
      const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/guidance/teams/calendar/events/41/update');
      await page.getByRole('button', { name: 'Save changes', exact: true }).click();
      await response;
      await page.locator('[data-modal-error]:not(.d-none)').waitFor();
      assert.equal(posts.at(-1).path, '/guidance/teams/calendar/events/41/update');
      assert.deepEqual(field(posts.at(-1), 'version'), ['3']);
      assert.deepEqual(field(posts.at(-1), 'participant_ids[]'), ['1']);
    }
  }
});

test('event detail links to edit and cancellation waits for shared confirmation, decline never posts', async t => {
  const page = await pageFor(t, '/clinic/teams/calendar?date=2026-10-01');
  await page.locator('.team-calendar-item.is-event').first().click();
  await modalReady(page);
  assert.equal(await page.getByRole('link', { name: 'Edit event', exact: true }).getAttribute('href'), origin + '/clinic/teams/calendar/events/41/edit');
  assert.match(await page.locator('#appFormModal').innerText(), /Participants \(1\)/);
  assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0);
  const count = posts.length;
  await page.locator('#appFormModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(posts.length, count);
  assert.equal(await page.locator('[data-confirm-title-text]').innerText(), 'Cancel team event');
  assert.match(await page.locator('[data-confirm-message]').innerText(), /Patient appointments are unaffected/);
  await page.locator('[data-confirm-cancel]').click();
  await page.waitForFunction(() => document.querySelector('#synapseConfirmModal')?.getAttribute('aria-hidden') === 'true' && !document.querySelector('#appFormModal')?.inert);
  assert.equal(posts.length, count);
  assert.equal(await page.locator('#appFormModal').isVisible(), true);
  assert.equal(await page.locator('#appFormModal').evaluate(el => el.inert), false);
  assert.equal(await page.evaluate(() => document.body.classList.contains('modal-open')), true);
  assert.equal(await page.locator('#appFormModal').evaluate(el => el.contains(document.activeElement)), true);
});

test('read-only event detail opens as a fragment without requiring an edit form', async t => {
  const page = await pageFor(t, '/clinic/teams/calendar?date=2026-10-01');
  await page.locator('.team-calendar-item.is-event').first().evaluate(el => { el.href += '?readonly=1'; });
  await page.locator('.team-calendar-item.is-event').first().click();
  await page.locator('#appFormModal .teams-preserve').waitFor();
  assert.equal(await page.locator('#appFormModal form').count(), 0);
  assert.equal(await page.getByRole('link', { name: 'Edit event', exact: true }).count(), 0);
  assert.equal(await page.locator('#appFormModal [data-modal-error]:not(.d-none)').count(), 0);
  await page.locator('#appFormModal a[data-app-modal-close]').click();
  await page.waitForFunction(() => !document.querySelector('#appFormModal'));
});

test('accepting shared cancellation confirmation posts exactly one versioned CSRF form', async t => {
  const page = await pageFor(t, '/clinic/teams/calendar?date=2026-10-01');
  await page.locator('.team-calendar-item.is-event').first().click();
  await modalReady(page);
  const count = posts.length;
  await page.locator('#appFormModal button[type="submit"]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(posts.length, count, 'No mutation before confirmation; observed POST paths: ' + JSON.stringify(posts.slice(count).map(post => post.path)));
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(origin + '/clinic/teams/calendar?date=2026-10-01');
  await page.waitForFunction(() => !document.querySelector('#appFormModal'));
  assert.equal(posts.length, count + 1, 'Exactly one cancellation; observed POST paths: ' + JSON.stringify(posts.slice(count).map(post => post.path)));
  assert.equal(posts.at(-1).path, '/clinic/teams/calendar/events/41/cancel');
  assert.deepEqual(field(posts.at(-1), 'version'), ['3']);
  assert.ok(field(posts.at(-1), 'csrf_test_name')[0]);
});

test('detail edit navigation opens direct facilities modal with stored midnight values and no XSS', async t => {
  const page = await pageFor(t, '/facilities/teams/calendar/events/41', { width: 390, dark: true });
  await modalReady(page);
  await page.getByRole('link', { name: 'Edit event', exact: true }).click();
  await page.waitForURL(origin + '/facilities/teams/calendar/events/41/edit');
  await modalReady(page);
  await pickers(page, true);
  assert.equal(await page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true');
  assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + '/facilities/teams/calendar/events/41/update');
  assert.equal(await page.locator('#eventMember2').isChecked(), true);
  assert.equal(await page.evaluate(() => window.calendarXss), undefined);
  await noOverflow(page);
});

test('direct edit independently initializes both stored pickers on narrow dark screen', async t => {
  const page = await pageFor(t, '/facilities/teams/calendar/events/41/edit', { width: 390, dark: true });
  await modalReady(page);
  await pickers(page, true);
  assert.equal(await page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true');
  assert.equal(await page.locator('#appFormModal [name="version"]').inputValue(), '3');
  assert.equal(await page.locator('#eventMember2').isChecked(), true);
  assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0);
  assert.equal(await page.evaluate(() => window.calendarXss), undefined);
  assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
  await noOverflow(page);
});

for (const dark of [false, true]) {
  test(`narrow ${dark ? 'dark' : 'light'} calendar confines overflow to grid in all views and create modal`, async t => {
    const page = await pageFor(t, '/facilities/teams/calendar?date=2026-10-01', { width: 390, dark });
    assert.equal(await page.locator('html').getAttribute('data-theme'), dark ? 'dark' : 'light');
    for (const mode of ['Month', 'Week', 'Agenda']) {
      await page.getByRole('link', { name: mode, exact: true }).click();
      await page.waitForURL(url => url.searchParams.get('view') === mode.toLowerCase());
      await noOverflow(page);
      if (mode !== 'Agenda') {
        assert.equal(await page.locator('.team-calendar-scroll').evaluate(el => el.scrollWidth > el.clientWidth && getComputedStyle(el).overflowX === 'auto'), true);
      }
    }
    await page.getByRole('link', { name: 'New event', exact: true }).click();
    await modalReady(page);
    await pickers(page);
    await noOverflow(page);
  });
}
