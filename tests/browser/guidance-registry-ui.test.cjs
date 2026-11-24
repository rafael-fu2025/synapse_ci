'use strict';

// Real five-view UI regressions only. Pure invented GET data; no app server,
// dotenv, persistent database, mutation requests, installs, or injected features.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const support = path.join(root, 'tests/_support');
const renderer = path.join(support, 'render-guidance-registry-ui-fixture.php');
const evidence = path.join(root, 'writable/guidance-registry-ui-preview');
const screens = [
  { id: 'intake', path: '/guidance/intake', select: 'intakePerPage', pages: ['intake_page', 'incoming_page'], tables: ['gi-intake-table', 'gi-clinic-bridge-table'], totals: [61, 37] },
  { id: 'episodes', path: '/guidance/episodes', select: 'episodesPerPage', pages: ['page'], tables: ['ge-episodes-table'], totals: [61], tab: 'Active', filtered: 15, status: 'active', modal: 'openEpisodeModal', opener: 'Open Episode' },
  { id: 'cosign', path: '/guidance/clinical/cosign-queue', select: 'cosignQueuePerPage', pages: ['page'], tables: ['gcq-table'], totals: [61] },
  { id: 'practitioners', path: '/guidance/governance/practitioners', select: 'practitionersPerPage', pages: ['profiles_page', 'supervision_page'], tables: ['gpr-profiles-table', 'gpr-supervisions-table'], totals: [61, 37] },
  { id: 'call-slips', path: '/guidance/call-slips', select: 'callSlipsPerPage', pages: ['page'], tables: ['gcs-callslips-table'], totals: [61], tab: 'Pending', filtered: 13, status: 'pending', modal: 'issueCallSlipModal', opener: 'Issue Call Slip' },
];
const modes = [
  { name: 'desktop-light', theme: 'light', viewport: { width: 1440, height: 1000 } },
  { name: 'mobile-dark', theme: 'dark', viewport: { width: 390, height: 844 }, isMobile: true },
];
const jsAssets = ['custom-dropdown', 'datepicker', 'datetimepicker', 'date-range-picker', 'loading', 'form-loading', 'csrf', 'toast', 'confirm',
  'person-lookup-popover', 'live-filters', 'page-styles', 'form-modal', 'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions'];
const cssAssets = fs.readFileSync(path.join(root, 'public/assets/css/style.css'), 'utf8');
const allowedAssets = new Set([
  '/assets/css/style.css', ...Array.from(cssAssets.matchAll(/@import\s+url\("([^"]+)"\)/g), match => '/assets/css/' + match[1]),
  '/assets/css/pages/guidance-cosign-queue.css', '/assets/css/pages/guidance-practitioners.css',
  ...jsAssets.map(name => '/assets/js/' + name + '.js'),
  '/assets/vendor/bootstrap/css/bootstrap.min.css', '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/bootstrap-icons.css', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
  '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff', '/assets/vendor/alpine/alpine.min.js',
]);
const MIME = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.woff': 'font/woff' };
let server, browser, origin;
const cache = new Map();
const counts = { gets: 0, pages: 0, renders: 0, posts: 0, rejected: 0 };
const violations = [];

function allowed(method, value) {
  if (method !== 'GET') return false;
  let url;
  try { url = new URL(value, origin); } catch { return false; }
  if (url.origin !== origin || url.username || url.password || url.hash || /[\\\0]/.test(url.pathname)) return false;
  if (allowedAssets.has(url.pathname)) {
    return [...url.searchParams.keys()].every(key => key === 'dd67030699838ea613ee6dbda90effa6');
  }
  const screen = screens.find(screen => screen.path === url.pathname);
  if (!screen) return false;
  const keys = [...screen.pages, 'per_page', ...(screen.id === 'intake' ? ['status', 'priority_hint'] : screen.tab ? ['status'] : [])];
  if ([...url.searchParams.keys()].some(key => !keys.includes(key) || url.searchParams.getAll(key).length !== 1)) return false;
  return [...url.searchParams].every(([key, value]) => {
    if (screen.pages.includes(key)) return /^[1-9]\d{0,3}$/.test(value) && Number(value) <= 1000;
    if (key === 'per_page') return ['20', '25', '50', '100'].includes(value);
    if (key === 'priority_hint') return ['', 'routine', 'urgent', 'crisis'].includes(value);
    const statuses = screen.id === 'intake'
      ? ['', 'draft', 'submitted', 'received', 'triaged', 'accepted', 'declined', 'case_linked', 'withdrawn', 'external_handoff']
      : screen.id === 'episodes' ? ['', 'open', 'active', 'closed', 'transferred'] : ['', 'pending', 'acknowledged', 'attended', 'missed', 'cancelled'];
    return statuses.includes(value);
  });
}

function render(url, theme) {
  const key = theme + ':' + url.pathname + url.search;
  if (cache.has(key)) return cache.get(key);
  const env = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
    .filter(key => process.env[key]).map(key => [key, process.env[key]]));
  env.PAGINATION_ISOLATED_TESTS = '1';
  const result = spawnSync('php', ['-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
    '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', renderer], {
    cwd: support, env, input: JSON.stringify({ origin, path: url.pathname + url.search, theme }),
    encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 15000, maxBuffer: 2 * 1024 * 1024,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout || result.error?.message);
  assert.equal(result.stderr, '', 'Isolated actual views must render without PHP diagnostics');
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Notice:|Deprecated:|Parse error/);
  assert.match(result.stdout, /SYNTHETIC TEST ONLY/);
  cache.set(key, result.stdout);
  counts.renders++;
  return result.stdout;
}

