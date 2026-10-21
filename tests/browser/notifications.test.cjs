'use strict';

// Real CI header/layout, CSS and scripts; only the personal JSON API is synthetic.
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
let browser, server, origin, html, serial = 0;
const scenarios = new Map();
const bell = '#notificationBell', menu = '#notificationMenu';
const badge = '[data-notification-badge]', items = '.notification-item';
const all = '[data-notification-mark-all]', error = '[data-notification-error]';
const item = (id = 17, extra = {}) => ({ id, title: 'Appointment confirmed',
  message: 'PRIVATE-FIXTURE consultation with Browser Patient', created_at: '2026-10-16T09:30:00', read_at: null,
  url: '/portal#my-appointments', ...extra });
const payload = (count = 1, entries = [item()]) => ({ items: entries, unread_count: count });
function gate() {
  let release;
  const promise = new Promise(resolve => { release = resolve; });
  return { promise, release };
}
function json(res, body, status = 200, headers = {}) {
  if (!res.destroyed) res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', ...headers }).end(JSON.stringify(body));
}

before(async () => {
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      const id = /(?:^|;\s*)notificationScenario=(\d+)/.exec(req.headers.cookie || '')?.[1];
      const scenario = scenarios.get(id);
      if (url.pathname === '/notifications' || /^\/notifications\/(?:read-all|\d+\/read)$/.test(url.pathname)) {
        if (!scenario) return json(res, {}, 400);
        const chunks = [];
        for await (const chunk of req) chunks.push(chunk);
        const call = { method: req.method, path: url.pathname, headers: req.headers, body: Buffer.concat(chunks).toString() };
        scenario.calls.push(call);
        const reply = await scenario.api(call, scenario.calls.length);
        return json(res, reply.body, reply.status ?? 200, reply.headers);
      }
      if (url.pathname === '/notification-fixture' || url.pathname === '/portal' || ['/portal/announcements', '/portal/surveys'].includes(url.pathname) || /^\/(clinic|guidance)\/referrals$/.test(url.pathname)) {
        res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }).end(html);
        return;
      }
      const base = path.join(root, 'public');
      const file = path.resolve(base, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(base + path.sep)) return res.writeHead(403).end();
      fs.readFile(file, (failure, content) => {
        if (failure) return res.writeHead(404).end();
        const ext = path.extname(file);
        res.writeHead(200, { 'Content-Type': ({ '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.webp': 'image/webp', '.woff2': 'font/woff2' })[ext] || 'application/octet-stream' }).end(content);
      });
    } catch (failure) { if (!res.destroyed) res.writeHead(500).end(String(failure)); }
  });
  server.requestTimeout = 10_000;
  server.headersTimeout = 10_000;
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  const rendered = spawnSync('php', ['tests/_support/render-notification-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin }), encoding: 'utf8', timeout: 10_000
  });
  assert.equal(rendered.status, 0, rendered.stderr || rendered.stdout);
  assert.doesNotMatch(rendered.stdout, /Fatal error|Warning:|Parse error|"code":\s*500/);
  assert.match(rendered.stdout, /data-notifications/);
  assert.match(rendered.stdout, /assets\/js\/notifications.js/);
  html = rendered.stdout;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
    assert.ok(executablePath, 'Install Playwright Chromium before running notification tests');
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20_000 });
}, { timeout: 40_000 });

after(async () => {
  for (const scenario of scenarios.values()) for (const pending of scenario.gates) pending.release();
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
}, { timeout: 20_000 });

