'use strict';

// Production PHP views/assets with per-context synthetic HTTP state. No live API, DB, or dotenv.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const crypto = require('node:crypto');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const PHOTO = '/portal/settings/photo', REMOVE = PHOTO + '/remove', CONTACT = '/portal/settings/emergency-contact';
const SURVEY = '/portal/surveys/1/submit';
const contactNames = ['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone'];
const initialContact = { emergency_contact_name: 'Maria Santos', emergency_contact_relationship: 'Mother', emergency_contact_phone: '0917-123-4567' };
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=', 'base64');
const sessions = new Map(), rendered = new Map(), serverErrors = [], screenshots = [];
let browser, server, origin, evidenceDirectory;

function render(requestPath, state) {
  const fixture = { origin, path: requestPath, kind: state.kind, permissions: state.permissions,
    csrfHash: state.csrfHash, hasPhoto: state.hasPhoto, contact: state.contact, submitted: state.submitted };
  const key = JSON.stringify(fixture);
  if (!rendered.has(key)) {
    const result = spawnSync('php', [path.join(root, 'tests/_support/render-portal-workspace-fixture.php')], {
      cwd: root, input: key, encoding: 'utf8', timeout: 20000,
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /<!doctype html>|data-app-form-modal/i);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code":\s*500/);
    rendered.set(key, result.stdout);
  }
  return rendered.get(key);
}
function cookie(req) {
  return (req.headers.cookie || '').split(';').map(item => item.trim()).find(item => item.startsWith('portalFixture='))?.slice('portalFixture='.length);
}
function defer(t) {
  let release;
  const promise = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  return { promise, release };
}
async function until(predicate, message, timeout = 7000) {
  const deadline = Date.now() + timeout;
  while (!predicate()) {
    assert.ok(Date.now() < deadline, message);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
}
const paneReads = state => state.reads.filter(read => read.headers['x-synapse-portal-pane'] === '1');

before(async () => {
  assert.equal(require('playwright/package.json').version, '1.58.2', 'Use the locked browser test dependency');
  let executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || chromium.executablePath();
  if (!fs.existsSync(executablePath) && !process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) {
    const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || path.join(process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData/Local'), 'ms-playwright');
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome']
        .map(relative => path.join(cache, name, relative))).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath && fs.existsSync(executablePath), 'An installed Chromium runtime is required; no downloads');
  evidenceDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'humus-portal-browser-'));
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin), state = sessions.get(cookie(req));
      if (req.method === 'POST') {
        if (!state || ![PHOTO, REMOVE, CONTACT, SURVEY].includes(url.pathname)) { res.writeHead(404).end(); return; }
        const chunks = []; for await (const chunk of req) chunks.push(chunk);
        const data = await new Request(origin + req.url, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] }, body: Buffer.concat(chunks) }).formData();
        const entry = { path: url.pathname, token: data.get('csrf_test_name'), csrfHeader: req.headers['x-csrf-token'],
          names: [...data.keys()], accept: req.headers.accept, ajax: req.headers['x-requested-with'],
          answer: data.get('answers[0]'), other: data.get('answers_other[0]'), finished: false };
        assert.equal(entry.token, state.csrfHash, 'Every synthetic write must carry the current real-view CSRF token');
        if (url.pathname !== SURVEY) assert.equal(entry.csrfHeader, entry.token);
        state.posts.push(entry); ++state.activeWrites;
        state.maxConcurrentWrites = Math.max(state.maxConcurrentWrites, state.activeWrites);
        try {
          if (state.writeGate) await state.writeGate.promise;
          state.csrfHash = crypto.randomBytes(16).toString('hex');
          entry.responseToken = state.csrfHash;
          if (url.pathname === SURVEY) state.submitted = true;
          else if (url.pathname === CONTACT) state.contact = Object.fromEntries(contactNames.map(name => [name, String(data.get(name) || '').trim() || null]));
          else state.hasPhoto = url.pathname !== REMOVE;
          const message = url.pathname === CONTACT ? 'Emergency contact updated.' : url.pathname === REMOVE ? 'Profile picture removed.' : url.pathname === SURVEY ? 'Response submitted.' : 'Profile picture updated.';
          const payload = { success: true, message, csrf: { name: 'csrf_test_name', hash: state.csrfHash, header: 'X-CSRF-TOKEN' },
            ...(url.pathname === CONTACT ? { emergency_contact: state.contact } : url.pathname === SURVEY ? {} : { has_photo: state.hasPhoto, photo_url: origin + '/account/photo?v=' + state.posts.length }) };
          if (!res.destroyed) res.writeHead(200, { 'Content-Type': 'application/json', 'X-CSRF-NAME': 'csrf_test_name', 'X-CSRF-TOKEN': state.csrfHash, 'X-CSRF-HEADER': 'X-CSRF-TOKEN' }).end(JSON.stringify(payload));
        } finally { --state.activeWrites; entry.finished = true; }
        return;
      }
      if ([PHOTO, '/account/photo'].includes(url.pathname)) { res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'no-store' }).end(png); return; }
      if (url.pathname === '/auth/login') { res.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><title>Sign in</title><h1>Sign in</h1>'); return; }
      if (url.pathname === '/notifications') { res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ success: true, notifications: [], unread_count: 0 })); return; }
      if (['/portal', '/portal/settings', '/portal/announcements', '/portal/surveys', '/portal/surveys/1', '/portal/assessments/mi/7', '/portal/appointments/clinic/create'].includes(url.pathname)) {
        assert.ok(state, 'Every browser context uses a private synthetic session');
        const entry = { path: url.pathname, search: url.search, headers: req.headers };
        state.reads.push(entry);
        const answer = { ...(state.readResponses.get(url.pathname) || {}) }, gate = state.readGates.get(url.pathname);
        if (gate) await gate.promise;
        if (res.destroyed) return;
        if (answer.redirect) { res.writeHead(302, { Location: answer.redirect }).end(); return; }
        res.writeHead(answer.status || 200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store', ...(answer.headers || {}) })
          .end(answer.body ?? (answer.status >= 400 ? '<!doctype html><title>Denied</title><h1>Access denied</h1>' : render(url.pathname + url.search, state)));
        return;
      }
      if (url.pathname === '/favicon.ico') { res.writeHead(204).end(); return; }
      if (!url.pathname.startsWith('/assets/')) { res.writeHead(404).end(); return; }
      const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(fs.readFileSync(file));
    } catch (error) { serverErrors.push(error.stack); if (!res.headersSent) res.writeHead(500); res.end(String(error)); }
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  for (const state of sessions.values()) { state.writeGate?.release(); for (const gate of state.readGates.values()) gate.release(); }
  try { await browser?.close(); } finally {
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
  assert.deepEqual(serverErrors, [], 'The isolated renderer and synthetic receiver must be error-free');
  console.log('Portal screenshot evidence: ' + evidenceDirectory + ' (' + screenshots.length + ' images)');
}, { timeout: 20000 });

