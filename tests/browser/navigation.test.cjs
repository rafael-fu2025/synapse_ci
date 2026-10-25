'use strict';

// Real PHP views exercise the production shell. Synthetic pages below isolate
// transport failures and timer/history races, without repairing rendered markup.
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
let browser, server, origin;
const cache = new Map();
const parents = {
  '/admin/carousel': 'manager',
  '/clinic/appointments': 'clinic-list',
  '/guidance/appointments': 'guidance-list',
  '/clinic/referrals': 'referral-list',
  '/guidance/referrals': 'referral-list'
};
const forms = {
  '/clinic/appointments/create': 'clinic',
  '/guidance/appointments/create': 'guidance'
};

function render(kind, requestPath, fragment = false) {
  const key = `${kind}:${requestPath}:${fragment}`;
  if (!cache.has(key)) {
    const result = spawnSync('php', ['tests/_support/render-modal-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: requestPath, kind, fragment }),
      encoding: 'utf8', timeout: 10_000
    });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /<div/);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
    cache.set(key, result.stdout);
  }
  return cache.get(key);
}

function synthetic(name) {
  return `<!doctype html><html><head><title>Navigation ${name}</title>
    <meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="token-${name}">
    <meta name="csrf-header" content="X-Fixture-CSRF">
    <script defer src="/assets/vendor/alpine/alpine.min.js"></script></head>
    <body data-navigation-scope="navigation-fixture" hx-boost="false">
    <div id="page-styles" hidden><style>.fixture-${name} { color: green; --navigation-page: ${name}; }</style></div>
    <header id="header" style="position:fixed;top:0;right:0">Persistent header<form method="post"><input type="hidden" name="fixture_csrf" value="token-${name}" data-shell-csrf></form></header><aside id="sidebar" style="position:fixed;top:30px;right:0"><nav class="sidebar-nav">
    ${['a', 'b', 'slow', 'fault', 'expired'].map(route => `<a class="nav-link${route === name ? ' active' : ''}" href="/navigation-fixture/${route}">${route}</a>`).join('')}
    </nav></aside><main id="main" style="min-height:3200px">
    <h1 class="fixture-${name}">Navigation ${name}</h1><a id="hash-link" href="#anchor">Jump</a><form method="post"><input name="note" aria-label="Note">
    <input type="hidden" name="fixture_csrf" value="token-${name}" data-page-csrf>
    <input type="hidden" name="unrelated" value="unchanged"></form>
    <div id="anchor" style="margin-top:900px">Anchor</div>
    <div x-data="navigationCounter()"><button id="increment" @click="count++">Increment</button><output id="counter" x-text="count"></output></div>
    </main><script src="/assets/js/csrf.js"></script><script src="/assets/js/navigation.js"></script>
    <script src="/assets/js/page-lifecycle.js"></script><div id="page-scripts">
    <script src="/navigation-fixture/shared.js"></script>
    <script>
      window.navigationInlineRuns = (window.navigationInlineRuns || 0) + 1;
      window.navigationCounter = () => ({ count: 0 });
      document.addEventListener('synapse:page-load', function init(event) {
        document.removeEventListener('synapse:page-load', init);
        window.navigationTicks = window.navigationTicks || {};
        const id = event.detail.root.querySelector('h1').textContent;
        const timer = setInterval(() => { window.navigationTicks[id] = (window.navigationTicks[id] || 0) + 1; }, 20);
        SynapseNavigation.registerCleanup(() => clearInterval(timer));
      });
    </script></div></body></html>`;
}

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
    assert.ok(executablePath, 'A Playwright Chromium installation is required');
  }
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/navigation-fixture/shared.js') {
      res.writeHead(200, { 'Content-Type': 'text/javascript' });
      res.end('window.navigationSharedRuns = (window.navigationSharedRuns || 0) + 1;');
      return;
    }
    if (url.pathname.startsWith('/navigation-fixture/')) {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(synthetic(url.pathname.split('/').pop()));
      return;
    }
    if (url.pathname === '/api/patients/search') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ results: [{ id: 11, first_name: 'Ada', last_name: 'Student', mis_id: 'S001', kind: 'student' }] }));
      return;
    }
    if (url.pathname === '/api/appointments/slots') {
      const date = url.searchParams.get('date');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ slots: [{ starts_at: date + ' 09:00:00', ends_at: date + ' 09:20:00', slot_time: '09:00', available: true }] }));
      return;
    }
    const kind = parents[url.pathname] || forms[url.pathname];
    if (kind) {
      try {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(render(kind, url.pathname + url.search, url.searchParams.has('fragment')));
      } catch (error) { res.destroy(error); }
      return;
    }
    const dir = path.join(root, 'public');
    const file = path.resolve(dir, '.' + url.pathname);
    if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20_000 });
}, { timeout: 40_000 });

