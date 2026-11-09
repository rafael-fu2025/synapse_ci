'use strict';

// Real PHP views and production assets; only the HTTP persistence/transport is synthetic.
// PHP tests separately cover authorization, CSRF enforcement and secure image processing.
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
const PHOTO = '/portal/settings/photo';
const REMOVE = '/portal/settings/photo/remove';
const CONTACT = '/portal/settings/emergency-contact';
const fields = ['emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship'];
const initialContact = {
  emergency_contact_name: 'Maria Santos',
  emergency_contact_phone: '0917-123-4567',
  emergency_contact_relationship: 'Mother'
};
const changedContact = {
  emergency_contact_name: 'Dr. Roberto Santos',
  emergency_contact_phone: '+63 918 555 1234',
  emergency_contact_relationship: 'Father'
};
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=', 'base64');
const sessions = new Map(), rendered = new Map(), serverErrors = [];
let browser, server, origin;

function render(requestPath, state) {
  const fixture = {
    origin, path: requestPath, kind: state.kind, hasPhoto: state.hasPhoto,
    contact: state.contact, csrfHash: state.csrfHash, successMessage: state.successMessage || ''
  };
  const key = JSON.stringify(fixture);
  if (!rendered.has(key)) {
    const result = spawnSync('php', [path.join(root, 'tests/_support/render-profile-settings-fixture.php')], {
      cwd: root, input: key, encoding: 'utf8', timeout: 20000
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code":\s*500/);
    assert.match(result.stdout, /<!doctype html>/i, 'The real fixture must render a complete app document');
    rendered.set(key, result.stdout);
  }
  return rendered.get(key);
}

function cookiesOf(req) {
  return Object.fromEntries((req.headers.cookie || '').split(';').filter(Boolean).map(item => {
    const index = item.indexOf('=');
    return [item.slice(0, index).trim(), item.slice(index + 1)];
  }));
}
function normalizeContact(data) {
  return Object.fromEntries(fields.map(name => [name, String(data.get(name) || '').trim() || null]));
}
function defer(t) {
  let release;
  const promise = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  return { promise, release };
}
async function waitFor(predicate, message, timeout = 6000) {
  const deadline = Date.now() + timeout;
  while (!predicate()) {
    assert.ok(Date.now() < deadline, message);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
}

before(async () => {
  let executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || chromium.executablePath();
  if (!fs.existsSync(executablePath) && !process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) {
    const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'win32'
      ? path.join(process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData/Local'), 'ms-playwright')
      : process.platform === 'darwin' ? path.join(os.homedir(), 'Library/Caches/ms-playwright')
        : path.join(process.env.XDG_CACHE_HOME || path.join(os.homedir(), '.cache'), 'ms-playwright'));
    if (fs.existsSync(cache)) executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium', 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing']
        .map(relative => path.join(cache, name, relative))).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath && fs.existsSync(executablePath), 'An installed Playwright Chromium runtime is required; set PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH if necessary');
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin);
      const state = sessions.get(cookiesOf(req).fixtureSession);
      if (req.method === 'POST') {
        if (!state || ![PHOTO, REMOVE, CONTACT].includes(url.pathname)) { res.writeHead(404).end(); return; }
        const chunks = []; for await (const chunk of req) chunks.push(chunk);
        const data = await new Request(origin + req.url, {
          method: 'POST', headers: { 'Content-Type': req.headers['content-type'] }, body: Buffer.concat(chunks)
        }).formData();
        const file = data.get('photo');
        const entry = {
          path: url.pathname, names: [...data.keys()], token: data.get('csrf_test_name'),
          csrfHeader: req.headers['x-csrf-token'], ajax: req.headers['x-requested-with'] === 'XMLHttpRequest',
          accept: req.headers.accept, size: file?.size, type: file?.type, filename: file?.name,
          contact: url.pathname === CONTACT ? normalizeContact(data) : null, finished: false
        };
        const answer = { ...(state.responses.get(url.pathname) || {}) }, gate = state.writeGate;
        state.posts.push(entry);
        ++state.activeWrites;
        state.maxConcurrentWrites = Math.max(state.maxConcurrentWrites, state.activeWrites);
        try {
          if (gate) await gate.promise;
          const status = answer.status || 200, success = status >= 200 && status < 300;
          state.csrfHash = crypto.randomBytes(16).toString('hex');
          entry.responseToken = state.csrfHash;
          if (success) {
            if (url.pathname === CONTACT) state.contact = entry.contact;
            else state.hasPhoto = url.pathname !== REMOVE;
          }
          const message = answer.message || (success
            ? url.pathname === CONTACT ? 'Emergency contact updated.' : url.pathname === REMOVE ? 'Profile picture removed.' : 'Profile picture updated.'
            : 'The save was rejected. Your existing details were kept.');
          if (!entry.ajax) {
            state.successMessage = message;
            res.writeHead(303, { Location: '/portal/settings' }).end();
            return;
          }
          const headers = {
            'Content-Type': answer.contentType || 'application/json',
            'X-CSRF-NAME': 'csrf_test_name', 'X-CSRF-TOKEN': state.csrfHash, 'X-CSRF-HEADER': 'X-CSRF-TOKEN'
          };
          const payload = {
            success, message, csrf: { name: 'csrf_test_name', hash: state.csrfHash },
            ...(url.pathname === CONTACT ? { emergency_contact: entry.contact } : {
              has_photo: state.hasPhoto, photo_url: origin + '/account/photo?v=' + state.posts.length
            }),
            ...(answer.payload || {})
          };
          res.writeHead(status, headers).end(answer.body ?? JSON.stringify(payload));
        } finally {
          --state.activeWrites;
          entry.finished = true;
        }
        return;
      }
      if (['/portal/settings', '/portal/announcements', '/portal'].includes(url.pathname)) {
        assert.ok(state, 'A per-context synthetic session is required');
        state.reads.push({ path: url.pathname, search: url.search, headers: req.headers });
        const gate = state.readGates.get(url.pathname);
        if (gate) await gate.promise;
        if (!res.destroyed) res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }).end(render(url.pathname + url.search, state));
        return;
      }
      if ([PHOTO, '/account/photo'].includes(url.pathname)) {
        res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff' }).end(png);
        return;
      }
      if (url.pathname === '/notifications') {
        res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ success: true, notifications: [], unread_count: 0 }));
        return;
      }
      if (!url.pathname.startsWith('/assets/')) { res.writeHead(404).end(); return; }
      const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.woff2': 'font/woff2', '.woff': 'font/woff' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(fs.readFileSync(file));
    } catch (error) {
      serverErrors.push(error.message);
      if (!res.headersSent) res.writeHead(500);
      res.end(String(error.stack));
    }
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });

after(async () => {
  for (const state of sessions.values()) {
    state.writeGate?.release();
    for (const gate of state.readGates.values()) gate.release();
  }
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
  assert.deepEqual(serverErrors, [], 'No PHP fixture or synthetic receiver errors');
}, { timeout: 20000 });

async function pageFor(t, options = {}) {
  const state = {
    id: crypto.randomUUID(), kind: options.kind || 'student', hasPhoto: options.hasPhoto ?? true,
    contact: { ...initialContact, ...options.contact }, csrfHash: crypto.randomBytes(16).toString('hex'),
    posts: [], reads: [], responses: new Map(), readGates: new Map(), writeGate: null,
    activeWrites: 0, maxConcurrentWrites: 0, successMessage: ''
  };
  sessions.set(state.id, state);
  const context = await browser.newContext({
    viewport: { width: options.width || 1280, height: 900 }, javaScriptEnabled: options.javaScript !== false
  });
  await context.addCookies([{ name: 'fixtureSession', value: state.id, url: origin }]);
  await context.addInitScript(dark => {
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    window.createdBlobs = []; window.revokedBlobs = []; window.fixturePostSignals = [];
    const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
    URL.createObjectURL = value => { const url = create(value); window.createdBlobs.push(url); return url; };
    URL.revokeObjectURL = url => { window.revokedBlobs.push(url); revoke(url); };
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (resource, options = {}) => {
      const url = new URL(typeof resource === 'string' ? resource : resource.url, location.href);
      if (options.method?.toUpperCase() === 'POST' && url.pathname.startsWith('/portal/settings/')) {
        window.fixturePostSignals.push(options.signal);
        // Opt in only in teardown-race tests: an abort need not retract a server write/late response.
        if (window.fixtureIgnorePostAbort) return nativeFetch(resource, { ...options, signal: undefined });
      }
      return nativeFetch(resource, options);
    };
  }, Boolean(options.dark));
  await context.route('**/*', route => new URL(route.request().url()).origin === origin
    ? route.continue() : route.fulfill({ status: 200, body: '' }));
  const page = await context.newPage(), errors = [], documents = [];
  page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
  });
  t.after(async () => {
    state.writeGate?.release();
    for (const gate of state.readGates.values()) gate.release();
    await context.close();
    sessions.delete(state.id);
    assert.deepEqual(errors, [], 'No page JavaScript errors');
  });
  await page.goto(origin + (options.start || '/portal/settings'));
  if (options.javaScript !== false) await ready(page, (options.start || '/portal/settings').startsWith('/portal/settings'));
  return { page, state, documents };
}
async function ready(page, settings = true) {
  await page.waitForFunction(settings => Boolean(window.SynapseNavigation && window.SynapseProfileSettings &&
    document.querySelector('#main h1') && !document.body.classList.contains('is-navigating') &&
    (!settings || document.querySelector('#main [data-profile-settings]'))), settings);
}
async function settle(page) {
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}
async function assertNoProfileModal(page) {
  assert.equal(await page.locator('#appFormModal').count(), 0);
  assert.equal(await page.locator('#main [data-app-modal-close], #main [data-modal-error]').count(), 0);
  assert.equal(await page.locator('.modal-backdrop').count(), 0);
  assert.equal(await page.locator('body').evaluate(body => body.classList.contains('modal-open')), false);
}
async function assertSettingsPage(page) {
  assert.equal(new URL(page.url()).pathname, '/portal/settings');
  assert.equal(await page.locator('#main h1').count(), 1);
  assert.equal(await page.locator('#main h1').innerText(), 'Profile & Settings');
  assert.equal(await page.locator('#main [data-profile-settings]').count(), 1);
  assert.equal(await page.locator('[data-profile-settings]').evaluate(panel =>
    ['profilePhotoForm', 'emergencyContactForm', 'profilePhotoTitle', 'profileAccountTitle', 'profileEmergencyTitle']
      .every(id => panel.contains(document.getElementById(id))) && !panel.closest('[role="dialog"], .modal')), true);
  const back = page.locator('#main .page-actions').getByRole('link', { name: 'Back to My Health Portal', exact: true });
  assert.equal(new URL(await back.getAttribute('href'), origin).href, origin + '/portal');
  assert.equal(await back.getAttribute('data-app-modal-close'), null);
  for (const [selector, action] of [['#profilePhotoForm', PHOTO], ['[data-profile-photo-remove]', REMOVE], ['#emergencyContactForm', CONTACT]]) {
    assert.equal(await page.locator(selector).count(), 1);
    assert.equal((await page.locator(selector).getAttribute('method')).toUpperCase(), 'POST');
    assert.equal(new URL(await page.locator(selector).getAttribute('action'), origin).pathname, action);
  }
  await assertNoProfileModal(page);
}
async function rememberShell(page) {
  await page.evaluate(() => { window.fixtureShell = {
    main: document.getElementById('main'), header: document.querySelector('header.header'), sidebar: document.querySelector('.sidebar')
  }; });
}
async function assertSoftSwap(page, documents, documentCount) {
  assert.equal(documents.length, documentCount, 'Eligible entry must not request a new browser document');
  assert.equal(await page.evaluate(() => Boolean(window.fixtureShell &&
    window.fixtureShell.main !== document.getElementById('main') &&
    window.fixtureShell.header === document.querySelector('header.header') &&
    window.fixtureShell.sidebar === document.querySelector('.sidebar'))), true, 'Only main is replaced; the shell stays alive');
  assert.equal(await page.locator('#main h1').evaluate(heading => heading === document.activeElement), true, 'Navigation focuses the incoming H1');
}
async function entryLink(page, source) {
  if (source === 'account') await page.locator('.user-trigger').click();
  const link = source === 'account' ? page.locator('.user-menu a[href$="/portal/settings"]')
    : source === 'sidebar' ? page.locator('.sidebar-nav a[href$="/portal/settings"]')
      : page.locator('[data-portal-emergency-card]').getByRole('link', { name: source === 'portal-add' ? 'Add emergency contact' : 'Edit', exact: true });
  assert.equal(await link.count(), 1);
  if (source === 'sidebar' && (await link.boundingBox())?.x < 0) await page.locator('.sidebar-toggle').click();
  for (const attribute of ['data-app-modal-open', 'data-app-modal-close', 'data-bs-toggle', 'data-bs-target']) {
    assert.equal(await link.getAttribute(attribute), null, `${source} entry is an ordinary link`);
  }
  if (source === 'account') assert.notEqual(await link.getAttribute('data-navigation'), null);
  return link;
}
async function open(page, source = 'sidebar') {
  const link = await entryLink(page, source);
  const response = page.waitForResponse(response => new URL(response.url()).pathname === '/portal/settings' && response.request().method() === 'GET');
  await link.click();
  const fetched = await response, html = await fetched.text();
  assert.match(html, /<html\b/i);
  assert.doesNotMatch(html, /id=["']appFormModal["']|data-app-modal-close|data-modal-error/);
  assert.equal(fetched.request().headers()['x-synapse-navigation'], '1');
  assert.equal(fetched.request().headers()['x-requested-with'], undefined);
  assert.equal(fetched.request().headers()['hx-request'], undefined);
  assert.equal(new URL(fetched.url()).searchParams.has('fragment'), false);
  await page.waitForURL(origin + '/portal/settings');
  await ready(page);
  await assertSettingsPage(page);
}
async function select(page, buffer = png, name = 'portrait.png') {
  const previous = await page.locator('[data-profile-photo-preview]').getAttribute('src');
  await page.locator('#profilePhotoUpload').setInputFiles({ name, mimeType: 'image/png', buffer });
  await page.waitForFunction(({ previous, name }) => {
    const preview = document.querySelector('[data-profile-photo-preview]');
    return document.querySelector('#profilePhotoUpload').files[0]?.name === name && preview && !preview.hidden &&
      preview.src.startsWith('blob:') && preview.getAttribute('src') !== previous;
  }, { previous, name });
  return page.locator('[data-profile-photo-preview]').getAttribute('src');
}
async function fillContact(page, contact = changedContact) {
  for (const name of fields) await page.locator(`#emergencyContactForm [name="${name}"]`).fill(contact[name] ?? '');
}
async function contactValues(page) {
  return page.locator('#emergencyContactForm').evaluate(form => Object.fromEntries(
    ['emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship'].map(name => [name, form.elements.namedItem(name).value])));
}
async function save(page, operation, text = operation === 'contact' ? 'Emergency contact updated.' : 'Profile picture updated.') {
  await page.getByRole('button', { name: operation === 'contact' ? 'Save emergency contact' : 'Upload picture', exact: true }).click();
  await feedback(page, operation, text);
}
async function feedback(page, operation, text) {
  const selector = operation === 'contact' ? '[data-emergency-contact-feedback]' : '[data-profile-photo-feedback]';
  await page.waitForFunction(({ selector, text }) => document.querySelector(selector)?.textContent.includes(text) &&
    !document.querySelector('[data-profile-settings] form[aria-busy="true"]'), { selector, text });
}
async function resetPhoto(page) {
  await page.locator('#profilePhotoForm').evaluate(form => form.reset());
  await page.waitForFunction(() => !document.querySelector('#profilePhotoUpload').files.length && document.querySelector('[data-profile-photo-preview]').hidden);
  await settle(page);
}
async function recordConfirm(page, answer) {
  await page.evaluate(answer => {
    window.fixtureConfirmMessages = [];
    window.confirm = message => { window.fixtureConfirmMessages.push(message); return answer; };
  }, answer);
}
async function expectDirtyStay(page, state) {
  await settle(page);
  const address = page.url(), reads = state.reads.length;
  await rememberShell(page); await recordConfirm(page, false);
  assert.equal(await page.evaluate(target => SynapseNavigation.navigate(target, { fallback: false }), origin + '/portal/announcements'), false);
  assert.equal(page.url(), address);
  assert.equal(await page.evaluate(() => document.getElementById('main') === window.fixtureShell.main), true);
  assert.equal(await page.evaluate(() => window.fixtureConfirmMessages.length), 1, 'The existing window.confirm navigator owns dirty consent');
  assert.equal(state.reads.length, reads, 'Cancel must not fetch or discard the outgoing draft');
  assert.equal(await page.locator('#synapseConfirmModal.show').count(), 0, 'Dirty navigation must not use the removal confirmation UI');
  await assertNoProfileModal(page);
}
async function navigate(page, target, options = { fallback: false }) {
  // Keep DevTools from awaiting a collectible Promise across the main swap.
  // Node observes completion; preserve the navigator's exact resolved result.
  await page.evaluate(({ target, options }) => {
    const result = window.fixtureNavigationResult = { settled: false };
    SynapseNavigation.navigate(target, options).then(value => {
      result.value = value; result.settled = true;
    }, error => {
      result.error = error.message; result.settled = true;
    });
  }, { target, options });
  await page.waitForFunction(() => window.fixtureNavigationResult?.settled);
  const result = await page.evaluate(() => {
    const result = window.fixtureNavigationResult;
    delete window.fixtureNavigationResult;
    return result;
  });
  assert.equal(result.error, undefined, 'Navigation must resolve without an error');
  return result.value;
}
async function expectCleanLeave(page) {
  await settle(page); await recordConfirm(page, false);
  assert.equal(await page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented;
  }), false, 'A saved or reverted page has no native unload warning');
  assert.equal(await navigate(page, origin + '/portal'), true);
  await page.waitForURL(origin + '/portal'); await ready(page, false);
  assert.deepEqual(await page.evaluate(() => window.fixtureConfirmMessages), [], 'A reverted or saved page does not ask to discard');
}
async function nativeDialog(page, action, type, accept) {
  const pending = page.waitForEvent('dialog');
  const work = Promise.resolve().then(action).then(value => ({ value }), error => ({ error }));
  const dialog = await pending;
  assert.equal(dialog.type(), type);
  if (accept) await dialog.accept(); else await dialog.dismiss();
  return work;
}
async function makeDraft(page, kind) {
  let blob = null;
  if (kind === 'photo' || kind === 'both') blob = await select(page);
  if (kind === 'contact' || kind === 'both') await fillContact(page);
  return blob;
}
async function mutationControlsDisabled(page) {
  await page.waitForFunction(() => [...document.querySelectorAll('[data-profile-settings] input:not([type="hidden"]), [data-profile-settings] select, [data-profile-settings] textarea, [data-profile-settings] button')]
    .every(control => control.matches(':disabled')));
}

