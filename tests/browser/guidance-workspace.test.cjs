'use strict';
// Actual view/scripts, synthetic local fixtures only; no DB or clinical writes.
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
let browser, server, origin;
const cache = new Map();
function render(url) {
  const key = url.search;
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', ['tests/_support/render-guidance-workspace-fixture.php'], { cwd: root,
    input: JSON.stringify({ origin, status: url.searchParams.get('status'), active: url.searchParams.has('active') }),
    encoding: 'utf8', timeout: 10000 });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  const capture = `<script>window.fixtureSubmissions=[];document.addEventListener('submit',event=>{event.preventDefault();window.fixtureSubmissions.push({action:new URL(event.target.action).pathname,fields:Array.from(new FormData(event.target,event.submitter).entries())});});</script>`;
  const html = result.stdout.replace('</body>', capture + '</body>');
  cache.set(key, html);
  return html;
}
before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method !== 'GET') return res.writeHead(405).end();
    if (url.pathname === '/guidance/cases/10/photo') {
      return res.writeHead(200, { 'Content-Type': 'image/svg+xml' }).end('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><text>SC</text></svg>');
    }
    if (url.pathname === '/guidance/cases/10') {
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url)); }
      catch (error) { return res.writeHead(500).end(error.message); }
    }
    const dir = path.join(root, 'public');
    const file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(dir + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(data);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});
async function open(t, theme, mobile, query = '') {
  const context = await browser.newContext({ viewport: mobile ? { width: 375, height: 812 } : { width: 1440, height: 1000 } });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage(); page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(20000);
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/guidance/cases/10' + query);
  await page.waitForFunction(() => window.SynapseConfirm && document.querySelector('#case-session-type')?.dataset.bdSelect || window.SynapseConfirm && !document.querySelector('#case-session-type'));
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return page;
}
async function noOverflow(page) {
  const sizes = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  assert.ok(sizes.scroll <= sizes.width + 1, JSON.stringify(sizes));
  for (const element of await page.locator('.guidance-case-workspace .btn:visible, .gc-page-links .btn:visible').all()) {
    const size = await element.evaluate(el => ({ width: el.clientWidth, scroll: el.scrollWidth }));
    assert.ok(size.scroll <= size.width + 2, JSON.stringify(size));
  }
}
for (const theme of ['light', 'dark']) for (const mobile of [false, true]) {
  test(`${theme} ${mobile ? 'mobile' : 'desktop'}: organized controls, native validation and confirmation`, { timeout: 30000 }, async t => {
    const page = await open(t, theme, mobile);
    await noOverflow(page);
    const avatar = page.locator('.gc-person-icon img');
    assert.equal(await avatar.getAttribute('alt'), '');
    assert.ok((await avatar.getAttribute('src')).endsWith('/guidance/cases/10/photo'));
    await avatar.evaluate(img => img.decode());
    assert.equal(await avatar.evaluate(img => img.naturalWidth > 0), true);
    await page.route('**/guidance/cases/10/photo?missing=1', route => route.fulfill({ status: 404 }));
    await avatar.evaluate(img => { img.src += '?missing=1'; });
    await page.waitForFunction(() => document.querySelector('.gc-person-icon img')?.src.startsWith('data:image/svg+xml;base64,'));
    const fallback = await avatar.getAttribute('src');
    assert.match(Buffer.from(fallback.split(',')[1], 'base64').toString('utf8'), /SC/);
    assert.equal(await page.locator('.gc-person-icon').getAttribute('aria-hidden'), 'true');
    assert.equal(await page.locator('.pagetitle select, .pagetitle input').count(), 0);
    const start = page.getByRole('button', { name: 'Start New Session', exact: true });
    assert.equal(await start.evaluate(el => getComputedStyle(el).whiteSpace), 'nowrap');
    assert.ok((await start.boundingBox()).height < 60);
    const folder = path.join(root, 'writable/guidance-workspace-preview'); fs.mkdirSync(folder, { recursive: true });
    await page.screenshot({ path: path.join(folder, `${theme}-${mobile ? 'mobile' : 'desktop'}.png`), fullPage: true });
    await page.locator('.gc-session-type .bd-select-toggle').click();
    await page.getByRole('option', { name: 'Crisis', exact: true }).click();
    await start.click();
    assert.equal(await page.evaluate(() => window.fixtureSubmissions[0].fields.some(([key, value]) => key === 'session_type' && value === 'crisis')), true);
    await page.getByRole('button', { name: 'Manage case status', exact: true }).click();
    await page.locator('#case-status-actions.show').waitFor();
    await noOverflow(page);
    await page.screenshot({ path: path.join(folder, `${theme}-${mobile ? 'mobile' : 'desktop'}-status.png`), fullPage: true });
    const monitor = page.locator('form[action$="/monitor"]');
    await monitor.getByRole('button', { name: 'Move to Monitoring', exact: true }).click();
    assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false);
    assert.equal(await page.evaluate(() => window.fixtureSubmissions.length), 1);
    await monitor.getByRole('checkbox', { name: 'Confirm monitoring', exact: true }).check();
    await monitor.getByRole('button', { name: 'Move to Monitoring', exact: true }).click();
    await page.locator('#synapseConfirmModal.show').waitFor();
    await page.locator('[data-confirm-cancel]').click();
    await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
    assert.equal(await page.evaluate(() => window.fixtureSubmissions.length), 1);
    await monitor.getByRole('button', { name: 'Move to Monitoring', exact: true }).click();
    await page.locator('#synapseConfirmModal.show').waitFor();
    await page.locator('[data-confirm-accept]').click();
    await page.waitForFunction(() => window.fixtureSubmissions.length === 2);
    assert.equal(await page.evaluate(() => window.fixtureSubmissions[1].action), '/guidance/cases/10/monitor');
    assert.equal(await page.evaluate(() => window.fixtureSubmissions[1].fields.some(([key, value]) => key === 'confirmed' && value === '1')), true);
    const close = page.locator('form[action$="/cases/10/close"]');
    await close.getByRole('checkbox', { name: 'Confirm case closure', exact: true }).check();
    await close.getByRole('button', { name: 'Close Case', exact: true }).click();
    await page.locator('#synapseConfirmModal.show').waitFor();
    await page.locator('[data-confirm-cancel]').click();
    await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
    assert.equal(await page.evaluate(() => window.fixtureSubmissions.length), 2);
    await page.getByRole('tab', { name: /Follow-up Commitments/ }).click();
    await page.locator('#tab-followups.active').waitFor();
    await noOverflow(page);
  });
}
test('Active sessions and closed/monitoring cases retain lifecycle restrictions', { timeout: 30000 }, async t => {
  const page = await open(t, 'dark', true, '?active=1');
  assert.equal(await page.getByRole('button', { name: 'Manage case status', exact: true }).count(), 0);
  assert.equal(await page.locator('form[action$="/monitor"], form[action$="/cases/10/close"]').count(), 0);
  await noOverflow(page);
  await page.goto(origin + '/guidance/cases/10?status=closed');
  assert.equal(await page.getByRole('button', { name: 'Start New Session', exact: true }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Manage case status', exact: true }).count(), 0);
  await noOverflow(page);
  await page.goto(origin + '/guidance/cases/10?status=monitoring');
  await page.getByRole('button', { name: 'Manage case status', exact: true }).click();
  await page.locator('#case-status-actions.show').waitFor();
  assert.equal(await page.getByRole('button', { name: 'Move to Monitoring', exact: true }).count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Close Case', exact: true }).count(), 1);
});
