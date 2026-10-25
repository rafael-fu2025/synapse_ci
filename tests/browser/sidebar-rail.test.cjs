'use strict';

// Real PHP shell + local production assets; no DB, controllers, or device services.
// Run alone: node --test tests/browser/sidebar-rail.test.cjs
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
const fixturePath = '/facilities/units/11';
const storageKey = 'synapse-sidebar-collapsed';
let server, browser, origin, rendered;

before(async () => {
  server = http.createServer((req, res) => {
    try {
      if (req.method !== 'GET') { res.writeHead(405); res.end(); return; }
      const url = new URL(req.url, origin);
      if (url.pathname === fixturePath) {
        if (rendered === undefined) {
          const result = spawnSync('php', ['tests/_support/render-bmg-fixture.php'], {
            cwd: root, input: JSON.stringify({ origin, path: fixturePath, kind: 'unit_detail' }),
            encoding: 'utf8', timeout: 20000, maxBuffer: 8 * 1024 * 1024
          });
          assert.equal(result.status, 0, `PHP fixture failed: ${result.error || result.stderr || result.stdout}`);
          assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
          assert.match(result.stdout, /<!DOCTYPE html>/i);
          rendered = result.stdout;
        }
        res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(rendered); return;
      }
      const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
      if (!url.pathname.startsWith('/assets/') || !file.startsWith(path.join(root, 'public/assets') + path.sep)
          || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        res.writeHead(404); res.end(); return;
      }
      const types = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml',
        '.png': 'image/png', '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2' };
      res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
      res.end(fs.readFileSync(file));
    } catch (error) { res.writeHead(500); res.end(String(error.stack)); }
  });
  server.requestTimeout = 10000; server.headersTimeout = 10000;
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(dir) && fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install cached Chromium in writable/widget-test-tools');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });

after(async () => {
  await browser?.close();
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

async function pageFor(t, { width = 1440, theme = 'light', motion = 'reduce', saved, storageDisabled = false, prepaint = false } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 1000 },
    colorScheme: theme, reducedMotion: motion, serviceWorkers: 'block' });
  t.after(() => context.close());
  await context.addInitScript(({ theme, saved, storageDisabled }) => {
    localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
    // Seed once: reload must exercise the value written by the real toggle handler.
    if (!sessionStorage.getItem('rail-fixture-seeded')) {
      if (saved !== undefined) localStorage.setItem('synapse-sidebar-collapsed', saved);
      sessionStorage.setItem('rail-fixture-seeded', '1');
    }
    if (storageDisabled) {
      for (const method of ['getItem', 'setItem', 'removeItem']) {
        Storage.prototype[method] = () => { throw new DOMException('Storage disabled', 'SecurityError'); };
      }
    }
  }, { theme, saved, storageDisabled });
  await context.route('**/*', route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin !== origin) return route.fulfill({ status: 200, body: '' });
    // Blocking core/main.js lets the test inspect sidebar-state.js before runtime initialization.
    if (prepaint && url.pathname === '/assets/js/core/main.js') return route.fulfill({ status: 200, body: '' });
    if (req.method() !== 'GET' || (!url.pathname.startsWith('/assets/') && url.pathname !== fixturePath)) {
      return route.fulfill({ status: 404, body: 'No application endpoints in rail fixtures' });
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000); page.setDefaultNavigationTimeout(20000);
  const response = await page.goto(origin + fixturePath, { waitUntil: 'load' });
  assert.equal(response.status(), 200, await response.text());
  await page.evaluate(() => document.fonts.ready);
  // Theme restoration itself reads storage unguarded; this case tests sidebar resilience only.
  if (!storageDisabled) assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  assert.equal(await page.evaluate(() => typeof bootstrap.Tooltip), 'function');
  assert.equal(await page.locator('body.app-shell').count(), 1);
  const sheets = await page.evaluate(() => {
    const paths = [];
    function visit(sheet) {
      if (sheet.href && new URL(sheet.href).origin !== location.origin) return;
      if (sheet.href) paths.push(new URL(sheet.href).pathname);
      for (const rule of sheet.cssRules) if (rule.styleSheet) visit(rule.styleSheet);
    }
    [...document.styleSheets].forEach(visit); return paths;
  });
  for (const file of ['vendor/bootstrap/css/bootstrap.min.css', 'css/core/layout.css', 'css/core/synapse-theme.css']) {
    assert.ok(sheets.includes('/assets/' + file), `Production stylesheet loaded: ${file}`);
  }
  return page;
}

