'use strict';
// Real responses view/full shell, synthetic PHP fixtures and local assets only; no live app or DB.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const responsesPath = '/guidance/surveys/1/responses';
const cache = new Map();
const serverMutations = [];
let browser, server, origin;
function render(empty) {
  if (cache.has(empty)) return cache.get(empty);
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['tests/_support/render-survey-analytics-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, empty }), encoding: 'utf8', timeout: 10000,
  });
  assert.equal(result.status, 0, result.stderr || result.error?.message);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  assert.match(result.stdout, /class="app-shell"/);
  assert.match(result.stdout, /id="app-sidebar"/);
  assert.match(result.stdout, /id="tab-visualizations"/);
  cache.set(empty, result.stdout);
  return result.stdout;
}
before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method !== 'GET') {
      serverMutations.push(`${req.method} ${url.pathname}`);
      return res.writeHead(405).end('No mutations allowed');
    }
    if (url.pathname === responsesPath) {
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url.searchParams.has('empty'))); }
      catch (error) { return res.writeHead(500).end(error.stack); }
    }
    if (!url.pathname.startsWith('/assets/')) return res.writeHead(404).end();
    const dir = path.join(root, 'public');
    const file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(dir + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(data);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
    assert.ok(executablePath, 'Install the Playwright Chromium browser before running this suite');
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
  assert.deepEqual(serverMutations, [], 'the fixture server must never receive a mutation');
});
async function open(t, width, theme) {
  const context = await browser.newContext({ viewport: { width, height: 950 }, hasTouch: width < 768, colorScheme: theme, serviceWorkers: 'block' });
  const mutations = [], errors = [];
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  await context.route('**/*', route => {
    const request = route.request();
    // Record before checking the origin so even an external POST is a test failure.
    if (request.method() !== 'GET') {
      mutations.push(`${request.method()} ${request.url()}`);
      return route.abort();
    }
    if (new URL(request.url()).origin !== origin) return route.abort();
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(15000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => {
    await context.close();
    assert.deepEqual(mutations, [], 'viewing analytics and switching tabs must not POST');
    assert.deepEqual(errors, [], 'production scripts must initialize without errors');
  });
  await page.goto(origin + responsesPath);
  await page.evaluate(() => document.fonts.ready);
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  assert.equal(await page.locator('.survey-q-card').count(), 4);
  return page;
}
async function layoutProblems(page, mobile) {
  return page.locator('#tab-visualizations').evaluate((root, mobile) => {
    const issues = [];
    const tolerance = 1;
    const inside = (rect, bounds) => rect.left >= bounds.left - tolerance && rect.right <= bounds.right + tolerance && rect.top >= bounds.top - tolerance && rect.bottom <= bounds.bottom + tolerance;
    for (const [cardIndex, card] of [...root.querySelectorAll('.survey-q-card')].entries()) {
      const cardBox = card.getBoundingClientRect();
      if (cardBox.left < -tolerance || cardBox.right > document.documentElement.clientWidth + tolerance) issues.push(`Q${cardIndex + 1}: card outside viewport`);
      for (const [rowIndex, row] of [...card.querySelectorAll('.survey-bar-row')].entries()) {
        const id = `Q${cardIndex + 1} row ${rowIndex + 1}`;
        const label = row.querySelector('.survey-bar-label'), meta = row.querySelector('.survey-bar-meta'), track = row.querySelector('.survey-bar-track');
        const l = label.getBoundingClientRect(), m = meta.getBoundingClientRect(), b = track.getBoundingClientRect(), r = row.getBoundingClientRect();
        // Check actual descendants, not just row width: a clipped card can hide escaped children.
        for (const part of [label, meta, track]) for (const el of [part, ...part.querySelectorAll('*')]) {
          const rect = el.getBoundingClientRect(), css = getComputedStyle(el);
          if (rect.width <= 0 || rect.height <= 0 || css.display === 'none' || css.visibility !== 'visible' || Number(css.opacity) === 0) issues.push(`${id}: ${el.className} has no visible area`);
          if (!inside(rect, cardBox)) issues.push(`${id}: ${el.className} outside question card`);
        }
        if (b.width <= 0 || b.height <= 0) issues.push(`${id}: collapsed track`);
        if (mobile) {
          if (getComputedStyle(row).display !== 'grid') issues.push(`${id}: mobile row is not a grid`);
          if (l.bottom < m.top - tolerance || m.bottom < l.top - tolerance || l.right > m.left + tolerance) issues.push(`${id}: label and meta must share the first row without overlap`);
          if (b.top < Math.max(l.bottom, m.bottom) - tolerance) issues.push(`${id}: track must be below both label and meta`);
          if (Math.abs(b.left - r.left) > tolerance || Math.abs(b.right - r.right) > tolerance) issues.push(`${id}: track must span the full second row`);
          const css = getComputedStyle(label);
          if (css.whiteSpace === 'nowrap' || css.textOverflow === 'ellipsis' || css.webkitLineClamp !== 'none' && css.webkitLineClamp !== '0') issues.push(`${id}: mobile label is truncated`);
          if (label.scrollWidth > label.clientWidth + tolerance || label.scrollHeight > label.clientHeight + tolerance) issues.push(`${id}: mobile label has hidden text`);
          const range = document.createRange(); range.selectNodeContents(label);
          const lines = [...range.getClientRects()].filter(rect => rect.width > 0 && rect.height > 0);
          if (lines.some(rect => !inside(rect, l))) issues.push(`${id}: label text escapes its bounds`);
          if (label.textContent.trim().length > 80 && new Set(lines.map(rect => Math.round(rect.top))).size < 2) issues.push(`${id}: long option does not wrap`);
        } else {
          if (l.right > b.left + tolerance || b.right > m.left + tolerance) issues.push(`${id}: desktop label/track/meta are not horizontal`);
          if (Math.abs((l.top + l.bottom) / 2 - (b.top + b.bottom) / 2) > tolerance || Math.abs((m.top + m.bottom) / 2 - (b.top + b.bottom) / 2) > tolerance) issues.push(`${id}: desktop row is not vertically centered`);
        }
      }
    }
    return issues;
  }, mobile);
}
const expectedRows = [
  [
    ['An individual appointment with a guidance counsellor to discuss study habits and planning for the next semester', '5 picks', '62.5%'],
    ['A group workshop', '3 picks', '37.5%'],
    ['No preference', '0 picks', '0%'],
  ],
  [
    ['Follow-up resources for students using the CampusWellbeingAndAcademicSupportResourceCollectionWithoutAbbreviations', '8 picks', '100%'],
    ['Peer support', '3 picks', '37.5%'],
    ['Other resources', '0 picks', '0%'],
  ],
  [['5 Stars ★', '3 votes', '37.5%'], ['4 Stars ★', '4 votes', '50%'], ['3 Stars ★', '0 votes', '0%'], ['2 Stars ★', '1 vote', '12.5%'], ['1 Star ★', '0 votes', '0%']],
  [],
];
for (const theme of ['light', 'dark']) for (const width of [320, 390, 768, 1440]) {
  test(`${width}px ${theme}: choice/rating descendants fit cards and tracks retain visible width`, { timeout: 25000 }, async t => {
    const page = await open(t, width, theme);
    if (width < 768) {
      // Prove readability comes from rendered text, not the existing hover title attribute.
      await page.locator('.survey-bar-label[title]').evaluateAll(labels => labels.forEach(label => label.removeAttribute('title')));
    }
    assert.deepEqual(await layoutProblems(page, width < 768), []);
    assert.equal(await page.locator('.survey-bar-track:visible').count(), 11, 'includes zero-count choice options and rating buckets');
  });
  test(`${width}px ${theme}: counts, percentages, unanswered question and empty survey remain intact without POST`, { timeout: 25000 }, async t => {
    const page = await open(t, width, theme);
    const actualRows = await page.locator('.survey-q-card').evaluateAll(cards => cards.map(card => [...card.querySelectorAll('.survey-bar-row')].map(row =>
      ['.survey-bar-label', '.survey-bar-meta', '.survey-bar-fill'].map(selector => row.querySelector(selector).textContent.replace(/\s+/g, ' ').trim())
    )));
    assert.deepEqual(actualRows, expectedRows);
    const answered = await page.locator('.survey-q-header .badge').allTextContents();
    assert.deepEqual(answered.map(text => text.replace(/\s+/g, ' ').trim()).filter(text => / answered$/.test(text)), ['8 answered', '8 answered', '8 answered', '0 answered']);
    assert.equal(await page.locator('.survey-q-card').nth(2).getByText('4.1', { exact: true }).isVisible(), true);
    const unanswered = page.locator('.survey-q-card').nth(3);
    assert.equal(await unanswered.getByText('No answers recorded for this question yet.', { exact: true }).isVisible(), true);
    assert.equal(await unanswered.locator('.survey-bar-row, .survey-bar-track').count(), 0);
    await page.getByRole('tab', { name: /Individual Submissions/ }).click();
    await page.locator('#tab-submissions.show.active').waitFor({ state: 'visible' });
    assert.equal(await page.getByText('8 identified submissions.', { exact: true }).isVisible(), true);
    assert.equal(await page.locator('#tab-submissions tbody tr').count(), 8);
    await page.getByRole('tab', { name: /Visual Analytics/ }).click();
    await page.locator('#tab-visualizations.show.active').waitFor({ state: 'visible' });
    assert.deepEqual(await page.locator('.survey-bar-fill').allTextContents().then(values => values.map(value => value.trim())), expectedRows.flat().map(row => row[2]));
    await page.goto(origin + responsesPath + '?empty=1');
    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
    assert.equal(await page.locator('#tab-visualizations').getByText('No responses recorded yet', { exact: true }).isVisible(), true);
    assert.equal(await page.locator('.survey-q-card, .survey-bar-row, .survey-bar-track').count(), 0);
    assert.equal(await page.locator('.nd-kpi-card').first().locator('.nd-kpi-value').innerText(), '0');
    await page.getByRole('tab', { name: /Individual Submissions \(0\)/ }).click();
    await page.locator('#tab-submissions.show.active').waitFor({ state: 'visible' });
    assert.equal(await page.getByText('0 identified submissions.', { exact: true }).isVisible(), true);
    assert.equal(await page.locator('#tab-submissions').getByText('No responses recorded yet.', { exact: true }).isVisible(), true);
  });
}
