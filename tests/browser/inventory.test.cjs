'use strict';

// Real CI templates and local production assets; HTTP writes are response fixtures.
// This suite does not run controllers, authenticate accounts, query MIS/MySQL or
// prove stock persistence. Capability flags deliberately exercise presentation only.
const { describe, test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const base = '/clinic/inventory';
const encounterUrl = '/clinic/encounters/21';
const literal = '\\"><img src=x onerror="window.inventoryXss=1"><script>window.inventoryXss=1</script>&';
const forms = [
  { kind: 'catalog-create', parent: base, url: base + '/create', action: base + '/store', title: 'Add Medicine' },
  { kind: 'catalog-edit', parent: base + '/11', url: base + '/11/edit', action: base + '/11/update', title: 'Edit Medicine' },
  { kind: 'receive', parent: base + '/11', url: base + '/11/receive', title: 'Receive Medicine' },
  { kind: 'adjust', parent: base + '/11', url: base + '/batches/31/adjust', title: 'Remove Stock' },
  { kind: 'status', parent: base + '/11', url: base + '/batches/31/status', title: 'Change Batch Status' },
  { kind: 'reverse', parent: base + '/11', url: base + '/operations/41/reverse', title: 'Correct Dispensation' }
];
let browser, server, origin, failSave = false, saveGate;
const cache = new Map(), posts = [];
function gate() {
  let release;
  const promise = new Promise(resolve => { release = resolve; });
  return { promise, release };
}
function render(kind, requestPath, fragment) {
  const key = JSON.stringify([kind, requestPath, fragment]);
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', ['tests/_support/render-inventory-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: requestPath, kind, fragment }), encoding: 'utf8', timeout: 6000
  });
  assert.equal(result.status, 0, `${kind} ${requestPath}: ${result.stderr || result.stdout}`);
  assert.match(result.stdout, /<div/);
  const failure = result.stdout.match(/"message"\s*:\s*("(?:\\.|[^"\\])*")/);
    assert.ok(!/Fatal error|Warning:|Parse error|"code"\s*:\s*500/.test(result.stdout),
      `${kind} ${requestPath}: ${failure ? JSON.parse(failure[1]) : 'PHP view render failed'}`);
  cache.set(key, result.stdout);
  return result.stdout;
}
function fields(body) {
  const result = {};
  for (const match of body.matchAll(/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([\s\S]*?)(?=\r\n--)/g)) result[match[1]] = match[2];
  return result;
}
async function pageFor(t, route, theme = 'light') {
  failSave = false;
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  // Keep the suite offline even if the shell gains new external assets.
  await context.route('**/*', route => route.request().url().startsWith(origin + '/')
    ? route.continue() : route.fulfill({ status: 200, body: '' }));
  const page = await context.newPage();
  page.setDefaultTimeout(3500);
  page.setDefaultNavigationTimeout(15000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'Production script errors'); });
  const response = await page.goto(origin + route);
  assert.equal(response.status(), 200, `${route}: ${response.ok() ? '' : await response.text()}`);
  await page.waitForFunction(() => document.readyState === 'complete' && window.bootstrap && window.Alpine && window.BalaySelect && window.BalayDatepicker);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return page;
}
async function readyModal(page, item) {
  await page.waitForFunction(title => {
    const modal = document.querySelector('#appFormModal');
    return modal?.querySelector('form') && modal.querySelector('#appFormModalTitle').textContent.trim() === title &&
      modal.getAttribute('aria-modal') === 'true' && modal.contains(document.activeElement) &&
      bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  }, item.title);
}
async function open(page, item) {
  await page.locator(`a[data-app-modal-open][href="${origin + item.url}"]`).first().click();
  await readyModal(page, item);
}
async function close(page, selector) {
  await page.locator('#appFormModal ' + selector).click();
  await page.waitForFunction(() => !document.querySelector('#appFormModal'));
}
async function choose(page, id, name) {
  const wrap = page.locator(id).locator('..');
  assert.equal(await wrap.locator('.bd-select-toggle').count(), 1, id + ' must be upgraded once');
  await wrap.locator('.bd-select-toggle').click();
  await wrap.getByRole('option', { name, exact: true }).click();
}
async function values(page) {
  return page.locator('#appFormModal form').evaluate(form => Object.fromEntries(new FormData(form)));
}
async function fill(page, item) {
  if (item.kind.startsWith('catalog')) {
    for (const [name, value] of Object.entries({ name: literal, generic_name: 'Fixture Generic', category: literal,
      dosage_form: 'tablet', strength: '500 mg', unit_of_measure: 'tablet', minimum_stock_level: '12' })) {
      await page.locator(`#appFormModal [name="${name}"]`).fill(value);
    }
    await choose(page, '#inventoryActive', 'Archived');
    if (item.kind === 'catalog-edit') assert.equal(await page.locator('[name="version"]').inputValue(), '3');
  } else if (item.kind === 'receive') {
    await page.locator('#inventoryLot').fill('LOT-31');
    const expiry = page.locator('#inventoryExpiry');
    assert.equal(await expiry.getAttribute('data-target'), '#inventoryExpiryIso');
    assert.equal(await expiry.getAttribute('name'), null, 'Only the ISO mirror is submitted');
    assert.equal(await page.locator('#inventoryExpiryIso').getAttribute('name'), 'expiration_date');
    await expiry.click();
    await page.locator('#appFormModal .dp-popup:not([hidden])').waitFor();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Enter');
    assert.match(await expiry.inputValue(), /^\d{2}\/\d{2}\/\d{4}$/);
    assert.match(await page.locator('#inventoryExpiryIso').inputValue(), /^\d{4}-\d{2}-\d{2}$/);
    await page.locator('#inventoryReceiptQty').fill('2');
    await page.locator('#inventorySupplier').fill(literal);
    await page.locator('#inventoryReference').fill(literal);
  } else if (item.kind === 'adjust') {
    assert.equal(await page.locator('#inventoryRemovalQty').getAttribute('max'), '10');
    await page.locator('#inventoryRemovalQty').fill('2');
    await choose(page, '#inventoryRemovalReason', 'Count correction (remove only)');
    await page.locator('#inventoryRemovalNote').fill(literal);
  } else if (item.kind === 'status') {
    await choose(page, '#inventoryBatchStatus', 'Active — release from quarantine');
    await page.locator('#inventoryStatusReason').fill(literal);
  } else {
    assert.equal(await page.locator('#inventoryRecordOnly').isChecked(), true, 'Safe default');
    const label = await page.locator('label[for="inventoryRestoreUnissued"]').innerText();
    assert.match(label, /ALL unissued units/);
    assert.match(label, /never issued.*clinic custody/);
    assert.match(await page.locator('#appFormModal').innerText(), /Patient returns must never be restocked/);
    await page.locator('#inventoryRestoreUnissued').check();
    await page.locator('#inventoryCorrectionReason').fill(literal);
    assert.equal(await page.locator('#inventoryCorrectionReview').getAttribute('required'), '');
    assert.match(await page.locator('label[for="inventoryCorrectionReview"]').innerText(), /none are patient returns/);
    const count = posts.length;
    await page.locator('#appFormModal button[type="submit"]').click();
    assert.equal(await page.locator('#inventoryCorrectionReview').evaluate(input => input.validity.valueMissing), true);
    assert.equal(posts.length, count, 'Unreviewed corrections must not submit');
    await page.locator('#inventoryCorrectionReview').check();
  }
  for (const input of await page.locator('#appFormModal input[type="number"]').all()) {
    assert.equal(await input.getAttribute('step'), '1');
    await input.fill('1.5');
    assert.equal(await input.evaluate(input => input.validity.stepMismatch), true);
    await input.fill(item.kind.startsWith('catalog') ? '12' : '2');
  }
  assert.equal(await page.locator('#appFormModal form').evaluate(form => form.checkValidity()), true);
}
async function assertSafe(page) {
  assert.equal(await page.evaluate(() => window.inventoryXss), undefined);
  assert.equal(await page.locator('#main img[onerror], #main script:not([src]), #appFormModal img[onerror], #appFormModal script').count(), 0);
}

