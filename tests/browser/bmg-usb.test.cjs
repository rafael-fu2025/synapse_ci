'use strict';
// Real PHP view/application scripts; serial hardware and the local ESM import are mocked.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const cache = new Map();
const bytes = Buffer.from([1, 2, 3, 4]);
const hash = createHash('sha256').update(bytes).digest('hex');
function approved(routePrefix = '') {
  return { id: 'approved-v1', name: 'Approved release', chip_family: 'ESP32-S3', flash_size: '16MB',
    parts: [{ name: 'Application', address: 0, size: 4, sha256: hash,
      url: origin + routePrefix + '/facilities/unit/11/usb/releases/approved-v1/parts/0' }] };
}
const stub = `
export class Transport {
  constructor(port, trace) { if (trace !== false) throw Error('trace'); this.port = port; }
  async disconnect() { window.calls.push('disconnect'); await this.port.close(); }
}
export class ESPLoader {
  constructor(options) {
    if (options.baudrate !== 115200 || options.romBaudrate !== 115200) throw Error('baud');
    this.transport = options.transport; this.terminal = options.terminal;
    this.ESP_RAM_BLOCK = 0x1800;
    this.chip = {
      CHIP_NAME: window.settings.family || 'ESP32-S3',
      readMac: async () => window.settings.mac || 'aa:bb:cc:dd:ee:ff',
      postConnect: async loader => {
        window.calls.push('postConnect');
        if (loader !== this || !window.calls.includes('authorized')) throw Error('postConnect ran before authorization');
        if (!document.querySelector('[data-usb-isolated]').checked) throw Error('postConnect ran without isolation');
        if (window.settings.postConnectFailure) throw Error('mock postConnect failure');
        loader.ESP_RAM_BLOCK = 0x800;
        if (window.settings.postConnectRevokeIsolation) document.querySelector('[data-usb-isolated]').checked = false;
      }
    };
    this.secureDownloadMode = !!window.settings.secure;
  }
  async detectChip(mode, attempts) {
    window.calls.push(['detect', mode, attempts]); await this.transport.port.open({ baudRate: 115200 });
    this.terminal.writeLine('<img src=x onerror=alert(1)>');
  }
  async readFlashId() { return window.settings.flashId ?? 0x1840ef; }
  async runStub() {
    if (this.ESP_RAM_BLOCK !== 0x800 || window.calls.at(-1) !== 'postConnect') throw Error('native USB OTG RAM block not prepared before stub');
    window.calls.push('stub'); this.IS_STUB = true;
  }
  async writeFlash(options) {
    if (!(options.fileArray[0].data instanceof Uint8Array) || options.eraseAll !== false || !options.compress
      || options.flashSize !== 'keep' || options.flashMode !== 'keep' || options.flashFreq !== 'keep') throw Error('unsafe options');
    window.calls.push('write'); this.data = options.fileArray[0].data.slice();
    options.reportProgress(0, this.data.length, this.data.length);
    if (window.settings.hold) await new Promise(resolve => window.finishWrite = resolve);
  }
  async readFlash(address, size) {
    window.calls.push(['readback', address, size]);
    return window.settings.badReadback ? new Uint8Array(size) : this.data;
  }
}
`;
before(async () => {
  server = http.createServer((req, res) => {
    try {
      const url = new URL(req.url, origin);
      const assetPath = url.pathname.replace(/^\/synapse-test(?=\/)/, '');
      if (assetPath.startsWith('/assets/')) {
        const file = path.resolve(root, 'public', '.' + assetPath);
        if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
        res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream');
        res.end(fs.readFileSync(file)); return;
      }
      if (!cache.has(req.url)) {
        const result = spawnSync('php', ['tests/_support/render-bmg-usb-fixture.php'], {
          cwd: root, input: JSON.stringify({ origin, path: req.url,
            ...(url.pathname.startsWith('/synapse-test/') ? { basePath: '/synapse-test', indexPage: 'index.php' } : {}) }), encoding: 'utf8', timeout: 10000
        });
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.match(result.stdout, /<!DOCTYPE html>/);
        cache.set(req.url, result.stdout);
      }
      res.setHeader('Content-Type', 'text/html'); res.end(cache.get(req.url));
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
  browser = await chromium.launch({ executablePath, headless: true, timeout: 20000 });
});
after(async () => { await browser?.close(); server?.close(); });
async function pageFor(t, settings = {}, variant = '') {
  const context = await browser.newContext({ reducedMotion: 'reduce', ...(settings.viewport ? { viewport: settings.viewport } : {}) });
  const routePrefix = settings.subfolder ? '/synapse-test/index.php' : '';
  const assetPrefix = settings.subfolder ? '/synapse-test' : '';
  await context.addInitScript(settings => {
    window.settings = settings; window.calls = [];
    localStorage.setItem('na-dark-mode', settings.theme === 'dark' ? '1' : '0');
    if (settings.insecure) Object.defineProperty(window, 'isSecureContext', { value: false });
    let controller;
    const port = new EventTarget();
    port.getInfo = () => ({ usbVendorId: 0x10c4, usbProductId: 0xea60 });
    port.open = async options => {
      window.calls.push(['open', options.baudRate]);
      if (port.readable) throw Error('already open');
      port.readable = new ReadableStream({ start(c) { controller = c; }, cancel() { window.calls.push('cancel'); } });
    };
    port.close = async () => {
      if (window.settings.closeFailure) { window.settings.closeFailure = false; throw Error('mock close failure'); }
      if (port.readable?.locked) throw Error('reader still locked');
      window.calls.push('close'); port.readable = null;
    };
    port.writable = null;
    window.usbPort = port;
    window.receive = value => controller.enqueue(new TextEncoder().encode(value));
        window.endReceive = () => controller.close();
    const serial = new EventTarget();
    serial.requestPort = async () => { window.calls.push('request'); return port; };
    serial.getPorts = () => { throw Error('cached permissions forbidden'); };
    Object.defineProperty(navigator, 'serial', { configurable: true, value: settings.unsupported ? undefined : serial });
  }, settings);
  const network = [], posts = [], errors = [];
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url()); network.push({ url: url.href, method: request.method() });
    if (url.origin !== origin) return route.fulfill({ body: '' });
    if (!settings.realBundle && url.pathname === assetPrefix + '/assets/vendor/esptool-js/bundle.js') return route.fulfill({ contentType: 'text/javascript', body: stub });
    if (url.pathname.endsWith('/manifest')) {
      const value = approved(routePrefix);
      if (settings.manifest === 'url') value.parts[0].url = origin + '/arbitrary.bin';
      if (settings.manifest === 'external') value.parts[0].url = 'https://evil.invalid/file.bin';
      if (settings.manifest === 'unaligned') value.parts[0].address = 1;
      if (settings.manifest === 'overlap') value.parts.push({ ...value.parts[0], url: value.parts[0].url.replace('/0', '/1') });
      if (settings.manifest === 'count') value.parts = Array(33).fill(value.parts[0]);
      if (settings.manifest === 'size') value.parts[0].size = 16777217;
      if (settings.manifest === 'hash') value.parts[0].sha256 = '0'.repeat(64);
      return route.fulfill({ json: value });
    }
    if (/\/parts\/\d+$/.test(url.pathname)) {
      if (settings.redirect) return route.fulfill({ status: 302, headers: { location: '/arbitrary.bin' } });
      return route.fulfill({ contentType: 'application/octet-stream', body: bytes });
    }
    if (url.pathname.endsWith('/authorize-flash')) {
      posts.push(request.postData());
      await request.frame().evaluate(() => window.calls.push('authorized'));
      const value = approved(routePrefix); if (settings.changed) value.name = 'Changed';
      return route.fulfill({ status: settings.denied ? 409 : 200,
        json: { success: !settings.denied, manifest: value, csrf: { name: 'csrf_test_name', hash: 'rotated-token' } } });
    }
    if (settings.config && url.pathname.endsWith('/usb')) {
      const response = await route.fetch();
      let html = await response.text();
      for (const [key, raw] of Object.entries(settings.config)) {
        html = html.replace(new RegExp('\\sdata-' + key + '="[^"]*"', 'g'), '');
        if (raw !== null) {
          const value = raw.replaceAll('{origin}', origin).replaceAll('{authority}', new URL(origin).host).replaceAll('&', '&amp;').replaceAll('"', '&quot;');
          html = html.replace('data-bmg-usb', `data-bmg-usb data-${key}="${value}"`);
        }
      }
      return route.fulfill({ response, body: html });
    }
    return route.continue();
  });
  const page = await context.newPage(); page.setDefaultTimeout(6000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  const response = await page.goto(origin + routePrefix + '/facilities/unit/11/usb' + variant);
  assert.equal(response.status(), 200, await response.text());
  await page.waitForFunction(() => window.SynapseBmgUsb && window.SynapseConfirm);
  return { page, network, posts };
}
const control = (page, name) => page.locator(`[data-usb-${name}]`);

test('USB layout is responsive and theme-aware without starting hardware', async t => {
  const previews = path.join(root, 'writable/bmg-layout-previews');
  fs.mkdirSync(previews, { recursive: true });
  for (const theme of ['light', 'dark']) {
    for (const mobile of [false, true]) {
      const { page } = await pageFor(t, { theme, viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 } }, '?gate-off&no-firmware');
      assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      assert.equal(await page.locator('.bmg-usb-workflow li').count(), 3);
      assert.equal(await page.locator('[data-usb-release] option').textContent(), 'No approved firmware available');
      assert.equal(await control(page, 'select').isEnabled(), true);
      assert.equal(await control(page, 'identify').isDisabled(), true);
      assert.equal(await control(page, 'flash').isDisabled(), true);
      assert.deepEqual(await page.evaluate(() => window.calls), []);
      const contrast = await page.locator('.bmg-usb-step').first().evaluate(element => {
        const luminance = color => {
          let channels = color.match(/[\d.]+/g).slice(0, 3).map(Number);
          if (!color.startsWith('color(')) channels = channels.map(channel => channel / 255);
          channels = channels.map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
          return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        };
        const foreground = luminance(getComputedStyle(element).color);
        const background = luminance(getComputedStyle(element).backgroundColor);
        return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
      });
      assert.ok(contrast >= 4.5, `USB step contrast ${contrast} in ${theme}`);
      await page.screenshot({ path: path.join(previews, `usb-${theme}-${mobile ? 'mobile' : 'desktop'}.png`), fullPage: true });
    }
  }
});
async function selected(page) {
  await control(page, 'select').click();
  await page.waitForFunction(() => window.calls.includes('request'));
  await control(page, 'isolated').check();
}
async function confirm(page) {
  await page.locator('[data-confirm-accept]').click();
  await page.waitForFunction(() => !document.querySelector('#synapseConfirmModal.show'));
}
async function identified(page) {
  await selected(page); await control(page, 'identify').click(); await confirm(page);
  await page.waitForFunction(() => document.querySelector('[data-usb-identity]').textContent.includes('bootloader mode'));
  await page.waitForFunction(() => !document.querySelector('[data-usb-identify]').disabled);
}
async function flash(page) {
  await control(page, 'release').selectOption('approved-v1');
  await control(page, 'flash').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-identify]').disabled);
}
async function calls(page) { return page.evaluate(() => window.calls); }

