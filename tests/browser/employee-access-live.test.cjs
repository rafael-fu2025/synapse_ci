'use strict';

// Explicit opt-in only: uses the real LOCAL app and existing synthetic accounts.
// SYNAPSE_LIVE_ACCESS_TEST=1 node --test tests/browser/employee-access-live.test.cjs
// Changes ONLY test.clinic.staff2 through HTTP and restores its original role.
// Audit events/auth_version increments intentionally remain; no SQL or MIS calls.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const root = path.resolve(__dirname, '../..');
const main = 'http://synapse.localhost:8080/index.php';
const clinic = 'http://clinic.localhost:8080/index.php';
const enabled = process.env.SYNAPSE_LIVE_ACCESS_TEST === '1';
const targetUsername = 'test.clinic.staff2';
const targetMisId = 'SYNAPSE-TEST-CLINIC-STAFF2';
const roles = ['employee', 'clinic_staff', 'clinic_admin', 'counsellor', 'guidance_supervisor',
  'guidance_admin', 'facilities_op', 'bmg_admin', 'audit_reader', 'report_viewer', 'superadmin'];

function executable(chromium) {
  if (fs.existsSync(chromium.executablePath())) return chromium.executablePath();
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  return fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
}

const findings = [];

async function login(page, base, username) {
  const loginPage = await page.goto(base + '/auth/login');
  assert.equal(loginPage.status(), 200, 'Local login page is available');
  assert.match(loginPage.headers()['content-security-policy'] || '', /form-action 'self'(?:;|$)/, 'Same-origin form protection remains enforced');
  await page.locator('#identifier').fill(username);
  await page.locator('#password').fill('password123');
  const csp = [];
  const observe = message => { if (/form-action/.test(message.text()) && /violat|refus|block/i.test(message.text())) csp.push(message.text()); };
  page.on('console', observe);
  const [navigation, response] = await Promise.allSettled([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/auth/login')),
    page.getByRole('button', { name: 'Sign in', exact: true }).click()
  ]);
  page.off('console', observe);
  if (navigation.status === 'rejected' && csp.length > 0) {
    findings.push('HIGH: main-host staff login authenticates but its cross-host redirect is blocked by form-action self CSP, leaving the login page displayed');
    assert.equal(await status(page, main + '/portal'), 200, 'The blocked redirect still established a main-host session');
    // Continue via a normal GET to assess the rest of the flow, without changing CSP.
    return;
  }
  if (navigation.status === 'rejected') throw navigation.reason;
  if (response.status === 'rejected') throw response.reason;
  assert.ok([302, 303].includes(response.value.status()), 'Synthetic local credentials accepted with a POST redirect');
  await page.waitForLoadState('domcontentloaded');
}

async function directory(page, username) {
  const response = await page.goto(main + '/admin/access?q=' + encodeURIComponent(username));
  assert.equal(response.status(), 200);
  return page.locator('tbody tr').filter({ hasText: username === targetUsername ? targetMisId : 'SYNAPSE-TEST-COUNSELLOR2' });
}

async function openTarget(page) {
  const row = await directory(page, targetUsername);
  assert.equal(await row.count(), 1, 'Exact synthetic target must be present before any write');
  await row.getByRole('link', { name: 'Manage access', exact: true }).click();
  const modal = page.locator('#appFormModal');
  await modal.locator('select[name=role]').waitFor();
  assert.match(await modal.innerText(), new RegExp(targetMisId));
  return modal;
}

async function snapshot(page, editUrl) {
  const response = await page.goto(editUrl);
  assert.equal(response.status(), 200);
  const modal = page.locator('#appFormModal');
  await modal.locator('select[name=role]').waitFor();
  assert.match(await modal.innerText(), new RegExp(targetMisId));
  const options = await modal.locator('select[name=role] option').evaluateAll(nodes => nodes.map(n => n.value));
  const missing = roles.filter(role => !options.includes(role));
  assert.equal(missing.length, 1, 'Superadmin form identifies exactly one current role');
  return { role: missing[0], version: Number(await modal.locator('input[name=version]').inputValue()) };
}

