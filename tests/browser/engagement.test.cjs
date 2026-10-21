'use strict';

// Real CI/NiceAdmin views and production scripts; local HTTP responses only. No DB/MIS.
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
let browser, server, origin, failSave = false, persistTargeting = false, bankUnavailable = false, bankMulti = false;
const posts = [], cache = new Map(), savedTargets = new Map();
function render(requestPath) {
  if (!persistTargeting && cache.has(requestPath)) return cache.get(requestPath);
  const base = '/' + requestPath.split('/').slice(1, 3).join('/').split('?')[0];
  const result = spawnSync('php', ['tests/_support/render-engagement-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: requestPath, savedTarget: persistTargeting ? savedTargets.get(base) : undefined }), encoding: 'utf8', timeout: 10000
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  assert.match(result.stdout, /<div/);
  if (!persistTargeting) cache.set(requestPath, result.stdout);
  return result.stdout;
}
function fields(body, contentType) {
  if (!contentType.includes('multipart/form-data')) return [...new URLSearchParams(body)];
  return [...body.matchAll(/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([\s\S]*?)(?=\r\n--)/g)].map(m => [m[1], m[2]]);
}
before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      if (url.pathname === '/guidance/question-bank/catalog') {
        const item = { id: 7, revision: 3, category: 'Service feedback', audience: 'all', review_note: 'Author-only review note',
          question: { type: 'single', label: 'Reusable <img src=x onerror="window.fixtureXss=1"> question?', required: true, options: ['Morning', 'Afternoon'] } };
        const page = Number(url.searchParams.get('page') || 1);
        const second = { ...item, id: 8, revision: 4, question: { ...item.question, label: 'Second bank question' } };
        const third = { ...item, id: 9, revision: 5, question: { ...item.question, label: 'Third bank question' } };
        const items = bankMulti ? (page === 2 ? [third] : [item, second]) : [item];
        res.writeHead(bankUnavailable ? 503 : 200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(bankUnavailable ? { success: false, message: 'Bank temporarily unavailable' } : { success: true, items, page: bankMulti ? page : 1, pages: bankMulti ? 2 : 1, total: bankMulti ? 3 : 1 }));
        return;
      }
      if (req.method === 'POST') {
        const chunks = []; for await (const chunk of req) chunks.push(chunk);
        posts.push({ path: url.pathname, fields: fields(Buffer.concat(chunks).toString(), req.headers['content-type'] || ''), headers: req.headers });
        const base = '/' + url.pathname.split('/').slice(1, 3).join('/');
        if (persistTargeting && !failSave && /\/(store|update)$/.test(url.pathname)) {
          const sent = posts.at(-1).fields;
          const selected = name => sent.filter(([key]) => key === name + '[]').map(([, value]) => value);
          savedTargets.set(base, { audience: Object.fromEntries(sent).audience,
            target_year_levels: selected('target_year_levels').length ? JSON.stringify(selected('target_year_levels')) : null,
            target_student_categories: selected('target_student_categories').length ? JSON.stringify(selected('target_student_categories')) : null });
        }
        if (req.headers['x-requested-with'] === 'XMLHttpRequest') {
          res.writeHead(failSave ? 422 : 200, { 'Content-Type': 'application/json',
                      ...(!failSave && url.pathname.endsWith('/submit') ? { 'Set-Cookie': 'engagementSubmitted=1; Path=/; SameSite=Strict' } : {}) });
          res.end(JSON.stringify({ success: !failSave, message: failSave ? 'Please correct the engagement form.' : 'Saved',
            redirect: origin + (url.pathname.endsWith('/submit') ? base + '/1?submitted=1' : base),
            csrf: { name: 'csrf_test_name', hash: 'retry-token' } }));
        } else { res.writeHead(303, { Location: base + (url.pathname.endsWith('/read') ? '/1?read=1' : '') }); res.end(); }
        return;
      }
      if (/^\/(clinic|guidance|portal)\/(announcements|surveys)(?:\/(?:create|1(?:\/edit)?))?$/.test(url.pathname)) {
        if (url.pathname.startsWith('/portal/surveys') && /(?:^|;\s*)engagementSubmitted=1(?:;|$)/.test(req.headers.cookie || '')) url.searchParams.set('submitted', '1');
                res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(render(url.pathname + url.search)); return;
      }
      const dir = path.join(root, 'public'), file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(dir + path.sep)) return res.writeHead(403).end();
      fs.readFile(file, (error, content) => {
        if (error) return res.writeHead(404).end();
        res.writeHead(200, { 'Content-Type': ({ '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2' })[path.extname(file)] || 'application/octet-stream' }); res.end(content);
      });
    } catch (error) { res.writeHead(500).end(String(error)); }
  });
  server.requestTimeout = 10000; server.headersTimeout = 10000;
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});
async function pageFor(t, route, theme, mobile = false) {
  failSave = false;
  bankUnavailable = false;
  bankMulti = false;
  const context = await browser.newContext({ viewport: mobile ? { width: 375, height: 812 } : { width: 1280, height: 900 } });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage(); page.setDefaultTimeout(6000);
    page.setDefaultNavigationTimeout(20000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.text().includes('Alpine Expression Error')) errors.push(message.text()); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + route);
  await page.waitForFunction(() => !!window.SynapseConfirm);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return page;
}
// Observe only this page's full-document filter reads, not assets or modal fragments.
function portalFilterReads(page, pathname) {
  const reads = [];
  page.on('request', request => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === pathname && request.headers()['x-requested-with'] === 'XMLHttpRequest') {
      assert.equal(url.searchParams.has('fragment'), false);
      reads.push(url);
    }
  });
  return reads;
}
async function portalFilterUpdate(page, action, history = false) {
  const previous = await page.locator('[data-filter-region="results"]').evaluateHandle(el => el.firstElementChild);
  const response = page.waitForResponse(response => response.request().method() === 'GET' &&
    (history ? response.request().resourceType() === 'fetch' : response.request().headers()['x-requested-with'] === 'XMLHttpRequest') &&
    new URL(response.url()).pathname === new URL(page.url()).pathname);
  await action();
  const received = await response;
  assert.equal(received.status(), 200);
  assert.match(await received.text(), /<html[\s>]/i);
  // History can change before Alpine's mutateDom has replaced the children.
  await page.waitForFunction(old => !old.isConnected && !document.querySelector('[data-live-filters][aria-busy="true"]'), previous);
  await previous.dispose();
}

