'use strict';

// Browser integration with real production scripts and an HTTP-only fixture server.
// This does not exercise PHP persistence, session handling, or server flash storage.
// Run: node --test tests/browser/background-actions.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
let playwright;
try { playwright = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright')); }
catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  playwright = require('playwright');
}
const publicRoot = path.join(root, 'public');
const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/app.php'), 'utf8');
const modalStart = layout.indexOf('<div class="modal fade" id="synapseConfirmModal"');
const modalEnd = layout.indexOf('<!-- Vendor JS Files -->', modalStart);
assert.ok(modalStart >= 0 && modalEnd > modalStart, 'Real layout must supply shared confirmation markup');
const confirmMarkup = layout.slice(modalStart, layout.lastIndexOf('</div>', modalEnd) + 6);
assert.doesNotMatch(confirmMarkup, /<\?/);
const sharedBootstrap = [
  '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/js/loading.js', '/assets/js/csrf.js', '/assets/js/toast.js',
  '/assets/js/confirm.js', '/assets/js/form-modal.js', '/assets/js/page-lifecycle.js',
  '/assets/js/navigation.js', '/assets/js/background-actions.js'
].map(src => `<script src="${src}"></script>`).join('');
let browser, server, foreignServer, origin, foreignOrigin, nextId = 0;
const cases = new Map();
const foreignRequests = [];
const hostileMessage = '<img src=x onerror="window.serverTextExecuted=true"> Saved & authoritative';

const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);

