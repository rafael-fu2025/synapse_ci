'use strict';

// Run: node --test tests/browser/team-chat.test.cjs
// Real view/layout/assets; the HTTP fixture models only the documented chat API.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const fixtures = new Map();
let sequence = 0;
const attack = '<img src=x onerror="window.chatXss=1">';
const message = (id, changes = {}) => ({ id, body: `Message ${id}`, sender_user_id: 2,
  sender_name: 'Other member', task_id: null, task_title: null, mentions: [], reply_to: null, client_nonce: null,
  created_at: '2026-10-01 16:15:00', ...changes });
const csrf = { name: 'csrf_test_name', hash: 'chat-refreshed-token' };
function summary(f) {
  return { last_read_id: f.lastRead, latest_id: f.messages.at(-1)?.id || 0,
    unread_count: f.messages.filter(m => m.id > f.lastRead && m.sender_user_id !== 1).length };
}
function history(f, url) {
  let rows = f.messages;
  const after = url.searchParams.get('after'), before = url.searchParams.get('before');
  const around = url.searchParams.get('around') ?? url.searchParams.get('message');
  if (around !== null) rows = rows.filter(m => m.id <= Number(around));
  if (after !== null) rows = rows.filter(m => m.id > Number(after));
  if (before !== null) rows = rows.filter(m => m.id < Number(before));
  const has_more = rows.length > 40;
  rows = after !== null ? rows.slice(0, 40) : rows.slice(-40);
  // Deliberately overlapping responses test defensive client de-duplication.
  if (f.overlap && before !== null) rows = [...rows, f.messages.find(m => m.id === Number(before))];
  return { messages: rows, has_more, next_before: after === null && has_more ? rows[0].id : null, ...summary(f) };
}
function render(f, url) {
  const initial = f.initial || history(f, url);
  const result = spawnSync('php', ['tests/_support/render-team-chat-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: url.pathname + url.search, history: initial,
      afterMode: f.afterMode, targetMessageId: f.targetMessageId ?? (url.searchParams.has('message') ? Number(url.searchParams.get('message')) : null),
      browsingHistory: url.searchParams.has('message') || url.searchParams.has('before') }), encoding: 'utf8', timeout: 10000
  });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /data-team-chat/);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error/);
  return result.stdout;
}
before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath, 'Use the existing Playwright Chromium installation');
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (/^\/(clinic|guidance|facilities)\/teams\/chat(?:\/|$)/.test(url.pathname)) {
      const referer = new URL(req.headers.referer || origin);
      const key = url.searchParams.get('fixture') || referer.searchParams.get('fixture');
      const f = fixtures.get(key);
      if (!f) { res.writeHead(404).end(); return; }
      const reply = (data, status = 200) => {
        res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store, private' });
        res.end(JSON.stringify({ success: status < 400, ...(status < 400 ? { data } : { message: data }), csrf }));
      };
      if (req.method === 'POST') {
        const chunks = [];
        for await (const chunk of req) chunks.push(chunk);
        const params = new URLSearchParams(Buffer.concat(chunks).toString());
        const fields = Object.fromEntries(params);
        if (params.has('task_ids[]')) fields['task_ids[]'] = params.getAll('task_ids[]');
        const mentions = [];
        for (const [key, value] of params) {
          const match = /^mentions\[(\d+)\]\[(user_id|start|length)\]$/.exec(key);
          if (match) (mentions[Number(match[1])] ||= {})[match[2]] = Number(value);
        }
        f.posts.push({ path: url.pathname, fields, headers: req.headers });
        if (url.pathname.endsWith('/read')) {
          f.lastRead = Math.max(f.lastRead, Number(fields.last_read_id));
          reply(summary(f));
        } else if (url.pathname.endsWith('/send')) {
          if (f.reject) { reply('Fixture validation error', 422); return; }
          let saved = f.messages.find(m => m.client_nonce === fields.client_nonce);
          if (!saved) {
            saved = message((f.messages.at(-1)?.id || 0) + 1, { body: fields.body,
              sender_user_id: 1, sender_name: 'Chat Fixture', client_nonce: fields.client_nonce,
              tasks: params.getAll('task_ids[]').map(id => f.tasks.find(task => task.id === Number(id))),
              mentions: mentions.map(mention => ({ ...mention, name: mention.user_id === 1 ? 'Chat Fixture' : 'Other member' })),
              reply_to: fields.reply_to_id ? (() => {
                const original = f.messages.find(m => m.id === Number(fields.reply_to_id));
                return { id: original.id, sender_name: original.sender_name, body: original.body };
              })() : null });
            f.messages.push(saved);
          }
          reply({ message: saved });
        } else res.writeHead(404).end();
      } else if (url.pathname.endsWith('/tasks')) {
        const query = url.searchParams.get('q') || '';
        f.taskQueries.push(query);
        reply({ tasks: f.tasks.filter(task => `${task.id} ${task.title}`.toLocaleLowerCase().includes(query.toLocaleLowerCase())).slice(0, 50) });
      } else if (url.pathname.endsWith('/history')) {
        f.gets.push(url.search);
        reply(history(f, url));
      } else {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(render(f, url));
      }
      return;
    }
    const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + url.pathname);
    if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});
async function pageFor(t, { messages = [], width = 1280, dark = false, hidden = false, realtime = null, unit = 'clinic', ...options } = {}) {
  const key = String(++sequence);
  const f = { messages, lastRead: 0, posts: [], gets: [], taskQueries: [],
    tasks: [{ id: 12, title: attack }, { id: 99, title: 'Review supplies' }, { id: 101, title: 'Schedule maintenance' }], ...options };
  fixtures.set(key, f);
  const context = await browser.newContext({ viewport: { width, height: 900 }, timezoneId: 'America/Los_Angeles' });
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  await context.addInitScript(({ dark, hidden, realtime }) => {
      if (realtime !== null) window.SynapseRealtime = { status: realtime };
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    window.fixtureHidden = hidden;
    window.fixtureTargetFocus = [];
    const focus = HTMLElement.prototype.focus;
    HTMLElement.prototype.focus = function (options) {
      if (this.matches('article[id^="chat-message-"]')) window.fixtureTargetFocus.push({ id: this.id, preventScroll: options?.preventScroll });
      return focus.call(this, options);
    };
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => window.fixtureHidden });
  }, { dark, hidden, realtime });
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  await page.clock.install();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); fixtures.delete(key); assert.deepEqual(errors, []); });
  const target = options.targetMessageId;
  await page.goto(`${origin}/${unit}/teams/chat?fixture=${key}${target ? `&message=${target}#chat-message-${target}` : ''}`);
  await page.waitForFunction(() => window.SynapseTeamChat && !document.querySelector('[data-team-chat]').hasAttribute('data-initial'));
  return { page, f };
}
const sends = f => f.posts.filter(p => p.path.endsWith('/send'));
const reads = f => f.posts.filter(p => p.path.endsWith('/read'));
const sendButton = page => page.getByRole('button', { name: 'Send', exact: true });
async function pickTasks(page, f, ids) {
  await page.getByRole('button', { name: 'Add task references', exact: true }).click();
  for (const id of ids) {
    await page.getByRole('checkbox', { name: `#${id}: ${f.tasks.find(task => task.id === id).title}`, exact: true }).check();
  }
  await page.getByRole('button', { name: 'Done', exact: true }).click();
}
async function sendDraft(page, id) {
  await sendButton(page).click();
  await page.waitForFunction(() => document.querySelector('[data-chat-body]').value === '');
  await page.locator(`[data-message-id="${id}"]`).waitFor();
}