async function pageFor(t, options = {}) {
  const state = { id: crypto.randomUUID(), kind: options.kind || 'student', permissions: options.permissions || ['portal.records.read', 'portal.appointments.read', 'portal.surveys.submit'],
    csrfHash: crypto.randomBytes(16).toString('hex'), hasPhoto: true, contact: { ...initialContact }, submitted: false,
    reads: [], posts: [], readResponses: new Map(), readGates: new Map(), writeGate: null, activeWrites: 0, maxConcurrentWrites: 0 };
  options.prepare?.(state); sessions.set(state.id, state);
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await context.addCookies([{ name: 'portalFixture', value: state.id, url: origin }]);
  await context.addInitScript(() => {
    localStorage.setItem('na-dark-mode', '0');
    window.fixtureNavigationEvents = []; window.fixtureGlobalCleanups = 0;
    document.addEventListener('synapse:before-navigate', event => window.fixtureNavigationEvents.push({ url: event.detail?.url, root: event.detail?.root?.id, popstate: event.detail?.popstate }));
    document.addEventListener('synapse:before-swap', event => {
      const form = event.detail?.root?.querySelector?.('#survey-response-form');
      if (form) { window.fixtureOutgoingSurvey = form; window.fixtureOutgoingSurveyMarker = form.dataset.bound; }
    });
    document.addEventListener('DOMContentLoaded', () => {
      window.SynapseNavigation?.registerCleanup(() => { ++window.fixtureGlobalCleanups; });
      const rail = document.querySelector('.portal-left-rail');
      if (rail) {
        const form = document.createElement('form'); form.method = 'post'; form.id = 'fixture-shell-form';
        form.innerHTML = '<label>Synthetic shell draft<input name="shell_note" aria-label="Synthetic shell draft"></label>';
        rail.append(form);
      }
    }, { once: true });
  });
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.fulfill({ status: 200, body: '' }));
  const page = await context.newPage(), errors = [], documents = [];
  page.setDefaultTimeout(7000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url()); });
  t.after(async () => {
    state.writeGate?.release(); for (const gate of state.readGates.values()) gate.release();
    if (!page.isClosed()) {
      const file = path.join(evidenceDirectory, t.name.replace(/[^a-z0-9]+/gi, '-').slice(0, 115) + '.png');
      await page.screenshot({ path: file, fullPage: false }); screenshots.push(file);
    }
    await context.close(); assert.deepEqual(errors, [], 'No page JavaScript errors');
  });
  await page.goto(origin + (options.start || '/portal'));
  if (options.ready !== false) await ready(page, options.standalone === true);
  return { page, state, documents };
}
async function ready(page, standalone = false) {
  await page.waitForFunction(standalone => {
    const main = document.getElementById('main'), pane = document.getElementById('portal-workspace-pane');
    return window.SynapseNavigation && window.SynapsePortalWorkspace && main?.isConnected &&
      !document.documentElement.classList.contains('portal-shell-hydrating') && main.style.opacity !== '0' &&
      !document.body.classList.contains('is-navigating') && !SynapseNavigation.workspace.restoring &&
      (standalone ? !pane : pane?.dataset.currentPaneUrl === location.href && pane.getAttribute('aria-busy') !== 'true');
  }, standalone);
}
async function settle(page) { await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))); }
async function entry(page) { return page.evaluate(() => ({ ...history.state.__synapseNavigation, length: history.length })); }
async function recordConfirm(page, answers = [false]) {
  await page.evaluate(answers => {
    window.fixtureConfirmMessages = []; window.fixtureConfirmAnswers = answers;
    window.confirm = message => { window.fixtureConfirmMessages.push(message); return window.fixtureConfirmAnswers.length > 1 ? window.fixtureConfirmAnswers.shift() : window.fixtureConfirmAnswers[0]; };
  }, answers);
}
async function navigate(page, url, options = {}, owner = 'SynapseNavigation') {
  await page.evaluate(({ url, options, owner }) => {
    const result = window.fixtureNavigationResult = { settled: false };
    if (options.exceptSurvey) { options.exceptForm = document.getElementById('survey-response-form'); delete options.exceptSurvey; }
    window[owner].navigate(url, options).then(value => { result.value = value; result.settled = true; }, error => { result.error = error.message; result.settled = true; });
  }, { url, options, owner });
  await page.waitForFunction(() => window.fixtureNavigationResult?.settled);
  const result = await page.evaluate(() => window.fixtureNavigationResult);
  assert.equal(result.error, undefined); return result.value;
}
async function startNavigation(page, url) {
  await page.evaluate(url => {
    const result = window.fixtureDelayedNavigation = { settled: false };
    SynapseNavigation.navigate(url, { fallback: false }).then(value => { result.value = value; result.settled = true; });
  }, url);
}
async function settings(page) {
  await page.locator('.sidebar-nav a[href$="/portal/settings"]').click(); await ready(page);
  await page.locator('#portal-workspace-pane [data-profile-settings]').waitFor();
}
async function selectPhoto(page) {
  await page.locator('#profilePhotoUpload').setInputFiles({ name: 'draft.png', mimeType: 'image/png', buffer: png });
  await page.locator('[data-profile-photo-preview]').waitFor({ state: 'visible' });
  return page.locator('[data-profile-photo-preview]').getAttribute('src');
}
async function chooseOther(page, text = 'Counseling support') {
  await page.getByLabel('Other (please specify)', { exact: true }).check();
  const input = page.locator('[data-other-input]'); await input.waitFor({ state: 'visible' });
  assert.equal(await input.isEnabled(), true); assert.equal(await input.getAttribute('required'), '');
  await input.fill(text);
}
async function approve(page, label = 'Submit response') {
  await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(await page.locator('[data-confirm-accept]').innerText(), label);
  await page.locator('[data-confirm-accept]').click();
  await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
}
async function assertKept(page, state, savedEntry, paneReadCount) {
  await settle(page);
  const current = await entry(page);
  assert.equal(page.url(), savedEntry.url); assert.equal(current.url, savedEntry.url);
  assert.equal(current.index, savedEntry.index); assert.equal(current.id, savedEntry.id); assert.equal(current.length, savedEntry.length);
  assert.equal(paneReads(state).length, paneReadCount, 'Veto precedes every destination GET');
  assert.equal(await page.locator('#portal-workspace-pane [data-profile-settings]').count(), 1);
}

