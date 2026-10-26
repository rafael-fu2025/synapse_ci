'use strict';

// Run: node --test tests/browser/confirm.test.cjs
// Browser/client integration only: the HTTP server below is a fixture.
// These tests do NOT validate PHP POST -> GET handling, sessions, flash messages
// or database writes.
//
// What is real here: public/assets/js/confirm.js, the real Bootstrap 5.3 bundle
// and the #synapseConfirmModal markup, which is lifted verbatim out of
// app/Views/layouts/app.php instead of being retyped. A duplicated title, a
// dropped data-confirm-* hook or an aria target that no longer exists in the view
// therefore reaches this suite as a failure.
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
const confirmAsset = path.join(publicRoot, 'assets/js/confirm.js');
const bootstrapJs = path.join(publicRoot, 'assets/vendor/bootstrap/js/bootstrap.bundle.min.js');
const bootstrapCss = path.join(publicRoot, 'assets/vendor/bootstrap/css/bootstrap.min.css');
for (const asset of [confirmAsset, bootstrapJs, bootstrapCss]) {
  assert.ok(fs.existsSync(asset), `Real asset missing from the working tree: ${asset}`);
}

// The dialog markup is read out of the real layout, so the fixture cannot drift from it.
const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/app.php'), 'utf8');
const modalStart = layout.indexOf('<div class="modal fade" id="synapseConfirmModal"');
const modalEnd = layout.indexOf('<!-- Vendor JS Files -->');
assert.notEqual(modalStart, -1, 'app.php no longer contains #synapseConfirmModal');
assert.notEqual(modalEnd, -1, 'app.php no longer loads its scripts after the confirm modal');
const modalMarkup = layout.slice(modalStart, layout.lastIndexOf('</div>', modalEnd) + '</div>'.length).trim();
assert.doesNotMatch(modalMarkup, /<\?/, 'The confirm modal markup must be PHP-free so the fixture can use it verbatim');

const CSRF_FIELD = 'csrf_test_name'; // app/Config/Security.php $tokenName
const CSRF_TOKEN = 'fixture-csrf-token';

let browser;
const cleanups = new Set();

// Same cached Chromium detection as carousel.test.cjs / widgets.test.cjs.
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

const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

// Poll until counts() matches `wanted` exactly, so a test never asserts on a
// half-arrived POST. The loop stops as soon as the wait ends, whether it
// matched or timed out, so a failing assertion cannot keep the runner alive.
async function waitForCounts(counts, wanted, label, milliseconds = 4_000) {
  const deadline = Date.now() + milliseconds;
  while (JSON.stringify(counts()) !== JSON.stringify(wanted)) {
    if (Date.now() >= deadline) throw new Error(`Timed out: ${label}`);
    await wait(25);
  }
}

// Viewport centre of a selector, for driving the mouse directly when a click must
// NOT wait on the navigation it triggers.
async function acceptCentre(page, selector) {
  const box = await page.locator(selector).boundingBox();
  assert.ok(box, `Could not resolve a clickable box for ${selector}`);
  return [box.x + box.width / 2, box.y + box.height / 2];
}

// The native confirm() call log has to survive the POST -> 302 -> /result
// navigation that the confirmed fallback triggers, so it is mirrored into
// sessionStorage and rehydrated on EVERY document. addInitScript runs again on
// each navigation, which used to reset window.__confirmCalls to [] on /result and
// made the post-navigation assertion vacuous.
//
// Returned as { content } on purpose: addInitScript serialises the script into the
// page, so it cannot close over anything out here. The answer is baked in as a
// literal for the same reason.
function nativePrompt(answer) {
  return { content: `(() => {
    const key = '__confirmCallLog';
    let stored;
    try { stored = JSON.parse(sessionStorage.getItem(key) || '[]'); }
    catch (error) { stored = []; }
    if (!Array.isArray(stored)) stored = [];
    window.__confirmCalls = stored;
    window.confirm = message => {
      window.__confirmCalls.push(String(message));
      try { sessionStorage.setItem(key, JSON.stringify(window.__confirmCalls)); }
      catch (error) { /* the in-memory log still stands for this document */ }
      return ${answer === true};
    };
  })();` };
}

// Realistic declarative usage, mirroring app/Views/appointments/index.php and
// app/Views/admin/carousel/index.php: the prompt lives on the FORM, not the button.
function pageHtml({ modal = true, withConfirm = true } = {}) {
  return `<!doctype html><html lang="en"><head>
    <meta charset="utf-8">
    <meta name="csrf-header" content="X-CSRF-TOKEN">
    <meta name="csrf-token" content="${CSRF_TOKEN}">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>main{max-width:680px;margin:24px auto}#outerContainer{padding:16px;border:1px solid #ddd}</style>
    </head><body><main>
    <h1 id="pageTitle">Appointments</h1>

    <form id="dangerForm" action="/danger" method="POST" class="d-inline"
          data-confirm="Delete this appointment?" data-confirm-title="Delete appointment"
          data-confirm-label="Delete it" data-confirm-tone="danger">
      <input type="hidden" name="${CSRF_FIELD}" value="${CSRF_TOKEN}">
      <input type="hidden" name="intent" value="delete-appointment">
      <button type="submit" id="dangerSubmit">Delete appointment</button>
    </form>

    <div class="container" id="outerContainer">
      <form id="nestedForm" action="/nested" method="POST"
            data-confirm="Archive this record?" data-confirm-title="Archive record"
            data-confirm-label="Archive" data-confirm-tone="warning">
        <input type="hidden" name="${CSRF_FIELD}" value="${CSRF_TOKEN}">
        <input type="hidden" name="intent" value="archive-record">
        <button type="submit" id="nestedSubmit">Archive</button>
      </form>
    </div>

    <form id="plainForm" action="/plain" method="POST">
      <input type="hidden" name="${CSRF_FIELD}" value="${CSRF_TOKEN}">
      <input type="hidden" name="intent" value="plain-submit">
      <button type="submit" id="plainSubmit">Save without guard</button>
    </form>

    <form id="untitledForm" action="/untitled" method="POST" data-confirm="Proceed with this?">
      <input type="hidden" name="${CSRF_FIELD}" value="${CSRF_TOKEN}">
      <button type="submit" id="untitledSubmit">Proceed</button>
    </form>
    </main>
    ${modal ? modalMarkup : ''}
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    ${withConfirm ? '<script src="/assets/js/confirm.js"></script>' : ''}
    </body></html>`;
}

