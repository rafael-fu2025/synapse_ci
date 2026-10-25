'use strict';
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;

const clinical = {
  bp_systolic: '121', bp_diastolic: '81', pulse_bpm: '73', temp_c: '36.7',
  spo2_pct: '98', weight_kg: '65.5', height_cm: '168.5',
  chief_complaint: 'Headache', assessment_notes: 'Alert & oriented\nNo fever',
  diagnosis: 'Tension headache', services_rendered: 'Vitals check',
  treatment_plan: 'Rest & hydration', disposition: 'sent_home',
  follow_up_date: '2026-11-02', disposition_notes: 'Return if symptoms persist'
};
const csrf = '<input type="hidden" name="csrf_test_name" value="initial">';
function fixture() {
  const vitals = [
    ['bp_systolic', 30, 300, 1], ['bp_diastolic', 10, 200, 1], ['pulse_bpm', 20, 300, 1],
    ['temp_c', 25, 45, 0.1], ['spo2_pct', 0, 100, 1], ['weight_kg', 0.1, 500, 0.1], ['height_cm', 10, 300, 0.1]
  ].map(([name, min, max, step]) => `<input name="${name}" type="number" min="${min}" max="${max}" step="${step}">`).join('');
  // Minimal production selector/validation contract; no PHP, patient records, or app server.
  return `<!doctype html><html><head><meta charset="utf-8"><title>Encounter fixture</title>
    <meta name="csrf-name" content="csrf_test_name"><meta name="csrf-token" content="initial">
    <meta name="csrf-header" content="X-CSRF-TOKEN">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <script defer src="/assets/vendor/alpine/alpine.min.js"></script></head><body>
    <a id="leave" href="/left"><span>Leave encounter</span></a>
    <a id="chart" href="/chart" target="_blank">Full chart</a><a id="hash" href="#local">Local anchor</a>
    <a id="modal" href="/referral" data-app-modal-open>Referral modal</a>
    <main x-data="encounterStepper()" data-encounter-stepper data-draft-url="/draft">
      <div role="status" data-encounter-save-status>Saved</div><button type="button" data-save-draft hidden>Retry</button>
      <nav class="nav nav-tabs" role="tablist">${['triage', 'assessment', 'dispense', 'outcome'].map((step, i) => `
        <button type="button" class="nav-link ${i === 0 ? 'active' : ''}" data-bs-toggle="tab" data-bs-target="#step-${step}">${step}</button>`).join('')}</nav>
      <div class="tab-content">
        <section class="tab-pane active show" id="step-triage"><form method="post" action="/vitals" data-clinical-form data-next-step="#step-assessment">
          ${csrf}${vitals}<button type="submit">Save vitals</button></form></section>
        <section class="tab-pane" id="step-assessment"><form method="post" action="/assessment" data-clinical-form data-next-step="#step-dispense">
          ${csrf}<input name="chief_complaint" required maxlength="255" value="Headache">
          <textarea name="assessment_notes" maxlength="10000"></textarea><input name="diagnosis" maxlength="255">
          <input name="services_rendered" maxlength="10000"><textarea name="treatment_plan" maxlength="10000"></textarea>
          <button type="submit">Save assessment</button></form></section>
        <section class="tab-pane" id="step-dispense"><form method="post" action="/dispense">
          ${csrf}<input type="hidden" name="idempotency_key" value="fixture-dispense-key">
          <select name="medicine_id" required><option value="">Choose medicine</option><option value="7">Fixture medicine</option></select>
          <input type="number" name="quantity" min="1" max="1000000" step="1" value="1" required>
          <input name="instructions" maxlength="255"><button type="submit">Dispense medicine</button></form>
          <button type="button" data-discard-medicine>Discard medicine</button>
          <button type="button" data-continue-outcome>Continue to outcome</button></section>
        <section class="tab-pane" id="step-outcome"><form method="post" action="/complete" data-clinical-form data-complete-encounter>
          ${csrf}<select name="disposition" required><option value="discharged">Discharged</option><option value="sent_home">Sent home</option></select>
          <input type="text" id="follow_up_date"><input type="hidden" name="follow_up_date" value="">
          <textarea name="disposition_notes" maxlength="10000"></textarea><button type="submit">Complete encounter</button></form></section>
      </div>
    </main>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/csrf.js"></script><script src="/assets/js/encounter-stepper.js"></script>
    <script>
      function encounterStepper() {
        return {
          init() { this.$nextTick(() => {
            this.stopDraftSaving = window.SynapseEncounterStepper.init(this.$el);
            this.$el.dataset.ready = 'true';
          }); },
          destroy() { this.stopDraftSaving?.(); }
        };
      }
    </script></body></html>`;
}

