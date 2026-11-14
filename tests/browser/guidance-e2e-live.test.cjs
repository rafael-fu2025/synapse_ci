'use strict';

/**
 * Live End-to-End Playwright Browser Walkthrough using Real Seeded Test Accounts
 * against the running local application (http://localhost:8080/index.php) + MySQL DB.
 *
 * Run:
 *   node --test tests/browser/guidance-e2e-live.test.cjs
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));

const BASE_URL = (process.env.SYNAPSE_LIVE_BASE_URL || 'http://localhost:8080/index.php').replace(/\/+$/, '');
const TEST_PASSWORD = 'password123';

function findChromiumExecutable() {
  if (fs.existsSync(chromium.executablePath())) {
    return chromium.executablePath();
  }
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const found = fs.readdirSync(dir)
    .filter((n) => /^chromium-\d+$/.test(n))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map((n) => path.join(dir, n, 'chrome-win64/chrome.exe'))
    .find((p) => fs.existsSync(p));
  if (!found) {
    throw new Error('Playwright Chromium executable not found.');
  }
  return found;
}

function resetActivationGatesToDefault() {
  const php = `
    $env = [];
    foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $env[$k] = trim($v, "'\\"");
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['database.default.hostname'] ?? '127.0.0.1', $env['database.default.port'] ?? '3306', $env['database.default.database'] ?? 'humus_db');
    $pdo = new PDO($dsn, $env['database.default.username'] ?? 'root', $env['database.default.password'] ?? '');
    $pdo->exec("UPDATE guidance_activation_gates SET recorded_signoffs_json = '[]', is_enabled = 0, approved_at = NULL");
  `;
  execFileSync('php', ['-r', php], { cwd: root });
}

async function createTrackedContext(browser) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const consoleErrors = [];
  const pageErrors = [];
  const failed500s = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const txt = msg.text();
      if (!/Failed to load resource: the server responded with a status of (403|404|422)/.test(txt)) {
        consoleErrors.push(txt);
      }
    }
  });
  page.on('pageerror', (err) => pageErrors.push(`[${page.url()}] ${err?.stack || String(err)}`));
  page.on('response', (res) => {
    if (res.status() >= 500) {
      failed500s.push(`${res.status()} ${res.url()}`);
    }
  });

  return { context, page, consoleErrors, pageErrors, failed500s };
}

async function loginAs(page, username, expectedUrlPart) {
  const loginRes = await page.goto(`${BASE_URL}/auth/login`, { waitUntil: 'domcontentloaded' });
  assert.equal(loginRes.status(), 200, `Login page accessible for ${username}`);

  await page.locator('#identifier').fill(username);
  await page.locator('#password').fill(TEST_PASSWORD);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    page.getByRole('button', { name: 'Sign in', exact: true }).click(),
  ]);

  assert.ok(!page.url().includes('/auth/login'), `Expected ${username} to leave /auth/login, stayed at ${page.url()}`);
  if (expectedUrlPart) {
    assert.ok(page.url().includes(expectedUrlPart), `Expected URL to contain '${expectedUrlPart}', got ${page.url()}`);
  }
}

async function assertCleanPage(page, urlPath, expectedHeadingOrText) {
  const fullUrl = urlPath.startsWith('http') ? urlPath : `${BASE_URL}${urlPath}`;
  const res = await page.goto(fullUrl, { waitUntil: 'domcontentloaded' });
  assert.equal(res.status(), 200, `Expected HTTP 200 on ${fullUrl}, got ${res.status()}`);
  if (expectedHeadingOrText) {
    const bodyText = await page.locator('body').innerText();
    assert.ok(
      bodyText.includes(expectedHeadingOrText),
      `Expected '${expectedHeadingOrText}' on ${fullUrl}`
    );
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 2, `Horizontal overflow (${overflow}px) detected on ${fullUrl}`);
  return res;
}

/**
 * Clicks a submit trigger, confirms `#synapseConfirmModal` if triggered, awaits the matching POST response,
 * and asserts success (HTTP < 400, plus JSON `success === true` when JSON).
 */