for (const theme of ['light', 'dark']) for (const mobile of [false, true]) {
  for (const kind of ['announcements', 'surveys']) {
    test(`${theme} ${mobile ? 'mobile' : 'desktop'} portal ${kind}: live search, tabs, reset and real history`, { timeout: 30000 }, async t => {
      const pathname = `/portal/${kind}`;
      const page = await pageFor(t, pathname, theme, mobile);
      const reads = portalFilterReads(page, pathname), postCount = posts.length;
      const isNotice = kind === 'announcements';
      const search = page.locator(isNotice ? '#notice-search' : '#survey-search');
      const root = page.locator(`[data-live-filters][data-filter-key="portal-${kind}"]`);
      const identities = await root.evaluateHandle(el => [el, el.querySelector('[data-filter-form]'), el.querySelector('input[type="search"]')]);
      await page.waitForFunction(() => !!window.SynapseNavigation);
      assert.equal(await root.locator('.pe-content-card').count(), isNotice ? 2 : 3);
      await portalFilterUpdate(page, async () => {
        await search.fill('Camp');
        await search.fill(isNotice ? 'Campus notice' : 'Campus wellbeing');
      });
      assert.equal(reads.length, 1, 'Typing is coalesced into one debounced GET');
      assert.equal(await root.locator('.pe-content-card').count(), 1);
      assert.equal(await search.evaluate(el => el === document.activeElement), true);
      assert.equal(await root.locator('.pe-card-title').innerText(), isNotice ? 'Campus notice' : 'Campus wellbeing');
      await portalFilterUpdate(page, () => root.locator('[data-filter-form] [data-filter-reset]').click());
      assert.equal(await search.inputValue(), '');
      assert.equal(await root.locator('.pe-content-card').count(), isNotice ? 2 : 3);
      if (isNotice) {
        // Exercise the production custom dropdown rather than its hidden select.
        await root.getByRole('combobox', { name: /^Published by:/ }).click();
        await portalFilterUpdate(page, () => root.getByRole('option', { name: 'Guidance', exact: true }).click());
        assert.equal(reads.at(-1).searchParams.get('unit'), 'guidance');
        assert.equal(await root.locator('.pe-card-title').innerText(), 'Guidance support hours');
      }
      const status = isNotice ? 'read' : 'submitted';
      await portalFilterUpdate(page, () => root.locator(`[data-filter-link][href*="status=${status}"]`).click());
      assert.equal(await root.locator('.pe-content-card').count(), 1);
      assert.equal(await root.locator('.pe-state').innerText(), isNotice ? 'Read' : 'Submitted');
      assert.equal(await root.locator('[aria-current="page"][data-filter-link]').getAttribute('href').then(href => new URL(href).searchParams.get('status')), status);
      await portalFilterUpdate(page, () => root.locator('[data-filter-form] [data-filter-reset]').click());
      assert.equal(await root.locator('.pe-content-card').count(), isNotice ? 2 : 3);
      assert.equal(await page.evaluate(nodes => nodes[0] === document.querySelector('[data-live-filters]') &&
        nodes[1] === document.querySelector('[data-filter-form]') && nodes[2] === document.querySelector('input[type="search"]'), identities), true);
      await identities.dispose();
      // Do not mock navigation: these traverse entries owned by SynapseNavigation.
      await portalFilterUpdate(page, () => page.goBack(), true);
      assert.equal(await page.locator('[data-filter-form] [name="status"]').inputValue(), status);
      assert.equal(await page.locator('.pe-content-card').count(), 1);
      assert.equal(await page.locator('.pe-state').innerText(), isNotice ? 'Read' : 'Submitted');
      await portalFilterUpdate(page, () => page.goForward(), true);
      assert.equal(await page.locator('[data-filter-form] [name="status"]').inputValue(), 'all');
      assert.equal(await page.locator('.pe-content-card').count(), isNotice ? 2 : 3);
      assert.equal(reads.length, isNotice ? 5 : 4, 'One filter XHR per search/dropdown/tab/reset; history uses full navigation GETs');
      assert.equal(posts.length, postCount, 'Filtering and history never POST');
      await noOverflow(page);
    });
  }
}