before(async () => {
  const assets = new Map([
    ['/assets/vendor/bootstrap/css/bootstrap.min.css', 'text/css'],
    ['/assets/vendor/bootstrap/js/bootstrap.bundle.min.js', 'text/javascript'],
    ['/assets/vendor/alpine/alpine.min.js', 'text/javascript'],
    ['/assets/js/csrf.js', 'text/javascript'], ['/assets/js/encounter-stepper.js', 'text/javascript']
  ]);
  server = http.createServer((req, res) => {
    if (req.method !== 'GET') { res.writeHead(405).end('Writes must be mocked by Playwright'); return; }
    const pathname = new URL(req.url, 'http://localhost').pathname;
    if (pathname === '/encounter') { res.writeHead(200, { 'Content-Type': 'text/html' }).end(fixture()); return; }
    if (['/left', '/completed', '/dispensed', '/chart'].includes(pathname)) {
      res.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><title>Destination</title><h1>Destination</h1>'); return;
    }
    if (!assets.has(pathname)) { res.writeHead(404).end(); return; }
    fs.readFile(path.join(root, 'public', pathname), (error, content) => {
      if (error) { res.writeHead(404).end(error.message); return; }
      res.writeHead(200, { 'Content-Type': assets.get(pathname) }).end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) && fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install Playwright Chromium before running encounter stepper tests');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
}, { timeout: 20000 });

async function pageFor(t) {
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const posts = [], replies = [], errors = [], unexpected = [], dialogs = [];
  const page = await context.newPage();
  page.setDefaultTimeout(4000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.text().includes('Alpine Expression Error')) errors.push(message.text()); });
  const state = { page, posts, replies, dialogs, serverClinical: {}, acceptDialog: false };
  page.on('dialog', async dialog => {
    dialogs.push({ type: dialog.type(), message: dialog.message() });
    if (state.acceptDialog) await dialog.accept(); else await dialog.dismiss();
  });
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== origin || (request.method() !== 'GET' && !['/draft', '/complete', '/dispense'].includes(url.pathname))) {
      unexpected.push(`${request.method()} ${url.href}`); return route.abort();
    }
    if (request.method() === 'GET') return route.continue();
    const body = await new Response(request.postDataBuffer(), { headers: { 'Content-Type': request.headers()['content-type'] } }).formData();
    posts.push({ path: url.pathname, values: Object.fromEntries(body), headers: request.headers(), navigation: request.isNavigationRequest() });
    const reply = replies.shift() || {};
    if (reply.wait) await reply.wait;
    if (reply.abort) return route.abort('failed');
    const result = reply.body || { success: true, redirect: url.pathname === '/dispense' ? '/dispensed' : '/completed' };
    if (reply.raw === undefined && (reply.status || 200) < 400 && result.success && url.pathname !== '/dispense') {
      const { csrf_test_name, csrf_rotated, ...values } = Object.fromEntries(body);
      Object.assign(state.serverClinical, values);
    }
    await route.fulfill({ status: reply.status || 200, contentType: 'application/json', headers: reply.headers || {},
      body: reply.raw === undefined ? JSON.stringify(result) : reply.raw });
  });
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, [], 'No production JavaScript or Alpine errors');
    assert.deepEqual(unexpected, [], 'No external requests or unmocked app writes');
  });
  await page.goto(origin + '/encounter');
  await page.waitForSelector('[data-encounter-stepper][data-ready="true"]');
  return state;
}
const tab = (page, name) => page.locator(`[data-bs-target="#step-${name}"]`);
async function active(page, name) {
  await page.waitForFunction(name => document.querySelector('.tab-pane.active')?.id === 'step-' + name, name);
  assert.equal(await tab(page, name).getAttribute('aria-selected'), 'true');
}
async function saved(page) {
  await page.waitForFunction(() => document.querySelector('[data-encounter-save-status]').textContent === 'Saved' && !document.querySelector('[data-encounter-stepper]').inert);
}
async function errorShown(page, message) {
  await page.waitForFunction(message => document.querySelector('[data-encounter-save-status]').textContent.includes(message), message);
  assert.equal(await page.locator('[data-save-draft]').isVisible(), true);
  assert.equal(await page.locator('[data-encounter-stepper]').evaluate(el => el.inert), false);
}
async function setValues(page, values) {
  // Also models the custom datepicker's hidden ISO field and edits in other panes.
  await page.evaluate(values => {
    for (const [name, value] of Object.entries(values)) {
      const field = document.querySelector(`[name="${name}"]`);
      field.value = value;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, values);
}
async function unloadBlocked(page) {
  return page.evaluate(() => !window.dispatchEvent(new Event('beforeunload', { cancelable: true })));
}
async function beforeNavigate(page) {
  return page.evaluate(() => !document.dispatchEvent(new CustomEvent('synapse:before-navigate', {
    cancelable: true, detail: { url: '/left' }
  })));
}
function hold(state, t, response = {}) {
  let release;
  const wait = new Promise(resolve => { release = resolve; });
  state.replies.push({ ...response, wait });
  t.after(release);
  return release;
}
async function posted(state, count) {
  await state.page.waitForFunction(() => document.querySelector('[data-encounter-stepper]').inert);
  // The DOM enters busy state before Playwright's asynchronous request parsing finishes.
  await assertEventually(() => state.posts.length === count, `Expected ${count} mocked POST(s)`);
}
async function assertEventually(predicate, message) {
  const deadline = Date.now() + 4000;
  while (!predicate() && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 10));
  assert.ok(predicate(), message);
}

async function editFollowUpDate(page, visible, iso, validationMessage = '') {
  // Simulate the datepicker contract without loading its parsing/calendar UI.
  await page.evaluate(({ visible, iso, validationMessage }) => {
    const trigger = document.querySelector('#follow_up_date');
    trigger.value = visible;
    trigger.setCustomValidity(validationMessage);
    const hidden = document.querySelector('[name="follow_up_date"]');
    hidden.value = iso;
    for (const field of [trigger, hidden]) {
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, { visible, iso, validationMessage });
}
async function savePriorFollowUpDate(state) {
  await setValues(state.page, { follow_up_date: '2026-11-02' });
  await tab(state.page, 'outcome').click(); await active(state.page, 'outcome'); await saved(state.page);
  assert.equal(state.serverClinical.follow_up_date, '2026-11-02');
  assert.equal(state.posts.length, 1);
}

for (const navigation of ['tab', 'leave link', 'before-navigate']) {
  test(`invalid visible follow-up date blocks ${navigation} without clearing the saved server date`, { timeout: 15000 }, async t => {
    const state = await pageFor(t), { page, posts, serverClinical } = state;
    await savePriorFollowUpDate(state);
    await editFollowUpDate(page, 'not a date', '', 'Enter a valid follow-up date.');
    if (navigation === 'tab') await tab(page, 'triage').click();
    else if (navigation === 'leave link') await page.locator('#leave span').click();
    else assert.equal(await beforeNavigate(page), true);
    await errorShown(page, 'Correct the highlighted field'); await active(page, 'outcome');
    assert.equal(page.url(), origin + '/encounter');
    assert.equal(await page.locator('#follow_up_date').inputValue(), 'not a date');
    assert.equal(await page.locator('#follow_up_date').evaluate(el => el.validity.customError), true);
    assert.equal(await page.locator('[name="follow_up_date"]').inputValue(), '');
    assert.equal(posts.length, 1, 'Invalid visible text must not POST the cleared hidden ISO field');
    assert.equal(serverClinical.follow_up_date, '2026-11-02', 'Previously saved date remains unchanged');
    assert.equal(await unloadBlocked(page), true);
    await page.locator('[data-save-draft]').click(); await errorShown(page, 'Correct the highlighted field');
    assert.equal(posts.length, 1, 'Retry cannot bypass custom validity');
    await editFollowUpDate(page, 'November 3, 2026', '2026-11-03');
    await tab(page, 'triage').click(); await active(page, 'triage'); await saved(page);
    assert.equal(posts.length, 2);
    assert.deepEqual(posts[1].values, { follow_up_date: '2026-11-03', csrf_test_name: 'initial' });
    assert.equal(serverClinical.follow_up_date, '2026-11-03', 'Correcting the date permits a new save');
  });
}

test('intentionally clearing the follow-up date saves an empty ISO value after invalid text is removed', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts, serverClinical } = state;
  await savePriorFollowUpDate(state);
  await editFollowUpDate(page, 'invalid date', '', 'Enter a valid follow-up date.');
  await tab(page, 'triage').click(); await errorShown(page, 'Correct the highlighted field');
  assert.equal(serverClinical.follow_up_date, '2026-11-02');
  await editFollowUpDate(page, '', '');
  assert.equal(await page.locator('#follow_up_date').evaluate(el => el.checkValidity()), true);
  await tab(page, 'triage').click(); await active(page, 'triage'); await saved(page);
  assert.equal(posts.length, 2, 'Only the initial date save and intentional clear reach the server');
  assert.deepEqual(posts[1].values, { follow_up_date: '', csrf_test_name: 'initial' });
  assert.equal(serverClinical.follow_up_date, '', 'An intentional clear must not retain the prior server date');
  assert.equal(await unloadBlocked(page), false);
});

test('incomplete chief complaint is allowed in a draft despite native valueMissing', { timeout: 15000 }, async t => {
  const { page, posts, serverClinical } = await pageFor(t);
  await tab(page, 'assessment').click(); await active(page, 'assessment');
  await page.locator('[name="chief_complaint"]').fill('');
  await page.locator('[name="assessment_notes"]').fill('Assessment still in progress');
  assert.equal(await page.locator('[name="chief_complaint"]').evaluate(el => el.validity.valueMissing), true);
  await tab(page, 'triage').click(); await active(page, 'triage'); await saved(page);
  assert.deepEqual(posts.map(p => p.path), ['/draft']);
  assert.deepEqual(posts[0].values, { chief_complaint: '', assessment_notes: 'Assessment still in progress', csrf_test_name: 'initial' });
  assert.equal(serverClinical.chief_complaint, '');
  assert.equal(await unloadBlocked(page), false);
});

test('whitespace-only complaint blocks completion even when native required validation passes', { timeout: 15000 }, async t => {
  const { page, posts } = await pageFor(t);
  await tab(page, 'outcome').click(); await active(page, 'outcome');
  await setValues(page, { chief_complaint: '   ' });
  assert.equal(await page.locator('[name="chief_complaint"]').evaluate(el => el.checkValidity()), true);
  await page.getByRole('button', { name: 'Complete encounter', exact: true }).click();
  await active(page, 'assessment'); await errorShown(page, 'Chief complaint is required');
  assert.equal(await page.locator('[name="chief_complaint"]').inputValue(), '   ');
  assert.equal(await page.locator('[name="chief_complaint"]').evaluate(el => el === document.activeElement), true);
  assert.equal(posts.length, 0, 'Whitespace complaint cannot complete or implicitly draft the encounter');
  assert.equal(page.url(), origin + '/encounter');
  assert.equal(await unloadBlocked(page), true);
});

test('dirty Bootstrap tab navigation waits for a draft patch; clean navigation does not write', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts } = state;
  await tab(page, 'assessment').click(); await active(page, 'assessment');
  assert.equal(posts.length, 0);
  await tab(page, 'triage').click();
  await page.locator('[name="bp_systolic"]').fill('122');
  const release = hold(state, t);
  await tab(page, 'assessment').click(); await posted(state, 1);
  await active(page, 'triage');
  assert.deepEqual(posts[0].values, { bp_systolic: '122', csrf_test_name: 'initial' });
  assert.equal(await page.locator('[data-encounter-stepper]').getAttribute('aria-busy'), 'true');
  release(); await active(page, 'assessment'); await saved(page);
  assert.equal(await unloadBlocked(page), false);
  await tab(page, 'outcome').click(); await active(page, 'outcome');
  assert.equal(posts.length, 1, 'Clean tab switches must not repeat the draft');
});

