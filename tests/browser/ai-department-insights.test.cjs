'use strict';

// Real PHP views + page-owned JS, synthetic protected aggregates, intercepted requests only.
// No listening socket, application bootstrap, dotenv, database, or AI provider.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const origin = 'http://127.0.0.1:47991';
const sources = Object.fromEntries(['ai-insights', 'toast'].map(name =>
  [`/assets/js/${name}.js`, fs.readFileSync(path.join(root, `public/assets/js/${name}.js`), 'utf8')]));
sources['/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'] = fs.readFileSync(path.join(root, 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), 'utf8');
let browser;

function render(options) {
  const result = spawnSync('php', ['tests/_support/render-ai-department-fixture.php'], {
    cwd: root, input: JSON.stringify(options), encoding: 'utf8', timeout: 10000,
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.equal(result.stderr, '');
  return JSON.parse(result.stdout);
}
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

async function open(t, options) {
  const fixture = render({ insight: true, jobStatus: 'queued', ...options });
  const base = '/' + (options.unit === 'bmg' ? 'facilities' : options.unit) + '/insights';
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  const h = { page, base, fixture, panel: fixture.panel, requests: [], unexpected: [], errors: [], fail: false, rejection: null }; 
  page.on('pageerror', error => h.errors.push(error.message));
  t.after(async () => {
    await context.close();
    assert.deepEqual(h.unexpected, []);
    assert.deepEqual(h.errors, []);
  });
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) { h.unexpected.push(url.href); return route.abort(); }
    if (request.isNavigationRequest() && url.pathname === base && request.method() === 'GET') {
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html><head><meta charset="utf-8"><script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="/assets/js/toast.js"></script></head><body>' + fixture.index + '</body></html>' });
    }
    if (sources[url.pathname]) return route.fulfill({ contentType: 'text/javascript', body: sources[url.pathname] });
    if (url.pathname.startsWith(base + '/')) {
      h.requests.push({ path: url.pathname, method: request.method(), body: request.postData() });
      if (h.fail && request.method() === 'POST') return route.abort('failed');
      if (h.rejection && request.method() === 'POST') return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify(h.rejection) });
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(request.method() === 'GET'
        ? { success: true, html: h.panel }
        : { success: true, message: 'Synthetic action saved.', baseline: fixture.baseline }) });
    }
    h.unexpected.push(request.method() + ' ' + url.href);
    return route.abort();
  });
  await page.clock.install();
  await page.clock.pauseAt(new Date(Date.now() + 1000));
  await page.goto(origin + base);
  await page.waitForFunction(() => Boolean(window.SynapseAiInsights && window.SynapseToast));
  await page.evaluate(() => {
    window.fixtureNotices = [];
    const show = window.SynapseToast.show;
    window.SynapseToast.show = (message, options) => {
      window.fixtureNotices.push({ message, ...options });
      return show(message, options);
    };
  });
  return h;
}
async function notice(h, type, message) {
  await h.page.clock.runFor(50);
  const toast = h.page.locator('#synapse-toast-container .synapse-toast');
  assert.equal(await toast.count(), 1);
  assert.equal(await toast.isVisible(), true);
  assert.equal(await toast.getAttribute('data-type'), type);
  assert.match(await toast.locator('.synapse-toast-message').innerText(), message);
  assert.equal(await h.page.locator('[data-ai-feedback]').isVisible(), false);
  assert.match(await h.page.locator('[data-ai-feedback]').textContent(), message);
}
async function submit(h, selector) {
  const before = h.requests.filter(r => r.method === 'POST').length;
  // aria-busy clears before the completion refresh starts; wait for the actual status response too.
  const [response] = await Promise.all([
    h.page.waitForResponse(response => response.request().method() === 'GET' &&
      new URL(response.url()).pathname === h.base + '/panel'),
    h.page.locator(selector + ' button').click(),
  ]);
  assert.equal(await response.finished(), null, 'Post-action status response must finish');
  await h.page.waitForFunction(() => !document.querySelector('[data-ai-workspace]').hasAttribute('aria-busy'));
  assert.equal(h.requests.filter(r => r.method === 'POST').length, before + 1);
}

