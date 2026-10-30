'use strict';

// No server/application boot: route every request and render only the real PHP view.
// Run: node --test tests/browser/ai-settings.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const origin = 'http://127.0.0.1:47991'; // Virtual origin; no listening socket.
const base = '/admin/ai';
const secret = 'synthetic-browser-settings-secret';
const version = 'a'.repeat(32);
const nextVersion = 'b'.repeat(32);
const sources = Object.fromEntries(['ai-settings', 'csrf', 'auth-session'].map(name =>
  [`/assets/js/${name}.js`, fs.readFileSync(path.join(root, `public/assets/js/${name}.js`), 'utf8')]));
sources['/assets/js/toast.js'] = fs.readFileSync(path.join(root, 'public/assets/js/toast.js'), 'utf8');
sources['/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'] = fs.readFileSync(path.join(root, 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), 'utf8');
const fixtures = new Map();
let browser;

function render(settings = {}, access = { ready: true, grants: [] }) {
  const input = JSON.stringify({ settings, access });
  if (!fixtures.has(input)) {
    const result = spawnSync('php', ['tests/_support/render-ai-settings-fixture.php'], {
      cwd: root, input, encoding: 'utf8', timeout: 10000, maxBuffer: 1024 * 1024,
    });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(result.stderr, '', 'View renderer must not emit warnings');
    fixtures.set(input, JSON.parse(result.stdout));
  }
  return fixtures.get(input);
}
function saved(changes = {}) {
  return render({ saved: true, version, base_url: 'https://gateway.example.test/team/v1',
    model: 'org/synthetic-model', has_api_key: true, storage_key_ready: true, ...changes }).settings;
}
const json = (payload, status = 200, headers = {}) => ({ status, contentType: 'application/json', headers, body: JSON.stringify(payload) });
const ok = (settings, message = 'Synthetic settings updated.') => json({ success: true, settings, message,
  csrf: { name: 'csrf_fixture', hash: 'fresh-token' } });
function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}
async function until(predicate, message, timeout = 8000) {
  const deadline = performance.now() + timeout;
  do {
    if (await predicate()) return;
    await delay(20);
  } while (performance.now() < deadline);
  assert.fail(message);
}

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