async function realtimeEvent(page, detail) {
  await page.evaluate(detail => document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail })), detail);
}
async function realtimeStatus(page, status) {
  await page.evaluate(status => {
    window.SynapseRealtime.status = status;
    document.dispatchEvent(new CustomEvent('synapse:realtime-status', { detail: { status } }));
  }, status);
}

test('an explicitly expired chat session leaves the authenticated shell for the canonical login document', async t => {
  const { page, f } = await pageFor(t, { unit: 'guidance' });
  const before = sends(f).length;
  await page.route('**/auth/login', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Sign in</title><h1>Sign in</h1>' }));
  await page.route('**/guidance/teams/chat/history*', route => route.fulfill({ status: 401,
    headers: { 'Content-Type': 'application/json', 'X-Synapse-Auth': 'session_expired', 'X-Synapse-Login': origin + '/auth/login' },
    body: JSON.stringify({ message: 'Your session has expired. Please sign in again to continue.' }) }));
  await page.clock.runFor(5100);
  await page.waitForURL(origin + '/auth/login');
  assert.equal(await page.locator('[data-team-chat]').count(), 0);
  assert.equal(new URL(page.url()).search, '');
  assert.equal(sends(f).length, before, 'Authentication recovery must not submit or replay a message');
});

test('chat permission denial stays a permission error, not an invented expired-session redirect', async t => {
  const { page } = await pageFor(t);
  const original = page.url();
  await page.route('**/clinic/teams/chat/history*', route => route.fulfill({ status: 403, contentType: 'application/json',
    body: JSON.stringify({ success: false, message: 'You no longer have access to this team.' }) }));
  await page.clock.runFor(5100);
  await page.getByText('You no longer have access to this team.', { exact: true }).waitFor();
  assert.equal(page.url(), original);
  assert.equal(await page.evaluate(() => window.SynapseAuth.redirecting), false);
});

test('ready realtime coalesces scoped hints and uses slow reconciliation with fast unavailable fallback', async t => {
  const { page, f } = await pageFor(t, { realtime: 'ready' });
  await page.clock.runFor(5000);
  assert.equal(f.gets.length, 0);
  await realtimeEvent(page, { event: 'queue.invalidated', data: { unit: 'clinic' } });
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'guidance' } });
  await page.clock.runFor(1000);
  assert.equal(f.gets.length, 0);
  f.messages.push(message(1));
  await page.evaluate(() => {
    for (let i = 0; i < 20; i++) document.dispatchEvent(new CustomEvent('synapse:realtime-event', {
      detail: { event: 'team.chat.changed', data: { unit: 'clinic' } }
    }));
  });
  await page.clock.runFor(150);
  await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 1);
  assert.equal(f.gets.length, 1);
  await page.clock.runFor(59000);
  assert.equal(f.gets.length, 1);
  const reconciliation = page.waitForResponse(response => response.url().includes('/history'));
  await page.clock.runFor(1100);
  await reconciliation;
  assert.equal(f.gets.length, 2);
  await realtimeStatus(page, 'unavailable');
  await page.clock.runFor(5100);
  await page.waitForFunction(() => document.querySelector('[data-chat-connection]').textContent.includes('5 seconds'));
  assert.equal(f.gets.length, 3);
});

test('disconnect during an in-flight poll schedules the current 5-second fallback on completion', async t => {
  const { page, f } = await pageFor(t, { realtime: 'ready' });
  let release, started;
  const held = new Promise(resolve => { release = resolve; });
  const requested = new Promise(resolve => { started = resolve; });
  await page.route('**/chat/history?after=*', async route => {
    const response = await route.fetch();
    started();
    await held;
    await route.fulfill({ response });
  }, { times: 1 });
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'clinic' } });
  await page.clock.runFor(150);
  await requested;
  await realtimeStatus(page, 'unavailable');
  // The disconnect's own timer expires while the HTTP poll is still busy.
  await page.clock.runFor(5100);
  assert.equal(f.gets.length, 1);
  const completed = page.waitForResponse(response => response.url().includes('/history'));
  release();
  await completed;
  await page.waitForFunction(() => document.querySelector('[data-chat-connection]').textContent.includes('5 seconds'));
  await page.clock.runFor(4900);
  assert.equal(f.gets.length, 1);
  const fallback = page.waitForResponse(response => response.url().includes('/history'));
  await page.clock.runFor(200);
  await fallback;
  assert.equal(f.gets.length, 2);
});

test('reconnect and same-user read hints reconcile without resetting scroll or drafts', async t => {
  const { page, f } = await pageFor(t, { realtime: 'unavailable', messages: Array.from({ length: 30 }, (_, i) => message(i + 1)) });
  await page.locator('[data-chat-body]').fill('Keep my draft');
  const top = await page.locator('[data-chat-log]').evaluate(log => { log.scrollTop = 80; return log.scrollTop; });
  f.lastRead = 30;
  await realtimeStatus(page, 'ready');
  await page.clock.runFor(150);
  await page.waitForFunction(() => document.querySelector('[data-chat-unread]').textContent === '0');
  assert.equal(f.gets.length, 1);
  assert.equal(await page.locator('[data-chat-log]').evaluate(log => log.scrollTop), top);
  assert.equal(await page.locator('[data-chat-body]').inputValue(), 'Keep my draft');
  const refresh = page.waitForResponse(response => response.url().includes('/history'));
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'clinic' } });
  await page.clock.runFor(150);
  await refresh;
  assert.equal(f.gets.length, 2);
});

