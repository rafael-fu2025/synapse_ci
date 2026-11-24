'use strict';

// Dedicated loopback fixture. Only the actual Call Slips view/local assets are
// read; all client directory/results/writes are synthetic, bounded and in RAM.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const { localPlaywright, installedChromium } = require('./guidance-rollout-gates-browser-harness.cjs');
const root = path.resolve(__dirname, '../..');
const publicAssets = path.join(root, 'public/assets');
const renderer = path.join(__dirname, 'render-guidance-callslips-fixture.php');
const listPath = '/guidance/call-slips';
const lookupPath = '/api/patients/search';
const owner = 'guidance.call-slips';
const statuses = Object.freeze(['pending', 'acknowledged', 'attended', 'missed', 'cancelled']);
const purposes = Object.freeze(['personal_counseling', 'career_counseling', 'routine_interview', 'follow_up', 'academic_counseling', 'others']);
const limits = Object.freeze({ bodyBytes: 16384, rendererBytes: 2 * 1024 * 1024, rows: 128, requests: 48, posts: 12, scenarios: 40, diagnostics: 24 });
const MIME = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.woff': 'font/woff',
  '.ttf': 'font/ttf', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const people = Object.freeze([
  { id: 93002, first_name: 'Synthetic Mateo', last_name: 'Cruz', mis_id: 'SYN-2025-00456', kind: 'student' },
  { id: 93001, first_name: 'Synthetic Elena', last_name: 'Reyes', mis_id: 'SYN-2024-00123', kind: 'student' },
]);
const longText = 'SyntheticUnbrokenClientProgramAndPurpose'.repeat(12);
const hostileText = '<script>alert("synthetic")</script> & "quoted" <img src="https://invalid.example/callslips" onerror="alert(1)">';

