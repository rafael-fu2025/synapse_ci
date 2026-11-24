'use strict';

// Real Clinic/Guidance views, ApexCharts, ChartTheme and progressive navigation.
// Every count is invented; no app bootstrap, database or external traffic.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const vm = require('node:vm');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(__dirname, '../_support/render-clinic-dashboard-fixture.php');
const playground = [path.join(__dirname, 'node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')]
  .find(candidate => fs.existsSync(path.join(candidate, 'package.json')));
assert.ok(playground, 'Existing local Playwright is required; this suite never installs packages.');
const { chromium } = require(playground);
const jsPaths = new Set(['vendor/apexcharts/apexcharts.min', 'js/chart-theme', 'js/navigation', 'js/csrf', 'js/queue-live'].map(name => '/assets/' + name + '.js'));
const chartId = 'clinicActivityIntake';
const guidanceChartId = 'guidanceActivityTrajectory';
const evidenceDir = path.join(root, 'writable/clinic-dashboard-chart-preview');
const counts = {
  '7d': { days: ['Nov 17', 'Nov 18', 'Nov 19', 'Nov 20', 'Nov 21', 'Nov 22', 'Nov 23'], series: [3, 0, 8, 4, 2, 9, 5] },
  '30d': { days: Array.from({ length: 30 }, (_, i) => 'Day ' + (i + 1)), series: Array.from({ length: 30 }, (_, i) => (i * 7 + 2) % 17) },
  sy: { days: ['Jun 2031', 'Jul 2031', 'Aug 2031', 'Sep 2031', 'Oct 2031', 'Nov 2031'], series: [13, 6, 28, 12, 3, 19] },
};
function rangeData(key, dashboard = 'clinic') {
  const data = counts[key];
  return dashboard === 'guidance' ? { days: data.days, series: data.series.map(value => value * 2 + 1) } : data;
}
function payload(key, dashboard = 'clinic') {
  const data = rangeData(key, dashboard);
  const total = data.series.reduce((sum, value) => sum + value, 0);
  const peakValue = Math.max(...data.series);
  const subtitle = dashboard === 'guidance'
    ? (key === 'sy' ? 'SY 2031-2032 monthly guidance engagements' : `${key === '30d' ? '30' : '7'}-day session & engagement trajectory · Manila business days`)
    : (key === 'sy' ? 'SY 2031-2032 monthly clinical intake' : `${key === '30d' ? '30' : '7'}-day clinical intake trajectory · Manila business days`);
  return { ...data, rangeKey: key, schoolYear: '2031-2032', total, average: Number((total / data.series.length).toFixed(1)), peakValue,
    peakLabel: data.days[data.series.indexOf(peakValue)], subtitle,
    avgLabel: key === 'sy' ? 'Monthly Average' : 'Daily Average', avgUnit: key === 'sy' ? '/ month' : '/ day' };
}
let server, browser, origin, scenarioSequence = 0;
const scenarios = new Map();
const renderCache = new Map();
function renderSections(key, theme, dashboard = 'clinic') {
  const cacheKey = dashboard + key + theme;
  if (!renderCache.has(cacheKey)) {
    const env = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
      .filter(key => process.env[key]).map(key => [key, process.env[key]]));
    const output = execFileSync('php', ['-n', '-d', `open_basedir=${[path.join(root, 'app/Views'), path.dirname(renderer)].join(path.delimiter)}`,
      '-d', 'allow_url_fopen=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'display_errors=stderr', renderer], {
      cwd: root, env, input: JSON.stringify({ ...rangeData(key, dashboard), dashboard, rangeKey: key, theme, origin }), encoding: 'utf8', timeout: 15000, maxBuffer: 1048576,
    });
    renderCache.set(cacheKey, JSON.parse(output));
  }
  return renderCache.get(cacheKey);
}
function typographyFixture(theme) {
  const samples = [
    ['Guidance / Clinic IDs', 'ce-workspace', 'ce-mono'],
    ['Queue counts', '', 'q-mono'],
    ['Dashboard badges', 'cdc-dashboard', 'cdc-mono'],
    ['Teams counts', 'tws-workspace', 'tws-count'],
    ['AI Insights IDs', 'ai-insights', 'ai-insights__id'],
    ['Admin percentages', '', 'sh-metric-share'],
    ['Portal profile IDs', 'portal-profile-card', 'card-id'],
    ['Ticket IDs', '', 'ticket-id'],
    ['Bootstrap code', '', 'font-monospace'],
  ];
  return `<!doctype html><html lang="en" data-theme="${theme}"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,">
    <title>Synthetic shared typography</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/queue.css">
    <link rel="stylesheet" href="/assets/css/apps.css">
    <style>
      body { margin: 0; padding: 24px; }
      .font-evidence { max-width: 860px; margin: auto; }
      .font-samples { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
      .font-sample { min-width: 0; padding: 14px; border: 1px solid var(--border-color); background: var(--surface-color); border-radius: 4px; }
      .font-label { display: block; margin-bottom: 10px; font-family: var(--default-font); font-size: 14px; }
      .font-value { display: block; font-size: 24px !important; line-height: 1.6; color: var(--default-color) !important; overflow-wrap: anywhere; }
      .font-sample.ai-insights { display: block; }
      .font-sample.portal-profile-card :is(.font-label, .font-value) { color: #ffffff !important; }
    </style></head><body><main class="font-evidence">
    <h1>Plain-zero typography</h1><p>SYNTHETIC TEST ONLY — shared styles, invented IDs and numbers.</p>
    <div class="font-samples">${samples.map(([label, parent, utility]) => `<section class="font-sample ${parent}"><span class="font-label">${label}</span><span class="font-value ${utility}" data-font-sample>${'0 10 100 1000'}<br>${'SYN-001020'}</span></section>`).join('')}</div>
    </main></body></html>`;
}

