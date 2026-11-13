'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { once } = require('node:events');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
let browser;
let server;
let origin;
let nextId = 0;
const cases = new Map();

function send(res, status, type, body, headers = {}) {
  res.writeHead(status, { 'Content-Type': type, ...headers });
  res.end(body);
}

function pageHtml(state, options = {}) {
  const scope = options.scope || 'refresh-regions-fixture';
  const version = options.version ?? state.version;
  const record = options.record || `Record ${version}`;
  const summary = options.summary || `Summary ${version}`;
  const owner = state.owner;
  const regions = options.regions === false ? '' : `
    <section data-refresh-owner="${owner}" data-refresh-url="/case/${state.id}">
      <h2 data-refresh-heading tabindex="-1">Records</h2>
      <div data-refresh-region="records"><div data-refresh-focus-key="record-focus"><button id="record-action">${record}</button>${options.unsafe ? '<script>window.incomingScriptRan = true</script>' : ''}</div></div>
      <div data-refresh-region="summary"><p>${summary}</p></div>
    </section>`;
  return `<!doctype html><html><head><title>Regions ${version}</title>
    <meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="token-${version}">
    <meta name="csrf-header" content="X-Fixture-CSRF">
    <meta name="synapse-refresh-regions-enabled" content="1">
    <meta name="synapse-refresh-region-owners" content="${owner}">
    </head><body data-navigation-scope="${scope}">
    <header id="header">Header</header><aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link active" href="/case/${state.id}">Records</a></nav></aside>
    <main id="main"><div class="main-content"><section class="section">${regions}</section></div></main>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/loading.js"></script><script src="/assets/js/csrf.js"></script><script src="/assets/js/toast.js"></script>
    <script src="/assets/js/navigation.js"></script><script src="/assets/js/page-lifecycle.js"></script><script src="/assets/js/refresh-regions.js"></script>
    <div id="page-styles" hidden></div><div id="page-scripts" hidden></div></body></html>`;
}

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://fixture.local');
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, '.' + url.pathname);
      if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file)) return send(res, 404, 'text/plain', 'Missing asset');
      return send(res, 200, 'text/javascript', fs.readFileSync(file));
    }
    const state = cases.get(url.pathname.split('/')[2]);
    if (!state) return send(res, 404, 'text/plain', 'Unknown fixture');
    state.reads++;
    if (state.mode === 'failure') return send(res, 503, 'text/plain', 'Unavailable');
    if (state.mode === 'nonhtml') return send(res, 200, 'application/json', '{}');
    if (state.mode === 'denied') return send(res, 403, 'text/html', pageHtml(state));
    send(res, 200, 'text/html', pageHtml(state, {
      scope: state.mode === 'scope' ? 'changed-scope' : undefined,
      regions: state.mode === 'missing' ? false : undefined,
      unsafe: state.mode === 'unsafe',
    }), { 'X-CSRF-TOKEN': state.headerToken || `token-${state.version}`, 'X-CSRF-NAME': 'fixture_csrf' });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true });
});

