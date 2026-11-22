'use strict';

// Stateful per-case, loopback-only fixture. Real views/assets; all writes stay in
// these Maps. No application boot, DB, installs, screenshots, exports or realtime.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(__dirname, 'render-teams-workspace-fixture.php');
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml', '.png': 'image/png' };
const environment = () => Object.fromEntries(['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'].filter(key => process.env[key]).map(key => [key, process.env[key]]));
let phpOptions;
function phpArguments() {
  if (!phpOptions) {
    const binary = execFileSync('php', ['-n', '-r', 'print PHP_BINARY;'], { cwd: root, env: environment(), encoding: 'utf8', timeout: 10000 }).trim();
    const extension = path.join(path.dirname(binary), 'ext', process.platform === 'win32' ? 'php_mbstring.dll' : 'mbstring.so');
    assert.ok(fs.existsSync(extension), `Explicit installed mbstring is required at ${extension}; no installs are performed`);
    phpOptions = ['-n', '-d', `extension=${extension}`, '-d', 'allow_url_fopen=0', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1'];
  }
  return phpOptions;
}
function renderFixture(options) {
  const html = execFileSync('php', [...phpArguments(), renderer], { cwd: root, env: environment(), input: JSON.stringify(options), encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'], timeout: 10000, maxBuffer: 6 * 1024 * 1024 });
  assert.doesNotMatch(html, /Fatal error|Warning:|Notice:|Deprecated:|Parse error|"code":\s*500/, 'Isolated Teams view must not emit PHP errors');
  return html;
}
function localPlaywright() {
  const candidates = [path.join(root, 'tests/browser/node_modules/playwright'), path.join(root, 'writable/widget-test-tools/node_modules/playwright')];
  const module = candidates.find(candidate => fs.existsSync(path.join(candidate, 'package.json')));
  assert.ok(module, 'Existing local Playwright is required; no packages are installed');
  return require(module);
}
function installedChromium(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const installed = fs.existsSync(cache) ? fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .flatMap(name => ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe'].map(file => path.join(cache, name, file)))
    .find(file => fs.existsSync(file)) : null;
  assert.ok(installed, `Existing Chromium required at ${cache}; this harness never downloads a browser`);
  return installed;
}
const members = [
  { id: 1, name: 'Workspace Actor', role: 'staff' }, { id: 2, name: 'Other Member', role: 'staff' },
  { id: 3, name: 'Operations Lead', role: 'unit_head' }, { id: 4, name: 'Long Member Name For Workspace Geometry', role: 'staff' },
  { id: 5, name: 'Planning Member', role: 'staff' }, { id: 6, name: 'Support Member', role: 'staff' },
];
function message(id, changes = {}) {
  return { id, body: `Operational message ${id}`, sender_user_id: 2, sender_name: 'Other Member', task_id: null, task_title: null,
    tasks: [], mentions: [], reply_to: null, client_nonce: null, created_at: '2030-01-31 01:15:00', ...changes };
}
function initialTasks(count = 125) {
  return Array.from({ length: count }, (_, i) => ({ id: i + 31,
    title: i === 0 ? 'Review workstation supplies <literal>' : `Workspace task ${i + 31}`,
    description: i === 0 ? 'Operational details <img src=x onerror="window.teamsXss=1"> & "literal"\n' + 'UnbrokenWorkspaceText'.repeat(35) : 'Synthetic operational work only.',
    status: ['todo', 'in_progress', 'blocked', 'done'][i % 4], priority: ['normal', 'high', 'low'][i % 3],
    assignee_user_id: i % 3 === 0 ? 1 : i % 3 === 1 ? 2 : null, due_date: i === 0 ? '2030-01-31' : i < 3 ? '2030-02-01' : null,
    created_by_user_id: 1, version: i === 0 ? 7 : 3, canEdit: i !== 3,
    checklist: i === 0 ? [{ text: 'Inspect stored supplies', done: true }, { text: 'Record count <literal>', done: false }] : [],
    comments: i === 0 ? [{ actor_user_id: 2, body: 'Comment <img src=x onerror="window.teamsXss=1">', created_at: '2030-01-31 01:00:00' }] : [],
    comments_total: i === 0 ? 1 : 0, comments_limit: 50, activity: [], activity_total: 0, activity_limit: 50 }));
}
function initialEvents() {
  return [
    { id: 41, title: 'Monthly coordination <literal>', description: 'Internal event <img src=x onerror="window.teamsXss=1">', location: 'Operations room',
      status: 'scheduled', starts_at: '2030-01-31 01:15:00', ends_at: '2030-02-01 16:00:00', participant_ids: [2], created_by_user_id: 1, version: 3, canEdit: true },
    { id: 42, title: 'Cancelled planning event', description: '', location: '', status: 'cancelled', starts_at: '2030-01-31 03:00:00', ends_at: '2030-01-31 04:00:00', participant_ids: [1], created_by_user_id: 2, version: 4, canEdit: true },
    { id: 43, title: 'Other member schedule', description: '', location: '', status: 'scheduled', starts_at: '2030-02-02 01:00:00', ends_at: '2030-02-02 02:00:00', participant_ids: [2], created_by_user_id: 2, version: 2, canEdit: true },
  ];
}
function summary(state) {
  return { last_read_id: state.lastRead, latest_id: state.messages.at(-1)?.id || 0,
    unread_count: state.messages.filter(row => row.id > state.lastRead && row.sender_user_id !== 1).length };
}
function history(state, url) {
  let rows = state.messages;
  const after = url.searchParams.get('after'), before = url.searchParams.get('before');
  const around = url.searchParams.get('around') ?? url.searchParams.get('message');
  if (around !== null) rows = rows.filter(row => row.id <= Number(around));
  if (after !== null) rows = rows.filter(row => row.id > Number(after));
  if (before !== null) rows = rows.filter(row => row.id < Number(before));
  const has_more = rows.length > 40;
  rows = after !== null ? rows.slice(0, 40) : rows.slice(-40);
  if (state.overlap && before !== null) { const repeated = state.messages.find(row => row.id === Number(before)); if (repeated) rows = [...rows, repeated]; }
  return { messages: rows, has_more, next_before: after === null && has_more ? rows[0].id : null, ...summary(state) };
}
function optionsFor(state, url, origin) {
  return { origin, path: url.pathname + url.search, theme: state.theme, csrf: state.csrf, members: state.members,
    tasks: [...state.tasks.values()], events: [...state.events.values()].map(event => ({ ...event, participants: state.members.filter(member => event.participant_ids.includes(member.id)) })),
    history: url.searchParams.has('empty') ? { messages: [], has_more: false, next_before: null, last_read_id: 0, latest_id: 0, unread_count: 0 } : state.initialHistory || history(state, url),
    afterMode: state.afterMode || false, browsingHistory: url.searchParams.has('before') || url.searchParams.has('message') || state.browsingHistory || false,
    targetMessageId: url.searchParams.has('message') ? Number(url.searchParams.get('message')) : null };
}
async function readFields(req, origin, pathname) {
  const chunks = []; let size = 0;
  for await (const chunk of req) { size += chunk.length; if (size > 256 * 1024) throw new Error('Synthetic payload exceeds limit'); chunks.push(chunk); }
  const body = Buffer.concat(chunks);
  const form = await new Request(origin + pathname, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] || '' }, body }).formData();
  const entries = Array.from(form.entries());
  assert.ok(entries.every(([, value]) => typeof value === 'string'), 'File uploads are not provided by this fixture');
  return { form, fields: entries, body: body.toString('utf8') };
}
async function startServer({ port = 0 } = {}) {
  const scenarios = new Map(), violations = [];
  let origin, sequence = 0;
  function createScenario(options = {}) {
    const id = String(++sequence);
    const state = { id, theme: 'light', csrf: 'teams-token-0', tokenSequence: 0, revision: 0,
      members: structuredClone(members), tasks: new Map(initialTasks(options.taskCount).map(task => [task.id, task])),
      events: new Map(initialEvents().map(event => [event.id, event])),
      messages: [message(1, { body: 'Review these task details <img src=x onerror="window.teamsXss=1">', task_id: 31,
        task_title: 'Review workstation supplies <literal>', tasks: [{ id: 31, title: 'Review workstation supplies <literal>' }] }), message(2), message(3)],
      lastRead: 0, requests: [], posts: [], postStatuses: [], getStatuses: [], holds: new Map(), ...options };
    if (options.taskOverrides) for (const [taskId, changes] of Object.entries(options.taskOverrides)) Object.assign(state.tasks.get(Number(taskId)), changes);
    if (options.eventOverrides) for (const [eventId, changes] of Object.entries(options.eventOverrides)) Object.assign(state.events.get(Number(eventId)), changes);
    scenarios.set(id, state); return state;
  }
  const preview = createScenario();
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin || 'http://127.0.0.1');
      const id = /(?:^|;\s*)synthetic_teams=(\d+)(?:;|$)/.exec(req.headers.cookie || '')?.[1];
      const state = scenarios.get(id) || preview;
      const route = /^\/(clinic|guidance|facilities)\/teams(?:\/(.*))?$/.exec(url.pathname);
      const remainder = route?.[2] || '';
      const isView = !!route && /^(?:|create|\d+(?:\/edit)?|chat|calendar|calendar\/events\/(?:create|\d+(?:\/edit)?))$/.test(remainder);
      const chatGet = route && /^(chat\/(history|tasks))$/.test(remainder);
      const mutation = route && /^(?:store|\d+\/(?:update|move|comments)|chat\/(?:send|read)|calendar\/events\/(?:store|\d+\/(?:update|cancel)))$/.test(remainder);
      const record = { method: req.method, path: url.pathname + url.search, headers: req.headers };
      if (req.method === 'POST' && mutation) {
        const { form, fields, body } = await readFields(req, origin, url.pathname);
        Object.assign(record, { fields, body }); state.requests.push(record); state.posts.push(record);
        if (state.holds.has(remainder)) await state.holds.get(remainder);
        const validCsrf = form.get('fixture_csrf') === state.csrf && req.headers['x-fixture-csrf'] === state.csrf;
        let status = validCsrf ? (state.postStatuses.shift() || 200) : 403;
        const unit = route[1], teamsUrl = origin + '/' + unit + '/teams';
        const calendar = remainder.startsWith('calendar/'), chat = remainder.startsWith('chat/');
        const entityId = Number(/(?:^|\/)(\d+)\//.exec(remainder)?.[1]);
        const entity = calendar ? state.events.get(entityId) : state.tasks.get(entityId);
        if (status === 200 && /\/(?:update|move|cancel)$/.test(remainder) && (!entity || Number(form.get('version')) !== entity.version)) status = 409;
        if (status === 200 && calendar && !remainder.endsWith('/cancel')) {
          const start = Date.parse(form.get('starts_at') + ':00+08:00'), end = Date.parse(form.get('ends_at') + ':00+08:00');
          if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start || end - start > 31 * 86400000) status = 422;
        }
        // CSRF tokens are rotated on successful writes; on synthetic validation
        // failures they are also refreshed so modal retry contracts are exercised.
        if (status !== 403) state.csrf = `teams-token-${++state.tokenSequence}`;
        const result = { success: status === 200, message: status === 422 ? 'Synthetic validation: review the submitted values.'
          : status === 409 ? 'Synthetic conflict: this item changed elsewhere. Your inputs are retained.' : status === 403 ? 'Synthetic CSRF rejected.' : 'Synthetic Teams change saved.',
          csrf: { name: 'fixture_csrf', hash: state.csrf } };
        if (status === 200) {
          if (chat && remainder.endsWith('/read')) { state.lastRead = Math.max(state.lastRead, Number(form.get('last_read_id'))); result.data = summary(state); }
          else if (chat) {
            let saved = state.messages.find(row => row.client_nonce === form.get('client_nonce'));
            if (!saved) {
              const mentions = [];
              for (const [key, value] of form) { const match = /^mentions\[(\d+)\]\[(user_id|start|length)\]$/.exec(key);
                if (match) (mentions[Number(match[1])] ||= {})[match[2]] = Number(value); }
              const original = state.messages.find(row => row.id === Number(form.get('reply_to_id')));
              saved = message((state.messages.at(-1)?.id || 0) + 1, { body: form.get('body'), sender_user_id: 1, sender_name: 'Workspace Actor',
                client_nonce: form.get('client_nonce'), tasks: form.getAll('task_ids[]').map(id => state.tasks.get(Number(id))).filter(Boolean).map(task => ({ id: task.id, title: task.title })),
                mentions: mentions.map(mention => ({ ...mention, name: state.members.find(member => member.id === mention.user_id)?.name || 'Former member' })),
                reply_to: original ? { id: original.id, sender_name: original.sender_name, body: original.body } : null });
              state.messages.push(saved);
            }
            result.data = { message: saved };
          } else if (calendar) {
            if (remainder.endsWith('/cancel')) { entity.status = 'cancelled'; entity.version++; }
            else {
              const utc = value => new Date(value + ':00+08:00').toISOString().replace('T', ' ').slice(0, 19);
              const id = entity?.id || Math.max(...state.events.keys()) + 1;
              state.events.set(id, { ...(entity || { id, created_by_user_id: 1, status: 'scheduled', canEdit: true }),
                title: form.get('title'), description: form.get('description'), location: form.get('location'), starts_at: utc(form.get('starts_at')), ends_at: utc(form.get('ends_at')),
                participant_ids: form.getAll('participant_ids[]').map(Number), version: (entity?.version || 0) + 1 });
            }
          } else if (remainder.endsWith('/comments')) {
            assert.ok(entity, 'Synthetic comment task must exist');
            entity.comments.unshift({ actor_user_id: 1, body: form.get('body'), created_at: '2030-01-31 02:00:00' }); entity.comments_total++;
          } else if (remainder.endsWith('/move')) { entity.status = form.get('status'); entity.version++; }
          else {
            const id = entity?.id || Math.max(...state.tasks.keys()) + 1;
            state.tasks.set(id, { ...(entity || initialTasks(1)[0]), id, title: form.get('title'), description: form.get('description'), status: form.get('status'),
              priority: form.get('priority'), assignee_user_id: form.get('assignee_user_id') ? Number(form.get('assignee_user_id')) : null, due_date: form.get('due_date') || null,
              checklist: JSON.parse(form.get('checklist')), version: (entity?.version || 0) + 1 });
          }
          state.revision++;
          if (!chat) {
            const owner = `teams.${calendar ? 'calendar' : 'tasks'}.${unit}`;
            const savedEvent = calendar ? state.events.get(entity?.id || Math.max(...state.events.keys())) : null;
            const date = savedEvent ? new Date(savedEvent.starts_at.replace(' ', 'T') + 'Z').toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' }) : null;
            result.redirect = teamsUrl + (calendar ? '/calendar?date=' + date : remainder.endsWith('/comments') ? '/' + entityId : '');
            result.refresh = req.headers['x-synapse-refresh-owner'] === owner
              ? { mode: 'regions', owner, regions: calendar ? ['notice', 'summary', 'results'] : ['navigation', 'results'] }
              : { mode: 'navigate', url: result.redirect, history: 'push' };
          }
        }
        if (status === 200 && remainder === 'chat/send' && state.loseSendResponses > 0) {
          state.loseSendResponses--;
          // A committed write with a synthetic failed acknowledgement exercises
          // the existing client's same-nonce retry, without real network traffic.
          status = 503; result.success = false; result.message = 'Synthetic delivery acknowledgement unavailable after commit.';
          delete result.data;
        }
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf }).end(JSON.stringify(result));
        return;
      }
      if (req.method !== 'GET' || (!isView && !chatGet && !url.pathname.startsWith('/assets/'))) {
        violations.push(`${req.method} ${url.pathname}`); res.writeHead(405).end('Only synthetic Teams routes and real local assets are permitted'); return;
      }
      if (isView || chatGet) {
        state.requests.push(record);
        if (chatGet) {
          const data = remainder.endsWith('/tasks') ? { tasks: [...state.tasks.values()].filter(task => task.status !== 'done'
            && `${task.id} ${task.title}`.toLocaleLowerCase().includes((url.searchParams.get('q') || '').toLocaleLowerCase())).slice(0, 50).map(task => ({ id: task.id, title: task.title })) }
            : history(state, url);
          res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }).end(JSON.stringify({ success: true, data, csrf: { name: 'fixture_csrf', hash: state.csrf } })); return;
        }
        const getStatus = req.headers['x-requested-with'] === 'XMLHttpRequest' ? state.getStatuses.shift() || 200 : 200;
        if (getStatus !== 200) { res.writeHead(getStatus, { 'Content-Type': 'text/plain' }).end('Synthetic refresh unavailable'); return; }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', 'X-CSRF-NAME': 'fixture_csrf', 'X-CSRF-TOKEN': state.csrf })
          .end(renderFixture(optionsFor(state, url, origin))); return;
      }
      const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(publicRoot + path.sep) || !file.startsWith(path.join(publicRoot, 'assets') + path.sep)) {
        violations.push('Invalid local asset path'); res.writeHead(403).end(); return;
      }
      const data = await fs.promises.readFile(file);
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' }).end(data);
    } catch (error) {
      violations.push(error.stack || error.message); if (!res.headersSent) res.writeHead(500, { 'Content-Type': 'text/plain' }); res.end(error.message);
    }
  });
  server.listen(port, '127.0.0.1'); await once(server, 'listening'); origin = `http://127.0.0.1:${server.address().port}`;
  return { origin, server, scenarios, preview, violations, createScenario,
    close: async () => { if (server.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } } };
}
module.exports = { startServer, renderFixture, localPlaywright, installedChromium, message, initialTasks, initialEvents };
if (require.main === module) {
  startServer({ port: Number(process.argv[2] || 0) }).then(harness => {
    for (const suffix of ['', '/chat', '/calendar?date=2030-01-31']) console.log(`Synthetic Teams preview: ${harness.origin}/guidance/teams${suffix}`);
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => harness.close().then(() => process.exit(0)));
  }).catch(error => { console.error(error); process.exitCode = 1; });
}
