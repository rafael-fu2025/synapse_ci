'use strict';

// Full PHP view + inherited layout + local production assets; no controller, SQL, or live app server.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { spawnSync } = require('node:child_process');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const publicRoot = path.join(root, 'public');
const rendered = new Map();
let browser, server, origin;

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) && fs.readdirSync(cache).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(cache, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath, 'Install Playwright Chromium before running this test');
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') {
      const fixture = { origin, portal: url.searchParams.get('portal') || 'main',
        longAlert: url.searchParams.has('alert'), identifier: 'FIXTURE-123' };
      const key = JSON.stringify(fixture);
      if (!rendered.has(key)) {
        const result = spawnSync('php', ['tests/_support/render-login-responsive-fixture.php'], {
          cwd: root, input: key, encoding: 'utf8', timeout: 15_000
        });
        if (result.error || result.status !== 0 || /(?:Fatal error|Warning|Deprecated):/.test(result.stdout)) {
          res.writeHead(500, { 'Content-Type': 'text/plain' });
          res.end(`PHP fixture failed: ${result.error || result.stderr}\n${result.stdout}`);
          return;
        }
        rendered.set(key, result.stdout);
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(rendered.get(key));
      return;
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png',
        '.ico': 'image/x-icon', '.svg': 'image/svg+xml', '.woff': 'font/woff', '.woff2': 'font/woff2' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath });
});

after(async () => {
  try { if (browser) await browser.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
});

async function open(t, viewport, theme, portal = 'main', options = {}) {
  const context = await browser.newContext({ viewport, reducedMotion: 'reduce', colorScheme: theme });
  t.after(() => context.close());
  // Fonts are deliberately offline: exercise the shipped fallback typography deterministically.
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  await context.addInitScript(dark => localStorage.setItem('na-dark-mode', dark ? '1' : '0'), theme === 'dark');
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const response = await page.goto(`${origin}/fixture?portal=${portal}${options.alert ? '&alert=1' : ''}`);
  assert.equal(response.status(), 200, await response.text());
  await page.evaluate(() => document.fonts.ready);
  await page.waitForFunction(() => [...document.images].every(image => image.complete));
  assert.deepEqual(errors, [], 'Production login scripts must initialize without errors');
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  assert.equal(await page.locator('main .auth-pane form').count(), 1, 'Render the actual complete login view');
  const brokenImages = await page.locator('img').evaluateAll(images => images.filter(image => !image.naturalWidth).map(image => image.src));
  assert.deepEqual(brokenImages, [], 'Use real local brand assets');
  return page;
}

async function geometry(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const pane = document.querySelector('.auth-pane');
    return { width: doc.clientWidth, scrollWidth: Math.max(doc.scrollWidth, document.body.scrollWidth),
      height: innerHeight, scrollHeight: document.scrollingElement.scrollHeight,
      paneHeight: pane.getBoundingClientRect().height };
  });
}

async function reachable(page, selector) {
  const elements = page.locator(selector);
  assert.ok(await elements.count(), `Missing ${selector}`);
  for (const element of await elements.all()) {
    await element.scrollIntoViewIfNeeded();
    const result = await element.evaluate(el => {
      const r = el.getBoundingClientRect();
      const x = r.left + r.width / 2, y = r.top + r.height / 2;
      const hit = document.elementFromPoint(x, y);
      const clipped = [];
      for (let parent = el.parentElement; parent; parent = parent.parentElement) {
        const css = getComputedStyle(parent), box = parent.getBoundingClientRect();
        if (/(hidden|clip|auto|scroll)/.test(css.overflowY) && (r.top < box.top - 1 || r.bottom > box.bottom + 1)) clipped.push(parent.className || parent.tagName);
      }
      return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, width: r.width, height: r.height,
        viewportWidth: innerWidth, viewportHeight: innerHeight, hit: hit === el || el.contains(hit), clipped };
    });
    assert.ok(result.width > 0 && result.height > 0, `${selector} must be visible`);
    assert.ok(result.top >= -1 && result.bottom <= result.viewportHeight + 1 && result.left >= -1 && result.right <= result.viewportWidth + 1,
      `${selector} must be fully reachable: ${JSON.stringify(result)}`);
    assert.ok(result.hit, `${selector} must not be covered: ${JSON.stringify(result)}`);
    assert.deepEqual(result.clipped, [], `${selector} must not be clipped by an ancestor`);
  }
}

const controls = '#identifier, #password, [data-toggle-password], .auth-pane button[type="submit"], .auth-pane nav[aria-label="Socials"] a';
const viewports = [[1366, 650], [1280, 600], [1093, 560], [1024, 600], [1536, 760], [1366, 768], [1440, 800], [1920, 1080]];
const titles = { main: 'Welcome back', clinic: 'University Health Services',
  guidance: 'Guidance & Counseling Center', facilities: 'Biomechanical Goat (BMG)' };

