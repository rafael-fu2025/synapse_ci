'use strict';

// Production templates, CSS, Bootstrap, Alpine and modal loader; no application DB/server.
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
const cache = new Map(), posts = [];
let rejectSave = false;

function render(requestPath) {
  if (!cache.has(requestPath)) {
    const result = spawnSync('php', ['tests/_support/render-teams-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: requestPath }), encoding: 'utf8', timeout: 10000
    });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /teams|teamTaskTitle/);
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
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method === 'POST') {
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      posts.push({ path: url.pathname, body: Buffer.concat(chunks).toString('utf8') });
      res.writeHead(rejectSave ? 422 : 200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: !rejectSave, message: rejectSave ? 'Fixture validation error' : 'Saved',
        redirect: origin + '/' + url.pathname.split('/')[1] + '/teams',
        csrf: { name: 'csrf_test_name', hash: 'fixture-refreshed-token' } }));
      return;
    }
    if (/^\/(clinic|guidance|facilities)\/teams(?:\/|$)/.test(url.pathname)) {
      res.writeHead(200, { 'Content-Type': 'text/html' });
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
});

async function pageFor(t, url, viewport = { width: 1280, height: 900 }, dark = false) {
  rejectSave = false;
  const context = await browser.newContext({ viewport });
  await context.addInitScript(dark => localStorage.setItem('na-dark-mode', dark ? '1' : '0'), dark);
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  page.setDefaultNavigationTimeout(20000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + url);
  return page;
}

async function taskFilterUpdate(page, action, history = false) {
  const previous = await page.locator('[data-filter-region="results"]').evaluateHandle(el => el.firstElementChild);
  const response = page.waitForResponse(response => response.request().method() === 'GET' &&
    new URL(response.url()).pathname === '/guidance/teams' &&
    (history ? response.request().resourceType() === 'fetch' : response.request().headers()['x-requested-with'] === 'XMLHttpRequest'), { timeout: 15000 });
  await action();
  const received = await response;
  assert.equal(received.status(), 200);
  assert.match(await received.text(), /<html[\s>]/i);
  await page.waitForFunction(old => !old.isConnected && !document.querySelector('[data-live-filters][aria-busy="true"]'), previous);
  await previous.dispose();
}

for (const dark of [false, true]) {
  test(`${dark ? 'dark mobile' : 'light desktop'} task live filters preserve controls and support real navigation history`, { timeout: 45000 }, async t => {
    const page = await pageFor(t, '/guidance/teams', { width: dark ? 390 : 1280, height: 900 }, dark);
    await page.waitForFunction(() => !!window.SynapseNavigation);
    const root = page.locator('[data-live-filters][data-filter-key="teams-tasks-guidance"]');
    const identities = await root.evaluateHandle(el => [el, el.querySelector('[data-filter-form]'),
      ...el.querySelectorAll('#teamSearch, #teamStatus, #teamPriority')]);
    const reads = [], postCount = posts.length;
    page.on('request', request => {
      const url = new URL(request.url());
      if (request.method() === 'GET' && url.pathname === '/guidance/teams' && request.headers()['x-requested-with'] === 'XMLHttpRequest') {
        assert.equal(url.searchParams.has('fragment'), false);
        reads.push(url);
      }
    });
    await taskFilterUpdate(page, async () => {
      await page.locator('#teamSearch').fill('Fix');
      await page.locator('#teamSearch').fill('Fixture');
    });
    assert.equal(reads.length, 1, 'Search input is debounced');
    assert.equal(reads[0].searchParams.get('q'), 'Fixture');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Fixture');
    assert.equal(await page.locator('#teamSearch').evaluate(el => el === document.activeElement), true);
    // This fixture keeps its one task and fixed status/priority defaults; verify
    // outgoing filters rather than inventing server-side task filtering here.
    await root.getByRole('combobox', { name: /^Status:/ }).click();
    await taskFilterUpdate(page, () => root.getByRole('option', { name: 'Blocked', exact: true }).click());
    assert.equal(reads.at(-1).searchParams.get('status'), 'blocked');
    await root.getByRole('combobox', { name: /^Priority:/ }).click();
    await taskFilterUpdate(page, () => root.getByRole('option', { name: 'High', exact: true }).click());
    assert.equal(reads.at(-1).searchParams.get('priority'), 'high');
    await taskFilterUpdate(page, () => page.getByRole('link', { name: 'List view', exact: true }).click());
    await root.locator('table tbody tr').waitFor();
    assert.match(await root.locator('table tbody').innerText(), /Fixture task/);
    assert.equal(await root.locator('.teams-board').count(), 0);
    assert.equal(await root.locator('[data-filter-form] [name="view"]').inputValue(), 'list');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Fixture');
    await taskFilterUpdate(page, () => root.locator('[data-filter-reset]').click());
    assert.equal(await page.locator('#teamSearch').inputValue(), '');
    assert.equal(await root.locator('#teamStatus').inputValue(), '');
    assert.equal(await root.locator('#teamPriority').inputValue(), '');
    assert.equal(await page.evaluate(nodes => nodes.every(node => node.isConnected) &&
      nodes[0] === document.querySelector('[data-live-filters]') && nodes[1] === document.querySelector('[data-filter-form]'), identities), true);
    await identities.dispose();
    await taskFilterUpdate(page, () => page.goBack(), true);
    await page.locator('[data-filter-region="results"] table tbody tr').waitFor();
    assert.equal(await page.locator('#teamSearch').inputValue(), 'Fixture');
    await taskFilterUpdate(page, () => page.goForward(), true);
    await page.locator('[data-filter-region="results"] table tbody tr').waitFor();
    assert.equal(await page.locator('#teamSearch').inputValue(), '');
    assert.equal(reads.length, 5, 'Exactly one XHR for each filter action, no Apply click');
    assert.equal(posts.length, postCount);
  });
}