async function fixture(t, options = {}) {
  const id = String(++serial);
  const scenario = { calls: [], gates: [], api: options.api || (() => ({ body: payload() })) };
  scenarios.set(id, scenario);
  const context = await browser.newContext({ viewport: options.mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 } });
  await context.addCookies([{ name: 'notificationScenario', value: id, url: origin }]);
  await context.route('https://**/*', route => route.abort());
  await context.addInitScript(({ theme, ignoreAbort }) => {
    localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
    // Capture only the notification poll, leaving Bootstrap and other timers alone.
    const nativeSet = window.setTimeout.bind(window), nativeClear = window.clearTimeout.bind(window);
    const pending = new Map(); let next = -1;
    window.__notificationTimers = pending;
    window.setTimeout = (fn, delay, ...args) => {
      if (delay !== 30000) return nativeSet(fn, delay, ...args);
      const id = next--; pending.set(id, () => fn(...args)); return id;
    };
    window.clearTimeout = id => { if (!pending.delete(id)) nativeClear(id); };
    window.__runNotificationPoll = async () => {
      const [id, fn] = pending.entries().next().value || [];
      if (!fn) throw new Error('No 30-second notification poll scheduled');
      pending.delete(id); await fn();
    };
    let hidden = false;
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => hidden ? 'hidden' : 'visible' });
    window.__visibility = value => { hidden = value; document.dispatchEvent(new Event('visibilitychange')); };
    if (ignoreAbort) {
      const fetch = window.fetch.bind(window);
      window.fetch = async (url, options) => {
        const isList = new URL(url, location.href).pathname === '/notifications';
        const response = await fetch(url, isList ? { ...options, signal: undefined } : options);
        if (!isList) return response;
        const json = response.json.bind(response);
        response.json = async () => { const body = await json(); window.__listCompletions = (window.__listCompletions || 0) + 1; return body; };
        return response;
      };
    }
  }, { theme: options.theme || 'light', ignoreAbort: Boolean(options.ignoreAbort) });
  const page = await context.newPage(); page.setDefaultTimeout(5000);
  const errors = [];
  page.on('pageerror', failure => errors.push(failure.stack || failure.message));
  t.after(async () => {
    for (const pending of scenario.gates) pending.release();
    await context.close(); scenarios.delete(id);
    assert.deepEqual(errors, [], 'No browser script errors');
  });
  scenario.hold = () => { const pending = gate(); scenario.gates.push(pending); return pending; };
  scenario.page = page;
  await page.goto(origin + '/notification-fixture');
  return scenario;
}
async function ready(page, unread = '1') {
  await page.waitForFunction(value => document.querySelector('[data-notification-count]')?.textContent === `${value} unread`, unread);
}
async function open(page) {
  await page.locator(bell).click();
  await page.locator(menu).waitFor({ state: 'visible' });
}
async function settled(page) {
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}
const posts = scenario => scenario.calls.filter(call => call.method === 'POST');

for (const theme of ['light', 'dark']) for (const mobile of [false, true]) {
  test(`${theme} ${mobile ? 'mobile' : 'desktop'}: real v3 menu, API badge, keyboard and basic contrast`, { timeout: 20_000 }, async t => {
    const s = await fixture(t, { theme, mobile, api: () => ({ body: payload(7) }) });
    const { page } = s;
    await ready(page, '7');
    assert.equal(await page.locator(bell).isVisible(), true);
    const box = await page.locator(bell).boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= (mobile ? 390 : 1280), 'Bell stays inside viewport');
    assert.equal(await page.locator(badge).innerText(), '7', 'Count comes from API, not rendered item count');
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
    await page.locator(bell).focus(); await page.keyboard.press('Enter');
    await page.locator(menu).waitFor({ state: 'visible' });
    assert.equal(await page.locator(menu).getAttribute('class').then(value => value.includes('notification-menu')), true);
    assert.equal(await page.locator(items).count(), 1);
    assert.equal(await page.locator(bell).getAttribute('aria-expanded'), 'true');
    // Query in the same browser task: the opening GET may replace list nodes.
    const contrast = await page.evaluate(() => {
      const node = document.querySelector('.notification-title');
      const rgb = value => value.match(/[\d.]+/g).map(Number);
      let current = node, background;
      while (current) {
        const color = rgb(getComputedStyle(current).backgroundColor);
        if (color.length < 4 || color[3] === 1) { background = color; break; }
        current = current.parentElement;
      }
      const luminance = color => color.slice(0, 3).map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4)
        .reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
      if (!background) throw new Error('No opaque menu background');
      const a = luminance(rgb(getComputedStyle(node).color)), b = luminance(background);
      return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    });
    assert.ok(contrast >= 4.5, `Notification title contrast ${contrast.toFixed(2)} must be at least 4.5:1`);
    await page.keyboard.press('Escape');
    await page.locator(menu).waitFor({ state: 'hidden' });
    assert.equal(await page.locator(bell).getAttribute('aria-expanded'), 'false');
    assert.equal(await page.locator(bell).evaluate(node => node === document.activeElement), true);
    await page.keyboard.press('Space'); await page.locator(menu).waitFor({ state: 'visible' });
    assert.equal(posts(s).length, 0, 'Opening and toggling never marks notifications read');
  });
}