test('hints during a refresh trigger one trailing refresh and preserve off-bottom scroll and read safety', async t => {
  const { page, f } = await pageFor(t, { realtime: 'ready', lastRead: 30,
    messages: Array.from({ length: 30 }, (_, i) => message(i + 1)) });
  const top = await page.locator('[data-chat-log]').evaluate(log => { log.scrollTop = 80; return log.scrollTop; });
  let release;
  const held = new Promise(resolve => { release = resolve; });
  let started;
  const requested = new Promise(resolve => { started = resolve; });
  await page.route('**/chat/history?after=*', async route => {
    await route.fetch().then(async response => {
      const data = await response.json();
      started();
      await held;
      await route.fulfill({ json: data });
    });
  }, { times: 1 });
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'clinic' } });
  await page.clock.runFor(150);
  await requested;
  f.messages.push(message(31));
  await page.evaluate(() => {
    for (let i = 0; i < 20; i++) document.dispatchEvent(new CustomEvent('synapse:realtime-event', {
      detail: { event: 'team.chat.changed', data: { unit: 'clinic' } }
    }));
  });
  assert.equal(f.gets.length, 1);
  const firstDone = page.waitForResponse(response => response.url().includes('/history'));
  release();
  await firstDone;
  await page.clock.runFor(200);
  await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 31);
  assert.equal(f.gets.length, 2);
  assert.equal(await page.locator('[data-chat-log]').evaluate(log => log.scrollTop), top);
  await page.clock.runFor(1000);
  assert.equal(reads(f).length, 0, 'Offscreen new messages must not be acknowledged by hints');
  assert.equal(f.gets.length, 2);
});

test('hints preserve paused history and are ignored while hidden or after navigation cleanup', async t => {
  const { page, f } = await pageFor(t, { realtime: 'ready', messages: Array.from({ length: 80 }, (_, i) => message(i + 1)) });
  await page.locator('[data-chat-older]').click();
  await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 80);
  const before = { gets: f.gets.length, reads: reads(f).length,
    top: await page.locator('[data-chat-log]').evaluate(log => log.scrollTop) };
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'clinic' } });
  await page.clock.runFor(61000);
  assert.equal(f.gets.length, before.gets);
  assert.equal(reads(f).length, before.reads);
  assert.equal(await page.locator('[data-chat-log]').evaluate(log => log.scrollTop), before.top);
  await page.locator('[data-chat-latest]').click();
  await page.waitForFunction(() => !document.querySelector('[data-chat-history-status]').textContent.includes('paused'));
  await page.evaluate(() => { window.fixtureHidden = true; document.dispatchEvent(new Event('visibilitychange')); });
  const count = f.gets.length;
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'clinic' } });
  await realtimeStatus(page, 'unavailable');
  await realtimeStatus(page, 'ready');
  await page.clock.runFor(61000);
  assert.equal(f.gets.length, count);
  const resumed = page.waitForResponse(response => response.url().includes('/history'));
  await page.evaluate(() => { window.fixtureHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
  await page.clock.runFor(150);
  await resumed;
  assert.equal(f.gets.length, count + 1);
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('[data-team-chat]') } })));
  const stopped = f.gets.length;
  await realtimeEvent(page, { event: 'team.chat.changed', data: { unit: 'clinic' } });
  await realtimeStatus(page, 'unavailable');
  await realtimeStatus(page, 'ready');
  await page.clock.runFor(61000);
  assert.equal(f.gets.length, stopped);
});