function resultHtml() {
  return `<!doctype html><html lang="en"><head>
    <meta charset="utf-8">
    <meta name="csrf-header" content="X-CSRF-TOKEN">
    <meta name="csrf-token" content="${CSRF_TOKEN}">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    </head><body><main>
    <h1 id="pageTitle">Result</h1>
    <p id="resultPath" data-testid="fixture-boundary">Redirected after POST; no PHP session or database assertions</p>
    </main>
    ${modalMarkup}
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/confirm.js"></script>
    </body></html>`;
}

before(async () => {
  const executable = executablePath();
  console.log(`Confirm tests Chromium: ${executable}`);
  browser = await playwright.chromium.launch({ headless: true, executablePath: executable, timeout: 20_000 });
}, { timeout: 120_000 });

after(async () => {
  // Release every held redirect response before closing Chromium or any server.
  try { await Promise.all([...cleanups].map(cleanup => cleanup())); }
  // Closing Chromium on Windows can exceed five seconds under a full-suite
  // load. Keep teardown bounded, separately from the interaction-test deadlines.
  finally { if (browser) await bounded(browser.close(), 'browser cleanup', 20_000); }
}, { timeout: 30_000 });

async function open(t, { modal = true, withConfirm = true, holdResult = false, failedPost = false, init } = {}) {
  const posts = [];
  const errors = [];
  const held = new Set();
  let released = false;
  let resultGets = 0;
  let context;
  let closing;
  const counts = () => {
    const total = {};
    for (const post of posts) total[post.path.replace(/^\//, '')] = (total[post.path.replace(/^\//, '')] || 0) + 1;
    return total;
  };
  const sendResult = res => {
    if (!res.destroyed && !res.writableEnded) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(resultHtml());
    }
  };
  function release() {
    released = true;
    for (const res of held) sendResult(res);
    held.clear();
  }
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(pageHtml({ modal, withConfirm }));
      return;
    }
    if (url.pathname === '/result') {
      resultGets++;
      if (released || !holdResult) sendResult(res);
      else {
        held.add(res);
        res.on('close', () => held.delete(res));
      }
      return;
    }
    if (url.pathname === '/post-count') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(counts()));
      return;
    }
    if (req.method === 'POST') {
      const post = { path: url.pathname, headers: req.headers, chunks: [], body: null };
      posts.push(post);
      req.on('data', chunk => post.chunks.push(chunk));
      req.on('end', () => {
        post.body = Buffer.concat(post.chunks).toString('utf8');
        if (failedPost) {
          // Application-level failure for the background-submit retry test, not PHP validation.
          res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ success: false }));
          return;
        }
        // Same shape as CodeIgniter's redirect() after a successful POST.
        res.writeHead(302, { Location: `/result?path=${encodeURIComponent(post.path)}` }).end();
      });
      return;
    }
    // Real files out of public/, including the real confirm.js and Bootstrap bundle.
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      const mime = {
        '.js': 'text/javascript', '.css': 'text/css', '.map': 'application/json',
        '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.jpg': 'image/jpeg'
      };
      res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }).end(content);
    });
  });
  server.requestTimeout = 5_000;
  server.headersTimeout = 5_000;
  server.setTimeout(12_000, socket => socket.destroy());
  async function cleanup() {
    if (closing) return closing;
    release();
    closing = (async () => {
      try {
        if (context) {
          if (resultGets) {
            await Promise.all(context.pages().map(page =>
              page.waitForURL('**/result**', { waitUntil: 'load', timeout: 2_000 }).catch(() => {})));
          }
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
    assert.deepEqual(errors, [], 'The real confirm.js flow must produce no page errors and no console errors');
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const origin = `http://127.0.0.1:${server.address().port}`;
  context = await browser.newContext({ viewport: { width: 1000, height: 1000 } });
  const page = await context.newPage();
  page.on('request', request => {
    if (!request.url().startsWith(origin + '/')) errors.push(`Unexpected external request: ${request.url()}`);
  });
  page.on('console', message => {
    if (message.type() === 'error') errors.push(`console.error: ${message.text()}`);
  });
  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
  page.setDefaultTimeout(3_000);
  page.setDefaultNavigationTimeout(4_000);
  if (init) await page.addInitScript(init);
  await page.goto(`${origin}/fixture`);
  await page.evaluate(() => {
    window.__dialogHidden = true;
    const dialog = document.querySelector('#synapseConfirmModal');
    if (dialog) {
      dialog.addEventListener('show.bs.modal', () => { window.__dialogHidden = false; });
      dialog.addEventListener('hidden.bs.modal', () => { window.__dialogHidden = true; });
    }
  });
  // Attached after the first load, so navigations only ever records a POST redirect.
  const navigations = [];
  page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations.push(frame.url()); });

  // Listen for the dialog's own shown.bs.modal so assertions run against the
  // settled dialog rather than mid-transition.
  const armDialog = () => page.evaluate(() => {
    window.__dialogShown = false;
    const modal = document.querySelector('#synapseConfirmModal');
    if (modal) modal.addEventListener('shown.bs.modal', () => { window.__dialogShown = true; }, { once: true });
  });
  const shown = () => bounded(page.waitForFunction(() => window.__dialogShown === true), 'dialog shown', 3_000);

  return {
    page, origin, posts, navigations, counts, release, armDialog, shown,
    resultGets: () => resultGets,
    // Read the native-prompt log from whichever document the page is on now,
    // so the same accessor works before and after the redirect.
    confirmCalls: () => page.evaluate(() =>
      Array.isArray(window.__confirmCalls) ? window.__confirmCalls : []),
    // Click a submit button and wait until the shared dialog has fully appeared.
    openModal: async selector => {
      await armDialog();
      await page.locator(selector).click();
      await shown();
    },
    // CSS visibility changes before Bootstrap removes the backdrop/restores focus.
    hidden: () => page.waitForFunction(() => window.__dialogHidden === true, null, { timeout: 3_000 }),
    // Probe from inside the page so a held navigation cannot hide what happened.
    watchDialog: async () => {
      const seen = { shown: false };
      await page.exposeFunction('reportDialogShown', () => { seen.shown = true; });
      await page.evaluate(() => {
        const modal = document.querySelector('#synapseConfirmModal');
        if (!modal) return;
        new MutationObserver(() => {
          if (modal.classList.contains('show') && !window.__dialogSeen) {
            window.__dialogSeen = true;
            window.reportDialogShown();
          }
        }).observe(modal, { attributes: true, attributeFilter: ['class'] });
      });
      return seen;
    }
  };
}

test('opening the dialog posts nothing and paints title, message, label and danger tone', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#dangerSubmit');
  await wait(250);

  assert.deepEqual(f.counts(), {}, 'Opening the confirmation must not POST anything');
  assert.deepEqual(f.navigations, [], 'Opening must not navigate');
  assert.equal(await page.locator('#synapseConfirmModal').isVisible(), true);

  // Title, message and accept label, all driven from the form's data-* attributes.
  assert.equal(await page.locator('[data-confirm-title-text]').textContent(), 'Delete appointment');
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'Delete this appointment?');
  assert.equal(await page.locator('[data-confirm-accept]').textContent(), 'Delete it');
  assert.equal(await page.locator('[data-confirm-cancel]').textContent(), 'Cancel');

  // Tone: the danger button, plus the icon confirm.js actually drives (the first match).
  assert.match(await page.locator('[data-confirm-accept]').getAttribute('class'), /\bbtn-danger\b/);
  assert.match(await page.locator('#synapseConfirmTitle [data-confirm-icon]').getAttribute('class'),
    /bi-exclamation-triangle-fill/);

  // Accessibility wiring must resolve to real elements, and the header must hold
  // exactly ONE title node: a duplicated [data-confirm-title-text] was a real bug.
  const aria = await page.evaluate(() => {
    const modal = document.querySelector('#synapseConfirmModal');
    const labelledby = document.getElementById(modal.getAttribute('aria-labelledby'));
    const describedby = document.getElementById(modal.getAttribute('aria-describedby'));
    const squash = text => text.replace(/\s+/g, ' ').trim();
    return {
      labelledbyId: modal.getAttribute('aria-labelledby'),
      describedbyId: modal.getAttribute('aria-describedby'),
      labelledbyExists: Boolean(labelledby),
      describedbyExists: Boolean(describedby),
      titleText: labelledby ? squash(labelledby.textContent) : null,
      describedText: describedby ? squash(describedby.textContent) : null,
      titleCount: document.querySelectorAll('[data-confirm-title-text]').length,
      messageCount: document.querySelectorAll('[data-confirm-message]').length
    };
  });
  assert.deepEqual(aria, {
    labelledbyId: 'synapseConfirmTitle',
    describedbyId: 'synapseConfirmMessage',
    labelledbyExists: true,
    describedbyExists: true,
    titleText: 'Delete appointment',
    describedText: 'Delete this appointment?',
    titleCount: 1,
    messageCount: 1
  }, 'aria-labelledby/aria-describedby must resolve to existing elements holding exactly one title and one message');

  assert.equal(await page.evaluate(() => document.querySelector('#dangerForm').hasAttribute('data-confirm-bypass')), false,
    'An unanswered dialog must leave the form armed rather than half-submitted');

  // Last, because it is the one behaviour that has never worked: a destructive
  // dialog must open with focus on the safe choice.
  assert.equal(await page.evaluate(() => document.activeElement === document.querySelector('[data-confirm-cancel]')), true,
    'A danger dialog must move focus to Cancel, not to the destructive accept button');
});