test('direct survey hydration rebinds the cloned form: Other toggles and one confirmed submit sends exactly one POST', { timeout: 30000 }, async t => {
  const { page, state, documents } = await pageFor(t, { start: '/portal/surveys/1' });
  await page.locator('#portal-workspace-pane #survey-response-form[data-bound="1"]').waitFor();
  assert.equal(await page.evaluate(() => fixtureOutgoingSurveyMarker), '1', 'The original live form was bound before cloning');
  assert.equal(await page.evaluate(() => fixtureOutgoingSurvey !== document.getElementById('survey-response-form')), true, 'Handlers must bind the imported identity');
  assert.equal(state.reads.filter(read => read.path === '/portal/surveys/1').length, 1, 'Hydration must not refetch the destination');
  assert.equal(state.reads.filter(read => read.path === '/portal' && read.headers['x-synapse-portal-badge-sync'] === '1').length, 1);
  await chooseOther(page);
  await page.getByLabel('Study', { exact: true }).check();
  assert.equal(await page.locator('[data-other-input]').isEnabled(), false);
  assert.equal(await page.locator('[data-other-input]').inputValue(), '');
  assert.equal(await page.locator('[data-other-wrapper]').isVisible(), false);
  await chooseOther(page, 'Single identified answer');
  const gate = defer(t); state.writeGate = gate;
  const submit = page.getByRole('button', { name: 'Submit identified response', exact: true });
  await submit.dblclick();
  await approve(page);
  await until(() => state.posts.length === 1, 'Approved survey submits once');
  assert.equal(await submit.isEnabled(), false);
  assert.equal(state.posts[0].answer, '__other__'); assert.equal(state.posts[0].other, 'Single identified answer');
  assert.deepEqual(state.posts[0].names.sort(), ['csrf_test_name', 'answers[0]', 'answers_other[0]'].sort());
  assert.equal(state.posts[0].ajax, 'XMLHttpRequest'); assert.match(state.posts[0].accept, /application\/json/);
  gate.release(); state.writeGate = null;
  await page.waitForURL(origin + '/portal/surveys'); await ready(page);
  assert.equal(state.posts.length, 1); assert.equal(state.maxConcurrentWrites, 1); assert.equal(documents.length, 1);
  assert.equal(await page.locator('#portal-workspace-pane #survey-response-form').count(), 0);
  assert.equal(await page.evaluate(() => document.querySelector('meta[name="csrf-token"]').content), state.posts[0].responseToken);
});