test('lost send response retries identical nonce and text and creates one logical message', async t => {
  const { page, f } = await pageFor(t);
  let lost = false;
  await page.route('**/chat/send', async route => {
    if (!lost) { lost = true; await route.fetch(); await route.abort('failed'); }
    else await route.continue();
  });
  await page.locator('[data-chat-body]').fill('Keep this exact text\nand newline');
  await pickTasks(page, f, [12]);
  await page.locator('[data-chat-send]').click();
  await page.getByRole('button', { name: 'Retry send', exact: true }).waitFor();
  assert.equal(await page.locator('[data-chat-body]').getAttribute('readonly'), '');
  assert.equal(await page.getByRole('button', { name: 'Add task references', exact: true }).isDisabled(), true);
    assert.equal(await page.locator('.team-chat-task-chip').isDisabled(), true);
  assert.equal(await page.locator('[data-chat-body]').inputValue(), 'Keep this exact text\nand newline');
  await page.getByRole('button', { name: 'Retry send', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-chat-body]').value === '');
  await page.locator('[data-message-id="1"]').waitFor();
  assert.equal(sends(f).length, 2);
  assert.deepEqual(sends(f)[0].fields, sends(f)[1].fields);
  assert.match(sends(f)[0].fields.client_nonce, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  assert.ok(sends(f)[0].fields.csrf_test_name);
  assert.equal(f.messages.length, 1);
  assert.equal(await page.locator('[data-message-id]').count(), 1);
  assert.equal(await page.locator('[data-chat-body]').isEditable(), true);
});

test('422 retains draft and task, unlocks editing, and correction uses a fresh nonce', async t => {
  const { page, f } = await pageFor(t, { reject: true });
  await page.locator('[data-chat-body]').fill('Original draft');
  await pickTasks(page, f, [99]);
  await page.locator('[data-chat-send]').click();
  await page.locator('[data-chat-error]').filter({ hasText: 'Fixture validation error' }).waitFor();
  assert.equal(await page.locator('[data-chat-body]').inputValue(), 'Original draft');
  assert.match(await page.locator('.team-chat-task-chip').textContent(), /#99: Review supplies/);
  assert.equal(await page.locator('[data-chat-body]').isEditable(), true);
  assert.equal(await page.locator('.team-chat-task-chip').isEnabled(), true);
  assert.equal(await page.locator('[data-chat-send]').isEnabled(), true);
  f.reject = false;
  await page.locator('[data-chat-body]').fill('Corrected draft');
  await page.getByRole('button', { name: 'Remove task #99: Review supplies', exact: true }).click();
  await page.locator('[data-chat-send]').click();
  await page.waitForFunction(() => document.querySelector('[data-chat-body]').value === '');
  assert.notEqual(sends(f)[0].fields.client_nonce, sends(f)[1].fields.client_nonce);
  assert.equal(f.messages.length, 1);
  assert.equal(f.messages[0].body, 'Corrected draft');
});

test('mention keyboard navigation selects without sending and renders a highlighted mention', async t => {
  const { page, f } = await pageFor(t);
  const body = page.getByRole('textbox', { name: 'Message', exact: true });
  await body.fill('@');
  const options = page.getByRole('option');
  assert.deepEqual(await options.allTextContents(), ['@Chat Fixture', '@Other member']);
  assert.equal(await options.nth(0).getAttribute('aria-selected'), 'true');
  await body.press('ArrowUp');
  assert.equal(await options.nth(1).getAttribute('aria-selected'), 'true');
  assert.equal(await body.getAttribute('aria-activedescendant'), await options.nth(1).getAttribute('id'));
  await body.press('ArrowDown');
  assert.equal(await options.nth(0).getAttribute('aria-selected'), 'true');
  await body.press('ArrowDown');
  await body.press('Enter');
  assert.equal(await body.inputValue(), '@Other member ');
  assert.equal(sends(f).length, 0, 'Enter selects the mention instead of submitting');
  assert.equal(await body.getAttribute('aria-expanded'), 'false');
  assert.equal(await body.getAttribute('aria-activedescendant'), null);
  await page.keyboard.insertText('please review');
  await sendDraft(page, 1);
  assert.deepEqual(f.messages[0].mentions, [{ user_id: 2, start: 0, length: 13, name: 'Other member' }]);
  assert.equal(sends(f)[0].fields['mentions[0][user_id]'], '2');
  assert.equal(sends(f)[0].fields['mentions[0][start]'], '0');
  assert.equal(sends(f)[0].fields['mentions[0][length]'], '13');
  assert.equal(await page.locator('.team-chat-mention').textContent(), '@Other member');
  assert.equal(await page.locator('.team-chat-mention').getAttribute('title'), 'Other member');
});

for (const scenario of [
  { name: 'emoji before mention', prefix: '😀 ', edit: [0, 0, '🚀 '], start: 6 },
  { name: 'deleting emoji before mention', prefix: '😀 ', edit: [0, 3, ''], start: 0 },
  { name: 'editing inside mention invalidates it', prefix: '😀 ', edit: [5, 6, 'X'], start: null },
  { name: 'editing after mention preserves it', prefix: '😀 ', edit: [17, 17, '🚀'], start: 3 }
]) {
  test(`mention offsets: ${scenario.name}`, async t => {
    const { page, f } = await pageFor(t);
    const body = page.getByRole('textbox', { name: 'Message', exact: true });
    await body.fill(`${scenario.prefix}@Other`);
    await page.getByRole('option', { name: '@Other member', exact: true }).waitFor();
    await body.press('Tab');
    assert.equal(await body.inputValue(), `${scenario.prefix}@Other member `);
    assert.equal(sends(f).length, 0);
    await body.evaluate((el, [start, end]) => { el.focus(); el.setSelectionRange(start, end); }, scenario.edit);
    if (scenario.edit[2]) await page.keyboard.insertText(scenario.edit[2]);
    else await body.press('Backspace');
    const text = await body.inputValue();
    await sendDraft(page, 1);
    assert.equal(f.messages[0].body, text);
    if (scenario.start === null) {
      assert.deepEqual(f.messages[0].mentions, []);
      assert.equal(Object.keys(sends(f)[0].fields).some(key => key.startsWith('mentions[')), false);
      assert.equal(await page.locator('.team-chat-mention').count(), 0);
    } else {
      assert.deepEqual(f.messages[0].mentions, [{ user_id: 2, start: scenario.start, length: 13, name: 'Other member' }]);
      assert.equal(sends(f)[0].fields['mentions[0][start]'], String(scenario.start));
      assert.equal(await page.locator('.team-chat-mention').textContent(), '@Other member');
    }
  });
}

test('replies show a literal preview, can be cancelled, and persist a quoted response', async t => {
  const { page, f } = await pageFor(t, { messages: [message(1, { body: attack })] });
  const reply = page.locator('[data-message-id="1"]').getByRole('button', { name: 'Reply to Other member', exact: true });
  const preview = page.locator('[data-chat-reply-preview]');
  await reply.click();
  assert.equal(await preview.isVisible(), true);
  assert.equal(await preview.locator('p').textContent(), attack);
  assert.equal(await preview.locator('img').count(), 0);
  await page.getByRole('button', { name: 'Cancel reply', exact: true }).click();
  assert.equal(await preview.isHidden(), true);
  await page.locator('[data-chat-body]').fill('Not a reply');
  await sendDraft(page, 2);
  assert.equal(sends(f)[0].fields.reply_to_id, undefined);
  assert.equal(f.messages[1].reply_to, null);
  await reply.click();
  await page.locator('[data-chat-body]').fill('Acknowledged');
  await sendDraft(page, 3);
  assert.equal(sends(f)[1].fields.reply_to_id, '1');
  assert.deepEqual(f.messages[2].reply_to, { id: 1, sender_name: 'Other member', body: attack });
  const quote = page.locator('[data-message-id="3"] .team-chat-reply-quote');
  assert.equal(await quote.locator('strong').textContent(), 'Reply to Other member');
  assert.equal(await quote.locator('p').textContent(), attack);
  assert.equal(await quote.locator('img').count(), 0);
  assert.equal(await preview.isHidden(), true);
});

test('task picker searches, keeps multiple references, removes chips, and sends repeated task_ids[]', async t => {
  const { page, f } = await pageFor(t);
  await pickTasks(page, f, [12, 99, 101]);
  assert.equal(await page.locator('.team-chat-task-chip').count(), 3);
  assert.equal(await page.locator('[data-chat-selected-tasks] img').count(), 0);
  await page.getByRole('button', { name: 'Remove task #99: Review supplies', exact: true }).click();
  await page.getByRole('button', { name: 'Add task references', exact: true }).click();
  assert.equal(await page.getByRole('checkbox', { name: '#99: Review supplies', exact: true }).isChecked(), false);
  const search = page.getByRole('searchbox', { name: 'Search active tasks', exact: true });
  const result = page.waitForResponse(response => response.url().includes('/chat/tasks?q=maintenance'));
  await search.fill('maintenance');
  await page.clock.runFor(250);
  await result;
  await page.getByRole('checkbox', { name: '#101: Schedule maintenance', exact: true }).waitFor();
  assert.equal(await page.getByRole('checkbox').count(), 1);
  assert.equal(await page.getByRole('checkbox').isChecked(), true);
  assert.equal(await page.locator('.team-chat-task-chip').count(), 2);
  const empty = page.waitForResponse(response => response.url().includes('/chat/tasks?q=unmatched'));
  await search.fill('unmatched');
  await page.clock.runFor(250);
  await empty;
  await page.getByText('No active tasks match your search.', { exact: true }).waitFor();
  assert.ok(f.taskQueries.includes('maintenance'));
  assert.ok(f.taskQueries.includes('unmatched'));
  await page.getByRole('button', { name: 'Done', exact: true }).click();
  await page.locator('[data-chat-body]').fill('See these tasks');
  await sendDraft(page, 1);
  assert.deepEqual(sends(f)[0].fields['task_ids[]'], ['12', '101']);
  assert.equal(sends(f)[0].fields.task_id, undefined);
  assert.match(sends(f)[0].headers['content-type'], /^application\/x-www-form-urlencoded/);
  assert.deepEqual(f.messages[0].tasks, [f.tasks[0], f.tasks[2]]);
  assert.deepEqual(await page.locator('[data-message-id="1"] .team-chat-task').allTextContents(), [`Task #12: ${attack}`, 'Task #101: Schedule maintenance']);
  assert.deepEqual(await page.locator('[data-message-id="1"] .team-chat-task').evaluateAll(links => links.map(link => link.getAttribute('href'))), [`${origin}/clinic/teams/12`, `${origin}/clinic/teams/101`]);
  assert.equal(await page.locator('.team-chat-task-chip').count(), 0);
});

test('task references use a focus-contained modal that preserves selections when dismissed', async t => {
  const { page, f } = await pageFor(t, { width: 390, dark: true });
  const trigger = page.getByRole('button', { name: 'Add task references', exact: true });
  const dialog = page.getByRole('dialog', { name: 'Reference tasks', exact: true });
  await page.locator('[data-chat-body]').fill('Keep this draft');
  await trigger.click();
  await dialog.waitFor();
  assert.equal(await dialog.evaluate(el => el.matches(':modal')), true);
  assert.equal(await page.getByRole('searchbox', { name: 'Search active tasks' }).evaluate(el => el === document.activeElement), true);
  await page.getByRole('checkbox', { name: `#12: ${f.tasks[0].title}`, exact: true }).check();
  assert.equal(await page.locator('[data-chat-task-count]').textContent(), '1 of 20 selected');
  await page.getByRole('searchbox', { name: 'Search active tasks' }).press('Enter');
  assert.equal(sends(f).length, 0, 'Enter in task search must not submit the message');
  for (let i = 0; i < 8; i++) {
    await page.keyboard.press('Tab');
    assert.equal(await dialog.evaluate(el => el.contains(document.activeElement) || document.activeElement === document.body), true);
  }
  const bounds = await dialog.boundingBox();
  assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 391, 'Modal fits narrow viewport');
  await page.keyboard.press('Escape');
  assert.equal(await dialog.isVisible(), false);
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
  assert.equal(await trigger.evaluate(el => el === document.activeElement), true);
  assert.equal(await page.locator('[data-chat-body]').inputValue(), 'Keep this draft');
  await trigger.click();
  await page.getByRole('checkbox', { name: `#12: ${f.tasks[0].title}`, exact: true }).waitFor();
  assert.equal(await page.getByRole('checkbox', { name: `#12: ${f.tasks[0].title}`, exact: true }).isChecked(), true);
  await page.getByRole('button', { name: 'Close task references', exact: true }).click();
  assert.equal(await dialog.isVisible(), false);
  await trigger.click();
  await dialog.waitFor();
  await page.mouse.click(5, 5);
  assert.equal(await dialog.isVisible(), false);
  assert.equal(await page.locator('.team-chat-task-chip').count(), 1);
  await trigger.click();
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('[data-team-chat]') } })));
  assert.equal(await page.locator('dialog:modal').count(), 0, 'Navigation cleanup removes the modal top layer');
});

