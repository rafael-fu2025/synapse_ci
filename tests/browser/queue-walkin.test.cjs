'use strict';

// Run: node --test tests/browser/queue-walkin.test.cjs
// Only production assets and extracted view code run; no PHP server, DB or devices.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin, modalMarkup, pageScript;
const people = [
  { id: 11, first_name: 'Ada', last_name: 'Student', mis_id: 'S001', kind: 'student' },
  { id: 22, first_name: 'Grace', last_name: 'Employee', mis_id: 'E002', kind: 'employee' }
];

function shell(other = false) {
  return `<!doctype html><html><head><meta charset="utf-8"><title>Queue fixture</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <script defer src="/assets/vendor/alpine/alpine.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style></head>
    <body data-navigation-scope="queue-fixture" hx-boost="false">
    <div id="page-styles" hidden></div><header id="header">Fixture</header>
    <aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link" href="/queue">Queue</a>
    <a class="nav-link" href="/other">Other module</a></nav></aside>
    <main id="main"><h1>${other ? 'Other module' : 'Queue'}</h1>${other ? '' : `
      <button type="button" data-bs-toggle="modal" data-bs-target="#walkinModal">New Walk-in</button>
      <form id="unrelated" method="post" action="/never"><label>Unrelated note<input name="note"></label></form>
      ${modalMarkup}`}</main>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/navigation.js"></script>
    <div id="page-scripts">${other ? '' : `<script>${pageScript}</script>`}</div></body></html>`;
}

before(async () => {
  const view = fs.readFileSync(path.join(root, 'app/Views/queue/index.php'), 'utf8');
  const modal = view.match(/<!-- Walk-In[^]*?(?=<\?=\s*\$this->endSection\(\)\s*\?>)/);
  assert.ok(modal, 'Extract the real Walk-In modal through its section end');
  modalMarkup = modal[0].replace(/<\?=[^]*?\?>/g, tag => tag.includes('site_url') ? '/queue/walkin' : '');
  const scripts = [...view.matchAll(/<script\b[^>]*>([^]*?)<\/script>/g)];
  pageScript = scripts.find(match => /function walkinSearch\(/.test(match[1]))?.[1];
  assert.ok(pageScript, 'Use the production global walkinSearch factory before Alpine starts');
  pageScript = pageScript.replace(/<\?=\s*site_url\(['"]\/api\/patients\/search\?q=['"]\)\s*\?>/g, '/api/patients/search?q=');
  assert.doesNotMatch(modalMarkup + pageScript, /<\?/);
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    // A native ticket POST must be intercepted in the browser, never processed here.
    if (req.method !== 'GET') { res.writeHead(405).end('No writes permitted'); return; }
    if (url.pathname === '/queue' || url.pathname === '/other') {
      res.writeHead(200, { 'Content-Type': 'text/html' }).end(shell(url.pathname === '/other')); return;
    }
    if (url.pathname === '/api/patients/search') {
      const q = url.searchParams.get('q').toLowerCase();
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({
        results: people.filter(p => `${p.first_name} ${p.last_name} ${p.mis_id}`.toLowerCase().includes(q))
      })); return;
    }
    const assets = path.join(root, 'public/assets');
    const file = path.resolve(root, 'public', '.' + url.pathname);
    if (!file.startsWith(assets + path.sep)) { res.writeHead(404).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : 'text/css' }).end(content);
    });
  });
  server.requestTimeout = 10000;
  server.headersTimeout = 10000;
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(dir) && fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install Playwright Chromium before running walk-in tests');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });

after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
}, { timeout: 20000 });

async function pageFor(t, acceptDialogs = false) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
  const errors = [], unexpected = [], searches = [], dialogs = [], documents = [];
  await context.route('**/*', route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin || request.method() !== 'GET') {
      unexpected.push(`${request.method()} ${url.href}`);
      return route.abort();
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(4000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/api/patients/search') searches.push(request.url());
    if (request.isNavigationRequest()) documents.push(request.url());
  });
  page.on('dialog', async dialog => {
    dialogs.push(dialog.message());
    if (acceptDialogs) await dialog.accept(); else await dialog.dismiss();
  });
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, [], 'No production JavaScript/Alpine errors');
    assert.deepEqual(unexpected, [], 'No external, app write, or hardware requests');
  });
  await page.goto(origin + '/queue');
  await page.waitForFunction(() => Boolean(history.state?.__synapseNavigation));
  return { page, searches, dialogs, documents };
}