for (const failure of [
  { name: 'validation rejection', status: 422, body: { success: false, message: 'Draft rejected' }, message: 'Draft rejected' },
  { name: 'network failure', abort: true, message: 'Failed to fetch' },
  { name: 'unconfirmed non-JSON response', raw: '<html>Login required</html>', message: 'server did not confirm saving' }
]) {
  test(`failed draft (${failure.name}) blocks tab navigation, retains input, and allows retry`, { timeout: 15000 }, async t => {
    const { page, posts, replies } = await pageFor(t);
    replies.push(failure);
    await page.locator('[name="bp_systolic"]').fill('123');
    await tab(page, 'assessment').click(); await errorShown(page, failure.message);
    await active(page, 'triage');
    assert.equal(await page.locator('[name="bp_systolic"]').inputValue(), '123');
    assert.equal(await unloadBlocked(page), true);
    await page.locator('[data-save-draft]').click(); await saved(page);
    assert.equal(posts.length, 2);
    assert.deepEqual(posts[1].values, posts[0].values, 'Retry must retain the entire failed patch');
    await tab(page, 'assessment').click(); await active(page, 'assessment');
    assert.equal(posts.length, 2);
  });
}

test('clinical Save & Continue drafts before opening its next step, never its legacy action', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts } = state;
  await page.locator('[name="pulse_bpm"]').fill('74');
  const release = hold(state, t);
  await page.getByRole('button', { name: 'Save vitals', exact: true }).click(); await posted(state, 1);
  await active(page, 'triage'); release(); await active(page, 'assessment'); await saved(page);
  assert.deepEqual(posts.map(p => p.path), ['/draft']);
  assert.deepEqual(posts[0].values, { pulse_bpm: '74', csrf_test_name: 'initial' });
});