function html(state, { changedScope = false, login = false } = {}) {
  const base = `/case/${state.id}`;
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Records ${state.version}</title>
    <link rel="icon" href="data:,"><meta name="csrf-name" content="fixture_csrf">
    <meta name="csrf-token" content="document-${state.version}"><meta name="csrf-header" content="X-Fixture-CSRF">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <style>main{min-height:3200px;padding:24px}.synapse-toast-container{position:fixed;right:20px;top:20px;z-index:2000}</style>
    </head><body data-navigation-scope="${changedScope ? 'different-identity' : 'background-fixture'}" hx-boost="false">
    <div id="page-styles" hidden><style>#record{--record-version:${state.version}}</style></div>
    <header id="header">Persistent shell<input type="hidden" name="fixture_csrf" value="document-${state.version}"></header>
    <aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link active" href="${base}/list">Records</a>
    <a class="nav-link" href="${base}/other">Other page</a></nav></aside>
    <main id="main" data-version="${state.version}"><h1>${login ? 'Sign in again' : 'Records'}</h1>
    <output id="record" data-version="${state.version}">${state.version ? 'Authoritative saved state' : 'Original server state'}</output>
    <form id="action" method="post" action="${base}/save" data-background-submit>
      <input type="hidden" name="fixture_csrf" value="document-${state.version}">
      <input name="title" value="Original title"><input name="version" value="${state.version}" type="hidden">
      <input name="disabled_field" value="must-not-submit" disabled>
      <button id="save" type="submit" name="intent" value="save">Save</button>
      <button id="override" type="submit" name="intent" value="archive" formaction="${base}/alternate" formmethod="post" formtarget="_self">Archive</button>
    </form>
    <form id="other-editor" method="post" action="${base}/editor" data-background-submit><input name="draft" value="Unrelated editor">
      <button id="save-other" type="submit" name="intent" value="save-draft">Save other editor</button></form>
    ${state.twoForms ? `<output id="saved-title">${escapeHtml(state.savedTitle || '')}</output><output id="saved-draft">${escapeHtml(state.savedDraft || '')}</output>` : ''}
    <button id="widget" type="button" data-bs-toggle="tooltip" title="Reinitialized tooltip">Widget</button>
    </main>${confirmMarkup}${sharedBootstrap}
    <div id="page-scripts"><script>
      window.pageScriptRuns = (window.pageScriptRuns || 0) + 1;
      document.addEventListener('synapse:page-load', function initialize(event) {
        document.removeEventListener('synapse:page-load', initialize);
        const button = event.detail.root.querySelector('#widget');
        button.addEventListener('click', () => { button.dataset.clicks = String(Number(button.dataset.clicks || 0) + 1); });
      });
    </script></div></body></html>`;
}
function send(res, status, type, body, headers = {}) {
  res.writeHead(status, { 'Content-Type': type, ...headers });
  res.end(body);
}

before(async () => {
  foreignServer = http.createServer((req, res) => {
    foreignRequests.push({ method: req.method, url: req.url });
    req.resume();
    send(res, 200, 'application/json', '{"success":true}');
  });
  foreignServer.listen(0, '127.0.0.1');
  await once(foreignServer, 'listening');
  foreignOrigin = `http://127.0.0.1:${foreignServer.address().port}`;
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://fixture.local');
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, '.' + url.pathname);
      if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file)) return send(res, 404, 'text/plain', 'Missing asset');
      return send(res, 200, file.endsWith('.js') ? 'text/javascript' : 'text/css', fs.readFileSync(file));
    }
    const state = cases.get(url.pathname.split('/')[2]);
    if (!state) return send(res, 404, 'text/plain', 'Unknown fixture');
    const request = { method: req.method, url: req.url, headers: req.headers, body: '' };
    state.requests.push(request);
    if (req.method === 'POST') {
      for await (const chunk of req) request.body += chunk;
      if (state.postGate) await state.postGate;
      if (state.postRedirect) return send(res, 307, 'text/plain', '', { Location: `${foreignOrigin}/redirect-target` });
      const success = !state.postFailure;
      if (success) {
        state.version++;
        if (state.twoForms) {
          const submitted = new Map(fields(request));
          if (url.pathname.endsWith('/editor')) state.savedDraft = submitted.get('draft');
          else state.savedTitle = submitted.get('title');
        }
      }
      return send(res, success ? 200 : 422, 'application/json', JSON.stringify({
        success, message: state.message, csrf: { name: 'fixture_csrf', hash: 'post-token', header: 'X-Fixture-CSRF' }
      }), { 'X-CSRF-TOKEN': 'post-header-token', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-HEADER': 'X-Fixture-CSRF' });
    }
    const documentRequest = req.headers['sec-fetch-dest'] === 'document';
    if (!documentRequest && state.version) {
      state.refreshes++;
      if (state.getGate) await state.getGate;
      if (state.getMode === 'failure') return send(res, 503, 'text/plain', 'Read unavailable');
      if (state.getMode === 'nonhtml') return send(res, 200, 'application/json', '{"success":true}');
      if (state.getMode === 'auth') return send(res, 401, 'text/html', '<h1>Session expired</h1>');
    }
    send(res, 200, 'text/html', html(state, { changedScope: state.getMode === 'scope' && state.version > 0,
      login: documentRequest && state.version > 0 && state.getMode === 'auth' }),
    !documentRequest ? { 'X-CSRF-TOKEN': 'get-header-token', 'X-CSRF-NAME': 'fixture_csrf' } : {});
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = playwright.chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) && fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
    assert.ok(executablePath, 'Install Chromium for Playwright before running this test');
  }
  browser = await playwright.chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  await browser?.close();
  for (const instance of [server, foreignServer]) if (instance?.listening) {
    instance.closeAllConnections();
    await new Promise(resolve => instance.close(resolve));
  }
});

