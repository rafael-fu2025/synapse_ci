'use strict';

// GET-only synthetic aggregate pages. No app/bootstrap, database or real evaluations.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const os = require('node:os');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public/assets');
const renderer = path.join(__dirname, 'render-guidance-service-evaluations-fixture.php');
const listPath = '/guidance/evaluations/dashboard';
const pdfPath = '/guidance/evaluations/aggregate-pdf';
const cookieName = 'synthetic_service_evaluations';
const types = ['psychosocial_support_evaluation', 'counseling_evaluation'];
const labels = {
  psychosocialLabels: {
    rating_welcoming: 'The guidance personnel were approachable and welcoming.',
    rating_empathy: 'The guidance personnel understood and empathized with me.',
    rating_clarity: 'The goal of the psychosocial support session was clear and achieved.',
    rating_helpfulness: 'The psychosocial support session was helpful.',
    rating_environment: 'Confidentiality and a safe, supportive environment were maintained.',
  },
  counselingLabels: {
    rating_rapport: 'The counselor was approachable, welcoming, and established good rapport.',
    rating_understanding: 'The counselor understood and empathized with me.',
    rating_goal_progress: 'The goal of the counseling session was achieved and progress was made.',
    rating_confidentiality_comfort: 'Confidentiality was kept and I felt comfortable during the session.',
    rating_overall_service: 'Overall, the counseling session was helpful and effective.',
  },
  visitTypeLabels: { walk_in: 'Voluntarily came / Walk-in', called: 'Called by Guidance Personnel / Counselor', referred: 'Referred', follow_up: 'Follow-up' },
};
const providers = Array.from({ length: 12 }, (_, index) => ({ id: 97001 + index, first_name: 'Synthetic Provider ' + String(index + 1).padStart(2, '0'), last_name: 'Synthetic Staff', username: 'Synthetic_provider_' + index, role: index === 1 ? 'guidance_supervisor' : 'counsellor' }));
const hostileComment = 'Synthetic comment <img src="https://invalid.example/leak" onerror="alert(1)"> & "quoted" text.';
const longText = 'Synthetic' + 'LongUnbrokenName'.repeat(15);
const scripts = ['vendor/bootstrap/js/bootstrap.bundle.min.js', 'js/custom-dropdown.js', 'js/loading.js', 'js/csrf.js', 'js/page-styles.js', 'js/page-lifecycle.js', 'js/navigation.js'];
const assets = new Set(scripts.map(file => '/assets/' + file));
function collectCss(relative) {
  if (assets.has('/assets/' + relative)) return;
  assets.add('/assets/' + relative);
  const file = path.resolve(publicRoot, relative);
  const css = fs.readFileSync(file, 'utf8');
  for (const match of css.matchAll(/@import\s+url\(["']([^"']+)["']\)/g)) collectCss(path.posix.join(path.posix.dirname(relative), match[1]));
}
collectCss('css/style.css');
collectCss('css/pages/guidance-service-evaluations.css');
collectCss('vendor/bootstrap/css/bootstrap.min.css');
collectCss('vendor/bootstrap-icons/bootstrap-icons.css');
assets.add('/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff');
assets.add('/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2');