test('chat icons share header action styling and message input has restrained corners', async t => {
  const { page } = await pageFor(t);
  const styles = await page.evaluate(() => {
    const pick = selector => {
      const el = document.querySelector(selector), style = getComputedStyle(el);
      return { width: style.width, height: style.height, radius: style.borderRadius, border: style.border, background: style.backgroundColor, color: style.color };
    };
    return { notification: pick('#notificationBell'), add: pick('[data-chat-add-task]'), send: pick('[data-chat-send]'), input: pick('[data-chat-body]') };
  });
  assert.deepEqual(styles.add, styles.notification);
  assert.deepEqual(styles.send, styles.notification);
  assert.equal(styles.input.radius, '4px');
  assert.equal(await page.locator('[data-chat-add-task]').evaluate(el => el.parentElement.dataset.bsTitle), 'Add task references');
});

for (const [width, dark] of [[360, true], [1100, false]]) {
  test(`chat icon alignment and outside reply placement at ${width}px`, async t => {
    const { page } = await pageFor(t, { width, dark, messages: [
      message(1, { body: 'Team message' }),
      message(2, { body: 'My message\nwith multiple lines', sender_user_id: 1, sender_name: 'Chat Fixture' })
    ] });
    const checkCenter = async selector => {
      const result = await page.locator(selector).evaluate(el => {
        const box = el.getBoundingClientRect(), icon = el.querySelector('.bi').getBoundingClientRect();
        return { dx: Math.abs(box.x + box.width / 2 - icon.x - icon.width / 2), dy: Math.abs(box.y + box.height / 2 - icon.y - icon.height / 2) };
      });
      assert.ok(result.dx <= 1 && result.dy <= 1, `${selector}: ${JSON.stringify(result)}`);
    };
    await page.locator('[data-chat-body]').fill('Line one\nLine two\nLine three\nLine four');
    for (const selector of ['[data-chat-add-task]', '[data-chat-send]', '.team-chat-info-btn']) await checkCenter(selector);
    const composer = await page.evaluate(() => {
      const center = selector => { const rect = document.querySelector(selector).getBoundingClientRect(); return rect.y + rect.height / 2; };
      return { input: center('[data-chat-body]'), add: center('[data-chat-add-task]'), send: center('[data-chat-send]') };
    });
    assert.ok(Math.abs(composer.input - composer.add) <= 1 && Math.abs(composer.input - composer.send) <= 1);
    for (const id of [1, 2]) {
      const article = page.locator(`[data-message-id="${id}"]`);
      const reply = article.locator('[data-chat-reply]');
      assert.equal((await reply.textContent()).trim(), '', 'Reply is icon-only');
      assert.equal(await article.locator('.team-chat-bubble [data-chat-reply]').count(), 0);
      const geometry = await article.evaluate(el => {
        const bubble = el.querySelector('.team-chat-bubble').getBoundingClientRect(), reply = el.querySelector('[data-chat-reply]').getBoundingClientRect();
        return { centerDifference: Math.abs(bubble.y + bubble.height / 2 - reply.y - reply.height / 2), outside: el.classList.contains('is-own') ? reply.right <= bubble.left : reply.left >= bubble.right };
      });
      assert.equal(geometry.outside, true);
      assert.ok(geometry.centerDifference <= 1);
      await checkCenter(`[data-message-id="${id}"] [data-chat-reply]`);
      await reply.click();
      assert.equal(await page.locator('[data-chat-reply-preview]').isVisible(), true);
      await page.getByRole('button', { name: 'Cancel reply', exact: true }).click();
    }
    assert.equal(await page.locator('#teamChatHelp').count(), 0);
    assert.equal(await page.locator('[data-chat-body]').getAttribute('aria-describedby'), null);
    const info = await page.locator('.team-chat-info-btn').evaluate(el => {
      const rect = el.getBoundingClientRect(), style = getComputedStyle(el);
      return { width: rect.width, height: rect.height, background: style.backgroundColor, isGenericButton: el.classList.contains('btn') };
    });
    assert.deepEqual(info, { width: 22, height: 22, background: 'rgba(0, 0, 0, 0)', isGenericButton: false });
  });
}

