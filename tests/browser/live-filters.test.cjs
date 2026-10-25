'use strict';
// Synthetic full documents, production controller, no application/database writes.
// Run serially with the other browser suites: node --test tests/browser/live-filters.test.cjs
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const escape = value => String(value).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);
const region = '[data-filter-region="results"]';
const result = `${region} [data-result]`;

function render(url) {
  const p = url.searchParams;
  const value = name => escape(p.get(name) || '');
  const endpoint = origin + url.pathname;
  const link = query => escape(endpoint + '?' + new URLSearchParams(query));
  const key = 'portal-' + url.pathname.split('/').pop();
  return `<!doctype html><html><head><meta name="csrf-name" content="csrf"><meta name="csrf-token" content="fresh"><meta name="csrf-header" content="X-CSRF"></head>
<body data-navigation-scope="portal:fixture"><main><section data-live-filters data-filter-url="${endpoint}" data-filter-key="${key}"${p.has('live') ? ' data-live-events="portal.appointments.changed"' : ''}>
<form method="get" data-filter-form action="${endpoint}"><input name="q" type="search" value="${value('q')}" aria-label="Search">
<input name="category" value="${value('category')}" aria-label="Category">
<select name="status" aria-label="Status"><option value="">All</option><option value="open" ${p.get('status') === 'open' ? 'selected' : ''}>Open</option></select>
<input name="day" type="hidden" value="${value('day')}"><input name="mine" type="hidden" value="${value('mine')}">
<button type="submit">Apply</button></form><a data-filter-reset href="${endpoint}">Reset</a>
<p data-filter-status>Showing ${value('q') || 'all'} results</p><div data-filter-error hidden><span data-filter-error-text></span><button data-filter-retry>Retry</button></div>
<div data-filter-region="results"><p data-result>${value('q') || 'all'}|${value('category')}|${value('status')}|${value('day')}|${value('mine')}|${p.get('page') || '1'}</p>
<form method="post" action="/never-write"><input aria-label="Draft" name="title"><button>Save</button></form><div contenteditable="true" aria-label="Editor"></div></div>
<nav data-filter-region="navigation"><a data-filter-link href="${link({ q: p.get('q') || '', status: 'open', mine: '1', day: '2026-10-21', page: '2' })}">Next</a><a href="/ordinary">Ordinary</a></nav>
<div data-filter-region="summary">Summary ${value('q')}</div><div data-filter-region="notice">Notice ${value('q')}</div>
</section></main><div id="appFormModal" class="modal"><form method="post"><input name="external"></form></div>
<script src="/assets/js/live-filters.js"></script></body></html>`;
}

before(async () => {
  server = http.createServer((req, res) => {
    if (req.method !== 'GET') return res.writeHead(405).end();
    const url = new URL(req.url, origin || 'http://localhost');
    if (url.pathname === '/assets/js/live-filters.js') return res.writeHead(200, { 'Content-Type': 'text/javascript' }).end(fs.readFileSync(path.join(root, 'public/assets/js/live-filters.js')));
    if (/^\/portal\/(surveys|announcements)$/.test(url.pathname)) return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url));
    return res.writeHead(404).end();
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Playwright Chromium is required');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally {
    if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
});