async function open(page, name) {
  await page.getByRole('link', { name, exact: true }).first().click();
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return modal?.querySelector('form') && bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  });
  assert.equal(await page.locator('#appFormModal').isVisible(), true);
}
async function noOverflow(page, selector = '#main') {
  const geometry = await page.locator(selector).evaluate(el => {
    const rect = el.getBoundingClientRect();
    return { left: rect.left, right: rect.right, viewport: innerWidth, scroll: el.scrollWidth, client: el.clientWidth,
      document: document.documentElement.scrollWidth, width: document.documentElement.clientWidth };
  });
  assert.ok(geometry.left >= -1 && geometry.right <= geometry.viewport + 1, JSON.stringify(geometry));
  assert.ok(geometry.scroll <= geometry.client + 1, JSON.stringify(geometry));
  assert.ok(geometry.document <= geometry.width + 1, JSON.stringify(geometry));
}
async function modalVisuals(page, theme) {
  await noOverflow(page, '#appFormModal .modal-content');
  await noOverflow(page, '#appFormModal .modal-body');
  const style = await page.locator('#appFormModal .modal-content').evaluate(el => {
    const css = getComputedStyle(el); return { background: css.backgroundColor, color: css.color };
  });
  assert.notEqual(style.background, 'rgba(0, 0, 0, 0)');
  assert.notEqual(style.background, style.color);
  const rgb = style.background.match(/\d+/g).slice(0, 3).map(Number);
  assert.equal(rgb.reduce((a, b) => a + b) / 3 < 128, theme === 'dark', JSON.stringify(style));
}
const modal = '#appFormModal';
const question = (page, index) => page.locator(`${modal} fieldset`).nth(index);
async function choose(container, label, option) {
  // Alpine-added rows retain a usable native select until the widget is initialized.
  const select = container.locator('select').filter({ has: container.page().locator('option', { hasText: option }) }).first();
  if (await select.isVisible()) await select.selectOption({ label: option });
  else {
    await container.getByRole('combobox', { name: new RegExp('^' + label + ':') }).click();
    await container.getByRole('option', { name: option, exact: true }).click();
  }
}
async function cancelConfirm(page) {
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-cancel]').click();
  await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
}
async function acceptConfirm(page) {
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click();
}
for (const theme of ['light', 'dark']) for (const mobile of [false, true]) {
  const variant = `${theme} ${mobile ? 'mobile' : 'desktop'}`;
  for (const unit of ['clinic', 'guidance']) {
    const base = `/${unit}/announcements`;
    test(`${variant} ${unit} announcements: actual modal, payload, 422 retry and immutable actions`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, base, theme, mobile);
      await noOverflow(page);
      const rows = page.locator('#main tbody tr');
      assert.equal(await rows.nth(0).getByRole('link', { name: 'Edit draft' }).count(), 1);
      assert.equal(await rows.nth(1).getByRole('link', { name: 'Edit draft' }).count(), 0);
      assert.equal(await rows.nth(2).locator('form').count(), 0);
      await open(page, 'New draft'); await modalVisuals(page, theme);
      assert.equal(await page.locator(`${modal} form`).evaluate(f => f.checkValidity()), false);
      const literal = '<b>Plain text</b> & wellbeing';
      await page.getByLabel('Title', { exact: true }).fill('Support day');
      await page.getByLabel('Announcement text').fill(literal);
      await choose(page.locator(modal), 'Audience', 'Employees (including staff self-service)');
      await page.getByLabel('Visible from').fill('2026-10-20T09:00');
      await page.getByLabel('Expires at').fill('2026-10-21T17:00');
      const before = posts.length; failSave = true;
      await page.getByRole('button', { name: 'Save draft', exact: true }).click();
      await page.locator('[data-modal-error]:not(.d-none)').waitFor();
      assert.equal(posts.length, before + 1);
      const sent = Object.fromEntries(posts.at(-1).fields);
      assert.equal(posts.at(-1).path, base + '/store');
      assert.deepEqual(Object.keys(sent).sort(), ['audience', 'body', 'csrf_test_name', 'ends_at', 'starts_at', 'title']);
      assert.equal(sent.body, literal); assert.equal(sent.audience, 'employees');
      assert.equal(sent.starts_at, '2026-10-20T09:00'); assert.equal(sent.ends_at, '2026-10-21T17:00');
      assert.ok(sent.csrf_test_name);
      assert.equal(await page.getByLabel('Announcement text').inputValue(), literal);
      failSave = false; await page.getByRole('button', { name: 'Save draft', exact: true }).click();
      await page.waitForFunction(() => !document.querySelector('#appFormModal'));
      assert.equal(posts.length, before + 2);
      assert.deepEqual(Object.fromEntries(posts.at(-1).fields), { ...sent, csrf_test_name: 'retry-token' });
      await open(page, 'Edit draft');
      assert.equal(await page.locator(`${modal} [name=version]`).inputValue(), '7');
      assert.match(await page.getByLabel('Announcement text').inputValue(), /<img src=x/);
      assert.equal(await page.locator(`${modal} img`).count(), 0);
      await page.locator(`${modal} [data-app-modal-close]`).first().click();
      await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    });
  }
  test(`${variant} survey builder: visual controls, add/remove/reindex and exact draft payload`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, '/guidance/surveys', theme, mobile);
    await noOverflow(page); await open(page, 'Create survey'); await modalVisuals(page, theme);
    assert.equal(await page.locator(`${modal} fieldset`).count(), 1);
    assert.equal(await question(page, 0).getByRole('button', { name: 'Remove question' }).isDisabled(), true);
    assert.equal(await question(page, 0).getByRole('button', { name: 'Add option' }).isVisible(), false);
    assert.equal(await page.locator(`${modal} textarea[name*=questions]`).count(), 0);
    await page.getByLabel('Title', { exact: true }).fill('Wellbeing check');
    await page.getByLabel('Description', { exact: true }).fill('Identified campus feedback');
    await choose(page.locator(modal), 'Audience', 'Students');
    await question(page, 0).getByLabel('Question text').fill('Remove this question');
    await page.getByRole('button', { name: 'Add question', exact: true }).click();
    await question(page, 1).getByLabel('Question text').fill('Pick a time');
    await choose(question(page, 1), 'Answer type', 'Single choice');
    assert.equal(await question(page, 1).getByRole('button', { name: 'Remove option' }).first().isDisabled(), true);
    await question(page, 1).getByLabel('Option 1', { exact: true }).fill('Morning');
    await question(page, 1).getByLabel('Option 2', { exact: true }).fill('Evening');
    await question(page, 1).getByRole('button', { name: 'Add option', exact: true }).click();
    await question(page, 1).getByLabel('Option 3', { exact: true }).fill('Afternoon');
    await question(page, 1).getByRole('button', { name: 'Remove option' }).nth(1).click();
    assert.equal(await question(page, 1).getByLabel('Option 2', { exact: true }).inputValue(), 'Afternoon');
    await question(page, 0).getByRole('button', { name: 'Remove question' }).click();
    assert.equal(await question(page, 0).getByLabel('Question text').inputValue(), 'Pick a time');
    await question(page, 0).getByLabel('Required', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Add question', exact: true }).click();
    await question(page, 1).getByLabel('Question text').fill('Rate support');
    await choose(question(page, 1), 'Answer type', 'Rating (1–5)');
    await modalVisuals(page, theme);
    const before = posts.length;
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    assert.equal(posts.length, before + 1);
    assert.equal(posts.at(-1).path, '/guidance/surveys/store');
    const sent = Object.fromEntries(posts.at(-1).fields);
    const csrf = sent.csrf_test_name; assert.ok(csrf);
    assert.deepEqual(sent, { csrf_test_name: csrf, title: 'Wellbeing check', description: 'Identified campus feedback', audience: 'students', starts_at: '', ends_at: '',
      'questions[0][label]': 'Pick a time', 'questions[0][type]': 'single', 'questions[0][required]': '0',
      'questions[0][options][0]': 'Morning', 'questions[0][options][1]': 'Afternoon',
      'questions[1][label]': 'Rate support', 'questions[1][type]': 'rating', 'questions[1][required]': '1' });
    await open(page, 'Edit draft');
    assert.equal(await page.locator(`${modal} [name=version]`).inputValue(), '4');
    assert.equal(await page.locator(`${modal} fieldset`).count(), 4);
    assert.equal(await question(page, 2).locator('select').inputValue(), 'multiple');
    await choose(question(page, 2), 'Answer type', 'Text (up to 2,000 characters)');
    assert.equal(await question(page, 2).locator('[name*=options]').count(), 0);
    await page.locator(`${modal} a[data-app-modal-close]`).click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
  });
  test(`${variant} lifecycle confirmations: publish/archive announcements, publish/close surveys`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, '/guidance/announcements', theme, mobile);
    for (const [route, action, label, target, version] of [
      ['/guidance/announcements', 'Publish', 'Publish announcement', '/guidance/announcements/1/publish', '7'],
      ['/clinic/announcements', 'Archive', 'Archive announcement', '/clinic/announcements/1/archive', '7'],
      ['/guidance/surveys', 'Publish', 'Publish', '/guidance/surveys/1/publish', null],
      ['/guidance/surveys', 'Close', 'Close survey', '/guidance/surveys/2/close', null]
    ]) {
      await page.goto(origin + route);
      const button = page.locator('#main').getByRole('button', { name: action, exact: true }).first();
      const before = posts.length; await button.click();
      await page.locator('#synapseConfirmModal.show').waitFor();
      await noOverflow(page, '#synapseConfirmModal .modal-content');
      assert.match(await page.locator('[data-confirm-message]').innerText(), /cannot|immutable|No further/);
      assert.equal(posts.length, before); await cancelConfirm(page); assert.equal(posts.length, before);
      await button.click();
      assert.equal(await page.locator('[data-confirm-accept]').innerText(), label);
      await Promise.all([
        page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === target),
        // Mobile scroll/focus can cause same-document navigation before the form redirect.
        page.waitForEvent('load'),
        acceptConfirm(page)
      ]);
      assert.equal(posts.length, before + 1); assert.equal(posts.at(-1).path, target);
      const sent = Object.fromEntries(posts.at(-1).fields); assert.ok(sent.csrf_test_name);
      if (version) assert.equal(sent.version, version);
    }
  });
  test(`${variant} portal: literal announcement, read marker, identified response consent/retry/completion`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, '/portal/announcements', theme, mobile);
    await noOverflow(page);
    await page.getByRole('link', { name: 'Read announcement: Campus notice', exact: true }).click();
    await noOverflow(page);
    assert.match(await page.locator('#main article').innerText(), /<img src=x/);
    assert.equal(await page.locator('#main article img').count(), 0);
    const readBefore = posts.length;
    await page.getByRole('button', { name: 'Mark as read', exact: true }).click();
    await page.waitForURL(origin + '/portal/announcements/1?read=1');
    assert.equal(posts.length, readBefore + 1); assert.equal(posts.at(-1).path, '/portal/announcements/1/read');
    assert.equal(await page.getByRole('button', { name: 'Mark as read', exact: true }).count(), 0);
    await page.goto(origin + '/portal/surveys'); await noOverflow(page);
    // Direct-route submission is independent of the soft-navigation regression below.
    await page.goto(origin + '/portal/surveys/1');
    await noOverflow(page);
    assert.match(await page.locator('#main').innerText(), /not.*anonymous|identified survey/i);
    assert.equal(await page.locator('#main img').count(), 0);
    assert.equal(await page.locator('[data-survey-submit]').evaluate(f => f.checkValidity()), false);
    await page.getByLabel('Your feedback', { exact: true }).fill('Please offer more sessions');
    await page.getByLabel('Morning', { exact: true }).check();
    await page.getByLabel('Study', { exact: true }).check();
    await page.getByLabel('Career', { exact: true }).check();
    await choose(page.locator('[data-survey-submit]'), 'Overall rating', '5');
    const before = posts.length;
    await page.getByRole('button', { name: 'Submit identified response' }).click();
    await cancelConfirm(page); assert.equal(posts.length, before);
    failSave = true;
    await page.getByRole('button', { name: 'Submit identified response' }).click(); await acceptConfirm(page);
    await page.locator('[data-survey-error]:not(.d-none)').waitFor();
    assert.equal(posts.length, before + 1);
    assert.equal(await page.getByLabel('Your feedback', { exact: true }).inputValue(), 'Please offer more sessions');
    const submitted = posts.at(-1); assert.equal(submitted.path, '/portal/surveys/1/submit');
    assert.deepEqual(submitted.fields.filter(([key]) => key !== 'csrf_test_name'), [
      ['answers[0]', 'Please offer more sessions'], ['answers[1]', '0'], ['answers[2][]', '0'], ['answers[2][]', '2'], ['answers[3]', '5']
    ]);
    failSave = false;
    await page.getByRole('button', { name: 'Submit identified response' }).click();
        await Promise.all([
          page.waitForResponse(response => response.request().method() === 'POST'), acceptConfirm(page)
        ]);
        assert.equal(posts.at(-1).headers['x-requested-with'], 'XMLHttpRequest', JSON.stringify(posts.slice(before)));
        await page.waitForURL(origin + '/portal/surveys');
    assert.equal(posts.length, before + 2);
    assert.equal(Object.fromEntries(posts.at(-1).fields).csrf_test_name, 'retry-token');
    assert.equal(await page.locator('#main .pe-survey-card').first().locator('.pe-state').innerText(), 'Submitted');
    await page.getByRole('link', { name: 'View status', exact: true }).first().click();
    await page.waitForURL(origin + '/portal/surveys/1');
    await page.getByText('Your completion has been recorded.', { exact: false }).waitFor();
    assert.match(await page.locator('#main').innerText(), /Your completion has been recorded/);
    assert.equal(await page.locator('[data-survey-submit]').count(), 0);
    assert.equal(await page.evaluate(() => window.fixtureXss), undefined);
    await noOverflow(page);
  });
}
for (const theme of ['light', 'dark']) for (const mobile of [false, true]) {
  test(`Portal cards ${theme} ${mobile ? 'mobile' : 'desktop'}: feeds, statuses, filters and empty states`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, '/portal/surveys', theme, mobile);
    await noOverflow(page);
    assert.equal(await page.locator('#main table').count(), 0);
    assert.equal(await page.locator('.pe-survey-card').count(), 3);
    assert.equal(await page.getByRole('link', { name: 'Answer survey', exact: true }).count(), 1);
    assert.equal(await page.getByRole('button', { name: 'Survey closed', exact: true }).isDisabled(), true);
    assert.match(await page.locator('.pe-privacy').innerText(), /identified, not anonymous/);
    const columns = await page.locator('.pe-survey-grid').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    assert.equal(columns, mobile ? 1 : 2);
    const screenshots = path.join(root, 'writable/portal-content-preview');
    fs.mkdirSync(screenshots, { recursive: true });
    await page.screenshot({ path: path.join(screenshots, `surveys-${theme}-${mobile ? 'mobile' : 'desktop'}.png`), fullPage: true });
    await page.locator('.pe-tabs').getByRole('link', { name: /^Closed/ }).click();
    await page.waitForURL(/status=closed/);
    assert.equal(await page.locator('.pe-survey-card').count(), 1);
    assert.equal(await page.getByRole('link', { name: 'Answer survey', exact: true }).count(), 0);
    await page.goto(origin + '/portal/surveys?q=NoSuchSurvey');
    assert.equal(await page.locator('.pe-empty').count(), 1);
    await page.goto(origin + '/portal/announcements');
    await noOverflow(page);
    assert.equal(await page.locator('#main table').count(), 0);
    assert.equal(await page.locator('.pe-content-card').count(), 2);
    assert.equal(await page.locator('#main article img').count(), 0);
    await page.screenshot({ path: path.join(screenshots, `announcements-${theme}-${mobile ? 'mobile' : 'desktop'}.png`), fullPage: true });
    await page.locator('.pe-tabs').getByRole('link', { name: /^Unread/ }).click();
    await page.waitForURL(/status=unread/);
    assert.equal(await page.locator('.pe-content-card').count(), 1);
    assert.equal(await page.locator('.pe-content-card .pe-state').innerText(), 'Unread');
    await page.goto(origin + '/portal/announcements?q=NoSuchNotice');
    assert.equal(await page.locator('.pe-empty').count(), 1);
    assert.equal(await page.evaluate(() => window.fixtureXss), undefined);
  });
}

