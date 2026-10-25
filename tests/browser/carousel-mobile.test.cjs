'use strict';
// Production PHP view + shell, local assets and synthetic data. No live HTTP app or DB.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { once } = require('node:events');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let browser, server, origin;
const cache = new Map();
function render(empty) {
  if (cache.has(empty)) return cache.get(empty);
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['tests/_support/render-carousel-mobile-fixture.php'], {
    cwd: root, input: JSON.stringify({ origin, empty }), encoding: 'utf8', timeout: 10000,
  });
  assert.equal(result.status, 0, result.stderr);
  assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code": 500/);
  assert.match(result.stdout, /class="app-shell"/);
  assert.match(result.stdout, /id="app-sidebar"/);
  cache.set(empty, result.stdout);
  return result.stdout;
}
before(async () => {
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    // All POSTs must be intercepted by Playwright; never forward to PHP/controllers.
    if (req.method !== 'GET') return res.writeHead(405).end('No mutations allowed');
    if (url.pathname === '/admin/carousel') {
      try { return res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(render(url.searchParams.has('empty'))); }
      catch (error) { return res.writeHead(500).end(error.stack); }
    }
    if (!url.pathname.startsWith('/assets/')) return res.writeHead(404).end();
    const dir = path.join(root, 'public');
    const file = path.resolve(dir, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(dir + path.sep)) return res.writeHead(403).end();
    fs.readFile(file, (error, data) => {
      if (error) return res.writeHead(404).end();
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': type }).end(data);
    });
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(n => /^chromium-\d+$/.test(n)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(dir, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 40000 });
after(async () => {
  try { await browser?.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});
async function open(t, width = 320, theme = 'light', query = '') {
  const context = await browser.newContext({ viewport: { width, height: 950 }, hasTouch: width < 768 });
  await context.addInitScript(theme => localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0'), theme);
  const posts = [], saves = [], actions = [], reads = [], errors = [];
  const refresh = { body: null };
  await context.route('**/*', async route => {
    const request = route.request();
    if (new URL(request.url()).origin !== origin) return route.abort();
    if (request.method() === 'POST') {
      const contentType = request.headers()['content-type'] || '';
      const fields = contentType.startsWith('multipart/form-data')
        ? [...await new Response(request.postDataBuffer(), { headers: { 'Content-Type': contentType } }).formData()]
        : [...new URLSearchParams(request.postData())];
      posts.push({ path: new URL(request.url()).pathname, fields });
      if (request.isNavigationRequest()) return route.fulfill({ status: 204 });
      // Keep real fetches pending until the test has checked the busy state.
      const pending = new URL(request.url()).pathname === '/admin/carousel/reorder' ? saves : actions;
      return new Promise(resolve => pending.push({
        request,
        async respond(options = {}) {
          await route.fulfill({ status: 200, contentType: 'application/json',
            body: JSON.stringify({ success: true, message: 'Order saved by fixture.' }), ...options });
          resolve();
        },
        async abort() { await route.abort('failed'); resolve(); },
      }));
    }
    if (new URL(request.url()).pathname === '/admin/carousel') {
      reads.push(request);
      if (refresh.body !== null) return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: refresh.body });
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(6000); page.setDefaultNavigationTimeout(15000);
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await context.close(); assert.deepEqual(errors, []); });
  await page.goto(origin + '/admin/carousel' + query);
  await page.waitForFunction(() => window.SynapseConfirm && window.SynapseToast && (!document.querySelector('#slideList') || document.querySelector('#slideList').dataset.reorderReady));
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return { page, posts, saves, actions, reads, refresh };
}
async function token(page, refreshed = false) {
  if (refreshed) await page.evaluate(() => window.SynapseCsrf.sync({ name: 'rotated_csrf', hash: 'refreshed-carousel-token' }));
  return page.evaluate(() => [document.querySelector('meta[name="csrf-name"]').content, document.querySelector('meta[name="csrf-token"]').content]);
}
async function expectPost(page, posts, saves, action, order, csrf) {
  await expectSaving(page, saves, 1);
  await expectToastGeometry(page);
  assert.deepEqual(posts, [{ path: action, fields: [csrf, ['order', order]] }]);
  assert.equal(await page.locator('[data-slide-move]:enabled').count(), 0);
  assert.equal(await page.locator('.slide-row[draggable="true"]').count(), 0);
  // Programmatic repeats and a late dragend must not submit another order.
  await page.evaluate(() => {
    document.querySelectorAll('[data-slide-move]').forEach(button => button.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    document.querySelector('.slide-row').dispatchEvent(new Event('dragend', { bubbles: true }));
  });
  await page.waitForTimeout(80);
  assert.equal(posts.length, 1);
  await saves[0].respond();
  await expectSettled(page, order.split(','), 'Order saved by fixture.');
}
async function expectSettled(page, order, message, failed = false) {
  await page.waitForFunction(() => !document.querySelector('#slideList').hasAttribute('aria-busy'));
  assert.deepEqual(await page.locator('.slide-row').evaluateAll(rows => rows.map(row => row.dataset.id)), order);
  await expectToast(page, message, failed ? 'danger' : 'success');
  assert.deepEqual(await page.locator('.slide-row').evaluateAll(rows => rows.map((row, index) => ({
    draggable: row.draggable,
    up: row.querySelector('[data-slide-move="up"]').disabled,
    down: row.querySelector('[data-slide-move="down"]').disabled,
    actions: [...row.querySelectorAll('.slide-actions button:not([data-slide-move])')].every(button => !button.disabled),
    links: [...row.querySelectorAll('.slide-actions a')].every(link => !link.hasAttribute('aria-disabled')),
  }))), order.map((_, index) => ({ draggable: true, up: index === 0, down: index === order.length - 1, actions: true, links: true })));
}
async function expectSaving(page, saves, count) {
  await page.waitForFunction(() => document.querySelector('#slideList').getAttribute('aria-busy') === 'true');
  for (let attempt = 0; saves.length < count && attempt < 100; attempt++) await page.waitForTimeout(20);
  assert.equal(saves.length, count);
  await expectToast(page, 'Saving slide order…', 'muted');
  assert.equal(await page.locator('.slide-actions button:enabled').count(), 0);
  assert.equal(await page.locator('.slide-actions a:not([aria-disabled="true"])').count(), 0);
  assert.equal(await page.locator('.slide-row[draggable="true"]').count(), 0);
}
async function expectToast(page, message, state) {
  // Bootstrap keeps .show during transitions; wait for the settled visible state.
  await page.waitForFunction(() => {
    const toast = document.querySelector('.synapse-toast');
    return toast?.classList.contains('show') && !toast.classList.contains('showing') && !toast.classList.contains('hiding');
  });
  await page.waitForFunction(({ text, flags, regex }) => {
    const content = document.querySelector('.synapse-toast-message')?.textContent;
    return regex ? new RegExp(text, flags).test(content || '') : content === text;
  }, { text: message instanceof RegExp ? message.source : message, flags: message instanceof RegExp ? message.flags : '', regex: message instanceof RegExp });
  const toast = page.locator('.synapse-toast');
  assert.equal(await toast.count(), 1);
  assert.equal(await page.locator('#synapse-toast-container .toast').count(), 1);
  assert.match(await toast.getAttribute('class'), /\btoast\b/);
  const type = { muted: 'info', danger: 'error' }[state] || state;
  assert.equal(await toast.getAttribute('data-type'), type);
  const live = toast.locator('[aria-atomic="true"]');
  assert.equal(await live.count(), 1);
  assert.equal(await live.getAttribute('role'), state === 'danger' ? 'alert' : 'status');
  assert.equal(await live.getAttribute('aria-live'), state === 'danger' ? 'assertive' : 'polite');
  assert.equal(await page.locator('#main .synapse-toast, .carousel-reorder-notifications, [data-reorder-status]').count(), 0);
  assert.equal(await page.locator('#synapse-toast-container').evaluate(host => host.parentElement === document.body), true);
  assert.equal(await toast.isVisible(), true);
  const text = await toast.locator('.synapse-toast-message').textContent();
  if (message instanceof RegExp) assert.match(text, message);
  else assert.equal(text, message);
  const icon = toast.locator('.synapse-toast-icon');
  const symbol = { muted: 'info-circle-fill', success: 'check-circle-fill', danger: 'x-circle-fill' }[state];
  assert.match(await icon.getAttribute('class'), new RegExp(`\\bbi-${symbol}\\b`));
  assert.notEqual(await icon.evaluate(element => getComputedStyle(element).color), 'rgba(0, 0, 0, 0)');
  assert.equal(await icon.getAttribute('aria-hidden'), 'true');
  const close = toast.getByRole('button', { name: 'Dismiss notification', exact: true });
  assert.match(await close.getAttribute('class'), /\bsynapse-toast-close\b/);
  assert.equal(await close.isEnabled(), true);
  assert.equal(await toast.evaluate(element => Boolean(window.bootstrap.Toast.getInstance(element))), true);
}
async function expectToastGeometry(page) {
  const problems = await page.locator('#synapse-toast-container').evaluate(wrapper => {
    const issues = [], css = getComputedStyle(wrapper), box = wrapper.getBoundingClientRect();

    const header = parseFloat(css.getPropertyValue('--header-height')) || 64;
    const viewport = document.documentElement.clientWidth;
    const toast = wrapper.querySelector('.synapse-toast'), bounds = toast.getBoundingClientRect();
    const near = (actual, expected) => Math.abs(actual - expected) <= 1;
    if (css.position !== 'fixed') issues.push('banner is not fixed');
    if (!near(box.top, header + 16)) issues.push('banner is not below header + 16px');
    if (!near(viewport - box.right, 16)) issues.push('banner is not 16px from right edge');
    if (!near(box.width, Math.min(360, viewport - 32))) issues.push(`wrong responsive banner width: actual=${box.width}, client=${viewport}, window=${innerWidth}, css=${css.width}`);
    if (box.left < 15 || box.right > viewport || box.bottom > innerHeight) issues.push('banner outside viewport');
    if (!near(bounds.left, box.left) || !near(bounds.top, box.top) || !near(bounds.width, box.width)) issues.push('toast does not fill wrapper');
    for (const element of toast.querySelectorAll('.synapse-toast-message, .synapse-toast-icon, .synapse-toast-close')) {
      const rect = element.getBoundingClientRect();
      if (rect.left < bounds.left || rect.right > bounds.right || rect.top < bounds.top || rect.bottom > bounds.bottom) issues.push('toast content outside banner');
    }
    if (document.documentElement.scrollWidth > viewport + 1) issues.push('banner causes overflow');
    if (getComputedStyle(toast).backgroundColor === 'rgba(0, 0, 0, 0)') issues.push('transparent toast surface');
    return issues;
  });
  assert.deepEqual(problems, []);
}
for (const theme of ['light', 'dark']) for (const width of [320, 390, 768, 1440]) {
  test(`${width}px ${theme}: full row content, bounds, actions, reorder and top-right toast`, { timeout: 25000 }, async t => {
    const { page, posts, saves } = await open(t, width, theme);
    const problems = await page.locator('.carousel-admin').evaluate((root) => {
      const issues = [];
      const viewport = document.documentElement.clientWidth;
      if (document.documentElement.scrollWidth > viewport + 1) issues.push('page overflow');
      for (const row of root.querySelectorAll('.slide-row')) {
        const box = row.getBoundingClientRect();
        for (const element of row.querySelectorAll('*')) {
          const rect = element.getBoundingClientRect();
          if (!rect.width || !rect.height) continue;
          if (rect.left < box.left - 1 || rect.right > box.right + 1 || rect.left < -1 || rect.right > viewport + 1) issues.push(`${element.className}: outside row/viewport`);
        }
        for (const text of row.querySelectorAll('.slide-title, .slide-subtitle')) {
          const css = getComputedStyle(text);
          if (text.clientWidth < 80 || text.scrollWidth > text.clientWidth + 1 || text.scrollHeight > text.clientHeight + 1 || css.whiteSpace === 'nowrap' || css.textOverflow === 'ellipsis') issues.push('clipped text');
          const range = document.createRange(); range.selectNodeContents(text);
          const bounds = text.getBoundingClientRect();
          for (const rect of range.getClientRects()) if (rect.right > bounds.right + 1 || rect.bottom > bounds.bottom + 1) issues.push('hidden full text');
        }
        for (const button of row.querySelectorAll('.btn')) {
          const rect = button.getBoundingClientRect();
          if (rect.width < 44 || rect.height < 44 || !button.getAttribute('aria-label')) issues.push('small/unnamed action');
        }
      }
      return issues;
    });
    assert.deepEqual(problems, []);
    assert.equal(await page.locator('.slide-title').first().textContent(), 'A long carousel title for campus wellbeing with every word readable on a small phone');
    assert.equal(await page.locator('.slide-title').last().textContent(), 'Untitled Slide');
    assert.equal(await page.locator('.slide-subtitle').last().textContent(), '—');
    assert.equal(await page.locator('.slide-thumb').count(), 6);
    for (const img of await page.locator('.slide-thumb img').all()) assert.ok(await img.evaluate(img => img.complete && img.naturalWidth > 0));
    assert.equal(await page.locator('.slide-row').nth(1).getByText('Hidden', { exact: true }).isVisible(), true);
    assert.equal(await page.getByText('Contained + Blur', { exact: true }).isVisible(), true);
    assert.equal(await page.getByText('none', { exact: true }).isVisible(), true);
    const first = page.locator('.slide-row').first(), last = page.locator('.slide-row').last();
    assert.equal(await first.locator('[data-slide-move="up"]').isDisabled(), true);
    assert.equal(await last.locator('[data-slide-move="down"]').isDisabled(), true);
    assert.equal(await first.locator('[data-slide-move="down"]').isEnabled(), true);
    assert.equal(await last.locator('[data-slide-move="up"]').isEnabled(), true);
    if (width >= 768) {
      const boxes = await first.locator('.slide-thumbnails, .slide-copy, .slide-actions').evaluateAll(els => els.map(el => el.getBoundingClientRect().x));
      assert.ok(boxes[0] < boxes[1] && boxes[1] < boxes[2], 'desktop/tablet stays a horizontal row');
    }
    const csrf = await token(page, theme === 'dark');
    const down = first.getByRole('button', { name: /^Move down:/ });
    if (width < 768) await down.tap();
    else { await down.focus(); await page.keyboard.press('Enter'); }
    await expectPost(page, posts, saves, '/admin/carousel/reorder', '42,17,93', csrf);
    assert.deepEqual(await page.locator('.slide-row').evaluateAll(rows => rows.map(row => row.dataset.id)), ['42', '17', '93']);
  });
}
test('keyboard Space moves last slide up with refreshed CSRF', { timeout: 20000 }, async t => {
  const { page, posts, saves } = await open(t);
  const csrf = await token(page, true);
  await page.locator('.slide-row').last().getByRole('button', { name: 'Move up: Untitled Slide', exact: true }).focus();
  await page.keyboard.press('Space');
  await expectPost(page, posts, saves, '/admin/carousel/reorder', '17,93,42', csrf);
});
test('drag unchanged is a no-op; drag to end preserves exact order and CSRF', { timeout: 20000 }, async t => {
  const { page, posts, saves } = await open(t, 1440);
  const csrf = await token(page, true);
  await page.evaluate(() => {
    const row = document.querySelector('.slide-row');
    row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: new DataTransfer() }));
    row.dispatchEvent(new DragEvent('dragend', { bubbles: true }));
  });
  await page.waitForTimeout(100); assert.equal(posts.length, 0);
  await page.evaluate(() => {
    const row = document.querySelector('.slide-row'), list = row.parentElement;
    row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: new DataTransfer() }));
    list.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, clientY: list.getBoundingClientRect().bottom + 5 }));
    row.dispatchEvent(new DragEvent('dragend', { bubbles: true }));
  });
  await expectPost(page, posts, saves, '/admin/carousel/reorder', '42,93,17', csrf);
});
test('native mouse drag still reorders the desktop list', { timeout: 20000 }, async t => {
  const { page, posts, saves } = await open(t, 1440);
  const csrf = await token(page);
  const last = page.locator('.slide-row').last();
  const box = await last.boundingBox();
  await page.locator('.slide-row').first().dragTo(last, { targetPosition: { x: 20, y: box.height - 4 } });
  await expectPost(page, posts, saves, '/admin/carousel/reorder', '42,93,17', csrf);
});
test('saving toast persists, updates in place, cancels stale hide timers and auto-hides success after five seconds', { timeout: 20000 }, async t => {
  const { page, saves } = await open(t, 390);
  await page.clock.install();
  await page.locator('[data-id="17"] [data-slide-move="down"]').click();
  await expectSaving(page, saves, 1);
  await page.evaluate(() => {
    window.toastSnapshot = { node: document.querySelector('.synapse-toast'),
      instance: bootstrap.Toast.getInstance(document.querySelector('.synapse-toast')) };
  });
  await page.clock.fastForward(6000);
  await expectToast(page, 'Saving slide order…', 'muted');
  await saves[0].respond();
  await expectSettled(page, ['42', '17', '93'], 'Order saved by fixture.');
  await page.clock.fastForward(4000);
  await expectToast(page, 'Order saved by fixture.', 'success');
  await page.locator('[data-id="17"] [data-slide-move="down"]').click();
  await expectSaving(page, saves, 2);
  await page.clock.fastForward(1500);
  await expectToast(page, 'Saving slide order…', 'muted');
  await saves[1].respond();
  await expectSettled(page, ['42', '93', '17'], 'Order saved by fixture.');
  assert.equal(await page.evaluate(() => window.toastSnapshot.node === document.querySelector('.synapse-toast')
    && window.toastSnapshot.instance === bootstrap.Toast.getInstance(window.toastSnapshot.node)), true);
  await page.clock.fastForward(4000);
  await expectToast(page, 'Order saved by fixture.', 'success');
  await page.clock.fastForward(1500);
  await page.locator('.synapse-toast').waitFor({ state: 'hidden' });
  assert.equal(await page.locator('.synapse-toast.show').count(), 0);
  assert.equal(await page.locator('#synapse-toast-container .toast').count(), 0);
  assert.equal(await page.evaluate(() => bootstrap.Toast.getInstance(window.toastSnapshot.node)), null);
});
for (const failed of [false, true]) {
  test(`${failed ? 'error toast stays visible past five seconds' : 'success toast'} can be manually dismissed without navigation`, { timeout: 20000 }, async t => {
    const { page, posts, saves } = await open(t, failed ? 320 : 1440, failed ? 'dark' : 'light');
    const documents = [];
    page.on('request', request => { if (request.isNavigationRequest()) documents.push(request.url()); });
    await page.clock.install();
    await page.locator('[data-id="17"] [data-slide-move="down"]').click();
    await expectSaving(page, saves, 1);
    await saves[0].respond(failed ? { status: 500, body: JSON.stringify({ success: false, message: 'Save unavailable.' }) } : {});
    const message = failed ? 'Could not confirm the update. Save unavailable. Display restored to the last confirmed order. Try reordering again or reload to verify.' : 'Order saved by fixture.';
    await expectSettled(page, failed ? ['17', '42', '93'] : ['42', '17', '93'], message, failed);
    if (failed) {
      await page.clock.fastForward(10000);
      await expectToast(page, message, 'danger');
    }
    await expectToastGeometry(page);
    await page.evaluate(() => {
      window.dismissSnapshot = { document, main: document.querySelector('#main'), list: document.querySelector('#slideList'),
        toast: document.querySelector('.synapse-toast'), url: location.href, history: JSON.stringify(history.state), length: history.length };
    });
    await page.getByRole('button', { name: 'Dismiss notification', exact: true }).click();
    await page.locator('.synapse-toast').waitFor({ state: 'hidden' });
    assert.equal(await page.evaluate(() => {
      const snapshot = window.dismissSnapshot;
      return snapshot.document === document && snapshot.main === document.querySelector('#main') && snapshot.list === document.querySelector('#slideList')
        && !snapshot.toast.isConnected && bootstrap.Toast.getInstance(snapshot.toast) === null && snapshot.url === location.href
        && snapshot.history === JSON.stringify(history.state) && snapshot.length === history.length;
    }), true);
    assert.equal(posts.length, 1);
    assert.deepEqual(documents, []);
  });
}
test('repeated saves rotate header/body CSRF without navigation, rerender or scroll loss', { timeout: 25000 }, async t => {
  const { page, posts, saves } = await open(t, 390);
  const documents = [];
  page.on('request', request => { if (request.isNavigationRequest()) documents.push(request.url()); });
  const initialCsrf = await token(page, true);
  await page.evaluate(() => {
    window.reorderSnapshot = {
      document, main: document.querySelector('#main'), list: document.querySelector('#slideList'),
      rows: [...document.querySelectorAll('.slide-row')], status: null, toast: null,
      url: location.href, history: JSON.stringify(history.state), length: history.length, loads: 0, swaps: 0,
    };
    document.addEventListener('synapse:page-load', () => window.reorderSnapshot.loads++);
    document.addEventListener('synapse:before-swap', () => window.reorderSnapshot.swaps++);
  });
  const tokens = [initialCsrf, ['header_csrf', 'header-token'], ['body_csrf', 'body-token']];
  for (let index = 0; index < 3; index++) {
    const direction = index === 1 ? 'up' : 'down';
    await page.locator(`[data-id="17"] [data-slide-move="${direction}"]`).focus();
    await page.evaluate(async direction => {
      window.scrollTo({ top: 150, behavior: 'instant' });
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      window.reorderSnapshot.scroll = [scrollX, scrollY];
      window.reorderSnapshot.history = JSON.stringify(history.state);
      document.querySelector(`[data-id="17"] [data-slide-move="${direction}"]`).click();
    }, direction);
    await expectSaving(page, saves, index + 1);
    if (index === 0) await page.evaluate(() => {
      window.reorderSnapshot.status = document.querySelector('.synapse-toast');
      window.reorderSnapshot.toast = bootstrap.Toast.getInstance(window.reorderSnapshot.status);
    });
    assert.equal(await page.evaluate(() => window.reorderSnapshot.status === document.querySelector('.synapse-toast')
      && window.reorderSnapshot.toast === bootstrap.Toast.getInstance(document.querySelector('.synapse-toast'))), true);
    const order = index === 1 ? '17,42,93' : '42,17,93';
    assert.deepEqual(posts[index], { path: '/admin/carousel/reorder', fields: [tokens[index], ['order', order]] });
    const headers = saves[index].request.headers();
    assert.equal(headers['x-requested-with'], 'XMLHttpRequest');
    assert.equal(headers.accept, 'application/json');
    assert.match(headers['content-type'], /^application\/x-www-form-urlencoded/);
    assert.equal(saves[index].request.resourceType(), 'fetch');
    const csrf = index === 0 ? undefined : { name: 'body_csrf', hash: 'body-token' };
    await saves[index].respond({ headers: { 'X-CSRF-NAME': 'header_csrf', 'X-CSRF-TOKEN': 'header-token' },
      body: JSON.stringify({ success: true, message: `Confirmed save ${index + 1}.`, csrf }) });
    await expectSettled(page, order.split(','), `Confirmed save ${index + 1}.`);
    assert.deepEqual(await token(page), index === 0 ? tokens[1] : tokens[2]);
    assert.deepEqual(await page.evaluate(() => {
      const snapshot = window.reorderSnapshot;
      return {
        document: snapshot.document === document, main: snapshot.main === document.querySelector('#main'),
        list: snapshot.list === document.querySelector('#slideList'), status: snapshot.status === document.querySelector('.synapse-toast')
                  && snapshot.toast === bootstrap.Toast.getInstance(document.querySelector('.synapse-toast')),
        rows: snapshot.rows.every(row => row === document.querySelector(`[data-id="${row.dataset.id}"]`)),
        url: snapshot.url === location.href, history: snapshot.history === JSON.stringify(history.state), length: snapshot.length === history.length,
        loads: snapshot.loads, swaps: snapshot.swaps, scroll: [scrollX, scrollY],
      };
    }), await page.evaluate(() => ({ document: true, main: true, list: true, status: true, rows: true, url: true, history: true,
      length: true, loads: 0, swaps: 0, scroll: window.reorderSnapshot.scroll,
    })), 'save preserves the live page, history and scroll');
  }
  assert.deepEqual(documents, []);
});
for (const status of [422, 500]) {
  test(`${status} rolls back to the last confirmed order, announces failure and permits retry`, { timeout: 20000 }, async t => {
    const { page, posts, saves } = await open(t);
    await page.locator('[data-id="17"] [data-slide-move="down"]').click();
    await expectSaving(page, saves, 1);
    await saves[0].respond();
    await expectSettled(page, ['42', '17', '93'], 'Order saved by fixture.');
    await page.locator('[data-id="17"] [data-slide-move="down"]').focus();
    await page.keyboard.press('Enter');
    await expectSaving(page, saves, 2);
    await saves[1].respond({ status, body: JSON.stringify({ success: false, message: `Save rejected (${status}).`,
      csrf: { name: 'retry_csrf', hash: `retry-${status}` } }) });
    await expectSettled(page, ['42', '17', '93'], `Could not confirm the update. Save rejected (${status}). Display restored to the last confirmed order. Try reordering again or reload to verify.`, true);
    assert.equal(await page.evaluate(() => document.activeElement.closest('.slide-row')?.dataset.id), '17');
    await page.locator('[data-id="93"] [data-slide-move="up"]').click();
    await expectSaving(page, saves, 3);
    assert.deepEqual(posts[2].fields, [['retry_csrf', `retry-${status}`], ['order', '42,93,17']]);
    await saves[2].respond();
    await expectSettled(page, ['42', '93', '17'], 'Order saved by fixture.');
  });
}
for (const failure of ['network', 'invalid JSON', 'redirect', 'non-JSON', 'unsuccessful JSON']) {
  test(`${failure} response restores order and allows a successful retry`, { timeout: 20000 }, async t => {
    const { page, posts, saves } = await open(t);
    const csrf = await token(page);
    await page.locator('[data-id="17"] [data-slide-move="down"]').click();
    await expectSaving(page, saves, 1);
    if (failure === 'network') await saves[0].abort();
    else if (failure === 'invalid JSON') await saves[0].respond({ body: '{broken' });
    else if (failure === 'redirect') await saves[0].respond({ status: 302, headers: { location: '/admin/carousel?redirected=1' }, body: '' });
    else if (failure === 'non-JSON') await saves[0].respond({ contentType: 'text/html', body: '<html>Sign in</html>' });
    else await saves[0].respond({ body: JSON.stringify({ success: false, message: 'Not saved.' }) });
    await expectSettled(page, ['17', '42', '93'], /^Could not confirm the update\. .+ Display restored to the last confirmed order\. Try reordering again or reload to verify\.$/, true);
    if (failure === 'redirect' || failure === 'non-JSON') assert.match(await page.locator('.synapse-toast-message').textContent(), /Unexpected save response/);
    assert.equal(page.url(), origin + '/admin/carousel');
    await page.locator('[data-id="17"] [data-slide-move="down"]').click();
    await expectSaving(page, saves, 2);
    assert.deepEqual(posts[1].fields, [csrf, ['order', '42,17,93']]);
    await saves[1].respond();
    await expectSettled(page, ['42', '17', '93'], 'Order saved by fixture.');
  });
}
test('busy save blocks repeated clicks, drag, progressive navigation and unload only until completion', { timeout: 20000 }, async t => {
  const { page, posts, saves } = await open(t, 1440);
  const guards = () => page.evaluate(() => ({
    navigation: !document.dispatchEvent(new CustomEvent('synapse:before-navigate', { cancelable: true })),
    unload: !window.dispatchEvent(new Event('beforeunload', { cancelable: true })),
  }));
  assert.deepEqual(await guards(), { navigation: false, unload: false });
  await page.locator('[data-id="17"] [data-slide-move="down"]').dblclick({ force: true });
  await expectSaving(page, saves, 1);
  assert.deepEqual(await guards(), { navigation: true, unload: true });
  assert.equal(await page.evaluate(() => window.SynapseNavigation.navigate('/admin/carousel?empty=1')), false);
  await page.evaluate(() => {
    const list = document.querySelector('#slideList'), row = list.firstElementChild;
    list.querySelectorAll('button, a').forEach(element => element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })));
    row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
    list.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, clientY: list.getBoundingClientRect().bottom + 5 }));
    row.dispatchEvent(new DragEvent('dragend', { bubbles: true }));
  });
  await page.waitForTimeout(100);
  assert.equal(posts.length, 1);
  assert.deepEqual(await page.locator('.slide-row').evaluateAll(rows => rows.map(row => row.dataset.id)), ['42', '17', '93']);
  await saves[0].respond();
  await expectSettled(page, ['42', '17', '93'], 'Order saved by fixture.');
  assert.deepEqual(await guards(), { navigation: false, unload: false });
});
test('progressive navigation cleans up and initializes reorder once on return', { timeout: 25000 }, async t => {
  const { page, posts, saves } = await open(t);
  await page.clock.install();
  assert.equal(await page.evaluate(() => window.SynapseNavigation.navigate('/admin/carousel?empty=1')), true);
  assert.equal(await page.locator('#slideList').count(), 0);
  assert.equal(await page.evaluate(() => window.SynapseNavigation.navigate('/admin/carousel')), true);
  await page.waitForFunction(() => document.querySelector('#slideList')?.dataset.reorderReady === 'true');
  await token(page, true);
  await page.locator('[data-id="17"] [data-slide-move="down"]').click();
  await expectPost(page, posts, saves, '/admin/carousel/reorder', '42,17,93', ['rotated_csrf', 'refreshed-carousel-token']);
  assert.equal(await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-navigate', { cancelable: true }))), true);
  await page.evaluate(() => { window.disposedToast = document.querySelector('.synapse-toast'); });
  assert.equal(await page.evaluate(() => window.SynapseNavigation.navigate('/admin/carousel?empty=1')), true);
  await page.locator('.synapse-toast').waitFor({ state: 'detached' });
  assert.equal(await page.evaluate(() => bootstrap.Toast.getInstance(window.disposedToast)), null);
  await page.clock.fastForward(6000);
  assert.equal(await page.locator('.synapse-toast').count(), 0);
  assert.equal(await page.evaluate(() => window.SynapseNavigation.navigate('/admin/carousel')), true);
  await page.waitForFunction(() => document.querySelector('#slideList')?.dataset.reorderReady === 'true');
  await page.locator('[data-id="17"] [data-slide-move="down"]').click();
  await expectSaving(page, saves, 2);
  assert.equal(await page.evaluate(() => document.querySelector('.synapse-toast') !== window.disposedToast), true);
  await saves[1].respond();
  await expectSettled(page, ['42', '17', '93'], 'Order saved by fixture.');
});
for (const action of ['toggle', 'delete']) {
  test(`${action} uses one background POST and authoritative GET with shared feedback`, { timeout: 25000 }, async t => {
    const { page, posts, actions, reads, refresh } = await open(t, action === 'delete' ? 320 : 1440, action === 'delete' ? 'dark' : 'light');
    // Exercise the real generic asset even while the shell integration is being landed separately.
    if (!await page.evaluate(() => Boolean(window.SynapseBackgroundActions))) {
      await page.addScriptTag({ url: origin + '/assets/js/background-actions.js' });
    }
    assert.equal(await page.locator('.carousel-admin form[data-background-submit]').count(), 6);
    assert.equal(await page.locator('.carousel-admin form:not([data-background-submit])').count(), 0);
    const originalCsrf = await token(page);
    const csrf = await token(page, true);
    const documents = [];
    page.on('request', request => { if (request.isNavigationRequest()) documents.push(request.url()); });
    refresh.body = render(false).replaceAll(originalCsrf[0], 'after_action_csrf').replaceAll(originalCsrf[1], 'after-action-token');
    if (action === 'delete') {
      refresh.body = refresh.body.replace(/<li\b[^>]*data-id="17"[\s\S]*?<\/li>/, '').replace('3 total', '2 total');
    } else {
      refresh.body = refresh.body.replace(/<li\b[^>]*data-id="17"[\s\S]*?<\/li>/, row => row
        .replaceAll('Hide from login', 'Show on login').replace('bi-eye"', 'bi-eye-slash"')
        .replace('<div class="fw-semibold text-dark slide-title">', '<span class="badge bg-secondary-subtle text-secondary">Hidden</span><div class="fw-semibold text-dark slide-title">'));
    }
    const form = page.locator(`[data-id="17"] form[action$="/${action}"]`);
    const button = form.getByRole('button');
    await button.click();
    if (action === 'delete') {
      const modal = page.getByRole('dialog', { name: 'Delete carousel slide' });
      await modal.waitFor({ state: 'visible' });
      assert.equal(posts.length, 0);
      await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
      await modal.waitFor({ state: 'hidden' });
      assert.equal(posts.length, 0);
      await button.click();
      await modal.getByRole('button', { name: 'Delete slide', exact: true }).click();
      await modal.waitFor({ state: 'hidden' });
    }
    await page.waitForFunction(action => document.querySelector(`[data-id="17"] form[action$="/${action}"]`)?.getAttribute('aria-busy') === 'true', action);
    for (let attempt = 0; actions.length < 1 && attempt < 100; attempt++) await page.waitForTimeout(20);
    assert.equal(actions.length, 1);
    assert.deepEqual(posts, [{ path: `/admin/carousel/17/${action}`, fields: [csrf] }]);
    assert.equal(await button.isDisabled(), true);
    assert.equal(await page.locator('#main').evaluate(main => main.inert), true);
    const headers = actions[0].request.headers();
    assert.equal(headers['x-synapse-feedback'], 'toast');
    assert.equal(headers['x-requested-with'], 'XMLHttpRequest');
    assert.equal(headers.accept, 'application/json');
    assert.equal(actions[0].request.resourceType(), 'fetch');
    assert.equal(await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-navigate', { cancelable: true }))), false);
    assert.equal(reads.length, 1, 'no refresh before the write is confirmed');
    await page.evaluate(() => {
      window.actionSnapshot = { document, main: document.querySelector('#main'), url: location.href, length: history.length, loads: 0 };
      document.addEventListener('synapse:page-load', () => window.actionSnapshot.loads++);
    });
    if (action === 'toggle') {
      await form.evaluate(form => form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('button') })));
      await page.waitForTimeout(80);
      assert.equal(posts.length, 1);
    }
    await actions[0].respond({ body: JSON.stringify({ success: true, message: `${action} confirmed by fixture.`, csrf: { name: 'after_action_csrf', hash: 'after-action-token' } }) });
    await page.waitForFunction(() => window.actionSnapshot.loads === 1);
    await expectToast(page, `${action} confirmed by fixture.`, 'success');
    assert.equal(reads.length, 2);
    assert.equal(posts.length, 1);
    assert.deepEqual(documents, []);
    assert.equal(await page.evaluate(() => window.actionSnapshot.document === document && window.actionSnapshot.main !== document.querySelector('#main')
      && window.actionSnapshot.url === location.href && window.actionSnapshot.length === history.length && !document.querySelector('#main').inert), true);
    assert.deepEqual(await token(page), ['after_action_csrf', 'after-action-token']);
    assert.equal(await page.locator('.slide-row').count(), action === 'delete' ? 2 : 3);
    if (action === 'delete') assert.equal(await page.locator('[data-id="17"]').count(), 0);
    else {
      assert.equal(await page.locator('[data-id="17"]').getByText('Hidden', { exact: true }).isVisible(), true);
      assert.equal(await page.locator('[data-id="17"]').getByRole('button', { name: /^Show on login:/ }).isEnabled(), true);
    }
    await page.waitForFunction(() => document.querySelector('#slideList')?.dataset.reorderReady === 'true');
  });
}
test('native delete fallback preserves confirmation and current CSRF without fetch', { timeout: 20000 }, async t => {
  const { page, posts } = await open(t, 320, 'dark');
  const csrf = await token(page, true);
  await page.evaluate(() => { window.fetch = undefined; });
  const remove = page.locator('.slide-row').first().getByRole('button', { name: /^Delete slide:/ });
  await remove.tap();
  const modal = page.getByRole('dialog', { name: 'Delete carousel slide' });
  await modal.waitFor({ state: 'visible' });
  assert.equal(posts.length, 0);
  assert.equal(await modal.getByText('Delete this slide and its uploaded images? This cannot be undone.', { exact: true }).isVisible(), true);
  await modal.getByRole('button', { name: 'Cancel', exact: true }).click();
  await modal.waitFor({ state: 'hidden' }); assert.equal(posts.length, 0);
  await remove.tap(); await modal.waitFor({ state: 'visible' });
  await modal.getByRole('button', { name: 'Delete slide', exact: true }).click();
  await page.waitForTimeout(700);
  assert.deepEqual(posts, [{ path: '/admin/carousel/17/delete', fields: [csrf] }]);
});
test('empty list retains production empty state without reorder initialization errors', { timeout: 20000 }, async t => {
  const { page, posts } = await open(t, 320, 'light', '?empty=1');
  assert.equal(await page.locator('#slideList').count(), 0);
  assert.equal(await page.getByText('No slides configured yet.', { exact: false }).isVisible(), true);
  assert.deepEqual(posts, []);
});
