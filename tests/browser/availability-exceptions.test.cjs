'use strict';
// Isolated browser/real views. Synthetic exception snapshots only; no application DB.
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
let server, browser, origin, mode = 'validation';
const posts = [], cache = new Map();
function render(url) {
  const key = url.pathname + url.search;
  if (cache.has(key)) return cache.get(key);
  const unit = url.pathname.startsWith('/clinic/') ? 'clinic' : 'guidance';
  const result = spawnSync('php', ['tests/_support/render-modal-fixture.php'], { cwd: root, encoding: 'utf8', timeout: 12000,
    input: JSON.stringify({ origin, path: key, kind: unit + '-availability' }) });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  cache.set(key, result.stdout); return result.stdout;
}
before(async () => {
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method === 'POST') {
      const chunks = []; for await (const chunk of req) chunks.push(chunk);
      const body = Buffer.concat(chunks).toString('utf8');
      const fields = Object.fromEntries([...body.matchAll(/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([\s\S]*?)(?=\r\n--)/g)].map(m => [m[1], m[2]]));
      posts.push({ path: url.pathname, fields, headers: req.headers });
      const base = url.pathname.replace(/\/availability(?:\/.*)?$/, '/availability');
      const query = new URLSearchParams({ q: fields.return_q || '', provider: fields.staff_assignment_id || fields.return_provider || '', day: fields.return_day || '1', section: 'exceptions' });
      const message = mode === 'stale' ? 'Availability exception changed. Reload before saving.' : 'Synthetic validation: <img src=x onerror=alert(1)>';
      res.writeHead(mode === 'validation' ? 422 : mode === 'stale' ? 409 : 200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: ['success', 'badredirect'].includes(mode), message,
        redirect: mode === 'badredirect' ? origin + '/guidance/appointments/availability' : origin + base + '?' + query,
        csrf: { name: 'csrf_test_name', hash: 'exception-refreshed-token' } }));
      return;
    }
    if (/^\/(clinic|guidance)\/appointments\/availability$/.test(url.pathname)) {
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url)); }
      catch (error) { return res.writeHead(500).end(error.message); }
    }
    const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(dir + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(data);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening'); origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => { try { await browser?.close(); } finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } });
async function open(t, unit, mobile = false) {
  mode = 'validation';
  const context = await browser.newContext({ viewport: mobile ? { width: 375, height: 812 } : { width: 1366, height: 1000 } });
  await context.addInitScript(mobile => localStorage.setItem('na-dark-mode', mobile ? '1' : '0'), mobile);
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage(); page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(20000);
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(`${origin}/${unit}/appointments/availability?section=exceptions`);
  await page.waitForFunction(() => window.SynapseAvailabilityWorkspace && window.SynapseConfirm);
  return page;
}
async function noOverflow(page) {
  const sizes = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  assert.ok(sizes.scroll <= sizes.width + 1, JSON.stringify(sizes));
  const modal = page.locator('.modal.show .modal-content');
  if (await modal.count()) {
    const box = await modal.boundingBox();
    const viewport = page.viewportSize();
    assert.ok(box.x >= 0 && box.x + box.width <= viewport.width + 1, JSON.stringify(box));
    assert.ok(box.y >= 0 && box.y + box.height <= viewport.height + 1, JSON.stringify(box));
  }
}
async function choose(page, id, option) {
  const wrap = page.locator('#' + id).locator('..');
  await wrap.locator('.bd-select-toggle').first().click();
  await wrap.getByRole('option', { name: option, exact: true }).click();
}
async function openNew(page) {
  await page.getByRole('button', { name: 'Add exception', exact: true }).click();
  const modal = page.locator('#availability-exception-new-modal');
  await modal.locator('form[data-availability-save]').waitFor({ state: 'visible' });
  await choose(page, 'exceptionNewProvider', 'Provider Fixture');
  const iso = await page.evaluate(() => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()));
  await page.locator('#exceptionNewDateDisplay').focus(); await page.keyboard.press('ArrowDown');
  await page.locator(`.dp-popup:visible td[data-date="${iso}"]`).click();
  await page.locator('#exceptionNewReason').fill('Synthetic approved leave');
  return modal;
}
for (const unit of ['clinic', 'guidance']) for (const mobile of [false, true]) {
  test(`${unit} exceptions ${mobile ? 'dark mobile' : 'light desktop'}: custom picker, retries, snapshots and confirmation`, { timeout: 45000 }, async t => {
    const page = await open(t, unit, mobile);
    await noOverflow(page);
    assert.equal(await page.locator('#appFormModal').count(), 0);
    assert.equal(await page.locator('.av-exception-row').count(), 3);
    assert.equal(await page.getByRole('button', { name: 'Edit exception', exact: true }).count(), 1, 'Past dates and inactive assignments remain read-only');
    const screenshots = path.join(root, 'writable/availability-workspace-preview'); fs.mkdirSync(screenshots, { recursive: true });
    await page.screenshot({ path: path.join(screenshots, `${unit}-${mobile ? 'mobile' : 'desktop'}-exceptions.png`), fullPage: true, timeout: 15000, animations: 'disabled' });
    const modal = await openNew(page);
    await noOverflow(page);
    await choose(page, 'exceptionNewType', 'Custom hours (30-minute slots)');
    await page.locator('[name="custom_start_time"]').waitFor({ state: 'attached' });
    assert.equal(await modal.locator('input[type="text"][data-timepicker][required]').count(), 2);
    for (const [id, time, display, hour, minute, period] of [
      ['exceptionNewStart', '09:05', '09:05 AM', '9', '5', 'AM'],
      ['exceptionNewEnd', '17:45', '05:45 PM', '5', '45', 'PM']
    ]) {
      const input = modal.locator('#' + id);
      assert.equal(await input.getAttribute('data-target'), '#' + id + 'Value');
      await input.click();
      const popup = page.locator('.dtp-popup.dtp-time-only:visible');
      await popup.waitFor();
      await popup.locator(`.dtp-hours-wheel button[data-val="${hour}"]`).click();
      await popup.locator(`.dtp-minutes-wheel button[data-val="${minute}"]`).click();
      await popup.locator(`.dtp-ampm-wheel button[data-val="${period}"]`).click();
      await popup.getByRole('button', { name: 'Apply', exact: true }).click();
      await popup.waitFor({ state: 'hidden' });
      assert.equal(await input.inputValue(), display);
      assert.equal(await modal.locator('#' + id + 'Value').inputValue(), time);
    }
    await noOverflow(page);
    await choose(page, 'exceptionNewType', 'Leave / full day unavailable');
    await page.locator('[name="custom_start_time"]').waitFor({ state: 'detached' });
    const form = modal.locator('form[data-availability-save]');
    const before = posts.length;
    await form.getByRole('button', { name: 'Save exception', exact: true }).click();
    await form.locator('[data-availability-feedback]:visible').waitFor();
    assert.equal(posts.length, before + 1);
    assert.equal(posts.at(-1).headers['x-requested-with'], 'XMLHttpRequest');
    assert.equal(posts.at(-1).fields.id, '0'); assert.equal(posts.at(-1).fields.staff_assignment_id, '7');
    assert.equal(posts.at(-1).fields.custom_start_time, undefined);
    assert.equal(await page.locator('#exceptionNewReason').inputValue(), 'Synthetic approved leave');
    assert.equal(await form.locator('[data-availability-feedback] img').count(), 0);
    assert.equal(await form.getByRole('button', { name: 'Save exception', exact: true }).isEnabled(), true);
    await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
    await modal.waitFor({ state: 'hidden' });
    await page.getByRole('button', { name: 'Edit exception', exact: true }).click();
    const edit = page.locator('#availability-exception-61-modal');
    const update = edit.locator('form[action$="/exceptions"]');
    const token = await update.locator('[name="token"]').inputValue();
    assert.match(token, /^[a-f0-9]{64}$/);
    mode = 'stale';
    await update.getByRole('button', { name: 'Save exception', exact: true }).click();
    await update.locator('[data-availability-feedback]:visible').waitFor();
    assert.match(await update.locator('[data-availability-feedback]').innerText(), /changed/);
    assert.equal(posts.at(-1).fields.token, token); assert.equal(posts.at(-1).fields.csrf_test_name, 'exception-refreshed-token');
    const remove = edit.locator('form[action$="/remove"]');
    const count = posts.length;
    await remove.getByRole('button', { name: 'Remove exception', exact: true }).click();
    await page.locator('#synapseConfirmModal.show').waitFor();
    await page.locator('[data-confirm-cancel]').click();
    await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
    await page.waitForFunction(() => bootstrap.Modal.getInstance(document.getElementById('synapseConfirmModal'))?._isTransitioning === false);
    assert.equal(posts.length, count);
    await remove.getByRole('button', { name: 'Remove exception', exact: true }).click();
    await page.locator('#synapseConfirmModal.show').waitFor(); await page.locator('[data-confirm-accept]').click();
    await remove.locator('[data-availability-feedback]:visible').waitFor();
    assert.equal(posts.at(-1).fields.token, token); assert.equal(posts.at(-1).fields.id, '61');
    await noOverflow(page);
    await page.screenshot({ path: path.join(screenshots, `${unit}-${mobile ? 'mobile' : 'desktop'}-exception-modal.png`), fullPage: false, timeout: 15000, animations: 'disabled' });
  });
}
test('Weekly review links open the focused editor with the selected provider and day', { timeout: 25000 }, async t => {
  const page = await open(t, 'clinic');
  await page.goto(origin + '/clinic/appointments/availability?section=weekly');
  await page.getByRole('link', { name: 'Review / adjust', exact: true }).click();
  const modal = page.locator('#availability-individual-modal');
  await modal.waitFor({ state: 'visible' });
  assert.equal(await modal.locator('#availabilityProvider').inputValue(), '7');
  assert.equal(await modal.locator('#availabilityDay').inputValue(), '1');
  assert.equal(await modal.locator('[name="start_time"]').inputValue(), '08:00');
  assert.equal(await modal.locator('[name="end_time"]').inputValue(), '17:00');
  assert.equal(await modal.locator('#availabilityStart[data-timepicker]').inputValue(), '08:00 AM');
  assert.equal(await modal.locator('#availabilityEnd[data-timepicker]').inputValue(), '05:00 PM');
  await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal.waitFor({ state: 'hidden' });
  await page.getByRole('link', { name: 'Review / adjust', exact: true }).click();
  await modal.waitFor({ state: 'visible' });
  await noOverflow(page);
});

test('Workspace rejects cross-department action and response redirect', { timeout: 25000 }, async t => {
  const page = await open(t, 'clinic'); const modal = await openNew(page); const form = modal.locator('form');
  const original = await form.getAttribute('action'); const before = posts.length;
  await form.evaluate((el, origin) => { el.action = origin + '/guidance/appointments/availability/exceptions'; }, origin);
  await form.getByRole('button', { name: 'Save exception', exact: true }).click();
  await form.locator('[data-availability-feedback]:visible').waitFor(); assert.equal(posts.length, before);
  await form.evaluate((el, action) => { el.action = action; }, original);
  mode = 'badredirect'; await form.getByRole('button', { name: 'Save exception', exact: true }).click();
  await form.locator('[data-availability-feedback]:visible').waitFor();
  assert.match(await form.locator('[data-availability-feedback]').innerText(), /return address was invalid/);
  assert.equal(new URL(page.url()).pathname, '/clinic/appointments/availability');
});