test('Portal survey soft navigation binds the AJAX submission handler before consent', { timeout: 15000 }, async t => {
  const page = await pageFor(t, '/portal/surveys', 'light');
  await page.getByRole('link', { name: 'Answer survey', exact: true }).first().click();
  await page.waitForURL(origin + '/portal/surveys/1');
  await page.locator('[data-survey-submit]').waitFor();
  assert.equal(await page.locator('[data-survey-submit]').getAttribute('data-bound'), '1',
    'Survey initializer runs before the incoming form is connected (surveys/take.php:35–37; navigation.js:311–313,323)');
});
for (const base of ['/clinic/announcements', '/guidance/announcements', '/guidance/surveys']) {
  test(`${base}: targeting selection, save/edit, clearing and disabled stale filters`, { timeout: 45000 }, async t => {
    persistTargeting = true; savedTargets.clear();
    t.after(() => { persistTargeting = false; savedTargets.clear(); });
    const page = await pageFor(t, base, 'dark', true);
    assert.match(await page.locator('#main tbody').innerText(), /Years: 1 or 6 AND Categories: New or Transferee or Graduating/);
    await open(page, base.endsWith('surveys') ? 'Create survey' : 'New draft');
    const targets = () => page.locator(`${modal} [name^="target_"]`);
    assert.equal(await targets().count(), 10);
    for (const input of await targets().all()) {
      assert.equal(await input.isDisabled(), true);
      assert.equal(await input.isChecked(), false);
    }
    await choose(page.locator(modal), 'Audience', 'Students');
    await page.getByLabel('Title', { exact: true }).fill('Targeted campus support');
    if (base.endsWith('surveys')) await question(page, 0).getByLabel('Question text').fill('How can we help?');
    else await page.getByLabel('Announcement text').fill('Support for selected students');
    for (const label of ['Year 1', 'Year 6', 'New', 'Transferee', 'Graduating']) await page.getByLabel(label, { exact: true }).check();
    await modalVisuals(page, 'dark');
    failSave = true;
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await page.locator('[data-modal-error]:not(.d-none)').waitFor();
    assert.equal(await page.getByLabel('Graduating', { exact: true }).isChecked(), true);
    failSave = false;
    const save = async () => {
      await page.getByRole('button', { name: 'Save draft', exact: true }).click();
      await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    };
    await save();
    const selected = name => posts.at(-1).fields.filter(([key]) => key === name + '[]').map(([, value]) => value);
    assert.deepEqual(selected('target_year_levels'), ['1', '6']);
    assert.deepEqual(selected('target_student_categories'), ['new', 'transferee', 'graduating']);
    await open(page, 'Edit draft');
    for (const label of ['Year 1', 'Year 6', 'New', 'Transferee', 'Graduating']) assert.equal(await page.getByLabel(label, { exact: true }).isChecked(), true);
    assert.equal(await page.getByLabel('Continuing', { exact: true }).isChecked(), false);
    for (const audience of ['Students and employees', 'Employees', 'Students']) {
      const label = base.endsWith('surveys') ? audience : ({ 'Students and employees': 'All students and employees', Employees: 'Employees (including staff self-service)' }[audience] || audience);
      await choose(page.locator(modal), 'Audience', label);
      for (const input of await targets().all()) assert.equal(await input.isDisabled(), audience !== 'Students');
    }
    // Clearing every selection omits both arrays, representing unrestricted students.
    for (const input of await targets().all()) await input.uncheck();
    await save();
    assert.deepEqual(selected('target_year_levels'), []);
    assert.deepEqual(selected('target_student_categories'), []);
    await open(page, 'Edit draft');
    for (const input of await targets().all()) assert.equal(await input.isChecked(), false);
    await page.getByLabel('Year 2', { exact: true }).check();
    await page.getByLabel('Continuing', { exact: true }).check();
    await choose(page.locator(modal), 'Audience', base.endsWith('surveys') ? 'Employees' : 'Employees (including staff self-service)');
    assert.equal(await page.getByLabel('Year 2', { exact: true }).isChecked(), true);
    assert.equal(await page.getByLabel('Year 2', { exact: true }).isDisabled(), true);
    await save();
    assert.equal(Object.fromEntries(posts.at(-1).fields).audience, 'employees');
    assert.deepEqual(selected('target_year_levels'), []);
    assert.deepEqual(selected('target_student_categories'), []);
    await open(page, 'Edit draft');
    for (const input of await targets().all()) {
      assert.equal(await input.isChecked(), false);
      assert.equal(await input.isDisabled(), true);
    }
  });
}

