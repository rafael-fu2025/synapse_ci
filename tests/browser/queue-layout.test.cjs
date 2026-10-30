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
const histories = {};
before(async () => {
  for (const unit of ['clinic', 'guidance']) {
    boards[unit] = execFileSync('C:/Users/udtoh_lmtzs7k/php83/php.exe', ['tests/_support/render-queue-board-fixture.php', unit], { cwd: root, encoding: 'utf8' });
  }
  for (const unit of ['clinic', 'guidance']) {
    boards[`${unit}-no-show`] = execFileSync('php', ['tests/_support/render-queue-board-fixture.php', unit, 'no-show-action'], { cwd: root, encoding: 'utf8' });
  }
  for (const unit of ['clinic', 'guidance']) {
    histories[unit] = {};
    for (const count of [0, 80]) {
      histories[unit][count] = JSON.parse(execFileSync('php', ['tests/_support/render-queue-board-fixture.php', unit, 'history-fixture', String(count)], { cwd: root, encoding: 'utf8' }));
    }
  }
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const historyRoute = url.pathname.match(/^\/(clinic|guidance)\/(history|history-board)$/);
    if (historyRoute) {
      const unit = historyRoute[1];
      const fixture = histories[unit][url.searchParams.get('count') === '80' ? 80 : 0];
      if (historyRoute[2] === 'history-board') {
        res.writeHead(200, { 'Content-Type': 'text/html' }).end(fixture.board);
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><html><head>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
        <link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/queue.css">
        </head><body class="app-shell"><main id="main" class="main"><div class="main-content">
        <div class="pagetitle"><h1>Queue fixture</h1></div><section class="section">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">${fixture.history}
        <input id="queueDateFilter_iso" name="date" value="2026-10-27" aria-label="Queue date"></div>
        <div id="queue-board-container" data-queue-unit="${unit}" hx-get="/${unit}/history-board?count=${url.searchParams.get('count') === '80' ? 80 : 0}" hx-trigger="queue:refresh">${fixture.board}</div>
        </section></div></main>
        <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="/assets/vendor/htmx/htmx.min.js"></script>
        <script src="/assets/js/queue-realtime.js"></script></body></html>`);
      return;
    }
    if (url.pathname.endsWith('-no-show') && boards[url.pathname.slice(1)]) {
      const unit = url.pathname.slice(1).replace('-no-show', '');
      const action = boards[url.pathname.slice(1)].replace(/(?:action|hx-post)="[^"]*"/g, attribute =>
        `${attribute.split('=')[0]}="/${unit}/queue/no-show/71"`);
      res.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><html><body>
        <div id="shell-marker">Queue shell</div><input id="queueDateFilter_iso" name="date" value="2026-10-25">
        <div id="queue-board-container">${action}</div>
        <script src="/assets/vendor/htmx/htmx.min.js"></script>
        <script src="/assets/js/confirm.js"></script></body></html>`);
      return;
    }
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
  for (const [theme, width] of [['light', 1366], ['dark', 1280], ['dark', 390]]) {
    test(`${unit} no-show drawer stays compact and live at ${width}px in ${theme}`, { timeout: 30000 }, async () => {
      const page = await browser.newPage({ viewport: { width, height: 768 }, reducedMotion: 'reduce' });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      try {
        await page.addInitScript(() => {
          window.queueFixtureSettled = 0;
          document.addEventListener('htmx:afterSettle', () => { window.queueFixtureSettled++; });
        });
        await page.goto(`${origin}/${unit}/history?count=0`);
        await page.waitForFunction(() => window.queueFixtureSettled > 0 && document.querySelector('.queue-column').getBoundingClientRect().height > 0);
        await page.evaluate(theme => document.documentElement.setAttribute('data-theme', theme), theme);
        assert.equal(await page.locator('.queue-status-dot').count(), 0, 'Queue headings have no colored dots');
        const metricIcons = await page.locator('.queue-metric-icon').evaluateAll(els => els.map(el => ({
          color: getComputedStyle(el).color,
          muted: getComputedStyle(el).getPropertyValue('--muted-color').trim(),
          background: getComputedStyle(el).backgroundColor,
          radius: getComputedStyle(el).borderRadius,
        })));
        assert.equal(metricIcons.length, 6);
        for (const icon of metricIcons) {
          assert.equal(icon.background, 'rgba(0, 0, 0, 0)', 'Metric icons have no colored placeholder');
          assert.equal(icon.radius, '0px');
        }
        if (theme === 'dark') {
          const tabColors = await page.locator('.queue-history-tabs .nav-link').evaluateAll(els => els.map(el => getComputedStyle(el).color));
          assert.deepEqual(tabColors, ['rgb(255, 255, 255)', 'rgb(255, 255, 255)']);
        }
        const trigger = page.locator('[data-bs-target="#queueNoShowHistory"]');
        const drawer = page.locator('#queueNoShowHistory');
        const list = drawer.locator('[data-queue-no-show-list]');
        const count = trigger.locator('[data-queue-no-show-count]');
        const columns = () => page.locator('.queue-column').evaluateAll(els => els.map(el => {
          const rect = el.getBoundingClientRect(); return { top: rect.top, height: rect.height };
        }));
        const initialColumns = await columns();
        assert.equal(await count.textContent(), '0');
        await trigger.click();
        await page.waitForFunction(() => document.querySelector('#queueNoShowHistory').classList.contains('show'));
        assert.equal(await drawer.getAttribute('aria-modal'), 'true');
        assert.match(await list.textContent(), /No patients marked no-show for this date/);
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => !document.querySelector('#queueNoShowHistory').hasAttribute('aria-modal'));
        assert.equal(await trigger.evaluate(el => el === document.activeElement), true);

        await page.evaluate(async unit => { await htmx.ajax('GET', `/${unit}/history-board?count=80`, { target: '#queue-board-container', swap: 'innerHTML' }); }, unit);
        assert.equal(await count.textContent(), '80');
        assert.deepEqual(await columns(), initialColumns, 'Long history must not change queue column geometry');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await trigger.click();
        await page.waitForFunction(() => document.querySelector('#queueNoShowHistory').classList.contains('show'));
        assert.equal(await list.locator('li').count(), 80);
        assert.equal(await list.locator('img, script').count(), 0, 'Patient names remain escaped');
        const geometry = await drawer.evaluate(el => {
          const rect = el.getBoundingClientRect(), body = el.querySelector('.offcanvas-body');
          return { width: rect.width, height: rect.height, scrolls: body.scrollHeight > body.clientHeight, overflow: body.scrollWidth > body.clientWidth };
        });
        assert.ok(Math.abs(geometry.width - Math.min(width, 440)) <= 1);
        assert.equal(geometry.height, 768);
        assert.equal(geometry.scrolls, true);
        assert.equal(geometry.overflow, false);
        const close = drawer.getByRole('button', { name: 'Close no-show history' });
        await close.focus();
        await list.evaluate(el => { el.scrollTop = 150; });
        await page.evaluate(async unit => { await htmx.ajax('GET', `/${unit}/history-board?count=80`, { target: '#queue-board-container', swap: 'innerHTML' }); }, unit);
        assert.equal(await list.evaluate(el => el.scrollTop), 150, 'Unchanged refresh preserves reading position');
        assert.equal(await close.evaluate(el => el === document.activeElement), true);
        const blocked = await page.evaluate(() => !document.dispatchEvent(new CustomEvent('synapse:before-navigate', { cancelable: true })));
        assert.equal(blocked, true, 'Open drawer protects navigation from leaking a scroll lock');
        await page.evaluate(async unit => { await htmx.ajax('GET', `/${unit}/history-board?count=0`, { target: '#queue-board-container', swap: 'innerHTML' }); }, unit);
        assert.equal(await count.textContent(), '0');
        assert.equal(await drawer.locator('[data-queue-no-show-count]').textContent(), '0');
        assert.match(await list.textContent(), /No patients marked no-show/);
        assert.equal(await drawer.getAttribute('aria-modal'), 'true', 'Refresh does not close the drawer');
        await close.click();
        await page.waitForFunction(() => !document.querySelector('#queueNoShowHistory').hasAttribute('aria-modal'));
        await trigger.click();
        await page.waitForFunction(() => document.querySelector('#queueNoShowHistory').classList.contains('show'));
        await page.locator('.offcanvas-backdrop').dispatchEvent('mousedown');
        await page.waitForFunction(() => !document.querySelector('#queueNoShowHistory').hasAttribute('aria-modal'));
        assert.equal(await page.locator('.offcanvas-backdrop').count(), 0);
        assert.notEqual(await page.locator('body').evaluate(el => el.style.overflow), 'hidden');
        await page.evaluate(() => {
          document.body.classList.add('is-navigating');
          bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('queueNoShowHistory')).show();
        });
        assert.equal(await drawer.getAttribute('aria-modal'), null, 'Pending navigation prevents a late drawer open');
        assert.deepEqual(errors, []);
      } finally { await page.close(); }
    });
  }
  test(`${unit} no-show confirms once and updates only the board, preserving date and CSRF`, { timeout: 30000 }, async () => {
    const page = await browser.newPage();
    const writes = [];
    let documents = 0;
    page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents++; });
    await page.route(`**/${unit}/queue/no-show/71`, async route => {
      const request = route.request();
      writes.push({ method: request.method(), headers: request.headers(), body: request.postData() });
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<div id="no-show-result">No-show history (1)</div>' });
    });
    try {
      await page.goto(`${origin}/${unit}-no-show`);
      await page.waitForFunction(() => Boolean(window.htmx));
      const csrf = await page.locator('form input[type="hidden"]').first().evaluate(el => ({ name: el.name, value: el.value }));
      await page.evaluate(() => { window.originalQueueShell = document.getElementById('shell-marker'); });
      page.once('dialog', dialog => dialog.dismiss());
      await page.getByRole('button', { name: 'Mark no-show' }).click();
      await page.waitForTimeout(100);
      assert.equal(writes.length, 0, 'Cancelled confirmation must not write');
      page.once('dialog', dialog => dialog.accept());
      await page.getByRole('button', { name: 'Mark no-show' }).click();
      await page.locator('#no-show-result').waitFor();
      assert.equal(writes.length, 1);
      assert.equal(writes[0].method, 'POST');
      assert.equal(writes[0].headers['hx-request'], 'true');
      const fields = new URLSearchParams(writes[0].body);
      assert.equal(fields.get('date'), '2026-10-25');
      assert.ok(csrf.value);
      assert.equal(fields.get(csrf.name), csrf.value);
      assert.equal(documents, 1, 'No document navigation after the initial load');
      assert.equal(await page.evaluate(() => window.originalQueueShell === document.getElementById('shell-marker')), true);
      assert.equal(await page.locator('#queueDateFilter_iso').inputValue(), '2026-10-25');
    } finally { await page.close(); }
  });
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
