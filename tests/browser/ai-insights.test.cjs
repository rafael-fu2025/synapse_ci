'use strict';

// No listening socket or application boot: Chromium routes fulfill every request.
// PHP includes only the real presentation views with synthetic aggregate inputs.
// Run: node --test tests/browser/ai-insights.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const origin = 'http://127.0.0.1:47991'; // Intercepted virtual origin; nothing binds this port.
const base = '/clinic/insights';
const sources = Object.fromEntries(['ai-insights', 'csrf', 'auth-session', 'toast'].map(name =>
  [`/assets/js/${name}.js`, fs.readFileSync(path.join(root, `public/assets/js/${name}.js`), 'utf8')]));
sources['/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'] = fs.readFileSync(path.join(root, 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), 'utf8');
const fixtures = new Map();
let browser;

function render(options = {}) {
  const key = JSON.stringify(options);
  if (!fixtures.has(key)) {
    const result = spawnSync('php', ['tests/_support/render-ai-fixture.php'], {
      cwd: root, input: key, encoding: 'utf8', timeout: 10000, maxBuffer: 2 * 1024 * 1024,
    });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(result.stderr, '', 'PHP fixture must not emit warnings');
    fixtures.set(key, JSON.parse(result.stdout));
  }
  return fixtures.get(key);
}
async function until(predicate, message, timeout = 8000) {
  const deadline = performance.now() + timeout;
  do {
    if (await predicate()) return;
    await delay(20);
  } while (performance.now() < deadline);
  assert.fail(message);
}
function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}
const json = (payload, status = 200, headers = {}) => ({
  status, contentType: 'application/json', headers, body: JSON.stringify(payload),
});
const ok = (extra = {}) => json({ success: true, ...extra });

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath && fs.existsSync(executablePath), 'A locally installed Chromium is required');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => { await browser?.close(); });

