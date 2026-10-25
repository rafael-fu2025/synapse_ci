'use strict';

// Run only: node --test tests/browser/toast.test.cjs
// Actual toast, Bootstrap and theme assets; local fixture only, no PHP/app server.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
let playwright;
try { playwright = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright')); }
catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  playwright = require('playwright');
}
let browser, server, origin;
const markup = `<!doctype html><html lang="en" data-theme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,"><link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
<link rel="stylesheet" href="/assets/css/style.css"></head><body class="app-shell">
<main id="main"><button id="trigger">Load records</button></main>
<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/toast.js"></script></body></html>`;

before(async () => {
  server = http.createServer((req, res) => {
    if (req.url === '/') { res.setHeader('Content-Type', 'text/html'); res.end(markup); return; }
    if (req.url === '/retry') { res.setHeader('Content-Type', 'application/json'); res.end('{"ok":true}'); return; }
    const file = path.resolve(root, 'public', '.' + new URL(req.url, 'http://localhost').pathname);
    const base = path.join(root, 'public') + path.sep;
    if (!file.startsWith(base) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      res.writeHead(404); res.end(); return;
    }
    res.setHeader('Content-Type', ({ '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.woff': 'font/woff' })[path.extname(file)] || 'application/octet-stream');
    res.end(fs.readFileSync(file));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = playwright.chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) ? fs.readdirSync(cache)
      .filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file)) : undefined;
    assert.ok(executablePath, 'Install Playwright Chromium before running the focused suite');
  }
  browser = await playwright.chromium.launch({ headless: true, executablePath });
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });

async function fixture(t, options = {}) {
  const page = await browser.newPage(options);
  t.after(() => page.close());
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(() => assert.deepEqual(errors, [], 'No browser errors/unhandled rejections'));
  await page.goto(origin);
  await page.locator('#trigger').focus();
  return page;
}
const count = page => page.locator('.synapse-toast').count();
async function gone(page) { await page.waitForFunction(() => !document.querySelector('.synapse-toast')); }

test('layout loads once after CSRF and Bootstrap, before feature handlers; CSS imports once', () => {
  const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/app.php'), 'utf8');
  const needle = "base_url('assets/js/toast.js')";
  assert.equal(layout.split(needle).length - 1, 1);
  assert.ok(layout.indexOf('bootstrap.bundle.min.js') < layout.indexOf(needle));
  assert.ok(layout.indexOf('assets/js/csrf.js') < layout.indexOf(needle));
  assert.ok(layout.indexOf(needle) < layout.indexOf('assets/js/realtime.js'));
  assert.equal(fs.readFileSync(path.join(root, 'public/assets/css/style.css'), 'utf8').split('@import url("toast.css");').length - 1, 1);
});

test('safe text, tone enum, live-region urgency and focus-preserving keyboard close', async t => {
  const page = await fixture(t);
  await page.evaluate(() => {
    SynapseToast.show('<img src=x onerror="window.pwned=1">', { type: 'success', duration: 0 });
    SynapseToast.show('Attention', { type: 'warning' });
    SynapseToast.show('Fallback', { type: '__proto__' });
  });
  await page.waitForFunction(() => document.querySelector('.synapse-toast-message')?.textContent.includes('<img'));
  assert.equal(await page.locator('.synapse-toast img').count(), 0);
  assert.equal(await page.evaluate(() => window.pwned), undefined);
  assert.equal(await page.locator('.synapse-toast').nth(0).locator('[role="status"]').getAttribute('aria-live'), 'polite');
  assert.equal(await page.locator('.synapse-toast').nth(1).locator('[role="alert"]').getAttribute('aria-live'), 'assertive');
  assert.equal(await page.locator('.synapse-toast').nth(2).getAttribute('data-type'), 'info');
  assert.equal(await page.evaluate(() => document.activeElement.id), 'trigger');
  await page.locator('.synapse-toast-close').first().focus();
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('.synapse-toast').length === 2);
});

test('success defaults to five seconds; error, warning and info default sticky', async t => {
  const page = await fixture(t);
  await page.evaluate(() => SynapseToast.show('Saved', { type: 'success' }));
  await page.waitForTimeout(4200);
  assert.equal(await count(page), 1);
  await gone(page);
  await page.evaluate(() => ['error', 'warning', 'info'].forEach(type => SynapseToast.show(type, { type })));
  await page.waitForTimeout(5300);
  assert.equal(await count(page), 3);
  assert.equal(await page.locator('[data-type="error"] [role="alert"]').getAttribute('aria-live'), 'assertive');
});

test('keyed updates reuse handle/DOM, reset duration and action; oldest evicted at max three', async t => {
  const page = await fixture(t);
  assert.equal(await page.evaluate(() => {
    window.old = SynapseToast.show('First', { key: 'records', duration: 250, action: { label: 'Old', run() {} } });
    window.node = document.querySelector('.synapse-toast');
    return old === SynapseToast.show('Updated', { key: 'records', type: 'success', duration: 0 });
  }), true);
  await page.waitForTimeout(600);
  assert.equal(await count(page), 1);
  assert.equal(await page.locator('.synapse-toast-message').textContent(), 'Updated');
  assert.equal(await page.evaluate(() => node === document.querySelector('.synapse-toast')), true);
  assert.equal(await page.locator('.synapse-toast-action').isVisible(), false);
  await page.evaluate(() => ['Two', 'Three', 'Four'].forEach(text => SynapseToast.show(text)));
  assert.equal(await count(page), 3);
  assert.equal(await page.evaluate(() => bootstrap.Toast.getInstance(node)), null);
  await page.evaluate(() => { old.dismiss(); old.dismiss(); });
  assert.equal(await count(page), 3);
  await page.evaluate(() => SynapseToast.show('Replacement', { key: 'records' }));
  assert.equal(await count(page), 3);
});