after(async () => {
  await browser?.close();
  if (server?.listening) {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
});

async function fixture(t, options = {}) {
  const id = String(++nextId);
  const state = { id, owner: `records.fixture-${id}`, version: 0, mode: 'ok', reads: 0, ...options };
  cases.set(id, state);
  const context = await browser.newContext({ viewport: { width: 1100, height: 800 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.stack || error.message));
  t.after(async () => {
    await context.close();
    cases.delete(id);
    assert.deepEqual(errors, [], 'No browser exceptions');
  });
  await page.goto(`${origin}/case/${id}`);
  await page.waitForFunction(() => Boolean(window.SynapseRefreshRegions));
  return { page, state };
}

async function refresh(page, state, regions = ['records', 'summary']) {
  return page.evaluate(({ owner, regions }) => SynapseRefreshRegions.refresh({
    owner, regions, url: location.href, cause: 'test', history: 'none',
  }), { owner: state.owner, regions });
}

test('claimed full-document refresh preserves shell, main, owner and region identity while replacing only region children', async t => {
  const { page, state } = await fixture(t);
  await page.evaluate(() => {
    window.identities = {
      body: document.body, header: document.querySelector('#header'), sidebar: document.querySelector('#sidebar'),
      main: document.querySelector('#main'), owner: document.querySelector('[data-refresh-owner]'),
      records: document.querySelector('[data-refresh-region="records"]'),
      summary: document.querySelector('[data-refresh-region="summary"]'),
      oldRecord: document.querySelector('#record-action'),
    };
  });
  state.version = 1;
  const result = await refresh(page, state);
  assert.equal(result.status, 'refreshed');
  assert.deepEqual(await page.evaluate(() => ({
    body: identities.body === document.body,
    header: identities.header === document.querySelector('#header'),
    sidebar: identities.sidebar === document.querySelector('#sidebar'),
    main: identities.main === document.querySelector('#main'),
    owner: identities.owner === document.querySelector('[data-refresh-owner]'),
    records: identities.records === document.querySelector('[data-refresh-region="records"]'),
    summary: identities.summary === document.querySelector('[data-refresh-region="summary"]'),
    childReplaced: identities.oldRecord !== document.querySelector('#record-action'),
    record: document.querySelector('#record-action').textContent,
    summaryText: document.querySelector('[data-refresh-region="summary"]').textContent.trim(),
  })), { body: true, header: true, sidebar: true, main: true, owner: true, records: true,
    summary: true, childReplaced: true, record: 'Record 1', summaryText: 'Summary 1' });
});

test('one invalid member aborts an atomic multi-region refresh and claimed failure never replaces main', async t => {
  const { page, state } = await fixture(t);
  await page.evaluate(() => {
    window.mainIdentity = document.querySelector('#main');
    window.regionBefore = {
      records: document.querySelector('[data-refresh-region="records"]').innerHTML,
      summary: document.querySelector('[data-refresh-region="summary"]').innerHTML,
    };
  });
  state.version = 1;
  state.mode = 'missing';
  const result = await refresh(page, state);
  assert.equal(result.status, 'failed');
  assert.deepEqual(await page.evaluate(() => ({
    sameMain: document.querySelector('#main') === mainIdentity,
    records: document.querySelector('[data-refresh-region="records"]').innerHTML,
    summary: document.querySelector('[data-refresh-region="summary"]').innerHTML,
  })), { sameMain: true, records: await page.evaluate(() => regionBefore.records), summary: await page.evaluate(() => regionBefore.summary) });
});

test('unsafe incoming content is rejected without executing or changing any region', async t => {
  const { page, state } = await fixture(t);
  state.version = 1;
  state.mode = 'unsafe';
  const result = await refresh(page, state);
  assert.equal(result.status, 'failed');
  assert.equal(await page.evaluate(() => window.incomingScriptRan), undefined);
  assert.equal(await page.locator('#record-action').textContent(), 'Record 0');
});

test('network and non-HTML failures retain current regions and expose a failed result', async t => {
  const { page, state } = await fixture(t);
  for (const mode of ['failure', 'nonhtml']) {
    state.mode = mode;
    const result = await refresh(page, state);
    assert.equal(result.status, 'failed');
    assert.equal(await page.locator('#record-action').textContent(), 'Record 0');
  }
});

test('response header CSRF remains authoritative over fetched document metadata', async t => {
  const { page, state } = await fixture(t);
  state.version = 1;
  state.headerToken = 'header-token-1';
  const result = await refresh(page, state);
  assert.equal(result.status, 'refreshed');
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'header-token-1');
  assert.deepEqual(await page.evaluate(() => SynapseCsrf.headers()), { 'X-Fixture-CSRF': 'header-token-1' });
});

test('main navigation intent aborts a pending regional read before it can commit', async t => {
  const { page, state } = await fixture(t);
  let release;
  const held = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  await page.route(`**/case/${state.id}`, async route => {
    if ((await route.request().allHeaders())['x-synapse-refresh'] !== '1') return route.continue();
    await held;
    await route.fulfill({ contentType: 'text/html', body: pageHtml(state) }).catch(() => {});
  });
  await page.evaluate(({ owner }) => { window.pendingRegion = SynapseRefreshRegions.refresh({
    owner, regions: ['records'], url: location.href, cause: 'test', history: 'none',
  }); }, { owner: state.owner });
  await page.waitForRequest(request => request.headers()['x-synapse-refresh'] === '1');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-navigate', {
    cancelable: true, detail: { root: document.getElementById('main'), url: '/elsewhere' }
  })));
  release();
  assert.equal((await page.evaluate(() => window.pendingRegion)).status, 'superseded');
  assert.equal(await page.locator('#record-action').textContent(), 'Record 0');
});

test('invalid explicit mutation descriptor never downgrades to legacy main navigation', async t => {
  const { page } = await fixture(t);
  await page.evaluate(() => {
    window.legacyNavigations = 0;
    window.SynapseNavigation = {
      hasUnsavedChanges: () => false, markSaved: () => {},
      navigate: async () => { window.legacyNavigations++; return true; },
    };
  });
  const result = await page.evaluate(() => SynapseMutations.refresh(location.href, 'Saved', {
    descriptor: { mode: 'regions', owner: 'INVALID OWNER', regions: ['records'] }
  }));
  assert.equal(result, false);
  assert.equal(await page.evaluate(() => window.legacyNavigations), 0);
  assert.equal(await page.locator('.synapse-toast').getAttribute('data-type'), 'warning');
});