async function open(page) {
  const shown = page.evaluate(() => new Promise(resolve => document.querySelector('#walkinModal')
    .addEventListener('shown.bs.modal', resolve, { once: true })));
  await page.getByRole('button', { name: 'New Walk-in' }).click();
  await shown;
}
const search = page => page.locator('#walkinModal input[x-model="searchTerm"]');
const issue = page => page.locator('#walkinModal button[type="submit"]');
async function select(page, person = people[0]) {
  await search(page).fill(person.mis_id);
  await page.locator('#walkinModal .list-group-item').filter({ hasText: person.mis_id }).click();
  assert.equal(await page.locator('#walkinModal input[name="person_id"]').inputValue(), String(person.id));
  assert.equal(await issue(page).isEnabled(), true);
}
async function pristine(page) {
  assert.equal(await search(page).inputValue(), '', 'Search draft cleared');
  assert.equal(await page.locator('#walkinModal input[name="person_id"]').inputValue(), '', 'Selection cleared');
  assert.equal(await page.locator('#priRoutine').isChecked(), true, 'Priority reset to Routine');
  assert.equal(await page.locator('#walkinModal .list-group-item').count(), 0, 'Results cleared');
  assert.equal(await page.locator('#walkinModal .card').isVisible(), false);
  assert.equal(await issue(page).isDisabled(), true);
}
async function dismiss(page, method) {
  const hidden = page.evaluate(() => new Promise(resolve => document.querySelector('#walkinModal')
    .addEventListener('hidden.bs.modal', resolve, { once: true })));
  if (method === 'Escape') await page.keyboard.press('Escape');
  else if (method === 'backdrop') await page.locator('#walkinModal').click({ position: { x: 8, y: 8 } });
  else await page.locator('#walkinModal').getByRole('button', { name: method, exact: true }).click();
  await hidden;
  await page.locator('#walkinModal').waitFor({ state: 'hidden' });
}

