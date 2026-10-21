'use strict';

// Actual CI-rendered views and production scripts. API/save responses are fixtures;
// backend authentication, persistence and validation are covered by the PHP suite.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { createHash } = require('node:crypto');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const cache = new Map(), posts = [], apiCalls = [];
let failSave = false, failLoad = false, failSlots = false, loadGate = null, saveGate = null;
let exceptionConflict = false;
function gate() { let release; const promise = new Promise(resolve => { release = resolve; }); return { promise, release }; }

const cases = [
  { kind: 'clinic', parent: '/clinic/appointments', url: '/clinic/appointments/create', title: 'Book New Clinic Appointment' },
  { kind: 'guidance', parent: '/guidance/appointments', url: '/guidance/appointments/create', title: 'Book New Guidance Appointment' },
  { kind: 'referral', parent: '/clinic/referrals', url: '/clinic/referrals/create', title: 'Refer to Guidance' },
  { kind: 'add', parent: '/admin/carousel', url: '/admin/carousel/create', title: 'Add Carousel Slide' },
  { kind: 'edit', parent: '/admin/carousel', url: '/admin/carousel/23/edit', title: 'Edit Carousel Slide' },
  { kind: 'clinic-confirm', parent: '/clinic/appointments', url: '/clinic/appointments/31/confirm', title: 'Confirm Clinic Appointment' },
  { kind: 'guidance-confirm', parent: '/guidance/appointments', url: '/guidance/appointments/31/confirm', title: 'Confirm Guidance Appointment' },
  { kind: 'personal-clinic', parent: '/portal', url: '/portal/appointments/clinic/create', title: 'Book New Clinic Appointment' },
  { kind: 'personal-guidance', parent: '/portal', url: '/portal/appointments/guidance/create', title: 'Book New Guidance Appointment' },
  { kind: 'clinic-availability', parent: '/clinic/appointments', url: '/clinic/appointments/availability', title: 'Clinic Provider Availability' },
  { kind: 'guidance-availability', parent: '/guidance/appointments', url: '/guidance/appointments/availability', title: 'Guidance Provider Availability' }
];
function render(kind, requestPath, fragment) {
  const key = kind + ':' + requestPath + ':' + fragment;
  if (cache.has(key)) return cache.get(key);
  const result = spawnSync('php', ['tests/_support/render-modal-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, path: requestPath, kind, fragment }), encoding: 'utf8', timeout: 10_000
  });
  assert.equal(result.status, 0, result.stderr);
  assert.ok(result.stdout.includes('<div'), result.stdout);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  cache.set(key, result.stdout);
  return result.stdout;
}
function fields(body) {
  const result = {};
  for (const match of body.matchAll(/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([\s\S]*?)(?=\r\n--)/g)) result[match[1]] = match[2];
  return result;
}

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n))
      .sort((a,b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir,n,'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (req.method === 'POST') {
      const chunks = [];
      req.on('data', chunk => chunks.push(chunk));
      req.on('end', async () => {
        const body = Buffer.concat(chunks).toString('utf8');
        const submitted = { path: url.pathname,
          fields: req.headers['content-type']?.startsWith('application/x-www-form-urlencoded')
            ? Object.fromEntries(new URLSearchParams(body)) : fields(body),
          requestedWith: req.headers['x-requested-with'] };
        posts.push(submitted);
        if (saveGate) await saveGate.promise;
        if (res.destroyed) return;
        const availability = /\/availability(?:\/|$)/.test(url.pathname);
        const saved = submitted.fields;
        const context = new URLSearchParams({
          q: saved.return_q || '', provider: saved.return_provider || '',
          day: saved.return_day || '1', section: saved.return_section || 'weekly'
        });
        if (availability && saved.staff_assignment_id) context.set('provider', saved.staff_assignment_id);
        if (availability && saved.day_of_week) context.set('day', saved.day_of_week);
        const redirect = availability ? url.pathname.replace(/\/availability(?:\/.*)?$/, '/availability') + '?' + context
          : url.pathname.startsWith('/portal/appointments/') ? '/portal'
          : url.pathname.includes('appointments') ? url.pathname.replace(/\/(?:store|31\/confirm|availability(?:\/template)?)$/, '')
          : /\/(clinic|guidance)\/referrals\//.test(url.pathname) ? url.pathname.replace(/\/store$/, '') : '/admin/carousel';
        if (availability && !failSave && submitted.requestedWith !== 'XMLHttpRequest') {
          res.writeHead(303, { Location: origin + redirect }).end();
          return;
        }
        const conflict = exceptionConflict && /\/availability\/exceptions(?:\/remove)?$/.test(url.pathname);
        res.writeHead(conflict ? 409 : failSave ? 422 : 200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: !failSave && !conflict, message: conflict ? 'This exception changed. Refresh and review before retrying.' : failSave ? 'Choose a different slot or correct the form.' : 'Saved successfully.',
          redirect: origin + redirect, csrf: { name: 'csrf_test_name', hash: 'refreshed-token' } }));
      });
      return;
    }
    if (url.pathname === '/api/patients/search') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ results: [{ id: 11, first_name: 'Ada', last_name: 'Student', mis_id: 'S001', kind: 'student' }] }));
      return;
    }
    if (url.pathname === '/api/appointments/slots') {
      apiCalls.push(url);
      if (failSlots) {
        res.writeHead(503, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ message: 'Availability is temporarily unavailable. Please try again.' }));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      const date = url.searchParams.get('date');
      res.end(JSON.stringify({ slots: [{ starts_at: date + ' 09:00:00', ends_at: date + ' 09:20:00', slot_time: '09:00', available: true }] }));
      return;
    }
    const form = cases.find(item => item.url === url.pathname);
    const parents = { '/admin/carousel': 'manager', '/clinic/appointments': 'clinic-list', '/guidance/appointments': 'guidance-list', '/clinic/referrals': 'referral-list', '/guidance/referrals': 'referral-list', '/referrals': 'referral-chooser', '/portal': 'portal' };
    if (form || parents[url.pathname]) {
      if (form && url.searchParams.has('fragment')) {
        if (loadGate) await loadGate.promise;
        if (res.destroyed) return;
        if (failLoad) { res.writeHead(403).end('Access denied'); return; }
      }
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(render(form ? form.kind : parents[url.pathname], url.pathname + url.search, url.searchParams.has('fragment')));
      return;
    }
    const dir = path.join(root,'public'), file = path.resolve(dir,'.' + url.pathname);
    if (!file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file,(error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      res.end(content);
    });
  });
  server.listen(0,'127.0.0.1'); await once(server,'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless:true, executablePath, timeout:20_000 });
}, { timeout:40_000 });

