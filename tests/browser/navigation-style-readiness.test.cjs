'use strict';

// Isolated, read-only full-shell navigation. No PHP, app boot, database, environment
// file, remote asset, dependency installation, or existing test is involved.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const screenshotDir = path.join(root, 'writable/style-readiness-preview');
const scenarios = new Map();
const blocked = [];
const rejectedBrowserServices = new Set();
const routes = {
  a: ['Original page', '#7f1d1d', '#fff7ed', 'admin'],
  external: ['External stylesheet and script', '#0f766e', '#ecfdf5', 'profile'],
  nested: ['Nested imports', '#1d4ed8', '#eff6ff', 'profile'],
  inline: ['Inline import', '#6d28d9', '#f5f3ff', 'profile'],
  legacy: ['Legacy style owner', '#166534', '#f0fdf4', 'profile'],
  'stylesheet-fault': ['Failed stylesheet', '#0f766e', '#ecfdf5', 'profile'],
  'import-fault': ['Failed imported stylesheet', '#1d4ed8', '#eff6ff', 'profile'],
  'script-fault': ['Failed page script', '#6d28d9', '#f5f3ff', 'profile'],
  slow: ['Superseded or timed-out page', '#1d4ed8', '#eff6ff', 'profile'],
  next: ['Next page', '#166534', '#f0fdf4', 'admin'],
  'history-b': ['History destination', '#0f766e', '#ecfdf5', 'profile'],
};
let browser, server, origin;

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

async function deadline(promise, label, milliseconds = 5000) {
  let timer;
  try {
    return await Promise.race([promise, new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(label)), milliseconds);
    })]);
  } finally { clearTimeout(timer); }
}

function realCss(scenario, name) {
  const filename = routes[name][3] === 'admin' ? 'admin-workspace.css' : 'profile-settings.css';
  return `/assets/css/pages/${filename}?fixture=${scenario.id}&page=${name}`;
}
function fixtureCss(scenario, filename) {
  return `/assets/css/style-readiness/${scenario.id}/${filename}.css`;
}
function scriptUrl(scenario, name) {
  return `/assets/js/style-readiness/${scenario.id}/${name}.js`;
}
function pageUrl(scenario, name) {
  return `/style-readiness/${scenario.id}/${name}`;
}
function canaryCss(name, revision) {
  return `.readiness-canary { color: ${routes[name][1]}; background-color: ${routes[name][2]};
    border-color: ${routes[name][1]}; --readiness-page: ${name}; --readiness-revision: ${revision}; }`;
}

function styleMarkup(scenario, name, revision) {
  const attributes = `data-fixture-page-style="${name}"`;
  if (name === 'inline') {
    return `<style ${attributes} media="screen">@import url("${fixtureCss(scenario, 'inline-root')}") screen;
      ${canaryCss(name, revision)}</style>`;
  }
  const href = name === 'nested' ? fixtureCss(scenario, 'nested-root')
    : name === 'import-fault' ? fixtureCss(scenario, 'fault-root') : realCss(scenario, name);
  return `<link rel="stylesheet" media="screen" href="${href}" ${attributes}>
    <style ${attributes}>${canaryCss(name, revision)}</style>`;
}

function render(scenario, name, revision) {
  const styles = styleMarkup(scenario, name, revision);
  const headOwner = scenario.owner !== 'legacy';
  const nav = Object.entries(routes).map(([key, [label]]) =>
    `<a class="nav-link${key === name ? ' active' : ''}" href="${pageUrl(scenario, key)}"${key === name ? ' aria-current="page"' : ''}>${label}</a>`).join('\n');
  return `<!doctype html><html data-theme="light"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${routes[name][0]} · response ${revision}</title>
    <style>
      :root { font-size: 16px; --header-height: 64px; --fu-maroon: #800000; }
      * { box-sizing: border-box; }
      body { margin: 0; background: #f3f4f6; color: #111827; font-family: Arial, sans-serif; }
      #header { position: fixed; inset: 0 0 auto; height: 64px; z-index: 4; display: flex;
        align-items: center; padding: 0 24px; background: #fff; border-bottom: 1px solid #ddd; }
      #sidebar { position: fixed; inset: 64px auto 0 0; width: 240px; overflow: auto;
        padding: 16px; background: #fff; border-right: 1px solid #ddd; }
      .sidebar-nav { display: grid; gap: 8px; }
      .nav-link { display: block; color: #374151; padding: 9px 12px; text-decoration: none; border-radius: 6px; }
      .nav-link.active { background: #fee2e2; color: #7f1d1d; font-weight: bold; }
      #main { margin-left: 240px; padding: 88px 28px 28px; min-height: 1650px; }
      h1 { font-size: 25px; margin: 0 0 18px; }
      .readiness-canary { border: 5px solid #9ca3af; border-radius: 16px; padding: 22px;
        background: #fff; color: #111827; margin-bottom: 20px; }
      .paint-label { display: block; font-size: 24px; font-weight: bold; margin-bottom: 12px; }
      .fixture-controls { margin-top: 20px; display: grid; gap: 14px; }
      label { display: grid; gap: 7px; max-width: 440px; }
      input { height: 40px; padding: 8px; border: 1px solid #9ca3af; border-radius: 5px; }
      #fixture-anchor { margin-top: 900px; padding: 30px; border: 2px solid #64748b; background: #fff; }
      .visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
      .navigation-feedback { position: fixed; left: 265px; bottom: 20px; background: #fff; z-index: 8; }
      #navigation-retry, #navigation-fallback { margin: 8px; padding: 9px; }
      [hidden] { display: none !important; }
    </style>
    <link rel="stylesheet" href="/assets/css/navigation.css">
    ${headOwner ? `<template id="page-styles" data-page-style-owner="head"></template>${styles}<template data-page-styles-end></template>` : ''}
    </head><body data-navigation-scope="style-readiness-${scenario.id}" hx-boost="false">
    ${headOwner ? '' : `<div id="page-styles" hidden>${styles}</div>`}
    <header id="header">Persistent synthetic shell · stylesheet readiness</header>
    <aside id="sidebar"><nav class="sidebar-nav" aria-label="Fixture pages">${nav}</nav></aside>
    <main id="main" data-fixture-page="${name}" data-fixture-revision="${revision}">
      <div class="main-content"><section class="section">
        <h1>${routes[name][0]} · response ${revision}</h1>
        <div id="paint-probe" class="readiness-canary" data-paint-probe>
          <span class="paint-label">${routes[name][0]}</span>
          <span>Both this color and the real page sheet must remain correct on every frame.</span>
        </div>
        <div class="adm-workspace ps-shell"><div class="adm-sheet ps-sheet" data-real-sheet>
          <div class="adm-band ps-band" data-real-band>
            Real same-origin ${routes[name][3] === 'admin' ? 'administration' : 'profile'} stylesheet fixture
          </div>
        </div></div>
        <div class="fixture-controls"><output data-script-status>Waiting for the page script</output>
          <form method="post"><label>Note<input name="note" aria-label="Note" autocomplete="off"></label></form>
          <a href="#fixture-anchor">Jump to page anchor</a>
        </div>
        <div id="fixture-anchor">Page anchor · ${routes[name][0]}</div>
      </section></div>
    </main>
    <div class="navigation-feedback"><div id="navigation-status" class="visually-hidden"></div>
      <button id="navigation-retry" type="button" hidden>Retry navigation</button>
      <a id="navigation-fallback" data-navigation="false" hidden>Open the full page</a></div>
    <script src="/assets/js/page-styles.js"></script><script src="/assets/js/navigation.js"></script>
    <div id="page-scripts"><script data-fixture-script="${name}" src="${scriptUrl(scenario, name)}"></script></div>
    </body></html>`;
}