async function fixture(t, options = {}) {
  const state = { id: String(++nextId), version: 0, refreshes: 0, requests: [], message: 'Saved successfully', ...options };
  cases.set(state.id, state);
  const context = await browser.newContext({ viewport: { width: 1200, height: 800 } });
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  const errors = [], documents = [], dialogs = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
  });
  t.after(async () => {
    state.releasePost?.(); state.releaseGet?.();
    await context.close();
    cases.delete(state.id);
    assert.deepEqual(errors, [], 'No uncaught browser JavaScript errors');
  });
  const url = `${origin}/case/${state.id}/list?folder=inbox&date=2026-11-01&page=3`;
  await page.goto(url);
  await page.waitForFunction(() => !!history.state?.__synapseNavigation && !!window.SynapseBackgroundActions);
  await page.evaluate(() => {
    window.shellIdentity = [document.body, document.querySelector('#header'), document.querySelector('#sidebar'), document.querySelector('.sidebar-nav')];
    window.oldMain = document.querySelector('#main');
    window.oldWidget = document.querySelector('#widget');
    window.lifecycle = [];
    document.addEventListener('synapse:before-swap', event => lifecycle.push({ type: 'dispose', old: event.detail.root === oldMain }));
    document.addEventListener('synapse:page-load', event => lifecycle.push({ type: 'init', fresh: event.detail.root === document.querySelector('#main') }));
  });
  return { state, page, documents, dialogs, url };
}
const posts = state => state.requests.filter(request => request.method === 'POST');
const reads = state => state.requests.filter(request => request.method === 'GET' && request.headers['sec-fetch-dest'] !== 'document');
function hold(state, kind) {
  state[`${kind}Gate`] = new Promise(resolve => { state[kind === 'post' ? 'releasePost' : 'releaseGet'] = resolve; });
}
async function toast(page, type, message) {
  await page.waitForFunction(({ type, message }) => [...document.querySelectorAll(`.synapse-toast[data-type="${type}"] .synapse-toast-message`)]
    .some(node => message ? node.textContent === message : node.textContent.length > 0), { type, message });
}
async function refreshed(page, version = 1) {
  await page.waitForFunction(version => document.querySelector('#main')?.dataset.version === String(version) && !document.body.classList.contains('is-navigating'), version);
}
async function shellSurvives(page, documents) {
  assert.equal(documents.length, 1, 'Only the initial request may navigate the document');
  assert.equal(await page.evaluate(() => shellIdentity.every((node, index) => node === [document.body, document.querySelector('#header'), document.querySelector('#sidebar'), document.querySelector('.sidebar-nav')][index])), true);
}
function fields(request) {
  const boundary = request.headers['content-type'].match(/boundary=(.+)$/)[1];
  const result = [];
  for (const part of request.body.split(`--${boundary}`)) {
    const match = part.match(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n$/);
    if (match) result.push([match[1], match[2]]);
  }
  return result;
}

