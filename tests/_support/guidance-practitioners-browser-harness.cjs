'use strict';

// Dedicated loopback-only actual-view fixture. All accounts/records and writes
// are invented, bounded and in RAM. Never load app/Composer/dotenv/session/DB.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
// Reuse only existing local browser discovery, not a legacy server or renderer.
const { localPlaywright, installedChromium } = require('./guidance-rollout-gates-browser-harness.cjs');
const root = path.resolve(__dirname, '../..');
const publicAssets = path.join(root, 'public/assets');
const renderer = path.join(__dirname, 'render-guidance-practitioners-fixture.php');
const listPath = '/guidance/governance/practitioners';
const registerPath = listPath + '/register';
const supervisionPath = listPath + '/supervision';
const owner = 'guidance.governance.practitioners';
const cookieName = 'synthetic_practitioners';
const tiers = Object.freeze(['counselor_associate', 'licensed_counselor', 'guidance_supervisor', 'qualified_assessor']);
const scopes = Object.freeze({ intake: 'Intake & Referral Processing', counseling: 'Counseling Sessions & Notes',
  psychosocial: 'Psychosocial Support Interviews', safety_triage: 'Clinical Safety Triage & Escalation',
  assessment_administration: 'Assessment Administration', assessment_interpretation: 'Assessment Interpretation', call_slips: 'Call Slips & Appointments' });
const permissions = Object.freeze({ 'read-only': [false, false], 'credentials-only': [true, false], 'supervision-only': [false, true], both: [true, true] });
const limits = Object.freeze({ bodyBytes: 16384, rendererBytes: 2 * 1024 * 1024, rows: 40, requests: 40, posts: 10, scenarios: 40, diagnostics: 24, renderMs: 20000 });
const longText = 'SyntheticUnbrokenPractitionerReference'.repeat(7);
const hostileText = '<script>alert("synthetic")</script> & "quoted" <img src="/never-allowed-synthetic" onerror="alert(1)">';
const savedStamp = '2031-02-01 04:05:00';
const staff = Object.freeze(['Aster', 'Beech', 'Cedar', 'Dawn', 'Elm', 'Forest', '', 'Grove'].map((name, index) => Object.freeze({
  id: 93001 + index, person_id: 93801 + index, username: `synthetic_staff_${index + 1}`, first_name: name ? 'Synthetic ' + name : '',
  last_name: name ? 'Fixture' : '', mis_id: `SYN-STAFF-${index + 1}`, role: ['counsellor', 'guidance_supervisor', 'guidance_admin'][index % 3],
})));
const jsNames = ['custom-dropdown', 'datepicker', 'loading', 'form-loading', 'csrf', 'toast', 'confirm', 'live-filters', 'page-styles', 'form-modal',
  'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions'];
// Exact existing style.css import closure, not a wildcard asset directory.
const cssNames = ['style', 'core/tokens', 'core/base', 'core/layout', 'core/components', 'pages/dashboard', 'pages/portal-engagement',
  'pages/guidance-case', 'pages/availability-workspace', 'core/synapse-theme', 'datepicker', 'datetimepicker', 'date-range-picker',
  'core/synapse-widgets', 'pages/profile-settings', 'pages/portal-surveys', 'pages/portal-referrals', 'pages/portal-announcements',
  'pages/portal-appointments', 'pages/portal-visits', 'pages/admin-workspace', 'pages/ai-insights', 'pages/referrals-workspace',
  'pages/clinic-reports', 'pages/clinic-isolation', 'pages/clinic-inventory', 'pages/appointments-workspace', 'pages/clinic-patients',
  'pages/clinic-encounters', 'pages/clinic-dashboard', 'navigation', 'loading', 'toast', 'teams', 'responsive-tables', 'global-search',
  'pages/guidance-practitioners'];