async function open(t, options = {}) {
  const initial = render(options);
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  page.setDefaultNavigationTimeout(10000);
  const h = {
    page, context, requests: [], documents: [], unexpected: [], errors: [], gates: [], authEvents: [],
    panel: initial.panel,
    respond: request => request.method === 'GET' ? ok({ html: h.panel }) : ok({ message: 'Synthetic action saved.' }),
    hold() { const gate = deferred(); h.gates.push(gate); return gate; },
    posts() { return h.requests.filter(request => request.method === 'POST'); },
    gets() { return h.requests.filter(request => request.method === 'GET'); },
  };
  page.on('pageerror', error => h.errors.push(error.message));
  await page.exposeFunction('fixtureRecordAuth', event => { h.authEvents.push(event); });
  t.after(async () => {
    h.gates.forEach(gate => gate.resolve());
    await context.close();
    assert.deepEqual(h.unexpected, [], 'No unmocked, provider, or other outbound requests are allowed');
    assert.deepEqual(h.errors, [], 'No uncaught browser or route errors');
  });
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) {
      h.unexpected.push(request.url());
      return route.abort('blockedbyclient');
    }
    if (request.isNavigationRequest()) {
      h.documents.push({ path: url.pathname, method: request.method() });
      if (url.pathname === base && request.method() === 'GET') {
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head>
          <meta charset="utf-8"><meta name="csrf-name" content="csrf_fixture"><meta name="csrf-token" content="initial-token">
          <meta name="csrf-header" content="X-CSRF-TOKEN"><meta name="synapse-login-url" content="/auth/login">
          <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
                    ${options.withoutToast ? '' : '<script src="/assets/js/toast.js"></script>'}
                    <script src="/assets/js/auth-session.js"></script><script src="/assets/js/csrf.js"></script>
          </head><body><aside id="shell">Unrelated workspace <input value="keep me"></aside>
          ${initial.index}</body></html>` });
      }
      if (url.pathname === '/auth/login') return route.fulfill({ contentType: 'text/html', body: '<h1>Synthetic login</h1>' });
      h.unexpected.push(`${request.method()} navigation ${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    if (sources[url.pathname] && request.method() === 'GET') {
      return route.fulfill({ contentType: 'text/javascript', body: sources[url.pathname] });
    }
    if (/^\/clinic\/insights\/(?:panel|prepare|generate|47\/review|jobs\/31\/cancel)$/.test(url.pathname)) {
      const entry = { path: url.pathname, method: request.method(), headers: request.headers(), body: request.postData() };
      h.requests.push(entry);
      try {
        const reply = await h.respond(entry);
        if (reply === 'network-error') return await route.abort('failed');
        await route.fulfill(reply);
      } catch (error) {
        // A before-swap abort or context teardown may dispose a held route.
        if (!context.pages().length || /closed|disposed|Invalid InterceptionId/i.test(error.message)) return;
        h.errors.push(error.message);
      }
      return;
    }
    h.unexpected.push(`${request.method()} ${request.url()}`);
    return route.abort('blockedbyclient');
  });
  // A fresh clock per test: never shrink production delays or share timer state.
  await page.clock.install({ time: new Date('2026-10-01T00:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-01T00:00:01Z'));
  await page.addInitScript(() => {
    window.fixtureCalls = [];
    window.fixtureAuthEvents = [];
        window.fixtureNotices = [];
    document.addEventListener('synapse:auth-required', event => {
      const observed = { reason: event.detail.reason,
        token: document.querySelector('meta[name="csrf-token"]').content,
        notices: window.fixtureNotices.length }; 
      window.fixtureAuthEvents.push(observed);
      void window.fixtureRecordAuth(observed);
    });
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = async (url, options = {}) => {
      const call = { url: String(url), method: options.method, credentials: options.credentials,
        cache: options.cache, redirect: options.redirect, aborted: false, finished: false };
      window.fixtureCalls.push(call);
      options.signal?.addEventListener('abort', () => { call.aborted = true; }, { once: true });
      try { return await fetchOriginal(url, options); }
      finally { call.finished = true; }
    };
  });
  await page.goto(origin + base);
  await page.waitForFunction(() => window.SynapseAiInsights && window.SynapseCsrf && window.SynapseAuth);
  await page.evaluate(() => {
    if (window.SynapseToast) {
          const show = window.SynapseToast.show;
          window.SynapseToast.show = (message, options) => {
            window.fixtureNotices.push({ message, ...options });
            return show(message, options);
          };
        }
        window.fixtureShell = document.querySelector('#shell');
    window.fixtureWorkspace = document.querySelector('[data-ai-workspace]');
  });
  return h;
}
async function notice(h, type, message) {
  // Toast text is announced on requestAnimationFrame; advance the controlled clock, not production timers.
  await h.page.clock.runFor(50);
  const toast = h.page.locator('#synapse-toast-container .synapse-toast');
  assert.equal(await toast.count(), 1, 'One keyed shared notification, not duplicate notices');
  assert.equal(await toast.isVisible(), true);
  assert.equal(await toast.getAttribute('data-type'), type);
    assert.equal(await h.page.evaluate(() => window.fixtureNotices.at(-1).key), 'ai-insights');
  assert.match(await toast.locator('.synapse-toast-message').innerText(), message);
  assert.equal(await toast.locator('[aria-live]').getAttribute('role'), ['warning', 'error'].includes(type) ? 'alert' : 'status');
  assert.equal(await h.page.locator('[data-ai-feedback]').isVisible(), false, 'Retained feedback must not duplicate the toast');
  assert.match(await h.page.locator('[data-ai-feedback]').textContent(), message);
}
async function idle(h) {
  await h.page.waitForFunction(() => !document.querySelector('[data-ai-workspace]')?.hasAttribute('aria-busy') &&
    window.fixtureCalls.every(call => call.finished));
}
async function submit(h, suffix, count = h.posts().length + 1) {
  await h.page.locator(`form[action$="${suffix}"] button`).click();
  await until(() => h.posts().length >= count, `Expected POST ${suffix}`);
  await idle(h);
}
async function refresh(h, count = h.gets().length + 1) {
  await h.page.locator('[data-ai-refresh]').click();
  await until(() => h.gets().length >= count, 'Expected status GET');
  await idle(h);
}
async function samePage(h) {
  assert.equal(h.page.url(), origin + base);
  assert.deepEqual(h.documents, [{ path: base, method: 'GET' }], 'No full reload, form navigation, or login');
  assert.equal(await h.page.evaluate(() => window.SynapseAuth.redirecting), false);
  assert.equal(await h.page.evaluate(() => window.fixtureAuthEvents.length), 0);
  assert.equal(await h.page.evaluate(() => window.fixtureShell === document.querySelector('#shell') &&
    window.fixtureWorkspace === document.querySelector('[data-ai-workspace]')), true, 'Preserve scoped DOM owners');
  assert.equal(await h.page.locator('#shell input').inputValue(), 'keep me');
}
async function visibility(h, hidden) {
  // Explicitly model the browser visibility signal; foregrounding headless tabs
  // is OS-dependent and would couple otherwise independent tests.
  await h.page.evaluate(value => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => value });
    document.dispatchEvent(new Event('visibilitychange'));
  }, hidden);
}
async function beforeSwap(h) {
  await h.page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', {
    detail: { root: document.querySelector('[data-ai-workspace]') },
  })));
}

async function hint(h, unit = 'clinic', count = 1) {
  await h.page.evaluate(({ unit, count }) => {
    for (let i = 0; i < count; i++) document.dispatchEvent(new CustomEvent('synapse:realtime-event', {
      detail: { event: 'ai.insights.changed', data: { unit, draft: '<img src=x onerror=alert(1)>', token: 'DO NOT RENDER' } },
    }));
  }, { unit, count });
}