function gate(scenario, key, options = {}) {
  const entered = deferred(), release = deferred();
  const value = {
    key, after: options.after || 0, entered: entered.promise, released: false,
    requests: [], waiting: release.promise,
    release(result = null) {
      if (value.released) return;
      value.released = true;
      release.resolve(result);
    },
    note(request) { value.requests.push(request); entered.resolve(request); },
  };
  scenario.gates.push(value);
  return value;
}

async function sendAsset(req, res, url, scenario, content, type) {
  const key = url.pathname + url.search;
  const occurrence = scenario ? (scenario.assetCounts.get(key) || 0) + 1 : 1;
  if (scenario) scenario.assetCounts.set(key, occurrence);
  const record = { path: key, occurrence, destination: req.headers['sec-fetch-dest'], finished: false };
  if (scenario) scenario.assets.push(record);
  res.on('finish', () => { record.finished = true; });
  res.on('close', () => { record.closed = true; });
  const pending = scenario?.gates.find(item => item.key === key && !item.released && occurrence > item.after);
  let result;
  if (pending) { pending.note(record); result = await pending.waiting; }
  if (res.destroyed) return;
  const status = result?.status || scenario?.failures.get(key)?.status || 200;
  res.writeHead(status, {
    'Content-Type': type,
    'Cache-Control': status !== 200 || scenario?.cache === 'cold' ? 'no-store' : 'public, max-age=3600',
    'X-Content-Type-Options': 'nosniff',
  });
  res.end(result?.body ?? scenario?.failures.get(key)?.body ?? content);
}

before(async () => {
  // Refuse missing production prerequisites rather than silently running against a stub.
  for (const filename of ['public/assets/js/navigation.js', 'public/assets/js/page-styles.js',
    'public/assets/css/pages/admin-workspace.css', 'public/assets/css/pages/profile-settings.css']) {
    assert.ok(fs.existsSync(path.join(root, filename)), `Required production asset: ${filename}`);
  }
  fs.mkdirSync(screenshotDir, { recursive: true });
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin);
      if (url.origin !== origin) {
        rejectedBrowserServices.add(url.origin);
        return res.writeHead(403).end('External browser services are never forwarded');
      }
      if (req.method !== 'GET') {
        blocked.push(`${req.method} ${url.href}`);
        return res.writeHead(405).end('Loopback GET fixtures only');
      }
      if (url.pathname === '/favicon.ico') return res.writeHead(204).end();
      const pageMatch = /^\/style-readiness\/([^/]+)\/([^/]+)$/.exec(url.pathname);
      if (pageMatch && scenarios.has(pageMatch[1]) && routes[pageMatch[2]]) {
        const scenario = scenarios.get(pageMatch[1]), name = pageMatch[2];
        const revision = (scenario.revisions.get(name) || 0) + 1;
        scenario.revisions.set(name, revision);
        scenario.documents.push({ name, revision, headers: req.headers, path: url.pathname + url.search });
        return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' })
          .end(render(scenario, name, revision));
      }
      const cssMatch = /^\/assets\/css\/style-readiness\/([^/]+)\/([^/]+)\.css$/.exec(url.pathname);
      if (cssMatch && scenarios.has(cssMatch[1])) {
        const scenario = scenarios.get(cssMatch[1]);
        const css = {
          'nested-root': '@import url("./nested-middle.css") screen; .readiness-canary { --import-root: ready; }',
          'nested-middle': '@import "./nested-leaf.css"; .readiness-canary { --import-middle: ready; }',
          'nested-leaf': `@import url("${realCss(scenario, 'nested')}"); .readiness-canary { --import-leaf: ready; }`,
          'inline-root': `@import url("${realCss(scenario, 'inline')}"); .readiness-canary { --inline-import: ready; }`,
          'fault-root': '@import url("./fault-leaf.css"); .readiness-canary { --failed-import-root: loaded; }',
          'fault-leaf': `@import url("${realCss(scenario, 'import-fault')}"); .readiness-canary { --failed-import-leaf: loaded; }`,
        }[cssMatch[2]];
        if (css !== undefined) return await sendAsset(req, res, url, scenario, css, 'text/css; charset=utf-8');
      }
      const jsMatch = /^\/assets\/js\/style-readiness\/([^/]+)\/([^/]+)\.js$/.exec(url.pathname);
      if (jsMatch && scenarios.has(jsMatch[1]) && routes[jsMatch[2]]) {
        const scenario = scenarios.get(jsMatch[1]), name = jsMatch[2];
        // A real uncached external script registers a page-load initializer. The
        // test does not pre-register it or inject its result into the page.
        const js = `(function () {
          const name = ${JSON.stringify(name)};
          document.dispatchEvent(new CustomEvent('style-readiness:script-executed', { detail: { name } }));
          document.addEventListener('synapse:page-load', function (event) {
            if (event.detail.root.dataset.fixturePage !== name) return;
            event.detail.root.querySelector('[data-script-status]').textContent = 'Script ready: ' + name;
          });
        })();`;
        return await sendAsset(req, res, url, scenario, js, 'text/javascript; charset=utf-8');
      }
      const staticFiles = {
        '/assets/js/page-styles.js': 'public/assets/js/page-styles.js',
        '/assets/js/navigation.js': 'public/assets/js/navigation.js',
        '/assets/css/navigation.css': 'public/assets/css/navigation.css',
        '/assets/css/pages/admin-workspace.css': 'public/assets/css/pages/admin-workspace.css',
        '/assets/css/pages/profile-settings.css': 'public/assets/css/pages/profile-settings.css',
      };
      if (staticFiles[url.pathname]) {
        const scenario = scenarios.get(url.searchParams.get('fixture'));
        const type = url.pathname.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8';
        return await sendAsset(req, res, url, scenario, fs.readFileSync(path.join(root, staticFiles[url.pathname])), type);
      }
      res.writeHead(404).end('Not a fixture route');
    } catch (error) { if (!res.destroyed) res.writeHead(500).end(String(error.stack)); }
  });
  // Any non-loopback HTTPS attempt is rejected here, never tunneled upstream.
  server.on('connect', (req, socket) => {
    rejectedBrowserServices.add(req.url);
    socket.on('error', () => {}); // A browser rejecting this closed tunnel may reset its socket.
    socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
  });
  server.on('clientError', (_, socket) => { socket.destroy(); });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const installed = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(installed).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(installed, name, 'chrome-win64/chrome.exe')).find(filename => fs.existsSync(filename));
  }
  assert.ok(executablePath, 'An already installed Playwright Chromium is required; nothing is installed by this test.');
  // A proxy enforces loopback-only traffic without Playwright routing, which
  // would disable the HTTP cache and invalidate the cached Back/Forward test.
  browser = await chromium.launch({ executablePath, headless: true, timeout: 20000,
    args: ['--disable-background-networking', '--disable-component-update', '--disable-domain-reliability',
      '--disable-features=AutofillServerCommunication,MediaRouter,OptimizationHints', '--disable-sync',
      '--no-first-run', '--safebrowsing-disable-auto-update'],
    proxy: { server: origin, bypass: '127.0.0.1,localhost' } });
}, { timeout: 30000 });

