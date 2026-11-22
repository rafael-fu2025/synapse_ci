'use strict';

// Actual gates view + local assets only. All mutations change these synthetic
// per-scenario Maps. Never bootstrap CI/Composer/dotenv/controllers/a database.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(__dirname, 'render-guidance-rollout-gates-fixture.php');
const gatesPath = '/guidance/governance/gates';
const owner = 'guidance.governance.gates';
const limits = Object.freeze({ bodyBytes: 16384, rendererBytes: 2 * 1024 * 1024, requests: 32, posts: 8, diagnostics: 20 });
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml', '.png': 'image/png' };
const authorityRoles = ['guidance_head', 'qa_office', 'dpo'];
const hostile = '<img src="https://invalid.example/gates" onerror="alert(1)"><script>alert("synthetic")</script> & "quoted"';
const longText = 'UnbrokenSyntheticGovernanceReference'.repeat(35);
const stamp = '2030-01-31 00:30:00';
const savedStamp = '2030-02-01 04:05:00';
const environment = () => Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
  .filter(key => process.env[key]).map(key => [key, process.env[key]]));

function renderFixture(options) {
  const result = execFileSync('php', ['-n', '-d', 'allow_url_fopen=0', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', renderer], {
    cwd: root, env: environment(), input: JSON.stringify(options), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 10000, maxBuffer: limits.rendererBytes,
  });
  assert.doesNotMatch(result, /Fatal error|Warning:|Notice:|Deprecated:|Parse error|"code":\s*500/, 'Actual gates view must render without PHP diagnostics');
  return result;
}
function localPlaywright() {
  const candidates = [path.join(root, 'tests/browser/node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')];
  const installed = candidates.find(candidate => fs.existsSync(path.join(candidate, 'package.json')));
  assert.ok(installed, 'Installed local Playwright is required; no packages are installed');
  return require(installed);
}
function installedChromium(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const cache = process.env.PLAYWRIGHT_BROWSERS_PATH || (process.platform === 'win32'
    ? path.join(os.homedir(), 'AppData/Local/ms-playwright') : path.join(os.homedir(), '.cache/ms-playwright'));
  const installed = fs.existsSync(cache) ? fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe', 'chrome-linux/chrome', 'chrome-linux64/chrome', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium']
      .map(file => path.join(cache, name, file))).find(file => fs.existsSync(file)) : null;
  assert.ok(installed, `Existing Chromium required at ${cache}; this harness never downloads browsers`);
  return installed;
}
function gate(key, changes = {}) {
  return { gate_key: key, title: `Synthetic ${key} activation`, description: 'Invented rollout policy; no real institutional approval.',
    required_signoffs: [...authorityRoles], recorded_signoffs: {}, is_enabled: 0, approved_at: null, ...changes };
}
function entry(user_id = 93001, notes = 'Synthetic memorandum only.', signed_at = stamp) { return { user_id, notes, signed_at }; }
function fixtureData(kind = 'mixed') {
  const pending = gate('synthetic_initial_review', { title: `Synthetic pending 'review' ${hostile}`, description: hostile + '\n' + longText });
  const partial = gate('synthetic_limited_pilot', { recorded_signoffs: { guidance_head: entry(93001, hostile + '\n' + longText) } });
  const enabled = gate('synthetic_completed_review', { is_enabled: 1, approved_at: stamp,
    recorded_signoffs: { guidance_head: entry(93001), qa_office: entry(93002, ''), dpo: entry(0, 'Synthetic officer reference', '') } });
  const data = { canSignOff: true, authorityRoles: [...authorityRoles],
    signerNames: { 93001: `Synthetic Officer ${hostile} ${longText}`, 93999: 'Synthetic fixture actor' } };
  if (kind === 'pending') data.gates = [pending];
  else if (kind === 'partial') data.gates = [partial];
  else if (kind === 'enabled') data.gates = [enabled];
  else if (kind === 'empty') data.gates = [];
  else if (kind === 'read-only') { data.gates = [partial, enabled]; data.canSignOff = false; }
  else if (kind === 'customgate') data.gates = [gate('synthetic_custom / key & "quoted"', { title: 'Invented nonstandard gate', required_signoffs: ['dpo', 'guidance_head'] })];
  else if (kind === 'customauthority') {
    data.authorityRoles = ['synthetic_review_board', 'dpo'];
    data.gates = [gate('synthetic_future_authorities', { required_signoffs: ['synthetic_review_board', 'dpo'],
      recorded_signoffs: { synthetic_review_board: entry(0, hostile) } }),
    gate('synthetic_authority_fallback', { required_signoffs: null })];
  } else if (kind === 'flags') {
    data.gates = [gate('synthetic_flag_two', { is_enabled: 2 }), gate('synthetic_flag_null', { is_enabled: null }),
      gate('synthetic_signed_but_disabled', { recorded_signoffs: structuredClone(enabled.recorded_signoffs) }),
      gate('synthetic_string_one', { is_enabled: '1', approved_at: stamp, recorded_signoffs: {} })];
  } else if (kind === 'normal') {
    data.signerNames[93001] = 'Synthetic Guidance Officer';
    pending.title = 'Synthetic initial review';
    pending.description = 'Initial rollout awaits explicit institutional authority review.';
    partial.title = 'Synthetic limited pilot';
    partial.recorded_signoffs.guidance_head.notes = 'Synthetic review memo RG-01.';
    enabled.title = 'Synthetic completed review';
    data.gates = [pending, partial, enabled];
  } else if (kind === 'mixed') data.gates = [pending, partial, enabled];
  else throw new Error(`Unknown synthetic fixture kind: ${kind}`);
  return data;
}
function assetFile(pathname) {
  let decoded;
  try { decoded = decodeURIComponent(pathname); } catch { return null; }
  if (!decoded.startsWith('/assets/') || /[\\\0]/.test(decoded) || decoded.split('/').some(part => part === '..' || part === '.')) return null;
  const file = path.resolve(publicRoot, '.' + decoded);
  return file.startsWith(path.join(publicRoot, 'assets') + path.sep) && MIME[path.extname(file)] ? file : null;
}
function signoffKey(pathname) {
  const match = /^\/guidance\/governance\/gates\/([^/]+)\/signoff$/.exec(pathname);
  try { return match ? decodeURIComponent(match[1]) : null; } catch { return null; }
}
function allowedRequest(origin, state, method, value) {
  let url;
  try { url = new URL(value, origin); } catch { return false; }
  if (url.origin !== origin || url.username || url.password) return false;
  if (method === 'GET') return url.pathname === gatesPath || !!assetFile(url.pathname);
  return method === 'POST' && !url.search && state.gates.has(signoffKey(url.pathname));
}
async function readFields(req, origin, pathname) {
  const chunks = []; let size = 0;
  for await (const chunk of req) { size += chunk.length; if (size > limits.bodyBytes) throw new Error('Synthetic form exceeds bounded payload limit'); chunks.push(chunk); }
  const body = Buffer.concat(chunks);
  const form = await new Request(origin + pathname, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] || '' }, body }).formData();
  const fields = Array.from(form.entries());
  assert.ok(fields.every(([, value]) => typeof value === 'string'), 'This gates fixture does not permit file uploads');
  return { form, fields };
}
async function startServer({ port = 0 } = {}) {
  assert.ok(Number.isInteger(port) && port >= 0 && port <= 65535, 'Expected a valid loopback listener port');
  const scenarios = new Map(), violations = [];
  const counts = { views: 0, posts: 0, assets: 0, rejected: 0, scenarios: 0, maxRequests: 0, maxPosts: 0 };
  let origin, sequence = 0;
  function createScenario(options = {}) {
    const defaults = fixtureData(options.kind || 'mixed');
    const state = { id: String(++sequence), theme: options.theme || 'light', csrf: 'gates-token-0', tokenSequence: 0, revision: 0,
      gates: new Map(structuredClone(options.gates ?? defaults.gates).map(row => [row.gate_key, row])),
      authorityRoles: structuredClone(options.authorityRoles ?? defaults.authorityRoles),
      signerNames: structuredClone(options.signerNames ?? defaults.signerNames), canSignOff: options.canSignOff ?? defaults.canSignOff,
      requests: [], posts: [], postStatuses: [...(options.postStatuses || [])], getStatuses: [...(options.getStatuses || [])] };
    scenarios.set(state.id, state); counts.scenarios++; return state;
  }
  function record(state, request) {
    if (state.requests.length >= limits.requests || (request.method === 'POST' && state.posts.length >= limits.posts)) throw new Error('Synthetic request ledger limit exceeded');
    state.requests.push(request);
    if (request.method === 'POST') state.posts.push(request);
    counts.maxRequests = Math.max(counts.maxRequests, state.requests.length);
    counts.maxPosts = Math.max(counts.maxPosts, state.posts.length);
  }
  function reject(message) { counts.rejected++; if (violations.length < limits.diagnostics) violations.push(message); }
  const preview = createScenario();
  const simplePreview = createScenario({ kind: 'normal' });
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin || 'http://127.0.0.1');
      const id = /(?:^|;\s*)synthetic_rollout_gates=(\d+)(?:;|$)/.exec(req.headers.cookie || '')?.[1];
      const isSimple = !id && url.searchParams.get('simple') === '1';
      const state = id ? scenarios.get(id) : isSimple ? simplePreview : preview;
      if (isSimple && req.method === 'GET' && url.pathname === gatesPath) {
        res.setHeader('Set-Cookie', `synthetic_rollout_gates=${simplePreview.id}; Path=/; HttpOnly; SameSite=Strict`);
      }
      if (!state || req.headers.host !== new URL(origin).host || !allowedRequest(origin, state, req.method, url.href)) {
        reject(`${req.method} ${req.url}`); res.writeHead(403, { 'Cache-Control': 'no-store' }).end('Only this synthetic gates fixture and local assets are allowed'); return;
      }
      const request = { method: req.method, path: url.pathname + url.search, headers: req.headers };
      if (req.method === 'POST') {
        counts.posts++;
        const { form, fields } = await readFields(req, origin, url.pathname);
        Object.assign(request, { fields }); record(state, request);
        const row = state.gates.get(signoffKey(url.pathname));
        const required = row.required_signoffs ?? state.authorityRoles;
        const fieldNames = fields.map(([name]) => name).sort();
        const csrfValid = form.get('fixture_csrf') === state.csrf && req.headers['x-fixture-csrf'] === state.csrf;
        const valid = JSON.stringify(fieldNames) === JSON.stringify(['authority_role', 'confirmed', 'fixture_csrf', 'notes'])
          && form.get('confirmed') === '1' && authorityRoles.includes(form.get('authority_role'))
          && required.includes(form.get('authority_role')) && String(form.get('notes')).length <= 1000;
        const status = !state.canSignOff || !csrfValid ? 403 : !valid ? 422 : state.postStatuses.shift() || 200;
        request.status = status;
        // Synthetic response contract rotates CSRF even for known 422/403
        // failures, exercising the actual token owner without a real session.
        state.csrf = `gates-token-${++state.tokenSequence}`;
        const payload = { success: status === 200, message: status === 422 ? 'Synthetic validation: please review the sign-off values.'
          : status === 403 ? 'Synthetic authorization: this sign-off was not permitted.' : 'Synthetic sign-off recorded.',
          csrf: { name: 'fixture_csrf', hash: state.csrf } };
        if (status === 200) {
          state.revision++;
          row.recorded_signoffs ||= {};
          row.recorded_signoffs[form.get('authority_role')] = entry(93999, String(form.get('notes')).trim(), savedStamp);
          row.is_enabled = required.every(role => row.recorded_signoffs[role] != null) ? 1 : 0;
          row.approved_at = row.is_enabled ? row.approved_at || savedStamp : null;
          payload.redirect = origin + gatesPath;
          payload.refresh = { mode: 'regions', owner, regions: ['workspace'], url: origin + gatesPath };
        }
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store',
          'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf }).end(JSON.stringify(payload)); return;
      }
      if (url.pathname === gatesPath) {
        counts.views++; record(state, request);
        const refresh = req.headers['x-synapse-refresh'] === '1';
        const status = refresh ? state.getStatuses.shift() || 200 : 200;
        request.status = status;
        if (status !== 200) { res.writeHead(status, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' }).end('Synthetic gates refresh unavailable'); return; }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf })
          .end(renderFixture({ origin, path: url.pathname + url.search, theme: url.searchParams.get('theme') || state.theme, csrf: state.csrf,
            gates: [...state.gates.values()], authorityRoles: state.authorityRoles, signerNames: state.signerNames, canSignOff: state.canSignOff })); return;
      }
      const file = assetFile(url.pathname);
      const realFile = await fs.promises.realpath(file);
      if (!realFile.startsWith(path.join(publicRoot, 'assets') + path.sep)) throw new Error('Local asset escaped fixture root');
      const bytes = await fs.promises.readFile(realFile);
      counts.assets++;
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file)], 'Cache-Control': 'no-store' }).end(bytes);
    } catch (error) {
      reject(error.stack || error.message);
      if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(error.message);
    }
  });
  server.listen(port, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  return { origin, server, scenarios, preview, violations, counts, createScenario,
    close: async () => { if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } };
}
module.exports = { startServer, renderFixture, localPlaywright, installedChromium, fixtureData, allowedRequest, gatesPath, owner, hostile, longText, stamp, savedStamp, limits };
if (require.main === module) {
  startServer({ port: Number(process.argv[2] || 0) }).then(harness => {
    console.log(`Synthetic Rollout Gates preview: ${harness.origin}${gatesPath}`);
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => harness.close().then(() => process.exit(0)));
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