test('completion serializes every clinical value including previously saved and hidden ISO values, not medicine', { timeout: 15000 }, async t => {
  const { page, posts } = await pageFor(t);
  await setValues(page, clinical);
  await tab(page, 'outcome').click(); await active(page, 'outcome'); await saved(page);
  // Multipart serialization normalizes textarea line endings to CRLF.
  const wireValues = { ...clinical, assessment_notes: 'Alert & oriented\r\nNo fever', csrf_test_name: 'initial' };
  const { chief_complaint, ...draftValues } = wireValues;
  assert.deepEqual(posts[0].values, draftValues, 'Draft sends only changed values; complaint was already populated');
  await page.getByRole('button', { name: 'Complete encounter', exact: true }).click();
  await page.waitForURL(origin + '/completed');
  assert.deepEqual(posts.map(p => p.path), ['/draft', '/complete']);
  assert.deepEqual(posts[1].values, wireValues, 'Completion needs a full snapshot even when nothing remains dirty');
  assert.ok(posts.every(p => !p.navigation), 'Clinical writes are fetch, not native form navigation');
});

test('empty chief complaint blocks completion and reveals the assessment pane', { timeout: 15000 }, async t => {
  const { page, posts } = await pageFor(t);
  await tab(page, 'outcome').click(); await active(page, 'outcome');
  await setValues(page, { chief_complaint: '' });
  await page.getByRole('button', { name: 'Complete encounter', exact: true }).click();
  await active(page, 'assessment');
  assert.equal(await page.locator('[name="chief_complaint"]').evaluate(el => el.validity.valueMissing), true);
  assert.equal(posts.length, 0, 'Invalid completion must not save, complete, or dispense');
  assert.equal(await unloadBlocked(page), true);
});

