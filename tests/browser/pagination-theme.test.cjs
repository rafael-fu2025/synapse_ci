'use strict';

// Only a loopback synthetic renderer and explicitly allowlisted production assets.
// No application bootstrap, DB, installed dependency changes, or external requests.
// Run: node --test --test-concurrency=1 tests/browser/pagination-theme.test.cjs
// GUI fixture: node tests/browser/pagination-theme.test.cjs --serve
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const renderer = path.join(root, 'tests/_support/render-pagination-theme-fixture.php');
const screenshotDir = path.join(root, 'writable/pagination-theme-preview');
const themes = ['light', 'dark', 'legacy-dark'];
const scopes = {
  generic: 'Generic table pages', admin: 'Admin table pages', mi: 'MI registry pages',
  portal: 'Portal content pages', modal: 'Modal history pages',
};
const assets = new Set([
  '/assets/vendor/bootstrap/css/bootstrap.min.css', '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/css/style.css', '/assets/css/core/tokens.css', '/assets/css/core/base.css',
  '/assets/css/core/layout.css', '/assets/css/core/components.css', '/assets/css/core/synapse-theme.css',
  '/assets/css/core/synapse-widgets.css', '/assets/css/pages/dashboard.css',
  '/assets/css/pages/portal-engagement.css', '/assets/css/pages/guidance-case.css',
  '/assets/css/pages/availability-workspace.css', '/assets/css/pages/profile-settings.css',
  '/assets/css/pages/portal-surveys.css', '/assets/css/pages/portal-referrals.css',
  '/assets/css/pages/portal-announcements.css', '/assets/css/pages/portal-appointments.css',
  '/assets/css/pages/portal-visits.css', '/assets/css/pages/admin-workspace.css',
  '/assets/css/pages/ai-insights.css', '/assets/css/pages/referrals-workspace.css',
  '/assets/css/pages/clinic-reports.css', '/assets/css/pages/clinic-isolation.css',
  '/assets/css/pages/clinic-inventory.css', '/assets/css/pages/appointments-workspace.css',
  '/assets/css/pages/clinic-patients.css', '/assets/css/pages/clinic-encounters.css',
  '/assets/css/pages/clinic-dashboard.css', '/assets/css/pages/guidance-mi-registry.css',
  ...['datepicker', 'datetimepicker', 'date-range-picker', 'navigation', 'loading', 'toast',
    'teams', 'responsive-tables', 'global-search'].map(name => `/assets/css/${name}.css`),
]);
const queryKeys = new Set(['theme', 'table_page', 'content_page', 'table_per_page', 'content_per_page',
  'q', 'kind', 'status', 'unit']);
let server, origin, browser;

function renderFixture(input) {
  const result = spawnSync('php', ['-n', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', renderer], {
    cwd: root, input: JSON.stringify(input), encoding: 'utf8', timeout: 5000, maxBuffer: 1024 * 1024,
  });
  assert.equal(result.status, 0, result.error?.message || result.stderr);
  assert.doesNotMatch(result.stdout, /Warning:|Fatal error|Parse error/);
  return result.stdout;
}

async function startFixture() {
  const cache = new Map();
  const fixtureServer = http.createServer((req, res) => {
    try {
      if (req.method !== 'GET') return res.writeHead(405).end('Read-only synthetic fixture');
      const url = new URL(req.url, origin);
      if (url.pathname === '/pagination-theme') {
        if ([...url.searchParams.keys()].some(key => !queryKeys.has(key))) return res.writeHead(400).end();
        const theme = url.searchParams.get('theme') || 'light';
        const table_page = Number(url.searchParams.get('table_page') || 1);
        const content_page = Number(url.searchParams.get('content_page') || 1);
        if (!themes.includes(theme) || ![table_page, content_page].every(n => Number.isInteger(n) && n >= 1 && n <= 5)) {
          return res.writeHead(400).end();
        }
        const key = JSON.stringify({ origin, theme, table_page, content_page });
        if (!cache.has(key)) cache.set(key, renderFixture(JSON.parse(key)));
        return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }).end(cache.get(key));
      }
      if (!assets.has(url.pathname) || url.search) return res.writeHead(404).end();
      const file = path.join(root, 'public', ...url.pathname.slice(1).split('/'));
      if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return res.writeHead(404).end();
      return res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : 'text/css' }).end(fs.readFileSync(file));
    } catch (error) {
      res.writeHead(500, { 'Content-Type': 'text/plain' }).end(error.message);
    }
  });
  fixtureServer.listen(0, '127.0.0.1');
  await once(fixtureServer, 'listening');
  origin = `http://127.0.0.1:${fixtureServer.address().port}`;
  return fixtureServer;
}