test('realtime hints refetch only the current department and never render event content', { timeout: 20000 }, async t => {
  const h = await open(t);
  await hint(h, 'guidance');
  await h.page.clock.runFor(300);
  assert.equal(h.gets().length, 0);
  await hint(h, 'clinic', 20);
  await h.page.clock.runFor(251);
  await until(() => h.gets().length === 1, 'A burst produces one authorized panel read');
  await idle(h);
  assert.equal(h.posts().length, 0);
  assert.doesNotMatch(await h.page.locator('[data-ai-panel]').innerText(), /DO NOT RENDER/);
  assert.equal(await h.page.locator('[data-ai-panel] img').count(), 0);
    assert.equal(await h.page.locator('.synapse-toast').count(), 0, 'Realtime reconciliation must not notify');
      assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 0);
});

test('persisted progress rerenders queued to running to ready on realtime hints', { timeout: 20000 }, async t => {
  const h = await open(t, { jobStatus: 'queued' });
  const request = h.page.locator('[data-ai-request="31"]');
  assert.equal(await request.getAttribute('data-ai-progress'), 'queued');
  assert.match(await request.innerText(), /Waiting for a worker; processing has not started/);
  assert.equal(await request.locator('[data-ai-progress-spinner]').count(), 0);
  for (const [status, stage] of [['running', 'generating'], ['succeeded', 'ready']]) {
    h.panel = render({ jobStatus: status, insight: status === 'succeeded' }).panel;
    const reads = h.gets().length;
    await hint(h);
    await h.page.clock.runFor(251);
    await until(() => h.gets().length === reads + 1, 'Realtime hint refetches persisted progress');
    await idle(h);
    assert.equal(await request.getAttribute('data-ai-progress'), stage);
    assert.equal(await request.locator('[data-ai-progress-spinner]').count(), status === 'running' ? 1 : 0);
    assert.equal(await request.locator('[aria-current="step"]').getAttribute('data-ai-stage'), stage);
    assert.equal(await request.locator('form').count(), 0, 'Only queued requests can be cancelled');
    assert.equal(await h.page.locator('[data-ai-panel] article').count(), status === 'succeeded' ? 1 : 0);
  }
  assert.match(await request.innerText(), /Ready for review/);
  assert.equal(await request.locator('[aria-live], [role="status"], [aria-valuenow]').count(), 0);
  assert.doesNotMatch(await h.page.locator('[data-ai-panel]').innerText(), /DO NOT RENDER/);
  assert.equal(await h.page.locator('[data-ai-panel] img').count(), 0);
  assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 0);
  assert.equal(h.posts().length, 0);
  await samePage(h);
});

test('hints during an in-flight read trigger exactly one follow-up reconciliation', { timeout: 20000 }, async t => {
  const h = await open(t);
  const gate = h.hold();
  let reads = 0;
  h.respond = async () => { if (++reads === 1) await gate.promise; return ok({ html: h.panel }); };
  await hint(h);
  await h.page.clock.runFor(251);
  await until(() => h.gets().length === 1, 'First read');
  await hint(h, 'clinic', 20);
  await h.page.clock.runFor(500);
  assert.equal(h.gets().length, 1);
  gate.resolve(); await idle(h);
  await h.page.clock.runFor(251);
  await until(() => h.gets().length === 2, 'Dirty read reconciles once');
  await idle(h);
  assert.equal(h.posts().length, 0);
});

test('hints during a mutation wait for its status read without replaying the write', { timeout: 20000 }, async t => {
  const h = await open(t);
  const gate = h.hold();
  h.respond = async request => {
    if (request.method === 'POST') await gate.promise;
    return ok({ html: h.panel });
  };
  await h.page.locator('form[action$="/generate"] button').click();
  await until(() => h.posts().length === 1, 'Mutation started');
  await hint(h, 'clinic', 20);
  await h.page.clock.runFor(500);
  assert.equal(h.gets().length, 0);
  gate.resolve(); await idle(h);
  await until(() => h.gets().length === 1, 'Mutation completion reconciles status');
  await h.page.clock.runFor(500);
  assert.equal(h.posts().length, 1);
  assert.equal(h.gets().length, 1);
});

test('reconnect and page resume reconcile; hidden and swapped panels do not fetch', { timeout: 20000 }, async t => {
  const h = await open(t);
  await h.page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:realtime-status', { detail: { status: 'ready' } })));
  await h.page.clock.runFor(251);
  await until(() => h.gets().length === 1, 'Reconnect reconciles'); await idle(h);
  await visibility(h, true);
  await hint(h);
  await h.page.clock.runFor(60001);
  assert.equal(h.gets().length, 1);
  await visibility(h, false);
  await until(() => h.gets().length === 2, 'Visible reconciles'); await idle(h);
  await h.page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  await hint(h);
  await h.page.clock.runFor(60001);
  assert.equal(h.gets().length, 2);
  await h.page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
  await until(() => h.gets().length === 3, 'Page resume reconciles'); await idle(h);
  await beforeSwap(h); await hint(h);
  await h.page.clock.runFor(60001);
  assert.equal(h.gets().length, 3);
});