function sortSlips(rows) {
  return rows.sort((a, b) => String(b.call_date || '').localeCompare(String(a.call_date || ''))
    || String(b.call_time || '').localeCompare(String(a.call_time || '')) || b.id - a.id);
}
function fixtureData({ empty = false, long = false, paging = false } = {}) {
  if (empty) return [];
  const count = paging ? 113 : purposes.length;
  return sortSlips(Array.from({ length: count }, (_, index) => {
    const purpose = purposes[index % purposes.length];
    const person = people[index % people.length];
    const id = paging ? 93200 - index : 41 - index;
    return { id, call_slip_number: `CS-2026-${String(id).padStart(4, '0')}`, form_instance_number: `SYN-GCS-${id}`, episode_id: index === 0 ? 914 : null,
      person_id: person.id, first_name: index === 0 && long ? person.first_name + ' ' + longText : person.first_name,
      last_name: person.last_name, mis_id: person.mis_id, program: index === 0 && long ? longText : index % 2 ? 'BS Nursing' : 'BS Computer Science',
      year_level: String(index % 4 + 1), parent_guardian_delivery: index === 0 ? 1 : 0,
      call_date: '2030-01-31', call_time: '14:00:00', purpose,
      purpose_other: purpose === 'others' ? (long ? hostileText + ' ' + longText : 'Synthetic adviser coordination only') : null,
      attendance_status: statuses[index % statuses.length], acknowledged_at: index === 1 ? '2030-01-30 10:15:00' : null };
  }));
}
function renderFixture(options) {
  const environment = Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE']
    .filter(key => process.env[key]).map(key => [key, process.env[key]]));
  const openBasedir = [path.join(root, 'app/Views'), __dirname].join(path.delimiter);
  const result = execFileSync('php', ['-n', '-d', `open_basedir=${openBasedir}`, '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
    '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1',
    '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,mail,fsockopen,pfsockopen,stream_socket_client', renderer], {
    cwd: __dirname, env: environment, input: JSON.stringify(options), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000, maxBuffer: limits.rendererBytes,
  });
  assert.doesNotMatch(result, /Fatal error|Warning:|Notice:|Deprecated:|Parse error|"code":\s*500/, 'Actual Call Slips renderer must not emit PHP diagnostics');
  return result;
}
function assetFile(pathname) {
  let decoded;
  try { decoded = decodeURIComponent(pathname); } catch { return null; }
  if (!decoded.startsWith('/assets/') || /[\\\0]/.test(decoded) || decoded.split('/').some(part => part === '.' || part === '..')) return null;
  const file = path.resolve(root, 'public', '.' + decoded);
  return file.startsWith(publicAssets + path.sep) && MIME[path.extname(file).toLowerCase()] ? file : null;
}
function statusId(pathname) {
  return Number(/^\/guidance\/call-slips\/(\d+)\/status$/.exec(pathname)?.[1] || 0);
}
function pdfOptions(state, url) {
  const id = Number(/^\/guidance\/call-slips\/(\d+)\/pdf$/.exec(url.pathname)?.[1] || 0);
  const layout = url.searchParams.get('layout'), mode = url.searchParams.get('mode');
  if (!state.pdfFixtures || !state.slips.has(id) || !['single', 'quad'].includes(layout) || !['preview', 'issue'].includes(mode)
      || [...url.searchParams.keys()].some(name => !['layout', 'mode'].includes(name))
      || url.searchParams.getAll('layout').length !== 1 || url.searchParams.getAll('mode').length !== 1) return null;
  return { id, layout, mode };
}
function renderPdfFixture(slip, { layout, mode }) {
  const escape = value => String(value).replace(/[^\x20-\x7e]/g, '?').replace(/[\\()]/g, '\\$&');
  const text = (x, y, size, value) => `BT /F1 ${size} Tf 1 0 0 1 ${x} ${y} Tm (${escape(value)}) Tj ET`;
  const content = [text(28, 809, 16, 'SYNTHETIC GUIDANCE CALL SLIP'),
    text(28, 789, 10, `${layout === 'quad' ? '4-Up Tear-Off Sheet' : 'Single A4'} - ${mode === 'issue' ? 'Official print button test' : 'Preview button test'}`),
    text(28, 770, 9, 'TEST ONLY: no official document, signature, or issuance record is created.')];
  const cells = layout === 'quad' ? [[28, 414, 258, 342], [309, 414, 258, 342], [28, 48, 258, 342], [309, 48, 258, 342]] : [[28, 48, 539, 708]];
  for (const [index, [x, y, width, height]] of cells.entries()) {
    content.push(`${x} ${y} ${width} ${height} re S`);
    let lineY = y + height - 25;
    for (const line of ['SYNTHETIC TEST COPY - NOT OFFICIAL', `Call slip: ${slip.call_slip_number}`, `Client: ${slip.first_name} ${slip.last_name}`,
      `MIS ID: ${slip.mis_id}`, `Date: ${slip.call_date}`, `Time: ${slip.call_time}`, `Purpose: ${slip.purpose.replaceAll('_', ' ')}`,
      layout === 'quad' ? `Tear-off copy ${index + 1} of 4` : 'Single A4 sample']) {
      const words = line.match(/.{1,40}/g) || [''];
      for (const word of words) { content.push(text(x + 12, lineY, 9, word)); lineY -= 16; }
    }
  }
  const stream = content.join('\n');
  const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', `<< /Length ${Buffer.byteLength(stream, 'ascii')} >>\nstream\n${stream}\nendstream`,
    `<< /Title (${escape(`Synthetic Call Slip - ${layout} - ${mode}`)}) >>`];
  let pdf = '%PDF-1.4\n';
  const offsets = [0];
  objects.forEach((object, index) => { offsets.push(Buffer.byteLength(pdf, 'ascii')); pdf += `${index + 1} 0 obj\n${object}\nendobj\n`; });
  const xref = Buffer.byteLength(pdf, 'ascii');
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  for (const offset of offsets.slice(1)) pdf += `${String(offset).padStart(10, '0')} 00000 n \n`;
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R /Info 6 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(pdf, 'ascii');
}
function allowedRequest(origin, state, method, value) {
  let url;
  try { url = new URL(value, origin); } catch { return false; }
  if (url.origin !== origin || url.username || url.password) return false;
  if (method === 'GET') {
    if (url.pathname === listPath) {
      const names = new Set(['page', 'per_page', 'status', 'person_id', 'episode_id', 'empty']);
      if ([...url.searchParams.keys()].some(name => !names.has(name) || url.searchParams.getAll(name).length !== 1)) return false;
      for (const name of ['page', 'person_id', 'episode_id']) {
        const value = url.searchParams.get(name);
        if (value !== null && (!/^[1-9]\d{0,6}$/.test(value) || Number(value) > 1000000)) return false;
      }
      return (!url.searchParams.has('per_page') || ['20', '25', '50', '100'].includes(url.searchParams.get('per_page')))
        && (!url.searchParams.has('status') || ['', ...statuses].includes(url.searchParams.get('status')))
        && (!url.searchParams.has('empty') || url.searchParams.get('empty') === '1');
    }
    return url.pathname === lookupPath || !!assetFile(url.pathname) || !!pdfOptions(state, url);
  }
  return method === 'POST' && !url.search && (url.pathname === listPath + '/issue' || state.slips.has(statusId(url.pathname)));
}
async function readFields(req, origin, pathname) {
  const chunks = []; let bytes = 0;
  for await (const chunk of req) {
    bytes += chunk.length;
    if (bytes > limits.bodyBytes) throw new Error('Synthetic Call Slips payload exceeds its bound');
    chunks.push(chunk);
  }
  const form = await new Request(origin + pathname, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] || '' }, body: Buffer.concat(chunks) }).formData();
  const fields = Array.from(form.entries());
  assert.ok(fields.every(([, value]) => typeof value === 'string'), 'This fixture never accepts file uploads');
  return { form, fields };
}
function validateIssue(form, fields) {
  const allowed = new Set(['fixture_csrf', 'person_id', 'episode_id', 'call_date', 'call_time', 'purpose', 'purpose_other', 'parent_guardian_delivery', 'delivery_notes']);
  const names = fields.map(([name]) => name);
  return names.every(name => allowed.has(name)) && new Set(names).size === names.length
    && people.some(person => String(person.id) === form.get('person_id'))
    && /^\d{4}-\d{2}-\d{2}$/.test(form.get('call_date') || '')
    && /^([01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(form.get('call_time') || '')
    && purposes.includes(form.get('purpose')) && (form.get('purpose') !== 'others' || !!String(form.get('purpose_other') || '').trim())
    && String(form.get('purpose_other') || '').length <= 255 && String(form.get('delivery_notes') || '').length <= 500
    && (!form.get('episode_id') || /^[1-9]\d*$/.test(form.get('episode_id')))
    && (!form.has('parent_guardian_delivery') || form.get('parent_guardian_delivery') === '1');
}
async function startServer({ port = 0 } = {}) {
  assert.ok(Number.isInteger(port) && port >= 0 && port <= 65535);
  const scenarios = new Map(), violations = [];
  const counts = { views: 0, lookups: 0, posts: 0, assets: 0, rejected: 0 };
  let origin, sequence = 0;
  function createScenario(options = {}) {
    assert.ok(scenarios.size < limits.scenarios, 'Synthetic scenario bound exceeded');
    const rows = sortSlips(structuredClone(options.callSlips ?? fixtureData(options)));
    assert.ok(rows.length <= limits.rows && new Set(rows.map(row => row.id)).size === rows.length, 'Synthetic Call Slip row bound exceeded');
    const state = { id: String(++sequence), theme: options.theme || 'light', regionalEnabled: options.regionalEnabled ?? true,
      slips: new Map(rows.map(row => [row.id, row])), slipSequence: Math.max(42, ...rows.map(row => row.id)),
      pdfFixtures: options.pdfFixtures === true, csrf: 'callslips-token-0', tokenSequence: 0,
      requests: [], posts: [], postStatuses: [...(options.postStatuses || [])], getStatuses: [...(options.getStatuses || [])],
      lookupStatuses: [...(options.lookupStatuses || [])], postDelayMs: options.postDelayMs ?? 0 };
    scenarios.set(state.id, state);
    return state;
  }
  function record(state, request) {
    assert.ok(state.requests.length < limits.requests, 'Synthetic request ledger bound exceeded');
    state.requests.push(request);
    if (request.method === 'POST') {
      assert.ok(state.posts.length < limits.posts, 'Synthetic POST ledger bound exceeded');
      state.posts.push(request);
    }
  }
  function reject(message) {
    counts.rejected++;
    if (violations.length < limits.diagnostics) violations.push(message);
  }
  const preview = createScenario({ pdfFixtures: true });
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin || 'http://127.0.0.1');
      const id = /(?:^|;\s*)synthetic_callslips=(\d+)(?:;|$)/.exec(req.headers.cookie || '')?.[1];
      const state = id ? scenarios.get(id) : preview;
      if (!state || req.headers.host !== new URL(origin).host || !allowedRequest(origin, state, req.method, url.href)) {
        reject(`${req.method} ${req.url}`);
        res.writeHead(403, { 'Cache-Control': 'no-store' }).end('Only synthetic Call Slips/local assets are allowed');
        return;
      }
      const request = { method: req.method, path: url.pathname + url.search, headers: req.headers };
      const pdf = req.method === 'GET' && pdfOptions(state, url);
      if (pdf) {
        request.status = 200;
        record(state, request);
        const bytes = renderPdfFixture(state.slips.get(pdf.id), pdf);
        res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Length': bytes.length,
          'Content-Disposition': `inline; filename="synthetic-call-slip-${pdf.id}-${pdf.layout}-${pdf.mode}.pdf"`,
          'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff', 'X-Synthetic-PDF': '1' }).end(bytes);
        return;
      }
      if (req.method === 'POST') {
        counts.posts++;
        const { form, fields } = await readFields(req, origin, url.pathname);
        Object.assign(request, { fields }); record(state, request);
        const isIssue = url.pathname === listPath + '/issue';
        const csrfValid = form.get('fixture_csrf') === state.csrf && req.headers['x-fixture-csrf'] === state.csrf;
        const valid = isIssue ? validateIssue(form, fields)
          : fields.length === 2 && form.has('fixture_csrf') && statuses.includes(form.get('attendance_status'));
        const status = !csrfValid ? 403 : !valid ? 422 : state.postStatuses.shift() || 200;
        request.status = status;
        state.csrf = `callslips-token-${++state.tokenSequence}`;
        if (status === 200) {
          if (isIssue) {
            const person = people.find(person => String(person.id) === form.get('person_id'));
            assert.ok(state.slips.size < limits.rows, 'Synthetic Call Slip row bound exceeded');
            const id = ++state.slipSequence;
            state.slips.set(id, { ...person, id, person_id: person.id, call_slip_number: `SYN-CS-${id}`, form_instance_number: `SYN-GCS-${id}`,
              episode_id: Number(form.get('episode_id')) || null, call_date: form.get('call_date'), call_time: form.get('call_time'),
              purpose: form.get('purpose'), purpose_other: form.get('purpose_other'), attendance_status: 'pending',
              parent_guardian_delivery: form.has('parent_guardian_delivery') ? 1 : 0, delivery_notes: form.get('delivery_notes') });
          } else {
            const slip = state.slips.get(statusId(url.pathname));
            slip.attendance_status = form.get('attendance_status');
            if (['acknowledged', 'attended'].includes(slip.attendance_status)) slip.acknowledged_at ||= '2030-01-31 10:15:00';
          }
        }
        if (state.postDelayMs) await new Promise(resolve => setTimeout(resolve, state.postDelayMs));
        const payload = { success: status === 200,
          message: status === 422 ? 'Synthetic validation: please review Call Slips values.' : status === 409 ? 'Synthetic conflict: please retry explicitly.'
            : status === 403 ? 'Synthetic CSRF rejected.' : isIssue ? 'Synthetic call slip saved.' : 'Synthetic attendance saved.',
          csrf: { name: 'fixture_csrf', hash: state.csrf } };
        if (status === 200) payload.redirect = origin + listPath;
        // Match actual controller JSON: no fabricated descriptor. The actual row
        // form supplies its opt-in descriptor; Issue follows existing navigation.
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store',
          'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf }).end(JSON.stringify(payload));
        return;
      }
      if (url.pathname === lookupPath) {
        counts.lookups++; record(state, request);
        const q = (url.searchParams.get('q') || '').slice(0, 120).trim().toLowerCase();
        const status = state.lookupStatuses.shift() || 200; request.status = status;
        const results = q.length < 2 ? [] : people.filter(person => `${person.first_name} ${person.last_name} ${person.mis_id}`.toLowerCase().includes(q));
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' })
          .end(JSON.stringify(status === 200 ? { results } : { message: 'Synthetic directory unavailable' }));
        return;
      }
      if (url.pathname === listPath) {
        counts.views++; record(state, request);
        const isFetch = req.headers['x-requested-with'] === 'XMLHttpRequest' || req.headers['x-synapse-refresh'] === '1' || req.headers['x-synapse-navigation'] === '1';
        const status = isFetch ? state.getStatuses.shift() || 200 : 200; request.status = status;
        if (status !== 200) {
          res.writeHead(status, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' }).end('Synthetic filter temporarily unavailable');
          return;
        }
        if (!id) res.setHeader('Set-Cookie', `synthetic_callslips=${state.id}; Path=/; HttpOnly; SameSite=Strict`);
        const prefillPerson = people.find(person => String(person.id) === url.searchParams.get('person_id')) || null;
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store',
          'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf }).end(renderFixture({ origin, path: url.pathname + url.search,
          theme: state.theme, regionalEnabled: state.regionalEnabled, csrf: state.csrf, callSlips: sortSlips([...state.slips.values()]), prefillPerson }));
        return;
      }
      const file = assetFile(url.pathname);
      const real = await fs.promises.realpath(file);
      if (!real.startsWith(publicAssets + path.sep)) throw new Error('Local asset escaped its fixture root');
      const bytes = await fs.promises.readFile(real);
      counts.assets++;
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file).toLowerCase()], 'Cache-Control': 'no-store' }).end(bytes);
    } catch (error) {
      reject(error.stack || error.message);
      if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(error.message);
    }
  });
  server.listen(port, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  return { origin, server, preview, counts, violations, createScenario,
    close: async () => { if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } };
}

module.exports = { startServer, renderFixture, localPlaywright, installedChromium, allowedRequest, fixtureData,
  listPath, lookupPath, owner, statuses, purposes, people, limits, longText, hostileText };
if (require.main === module) {
  startServer().then(harness => {
    console.log(`Synthetic Guidance Call Slips preview: ${harness.origin}${listPath}`);
    console.log(`Prefilled modal: ${harness.origin}${listPath}?person_id=${people[0].id}&episode_id=914`);
    console.log('Only synthetic in-memory writes and labeled sample PDFs; no official documents are issued.');
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => harness.close().then(() => process.exit(0)));
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