after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
  }
}, { timeout: 20_000 });

async function pageFor(t, route, options = {}) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const errors = [], documents = [], requests = [];
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  await context.addInitScript(() => localStorage.setItem('na-dark-mode', '0'));
  if (options.init) await context.addInitScript(options.init);
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if ((message.type() === 'error' && !message.text().includes('Failed to load resource')) || message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  page.on('request', request => {
    requests.push(request);
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
  });
  t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'No JavaScript or Alpine errors'); });
  await page.goto(origin + route);
  assert.deepEqual(await page.evaluate(() => ({
    scope: Boolean(document.body.dataset.navigationScope),
    main: document.querySelectorAll('#main').length,
    scripts: document.querySelectorAll('#page-scripts').length,
    styles: document.querySelectorAll('#page-styles').length,
    navigation: typeof window.SynapseNavigation?.navigate,
    lifecycle: typeof window.SynapsePageLifecycle?.init
  })), { scope: true, main: 1, scripts: 1, styles: 1, navigation: 'function', lifecycle: 'function' }, 'Rendered layout must supply the navigation contract');
  await page.waitForFunction(() => Boolean(history.state?.__synapseNavigation));
  await page.evaluate(() => {
    window.shellIdentity = [document.body, document.querySelector('.header, #header'), document.querySelector('#app-sidebar, #sidebar'), document.querySelector('.sidebar-nav')];
    window.initialMain = document.querySelector('#main');
  });
  return { page, documents, requests };
}

async function assertShell(page) {
  assert.equal(await page.evaluate(() => window.shellIdentity?.every((element, index) => element === [document.body, document.querySelector('.header, #header'), document.querySelector('#app-sidebar, #sidebar'), document.querySelector('.sidebar-nav')][index])), true, 'Body/header/sidebar/nav must retain object identity');
}

async function clickRoute(page, route) {
  await page.locator(`.sidebar-nav a.nav-link[href="${origin + route}"], .sidebar-nav a.nav-link[href="${route}"]`).click();
  await settled(page, route);
}

async function settled(page, route) {
  await page.waitForFunction(route => location.pathname + location.search === route && !document.body.classList.contains('is-navigating') && document.querySelector('#main')?.getAttribute('aria-busy') !== 'true', route);
}

async function traverse(page, direction, route) {
  await page.evaluate(direction => new Promise(resolve => {
    document.addEventListener('synapse:page-load', () => resolve(), { once: true });
    history[direction]();
  }), direction);
  await settled(page, route);
}