for (const kind of ['student', 'employee']) for (const fragment of [false, true]) {
  test(`${kind} direct${fragment ? ' fragment=1' : ''} GET is one ordinary full settings page with a fixed portal action`, async t => {
    const start = '/portal/settings' + (fragment ? '?fragment=1&return_url=https%3A%2F%2Fexample.test%2Funsafe' : '');
    const { page, state } = await pageFor(t, { kind, start });
    await assertSettingsPage(page);
    assert.equal(state.reads.length, 1);
    assert.equal(await page.locator('[data-profile-settings] [data-profile-photo-current]').count(), 1);
    assert.equal(await page.locator('[data-profile-settings] [data-emergency-contact-feedback]').count(), 1);
    assert.equal(state.posts.length, 0);
  });
}
for (const source of ['account', 'sidebar', 'portal-edit', 'portal-add']) {
  test(`${source} entry replaces main, changes URL/history and focuses H1 without a profile modal`, async t => {
    const { page, documents } = await pageFor(t, {
      kind: source === 'account' ? 'employee' : 'student', start: source.startsWith('portal-') ? '/portal' : '/portal/announcements',
      contact: source === 'portal-add' ? Object.fromEntries(fields.map(name => [name, null])) : undefined
    });
    const documentCount = documents.length;
    await rememberShell(page);
    await open(page, source);
    await assertSoftSwap(page, documents, documentCount);
    assert.equal(await page.locator('.sidebar-nav a[href$="/portal/settings"]').evaluate(link => link.classList.contains('active')), true);
    await page.evaluate(() => history.back());
    await page.waitForURL(origin + (source.startsWith('portal-') ? '/portal' : '/portal/announcements'));
    await ready(page, false);
    assert.equal(await page.locator('[data-profile-settings]').count(), 0);
    assert.equal(documents.length, documentCount, 'Managed Back also stays in the persistent shell');
  });
}