function installedPlaywright() {
  const candidates = [path.join(root, 'tests/browser/node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')];
  const found = candidates.find(candidate => fs.existsSync(path.join(candidate, 'package.json')));
  assert.ok(found, 'An installed local Playwright is required; this suite never installs dependencies');
  return require(found);
}
function installedChromium(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const cachePath = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'win32'
    ? path.join(os.homedir(), 'AppData/Local/ms-playwright') : path.join(os.homedir(), '.cache/ms-playwright'));
  const found = fs.existsSync(cachePath) && fs.readdirSync(cachePath).filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium']
      .map(file => path.join(cachePath, name, file))).find(file => fs.existsSync(file));
  assert.ok(found, 'An already installed Chromium is required; this suite never downloads browsers');
  return found;
}

before(async () => {
  fs.mkdirSync(evidence, { recursive: true });
  server = http.createServer((request, response) => {
    if (!allowed(request.method, request.url)) {
      if (request.method === 'POST') counts.posts++;
      counts.rejected++;
      violations.push(request.method + ' ' + request.url);
      return response.writeHead(405, { 'Content-Type': 'text/plain' }).end('GET-only named synthetic fixtures');
    }
    counts.gets++;
    const url = new URL(request.url, origin);
    if (screens.some(screen => screen.path === url.pathname)) {
      counts.pages++;
      const theme = /(?:^|;\s*)synthetic_registry_theme=dark(?:;|$)/.test(request.headers.cookie || '') ? 'dark' : 'light';
      try {
        const html = render(url, theme);
        return response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }).end(html);
      } catch (error) {
        if (!response.headersSent) response.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
        return response.end(error.stack);
      }
    }
    const file = path.resolve(root, 'public', '.' + url.pathname);
    if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      violations.push('Missing allowed asset ' + url.pathname);
      return response.writeHead(404).end('Missing local asset');
    }
    response.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(response);
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = 'http://127.0.0.1:' + server.address().port;
  const { chromium } = installedPlaywright();
  browser = await chromium.launch({ headless: true, executablePath: installedChromium(chromium), timeout: 20000,
    args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run'] });
  console.log('Environment preparation is complete; formal testing is beginning. Disposable actual-view GET fixture at ' + origin);
}, { timeout: 30000 });

after(async () => {
  try { await browser?.close(); } finally {
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
  console.log('Guidance registry fixture totals: ' + JSON.stringify(counts));
  assert.equal(counts.posts, 0, 'No mutation endpoint may be requested');
  assert.equal(counts.rejected, 0);
  assert.deepEqual(violations, []);
});

const registryRoot = (page, screen) => page.locator(`[data-guidance-registry="${screen.id}"]`);
const pageFooter = (page, parameter) => page.locator(`[data-table-pagination][data-page-param="${parameter}"]`);
const rows = (page, table) => page.locator('table.' + table + ' > tbody > tr').filter({ has: page.locator('td[data-label]') });
const near = (actual, expected, message, tolerance = 2) => assert.ok(Math.abs(actual - expected) <= tolerance, `${message}: expected ${expected}, actual ${actual}`);

async function screenshot(page, name) {
  const file = path.join(evidence, name + '.png');
  await page.screenshot({ path: file, animations: 'disabled', timeout: 12000 });
  console.log('Guidance registry screenshot: ' + file);
  return file;
}
async function snapshot(page, screen, mode, suffix) {
  const data = await registryRoot(page, screen).evaluate(root => ({
    theme: document.documentElement.dataset.theme,
    busy: root.hasAttribute('aria-busy'),
    select: Array.from(root.querySelectorAll('select[name="per_page"]'), select => ({ value: select.value, hidden: select.getAttribute('aria-hidden'), native: select.hasAttribute('data-native-select') })),
    pages: Array.from(root.querySelectorAll('[data-table-pagination]'), footer => ({ parameter: footer.dataset.pageParam, page: footer.dataset.page,
      size: footer.dataset.perPage, filtered: footer.dataset.filtered, total: footer.dataset.total })),
    error: root.querySelector('[data-filter-error]:not([hidden])')?.textContent.trim() || '',
  }));
  assert.equal(data.theme, mode.theme);
  assert.equal(data.error, '');
  await screenshot(page, `${screen.id}-${mode.name}-${suffix}`);
  return data;
}
async function ready(page, screen) {
  await registryRoot(page, screen).waitFor({ state: 'visible' });
  await page.waitForFunction(id => document.readyState === 'complete' && window.BalaySelect && window.SynapsePageStyles
    && window.SynapseNavigation && window.SynapseLiveFilters && document.getElementById(id)?.dataset.bdSelect === '1'
    && !document.querySelector('#main')?.inert && !document.body.classList.contains('is-navigating'), screen.select);
}
async function waitMetadata(page, screen, size, current = 1, filtered = screen.totals[0]) {
  await page.waitForFunction(({ id, parameter, size, current, filtered }) => {
    const root = document.querySelector(`[data-guidance-registry="${id}"]`);
    const footer = root?.querySelector(`[data-table-pagination][data-page-param="${parameter}"]`);
    return footer?.dataset.perPage === String(size) && footer.dataset.page === String(current) && footer.dataset.filtered === String(filtered)
      && !root.hasAttribute('aria-busy') && !document.querySelector('#main')?.inert && !document.body.classList.contains('is-navigating');
  }, { id: screen.id, parameter: screen.pages[0], size, current, filtered });
  await ready(page, screen);
}

async function open(t, screen, mode) {
  const diagnostics = { denied: [], pageErrors: [], consoleErrors: [], failedResponses: [] };
  const record = (name, value) => { if (diagnostics[name].length < 20) diagnostics[name].push(value); };
  const context = await browser.newContext({ viewport: mode.viewport, isMobile: Boolean(mode.isMobile), colorScheme: mode.theme,
    reducedMotion: 'reduce', serviceWorkers: 'block', acceptDownloads: false });
  // Preparation chooses only the theme. It never pre-completes a widget/filter/modal action.
  await context.addCookies([{ name: 'synthetic_registry_theme', value: mode.theme, url: origin }]);
  await context.route('**/*', route => {
    const request = route.request();
    if (!allowed(request.method(), request.url())) {
      record('denied', request.method() + ' ' + request.url());
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  await context.routeWebSocket('**/*', socket => { record('denied', 'WebSocket ' + socket.url()); socket.close(); });
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  page.setDefaultNavigationTimeout(30000);
  page.on('pageerror', error => record('pageErrors', error.message));
  page.on('console', message => { if (message.type() === 'error') record('consoleErrors', message.text()); });
  page.on('response', response => { if (response.status() >= 400) record('failedResponses', response.status() + ' ' + response.url()); });
  page.on('download', download => { record('denied', 'Unexpected download'); void download.cancel(); });
  page.on('popup', popup => { record('denied', 'Unexpected popup'); void popup.close(); });
  page.on('dialog', dialog => { record('denied', 'Unexpected native dialog ' + dialog.message()); void dialog.dismiss(); });
  t.after(async () => {
    await context.close();
    console.log(`${screen.id} ${mode.name} diagnostics: ` + JSON.stringify(diagnostics));
    for (const [name, values] of Object.entries(diagnostics)) assert.deepEqual(values, [], name);
  });
  const query = new URLSearchParams({ per_page: '25', ...Object.fromEntries(screen.pages.map(parameter => [parameter, '2'])) });
  const response = await page.goto(origin + screen.path + '?' + query, { waitUntil: 'domcontentloaded' });
  assert.equal(response.status(), 200, await response.text());
  await ready(page, screen);
  return page;
}

async function assertWidget(page, screen, value) {
  const source = page.locator('#' + screen.select);
  assert.equal(await source.inputValue(), String(value));
  assert.equal(await source.getAttribute('data-native-select'), null);
  assert.equal(await source.getAttribute('aria-hidden'), 'true');
  assert.equal(await source.getAttribute('tabindex'), '-1');
  const hidden = await source.evaluate(select => {
    const css = getComputedStyle(select), box = select.getBoundingClientRect();
    return { source: select.classList.contains('bd-select-source'), width: box.width, height: box.height,
      clip: css.clip, position: css.position, events: css.pointerEvents, form: select.form?.method };
  });
  assert.equal(hidden.source, true);
  assert.equal(hidden.position, 'absolute', 'The native source is removed from layout');
  assert.equal(hidden.clip, 'rect(0px, 0px, 0px, 0px)');
  assert.equal(hidden.events, 'none');
  assert.equal(hidden.form, 'get');
  const control = source.locator('..').locator('.bd-select-toggle');
  assert.equal(await control.count(), 1);
  assert.equal(await control.isVisible(), true);
  assert.equal(await control.getAttribute('role'), 'combobox');
  assert.match(await control.getAttribute('aria-label'), new RegExp('^Rows per page: ' + value));
  const box = await control.boundingBox();
  assert.ok(box.width > 50 && box.height >= 32, 'The actual custom dropdown is operable and compact');
  const label = await control.locator('.bd-select-label').evaluate(element => ({ width: element.clientWidth, scroll: element.scrollWidth }));
  assert.ok(label.scroll <= label.width + 1, 'The selected page-size label is fully readable: ' + JSON.stringify(label));
  return control;
}

async function assertLayout(page, screen, mode) {
  const geometry = await registryRoot(page, screen).evaluate(root => {
    const rect = element => { const box = element.getBoundingClientRect(); return { x: box.x, y: box.y, right: box.right, bottom: box.bottom, width: box.width, height: box.height }; };
    const style = element => { const css = getComputedStyle(element); return { display: css.display, background: css.backgroundColor, border: css.borderTopWidth,
      padding: [css.paddingTop, css.paddingRight, css.paddingBottom, css.paddingLeft], columns: css.gridTemplateColumns }; };
    const form = root.querySelector('select[name="per_page"]').form;
    const toolbar = root.querySelector('.ce-registry-toolbar');
    const tabs = root.querySelector('nav[data-filter-region="tabs"]');
    const header = form.closest('.ce-card-header');
    return { viewport: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth,
      root: rect(root), form: rect(form), formStyle: style(form), oldForm: form.classList.contains('gc-case-filter-form'),
      oldActions: Boolean(form.querySelector('.ce-filter-actions')), columns: style(form).columns,
      controls: Array.from(form.querySelectorAll('.bd-select-toggle, button[type="submit"], a[data-filter-reset]'), rect),
      toolbar: toolbar ? { box: rect(toolbar), css: style(toolbar) } : null,
      tabs: tabs ? { tag: tabs.tagName, box: rect(tabs), sibling: tabs.parentElement === form.parentElement, wrapsForm: tabs.contains(form) } : null,
      header: header ? { formInActions: Boolean(form.closest('.ce-registry-header-actions')), box: rect(header) } : null,
      outsideWorkspace: !form.closest('[data-filter-region]'),
      persistentContext: Boolean(root.querySelector('.ce-registry-context') && !root.querySelector('.ce-registry-context').closest('[data-filter-region]')),
    };
  });
  assert.ok(geometry.document <= geometry.viewport + 1 && geometry.body <= geometry.viewport + 1, 'No document-level horizontal overflow: ' + JSON.stringify(geometry));
  assert.ok(geometry.root.x >= -1 && geometry.root.right <= geometry.viewport + 1, 'The actual registry fits the viewport');
  for (const control of geometry.controls) assert.ok(control.x >= geometry.root.x - 1 && control.right <= geometry.root.right + 1, 'Controls do not escape the registry');
  assert.equal(geometry.outsideWorkspace, true, 'The GET page-size form is not replaced with results');
  if (screen.id === 'intake') {
    assert.equal(geometry.oldForm, false);
    assert.equal(geometry.oldActions, false);
    assert.equal(geometry.formStyle.display, 'grid');
    assert.equal(geometry.columns.split(' ').length, mode.isMobile ? 1 : 4, 'Intake uses its own four-column desktop / one-column mobile grid');
    const [status, priority, size, filter, reset] = geometry.controls;
    near(filter.y + filter.height / 2, reset.y + reset.height / 2, 'Filter and Reset stay aligned');
    if (!mode.isMobile) {
      for (const control of [priority, size, filter, reset]) near(control.y + control.height / 2, status.y + status.height / 2, 'All desktop intake controls share a row center');
      assert.ok(status.right < priority.x && priority.right < size.x && size.right <= filter.x, 'Four distinct desktop grid columns avoid the old action overlap');
    } else {
      near(status.x, priority.x, 'Mobile filter columns share their left edge');
      near(priority.x, size.x, 'Mobile row size aligns with filters');
      assert.ok(status.bottom <= priority.y && priority.bottom <= size.y && size.bottom <= filter.y + 1, 'Mobile filters stack without overlap');
    }
  } else {
    assert.equal(geometry.formStyle.display, 'flex');
    assert.equal(geometry.formStyle.background, 'rgba(0, 0, 0, 0)', 'Compact page-size form is unboxed');
    assert.equal(geometry.formStyle.border, '0px');
    assert.deepEqual(geometry.formStyle.padding, ['0px', '0px', '0px', '0px']);
    assert.ok(geometry.form.height <= 44, 'Compact page-size form must not become a full-width stacked toolbar');
    const size = geometry.controls[0], apply = geometry.controls[1];
    near(size.y + size.height / 2, apply.y + apply.height / 2, 'Page-size dropdown and Apply align');
    if (screen.tab) {
      assert.equal(geometry.tabs.tag, 'NAV');
      assert.equal(geometry.tabs.sibling, true, 'Tabs and persistent form are siblings');
      assert.equal(geometry.tabs.wrapsForm, false);
      assert.equal(geometry.toolbar.css.display, 'flex');
      assert.equal(geometry.toolbar.css.background, 'rgba(0, 0, 0, 0)');
      assert.equal(geometry.toolbar.css.border, '0px');
      if (!mode.isMobile) near(geometry.tabs.box.y + geometry.tabs.box.height / 2, geometry.form.y + geometry.form.height / 2, 'Desktop tabs and page-size control share one toolbar row', 4);
      else assert.ok(geometry.tabs.box.bottom <= geometry.form.y + 1, 'Mobile tabs and page-size control wrap cleanly');
    }
    if (screen.id === 'cosign') assert.equal(geometry.header.formInActions, true, 'Co-sign size control belongs inside the ledger header actions');
    if (screen.id === 'practitioners') assert.equal(geometry.persistentContext, true, 'Credential context remains outside the replaceable workspace');
  }
}

async function assertFooters(page, screen, mode, suffix) {
  assert.equal(await registryRoot(page, screen).locator('[data-table-pagination]').count(), screen.pages.length);
  for (const parameter of screen.pages) {
    const footer = pageFooter(page, parameter);
    await footer.scrollIntoViewIfNeeded();
    await screenshot(page, `${screen.id}-${mode.name}-${suffix}-${parameter}-footer`);
    const box = await footer.evaluate(footer => {
      const css = getComputedStyle(footer), rect = footer.getBoundingClientRect(), card = footer.parentElement.getBoundingClientRect();
      const child = element => { const bounds = element.getBoundingClientRect(); return { x: bounds.x, right: bounds.right, y: bounds.y, bottom: bounds.bottom }; };
      const pagination = footer.querySelector('.pagination');
      const links = [...pagination.querySelectorAll('.page-link')].map(link => link.getBoundingClientRect());
      return { className: footer.className, parent: footer.parentElement.classList.contains('ce-card'),
        columnGap: getComputedStyle(pagination).columnGap,
        segmentGaps: links.slice(1).flatMap((box, index) => Math.abs(box.top - links[index].top) <= 1
          ? [box.left - links[index].right] : []),
        padding: [css.paddingTop, css.paddingRight, css.paddingBottom, css.paddingLeft], border: css.borderTopWidth,
        rect: { x: rect.x, y: rect.y, right: rect.right, bottom: rect.bottom }, card: { x: card.x, right: card.right },
        paragraph: child(footer.querySelector('p')), nav: child(footer.querySelector('nav')), viewport: innerWidth };
    });
    assert.match(box.className, /\bce-table-footer\b/);
    assert.equal(box.columnGap, '0px', 'Pagination buttons have no space between segments');
    assert.ok(box.segmentGaps.every(gap => gap >= -1.1 && gap <= 0.1), 'Pagination segment borders touch: ' + JSON.stringify(box.segmentGaps));
    assert.doesNotMatch(box.className, /\bmt-3\b/);
    assert.equal(box.parent, true, 'Footer is an actual ledger card child');
    assert.deepEqual(box.padding, ['16px', '16px', '16px', '16px'], 'Ledger footer retains a 16px content inset');
    assert.equal(box.border, '1px');
    near(box.paragraph.x - box.card.x, 17, 'Footer summary uses 16px padding inside the card border');
    for (const child of [box.paragraph, box.nav]) {
      assert.ok(child.x >= box.rect.x + 15 && child.right <= box.rect.right - 15, 'Footer content respects both horizontal insets');
      assert.ok(child.y >= box.rect.y + 16 && child.bottom <= box.rect.bottom - 15, 'Footer content respects vertical insets');
    }
    assert.ok(box.rect.x >= -1 && box.rect.right <= box.viewport + 1, 'Footer causes no horizontal page overflow');
  }
}

async function choose(page, screen, mode, id, label, suffix) {
  const source = page.locator('#' + id);
  const control = source.locator('..').locator('.bd-select-toggle');
  // Reach the real control before clicking; pending scroll events otherwise
  // legitimately dismiss a dropdown immediately after long-ledger navigation.
  await control.scrollIntoViewIfNeeded();
  await snapshot(page, screen, mode, suffix + '-before');
  await control.click();
  const menuId = await control.getAttribute('aria-controls');
  const menu = page.locator('#' + menuId);
  await menu.waitFor({ state: 'visible' });
  assert.equal(await control.getAttribute('aria-expanded'), 'true');
  assert.equal(await menu.getAttribute('role'), 'listbox');
  const box = await menu.boundingBox();
  assert.ok(box.x >= -1 && box.x + box.width <= mode.viewport.width + 1, 'Visible dropdown stays in the viewport');
  await snapshot(page, screen, mode, suffix + '-menu');
  const option = menu.getByRole('option', { name: label, exact: true });
  assert.equal(await option.isVisible(), true);
  await option.click();
  await menu.waitFor({ state: 'hidden' });
}

async function modal(page, screen, mode) {
  const opener = page.locator('.page-actions').getByRole('button', { name: screen.opener, exact: true });
  await opener.click();
  const dialog = page.locator('#' + screen.modal);
  await dialog.waitFor({ state: 'visible' });
  await page.waitForFunction(id => document.getElementById(id)?.classList.contains('show') && document.getElementById(id).contains(document.activeElement), screen.modal);
  assert.match(await dialog.locator('.modal-dialog').getAttribute('class'), /\bmodal-dialog-centered\b/);
  const box = await dialog.locator('.modal-content').boundingBox();
  near(box.x + box.width / 2, mode.viewport.width / 2, 'Actual modal is horizontally centered', 3);
  near(box.y + box.height / 2, mode.viewport.height / 2, 'Actual modal is vertically centered', 3);
  assert.ok(box.x >= -1 && box.x + box.width <= mode.viewport.width + 1 && box.y >= 0 && box.y + box.height <= mode.viewport.height + 1, 'Modal fits its viewport');
  await screenshot(page, `${screen.id}-${mode.name}-modal-open`);
  // Click a visible field to start normal keyboard traversal inside the form.
  // Do not mistake browser-chrome traversal from the initially focused modal
  // container for an in-form focus containment regression.
  await dialog.locator('input[type="search"], input[type="text"]:not([data-datepicker]):not([data-timepicker])').first().click();
  await screenshot(page, `${screen.id}-${mode.name}-modal-field-focus`);
  for (const [index, key] of ['Tab', 'Shift+Tab', 'Tab'].entries()) {
    await page.keyboard.press(key);
    assert.equal(await dialog.evaluate(dialog => dialog.contains(document.activeElement)), true, 'Normal field traversal remains inside the actual modal');
    await screenshot(page, `${screen.id}-${mode.name}-modal-focus-${index + 1}`);
  }
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await dialog.waitFor({ state: 'hidden' });
  await page.waitForFunction(id => !document.body.classList.contains('modal-open') && !document.querySelector('.modal-backdrop')
    && document.activeElement?.getAttribute('data-bs-target') === '#' + id, screen.modal);
  assert.equal(await opener.evaluate(button => button === document.activeElement), true, 'Cancel returns focus to the real opener');
  await snapshot(page, screen, mode, 'modal-cancel-focus-return');
}

for (const screen of screens) for (const mode of modes) {
  test(`${screen.id}: ${mode.name} compact controls, real custom paging, inset footers${screen.modal ? ', centered keyboard-safe modal' : ''}`, { timeout: 60000 }, async t => {
    const page = await open(t, screen, mode);
    try {
      await snapshot(page, screen, mode, 'initial');
      assert.equal(await registryRoot(page, screen).count(), 1);
      await assertWidget(page, screen, 25);
      await assertLayout(page, screen, mode);
      for (const [index, parameter] of screen.pages.entries()) {
        assert.equal(await pageFooter(page, parameter).getAttribute('data-page'), '2');
        assert.equal(await pageFooter(page, parameter).getAttribute('data-total'), String(screen.totals[index]));
        assert.equal(await rows(page, screen.tables[index]).count(), Math.min(25, screen.totals[index] - 25));
      }
      const persistentForm = screen.id === 'cosign' ? null : await page.locator('#' + screen.select).locator('xpath=ancestor::form').elementHandle();
      const persistentContext = screen.id === 'practitioners' ? await registryRoot(page, screen).locator('.ce-registry-context').elementHandle() : null;
      await choose(page, screen, mode, screen.select, screen.id === 'intake' ? '20 rows per page' : '20', 'page-size');
      if (screen.id === 'cosign') {
        await assertWidget(page, screen, 20);
        assert.equal(await pageFooter(page, 'page').getAttribute('data-per-page'), '25', 'Co-sign GET form waits for Apply');
        await snapshot(page, screen, mode, 'page-size-selected');
        await page.locator('#' + screen.select).locator('xpath=ancestor::form').getByRole('button', { name: 'Apply', exact: true }).click();
      }
      await waitMetadata(page, screen, 20);
      await snapshot(page, screen, mode, 'page-size-applied');
      await assertWidget(page, screen, 20);
      await assertLayout(page, screen, mode);
      assert.equal(new URL(page.url()).searchParams.get('per_page'), '20');
      for (const [index, parameter] of screen.pages.entries()) {
        assert.equal(await pageFooter(page, parameter).getAttribute('data-page'), '1', 'Size changes reset every independent cursor');
        assert.equal(await pageFooter(page, parameter).getAttribute('data-per-page'), '20');
        assert.equal(await rows(page, screen.tables[index]).count(), 20);
      }
      if (persistentForm) assert.equal(await persistentForm.evaluate(form => form.isConnected), true, 'Live paging preserves the actual form');
      if (persistentContext) assert.equal(await persistentContext.evaluate(context => context.isConnected), true, 'Live paging preserves credential context');
      await pageFooter(page, screen.pages[0]).getByRole('link', { name: 'Next', exact: true }).click();
      await waitMetadata(page, screen, 20, 2);
      await pageFooter(page, screen.pages[0]).scrollIntoViewIfNeeded();
      await snapshot(page, screen, mode, 'next-page');
      assert.equal(new URL(page.url()).searchParams.get(screen.pages[0]), '2');
      await assertWidget(page, screen, 20);
      if (screen.pages.length === 2) assert.equal(await pageFooter(page, screen.pages[1]).getAttribute('data-page'), '1', 'The other cursor stays independent');
      if (screen.id === 'intake') {
        await choose(page, screen, mode, 'intakeStatusFilter', 'Submitted', 'status-filter');
        await waitMetadata(page, screen, 20, 1, 31);
        await snapshot(page, screen, mode, 'status-filtered');
        assert.equal(await page.locator('#intakeStatusFilter').inputValue(), 'submitted');
        await choose(page, screen, mode, 'intakePriorityFilter', 'Urgent', 'priority-filter');
        await waitMetadata(page, screen, 20, 1, 10);
        await snapshot(page, screen, mode, 'priority-filtered');
        assert.equal(await rows(page, screen.tables[0]).count(), 10);
        await registryRoot(page, screen).getByRole('link', { name: 'Reset', exact: true }).click();
        await waitMetadata(page, screen, 20);
        await snapshot(page, screen, mode, 'filters-reset');
        assert.equal(await page.locator('#intakeStatusFilter').inputValue(), '');
        assert.equal(await page.locator('#intakePriorityFilter').inputValue(), '');
        await assertLayout(page, screen, mode);
      } else if (screen.tab) {
        const tabs = registryRoot(page, screen).locator('nav[data-filter-region="tabs"]');
        await tabs.getByRole('link', { name: new RegExp('^' + screen.tab + '(?:\\s|$)') }).click();
        await waitMetadata(page, screen, 20, 1, screen.filtered);
        await snapshot(page, screen, mode, 'tab-filtered');
        assert.equal(new URL(page.url()).searchParams.get('status'), screen.status);
        assert.equal(await page.locator('#' + screen.select).locator('xpath=ancestor::form').locator('input[name="status"]').inputValue(), screen.status);
        await tabs.getByRole('link', { name: /^All(?:\s|$)/ }).click();
        await waitMetadata(page, screen, 20);
        await snapshot(page, screen, mode, 'tabs-reset');
        await assertLayout(page, screen, mode);
        assert.equal(await persistentForm.evaluate(form => form.isConnected), true, 'Tab filtering never replaces the sibling form');
      }
      if (screen.modal) await modal(page, screen, mode);
      if (persistentContext) assert.equal(await persistentContext.evaluate(context => context.isConnected), true);
      await assertFooters(page, screen, mode, 'inset');
      await snapshot(page, screen, mode, 'final');
    } catch (error) {
      await screenshot(page, `${screen.id}-${mode.name}-FAILED`).catch(() => {});
      console.error(`Guidance registry first failure ${screen.id} ${mode.name}: ${error.message}`);
      console.error('Retained actual DOM: ' + await registryRoot(page, screen).evaluate(root => ({
        form: root.querySelector('select[name="per_page"]')?.form.className,
        select: root.querySelector('select[name="per_page"]')?.outerHTML.slice(0, 1500),
        geometry: Array.from(root.querySelectorAll('.ce-registry-toolbar, .ce-registry-page-size, .ce-intake-filter-form'), element => {
          const box = element.getBoundingClientRect(), css = getComputedStyle(element);
          return { className: element.className, x: box.x, y: box.y, width: box.width, height: box.height, columns: css.gridTemplateColumns };
        }),
        status: root.querySelector('[data-filter-status]')?.textContent, error: root.querySelector('[data-filter-error-text]')?.textContent,
        footers: Array.from(root.querySelectorAll('[data-table-pagination]'), footer => ({ parameter: footer.dataset.pageParam, page: footer.dataset.page, size: footer.dataset.perPage })),
        active: document.activeElement?.outerHTML.slice(0, 500),
      })).then(JSON.stringify).catch(() => 'Page unavailable'));
      throw error;
    }
  });
}
