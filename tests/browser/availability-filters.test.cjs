'use strict';
// Real production views and assets; fixture-only GETs, no DB or live backend.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const cache = new Map();
const filters = 'form[data-availability-filters]';
const results = '[data-availability-results]';
const status = '[data-availability-filter-status]';
const apply = '[data-availability-apply]';
const roster = '[data-department-staff] tbody';
const names = ['New MIS Employee', 'Provider Fixture', 'New Staff Fixture', 'Pending Employee'];

function render(url) {
  const key = url.pathname + url.search;
  if (!cache.has(key)) {
    const unit = url.pathname.startsWith('/clinic/') ? 'clinic' : 'guidance';
    const output = spawnSync('php', ['tests/_support/render-modal-fixture.php'], {
      cwd: root, encoding: 'utf8', timeout: 12000,
      input: JSON.stringify({ origin, path: key, kind: unit + '-availability' })
    });
    assert.equal(output.status, 0, output.stderr);
    assert.doesNotMatch(output.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
    cache.set(key, output.stdout);
  }
  return cache.get(key);
}

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    // Fail closed: even accidental mutation requests cannot reach an app backend.
    if (req.method !== 'GET') return res.writeHead(405).end();
    if (/^\/(clinic|guidance)\/appointments\/availability$/.test(url.pathname)) {
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url)); }
      catch (error) { return res.writeHead(500).end(error.message); }
    }
    const dir = path.join(root, 'public');
    const file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(dir + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(data);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath, 'A Playwright Chromium installation is required');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
});

async function open(t, unit = 'clinic', query = 'section=enrollment&day=3') {
  const context = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
  const reads = [], mutations = [], errors = [], documents = [];
  const transport = { intercept: null };
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) return route.abort();
    if (request.method() !== 'GET') {
      mutations.push({ method: request.method(), url: request.url() });
      return route.abort();
    }
    if (request.isNavigationRequest() && request.frame().parentFrame() === null) documents.push(request.url());
    if (url.pathname === `/${unit}/appointments/availability` && request.headers()['x-requested-with'] === 'XMLHttpRequest') {
      reads.push(url);
      if (transport.intercept && await transport.intercept(route, url)) return;
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => {
    await context.close();
    assert.deepEqual(mutations, [], 'Filtering must not issue mutating requests');
    assert.deepEqual(errors, [], 'Production widgets must initialize without browser errors');
  });
  await page.goto(`${origin}/${unit}/appointments/availability?${query}`);
  await page.waitForFunction(() => window.SynapseAvailabilityFilters && window.SynapseConfirm && window.Alpine && document.querySelector('[data-availability-apply]')?.hidden);
  await page.locator('#workspaceProvider').locator('..').locator('.bd-select-toggle').waitFor();
  await page.evaluate(() => {
    window.filterTestNodes = {
      main: document.querySelector('#main'), root: document.querySelector('#availability-workspace'),
      form: document.querySelector('[data-availability-filters]'), search: document.querySelector('#workspaceSearch'),
      results: document.querySelector('[data-availability-results]')
    };
  });
  return { page, reads, transport, documents };
}
async function shown(page, expected) {
  await page.waitForFunction(({ roster, expected }) => {
    const actual = Array.from(document.querySelectorAll(roster + ' tr td:nth-child(3)'), el => el.textContent.trim());
    return JSON.stringify(actual) === JSON.stringify(expected);
  }, { roster, expected });
  await page.waitForFunction(selector => /^Showing \d/.test(document.querySelector(selector)?.textContent || ''), status);
}
async function mounted(page) {
  assert.equal(await page.evaluate(() => Object.values(window.filterTestNodes).every(node => node.isConnected)
    && filterTestNodes.search === document.querySelector('#workspaceSearch')
    && filterTestNodes.main === document.querySelector('#main')
    && filterTestNodes.root === document.querySelector('#availability-workspace')), true, 'Live filtering preserves main, workspace, results and filter controls');
}
function urlState(page, expected) {
  const url = new URL(page.url());
  for (const [name, value] of Object.entries(expected)) assert.equal(url.searchParams.get(name), value, name);
}
async function chooseProvider(page, label) {
  const wrap = page.locator('#workspaceProvider').locator('..');
  await wrap.locator('.bd-select-toggle').click();
  await wrap.getByRole('option', { name: label, exact: true }).click();
}
// Hold a real rendered response until explicitly released, including after a newer GET.
function hold(transport, q) {
  let release;
  const ready = new Promise(resolve => {
    transport.intercept = async (route, url) => {
      if (url.searchParams.get('q') !== q) return false;
      const html = render(url);
      await new Promise(resume => { release = resume; resolve(); });
      try { await route.fulfill({ status: 200, contentType: 'text/html', body: html }); }
      catch (error) {
        // The production AbortController may already have canceled this request.
        if (!/closed|canceled|cancelled|Invalid InterceptionId/i.test(error.message)) throw error;
      }
      return true;
    };
  });
  return { ready, release: () => release?.() };
}