async function desktopState(page, collapsed, runtime = true) {
  await page.waitForFunction(({ collapsed, runtime }) => {
    const sidebar = document.querySelector('#app-sidebar'), toggle = document.querySelector('.sidebar-toggle');
    const width = collapsed ? 72 : 280;
    return document.body.classList.contains('sidebar-collapsed') === collapsed
      && !document.body.classList.contains('sidebar-hidden')
      && Math.abs(sidebar.getBoundingClientRect().width - width) <= 1
      && Math.abs(document.querySelector('.header').getBoundingClientRect().left - width) <= 1
      && Math.abs(parseFloat(getComputedStyle(document.querySelector('#main')).marginLeft) - width) <= 1
      && (!runtime || (toggle.getAttribute('aria-expanded') === String(!collapsed)
        && !sidebar.inert && sidebar.getAttribute('aria-hidden') === 'false'));
  }, { collapsed, runtime });
  const geometry = await page.evaluate(() => {
    const rect = selector => {
      const r = document.querySelector(selector).getBoundingClientRect();
      return { x: r.x, y: r.y, width: r.width, right: r.right, bottom: r.bottom };
    };
    return { sidebar: rect('#app-sidebar'), header: rect('.header'), main: rect('#main'),
      viewport: innerWidth, height: innerHeight, overflow: document.documentElement.scrollWidth,
      sidebarZ: Number(getComputedStyle(document.querySelector('#app-sidebar')).zIndex),
      headerZ: Number(getComputedStyle(document.querySelector('.header')).zIndex) };
  });
  const width = collapsed ? 72 : 280;
  assert.equal(geometry.sidebar.x, 0); assert.equal(geometry.sidebar.y, 0);
  assert.equal(geometry.sidebar.bottom, geometry.height);
  assert.equal(geometry.sidebarZ, 1040); assert.ok(geometry.sidebarZ > geometry.headerZ);
  for (const name of ['header', 'main']) {
    assert.ok(Math.abs(geometry[name].width - (geometry.viewport - width)) <= 1, `${name} uses remaining width: ${JSON.stringify(geometry)}`);
    assert.ok(Math.abs(geometry[name].right - geometry.viewport) <= 1);
  }
  assert.ok(geometry.overflow <= geometry.viewport + 1);
  if (runtime) {
    assert.equal(await page.locator('.sidebar-toggle').getAttribute('aria-label'), collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    assert.equal(await page.locator('.sidebar-toggle').getAttribute('aria-controls'), 'app-sidebar');
  }
}

async function brand(page, collapsed) {
  const home = page.getByRole('link', { name: 'SYNAPSE home', exact: true });
  assert.equal(await home.count(), 1);
  assert.equal(await home.getAttribute('href'), origin + '/');
  assert.equal(await page.locator('.header .sidebar-brand, .header .header-logo, .header .header-brand img').count(), 0);
  const logo = home.locator('.sidebar-brand-logo');
  assert.equal(await logo.isVisible(), true);
  assert.equal(await logo.evaluate(img => img.complete && img.naturalWidth > 0), true, 'Real brand image loaded');
  assert.equal(await home.locator('.sidebar-brand-copy').isVisible(), !collapsed);
  assert.equal(await home.locator('strong').textContent(), 'SYNAPSE');
  assert.equal(await home.locator('.sidebar-brand-copy > span').textContent(), 'Foundation University');
  const fits = await logo.evaluate(img => {
    const r = img.getBoundingClientRect(), p = img.closest('aside').getBoundingClientRect();
    return r.left >= p.left && r.right <= p.right && r.top >= p.top && r.bottom <= p.bottom;
  });
  assert.ok(fits, 'Logo is not clipped');
}

async function noTips(page) { await page.waitForSelector('.tooltip', { state: 'detached' }); }
async function showTip(page, link, trigger = 'hover') {
  await page.mouse.move(900, 500); await link.evaluate(el => el.blur()); await noTips(page);
  if (trigger === 'focus') await link.focus(); else await link.hover();
  const tip = page.getByRole('tooltip'); await tip.waitFor({ state: 'visible' });
  const label = (await link.locator('.nav-text').textContent()).trim();
  assert.equal(await tip.locator('.tooltip-inner').textContent(), label, 'Tooltip is the exact plain-text nav label');
  assert.match(await tip.getAttribute('data-popper-placement'), /^right/);
  assert.equal(await link.getAttribute('aria-describedby'), await tip.getAttribute('id'));
  const anchor = await link.boundingBox();
  const visible = await tip.evaluate((el, anchorRight) => {
    const r = el.getBoundingClientRect(), rail = document.querySelector('#app-sidebar').getBoundingClientRect();
    // Bootstrap tooltips ignore pointer hit testing by default. Temporarily include this
    // surface in hit testing to check occlusion without changing its visual geometry.
    const pointerEvents = el.style.pointerEvents;
    el.style.pointerEvents = 'auto';
    const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    el.style.pointerEvents = pointerEvents;
    return { bodyContainer: el.parentElement === document.body,
      // Placement is relative to the inset nav link, not the outer rail edge.
      // A small overlap with rail padding is valid if the tooltip paints unclipped.
      rightOfAnchor: r.left >= anchorRight && r.right > rail.right,
      inViewport: r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight,
      unobscured: top === el || el.contains(top),
      textFits: el.querySelector('.tooltip-inner').scrollWidth <= el.querySelector('.tooltip-inner').clientWidth + 1,
      rect: { left: r.left, right: r.right, top: r.top, bottom: r.bottom }, railRight: rail.right,
      hit: top?.tagName + '.' + top?.className };
  }, anchor.x + anchor.width);
  assert.ok(visible.bodyContainer && visible.rightOfAnchor && visible.inViewport && visible.unobscured && visible.textFits,
    `Tooltip appears right of link, beyond rail, in viewport, unobscured and unclipped: ${JSON.stringify(visible)}`);
}

async function headerToggleLabel(page, label) {
  const toggle = page.locator('.header .sidebar-toggle');
  assert.equal(await toggle.getAttribute('title'), null, 'No native browser tooltip');
  assert.equal(await toggle.getAttribute('data-bs-toggle'), 'tooltip');
  assert.equal(await toggle.getAttribute('data-bs-placement'), 'bottom');
  assert.equal(await toggle.getAttribute('aria-label'), label);
  assert.equal(await toggle.getAttribute('data-bs-title'), label, 'Tooltip title follows the accessible action');
  assert.equal(await toggle.evaluate(el => !!bootstrap.Tooltip.getInstance(el)), true,
    'Real core/main.js generic initializer owns the header tooltip');
}

async function headerTip(page, trigger, label, allowFlip = false) {
  await page.mouse.move(900, 500);
  await trigger.evaluate(el => el.blur());
  await noTips(page);
  await trigger.hover();
  const tip = page.locator('.tooltip');
  await tip.waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('.tooltip.show')
    && getComputedStyle(document.querySelector('.tooltip')).opacity === '1');
  assert.equal(await tip.count(), 1, 'Only one Bootstrap tooltip is mounted');
  assert.equal(await tip.locator('.tooltip-inner').textContent(), label);
  assert.equal(await trigger.getAttribute('aria-describedby'), await tip.getAttribute('id'));
  // Popper may flip near the narrow drawer viewport; desktop has room below.
  assert.match(await tip.getAttribute('data-popper-placement'), allowFlip ? /^(bottom|top|left|right)/ : /^bottom/);
  return tip.evaluate(el => {
    const inner = getComputedStyle(el.querySelector('.tooltip-inner'));
    const arrow = getComputedStyle(el.querySelector('.tooltip-arrow'), '::before');
    return Object.fromEntries([
      ...['backgroundColor', 'color', 'borderRadius', 'padding', 'fontSize', 'fontWeight',
        'boxShadow', 'borderTopColor', 'borderTopWidth'].map(key => [key, inner[key]]),
      ['opacity', getComputedStyle(el).opacity], ['arrowColor', arrow.borderBottomColor]
    ]);
  });
}