after(async () => {
  loadGate?.release(); saveGate?.release();
  try { if (browser) await browser.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
}, { timeout:30_000 });

async function pageFor(t, parent, theme = 'light', options = {}) {
  failSave = false; failLoad = false; failSlots = false; exceptionConflict = false;
  const context = await browser.newContext({ viewport:options.viewport || {width:1280,height:900} });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode',theme === 'dark' ? '1' : '0'),theme);
  if (options.initScript) await context.addInitScript(options.initScript);
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType:'text/css', body:'' }));

  const page = await context.newPage(); page.setDefaultTimeout(6000);
  const errors = [];
  page.on('pageerror',error => errors.push(error.message));
  page.on('console',message => {
    if ((message.type() === 'error' && !message.text().includes('Failed to load resource')) ||
        message.text().includes('Alpine Expression Error')) errors.push(message.text());
  });
  t.after(async () => { await context.close(); assert.deepEqual(errors,[]); });
  await page.goto(origin + parent);
  return page;
}
async function open(page,item) {
  await page.locator(`a[data-app-modal-open][href="${origin + item.url}"]`).click();
  await page.waitForFunction(title => {
    const modal = document.querySelector('#appFormModal');
    return modal?.querySelector('form') && modal.querySelector('#appFormModalTitle').textContent.trim() === title &&
      modal.contains(document.activeElement) && modal.getAttribute('aria-modal') === 'true' &&
      bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  },item.title);
}
async function assertWorkspace(page) {
  await page.locator('#availability-workspace[data-availability-workspace]').waitFor();
  assert.equal(await page.locator('#appFormModal, [data-app-form-modal]').count(), 0);
  assert.equal(await page.locator('form[data-availability-save][data-modal-submit]').count(), 0);
}
function availabilityBusinessFields(values) {
  return Object.fromEntries(Object.entries(values).filter(([name]) =>
    !['return_q', 'return_provider', 'return_day', 'return_section', 'workspace_form', 'csrf_test_name'].includes(name)));
}
async function assertAvailabilityContext(form, workspaceForm, section, q = '', provider = '', day = '1') {
  for (const [name, value] of Object.entries({workspace_form:workspaceForm, return_q:q,
    return_provider:provider, return_day:day, return_section:section})) {
    assert.equal(await form.locator(`input[type="hidden"][name="${name}"]`).inputValue(), value);
  }
}
async function chooseScheduleTime(form, id, time) {
  const [hour, minute] = time.split(':');
  const input = form.locator('#' + id);
  assert.equal(await input.getAttribute('type'), 'text');
  assert.equal(await input.getAttribute('data-target'), '#' + id + 'Value');
  assert.equal(await input.getAttribute('data-timepicker'), '');
  await input.click();
  const popup = form.page().locator('.dtp-popup.dtp-time-only:visible');
  await popup.waitFor();
  const period = Number(hour) >= 12 ? 'PM' : 'AM';
  for (const [wheel, value] of [['hours', String(Number(hour) % 12 || 12)],
    ['minutes', String(Number(minute))], ['ampm', period]]) {
    await popup.locator(`.dtp-${wheel}-wheel button[data-val="${value}"]`).click();
  }
  await popup.getByRole('button', {name:'Apply', exact:true}).click();
  await popup.waitFor({state:'hidden'});
  assert.equal(await input.inputValue(), `${String(Number(hour) % 12 || 12).padStart(2, '0')}:${minute} ${period}`);
  assert.equal(await form.locator('#' + id + 'Value').inputValue(), time);
}
async function availabilityFailure(form) {
  const feedback = form.locator('[data-availability-feedback][role="alert"]');
  await feedback.waitFor({state:'visible'});
  assert.match(await feedback.innerText(), /Choose a different slot or correct the form/);
  assert.equal(posts.at(-1).requestedWith, 'XMLHttpRequest');
}
async function availabilityReload(page, item, section, provider = '', day = '1', q = '') {
  await page.waitForURL(url => url.pathname === item.url && url.searchParams.get('section') === section &&
    url.searchParams.get('provider') === provider && url.searchParams.get('day') === day && url.searchParams.get('q') === q);
  await assertWorkspace(page);
}
async function close(page, button) {
  await page.locator(button).click();
  await page.waitForFunction(() => !document.querySelector('#appFormModal'));
}
async function choosePatient(page) {
  await page.locator('#appFormModal input[placeholder^="Search Student"]').fill('Ada');
  await page.locator('#appFormModal .list-group button').filter({hasText:'Student, Ada'}).click();
}
async function chooseDate(page) {
  await page.locator('#bookingDate').click();
  await page.locator('#appFormModal .dp-popup:not([hidden])').waitFor();
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('Enter');
  assert.match(await page.locator('#bookingDate').inputValue(), /^\d{2}\/\d{2}\/\d{4}$/);
  const date = await page.locator('#bookingDate_iso').inputValue();
  assert.match(date, /^\d{4}-\d{2}-\d{2}$/);
  return date;
}
async function assertRequestFields(page, unit, action) {
  assert.equal(await page.locator('#appFormModal [x-data="appointmentRequest($el.dataset)"]').getAttribute('data-unit'), unit);
  assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + action);
  assert.equal(await page.locator('#bookingDate[data-datepicker]').count(), 1);
  assert.equal(await page.locator('#bookingDate').getAttribute('data-min'),
    await page.locator('#appFormModal [data-initial-date]').getAttribute('data-initial-date'),
    'The date minimum must use the server calendar, not browser-local today');
  assert.equal(await page.locator('#bookingDate_iso').getAttribute('x-model'), 'date');
  assert.equal(await page.locator('#preferredTime[type="text"][data-timepicker][required]').count(), 1);
  assert.equal(await page.locator('#preferredTime').getAttribute('aria-describedby'), 'preferredTimeHelp');
  assert.equal(await page.locator('#preferredTimeValue[type="hidden"][name="preferred_time"]').count(), 1);
  assert.deepEqual(await page.locator('#type option').evaluateAll(options => options.map(option => option.value).filter(Boolean)),
    [unit === 'clinic' ? 'routine' : 'initial', 'follow_up', 'referral_based']);
  assert.equal(await page.locator('textarea[name="reason"][maxlength="255"]').count(), 1);
  assert.equal(await page.locator('#appFormModal [name="staff_assignment_id"], #appFormModal [name="starts_at"], #appFormModal [name="ends_at"]').count(), 0);
  assert.equal(await page.locator('#appFormModal [data-slots-url]').count(), 0);
  assert.equal((await page.locator('#appFormModal button[type="submit"]').innerText()).trim(), 'Submit Request');
}

// Observe the real restoration script from document start, before deferred shell code.
function observeSidebarScroll() {
  window.sidebarProbe = { smooth: [], restored: null, samples: [], initialSaved:sessionStorage.getItem('synapse-sidebar-scroll') };
  for (const method of ['scroll', 'scrollTo', 'scrollBy', 'scrollIntoView']) {
    const original = Element.prototype[method];
    Element.prototype[method] = function(...args) {
      if ((this.matches('.sidebar-nav') || this.closest('.sidebar-nav')) &&
          args.some(arg => arg && arg.behavior === 'smooth')) window.sidebarProbe.smooth.push(method);
      return original.apply(this, args);
    };
  }
  const observer = new MutationObserver(() => {
    const nav = document.querySelector('.sidebar-nav[data-scroll-restored]');
    if (!nav) return;
    observer.disconnect();
    const active = nav.querySelector('.nav-link.active');
    const bounds = nav.getBoundingClientRect(), link = active.getBoundingClientRect();
    window.sidebarProbe.restored = {
      top: nav.scrollTop,
      activeVisible: link.top >= bounds.top && link.bottom <= bounds.top + nav.clientHeight,
      readyState: document.readyState
    };
    const sample = () => {
      window.sidebarProbe.samples.push(nav.scrollTop);
      if (window.sidebarProbe.samples.length < 120) requestAnimationFrame(sample);
    };
    sample();
  });
  observer.observe(document, { childList:true, subtree:true, attributes:true, attributeFilter:['data-scroll-restored'] });
}

async function assertSidebarSteady(page, expectedTop) {
  await page.waitForFunction(() => window.sidebarProbe.restored &&
    document.readyState === 'complete' && typeof window.SynapseConfirm !== 'undefined');
  // Cover late shell initialization and the duration of an accidental smooth scroll.
  await page.waitForTimeout(700);
  const state = await page.evaluate(() => ({ ...window.sidebarProbe,
    top: document.querySelector('.sidebar-nav').scrollTop }));
  assert.equal(state.restored.readyState, 'loading', 'Restoration must happen synchronously during parsing');
  assert.equal(state.restored.activeVisible, true, 'Active link must already be visible at restoration');
  assert.deepEqual(state.smooth, [], 'Sidebar must never request smooth scrolling');
  const top = expectedTop === undefined ? state.restored.top : expectedTop;
  assert.ok(Math.abs(state.restored.top - top) <= 1, `Initial scroll ${state.restored.top}, expected ${top}`);
  assert.ok(Math.abs(state.top - top) <= 1, `Final scroll ${state.top}, expected ${top}`);
  assert.ok(state.samples.length > 1, 'Must sample restoration across animation frames');
    assert.ok(state.samples.every(value => Math.abs(value - top) <= 1), 'Sidebar must not drift after restoration');
}

