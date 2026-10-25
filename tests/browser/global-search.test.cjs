'use strict';

// Run: node --test tests/browser/global-search.test.cjs
// Local browser fixture only: no PHP/app server, sessions, or authorization checks.
// Real search/confirmation/header/sidebar-brand markup, CSS and Bootstrap modal lifecycle.
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

const dialogMarkup = fs.readFileSync(path.join(root, 'app/Views/partials/global_search.php'), 'utf8');
assert.doesNotMatch(dialogMarkup, /<\?/, 'Use the real PHP-free dialog verbatim');
const header = fs.readFileSync(path.join(root, 'app/Views/partials/header.php'), 'utf8');
const triggerMarkup = header.match(/<button\b[^>]*data-search-open\b[^>]*>[\s\S]*?<\/button>/)?.[0];
assert.ok(triggerMarkup, 'The real header must contain the search trigger');
assert.doesNotMatch(triggerMarkup, /<\?/);
// Preserve the real header structure; only PHP-backed controls/data
// are replaced. No fixture CSS may mask shell grid, visibility or sizing rules.
const headerPrefix = header.slice(0, header.indexOf('<?php $currentUser'));
assert.ok(headerPrefix.includes('theme-toggle'), 'Extract through the real theme control');
const headerMarkup = headerPrefix

  .replace(/<\?= \$this->include\('partials\/notifications'\) \?>/,
    '<div class="header-action-wrap dropdown"><button type="button" class="header-action" aria-label="Notifications"><i class="bi bi-bell"></i></button></div>')
  .replace(/<\?= \$this->include\('partials\/workstation_switcher'\) \?>/,
    '<div class="header-action-wrap dropdown"><button type="button" class="header-action" aria-label="Switch workstation"><i class="bi bi-grid"></i></button></div>')
  + `<div class="header-action-wrap dropdown user-dropdown">
      <button type="button" class="dropdown-toggle user-trigger" aria-label="User account and profile menu" aria-expanded="false">
        <img src="/fixture-avatar.svg" alt="User Avatar" class="user-avatar">
        <div class="user-brief d-none d-md-flex text-start"><span class="user-name">Test User</span><span class="user-role">Clinic Staff</span></div>
      </button>
    </div></div></div></header>`;
assert.doesNotMatch(headerMarkup, /<\?/, 'Sanitized header must contain no unevaluated PHP');
const sidebar = fs.readFileSync(path.join(root, 'app/Views/partials/sidebar.php'), 'utf8');
const sidebarPrefix = sidebar.match(/<aside\b[^>]*>[\s\S]*?<ul class="nav-menu">/)?.[0];
assert.ok(sidebarPrefix?.includes('sidebar-brand-row'), 'Extract the real sidebar shell and brand before navigation');
const sidebarMarkup = sidebarPrefix
  .replace(/<\?= site_url\('\/'\) \?>/g, '/')
  .replace(/<\?= base_url\('([^']+)'\) \?>/g, '/$1');
assert.doesNotMatch(sidebarMarkup, /<\?/, 'Sanitized sidebar must contain no unevaluated PHP');
const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/app.php'), 'utf8');
const confirmStart = layout.indexOf('<div class="modal fade" id="synapseConfirmModal"');
const confirmEnd = layout.indexOf('<!-- Vendor JS Files -->', confirmStart);
assert.notEqual(confirmStart, -1, 'Real confirmation modal exists');
assert.notEqual(confirmEnd, -1, 'Confirmation modal precedes vendor scripts');
const confirmationMarkup = layout.slice(confirmStart, layout.lastIndexOf('</div>', confirmEnd) + '</div>'.length).trim();
assert.doesNotMatch(confirmationMarkup, /<\?/);
const publicRoot = path.join(root, 'public');
const mime = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png',
  '.svg': 'image/svg+xml', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf' };

// Same package and Windows Chromium-cache convention as confirm.test.cjs.
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