test('CSRF refresh from response headers and failed JSON payload reaches subsequent body and headers', { timeout: 15000 }, async t => {
  const { page, posts, replies } = await pageFor(t);
  replies.push({ headers: { 'X-CSRF-TOKEN': 'header-token', 'X-CSRF-NAME': 'csrf_test_name' } });
  await page.locator('[name="bp_systolic"]').fill('124');
  await tab(page, 'assessment').click(); await active(page, 'assessment'); await saved(page);
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'header-token');
  replies.push({ status: 403, body: { success: false, message: 'Retry with refreshed token', csrf: { name: 'csrf_rotated', hash: 'json-token', header: 'X-ROTATED-CSRF' } } });
  await page.locator('[name="diagnosis"]').fill('Updated impression');
  await tab(page, 'outcome').click(); await errorShown(page, 'Retry with refreshed token');
  await active(page, 'assessment');
  assert.equal(posts[1].values.csrf_test_name, 'header-token');
  assert.equal(posts[1].headers['x-csrf-token'], 'header-token');
  assert.deepEqual(await page.locator('input[name="csrf_rotated"]').evaluateAll(els => els.map(el => el.value)), Array(4).fill('json-token'));
  await page.locator('[data-save-draft]').click(); await saved(page);
  assert.deepEqual(posts[2].values, { diagnosis: 'Updated impression', csrf_rotated: 'json-token' });
  assert.equal(posts[2].headers['x-rotated-csrf'], 'json-token');
  assert.equal(posts[2].headers['x-requested-with'], 'XMLHttpRequest');
  assert.equal(posts[2].headers.accept, 'application/json');
});

