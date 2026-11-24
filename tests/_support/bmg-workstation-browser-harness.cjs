'use strict';

// Isolated UI intent only. No application, device API, database, serial port or credentials.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const crypto = require('node:crypto');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(__dirname, 'render-bmg-workstation-fixture.php');
const listPath = '/facilities';
const dashboardPath = '/facilities/dashboard';
const detailPath = '/facilities/unit/98001';
const cookieName = 'synthetic_bmg_workstation';
const dashboardDays = ['Nov 18', 'Nov 19', 'Nov 20', 'Nov 21', 'Nov 22', 'Nov 23', 'Nov 24'];
const dashboardSeries = [3, 0, 8, 4, 2, 9, 5];
const scripts = ['vendor/apexcharts/apexcharts.min.js', 'js/chart-theme.js', 'vendor/bootstrap/js/bootstrap.bundle.min.js', 'js/custom-dropdown.js', 'js/datetimepicker.js', 'js/loading.js', 'js/csrf.js', 'js/toast.js', 'js/confirm.js', 'js/page-styles.js', 'js/form-modal.js', 'js/page-lifecycle.js', 'js/navigation.js'];
const assets = new Set(scripts.map(file => '/assets/' + file));
for (const file of ['bmg.js', 'bmg-gear.js', 'bmg-gear-renderer.js']) assets.add('/assets/js/' + file);
for (const file of ['drum-maroon.png', 'drum-whte.png']) assets.add('/' + file);
function collectCss(relative) {
  if (assets.has('/assets/' + relative)) return;
  assets.add('/assets/' + relative);
  const css = fs.readFileSync(path.join(publicRoot, 'assets', relative), 'utf8');
  for (const match of css.matchAll(/@import\s+url\(["']([^"']+)["']\)/g)) collectCss(path.posix.join(path.posix.dirname(relative), match[1]));
}
for (const file of ['css/style.css', 'css/bmg.css', 'css/pages/bmg-workstation.css', 'vendor/bootstrap/css/bootstrap.min.css', 'vendor/bootstrap-icons/bootstrap-icons.css']) collectCss(file);
assets.add('/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff');
assets.add('/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2');
const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/app.php'), 'utf8');
const confirmation = layout.match(/<div class="modal fade" id="synapseConfirmModal"[\s\S]*?(?=\s*<!-- Vendor JS Files -->)/)?.[0];
if (!confirmation || confirmation.includes('<?')) throw new Error('Shared confirmation markup could not be isolated.');
const variants = ['full', 'reader', 'operator', 'config-only', 'gate-off', 'unbound', 'empty', 'active-batch', 'zero-version', 'stale', 'archived', 'zero-batches'];
const postPaths = new Set(['/facilities/units/store', ...['update', 'schedule', 'command', 'batch', 'archive', 'restore', 'device'].map(action => detailPath + '/' + action), '/facilities/device/99001/revoke', '/facilities/batch/99501/release', '/facilities/batch/99501/cancel']);
function validateDashboardSeries(values) {
  if (!Array.isArray(values) || values.length !== 7) throw new Error('Exactly seven bounded synthetic Manila-day counts are required.');
  for (const value of values) if (!Number.isInteger(value) || value < 0 || value > 1000) throw new Error('Exactly seven bounded synthetic Manila-day counts are required.');
}
function renderFixture(origin, url, options = {}) {
  const kind = new Map([[listPath, 'index'], [dashboardPath, 'dashboard'], [detailPath, 'unit_detail']]).get(url.pathname);
  if (!kind) throw new Error('Explicit synthetic workstation routes only.');
  const data = { origin, url: url.href, kind, variant: options.variant || 'full', long: options.long === true, csrfHash: options.csrfHash || crypto.randomBytes(32).toString('hex') };
  if (Object.hasOwn(options, 'dashboardSeries')) {
    if (kind !== 'dashboard') throw new Error('Dashboard counts belong to the dashboard route only.');
    validateDashboardSeries(options.dashboardSeries);
    data.dashboardSeries = options.dashboardSeries;
  }
  const env = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'].filter(key => process.env[key]).map(key => [key, process.env[key]]));
  return JSON.parse(execFileSync('php', ['-n', '-d', `open_basedir=${[path.join(root, 'app/Views'), __dirname].join(path.delimiter)}`, '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,mail,fsockopen,pfsockopen', renderer], { cwd: root, env, input: JSON.stringify(data), encoding: 'utf8', timeout: 20000, maxBuffer: 2097152 }));
}
function queryAllowed(url) {
  const seen = new Set();
  for (const [key, value] of url.searchParams) {
    if (seen.has(key)) return false;
    seen.add(key);
    if (url.pathname === listPath && key === 'view') { if (!['current', 'archived'].includes(value)) return false; }
    else if (url.pathname === listPath && key === 'create') { if (!['0', '1'].includes(value)) return false; }
    else if ((url.pathname === listPath && key === 'per_page') || (url.pathname === detailPath && ['commands_per_page', 'runs_per_page', 'batches_per_page'].includes(key))) { if (!['20', '25', '50', '100'].includes(value)) return false; }
    else if ((url.pathname === listPath && key === 'page') || (url.pathname === detailPath && ['commands_page', 'runs_page', 'batches_page'].includes(key))) { if (!/^[1-9]\d{0,3}$/.test(value) || Number(value) > 1000) return false; }
    else return false;
  }
  return true;
}
function allowedRequest(origin, url, method = 'GET') {
  if (url.origin !== origin || url.username || url.password || /[\\\0]/.test(url.pathname)) return false;
  if (assets.has(url.pathname)) return method === 'GET' && (!url.search || (/bootstrap-icons\.woff2?$/.test(url.pathname) && url.search === '?dd67030699838ea613ee6dbda90effa6'));
  if (method === 'POST') return !url.search && postPaths.has(url.pathname);
  if (method !== 'GET') return false;
  if (url.pathname === '/bmg-fixture-neighbor') return !url.search;
  return [listPath, dashboardPath, detailPath].includes(url.pathname) && queryAllowed(url);
}
function documentFixture(origin, url, scenario) {
  const neighbor = url.pathname === '/bmg-fixture-neighbor';
  const options = { ...scenario.options, csrfHash: scenario.csrfHash };
  if (url.pathname === dashboardPath && scenario.options.dashboardSeriesVisits) {
    // Predetermined GET-only seeds exercise re-entry without changing app or chart state.
    const visits = scenario.options.dashboardSeriesVisits;
    options.dashboardSeries = visits[Math.min(scenario.dashboardVisits++, visits.length - 1)];
  }
  const sections = neighbor ? {} : renderFixture(origin, url, options);
  const title = neighbor ? 'Synthetic Neighbor' : (url.pathname === listPath ? 'BMG Workstation' : (url.pathname === dashboardPath ? 'BMG Dashboard' : 'Manage Drum'));
  const content = neighbor ? '<p>Read-only synthetic neighbor. No hardware or application records.</p><a href="/facilities">Open BMG workstation</a><a href="/facilities/dashboard">Open BMG dashboard</a>' : sections.content;
  return `<!doctype html><html lang="en" data-theme="${scenario.options.theme || 'light'}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,"><title>${title} — Synthetic UI</title>
    <meta name="csrf-name" content="csrf_test_name"><meta name="csrf-token" content="${scenario.csrfHash}"><meta name="csrf-header" content="X-Fixture-CSRF">
    <meta name="synapse-refresh-regions-enabled" content="0"><meta name="synapse-refresh-region-owners" content="">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css"><link rel="stylesheet" href="/assets/css/style.css">
    <style>#sidebar .nav-link{display:block;padding:12px;color:#fff}#sidebar .nav-link:hover{background:#5a0000}#header{padding:0 16px;font-size:12px}.pagetitle{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}</style>
    <template id="page-styles" data-page-style-owner="head"></template>${sections.styles || ''}<template data-page-styles-end></template></head>
    <body class="app-shell" data-navigation-scope="synthetic-bmg-workstation"><header id="header" class="header">SYNTHETIC UI ONLY — no live hardware, application records or credentials</header><aside id="sidebar" class="sidebar"><nav class="sidebar-nav"><a class="nav-link" href="${origin + listPath}">BMG Workstation</a><a class="nav-link" href="${origin + dashboardPath}">BMG Dashboard</a><a class="nav-link" href="${origin}/bmg-fixture-neighbor">Synthetic Neighbor</a></nav></aside>
    <main id="main" class="main"><div class="main-content"><div class="pagetitle d-flex justify-content-between align-items-center mb-3"><div class="page-title-copy"><h1 class="h3 mb-0">${title}</h1></div><div class="page-actions">${sections.page_actions || ''}</div></div><section class="section">${content}</section></div></main>
    ${confirmation}${scripts.map(file => '<script src="/assets/' + file + '"></script>').join('')}<div id="page-scripts" hidden>${sections.scripts || ''}</div></body></html>`;
}
function parseBody(bytes, contentType) {
  const text = bytes.toString('utf8');
  if (contentType.startsWith('application/x-www-form-urlencoded')) return Array.from(new URLSearchParams(text));
  const boundary = /boundary=(?:"([^"]+)"|([^;]+))/.exec(contentType);
  if (!boundary) throw new Error('Synthetic form encoding only.');
  return text.split('--' + (boundary[1] || boundary[2])).flatMap(part => {
    const name = /name="([^"\r\n]+)"/.exec(part)?.[1];
    const start = part.indexOf('\r\n\r\n');
    if (!name || start < 0 || /filename=/.test(part)) return [];
    return [[name, part.slice(start + 4).replace(/\r\n$/, '')]];
  });
}
async function startServer({ port = 0, preview = false } = {}) {
  let origin, sequence = 0;
  const scenarios = new Map();
  const totals = { views: 0, assets: 0, posts: 0, rejected: 0 };
  const createScenario = options => {
    if (scenarios.size >= 60 || (options?.variant && !variants.includes(options.variant))) throw new Error('Invalid synthetic scenario.');
    if (options && Object.hasOwn(options, 'dashboardSeries')) validateDashboardSeries(options.dashboardSeries);
    if (options && Object.hasOwn(options, 'dashboardSeriesVisits')) {
      const visits = options.dashboardSeriesVisits;
      if (!Array.isArray(visits) || visits.length < 1 || visits.length > 8 || Object.hasOwn(options, 'dashboardSeries')) throw new Error('One bounded synthetic dashboard visit plan is required.');
      for (const values of visits) validateDashboardSeries(values);
    }
    const scenario = { id: String(++sequence), options: structuredClone(options || {}), dashboardVisits: 0, csrfHash: crypto.randomBytes(32).toString('hex'), requests: [], posts: [], statuses: [...(options?.postStatuses || [])] };
    scenarios.set(scenario.id, scenario);
    return scenario;
  };
  const fallback = preview ? createScenario({}) : null;
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, origin);
    if (req.headers.host !== new URL(origin).host || !allowedRequest(origin, url, req.method)) { totals.rejected++; res.writeHead(404).end('Synthetic BMG scope only'); return; }
    res.setHeader('Cache-Control', 'private, no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
    if (assets.has(url.pathname)) {
      const file = fs.realpathSync(path.resolve(publicRoot, '.' + url.pathname));
      if (!file.startsWith(fs.realpathSync(publicRoot) + path.sep)) { res.writeHead(404).end(); return; }
      totals.assets++;
      fs.readFile(file, (error, data) => {
        if (error) { res.writeHead(404).end(); return; }
        const type = { '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff': 'font/woff', '.woff2': 'font/woff2' }[path.extname(file)];
        res.writeHead(200, { 'Content-Type': type || 'application/octet-stream' }).end(data);
      });
      return;
    }
    const cookie = new RegExp('(?:^|;\\s*)' + cookieName + '=(\\d+)(?:;|$)').exec(req.headers.cookie || '');
    const scenario = scenarios.get(cookie?.[1]) || fallback;
    if (!scenario || scenario.requests.length >= 100) { totals.rejected++; res.writeHead(404).end('Unknown synthetic scenario'); return; }
    scenario.requests.push({ method: req.method, url: url.pathname + url.search });
    if (req.method === 'POST') {
      if (scenario.posts.length >= 10) { res.writeHead(429).end('Synthetic request limit'); return; }
      const chunks = []; let size = 0;
      for await (const chunk of req) { size += chunk.length; if (size > 16384) { res.writeHead(413).end(); return; } chunks.push(chunk); }
      const entries = parseBody(Buffer.concat(chunks), req.headers['content-type'] || '');
      scenario.posts.push({ path: url.pathname, entries, headers: req.headers });
      totals.posts++;
      if (url.pathname.endsWith('/device')) {
        // Observe native delivery intent without issuing or showing any credential.
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end('<!doctype html><html><body><h1>Synthetic registration intent only</h1><p>No device credential was issued. No hardware was contacted.</p></body></html>');
        return;
      }
      const status = scenario.statuses.shift() || 200;
      scenario.csrfHash = crypto.randomBytes(32).toString('hex');
      const refreshUrl = req.headers['x-synapse-refresh-url'];
      const redirect = url.pathname === '/facilities/units/store' ? origin + detailPath : (url.pathname.endsWith('/archive') || url.pathname.endsWith('/restore') ? origin + listPath : (refreshUrl || origin + detailPath));
      const payload = { success: status < 400, message: status < 400 ? 'Synthetic UI request accepted; no real record or device changed.' : 'Synthetic request rejected; review the retained fields.', redirect, csrf: { name: 'csrf_test_name', hash: scenario.csrfHash, header: 'X-Fixture-CSRF' } };
      const send = () => { if (!res.destroyed) res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(payload)); };
      if (scenario.options.postDelayMs) setTimeout(send, Math.min(1500, scenario.options.postDelayMs)); else send();
      return;
    }
    totals.views++;
    try { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(documentFixture(origin, url, scenario)); }
    catch (error) { res.writeHead(500).end('Synthetic BMG rendering failed'); console.error('Synthetic renderer failed:', error.status || error.code || 'fixture'); }
  });
  server.listen(port, '127.0.0.1');
  await once(server, 'listening');
  origin = 'http://127.0.0.1:' + server.address().port;
  return { origin, createScenario, totals, close: async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } };
}
function localPlaywright() {
  const installed = [path.join(root, 'tests/browser/node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')].find(file => fs.existsSync(path.join(file, 'package.json')));
  if (!installed) throw new Error('Use installed Playwright only.');
  return require(installed);
}
function installedChromium(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'win32' ? path.join(os.homedir(), 'AppData/Local/ms-playwright') : path.join(os.homedir(), '.cache/ms-playwright'));
  return fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1])).flatMap(name => ['chrome-win64/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome'].map(file => path.join(cache, name, file))).find(file => fs.existsSync(file));
}
module.exports = { startServer, renderFixture, allowedRequest, localPlaywright, installedChromium, listPath, dashboardPath, detailPath, cookieName, variants, dashboardDays, dashboardSeries };
