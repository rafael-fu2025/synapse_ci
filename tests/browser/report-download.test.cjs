'use strict';

// node --test --test-concurrency=1 tests/browser/report-download.test.cjs
// Real browser + production assets; synthetic loopback HTTP only, no app/DB/login.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { setTimeout: delay } = require('node:timers/promises');

const root = path.resolve(__dirname, '../..');
let playwright;
try { playwright = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright')); }
catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  playwright = require('playwright');
}
const scripts = ['loading.js', 'form-loading.js', 'csrf.js', 'report-download.js'];
const hasCSS = fs.existsSync(path.join(root, 'public/assets/css/loading.css'));
const fixtures = new Map();
let server, browser, origin, nextId = 0;

function executablePath() {
  const expected = playwright.chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const cached = fs.existsSync(cache) ? fs.readdirSync(cache)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(name => path.join(cache, name, 'chrome-win64/chrome.exe'))
    .find(file => fs.existsSync(file)) : undefined;
  assert.ok(cached, `No local Chromium at ${expected} or ${cache}; install it before running this offline test`);
  return cached;
}

function html(id) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-name" content="csrf_test"><meta name="csrf-token" content="initial-token">
    <meta name="csrf-header" content="X-CSRF-TEST"><link rel="icon" href="data:,">
    ${hasCSS ? '<link rel="stylesheet" href="/assets/css/loading.css">' : ''}
    </head><body><main id="fixture-root"><form data-report-download method="post" action="/export?id=${id}">
    <input type="hidden" name="csrf_test" value="initial-token">
    <input type="hidden" name="unit" value="synthetic-unit">
    <input type="hidden" name="from" value="2030-01-01">
    <input type="hidden" name="to" value="2030-01-31">
    <button type="submit" name="format" value="csv">Download CSV</button>
    <button type="submit" name="format" value="pdf">Download PDF</button>
    </form></main>${scripts.map(name => `<script src="/assets/js/${name}"></script>`).join('')}
    <script>
    window.saveSnapshots = [];
    document.addEventListener('click', event => {
      const link = event.target.closest('a[download]');
      if (link) window.saveSnapshots.push({
        progress: document.querySelector('.report-download-panel progress')?.value,
        message: document.querySelector('.report-download-message')?.textContent,
        filename: link.download
      });
    }, true);
    </script></body></html>`;
}

function payload(format) {
  return Buffer.from(format === 'pdf'
    ? '%PDF-1.4\n% synthetic streamed browser fixture\n' + '0123456789abcdef'.repeat(768) + '\n%%EOF\n'
    : 'unit,value\r\n' + 'synthetic-unit,42\r\n'.repeat(768), 'utf8');
}

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const fixture = fixtures.get(url.searchParams.get('id'));
    if (req.method === 'GET' && url.pathname === '/fixture' && fixture) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(html(fixture.id));
      return;
    }
    if (req.method === 'POST' && url.pathname === '/export' && fixture) {
      const chunks = [];
      req.on('data', chunk => chunks.push(chunk));
      req.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf8');
        const fields = Object.fromEntries(Array.from(body.matchAll(/name="([^"]+)"\r\n\r\n([^\r]*)\r\n/g), match => [match[1], match[2]]));
        const plan = fixture.plans.shift() || { mode: 'unexpected' };
        const record = { fields, headers: req.headers, res, closed: false, ended: false, mode: plan.mode };
        fixture.posts.push(record);
        res.on('close', () => { record.closed = true; });
        const csrf = { 'X-CSRF-NAME': 'csrf_rotated', 'X-CSRF-TOKEN': 'header-token', 'X-CSRF-HEADER': 'X-CSRF-ROTATED' };
        if (plan.mode === 'json422' || plan.mode === 'json403') {
          const status = plan.mode === 'json422' ? 422 : 403;
          res.writeHead(status, { ...csrf, 'Content-Type': 'application/json' }).end(JSON.stringify({
            message: `Synthetic ${status}: <img src=x onerror=alert(1)>`,
            csrf: { name: 'csrf_rotated', hash: 'json-token', header: 'X-CSRF-ROTATED' }
          }));
        } else if (plan.mode === 'html') {
          res.writeHead(200, { ...csrf, 'Content-Type': 'text/html' }).end('<!doctype html><h1>Sign in</h1>');
        } else if (plan.mode === 'redirect') {
          res.writeHead(302, { Location: '/login' }).end();
        } else if (plan.mode === 'unexpected') {
          res.writeHead(500).end('Unexpected duplicate POST');
        } else {
          record.bytes = payload(fields.format);
          record.filename = `synthetic-report.${fields.format}`;
          const headers = { ...csrf, 'Content-Type': fields.format === 'pdf' ? 'application/pdf' : 'text/csv',
            'Content-Disposition': `attachment; filename="${record.filename}"` };
          if (plan.mode !== 'missing-length') headers['Content-Length'] = record.bytes.length;
          res.writeHead(200, headers);
          res.flushHeaders();
          record.offset = Math.floor(record.bytes.length / 3);
          res.write(record.bytes.subarray(0, record.offset));
        }
      });
      return;
    }
    if (req.method === 'GET' && url.pathname === '/login') {
      if (fixture) fixture.loginHits++;
      res.writeHead(200, { 'Content-Type': 'text/html' }).end('<h1>Login</h1>');
      return;
    }
    const allowed = [...scripts.map(name => `/assets/js/${name}`), '/assets/css/loading.css'];
    if (req.method !== 'GET' || !allowed.includes(url.pathname)) { res.writeHead(404).end(); return; }
    fs.readFile(path.join(root, 'public', url.pathname), (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' }).end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await playwright.chromium.launch({ headless: true, executablePath: executablePath(), timeout: 20_000 });
}, { timeout: 30_000 });

after(async () => {
  try { if (browser) await browser.close(); }
  finally {
    if (server?.listening) await new Promise((resolve, reject) => {
      server.close(error => error ? reject(error) : resolve());
      server.closeAllConnections();
    });
  }
}, { timeout: 15_000 });

async function until(check, description) {
  const deadline = Date.now() + 4_000;
  while (!check()) {
    assert.ok(Date.now() < deadline, `Timed out waiting for ${description}`);
    await delay(20);
  }
}

async function open(t, plans, viewport = { width: 1280, height: 800 }) {
  const fixture = { id: String(++nextId), plans: plans.map(mode => ({ mode })), posts: [], loginHits: 0 };
  fixtures.set(fixture.id, fixture);
  const context = await browser.newContext({ acceptDownloads: true, viewport, reducedMotion: 'reduce', serviceWorkers: 'block' });
  t.after(async () => {
    await context.close();
    fixture.posts.forEach(post => post.res.destroy());
    fixtures.delete(fixture.id);
  });
  const errors = [];
  await context.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(origin + '/')) return route.continue();
    errors.push(`External request blocked: ${url}`);
    return route.abort();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(4_000);
  page.setDefaultNavigationTimeout(8_000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.url().includes('/assets/') && response.status() !== 200) errors.push(`Asset HTTP ${response.status()}: ${response.url()}`);
  });
  t.after(() => assert.deepEqual(errors, [], 'Production scripts/assets must load without browser errors or external requests'));
  fixture.downloads = [];
  page.on('download', download => fixture.downloads.push(download));
  await page.goto(`${origin}/fixture?id=${fixture.id}`);
  await page.waitForFunction(() => window.SynapseLoading && window.SynapseCsrf && window.SynapseReportDownloads);
  return { page, fixture };
}

async function start(page, fixture, format = 'csv') {
  const count = fixture.posts.length;
  const csrfName = await page.locator('meta[name="csrf-name"]').getAttribute('content');
  await page.locator(`button[value="${format}"]`).click();
  await until(() => fixture.posts.length === count + 1, 'one export POST');
  const post = fixture.posts[count];
  assert.deepEqual(Object.keys(post.fields).sort(), [csrfName, 'format', 'from', 'to', 'unit'].sort());
  assert.equal(post.fields.format, format);
  assert.equal(post.fields.unit, 'synthetic-unit');
  assert.equal(post.fields.from, '2030-01-01');
  assert.equal(post.fields.to, '2030-01-31');
  assert.equal(post.headers['x-requested-with'], 'XMLHttpRequest');
  return post;
}

async function receiving(page) {
  await page.waitForFunction(() => document.querySelector('.report-download-message')?.textContent.startsWith('Receiving file'));
  assert.equal(await page.locator('form').getAttribute('aria-busy'), 'true');
  assert.equal(await page.locator('form .app-loading-spinner').count(), 1);
  assert.deepEqual(await page.locator('button[type="submit"]').evaluateAll(buttons => buttons.map(b => b.disabled)), [true, true]);
}

async function cleaned(page) {
  await page.waitForFunction(() => !document.querySelector('form').hasAttribute('aria-busy') &&
    [...document.querySelectorAll('button[type="submit"]')].every(button => !button.disabled));
  assert.equal(await page.locator('form [aria-busy], form .app-loading-spinner, form .app-loading-status, form.app-loading-region, form .app-loading-button').count(), 0);
}

async function finish(page, fixture, post) {
  const downloadsBefore = fixture.downloads.length;
  assert.equal(post.closed, false, 'Transfer must still be open');
  const downloadPromise = page.waitForEvent('download', { timeout: 4_000 });
  post.ended = true;
  post.res.end(post.bytes.subarray(post.offset));
  const download = await downloadPromise;
  assert.equal(post.ended, true);
  assert.equal(download.suggestedFilename(), post.filename);
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  assert.deepEqual(Buffer.concat(chunks), post.bytes, 'Downloaded bytes must exactly match the streamed attachment');
  assert.equal(await download.failure(), null);
  assert.equal(fixture.downloads.length, downloadsBefore + 1);
  const snapshot = await page.evaluate(() => window.saveSnapshots.at(-1));
  assert.deepEqual(snapshot, { progress: 100, message: '100% received. Starting browser download…', filename: post.filename });
  await page.waitForFunction(() => document.querySelector('.report-download-panel')?.dataset.state === 'complete');
  await cleaned(page);
}

async function noSave(page, fixture, count = 0) {
  // Wait beyond the production 120ms completion/paint delay to catch late saves.
  await page.waitForTimeout(180);
  assert.equal(fixture.downloads.length, count, 'No browser download before a fully received valid attachment');
  assert.equal(await page.evaluate(() => window.saveSnapshots.length), count);
}

async function failed(page, fixture, message) {
  await page.waitForFunction(() => document.querySelector('.report-download-panel')?.dataset.state === 'error');
  if (message) assert.match(await page.locator('.report-download-message').textContent(), message);
  await cleaned(page);
  await noSave(page, fixture);
}

// Explicitly serial even when invoked by a concurrent test runner.
test('Report attachment download lifecycle (real production scripts)', { concurrency: false, timeout: 150_000 }, async t => {
  for (const format of ['csv', 'pdf']) {
    await t.test(`${format}: delayed chunks, progress, duplicate submit, exact save and CSRF retry`, { timeout: 20_000 }, async t => {
      const { page, fixture } = await open(t, ['stream', 'stream']);
      const post = await start(page, fixture, format);
      assert.equal(post.fields.csrf_test, 'initial-token');
      assert.equal(post.headers['x-csrf-test'], 'initial-token');
      await receiving(page);
      await page.waitForFunction(() => document.querySelector('progress').value > 0);
      const initial = await page.locator('progress').evaluate(el => el.value);
      assert.ok(initial > 0 && initial < 100);
      await page.evaluate(() => {
        const form = document.querySelector('form');
        form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('[value="pdf"]') }));
      });
      await noSave(page, fixture);
      assert.equal(fixture.posts.length, 1, 'Duplicate submit must not issue a second POST');
      const next = Math.floor(post.bytes.length * 2 / 3);
      post.res.write(post.bytes.subarray(post.offset, next));
      post.offset = next;
      await page.waitForFunction(value => document.querySelector('progress').value > value, initial);
      assert.ok(await page.locator('progress').evaluate(el => el.value) < 100);
      await noSave(page, fixture);
      await finish(page, fixture, post);
      assert.equal(await page.locator('input[name="csrf_rotated"]').inputValue(), 'header-token');
      const retry = await start(page, fixture, format === 'csv' ? 'pdf' : 'csv');
      assert.equal(retry.fields.csrf_rotated, 'header-token');
      assert.equal(retry.headers['x-csrf-rotated'], 'header-token');
      await receiving(page);
      await finish(page, fixture, retry);
      assert.equal(await page.locator('.report-download-panel').count(), 1);
    });
  }

  await t.test('missing Content-Length remains indeterminate until EOF', { timeout: 15_000 }, async t => {
    const { page, fixture } = await open(t, ['missing-length']);
    const post = await start(page, fixture);
    await receiving(page);
    await page.waitForFunction(() => document.querySelector('.report-download-message').textContent.includes('KB received'));
    assert.equal(await page.locator('progress').getAttribute('value'), null);
    await noSave(page, fixture);
    await finish(page, fixture, post);
  });

  for (const mode of ['json422', 'json403', 'html', 'redirect']) {
    await t.test(`${mode}: never download; clean up and allow retry`, { timeout: 15_000 }, async t => {
      const { page, fixture } = await open(t, [mode, 'stream']);
      await start(page, fixture);
      const message = mode.startsWith('json') ? new RegExp(`Synthetic ${mode.slice(4)}:`)
        : mode === 'html' ? /expected report file/ : /Connection lost or sign-in redirect/;
      await failed(page, fixture, message);
      assert.equal(await page.locator('.report-download-panel img').count(), 0, 'JSON messages must be rendered as text, never HTML');
      assert.equal(new URL(page.url()).pathname, '/fixture', 'A login response must not navigate the fixture');
      if (mode.startsWith('json')) {
        assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'json-token');
        assert.equal(await page.locator('input[name="csrf_rotated"]').inputValue(), 'json-token');
      }
      const retry = await start(page, fixture);
      if (mode.startsWith('json')) {
        assert.equal(retry.fields.csrf_rotated, 'json-token');
        assert.equal(retry.headers['x-csrf-rotated'], 'json-token');
      }
      await receiving(page);
      await finish(page, fixture, retry);
    });
  }

  await t.test('interrupted Content-Length stream never saves a partial file and permits retry', { timeout: 15_000 }, async t => {
    const { page, fixture } = await open(t, ['stream', 'stream']);
    const post = await start(page, fixture);
    await receiving(page);
    await noSave(page, fixture);
    post.res.destroy();
    await failed(page, fixture);
    const retry = await start(page, fixture);
    await receiving(page);
    await finish(page, fixture, retry);
  });

  await t.test('Cancel aborts the actual stream, restores both buttons and permits retry', { timeout: 15_000 }, async t => {
    const { page, fixture } = await open(t, ['stream', 'stream']);
    const post = await start(page, fixture);
    await receiving(page);
    await page.locator('.report-download-panel').getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('.report-download-panel')?.dataset.state === 'cancelled');
    await until(() => post.closed, 'server connection to close after cancellation');
    await cleaned(page);
    await noSave(page, fixture);
    const retry = await start(page, fixture, 'pdf');
    await receiving(page);
    await finish(page, fixture, retry);
  });

  await t.test('before-swap aborts the stream and removes the panel/loading state', { timeout: 15_000 }, async t => {
    const { page, fixture } = await open(t, ['stream', 'stream']);
    const post = await start(page, fixture);
    await receiving(page);
    await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', {
      detail: { root: document.querySelector('#fixture-root') }
    })));
    await until(() => post.closed, 'server connection to close after before-swap');
    await cleaned(page);
    assert.equal(await page.locator('.report-download-panel').count(), 0);
    await noSave(page, fixture);
    const retry = await start(page, fixture);
    await receiving(page);
    await finish(page, fixture, retry);
  });

  await t.test('mobile download panel stays inside the viewport', { skip: !hasCSS, timeout: 15_000 }, async t => {
    const { page, fixture } = await open(t, ['stream'], { width: 320, height: 720 });
    const post = await start(page, fixture);
    await receiving(page);
    const dimensions = await page.locator('.report-download-panel').evaluate(panel => {
      const box = panel.getBoundingClientRect();
      return { left: box.left, right: box.right, viewport: document.documentElement.clientWidth,
        document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
        panel: panel.clientWidth, content: panel.scrollWidth };
    });
    assert.ok(dimensions.left >= 0 && dimensions.right <= dimensions.viewport &&
      dimensions.document <= dimensions.viewport && dimensions.body <= dimensions.viewport &&
      dimensions.content <= dimensions.panel, `Mobile panel overflow: ${JSON.stringify(dimensions)}`);
    await noSave(page, fixture);
    await finish(page, fixture, post);
  });
});
