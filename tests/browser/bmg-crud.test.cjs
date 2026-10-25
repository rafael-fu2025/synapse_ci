'use strict';

// Real views, navigation, Bootstrap and shared consent; every write stays in this fixture server.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const cache = new Map();
const posts = [];
const responses = [];

function render(kind, url) {
  const key = kind + url;
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', ['tests/_support/render-bmg-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: url, kind }), encoding: 'utf8', timeout: 10000
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
  assert.match(result.stdout, /<!DOCTYPE html>/);
  cache.set(key, result.stdout);
  return result.stdout;
}

before(async () => {
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin);
      if (req.method === 'POST') {
        let body = ''; for await (const chunk of req) body += chunk;
        const data = await new Request(origin + req.url, { method: 'POST', body,
          headers: { 'Content-Type': req.headers['content-type'] } }).formData();
        posts.push({ url: req.url, fields: [...data.entries()], headers: req.headers });
        const reply = responses.shift() || {};
        const csrf = { name: 'csrf_test_name', hash: 'crud-refreshed-token', header: 'X-CSRF-TOKEN' };
        res.writeHead(reply.status || 200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: !reply.status, message: reply.message || 'Fixture write accepted',
          redirect: reply.redirect || '/facilities', csrf }));
        return;
      }
      const rootAsset = ['/drum-maroon.png', '/drum-whte.png', '/favicon.ico', '/favicon-192.png', '/favicon-180.png'].includes(url.pathname);
      if (url.pathname.startsWith('/assets/') || rootAsset) {
        const file = path.resolve(root, 'public', '.' + url.pathname);
        if ((!rootAsset && !file.startsWith(path.join(root, 'public', 'assets') + path.sep)) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
          res.writeHead(404); res.end(); return;
        }
        const types = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
        res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
        res.end(fs.readFileSync(file)); return;
      }
      const kind = url.pathname === '/facilities' ? 'index' : url.pathname === '/facilities/unit/11' ? 'unit_detail' : null;
      if (!kind) { res.writeHead(404); res.end('Not a fixture route'); return; }
      res.setHeader('Content-Type', 'text/html'); res.end(render(kind, req.url));
    } catch (error) { res.writeHead(500); res.end(String(error.stack)); }
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install Chromium for the existing browser-test tools');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
});
after(async () => { await browser?.close(); server?.close(); });

async function pageFor(t, url = '/facilities/unit/11') {
  assert.equal(responses.length, 0, 'No mock responses leaked from another test');
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
  await context.route('**/*', route => {
    if (new URL(route.request().url()).pathname.endsWith('/assets/js/notifications.js')) return route.fulfill({ contentType: 'text/javascript', body: '' });
    return route.request().url().startsWith(origin + '/') ? route.continue() : route.fulfill({ status: 200, body: '' });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000); page.setDefaultNavigationTimeout(20000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const dialogs = []; page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); assert.deepEqual(dialogs, [], 'No stale draft warning or native consent'); });
  const response = await page.goto(origin + url);
  assert.equal(response.status(), 200, await response.text());
  await page.waitForFunction(() => window.SynapseBmg && window.bootstrap && window.SynapseConfirm && window.SynapseNavigation);
  return page;
}

async function expectedFields(form, values) {
  const csrf = form.locator('input[name="csrf_test_name"]');
  assert.equal(await csrf.count(), 1);
  assert.equal(await csrf.getAttribute('type'), 'hidden');
  const token = await csrf.inputValue(); assert.ok(token);
  return Object.entries({ csrf_test_name: token, ...values }).sort(([a], [b]) => a.localeCompare(b));
}
function exactPost(count, url, fields) {
  assert.equal(posts.length, count + 1, 'Exactly one write');
  const post = posts.at(-1);
  assert.equal(post.url, url);
  assert.deepEqual(post.fields.sort(([a], [b]) => a.localeCompare(b)), fields, 'Exact fields, including CSRF and current version; no operational enable fields');
  assert.equal(post.headers['x-requested-with'], 'XMLHttpRequest');
}
async function sharedConsent(page) {
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(await page.locator('.modal.show').count(), 1, 'Shared confirmation never stacks editors');
  assert.equal(await page.locator('.modal-backdrop').count(), 1);
}