test('accepting posts exactly once with the right action and CSRF body, then closes the dialog', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#dangerSubmit');
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });

  assert.equal(f.posts.length, 1, 'Exactly one POST, with no duplicate from the approval path');
  const [post] = f.posts;
  assert.equal(post.path, '/danger');
  assert.match(post.headers['content-type'], /^application\/x-www-form-urlencoded/);
  const fields = new URLSearchParams(post.body);
  assert.equal(fields.get(CSRF_FIELD), CSRF_TOKEN, 'The real CSRF hidden input must ride along');
  assert.equal(fields.get('intent'), 'delete-appointment', 'The rest of the real form body must ride along');

  assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false, 'The dialog must be gone after the redirect');
  assert.equal(await page.locator('#pageTitle').textContent(), 'Result');
});

async function requireCancellationReason(page, value = '') {
  await page.evaluate(value => {
    const form = document.querySelector('#dangerForm');
    form.setAttribute('data-confirm-reason', '');
    const reason = document.createElement('input');
    reason.type = 'hidden';
    reason.name = 'reason';
    reason.value = value;
    form.append(reason);
  }, value);
}

const reasonInput = '[data-confirm-reason-input]';
const hiddenReason = '#dangerForm input[type="hidden"][name="reason"]';

test('cancellation requires a labelled, focused reason and rejects blank, Unicode whitespace, controls and formatting', { timeout: 20_000 }, async t => {
  const f = await open(t);
  const { page } = f;
  await requireCancellationReason(page);
  await f.openModal('#dangerSubmit');

  const input = page.locator(reasonInput);
  assert.equal(await page.locator('[data-confirm-reason-group]').isVisible(), true);
  assert.equal(await page.getByRole('textbox', { name: 'Cancellation reason' }).count(), 1);
  assert.equal(await input.getAttribute('type'), 'text');
  assert.equal(await input.evaluate(el => el.required && !el.disabled && document.activeElement === el), true);
  assert.equal(await input.getAttribute('maxlength'), '255');

  for (const value of ['', '   ', '\u00a0\u2003\u202f\u3000', 'before\u0001after', 'before\u007fafter', 'before\u0085after', 'before\u200bafter', 'before\u202eafter', 'x'.repeat(256)]) {
    // Programmatic value assignment also exercises the length guard beyond maxlength.
    await input.evaluate((el, value) => {
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }, value);
    await page.locator('[data-confirm-accept]').click();
    await wait(100);
    assert.equal(await page.locator('#synapseConfirmModal').isVisible(), true, `Invalid reason ${JSON.stringify(value)} must leave the dialog open`);
    assert.equal(await input.evaluate(el => el.validity.valid), false);
    assert.equal(await page.locator(hiddenReason).inputValue(), '', 'Rejected input must not reach the hidden field');
    assert.deepEqual(f.counts(), {}, 'Rejected reasons must not POST');
    assert.deepEqual(f.navigations, [], 'Rejected reasons must not navigate');
  }

  // An input event must clear the previous custom error so correction can succeed.
  await input.fill('Corrected reason');
  await input.press('Enter');
  await page.waitForURL('**/result**');
  assert.deepEqual(f.counts(), { danger: 1 });
  assert.equal(new URLSearchParams(f.posts[0].body).get('reason'), 'Corrected reason');
});