async function save(page, role, reason, status = 200) {
  const modal = page.locator('#appFormModal');
  await modal.locator('select[name=role]').selectOption(role);
  await modal.locator('textarea[name=reason]').fill(reason);
  await modal.getByRole('button', { name: 'Save access change', exact: true }).click();
  const confirmation = page.locator('#synapseConfirmModal');
  await confirmation.waitFor({ state: 'visible' });
  const action = await modal.locator('form[data-modal-submit]').getAttribute('action');
  let captured;
  // Forward the real POST unchanged and capture its body before the app's success
  // navigation discards Chromium's response resource. No server response is mocked.
  const forward = async route => {
    const response = await route.fetch({ maxRedirects: 0 });
    const body = await response.body();
    captured = { status: response.status(), payload: JSON.parse(body.toString('utf8')) };
    await route.fulfill({ response, body });
  };
  await page.route(action, forward);
  try {
    await Promise.all([
      page.waitForResponse(r => r.request().method() === 'POST' && r.url() === action),
      confirmation.locator('[data-confirm-accept]').click()
    ]);
  } finally {
    await page.unroute(action, forward);
  }
  assert.ok(captured, 'The real application returned an assignment response');
  const payload = captured.payload;
  assert.equal(captured.status, status, payload.message);
  assert.equal(payload.success, status === 200);
  if (status === 200) {
    await modal.waitFor({ state: 'hidden' });
    await page.waitForURL(main + '/admin/access');
    await page.getByRole('heading', { name: 'Employees & Access', exact: true }).waitFor();
  } else {
    await modal.locator('[data-modal-error]:not(.d-none)').waitFor();
    assert.match(await modal.locator('[data-modal-error]').innerText(), /changed|reload/i);
    assert.equal(await modal.locator('textarea[name=reason]').inputValue(), reason, 'Failed save preserves the reason');
  }
}

async function status(page, url) {
  const response = await page.request.get(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, maxRedirects: 0 });
  return response.status();
}

