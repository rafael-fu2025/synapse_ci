'use strict';

// VM checks need only Node. Browser checks use an in-memory HTTP fixture, never
// PHP, application sessions, or a database. Run: node --test tests/browser/auth-session.test.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const http = require('node:http');
const os = require('node:os');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const sources = Object.fromEntries(['auth-session', 'csrf', 'navigation'].map(name =>
  [name, fs.readFileSync(path.join(root, 'public/assets/js', name + '.js'), 'utf8')]));
const origin = 'https://synapse.test';
const login = origin + '/auth/login';
const reasons = ['session_expired', 'reauthentication_required', 'authentication_required'];

function harness({ href = origin + '/records', configured = '/auth/login' } = {}) {
  const metas = new Map(Object.entries({
    'synapse-login-url': configured, 'csrf-name': 'csrf_initial',
    'csrf-token': 'initial-token', 'csrf-header': 'X-CSRF-TOKEN'
  }).filter(([, content]) => content !== null).map(([name, content]) => [name, { content }]));
  const inputs = [{ name: 'csrf_initial', value: 'initial-token' }, { name: 'unrelated', value: 'kept' }];
  const listeners = new Map(), events = [], replacements = [], effects = [], order = [];
  const forbidden = name => () => { effects.push(name); throw new Error('Unexpected ' + name); };
  const location = Object.assign(new URL(href), {
    replace(url) { order.push('replace'); replacements.push(url); },
    assign: forbidden('assign'), reload: forbidden('reload')
  });
  const document = {
    querySelector(selector) { return metas.get(/^meta\[name="([^"]+)"\]$/.exec(selector)?.[1]) || null; },
    querySelectorAll(selector) { assert.equal(selector, 'input[type="hidden"]'); return inputs; },
    addEventListener(name, callback) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(callback);
    },
    dispatchEvent(event) {
      events.push(event);
      if (event.type === 'synapse:auth-required') order.push('event');
      for (const callback of listeners.get(event.type) || []) callback(event);
      return true;
    }
  };
  const fetch = forbidden('fetch');
  const storage = new Proxy({}, { get: forbidden('storage read'), set: forbidden('storage write') });
  const context = vm.createContext({
    URL, location, document, fetch, localStorage: storage, sessionStorage: storage,
    setTimeout: forbidden('timeout'), setInterval: forbidden('interval'),
    CustomEvent: class CustomEvent { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } }
  });
  context.window = context;
  const load = name => vm.runInContext(sources[name], context, { filename: name + '.js' });
  load('auth-session');
  load('csrf');
  const snapshot = () => JSON.stringify({ metas: [...metas], inputs });
  return { context, metas, inputs, events, replacements, effects, order, listeners, load, snapshot,
    fetch, auth: context.SynapseAuth, csrf: context.SynapseCsrf, document };
}

function response({ status = 401, reason = reasons[0], target = '/auth/login',
  source = origin + '/api/save', xhr = false, extra = {} } = {}) {
  const headers = new Map(Object.entries({ 'X-Synapse-Auth': reason, 'X-Synapse-Login': target, ...extra })
    .filter(([, value]) => value !== null).map(([name, value]) => [name.toLowerCase(), value]));
  const get = name => headers.get(name.toLowerCase()) ?? null;
  return xhr ? { status, responseURL: source, getResponseHeader: get } : { status, url: source, headers: { get } };
}

for (const reason of reasons) {
  for (const xhr of [false, true]) {
    test(`VM: explicit ${reason} via ${xhr ? 'XHR' : 'fetch'} replaces canonical login once`, () => {
      const h = harness();
      const before = h.snapshot();
      const denied = response({ reason, xhr, extra: { 'X-CSRF-TOKEN': 'must-not-sync', 'X-CSRF-NAME': 'changed' } });
      assert.equal(h.csrf.fromResponse(denied), true);
      assert.equal(h.auth.redirecting, true);
      assert.deepEqual(h.replacements, [login]);
      assert.deepEqual(h.order, ['event', 'replace'], 'Auth event precedes replacement');
      assert.equal(h.events.length, 1);
      assert.equal(h.events[0].type, 'synapse:auth-required');
      assert.equal(h.events[0].detail.reason, reason);
      assert.equal(h.snapshot(), before, 'Auth handling must precede CSRF rotation');
      assert.equal(h.csrf.fromResponse(response({ reason, xhr })), true, 'Duplicate denial is still handled');
      assert.equal(h.auth.handle(response({ reason: reasons[1] })), true);
      assert.equal(h.events.length, 1);
      assert.deepEqual(h.replacements, [login]);
      assert.deepEqual(h.effects, []);
    });
  }
}