test('pending medicine blocks continue and completion; discard requires explicit consent and resets defaults', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts, dialogs } = state;
  await tab(page, 'dispense').click(); await active(page, 'dispense');
  await page.locator('[name="medicine_id"]').selectOption('7');
  await page.locator('[name="quantity"]').fill('3');
  await page.locator('[name="instructions"]').fill('After food');
  await page.locator('[data-continue-outcome]').click(); await errorShown(page, 'Medicine has not been dispensed');
  await active(page, 'dispense');
  await tab(page, 'outcome').click(); await active(page, 'outcome');
  await page.getByRole('button', { name: 'Complete encounter', exact: true }).click();
  await active(page, 'dispense'); await errorShown(page, 'Medicine has not been dispensed');
  assert.equal(posts.length, 0, 'Neither tab switches nor completion may dispense an unfinished entry');
  await page.locator('[data-discard-medicine]').click();
  assert.equal(dialogs.length, 1); assert.equal(dialogs[0].type, 'confirm');
  assert.match(dialogs[0].message, /Already dispensed medicines are not affected/);
  assert.equal(await page.locator('[name="quantity"]').inputValue(), '3', 'Cancel discard must keep medicine');
  assert.equal(await unloadBlocked(page), true);
  state.acceptDialog = true;
  await page.locator('[data-discard-medicine]').click(); await saved(page);
  assert.deepEqual(await page.locator('#step-dispense form').evaluate(form => ['medicine_id', 'quantity', 'instructions'].map(name => form.elements.namedItem(name).value)), ['', '1', '']);
  assert.equal(posts.length, 0, 'Discard is local, never an inventory write');
  assert.equal(await unloadBlocked(page), false);
  await page.locator('[data-continue-outcome]').click(); await active(page, 'outcome');
  await page.getByRole('button', { name: 'Complete encounter', exact: true }).click(); await page.waitForURL(origin + '/completed');
  assert.deepEqual(posts.map(p => p.path), ['/complete']);
});

test('explicit dispense waits for the dirty clinical draft, then sends only medicine and refreshed CSRF', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts } = state;
  await tab(page, 'dispense').click(); await active(page, 'dispense');
  await setValues(page, { assessment_notes: 'Save before stock deduction', medicine_id: '7', quantity: '2', instructions: 'After food' });
  const release = hold(state, t, { body: { success: true, csrf: { name: 'csrf_test_name', hash: 'dispense-token' } } });
  // The stepper's submit listener runs after the shared confirmation controller grants consent.
  await page.getByRole('button', { name: 'Dispense medicine', exact: true }).click(); await posted(state, 1);
  assert.deepEqual(posts.map(p => p.path), ['/draft'], 'Inventory must not be touched until the draft succeeds');
  assert.deepEqual(posts[0].values, { assessment_notes: 'Save before stock deduction', csrf_test_name: 'initial' });
  release(); await page.waitForURL(origin + '/dispensed');
  assert.deepEqual(posts.map(p => p.path), ['/draft', '/dispense']);
  assert.deepEqual(posts[1].values, { csrf_test_name: 'dispense-token', idempotency_key: 'fixture-dispense-key', medicine_id: '7', quantity: '2', instructions: 'After food' });
  assert.equal(posts[1].headers['x-csrf-token'], 'dispense-token');
});

