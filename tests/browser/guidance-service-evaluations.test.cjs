'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const fixture = require('../_support/guidance-service-evaluations-browser-harness.cjs');
const { chromium } = fixture.localPlaywright();
const root = path.resolve(__dirname, '../..');
const evidenceDir = path.join(root, 'writable/guidance-service-evaluations-preview');
let server, browser;

before(async () => {
  server = await fixture.startServer();
  const executablePath = fixture.installedChromium(chromium);
  assert.ok(executablePath, 'Use existing Chromium only; do not install a browser.');
  browser = await chromium.launch({ headless: true, executablePath });
}, { timeout: 30000 });
after(async () => {
  await browser?.close();
  if (server) { console.log('Evaluation fixture totals: ' + JSON.stringify(server.totals)); await server.close(); }
});
async function open(t, options = {}) {
  const scenario = server.createScenario(options);
  const context = await browser.newContext({ viewport: { width: options.width || 1440, height: 1000 }, javaScriptEnabled: options.javaScriptEnabled !== false, serviceWorkers: 'block', colorScheme: options.theme || 'light', reducedMotion: 'reduce' });
  await context.addCookies([{ name: fixture.cookieName, value: scenario.id, url: server.origin }]);
  const errors = [], rejected = [], documents = [];
  await context.route('**/*', route => {
    const request = route.request();
    if (request.method() !== 'GET' || !fixture.allowedRequest(server.origin, new URL(request.url()))) { rejected.push(request.url()); return route.abort(); }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(12000);
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (request.isNavigationRequest() && request.resourceType() === 'document') documents.push(request.url()); });
  page.on('dialog', async dialog => { errors.push('Unexpected dialog: ' + dialog.message()); await dialog.dismiss(); });
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); assert.deepEqual(rejected, []); });
  await page.goto(server.origin + (options.start || fixture.listPath), { waitUntil: 'load' });
  if (!options.start || options.start === fixture.listPath) await page.locator('.gse-workspace').waitFor();
  return { page, context, scenario, documents };
}
async function screenshot(page, name) {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const image = path.join(evidenceDir, name + '.png');
  await page.waitForFunction(() => !document.body.classList.contains('is-navigating')
    && !document.querySelector('[data-page-style-stage]')
    && !document.querySelector('#main[aria-busy="true"]'));
  await page.screenshot({ path: image, timeout: 30000, animations: 'disabled', caret: 'hide' });
  console.log('Evaluation screenshot: ' + image);
}
function card(page, type) { return page.locator('[data-evaluation-type="' + type + '"]'); }
async function overflow(page) {
  const result = await page.evaluate(() => {
    const bad = [...document.querySelectorAll('.gse-workspace, .gse-aggregate, .gse-filter-form, .gse-stat, .gse-dimensions, .gse-comments, .gse-visits')]
      .filter(node => node.scrollWidth > node.clientWidth + 2).map(node => ({ className: node.className, client: node.clientWidth, scroll: node.scrollWidth }));
    return { document: document.documentElement.scrollWidth <= innerWidth + 2, bad };
  });
  assert.equal(result.document, true, JSON.stringify(result));
  assert.deepEqual(result.bad, []);
}
async function assertPdfQueries(page, expected) {
  for (const type of fixture.types) {
    const link = card(page, type).getByRole('link', { name: /Aggregate PDF/ });
    const url = new URL(await link.getAttribute('href'));
    assert.equal(url.origin, server.origin);
    assert.equal(url.pathname, fixture.pdfPath);
    assert.deepEqual(Object.fromEntries(url.searchParams), { evaluation_type: type, provider_user_id: String(expected.provider || 97001), ...(expected.term ? { academic_term: expected.term } : {}), ...(expected.year ? { academic_year: expected.year } : {}) });
    assert.equal(await link.getAttribute('target'), '_blank');
    assert.equal(await link.getAttribute('rel'), 'noopener');
    assert.equal(await link.getAttribute('data-navigation'), 'false');
  }
}

test('Contract: real view retains filters, service labels, exports, escaping and synthetic dependency bounds', async () => {
  const origin = 'http://127.0.0.1:54444';
  const data = fixture.fixtureData(origin, new URL(origin + fixture.listPath), { long: true });
  const sections = fixture.renderFixture(data);
  assert.ok(sections.content.includes('gse-workspace'));
  assert.ok(sections.styles.includes('guidance-service-evaluations.css'));
  assert.ok(!sections.scripts);
  assert.ok(sections.content.includes('F-OGC-GPS-PEF-008-00'));
  assert.ok(sections.content.includes('F-OGC-GCS-CEF-009-00'));
  assert.ok(sections.content.includes('Willingness to Visit Again'));
  assert.ok(sections.content.includes('Service Recommendation Rate'));
  assert.ok(sections.content.includes('maxlength="64"'));
  assert.ok(sections.content.includes('maxlength="32"'));
  assert.ok(sections.content.includes('&lt;img'));
  assert.ok(!sections.content.includes('<img'));
  assert.ok(!sections.content.includes('<script>synthetic</script>'));
  const readonly = fixture.renderFixture({ ...data, canModerate: false, canViewAnyProvider: false });
  assert.ok(!readonly.page_actions.includes('/moderate'));
  assert.ok(!readonly.content.includes('eval-provider-select'));
  for (const patch of [{ unexpected: true }, { origin: 'https://example.com' }, { providerUserId: 1 }, { currentUrl: origin + '/outside' }]) {
    assert.throws(() => fixture.renderFixture({ ...data, ...patch }));
  }
});