test('GET and explicit status refresh never prepare or dispatch AI automatically', { timeout: 20000 }, async t => {
  const h = await open(t);
  assert.deepEqual(h.requests, []);
  await h.page.clock.runFor(30001);
  await until(() => h.gets().length === 1, 'Idle panels reconcile missed realtime hints');
  await idle(h);
  await refresh(h, 2);
  assert.equal(await h.page.locator('.synapse-toast').count(), 0, 'Polling and manual status reads must not notify');
    assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 0);
  assert.deepEqual(h.requests.map(r => [r.method, r.path]), [['GET', base + '/panel'], ['GET', base + '/panel']]);
  const call = await h.page.evaluate(() => window.fixtureCalls[0]);
  assert.deepEqual([call.credentials, call.cache, call.redirect], ['same-origin', 'no-store', 'error']);
  await samePage(h);
});

for (const action of ['prepare', 'generate']) {
  test(`${action}: insufficient data is an actionable confirmed warning, not an uncertain connection failure`, { timeout: 20000 }, async t => {
    const h = await open(t);
    const message = 'No comparison is available yet. Both completed months need enough different people with completed clinic visits and valid linked records to meet the privacy rules. Check that eligible visits are recorded as completed. Hidden or unavailable values are not zero. This is not an AI connection error.';
    h.respond = request => request.method === 'POST'
      ? json({ success: false, reason: 'insufficient_data', feedback_type: 'warning', message }, 422)
      : ok({ html: h.panel });
    await submit(h, '/' + action);
    await notice(h, 'warning', /Check that eligible visits are recorded as completed.*not an AI connection error/);
    assert.equal(await h.page.locator('.synapse-toast-message').textContent(), message);
    assert.doesNotMatch(await h.page.locator('.synapse-toast-message').textContent(), /could not confirm|automatic retry|Refresh status/);
    await h.page.clock.runFor(60000);
    await idle(h);
    await notice(h, 'warning', /No comparison is available yet/);
    assert.equal(await h.page.locator('.synapse-toast-countdown').isVisible(), false);
    assert.equal(h.posts().length, 1);
    assert.ok(h.gets().length >= 1, 'Only status reconciliation follows a confirmed rejection');
    await samePage(h);
  });
}

for (const outcome of ['success', 'warning', 'error']) {
  test(`missing toast service uses styled ${outcome} feedback`, { timeout: 20000 }, async t => {
    const h = await open(t, { withoutToast: true });
    h.respond = request => request.method === 'GET' ? ok({ html: h.panel })
      : outcome === 'success' ? ok({ message: 'Local summary ready.', baseline: render().baseline })
      : json({ success: false, reason: outcome === 'warning' ? 'insufficient_data' : 'store_unavailable', message: 'Check the eligible records or setup.' }, outcome === 'warning' ? 422 : 503);
    await submit(h, '/prepare');
    const feedback = h.page.locator('[data-ai-feedback]');
    assert.equal(await feedback.isVisible(), true);
    assert.match(await feedback.getAttribute('class'), new RegExp('alert alert-' + (outcome === 'error' ? 'danger' : outcome)));
    assert.match(await feedback.innerText(), outcome === 'success' ? /Local summary ready/ : /Check the eligible records or setup/);
    assert.equal(await h.page.locator('.synapse-toast').count(), 0);
    await samePage(h);
  });
}

test('prepare local summary replaces only the baseline and refreshes status', { timeout: 20000 }, async t => {
  const h = await open(t, { reason: 'disabled' });
  assert.equal(await h.page.getByRole('button', { name: 'Request AI draft', exact: true }).isDisabled(), true);
  const baseline = render().baseline;
  h.respond = request => request.method === 'POST' ? ok({ baseline, message: 'Protected local summary prepared.' }) : ok({ html: h.panel });
  await submit(h, '/prepare');
  assert.deepEqual(h.requests.map(r => [r.method, r.path]), [['POST', base + '/prepare'], ['GET', base + '/panel']]);
  assert.match(await h.page.locator('[data-ai-baseline]').innerText(), /Current complete month/);
  assert.equal(await h.page.locator('[data-ai-baseline]').isVisible(), true);
    await notice(h, 'success', /^Protected local summary prepared\.$/);
  assert.equal(new URLSearchParams(h.posts()[0].body).get('csrf_fixture'), 'initial-token');
  await samePage(h);
});

