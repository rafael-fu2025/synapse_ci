'use strict';

// node --test tests/browser/widget-surfaces.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');

const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const surfaceFailures = new WeakMap();
const referenceRadii = new WeakMap();

const fixture = `<!doctype html><html lang="en-GB"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
</head><body><main class="container p-4">
  <div id="card-reference" class="card"><div class="card-body">Reference card</div></div>
  <div hidden id="radius-sm" style="border-radius:var(--radius-sm)"></div>
  <div hidden id="radius-md" style="border-radius:var(--radius-md)"></div>
  <div hidden id="radius-lg" style="border-radius:var(--radius-lg)"></div>
  <label for="choice">Appointment type</label>
  <select id="choice" class="form-select">
    <option value="a">Consultation</option><option value="b">Follow-up</option>
  </select>
  <div class="dropdown my-3">
    <button id="bootstrap-toggle" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
    <ul id="bootstrap-menu" class="dropdown-menu"><li><button class="dropdown-item">Edit</button></li></ul>
  </div>
  <label for="date">Appointment date</label>
  <input id="date" class="form-control" data-datepicker data-target="#date-iso" autocomplete="off">
  <input id="date-iso" type="hidden" value="2030-01-15">
  <label for="datetime">Starts at</label>
  <input id="datetime" class="form-control" data-datetimepicker data-target="#datetime-iso" autocomplete="off">
  <input id="datetime-iso" type="hidden" value="2030-01-15T08:30">
  <button id="open-modal" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modal">Open modal</button>
  <button id="open-dialog" class="btn btn-secondary">Open dialog</button>
  <div id="modal" class="modal" tabindex="-1" aria-label="Appointment modal">
    <div class="modal-dialog"><div class="modal-content shadow">
      <div class="modal-body">Appointment details</div>
      <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    </div></div>
  </div>
  <dialog id="dialog" class="shadow" aria-label="Confirmation">
    <p>Confirm appointment</p><form method="dialog"><button>Close</button></form>
  </dialog>
</main>
<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/custom-dropdown.js"></script>
<script src="/assets/js/datepicker.js"></script>
<script src="/assets/js/datetimepicker.js"></script>
<script>document.querySelector('#open-dialog').addEventListener('click', () => document.querySelector('#dialog').showModal());</script>
</body></html>`;

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) ? fs.readdirSync(cache)
      .filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe'))
      .find(file => fs.existsSync(file)) : undefined;
    assert.ok(executablePath, 'Install Chromium for the existing browser-test tools');
  }
  browser = await chromium.launch({ executablePath, headless: true, timeout: 30_000 });
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(fixture);
      return;
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
}, { timeout: 40_000 });

after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      const closed = once(server, 'close');
      server.close();
      server.closeAllConnections();
      await closed;
    }
  }
});

async function open(t, theme, rootFont) {
  const context = await browser.newContext({ viewport: { width: 1200, height: 900 }, reducedMotion: 'reduce' });
  const errors = [];
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, [], 'Production assets must load without browser or HTTP errors');
  });
  await context.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
  const page = await context.newPage();
  surfaceFailures.set(page, []);
  t.after(() => assert.deepEqual(surfaceFailures.get(page), [], 'Computed production surface regressions'));
  page.setDefaultTimeout(5_000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.status() >= 400) errors.push(`HTTP ${response.status()}: ${response.url()}`);
  });
  await page.goto(`${origin}/fixture`);
  await page.evaluate(({ theme, rootFont }) => {
    document.documentElement.dataset.theme = theme;
    if (rootFont) document.documentElement.style.fontSize = rootFont;
  }, { theme, rootFont });
  const radii = await page.evaluate(() => {
    const corners = element => {
      const css = getComputedStyle(element);
      return [css.borderTopLeftRadius, css.borderTopRightRadius, css.borderBottomRightRadius, css.borderBottomLeftRadius];
    };
    return { card: corners(document.querySelector('#card-reference')),
      ...Object.fromEntries(['sm', 'md', 'lg'].map(size => [size, corners(document.querySelector(`#radius-${size}`))])) };
  });
  assert.deepEqual(radii.card, radii.lg, 'Production card must use the shared large-radius token');
  assert.ok(radii.card.every(radius => parseFloat(radius) > 0), 'Reference card must have a resolved radius');
  referenceRadii.set(page, radii);
  await page.waitForFunction(() => window.bootstrap && document.querySelector('#choice').dataset.bdSelect &&
    document.querySelector('#date').closest('.dp-wrap') && document.querySelector('#datetime').closest('.dtp-wrap'));
  return page;
}

async function surfaces(page, selector, count, token = 'lg') {
  const actual = await page.locator(selector).evaluateAll(elements => elements.map(element => {
    const css = getComputedStyle(element);
    return {
      radii: [css.borderTopLeftRadius, css.borderTopRightRadius, css.borderBottomRightRadius, css.borderBottomLeftRadius],
      shadow: css.boxShadow
    };
  }));
  const failures = surfaceFailures.get(page);
  if (actual.length !== count) failures.push(`${selector}: expected ${count} elements, found ${actual.length}`);
  const reference = referenceRadii.get(page);
  const expected = token === 'lg' ? reference.card : reference[token];
  const unexpected = actual.filter(style => style.radii.some((radius, index) => radius !== expected[index]) || style.shadow !== 'none');
  if (unexpected.length) {
    failures.push(`${selector}: expected --radius-${token} ${JSON.stringify(expected)} and no shadow; ${unexpected.length}/${actual.length} unexpected surfaces: ${JSON.stringify(
      [...new Set(unexpected.map(style => JSON.stringify(style)))].map(style => JSON.parse(style))
    )}`);
  }
}