test('failed pre-dispense draft prevents stock deduction and preserves both clinical and medicine entries', { timeout: 15000 }, async t => {
  const { page, posts, replies } = await pageFor(t);
  await tab(page, 'dispense').click(); await active(page, 'dispense');
  await setValues(page, { diagnosis: 'Keep diagnosis', medicine_id: '7', quantity: '4', instructions: 'Keep instructions' });
  replies.push({ status: 500, body: { success: false, message: 'Draft unavailable' } });
  await page.getByRole('button', { name: 'Dispense medicine', exact: true }).click(); await errorShown(page, 'Draft unavailable');
  assert.deepEqual(posts.map(p => p.path), ['/draft']);
  assert.equal(await page.locator('[name="diagnosis"]').inputValue(), 'Keep diagnosis');
  assert.equal(await page.locator('[name="quantity"]').inputValue(), '4');
  assert.equal(await page.locator('[name="instructions"]').inputValue(), 'Keep instructions');
  await page.locator('[data-save-draft]').click(); await saved(page);
  assert.deepEqual(posts.map(p => p.path), ['/draft', '/draft'], 'Retry draft alone never dispenses medicine');
  assert.equal(await unloadBlocked(page), true, 'Pending medicine remains guarded after clinical save');
});

test('leave link blocks on failed save, then saves successfully before navigating', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts, replies } = state;
  await page.locator('[name="bp_systolic"]').fill('125');
  replies.push({ status: 500, body: { success: false, message: 'Stay on encounter' } });
  await page.locator('#leave span').click(); await errorShown(page, 'Stay on encounter');
  assert.equal(page.url(), origin + '/encounter');
  assert.equal(await page.locator('[name="bp_systolic"]').inputValue(), '125');
  const release = hold(state, t);
  await page.locator('#leave span').click(); await posted(state, 2);
  assert.equal(page.url(), origin + '/encounter');
  assert.equal(await unloadBlocked(page), true);
  release(); await page.waitForURL(origin + '/left');
  assert.deepEqual(posts.map(p => p.path), ['/draft', '/draft']);
  assert.deepEqual(posts[1].values, { bp_systolic: '125', csrf_test_name: 'initial' });
});

test('before-navigate allows clean state, blocks pending medicine, and saves dirty state while busy guards reentry', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts } = state;
  assert.equal(await beforeNavigate(page), false);
  assert.equal(await unloadBlocked(page), false);
  await setValues(page, { instructions: 'Unfinished medicine' });
  assert.equal(await beforeNavigate(page), true);
  await active(page, 'dispense'); await errorShown(page, 'Medicine has not been dispensed');
  assert.equal(posts.length, 0); assert.equal(page.url(), origin + '/encounter');
  assert.equal(await unloadBlocked(page), true);
  state.acceptDialog = true;
  await page.locator('[data-discard-medicine]').click(); await saved(page);
  await setValues(page, { diagnosis: 'Save on leave' });
  const release = hold(state, t);
  assert.equal(await beforeNavigate(page), true); await posted(state, 1);
  assert.equal(await beforeNavigate(page), true, 'A second navigation is prevented during the pending draft');
  // Bootstrap programmatic show still emits the cancelable event while the root is inert.
  await page.evaluate(() => bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#step-outcome"]')).show());
  await active(page, 'dispense');
  assert.equal(posts.length, 1, 'Busy navigation cannot start another draft');
  assert.equal(await unloadBlocked(page), true);
  release(); await page.waitForURL(origin + '/left');
});

test('native beforeunload prompts for dirty input, cancellation retains it, and clean state stops prompting', { timeout: 15000 }, async t => {
  const { page, posts, dialogs } = await pageFor(t);
  await page.locator('[name="bp_systolic"]').fill('126');
  const navigation = page.goto(origin + '/left').catch(error => {
    assert.match(error.message, /ERR_ABORTED|interrupted/);
  });
  await navigation;
  await assertEventually(() => dialogs.length === 1, 'Dirty browser navigation should prompt');
  assert.equal(dialogs[0].type, 'beforeunload');
  assert.equal(page.url(), origin + '/encounter');
  assert.equal(await page.locator('[name="bp_systolic"]').inputValue(), '126');
  assert.equal(posts.length, 0, 'Native unload must not attempt an unreliable asynchronous save');
  await tab(page, 'assessment').click(); await active(page, 'assessment'); await saved(page);
  await page.goto(origin + '/left');
  assert.equal(dialogs.length, 1, 'Saved browser navigation must not prompt again');
});

