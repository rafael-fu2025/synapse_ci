'use strict';

// Actual resource views/assets; loopback synthetic arrays and one RAM-only batch response.
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
const screenshotDir = path.join(root, 'writable/resource-history-preview');
const reads = [], posts = [], blocked = [];
let browser, server, origin, refreshRevision = 0;

function render(url) {
  const result = spawnSync('php', [path.join(root, 'tests/_support/render-resource-history-fixture.php')], {
    cwd: root, env: { ...process.env, PAGINATION_ISOLATED_TESTS: '1' },
    input: JSON.stringify({ origin, path: url.pathname + url.search, refreshRevision }),
    encoding: 'utf8', timeout: 10000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
  return result.stdout;
}

before(async () => {
  fs.mkdirSync(screenshotDir, { recursive: true });
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin);
      if (req.method === 'POST') {
        // This route never calls PHP or a controller and cannot affect a runtime record.
        if (url.pathname !== '/facilities/batch/500/release') {
          blocked.push({ method: req.method, path: url.pathname });
          return res.writeHead(405).end('Synthetic read-only route');
        }
        let body = ''; for await (const chunk of req) body += chunk;
        const data = await new Request(origin + req.url, { method: 'POST', body,
          headers: { 'Content-Type': req.headers['content-type'] } }).formData();
        posts.push({ path: url.pathname, fields: Object.fromEntries(data), headers: req.headers });
        const hint = new URL(req.headers['x-synapse-refresh-url'] || origin + '/facilities/unit/11');
        assert.equal(hint.origin, origin);
        assert.equal(hint.pathname, '/facilities/unit/11');
        refreshRevision++;
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ success: true, message: 'Synthetic bookkeeping response only', redirect: hint.href,
          refresh: { mode: 'regions', owner: 'facilities.unit.11', regions: ['unit'], url: hint.href } }));
      }
      if (req.method !== 'GET') {
        blocked.push({ method: req.method, path: url.pathname });
        return res.writeHead(405).end();
      }
      if (['/', '/clinic/teams/11', '/facilities/unit/11', '/clinic/inventory/11'].includes(url.pathname)) {
        reads.push({ url: url.href, headers: req.headers });
        const html = render(url);
        if (url.pathname === '/clinic/teams/11' && url.searchParams.get('fragment') === '1') {
          assert.match(html, /data-app-form-modal/);
          assert.doesNotMatch(html, /<script\b|<iframe\b|<object\b|<embed\b|<base\b/i,
            'Actual task history fragment must pass the shared modal content validator');
        }
        return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }).end(html);
      }
      const allowedImages = ['/drum-maroon.png', '/drum-whte.png'];
      if (!url.pathname.startsWith('/assets/') && !allowedImages.includes(url.pathname)) return res.writeHead(404).end();
      const file = path.resolve(root, 'public', '.' + url.pathname);
      const allowedRoot = path.join(root, 'public', 'assets') + path.sep;
      if ((!file.startsWith(allowedRoot) && !allowedImages.includes(url.pathname))
          || !fs.existsSync(file) || !fs.statSync(file).isFile()) return res.writeHead(404).end();
      const type = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css'
        : file.endsWith('.png') ? 'image/png' : file.endsWith('.woff2') ? 'font/woff2' : 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type });
      fs.createReadStream(file).pipe(res);
    } catch (error) { res.writeHead(500).end(String(error.stack)); }
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const installed = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(installed).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(installed, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Use an existing Chromium installation; this test never installs dependencies.');
  browser = await chromium.launch({ executablePath, headless: true, timeout: 20000 });
});

after(async () => {
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  assert.deepEqual(blocked, [], 'No unexpected writes or methods');
  assert.equal(posts.length, 1, 'Only the explicit RAM-only facilities response is permitted');
});