for (const unit of ['clinic', 'guidance']) {
  test(`${unit}: debounced typing preserves DOM, focus, caret and URL context (including IME)`, { timeout: 30000 }, async t => {
    const { page, reads, documents } = await open(t, unit);
    assert.equal(await page.locator(roster + ' tr').count(), 4);
    assert.equal(await page.locator(apply).isVisible(), false);
    const search = page.locator('#workspaceSearch');
    await search.focus();
    if (unit === 'guidance') {
      await search.dispatchEvent('compositionstart');
      await search.fill('Pending');
      await page.waitForTimeout(450);
      assert.equal(reads.length, 0, 'IME composition must not start a GET');
      await search.dispatchEvent('compositionend');
    } else {
      await search.pressSequentially('Pending', { delay: 25 });
      assert.equal(reads.length, 0, 'Typing must debounce before requesting');
    }
    await search.evaluate(el => el.setSelectionRange(2, 5));
    await shown(page, ['Pending Employee']);
    assert.equal(reads.length, 1);
    assert.deepEqual(await search.evaluate(el => ({ focused: el === document.activeElement, start: el.selectionStart, end: el.selectionEnd })), { focused: true, start: 2, end: 5 });
    await mounted(page);
    assert.equal(documents.length, 1, 'No full-page navigation for live filtering');
    assert.equal(await page.locator(apply).isVisible(), false);
    assert.match(await page.locator(status).innerText(), /1 matching staff members/);
    urlState(page, { q: 'Pending', provider: '', day: '3', section: 'enrollment' });
  });
}

test('Provider custom dropdown and reset auto-apply; Back/Forward restore URL and controls', { timeout: 30000 }, async t => {
  const { page, reads, documents } = await open(t, 'guidance', 'q=Fixture&section=enrollment&day=4');
  await chooseProvider(page, 'Provider Fixture');
  await shown(page, ['Provider Fixture']);
  assert.equal(reads.length, 1);
  urlState(page, { q: 'Fixture', provider: '7', day: '4', section: 'enrollment' });
  assert.deepEqual(await page.locator(`${results} form[data-availability-save]`).evaluateAll(forms => forms.map(form => {
    const data = new FormData(form);
    return Object.fromEntries(['return_q', 'return_provider', 'return_day', 'return_section'].map(name => [name, data.get(name)]));
  })), Array.from({ length: 2 }, () => ({ return_q: 'Fixture', return_provider: '7', return_day: '4', return_section: 'enrollment' })), 'Fresh partial-result POST forms retain the applied context without submitting');
  await mounted(page);
  await page.locator('[data-availability-reset]').click();
  await shown(page, names);
  urlState(page, { q: '', provider: '', day: '4', section: 'enrollment' });
  assert.equal(await page.locator('#workspaceSearch').inputValue(), '');
  assert.equal(await page.locator('#workspaceProvider').inputValue(), '');
  assert.equal(documents.length, 1);
  await mounted(page);
  // SynapseNavigation is allowed to swap main for history traversal, not reload the document.
  await page.evaluate(() => history.back());
  await page.waitForFunction(() => document.querySelector('#workspaceSearch')?.value === 'Fixture' && document.querySelector('#workspaceProvider')?.value === '7');
  await page.waitForFunction(() => document.querySelectorAll('[data-department-staff] tbody tr').length === 1);
  urlState(page, { q: 'Fixture', provider: '7', day: '4', section: 'enrollment' });
  await page.evaluate(() => history.forward());
  await page.waitForFunction(() => document.querySelector('#workspaceSearch')?.value === '' && document.querySelector('#workspaceProvider')?.value === '' && document.querySelectorAll('[data-department-staff] tbody tr').length === 4);
  urlState(page, { q: '', provider: '', day: '4', section: 'enrollment' });
  assert.equal(documents.length, 1);
});

test('A slow stale GET cannot overwrite newer results or URL', { timeout: 30000 }, async t => {
  const { page, transport } = await open(t);
  const slow = hold(transport, 'Provider');
  t.after(() => slow.release());
  await page.locator('#workspaceSearch').fill('Provider');
  await slow.ready;
  await page.locator('#workspaceSearch').fill('Pending');
  await shown(page, ['Pending Employee']);
  slow.release();
  await page.waitForTimeout(450);
  assert.equal(await page.locator(roster + ' tr td:nth-child(3)').innerText(), 'Pending Employee');
  urlState(page, { q: 'Pending' });
  await mounted(page);
});

