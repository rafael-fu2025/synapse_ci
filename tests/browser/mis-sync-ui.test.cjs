'use strict';

// Read-only production view fixture: no app bootstrap, MIS, database or hardware.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium, expect } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright/test'));
let server, browser, origin, html;

function renderView() {
  const rendered = spawnSync('php', [], {
    cwd: root, encoding: 'utf8', timeout: 10000,
    input: `<?php
      function esc($value, $context = null) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
      function site_url($path) { return '/' . $path; }
      function csrf_field() { return '<input type="hidden" name="csrf_fixture" value="fixture-token">'; }
      class FixtureView {
        function extend($name) { return ''; }
        function section($name) { return ''; }
        function endSection() { return ''; }
        function render() {
          $actor = ['tenant_id' => 7];
          $catalogs = array_fill_keys(['departments', 'programs', 'year_levels'], ['total' => 0, 'last_synced_at' => null]);
          $lastSync = ['occurred_at' => '2026-01-01 00:00:00', 'actor_user_id' => 42];
          include 'app/Views/admin/mis_sync.php';
        }
      }
      (new FixtureView())->render();`
  });
  assert.equal(rendered.status, 0, `View fixture failed: ${rendered.error || rendered.stderr || rendered.stdout}`);
  assert.doesNotMatch(rendered.stdout, /Fatal error|Warning:|Parse error/);
  return `<!doctype html><html lang="en"><head><meta charset="utf-8">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    </head><body><main class="container py-4"><h1>MIS reference synchronization</h1>${rendered.stdout}</main>
    <script src="/assets/js/mis-sync.js"></script></body></html>`;
}

