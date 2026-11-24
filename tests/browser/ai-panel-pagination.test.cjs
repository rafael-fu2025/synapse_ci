'use strict';

// Offline regression harness: all Chromium requests are intercepted. No PHP boot,
// provider, HTTP listener, configured database or browser-side payload cache.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const origin = 'http://127.0.0.1:47993';
const source = fs.readFileSync(path.join(root, 'public/assets/js/ai-insights.js'), 'utf8');
const bootstrap = fs.readFileSync(path.join(root, 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), 'utf8');
const defaults = { insights_page: '1', insights_per_page: '20', jobs_page: '1', jobs_per_page: '20', insights_status: 'all' };
let browser;

function state(query = {}) { return { ...defaults, ...query }; }
function url(base, query, changes = {}) { return base + '?' + new URLSearchParams({ ...query, ...changes }); }
function pager(base, query, list) {
  const key = list + '_page';
  const size = list + '_per_page';
  return `<div data-ai-pagination="${list}" data-ai-key="pagination-${list}">
    <nav aria-label="${list} pages"><a href="${url(base, query, { [key]: String(Number(query[key]) + 1) })}" data-table-page="${Number(query[key]) + 1}">Next ${list}</a>
    <a href="${url(base, query, { [key]: '99' })}" data-table-page="99">Last ${list}</a></nav>
    <form data-ai-page-size method="get" action="${base}">
      ${Object.entries({ ...query, [key]: '1' }).filter(([name]) => name !== size).map(([name, value]) => `<input type="hidden" name="${name}" value="${value}">`).join('')}
      <label for="fixture-${size}">${list} per page</label><select id="fixture-${size}" name="${size}" data-native-select>
        ${['20', '25', '50', '100'].map(value => `<option${query[size] === value ? ' selected' : ''}>${value}</option>`).join('')}
      </select><button>Apply ${list}</button>
    </form></div>`;
}
function panel(base, query, options = {}) {
  const page = Number(query.insights_page);
  const id = 100 + page;
  return `<div data-ai-page-state data-ai-pending="${options.pending === false ? 'false' : 'true'}" ${Object.entries(query).map(([key, value]) => `data-ai-${key.replaceAll('_', '-')}="${value}"`).join(' ')}>
    <h2>Department drafts</h2><p data-ai-count-scope>45 scoped drafts; 7 active requests across all pages.</p>
    <select data-ai-analysis-selector aria-label="Analysis"><option>clinic.activity</option><option>clinic.complaints</option></select>
    <form method="post" action="${base}/generate" data-ai-action><input type="hidden" name="analysis_code" value="clinic.activity" data-ai-analysis-value><button type="submit">Generate draft</button></form>
    <button data-ai-refresh>Refresh status</button>
    <a role="button" href="${url(base, query, { insights_page: '1', insights_status: 'reviewed' })}" data-ai-status="reviewed" data-navigation="false">Reviewed 22</a>
    <article data-ai-insight-card="${id}" data-ai-insight-version="1"><p>Private current page ${page}</p><button data-ai-open-review data-ai-insight-id="${id}" data-ai-insight-version="1">Open draft ${id}</button></article>
    <template data-ai-review-template="${id}" data-ai-insight-version="1" data-title="Draft ${id}"><p>Private modal page ${page}</p></template>
    <template data-ai-review-actions="${id}" data-ai-insight-version="1"><form method="post" action="${base}/${id}/review" data-ai-action data-ai-review-form><input type="hidden" name="version" value="1"><input type="hidden" name="decision" value="accept"><button type="submit">Accept insight</button></form></template>
    ${pager(base, query, 'insights')}<p>Request page ${query.jobs_page}</p>${pager(base, query, 'jobs')}</div>`;
}
function documentHtml(base, query, options) {
  return `<!doctype html><html><head><meta charset="utf-8"><title>AI pagination fixture</title><script src="/bootstrap.js"></script>
    <style>body{font:16px sans-serif;padding:20px} a,button,select{margin:6px} [data-ai-pagination]{padding:12px;border:1px solid #ccc;margin:12px 0}.modal{display:none;position:fixed;inset:0;background:#fff;padding:20px;z-index:10}.modal.show{display:block}[hidden]{display:none!important}</style></head><body>
    <aside id="shell">Unrelated shell</aside><section data-ai-workspace data-panel-url="${base}/panel"><p data-ai-feedback hidden></p><div data-ai-panel>${panel(base, query, options)}</div><div data-ai-baseline></div>
    <div class="modal" data-ai-review-modal tabindex="-1"><div class="modal-dialog"><div class="modal-content"><h2 data-ai-review-modal-title></h2><p data-ai-review-stale hidden></p><div data-ai-review-modal-body></div><div data-ai-review-modal-actions></div><button type="button" data-bs-dismiss="modal">Close review</button></div></div></div></section><script src="/ai-insights.js"></script></body></html>`;
}
async function until(check, message) {
  const end = performance.now() + 8000;
  do { if (await check()) return; await delay(15); } while (performance.now() < end);
  assert.fail(message);
}
function gate() { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; }
const ok = html => ({ contentType: 'application/json', body: JSON.stringify({ success: true, html }) });

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath) && process.platform === 'win32') {
    const directory = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = (fs.existsSync(directory) ? fs.readdirSync(directory) : []).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(directory, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath && fs.existsSync(executablePath), 'An already-installed Chromium is required; this test never installs one.');
  browser = await chromium.launch({ headless: true, executablePath });
});
after(async () => { await browser?.close(); });