test('composer grows to four lines then scrolls; Shift+Enter adds a newline and Enter sends', async t => {
  const { page, f } = await pageFor(t);
  const body = page.getByRole('textbox', { name: 'Message', exact: true });
  const metrics = () => body.evaluate(el => {
    const style = getComputedStyle(el);
    return { height: el.getBoundingClientRect().height, scrollHeight: el.scrollHeight, clientHeight: el.clientHeight,
      overflow: style.overflowY, lineHeight: parseFloat(style.lineHeight), maximum: parseFloat(style.lineHeight) * 4 + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom) + parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth) };
  });
  const initial = await metrics();
  await body.fill('First line');
  await body.press('End');
  await body.press('Shift+Enter');
  await page.keyboard.insertText('Second line');
  assert.equal(await body.inputValue(), 'First line\nSecond line');
  assert.equal(sends(f).length, 0);
  assert.ok((await metrics()).height > initial.height);
  await body.fill('Line 1\nLine 2\nLine 3\nLine 4');
  const fourLines = await metrics();
  const longDraft = Array.from({ length: 10 }, (_, i) => `Line ${i + 1}`).join('\n');
  await body.fill(longDraft);
  const capped = await metrics();
  assert.ok(capped.height <= capped.maximum + 1, JSON.stringify(capped));
    assert.ok(capped.height > capped.maximum - capped.lineHeight, 'Cap accommodates four lines, not three');
    assert.ok(Math.abs(capped.height - fourLines.height) <= 1, 'Additional lines do not grow the textarea');
  assert.ok(capped.scrollHeight > capped.clientHeight);
  assert.equal(capped.overflow, 'auto');
  await body.press('Control+End');
  assert.ok(await body.evaluate(el => el.scrollTop > 0), 'Textarea scrolls to its final line');
  await body.press('Enter');
  await page.locator('[data-message-id="1"]').waitFor();
  assert.equal(sends(f).length, 1);
  assert.equal(f.messages[0].body, longDraft);
  assert.equal(await body.inputValue(), '');
  assert.ok((await metrics()).height <= initial.height + 1);
  assert.equal((await metrics()).overflow, 'hidden');
});

test('40-message pages merge chronologically with overlapping response deduplication', async t => {
  const { page, f } = await pageFor(t, { messages: Array.from({ length: 85 }, (_, i) => message(i + 1)), overlap: true });
  assert.equal(await page.locator('[data-message-id]').count(), 40);
  assert.equal(await page.locator('[data-message-id]').first().getAttribute('data-message-id'), '46');
  await page.locator('[data-chat-older]').click();
  await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 80);
  assert.ok(f.gets.includes('?before=46'));
  await page.locator('[data-chat-older]').click();
  await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 85);
  assert.ok(f.gets.includes('?before=6'));
  assert.deepEqual(await page.locator('[data-message-id]').evaluateAll(nodes => nodes.map(n => Number(n.dataset.messageId))), Array.from({ length: 85 }, (_, i) => i + 1));
  assert.equal(await page.locator('[data-chat-older]').isHidden(), true);
  const count = f.gets.length;
  await page.clock.runFor(11000);
  assert.equal(f.gets.length, count, 'Browsing history pauses polling');
});

for (const unit of ['clinic', 'guidance', 'facilities']) {
  test(`${unit}: an older deep-linked message is focused and stays unread until Show latest`, { timeout: 15000 }, async t => {
    const { page, f } = await pageFor(t, { unit, targetMessageId: 55, realtime: 'ready',
      messages: Array.from({ length: 150 }, (_, i) => message(i + 1)) });
    const target = page.locator('#chat-message-55');
    assert.equal(await page.locator('[data-message-id]').count(), 40);
    assert.equal(await page.locator('[data-message-id]').first().getAttribute('data-message-id'), '16');
    assert.equal(await page.locator('[data-message-id]').last().getAttribute('data-message-id'), '55');
    assert.equal(await page.locator('#chat-message-150').count(), 0, 'Target is not replaced with the latest page');
    assert.equal(await page.locator('.is-targeted').count(), 1);
    assert.equal(await target.getAttribute('data-message-id'), '55');
    assert.equal(await target.evaluate(el => el.classList.contains('is-targeted') && document.activeElement === el), true);
    assert.deepEqual(await page.evaluate(() => window.fixtureTargetFocus), [{ id: 'chat-message-55', preventScroll: true }]);
    assert.equal(await target.evaluate(el => {
      const box = el.getBoundingClientRect(), log = el.closest('[data-chat-log]').getBoundingClientRect();
      return box.top >= log.top && box.bottom <= log.bottom;
    }), true, 'Target is scrolled into the chat log');
    assert.match(await page.locator('[data-chat-history-status]').textContent(), /paused/i);
    await page.clock.runFor(1000);
    assert.deepEqual(reads(f), [], 'Focusing the target must not acknowledge unseen messages');
    await page.locator('[data-chat-older]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-message-id]').length === 55);
    assert.deepEqual(f.gets, ['?before=16']);
    assert.equal(await target.evaluate(el => el.classList.contains('is-targeted')), true);
    await realtimeEvent(page, { event: 'team.chat.changed', data: { unit } });
    await page.clock.runFor(61000);
    assert.deepEqual(f.gets, ['?before=16'], 'History pauses polling and realtime refreshes');
    assert.deepEqual(reads(f), [], 'Loading history must not acknowledge the unseen backlog');
    assert.equal(f.lastRead, 0);
    await page.locator('[data-chat-latest]').click();
    await page.waitForFunction(() => !document.querySelector('[data-chat-history-status]').textContent.includes('paused'));
    await page.clock.runFor(500);
    await page.waitForFunction(() => document.querySelector('[data-message-id="150"]'));
    assert.equal(await page.locator('#chat-message-55').count(), 0);
    assert.ok(f.gets.includes(''), 'Show latest fetches the latest page without a target or stale cursor');
    const count = f.gets.length;
    const refreshed = page.waitForResponse(response => response.url().includes('/history'));
    await realtimeEvent(page, { event: 'team.chat.changed', data: { unit } });
    await page.clock.runFor(150);
    await refreshed;
    assert.equal(f.gets.length, count + 1, 'Show latest resumes live updates');
  });
}

