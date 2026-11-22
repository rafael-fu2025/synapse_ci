'use strict';

// Standalone synthetic server: no application bootstrap, dotenv, DB, network,
// exports, screenshots, or installation. Every mutation affects this Map only.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(__dirname, 'render-guidance-announcements-fixture.php');
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml', '.png': 'image/png' };

function localPlaywright() {
  const candidates = [path.join(root, 'tests/browser/node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')];
  const module = candidates.find(candidate => fs.existsSync(path.join(candidate, 'package.json')));
  assert.ok(module, 'Existing local Playwright is required; this fixture never installs packages');
  return require(module);
}
function installedChromium(chromium) {
  const expected = chromium.executablePath();
  if (fs.existsSync(expected)) return expected;
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const installed = fs.existsSync(cache) ? fs.readdirSync(cache)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe'].map(file => path.join(cache, name, file)))
    .find(file => fs.existsSync(file)) : null;
  assert.ok(installed, `Existing Chromium required at ${expected} or ${cache}; no downloads are performed`);
  return installed;
}
function renderFixture(options) {
  const html = execFileSync('php', ['-n', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', renderer], {
    cwd: root, input: JSON.stringify(options), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 10000, maxBuffer: 4 * 1024 * 1024,
    // Child gets no ambient application/DB variables; -n disables php.ini and
    // therefore any configured auto_prepend_file and database extensions.
    env: Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'].filter(key => process.env[key]).map(key => [key, process.env[key]])),
  });
  assert.doesNotMatch(html, /Fatal error|Warning:|Notice:|Deprecated:|Parse error|"code":\s*500/, 'Synthetic view renderer must not emit PHP errors');
  return html;
}

async function startServer({ port = 0 } = {}) {
  const scenarios = new Map();
  const violations = [];
  let origin, sequence = 0;
  function createScenario(options = {}) {
    const id = String(++sequence);
    const state = { id, theme: 'light', csrf: 'synthetic-token-0', tokenSequence: 0, revision: 0,
      overrides: {}, requests: [], posts: [], postStatuses: [], ...options };
    scenarios.set(id, state);
    return state;
  }
  const preview = createScenario();
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin || 'http://127.0.0.1');
      const id = /(?:^|;\s*)synthetic_announcements=(\d+)(?:;|$)/.exec(req.headers.cookie || '')?.[1];
      const state = scenarios.get(id) || preview;
      const isView = /^\/(guidance|clinic)\/announcements(?:\/(?:create|\d+\/edit))?$/.test(url.pathname);
      const mutation = /^\/(guidance|clinic)\/announcements\/(store|\d+\/(?:update|publish|archive))$/.exec(url.pathname);
      if (req.method === 'POST' && mutation) {
        const chunks = [];
        let size = 0;
        for await (const chunk of req) {
          size += chunk.length;
          if (size > 256 * 1024) throw new Error('Fixture payload too large');
          chunks.push(chunk);
        }
        const body = Buffer.concat(chunks);
        const form = await new Request(origin + url.pathname, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] || '' }, body }).formData();
        const fields = Array.from(form.entries());
        const record = { method: req.method, path: url.pathname, fields, headers: req.headers };
        state.posts.push(record); state.requests.push(record);
        const csrf = form.get('fixture_csrf');
        const status = csrf === state.csrf && req.headers['x-fixture-csrf'] === state.csrf ? (state.postStatuses.shift() || 200) : 403;
        state.csrf = `synthetic-token-${++state.tokenSequence}`;
        if (status === 200) {
          state.revision++;
          const noticeId = Number(/\/(\d+)\//.exec(url.pathname)?.[1] || 44);
          const old = state.overrides[noticeId] || {};
          if (url.pathname.endsWith('/publish') || url.pathname.endsWith('/archive')) {
            state.overrides[noticeId] = { ...old, status: url.pathname.endsWith('/publish') ? 'published' : 'archived',
              published_at: '2030-01-31 04:00:00', version: Number(form.get('version')) + 1 };
          } else {
            state.overrides[noticeId] = { ...old, title: form.get('title'), body: form.get('body'), audience: form.get('audience'),
              is_pinned_advisory: form.has('is_pinned_advisory') ? 1 : 0,
              target_year_levels: form.getAll('target_year_levels[]'), target_student_categories: form.getAll('target_student_categories[]'),
              version: Number(form.get('version') || 0) + 1 };
          }
        }
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store',
          'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf });
        res.end(JSON.stringify({ success: status === 200,
          message: status === 422 ? 'Synthetic validation: please review the announcement.' : status === 409 ? 'Synthetic conflict: draft changed elsewhere. Your edits are retained.' : status === 403 ? 'Synthetic CSRF rejected.' : 'Synthetic announcement change saved.',
          csrf: { name: 'fixture_csrf', hash: state.csrf }, redirect: origin + '/' + mutation[1] + '/announcements',
          refresh: { mode: 'regions', owner: 'announcements.staff.' + mutation[1], regions: ['announcements.list', 'announcements.read-history'] } }));
        return;
      }
      if (req.method !== 'GET' || (!isView && !url.pathname.startsWith('/assets/'))) {
        violations.push(`${req.method} ${url.pathname}`);
        res.writeHead(405).end('Only synthetic announcement fixtures and local assets are allowed');
        return;
      }
      if (isView) {
        state.requests.push({ method: 'GET', path: url.pathname + url.search, headers: req.headers });
        const html = renderFixture({ origin, path: url.pathname + url.search, theme: url.searchParams.get('theme') || state.theme,
          csrf: state.csrf, overrides: state.overrides, revision: state.revision });
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store',
          'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf }).end(html);
        return;
      }
      const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(publicRoot + path.sep)) { violations.push('Invalid asset path'); res.writeHead(403).end(); return; }
      const data = await fs.promises.readFile(file);
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' }).end(data);
    } catch (error) {
      violations.push(error.stack || error.message);
      if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end(error.message);
    }
  });
  server.listen(port, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  return { origin, server, violations, preview, createScenario,
    close: async () => { if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } };
}

module.exports = { startServer, renderFixture, localPlaywright, installedChromium };
if (require.main === module) {
  startServer().then(harness => {
    console.log(`Synthetic Guidance announcement preview: ${harness.origin}/guidance/announcements?reads=42`);
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => harness.close().then(() => process.exit(0)));
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