after(async () => {
  for (const scenario of scenarios.values()) for (const item of scenario.gates) item.release();
  try { await browser?.close(); }
  finally {
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
  assert.deepEqual(blocked, [], 'No external traffic, app endpoints, or write requests');
  if (rejectedBrowserServices.size) console.log('Browser background services rejected locally (never forwarded): ' + [...rejectedBrowserServices].join(', '));
}, { timeout: 15000 });

// Installed before formal testing, this observer only reads DOM/computed paint
// and lifecycle events. It never changes application state, styles, or history.
function installObserver() {
  const observation = { loads: [], loadFrames: [], frames: [], swaps: [], cssEvents: [], scripts: [], active: false };
  Object.defineProperty(window, '__styleReadinessObservation', { value: observation });
  const ownedNodes = () => {
    const owner = document.getElementById('page-styles');
    if (!owner) return [];
    if (window.SynapsePageStyles?.nodes) return window.SynapsePageStyles.nodes(owner);
    if (owner.tagName !== 'TEMPLATE') return [...owner.querySelectorAll('style, link[rel~="stylesheet"]')];
    const nodes = [];
    for (let node = owner.nextElementSibling; node && !node.hasAttribute('data-page-styles-end'); node = node.nextElementSibling) {
      if (node.matches('style, link[rel~="stylesheet"]')) nodes.push(node);
    }
    return nodes;
  };
  const read = () => {
    const main = document.getElementById('main'), probe = main?.querySelector('[data-paint-probe]');
    const real = main?.querySelector('[data-real-band]'), sheet = main?.querySelector('[data-real-sheet]');
    const paint = probe && getComputedStyle(probe), realPaint = real && getComputedStyle(real);
    const currentShell = [document.body, document.head, document.getElementById('header'),
      document.getElementById('sidebar'), document.querySelector('.sidebar-nav'), document.getElementById('page-styles')];
    return {
      page: main?.dataset.fixturePage || null, revision: Number(main?.dataset.fixtureRevision || 0),
      color: paint?.color || null, background: paint?.backgroundColor || null,
      marker: paint?.getPropertyValue('--readiness-page').trim() || '',
      cssRevision: Number(paint?.getPropertyValue('--readiness-revision').trim() || 0),
      importRoot: paint?.getPropertyValue('--import-root').trim() || '',
      importMiddle: paint?.getPropertyValue('--import-middle').trim() || '',
      importLeaf: paint?.getPropertyValue('--import-leaf').trim() || '',
      inlineImport: paint?.getPropertyValue('--inline-import').trim() || '',
      real: { admin: realPaint?.getPropertyValue('--adm-border').trim() || '',
        profile: realPaint?.getPropertyValue('--ps-border').trim() || '',
        paddingTop: realPaint?.paddingTop || '', radius: sheet ? getComputedStyle(sheet).borderRadius : '' },
      shellStable: Boolean(observation.shell?.every((node, index) => node === currentShell[index])),
      initialMain: main === observation.initialMain,
      oldCssConnected: observation.oldCss?.map(node => node.isConnected) || [],
      owned: ownedNodes().map(node => ({ tag: node.tagName, media: node.media, page: node.dataset.fixturePageStyle })),
      staged: [...document.querySelectorAll('style, link[rel~="stylesheet"]')]
        .filter(node => node.media.replace(/\s+/g, ' ').trim().toLowerCase() === 'not all')
        .map(node => ({ tag: node.tagName, page: node.dataset.fixturePageStyle || '', href: node.href || '' })),
      busy: document.body?.classList.contains('is-navigating') || false,
      url: location.href,
    };
  };
  observation.read = read;
  const keep = (collection, value) => { collection.push(value); if (collection.length > 900) collection.shift(); };
  document.addEventListener('style-readiness:script-executed', event => {
    observation.scripts.push({ name: event.detail.name, paint: read() });
  });
  const dependencies = sheet => {
    const urls = new Set(), seen = new Set();
    const visit = current => {
      if (!current || seen.has(current)) return;
      seen.add(current);
      let rules;
      try { rules = [...current.cssRules]; } catch (_) { return; }
      for (const rule of rules) if (rule.type === CSSRule.IMPORT_RULE) {
        urls.add(rule.href);
        visit(rule.styleSheet);
      }
    };
    visit(sheet);
    return [...urls];
  };
  for (const type of ['load', 'error']) document.addEventListener(type, event => {
    if (event.target?.matches?.('style, link[rel~="stylesheet"]')) {
      observation.cssEvents.push({ type, href: event.target.href || '', media: event.target.media,
        dependencies: dependencies(event.target.sheet), page: event.target.dataset.fixturePageStyle || '', paint: read() });
    }
  }, true);
  document.addEventListener('synapse:before-navigate', () => { observation.active = true; });
  document.addEventListener('synapse:before-swap', () => { observation.swaps.push(read()); });
  document.addEventListener('synapse:page-load', event => {
    const record = { initial: Boolean(event.detail.initial), popstate: Boolean(event.detail.popstate), paint: read() };
    observation.loads.push(record);
    requestAnimationFrame(() => {
      observation.loadFrames.push({ initial: record.initial, popstate: record.popstate, phase: 'first-frame', paint: read() });
      requestAnimationFrame(() => {
        observation.loadFrames.push({ initial: record.initial, popstate: record.popstate, phase: 'painted-frame', paint: read() });
      });
    });
  });
  document.addEventListener('DOMContentLoaded', () => {
    observation.shell = [document.body, document.head, document.getElementById('header'),
      document.getElementById('sidebar'), document.querySelector('.sidebar-nav'), document.getElementById('page-styles')];
    observation.initialMain = document.getElementById('main');
    observation.oldCss = ownedNodes();
    new MutationObserver(() => { if (observation.active) keep(observation.frames, read()); })
      .observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['media', 'disabled'] });
    const frame = () => { if (observation.active) keep(observation.frames, read()); requestAnimationFrame(frame); };
    requestAnimationFrame(frame);
  }, { once: true });
}

