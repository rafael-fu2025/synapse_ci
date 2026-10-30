'use strict';

// Isolated real-view tests: no application server, credentials, database or provider calls.
// Run: node --test tests/browser/ai-model-discovery.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const origin = 'http://127.0.0.1:47991';
const baseURL = 'https://gateway.example.test/team/v1';
const secret = 'synthetic-discovery-secret';
const token = 'c'.repeat(64);
const version = 'a'.repeat(32);
const sources = Object.fromEntries(['ai-settings', 'csrf', 'auth-session'].map(name =>
  [`/assets/js/${name}.js`, fs.readFileSync(path.join(root, `public/assets/js/${name}.js`), 'utf8')]));
// Extend only the CLI fixture's local scope, leaving the shared fixture/backend untouched.
const renderer = fs.readFileSync(path.join(root, 'tests/_support/render-ai-settings-fixture.php'), 'utf8')
  .replace(/^<\?php/, '')
  .replaceAll("dirname(__DIR__, 2)", "getcwd()")
  .replace('ob_start();', "$catalog = $GLOBALS['options']['catalog'] ?? null; ob_start();");
function render(settings = {}, catalog) {
  const result = spawnSync('php', ['-r', renderer], { cwd: root, input: JSON.stringify({ settings, catalog }),
    encoding: 'utf8', timeout: 10000, maxBuffer: 2 * 1024 * 1024 });
  assert.ifError(result.error);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.equal(result.stderr, '');
  return JSON.parse(result.stdout);
}
const row = (id = 'org/model', overrides = {}) => ({ id, name: id, context_window: 128000, max_output_tokens: 16000,
  supports_thinking: true, defaults: { max_input_tokens: 8192, max_output_tokens: 4000, thinking_mode: 'disabled',
    reasoning_effort: '', token_field: 'max_completion_tokens', structured_output: 'none' },
  sources: { context_window: 'provider: context_window', max_output_tokens: 'provider: max_output_tokens',
    supports_thinking: 'provider: supports_thinking', 'defaults.max_input_tokens': 'local safe budget',
    'defaults.max_output_tokens': 'current config capped by provider', 'defaults.thinking_mode': 'reviewed preset',
    'defaults.reasoning_effort': 'provider default', 'defaults.token_field': 'provider: supported_parameters',
    'defaults.structured_output': 'provider default' }, warnings: [], ...overrides });
const catalog = (models = [row()], base_url = baseURL) => ({ base_url, models, imported_at: '2026-10-01T00:00:00Z' });
const preview = (models = [row()], extra = {}) => ({ base_url: baseURL, models, preview_token: token,
  expires_at: 1790813400, ...extra }); // 2026-10-01 00:10 UTC
