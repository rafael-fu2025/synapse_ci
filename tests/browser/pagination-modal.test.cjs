'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin, failRead = false;
const reads = [], writes = [];
const screenshotDir = path.join(root, 'writable/pagination-preview');

function render(url, theme = 'light') {
  const result = spawnSync('php', ['-n', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
    path.join(root, 'tests/_support/render-pagination-modal-fixture.php')], {
    cwd: root, input: JSON.stringify({ origin, comments_page: url.searchParams.get('comments_page'),
      activity_page: url.searchParams.get('activity_page'), fragment: url.searchParams.get('fragment') === '1', theme }),
    encoding: 'utf8', timeout: 10000,
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Warning:|Fatal error|Parse error/);
  return result.stdout;
}

before(async () => {
  fs.mkdirSync(screenshotDir, { recursive: true });
  server = http.createServer((req, res) => {
    if (req.method !== 'GET') { writes.push(req.method); return res.writeHead(405).end(); }
    const url = new URL(req.url, origin);
    if (url.pathname === '/' || url.pathname === '/tasks/7') {
      if (url.searchParams.get('fragment') === '1') {
        reads.push(url);
        if (failRead) return res.writeHead(503, { 'Content-Type': 'text/html' }).end('Unavailable');
      }
      return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url, url.searchParams.get('theme') || 'light'));
    }
    if (!url.pathname.startsWith('/assets/')) return res.writeHead(404).end();
    const file = path.resolve(root, 'public', '.' + url.pathname);
    const prefix = path.join(root, 'public/assets') + path.sep;
    if (!file.startsWith(prefix) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return res.writeHead(404).end();
    res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const parent = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(parent).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(parent, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Installed Chromium is required; no installation is performed.');
  browser = await chromium.launch({ executablePath, headless: true });
});
after(async () => {
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  assert.deepEqual(writes, []);
});

async function open(t, width = 1280, theme = 'light') {
  failRead = false;
  const errors = [];
  const context = await browser.newContext({ viewport: { width, height: 900 }, serviceWorkers: 'block' });
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/?theme=' + theme);
  await page.getByRole('link', { name: 'Open task history', exact: true }).click();
  const modal = page.locator('#appFormModal');
  await modal.getByText('Comments page 1', { exact: true }).waitFor();
  await modal.getByRole('textbox', { name: 'Comment draft' }).waitFor();
  await image(page, 'modal-' + width + '-' + theme + '-initial');
  return { page, modal };
}
async function image(page, name) {
  const file = path.join(screenshotDir, name + '.png');
  await page.screenshot({ path: file });
  console.log('Pagination screenshot: ' + file);
}

for (const [width, theme] of [[1280, 'light'], [390, 'dark']]) test(`modal paging keeps the task dialog and independent histories: ${width}px ${theme}`, async t => {
  const { page, modal } = await open(t, width, theme);
  const before = page.url();
  const discussion = modal.getByRole('navigation', { name: 'Discussion pages', exact: true });
  await discussion.getByRole('link', { name: 'Next', exact: true }).click();
  await modal.getByText('Comments page 2', { exact: true }).waitFor();
  assert.equal(await modal.getByText('Activity page 1', { exact: true }).count(), 1);
  assert.equal(page.url(), before);
  assert.equal(await modal.getByText('Showing 21–40 of 65', { exact: true }).count(), 1);
  await image(page, 'modal-' + width + '-' + theme + '-comments-page2');
  await modal.getByRole('navigation', { name: 'Activity pages', exact: true }).getByRole('link', { name: 'Last', exact: true }).click();
  await modal.getByText('Activity page 3', { exact: true }).waitFor();
  assert.equal(await modal.getByText('Comments page 2', { exact: true }).count(), 1);
  assert.equal(await modal.getByText('Showing 41–45 of 45', { exact: true }).count(), 1);
  assert.equal(page.url(), before);
  await image(page, 'modal-' + width + '-' + theme + '-activity-last');
});

test('dirty modal paging requires consent; cancellation retains draft and accepted paging resets it', async t => {
  const { page, modal } = await open(t);
  await modal.getByRole('textbox', { name: 'Comment draft' }).fill('Keep this synthetic draft');
  const count = reads.length;
  await modal.getByRole('navigation', { name: 'Discussion pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  const confirm = page.locator('#synapseConfirmModal');
  await confirm.getByRole('button', { name: 'Keep edits', exact: true }).waitFor();
  await image(page, 'modal-dirty-consent');
  await confirm.getByRole('button', { name: 'Keep edits', exact: true }).click();
  await confirm.waitFor({ state: 'hidden' });
  assert.equal(await modal.getByRole('textbox', { name: 'Comment draft' }).inputValue(), 'Keep this synthetic draft');
  assert.equal(reads.length, count);
  await image(page, 'modal-dirty-cancelled');
  await modal.getByRole('navigation', { name: 'Discussion pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  await confirm.getByRole('button', { name: 'Discard and change page', exact: true }).click();
  await modal.getByText('Comments page 2', { exact: true }).waitFor();
  assert.equal(await modal.getByRole('textbox', { name: 'Comment draft' }).inputValue(), '');
  assert.equal(reads.length, count + 1);
  await image(page, 'modal-dirty-accepted');
});

test('failed history GET retains old content and allows a read-only retry', async t => {
  const { page, modal } = await open(t);
  failRead = true;
  await modal.getByRole('navigation', { name: 'Discussion pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  await modal.getByRole('alert').waitFor({ state: 'visible' });
  assert.match(await modal.getByRole('alert').innerText(), /Unable to load history/);
  assert.equal(await modal.getByText('Comments page 1', { exact: true }).count(), 1);
  await image(page, 'modal-failed-history');
  failRead = false;
  await modal.getByRole('navigation', { name: 'Discussion pages', exact: true }).getByRole('link', { name: 'Next', exact: true }).click();
  await modal.getByText('Comments page 2', { exact: true }).waitFor();
  await image(page, 'modal-retried-history');
});