function installedChromium(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  if (!fs.existsSync(dir)) return null;
  return fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe'].map(file => path.join(dir, name, file)))
    .find(file => fs.existsSync(file));
}

async function open(t, theme = 'light') {
  const errors = [];
  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 },
    colorScheme: theme === 'light' ? 'light' : 'dark', reducedMotion: 'reduce', serviceWorkers: 'block' });
  await context.route('**/*', route => {
    const request = route.request(), url = new URL(request.url());
    if (request.method() !== 'GET' || url.origin !== origin ||
      (url.pathname !== '/pagination-theme' && !assets.has(url.pathname))) {
      errors.push(`Blocked request: ${request.method()} ${url.href}`);
      return route.abort();
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(3000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('response', response => { if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'No browser, resource, or external request errors'); });
  const response = await page.goto(`${origin}/pagination-theme?theme=${theme}`, { waitUntil: 'load' });
  assert.equal(response.status(), 200);
  assert.equal(await page.getByRole('heading', { name: 'SYNTHETIC TEST ONLY — pagination colors', exact: true }).count(), 1);
  return page;
}

const pager = (page, scope) => page.getByRole('navigation', { name: scopes[scope], exact: true });
const card = (page, scope) => page.locator(`[data-theme-card="${scope}"]`);
async function paint(locator) {
  return locator.evaluate(element => {
    const s = getComputedStyle(element);
    return { bg: s.backgroundColor, fg: s.color, border: s.borderRightColor, opacity: s.opacity,
      cursor: s.cursor, pointer: s.pointerEvents, outlineStyle: s.outlineStyle,
      outlineWidth: s.outlineWidth, outlineColor: s.outlineColor, outlineOffset: s.outlineOffset,
      focusVisible: element.matches(':focus-visible'), height: element.getBoundingClientRect().height,
      minHeight: s.minHeight, radius: s.borderTopLeftRadius };
  });
}
async function tokens(page, scope) {
  return pager(page, scope).evaluate(element => {
    const s = getComputedStyle(element);
    return Object.fromEntries(['--bs-secondary-bg', '--bs-tertiary-bg', '--default-color', '--muted-color',
      '--control-border', '--fu-brand', '--focus-color', '--surface-color', '--ce-card', '--ce-border',
      '--ce-fg-secondary', '--ce-subtle', '--ce-muted-fg', '--adm-border-subtle'].map(name => [name, s.getPropertyValue(name).trim()]));
  });
}
const rgb = value => {
  if (/^#[0-9a-f]{3}$/i.test(value)) value = '#' + [...value.slice(1)].map(c => c + c).join('');
  assert.match(value, /^#[0-9a-f]{6}$/i, `Resolved color token: ${value}`);
  return `rgb(${[1, 3, 5].map(i => parseInt(value.slice(i, i + 2), 16)).join(', ')})`;
};
function expectPaint(actual, token, state = 'idle') {
  assert.equal(actual.bg, rgb(token[state === 'active' ? '--fu-brand' : state === 'hover' ? '--bs-tertiary-bg' : '--bs-secondary-bg']));
  assert.equal(actual.fg, state === 'active' ? 'rgb(255, 255, 255)' : rgb(token[state === 'disabled' ? '--muted-color' : '--default-color']));
  assert.equal(actual.border, rgb(token[state === 'active' ? '--fu-brand' : '--control-border']));
  assert.equal(actual.opacity, '1');
}
async function image(page, name, target) {
  // Admin retains its existing transition; capture settled production paint, not
  // the brief Bootstrap starting colors while an imported stylesheet takes effect.
  await page.waitForFunction(() => [...document.querySelectorAll('.synapse-pagination .page-link')]
    .filter(el => el.getClientRects().length).every(el => {
      const s = getComputedStyle(el);
      const disabled = el.matches('.disabled, [aria-disabled="true"]') || el.parentElement.classList.contains('disabled');
      const active = el.matches('.active, [aria-current="page"]') || el.parentElement.classList.contains('active');
      const state = disabled ? 'disabled-' : active ? 'active-' : el.matches(':hover, :focus') ? 'hover-' : '';
      const value = s.getPropertyValue(`--bs-pagination-${state}bg`).trim();
      const hex = value.length === 4 ? '#' + [...value.slice(1)].map(c => c + c).join('') : value;
      const expected = /^#[0-9a-f]{6}$/i.test(hex)
        ? `rgb(${[1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16)).join(', ')})` : value;
      return s.backgroundColor === expected;
    }));
  const strips = await page.locator('.synapse-pagination').evaluateAll(pagers => pagers
    .filter(pager => pager.getClientRects().length).map(pager => {
      const style = getComputedStyle(pager);
      const links = [...pager.querySelectorAll('.page-link')].map(link => link.getBoundingClientRect());
      return { columnGap: style.columnGap, rowGap: style.rowGap,
        gaps: links.slice(1).flatMap((box, index) => Math.abs(box.top - links[index].top) <= 1
          ? [box.left - links[index].right] : []) };
    }));
  for (const strip of strips) {
    assert.equal(strip.columnGap, '0px', 'Pagination buttons form a connected strip');
    assert.equal(strip.rowGap, '0px', 'Wrapped pagination has no added row gaps');
    assert.ok(strip.gaps.every(gap => gap >= -1.1 && gap <= 0.1), 'Adjacent pagination borders touch: ' + JSON.stringify(strip));
  }
  const file = path.join(screenshotDir, name + '.png');
  if (target) await target.screenshot({ path: file });
  else await page.screenshot({ path: file, fullPage: true });
  console.log('Pagination theme screenshot: ' + file);
}
async function modal(page) {
  await page.getByRole('button', { name: 'Open modal pager', exact: true }).click();
  await page.getByRole('dialog', { name: 'Synthetic modal history', exact: true }).waitFor();
}
async function unchangedSurface(page, scope, theme) {
  const actual = await card(page, scope).evaluate(element => {
    const s = getComputedStyle(element);
    return { bg: s.backgroundColor, radius: s.borderTopLeftRadius, border: s.borderTopColor };
  });
  const expected = scope === 'mi' ? theme === 'light' ? '#ffffff' : '#161618'
    : theme === 'light' ? '#ffffff' : '#1c1c1c';
  assert.equal(actual.bg, rgb(expected), `${scope} surrounding card stays unchanged`);
  return actual;
}

if (process.argv.includes('--serve')) {
  startFixture().then(() => console.log('Synthetic pagination fixture: ' + origin + '/pagination-theme'));
} else {
  before(async () => {
    fs.mkdirSync(screenshotDir, { recursive: true });
    server = await startFixture();
    const { chromium } = require('playwright'); // already installed in tests/browser; never installs
    const executablePath = installedChromium(chromium);
    assert.ok(executablePath, 'An installed Chromium is required; this test never downloads one');
    browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
  });
  after(async () => {
    await browser?.close();
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  });

  for (const [index, theme] of themes.entries()) test(`shared state colors in all five contexts: ${theme}`, async t => {
    const page = await open(t, theme);
    for (const scope of ['generic', 'admin', 'mi', 'portal']) {
      const nav = pager(page, scope), token = await tokens(page, scope);
      assert.match(await nav.locator('ul').getAttribute('class'), /pagination pagination-sm flex-wrap mb-0 synapse-pagination/);
      expectPaint(await paint(nav.getByRole('link', { name: 'Next', exact: true })), token);
      expectPaint(await paint(nav.locator('[aria-current="page"]')), token, 'active');
      expectPaint(await paint(nav.locator('[aria-disabled="true"]').first()), token, 'disabled');
      await unchangedSurface(page, scope, theme);
    }
    await image(page, `t0${index + 1}-${theme}-all-contexts`);
    await modal(page);
    const nav = pager(page, 'modal'), token = await tokens(page, 'modal');
    expectPaint(await paint(nav.getByRole('link', { name: 'Next', exact: true })), token);
    expectPaint(await paint(nav.locator('[aria-current="page"]')), token, 'active');
    expectPaint(await paint(nav.locator('[aria-disabled="true"]').first()), token, 'disabled');
    await unchangedSurface(page, 'modal', theme);
    await image(page, `t0${index + 1}-${theme}-modal`);
  });

  const interactions = [['generic', 'light'], ['admin', 'dark'], ['mi', 'legacy-dark'], ['portal', 'dark'], ['modal', 'light']];
  for (const [index, [scope, theme]] of interactions.entries()) test(`normal hover, keyboard focus, and mobile resize: ${scope} ${theme}`, async t => {
    const page = await open(t, theme);
    if (scope === 'modal') await modal(page);
    const name = `t0${index + 4}-${scope}-${theme}`, target = card(page, scope);
    const nav = pager(page, scope), next = nav.getByRole('link', { name: 'Page 2', exact: true });
    const token = await tokens(page, scope), surface = await unchangedSurface(page, scope, theme);
    await image(page, name + '-idle', target);
    await next.hover();
    await page.waitForFunction(({ label, expected }) => {
      const nav = [...document.querySelectorAll('nav')].find(el => el.getAttribute('aria-label') === label);
      return getComputedStyle(nav.querySelector('[aria-label="Page 2"]')).backgroundColor === expected;
    }, { label: scopes[scope], expected: rgb(token['--bs-tertiary-bg']) });
    expectPaint(await paint(next), token, 'hover');
    await image(page, name + '-hover', target);

    // Real keyboard input from the preceding visible button; no focus/event injection.
    const start = page.getByRole('button', { name: `Focus ${scope} pager`, exact: true });
    await start.click();
    assert.equal(await start.evaluate(el => el === document.activeElement), true);
    await image(page, name + '-keyboard-start', target);
    await start.press('Tab');
    assert.equal(await next.evaluate(el => el === document.activeElement), true);
    await page.waitForFunction(expected => getComputedStyle(document.activeElement).backgroundColor === expected,
      rgb(token['--bs-tertiary-bg']));
    const focused = await paint(next);
    expectPaint(focused, token, 'hover');
    assert.equal(focused.focusVisible, true);
    assert.equal(focused.outlineStyle, 'solid');
    assert.equal(focused.outlineWidth, '2px');
    assert.equal(focused.outlineOffset, '-3px');
    assert.equal(focused.outlineColor, rgb(token['--focus-color']));
    await image(page, name + '-keyboard-focus', target);

    // Current spans must keep brand paint even under normal pointer hover.
    await nav.locator('[aria-current="page"]').hover();
    expectPaint(await paint(nav.locator('[aria-current="page"]')), token, 'active');
    expectPaint(await paint(nav.locator('[aria-disabled="true"]').first()), token, 'disabled');
    if (scope === 'admin') {
      assert.equal((await paint(nav.locator('[aria-current="page"]'))).cursor, 'default');
      assert.equal((await paint(nav.locator('[aria-disabled="true"]').first())).cursor, 'not-allowed');
    }
    await image(page, name + '-current-hover', target);
    await page.setViewportSize({ width: 390, height: 844 });
    const sizes = await page.locator('.synapse-pagination .page-link').evaluateAll(links => links
      .filter(el => el.getClientRects().length).map(el => ({ height: el.getBoundingClientRect().height,
        minHeight: getComputedStyle(el).minHeight })));
    assert.ok(sizes.length >= 7);
    assert.ok(sizes.every(size => size.height >= 40 && parseFloat(size.minHeight) >= 40), JSON.stringify(sizes));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No horizontal document overflow');
    assert.deepEqual(await unchangedSurface(page, scope, theme), surface, 'Resize does not repaint/rebuild the card');
    if (scope === 'admin') {
      const footer = await page.locator('.adm-pagination-band').evaluate(el => {
        const s = getComputedStyle(el);
        return { top: s.borderTopWidth, padding: parseFloat(s.paddingTop),
          rem: parseFloat(getComputedStyle(document.documentElement).fontSize) };
      });
      assert.equal(footer.top, '1px');
      assert.ok(Math.abs(footer.padding - 0.85 * footer.rem) < 0.01, 'Original .85rem footer padding is retained');
    }
    await image(page, name + '-mobile', scope === 'modal' ? undefined : target);
  });

  test('actual partial URLs, independent cursors, hooks, and boundary spans remain intact', async t => {
    const page = await open(t);
    const generic = pager(page, 'generic'), next = generic.getByRole('link', { name: 'Next', exact: true });
    const url = new URL(await next.getAttribute('href'));
    assert.equal(url.searchParams.get('table_page'), '2');
    assert.equal(url.searchParams.get('content_page'), '1');
    assert.equal(url.searchParams.get('table_per_page'), '25');
    assert.equal(url.searchParams.get('content_per_page'), '12');
    assert.equal(url.searchParams.get('q'), 'synthetic only');
    assert.equal(url.searchParams.get('kind'), 'sample');
    assert.equal(url.searchParams.get('status'), 'open');
    assert.equal(url.hash, '#theme-records');
    assert.equal(await next.getAttribute('data-table-page'), '2');
    assert.notEqual(await next.getAttribute('data-filter-link'), null);
    assert.notEqual(await next.getAttribute('data-pagination-navigation'), null);
    await next.click();
    assert.equal(await pager(page, 'generic').locator('[aria-current="page"]').innerText(), '2');
    assert.equal(await pager(page, 'portal').locator('[aria-current="page"]').innerText(), '1');
    await image(page, 't09-table-next-cursor');
    const contentNext = pager(page, 'portal').getByRole('link', { name: 'Next', exact: true });
    assert.equal(await contentNext.getAttribute('rel'), 'next');
    assert.notEqual(await contentNext.getAttribute('data-filter-link'), null);
    await contentNext.click();
    assert.equal(await pager(page, 'portal').locator('[aria-current="page"]').innerText(), '2');
    assert.equal(await pager(page, 'generic').locator('[aria-current="page"]').innerText(), '2');
    assert.equal(await pager(page, 'portal').getByRole('link', { name: 'Previous', exact: true }).getAttribute('rel'), 'prev');
    await image(page, 't09-content-next-independent-cursor');
    await pager(page, 'generic').getByRole('link', { name: 'Last', exact: true }).click();
    const last = pager(page, 'generic');
    assert.equal(await last.locator('[aria-current="page"]').innerText(), '5');
    assert.equal(await last.getByRole('link', { name: 'Next', exact: true }).count(), 0);
    assert.equal(await last.locator('[aria-disabled="true"]').count(), 2);
    await image(page, 't09-last-page-boundary');
    await modal(page);
    assert.notEqual(await pager(page, 'modal').getByRole('link', { name: 'Previous', exact: true }).getAttribute('data-app-modal-page'), null);
    await image(page, 't09-modal-hook-preserved');
  });

  test('unhooked admin and MI legacy pagers retain their existing paint', async t => {
    const page = await open(t);
    await page.getByText('Legacy pagers without shared hook', { exact: true }).click();
    const admin = page.getByRole('navigation', { name: 'Legacy admin pages', exact: true });
    const mi = page.getByRole('navigation', { name: 'Legacy MI pages', exact: true });
    assert.doesNotMatch(await admin.locator('ul').getAttribute('class'), /synapse-pagination/);
    assert.equal((await paint(admin.getByRole('link', { name: 'Next', exact: true }))).bg, 'rgb(255, 255, 255)');
    assert.equal((await paint(admin.locator('[aria-disabled="true"]').first())).opacity, '0.45');
    assert.equal((await paint(mi.getByRole('link', { name: 'Next', exact: true }))).bg, 'rgb(255, 255, 255)');
    assert.equal((await paint(mi.locator('[aria-current="page"]'))).bg, 'rgb(128, 0, 0)');
    await image(page, 't10-unhooked-legacy-paint', page.locator('details'));
  });

  test('synthetic renderer and HTTP routes reject application paths, writes, and unallowlisted inputs', async () => {
    assert.equal((await fetch(origin + '/pagination-theme', { method: 'POST' })).status, 405);
    for (const resource of ['/admin', '/portal', '/.env', '/assets/../.env', '/pagination-theme?file=app.php', '/pagination-theme?table_page=999']) {
      assert.ok([400, 404].includes((await fetch(origin + resource)).status));
    }
    for (const input of [{ origin: 'https://example.test' }, { origin, theme: 'unknown' }, { origin, partial: '../bootstrap' }]) {
      const result = spawnSync('php', ['-n', renderer], { cwd: root, input: JSON.stringify(input), encoding: 'utf8', timeout: 5000 });
      assert.equal(result.status, 2);
      assert.equal(result.stdout, '');
    }
  });
}