test('one POST then authoritative GET preserves shell, query, scroll and initializes fresh state', async t => {
  const { page, state, documents, dialogs, url } = await fixture(t, { message: hostileMessage });
  await page.locator('[name="title"]').fill('Submitted edit');
  await page.evaluate(() => window.scrollTo(0, 640));
  // requestSubmit avoids Playwright scrolling the button into view before submission.
  await page.evaluate(() => document.querySelector('#action').requestSubmit(document.querySelector('#save')));
  await refreshed(page);
  await toast(page, 'success', hostileMessage);
  await shellSurvives(page, documents);
  assert.equal(page.url(), url);
  assert.equal(await page.evaluate(() => scrollY), 640);
  assert.equal(posts(state).length, 1);
  assert.equal(reads(state).length, 1);
  assert.deepEqual(state.requests.map(request => request.method), ['GET', 'POST', 'GET']);
  assert.equal(posts(state)[0].headers.accept, 'application/json');
  assert.equal(posts(state)[0].headers['x-synapse-feedback'], 'toast');
  assert.equal(posts(state)[0].headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(posts(state)[0].headers['x-fixture-csrf'], 'document-0');
  assert.equal(reads(state)[0].headers['x-fixture-csrf'], 'post-token');
  assert.equal(reads(state)[0].headers['x-requested-with'], undefined);
  assert.equal(reads(state)[0].headers['hx-request'], undefined);
  assert.equal(reads(state)[0].url, new URL(url).pathname + new URL(url).search);
  assert.equal(await page.locator('#record').textContent(), 'Authoritative saved state');
  assert.equal(await page.locator('[name="title"]').inputValue(), 'Original title');
  assert.equal(await page.locator('[name="version"]').inputValue(), '1');
  assert.equal(await page.title(), 'Records 1');
  assert.deepEqual(await page.evaluate(() => ({
    replaced: oldMain !== document.querySelector('#main'), runs: pageScriptRuns, lifecycle,
    token: document.querySelector('meta[name="csrf-token"]').content,
    tokens: [...document.querySelectorAll('input[name="fixture_csrf"]')].map(input => input.value),
    style: getComputedStyle(document.querySelector('#record')).getPropertyValue('--record-version').trim(),
    oldTooltipDisposed: bootstrap.Tooltip.getInstance(oldWidget) === null,
    freshTooltip: !!bootstrap.Tooltip.getInstance(document.querySelector('#widget')),
    dirty: SynapseNavigation.hasUnsavedChanges(), escaped: !document.querySelector('.synapse-toast img') && !window.serverTextExecuted
  })), { replaced: true, runs: 2, lifecycle: [{ type: 'dispose', old: true }, { type: 'init', fresh: true }],
    token: 'get-header-token', tokens: ['get-header-token', 'get-header-token'], style: '1',
    oldTooltipDisposed: true, freshTooltip: true, dirty: false, escaped: true });
  await page.locator('#widget').click();
  assert.equal(await page.locator('#widget').getAttribute('data-clicks'), '1');
  assert.deepEqual(dialogs, [], 'The saved form must not trigger an unsaved-edits prompt');
  assert.equal(await page.locator('#main .alert, #main [data-flash]').count(), 0, 'Fixture refresh contains no duplicate flash feedback');
  // Delegated handlers must still work after a real main replacement, exactly once.
  await page.locator('#save').click();
  await refreshed(page, 2);
  assert.equal(posts(state).length, 2);
  assert.equal(reads(state).length, 2);
});

test('in-flight write rejects duplicate submissions, real navigation and beforeunload', async t => {
  const { page, state, documents } = await fixture(t);
  hold(state, 'post');
  await page.locator('#save').click();
  await page.waitForFunction(() => document.querySelector('#action').getAttribute('aria-busy') === 'true');
  const guards = await page.evaluate(async () => {
    document.querySelector('#action').dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: document.querySelector('#save') }));
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    return { unloaded: unload.defaultPrevented, navigation: await SynapseNavigation.navigate(document.querySelectorAll('.sidebar-nav a')[1].href),
      inert: document.querySelector('#main').inert, disabled: document.querySelector('#save').disabled };
  });
  assert.deepEqual(guards, { unloaded: true, navigation: false, inert: true, disabled: true });
  assert.equal(reads(state).length, 0);
  state.releasePost();
  await refreshed(page);
  assert.equal(posts(state).length, 1);
  await shellSurvives(page, documents);
  assert.equal(await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; }), false);
});

test('canceled real confirmation sends no POST; acceptance submits exactly once', async t => {
  const { page, state, documents } = await fixture(t);
  await page.locator('#action').evaluate(form => form.setAttribute('data-confirm', 'Archive this record?'));
  await page.locator('#save').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(posts(state).length, 0);
  await page.locator('[data-confirm-cancel]').click();
  await page.waitForFunction(() => !document.querySelector('.modal.show, .modal-backdrop'));
  assert.equal(posts(state).length, 0);
  assert.equal(await page.locator('#save').isEnabled(), true);
  await page.locator('#save').click();
  await page.locator('[data-confirm-accept]').click();
  await refreshed(page);
  assert.equal(posts(state).length, 1);
  assert.equal(await page.locator('.modal-backdrop').count(), 0);
  await shellSurvives(page, documents);
});