for (const [label, entered, expected] of [
  ['trimmed text', ' \u00a0 Scheduling conflict & travel = unavailable \u3000 ', 'Scheduling conflict & travel = unavailable'],
  ['zero', '0', '0'],
  ['255 characters', 'x'.repeat(255), 'x'.repeat(255)]
]) {
  test(`an approved cancellation posts ${label} exactly once with the original form and CSRF`, { timeout: 15_000 }, async t => {
    const f = await open(t);
    const { page } = f;
    await requireCancellationReason(page);
    await f.openModal('#dangerSubmit');
    await page.locator(reasonInput).fill(entered);
    assert.equal(await page.locator(hiddenReason).inputValue(), '', 'Typing alone must not change the hidden field');
    assert.deepEqual(f.counts(), {});
    await page.locator('[data-confirm-accept]').click();
    await page.waitForURL('**/result**');
    assert.deepEqual(f.counts(), { danger: 1 });
    const [post] = f.posts;
    assert.equal(post.path, '/danger');
    assert.equal(post.headers['x-csrf-token'], undefined, 'This remains a native form POST, not a replacement AJAX request');
    assert.match(post.headers['content-type'], /^application\/x-www-form-urlencoded/);
    const fields = new URLSearchParams(post.body);
    assert.deepEqual(fields.getAll('reason'), [expected]);
    assert.equal(fields.get(CSRF_FIELD), CSRF_TOKEN);
    assert.equal(fields.get('intent'), 'delete-appointment');
    assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false);
  });
}

for (const dismissal of ['cancel button', 'Escape', 'backdrop']) {
  test(`dismissing a cancellation with ${dismissal} preserves the hidden reason and posts nothing`, { timeout: 15_000 }, async t => {
    const f = await open(t);
    const { page } = f;
    await requireCancellationReason(page, 'Previously approved reason');
    await f.openModal('#dangerSubmit');
    assert.equal(await page.locator(reasonInput).inputValue(), 'Previously approved reason');
    await page.locator(reasonInput).fill('Unapproved edit');
    if (dismissal === 'cancel button') await page.locator('[data-confirm-cancel]').click();
    else if (dismissal === 'Escape') await page.keyboard.press('Escape');
    else await page.mouse.click(8, 500);
    await f.hidden();
    await wait(150);
    assert.equal(await page.locator(hiddenReason).inputValue(), 'Previously approved reason');
    assert.equal(await page.locator(reasonInput).inputValue(), '', 'Closing clears the transient editor');
    assert.deepEqual(f.counts(), {});
    assert.deepEqual(f.navigations, []);
    await f.openModal('#dangerSubmit');
    assert.equal(await page.locator(reasonInput).inputValue(), 'Previously approved reason', 'Reopening must use the hidden value, not the discarded edit');
  });
}

test('switching from a cancellation to an unrelated question hides and resets the reason editor', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;
  await requireCancellationReason(page, 'Saved reason');
  await f.openModal('#dangerSubmit');
  await page.locator(reasonInput).fill('Discard this edit');
  // Replace the open question through the public API, not by bypassing the backdrop.
  await page.evaluate(() => {
    window.__unrelatedAnswer = null;
    window.SynapseConfirm.confirm({ message: 'Unrelated question?', tone: 'primary' })
      .then(answer => { window.__unrelatedAnswer = answer; });
  });
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'Unrelated question?');
  assert.equal(await page.locator('[data-confirm-reason-group]').isVisible(), false);
  assert.equal(await page.locator(reasonInput).inputValue(), '');
  assert.equal(await page.locator(reasonInput).evaluate(el => el.disabled && !el.required && el.validity.valid), true);
  assert.equal(await page.locator('[data-confirm-cancel]').evaluate(el => document.activeElement === el), true);
  assert.equal(await page.locator(hiddenReason).inputValue(), 'Saved reason');
  await page.locator('[data-confirm-accept]').click();
  await f.hidden();
  await page.waitForFunction(() => window.__unrelatedAnswer === true);
  assert.deepEqual(f.counts(), {}, 'Replacing the question must decline the original cancellation');
  assert.deepEqual(f.navigations, []);

  // The unchanged general form must still submit without any reason field.
  await f.openModal('#nestedSubmit');
  assert.equal(await page.locator('[data-confirm-reason-group]').isVisible(), false);
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL('**/result**');
  assert.deepEqual(f.counts(), { nested: 1 });
  assert.equal(new URLSearchParams(f.posts[0].body).has('reason'), false);
});