const rejected = [
  ...[200, 204, 302, 400, 403, 419, 500, 502, 503, 599].map(status => [`status ${status}`, { status }]),
  ['string status', { status: '401' }],
  ['missing reason', { reason: null }], ['unknown reason', { reason: 'expired' }],
  ['case-changed reason', { reason: 'SESSION_EXPIRED' }], ['padded reason', { reason: ' session_expired ' }],
  ['missing login header', { target: null }], ['empty login header', { target: '' }],
  ['noncanonical local path', { target: '/login' }],
  ['off-origin login', { target: 'https://evil.test/auth/login' }],
  ['protocol-relative foreign login', { target: '//evil.test/auth/login' }],
  ['HTTP downgrade', { target: 'http://synapse.test/auth/login' }],
  ['different port', { target: 'https://synapse.test:444/auth/login' }],
  ['query', { target: '/auth/login?reason=session_expired' }],
  ['fragment', { target: '/auth/login#notice' }],
  ['username', { target: 'https://user@synapse.test/auth/login' }],
  ['password', { target: 'https://user:secret@synapse.test/auth/login' }],
  ['javascript URL', { target: 'javascript:alert(1)' }],
  ['data URL', { target: 'data:text/html,login' }],
  ['file URL', { target: 'file:///auth/login' }],
  ['malformed URL', { target: 'http://[' }],
  ['foreign response URL', { source: 'https://evil.test/api/save' }],
  ['malformed response URL', { source: 'http://[' }]
];
for (const [label, options] of rejected) {
  test(`VM: rejects ${label} without auth or CSRF side effects`, () => {
    for (const xhr of [false, true]) {
      const h = harness();
      const before = h.snapshot();
      assert.equal(h.auth.handle(response({ ...options, xhr })), false);
      assert.equal(h.csrf.fromResponse(response({ ...options, xhr })), undefined);
      assert.equal(h.auth.redirecting, false);
      assert.deepEqual(h.replacements, []);
      assert.deepEqual(h.events, []);
      assert.deepEqual(h.effects, []);
      assert.equal(h.snapshot(), before);
    }
  });
}

test('VM: absent responses, headers, and canonical meta never infer an expired session', () => {
  for (const denied of [null, undefined, {}, { status: 401 }, response({ reason: null, target: null })]) {
    const h = harness();
    assert.equal(h.auth.handle(denied), false);
    assert.equal(h.csrf.fromResponse(denied), undefined);
    assert.deepEqual(h.replacements, []);
    assert.deepEqual(h.events, []);
    assert.deepEqual(h.effects, []);
  }
  for (const configured of [null, '', 'http://[', '/different-login']) {
    const h = harness({ configured });
    assert.equal(h.auth.handle(response()), false);
    assert.equal(h.auth.redirecting, false);
    assert.deepEqual(h.replacements, []);
  }
});

test('VM: unsafe URLs remain rejected even when the canonical meta agrees', () => {
  for (const target of ['https://evil.test/auth/login', '/auth/login?next=/records', '/auth/login#notice',
    'https://user:password@synapse.test/auth/login', 'javascript:alert(1)']) {
    const h = harness({ configured: target });
    assert.equal(h.auth.handle(response({ target })), false, target);
    assert.deepEqual(h.replacements, []);
    assert.deepEqual(h.events, []);
  }
});

test('VM: URL-equivalent canonical paths are accepted without inventing a query or fragment', () => {
  const h = harness({ configured: login });
  assert.equal(h.auth.handle(response({ target: '/area/../auth/login', source: '/api/save' })), true);
  assert.deepEqual(h.replacements, [login]);
});

test('VM: already at login does not loop, even with an existing query or fragment', () => {
  for (const href of [login, login + '?existing=1#notice']) {
    const h = harness({ href });
    assert.equal(h.csrf.fromResponse(response()), undefined);
    assert.equal(h.auth.handle(response()), false);
    assert.equal(h.auth.redirecting, false);
    assert.deepEqual(h.replacements, []);
    assert.deepEqual(h.events, []);
  }
});