test('Network failure and 403 retain old results and URL, then retry successfully', { timeout: 30000 }, async t => {
  const { page, transport } = await open(t);
  for (const failure of ['network', '403']) {
    const oldUrl = page.url();
    const oldRows = await page.locator(roster).innerHTML();
    transport.intercept = async route => {
      if (failure === 'network') await route.abort('failed');
      else await route.fulfill({ status: 403, contentType: 'text/html', body: 'Synthetic access denied' });
      return true;
    };
    const q = failure === 'network' ? 'Pending' : 'New';
    await page.locator('#workspaceSearch').fill(q);
    await page.locator('[data-availability-filter-error]').waitFor({ state: 'visible' });
    assert.equal(await page.locator(roster).innerHTML(), oldRows);
    assert.equal(page.url(), oldUrl);
    assert.match(await page.locator('[data-availability-filter-error-text]').innerText(), failure === 'network' ? /connection|retry/i : /session|access/i);
    transport.intercept = null;
    await page.locator('[data-availability-filter-retry]').click();
    await shown(page, failure === 'network' ? ['Pending Employee'] : ['New MIS Employee', 'New Staff Fixture']);
    assert.equal(await page.locator('[data-availability-filter-error]').isVisible(), false);
    urlState(page, { q });
    await mounted(page);
  }
});

test('Unsaved enrollment selections pause filtering; shared confirm cancel keeps them, approve clears them', { timeout: 30000 }, async t => {
  const { page, reads } = await open(t);
  const selected = page.getByRole('checkbox', { name: 'Enroll Pending Employee', exact: true });
  await selected.check();
  await page.waitForFunction(() => document.querySelector('#departmentEnrollment button').textContent === 'Enroll 1 Selected Employees');
  await page.locator('#workspaceSearch').fill('New');
  await page.locator(apply).waitFor({ state: 'visible' });
  await page.waitForTimeout(450);
  assert.equal(reads.length, 0);
  assert.equal(await selected.isChecked(), true);
  await page.locator(apply).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-cancel]').click();
  await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
  assert.equal(reads.length, 0);
  assert.equal(await selected.isChecked(), true);
  assert.equal(await page.locator(roster + ' tr').count(), 4);
  await page.waitForFunction(() => bootstrap.Modal.getInstance(document.getElementById('synapseConfirmModal'))?._isTransitioning === false);
  await page.locator(apply).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click();
  await shown(page, ['New MIS Employee', 'New Staff Fixture']);
  assert.equal(await page.locator(roster + ' input:checked').count(), 0);
  assert.equal(await page.locator('#departmentEnrollment button').innerText(), 'Enroll 0 Selected Employees');
  assert.equal(reads.length, 1);
  await mounted(page);
});

test('Editing staff selections during a pending GET blocks replacement', { timeout: 30000 }, async t => {
  const { page, transport } = await open(t);
  const slow = hold(transport, 'New');
  t.after(() => slow.release());
  await page.locator('#workspaceSearch').fill('New');
  await slow.ready;
  const selected = page.getByRole('checkbox', { name: 'Select Provider Fixture', exact: true });
  // The loading overlay covers pointer input, but a focused control can still be edited.
  await selected.focus();
  await selected.press('Space');
  await page.locator(apply).waitFor({ state: 'visible' });
  slow.release();
  await page.waitForTimeout(450);
  assert.equal(await selected.isChecked(), true);
  assert.equal(await page.locator(roster + ' tr').count(), 4);
  assert.equal(await page.locator('#departmentTemplate button[type="submit"]').innerText(), 'Apply to 1 Selected Staff');
  assert.equal(new URL(page.url()).searchParams.get('q'), null);
  await mounted(page);
});