async function open(t, options = {}) {
  const base = options.base || '/clinic/insights';
  const initial = state(options.query);
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  const h = { page, context, base, gets: [], posts: [], errors: [], unexpected: [], holds: [], completed: 0, aborted: [] };
  h.reply = entry => ok(panel(base, state(entry.query), options));
  page.on('pageerror', error => h.errors.push(error.message));
  page.on('requestfinished', request => { if (request.url().startsWith(origin + base + '/')) ++h.completed; });
  page.on('requestfailed', request => {
    if (request.url().startsWith(origin + base + '/')) { ++h.completed; h.aborted.push(request.url()); }
  });
  await context.route('**/*', async route => {
    const request = route.request();
    const requestUrl = new URL(request.url());
    if (requestUrl.origin !== origin) { h.unexpected.push(request.url()); return route.abort('blockedbyclient'); }
    if (request.isNavigationRequest() && requestUrl.pathname === base) return route.fulfill({ contentType: 'text/html', body: documentHtml(base, state(Object.fromEntries(requestUrl.searchParams)), options) });
    if (requestUrl.pathname === '/bootstrap.js') return route.fulfill({ contentType: 'text/javascript', body: bootstrap });
    if (requestUrl.pathname === '/ai-insights.js') return route.fulfill({ contentType: 'text/javascript', body: source });
    if (requestUrl.pathname === base + '/panel' && request.method() === 'GET') {
      const entry = { path: requestUrl.pathname, query: Object.fromEntries(requestUrl.searchParams), headers: request.headers() };
      h.gets.push(entry);
      try { return await route.fulfill(await h.reply(entry)); }
      catch (error) { if (!/closed|disposed|Invalid InterceptionId/i.test(error.message)) h.errors.push(error.message); return; }
    }
    if (requestUrl.pathname.startsWith(base + '/') && request.method() === 'POST') {
      h.posts.push({ path: requestUrl.pathname, query: requestUrl.search, body: request.postData() });
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, message: 'Synthetic action saved.' }) });
    }
    h.unexpected.push(request.url()); return route.abort('blockedbyclient');
  });
  if (!options.realTime) {
    await page.clock.install({ time: new Date('2026-11-23T00:00:00Z') });
    await page.clock.pauseAt(new Date('2026-11-23T00:00:01Z'));
  }
  t.after(async () => {
    h.holds.forEach(item => item.resolve());
    await context.close();
    assert.deepEqual(h.unexpected, [], 'No provider or unmocked network');
    assert.deepEqual(h.errors, [], 'No uncaught page or route errors');
  });
  await page.goto(origin + url(base, initial));
  await page.waitForFunction(() => Boolean(window.SynapseAiInsights));
  return h;
}
async function idle(h) { await until(() => h.completed >= h.gets.length + h.posts.length, 'Intercepted requests finish'); }
async function closeReview(h) {
  await h.page.getByRole('button', { name: 'Close review', exact: true }).click();
  await h.page.locator('[data-ai-review-modal]').waitFor({ state: 'hidden' });
}
async function next(h, list = 'insights') {
  const before = h.gets.length;
  await h.page.getByRole('link', { name: `Next ${list}`, exact: true }).click();
  await until(() => h.gets.length === before + 1, 'Paging GET expected');
  await idle(h);
}
async function snapshot(h, name) {
  const directory = path.join(root, 'writable/pagination-preview');
  fs.mkdirSync(directory, { recursive: true });
  const image = await h.page.screenshot({ path: path.join(directory, `ai-${name}.png`) });
  assert.ok(image.length > 1000, 'Rendered UI screenshot is available for visual inspection');
}