test('single-host scopes are route-independent while dedicated host scopes stay isolated', () => {
  const scope = (host, route, kind) => {
    const result = spawnSync('php', ['tests/_support/render-modal-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin: `http://${host}:8080`, path: route, kind }),
      encoding: 'utf8', timeout: 10000
    });
    assert.equal(result.status, 0, result.stderr);
    const match = result.stdout.match(/data-navigation-scope="([^"]+)"/);
    assert.ok(match, 'Real layout must declare a navigation scope');
    return match[1];
  };
  const shared = scope('172.16.153.237', '/admin/carousel', 'manager');
  assert.equal(scope('172.16.153.237', '/clinic/appointments', 'clinic-list'), shared);
  assert.equal(scope('172.16.153.237', '/guidance/appointments', 'guidance-list'), shared);
  const clinic = scope('clinic.localhost', '/clinic/appointments', 'clinic-list');
  const guidance = scope('guidance.localhost', '/guidance/appointments', 'guidance-list');
  const main = scope('synapse.localhost', '/admin/carousel', 'manager');
  assert.equal(new Set([shared, clinic, guidance, main]).size, 4, 'Host-bound and single-host shells must not share scopes');
});

test('actual PHP shell wiring, sidebar identity, server active route, title and focus', { timeout: 30_000 }, async t => {
  const { page, documents, requests } = await pageFor(t, '/admin/carousel');
  assert.equal(await page.evaluate(() => {
    const navigation = document.querySelector('script[src$="/navigation.js"]');
    const lifecycle = document.querySelector('script[src$="/page-lifecycle.js"]');
    const core = document.querySelector('script[src$="/core/main.js"]');
    const scripts = document.querySelector('#page-scripts');
    return navigation.parentElement === document.body && Boolean(navigation.compareDocumentPosition(scripts) & Node.DOCUMENT_POSITION_FOLLOWING) &&
      Boolean(core.compareDocumentPosition(lifecycle) & Node.DOCUMENT_POSITION_FOLLOWING) && !document.querySelector('#main').contains(document.querySelector('#page-styles'));
  }), true, 'Navigation belongs at body bottom before page scripts; lifecycle follows core; styles are independently swappable');
  for (const route of ['/clinic/appointments', '/guidance/appointments', '/clinic/referrals', '/admin/carousel']) {
    const expected = await page.evaluate(html => {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      return { title: doc.title, active: [...doc.querySelectorAll('.sidebar-nav a.active')].map(a => a.href) };
    }, render(parents[route], route));
    await clickRoute(page, route);
    await assertShell(page);
    assert.equal(await page.title(), expected.title);
    assert.deepEqual(await page.locator('.sidebar-nav a.active').evaluateAll(links => links.map(a => a.href)), expected.active);
    assert.equal(await page.evaluate(() => document.activeElement === document.querySelector('#main h1')), true);
    assert.equal(await page.locator('#main h1').getAttribute('tabindex'), '-1');
    assert.equal(await page.evaluate(() => window.initialMain.isConnected), false);
  }
  assert.equal(documents.length, 1, 'Sidebar clicks must not load another document');
  const navigationRequests = requests.filter(request => request.resourceType() === 'fetch' && parents[new URL(request.url()).pathname]);
  assert.equal(navigationRequests.length, 4);
  for (const request of navigationRequests) {
    const headers = request.headers();
    assert.equal(headers.accept, 'text/html');
    assert.equal(headers['hx-request'], undefined);
    assert.equal(headers['x-requested-with'], undefined);
  }
});

test('real carousel CSS applies initially, leaves with appointments and returns with carousel', { timeout: 20_000 }, async t => {
  const { page, documents } = await pageFor(t, '/admin/carousel');
  // Keep the stylesheet's scope outside the swapped main to detect leaked CSS.
  await page.evaluate(() => {
    const scope = document.createElement('div');
    scope.className = 'carousel-admin';
    scope.hidden = true;
    const probe = document.createElement('div');
    probe.id = 'carousel-style-probe';
    probe.className = 'slide-row dragging';
    scope.append(probe);
    document.body.append(scope);
  });
  const opacity = () => page.locator('#carousel-style-probe').evaluate(element => getComputedStyle(element).opacity);
  assert.equal(await opacity(), '0.5', 'Actual carousel stylesheet must apply on initial render');
  const firstStyles = await page.locator('#main style, #page-styles style').elementHandles();
  assert.ok(firstStyles.length > 0);
  await clickRoute(page, '/clinic/appointments');
  assert.equal(await opacity(), '1', 'Carousel CSS must not leak into appointments');
  for (const style of firstStyles) assert.equal(await style.evaluate(element => element.isConnected), false);
  await clickRoute(page, '/admin/carousel');
  assert.equal(await opacity(), '0.5', 'Returning must install carousel CSS again');
  assert.equal(await page.locator('#slideList .slide-row').count(), 1);
  await assertShell(page);
  assert.equal(documents.length, 1);
});