test('subfolder and index.php use configured module, manifest, parts and authorization URLs', async t => {
  const { page, network, posts } = await pageFor(t, { subfolder: true });
  const config = await page.locator('[data-bmg-usb]').evaluate(root => ({ ...root.dataset }));
  assert.equal(config.esptoolUrl, origin + '/synapse-test/assets/vendor/esptool-js/bundle.js');
  assert.equal(config.releasesBase, origin + '/synapse-test/index.php/facilities/unit/11/usb/releases');
  await identified(page); await flash(page);
  assert.match(await control(page, 'progress-label').textContent(), /verified by SHA-256 readback/);
  for (const suffix of ['/approved-v1/manifest', '/approved-v1/parts/0']) {
    assert.ok(network.some(item => item.url === config.releasesBase + suffix));
  }
  assert.ok(network.some(item => item.url === config.authorizeUrl && item.method === 'POST'));
  assert.ok(network.some(item => item.url === config.esptoolUrl));
  assert.equal(posts.length, 1);
});
for (const [name, config] of [
  ['missing module', { 'esptool-url': null }],
  ['external module', { 'esptool-url': 'https://evil.invalid/assets/vendor/esptool-js/bundle.js' }],
  ['module traversal', { 'esptool-url': '{origin}/tmp/../assets/vendor/esptool-js/bundle.js' }],
  ['encoded traversal', { 'esptool-url': '{origin}/%2e%2e/assets/vendor/esptool-js/bundle.js' }],
  ['module query', { 'esptool-url': '{origin}/assets/vendor/esptool-js/bundle.js?evil' }],
  ['module fragment', { 'esptool-url': '{origin}/assets/vendor/esptool-js/bundle.js#evil' }],
  ['module credentials', { 'esptool-url': 'http://user:pass@{authority}/assets/vendor/esptool-js/bundle.js' }],
  ['wrong module suffix', { 'esptool-url': '{origin}/assets/vendor/esptool-js/other.js' }],
  ['external releases', { 'releases-base': 'https://evil.invalid/facilities/unit/11/usb/releases' }],
  ['wrong unit', { 'releases-base': '{origin}/facilities/unit/12/usb/releases' }],
  ['releases query', { 'releases-base': '{origin}/facilities/unit/11/usb/releases?' }],
  ['releases trailing slash', { 'releases-base': '{origin}/facilities/unit/11/usb/releases/' }],
  ['authorize prefix', { 'authorize-url': '{origin}/elsewhere/facilities/unit/11/usb/authorize-flash' }],
  ['authorize query', { 'authorize-url': '{origin}/facilities/unit/11/usb/authorize-flash?evil' }],
]) test('malformed root configuration fails closed: ' + name, async t => {
  const { page, network } = await pageFor(t, { config });
  assert.match(await control(page, 'status').textContent(), /URL configuration is invalid/);
  assert.equal(await control(page, 'select').isDisabled(), true);
  assert.deepEqual(await calls(page), []);
  assert.equal(network.some(item => /esptool-js\/bundle|\/manifest$|\/parts\/|authorize-flash/.test(item.url)), false);
});
test('real local esptool ESM exports import without serial access or a stub response', async t => {
  const { page, network } = await pageFor(t, { realBundle: true, subfolder: true });
  const exports = await page.evaluate(async () => {
    const url = document.querySelector('[data-bmg-usb]').dataset.esptoolUrl;
    const module = await import(url);
    return { loader: typeof module.ESPLoader, transport: typeof module.Transport,
      noRebootFinish: /flashDeflFinish\((?:false|!1),/.test(module.ESPLoader.prototype.writeFlash.toString()),
      finishCode: module.ESPLoader.prototype.flashDeflFinish.toString() };
  });
  assert.equal(exports.loader, 'function'); assert.equal(exports.transport, 'function');
  assert.equal(exports.noRebootFinish, true);
  assert.match(exports.finishCode, /\?0:1/); // false reboot argument sends the stay-in-loader value.
  assert.deepEqual(await calls(page), []);
  assert.ok(network.some(item => item.url === origin + '/synapse-test/assets/vendor/esptool-js/bundle.js'));
});
test('real bundled ESP32-S3 postConnect caps native USB OTG RAM uploads without hardware', async t => {
  const { page } = await pageFor(t, { realBundle: true });
  const result = await page.evaluate(async () => {
    const module = await import(document.querySelector('[data-bmg-usb]').dataset.esptoolUrl);
    // Pinned 0.7.0's internal lookup gives the actual bundled target, not a copied hook.
    const chip = module.ESPLoader.prototype.romFromChipId.call({}, 9);
    const probes = [];
    const native = { ESP_RAM_BLOCK: 0x1800, usesUsbOtg: async () => { probes.push('native'); return true; } };
    const uart = { ESP_RAM_BLOCK: 0x1800, usesUsbOtg: async () => { probes.push('uart'); return false; } };
    await chip.postConnect(native); await chip.postConnect(uart);
    return { family: chip.CHIP_NAME, native: native.ESP_RAM_BLOCK, uart: uart.ESP_RAM_BLOCK, probes };
  });
  assert.deepEqual(result, { family: 'ESP32-S3', native: 0x800, uart: 0x1800, probes: ['native', 'uart'] });
  assert.deepEqual(await calls(page), []);
});
test('unsupported Web Serial fails gracefully and never requests permission', async t => {
  const { page } = await pageFor(t, { unsupported: true });
  assert.match(await control(page, 'support').textContent(), /Chrome or Edge.*secure context/);
  assert.equal(await control(page, 'select').isDisabled(), true);
  assert.deepEqual(await calls(page), []);
});
test('insecure context never permits port selection', async t => {
  const { page } = await pageFor(t, { insecure: true });
  assert.equal(await control(page, 'select').isDisabled(), true);
  assert.deepEqual(await calls(page), []);
});
test('disconnect failures are visible and invalidate identity', async t => {
  const { page } = await pageFor(t, { closeFailure: true }); await selected(page);
  await control(page, 'identify').click(); await confirm(page);
  await page.waitForFunction(() => document.querySelector('[data-usb-log]').textContent.includes('USB disconnect failed'));
  assert.match(await control(page, 'identity').textContent(), /Not identified/);
  assert.equal(await control(page, 'flash').isDisabled(), true);
  assert.match(await control(page, 'status').textContent(), /mock close failure/);
});
test('selection grants access only; isolation and shared confirmation precede reset', async t => {
  const { page, network } = await pageFor(t);
  assert.deepEqual(await calls(page), []);
  await control(page, 'select').click();
  await page.waitForFunction(() => document.querySelector('[data-usb-status]').textContent.includes('serial adapter selected'));
  assert.deepEqual(await calls(page), ['request']);
  assert.equal(await control(page, 'identify').isDisabled(), true);
  await control(page, 'isolated').check(); await control(page, 'identify').click();
  assert.deepEqual(await calls(page), ['request']);
  await page.locator('[data-confirm-cancel]').click();
  await page.waitForFunction(() => !document.querySelector('[data-usb-identify]').disabled);
  assert.deepEqual(await calls(page), ['request']);
  await control(page, 'identify').click(); await confirm(page);
  await page.waitForFunction(() => window.calls.includes('disconnect'));
  const list = await calls(page);
  assert.deepEqual(list, ['request', ['detect', 'default_reset', 3], ['open', 115200], 'disconnect', 'close']);
  assert.match(await control(page, 'identity').textContent(), /ESP32-S3.*16MB.*bootloader/);
  assert.equal(await control(page, 'log').locator('img').count(), 0);
  assert.equal(network.some(n => /authorize|parts\/|manifest/.test(n.url)), false);
});
test('receive-only monitor is bounded text, stops/release locks, switching clears identity, logs stay local', async t => {
  const { page, network } = await pageFor(t); await identified(page);
  await control(page, 'monitor').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-stop-monitor]').disabled);
  assert.match(await control(page, 'identity').textContent(), /Not identified/);
  assert.equal(await control(page, 'identify').isDisabled(), true);
  await page.evaluate(() => window.receive('z'.repeat(70000) + '<img src=x>'));
  await page.waitForFunction(() => document.querySelector('[data-usb-log]').textContent.length === 65536);
  assert.equal(await control(page, 'log').locator('img').count(), 0);
  await control(page, 'stop-monitor').click();
  await page.waitForFunction(() => !document.querySelector('[data-usb-monitor]').disabled);
  assert.ok((await calls(page)).includes('cancel'));
  assert.equal(await page.evaluate(() => window.usbPort.readable), null);
  assert.equal(network.some(n => n.method === 'POST'), false);
  await control(page, 'clear').click(); assert.equal(await control(page, 'log').textContent(), '');
  await control(page, 'disconnect').click();
  await page.waitForFunction(() => document.querySelector('[data-usb-identify]').disabled);
});
test('monitor EOF and close failure settle without an unhandled rejection', async t => {
  const { page } = await pageFor(t); await selected(page);
  await control(page, 'monitor').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-stop-monitor]').disabled);
  await page.evaluate(() => { window.settings.closeFailure = true; window.endReceive(); });
  await page.waitForFunction(() => window.usbPort.readable === null);
  assert.match(await control(page, 'log').textContent(), /USB disconnect failed.*mock close failure/);
  assert.match(await control(page, 'status').textContent(), /mock close failure/);
  assert.equal(await control(page, 'flash').isDisabled(), true);
});
test('approved install hashes all downloads, authorizes exact fields, writes Uint8Array and verifies readback without reboot', async t => {
  const { page, network, posts } = await pageFor(t); await identified(page); await flash(page);
  assert.match(await control(page, 'progress-label').textContent(), /verified by SHA-256 readback/);
  const list = await calls(page);
  assert.ok(list.indexOf('authorized') < list.indexOf('postConnect'));
  assert.equal(list[list.indexOf('stub') - 1], 'postConnect');
  assert.ok(list.indexOf('stub') < list.indexOf('write'));
  assert.deepEqual(list.slice(-4), ['write', ['readback', 0, 4], 'disconnect', 'close']);
  assert.equal(posts.length, 1);
  for (const field of ['release_id', 'chip_family', 'chip_mac', 'isolated_confirmed', 'expected_version']) assert.match(posts[0], new RegExp('name="' + field + '"'));
  assert.match(posts[0], /name="expected_version"\r\n\r\n3/);
  assert.match(posts[0], /name="csrf_test_name"/);
  assert.equal((posts[0].match(/Content-Disposition/g) || []).length, 6);
  const downloads = network.findIndex(n => /\/parts\/0$/.test(n.url));
  const auth = network.findIndex(n => /authorize-flash$/.test(n.url));
  assert.ok(downloads >= 0 && auth > downloads);
  assert.match(await control(page, 'status').textContent(), /No reboot requested/);
});
for (const settings of [
  { family: 'ESP32' }, { flashId: 0x1640ef }, { mac: '11:22:33:44:55:66' }, { secure: true }
]) test('chip safety gate denies installation: ' + JSON.stringify(settings), async t => {
  const { page } = await pageFor(t, settings);
  if (settings.secure) {
    await selected(page); await control(page, 'identify').click(); await confirm(page);
    await page.waitForFunction(() => document.querySelector('[data-usb-status]').textContent.includes('Secure Download'));
  } else await identified(page);
  await control(page, 'release').selectOption('approved-v1');
  assert.equal(await control(page, 'flash').isDisabled(), true);
  assert.equal((await calls(page)).includes('write'), false);
});
test('server flash flag fails closed', async t => {
  const { page } = await pageFor(t, {}, '?gate-off'); await identified(page);
  await control(page, 'release').selectOption('approved-v1');
  assert.equal(await control(page, 'flash').isDisabled(), true);
});
for (const settings of [
  ...['url', 'external', 'unaligned', 'overlap', 'count', 'size', 'hash'].map(manifest => ({ manifest })),
  { changed: true }, { denied: true }, { redirect: true }
]) test('prewrite integrity/authorization fails closed: ' + JSON.stringify(settings), async t => {
  const { page } = await pageFor(t, settings); await identified(page); await flash(page);
  assert.equal((await calls(page)).includes('write'), false);
  assert.equal((await calls(page)).includes('stub'), false);
  assert.equal((await calls(page)).includes('postConnect'), false);
  assert.doesNotMatch(await control(page, 'progress-label').textContent(), /verified/);
});
for (const settings of [{ postConnectFailure: true }, { postConnectRevokeIsolation: true }]) {
  test('postConnect failure or isolation loss prevents stub upload and writes: ' + JSON.stringify(settings), async t => {
    const { page } = await pageFor(t, settings); await identified(page);
    await control(page, 'release').selectOption('approved-v1');
    await control(page, 'flash').click(); await confirm(page);
    await page.waitForFunction(() => !document.querySelector('[data-usb-select]').disabled);
    const list = await calls(page);
    assert.ok(list.includes('postConnect'));
    assert.ok(list.indexOf('authorized') < list.indexOf('postConnect'));
    assert.equal(list.includes('stub'), false); assert.equal(list.includes('write'), false);
    assert.ok(list.includes('disconnect'));
    assert.match(await control(page, 'status').textContent(), /mock postConnect failure|Physical motor isolation/);
  });
}
test('readback mismatch never claims verification', async t => {
  const { page } = await pageFor(t, { badReadback: true }); await identified(page); await flash(page);
  assert.match(await control(page, 'status').textContent(), /partial firmware.*readback SHA-256 mismatch/);
  assert.doesNotMatch(await control(page, 'progress-label').textContent(), /All approved parts verified/);
  assert.ok((await calls(page)).includes('disconnect'));
});
test('writing blocks links, cancellable navigation and unload; no early hardware release', async t => {
  const { page } = await pageFor(t, { hold: true }); await identified(page);
  await control(page, 'release').selectOption('approved-v1'); await control(page, 'flash').click(); await confirm(page);
  await page.waitForFunction(() => !!window.finishWrite);
  const before = await calls(page);
  const result = await page.evaluate(() => {
    const nav = new CustomEvent('synapse:before-navigate', { cancelable: true, detail: { url: location.href } });
    document.dispatchEvent(nav);
    const link = document.createElement('a'); link.href = '/elsewhere'; document.body.append(link);
    const click = new MouseEvent('click', { bubbles: true, cancelable: true }); link.dispatchEvent(click);
    const unload = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(unload);
    return [nav.defaultPrevented, click.defaultPrevented, unload.defaultPrevented];
  });
  assert.deepEqual(result, [true, true, true]); assert.deepEqual(await calls(page), before);
  await page.evaluate(() => window.finishWrite());
  await page.waitForFunction(() => document.querySelector('[data-usb-progress]').value === 100);
});
test('before-swap/pagehide cancel monitor, release resources, initialize a new root exactly once', async t => {
  const { page } = await pageFor(t); await selected(page);
  await control(page, 'monitor').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-stop-monitor]').disabled);
  await page.evaluate(() => {
    const old = document.querySelector('[data-bmg-usb]');
    window.replacement = old.cloneNode(true);
    document.dispatchEvent(new CustomEvent('synapse:before-swap'));
  });
  await page.waitForFunction(() => window.usbPort.readable === null);
  await page.evaluate(() => {
    document.querySelector('[data-bmg-usb]').replaceWith(window.replacement);
    document.dispatchEvent(new CustomEvent('synapse:page-load'));
    document.dispatchEvent(new CustomEvent('synapse:page-load'));
  });
  assert.equal(await control(page, 'identify').isDisabled(), true);
  await control(page, 'select').click();
  await page.waitForFunction(() => window.calls.filter(c => c === 'request').length === 2);
  assert.equal((await calls(page)).filter(c => c === 'request').length, 2);
  await control(page, 'isolated').check(); await control(page, 'monitor').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-stop-monitor]').disabled);
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  await page.waitForFunction(() => window.usbPort.readable === null);
});
test('USB unplug stops the reader and invalidates chip identity', async t => {
  const { page } = await pageFor(t); await identified(page);
  await control(page, 'monitor').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-stop-monitor]').disabled);
  await page.evaluate(() => {
    const event = new Event('disconnect'); Object.defineProperty(event, 'port', { value: window.usbPort });
    navigator.serial.dispatchEvent(event);
  });
  await page.waitForFunction(() => window.usbPort.readable === null);
  assert.match(await control(page, 'status').textContent(), /disconnected.*partial/);
  assert.match(await control(page, 'identity').textContent(), /Not identified/);
  assert.equal(await control(page, 'flash').isDisabled(), true);
});
test('revoked isolation while confirmation is open fails closed', async t => {
  const { page } = await pageFor(t); await selected(page);
  await control(page, 'identify').click();
  await page.evaluate(() => { document.querySelector('[data-usb-isolated]').checked = false; });
  await confirm(page);
  await page.waitForFunction(() => document.querySelector('[data-usb-status]').textContent.includes('Physical motor isolation'));
  assert.deepEqual(await calls(page), ['request']);
});
test('pagehide during write retains the hardware lock until protocol completion and never verifies an abandoned write', async t => {
  const { page } = await pageFor(t, { hold: true }); await identified(page);
  await control(page, 'release').selectOption('approved-v1'); await control(page, 'flash').click(); await confirm(page);
  await page.waitForFunction(() => !!window.finishWrite);
  const before = await calls(page);
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  assert.deepEqual(await calls(page), before);
  await page.evaluate(() => window.finishWrite());
  await page.waitForFunction(() => window.usbPort.readable === null);
  assert.match(await control(page, 'status').textContent(), /partial firmware/);
  assert.equal((await calls(page)).some(c => Array.isArray(c) && c[0] === 'readback'), false);
  await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
  assert.equal(await control(page, 'identify').isDisabled(), true);
  assert.equal(await control(page, 'select').isDisabled(), false);
});
test('normal Synapse navigation waits for monitor cleanup before replacement', async t => {
  const { page } = await pageFor(t); await selected(page);
  await control(page, 'monitor').click(); await confirm(page);
  await page.waitForFunction(() => !document.querySelector('[data-usb-stop-monitor]').disabled);
  await page.evaluate(() => window.SynapseNavigation.navigate('/facilities/unit/11/usb?next'));
  await page.waitForURL('**?next');
  assert.equal(await page.evaluate(() => window.usbPort.readable), null);
  assert.equal(await control(page, 'identify').isDisabled(), true);
});