function fixtureHtml() {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/style.css"></head><body class="app-shell">
    ${headerMarkup}
    ${sidebarMarkup}
      <li><a class="nav-link" href="/personal/appointments"><span class="nav-text">Appointments</span></a></li>
      <li class="nav-heading">Clinic Workstation</li>
      <li><a class="nav-link" href="/clinic/appointments"><span aria-hidden="true">ICON</span><span class="nav-text">Appointments</span></a></li>
      <li><a class="nav-link" href="/clinic/cafe" data-navigation="full" target="_self" rel="nofollow"><span class="nav-text">Café reports</span></a></li>
      <li class="nav-heading">Guidance Workspace</li>
      <li><a class="nav-link" href="/guidance/cases"><span class="nav-text">Cases</span></a></li>
    </ul></nav></div></aside>
    <template hidden data-search-actions>
      <a href="/clinic/appointments/create" data-search-label="Create appointment" data-search-context="Clinic Workstation" data-app-modal-open>Ignored action text</a>
      <a href="/guidance/cases/create" data-search-label="Open new case" data-search-context="Guidance Workspace" data-app-modal-open>Ignored case text</a>
    </template>
    <main id="main" class="main"><button id="prior-focus">Prior focus</button>
      <a class="nav-link" href="/admin/secrets"><span class="nav-text">Secret admin</span></a>
      <a href="/unscoped/action" data-search-label="Unscoped action" data-search-context="Admin">Outside action source</a>
      <ul class="nav-menu"><li><a class="nav-link" href="/outside">Outside sidebar</a></li></ul>
    </main>
    <div id="existing-modal" class="modal fade" tabindex="-1" aria-hidden="true" aria-labelledby="existing-modal-title">
          <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h2 id="existing-modal-title" class="modal-title">Create appointment</h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close form"></button></div>
            <div class="modal-body"><form id="handoff-form"><label for="existing-modal-input">Appointment name</label>
              <input id="existing-modal-input" name="name" required><button type="submit">Save appointment</button></form></div>
          </div></div>
        </div>
    <dialog id="other-dialog"><input aria-label="Other dialog input"></dialog>
    ${dialogMarkup}
    ${confirmationMarkup}
    <!-- Keep forward Tab in the document so Bootstrap can handle focusin;
         browser chrome is outside the scope of a DOM focus trap. -->
    <button id="after-modal-focus" type="button">Outside modal focus target</button>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
      window.modalEvents = [];
      for (const id of ['globalSearchDialog', 'existing-modal']) {
        const modal = document.getElementById(id);
        for (const type of ['show', 'shown', 'hide', 'hidden']) {
          modal.addEventListener(type + '.bs.modal', () => window.modalEvents.push({ id, type }));
        }
      }
    </script>
    <script src="/assets/js/global-search.js"></script>
    <script>
      window.activations = [];
      window.submissions = [];
      const formModal = document.getElementById('existing-modal');
      formModal.addEventListener('shown.bs.modal', () => document.getElementById('existing-modal-input').focus());
      document.getElementById('handoff-form').addEventListener('submit', event => {
        event.preventDefault();
        window.submissions.push(new FormData(event.target).get('name'));
      });
      document.addEventListener('click', event => {
        const link = event.target.closest('a[data-search-index]');
        if (!link) return;
        event.preventDefault();
        window.activations.push({ path: new URL(link.href).pathname,
          modal: link.hasAttribute('data-app-modal-open'),
          show: !!document.querySelector('.modal.show'),
          backdrop: !!document.querySelector('.modal-backdrop'),
          modalOpen: document.body.classList.contains('modal-open'),
          hidden: window.modalEvents.filter(event => event.id === 'globalSearchDialog').at(-1)?.type === 'hidden',
          originalResult: link === window.expectedResult });
        if (window.openFormOnAction && link.hasAttribute('data-app-modal-open')) {
          bootstrap.Modal.getOrCreateInstance(formModal).show();
        }
      });
    </script></body></html>`;
}

let browser, server, origin;
before(async () => {
  const executable = executablePath();
  console.log(`Global search tests Chromium: ${executable}`);
  browser = await playwright.chromium.launch({ headless: true, executablePath: executable, timeout: 20_000 });
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(fixtureHtml());
    } else if (url.pathname === '/fixture-avatar.svg') {
      res.writeHead(200, { 'Content-Type': 'image/svg+xml' });
      res.end('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><circle cx="16" cy="16" r="16" fill="#723"/></svg>');
    } else if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
      if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
        res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
        res.end(fs.readFileSync(file));
      } else {
        res.writeHead(404);
        res.end();
      }
    } else {
      res.writeHead(404);
      res.end();
    }
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
}, { timeout: 120_000 });

after(async () => {
  try { if (browser) await browser.close(); }
  finally {
    if (server) {
      const closed = new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
      server.closeAllConnections();
      await closed;
    }
  }
}, { timeout: 30_000 });

async function openFixture(t, viewport = { width: 1280, height: 900 }) {
  const context = await browser.newContext({ viewport });
  t.after(() => context.close());
  const page = await context.newPage();
  page.setDefaultTimeout(5_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => {
    if (response.url().includes('/assets/') && response.status() >= 400) {
      errors.push(`Asset HTTP ${response.status()}: ${response.url()}`);
    }
  });
  page.on('requestfailed', request => errors.push(`Request failed: ${request.url()}`));
  t.after(() => assert.deepEqual(errors, [], 'No browser runtime errors'));
  await page.goto(origin, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  const loadedStyles = await page.evaluate(() => {
    const urls = [];
    function visit(sheet) {
      urls.push(new URL(sheet.href).pathname);
      for (const rule of sheet.cssRules) if (rule.styleSheet) visit(rule.styleSheet);
    }
    for (const sheet of document.styleSheets) visit(sheet);
    return urls;
  });
  for (const asset of ['/assets/vendor/bootstrap/css/bootstrap.min.css', '/assets/css/style.css',
    '/assets/css/core/layout.css', '/assets/css/global-search.css']) {
    assert.ok(loadedStyles.includes(asset), `Real stylesheet loaded, including imports: ${asset}`);
  }
  const dialog = page.locator('#globalSearchDialog');
  const input = page.locator('#globalSearchInput');
  const trigger = page.locator('[data-search-open]');
  assert.equal(await trigger.isVisible(), true);
  assert.equal(await page.evaluate(() => typeof bootstrap.Modal), 'function', 'Real Bootstrap bundle loaded');
    assert.equal(await dialog.evaluate(el => el.tagName), 'DIV', 'Search is a Bootstrap modal, not a native dialog');
    assert.equal(await dialog.getAttribute('tabindex'), '-1');
    assert.equal(await dialog.getAttribute('aria-hidden'), 'true');
    assert.equal(await dialog.evaluate(el => el.matches('.modal.fade')), true);
    assert.equal(await dialog.locator('.modal-dialog.modal-dialog-centered.modal-dialog-scrollable').count(), 1);
    assert.equal(await dialog.locator('.modal-content.border-0.shadow').count(), 1);
    assert.equal(await dialog.locator('.modal-header').count(), 1);
    assert.equal(await dialog.locator('.modal-body').count(), 1);
    assert.equal(await dialog.locator('.modal-footer').count(), 1);
    assert.equal(await dialog.locator('[data-search-close]').getAttribute('data-bs-dismiss'), 'modal');
    assert.equal(await dialog.locator('.modal-dialog').evaluate(el => getComputedStyle(el).maxWidth), '620px');
  return { page, dialog, input, trigger };
}

async function assertOpen({ page, dialog, input }) {
  await page.waitForFunction(() => {
    const modal = document.getElementById('globalSearchDialog');
    return modal.classList.contains('show') && modal.getAttribute('aria-modal') === 'true' &&
      window.modalEvents.filter(event => event.id === modal.id).at(-1)?.type === 'shown' &&
      document.activeElement.id === 'globalSearchInput';
  });
  assert.equal(await dialog.getAttribute('role'), 'dialog');
  assert.equal(await dialog.getAttribute('aria-hidden'), null);
  assert.equal(await page.locator('.modal-backdrop.show').count(), 1);
  assert.equal(await page.locator('body').evaluate(el => el.classList.contains('modal-open')), true);
  assert.equal(await input.evaluate(el => el === document.activeElement), true, 'Search input receives focus after shown');
  assert.equal((await page.locator('#globalSearchTitle').textContent()).trim(), 'Find a page or action');
}

async function assertHidden({ page, dialog }) {
  await page.waitForFunction(() => {
    const modal = document.getElementById('globalSearchDialog');
    return getComputedStyle(modal).display === 'none' && !modal.classList.contains('show') &&
      !document.querySelector('.modal-backdrop') && !document.body.classList.contains('modal-open') &&
      window.modalEvents.filter(event => event.id === modal.id).at(-1)?.type === 'hidden';
  });
  assert.equal(await dialog.getAttribute('aria-hidden'), 'true');
  assert.equal(await dialog.getAttribute('aria-modal'), null);
}

async function expectActivation(f, pathname, modal) {
  await f.page.waitForFunction(() => window.activations.length === 1);
  assert.deepEqual(await f.page.evaluate(() => window.activations), [{
    path: pathname, modal, show: false, backdrop: false, modalOpen: false, hidden: true, originalResult: true
  }]);
}

async function rememberResult(f) {
  await f.page.locator('[role="option"][aria-selected="true"]').evaluate(el => { window.expectedResult = el; });
}

async function labels(page, group) {
  const prefix = group ? `[role="group"][aria-label="${group}"] ` : '';
  return page.locator(`${prefix}[role="option"] > span`).allTextContents();
}

async function assertActive(input, label) {
  const id = await input.getAttribute('aria-activedescendant');
  assert.ok(id, 'Combobox exposes its active descendant');
  assert.deepEqual(await input.page().locator(`#${id}`).evaluate(el => ({
    label: el.querySelector('span').textContent, selected: el.getAttribute('aria-selected')
  })), { label, selected: 'true' });
  assert.equal(await input.page().locator('[role="option"][aria-selected="true"]').count(), 1);
}

