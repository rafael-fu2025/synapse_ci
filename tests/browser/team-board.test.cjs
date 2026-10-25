'use strict';
// Run: node --test tests/browser/team-board.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser;
before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath });
});
after(async () => { await browser?.close(); });
async function fixture(t) {
  const page = await browser.newPage();
  t.after(() => page.close());
  await page.route('https://board.test/**', route => route.fulfill({ contentType: 'text/html', body: `
    <meta name="csrf-name" content="csrf_test"><meta name="csrf-token" content="old-token">
    <meta name="csrf-header" content="X-CSRF-TOKEN">
    <style>[data-team-board]{display:flex;gap:20px}section{width:220px;min-height:300px}article{padding:20px}</style>
    <div data-team-board><section data-task-column="todo"><article data-task-id="7" data-task-version="3"
      data-task-status="todo" data-task-move-url="/clinic/teams/7/move" draggable="true">
      <button type="button" data-task-drag-handle aria-label="Move task">Move</button><a href="/edit">Edit</a><input value="draft">
    </article><article data-task-id="8">Read only</article></section>
    <section data-task-column="in_progress" aria-label="In progress"></section>
    <section data-task-column="done"></section></div>` }));
  await page.goto('https://board.test/clinic/teams?mine=1');
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/csrf.js') });
  await page.evaluate(() => {
    window.posts = []; window.refreshes = []; window.notices = []; window.safe = true;
    window.SynapseToast = { show: (message, options) => notices.push({ message, options }) };
    window.SynapseMutations = {
      canRefresh: () => safe,
      refresh: async (...args) => {
        refreshes.push(args);
        const event = new Event('synapse:before-navigate', { cancelable: true });
        document.dispatchEvent(event);
        window.refreshBlocked = event.defaultPrevented;
        return false;
      }
    };
    window.fetch = (url, options) => {
      posts.push({ url, fields: Object.fromEntries(options.body), headers: options.headers, redirect: options.redirect });
      return new Promise((resolve, reject) => { window.reply = (status = 200, extra = {}) => resolve(new Response(JSON.stringify({
        success: status < 400, message: status === 409 ? 'Task changed. Reload before saving.' : 'Task status updated.',
        csrf: { name: 'csrf_test', hash: 'new-token' }, ...extra
      }), { status, headers: { 'Content-Type': 'application/json' } })); window.reject = reject; });
    };
  });
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/team-board.js') });
  return page;
}
async function drop(page, steps = 1) {
  const handle = page.locator('[data-task-drag-handle]');
  await handle.focus();
  await handle.press('Space');
  for (let i = 0; i < steps; i++) await handle.press('ArrowRight');
  await handle.press('Enter');
}
async function settle(page) { await page.waitForFunction(() => !document.querySelector('[data-team-board]').hasAttribute('aria-busy')); }

test('keyboard move posts version/status and CSRF once, locks until response, then refreshes current filters', async t => {
  const page = await fixture(t);
  await drop(page);
  assert.equal(await page.locator('[data-team-board]').getAttribute('aria-busy'), 'true');
  assert.equal(await page.locator('[data-team-board]').evaluate(el => el.inert), true);
  assert.equal(await page.evaluate(() => {
    const event = new Event('synapse:before-navigate', { cancelable: true }); document.dispatchEvent(event); return event.defaultPrevented;
  }), true);
  await page.evaluate(() => {
    const handle = document.querySelector('[data-task-drag-handle]');
    for (const key of [' ', 'ArrowRight', 'Enter']) handle.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
  });
  const posts = await page.evaluate(() => posts);
  assert.equal(posts.length, 1);
  assert.deepEqual(posts[0].fields, { version: '3', status: 'in_progress', csrf_test: 'old-token' });
  assert.equal(posts[0].headers['X-CSRF-TOKEN'], 'old-token');
  assert.equal(posts[0].headers['X-Synapse-Feedback'], 'toast');
  assert.equal(posts[0].headers.Accept, 'application/json');
  assert.equal(posts[0].redirect, 'error');
  assert.equal(await page.locator('[data-task-id="7"]').evaluate(el => el.parentElement.dataset.taskColumn), 'todo');
  await page.evaluate(() => reply());
  await page.waitForFunction(() => refreshes.length === 1);
  assert.deepEqual(await page.evaluate(() => refreshes[0]), ['https://board.test/clinic/teams?mine=1', 'Task status updated.', { key: 'team-task-move' }]);
  assert.equal(await page.evaluate(() => refreshBlocked), false);
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'new-token');
  await drop(page); // Failed reconciliation must not replay a confirmed write.
  assert.equal(await page.evaluate(() => posts.length), 1);
});

test('same-column, Escape, read-only cards, links and form controls do not write', async t => {
  const page = await fixture(t);
  await drop(page, 0);
  const handle = page.locator('[data-task-drag-handle]');
  await handle.press('Space'); await handle.press('ArrowRight'); await handle.press('Escape'); await handle.press('Enter');
  await page.locator('input').press('Space');
  assert.equal(await page.locator('[data-task-dragging]').count(), 0);
  assert.equal(await page.locator('[data-task-drop-target]').count(), 0);
  assert.equal(await page.evaluate(() => posts.length), 0);
  assert.equal(await page.locator('[data-task-id="8"]').getAttribute('draggable'), null);
  assert.equal(await page.evaluate(() => {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    const link = document.querySelector('a');
    link.addEventListener('click', e => { window.linkPrevented = e.defaultPrevented; e.preventDefault(); }, { once: true });
    link.dispatchEvent(event); return linkPrevented;
  }), false);
});