async function open(t, settings = {}, access = { ready: true, grants: [] }) {
  const fixture = render(settings, access);
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  const h = {
    page, context, requests: [], documents: [], unexpected: [], errors: [], gates: [], authEvents: [],
    respond: entry => ok(saved(entry.path.endsWith('/test') ? { connection_tested: true } : {})),
    posts() { return h.requests.filter(entry => entry.method === 'POST'); },
    gets() { return h.requests.filter(entry => entry.method === 'GET'); },
    hold() { const gate = deferred(); h.gates.push(gate); return gate; },
  };
  page.on('pageerror', error => h.errors.push(error.message));
  await page.exposeFunction('fixtureAuth', event => { h.authEvents.push(event); });
  t.after(async () => {
    h.gates.forEach(gate => gate.resolve());
    await context.close();
    assert.deepEqual(h.unexpected, [], 'No provider, application, or unmocked outbound requests');
    assert.deepEqual(h.errors, [], 'No uncaught browser errors');
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
          <script src="/assets/js/toast.js"></script>
          <script src="/assets/js/auth-session.js"></script><script src="/assets/js/csrf.js"></script>
          </head><body><aside id="shell"><input value="keep me"></aside>${fixture.html}</body></html>` });
      }
      if (url.pathname === '/auth/login') return route.fulfill({ contentType: 'text/html', body: '<h1>Synthetic login</h1>' });
    } else if (sources[url.pathname] && request.method() === 'GET') {
      return route.fulfill({ contentType: 'text/javascript', body: sources[url.pathname] });
    } else if (/^\/admin\/ai\/(?:status|save|test|enable|disable|access)$/.test(url.pathname)) {
      const entry = { path: url.pathname, method: request.method(), url: request.url(), headers: request.headers(), body: request.postData() };
      h.requests.push(entry);
      try {
        const reply = await h.respond(entry);
        if (reply === 'network-error') return await route.abort('failed');
        return await route.fulfill(reply);
      } catch (error) {
        if (!context.pages().length || /closed|disposed|Invalid InterceptionId/i.test(error.message)) return;
        h.errors.push(error.message);
      }
      return;
    }
    h.unexpected.push(`${request.method()} ${request.url()}`);
    return route.abort('blockedbyclient');
  });
  await page.clock.install({ time: new Date('2026-10-01T00:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-01T00:00:01Z'));
  await page.addInitScript(() => {
    window.fixtureCalls = [];
    window.fixtureStorageWrites = [];
    const setItem = Storage.prototype.setItem;
    Storage.prototype.setItem = function (key, value) {
      window.fixtureStorageWrites.push([String(key), String(value)]);
      return setItem.call(this, key, value);
    };
    const openDb = IDBFactory.prototype.open;
    IDBFactory.prototype.open = function (...args) {
      window.fixtureStorageWrites.push(['indexedDB', ...args]);
      return openDb.apply(this, args);
    };
    document.addEventListener('synapse:auth-required', event => {
      void window.fixtureAuth({ reason: event.detail.reason, token: document.querySelector('meta[name="csrf-token"]').content });
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
    await page.waitForFunction(() => window.SynapseAiSettings && window.SynapseCsrf && window.SynapseAuth && window.SynapseToast);
  await page.evaluate(() => {
    window.fixtureRoot = document.querySelector('[data-ai-settings]');
    window.fixtureShell = document.querySelector('#shell');
  });
  return h;
}
const form = (h, action) => h.page.locator(`[data-ai-settings-form="${action}"]`);
async function idle(h) {
  await h.page.waitForFunction(() => document.querySelector('[data-ai-settings]').getAttribute('aria-busy') === 'false' &&
    window.fixtureCalls.every(call => call.finished));
}
async function submit(h, action) {
  const count = h.posts().length + 1;
  await form(h, action).locator('button[type="submit"]').click();
  await until(() => h.posts().length >= count, `Expected ${action} POST`);
  await idle(h);
}
async function fill(h) {
  await h.page.locator('[name="base_url"]').fill('https://gateway.example.test/team/v1');
  await h.page.locator('[name="model"]').fill('org/synthetic-model');
  await h.page.locator('[name="api_key"]').fill(secret);
}
async function samePage(h) {
  assert.equal(h.page.url(), origin + base);
  assert.deepEqual(h.documents, [{ path: base, method: 'GET' }], 'No reload, form navigation or login');
  assert.deepEqual(h.authEvents, []);
  assert.equal(await h.page.evaluate(() => window.fixtureRoot === document.querySelector('[data-ai-settings]') &&
    window.fixtureShell === document.querySelector('#shell') && !window.SynapseAuth.redirecting), true);
  assert.equal(await h.page.locator('#shell input').inputValue(), 'keep me');
}
async function noStoredSecret(h) {
  const state = await h.page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage },
    writes: window.fixtureStorageWrites, cookie: document.cookie, url: location.href }));
  assert.deepEqual(state.local, {});
  assert.deepEqual(state.session, {});
  assert.deepEqual(state.writes, [], 'Settings must not write browser storage, even transiently');
  assert.ok(!JSON.stringify(state).includes(secret));
  assert.ok(!JSON.stringify(await h.context.cookies()).includes(secret));
  for (const entry of h.requests) {
    assert.ok(!entry.url.includes(secret), 'No secret in a URL');
    assert.ok(!JSON.stringify(entry.headers).includes(secret), 'No provider key in browser headers');
    if (!entry.path.endsWith('/save')) assert.ok(!String(entry.body).includes(secret), 'Only explicit Save may transmit a key');
  }
}

test('advanced thinking mode saves explicitly, reloads, and requires a new test independently of reasoning', { timeout: 20000 }, async t => {
  const h = await open(t, saved({ connection_tested: true }));
  await h.page.locator('details summary').click();
  const thinking = h.page.locator('[name="thinking_mode"]');
  assert.deepEqual(await thinking.locator('option').allTextContents(), ['Provider default', 'Disabled', 'Enabled']);
  assert.equal(await thinking.inputValue(), '');
  await thinking.selectOption('disabled');
  await h.page.locator('[name="reasoning_effort"]').selectOption('none');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  h.respond = () => ok(saved({ version: nextVersion, thinking_mode: 'disabled', reasoning_effort: 'none' }));
  await submit(h, 'save');
  const post = new URLSearchParams(h.posts()[0].body);
  assert.equal(post.get('thinking_mode'), 'disabled');
  assert.equal(post.get('reasoning_effort'), 'none');
  assert.equal(await thinking.inputValue(), 'disabled');
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  h.respond = () => ok(saved({ version: nextVersion, thinking_mode: 'enabled', reasoning_effort: 'none' }));
  await h.page.getByRole('button', { name: 'Reload saved settings' }).click();
  await until(() => h.gets().length === 1, 'Expected saved settings reload');
  await idle(h);
  assert.equal(await thinking.inputValue(), 'enabled');
  assert.equal(await h.page.locator('[name="reasoning_effort"]').inputValue(), 'none');
  await samePage(h);
  await noStoredSecret(h);
});

test('approved department checkboxes post explicit selection and apply the saved units', { timeout: 20000 }, async t => {
  const h = await open(t);
  await fill(h);
  await h.page.locator('#ai-allow-clinic').uncheck();
  await h.page.locator('#ai-allow-guidance').check();
  await h.page.locator('#ai-allow-bmg').check();
  h.respond = () => ok(saved({ approved_units: ['guidance', 'bmg'] }));
  await submit(h, 'save');
  const post = new URLSearchParams(h.posts()[0].body);
  assert.equal(post.get('unit_selection'), '1');
  assert.equal(post.has('allow_clinic'), false);
  assert.equal(post.get('allow_guidance'), '1');
  assert.equal(post.get('allow_bmg'), '1');
  assert.equal(await h.page.locator('#ai-allow-clinic').isChecked(), false);
  assert.equal(await h.page.locator('#ai-allow-guidance').isChecked(), true);
  await noStoredSecret(h);
});

test('department matrix grants and revokes scoped permissions without saving dirty provider fields', { timeout: 20000 }, async t => {
  const h = await open(t);
  await fill(h);
  const access = h.page.locator('[data-ai-settings-form="access"]').filter({ has: h.page.locator('[name="role"][value="counsellor"]') })
    .filter({ has: h.page.locator('[name="permission"][value="ai.insights.read"]') });
  assert.equal(await access.count(), 1);
  h.respond = entry => {
    const body = new URLSearchParams(entry.body);
    return json({ success: true, message: 'Scoped access updated.', access: { ready: true, grants: body.get('operation') === 'grant'
      ? [{ unit: 'guidance', role: 'counsellor', permission: 'ai.insights.read' }] : [] }, csrf: { name: 'csrf_fixture', hash: 'grant-token' } });
  };
  await access.locator('button').click();
  await idle(h);
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.posts()[0].body)), {
    csrf_fixture: 'initial-token', operation: 'grant', unit: 'guidance', role: 'counsellor', permission: 'ai.insights.read',
  });
  assert.equal(h.posts()[0].path, '/admin/ai/access');
  assert.equal(h.posts()[0].headers['x-csrf-token'], 'initial-token');
  assert.match(await access.locator('button').textContent(), /Revoke Read/);
  assert.equal(await h.page.locator('#ai-api-key').inputValue(), secret);
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  await access.locator('button').click();
  await idle(h);
  assert.equal(new URLSearchParams(h.posts()[1].body).get('operation'), 'revoke');
  assert.equal(h.posts()[1].headers['x-csrf-token'], 'grant-token');
  assert.match(await access.locator('button').textContent(), /Grant Read/);
  const permissions = await h.page.locator('[data-ai-settings-form="access"] [name="permission"]').evaluateAll(inputs => [...new Set(inputs.map(item => item.value))]);
  assert.deepEqual(permissions, ['ai.insights.read', 'ai.insights.generate', 'ai.insights.review', 'ai.insights.approve']);
  await samePage(h);
  await noStoredSecret(h);
});

test('missing access schema disables only grant controls and explicit status reload recovers them', { timeout: 20000 }, async t => {
  const h = await open(t, { approved_units: [] }, { ready: false, grants: [] });
  const buttons = h.page.locator('[data-ai-settings-form="access"] button');
  assert.equal(await buttons.count(), 40);
  assert.equal(await buttons.first().isDisabled(), true);
  assert.equal(await form(h, 'save').locator('button[type="submit"]').isDisabled(), false);
  h.respond = () => json({ success: true, settings: render({ approved_units: [] }).settings, access: { ready: true, grants: [] }, csrf: { name: 'csrf_fixture', hash: 'fresh-token' } });
  await h.page.getByRole('button', { name: 'Reload saved settings' }).click();
  await idle(h);
  assert.equal(await buttons.first().isDisabled(), false);
  assert.equal(h.posts().length, 0);
  assert.equal(h.gets().length, 1);
  assert.equal(await h.page.locator('[name^="allow_"]:checked').count(), 0);
});

test('access writes reject cross-origin actions and never retry an uncertain mutation', { timeout: 20000 }, async t => {
  const h = await open(t);
  const access = form(h, 'access').first();
  await access.evaluate(item => { item.action = 'https://untrusted.example/admin/ai/access'; });
  await access.locator('button').click();
  await idle(h);
  assert.equal(h.posts().length, 0);
  assert.equal(h.unexpected.length, 0);
  await access.evaluate(item => { item.action = '/admin/ai/access'; });
  h.respond = () => 'network-error';
  await access.locator('button').click();
  await idle(h);
  await delay(200);
  assert.equal(h.posts().length, 1);
  assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /No automatic retry/);
});

test('initial GET renders editable endpoint/key/model, provider-default reasoning and token limits without requests', { timeout: 20000 }, async t => {
  const h = await open(t);
  assert.equal(await h.page.locator('#ai-base-url').getAttribute('type'), 'url');
  assert.equal(await h.page.locator('#ai-api-key').getAttribute('type'), 'password');
  assert.equal(await h.page.locator('#ai-api-key').getAttribute('autocomplete'), 'new-password');
  for (const id of ['ai-base-url', 'ai-api-key', 'ai-model', 'ai-reasoning']) assert.equal(await h.page.locator(`#${id}`).inputValue(), '');
  assert.match(await h.page.locator('#ai-reasoning option:checked').textContent(), /Provider default/);
  assert.equal(await h.page.locator('#ai-input-tokens').inputValue(), '8192');
  assert.equal(await h.page.locator('#ai-output-tokens').inputValue(), '2000');
  assert.equal(await h.page.locator('#ai-auth-mode').inputValue(), 'bearer');
  assert.equal(await h.page.locator('#ai-token-field').inputValue(), 'max_tokens');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  assert.match(await h.page.locator('[data-ai-settings-state]').textContent(), /Not configured/);
  assert.deepEqual(h.requests, []);
  await samePage(h);
  await noStoredSecret(h);
});

test('Save posts exact form contract with CSRF, applies server JSON, clears password and updates every version without reload', { timeout: 20000 }, async t => {
  const h = await open(t);
  await fill(h);
  await h.page.locator('#ai-reasoning').selectOption('high');
  await h.page.locator('#ai-input-tokens').fill('16384');
  await h.page.locator('#ai-output-tokens').fill('3072');
  await h.page.locator('details summary').click();
  await h.page.locator('#ai-auth-mode').selectOption('api_key_header');
  await h.page.locator('#ai-token-field').selectOption('max_completion_tokens');
  await h.page.locator('#ai-structured').selectOption('json_object');
  await h.page.locator('#ai-private-network').check();
  const response = saved({ version: nextVersion, base_url: 'https://normalized.example.test/v1', model: 'server-model',
    reasoning_effort: 'low', max_input_tokens: 32768, max_output_tokens: 1024, auth_mode: 'api_key_header',
    token_field: 'max_completion_tokens', structured_output: 'json_object', allow_private_network: true });
  h.respond = () => ok(response, 'Saved server-normalized configuration.');
  await submit(h, 'save');
  const post = h.posts()[0];
  assert.deepEqual(Object.fromEntries(new URLSearchParams(post.body)), {
    csrf_fixture: 'initial-token', version: '', base_url: 'https://gateway.example.test/team/v1', api_key: secret,
    model: 'org/synthetic-model', reasoning_effort: 'high', thinking_mode: '', max_input_tokens: '16384', max_output_tokens: '3072',
    auth_mode: 'api_key_header', token_field: 'max_completion_tokens', structured_output: 'json_object', allow_private_network: '1',
        unit_selection: '1', allow_clinic: '1',
  });
  assert.equal(post.headers['x-csrf-token'], 'initial-token');
  assert.match(post.headers['content-type'], /^application\/x-www-form-urlencoded/);
  for (const field of ['base_url', 'model', 'reasoning_effort', 'thinking_mode', 'max_input_tokens', 'max_output_tokens', 'auth_mode', 'token_field', 'structured_output']) {
    assert.equal(await form(h, 'save').locator(`[name="${field}"]`).inputValue(), String(response[field]));
  }
  assert.equal(await h.page.locator('#ai-api-key').inputValue(), '');
  assert.match(await h.page.locator('#ai-api-key').getAttribute('placeholder'), /leave blank to keep/);
  assert.deepEqual(await h.page.locator('[name="version"]').evaluateAll(inputs => inputs.map(input => input.value)), Array(4).fill(nextVersion));
  assert.deepEqual(await h.page.locator('[name="csrf_fixture"]').evaluateAll(inputs => inputs.map(input => input.value)), Array(44).fill('fresh-token'));
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), false);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /server-normalized/);
  assert.deepEqual(await h.page.evaluate(() => window.fixtureCalls.map(({ credentials, cache, redirect }) => ({ credentials, cache, redirect }))),
    [{ credentials: 'same-origin', cache: 'no-store', redirect: 'error' }]);
  await samePage(h);
  await noStoredSecret(h);
});