test('Survey builder enforces 1–20 questions and 2–10 options through visible controls', { timeout: 30000 }, async t => {
  const page = await pageFor(t, '/guidance/surveys', 'light'); await open(page, 'Create survey');
  await choose(question(page, 0), 'Answer type', 'Multiple choice');
  for (let i = 2; i < 10; i++) await question(page, 0).getByRole('button', { name: 'Add option', exact: true }).click();
  assert.equal(await question(page, 0).getByRole('button', { name: 'Add option', exact: true }).isDisabled(), true);
  assert.equal(await question(page, 0).locator('[name*=options]').count(), 10);
  await question(page, 0).getByRole('button', { name: 'Remove option' }).last().click();
  assert.equal(await question(page, 0).getByRole('button', { name: 'Add option', exact: true }).isEnabled(), true);
  for (let i = 1; i < 20; i++) await page.getByRole('button', { name: 'Add question', exact: true }).click();
  assert.equal(await page.locator(`${modal} fieldset`).count(), 20);
  assert.equal(await page.getByRole('button', { name: 'Add question', exact: true }).isDisabled(), true);
  await question(page, 19).getByRole('button', { name: 'Remove question' }).click();
  assert.equal(await page.getByRole('button', { name: 'Add question', exact: true }).isEnabled(), true);
});

