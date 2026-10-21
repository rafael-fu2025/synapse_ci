'use strict';

// Run: node --test tests/browser/carousel.test.cjs
// Browser/client integration only: HTTP responses and the manager are fixtures.
// These tests do NOT validate PHP POST -> GET, database writes, sessions or flash.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { once } = require('node:events');

const root = path.resolve(__dirname, '../..');
const tools = path.join(root, 'writable/widget-test-tools/node_modules/playwright');
let playwright;
try { playwright = require(tools); }
catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw error;
  playwright = require('playwright');
}
const publicRoot = path.join(root, 'public');
const imagePath = path.join(publicRoot, 'fuel-bg.jpg');
const imageBytes = fs.readFileSync(imagePath);
const view = fs.readFileSync(path.join(root, 'app/Views/admin/carousel/_form_scripts.php'), 'utf8');
// Execute the real view's script/style, never a rewritten copy of its logic.
const formScripts = (view.match(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi) || []).join('\n');
assert.match(formScripts, /<script>/);
assert.match(formScripts, /<style>/);
assert.doesNotMatch(formScripts, /<\?/);
let browser;
const cleanups = new Set();

// Same cached Chromium detection as widgets.test.cjs.
function executablePath() {
  const expected = playwright.chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const cached = fs.existsSync(cache) ? fs.readdirSync(cache)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(name => path.join(cache, name, 'chrome-win64/chrome.exe'))
    .find(file => fs.existsSync(file)) : undefined;
  if (cached) return cached;
  const packageFile = require.resolve('playwright/package.json', { paths: [path.dirname(tools), root] });
  execFileSync(process.execPath, [path.join(path.dirname(packageFile), 'cli.js'), 'install', 'chromium'], {
    stdio: 'inherit', timeout: 90_000
  });
  assert.ok(fs.existsSync(expected), `Chromium installation did not create ${expected}`);
  return expected;
}

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

function bounded(promise, label, milliseconds = 4_000) {
  let timer;
  return Promise.race([
    promise,
    new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`Timed out: ${label}`)), milliseconds); })
  ]).finally(() => clearTimeout(timer));
}

function fixture({ edit = false, manager = false } = {}) {
  const zones = ['light', 'dark'].map(mode => `
    <label for="${mode}_file">${mode} image</label>
    <div class="upload-dropzone" data-input="${mode}_file" data-current="${edit ? '/fuel-bg.jpg' : ''}">
      <input type="file" name="${mode}_file" id="${mode}_file" accept="image/*" class="d-none">
      <div class="dz-body">Drag &amp; drop or <span class="upload-browse-link">browse</span></div>
      <div class="dz-preview d-none"></div>
    </div>`).join('\n');
  return `<!doctype html><html lang="en"><head>
    <meta charset="utf-8"><meta name="csrf-token" content="initial-csrf">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <style>main{max-width:600px;margin:20px auto}.upload-dropzone{margin:8px 0}</style>
    </head><body><main>
    ${manager ? `<h1>Carousel manager</h1><p data-testid="fixture-boundary">Mock manager; no PHP database or session assertions</p>
      <div role="status" id="managerSuccess">Carousel slide created successfully.</div>
      <article id="savedSlide"><h2>Browser upload</h2><img src="/fuel-bg.jpg" alt="Saved slide thumbnail" width="120"></article>` : ''}
    <h2 id="formTitle">${edit ? 'Edit Slide' : 'Add Slide'}</h2>
    <form id="slideForm" action="/store" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_test_name" value="initial-csrf">
      <label>Title<input type="text" name="title" maxlength="150" value="${edit ? 'Existing slide' : ''}"></label>
      <label>Subtitle<input type="text" name="subtitle" maxlength="255"></label>
      <label>Display Fit<select name="fit_mode"><option value="cover">Full Cover</option><option value="contain_blur">Contained with Blur</option></select></label>
      ${zones}
      ${edit ? '<div id="removeDarkWrap"><input type="checkbox" value="1" name="remove_dark" id="remove_dark"><label for="remove_dark">Remove dark image</label></div>' : ''}
      <div class="carousel-upload-progress d-none mb-3">
        <div class="d-flex justify-content-between small mb-1">
          <span class="dz-status fw-semibold" role="status" aria-live="polite">Uploading&hellip;</span>
          <span class="dz-pct text-muted">0%</span>
        </div>
        <div class="progress" style="height:6px"><div class="progress-bar bg-primary" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
      </div>
      <div class="alert d-none mb-3" id="slideFormAlert" role="alert"></div>
      <button type="submit" id="slideSubmitBtn">${edit ? 'Save Changes' : 'Save Slide'}</button>
      <a href="/admin/carousel">Cancel</a>
    </form></main>${formScripts}</body></html>`;
}