test('Loading, empty, fetch error and retry never invent unread counts', { timeout: 20_000 }, async t => {
  let pending = gate(), mode = 'loading';
  const s = await fixture(t, { api: async () => {
    if (mode === 'loading') await pending.promise;
    return mode === 'error' ? { status: 503, body: {} } : { body: payload(0, []) };
  } });
  s.gates.push(pending);
  const { page } = s;
  assert.equal(await page.locator(badge).isVisible(), false);
  assert.match(await page.locator('[data-notification-list]').textContent(), /Loading/);
  mode = 'empty'; pending.release(); await ready(page, '0'); await open(page);
  assert.match(await page.locator('[data-notification-list]').innerText(), /No notifications/);
  assert.equal(await page.locator(badge).isVisible(), false);
  assert.equal(await page.locator(all).isDisabled(), true);
  mode = 'error';
  await page.evaluate(() => window.__runNotificationPoll());
  await page.locator(error).waitFor({ state: 'visible' });
  assert.equal(await page.locator(badge).isVisible(), false);
  mode = 'success'; s.api = () => ({ body: payload(3) });
  await page.locator('[data-notification-retry]').click(); await ready(page, '3');
  assert.equal(await page.locator(error).isVisible(), false);
  assert.equal(posts(s).length, 0);
});

test('Item read sends fresh CSRF POST and navigates only after success to fixed portal URL', { timeout: 20_000 }, async t => {
  let writing;
  const s = await fixture(t, { api: async call => {
    if (call.method === 'GET') return { body: payload() };
    await writing.promise; return { body: payload(0, [item(17, { read_at: '2026-10-16 10:00:00' })]) };
  } });
  writing = s.hold();
  const { page } = s; await ready(page); await open(page); await ready(page);
  const csrf = await page.evaluate(() => ({ header: document.querySelector('meta[name="csrf-header"]').content.toLowerCase(), token: document.querySelector('meta[name="csrf-token"]').content }));
  await page.locator(items).click();
  await page.waitForFunction(() => document.querySelector('[data-notifications]').getAttribute('aria-busy') === 'true');
  await assertEventually(() => posts(s).length === 1);
  assert.equal(page.url(), origin + '/notification-fixture');
  assert.equal(posts(s)[0].path, '/notifications/17/read');
  assert.equal(posts(s)[0].headers[csrf.header], csrf.token);
  assert.equal(posts(s)[0].headers['x-requested-with'], 'XMLHttpRequest');
  writing.release();
  await page.waitForURL(origin + '/portal#my-appointments');
});

for (const unit of ['clinic', 'guidance']) {
  test(`${unit} referral notice opens only its configured incoming inbox after read POST`, { timeout: 20_000 }, async t => {
    const target = `/${unit}/referrals?tab=incoming`;
    const s = await fixture(t, { api: call => call.method === 'POST'
      ? { body: payload(0, []) }
      : { body: payload(1, [item(31, { title: 'Incoming referral', message: 'A new referral is available.', url: target })]) } });
    await ready(s.page);
    await open(s.page);
    assert.equal(await s.page.locator(items).getAttribute('href'), origin + target);
    assert.equal(await s.page.locator(`${items} .bi-arrow-left-right`).count(), 1);
    await s.page.locator(items).click();
    await s.page.waitForURL(origin + target);
    assert.equal(posts(s).length, 1);
    assert.equal(posts(s)[0].path, '/notifications/31/read');
  });
}

for (const [title, target, glyph] of [
  ['New announcement', '/portal/announcements', 'bi-megaphone'],
  ['New survey', '/portal/surveys', 'bi-clipboard-check']
]) {
  test(`${title} navigates to exact ${target} only after successful read POST`, { timeout: 20_000 }, async t => {
    let writing;
    const s = await fixture(t, { api: async call => {
      if (call.method === 'GET') return { body: payload(1, [item(41, { title, message: 'New campus engagement item.', url: target })]) };
      await writing.promise;
      return { body: payload(0, []) };
    } });
    writing = s.hold();
    const { page } = s;
    await ready(page); await open(page);
    assert.equal(await page.locator(items).getAttribute('href'), origin + target);
    assert.equal(await page.locator(`${items} .${glyph}`).count(), 1);
    await page.locator(items).click();
    await assertEventually(() => posts(s).length === 1);
    assert.equal(page.url(), origin + '/notification-fixture');
    assert.equal(posts(s)[0].path, '/notifications/41/read');
    assert.equal(posts(s)[0].headers['x-requested-with'], 'XMLHttpRequest');
    writing.release();
    await page.waitForURL(origin + target);
    assert.equal(page.url(), origin + target);
    assert.equal(posts(s).length, 1);
  });
}