for (const theme of ['light', 'dark']) {
  test(`Question bank copies and customization work in ${theme} mobile survey modal`, { timeout: 25000 }, async t => {
    const page = await pageFor(t, '/guidance/surveys', theme, true);
    await open(page, 'Create survey');
    await page.getByRole('button', { name: 'Add from question bank', exact: true }).click();
    const picker = page.getByRole('region', { name: 'Question bank picker' });
    await picker.getByRole('button', { name: 'Add copy', exact: true }).click();
    assert.equal(await page.locator(`${modal} fieldset`).count(), 1, 'Replace the initial empty question');
    assert.equal(await question(page, 0).locator('[name$="[bank_id]"]').inputValue(), '7');
    assert.equal(await question(page, 0).locator('[name$="[bank_revision]"]').inputValue(), '3');
    assert.equal(await question(page, 0).locator('[name*=options]').count(), 2);
    assert.equal(await page.evaluate(() => window.fixtureXss), undefined);
    await picker.getByRole('button', { name: 'Add copy', exact: true }).click();
    await question(page, 0).getByRole('textbox', { name: 'Option 1', exact: true }).fill('Customized morning');
    assert.equal(await question(page, 0).locator('[name$="[bank_id]"]').count(), 0, 'Editing detaches source metadata');
    assert.equal(await question(page, 1).getByRole('textbox', { name: 'Option 1', exact: true }).inputValue(), 'Morning', 'Option arrays are independent copies');
    assert.equal(await question(page, 1).locator('[name$="[bank_revision]"]').inputValue(), '3');
    await modalVisuals(page, theme);
  });
}