for (const [unit, analysis] of [['clinic', 'clinic.complaints'], ['guidance', 'guidance.themes'], ['bmg', 'bmg.rotations']]) {
  test(unit + ': fixed analyses and all actions stay on the workstation', { timeout: 30000 }, async t => {
    const h = await open(t, { unit, analysis });
    assert.equal(h.requests.length, 0, 'Reads never prepare or dispatch automatically');
    const forms = await h.page.locator('form').evaluateAll(forms => forms.map(form => ({
      path: new URL(form.action).pathname,
      fields: [...new FormData(form).keys()],
      analysis: new FormData(form).get('analysis_code'),
    })));
    const codes = unit === 'clinic' ? ['clinic.activity', 'clinic.complaints']
      : unit === 'guidance' ? ['guidance.activity', 'guidance.themes'] : ['bmg.batches', 'bmg.rotations'];
    for (const form of forms) {
      assert.ok(form.path.startsWith(h.base + '/'));
      assert.ok(!form.fields.some(key => ['unit', 'department', 'tenant_id', 'tenant'].includes(key)));
    }
    assert.deepEqual(forms.filter(f => f.path.endsWith('/prepare')).map(f => f.analysis), codes);
    for (const action of ['prepare', 'generate']) {
      const form = `form[action$="/${action}"]:has(input[value="${analysis}"])`;
      await submit(h, form);
      await notice(h, action === 'prepare' ? 'success' : 'info', /Synthetic action saved/);
      assert.equal(await h.page.locator('[data-ai-baseline]').isVisible(), true);
      const request = h.requests.filter(r => r.method === 'POST').at(-1);
      assert.equal(request.path, h.base + '/' + action);
      assert.deepEqual(Object.fromEntries(new URLSearchParams(request.body)), { csrf_fixture: 'initial-token', analysis_code: analysis });
    }
    await submit(h, 'form[action$="/47/review"]:has(input[value="accept"])');
    await submit(h, 'form[action$="/jobs/31/cancel"]');
    assert.ok(h.requests.every(r => r.path.startsWith(h.base + '/')));
    assert.equal(await h.page.locator('[data-ai-baseline] h4').count(), h.fixture.evidence.metrics.length);
    assert.match(await h.page.locator('[data-ai-baseline]').innerText(), /Hidden or missing values are not zero/);
    assert.equal(h.page.url(), origin + h.base);
  });
}

for (const unit of ['clinic', 'guidance', 'bmg']) {
  test(unit + ': realtime notifications refetch only the matching department', { timeout: 30000 }, async t => {
    const h = await open(t, { unit });
    async function notify(selected) {
      await h.page.evaluate(selected => document.dispatchEvent(new CustomEvent('synapse:realtime-event', {
        detail: { event: 'ai.insights.changed', data: { unit: selected, draft: 'unvalidated realtime content' } },
      })), selected);
      await h.page.clock.runFor(251);
    }
    await notify(unit === 'clinic' ? 'guidance' : 'clinic');
    assert.equal(h.requests.length, 0);
    const response = h.page.waitForResponse(response => new URL(response.url()).pathname === h.base + '/panel');
    await notify(unit);
    await (await response).finished();
    assert.deepEqual(h.requests.map(r => [r.method, r.path]), [['GET', h.base + '/panel']]);
    assert.doesNotMatch(await h.page.locator('[data-ai-panel]').innerText(), /unvalidated realtime content/);
    await h.page.clock.runFor(50);
    assert.equal(await h.page.locator('.synapse-toast').count(), 0, 'Background hints must not create notifications');
        assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 0);
  });
}

for (const unit of ['clinic', 'guidance', 'bmg']) {
  test(unit + ': realtime progress uses persisted stages without partial drafts', { timeout: 30000 }, async t => {
    const h = await open(t, { unit, insight: false });
    const request = h.page.locator('[data-ai-request="31"]');
    assert.equal(await request.getAttribute('data-ai-progress'), 'queued');
    assert.equal(await request.locator('[data-ai-progress-spinner]').count(), 0);
    for (const [status, stage] of [['running', 'generating'], ['succeeded', 'ready']]) {
      h.panel = render({ unit, jobStatus: status, insight: status === 'succeeded' }).panel;
      const response = h.page.waitForResponse(response => new URL(response.url()).pathname === h.base + '/panel');
      await h.page.evaluate(unit => document.dispatchEvent(new CustomEvent('synapse:realtime-event', {
        detail: { event: 'ai.insights.changed', data: { unit, draft: 'unvalidated realtime content' } },
      })), unit);
      await h.page.clock.runFor(251);
      await (await response).finished();
      await h.page.waitForFunction(stage => document.querySelector('[data-ai-request="31"]').dataset.aiProgress === stage, stage);
      assert.equal(await request.locator('[data-ai-progress-spinner]').count(), status === 'running' ? 1 : 0);
      assert.equal(await request.locator('[aria-current="step"]').getAttribute('data-ai-stage'), stage);
      assert.equal(await request.locator('form').count(), 0);
      assert.equal(await h.page.locator('[data-ai-panel] article').count(), status === 'succeeded' ? 1 : 0);
    }
    assert.doesNotMatch(await h.page.locator('[data-ai-panel]').innerText(), /unvalidated realtime content/);
    assert.equal(await request.locator('[aria-live], [aria-valuenow]').count(), 0);
    assert.equal(await h.page.evaluate(() => window.fixtureNotices.length), 0);
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 0);
  });
}

