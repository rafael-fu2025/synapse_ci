'use strict';

// Actual CI templates + production JS; Node owns all HTTP/API responses. No DB/MIS.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin, failSave = false, saveGate;
const posts = [], allPosts = [], requests = [], cache = new Map();
function render(requestPath) {
  if (cache.has(requestPath)) return cache.get(requestPath);
  const result = spawnSync('php', ['tests/_support/render-referral-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: requestPath }), encoding: 'utf8', timeout: 10000
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  assert.match(result.stdout, /<div/);
  cache.set(requestPath, result.stdout);
  return result.stdout;
}
function fields(body) {
  return Object.fromEntries([...body.matchAll(/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([\s\S]*?)(?=\r\n--)/g)].map(m => [m[1], m[2]]));
}
before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a,b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    requests.push(url);
    if (req.method === 'POST') {
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const post = { path: url.pathname, fields: fields(Buffer.concat(chunks).toString()) };
      allPosts.push(post);
      // Startup services (for example /realtime/ticket) are not referral saves.
      if (!/^\/(?:clinic|guidance)\/referrals(?:\/|$)/.test(url.pathname)) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, message: 'Not provided by this referral fixture.' }));
        return;
      }
      posts.push(post);
      if (saveGate) await saveGate;
      res.writeHead(failSave ? 422 : 200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: !failSave, message: failSave ? 'Correct the shared summary.' : 'Saved',
        redirect: origin + '/' + url.pathname.split('/')[1] + '/referrals', csrf: { name: 'csrf_test_name', hash: 'retry-token' } }));
      return;
    }
    if (url.pathname === '/api/patients/search') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ results: [{ id: 11, first_name: 'Ada', last_name: 'Student', mis_id: 'S001', kind: 'student' }] }));
      return;
    }
    if (/^\/(clinic|guidance)\/referrals(?:\/create|\/2\/handoff)?$/.test(url.pathname) || url.pathname === '/referrals') {
      res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(render(url.pathname + url.search)); return;
    }
    const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + url.pathname);
    if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' }); res.end(content);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});