async function iconPaint(link) {
  return link.evaluate(el => {
    const icon = el.querySelector('.nav-icon'), glyph = icon.querySelector('i');
    const paint = (node, pseudo) => {
      const style = getComputedStyle(node, pseudo);
      let opacity = Number(style.opacity);
      for (let parent = pseudo ? node : node.parentElement; parent; parent = parent.parentElement) {
        opacity *= Number(getComputedStyle(parent).opacity);
      }
      return { color: style.color, opacity, content: pseudo ? style.content : null };
    };
    return { foreground: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor,
      icon: paint(icon), glyph: paint(glyph), before: paint(glyph, '::before') };
  });
}

for (const theme of ['light', 'dark']) {
  test(`header toggle uses shared Bootstrap tooltip and updates after clicks: ${theme}`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, { theme });
    const toggle = page.locator('.header .sidebar-toggle');
    const themeTrigger = page.locator('.header-action-wrap').filter({ has: page.locator('.theme-toggle') });
    const sharedStyle = await headerTip(page, themeTrigger, 'Toggle Theme (Light / Dark mode)');
    assert.equal(sharedStyle.backgroundColor, theme === 'dark' ? 'rgb(42, 42, 42)' : 'rgb(30, 30, 30)');
    assert.equal(sharedStyle.color, 'rgb(255, 255, 255)');
    assert.equal(sharedStyle.opacity, '1');
    // Repeated real clicks exercise both cached tooltip content and stale/duplicate nodes.
    for (const collapsed of [false, true, false, true, false]) {
      await desktopState(page, collapsed);
      const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
      await headerToggleLabel(page, label);
      assert.deepEqual(await headerTip(page, toggle, label), sharedStyle, 'Same rendered styling as header theme tooltip');
      await toggle.click();
      await noTips(page);
      assert.equal(await toggle.getAttribute('aria-describedby'), null, 'Click clears tooltip association');
      await desktopState(page, !collapsed);
      await headerToggleLabel(page, collapsed ? 'Collapse sidebar' : 'Expand sidebar');
    }
  });

  test(`header tooltip mobile suppression and dynamic drawer labels at 767/768px: ${theme}`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, { width: 768, theme });
    const toggle = page.locator('.header .sidebar-toggle');
    await drawerState(page, false);
    await headerToggleLabel(page, 'Open navigation');
    await headerTip(page, toggle, 'Open navigation', true);
    await page.setViewportSize({ width: 767, height: 1000 });
    await noTips(page);
    for (const width of [767, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const open of [false, true]) {
        await drawerState(page, open);
        await headerToggleLabel(page, open ? 'Close navigation' : 'Open navigation');
        await page.mouse.move(width - 1, 980);
        // The open drawer covers the header toggle; only hover it when reachable.
        if (!open) await toggle.hover();
        // A real Bootstrap show attempt must be cancelled by the mobile guard,
        // not just absent because hover was already active at the breakpoint.
        assert.equal(await toggle.evaluate(el => {
          let showEvent;
          el.addEventListener('show.bs.tooltip', event => { showEvent = event; }, { once: true });
          bootstrap.Tooltip.getInstance(el).show();
          return showEvent?.defaultPrevented;
        }), true, 'Shared mobile guard cancels Bootstrap show event');
        await page.waitForTimeout(250);
        assert.equal(await page.locator('.tooltip').count(), 0, 'No tooltip mounted at <=767px');
        if (!open) await toggle.focus();
        await noTips(page);
        if (open) await page.keyboard.press('Escape'); else await toggle.click();
      }
      await drawerState(page, false);
    }
    await page.setViewportSize({ width: 768, height: 1000 });
    await headerTip(page, toggle, 'Open navigation', true);
    await toggle.click(); await drawerState(page, true); await noTips(page);
    await headerToggleLabel(page, 'Close navigation');
    // Do not immediately re-hover the opener when Escape uncovers the header.
    await page.mouse.move(767, 980);
    await page.keyboard.press('Escape'); await drawerState(page, false); await noTips(page);
    await headerToggleLabel(page, 'Open navigation');
  });

  for (const collapsed of [false, true]) {
    test(`sidebar icon glyphs remain opaque and active contrast survives: ${theme} ${collapsed ? 'collapsed' : 'expanded'}`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, { theme, saved: collapsed ? '1' : '0' });
      await desktopState(page, collapsed);
      const inactive = page.locator('.app-shell .sidebar-nav .nav-link:not(.active)');
      for (const family of ['ph-light', 'bi']) {
        const links = inactive.filter({ has: page.locator(`.nav-icon i.${family}`) });
        assert.ok(await links.count() > 0, `Real BMG fixture includes inactive ${family} icons`);
        for (const link of await links.all()) {
          for (const state of ['normal', 'hover', 'focus']) {
            await page.mouse.move(900, 500);
            await link.evaluate(el => el.blur());
            if (state === 'hover') await link.hover();
            if (state === 'focus') await link.focus();
            const paint = await iconPaint(link);
            const label = (await link.locator('.nav-text').textContent()).trim();
            for (const part of ['icon', 'glyph', 'before']) {
              assert.equal(paint[part].color, 'rgb(255, 255, 255)', `${label} ${family} ${state} ${part} RGB255 alpha1`);
              assert.equal(paint[part].opacity, 1, `${label} ${state} ${part} effective opacity`);
            }
            assert.ok(paint.before.content && !['none', 'normal', '""'].includes(paint.before.content), `${family} renders an actual pseudo glyph`);
          }
        }
      }
      const active = page.locator('.sidebar-nav .nav-link.active');
      assert.equal(await active.count(), 1);
      for (const state of ['normal', 'hover', 'focus']) {
        await page.mouse.move(900, 500); await page.locator(':focus').evaluateAll(elements => elements.forEach(el => el.blur()));
        if (state === 'hover') await active.hover();
        if (state === 'focus') await active.focus();
        const paint = await iconPaint(active);
        const foreground = theme === 'light' ? 'rgb(128, 0, 0)' : 'rgb(255, 255, 255)';
        assert.equal(paint.foreground, foreground, `Active ${state} foreground`);
        if (theme === 'light') assert.equal(paint.background, 'rgb(255, 255, 255)', `Active ${state} selected background`);
        else {
          const channels = paint.background.match(/[\d.]+/g).map(Number);
          assert.ok(channels.slice(0, 3).every(value => value < 128) && (channels[3] ?? 1) === 1,
            `Dark selected background remains opaque and dark: ${paint.background}`);
        }
        for (const part of ['icon', 'glyph', 'before']) {
          assert.equal(paint[part].color, foreground, `Active ${state} ${part} retains contrast`);
          assert.equal(paint[part].opacity, 1);
        }
      }
    });
  }

  for (const width of [992, 1440]) {
    test(`desktop rail geometry, brand and accessible navigation: ${width}px ${theme}`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, { width, theme });
      await desktopState(page, false); await brand(page, false);
      const link = page.getByRole('link', { name: 'Drum Rotation', exact: true });
      await link.hover(); await link.focus(); await noTips(page);
      await page.locator('.sidebar-toggle').click();
      await desktopState(page, true); await brand(page, true);
      const links = page.locator('.sidebar-nav a.nav-link');
      assert.ok(await links.count() > 3);
      for (const nav of await links.all()) {
        const label = (await nav.locator('.nav-text').textContent()).trim();
        assert.equal(await nav.getAttribute('aria-label'), label);
        assert.equal(await nav.and(page.getByRole('link', { name: label, exact: true })).count(), 1,
          `Hidden visual label retains accessible name on this link: ${label}`);
        assert.equal(await nav.locator('.nav-text').isVisible(), false);
        assert.equal(await nav.locator('.nav-icon i').isVisible(), true);
        assert.equal(await nav.evaluate(el => el.tabIndex), 0);
      }
      assert.equal(await link.getAttribute('aria-current'), 'page');
      await showTip(page, link); await showTip(page, link, 'focus');
      // Observe activation without replacing navigation or contacting application endpoints.
      await link.evaluate(el => el.addEventListener('click', event => {
        event.preventDefault(); window.railActivation = { href: el.href, trusted: event.isTrusted };
      }, { once: true }));
      await page.keyboard.press('Enter'); await noTips(page);
      assert.deepEqual(await page.evaluate(() => window.railActivation), { href: origin + '/facilities', trusted: true });
      await page.locator('.sidebar-toggle').click();
      await desktopState(page, false); await brand(page, false);
      await link.hover(); await link.focus(); await noTips(page);
    });
  }

  test(`rail tooltips dismiss on click, scroll, modal, expansion and mobile: ${theme}`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, { theme, saved: '1' });
    await desktopState(page, true);
    const link = page.getByRole('link', { name: 'Drum Rotation', exact: true });
    await showTip(page, link);
    await link.evaluate(el => el.addEventListener('click', event => event.preventDefault(), { once: true }));
    await link.click(); await noTips(page);
    await showTip(page, link, 'focus');
    await page.locator('.sidebar-nav').evaluate(el => { el.scrollTop += 50; });
    await noTips(page);
    await showTip(page, link);
    await page.evaluate(() => window.scrollBy(0, 100)); await noTips(page);
    await showTip(page, link, 'focus');
    await page.evaluate(() => bootstrap.Modal.getOrCreateInstance(document.querySelector('#logoutConfirmModal')).show());
    await page.getByRole('dialog', { name: 'Confirm Sign Out' }).waitFor(); await noTips(page);
    await page.keyboard.press('Escape');
    await page.locator('#logoutConfirmModal').waitFor({ state: 'hidden' });
    await showTip(page, link);
    await page.locator('.sidebar-toggle').click(); await desktopState(page, false); await noTips(page);
    await link.hover(); await link.focus(); await noTips(page);
    await page.locator('.sidebar-toggle').click(); await desktopState(page, true);
    await showTip(page, link);
    await page.setViewportSize({ width: 390, height: 1000 }); await noTips(page);
    await page.locator('.sidebar-toggle').click(); await drawerState(page, true);
    await link.hover(); await link.focus(); await noTips(page);
  });
}

