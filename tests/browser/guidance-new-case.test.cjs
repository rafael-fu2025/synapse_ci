'use strict';

// Actual CI views and globally loaded production scripts; all API/save traffic
// terminates at this DB-free HTTP fixture, never at an application backend.
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
const people = [
  { id: 11, first_name: 'Ada', last_name: 'Student', mis_id: 'S001', kind: 'student' },
  { id: 22, first_name: 'Grace', last_name: 'Employee', mis_id: 'E002', kind: 'employee' }
];
const deployments = [
  { name: 'root URLs', basePath: '/', indexPage: '', prefix: '' },
  { name: 'base path and index.php URLs', basePath: '/campus/', indexPage: 'index.php', prefix: '/campus/index.php' }
];
let browser, server, origin;
const cache = new Map(), searches = [], posts = [], fragments = [];

function render(kind, requestPath, fragment, deployment) {
  const key = JSON.stringify([kind, requestPath, fragment, deployment]);
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', ['tests/_support/render-modal-fixture.php'], {
    cwd: root, encoding: 'utf8', timeout: 10_000,
    input: JSON.stringify({ origin, path: requestPath, kind, fragment,
      basePath: deployment.basePath, indexPage: deployment.indexPage })
  });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /<div/);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  cache.set(key, result.stdout);
  return result.stdout;
}

function fields(body) {
  const result = {};
  for (const match of body.matchAll(/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([\s\S]*?)(?=\r\n--)/g)) {
    assert.equal(Object.hasOwn(result, match[1]), false, 'No duplicate form fields');
    result[match[1]] = match[2];
  }
  return result;
}

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
    assert.ok(executablePath, 'Install Playwright Chromium before running browser tests');
  }
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      const deployment = url.pathname.startsWith('/campus/') ? deployments[1] : deployments[0];
      const route = url.pathname.slice(deployment.prefix.length);
      if (req.method === 'POST' && route === '/guidance/cases/store') {
        const chunks = [];
        for await (const chunk of req) chunks.push(chunk);
        const submitted = fields(Buffer.concat(chunks).toString('utf8'));
        posts.push({ path: url.pathname, fields: submitted, headers: req.headers });
        // Retry is recognized only by the CSRF token returned with our 422.
        const retry = submitted.csrf_test_name === 'new-case-retry-token';
        res.writeHead(retry ? 200 : 422, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: retry,
          message: retry ? 'Fixture save accepted.' : 'Please revise the intake title and retry.',
          redirect: origin + deployment.prefix + '/guidance/cases',
          csrf: { name: 'csrf_test_name', hash: 'new-case-retry-token' } }));
        return;
      }
      if (req.method !== 'GET') { res.writeHead(405).end(); return; }
      if (route === '/api/patients/search') {
        searches.push(url);
        const q = (url.searchParams.get('q') || '').toLowerCase();
        const results = people.filter(person => `${person.first_name} ${person.last_name} ${person.mis_id}`.toLowerCase().includes(q));
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ results }));
        return;
      }
      if (route === '/guidance/cases' || route === '/guidance/cases/create') {
        const fragment = url.searchParams.get('fragment') === '1';
        if (route.endsWith('/create')) fragments.push({ path: url.pathname, fragment });
        const html = render(route.endsWith('/create') ? 'guidance-new-case' : 'guidance-case-list',
          url.pathname + url.search, fragment, deployment);
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(html);
        return;
      }
      // base_url assets deliberately omit index.php, unlike site_url endpoints.
      const assetPath = url.pathname.startsWith('/campus/') ? url.pathname.slice('/campus'.length) : url.pathname;
      const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + assetPath);
      if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
      fs.readFile(file, (error, content) => {
        if (error) { res.writeHead(404).end(); return; }
        res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
        res.end(content);
      });
    } catch (error) {
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end(error.stack);
    }
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20_000 });
}, { timeout: 40_000 });

after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
}, { timeout: 30_000 });

async function freshRegistry(t, deployment) {
  searches.length = 0; posts.length = 0; fragments.length = 0;
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  // Keep fonts and any incidental external resources offline as well.
  await context.route('**/*', route => new URL(route.request().url()).origin === origin
    ? route.continue() : route.fulfill({ status: 200, body: '', contentType: 'text/plain' }));
  const page = await context.newPage();
  page.setDefaultTimeout(6_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if ((message.type() === 'error' && !message.text().includes('Failed to load resource')) ||
        message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'No Alpine/runtime errors'); });
  const response = await page.goto(origin + deployment.prefix + '/guidance/cases');
  assert.equal(response.status(), 200, `Registry fixture failed to render: ${await response.text()}`);
  assert.match(await response.text(), /<!doctype html>/i, 'Registry fixture must render the full app shell');
  await page.waitForFunction(() => window.Alpine && window.bootstrap && document.readyState === 'complete');
  assert.equal(await page.evaluate(() => typeof window.newCaseForm), 'function',
    'care-forms.js must register newCaseForm on a cold registry load');
  assert.deepEqual(fragments, [], 'No direct create visit or earlier form may register the factory');
  assert.equal(await page.locator('#appFormModal').count(), 0);
  assert.equal(await page.locator('script[src$="/assets/js/care-forms.js"]').count(), 1);
  return page;
}

async function open(page, deployment) {
  const href = origin + deployment.prefix + '/guidance/cases/create';
  await page.locator(`a[data-app-modal-open][href="${href}"]`).click();
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.querySelector('form[data-modal-submit]') && modal.getAttribute('aria-modal') === 'true' &&
      bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  });
  assert.equal(await page.locator('#appFormModalTitle').innerText(), 'Open New Case');
  assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + deployment.prefix + '/guidance/cases/store');
  assert.equal(await page.locator('#appFormModal script').count(), 0, 'Fetched scripts are never needed');
  assert.equal(await page.locator('#appFormModal button[type="submit"]').isDisabled(), true);
  assert.equal(await page.locator('#appFormModal [name="person_id"]').inputValue(), '');
  assert.deepEqual(fragments.at(-1), { path: deployment.prefix + '/guidance/cases/create', fragment: true });
}

