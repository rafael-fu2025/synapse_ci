'use strict';

// node --test --test-concurrency=1 tests/browser/report-exports.test.cjs
// Isolated PHP view only: no CI bootstrap, database, authentication or app server.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { once } = require('node:events');

const root = path.resolve(__dirname, '../..');
const tools = path.join(root, 'writable/widget-test-tools/node_modules/playwright');
let playwright;
try { playwright = require(tools); }
catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  playwright = require('playwright');
}
const publicRoot = path.join(root, 'public');
const screenshots = path.join(root, 'writable/report-design-preview');
const units = ['clinic', 'guidance', 'bmg'];
const labels = { clinic: 'Clinic', guidance: 'Guidance', bmg: 'BMG' };
const fixtures = new Map();
let server, browser, origin;
const posts = [];

function executablePath() {
  const expected = playwright.chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const cached = fs.existsSync(cache) ? fs.readdirSync(cache)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(name => path.join(cache, name, 'chrome-win64/chrome.exe'))
    .find(file => fs.existsSync(file)) : undefined;
  assert.ok(cached, `No local Chromium found at ${expected} or ${cache}; install it before running this offline test`);
  return cached;
}

function renderView(allowedUnits) {
  // Include the real view in object scope so its layout calls use this stub.
  const php = String.raw`
function esc($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function site_url($path) { return '/' . ltrim($path, '/'); }
function csrf_field() { return '<input type="hidden" name="csrf_test" value="synthetic-csrf">'; }
class ExportLayoutStub {
    public function extend($layout) {
        if ($layout !== 'layouts/app') { throw new RuntimeException('Unexpected layout'); }
        return '';
    }
    public function section($section) {
        if ($section !== 'content') { throw new RuntimeException('Unexpected section'); }
        return '';
    }
    public function endSection() { return ''; }
    public function render($file, $units) {
        $from = '2030-01-01'; $to = '2030-01-31';
        $fromDisplay = '01/01/2030'; $toDisplay = '31/01/2030';
        ob_start(); require $file; return ob_get_clean();
    }
}
echo (new ExportLayoutStub())->render($argv[1], json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR));
`;
  const content = execFileSync('php', [
    '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', php,
    path.join(root, 'app/Views/reports/export.php'), JSON.stringify(allowedUnits)
  ], { cwd: root, encoding: 'utf8', timeout: 10_000, maxBuffer: 1024 * 1024 });
  assert.match(content, /data-operational-export/);
  return `<!doctype html><html lang="en" data-theme="light"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>.main-content{padding:1.5rem 1.75rem;width:100%}@media(max-width:768px){.main-content{padding:1rem .75rem}}</style>
    </head><body><main id="main" class="main"><div class="main-content">${content}</div></main>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/custom-dropdown.js"></script>
    <script src="/assets/js/datepicker.js"></script>
    <script>
      window.exportSubmissions = [];
      document.querySelector('[data-operational-export]').addEventListener('submit', event => {
        event.preventDefault();
        window.exportSubmissions.push({
          method: event.target.method,
          action: new URL(event.target.action).pathname,
          submitter: { name: event.submitter.name, value: event.submitter.value },
          fields: Array.from(new FormData(event.target, event.submitter).entries())
        });
      });
    </script></body></html>`;
}

before(async () => {
  fs.accessSync(screenshots, fs.constants.W_OK);
  fixtures.set('all', renderView(units));
  for (const unit of units) fixtures.set(unit, renderView([unit]));
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method !== 'GET') {
      posts.push({ method: req.method, path: url.pathname });
      res.writeHead(405).end();
      return;
    }
    if (url.pathname === '/fixture') {
      const html = fixtures.get(url.searchParams.get('role'));
      if (!html) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(html);
      return;
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }).end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  const executable = executablePath();
  console.log(`Report export tests Chromium: ${executable}`);
  browser = await playwright.chromium.launch({ headless: true, executablePath: executable, timeout: 20_000 });
}, { timeout: 65_000 });

after(async () => {
  try { if (browser) await browser.close(); }
  finally {
    if (server?.listening) await new Promise((resolve, reject) => {
      server.close(error => error ? reject(error) : resolve());
      server.closeAllConnections();
    });
  }
  assert.deepEqual(posts, [], 'Every POST must be prevented in the browser');
});

async function open(t, role, viewport) {
  const context = await browser.newContext({ viewport, reducedMotion: 'reduce', serviceWorkers: 'block' });
  t.after(() => context.close());
  const errors = [];
  await context.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(origin + '/')) return route.continue();
    errors.push(`External request: ${url}`);
    return route.abort();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(4_000);
  page.setDefaultNavigationTimeout(8_000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.status() >= 400) errors.push(`HTTP ${response.status()}: ${response.url()}`);
  });
  page.on('requestfailed', request => errors.push(`Failed request: ${request.url()}`));
  t.after(() => assert.deepEqual(errors, [], 'Local assets and scripts must load without errors'));
  await page.goto(`${origin}/fixture?role=${role}`);
  await page.waitForFunction(() => document.querySelector('#export-unit').dataset.bdSelect &&
    document.querySelector('#export-from-display').closest('.dp-wrap') &&
    document.querySelector('#export-to-display').closest('.dp-wrap'));
  await page.evaluate(() => document.fonts.ready);
  return page;
}