test('dirty drafts block the POST before mutation', async t => {
  const page = await fixture(t);
  await page.evaluate(() => { safe = false; });
  await drop(page);
  assert.equal(await page.evaluate(() => posts.length), 0);
  assert.match(await page.locator('[role="status"]').textContent(), /save other edits/);
  assert.equal(await page.locator('input').inputValue(), 'draft');
});

test('409 rotates CSRF, keeps DOM unchanged, and prevents stale retries', async t => {
  const page = await fixture(t);
  await drop(page); await page.evaluate(() => reply(409)); await settle(page);
  assert.match(await page.locator('[role="status"]').textContent(), /Task changed/);
  await drop(page);
  assert.equal(await page.evaluate(() => posts.length), 1);
  assert.equal(await page.evaluate(() => refreshes.length), 0);
  assert.equal(await page.locator('[data-task-id="7"]').getAttribute('data-task-version'), '3');
  assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), 'new-token');
});

test('known validation error unlocks and permits a deliberate retry; unknown outcome does not', async t => {
  const page = await fixture(t);
  await drop(page); await page.evaluate(() => reply(422)); await settle(page);
  await drop(page);
  assert.equal(await page.evaluate(() => posts.length), 2);
  await page.evaluate(() => reject(new TypeError('Network interrupted'))); await settle(page);
  await drop(page);
  assert.equal(await page.evaluate(() => posts.length), 2);
  assert.match(await page.locator('[role="status"]').textContent(), /may have been saved/);
});

test('native drag moves only between columns; cleanup and reinit are idempotent', async t => {
  const page = await fixture(t);
  await page.evaluate(() => {
    SynapseTeamBoard.init(document); SynapseTeamBoard.init(document);
    const card = document.querySelector('[data-task-id="7"]');
    const column = document.querySelector('[data-task-column="done"]');
    const dataTransfer = new DataTransfer();
    card.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer }));
    column.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer }));
    column.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }));
  });
  assert.equal(await page.evaluate(() => posts[0].fields.status), 'done');
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('[data-team-board]') } }));
    reply();
  });
  assert.equal(await page.locator('[role="status"]').count(), 0);
  assert.equal(await page.evaluate(() => refreshes.length), 0);
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document } })));
  assert.equal(await page.locator('[role="status"]').count(), 1);
});

test('interactive descendants never initiate a native task drag', async t => {
  const page = await fixture(t);
  const results = await page.evaluate(() => ['a', 'input'].map(selector => {
    const source = document.querySelector(selector);
    source.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'mouse' }));
    const event = new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() });
    document.querySelector('[data-task-id="7"]').dispatchEvent(event);
    return event.defaultPrevented;
  }));
  assert.deepEqual(results, [true, true]);
  assert.equal(await page.locator('[data-task-dragging]').count(), 0);
  assert.equal(await page.evaluate(() => posts.length), 0);
});

test('unsafe endpoint and non-JSON responses cannot navigate or replay a move', async t => {
  const page = await fixture(t);
  await page.locator('[data-task-id="7"]').evaluate(el => { el.dataset.taskMoveUrl = 'https://evil.test/move'; });
  await drop(page);
  assert.equal(await page.evaluate(() => posts.length), 0);
  await page.locator('[data-task-id="7"]').evaluate(el => { el.dataset.taskMoveUrl = '/clinic/teams/7/move'; });
  await page.evaluate(() => { window.fetch = async () => { posts.push({}); return new Response('<html>Login</html>', { headers: { 'Content-Type': 'text/html' } }); }; });
  await drop(page); await settle(page);
  await drop(page);
  assert.equal(await page.evaluate(() => posts.length), 1);
  assert.equal(page.url(), 'https://board.test/clinic/teams?mine=1');
  assert.equal(await page.evaluate(() => refreshes.length), 0);
});

test('touch pointer handle selects destination without moving the DOM optimistically', async t => {
  const page = await fixture(t);
  await page.evaluate(() => {
    const handle = document.querySelector('[data-task-drag-handle]');
    // Synthetic pointer events have no browser capture; stub capture only, retaining real hit testing.
    handle.setPointerCapture = () => {};
    const start = handle.getBoundingClientRect();
    const end = document.querySelector('[data-task-column="done"]').getBoundingClientRect();
    const send = (type, x, y) => handle.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true,
      pointerId: 4, pointerType: 'touch', isPrimary: true, button: 0, clientX: x, clientY: y }));
    send('pointerdown', start.x + 5, start.y + 5);
    send('pointermove', end.x + 20, end.y + 20);
    send('pointerup', end.x + 20, end.y + 20);
  });
  assert.equal(await page.evaluate(() => posts[0].fields.status), 'done');
  assert.equal(await page.locator('[data-task-id="7"]').evaluate(el => el.parentElement.dataset.taskColumn), 'todo');
  await page.evaluate(() => reply());
  await settle(page);
});