test('edited fields and the selected submitter preserve safe action/method/target overrides', async t => {
  const { page, state, documents } = await fixture(t);
  await page.locator('[name="title"]').fill('A & B <draft>');
  await page.locator('#action').evaluate(form => { form.action = '/never-post'; form.method = 'get'; form.target = '_blank'; });
  await page.locator('#override').click();
  await refreshed(page);
  assert.equal(posts(state).length, 1);
  assert.equal(posts(state)[0].url, `/case/${state.id}/alternate`);
  assert.deepEqual(fields(posts(state)[0]), [ ['fixture_csrf', 'document-0'], ['title', 'A & B <draft>'], ['version', '0'], ['intent', 'archive'] ]);
  await shellSurvives(page, documents);
});

for (const [attribute, value] of [['formaction', 'external'], ['formaction', 'credentials'], ['formmethod', 'get'], ['formtarget', '_blank']]) {
  test(`unsafe submitter ${attribute}=${value} is rejected without a request`, async t => {
    const { page, state, documents } = await fixture(t);
    const target = value === 'external' ? `${foreignOrigin}/unsafe` : value === 'credentials' ? origin.replace('http://', 'http://user:password@') + '/unsafe' : value;
    await page.locator('#save').evaluate((button, { attribute, target }) => button.setAttribute(attribute, target), { attribute, target });
    await page.locator('#save').click();
    await toast(page, 'error', 'This action must be submitted to a valid page on this site.');
    assert.equal(posts(state).length, 0);
    assert.equal(reads(state).length, 0);
    await shellSurvives(page, documents);
  });
}

test('two dirty forms save sequentially without deadlock, lost drafts or POST replay', async t => {
  const { page, state, documents, dialogs, url } = await fixture(t, { twoForms: true });
  const title = 'First saved title & <record>';
  const draft = 'Keep unrelated draft';
  const revisedDraft = 'Second saved draft & <editor>';
  await page.locator('#other-editor input').fill(draft);
  await page.locator('[name="title"]').fill(title);
  assert.equal(await page.evaluate(() => SynapseMutations.canRefresh(document.querySelector('#action'))), false);
  await page.locator('#save').click();
  await toast(page, 'warning');
  assert.equal(posts(state).length, 1, 'Unrelated dirty edits must not veto an explicit POST');
  assert.equal(state.savedTitle, title);
  assert.equal(state.savedDraft, undefined);
  assert.equal(reads(state).length, 0, 'Defer the destructive GET while the other form is dirty');
  assert.equal(await page.locator('#other-editor input').inputValue(), draft);
  assert.equal(await page.locator('[name="title"]').inputValue(), title);
  assert.equal(await page.locator('#other-editor input').isEditable(), true);
  assert.equal(await page.locator('#save-other').isEnabled(), true);
  assert.deepEqual(await page.evaluate(() => ({
    sameMain: oldMain === document.querySelector('#main'), inert: document.querySelector('#main').inert,
    savedLocked: [...document.querySelector('#action').elements].every(control => control.disabled),
    anyDirty: SynapseNavigation.hasUnsavedChanges(),
    dirtyExceptOther: SynapseNavigation.hasUnsavedChanges(document.querySelector('#other-editor')),
    dirtyExceptSaved: SynapseNavigation.hasUnsavedChanges(document.querySelector('#action'))
  })), { sameMain: true, inert: false, savedLocked: true, anyDirty: true, dirtyExceptOther: false, dirtyExceptSaved: true });
  const retry = page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action');
  assert.equal(await retry.textContent(), 'Retry refresh');
  await page.mouse.move(0, 0);
  await page.waitForTimeout(5200);
  assert.equal(await retry.isVisible(), true, 'Deferred-refresh warning must remain sticky');
  await page.locator('#other-editor input').fill(revisedDraft);
  await retry.click();
  await page.waitForFunction(() => !document.querySelector('.synapse-toast-action').disabled);
  assert.equal(reads(state).length, 0, 'Retry must still respect the unrelated dirty draft');
  assert.equal(posts(state).length, 1, 'Retry must not repeat the saved POST');
  assert.equal(await page.locator('#other-editor input').inputValue(), revisedDraft);
  assert.equal(await page.locator('#other-editor input').isEditable(), true);
  await page.evaluate(() => document.querySelector('#action').dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true })));
  assert.equal(posts(state).length, 1, 'The committed first form must stay locked');
  await shellSurvives(page, documents);

  hold(state, 'get');
  await page.locator('#save-other').click();
  await page.waitForFunction(() => document.body.classList.contains('is-navigating'));
  assert.equal(posts(state).length, 2, 'The second dirty form must be able to save without deadlocking');
  assert.equal(reads(state).length, 1);
  assert.equal(await page.evaluate(() => SynapseNavigation.hasUnsavedChanges()), false, 'Both submitted forms are now saved');
  assert.equal(await page.locator('#other-editor input').isDisabled(), true);
  await page.evaluate(() => {
    for (const form of document.querySelectorAll('form[data-background-submit]')) {
      form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    }
  });
  state.releaseGet();
  await refreshed(page, 2);
  await toast(page, 'success', 'Saved successfully');
  assert.equal(await page.locator('#saved-title').textContent(), title);
  assert.equal(await page.locator('#saved-draft').textContent(), revisedDraft);
  assert.equal(state.savedTitle, title);
  assert.equal(state.savedDraft, revisedDraft);
  assert.deepEqual(posts(state).map(request => request.url), [`/case/${state.id}/save`, `/case/${state.id}/editor`]);
  assert.equal(new Map(fields(posts(state)[0])).get('title'), title);
  assert.equal(new Map(fields(posts(state)[1])).get('draft'), revisedDraft);
  assert.equal(reads(state).length, 1, 'Only one authoritative GET is needed after both saves');
  assert.deepEqual(state.requests.map(request => request.method), ['GET', 'POST', 'POST', 'GET']);
  assert.equal(page.url(), url);
  assert.deepEqual(dialogs, [], 'Saving each form must clear only its own navigation dirty state');
  await shellSurvives(page, documents);
});