test('detail Add drum navigates to one-shot create; cancel clears draft and navigation warning', { timeout: 60000 }, async t => {
  const page = await pageFor(t);
  const count = posts.length;
  assert.equal(await page.getByRole('link', { name: 'All drums', exact: true }).getAttribute('href'), origin + '/facilities');
  const add = page.getByRole('link', { name: 'Add drum', exact: true });
  assert.equal(await add.getAttribute('href'), origin + '/facilities?create=1');
  await page.evaluate(() => { window.crudNavigationSentinel = true; });
  await add.click(); await page.waitForURL(origin + '/facilities?create=1');
  await page.locator('#unitModal.show').waitFor();
  assert.equal(await page.evaluate(() => window.crudNavigationSentinel), true, 'Use production partial navigation');
  assert.equal(await page.locator('[data-bmg-open-create]').count(), 0, 'Auto-open marker consumed');
  await page.getByLabel('Unit code', { exact: true }).fill('DRAFT');
  await page.getByLabel('Drum name', { exact: true }).fill('Discard me');
  await page.getByLabel('Capacity (kg)', { exact: true }).fill('50.25');
  await page.getByLabel('Availability', { exact: true }).and(page.locator('select')).selectOption('maintenance', { force: true });
  await page.locator('#unitModal').getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.locator('#unitModal').waitFor({ state: 'hidden' });
  for (const label of ['Unit code', 'Drum name', 'Capacity (kg)']) assert.equal(await page.getByLabel(label, { exact: true }).inputValue(), '');
  assert.equal(await page.getByLabel('Availability', { exact: true }).and(page.locator('select')).inputValue(), 'active');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:page-load')));
  assert.equal(await page.locator('#unitModal.show').count(), 0, 'Reinitializing does not reopen create');
  await page.getByRole('link', { name: 'Archived drums', exact: true }).click();
  await page.waitForURL(origin + '/facilities?view=archived');
  await page.getByRole('heading', { name: 'Archived drums', exact: true }).waitFor();
  assert.equal(posts.length, count);
});

test('create submits only exact saved fields and redirects to the list', { timeout: 60000 }, async t => {
  const page = await pageFor(t, '/facilities?create=1');
  await page.locator('#unitModal.show').waitFor();
  const form = page.locator('#unitModal form');
  await page.getByLabel('Unit code', { exact: true }).fill('DRUM-NEW');
  await page.getByLabel('Drum name', { exact: true }).fill('New drum');
  await page.getByLabel('Capacity (kg)', { exact: true }).fill('125.25');
  await page.getByLabel('Availability', { exact: true }).and(page.locator('select')).selectOption('maintenance', { force: true });
  const fields = await expectedFields(form, { unit_code: 'DRUM-NEW', name: 'New drum', drum_capacity_kg: '125.25', status: 'maintenance' });
  const count = posts.length;
  await form.getByRole('button', { name: 'Save drum', exact: true }).click();
  await page.waitForURL(origin + '/facilities');
  exactPost(count, '/facilities/units/store', fields);
  assert.equal(await page.locator('#unitModal.show').count(), 0);
});

test('edit opens persisted values and submits exact fields with the current version', { timeout: 60000 }, async t => {
  const page = await pageFor(t);
  await page.getByRole('button', { name: 'Edit drum', exact: true }).click();
  await page.locator('#unitModal.show').waitFor();
  assert.equal(await page.getByLabel('Availability', { exact: true }).and(page.locator('select')).inputValue(), 'active');
    for (const [label, value] of [['Unit code', 'DRUM-11'], ['Drum name', 'North drum'], ['Capacity (kg)', '275.50']]) {
    assert.equal(await page.getByLabel(label, { exact: true }).inputValue(), value);
  }
  await page.getByLabel('Drum name', { exact: true }).fill('Renamed drum');
  await page.getByLabel('Capacity (kg)', { exact: true }).fill('300.75');
  const form = page.locator('#unitModal form');
  const fields = await expectedFields(form, { expected_version: '3', unit_code: 'DRUM-11', name: 'Renamed drum', drum_capacity_kg: '300.75', status: 'active' });
  const count = posts.length;
  responses.push({ redirect: '/facilities/unit/11' });
  const navigation = page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() && frame.url() === origin + '/facilities/unit/11' });
  await form.getByRole('button', { name: 'Save drum', exact: true }).click(); await navigation;
  exactPost(count, '/facilities/unit/11/update', fields);
});

test('archive with an active batch exposes the reason and disables acknowledgement and submit', { timeout: 60000 }, async t => {
  const page = await pageFor(t);
  const count = posts.length;
  await page.getByRole('button', { name: 'Archive drum', exact: true }).click();
  const modal = page.locator('#archiveUnitModal'); await modal.waitFor({ state: 'visible' });
  assert.match(await modal.getByRole('status').textContent(), /active batch.*Complete or cancel/s);
  const checkbox = modal.locator('[name="retirement_confirmed"]');
  assert.equal(await checkbox.isDisabled(), true);
  assert.equal(await checkbox.getAttribute('required'), '');
  assert.equal(await checkbox.inputValue(), '1');
  assert.equal(await modal.locator('button[type="submit"]').isDisabled(), true);
  assert.equal(await modal.locator('[name="expected_version"]').inputValue(), '3');
  assert.equal(await modal.locator('form').getAttribute('action'), origin + '/facilities/unit/11/archive');
  assert.equal(await modal.locator('form[data-bmg-submit][data-confirm][method="POST"]').count(), 1);
  assert.equal(await page.locator('#synapseConfirmModal.show').count(), 0);
  assert.equal(posts.length, count);
});

