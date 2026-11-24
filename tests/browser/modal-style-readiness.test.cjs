'use strict';

// Visible Chromium + loopback synthetic display, production layout/scripts, no app/DB or writes.
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
const assets = new Set(['/assets/vendor/bootstrap/css/bootstrap.min.css',
  '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js', '/assets/js/page-styles.js', '/assets/js/form-modal.js']);
const evidence = [], requests = [], rejected = [];
const gates = new Map();
let browser, server, origin, screenshotDir;

function defer() { let release; const promise = new Promise(resolve => { release = resolve; }); return { promise, release }; }
function render(options = {}) {
  const result = spawnSync('php', ['-n', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
    path.join(root, 'tests/_support/render-modal-style-readiness-fixture.php')], {
    cwd: root, input: JSON.stringify({ origin, ...options }), encoding: 'utf8', timeout: 10000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.doesNotMatch(result.stdout, /Warning:|Fatal error|Parse error/);
  return result.stdout;
}
async function until(predicate, message) {
  const deadline = Date.now() + 6000;
  while (!predicate()) {
    assert.ok(Date.now() < deadline, message);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
}
function hold(t, pathname) {
  const gate = defer(); gates.set(pathname, gate);
  t.after(() => { gate.release(); gates.delete(pathname); });
  return () => { gate.release(); gates.delete(pathname); };
}
const panelCSS = '.modal-ready-panel { padding: 32px; background: rgb(216, 242, 224); border: 4px solid rgb(22, 98, 58); border-radius: 12px; }';
before(async () => {
  screenshotDir = fs.mkdtempSync(path.join(os.tmpdir(), 'humus-modal-style-readiness-'));
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, origin);
    requests.push(url.pathname);
    if (req.method !== 'GET') { rejected.push(req.method + ' ' + url.pathname); return res.writeHead(405).end(); }
    if (url.pathname === '/') return res.writeHead(200, { 'Content-Type': 'text/html' }).end(render({ host: true }));
    if (url.pathname === '/modal/readiness') return res.writeHead(200, { 'Content-Type': 'text/html' }).end(render({
      mode: url.searchParams.get('mode'), page: Number(url.searchParams.get('page') || 1),
    }));
    if (url.pathname.startsWith('/assets/modal-style-readiness/')) {
      if (url.pathname.includes('/never-')) { rejected.push(url.pathname); return res.writeHead(418).end(); }
      const gate = gates.get(url.pathname);
      if (gate) await gate.promise;
      if (res.destroyed) return;
      const failed = url.pathname.endsWith('failure-1.css');
      let css = panelCSS;
      if (url.pathname.endsWith('delayed-1.css')) css = '@import url("delayed-import.css"); .modal-ready-panel { border: 4px solid rgb(22, 98, 58); border-radius: 12px; }';
      if (url.pathname.endsWith('delayed-import.css')) css = '.modal-ready-panel { padding: 32px; background: rgb(216, 242, 224); }';
      return res.writeHead(failed ? 503 : 200, { 'Content-Type': 'text/css', 'Cache-Control': 'public, max-age=600' }).end(failed ? '' : css);
    }
    if (url.pathname === '/favicon.ico') return res.writeHead(204).end();
    if (!assets.has(url.pathname)) { rejected.push(url.pathname); return res.writeHead(404).end(); }
    const file = path.join(root, 'public', url.pathname);
    res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : 'text/javascript' });
    fs.createReadStream(file).pipe(res);
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Installed Chromium is required; nothing is downloaded.');
  browser = await chromium.launch({ executablePath, headless: false });
});
after(async () => {
  for (const gate of gates.values()) gate.release();
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  assert.deepEqual(rejected, [], 'No write, fetched script, global fragment CSS, or unexpected network request');
  console.log('Viewed-evidence candidates: ' + JSON.stringify(evidence));
});
async function image(page, name) {
  const file = path.join(screenshotDir, name + '.png');
  await page.screenshot({ path: file }); evidence.push(file);
  console.log('Modal readiness screenshot: ' + file);
}
async function pageFor(t) {
  const context = await browser.newContext({ viewport: { width: 1120, height: 850 }, serviceWorkers: 'block' });
  // Read-only mount instrumentation is installed before the fixture is loaded.
  // It verifies the first computed style, not merely the eventually settled form.
  await context.addInitScript(() => {
    window.fixtureStyleMounts = [];
    const seen = new WeakSet();
    new MutationObserver(() => {
      document.querySelectorAll('[data-modal-ready-panel]').forEach(panel => {
        if (seen.has(panel)) return;
        seen.add(panel);
        const style = getComputedStyle(panel);
        window.fixtureStyleMounts.push({ padding: style.padding, background: style.backgroundColor });
      });
    }).observe(document, { childList: true, subtree: true });
  });
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin === origin) return route.continue();
    rejected.push('off-site request'); return route.abort();
  });
  const page = await context.newPage(), errors = [];
  page.setDefaultTimeout(6000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/');
  await page.getByRole('heading', { name: 'Current screen stays mounted', exact: true }).waitFor();
  await page.getByRole('textbox', { name: 'Current screen draft', exact: true }).fill('Keep the underlying editor');
  assert.equal(await page.locator('[data-page-style-owner="head"]').count(), 1);
  return page;
}
async function assertLoading(page, name) {
  const modal = page.locator('#appFormModal');
  await modal.getByRole('button', { name: 'Close', exact: true }).waitFor();
  assert.equal(await modal.locator('form, [data-modal-ready-panel]').count(), 0);
  assert.equal(await page.getByRole('textbox', { name: 'Current screen draft', exact: true }).inputValue(), 'Keep the underlying editor');
  assert.equal(await page.locator('[data-modal-style-owner]').count(), 0);
  await image(page, name);
  return modal;
}
async function assertStyled(page, name) {
  const modal = page.locator('#appFormModal');
  await modal.getByRole('textbox', { name: 'Modal draft', exact: true }).waitFor();
  assert.deepEqual(await modal.locator('[data-modal-ready-panel]').evaluate(panel => ({
    padding: getComputedStyle(panel).padding, background: getComputedStyle(panel).backgroundColor,
  })), { padding: '32px', background: 'rgb(216, 242, 224)' });
  const firstStyles = await page.evaluate(() => window.fixtureStyleMounts);
  assert.ok(firstStyles.length > 0, 'Observe the actual inserted form');
  for (const style of firstStyles) assert.deepEqual(style, { padding: '32px', background: 'rgb(216, 242, 224)' }, 'CSS is ready at the first mount observation');
  assert.equal(await page.locator('[data-modal-style-owner]').count(), 1);
  assert.equal(await page.locator('head [data-page-style-stage]').count(), 0);
  assert.equal(await modal.locator('script, style, link').count(), 0);
  await image(page, name);
  return modal;
}