before(async () => {
  const executable = executablePath();
  console.log(`Carousel tests Chromium: ${executable}`);
  browser = await playwright.chromium.launch({ headless: true, executablePath: executable, timeout: 20_000 });
}, { timeout: 120_000 });

after(async () => {
  // Release every pending manager response before closing Chromium or servers.
  try { await Promise.all([...cleanups].map(cleanup => cleanup())); }
  finally { if (browser) await bounded(browser.close(), 'browser cleanup', 5_000); }
}, { timeout: 15_000 });

async function open(t, { edit = false, outcomes = ['success'] } = {}) {
  const posts = [];
  const managerRequested = deferred();
  const held = new Set();
  let released = false;
  let managerGets = 0;
  let context;
  let closing;
  const errors = [];
  const sendManager = res => {
    if (!res.destroyed && !res.writableEnded) res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(fixture({ manager: true }));
  };
  function releaseManager() {
    released = true;
    for (const res of held) sendManager(res);
    held.clear();
  }
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(fixture({ edit }));
      return;
    }
    if (url.pathname === '/store' && req.method === 'POST') {
      const post = { headers: req.headers, chunks: [], body: null };
      posts.push(post);
      const outcome = outcomes[posts.length - 1] || 'success';
      req.on('data', chunk => post.chunks.push(chunk));
      req.on('end', () => {
        post.body = Buffer.concat(post.chunks);
        if (outcome === 'network') {
                  // Send headers first so Chromium cannot transparently retry a reset
                  // connection as if this POST had never reached the server.
                  res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': 1000 });
                  res.flushHeaders();
                  res.write('{');
                  setTimeout(() => res.destroy(), 10);
                  return;
                }
        if (outcome === 'malformed') {
          res.writeHead(200, { 'Content-Type': 'application/json' }).end('{not valid JSON');
          return;
        }
        // Same status and payload shape as AdminCarouselController::respond().
        res.writeHead(outcome === '422' ? 422 : 200, { 'Content-Type': 'application/json' }).end(JSON.stringify({
          success: outcome !== '422',
          message: outcome === '422' ? 'Choose a valid image.' : 'Carousel slide created successfully.',
          redirect: '/admin/carousel',
          csrf: { name: 'csrf_test_name', hash: `refreshed-csrf-${posts.length}` }
        }));
      });
      return;
    }
    if (url.pathname === '/post-count') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ count: posts.length }));
      return;
    }
    if (url.pathname === '/admin/carousel') {
      managerGets++;
      if (released) sendManager(res);
      else {
        held.add(res);
        res.on('close', () => held.delete(res));
      }
      managerRequested.resolve();
      return;
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = { '.js': 'text/javascript', '.css': 'text/css', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }).end(content);
    });
  });
  server.requestTimeout = 5_000;
  server.headersTimeout = 5_000;
  server.setTimeout(12_000, socket => socket.destroy());
  async function cleanup() {
    if (closing) return closing;
    releaseManager();
    closing = (async () => {
      try {
        if (context) {
          if (managerGets) await Promise.all(context.pages().map(page =>
            page.waitForURL('**/admin/carousel', { waitUntil: 'load', timeout: 2_000 }).catch(() => {})));
          await bounded(context.close(), 'browser context cleanup', 3_000);
        }
      } finally {
        if (server.listening) await new Promise((resolve, reject) => {
          server.close(error => error ? reject(error) : resolve());
          server.closeAllConnections();
        });
        cleanups.delete(cleanup);
      }
    })();
    return closing;
  }
  cleanups.add(cleanup);
  t.after(async () => {
    await cleanup();
    assert.deepEqual(errors, [], 'Actual carousel script and local assets must have no browser errors');
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const origin = `http://127.0.0.1:${server.address().port}`;
  context = await browser.newContext({ viewport: { width: 1000, height: 1000 } });
  const page = await context.newPage();
  page.on('request', request => {
    if (!request.url().startsWith(origin + '/') && !request.url().startsWith(`blob:${origin}/`)) {
      errors.push(`Unexpected external request: ${request.url()}`);
    }
  });
  page.setDefaultTimeout(3_000);
  page.setDefaultNavigationTimeout(4_000);
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`${origin}/fixture`);
  await page.waitForFunction(() => document.querySelector('.upload-dropzone').getAttribute('role') === 'button');
  const navigations = [];
  page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations.push(frame.url()); });
  return { page, origin, posts, navigations, managerRequested, releaseManager, managerGets: () => managerGets };
}