test('settings header has no duplicate page heading and connection results announce through a toast', { timeout: 20000 }, async t => {
  const h = await open(t, saved());
  assert.equal(await h.page.locator('[data-ai-settings] h1, [data-ai-settings] h2').count(), 0);
  assert.equal(await h.page.locator('[data-ai-settings]').getByText('AI Settings', { exact: true }).count(), 0);
  const message = 'Connection successful. Endpoint, API key, model, and selected options worked.';
  h.respond = () => ok(saved({ connection_tested: true }), message);
  await submit(h, 'test');
  const toast = h.page.locator('#synapse-toast-container .synapse-toast');
  await h.page.clock.resume();
  await h.page.waitForFunction(message => [...document.querySelectorAll('.synapse-toast .synapse-toast-message')]
    .some(node => node.textContent === message), message);
  assert.equal(await toast.count(), 1);
  assert.match(await toast.textContent(), /Connection successful/);
  assert.equal(await toast.locator('.synapse-toast-message').textContent(), message);
  assert.equal(await toast.getAttribute('data-type'), 'success');
  assert.equal(await h.page.locator('[data-ai-settings-feedback]').textContent(), message);
  await samePage(h);
  await noStoredSecret(h);
});

test('blank key Save retains saved-key state and Test sends only saved version plus fresh CSRF, not unsent provider fields', { timeout: 20000 }, async t => {
  const h = await open(t, saved());
  await submit(h, 'save');
  assert.equal(new URLSearchParams(h.posts()[0].body).get('api_key'), '');
  assert.match(await h.page.locator('[data-ai-key-state]').textContent(), /A key is saved/);
  // A password manager filling without an input event must still never leak a key into Test.
  await h.page.locator('#ai-api-key').evaluate((input, value) => { input.value = value; }, secret);
  await submit(h, 'test');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.posts()[1].body)), { csrf_fixture: 'fresh-token', version });
  assert.equal(h.posts()[1].headers['x-csrf-token'], 'fresh-token');
  assert.match(await h.page.locator('[data-ai-settings-state]').textContent(), /Connection verified/);
  assert.equal(await h.page.locator('#ai-api-key').inputValue(), '');
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), false);
  await samePage(h);
  await noStoredSecret(h);
});