for (const [index, method] of ['Cancel', 'Close', 'Escape', 'backdrop'].entries()) {
  test(`${method} discards the walk-in draft without a false navigation warning`, { timeout: 15000 }, async t => {
    const { page, dialogs, documents } = await pageFor(t);
    await open(page);
    await select(page, people[index % people.length]);
    await page.locator('#priEmergency').check();
    await dismiss(page, method);
    await pristine(page);
    await open(page);
    await pristine(page);
    await dismiss(page, 'Cancel');
    await page.getByRole('link', { name: 'Other module', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('#main h1').textContent === 'Other module');
    assert.deepEqual(dialogs, [], 'Discard emits a bubbling reset that clears navigation dirty state');
    assert.equal(documents.length, 1, 'Other module uses production same-document navigation');
  });
}

test('discarding a walk-in preserves an unrelated dirty form warning', { timeout: 15000 }, async t => {
  const { page, dialogs } = await pageFor(t);
  await page.getByRole('textbox', { name: 'Unrelated note' }).fill('Keep this edit');
  await open(page);
  await select(page, people[1]);
  await page.locator('#priUrgent').check();
  await dismiss(page, 'Cancel');
  await page.getByRole('link', { name: 'Other module', exact: true }).click();
  assert.equal(dialogs.length, 1);
  assert.match(dialogs[0], /unsaved changes/i);
  assert.equal(await page.getByRole('textbox', { name: 'Unrelated note' }).inputValue(), 'Keep this edit');
  assert.equal(await page.locator('#main h1').textContent(), 'Queue');
});

async function holdSearch(page) {
  // Hold the decoded result even if the transport has completed: abort alone must
  // not let an already-resolved response repopulate a discarded draft.
  await page.evaluate(() => {
    const fetch = window.fetch;
    window.fetch = async (...args) => {
      const response = await fetch(...args);
      if (String(args[0]).includes('/api/patients/search') && !window.searchHeld) {
        args[1]?.signal?.addEventListener('abort', () => { window.searchAborted = true; });
        const json = response.json.bind(response);
        response.json = async () => {
          const data = await json();
          window.searchHeld = true;
          await new Promise(resolve => { window.releaseSearch = resolve; });
          return data;
        };
      }
      return response;
    };
  });
}

test('close aborts a pending search, ignores its late result and suppresses closed debounce', { timeout: 15000 }, async t => {
  const { page, searches } = await pageFor(t);
  await holdSearch(page);
  await open(page);
  await search(page).fill('S001');
  await page.waitForFunction(() => window.searchHeld);
  await dismiss(page, 'Cancel');
  assert.equal(await page.evaluate(() => window.searchAborted), true, 'Closing aborts the active request');
  await page.evaluate(() => window.releaseSearch());
  await page.waitForTimeout(400);
  await pristine(page);
  await open(page);
  await search(page).fill('E002');
  await dismiss(page, 'Escape');
  await page.waitForTimeout(450);
  assert.equal(searches.length, 1, 'A debounce firing after close must not start another search');
  await open(page);
  await pristine(page);
});

test('changing the query aborts the old search and rejects its late results', { timeout: 15000 }, async t => {
  const { page } = await pageFor(t);
  await holdSearch(page);
  await open(page);
  await search(page).fill('S001');
  await page.waitForFunction(() => window.searchHeld);
  await search(page).fill('E002');
  await page.locator('#walkinModal .list-group-item').filter({ hasText: 'E002' }).waitFor();
  assert.equal(await page.evaluate(() => window.searchAborted), true, 'The newer query aborts the old request');
  await page.evaluate(() => window.releaseSearch());
  await page.waitForTimeout(400);
  assert.equal(await page.locator('#walkinModal .list-group-item').count(), 1);
  assert.match(await page.locator('#walkinModal .list-group-item').textContent(), /E002/);
});

test('selected person suppresses search until Change clears the selection and results', { timeout: 15000 }, async t => {
  const { page, searches } = await pageFor(t);
  await open(page);
  await select(page);
  const searchCount = searches.length;
  await search(page).fill('E002');
  await page.waitForTimeout(450);
  assert.equal(searches.length, searchCount, 'Editing search while selected must not start a request');
  assert.equal(await page.locator('#walkinModal input[name="person_id"]').inputValue(), '11');
  await page.locator('#walkinModal').getByRole('button', { name: 'Change', exact: true }).click();
  assert.equal(await page.locator('#walkinModal .list-group-item').count(), 0);
  assert.equal(await page.locator('#walkinModal input[name="person_id"]').inputValue(), '');
  assert.equal(await issue(page).isDisabled(), true);
  await select(page, people[1]);
  assert.equal(searches.length, searchCount + 1, 'A fresh search works after Change');
  assert.match(await page.locator('#walkinModal .card').textContent(), /Employee, Grace/);
});

test('destroy aborts page-owned searches during production module navigation', { timeout: 15000 }, async t => {
  const { page, documents } = await pageFor(t, true);
  await holdSearch(page);
  await open(page);
  await search(page).fill('S001');
  await page.waitForFunction(() => window.searchHeld);
  // The sidebar is obscured by the modal; use the public production navigation API.
  await page.evaluate(() => SynapseNavigation.navigate('/other'));
  await page.waitForFunction(() => document.querySelector('#main h1').textContent === 'Other module');
  assert.equal(await page.evaluate(() => window.searchAborted), true, 'Alpine destroy aborts the active search');
  await page.evaluate(() => window.releaseSearch());
  await page.waitForTimeout(400);
  assert.equal(await page.locator('#walkinModal').count(), 0);
  await page.getByRole('link', { name: 'Queue', exact: true }).click();
  await page.getByRole('button', { name: 'New Walk-in' }).waitFor();
  await open(page);
  await pristine(page);
  assert.equal(documents.length, 1, 'Destruction and return stay within the shell');
});

test('native submit keeps Bootstrap open while busy without creating a ticket', { timeout: 15000 }, async t => {
  const { page } = await pageFor(t);
  let release;
  const held = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  await page.route('**/queue/walkin', async route => { await held; await route.abort(); });
  await open(page);
  await select(page);
  const submitted = page.waitForRequest(request => request.method() === 'POST' && request.url() === origin + '/queue/walkin');
  // Evaluate the hide attempt in the submitting document; a pending document
  // navigation can otherwise block subsequent Playwright evaluations.
  const attempt = page.evaluate(() => new Promise(resolve => {
    const modal = document.querySelector('#walkinModal');
    modal.addEventListener('hide.bs.modal', event => {
      queueMicrotask(() => resolve({
        prevented: event.defaultPrevented,
        open: modal.classList.contains('show'),
        personId: modal.querySelector('input[name="person_id"]').value
      }));
    }, { once: true });
    modal.querySelector('button[type="submit"]').click();
    queueMicrotask(() => bootstrap.Modal.getInstance(modal).hide());
  }));
  const [request, state] = await Promise.all([submitted, attempt]);
  assert.equal(new URLSearchParams(request.postData()).get('person_id'), '11');
  assert.deepEqual(state, { prevented: true, open: true, personId: '11' });
  release();
});

test('a prevented submit releases busy state so the draft can be dismissed', { timeout: 15000 }, async t => {
  const { page } = await pageFor(t);
  await open(page);
  await select(page);
  await page.evaluate(() => {
    document.querySelector('#walkinModal form').addEventListener('submit', event => {
      window.submitSeen = true;
      event.preventDefault();
    }, { once: true });
  });
  await issue(page).click();
  assert.equal(await page.evaluate(() => window.submitSeen), true);
  await page.waitForFunction(() => !document.querySelector('#walkinModal button[type="submit"]').disabled,
    null, { timeout: 4000 });
  await dismiss(page, 'Cancel');
  await open(page);
  await pristine(page);
});