for (const kind of ['student', 'employee']) for (const narrow of [false, true]) {
  test(`${kind} ${narrow ? 'dark narrow' : 'light desktop'} settings keeps accessible sections and safe white initials on maroon`, async t => {
    const { page, state } = await pageFor(t, { kind, dark: narrow, width: narrow ? 390 : 1280, hasPhoto: false });
    await assertSettingsPage(page);
    assert.equal(await page.locator('html').getAttribute('data-theme'), narrow ? 'dark' : 'light');
    const initials = kind === 'student' ? 'SS' : 'SE';
    for (const selector of ['.user-avatar', '.user-menu-avatar', '[data-profile-photo-current]']) {
      const src = await page.locator(selector).getAttribute('src');
      assert.match(src, /^data:image\/svg\+xml;base64,/);
      const svg = Buffer.from(src.split(',')[1], 'base64').toString('utf8');
      assert.ok(svg.includes(`>${initials}</text>`));
      assert.ok(svg.includes('fill="#800000"')); assert.ok(svg.includes('fill="#ffffff"'));
      assert.doesNotMatch(svg, /<script|<image|foreignObject|onload=/);
    }
    assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), false);
    assert.match(await page.locator('#profilePhotoHelp').innerText(), /10 MB/);
    assert.equal(await page.locator('#profilePhotoUpload').getAttribute('accept'), 'image/jpeg,image/png,image/webp');
    assert.equal(await page.getByLabel('Contact person', { exact: true }).getAttribute('id'), 'emergencyContactName');
    assert.equal(await page.getByLabel('Relationship', { exact: true }).getAttribute('id'), 'emergencyContactRelationship');
    assert.equal(await page.getByLabel('Emergency phone', { exact: true }).getAttribute('id'), 'emergencyContactPhone');
    assert.equal(await page.locator('#profileAccountTitle').evaluate(title => title.closest('section').querySelectorAll('input, select, textarea').length), 0, 'MIS account details stay read-only');
    for (const selector of ['#profilePhotoUpload', '#emergencyContactName', '#emergencyContactPhone', '#emergencyContactRelationship']) {
      await page.locator(selector).scrollIntoViewIfNeeded();
      const bounds = await page.locator(selector).boundingBox();
      assert.ok(bounds && bounds.x >= -1 && bounds.x + bounds.width <= (narrow ? 390 : 1280) + 1, `${selector} remains within the viewport`);
      assert.equal(await page.locator(selector).isEnabled(), true);
    }
    assert.equal(await page.locator('#main').evaluate(main => main.scrollWidth <= main.clientWidth + 2), true, 'Settings has no horizontal content overflow');
    await page.locator('.user-avatar').evaluate(image => { image.src = '/missing-avatar.webp'; });
    await page.waitForFunction(() => document.querySelector('.user-avatar').src.startsWith('data:image/svg+xml;base64,') && document.querySelector('.user-avatar').complete);
    assert.equal(await page.locator('.user-avatar').evaluate(image => image.naturalWidth), 128);
    assert.equal(state.posts.length, 0);
  });
}

test('photo size/type/decode validation, replacement and clearing are local previews, never autosaves', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t);
  const input = page.locator('#profilePhotoUpload'), current = await page.locator('[data-profile-photo-current]').getAttribute('src');
  await input.setInputFiles({ name: 'too-large.png', mimeType: 'image/png', buffer: Buffer.alloc(10 * 1024 * 1024 + 1) });
  assert.equal(await input.evaluate(input => input.checkValidity()), false);
  await page.getByRole('button', { name: 'Upload picture', exact: true }).click();
  await input.setInputFiles({ name: 'unsafe.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg onload="window.photoXss=1"/>') });
  assert.equal(await input.evaluate(input => input.checkValidity()), false);
  await input.setInputFiles({ name: 'empty.png', mimeType: 'image/png', buffer: Buffer.alloc(0) });
  assert.equal(await input.evaluate(input => input.checkValidity()), false);
  await input.setInputFiles({ name: 'broken.png', mimeType: 'image/png', buffer: Buffer.from('not an image') });
  await page.waitForFunction(() => document.querySelector('#profilePhotoUpload').validity.customError);
  const first = await select(page), second = await select(page, png, 'replacement.png');
  assert.notEqual(first, second);
  assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(first));
  assert.equal(await page.locator('[data-profile-photo-current]').getAttribute('src'), current);
  if (await page.locator('[data-profile-photo-clear]').count()) {
    assert.equal(await page.locator('[data-profile-photo-clear]').isVisible(), true);
    await page.locator('[data-profile-photo-clear]').click();
  } else await resetPhoto(page);
  await page.waitForFunction(() => document.querySelector('[data-profile-photo-preview]').hidden);
  assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(second));
  await select(page);
  await input.setInputFiles([]); await settle(page);
  assert.equal(await page.locator('[data-profile-photo-preview]').isVisible(), false);
  assert.equal(await input.evaluate(input => input.checkValidity()), false, 'Native required-file validation remains in place');
  assert.equal(state.posts.length, 0);
  assert.equal(await page.evaluate(() => window.photoXss), undefined);
  await expectCleanLeave(page);
});