describe('Inventory presentation fixtures (no application or MySQL)', { timeout: 110_000 }, () => {
  before(async () => {
    let executablePath = chromium.executablePath();
    if (!fs.existsSync(executablePath)) {
      const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
      executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
        .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
        .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
      assert.ok(executablePath, 'Install Chromium for the existing browser-test tools');
    }
    server = http.createServer(async (req, res) => {
      try {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname === '/realtime/ticket') {
          res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ enabled: false }));
          return;
        }
        if (req.method === 'POST') {
          const chunks = [];
          for await (const chunk of req) chunks.push(chunk);
          const body = Buffer.concat(chunks).toString('utf8');
          const submitted = req.headers['content-type']?.startsWith('application/x-www-form-urlencoded')
            ? Object.fromEntries(new URLSearchParams(body)) : fields(body);
          posts.push({ path: url.pathname, fields: submitted,
            ajax: req.headers['x-requested-with'], accept: req.headers.accept });
          if (url.pathname === encounterUrl + '/dispense') {
            // Native-form response only: no stock mutation or replay semantics.
            res.writeHead(303, { Location: origin + encounterUrl }).end();
            return;
          }
          if (saveGate) await saveGate.promise;
          if (res.destroyed) return;
          const item = forms.find(item => (item.action || item.url) === url.pathname);
          res.writeHead(failSave ? 422 : 200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ success: !failSave, message: failSave ? 'Fixture validation rejected the form. ' + literal : 'Saved.',
            redirect: origin + item.parent, csrf: { name: 'csrf_test_name', hash: 'inventory-refreshed-token' } }));
          return;
        }
        if (url.pathname === '/__barrier') { res.writeHead(204).end(); return; }
        const item = forms.find(item => item.url === url.pathname);
        // Collision paths intentionally render the real inventory list at that URI,
        // so sidebar matching is exercised without fabricating a navigation shell.
        const kind = item?.kind || (url.pathname === base + '/11' ? 'show' :
          [base, base + '-archive', base + 'ish'].includes(url.pathname) ? 'index' :
                      url.pathname === encounterUrl ? 'stepper' :
            url.pathname === '/clinic/queue/board' ? 'queue-clinic' :
            url.pathname === '/guidance/queue/board' ? 'queue-guidance' : null);
        if (kind) {
          const html = render(kind, url.pathname + url.search, url.searchParams.has('fragment'));
          res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(html);
          return;
        }
        const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + url.pathname);
        if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
        fs.readFile(file, (error, content) => {
          if (error) { res.writeHead(404).end(); return; }
          res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' }).end(content);
        });
      } catch (error) {
        res.writeHead(500, { 'Content-Type': 'text/plain' }).end(error.stack);
      }
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    origin = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ headless: true, executablePath, timeout: 15_000 });
  }, { timeout: 20_000 });
  after(async () => {
    saveGate?.release();
    try { await browser?.close(); }
    finally {
      if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
    }
  }, { timeout: 10_000 });

  for (const theme of ['light', 'dark']) {
    for (const item of forms) {
      test(`${theme} ${item.kind}: static modal, X/Cancel, retained 422 retry and one in-flight write`, { timeout: 10_000 }, async t => {
        const page = await pageFor(t, item.parent, theme);
        await page.evaluate(() => { window.inventoryMain = document.querySelector('#main'); });
        await open(page, item);
        assert.equal(page.url(), origin + item.parent);
        assert.deepEqual(await page.locator('#appFormModal').evaluate(modal => ({
          backdrop: bootstrap.Modal.getInstance(modal)._config.backdrop,
          keyboard: bootstrap.Modal.getInstance(modal)._config.keyboard,
          forms: modal.querySelectorAll('form').length,
          preserved: document.querySelector('#main') === window.inventoryMain
        })), { backdrop: 'static', keyboard: false, forms: 1, preserved: true });
        await page.keyboard.press('Escape');
        await page.mouse.click(5, 5);
        assert.equal(await page.locator('#appFormModal').isVisible(), true);
        for (let i = 0; i < 5; i++) {
          await page.keyboard.press('Tab');
          assert.equal(await page.locator('#appFormModal').evaluate(modal => modal.contains(document.activeElement)), true);
        }
        await close(page, 'button[data-app-modal-close]');
        await open(page, item);
        await close(page, 'a[data-app-modal-close]');
        assert.equal(page.url(), origin + item.parent);
        assert.equal(await page.evaluate(() => document.querySelector('#main') === window.inventoryMain), true);
        await open(page, item);
        assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + (item.action || item.url));
        assert.equal(await page.locator('#appFormModal [name="quantity_remaining"], #appFormModal [name="remaining_quantity"], #appFormModal [name="total_stock"]').count(), 0);
        await fill(page, item);
        const original = await values(page), count = posts.length;
        assert.ok(original.idempotency_key);
        assert.ok(original.csrf_test_name);
        failSave = true;
        const held = saveGate = gate();
        t.after(() => { held.release(); saveGate = null; });
        const request = page.waitForRequest(req => req.method() === 'POST');
        await page.locator('#appFormModal button[type="submit"]').click();
        await request;
        assert.equal(await page.locator('#appFormModal form').getAttribute('aria-busy'), 'true');
        assert.equal(await page.locator('#appFormModal button[type="submit"]').isDisabled(), true);
        await page.locator('#appFormModal form').evaluate(form => {
          form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
          form.requestSubmit();
        });
        await page.locator('#appFormModal button[data-app-modal-close]').click();
        await page.locator('#appFormModal a[data-app-modal-close]').click();
        await page.keyboard.press('Escape');
        await page.mouse.click(5, 5);
        await page.evaluate(() => fetch('/__barrier'));
        assert.equal(posts.length, count + 1, 'Duplicate in-flight submissions must be blocked');
        assert.equal(await page.locator('#appFormModal').isVisible(), true);
        held.release();
        saveGate = null;
        await page.locator('[data-modal-error]:not(.d-none)').waitFor();
        await page.waitForFunction(() => !document.querySelector('#appFormModal form').hasAttribute('aria-busy'));
        assert.deepEqual(posts.at(-1).fields, original);
        assert.equal(posts.at(-1).path, item.action || item.url);
        assert.equal(posts.at(-1).ajax, 'XMLHttpRequest');
        assert.match(posts.at(-1).accept, /application\/json/);
        assert.deepEqual(await values(page), { ...original, csrf_test_name: 'inventory-refreshed-token' });
        assert.match(await page.locator('[data-modal-error]').innerText(), /<img src=x/);
        await assertSafe(page);
        failSave = false;
        // The overlay's URL already equals the redirect: waitForURL alone can
        // return before the retry has even reached the fixture server.
        const navigated = page.waitForEvent('framenavigated', frame => frame === page.mainFrame());
        await page.locator('#appFormModal button[type="submit"]').click();
        await navigated;
        await page.waitForLoadState('load');
        assert.equal(page.url(), origin + item.parent);
        assert.equal(posts.length, count + 2);
        assert.deepEqual(posts.at(-1).fields, { ...original, csrf_test_name: 'inventory-refreshed-token' });
      });
    }

    test(`${theme} full-route forms use the actual modal shell and return on Cancel`, { timeout: 12_000 }, async t => {
      const page = await pageFor(t, forms[0].url, theme);
      for (const item of forms) {
        if (page.url() !== origin + item.url) await page.goto(origin + item.url);
        await readyModal(page, item);
        assert.equal(await page.locator('#appFormModal').getAttribute('data-modal-direct'), 'true');
        assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + (item.action || item.url));
        await page.locator('#appFormModal a[data-app-modal-close]').click();
        await page.waitForURL(origin + item.parent);
      }
    });

    test(`${theme} manager/staff/read-only affordances, stock alerts and immutable balances`, { timeout: 10_000 }, async t => {
      const page = await pageFor(t, base, theme);
      for (const profile of ['manager', 'staff', 'reader']) {
        for (const route of [base, base + '/11']) {
          await page.goto(origin + route + '?profile=' + profile);
          await page.waitForFunction(() => window.BalaySelect && document.readyState === 'complete');
          const hrefs = await page.locator('a[data-app-modal-open]').evaluateAll(nodes => nodes.map(node => new URL(node.href).pathname));
          assert.equal(hrefs.includes(base + '/11/receive'), profile !== 'reader', `${profile} receive on ${route}`);
          assert.equal(hrefs.includes(base + '/11/edit'), profile === 'manager');
          if (route === base) {
            assert.equal(hrefs.includes(base + '/create'), profile === 'manager');
            assert.match(await page.locator('#main').innerText(), /Expired: 2/);
            assert.match(await page.locator('#main').innerText(), /Quarantined: 3/);
            assert.equal(await page.locator('#inventoryFilter').locator('..').locator('.bd-select-toggle').count(), 1);
            assert.equal(await page.locator(`a[href="${origin + base}/11"]`).count(), 2);
          } else {
            for (const suffix of ['/batches/31/adjust', '/batches/31/status', '/operations/41/reverse']) {
              assert.equal(hrefs.includes(base + suffix), profile === 'manager', `${profile} ${suffix}`);
            }
            assert.match(await page.locator('#inventory-batch-31').innerText(), /10\s+10/);
            assert.match(await page.locator('#inventory-batch-32').innerText(), /Expired/);
            assert.match(await page.locator('#inventory-batch-33').innerText(), /Quarantined/);
          }
          assert.equal(await page.locator('#main input[name="quantity_remaining"], #main input[name="remaining_quantity"], #main input[name="total_stock"]').count(), 0);
        }
      }
      await page.goto(origin + base + '?variant=archived');
      assert.equal(await page.locator(`a[data-app-modal-open][href="${origin + base}/11/receive"]`).count(), 0);
    });

    test(`${theme} stepper: old input, usable stock units and custom confirmation before one native POST`, { timeout: 10_000 }, async t => {
      const page = await pageFor(t, encounterUrl + '?variant=retained', theme);
      await page.locator('[data-bs-target="#step-dispense"]').click();
      const form = page.locator('#step-dispense form');
      await form.waitFor({ state: 'visible' });
      assert.equal(await form.count(), 1);
      assert.equal(await form.getAttribute('action'), origin + encounterUrl + '/dispense');
      assert.equal(await form.getAttribute('method'), 'POST');
      assert.equal(await form.getAttribute('data-modal-submit'), null);
      assert.equal(await page.locator('#appFormModal').count(), 0);
      assert.match(await form.getAttribute('data-confirm'), /Usable stock will be deducted/);
      assert.match(await form.getAttribute('data-confirm'), /Patient returns must not be restocked/);
      assert.equal(await page.locator('#medicine_id').inputValue(), '12');
      assert.equal(await page.locator('#quantity').inputValue(), '3');
      assert.equal(await page.locator('#instructions').inputValue(), literal);
      const options = await page.locator('#medicine_id option').evaluateAll(nodes => nodes.map(node => ({
        value: node.value, text: node.textContent.replace(/\s+/g, ' ').trim()
      })));
      // This verifies presentation of an already-filtered model result, not SQL
      // exclusion of expired/quarantined/archived stock or FEFO allocation.
      assert.deepEqual(options.map(option => option.value), ['', '11', '12']);
      assert.match(options[1].text, /Usable: 10 tablet$/);
      assert.match(options[2].text, /Usable: 4 bottle$/);
      assert.doesNotMatch(options.map(option => option.text).join(' '), /Usable: (?:15|9)\b|EXPIRED|QUARANTINE|Archived/);
      assert.match(await form.innerText(), /Whole stock units.*active, unexpired batches/);
      await choose(page, '#medicine_id', /Fixture Medicine/);
      await page.locator('#quantity').fill('1.5');
      assert.equal(await page.locator('#quantity').evaluate(input => input.validity.stepMismatch), true);
      await page.locator('#quantity').fill('2');
      const original = await form.evaluate(form => Object.fromEntries(new FormData(form)));
      assert.deepEqual(original, { csrf_test_name: original.csrf_test_name,
        idempotency_key: 'inventory-fixture-dispense-key', medicine_id: '11', quantity: '2', instructions: literal });
      assert.ok(original.csrf_test_name);
      assert.match(original.idempotency_key, /^[A-Za-z0-9._:-]{1,128}$/);
      assert.equal(await page.evaluate(() => window.inventoryXss), undefined);
      assert.equal(await page.locator('#step-dispense img[onerror], #step-dispense script').count(), 0);
      const count = posts.length;
      await form.locator('button[type="submit"]').click();
      const dialog = page.locator('#synapseConfirmModal');
      await dialog.waitFor({ state: 'visible' });
      assert.equal(await dialog.getAttribute('data-synapse-confirm-ready'), '');
      assert.equal((await dialog.locator('[data-confirm-title-text]').innerText()).trim(), 'Confirm dispensing');
      assert.equal((await dialog.locator('[data-confirm-accept]').innerText()).trim(), 'Dispense medicine');
      assert.equal(posts.length, count, 'Opening the confirmation must not POST');
      await dialog.locator('[data-confirm-cancel]').click();
      await dialog.waitFor({ state: 'hidden' });
      await page.waitForFunction(() => !document.querySelector('.modal-backdrop'));
      await page.evaluate(() => fetch('/__barrier'));
      assert.equal(posts.length, count, 'Cancelling confirmation must not POST');
      assert.deepEqual(await form.evaluate(form => Object.fromEntries(new FormData(form))), original);
      assert.equal(page.url(), origin + encounterUrl + '?variant=retained');
      await form.locator('button[type="submit"]').click();
      await dialog.waitFor({ state: 'visible' });
      const submitted = page.waitForRequest(req => req.method() === 'POST' && req.url() === origin + encounterUrl + '/dispense');
      await dialog.locator('[data-confirm-accept]').click();
      const request = await submitted;
      await page.waitForURL(origin + encounterUrl);
      await page.waitForLoadState('load');
      assert.equal(request.isNavigationRequest(), true, 'Approval must preserve native form submission');
      assert.equal(posts.length, count + 1);
      assert.deepEqual(posts.at(-1).fields, original);
      assert.equal(posts.at(-1).path, encounterUrl + '/dispense');
      assert.equal(posts.at(-1).ajax, undefined);
      // One approval is observed here. Duplicate-write protection belongs to the
      // backend ledger; a fixture response cannot prove it.
    });

    test(`${theme} stepper: closed and read-only encounters have no dispensing form`, { timeout: 8000 }, async t => {
      const page = await pageFor(t, encounterUrl + '?variant=closed', theme);
      for (const query of ['?variant=closed', '?profile=reader', '?profile=reader&variant=closed']) {
        await page.goto(origin + encounterUrl + query);
        await page.locator('[data-bs-target="#step-dispense"]').click();
        await page.locator('#step-dispense .alert').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#step-dispense form, #step-dispense [name="idempotency_key"]').count(), 0, query);
        assert.match(await page.locator('#step-dispense').innerText(), /Dispensing is unavailable/);
        assert.equal(await page.locator('#step-dispense tbody tr').count(), 3, 'History remains readable');
      }
    });

    test(`${theme} stepper history: original snapshots, full expiry and correction/reconstruction badges`, { timeout: 8000 }, async t => {
      const page = await pageFor(t, encounterUrl + '?variant=closed', theme);
      await page.locator('[data-bs-target="#step-dispense"]').click();
      const rows = page.locator('#step-dispense tbody tr');
      assert.equal(await rows.count(), 3);
      assert.match(await rows.nth(0).innerText(), /Original medicine snapshot.*500 mg/s);
      assert.match(await rows.nth(0).innerText(), /ORIGINAL-LOT-31.*Exp: Jul 19, 2030/s);
      assert.match(await rows.nth(0).innerText(), /2 tablet/);
      assert.match(await rows.nth(0).innerText(), /Corrected: unissued units restored/);
      assert.match(await rows.nth(1).innerText(), /Corrected: stock unchanged/);
      assert.match(await rows.nth(2).innerText(), /Exp: Mar 9, 2020/);
      assert.match(await rows.nth(2).innerText(), /Legacy description reconstructed at inventory cutover/);
      assert.equal(await rows.nth(0).locator('.badge').count(), 2);
      assert.doesNotMatch(await rows.nth(0).innerText(), /reconstructed/);
      assert.doesNotMatch(await rows.nth(2).innerText(), /Corrected:/);
      assert.match(await rows.nth(0).innerText(), /Original directions/);
    });

    test(`${theme} sidebar inventory route is unique and segment-safe`, { timeout: 10_000 }, async t => {
      const page = await pageFor(t, base, theme);
      for (const route of [base, ...forms.map(item => item.url), base + '/11', base + '-archive', base + 'ish']) {
        await page.goto(origin + route);
        await page.waitForFunction(() => document.readyState === 'complete');
        const links = page.locator(`.sidebar-nav .nav-link[href="${origin + base}"]`);
        assert.equal(await links.count(), 1, 'Exactly one inventory sidebar link');
        const active = await page.locator('.sidebar-nav .nav-link.active').evaluateAll(nodes => nodes.map(node => new URL(node.href).pathname));
        assert.deepEqual(active, route.startsWith(base + '-') || route.endsWith('inventoryish') ? [] : [base], route);
      }
    });
  }

  test('Queue Open Chart: both departments use CSRF POST forms', { timeout: 8000 }, async t => {
    const page = await pageFor(t, base);
    const count = posts.length;
    for (const unit of ['clinic', 'guidance']) {
      await page.goto(origin + '/' + unit + '/queue/board');
      assert.match(await page.locator('body').innerText(), /Student, Ada/);
      if (unit === 'clinic') {
        const form = page.locator(`form[action="${origin}/clinic/encounters/start-for-ticket/71"]`);
        assert.equal(await form.count(), 1);
        assert.equal(await form.getAttribute('method'), 'POST');
        assert.ok(await form.locator('input[type="hidden"][name="csrf_test_name"]').inputValue());
        assert.equal((await form.getByRole('button', { name: 'Open Chart' }).innerText()).trim(), 'Open Chart');
        assert.equal(await page.getByRole('link', { name: 'Open Chart' }).count(), 0);
      } else {
        const form = page.locator(`form[action="${origin}/guidance/cases/start-for-ticket/71"]`);
        assert.equal(await form.getAttribute('method'), 'POST');
        assert.ok(await form.locator('input[type="hidden"][name="csrf_test_name"]').inputValue());
        assert.equal(await form.getByRole('button', { name: 'Open Chart' }).count(), 1);
        assert.equal(await page.getByRole('link', { name: 'Open Chart' }).count(), 0);
      }
    }
    assert.equal(posts.length, count, 'Rendering queue links must not start an encounter');
  });

  test('Malicious stored catalog, supplier, reason and old input remain literal text', { timeout: 12_000 }, async t => {
    const page = await pageFor(t, base + '?variant=malicious');
    assert.match(await page.locator('#main').innerText(), /<script>window.inventoryXss=1<\/script>/);
    await assertSafe(page);
    await page.goto(origin + base + '/11?variant=malicious');
    assert.match(await page.locator('#inventory-operation-42').innerText(), /Supplier:.*<img src=x/);
    assert.match(await page.locator('#inventory-operation-41').innerText(), /<script>/);
    assert.match(await page.locator('#inventory-movement-51').innerText(), /<script>/);
    await assertSafe(page);
    for (const item of forms) {
      await page.goto(origin + item.url + '?variant=malicious');
      await readyModal(page, item);
      for (const field of await page.locator('#appFormModal [name="name"], #appFormModal [name="supplier"], #appFormModal textarea').all()) {
        assert.equal(await field.inputValue(), literal, item.kind + ' old input should be escaped exactly once');
      }
      await assertSafe(page);
    }
  });

  test('Actual history renders correction links, original movements and legacy limitations', { timeout: 30000 }, async t => {
    const page = await pageFor(t, base + '/11?variant=corrected');
    const original = page.locator('#inventory-operation-41');
    assert.match(await original.innerText(), /Corrected.*Unissued stock restored/);
    assert.equal(await original.locator('a[data-app-modal-open]').count(), 0);
    assert.equal(await original.locator('a[href="#inventory-operation-43"]').count(), 1);
    assert.equal(await page.locator('#inventory-operation-43 a[href="#inventory-operation-41"]').count(), 1);
    assert.match(await page.locator('#inventory-movement-52').innerText(), /\+2/);
    assert.equal(await page.locator('#inventory-movement-52 a[href="#inventory-movement-51"]').count(), 1);
    assert.match(await page.locator('#inventory-movement-50').innerText(), /Legacy opening balance.*correction unavailable/);
    const pager = page.locator('#inventory-movements [data-table-pagination]');
    assert.match(await pager.innerText(), /Showing 1–100 of 101/);
    assert.equal(await pager.locator('.disabled').count(), 2);
    await pager.getByRole('link', { name: 'Next', exact: true }).click();
    await page.waitForURL(origin + base + '/11?page=2&per_page=100');
    assert.match(await pager.innerText(), /Showing 101–101 of 101/);
    assert.equal(await pager.locator('.disabled').count(), 2);
    assert.equal(await pager.locator('[aria-current="page"]').textContent(), '2');
    await pager.getByRole('link', { name: 'First', exact: true }).click();
    await page.waitForURL(origin + base + '/11?page=1&per_page=100');
    await page.goto(origin + base + '/11?page=3&per_page=20');
    assert.match(await pager.innerText(), /Showing 41–60 of 101/);
    assert.equal(await page.locator('#movementPerPage option').count(), 4);
    assert.equal(await page.locator('#movementPerPage').inputValue(), '20');
    for (const href of await pager.locator('a').evaluateAll(links => links.map(link => link.href))) {
      assert.equal(new URL(href).searchParams.get('per_page'), '20');
    }
    await page.goto(origin + base + '/11?variant=empty');
    assert.match(await pager.innerText(), /Showing 0–0 of 0/);
    assert.equal(await pager.locator('a').count(), 0);
    assert.equal(await pager.locator('[aria-disabled="true"]').count(), 4);
  });
});