const assetPaths = new Set([...jsNames.map(name => '/assets/js/' + name + '.js'), ...cssNames.map(name => '/assets/css/' + name + '.css'),
  '/assets/vendor/bootstrap/css/bootstrap.min.css', '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/bootstrap-icons.css', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff']);
const MIME = Object.freeze({ '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.woff': 'font/woff' });

function fixtureData({ empty = false, long = false, permission = 'both', noStaff = false, paging = false } = {}) {
  assert.ok(Object.hasOwn(permissions, permission), 'Unknown synthetic permission fixture');
  const users = structuredClone(staff);
  if (paging) {
    for (let index = users.length; index < 34; ++index) users.push({
      id: 93001 + index, person_id: 93801 + index, username: `synthetic_staff_${index + 1}`,
      first_name: `Synthetic Registry ${String(index + 1).padStart(2, '0')}`, last_name: 'Fixture',
      mis_id: `SYN-STAFF-${index + 1}`, role: index % 2 ? 'counsellor' : 'guidance_supervisor',
    });
  }
  if (long) { users[5].first_name += ' ' + hostileText + ' ' + longText; users[5].username += '_' + longText; }
  const standings = ['active', 'suspended', 'lapsed', 'active', 'active', 'synthetic_unknown_standing', 'inactive'];
  const profileTiers = [tiers[0], tiers[1], tiers[2], tiers[3], tiers[1], 'synthetic_future_tier', tiers[0]];
  const profiles = empty ? [] : users.slice(0, paging ? 32 : 7).map((user, index) => ({ ...user, id: 94001 + index, user_id: user.id, user_role: user.role,
    credential_tier: profileTiers[index] || tiers[index % tiers.length], professional_standing: standings[index] || 'active', is_active: [3, 5, 6].includes(index) ? 0 : 1,
    license_number: index === 0 || index === 6 ? null : index === 5 && long ? 'SYN-' + longText : `SYN-LICENSE-${index + 1}`,
    credential_expiry: index === 4 ? '2000-01-01' : index === 5 ? 'synthetic-unknown-date' : index === 6 || index === 0 ? null : '2099-12-31',
    service_scope: index === 6 ? [] : index === 5 ? ['synthetic_future_scope' + (long ? '_' + longText : '')] : [Object.keys(scopes)[index % Object.keys(scopes).length], 'call_slips'],
  }));
  const assignments = [{ id: 95001, associate_user_id: 93001, supervisor_user_id: 93003, co_sign_required: 1, starts_at: '2026-01-10 09:30:00', status: 'active', ends_at: null },
    { id: 95002, associate_user_id: 93004, supervisor_user_id: 93002, co_sign_required: 0, starts_at: null, status: 'active', ends_at: null }];
  if (paging) {
    for (const user of users.slice(1, 33).filter(user => ![93003, 93004].includes(user.id))) assignments.push({
      id: 95001 + assignments.length, associate_user_id: user.id, supervisor_user_id: 93003,
      co_sign_required: assignments.length % 2, starts_at: '2026-01-10 09:30:00', status: 'active', ends_at: null,
    });
  }
  const supervisions = empty ? [] : sortSupervisions(assignments.map(row => joinAssignment(row, users)));
  return { profiles: sortProfiles(profiles), supervisions, guidanceUsers: noStaff ? [] : users, tiers: [...tiers], availableScopes: { ...scopes },
    canManagePractitioners: permissions[permission][0], canReassign: permissions[permission][1] };
}
function sortProfiles(rows) {
  return rows.sort((a, b) => b.is_active - a.is_active || a.credential_tier.localeCompare(b.credential_tier)
    || a.username.localeCompare(b.username) || a.id - b.id);
}
function sortSupervisions(rows) {
  return rows.sort((a, b) => String(b.starts_at || '').localeCompare(String(a.starts_at || '')) || b.id - a.id);
}
function joinAssignment(row, users) {
  const associate = users.find(user => user.id === row.associate_user_id), supervisor = users.find(user => user.id === row.supervisor_user_id);
  assert.ok(associate && supervisor, 'Assignments reference only invented fixture staff');
  return { ...row, associate_username: associate.username, associate_first_name: associate.first_name, associate_last_name: associate.last_name,
    supervisor_username: supervisor.username, supervisor_first_name: supervisor.first_name, supervisor_last_name: supervisor.last_name };
}
function renderFixture(options = {}) {
  const environment = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
    .filter(key => process.env[key]).map(key => [key, process.env[key]]));
  const result = execFileSync('php', ['-n', '-d', `open_basedir=${[path.join(root, 'app/Views'), __dirname].join(path.delimiter)}`,
    '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
    '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,mail,fsockopen,pfsockopen,stream_socket_client', renderer], {
    cwd: __dirname, env: environment, input: JSON.stringify(options), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
    timeout: limits.renderMs, maxBuffer: limits.rendererBytes,
  });
  assert.doesNotMatch(result, /Fatal error|Warning:|Notice:|Deprecated:|Parse error/, 'Isolated actual view must not emit PHP diagnostics');
  return result;
}
function assetFile(pathname) {
  if (!assetPaths.has(pathname) || /[\\\0%]/.test(pathname)) return null;
  const file = path.resolve(root, 'public', '.' + pathname);
  return file.startsWith(publicAssets + path.sep) && MIME[path.extname(file)] ? file : null;
}
function allowedRequest(origin, state, method, value) {
  let url;
  try { url = new URL(value, origin); } catch { return false; }
  if (!/^http:\/\/127\.0\.0\.1(?::\d+)?$/.test(origin) || url.origin !== origin || url.username || url.password || url.hash) return false;
  if (method === 'GET') {
    if (url.pathname !== listPath) {
      const iconFont = ['/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff'].includes(url.pathname);
      return !!assetFile(url.pathname) && (!url.search || iconFont && url.search === '?dd67030699838ea613ee6dbda90effa6');
    }
    const names = new Set(['profiles_page', 'supervision_page', 'per_page']);
    if ([...url.searchParams.keys()].some(name => !names.has(name) || url.searchParams.getAll(name).length !== 1)) return false;
    for (const name of ['profiles_page', 'supervision_page']) {
      const value = url.searchParams.get(name);
      if (value !== null && (!/^[1-9]\d{0,6}$/.test(value) || Number(value) > 1000000)) return false;
    }
    return !url.searchParams.has('per_page') || ['20', '25', '50', '100'].includes(url.searchParams.get('per_page'));
  }
  return method === 'POST' && !url.search && [registerPath, supervisionPath].includes(url.pathname);
}
async function readFields(req, origin, pathname) {
  assert.match(req.headers['content-type'] || '', /^(?:multipart\/form-data;|application\/x-www-form-urlencoded(?:;|$))/i, 'Only ordinary synthetic form payloads are accepted');
  const chunks = []; let bytes = 0;
  for await (const chunk of req) {
    bytes += chunk.length;
    assert.ok(bytes <= limits.bodyBytes, 'Synthetic form exceeds its byte bound');
    chunks.push(chunk);
  }
  const form = await new Request(origin + pathname, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] }, body: Buffer.concat(chunks) }).formData();
  const fields = [...form.entries()];
  assert.ok(fields.every(([, value]) => typeof value === 'string' && value.length <= 2048), 'No uploads or oversized values are accepted');
  return { form, fields };
}
function validPayload(state, pathname, fields) {
  const names = fields.map(([name]) => name), values = new URLSearchParams(fields);
  const repeated = pathname === registerPath ? 'service_scope[]' : 'co_sign_required';
  const required = pathname === registerPath ? ['fixture_csrf', 'user_id', 'credential_tier', 'license_number', 'credential_expiry']
    : ['fixture_csrf', 'associate_user_id', 'supervisor_user_id', 'co_sign_required'];
  if (names.some(name => ![...required, repeated].includes(name)) || required.some(name => !names.includes(name))
      || names.some(name => name !== repeated && values.getAll(name).length !== 1)) return false;
  const userExists = name => state.guidanceUsers.some(user => String(user.id) === values.get(name));
  if (pathname === registerPath) {
    const expiry = values.get('credential_expiry');
    return userExists('user_id') && tiers.includes(values.get('credential_tier'))
      && values.get('license_number').length <= 64 && (!values.get('license_number') || values.get('license_number').trim().startsWith('SYN-'))
      && (!expiry || /^\d{4}-\d{2}-\d{2}$/.test(expiry) && !Number.isNaN(Date.parse(expiry)) && new Date(expiry).toISOString().slice(0, 10) === expiry)
      && values.getAll('service_scope[]').every(scope => Object.hasOwn(scopes, scope))
      && new Set(values.getAll('service_scope[]')).size === values.getAll('service_scope[]').length;
  }
  // PHP getPost returns the last successful value: unchecked -> [0], checked -> [0,1].
  const coSign = values.getAll('co_sign_required');
  return userExists('associate_user_id') && userExists('supervisor_user_id') && values.get('associate_user_id') !== values.get('supervisor_user_id')
    && (JSON.stringify(coSign) === '["0"]' || JSON.stringify(coSign) === '["0","1"]');
}
function applyMutation(state, pathname, fields) {
  const values = new URLSearchParams(fields);
  if (pathname === registerPath) {
    const userId = Number(values.get('user_id')), user = state.guidanceUsers.find(user => user.id === userId), existing = state.profiles.get(userId);
    const serviceScope = [...new Set(values.getAll('service_scope[]').map(value => value.trim()).filter(Boolean))];
    state.profiles.set(userId, { ...user, ...existing, user_id: userId, id: existing?.id || ++state.profileSequence, user_role: user.role,
      credential_tier: values.get('credential_tier'), license_number: values.get('license_number').trim() || null,
      credential_expiry: values.get('credential_expiry').trim() || null, service_scope: serviceScope, service_scope_json: JSON.stringify(serviceScope),
      professional_standing: 'active', is_active: 1, updated_at: savedStamp });
    assert.ok(state.profiles.size <= limits.rows, 'Synthetic practitioner profile bound exceeded');
  } else {
    const associateId = Number(values.get('associate_user_id'));
    for (const row of state.assignments.values()) if (row.associate_user_id === associateId && row.status === 'active') {
      row.status = 'completed'; row.ends_at = savedStamp;
    }
    assert.ok(state.assignments.size < limits.rows, 'Synthetic supervision fixture bound exceeded');
    const row = joinAssignment({ id: ++state.assignmentSequence, associate_user_id: associateId, supervisor_user_id: Number(values.get('supervisor_user_id')),
      co_sign_required: values.getAll('co_sign_required').at(-1) === '1' ? 1 : 0, status: 'active', starts_at: savedStamp, ends_at: null, created_at: savedStamp }, state.guidanceUsers);
    state.assignments.set(row.id, row);
  }
  state.revision++;
}
function responsePayload(state, status, pathname, origin) {
  const payload = { success: status === 200, message: status === 422 ? 'Synthetic validation: review the practitioner form and retry explicitly.'
    : status === 403 ? 'Synthetic permission or CSRF rejected.' : status === 409 ? 'Synthetic conflict: retry explicitly.'
      : pathname === registerPath ? 'Synthetic practitioner credential profile saved.' : 'Synthetic supervision assignment updated.',
    csrf: { name: 'fixture_csrf', hash: state.csrf } };
  if (status === 200) {
    payload.redirect = origin + listPath;
    if (state.regionalEnabled) payload.refresh = { mode: 'regions', owner, regions: ['workspace'], url: origin + listPath };
  }
  return payload;
}
async function startServer({ port = 0 } = {}) {
  assert.ok(Number.isInteger(port) && port >= 0 && port <= 65535, 'Valid loopback port required');
  const scenarios = new Map(), violations = [], counts = { views: 0, posts: 0, assets: 0, rejected: 0 };
  let origin, sequence = 0;
  function createScenario(options = {}) {
    assert.ok(scenarios.size < limits.scenarios, 'Synthetic scenario bound exceeded');
    const data = fixtureData(options);
    assert.ok(['light', 'dark'].includes(options.theme || 'light'));
    assert.ok((options.postDelayMs || 0) >= 0 && (options.postDelayMs || 0) <= 2000);
    for (const statuses of [options.postStatuses || [], options.getStatuses || []]) assert.ok(statuses.length <= 10 && statuses.every(status => [200, 403, 409, 422, 503].includes(status)));
    const state = { ...data, id: String(++sequence), theme: options.theme || 'light', regionalEnabled: options.regionalEnabled ?? true,
      profiles: new Map(data.profiles.map(row => [row.user_id, row])), assignments: new Map(data.supervisions.map(row => [row.id, row])),
      csrf: 'practitioners-token-0', tokenSequence: 0, revision: 0,
      profileSequence: Math.max(94010, ...data.profiles.map(row => row.id)), assignmentSequence: Math.max(95010, ...data.supervisions.map(row => row.id)),
      requests: [], posts: [], postStatuses: [...(options.postStatuses || [])], getStatuses: [...(options.getStatuses || [])], postDelayMs: options.postDelayMs || 0 };
    delete state.supervisions;
    scenarios.set(state.id, state);
    return state;
  }
  function reject(message) { counts.rejected++; if (violations.length < limits.diagnostics) violations.push(message); }
  function record(state, request) {
    assert.ok(state.requests.length < limits.requests && (request.method !== 'POST' || state.posts.length < limits.posts), 'Synthetic request ledger bound exceeded');
    state.requests.push(request);
    if (request.method === 'POST') state.posts.push(request);
  }
  const preview = createScenario();
  const server = http.createServer(async (req, res) => {
    try {
      const host = new URL(origin).host;
      const remote = req.socket.remoteAddress;
      const rawPath = req.url || '';
      const url = new URL(rawPath, origin);
      const cookies = (req.headers.cookie || '').split(';').map(value => value.trim()).filter(value => value.startsWith(cookieName + '='));
      const id = cookies.length === 1 ? cookies[0].slice(cookieName.length + 1) : null;
      const state = id ? scenarios.get(id) : preview;
      if (!state || cookies.length > 1 || req.headers.host !== host || remote !== '127.0.0.1' || !rawPath.startsWith('/')
          || /[\\\0%]/.test(rawPath) || rawPath.split(/[/?]/).some(part => part === '.' || part === '..')
          || req.headers.origin && req.headers.origin !== origin || req.headers['sec-fetch-site'] === 'cross-site'
          || !allowedRequest(origin, state, req.method, url.href)) {
        reject(`${req.method} ${rawPath}`);
        res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' }).end('Only this synthetic loopback fixture and named local assets are allowed');
        return;
      }
      res.setHeader('Cache-Control', 'private, no-store');
      res.setHeader('X-Content-Type-Options', 'nosniff');
      if (req.method === 'POST') {
        counts.posts++;
        const { fields } = await readFields(req, origin, url.pathname);
        const request = { method: 'POST', path: url.pathname, headers: req.headers, fields };
        record(state, request);
        const token = new URLSearchParams(fields).get('fixture_csrf');
        const permission = url.pathname === registerPath ? state.canManagePractitioners : state.canReassign;
        const status = !permission || token !== state.csrf || req.headers['x-fixture-csrf'] !== state.csrf ? 403
          : !validPayload(state, url.pathname, fields) ? 422 : state.postStatuses.shift() || 200;
        request.status = status;
        state.csrf = `practitioners-token-${++state.tokenSequence}`;
        if (status === 200) applyMutation(state, url.pathname, fields);
        request.payload = responsePayload(state, status, url.pathname, origin);
        if (state.postDelayMs) await new Promise(resolve => setTimeout(resolve, state.postDelayMs));
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf }).end(JSON.stringify(request.payload));
        return;
      }
      if (url.pathname === listPath) {
        counts.views++;
        const request = { method: 'GET', path: url.pathname + url.search, headers: req.headers, revision: state.revision };
        record(state, request);
        const isFetch = req.headers['x-requested-with'] === 'XMLHttpRequest' || req.headers['x-synapse-refresh'] === '1' || req.headers['x-synapse-navigation'] === '1';
        const status = isFetch ? state.getStatuses.shift() || 200 : 200;
        request.status = status;
        if (status !== 200) { res.writeHead(status, { 'Content-Type': 'text/plain; charset=utf-8' }).end('Synthetic workspace refresh unavailable'); return; }
        if (!id) res.setHeader('Set-Cookie', `${cookieName}=${state.id}; Path=/; HttpOnly; SameSite=Strict`);
        const started = Date.now();
        const html = renderFixture({ origin, path: url.pathname + url.search, theme: state.theme, csrf: state.csrf, regionalEnabled: state.regionalEnabled,
          profiles: sortProfiles([...state.profiles.values()]), supervisions: sortSupervisions([...state.assignments.values()]), guidanceUsers: state.guidanceUsers,
          tiers: state.tiers, availableScopes: state.availableScopes, canManagePractitioners: state.canManagePractitioners, canReassign: state.canReassign });
        request.renderMs = Date.now() - started;
        if (request.renderMs > 2000) console.log(`Synthetic practitioner renderer: ${request.renderMs}ms`);
        // Inline scripts are fixture controls/observations and actual page sections only.
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf,
          'Content-Security-Policy': "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src 'none'; object-src 'none'" }).end(html);
        return;
      }
      const file = assetFile(url.pathname), real = await fs.promises.realpath(file);
      assert.ok(real.startsWith(publicAssets + path.sep), 'Named asset escaped the local root');
      const bytes = await fs.promises.readFile(real);
      counts.assets++;
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] }).end(bytes);
    } catch (error) {
      reject(error.message);
      if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end('Synthetic fixture failed closed.');
      console.error(error.stack || error.message);
    }
  });
  server.requestTimeout = 30000;
  server.headersTimeout = 10000;
  server.listen(port, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  return { origin, server, preview, counts, violations, createScenario,
    close: async () => { if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } };
}
module.exports = { startServer, renderFixture, localPlaywright, installedChromium, allowedRequest, assetPaths, fixtureData,
  validPayload, applyMutation, responsePayload, listPath, registerPath, supervisionPath, owner, cookieName, tiers, scopes, staff, permissions, limits, longText, hostileText, savedStamp };
if (require.main === module) {
  startServer().then(harness => {
    console.log(`Synthetic Guidance Practitioners preview: ${harness.origin}${listPath}`);
    console.log('SYNTHETIC TEST ONLY: invented staff/credentials/supervision; RAM-only writes; no application/session/DB bootstrap.');
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => harness.close().then(() => process.exit(0)));
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