test('queue 202 then GET status, with no duplicate submit while the write is unresolved', { timeout: 20000 }, async t => {
  const h = await open(t);
  const gate = h.hold();
  h.panel = render({ jobStatus: 'queued' }).panel;
  h.respond = async request => {
    if (request.method === 'GET') return ok({ html: h.panel });
    await gate.promise;
    return json({ success: true, message: 'Analysis queued.' }, 202);
  };
  await h.page.locator('form[action$="/generate"] button').click();
  await until(() => h.posts().length === 1, 'Queue write started');
  assert.equal(await h.page.locator('[data-ai-workspace]').getAttribute('aria-busy'), 'true');
  assert.equal(await h.page.locator('[data-ai-workspace] button:enabled').count(), 0);
    assert.equal(await h.page.locator('[data-ai-feedback]').isVisible(), true);
    assert.match(await h.page.locator('[data-ai-feedback]').getAttribute('class'), /alert alert-info/);
    assert.match(await h.page.locator('[data-ai-feedback]').innerText(), /requesting an AI draft/);
    assert.equal(await h.page.locator('.synapse-toast').count(), 0, 'Progress is an inline styled status, not a final notification');
  await h.page.evaluate(() => {
    for (let i = 0; i < 4; i++) document.querySelector('form[action$="/generate"]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  await h.page.clock.runFor(5000);
  assert.equal(h.posts().length, 1);
  assert.equal(h.gets().length, 0, 'Do not poll while writing');
  gate.resolve();
  await idle(h);
  await until(() => h.gets().length === 1, 'Queue completion must read status');
  await h.page.locator('[data-ai-pending="true"]').waitFor();
  assert.equal(await h.page.getByRole('button', { name: 'Cancel waiting request' }).count(), 1);
    await notice(h, 'info', /^Analysis queued\.$/);
  await h.page.clock.runFor(5000);
  await until(() => h.gets().length === 2, 'Pending job should poll');
  await idle(h);
  assert.equal(h.posts().length, 1, 'Status GET must never redispatch the job');
    assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 1, 'Pending polls must not repeatedly update the keyed toast');
  await samePage(h);
});

test('real CSRF owner sends fresh form/header values and syncs response headers and JSON', { timeout: 20000 }, async t => {
  const h = await open(t);
  h.respond = () => json({ success: true, html: h.panel }, 200,
    { 'X-CSRF-NAME': 'csrf_fixture', 'X-CSRF-TOKEN': 'header-token' });
  await refresh(h);
  assert.equal(await h.page.locator('meta[name="csrf-token"]').getAttribute('content'), 'header-token');
  assert.deepEqual(await h.page.evaluate(() => window.SynapseCsrf.headers()), { 'X-CSRF-TOKEN': 'header-token' });
  h.respond = request => request.method === 'POST'
    ? json({ success: true, csrf: { name: 'csrf_rotated', hash: 'payload-token', header: 'X-CSRF-ROTATED' } }, 200,
      { 'X-CSRF-NAME': 'csrf_fixture', 'X-CSRF-TOKEN': 'header-token' })
    : ok({ html: h.panel });
  await submit(h, '/prepare');
  assert.equal(h.posts()[0].headers['x-csrf-token'], 'header-token');
  assert.equal(new URLSearchParams(h.posts()[0].body).get('csrf_fixture'), 'header-token');
  assert.equal(h.posts()[0].headers.accept, 'application/json');
  assert.equal(h.posts()[0].headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(await h.page.locator('meta[name="csrf-token"]').getAttribute('content'), 'payload-token');
  assert.equal(await h.page.locator('meta[name="csrf-name"]').getAttribute('content'), 'csrf_rotated');
  h.respond = request => request.method === 'POST' ? json({ success: false, message: 'Validation fixture.',
    csrf: { name: 'csrf_rotated', hash: 'error-token' } }, 422) : ok({ html: h.panel });
  // Deliberately stale fields from the new fragment must not override the owner.
  await h.page.locator('form[action$="/prepare"] input').evaluate(input => { input.value = 'stale-fragment'; });
  await submit(h, '/prepare');
  const second = h.posts()[1];
  assert.equal(second.headers['x-csrf-rotated'], 'payload-token');
  assert.equal(new URLSearchParams(second.body).get('csrf_rotated'), 'payload-token');
  assert.equal(await h.page.locator('meta[name="csrf-token"]').getAttribute('content'), 'error-token');
  assert.equal(h.posts().length, 2);
  await samePage(h);
});

test('explicit auth denial delegates once to shared auth owner before token rotation', { timeout: 20000 }, async t => {
  const h = await open(t);
  h.respond = () => json({ success: false, message: 'Authentication required.' }, 401, {
    'X-Synapse-Auth': 'session_expired', 'X-Synapse-Login': '/auth/login',
    'X-CSRF-TOKEN': 'must-not-sync', 'X-CSRF-NAME': 'csrf_fixture',
  });
  await h.page.locator('form[action$="/generate"] button').click();
  await h.page.waitForURL(origin + '/auth/login');
  assert.deepEqual(h.documents, [{ path: base, method: 'GET' }, { path: '/auth/login', method: 'GET' }]);
  assert.equal(h.posts().length, 1);
  assert.equal(h.gets().length, 0, 'Auth must stop the completion refresh');
  await until(() => h.authEvents.length === 1, 'Shared auth event must precede login navigation');
  assert.deepEqual(h.authEvents, [{ reason: 'session_expired', token: 'initial-token', notices: 0 }]);
});

for (const status of [401, 403]) {
  test(`unmarked ${status} clears private output, stops actions/polling, and does not infer login`, { timeout: 20000 }, async t => {
    const h = await open(t, { insight: true, jobStatus: 'queued' });
    await h.page.locator('[data-ai-baseline]').evaluate(el => { el.textContent = 'Synthetic protected baseline'; });
    h.respond = () => json({ success: false, message: 'Access changed.' }, status);
    await submit(h, '/generate');
    assert.equal(await h.page.locator('[data-ai-panel]').innerHTML(), '');
    assert.equal(await h.page.locator('[data-ai-baseline]').innerHTML(), '');
    await h.page.clock.runFor(30000);
    await h.page.evaluate(() => document.querySelector('form[action$="/prepare"]')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(h.posts().length, 1);
    assert.equal(h.gets().length, 0);
    assert.equal(await h.page.locator('.synapse-toast').count(), 0, 'Auth handling must not add an AI notification');
        assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 0);
    await samePage(h);
  });
}

test('provider unavailable (503) stays on the page even with misleading auth headers', { timeout: 20000 }, async t => {
  const h = await open(t);
  h.respond = request => request.method === 'POST'
    ? json({ success: false, message: 'Provider unavailable; local summaries remain available.' }, 503,
      { 'X-Synapse-Auth': 'session_expired', 'X-Synapse-Login': '/auth/login' })
    : ok({ html: h.panel });
  await submit(h, '/generate');
  await notice(h, 'error', /Provider unavailable/);
    assert.doesNotMatch(await h.page.locator('.synapse-toast-message').textContent(), /could not confirm|automatic retry|Refresh status/);
  await h.page.clock.runFor(30001);
  await until(() => h.gets().length === 2, 'Read-only fallback reconciliation');
  await idle(h);
  assert.equal(h.posts().length, 1);
  assert.equal(h.gets().length, 2, 'Immediate status and bounded reconciliation reads, never a dispatch retry');
  await samePage(h);
});

for (const failure of ['network', 'timeout']) {
  test(`${failure} during POST never replays mutation, reloads, or redirects`, { timeout: 20000 }, async t => {
    const h = await open(t);
    const gate = h.hold();
    h.respond = async request => {
      if (request.method === 'GET') return ok({ html: h.panel });
      if (failure === 'network') return 'network-error';
      await gate.promise;
      return ok({ baseline: '<p>Late response must not apply</p>' });
    };
    await h.page.locator('form[action$="/generate"] button').click();
    await until(() => h.posts().length === 1, 'Mutation started');
    if (failure === 'timeout') await h.page.clock.runFor(12001);
    await idle(h);
    await until(() => h.gets().length === 1, 'Uncertain mutation should refresh only status');
    await notice(h, 'warning', /could not confirm whether your action finished\. Check Refresh status before trying again\. No automatic retry was made/);
    if (failure === 'timeout') {
      assert.equal(await h.page.evaluate(() => window.fixtureCalls.find(call => call.method === 'POST').aborted), true);
      gate.resolve();
    }
    await h.page.clock.runFor(60000);
    await idle(h);
    assert.equal(h.posts().length, 1);
    assert.ok(h.gets().length >= 1 && h.gets().length <= 3, 'Only bounded read reconciliation may follow an uncertain write');
    assert.equal(await h.page.locator('[data-ai-baseline]').innerHTML(), '');
    await notice(h, 'warning', /Check Refresh status/);
    assert.equal(await h.page.locator('.synapse-toast-countdown').isVisible(), false, 'Safety warning stays until explicitly dismissed');
    assert.equal(await h.page.locator('form[action$="/generate"] button').isEnabled(), true);
    await samePage(h);
  });
}

test('409 conflict reports stale version in place and next review sends the current rendered version', { timeout: 20000 }, async t => {
  const h = await open(t, { insight: true, version: 7 });
  h.panel = render({ insight: true, version: 8 }).panel;
  h.respond = request => request.method === 'POST'
    ? json({ success: false, message: 'The insight changed. Review its current version.' }, 409,
      { 'X-Synapse-Auth': 'session_expired', 'X-Synapse-Login': '/auth/login' })
    : ok({ html: h.panel });
  await h.page.getByRole('button', { name: 'Accept insight', exact: true }).click();
  await until(() => h.posts().length === 1, 'Review submitted');
  await idle(h);
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.posts()[0].body)), {
    csrf_fixture: 'initial-token', version: '7', decision: 'accept',
  });
  await h.page.waitForFunction(() => document.querySelector('input[name="version"]')?.value === '8');
  await notice(h, 'error', /insight changed/);
    assert.doesNotMatch(await h.page.locator('.synapse-toast-message').textContent(), /could not confirm|automatic retry/);
  await samePage(h);
  h.respond = request => request.method === 'POST' ? ok({ message: 'Rejected current insight.' }) : ok({ html: h.panel });
  await h.page.getByRole('button', { name: 'Reject insight', exact: true }).click();
  await until(() => h.posts().length === 2, 'Current review submitted');
  await idle(h);
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.posts()[1].body)), {
    csrf_fixture: 'initial-token', version: '8', decision: 'reject',
  });
  assert.equal(h.posts()[1].path, base + '/47/review');
  await samePage(h);
});