test('photo dimension limits reject more than 10,000 pixels per side or 20 megapixels before a POST', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t);
  for (const [width, height] of [[10001, 1], [5000, 4001]]) {
    const base64 = await page.evaluate(({ width, height }) => {
      const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
      return canvas.toDataURL('image/png').split(',')[1];
    }, { width, height });
    await page.locator('#profilePhotoUpload').setInputFiles({ name: `oversized-${width}.png`, mimeType: 'image/png', buffer: Buffer.from(base64, 'base64') });
    await page.waitForFunction(() => document.querySelector('#profilePhotoUpload').validity.customError);
    assert.equal(await page.locator('[data-profile-photo-preview]').isVisible(), false);
    await page.getByRole('button', { name: 'Upload picture', exact: true }).click();
  }
  assert.equal(state.posts.length, 0);
  await resetPhoto(page); await expectCleanLeave(page);
});

test('10 MB explicit JSON upload updates avatars in place; 422 keeps the file/preview and retry sends fresh CSRF safely', { timeout: 30000 }, async t => {
  const { page, state, documents } = await pageFor(t, { hasPhoto: false });
  await rememberShell(page);
  const address = page.url(), documentCount = documents.length, buffer = Buffer.alloc(10 * 1024 * 1024); png.copy(buffer);
  const blob = await select(page, buffer, 'full-limit.png');
  const rejection = '<img src=x onerror="window.photoXss=1"> Upload rejected';
  state.responses.set(PHOTO, { status: 422, message: rejection });
  await save(page, 'photo', rejection);
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files[0].size), buffer.length);
  assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.equal(await page.locator('[data-profile-photo-feedback] img').count(), 0);
  assert.equal(await page.evaluate(() => window.photoXss), undefined);
  assert.equal(await page.locator('#profilePhotoUpload').isEnabled(), true);
  await expectDirtyStay(page, state);
  state.responses.delete(PHOTO);
  await save(page, 'photo');
  assert.equal(page.url(), address); assert.equal(documents.length, documentCount);
  assert.equal(await page.evaluate(() => document.getElementById('main') === window.fixtureShell.main), true);
  assert.equal(state.posts.length, 2);
  const request = state.posts[1];
  assert.equal(request.token, state.posts[0].responseToken); assert.equal(request.csrfHeader, request.token);
  assert.equal(request.ajax, true); assert.match(request.accept, /application\/json/);
  assert.equal(request.size, buffer.length); assert.equal(request.filename, 'full-limit.png'); assert.equal(request.type, 'image/png');
  assert.deepEqual(request.names.sort(), ['MAX_FILE_SIZE', 'csrf_test_name', 'photo'].sort());
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 0);
  assert.equal(await page.locator('[data-profile-photo-preview]').isVisible(), false);
  assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(blob));
  const photo = await page.locator('[data-profile-photo-current]').getAttribute('src');
  assert.match(photo, /\/account\/photo\?v=/);
  assert.deepEqual(await page.locator('[data-profile-avatar]').evaluateAll(images => images.map(image => image.getAttribute('src'))), [photo, photo]);
  assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), true);
  await assertNoProfileModal(page); await expectCleanLeave(page);
});

test('contact saves only on explicit submit, adopts the normalized response baseline, and appears on a freshly fetched portal', async t => {
  const { page, state, documents } = await pageFor(t);
  const documentCount = documents.length;
  await fillContact(page, Object.fromEntries(fields.map(name => [name, '  ' + changedContact[name] + '  '])));
  await settle(page); assert.equal(state.posts.length, 0);
  await save(page, 'contact');
  assert.equal(state.posts.length, 1);
  assert.equal(state.posts[0].path, CONTACT); assert.equal(state.posts[0].token, state.posts[0].csrfHeader);
  assert.deepEqual(state.posts[0].names.sort(), [...fields, 'csrf_test_name'].sort());
  assert.deepEqual(await contactValues(page), changedContact); assert.deepEqual(state.contact, changedContact);
  await recordConfirm(page, false); await rememberShell(page);
  await page.locator('#main .page-actions').getByRole('link', { name: 'Back to My Health Portal', exact: true }).click();
  await page.waitForURL(origin + '/portal'); await ready(page, false);
  await assertSoftSwap(page, documents, documentCount);
  assert.deepEqual(await page.evaluate(() => window.fixtureConfirmMessages), []);
  const card = page.locator('[data-portal-emergency-card]');
  assert.match(await card.innerText(), /Dr\. Roberto Santos/); assert.match(await card.innerText(), /Father/);
  assert.doesNotMatch(await card.innerText(), /Maria Santos/);
  assert.equal(await card.locator('a[href^="tel:"]').getAttribute('href'), 'tel:+639185551234');
  assert.equal(state.reads.at(-1).path, '/portal', 'The card is server-rendered from persisted contact state, not an old modal patch');
  await open(page, 'portal-edit');
  assert.deepEqual(await contactValues(page), changedContact);
  await expectCleanLeave(page);
});

test('canonical blank contact fields become null, clear the draft baseline and render a normal Add link on return', async t => {
  const { page, state } = await pageFor(t);
  await fillContact(page, Object.fromEntries(fields.map(name => [name, '  '])));
  await save(page, 'contact');
  assert.deepEqual(state.contact, Object.fromEntries(fields.map(name => [name, null])));
  assert.deepEqual(await contactValues(page), Object.fromEntries(fields.map(name => [name, ''])));
  await expectCleanLeave(page);
  assert.match(await page.locator('[data-portal-emergency-content]').innerText(), /No emergency contact recorded/);
  await open(page, 'portal-add');
  assert.deepEqual(await contactValues(page), Object.fromEntries(fields.map(name => [name, ''])));
});

for (const kind of ['photo', 'contact', 'both']) {
  test(`${kind} draft prompts on soft navigation, but exact field reversion and file reset leave without a warning`, async t => {
    const { page, state } = await pageFor(t, { hasPhoto: false });
    const blob = await makeDraft(page, kind);
    await expectDirtyStay(page, state);
    if (kind !== 'photo') await fillContact(page, initialContact);
    if (kind !== 'contact') await resetPhoto(page);
    assert.equal(state.posts.length, 0);
    if (blob) assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(blob));
    await expectCleanLeave(page);
  });
}
for (const operation of ['photo', 'contact']) {
  test(`with both drafts dirty, saving ${operation} never clears the other form's draft`, async t => {
    const { page, state } = await pageFor(t, { hasPhoto: false });
    const blob = await makeDraft(page, 'both');
    await expectDirtyStay(page, state); await save(page, operation);
    assert.equal(state.posts.length, 1);
    if (operation === 'photo') {
      assert.deepEqual(await contactValues(page), changedContact);
      assert.deepEqual(state.contact, initialContact);
      assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 0);
    } else {
      assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 1);
      assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
      assert.equal((await page.evaluate(() => window.revokedBlobs)).includes(blob), false);
    }
    await expectDirtyStay(page, state);
    if (operation === 'photo') await fillContact(page, initialContact); else await resetPhoto(page);
    await expectCleanLeave(page);
  });
}