test('dirty settings disable Test and Enable, including synthetic submits, until Save completes', { timeout: 20000 }, async t => {
  const h = await open(t, saved({ connection_tested: true }));
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), false);
  await h.page.locator('#ai-model').fill('unsaved-model');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  await h.page.locator('#ai-confirm-analytics').check();
  await h.page.evaluate(() => {
    for (const action of ['test', 'enable']) document.querySelector(`[data-ai-settings-form="${action}"]`)
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  assert.deepEqual(h.requests, []);
  await submit(h, 'save');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), false);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  assert.equal(await h.page.locator('#ai-confirm-analytics').isChecked(), false);
  await samePage(h);
});

test('Enable requires confirmation, then server response switches to Pause without replaying provider settings', { timeout: 20000 }, async t => {
  const h = await open(t, saved({ connection_tested: true }));
  await form(h, 'enable').locator('button').click();
  assert.deepEqual(h.requests, []);
  await h.page.locator('#ai-confirm-analytics').check();
  h.respond = () => ok(saved({ connection_tested: true, enabled: true }));
  await submit(h, 'enable');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.posts()[0].body)), { csrf_fixture: 'initial-token', version, confirm_analytics: '1' });
  assert.equal(await form(h, 'enable').isHidden(), true);
  assert.equal(await form(h, 'disable').isVisible(), true);
  h.respond = () => ok(saved({ connection_tested: true }));
  await submit(h, 'disable');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.posts()[1].body)), { csrf_fixture: 'fresh-token', version });
  assert.equal(await form(h, 'disable').isHidden(), true);
  assert.equal(await form(h, 'enable').isVisible(), true);
  await samePage(h);
  await noStoredSecret(h);
});