test('Privacy: suppressed zero/four response cards discard score, rate, distribution and comment canaries', { timeout: 60000 }, async t => {
  const { page } = await open(t, { totals: [0, 4], canaries: true, cohortGateEnabled: false });
  assert.equal(await page.locator('.gse-summary, .gse-dimensions, .gse-visits, .gse-comments').count(), 0);
  const html = await page.content();
  for (const canary of ['Synthetic withheld comment', '4.99', '88.8', 'rating_welcoming']) assert.ok(!html.includes(canary));
  assert.match(await card(page, fixture.types[0]).textContent(), /0\/5/);
  assert.match(await card(page, fixture.types[1]).textContent(), /4\/5/);
  assert.match(await page.locator('.gse-policy').textContent(), /Sign-off Pending/);
  assert.equal(await page.getByText('Cohort Threshold Suppression Active', { exact: true }).count(), 2);
  await assertPdfQueries(page, {});
  await overflow(page);
  await screenshot(page, 'suppressed-zero-four-1440-light');
});

test('Privacy: exactly five responses release aggregates while policy sign-off remains informational', { timeout: 60000 }, async t => {
  const { page } = await open(t, { totals: [5, 5], cohortGateEnabled: false, noComments: true });
  assert.equal(await page.locator('.gse-summary').count(), 2);
  assert.equal(await page.locator('.gse-dimensions li').count(), 10);
  assert.equal(await page.locator('.gse-visits').count(), 1);
  assert.equal(await page.locator('.gse-comments-empty').count(), 2);
  assert.match(await card(page, fixture.types[0]).textContent(), /4\.65/);
  assert.match(await card(page, fixture.types[0]).textContent(), /87\.5%/);
  assert.match(await card(page, fixture.types[1]).textContent(), /80\.0%/);
  assert.equal(await page.getByText('Cohort threshold met', { exact: true }).count(), 2);
  await assertPdfQueries(page, {});
  await screenshot(page, 'threshold-five-policy-pending-1440-light');
});

test('Workflow: provider/term/year native GET filters, PDF context and Reset preserve existing contracts', { timeout: 60000 }, async t => {
  const { page, scenario, documents } = await open(t);
  await screenshot(page, 'filters-initial-1440-light');
  const trigger = page.getByRole('combobox', { name: 'Guidance Provider' });
  await trigger.click();
  const listId = await trigger.getAttribute('aria-controls');
  const menu = page.locator('[id="' + listId + '"]');
  await menu.waitFor({ state: 'visible' });
  await screenshot(page, 'provider-menu-1440-light');
  await menu.getByRole('option', { name: 'Synthetic Provider 02 Synthetic Staff (Guidance Supervisor)', exact: true }).click();
  await screenshot(page, 'provider-selected-1440-light');
  await page.getByLabel('Academic Term', { exact: true }).fill('Synthetic Narrow');
  await page.getByLabel('Academic Year', { exact: true }).fill('2031-2032');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await page.waitForURL(url => url.pathname === fixture.listPath && url.searchParams.get('academic_term') === 'Synthetic Narrow');
  assert.deepEqual(Object.fromEntries(new URL(page.url()).searchParams), { provider_user_id: '97002', academic_term: 'Synthetic Narrow', academic_year: '2031-2032' });
  assert.equal(await page.locator('#eval-provider-select').inputValue(), '97002');
  assert.equal(await page.locator('#eval-filter-term').inputValue(), 'Synthetic Narrow');
  assert.equal(await page.locator('.gse-summary').count(), 1);
  assert.match(await page.locator('.gse-provider h2').textContent(), /Provider 02/);
  await assertPdfQueries(page, { provider: 97002, term: 'Synthetic Narrow', year: '2031-2032' });
  await screenshot(page, 'filtered-mixed-1440-light');
  await page.getByRole('link', { name: 'Reset', exact: true }).click();
  await page.waitForURL(server.origin + fixture.listPath);
  assert.equal(await page.locator('#eval-filter-term').inputValue(), '');
  assert.equal(await page.locator('#eval-filter-year').inputValue(), '');
  assert.equal(await page.locator('#eval-provider-select').inputValue(), '97001');
  assert.equal(await page.locator('.gse-summary').count(), 2);
  assert.equal(await page.locator('.bd-select').count(), 1);
  assert.ok(documents.length >= 2, 'Apply remains a native GET document submission');
  assert.ok(scenario.requests.some(url => url.includes('academic_term=Synthetic')));
  await screenshot(page, 'reset-1440-light');
});

