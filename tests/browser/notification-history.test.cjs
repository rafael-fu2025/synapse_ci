'use strict';
// Synthetic transport + the real PHP view, shell and CSRF helper. No application server or DB.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const panel = '[data-notification-history]';
const item = (id, overrides = {}) => ({ id, title: `Alert ${id}`, message: `Message ${id}`, created_at: '2026-10-20 12:34:56', read_at: null, url: origin + '/portal/announcements', ...overrides });
function history(url, items = [item(1)], total = items.length, unread = total) {
  return { items, total, unread_count: Math.min(100, unread), page: Number(url.searchParams.get('page')), pages: Math.ceil(total / 20), filter: url.searchParams.get('filter'), per_page: 20 };
}
function render(blank = false) {
  const result = spawnSync('php', ['tests/_support/render-notification-history-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, blank }), encoding: 'utf8', timeout: 15000,
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  return result.stdout;
}
before(async () => {
  const pages = new Map();
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (['/notifications/inbox', '/other'].includes(url.pathname)) {
      if (!pages.has(url.pathname)) pages.set(url.pathname, render(url.pathname === '/other'));
      return res.writeHead(200, { 'Content-Type': 'text/html' }).end(pages.get(url.pathname));
    }
    const base = path.join(root, 'public'), file = path.resolve(base, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(base + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(data);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening'); origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});
async function fixture(t, options = {}) {
  const context = await browser.newContext({ timezoneId: options.timezoneId || 'Asia/Manila', viewport: options.mobile ? { width: 375, height: 812 } : { width: 1366, height: 1000 } });
  const calls = [];
  await context.addInitScript(({ dark, ready }) => {
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    if (ready) window.SynapseRealtime = { status: 'ready' };
    // Drive long deadlines deterministically without waiting, writing artifacts or running a watcher.
    const set = window.setTimeout.bind(window), clear = window.clearTimeout.bind(window);
    const pending = new Map(); let serial = 1000000, hidden = false;
    window.setTimeout = (fn, delay, ...args) => {
      if ([10000, 30000, 60000].includes(delay)) { const id = serial++; pending.set(id, { fn: () => fn(...args), delay }); return id; }
      return set(fn, delay, ...args);
    };
    window.clearTimeout = id => { if (!pending.delete(id)) clear(id); };
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    window.historyTest = {
      fire(delay) { for (const [id, entry] of [...pending]) if (entry.delay === delay) { pending.delete(id); entry.fn(); } },
      count(delay) { return [...pending.values()].filter(entry => entry.delay === delay).length; },
      visible(value) { hidden = !value; document.dispatchEvent(new Event('visibilitychange')); },
    };
  }, { dark: !!options.mobile, ready: !!options.ready });
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== origin) return route.abort();
    if (url.pathname === '/notifications/history' || (request.method() === 'POST' && url.pathname.startsWith('/notifications/'))) {
      calls.push({ method: request.method(), url, headers: request.headers() });
      const result = await options.api?.(url, request, calls) || { body: request.method() === 'POST' ? { items: [], unread_count: 0 } : history(url) };
      return route.fulfill({ status: result.status || 200, contentType: 'application/json', headers: result.headers || {}, body: JSON.stringify(result.body) }).catch(() => {});
    }
    if (url.pathname === '/notifications') return route.fulfill({ contentType: 'application/json', body: '{"items":[],"unread_count":0}' });
    return route.continue();
  });
  const page = await context.newPage(); page.setDefaultTimeout(7000); page.setDefaultNavigationTimeout(20000);
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/notifications/inbox');
  await page.waitForFunction(() => window.SynapseNotificationHistory);
  return { page, calls, context };
}
async function settled(page) {
  await page.waitForFunction(() => document.querySelector('[data-notification-history]')?.getAttribute('aria-busy') === 'false');
}
async function emit(page) {
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail: { event: 'notifications.changed' } })));
}
async function eventually(check) {
  const deadline = Date.now() + 6000;
  while (Date.now() < deadline) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 30)); }
  assert.fail('Condition did not settle');
}
test('history displays Philippine time and explicit offset independently of browser timezone', async t => {
  const created = '2027-01-01T07:59:59+08:00';
  const { page } = await fixture(t, { timezoneId: 'America/New_York', api: url => ({ body: history(url, [item(1, { created_at: created })]) }) });
  await settled(page);
  const time = page.locator(`${panel} time`).first();
  assert.equal(await time.textContent(), '2027-01-01 07:59');
  assert.equal(await time.getAttribute('datetime'), created);
  assert.equal(await time.getAttribute('title'), 'Philippine time (Asia/Manila, UTC+08:00)');
});