test('cancel queued job uses its fixed POST route then stops polling after terminal status', { timeout: 20000 }, async t => {
  const h = await open(t, { jobStatus: 'queued' });
  h.panel = render({ jobStatus: 'cancelled' }).panel;
  await submit(h, '/jobs/31/cancel');
  assert.deepEqual(h.requests.map(r => [r.method, r.path]), [['POST', base + '/jobs/31/cancel'], ['GET', base + '/panel']]);
  assert.equal(new URLSearchParams(h.posts()[0].body).get('csrf_fixture'), 'initial-token');
  await h.page.locator('[data-ai-pending="false"]').waitFor();
  assert.equal(await h.page.getByRole('button', { name: 'Cancel waiting request' }).count(), 0);
  await h.page.clock.runFor(30000);
  assert.equal(h.gets().length, 1);
  assert.equal(h.posts().length, 1);
  await samePage(h);
});

test('poll only pending and visible; visibility resumes one read and terminal status stops timers', { timeout: 20000 }, async t => {
  const h = await open(t, { jobStatus: 'running' });
  await h.page.clock.runFor(5000);
  await until(() => h.gets().length === 1, 'Running job should poll');
  await idle(h);
  await visibility(h, true);
  await h.page.clock.runFor(30000);
  assert.equal(h.gets().length, 1, 'Hidden page must not poll');
  await h.page.locator('[data-ai-refresh]').evaluate(button => button.click());
  assert.equal(h.gets().length, 1, 'Hidden page must not start a manual status fetch either');
  h.panel = render({ jobStatus: 'succeeded' }).panel;
  await visibility(h, false);
  await until(() => h.gets().length === 2, 'Becoming visible should refresh status');
  await idle(h);
  await h.page.locator('[data-ai-pending="false"]').waitFor();
  await h.page.clock.runFor(30000);
  assert.equal(h.gets().length, 2, 'Terminal jobs do not poll');
  assert.equal(h.posts().length, 0);
  await samePage(h);
});