async function pageFor(t, id, options = {}) {
  const scenario = { id, owner: options.owner || 'head', cache: options.cache || 'cached',
    gates: [], failures: new Map(), assetCounts: new Map(), assets: [], revisions: new Map(), documents: [] };
  scenarios.set(id, scenario);
  if (options.prepare) options.prepare(scenario);
  const context = await browser.newContext({ viewport: { width: 1200, height: 800 },
    colorScheme: 'light', reducedMotion: 'reduce', serviceWorkers: 'block' });
  await context.addInitScript(installObserver);
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  page.setDefaultNavigationTimeout(10000);
  if (options.clock) await page.clock.install({ time: new Date('2026-11-23T12:00:00Z') });
  const errors = [], consoleErrors = [], documents = [], foreign = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('request', request => {
    if (new URL(request.url()).origin !== origin) foreign.push(request.url());
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
  });
  if (options.dialog) page.on('dialog', options.dialog);
  t.after(async () => {
    for (const item of scenario.gates) item.release();
    if (!page.isClosed()) {
      await image(page, id + '-final');
      const observations = await page.evaluate(() => {
        const state = window.__styleReadinessObservation;
        const summarize = item => ({ page: item.paint.page, revision: item.paint.revision,
          initial: item.initial, popstate: item.popstate, phase: item.phase || 'event', real: item.paint.real,
          imports: [item.paint.importRoot, item.paint.importMiddle, item.paint.importLeaf, item.paint.inlineImport] });
        return { last: state?.read(), loads: state?.loads.map(summarize), loadFrames: state?.loadFrames.map(summarize),
          cssEvents: state?.cssEvents.map(item => ({ type: item.type, href: item.href, media: item.media, dependencies: item.dependencies })) };
      });
      t.diagnostic(JSON.stringify({ fixture: id, observations, assets: scenario.assets, consoleErrors }));
    }
    await context.close();
    assert.deepEqual(errors, [], 'No uncaught browser JavaScript errors');
    assert.deepEqual(consoleErrors.filter(message => !message.startsWith('Failed to load resource:')), [], 'No non-resource console errors');
    assert.deepEqual(foreign, [], 'All document and asset requests are same-origin loopback');
    assert.equal(documents.length, options.fullRecovery ? 2 : 1, options.fullRecovery
      ? 'Failure after executable scripts begin uses the approved full-document recovery boundary'
      : 'Navigation and native Back/Forward retain the original document');
  });
  const response = await page.goto(origin + pageUrl(scenario, 'a'));
  assert.equal(response.status(), 200);
  await page.waitForFunction(() => Boolean(history.state?.__synapseNavigation) &&
    window.__styleReadinessObservation?.loadFrames.some(item => item.initial && item.phase === 'painted-frame'));
  assert.deepEqual(await page.evaluate(() => ({ navigation: typeof window.SynapseNavigation?.navigate,
    styles: typeof window.SynapsePageStyles?.stage })), { navigation: 'function', styles: 'function' },
  'The synthetic shell must use the real navigator and page-style helper');
  console.log(`Environment preparation is complete; formal testing is beginning (${id}).`);
  await image(page, id + '-entry');
  await assertPaint(page, 'a', 1);
  return { page, scenario, documents };
}