test('optional shell403 preserves visible independently authorized survey and its working standalone submission', { timeout: 30000 }, async t => {
  const { page, state, documents } = await pageFor(t, { start: '/portal/surveys/1', standalone: true,
    permissions: ['portal.surveys.submit'], prepare: state => state.readResponses.set('/portal', { status: 403 }) });
  assert.equal(await page.locator('#main h1').innerText(), 'Campus wellbeing');
  assert.equal(await page.locator('#main').isVisible(), true); assert.equal(await page.locator('#survey-response-form[data-bound="1"]').count(), 1);
  assert.equal(state.reads.filter(read => read.path === '/portal').length, 1, 'An optional shell denial must not loop or load a browser document');
  await chooseOther(page, 'Authorized without records access');
  await page.getByRole('button', { name: 'Submit identified response', exact: true }).click(); await approve(page);
  await page.waitForURL(origin + '/portal/surveys'); await ready(page, true);
  assert.equal(state.posts.length, 1); assert.equal(state.posts[0].other, 'Authorized without records access');
  assert.equal(documents.length, 2, 'Standalone success uses its authorized destination, not /portal');
  assert.equal(await page.locator('#main h1').innerText(), 'My Surveys');
  assert.equal(state.reads.filter(read => read.path === '/portal').length, 2, 'At most one optional attempt per full standalone document');
});

test('delayed optional hydration cannot discard edits made on the independently authorized standalone survey', { timeout: 25000 }, async t => {
  const gate = defer(t);
  const { page, state } = await pageFor(t, { start: '/portal/surveys/1', ready: false,
    prepare: state => state.readGates.set('/portal', gate) });
  await page.locator('#survey-response-form[data-bound="1"]').waitFor({ state: 'attached' });
  await until(() => state.reads.some(read => read.path === '/portal'), 'Optional shell GET is delayed');
  // The layout's documented hydration fallback exposes the original form after four seconds.
  await page.locator('#survey-response-form').waitFor({ state: 'visible' });
  await chooseOther(page, 'Draft while optional shell waits');
  gate.release(); state.readGates.delete('/portal'); await ready(page, true);
  assert.equal(page.url(), origin + '/portal/surveys/1');
  assert.equal(await page.locator('[data-other-input]').inputValue(), 'Draft while optional shell waits');
  assert.equal(await page.locator('#survey-response-form[data-bound="1"]').count(), 1);
  assert.equal(state.posts.length, 0);
});

test('optional shell authentication denial still replaces the document with the configured login', { timeout: 20000 }, async t => {
  const { page, documents } = await pageFor(t, { start: '/portal/surveys/1', ready: false,
    prepare: state => state.readResponses.set('/portal', { status: 401, headers: { 'X-Synapse-Auth': 'authentication_required', 'X-Synapse-Login': origin + '/auth/login' } }) });
  await page.waitForURL(origin + '/auth/login'); await page.getByRole('heading', { name: 'Sign in', exact: true }).waitFor();
  assert.equal(documents.length, 2); assert.equal(await page.locator('#main').count(), 0);
});

test('MI detail routes activate the single My Surveys pane without a separate MI tile', { timeout: 20000 }, async t => {
  const { page } = await pageFor(t, { start: '/portal/assessments/mi/7' });
  assert.equal(await page.locator('.portal-app-dock [data-portal-pane-link="surveys"].is-active').count(), 1);
  assert.equal(await page.locator('.portal-app-dock [data-portal-pane-link="mi"]').count(), 0);
  assert.equal(await page.locator('.sidebar-nav a[href$="/portal/surveys"].active').count(), 1);
  assert.equal(await page.locator('.sidebar-nav a[href*="/portal/assessments/mi"]').count(), 0);
  assert.equal(await page.getByRole('link', { name: 'My Surveys', exact: true }).first().getAttribute('href'), origin + '/portal/surveys');
});

test('a forbidden pane destination is still a strict full-document denial, not optional shell fallback', { timeout: 20000 }, async t => {
  const { page, state, documents } = await pageFor(t);
  state.readResponses.set('/portal/surveys', { status: 403 });
  await page.locator('.portal-app-dock [data-portal-pane-link="surveys"]').click();
  await page.waitForURL(origin + '/portal/surveys'); await page.getByRole('heading', { name: 'Access denied', exact: true }).waitFor();
  assert.equal(documents.length, 2); assert.equal(await page.locator('#portal-workspace-pane').count(), 0); assert.equal(state.posts.length, 0);
});