for (const missing of ['modal', 'reason group', 'reason input', 'hidden reason']) {
  test(`a reason-required form fails closed without its ${missing}`, { timeout: 15_000 }, async t => {
    const f = await open(t, { modal: missing !== 'modal', init: nativePrompt(true) });
    const { page } = f;
    await requireCancellationReason(page, 'Saved reason');
    await page.evaluate(missing => {
      const selectors = {
        'reason group': '[data-confirm-reason-group]',
        'reason input': '[data-confirm-reason-input]',
        'hidden reason': '#dangerForm input[name="reason"]'
      };
      if (selectors[missing]) document.querySelector(selectors[missing]).remove();
    }, missing);
    for (let attempt = 0; attempt < 2; attempt++) {
      await page.locator('#dangerSubmit').click();
      await wait(200);
      assert.deepEqual(f.counts(), {});
      assert.deepEqual(f.navigations, []);
      assert.deepEqual(await f.confirmCalls(), [], 'A native yes/no prompt cannot collect a mandatory reason');
      assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false);
      if (missing !== 'hidden reason') assert.equal(await page.locator(hiddenReason).inputValue(), 'Saved reason');
    }
  });
}

test('a failed background response preserves the approved hidden reason when retrying', { timeout: 15_000 }, async t => {
  const f = await open(t, { failedPost: true });
  const { page } = f;
  await requireCancellationReason(page);
  // Model a background form consumer: it only receives the approved native submit.
  // The fixture response is an application failure; no PHP/session behavior is asserted.
  await page.evaluate(() => {
    window.__backgroundResults = [];
    document.querySelector('#dangerForm').addEventListener('submit', async event => {
      event.preventDefault();
      const response = await fetch(event.target.action, {
        method: event.target.method,
        body: new URLSearchParams(new FormData(event.target))
      });
      window.__backgroundResults.push(await response.json());
    });
  });
  await f.openModal('#dangerSubmit');
  await page.locator(reasonInput).fill('  Scheduling conflict  ');
  await page.locator('[data-confirm-accept]').click();
  await f.hidden();
  await page.waitForFunction(() => window.__backgroundResults.length === 1);
  assert.deepEqual(await page.evaluate(() => window.__backgroundResults), [{ success: false }]);
  assert.deepEqual(f.counts(), { danger: 1 });
  assert.equal(await page.locator(hiddenReason).inputValue(), 'Scheduling conflict');

  await f.openModal('#dangerSubmit');
  assert.equal(await page.locator(reasonInput).inputValue(), 'Scheduling conflict');
  assert.deepEqual(f.counts(), { danger: 1 }, 'Retrying must ask again, not reuse approval');
  await page.locator('[data-confirm-accept]').click();
  await f.hidden();
  await page.waitForFunction(() => window.__backgroundResults.length === 2);
  assert.deepEqual(f.counts(), { danger: 2 });
  for (const post of f.posts) {
    const fields = new URLSearchParams(post.body);
    assert.equal(fields.get('reason'), 'Scheduling conflict');
    assert.equal(fields.get(CSRF_FIELD), CSRF_TOKEN);
    assert.equal(fields.get('intent'), 'delete-appointment');
  }
  assert.deepEqual(f.navigations, []);
});

test('the cancel button posts nothing, closes the dialog and leaves the form usable', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#dangerSubmit');
  await page.locator('[data-confirm-cancel]').click();
  await f.hidden();
  await wait(200);

  assert.deepEqual(f.counts(), {}, 'Cancelling must not POST');
  assert.deepEqual(f.navigations, [], 'Cancelling must not navigate');
  assert.equal(await page.evaluate(() => document.querySelector('#dangerForm').hasAttribute('data-confirm-bypass')), false,
    'A cancelled dialog must leave the form armed for the next attempt');

  await f.openModal('#dangerSubmit');
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });
  assert.equal(f.counts().danger, 1, 'A retry after a cancel must post exactly once');
});

test('Escape posts nothing and closes the dialog', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#dangerSubmit');
  await page.keyboard.press('Escape');
  await f.hidden();
  await wait(200);

  assert.deepEqual(f.counts(), {}, 'Escape must not POST');
  assert.deepEqual(f.navigations, [], 'Escape must not navigate');
});

test('a backdrop click posts nothing and closes the dialog', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#dangerSubmit');
  // The dialog is centred with max-width 420px, so (8, 500) is the backdrop itself.
  await page.mouse.click(8, 500);
  await wait(500);

  assert.deepEqual(f.counts(), {}, 'A backdrop click must not POST');
  assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false,
    'A backdrop click must dismiss the confirmation dialog (confirm.js builds it with backdrop: "static")');
});

test('a form without data-confirm submits untouched, with no dialog and no side effects', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;
  const dialog = await f.watchDialog();

  await page.locator('#plainSubmit').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });

  assert.equal(dialog.shown, false, 'A form without data-confirm must never open the dialog');
  assert.equal(f.counts().plain, 1, 'The unguarded form must submit exactly once');
  assert.equal(f.counts().danger, undefined, 'A guarded form elsewhere on the page must not be affected');
  const [post] = f.posts;
  assert.equal(post.path, '/plain');
  assert.equal(new URLSearchParams(post.body).get(CSRF_FIELD), CSRF_TOKEN);
});

test('a double-click on a danger form posts exactly once', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.armDialog();
  await page.locator('#dangerSubmit').dblclick();
  await f.shown();
  await wait(250);

  assert.deepEqual(f.counts(), {}, 'The second click of a double-click must not slip past the dialog');
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });
  assert.equal(f.counts().danger, 1, 'A double-click must resolve to exactly one POST');
});