for (const [basePath, indexPage] of [['/', 'index.php'], ['/humus/public/', 'index.php'], ['/humus/public/', '']]) {
  test(`sidebar activation excludes installation path ${basePath} and index ${indexPage}`, () => {
    for (const route of ['/clinic/appointments', '/guidance/appointments/create', '/clinic/referrals']) {
      const result = spawnSync('php', ['tests/_support/render-modal-fixture.php'], {
        cwd: root,
        input: JSON.stringify({ origin, path: route, kind: 'clinic', fragment: false, basePath, indexPage }),
        encoding: 'utf8', timeout: 10_000
      });
      assert.equal(result.status, 0, result.stderr);
      const sidebar = result.stdout.match(/<nav class="sidebar-nav">([\s\S]*?)<\/nav>/)?.[1];
      assert.ok(sidebar, 'Actual sidebar must render');
      const links = [...sidebar.matchAll(/<a class="nav-link active" href="([^"]+)"/g)].map(match => match[1].replace(/&amp;/g, '&'));
      const expected = route.replace('/create', '');
      assert.deepEqual(links, [origin + basePath + (indexPage ? indexPage + '/' : '') + expected.slice(1)]);
    }
  });
}

for (const theme of ['light','dark']) {
  test(`${theme} sidebar: active background and text persist without hover`, {timeout:20_000}, async t => {
    const page = await pageFor(t, '/clinic/appointments', theme);
    await page.mouse.move(1200, 850);
    await page.waitForFunction(() => document.readyState === 'complete' && typeof window.SynapseConfirm !== 'undefined');
    const colors = await page.locator('.sidebar-nav .nav-link.active').evaluate(link => {
      const nav = link.closest('.sidebar-nav');
      const style = getComputedStyle(link);
      // Resolve custom properties through the browser rather than hard-coding either palette.
      const probe = document.createElement('span');
      probe.style.backgroundColor = 'var(--sidebar-active-bg)';
      probe.style.color = 'var(--sidebar-active-color)';
      nav.append(probe);
      const expected = getComputedStyle(probe);
      const result = { hovered: link.matches(':hover'), background: style.backgroundColor, color: style.color,
        expectedBackground: expected.backgroundColor, expectedColor: expected.color,
        sidebarBackground: getComputedStyle(link.closest('.sidebar')).backgroundColor };
      probe.remove();
      return result;
    });
    assert.equal(colors.hovered, false);
    assert.notEqual(colors.expectedBackground, 'rgba(0, 0, 0, 0)');
    assert.equal(colors.background, colors.expectedBackground);
    assert.equal(colors.color, colors.expectedColor);
    assert.notEqual(colors.background, colors.sidebarBackground, 'Active item must visibly stand out from the sidebar');
  });

  test(`${theme} sidebar: instant reveal, link navigation and reload retain visible scroll`, {timeout:25_000}, async t => {
    const page = await pageFor(t, '/guidance/appointments', theme, {
      viewport:{width:1280,height:480}, initScript:observeSidebarScroll
    });
    assert.equal(await page.evaluate(() => window.sidebarProbe.initialSaved), null,
      'Fresh context must exercise reveal without a saved position');
    await assertSidebarSteady(page);
    const position = await page.locator('.sidebar-nav').evaluate(nav => {
      const active = nav.querySelector('.nav-link.active');
      const target = nav.querySelector('a[href$="/guidance/referrals"]');
      const bounds = nav.getBoundingClientRect();
      const originalTop = active.getBoundingClientRect().top - bounds.top + nav.scrollTop;
      const overflow = nav.scrollHeight > nav.clientHeight;
      nav.scrollTop = originalTop - 40;
      const visible = link => {
        const rect = link.getBoundingClientRect();
        return rect.top >= bounds.top && rect.bottom <= bounds.top + nav.clientHeight;
      };
      return { top:nav.scrollTop, overflow, initiallyOffscreen:originalTop + active.offsetHeight > nav.clientHeight,
              activeVisible:visible(active), targetVisible:visible(target) };
    });
    assert.equal(position.overflow, true, 'Real superadmin sidebar must overflow');
    assert.equal(position.initiallyOffscreen, true, 'Active link starts offscreen without restoration');
    assert.ok(position.top > 0);
    assert.equal(position.activeVisible, true);
    assert.equal(position.targetVisible, true, 'Navigation target must be visible without Playwright scrolling it');
    await page.waitForFunction(top => Math.abs(Number(sessionStorage.getItem('synapse-sidebar-scroll')) - top) <= 1, position.top);
    await page.evaluate(() => { window.persistentSidebar = document.querySelector('.sidebar-nav'); });
    await Promise.all([
      page.waitForURL(origin + '/guidance/referrals'),
      page.locator('.sidebar-nav a[href$="/guidance/referrals"]').click()
    ]);
    await page.waitForFunction(() => !document.body.classList.contains('is-navigating'));
    assert.equal(await page.locator('.sidebar-nav .nav-link.active').getAttribute('href'), origin + '/guidance/referrals');
    const swapped = await page.evaluate(async () => {
      const nav = document.querySelector('.sidebar-nav');
      const samples = [];
      for (let i = 0; i < 12; i++) {
        await new Promise(resolve => requestAnimationFrame(resolve));
        samples.push(nav.scrollTop);
      }
      return { same: nav === window.persistentSidebar, samples, smooth: window.sidebarProbe.smooth };
    });
    assert.equal(swapped.same, true, 'Partial navigation must retain the actual sidebar');
    assert.deepEqual(swapped.smooth, []);
    assert.ok(swapped.samples.every(top => Math.abs(top - position.top) <= 1));
    await page.reload();
    await assertSidebarSteady(page, position.top);
    assert.ok(Math.abs(Number(await page.evaluate(() => sessionStorage.getItem('synapse-sidebar-scroll'))) - position.top) <= 1);
  });

  for (const [route, expected] of [
    ['/clinic/appointments', '/clinic/appointments'],
    ['/guidance/appointments', '/guidance/appointments'],
    ['/clinic/appointments/create', '/clinic/appointments'],
    ['/guidance/appointments/create', '/guidance/appointments'],
    ['/clinic/referrals', '/clinic/referrals'],
    ['/guidance/referrals', '/guidance/referrals'],
    ['/referrals', null],
    ['/referrals?target=invalid', null]
  ]) {
    test(`${theme} superadmin sidebar: only ${expected} is active on ${route}`, {timeout:20_000}, async t => {
      const page = await pageFor(t, route, theme);
      // Wait past deferred shell initialization, where the duplicate used to appear.
      await page.waitForFunction(() => document.readyState === 'complete' && typeof window.SynapseConfirm !== 'undefined');
      const links = await page.locator('.sidebar-nav .nav-link.active').evaluateAll(nodes =>
        nodes.map(node => { const url = new URL(node.href); return url.pathname + url.search; }));
      assert.deepEqual(links, expected ? [expected] : []);
      const siblings = await page.locator('.sidebar-nav .nav-link').evaluateAll(nodes =>
        nodes.filter(node => node.textContent.trim() === 'Appointments').length);
      assert.equal(siblings, 2, 'Superadmin fixture must expose both departments');
    });
  }
  for (const item of cases.filter(item => !item.kind.endsWith('-availability'))) {
    test(`${theme} ${item.kind}: real overlay, static backdrop, Escape ignored, X/Cancel preserve the list`, {timeout:20_000},async t => {
      const page = await pageFor(t,item.parent,theme);
      await page.evaluate(() => { window.originalMain = document.querySelector('#main'); });
      const before = page.url();
      await open(page,item);
      assert.equal(page.url(),before);
      assert.deepEqual(await page.locator('#appFormModal').evaluate(root => ({
        exists: !!bootstrap.Modal.getInstance(root), backdrop: bootstrap.Modal.getInstance(root)._config.backdrop,
        keyboard: bootstrap.Modal.getInstance(root)._config.keyboard,
        preserved: document.querySelector('#main') === window.originalMain,
        forms: root.querySelectorAll('form').length
      })),{exists:true,backdrop:'static',keyboard:false,preserved:true,forms:1});
      if (item.kind === 'edit') assert.equal(await page.locator('#slideForm input[name="title"]').inputValue(),'Existing Slide');
      await page.keyboard.press('Escape');
      await page.mouse.click(5,5);
      assert.equal(await page.locator('#appFormModal').isVisible(),true);
      for (let step = 0; step < 10; step++) {
        await page.keyboard.press('Tab');
        const focused = await page.locator('#appFormModal').evaluate(el => ({ inside: el.contains(document.activeElement), html: document.activeElement.outerHTML.slice(0,150), trap: bootstrap.Modal.getInstance(el)._focustrap._isActive }));
        assert.equal(focused.inside,true, `${theme} ${item.kind} Tab ${step}: ${JSON.stringify(focused)}`);
      }
      const contrast = await page.locator('#appFormModal a[data-app-modal-close]').evaluate(el => {
        const rgb = color => color.match(/[\d.]+/g).slice(0,3).map(Number);
        const luminance = values => values.map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055)/1.055)**2.4; })
          .reduce((sum,v,i) => sum + v * [.2126,.7152,.0722][i],0);
        let parent = el;
        while (getComputedStyle(parent).backgroundColor === 'rgba(0, 0, 0, 0)') parent = parent.parentElement;
        const a = luminance(rgb(getComputedStyle(el).color)), b = luminance(rgb(getComputedStyle(parent).backgroundColor));
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
      });
      assert.ok(contrast >= 4.5, `Cancel contrast: ${contrast}`);
      await close(page,'#appFormModal button[data-app-modal-close]');
      assert.equal(page.url(),before);
      await open(page,item);
      await close(page,'#appFormModal a[data-app-modal-close]');
      assert.equal(page.url(),before);
      assert.equal(await page.evaluate(() => document.querySelector('#main') === window.originalMain),true);
    });
  }
}

