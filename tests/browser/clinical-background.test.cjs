'use strict';
// Isolated fixtures: production mutation/confirmation/stepper owners, no patient data or app DB.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const viewScript = name => fs.readFileSync(path.join(root, 'app/Views', name), 'utf8').split('<script>')[1].split('</script>')[0];
const tab = (id, active = false) => `<button type="button" class="nav-link ${active ? 'active' : ''}" data-bs-toggle="tab" data-bs-target="#${id}">${id}</button>`;
const pane = (id, content, active = false) => `<section id="${id}" class="tab-pane ${active ? 'active show' : ''}">${content}</section>`;
const form = (id, content, attributes = 'data-background-submit') => `<form id="${id}" method="post" action="/${id}" ${attributes}><input type="hidden" name="csrf" value="old">${content}</form>`;
function main(kind) {
  const other = form('other', '<input name="unrelated" value="original">', '');
  if (kind === 'stepper') return `<main id="main">${other}<div data-encounter-stepper data-draft-url="/draft">
    <span data-encounter-save-status>Saved</span><button data-save-draft hidden>Retry</button>
    <nav class="nav nav-tabs">${['triage', 'assessment', 'dispense', 'outcome'].map((s, i) => tab('step-' + s, !i)).join('')}</nav><div class="tab-content">
    ${pane('step-triage', form('vitals', '<input name="pulse_bpm" value="80"><button>Save</button>', 'data-clinical-form'), true)}
    ${pane('step-assessment', form('assessment', '<input name="chief_complaint" value="Headache"><textarea name="assessment_notes"></textarea><button>Save</button>', 'data-clinical-form'))}
    ${pane('step-dispense', form('dispense', '<input type="hidden" name="idempotency_key" value="fixed-key"><select name="medicine_id"><option value="">Choose</option><option value="7">Medicine</option></select><input name="quantity" value="1"><input name="instructions"><button>Dispense</button>', 'data-confirm="Deduct stock?"') + '<button data-discard-medicine>Discard</button><button data-continue-outcome>Continue</button>')}
    ${pane('step-outcome', form('complete', '<input name="disposition" value="discharged"><button>Complete</button>', 'data-clinical-form data-complete-encounter'))}
    </div></div></main>`;
  const guidance = kind === 'guidance';
  const editor = guidance ? form('notes', '<textarea name="notes">Server note</textarea><button name="action" value="draft" formaction="/notes">Draft</button><button name="action" value="finalize" formaction="/notes">Finalize</button>') : form('health', '<textarea name="allergies">Server profile</textarea><button>Save health</button>');
  return `<main id="main">${other}<div ${guidance ? 'data-guidance-case="42"' : 'data-patient-chart="42"'}><nav class="nav nav-tabs">${tab('history', true)}${tab('editor')}${tab('followups')}</nav><div class="tab-content">${pane('history', '', true)}${pane('editor', editor)}${pane('followups', form('followup', '<input name="action_plan" value="Plan"><button>Done</button>'))}</div></div></main>`;
}
function html(kind) {
  return `<!doctype html><link rel="icon" href="data:,"><link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css"><body>${main(kind)}
  <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="/assets/js/toast.js"></script><script src="/assets/js/confirm.js"></script>
  <script>
  window.dirty = new Set(); window.calls = []; window.savedForms = []; window.failRefresh = false;
  window.SynapseCsrf = { headers: () => ({'X-CSRF-Token':'old'}), fromResponse: () => {}, sync: value => { window.csrf = value; } };
  document.addEventListener('input', event => { if(event.target.form) dirty.add(event.target.form); });
  document.addEventListener('change', event => { if(event.target.form) dirty.add(event.target.form); });
  window.SynapseNavigation = {
    hasUnsavedChanges: except => [...dirty].some(f => f.isConnected && f !== except),
    markSaved: f => { dirty.delete(f); savedForms.push(f.id); },
    navigate: async (url, options) => {
      const event = new CustomEvent('synapse:before-navigate', {cancelable:true, detail:{url}}); document.dispatchEvent(event);
      calls.push({url, options, veto:event.defaultPrevented, inert:document.querySelector('[data-encounter-stepper]')?.inert});
      if(event.defaultPrevented || SynapseNavigation.hasUnsavedChanges()) return false;
      const response = await fetch(url); if(failRefresh || !response.ok) return false;
      const incoming = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('main');
      const old = document.getElementById('main');
      document.dispatchEvent(new CustomEvent('synapse:before-swap', {detail:{root:old, incoming, url}}));
      window.stopStepper?.(); old.replaceWith(incoming); dirty.clear(); window.swaps = (window.swaps || 0) + 1;
      if(document.querySelector('[data-encounter-stepper]')) window.stopStepper = SynapseEncounterStepper.init(document.querySelector('[data-encounter-stepper]'));
      return true;
    }
  };
  </script><script src="/assets/js/form-modal.js"></script><script src="/assets/js/background-actions.js"></script><script src="/assets/js/encounter-stepper.js"></script>
  <script>
  window.refreshResults = [];
  const sharedRefresh = window.SynapseMutations.refresh;
  window.SynapseMutations.refresh = async (...args) => {
    const result = await sharedRefresh(...args); refreshResults.push(result); return result;
  };
  </script>
  <script>${kind === 'stepper' ? "window.stopStepper = SynapseEncounterStepper.init(document.querySelector('[data-encounter-stepper]'));" : viewScript(kind === 'guidance' ? 'guidance/cases/view.php' : 'clinic/patients/chart.php')}</script></body>`;
}
before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method === 'POST') {
      req.resume(); req.on('end', () => { res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({success:true, message:'Confirmed saved', redirect:'/intentional-exit', csrf:{name:'csrf', hash:'rotated'}})); }); return;
    }
    if (url.pathname.startsWith('/fixture/')) { res.writeHead(200, {'Content-Type':'text/html'}).end(html(url.pathname.split('/')[2])); return; }
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(root, 'public', '.' + url.pathname);
      if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
      res.writeHead(200, {'Content-Type':file.endsWith('.js') ? 'text/javascript' : 'text/css'}).end(fs.readFileSync(file)); return;
    }
    res.writeHead(200, {'Content-Type':'text/html'}).end('<h1>Intentional destination</h1>');
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening'); origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(n => /^chromium-\d+$/.test(n)).sort((a,b) => Number(b.split('-')[1]) - Number(a.split('-')[1])).map(n => path.join(cache, n, 'chrome-win64/chrome.exe')).find(fs.existsSync);
  }
  browser = await chromium.launch({executablePath, headless:true, timeout:20000});
}, {timeout:30000});
after(async () => { await browser?.close(); if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } });
async function fixture(t, kind) {
  const page = await browser.newPage(); t.after(() => page.close());
  const posts = [], errors = [];
  page.on('pageerror', e => errors.push(e.message)); page.on('request', r => { if(r.method() === 'POST') posts.push(r); });
  t.after(() => assert.deepEqual(errors, []));
  await page.goto(`${origin}/fixture/${kind}`); return {page, posts};
}
async function step(page, target) {
  await page.locator(`[data-bs-target="#${target}"]`).click();
  await page.waitForFunction(id => document.getElementById(id).classList.contains('active'), target);
}
for (const kind of ['guidance', 'health']) {
  test(`${kind}: unrelated edits allow saving but defer destructive refresh`, async t => {
    const {page, posts} = await fixture(t, kind); await step(page, 'editor');
    await page.locator('#other input[name="unrelated"]').fill('Keep unrelated');
    await page.locator('#editor textarea').fill('Keep clinical'); await page.locator('#editor button').first().click();
    await page.locator('.synapse-toast-action').waitFor();
    await page.waitForFunction(() => refreshResults.length === 1);
    assert.equal(posts.length, 1); assert.equal(await page.locator('#editor textarea').inputValue(), 'Keep clinical');
    assert.equal(await page.locator('#other input[name="unrelated"]').inputValue(), 'Keep unrelated');
    const state = await page.evaluate(() => ({calls, savedForms, refreshResults, dirty:[...dirty].map(f => f.id),
      locked:[...document.querySelector('#editor form').elements].every(control => control.disabled)}));
    assert.deepEqual(state.calls, []); assert.deepEqual(state.refreshResults, [false]);
    assert.deepEqual(state.savedForms, [kind === 'guidance' ? 'notes' : 'health']);
    assert.deepEqual(state.dirty, ['other']); assert.equal(state.locked, true);
    assert.equal(await page.locator('#other input[name="unrelated"]').isEnabled(), true);
  });
  test(`${kind}: confirmed save refreshes current GET and preserves selected tab`, async t => {
    const {page, posts} = await fixture(t, kind); await step(page, 'editor');
    await page.locator('#editor textarea').fill('Edited clinical'); await page.locator('#editor button').last().click();
    await page.waitForFunction(() => window.swaps === 1);
    assert.equal(posts.length, 1); assert.equal(posts[0].headers()['x-synapse-feedback'], 'toast');
    if(kind === 'guidance') {
      assert.equal(new URL(posts[0].url()).pathname, '/notes');
      assert.match(posts[0].postData(), /name="action"\r\n\r\nfinalize/);
    }
    const state = await page.evaluate(() => ({calls, savedForms, csrf, active:document.querySelector('#editor').classList.contains('active')}));
    assert.equal(state.active, true); assert.equal(state.calls[0].url, page.url()); assert.equal(state.calls[0].veto, false);
    assert.equal(state.calls[0].options.fallback, false); assert.deepEqual(state.savedForms, [kind === 'guidance' ? 'notes' : 'health']); assert.equal(state.csrf.hash, 'rotated');
  });
}
test('dirty guidance follow-up saves without discarding hidden notes, then notes can save too', async t => {
  const {page, posts} = await fixture(t, 'guidance'); await step(page, 'editor');
  await page.locator('#notes textarea').fill('Private unsaved note'); await step(page, 'followups');
  await page.locator('#followup input[name="action_plan"]').fill('Updated plan');
  await page.locator('#followup button').click(); await page.locator('.synapse-toast-action').waitFor();
  await page.waitForFunction(() => refreshResults.length === 1);
  assert.equal(posts.length, 1); assert.equal(await page.locator('#notes textarea').inputValue(), 'Private unsaved note');
  assert.deepEqual(await page.evaluate(() => ({calls, savedForms, refreshResults, dirty:[...dirty].map(f => f.id)})),
    {calls:[], savedForms:['followup'], refreshResults:[false], dirty:['notes']});
  assert.equal(await page.locator('#followup input[name="action_plan"]').isDisabled(), true);
  await step(page, 'editor'); assert.equal(await page.locator('#notes textarea').isEnabled(), true);
  await page.locator('#notes button').first().click(); await page.waitForFunction(() => window.swaps === 1);
  assert.equal(posts.length, 2); assert.deepEqual(await page.evaluate(() => savedForms), ['followup', 'notes']);
});
test('failed GET keeps confirmed note form locked and offers GET-only retry', async t => {
  const {page, posts} = await fixture(t, 'guidance'); await step(page, 'editor');
  await page.evaluate(() => { failRefresh = true; }); await page.locator('#notes button').first().click();
  await page.locator('.synapse-toast-action').waitFor(); assert.equal(posts.length, 1);
  await page.evaluate(() => document.getElementById('notes').requestSubmit()); assert.equal(posts.length, 1);
  await page.evaluate(() => { failRefresh = false; }); await page.locator('.synapse-toast-action').click();
  await page.waitForFunction(() => window.swaps === 1); assert.equal(posts.length, 1);
  assert.equal(await page.evaluate(() => document.getElementById('editor').classList.contains('active')), true);
});
test('JSON error retains notes, syncs CSRF and permits correction without refresh', async t => {
  const {page, posts} = await fixture(t, 'guidance'); await step(page, 'editor');
  await page.route('**/notes', route => route.fulfill({status:422, contentType:'application/json', body:JSON.stringify({success:false, message:'Check fields', csrf:{hash:'error-token'}})}));
  await page.locator('#notes textarea').fill('Keep draft'); await page.locator('#notes button').first().click();
  await page.waitForFunction(() => document.querySelector('.synapse-toast-message')?.textContent, {timeout:5000});
  assert.equal(await page.locator('.synapse-toast-message').textContent(), 'Check fields', JSON.stringify(posts.map(p => p.url())));
  assert.equal(await page.locator('#notes textarea').inputValue(), 'Keep draft'); assert.equal(await page.evaluate(() => csrf.hash), 'error-token');
  assert.equal(await page.evaluate(() => calls.length), 0); assert.equal(posts.length, 1); assert.equal(await page.locator('#notes button').first().isEnabled(), true);
});
async function medicine(page) { await step(page, 'step-dispense'); await page.locator('[name="medicine_id"]').selectOption('7'); }
test('dispense consent cancellation performs no writes', async t => {
  const {page, posts} = await fixture(t, 'stepper'); await medicine(page);
  page.once('dialog', d => d.dismiss()); await page.locator('#dispense button').click();
  await page.waitForTimeout(100); assert.equal(posts.length, 0);
});
test('confirmed draft marks changed clinical forms saved before dispense refresh, preserving step and key', async t => {
  const {page, posts} = await fixture(t, 'stepper'); await medicine(page);
  await page.evaluate(() => { for(const name of ['pulse_bpm', 'assessment_notes']) { const field = document.querySelector('[name="' + name + '"]'); field.value = name === 'pulse_bpm' ? '90' : 'Updated assessment'; field.dispatchEvent(new Event('input', {bubbles:true})); } });
  page.once('dialog', d => d.accept()); await page.locator('#dispense button').click(); await page.waitForFunction(() => window.swaps === 1);
  assert.equal(posts.length, 2); assert.equal(new URL(posts[0].url()).pathname, '/draft'); assert.equal(new URL(posts[1].url()).pathname, '/dispense');
  assert.match(posts[1].postData(), /fixed-key/); assert.equal(posts[1].headers()['x-synapse-feedback'], 'toast');
  const state = await page.evaluate(() => ({calls, savedForms, active:document.getElementById('step-dispense').classList.contains('active')}));
  assert.deepEqual(state.savedForms, ['vitals', 'assessment', 'dispense']); assert.equal(state.calls[0].veto, false); assert.equal(state.calls[0].inert, false); assert.equal(state.active, true);
});
test('dispense saves after draft baseline with unrelated edits, locks medicine and defers GET without replay', async t => {
  const {page, posts} = await fixture(t, 'stepper'); await medicine(page);
  await page.locator('#other input[name="unrelated"]').fill('Other draft');
  await page.evaluate(() => {
    const field = document.querySelector('[name="assessment_notes"]');
    field.value = 'Save this assessment first'; field.dispatchEvent(new Event('input', {bubbles:true}));
  });
  page.once('dialog', d => d.accept()); await page.locator('#dispense button').click();
  await page.locator('.synapse-toast-action').waitFor(); await page.waitForFunction(() => refreshResults.length === 1);
  assert.equal(posts.length, 2);
  assert.deepEqual(posts.map(p => new URL(p.url()).pathname), ['/draft', '/dispense']);
  assert.match(posts[1].postData(), /fixed-key/);
  assert.equal(await page.locator('#other input[name="unrelated"]').inputValue(), 'Other draft');
  assert.equal(await page.locator('#other input[name="unrelated"]').isEnabled(), true);
  assert.equal(await page.locator('[name="medicine_id"]').inputValue(), '7');
  const state = await page.evaluate(() => ({calls, savedForms, refreshResults, dirty:[...dirty].map(f => f.id),
    locked:[...document.getElementById('dispense').elements].every(control => control.disabled),
    medicineInert:document.getElementById('dispense').inert, rootInert:document.querySelector('[data-encounter-stepper]').inert}));
  assert.deepEqual(state.calls, []); assert.deepEqual(state.refreshResults, [false]);
  assert.deepEqual(state.savedForms, ['assessment', 'dispense']); assert.deepEqual(state.dirty, ['other']);
  assert.equal(state.locked, true); assert.equal(state.medicineInert, true); assert.equal(state.rootInert, false);
  await page.evaluate(() => document.getElementById('dispense').dispatchEvent(new Event('submit', {bubbles:true, cancelable:true})));
  await page.locator('.synapse-toast-action').click();
  assert.equal(posts.length, 2); assert.equal(await page.evaluate(() => calls.length), 0);
  assert.equal(await page.locator('[name="quantity"]').isDisabled(), true);
  await page.evaluate(() => dirty.delete(document.getElementById('other')));
  await page.locator('.synapse-toast-action').click(); await page.waitForFunction(() => window.swaps === 1);
  assert.equal(posts.length, 2); assert.equal(await page.evaluate(() => calls[0].veto), false);
  assert.deepEqual(await page.evaluate(() => savedForms), ['assessment', 'dispense']);
});
test('dispense GET failure never replays stock deduction and GET retry releases private guards', async t => {
  const {page, posts} = await fixture(t, 'stepper'); await medicine(page); await page.evaluate(() => { failRefresh = true; });
  page.once('dialog', d => d.accept()); await page.locator('#dispense button').click(); await page.locator('.synapse-toast-action').waitFor();
  await page.evaluate(() => document.getElementById('dispense').dispatchEvent(new Event('submit', {bubbles:true, cancelable:true})));
  assert.equal(posts.length, 1); assert.equal(await page.evaluate(() => calls[0].veto), false);
  await page.evaluate(() => { failRefresh = false; }); await page.locator('.synapse-toast-action').click(); await page.waitForFunction(() => window.swaps === 1);
  assert.equal(posts.length, 1); assert.equal(await page.evaluate(() => document.getElementById('step-dispense').classList.contains('active')), true);
});
