'use strict';
// Real rendered view; endpoints are intercepted and no physical serial device is used.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const { crc32 } = require('node:zlib');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const cache = new Map();
const deviceToken = 'ab'.repeat(32);
const caPem = '-----BEGIN CERTIFICATE-----\nMOCK_PUBLIC_CA\n-----END CERTIFICATE-----';
const policySource = '#pragma once\n// reviewed motor-disabled policy\n';
before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, origin);
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(root, 'public', '.' + url.pathname);
      if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
      res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css');
      res.end(fs.readFileSync(file)); return;
    }
    if (!cache.has(req.url)) {
      const result = spawnSync('php', ['tests/_support/render-bmg-usb-fixture.php'], { cwd: root, input: JSON.stringify({ origin, path: req.url }), encoding: 'utf8', timeout: 15000 });
      if (result.status !== 0) { res.writeHead(500); res.end(result.stderr || result.stdout); return; }
      cache.set(req.url, result.stdout);
    }
    res.setHeader('Content-Type', 'text/html'); res.end(cache.get(req.url));
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  browser = await chromium.launch({ executablePath, headless: true, timeout: 20000 });
});
after(async () => { await browser?.close(); server?.close(); });
async function pageFor(t, settings = {}) {
  const context = await browser.newContext({ viewport: settings.mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 } });
  t.after(() => context.close());
  const page = await context.newPage();
  const posts = [], requests = [], errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/usb/{setup-status,sketch,build}', async route => {
    const request = route.request(); requests.push(request.url());
    if (request.url().endsWith('/build')) {
      posts.push({ body: request.postData(), headers: request.headers() });
      if (settings.hold) await new Promise(resolve => { settings.release = resolve; });
      if (settings.fail) return route.abort();
      if (settings.invalidJson) return route.fulfill({ contentType: 'application/json', body: '{invalid' });
      if (settings.unknownResponse) return route.fulfill({ json: { success: 'maybe' } });
      if (settings.errorStatus && posts.length === 1) return route.fulfill({ status: settings.errorStatus, json: {
        success: false, message: settings.errorMessage || 'Review the configuration before building.', csrf: { name: 'csrf_test_name', hash: 'rejected-build-token' }
      } });
      return route.fulfill({ json: { success: true, release: { id: 'private-build-1', name: '<private>', chip_family: 'ESP32-S3', flash_size: '16MB' }, csrf: { name: 'csrf_test_name', hash: 'fresh-build-token' } } });
    }
    if (request.url().endsWith('/sketch')) return route.fulfill({ json: { source: '// reviewed commissioning only\nvoid setup() {}', filename: 'compost.ino', motor_outputs_enabled: false,
      support_files: settings.missingSupport ? {} : { 'commissioning_policy.h': policySource } } });
    return route.fulfill({ json: { builder: { available: !settings.unavailable, message: 'Prerequisites checked', board_profile: 'esp32:esp32:esp32s3', firmware_version: 'network-v1', motor_outputs_enabled: settings.unsafe === true },
      device: { last_seen_at: '2025-01-01 12:00:00', firmware_version: 'old-v0', firmware_reported_at: null }, csrf: { name: 'csrf_test_name', hash: 'fresh-status-token' } } });
  });
  await page.goto(origin + '/facilities/unit/11/usb');
  await page.waitForFunction(() => !!window.SynapseBmgSetup);
  return { page, posts, requests, errors };
}
function inspectZip(bytes) {
  // Independently read both directories and check CRCs using Node's native zlib.
  const end = bytes.length - 22, files = {};
  assert.equal(bytes.readUInt32LE(end), 0x06054b50);
  const count = bytes.readUInt16LE(end + 10), start = bytes.readUInt32LE(end + 16);
  let cursor = start;
  for (let index = 0; index < count; index++) {
    assert.equal(bytes.readUInt32LE(cursor), 0x02014b50);
    assert.equal(bytes.readUInt16LE(cursor + 10), 0); // STORE, not compressed
    const size = bytes.readUInt32LE(cursor + 24), nameLength = bytes.readUInt16LE(cursor + 28);
    const name = bytes.subarray(cursor + 46, cursor + 46 + nameLength).toString('utf8');
    const local = bytes.readUInt32LE(cursor + 42);
    assert.equal(bytes.readUInt32LE(local), 0x04034b50);
    assert.equal(bytes.readUInt16LE(local + 6), 0x800); // UTF-8 flag
    assert.equal(bytes.readUInt16LE(local + 8), 0);
    assert.equal(bytes.readUInt32LE(local + 18), size);
    assert.equal(bytes.readUInt32LE(local + 22), size);
    assert.equal(bytes.subarray(local + 30, local + 30 + nameLength).toString('utf8'), name);
    const data = bytes.subarray(local + 30 + nameLength, local + 30 + nameLength + size);
    assert.equal(crc32(data), bytes.readUInt32LE(local + 14));
    assert.equal(crc32(data), bytes.readUInt32LE(cursor + 16));
    assert.equal(files[name], undefined);
    files[name] = data.toString('utf8');
    cursor += 46 + nameLength + bytes.readUInt16LE(cursor + 30) + bytes.readUInt16LE(cursor + 32);
  }
  assert.equal(cursor, end);
  assert.equal(cursor - start, bytes.readUInt32LE(end + 12));
  return files;
}
const el = (page, name) => page.locator(`[data-setup-${name}]`);
async function prepare(page) {
  await el(page, 'check').click();
  await el(page, 'builder').filter({ hasText: 'Prerequisites checked' }).waitFor();
  await page.locator('[data-usb-isolated]').check();
  await el(page, 'ssid').fill('Compost 2.4');
  await el(page, 'password').fill('wifi-secret');
  await el(page, 'api').fill('https://compost.example.test/api/device');
  await el(page, 'token').fill(deviceToken);
  await page.locator('summary').filter({ hasText: 'HTTPS trust' }).click();
  await el(page, 'ca').fill(caPem);
}
test('real rendered view starts fail-closed, responsive and without requests or stored secrets', async t => {
  const { page, requests, errors } = await pageFor(t, { mobile: true });
  assert.equal(await el(page, 'build').isDisabled(), true);
  assert.equal(requests.length, 0);
  assert.equal(await el(page, 'password').getAttribute('type'), 'password');
  assert.equal(await el(page, 'token').getAttribute('type'), 'password');
  assert.equal(await page.locator('[data-setup-form] [name]').count(), 0);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  assert.deepEqual(errors, []);
});
test('build sends exact trusted fields and CSRF, selects private release without automatic installation', async t => {
  const { page, posts, errors } = await pageFor(t);
  await prepare(page);
  await el(page, 'build').click();
  await el(page, 'message').filter({ hasText: 'Private reviewed build selected' }).waitFor();
  assert.equal(posts.length, 1);
  const fields = [...posts[0].body.matchAll(/name="([^"]+)"/g)].map(match => match[1]).sort();
  assert.deepEqual(fields, ['allow_http', 'api_base_url', 'device_token', 'expected_version', 'physical_isolation', 'root_ca_pem', 'wifi_password', 'wifi_ssid'].sort());
  assert.ok(Object.keys(posts[0].headers).some(key => /csrf/i.test(key)));
  assert.equal(await page.locator('[data-usb-release]').inputValue(), 'private-build-1');
  assert.equal(await page.locator('[data-usb-flash]').isDisabled(), true);
  assert.equal(await page.locator('[data-usb-progress]').getAttribute('value'), '0');
  assert.equal(await el(page, 'token').inputValue(), '');
  assert.equal(await el(page, 'password').inputValue(), '');
  assert.equal(await page.evaluate(() => /wifi-secret|(?:ab){32}/.test(JSON.stringify({ ...localStorage, ...sessionStorage }))), false);
  assert.deepEqual(errors, []);
});
test('HTTP requires explicit acknowledgement; localhost and non-HTTP protocols are rejected', async t => {
  const { page, posts } = await pageFor(t); await prepare(page);
  for (const value of ['http://localhost/api', 'ftp://host.test/api', 'https://name:secret@host.test/api', 'https://host.test/api?secret=1']) {
    await el(page, 'api').fill(value); await el(page, 'build').click();
  }
  await el(page, 'api').fill('http://192.168.1.8/api'); await el(page, 'build').click();
  assert.equal(posts.length, 0);
  await el(page, 'http').check(); await el(page, 'build').click();
  await el(page, 'message').filter({ hasText: 'Private reviewed build selected' }).waitFor();
  assert.match(posts[0].body, /name="allow_http"\r\n\r\n1/);
});
test('unavailable or motor-enabled builder cannot compile', async t => {
  for (const settings of [{ unavailable: true }, { unsafe: true }]) {
    const { page, posts } = await pageFor(t, settings); await prepare(page);
    assert.equal(await el(page, 'build').isDisabled(), true); assert.equal(posts.length, 0);
  }
});
test('local edits and .ino uploads are export-only; reset restores canonical build', async t => {
  const { page, posts } = await pageFor(t); await prepare(page);
  await page.locator('summary').filter({ hasText: 'reviewed source' }).click();
  await el(page, 'source').click(); await el(page, 'editor').waitFor({ state: 'visible' });
  await page.waitForFunction(() => !document.querySelector('[data-setup-editor]').disabled);
  assert.doesNotMatch(await el(page, 'editor').inputValue(), /wifi-secret|(?:ab){32}|MOCK_PUBLIC_CA/);
  await el(page, 'editor').fill('// local edit');
  assert.equal(await el(page, 'build').isDisabled(), true);
  await el(page, 'upload').setInputFiles({ name: 'local.ino', mimeType: 'text/plain', buffer: Buffer.from('// local upload') });
  await page.waitForFunction(() => document.querySelector('[data-setup-editor]').value === '// local upload');
  assert.equal(await el(page, 'build').isDisabled(), true);
  const downloadPromise = page.waitForEvent('download'); await el(page, 'download').click();
  const download = await downloadPromise;
  assert.equal(download.suggestedFilename(), 'compost-project.zip');
  const files = inspectZip(fs.readFileSync(await download.path()));
  assert.deepEqual(Object.keys(files).sort(), ['compost/README.txt', 'compost/commissioning_policy.h', 'compost/compost.ino', 'compost/device_config.h.example']);
  assert.equal(files['compost/compost.ino'], '// local upload');
  assert.equal(files['compost/commissioning_policy.h'], policySource);
  assert.match(files['compost/device_config.h.example'], /static const char WIFI_PASS/);
  assert.match(files['compost/README.txt'], /Copy device_config.h.example to device_config.h/);
  assert.match(files['compost/README.txt'], /esp32:esp32:esp32s3/);
  assert.doesNotMatch(JSON.stringify(files), /wifi-secret|(?:ab){32}|MOCK_PUBLIC_CA|Compost 2.4|compost.example.test/);
  await el(page, 'source').click();
  await page.waitForFunction(() => !document.querySelector('[data-setup-build]').disabled);
  assert.equal(posts.length, 0);
});
test('pending build prevents duplicates, USB operations and navigation until response', async t => {
  const settings = { hold: true }; const { page, posts } = await pageFor(t, settings); await prepare(page);
  await el(page, 'build').click(); await page.waitForFunction(() => document.querySelector('[data-bmg-usb]').dataset.setupBusy === '1');
  assert.equal(await page.locator('[data-usb-select]').isDisabled(), true);
  assert.equal(await el(page, 'editor').isDisabled(), true);
  assert.equal(await el(page, 'build').getAttribute('aria-busy'), 'true');
  assert.equal(await el(page, 'waiting').isVisible(), true);
  assert.match(await el(page, 'message').textContent(), /may take 3 minutes/);
  assert.equal(await page.evaluate(() => {
    document.querySelector('[data-setup-form]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const event = new CustomEvent('synapse:before-navigate', { cancelable: true, detail: { url: '/elsewhere' } });
    document.dispatchEvent(event); return event.defaultPrevented;
  }), true);
  assert.equal(await page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event); return event.defaultPrevented;
  }), true);
  assert.equal(posts.length, 1); settings.release();
  await el(page, 'message').filter({ hasText: 'Private reviewed build selected' }).waitFor();
  assert.equal(await el(page, 'build').getAttribute('aria-busy'), 'false');
  assert.equal(await el(page, 'waiting').isVisible(), false);
});
test('uncertain build response remains locked and never claims failure or permits blind retry', async t => {
  const { page, posts } = await pageFor(t, { fail: true }); await prepare(page); await el(page, 'build').click();
  await el(page, 'message').filter({ hasText: 'outcome could not be confirmed' }).waitFor();
  assert.equal(await el(page, 'build').isDisabled(), true); assert.equal(posts.length, 1);
});
for (const status of [422, 409, 503]) test(`confirmed ${status} JSON rejection unlocks correction and explicit retry only`, async t => {
  const { page, posts } = await pageFor(t, { errorStatus: status, errorMessage: `Review wifi-secret and ${deviceToken}; <b>configuration rejected</b>.` });
  await prepare(page); await el(page, 'build').click();
  await el(page, 'message').filter({ hasText: 'Build rejected:' }).waitFor();
  assert.equal(await el(page, 'build').isDisabled(), false);
  assert.equal(await el(page, 'password').inputValue(), 'wifi-secret');
  assert.equal(await el(page, 'token').inputValue(), deviceToken);
  assert.doesNotMatch(await el(page, 'message').textContent(), /wifi-secret|(?:ab){32}/);
  assert.equal(await el(page, 'message').locator('b').count(), 0);
  assert.match(await el(page, 'message').textContent(), /explicitly choose Compile/);
  assert.equal(await el(page, 'waiting').isVisible(), false);
  assert.equal(await page.evaluate(() => {
    const event = new CustomEvent('synapse:before-navigate', { cancelable: true }); document.dispatchEvent(event); return event.defaultPrevented;
  }), false);
  await page.waitForTimeout(100); assert.equal(posts.length, 1);
  await el(page, 'password').fill('corrected-wifi'); await el(page, 'build').click();
  await el(page, 'message').filter({ hasText: 'Private reviewed build selected' }).waitFor();
  assert.equal(posts.length, 2); assert.match(posts[1].body, /corrected-wifi/);
  assert.ok(Object.values(posts[1].headers).includes('rejected-build-token'));
});
for (const settings of [{ invalidJson: true }, { unknownResponse: true }, { errorStatus: 500 }]) test(`unconfirmed response keeps retry and navigation locked: ${JSON.stringify(settings)}`, async t => {
  const { page, posts } = await pageFor(t, settings); await prepare(page); await el(page, 'build').click();
  await el(page, 'message').filter({ hasText: 'outcome could not be confirmed' }).waitFor();
  assert.equal(await el(page, 'build').isDisabled(), true);
  assert.equal(await el(page, 'waiting').isVisible(), false);
  assert.equal(await page.evaluate(() => {
    const event = new CustomEvent('synapse:before-navigate', { cancelable: true }); document.dispatchEvent(event); return event.defaultPrevented;
  }), true);
  assert.equal(posts.length, 1);
});
test('credential and SSID byte validation is inline, with no raw values or requests', async t => {
  const { page, posts } = await pageFor(t); await prepare(page);
  assert.match(await page.locator('a').filter({ hasText: 'Issue a token' }).getAttribute('href'), /#bmg-device$/);
  for (const [name, value, expected, restore] of [
    ['password', '', /8–64 characters/, 'wifi-secret'],
    ['password', 'short', /8–64 characters/, 'wifi-secret'],
    ['token', 'old-token', /64 lowercase hexadecimal/, deviceToken],
    ['token', 'A'.repeat(64), /64 lowercase hexadecimal/, deviceToken],
    ['ssid', 'é'.repeat(17), /1–32 UTF-8 bytes/, 'Compost 2.4'],
    ['ca', '', /HTTPS requires a reviewed root CA PEM/, caPem],
    ['ca', 'not-a-certificate', /HTTPS requires a reviewed root CA PEM/, caPem]
  ]) {
    await el(page, name).fill(value); await el(page, 'build').click();
    assert.match(await el(page, 'validation').textContent(), expected);
    assert.equal(posts.length, 0);
    await el(page, name).fill(restore);
  }
  assert.equal(await el(page, 'password').getAttribute('maxlength'), '64');
  assert.equal(await el(page, 'password').getAttribute('minlength'), '8');
});
test('local upload first requires support fetch, preserves edits and exports a complete secret-free ZIP', async t => {
  const { page, posts, requests } = await pageFor(t); await prepare(page);
  await page.locator('summary').filter({ hasText: 'reviewed source' }).click();
  const localSource = '// Custom local compost 🌱\nvoid setup() {}\n';
  await el(page, 'upload').setInputFiles({ name: 'custom.ino', mimeType: 'text/plain', buffer: Buffer.from(localSource) });
  await page.waitForFunction(() => !document.querySelector('[data-setup-editor]').disabled);
  assert.equal(await el(page, 'download').isDisabled(), true);
  assert.equal(requests.filter(url => url.endsWith('/sketch')).length, 0);
  await el(page, 'support').click();
  await page.waitForFunction(() => !document.querySelector('[data-setup-download]').disabled);
  assert.equal(await el(page, 'editor').inputValue(), localSource);
  assert.equal(await el(page, 'build').isDisabled(), true);
  const promise = page.waitForEvent('download'); await el(page, 'download').click();
  const files = inspectZip(fs.readFileSync(await (await promise).path()));
  assert.equal(files['compost/compost.ino'], localSource);
  assert.equal(files['compost/commissioning_policy.h'], policySource);
  assert.equal(files['compost/device_config.h'], undefined);
  assert.doesNotMatch(JSON.stringify(files), /wifi-secret|(?:ab){32}|MOCK_PUBLIC_CA|Compost 2.4|compost.example.test/);
  assert.equal(posts.length, 0);
});
test('missing reviewed policy header cannot produce a partial project ZIP', async t => {
  const { page } = await pageFor(t, { missingSupport: true });
  await page.locator('summary').filter({ hasText: 'reviewed source' }).click();
  await el(page, 'upload').setInputFiles({ name: 'custom.ino', mimeType: 'text/plain', buffer: Buffer.from('// custom') });
  await page.waitForFunction(() => !document.querySelector('[data-setup-editor]').disabled);
  await el(page, 'support').click();
  await el(page, 'message').filter({ hasText: 'Setup request could not be completed' }).waitFor();
  assert.equal(await el(page, 'download').isDisabled(), true);
  assert.equal(await el(page, 'editor').inputValue(), '// custom');
});
test('heartbeat check is manual and labels historical evidence without motor/RTC approval', async t => {
  const { page, requests } = await pageFor(t);
  await el(page, 'verify').click();
  await el(page, 'heartbeat').filter({ hasText: 'Historical last seen: 2025' }).waitFor();
  assert.match(await el(page, 'heartbeat').textContent(), /does not verify a new heartbeat, RTC or motor safety/);
  assert.equal(requests.length, 1);
});
test('cross-origin setup URL fails closed', async t => {
  const { page, requests } = await pageFor(t);
  await page.evaluate(() => {
    document.dispatchEvent(new Event('synapse:before-swap'));
    document.querySelector('[data-bmg-usb]').dataset.usbBuildUrl = 'https://other.test/facilities/unit/11/usb/build';
    document.dispatchEvent(new Event('synapse:page-load'));
  });
  assert.match(await el(page, 'message').textContent(), /Invalid setup endpoint/);
  assert.equal(await el(page, 'fields').evaluate(fieldset => fieldset.disabled), true);
  assert.equal(requests.length, 0);
});
test('active USB blocks build, source reads and edits until the USB state becomes idle', async t => {
  const { page, posts, requests } = await pageFor(t); await prepare(page);
  await page.evaluate(() => {
    window.originalCanSetup = window.SynapseBmgUsb.canSetup;
    window.SynapseBmgUsb.canSetup = () => false;
    document.querySelector('[data-bmg-usb]').dispatchEvent(new CustomEvent('synapse:bmg-usb-state', { detail: { busy: true, writing: true, monitoring: false } }));
  });
  assert.equal(await el(page, 'build').isDisabled(), true);
  assert.equal(await el(page, 'source').isDisabled(), true);
  assert.equal(await el(page, 'upload').isDisabled(), true);
  await page.evaluate(() => document.querySelector('[data-setup-form]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  assert.equal(posts.length, 0); assert.equal(requests.length, 1);
  await page.evaluate(() => {
    window.SynapseBmgUsb.canSetup = window.originalCanSetup;
    document.querySelector('[data-bmg-usb]').dispatchEvent(new CustomEvent('synapse:bmg-usb-state'));
  });
  assert.equal(await el(page, 'build').isDisabled(), false);
});
test('public release API rejects malformed metadata and does not bypass installation gates', async t => {
  const { page } = await pageFor(t);
  assert.equal(await page.evaluate(() => window.SynapseBmgUsb.selectBuild(document.querySelector('[data-bmg-usb]'), {
    id: '../untrusted', name: 'Bad', chip_family: 'ESP32-S3', flash_size: '16MB'
  })), false);
  assert.equal(await page.locator('[data-usb-release]').inputValue(), '');
  assert.equal(await page.locator('[data-usb-flash]').isDisabled(), true);
});
test('absent setup URLs remain inert for legacy fixtures', async t => {
  const { page, requests, errors } = await pageFor(t);
  await page.evaluate(() => {
    document.dispatchEvent(new Event('synapse:before-swap'));
    delete document.querySelector('[data-bmg-usb]').dataset.usbBuildUrl;
    document.dispatchEvent(new Event('synapse:page-load'));
    window.SynapseBmgSetup.initialize();
  });
  assert.equal(requests.length, 0); assert.deepEqual(errors, []);
});
