'use strict';

// Real PHP views, served only from CLI-rendered synthetic fixtures. No application server or DB.
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
const previews = path.join(root, 'writable/bmg-layout-preview');
const cache = new Map();
let server, browser, origin;
const pages = {
  overview: { url: '/facilities', kind: 'index', heading: 'Mechanized drum rotation' },
  detail: { url: '/facilities/unit/11', kind: 'unit_detail', heading: 'North drum' },
  usb: { url: '/facilities/unit/11/usb', kind: 'usb', heading: 'USB commissioning · North drum' },
  token: { url: '/token', kind: 'device_token', heading: 'Device credential issued' }
};

function render(kind, url) {
  const key = kind + url;
  if (!cache.has(key)) {
    const fixture = kind === 'usb' ? 'render-bmg-usb-fixture.php' : 'render-bmg-fixture.php';
    const result = spawnSync('php', ['tests/_support/' + fixture], {
      cwd: root, input: JSON.stringify({ origin, path: url, kind }), encoding: 'utf8', timeout: 20000
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
    assert.match(result.stdout, /<!DOCTYPE html>/);
    cache.set(key, result.stdout);
  }
  return cache.get(key);
}

before(async () => {
  fs.mkdirSync(previews, { recursive: true });
  server = http.createServer((req, res) => {
    try {
      if (req.method !== 'GET') { res.writeHead(405); res.end('Layout fixtures are read-only'); return; }
      const url = new URL(req.url, origin);
      if (url.pathname.startsWith('/assets/')) {
        const file = path.resolve(root, 'public', '.' + url.pathname);
        if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
          res.writeHead(404); res.end(); return;
        }
        const types = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
        res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
        res.end(fs.readFileSync(file)); return;
      }
      const config = Object.values(pages).find(item => item.url === url.pathname);
      if (!config) { res.writeHead(404); res.end('Not a fixture URL'); return; }
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(render(config.kind, req.url));
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
after(async () => {
  await browser?.close();
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

async function pageFor(t, name, { theme = 'light', mobile = false, variant = '' } = {}) {
  const context = await browser.newContext({
    viewport: { width: mobile ? 390 : 1440, height: mobile ? 844 : 1000 },
    reducedMotion: 'reduce', colorScheme: theme, serviceWorkers: 'block'
  });
  const errors = [], unexpected = [], requests = [];
  await context.addInitScript(theme => {
    localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
    window.layoutSerialCalls = [];
    const serial = new EventTarget();
    serial.requestPort = async () => { window.layoutSerialCalls.push('requestPort'); throw new Error('No hardware in layout tests'); };
    serial.getPorts = async () => { window.layoutSerialCalls.push('getPorts'); return []; };
    Object.defineProperty(navigator, 'serial', { configurable: true, value: serial });
  }, theme);
  await context.route('**/*', route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== origin) return route.fulfill({ status: 200, body: '' });
    requests.push({ url: request.url(), type: request.resourceType(), method: request.method() });
    if (request.method() !== 'GET' || (!url.pathname.startsWith('/assets/') && !Object.values(pages).some(item => item.url === url.pathname))) {
      unexpected.push(request.method() + ' ' + url.pathname);
      return route.fulfill({ status: 404, body: 'No application endpoints in layout tests' });
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => {
    const serialCalls = await page.evaluate(() => window.layoutSerialCalls);
    await context.close();
    assert.deepEqual(serialCalls, [], 'USB APIs must not run automatically');
    assert.deepEqual(unexpected, [], 'No API, write, hardware, or unrecognised page requests');
    assert.deepEqual(errors, [], 'No browser runtime errors');
  });
  const response = await page.goto(origin + pages[name].url + (variant ? '?variant=' + variant : ''), { waitUntil: 'load' });
  assert.equal(response.status(), 200, await response.text());
  await page.waitForFunction(() => window.bootstrap && (document.querySelector('[data-bmg-usb]') || window.SynapseBmg));
  await page.evaluate(() => document.fonts.ready);
  assert.equal(await page.locator('body').evaluate(el => el.classList.contains('dark-mode')), theme === 'dark', 'Actual theme class');
  return { page, requests };
}

async function stylesheet(page, filename) {
  const loaded = await page.evaluate(filename => [...document.styleSheets].some(sheet => {
    if (!sheet.href || new URL(sheet.href).pathname !== '/assets/css/' + filename) return false;
    return sheet.cssRules.length > 0;
  }), filename);
  assert.ok(loaded, filename + ' must actually load and parse');
}

async function noOverflow(page) {
  const metrics = await page.evaluate(() => {
    const main = document.querySelector('main');
    return { document: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
      main: [main.scrollWidth, main.clientWidth], mainRight: main.getBoundingClientRect().right, viewport: innerWidth };
  });
  assert.ok(metrics.document[0] <= metrics.document[1] + 1, 'Document overflow: ' + JSON.stringify(metrics));
  assert.ok(metrics.main[0] <= metrics.main[1] + 1, 'Main overflow (table wrappers may scroll internally): ' + JSON.stringify(metrics));
  assert.ok(metrics.mainRight <= metrics.viewport + 1, 'Main extends beyond viewport: ' + JSON.stringify(metrics));
}

async function labelledControls(page) {
  const missing = await page.locator('main input:not([type="hidden"]):not([type="submit"]), main select, main textarea, main progress').evaluateAll(controls => controls.filter(el => {
    const refs = (el.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean);
    return ![...(el.labels || [])].some(label => label.textContent.trim()) &&
      !el.getAttribute('aria-label')?.trim() && !refs.some(id => document.getElementById(id)?.textContent.trim());
  }).map(el => el.outerHTML));
  assert.deepEqual(missing, [], 'All form controls, including modal fields, need associated labels');
}

async function captionContrast(page) {
  // Composite each computed rgba layer over its ancestors, not a guessed CSS token.
  const results = await page.locator('.bmg-workspace .bmg-caption, .bmg-workspace .text-muted, .bmg-workspace .form-text, .bmg-workspace .bmg-stat-label, .bmg-workspace .bmg-eyebrow').evaluateAll(elements => {
    const rgba = value => {
      const values = value.match(/[\d.]+/g).map(Number);
      return [values[0] / 255, values[1] / 255, values[2] / 255, values[3] ?? 1];
    };
    const over = (fg, bg) => [0, 1, 2].map(i => fg[i] * fg[3] + bg[i] * (1 - fg[3])).concat(1);
    const luminance = rgb => rgb.slice(0, 3).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4)
      .reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
    return elements.filter(el => el.getClientRects().length && el.textContent.trim()).map(el => {
      const layers = []; for (let node = el; node; node = node.parentElement) layers.unshift(node);
      let bg = [1, 1, 1, 1];
      for (const node of layers) bg = over(rgba(getComputedStyle(node).backgroundColor), bg);
      const fg = over(rgba(getComputedStyle(el).color), bg);
      const a = luminance(fg), b = luminance(bg);
      return { text: el.textContent.trim().slice(0, 90), color: getComputedStyle(el).color,
        background: bg, ratio: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
    });
  });
  assert.ok(results.length > 0, 'Must measure actual captions');
  assert.deepEqual(results.filter(result => result.ratio < 4.5), [], 'Dark caption contrast must be >= 4.5:1');
}

const screenshots = {
  'overview-light-desktop': 'overview-light.png', 'detail-light-desktop': 'detail-light.png',
  'detail-dark-desktop': 'detail-dark.png', 'usb-light-desktop': 'usb-light.png',
  'usb-dark-mobile': 'usb-mobile-dark.png'
};
for (const name of Object.keys(pages)) for (const theme of ['light', 'dark']) for (const mobile of [false, true]) {
  const key = `${name}-${theme}-${mobile ? 'mobile' : 'desktop'}`;
  test('layout: ' + key, { timeout: 60000 }, async t => {
    const { page } = await pageFor(t, name, { theme, mobile });
    // Never capture the credential page, even though its token is synthetic.
    if (screenshots[key]) {
      await page.screenshot({ path: path.join(previews, screenshots[key]), fullPage: true, animations: 'disabled', timeout: 20000 });
      t.diagnostic('Synthetic screenshot: writable/bmg-layout-preview/' + screenshots[key]);
    }
    await stylesheet(page, 'bmg.css');
    if (name === 'usb') await stylesheet(page, 'bmg-usb.css');
    assert.ok(await page.getByRole('heading', { name: pages[name].heading, exact: true }).isVisible());
    const panels = page.locator('.bmg-workspace .bmg-panel');
    assert.ok(await panels.count() > 0);
    for (const panel of await panels.all()) assert.ok(await panel.isVisible(), 'Panel must be rendered');
    for (const heading of await page.locator('.bmg-workspace h2, .bmg-workspace h3').all()) assert.ok(await heading.isVisible(), 'Section headings must be rendered');
    await noOverflow(page); await labelledControls(page);
    if (theme === 'dark') await captionContrast(page);
    if (name === 'overview' || name === 'detail') {
      assert.equal(await page.locator('.bmg-stat').count(), 4);
      assert.equal(await page.locator('.bmg-stat-grid').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), mobile ? 2 : 4);
    }
    if (name === 'detail') {
      assert.equal(await page.locator('.bmg-detail-grid').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), mobile ? 1 : 2);
      assert.equal(await page.locator('#bmg-history table').count(), 3, 'All three histories remain');
    }
    if (name === 'token') assert.equal(await page.getByLabel('Device token', { exact: true }).inputValue(), 'fixture-secret-not-a-real-token');
    if (name === 'usb') {
      const rail = page.getByRole('list', { name: 'Commissioning workflow' });
      assert.ok(await rail.isVisible());
      assert.deepEqual(await rail.locator('strong').allTextContents(), ['Connect', 'Identify', 'Install']);
      assert.equal(await rail.locator('button, a, [role="tab"]').count(), 0, 'Step rail is static, not interactive');
      assert.ok(await page.locator('.bmg-usb-safety').isVisible());
      assert.match(await page.locator('.bmg-usb-safety').innerText(), /not emergency stops/);
      assert.ok(await page.getByRole('heading', { name: 'Receive-only serial monitor', exact: true }).isVisible());
      assert.ok(await page.locator('.bmg-usb-monitor [data-usb-clear]').isEnabled());
      assert.equal(await page.locator('.bmg-usb-monitor [data-usb-flash]').count(), 0, 'Monitor is separate from installer');
      await page.locator('[data-usb-log]').evaluate(el => { el.textContent = 'Synthetic diagnostic line'; });
      await page.getByRole('button', { name: 'Clear log', exact: true }).click();
      assert.equal(await page.locator('[data-usb-log]').textContent(), '');
    }
  });
}

test('native detail section links resolve IDs and change hash without fetching a page', { timeout: 60000 }, async t => {
  const { page, requests } = await pageFor(t, 'detail');
  const links = await page.locator('.bmg-section-nav a').all();
  assert.equal(links.length, 5);
  await page.evaluate(() => { window.layoutDocumentMarker = 'same-document'; });
  for (const link of links) {
    const hash = await link.getAttribute('href');
    assert.match(hash, /^#bmg-/);
    assert.equal(await page.locator(hash).count(), 1);
    const before = requests.length;
    await link.click();
    await page.waitForFunction(hash => location.hash === hash, hash);
    assert.equal(await page.evaluate(() => window.layoutDocumentMarker), 'same-document');
    assert.deepEqual(requests.slice(before).filter(req => ['document', 'fetch', 'xhr'].includes(req.type)), [], 'Anchor must not fetch full app navigation');
  }
});

test('permission variants keep registration clickable while commands fail closed', { timeout: 120000 }, async t => {
  for (const variant of ['gate-off', 'controller-gate-off', 'detail-gate-off', 'no-hardware', 'reader', 'operator', 'config-only']) {
    const { page } = await pageFor(t, 'detail', { variant });
    const configurable = !['reader', 'operator', 'config-only'].includes(variant);
    for (const name of ['Request run now', 'Request web stop']) assert.ok(await page.getByRole('button', { name, exact: true }).isDisabled(), variant + ': ' + name);
    assert.equal(await page.locator('[data-bs-target="#deviceModal"]').count(), configurable ? 1 : 0, variant);
    if (configurable) {
      assert.ok(await page.locator('#scheduleEnabled').isDisabled());
      await page.locator('[data-bs-target="#deviceModal"]').click();
      await page.locator('#deviceModal.show').waitFor();
      assert.ok(await page.locator('#deviceModal button[type="submit"]').isEnabled());
      await page.locator('#deviceModal button[type="submit"]').click();
      await page.locator('#synapseConfirmModal.show').waitFor();
      assert.ok(await page.locator('[data-confirm-accept]').isEnabled(), 'Registration reaches shared confirmation without enabling hardware');
    }
  }
});

test('detail retains button, form, CSRF and version hooks including modal labels', { timeout: 60000 }, async t => {
  const { page } = await pageFor(t, 'detail');
  for (const selector of ['[data-bmg-schedule]', '[data-bmg-add-time]', '[data-bmg-remove-time]', '[data-bmg-time-count]', '[data-bmg-schedule-error]', '[data-bmg-telemetry]', '#unitModal', '#deviceModal', '#completeBatchModal', '#cancelBatchModal']) {
    assert.ok(await page.locator(selector).count() > 0, selector);
  }
  for (const name of ['Save schedule', 'Request run now', 'Request web stop', 'Revoke device', 'Complete batch', 'Cancel batch', 'Edit drum']) {
    assert.ok(await page.getByRole('button', { name, exact: true }).isEnabled(), name);
  }
  const forms = await page.locator('main form[method="POST"]').evaluateAll(forms => forms.map(form => ({
    action: new URL(form.action).pathname, csrf: [...form.querySelectorAll('input[name="csrf_test_name"]')].map(el => [el.type, !!el.value]),
    version: [...form.querySelectorAll('[name="expected_version"]')].map(el => [el.type, el.value]),
    confirm: form.hasAttribute('data-confirm'), ajax: form.hasAttribute('data-bmg-submit')
  })));
  assert.equal(forms.length, 8);
  for (const form of forms) assert.deepEqual(form.csrf, [['hidden', true]], form.action);
  assert.equal(forms.filter(form => form.version.length).length, 6);
  for (const form of forms.filter(form => form.version.length)) assert.deepEqual(form.version, [['hidden', '3']], form.action);
  const registration = forms.find(form => form.action.endsWith('/device'));
  assert.ok(registration?.confirm); assert.equal(registration.ajax, false, 'Token issuance remains direct POST');
  await labelledControls(page);
  const { page: idle } = await pageFor(t, 'detail', { variant: 'idle' });
  await idle.getByRole('button', { name: 'Start batch', exact: true }).click();
  await idle.locator('#startBatchModal.show').waitFor();
  await labelledControls(idle);
});

test('malicious fixture fields remain literal text, never DOM or executable script', { timeout: 60000 }, async t => {
  const literal = '<img src=x onerror="window.bmgXss=1"><script>window.bmgXss=1</script>&"';
  for (const name of ['overview', 'detail', 'token']) {
    const { page } = await pageFor(t, name, { variant: 'malicious', mobile: true, theme: 'dark' });
    assert.equal(await page.evaluate(() => window.bmgXss), undefined);
    assert.equal(await page.locator('main img[src="x"], main [onerror]').count(), 0);
    assert.equal(await page.locator('main script').evaluateAll(scripts => scripts.filter(el => el.textContent.includes('window.bmgXss')).length), 0);
    if (name === 'token') assert.equal(await page.getByLabel('Device token', { exact: true }).inputValue(), literal);
    else {
      assert.ok((await page.locator('.bmg-workspace').textContent()).includes(literal));
      if (name === 'detail') {
        assert.ok((await page.locator('[data-bmg-telemetry]').textContent()).includes(literal));
        assert.ok((await page.locator('#bmg-history').textContent()).includes(literal));
        assert.ok((await page.locator('#bmg-batch').textContent()).includes(literal));
      }
    }
    await noOverflow(page);
  }
});