async function pageFor(t, url) {
  const context = await browser.newContext({ viewport: { width: 1360, height: 950 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage();
  page.setDefaultTimeout(7000);
  page.setDefaultNavigationTimeout(15000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'No uncaught browser errors'); });
  const response = await page.goto(origin + url);
  assert.equal(response.status(), 200, await response.text());
  return page;
}
async function image(page, name, focus) {
  if (focus) await focus.scrollIntoViewIfNeeded();
  const file = path.join(screenshotDir, name + '.png');
  await page.screenshot({ path: file });
  console.log('Resource history screenshot: ' + file);
}
function state(url) {
  return Object.fromEntries(new URL(url).searchParams);
}
async function assertSiblingLinks(nav, expected) {
  const links = nav.getByRole('link');
  assert.ok(await links.count());
  for (const link of await links.all()) {
    const query = state(await link.getAttribute('href'));
    for (const [key, value] of Object.entries(expected)) assert.equal(query[key], String(value));
  }
}

test('actual task modal browses comments and activity without closing or executing fetched scripts', async t => {
  const page = await pageFor(t, '/');
  await page.getByRole('link', { name: 'Open task history', exact: true }).waitFor();
  await image(page, 't1-entry');
  await page.getByRole('link', { name: 'Open task history', exact: true }).click();
  const modal = page.locator('#appFormModal');
  await modal.getByRole('heading', { name: 'Actual task history fixture', exact: true }).waitFor();
  assert.equal(await modal.locator('.teams-comment').count(), 20);
  assert.match(await modal.locator('.teams-comment').first().innerText(), /Comment 121/);
  const entry = page.url();
  await image(page, 't1-task-initial');
  const discussion = modal.getByRole('navigation', { name: 'Task discussion pages', exact: true });
  assert.equal(await discussion.getByRole('link', { name: 'Last', exact: true }).getAttribute('data-app-modal-page'), '');
  await discussion.getByRole('link', { name: 'Last', exact: true }).click();
  await modal.getByText('Comment 1', { exact: true }).waitFor();
  assert.equal(await modal.locator('.teams-comment').count(), 1);
  assert.equal(await modal.locator('[data-modal-error]:not(.d-none)').count(), 0);
  assert.equal(page.url(), entry);
  await image(page, 't1-comments-last');
  await modal.locator('summary').getByText('Activity history (111)', { exact: true }).click();
  await modal.getByRole('navigation', { name: 'Task activity pages', exact: true }).waitFor();
  assert.equal(await modal.locator('.teams-activity li').count(), 20);
  await image(page, 't1-activity-expanded');
  await modal.getByRole('navigation', { name: 'Task activity pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  await modal.getByText('Activity actor 91', { exact: true }).waitFor();
  assert.equal(await modal.getByText('Comment 1', { exact: true }).count(), 1);
  assert.equal(await modal.locator('details[open]').count(), 1);
  assert.equal(page.url(), entry);
  await image(page, 't1-activity-next');
  await modal.getByRole('navigation', { name: 'Task discussion pages', exact: true }).getByRole('link', { name: 'First', exact: true }).click();
  await modal.getByText('Comment 121', { exact: true }).waitFor();
  assert.equal(await modal.getByText('Activity actor 91', { exact: true }).count(), 1);
  assert.equal(page.url(), entry);
  await image(page, 't1-comments-first');
  assert.equal(posts.length, 0, 'Task paging is GET-only');
  const historyReads = reads.filter(read => new URL(read.url).pathname === '/clinic/teams/11');
  assert.equal(historyReads.length, 4);
  assert.equal(state(historyReads[2].url).comments_page, '7');
  assert.equal(state(historyReads[2].url).activity_page, '2');
});

test('actual drum pagers keep siblings and send canonical cursor headers to a RAM-only refresh stub', async t => {
  refreshRevision = 0;
  const page = await pageFor(t, '/facilities/unit/11?commands_page=2&commands_per_page=20&runs_page=2&runs_per_page=25&batches_page=2&batches_per_page=50');
  const commands = () => page.getByRole('navigation', { name: 'Drum command history pages', exact: true });
  const runs = () => page.getByRole('navigation', { name: 'Drum motor event pages', exact: true });
  const batches = () => page.getByRole('navigation', { name: 'Drum past batch pages', exact: true });
  await commands().waitFor();
  await assertSiblingLinks(commands(), { runs_page: 2, runs_per_page: 25, batches_page: 2, batches_per_page: 50 });
  await image(page, 't2-drum-initial');
  await commands().getByRole('link', { name: 'Last', exact: true }).click();
  await commands().getByText('4', { exact: true }).waitFor();
  const commandRows = page.locator('section[aria-labelledby="bmg-command-history-title"] tbody tr');
  console.log('Drum last-page rows: ' + JSON.stringify(await commandRows.allTextContents()));
  assert.match(await commandRows.last().innerText(), /Command 1\b/);
  assert.equal(state(page.url()).runs_page, '2');
  await image(page, 't2-command-last');
  await runs().getByRole('link', { name: 'First', exact: true }).click();
  await page.getByText('Report 76', { exact: true }).waitFor();
  assert.equal(state(page.url()).commands_page, '4');
  await image(page, 't2-runs-first', runs());
  await batches().getByRole('link', { name: 'Last', exact: true }).click();
  try { await page.getByText('PAST-1', { exact: true }).waitFor(); }
  catch (error) {
    await image(page, 't2-paging-failure');
    console.log('Resource paging retained state: ' + JSON.stringify(await page.locator('[data-bmg-base-url]').evaluate(root => ({
      location: location.href, navigating: document.body.classList.contains('is-navigating'),
      focus: document.activeElement?.outerHTML, refresh: root.dataset.refreshUrl,
      status: document.getElementById('navigation-status')?.textContent,
      tabs: [...root.querySelectorAll('[data-table-pagination]')].map(meta => ({ page: meta.dataset.page, param: meta.dataset.pageParam })),
    }))));
    console.log('Resource paging last reads: ' + JSON.stringify(reads.slice(-4)));
    throw error;
  }
  assert.equal(state(page.url()).commands_page, '4');
  assert.equal(state(page.url()).runs_page, '1');
  assert.equal(await page.locator('#bmg-batch [data-table-pagination]').count(), 0);
  await image(page, 't2-batches-last', batches());
  await page.getByRole('button', { name: 'Complete batch', exact: true }).click();
  const complete = page.locator('#completeBatchModal');
  await complete.getByRole('button', { name: 'Complete batch', exact: true }).waitFor();
  await image(page, 't2-complete-dialog');
  const before = posts.length;
  await complete.getByRole('button', { name: 'Complete batch', exact: true }).click();
  await page.getByRole('heading', { name: 'Fixture drum · Refreshed 1', exact: true }).waitFor();
  assert.equal(posts.length, before + 1);
  assert.equal(posts.at(-1).headers['x-synapse-refresh-owner'], 'facilities.unit.11');
  assert.deepEqual(state(posts.at(-1).headers['x-synapse-refresh-url']), {
    commands_page: '4', commands_per_page: '20', runs_page: '1', runs_per_page: '25', batches_page: '3', batches_per_page: '50',
  });
  assert.equal(await page.locator('#bmg-history [data-table-pagination][data-page="4"]').count(), 1);
  assert.equal(await page.locator('#bmg-history [data-table-pagination][data-page="3"]').count(), 1);
  assert.equal(await page.locator('#completeBatchModal.show').count(), 0);
  await image(page, 't2-refreshed-cursors', batches());
});

test('actual inventory operations, movements and movement-size form retain independent cursors', async t => {
  const page = await pageFor(t, '/clinic/inventory/11?page=2&per_page=50&operations_page=2&operations_per_page=100');
  const operations = () => page.getByRole('navigation', { name: 'Inventory operation pages', exact: true });
  const movements = () => page.getByRole('navigation', { name: 'Stock movement pages', exact: true });
  await operations().waitFor();
  await assertSiblingLinks(operations(), { page: 2, per_page: 50 });
  await assertSiblingLinks(movements(), { operations_page: 2, operations_per_page: 100 });
  await image(page, 't3-inventory-initial');
  await operations().getByRole('link', { name: 'Last', exact: true }).click();
  await page.locator('#inventory-operation-1').waitFor();
  assert.equal(state(page.url()).page, '2');
  assert.equal(await page.locator('#inventory-operations tbody tr').count(), 31);
  await image(page, 't3-operations-last', operations());
  await movements().getByRole('link', { name: 'Last', exact: true }).click();
  await page.locator('#inventory-movement-1').waitFor();
  assert.equal(state(page.url()).operations_page, '3');
  assert.equal(await page.locator('#inventory-movements tbody tr').count(), 41);
  await image(page, 't3-movements-last', movements());
  await page.getByRole('combobox', { name: 'Movements per page', exact: true }).selectOption('25');
  assert.equal(await page.getByRole('combobox', { name: 'Movements per page', exact: true }).inputValue(), '25');
  await image(page, 't3-movement-size-selected', movements());
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await page.locator('#inventory-movement-141').waitFor();
  assert.equal(state(page.url()).operations_page, '3');
  assert.equal(state(page.url()).operations_per_page, '100');
  assert.equal(state(page.url()).per_page, '25');
  assert.equal(await page.locator('#inventory-operations tbody tr').count(), 31);
  assert.equal(await page.locator('#inventory-movements tbody tr').count(), 25);
  await image(page, 't3-size-applied', movements());
});