for (const shortcut of ['Control+k', 'Meta+k']) {
  test(`${shortcut} opens Bootstrap search and refocuses without clearing the query`, { timeout: 15_000 }, async t => {
    const f = await openFixture(t);
    await f.page.locator('#prior-focus').focus();
    await f.page.keyboard.press(shortcut);
    await assertOpen(f);
    await f.input.fill('clinic');
    await f.page.locator('[data-search-close]').focus();
    await f.page.keyboard.press(shortcut);
    await assertOpen(f);
    assert.equal(await f.input.inputValue(), 'clinic');
    // The dialog handler must close on the first Escape, even with a query.
    await f.page.keyboard.press('Escape');
    await assertHidden(f);
    await f.page.waitForFunction(() => document.activeElement.id === 'prior-focus');
    assert.equal(await f.input.getAttribute('aria-expanded'), 'false');
    assert.equal(await f.input.getAttribute('aria-activedescendant'), null);
  });
}

test('trigger groups only rendered sidebar pages and explicitly scoped actions', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  assert.deepEqual(await f.page.locator('[role="group"]').evaluateAll(nodes => nodes.map(el => el.getAttribute('aria-label'))), ['Pages', 'Actions']);
  assert.deepEqual(await labels(f.page, 'Pages'), ['Appointments', 'Appointments', 'Café reports', 'Cases']);
  assert.deepEqual(await labels(f.page, 'Actions'), ['Create appointment', 'Open new case']);
  assert.equal(await f.page.locator('[data-search-status]').textContent(), '6 results available.');
  assert.deepEqual(await f.page.locator('[role="option"]').evaluateAll(nodes => nodes.map(el => new URL(el.href).pathname)), [
    '/personal/appointments', '/clinic/appointments', '/clinic/cafe', '/guidance/cases',
    '/clinic/appointments/create', '/guidance/cases/create'
  ]);
  await f.page.locator('[data-search-close]').click();
  await assertHidden(f);
  await f.page.waitForFunction(() => document.activeElement.matches('[data-search-open]'));
});

test('tokens match label, group and workspace context with case/accent normalization', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  for (const [query, expected] of [
    ['  APPOINTMENT   clinic  ', ['Appointments', 'Create appointment']],
    ['workspace cases pages', ['Cases']],
    ['WORKSPACE new actions', ['Open new case']],
    ['personal health appointment', ['Appointments']],
    ['CAFE clinic pages', ['Café reports']],
    ['clinic workspace', []]
  ]) {
    await f.input.fill(query);
    assert.deepEqual(await labels(f.page), expected, query);
  }
});

test('arrows wrap and Enter activates the original result only after hidden.bs.modal', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  await f.input.fill('clinic appointment');
  await assertActive(f.input, 'Appointments');
  await f.input.press('ArrowUp');
  await assertActive(f.input, 'Create appointment');
  await f.input.press('ArrowDown');
  await assertActive(f.input, 'Appointments');
  await f.input.press('ArrowDown');
  await assertActive(f.input, 'Create appointment');
  assert.equal(await f.page.locator('[role="option"][aria-selected="true"]').getAttribute('data-app-modal-open'), '');
  await rememberResult(f);
  await f.input.press('Enter');
  await assertHidden(f);
  await expectActivation(f, '/clinic/appointments/create', true);
});

test('click activation preserves navigation attributes and delegates only after hidden.bs.modal', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  await f.input.fill('cafe');
  const result = f.page.locator('[role="option"]');
  assert.deepEqual(await result.evaluate(el => ['data-navigation', 'target', 'rel'].map(name => el.getAttribute(name))), ['full', '_self', 'nofollow']);
  await rememberResult(f);
  await result.locator('span').click();
  await assertHidden(f);
  await expectActivation(f, '/clinic/cafe', false);
});

test('no results clears active state and Enter/arrows cannot activate stale results', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  await f.input.fill('not-a-rendered-destination');
  assert.deepEqual(await labels(f.page), []);
  assert.equal(await f.page.locator('[data-search-empty]').isVisible(), true);
  assert.match(await f.page.locator('[data-search-empty]').textContent(), /No matching pages or actions/);
  assert.equal(await f.page.locator('[data-search-status]').textContent(), '0 results available.');
  assert.equal(await f.input.getAttribute('aria-expanded'), 'false');
  assert.equal(await f.input.getAttribute('aria-activedescendant'), null);
  for (const key of ['ArrowDown', 'ArrowUp', 'Enter']) await f.input.press(key);
  assert.deepEqual(await f.page.evaluate(() => window.activations), []);
  await assertOpen(f);
  await f.input.fill('cafe');
  assert.equal(await f.page.locator('[data-search-empty]').isVisible(), false);
  assert.equal(await f.page.locator('[data-search-status]').textContent(), '1 result available.');
  assert.equal(await f.input.getAttribute('aria-expanded'), 'true');
  await assertActive(f.input, 'Café reports');
});