function documentFixture(state, url) {
  if (url.pathname === '/typography') return typographyFixture(state.theme);
  const dashboard = ['/guidance', '/guidance/dashboard'].includes(url.pathname) ? 'guidance' : (url.pathname === '/clinic' ? 'clinic' : null);
  const key = url.searchParams.get('range') || '7d';
  const sections = dashboard ? renderSections(key, state.theme, dashboard) : {};
  const title = dashboard === 'guidance' ? 'Guidance Workstation Dashboard' : (dashboard === 'clinic' ? 'Clinic Workstation Dashboard' : 'Synthetic Neighbor');
  return `<!doctype html><html lang="en" data-theme="${state.theme}"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,">
    <title>Synthetic dashboard chart regression</title>
    <link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/navigation.css">
    <meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="synthetic-token"><meta name="csrf-header" content="X-Fixture-CSRF">
    </head><body class="app-shell" data-navigation-scope="synthetic-clinic-dashboard">
    <div id="page-styles" hidden>${sections.styles || ''}</div>
    <header id="header" class="header">SYNTHETIC TEST ONLY — invented dashboard counts</header>
    <aside id="sidebar" class="sidebar"><nav class="sidebar-nav"><a class="nav-link" href="${origin}/clinic">Clinic Dashboard</a><a class="nav-link" href="${origin}/guidance/dashboard">Guidance Dashboard</a><a class="nav-link" href="${origin}/chart-neighbor">Chart Test Neighbor</a></nav></aside>
    <main id="main" class="main"><div class="main-content"><div class="pagetitle"><h1>${title}</h1></div>
    <section class="section">${dashboard ? sections.content : '<p>Read-only navigation fixture.</p><a href="/clinic">Open Clinic Dashboard</a>'}</section></div></main>
    <script src="/assets/vendor/apexcharts/apexcharts.min.js"></script><script src="/assets/js/chart-theme.js"></script>
    <script src="/assets/js/csrf.js"></script><script src="/assets/js/navigation.js"></script>
    <div id="page-scripts" hidden>${sections.scripts || ''}</div>
    </body></html>`;
}
function allowed(url) {
  if (url.origin !== origin || url.username || url.password || /[\\\0]/.test(url.pathname)) return false;
  if (jsPaths.has(url.pathname)) return !url.search;
  if (url.pathname === '/assets/vendor/bootstrap/css/bootstrap.min.css') return !url.search;
  if (url.pathname.startsWith('/assets/css/') && /\.css$/.test(url.pathname) && !url.search) return true;
  if (['/clinic', '/guidance', '/guidance/dashboard', '/clinic/dashboard/intake-series', '/guidance/dashboard/session-series'].includes(url.pathname)) {
    return [...url.searchParams].every(([key, value]) => key === 'range' && Object.hasOwn(counts, value)) && [...url.searchParams].length <= 1;
  }
  return !url.search && ['/typography', '/chart-neighbor', '/clinic/dashboard/queue-counts', '/guidance/dashboard/queue-counts'].includes(url.pathname);
}