const posts = calls => calls.filter(call => call.method === 'POST');
for (const mobile of [false, true]) test(`History ${mobile ? 'dark mobile' : 'desktop'}: pagination, explicit reads, filters, total and empty state`, { timeout: 35000 }, async t => {
  let read = false;
  const { page, calls } = await fixture(t, { mobile, api: (url, req) => {
    if (req.method() === 'POST') { read = true; return { body: { items: [], unread_count: 0, csrf: { name: 'csrf_test_name', hash: 'rotated-history-token', header: 'X-CSRF-TOKEN' } } }; }
    if (url.searchParams.get('filter') === 'unread' && read) return { body: history(url, [], 0, 0) };
    return { body: history(url, [item(url.searchParams.get('page') === '2' ? 21 : 1, { read_at: read ? '2026-10-24' : null })], 21, read ? 0 : 100) };
  } });
  await settled(page);
  assert.equal(await page.locator('.notification-footer a').textContent().then(text => text.trim()), 'View all notifications');
  assert.equal(await page.locator('.notification-footer a').getAttribute('href'), origin + '/notifications/inbox');
  assert.equal(posts(calls).length, 0, 'Opening history must not mark anything');
  assert.equal(await page.locator('[data-history-unread]').textContent(), '99+ unread');
  assert.equal(await page.locator('[data-history-status]').textContent(), '1–20 of 21 notifications');
  await page.locator('[data-history-next]').click(); await settled(page);
  assert.equal(await page.locator('[data-history-page]').textContent(), 'Page 2 of 2');
  assert.equal(await page.locator('[data-history-status]').textContent(), '21–21 of 21 notifications');
  const csrf = await page.evaluate(() => ({ header: document.querySelector('meta[name="csrf-header"]').content.toLowerCase(), token: document.querySelector('meta[name="csrf-token"]').content }));
  await page.locator('[data-history-read]').click();
  await eventually(() => calls.filter(c => c.method === 'GET').length >= 3); await settled(page);
  assert.equal(posts(calls).length, 1); assert.equal(posts(calls)[0].url.pathname, '/notifications/21/read');
  assert.equal(posts(calls)[0].headers[csrf.header], csrf.token);
  assert.equal(await page.locator('[data-history-page]').textContent(), 'Page 2 of 2');
  assert.equal(await page.locator('[data-history-read]').count(), 0);
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'rotated-history-token');
  await page.locator('[data-history-filter="unread"]').click(); await settled(page);
  assert.match(await page.locator('[data-history-list]').textContent(), /caught up/);
  assert.equal(await page.locator('[data-history-status]').textContent(), '0–0 of 0 unread notifications');
  assert.equal(await page.locator('[data-history-filter="unread"]').getAttribute('aria-pressed'), 'true');
  assert.equal(await page.locator('[data-history-mark-all]').isDisabled(), true);
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));
});
for (const mobile of [false, true]) test(`Notification rows open from their message on ${mobile ? 'mobile' : 'desktop'} without intercepting read controls`, { timeout: 30000 }, async t => {
  let read = false;
  const { page, calls } = await fixture(t, { mobile, api: (url, request) => {
    if (request.method() === 'POST') { read = true; return { body: { items: [], unread_count: 0 } }; }
    return { body: history(url, [item(1, { read_at: read ? '2026-10-24' : null }), item(2, { read_at: '2026-10-24' })], 2, read ? 0 : 1) };
  } });
  await page.route(origin + '/portal/announcements', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Opened notification</title><p>Destination opened</p>' }));
  await settled(page);
  await page.locator('[data-history-read]').click();
  await eventually(() => calls.filter(call => call.method === 'GET').length >= 2); await settled(page);
  assert.equal(posts(calls).length, 1);
  assert.equal(page.url(), origin + '/notifications/inbox', 'Mark read must not navigate');
  await page.locator('.notification-history-message').nth(1).click();
  await page.waitForURL(origin + '/portal/announcements');
  assert.equal(posts(calls).length, 1, 'Opening a notification must not send another read mutation');
  await page.goto(origin + '/notifications/inbox'); await settled(page);
  await page.locator('[data-history-destination]').first().focus();
  await page.keyboard.press('Enter');
  await page.waitForURL(origin + '/portal/announcements');
});

test('Destinations use exact allowlist, literal text, cross-host ordinary links and no read-on-navigation', { timeout: 30000 }, async t => {
  const { page, calls } = await fixture(t, { api: url => ({ body: history(url, [
    item(1, { title: '<img src=x onerror=alert(1)>', message: '<script>alert(1)</script>', url: 'javascript:alert(1)' }),
    item(2, { url: `http://clinic.notification.invalid:${new URL(origin).port}/clinic/teams` }),
    item(3, { url: `http://clinic.notification.invalid:${new URL(origin).port}/clinic/teams?spoof=1` }),
    item(4, { url: `http://user:pass@clinic.notification.invalid:${new URL(origin).port}/clinic/teams` }),
  ]) }) });
  await settled(page);
  const config = await page.locator(panel).evaluate(el => JSON.parse(el.dataset.destinations));
  const links = page.locator('[data-history-destination]');
  assert.equal(await links.nth(0).locator('.notification-history-title').textContent(), '<img src=x onerror=alert(1)>');
  assert.equal(await page.locator('[data-history-list] img, [data-history-list] script').count(), 0);
  assert.equal(await links.nth(0).getAttribute('href'), config.portal);
  assert.equal(await links.nth(1).getAttribute('href'), config.clinicTeams);
  assert.equal(await links.nth(1).getAttribute('data-navigation'), 'false');
  assert.equal(await links.nth(2).getAttribute('href'), config.portal);
  assert.equal(await links.nth(3).getAttribute('href'), config.portal);
  // A later DOM mutation must also be checked before the shell sees the click.
  const clicked = await links.nth(1).evaluate(el => {
    el.href = 'https://evil.invalid/';
    const stop = event => event.preventDefault(); document.addEventListener('click', stop, { once: true });
    el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    return el.href;
  });
  assert.equal(clicked, config.portal);
  assert.equal(posts(calls).length, 0);
});
for (const unit of ['clinic', 'guidance', 'facilities']) {
  test(`${unit} history chat mentions: exact destinations, private preview and explicit reads only`, { timeout: 30000 }, async t => {
    const host = `http://${unit === 'facilities' ? 'bmg' : unit}.notification.invalid:${new URL(origin).port}`;
    const base = `${host}/${unit}/teams/chat`;
    const target = `${base}?message=55#chat-message-55`;
    const valid = [target, `${base}?message=1#chat-message-1`, `${base}?message=9007199254740991#chat-message-9007199254740991`];
    const malicious = [
      target.replace('?message=55', '?message=55&extra=1'), target + '&extra=1', target + '#extra',
      target.replace('#chat-message-55', '#chat-message-56'), target.replace(host, 'https://attacker.invalid'),
      target.replace(host, origin), target.replace('://', '://user:pass@'), `${base}?message=55`,
      `${base}?message=0#chat-message-0`, `${base}?message=01#chat-message-01`,
      `${base}?message=9007199254740992#chat-message-9007199254740992`,
      `${base}?message=55&message=55#chat-message-55`, `${base}?message=%35%35#chat-message-55`
    ];
    let read = false;
    const { page, calls } = await fixture(t, { api: (url, request) => {
      if (request.method() === 'POST') { read = true; return { body: { items: [], unread_count: 0 } }; }
      const entries = [...valid, ...malicious].map((destination, i) => item(i + 1, {
        title: 'Team chat mention', message: '<img src=x onerror="window.historyMentionXss=1">PRIVATE-CHAT body/sender/mention',
        url: destination, read_at: read && i === 0 ? '2026-10-24' : null
      }));
      return { body: history(url, entries, entries.length, entries.length - (read ? 1 : 0)) };
    } });
    await settled(page);
    const config = await page.locator(panel).evaluate(el => JSON.parse(el.dataset.destinations));
    assert.equal(config[`${unit}TeamChat`], base);
    const links = page.locator('[data-history-destination]');
    assert.deepEqual(await links.evaluateAll(nodes => nodes.map(node => node.href)),
      [...valid, ...malicious.map(() => config.portal)]);
    assert.deepEqual(await page.locator('.notification-history-title').allTextContents(), Array(valid.length + malicious.length).fill('Team chat mention'));
    assert.deepEqual(await page.locator('.notification-history-message').allTextContents(),
      Array(valid.length + malicious.length).fill('You were mentioned in your team chat. Open the conversation to view the message.'));
    assert.doesNotMatch(await page.locator('[data-history-list]').innerHTML(), /PRIVATE-CHAT|onerror|<img/);
    assert.equal(await page.evaluate(() => window.historyMentionXss), undefined);
    assert.equal(posts(calls).length, 0, 'Opening history does not mark mentions read');
    assert.equal(await links.first().getAttribute('data-navigation'), 'false');
    await page.route(`${base}**`, route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Team chat destination</title>' }));

    await links.first().locator('.notification-history-message').click();
    await page.waitForURL(target);
    assert.equal(posts(calls).length, 0, 'Opening the unread chat mention navigates without a read mutation');
    await page.goto(origin + '/notifications/inbox'); await settled(page);
    const csrf = await page.evaluate(() => ({ header: document.querySelector('meta[name="csrf-header"]').content.toLowerCase(),
      token: document.querySelector('meta[name="csrf-token"]').content }));
    const gets = calls.filter(call => call.method === 'GET').length;
    await page.locator('[data-history-read="1"]').click();
    await eventually(() => calls.filter(call => call.method === 'GET').length > gets); await settled(page);
    assert.equal(posts(calls).length, 1);
    assert.equal(posts(calls)[0].url.pathname, '/notifications/1/read');
    assert.equal(posts(calls)[0].headers[csrf.header], csrf.token);
    assert.equal(page.url(), origin + '/notifications/inbox', 'Explicit Mark read does not navigate');
    assert.equal(await page.locator('[data-history-read="1"]').count(), 0);
    // Revalidate an injected DOM destination before allowing the shell to navigate.
    const clicked = await links.nth(valid.length).evaluate(node => {
      node.href = 'https://attacker.invalid/teams/chat?message=55#chat-message-55';
      document.addEventListener('click', event => event.preventDefault(), { once: true });
      node.querySelector('.notification-history-message').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      return node.href;
    });
    assert.equal(clicked, config.portal);
    assert.equal(posts(calls).length, 1, 'Opening a rejected destination also leaves read state unchanged');
  });
}

test('Mark-all is single-flight, uses fresh CSRF, and reconciles with history rather than bell JSON', { timeout: 30000 }, async t => {
  let release, written = false;
  const gate = new Promise(resolve => { release = resolve; });
  const { page, calls } = await fixture(t, { api: async (url, req) => {
    if (req.method() === 'POST') { await gate; written = true; return { body: { items: [], unread_count: 0 } }; }
    return { body: history(url, [item(1, { read_at: written ? 'read' : null })], 1, written ? 0 : 1) };
  } });
  await settled(page);
  await page.evaluate(() => window.SynapseCsrf.sync({ name: 'csrf_test_name', hash: 'fresh-history-token', header: 'X-CSRF-TOKEN' }));
  await page.locator('[data-history-mark-all]').click();
  await page.locator('[data-history-mark-all]').evaluate(el => { el.click(); document.querySelector('[data-history-read]').click(); });
  await eventually(() => posts(calls).length === 1);
  assert.equal(posts(calls)[0].url.pathname, '/notifications/read-all');
  assert.equal(posts(calls)[0].headers['x-csrf-token'], 'fresh-history-token');
  await emit(page); release();
  await eventually(() => calls.filter(c => c.method === 'GET').length >= 2); await settled(page);
  assert.equal(await page.locator('[data-history-destination] .notification-history-title').textContent(), 'Alert 1');
  assert.equal(await page.locator('[data-history-unread]').textContent(), '0 unread');
});
test('Unread removal clamps the last page without resetting the filter', { timeout: 30000 }, async t => {
  let written = false;
  const { page, calls } = await fixture(t, { api: (url, req) => {
    if (req.method() === 'POST') { written = true; return { body: { items: [], unread_count: 20 } }; }
    const n = Number(url.searchParams.get('page'));
    return { body: history(url, written && n === 2 ? [] : [item(n === 2 ? 21 : 1)], written ? 20 : 21) };
  } });
  await settled(page); await page.locator('[data-history-filter="unread"]').click(); await settled(page);
  await page.locator('[data-history-next]').click(); await settled(page);
  await page.locator('[data-history-read]').click();
  await eventually(async () => await page.locator('[data-history-page]').textContent() === 'Page 1 of 1'); await settled(page);
  assert.equal(calls.at(-1).url.searchParams.get('filter'), 'unread');
  assert.equal(calls.at(-1).url.searchParams.get('page'), '1');
  assert.equal(posts(calls).length, 1);
});
test('Loading, failed GET, retry, malformed payload and revoked authorization are explicit and clear private rows', { timeout: 30000 }, async t => {
  let mode = 'error';
  const { page } = await fixture(t, { api: url => mode === 'ok' ? { body: history(url) }
    : mode === 'auth' ? { status: 403, body: {} } : mode === 'invalid' ? { body: { items: [] } } : { status: 503, body: {} } });
  await settled(page);
  assert.match(await page.locator('[data-history-error]').textContent(), /temporarily unavailable/);
  assert.equal(await page.locator('[data-history-read]').count(), 0);
  mode = 'ok'; await page.locator('[data-history-retry]').click(); await settled(page);
  assert.equal(await page.locator('[data-history-read]').count(), 1);
  mode = 'invalid'; await page.locator('[data-history-refresh]').click(); await settled(page);
  assert.match(await page.locator('[data-history-error]').textContent(), /Invalid notification history/);
  mode = 'auth'; await page.locator('[data-history-retry]').click(); await settled(page);
  assert.equal(await page.locator('[data-history-list]').textContent(), '');
  assert.equal(await page.locator('[data-history-mark-all]').isDisabled(), true);
  assert.match(await page.locator('[data-history-unread]').textContent(), /Sign in again/);
});
test('Realtime bursts, visibility, adaptive polling and SPA teardown/re-entry remain page-owned', { timeout: 35000 }, async t => {
  const { page, calls } = await fixture(t); await settled(page);
  const count = () => calls.filter(c => c.method === 'GET').length;
  let before = count();
  await page.evaluate(() => { for (let i = 0; i < 10; ++i) document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail: { event: 'notifications.changed' } })); });
  await eventually(() => count() === before + 1); await settled(page);
  before = count();
  await page.evaluate(() => window.historyTest.fire(30000)); await eventually(() => count() === before + 1); await settled(page);
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:realtime-status', { detail: { status: 'ready' } })));
  await eventually(() => count() === before + 2); await settled(page);
  assert.equal(await page.evaluate(() => window.historyTest.count(60000)), 2, 'The persistent bell and page each own one poll timer');
  await page.evaluate(() => window.historyTest.visible(false)); before = count();
  await emit(page); await page.waitForTimeout(150); assert.equal(count(), before);
  await page.evaluate(() => window.historyTest.visible(true)); await eventually(() => count() === before + 1); await settled(page);
  await page.evaluate(url => window.SynapseNavigation.navigate(url), origin + '/other');
  assert.equal(await page.locator(panel).count(), 0); before = count();
  await emit(page); await page.evaluate(() => { window.historyTest.fire(30000); window.historyTest.fire(60000); });
  await page.waitForTimeout(150); assert.equal(count(), before);
  await page.evaluate(url => window.SynapseNavigation.navigate(url), origin + '/notifications/inbox');
  await settled(page); assert.equal(count(), before + 1);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide'))); before = count();
  await emit(page); await page.waitForTimeout(150); assert.equal(count(), before);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await eventually(() => count() === before + 1); await settled(page);
});
test('A timed-out POST is never retried, stale JSON cannot rotate CSRF, and GET reconciles an uncertain write', { timeout: 30000 }, async t => {
  const { page, calls } = await fixture(t); await settled(page);
  await page.evaluate(() => {
    const native = window.fetch.bind(window);
    window.fetch = async (url, options) => {
      if (options.method !== 'POST' || !String(url).includes('/notifications/')) return native(url, options);
      return { ok: true, status: 200, redirected: false, headers: new Headers(), json: () => new Promise(resolve => {
        window.releaseHistoryPost = () => resolve({ items: [], unread_count: 0, csrf: { name: 'csrf_test_name', hash: 'STALE' } });
      }) };
    };
  });
  const before = calls.length;
  await page.locator('[data-history-mark-all]').click();
  await page.waitForFunction(() => window.releaseHistoryPost);
  await page.evaluate(() => window.historyTest.fire(10000));
  await eventually(() => calls.length > before); await settled(page);
  assert.equal(await page.locator('[data-history-read]').count(), 1);
  await page.evaluate(() => window.releaseHistoryPost()); await page.waitForTimeout(150);
  assert.notEqual(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'STALE');
  assert.equal(posts(calls).length, 0, 'Synthetic timed-out POST is not retried on the network');
});
test('Stale GET parsing cannot overwrite a new filter or rotate its CSRF; in-flight invalidations do not overlap', { timeout: 30000 }, async t => {
  const { page, calls } = await fixture(t); await settled(page);
  await page.evaluate(() => {
    const native = window.fetch.bind(window); let held = false;
    window.fetch = async (url, options) => {
      if (String(url).includes('/notifications/history') && !held) {
        held = true;
        return { ok: true, status: 200, redirected: false, headers: new Headers(), json: () => new Promise(resolve => {
          window.releaseHistoryGet = () => resolve({ items: [], total: 0, page: 1, pages: 0, per_page: 20,
            filter: 'all', unread_count: 0, csrf: { name: 'csrf_test_name', hash: 'STALE-GET' } });
        }) };
      }
      return native(url, options);
    };
  });
  await page.locator('[data-history-refresh]').click(); await page.waitForFunction(() => window.releaseHistoryGet);
  await emit(page); // This must not start another concurrent GET.
  const before = calls.length; await page.waitForTimeout(150); assert.equal(calls.length, before);
  await page.locator('[data-history-filter="unread"]').click(); await settled(page);
  await page.evaluate(() => window.releaseHistoryGet()); await page.waitForTimeout(150);
  assert.equal(await page.locator('[data-history-filter="unread"]').getAttribute('aria-pressed'), 'true');
  assert.equal(await page.locator('[data-history-destination] .notification-history-title').textContent(), 'Alert 1');
  assert.notEqual(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'STALE-GET');
  assert.equal(calls.at(-1).url.searchParams.get('filter'), 'unread');
});
test('A hung GET times out without inventing data, and manual refresh recovers', { timeout: 30000 }, async t => {
  const { page } = await fixture(t); await settled(page);
  await page.evaluate(() => {
    const native = window.fetch.bind(window); let hung = false;
    window.fetch = (url, options) => {
      if (String(url).includes('/notifications/history') && !hung) { hung = true; return new Promise(() => {}); }
      return native(url, options);
    };
  });
  await page.locator('[data-history-refresh]').click();
  assert.equal(await page.locator(panel).getAttribute('aria-busy'), 'true');
  await page.evaluate(() => window.historyTest.fire(10000)); await settled(page);
  assert.match(await page.locator('[data-history-error]').textContent(), /timed out/);
  assert.equal(await page.locator('[data-history-destination] .notification-history-title').textContent(), 'Alert 1');
  await page.locator('[data-history-retry]').click(); await settled(page);
  assert.equal(await page.locator('[data-history-error]').isVisible(), false);
});
test('Failed POST does not navigate or optimistically mark rows; failed reconciliation preserves uncertainty', { timeout: 30000 }, async t => {
  let written = false;
  const { page, calls } = await fixture(t, { api: (url, req) => {
    if (req.method() === 'POST') { written = true; return { status: 500, body: {} }; }
    return written ? { status: 503, body: {} } : { body: history(url) };
  } });
  await settled(page); await page.locator('[data-history-read]').click();
  await eventually(() => calls.filter(c => c.method === 'GET').length === 2); await settled(page);
  assert.equal(posts(calls).length, 1);
  assert.equal(page.url(), origin + '/notifications/inbox');
  assert.equal(await page.locator('[data-history-read]').count(), 1);
  assert.match(await page.locator('[data-history-error]').textContent(), /Could not confirm read state/);
});
