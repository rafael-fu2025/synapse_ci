'use strict';
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const boards = {};
before(async () => {
  for (const unit of ['clinic', 'guidance']) {
    boards[unit] = execFileSync('C:/Users/udtoh_lmtzs7k/php83/php.exe', ['tests/_support/render-queue-board-fixture.php', unit], { cwd: root, encoding: 'utf8' });
  }
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (boards[url.pathname.slice(1)]) {
      res.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><html><head>
      <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
      <link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/queue.css">
      </head><body class="app-shell"><main id="main" class="main"><div class="main-content">
            <div class="pagetitle"><h1>Queue fixture</h1></div><section class="section">
            <div class="mb-3">Daily queue · Refreshes automatically</div>
            <div id="queue-board-container">${boards[url.pathname.slice(1)]}</div>
            </section></div></main>
      <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script></body></html>`);
      return;
    }
    const file = path.resolve(root, 'public', '.' + url.pathname);
    if (!file.startsWith(path.join(root, 'public/assets') + path.sep)) { res.writeHead(404).end(); return; }
    fs.readFile(file, (err, data) => {
      if (err) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : 'text/css' }).end(data);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ executablePath, headless: true });
});
after(async () => {
  await browser?.close();
  if (server?.listening) {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
});
for (const unit of ['clinic', 'guidance']) {
  for (const [theme, width] of [['light', 1560], ['dark', 1280], ['light', 390]]) {
    test(`${unit} queue headers, totals and history work at ${width}px in ${theme}`, async () => {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      try {
        await page.goto(`${origin}/${unit}`);
        await page.evaluate(theme => document.documentElement.setAttribute('data-theme', theme), theme);
        assert.equal(await page.locator('.queue-metric').count(), 6);
        assert.equal(await page.locator('.queue-column').count(), 4);
        assert.equal(await page.locator('.queue-column-header').count(), 4);
        assert.equal(await page.locator('.queue-column-count').count(), 5);
        assert.deepEqual(await page.locator('.queue-metric-value').allTextContents(), Array(6).fill('0'));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        const geometry = await page.locator('.queue-column-header').first().evaluate(el => {
          const css = getComputedStyle(el);
          return { height: el.getBoundingClientRect().height, border: css.borderBottomWidth, color: getComputedStyle(el.querySelector('h6')).color };
        });
        assert.ok(geometry.height >= 59);
        assert.equal(geometry.border, '1px');
        assert.notEqual(geometry.color, 'rgba(0, 0, 0, 0)');
        if (width >= 1200) {
          const bottoms = await page.locator('.queue-column').evaluateAll(els => els.map(el => el.getBoundingClientRect().bottom));
          for (const bottom of bottoms) assert.ok(Math.abs(bottom - 876) < 3, `Column ends at ${bottom}, expected viewport bottom minus padding`);
          const body = page.locator('.queue-column-body').first();
          const height = await body.evaluate(el => el.clientHeight);
          await body.evaluate(el => { const tickets = document.createElement('div'); tickets.style.height = '2000px'; el.append(tickets); });
          assert.equal(await body.evaluate(el => el.clientHeight), height);
          assert.ok(await body.evaluate(el => el.scrollHeight > el.clientHeight));
        }
        const tabs = page.locator('.queue-history-tabs .nav-link');
        const widths = await tabs.evaluateAll(els => els.map(el => el.getBoundingClientRect().width));
        assert.ok(Math.abs(widths[0] - widths[1]) <= 1, 'History tabs split the header equally');
        const appearance = () => tabs.evaluateAll(els => els.map(el => {
          const css = getComputedStyle(el);
          return { background: css.backgroundColor, underline: css.borderBottomColor, selected: el.getAttribute('aria-selected') };
        }));
        const initial = await appearance();
        assert.equal(initial[0].selected, 'true');
        assert.equal(initial[1].selected, 'false');
        assert.notEqual(initial[0].background, initial[1].background);
        assert.notEqual(initial[0].underline, initial[1].underline);
        await page.getByRole('tab', { name: /Done/ }).click();
        assert.equal(await page.locator('#tab-done').isVisible(), true);
        assert.equal(await page.locator('#tab-skipped').isVisible(), false);
        await page.waitForFunction(background => getComputedStyle(document.querySelector('.queue-history-tabs .nav-link.active')).backgroundColor === background, initial[0].background);
        const switched = await appearance();
        assert.equal(switched[0].selected, 'false');
        assert.equal(switched[1].selected, 'true');
        assert.equal(switched[1].background, initial[0].background);
        assert.equal(switched[1].underline, initial[0].underline);
      } finally { await page.close(); }
    });
  }
}