test('explicit timers pause on hover and focus, resume, and dispose on hidden', async t => {
  const page = await fixture(t);
  await page.evaluate(() => {
    SynapseToast.show('Timed', { duration: 600 });
    window.timedNode = document.querySelector('.synapse-toast');
  });
  await page.locator('.synapse-toast').hover();
  await page.waitForTimeout(850);
  assert.equal(await count(page), 1);
  await page.locator('.synapse-toast-close').focus();
  await page.mouse.move(0, 0);
  await page.waitForTimeout(850);
  assert.equal(await count(page), 1);
  await page.locator('#trigger').focus();
  await gone(page);
  assert.equal(await page.evaluate(() => bootstrap.Toast.getInstance(timedNode)), null);
  await page.evaluate(() => {
    window.manual = SynapseToast.show('Manual', { duration: 400 });
    window.manualNode = document.querySelector('.synapse-toast');
    manual.dismiss();
  });
  await gone(page);
  await page.waitForTimeout(600);
  assert.equal(await page.evaluate(() => bootstrap.Toast.getInstance(manualNode)), null);
});

test('GET retry, synchronous throw and rejected promise are caught; keyed action races are safe', async t => {
  const page = await fixture(t);
  await page.evaluate(() => SynapseToast.show('Retry load', { key: 'retry', action: {
    label: '<Retry>', async run() { window.result = await (await fetch('/retry')).json(); }
  } }));
  await page.locator('.synapse-toast-action').click();
  await page.waitForFunction(() => window.result?.ok);
  for (const asyncFailure of [false, true]) {
    await page.evaluate(flag => SynapseToast.show('Failure', { key: 'retry', action: {
      label: 'Retry', run() { if (flag) return Promise.reject(new Error('async')); throw new Error('sync'); }
    } }), asyncFailure);
    await page.locator('.synapse-toast-action').click();
    await page.waitForFunction(() => !document.querySelector('.synapse-toast-action').disabled);
  }
  await page.evaluate(() => SynapseToast.show('Old action', { key: 'retry', action: {
    label: 'Run', run() { return new Promise(resolve => { window.finishAction = resolve; }); }
  } }));
  await page.locator('.synapse-toast-action').click();
  assert.equal(await page.locator('.synapse-toast-action').isDisabled(), true);
  await page.evaluate(() => {
    SynapseToast.show('New action', { key: 'retry', action: { label: 'New', run() {} } });
    finishAction();
  });
  assert.equal(await page.locator('.synapse-toast-action').textContent(), 'New');
  assert.equal(await page.locator('.synapse-toast-action').isDisabled(), false);
});

test('body host survives main replacement and duplicate script loading; mobile themes and reduced motion', async t => {
  const page = await fixture(t, { viewport: { width: 320, height: 640 }, reducedMotion: 'reduce' });
  await page.evaluate(() => {
    window.api = SynapseToast;
    SynapseToast.show('Long message '.repeat(20), { duration: 0 });
    document.querySelector('#main').outerHTML = '<main id="main">Next page</main>';
  });
  await page.addScriptTag({ url: origin + '/assets/js/toast.js' });
  assert.equal(await page.evaluate(() => api === SynapseToast), true);
  assert.equal(await page.locator('body > #synapse-toast-container').count(), 1);
  for (const width of [320, 375, 1280]) {
    await page.setViewportSize({ width, height: 720 });
    for (const theme of ['light', 'dark']) {
      await page.evaluate(value => document.documentElement.dataset.theme = value, theme);
      const geometry = await page.locator('.synapse-toast').evaluate(element => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        const bodyStyle = getComputedStyle(document.body);
        const probe = document.createElement('div');
        probe.style.backgroundColor = bodyStyle.getPropertyValue('--surface-color');
        probe.style.color = bodyStyle.getPropertyValue('--default-color');
        document.body.append(probe);
        const expected = getComputedStyle(probe);
        const result = { x: rect.x, right: rect.right, top: rect.top, width: rect.width,
          bg: style.backgroundColor, color: style.color, expectedBg: expected.backgroundColor,
          expectedColor: expected.color, transition: style.transitionDuration,
          header: parseFloat(bodyStyle.getPropertyValue('--header-height')),
          scroll: document.documentElement.scrollWidth };
        probe.remove(); return result;
      });
      assert.ok(geometry.width <= 360 && geometry.x >= 16 && geometry.right <= width - 15);
      assert.equal(geometry.top, geometry.header + 16);
      assert.equal(geometry.bg, geometry.expectedBg);
      assert.equal(geometry.color, geometry.expectedColor);
      assert.equal(geometry.transition, '0s');
      assert.ok(geometry.scroll <= width);
    }
  }
});