test('active hidden page-style containers replace CSS on navigation and history', { timeout: 15_000 }, async t => {
  const { page, documents } = await pageFor(t, '/navigation-fixture/a');
  const styles = await page.locator('#page-styles').elementHandle();
  const pageStyle = () => page.locator('#main h1').evaluate(element => ({
    color: getComputedStyle(element).color,
    marker: getComputedStyle(element).getPropertyValue('--navigation-page').trim()
  }));
  assert.deepEqual(await pageStyle(), { color: 'rgb(0, 128, 0)', marker: 'a' });
  await page.evaluate(() => {
    const probe = document.createElement('div');
    probe.id = 'old-style-probe';
    probe.className = 'fixture-a';
    probe.hidden = true;
    document.body.append(probe);
  });
  await clickRoute(page, '/navigation-fixture/b');
  assert.equal(await styles.evaluate(element => element.isConnected), false);
  assert.deepEqual(await pageStyle(), { color: 'rgb(0, 128, 0)', marker: 'b' });
  assert.equal(await page.locator('#old-style-probe').evaluate(element => getComputedStyle(element).getPropertyValue('--navigation-page')), '');
  await traverse(page, 'back', '/navigation-fixture/a');
  assert.deepEqual(await pageStyle(), { color: 'rgb(0, 128, 0)', marker: 'a' });
  assert.equal(await page.locator('#old-style-probe').evaluate(element => getComputedStyle(element).getPropertyValue('--navigation-page').trim()), 'a');
  await assertShell(page);
  assert.equal(documents.length, 1);
});