async function image(page, name) {
  const filename = path.join(screenshotDir, name + '.png');
  await page.screenshot({ path: filename });
  console.log('Style readiness screenshot: ' + filename);
}
function rgb(hex) {
  return `rgb(${[1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16)).join(', ')})`;
}
async function read(page) { return await page.evaluate(() => window.__styleReadinessObservation.read()); }
async function baseline(page) {
  const snapshot = await read(page);
  return { snapshot, main: await page.locator('#main').elementHandle(),
    css: await page.locator('[data-fixture-page-style]').elementHandles(),
    history: await page.evaluate(() => ({ length: history.length, state: history.state })),
    loadCount: await page.evaluate(() => window.__styleReadinessObservation.loads.length) };
}
function expectedPaint(name, revision) {
  return { page: name, revision, color: rgb(routes[name][1]), background: rgb(routes[name][2]), marker: name, cssRevision: revision };
}
function paintFields(snapshot) {
  return Object.fromEntries(['page', 'revision', 'color', 'background', 'marker', 'cssRevision'].map(key => [key, snapshot[key]]));
}
function assertSnapshot(snapshot, name, revision) {
  assert.deepEqual(paintFields(snapshot), expectedPaint(name, revision), 'The visible main must have its own complete page paint');
  assert.equal(snapshot.shellStable, true, 'Body/head/header/sidebar/nav/style owner retain object identity');
  assert.equal(snapshot.real.admin, routes[name][3] === 'admin' ? '#e5e7eb' : '', 'Actual administration CSS is present only on its page');
  assert.equal(snapshot.real.profile, routes[name][3] === 'profile' ? '#e5e7eb' : '', 'Actual profile CSS is present only on its page');
  assert.equal(snapshot.real.radius, '12px', 'A real /assets/css page rule must paint the sheet');
  if (name === 'nested') assert.deepEqual([snapshot.importRoot, snapshot.importMiddle, snapshot.importLeaf], ['ready', 'ready', 'ready'],
    'Every recursive import must be active at page-load and on observed frames');
  if (name === 'inline') assert.equal(snapshot.inlineImport, 'ready', 'The inline import is active at page-load and on observed frames');
}
async function assertPaint(page, name, revision) { assertSnapshot(await read(page), name, revision); }
async function assertRetained(page, previous, busy = true) {
  const snapshot = await read(page);
  assert.equal(await previous.main.evaluate(node => node === document.getElementById('main')), true, 'The outgoing main stays connected and identical');
  for (const node of previous.css) assert.equal(await node.evaluate(element => element.isConnected), true, 'The outgoing CSS stays connected');
  assert.deepEqual(paintFields(snapshot), paintFields(previous.snapshot), 'Held work must not expose incoming paint');
  assert.deepEqual(snapshot.real, previous.snapshot.real, 'Held work must not remove or override outgoing real CSS');
  assert.equal(snapshot.shellStable, true);
  assert.equal(snapshot.busy, busy);
  assert.equal(snapshot.url, previous.snapshot.url, 'A pending or failed request must not change the address');
  assert.deepEqual(await page.evaluate(() => ({ length: history.length, state: history.state })), previous.history, 'No pending/failed history payload');
  assert.equal(await page.evaluate(() => window.__styleReadinessObservation.loads.length), previous.loadCount);
}
async function assertInactive(page, incoming) {
  const snapshot = await read(page);
  assert.ok(snapshot.staged.length > 0, 'Readiness creates real inactive CSS nodes, not merely a detached parse');
  assert.ok(snapshot.staged.some(node => node.page === incoming), 'The incoming CSS is staged with media="not all"');
  assert.equal(snapshot.owned.every(node => node.page === snapshot.page && node.media !== 'not all'), true, 'The live owner still contains only active outgoing CSS');
}
async function assertClean(page, abandoned) {
  assert.deepEqual((await read(page)).staged, [], 'No inactive stage nodes survive completion/failure/abort');
  assert.equal(await page.locator('[data-page-style-stage]').count(), 0, 'No helper staging markers survive completion/failure/abort');
  if (abandoned) assert.equal(await page.locator(`[data-fixture-page-style="${abandoned}"]`).count(), 0, 'Abandoned CSS nodes are disconnected');
  assert.equal(await page.locator('#page-styles').count(), 1, 'No duplicate style owners');
  assert.equal(await page.locator('#page-scripts').count(), 1, 'No duplicate script owners');
}
async function click(page, name) {
  await page.getByRole('navigation', { name: 'Fixture pages' }).getByRole('link', { name: routes[name][0], exact: true }).click();
}
async function settled(page, scenario, name, revision) {
  await page.waitForFunction(({ name, revision }) => {
    const state = window.__styleReadinessObservation;
    return document.getElementById('main')?.dataset.fixturePage === name &&
      Number(document.getElementById('main')?.dataset.fixtureRevision) === revision && !document.body.classList.contains('is-navigating') &&
      state.loadFrames.some(item => item.paint.page === name && item.paint.revision === revision &&
        !item.initial && item.phase === 'painted-frame');
  }, { name, revision });
  assert.equal(new URL(page.url()).pathname, pageUrl(scenario, name));
  await assertPaint(page, name, revision);
  assert.equal(await page.locator('[data-script-status]').textContent(), 'Script ready: ' + name, 'The external script initialized at page-load');
  const records = await page.evaluate(({ name, revision }) => {
    const state = window.__styleReadinessObservation;
    return [...state.loads, ...state.loadFrames].filter(item => item.paint.page === name && item.paint.revision === revision).map(item => item.paint);
  }, { name, revision });
  assert.ok(records.length >= 3, 'Synchronous page-load paint, first rendering frame, and painted frame are observed');
  for (const record of records) assertSnapshot(record, name, revision);
  await assertClean(page);
}
async function assertNoBadFrames(page) {
  const frames = await page.evaluate(() => window.__styleReadinessObservation.frames);
  assert.ok(frames.length > 0, 'Frame and mutation paint sampling ran');
  for (const frame of frames) assertSnapshot(frame, frame.page, frame.revision);
}
async function assertPrivateHistory(page) {
  const state = await page.evaluate(() => history.state);
  const entry = state.__synapseNavigation;
  assert.equal(typeof entry.owner, 'string');
  assert.ok(Number.isInteger(entry.index));
  assert.equal(entry.url, page.url());
  assert.ok(Array.isArray(entry.scroll));
  const serialized = JSON.stringify(state);
  assert.ok(Buffer.byteLength(serialized) < 1500, 'History contains small private navigation metadata, not a document cache');
  assert.doesNotMatch(serialized, /<\s*(?:html|head|body|main|style|script)\b|readiness-canary|page-styles|page-scripts/i);
  for (const request of scenarios.get(new URL(page.url()).pathname.split('/')[2]).documents.slice(1)) {
    assert.equal(request.headers['x-synapse-navigation'], '1');
    assert.equal(request.headers.accept, 'text/html');
    assert.equal(request.headers['hx-request'], undefined);
    assert.equal(request.headers['x-requested-with'], undefined);
  }
}