for (const kind of ['clinic','guidance','referral']) {
  test(`${kind}: actual Alpine form initializes, failed save retains entries, retry sends refreshed CSRF`,{timeout:20_000},async t => {
    const item = cases.find(item => item.kind === kind), page = await pageFor(t,item.parent);
    await open(page,item); await choosePatient(page);
    const slotsBefore = apiCalls.length;
    let date;
    if (kind !== 'referral') {
      await assertRequestFields(page, kind, item.url.replace('/create', '/store'));
      date = await chooseDate(page);
      await chooseScheduleTime(page.locator('#appFormModal form'), 'preferredTime', '09:00');
      await page.locator('#preferredTime').fill('25:00');
      await page.locator('#preferredTime').blur();
      assert.equal(await page.locator('[name="preferred_time"]').inputValue(), '', 'Invalid edits must clear the previous submitted time');
      const invalidBefore = posts.length;
      await page.locator('#appFormModal button[type="submit"]').click();
      assert.equal(posts.length, invalidBefore, 'Invalid time must not submit a request');
      assert.equal(await page.locator('#appFormModal form').evaluate(form => form.checkValidity()), false);
      await chooseScheduleTime(page.locator('#appFormModal form'), 'preferredTime', '09:00');
      await page.locator('#reason').fill('Keep this reason on error');
      assert.equal(await page.locator('#appFormModal form').evaluate(form => form.checkValidity()), true);
      assert.equal(await page.locator('#appFormModal button[type="submit"]').isEnabled(), true);
    } else {
      await page.locator('#reason_code').selectOption('anxiety_stress');
      await page.locator('#reason_description').fill('Keep these referral notes on error');
    }
    const before = posts.length;
    failSave = true;
    await page.locator('#appFormModal button[type="submit"]').click();
    await page.locator('[data-modal-error]:not(.d-none)').waitFor();
    assert.equal(posts.length,before + 1);
    assert.equal(posts.at(-1).fields[kind === 'referral' ? 'patient_person_id' : 'person_id'],'11');
    const submitted = posts.at(-1);
    if (kind !== 'referral') {
      assert.equal(submitted.path, '/' + kind + '/appointments/store');
      assert.equal(submitted.fields.date, date);
      assert.equal(submitted.fields.preferred_time, '09:00');
      assert.equal(submitted.fields.type, kind === 'clinic' ? 'routine' : 'initial');
      for (const name of ['staff_assignment_id', 'starts_at', 'ends_at']) assert.equal(Object.hasOwn(submitted.fields, name), false);
      assert.equal(await page.locator('#bookingDate_iso').inputValue(), date);
      assert.equal(await page.locator('[name="preferred_time"]').inputValue(), '09:00');
            assert.equal(await page.locator('#preferredTime').inputValue(), '09:00 AM');
    }
    assert.equal(await page.locator(kind === 'referral' ? '#reason_description' : '#reason').inputValue(),kind === 'referral' ? 'Keep these referral notes on error' : 'Keep this reason on error');
    assert.equal(await page.locator('#appFormModal').isVisible(),true);
    failSave = false;
    await page.locator('#appFormModal button[type="submit"]').click();
    await page.waitForURL(origin + item.parent);
    assert.equal(posts.length,before + 2);
    assert.equal(posts.at(-1).fields.csrf_test_name,'refreshed-token');
    if (kind !== 'referral') {
      assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name: 'refreshed-token'});
      assert.equal(apiCalls.length, slotsBefore, 'Requests must not fetch provider availability');
    }
  });
}

for (const unit of ['clinic', 'guidance']) {
  test(`${unit} confirmation: eligible provider and slot selection survive validation failure and retry`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-confirm'), page = await pageFor(t, item.parent);
    await open(page, item);
    assert.equal(await page.locator('#appFormModal [x-data="appointmentBooking($el.dataset)"]').getAttribute('data-unit'), unit);
    assert.equal(await page.locator('#appFormModal form').getAttribute('action'), origin + item.url);
    assert.match(await page.locator('#appFormModal').innerText(), /Ada/);
    assert.equal(await page.locator('#appFormModal input[placeholder^="Search Student"]').count(), 0);
    assert.equal(await page.locator('#staff_assignment_id').getAttribute('required'), '');
    assert.deepEqual(await page.locator('#staff_assignment_id option').evaluateAll(options =>
      options.filter(option => option.value).map(option => ({value: option.value, text: option.textContent.trim()}))),
      [{value: '7', text: `Provider Fixture (${unit === 'clinic' ? 'Clinic Staff' : 'Counsellor'})`}]);
    assert.equal(await page.locator('[name="version"][type="hidden"]').inputValue(), '1');
    assert.equal(await page.locator('[name="starts_at"][type="hidden"], [name="ends_at"][type="hidden"]').count(), 2);
    assert.equal(await page.locator('#bookingDate[data-datepicker]').count(), 1);
    assert.match(await page.locator('#bookingDate').getAttribute('data-min'), /^\d{4}-\d{2}-\d{2}$/);
    assert.equal(await page.locator('#bookingDate_iso').getAttribute('x-model'), 'date');
    await page.locator('#staff_assignment_id').locator('..').locator('.bd-select-toggle').click();
    await page.locator('#staff_assignment_id').locator('..').getByRole('option', {name:/Provider Fixture/}).click();
    const date = await chooseDate(page);
    await page.locator('#appFormModal button').filter({hasText:'09:00'}).click();
    assert.equal(apiCalls.at(-1).searchParams.get('unit'), unit);
    assert.equal(apiCalls.at(-1).searchParams.get('staff_assignment_id'), '7');
    assert.equal(apiCalls.at(-1).searchParams.get('date'), date);
    const before = posts.length;
    failSave = true;
    await page.locator('#appFormModal button[type="submit"]').click();
    await page.locator('[data-modal-error]:not(.d-none)').waitFor();
    assert.equal(posts.length, before + 1);
    const submitted = posts.at(-1);
    assert.equal(submitted.path, item.url);
    assert.equal(submitted.fields.staff_assignment_id, '7');
    assert.equal(submitted.fields.starts_at, date + ' 09:00:00');
    assert.equal(submitted.fields.ends_at, date + ' 09:20:00');
    assert.equal(submitted.fields.version, '1');
    assert.equal(await page.locator('#staff_assignment_id').inputValue(), '7');
    assert.equal(await page.locator('#bookingDate_iso').inputValue(), date);
    assert.equal(await page.locator('[name="starts_at"]').inputValue(), submitted.fields.starts_at);
    assert.equal(await page.locator('[name="ends_at"]').inputValue(), submitted.fields.ends_at);
    assert.equal(await page.locator('#appFormModal').isVisible(), true);
    failSave = false;
    await page.locator('#appFormModal button[type="submit"]').click();
    await page.waitForURL(origin + item.parent);
    assert.equal(posts.length, before + 2);
    assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name: 'refreshed-token'});
  });
}