test('idle archive requires local retirement acknowledgement then unstacked consent and exact POST', { timeout: 60000 }, async t => {
  const page = await pageFor(t, '/facilities/unit/11?variant=idle');
  const count = posts.length;
  await page.getByRole('button', { name: 'Archive drum', exact: true }).click();
  const modal = page.locator('#archiveUnitModal'); await modal.waitFor({ state: 'visible' });
  const form = modal.locator('form');
  const checkbox = modal.locator('[name="retirement_confirmed"]');
  assert.equal(await checkbox.isEnabled(), true);
  assert.equal(await modal.locator('button[type="submit"]').isEnabled(), true);
  assert.equal(await checkbox.isChecked(), false);
  await modal.locator('button[type="submit"]').click();
  assert.equal(await form.evaluate(form => form.checkValidity()), false);
  assert.equal(await page.locator('#synapseConfirmModal.show').count(), 0);
  assert.equal(posts.length, count);
  await checkbox.check();
  const fields = await expectedFields(form, { expected_version: '3', retirement_confirmed: '1' });
  await modal.locator('button[type="submit"]').click();
  await sharedConsent(page);
  assert.equal(await modal.isVisible(), false);
  assert.equal(posts.length, count, 'No archive before consent');
  await page.locator('#synapseConfirmModal [data-bs-dismiss="modal"]').last().click();
  await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
  assert.equal(posts.length, count, 'Cancel shared consent does not write');
  await page.getByRole('button', { name: 'Archive drum', exact: true }).click();
  await modal.waitFor({ state: 'visible' });
  await modal.locator('button[type="submit"]').click(); await sharedConsent(page);
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL(origin + '/facilities');
  exactPost(count, '/facilities/unit/11/archive', fields);
});

test('archived toggle renders safe restore with shared consent and no auto-enable fields', { timeout: 60000 }, async t => {
  const page = await pageFor(t, '/facilities');
  await page.getByRole('link', { name: 'Archived drums', exact: true }).click();
  await page.waitForURL(origin + '/facilities?view=archived');
  await page.getByRole('heading', { name: 'Archived drums', exact: true }).waitFor();
  assert.equal(await page.getByRole('link', { name: 'Archived drums', exact: true }).getAttribute('aria-current'), 'page');
  const card = page.locator('.bmg-drum-card');
  assert.match(await card.textContent(), /Archived at\s*2026-10-18 09:30:00/);
  assert.match(await card.textContent(), /No active device bound/);
  assert.match(await card.textContent(), /Saved disabled/);
  assert.match(await card.textContent(), /Controller setup needed/);
  assert.equal(await card.getByRole('link', { name: /Manage drum/ }).count(), 0);
  assert.equal(await page.getByRole('button', { name: /delete|erase/i }).count(), 0);
  const form = card.locator('form[method="POST"][data-bmg-submit][data-confirm]');
  assert.equal(await form.getAttribute('action'), origin + '/facilities/unit/11/restore');
  const fields = await expectedFields(form, { expected_version: '3' });
  assert.deepEqual(await form.locator('[name]').evaluateAll(inputs => inputs.map(input => input.name).sort()), ['csrf_test_name', 'expected_version']);
  const count = posts.length;
  await form.getByRole('button', { name: /Restore drum/ }).click(); await sharedConsent(page);
  assert.match(await page.locator('#synapseConfirmModal').textContent(), /offline.*schedule disabled.*registration.*no motor/s);
  assert.equal(posts.length, count);
  await page.locator('[data-confirm-accept]').click(); await page.waitForURL(origin + '/facilities');
  exactPost(count, '/facilities/unit/11/restore', fields);
  const empty = await pageFor(t, '/facilities?view=archived&variant=empty');
  await empty.getByRole('heading', { name: 'No archived drums', exact: true }).waitFor();
  assert.equal(await empty.getByRole('heading', { name: 'No drums registered', exact: true }).count(), 0);
  assert.equal(await empty.locator('form[action$="/restore"]').count(), 0);
});

for (const variant of ['reader', 'operator', 'config-only']) {
  test(`${variant} CRUD controls follow configuration permission, not batch permission`, { timeout: 60000 }, async t => {
    const allowed = variant === 'config-only';
    const detail = await pageFor(t, '/facilities/unit/11?variant=' + variant);
    assert.equal(await detail.getByRole('link', { name: 'All drums', exact: true }).count(), 1);
    assert.equal(await detail.getByRole('link', { name: 'Add drum', exact: true }).count(), Number(allowed));
    for (const name of ['Edit drum', 'Archive drum']) assert.equal(await detail.getByRole('button', { name, exact: true }).count(), Number(allowed));
    assert.equal(await detail.locator('#unitModal, #archiveUnitModal').count(), allowed ? 2 : 0);
    const overview = await pageFor(t, '/facilities?create=1&variant=' + variant);
    assert.equal(await overview.getByRole('button', { name: 'Add drum', exact: true }).count(), Number(allowed));
    if (allowed) await overview.locator('#unitModal.show').waitFor();
    else assert.equal(await overview.locator('#unitModal, [data-bmg-open-create]').count(), 0);
    const archived = await pageFor(t, '/facilities?view=archived&variant=' + variant);
    assert.equal(await archived.locator('nav[aria-label="Drum records"]').count(), Number(allowed));
    assert.equal(await archived.locator('form[action$="/restore"]').count(), Number(allowed));
    assert.equal(await archived.getByRole('button', { name: /Restore drum/ }).count(), Number(allowed));
  });
}