async function noOverflow(page) {
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth
  }));
  assert.ok(dimensions.document <= dimensions.viewport && dimensions.body <= dimensions.viewport,
    `Horizontal overflow: ${JSON.stringify(dimensions)}`);
}

async function selectUnit(page, label) {
  const wrapper = page.locator('#export-unit').locator('..');
  const toggle = wrapper.locator('.bd-select-toggle');
  await toggle.click();
  await wrapper.locator('.bd-select-menu').getByRole('option', { name: label, exact: true }).click();
  if (await toggle.getAttribute('aria-expanded') === 'true') {
    await toggle.focus();
    await page.keyboard.press('Escape');
  }
}

async function pickDate(page, field, iso, display) {
  const input = page.locator(`#export-${field}-display`);
  assert.equal(await page.locator(`#export-${field}`).getAttribute('type'), 'hidden');
  await input.focus();
  await page.keyboard.press('ArrowDown');
  await page.locator('.dp-popup:visible').waitFor();
  await noOverflow(page);
  await page.locator(`.dp-popup:visible td[data-date="${iso}"]`).click();
  assert.equal(await input.inputValue(), display);
  assert.equal(await page.locator(`#export-${field}`).inputValue(), iso);
}

async function submitBoth(page, unit, from, to) {
  const form = page.locator('[data-operational-export]');
  assert.equal(await form.getAttribute('method'), 'post');
  assert.equal(await form.locator('button[type="submit"]').count(), 2);
  assert.equal(await form.locator('select[name="format"]').count(), 0);
  for (const [format, name] of [['pdf', 'Download PDF report'], ['csv', 'Download CSV data']]) {
    const button = form.getByRole('button', { name, exact: true });
    assert.equal(await button.isVisible(), true);
    assert.equal(await button.isEnabled(), true);
    assert.equal(await button.getAttribute('name'), 'format');
    assert.equal(await button.getAttribute('value'), format);
    const count = await page.evaluate(() => window.exportSubmissions.length);
    await button.click();
    const submissions = await page.evaluate(() => window.exportSubmissions);
    assert.equal(submissions.length, count + 1, 'A valid button click must fire one prevented submit');
    const submission = submissions.at(-1);
    assert.deepEqual(submission, {
      method: 'post', action: '/reports/export/operational',
      submitter: { name: 'format', value: format },
      fields: [['csrf_test', 'synthetic-csrf'], ['unit', unit], ['from', from], ['to', to], ['format', format]]
    });
  }
}

// Explicitly serial even if the caller enables test concurrency.
test('PHP-rendered report exports', { concurrency: false, timeout: 100_000 }, async t => {
  for (const [name, viewport] of [
    ['desktop', { width: 1440, height: 1000 }],
    ['mobile', { width: 390, height: 844 }]
  ]) {
    await t.test(`${name}: layout, real widgets and both submitter formats`, { timeout: 25_000 }, async t => {
      const page = await open(t, 'all', viewport);
      assert.deepEqual(await page.locator('#export-unit option').evaluateAll(options => options.map(o => o.value)), ['all', ...units]);
      assert.equal(await page.locator('#export-unit').inputValue(), 'all');
      assert.equal(await page.locator('#export-from-display').inputValue(), '01/01/2030');
      assert.equal(await page.locator('#export-to-display').inputValue(), '31/01/2030');
      await noOverflow(page);
      await selectUnit(page, 'Guidance');
      assert.equal(await page.locator('#export-unit').inputValue(), 'guidance');
      await pickDate(page, 'from', '2030-01-08', '08/01/2030');
      await pickDate(page, 'to', '2030-01-22', '22/01/2030');
      await submitBoth(page, 'guidance', '2030-01-08', '2030-01-22');
      await noOverflow(page);
      await page.evaluate(() => window.scrollTo(0, 0));
      const file = path.join(screenshots, `report-exports-${name}.png`);
      await page.screenshot({ path: file, fullPage: true, animations: 'disabled' });
      console.log(`Screenshot: ${file}`);
    });
  }
  for (const unit of units) {
    await t.test(`${unit} role fixture: only its department is offered`, { timeout: 15_000 }, async t => {
      const page = await open(t, unit, { width: 390, height: 844 });
      assert.deepEqual(await page.locator('#export-unit option').evaluateAll(options => options.map(o => ({ value: o.value, label: o.textContent.trim() }))),
        [{ value: unit, label: labels[unit] }]);
      assert.equal(await page.locator('#export-unit').inputValue(), unit);
      assert.equal(await page.getByText('All units, one download.', { exact: true }).count(), 0);
      for (const other of units) {
        assert.equal(await page.locator(`#export-${other}-title`).count(), other === unit ? 1 : 0);
      }
      await noOverflow(page);
      await submitBoth(page, unit, '2030-01-01', '2030-01-31');
    });
  }
});