test('failed contact save retains both drafts, displays page-owned escaped status, and reversion uses the last saved normalized baseline', async t => {
  const { page, state } = await pageFor(t, { hasPhoto: false });
  await fillContact(page, Object.fromEntries(fields.map(name => [name, '  ' + changedContact[name] + '  '])));
  await save(page, 'contact');
  const canonicalToken = state.posts[0].responseToken, blob = await select(page);
  const invalidDraft = { ...changedContact, emergency_contact_name: 'Unsubmitted replacement' };
  await fillContact(page, invalidDraft);
  const rejection = '<img src=x onerror="window.contactXss=1"> Invalid contact details';
  state.responses.set(CONTACT, { status: 422, message: rejection });
  await save(page, 'contact', rejection);
  assert.equal(state.posts[1].token, canonicalToken);
  assert.deepEqual(await contactValues(page), invalidDraft); assert.deepEqual(state.contact, changedContact);
  assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.equal(await page.locator('[data-emergency-contact-feedback] img').count(), 0);
  assert.equal(await page.evaluate(() => window.contactXss), undefined);
  assert.equal(await page.locator('#emergencyContactName').isEnabled(), true);
  await expectDirtyStay(page, state);
  await fillContact(page, changedContact); await resetPhoto(page);
  await expectCleanLeave(page);
});

test('the fixed page action uses native navigator confirm: cancel keeps both drafts, accept discards without saving', async t => {
  const { page, state, documents } = await pageFor(t);
  const blob = await makeDraft(page, 'both'), address = page.url(), documentCount = documents.length;
  await rememberShell(page);
  const back = page.locator('#main .page-actions').getByRole('link', { name: 'Back to My Health Portal', exact: true });
  const canceled = await nativeDialog(page, () => back.click(), 'confirm', false);
  assert.equal(canceled.error, undefined); assert.equal(page.url(), address);
  assert.equal(await page.evaluate(() => document.getElementById('main') === window.fixtureShell.main), true);
  assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.deepEqual(await contactValues(page), changedContact);
  await assertNoProfileModal(page);
  const accepted = await nativeDialog(page, () => back.click(), 'confirm', true);
  assert.equal(accepted.error, undefined);
  await page.waitForURL(origin + '/portal'); await ready(page, false);
  await assertSoftSwap(page, documents, documentCount);
  assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(blob));
  assert.equal(state.posts.length, 0); assert.deepEqual(state.contact, initialContact);
});
for (const kind of ['photo', 'contact', 'both']) {
  test(`managed browser Back cancels and restores URL/main for ${kind}, then accepts exactly once`, async t => {
    const { page, state, documents } = await pageFor(t, { start: '/portal/announcements', hasPhoto: false });
    await open(page);
    const blob = await makeDraft(page, kind), documentCount = documents.length;
    await rememberShell(page);
    const canceled = await nativeDialog(page, () => page.evaluate(() => history.back()), 'confirm', false);
    assert.equal(canceled.error, undefined);
    await page.waitForURL(origin + '/portal/settings'); await ready(page); await settle(page);
    assert.equal(await page.evaluate(() => document.getElementById('main') === window.fixtureShell.main), true);
    if (blob) assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
    if (kind !== 'photo') assert.deepEqual(await contactValues(page), changedContact);
    await assertNoProfileModal(page);
    const accepted = await nativeDialog(page, () => page.evaluate(() => history.back()), 'confirm', true);
    assert.equal(accepted.error, undefined);
    await page.waitForURL(origin + '/portal/announcements'); await ready(page, false);
    await assertSoftSwap(page, documents, documentCount);
    if (blob) assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(blob));
    assert.equal(state.posts.length, 0);
  });
}

test('native refresh warns for draft changes; cancel preserves both and accept reloads the saved page', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t);
  await page.locator('#emergencyContactName').click();
  const blob = await makeDraft(page, 'both');
  await nativeDialog(page, () => page.reload({ waitUntil: 'domcontentloaded', timeout: 5000 }), 'beforeunload', false);
  assert.equal(new URL(page.url()).pathname, '/portal/settings');
  assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.deepEqual(await contactValues(page), changedContact);
  const accepted = await nativeDialog(page, () => page.reload({ waitUntil: 'domcontentloaded' }), 'beforeunload', true);
  assert.equal(accepted.error, undefined);
  await ready(page); await assertSettingsPage(page);
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 0);
  assert.deepEqual(await contactValues(page), initialContact); assert.equal(state.posts.length, 0);
});

for (const operation of ['photo', 'contact']) {
  test(`pending ${operation} serializes duplicate/cross-form writes, disables all mutation controls and vetoes soft navigation/Back`, { timeout: 30000 }, async t => {
    const { page, state } = await pageFor(t, { start: '/portal/announcements', hasPhoto: true });
    await open(page); await makeDraft(page, 'both');
    const gate = defer(t); state.writeGate = gate;
    await page.getByRole('button', { name: operation === 'photo' ? 'Upload picture' : 'Save emergency contact', exact: true }).click();
    await waitFor(() => state.posts.length === 1, 'The first explicit write must reach the synthetic receiver');
    await mutationControlsDisabled(page);
    await page.evaluate(() => {
      for (const id of ['profilePhotoForm', 'emergencyContactForm']) {
        document.getElementById(id).requestSubmit(); document.getElementById(id).requestSubmit();
      }
    });
    await settle(page); assert.equal(state.posts.length, 1);
    const address = page.url(), reads = state.reads.length;
    await recordConfirm(page, true);
    assert.equal(await page.evaluate(target => SynapseNavigation.navigate(target, { fallback: false }), origin + '/portal'), false);
    await page.evaluate(() => history.back());
    await page.waitForURL(address); await ready(page); await settle(page);
    assert.equal(state.reads.length, reads); assert.deepEqual(await page.evaluate(() => window.fixtureConfirmMessages), []);
    assert.equal(await page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented;
    }), true, 'Pending writes also own a native beforeunload warning');
    gate.release(); state.writeGate = null;
    await feedback(page, operation, operation === 'photo' ? 'Profile picture updated.' : 'Emergency contact updated.');
    assert.equal(state.posts.length, 1, 'Blocked submissions are not replayed after the first save');
    assert.equal(await page.locator('#profilePhotoUpload').isEnabled(), true);
    assert.equal(await page.locator('#emergencyContactName').isEnabled(), true);
    assert.equal(await page.getByRole('button', { name: 'Save emergency contact', exact: true }).isEnabled(), true);
    await expectDirtyStay(page, state);
    await save(page, operation === 'photo' ? 'contact' : 'photo');
    assert.equal(state.posts.length, 2); assert.equal(state.maxConcurrentWrites, 1);
    assert.equal(state.posts[1].token, state.posts[0].responseToken);
    await expectCleanLeave(page);
  });
}