test('navigation synchronizes rotating CSRF metadata, shell/page inputs and subsequent request headers', { timeout: 15_000 }, async t => {
  const { page, documents, requests } = await pageFor(t, '/navigation-fixture/a');
  const csrf = () => page.evaluate(() => ({
    name: document.querySelector('meta[name="csrf-name"]').content,
    token: document.querySelector('meta[name="csrf-token"]').content,
    header: document.querySelector('meta[name="csrf-header"]').content,
    shell: document.querySelector('[data-shell-csrf]').value,
    page: document.querySelector('[data-page-csrf]').value,
    unrelated: document.querySelector('input[name="unrelated"]').value,
    headers: SynapseCsrf.headers()
  }));
  const expected = token => ({ name: 'fixture_csrf', token, header: 'X-Fixture-CSRF', shell: token, page: token, unrelated: 'unchanged', headers: { 'X-Fixture-CSRF': token } });
  assert.deepEqual(await csrf(), expected('token-a'));
  await clickRoute(page, '/navigation-fixture/b');
  assert.deepEqual(await csrf(), expected('token-b'));
  assert.equal(requests.find(request => request.resourceType() === 'fetch' && request.url().endsWith('/navigation-fixture/b')).headers()['x-fixture-csrf'], 'token-a');
  await page.route('**/navigation-fixture/a', route => route.fulfill({
    contentType: 'text/html', body: synthetic('a'),
    headers: { 'X-CSRF-TOKEN': 'response-token-a', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-HEADER': 'X-Fixture-CSRF' }
  }));
  await clickRoute(page, '/navigation-fixture/a');
  assert.deepEqual(await csrf(), expected('response-token-a'), 'Response headers must override document tokens in both persistent and incoming forms');
  assert.equal(requests.find(request => request.resourceType() === 'fetch' && request.url().endsWith('/navigation-fixture/a')).headers()['x-fixture-csrf'], 'token-b');
  await clickRoute(page, '/navigation-fixture/b');
  assert.equal(requests.filter(request => request.resourceType() === 'fetch' && request.url().endsWith('/navigation-fixture/b'))[1].headers()['x-fixture-csrf'], 'response-token-a');
  assert.deepEqual(await csrf(), expected('token-b'));
  await assertShell(page);
  assert.equal(documents.length, 1);
});

for (const mismatch of ['scope', 'sidebar link signature']) {
  test(`incoming ${mismatch} mismatch falls back to a full document navigation`, { timeout: 15_000 }, async t => {
    const { page, documents, requests } = await pageFor(t, '/navigation-fixture/a');
    const incoming = mismatch === 'scope'
      ? synthetic('b').replace('data-navigation-scope="navigation-fixture"', 'data-navigation-scope="different-user"')
      : synthetic('b').replace('href="/navigation-fixture/slow"', 'href="/navigation-fixture/changed"');
    await page.route('**/navigation-fixture/b', route => route.fulfill({ contentType: 'text/html', body: incoming }));
    await page.locator('a[href="/navigation-fixture/b"]').click();
    await page.waitForURL(origin + '/navigation-fixture/b', { waitUntil: 'load' });
    await page.waitForFunction(() => !window.shellIdentity && document.title === 'Navigation b');
    assert.equal(documents.length, 2, 'Incompatible shells must not swap in the old document');
    assert.equal(requests.filter(request => request.resourceType() === 'fetch' && request.url().endsWith('/navigation-fixture/b')).length, 1);
    assert.equal(await page.locator('#navigation-retry').isHidden(), true);
    if (mismatch === 'scope') assert.equal(await page.locator('body').getAttribute('data-navigation-scope'), 'different-user');
    else assert.equal(await page.locator('.sidebar-nav a[href="/navigation-fixture/changed"]').count(), 1);
  });
}

test('actual fixtures use same-document Back/Forward and retain sidebar scroll', { timeout: 30_000 }, async t => {
  const { page, documents } = await pageFor(t, '/admin/carousel');
  await clickRoute(page, '/clinic/appointments');
  const sidebarTop = await page.locator('.sidebar-nav').evaluate(nav => { nav.scrollTop = 180; return nav.scrollTop; });
  await clickRoute(page, '/guidance/appointments');
  await traverse(page, 'back', '/clinic/appointments');
  await traverse(page, 'forward', '/guidance/appointments');
  await assertShell(page);
  assert.ok(Math.abs(await page.locator('.sidebar-nav').evaluate(nav => nav.scrollTop) - sidebarTop) <= 1);
  assert.equal(documents.length, 1);
});

test('actual appointment Alpine factory and modal datepicker work after repeated navigation', { timeout: 35_000 }, async t => {
  const { page, documents, requests } = await pageFor(t, '/admin/carousel');
  for (const route of ['/clinic/appointments', '/guidance/appointments']) {
    await clickRoute(page, route);
    await page.locator(`a[data-app-modal-open][href="${origin + route}/create"]`).click();
    await page.waitForFunction(() => document.querySelector('#appFormModal form') && document.querySelector('#appFormModal')?.getAttribute('aria-modal') === 'true');
    await page.locator('#appFormModal input[placeholder^="Search Student"]').fill('Ada');
    await page.locator('#appFormModal .list-group button').filter({ hasText: 'Student, Ada' }).click();
    await page.locator('#bookingDate').click();
    await page.locator('#appFormModal .dp-popup:not([hidden])').waitFor();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Enter');
    assert.match(await page.locator('#bookingDate').inputValue(), /^\d{2}\/\d{2}\/\d{4}$/);
    assert.match(await page.locator('#bookingDate_iso').inputValue(), /^\d{4}-\d{2}-\d{2}$/);
    const main = await page.locator('#main').elementHandle();
    assert.equal(await page.evaluate(() => SynapseNavigation.navigate('/admin/carousel')), false, 'An open real modal vetoes navigation');
    assert.equal(await main.evaluate(element => element === document.querySelector('#main')), true);
    assert.equal(new URL(page.url()).pathname, route);
    await page.locator('#appFormModal [data-app-modal-close]').first().click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    await clickRoute(page, '/admin/carousel');
  }
  await assertShell(page);
  assert.equal(documents.length, 1);
  for (const asset of ['care-forms.js', 'datepicker.js', 'form-modal.js', 'navigation.js']) {
    assert.equal(requests.filter(request => new URL(request.url()).pathname.endsWith('/' + asset)).length, 1, `${asset} must load once`);
  }
});

test('synthetic history restores window scroll, factories, scripts and timer cleanup', { timeout: 20_000 }, async t => {
  const { page, documents, requests } = await pageFor(t, '/navigation-fixture/a');
  await page.locator('#increment').click();
  assert.equal(await page.locator('#counter').textContent(), '1');
  await page.evaluate(() => window.scrollTo({ top: 620, behavior: 'instant' }));
  await page.waitForFunction(() => history.state.__synapseNavigation.scroll[1] === 620);
  await clickRoute(page, '/navigation-fixture/b');
  assert.equal(await page.evaluate(() => scrollY), 0);
  await page.waitForFunction(() => window.navigationTicks?.['Navigation b'] >= 2);
  const oldTicks = await page.evaluate(() => window.navigationTicks['Navigation a']);
  await page.waitForFunction(() => window.navigationTicks['Navigation b'] >= 5);
  assert.equal(await page.evaluate(() => window.navigationTicks['Navigation a']), oldTicks, 'Departed page timer must stop');
  await page.evaluate(() => window.scrollTo({ top: 910, behavior: 'instant' }));
  await page.waitForFunction(() => history.state.__synapseNavigation.scroll[1] === 910);
  await traverse(page, 'back', '/navigation-fixture/a');
  assert.equal(await page.evaluate(() => scrollY), 620);
  await page.locator('#increment').click();
  assert.equal(await page.locator('#counter').textContent(), '1', 'Factory must initialize the new Alpine tree exactly once');
  await traverse(page, 'forward', '/navigation-fixture/b');
  assert.equal(await page.evaluate(() => scrollY), 910);
  await assertShell(page);
  assert.deepEqual(await page.evaluate(() => [navigationSharedRuns, navigationInlineRuns]), [1, 4]);
  assert.equal(requests.filter(request => request.url().endsWith('/shared.js')).length, 1);
  assert.equal(documents.length, 1);
});

for (const failure of ['http', 'network', 'timeout']) {
  test(`${failure} failure keeps content and history; Retry completes without reloading`, { timeout: 20_000 }, async t => {
    const { page, documents } = await pageFor(t, '/navigation-fixture/a', failure === 'timeout' ? {
      init: () => {
        const original = window.setTimeout;
        let injectTimeout = true;
        window.setTimeout = function(callback, delay, ...args) {
          if (injectTimeout && delay === 20000) {
            injectTimeout = false;
            return original.call(this, callback, 80, ...args);
          }
          return original.call(this, callback, delay, ...args);
        };
      }
    } : {});
    let fail = true;
    await page.route('**/navigation-fixture/fault', async route => {
      if (!fail) { await route.continue(); return; }
      if (failure === 'http') await route.fulfill({ status: 503, contentType: 'text/html', body: '<h1>Unavailable</h1>' });
      else if (failure === 'network') await route.abort('failed');
      else { await new Promise(resolve => setTimeout(resolve, 250)); await route.abort().catch(() => {}); }
    });
    const historyLength = await page.evaluate(() => history.length);
    await page.locator('a[href="/navigation-fixture/fault"]').click();
    await page.locator('#navigation-retry').waitFor({ state: 'visible' });
    assert.equal(await page.evaluate(() => document.querySelector('#main') === window.initialMain), true);
    assert.equal(new URL(page.url()).pathname, '/navigation-fixture/a');
    assert.equal(await page.evaluate(() => history.length), historyLength);
    assert.equal(await page.locator('#main').getAttribute('aria-busy'), 'false');
    assert.match(await page.locator('#navigation-status').textContent(), failure === 'timeout' ? /timed out/ : /current page has been kept/);
    assert.equal(await page.locator('#navigation-fallback').getAttribute('href'), origin + '/navigation-fixture/fault');
    fail = false;
    await page.locator('#navigation-retry').click();
    await settled(page, '/navigation-fixture/fault');
    assert.equal(await page.locator('#navigation-retry').isHidden(), true);
    assert.equal(await page.evaluate(() => history.length), historyLength + 1);
    await assertShell(page);
    assert.equal(documents.length, 1);
  });
}

test('rapid navigation ignores a stale delayed response', { timeout: 15_000 }, async t => {
  const { page, documents } = await pageFor(t, '/navigation-fixture/a');
  let release;
  const held = new Promise(resolve => { release = resolve; });
  t.after(() => release());
  await page.route('**/navigation-fixture/slow', async route => {
    await held;
    await route.fulfill({ contentType: 'text/html', body: synthetic('slow') }).catch(() => {});
  });
  const requested = page.waitForRequest('**/navigation-fixture/slow');
  await page.evaluate(() => { window.slowNavigation = SynapseNavigation.navigate('/navigation-fixture/slow'); });
  await requested;
  assert.equal(await page.evaluate(() => SynapseNavigation.navigate('/navigation-fixture/b')), true);
  release();
  assert.equal(await page.evaluate(() => window.slowNavigation), false);
  await settled(page, '/navigation-fixture/b');
  await page.waitForTimeout(100);
  assert.equal(await page.title(), 'Navigation b');
  await assertShell(page);
  assert.equal(documents.length, 1);
  assert.equal(await page.locator('#navigation-retry').isHidden(), true);
});

test('authentication redirect to a non-shell login performs a full document navigation', { timeout: 15_000 }, async t => {
  const { page, documents } = await pageFor(t, '/navigation-fixture/a');
  await page.route('**/navigation-fixture/expired', route => route.fulfill({ status: 302, headers: { location: origin + '/auth/login' } }));
  await page.route('**/auth/login', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Sign in</title><h1>Sign in</h1><form><input name="username"></form>' }));
  await page.locator('a[href="/navigation-fixture/expired"]').click();
  await page.waitForURL(origin + '/auth/login');
  await page.locator('input[name="username"]').waitFor();
  assert.equal(await page.title(), 'Sign in');
  assert.equal(await page.evaluate(() => Boolean(window.shellIdentity)), false);
  assert.equal(await page.locator('#main').count(), 0);
  assert.equal(documents.length, 2);
});

test('dirty POST form cancellation preserves content and canceled Back restores the current entry', { timeout: 20_000 }, async t => {
  const { page, documents, requests } = await pageFor(t, '/navigation-fixture/a');
  await clickRoute(page, '/navigation-fixture/b');
  await page.getByLabel('Note').fill('Unsaved work');
  let prompts = 0;
  page.on('dialog', async dialog => { prompts++; await dialog.dismiss(); });
  const count = requests.length;
  assert.equal(await page.evaluate(() => SynapseNavigation.navigate('/navigation-fixture/a')), false);
  assert.equal(requests.length, count, 'Cancellation must precede fetch');
  assert.equal(new URL(page.url()).pathname, '/navigation-fixture/b');
  assert.equal(await page.getByLabel('Note').inputValue(), 'Unsaved work');
  await page.evaluate(() => history.back());
  await page.waitForFunction(() => location.pathname === '/navigation-fixture/b' && history.state.__synapseNavigation.index === 1);
  assert.equal(prompts, 2);
  assert.equal(await page.getByLabel('Note').inputValue(), 'Unsaved work');
  await assertShell(page);
  assert.equal(documents.length, 1);
  page.removeAllListeners('dialog');
  page.on('dialog', dialog => dialog.accept());
  assert.equal(await page.evaluate(() => SynapseNavigation.navigate('/navigation-fixture/a')), true);
  await settled(page, '/navigation-fixture/a');
});

test('fragment history, canceled in-flight Back and edits during pending navigation preserve work and updateHistory', { timeout: 25_000 }, async t => {
  const { page, documents } = await pageFor(t, '/navigation-fixture/a');
  await page.locator('#hash-link').click();
  assert.equal(new URL(page.url()).hash, '#anchor');
  assert.ok(await page.evaluate(() => scrollY > 400));
  await page.getByLabel('Note').fill('Draft after anchor');
  let prompts = 0;
  page.on('dialog', async dialog => { prompts++; await dialog.dismiss(); });
  await page.evaluate(() => history.back());
  await page.waitForFunction(() => !location.hash && scrollY === 0);
  assert.equal(prompts, 0, 'Same-document fragment Back must not prompt or reload');
  assert.equal(await page.getByLabel('Note').inputValue(), 'Draft after anchor');
  await page.locator('#main form').evaluate(form => form.reset());
  let heldCount = 0;
  const gates = [null, null].map(() => {
    let release;
    return { promise: new Promise(resolve => { release = resolve; }), release };
  });
  t.after(() => gates.forEach(gate => gate.release()));
  await page.route('**/navigation-fixture/slow', async route => {
    const gate = gates[heldCount++];
    if (gate) await gate.promise;
    await route.fulfill({ contentType: 'text/html', body: synthetic('slow') }).catch(() => {});
  });
  await clickRoute(page, '/navigation-fixture/b');
  await page.getByLabel('Note').fill('Dirty b');
  const firstSlow = page.waitForRequest('**/navigation-fixture/slow');
  await page.evaluate(() => {
    window.allowDirtyOnce = true;
    const nativeConfirm = window.confirm;
    window.confirm = message => {
      if (window.allowDirtyOnce) { window.allowDirtyOnce = false; return true; }
      return nativeConfirm(message);
    };
    void SynapseNavigation.navigate('/navigation-fixture/slow');
  });
  await firstSlow;
  await page.evaluate(() => history.back());
  await page.waitForFunction(() => location.pathname === '/navigation-fixture/b' && history.state.__synapseNavigation.index === 1);
  assert.equal(prompts, 1, 'Canceling Back prompts exactly once');
  assert.equal(await page.evaluate(() => SynapseNavigation.updateHistory('/navigation-fixture/b?tab=filtered', 'push')), true,
    'Canceled Back during pending fetch must not leave pending state stuck');
  assert.equal(new URL(page.url()).search, '?tab=filtered');
  await page.locator('#main form').evaluate(form => form.reset());
  const secondSlow = page.waitForRequest('**/navigation-fixture/slow');
  await page.evaluate(() => { window.lateEditResult = SynapseNavigation.navigate('/navigation-fixture/slow'); });
  await secondSlow;
  await page.getByLabel('Note').fill('Typed while loading');
  gates[1].release();
  assert.equal(await page.evaluate(() => window.lateEditResult), false, 'Edits made during fetch must re-check before swapping');
  assert.equal(await page.getByLabel('Note').inputValue(), 'Typed while loading');
  assert.equal(new URL(page.url()).pathname, '/navigation-fixture/b');
  await assertShell(page);
  assert.equal(documents.length, 1);
});