test('failed POST retains inputs, escapes server errors, rotates CSRF and restores disabled states', async t => {
  const { page, state, documents } = await fixture(t, { postFailure: true, message: hostileMessage });
  await page.locator('[name="title"]').fill('Do not lose this draft');
  await page.locator('#save').click();
  await toast(page, 'error', hostileMessage);
  assert.equal(posts(state).length, 1);
  assert.equal(reads(state).length, 0);
  assert.equal(await page.locator('[name="title"]').inputValue(), 'Do not lose this draft');
  assert.equal(await page.locator('#save').isEnabled(), true);
  assert.equal(await page.locator('[name="disabled_field"]').isDisabled(), true);
  assert.deepEqual(await page.evaluate(() => ({ inert: document.querySelector('#main').inert,
    busy: document.querySelector('#action').hasAttribute('aria-busy'), dirty: SynapseNavigation.hasUnsavedChanges(),
    token: document.querySelector('meta[name="csrf-token"]').content,
    escaped: !document.querySelector('.synapse-toast img') && !window.serverTextExecuted })),
  { inert: false, busy: false, dirty: true, token: 'post-token', escaped: true });
  state.postFailure = false;
  await page.locator('#save').click();
  await refreshed(page);
  assert.equal(posts(state).length, 2);
  assert.equal(posts(state)[1].headers['x-fixture-csrf'], 'post-token');
  await shellSurvives(page, documents);
});