test('native refresh warns while a write is pending and cancellation keeps it in flight without another submission', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t);
  await fillContact(page); const gate = defer(t); state.writeGate = gate;
  await page.getByRole('button', { name: 'Save emergency contact', exact: true }).click();
  await waitFor(() => state.posts.length === 1, 'Contact write must start');
  await nativeDialog(page, () => page.reload({ waitUntil: 'domcontentloaded', timeout: 5000 }), 'beforeunload', false);
  assert.equal(state.posts.length, 1); assert.equal(state.activeWrites, 1);
  await mutationControlsDisabled(page);
  gate.release(); state.writeGate = null; await feedback(page, 'contact', 'Emergency contact updated.');
  await expectCleanLeave(page);
});

test('once document navigation has begun, outgoing photo/contact submits cannot launch writes', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t);
  const blob = await makeDraft(page, 'both'), gate = defer(t);
  state.readGates.set('/portal/announcements', gate); await recordConfirm(page, true);
  const navigation = page.evaluate(target => SynapseNavigation.navigate(target, { fallback: false }), origin + '/portal/announcements');
  await page.waitForFunction(() => document.body.classList.contains('is-navigating'));
  await waitFor(() => state.reads.some(read => read.path === '/portal/announcements'), 'Navigation GET must be in flight');
  await page.evaluate(() => {
    document.getElementById('profilePhotoForm').requestSubmit();
    document.getElementById('emergencyContactForm').requestSubmit();
  });
  await settle(page); assert.equal(state.posts.length, 0);
  gate.release(); state.readGates.delete('/portal/announcements');
  assert.equal(await navigation, true); await ready(page, false);
  assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(blob));
  assert.equal(state.posts.length, 0);
});

test('only photo removal uses SynapseConfirm; cancel preserves both drafts, accept clears only photo and serializes writes', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t, { kind: 'employee', hasPhoto: true });
  const upload = page.getByRole('button', { name: 'Upload picture', exact: true }), remove = page.getByRole('button', { name: 'Remove picture', exact: true });
  const uploadBox = await upload.boundingBox(), removeBox = await remove.boundingBox();
  assert.ok(Math.abs(uploadBox.y - removeBox.y) < 1); assert.ok(Math.abs(uploadBox.height - removeBox.height) < 1);
  assert.equal(await page.getByRole('heading', { name: 'Appearance', exact: true }).count(), 0);
  const blob = await makeDraft(page, 'both'), address = page.url();
  await remove.click(); await page.locator('#synapseConfirmModal.show').waitFor();
  assert.equal(await page.locator('#appFormModal').count(), 0);
  await page.locator('[data-confirm-cancel]').click(); await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
  assert.equal(state.posts.length, 0); assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.deepEqual(await contactValues(page), changedContact);
  const gate = defer(t); state.writeGate = gate;
  await remove.click(); await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click();
  await waitFor(() => state.posts.length === 1, 'Confirmed removal must submit once'); await mutationControlsDisabled(page);
  await page.evaluate(() => {
    document.getElementById('profilePhotoForm').requestSubmit(); document.getElementById('emergencyContactForm').requestSubmit();
  });
  await settle(page); assert.equal(state.posts.length, 1);
  gate.release(); state.writeGate = null; await feedback(page, 'photo', 'Profile picture removed.');
  assert.equal(state.posts[0].path, REMOVE); assert.deepEqual(state.posts[0].names, ['csrf_test_name']);
  assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), false);
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 0);
  assert.ok((await page.evaluate(() => window.revokedBlobs)).includes(blob));
  for (const src of await page.locator('[data-profile-avatar], [data-profile-photo-current]').evaluateAll(images => images.map(image => image.src))) {
    assert.match(src, /^data:image\/svg\+xml;base64,/); assert.ok(Buffer.from(src.split(',')[1], 'base64').toString().includes('>SE</text>'));
  }
  assert.equal(page.url(), address); assert.deepEqual(await contactValues(page), changedContact);
  await assertNoProfileModal(page); await expectDirtyStay(page, state);
  await fillContact(page, initialContact); await expectCleanLeave(page);
});

test('repeated real page entry/save/discard lifecycles keep one owner and revoke every superseded preview URL', { timeout: 40000 }, async t => {
  const { page, state, documents } = await pageFor(t, { start: '/portal/announcements', hasPhoto: false });
  const documentCount = documents.length;
  for (let iteration = 0; iteration < 3; ++iteration) {
    await open(page, iteration === 1 ? 'account' : 'sidebar');
    await page.evaluate(() => { SynapseProfileSettings.init(document); SynapseProfileSettings.init(document); });
    const created = await page.evaluate(() => window.createdBlobs.length);
    await select(page, png, `first-${iteration}.png`); await select(page, png, `last-${iteration}.png`);
    assert.equal(await page.evaluate(() => window.createdBlobs.length), created + 2, 'Idempotent init must not bind duplicate preview listeners');
    if (iteration === 1) {
      await recordConfirm(page, true);
      assert.equal(await navigate(page, origin + '/portal'), true);
    } else { await save(page, 'photo'); await expectCleanLeave(page); }
    await ready(page, false);
    const blobs = await page.evaluate(() => ({ created: window.createdBlobs, revoked: window.revokedBlobs }));
    assert.ok(blobs.created.every(url => blobs.revoked.includes(url)), 'Saved, replaced and discarded blobs are all released');
    assert.equal(await page.locator('[data-profile-settings]').count(), 0);
  }
  assert.equal(state.posts.length, 2); assert.equal(documents.length, documentCount);
});