for (const unit of ['clinic', 'guidance']) {
  test(`${unit} availability: page action navigates to workspace and section links stay full-page`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-availability');
    const page = await pageFor(t, item.parent);
    const link = page.locator(`a[href="${origin + item.url}"]`).filter({hasText:'Staff Availability'});
    assert.equal(await link.getAttribute('data-app-modal-open'), null);
    await link.click();
    await page.waitForURL(origin + item.url);
    await assertWorkspace(page);
    assert.equal(await page.locator('[data-configured-windows]').isVisible(), true);
    for (const [section, label] of [['enrollment', 'Department setup'], ['exceptions', 'Leave & exceptions'], ['weekly', 'Weekly schedules']]) {
      const tab = page.locator('.av-section-nav').getByRole('link', {name:label, exact:true});
      assert.equal(await tab.getAttribute('data-app-modal-open'), null);
      assert.equal(new URL(await tab.getAttribute('href'), page.url()).searchParams.get('section'), section);
      await tab.click();
      await page.waitForURL(url => url.pathname === item.url && url.searchParams.get('section') === section);
      await assertWorkspace(page);
    }
  });
  test(`${unit} enrollment: native full-page submission reloads the same workspace context`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-availability');
    const page = await pageFor(t, item.url + '?section=enrollment&q=Employee&day=4');
    await assertWorkspace(page);
    await assertAvailabilityContext(page.locator('#departmentEnrollment'), 'enrollment', 'enrollment', 'Employee', '', '4');
    await page.locator('[data-department-staff]').getByRole('checkbox', {name:'Enroll Pending Employee', exact:true}).check();
    const before = posts.length;
    await page.locator('#departmentEnrollment').evaluate(form => HTMLFormElement.prototype.submit.call(form));
    await availabilityReload(page, item, 'enrollment', '', '4', 'Employee');
    assert.equal(posts.length, before + 1);
    assert.equal(posts.at(-1).requestedWith, undefined);
    assert.equal(posts.at(-1).fields['person_ids[]'], '71');
  });
  for (const theme of ['light', 'dark']) {
    test(`${theme} ${unit} enrollment: select MIS employees without accounts, retain retry and keep hours separate`, {timeout:25_000}, async t => {
      const item = cases.find(item => item.kind === unit + '-availability');
      const page = await pageFor(t, item.url + '?section=enrollment', theme);
      await assertWorkspace(page);
      const roster = page.locator('#availability-workspace table[data-department-staff]');
      const form = page.locator('#departmentEnrollment[data-department-enrollment][data-availability-save]');
      assert.equal(await roster.locator('[name="person_ids[]"]').evaluateAll(inputs => inputs.every(input => input.form?.id === 'departmentEnrollment')), true);
      await assertAvailabilityContext(form, 'enrollment', 'enrollment');
      const button = form.locator('[type="submit"]');
      assert.equal(await button.isDisabled(), true);
      assert.equal(await roster.getByRole('checkbox', {name:'Enroll Provider Fixture',exact:true}).isDisabled(), true);
      const all = roster.getByRole('checkbox', {name:'Select all unenrolled staff',exact:true});
      await all.check();
      assert.equal(await roster.locator('[name="person_ids[]"]:checked').count(), 2);
      assert.equal(await roster.locator('[name="staff_assignment_ids[]"]:checked').count(), 0);
      await roster.getByRole('checkbox', {name:'Enroll New MIS Employee',exact:true}).uncheck();
      assert.equal(await all.evaluate(input => input.indeterminate), true);
      assert.equal(await button.innerText(), 'Enroll 1 Selected Employees');
      const before = posts.length;
      failSave = true;
      await button.click();
      await availabilityFailure(form);
      assert.equal(posts.length, before + 1);
      const submitted = posts.at(-1);
      assert.equal(submitted.path, item.url + '/enroll');
      assert.deepEqual(availabilityBusinessFields(submitted.fields), {'person_ids[]':'71'});
      assert.equal(submitted.fields['person_ids[]'], '71');
      assert.equal('staff_assignment_ids[]' in submitted.fields, false);
      assert.equal('days[]' in submitted.fields, false);
      assert.equal('start_time' in submitted.fields, false);
      assert.equal(await roster.getByRole('checkbox', {name:'Enroll Pending Employee',exact:true}).isChecked(), true);
      failSave = false;
      await button.click();
      await availabilityReload(page, item, 'enrollment');
      assert.equal(posts.length, before + 2);
      assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name:'refreshed-token'});
    });
    test(`${theme} ${unit} department template: roster, retained retry, isolated form and CSRF`, {timeout:25_000}, async t => {
      const item = cases.find(item => item.kind === unit + '-availability');
      const page = await pageFor(t, item.url + '?section=enrollment', theme);
      await assertWorkspace(page);
      const roster = page.locator('#availability-workspace table[data-department-staff]');
      assert.equal(await roster.locator('[name="staff_assignment_ids[]"]').evaluateAll(inputs => inputs.every(input => input.form?.id === 'departmentTemplate')), true);
      assert.match(await roster.innerText(), /Provider Fixture/);
      assert.deepEqual(await roster.locator('tbody tr td:nth-child(4)').allTextContents(),
        Array(4).fill(unit === 'clinic' ? 'Health Services' : 'Guidance'));
      assert.match(await roster.innerText(), /Stored <position>/);
      assert.match(await roster.innerText(), /Not enrolled for scheduling/);
      assert.equal(await roster.locator('script, position').count(), 0);
      const form = page.locator('#departmentTemplate[data-department-template][data-availability-save]');
      await assertAvailabilityContext(form, 'template', 'enrollment');
      assert.equal(await form.getAttribute('action'), origin + item.url + '/template');
      assert.equal(await form.locator('input[type="checkbox"]:checked').count(), 0);
      assert.equal(await form.locator('[name="start_time"]').inputValue(), '');
      assert.equal(await form.locator('button[type="submit"]').isDisabled(), true);
      assert.equal(await roster.getByRole('checkbox', {name: 'Select Pending Employee', exact: true}).isDisabled(), true);
      const selectAll = roster.getByRole('checkbox', {name: 'Select all eligible staff', exact: true});
      await selectAll.check();
      assert.equal(await roster.locator('tbody input:checked').count(), 2);
      await roster.getByRole('checkbox', {name: 'Select New Staff Fixture', exact: true}).uncheck();
      assert.equal(await selectAll.evaluate(input => input.indeterminate), true);
      assert.equal(await form.locator('button[type="submit"]').innerText(), 'Apply to 1 Selected Staff');
      await selectAll.check();
      await selectAll.uncheck();
      assert.equal(await roster.locator('tbody input:checked').count(), 0);
      assert.equal(await form.locator('button[type="submit"]').isDisabled(), true);
      await roster.getByRole('checkbox', {name: 'Select Provider Fixture', exact: true}).check();
      await form.locator('#departmentDayTuesday').check();
      await chooseScheduleTime(form, 'departmentStart', '09:00');
      await chooseScheduleTime(form, 'departmentEnd', '12:00');
      const before = posts.length;
      failSave = true;
      await form.locator('button[type="submit"]').click();
      await availabilityFailure(form);
      assert.equal(posts.length, before + 1);
      const submitted = posts.at(-1);
      assert.equal(submitted.path, item.url + '/template');
      assert.equal(submitted.fields['days[]'], 'Tuesday');
      assert.equal(submitted.fields['staff_assignment_ids[]'], '7');
      assert.equal(await roster.getByRole('checkbox', {name: 'Select Provider Fixture', exact: true}).isChecked(), true);
      assert.equal(await roster.getByRole('checkbox', {name: 'Select New Staff Fixture', exact: true}).isChecked(), false);
      assert.equal(submitted.fields.start_time, '09:00');
      assert.equal(submitted.fields.end_time, '12:00');
      assert.equal(submitted.fields.slot_duration_minutes, '30');
      assert.equal('staff_assignment_id' in submitted.fields, false);
      assert.equal(await form.locator('#departmentDayTuesday').isChecked(), true);
      assert.equal(await form.locator('[name="start_time"]').inputValue(), '09:00');
      assert.equal(await form.locator('[name="end_time"]').inputValue(), '12:00');
      assert.equal(await page.locator('[data-individual-availability]').count(), 0);
      assert.deepEqual(availabilityBusinessFields(submitted.fields), {
        'days[]':'Tuesday', 'staff_assignment_ids[]':'7', start_time:'09:00', end_time:'12:00', slot_duration_minutes:'30'
      });
      failSave = false;
      await form.locator('button[type="submit"]').click();
      await availabilityReload(page, item, 'enrollment');
      assert.equal(posts.length, before + 2);
      assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name: 'refreshed-token'});
    });
  }
  test(`${unit} availability: configured window and form fields survive validation failure and retry`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-availability'), page = await pageFor(t, item.parent);
    await page.goto(origin + item.url + '?section=weekly&q=Provider&provider=7&day=1');
    await assertWorkspace(page);
    const trigger = page.locator('button[type="button"][data-bs-target="#availability-individual-modal"]');
    assert.equal(await trigger.getAttribute('data-app-modal-open'), null);
    await trigger.click();
    const modal = page.locator('#availability-individual-modal');
    await page.waitForFunction(() => {
      const modal = document.querySelector('#availability-individual-modal');
      return modal?.contains(document.activeElement) && bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
    });
    const form = modal.locator('form[data-individual-availability][data-availability-save]');
    assert.equal(await form.getAttribute('action'), origin + item.url);
    assert.equal(await form.getAttribute('method'), 'POST');
    await assertAvailabilityContext(form, 'individual', 'weekly', 'Provider', '7', '1');
    assert.deepEqual(await form.locator('select[name], input[name="start_time"], input[name="end_time"]').evaluateAll(inputs => inputs.map(input => input.name)),
      ['staff_assignment_id', 'day_of_week', 'start_time', 'end_time', 'slot_duration_minutes', 'is_active']);
    assert.equal(await form.locator('input[type="text"][data-timepicker][required]').count(), 2);
    assert.equal(await form.locator('input[type="hidden"][name="start_time"], input[type="hidden"][name="end_time"]').count(), 2);
    assert.equal(await form.locator('#availabilityStart').inputValue(), '08:00 AM');
    assert.equal(await form.locator('#availabilityEnd').inputValue(), '05:00 PM');
    assert.deepEqual(await form.locator('#availabilityProvider option').evaluateAll(options =>
      options.filter(option => option.value).map(option => ({value: option.value, text: option.textContent.trim()}))),
      [{value: '7', text: 'Provider Fixture'}]);
    assert.deepEqual(await form.locator('#availabilityDay option').evaluateAll(options => options.map(option => option.value)),
      ['0', '1', '2', '3', '4', '5', '6']);
    assert.deepEqual(await form.locator('#availabilityDuration option').evaluateAll(options => options.map(option => option.value)),
      ['15', '30', '45', '60']);
    assert.deepEqual(await form.locator('#availabilityActive option').evaluateAll(options => options.map(option => option.value)), ['1', '0']);
    assert.deepEqual(await page.locator('table[data-configured-windows] tbody tr td:nth-child(-n+5)').allTextContents(),
      ['Provider Fixture', 'Monday', '8:00 AM – 5:00 PM', '30 min', 'Active']);
    assert.equal(await form.locator('[data-configured-windows]').count(), 0);
    for (const [id, name] of [['availabilityProvider', 'Provider Fixture'], ['availabilityDay', 'Tuesday'],
      ['availabilityDuration', '45 minutes'], ['availabilityActive', 'Inactive']]) {
      const select = form.locator('#' + id).locator('..');
      await select.locator('.bd-select-toggle').click();
      await select.getByRole('option', {name, exact:true}).click();
    }
    await chooseScheduleTime(form, 'availabilityStart', '09:15');
    await chooseScheduleTime(form, 'availabilityEnd', '11:45');
    assert.equal(await form.evaluate(form => form.checkValidity()), true);
    const before = posts.length;
    failSave = true;
    await form.locator('button[type="submit"]').click();
    await availabilityFailure(form);
    assert.equal(posts.length, before + 1);
    const submitted = posts.at(-1);
    assert.equal(submitted.path, item.url);
    const values = {staff_assignment_id: '7', day_of_week: '2', start_time: '09:15', end_time: '11:45', slot_duration_minutes: '45', is_active: '0'};
    assert.deepEqual(availabilityBusinessFields(submitted.fields), values);
    for (const [name, value] of Object.entries(values)) {
      assert.equal(submitted.fields[name], value);
      assert.equal(await form.locator(`[name="${name}"]`).inputValue(), value);
    }
    assert.equal(await modal.isVisible(), true);
    failSave = false;
    await form.locator('button[type="submit"]').click();
    await availabilityReload(page, item, 'weekly', '7', '2', 'Provider');
    assert.equal(posts.length, before + 2);
    assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name: 'refreshed-token'});
  });

  test(`${unit} confirmation: slots API errors clear selection and recover on date retry`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-confirm'), page = await pageFor(t, item.parent);
    await open(page, item);
    await page.locator('#staff_assignment_id').locator('..').locator('.bd-select-toggle').click();
    await page.locator('#staff_assignment_id').locator('..').getByRole('option', {name:/Provider Fixture/}).click();
    const slot = page.locator('#appFormModal button').filter({hasText:'09:00'});
    await slot.click();
    assert.equal(await page.locator('#appFormModal button[type="submit"]').isEnabled(), true);
    const before = posts.length;
    failSlots = true;
    await chooseDate(page);
    const error = page.locator('#appFormModal [x-text="slotError"][role="alert"]');
    await error.waitFor({state:'visible'});
    assert.equal(await error.innerText(), 'Availability is temporarily unavailable. Please try again.');
    assert.equal(await slot.count(), 0);
    assert.equal(await page.locator('[name="starts_at"]').inputValue(), '');
    assert.equal(await page.locator('[name="ends_at"]').inputValue(), '');
    assert.equal(await page.locator('#appFormModal button[type="submit"]').isDisabled(), true);
    assert.equal(posts.length, before);
    failSlots = false;
    const date = await chooseDate(page);
    await slot.click();
    assert.equal(await error.isVisible(), false);
    assert.equal(await page.locator('[name="starts_at"]').inputValue(), date + ' 09:00:00');
    assert.equal(await page.locator('[name="ends_at"]').inputValue(), date + ' 09:20:00');
    assert.equal(await page.locator('#appFormModal button[type="submit"]').isEnabled(), true);
    assert.equal(apiCalls.at(-1).searchParams.get('unit'), unit);
    assert.equal(apiCalls.at(-1).searchParams.get('staff_assignment_id'), '7');
    assert.equal(apiCalls.at(-1).searchParams.get('date'), date);
    await close(page, '#appFormModal a[data-app-modal-close]');
    assert.equal(page.url(), origin + item.parent);
  });
}