async function pageFor(t, unit, theme) {
  failSave = false; saveGate = null;
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  // All fixtures/assets are local; prevent incidental external font/network access.
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage(); page.setDefaultTimeout(6000);
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.text().includes('Alpine Expression Error')) errors.push(m.text()); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/' + unit + '/referrals');
  await page.waitForFunction(() => typeof window.SynapseConfirm !== 'undefined');
  return page;
}
async function open(page, suffix = '/create') {
  // Change only the fixture URL to exercise server-provided prefill/empty options.
  const link = page.locator(`a[data-app-modal-open][href*="${suffix.startsWith('/create') ? '/create' : '/2/handoff'}"]`).first();
  if (suffix.includes('?')) await link.evaluate((a, suffix) => { a.href = a.href.split('/referrals')[0] + '/referrals' + suffix; }, suffix);
  await link.click();
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.querySelector('form') && bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  });
}
async function dismiss(page, selector) {
  await page.locator('#appFormModal ' + selector).click();
  await page.waitForFunction(() => !document.querySelector('#appFormModal'));
}
const reasons = {
  clinic: ['anxiety_stress', 'academic_distress', 'grief_trauma', 'behavioral_concern', 'personal_support', 'career_guidance', 'family_support', 'other'],
  guidance: ['medical_assessment', 'medical_followup', 'physical_symptoms', 'health_advice', 'other']
};
for (const theme of ['light', 'dark']) for (const unit of ['clinic', 'guidance']) {
  const base = '/' + unit + '/referrals';
  test(`${theme} ${unit} referral create: manual/prefilled, fixed route, required fields and 422 retry`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, unit, theme);
    await open(page);
    const modal = page.locator('#appFormModal');
    assert.equal(await modal.locator('[x-data="referralForm($el.dataset)"]').getAttribute('data-initial-person'), 'null');
    assert.equal(await modal.locator('button[type=submit]').isDisabled(), true);
    await page.locator('#referralPatientSearch').fill('Ada');
    await modal.locator('.list-group button').filter({ hasText: 'Student, Ada' }).click();
    assert.equal(await modal.locator('[name=patient_person_id]').inputValue(), '11');
    assert.equal(await modal.locator('form').getAttribute('action'), origin + base + '/store');
    assert.equal(await modal.locator('[name=source_module], [name=target_module], select[name*=source], select[name*=target]').count(), 0);
    assert.match(await modal.innerText(), unit === 'clinic' ? /Clinic → Guidance/ : /Guidance → Clinic/);
    assert.doesNotMatch(await modal.innerText(), /within \d|\d+\s*(?:hours?|minutes?)|SLA/i);
    assert.match(await modal.innerText(), /does not guarantee an immediate response/);
    assert.deepEqual(await page.locator('#reason_code option').evaluateAll(nodes => nodes.map(n => n.value).filter(Boolean)), reasons[unit]);
    for (const name of ['reason_code', 'priority', 'reason_description']) assert.equal(await modal.locator(`[name=${name}]`).getAttribute('required'), '');
    assert.equal(await page.locator('#reason_description').getAttribute('maxlength'), '2000');
    assert.equal(await modal.locator('form').evaluate(f => f.checkValidity()), false);
    await page.locator('#reason_code').selectOption(reasons[unit][0]);
    await page.locator('#reason_description').fill('A'.repeat(2000));
    const key = await modal.locator('[name=request_key]').inputValue(); assert.match(key, /^[a-f0-9]{64}$/);
    const before = posts.length; failSave = true;
    await modal.locator('button[type=submit]').click();
    await page.locator('[data-modal-error]:not(.d-none)').waitFor();
    assert.equal(posts.length, before + 1);
    const submitted = posts.at(-1); assert.equal(submitted.path, base + '/store');
    assert.equal(submitted.fields.request_key, key); assert.equal(submitted.fields.reason_description.length, 2000);
    assert.equal(submitted.fields.patient_person_id, '11');
    for (const name of ['source_module', 'target_module']) assert.equal(Object.hasOwn(submitted.fields, name), false);
    assert.equal(await page.locator('#reason_description').inputValue(), 'A'.repeat(2000));
    failSave = false; await modal.locator('button[type=submit]').click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    assert.equal(posts.length, before + 2);
    assert.deepEqual(posts.at(-1).fields, { ...submitted.fields, csrf_test_name: 'retry-token' });
    await open(page, '/create?prefill=1' + (unit === 'clinic' ? '&source=1' : ''));
    assert.equal(await page.locator('#appFormModal [name=patient_person_id]').inputValue(), '11');
    assert.equal(await page.locator('#referralPatientSearch').isVisible(), false);
    assert.equal(await page.locator('#appFormModal button').filter({ hasText: /^Change$/ }).count(), unit === 'clinic' ? 0 : 1);
    if (unit === 'clinic') assert.equal(await page.locator('[name=source_encounter_id]').inputValue(), '71');
    await dismiss(page, 'a[data-app-modal-close]');
  });
  test(`${theme} ${unit} referral folders: own status actions, sent/history read-only, tab-safe search/paging/sidebar`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, unit, theme);
    const row = code => page.locator('tbody tr').filter({ hasText: code });
    assert.equal(await row('REF-1').getByRole('button', { name: 'Acknowledge', exact: true }).count(), 1);
    assert.equal(await row('REF-1').locator('a, form').count(), 1, 'No pre-ack handoff or close');
    assert.equal(await row('REF-2').getByRole('button', { name: 'Start review', exact: true }).count(), 1);
    assert.equal(await row('REF-2').locator('a[data-app-modal-open]').getAttribute('href'), origin + base + '/2/handoff');
    assert.equal(await row('REF-2').getByRole('button', { name: 'Close referral', exact: true }).count(), 1);
    assert.equal(await row('REF-3').getByRole('button', { name: 'Start review', exact: true }).count(), 0);
    assert.equal(await row('REF-3').locator('a[data-app-modal-open]').count(), 1);
    assert.equal(await row('REF-4').locator('a[data-app-modal-open]').count(), 0);
    assert.equal(await row('REF-4').getByRole('link', { name: /Open linked/ }).getAttribute('href'), origin + (unit === 'clinic' ? '/clinic/encounters/81' : '/guidance/cases/81'));
    for (const tab of ['incoming', 'sent', 'history']) {
      await page.goto(origin + base + '?tab=' + tab);
      assert.deepEqual(await page.locator('.sidebar-nav .nav-link.active').evaluateAll(ns => ns.map(n => new URL(n.href).pathname)), [base]);
      assert.equal(await page.locator('.sidebar-nav a[href="' + origin + base + '"]').count(), 1);
      assert.equal(await page.locator('[aria-label="Referral folders"] [aria-current=page]').count(), 1);
      if (tab !== 'incoming') assert.equal(await page.locator('tbody form, tbody [data-app-modal-open]').count(), 0);
      if (tab === 'sent') assert.equal(await page.locator('tbody a, tbody button').count(), 0);
      await page.locator('#referralSearch').fill('Ada');
      await page.waitForFunction(() => new URL(location.href).searchParams.get('q') === 'Ada');
      assert.equal(new URL(page.url()).searchParams.get('tab'), tab);
      const next = page.getByRole('link', { name: 'Next', exact: true });
      const url = new URL(await next.getAttribute('href')); assert.equal(url.searchParams.get('tab'), tab); assert.equal(url.searchParams.get('q'), 'Ada');
      await next.click();
      await page.waitForFunction(() => document.querySelector('[data-table-pagination]').dataset.page === '2');
      assert.equal(new URL(page.url()).searchParams.get('tab'), tab);
      await page.locator('[data-table-reset]').click();
      await page.waitForFunction(() => document.querySelector('#referralSearch').value === '' && document.querySelector('[data-table-pagination]').dataset.page === '1');
      assert.equal(new URL(page.url()).searchParams.get('tab'), tab);
      const last = requests.filter(u => u.pathname === base && u.searchParams.has('fragment')).at(-1);
      assert.equal(last.searchParams.get('tab'), tab);
    }
  });
  test(`${theme} ${unit} referral handoff: protected static modal, conditional modes and single submit`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, unit, theme);
    await page.evaluate(() => { window.originalMain = document.querySelector('#main'); });
    await open(page, '/2/handoff');
    assert.deepEqual(await page.locator('#appFormModal').evaluate(el => ({ backdrop: bootstrap.Modal.getInstance(el)._config.backdrop, keyboard: bootstrap.Modal.getInstance(el)._config.keyboard, forms: el.querySelectorAll('form').length })), { backdrop: 'static', keyboard: false, forms: 1 });
    assert.match(await page.locator('#appFormModal').innerText(), /Student, Ada/);
    assert.equal(await page.locator('#appFormModal [name=patient_person_id], #appFormModal input[type=search]').count(), 0);
    await page.keyboard.press('Escape'); await page.mouse.click(5, 5);
    assert.equal(await page.locator('#appFormModal').isVisible(), true);
    await dismiss(page, 'button[data-app-modal-close]');
    await open(page, '/2/handoff'); await dismiss(page, 'a[data-app-modal-close]');
    assert.equal(await page.evaluate(() => document.querySelector('#main') === window.originalMain), true);
    for (const mode of ['link', 'create']) {
      await open(page, '/2/handoff');
      await page.locator('#referralHandoffMode').selectOption(mode);
      const target = page.locator('#referralHandoffTarget');
      assert.equal(await target.isDisabled(), mode !== 'link');
      assert.equal(await target.evaluate(el => el.required), mode === 'link');
      if (mode === 'link') await target.selectOption('81');
      if (unit === 'guidance') {
        for (const id of ['#referralCaseTitle', '#referralCaseCategory']) {
          assert.equal(await page.locator(id).isDisabled(), mode !== 'create');
          assert.equal(await page.locator(id).evaluate(el => el.required), mode === 'create');
        }
        if (mode === 'create') {
          assert.equal(await page.locator('#appFormModal form').evaluate(f => f.checkValidity()), false);
          await page.locator('#referralCaseTitle').fill('Support plan'); await page.locator('#referralCaseCategory').selectOption('academic');
        }
      } else {
        assert.equal(await page.locator('#referralCaseTitle, #referralCaseCategory').count(), 0);
        assert.match(await page.locator('#appFormModal').innerText(), /No appointment or queue ticket is created automatically/);
        if (mode === 'create') assert.match(await page.locator('#appFormModal').innerText(), /patient is present/);
      }
      let release; saveGate = new Promise(resolve => { release = resolve; }); t.after(() => release());
      const before = posts.length, allBefore = allPosts.length;
      await page.locator('#appFormModal button[type=submit]').click();
      await page.waitForFunction(() => document.querySelector('#appFormModal button[type=submit]').disabled);
      await page.locator('#appFormModal form').evaluate(f => f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
      await page.keyboard.press('Escape');
      assert.equal(await page.locator('#appFormModal').isVisible(), true);
      release(); saveGate = null;
      await page.waitForFunction(() => !document.querySelector('#appFormModal'));
      assert.equal(posts.length, before + 1,
        `Expected exactly one referral mutation; all POST paths: ${JSON.stringify(allPosts.slice(allBefore).map(post => post.path))}`);
      assert.equal(posts.at(-1).path, base + '/2/handoff');
      assert.equal(posts.at(-1).fields.mode, mode);
      assert.equal(Object.hasOwn(posts.at(-1).fields, 'target_id'), mode === 'link');
      assert.equal(Object.hasOwn(posts.at(-1).fields, 'title'), unit === 'guidance' && mode === 'create');
    }
    await open(page, '/2/handoff?empty=1');
    assert.equal(await page.locator('#referralHandoffMode').inputValue(), 'create');
    assert.equal(await page.locator('#referralHandoffMode option[value=link]').isDisabled(), true);
    await dismiss(page, 'a[data-app-modal-close]');
  });
}
test('legacy referral route requires an explicit workstation, with no active sidebar default', async t => {
  const page = await pageFor(t, 'clinic', 'light');
  await page.goto(origin + '/referrals');
  assert.equal(await page.locator('.sidebar-nav .nav-link.active').count(), 0);
  assert.equal(await page.getByRole('link', { name: 'Clinic Workstation', exact: true }).getAttribute('href'), origin + '/clinic/referrals');
  assert.equal(await page.getByRole('link', { name: 'Guidance Workstation', exact: true }).getAttribute('href'), origin + '/guidance/referrals');
});