async function selection(page) {
  return page.evaluate(() => ['light', 'dark'].map(mode => {
    const zone = document.querySelector(`[data-input="${mode}_file"]`);
    const file = zone.querySelector('input').files[0];
    const img = zone.querySelector('.dz-preview img');
    return {
      mode, file: file && { name: file.name, size: file.size, type: file.type, lastModified: file.lastModified },
      src: img?.src, width: img?.naturalWidth, height: img?.naturalHeight,
      visible: Boolean(img && img.getBoundingClientRect().height > 0 && !zone.querySelector('.dz-preview').classList.contains('d-none'))
    };
  }));
}

async function prepare(page) {
  await page.locator('[name="title"]').fill('Browser upload');
  for (const mode of ['light', 'dark']) await page.locator(`#${mode}_file`).setInputFiles(imagePath);
  await page.waitForFunction(() => [...document.querySelectorAll('.dz-preview img')].length === 2 &&
    [...document.querySelectorAll('.dz-preview img')].every(img => img.complete && img.naturalWidth > 0));
  const selected = await selection(page);
  for (const item of selected) {
    assert.deepEqual({ name: item.file.name, size: item.file.size, type: item.file.type }, {
      name: 'fuel-bg.jpg', size: imageBytes.length, type: 'image/jpeg'
    });
    assert.match(item.src, /^blob:/);
    assert.ok(item.visible && item.width > 0 && item.height > 0, `${item.mode} preview must actually render`);
  }
  return selected;
}