test('repeated opens reset query and selection and refresh sidebar/action sources', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  await f.input.fill('clinic');
  await f.input.press('ArrowDown');
  await f.page.locator('[data-search-close]').click();
  await assertHidden(f);
  await f.page.evaluate(() => {
    document.querySelector('#app-sidebar .nav-menu').innerHTML = '<li class="nav-heading">Facilities Workspace</li><li><a class="nav-link" href="/facilities/devices"><span class="nav-text">Devices</span></a></li>';
    document.querySelector('[data-search-actions]').innerHTML = '<a href="/facilities/devices/create" data-search-label="Register device" data-search-context="Facilities Workspace" data-app-modal-open>Register</a>';
  });
  for (let attempt = 0; attempt < 2; attempt++) {
    await f.trigger.click();
    await assertOpen(f);
    assert.equal(await f.input.inputValue(), '');
    assert.deepEqual(await labels(f.page), ['Devices', 'Register device']);
    await assertActive(f.input, 'Devices');
    await f.input.fill('workspace device');
    assert.deepEqual(await labels(f.page), ['Devices', 'Register device']);
    await f.input.press('Escape');
    await assertHidden(f);
  }
});

test('mobile trigger and centered modal content fit the viewport with stacked results', { timeout: 15_000 }, async t => {
  const f = await openFixture(t, { width: 375, height: 667 });
  const triggerBox = await f.trigger.boundingBox();
  assert.ok(triggerBox.width > 0 && triggerBox.height > 0);
  assert.ok(triggerBox.x >= 0 && triggerBox.x + triggerBox.width <= 375);
  assert.equal(await f.page.locator('.global-search-trigger-label').isVisible(), false);
  assert.equal(await f.page.locator('[data-search-shortcut]').isVisible(), false);
  await f.trigger.click();
  await assertOpen(f);
  const box = await f.dialog.locator('.modal-content').boundingBox();
  assert.ok(box.x >= 0 && box.x + box.width <= 375, JSON.stringify(box));
  assert.ok(box.y >= 0 && box.y + box.height <= 667, JSON.stringify(box));
  assert.ok(Math.abs(box.y + box.height / 2 - 667 / 2) <= 2, 'Bootstrap centers the content vertically');
  assert.equal(await f.dialog.locator('.modal-content').evaluate(el => el.scrollWidth <= el.clientWidth), true);
  assert.equal(await f.page.locator('[role="option"]').first().evaluate(el => getComputedStyle(el).flexDirection), 'column');
  await f.input.fill('workspace new');
  await rememberResult(f);
  await f.page.locator('[role="option"] small').click();
  await assertHidden(f);
  await expectActivation(f, '/guidance/cases/create', true);
});

for (const width of [320, 375, 768, 1280]) {
  test(`real app-shell header and search dialog fit without overlap at ${width}px`, { timeout: 15_000 }, async t => {
    const height = width < 768 ? 667 : 900;
    const f = await openFixture(t, { width, height });
    const geometry = await f.page.evaluate(() => {
      const rect = el => {
        const r = el.getBoundingClientRect();
        return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
      };
      const selectors = ['.sidebar-toggle', '[data-search-open]',
        '.header-right [aria-label="Notifications"]', '.header-right [aria-label="Switch workstation"]',
        '.header-right .theme-toggle', '.header-right .user-trigger'];
      return {
        header: rect(document.querySelector('.header')),
        sidebar: rect(document.querySelector('.sidebar')),
        sidebarWidth: parseFloat(getComputedStyle(document.body).getPropertyValue('--sidebar-width')),
        headerBrands: document.querySelectorAll('.header-brand').length,
        controls: selectors.map(selector => {
          const el = document.querySelector(selector);
          const r = rect(el);
          const center = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          return { selector, ...r, hit: !!center && el.contains(center) };
        }),
        scrollWidth: document.documentElement.scrollWidth,
        bodyScrollWidth: document.body.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        headerScrollWidth: document.querySelector('.header').scrollWidth,
        headerClientWidth: document.querySelector('.header').clientWidth,
        actions: document.querySelectorAll('.header-right button').length
      };
    });
    const contents = await f.trigger.evaluate(el => {
      const bounds = node => {
        const r = node.getBoundingClientRect();
        return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
      };
      return { trigger: bounds(el), parts: ['.bi-search', '.global-search-trigger-label', '[data-search-shortcut]'].map(selector => {
        const node = el.querySelector(selector);
        return { selector, ...bounds(node), visible: getComputedStyle(node).display !== 'none',
          scrollWidth: node.scrollWidth, clientWidth: node.clientWidth };
      }) };
    });
    for (const part of contents.parts) {
      const visible = width >= 768 || part.selector === '.bi-search';
      assert.equal(part.visible, visible, `${width}px ${part.selector} responsive visibility`);
      if (!visible) continue;
      assert.ok(part.width > 0 && part.height > 0, `${width}px ${part.selector} has visible content`);
      assert.ok(part.x >= contents.trigger.x && part.right <= contents.trigger.right &&
        part.y >= contents.trigger.y && part.bottom <= contents.trigger.bottom,
      `${width}px ${part.selector} fits inside the search trigger: ${JSON.stringify(contents)}`);
      assert.ok(part.scrollWidth <= part.clientWidth, `${width}px ${part.selector} is not clipped or ellipsized`);
    }
    const visibleParts = contents.parts.filter(part => part.visible);
    for (let i = 1; i < visibleParts.length; i++) {
      assert.ok(visibleParts[i - 1].right <= visibleParts[i].x, `${width}px search content does not overlap`);
    }
    console.log(`Header geometry ${width}px: ${JSON.stringify(geometry)}`);
    const problems = [];
    assert.equal(geometry.actions, 4, 'Exercise all four right-hand actions');
    assert.equal(geometry.headerBrands, 0, 'Branding moved out of the header');
    assert.equal(geometry.header.x, width >= 992 ? geometry.sidebarWidth : 0, 'Desktop header starts after the sidebar');
    assert.equal(geometry.header.right, width, 'Header reaches the viewport edge');
    assert.equal(geometry.sidebar.y, 0, 'Sidebar starts at the top of the full shell');
    for (const control of geometry.controls) {
      if (control.width <= 0 || control.height <= 0 || !control.hit) problems.push(`${control.selector} invisible or obscured`);
      if (control.x < -1 || control.right > width + 1 || control.y < -1 || control.bottom > geometry.header.bottom + 1) {
        problems.push(`${control.selector} outside viewport/header bounds`);
      }
    }
    for (let i = 0; i < geometry.controls.length; i++) {
      for (let j = i + 1; j < geometry.controls.length; j++) {
        const a = geometry.controls[i], b = geometry.controls[j];
        if (Math.min(a.right, b.right) - Math.max(a.x, b.x) > 1 &&
            Math.min(a.bottom, b.bottom) - Math.max(a.y, b.y) > 1) {
          problems.push(`${a.selector} overlaps ${b.selector}`);
        }
      }
    }
    if (Math.max(geometry.scrollWidth, geometry.bodyScrollWidth) > geometry.clientWidth + 1) problems.push('Document overflows horizontally');
    if (geometry.headerScrollWidth > geometry.headerClientWidth + 1) problems.push('Header overflows horizontally');
    // Reveal the mobile drawer without loading unrelated application shell JS.
    if (width < 992) {
      await f.page.evaluate(() => document.body.classList.add('sidebar-open'));
      await f.page.waitForFunction(() => Math.abs(document.querySelector('.sidebar').getBoundingClientRect().x) < 0.1);
    }
    const brand = await f.page.locator('.sidebar-brand').boundingBox();
    const sidebarBox = await f.page.locator('.sidebar').boundingBox();
    const brandRow = await f.page.locator('.sidebar-brand-row').boundingBox();
    const nav = await f.page.locator('.sidebar-nav').boundingBox();
    assert.ok(brand.width > 0 && brand.height > 0, 'Sidebar brand is visible');
    assert.ok(brand.x >= sidebarBox.x && brand.x + brand.width <= sidebarBox.x + sidebarBox.width,
      `Brand fits inside sidebar at ${width}px: ${JSON.stringify(brand)}`);
    assert.ok(brand.y >= brandRow.y && brand.y + brand.height <= brandRow.y + brandRow.height,
      'Brand fits inside the sidebar brand row');
    assert.ok(brandRow.y + brandRow.height <= nav.y, 'Sidebar brand precedes navigation without overlap');
    assert.equal(await f.page.locator('.sidebar-brand').evaluate(el => {
      const r = el.getBoundingClientRect();
      return el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
    }), true, 'Sidebar brand is not obscured');
    if (width < 992) {
      await f.page.evaluate(() => document.body.classList.remove('sidebar-open'));
      await f.page.waitForFunction(() => document.querySelector('.sidebar').getBoundingClientRect().right <= 0.1);
    }
    await f.trigger.click();
    await assertOpen(f);
    for (const selector of ['.modal-dialog', '.modal-content']) {
      const box = await f.dialog.locator(selector).boundingBox();
      assert.ok(box.x >= 0 && box.x + box.width <= width + 1, `${selector} horizontal fit: ${JSON.stringify(box)}`);
      assert.ok(box.y >= 0 && box.y + box.height <= height + 1, `${selector} vertical fit: ${JSON.stringify(box)}`);
      assert.equal(await f.dialog.locator(selector).evaluate(el => el.scrollWidth <= el.clientWidth), true, 'No internal horizontal overflow');
    }
    await f.input.fill('clinic');
    await f.input.press('Escape');
    await assertHidden(f);
    await f.page.waitForFunction(() => document.activeElement.matches('[data-search-open]'));
    assert.deepEqual(problems, [], `Header geometry at ${width}px`);
  });
}