test('Add/Edit uploads initialize per opening, keep previews on failure and release object URLs on Cancel',{timeout:20_000},async t => {
  const item = cases.find(item => item.kind === 'add'), page = await pageFor(t,item.parent);
  await page.evaluate(() => {
    window.revoked = 0;
    const revoke = URL.revokeObjectURL;
    URL.revokeObjectURL = url => { window.revoked++; revoke.call(URL,url); };
  });
  for (let i=0;i<2;i++) {
    await open(page,item);
    await page.locator('#light_file').setInputFiles(path.join(root,'public/assets/img/brand/fuel-bg.jpg'));
    await page.locator('.dz-preview:not(.d-none) img').waitFor();
    if (i === 0) {
      failSave = true;
      await page.locator('#slideSubmitBtn').click();
      await page.locator('#slideFormAlert.alert-danger').waitFor();
      assert.equal(await page.locator('#light_file').evaluate(input => input.files.length),1);
      assert.equal(await page.locator('#slideSubmitBtn').isEnabled(),true);
    }
    await close(page,'#appFormModal a[data-app-modal-close]');
  }
  assert.equal(await page.evaluate(() => window.revoked),2);
});

for (const unit of ['clinic', 'guidance']) {
  test(`${unit} personal request: signed-in person needs no search or person ID and returns to the portal`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === 'personal-' + unit), page = await pageFor(t, item.parent);
    const slotsBefore = apiCalls.length;
    await open(page, item);
    await assertRequestFields(page, unit, item.url.replace('/create', '/store'));
    assert.match(await page.locator('#appFormModal').innerText(), /Ada/);
    assert.match(await page.locator('#appFormModal').innerText(), /Student/);
    assert.equal(await page.locator('#appFormModal input[placeholder^="Search Student"]').count(), 0);
    assert.equal(await page.locator('#appFormModal input[name="person_id"]').count(), 0);
    await close(page, '#appFormModal a[data-app-modal-close]');
    assert.equal(page.url(), origin + '/portal');
    await open(page, item);
    const date = await chooseDate(page);
    await chooseScheduleTime(page.locator('#appFormModal form'), 'preferredTime', '09:00');
    await page.locator('#reason').fill('Personal request reason');
    const before = posts.length;
    failSave = true;
    await page.locator('#appFormModal button[type="submit"]').click();
    await page.locator('[data-modal-error]:not(.d-none)').waitFor();
    assert.equal(posts.length, before + 1);
    const submitted = posts.at(-1);
    assert.equal(submitted.path, '/portal/appointments/' + unit + '/store');
    assert.equal(submitted.fields.date, date);
    assert.equal(submitted.fields.preferred_time, '09:00');
    assert.equal(submitted.fields.type, unit === 'clinic' ? 'routine' : 'initial');
    assert.equal(submitted.fields.reason, 'Personal request reason');
    for (const name of ['person_id', 'staff_assignment_id', 'starts_at', 'ends_at']) assert.equal(Object.hasOwn(submitted.fields, name), false);
    assert.equal(await page.locator('#reason').inputValue(), 'Personal request reason');
    failSave = false;
    await page.locator('#appFormModal button[type="submit"]').click();
    await page.waitForURL(origin + '/portal');
    assert.equal(posts.length, before + 2);
    assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name: 'refreshed-token'});
    assert.equal(apiCalls.length, slotsBefore, 'Personal requests must not fetch provider availability');
  });
}