for (const getMode of ['failure', 'nonhtml']) {
  test(`saved POST with ${getMode} GET keeps stale form locked and offers sticky GET-only retry`, async t => {
    const { page, state, documents } = await fixture(t, { getMode });
    await page.locator('[name="title"]').fill('Saved input');
    await page.locator('#save').click();
    await toast(page, 'warning');
    const retry = page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action');
    assert.equal(await retry.textContent(), 'Retry refresh');
    assert.equal(await page.locator('#save').isDisabled(), true);
    assert.equal(await page.locator('#override').isDisabled(), true);
    assert.equal(await page.locator('[name="title"]').inputValue(), 'Saved input');
    assert.equal(await page.evaluate(() => SynapseNavigation.hasUnsavedChanges()), false);
    assert.equal(await page.locator('#main').getAttribute('data-version'), '0');
    await page.evaluate(() => document.querySelector('#action').dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts(state).length, 1);
    assert.equal(reads(state).length, 1);
    await shellSurvives(page, documents);
    // Past the default five-second success lifetime: warnings must remain actionable.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(5200);
    assert.equal(await retry.isVisible(), true);
    state.getMode = 'ok';
    hold(state, 'get');
    await retry.click();
    await page.waitForFunction(() => document.body.classList.contains('is-navigating'));
    assert.equal(await retry.isDisabled(), true);
    await retry.dispatchEvent('click');
    state.releaseGet();
    await refreshed(page);
    await toast(page, 'success', 'Saved successfully');
    assert.equal(posts(state).length, 1, 'Refresh retry must never repeat the committed POST');
    assert.equal(reads(state).length, 2, 'Repeated retry clicks must not duplicate the GET');
    await shellSurvives(page, documents);
  });
}

for (const getMode of ['auth', 'scope']) {
  test(`${getMode} change forces full-document fallback despite mutation fallback:false`, async t => {
    const { page, state, documents, url } = await fixture(t, { getMode });
    await Promise.all([page.waitForEvent('load'), page.locator('#save').click()]);
    await page.waitForFunction(() => !!window.SynapseBackgroundActions);
    assert.equal(posts(state).length, 1);
    assert.equal(reads(state).length, 1);
    assert.equal(documents.length, 2, 'Identity/session changes must replace the privileged shell');
    assert.equal(page.url(), url);
    assert.equal(await page.evaluate(() => typeof window.shellIdentity), 'undefined');
    if (getMode === 'scope') assert.equal(await page.locator('body').getAttribute('data-navigation-scope'), 'different-identity');
    else assert.equal(await page.locator('h1').textContent(), 'Sign in again');
  });
}

test('external mutation refresh is rejected without navigation, foreign requests or an unsafe GET retry', async t => {
  const foreignBefore = foreignRequests.length;
  const { page, state, documents, url } = await fixture(t);
  const result = await page.evaluate(target => SynapseMutations.refresh(target, 'Saved successfully'), `${foreignOrigin}/unsafe-refresh`);
  assert.equal(result, false);
  await toast(page, 'warning');
  const action = page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action');
  assert.equal(await action.isHidden(), true, 'Invalid refresh targets must not expose Retry refresh');
  assert.equal(await action.textContent(), '');
  // Even a synthetic click on the hidden button must have no unsafe retry callback.
  await action.dispatchEvent('click');
  await page.waitForTimeout(100);
  assert.equal(posts(state).length, 0);
  assert.equal(reads(state).length, 0);
  assert.equal(foreignRequests.length, foreignBefore);
  assert.equal(page.url(), url);
  assert.equal(await page.evaluate(() => oldMain === document.querySelector('#main')), true);
  await shellSurvives(page, documents);
});

test('cross-origin POST redirect is rejected rather than forwarding the mutation or navigating', async t => {
  const foreignBefore = foreignRequests.length;
  const { page, state, documents } = await fixture(t, { postRedirect: true });
  await page.locator('[name="title"]').fill('Retain uncertain outcome');
  await page.locator('#save').click();
  await toast(page, 'error', 'The response was interrupted; the change may have been saved. Review the record before retrying.');
  assert.equal(posts(state).length, 1);
  assert.equal(reads(state).length, 0);
  assert.equal(foreignRequests.length, foreignBefore, 'redirect:error must not contact the external destination');
  assert.equal(await page.locator('[name="title"]').inputValue(), 'Retain uncertain outcome');
  assert.equal(await page.locator('#save').isEnabled(), true);
  await shellSurvives(page, documents);
});