for (const theme of ['light', 'dark']) {
  for (const shell of ['desktop expanded', 'desktop collapsed', 'mobile']) {
    test(`${theme} ${shell} search trigger matches normal header actions and stays neutral on hover`, { timeout: 30_000 }, async t => {
      const mobile = shell === 'mobile';
      const f = await openFixture(t, mobile ? { width: 375, height: 667 } : { width: 1280, height: 900 });
      await f.page.evaluate(({ theme, collapsed }) => {
        document.documentElement.dataset.theme = theme;
        document.body.classList.toggle('sidebar-collapsed', collapsed);
      }, { theme, collapsed: shell === 'desktop collapsed' });
      assert.equal(await f.trigger.evaluate(el => el.matches('.header-action.global-search-trigger')), true,
        'The real search trigger uses the shared header-action class');

      const controls = [f.trigger, f.page.locator('.header-right [aria-label="Notifications"]'),
        f.page.locator('.header-right .theme-toggle')];
      const settle = async () => {
        await f.page.evaluate(async () => {
          let animations;
          do {
            await new Promise(resolve => requestAnimationFrame(resolve));
            animations = document.getAnimations();
            // Theme changes can cancel and replace an in-flight transition.
            await Promise.allSettled(animations.map(animation => animation.finished));
          } while (animations.length);
        });
      };
      const read = async control => control.evaluate(el => {
        const css = getComputedStyle(el);
        const properties = ['height', 'borderTopLeftRadius', 'borderTopRightRadius',
          'borderBottomLeftRadius', 'borderBottomRightRadius', 'backgroundColor', 'color'];
        for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
          for (const property of ['Width', 'Style', 'Color']) properties.push(`border${side}${property}`);
        }
        const icon = [...el.querySelectorAll('i')].find(icon => getComputedStyle(icon).display !== 'none');

        return { ...Object.fromEntries(properties.map(name => [name, css[name]])),
          iconFontSize: icon ? getComputedStyle(icon).fontSize : null,
          iconColor: icon ? getComputedStyle(icon).color : null };
      });
      const assertContents = async state => {
        const contents = await f.trigger.evaluate(el => {
          const css = getComputedStyle(el);
          const label = getComputedStyle(el.querySelector('.global-search-trigger-label'));
          const shortcut = getComputedStyle(el.querySelector('[data-search-shortcut]'));
          const borderProbe = document.createElement('span');
          borderProbe.style.color = 'var(--border-color)';
          el.append(borderProbe);
          const borderColor = getComputedStyle(borderProbe).color;
          borderProbe.remove();
          return { color: css.color, labelColor: label.color, shortcutColor: shortcut.color, borderColor,
            shortcutBackground: shortcut.backgroundColor,
            shortcutBorders: ['Top', 'Right', 'Bottom', 'Left'].map(side => ({
              width: shortcut[`border${side}Width`], style: shortcut[`border${side}Style`],
              color: shortcut[`border${side}Color`]
            })) };
        });
        assert.equal(contents.labelColor, contents.color, `${state}: label inherits the trigger color`);
        assert.equal(contents.shortcutColor, contents.color, `${state}: shortcut inherits the trigger color`);
        assert.equal(contents.shortcutBackground, 'rgba(0, 0, 0, 0)', `${state}: shortcut background is transparent`);
        for (const border of contents.shortcutBorders) {
          assert.deepEqual(border, { width: '1px', style: 'solid', color: contents.borderColor },
            `${state}: shortcut uses the shared --border-color border`);
        }
      };

      // Move outside the header before every baseline; a previous hover must not leak into it.
      const baseline = async () => {
        await f.page.mouse.move(1, 666);
        await settle();
        for (const control of controls) assert.equal(await control.evaluate(el => el.matches(':hover')), false);
        const styles = await Promise.all(controls.map(read));
        assert.ok(styles[0].iconFontSize, 'Compare an actual visible search icon');
        assert.equal(styles[0].iconColor, styles[0].color, 'Search icon inherits the control foreground');
        assert.equal(styles[0].height, mobile ? '44px' : '34px', 'Shared responsive control height');
        assert.deepEqual(styles[0], styles[1], 'Normal search styling matches notifications');
        assert.deepEqual(styles[0], styles[2], 'Normal search styling matches the theme toggle');
        await assertContents('normal');
        return styles[0];
      };
      const normal = await baseline();
      const hovered = [];
      for (const control of controls) {
        await baseline();
        await control.hover();
        await settle();
        assert.equal(await control.evaluate(el => el.matches(':hover')), true);
        hovered.push(await read(control));
        if (control === f.trigger) await assertContents('hovered');
      }
      assert.equal(hovered[0].iconColor, hovered[0].color, 'Hovered search icon inherits the control foreground');
      assert.deepEqual(hovered[0], normal, 'Search hover preserves its normal styling without a maroon background');
      assert.deepEqual(hovered[1], hovered[2], 'Other header controls retain their shared hover styling');
      assert.notEqual(hovered[1].backgroundColor, normal.backgroundColor, 'Other header controls still change background on hover');
      await baseline();
      if (mobile) {
        const box = await f.trigger.boundingBox();
        assert.equal(box.width, 44, 'Mobile search keeps its 44px width');
        assert.equal(box.height, 44, 'Mobile search keeps its 44px height');
      }
    });
  }
}

