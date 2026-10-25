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
const cache = new Map(), posts = [], allPosts = [];
const isTeamsPath = pathname => /^\/(?:clinic|guidance|facilities)\/teams(?:\/|$)/.test(pathname);
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
      const post = { path: url.pathname, body: Buffer.concat(chunks).toString('utf8') };
      allPosts.push(post);
      // Layout startup requests (notably /realtime/ticket) are not Teams mutations.
      if (!isTeamsPath(url.pathname)) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, message: 'Not provided by this Teams fixture.' }));
        return;
      }
      posts.push(post);
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
  assert.deepEqual(allPosts.filter(post => !isTeamsPath(post.path) && post.path !== '/realtime/ticket').map(post => post.path), [],
    'No unexpected writes outside Teams; only realtime ticket acquisition is background traffic');
});

async function pageFor(t, url, viewport = { width: 1280, height: 900 }, dark = false) {
  rejectSave = false;
  const context = await browser.newContext({ viewport });
  await context.addInitScript(dark => localStorage.setItem('na-dark-mode', dark ? '1' : '0'), dark);
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
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
    assert.equal(posts.length, postCount, 'Task filtering must not mutate data; observed POST paths: ' + JSON.stringify(posts.slice(postCount).map(post => post.path)));
  });
}

async function modalReady(page) {
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.getAttribute('aria-modal') === 'true' && modal.contains(document.activeElement) &&
      bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  });
}

test('task edit refreshes only board regions and preserves filters, shell and scroll', async t => {
  const page = await pageFor(t, '/guidance/teams?view=board&q=Fixture&mine=1');
  await page.waitForFunction(() => window.SynapseLiveFilters && window.SynapseTeamBoard && window.SynapseMutations);
  const identities = await page.evaluateHandle(() => [document.getElementById('main'), document.querySelector('.teams-workspace'), document.querySelector('[data-filter-form]'), document.querySelector('#teamSearch'), document.querySelector('.teams-intro')]);
  await page.getByRole('link', { name: 'Edit / move', exact: true }).click();
  await modalReady(page);
  await page.getByLabel('Title *', { exact: true }).fill('Updated task');
  const before = posts.length;
  const refresh = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/guidance/teams' && r.request().headers()['x-requested-with'] === 'XMLHttpRequest');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  const response = await refresh;
  await page.waitForFunction(() => !document.querySelector('.modal.show, .modal-backdrop') && !document.querySelector('[data-live-filters][aria-busy="true"]'));
  assert.equal(new URL(response.url()).searchParams.get('q'), 'Fixture');
  assert.equal(new URL(response.url()).searchParams.get('mine'), '1');
  assert.equal(posts.length, before + 1);
  assert.equal(await page.evaluate(nodes => nodes.every(node => node.isConnected) && nodes[1] === document.querySelector('.teams-workspace'), identities), true);
  assert.equal(await page.locator('#teamSearch').inputValue(), 'Fixture');
  await identities.dispose();
});

test('failed regional refresh keeps the saved board in place and retries GET only', async t => {
  const page = await pageFor(t, '/guidance/teams?q=Fixture');
  await page.waitForFunction(() => !!window.SynapseTeamBoard);
  const workspace = await page.locator('.teams-workspace').elementHandle();
  let failRead = true;
  await page.route('**/guidance/teams?*', async route => {
    if (route.request().headers()['x-requested-with'] === 'XMLHttpRequest' && failRead) {
      await route.fulfill({ status: 503, contentType: 'text/html', body: 'Temporarily unavailable' });
    } else await route.continue();
  });
  await page.getByRole('link', { name: 'Edit / move', exact: true }).click(); await modalReady(page);
  const before = posts.length;
  await page.getByLabel('Title *', { exact: true }).fill('Confirmed save');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.getByRole('button', { name: 'Retry refresh', exact: true }).waitFor();
  assert.equal(posts.length, before + 1);
  assert.equal(await workspace.evaluate(node => node.isConnected), true);
  failRead = false;
  const refreshed = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/guidance/teams' && r.status() === 200);
  await page.getByRole('button', { name: 'Retry refresh', exact: true }).click(); await refreshed;
  await page.waitForFunction(() => !document.querySelector('[data-live-filters][aria-busy="true"]'));
  assert.equal(posts.length, before + 1);
  assert.equal(await workspace.evaluate(node => node.isConnected), true);
});

test('keyboard card move uses production move endpoint without replacing the workspace and rebinds after filtering', async t => {
  const page = await pageFor(t, '/clinic/teams?q=Fixture');
  await page.waitForFunction(() => !!window.SynapseTeamBoard);
  const workspace = await page.locator('.teams-workspace').elementHandle();
  const before = posts.length;
  const move = async () => {
    const handle = page.locator('[data-task-drag-handle]').first();
    await handle.focus(); await handle.press('Space'); await handle.press('ArrowRight');
    const refreshed = page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === '/clinic/teams' && r.request().headers()['x-requested-with'] === 'XMLHttpRequest');
    await handle.press('Enter'); await refreshed;
    await page.waitForFunction(() => !document.querySelector('[data-live-filters][aria-busy="true"]'));
  };
  await move();
  assert.equal(posts.at(-1).path, '/clinic/teams/31/move');
  assert.equal(new URLSearchParams(posts.at(-1).body).get('status'), 'in_progress');
  assert.equal(new URLSearchParams(posts.at(-1).body).get('version'), '3');
  assert.equal(await workspace.evaluate(node => node.isConnected), true);
  // The fixture returns its original task; a second move proves the refreshed card has a fresh controller.
  await move(); assert.equal(posts.length, before + 2);
  assert.equal(new URL(page.url()).searchParams.get('q'), 'Fixture');
});

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
  const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/clinic/teams/store');
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