test('a failed load offers X/Cancel and can be retried without leaving the list',{timeout:15_000},async t => {
  const item = cases[0], page = await pageFor(t,item.parent);
  failLoad = true;
  await page.locator(`a[data-app-modal-open][href="${origin + item.url}"]`).click();
  await page.locator('[data-modal-error]:not(.d-none)').waitFor();
  await close(page,'#appFormModal button.btn-close[data-app-modal-close]');
  assert.equal(page.url(),origin + item.parent);
  failLoad = false;
  await open(page,item);
  await close(page,'#appFormModal a[data-app-modal-close]');
});

test('Cancel during loading aborts the fetch and prevents a late form from reopening',{timeout:15_000},async t => {
  const page = await pageFor(t,cases[0].parent);
  const held = gate(); loadGate = held;
  t.after(() => { held.release(); loadGate = null; });
  await page.locator(`a[data-app-modal-open][href="${origin + cases[0].url}"]`).click();
  await close(page,'#appFormModal button.btn-outline-secondary[data-app-modal-close]');
  held.release(); loadGate = null;
  await open(page,cases[0]);
  assert.equal(await page.locator('#appFormModal').count(),1);
  await close(page,'#appFormModal a[data-app-modal-close]');
});

test('an in-flight carousel save blocks dismissal and repeated submission until it completes',{timeout:20_000},async t => {
  const item = cases.find(item => item.kind === 'add'), page = await pageFor(t,item.parent);
  await open(page,item);
  await page.locator('#light_file').setInputFiles(path.join(root,'public/assets/img/brand/fuel-bg.jpg'));
  const held = gate(); saveGate = held;
  t.after(() => { held.release(); saveGate = null; });
  const before = posts.length;
  await page.locator('#slideSubmitBtn').click();
  await page.waitForFunction(() => document.querySelector('#slideForm').getAttribute('aria-busy') === 'true');
  await page.locator('#appFormModal button[data-app-modal-close]').click();
  await page.locator('#appFormModal a[data-app-modal-close]').click();
  await page.evaluate(() => document.querySelector('#slideForm').requestSubmit());
  assert.equal(await page.locator('#appFormModal').isVisible(),true);
  held.release(); saveGate = null;
  await page.waitForURL(origin + item.parent);
  assert.equal(posts.length,before + 1);
});