test('rapid double submit posts exactly once and never bypasses the dialog', { timeout: 15_000 }, async t => {
  const f = await open(t, { holdResult: true });
  const { page } = f;
  const reported = deferred();
  await page.exposeFunction('reportProbe', snapshot => reported.resolve(snapshot));

  // Two submits in one task, as a fast double-click or a repeated Enter produces.
  await page.evaluate(() => {
    const form = document.querySelector('#dangerForm');
    form.requestSubmit();
    form.requestSubmit();
    setTimeout(async () => {
      try {
        const counts = await fetch('/post-count').then(response => response.json());
        await window.reportProbe({
          counts,
          dialogShown: document.querySelector('#synapseConfirmModal').classList.contains('show')
        });
      } catch (error) {
        await window.reportProbe({ error: String(error) });
      }
    }, 400);
  });

  // Probed from inside the page: a held redirect keeps this document alive.
  const probe = await bounded(reported.promise, 'rapid double submit probe');
  assert.equal(probe.error, undefined);
  assert.equal(probe.dialogShown, true, 'The dialog must still be the thing the second submit hits');
  assert.equal(probe.counts.danger || 0, 0,
    'A submit that arrives while the dialog is open must be swallowed, never sent to the server');

  // This fixture deliberately HOLDS the redirect response, so the navigation this
  // click starts never completes. Playwright's click waits for scheduled
  // navigations by default, which is what made this test time out on some runs.
  // noWaitAfter is deprecated and has no effect in Playwright 1.64, so fire the
  // real mouse click directly (no navigation wait) and then poll the server.
  const settled = { danger: 1 };
  await page.mouse.click(...await acceptCentre(page, '[data-confirm-accept]'));
  await waitForCounts(f.counts, settled, 'rapid double submit POST');
  assert.deepEqual(f.counts(), settled,
    'Rapid double submit must resolve to exactly one POST: a repeat submit must never reach the server');
});

test('without confirm.js there is no dialog and the form submits normally', { timeout: 15_000 }, async t => {
  const f = await open(t, {
    withConfirm: false,
    init: nativePrompt(false)
  });
  const { page } = f;
  const dialog = await f.watchDialog();

  await page.locator('#dangerSubmit').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });

  assert.equal(dialog.shown, false, 'With no script there is no dialog at all');
  assert.deepEqual(await f.confirmCalls(), [],
    'With no confirm.js the native prompt must never be consulted');
  assert.equal(f.counts().danger, 1, 'A form must never be locked out when JavaScript is unavailable');
});

test('the native confirm() fallback is used and submits exactly once when confirmed', { timeout: 15_000 }, async t => {
  const f = await open(t, {
    modal: false,
    init: nativePrompt(true)
  });
  const { page } = f;

  await page.locator('#dangerSubmit').click();
  // Wait for the redirect rather than guessing: the log must still be readable
  // once the confirmed submit has navigated to the result page.
  await page.waitForURL(`**/result**`, { timeout: 3_000 });
  await bounded(page.waitForFunction(() => Array.isArray(window.__confirmCalls)
    && window.__confirmCalls.length === 1), 'native prompt call recorded', 3_000);

  assert.deepEqual(await f.confirmCalls(), ['Delete this appointment?'],
    'The real prompt text must reach the native dialog when the shared markup is missing');
  assert.equal(f.counts().danger, 1, 'A confirmed native prompt must submit the form exactly once');
});

test('declining the native confirm() posts nothing, while unguarded forms still submit', { timeout: 15_000 }, async t => {
  const f = await open(t, {
    modal: false,
    init: nativePrompt(false)
  });
  const { page } = f;

  // Prove the fallback is really taken here, not skipped: with the shared markup
  // absent, confirm.js must call the native prompt exactly once with the form's
  // own text before deciding anything.
  await page.locator('#dangerSubmit').click();
  await bounded(page.waitForFunction(() => Array.isArray(window.__confirmCalls)
    && window.__confirmCalls.length === 1), 'native prompt call recorded', 3_000);
  assert.deepEqual(await f.confirmCalls(), ['Delete this appointment?'],
    'A declined native prompt must have been shown the real prompt text exactly once');
  assert.deepEqual(f.counts(), {}, 'A declined native prompt must not POST');
  assert.deepEqual(f.navigations, [], 'A declined native prompt must not navigate');

  await page.locator('#plainSubmit').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });
  assert.equal(f.counts().plain, 1, 'A form without data-confirm always submits');
});

test('a form added after load (HTMX-style) is still intercepted and approved', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  const lateForm = '<form id="lateForm" action="/late" method="POST" data-confirm="Remove the late item?"' +
    ' data-confirm-title="Remove item" data-confirm-label="Remove" data-confirm-tone="danger">' +
    `<input type="hidden" name="${CSRF_FIELD}" value="${CSRF_TOKEN}">` +
    '<button type="submit" id="lateSubmit">Remove</button></form>';
  await page.evaluate(html => { document.body.insertAdjacentHTML('beforeend', html); }, lateForm);

  await f.openModal('#lateSubmit');
  await wait(200);
  assert.deepEqual(f.counts(), {}, 'A late form must not POST before approval');
  assert.equal(await page.locator('[data-confirm-title-text]').textContent(), 'Remove item');
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'Remove the late item?');

  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });
  assert.equal(f.counts().late, 1, 'The delegated listener must approve the late form exactly once');
  assert.equal(new URLSearchParams(f.posts[0].body).get(CSRF_FIELD), CSRF_TOKEN);
});

test('the promise API resolves true when the accept button is clicked', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.armDialog();
  await page.evaluate(() => {
    window.__approved = null;
    window.SynapseConfirm.confirm({ message: 'Discard this draft?', tone: 'danger' })
      .then(value => { window.__approved = value; });
  });
  await f.shown();
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'Discard this draft?');
  assert.match(await page.locator('[data-confirm-accept]').getAttribute('class'), /\bbtn-danger\b/);

  await page.locator('[data-confirm-accept]').click();
  await bounded(page.waitForFunction(() => window.__approved !== null), 'approved promise', 3_000);
  assert.equal(await page.evaluate(() => window.__approved), true);
  assert.deepEqual(f.counts(), {}, 'The imperative API must never submit anything by itself');
});