for (const status of [401, 503]) {
  test(`provider model/key error (${status}) stays in place and never invokes login`, { timeout: 20000 }, async t => {
    const h = await open(t, saved());
    h.respond = () => json({ success: false, reason: 'provider_rejected',
      message: 'The endpoint did not accept the selected model or options.', csrf: { name: 'csrf_fixture', hash: 'error-token' } }, status,
    status === 503 ? { 'X-Synapse-Auth': 'authentication_required', 'X-Synapse-Login': '/auth/login' } : {});
    await submit(h, 'test');
    assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /selected model or options/);
    assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
    assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), false);
    await samePage(h);
  });
}

test('explicit user authentication denial delegates to shared auth owner once before CSRF sync and never replays POST', { timeout: 20000 }, async t => {
  const h = await open(t, saved());
  h.respond = () => json({ success: false, message: 'Sign in again.' }, 401, {
    'X-Synapse-Auth': 'authentication_required', 'X-Synapse-Login': '/auth/login',
    'X-CSRF-NAME': 'csrf_fixture', 'X-CSRF-TOKEN': 'must-not-sync',
  });
  await form(h, 'test').locator('button').click();
  await h.page.waitForURL(origin + '/auth/login');
  await until(() => h.authEvents.length === 1, 'Shared auth event');
  assert.deepEqual(h.authEvents, [{ reason: 'authentication_required', token: 'initial-token' }]);
  assert.equal(h.posts().length, 1);
  assert.deepEqual(h.documents, [{ path: base, method: 'GET' }, { path: '/auth/login', method: 'GET' }]);
});

