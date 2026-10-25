'use strict';

// Focused local fixtures: real save owners and Bootstrap lifecycle; promised navigation API stub.
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

function modal(form, attributes = '') {
  return `<div class="modal fade" tabindex="-1" ${attributes}><div class="modal-dialog"><div class="modal-content"><button type="button" data-app-modal-close>Close</button><div class="modal-body"><div data-modal-error class="d-none"></div>${form}</div></div></div></div>`;
}
function markup(kind, fallback) {
  const generic = kind === 'generic' || kind === 'carousel';
  let fields = '<input name="title" value="Keep these inputs"><button type="submit">Save</button>';
  if (kind === 'carousel') fields = `<input id="light_file" type="file" name="light_file"><input id="dark_file" type="file" name="dark_file"><input id="light_path" value="assets/img/brand/example.png"><input id="dark_path"><div id="slideFormAlert" class="d-none"></div><button id="slideSubmitBtn" type="submit">Save</button><div class="carousel-upload-progress d-none"><div class="progress-bar"></div><span class="dz-status"></span><span class="dz-pct"></span></div>`;
  const form = `<form method="post" action="${kind === 'bmg' ? '/facilities/save' : kind === 'availability' ? '/availability/template' : '/save'}" ${kind === 'carousel' ? 'id="slideForm"' : ''} ${generic ? 'data-modal-submit' : kind === 'bmg' ? 'data-bmg-submit' : 'data-availability-save'}>${fields}</form>`;
  let content = modal(form, generic ? 'id="appFormModal" data-app-form-modal data-modal-direct="true" data-return-url="/cancelled"' : kind === 'bmg' ? 'id="operation" data-bmg-modal' : 'id="operation"');
  if (kind === 'availability') content = `<section data-availability-workspace data-workspace-url="/availability">${content}</section>`;
  return `<!doctype html><html><head><link rel="icon" href="data:,"><link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css"></head><body><main id="main"><h1>Background</h1><form id="other" method="post"><input name="draft" value="Unrelated draft"></form><div data-bmg-base-url="/facilities"></div>${content}</main>
  <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="/assets/js/toast.js"></script>
  <script>
  window.calls = []; window.posts = []; window.dirty = new Set(); window.loading = 0; window.finished = 0;
  document.addEventListener('shown.bs.modal', e => { e.target.dataset.ready = 'true'; });
  document.addEventListener('input', e => { if(e.target.form) dirty.add(e.target.form); });
  document.addEventListener('change', e => { if(e.target.form) dirty.add(e.target.form); });
  window.SynapseCsrf = { headers: () => ({'X-CSRF-Token':'old'}), fromResponse: () => {}, sync: csrf => { window.lastCsrf = csrf; } };
  window.SynapseLoading = { begin: (form, {button} = {}) => { loading++; let done = false; const disabled = button?.disabled; if(button) button.disabled = true; return () => { if(!done) { done = true; loading--; finished++; if(button) button.disabled = disabled; } }; } };
  window.SynapseNavigation = {
    hasUnsavedChanges: except => [...dirty].some(form => form.isConnected && form !== except),
    markSaved: form => { calls.push({saved: form.id || form.dataset.modalSubmit || 'form'}); dirty.delete(form); },
    navigate: async (url, options) => {
      const event = new CustomEvent('synapse:before-navigate', {cancelable:true, detail:{url}});
      document.dispatchEvent(event);
      calls.push({url, options, veto:event.defaultPrevented, modal:!!document.querySelector('.modal.show, .modal-backdrop'), bodyModal:document.body.classList.contains('modal-open'), loading});
      if(event.defaultPrevented || window.SynapseNavigation.hasUnsavedChanges()) return false;
      const response = await fetch(url);
      if(window.failRefresh || !response.ok) return false;
      const old = document.getElementById('main');
      document.dispatchEvent(new CustomEvent('synapse:before-swap', {detail:{root:old}}));
      old.innerHTML = '<h1 id="result">Refreshed server destination</h1>';
      history.replaceState({}, '', url);
      document.dispatchEvent(new CustomEvent('synapse:page-load', {detail:{root:old}}));
      return true;
    }
  };
  </script><script src="/assets/js/form-modal.js"></script>
  ${fallback ? '<script>delete window.SynapseMutations;</script>' : ''}
  ${kind === 'carousel' ? '<script src="/carousel.js"></script>' : kind === 'generic' ? '' : `<script src="/assets/js/${kind === 'bmg' ? 'bmg' : 'availability-workspace'}.js"></script>`}
  <script>${generic ? '' : "bootstrap.Modal.getOrCreateInstance(document.getElementById('operation')).show();"}</script></body></html>`;
}

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method === 'POST') {
      req.resume();
      req.on('end', () => {
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ success: true, message: 'Confirmed saved', redirect: req.url.startsWith('/facilities') ? '/facilities/42' : req.url.startsWith('/availability') ? '/availability?provider=42&day=3&section=weekly' : '/result?date=2026-11-01', csrf: { name: 'csrf', hash: 'new-token' } }));
      });
      return;
    }
    if (url.pathname.startsWith('/fixture/')) {
      res.setHeader('Content-Type', 'text/html'); res.end(markup(url.pathname.split('/')[2], url.searchParams.has('fallback'))); return;
    }
    if (url.pathname === '/carousel.js') {
      const source = fs.readFileSync(path.join(root, 'app/Views/admin/carousel/_form_scripts.php'), 'utf8').split('<script>')[1].split('</script>')[0].replace(/<\?= !empty\(\$slide\['id'\]\) \? 'true' : 'false' \?>/, 'false');
      res.setHeader('Content-Type', 'text/javascript'); res.end(source); return;
    }
    if (!url.pathname.startsWith('/assets/')) { res.setHeader('Content-Type', 'text/html'); res.end('<h1>Server result</h1>'); return; }
    const file = path.resolve(root, 'public', '.' + url.pathname);
    if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
    res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream');
    res.end(fs.readFileSync(file));
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name)).sort((a,b) => Number(b.split('-')[1]) - Number(a.split('-')[1])).map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
    assert.ok(executablePath, 'Install Chromium for Playwright');
  }
  browser = await chromium.launch({ executablePath, headless: true });
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });

async function fixture(t, kind, suffix = '') {
  const page = await browser.newPage();
  t.after(() => page.close());
  const errors = [], posts = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (request.method() === 'POST') posts.push(request); });
  t.after(() => assert.deepEqual(errors, []));
  await page.goto(`${origin}/fixture/${kind}${suffix}`);
  await page.waitForFunction(() => !!document.querySelector('.modal.show[data-ready="true"]'));
  return { page, posts };
}

for (const kind of ['generic', 'availability', 'bmg', 'carousel']) {
  test(`${kind}: saves once with another dirty editor and defers only the GET`, async t => {
    const { page, posts } = await fixture(t, kind);
    await page.evaluate(() => {
      const input = document.querySelector('#other input');
      input.value = 'Retain unrelated edit';
      input.dispatchEvent(new Event('input', { bubbles:true }));
      dirty.add(document.querySelector('.modal form'));
    });
    assert.equal(await page.evaluate(() => SynapseMutations.canRefresh(document.querySelector('.modal form'))), false);
    await page.locator('.modal button[type="submit"]').click();
    await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').waitFor();
    assert.equal(posts.length, 1);
    assert.equal(await page.locator('#other input').inputValue(), 'Retain unrelated edit');
    assert.equal(await page.locator('#other input').isEnabled(), true);
    assert.equal(await page.evaluate(() => dirty.has(document.getElementById('other'))), true);
    assert.equal(await page.evaluate(() => calls.filter(call => call.url).length), 0);
    assert.equal(await page.evaluate(() => calls.filter(call => 'saved' in call).length), 1);
    assert.equal(await page.evaluate(() => [...dirty].length), 1, 'Only the confirmed-saved form is marked clean');
    assert.equal(await page.evaluate(() => !!document.querySelector('.modal.show, .modal-backdrop')), false);
    await page.locator('.synapse-toast-action').click();
    assert.equal(await page.evaluate(() => calls.filter(call => call.url).length), 0, 'Retry must still retain the other draft');
    await page.evaluate(() => dirty.delete(document.getElementById('other')));
    await page.locator('.synapse-toast-action').click();
    await page.locator('#result').waitFor();
    assert.equal(posts.length, 1, 'Deferred retry performs GET only');
    assert.equal(await page.evaluate(() => calls.filter(call => 'saved' in call).length), 1);
  });
  test(`${kind}: confirmed save cleans modal and owner locks before progressive GET`, async t => {
    const { page, posts } = await fixture(t, kind);
    await page.locator('.modal button[type="submit"]').click();
    await page.locator('#result').waitFor();
    assert.equal(posts.length, 1);
    assert.equal(posts[0].headers()['x-synapse-feedback'], 'toast');
    assert.equal(posts[0].headers()['x-csrf-token'], 'old');
    const state = await page.evaluate(() => ({ calls, csrf:lastCsrf, loading }));
    const navigation = state.calls.find(call => call.url);
    assert.equal(navigation.modal, false);
    assert.equal(navigation.bodyModal, false);
    assert.equal(navigation.veto, false);
    assert.equal(navigation.loading, 0);
    assert.equal(navigation.options.history, 'replace');
    assert.equal(navigation.options.fallback, false);
    assert.equal(state.csrf.hash, 'new-token');
    assert.equal(state.calls.filter(call => 'saved' in call).length, 1);
    assert.match(page.url(), kind === 'availability' ? /availability\?provider=42&day=3&section=weekly$/ : kind === 'bmg' ? /facilities\/42$/ : /result\?date=2026-11-01$/);
    await page.waitForFunction(() => document.querySelector('.synapse-toast-message')?.textContent === 'Confirmed saved');
  });
}