for (const theme of ['light', 'dark']) {
  for (const portal of Object.keys(titles)) {
    for (const [width, height] of viewports) {
      test(`${theme} ${portal}: ${width}x${height} fits without unnecessary scrolling`, async t => {
        const page = await open(t, { width, height }, theme, portal);
        assert.equal((await page.locator('#login-title').textContent()).trim(), titles[portal]);
        const box = await geometry(page);
        assert.ok(box.scrollWidth <= box.width, `Horizontal overflow: ${JSON.stringify(box)}`);
        assert.ok(box.scrollHeight <= height + 1, `Unnecessary vertical scrolling: ${JSON.stringify(box)}`);
        assert.ok(box.paneHeight >= height - 1, 'Auth pane fills the viewport');
        await reachable(page, controls);
        assert.equal(await page.evaluate(() => scrollY), 0, 'Reaching controls must not scroll a fitting page');
      });
    }
  }
  for (const [width, height] of [[390, 600], [360, 568], [320, 480]]) {
    test(`${theme}: ${width}x${height} phone has no horizontal overflow and a reachable footer`, async t => {
      const page = await open(t, { width, height }, theme);
      const box = await geometry(page);
      assert.ok(box.scrollWidth <= box.width, `Horizontal overflow: ${JSON.stringify(box)}`);
      assert.equal(await page.locator('.auth-photo-aside').isVisible(), false);
      await reachable(page, controls);
      await reachable(page, '.auth-pane nav[aria-label="Socials"]');
    });
  }
  for (const scenario of [
    { name: 'very short viewport', width: 1024, height: 320 },
    { name: 'long server alerts', width: 390, height: 600, alert: true },
    { name: 'enlarged text', width: 1024, height: 600, enlarged: true },
    { name: 'long alerts and enlarged phone text', width: 390, height: 600, alert: true, enlarged: true }
  ]) {
    test(`${theme}: ${scenario.name} grows naturally and scrolls to every control`, async t => {
      const page = await open(t, { width: scenario.width, height: scenario.height }, theme, 'main', scenario);
      if (scenario.enlarged) await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
      if (scenario.alert) {
        assert.equal(await page.locator('.auth-pane .alert').count(), 2, 'Alerts must come from PHP flashdata');
        assert.ok((await page.locator('.auth-pane .alert').first().textContent()).length > 1000);
      }
      const box = await geometry(page);
      assert.ok(box.scrollHeight > box.height, `Overflowing content must grow the document: ${JSON.stringify(box)}`);
      assert.ok(box.scrollWidth <= box.width, `No horizontal overflow: ${JSON.stringify(box)}`);
      const overflow = await page.locator('html, body, .auth-main-wrapper, .auth-pane').evaluateAll(elements =>
        elements.map(el => ({ element: el.className || el.tagName, overflowY: getComputedStyle(el).overflowY })));
      assert.ok(overflow.every(el => !['hidden', 'clip'].includes(el.overflowY)),
        `Content overflow must stay scrollable, not hidden: ${JSON.stringify(overflow)}`);
      await reachable(page, controls);
      await reachable(page, '.auth-pane nav[aria-label="Socials"]');
      assert.ok(await page.evaluate(() => scrollY > 0), 'The document must actually scroll to the footer');
      if (scenario.alert) await reachable(page, '.auth-pane .alert .btn-close');
    });
  }
  test(`${theme}: actual form retains old input, CSRF, semantics and working password toggle`, async t => {
    const page = await open(t, { width: 390, height: 600 }, theme);
    const form = page.locator('.auth-pane form');
    assert.equal(await form.getAttribute('method'), 'POST');
    assert.equal(new URL(await form.getAttribute('action')).pathname, '/auth/login');
    assert.equal(await page.locator('#identifier').inputValue(), 'FIXTURE-123');
    for (const [id, autocomplete] of [['identifier', 'username'], ['password', 'current-password']]) {
      assert.equal(await page.locator(`#${id}`).getAttribute('name'), id);
      assert.equal(await page.locator(`#${id}`).getAttribute('autocomplete'), autocomplete);
      assert.notEqual(await page.locator(`#${id}`).getAttribute('required'), null);
    }
    const csrfName = await page.locator('meta[name="csrf-name"]').getAttribute('content');
    const csrfHash = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    assert.ok(csrfName && csrfHash, 'Real framework CSRF metadata must be nonempty');
    const csrf = form.locator('input[type="hidden"]');
    const fields = await csrf.evaluateAll(inputs => inputs.map(el => ({ name: el.name, value: el.value })));
    assert.ok(fields.some(field => field.name === csrfName && field.value === csrfHash), 'CSRF hidden field agrees with the framework metadata');
    await page.locator('#identifier').fill('STUDENT-456');
    await page.locator('#password').fill('fixture-password');
    for (const type of ['text', 'password']) {
      await page.locator('[data-toggle-password]').click();
      assert.equal(await page.locator('#password').getAttribute('type'), type);
      assert.equal(await page.locator('#password').inputValue(), 'fixture-password');
      assert.equal(await page.locator('#identifier').inputValue(), 'STUDENT-456');
      const data = await form.evaluate(el => Object.fromEntries(new FormData(el)));
      assert.deepEqual(data, { [csrfName]: csrfHash, identifier: 'STUDENT-456', password: 'fixture-password' });
    }
    await page.locator('#authThemeToggle').click();
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme === 'dark' ? 'light' : 'dark');
  });
}
