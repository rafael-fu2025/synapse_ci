'use strict';
// Real views/assets, synthetic POST receiver. Processing/authority have separate PHP tests.
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
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=', 'base64');
let browser, server, origin, failSave = false, holdPost = null;
const posts = [], rendered = new Map();
function render(url) {
  if (!rendered.has(url)) {
    const result = spawnSync('php', ['tests/_support/render-profile-settings-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: url }), encoding: 'utf8', timeout: 20000
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error/);
    rendered.set(url, result.stdout);
  }
  return rendered.get(url);
}
before(async () => {
  fs.mkdirSync(path.join(root, 'writable/profile-settings-preview'), { recursive: true });
  server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, origin);
      if (req.method === 'POST') {
        const chunks = []; for await (const chunk of req) chunks.push(chunk);
        const data = await new Request(origin + req.url, { method: 'POST', headers: { 'Content-Type': req.headers['content-type'] }, body: Buffer.concat(chunks) }).formData();
        const file = data.get('photo');
        posts.push({ path: url.pathname, names: [...data.keys()], token: data.get('csrf_test_name'), size: file?.size, type: file?.type, filename: file?.name });
        if (holdPost) await holdPost;
        if (url.pathname.endsWith('/emergency-contact')) {
          const contact = {
            emergency_contact_name: data.get('emergency_contact_name') || '',
            emergency_contact_phone: data.get('emergency_contact_phone') || '',
            emergency_contact_relationship: data.get('emergency_contact_relationship') || '',
          };
          res.writeHead(failSave ? 422 : 200, {
            'Content-Type': 'application/json',
            'X-CSRF-NAME': 'csrf_test_name',
            'X-CSRF-TOKEN': 'contact-refreshed-token',
            'X-CSRF-HEADER': 'X-CSRF-TOKEN'
          });
          res.end(JSON.stringify({
            success: !failSave,
            message: failSave ? 'Invalid contact details' : 'Emergency contact updated.',
            emergency_contact: contact,
            csrf: { name: 'csrf_test_name', hash: 'contact-refreshed-token' }
          }));
          return;
        }
        if (req.headers['x-requested-with'] === 'XMLHttpRequest') {
          const hasPhoto = !url.pathname.endsWith('/remove');
          res.writeHead(failSave ? 422 : 200, { 'Content-Type': 'application/json',
            'X-CSRF-NAME': 'csrf_test_name', 'X-CSRF-TOKEN': 'photo-refreshed-token', 'X-CSRF-HEADER': 'X-CSRF-TOKEN',
            ...(!failSave ? { 'Set-Cookie': `fixtureSaved=${hasPhoto ? '1' : '0'}; Path=/; SameSite=Lax` } : {}) });
          res.end(JSON.stringify({ success: !failSave, message: failSave ? '<img src=x onerror="window.photoXss=1"> Upload rejected' : hasPhoto ? 'Profile picture updated.' : 'Profile picture removed.',
            has_photo: hasPhoto, photo_url: origin + '/account/photo?v=' + posts.length,
            csrf: { name: 'csrf_test_name', hash: 'photo-refreshed-token' } })); return;
        }
        res.writeHead(303, { Location: '/portal/settings?uploaded=1' }); res.end(); return;
      }
      if (['/portal/settings', '/portal/announcements', '/portal'].includes(url.pathname)) {
        const cookies = Object.fromEntries((req.headers.cookie || '').split(';').map(item => item.trim().split('=')));
        url.searchParams.set('kind', cookies.fixtureKind || url.searchParams.get('kind') || 'student');
        if (cookies.fixtureSaved === '1') url.searchParams.set('saved', '1');
        res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(render(url.pathname + url.search)); return;
      }
      if (['/portal/settings/photo', '/account/photo'].includes(url.pathname)) { res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'no-store' }); res.end(png); return; }
      if (!url.pathname.startsWith('/assets/')) { res.writeHead(404).end(); return; }
      const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(path.join(root, 'public/assets') + path.sep) || !fs.existsSync(file)) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' }); res.end(fs.readFileSync(file));
    } catch (error) { if (!res.headersSent) res.writeHead(500); res.end(String(error.stack)); }
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening'); origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
});
after(async () => { await browser?.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); });
async function ready(page) {
  await page.waitForFunction(() => document.querySelector('#appFormModal [data-profile-settings]') &&
    document.querySelector('#appFormModal')?.getAttribute('aria-modal') === 'true' &&
    bootstrap.Modal.getInstance(document.querySelector('#appFormModal'))?._focustrap?._isActive);
}
async function pageFor(t, kind = 'student', dark = false, saved = true, host = false) {
  failSave = false; holdPost = null;
  const context = await browser.newContext({ viewport: { width: dark ? 390 : 1280, height: 900 } });
  await context.addCookies([{ name: 'fixtureKind', value: kind, url: origin }, { name: 'fixtureSaved', value: saved ? '1' : '0', url: origin }]);
  await context.addInitScript(dark => {
    localStorage.setItem('na-dark-mode', dark ? '1' : '0');
    window.revoked = [];
    const revoke = URL.revokeObjectURL.bind(URL);
    URL.revokeObjectURL = url => { window.revoked.push(url); revoke(url); };
  }, dark);
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.fulfill({ status: 200, body: '' }));
  const page = await context.newPage(); page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(20000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + (host ? '/portal/announcements' : '/portal/settings'));
  await page.waitForFunction(() => !!window.SynapseProfileSettings);
  if (!host) await ready(page);
  return page;
}
async function open(page, header = false) {
  const response = page.waitForResponse(response => new URL(response.url()).pathname === '/portal/settings' && new URL(response.url()).searchParams.get('fragment') === '1');
  if (header) await page.locator('.user-trigger').click();
  const link = page.locator(header ? '.user-menu a[data-app-modal-open][href$="/portal/settings"]' : '.sidebar a[data-app-modal-open][href$="/portal/settings"]');
  if (!header && (await link.boundingBox())?.x < 0) await page.locator('.sidebar-toggle').click();
  await link.click();
  const html = await (await response).text();
  assert.doesNotMatch(html, /<html|<script/);
  await ready(page);
  assert.equal(await page.locator('#appFormModal .modal-lg, #appFormModal .modal-xl').count(), 0, 'Settings uses the medium/default dialog width');
  return link;
}
async function select(page, buffer = png, name = 'portrait.png') {
  await page.locator('#profilePhotoUpload').setInputFiles({ name, mimeType: 'image/png', buffer });
  await page.locator('[data-profile-photo-preview]').waitFor({ state: 'visible' });
}
async function saved(page, text = 'updated') {
  await page.waitForFunction(text => !document.querySelector('[data-profile-photo-form][aria-busy="true"]') &&
    !document.querySelector('[data-profile-photo-remove][aria-busy="true"]') &&
    document.querySelector('[data-profile-photo-feedback]')?.textContent.includes(text), text);
}
for (const kind of ['student', 'employee']) for (const dark of [false, true]) {
  test(`${kind} ${dark ? 'dark mobile' : 'light desktop'} initials remain white on maroon in the settings dialog`, async t => {
    const page = await pageFor(t, kind, dark, false);
    const initials = kind === 'student' ? 'SS' : 'SE';
    for (const selector of ['.user-avatar', '.user-menu-avatar', '[data-profile-photo-current]']) {
      const src = await page.locator(selector).getAttribute('src');
      assert.match(src, /^data:image\/svg\+xml;base64,/);
      const svg = Buffer.from(src.split(',')[1], 'base64').toString('utf8');
      assert.ok(svg.includes(`>${initials}</text>`));
      assert.ok(svg.includes('fill="#800000"')); assert.ok(svg.includes('fill="#ffffff"'));
      assert.doesNotMatch(svg, /<script|<image|foreignObject|onload=/);
    }
    assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), false);
    await page.locator('.user-avatar').evaluate(image => { image.src = '/missing-avatar.webp'; });
    await page.waitForFunction(() => document.querySelector('.user-avatar').src.startsWith('data:image/svg+xml;base64,') && document.querySelector('.user-avatar').complete);
    assert.equal(await page.locator('.user-avatar').evaluate(image => image.naturalWidth), 128);
  });
  test(`${kind} ${dark ? 'dark mobile' : 'light desktop'} modal validates and previews without changing the underlying page`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, kind, dark, true, true);
    const address = page.url();
    await page.evaluate(() => { window.originalMain = document.querySelector('#main'); });
    const opener = await open(page, !dark);
    await page.screenshot({ path: path.join(root, 'writable/profile-settings-preview', `${kind}-${dark ? 'dark-mobile' : 'light-desktop'}.png`), animations: 'disabled', timeout: 15000 });
    assert.equal(await page.locator('html').getAttribute('data-theme'), dark ? 'dark' : 'light');
    assert.equal(await page.locator('[data-profile-settings] input:not([type="hidden"]):not([type="file"])').count(), 0);
    assert.match(await page.locator('#profilePhotoHelp').innerText(), /10 MB/);
    const current = await page.locator('[data-profile-photo-current]').getAttribute('src');
    const before = posts.length, input = page.locator('#profilePhotoUpload');
    await input.setInputFiles({ name: 'too-large.png', mimeType: 'image/png', buffer: Buffer.alloc(10 * 1024 * 1024 + 1) });
    assert.equal(await input.evaluate(el => el.checkValidity()), false);
    await page.getByRole('button', { name: 'Upload picture' }).click(); assert.equal(posts.length, before);
    await input.setInputFiles({ name: 'unsafe.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
    assert.equal(await input.evaluate(el => el.checkValidity()), false);
    await input.setInputFiles({ name: 'broken.png', mimeType: 'image/png', buffer: Buffer.from('not an image') });
    await page.waitForFunction(() => document.querySelector('#profilePhotoUpload').validity.customError);
    await select(page);
    assert.equal(await page.locator('[data-profile-photo-current]').getAttribute('src'), current);
    const blob = await page.locator('[data-profile-photo-preview]').getAttribute('src');
    await input.setInputFiles([]);
    assert.equal(await page.locator('[data-profile-photo-preview]').isVisible(), false);
    assert.ok((await page.evaluate(() => window.revoked)).includes(blob));
    assert.equal(posts.length, before);
    const bounds = await page.locator('#appFormModal .modal-content').boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= (dark ? 390 : 1280));
    await page.locator('#appFormModal .modal-header [data-app-modal-close]').click();
    await page.locator('#appFormModal').waitFor({ state: 'detached' });
    assert.equal(page.url(), address);
    assert.equal(await page.evaluate(() => document.querySelector('#main') === window.originalMain), true);
    assert.equal(await (dark ? opener : page.locator('.user-trigger')).evaluate(link => link === document.activeElement), true);
  });
}
test('10 MB AJAX upload updates avatars in place; 422 retains file/preview and retry sends fresh CSRF', { timeout: 30000 }, async t => {
  const page = await pageFor(t, 'student', false, false, true);
  await open(page);
  const address = page.url(), before = posts.length;
  const buffer = Buffer.alloc(10 * 1024 * 1024); png.copy(buffer);
  await select(page, buffer, 'full-limit.png');
  const blob = await page.locator('[data-profile-photo-preview]').getAttribute('src');
  failSave = true;
  await page.getByRole('button', { name: 'Upload picture' }).click();
  await page.locator('[data-modal-error]:not(.d-none)').waitFor();
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files[0].size), 10 * 1024 * 1024);
  assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.equal(await page.locator('[data-modal-error] img').count(), 0);
  assert.equal(await page.evaluate(() => window.photoXss), undefined);
  assert.equal(await page.locator('#profilePhotoUpload').isEnabled(), true);
  failSave = false;
  await page.getByRole('button', { name: 'Upload picture' }).click();
  await saved(page);
  assert.equal(page.url(), address);
  assert.equal(posts.length, before + 2);
  assert.equal(posts.at(-1).token, 'photo-refreshed-token');
  assert.equal(posts.at(-1).size, 10 * 1024 * 1024);
  assert.deepEqual(posts.at(-1).names.sort(), ['MAX_FILE_SIZE', 'csrf_test_name', 'photo'].sort());
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 0);
  assert.equal(await page.locator('[data-profile-photo-preview]').isVisible(), false);
  assert.ok((await page.evaluate(() => window.revoked)).includes(blob));
  const photo = await page.locator('[data-profile-photo-current]').getAttribute('src');
  assert.match(photo, /\/account\/photo\?v=/);
  assert.deepEqual(await page.locator('[data-profile-avatar]').evaluateAll(images => images.map(image => image.getAttribute('src'))), [photo, photo]);
  assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), true);
  assert.equal(await page.locator('#appFormModal').isVisible(), true);
});
test('emergency contact form renders in settings modal and saves via AJAX with CSRF', async t => {
  const page = await pageFor(t, 'student', false, false);
  const form = page.locator('#emergencyContactForm');
  assert.equal(await form.count(), 1);
  assert.equal(await form.getAttribute('method'), 'POST');
  assert.equal(new URL(await form.getAttribute('action')).pathname, '/portal/settings/emergency-contact');

  const nameInput = page.locator('#emergencyContactName');
  const relInput = page.locator('#emergencyContactRelationship');
  const phoneInput = page.locator('#emergencyContactPhone');
  const feedback = page.locator('[data-emergency-contact-feedback]');

  assert.equal(await nameInput.inputValue(), 'Maria Santos');
  assert.equal(await relInput.inputValue(), 'Mother');
  assert.equal(await phoneInput.inputValue(), '0917-123-4567');

  await nameInput.fill('Dr. Roberto Santos');
  await relInput.fill('Father');
  await phoneInput.fill('+63 918 555 1234');

  const before = posts.length;
  await form.locator('button[type="submit"]').click();

  await page.waitForFunction(() => document.querySelector('[data-emergency-contact-feedback]')?.textContent.includes('Emergency contact updated.'));
  assert.equal(posts.length, before + 1);
  assert.equal(posts.at(-1).path, '/portal/settings/emergency-contact');
  assert.ok(posts.at(-1).names.includes('csrf_test_name'));
  assert.ok(posts.at(-1).token && typeof posts.at(-1).token === 'string');

  assert.equal(await nameInput.inputValue(), 'Dr. Roberto Santos');
  assert.equal(await relInput.inputValue(), 'Father');
  assert.equal(await phoneInput.inputValue(), '+63 918 555 1234');
});