test('initial unread acknowledgement includes only fully visible messages, not unseen backlog', async t => {
  const messages = Array.from({ length: 85 }, (_, i) => message(i + 1, { body: 'A tall message\n'.repeat(8) }));
  const initial = { messages: messages.slice(0, 40), has_more: true, next_before: null,
    last_read_id: 0, unread_count: 85, latest_id: 85 };
  const { page, f } = await pageFor(t, { messages, initial, afterMode: true });
  const seen = await page.locator('[data-chat-log]').evaluate(log => {
    const bounds = log.getBoundingClientRect();
    return [...log.querySelectorAll('[data-message-id]')].filter(el => {
      const box = el.getBoundingClientRect(); return box.top >= bounds.top && box.bottom <= bounds.bottom;
    }).map(el => Number(el.dataset.messageId));
  });
  assert.ok(seen.length > 0, 'Fixture must contain at least one fully visible unread message');
  const acknowledged = page.waitForResponse(r => r.url().endsWith('/read'));
  await page.clock.runFor(600);
  await acknowledged;
  assert.ok(reads(f).length > 0);
  assert.equal(Number(reads(f)[0].fields.last_read_id), Math.max(...seen));
  assert.ok(f.lastRead < 40, 'Offscreen messages in the first page are not acknowledged');
  assert.ok(f.lastRead < 85, 'Unloaded unread backlog is not acknowledged');
});

test('chat below the browser viewport stays unread until the browser scrolls it into view', async t => {
  const { page, f } = await pageFor(t, { messages: [message(1)] });
  const offscreen = await page.locator('[data-team-chat]').evaluate(root => {
    const spacer = document.createElement('div');
    spacer.style.height = `${window.innerHeight + 200}px`;
    root.before(spacer);
    window.scrollTo(0, 0);
    const log = root.querySelector('[data-chat-log]');
    const bounds = log.getBoundingClientRect();
    const box = log.querySelector('[data-message-id]').getBoundingClientRect();
    return { logTop: bounds.top, viewportHeight: innerHeight,
      messageInsidePane: box.bottom > bounds.top && box.bottom <= bounds.bottom };
  });
  assert.ok(offscreen.logTop > offscreen.viewportHeight, 'Entire chat log is below the browser viewport');
  assert.equal(offscreen.messageInsidePane, true, 'Message would qualify if only the pane rectangle were checked');
  await page.clock.runFor(700);
  assert.equal(reads(f).length, 0, 'Offscreen chat must not acknowledge messages');
  assert.equal(f.lastRead, 0);

  const acknowledged = page.waitForResponse(r => r.url().endsWith('/read'));
  await page.locator('[data-chat-log]').evaluate(log => {
    window.scrollTo(0, window.scrollY + log.getBoundingClientRect().top - 100);
  });
  await page.clock.runFor(700);
  await acknowledged;
  assert.ok(await page.locator('[data-message-id="1"]').evaluate(el => {
    const box = el.getBoundingClientRect();
    return box.bottom > 0 && box.bottom <= innerHeight;
  }), 'Browser scrolling reveals the message bottom');
  assert.equal(reads(f).length, 1);
  assert.equal(Number(reads(f)[0].fields.last_read_id), 1);
  assert.equal(f.lastRead, 1);
});

test('a message taller than the chat pane acknowledges its bottom, not its visible top', async t => {
  const { page, f } = await pageFor(t, { messages: [message(1, { body: 'Long message line\n'.repeat(100) })] });
  const initial = await page.locator('[data-chat-log]').evaluate(log => {
    log.scrollTop = 0;
    window.scrollTo(0, window.scrollY + log.getBoundingClientRect().top - 100);
    const bounds = log.getBoundingClientRect();
    const box = log.querySelector('[data-message-id]').getBoundingClientRect();
    return { tallerThanPane: box.height > log.clientHeight,
      topVisible: box.top >= Math.max(bounds.top, 0) && box.top < Math.min(bounds.bottom, innerHeight),
      bottomHidden: box.bottom > Math.min(bounds.bottom, innerHeight) };
  });
  assert.equal(initial.tallerThanPane, true);
  assert.equal(initial.topVisible, true);
  assert.equal(initial.bottomHidden, true);
  await page.clock.runFor(700);
  assert.equal(reads(f).length, 0, 'Seeing only the beginning of a tall message is not acknowledgement');
  assert.equal(f.lastRead, 0);

  const acknowledged = page.waitForResponse(r => r.url().endsWith('/read'));
  const bottom = await page.locator('[data-chat-log]').evaluate(log => {
    log.scrollTop = log.scrollHeight;
    const bounds = log.getBoundingClientRect();
    const box = log.querySelector('[data-message-id]').getBoundingClientRect();
    return { topClipped: box.top < bounds.top,
      bottomVisible: box.bottom > Math.max(bounds.top, 0) && box.bottom <= Math.min(bounds.bottom, innerHeight) };
  });
  assert.equal(bottom.topClipped, true, 'Tall message cannot fit wholly in the pane');
  assert.equal(bottom.bottomVisible, true);
  await page.clock.runFor(700);
  await acknowledged;
  assert.equal(reads(f).length, 1);
  assert.equal(Number(reads(f)[0].fields.last_read_id), 1);
  assert.equal(f.lastRead, 1);
});

test('hidden tab neither polls nor acknowledges; visibility resumes and navigation cleans up', async t => {
  const { page, f } = await pageFor(t, { hidden: true, messages: [message(1)] });
  await page.clock.runFor(16000);
  assert.equal(f.gets.length, 0);
  assert.equal(reads(f).length, 0);
  await page.evaluate(() => { window.fixtureHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
  await page.clock.runFor(700);
  await page.waitForFunction(() => document.querySelector('[data-chat-unread]').textContent === '0');
  assert.ok(f.gets.length > 0);
  assert.equal(f.lastRead, 1);
  await page.evaluate(() => { window.fixtureHidden = true; document.dispatchEvent(new Event('visibilitychange')); });
  const paused = { gets: f.gets.length, posts: f.posts.length };
  await page.clock.runFor(16000);
  assert.deepEqual({ gets: f.gets.length, posts: f.posts.length }, paused);
  await page.evaluate(() => {
    const root = document.querySelector('[data-team-chat]');
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root } }));
    root.remove(); window.fixtureHidden = false;
    document.dispatchEvent(new Event('visibilitychange'));
    document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document } }));
  });
  await page.clock.runFor(16000);
  assert.deepEqual({ gets: f.gets.length, posts: f.posts.length }, paused);
});

