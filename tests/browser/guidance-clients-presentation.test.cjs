'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createFixtureServer } = require('../_support/guidance-clients-fixture-server.cjs');

const root = path.resolve(__dirname, '../..');
let playwright;
try {
  playwright = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
} catch (_) {
  playwright = require('playwright');
}

function resolveChromiumPath() {
  let executablePath = playwright.chromium.executablePath();
  if (fs.existsSync(executablePath)) return executablePath;
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const found = fs.readdirSync(dir)
    .filter(name => /^chromium-\d+$/.test(name))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(name => path.join(dir, name, 'chrome-win64/chrome.exe'))
    .find(candidate => fs.existsSync(candidate));
  assert.ok(found, 'Playwright Chromium executable not found');
  return found;
}

let fixture;
let origin;
let browser;
before(async () => {
  fixture = createFixtureServer();
  origin = await fixture.start();
  browser = await playwright.chromium.launch({
    headless: true,
    executablePath: resolveChromiumPath(),
    timeout: 20_000,
  });
});
after(async () => {
  await browser?.close();
  await fixture?.close();
});

function render(recordPath) {
  const result = spawnSync('php', [path.resolve(__dirname, '../_support/render-guidance-clients-fixture.php')], {
    cwd: path.resolve(__dirname, '../..'), input: JSON.stringify({ origin, path: recordPath }), encoding: 'utf8', timeout: 15000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
}

test('Guidance client directory renders shared shell, neutral links, and read-only fragments', () => {
  const html = render('/guidance/clients');
  assert.match(html, /Guidance Clients &amp; Records/);
  assert.match(html, /data-live-table/);
  assert.match(html, /data-refresh-owner="guidance.clients"/);
  assert.match(html, /data-native-select/);
  assert.match(html, /guidance\/clients\/1/);
  assert.doesNotMatch(html, /Blood Type|Clinic Visits|Start Visit/);
  const fragment = render('/guidance/clients?q=SYNTH-001&fragment=1');
  assert.match(fragment, /^<div data-table-content>/);
  assert.match(fragment, /SYNTH-001/);
  assert.doesNotMatch(fragment, /SYNTH-002|<script|<!DOCTYPE|<form/);
});

test('all seven record sections and the legacy cumulative URL render without PHP warnings', () => {
  const sections = {
    overview: /Client profile/, cases: /Authorized counseling cases/, sessions: /Session chronology/,
    assessments: /Multiple Intelligence history/, coordination: /Intake referrals/,
    forms: /Counseling Contact Sheet/, appointments: /Shared scheduling appointments/,
  };
  for (const [section, expected] of Object.entries(sections)) {
    const html = render('/guidance/clients/1?tab=' + section);
    assert.match(html, expected, section);
    assert.match(html, /data-refresh-owner="guidance.client.1"/);
    assert.match(html, /aria-current="page"/);
    assert.doesNotMatch(html, /Warning:|Fatal error:|Blood Type|Vital Trends|Lab Results/);
  }
  assert.match(render('/guidance/students/1/cumulative?tab=cases'), /Authorized counseling cases/);
  const employee = render('/guidance/clients/2');
  assert.match(employee, /Employee/);
  assert.match(employee, /Campus Services/);
});

test('synthetic fixture serves no-store GET pages and never accepts writes', async () => {
  const directory = await fetch(origin + '/guidance/clients');
  assert.equal(directory.status, 200);
  assert.equal(directory.headers.get('cache-control'), 'private, no-store');
  const fragment = await fetch(origin + '/guidance/clients?kind=employee&fragment=1');
  assert.equal(fragment.status, 200);
  const text = await fragment.text();
  assert.match(text, /SYNTH-002/);
  assert.doesNotMatch(text, /SYNTH-001/);
  const write = await fetch(origin + '/guidance/clients', { method: 'POST', body: 'synthetic=1' });
  assert.equal(write.status, 405);
});

test('cumulative record tabs, KPI cards, history page size, and Back/Forward update only the history region', async () => {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));

  try {
    await page.goto(origin + '/guidance/clients/1', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-guidance-client-record]');

    const handles = await page.evaluateHandle(() => ({
      main: document.getElementById('main'),
      profile: document.querySelector('[data-refresh-region="profile"]'),
      history: document.querySelector('[data-refresh-region="history"]'),
      kpiRow: document.querySelector('[data-guidance-client-record] > .row'),
    }));

    const assertSameShell = async label => {
      const same = await page.evaluate(nodes => (
        nodes.main === document.getElementById('main')
        && nodes.profile === document.querySelector('[data-refresh-region="profile"]')
        && nodes.history === document.querySelector('[data-refresh-region="history"]')
        && nodes.kpiRow === document.querySelector('[data-guidance-client-record] > .row')
      ), handles);
      assert.equal(same, true, `${label} must keep #main, profile, KPI row, and history region mounted`);
    };

    await page.evaluate(() => window.scrollTo(0, 120));

    await page.click('.nav-tabs a[data-record-tab-link="cases"]');
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Cases & Episodes'
      && new URL(location.href).searchParams.get('tab') === 'cases'
    ));
    await assertSameShell('Cases tab click');
    const scrollAfterTab = await page.evaluate(() => window.scrollY);
    assert.ok(Math.abs(scrollAfterTab - 120) <= 2, `Expected scrollY to remain near 120, got ${scrollAfterTab}`);
    assert.match(await page.locator('[data-refresh-region="history"]').innerText(), /Authorized counseling cases/);

    // Browser Back back to the initial overview entry must also stay regional.
    await page.goBack();
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Overview'
      && !new URL(location.href).searchParams.get('tab')
    ));
    await assertSameShell('Browser Back to initial Overview');

    await page.goForward();
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Cases & Episodes'
      && new URL(location.href).searchParams.get('tab') === 'cases'
    ));
    await assertSameShell('Browser Forward to Cases');

    await page.click('.nav-tabs a[data-record-tab-link="sessions"]');
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Sessions & Follow-ups'
      && new URL(location.href).searchParams.get('tab') === 'sessions'
    ));
    await assertSameShell('Sessions tab click');
    assert.match(await page.locator('[data-refresh-region="history"]').innerText(), /Session chronology/);

    await page.click('.nav-tabs a[data-record-tab-link="assessments"]');
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Assessments & Surveys'
      && new URL(location.href).searchParams.get('tab') === 'assessments'
    ));
    await assertSameShell('Assessments tab click');
    assert.match(await page.locator('[data-refresh-region="history"]').innerText(), /Multiple Intelligence history/);

    // Click a KPI summary card above the tabs.
    await page.click('[data-guidance-client-record] > .row a[data-record-tab-link="cases"]');
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Cases & Episodes'
      && new URL(location.href).searchParams.get('tab') === 'cases'
    ));
    await assertSameShell('KPI summary card click');

    // Change Per history page size.
    await page.selectOption('#guidanceHistorySize', '50');
    await page.waitForFunction(() => (
      new URL(location.href).searchParams.get('per_page') === '50'
      && document.getElementById('guidanceHistorySize')?.value === '50'
    ));
    await assertSameShell('Per history select change');
    const kpiHref = await page.locator('[data-guidance-client-record] > .row a[data-record-tab-link="sessions"]').first().getAttribute('href');
    assert.match(kpiHref || '', /per_page=50/, 'Top KPI card links should sync per_page query');

    // Overview engagement card click.
    await page.click('.nav-tabs a[data-record-tab-link="overview"]');
    await page.waitForFunction(() => document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Overview');
    await assertSameShell('Overview tab click');
    await page.click('#guidance-record-section a[data-record-tab-link="appointments"]');
    await page.waitForFunction(() => (
      document.querySelector('[data-refresh-heading]')?.textContent.trim() === 'Appointments & Call Slips'
      && new URL(location.href).searchParams.get('tab') === 'appointments'
    ));
    await assertSameShell('Overview engagement card click');

    assert.deepEqual(pageErrors, [], 'Expected no uncaught page errors');
  } finally {
    await page.close();
  }
});