async function open(t, kind = 'surveys', navigation = false, search = '') {
  const context = await browser.newContext();
  const reads = [], mutations = [], errors = [];
  const transport = { intercept: null };
  await context.addInitScript(({ navigation }) => {
    window.calls = { history: [], lifecycle: [], alpine: [], htmx: 0, csrf: [], confirmations: 0, sync: [] };
    window.confirmAnswer = false;
    window.SynapseConfirm = { confirm: async () => { ++window.calls.confirmations; return window.confirmAnswer; } };
    window.SynapseCsrf = { headers: () => ({ 'X-CSRF': 'current' }), fromResponse: () => window.calls.csrf.push('response'), sync: data => window.calls.csrf.push(data) };
    window.SynapsePageLifecycle = { destroy: node => window.calls.lifecycle.push('destroy:' + node.dataset.filterRegion), init: node => window.calls.lifecycle.push('init:' + node.dataset.filterRegion) };
    window.Alpine = { mutateDom: fn => fn(), destroyTree: () => window.calls.alpine.push('destroy'), initTree: () => window.calls.alpine.push('init') };
    window.htmx = { process: () => ++window.calls.htmx };
    if (navigation) window.SynapseNavigation = { updateHistory: (url, mode) => {
      window.calls.history.push({ url, mode });
      if (window.navigationBlocked) return false;
      history[mode === 'push' ? 'pushState' : 'replaceState']({ ...history.state, owner: 'navigation' }, '', url);
      return true;
    } };
    document.addEventListener('DOMContentLoaded', () => {
      window.originalForm = document.querySelector('[data-filter-form]');
      window.originalSearch = document.querySelector('[name="q"]');
      for (const type of ['bd-select:sync', 'dp:sync']) document.querySelector('[name="day"]').addEventListener(type, () => window.calls.sync.push(type));
    });
  }, { navigation });
  await context.route('**/*', async route => {
    const request = route.request();
    if (request.method() !== 'GET') { mutations.push(request.method()); return route.abort(); }
    if (request.headers()['x-requested-with'] === 'XMLHttpRequest') {
      const url = new URL(request.url());
      assert.equal(request.headers()['x-csrf'], 'current');
      assert.equal(url.searchParams.has('fragment'), false);
      reads.push(url);
      if (transport.intercept && await transport.intercept(route, url)) return;
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(mutations, []); assert.deepEqual(errors, []); });
  await page.goto(`${origin}/portal/${kind}${search}`);
  await page.waitForFunction(() => window.SynapseLiveFilters && window.originalForm);
  return { page, reads, transport };
}
async function shown(page, text) { await page.waitForFunction(({ result, text }) => document.querySelector(result)?.textContent === text, { result, text }); }
async function idle(page) { await page.waitForFunction(() => !document.querySelector('[data-live-filters]').hasAttribute('aria-busy')); }
function hold(transport, query) {
  let release, started;
  const ready = new Promise(resolve => { started = resolve; });
  const wait = new Promise(resolve => { release = resolve; });
  transport.intercept = async (route, url) => {
    if (url.searchParams.get('q') !== query) return false;
    started(); await wait;
    await route.fulfill({ contentType: 'text/html', body: render(url) }).catch(() => {});
    return true;
  };
  return { ready, release };
}

for (const kind of ['surveys', 'announcements']) test(`${kind}: debounced named text, IME, persistent focus/caret and no initialization reads`, async t => {
  const { page, reads } = await open(t, kind);
  await page.locator('[name="day"]').dispatchEvent('input');
  await page.waitForTimeout(350); assert.equal(reads.length, 0);
  const search = page.locator('[name="q"]');
  await search.fill('al'); await search.press('End'); await search.pressSequentially('pha', { delay: 35 });
  await shown(page, 'alpha|||||1');
  assert.equal(reads.length, 1);
  assert.deepEqual(await search.evaluate(el => ({ same: el === window.originalSearch && el.form === window.originalForm, focused: document.activeElement === el, caret: el.selectionStart })), { same: true, focused: true, caret: 5 });
  await page.locator('[name="category"]').fill('news');
  await shown(page, 'alpha|news||||1');
  await search.dispatchEvent('compositionstart'); await search.fill('日本');
  await page.waitForTimeout(350); assert.equal(reads.length, 2);
  await search.dispatchEvent('compositionend'); await shown(page, '日本|news||||1');
  assert.equal(reads.length, 3);
});

test('immediate select/reset/date pairs, keyed links and explicit GET submit', async t => {
  const { page, reads } = await open(t);
  await page.locator('[name="status"]').selectOption('open'); await shown(page, 'all||open|||1');
  assert.equal(reads.length, 1);
  await page.evaluate(() => { const day = document.querySelector('[name="day"]'); day.value = '2026-10-20'; day.dispatchEvent(new Event('input', { bubbles: true })); day.dispatchEvent(new Event('change', { bubbles: true })); });
  await shown(page, 'all||open|2026-10-20||1'); assert.equal(reads.length, 2);
  await page.getByText('Next', { exact: true }).click(); await shown(page, 'all||open|2026-10-21|1|2');
  assert.equal(await page.locator('[name="mine"]').inputValue(), '1');
  assert.equal(await page.locator('[name="day"]').inputValue(), '2026-10-21');
  assert.ok((await page.evaluate(() => window.calls.sync)).includes('dp:sync'));
  await page.locator('[name="category"]').fill('typed'); await page.getByText('Apply', { exact: true }).click();
  await shown(page, 'all|typed|open|2026-10-21|1|1');
  assert.equal(new URL(page.url()).searchParams.has('page'), false);
  await page.getByText('Reset', { exact: true }).click(); await shown(page, 'all|||||1');
  assert.equal(await page.locator('[name="category"]').inputValue(), '');
  await page.waitForTimeout(350); assert.equal(reads.length, 5);
});

test('Reset uses its explicit URL and synchronizes canonical select/text/hidden defaults only on success', async t => {
  const { page, reads, transport } = await open(t);
  await page.evaluate(() => {
    const form = document.querySelector('[data-filter-form]');
    form.elements.q.value = 'keep'; form.elements.category.value = 'news'; form.elements.status.value = 'open';
    form.requestSubmit();
  });
  await shown(page, 'keep|news|open|||1');
  const reset = new URL(page.url()); reset.search = 'day=2026-10-22&mine=1&page=3';
  await page.locator('[data-filter-reset]').evaluate((el, href) => { el.href = href; }, reset.href);
  let release, started;
  const ready = new Promise(resolve => { started = resolve; });
  const wait = new Promise(resolve => { release = resolve; });
  transport.intercept = async (route, url) => {
    started(); await wait;
    const canonical = new URL(url); canonical.searchParams.set('status', 'all');
    const body = render(canonical).replace('<option value="">All', '<option value="all" selected>All');
    await route.fulfill({ contentType: 'text/html', body }); return true;
  };
  await page.getByText('Reset', { exact: true }).click(); await ready;
  assert.equal(await page.locator('[name="q"]').inputValue(), 'keep');
  assert.equal(await page.locator('[name="status"]').inputValue(), 'open');
  assert.equal(await page.locator('[name="day"]').inputValue(), '');
  assert.equal(reads.at(-1).href, reset.href, 'Reset must request the parent URL without merging form values');
  release(); await shown(page, 'all||all|2026-10-22|1|3');
  assert.equal(await page.locator('[name="q"]').inputValue(), '');
  assert.equal(await page.locator('[name="category"]').inputValue(), '');
  assert.equal(await page.locator('[name="status"]').inputValue(), 'all');
  assert.equal(await page.locator('[name="day"]').inputValue(), '2026-10-22');
  assert.equal(new URL(page.url()).searchParams.get('status'), 'all');
  await page.waitForTimeout(350); assert.equal(reads.length, 2);
});

test('failed Reset and dirty-paused Reset leave every persistent control unchanged', async t => {
  const { page, reads, transport } = await open(t);
  await page.evaluate(() => {
    const form = document.querySelector('[data-filter-form]');
    form.elements.q.value = 'keep'; form.elements.category.value = 'news'; form.elements.status.value = 'open';
    form.elements.day.value = '2026-10-20'; form.elements.mine.value = '1'; form.requestSubmit();
  });
  await shown(page, 'keep|news|open|2026-10-20|1|1');
  const controls = () => page.locator('[data-filter-form]').evaluate(form => Array.from(new FormData(form)));
  const before = await controls(); const oldUrl = page.url();
  transport.intercept = async route => { await route.fulfill({ status: 403, contentType: 'text/html', body: 'denied' }); return true; };
  await page.getByText('Reset', { exact: true }).click();
  await page.locator('[data-filter-error]').waitFor({ state: 'visible' }); await idle(page);
  assert.deepEqual(await controls(), before); assert.equal(page.url(), oldUrl);
  assert.equal(await page.locator(result).textContent(), 'keep|news|open|2026-10-20|1|1');
  await page.getByLabel('Draft', { exact: true }).fill('unsaved');
  await page.getByText('Reset', { exact: true }).click();
  await page.waitForTimeout(350);
  assert.deepEqual(await controls(), before); assert.equal(reads.length, 2);
  assert.equal(await page.getByLabel('Draft', { exact: true }).inputValue(), 'unsaved');
  assert.equal(await page.evaluate(() => window.calls.confirmations), 0);
});

for (const paging of [true, false]) test(`${paging ? 'pagination' : 'tab'} link during debounce preserves newer q/category and starts at page one`, async t => {
  const { page, reads } = await open(t);
  await page.evaluate(paging => {
    const form = document.querySelector('[data-filter-form]');
    const link = document.querySelector('[data-filter-link]');
    link.href = '?q=stale&category=stale&status=open&mine=1' + (paging ? '&page=9' : '');
    for (const [name, value] of [['q', 'new query'], ['category', 'fresh']]) {
      form.elements[name].value = value;
      form.elements[name].dispatchEvent(new Event('input', { bubbles: true }));
    }
    link.click();
  }, paging);
  await shown(page, 'new query|fresh|open||1|1');
  assert.equal(reads[0].searchParams.get('q'), 'new query');
  assert.equal(reads[0].searchParams.get('category'), 'fresh');
  assert.equal(reads[0].searchParams.has('page'), false);
  assert.equal(await page.locator('[name="q"]').inputValue(), 'new query');
  assert.equal(await page.locator('[name="category"]').inputValue(), 'fresh');
  assert.equal(await page.locator('[name="status"]').inputValue(), 'open');
  await page.waitForTimeout(350); assert.equal(reads.length, 1);
});

test('q/category invalidate pending immediately and stale reads cannot commit', async t => {
  const { page, transport } = await open(t);
  const slow = hold(transport, 'slow');
  await page.locator('[name="q"]').fill('slow'); await slow.ready;
  await page.locator('[name="category"]').fill('new'); slow.release();
  await page.waitForTimeout(100); assert.equal(await page.locator(result).textContent(), 'all|||||1');
  transport.intercept = null;
  await shown(page, 'slow|new||||1');
  assert.equal(new URL(page.url()).searchParams.get('category'), 'new');
});

test('403/non-HTML/redirect and invalid document contracts retain all old regions; retry works', async t => {
  const { page, transport } = await open(t);
  const initialUrl = page.url();
  const bad = [
    () => ({ status: 403, contentType: 'text/html', body: 'denied' }),
    () => ({ contentType: 'application/json', body: '{}' }),
    () => ({ status: 302, headers: { location: '/login' }, body: '' }),
    url => ({ body: render(url).replace('portal:fixture', 'another-scope') }),
    url => ({ body: render(url).replace('data-filter-key="portal-surveys"', 'data-filter-key="wrong"') }),
    url => ({ body: render(url).replace('data-filter-url="' + origin, 'data-filter-url="https://wrong.example') }),
    url => ({ body: render(url).replace('data-filter-region="notice"', 'data-filter-region="unknown"') }),
    url => ({ body: render(url).replace('<p data-result>', '<template><template><iframe></iframe></template></template><p data-result>') }),
    url => ({ body: render(url).replace('<p data-result>', '<template><script>window.bad=true</script></template><p data-result>') }),
    url => ({ body: render(url).replace('<p data-result>', '<template><embed></template><p data-result>') }),
    url => ({ body: render(url).replace('<p data-result>', '<template><base href="/bad"></template><p data-result>') }),
    url => ({ body: render(url).replace('data-filter-form', 'data-missing-form') })
  ];
  for (const [index, response] of bad.entries()) {
    transport.intercept = async (route, url) => { await route.fulfill({ contentType: 'text/html', ...response(url) }); return true; };
    await page.locator('[name="q"]').fill('bad-' + index);
    await page.locator('[data-filter-error]').waitFor({ state: 'visible' }); await idle(page);
    assert.equal(page.url(), initialUrl);
    assert.equal(await page.locator(result).textContent(), 'all|||||1');
    assert.equal(await page.locator('[data-filter-region="summary"]').textContent(), 'Summary ');
    assert.equal(await page.evaluate(() => window.bad), undefined);
    // Ensure the next iteration waits for its own error rather than the preceding one.
    await page.locator('[data-filter-error]').evaluate(el => { el.hidden = true; });
  }
  transport.intercept = null;
  await page.getByText('Retry', { exact: true }).evaluate(el => { el.parentElement.hidden = false; });
  await page.getByText('Retry', { exact: true }).click(); await shown(page, `bad-${bad.length - 1}|||||1`);
});

const hint = (page, event = 'portal.appointments.changed') => page.evaluate(event => document.dispatchEvent(new CustomEvent('synapse:realtime-event', { detail: { event, data: {} } })), event);

test('opted-in region refetches silently on its realtime hint and ignores others', async t => {
  const { page, reads } = await open(t, 'surveys', false, '?live=1&q=seed');
  assert.equal(reads.length, 0);
  await hint(page, 'notifications.changed'); await hint(page, 'queue.invalidated');
  await page.waitForTimeout(500); assert.equal(reads.length, 0);
  await hint(page); await hint(page); // bursts coalesce
  await page.waitForFunction(() => document.querySelector('[data-filter-status]').textContent.includes('seed'));
  await page.waitForTimeout(400); assert.equal(reads.length, 1);
  assert.equal(reads[0].searchParams.get('q'), 'seed');
  assert.equal(await page.locator('[data-filter-error]').isHidden(), true);
});

test('live hints never replace drafts, open editors or a region that did not opt in', async t => {
  const { page, reads } = await open(t, 'surveys', false, '?live=1');
  await page.getByLabel('Draft', { exact: true }).fill('keep me');
  await hint(page); await page.waitForTimeout(700); assert.equal(reads.length, 0);
  assert.equal(await page.getByLabel('Draft', { exact: true }).inputValue(), 'keep me');
  const plain = await open(t, 'announcements');
  await hint(plain.page); await plain.page.waitForTimeout(700); assert.equal(plain.reads.length, 0);
});

test('background refresh failures stay silent but lost access is reported', async t => {
  const { page, reads, transport } = await open(t, 'surveys', false, '?live=1');
  let status = 500;
  transport.intercept = async route => { await route.fulfill({ status, contentType: 'text/plain', body: 'no' }); return true; };
  await hint(page); await page.waitForFunction(() => true); await page.waitForTimeout(700);
  assert.equal(reads.length, 1); assert.equal(await page.locator('[data-filter-error]').isHidden(), true);
  assert.equal(await page.locator(result).textContent(), 'all|||||1');
  status = 403; await hint(page);
  await page.locator('[data-filter-error]').waitFor({ state: 'visible' });
  assert.equal(await page.locator(result).textContent(), 'all|||||1');
});

test('dirty POST drafts pause automatic reads; only explicit submit uses shared confirmation', async t => {
  const { page, reads } = await open(t);
  await page.getByLabel('Draft', { exact: true }).fill('keep me');
  await page.locator('[name="q"]').fill('new'); await page.waitForTimeout(350);
  assert.equal(reads.length, 0); assert.equal(await page.evaluate(() => window.calls.confirmations), 0);
  await page.getByText('Apply', { exact: true }).click();
  await page.waitForFunction(() => window.calls.confirmations === 1);
  assert.equal(await page.getByLabel('Draft', { exact: true }).inputValue(), 'keep me');
  await page.evaluate(() => { window.confirmAnswer = true; });
  await page.getByText('Apply', { exact: true }).click(); await shown(page, 'new|||||1');
  assert.equal(await page.getByLabel('Draft', { exact: true }).inputValue(), '');
});

test('editing during a pending read prevents replacement and URL changes', async t => {
  const { page, reads, transport } = await open(t);
  const slow = hold(transport, 'slow'); const oldUrl = page.url();
  await page.locator('[name="q"]').fill('slow'); await slow.ready;
  await page.getByLabel('Draft', { exact: true }).fill('pending draft'); slow.release();
  await page.waitForTimeout(400); assert.equal(reads.length, 1);
  assert.equal(page.url(), oldUrl); assert.equal(await page.locator(result).textContent(), 'all|||||1');
  assert.equal(await page.getByLabel('Draft', { exact: true }).inputValue(), 'pending draft');
});

test('external modal and active editors pause reads, including a modal opened in flight', async t => {
  const { page, reads, transport } = await open(t);
  await page.evaluate(() => document.querySelector('#appFormModal').classList.add('show'));
  await page.locator('[name="q"]').fill('modal'); await page.waitForTimeout(350); assert.equal(reads.length, 0);
  await page.getByText('Apply', { exact: true }).click(); assert.equal(reads.length, 0);
  await page.evaluate(() => document.querySelector('#appFormModal').classList.remove('show'));
  const slow = hold(transport, 'slow');
  await page.locator('[name="q"]').fill('slow'); await slow.ready;
  await page.evaluate(() => { const modal = document.querySelector('#appFormModal'); modal.dispatchEvent(new Event('show.bs.modal', { bubbles: true })); modal.classList.add('show'); });
  slow.release(); await page.waitForTimeout(350); assert.equal(await page.locator(result).textContent(), 'all|||||1');
  await page.evaluate(() => { document.querySelector('#appFormModal').classList.remove('show'); document.querySelector('[contenteditable]').focus(); document.querySelector('[data-filter-form]').requestSubmit(); });
  await page.waitForTimeout(100); assert.equal(reads.length, 1);
});

test('history ownership, initialization hooks, refusal to commit and lifecycle cleanup', async t => {
  const { page, reads } = await open(t, 'announcements', true);
  await page.evaluate(() => { window.SynapseLiveFilters.init(); window.SynapseLiveFilters.init(); });
  await page.locator('[name="status"]').selectOption('open'); await shown(page, 'all||open|||1');
  assert.equal(reads.length, 1);
  const calls = await page.evaluate(() => window.calls);
  assert.equal(calls.history[0].mode, 'push'); assert.equal(calls.htmx, 4);
  assert.equal(calls.lifecycle.length, 8); assert.equal(calls.alpine.length, 8);
  assert.ok(calls.csrf.some(item => item.hash === 'fresh'));
  await page.evaluate(() => window.dispatchEvent(new PopStateEvent('popstate')));
  await page.waitForTimeout(350); assert.equal(reads.length, 1, 'navigation owns Back/Forward');
  await page.evaluate(() => { window.navigationBlocked = true; });
  await page.locator('[name="q"]').fill('blocked'); await page.locator('[data-filter-error]').waitFor({ state: 'visible' });
  assert.equal(await page.locator(result).textContent(), 'all||open|||1');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('main') } })));
  await page.locator('[name="q"]').fill('orphan'); await page.waitForTimeout(350); assert.equal(reads.length, 2);
  await page.evaluate(() => { window.navigationBlocked = false; document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document.querySelector('main') } })); });
  await page.locator('[name="q"]').fill('remounted'); await shown(page, 'remounted||open|||1'); assert.equal(reads.length, 3);
});

