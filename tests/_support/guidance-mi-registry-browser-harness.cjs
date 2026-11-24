'use strict';

// Actual-view, loopback-only fixture. Invented records and all writes stay in
// bounded RAM. No application/Composer/dotenv/session/database bootstrap.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
// Discovery only: do not start the rollout harness or install a browser.
const { localPlaywright, installedChromium } = require('./guidance-rollout-gates-browser-harness.cjs');
const root = path.resolve(__dirname, '../..');
const publicAssets = path.join(root, 'public/assets');
const renderer = path.join(__dirname, 'render-guidance-mi-registry-fixture.php');
const listPath = '/guidance/assessments/mi';
const assignPath = listPath + '/assign';
const cookieName = 'synthetic_mi_registry';
const programs = Object.freeze(['BS Computer Science', 'BS Nursing', 'BS Psychology']);
const statuses = Object.freeze(['assigned', 'in_progress', 'completed', 'cancelled']);
const yearLevels = Object.freeze(['1', '2', '3', '4']);
const limits = Object.freeze({ bodyBytes: 16384, rendererBytes: 2 * 1024 * 1024, inputBytes: 262144,
  requests: 40, posts: 10, scenarios: 40, diagnostics: 24, renderMs: 20000, students: 40, assignments: 200 });
const longText = 'SyntheticUnbrokenStudentReference'.repeat(7);
const hostileText = '<script>alert("synthetic")</script> & "quoted" <img src="/never-allowed-synthetic" onerror="alert(1)">';
const savedStamp = '2031-04-01 04:05:00';
const students = Object.freeze(['Aster', 'Beech', 'Cedar', 'Dawn', 'Elm', 'Forest', 'Grove', 'Hazel', 'Iris', 'Juniper', 'Kite', 'Lark']
  .map((name, index) => Object.freeze({ id: 93001 + index, first_name: `synthetic_${name.toLowerCase()}`,
    middle_name: index % 3 === 0 ? 'synthetic_middle' : '', last_name: `synthetic_fixture_${String(index + 1).padStart(2, '0')}`,
    mis_id: `SYN-STUDENT-${String(index + 1).padStart(2, '0')}`, program: [7, 11].includes(index) ? '' : programs[index % 3],
    year_level: String(Math.floor(index / 3) + 1) })));
const natural = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });
const jsNames = ['custom-dropdown', 'datepicker', 'loading', 'form-loading', 'csrf', 'toast', 'confirm', 'live-filters',
  'page-styles', 'form-modal', 'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions', 'pages/guidance-mi-registry'];
// Exact existing style.css import closure, adapted from the practitioner harness;
// no wildcard assets, core/main scripts, external files or network owners.
const cssNames = ['style', 'core/tokens', 'core/base', 'core/layout', 'core/components', 'pages/dashboard', 'pages/portal-engagement',
  'pages/guidance-case', 'pages/availability-workspace', 'core/synapse-theme', 'datepicker', 'datetimepicker', 'date-range-picker',
  'core/synapse-widgets', 'pages/profile-settings', 'pages/portal-surveys', 'pages/portal-referrals', 'pages/portal-announcements',
  'pages/portal-appointments', 'pages/portal-visits', 'pages/admin-workspace', 'pages/ai-insights', 'pages/referrals-workspace',
  'pages/clinic-reports', 'pages/clinic-isolation', 'pages/clinic-inventory', 'pages/appointments-workspace', 'pages/clinic-patients',
  'pages/clinic-encounters', 'pages/clinic-dashboard', 'navigation', 'loading', 'toast', 'teams', 'responsive-tables', 'global-search',
  'pages/guidance-mi-registry'];