const json = (payload, status = 200, headers = {}) => ({ status, headers, contentType: 'application/json', body: JSON.stringify(payload) });
const ok = payload => json({ success: true, csrf: { name: 'csrf_fixture', hash: 'fresh-token' }, ...payload });
let browser;
before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'A locally installed Chromium is required');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => { await browser?.close(); });
async function open(t, settings = {}, initialCatalog) {
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage(); page.setDefaultTimeout(8000);
  const h = { page, context, requests: [], errors: [], unexpected: [], consoles: [], authEvents: [],
    fixture: render(settings, initialCatalog), respond: () => ok({ preview: preview() }) };
  await page.exposeFunction('fixtureAuth', event => h.authEvents.push(event));
  page.on('pageerror', e => h.errors.push(e.message));
  page.on('console', e => h.consoles.push(e.text()));
  t.after(async () => {
    await context.close();
    assert.deepEqual(h.errors, []);
    assert.deepEqual(h.unexpected, [], 'No outbound provider or unmocked requests');
    assert.ok(!h.consoles.join('').includes(secret));
  });
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin === origin && request.isNavigationRequest() && url.pathname === '/admin/ai') {
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">
        <meta name="csrf-name" content="csrf_fixture"><meta name="csrf-token" content="initial-token">
        <meta name="csrf-header" content="X-CSRF-TOKEN"><meta name="synapse-login-url" content="/auth/login">
        <script src="/assets/js/auth-session.js"></script><script src="/assets/js/csrf.js"></script>
        </head><body>${h.fixture.html}</body></html>` });
    }
    if (url.origin === origin && url.pathname === '/auth/login') return route.fulfill({ contentType: 'text/html', body: '<h1>Synthetic login</h1>' });
    if (url.origin === origin && sources[url.pathname]) return route.fulfill({ contentType: 'text/javascript', body: sources[url.pathname] });
    if (url.origin === origin && /^\/admin\/ai\/(?:status|save|test|enable|disable|access|models\/preview|models\/import)$/.test(url.pathname)) {
      const entry = { path: url.pathname, method: request.method(), url: request.url(), headers: request.headers(), body: request.postData() };
      h.requests.push(entry);
      try {
        const reply = await h.respond(entry);
        if (reply === 'network-error') return await route.abort('failed');
        return await route.fulfill(reply);
      } catch (error) { if (!/closed|disposed|Invalid InterceptionId/i.test(error.message)) h.errors.push(error.message); }
      return;
    }
    h.unexpected.push(request.url()); return route.abort('blockedbyclient');
  });
  await page.clock.install({ time: new Date('2026-10-01T00:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-01T00:00:01Z'));
  await page.addInitScript(() => {
    window.calls = []; window.storageWrites = [];
    document.addEventListener('synapse:auth-required', event => {
      queueMicrotask(() => window.fixtureAuth({ reason: event.detail.reason,
        key: document.querySelector('[name="api_key"]').value,
        csrf: document.querySelector('meta[name="csrf-token"]').content }));
    });
    const setItem = Storage.prototype.setItem;
    Storage.prototype.setItem = function (...args) { window.storageWrites.push(args); return setItem.apply(this, args); };
    const open = IDBFactory.prototype.open;
    IDBFactory.prototype.open = function (...args) { window.storageWrites.push(args); return open.apply(this, args); };
    const fetch = window.fetch.bind(window);
    window.fetch = async (url, options) => {
      const call = { url: String(url), aborted: false, finished: false, credentials: options.credentials, cache: options.cache, redirect: options.redirect };
      window.calls.push(call); options.signal.addEventListener('abort', () => { call.aborted = true; });
      try { return await fetch(url, options); } finally { call.finished = true; }
    };
  });
  await page.goto(origin + '/admin/ai');
  await page.waitForFunction(() => window.SynapseAiSettings && document.querySelector('[data-ai-settings]').getAttribute('aria-busy') === 'false');
  return h;
}
async function waitForRequests(h, count) {
  const deadline = performance.now() + 8000;
  while (h.requests.length < count && performance.now() < deadline) await delay(20);
  assert.equal(h.requests.length, count, 'Expected routed request count before cancellation');
}
async function idle(h) {
  await h.page.waitForFunction(() => document.querySelector('[data-ai-settings]').getAttribute('aria-busy') === 'false' && window.calls.every(c => c.finished));
}
async function click(h, selector) { await h.page.locator(selector).click(); await idle(h); }
async function fetchModels(h) { await click(h, '[data-ai-model-fetch]'); }
async function connection(h) {
  await h.page.locator('[name="base_url"]').fill(baseURL);
  await h.page.locator('[name="api_key"]').fill(secret);
}
async function secure(h) {
  const state = await h.page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage }, writes: window.storageWrites,
    cookie: document.cookie, url: location.href, calls: window.calls, feedback: document.querySelector('[data-ai-settings-feedback]').textContent }));
  assert.deepEqual(state.local, {}); assert.deepEqual(state.session, {}); assert.deepEqual(state.writes, []);
  assert.ok(!JSON.stringify(state).includes(secret));
  assert.ok(!JSON.stringify(await h.context.cookies()).includes(secret));
  for (const r of h.requests) {
    assert.ok(!r.url.includes(secret)); assert.ok(!JSON.stringify(r.headers).includes(secret));
    if (!r.path.endsWith('/preview')) assert.ok(!String(r.body).includes(secret));
  }
}

test('fetch with no model uses only unsaved connection and CSRF; selected import does not save or activate', async t => {
  const h = await open(t); await connection(h);
  assert.equal(h.requests.length, 0);
  assert.equal(await h.page.locator('[name="model"]').inputValue(), '');
  h.respond = r => r.path.endsWith('/preview') ? ok({ preview: preview([row(), row('org/other')]) }) : ok({ catalog: catalog() });
  await fetchModels(h);
  assert.equal(h.requests.length, 1);
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.requests[0].body)), { csrf_fixture: 'initial-token', version: '',
    base_url: baseURL, api_key: secret, remove_api_key: '0', auth_mode: 'bearer', allow_private_network: '0' });
  assert.equal(h.requests[0].headers['x-csrf-token'], 'initial-token');
  assert.equal(await h.page.locator('[data-ai-model-import-selected]').isDisabled(), true);
  await h.page.locator('[data-ai-model-preview-list] input').first().check();
  await click(h, '[data-ai-model-import-selected]');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.requests[1].body)), { csrf_fixture: 'fresh-token', preview_token: token, model_ids: '["org/model"]', import_all: '0' });
  assert.equal(await h.page.locator('[name="model"]').inputValue(), '');
  assert.equal(await h.page.locator('[name="api_key"]').inputValue(), secret);
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 2);
  await h.page.locator('[data-ai-model-select]').selectOption('org/model');
  for (const [key, value] of Object.entries({ model: 'org/model', ...row().defaults })) assert.equal(await h.page.locator(`[name="${key}"]`).inputValue(), String(value));
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  assert.match(await h.page.locator('[data-ai-model-details]').textContent(), /Provider reported.*App recommendation.*Reviewed recommendation/s);
  assert.match(await h.page.locator('[data-ai-model-defaults-note]').textContent(), /not saved yet/);
  assert.deepEqual(h.requests.map(r => r.path), ['/admin/ai/models/preview', '/admin/ai/models/import']);
  const call = await h.page.evaluate(() => window.calls[0]);
  assert.equal(call.credentials, 'same-origin'); assert.equal(call.cache, 'no-store'); assert.equal(call.redirect, 'error');
  await secure(h);
});

test('selecting recommendations is dirty until normal Save and Test; neither import nor selection activates', async t => {
  const h = await open(t, { saved: true, version, base_url: baseURL, model: 'old/model', has_api_key: true,
    connection_tested: true }, catalog());
  await h.page.locator('[data-ai-model-select]').selectOption('org/model');
  assert.equal(h.requests.length, 0);
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  const settings = render({ saved: true, version: 'b'.repeat(32), base_url: baseURL, model: 'org/model',
    has_api_key: true, ...row().defaults }).settings;
  h.respond = () => ok({ settings });
  await click(h, '[data-ai-settings-form="save"] button[type="submit"]');
  const body = Object.fromEntries(new URLSearchParams(h.requests[0].body));
  assert.equal(body.model, 'org/model');
  for (const [key, value] of Object.entries(row().defaults)) assert.equal(body[key], String(value));
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), false);
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), true);
  h.respond = () => ok({ settings: { ...settings, connection_tested: true } });
  await click(h, '[data-ai-settings-test]');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(h.requests[1].body)), { csrf_fixture: 'fresh-token', version: settings.version });
  assert.equal(await h.page.locator('[data-ai-settings-enable]').isDisabled(), false);
  assert.equal(await h.page.locator('[data-ai-settings-form="disable"]').isHidden(), true);
});

test('saved key discovery sends blank key plus version; unsaved auth and private-network options use explicit flags', async t => {
  const h = await open(t, { saved: true, version, base_url: baseURL, model: 'old/model', has_api_key: true });
  await fetchModels(h);
  let body = Object.fromEntries(new URLSearchParams(h.requests[0].body));
  assert.equal(body.api_key, ''); assert.equal(body.version, version); assert.equal(body.remove_api_key, '0');
  await h.page.locator('[data-ai-settings-form="save"] > details').evaluate(el => { el.open = true; });
  await h.page.locator('[name="auth_mode"]').selectOption('none');
  await h.page.locator('[name="remove_api_key"]').check();
  await h.page.locator('[name="allow_private_network"]').check();
  await fetchModels(h);
  body = Object.fromEntries(new URLSearchParams(h.requests[1].body));
  assert.equal(body.auth_mode, 'none'); assert.equal(body.remove_api_key, '1'); assert.equal(body.allow_private_network, '1');
  assert.equal(body.model, undefined); assert.equal(body.max_input_tokens, undefined);
  await secure(h);
});

test('import all sends empty IDs; status and SSR hydrate persisted catalogs without selecting or overriding settings', async t => {
  const h = await open(t, { base_url: baseURL }, catalog([row('retired/model')]));
  h.respond = r => r.path.endsWith('/preview') ? ok({ preview: preview([row(), row('org/other')]) }) : ok({ catalog: catalog([row(), row('org/other')]) });
  await fetchModels(h);
  await h.page.locator('[data-ai-model-preview-search]').fill('org/other');
  assert.equal(await h.page.locator('[data-ai-model-preview-list] > div:visible').count(), 1);
  const help = await h.page.locator('[data-ai-model-import-all]').locator('..').textContent();
  assert.match(help, /Import all replaces the imported catalog with this preview; removes retired entries/);
  assert.match(help, /Import selected adds to the existing catalog/);
  assert.match(help, /Import all includes every model in this preview, not just matches/);
  await click(h, '[data-ai-model-import-all]');
  assert.deepEqual(await h.page.locator('[data-ai-model-select] option').evaluateAll(options => options.map(option => option.value)), ['', 'org/model', 'org/other']);
  const body = Object.fromEntries(new URLSearchParams(h.requests[1].body));
  assert.equal(body.import_all, '1'); assert.equal(body.model_ids, '[]'); assert.equal(body.preview_token, token);
  h.fixture = render({ base_url: baseURL, model: 'manual-saved', max_output_tokens: 1234 }, catalog([row(), row('org/other')]));
  await h.page.reload();
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 3);
  assert.equal(await h.page.locator('[data-ai-model-select]').inputValue(), '');
  assert.equal(await h.page.locator('[name="max_output_tokens"]').inputValue(), '1234');
  const settings = render({ base_url: baseURL, model: 'status-manual' }).settings;
  h.respond = () => ok({ settings, catalog: catalog([row('org/status')]) });
  await click(h, 'button:text-is("Reload saved settings")');
  assert.equal(await h.page.locator('[data-ai-model-select] option').last().getAttribute('value'), 'org/status');
  // Older status fixtures may omit catalog entirely.
  h.respond = () => ok({ settings }); await click(h, 'button:text-is("Reload saved settings")');
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 2);
  await secure(h);
});

test('empty compatible-model preview explains minimum budget, disables imports and keeps manual ID available', async t => {
  const h = await open(t, { base_url: baseURL });
  h.respond = () => ok({ preview: preview([]) });
  await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), true);
  assert.match(await h.page.locator('[data-ai-model-preview]').textContent(), /Only models compatible with minimum app budget are listed; manual ID remains available/);
  assert.match(await h.page.locator('[data-ai-model-preview-state]').textContent(), /No models compatible with the minimum app budget were returned/);
  assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /Enter a manual exact model ID/);
  assert.equal(await h.page.locator('[data-ai-model-import-selected]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-model-import-all]').isDisabled(), true);
  assert.equal(await h.page.locator('[name="model"]').isEnabled(), true);
  await h.page.locator('[name="model"]').fill('manual:opaque-model');
  await h.page.locator('[data-ai-model-import-all]').dispatchEvent('click');
  assert.equal(h.requests.length, 1, 'Even synthetic import-all clicks cannot import an empty preview');
});

test('unknown capabilities stay unknown; manual overrides survive import and preview; exact path matching isolates endpoints', async t => {
  const unknown = row('unknown', { context_window: null, max_output_tokens: null, supports_thinking: null, sources: { context_window: 'unknown', max_output_tokens: 'unknown', supports_thinking: 'unknown' }, warnings: ['Verify with your provider.'] });
  const h = await open(t, { base_url: baseURL + '/' }, catalog([unknown]));
  await h.page.locator('[data-ai-model-select]').selectOption('unknown');
  assert.match(await h.page.locator('[data-ai-model-details]').textContent(), /Context window: Unknown.*Thinking support: Unknown/s);
  await h.page.locator('[name="max_output_tokens"]').fill('777');
  await h.page.locator('[name="model"]').fill('manual/exact');
  await h.page.locator('[name="base_url"]').fill('https://gateway.example.test/other/v1');
  assert.equal(await h.page.locator('[data-ai-model-select]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 1);
  await h.page.locator('[name="base_url"]').fill(baseURL);
  await fetchModels(h);
  await h.page.locator('[name="max_input_tokens"]').fill('9000');
  await h.page.locator('[name="model"]').fill('manual/exact-2');
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), true);
  h.respond = () => ok({ catalog: catalog() }); await click(h, '[data-ai-model-import-all]');
  assert.equal(await h.page.locator('[name="max_output_tokens"]').inputValue(), '777');
  assert.equal(await h.page.locator('[name="max_input_tokens"]').inputValue(), '9000');
  assert.equal(await h.page.locator('[name="model"]').inputValue(), 'manual/exact-2');
});

test('each connection edit invalidates preview, unlike manual options; expired preview never posts import', async t => {
  const h = await open(t, { base_url: baseURL });
  for (const name of ['base_url', 'api_key', 'remove_api_key', 'auth_mode', 'allow_private_network']) {
    await fetchModels(h);
    assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), true);
    await h.page.locator(`[name="${name}"]`).dispatchEvent('input');
    assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false, name);
    assert.equal(await h.page.locator('[data-ai-model-import-all]').isDisabled(), true);
  }
  await fetchModels(h); const count = h.requests.length;
  await h.page.clock.fastForward(610000);
  await click(h, '[data-ai-model-import-all]');
  assert.equal(h.requests.length, count);
  assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /expired/);
});

test('stale connection aborts in-flight fetch; busy/reentrant actions and SPA teardown cannot replay or apply late results', async t => {
  const h = await open(t, { base_url: baseURL });
  let release; const gate = new Promise(resolve => { release = resolve; }); t.after(() => release());
  h.respond = async () => { await gate; return ok({ preview: preview() }); };
  await h.page.locator('[data-ai-model-fetch]').click();
  // A fetch invocation can precede route delivery; observe delivery before testing no replay.
  await waitForRequests(h, 1);
  await h.page.evaluate(() => {
    window.SynapseAiSettings.init();
    document.querySelector('[data-ai-model-fetch]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    const input = document.querySelector('[name="base_url"]'); input.value += '/changed'; input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await idle(h); assert.equal(h.requests.length, 1);
  assert.equal(await h.page.evaluate(() => window.calls[0].aborted), true);
  release();
  await h.page.locator('[name="base_url"]').fill(baseURL);
  let release2; const gate2 = new Promise(resolve => { release2 = resolve; }); t.after(() => release2());
  h.respond = async () => { await gate2; return ok({ preview: preview() }); };
  await h.page.locator('[name="api_key"]').fill(secret);
  await h.page.locator('[data-ai-model-fetch]').click();
  await waitForRequests(h, 2);
  await h.page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('[data-ai-settings]') } })));
  assert.equal(await h.page.locator('[name="api_key"]').inputValue(), '');
  assert.equal(await h.page.evaluate(() => window.calls[1].aborted), true);
  release2(); await secure(h);
  assert.equal(h.requests.length, 2, 'Neither cancelled request is replayed');
});

test('hostile metadata is inert in SSR and preview; bounded 200-row metadata responses exceed old 32KB limit safely', async t => {
  const attack = '<img src="https://evil.test/x" onerror="window.pwned=1">';
  const hostile = row('hostile', { name: attack, warnings: [attack] });
  const h = await open(t, { base_url: baseURL }, catalog([hostile]));
  assert.equal(await h.page.locator('[data-ai-model-select] option').last().textContent(), attack + ' — hostile');
  h.respond = () => ok({ preview: preview([hostile]) }); await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview-list] img').count(), 0);
  await h.page.locator('[data-ai-model-preview-list] summary').click();
  await h.page.waitForFunction(() => document.querySelector('[data-ai-model-preview-list] details p'));
  assert.ok((await h.page.locator('[data-ai-model-preview-list] details').textContent()).includes(attack));
  assert.equal(await h.page.locator('[data-ai-model-preview-list] img').count(), 0);
  assert.equal(await h.page.evaluate(() => window.pwned), undefined);
  const rows = Array.from({ length: 200 }, (_, i) => row('model-' + i));
  assert.ok(JSON.stringify(rows).length > 32768);
  h.respond = () => ok({ preview: preview(rows) }); await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview-list] input').count(), 200);
  h.respond = () => ok({ preview: preview([...rows, row('overflow')]) }); await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false);
  h.respond = () => ok({ preview: preview(), padding: 'x'.repeat(524288) }); await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false);
  await secure(h);
});

test('fixed same-origin action allowlist rejects hostile endpoints and no automatic retry follows uncertain imports', async t => {
  const h = await open(t, { base_url: baseURL });
  for (const url of ['https://evil.test/admin/ai/models/preview', '/admin/ai/models/preview?key=x', '/admin/ai/models/arbitrary']) {
    await h.page.locator('[data-ai-settings]').evaluate((el, url) => { el.dataset.modelPreviewUrl = url; }, url);
    await fetchModels(h); assert.equal(h.requests.length, 0);
  }
  await h.page.locator('[data-ai-settings]').evaluate(el => { el.dataset.modelPreviewUrl = '/admin/ai/models/preview'; });
  await fetchModels(h);
  h.respond = () => 'network-error'; await click(h, '[data-ai-model-import-all]');
  assert.equal(h.requests.length, 2);
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false);
  assert.match(await h.page.locator('[data-ai-settings-feedback]').textContent(), /No automatic retry/);
});

for (const [status, reason, message] of [
  [401, 'provider_auth_failed', 'The provider rejected the API key. Check the key and try again.'],
  [422, 'models_unsupported', 'This endpoint does not support model discovery. Enter an exact model ID manually.'],
  [429, 'catalog_rate_limited', 'Too many discovery requests. Wait a minute before fetching models again.'],
]) {
  test(`confirmed ${reason} shows actionable feedback without browser login or retry`, async t => {
    const h = await open(t, { base_url: baseURL });
    await h.page.locator('[name="api_key"]').fill(secret);
    h.respond = () => json({ success: false, reason, message, provider_body: secret,
      csrf: { name: 'csrf_fixture', hash: 'error-token' } }, status);
    await fetchModels(h);
    assert.equal(await h.page.locator('[data-ai-settings-feedback]').textContent(), message);
    assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false);
    assert.equal(await h.page.locator('[data-ai-model-fetch]').isEnabled(), true);
    assert.equal(await h.page.locator('[name="api_key"]').inputValue(), secret);
    assert.equal(await h.page.locator('meta[name="csrf-token"]').getAttribute('content'), 'error-token');
    assert.equal(h.page.url(), origin + '/admin/ai');
    assert.deepEqual(h.authEvents, []);
    assert.equal(await h.page.evaluate(() => window.SynapseAuth.redirecting), false);
    assert.equal(h.requests.length, 1);
    await secure(h);
  });
}

test('confirmed import errors use safe messages and discard consumed or invalid previews', async t => {
  const h = await open(t, { base_url: baseURL });
  await fetchModels(h);
  const message = 'The model preview has expired. Fetch models again before importing.';
  h.respond = () => json({ success: false, reason: 'catalog_preview_expired', message }, 409);
  await click(h, '[data-ai-model-import-all]');
  assert.equal(await h.page.locator('[data-ai-settings-feedback]').textContent(), message);
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false);
  assert.equal(h.requests.length, 2);
});

test('case-distinct IDs import and select independently while exact duplicates are rejected', async t => {
  const models = [row('ModelA'), row('modela', { defaults: { ...row().defaults, max_output_tokens: 1234 } })];
  const h = await open(t, { base_url: baseURL });
  h.respond = r => r.path.endsWith('/preview') ? ok({ preview: preview(models) }) : ok({ catalog: catalog(models) });
  await fetchModels(h);
  await h.page.locator('[data-ai-model-preview-list] input').nth(0).check();
  await h.page.locator('[data-ai-model-preview-list] input').nth(1).check();
  await click(h, '[data-ai-model-import-selected]');
  assert.deepEqual(JSON.parse(new URLSearchParams(h.requests[1].body).get('model_ids')), ['ModelA', 'modela']);
  for (const model of models) {
    await h.page.locator('[data-ai-model-select]').selectOption(model.id);
    assert.equal(await h.page.locator('[name="model"]').inputValue(), model.id);
    assert.equal(await h.page.locator('[name="max_output_tokens"]').inputValue(), String(model.defaults.max_output_tokens));
  }
  h.fixture = render({ base_url: baseURL }, catalog(models));
  await h.page.reload(); await idle(h);
  assert.deepEqual(await h.page.locator('[data-ai-model-select] option').evaluateAll(options => options.map(option => option.value)), ['', 'ModelA', 'modela']);
  h.respond = () => ok({ preview: preview([models[0], models[0]]) });
  await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview]').isVisible(), false);
});

test('173-model catalogs have lazy collapsed metadata and local filters that preserve selections and settings', async t => {
  const models = Array.from({ length: 173 }, (_, i) => row(`model-${i}`));
  const payload = ok({ preview: preview(models) });
  assert.ok(Buffer.byteLength(payload.body) < 524288);
  const h = await open(t, { saved: true, version, base_url: baseURL, model: 'manual:active' }, catalog(models));
  h.respond = () => payload;
  await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview-list] details:not([open])').count(), 173);
  assert.equal(await h.page.locator('[data-ai-model-preview-list] p').count(), 0, 'Metadata is not built until expanded');
  await h.page.locator('[data-ai-model-preview-list] summary').first().click();
  await h.page.waitForFunction(() => document.querySelector('[data-ai-model-preview-list] details p'));
  const count = await h.page.locator('[data-ai-model-preview-list] p').count();
  await h.page.locator('[data-ai-model-preview-list] summary').first().click();
  await h.page.locator('[data-ai-model-preview-list] summary').first().click();
  assert.equal(await h.page.locator('[data-ai-model-preview-list] p').count(), count);
  await h.page.locator('[data-ai-model-preview-list] input').first().check();
  await h.page.locator('[data-ai-model-preview-search]').fill('MODEL-172');
  assert.equal(await h.page.locator('[data-ai-model-preview-list] > div:visible').count(), 1);
  await h.page.locator('[data-ai-model-preview-list] > div:visible input').check();
  assert.match(await h.page.locator('[data-ai-model-preview-count]').textContent(), /1 of 173 shown · 2 selected/);
  await h.page.locator('[data-ai-model-catalog-search]').fill('MODEL-172');
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 2);
  await h.page.locator('[data-ai-model-catalog-search]').fill('no-such-model');
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 1);
  assert.match(await h.page.locator('[data-ai-model-catalog-count]').textContent(), /0 of 173/);
  await h.page.locator('[data-ai-model-catalog-search]').fill('');
  assert.equal(await h.page.locator('[data-ai-model-select] option').count(), 174);
  assert.equal(await h.page.locator('[name="model"]').inputValue(), 'manual:active');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isEnabled(), true, 'Filtering does not dirty settings');
  assert.equal(h.requests.length, 1, 'Filtering is local');
  h.respond = () => ok({ catalog: catalog([models[0], models[172]]) });
  await click(h, '[data-ai-model-import-selected]');
  assert.deepEqual(JSON.parse(new URLSearchParams(h.requests[1].body).get('model_ids')), ['model-0', 'model-172']);
  await secure(h);
});

test('colon and Unicode model IDs round-trip as opaque strings through preview, import, selection and manual input', async t => {
  const id = '組織/模型:最新版-α';
  const h = await open(t, { base_url: baseURL });
  const model = row(id);
  h.respond = r => r.path.endsWith('/preview') ? ok({ preview: preview([model]) }) : ok({ catalog: catalog([model]) });
  await fetchModels(h);
  assert.equal(await h.page.locator('[data-ai-model-preview-list] input').inputValue(), id);
  await h.page.locator('[data-ai-model-preview-list] input').check();
  await click(h, '[data-ai-model-import-selected]');
  assert.deepEqual(JSON.parse(new URLSearchParams(h.requests[1].body).get('model_ids')), [id]);
  await h.page.locator('[data-ai-model-select]').selectOption(id);
  assert.equal(await h.page.locator('[name="model"]').inputValue(), id);
  await h.page.locator('[name="model"]').fill('別の:opaque:模型');
  assert.equal(await h.page.locator('[name="model"]').inputValue(), '別の:opaque:模型');
});

test('missing initial catalog and legacy discovery markup leave original settings controls functional', async t => {
  const h = await open(t, { saved: true, version, base_url: baseURL, model: 'manual:model' });
  assert.equal(await h.page.locator('[data-ai-model-select]').isDisabled(), true);
  assert.match(await h.page.locator('[data-ai-model-catalog-state]').textContent(), /No imported models/);
  h.fixture.html = h.fixture.html.replace(/<template data-ai-model-catalog[^>]*><\/template>/, '');
  await h.page.reload();
  await idle(h);
  assert.equal(await h.page.locator('[data-ai-model-select]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-settings-test]').isEnabled(), true);
  const start = h.fixture.html.indexOf('<div class="col-12" data-ai-model-discovery>');
  const end = h.fixture.html.indexOf('<div class="col-md-6"><label class="form-label" for="ai-model">');
  assert.ok(start >= 0 && end > start);
  h.fixture.html = h.fixture.html.slice(0, start) + h.fixture.html.slice(end);
  await h.page.reload();
  await idle(h);
  assert.equal(await h.page.locator('[data-ai-model-select]').count(), 0);
  await h.page.locator('[name="model"]').fill('manual:更新');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isDisabled(), true);
  assert.equal(await h.page.locator('[data-ai-settings-form="save"] button[type="submit"]').isEnabled(), true);
  h.respond = () => ok({ settings: render({ saved: true, version, base_url: baseURL, model: 'manual:更新' }).settings });
  await click(h, '[data-ai-settings-form="save"] button[type="submit"]');
  assert.equal(await h.page.locator('[data-ai-settings-test]').isEnabled(), true);
});

test('authentication denial clears password and delegates to shared auth lifecycle without replay', async t => {
  const h = await open(t, { base_url: baseURL });
  await h.page.locator('[name="api_key"]').fill(secret);
  h.respond = () => json({ success: false, reason: 'session_expired', message: 'Sign in again.' }, 401,
    { 'X-Synapse-Auth': 'authentication_required', 'X-Synapse-Login': '/auth/login', 'X-CSRF-TOKEN': 'must-not-sync' });
  await h.page.locator('[data-ai-model-fetch]').click();
  await h.page.waitForURL(origin + '/auth/login');
  // The exposed callback crosses the browser/Node boundary independently of navigation.
  const deadline = performance.now() + 8000;
  while (!h.authEvents.length && performance.now() < deadline) await delay(20);
  assert.equal(h.requests.length, 1);
  assert.deepEqual(h.authEvents, [{ reason: 'authentication_required', key: '', csrf: 'initial-token' }]);
});