const exceptionPreviewDirectory = path.join(root, 'writable/availability-workspace-preview', 'exceptions-' + Date.now());
async function selectExceptionOption(form, id, name) {
  const wrapper = form.locator('#' + id).locator('..');
  await wrapper.locator('.bd-select-toggle').click();
  await wrapper.getByRole('option', {name, exact:true}).click();
}
async function openException(page, id) {
  await page.locator(`button[data-bs-target="#${id}"]`).click();
  const modal = page.locator('#' + id);
  await modal.waitFor({state:'visible'});
  await page.waitForFunction(id => {
    const modal = document.getElementById(id);
    return modal.contains(document.activeElement) && bootstrap.Modal.getInstance(modal)?._focustrap?._isActive;
  }, id);
  return modal;
}
async function exceptionSnapshot(form) {
  const values = {};
  for (const name of ['id', 'token', 'staff_assignment_id', 'exception_date']) {
    const input = form.locator(`input[type="hidden"][name="${name}"]`);
    assert.equal(await input.count(), 1);
    values[name] = await input.inputValue();
  }
  assert.equal(values.id, '61');
  assert.equal(values.staff_assignment_id, '7');
  const tokenFields = {id:'61', tenant_id:'1', staff_assignment_id:'7', exception_date:values.exception_date,
    is_unavailable:'1', custom_start_time:'', custom_end_time:'', reason:'Future leave fixture', updated_at:'2026-01-01 00:00:00'};
  assert.equal(values.token, createHash('sha256').update(JSON.stringify(tokenFields)).digest('hex'));
  return values;
}
for (const unit of ['clinic', 'guidance']) {
  for (const theme of ['light', 'dark']) {
    test(`${unit} availability exceptions: ${theme} mobile new exception date, leave/custom controls and isolated payload`, {timeout:20_000}, async t => {
      const item = cases.find(item => item.kind === unit + '-availability');
      const page = await pageFor(t, item.url + '?section=exceptions', theme, {viewport:{width:390,height:844}});
      await assertWorkspace(page);
      assert.match(await page.locator('[role="note"]').innerText(), /Existing appointments are never cancelled or moved/);
      for (const reason of ['Past leave fixture', 'Inactive enrollment fixture']) {
        const row = page.locator('.av-exception-row').filter({hasText:reason});
        assert.match(await row.innerText(), /Read-only history \/ inactive enrollment/);
        assert.equal(await row.locator('button, form').count(), 0);
      }
      assert.equal(await page.locator('#availability-exception-62-modal, #availability-exception-63-modal').count(), 0);
      fs.mkdirSync(exceptionPreviewDirectory, {recursive:true});
      const screenshot = path.join(exceptionPreviewDirectory, `${unit}-${theme}-mobile.png`);
      await page.screenshot({path:screenshot, fullPage:true, animations:'disabled'});
      t.diagnostic('Screenshot: ' + path.relative(root, screenshot));
      const before = posts.length;
      const modal = await openException(page, 'availability-exception-new-modal');
      const form = modal.locator('form[data-availability-save]');
      await assertAvailabilityContext(form, 'exception', 'exceptions');
      assert.equal(await form.locator('[data-datepicker]').count(), 1);
      assert.equal(await form.locator('#exceptionNewDateDisplay').getAttribute('data-target'), '#exceptionNewDate');
      assert.equal(await form.locator('#exceptionNewDate').getAttribute('type'), 'hidden');
      assert.equal(await form.locator('#exceptionNewReason').getAttribute('required'), '');
      assert.equal(await form.locator('[name="custom_start_time"], [name="custom_end_time"]').count(), 0);
      await selectExceptionOption(form, 'exceptionNewProvider', 'Provider Fixture');
      await form.locator('#exceptionNewDateDisplay').click();
      await modal.locator('.dp-popup:not([hidden])').waitFor();
      await page.keyboard.press('ArrowRight');
      await page.keyboard.press('Enter');
      const date = await form.locator('#exceptionNewDate').inputValue();
      assert.match(date, /^\d{4}-\d{2}-\d{2}$/);
      assert.match(await form.locator('#exceptionNewDateDisplay').inputValue(), /^\d{2}\/\d{2}\/\d{4}$/);
      assert.equal(await form.evaluate(form => form.checkValidity()), false, 'A reason is required');
      await form.locator('#exceptionNewReason').fill('Mobile custom hours fixture');
      await selectExceptionOption(form, 'exceptionNewType', 'Custom hours (30-minute slots)');
      await form.locator('#exceptionNewStart').waitFor({state:'attached'});
      assert.equal(await form.locator('input[type="text"][data-timepicker][required]').count(), 2);
      await chooseScheduleTime(form, 'exceptionNewStart', '09:15');
      await chooseScheduleTime(form, 'exceptionNewEnd', '11:45');
      assert.equal(await form.evaluate(form => form.checkValidity()), true);
      assert.equal(posts.length, before, 'Opening a dialog and changing fields must not auto-submit');
      failSave = true;
      await form.getByRole('button', {name:'Save exception', exact:true}).click();
      await availabilityFailure(form);
      assert.equal(posts.length, before + 1);
      assert.equal(posts.at(-1).path, item.url + '/exceptions');
      assert.deepEqual(availabilityBusinessFields(posts.at(-1).fields), {id:'0', staff_assignment_id:'7', exception_date:date,
        exception_type:'custom_hours', custom_start_time:'09:15', custom_end_time:'11:45', reason:'Mobile custom hours fixture'});
      assert.equal(await form.locator('#exceptionNewDate').inputValue(), date);
      assert.equal(await form.locator('[name="custom_start_time"]').inputValue(), '09:15');
      assert.equal(await form.locator('[name="custom_end_time"]').inputValue(), '11:45');
      assert.equal(await form.locator('[name="reason"]').inputValue(), 'Mobile custom hours fixture');
      const other = page.locator('#availability-exception-61-modal form').first();
      assert.equal(await other.locator('[name="reason"]').inputValue(), 'Future leave fixture');
      assert.equal(await other.locator('[name="custom_start_time"], [name="custom_end_time"]').count(), 0);
      await selectExceptionOption(form, 'exceptionNewType', 'Leave / full day unavailable');
      assert.equal(await form.locator('[name="custom_start_time"], [name="custom_end_time"]').count(), 0);
      assert.equal(await page.locator('#appFormModal').count(), 0);
    });
  }
  test(`${unit} availability exceptions: edit retains exact snapshot on conflict and retries with refreshed CSRF`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-availability');
    const page = await pageFor(t, item.url + '?section=exceptions&q=Provider&provider=7&day=3');
    await assertWorkspace(page);
    const modal = await openException(page, 'availability-exception-61-modal');
    const form = modal.locator('form').first();
    const remove = modal.locator('form').last();
    await assertAvailabilityContext(form, 'exception', 'exceptions', 'Provider', '7', '3');
    const snapshot = await exceptionSnapshot(form);
    assert.deepEqual(await exceptionSnapshot(remove), snapshot);
    assert.equal(await form.locator('[data-datepicker], select[name="staff_assignment_id"]').count(), 0);
    await form.locator('[name="reason"]').fill('Retain edited leave reason');
    const before = posts.length;
    exceptionConflict = true;
    await form.getByRole('button', {name:'Save exception', exact:true}).click();
    const feedback = form.locator('[data-availability-feedback][role="alert"]');
    await feedback.waitFor({state:'visible'});
    assert.match(await feedback.innerText(), /This exception changed/);
    assert.equal(posts.length, before + 1);
    const submitted = posts.at(-1);
    assert.equal(submitted.requestedWith, 'XMLHttpRequest');
    assert.deepEqual(availabilityBusinessFields(submitted.fields), {...snapshot, exception_type:'leave', reason:'Retain edited leave reason'});
    assert.deepEqual(await exceptionSnapshot(form), snapshot);
    assert.equal(await form.locator('[name="reason"]').inputValue(), 'Retain edited leave reason');
    assert.equal(await remove.locator('[name="reason"]').count(), 0);
    assert.equal(await remove.locator('[data-availability-feedback]').count(), 0);
    assert.equal(await form.locator('[name="csrf_test_name"]').inputValue(), 'refreshed-token');
    exceptionConflict = false;
    await form.getByRole('button', {name:'Save exception', exact:true}).click();
    await availabilityReload(page, item, 'exceptions', '7', '3', 'Provider');
    assert.equal(posts.length, before + 2);
    assert.deepEqual(posts.at(-1).fields, {...submitted.fields, csrf_test_name:'refreshed-token'});
  });
  test(`${unit} availability exceptions: remove requires confirmation and submits only the exact snapshot`, {timeout:20_000}, async t => {
    const item = cases.find(item => item.kind === unit + '-availability');
    const page = await pageFor(t, item.url + '?section=exceptions');
    await assertWorkspace(page);
    const before = posts.length;
    const modal = await openException(page, 'availability-exception-61-modal');
    const form = modal.locator('form').last();
    await assertAvailabilityContext(form, 'exception-remove', 'exceptions');
    const snapshot = await exceptionSnapshot(form);
    assert.equal(posts.length, before, 'Opening the editor must not auto-submit');
    await form.getByRole('button', {name:'Remove exception', exact:true}).click();
    const confirm = page.locator('#synapseConfirmModal');
    await confirm.waitFor({state:'visible'});
    assert.match(await confirm.innerText(), /Existing appointments will not be cancelled or moved/);
    assert.equal(posts.length, before, 'Prompting for confirmation must not submit');
    await confirm.locator('[data-confirm-cancel]').click();
    await confirm.waitFor({state:'hidden'});
    assert.equal(posts.length, before, 'Cancelling must send zero requests');
    await form.getByRole('button', {name:'Remove exception', exact:true}).click();
    await confirm.waitFor({state:'visible'});
    assert.equal(posts.length, before);
    await confirm.locator('[data-confirm-accept]').click();
    await availabilityReload(page, item, 'exceptions', '7');
    assert.equal(posts.length, before + 1);
    assert.equal(posts.at(-1).path, item.url + '/exceptions/remove');
    assert.equal(posts.at(-1).requestedWith, 'XMLHttpRequest');
    assert.deepEqual(availabilityBusinessFields(posts.at(-1).fields), snapshot);
  });
}

for (const item of cases) {
  if (item.kind.endsWith('-availability')) {
    test(`${item.kind}: direct URL opens the full-page weekly workspace and returns to appointments`, {timeout:15_000}, async t => {
      const page = await pageFor(t, item.url);
      await assertWorkspace(page);
      assert.equal(page.url(), origin + item.url);
      assert.equal(await page.locator('.av-section-nav a[aria-current="page"]').innerText(), 'Weekly schedules');
      assert.equal(await page.locator('table[data-configured-windows]').isVisible(), true);
      assert.equal(await page.locator('#availability-individual-modal').isVisible(), false);
      const back = page.getByRole('link', {name:'Appointments', exact:true}).filter({has:page.locator('.bi-arrow-left')});
      assert.equal(await back.getAttribute('data-app-modal-open'), null);
      await back.click();
      await page.waitForURL(origin + item.parent);
    });
  } else {
    test(`${item.kind}: direct URL opens an initialized dialog and X returns to the correct list`,{timeout:15_000},async t => {
      const page = await pageFor(t,item.url);
      await page.waitForFunction(() => bootstrap.Modal.getInstance(document.querySelector('#appFormModal'))?._isShown);
      await page.locator('#appFormModal button[data-app-modal-close]').click();
      await page.waitForURL(origin + item.parent);
    });
  }
}