test('independent cursors, sizes and status survive polling and mutation; history contains no payload', { timeout: 20000 }, async t => {
  const h = await open(t, { query: { jobs_page: '2', jobs_per_page: '25' } });
  await next(h);
  assert.deepEqual(h.gets.at(-1).query, state({ insights_page: '2', jobs_page: '2', jobs_per_page: '25' }));
  assert.equal(await h.page.getByText('Private current page 2', { exact: true }).count(), 1);
  assert.equal(await h.page.locator('[data-ai-review-template]').count(), 1);
  assert.equal(await h.page.getByText('Private current page 1', { exact: true }).count(), 0);
  await snapshot(h, 'page-state');
  const pageQuery = Object.fromEntries(new URL(h.page.url()).searchParams);
  assert.deepEqual(pageQuery, h.gets.at(-1).query);
  await h.page.getByLabel('jobs per page', { exact: true }).selectOption('100');
  await h.page.getByRole('button', { name: 'Apply jobs', exact: true }).click();
  await until(() => h.gets.length === 2, 'Size read'); await idle(h);
  assert.equal(h.gets.at(-1).query.insights_page, '2');
  assert.equal(h.gets.at(-1).query.jobs_page, '1');
  assert.equal(h.gets.at(-1).query.jobs_per_page, '100');
  await h.page.getByRole('button', { name: 'Reviewed 22', exact: true }).click();
  await until(() => h.gets.length === 3, 'Scoped status read'); await idle(h);
  assert.equal(h.gets.at(-1).query.insights_page, '1');
  assert.equal(h.gets.at(-1).query.insights_status, 'reviewed');
  const selected = { ...h.gets.at(-1).query };
  await h.page.clock.runFor(5001); await until(() => h.gets.length === 4, 'Full-scope pending poll'); await idle(h);
  assert.deepEqual(h.gets.at(-1).query, selected);
  await h.page.getByRole('button', { name: 'Generate draft', exact: true }).click();
  await until(() => h.posts.length === 1 && h.gets.length === 5, 'Mutation reconciles selected page'); await idle(h);
  assert.equal(h.posts[0].query, '');
  assert.deepEqual(h.gets.at(-1).query, selected);
  assert.equal(await h.page.locator('[data-ai-count-scope]').textContent(), '45 scoped drafts; 7 active requests across all pages.');
  assert.equal(await h.page.evaluate(() => JSON.stringify(history.state).includes('Private')), false);
  await h.page.goBack();
  await until(() => h.gets.length === 6, 'Back restores validated page cursor'); await idle(h);
  assert.equal(h.gets.at(-1).query.insights_page, '2');
});

test('open review blocks history paging and a closed review opens only the selected-page draft', { timeout: 20000 }, async t => {
  const h = await open(t, { realTime: true, pending: false });
  await next(h);
  await h.page.getByRole('button', { name: 'Open draft 102', exact: true }).click();
  assert.equal(await h.page.locator('[data-ai-review-modal]').isVisible(), true);
  await h.page.goBack();
  await delay(50);
  assert.equal(h.gets.length, 1);
  assert.match(await h.page.locator('[data-ai-review-stale]').textContent(), /Close the draft review before changing pages/);
  assert.equal(await h.page.getByText('Private modal page 2', { exact: true }).isVisible(), true);
  assert.equal(await h.page.getByRole('button', { name: 'Accept insight', exact: true }).isEnabled(), true);
  await snapshot(h, 'review-veto');
  await closeReview(h);
  await next(h);
  await h.page.getByRole('button', { name: 'Open draft 103', exact: true }).click();
  assert.equal(await h.page.getByText('Private modal page 3', { exact: true }).isVisible(), true);
  assert.equal(await h.page.getByText('Private modal page 2', { exact: true }).count(), 0);
  await closeReview(h);
  assert.equal(h.gets.length, 2);
  assert.equal(new URL(h.page.url()).searchParams.get('insights_page'), '3');
  // Native modifier-click destinations require a non-routed browser harness.
  // The source's primary-unmodified guard preserves those links; do not force a popup URL.
});

test('out-of-order read is aborted and only SQL-clamped response owns future refresh state', { timeout: 20000 }, async t => {
  const h = await open(t, { base: '/facilities/insights', query: { jobs_page: '2' } });
  const held = gate(); h.holds.push(held);
  h.reply = async entry => {
    if (entry.query.insights_page === '2') await held.promise;
    const nextState = state(entry.query);
    if (nextState.insights_page === '99') nextState.insights_page = '3';
    return ok(panel(h.base, nextState));
  };
  await h.page.getByRole('link', { name: 'Next insights', exact: true }).click();
  await until(() => h.gets.length === 1, 'First page read held');
  await h.page.getByRole('link', { name: 'Last insights', exact: true }).click();
  await until(() => h.gets.length === 2, 'New cursor read');
  await h.page.getByText('Private current page 3', { exact: true }).waitFor();
  held.resolve(); await idle(h);
  assert.equal(h.aborted.some(value => new URL(value).searchParams.get('insights_page') === '2'), true);
  assert.equal(await h.page.getByText('Private current page 2', { exact: true }).count(), 0);
  assert.equal(new URL(h.page.url()).searchParams.get('insights_page'), '3');
  await h.page.getByRole('button', { name: 'Refresh status', exact: true }).click();
  await until(() => h.gets.length === 3, 'Clamped follow-up read'); await idle(h);
  assert.equal(h.gets.at(-1).query.insights_page, '3');
  assert.equal(h.gets.at(-1).query.jobs_page, '2');
  assert.equal(h.gets.at(-1).path, '/facilities/insights/panel');
  assert.equal(h.posts.length, 0);
  await snapshot(h, 'race-clamp');
});