// 11 focused scenarios. Each action is a visible link/form operation or native
// Back/Forward. Only the specific timeout scenario controls the browser clock.
test('head-owned CSS and an uncached external script both gate the synchronous paint swap', { timeout: 20000 }, async t => {
  let css, script;
  const { page, scenario } = await pageFor(t, 't01', { prepare(sc) {
    css = gate(sc, realCss(sc, 'external'));
    script = gate(sc, scriptUrl(sc, 'external'));
  } });
  assert.equal(await page.locator('head > template#page-styles[data-page-style-owner="head"]').count(), 1);
  assert.equal(await page.locator('head > template[data-page-styles-end]').count(), 1);
  const collection = await page.evaluate(() => {
    const describe = fragment => [...fragment.childNodes].map(node => ({ tag: node.tagName,
      media: node.media, page: node.dataset.fixturePageStyle }));
    return { document: describe(window.SynapsePageStyles.collect(document)),
      owner: describe(window.SynapsePageStyles.collect(document.getElementById('page-styles'))) };
  });
  assert.deepEqual(collection.document, [{ tag: 'LINK', media: 'screen', page: 'a' }, { tag: 'STYLE', media: '', page: 'a' }]);
  assert.deepEqual(collection.owner, collection.document, 'Document and head-owner collection extract only page-owned CSS');
  const previous = await baseline(page);
  await click(page, 'external');
  await deadline(css.entered, 'The real external stylesheet was not requested');
  await image(page, 't01-css-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'external');
  css.release();
  await deadline(script.entered, 'The uncached external page script was not requested');
  await image(page, 't01-script-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'external');
  assert.equal(await page.evaluate(() => window.__styleReadinessObservation.scripts.some(item => item.name === 'external')), false);
  script.release();
  await settled(page, scenario, 'external', 1);
  const executedPaint = await page.evaluate(() => window.__styleReadinessObservation.scripts
    .find(item => item.name === 'external')?.paint);
  assertSnapshot(executedPaint, 'a', 1);
  for (const node of previous.css) assert.equal(await node.evaluate(element => element.isConnected), false, 'Only old CSS nodes, not the owner, are retired');
  await image(page, 't01-ready');
  await assertNoBadFrames(page);
  await assertPrivateHistory(page);
});

test('an external @import tree waits for both an intermediate and a real deep stylesheet', { timeout: 20000 }, async t => {
  let middle, leaf;
  const { page, scenario } = await pageFor(t, 't02', { prepare(sc) {
    middle = gate(sc, fixtureCss(sc, 'nested-middle'));
    leaf = gate(sc, realCss(sc, 'nested'));
  } });
  const previous = await baseline(page);
  await click(page, 'nested');
  await deadline(middle.entered, 'The first nested @import was not requested');
  await image(page, 't02-intermediate-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'nested');
  middle.release();
  await deadline(leaf.entered, 'The recursive real stylesheet leaf was not requested');
  await image(page, 't02-deep-leaf-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'nested');
  t.diagnostic('Native CSS events before deep-leaf release: ' + JSON.stringify(await page.evaluate(() =>
    window.__styleReadinessObservation.cssEvents.filter(item => item.page === 'nested').map(item => ({ type: item.type, href: item.href, media: item.media })))));
  leaf.release();
  await settled(page, scenario, 'nested', 1);
  const snapshot = await read(page);
  assert.deepEqual([snapshot.importRoot, snapshot.importMiddle, snapshot.importLeaf], ['ready', 'ready', 'ready']);
  for (const filename of ['nested-root', 'nested-middle', 'nested-leaf']) {
    assert.ok(scenario.assets.some(item => item.path === fixtureCss(scenario, filename)), `Browser requested ${filename}`);
  }
  await image(page, 't02-ready');
  await assertNoBadFrames(page);
});

test('inline @import readiness includes recursively imported real CSS, not merely a style.sheet', { timeout: 20000 }, async t => {
  let leaf;
  const { page, scenario } = await pageFor(t, 't03', { prepare(sc) { leaf = gate(sc, realCss(sc, 'inline')); } });
  const previous = await baseline(page);
  await click(page, 'inline');
  await deadline(leaf.entered, 'The inline @import did not fetch its recursive real CSS dependency');
  await image(page, 't03-inline-import-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'inline');
  leaf.release();
  await settled(page, scenario, 'inline', 1);
  assert.equal((await read(page)).inlineImport, 'ready');
  assert.ok(scenario.assets.some(item => item.path === fixtureCss(scenario, 'inline-root')));
  await image(page, 't03-ready');
  await assertNoBadFrames(page);
});

test('legacy hidden DIV ownership extracts and stages CSS while retaining the live container', { timeout: 20000 }, async t => {
  let css;
  const { page, scenario } = await pageFor(t, 't04', { owner: 'legacy', prepare(sc) { css = gate(sc, realCss(sc, 'legacy')); } });
  assert.equal(await page.locator('body > div#page-styles[hidden]').count(), 1);
  const previous = await baseline(page);
  await click(page, 'legacy');
  await deadline(css.entered, 'The legacy owner stylesheet was not collected');
  await image(page, 't04-legacy-css-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'legacy');
  css.release();
  await settled(page, scenario, 'legacy', 1);
  const ownership = await page.evaluate(() => {
    const owner = document.getElementById('page-styles');
    const nodes = window.SynapsePageStyles.nodes(owner);
    const clone = owner.cloneNode(true);
    return { live: nodes.map(node => ({ tag: node.tagName, media: node.media, head: node.parentNode === document.head,
        dependency: node.href || [...node.sheet.cssRules].find(rule => rule.type === CSSRule.IMPORT_RULE)?.href || '' })),
      mirror: Boolean(owner.querySelector(':scope > template[data-page-style-assets]')),
      collected: [...window.SynapsePageStyles.collect(owner).childNodes].map(node => ({ tag: node.tagName, media: node.media })),
      cloneCollected: [...window.SynapsePageStyles.collect(clone).childNodes].map(node => ({ tag: node.tagName, media: node.media })) };
  });
  assert.deepEqual(ownership.live.map(node => ({ media: node.media, head: node.head, dependency: node.dependency })),
    [{ media: 'screen', head: true, dependency: origin + realCss(scenario, 'legacy') }, { media: '', head: true, dependency: '' }],
    'Logical legacy ownership leaves loaded native CSS and its canonical dependency connected in head');
  assert.ok(ownership.live.every(node => ['STYLE', 'LINK'].includes(node.tag)));
  assert.equal(ownership.mirror, true, 'An inert template preserves legacy owner cloning without activating duplicate CSS');
  assert.deepEqual(ownership.collected, [{ tag: 'LINK', media: 'screen' }, { tag: 'STYLE', media: '' }]);
  assert.deepEqual(ownership.cloneCollected, ownership.collected, 'Cloned legacy owner metadata retains stylesheet dependencies');
  for (const node of previous.css) assert.equal(await node.evaluate(element => element.isConnected), false);
  await image(page, 't04-ready');
  await assertNoBadFrames(page);
});

test('external and deeply imported stylesheet failures keep the outgoing page and remove staging', { timeout: 30000 }, async t => {
  for (const [id, name] of [['t05-external', 'stylesheet-fault'], ['t05-import', 'import-fault']]) {
    let css;
    const { page, scenario } = await pageFor(t, id, { prepare(sc) { css = gate(sc, realCss(sc, name)); } });
    const previous = await baseline(page);
    await click(page, name);
    await deadline(css.entered, `${name} did not request its real CSS dependency`);
    await image(page, id + '-held');
    await assertRetained(page, previous);
    scenario.failures.set(realCss(scenario, name), { status: 404, body: '/* deliberately unavailable synthetic stylesheet */' });
    css.release();
    await page.getByRole('button', { name: 'Retry navigation', exact: true }).waitFor({ state: 'visible' });
    await image(page, id + '-failed');
    await assertRetained(page, previous, false);
    await assertClean(page, name);
    const dependencyUrl = origin + (name === 'import-fault' ? fixtureCss(scenario, 'fault-root') : realCss(scenario, name));
    const cssEvents = await page.evaluate(({ name, dependencyUrl }) => window.__styleReadinessObservation.cssEvents
      .filter(item => item.page === name && (item.href === dependencyUrl || item.dependencies?.includes(dependencyUrl)))
      .map(item => item.type), { name, dependencyUrl });
    assert.ok(cssEvents.includes('load') || cssEvents.includes('error'), 'The failed external/imported stylesheet emitted a native terminal event');
    t.diagnostic(`Native ${name} dependency events before rejection: ${cssEvents.join(', ')}`);
    if (!cssEvents.includes('error')) assert.ok(scenario.assets.some(item => item.path === realCss(scenario, name) && item.destination === 'empty'),
      'A failed deep import with parent load is separately verified, not trusted as ready');
    assert.match(await page.locator('#navigation-status').textContent(), /current page has been kept/i);
    await assertNoBadFrames(page);
    await assertPrivateHistory(page);
    scenario.failures.delete(realCss(scenario, name));
    await page.getByRole('button', { name: 'Retry navigation', exact: true }).click();
    await settled(page, scenario, name, 2);
    await image(page, id + '-retry-ready');
    await assertNoBadFrames(page);
  }
});

test('uncached script failure keeps outgoing paint while held, then uses full-document recovery', { timeout: 20000 }, async t => {
  let script;
  const { page, scenario } = await pageFor(t, 't06', { fullRecovery: true,
    prepare(sc) { script = gate(sc, scriptUrl(sc, 'script-fault')); } });
  const previous = await baseline(page);
  await click(page, 'script-fault');
  await deadline(script.entered, 'The deliberately failing uncached script was not requested');
  await image(page, 't06-script-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'script-fault');
  const recovered = page.waitForURL(origin + pageUrl(scenario, 'script-fault'), { waitUntil: 'load' });
  script.release({ status: 503, body: '/* deliberately unavailable synthetic script */' });
  await recovered;
  await page.waitForFunction(() => document.getElementById('main')?.dataset.fixturePage === 'script-fault' &&
    !document.body.classList.contains('is-navigating') &&
    window.__styleReadinessObservation?.loadFrames.some(item => item.initial && item.phase === 'painted-frame'));
  await image(page, 't06-full-document-recovery');
  await assertPaint(page, 'script-fault', 2);
  await assertClean(page);
  assert.equal(await page.locator('[data-script-status]').textContent(), 'Script ready: script-fault');
  assert.equal(await page.locator('#navigation-retry').isHidden(), true);
  assert.equal(scenario.assets.filter(item => item.path === scriptUrl(scenario, 'script-fault')).length, 2);
  assert.equal(await page.evaluate(() => window.__styleReadinessObservation.scripts.filter(item => item.name === 'script-fault').length), 1);
});

test('the actual navigation timeout aborts inactive CSS and ignores its late response', { timeout: 20000 }, async t => {
  let css;
  const { page } = await pageFor(t, 't07', { clock: true, prepare(sc) { css = gate(sc, realCss(sc, 'slow')); } });
  const previous = await baseline(page);
  await click(page, 'slow');
  await deadline(css.entered, 'The timeout scenario never reached stylesheet staging');
  await image(page, 't07-timeout-held');
  await assertRetained(page, previous);
  await assertInactive(page, 'slow');
  await page.clock.fastForward(20001);
  await page.getByRole('button', { name: 'Retry navigation', exact: true }).waitFor({ state: 'visible' });
  await image(page, 't07-timed-out');
  assert.match(await page.locator('#navigation-status').textContent(), /timed out/i);
  await assertRetained(page, previous, false);
  await assertClean(page, 'slow');
  css.release();
  await page.clock.runFor(80);
  await image(page, 't07-late-response');
  await assertRetained(page, previous, false);
  await assertClean(page, 'slow');
  await assertNoBadFrames(page);
});

test('a visible second navigation supersedes held CSS and late first-page paint never leaks', { timeout: 20000 }, async t => {
  let css;
  const { page, scenario } = await pageFor(t, 't08', { prepare(sc) { css = gate(sc, realCss(sc, 'slow')); } });
  const previous = await baseline(page);
  await click(page, 'slow');
  await deadline(css.entered, 'Supersession scenario never reached stylesheet staging');
  await image(page, 't08-first-held');
  await assertRetained(page, previous);
  await click(page, 'next');
  await settled(page, scenario, 'next', 1);
  await assertClean(page, 'slow');
  await image(page, 't08-second-ready');
  const heldFrameCount = await page.evaluate(() => window.__styleReadinessObservation.frames.length);
  css.release();
  await page.waitForFunction(count => window.__styleReadinessObservation.frames.length >= count + 3, heldFrameCount);
  await image(page, 't08-late-first-response');
  await assertPaint(page, 'next', 1);
  await assertClean(page, 'slow');
  assert.equal(await page.evaluate(() => window.__styleReadinessObservation.loads.some(item => item.paint.page === 'slow')), false);
  assert.equal(await page.evaluate(() => history.state.__synapseNavigation.index), 1);
  await assertNoBadFrames(page);
  await assertPrivateHistory(page);
});

test('edits typed while CSS is held are rechecked by the native unsaved-change dialog', { timeout: 20000 }, async t => {
  let css;
  const dialogs = [], prompted = deferred();
  const { page } = await pageFor(t, 't09', { prepare(sc) { css = gate(sc, realCss(sc, 'external')); },
    dialog: async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); prompted.resolve(); } });
  const previous = await baseline(page);
  await click(page, 'external');
  await deadline(css.entered, 'Late-edit scenario never reached stylesheet staging');
  await page.getByRole('textbox', { name: 'Note', exact: true }).fill('Typed while the stylesheet was loading');
  await image(page, 't09-edited-while-held');
  await assertRetained(page, previous);
  css.release();
  await deadline(prompted.promise, 'Late edits were not rechecked before the destructive swap');
  await page.waitForFunction(() => !document.body.classList.contains('is-navigating'));
  await image(page, 't09-cancelled');
  assert.equal(dialogs.length, 1);
  assert.match(dialogs[0], /unsaved changes/i);
  assert.equal(await page.getByRole('textbox', { name: 'Note', exact: true }).inputValue(), 'Typed while the stylesheet was loading');
  await assertRetained(page, previous, false);
  await assertClean(page, 'external');
  assert.equal(await page.evaluate(() => window.__styleReadinessObservation.swaps.length), 0);
  await assertNoBadFrames(page);
});

test('cold native Back/Forward waits for styles, refetches HTML and keeps private shell history', { timeout: 25000 }, async t => {
  const { page, scenario } = await pageFor(t, 't10', { cache: 'cold' });
  await click(page, 'history-b');
  await settled(page, scenario, 'history-b', 1);
  await image(page, 't10-destination');
  const destination = await baseline(page);
  const backCss = gate(scenario, realCss(scenario, 'a'), { after: scenario.assetCounts.get(realCss(scenario, 'a')) || 0 });
  await page.goBack();
  await deadline(backCss.entered, 'Cold Back did not request the original real stylesheet');
  await image(page, 't10-back-held');
  // Native history changes the address before its asynchronous document fetch.
  assert.equal(await destination.main.evaluate(node => node === document.getElementById('main')), true);
  assert.deepEqual(paintFields(await read(page)), paintFields(destination.snapshot));
  assert.deepEqual((await read(page)).real, destination.snapshot.real, 'Cold Back keeps outgoing real CSS active while held');
  for (const node of destination.css) assert.equal(await node.evaluate(element => element.isConnected), true);
  await assertInactive(page, 'a');
  backCss.release();
  await settled(page, scenario, 'a', 2);
  await image(page, 't10-back-ready');
  const returned = await baseline(page);
  const forwardCss = gate(scenario, realCss(scenario, 'history-b'), {
    after: scenario.assetCounts.get(realCss(scenario, 'history-b')) || 0 });
  await page.goForward();
  await deadline(forwardCss.entered, 'Cold Forward did not request the destination real stylesheet');
  await image(page, 't10-forward-held');
  assert.equal(await returned.main.evaluate(node => node === document.getElementById('main')), true);
  assert.deepEqual(paintFields(await read(page)), paintFields(returned.snapshot));
  assert.deepEqual((await read(page)).real, returned.snapshot.real, 'Cold Forward keeps outgoing real CSS active while held');
  for (const node of returned.css) assert.equal(await node.evaluate(element => element.isConnected), true);
  await assertInactive(page, 'history-b');
  forwardCss.release();
  await settled(page, scenario, 'history-b', 2);
  assert.deepEqual(scenario.documents.map(item => item.name), ['a', 'history-b', 'a', 'history-b']);
  assert.equal(await page.evaluate(() => history.state.__synapseNavigation.index), 1);
  await assertPrivateHistory(page);
  await assertNoBadFrames(page);
});

test('cached native Back/Forward still refetches HTML; hash history neither swaps nor caches HTML', { timeout: 25000 }, async t => {
  const { page, scenario } = await pageFor(t, 't11');
  await click(page, 'history-b');
  await settled(page, scenario, 'history-b', 1);
  await image(page, 't11-destination');
  await page.goBack();
  await settled(page, scenario, 'a', 2);
  await image(page, 't11-cached-back');
  await page.goForward();
  await settled(page, scenario, 'history-b', 2);
  await image(page, 't11-cached-forward');
  assert.deepEqual(scenario.documents.map(item => item.name), ['a', 'history-b', 'a', 'history-b'], 'Back/Forward must fetch fresh authorized HTML, not reuse a document cache');
  for (const name of ['a', 'history-b']) {
    const entries = await page.evaluate(href => performance.getEntriesByName(href).map(entry => ({
      initiatorType: entry.initiatorType, transferSize: entry.transferSize, encodedBodySize: entry.encodedBodySize,
    })), origin + realCss(scenario, name));
    t.diagnostic(`Actual cached CSS timing (${name}): ${JSON.stringify(entries)}`);
    assert.ok(entries.some(entry => ['link', 'css'].includes(entry.initiatorType) && entry.transferSize > 0 && entry.encodedBodySize > 0),
      'The stylesheet started as a real cold browser stylesheet/import request');
    const nativeLoads = await page.evaluate(href => window.__styleReadinessObservation.cssEvents
      .filter(item => item.type === 'load' && (item.href === href || item.dependencies?.includes(href))).length,
    origin + realCss(scenario, name));
    assert.ok(nativeLoads >= 2, 'Native stylesheet load fires again during history restoration');
    assert.equal(scenario.assets.filter(item => item.path === realCss(scenario, name)).length, 1,
      'History uses Chromium cached CSS without another server request (memory reuse may omit another timing entry)');
    assert.equal(scenario.assets.filter(item => item.path === scriptUrl(scenario, name)).length, 1, 'Previously executed external scripts are not executed/fetched again');
  }
  const previous = await baseline(page), htmlReads = scenario.documents.length;
  await page.getByRole('link', { name: 'Jump to page anchor', exact: true }).click();
  await page.waitForFunction(() => location.hash === '#fixture-anchor' && scrollY > 600);
  await image(page, 't11-hash');
  assert.equal(await previous.main.evaluate(node => node === document.getElementById('main')), true);
  await assertPrivateHistory(page);
  await page.goBack();
  await page.waitForFunction(() => !location.hash && history.state.__synapseNavigation.index === 1);
  await image(page, 't11-hash-back');
  assert.equal(await previous.main.evaluate(node => node === document.getElementById('main')), true);
  await page.goForward();
  await page.waitForFunction(() => location.hash === '#fixture-anchor' && history.state.__synapseNavigation.index === 2);
  await image(page, 't11-hash-forward');
  assert.equal(await previous.main.evaluate(node => node === document.getElementById('main')), true);
  assert.equal(scenario.documents.length, htmlReads, 'Same-document hash traversal must not fetch or swap a main');
  assert.equal(await page.evaluate(() => window.__styleReadinessObservation.loads.length), previous.loadCount);
  await assertPaint(page, 'history-b', 2);
  await assertPrivateHistory(page);
  await assertNoBadFrames(page);
});