test('emergency contact card appears on portal home and edit opens settings modal', async t => {
  const page = await pageFor(t, 'student', false, false, true);
  await page.goto(origin + '/portal');
  await page.waitForFunction(() => !!document.querySelector('[data-portal-emergency-card]'));

  const card = page.locator('[data-portal-emergency-card]');
  assert.equal(await card.count(), 1);
  assert.match(await card.innerText(), /Emergency Contact/);
  assert.match(await card.innerText(), /Maria Santos/);
  assert.match(await card.innerText(), /Mother/);
  assert.match(await card.innerText(), /0917-123-4567/);

  const editBtn = card.locator('a[data-app-modal-open]');
  assert.equal(await editBtn.count(), 1);
  assert.equal(new URL(await editBtn.getAttribute('href')).pathname, '/portal/settings');
});

test('closing a selected picture requires discard consent; cancel keeps preview and focus contained', async t => {
  const page = await pageFor(t, 'employee', true, false, true);
  await open(page);
  await select(page);
  const blob = await page.locator('[data-profile-photo-preview]').getAttribute('src'), before = posts.length;
  await page.locator('#appFormModal .modal-header [data-app-modal-close]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-cancel]').click();
  await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
  assert.equal(await page.locator('#profilePhotoUpload').evaluate(input => input.files.length), 1);
  assert.equal(await page.locator('[data-profile-photo-preview]').getAttribute('src'), blob);
  assert.equal(await page.locator('#appFormModal').isVisible(), true);
  await page.waitForFunction(() => {
    const modal = document.querySelector('#appFormModal');
    return !modal.inert && modal.contains(document.activeElement);
  });
  assert.equal(await page.locator('#appFormModal').evaluate(modal => modal.contains(document.activeElement)), true);
  await page.waitForFunction(() => !bootstrap.Modal.getInstance(document.querySelector('#synapseConfirmModal'))._isTransitioning);
  await page.locator('#appFormModal .modal-header [data-app-modal-close]').click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click();
  await page.locator('#appFormModal').waitFor({ state: 'detached' });
  assert.ok((await page.evaluate(() => window.revoked)).includes(blob));
  assert.equal(posts.length, before);
  assert.equal(await page.locator('.modal-backdrop').count(), 0);
});
test('removal confirms and restores initials/header without navigating or stacking a second settings modal', async t => {
  const page = await pageFor(t, 'employee', false, true, true);
  await open(page);
  const uploadBox = await page.getByRole('button', { name: 'Upload picture' }).boundingBox();
  const removeBox = await page.getByRole('button', { name: 'Remove picture', exact: true }).boundingBox();
  assert.ok(Math.abs(uploadBox.y - removeBox.y) < 1, 'Picture actions share one horizontal row');
  assert.ok(Math.abs(uploadBox.height - removeBox.height) < 1, 'Picture actions have matching heights');
  assert.equal(await page.getByRole('heading', { name: 'Appearance', exact: true }).count(), 0);
  assert.equal(await page.getByText('Use the sun/moon button in the header', { exact: false }).count(), 0);
  const address = page.url(), before = posts.length;
  await page.getByRole('button', { name: 'Remove picture', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-cancel]').click();
  await page.locator('#synapseConfirmModal').waitFor({ state: 'hidden' });
  assert.equal(posts.length, before);
  await page.waitForFunction(() => !bootstrap.Modal.getInstance(document.querySelector('#synapseConfirmModal'))._isTransitioning);
  await page.getByRole('button', { name: 'Remove picture', exact: true }).click();
  await page.locator('#synapseConfirmModal.show').waitFor();
  await page.locator('[data-confirm-accept]').click();
  await saved(page, 'removed');
  assert.equal(posts.length, before + 1); assert.equal(posts.at(-1).path, '/portal/settings/photo/remove');
  assert.equal(await page.locator('[data-profile-photo-remove]').isVisible(), false);
  for (const src of await page.locator('[data-profile-avatar], [data-profile-photo-current]').evaluateAll(images => images.map(image => image.src))) {
    assert.match(src, /^data:image\/svg\+xml;base64,/);
    assert.ok(Buffer.from(src.split(',')[1], 'base64').toString().includes('>SE</text>'));
  }
  assert.equal(page.url(), address); assert.equal(await page.locator('#appFormModal').count(), 1);
});
test('pending saves block duplicate writes and dismissal; direct route still closes to the fixed portal', { timeout: 30000 }, async t => {
  const page = await pageFor(t);
  await select(page);
  let release; holdPost = new Promise(resolve => { release = resolve; });
  const before = posts.length;
  await page.getByRole('button', { name: 'Upload picture' }).click();
  await page.locator('[data-profile-photo-form][aria-busy="true"]').waitFor();
  await page.waitForFunction(() => document.querySelector('#profilePhotoUpload').disabled);
  await page.evaluate(() => {
    bootstrap.Modal.getInstance(document.querySelector('#appFormModal')).hide();
    document.querySelector('[data-profile-photo-form]').requestSubmit();
  });
  assert.equal(await page.locator('#appFormModal').isVisible(), true);
  release(); holdPost = null;
  await saved(page);
  assert.equal(posts.length, before + 1);
  await page.locator('#appFormModal .modal-header [data-app-modal-close]').click();
  await page.waitForURL(origin + '/portal');
  assert.equal(await page.locator('#appFormModal').count(), 0);
});