test('leave guard ignores hash, modal, new-window, download and modified links', { timeout: 15000 }, async t => {
  const { page, posts } = await pageFor(t);
  await page.locator('[name="bp_systolic"]').fill('127');
  const intercepted = await page.evaluate(() => {
    const checks = [['#hash', {}], ['#modal', {}], ['#chart', {}], ['#leave', { ctrlKey: true }], ['#leave', { metaKey: true }], ['#leave', { shiftKey: true }], ['#leave', { altKey: true }]];
    const download = document.createElement('a');
    download.id = 'download'; download.href = '/left'; download.setAttribute('download', 'fixture');
    document.body.append(download);
    checks.push(['#download', {}]);
    // Observe capture-phase prevention, then stop actual default navigation/popups.
    return checks.map(([selector, options]) => {
      const link = document.querySelector(selector);
      let guarded;
      link.addEventListener('click', event => { guarded = event.defaultPrevented; event.preventDefault(); }, { once: true });
      link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ...options }));
      return guarded;
    });
  });
  assert.deepEqual(intercepted, Array(8).fill(false));
  assert.equal(posts.length, 0);
  assert.equal(await unloadBlocked(page), true);
});

test('failed dispensing retains the medicine entry and never retries inventory through draft retry', { timeout: 15000 }, async t => {
  const { page, posts, replies } = await pageFor(t);
  await tab(page, 'dispense').click(); await active(page, 'dispense');
  await setValues(page, { medicine_id: '7', quantity: '5', instructions: 'Retain this entry' });
  replies.push({ status: 409, body: { success: false, message: 'Stock changed; review quantity' } });
  await page.getByRole('button', { name: 'Dispense medicine', exact: true }).click();
  await errorShown(page, 'Stock changed; review quantity');
  assert.deepEqual(posts.map(p => p.path), ['/dispense'], 'Clean clinical data needs no preliminary draft');
  assert.equal(await page.locator('[name="quantity"]').inputValue(), '5');
  assert.equal(await page.locator('[name="instructions"]').inputValue(), 'Retain this entry');
  assert.equal(page.url(), origin + '/encounter');
  await page.locator('[data-save-draft]').click();
  assert.deepEqual(posts.map(p => p.path), ['/dispense'], 'Draft retry must never repeat stock deduction');
  assert.equal(await unloadBlocked(page), true);
});

test('completion includes current unsaved values and busy state prevents duplicate completion and leaving', { timeout: 15000 }, async t => {
  const state = await pageFor(t), { page, posts } = state;
  await tab(page, 'outcome').click(); await active(page, 'outcome');
  await setValues(page, clinical);
  const release = hold(state, t);
  await page.getByRole('button', { name: 'Complete encounter', exact: true }).click(); await posted(state, 1);
  assert.deepEqual(posts.map(p => p.path), ['/complete']);
  assert.deepEqual(posts[0].values, { ...clinical, assessment_notes: 'Alert & oriented\r\nNo fever', csrf_test_name: 'initial' });
  await page.evaluate(() => document.querySelector('[data-complete-encounter]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  assert.equal(await beforeNavigate(page), true);
  assert.equal(await unloadBlocked(page), true);
  assert.equal(posts.length, 1, 'Busy completion cannot be submitted twice');
  assert.equal(page.url(), origin + '/encounter');
  release(); await page.waitForURL(origin + '/completed');
});

test('pending medicine also prevents ordinary leave links without saving or dispensing', { timeout: 15000 }, async t => {
  const { page, posts } = await pageFor(t);
  await setValues(page, { quantity: '2' });
  await page.locator('#leave span').click(); await active(page, 'dispense');
  await errorShown(page, 'Medicine has not been dispensed');
  assert.equal(page.url(), origin + '/encounter');
  assert.equal(posts.length, 0);
  assert.equal(await page.locator('[name="quantity"]').inputValue(), '2');
  assert.equal(await unloadBlocked(page), true);
});

test('Alpine destroy removes document and beforeunload guards', { timeout: 15000 }, async t => {
  const { page, posts } = await pageFor(t);
  await page.locator('[name="bp_systolic"]').fill('128');
  assert.equal(await unloadBlocked(page), true);
  await page.evaluate(() => {
    const el = document.querySelector('[data-encounter-stepper]');
    Alpine.destroyTree(el);
    el.remove();
  });
  assert.equal(await unloadBlocked(page), false);
  assert.equal(await beforeNavigate(page), false);
  assert.equal(posts.length, 0);
});