test('the promise API resolves false when the dialog is cancelled', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.armDialog();
  await page.evaluate(() => {
    window.__cancelled = null;
    window.SynapseConfirm.confirm({ message: 'Discard this draft?', tone: 'danger' })
      .then(value => { window.__cancelled = value; });
  });
  await f.shown();

  await page.locator('[data-confirm-cancel]').click();
  await f.hidden();
  await bounded(page.waitForFunction(() => window.__cancelled !== null), 'cancelled promise', 3_000);
  assert.equal(await page.evaluate(() => window.__cancelled), false);
  assert.deepEqual(f.counts(), {});
});

test('two concurrent confirm() calls both settle, the first as declined', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.armDialog();
  await page.evaluate(() => {
    window.__results = [];
    window.SynapseConfirm.confirm({ message: 'first question', tone: 'danger' })
      .then(value => window.__results.push(['first', value]));
    window.SynapseConfirm.confirm({ message: 'second question', tone: 'warning' })
      .then(value => window.__results.push(['second', value]));
  });
  await f.shown();
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'second question',
    'The most recent request owns the single dialog');

  await bounded(page.waitForFunction(() => window.__results.length >= 1), 'first promise', 3_000);
  assert.deepEqual(await page.evaluate(() => window.__results), [['first', false]],
    'A superseded request must resolve false rather than stay pending forever');

  await page.locator('[data-confirm-accept]').click();
  await bounded(page.waitForFunction(() => window.__results.length === 2), 'second promise', 3_000);
  assert.deepEqual(await page.evaluate(() => window.__results), [['first', false], ['second', true]]);
  assert.deepEqual(f.counts(), {}, 'Neither imperative call may submit a form');
});

test('a confirm form with no data-confirm-title falls back to the default title', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#untitledSubmit');
  await wait(200);

  assert.deepEqual(f.counts(), {});
  assert.equal(await page.locator('[data-confirm-title-text]').textContent(), 'Please confirm');
  assert.equal(await page.locator('[data-confirm-title-text]').count(), 1);
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'Proceed with this?');
  assert.equal(await page.locator('[data-confirm-accept]').textContent(), 'Yes, continue');
  assert.match(await page.locator('[data-confirm-accept]').getAttribute('class'), /\bbtn-danger\b/,
    'An unspecified tone must fall back to danger');

  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(`**/result**`, { timeout: 3_000 });
  assert.equal(f.counts().untitled, 1);
});

test('a warning-tone form nested in a container is intercepted too', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await f.openModal('#nestedSubmit');
  await wait(200);

  assert.deepEqual(f.counts(), {}, 'Delegation must reach nested forms');
  assert.equal(await page.locator('[data-confirm-title-text]').textContent(), 'Archive record');
  assert.equal(await page.locator('[data-confirm-message]').textContent(), 'Archive this record?');
  assert.match(await page.locator('[data-confirm-accept]').getAttribute('class'), /\bbtn-warning\b/);

  await page.locator('[data-confirm-cancel]').click();
  await f.hidden();
  assert.deepEqual(f.counts(), {});
});
// ---------------------------------------------------------------------------
// Lifecycle invariants. Each of these corresponds to a defect that shipped and
// was caught only by exercising the real runtime.
// ---------------------------------------------------------------------------

test('failed validation expires approval; a corrected form must be confirmed again', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  // The field starts satisfiable so the dialog can open; it is made required
  // only afterwards, so approval is what runs into the validation wall.
  await page.evaluate(() => {
    const required = document.createElement('input');
    required.name = 'required_note';
    required.id = 'requiredNote';
    required.value = '';
    document.querySelector('#dangerForm').prepend(required);
  });

  await f.openModal('#dangerSubmit');
  await page.evaluate(() => {
    window.validationAttempts = 0;
    const field = document.querySelector('#requiredNote');
    field.required = true;
    field.addEventListener('invalid', () => window.validationAttempts++);
  });
  await page.locator('[data-confirm-accept]').click();
  // Wait for the actual approved retry to fail validation, not merely the CSS
  // hide animation. Correcting the field sooner would race that retry.
  await page.waitForFunction(() => window.validationAttempts > 0);
  await f.hidden();

  assert.deepEqual(f.counts(), {},
    'A submit blocked by validation must never reach the server');
  assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false,
    'Approving must dismiss the dialog even when validation blocks the submit');
  assert.equal(await page.locator('#dangerForm').getAttribute('data-confirm-bypass'), null,
    'Consent must not be left behind in the DOM after a blocked submit');

  await page.locator('#requiredNote').fill('ok');
  await f.openModal('#dangerSubmit');
  assert.deepEqual(f.counts(), {}, 'An old approval cannot authorize changed form data');
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL('**/result**');
  assert.deepEqual(f.counts(), { danger: 1 });
});

test('a permit is not honoured for a different button or a different form', { timeout: 15_000 }, async t => {
  // No server round-trip is involved: submits are recorded inside the page and
  // their navigation is blocked, so an in-flight request can never destroy the
  // document this test is asserting in.
  const f = await open(t);
  const { page } = f;

  await page.evaluate(() => {
    window.__sent = [];
    document.addEventListener('submit', event => {
      if (!event.defaultPrevented) window.__sent.push({
        form: event.target.id,
        submitter: event.submitter ? event.submitter.id : null
      });
      // Observe after the confirmation guard, and suppress only navigation.
      event.preventDefault();
    });
    document.querySelector('#dangerForm').insertAdjacentHTML(
      'beforeend', '<button id="otherSubmit" type="submit" name="intent" value="other">Other</button>');
  });

  await f.openModal('#dangerSubmit');
  await page.locator('[data-confirm-accept]').click();
  await f.hidden();
  await page.waitForFunction(() => window.__sent.length === 1);

  // A different submitter in the same form must prompt again rather than ride
  // along on consent that was granted for another button.
  await f.openModal('#otherSubmit');

  assert.deepEqual(
    await page.evaluate(() => window.__sent.map(entry => entry.submitter)),
    ['dangerSubmit'],
    'Consent must be scoped to one button; a different submit must re-prompt, never dispatch');

  await page.locator('[data-confirm-cancel]').click();
  await f.hidden();

  // A different form must also re-prompt.
  await f.openModal('#nestedSubmit');
  await page.locator('[data-confirm-cancel]').click();
  await f.hidden();

  assert.deepEqual(
    await page.evaluate(() => window.__sent.map(entry => entry.form)),
    ['dangerForm'],
    'Consent must never carry across to another form');
});