before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, origin);
    if (req.method !== 'GET' || !allowed(url)) { res.writeHead(404).end('Synthetic chart fixture only'); return; }
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, '.' + url.pathname);
      if (!file.startsWith(path.join(publicRoot, 'assets') + path.sep)) { res.writeHead(404).end(); return; }
      fs.readFile(file, (error, bytes) => {
        if (error) { res.writeHead(404).end(); return; }
        res.writeHead(200, { 'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' }).end(bytes);
      });
      return;
    }
    const id = /synthetic_clinic_chart=(\d+)/.exec(req.headers.cookie || '')?.[1];
    const state = scenarios.get(id);
    if (!state) { res.writeHead(404).end('Unknown scenario'); return; }
    state.requests.push({ path: url.pathname, range: url.searchParams.get('range'), headers: req.headers });
    res.setHeader('Cache-Control', 'private, no-store');
    if (['/clinic/dashboard/queue-counts', '/guidance/dashboard/queue-counts'].includes(url.pathname)) {
      const unit = url.pathname.startsWith('/guidance/') ? 'guidance' : 'clinic';
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ unit, date: '2031-11-23', counts: { queueToday: 0, queueWaiting: 0, queueCalled: 0, queueCompleted: 0 } }));
      return;
    }
    if (['/clinic/dashboard/intake-series', '/guidance/dashboard/session-series'].includes(url.pathname)) {
      const key = url.searchParams.get('range') || '7d';
      const dashboard = url.pathname.startsWith('/guidance/') ? 'guidance' : 'clinic';
      const status = state.statuses[key]?.shift() || 200;
      const respond = () => {
        if (res.destroyed) return;
        res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(status === 200 ? payload(key, dashboard) : { message: 'Synthetic unavailable range' }));
      };
      const delay = state.delays[key] || 0;
      if (delay) setTimeout(respond, delay); else respond();
      return;
    }
    try { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(documentFixture(state, url)); }
    catch (error) { console.error(error); res.end('Fixture rendering failed'); }
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'win32' ? path.join(os.homedir(), 'AppData/Local/ms-playwright') : path.join(os.homedir(), '.cache/ms-playwright'));
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome'].map(file => path.join(cache, name, file))).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'An existing Chromium browser is required.');
  browser = await chromium.launch({ headless: true, executablePath });
}, { timeout: 30000 });
after(async () => {
  await browser?.close();
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
async function open(t, { theme = 'light', width = 1440, range = '7d', start = '/clinic', reducedMotion = 'reduce', statuses = {}, delays = {}, recordAnimation = false } = {}) {
  const id = String(++scenarioSequence);
  const state = { id, theme, requests: [], statuses: structuredClone(statuses), delays };
  scenarios.set(id, state);
  const context = await browser.newContext({ viewport: { width, height: 1000 }, colorScheme: theme, reducedMotion, serviceWorkers: 'block' });
  await context.addCookies([{ name: 'synthetic_clinic_chart', value: id, url: origin }]);
  if (recordAnimation) {
    // Observe rendered SVG frames from setup; never change chart state or interactions.
    await context.addInitScript(() => {
      const frames = [];
      Object.defineProperty(window, 'fixtureChartFrames', { value: frames });
      let previous = '';
      function sample() {
        const chart = window.ApexCharts?.getChartByID('clinicActivityIntake');
        const d = document.querySelector('#clinicActivityChart .apexcharts-area')?.getAttribute('d');
        if (chart && d) {
          const series = JSON.stringify(chart.w.config.series[0].data);
          const signature = series + d;
          if (signature !== previous) {
            frames.push({ time: performance.now(), series, d, ended: chart.w.globals.animationEnded });
            if (frames.length > 600) frames.shift();
            previous = signature;
          }
        }
        requestAnimationFrame(sample);
      }
      requestAnimationFrame(sample);
    });
  }
  const errors = [], denied = [], documents = [];
  await context.route('**/*', route => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() !== 'GET' || !allowed(url)) { denied.push(request.url()); return route.abort(); }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(12000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  page.on('request', request => { if (request.isNavigationRequest() && request.resourceType() === 'document') documents.push(request.url()); });
  t.after(async () => { await context.close(); scenarios.delete(id); assert.deepEqual(errors, []); assert.deepEqual(denied, []); });
  await page.goto(origin + start + (start === '/clinic' && range !== '7d' ? '?range=' + range : ''), { waitUntil: 'load' });
  if (start === '/clinic') await ready(page);
  if (start === '/guidance/dashboard') await ready(page, 'guidance');
  return { page, state, documents };
}
async function ready(page, dashboard = 'clinic') {
  const id = dashboard === 'guidance' ? guidanceChartId : chartId;
  const target = dashboard === 'guidance' ? '#guidanceActivityChart' : '#clinicActivityChart';
  await page.waitForFunction(({ id, target }) => typeof ApexCharts !== 'undefined' && !!ApexCharts.getChartByID(id)?.el?.isConnected && !!document.querySelector(target + ' .apexcharts-area'), { id, target });
}
async function expectRange(page, key, dashboard = 'clinic') {
  const expected = payload(key, dashboard);
  const id = dashboard === 'guidance' ? guidanceChartId : chartId;
  const target = dashboard === 'guidance' ? '#guidanceActivityChart' : '#clinicActivityChart';
  await page.waitForFunction(({ id, expected }) => {
    const chart = ApexCharts.getChartByID(id);
    return JSON.stringify(chart?.w.config.series[0].data) === JSON.stringify(expected.series)
      && JSON.stringify(chart?.w.globals.categoryLabels) === JSON.stringify(expected.days)
      && (!chart.w.config.chart.animations.enabled || chart.w.globals.animationEnded)
      && document.querySelector('[data-cdc-range="' + expected.rangeKey + '"]')?.classList.contains('active')
      && !document.querySelector('[data-cdc-intake-panel]')?.hasAttribute('aria-busy')
      && document.querySelector('[data-cdc-intake-total]')?.textContent === expected.total.toLocaleString('en-US')
      && document.querySelector('[data-cdc-intake-avg]')?.textContent === expected.average.toFixed(1)
      && document.querySelector('[data-cdc-intake-peak]')?.textContent === String(expected.peakValue);
  }, { id, expected }).catch(async error => {
    fs.mkdirSync(evidenceDir, { recursive: true });
    const image = path.join(evidenceDir, `${dashboard}-${key}-failed.png`);
    await page.screenshot({ path: image, fullPage: true });
    console.log('Dashboard chart screenshot: ' + image);
    console.log('Dashboard chart diagnostic: ' + JSON.stringify(await page.evaluate(id => {
      const chart = ApexCharts.getChartByID(id);
      return { series: chart?.w.config.series, categories: chart?.w.config.xaxis.categories, globals: { series: chart?.w.globals.series, labels: chart?.w.globals.labels, categoryLabels: chart?.w.globals.categoryLabels },
        buttons: [...document.querySelectorAll('[data-cdc-range]')].map(button => ({ range: button.dataset.cdcRange, active: button.classList.contains('active') })),
        total: document.querySelector('[data-cdc-intake-total]')?.textContent, subtitle: document.querySelector('[data-cdc-intake-subtitle]')?.textContent };
    }, id)));
    throw error;
  });
  assert.equal(await page.locator('[data-cdc-intake-total]').textContent(), expected.total.toLocaleString('en-US'));
  assert.equal(await page.locator('[data-cdc-intake-avg]').textContent(), expected.average.toFixed(1));
  assert.equal(await page.locator('[data-cdc-intake-peak]').textContent(), String(expected.peakValue));
  assert.equal(await page.locator('[data-cdc-intake-subtitle]').textContent(), expected.subtitle);
  assert.equal(await page.locator('[data-cdc-intake-avg-label]').textContent(), expected.avgLabel);
  assert.equal(await page.locator('[data-cdc-intake-avg-unit]').textContent(), expected.avgUnit);
  assert.equal(await page.locator('[data-cdc-intake-peak-label]').textContent(), 'on ' + expected.peakLabel);
  assert.equal(await page.locator('[data-cdc-range].active').count(), 1);
  assert.equal(await page.locator(target + ' .apexcharts-canvas').count(), 1);
  return page.locator(target + ' .apexcharts-area').first().getAttribute('d');
}
async function switchRange(page, key, dashboard = 'clinic') {
  await page.locator('[data-cdc-range="' + key + '"]').click();
  return expectRange(page, key, dashboard);
}
async function screenshot(page, name) {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const image = path.join(evidenceDir, name + '.png');
  await page.screenshot({ path: image });
  console.log('Dashboard chart screenshot: ' + image);
}

// Deterministic queue checks use the real helper with a controlled renderer,
// separate from the GUI tests using real ApexCharts below.
function chartQueueFixture() {
  const updates = [], charts = [], frames = [], listeners = new Map();
  function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
  }
  const target = { isConnected: true, clientWidth: 640, dataset: {}, getClientRects: () => [{}] };
  const style = { paddingLeft: '0', paddingRight: '0', backgroundColor: '#ffffff', color: '#111111', getPropertyValue: () => '' };
  const document = {
    body: { classList: { contains: () => false } },
    documentElement: { getAttribute: () => 'light' },
    addEventListener: (name, handler) => listeners.set(name, handler),
  };
  const window = {
    addEventListener: (name, handler) => listeners.set(name, handler),
    requestAnimationFrame: handler => { frames.push(handler); return frames.length; },
    ApexCharts: class {
      constructor(target, options) { this.target = target; this.options = options; this.rendered = deferred(); charts.push(this); }
      render() { return this.rendered.promise; }
      updateOptions(options, redrawPaths, animate, updateSyncedCharts) {
        const done = deferred();
        updates.push({ series: Array.from(options.series[0].data), width: options.chart.width, redrawPaths, animate, updateSyncedCharts, done });
        return done.promise;
      }
      destroy() { this.destroyed = true; }
    },
  };
  const context = vm.createContext({ window, document, getComputedStyle: () => style, MutationObserver: class { observe() {} }, console });
  vm.runInContext(fs.readFileSync(path.join(publicRoot, 'assets/js/chart-theme.js'), 'utf8'), context);
  return { theme: window.ChartTheme, target, updates, charts,
    resize(width) { target.clientWidth = width; listeners.get('resize')(); while (frames.length) frames.shift()(); },
    themeRefresh() { listeners.get('synapse:theme-change')(); },
    destroy() { listeners.get('synapse:before-swap')({ detail: { root: target } }); },
  };
}
async function drainQueue() { await new Promise(resolve => setImmediate(resolve)); }

test('chart queue retains animation intent and latest data through render/update/theme/resize overlap', async () => {
  const h = chartQueueFixture();
  let series = [1];
  h.theme.create(h.target, () => ({ chart: {}, series: [{ data: series }] }));
  series = [2]; h.theme.refresh(h.target, true);
  series = [3]; h.theme.refresh(h.target, true);
  h.themeRefresh();
  h.resize(800);
  assert.equal(h.updates.length, 0, 'Do not update while the initial render is pending');
  h.charts[0].rendered.resolve();
  await drainQueue();
  assert.equal(h.updates.length, 1);
  assert.deepEqual(h.updates[0].series, [3]);
  assert.equal(h.updates[0].width, 800);
  assert.deepEqual([h.updates[0].redrawPaths, h.updates[0].animate, h.updates[0].updateSyncedCharts], [true, true, false]);

  series = [4]; h.theme.refresh(h.target, true);
  series = [5]; h.theme.refresh(h.target, true);
  h.themeRefresh();
  h.resize(720);
  assert.equal(h.updates.length, 1, 'Coalesce pending updates instead of racing ApexCharts');
  h.updates[0].done.resolve();
  await drainQueue();
  assert.equal(h.updates.length, 2);
  assert.deepEqual(h.updates[1].series, [5]);
  assert.equal(h.updates[1].width, 720);
  assert.equal(h.updates[1].animate, true);

  h.themeRefresh();
  h.updates[1].done.resolve();
  await drainQueue();
  assert.equal(h.updates.length, 3);
  assert.deepEqual([h.updates[2].redrawPaths, h.updates[2].animate, h.updates[2].updateSyncedCharts], [false, false, false]);
  h.updates[2].done.resolve();
  await drainQueue();
  h.resize(700);
  assert.equal(h.updates[3].animate, false, 'Standalone resize stays nonanimated');
  h.updates[3].done.resolve();
  await drainQueue();
  h.theme.refresh(h.target, false);
  assert.equal(h.updates[4].animate, false, 'Reduced-motion opt-out stays nonanimated');
  h.updates[4].done.resolve();
  await drainQueue();
  assert.equal(h.charts.length, 1);
});

test('chart queue drops an animated pending update when its page target is destroyed', async () => {
  const h = chartQueueFixture();
  h.theme.create(h.target, () => ({ chart: {}, series: [{ data: [1] }] }));
  h.theme.refresh(h.target, true);
  h.destroy();
  h.charts[0].rendered.resolve();
  await drainQueue();
  assert.equal(h.charts[0].destroyed, true);
  assert.equal(h.updates.length, 0);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: shared badges, identifiers and code use plain-zero monospace across the UI`, { timeout: 60000 }, async t => {
    const { page } = await open(t, { start: '/typography', theme });
    const values = await page.locator('[data-font-sample]').evaluateAll(nodes => nodes.map(node => ({ text: node.textContent, family: getComputedStyle(node).fontFamily })));
    assert.equal(values.length, 9);
    for (const value of values) {
      assert.equal(value.text, '0 10 100 1000SYN-001020');
      assert.ok(value.family.startsWith('"Courier New"'), value.family);
      assert.equal(/JetBrains|Consolas|SFMono|Menlo|Monaco|ui-monospace/.test(value.family), false);
    }
    const widths = await page.locator('.ce-workspace [data-font-sample]').evaluate(node => {
      const range = document.createRange();
      const text = node.firstChild;
      const at = [0, 2];
      return at.map(index => { range.setStart(text, index); range.setEnd(text, index + 1); return range.getBoundingClientRect().width; });
    });
    assert.ok(Math.abs(widths[0] - widths[1]) < 0.1, 'Keep fixed-width zero and one alignment');
    await screenshot(page, `shared-plain-zero-${theme}`);
  });
}

for (const theme of ['light', 'dark']) {
  test(`${theme}: dashboard metrics and chart numbers use plain-zero sans fonts without changing metadata`, { timeout: 60000 }, async t => {
    const { page } = await open(t, { start: '/guidance/dashboard', theme });
    for (const dashboard of ['guidance', 'clinic']) {
      if (dashboard === 'clinic') {
        await page.getByRole('link', { name: 'Clinic Dashboard', exact: true }).click();
        await page.waitForURL(origin + '/clinic');
        await ready(page);
      }
      await expectRange(page, '7d', dashboard);
      const fonts = await page.evaluate(dashboard => {
        const family = getComputedStyle(document.body).getPropertyValue('--default-font').trim();
        const metricNodes = document.querySelectorAll('.cdc-deck-num:not(.cdc-deck-num--text), .cdc-period-stat-val strong, .cdc-acuity-pct, .cdc-acuity-count');
        const chartId = dashboard === 'clinic' ? 'clinicActivityIntake' : 'guidanceActivityTrajectory';
        const chart = ApexCharts.getChartByID(chartId);
        const donut = document.querySelector((dashboard === 'clinic' ? '#clinicTriageChart' : '#guidanceCategoryChart') + ' .apexcharts-datalabel-value');
        return { family,
          metrics: Array.from(metricNodes, node => ({ family: getComputedStyle(node).fontFamily, variant: getComputedStyle(node).fontVariantNumeric })),
          chartFonts: [chart.w.config.xaxis.labels.style.fontFamily, chart.w.config.yaxis[0].labels.style.fontFamily],
          donutFamily: getComputedStyle(donut).fontFamily,
          metadataFamily: getComputedStyle(document.querySelector('.cdc-shift-meta.cdc-mono')).fontFamily };
      }, dashboard);
      assert.ok(fonts.metrics.length >= 7);
      for (const metric of fonts.metrics) {
        assert.equal(metric.family, fonts.family);
        assert.ok(metric.variant.includes('tabular-nums'));
        assert.ok(metric.variant.includes('lining-nums'));
      }
      assert.deepEqual(fonts.chartFonts, [fonts.family, fonts.family]);
      assert.equal(fonts.donutFamily, fonts.family);
      assert.ok(fonts.metadataFamily.startsWith('"Courier New"'), 'Identifiers use the shared plain-zero monospace font');
      await screenshot(page, `numeric-font-${dashboard}-${theme}`);
    }
  });
}

for (const theme of ['light', 'dark']) {
  test(`${theme}: Guidance to Clinic round trips keep ranges owned by the current dashboard`, { timeout: 60000 }, async t => {
    const { page, state, documents } = await open(t, { start: '/guidance/dashboard', theme });
    const header = await page.locator('#header').elementHandle();
    await expectRange(page, '7d', 'guidance');
    await screenshot(page, `guidance-${theme}-initial-7d`);
    for (const key of ['30d', 'sy', '7d']) {
      await switchRange(page, key, 'guidance');
      await screenshot(page, `guidance-${theme}-initial-${key}`);
    }
    for (let visit = 1; visit <= 2; visit++) {
      await page.getByRole('link', { name: 'Clinic Dashboard', exact: true }).click();
      await page.waitForURL(origin + '/clinic');
      await ready(page);
      assert.equal(await page.locator('#guidanceActivityChart').count(), 0);
      const initialLine = await expectRange(page, '7d');
      await screenshot(page, `clinic-${theme}-visit-${visit}-7d`);
      const monthLine = await switchRange(page, '30d');
      await screenshot(page, `clinic-${theme}-visit-${visit}-30d`);
      assert.notEqual(initialLine, monthLine, 'The actual Clinic line must change after Guidance navigation');
      const schoolYearLine = await switchRange(page, 'sy');
      await screenshot(page, `clinic-${theme}-visit-${visit}-sy`);
      assert.notEqual(monthLine, schoolYearLine, 'School Year must render a different Clinic line');
      await switchRange(page, '7d');
      await screenshot(page, `clinic-${theme}-visit-${visit}-return-7d`);
      if (visit === 1) {
        await page.getByRole('link', { name: 'Guidance Dashboard', exact: true }).click();
        await page.waitForURL(origin + '/guidance/dashboard');
        await ready(page, 'guidance');
        assert.equal(await page.locator('#clinicActivityChart').count(), 0);
        await expectRange(page, '7d', 'guidance');
        await screenshot(page, `guidance-${theme}-reentry-7d`);
        for (const key of ['sy', '30d', '7d']) {
          await switchRange(page, key, 'guidance');
          await screenshot(page, `guidance-${theme}-reentry-${key}`);
        }
      }
    }
    assert.equal(await header.evaluate(header => header === document.querySelector('#header')), true);
    assert.equal(documents.length, 1, 'Cross-dashboard navigation and range changes must retain the document');
    assert.deepEqual(state.requests.filter(req => req.path === '/clinic/dashboard/intake-series').map(req => req.range), ['30d', 'sy', '30d', 'sy']);
    assert.deepEqual(state.requests.filter(req => req.path === '/guidance/dashboard/session-series').map(req => req.range), ['30d', 'sy', 'sy', '30d']);
  });
}

for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
  test(`${width}px ${theme}: all ranges update the real line and summary without navigation or reload`, { timeout: 60000 }, async t => {
    const { page, state, documents } = await open(t, { width, theme });
    const main = await page.locator('#main').elementHandle();
    const paths = [await expectRange(page, '7d')];
    for (const key of ['30d', 'sy', '7d']) paths.push(await switchRange(page, key));
    assert.notEqual(paths[0], paths[1], 'The actual rendered line must change for 30 days');
    assert.notEqual(paths[1], paths[2], 'The actual rendered line must change for School Year');
    assert.equal(await main.evaluate(main => main === document.querySelector('#main')), true);
    assert.equal(documents.length, 1, 'Timeframe changes must not reload or replace the document');
    assert.deepEqual(state.requests.filter(req => req.path.endsWith('intake-series')).map(req => req.range), ['30d', 'sy']);
  });
}

test('initial page-load and repeated lifecycle signals keep one chart state owner', { timeout: 60000 }, async t => {
  const { page, state } = await open(t);
  await switchRange(page, '30d');
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document.querySelector('#main') } }));
    document.documentElement.setAttribute('data-theme', 'dark');
    ChartTheme.refresh();
  });
  await expectRange(page, '30d');
  await switchRange(page, 'sy');
  await page.evaluate(() => ChartTheme.refresh());
  await expectRange(page, 'sy');
  assert.deepEqual(state.requests.filter(req => req.path.endsWith('intake-series')).map(req => req.range), ['30d', 'sy']);
});

test('progressive entry, leave/re-entry and browser history rebind the current chart once', { timeout: 60000 }, async t => {
  const { page, documents } = await open(t, { start: '/chart-neighbor' });
  const header = await page.locator('#header').elementHandle();
  for (const key of ['30d', 'sy']) {
    await page.locator('.sidebar-nav a[href$="/clinic"]').click();
    await page.waitForURL(origin + '/clinic');
    await ready(page);
    await expectRange(page, '7d');
    await switchRange(page, key);
    await page.locator('.sidebar-nav a[href$="/chart-neighbor"]').click();
    await page.waitForURL(origin + '/chart-neighbor');
    assert.equal(await page.locator('.apexcharts-canvas').count(), 0);
  }
  await page.goBack();
  await page.waitForURL(origin + '/clinic?range=sy');
  await ready(page);
  await expectRange(page, 'sy');
  await switchRange(page, '30d');
  assert.equal(await header.evaluate(header => header === document.querySelector('#header')), true);
  assert.equal(documents.length, 1);
});

for (const reducedMotion of ['no-preference', 'reduce']) {
  test(`${reducedMotion}: range changes visibly animate only when motion is allowed`, { timeout: 60000 }, async t => {
    const { page, documents } = await open(t, { start: '/guidance/dashboard', reducedMotion, recordAnimation: true });
    await expectRange(page, '7d', 'guidance');
    await page.getByRole('link', { name: 'Clinic Dashboard', exact: true }).click();
    await page.waitForURL(origin + '/clinic');
    await ready(page);
    await expectRange(page, '7d');
    await screenshot(page, `animation-${reducedMotion}-7d`);
    for (const key of ['30d', 'sy', '7d']) {
      const started = await page.evaluate(() => performance.now());
      await page.getByRole('link', { name: key === '30d' ? '30 Days' : (key === 'sy' ? 'School Year' : '7 Days'), exact: true }).click();
      if (reducedMotion === 'no-preference') {
        await page.waitForFunction(({ id, series }) => {
          const chart = ApexCharts.getChartByID(id);
          return JSON.stringify(chart?.w.config.series[0].data) === series && chart.w.globals.animationEnded === false;
        }, { id: chartId, series: JSON.stringify(counts[key].series) });
        await screenshot(page, `animation-${reducedMotion}-${key}-transition`);
      }
      await expectRange(page, key);
      await screenshot(page, `animation-${reducedMotion}-${key}-settled`);
      const observed = await page.evaluate(({ id, series, started }) => {
        const chart = ApexCharts.getChartByID(id);
        return { enabled: chart.w.config.chart.animations.enabled,
          frames: window.fixtureChartFrames.filter(frame => frame.time >= started && frame.series === series) };
      }, { id: chartId, series: JSON.stringify(counts[key].series), started });
      assert.equal(observed.enabled, reducedMotion === 'no-preference');
      if (reducedMotion === 'no-preference') {
        assert.ok(observed.frames.some(frame => frame.ended === false), 'Observe a real in-flight animation');
        assert.ok(new Set(observed.frames.map(frame => frame.d)).size > 1, 'The actual SVG line must interpolate, not just switch configuration');
      } else {
        assert.ok(observed.frames.every(frame => frame.ended !== false), 'Reduced motion must suppress line interpolation');
      }
    }
    assert.equal(documents.length, 1, 'Animated range changes must not reload the document');
  });
}

test('animated ranges survive overlapping render, resize and theme refresh', { timeout: 60000 }, async t => {
  const { page, documents } = await open(t, { reducedMotion: 'no-preference', delays: { '30d': 50, sy: 50 } });
  await page.locator('[data-cdc-range="30d"]').click();
  await page.setViewportSize({ width: 1280, height: 1000 });
  await expectRange(page, '30d');
  await page.locator('[data-cdc-range="sy"]').click();
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'dark');
    ChartTheme.refresh();
  });
  await expectRange(page, 'sy');
  await page.setViewportSize({ width: 1440, height: 1000 });
  await switchRange(page, '7d');
  assert.equal(documents.length, 1);
});

// A late script can run after the initial page-load signal; a later signal must
// never let its old PHP seed data own a newly inserted dashboard chart.
test('late initialization never reuses an earlier dashboard state on same-shell re-entry', { timeout: 60000 }, async t => {
  const { page, documents } = await open(t, { range: '30d' });
  await page.evaluate(() => {
    const main = document.querySelector('#main');
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: main } }));
    const script = document.querySelector('#page-scripts script:not([src])');
    const lateScript = document.createElement('script');
    lateScript.textContent = script.textContent;
    script.replaceWith(lateScript);
  });
  await ready(page);
  assert.equal(await page.evaluate(() => SynapseNavigation.navigate('/clinic', { history: 'replace' })), true);
  await page.waitForURL(origin + '/clinic');
  await ready(page);
  await expectRange(page, '7d');
  await switchRange(page, '30d');
  assert.equal(documents.length, 1);
});

test('failed range keeps the old line, retries once and rapid switches apply only the latest choice', { timeout: 60000 }, async t => {
  const { page, state } = await open(t, { statuses: { '30d': [503, 200] }, delays: { '30d': 150 } });
  const oldLine = await expectRange(page, '7d');
  await page.locator('[data-cdc-range="30d"]').click();
  await page.locator('[data-cdc-intake-error]').waitFor({ state: 'visible' });
  assert.equal(await expectRange(page, '7d'), oldLine);
  await switchRange(page, '30d');
  await switchRange(page, '7d');
  const before = state.requests.length;
  await page.evaluate(() => {
    document.querySelector('[data-cdc-range="sy"]').click();
    document.querySelector('[data-cdc-range="30d"]').click();
  });
  await expectRange(page, '30d');
  assert.equal(await page.locator('[data-cdc-intake-error]').isVisible(), false);
  assert.ok(state.requests.length >= before);
});