for (const suffix of ['', '?fallback']) {
  test(`generic: hide.bs.modal reentry cannot POST again${suffix ? ' without the helper' : ''}`, async t => {
    const { page, posts } = await fixture(t, 'generic', suffix);
    await page.evaluate(() => {
      document.querySelector('.modal').addEventListener('hide.bs.modal', event => {
        const form = event.target.querySelector('form');
        window.reentry = {
          disabled: [...form.elements].every(control => !('disabled' in control) || control.disabled),
          inert: form.inert,
          loading,
          prevented: !form.dispatchEvent(new Event('submit', { bubbles:true, cancelable:true }))
        };
      }, { once:true });
    });
    if (suffix) {
      // Keep this document so the teardown observations can be inspected after assign().
      await page.route('**/result?date=2026-11-01', route => route.fulfill({ status:204 }));
    }
    await page.locator('.modal button[type="submit"]').click();
    if (suffix) await page.waitForFunction(() => !document.querySelector('.modal'));
    else await page.locator('#result').waitFor();
    assert.equal(posts.length, 1, 'The hide handler must not issue a second POST');
    assert.deepEqual(await page.evaluate(() => reentry), { disabled:true, inert:true, loading:0, prevented:true });
  });
}

test('shared refresh locks every saved-form control but leaves the other editor editable', async t => {
  const { page, posts } = await fixture(t, 'bmg');
  const result = await page.evaluate(async () => {
    const form = document.createElement('form');
    form.id = 'saved-editor';
    form.method = 'post';
    form.innerHTML = '<input value="Saved value"><textarea>Saved notes</textarea><select><option>Saved choice</option></select><input type="checkbox" checked><button>Save</button>';
    document.getElementById('main').append(form);
    const external = document.createElement('input');
    external.setAttribute('form', form.id);
    document.getElementById('main').append(external);
    const other = document.getElementById('other');
    dirty.add(form);
    dirty.add(other);
    const refreshed = await SynapseMutations.refresh('/result', 'Confirmed saved', { form });
    return {
      refreshed,
      disabled: [...form.elements].every(control => control.disabled),
      savedClean: !dirty.has(form),
      otherDirty: dirty.has(other),
      otherEditable: !other.elements[0].disabled,
      submitPrevented: !form.dispatchEvent(new Event('submit', { bubbles:true, cancelable:true }))
    };
  });
  assert.deepEqual(result, { refreshed:false, disabled:true, savedClean:true, otherDirty:true, otherEditable:true, submitPrevented:true });
  assert.equal(posts.length, 0);
  assert.equal(await page.evaluate(() => calls.filter(call => call.url).length), 0);
  await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').waitFor();
});

