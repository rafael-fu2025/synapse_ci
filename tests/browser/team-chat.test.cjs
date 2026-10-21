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
  sender_name: 'Other member', task_id: null, task_title: null, client_nonce: null,
  created_at: '2026-10-01 16:15:00', ...changes });
const csrf = { name: 'csrf_test_name', hash: 'chat-refreshed-token' };
function summary(f) {
  return { last_read_id: f.lastRead, latest_id: f.messages.at(-1)?.id || 0,
    unread_count: f.messages.filter(m => m.id > f.lastRead && m.sender_user_id !== 1).length };
}
function history(f, url) {
  let rows = f.messages;
  const after = url.searchParams.get('after'), before = url.searchParams.get('before');
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
      afterMode: f.afterMode }), encoding: 'utf8', timeout: 10000
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
        const fields = Object.fromEntries(new URLSearchParams(Buffer.concat(chunks).toString()));
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
              task_id: fields.task_id ? Number(fields.task_id) : null, task_title: fields.task_id ? attack : null });
            f.messages.push(saved);
          }
          reply({ message: saved });
        } else res.writeHead(404).end();
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
async function pageFor(t, { messages = [], width = 1280, dark = false, hidden = false, unit = 'clinic', ...options } = {}) {
  const key = String(++sequence);
  const f = { messages, lastRead: 0, posts: [], gets: [], ...options };
  fixtures.set(key, f);
  const context = await browser.newContext({ viewport: { width, height: 900 }, timezoneId: 'America/Los_Angeles' });
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  await context.addInitScript(({ dark, hidden }) => {
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    window.fixtureHidden = hidden;
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => window.fixtureHidden });
  }, { dark, hidden });
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  await page.clock.install();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); fixtures.delete(key); assert.deepEqual(errors, []); });
  await page.goto(`${origin}/${unit}/teams/chat?fixture=${key}`);
  await page.waitForFunction(() => window.SynapseTeamChat && !document.querySelector('[data-team-chat]').hasAttribute('data-initial'));
  return { page, f };
}
const sends = f => f.posts.filter(p => p.path.endsWith('/send'));
const reads = f => f.posts.filter(p => p.path.endsWith('/read'));


test('lost send response retries identical nonce and text and creates one logical message', async t => {
  const { page, f } = await pageFor(t);
  let lost = false;
  await page.route('**/chat/send', async route => {
    if (!lost) { lost = true; await route.fetch(); await route.abort('failed'); }
    else await route.continue();
  });
  await page.locator('[data-chat-body]').fill('Keep this exact text\nand newline');
  await page.locator('[data-chat-task]').fill('12');
  await page.locator('[data-chat-send]').click();
  await page.getByRole('button', { name: 'Retry send', exact: true }).waitFor();
  assert.equal(await page.locator('[data-chat-body]').getAttribute('readonly'), '');
  assert.equal(await page.locator('[data-chat-task]').getAttribute('readonly'), '');
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
  await page.locator('[data-chat-task]').fill('99');
  await page.locator('[data-chat-send]').click();
  await page.locator('[data-chat-error]').filter({ hasText: 'Fixture validation error' }).waitFor();
  assert.equal(await page.locator('[data-chat-body]').inputValue(), 'Original draft');
  assert.equal(await page.locator('[data-chat-task]').inputValue(), '99');
  assert.equal(await page.locator('[data-chat-body]').isEditable(), true);
  assert.equal(await page.locator('[data-chat-task]').isEditable(), true);
  assert.equal(await page.locator('[data-chat-send]').isEnabled(), true);
  f.reject = false;
  await page.locator('[data-chat-body]').fill('Corrected draft');
  await page.locator('[data-chat-task]').fill('');
  await page.locator('[data-chat-send]').click();
  await page.waitForFunction(() => document.querySelector('[data-chat-body]').value === '');
  assert.notEqual(sends(f)[0].fields.client_nonce, sends(f)[1].fields.client_nonce);
  assert.equal(f.messages.length, 1);
  assert.equal(f.messages[0].body, 'Corrected draft');
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