test('pane lifecycle remains scoped: unrelated swaps leave survey bound and pane swaps never drain global cleanup', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t);
  for (let iteration = 0; iteration < 2; ++iteration) {
    assert.equal(await navigate(page, '/portal/surveys/1'), true); await ready(page);
    await page.locator('#survey-response-form[data-bound="1"]').waitFor();
    await page.evaluate(() => {
      document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('.portal-profile-card') } }));
      document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document.getElementById('portal-workspace-pane') } }));
      SynapsePortalWorkspace.sync(); SynapsePortalWorkspace.sync();
    });
    await chooseOther(page, 'Scoped draft ' + iteration);
    assert.equal(await page.evaluate(() => fixtureGlobalCleanups), 0);
    assert.equal(await navigate(page, '/portal', { bypassDirtyCheck: true }), true); await ready(page);
    assert.equal(await page.evaluate(() => fixtureOutgoingSurvey.isConnected), false);
    assert.equal(await page.evaluate(() => fixtureOutgoingSurvey.dataset.bound), undefined);
    assert.equal(await page.evaluate(() => fixtureGlobalCleanups), 0, 'The document cleanup registry belongs to full-main navigation only');
  }
  assert.equal(state.posts.length, 0);
});

test('pane dirty consent excludes shell forms and honors exceptForm with native window.confirm override', { timeout: 25000 }, async t => {
  const { page, state } = await pageFor(t);
  await page.getByLabel('Synthetic shell draft', { exact: true }).fill('Persistent shell work');
  await recordConfirm(page, [false]);
  assert.equal(await navigate(page, '/portal/surveys/1'), true); await ready(page);
  assert.equal(await page.getByLabel('Synthetic shell draft', { exact: true }).inputValue(), 'Persistent shell work');
  assert.deepEqual(await page.evaluate(() => fixtureConfirmMessages), [], 'An unrelated shell draft cannot veto a pane swap');
  await chooseOther(page, 'Except this successful editor');
  assert.equal(await navigate(page, '/portal/surveys', { exceptSurvey: true }, 'SynapsePortalWorkspace'), true); await ready(page);
  assert.deepEqual(await page.evaluate(() => fixtureConfirmMessages), [], 'exceptForm must survive the programmatic pane wrapper');
  assert.equal(await page.evaluate(() => SynapseNavigation.hasUnsavedChanges()), true, 'The unswapped shell draft remains dirty');
  assert.equal(state.posts.length, 0);
});

test('owned pane Back/Forward adopts traversed indices, IDs and scroll without pushing or replacing the Portal shell', { timeout: 30000 }, async t => {
  const { page, state, documents } = await pageFor(t);
  await page.addStyleTag({ content: '.app-shell #main.main { min-height: 3000px; } html { overflow-anchor: none; }' });
  await page.evaluate(() => { window.fixtureShell = [document.getElementById('main'), document.getElementById('portal-workspace-pane'), document.querySelector('.portal-profile-card')]; });
  const scroll = async top => {
    await page.evaluate(async top => { await document.fonts.ready; window.scrollTo({ top, behavior: 'instant' }); }, top);
    await settle(page);
    const actual = await page.evaluate(() => scrollY);
    assert.equal(actual, top, 'The synthetic page is tall enough for the intended scroll');
    await page.waitForFunction(top => history.state.__synapseNavigation.scroll[1] === top, actual);
  };
  await scroll(260);
  const initial = await entry(page);
  assert.equal(await navigate(page, '/portal/announcements', { scroll: [0, 480] }), true); await ready(page);
  assert.equal(await page.evaluate(() => scrollY), 480);
  await page.waitForFunction(() => history.state.__synapseNavigation.scroll[1] === 480);
  const first = await entry(page);
  assert.equal(await navigate(page, '/portal/surveys', { scroll: [0, 720] }), true); await ready(page);
  await page.waitForFunction(() => history.state.__synapseNavigation.scroll[1] === 720);
  const second = await entry(page);
  assert.equal(first.index, initial.index + 1); assert.equal(second.index, first.index + 1);
  for (const [direction, expected] of [['back', first], ['back', initial], ['forward', first], ['forward', second]]) {
    await page.evaluate(direction => history[direction](), direction); await page.waitForURL(expected.url); await ready(page);
    const current = await entry(page);
    assert.equal(current.id, expected.id); assert.equal(current.index, expected.index); assert.equal(current.length, second.length);
    assert.equal(await page.evaluate(() => scrollY), expected.scroll[1]);
  }
  assert.equal(await page.evaluate(() => fixtureShell.every((element, index) => element === [document.getElementById('main'), document.getElementById('portal-workspace-pane'), document.querySelector('.portal-profile-card')][index])), true);
  assert.equal(documents.length, 1); assert.equal(paneReads(state).length, 6);
});

