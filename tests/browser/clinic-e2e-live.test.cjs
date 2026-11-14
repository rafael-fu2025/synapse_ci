'use strict';

/**
 * Live End-to-End Playwright Browser Walkthrough for Clinic Transcript Enhancements
 * using Real Seeded Test Accounts against http://localhost:8080/index.php + MySQL DB.
 *
 * Run:
 *   node --test tests/browser/clinic-e2e-live.test.cjs
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

function runPhpDb(code) {
  const bootstrap = `
    $env = [];
    foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $env[$k] = trim($v, "'\\"");
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['database.default.hostname'] ?? '127.0.0.1', $env['database.default.port'] ?? '3306', $env['database.default.database'] ?? 'humus_db');
    $pdo = new PDO($dsn, $env['database.default.username'] ?? 'root', $env['database.default.password'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  `;
  return execFileSync('php', ['-r', bootstrap + code], { cwd: root, encoding: 'utf8' });
}

function ensureInternFixture(suffix) {
  const hash = passwordHashPhp(TEST_PASSWORD);
  const out = runPhpDb(`
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
    $username = 'test.clinic.intern.${suffix}';
    $pdo->prepare("INSERT INTO people (tenant_id, kind, mis_id, first_name, last_name, email, created_at, updated_at) VALUES (?, 'employee', ?, 'Intern', 'Nurse${suffix}', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
      ->execute([$tenantId, 'EMP-INT-${suffix}', $username . '@synapse.invalid']);
    $internPersonId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee_profiles (tenant_id, person_id, department) VALUES (?, ?, 'University Clinic')")
      ->execute([$tenantId, $internPersonId]);
    $stmt = $pdo->prepare("INSERT INTO users (tenant_id, person_id, username, email, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'clinic_intern', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$tenantId, $internPersonId, $username, $username . '@synapse.invalid', '${hash}']);
    $internId = (int) $pdo->lastInsertId();
    $stuPersonId = (int) $pdo->query("SELECT u.person_id FROM users u WHERE u.tenant_id = {$tenantId} AND u.username = 'test.student' AND u.deleted_at IS NULL LIMIT 1")->fetchColumn();
    echo json_encode(['tenantId' => $tenantId, 'internUsername' => $username, 'internId' => $internId, 'internPersonId' => $internPersonId, 'studentPersonId' => $stuPersonId]);
  `);
  return JSON.parse(out.trim());
}

function passwordHashPhp(pw) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(pw)}, PASSWORD_BCRYPT);`], { cwd: root, encoding: 'utf8' }).trim();
}

function cleanupE2ERecords({ internId, internPersonId, encounterId, isolationIds, clearanceId, announcementId }) {
  runPhpDb(`
    if (${encounterId ? 1 : 0}) {
      $pdo->exec("DELETE FROM clinic_encounter_addenda WHERE encounter_id = " . (int) ${encounterId || 0});
      $pdo->exec("DELETE FROM clinic_lab_results WHERE encounter_id = " . (int) ${encounterId || 0});
      $pdo->exec("DELETE FROM clinic_treatments WHERE encounter_id = " . (int) ${encounterId || 0});
      $pdo->exec("DELETE FROM clinic_vitals WHERE encounter_id = " . (int) ${encounterId || 0});
      $pdo->exec("DELETE FROM clinic_encounters WHERE id = " . (int) ${encounterId || 0});
    }
    if (${clearanceId ? 1 : 0}) {
      $pdo->exec("DELETE FROM clinic_enrollment_clearances WHERE id = " . (int) ${clearanceId || 0});
    }
    foreach (${JSON.stringify(isolationIds || [])} as $isoId) {
      $pdo->exec("DELETE FROM clinic_isolation_cases WHERE id = " . (int) $isoId);
    }
    if (${announcementId ? 1 : 0}) {
      $pdo->exec("DELETE FROM notifications WHERE announcement_id = " . (int) ${announcementId || 0});
      $pdo->exec("DELETE FROM announcements_reads WHERE announcement_id = " . (int) ${announcementId || 0});
      $pdo->exec("DELETE FROM announcements WHERE id = " . (int) ${announcementId || 0});
    }
    if (${internId ? 1 : 0}) {
      $pdo->exec("DELETE FROM users WHERE id = " . (int) ${internId || 0});
    }
    if (${internPersonId ? 1 : 0}) {
      $pdo->exec("DELETE FROM employee_profiles WHERE person_id = " . (int) ${internPersonId || 0});
      $pdo->exec("DELETE FROM people WHERE id = " . (int) ${internPersonId || 0});
    }
  `);
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

async function assertCleanPage(page, urlPath, expectedText) {
  const fullUrl = urlPath.startsWith('http') ? urlPath : `${BASE_URL}${urlPath}`;
  const res = await page.goto(fullUrl, { waitUntil: 'load' });
  assert.equal(res.status(), 200, `Expected HTTP 200 on ${fullUrl}, got ${res.status()}`);
  if (expectedText) {
    const bodyText = await page.locator('body').innerText();
    assert.ok(bodyText.includes(expectedText), `Expected '${expectedText}' on ${fullUrl}`);
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 2, `Horizontal overflow (${overflow}px) detected on ${fullUrl}`);
  return res;
}

async function submitAndConfirmModal(page, triggerLocator, expectedUrlSubstring) {
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
  if (ct.includes('application/json') && body.trim() !== '') {
    const parsed = JSON.parse(body);
    assert.equal(parsed.success, true, `POST ${expectedUrlSubstring} JSON error: ${JSON.stringify(parsed)}`);
  }
  await page.waitForTimeout(400);
  return { res, body };
}

test('Live E2E Clinic Transcript Enhancements Walkthrough with Real Accounts', { timeout: 300000 }, async (t) => {
  const suffix = String(Date.now()).slice(-6);
  const fixture = ensureInternFixture(suffix);
  const cleanupState = {
    internId: fixture.internId,
    internPersonId: fixture.internPersonId,
    encounterId: null,
    isolationIds: [],
    clearanceId: null,
    announcementId: null,
  };

  const browser = await chromium.launch({ executablePath: findChromiumExecutable(), headless: true });

  try {
    // 1. Student Nurse (clinic_intern): Vitals + Structured Complaint + Draft without Seal permission
    await t.test('1. Clinic Intern encodes backlog visit & structured complaint without seal privileges', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, fixture.internUsername, '/clinic');
        await assertCleanPage(page, '/clinic/encounters', 'Clinical Encounters');
        await assertCleanPage(page, '/clinic/isolation', 'Isolation & Outbreak Monitoring');

        await assertCleanPage(page, `/clinic/patients/${fixture.studentPersonId}`, 'Visit Frequency Summary');
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'load', timeout: 15000 }),
          page.locator(`form[action*="/clinic/encounters/start-direct/${fixture.studentPersonId}"] button[type="submit"]`).click(),
        ]);

        const match = page.url().match(/\/clinic\/encounters\/(\d+)/);
        assert.ok(match, `Expected encounter URL after start-direct, got ${page.url()}`);
        cleanupState.encounterId = Number(match[1]);

        const bodyText = await page.locator('body').innerText();
        assert.ok(bodyText.includes('Student Nurse / Intern Mode'), 'Intern banner should be displayed');
        assert.equal(await page.locator('[data-complete-encounter] button[type="submit"]').count(), 0, 'Intern must not see Save & Complete button');

        await page.locator('input[name="bp_systolic"]').fill('118');
        await page.locator('input[name="bp_diastolic"]').fill('78');
        await page.locator('button[data-bs-target="#step-assessment"]').click();
        await page.waitForTimeout(400);

        await page.locator('[data-complaint-category]').selectOption('headache');
        await page.waitForTimeout(200);
        await page.locator('[data-complaint-qualifier]').selectOption('Frontal');
        const composed = await page.locator('[data-chief-complaint-input]').inputValue();
        assert.ok(composed.includes('Headache'), `Expected composed complaint, got '${composed}'`);

        await page.locator('button[data-bs-target="#step-outcome"]').click();
        await page.waitForTimeout(400);
        await page.locator('textarea[name="disposition_notes"]').fill('Initial intern observation awaiting physician sign-off.');
        await submitAndConfirmModal(page, page.locator('[data-save-draft-manual]'), `/clinic/encounters/${cleanupState.encounterId}/draft`);

        assert.deepEqual(consoleErrors, [], `Console errors (intern): ${consoleErrors.join('; ')}`);
        assert.deepEqual(pageErrors, [], `Page errors (intern): ${pageErrors.join('; ')}`);
        assert.deepEqual(failed500s, [], `500 responses (intern): ${failed500s.join('; ')}`);
      } finally {
        await context.close();
      }
    });

    // 2. Clinic Staff seals intern draft, appends lab addendum, issues SY clearance, logs/clears isolation & checks reports
    await t.test('2. Clinic Staff seals encounter, records addendum, issues clearance, manages isolation & CHED reports', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.clinic.staff', '/clinic');
        await assertCleanPage(page, '/clinic?range=30d', 'Recent Clinical Encounters');
        await assertCleanPage(page, '/clinic?range=sy', 'Recent Clinical Encounters');
        await assertCleanPage(page, `/clinic/encounters/${cleanupState.encounterId}`, 'Clinical Consultation Stepper');

        // Verify dispense dropdown & sealing capability
        assert.equal(await page.locator('[data-complete-encounter] button[type="submit"]').count(), 1, 'Clinic staff sees seal button');
        await page.locator('button[data-bs-target="#step-outcome"]').click();
        await page.waitForTimeout(250);
        await page.locator('select[name="disposition"]').selectOption('discharged');
        await page.locator('textarea[name="disposition_notes"]').fill('Cleared after hydration rest.');

        await Promise.all([
          page.waitForNavigation({ waitUntil: 'load', timeout: 20000 }),
          submitAndConfirmModal(page, page.locator('[data-complete-encounter] button[type="submit"]'), `/clinic/encounters/${cleanupState.encounterId}/complete`),
        ]);

        // Re-open closed encounter: verify read-only banner, draft encoder attribution & append lab addendum
        await assertCleanPage(page, `/clinic/encounters/${cleanupState.encounterId}`, 'closed and read-only');
        const closedText = await page.locator('body').innerText();
        assert.ok(closedText.includes(fixture.internUsername), 'Draft encoder attribution should appear on closed encounter');

        const certRes = await page.request.get(`${BASE_URL}/clinic/encounters/${cleanupState.encounterId}/certificate`);
        assert.equal(certRes.status(), 200);
        assert.match(certRes.headers()['content-type'] || '', /application\/pdf/i);

        await page.locator('#addendum_test_name').fill('Urinalysis');
        await page.locator('#addendum_result_status').selectOption('normal');
        await page.locator('#addendum_result_summary').fill('Within normal limits');
        await page.locator('#addendum_note').fill('E2E delayed lab verification.');
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
          submitAndConfirmModal(page, page.locator('form[action*="/addendum"] button[type="submit"]'), `/clinic/encounters/${cleanupState.encounterId}/addendum`),
        ]);
        const afterAddendum = await page.locator('body').innerText();
        assert.ok(afterAddendum.includes('Urinalysis') && afterAddendum.includes('Within normal limits'));

        // Patient chart: save SY enrollment health clearance
        await assertCleanPage(page, `/clinic/patients/${fixture.studentPersonId}`, 'Visit Frequency Summary');
        await page.locator('button[data-bs-target="#tab-clearances"]').click();
        await page.waitForTimeout(250);
        await page.locator('#chk_physical_exam').check();
        await page.locator('#chk_cbc').check();
        await page.locator('#clrStatus').selectOption('cleared');
        await page.locator('#clrRemarks').fill('E2E Medical Exam Cleared');
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
          submitAndConfirmModal(page, page.locator('form[action*="/clearances"] button[type="submit"]'), `/clinic/patients/${fixture.studentPersonId}/clearances`),
        ]);
        const clearanceRowId = runPhpDb(`echo (int) $pdo->query("SELECT id FROM clinic_enrollment_clearances WHERE person_id = ${fixture.studentPersonId} ORDER BY id DESC LIMIT 1")->fetchColumn();`).trim();
        cleanupState.clearanceId = Number(clearanceRowId) || null;
        if (cleanupState.clearanceId) {
          const clrPdfRes = await page.request.get(`${BASE_URL}/clinic/clearances/${cleanupState.clearanceId}/certificate`);
          assert.equal(clrPdfRes.status(), 200);
          assert.match(clrPdfRes.headers()['content-type'] || '', /application\/pdf/i);
        }

        // Isolation Calendar: log & clear communicable case
        await assertCleanPage(page, '/clinic/isolation', 'Monthly Isolation Calendar');
        await page.locator('button[data-bs-target="#logIsolationModal"]').click();
        const isoModal = page.locator('#logIsolationModal');
        await isoModal.waitFor({ state: 'visible' });
        await isoModal.locator('input[placeholder*="Type name"]').fill('Student');
        await isoModal.locator('button.list-group-item-action', { hasText: 'Student' }).first().click({ timeout: 10000 });
        await isoModal.locator('select[name="condition_code"]').selectOption('chickenpox');
        await isoModal.locator('textarea[name="notes"]').fill('E2E isolation walkthrough');
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
          isoModal.locator('button[type="submit"]').click(),
        ]);
        const latestIsoId = Number(runPhpDb(`echo (int) $pdo->query("SELECT id FROM clinic_isolation_cases ORDER BY id DESC LIMIT 1")->fetchColumn();`).trim());
        if (latestIsoId) cleanupState.isolationIds.push(latestIsoId);
        const isoBody = await page.locator('body').innerText();
        assert.ok(isoBody.includes('Chickenpox'), 'Logged Chickenpox case should render in calendar/table');

        // Periodic & CHED Summary Report + LaTeX PDF
        await assertCleanPage(page, '/clinic/reports?preset=school_year', 'Monthly Encounter Volume');
        const sumPdfRes = await page.request.get(`${BASE_URL}/clinic/reports/pdf?preset=school_year`);
        assert.equal(sumPdfRes.status(), 200);
        assert.match(sumPdfRes.headers()['content-type'] || '', /application\/pdf/i);

        // Encounter Filters view check
        await assertCleanPage(page, '/clinic/encounters?complaint=headache&disposition=discharged', 'Headache');

        assert.deepEqual(consoleErrors, [], `Console errors (staff): ${consoleErrors.join('; ')}`);
        assert.deepEqual(pageErrors, [], `Page errors (staff): ${pageErrors.join('; ')}`);
        assert.deepEqual(failed500s, [], `500 responses (staff): ${failed500s.join('; ')}`);
      } finally {
        await context.close();
      }
    });

    // 3. Clinic Admin pins advisory -> Student sees prominent banner in Portal
    await t.test('3. Clinic Admin pins Clinic advisory and Student sees prominent banner in Portal', async () => {
      const { context, page, consoleErrors, pageErrors, failed500s } = await createTrackedContext(browser);
      try {
        await loginAs(page, 'test.clinic.admin', '/clinic');
        await assertCleanPage(page, '/clinic/announcements', 'New draft');
        const advTitle = `E2E Clinic Health Advisory ${suffix}`;
        await page.locator('a[href*="/clinic/announcements/create"]').click();
        const modal = page.locator('#appFormModal');
        await modal.waitFor({ state: 'visible' });
        await modal.locator('#announcementTitle').fill(advTitle);
        await modal.locator('#announcementBody').fill('Mandatory medical clearance verification this week at the University Clinic.');
        await modal.locator('#announcementPinned').check();
        await submitAndConfirmModal(page, modal.locator('button[type="submit"]'), '/clinic/announcements/store');
        await page.waitForTimeout(400);

        cleanupState.announcementId = Number(runPhpDb(`echo (int) $pdo->query("SELECT id FROM announcements WHERE title = " . $pdo->quote(${JSON.stringify(advTitle)}) . " ORDER BY id DESC LIMIT 1")->fetchColumn();`).trim()) || null;
        assert.ok(cleanupState.announcementId > 0, 'Announcement row should exist after modal save');

        // Publish that draft via confirmation modal
        await assertCleanPage(page, '/clinic/announcements', advTitle);
        await submitAndConfirmModal(
          page,
          page.locator(`form[action*="/clinic/announcements/${cleanupState.announcementId}/publish"] button[type="submit"]`),
          `/clinic/announcements/${cleanupState.announcementId}/publish`
        );

        // Student portal banner visibility check
        const stuCtx = await createTrackedContext(browser);
        try {
          await loginAs(stuCtx.page, 'test.student', '/portal');
          const portalText = await stuCtx.page.locator('[data-pinned-advisories]').innerText();
          assert.ok(portalText.includes(advTitle), `Expected pinned advisory '${advTitle}' on student portal`);
          assert.deepEqual(stuCtx.consoleErrors, [], `Console errors (student): ${stuCtx.consoleErrors.join('; ')}`);
          assert.deepEqual(stuCtx.pageErrors, [], `Page errors (student): ${stuCtx.pageErrors.join('; ')}`);
          assert.deepEqual(stuCtx.failed500s, [], `500 responses (student): ${stuCtx.failed500s.join('; ')}`);
        } finally {
          await stuCtx.context.close();
        }

        assert.deepEqual(consoleErrors, [], `Console errors (admin): ${consoleErrors.join('; ')}`);
        assert.deepEqual(pageErrors, [], `Page errors (admin): ${pageErrors.join('; ')}`);
        assert.deepEqual(failed500s, [], `500 responses (admin): ${failed500s.join('; ')}`);
      } finally {
        await context.close();
      }
    });
  } finally {
    await browser.close();
    cleanupE2ERecords(cleanupState);
  }
});