async function drawerState(page, open) {
  await page.waitForFunction(open => {
    const sidebar = document.querySelector('#app-sidebar'), r = sidebar.getBoundingClientRect();
    return document.querySelector('.sidebar-toggle').getAttribute('aria-expanded') === String(open)
      && sidebar.inert === !open && sidebar.getAttribute('aria-hidden') === String(!open)
      && (open ? Math.abs(r.left) <= 1 && Math.abs(r.width - Math.min(280, innerWidth - 48)) <= 1 : r.right <= 1);
  }, open);
  assert.equal(await page.locator('.sidebar-overlay').isVisible(), open);
  assert.equal(await page.locator('.sidebar-toggle').getAttribute('aria-label'), open ? 'Close navigation' : 'Open navigation');
  assert.equal(await page.locator('#main').evaluate(el => parseFloat(getComputedStyle(el).marginLeft)), 0);
  assert.equal(await page.locator('.header').evaluate(el => el.getBoundingClientRect().left), 0);
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
}

for (const theme of ['light', 'dark']) {
  for (const width of [320, 390]) {
    test(`mobile drawer full brand, overlay, Escape and tab trap: ${width}px ${theme}`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, { width, theme, saved: '1' });
      await drawerState(page, false);
      assert.equal(await page.getByRole('link', { name: 'Drum Rotation', exact: true }).count(), 0, 'Closed drawer absent from accessibility tree');
      const toggle = page.locator('.sidebar-toggle');
      await toggle.click(); await drawerState(page, true); await brand(page, false);
      assert.equal(await page.locator('#app-sidebar').evaluate(el => el.contains(document.activeElement)), true,
        'Opening drawer moves focus inside navigation');
      assert.equal(await page.locator('body').evaluate(el => getComputedStyle(el).overflow), 'hidden');
      const controls = page.locator('#app-sidebar').locator('a[href], button:not([disabled])').filter({ visible: true });
      await controls.last().focus(); await page.keyboard.press('Tab');
      assert.equal(await controls.first().evaluate(el => el === document.activeElement), true, 'Tab wraps to first drawer control');
      await page.keyboard.press('Shift+Tab');
      assert.equal(await controls.last().evaluate(el => el === document.activeElement), true, 'Shift+Tab wraps to last drawer control');
      await page.getByRole('link', { name: 'Drum Rotation', exact: true }).focus(); await noTips(page);
      await page.keyboard.press('Escape'); await drawerState(page, false);
      assert.equal(await toggle.evaluate(el => el === document.activeElement), true, 'Escape restores opener focus');
      await toggle.click(); await drawerState(page, true);
      const r = await page.locator('#app-sidebar').boundingBox();
      await page.mouse.click((r.x + r.width + width) / 2, 980); await drawerState(page, false);
      assert.equal(await toggle.evaluate(el => el === document.activeElement), true, 'Overlay restores opener focus');
      await toggle.click(); await drawerState(page, true);
      await page.getByRole('button', { name: 'Close sidebar', exact: true }).click(); await drawerState(page, false);
      assert.equal(await page.evaluate(key => localStorage.getItem(key), storageKey), '1', 'Mobile drawer never overwrites desktop preference');
    });
  }
}