function assertMultipart(post, csrf = 'initial-csrf') {
  assert.equal(post.headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(post.headers.accept, 'application/json');
  assert.match(post.headers['content-type'], /^multipart\/form-data; boundary=/);
  assert.ok(Buffer.isBuffer(post.body), 'Server must receive the complete real XHR body');
  const boundary = post.headers['content-type'].split('boundary=')[1].replace(/^"|"$/g, '');
  const parts = post.body.toString('latin1').split(`--${boundary}`);
  function field(name) {
    const part = parts.find(value => value.includes(`name="${name}"`));
    assert.ok(part, `Missing multipart field ${name}`);
    return part.slice(part.indexOf('\r\n\r\n') + 4, -2);
  }
  assert.equal(field('title'), 'Browser upload');
  assert.equal(field('csrf_test_name'), csrf);
  for (const mode of ['light', 'dark']) {
    const part = parts.find(value => value.includes(`name="${mode}_file"; filename="fuel-bg.jpg"`));
    assert.ok(part, `Missing multipart ${mode} image`);
    assert.match(part, /Content-Type: image\/jpeg/);
    assert.deepEqual(Buffer.from(field(`${mode}_file`), 'latin1'), imageBytes);
  }
}

async function successfulSave(f) {
  const { page } = f;
  const expectedPosts = f.posts.length + 1;
  const observed = deferred();
  await page.exposeFunction('reportSaveObservation', snapshot => observed.resolve(snapshot));
  // Arm before submission: Playwright locator clicks/evaluations can wait for
  // navigation forever while the manager GET is deliberately held by the server.
  await page.evaluate(() => {
    const form = document.querySelector('#slideForm');
    const button = document.querySelector('#slideSubmitBtn');
    const snapshot = () => ({
      disabled: button.disabled, busy: form.getAttribute('aria-busy'),
      status: form.querySelector('.dz-status').textContent,
      percent: form.querySelector('.dz-pct').textContent,
      progress: form.querySelector('.progress-bar').getAttribute('aria-valuenow'),
      success: document.querySelector('#slideFormAlert').classList.contains('alert-success')
    });
    const poll = setInterval(() => {
      if (form.querySelector('.dz-status').textContent !== 'Saved') return;
      clearInterval(poll);
      // Leave the success XHR's loadend handler time to run before probing.
      setTimeout(() => {
        const before = snapshot();
        button.click();
        form.requestSubmit();
        setTimeout(async () => {
          try {
            const { count } = await fetch('/post-count').then(response => response.json());
            await window.reportSaveObservation({ before, after: snapshot(), postCount: count });
          } catch (error) { await window.reportSaveObservation({ error: String(error) }); }
        }, 250);
      }, 50);
    }, 10);
    setTimeout(() => button.click(), 0);
  });
  try {
    await bounded(f.managerRequested.promise, 'manager GET to be held');
    const snapshot = await bounded(observed.promise, 'in-page duplicate-submit probe');
    assert.equal(snapshot.error, undefined);
    const expected = { disabled: true, busy: 'true', status: 'Saved', percent: '100%', progress: '100', success: true };
    assert.deepEqual(snapshot.before, expected, 'Success must remain locked after loadend while manager navigation is pending');
    assert.deepEqual(snapshot.after, expected, 'Second click and requestSubmit must not restart an upload');
    assert.equal(snapshot.postCount, expectedPosts, 'Duplicate attempts must not send another POST');
        assert.equal(f.posts.length, expectedPosts, 'Browser and server POST counters must agree');
    assert.equal(f.managerGets(), 1, 'Only the successful save should request the manager');
  } finally {
    // Also release on failed assertions/timeouts, before test/browser teardown.
    f.releaseManager();
    // Let the released navigation (and exposed binding) settle even when the
    // regression assertion fails, before context.close() tries to tear it down.
    if (f.managerGets()) await page.waitForURL(`${f.origin}/admin/carousel`, { waitUntil: 'load' });
  }
  await page.waitForURL(`${f.origin}/admin/carousel`);
  await page.waitForFunction(() => {
    const image = document.querySelector('#savedSlide img');
    return image?.complete && image.naturalWidth > 0;
  });
  assert.equal(await page.locator('#formTitle').textContent(), 'Add Slide');
  assert.equal(await page.locator('[name="title"]').inputValue(), '');
  assert.equal(await page.locator('[name="subtitle"]').inputValue(), '');
  assert.equal(await page.locator('[name="fit_mode"]').inputValue(), 'cover');
  for (const mode of ['light', 'dark']) {
    assert.equal(await page.locator(`#${mode}_file`).evaluate(input => input.files.length), 0);
    assert.equal(await page.locator(`[data-input="${mode}_file"]`).getAttribute('data-current'), '');
  }
  assert.equal(await page.locator('.dz-preview img').count(), 0);
  assert.equal(await page.locator('#slideSubmitBtn').isEnabled(), true);
  assert.match(await page.locator('#managerSuccess').textContent(), /successfully/);
  assert.equal(await page.locator('#savedSlide h2').textContent(), 'Browser upload');
  assert.equal(await page.locator('#savedSlide img').isVisible(), true);
  assert.match(await page.locator('[data-testid="fixture-boundary"]').textContent(), /no PHP database or session assertions/);
  assert.deepEqual(f.navigations, [`${f.origin}/admin/carousel`]);
}

for (const edit of [false, true]) {
  test(`${edit ? 'edit' : 'add'} success stays busy through held manager navigation and blocks duplicate POSTs`, { timeout: 15_000 }, async t => {
    const f = await open(t, { edit });
    if (edit) {
      await f.page.waitForFunction(() => [...document.querySelectorAll('.dz-preview img')].every(img => img.complete && img.naturalWidth > 0));
      assert.equal(await f.page.locator('.dz-preview img').count(), 2);
      assert.equal(await f.page.locator('#formTitle').textContent(), 'Edit Slide');
    }
    await prepare(f.page);
    await successfulSave(f);
    assert.equal(f.posts.length, 1, 'Exactly one multipart POST, including the pending-navigation duplicate attempts');
    assertMultipart(f.posts[0]);
  });
}

for (const [outcome, status, message] of [
  ['422', 'Not saved', 'Choose a valid image.'],
  ['network', 'Upload failed', 'Network error. Your image selection is retained so you can retry.'],
  ['malformed', 'Not saved', 'Upload was not saved. Your session may have expired; refresh and try again.']
]) {
  test(`${outcome} failure unlocks Save, retains both files/previews and does not navigate${outcome === '422' ? '; retry refreshes CSRF and succeeds' : ''}`, { timeout: 15_000 }, async t => {
    const f = await open(t, { outcomes: [outcome, 'success'] });
    const selected = await prepare(f.page);
    await f.page.locator('#slideSubmitBtn').click();
    await f.page.waitForFunction(expected => {
      const form = document.querySelector('#slideForm');
      return !document.querySelector('#slideSubmitBtn').disabled && !form.hasAttribute('aria-busy') &&
        form.querySelector('.dz-status').textContent === expected;
    }, status);
    assert.equal(await f.page.locator('#slideFormAlert').textContent(), message);
    assert.equal(await f.page.locator('#slideFormAlert').isVisible(), true);
    assert.match(await f.page.locator('#slideFormAlert').getAttribute('class'), /alert-danger/);
    assert.equal(f.page.url(), `${f.origin}/fixture`);
    assert.deepEqual(f.navigations, []);
    assert.equal(f.managerGets(), 0);
    assert.equal(f.posts.length, 1);
    assertMultipart(f.posts[0]);
    assert.deepEqual(await selection(f.page), selected, 'Failure must preserve the same File metadata and rendered object URLs');
    assert.equal(await f.page.locator('[name="title"]').inputValue(), 'Browser upload');
    if (outcome === '422') {
      assert.equal(await f.page.locator('[name="csrf_test_name"]').inputValue(), 'refreshed-csrf-1');
      assert.equal(await f.page.locator('meta[name="csrf-token"]').getAttribute('content'), 'refreshed-csrf-1');
      await successfulSave(f);
      assert.equal(f.posts.length, 2, 'Exactly one failed attempt and one successful retry, no duplicate save');
      assertMultipart(f.posts[1], 'refreshed-csrf-1');
    }
  });
}