test('delayed root and imported modal CSS keep the loading shell until the complete styled form is ready', async t => {
  const releaseRoot = hold(t, '/assets/modal-style-readiness/delayed-1.css');
  const releaseImport = hold(t, '/assets/modal-style-readiness/delayed-import.css');
  const page = await pageFor(t);
  await image(page, 't1-initial');
  await page.getByRole('link', { name: 'Open styled form', exact: true }).click();
  await until(() => requests.includes('/assets/modal-style-readiness/delayed-1.css'), 'Root stylesheet request starts');
  await assertLoading(page, 't1-root-pending');
  releaseRoot();
  await until(() => requests.includes('/assets/modal-style-readiness/delayed-import.css'), 'Imported stylesheet request starts');
  await assertLoading(page, 't1-import-pending');
  releaseImport();
  const modal = await assertStyled(page, 't1-ready');
  await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal.waitFor({ state: 'detached' });
  assert.equal(await page.locator('[data-modal-style-owner], [data-page-style-stage]').count(), 0);
  await image(page, 't1-closed');
});

test('a failed modal stylesheet shows a closeable error without final content or leaked CSS', async t => {
  const page = await pageFor(t);
  await image(page, 't2-initial');
  await page.getByRole('link', { name: 'Open failing form', exact: true }).click();
  const modal = page.locator('#appFormModal');
  await modal.getByRole('alert').waitFor({ state: 'visible' });
  assert.match(await modal.getByRole('alert').innerText(), /stylesheet|styles/i);
  assert.equal(await modal.locator('form, [data-modal-ready-panel]').count(), 0);
  assert.equal(await page.locator('[data-modal-style-owner], [data-page-style-stage]').count(), 0);
  await image(page, 't2-failed');
  await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal.waitFor({ state: 'detached' });
  assert.equal(await page.getByRole('textbox', { name: 'Current screen draft', exact: true }).inputValue(), 'Keep the underlying editor');
  await image(page, 't2-closed');
});

test('closing the loading shell aborts staged CSS and a late stylesheet cannot reopen or style the dialog', async t => {
  const release = hold(t, '/assets/modal-style-readiness/close-1.css');
  const page = await pageFor(t);
  await image(page, 't3-initial');
  await page.getByRole('link', { name: 'Open slow form', exact: true }).click();
  await until(() => requests.includes('/assets/modal-style-readiness/close-1.css'), 'Slow CSS starts');
  const modal = await assertLoading(page, 't3-css-pending');
  await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal.waitFor({ state: 'detached' });
  release();
  assert.equal(await page.locator('[data-modal-style-owner], [data-page-style-stage]').count(), 0);
  await image(page, 't3-closed-aborted');
  await page.getByRole('link', { name: 'Open styled history', exact: true }).click();
  const reopened = await assertStyled(page, 't3-new-dialog');
  assert.equal(await reopened.getByRole('heading', { name: 'Styled history page 1', exact: true }).count(), 1);
  await reopened.getByRole('button', { name: 'Cancel', exact: true }).click();
  await reopened.waitFor({ state: 'detached' });
});

test('history paging stages replacement CSS while retaining the old styled content and one modal owner', async t => {
  const release = hold(t, '/assets/modal-style-readiness/paging-2.css');
  const page = await pageFor(t);
  await page.getByRole('link', { name: 'Open styled history', exact: true }).click();
  const modal = await assertStyled(page, 't4-page1');
  const firstSlot = await page.locator('[data-modal-style-owner]').getAttribute('id');
  await modal.getByRole('link', { name: 'Next history page', exact: true }).click();
  await until(() => requests.includes('/assets/modal-style-readiness/paging-2.css'), 'History CSS starts');
  assert.equal(await modal.getByRole('heading', { name: 'Styled history page 1', exact: true }).count(), 1);
  assert.equal(await modal.getByRole('heading', { name: 'Styled history page 2', exact: true }).count(), 0);
  assert.equal(await modal.locator('[data-modal-ready-panel]').evaluate(panel => getComputedStyle(panel).padding), '32px');
  await image(page, 't4-page2-pending');
  release();
  await modal.getByRole('heading', { name: 'Styled history page 2', exact: true }).waitFor();
  await assertStyled(page, 't4-page2-ready');
  assert.equal(await page.locator('[data-modal-style-owner]').getAttribute('id'), firstSlot);
  assert.equal(await page.locator('[data-modal-style-owner]').evaluate(slot => window.SynapsePageStyles.nodes(slot).length), 1);
  await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal.waitFor({ state: 'detached' });
  assert.equal(await page.locator('[data-modal-style-owner], [data-page-style-stage]').count(), 0);
  await image(page, 't4-closed');
});