test('desktop preference persists through reload and the exact 991/992 breakpoint', { timeout: 30000 }, async t => {
  const page = await pageFor(t);
  await desktopState(page, false);
  await page.locator('.sidebar-toggle').click(); await desktopState(page, true);
  assert.equal(await page.evaluate(key => localStorage.getItem(key), storageKey), '1');
  await page.reload({ waitUntil: 'load' }); await desktopState(page, true);
  await page.setViewportSize({ width: 991, height: 1000 }); await drawerState(page, false);
  await page.locator('.sidebar-toggle').click(); await drawerState(page, true); await brand(page, false);
  await page.setViewportSize({ width: 992, height: 1000 }); await desktopState(page, true);
  assert.equal(await page.locator('body').evaluate(el => el.classList.contains('sidebar-open')), false);
  await page.locator('.sidebar-toggle').click(); await desktopState(page, false);
  assert.equal(await page.evaluate(key => localStorage.getItem(key), storageKey), '0');
  await page.reload({ waitUntil: 'load' }); await desktopState(page, false);
  await page.setViewportSize({ width: 991, height: 1000 }); await drawerState(page, false);
  await page.setViewportSize({ width: 992, height: 1000 }); await desktopState(page, false);
});

test('mobile load remembers desktop preference without collapsing the drawer', { timeout: 30000 }, async t => {
  const page = await pageFor(t, { width: 390, saved: '1' });
  await drawerState(page, false);
  await page.locator('.sidebar-toggle').click(); await drawerState(page, true); await brand(page, false);
  await page.setViewportSize({ width: 992, height: 1000 }); await desktopState(page, true);
});