test('delayed pane GET followed by dirty canceled Back immediately releases shared pending and ignores late response', { timeout: 30000 }, async t => {
  const { page, state, documents } = await pageFor(t, { start: '/portal/announcements' });
  await settings(page); await page.locator('#emergencyContactName').fill('Original draft');
  const before = await entry(page), reads = paneReads(state).length, gate = defer(t);
  state.readGates.set('/portal/surveys', gate); await recordConfirm(page, [true, false]);
  await startNavigation(page, '/portal/surveys'); await until(() => paneReads(state).length === reads + 1, 'The delayed pane GET begins');
  await page.locator('#emergencyContactName').fill('Late draft while GET runs');
  await page.evaluate(() => history.back()); await page.waitForURL(before.url); await ready(page);
  await assertKept(page, state, before, reads + 1);
  assert.equal(await page.locator('#emergencyContactName').inputValue(), 'Late draft while GET runs');
  assert.equal(await page.evaluate(() => fixtureConfirmMessages.length), 2, 'Back cancellation prompts once; restoration replay is not intercepted');
  assert.equal(await page.evaluate(() => SynapseNavigation.updateHistory('/portal/settings?section=current', 'push')), true,
    'Canceled Back must not leave the shared pending owner stuck');
  assert.equal((await entry(page)).index, before.index + 1);
  gate.release(); state.readGates.delete('/portal/surveys');
  await page.waitForFunction(() => fixtureDelayedNavigation.settled);
  assert.equal(await page.evaluate(() => fixtureDelayedNavigation.value), false);
  assert.equal(await page.locator('#emergencyContactName').inputValue(), 'Late draft while GET runs');
  assert.equal(page.url(), origin + '/portal/settings?section=current'); assert.equal(documents.length, 1);
});

test('late survey edits during delayed GET recheck consent and preserve the live form on cancel', { timeout: 25000 }, async t => {
  const { page, state } = await pageFor(t, { start: '/portal/surveys/1' });
  const before = await entry(page), gate = defer(t); state.readGates.set('/portal/announcements', gate);
  await recordConfirm(page, [false]); await startNavigation(page, '/portal/announcements');
  await until(() => paneReads(state).some(read => read.path === '/portal/announcements'), 'The delayed GET begins before edits');
  await chooseOther(page, 'Typed during navigation'); gate.release(); state.readGates.delete('/portal/announcements');
  await page.waitForFunction(() => fixtureDelayedNavigation.settled); await ready(page);
  assert.equal(await page.evaluate(() => fixtureDelayedNavigation.value), false);
  assert.equal((await entry(page)).id, before.id); assert.equal(page.url(), before.url);
  assert.equal(await page.locator('[data-other-input]').inputValue(), 'Typed during navigation');
  assert.equal(await page.locator('#survey-response-form[data-bound="1"]').count(), 1);
  assert.equal(await page.evaluate(() => fixtureConfirmMessages.length), 1);
  assert.equal(state.posts.length, 0);
});

for (const failure of ['http', 'network']) {
  test(`${failure} failed Back GET restores the previous owned URL/entry without a prompt or push and can retry traversal`, { timeout: 30000 }, async t => {
    const { page, state, documents } = await pageFor(t, { start: '/portal/announcements' });
    const previous = await entry(page); await settings(page); const before = await entry(page);
    await recordConfirm(page, [false]);
    let networkAttempts = 0;
    const networkUrl = origin + '/portal/announcements';
    const failNetwork = async route => { ++networkAttempts; await route.abort('failed'); };
    if (failure === 'http') state.readResponses.set('/portal/announcements', { status: 503 });
    else await page.route(networkUrl, failNetwork);
    const reads = paneReads(state).length;
    await page.evaluate(() => history.back());
    await until(() => failure === 'http' ? paneReads(state).length > reads : networkAttempts === 1, 'Back must request its destination GET');
    await page.locator('#synapse-toast-container .synapse-toast-message').filter({ hasText: /Unable to load|fetch/i }).waitFor();
    await page.waitForURL(before.url); await ready(page);
    await assertKept(page, state, before, reads + (failure === 'http' ? 1 : 0));
    if (failure === 'network') assert.equal(networkAttempts, 1, 'One JS GET fails; restoration never retries it');
    assert.deepEqual(await page.evaluate(() => fixtureConfirmMessages), []);
    assert.equal(await page.evaluate(() => SynapseNavigation.canUpdateHistory(location.href)), true);
    state.readResponses.delete('/portal/announcements');
    if (failure === 'network') await page.unroute(networkUrl, failNetwork);
    await page.evaluate(() => history.back()); await page.waitForURL(previous.url); await ready(page);
    assert.equal((await entry(page)).id, previous.id); assert.equal((await entry(page)).index, previous.index);
    assert.equal((await entry(page)).length, before.length); assert.equal(documents.length, 1); assert.equal(state.posts.length, 0);
  });
}