test('Failed item POST preserves unread and never navigates', { timeout: 20_000 }, async t => {
  const s = await fixture(t, { api: call => call.method === 'POST' ? { status: 500, body: {} } : { body: payload(4) } });
  const { page } = s; await ready(page, '4'); await open(page);
  await page.locator(items).click(); await page.locator(error).waitFor({ state: 'visible' });
  assert.equal(page.url(), origin + '/notification-fixture');
  assert.equal(await page.locator(badge).innerText(), '4');
  assert.equal(await page.locator('.notification-item.unread').count(), 1);
  assert.equal(await page.locator(all).isEnabled(), true);
});

test('Bell tooltip is suppressed while open so mark-all remains pointer-accessible', { timeout: 15_000 }, async t => {
  const s = await fixture(t); const { page } = s; await ready(page);
  await page.locator(bell).hover();
  await page.locator('[role="tooltip"]').waitFor({ state: 'visible' });
  await open(page);
  await page.locator('[role="tooltip"]').waitFor({ state: 'hidden' });
  await page.locator(all).click({ timeout: 2000 });
  await assertEventually(() => posts(s).length === 1);
});

test('Mark-all POST uses rotated header token and synchronizes payload token into forms', { timeout: 20_000 }, async t => {
  let writes = 0;
  const s = await fixture(t, { api: call => {
    if (call.method === 'GET') return { body: payload(), headers: { 'X-CSRF-TOKEN': 'header-token', 'X-CSRF-NAME': 'rotated_name', 'X-CSRF-HEADER': 'X-Fixture-CSRF' } };
    writes++;
    return writes === 1 ? { body: { ...payload(1), csrf: { name: 'payload_name', hash: 'payload-token', header: 'X-Payload-CSRF' } } }
      : { body: payload(0, [item(17, { read_at: '2026-10-16 10:00:00' })]) };
  } });
  const { page } = s; await ready(page); await open(page); await ready(page);
  assert.equal(await page.locator('input[type="hidden"][name="rotated_name"]').inputValue(), 'header-token');
  // Keyboard activation isolates token handling from pointer/tooltip geometry.
  await page.locator(all).press('Enter');
  await page.waitForFunction(() => document.querySelector('meta[name="csrf-token"]').content === 'payload-token');
  assert.equal(posts(s)[0].path, '/notifications/read-all');
  assert.equal(posts(s)[0].headers['x-fixture-csrf'], 'header-token');
  assert.equal(await page.locator('input[type="hidden"][name="payload_name"]').inputValue(), 'payload-token');
  await page.locator(all).press('Enter'); await ready(page, '0');
  assert.equal(posts(s)[1].headers['x-payload-csrf'], 'payload-token');
  assert.equal(await page.locator(badge).isVisible(), false);
  assert.equal(await page.locator(all).isDisabled(), true);
  assert.equal(page.url(), origin + '/notification-fixture');
});

test('Rapid item and mark-all clicks share one in-flight write', { timeout: 20_000 }, async t => {
  let pending;
  const s = await fixture(t, { api: async call => {
    if (call.method === 'POST') { await pending.promise; return { body: payload(0, []) }; }
    return { body: payload() };
  } });
  pending = s.hold(); const { page } = s; await ready(page); await open(page);
  await page.locator(all).evaluate(node => {
    node.click(); node.click();
    const link = document.querySelector('.notification-item');
    link.click(); link.click();
  });
  await assertEventually(() => posts(s).length === 1);
  await settled(page);
  assert.equal(posts(s).length, 1);
  assert.equal(posts(s)[0].path, '/notifications/read-all');
  pending.release(); await ready(page, '0');
  assert.equal(page.url(), origin + '/notification-fixture');
});