test('busy controls and repeated init/submits permit only one unresolved mutation', { timeout: 20000 }, async t => {
  const h = await open(t, saved({ connection_tested: true }));
  const gate = h.hold();
  h.respond = async () => { await gate.promise; return ok(saved()); };
  await h.page.evaluate(() => {
    for (let i = 0; i < 3; i++) {
      window.SynapseAiSettings.init();
      document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document.querySelector('[data-ai-settings]') } }));
    }
  });
  await form(h, 'save').locator('button[type="submit"]').click();
  await until(() => h.posts().length === 1, 'First pending Save');
  assert.equal(await h.page.locator('[data-ai-settings]').getAttribute('aria-busy'), 'true');
  assert.equal(await h.page.locator('[data-ai-settings] button:not(:disabled)').count(), 0);
  await h.page.evaluate(() => {
    for (let i = 0; i < 3; i++) for (const form of document.querySelectorAll('[data-ai-settings-form]')) {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
  });
  assert.equal(h.posts().length, 1);
  gate.resolve();
  await idle(h);
  assert.equal(await form(h, 'save').locator('button[type="submit"]').isDisabled(), false);
  await samePage(h);
});

for (const action of ['save', 'test']) {
  for (const failure of ['network', 'timeout']) {
    test(`${action} ${failure} makes no automatic POST replay; explicit recovery is one redacted GET`, { timeout: 20000 }, async t => {
      const h = await open(t, saved());
      const gate = h.hold();
      h.respond = failure === 'network' ? () => 'network-error' : async () => { await gate.promise; return ok(saved()); };
      await form(h, action).locator('button[type="submit"]').click();
      await until(() => h.posts().length === 1, 'Initial mutation');
      if (failure === 'timeout') await h.page.clock.fastForward(action === 'test' ? 135001 : 15001);
      await idle(h);
      assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /No automatic retry/);
      if (failure === 'timeout') assert.equal(await h.page.evaluate(() => window.fixtureCalls[0].aborted), true);
      await h.page.clock.fastForward(300000);
      assert.equal(h.posts().length, 1);
      assert.equal(h.gets().length, 0, 'Recovery must be explicit');
      h.respond = () => ok(saved({ version: nextVersion }));
      await h.page.getByRole('button', { name: 'Reload saved settings' }).click();
      await until(() => h.gets().length === 1, 'Explicit status GET');
      await idle(h);
      assert.equal(h.gets()[0].path, base + '/status');
      assert.equal(h.gets()[0].body, null);
      assert.equal(h.posts().length, 1);
      assert.equal(await form(h, 'test').locator('[name="version"]').inputValue(), nextVersion);
      assert.equal(await h.page.locator('#ai-api-key').inputValue(), '');
      await samePage(h);
      await noStoredSecret(h);
    });
  }
}