for (const operation of ['photo', 'contact', 'remove']) {
  test(`pending ${operation} vetoes dock/sidebar/account/pane/Back/programmatic and modal entry paths even with bypassDirtyCheck`, { timeout: 40000 }, async t => {
    const { page, state, documents } = await pageFor(t, { start: '/portal/announcements' });
    await settings(page); const blob = await selectPhoto(page); await page.locator('#emergencyContactName').fill('Pending independent contact draft');
    const gate = defer(t); state.writeGate = gate;
    const label = operation === 'photo' ? 'Upload picture' : operation === 'contact' ? 'Save emergency contact' : 'Remove picture';
    await page.getByRole('button', { name: label, exact: true }).click(); if (operation === 'remove') await approve(page, 'Remove picture');
    await until(() => state.posts.length === 1, 'The first explicit profile write reaches the receiver');
    await page.waitForFunction(() => [...document.querySelectorAll('[data-profile-settings] input:not([type="hidden"]), [data-profile-settings] button')].every(control => control.matches(':disabled')));
    const before = await entry(page), reads = paneReads(state).length;
    await recordConfirm(page, [true]);
    for (const selector of ['.portal-app-dock [data-portal-pane-link="surveys"]', '.sidebar-nav a[href$="/portal/surveys"]', '#portal-workspace-pane .portal-workspace-actions a']) {
      await page.locator(selector).click(); await assertKept(page, state, before, reads);
    }
    await page.locator('.user-trigger').click(); await page.locator('.user-menu a[href$="/portal/settings"]').click();
    await assertKept(page, state, before, reads);
    if (await page.locator('.user-trigger').getAttribute('aria-expanded') === 'true') await page.locator('.user-trigger').click();
    for (const owner of ['SynapseNavigation', 'SynapsePortalWorkspace']) {
      assert.equal(await navigate(page, '/portal/surveys', { bypassDirtyCheck: true, history: 'replace', scroll: [0, 340] }, owner), false);
      await assertKept(page, state, before, reads);
    }
    await page.evaluate(() => history.back()); await page.waitForURL(before.url); await ready(page); await assertKept(page, state, before, reads);
    await page.locator('#main .portal-request-actions a[data-app-modal-open][href$="/portal/appointments/clinic/create"]').click();
    await page.locator('#appFormModal form').waitFor();
    assert.equal(await navigate(page, '/portal/surveys', { bypassDirtyCheck: true }), false);
    await assertKept(page, state, before, reads);
    await page.locator('#appFormModal [data-app-modal-close]').first().click(); await page.locator('#appFormModal').waitFor({ state: 'detached' });
    await page.evaluate(() => { for (const id of ['profilePhotoForm', 'emergencyContactForm']) { document.getElementById(id).requestSubmit(); document.getElementById(id).requestSubmit(); } });
    await settle(page); assert.equal(state.posts.length, 1); assert.equal(state.activeWrites, 1); assert.equal(state.maxConcurrentWrites, 1);
    assert.deepEqual(await page.evaluate(() => fixtureConfirmMessages), [], 'Veto cannot be bypassed with dirty consent or summon a dirty modal');
    assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
    assert.equal(await page.locator('#emergencyContactName').inputValue(), 'Pending independent contact draft');
    gate.release(); state.writeGate = null;
    await page.waitForFunction(() => !document.querySelector('[data-profile-settings] form[aria-busy="true"]'));
    await until(() => state.posts[0].finished, 'The one profile write completes'); await settle(page);
    assert.equal(state.posts.length, 1, 'Blocked duplicate or cross-form submissions are never replayed');
    assert.equal(state.posts[0].path, operation === 'photo' ? PHOTO : operation === 'contact' ? CONTACT : REMOVE);
    assert.equal(await page.locator('#profilePhotoUpload').isEnabled(), true); assert.equal(documents.length, 1);
    if (operation !== 'contact') assert.equal(await page.locator('#emergencyContactName').inputValue(), 'Pending independent contact draft');
    else assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  });
}

test('an independently open real form modal vetoes pane APIs and Back, then closes without a write or global teardown', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t); await settings(page); const before = await entry(page), reads = paneReads(state).length;
  await page.locator('#main .portal-request-actions a[data-app-modal-open][href$="/portal/appointments/clinic/create"]').click(); await page.locator('#appFormModal form').waitFor();
  await recordConfirm(page, [true]);
  for (const owner of ['SynapseNavigation', 'SynapsePortalWorkspace']) assert.equal(await navigate(page, '/portal/surveys', { bypassDirtyCheck: true }, owner), false);
  await page.evaluate(() => history.back()); await page.waitForURL(before.url); await ready(page); await assertKept(page, state, before, reads);
  assert.equal(await page.locator('#appFormModal form').count(), 1); assert.deepEqual(await page.evaluate(() => fixtureConfirmMessages), []);
  await page.locator('#appFormModal [data-app-modal-close]').first().click(); await page.locator('#appFormModal').waitFor({ state: 'detached' });
  assert.equal(await navigate(page, '/portal/surveys'), true); await ready(page);
  assert.equal(state.posts.length, 0); assert.equal(await page.evaluate(() => fixtureGlobalCleanups), 0);
});