test('A stale GET cannot overwrite a newer successful POST even if abort is ignored', { timeout: 20_000 }, async t => {
  let pending, delayGet = false;
  const s = await fixture(t, { ignoreAbort: true, api: async call => {
    if (call.method === 'POST') return { body: payload(0, [item(17, { read_at: '2026-10-16 10:00:00' })]) };
    if (delayGet) { await pending.promise; return { body: payload(9) }; }
    return { body: payload() };
  } });
  const { page } = s; await ready(page); await open(page); await ready(page);
  // Finish the open-triggered refresh before introducing the delayed poll.
  await page.waitForFunction(() => window.__listCompletions >= 2);
  pending = s.hold(); delayGet = true;
  const before = s.calls.length;
  await page.evaluate(() => { window.__pendingPoll = window.__runNotificationPoll(); });
  await assertEventually(() => s.calls.length > before);
  await page.locator(all).press('Enter'); await ready(page, '0');
  pending.release(); await page.evaluate(() => window.__pendingPoll); await settled(page);
  assert.equal(await page.locator('[data-notification-count]').innerText(), '0 unread');
  assert.equal(await page.locator('.notification-item.unread').count(), 0);
});

test('Untrusted content is literal text; API URLs are never used; PII is not persisted', { timeout: 20_000 }, async t => {
  const title = '<img src=x onerror="window.notificationXss=1">';
  const message = '<svg onload="window.notificationXss=2">PRIVATE-FIXTURE sensitive appointment</svg>';
  const malicious = ['javascript:window.notificationXss=3', 'https://attacker.invalid/steal', '//attacker.invalid/', '/admin/users', '/clinic/referrals?tab=sent', '/guidance/referrals?tab=incoming&patient=999'];
  const s = await fixture(t, { api: () => ({ body: payload(4, malicious.map((url, index) => item(index + 1, { title, message, url }))) }) });
  const { page } = s; await ready(page, '4'); await open(page);
  assert.equal(await page.locator('.notification-title').first().textContent(), title);
  assert.equal(await page.locator('.notification-text').first().textContent(), message);
  assert.equal(await page.locator('[data-notification-list] img, [data-notification-list] svg, [data-notification-list] script').count(), 0);
  assert.deepEqual(await page.locator(items).evaluateAll(nodes => nodes.map(node => node.href)), malicious.map(() => origin + '/portal#my-appointments'));
  assert.equal(await page.evaluate(() => window.notificationXss), undefined);
  await page.evaluate(() => window.__runNotificationPoll());
  const stored = await page.evaluate(async () => ({
    local: { ...localStorage }, session: { ...sessionStorage },
    databases: indexedDB.databases ? await indexedDB.databases() : [], caches: await caches.keys()
  }));
  assert.doesNotMatch(JSON.stringify(stored), /PRIVATE-FIXTURE|sensitive appointment|Appointment confirmed|notificationXss/);
  // The shell may retain its non-sensitive sidebar scroll preference.
  assert.deepEqual(Object.keys(stored.session).filter(key => key !== 'synapse-sidebar-scroll'), []);
  assert.deepEqual(stored.databases, []);
  assert.deepEqual(stored.caches, []);
});

test('30-second polling runs only while visible and stops on pagehide', { timeout: 20_000 }, async t => {
  const s = await fixture(t); const { page } = s; await ready(page);
  assert.equal(await page.evaluate(() => window.__notificationTimers.size), 1);
  let count = s.calls.length;
  await page.evaluate(() => window.__runNotificationPoll());
  assert.equal(s.calls.length, count + 1);
  await page.evaluate(() => window.__visibility(true));
  assert.equal(await page.evaluate(() => window.__notificationTimers.size), 0);
  count = s.calls.length;
  await page.evaluate(() => document.querySelector('[data-notifications]').dispatchEvent(new Event('shown.bs.dropdown')));
  await settled(page); assert.equal(s.calls.length, count, 'Hidden dropdown events cannot fetch');
  await page.evaluate(() => window.__visibility(false));
  await assertEventually(() => s.calls.length === count + 1);
  assert.equal(await page.evaluate(() => window.__notificationTimers.size), 1);
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  assert.equal(await page.evaluate(() => window.__notificationTimers.size), 0);
  count = s.calls.length;
  await page.evaluate(() => window.__visibility(false)); await settled(page);
  assert.equal(s.calls.length, count, 'No work after pagehide');
});

async function assertEventually(predicate) {
  const deadline = Date.now() + 5000;
  while (!predicate()) {
    assert.ok(Date.now() < deadline, 'Expected server request within 5 seconds');
    await new Promise(resolve => setTimeout(resolve, 10));
  }
}