const assetPaths = new Set([...jsNames.map(name => '/assets/js/' + name + '.js'), ...cssNames.map(name => '/assets/css/' + name + '.css'),
  '/assets/vendor/bootstrap/css/bootstrap.min.css', '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/bootstrap-icons.css', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff']);
const MIME = Object.freeze({ '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.woff': 'font/woff' });
const CSP = "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src 'none'; object-src 'none'";

function sortAssignments(rows) {
  return rows.sort((a, b) => Number(!String(a.program || '').trim()) - Number(!String(b.program || '').trim())
    || natural.compare(String(a.program || ''), String(b.program || '')) || b.id - a.id);
}
function allRows(data) { return data.assignments instanceof Map ? [...data.assignments.values()] : data.assignments; }
function activeIds(data) {
  return [...new Set(allRows(data).filter(row => ['assigned', 'in_progress'].includes(row.status)).map(row => row.person_id))];
}
function fixtureData({ empty = false, long = false, noStudents = false, noPrograms = false, canAssign = true, miGateEnabled = false } = {}) {
  for (const flag of [empty, long, noStudents, noPrograms, canAssign, miGateEnabled]) assert.equal(typeof flag, 'boolean', 'Synthetic presets must be boolean');
  const people = noStudents ? [] : structuredClone(students);
  if (noPrograms) for (const person of people) person.program = '';
  if (long && people.length) {
    people[0].first_name += ' ' + hostileText + ' ' + longText;
    people[0].mis_id += '-' + longText;
    people[2].last_name += '_' + longText;
  }
  let submissionSequence = 94000;
  const assignments = empty || noStudents ? [] : Array.from({ length: 60 }, (_, index) => {
    const person = people[index % people.length];
    // Historical rounds and a recent status mix leave targets for each batch mode.
    const status = index < 48 ? (Math.floor(index / 12) % 2 === 0 ? 'completed' : 'cancelled')
      : person.id === 93005 ? 'cancelled' : statuses[index % 4];
    const month = String(Math.floor(index / 12) + 1).padStart(2, '0');
    const assignedAt = `2031-${month}-01 09:30:00`;
    const completedAt = status === 'completed' ? `2031-${month}-03 10:15:00` : null;
    return { ...person, id: 95001 + index, person_id: person.id, status,
      academic_term: long && person.id === 93001 ? 'synthetic_' + 'UnbrokenTerm'.repeat(4) : `synthetic_term_${Math.floor(index / 12) + 1}`,
      academic_year: 'synthetic_2031-2032', assigned_at: assignedAt, due_at: `2031-${month}-28 23:59:00`, completed_at: completedAt,
      submission_id: status === 'completed' ? ++submissionSequence : null, submission_status: status === 'completed' ? 'finalized' : null,
      attested_at: completedAt, created_at: assignedAt, updated_at: completedAt || assignedAt, deleted_at: null };
  });
  const data = { assignments: sortAssignments(assignments), students: people,
    programs: [...new Set(people.map(person => person.program).filter(Boolean))].sort(natural.compare),
    yearLevels: [...yearLevels], canAssign, miGateEnabled };
  data.activePersonIds = activeIds(data);
  return data;
}
function validOrigin(origin) {
  try { const url = new URL(origin); return /^http:\/\/127\.0\.0\.1(?::\d{1,5})?$/.test(origin) && url.origin === origin; } catch { return false; }
}
function listQuery(url, data) {
  if (url.pathname !== listPath || url.search.length > 2048 || /%(?![0-9a-f]{2})/i.test(url.search)) return null;
  const query = Object.create(null);
  for (const [key, value] of url.searchParams) {
    if (!['program', 'status', 'per_page', 'page'].includes(key) || Object.hasOwn(query, key)) return null;
    if (key === 'program' && value !== '' && !(data.programs || programs).includes(value)) return null;
    if (key === 'status' && value !== '' && !statuses.includes(value)) return null;
    if (key === 'per_page' && !['20', '25', '50', '100'].includes(value)) return null;
    // Bounded signed integers preserve the real controller's page clamping.
    if (key === 'page' && !/^-?(?:0|[1-9]\d{0,3})$/.test(value)) return null;
    query[key] = value;
  }
  return { program: query.program || '', status: query.status || '', per_page: Number(query.per_page || 25), page: Number(query.page ?? 1) };
}
function indexData(data, value = listPath) {
  const url = value instanceof URL ? value : new URL(value, 'http://127.0.0.1');
  const query = listQuery(url, data);
  assert.ok(query, 'Only the real MI registry filters and bounded query values are allowed');
  const rows = allRows(data);
  assert.ok(Array.isArray(rows) && rows.length <= limits.assignments);
  const filtered = sortAssignments(rows.filter(row => (!query.program || row.program === query.program) && (!query.status || row.status === query.status)));
  const pages = Math.max(1, Math.ceil(filtered.length / query.per_page)), page = Math.max(1, Math.min(pages, query.page));
  return { students: data.students, programs: data.programs, yearLevels: data.yearLevels, canAssign: data.canAssign, miGateEnabled: data.miGateEnabled,
    assignments: filtered.slice((page - 1) * query.per_page, page * query.per_page),
    // Deliberately computed from ALL assignments, never the visible filtered page.
    activePersonIds: activeIds(data), statusFilter: query.status, programFilter: query.program,
    pagination: { per_page: query.per_page, filtered: filtered.length, total: rows.length, pages, page } };
}
function renderFixture(options = {}) {
  const presetKeys = ['empty', 'long', 'noStudents', 'noPrograms'];
  const renderKeys = ['origin', 'path', 'theme', 'csrf', 'regionalEnabled', 'assignments', 'students', 'programs', 'yearLevels',
    'activePersonIds', 'statusFilter', 'programFilter', 'pagination', 'miGateEnabled', 'canAssign'];
  assert.ok(options && typeof options === 'object' && !Array.isArray(options));
  assert.ok(Object.keys(options).every(key => [...presetKeys, ...renderKeys].includes(key)), 'Unknown synthetic renderer option');
  const data = { ...fixtureData(options), ...options };
  let fixturePath = options.path || listPath;
  if (!options.path && (options.statusFilter || options.programFilter)) {
    const query = new URLSearchParams();
    if (options.programFilter) query.set('program', options.programFilter);
    if (options.statusFilter) query.set('status', options.statusFilter);
    fixturePath += '?' + query.toString();
  }
  const url = new URL(fixturePath, options.origin || 'http://127.0.0.1');
  assert.ok(listQuery(url, data), 'Unknown synthetic renderer query');
  const pageData = options.pagination ? Object.fromEntries(renderKeys.filter(key => Object.hasOwn(data, key)).map(key => [key, data[key]])) : indexData(data, url);
  const input = { ...pageData, origin: options.origin || 'http://127.0.0.1', path: fixturePath,
    theme: options.theme || 'light', csrf: options.csrf || 'mi-registry-token-0', regionalEnabled: options.regionalEnabled ?? true };
  assert.ok(Buffer.byteLength(JSON.stringify(input)) <= limits.inputBytes, 'Synthetic renderer input exceeds its byte bound');
  const environment = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
    .filter(key => process.env[key]).map(key => [key, process.env[key]]));
  const result = execFileSync('php', ['-n', '-d', `open_basedir=${[path.join(root, 'app/Views'), __dirname].join(path.delimiter)}`,
    '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
    '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,mail,fsockopen,pfsockopen,stream_socket_client', renderer], {
    cwd: __dirname, env: environment, input: JSON.stringify(input), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
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
function completedDestination(state, pathname) {
  const match = /^\/guidance\/assessments\/mi\/results\/(94\d{3})(\/pdf)?$/.exec(pathname);
  if (!match || Number(match[1]) < 94001 || !state) return null;
  const row = allRows(state).find(item => item.status === 'completed' && item.submission_id === Number(match[1]));
  return row ? { row, submissionId: Number(match[1]), kind: match[2] ? 'pdf' : 'results' } : null;
}
function allowedRequest(origin, state, method, value) {
  if (!validOrigin(origin) || typeof value !== 'string' || /[\\\0\r\n]/.test(value) || /%(?![0-9a-f]{2})/i.test(value)) return false;
  let url;
  try { url = new URL(value, origin); } catch { return false; }
  if (url.origin !== origin || url.username || url.password || url.hash || /[\\\0%]/.test(url.pathname)) return false;
  if (method === 'GET') {
    if (url.pathname === listPath) return !!listQuery(url, state || { programs });
    const bundledFont = /^\/assets\/vendor\/bootstrap-icons\/fonts\/bootstrap-icons\.woff2?$/.test(url.pathname)
      && url.search === '?dd67030699838ea613ee6dbda90effa6';
    return ((!url.search || bundledFont) && !!assetFile(url.pathname)) || (!url.search && !!completedDestination(state, url.pathname));
  }
  return method === 'POST' && !url.search && url.pathname === assignPath;
}
async function readFields(req, origin, pathname) {
  assert.match(req.headers['content-type'] || '', /^(?:multipart\/form-data;|application\/x-www-form-urlencoded(?:;|$))/i,
    'Only ordinary synthetic form payloads are accepted');
  const chunks = []; let bytes = 0;
  for await (const chunk of req) {
    bytes += chunk.length;
    assert.ok(bytes <= limits.bodyBytes, 'Synthetic form exceeds its byte bound');
    chunks.push(chunk);
  }
  const form = await new Request(origin + pathname, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] }, body: Buffer.concat(chunks) }).formData();
  const fields = [...form.entries()];
  assert.ok(fields.length <= 128 && fields.every(([, value]) => typeof value === 'string' && value.length <= 2048), 'No uploads or oversized values are accepted');
  return { form, fields };
}
function validDue(value) {
  if (!value) return true;
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/.exec(value);
  if (!match || Number(match[1]) < 2000 || Number(match[1]) > 2099 || Number(match[4] || 0) > 23
      || Number(match[5] || 0) > 59 || Number(match[6] || 0) > 59 || !Number.isFinite(Date.parse(value))) return false;
  return new Date(value.slice(0, 10) + 'T00:00:00Z').toISOString().slice(0, 10) === value.slice(0, 10);
}
function validPayload(state, pathname, fields) {
  if (pathname !== assignPath || !Array.isArray(fields)) return false;
  const scalars = ['assign_scope', 'assign_all_students', 'academic_term', 'academic_year', 'due_at', 'fixture_csrf'];
  const arrays = ['person_ids[]', 'target_programs[]', 'target_year_levels[]'];
  const values = new URLSearchParams(fields);
  if (fields.some(([name, value]) => ![...scalars, ...arrays].includes(name) || typeof value !== 'string')
      || scalars.some(name => values.getAll(name).length > 1) || values.getAll('fixture_csrf').length !== 1
      || arrays.some(name => values.getAll(name).length > 40)) return false;
  const ids = values.getAll('person_ids[]');
  return (!values.has('assign_scope') || ['individual', 'cohort'].includes(values.get('assign_scope')))
    && (!values.has('assign_all_students') || ['', '0', '1', 'false', 'true', 'on'].includes(values.get('assign_all_students')))
    && ids.every(value => /^93\d{3}$/.test(value) && Number(value) >= 93001 && state.students.some(person => person.id === Number(value)))
    && values.getAll('target_programs[]').every(value => state.programs.includes(value.trim()))
    && values.getAll('target_year_levels[]').every(value => yearLevels.includes(value.trim()))
    && Buffer.byteLength(values.get('academic_term') || '') <= 64 && Buffer.byteLength(values.get('academic_year') || '') <= 32
    && validDue((values.get('due_at') || '').trim());
}
function resolveAssignTargets(state, fields) {
  const values = new URLSearchParams(fields);
  const direct = values.getAll('person_ids[]').map(Number);
  const selectedPrograms = [...new Set(values.getAll('target_programs[]').map(value => value.trim()).filter(Boolean))];
  const selectedYears = [...new Set(values.getAll('target_year_levels[]').map(value => value.trim()).filter(Boolean))];
  const all = !!values.get('assign_all_students') && !['0', 'false'].includes(values.get('assign_all_students').toLowerCase());
  const isCohortRequest = values.get('assign_scope') === 'cohort' || all || selectedPrograms.length > 0 || selectedYears.length > 0;
  const cohort = isCohortRequest && (all || selectedPrograms.length || selectedYears.length)
    ? state.students.filter(person => all || ((!selectedPrograms.length || selectedPrograms.includes(person.program))
      && (!selectedYears.length || selectedYears.includes(String(person.year_level))))).map(person => person.id) : [];
  const targetPersonIds = [...new Set([...direct, ...cohort])];
  return { targetPersonIds, isCohortRequest, allowSkipActive: isCohortRequest || targetPersonIds.length > 1 };
}
function applyMutation(state, pathname, fields, resolved = resolveAssignTargets(state, fields)) {
  assert.equal(pathname, assignPath);
  const values = new URLSearchParams(fields), active = new Set(activeIds(state)), created = [];
  let skippedActiveCount = 0;
  assert.ok(resolved.targetPersonIds.length > 0);
  assert.ok(resolved.allowSkipActive || !active.has(resolved.targetPersonIds[0]), 'Single active assignments conflict');
  const newCount = resolved.targetPersonIds.filter(id => !active.has(id)).length;
  assert.ok(state.assignments.size + newCount <= limits.assignments, 'Synthetic assignment bound exceeded');
  for (const personId of resolved.targetPersonIds) {
    if (active.has(personId)) { skippedActiveCount++; continue; }
    const person = state.students.find(student => student.id === personId);
    assert.ok(person, 'Assignments reference only known invented students');
    const row = { ...person, id: ++state.assignmentSequence, person_id: personId, status: 'assigned',
      academic_term: (values.get('academic_term') || '').trim() || null, academic_year: (values.get('academic_year') || '').trim() || null,
      due_at: (values.get('due_at') || '').trim() || null, assigned_at: savedStamp, completed_at: null,
      submission_id: null, submission_status: null, attested_at: null, created_at: savedStamp, updated_at: savedStamp, deleted_at: null };
    assert.ok(row.id >= 95001 && row.id <= 95999);
    state.assignments.set(row.id, row);
    active.add(personId); created.push(row);
  }
  if (created.length) state.revision++;
  state.activePersonIds = activeIds(state);
  state.counts.assigned += created.length; state.counts.skippedActive += skippedActiveCount;
  return { created, assigned_count: created.length, skipped_active_count: skippedActiveCount };
}
function responsePayload(state, status, origin, batch = null) {
  const payload = { success: status === 201,
    message: status === 422 ? 'Synthetic validation: select matching students and review assignment fields before retrying.'
      : status === 403 || status === 401 ? 'Synthetic permission or CSRF rejected.'
        : status === 409 ? 'Synthetic conflict: this student already has an active MI assignment.'
          : status !== 201 ? 'Synthetic assignment request unavailable; retry explicitly.'
            : `Synthetic MI survey assigned to ${batch.assigned_count} student(s) (${batch.skipped_active_count} skipped with an existing active assignment).`,
    csrf: { name: 'fixture_csrf', hash: state.csrf } };
  if (status === 201) Object.assign(payload, { assignment: batch.created[0] || null, assignments: batch.created,
    assigned_count: batch.assigned_count, skipped_active_count: batch.skipped_active_count, redirect: origin + listPath });
  // No refresh descriptor, owner, region headers or simulated MI protocol.
  return payload;
}
function createScenario(options = {}, id = '1') {
  const data = fixtureData(options);
  const theme = options.theme || 'light', delay = options.postDelayMs ?? 0;
  assert.ok(['light', 'dark'].includes(theme));
  assert.equal(typeof (options.regionalEnabled ?? true), 'boolean');
  assert.ok(Number.isInteger(delay) && delay >= 0 && delay <= 2000);
  const postStatuses = options.postStatuses || [], getStatuses = options.getStatuses || [];
  assert.ok(Array.isArray(postStatuses) && postStatuses.length <= limits.posts && postStatuses.every(status => [201, 400, 401, 403, 404, 409, 422, 500, 503].includes(status)));
  assert.ok(Array.isArray(getStatuses) && getStatuses.length <= 10 && getStatuses.every(status => [200, 403, 409, 422, 503].includes(status)));
  assert.match(String(id), /^[1-9]\d{0,3}$/);
  return { ...data, id: String(id), theme, regionalEnabled: options.regionalEnabled ?? true,
    assignments: new Map(data.assignments.map(row => [row.id, row])), csrf: 'mi-registry-token-0', tokenSequence: 0, revision: 0,
    assignmentSequence: Math.max(95000, ...data.assignments.map(row => row.id)), requests: [], posts: [], destinations: [],
    counts: { views: 0, posts: 0, results: 0, pdfs: 0, assigned: 0, skippedActive: 0 },
    postStatuses: [...postStatuses], getStatuses: [...getStatuses], postDelayMs: delay };
}
function htmlEscape(value) { return String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char])); }
function destinationHtml(destination, origin) {
  const heading = destination.kind === 'pdf' ? 'SYNTHETIC PDF TEST LANDING — HTML, NOT A PDF' : 'SYNTHETIC READ-ONLY MI RESULTS TEST PAGE';
  return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<link rel="icon" href="data:,"><title>' + heading + '</title></head><body data-navigation-scope="synthetic-guidance-mi-registry">'
    + '<div id="page-styles" hidden></div><main id="main" class="main"><div class="main-content"><h1>' + heading + '</h1>'
    + '<p><strong>SYNTHETIC TEST ONLY.</strong> Completed invented submission ' + destination.submissionId + '. No scoring, survey answers, attestation, compiler or real document issuance is performed.</p>'
    + '<p>Invented student: ' + htmlEscape(destination.row.first_name + ' ' + destination.row.last_name) + ' (' + htmlEscape(destination.row.mis_id) + ').</p>'
    + (destination.kind === 'pdf' ? '<p>This labeled HTML landing verifies the PDF link destination only. It does not claim to be a generated PDF or assessment report.</p>' : '')
    + '<a href="' + htmlEscape(origin + listPath) + '" data-navigation="false">Back to MI Registry</a></div></main><div id="page-scripts" hidden></div></body></html>';
}
async function startServer({ port = 0 } = {}) {
  assert.ok(Number.isInteger(port) && port >= 0 && port <= 65535, 'Valid loopback port required');
  const scenarios = new Map(), violations = [], counts = { views: 0, posts: 0, assets: 0, rejected: 0, results: 0, pdfs: 0 };
  let origin, sequence = 0;
  function newScenario(options = {}) {
    assert.ok(scenarios.size < limits.scenarios, 'Synthetic scenario bound exceeded');
    const state = createScenario(options, String(++sequence));
    scenarios.set(state.id, state);
    return state;
  }
  function reject(message) { counts.rejected++; if (violations.length < limits.diagnostics) violations.push(message); }
  function record(state, request) {
    assert.ok(state.requests.length < limits.requests && (request.method !== 'POST' || state.posts.length < limits.posts), 'Synthetic request ledger bound exceeded');
    state.requests.push(request);
    if (request.method === 'POST') state.posts.push(request);
  }
  const preview = newScenario();
  const server = http.createServer(async (req, res) => {
    try {
      const host = new URL(origin).host, rawPath = req.url || '', url = new URL(rawPath, origin);
      const cookies = (req.headers.cookie || '').split(';').map(value => value.trim()).filter(value => value.startsWith(cookieName + '='));
      const id = cookies.length === 1 ? cookies[0].slice(cookieName.length + 1) : null;
      const state = id ? scenarios.get(id) : preview;
      if (!state || cookies.length > 1 || req.headers.host !== host || req.socket.remoteAddress !== '127.0.0.1' || !rawPath.startsWith('/')
          || rawPath.startsWith('//') || /[\\\0\r\n#]/.test(rawPath) || /%(?![0-9a-f]{2})/i.test(rawPath)
          || rawPath.split('?')[0].split('/').some(part => part === '.' || part === '..')
          || req.headers.origin && req.headers.origin !== origin || req.headers['sec-fetch-site'] === 'cross-site'
          || !allowedRequest(origin, state, req.method, rawPath)) {
        reject(`${req.method} ${rawPath}`);
        res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' }).end('Only this synthetic loopback fixture and named local assets are allowed');
        return;
      }
      res.setHeader('Cache-Control', 'private, no-store');
      res.setHeader('X-Content-Type-Options', 'nosniff');
      if (!id) res.setHeader('Set-Cookie', `${cookieName}=${state.id}; Path=/; HttpOnly; SameSite=Strict`);
      if (req.method === 'POST') {
        counts.posts++; state.counts.posts++;
        const { fields } = await readFields(req, origin, url.pathname);
        const request = { method: 'POST', path: assignPath, headers: req.headers, fields, csrfBefore: state.csrf, revisionBefore: state.revision };
        record(state, request);
        const token = new URLSearchParams(fields).get('fixture_csrf');
        let resolved = null;
        let status = !state.canAssign || token !== state.csrf || req.headers['x-fixture-csrf'] !== state.csrf ? 403
          : !validPayload(state, assignPath, fields) ? 422 : null;
        if (status === null) {
          resolved = resolveAssignTargets(state, fields);
          request.targetPersonIds = resolved.targetPersonIds; request.isCohortRequest = resolved.isCohortRequest;
          status = !resolved.targetPersonIds.length ? 422
            : !resolved.allowSkipActive && activeIds(state).includes(resolved.targetPersonIds[0]) ? 409 : state.postStatuses.shift() || 201;
        }
        assert.ok(state.tokenSequence < 9999);
        state.csrf = `mi-registry-token-${++state.tokenSequence}`;
        const batch = status === 201 ? applyMutation(state, assignPath, fields, resolved) : null;
        request.status = status; request.csrfAfter = state.csrf; request.revision = state.revision;
        request.payload = responsePayload(state, status, origin, batch);
        if (state.postDelayMs) await new Promise(resolve => setTimeout(resolve, state.postDelayMs));
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-NAME': 'fixture_csrf',
          'X-CSRF-TOKEN': state.csrf, 'X-Fixture-CSRF': state.csrf }).end(JSON.stringify(request.payload));
        return;
      }
      if (url.pathname === listPath) {
        counts.views++; state.counts.views++;
        const request = { method: 'GET', path: listPath + url.search, pathname: listPath, query: Object.fromEntries(url.searchParams), headers: req.headers, revision: state.revision };
        record(state, request);
        const isFetch = req.headers['x-requested-with'] === 'XMLHttpRequest' || req.headers['x-synapse-refresh'] === '1' || req.headers['x-synapse-navigation'] === '1';
        const status = isFetch ? state.getStatuses.shift() || 200 : 200;
        request.status = status;
        if (status !== 200) { res.writeHead(status, { 'Content-Type': 'text/plain; charset=utf-8' }).end('Synthetic registry navigation unavailable'); return; }
        const started = Date.now();
        const html = renderFixture({ ...indexData(state, url), origin, path: listPath + url.search, theme: state.theme,
          csrf: state.csrf, regionalEnabled: state.regionalEnabled });
        request.renderMs = Date.now() - started;
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf,
          'X-Fixture-CSRF': state.csrf, 'Content-Security-Policy': CSP }).end(html);
        return;
      }
      const destination = completedDestination(state, url.pathname);
      if (destination) {
        const key = destination.kind === 'pdf' ? 'pdfs' : 'results';
        counts[key]++; state.counts[key]++;
        const request = { method: 'GET', path: url.pathname, headers: req.headers, status: 200,
          destination: destination.kind, submissionId: destination.submissionId, revision: state.revision };
        record(state, request); state.destinations.push(request);
        const headers = { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': CSP };
        if (destination.kind === 'pdf') headers['X-Synthetic-PDF'] = 'html-test-landing-not-a-pdf';
        res.writeHead(200, headers).end(destinationHtml(destination, origin));
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
  return { origin, server, preview, counts, violations, createScenario: newScenario,
    close: async () => { if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } };
}
module.exports = { startServer, renderFixture, fixtureData, allowedRequest, createScenario, localPlaywright, installedChromium,
  assetPaths, validPayload, resolveAssignTargets, applyMutation, responsePayload, indexData, sortAssignments, activeIds, completedDestination,
  listPath, assignPath, cookieName, students, programs, statuses, yearLevels, limits, longText, hostileText, savedStamp };
if (require.main === module) {
  startServer().then(harness => {
    console.log(`Synthetic Guidance MI Registry preview: ${harness.origin}${listPath}`);
    console.log('SYNTHETIC TEST ONLY: invented students/assignments; RAM-only writes; labeled read-only results/PDF test landings; no application/session/DB bootstrap.');
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => harness.close().then(() => process.exit(0)));
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