for (const unit of ['clinic', 'guidance', 'bmg']) {
  test(unit + ': insufficient data warns without implying a failed connection or retry', { timeout: 30000 }, async t => {
    const h = await open(t, { unit });
    const records = unit === 'bmg' ? 'batch records or accepted rotation reports' : 'eligible visits or sessions recorded as completed';
    const message = `No comparison is available yet. Both completed months need eligible records. Check ${records}. Unavailable values are not zero. This is not an AI connection error.`;
    h.rejection = { success: false, reason: 'insufficient_data', feedback_type: 'warning', message };
    await submit(h, 'form[action$="/prepare"]:has(input[value="' + (unit === 'clinic' ? 'clinic.activity' : unit === 'guidance' ? 'guidance.activity' : 'bmg.batches') + '"])');
    await notice(h, 'warning', /Check .*not an AI connection error/);
    assert.equal(await h.page.locator('.synapse-toast-message').textContent(), message);
    assert.doesNotMatch(await h.page.locator('.synapse-toast-message').textContent(), /could not confirm|automatic retry|Refresh status/);
    await h.page.clock.runFor(16000);
    await notice(h, 'warning', /No comparison is available yet/);
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
    assert.ok(h.requests.some(r => r.method === 'GET'));
  });
}

test('v2 evidence shows released category counts, denominators and shares without inventing suppressed cells', { timeout: 30000 }, async t => {
  const h = await open(t, { unit: 'guidance', analysis: 'guidance.themes' });
  await h.page.locator('details').evaluateAll(details => details.forEach(detail => { detail.open = true; }));
  const text = await h.page.locator('[data-ai-panel]').innerText();
  assert.equal(await h.page.locator('section[aria-label="Academic"]').count(), 1);
  assert.equal(await h.page.locator('section[aria-label="Career"]').count(), 0, 'Suppressed category is not rendered as zero');
  assert.match(text, /Completed sessions/);
  assert.match(text, /denominator/);
  assert.match(text, /50\.00%/);
  assert.match(text, /percentage points/);
  assert.match(text, /not diagnoses, severity, outcomes/);
  assert.match(text, /AI can make mistakes.*not a clinical finding/);
});

test('zero denominators remain undefined and all BMG metrics render', { timeout: 30000 }, async t => {
  const h = await open(t, { unit: 'bmg', analysis: 'bmg.rotations' });
  assert.equal(await h.page.locator('[data-ai-panel] section[aria-label="Reported failures"]').count(), 1);
  assert.equal(await h.page.locator('[data-ai-panel] section[aria-label="Reported interruptions"]').count(), 1);
  const text = await h.page.locator('section[aria-label="Reported failures"]').innerText();
  assert.match(text, /20\.00%/);
  assert.match(text, /Not defined/);
  assert.match(text, /2026-08 · total for share\s+0/);
  assert.doesNotMatch(text, /2026-08 · share\s+0\.00%/);
});

test('same-origin cross-unit, wrong-action, malformed and external endpoints are blocked without POST replay', { timeout: 30000 }, async t => {
  const h = await open(t, { unit: 'guidance' });
  const unsafe = ['/clinic/insights/prepare', '/facilities/insights/generate', h.base + '/panel', h.base,
    h.base + '/0/review', h.base + '/prepare?unit=clinic', h.base + '/generate#fragment',
    'https://example.invalid/guidance/insights/prepare', origin.replace('http://', 'http://user:pass@') + h.base + '/prepare'];
  for (const action of unsafe) {
    const before = h.requests.filter(r => r.method === 'POST').length;
    await h.page.locator('form[data-ai-action]').first().evaluate((form, action) => {
      form.action = action;
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }, action);
    await h.page.waitForFunction(() => !document.querySelector('[data-ai-workspace]').hasAttribute('aria-busy'));
    assert.equal(h.requests.filter(r => r.method === 'POST').length, before);
    await notice(h, 'warning', /Check Refresh status.*No automatic retry/);
  }
  await h.page.locator('[data-ai-workspace]').evaluate(root => { root.dataset.panelUrl = '/clinic/insights/panel'; });
  const before = h.requests.length;
  await h.page.locator('[data-ai-refresh]').click();
  assert.equal(h.requests.length, before, 'Cross-unit status read is also blocked');
});

test('an uncertain departmental mutation is never automatically retried', { timeout: 30000 }, async t => {
  const h = await open(t, { unit: 'bmg' });
  h.fail = true;
  await submit(h, 'form[action$="/generate"]:has(input[value="bmg.batches"])');
  await h.page.clock.runFor(16000);
  assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
  await notice(h, 'warning', /Check Refresh status.*No automatic retry/);
  assert.equal(await h.page.locator('.synapse-toast-countdown').isVisible(), false);
  assert.ok(h.requests.some(r => r.method === 'GET'), 'Unknown write reconciles status only');
});

test('v2 evidence and departmental output escape hostile synthetic text', { timeout: 30000 }, async t => {
  const h = await open(t, { unit: 'guidance', analysis: 'guidance.themes', hostile: true });
  assert.equal(await h.page.locator('[data-ai-workspace] script, [data-ai-workspace] img').count(), 0);
  assert.equal(await h.page.evaluate(() => window.aiFixtureXss), undefined);
  assert.match(await h.page.locator('[data-ai-panel]').innerText(), /<script>/);
});
