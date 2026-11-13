'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { once } = require('node:events');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
let browser;
let server;
let origin;
let version = 0;
let failRead = false;
let postCount = 0;
let readCount = 0;

function pageHtml() {
  const read = version > 0;
  return `<!doctype html><html><head><title>Announcement detail</title>
    <meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="token-${version}">
    <meta name="csrf-header" content="X-Fixture-CSRF">
    <meta name="synapse-refresh-regions-enabled" content="1">
    <meta name="synapse-refresh-region-owners" content="portal-announcement-detail.1">
    </head><body data-navigation-scope="announcement-pilot">
    <header id="header">Header</header><aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link active" href="/portal/announcements/1">Announcement</a></nav></aside>
    <main id="main"><section data-refresh-owner="portal-announcement-detail.1">
      <article><h1>Campus notice</h1><p id="article-body">Authoritative article body</p>
        <span data-refresh-region="read-state">${read ? 'Read' : 'Unread'}</span>
        <div data-refresh-region="read-action">${read ? '<p id="completion">Marked read</p>' : '<form id="read-form"><button id="read-button">Mark as read</button></form>'}</div>
      </article>
    </section></main>
    <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/loading.js"></script><script src="/assets/js/csrf.js"></script><script src="/assets/js/toast.js"></script>
    <script src="/assets/js/page-lifecycle.js"></script><script src="/assets/js/refresh-regions.js"></script>
    <div id="page-styles" hidden></div><div id="page-scripts" hidden></div></body></html>`;
}

before(async () => {
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://fixture.local');
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(publicRoot, '.' + url.pathname);
      if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': 'text/javascript' }); res.end(fs.readFileSync(file)); return;
    }
    if (req.method === 'POST') {
      postCount++; req.resume(); version++;
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true, message: 'Announcement marked as read.', redirect: origin + '/portal/announcements/1',
        refresh: { mode: 'regions', owner: 'portal-announcement-detail.1', regions: ['read-state', 'read-action'], url: origin + '/portal/announcements/1' },
        csrf: { name: 'fixture_csrf', hash: 'post-token' } })); return;
    }
    readCount++;
    if (failRead) { res.writeHead(503, { 'Content-Type': 'text/plain' }); res.end('Unavailable'); return; }
    res.writeHead(200, { 'Content-Type': 'text/html', 'X-CSRF-TOKEN': `token-${version}`, 'X-CSRF-NAME': 'fixture_csrf' }); res.end(pageHtml());
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true });
});

after(async () => {
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

async function fixture(t) {
  version = 0; failRead = false; postCount = 0; readCount = 0;
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.stack || error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/portal/announcements/1');
  await page.waitForFunction(() => Boolean(window.SynapseMutations));
  await page.evaluate(() => {
    window.refreshEvents = [];
    for (const name of ['refresh_committed', 'refresh_failed', 'refresh_canceled', 'refresh_security_escalated']) {
      document.addEventListener(name, event => refreshEvents.push({ name, detail: event.detail }));
    }
    const form = document.querySelector('#read-form');
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const response = await fetch('/portal/announcements/1/read', { method: 'POST', headers: { Accept: 'application/json' } });
      const payload = await response.json();
      await SynapseMutations.refresh(payload.redirect, payload.message, { form, descriptor: payload.refresh });
    });
  });
  return page;
}

test('portal mark-read preserves article, main, owner and region identity while replacing both region children', async t => {
  const page = await fixture(t);
  await page.evaluate(() => {
    window.identities = {
      main: document.querySelector('#main'),
      owner: document.querySelector('[data-refresh-owner]'),
      state: document.querySelector('[data-refresh-region="read-state"]'),
      action: document.querySelector('[data-refresh-region="read-action"]'),
      article: document.querySelector('#article-body'),
      actionChild: document.querySelector('[data-refresh-region="read-action"]').firstElementChild,
    };
  });
  await page.locator('#read-button').click();
  await page.locator('#completion').waitFor();
  assert.equal(postCount, 1);
  assert.equal(readCount, 2);
  assert.deepEqual(await page.evaluate(() => ({
    main: identities.main === document.querySelector('#main'),
    owner: identities.owner === document.querySelector('[data-refresh-owner]'),
    state: identities.state === document.querySelector('[data-refresh-region="read-state"]'),
    action: identities.action === document.querySelector('[data-refresh-region="read-action"]'),
    article: identities.article === document.querySelector('#article-body'),
    child: identities.actionChild !== document.querySelector('[data-refresh-region="read-action"]').firstElementChild,
    status: document.querySelector('[data-refresh-region="read-state"]').textContent,
  })), { main: true, owner: true, state: true, action: true, article: true, child: true, status: 'Read' });
});

test('confirmed mark-read with failed GET never replays POST and exposes GET-only retry', async t => {
  const page = await fixture(t);
  failRead = true;
  await page.locator('#read-button').click();
  await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').waitFor();
  assert.equal(postCount, 1);
  assert.equal(readCount, 2);
  assert.equal(await page.locator('#read-button').isDisabled(), true);
  failRead = false;
  await page.locator('.synapse-toast[data-type="warning"] .synapse-toast-action').click();
  await page.locator('#completion').waitFor();
  assert.equal(postCount, 1);
  assert.equal(readCount, 3);
});