test('the promise API dismisses the dialog on approval instead of leaving it open', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;

  await page.evaluate(() => {
    window.__answer = null;
    window.SynapseConfirm.confirm({ message: 'Imperative probe' }).then(value => { window.__answer = value; });
  });
  await bounded(page.waitForFunction(
    () => document.querySelector('#synapseConfirmModal').classList.contains('show')),
    'imperative dialog shown');

  await page.locator('[data-confirm-accept]').click();
  await bounded(page.waitForFunction(() => window.__answer === true), 'promise resolved true');
  // The hide is animated, so wait for the settled state before asserting.
  await f.hidden();

  assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false,
    'An approving caller must not be left behind an open dialog');
});

test('modal initialization does not accumulate event listeners across requests', { timeout: 15_000 }, async t => {
  const f = await open(t, { init: () => {
    window.listenerRegistrations = [];
    const add = EventTarget.prototype.addEventListener;
    EventTarget.prototype.addEventListener = function(type, handler, options) {
      // Observe persistent lifecycle/action handlers, not the fixture's once-only
      // shown listener or Bootstrap's per-transition/backdrop one-shot handlers.
      if ((this.id === 'synapseConfirmModal' && ['hide.bs.modal', 'hidden.bs.modal'].includes(type)) ||
          (this.hasAttribute?.('data-confirm-accept') && type === 'click')) {
        window.listenerRegistrations.push(type);
      }
      return add.call(this, type, handler, options);
    };
  } });
  const { page } = f;

  await f.openModal('#dangerSubmit');
  await page.locator('[data-confirm-cancel]').click();
  await f.hidden();
  const registrations = await page.evaluate(() => window.listenerRegistrations.slice());
  for (const selector of ['#dangerSubmit', '#nestedSubmit', '#dangerSubmit', '#untitledSubmit']) {
    await f.openModal(selector);
    await page.locator('[data-confirm-cancel]').click();
    await f.hidden();
  }

  assert.equal(
    await page.locator('#synapseConfirmModal').getAttribute('data-synapse-confirm-ready'), '',
    'The modal must advertise a single initialisation');

  assert.deepEqual(await page.evaluate(() => window.listenerRegistrations), registrations,
    'Opening the shared dialog again must not attach more listeners');
});

test('approval preserves the clicked button value and action override', { timeout: 15_000 }, async t => {
  const f = await open(t);
  await f.page.evaluate(() => {
    const button = document.querySelector('#dangerSubmit');
    button.name = 'operation';
    button.value = 'remove-specific-item';
    button.formAction = '/chosen-action';
  });
  await f.openModal('#dangerSubmit');
  await f.page.locator('[data-confirm-accept]').click();
  await f.page.waitForURL('**/result**');
  assert.equal(f.posts.length, 1);
  assert.equal(f.posts[0].path, '/chosen-action');
  assert.equal(new URLSearchParams(f.posts[0].body).get('operation'), 'remove-specific-item');
  assert.equal(new URLSearchParams(f.posts[0].body).get(CSRF_FIELD), CSRF_TOKEN);
});

test('a request during closing waits for hidden before opening the next question', { timeout: 15_000 }, async t => {
  const f = await open(t);
  const { page } = f;
  await f.openModal('#dangerSubmit');
  await page.evaluate(() => {
    window.afterClosing = null;
    document.querySelector('[data-confirm-cancel]').click();
    SynapseConfirm.confirm({ message: 'Queued during closing' }).then(value => window.afterClosing = value);
  });
  await page.waitForFunction(() =>
    document.querySelector('[data-confirm-message]').textContent === 'Queued during closing' &&
    document.activeElement === document.querySelector('[data-confirm-cancel]'));
  await page.locator('[data-confirm-accept]').click();
  await page.waitForFunction(() => window.afterClosing === true);
  assert.equal(await page.locator('#synapseConfirmModal').isVisible(), false);
  assert.deepEqual(f.counts(), {});
});

for (const theme of ['light', 'dark']) {
  test(`${theme} confirmation uses the real SYNAPSE theme with readable text and safe focus`, { timeout: 15_000 }, async t => {
    const f = await open(t);
    await f.page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
    await f.openModal('#dangerSubmit');
    const colors = await f.page.evaluate(() => {
      function rgba(color) {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        return Array.from(ctx.getImageData(0, 0, 1, 1).data);
      }
      function luminance(rgb) {
        const c = rgb.slice(0, 3).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
        return .2126 * c[0] + .7152 * c[1] + .0722 * c[2];
      }
      return ['#synapseConfirmTitle', '[data-confirm-message]', '[data-confirm-cancel]', '[data-confirm-accept]'].map(selector => {
        const el = document.querySelector(selector);
        const fg = rgba(getComputedStyle(el).color);
        let node = el, bg;
        do { bg = rgba(getComputedStyle(node).backgroundColor); node = node.parentElement; } while (bg[3] === 0 && node);
        const a = luminance(fg), b = luminance(bg);
        return { selector, contrast: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
      });
    });
    colors.forEach(sample => assert.ok(sample.contrast >= 4.5, JSON.stringify(sample)));
    assert.equal(await f.page.locator('[data-confirm-cancel]').evaluate(el => document.activeElement === el), true);
    await f.page.locator('[data-confirm-cancel]').click();
    await f.hidden();
    assert.equal(await f.page.locator('#dangerSubmit').evaluate(el => document.activeElement === el), true);
  });
}

test('a polling timeout rejects and stops sampling instead of keeping Node alive', async () => {
  let reads = 0;
  await assert.rejects(waitForCounts(() => { reads++; return {}; }, { impossible: 1 }, 'timeout probe', 40), /Timed out/);
  const stoppedAt = reads;
  await wait(80);
  assert.equal(reads, stoppedAt);
});