for (const theme of ['light', 'dark']) {
  test(`Question bank multi-select retains pages, adds snapshots and submits cleanly in ${theme} mobile`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, '/guidance/surveys', theme, true);
    bankMulti = true;
    await open(page, 'Create survey');
    await page.getByRole('button', { name: 'Add from question bank', exact: true }).click();
    const picker = page.getByRole('region', { name: 'Question bank picker' });
    const selectPage = picker.getByRole('checkbox', { name: 'Select this page', exact: true });
    await picker.getByRole('checkbox', { name: /Select question: Reusable/ }).check();
    assert.equal(await selectPage.evaluate(el => el.indeterminate), true);
    await selectPage.check();
    assert.equal(await picker.getByRole('checkbox', { name: 'Select question: Second bank question', exact: true }).isChecked(), true);
    await picker.getByRole('button', { name: 'Next', exact: true }).click();
    const third = picker.getByRole('checkbox', { name: 'Select question: Third bank question', exact: true });
    await third.check();
    await picker.getByRole('button', { name: 'Previous', exact: true }).click();
    await picker.getByRole('checkbox', { name: 'Select question: Second bank question', exact: true }).waitFor();
    assert.equal(await selectPage.isChecked(), true);
    await picker.getByRole('button', { name: 'Search bank', exact: true }).click();
    await picker.getByRole('checkbox', { name: 'Select question: Second bank question', exact: true }).waitFor();
    assert.equal(await selectPage.isChecked(), true);
    await picker.getByRole('button', { name: 'Add selected questions', exact: true }).click();
    assert.equal(await page.locator(`${modal} fieldset`).count(), 3);
    assert.equal(await picker.getByRole('button', { name: 'Add selected questions', exact: true }).isDisabled(), true);
    assert.deepEqual(await page.locator(`${modal} [name$="[bank_id]"]`).evaluateAll(inputs => inputs.map(input => input.value)), ['7', '8', '9']);
    assert.deepEqual(await page.locator(`${modal} [name$="[bank_revision]"]`).evaluateAll(inputs => inputs.map(input => input.value)), ['3', '4', '5']);
    await modalVisuals(page, theme);
    await page.getByLabel('Title', { exact: true }).fill('Bulk selected questions');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('#appFormModal'));
    const sent = posts.at(-1).fields;
    assert.deepEqual(sent.filter(([key]) => key.endsWith('[bank_id]')).map(([, value]) => value), ['7', '8', '9']);
    assert.equal(sent.some(([key]) => /bankSelected|bankQuery/.test(key)), false);
    assert.equal(await page.evaluate(() => window.fixtureXss), undefined);
  });
}