test('portal shell places quick services in page actions, tickets above profile, and request buttons below profile', { timeout: 25000 }, async t => {
  const { page } = await pageFor(t);
  assert.equal(await page.locator('.portal-left-rail > [data-personal-queue]').count(), 1);
  assert.equal(await page.locator('#main .page-actions .portal-app-dock').count(), 1);
  assert.equal(await page.locator('#main .page-actions a[href$="/portal/visits"]').count(), 0, 'My Visit History header button removed');
  assert.equal(await page.locator('#app-sidebar a[href$="/portal/visits"]').count(), 1, 'My Visit History remains in the sidebar');
  assert.equal(await page.locator('#portal-workspace-pane > .portal-workspace-header h2.portal-workspace-title').innerText(), 'My Appointments', 'My Appointments matches other portal pane headers');
  assert.equal(await page.locator('.portal-left-rail .portal-request-actions a[data-app-modal-open]').count(), 2);
  assert.equal(await page.locator('.portal-left-rail .portal-request-actions svg.portal-request-icon').count(), 2, 'Request buttons use Clinic and Guidance vector SVGs');
  assert.equal(await page.locator('.portal-left-rail .portal-request-actions i').count(), 0, 'Calendar icon fonts replaced by vector SVGs');
  const lightBtnColors = await page.locator('.portal-left-rail .portal-request-actions .portal-request-btn').evaluateAll(btns =>
    btns.map(b => getComputedStyle(b).backgroundColor));
  assert.deepEqual(lightBtnColors, ['rgb(128, 0, 0)', 'rgb(128, 0, 0)'], 'Both request buttons are maroon in light mode');
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await page.waitForFunction(() => {
    const btns = [...document.querySelectorAll('.portal-left-rail .portal-request-actions .portal-request-btn')];
    return btns.length === 2 && btns.every(b => getComputedStyle(b).backgroundColor === 'rgb(30, 30, 30)');
  });
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
  await page.waitForFunction(() => {
    const btns = [...document.querySelectorAll('.portal-left-rail .portal-request-actions .portal-request-btn')];
    return btns.length === 2 && btns.every(b => getComputedStyle(b).backgroundColor === 'rgb(128, 0, 0)');
  });

  const desktop = await page.evaluate(() => {
    const q = document.querySelector('[data-personal-queue]').getBoundingClientRect();
    const p = document.querySelector('.portal-profile-stack').getBoundingClientRect();
    const r = document.querySelector('.portal-request-actions').getBoundingClientRect();
    const w = document.querySelector('#portal-workspace-pane').getBoundingClientRect();
    const dock = document.querySelector('#main .page-actions .portal-app-dock').getBoundingClientRect();
    const tile = document.querySelector('#main .page-actions .portal-app-tile').getBoundingClientRect();
    return { overflow: document.documentElement.scrollWidth > window.innerWidth, q, p, r, w, dock, tile };
  });
  assert.equal(desktop.overflow, false);
  assert.ok(desktop.dock.bottom <= desktop.q.top + 2, 'Quick Services sits in the top header above the workspace row');
  assert.ok(Math.abs(desktop.tile.width - desktop.tile.height) <= 2, 'Quick Services tiles remain square');
  assert.ok(desktop.q.bottom <= desktop.p.top + 2, 'Tickets render directly above the profile card');
  assert.ok(Math.abs(desktop.q.width - desktop.p.width) <= 2, 'Tickets match the 360px profile card width on desktop');
  assert.ok(desktop.r.top >= desktop.p.bottom - 2, 'Request buttons sit directly below the profile card on desktop');
  assert.ok(Math.abs(desktop.r.width - desktop.p.width) <= 2, 'Request buttons match the 360px profile card width on desktop');
  assert.ok(Math.abs(desktop.w.top - desktop.q.top) <= 6, 'Workspace pane starts at the top alongside the ticket rail');
  assert.ok(desktop.q.right <= desktop.w.left + 2, 'Tickets do not span above the workspace pane');

  await page.setViewportSize({ width: 992, height: 900 });
  const tablet = await page.evaluate(() => {
    const q = document.querySelector('[data-personal-queue]').getBoundingClientRect();
    const p = document.querySelector('.portal-profile-stack').getBoundingClientRect();
    const r = document.querySelector('.portal-request-actions').getBoundingClientRect();
    return { overflow: document.documentElement.scrollWidth > window.innerWidth, q, p, r };
  });
  assert.equal(tablet.overflow, false);
  assert.ok(tablet.q.bottom <= tablet.p.top + 2, 'Tickets sit above profile card on tablet');
  assert.ok(tablet.q.right <= tablet.r.left + 2, 'Request actions sit neatly in column 2 alongside Ticket/Profile on tablet');

  await page.setViewportSize({ width: 375, height: 812 });
  const mobile = await page.evaluate(() => {
    const q = document.querySelector('[data-personal-queue]').getBoundingClientRect();
    const p = document.querySelector('.portal-profile-stack').getBoundingClientRect();
    const r = document.querySelector('.portal-request-actions').getBoundingClientRect();
    return { overflow: document.documentElement.scrollWidth > window.innerWidth, q, p, r };
  });
  assert.equal(mobile.overflow, false);
  assert.ok(mobile.q.bottom <= mobile.p.top + 2, 'Tickets sit above profile card on mobile');
  assert.ok(mobile.r.top >= mobile.p.bottom - 2, 'Request buttons sit below profile card on mobile');
  assert.ok(Math.abs(mobile.q.width - mobile.p.width) <= 2, 'Tickets match profile width on mobile');

  await page.setViewportSize({ width: 1280, height: 900 });
  for (const [pane, expectedTitle] of [['announcements', 'Announcements'], ['surveys', 'My Surveys']]) {
    await page.locator(`.portal-app-dock [data-portal-pane-link="${pane}"]`).click();
    await ready(page);
    assert.equal(await page.locator('#portal-workspace-pane .portal-workspace-title').innerText(), expectedTitle);
    assert.equal(await page.locator('#portal-workspace-pane .portal-workspace-actions').getByRole('link', { name: 'Back to My Health Portal', exact: true }).count(), 1);
  }
});