test('overlapping manual and timer refreshes coalesce into a single status read', { timeout: 20000 }, async t => {
  const h = await open(t, { jobStatus: 'queued' });
  const gate = h.hold();
  h.respond = async () => { await gate.promise; return ok({ html: h.panel }); };
  await h.page.clock.runFor(5000);
  await until(() => h.gets().length === 1, 'Poll started');
  await h.page.locator('[data-ai-refresh]').click();
  await h.page.locator('[data-ai-refresh]').click();
  await h.page.clock.runFor(5000);
  assert.equal(h.gets().length, 1);
  gate.resolve();
  await idle(h);
  assert.equal(h.posts().length, 0);
  await samePage(h);
});

for (const operation of ['read', 'write']) {
  test(`before-swap aborts in-flight ${operation}, ignores late output, and removes listeners/timers`, { timeout: 20000 }, async t => {
    const h = await open(t, { jobStatus: 'queued' });
    const gate = h.hold();
    h.respond = async () => {
      await gate.promise;
      return ok({ html: '<p>Late status</p>', baseline: '<p>Late baseline</p>' });
    };
    const original = await h.page.locator('[data-ai-panel]').innerHTML();
    if (operation === 'read') {
      await h.page.locator('[data-ai-refresh]').click();
      await until(() => h.gets().length === 1, 'Read started');
    } else {
      await h.page.locator('form[action$="/generate"] button').click();
      await until(() => h.posts().length === 1, 'Write started');
    }
    await beforeSwap(h);
    await h.page.waitForFunction(() => window.fixtureCalls[0].aborted && window.fixtureCalls[0].finished);
    gate.resolve();
    await idle(h);
    await h.page.clock.runFor(30000);
    await visibility(h, false);
    await h.page.locator('[data-ai-refresh]').click();
    // Retain the old root to prove its delegated submit listener was removed.
    await h.page.evaluate(() => {
      window.fixtureAfterSwapSubmitPrevented = false;
      document.addEventListener('submit', event => {
        window.fixtureAfterSwapSubmitPrevented = event.defaultPrevented;
        event.preventDefault(); // Prevent a native navigation in this probe only.
      }, { once: true });
      document.querySelector('form[data-ai-action]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    assert.equal(await h.page.evaluate(() => window.fixtureAfterSwapSubmitPrevented), false);
    assert.equal(h.requests.length, 1, 'Destroyed root cannot poll, submit, or refresh');
    assert.equal(await h.page.locator('[data-ai-panel]').innerHTML(), original);
    assert.equal(await h.page.locator('[data-ai-baseline]').innerHTML(), '');
    await samePage(h);
  });
}

test('page-load init is idempotent and a swapped-in root gets exactly one action handler', { timeout: 20000 }, async t => {
  const h = await open(t);
  await h.page.evaluate(() => {
    for (let i = 0; i < 3; i++) document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document } }));
  });
  await submit(h, '/prepare');
  assert.equal(h.posts().length, 1);
  await beforeSwap(h);
  await h.page.evaluate(() => {
    const old = document.querySelector('[data-ai-workspace]');
    const next = old.cloneNode(true);
    old.replaceWith(next);
    window.fixtureWorkspace = next;
    for (let i = 0; i < 3; i++) document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: next } }));
  });
  await submit(h, '/prepare');
  assert.equal(h.posts().length, 2);
  assert.equal(h.gets().length, 2);
  await samePage(h);
});