test('VM: HTMX afterRequest uses the auth-first CSRF owner and registration is idempotent', () => {
  const h = harness();
  const owner = h.auth, csrf = h.csrf;
  h.load('auth-session');
  h.load('csrf');
  assert.equal(h.context.SynapseAuth, owner);
  assert.equal(h.context.SynapseCsrf, csrf);
  assert.equal(h.listeners.get('htmx:afterRequest').length, 1);
  const before = h.snapshot();
  for (const reason of reasons) {
    h.document.dispatchEvent(new h.context.CustomEvent('htmx:afterRequest', {
      detail: { xhr: response({ reason, xhr: true, extra: { 'X-CSRF-TOKEN': 'denied-token' } }) }
    }));
  }
  assert.equal(h.snapshot(), before);
  assert.equal(h.events.filter(event => event.type === 'synapse:auth-required').length, 1);
  assert.deepEqual(h.replacements, [login]);
  assert.deepEqual(h.effects, []);
});

test('VM: stale success cannot reset auth state, redirect again, or infer a timeout', () => {
  const h = harness({ href: origin + '/records?session_expired=1#reauthentication_required' });
  const before = h.snapshot();
  const success = response({ status: 200 });
  assert.equal(h.auth.handle(success), false);
  assert.deepEqual(h.events, []);
  assert.equal(h.csrf.fromResponse(response()), true);
  assert.equal(h.csrf.fromResponse(success), undefined);
  assert.equal(h.auth.handle(success), false);
  assert.equal(h.auth.redirecting, true);
  assert.equal(h.snapshot(), before);
  assert.deepEqual(h.replacements, [login]);
  assert.equal(h.events.length, 1);
  assert.equal(h.context.fetch, h.fetch, 'Production must not wrap fetch');
  assert.deepEqual(h.effects, [], 'No timer, storage, replay, or competing navigation');
});

test('VM: ordinary successful responses still rotate CSRF without invoking auth navigation', () => {
  const h = harness();
  assert.equal(h.csrf.fromResponse(response({ status: 200, extra: {
    'X-CSRF-NAME': 'csrf_rotated', 'X-CSRF-TOKEN': 'rotated', 'X-CSRF-HEADER': 'X-Rotated-CSRF'
  } })), undefined);
  assert.equal(h.metas.get('csrf-token').content, 'rotated');
  assert.equal(h.inputs[0].name, 'csrf_rotated');
  assert.equal(h.inputs[0].value, 'rotated');
  assert.deepEqual(h.inputs[1], { name: 'unrelated', value: 'kept' });
  assert.deepEqual({ ...h.csrf.headers() }, { 'X-Rotated-CSRF': 'rotated' });
  assert.deepEqual(h.events, []);
  assert.deepEqual(h.replacements, []);
});

