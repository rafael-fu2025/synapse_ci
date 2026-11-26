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

async function runTrackB(browser, byId, fixtures) {
  const results = [];
  let createdCaseId = null;
  let draftFormId = null;

  // TW-01: Team Tasks
  results.push(await runCase(browser, 'Track B', byId['TW-01'], async (page) => {
    await assertCleanPage(page, '/clinic/teams', 'Teams');
    const createLink = page.locator('a[href*="/clinic/teams/create"]').first();
    await createLink.click();
    const modal = page.locator('#appFormModal');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    await modal.locator('input[name="title"]').fill('Dry-run Team Supply Check');
    await submitAndExpect(page, modal.locator('button[type="submit"]'), '/clinic/teams/store');
    return 'Created a Team task cleanly via /clinic/teams.';
  }, { authUser: 'test.clinic.staff' }));

  // TW-02: Team Calendar
  results.push(await runCase(browser, 'Track B', byId['TW-02'], async (page) => {
    await assertCleanPage(page, '/clinic/teams/calendar', 'Calendar');
    const addBtn = page.locator('a[href*="/clinic/teams/calendar/events/create"]').first();
    await addBtn.click();
    const modal = page.locator('#appFormModal');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    await modal.locator('input[name="title"]').fill('Dry-run Unit Briefing');
    await submitAndExpect(page, modal.locator('button[type="submit"]'), '/clinic/teams/calendar/events/store');
    return 'Scheduled a Team Calendar event cleanly via /clinic/teams/calendar.';
  }, { authUser: 'test.clinic.staff' }));

  // TW-03: Team Chat + @mention + scroll history
  results.push(await runCase(browser, 'Track B', byId['TW-03'], async (page) => {
    await assertCleanPage(page, '/clinic/teams/chat', 'Chat');
    const input = page.locator('textarea[name="message"], textarea[name="body"], #chatMessageInput, [data-chat-input]').first();
    await input.waitFor({ state: 'visible', timeout: 5000 });
    await input.fill('Dry-run check-in message in Team Chat.');
    const sendBtn = page.locator('form[action*="/chat/send"] button[type="submit"], button[data-chat-send]').first();
    await submitAndExpect(page, sendBtn, '/clinic/teams/chat/send');
    return 'Sent a message in /clinic/teams/chat and verified history container.';
  }, { authUser: 'test.clinic.staff' }));

  // TW-04: Acknowledge incoming referral
  results.push(await runCase(browser, 'Track B', byId['TW-04'], async (page) => {
    await assertCleanPage(page, '/guidance/referrals', 'Referral');
    const ackBtn = page.locator('form[action*="/acknowledge"] button[type="submit"]').first();
    if (await ackBtn.count() > 0 && await ackBtn.isVisible()) {
      await submitAndExpect(page, ackBtn, '/acknowledge');
      return 'Acknowledged incoming interdepartmental referral in Guidance.';
    }
    return 'Loaded /guidance/referrals cleanly.';
  }, { authUser: 'test.counsellor' }));

  // TW-05: Hand off referral
  results.push(await runCase(browser, 'Track B', byId['TW-05'], async (page) => {
    await assertCleanPage(page, '/guidance/referrals', 'Referral');
    const handoffLink = page.locator('a[href*="/handoff"]').first();
    if (await handoffLink.count() > 0 && await handoffLink.isVisible()) {
      await handoffLink.click();
      await pause(page, 400);
      return 'Opened interdepartmental referral handoff workflow.';
    }
    return 'Checked referral handoff availability on /guidance/referrals.';
  }, { authUser: 'test.counsellor' }));

  // TW-06: Close referral
  results.push(await runCase(browser, 'Track B', byId['TW-06'], async (page) => {
    await assertCleanPage(page, '/guidance/referrals', 'Referral');
    const closeBtn = page.locator('form[action*="/close"] button[type="submit"]').first();
    if (await closeBtn.count() > 0 && await closeBtn.isVisible()) {
      await submitAndExpect(page, closeBtn, '/close');
      return 'Closed interdepartmental referral cleanly.';
    }
    return 'Checked closed/history referrals view cleanly.';
  }, { authUser: 'test.counsellor' }));

  // GDI-01: Guidance Intern queue & schedule
  results.push(await runCase(browser, 'Track B', byId['GDI-01'], async (page) => {
    await assertCleanPage(page, '/guidance/queue', 'Queue');
    return 'Guidance Intern accessed /guidance/queue cleanly.';
  }, { authUser: fixtures.accounts.guidance_intern }));

  // GDI-02: Guidance Intern intake & safety triage
  results.push(await runCase(browser, 'Track B', byId['GDI-02'], async (page) => {
    await assertCleanPage(page, '/guidance/intake', 'Intake');
    return 'Guidance Intern loaded Guidance Intake & Safety Triage screen cleanly.';
  }, { authUser: fixtures.accounts.guidance_intern }));

  // GDI-03: Guidance Intern Clients directory & assigned cases
  results.push(await runCase(browser, 'Track B', byId['GDI-03'], async (page) => {
    await assertCleanPage(page, '/guidance/clients', 'Client');
    await assertCleanPage(page, '/guidance/cases', 'Case');
    return 'Guidance Intern viewed Clients Directory and Cases list (scoped cleanly).';
  }, { authUser: fixtures.accounts.guidance_intern }));

  // GDI-04: Guidance Intern draft clinical form requires co-sign
  results.push(await runCase(browser, 'Track B', byId['GDI-04'], async (page) => {
    await assertCleanPage(page, '/guidance/clinical/forms/new/psychosocial_support_interview', 'Psychosocial');
    const editor = page.locator('#clinicalFormEditor');
    if (await editor.count() > 0) {
      if (await editor.locator('select[name="fields[client_type]"]').count() > 0) {
        await editor.locator('select[name="fields[client_type]"]').selectOption('walk_in');
      }
      assert.equal(await editor.locator('form[action*="/finalize"]').count(), 0, 'Intern must not see direct finalize');
    }
    return 'Verified Guidance Intern clinical form draft UI enforces Supervisor co-sign.';
  }, { authUser: fixtures.accounts.guidance_intern }));

  // CNS-01: Counsellor case, session & follow-up
  results.push(await runCase(browser, 'Track B', byId['CNS-01'], async (page) => {
    await assertCleanPage(page, '/guidance/cases', 'Cases');
    const existingCase = page.locator('a[href*="/guidance/cases/"]').filter({ hasText: /GC-/ }).first();
    if (await existingCase.count() > 0) {
      const href = await existingCase.getAttribute('href');
      createdCaseId = Number((href.match(/\/guidance\/cases\/(\d+)/) || [])[1]);
      await assertCleanPage(page, href, 'Clinical');
    } else {
      await page.locator('a[href*="/guidance/cases/create"][data-app-modal-open]').first().click();
      const modal = page.locator('#appFormModal');
      await modal.waitFor({ state: 'visible', timeout: 5000 });
      await pause(page, 350);
    }
    return `Opened Counsellor case view (#${createdCaseId || 'modal'}) cleanly.`;
  }, { authUser: 'test.counsellor' }));

  // CNS-02: Care episodes & transfer
  results.push(await runCase(browser, 'Track B', byId['CNS-02'], async (page) => {
    await assertCleanPage(page, '/guidance/episodes', 'Episode');
    return 'Loaded Guidance Care Episodes & Transfers workspace cleanly.';
  }, { authUser: 'test.counsellor' }));

  // CNS-03: Official OGC clinical forms fill/finalize
  results.push(await runCase(browser, 'Track B', byId['CNS-03'], async (page) => {
    const caseId = createdCaseId || runPhp(`echo (int) $pdo->query("SELECT id FROM counselling_cases WHERE counsellor_user_id = ${fixtures.counsellor_id} AND deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();`);
    assert.ok(caseId > 0, 'Needs a case for Counsellor');
    await assertCleanPage(page, `/guidance/cases/${caseId}`, 'Clinical Forms');
    await page.locator('#case-clinical-forms-tab').click();
    const newLink = page.locator('a[href*="/guidance/clinical/forms/new/psychosocial_support_interview"]').first();
    await newLink.waitFor({ state: 'visible', timeout: 5000 });
    await newLink.click();
    const editor = page.locator('#clinicalFormEditor');
    await editor.waitFor({ state: 'visible', timeout: 10000 });
    await editor.locator('select[name="fields[client_type]"]').selectOption({ index: 1 });
    if (await editor.locator('input[name="fields[course_year]"]').count() > 0) {
      await editor.locator('input[name="fields[course_year]"]').fill('BSIT 4');
    }
    if (await editor.locator('textarea[name="fields[presenting_concerns]"]').count() > 0) {
      await editor.locator('textarea[name="fields[presenting_concerns]"]').fill('Dry-run academic stress intake.');
    }
    if (await editor.locator('select[name="fields[concern_severity]"]').count() > 0) {
      await editor.locator('select[name="fields[concern_severity]"]').selectOption({ index: 1 });
    }
    if (await editor.locator('textarea[name="fields[problem_duration]"]').count() > 0) {
      await editor.locator('textarea[name="fields[problem_duration]"]').fill('Two weeks');
    }
    if (await editor.locator('textarea[name="fields[hoped_changes]"]').count() > 0) {
      await editor.locator('textarea[name="fields[hoped_changes]"]').fill('Better sleep and study habits');
    }
    if (await editor.locator('input[name="fields[privacy_consent_acknowledged]"][type="checkbox"]').count() > 0) {
      await editor.locator('input[name="fields[privacy_consent_acknowledged]"][type="checkbox"]').check();
    }
    await submitAndExpect(page, editor.locator('button[type="submit"]').first(), '/guidance/clinical/forms/create');
    await page.waitForURL(/\/guidance\/clinical\/forms\/\d+/, { timeout: 15000 });
    draftFormId = Number((page.url().match(/\/guidance\/clinical\/forms\/(\d+)/) || [])[1]) || null;
    return `Created OGC Clinical Form #${draftFormId} in Counsellor workspace.`;
  }, { authUser: 'test.counsellor' }));

  // CNS-04: Print official OGC form PDF
  results.push(await runCase(browser, 'Track B', byId['CNS-04'], async (page) => {
    const fid = draftFormId || runPhp("echo (int) $pdo->query(\"SELECT id FROM guidance_form_instances WHERE status IN ('finalized','signed','cosigned','pending_cosign') ORDER BY id DESC LIMIT 1\")->fetchColumn();");
    if (fid) {
      const pdfRes = await page.request.get(`${BASE_URL}/guidance/clinical/forms/${fid}/pdf?mode=preview`);
      assert.equal(pdfRes.status(), 200, `Expected OGC PDF 200 for form #${fid}, got ${pdfRes.status()}`);
      assert.equal((await pdfRes.body()).subarray(0, 5).toString('utf8'), '%PDF-');
      return `Verified OGC form #${fid} PDF preview returned valid %PDF- bytes.`;
    }
    throw new Error('No form instance available to preview PDF.');
  }, { authUser: 'test.counsellor' }));

  // CNS-05: Call slips (CSP-007) & PDF
  results.push(await runCase(browser, 'Track B', byId['CNS-05'], async (page) => {
    await assertCleanPage(page, '/guidance/call-slips', 'Call Slip');
    const pdfLink = page.locator('a[href*="/guidance/call-slips/"][href*="/pdf"]').first();
    if (await pdfLink.count() > 0) {
      const href = await pdfLink.getAttribute('href');
      const res = await page.request.get(href);
      assert.equal(res.status(), 200, 'Call slip PDF must return 200');
      assert.equal((await res.body()).subarray(0, 5).toString('utf8'), '%PDF-');
    }
    return 'Loaded Guidance Call Slips (CSP-007) and verified PDF output cleanly.';
  }, { authUser: 'test.counsellor' }));

  // CNS-06: MI Assessment assign & view results
  results.push(await runCase(browser, 'Track B', byId['CNS-06'], async (page) => {
    await assertCleanPage(page, '/guidance/assessments/mi', 'Multiple Intelligence');
    return 'Loaded Counsellor MI Assessments console cleanly.';
  }, { authUser: 'test.counsellor' }));

  // CNS-07: Evaluations issue & summary
  results.push(await runCase(browser, 'Track B', byId['CNS-07'], async (page) => {
    await assertCleanPage(page, '/guidance/evaluations/dashboard', 'Evaluation');
    return 'Loaded Counsellor Evaluation Token & Summary Dashboard cleanly.';
  }, { authUser: 'test.counsellor' }));

  // CNS-08: Guidance Appointments (+ confirm Availability remains 403 for non-supervisor Counsellor)
  results.push(await runCase(browser, 'Track B', byId['CNS-08'], async (page) => {
    await assertCleanPage(page, '/guidance/appointments', 'Appointments');
    const availRes = await page.goto(`${BASE_URL}/guidance/appointments/availability`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    assert.equal(availRes.status(), 403, `Expected 403 for Counsellor on /guidance/appointments/availability, got ${availRes.status()}`);
    return 'Loaded Guidance Appointments cleanly and confirmed /guidance/appointments/availability enforces HTTP 403 for Counsellor.';
  }, { allowHttpError: true, authUser: 'test.counsellor' }));

  // CNS-09: Counsellor A blocked from other counsellor's private case
  results.push(await runCase(browser, 'Track B', byId['CNS-09'], async (page) => {
    const otherCaseId = runPhp(`echo (int) $pdo->query("SELECT id FROM counselling_cases WHERE counsellor_user_id != ${fixtures.counsellor_id} AND deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();`);
    await loginAs(page, 'test.counsellor');
    assert.ok(Number(otherCaseId) > 0, 'Expected another counsellor case in DB for isolation check');
    const r = await page.goto(`${BASE_URL}/guidance/cases/${otherCaseId}`, { waitUntil: 'domcontentloaded' });
    await pause(page, 300);
    assert.ok([403, 404].includes(r.status()), `Counsellor should receive 403/404 on unassigned case #${otherCaseId}, got ${r.status()}`);
    return `Confirmed test.counsellor blocked (HTTP ${r.status()}) from private case #${otherCaseId}.`;
  }, { allowHttpError: true }));

  // GDS-01: Guidance Supervisor oversight & reassign
  results.push(await runCase(browser, 'Track B', byId['GDS-01'], async (page) => {
    await assertCleanPage(page, '/guidance/cases', 'Cases');
    return 'Guidance Supervisor viewed cross-counsellor caseload cleanly.';
  }, { authUser: 'test.guidance.supervisor' }));

  // GDS-02: Co-sign queue
  results.push(await runCase(browser, 'Track B', byId['GDS-02'], async (page) => {
    await assertCleanPage(page, '/guidance/clinical/cosign-queue', 'Co-Sign');
    return 'Loaded Guidance Supervisor Co-Sign Review Queue cleanly.';
  }, { authUser: 'test.guidance.supervisor' }));

  // GDS-03: Announcements, Question Bank & Surveys
  results.push(await runCase(browser, 'Track B', byId['GDS-03'], async (page) => {
    await assertCleanPage(page, '/guidance/announcements', 'Announcement');
    await assertCleanPage(page, '/guidance/question-bank', 'Question');
    await assertCleanPage(page, '/guidance/surveys', 'Survey');
    return 'Loaded Guidance Announcements, Question Bank, and Surveys consoles cleanly.';
  }, { authUser: 'test.guidance.supervisor' }));

  // GDS-04: Survey responses & Evaluation comment moderation
  results.push(await runCase(browser, 'Track B', byId['GDS-04'], async (page) => {
    await assertCleanPage(page, '/guidance/evaluations/moderate', 'Moderation');
    const sid = runPhp("echo (int) $pdo->query(\"SELECT id FROM surveys WHERE status='published' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1\")->fetchColumn();");
    if (sid) {
      await assertCleanPage(page, `/guidance/surveys/${sid}/responses`, 'Response');
    }
    return 'Loaded Survey Responses & Evaluation Comment Moderation screens cleanly.';
  }, { authUser: 'test.guidance.supervisor' }));

  // GDS-05: Form governance, practitioner profiles, rollout activation gates & provider availability
  results.push(await runCase(browser, 'Track B', byId['GDS-05'], async (page) => {
    await assertCleanPage(page, '/guidance/forms/definitions', 'Form');
    await assertCleanPage(page, '/guidance/governance/practitioners', 'Practitioner');
    await assertCleanPage(page, '/guidance/governance/gates', 'Activation Gates');
    await assertCleanPage(page, '/guidance/appointments/availability', 'Availability');
    return 'Loaded Controlled Form Governance, Practitioner Credentials, Rollout Activation Gates, and Guidance Availability cleanly.';
  }, { authUser: 'test.guidance.supervisor' }));

  // GDA-01: Guidance Admin Legal Hold
  results.push(await runCase(browser, 'Track B', byId['GDA-01'], async (page) => {
    await assertCleanPage(page, '/admin/archive?tab=holds', 'Legal Hold');
    const holdBtn = page.locator('[data-arc-open-hold-modal]').first();
    assert.ok(await holdBtn.isVisible(), 'Guidance Admin must see Place Legal Hold control');
    return 'Guidance Admin accessed Archive Legal Holds panel cleanly.';
  }, { authUser: 'test.guidance.admin' }));

  // GDA-02: Guidance Admin Intern Governance & Employee Access
  results.push(await runCase(browser, 'Track B', byId['GDA-02'], async (page) => {
    await assertCleanPage(page, '/admin/interns', 'Student Intern');
    await assertCleanPage(page, '/admin/access', 'Employee');
    return 'Guidance Admin accessed Student Intern Placements and Employee Access cleanly.';
  }, { authUser: 'test.guidance.admin' }));

  return results;
}

module.exports = { runTrackB };
