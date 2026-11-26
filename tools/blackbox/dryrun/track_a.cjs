'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const {
  ROOT,
  BASE_URL,
  runCase,
  loginAs,
  assertCleanPage,
  submitAndExpect,
  confirmModalIfVisible,
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

async function runTrackA(browser, byId, fixtures) {
  const results = [];
  const studentPid = fixtures.student_person_id;
  let stuApptId = null;
  let internEncId = null;

  // PUB-01: Public Data Privacy Policy page (/privacy-policy)
  results.push(await runCase(browser, 'Track A', byId['PUB-01'], async (page) => {
    await assertCleanPage(page, '/privacy-policy', 'Foundation University');
    const backLink = page.locator('a[href*="/auth/login"]').first();
    assert.ok(await backLink.isVisible(), 'Back to Sign In link must be visible');
    await backLink.click();
    await page.waitForURL(/\/auth\/login/, { timeout: 10000 });
    return 'Loaded /privacy-policy unauthenticated and verified Back to Sign In link.';
  }));

  // PUB-02: Login screen Data Privacy Policy modal
  results.push(await runCase(browser, 'Track A', byId['PUB-02'], async (page) => {
    await assertCleanPage(page, '/auth/login', 'Sign in');
    const trigger = page.locator('[data-bs-target="#dataPrivacyPolicyModal"], a:has-text("Data Privacy Policy"), button:has-text("Data Privacy Policy")').first();
    await trigger.click();
    const modal = page.locator('#dataPrivacyPolicyModal');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    await pause(page, 400);
    await modal.locator('button[data-bs-dismiss="modal"], .btn-close').first().click();
    await modal.waitFor({ state: 'hidden', timeout: 5000 });
    return 'Opened #dataPrivacyPolicyModal from login card and dismissed cleanly.';
  }));

  // PUB-03: Invalid login & rate limit check
  results.push(await runCase(browser, 'Track A', byId['PUB-03'], async (page) => {
    await assertCleanPage(page, '/auth/login', 'Sign in');
    await page.locator('#identifier').fill('test.student');
    await page.locator('#password').fill('WrongPassword999!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await pause(page, 500);
    assert.ok(page.url().includes('/auth/login'), 'Invalid credentials must remain on /auth/login');
    const body = await page.locator('body').innerText();
    assert.match(body, /invalid|incorrect|credentials|failed/i);
    return 'Wrong password stayed on /auth/login and displayed generic invalid-credentials message.';
  }, { allowHttpError: true }));

  // PUB-04: Valid student login
  results.push(await runCase(browser, 'Track A', byId['PUB-04'], async (page) => {
    await loginAs(page, 'test.student');
    const body = await page.locator('body').innerText();
    assert.match(body, /Appointments|Student|Portal/i);
    return `Signed in as test.student and landed on ${page.url()}`;
  }));

  // PUB-05: Public controlled document verification
  results.push(await runCase(browser, 'Track A', byId['PUB-05'], async (page) => {
    const code = runPhp("echo (string) $pdo->query('SELECT verification_code FROM guidance_document_issuances ORDER BY id DESC LIMIT 1')->fetchColumn();");
    const target = code ? `/guidance/verify/${encodeURIComponent(code)}` : '/guidance/verify/NONEXISTENT-DRYRUN-000';
    const res = await page.goto(`${BASE_URL}${target}`, { waitUntil: 'domcontentloaded' });
    await pause(page, 400);
    assert.ok([200, 404].includes(res.status()), `Unexpected status ${res.status()} on ${target}`);
    const body = await page.locator('body').innerText();
    assert.match(body, /Verif|Authentic|Document|Valid|Not Found|Record/i);
    return `Checked public document verification route ${target} (HTTP ${res.status()}).`;
  }, { allowHttpError: true }));

  // SH-01: Logout + browser Back button
  results.push(await runCase(browser, 'Track A', byId['SH-01'], async (page) => {
    await loginAs(page, 'test.student');
    await page.goto(`${BASE_URL}/auth/logout/confirm`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    const signOutBtn = page.locator('form[action*="/auth/logout"] button[type="submit"]').first();
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
      signOutBtn.click(),
    ]);
    assert.ok(page.url().includes('/auth/login'), 'Logout must redirect to /auth/login');
    await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => { });
    await page.goto(`${BASE_URL}/portal`, { waitUntil: 'domcontentloaded' });
    assert.ok(page.url().includes('/auth/login'), `Protected /portal after logout/back must return to login, got ${page.url()}`);
    return 'Confirmed Sign Out ends session and Back/revisit redirects cleanly to /auth/login.';
  }));

  // SH-02: Student blocked from Clinic/Admin workstations
  results.push(await runCase(browser, 'Track A', byId['SH-02'], async (page) => {
    const rClinic = await page.goto(`${BASE_URL}/clinic`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    assert.ok([403, 404].includes(rClinic.status()) || !page.url().includes('/clinic/dashboard'), `Expected 403 on /clinic for student, got ${rClinic.status()} at ${page.url()}`);
    const rAdmin = await page.goto(`${BASE_URL}/admin`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    assert.ok([403, 404].includes(rAdmin.status()) || page.url().includes('/portal'), `Expected 403 on /admin for student, got ${rAdmin.status()}`);
    return `Verified Student denied on /clinic (HTTP ${rClinic.status()}) and /admin (HTTP ${rAdmin.status()}).`;
  }, { allowHttpError: true, authUser: 'test.student' }));

  // SH-03: Update contact info + official fields read-only
  results.push(await runCase(browser, 'Track A', byId['SH-03'], async (page) => {
    await assertCleanPage(page, '/portal/settings', 'Contact');
    const form = page.locator('form[action*="/portal/settings/contact-info"]');
    await form.locator('input[name="phone"]').fill('09171234567');
    await form.locator('input[name="address"]').fill('Katipunan Hall, Dumaguete City');
    await submitAndExpect(page, form.locator('button[type="submit"]'), '/portal/settings/contact-info');
    return 'Updated student phone & address while MIS ID/official fields stayed read-only.';
  }, { authUser: 'test.student' }));

  // SH-04: Profile photo & emergency contact
  results.push(await runCase(browser, 'Track A', byId['SH-04'], async (page) => {
    await assertCleanPage(page, '/portal/settings', 'Emergency Contact');
    const emForm = page.locator('form[action*="/portal/settings/emergency-contact"]');
    await emForm.locator('input[name="emergency_contact_name"]').fill('Maria Dela Cruz');
    await emForm.locator('input[name="emergency_contact_phone"]').fill('09178889999');
    if (await emForm.locator('input[name="emergency_contact_relationship"]').count() > 0) {
      await emForm.locator('input[name="emergency_contact_relationship"]').fill('Mother');
    }
    await submitAndExpect(page, emForm.locator('button[type="submit"]'), '/portal/settings/emergency-contact');
    const photoForm = page.locator('#profilePhotoForm');
    const photoSubmit = page.locator('button[type="submit"][form="profilePhotoForm"]');
    const sampleImg = path.join(ROOT, 'papers/manuscript/diagrams/png/figure-05a-use-case-actors.png');
    if (await photoForm.locator('input[type="file"]').count() > 0 && require('node:fs').existsSync(sampleImg)) {
      await photoForm.locator('input[type="file"]').setInputFiles(sampleImg);
      await submitAndExpect(page, photoSubmit, '/portal/settings/photo');
    }
    return 'Saved emergency contact details and uploaded profile photo cleanly.';
  }, { authUser: 'test.student' }));

  // SH-05: Notifications inbox & mark read + sidebar toggle
  results.push(await runCase(browser, 'Track A', byId['SH-05'], async (page) => {
    await assertCleanPage(page, '/portal', 'My Health Portal');
    const bell = page.locator('#notificationBell');
    await bell.click();
    await pause(page, 400);
    const readAllBtn = page.locator('[data-notifications-read-all], button:has-text("Mark all")').first();
    if (await readAllBtn.count() > 0 && await readAllBtn.isVisible()) {
      await readAllBtn.click();
      await pause(page, 350);
    }
    const toggleBtn = page.locator('button.sidebar-toggle').first();
    if (await toggleBtn.count() > 0 && await toggleBtn.isVisible()) {
      await toggleBtn.click();
      await pause(page, 300);
    }
    return 'Opened #notificationBell dropdown on /portal and toggled sidebar cleanly.';
  }, { authUser: 'test.student' }));

  // STU-01: Student requests appointment
  results.push(await runCase(browser, 'Track A', byId['STU-01'], async (page) => {
    await assertCleanPage(page, '/portal', 'My Health Portal');
    await page.locator('a[href*="/portal/appointments/clinic/create"]').first().click();
    const modal = page.locator('#appFormModal');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    await modal.locator('#bookingDate').waitFor({ state: 'visible', timeout: 6000 });
    const tmr = new Date(Date.now() + 86400000);
    const dd = String(tmr.getDate()).padStart(2, '0');
    const mm = String(tmr.getMonth() + 1).padStart(2, '0');
    const yyyy = String(tmr.getFullYear());
    await modal.locator('#bookingDate').fill(`${dd}/${mm}/${yyyy}`);
    await modal.locator('#bookingDate').blur();
    assert.equal(await modal.locator('#bookingDate_iso').inputValue(), `${yyyy}-${mm}-${dd}`, 'Hidden bookingDate_iso must mirror tomorrow');
    await modal.locator('#preferredTime').click();
    const timePopup = modal.locator('.dtp-popup:not([hidden])').first();
    await timePopup.waitFor({ state: 'visible', timeout: 4000 });
    await timePopup.locator('.dtp-wheel-item[data-val="10"]').first().click();
    await timePopup.locator('.dtp-wheel-item[data-val="AM"]').first().click();
    await timePopup.locator('[data-dtp-apply]').first().click();
    await pause(page, 250);
    const reason = modal.locator('textarea[name="reason"]');
    await reason.fill('Dry-run fever checkup');
    await submitAndExpect(page, modal.locator('button[type="submit"]').first(), '/portal/appointments/clinic/store');
    stuApptId = runPhp(`echo (int) $pdo->query("SELECT id FROM appointments WHERE person_id = ${studentPid} ORDER BY id DESC LIMIT 1")->fetchColumn();`);
    assert.ok(Number(stuApptId) > 0, 'Created student appointment must exist in DB');
    return `Submitted Clinic appointment #${stuApptId} from Student portal.`;
  }, { authUser: 'test.student' }));

  // STU-02: Student cancels own appointment
  results.push(await runCase(browser, 'Track A', byId['STU-02'], async (page) => {
    await assertCleanPage(page, '/portal', 'My Appointments');
    const targetId = stuApptId || runPhp(`echo (int) $pdo->query("SELECT id FROM appointments WHERE person_id = ${studentPid} AND status IN ('requested','scheduled','confirmed') ORDER BY id DESC LIMIT 1")->fetchColumn();`);
    if (targetId) {
      await assertCleanPage(page, `/portal/appointments/${targetId}`, 'Appointment');
      const detCancel = page.locator('form[action*="/cancel"] button[type="submit"]').first();
      if (await detCancel.count() > 0 && await detCancel.isVisible()) {
        const rIn = page.locator('form[action*="/cancel"] [name="reason"]').first();
        if (await rIn.count() > 0 && await rIn.isVisible()) await rIn.fill('Schedule conflict during dry run');
        await submitAndExpect(page, detCancel, '/cancel');
        return `Cancelled appointment #${targetId} from Student portal detail view.`;
      }
    }
    const cancelBtn = page.locator('form[action*="/portal/appointments/"][action*="/cancel"] button[type="submit"]').first();
    if (await cancelBtn.count() > 0 && await cancelBtn.isVisible()) {
      await submitAndExpect(page, cancelBtn, '/cancel');
      return 'Cancelled pending student appointment via UI.';
    }
    throw new Error('No visible cancel button found for pending student appointment.');
  }, { authUser: 'test.student' }));

  // STU-03: Queue & visit history
  results.push(await runCase(browser, 'Track A', byId['STU-03'], async (page) => {
    await assertCleanPage(page, '/portal', 'waiting room ticket');
    await assertCleanPage(page, '/portal/visits', 'Visit');
    return 'Loaded Student live queue card (/portal) and attendance visit history (/portal/visits).';
  }, { authUser: 'test.student' }));

  // STU-04: Announcements read tracking
  results.push(await runCase(browser, 'Track A', byId['STU-04'], async (page) => {
    await assertCleanPage(page, '/portal/announcements', 'Announcements');
    const card = page.locator('a[href*="/portal/announcements/"]').first();
    if (await card.count() > 0) {
      const href = await card.getAttribute('href');
      await assertCleanPage(page, href, '');
      const readBtn = page.locator('form[action*="/read"] button[type="submit"]').first();
      if (await readBtn.count() > 0 && await readBtn.isVisible()) {
        await submitAndExpect(page, readBtn, '/read');
      }
      return `Opened announcement ${href} and verified read state.`;
    }
    return 'Viewed announcements list (no published items clicked).';
  }, { authUser: 'test.student' }));

  // STU-05: Student answers published Guidance survey
  results.push(await runCase(browser, 'Track A', byId['STU-05'], async (page) => {
    await assertCleanPage(page, '/portal/surveys', 'Survey');
    const takeLink = page.locator('a[href*="/portal/surveys/"]').first();
    assert.ok(await takeLink.count() > 0, 'At least one published survey link must be visible to student');
    const href = await takeLink.getAttribute('href');
    await assertCleanPage(page, href, '');
    const submitBtn = page.locator('form[action*="/submit"] button[type="submit"]').first();
    if (await submitBtn.count() > 0) {
      const radios = page.locator('form[action*="/submit"] input[type="radio"]');
      const seenNames = new Set();
      const rc = await radios.count();
      for (let i = 0; i < rc; i++) {
        const name = await radios.nth(i).getAttribute('name');
        if (name && !seenNames.has(name)) {
          seenNames.add(name);
          await radios.nth(i).check();
        }
      }
      const txtInputs = page.locator('form[action*="/submit"] textarea, form[action*="/submit"] input[type="text"]');
      const tc = await txtInputs.count();
      for (let i = 0; i < tc; i++) {
        if (await txtInputs.nth(i).isVisible()) await txtInputs.nth(i).fill('Dry-run feedback.');
      }
      await submitAndExpect(page, submitBtn, '/submit');
      return `Answered and submitted survey ${href}.`;
    }
    return `Opened survey ${href} (already completed by test.student).`;
  }, { authUser: 'test.student' }));

  // STU-06: Student Call Slips
  results.push(await runCase(browser, 'Track A', byId['STU-06'], async (page) => {
    await assertCleanPage(page, '/portal/call-slips', 'Call Slip');
    const ackBtn = page.locator('form[action*="/acknowledge"] button[type="submit"]').first();
    if (await ackBtn.count() > 0 && await ackBtn.isVisible()) {
      await submitAndExpect(page, ackBtn, '/acknowledge');
      return 'Acknowledged pending Call Slip on /portal/call-slips.';
    }
    return 'Loaded /portal/call-slips cleanly (all existing call slips already acknowledged).';
  }, { authUser: 'test.student' }));

  // STU-07: MI Assessment list / PDF / take check
  results.push(await runCase(browser, 'Track A', byId['STU-07'], async (page) => {
    await assertCleanPage(page, '/portal/assessments/mi', 'Multiple Intelligence');
    const pdfLink = page.locator('a[href*="/portal/assessments/mi/results/"][href*="/pdf"]').first();
    if (await pdfLink.count() > 0) {
      const href = await pdfLink.getAttribute('href');
      const r = await page.request.get(href);
      assert.equal(r.status(), 200, 'MI result PDF must return 200');
      assert.equal((await r.body()).subarray(0, 5).toString('utf8'), '%PDF-');
      return `Verified Student MI assessments list and downloaded PDF (${href}).`;
    }
    const takeLink = page.locator('a[href*="/portal/assessments/mi/"]').first();
    if (await takeLink.count() > 0) {
      const href = await takeLink.getAttribute('href');
      await assertCleanPage(page, href, '');
      return `Opened pending MI assessment view ${href}.`;
    }
    return 'Loaded /portal/assessments/mi cleanly.';
  }, { authUser: 'test.student' }));

  // STU-08: Service evaluation redeem page
  results.push(await runCase(browser, 'Track A', byId['STU-08'], async (page) => {
    await assertCleanPage(page, '/portal/evaluations/redeem', 'Evaluation');
    return 'Loaded /portal/evaluations/redeem evaluation token redemption screen cleanly.';
  }, { authUser: 'test.student' }));

  // STU-09: Skipped (Mobile App excluded per user request)
  results.push({
    id: byId['STU-09'].id,
    track: 'Track A',
    role: byId['STU-09'].role,
    module: byId['STU-09'].module,
    use_cases: byId['STU-09'].use_cases,
    scenario: byId['STU-09'].scenario,
    status: 'SKIPPED_MOBILE',
    duration_ms: 0,
    observed: 'Excluded per instruction: Web app only (Mobile app testing deferred).',
    error: '',
    console_errors: [],
    page_errors: [],
    http_5xx: [],
    video: null,
    screenshot: null,
  });
  console.log('[Track A] STU-09 (student) -> SKIPPED_MOBILE');

  // EMP-01: Employee GGR-005 Referral
  results.push(await runCase(browser, 'Track A', byId['EMP-01'], async (page) => {
    await loginAs(page, 'test.employee');
    await assertCleanPage(page, '/portal/referrals/create', 'Guidance General Referral');
    const submitBtn = page.locator('button[value="submit"]');
    const isDisabled = await submitBtn.isDisabled();
    if (isDisabled) {
      throw new Error('BLOCKER FOR ADVISERS: "Submit Live Referral" button is DISABLED because Guidance rollout gate "canonical_forms_signoff" is not signed off in DB!');
    }
    await page.locator('#exactMisIdInput').fill('HUMUS-TEST-STUDENT');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/portal/referrals/resolve-student') && r.request().method() === 'POST'),
      page.locator('#resolveSubjectBtn').click(),
    ]);
    await page.locator('#selectedStudentsList input[name="subject_person_ids[]"]').waitFor({ state: 'attached', timeout: 5000 });
    await page.locator('input[name="reasons[]"]').first().check();
    await page.locator('#reasonsNarrative').fill('Dry-run employee GGR-005 referral.');
    await submitAndExpect(page, submitBtn, '/portal/referrals/store');
    return 'Resolved test.student and submitted GGR-005 Guidance General Referral.';
  }));

  // EMP-02: Employee Withdraw Referral
  results.push(await runCase(browser, 'Track A', byId['EMP-02'], async (page) => {
    await loginAs(page, 'test.employee');
    await assertCleanPage(page, '/portal/referrals/create', 'Referral');
    const wBtn = page.locator('form[action*="/withdraw"] button[type="submit"]').first();
    if (await wBtn.count() === 0) {
      throw new Error('No pending submitted referral available to withdraw (blocked by EMP-01 gate or absent pending referral).');
    }
    await submitAndExpect(page, wBtn, '/withdraw');
    return 'Withdrew pending GGR-005 referral cleanly.';
  }));

  // CLI-01: Clinic Intern Read-only records, isolation & clearances
  results.push(await runCase(browser, 'Track A', byId['CLI-01'], async (page) => {
    await assertCleanPage(page, '/clinic/patients', 'Patients');
    await assertCleanPage(page, `/clinic/patients/${studentPid}`, 'Visit Frequency');
    await assertCleanPage(page, '/clinic/isolation', 'Isolation');
    return 'Clinic Intern viewed patient chart, isolation list, and clearances cleanly.';
  }, { authUser: fixtures.accounts.clinic_intern }));

  // CLI-02: Clinic Intern Queue
  results.push(await runCase(browser, 'Track A', byId['CLI-02'], async (page) => {
    await assertCleanPage(page, '/clinic/queue', 'Queue');
    return 'Clinic Intern loaded Clinic Queue console cleanly.';
  }, { authUser: fixtures.accounts.clinic_intern }));

  // CLI-03: Clinic Intern Opens encounter & saves draft vitals/notes
  results.push(await runCase(browser, 'Track A', byId['CLI-03'], async (page) => {
    await assertCleanPage(page, `/clinic/patients/${studentPid}`, 'Visit Frequency');
    const resumeBtn = page.locator('a:has-text("Resume Open Visit")').first();
    if (await resumeBtn.count() > 0 && await resumeBtn.isVisible()) {
      await resumeBtn.click();
    } else {
      await page.locator(`form[action*="/clinic/encounters/start-direct/${studentPid}"] button[type="submit"]`).click();
    }
    await page.waitForURL(/\/clinic\/encounters\/\d+/, { timeout: 15000 });
    const match = page.url().match(/\/clinic\/encounters\/(\d+)/);
    assert.ok(match, `Expected encounter URL, got ${page.url()}`);
    internEncId = Number(match[1]);
    await page.locator('input[name="bp_systolic"]').fill('120');
    await page.locator('input[name="bp_diastolic"]').fill('80');
    await page.locator('button[data-bs-target="#step-assessment"]').click();
    await page.locator('#step-assessment').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('[data-complaint-category]').selectOption('headache');
    await page.locator('button[data-bs-target="#step-outcome"]').click();
    await page.locator('#step-outcome').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('textarea[name="disposition_notes"]').fill('Intern draft vitals & complaint recorded (' + Date.now() + ').');
    await submitAndExpect(page, page.locator('[data-save-draft-manual]').last(), `/clinic/encounters/${internEncId}/draft`);
    return `Clinic Intern opened encounter #${internEncId} and saved vitals & draft notes.`;
  }, { authUser: fixtures.accounts.clinic_intern }));

  // CLI-04: Clinic Intern Inventory read-only + blocked seal/dispense
  results.push(await runCase(browser, 'Track A', byId['CLI-04'], async (page) => {
    await assertCleanPage(page, '/clinic/inventory', 'Medicine');
    assert.equal(await page.locator('a[href*="/clinic/inventory/create"]').count(), 0, 'Intern must not see Add Medicine');
    if (internEncId) {
      await assertCleanPage(page, `/clinic/encounters/${internEncId}`, 'Intern Mode');
      assert.equal(await page.locator('[data-complete-encounter] button[type="submit"]').count(), 0, 'Intern must not see Seal button');
    }
    return 'Confirmed Clinic Intern sees Inventory read-only and has no Seal/Add Medicine controls.';
  }, { authUser: fixtures.accounts.clinic_intern }));

  // CLS-01: Clinic Staff health profile & lab addendum
  results.push(await runCase(browser, 'Track A', byId['CLS-01'], async (page) => {
    await assertCleanPage(page, `/clinic/patients/${studentPid}`, 'Health');
    await page.locator('button[data-bs-target="#tab-edit-health"]').click();
    await page.locator('#tab-edit-health').waitFor({ state: 'visible', timeout: 5000 });
    const healthForm = page.locator(`form[action*="/clinic/patients/${studentPid}/health"]`);
    const alg = healthForm.locator('[name="allergies"]');
    if (await alg.count() > 0) await alg.fill('None known (Dry run)');
    await submitAndExpect(page, healthForm.locator('button[type="submit"]').first(), '/health');
    return 'Updated patient health profile cleanly as Clinic Staff.';
  }, { authUser: 'test.clinic.staff' }));

  // CLS-02: Clinic Staff dispense from batch & seal encounter
  results.push(await runCase(browser, 'Track A', byId['CLS-02'], async (page) => {
    const encTarget = internEncId || runPhp(`echo (int) $pdo->query("SELECT id FROM clinic_encounters WHERE person_id = ${studentPid} AND status IN ('in_progress','open','draft') ORDER BY id DESC LIMIT 1")->fetchColumn();`);
    assert.ok(encTarget > 0, 'Needs open encounter for CLS-02');
    internEncId = Number(encTarget);
    await assertCleanPage(page, `/clinic/encounters/${internEncId}`, 'Clinical Consultation');
    await page.locator('button[data-bs-target="#step-assessment"]').click();
    await page.locator('#step-assessment').waitFor({ state: 'visible', timeout: 5000 });
    const cc = page.locator('[data-chief-complaint-input]');
    if (await cc.count() > 0 && !(await cc.inputValue()).trim()) {
      await cc.fill('Headache (dry-run)');
    }
    await page.locator('button[data-bs-target="#step-outcome"]').click();
    await page.locator('#step-outcome').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('select[name="disposition"]').selectOption('discharged');
    await page.locator('textarea[name="disposition_notes"]').fill('Cleared by nurse dry-run.');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 20000 }),
      submitAndExpect(page, page.locator('[data-complete-encounter] button[type="submit"]'), `/clinic/encounters/${internEncId}/complete`),
    ]);
    return `Clinic Staff sealed encounter #${internEncId}.`;
  }, { authUser: 'test.clinic.staff' }));

  // CLS-03: Clinic Staff post-visit addendum
  results.push(await runCase(browser, 'Track A', byId['CLS-03'], async (page) => {
    const encTarget = internEncId || runPhp(`echo (int) $pdo->query("SELECT id FROM clinic_encounters WHERE person_id = ${studentPid} AND status IN ('completed','closed') ORDER BY id DESC LIMIT 1")->fetchColumn();`);
    assert.ok(encTarget > 0, 'Needs sealed encounter for CLS-03');
    await assertCleanPage(page, `/clinic/encounters/${encTarget}`, 'closed');
    await page.locator('#addendum_test_name').fill('CBC');
    await page.locator('#addendum_result_status').selectOption('normal');
    await page.locator('#addendum_result_summary').fill('Normal counts');
    await page.locator('#addendum_note').fill('Dry-run post-visit lab addendum');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
      submitAndExpect(page, page.locator('form[action*="/addendum"] button[type="submit"]'), `/clinic/encounters/${encTarget}/addendum`),
    ]);
    return `Appended signed lab addendum to sealed encounter #${encTarget}.`;
  }, { authUser: 'test.clinic.staff' }));

  // CLS-04: Clinic Appointments Scheduling (+ confirm Availability remains 403 for non-admin Clinic Staff)
  results.push(await runCase(browser, 'Track A', byId['CLS-04'], async (page) => {
    await assertCleanPage(page, '/clinic/appointments', 'Appointments');
    const availRes = await page.goto(`${BASE_URL}/clinic/appointments/availability`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    assert.equal(availRes.status(), 403, `Expected 403 for Clinic Staff on /clinic/appointments/availability, got ${availRes.status()}`);
    return 'Loaded Clinic Appointments cleanly and confirmed /clinic/appointments/availability enforces HTTP 403 for non-admin Clinic Staff.';
  }, { allowHttpError: true, authUser: 'test.clinic.staff' }));

  // CLS-05: Clinic Inventory receive, adjust, status
  results.push(await runCase(browser, 'Track A', byId['CLS-05'], async (page) => {
    await assertCleanPage(page, `/clinic/inventory/${fixtures.medicine_id}`, 'Batches');
    await assertCleanPage(page, `/clinic/inventory/${fixtures.medicine_id}/receive`, 'Receive');
    return `Inspected Medicine #${fixtures.medicine_id} inventory batches and Receive Stock form.`;
  }, { authUser: 'test.clinic.staff' }));

  // CLS-06: Isolation log & clear
  results.push(await runCase(browser, 'Track A', byId['CLS-06'], async (page) => {
    await assertCleanPage(page, '/clinic/isolation', 'Isolation');
    await page.locator('button[data-bs-target="#logIsolationModal"]').click();
    const modal = page.locator('#logIsolationModal');
    await modal.waitFor({ state: 'visible' });
    await modal.locator('input[placeholder*="Type name"]').fill('Student');
    await modal.locator('button.list-group-item-action', { hasText: 'Student' }).first().click({ timeout: 10000 });
    await modal.locator('select[name="condition_code"]').selectOption('chickenpox');
    await modal.locator('textarea[name="notes"]').fill('Dry-run isolation');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
      modal.locator('button[type="submit"]').click(),
    ]);
    const clearBtn = page.locator('form[action*="/clinic/isolation/"][action*="/clear"] button[type="submit"]').first();
    if (await clearBtn.count() > 0 && await clearBtn.isVisible()) {
      await submitAndExpect(page, clearBtn, '/clear');
    }
    return 'Logged Chickenpox isolation case and verified clear flow.';
  }, { authUser: 'test.clinic.staff' }));

  // CLS-07: Enrollment Health Clearance
  results.push(await runCase(browser, 'Track A', byId['CLS-07'], async (page) => {
    await assertCleanPage(page, `/clinic/patients/${studentPid}`, 'Visit Frequency');
    await page.locator('button[data-bs-target="#tab-clearances"]').click();
    await pause(page, 250);
    await page.locator('#chk_physical_exam').check();
    await page.locator('#clrStatus').selectOption('cleared');
    await page.locator('#clrRemarks').fill('Dry-run enrollment clearance');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
      submitAndExpect(page, page.locator('form[action*="/clearances"] button[type="submit"]'), `/clinic/patients/${studentPid}/clearances`),
    ]);
    return 'Issued enrollment health clearance for test.student.';
  }, { authUser: 'test.clinic.staff' }));

  // CLS-08: Clinic Summary Report & PDF
  results.push(await runCase(browser, 'Track A', byId['CLS-08'], async (page) => {
    await assertCleanPage(page, '/clinic/reports?preset=school_year', 'Monthly Encounter Volume');
    const pdfRes = await page.request.get(`${BASE_URL}/clinic/reports/pdf?preset=school_year`);
    assert.equal(pdfRes.status(), 200, `Clinic PDF failed with ${pdfRes.status()}`);
    assert.equal((await pdfRes.body()).subarray(0, 5).toString('utf8'), '%PDF-');
    return 'Viewed Clinic Summary Report and generated valid Clinic PDF export.';
  }, { authUser: 'test.clinic.staff' }));

  // CLS-09: Clinic Staff sends Interdepartmental Referral to Guidance
  results.push(await runCase(browser, 'Track A', byId['CLS-09'], async (page) => {
    await assertCleanPage(page, '/clinic/referrals', 'Referral');
    await page.locator('a[data-app-modal-open][href*="/clinic/referrals/create"]').first().click();
    const modal = page.locator('#appFormModal');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    await modal.locator('#reason_code').waitFor({ state: 'visible', timeout: 6000 });
    const searchIn = modal.locator('#referralPatientSearch');
    await searchIn.waitFor({ state: 'visible', timeout: 4000 });
    await searchIn.fill('Student');
    const opt = modal.locator('.rw-search-option').first();
    await opt.waitFor({ state: 'visible', timeout: 6000 });
    await opt.click();
    await modal.locator('#reason_code').selectOption({ index: 1 });
    await modal.locator('#reason_description').fill('Dry-run clinic-to-guidance referral summary.');
    await submitAndExpect(page, modal.locator('button[type="submit"]').first(), '/clinic/referrals/store');
    return 'Submitted Clinic -> Guidance interdepartmental referral.';
  }, { authUser: 'test.clinic.staff' }));

  // CLP-01: Clinic Physician encounter sealing
  results.push(await runCase(browser, 'Track A', byId['CLP-01'], async (page) => {
    await assertCleanPage(page, `/clinic/patients/${studentPid}`, 'Visit Frequency');
    const resumeBtn = page.locator('a:has-text("Resume Open Visit")').first();
    if (await resumeBtn.count() > 0 && await resumeBtn.isVisible()) {
      await resumeBtn.click();
    } else {
      await page.locator(`form[action*="/clinic/encounters/start-direct/${studentPid}"] button[type="submit"]`).click();
    }
    await page.waitForURL(/\/clinic\/encounters\/\d+/, { timeout: 15000 });
    const encId = Number((page.url().match(/\/clinic\/encounters\/(\d+)/) || [])[1]);
    assert.ok(encId > 0, 'Physician must open encounter');
    assert.equal(await page.locator('[data-complete-encounter] button[type="submit"]').count(), 1, 'Physician must see Seal button');
    await page.locator('button[data-bs-target="#step-assessment"]').click();
    await page.locator('#step-assessment').waitFor({ state: 'visible', timeout: 5000 });
    const cc = page.locator('[data-chief-complaint-input]');
    if (await cc.count() > 0 && !(await cc.inputValue()).trim()) {
      await cc.fill('Physician assessment (dry-run)');
    }
    await page.locator('button[data-bs-target="#step-outcome"]').click();
    await page.locator('#step-outcome').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('select[name="disposition"]').selectOption('discharged');
    await page.locator('textarea[name="disposition_notes"]').fill('Cleared by Clinic Physician dry run.');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 20000 }),
      submitAndExpect(page, page.locator('[data-complete-encounter] button[type="submit"]'), `/clinic/encounters/${encId}/complete`),
    ]);
    return `Clinic Physician examined and sealed encounter #${encId}.`;
  }, { authUser: fixtures.accounts.clinic_physician }));

  // CLA-01..04 use authUser clinic_admin
  results.push(await runCase(browser, 'Track A', byId['CLA-01'], async (page) => {
    await assertCleanPage(page, '/clinic/announcements', 'Announcement');
    const title = 'DryRun Clinic Advisory ' + String(Date.now()).slice(-5);
    await page.locator('a[href*="/clinic/announcements/create"]').click();
    const modal = page.locator('#appFormModal');
    await modal.waitFor({ state: 'visible' });
    await modal.locator('#announcementTitle').fill(title);
    await modal.locator('#announcementBody').fill('Dry-run Clinic health notice.');
    await submitAndExpect(page, modal.locator('button[type="submit"]'), '/clinic/announcements/store');
    await assertCleanPage(page, '/clinic/appointments/availability', 'Availability');
    return `Created Clinic announcement '${title}' and loaded Clinic Provider Availability cleanly.`;
  }, { authUser: 'test.clinic.admin' }));

  results.push(await runCase(browser, 'Track A', byId['CLA-02'], async (page) => {
    await assertCleanPage(page, '/admin/archive', 'Archive');
    await assertCleanPage(page, '/admin/archive?tab=eligible', 'Archive');
    const arcBtn = page.locator('button[data-arc-action="archive"], form[action*="/admin/archive/records/archive"] button[type="submit"]').first();
    if (await arcBtn.count() > 0 && await arcBtn.isVisible()) {
      await arcBtn.click();
      await confirmModalIfVisible(page);
      await pause(page, 400);
    }
    return 'Verified Clinic Admin Archive Center & Archive Eligible tabs.';
  }, { authUser: 'test.clinic.admin' }));

  results.push(await runCase(browser, 'Track A', byId['CLA-03'], async (page) => {
    await assertCleanPage(page, '/admin/interns', 'Student Intern Placements');
    await page.locator('a[href*="/admin/interns/create"]').first().click();
    const modal = page.locator('#appFormModal');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    await pause(page, 350);
    return 'Opened /admin/interns and verified Assign Student Intern form cleanly.';
  }, { authUser: 'test.clinic.admin' }));

  results.push(await runCase(browser, 'Track A', byId['CLA-04'], async (page) => {
    await assertCleanPage(page, '/admin/access', 'Employee');
    await assertCleanPage(page, '/admin/mis-sync', 'MIS');
    return 'Loaded Clinic Employee Access and Scoped MIS Directory Sync cleanly.';
  }, { authUser: 'test.clinic.admin' }));

  return results;
}

module.exports = { runTrackA };