for (const rootFont of [null, '20px']) {
for (const theme of ['light', 'dark']) {
  const check = (name, run) => test(`${theme}, root ${rootFont || 'production baseline'}: ${name}`, { timeout: 20_000 }, run);

  check('Bootstrap and real custom-select dropdowns stay flat, including shadow utility', async t => {
    const page = await open(t, theme, rootFont);
    await page.locator('#bootstrap-toggle').click();
    await page.locator('#bootstrap-menu').waitFor({ state: 'visible' });
    await surfaces(page, '#bootstrap-menu', 1, 'md');
    await page.locator('#bootstrap-menu').evaluate(menu => menu.classList.add('shadow'));
    await surfaces(page, '#bootstrap-menu.shadow', 1, 'md');
    await page.keyboard.press('Escape');
    const toggle = page.locator('#choice').locator('..').locator('.bd-select-toggle');
    await toggle.click();
    await page.locator('#choice').locator('..').locator('.bd-select-menu').waitFor({ state: 'visible' });

    const menu = page.locator('#choice').locator('..').locator('.bd-select-menu');
    await surfaces(page, '.bd-select-menu.show', 1, 'md');
    await menu.evaluate(element => element.classList.add('shadow'));
    await surfaces(page, '.bd-select-menu.show.shadow', 1, 'md');
  });

  check('Bootstrap modal and native dialog override shadow utility', async t => {
    const page = await open(t, theme, rootFont);
    await page.locator('#open-modal').click();
    await page.locator('#modal').waitFor({ state: 'visible' });
    await surfaces(page, '#modal .modal-content.shadow', 1);
    await page.locator('#modal [data-bs-dismiss]').click();
    await page.locator('#modal').waitFor({ state: 'hidden' });
    await page.locator('#open-dialog').click();
    await page.locator('#dialog').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#dialog').evaluate(dialog => dialog.matches(':modal')), true);
    await surfaces(page, 'dialog.shadow', 1);
  });

  check('date popup, selected day, header and footer controls use flat shared-token surfaces', async t => {
    const page = await open(t, theme, rootFont);
    await page.locator('#date').focus();
    await page.keyboard.press('ArrowDown');
    await page.locator('.dp-popup').waitFor({ state: 'visible' });
    await surfaces(page, '.dp-popup', 1);
    await surfaces(page, '.dp-cell.is-selected .dp-day', 1, 'sm');
    await surfaces(page, '.dp-nav', 2, 'sm');
    await surfaces(page, '.dp-head .bd-select-toggle', 2, 'sm');
    await surfaces(page, '.dp-foot-btn', 2, 'sm');
    await surfaces(page, '.dp-day', 42, 'sm');
  });

  check('keyboard calendar focus uses a visible 2px outline without a shadow', async t => {
    const page = await open(t, theme, rootFont);
    await page.locator('#date').focus();
    await page.keyboard.press('ArrowDown');
    const selected = page.locator('.dp-cell.is-selected');
    assert.equal(await selected.evaluate(cell => document.activeElement === cell), true);
    for (const date of ['2030-01-15', '2030-01-16']) {
      const day = page.locator(`.dp-cell[data-date="${date}"]:focus .dp-day`);
      await day.waitFor({ state: 'visible' });
      const outline = await day.evaluate(element => {
        const css = getComputedStyle(element);
        return { width: css.outlineWidth, style: css.outlineStyle, offset: css.outlineOffset,
          color: css.outlineColor, opacity: css.opacity, shadow: css.boxShadow };
      });
      assert.equal(outline.width, '2px');
      assert.equal(outline.style, 'solid');
      assert.equal(outline.offset, '1px');
      assert.notEqual(outline.color, 'rgba(0, 0, 0, 0)');
      assert.notEqual(outline.color, 'transparent');
      assert.equal(outline.opacity, '1');
      assert.equal(outline.shadow, 'none');
      await page.keyboard.press('ArrowRight');
    }
  });

  check('datetime popup, days, controls and time wheel surfaces use flat shared-token geometry', async t => {
    const page = await open(t, theme, rootFont);
    await page.locator('#datetime').focus();
    await page.keyboard.press('ArrowDown');
    await page.locator('.dtp-popup').waitFor({ state: 'visible' });
    await surfaces(page, '.dtp-popup', 1);
    await surfaces(page, '.dtp-cell.is-selected .dtp-day', 1, 'sm');
    await surfaces(page, '.dtp-day', 35, 'sm');
    await surfaces(page, '.dtp-nav', 2, 'sm');
    await surfaces(page, '.dtp-head .dtp-select', 2, 'sm');
    await surfaces(page, '.dtp-foot-btn', 3, 'sm');
    // The shared wheel box is the rounded surface; scroll columns are unstyled containers.
    await surfaces(page, '.dtp-wheels-box', 1, 'md');
    await surfaces(page, '.dtp-time-readout', 1, 'sm');
    await surfaces(page, '.dtp-hours-wheel .dtp-wheel-item', 12, 'sm');
    await surfaces(page, '.dtp-minutes-wheel .dtp-wheel-item', 60, 'sm');
    await surfaces(page, '.dtp-ampm-wheel .dtp-wheel-item', 2, 'sm');
    await surfaces(page, '.dtp-wheel-item.is-selected', 3, 'sm');
    assert.equal(await page.locator('.dtp-time-readout').textContent(), '08:30 AM');
  });
}
}