test('Question bank bulk add never partially adds beyond the survey limit', { timeout: 30000 }, async t => {
  const page = await pageFor(t, '/guidance/surveys', 'light');
  bankMulti = true;
  await open(page, 'Create survey');
  await question(page, 0).getByLabel('Question text').fill('Keep my existing question');
  for (let i = 1; i < 19; i++) await page.getByRole('button', { name: 'Add question', exact: true }).click();
  await page.getByRole('button', { name: 'Add from question bank', exact: true }).click();
  const picker = page.getByRole('region', { name: 'Question bank picker' });
  await picker.getByRole('checkbox', { name: 'Select this page', exact: true }).check();
  await picker.getByRole('button', { name: 'Add selected questions', exact: true }).click();
  assert.equal(await page.locator(`${modal} fieldset`).count(), 19);
  assert.match(await picker.getByRole('alert').innerText(), /Only 1.*nothing was added/);
  assert.equal(await picker.getByRole('checkbox', { name: 'Select this page', exact: true }).isChecked(), true);
  await question(page, 18).getByRole('button', { name: 'Remove question', exact: true }).click();
  await picker.getByRole('button', { name: 'Add selected questions', exact: true }).click();
  assert.equal(await page.locator(`${modal} fieldset`).count(), 20);
  assert.equal(await picker.getByRole('button', { name: 'Add selected questions', exact: true }).isDisabled(), true);
});

test('Question bank fetch error is recoverable and leaves custom questions intact', { timeout: 25000 }, async t => {
  const page = await pageFor(t, '/guidance/surveys', 'light');
  await open(page, 'Create survey');
  await question(page, 0).getByLabel('Question text').fill('Keep this custom question');
  bankUnavailable = true;
  await page.getByRole('button', { name: 'Add from question bank', exact: true }).click();
  const picker = page.getByRole('region', { name: 'Question bank picker' });
  await picker.getByRole('alert').waitFor();
  assert.match(await picker.getByRole('alert').innerText(), /temporarily unavailable/);
  assert.equal(await question(page, 0).getByLabel('Question text').inputValue(), 'Keep this custom question');
  bankUnavailable = false;
  await picker.getByRole('button', { name: 'Search bank', exact: true }).click();
  await picker.getByRole('button', { name: 'Add copy', exact: true }).waitFor();
  await picker.getByRole('button', { name: 'Add copy', exact: true }).click();
  assert.equal(await page.locator(`${modal} fieldset`).count(), 2);
});