test('app layout declares the canonical login and loads auth before CSRF', () => {
  const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/app.php'), 'utf8');
  assert.match(layout, /<meta name="synapse-login-url" content="[^\n]*site_url\('auth\/login'\)/);
  const auth = layout.indexOf("base_url('assets/js/auth-session.js')");
  const csrf = layout.indexOf("base_url('assets/js/csrf.js')");
  assert.ok(auth >= 0 && csrf > auth);
});

test('Chromium: real HTTP auth denials navigate to a canonical login document without POST replay', { timeout: 60000 }, async t => {
  // Loading Playwright inside this test keeps the VM suite dependency-free.
  const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
    assert.ok(executablePath, 'A Playwright Chromium installation is required');
  }
  const requests = [];
  let fixtureOrigin;
  const html = `<!doctype html><html><head><title>Protected fixture</title><link rel="icon" href="data:,">
    <meta name="synapse-login-url" content="/auth/login"><meta name="csrf-name" content="fixture_csrf">
    <meta name="csrf-token" content="initial"><meta name="csrf-header" content="X-CSRF-TOKEN"></head>
    <body data-navigation-scope="auth-fixture"><div id="page-styles"></div>
    <nav class="sidebar-nav"><a class="nav-link" href="/protected">Protected</a></nav>
    <main id="main"><h1>Protected fixture</h1><input type="hidden" name="fixture_csrf" value="initial"></main>
    <script>window.originalFetch = window.fetch; window.authEvents = [];
    document.addEventListener('synapse:auth-required', event => window.authEvents.push(event.detail.reason));</script>
    <script src="/auth-session.js"></script><script src="/csrf.js"></script><script src="/navigation.js"></script>
    <div id="page-scripts"></div></body></html>`;
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://fixture.test');
    const record = { method: req.method, url: req.url, headers: req.headers, body: '' };
    requests.push(record);
    for await (const chunk of req) record.body += chunk;
    const asset = /^\/(auth-session|csrf|navigation)\.js$/.exec(url.pathname)?.[1];
    if (asset) return res.writeHead(200, { 'Content-Type': 'text/javascript' }).end(sources[asset]);
    if (url.pathname === '/auth/login') {
      return res.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><title>Canonical login</title><link rel="icon" href="data:,"><h1 id="login">Sign in again</h1>');
    }
    if (['/api/denied', '/api/forbidden', '/next'].includes(url.pathname)) {
      const status = url.pathname === '/api/forbidden' ? 403 : 401;
      return res.writeHead(status, {
        'Content-Type': 'application/json', 'X-Synapse-Auth': url.searchParams.get('reason') || 'session_expired',
        'X-Synapse-Login': fixtureOrigin + '/auth/login', 'X-CSRF-TOKEN': 'must-not-sync'
      }).end('{"message":"Denied"}');
    }
    if (url.pathname === '/protected') return res.writeHead(200, { 'Content-Type': 'text/html' }).end(html);
    res.writeHead(404).end();
  });
  t.after(async () => {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  fixtureOrigin = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
  t.after(() => browser.close());

  async function pageFor(t) {
    const context = await browser.newContext();
    t.after(() => context.close());
    const page = await context.newPage();
    page.setDefaultTimeout(5000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    t.after(() => assert.deepEqual(errors, [], 'No page script errors'));
    await page.goto(fixtureOrigin + '/protected');
    await page.waitForFunction(() => Boolean(window.SynapseNavigation && history.state?.__synapseNavigation));
    return page;
  }

  for (const reason of reasons) {
    await t.test(`fetch ${reason} replaces the full document and does not replay POST`, async t => {
      const page = await pageFor(t);
      const start = requests.length;
      const documents = [];
      page.on('request', request => { if (request.isNavigationRequest()) documents.push(request.url()); });
      await page.evaluate(async reason => {
        const response = await fetch('/api/denied?reason=' + reason, { method: 'POST', body: 'intent=save&value=kept' });
        const handled = SynapseCsrf.fromResponse(response);
        if (!handled || authEvents.length !== 1 || authEvents[0] !== reason) throw new Error('Auth was not handled exactly once');
        if (document.querySelector('meta[name="csrf-token"]').content !== 'initial') throw new Error('Denied CSRF was synchronized');
      }, reason);
      await page.waitForURL(fixtureOrigin + '/auth/login');
      assert.equal(await page.locator('#login').textContent(), 'Sign in again');
      assert.deepEqual(documents, [fixtureOrigin + '/auth/login']);
      assert.deepEqual(requests.slice(start).filter(r => !r.url.endsWith('.js')).map(r => [r.method, r.url, r.body]), [
        ['POST', '/api/denied?reason=' + reason, 'intent=save&value=kept'], ['GET', '/auth/login', '']
      ]);
      await page.goBack();
      assert.notEqual(page.url(), fixtureOrigin + '/protected', 'replace must remove the protected history entry');
    });
  }

  await t.test('403 is not auth expiry and a later success has no auth side effects', async t => {
    const page = await pageFor(t);
    const start = requests.length;
    const result = await page.evaluate(async () => {
      const response = await fetch('/api/forbidden', { method: 'POST', body: 'intent=forbidden' });
      const handled = SynapseCsrf.fromResponse(response);
      const success = SynapseAuth.handle(new Response('', { status: 200, headers: {
        'X-Synapse-Auth': 'session_expired', 'X-Synapse-Login': '/auth/login'
      } }));
      return { handled: handled === true, success, redirecting: SynapseAuth.redirecting, events: authEvents,
        sameFetch: fetch === originalFetch, local: localStorage.length, session: sessionStorage.length };
    });
    assert.deepEqual(result, { handled: false, success: false, redirecting: false, events: [], sameFetch: true, local: 0, session: 0 });
    assert.equal(page.url(), fixtureOrigin + '/protected');
    assert.deepEqual(requests.slice(start).map(r => [r.method, r.url]), [['POST', '/api/forbidden']]);
  });

  await t.test('navigation GET carries its marker and yields to the single auth replacement', async t => {
    const page = await pageFor(t);
    const start = requests.length;
    const documents = [];
    page.on('request', request => { if (request.isNavigationRequest()) documents.push(request.url()); });
    assert.equal(await page.evaluate(() => SynapseNavigation.navigate('/next')), false);
    await page.waitForURL(fixtureOrigin + '/auth/login');
    assert.equal(await page.title(), 'Canonical login');
    assert.deepEqual(documents, [fixtureOrigin + '/auth/login'], 'No competing full navigation to the denied resource');
    const traffic = requests.slice(start);
    assert.deepEqual(traffic.map(r => [r.method, r.url]), [['GET', '/next'], ['GET', '/auth/login']]);
    assert.equal(traffic[0].headers['x-synapse-navigation'], '1');
    assert.equal(traffic[0].headers['hx-request'], undefined);
  });
});