for (const theme of ['light', 'dark']) {
  for (const width of [375, 1280]) {
    test(`${theme} ${width}px search icon sits inside the dialog input`, { timeout: 15_000 }, async t => {
      const f = await openFixture(t, { width, height: 900 });
      await f.page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
      await f.trigger.click();
      await assertOpen(f);
      const geometry = await f.input.evaluate(input => {
        const icon = input.closest('.global-search-field').querySelector('.bi');
        const field = input.getBoundingClientRect();
        const glyph = icon.getBoundingClientRect();
        const css = getComputedStyle(input);
        return { inside: glyph.left > field.left && glyph.right < field.right && glyph.top > field.top && glyph.bottom < field.bottom,
          centerOffset: Math.abs((glyph.top + glyph.bottom - field.top - field.bottom) / 2),
          textStart: field.left + parseFloat(css.paddingLeft), iconRight: glyph.right,
          pointerEvents: getComputedStyle(icon).pointerEvents, iconX: glyph.left + glyph.width / 2, iconY: glyph.top + glyph.height / 2 };
      });
      assert.equal(geometry.inside, true, 'Icon is within the input border');
      assert.ok(geometry.centerOffset <= 1, 'Icon is vertically centered');
      assert.ok(geometry.textStart > geometry.iconRight, 'Text and placeholder have room after the icon');
      assert.equal(geometry.pointerEvents, 'none', 'Decorative icon does not intercept input clicks');
      await f.page.locator('[data-search-close]').focus();
      await f.page.mouse.click(geometry.iconX, geometry.iconY);
      assert.equal(await f.input.evaluate(el => el === document.activeElement), true, 'Clicking the icon area focuses the input');
      await f.input.fill('clinic');
      assert.ok((await labels(f.page)).length > 0, 'Search continues to filter results');
    });
  }
  test(`${theme} search input and selected/hovered results use thin square-cornered styling`, { timeout: 15_000 }, async t => {
    const f = await openFixture(t);
    await f.page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
    await f.trigger.click();
    await assertOpen(f);
    await f.page.mouse.move(1, 666);
    const settle = async () => {
      await f.page.evaluate(async () => {
        let animations;
        do {
          await new Promise(resolve => requestAnimationFrame(resolve));
          animations = document.getAnimations();
          await Promise.allSettled(animations.map(animation => animation.finished));
        } while (animations.length);
      });
    };
    const read = control => control.evaluate(el => {
      const css = getComputedStyle(el);
      const probe = document.createElement('span');
      probe.style.color = 'var(--border-color)';
      el.append(probe);
      const borderColor = getComputedStyle(probe).color;
      probe.remove();
      return { boxShadow: css.boxShadow, outlineWidth: css.outlineWidth, outlineStyle: css.outlineStyle,
        radii: ['TopLeft', 'TopRight', 'BottomLeft', 'BottomRight'].map(corner => css[`border${corner}Radius`]),
        borderColor, borders: ['Top', 'Right', 'Bottom', 'Left'].map(side => ({
          width: css[`border${side}Width`], style: css[`border${side}Style`], color: css[`border${side}Color`]
        })), backgroundColor: css.backgroundColor };
    });
    await settle();
    assert.equal(await f.input.evaluate(el => el === document.activeElement), true, 'Search input is focused');
    const inputStyle = await read(f.input);
    assert.equal(inputStyle.boxShadow, 'none', 'Focused input has no thick focus shadow');
    assert.deepEqual(inputStyle.radii, ['2px', '2px', '2px', '2px'], 'Focused input uses 2px corners');

    const options = f.dialog.locator('[role="option"]');
    const selected = options.first();
    const hovered = options.nth(1);
    const firstLabel = await selected.locator('span').textContent();
    const secondLabel = await hovered.locator('span').textContent();
    await assertActive(f.input, firstLabel);
    const idle = await read(hovered);
    for (const border of idle.borders) {
      assert.deepEqual(border, { width: '1px', style: 'solid', color: 'rgba(0, 0, 0, 0)' },
        'Idle result reserves a transparent 1px border');
    }
    const assertResult = async (control, state) => {
      const style = await read(control);
      assert.deepEqual(style.radii, ['2px', '2px', '2px', '2px'], `${state}: result uses 2px corners`);
      assert.ok(style.outlineWidth === '0px' || style.outlineStyle === 'none', `${state}: no thick result outline`);
      assert.equal(style.boxShadow, 'none', `${state}: no thick result shadow`);
      for (const border of style.borders) {
        assert.deepEqual(border, { width: '1px', style: 'solid', color: style.borderColor },
          `${state}: result uses the shared 1px border`);
      }
      assert.notEqual(style.backgroundColor, idle.backgroundColor, `${state}: result remains visually distinguished`);
    };
    await assertResult(selected, 'selected');
    await hovered.hover();
    await settle();
    assert.equal(await hovered.evaluate(el => el.matches(':hover')), true);
    await assertResult(hovered, 'hovered');
    await assertActive(f.input, firstLabel);
    assert.equal(await hovered.getAttribute('aria-selected'), 'false', 'Hover does not misrepresent keyboard selection');
    await f.input.press('ArrowDown');
    await assertActive(f.input, secondLabel);
    await f.page.mouse.move(1, 666);
    await settle();
    await assertResult(hovered, 'keyboard selected after hover');
    assert.equal(await f.input.evaluate(el => el === document.activeElement), true, 'Keyboard navigation retains combobox focus');
    await f.input.press('Escape');
    await assertHidden(f);
  });
}