async function modalReady(page) {
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.getAttribute('aria-modal') === 'true' && modal.contains(document.activeElement) &&
      bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  });
}

async function checklist(page) {
  return JSON.parse(await page.locator('#appFormModal input[name="checklist"]').inputValue());
}

test('new task modal initializes Alpine, enforces checklist limit and submits edited JSON', async t => {
  const page = await pageFor(t, '/clinic/teams');
  await page.getByRole('link', { name: 'New task', exact: true }).click();
  await modalReady(page);
  assert.equal(await page.locator('#appFormModal').count(), 1);
  assert.deepEqual(await checklist(page), []);
  const add = page.getByRole('button', { name: 'Add checklist item' });
  await add.click();
  await page.getByRole('textbox', { name: 'Checklist item 1', exact: true }).fill('First <script>literal</script>');
  await page.getByRole('checkbox', { name: 'Complete checklist item 1', exact: true }).check();
  await add.click();
  await page.getByRole('textbox', { name: 'Checklist item 2', exact: true }).fill('Second');
  await page.getByRole('button', { name: 'Remove checklist item 1', exact: true }).click();
  assert.equal(await page.getByRole('textbox', { name: 'Checklist item 1', exact: true }).inputValue(), 'Second');
  for (let i = 1; i < 20; i++) await add.click();
  assert.equal(await add.isDisabled(), true);
  assert.equal((await checklist(page)).length, 20);
  for (let i = 20; i > 1; i--) await page.getByRole('button', { name: `Remove checklist item ${i}`, exact: true }).click();
  assert.equal(await add.isEnabled(), true);
  await page.getByLabel('Title *', { exact: true }).fill('Browser task');
  rejectSave = true;
  const response = page.waitForResponse(r => r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Create task', exact: true }).click();
  await response;
  await page.locator('[data-modal-error]:not(.d-none)').waitFor();
  assert.equal(await page.locator('[data-modal-error]').innerText(), 'Fixture validation error');
  assert.match(posts.at(-1).body, /name="checklist"\r\n\r\n\[{"text":"Second","done":false}\]/);
  assert.match(posts.at(-1).path, /^\/clinic\/teams\/store$/);
  assert.deepEqual(await checklist(page), [{ text: 'Second', done: false }]);
  assert.equal(await page.locator('#appFormModal [name="csrf_test_name"]').inputValue(), 'fixture-refreshed-token');
});

test('board/list links retain filters and repeated fragment opens initialize stored checklist once', async t => {
  const page = await pageFor(t, '/guidance/teams?q=Fixture');
  assert.equal(await page.locator('.teams-column').count(), 4);
  assert.equal(await page.locator('.teams-board').evaluate(el => getComputedStyle(el).display), 'grid');
  const list = page.getByRole('link', { name: 'List view', exact: true });
  assert.equal(new URL(await list.getAttribute('href')).searchParams.get('q'), 'Fixture');
  await list.click();
  await page.locator('table tbody tr').waitFor();
  assert.equal(new URL(page.url()).searchParams.get('view'), 'list');
  for (let iteration = 0; iteration < 2; iteration++) {
    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    await modalReady(page);
    await page.getByRole('textbox', { name: 'Checklist item 2', exact: true }).waitFor();
    assert.equal((await checklist(page)).length, 2);
    assert.equal(await page.getByRole('checkbox', { name: 'Complete checklist item 1', exact: true }).isChecked(), true);
    assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0);
    assert.equal(await page.evaluate(() => window.teamXss), undefined);
    await page.getByRole('button', { name: 'Add checklist item' }).click();
    assert.equal((await checklist(page)).length, 3);
    await page.locator('#appFormModal a[data-app-modal-close]').click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
  }
  await page.locator('table tbody a[data-app-modal-open]').first().click();
  await modalReady(page);
  assert.match(await page.locator('.teams-comment').innerText(), /Comment <img/);
  assert.equal(await page.locator('#appFormModal img[src="x"]').count(), 0);
});

test('direct mobile facilities form opens modal with initialized stored values', async t => {
  const page = await pageFor(t, '/facilities/teams/31/edit', { width: 390, height: 844 });
  await modalReady(page);
  await page.getByRole('textbox', { name: 'Checklist item 2', exact: true }).waitFor();
  assert.equal(await page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true');
  assert.equal(await page.locator('#appFormModal [name="version"]').inputValue(), '3');
  assert.equal(await page.locator('#teamTaskDue').inputValue(), '2028-02-29');
  assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + '/facilities/teams/31/update');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  assert.equal(await page.evaluate(() => window.teamXss), undefined);
});