test('message, sender and task HTML stays literal; UTC timestamps display in Manila', async t => {
  const { page } = await pageFor(t, { unit: 'guidance', messages: [message(1, {
    body: attack, sender_name: attack, task_id: 12, task_title: attack
  })] });
  assert.equal(await page.locator('.team-chat-bubble p').textContent(), attack);
  assert.equal(await page.locator('.team-chat-message-header strong').textContent(), attack);
  assert.equal(await page.locator('.team-chat-task').textContent(), `Task #12: ${attack}`);
  assert.equal(await page.locator('.team-chat-task').getAttribute('href'), `${origin}/guidance/teams/12`);
  assert.equal(await page.locator('[data-chat-log] img, [data-chat-log] script').count(), 0);
  assert.equal(await page.evaluate(() => window.chatXss), undefined);
  assert.match(await page.locator('.team-chat-day').textContent(), /2 October 2026|October 2, 2026/);
  assert.match(await page.locator('.team-chat-message time').textContent(), /12:15\s*am/i);
  assert.equal(await page.locator('.team-chat-message time').getAttribute('datetime'), '2026-10-01T16:15:00.000Z');
});

test('empty chat has useful state and initialized composer', async t => {
  const { page } = await pageFor(t, { unit: 'facilities' });
  await page.getByText('No messages yet. Start an operational discussion.').waitFor();
  assert.equal(await page.locator('[data-chat-unread]').textContent(), '0');
  assert.equal(await page.locator('[data-chat-older]').isHidden(), true);
  assert.equal(await page.locator('[data-chat-send]').isEnabled(), true);
  assert.equal(await page.locator('[data-chat-body]').isEditable(), true);
});

for (const width of [360, 390]) {
  test(`narrow dark chat at ${width}px contains long text without document overflow`, async t => {
    const { page } = await pageFor(t, { width, dark: true,
      messages: [message(1, { body: 'W'.repeat(2000), task_id: 12, task_title: 'T'.repeat(200) })] });
    await page.clock.runFor(16);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.locator('[data-chat-body]').isVisible(), true);
    assert.equal(await page.locator('[data-chat-send]').isVisible(), true);
    assert.equal(await page.evaluate(() => localStorage.getItem('na-dark-mode')), '1');
  });
}

test('reply uses the unified tooltip and disposes it when messages refresh or chat closes', async t => {
  const { page } = await pageFor(t, { messages: [message(1)] });
  const reply = page.locator('[data-message-id="1"] [data-chat-reply]');
  assert.equal(await reply.getAttribute('data-bs-toggle'), 'tooltip');
  assert.equal(await reply.getAttribute('title'), null);
  await reply.hover();
  await page.locator('.tooltip.show .tooltip-inner').waitFor();
  assert.equal(await page.locator('.tooltip.show .tooltip-inner').textContent(), 'Reply');
  const style = await page.locator('.tooltip.show .tooltip-inner').evaluate(el => {
    const style = getComputedStyle(el); return { radius: style.borderRadius, color: style.color };
  });
  assert.deepEqual(style, { radius: '6px', color: 'rgb(255, 255, 255)' });
  await page.getByRole('button', { name: 'Show latest', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('.tooltip.show'));
  await page.locator('[data-chat-body]').hover();
  await reply.hover();
  await page.locator('.tooltip.show .tooltip-inner').waitFor();
  await reply.click();
  assert.equal(await page.locator('.tooltip.show').count(), 0, 'Reply tooltip dismisses on click');
  await page.getByRole('button', { name: 'Cancel reply', exact: true }).click();
  await reply.hover();
  await page.locator('.tooltip.show .tooltip-inner').waitFor();
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('[data-team-chat]') } })));
  assert.equal(await page.locator('.tooltip').count(), 0);
});

for (const unit of ['clinic', 'guidance', 'facilities']) {
  test(`${unit} dark chat uses neutral surfaces and readable message/channel text`, async t => {
    const { page } = await pageFor(t, { unit, dark: true, messages: [message(1), message(2, {
      sender_user_id: 1, body: '@Other member hello', mentions: [{ user_id: 2, start: 0, length: 13, name: 'Other member' }],
      reply_to: { id: 1, sender_name: 'Other member', body: 'Earlier message' }
    })] });
    const colors = await page.evaluate(() => {
      const selectors = ['.team-chat-message:not(.is-own) .team-chat-bubble', '.team-chat-message.is-own .team-chat-bubble', '.team-chat-channel', '.team-chat-sidebar .teams-eyebrow', '.team-chat-mention', '.team-chat-reply-quote'];
      return selectors.map(selector => { const style = getComputedStyle(document.querySelector(selector)); return { selector, text: style.color, background: style.backgroundColor }; });
    });
    const rgb = color => color.match(/[\d.]+/g).map(Number).slice(0, 3);
    for (const color of colors) {
      const text = rgb(color.text), background = rgb(color.background);
      assert.ok(Math.min(...text) >= 200, `${color.selector} needs light text: ${color.text}`);
      assert.equal(background[0], background[1], `${color.selector} should not have a maroon surface`);
      assert.equal(background[1], background[2]);
      assert.ok(Math.max(...background) <= 60, `${color.selector} should stay dark`);
    }
    await page.evaluate(() => { document.documentElement.removeAttribute('data-theme'); document.body.classList.add('dark-mode'); });
    assert.equal(await page.locator('.team-chat-message.is-own .team-chat-bubble').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(38, 38, 38)', 'Legacy dark-mode selector is supported');
  });
}

test('information button opens popover on desktop hover and mobile click/tap', async t => {
  const { page } = await pageFor(t, { width: 390 });
  const infoBtn = page.locator('.team-chat-info-btn');
  await infoBtn.waitFor();

  // Mobile tap:
  await infoBtn.click();
  await page.locator('.team-chat-info-popover.show').waitFor();
  const guidelines = await page.locator('.team-chat-info-popover .popover-body').textContent();
  assert.ok(guidelines.includes('Operational discussion only'));
  assert.equal(await page.locator('.team-chat-info-popover .popover-header').count(), 0, 'Guidelines must not be duplicated as a title');
  assert.equal((await page.locator('.team-chat-info-popover').textContent()).trim(), guidelines.trim());
  assert.equal(await infoBtn.getAttribute('title'), null);

  // Mobile tap outside to dismiss:
  await page.locator('[data-chat-log]').click();
  await page.locator('.team-chat-info-popover').waitFor({ state: 'detached' });

  // Mobile tap to reopen:
  await infoBtn.click();
  await page.locator('.team-chat-info-popover.show').waitFor();

  // Mobile tap on icon again to toggle close:
  await infoBtn.click();
  await page.locator('.team-chat-info-popover').waitFor({ state: 'detached' });
});