test('Workflow: own-provider read-only scope and no-JavaScript GET filtering remain usable', { timeout: 60000 }, async t => {
  const { page } = await open(t, { canViewAnyProvider: false, canModerate: false, javaScriptEnabled: false, width: 390 });
  assert.equal(await page.locator('#eval-provider-select').count(), 0);
  assert.equal(await page.getByRole('link', { name: 'Comment Moderation Queue' }).count(), 0);
  await page.getByLabel('Academic Term', { exact: true }).fill('Synthetic Missing');
  await page.getByLabel('Academic Year', { exact: true }).fill('2031-2032');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('academic_term') === 'Synthetic Missing');
  assert.ok(!new URL(page.url()).searchParams.has('provider_user_id'));
  assert.equal(await page.locator('.gse-summary').count(), 0);
  assert.equal(await page.getByText('No evaluation responses for this provider and the selected filters.', { exact: true }).count(), 2);
  await assertPdfQueries(page, { term: 'Synthetic Missing', year: '2031-2032' });
  await screenshot(page, 'readonly-no-js-empty-390-light');
});

test('Workflow: shared progressive entry, repeated return and browser history retain one select and current filters', { timeout: 60000 }, async t => {
  const { page, documents } = await open(t, { start: '/guidance' });
  const header = await page.locator('#header').elementHandle();
  await page.getByRole('link', { name: 'Open Service Evaluation Aggregates', exact: true }).click();
  await page.waitForURL(server.origin + fixture.listPath);
  await page.locator('.gse-workspace').waitFor();
  assert.equal(await page.locator('.bd-select').count(), 1);
  await page.getByLabel('Academic Term', { exact: true }).fill('Synthetic Narrow');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await page.waitForURL(url => url.searchParams.get('academic_term') === 'Synthetic Narrow');
  const newHeader = await page.locator('#header').elementHandle();
  const filtered = page.url();
  await page.getByRole('link', { name: 'Guidance Dashboard', exact: true }).click();
  await page.waitForURL(server.origin + '/guidance');
  assert.equal(await page.locator('.gse-workspace').count(), 0);
  await page.goBack();
  await page.waitForURL(filtered);
  await page.locator('.gse-workspace').waitFor();
  assert.equal(await page.locator('#eval-filter-term').inputValue(), 'Synthetic Narrow');
  assert.equal(await page.locator('.bd-select').count(), 1);
  assert.equal(await newHeader.evaluate(node => node === document.querySelector('#header')), true);
  assert.ok(documents.length === 2, 'Only initial entry and native filter Apply load a document');
  await screenshot(page, 'progressive-history-filtered-1440-light');
  await header.dispose();
});

test('Workflow: both PDF links and permission-gated moderation reach labeled synthetic destinations', { timeout: 60000 }, async t => {
  const { page, context } = await open(t, { totals: [4, 8], cohortGateEnabled: false });
  for (const type of fixture.types) {
    const popupPromise = context.waitForEvent('page');
    await card(page, type).getByRole('link', { name: /Aggregate PDF/ }).click();
    const popup = await popupPromise;
    await popup.waitForLoadState('load');
    assert.equal(new URL(popup.url()).searchParams.get('evaluation_type'), type);
    assert.match(await popup.getByRole('heading').textContent(), /HTML, NOT A PDF/);
    await screenshot(popup, type + '-pdf-link-landing');
    await popup.close();
  }
  await page.getByRole('link', { name: 'Comment Moderation Queue', exact: true }).click();
  await page.waitForURL(server.origin + '/guidance/evaluations/moderate');
  assert.match(await page.locator('#main').textContent(), /Synthetic read-only destination/);
  await screenshot(page, 'moderation-link-landing');
});

for (const theme of ['light', 'dark']) for (const width of [375, 768, 1024, 1440]) {
  test(`Responsive: ${width}px ${theme} long provider/comments, opaque cards and usable filters`, { timeout: 60000 }, async t => {
    const { page } = await open(t, { width, theme, long: true });
    assert.equal(await page.locator('.gse-comments li').count(), 2);
    assert.equal(await page.locator('.gse-workspace img, .gse-workspace script').count(), 0);
    assert.ok((await card(page, fixture.types[0]).textContent()).includes(fixture.hostileComment));
    await overflow(page);
    await screenshot(page, `responsive-${width}-${theme}-overview`);
    const comments = card(page, fixture.types[0]).locator('.gse-comments');
    await comments.scrollIntoViewIfNeeded();
    await screenshot(page, `responsive-${width}-${theme}-comments`);
    const providerTrigger = page.getByRole('combobox', { name: 'Guidance Provider' });
    await providerTrigger.scrollIntoViewIfNeeded();
    await screenshot(page, `responsive-${width}-${theme}-filter-anchor`);
    await providerTrigger.click();
    const listId = await providerTrigger.getAttribute('aria-controls');
    const menu = page.locator('[id="' + listId + '"]');
    await menu.waitFor({ state: 'visible' });
    const box = await menu.boundingBox();
    assert.ok(box && box.x >= -1 && box.x + box.width <= width + 1, JSON.stringify(box));
    await screenshot(page, `responsive-${width}-${theme}-provider-menu`);
  });
}