test('mobile search has the same minimum 44px touch target as the four header actions', { timeout: 15_000 }, async t => {
  const f = await openFixture(t, { width: 375, height: 667 });
  const sizes = await f.page.locator('[data-search-open], .header-right button').evaluateAll(nodes => nodes.map(el => ({
    name: el.getAttribute('aria-label'), width: el.getBoundingClientRect().width, height: el.getBoundingClientRect().height
  })));
  assert.equal(sizes.length, 5);
  assert.deepEqual(sizes.filter(size => size.width < 44 || size.height < 44), [], 'All mobile header controls need 44px touch targets');
});

test('shortcuts and trigger do not steal focus from an existing .modal.show', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.page.evaluate(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('existing-modal')).show());
  await f.page.waitForFunction(() => window.modalEvents.some(event => event.id === 'existing-modal' && event.type === 'shown'));
  await f.page.locator('#existing-modal-input').focus();
  for (const shortcut of ['Control+k', 'Meta+k']) {
    await f.page.keyboard.press(shortcut);
    assert.equal(await f.dialog.evaluate(el => el.classList.contains('show')), false);
    assert.equal(await f.page.locator('#existing-modal-input').evaluate(el => el === document.activeElement), true);
  }
  // Programmatic click retains the existing form's focus, unlike physically clicking a button.
  await f.trigger.evaluate(el => el.click());
  assert.equal(await f.dialog.evaluate(el => el.classList.contains('show')), false);
  assert.equal(await f.page.locator('#existing-modal-input').evaluate(el => el === document.activeElement), true);
  await f.page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('existing-modal')).hide());
  await f.page.waitForFunction(() => window.modalEvents.some(event => event.id === 'existing-modal' && event.type === 'hidden'));
  await f.page.keyboard.press('Control+k');
  await assertOpen(f);
});

for (const dismissal of ['backdrop', 'Escape', 'close']) {
  test(`${dismissal} uses one Bootstrap hide/hidden lifecycle and restores focus`, { timeout: 15_000 }, async t => {
    const f = await openFixture(t);
    await f.trigger.click();
    await assertOpen(f);
    await f.input.fill('clinic');
    // A click inside the content must not be mistaken for a backdrop click.
    await f.page.locator('#globalSearchTitle').click();
    assert.equal(await f.dialog.evaluate(el => el.classList.contains('show')), true);
    if (dismissal === 'backdrop') {
      // Bootstrap handles backdrop clicks on the full-screen .modal, not on its
      // separate decorative .modal-backdrop sibling.
      const content = await f.dialog.locator('.modal-content').boundingBox();
      assert.ok(content.x > 1 && content.y > 1, 'Backdrop point is outside the content');
      await f.page.mouse.click(1, 1);
    } else if (dismissal === 'Escape') {
      await f.input.press('Escape');
    } else {
      await f.dialog.locator('[data-search-close]').click();
    }
    await assertHidden(f);
    await f.page.waitForFunction(() => document.activeElement.matches('[data-search-open]'));
    assert.deepEqual(await f.page.evaluate(() => window.modalEvents.filter(event => event.id === 'globalSearchDialog').map(event => event.type)),
      ['show', 'shown', 'hide', 'hidden']);
    assert.deepEqual(await f.page.evaluate(() => window.activations), []);
    assert.equal(await f.input.getAttribute('aria-expanded'), 'false');
    assert.equal(await f.input.getAttribute('aria-activedescendant'), null);
    await f.trigger.click();
    await assertOpen(f);
    assert.equal(await f.input.inputValue(), '');
    await f.input.press('Escape');
    await assertHidden(f);
  });
}

test('Bootstrap locks background scrolling and restores body styles on repeated dismissal', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  const initial = await f.page.evaluate(() => {
    document.getElementById('main').style.minHeight = '3000px';
    document.body.style.paddingRight = '7px';
    window.scrollTo(0, 120);
    return { overflow: document.body.style.overflow, paddingRight: document.body.style.paddingRight };
  });
  for (let attempt = 0; attempt < 2; attempt++) {
    await f.trigger.click();
    await assertOpen(f);
    assert.equal(await f.page.locator('body').evaluate(el => getComputedStyle(el).overflow), 'hidden');
    const scroll = await f.page.evaluate(() => window.scrollY);
    await f.page.mouse.move(1, 1);
    await f.page.mouse.wheel(0, 600);
    // Allow the browser's wheel/compositor work to settle, not modal transitions.
    await f.page.evaluate(() => new Promise(resolve => {
      let frames = 0;
      const tick = () => ++frames === 6 ? resolve() : requestAnimationFrame(tick);
      requestAnimationFrame(tick);
    }));
    assert.equal(await f.page.evaluate(() => window.scrollY), scroll, 'Background cannot scroll under the modal');
    await f.input.press('Escape');
    await assertHidden(f);
    assert.deepEqual(await f.page.evaluate(() => ({ overflow: document.body.style.overflow, paddingRight: document.body.style.paddingRight })), initial);
  }
  // Wheel over the main document, not the fixed sidebar at the left edge.
    const mainBox = await f.page.locator('#main').boundingBox();
    await f.page.mouse.move(mainBox.x + mainBox.width / 2, 600);
  const scroll = await f.page.evaluate(() => window.scrollY);
  await f.page.mouse.wheel(0, 300);
  await f.page.waitForFunction(previous => window.scrollY > previous, scroll);
});