async function select(page, person, deployment) {
  await page.locator('#appFormModal input[placeholder^="Search Student"]').fill(person.mis_id);
  const result = page.locator('#appFormModal .list-group button').filter({ hasText: `${person.last_name}, ${person.first_name}` });
  await result.waitFor({ state: 'visible' });
  assert.match(await result.innerText(), new RegExp(person.kind.toUpperCase()));
  assert.equal(searches.at(-1).pathname, deployment.prefix + '/api/patients/search');
  assert.equal(searches.at(-1).searchParams.get('q'), person.mis_id);
  assert.deepEqual([...searches.at(-1).searchParams.keys()], ['q']);
  await result.click();
  await page.waitForFunction(id => document.querySelector('#appFormModal [name="person_id"]').value === String(id), person.id);
  assert.equal(await page.locator('#appFormModal [name="person_id"]').getAttribute('type'), 'hidden');
  assert.equal(await page.locator('#appFormModal button[type="submit"]').isEnabled(), true);
  assert.equal(await page.locator('#appFormModal .list-group').isVisible(), false);
  assert.match(await page.locator('#appFormModal .card').innerText(), new RegExp(`MIS ID: ${person.mis_id}`));
}

for (const deployment of deployments) {
  for (const person of people) {
    test(`cold New Case: ${person.kind}, ${deployment.name}, POST and 422 retry`, { timeout: 30_000 }, async t => {
      const page = await freshRegistry(t, deployment);
      await open(page, deployment);
      await select(page, person, deployment);
      await page.locator('#caseTitle').fill('Fixture intake title');
      await page.locator('#summary_notes').fill('Fixture-only confidential intake context.');
      // BalaySelect owns the native select in the production layout.
      await page.locator('#category').locator('..').locator('.bd-select-toggle').click();
      await page.getByRole('option', { name: 'Personal / Mental Health', exact: true }).click();
      // Each CLI render has its own token; production csrf.js uses the parent
      // page's token owner when FormData is constructed, not the fragment token.
      const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      assert.ok(csrf);
      await page.locator('#appFormModal button[type="submit"]').click();
      const error = page.locator('#appFormModal [data-modal-error]');
      await error.waitFor({ state: 'visible' });
      assert.equal(await error.innerText(), 'Please revise the intake title and retry.');
      assert.equal(page.url(), origin + deployment.prefix + '/guidance/cases');
      assert.equal(posts.length, 1);
      assert.deepEqual(posts[0].fields, { csrf_test_name: csrf, person_id: String(person.id),
        title: 'Fixture intake title', category: 'personal', summary_notes: 'Fixture-only confidential intake context.' });
      assert.equal(posts[0].path, deployment.prefix + '/guidance/cases/store');
      assert.equal(posts[0].headers.accept, 'application/json');
      assert.equal(posts[0].headers['x-requested-with'], 'XMLHttpRequest');
      assert.equal(await page.locator('#appFormModal [name="person_id"]').inputValue(), String(person.id));
      assert.equal(await page.locator('#caseTitle').inputValue(), 'Fixture intake title');
      assert.equal(await page.locator('#category').inputValue(), 'personal');
      assert.equal(await page.locator('#summary_notes').inputValue(), 'Fixture-only confidential intake context.');
      assert.equal(await page.locator('#appFormModal input[name="csrf_test_name"]').inputValue(), 'new-case-retry-token');
      await page.locator('#caseTitle').fill('Revised fixture intake title');
      await Promise.all([
        page.waitForNavigation(),
        page.locator('#appFormModal button[type="submit"]').click()
      ]);
      assert.equal(posts.length, 2);
      assert.deepEqual(posts[1].fields, { ...posts[0].fields, csrf_test_name: 'new-case-retry-token', title: 'Revised fixture intake title' });
      assert.equal(posts[1].path, posts[0].path);
      assert.equal(page.url(), origin + deployment.prefix + '/guidance/cases');
      assert.equal(await page.locator('#appFormModal').count(), 0);
    });
  }
}

test('New Case Change, Cancel and X close discard state and reopen cleanly', { timeout: 30_000 }, async t => {
  const deployment = deployments[0], page = await freshRegistry(t, deployment);
  await open(page, deployment);
  await select(page, people[0], deployment);
  await page.locator('#appFormModal').getByRole('button', { name: 'Change', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('#appFormModal [name="person_id"]').value);
  assert.equal(await page.locator('#appFormModal button[type="submit"]').isDisabled(), true);
  await select(page, people[1], deployment);
  await page.locator('#caseTitle').fill('Discard this unsaved title');
  for (const closeSelector of ['a[data-app-modal-close]', 'button[aria-label="Close"]']) {
    await page.locator('#appFormModal ' + closeSelector).click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    assert.equal(await page.locator('.modal-backdrop').count(), 0);
    assert.equal(page.url(), origin + '/guidance/cases');
    assert.equal(posts.length, 0, 'Dismissal must never submit');
    await open(page, deployment);
    assert.equal(await page.locator('#caseTitle').inputValue(), '');
    assert.equal(await page.locator('#appFormModal input[placeholder^="Search Student"]').inputValue(), '');
    assert.equal(await page.locator('#appFormModal .list-group button').count(), 0);
    await select(page, people[0], deployment);
  }
  assert.equal(fragments.length, 3);
  assert.equal(await page.locator('#appFormModal').count(), 1);
});