async function submitAndVerify(page, triggerLocator, expectedUrlSubstring) {
  const responsePromise = page.waitForResponse(
    (r) => r.request().method() === 'POST' && r.url().includes(expectedUrlSubstring),
    { timeout: 20000 }
  );

  await triggerLocator.click();

  const confirmModal = page.locator('#synapseConfirmModal');
  const shown = await confirmModal.waitFor({ state: 'visible', timeout: 1500 }).then(() => true).catch(() => false);
  if (shown) {
    await confirmModal.locator('[data-confirm-accept]').click();
  }

  const res = await responsePromise;
  const ct = (res.headers()['content-type'] || '').toLowerCase();
  const body = await res.text().catch(() => '');
  assert.ok(res.status() < 400, `POST ${expectedUrlSubstring} failed with ${res.status()}: ${body.slice(0, 300)}`);
  if (ct.includes('application/json')) {
    const parsed = JSON.parse(body);
    assert.equal(parsed.success, true, `POST ${expectedUrlSubstring} JSON error: ${JSON.stringify(parsed)}`);
  }
  await page.waitForTimeout(500);
  return { res, body };
}

test('Live E2E Guidance Walkthrough with Real Database Accounts', { timeout: 300000 }, async (t) => {
  const browser = await chromium.launch({ executablePath: findChromiumExecutable(), headless: true });

  let createdFormId = null;
  let createdCaseId = null;

  try {
    // -------------------------------------------------------------------------
    // 1. Guidance Admin: Practitioners, Supervision, Gates Sign-off & Evaluations
    // -------------------------------------------------------------------------
    await t.test('1. Guidance Admin registers practitioners + supervision and signs off test gates via UI', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.guidance.admin', '/guidance');
        await assertCleanPage(page, '/guidance', 'Guidance');
        await assertCleanPage(page, '/guidance/governance/practitioners', 'Practitioner Credentials');

        // Register test.counsellor as Counselor Associate
        const regForm = page.locator('form[action*="/guidance/governance/practitioners/register"]');
        const counsellorVal = await regForm.locator('select[name="user_id"] option', { hasText: 'test.counsellor' }).first().getAttribute('value');
        assert.ok(counsellorVal, 'test.counsellor option must exist');
        await regForm.locator('select[name="user_id"]').selectOption(counsellorVal);
        await regForm.locator('select[name="credential_tier"]').selectOption('counselor_associate');
        await submitAndVerify(page, regForm.locator('button[type="submit"]'), '/guidance/governance/practitioners/register');

        // Register test.guidance.supervisor as Guidance Supervisor
        await assertCleanPage(page, '/guidance/governance/practitioners', 'Practitioner Credentials');
        const supVal = await regForm.locator('select[name="user_id"] option', { hasText: 'test.guidance.supervisor' }).first().getAttribute('value');
        assert.ok(supVal, 'test.guidance.supervisor option must exist');
        await regForm.locator('select[name="user_id"]').selectOption(supVal);
        await regForm.locator('select[name="credential_tier"]').selectOption('guidance_supervisor');
        await submitAndVerify(page, regForm.locator('button[type="submit"]'), '/guidance/governance/practitioners/register');

        // Assign supervision (test.counsellor -> test.guidance.supervisor)
        await assertCleanPage(page, '/guidance/governance/practitioners', 'Practitioner Credentials');
        const supForm = page.locator('form[action*="/guidance/governance/practitioners/supervision"]');
        const assocOpt = await supForm.locator('select[name="associate_user_id"] option', { hasText: 'test.counsellor' }).first().getAttribute('value');
        const supervisorOpt = await supForm.locator('select[name="supervisor_user_id"] option', { hasText: 'test.guidance.supervisor' }).first().getAttribute('value');
        assert.ok(assocOpt && supervisorOpt, 'Both associate and supervisor options must exist');
        await supForm.locator('select[name="associate_user_id"]').selectOption(assocOpt);
        await supForm.locator('select[name="supervisor_user_id"]').selectOption(supervisorOpt);
        await submitAndVerify(page, supForm.locator('button[type="submit"]'), '/guidance/governance/practitioners/supervision');

        // Sign off canonical_forms_signoff, safety_escalation_sop_signoff, mi_reproduction_signoff cleanly
        await assertCleanPage(page, '/guidance/governance/gates', 'Rollout Activation Gates');
        for (const gateKey of ['canonical_forms_signoff', 'safety_escalation_sop_signoff', 'mi_reproduction_signoff']) {
          for (const authRole of ['guidance_head', 'qa_office', 'dpo']) {
            const gateForm = page.locator(`form[action*="/guidance/governance/gates/${gateKey}/signoff"]`);
            await gateForm.locator('select[name="authority_role"]').selectOption(authRole);
            await submitAndVerify(page, gateForm.locator('button[type="submit"]'), `/guidance/governance/gates/${gateKey}/signoff`);
          }
        }

        await assertCleanPage(page, '/guidance/evaluations/dashboard', 'Evaluation');
        await assertCleanPage(page, '/guidance/evaluations/moderate', 'Moderation');

        assert.deepEqual(failed500s, [], 'No HTTP 500 errors');
        assert.deepEqual(pageErrors, [], 'No page errors');
        assert.deepEqual(consoleErrors, [], 'No console errors');
      } finally {
        await context.close();
      }
    });

    // -------------------------------------------------------------------------
    // 2. Employee Referrer: Exact-ID Lookup + Submit Live General Referral
    // -------------------------------------------------------------------------
    await t.test('2. Employee resolves HUMUS-TEST-STUDENT by exact ID and submits a Live General Referral', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.employee', '/portal');
        await assertCleanPage(page, '/portal', 'Guidance referral');
        await assertCleanPage(page, '/portal/referrals/create', 'Guidance General Referral Form');

        // Ensure canonical_forms_signoff enabled the Submit button
        const submitBtn = page.locator('button[value="submit"]');
        assert.equal(await submitBtn.isDisabled(), false, 'Submit Live Referral button must be enabled after gate signoff');

        // Narrow exact-ID lookup
        await page.locator('#exactMisIdInput').fill('HUMUS-TEST-STUDENT');
        const [resolveRes] = await Promise.all([
          page.waitForResponse((r) => r.url().includes('/portal/referrals/resolve-subject') && r.request().method() === 'POST'),
          page.locator('#resolveSubjectBtn').click(),
        ]);
        assert.equal(resolveRes.status(), 200, 'Subject resolve should return 200');
        const resolveJson = await resolveRes.json();
        assert.equal(resolveJson.success, true, 'Exact-ID resolve must succeed');
        await page.locator('#resolvedSubjectCard').waitFor({ state: 'visible' });

        await page.locator('input[name="reasons[]"]').first().check();
        await page.locator('#reasonsNarrative').fill('Live E2E employee referral for attendance and advising support.');

        await submitAndVerify(page, submitBtn, '/portal/referrals/store');
        await assertCleanPage(page, '/portal/referrals/create', 'My Submitted & Draft Referrals');

        const bodyText = await page.locator('body').innerText();
        assert.match(bodyText, /GR-/, 'Submitted referral number GR-* must appear in sidebar');
        assert.deepEqual(failed500s, [], 'No HTTP 500 errors');
        assert.deepEqual(pageErrors, [], 'No page errors');
        assert.deepEqual(consoleErrors, [], 'No console errors');
      } finally {
        await context.close();
      }
    });

    // -------------------------------------------------------------------------
    // 3. Counselor: Intake Receive -> Safety Assess -> Triage -> Link Case
    //    -> Issue Call Slip & Verify PDF -> Assign MI -> Author Clinical Form & Submit Co-Sign
    // -------------------------------------------------------------------------
    await t.test('3. Counselor processes Intake, Safety, Call Slip PDF, MI Assign & Clinical Co-sign Submission', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.counsellor', '/guidance');
        await assertCleanPage(page, '/guidance/intake', 'Guidance Intake');

        const intakeLink = page.locator('table a[href*="/guidance/intake/"]').first();
        await intakeLink.waitFor({ state: 'visible', timeout: 10000 });
        const intakeHref = await intakeLink.getAttribute('href');
        await assertCleanPage(page, intakeHref, 'Referral');

        // Receive referral if still in submitted status
        const receiveBtn = page.locator('form[action$="/receive"] button[type="submit"]');
        if (await receiveBtn.count() > 0 && await receiveBtn.isVisible()) {
          await submitAndVerify(page, receiveBtn, '/receive');
          await assertCleanPage(page, intakeHref, 'Referral');
        }

        // Record Authoritative Safety Assessment (low risk)
        const safetyForm = page.locator('form[action*="/guidance/safety/assess"]');
        await safetyForm.locator('select[name="risk_level"]').selectOption('low');
        await safetyForm.locator('textarea[name="immediate_actions_taken"]').fill('Live E2E safety assessment: stable.');
        await submitAndVerify(page, safetyForm.locator('button[type="submit"]'), '/guidance/safety/assess');

        // Save Triage Notes (input[name="triage_notes"])
        await assertCleanPage(page, intakeHref, 'Referral');
        const triageForm = page.locator('form[action$="/triage"]');
        await triageForm.locator('input[name="triage_notes"]').fill('Triaged in live Playwright E2E test.');
        await submitAndVerify(page, triageForm.locator('button[type="submit"]'), '/triage');

        // Link / Create Counseling Case from Intake
        await assertCleanPage(page, intakeHref, 'Referral');
        const linkCaseForm = page.locator('form[action$="/link-case"]');
        if (await linkCaseForm.count() > 0 && await linkCaseForm.isVisible()) {
          await submitAndVerify(page, linkCaseForm.locator('button[type="submit"]'), '/link-case');
          await assertCleanPage(page, intakeHref, 'Referral');
        }
        const linkedCaseHref = await page.locator('a[href*="/guidance/cases/"]').first().getAttribute('href');
        const caseMatch = (linkedCaseHref || '').match(/\/guidance\/cases\/(\d+)/);
        assert.ok(caseMatch, `Expected linked case URL on intake page, got: ${linkedCaseHref}`);
        createdCaseId = Number(caseMatch[1]);

        // Issue a Call Slip for Test Student via modal on /guidance/call-slips
        await assertCleanPage(page, '/guidance/call-slips', 'Guidance Call Slips');
        const issueBtn = page.locator('[data-bs-target="#issueCallSlipModal"]').first();
        await issueBtn.waitFor({ state: 'visible', timeout: 8000 });
        await issueBtn.click();
        const modal = page.locator('#issueCallSlipModal');
        await modal.waitFor({ state: 'visible' });
        const searchInput = modal.locator('input[placeholder*="Search exact student"]');
        await searchInput.fill('HUMUS-TEST-STUDENT');
        const resultBtn = modal.locator('.list-group-item').first();
        await resultBtn.waitFor({ state: 'visible', timeout: 8000 });
        await resultBtn.click();
        await submitAndVerify(page, modal.locator('button[type="submit"]'), '/guidance/call-slips/issue');

        // Check Call Slip PDF Preview endpoint returns valid %PDF- bytes
        await assertCleanPage(page, '/guidance/call-slips', 'Guidance Call Slips');
        const pdfLink = page.locator('a[href*="/guidance/call-slips/"][href*="/pdf"]').first();
        await pdfLink.waitFor({ state: 'visible', timeout: 8000 });
        const pdfHref = await pdfLink.getAttribute('href');
        const pdfRes = await page.request.get(pdfHref);
        assert.equal(pdfRes.status(), 200, 'Call slip PDF preview must return HTTP 200');
        assert.match(pdfRes.headers()['content-type'] || '', /application\/pdf/i);
        const pdfBytes = await pdfRes.body();
        assert.equal(pdfBytes.subarray(0, 5).toString('utf8'), '%PDF-', 'Call slip output must start with %PDF-');

        // Assign MI survey to HUMUS-TEST-STUDENT via exact MIS ID input
        await assertCleanPage(page, '/guidance/assessments/mi', 'Multiple Intelligence');
        const miForm = page.locator('form[action*="/guidance/assessments/mi/assign"]');
        await miForm.locator('input[name="mis_id"]').fill('HUMUS-TEST-STUDENT');
        const miResPromise = page.waitForResponse(
          (r) => r.request().method() === 'POST' && r.url().includes('/guidance/assessments/mi/assign'),
          { timeout: 15000 }
        );
        await Promise.all([
          page.waitForURL(/\/guidance\/assessments\/mi/, { timeout: 15000 }),
          miResPromise,
          miForm.locator('button[type="submit"]').click(),
        ]);
        const miRes = await miResPromise;
        assert.ok([200, 302, 303].includes(miRes.status()), `Unexpected MI assign status: ${miRes.status()}`);

        // Navigate to linked Case -> Verify PSP / Tier display + Cumulative Record link -> Clinical Forms Tab
        await assertCleanPage(page, `/guidance/cases/${createdCaseId}`, 'Clinical Forms & Attestations');
        const caseBodyText = await page.locator('body').innerText();
        assert.match(caseBodyText, /Psychosocial Processing \(PSP\)|Counseling/, 'Case view must display PSP/Counseling distinction');

        const cumLink = page.locator('a[href*="/guidance/students/"][href*="/cumulative"]').first();
        await cumLink.waitFor({ state: 'visible', timeout: 5000 });
        const cumHref = await cumLink.getAttribute('href');
        await assertCleanPage(page, cumHref, 'Consolidated Guidance Cumulative Record');

        // Verify Periodic Guidance Summary on /guidance dashboard
        await assertCleanPage(page, '/guidance', 'Periodic Guidance Service Summary');
        const summaryResPromise = page.waitForResponse((r) => r.url().includes('/guidance/reports/summary'), { timeout: 10000 });
        await page.locator('#gd-summary-load').click();
        const summaryRes = await summaryResPromise;
        assert.equal(summaryRes.status(), 200, 'Periodic summary JSON endpoint must return HTTP 200');
        await page.locator('#gd-summary-grid').getByText('PSP Sessions (Associates)').waitFor({ state: 'visible', timeout: 5000 });

        await assertCleanPage(page, `/guidance/cases/${createdCaseId}`, 'Clinical Forms & Attestations');
        await page.locator('#case-clinical-forms-tab').click();
        const newPifLink = page.locator('a[href*="/guidance/clinical/forms/new/psychosocial_support_interview"]').first();
        await newPifLink.waitFor({ state: 'visible', timeout: 5000 });
        const newPifHref = await newPifLink.getAttribute('href');
        await assertCleanPage(page, newPifHref, 'Psychosocial');

        const editor = page.locator('#clinicalFormEditor');
        await editor.locator('input[name="fields[course_year]"]').fill('BS Computer Science - Year 2');
        await editor.locator('textarea[name="fields[presenting_concerns]"]').fill('Academic workload stress and sleep disruption.');
        await editor.locator('textarea[name="fields[counselor_assessment]"]').fill('Cooperative client experiencing situational transition strain.');
        await editor.locator('textarea[name="fields[intervention_plan]"]').fill('CBT time-management strategies and biweekly follow-up.');
        await editor.locator('input[name="fields[privacy_consent_acknowledged]"][type="checkbox"]').check();

        await submitAndVerify(page, editor.locator('button[type="submit"]').first(), '/guidance/clinical/forms/create');
        await page.waitForURL(/\/guidance\/clinical\/forms\/\d+/, { timeout: 15000 });
        const urlMatch = page.url().match(/\/guidance\/clinical\/forms\/(\d+)/);
        assert.ok(urlMatch, `Expected navigation to /guidance/clinical/forms/{id}, got ${page.url()}`);
        createdFormId = Number(urlMatch[1]);

        // Associate Counselor submits draft for Supervisor Co-Sign
        const submitCosignBtn = page.locator('form[action*="/submit-cosign"] button[type="submit"]');
        await submitCosignBtn.waitFor({ state: 'visible', timeout: 8000 });
        await page.locator('#submissionNotes').fill('Live E2E Associate submission for supervisor review.');
        await submitAndVerify(page, submitCosignBtn, `/guidance/clinical/forms/${createdFormId}/submit-cosign`);

        assert.deepEqual(failed500s, [], 'No HTTP 500 errors');
        assert.deepEqual(pageErrors, [], 'No page errors');
        assert.deepEqual(consoleErrors, [], 'No console errors');
      } finally {
        await context.close();
      }
    });

    // -------------------------------------------------------------------------
    // 4. Student Portal Self-Service: Call Slip Ack + MI Survey Finalize + PDF + Verify
    // -------------------------------------------------------------------------
    await t.test('4. Student acknowledges Call Slip, completes MI Survey, downloads MI PDF, and checks Portal views', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.student', '/portal');
        await assertCleanPage(page, '/portal', 'Guidance Call Slips');

        // Acknowledge pending Call Slip created in Step 3
        const ackBtn = page.locator('form[action*="/portal/guidance/call-slips/"][action$="/acknowledge"] button[type="submit"]').first();
        await ackBtn.waitFor({ state: 'visible', timeout: 8000 });
        await submitAndVerify(page, ackBtn, '/acknowledge');

        // Complete assigned 80-item MI Survey across all 8 sections + digital attestation
        await assertCleanPage(page, '/portal/assessments/mi', 'Multiple Intelligences Survey');
        const takeLink = page.locator('a[href*="/portal/assessments/mi/"]').filter({ hasNotText: /results/i }).first();
        await takeLink.waitFor({ state: 'visible', timeout: 8000 });
        const takeHref = await takeLink.getAttribute('href');
        await assertCleanPage(page, takeHref, 'Multiple Intelligence');

        for (let sec = 0; sec < 8; sec++) {
          const pane = page.locator(`.mi-section-pane[data-section-index="${sec}"]`);
          await pane.waitFor({ state: 'visible', timeout: 5000 });
          const secPills = pane.locator('label.mi-likert-option:has(input[value="4"])');
          const secCount = await secPills.count();
          assert.equal(secCount, 10, `Section ${sec} must contain 10 Likert items`);
          for (let i = 0; i < secCount; i++) {
            await secPills.nth(i).click();
          }
          if (sec < 7) {
            await pane.locator('.mi-next-btn').click();
          }
        }

        await page.locator('#mi-attestation-confirmed').check();
        const finalizeResPromise = page.waitForResponse(
          (r) => r.request().method() === 'POST' && r.url().includes('/finalize'),
          { timeout: 20000 }
        );
        await Promise.all([
          page.waitForURL(/\/portal\/assessments\/mi\/results\/\d+/, { timeout: 20000 }),
          finalizeResPromise,
          page.locator('#mi-finalize-btn').click(),
        ]);
        const finalizeRes = await finalizeResPromise;
        assert.equal(finalizeRes.status(), 200, `MI finalize must return HTTP 200, got ${finalizeRes.status()}`);

        // Verify completed MI PDF download works
        await assertCleanPage(page, '/portal/assessments/mi', 'Multiple Intelligences Survey');
        const miPdfLink = page.locator('a[href*="/portal/assessments/mi/results/"][href*="/pdf"]').first();
        await miPdfLink.waitFor({ state: 'visible', timeout: 8000 });
        const miPdfHref = await miPdfLink.getAttribute('href');
        const miPdfRes = await page.request.get(miPdfHref);
        assert.equal(miPdfRes.status(), 200, 'Student MI PDF must return HTTP 200');
        assert.equal((await miPdfRes.body()).subarray(0, 5).toString('utf8'), '%PDF-', 'MI PDF must start with %PDF-');

        await assertCleanPage(page, '/portal/evaluations/redeem', 'Evaluation');

        // Verify Student Selective Contact Phone & Boarding/Current Residence Update on Portal Settings
        await assertCleanPage(page, '/portal/settings', 'Contact number & current residence');
        const contactForm = page.locator('form[action*="/portal/settings/contact-info"]');
        await contactForm.locator('input[name="phone"]').fill('09175550199');
        await contactForm.locator('input[name="address"]').fill('Dorm C, Hibbard Ave, Dumaguete City');
        await submitAndVerify(page, contactForm.locator('button[type="submit"]'), '/portal/settings/contact-info');

        const verifyRes = await page.goto(`${BASE_URL}/guidance/verify/nonexistent-e2e-token-000`, { waitUntil: 'domcontentloaded' });
        assert.ok([200, 404].includes(verifyRes.status()), `Expected 200/404 on verify fallback, got ${verifyRes.status()}`);

        assert.deepEqual(failed500s, [], 'No HTTP 500 errors');
        assert.deepEqual(pageErrors, [], 'No page errors');
        assert.deepEqual(consoleErrors, [], 'No console errors');
      } finally {
        await context.close();
      }
    });

    // -------------------------------------------------------------------------
    // 5. Guidance Supervisor Co-Sign + Superadmin Boundary Check
    // -------------------------------------------------------------------------
    await t.test('5. Guidance Supervisor co-signs pending clinical form & Superadmin respects non-clinical boundary', async () => {
      assert.ok(createdFormId, 'createdFormId must be set by Step 3');
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.guidance.supervisor', '/guidance');
        await assertCleanPage(page, '/guidance', 'Guidance');
        await assertCleanPage(page, '/guidance/clinical/cosign-queue', 'Co-Sign');

        await assertCleanPage(page, `/guidance/clinical/forms/${createdFormId}`, 'Psychosocial');
        await page.locator('#supervisorNotes').fill('Live E2E supervisor clinical review completed and approved.');
        await page.locator('#coSignConfirm').check();
        const cosignBtn = page.locator(`form[action*="/guidance/clinical/forms/${createdFormId}/cosign"] button[type="submit"]`);
        await cosignBtn.waitFor({ state: 'visible', timeout: 8000 });
        await submitAndVerify(page, cosignBtn, `/guidance/clinical/forms/${createdFormId}/cosign`);
        await assertCleanPage(page, `/guidance/clinical/forms/${createdFormId}`, 'Finalized & Attested');

        // Verify finalized clinical PDF renders cleanly
        const clinPdfRes = await page.request.get(`${BASE_URL}/guidance/clinical/forms/${createdFormId}/pdf?mode=preview`);
        assert.equal(clinPdfRes.status(), 200, 'Clinical form PDF preview must return HTTP 200');
        assert.equal((await clinPdfRes.body()).subarray(0, 5).toString('utf8'), '%PDF-', 'Clinical form PDF must start with %PDF-');

        await assertCleanPage(page, '/guidance/evaluations/dashboard', 'Evaluation');

        // Verify Intake walk-in referral drawer + Cumulative Record shows student-updated contact & MI attempt
        await assertCleanPage(page, '/guidance/intake', 'Record Walk-In / Teacher Referral');
        await assertCleanPage(page, `/guidance/cases/${createdCaseId}`, 'Cumulative Record');
        const supCumHref = await page.locator('a[href*="/guidance/students/"][href*="/cumulative"]').first().getAttribute('href');
        await assertCleanPage(page, supCumHref, '09175550199');
        assert.match(await page.locator('body').innerText(), /Dorm C, Hibbard Ave, Dumaguete City/);

        assert.deepEqual(failed500s, [], 'No HTTP 500 errors');
        assert.deepEqual(pageErrors, [], 'No page errors');
        assert.deepEqual(consoleErrors, [], 'No console errors');
      } finally {
        await context.close();
      }

      const supCtx = await createTrackedContext(browser);
      try {
        await loginAs(supCtx.page, 'test.superadmin', '/');
        await assertCleanPage(supCtx.page, '/guidance/governance/gates', 'Rollout Activation Gates');
        const clinicalRes = await supCtx.page.goto(`${BASE_URL}/guidance/clinical/forms/new/psychosocial_support_interview`, { waitUntil: 'domcontentloaded' });
        assert.notEqual(clinicalRes.status(), 500, 'Superadmin clinical new form must not return 500');
        assert.deepEqual(supCtx.failed500s, [], 'No HTTP 500 errors for superadmin');
      } finally {
        await supCtx.context.close();
      }
    });
  } finally {
    await browser.close();
    resetActivationGatesToDefault();
  }
});