for (const mismatch of ['cross-department workspace URL', 'changed navigation scope']) {
  test(`Reject incoming HTML with ${mismatch} without replacing results or executing scripts`, { timeout: 30000 }, async t => {
    const { page, reads, transport, documents } = await open(t);
    const oldUrl = page.url();
    const oldRows = await page.locator(results).innerHTML();
    const oldScope = await page.locator('body').getAttribute('data-navigation-scope');
    const oldWorkspace = await page.locator('#availability-workspace').getAttribute('data-workspace-url');
    transport.intercept = async (route, url) => {
      let html = render(url);
      if (mismatch === 'cross-department workspace URL') {
        const attribute = /data-workspace-url="[^"]*"/;
        assert.match(html, attribute);
        html = html.replace(attribute, `data-workspace-url="${origin}/guidance/appointments/availability"`);
      } else {
        const attribute = /data-navigation-scope="[^"]*"/;
        assert.match(html, attribute);
        html = html.replace(attribute, 'data-navigation-scope="different-fixture-access"');
      }
      // Keep the script outside results so safeContent cannot mask the URL/scope guard.
      assert.match(html, /<\/body>/);
      html = html.replace('</body>', '<script>window.availabilityInjectedScriptRan = true</script></body>');
      await route.fulfill({ status: 200, contentType: 'text/html', body: html });
      return true;
    };
    await page.locator('#workspaceSearch').fill('Pending');
    await page.locator('[data-availability-filter-error]').waitFor({ state: 'visible' });
    assert.match(await page.locator('[data-availability-filter-error-text]').innerText(), /workspace or your access changed/i);
    assert.equal(await page.locator(results).innerHTML(), oldRows, 'Rejected HTML must leave previous results intact');
    assert.equal(await page.evaluate(() => window.availabilityInjectedScriptRan), undefined);
    assert.equal(await page.locator('body').getAttribute('data-navigation-scope'), oldScope);
    assert.equal(await page.locator('#availability-workspace').getAttribute('data-workspace-url'), oldWorkspace);
    assert.equal(page.url(), oldUrl);
    assert.equal(reads.length, 1);
    assert.equal(documents.length, 1);
    assert.equal(await page.locator(apply).isVisible(), true);
    await mounted(page);
  });
}

test('Replaced results reinitialize Alpine/select/time widgets and retain refreshed CSRF across GETs', { timeout: 30000 }, async t => {
  const { page, reads, transport } = await open(t);
  transport.intercept = async (route, url) => {
    await route.fulfill({ status: 200, contentType: 'text/html', body: render(url), headers: {
      'X-CSRF-NAME': 'csrf_test_name', 'X-CSRF-TOKEN': 'filters-refreshed-token', 'X-CSRF-HEADER': 'X-CSRF-TOKEN'
    } });
    return true;
  };
  await page.locator('#workspaceSearch').fill('New');
  await shown(page, ['New MIS Employee', 'New Staff Fixture']);
  await page.waitForFunction(() => document.querySelector('meta[name="csrf-token"]').content === 'filters-refreshed-token');
  assert.deepEqual(await page.locator(`${results} input[name="csrf_test_name"]`).evaluateAll(inputs => inputs.map(input => input.value)), ['filters-refreshed-token', 'filters-refreshed-token']);
  transport.intercept = null;
  const next = page.waitForRequest(request => request.headers()['x-requested-with'] === 'XMLHttpRequest' && new URL(request.url()).searchParams.get('q') === 'Fixture');
  await page.locator('#workspaceSearch').fill('Fixture');
  assert.equal((await next).headers()['x-csrf-token'], 'filters-refreshed-token');
  await shown(page, ['Provider Fixture', 'New Staff Fixture']);
  const wrap = page.locator('#departmentDuration').locator('..');
  await wrap.locator('.bd-select-toggle').click();
  await wrap.getByRole('option', { name: '45 minutes', exact: true }).click();
  assert.equal(await page.locator('#departmentDuration').inputValue(), '45');
  await page.getByRole('checkbox', { name: 'Select all eligible staff', exact: true }).check();
  await page.waitForFunction(() => document.querySelector('#departmentTemplate button[type="submit"]').textContent === 'Apply to 2 Selected Staff')
    .catch(async error => {
      assert.equal(await page.locator('#departmentTemplate button[type="submit"]').innerText(), 'Apply to 2 Selected Staff', 'Select-all must update the reinitialized Alpine selection model');
      throw error;
    });
  assert.equal(await page.locator(roster + ' input[name="staff_assignment_ids[]"]:checked').count(), 2);
  await page.getByRole('checkbox', { name: 'Select Provider Fixture', exact: true }).uncheck();
  await page.waitForFunction(() => document.querySelector('#departmentTemplate button[type="submit"]').textContent === 'Apply to 1 Selected Staff');
  assert.equal(await page.getByRole('checkbox', { name: 'Select all eligible staff', exact: true }).evaluate(el => el.indeterminate), true);
  const start = page.locator('#departmentStart[data-timepicker]');
  assert.equal(await start.getAttribute('data-target'), '#departmentStartValue');
  await start.click();
  const popup = page.locator('.dtp-popup.dtp-time-only:visible');
  await popup.waitFor();
  await popup.locator('.dtp-hours-wheel button[data-val="9"]').click();
  await popup.locator('.dtp-minutes-wheel button[data-val="5"]').click();
  await popup.locator('.dtp-ampm-wheel button[data-val="AM"]').click();
  await popup.getByRole('button', { name: 'Apply', exact: true }).click();
  await popup.waitFor({ state: 'hidden' });
  assert.equal(await start.inputValue(), '09:05 AM');
  assert.equal(await page.locator('#departmentStartValue').inputValue(), '09:05');
  assert.equal(reads.length, 2);
  await mounted(page);
});
