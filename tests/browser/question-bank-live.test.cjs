'use strict';

// Opt-in LOCAL UI/HTTP check. Creates one synthetic bank entry and archives it.
// Never saves/publishes a survey, sends notifications, or deletes audit history.
// SYNAPSE_LIVE_QUESTION_BANK_TEST=1 node --test tests/browser/question-bank-live.test.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const root = path.resolve(__dirname, '../..');
const base = 'http://guidance.localhost:8080/index.php/guidance';
const origin = 'http://guidance.localhost:8080';
const enabled = process.env.SYNAPSE_LIVE_QUESTION_BANK_TEST === '1';

function executable(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  return fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
}

async function saveBank(page) {
  const modal = page.locator('#appFormModal');
  const action = await modal.locator('form[data-modal-submit]').getAttribute('action');
  let result;
  const forward = async route => {
    const response = await route.fetch({ maxRedirects: 0 });
    const body = await response.body();
    result = { status: response.status(), data: JSON.parse(body.toString()) };
    await route.fulfill({ response, body });
  };
  await page.route(action, forward);
  try {
    await Promise.all([page.waitForResponse(r => r.url() === action && r.request().method() === 'POST'),
      modal.getByRole('button', { name: 'Save bank question', exact: true }).click()]);
    assert.equal(result.status, 200, result.data.message);
    assert.equal(result.data.success, true);
    await modal.waitFor({ state: 'hidden' });
    await page.waitForURL(base + '/question-bank');
  } finally { await page.unroute(action, forward); }
}

test('local Guidance bank: create, copy, revise, archive and preserve unsaved survey snapshots', {
  skip: !enabled && 'Set SYNAPSE_LIVE_QUESTION_BANK_TEST=1 to authorize a synthetic LOCAL bank entry', timeout: 120000
}, async t => {
  const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
  const browser = await chromium.launch({ headless: true, executablePath: executable(chromium) });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  const errors = [];
  context.on('page', p => p.on('pageerror', error => errors.push(error.message)));
  page.on('pageerror', error => errors.push(error.message));
  const label = 'QA bank verification ' + randomUUID();
  const catalog = async () => {
    const response = await page.request.get(base + '/question-bank/catalog?q=' + encodeURIComponent(label), { headers: { Accept: 'application/json' } });
    assert.equal(response.status(), 200);
    const data = await response.json();
    return data.items.filter(item => item.question.label === label || item.question.label === label + ' revised');
  };
  let id;
  let creationAttempted = false;
  try {
    await page.goto(origin + '/index.php/auth/login');
    await page.locator('#identifier').fill('test.guidance.admin');
    await page.locator('#password').fill('password123');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
    const response = await page.goto(base + '/question-bank');
    assert.equal(response.status(), 200);
    const activeLinks = await page.locator('.sidebar-nav a.active').allTextContents();
    assert.ok(activeLinks.some(text => text.trim() === 'Question Bank'));
    assert.ok(!activeLinks.some(text => text.trim() === 'Guidance Dashboard'));
    await page.getByRole('link', { name: 'Add bank question', exact: true }).click();
    await page.locator('#bankQuestionText').waitFor();
    await page.locator('#bankQuestionText').fill(label);
    await page.locator('#bankQuestionCategory').fill('QA verification');
    await page.locator('#bankQuestionType').selectOption('single', { force: true });
    await page.getByRole('textbox', { name: 'Option 1', exact: true }).fill('Morning');
    await page.getByRole('textbox', { name: 'Option 2', exact: true }).fill('Afternoon');
    await page.locator('#bankReviewNote').fill('Synthetic local UI verification only; not a screening instrument.');
    creationAttempted = true;
    await saveBank(page);
    let entries = await catalog();
    assert.equal(entries.length, 1);
    id = entries[0].id;
    assert.equal(entries[0].revision, 1);
    t.diagnostic('PASS: Guidance-admin bank navigation, real modal creation and current-revision catalog');

    const survey = await context.newPage();
    survey.setDefaultTimeout(15000);
    await survey.goto(base + '/surveys/create');
    await survey.locator('#appFormModal').getByRole('button', { name: 'Add from question bank', exact: true }).click();
    await survey.locator('#surveyBankSearch').fill(label);
    const picker = survey.getByRole('region', { name: 'Question bank picker' });
    await picker.getByRole('button', { name: 'Search bank', exact: true }).click();
    await picker.getByRole('button', { name: 'Add copy', exact: true }).click();
    const first = survey.locator('#appFormModal fieldset').first();
    assert.equal(await first.locator('[name$="[bank_revision]"]').inputValue(), '1');
    assert.equal(await first.getByLabel('Question text').inputValue(), label);
    t.diagnostic('PASS: bank question copies into the survey builder with revision provenance');

    await page.goto(base + '/question-bank/' + id + '/edit');
    await page.locator('#bankQuestionText').fill(label + ' revised');
    await saveBank(page);
    entries = await catalog();
    assert.equal(entries[0].revision, 2);
    assert.equal(await first.getByLabel('Question text').inputValue(), label);
    assert.equal(await first.locator('[name$="[bank_revision]"]').inputValue(), '1');
    await picker.getByRole('button', { name: 'Search bank', exact: true }).click();
    await picker.getByText(label + ' revised', { exact: true }).waitFor();
    await picker.getByRole('button', { name: 'Add copy', exact: true }).click();
    const second = survey.locator('#appFormModal fieldset').nth(1);
    assert.equal(await second.locator('[name$="[bank_revision]"]').inputValue(), '2');
    await second.getByLabel('Question text').fill('Custom question, detached from bank');
    assert.equal(await second.locator('[name$="[bank_id]"]').count(), 0);
    t.diagnostic('PASS: revision 2 leaves the old copy untouched; editing a new copy detaches provenance');

    await page.goto(base + '/question-bank?q=' + encodeURIComponent(label));
    await page.getByRole('button', { name: 'Archive', exact: true }).click();
    const confirmation = page.locator('#synapseConfirmModal');
    await confirmation.waitFor({ state: 'visible' });
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), confirmation.locator('[data-confirm-accept]').click()]);
    assert.equal((await catalog()).length, 0);
    await picker.getByRole('button', { name: 'Search bank', exact: true }).click();
    await picker.getByText(/No matching active questions/).waitFor();
    assert.equal(await first.getByLabel('Question text').inputValue(), label);
    await page.goto(base + '/question-bank/' + id + '?revision=1');
    await page.getByRole('heading', { name: label, exact: true }).waitFor();
    assert.match(await page.locator('#main').innerText(), /Archived/);
    assert.deepEqual(errors, []);
    t.diagnostic('PASS: archive removes new selections but preserves history and existing copies; no survey was saved or published');
  } finally {
    try {
      if (creationAttempted) {
        const entries = await catalog();
        if (entries.length > 0) {
          assert.equal(entries.length, 1, 'Cleanup must select only the unique synthetic entry');
          const entry = entries[0];
          if (id !== undefined) assert.equal(entry.id, id);
          await page.goto(base + '/question-bank');
          const tokenName = await page.locator('meta[name=csrf-name]').getAttribute('content');
          const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
          const response = await page.request.post(base + '/question-bank/' + entry.id + '/archive', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, form: { version: entry.version, [tokenName]: token }
          });
          assert.equal(response.status(), 200, 'Cleanup must archive the synthetic bank entry');
        }
        assert.equal((await catalog()).length, 0);
        t.diagnostic('CLEANUP VERIFIED: synthetic bank entry archived; revisions/audit retained; no survey, response or notification created');
      }
    } finally { await context.close(); await browser.close(); }
  }
});