test('regional Back failure restores the current entry and retries only its GET', async t => {
  const { page, state } = await fixture(t);
  await page.evaluate(owner => {
    const regions = { owner, regions: ['records', 'summary'] };
    SynapseNavigation.updateHistory(location.pathname + '?page=1', 'replace', regions);
    SynapseNavigation.updateHistory(location.pathname + '?page=2', 'push', regions);
    window.historyMain = document.getElementById('main');
  }, state.owner);
  const historyLength = await page.evaluate(() => history.length);
  state.mode = 'failure';
  await page.evaluate(() => history.back());
  await page.waitForFunction(() => new URL(location.href).searchParams.get('page') === '2' && !document.getElementById('navigation-retry').hidden);
  assert.equal(await page.evaluate(() => window.historyMain === document.getElementById('main')), true);
  assert.equal(await page.locator('#record-action').textContent(), 'Record 0');
  const reads = state.reads;
  state.mode = 'ok';
  state.version = 1;
  await page.locator('#navigation-retry').click();
  await page.waitForFunction(() => new URL(location.href).searchParams.get('page') === '1' && document.getElementById('record-action').textContent === 'Record 1');
  assert.equal(state.reads, reads + 1);
  assert.equal(await page.evaluate(() => history.length), historyLength);
  assert.equal(await page.evaluate(() => window.historyMain === document.getElementById('main')), true);
});

test('regional Back preserves affected dirty drafts and a navigation veto prevents the read', async t => {
  const { page, state } = await fixture(t);
  await page.evaluate(owner => {
    const regions = { owner, regions: ['records', 'summary'] };
    SynapseNavigation.updateHistory(location.pathname + '?page=1', 'replace', regions);
    SynapseNavigation.updateHistory(location.pathname + '?page=2', 'push', regions);
    const form = document.createElement('form');
    form.method = 'post';
    form.innerHTML = '<input name="draft" value="server">';
    document.querySelector('[data-refresh-region="records"]').append(form);
    const input = form.querySelector('input');
    input.value = 'Keep this draft';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, state.owner);
  const reads = state.reads;
  await page.evaluate(() => history.back());
  await page.waitForFunction(() => new URL(location.href).searchParams.get('page') === '2' && !document.getElementById('navigation-retry').hidden);
  assert.equal(await page.locator('[name="draft"]').inputValue(), 'Keep this draft');
  assert.equal(state.reads, reads);
  await page.evaluate(() => {
    SynapseNavigation.markSaved(document.querySelector('[name="draft"]').form);
    document.addEventListener('synapse:before-navigate', event => event.preventDefault(), { once: true });
    history.back();
  });
  await page.waitForFunction(() => new URL(location.href).searchParams.get('page') === '2');
  assert.equal(state.reads, reads);
});

test('rapid regional Back then Forward cancels the historical read before commit', async t => {
  const { page, state } = await fixture(t);
  await page.evaluate(owner => {
    const regions = { owner, regions: ['records', 'summary'] };
    SynapseNavigation.updateHistory(location.pathname + '?page=1', 'replace', regions);
    SynapseNavigation.updateHistory(location.pathname + '?page=2', 'push', regions);
    window.historyMain = document.getElementById('main');
  }, state.owner);
  let release;
  const held = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  await page.route(`**/case/${state.id}?page=1`, async route => {
    await held;
    await route.fulfill({ contentType: 'text/html', body: pageHtml(state, { version: 99 }) }).catch(() => {});
  });
  const read = page.waitForRequest(request => new URL(request.url()).searchParams.get('page') === '1');
  await page.evaluate(() => history.back());
  await read;
  await page.evaluate(() => history.forward());
  await page.waitForFunction(() => new URL(location.href).searchParams.get('page') === '2');
  release();
  await page.waitForTimeout(100);
  assert.equal(await page.locator('#record-action').textContent(), 'Record 0');
  assert.equal(await page.evaluate(() => window.historyMain === document.getElementById('main')), true);
  assert.equal(await page.locator('#navigation-retry').isHidden(), true);
});

test('post-teardown lifecycle failure uses explicit document recovery instead of an ordinary retained-page failure', async t => {
  const { page, state } = await fixture(t);
  await page.evaluate(() => {
    window.recoveries = [];
    SynapseNavigation.requireFullDocument = (url, options) => { recoveries.push({ url, options }); return true; };
    SynapsePageLifecycle.init = () => { throw new Error('Synthetic lifecycle failure'); };
  });
  state.version = 1;
  const result = await refresh(page, state);
  assert.equal(result.status, 'security');
  assert.equal(result.recovery, 'document');
  assert.equal((await page.evaluate(() => recoveries)).length, 1);
});

test('disabled or unknown owner is unclaimed without issuing a read', async t => {
  const { page, state } = await fixture(t);
  const before = state.reads;
  const result = await page.evaluate(() => SynapseRefreshRegions.refresh({
    owner: 'records.unknown', regions: ['records'], url: location.href,
  }));
  assert.equal(result.status, 'not-owned');
  assert.equal(state.reads, before);
});