for (const saved of ['0', '1']) {
  test(`sidebar-state restores saved=${saved} without core/main.js`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, { saved, prepaint: true });
    await desktopState(page, saved === '1', false); await brand(page, saved === '1');
    assert.equal(await page.locator('body').getAttribute('data-sidebar-collapsed'), String(saved === '1'));
    assert.equal(await page.locator('.sidebar-nav').getAttribute('data-scroll-restored'), 'true');
  });
}

test('disabled browser storage still permits rail and drawer interaction', { timeout: 30000 }, async t => {
  const page = await pageFor(t, { storageDisabled: true });
  await desktopState(page, false);
  await page.locator('.sidebar-toggle').click(); await desktopState(page, true);
  await page.setViewportSize({ width: 390, height: 1000 }); await drawerState(page, false);
  await page.locator('.sidebar-toggle').click(); await drawerState(page, true); await brand(page, false);
  await page.keyboard.press('Escape'); await drawerState(page, false);
  await page.setViewportSize({ width: 992, height: 1000 }); await desktopState(page, true);
  await page.locator('.sidebar-toggle').click(); await desktopState(page, false);
  await page.reload({ waitUntil: 'load' }); await desktopState(page, false);
});

for (const motion of ['reduce', 'no-preference']) {
  test(`rail transitions respect reduced motion: ${motion}`, { timeout: 30000 }, async t => {
    const page = await pageFor(t, { motion });
    const durations = await page.locator('#app-sidebar, .header, #main').evaluateAll(elements =>
      elements.map(el => getComputedStyle(el).transitionDuration.split(',').map(parseFloat)));
    assert.equal(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches), motion === 'reduce');
    if (motion === 'reduce') assert.ok(durations.flat().every(duration => duration === 0));
    else assert.ok(durations.every(values => values.some(duration => duration > 0)));
    await page.locator('.sidebar-toggle').click(); await desktopState(page, true);
    await page.locator('.sidebar-toggle').click(); await desktopState(page, false);
  });
}