function escape(value) { return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function aggregate(total, type, options = {}) {
  const keys = Object.keys(type === types[0] ? labels.psychosocialLabels : labels.counselingLabels);
  const means = type === types[0] ? [4.6, 4.8, 4.4, 4.7, 4.75] : [4.2, 4.4, 4.5, 4.6, 4.3];
  const averages = Object.fromEntries(keys.map((key, index) => [key, means[index]]));
  averages.overall_mean = type === types[0] ? 4.65 : 4.4;
  const suppressed = total < 5;
  const comments = options.noComments ? [] : [options.long ? hostileComment + '\n' + longText : 'Synthetic: The session was helpful and the environment felt supportive.'];
  return {
    cohort_met: !suppressed, total_responses: total, minimum_required: 5,
    averages: suppressed ? (options.canaries ? { ...averages, overall_mean: 4.99 } : null) : averages,
    boolean_percentage: suppressed ? (options.canaries ? 88.8 : null) : (type === types[0] ? 87.5 : 80),
    boolean_rate: suppressed ? null : (type === types[0] ? 0.875 : 0.8),
    visit_type_distribution: type === types[0] && (!suppressed || options.canaries) ? { walk_in: 1, called: 1, referred: 1, follow_up: Math.max(0, total - 3) } : null,
    comments: suppressed ? (options.canaries ? ['Synthetic withheld comment'] : []) : comments,
  };
}
function fixtureData(origin, url, options = {}) {
  const allProviders = structuredClone(providers);
  if (options.long) allProviders[0].last_name = longText + ' <script>synthetic</script>';
  const canViewAnyProvider = options.canViewAnyProvider !== false;
  const requested = Number(url.searchParams.get('provider_user_id'));
  const selectedId = canViewAnyProvider && allProviders.some(provider => provider.id === requested) ? requested : 97001;
  const selected = allProviders.find(provider => provider.id === selectedId);
  const filters = Object.fromEntries(['academic_term', 'academic_year'].map(key => [key, (url.searchParams.get(key) || '').trim()]).filter(([, value]) => value));
  let totals = options.totals || [8, 6];
  if (filters.academic_term === 'Synthetic Narrow') totals = [4, 5];
  if (filters.academic_term === 'Synthetic Missing') totals = [0, 0];
  return {
    origin, currentUrl: url.href, providerUserId: selectedId,
    providerName: selected.first_name + ' ' + selected.last_name,
    providers: options.emptyProviders ? [] : allProviders,
    canViewAnyProvider, canModerate: options.canModerate !== false, cohortGateEnabled: options.cohortGateEnabled !== false,
    filters, ...labels,
    psychosocial: aggregate(totals[0], types[0], options), counseling: aggregate(totals[1], types[1], options),
  };
}
function renderFixture(data) {
  const env = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
    .filter(key => process.env[key]).map(key => [key, process.env[key]]));
  return JSON.parse(execFileSync('php', ['-n', '-d', `open_basedir=${[path.join(root, 'app/Views'), __dirname].join(path.delimiter)}`,
    '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
    '-d', 'display_errors=stderr', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,mail,fsockopen,pfsockopen', renderer],
  { cwd: root, env, input: JSON.stringify(data), encoding: 'utf8', timeout: 15000, maxBuffer: 2097152 }));
}
function documentFixture(origin, url, scenario) {
  const isRegistry = url.pathname === listPath;
  const sections = isRegistry ? renderFixture(fixtureData(origin, url, scenario.options)) : {};
  const content = isRegistry ? sections.content : '<section class="ce-workspace"><div class="ce-card"><div class="ce-card-body"><p>Synthetic read-only destination. No real records or moderation actions.</p><a href="' + listPath + '">Open Service Evaluation Aggregates</a></div></div></section>';
  return `<!doctype html><html lang="en" data-theme="${scenario.options.theme || 'light'}"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,"><title>Synthetic service evaluation aggregates</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/css/style.css"><meta name="csrf-name" content="synthetic_csrf"><meta name="csrf-token" content="synthetic_only"><meta name="csrf-header" content="X-Synthetic-CSRF">
    <style>#sidebar .nav-link{display:block;padding:12px;color:#fff}#sidebar .nav-link:hover{background:#5a0000}#header{padding:0 16px;font-size:12px}</style>
    <template id="page-styles" data-page-style-owner="head"></template>${sections.styles || ''}<template data-page-styles-end></template>
    </head><body class="app-shell" data-navigation-scope="synthetic-service-evaluations">
    <header id="header" class="header">SYNTHETIC TEST ONLY — invented aggregates; no application or database</header>
    <aside id="sidebar" class="sidebar"><nav class="sidebar-nav"><a class="nav-link" href="${origin + listPath}">Evaluation Aggregates</a><a class="nav-link" href="${origin}/guidance">Guidance Hub</a></nav></aside>
    <main id="main" class="main"><div class="main-content"><div class="pagetitle"><h1>${isRegistry ? 'Service Evaluation Aggregates' : 'Synthetic Guidance Destination'}</h1></div>${sections.page_actions || ''}<section class="section">${content}</section></div></main>
    ${scripts.map(file => '<script src="/assets/' + file + '"></script>').join('')}<div id="page-scripts" hidden>${sections.scripts || ''}</div></body></html>`;
}
function allowedRequest(origin, url) {
  if (url.origin !== origin || url.username || url.password || /[\\\0]/.test(url.pathname)) return false;
  if (assets.has(url.pathname)) return !url.search || (/bootstrap-icons\.woff2?$/.test(url.pathname) && url.search === '?dd67030699838ea613ee6dbda90effa6');
  if (url.pathname === '/guidance' || url.pathname === '/guidance/evaluations/moderate') return !url.search;
  if (![listPath, pdfPath].includes(url.pathname)) return false;
  const seen = new Set();
  for (const [key, value] of url.searchParams) {
    if (seen.has(key)) return false;
    seen.add(key);
    if (key === 'provider_user_id') { if (!/^970(?:0[1-9]|[1-3]\d|40)$/.test(value)) return false; }
    else if (key === 'academic_term' || key === 'academic_year') { if (Buffer.byteLength(value) > (key === 'academic_term' ? 64 : 32)) return false; }
    else if (key === 'evaluation_type' && url.pathname === pdfPath) { if (!types.includes(value)) return false; }
    else return false;
  }
  return url.pathname !== pdfPath || seen.has('evaluation_type');
}
async function startServer({ port = 0, preview = false } = {}) {
  let origin, sequence = 0;
  const scenarios = new Map();
  const totals = { views: 0, assets: 0, pdfs: 0, rejected: 0 };
  const createScenario = options => {
    if (scenarios.size >= 40) throw new Error('Synthetic scenario limit reached.');
    const scenario = { id: String(++sequence), options: structuredClone(options || {}), requests: [] };
    scenarios.set(scenario.id, scenario);
    return scenario;
  };
  const fallback = preview ? createScenario({ totals: [8, 4] }) : null;
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, origin);
    if (req.method !== 'GET' || req.headers.host !== new URL(origin).host || !allowedRequest(origin, url)) {
      totals.rejected++; res.writeHead(404).end('Synthetic GET fixture only'); return;
    }
    res.setHeader('Cache-Control', 'private, no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
    if (assets.has(url.pathname)) {
      const file = path.resolve(publicRoot, '.' + url.pathname.slice('/assets'.length));
      if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(404).end(); return; }
      totals.assets++;
      fs.readFile(file, (error, bytes) => {
        if (error) { res.writeHead(404).end(); return; }
        res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : (file.endsWith('.js') ? 'text/javascript' : 'font/woff2') }).end(bytes);
      });
      return;
    }
    const cookie = new RegExp('(?:^|;\\s*)' + cookieName + '=(\\d+)(?:;|$)').exec(req.headers.cookie || '');
    const scenario = scenarios.get(cookie?.[1]) || fallback;
    if (!scenario || scenario.requests.length >= 40) { totals.rejected++; res.writeHead(404).end('Unknown synthetic scenario'); return; }
    scenario.requests.push(url.pathname + url.search);
    if (url.pathname === pdfPath) {
      totals.pdfs++;
      res.setHeader('X-Synthetic-PDF', 'html-test-landing-not-a-pdf');
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end('<!doctype html><html><body><h1>SYNTHETIC PDF LINK LANDING — HTML, NOT A PDF</h1><p>No PDF generation, real evaluations or document issuance.</p><pre>' + escape(url.searchParams.toString()) + '</pre></body></html>');
      return;
    }
    totals.views++;
    try { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(documentFixture(origin, url, scenario)); }
    catch (error) { res.writeHead(500).end('Synthetic renderer failed'); console.error(error); }
  });
  server.listen(port, '127.0.0.1');
  await once(server, 'listening');
  origin = 'http://127.0.0.1:' + server.address().port;
  return { origin, createScenario, totals, close: async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } };
}
function localPlaywright() {
  const installed = [path.join(root, 'tests/browser/node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')].find(file => fs.existsSync(path.join(file, 'package.json')));
  if (!installed) throw new Error('Existing local Playwright is required; no packages are installed.');
  return require(installed);
}
function installedChromium(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'win32' ? path.join(os.homedir(), 'AppData/Local/ms-playwright') : path.join(os.homedir(), '.cache/ms-playwright'));
  return fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome'].map(file => path.join(cache, name, file))).find(file => fs.existsSync(file));
}
module.exports = { startServer, fixtureData, renderFixture, allowedRequest, localPlaywright, installedChromium, listPath, pdfPath, cookieName, types, labels, providers, hostileComment, longText };