test('before-swap aborts in-flight work, clears detached password and removes handlers; replacement mounts once', { timeout: 20000 }, async t => {
  const h = await open(t, saved());
  await h.page.locator('#ai-api-key').fill(secret);
  const gate = h.hold();
  h.respond = async () => { await gate.promise; return ok(saved({ version: nextVersion })); };
  await form(h, 'save').locator('button[type="submit"]').click();
  await until(() => h.posts().length === 1, 'Pending Save');
  await h.page.evaluate(() => {
    const root = window.fixtureRoot;
    window.fixtureFeedback = root.querySelector('[data-ai-settings-feedback]').textContent;
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.body } }));
    root.remove();
  });
  await h.page.waitForFunction(() => window.fixtureCalls[0].aborted && window.fixtureCalls[0].finished);
  assert.equal(await h.page.evaluate(() => window.fixtureRoot.querySelector('[name="api_key"]').value), '');
  await h.page.evaluate(() => {
    window.fixtureRoot.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  gate.resolve();
  await h.page.clock.fastForward(150000);
  assert.equal(h.posts().length, 1);
  assert.equal(await h.page.evaluate(() => window.fixtureRoot.querySelector('[data-ai-settings-feedback]').textContent === window.fixtureFeedback), true);
  assert.equal(await h.page.evaluate(() => window.fixtureRoot.querySelector('[name="version"]').value), version);
  await h.page.evaluate(html => {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    const replacement = holder.querySelector('[data-ai-settings]');
    document.body.append(replacement);
    for (let i = 0; i < 3; i++) document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: replacement } }));
  }, render(saved()).html);
  h.respond = () => ok(saved());
  await submit(h, 'save');
  assert.equal(h.posts().length, 2);
  assert.equal(await h.page.getByRole('button', { name: 'Reload saved settings' }).count(), 1);
  await noStoredSecret(h);
});

test('real view escapes hostile provider fields without executable attributes or network requests', { timeout: 20000 }, async t => {
  const attack = '\"><img src="/xss-probe" onerror="window.fixtureXss=1"><script>window.fixtureXss=1</script>&';
  const h = await open(t, saved({ base_url: attack, model: attack, version: attack }));
  assert.equal(await h.page.locator('#ai-base-url').inputValue(), attack);
  assert.equal(await h.page.locator('#ai-model').inputValue(), attack);
  assert.equal(await h.page.locator('[data-ai-settings] img, [data-ai-settings] script, [onerror]').count(), 0);
  assert.equal(await h.page.evaluate(() => window.fixtureXss), undefined);
  assert.deepEqual(h.requests, []);
  await noStoredSecret(h);
});
