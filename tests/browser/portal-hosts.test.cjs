'use strict';
// Shell/host-only browser behavior fixtures. Actual authorization is in PortalHostPolicyTest.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const roles = ['superadmin', 'clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor', 'counsellor', 'bmg_admin', 'facilities_op', 'audit_reader', 'report_viewer', 'student', 'employee'];
const hosts = { 'synapse.localhost': 'main', 'clinic.localhost': 'clinic', 'guidance.localhost': 'guidance', 'bmg.localhost': 'bmg' };
const permitted = (role, portal) => role === 'superadmin' || ({ clinic: ['clinic_admin', 'clinic_staff'], guidance: ['guidance_admin', 'guidance_supervisor', 'counsellor'], bmg: ['bmg_admin', 'facilities_op'] }[portal] || []).includes(role);
let server, browser, port;
const origin = host => `http://${host}:${port}`;

before(async () => {
  server = http.createServer((req, res) => {
    const host = req.headers.host.split(':')[0];
    const url = new URL(req.url, origin(host));
    if (url.pathname === '/fixture/sign-in') {
      const role = url.searchParams.get('role');
      if (!roles.includes(role)) { res.writeHead(400).end(); return; }
      res.writeHead(302, { Location: '/index.php/portal', 'Set-Cookie': `fixture_role=${role}; HttpOnly; SameSite=Lax; Path=/` }).end();
      return;
    }
    if (url.pathname.startsWith('/assets/')) {
      const base = path.join(root, 'public'), file = path.resolve(base, '.' + url.pathname);
      if (!file.startsWith(base + path.sep)) { res.writeHead(403).end(); return; }
      fs.readFile(file, (error, body) => {
        if (error) { res.writeHead(404).end(); return; }
        res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream' });
        res.end(body);
      });
      return;
    }
    if (url.pathname.endsWith('/notifications')) {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ items: [], unread_count: 0 })); return;
    }
    const role = /(?:^|;\s*)fixture_role=([^;]+)/.exec(req.headers.cookie || '')?.[1];
    if (!role) { res.writeHead(200, { 'Content-Type': 'text/html' }).end('<h1>Sign in on this host</h1>'); return; }
    const result = spawnSync('php', ['tests/_support/render-portal-host-fixture.php'], {
      cwd: root, input: JSON.stringify({ host, port, path: url.pathname.replace(/^\/index\.php\/?/, ''), role }), encoding: 'utf8', timeout: 10000
    });
    if (result.status !== 0) { res.writeHead(500).end(result.stderr); return; }
    res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'private, no-store' }).end(result.stdout);
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening'); port = server.address().port;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath });
}, { timeout: 40000 });
after(async () => {
  await browser?.close();
  if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

for (const [host, portal] of Object.entries(hosts)) {
  test(`${host}: all 12 roles see only the selected authorized workstation`, { timeout: 90000 }, async t => {
    const context = await browser.newContext();
    t.after(() => context.close());
    await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
    const page = await context.newPage();
    for (const role of roles) {
      await page.goto(origin(host) + '/fixture/sign-in?role=' + role);
      await page.locator('.sidebar-nav').waitFor();
      const headings = await page.locator('.sidebar-nav .nav-heading').allTextContents();
      for (const [workspace, title] of Object.entries({ clinic: 'Clinic Workstation', guidance: 'Guidance Workstation', bmg: 'Facilities (BMG)' })) {
        assert.equal(headings.some(text => text.trim() === title), portal === workspace && permitted(role, workspace), `${role}@${host}: ${title}`);
      }
      const personal = page.locator('.sidebar-nav a').filter({ hasText: 'My Health Portal' });
      const destination = new URL(await personal.getAttribute('href'));
      assert.equal(destination.hostname, 'synapse.localhost');
      assert.equal(destination.port, String(port));
      assert.equal(destination.pathname, '/index.php/portal');
      const options = page.locator('[aria-label="Switch workspace"]');
      assert.equal(await options.count(), ['student', 'employee', 'audit_reader', 'report_viewer'].includes(role) ? 0 : 1);
    }
  });
}

test('single-host path fallback shows all workstations without a redundant switcher', { timeout: 30000 }, async t => {
  const context = await browser.newContext(); t.after(() => context.close());
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  const page = await context.newPage();
  await page.goto(origin('127.0.0.1') + '/fixture/sign-in?role=superadmin');
  await page.locator('.sidebar-nav').waitFor();
  const headings = await page.locator('.sidebar-nav .nav-heading').allTextContents();
  for (const title of ['Clinic Workstation', 'Guidance Workstation', 'Facilities (BMG)']) {
    assert.ok(headings.some(text => text.trim() === title), 'Path mode must retain ' + title);
  }
  assert.equal(await page.locator('[aria-label="Switch workspace"]').count(), 0,
    'The switcher must be absent when the sidebar already lists every authorized workstation');
});

test('workspace switching is cross-origin and host-only sign-in does not transfer', { timeout: 30000 }, async t => {
  const context = await browser.newContext(); t.after(() => context.close());
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  const page = await context.newPage();
  await page.goto(origin('clinic.localhost') + '/fixture/sign-in?role=superadmin');
  await page.getByRole('button', { name: 'Switch workspace', exact: true }).click();
  const guidance = page.getByRole('link', { name: 'Guidance workstation', exact: true });
  assert.equal(await guidance.getAttribute('data-navigation'), 'false');
  assert.equal(await guidance.getAttribute('href'), origin('guidance.localhost') + '/index.php/guidance');
  await guidance.click();
  await page.waitForURL(origin('guidance.localhost') + '/index.php/guidance');
  assert.equal(await page.getByRole('heading', { name: 'Sign in on this host' }).count(), 1);
  const cookies = await context.cookies();
  assert.equal(cookies.filter(cookie => cookie.name === 'fixture_role').length, 1);
  assert.equal(cookies.find(cookie => cookie.name === 'fixture_role').domain, 'clinic.localhost');
});