before(async () => {
  html = renderView();
  const assets = new Map([
    ['/assets/vendor/bootstrap/css/bootstrap.min.css', ['text/css', path.join(root, 'public/assets/vendor/bootstrap/css/bootstrap.min.css')]],
    ['/assets/js/mis-sync.js', ['text/javascript', path.join(root, 'public/assets/js/mis-sync.js')]]
  ]);
  for (const [, file] of assets.values()) assert.ok(fs.existsSync(file), `Missing production asset: ${file}`);
  server = http.createServer((req, res) => {
    // All mutations must be intercepted by Playwright, never forwarded to an app.
    if (req.method !== 'GET') { res.writeHead(405); res.end('Read-only fixture'); return; }
    if (req.url === '/admin/mis-sync') { res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(html); return; }
    const asset = assets.get(req.url);
    if (!asset) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': asset[0] }); res.end(fs.readFileSync(asset[1]));
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(dir) && fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install Chromium in writable/widget-test-tools before running UI tests');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => {
  await browser?.close();
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

async function progress(page, progressId, percent) {
  await page.evaluate(({ progressId, percent }) => {
    document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail: {
      event: 'mis.sync.progress', channel: 'user:7',
      data: { progress_id: progressId, percent, message: `Completed stage ${percent}` }
    } }));
  }, { progressId, percent });
}

for (const success of [true, false]) {
  test(`production MIS view: correlated progress and ${success ? 'confirmed success' : 'failed POST without false completion'}`, { timeout: 20000 }, async t => {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    t.after(() => context.close());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let posts = 0, reads = 0, postBody = '', resolvePost;
    const reply = new Promise(resolve => { resolvePost = resolve; });
    t.after(() => resolvePost());
    await context.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) return route.abort();
      if (request.method() === 'POST' && url.pathname === '/admin/mis-sync/run') {
        posts++;
        postBody = request.postData();
        assert.equal(request.headers().accept, 'application/json');
        assert.equal(request.headers()['x-requested-with'], 'XMLHttpRequest');
        await reply;
        return route.fulfill({ status: success ? 200 : 503, json: { success, message: 'Catalog validation failed. No references changed.' } });
      }
      if (url.pathname === '/admin/mis-sync' && request.headers().accept === 'application/json') {
        reads++;
        return route.fulfill({ json: {
          catalogs: Object.fromEntries(['departments', 'programs', 'year_levels'].map((name, index) =>
            [name, { total: 10 + index, last_synced_at: '2026-10-21 12:00:00' }])),
          lastSync: { occurred_at: '2026-10-21 12:00:00' }
        } });
      }
      if (request.method() !== 'GET') return route.abort();
      return route.continue();
    });
    await page.goto(origin + '/admin/mis-sync');
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('[data-sync-progress]')).toBeHidden();
    assert.equal(await page.locator('.progress').evaluate(el => getComputedStyle(el).display), 'flex', 'real Bootstrap CSS loaded');
    assert.equal(posts, 0); assert.equal(reads, 0);
    const button = page.locator('[data-mis-sync-form] button[type="submit"]');
    await button.click();
    await expect.poll(() => posts).toBe(1);
    const id = /name="progress_id"\r\n\r\n([^\r]+)/.exec(postBody)?.[1];
    assert.match(id || '', /^[a-f0-9-]{36}$/);
    assert.match(postBody, /fixture-token/);
    await expect(page.locator('[data-sync-percent]')).toHaveText('Waiting…');
    await progress(page, 'unrelated-run', 85);
    await expect(page.locator('[data-sync-percent]')).toHaveText('Waiting…');
    for (const stage of [5, 25, 45, 65, 85]) {
      await progress(page, id, stage);
      await expect(page.locator('[data-sync-percent]')).toHaveText(`${stage}%`);
      await expect(page.locator('[data-sync-bar]')).toHaveAttribute('aria-valuenow', String(stage));
      await expect(page.locator('[data-sync-message]')).toHaveText(`Completed stage ${stage}`);
    }
    await progress(page, id, 25);
    await expect(page.locator('[data-sync-percent]')).toHaveText('85%');
    if (success) {
      await progress(page, id, 100);
      await expect(page.locator('[data-sync-percent]')).toHaveText('100%');
      await expect(page.locator('[data-sync-message]')).toContainText('awaiting confirmation');
    }
    await expect(button).toBeDisabled();
    await expect(page.locator('[data-sync-result]')).toBeHidden();
    await page.locator('[data-mis-sync-form]').evaluate(form => {
      for (let i = 0; i < 3; i++) form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    assert.equal(posts, 1);
    resolvePost();
    await expect(button).toBeEnabled();
    await expect(page.locator('[data-sync-result]')).toBeVisible();
    await expect(page.locator('[data-sync-percent]')).toHaveText(success ? '100%' : '85%');
    if (success) {
      await expect(page.locator('[data-sync-result]')).toContainText('successfully');
      for (const [index, name] of ['departments', 'programs', 'year_levels'].entries()) {
        await expect(page.locator(`[data-sync-catalog="${name}"] [data-sync-total]`)).toHaveText(String(10 + index));
        await expect(page.locator(`[data-sync-catalog="${name}"] [data-sync-synced]`)).toHaveText('2026-10-21 12:00:00');
      }
      await expect(page.locator('[data-sync-last]')).toHaveText('2026-10-21 12:00:00');
      assert.equal(reads, 1);
    } else {
      await expect(page.locator('[data-sync-result]')).toHaveText('Catalog validation failed. No references changed.');
      assert.equal(reads, 0);
    }
    assert.equal(posts, 1);
    assert.deepEqual(errors, []);
  });
}

test('production form still submits natively without JavaScript, with POST intercepted', { timeout: 15000 }, async t => {
  const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block' });
  t.after(() => context.close());
  let posts = 0;
  await context.route('**/admin/mis-sync/run', route => {
    assert.equal(route.request().method(), 'POST');
    assert.match(route.request().postData(), /csrf_fixture=fixture-token/);
    posts++;
    return route.fulfill({ contentType: 'text/html', body: '<h1>Native fixture response</h1>' });
  });
  const page = await context.newPage();
  await page.goto(origin + '/admin/mis-sync');
  await page.locator('[data-mis-sync-form] button[type="submit"]').click();
  await expect(page.locator('h1')).toHaveText('Native fixture response');
  assert.equal(posts, 1);
});