for (const operation of ['photo', 'contact']) {
  test(`late ${operation} response after before-swap cleanup cannot mutate a newly entered page, avatars, draft or CSRF`, { timeout: 30000 }, async t => {
    const { page, state } = await pageFor(t, { hasPhoto: false });
    await page.evaluate(() => { window.fixtureIgnorePostAbort = true; });
    if (operation === 'photo') await select(page, png, 'old-owner.png'); else await fillContact(page);
    const gate = defer(t); state.writeGate = gate;
    await page.getByRole('button', { name: operation === 'photo' ? 'Upload picture' : 'Save emergency contact', exact: true }).click();
    await waitFor(() => state.posts.length === 1, 'Old owner write must begin');
    // External lifecycle disposal is intentionally exercised independently of the normal pending navigation veto.
    const outgoing = await page.evaluate(() => {
      const root = document.getElementById('main');
      document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root } }));
      return {
        connected: root.isConnected,
        inert: root.querySelector('[data-profile-settings]').inert,
        submitsBlocked: ['profilePhotoForm', 'emergencyContactForm'].every(id =>
          !document.getElementById(id).dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true })))
      };
    });
    assert.deepEqual(outgoing, { connected: true, inert: true, submitsBlocked: true }, 'The outgoing page stays locked while incoming scripts load, even after lifecycle cleanup');
    assert.equal(await page.evaluate(() => window.fixturePostSignals[0]?.aborted), true, 'Cleanup must abort the old owner request');
    await recordConfirm(page, true);
    assert.equal(await page.evaluate(target => SynapseNavigation.navigate(target, { fallback: false }), origin + '/portal'), true);
    await ready(page, false); await open(page);
    const newBlob = await select(page, png, 'new-owner.png');
    const newContact = { ...initialContact, emergency_contact_name: 'New unsaved owner' };
    await fillContact(page, newContact);
    const snapshot = await page.evaluate(() => ({ token: document.querySelector('meta[name="csrf-token"]').content,
      avatars: [...document.querySelectorAll('[data-profile-avatar], [data-profile-photo-current]')].map(image => image.src) }));
    gate.release(); state.writeGate = null;
    await waitFor(() => state.posts[0].finished, 'Late transport response must settle'); await settle(page);
    assert.deepEqual(await contactValues(page), newContact);
    assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), newBlob);
    assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files[0].name), 'new-owner.png');
    assert.deepEqual(await page.evaluate(() => ({ token: document.querySelector('meta[name="csrf-token"]').content,
      avatars: [...document.querySelectorAll('[data-profile-avatar], [data-profile-photo-current]')].map(image => image.src) })), snapshot);
    await expectDirtyStay(page, state);
  });
}

test('persisted pagehide/pageshow preserves independent photo/contact drafts and the last normalized saved contact baseline', { timeout: 30000 }, async t => {
  const { page, state } = await pageFor(t, { hasPhoto: false });
  await fillContact(page, Object.fromEntries(fields.map(name => [name, '  ' + changedContact[name] + '  '])));
  await save(page, 'contact'); await select(page);
  const draft = { ...changedContact, emergency_contact_name: 'BFCache unsaved contact' };
  await fillContact(page, draft);
  for (let iteration = 0; iteration < 3; ++iteration) {
    await page.evaluate(() => {
      window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await page.locator('[data-profile-photo-preview]').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 1);
    assert.deepEqual(await contactValues(page), draft);
    await expectDirtyStay(page, state);
  }
  await resetPhoto(page); await expectDirtyStay(page, state);
  await fillContact(page, changedContact); await expectCleanLeave(page);
  assert.equal(state.posts.length, 1, 'BFCache lifecycle events never auto-save either draft');
});

for (const operation of ['photo', 'contact']) {
  test(`non-JSON ${operation} success response remains an unsaved recoverable error, not an accepted baseline`, async t => {
    const { page, state } = await pageFor(t, { hasPhoto: false });
    await makeDraft(page, operation);
    state.responses.set(operation === 'photo' ? PHOTO : CONTACT, { contentType: 'text/html', body: '<p>Unexpected upstream response</p>' });
    await page.getByRole('button', { name: operation === 'photo' ? 'Upload picture' : 'Save emergency contact', exact: true }).click();
    await waitFor(() => state.posts.length === 1 && state.posts[0].finished, 'Synthetic non-JSON response must be returned');
    await page.waitForFunction(() => !document.querySelector('[data-profile-settings] form[aria-busy="true"]'));
    const selector = operation === 'photo' ? '[data-profile-photo-feedback]' : '[data-emergency-contact-feedback]';
    assert.match(await page.locator(selector).innerText(), /response|save|JSON|Unexpected|check|received/i);
    if (operation === 'photo') assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 1);
    else assert.deepEqual(await contactValues(page), changedContact);
    await expectDirtyStay(page, state);
  });
}

test('untrusted saved photo URLs cannot redirect avatar requests or clear the pending file selection', async t => {
  const { page, state } = await pageFor(t, { hasPhoto: false });
  const current = await page.locator('[data-profile-photo-current]').getAttribute('src'), blob = await select(page);
  for (const photo_url of ['https://example.test/unsafe.webp', origin + '/portal/private-photo', origin + '/account/photo#unsafe']) {
    state.responses.set(PHOTO, { payload: { photo_url } });
    await page.getByRole('button', { name: 'Upload picture', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('[data-profile-settings] form[aria-busy="true"]') &&
      /invalid|incomplete|reload|check|response/i.test(document.querySelector('[data-profile-photo-feedback]').textContent));
    assert.equal(await page.locator('[data-profile-photo-current]').getAttribute('src'), current);
    assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
    assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 1);
  }
  assert.equal(state.posts.length, 3); await expectDirtyStay(page, state);
});

for (const operation of ['photo', 'contact', 'remove']) {
  test(`JavaScript-disabled native ${operation} POST preserves the form/CSRF contract and returns to fixed settings`, async t => {
    const { page, state, documents } = await pageFor(t, { javaScript: false, hasPhoto: operation === 'remove' });
    await assertSettingsPage(page);
    if (operation === 'photo') await page.locator('#profilePhotoUpload').setInputFiles({ name: 'native.png', mimeType: 'image/png', buffer: png });
    if (operation === 'contact') await fillContact(page);
    const selector = operation === 'photo' ? 'Upload picture' : operation === 'contact' ? 'Save emergency contact' : 'Remove picture';
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: selector, exact: true }).click()]);
    assert.equal(page.url(), origin + '/portal/settings');
    assert.equal(state.posts.length, 1); assert.equal(state.posts[0].ajax, false);
    assert.equal(state.posts[0].path, operation === 'photo' ? PHOTO : operation === 'contact' ? CONTACT : REMOVE);
    assert.ok(state.posts[0].token); assert.equal(state.posts[0].csrfHeader, undefined);
    assert.ok(documents.length >= 3, 'Native fallback posts and follows a server redirect');
    await assertSettingsPage(page);
    if (operation === 'photo') {
      assert.equal(state.hasPhoto, true); assert.equal(state.posts[0].filename, 'native.png');
      assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), true);
    } else if (operation === 'contact') assert.deepEqual(await contactValues(page), changedContact);
    else assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), false);
  });
}