test('Bootstrap traps forward/backward Tab and programmatic focus inside search', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.trigger.click();
  await assertOpen(f);
  await f.page.locator('#prior-focus').evaluate(el => el.focus());
  assert.equal(await f.dialog.evaluate(el => el.contains(document.activeElement)), true, 'Outside focus is redirected into the modal');
  const focusable = f.dialog.locator('button:not([disabled]), input:not([disabled]), [tabindex="0"]');
  assert.ok(await focusable.count() >= 2);
  await focusable.last().focus();
  await f.page.keyboard.press('Tab');

  assert.equal(await focusable.first().evaluate(el => el === document.activeElement), true, 'Forward Tab wraps');
  await f.page.keyboard.press('Shift+Tab');
  assert.equal(await focusable.last().evaluate(el => el === document.activeElement), true, 'Backward Tab wraps');
  await f.page.keyboard.press('Escape');
  await assertHidden(f);
  await f.page.locator('#prior-focus').focus();
  assert.equal(await f.page.locator('#prior-focus').evaluate(el => el === document.activeElement), true, 'Hidden modal releases its focus trap');
});

for (const theme of ['light', 'dark']) {
  test(`${theme} search uses the shared confirmation modal surface/header/footer styling`, { timeout: 15_000 }, async t => {
    const f = await openFixture(t);
    await f.page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
    await f.trigger.click();
    await assertOpen(f);
    const styles = await f.page.evaluate(() => {
      const properties = {
        '.modal-content': ['backgroundColor', 'color', 'borderTopWidth', 'borderRadius', 'boxShadow'],
        '.modal-header': ['paddingTop', 'paddingBottom', 'borderBottomWidth', 'borderBottomColor'],
        '.modal-title': ['fontSize', 'fontWeight', 'color', 'display', 'alignItems', 'marginBottom'],
        '.modal-header .btn-close': ['backgroundImage', 'filter', 'opacity'],
        '.modal-footer': ['backgroundColor', 'borderTopWidth', 'borderTopColor']
      };
      return Object.entries(properties).map(([selector, names]) => {
        const read = id => {
          const el = document.querySelector('#' + id + ' ' + selector);
          if (!el) return null;
          const css = getComputedStyle(el);
          return Object.fromEntries(names.map(name => [name, css[name]]));
        };
        return { selector, search: read('globalSearchDialog'), confirmation: read('synapseConfirmModal') };
      });
    });
    for (const { selector, search, confirmation } of styles) {
      assert.ok(search && confirmation, `Both real modals contain ${selector}`);
      assert.deepEqual(search, confirmation, `${theme} shared ${selector} styling`);
    }
    assert.equal(await f.dialog.locator('.modal-title i[aria-hidden="true"]').count(), 1, 'Shared header includes a decorative icon');
    await f.input.press('Escape');
    await assertHidden(f);
  });
}

for (const activation of ['Enter', 'click']) {
  test(`${activation} hands off to a real Bootstrap form without stacked backdrops or late focus restoration`, { timeout: 15_000 }, async t => {
    const f = await openFixture(t);
    await f.page.evaluate(() => {
      window.openFormOnAction = true;
      window.maxBackdrops = 0;
      window.lateFocus = [];
      const observe = () => { window.maxBackdrops = Math.max(window.maxBackdrops, document.querySelectorAll('.modal-backdrop').length); };
      new MutationObserver(observe).observe(document.body, { childList: true });
      document.addEventListener('focusin', event => {
        const formState = window.modalEvents.filter(event => event.id === 'existing-modal').at(-1)?.type;
        if (['show', 'shown'].includes(formState) && !document.getElementById('existing-modal').contains(event.target)) window.lateFocus.push(event.target.id);
      }, true);
    });
    await f.trigger.click();
    await assertOpen(f);
    await f.input.fill('create appointment');
    await rememberResult(f);
    if (activation === 'Enter') await f.input.press('Enter');
    else await f.page.locator('[role="option"] span').click();
    await expectActivation(f, '/clinic/appointments/create', true);
    await f.page.waitForFunction(() => window.modalEvents.some(event => event.id === 'existing-modal' && event.type === 'shown') && document.activeElement.id === 'existing-modal-input');
    assert.equal(await f.dialog.isVisible(), false);
    assert.equal(await f.page.locator('.modal.show').count(), 1);
    assert.equal(await f.page.locator('.modal-backdrop.show').count(), 1);
    assert.equal(await f.page.evaluate(() => window.maxBackdrops), 1);
    assert.equal(await f.page.locator('body').evaluate(el => el.classList.contains('modal-open')), true);
    const field = f.page.locator('#existing-modal-input');
    await field.fill('Follow-up appointment');
    await f.page.keyboard.press('Tab');
    await f.page.getByRole('button', { name: 'Save appointment' }).click();
    assert.deepEqual(await f.page.evaluate(() => window.submissions), ['Follow-up appointment']);
    assert.deepEqual(await f.page.evaluate(() => window.lateFocus), [], 'Search must not restore outside focus once the form starts opening');
    assert.deepEqual(await f.page.evaluate(() => window.modalEvents), [
      { id: 'globalSearchDialog', type: 'show' }, { id: 'globalSearchDialog', type: 'shown' },
      { id: 'globalSearchDialog', type: 'hide' }, { id: 'globalSearchDialog', type: 'hidden' },
      { id: 'existing-modal', type: 'show' }, { id: 'existing-modal', type: 'shown' }
    ]);
    await f.page.getByRole('button', { name: 'Close form' }).click();
    await f.page.waitForFunction(() => window.modalEvents.at(-1)?.type === 'hidden');
    await assertHidden(f);
    await f.trigger.click();
    await assertOpen(f);
    await f.input.press('Escape');
    await assertHidden(f);
    assert.equal(await f.page.evaluate(() => window.activations.length), 1, 'No stale activation on the next hide');
  });
}

test('another native dialog also blocks search without stealing focus', { timeout: 15_000 }, async t => {
  const f = await openFixture(t);
  await f.page.locator('#other-dialog').evaluate(el => el.showModal());
  for (const shortcut of ['Control+k', 'Meta+k']) {
    await f.page.keyboard.press(shortcut);
    assert.equal(await f.dialog.evaluate(el => el.classList.contains('show')), false);
    assert.equal(await f.page.locator('#other-dialog input').evaluate(el => el === document.activeElement), true);
  }
});