test('failed GET offers sticky GET-only retry and marks only the saved form once', async t => {
  const { page, posts } = await fixture(t, 'generic');
  await page.evaluate(() => { window.failRefresh = true; });
  await page.locator('.modal button[type="submit"]').click();
  await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').waitFor();
  assert.equal(posts.length, 1);
  assert.equal(await page.locator('#other input').inputValue(), 'Unrelated draft');
  await page.evaluate(() => { window.failRefresh = false; });
  await page.locator('.synapse-toast-action').click();
  await page.locator('#result').waitFor();
  assert.equal(posts.length, 1, 'Never replay a POST after a confirmed save');
  assert.equal(await page.evaluate(() => calls.filter(call => call.url).length), 2);
  assert.equal(await page.evaluate(() => calls.filter(call => 'saved' in call).length), 1);
});

for (const kind of ['generic', 'carousel']) {
  test(`${kind}: pending POST freezes native and custom controls; JSON failure restores original states`, async t => {
    const { page, posts } = await fixture(t, kind);
    const input = page.locator(kind === 'generic' ? '.modal input[name="title"]' : '#light_path');
    const originalValue = await input.inputValue();
    await page.evaluate(() => {
      const form = document.querySelector('.modal form');
      form.insertAdjacentHTML('beforeend', '<input id="already-disabled" disabled value="Leave disabled"><button id="custom-control" type="button">Custom picker</button><div id="custom-editor" contenteditable="true">Custom draft</div>');
      window.customClicks = 0;
      document.getElementById('custom-control').addEventListener('click', () => { customClicks++; });
    });
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    t.after(() => release());
    await page.route('**/save', async route => {
      await gate;
      await route.fulfill({ status:422, contentType:'application/json', body:JSON.stringify({ success:false, message:'Correct this field' }) });
    });
    await page.locator('.modal button[type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('.modal form').getAttribute('aria-busy') === 'true');
    assert.equal(posts.length, 1);
    assert.equal(await page.evaluate(() => document.querySelector('.modal form').inert), true);
    assert.equal(await input.isDisabled(), true);
    await assert.rejects(input.fill('Edit after snapshot', { timeout:300 }), /Timeout/);
    await assert.rejects(page.locator('#custom-editor').click({ timeout:300 }), /Timeout/);
    assert.equal(await page.locator('#custom-editor').textContent(), 'Custom draft');
    await assert.rejects(page.locator('#custom-control').click({ timeout:300 }), /Timeout/);
    assert.equal(await page.evaluate(() => customClicks), 0);
    assert.equal(await input.inputValue(), originalValue);
    assert.equal(await page.locator('#other input').isEnabled(), true);
    assert.equal(await page.evaluate(() => document.getElementById('other').inert), false);
    assert.equal(await page.evaluate(() => document.getElementById('main').inert), false);
    await page.locator('[data-app-modal-close]').click();
    assert.equal(await page.locator('.modal.show').count(), 1, 'Close stays blocked while the write is pending');
    release();
    await page.waitForFunction(() => !document.querySelector('.modal form').hasAttribute('aria-busy'));
    assert.equal(await page.evaluate(() => document.querySelector('.modal form').inert), false);
    assert.equal(await input.isEnabled(), true);
    assert.equal(await page.locator('#already-disabled').isDisabled(), true);
    assert.equal(await page.locator('.modal button[type="submit"]').isEnabled(), true);
    assert.equal(await input.inputValue(), originalValue);
    await page.locator('#custom-control').click();
    assert.equal(await page.evaluate(() => customClicks), 1);
    await input.fill(kind === 'generic' ? 'Corrected draft' : 'assets/img/brand/corrected.png');
    assert.equal(await page.evaluate(() => calls.length), 0);
  });

  test(`${kind}: network failure restores a pre-existing inert form and disabled states`, async t => {
    const { page, posts } = await fixture(t, kind);
    await page.route('**/save', route => route.abort('failed'));
    await page.evaluate(() => {
      const form = document.querySelector('.modal form');
      form.insertAdjacentHTML('beforeend', '<input id="already-disabled" disabled>');
      form.inert = true;
      form.requestSubmit(form.querySelector('[type="submit"]'));
    });
    await page.waitForFunction(() => !document.querySelector('.modal form').hasAttribute('aria-busy'));
    assert.equal(posts.length, 1);
    assert.equal(await page.evaluate(() => document.querySelector('.modal form').inert), true);
    assert.equal(await page.locator('#already-disabled').isDisabled(), true);
    assert.equal(await page.locator('.modal button[type="submit"]').isEnabled(), true);
    assert.equal(await page.evaluate(() => calls.length), 0);
  });
}

test('JSON validation error retains modal inputs and permits a corrected submission', async t => {
  const { page, posts } = await fixture(t, 'generic');
  await page.route('**/save', route => route.fulfill({ status:422, contentType:'application/json', body:JSON.stringify({success:false, message:'Correct this field'}) }));
  await page.locator('.modal input[name="title"]').fill('Keep my draft');
  await page.locator('.modal button[type="submit"]').click();
  await page.waitForFunction(() => document.querySelector('[data-modal-error]').textContent === 'Correct this field');
  assert.equal(await page.locator('.modal input[name="title"]').inputValue(), 'Keep my draft');
  assert.equal(await page.locator('.modal button[type="submit"]').isEnabled(), true);
  assert.equal(await page.evaluate(() => calls.length), 0);
  await page.unroute('**/save');
  await page.locator('.modal button[type="submit"]').click();
  await page.locator('#result').waitFor();
  assert.equal(posts.length, 2);
});

for (const kind of ['availability', 'bmg']) {
  test(`${kind}: failed refresh locks the stale form without replaying its write`, async t => {
    const { page, posts } = await fixture(t, kind);
    await page.evaluate(() => { window.failRefresh = true; });
    await page.locator('.modal button[type="submit"]').click();
    await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').waitFor();
    assert.equal(await page.locator('.modal button[type="submit"]').isDisabled(), true);
    await page.evaluate(() => document.querySelector('.modal form').dispatchEvent(new Event('submit', { bubbles:true, cancelable:true })));
    assert.equal(posts.length, 1);
    await page.evaluate(() => { window.failRefresh = false; });
    await page.locator('.synapse-toast-action').click();
    await page.locator('#result').waitFor();
    assert.equal(posts.length, 1);
  });
}

test('edits made during POST prevent the subsequent GET without losing the confirmed save', async t => {
  const { page, posts } = await fixture(t, 'generic');
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  await page.route('**/save', async route => { await gate; await route.continue(); });
  await page.locator('.modal button[type="submit"]').click();
  await page.evaluate(() => { const input = document.querySelector('#other input'); input.value = 'New edit during POST'; input.dispatchEvent(new Event('input', { bubbles:true })); });
  release();
  await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').waitFor();
  assert.equal(posts.length, 1);
  assert.equal(await page.evaluate(() => calls.filter(call => call.url).length), 0);
  assert.equal(await page.locator('#other input').inputValue(), 'New edit during POST');
  await page.evaluate(() => dirty.delete(document.getElementById('other')));
  await page.locator('.synapse-toast-action').click();
  await page.locator('#result').waitFor();
  assert.equal(posts.length, 1);
});

test('direct-route cancel still returns to its declared return URL', async t => {
  const { page, posts } = await fixture(t, 'generic');
  await page.locator('[data-app-modal-close]').click();
  await page.waitForURL('**/cancelled');
  assert.equal(posts.length, 0);
});

test('no-helper fallback omits feedback header and retains server redirect', async t => {
  const { page, posts } = await fixture(t, 'generic', '?fallback');
  await page.locator('.modal button[type="submit"]').click();
  await page.waitForURL('**/result?date=2026-11-01');
  assert.equal(posts.length, 1);
  assert.equal(posts[0].headers()['x-synapse-feedback'], undefined);
});
