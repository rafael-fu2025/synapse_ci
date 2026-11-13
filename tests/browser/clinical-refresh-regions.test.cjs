'use strict';

// Synthetic owner fixture only: no application server, patient data, or stock database.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { once } = require('node:events');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
let browser;
let server;
let origin;
let version = 0;
let posts = 0;

function send(res, status, type, body) {
  res.writeHead(status, { 'Content-Type': type });
  res.end(body);
}

function owner() {
  return `<section data-refresh-owner="clinic.encounter.21" data-refresh-url="/encounter">
    <nav><button class="active" data-bs-toggle="tab" data-bs-target="#step-dispense">Dispense tab</button></nav>
    <section id="step-dispense" class="tab-pane active show">
      <div data-refresh-region="dispensed-items"><p id="dispensed-count">Dispensed ${version}</p></div>
      <div data-refresh-region="dispense-form"><form id="dispense-form" action="/dispense" method="post">
        <input type="hidden" name="idempotency_key" value="key-${version}">
        <select name="medicine_id"><option value="7">Fixture</option></select>
        <input name="quantity" value="1"><input name="instructions" value="">
        <button type="submit">Dispense</button>
      </form></div>
    </section>
  </section>`;
}

function page() {
  return `<!doctype html><html><head><title>Encounter fixture</title>
    <meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="token-${version}">
    <meta name="csrf-header" content="X-Fixture-CSRF">
    <meta name="synapse-refresh-regions-enabled" content="1">
    <meta name="synapse-refresh-region-owners" content="clinic.encounter.21">
    </head><body data-navigation-scope="clinical-fixture">
    <aside><nav class="sidebar-nav"><a class="nav-link active" href="/encounter">Encounter</a></nav></aside>
    <main id="main">${owner()}</main>
    <div id="page-styles" hidden></div><div id="page-scripts" hidden></div>
    <script>window.savedForms=[]; window.SynapseLoading={begin:()=>()=>{},clear:()=>{}};
      window.SynapsePageLifecycle={destroy:()=>{},init:()=>{}};
      window.SynapseNavigation={inspectDocument:()=>({status:'compatible'}),hasUnsavedChanges:()=>false,markSaved:f=>savedForms.push(f.id)};
      window.SynapseCsrf={headers:()=>({}),fromResponse:()=>false,sync:value=>{window.csrf=value}};
      window.SynapseToast={show:()=>{}};</script>
    <script src="/assets/js/refresh-regions.js"></script></body></html>`;
}

before(async () => {
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://fixture.local');
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, '.' + url.pathname);
      if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file)) return send(res, 404, 'text/plain', 'Missing');
      return send(res, 200, 'text/javascript', fs.readFileSync(file));
    }
    if (req.method === 'POST' && url.pathname === '/dispense') {
      posts++;
      req.resume();
      req.on('end', () => {
        version++;
        send(res, 200, 'application/json', JSON.stringify({
          success: true,
          message: 'Medicine dispensed.',
          redirect: origin + '/encounter',
          refresh: { mode: 'regions', owner: 'clinic.encounter.21', regions: ['dispensed-items', 'dispense-form'], url: origin + '/encounter' },
          csrf: { name: 'fixture_csrf', hash: `token-${version}` }
        }));
      });
      return;
    }
    if (url.pathname === '/encounter') return send(res, 200, 'text/html', page());
    send(res, 404, 'text/plain', 'Missing');
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true });
});

after(async () => {
  await browser?.close();
  if (server?.listening) {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
});

test('encounter dispensation refreshes its two atomic regions without replacing the owner or tab', async t => {
  version = 0;
  posts = 0;
  const context = await browser.newContext();
  const pageHandle = await context.newPage();
  const errors = [];
  pageHandle.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await pageHandle.goto(origin + '/encounter');
  await pageHandle.waitForFunction(() => Boolean(window.SynapseMutations));
  await pageHandle.evaluate(() => {
    window.identities = {
      main: document.querySelector('#main'),
      owner: document.querySelector('[data-refresh-owner]'),
      items: document.querySelector('[data-refresh-region="dispensed-items"]'),
      formRegion: document.querySelector('[data-refresh-region="dispense-form"]'),
    };
  });

  const result = await pageHandle.evaluate(async () => {
    const form = document.querySelector('#dispense-form');
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
    const payload = await response.json();
    return SynapseMutations.refresh(payload.redirect, payload.message, { form, descriptor: payload.refresh });
  });

  assert.equal(result, true);
  assert.equal(posts, 1);
  assert.deepEqual(await pageHandle.evaluate(() => ({
    main: identities.main === document.querySelector('#main'),
    owner: identities.owner === document.querySelector('[data-refresh-owner]'),
    items: identities.items === document.querySelector('[data-refresh-region="dispensed-items"]'),
    formRegion: identities.formRegion === document.querySelector('[data-refresh-region="dispense-form"]'),
    count: document.querySelector('#dispensed-count').textContent,
    key: document.querySelector('[name="idempotency_key"]').value,
    active: document.querySelector('[data-bs-target="#step-dispense"]').classList.contains('active'),
    savedForms,
  })), {
    main: true, owner: true, items: true, formRegion: true,
    count: 'Dispensed 1', key: 'key-1', active: true, savedForms: ['dispense-form'],
  });
});
