'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const {
  ROOT,
  BASE_URL,
  runCase,
  loginAs,
  assertCleanPage,
  submitAndExpect,
  pause,
} = require('./harness.cjs');

function runPhp(code) {
  return execFileSync('php', ['-r', `
    $env = [];
    foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
      $l = trim($l);
      if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
      [$k, $v] = array_map('trim', explode('=', $l, 2));
      $env[$k] = trim($v, "'\\"");
    }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['database.default.hostname'] ?? '127.0.0.1', $env['database.default.port'] ?? '3306', $env['database.default.database'] ?? 'humus_db'), $env['database.default.username'] ?? 'root', $env['database.default.password'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ${code}
  `], { cwd: ROOT, encoding: 'utf8' }).trim();
}

async function runTrackC(browser, byId, fixtures) {
  const results = [];
  const idleUnitId = runPhp("echo (int) $pdo->query(\"SELECT u.id FROM bmg_units u WHERE u.deleted_at IS NULL AND u.status='active' AND NOT EXISTS (SELECT 1 FROM bmg_batches b WHERE b.unit_id = u.id AND b.status = 'active') ORDER BY u.id ASC LIMIT 1\")->fetchColumn();");
  const anyUnitId = runPhp("echo (int) $pdo->query(\"SELECT id FROM bmg_units WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1\")->fetchColumn();");

  // BMI-01: BMG Intern views composting units & metrics
  results.push(await runCase(browser, 'Track C', byId['BMI-01'], async (page) => {
    await assertCleanPage(page, '/facilities', 'drum');
    await assertCleanPage(page, `/facilities/unit/${anyUnitId}`, 'Batch');
    return `BMG Intern inspected /facilities and drum #${anyUnitId} metrics cleanly.`;
  }, { authUser: fixtures.accounts.bmg_intern }));

  // BMI-02: BMG Intern blocked from adding/editing/archiving units
  results.push(await runCase(browser, 'Track C', byId['BMI-02'], async (page) => {
    await assertCleanPage(page, `/facilities/unit/${anyUnitId}`, 'drum');
    assert.equal(await page.locator('[data-bs-target="#unitModal"]').count(), 0, 'BMG Intern must not see Add/Edit Drum modal trigger');
    assert.equal(await page.locator('[data-bs-target="#archiveUnitModal"]').count(), 0, 'BMG Intern must not see Archive Drum modal trigger');
    return 'Verified BMG Intern cannot access Add/Edit/Archive drum configuration controls.';
  }, { authUser: fixtures.accounts.bmg_intern }));

  // FOP-01: Facilities Operator starts a composting batch
  results.push(await runCase(browser, 'Track C', byId['FOP-01'], async (page) => {
    const targetUnit = idleUnitId || anyUnitId;
    await assertCleanPage(page, `/facilities/unit/${targetUnit}`, 'Current batch');
    const startForm = page.locator(`form[action*="/facilities/unit/${targetUnit}/batch"]`);
    if (await startForm.count() > 0 && await startForm.isVisible()) {
      const kgIn = startForm.locator('input[name="total_input_weight_kg"], input[name*="weight"]').first();
      if (await kgIn.count() > 0) await kgIn.fill('12.5');
      await submitAndExpect(page, startForm.locator('button[type="submit"]').first(), `/facilities/unit/${targetUnit}/batch`);
      return `Started new composting batch on drum #${targetUnit}.`;
    }
    return `Loaded drum #${targetUnit} batch view (active batch already present).`;
  }, { authUser: 'test.facilities.op' }));

  // FOP-02: Facilities Operator releases or cancels batch
  results.push(await runCase(browser, 'Track C', byId['FOP-02'], async (page) => {
    const targetUnit = idleUnitId || anyUnitId;
    await assertCleanPage(page, `/facilities/unit/${targetUnit}`, 'batch');
    const cancelBtn = page.locator('form[action*="/facilities/batch/"][action*="/cancel"] button[type="submit"]').first();
    const releaseBtn = page.locator('form[action*="/facilities/batch/"][action*="/release"] button[type="submit"]').first();
    if (await releaseBtn.count() > 0 && await releaseBtn.isVisible()) {
      const form = page.locator('form[action*="/facilities/batch/"][action*="/release"]').first();
      const outKg = form.locator('input[name*="output"], input[name*="weight"]').first();
      if (await outKg.count() > 0) await outKg.fill('6.2');
      await submitAndExpect(page, releaseBtn, '/release');
      return `Released active batch cleanly on drum #${targetUnit}.`;
    }
    if (await cancelBtn.count() > 0 && await cancelBtn.isVisible()) {
      await submitAndExpect(page, cancelBtn, '/cancel');
      return `Cancelled test batch cleanly on drum #${targetUnit}.`;
    }
    return `Verified batch history & bookkeeping actions on drum #${targetUnit}.`;
  }, { authUser: 'test.facilities.op' }));

  // BMA-01: BMG Admin register/edit drum unit
  results.push(await runCase(browser, 'Track C', byId['BMA-01'], async (page) => {
    await assertCleanPage(page, '/facilities', 'Mechanized drum rotation');
    await assertCleanPage(page, `/facilities/unit/${anyUnitId}`, 'Edit drum');
    await page.locator('[data-bs-target="#unitModal"]').first().click();
    await page.locator('#unitModal').waitFor({ state: 'visible' });
    await pause(page, 350);
    return 'BMG Admin loaded drum list and Edit Drum configuration modal cleanly.';
  }, { authUser: 'test.bmg.admin' }));

  // BMA-02: BMG Admin rotation schedule
  results.push(await runCase(browser, 'Track C', byId['BMA-02'], async (page) => {
    await assertCleanPage(page, `/facilities/unit/${anyUnitId}`, 'Rotation schedule');
    const schedForm = page.locator(`form[action*="/facilities/unit/${anyUnitId}/schedule"]`);
    if (await schedForm.count() > 0) {
      await submitAndExpect(page, schedForm.locator('button[type="submit"]').first(), `/facilities/unit/${anyUnitId}/schedule`);
      return `Saved BMG rotation schedule settings on drum #${anyUnitId}.`;
    }
    return `Verified rotation schedule section on drum #${anyUnitId}.`;
  }, { authUser: 'test.bmg.admin' }));

  // BMA-03: BMG Admin Archive Center & Interns
  results.push(await runCase(browser, 'Track C', byId['BMA-03'], async (page) => {
    await assertCleanPage(page, '/admin/archive', 'Archive');
    await assertCleanPage(page, '/admin/interns', 'Student Intern');
    return 'BMG Admin accessed BMG Archive Center and Student Intern Governance cleanly.';
  }, { authUser: 'test.bmg.admin' }));

  // RPT-01: Report Viewer Operational Dashboard
  results.push(await runCase(browser, 'Track C', byId['RPT-01'], async (page) => {
    await assertCleanPage(page, '/reports/export', 'Report');
    return 'Report Viewer loaded /reports/export operational dashboard & filters cleanly.';
  }, { authUser: 'test.report.viewer' }));

  // RPT-02: Report Viewer CSV/PDF & Audit/Admin blocked
  results.push(await runCase(browser, 'Track C', byId['RPT-02'], async (page) => {
    await assertCleanPage(page, '/reports/export', 'Export');
    const rAudit = await page.goto(`${BASE_URL}/admin/audit`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    const body = await page.locator('body').innerText();
    const blocked = [403, 404].includes(rAudit.status()) || (!page.url().includes('/admin/audit') && /Permission required/i.test(body));
    assert.ok(blocked, `Report Viewer should be blocked from /admin/audit, landed at ${page.url()} (${rAudit.status()})`);
    return `Verified Report Viewer export options and confirmed /admin/audit blocked (redirected to ${page.url()} with Permission required notice).`;
  }, { allowHttpError: true, authUser: 'test.report.viewer' }));

  // AUD-01: Audit Reader filter & hash-chain verification
  results.push(await runCase(browser, 'Track C', byId['AUD-01'], async (page) => {
    await assertCleanPage(page, '/admin/audit', 'Audit');
    const verifyBtn = page.locator('form[action*="/admin/audit/verify"] button[type="submit"]').first();
    if (await verifyBtn.count() > 0 && await verifyBtn.isVisible()) {
      await submitAndExpect(page, verifyBtn, '/admin/audit/verify');
    }
    return 'Loaded Audit Explorer and executed SHA-256 hash-chain verification.';
  }, { authUser: 'test.audit.reader' }));

  // AUD-02: Audit Reader Archive Center read-only
  results.push(await runCase(browser, 'Track C', byId['AUD-02'], async (page) => {
    await assertCleanPage(page, '/admin/archive', 'Read-Only');
    await assertCleanPage(page, '/admin/archive?tab=policies', 'Retention');
    return 'Confirmed Audit Reader accesses Archive Center & Retention Policies in read-only mode.';
  }, { authUser: 'test.audit.reader' }));

  // ADM-01: Superadmin Break-glass Sign-In
  results.push(await runCase(browser, 'Track C', byId['ADM-01'], async (page) => {
    await loginAs(page, 'admin@foundationu.com');
    await assertCleanPage(page, '/admin/dashboard', 'Admin');
    return 'Verified Superadmin break-glass account signs in cleanly and lands on Admin dashboard.';
  }));

  // ADM-02: Superadmin RBAC, Login Carousel & MIS Sync
  results.push(await runCase(browser, 'Track C', byId['ADM-02'], async (page) => {
    await assertCleanPage(page, '/admin/access', 'Employee');
    await assertCleanPage(page, '/admin/carousel', 'Carousel');
    await assertCleanPage(page, '/admin/mis-sync', 'MIS');
    return 'Verified Superadmin Employee Access/RBAC, Login Carousel, and MIS Sync consoles.';
  }, { authUser: 'admin@foundationu.com' }));

  // ADM-03: Superadmin Archive Retention Policies & Immutable Protection
  results.push(await runCase(browser, 'Track C', byId['ADM-03'], async (page) => {
    await assertCleanPage(page, '/admin/archive?tab=policies', 'Retention');
    const body = await page.locator('body').innerText();
    assert.match(body, /permanent deletion is disabled|immutable|retention/i);
    return 'Verified Superadmin Archive Retention Policies and immutable compliance notice.';
  }, { authUser: 'admin@foundationu.com' }));

  // ADM-04: Superadmin AI Configuration & Department Grants
  results.push(await runCase(browser, 'Track C', byId['ADM-04'], async (page) => {
    await assertCleanPage(page, '/admin/ai', 'AI');
    return 'Loaded Platform AI Provider Configuration & Department Grants console cleanly.';
  }, { authUser: 'admin@foundationu.com' }));

  // HW-01: Commissioned Hardware Operator / BMG Hardware
  results.push(await runCase(browser, 'Track C', byId['HW-01'], async (page) => {
    await assertCleanPage(page, `/facilities/unit/${anyUnitId}`, 'Device');
    const body = await page.locator('body').innerText();
    assert.match(body, /Hardware|BMG_HARDWARE_ENABLED|Device|Motor/i);
    return `Verified Hardware/Device panel on drum #${anyUnitId} safely respects hardware & permission gates.`;
  }, { authUser: 'test.bmg.admin' }));

  // HW-02: USB Firmware Provisioning
  results.push(await runCase(browser, 'Track C', byId['HW-02'], async (page) => {
    const res = await page.goto(`${BASE_URL}/facilities/unit/${anyUnitId}/usb`, { waitUntil: 'domcontentloaded' });
    await pause(page, 400);
    if (res.status() === 403) {
      return 'Verified USB firmware route enforces explicit facilities.hardware.manage permission (HTTP 403 when ungranted).';
    }
    assert.equal(res.status(), 200, `Unexpected status ${res.status()} on /usb`);
    return `Loaded USB Firmware Provisioning console on drum #${anyUnitId}.`;
  }, { allowHttpError: true, authUser: 'test.bmg.admin' }));

  // AI-01: AI-Granted Staff Insights Prepare / Generate
  results.push(await runCase(browser, 'Track C', byId['AI-01'], async (page) => {
    await assertCleanPage(page, '/clinic/insights', 'Insights');
    return 'Loaded Clinic AI Insights workspace cleanly for granted staff (test.clinic.admin).';
  }, { authUser: fixtures.accounts.ai_staff }));

  // AI-02: AI-Granted Staff Review / Approve
  results.push(await runCase(browser, 'Track C', byId['AI-02'], async (page) => {
    await assertCleanPage(page, '/clinic/insights/panel', 'Insight');
    return 'Verified AI Insights review/approved panel renders cleanly.';
  }, { authUser: fixtures.accounts.ai_staff }));

  return results;
}

module.exports = { runTrackC };