test('local employee access: real role changes, department scope, stale edits and cross-host sessions', {
  skip: !enabled && 'Set SYNAPSE_LIVE_ACCESS_TEST=1 to authorize synthetic LOCAL role changes', timeout: 180000
}, async t => {
  const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
  const browser = await chromium.launch({ headless: true, executablePath: executable(chromium) });
  const contexts = [];
  const errors = [];
  const reason = 'Local access-flow QA: synthetic test.clinic.staff2 only';
  let superPage, editUrl, original;
  let potentiallyChanged = false;
  let successfulChanges = 0;
  const newPage = async () => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    contexts.push(context);
    await context.route('https://fonts.googleapis.com/**', r => r.fulfill({ contentType: 'text/css', body: '' }));
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', async dialog => { errors.push('Unexpected native browser dialog'); await dialog.dismiss(); });
    return page;
  };
  try {
    superPage = await newPage();
    await login(superPage, main, 'test.superadmin');
    let row = await directory(superPage, targetUsername);
    assert.equal(await row.count(), 1);
    editUrl = await row.getByRole('link', { name: 'Manage access', exact: true }).getAttribute('href');
    assert.equal(new URL(editUrl).origin, 'http://synapse.localhost:8080');
    assert.match(new URL(editUrl).pathname, /^\/index.php\/admin\/access\/\d+\/edit$/);
    original = await snapshot(superPage, editUrl);
    assert.equal(original.role, 'clinic_staff', 'Abort rather than overwrite a nonstandard fixture role');
    assert.ok(original.version > 0);
    const stalePage = await superPage.context().newPage();
    stalePage.setDefaultTimeout(15000);
    await stalePage.goto(editUrl);
    await stalePage.locator('#appFormModal select[name=role]').waitFor();
    t.diagnostic('PASS: main-host login, exact synthetic employee lookup, modal and original-role preflight');

    // Observe the ordinary full-name search path as well as official-ID/username search.
    const fullName = 'Test Clinic Staff 2';
    await superPage.goto(main + '/admin/access?q=' + encodeURIComponent(fullName));
    if (await superPage.locator('tbody tr').filter({ hasText: targetMisId }).count() === 0) {
      findings.push('MEDIUM: complete employee name search does not find the account; username/ID and individual name fields work');
      t.diagnostic(findings.at(-1));
    }

    const manager = await newPage();
    await login(manager, main, 'test.clinic.admin');
    assert.equal(new URL(manager.url()).pathname, '/index.php/portal', 'Main-host staff sign-in finishes on the personal portal');
    let modal = await openTarget(manager);
    const options = await modal.locator('select[name=role] option').evaluateAll(nodes => nodes.map(n => n.value).filter(Boolean));
    assert.deepEqual(options, ['employee'], 'Clinic admin cannot grant administrator or cross-workstation roles');
    await modal.locator('select[name=role]').selectOption('employee');
    await modal.locator('textarea[name=reason]').fill(reason);
    let writes = 0;
    const countWrites = req => { if (req.method() === 'POST' && /\/admin\/access\/\d+\/update$/.test(new URL(req.url()).pathname)) writes++; };
    manager.on('request', countWrites);
    await modal.getByRole('button', { name: 'Save access change', exact: true }).click();
    await manager.locator('#synapseConfirmModal').waitFor({ state: 'visible' });
    await manager.locator('#synapseConfirmModal [data-confirm-cancel]').click();
    await manager.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
    assert.equal(writes, 0, 'Cancelling confirmation must not submit access changes');
    manager.off('request', countWrites);
    assert.equal(await modal.locator('textarea[name=reason]').inputValue(), reason);
    t.diagnostic('PASS: Clinic admin role choices and cancellation preserve the form without submitting');

    row = await directory(superPage, 'test.counsellor2');
    assert.equal(await row.count(), 1);
    const foreignEdit = await row.getByRole('link', { name: 'Manage access', exact: true }).getAttribute('href');
    assert.equal(await status(manager, foreignEdit), 404, 'Direct access to another department account must fail');
    const student = await newPage();
    await login(student, main, 'test.student');
    assert.equal(await status(student, main + '/admin/access'), 403);
    assert.equal(await status(student, main + '/clinic/teams'), 403);
    t.diagnostic('PASS: cross-department direct URLs and student attempts are denied');

    const staff = await newPage();
    await login(staff, clinic, targetUsername);
    assert.equal(await status(staff, clinic + '/clinic/teams'), 200);
    potentiallyChanged = true; // Set before POST, including ambiguous-response failures.
    await save(manager, 'employee', reason + ': revoke workstation role');
    successfulChanges++;
    assert.equal(await status(staff, clinic + '/clinic/teams'), 401, 'Old department-host session must be revoked');
    await login(staff, main, targetUsername);
    assert.equal(await status(staff, main + '/portal'), 200);
    await staff.goto(main + '/portal');
    assert.equal(await staff.locator('.sidebar-nav a').filter({ hasText: 'Clinic Teams' }).count(), 0);
    assert.equal(await status(staff, main + '/admin/access'), 403);
    assert.equal(await status(staff, main + '/guidance/teams'), 403);
    t.diagnostic('PASS: persisted revocation, old workstation session rejection and employee personal-portal access without staff modules');

    await save(stalePage, 'employee', reason + ': stale form must fail', 409);
    t.diagnostic('PASS: stale modal rejects the write with 409, retains entered reason and remains recoverable');

    modal = await openTarget(manager);
    assert.equal(Number(await modal.locator('input[name=version]').inputValue()), original.version + 1);
    await save(manager, 'clinic_staff', reason + ': restore original workstation role');
    successfulChanges++;
    assert.equal(await status(staff, main + '/portal'), 401, 'Promotion must revoke the employee session on the main host');
    const restored = await snapshot(superPage, editUrl);
    assert.equal(restored.role, original.role);
    assert.equal(restored.version, original.version + 2);
    await login(staff, clinic, targetUsername);
    assert.equal(await status(staff, clinic + '/clinic/teams'), 200);
    assert.equal(await status(staff, clinic + '/guidance/teams'), 403);
    assert.equal(await status(staff, clinic + '/facilities/teams'), 403);
    const cookies = await staff.context().cookies();
    assert.ok(cookies.length > 0);
    assert.ok(cookies.every(cookie => ['synapse.localhost', 'clinic.localhost'].includes(cookie.domain)), 'Cookies remain host-only on the two local hosts');
    t.diagnostic('PASS: reassignment restores workstation access, revokes the main-host session and does not grant other workstations');


    const auditResponse = await superPage.goto(main + '/admin/audit');
    assert.equal(auditResponse.status(), 200);
    const targetId = /\/(\d+)\/edit$/.exec(editUrl)[1];
    const auditRows = superPage.locator('tbody tr').filter({ hasText: 'access.role_changed' })
      .filter({ hasText: new RegExp('user\\s+#' + targetId + '(?:\\s|$)') });
    assert.ok(await auditRows.count() >= 2, 'Both successful role changes appear in the ledger');
    await Promise.all([
      superPage.waitForURL('**/admin/audit'),
      superPage.getByRole('button', { name: 'Verify Cryptographic Chain' }).click()
    ]);
    await superPage.getByText(/Cryptographic chain verified: All/).waitFor();
    t.diagnostic('PASS: successful changes appear in the audit ledger and the live cryptographic chain verifies');
    const details = superPage.locator('[data-role-change-details]').filter({ hasText: reason });
    assert.ok(await details.count() >= 2, 'Role-change details and reasons are reviewable from the ledger');
    assert.match(await details.first().innerText(), /Role change details/);
    for (const finding of findings) t.diagnostic('QUALITY FINDING: ' + finding);
    assert.deepEqual(errors, [], 'No page script errors');
    assert.deepEqual(findings, [], 'The flow quality gate fails until the reported product issues are resolved');
  } finally {
    try {
      if (potentiallyChanged && original && editUrl) {
        const current = await snapshot(superPage, editUrl);
        if (current.role !== original.role) {
          await save(superPage, original.role, reason + ': finally restore original fixture role');
          assert.equal((await snapshot(superPage, editUrl)).role, original.role);
        } else if (successfulChanges === 0) {
          assert.equal(current.version, original.version, 'Failed transaction must roll back auth_version too');
          t.diagnostic('PASS: failed role write rolled back both role and authentication version');
        }
        t.diagnostic('CLEANUP VERIFIED: test.clinic.staff2 has its original clinic_staff role; existing audit history retained');
      }
    } finally {
      for (const finding of findings) t.diagnostic('QUALITY FINDING: ' + finding);
      for (const context of contexts) await context.close();
      await browser.close();
    }
  }
});