test('PHP views escape hostile synthetic narrative, evidence, provenance, program and reference output', { timeout: 20000 }, async t => {
  const fixture = render({ hostile: true, insight: true, jobStatus: 'outcome_unknown' });
  const h = await open(t, { hostile: true, insight: true, jobStatus: 'outcome_unknown' });
  const result = await h.page.locator('[data-ai-panel]').evaluate(panel => ({
    executable: panel.querySelectorAll('script, img, iframe, object, embed, [onerror], [onmouseover]').length,
    text: panel.textContent, links: [...panel.querySelectorAll('a')].map(link => ({
      href: link.getAttribute('href'), target: link.target, rel: link.rel, navigation: link.dataset.navigation,
    })),
  }));
  assert.equal(result.executable, 0);
  for (const label of ['Summary', 'Interpretation', 'Alternative explanation', 'Current complete month',
    'Previous complete month', 'Aggregate limitations', 'Fixture model', 'Fixture prompt', 'Operations planning',
    'Objective', 'Department staff', 'Activity', 'Resource assumption', 'Evaluation measure', 'Program limitation',
    'Operations reviewer', 'Reference title']) {
    assert.ok(result.text.includes(label + ' ' + fixture.attack), `${label} remains literal text`);
  }
  assert.equal(await h.page.evaluate(() => window.aiFixtureXss), undefined);
  assert.equal(result.links.length, 1);
  assert.equal(result.links[0].href, 'https://example.invalid/reference?label=" onmouseover="window.aiFixtureXss=1');
  assert.equal(result.links[0].target, '_blank');
  assert.match(result.links[0].rel, /noopener/);
  assert.match(result.links[0].rel, /noreferrer/);
  assert.equal(result.links[0].navigation, 'false');
  assert.match(result.text, /It will not be sent again automatically/);
  await samePage(h);
});

test('view permission/readiness/state gates and current-revision pending marker are preserved', { timeout: 20000 }, async t => {
  const h = await open(t, { canGenerate: false, canReview: false, canApprove: false, insight: true, jobStatus: 'queued' });
  assert.equal(await h.page.locator('form[data-ai-action]').count(), 0);
  assert.equal(await h.page.locator('[data-ai-refresh]').count(), 1);
  const cases = [
    [{ ready: false }, { prepare: true, generate: true, cancel: 0, review: [] }],
    [{ reason: 'disabled' }, { prepare: false, generate: true, cancel: 0, review: [] }],
    [{ jobStatus: 'running' }, { prepare: false, generate: false, cancel: 0, review: [] }],
    [{ jobStatus: 'queued', jobRevision: 'older-revision' }, { prepare: false, generate: false, cancel: 1, review: [], pending: 'false' }],
    [{ insight: true, insightStatus: 'accepted', version: 19 }, { prepare: false, generate: false, cancel: 0, review: ['reject', 'approve'] }],
  ];
  for (const [options, expected] of cases) {
    await h.page.locator('[data-ai-panel]').evaluate((panel, html) => { panel.innerHTML = html; }, render(options).panel);
    assert.equal(await h.page.locator('form[action$="/prepare"] button').isDisabled(), expected.prepare);
    assert.equal(await h.page.locator('form[action$="/generate"] button').isDisabled(), expected.generate);
    assert.equal(await h.page.locator('form[action$="/cancel"]').count(), expected.cancel);
    assert.deepEqual(await h.page.locator('input[name="decision"]').evaluateAll(inputs => inputs.map(input => input.value)), expected.review);
    if (expected.pending) assert.equal(await h.page.locator('[data-ai-pending]').getAttribute('data-ai-pending'), expected.pending);
  }
});

test('unsafe action endpoints and active HTML fragments are rejected without outbound requests', { timeout: 20000 }, async t => {
  const h = await open(t);
  for (const action of ['https://example.invalid/clinic/insights/generate', origin + base + '/generate?override=1',
    origin + base + '/generate#fragment', origin + '/other/write']) {
    await h.page.locator('form[action$="/generate"], form[data-fixture-probe]').first().evaluate((form, value) => {
      form.dataset.fixtureProbe = 'true'; form.action = value;
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }, action);
    await idle(h);
  }
  assert.equal(h.posts().length, 0);
  assert.equal(h.gets().length, 4, 'Rejected mutation may only refresh the fixed status route');
  const original = await h.page.locator('[data-ai-panel]').innerHTML();
  h.respond = () => ok({ html: '<script>window.aiFixtureXss=1</script><p>Do not insert</p>' });
  await refresh(h);
  assert.equal(await h.page.locator('[data-ai-panel]').innerHTML(), original);
  assert.equal(await h.page.evaluate(() => window.aiFixtureXss), undefined);
  assert.match(await h.page.locator('[data-ai-feedback]').innerText(), /Invalid analysis fragment/);
  await samePage(h);
});