test('fallback Back/Forward restores controls; only marked unmodified links are intercepted', async t => {
  const { page, reads } = await open(t);
  await page.locator('[name="q"]').fill('history'); await shown(page, 'history|||||1');
  await page.getByText('Next', { exact: true }).click(); await shown(page, 'history||open|2026-10-21|1|2');
  await page.getByText('Reset', { exact: true }).click(); await shown(page, 'all|||||1');
  await page.goBack(); await shown(page, 'history||open|2026-10-21|1|2');
  assert.equal(await page.locator('[name="q"]').inputValue(), 'history');
  assert.equal(await page.locator('[name="status"]').inputValue(), 'open');
  await page.goForward(); await shown(page, 'all|||||1');
  const count = reads.length;
  const prevented = await page.evaluate(() => {
    const events = [
      [document.querySelector('[data-filter-link]'), { ctrlKey: true }],
      [document.querySelector('a[href="/ordinary"]'), {}]
    ];
    return events.map(([link, options]) => {
      const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...options });
      // Observe after the controller, but prevent real navigation in this synthetic check.
      let intercepted;
      document.addEventListener('click', e => { intercepted = e.defaultPrevented; e.preventDefault(); }, { once: true });
      link.dispatchEvent(event); return intercepted;
    });
  });
  assert.deepEqual(prevented, [false, false]); assert.equal(reads.length, count);
});
