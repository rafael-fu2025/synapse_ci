'use strict';

// Real PHP shell and production assets only; no controllers, database, or device services.
// Run with: node --test tests/browser/mobile-layout.test.cjs
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
const widths = [320, 390, 768, 991, 992, 1440];
const fixturePath = '/facilities/units/11';
let server, browser, origin, rendered;

function realLayout() {
  if (rendered === undefined) {
    const result = spawnSync('php', ['tests/_support/render-bmg-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: fixturePath, kind: 'unit_detail' }),
      encoding: 'utf8', timeout: 20000, maxBuffer: 8 * 1024 * 1024
    });
    assert.equal(result.status, 0, `Real BMG layout fixture failed: ${result.error || result.stderr || result.stdout}`);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
    assert.match(result.stdout, /<!DOCTYPE html>/i);
    rendered = result.stdout;
  }
  return rendered;
}

before(async () => {
  server = http.createServer((req, res) => {
    try {
      if (req.method !== 'GET') { res.writeHead(405); res.end('Read-only mobile layout fixture'); return; }
      const url = new URL(req.url, origin);
      if (url.pathname === fixturePath) {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(realLayout()); return;
      }
      if (!url.pathname.startsWith('/assets/')) { res.writeHead(404); res.end(); return; }
      const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
      const assets = path.join(root, 'public', 'assets') + path.sep;
      if (!file.startsWith(assets) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        res.writeHead(404); res.end(); return;
      }
      const types = {
        '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml',
        '.png': 'image/png', '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2'
      };
      res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
      res.end(fs.readFileSync(file));
    } catch (error) { res.writeHead(500); res.end(String(error.stack)); }
  });
  server.requestTimeout = 10000;
  server.headersTimeout = 10000;
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(dir) && fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  assert.ok(executablePath, 'Install Chromium in writable/widget-test-tools before running mobile layout tests');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });

after(async () => {
  await browser?.close();
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

async function pageFor(t, width, theme) {
  const context = await browser.newContext({
    viewport: { width, height: 1000 }, colorScheme: theme,
    reducedMotion: 'reduce', serviceWorkers: 'block'
  });
  t.after(() => context.close());
  await context.addInitScript(theme => {
    localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
    document.documentElement?.setAttribute('data-theme', theme);
    document.addEventListener('DOMContentLoaded', () => {
      document.documentElement.setAttribute('data-theme', theme);
      document.body.classList.toggle('dark-mode', theme === 'dark');
    });
  }, theme);
  await context.route('**/*', route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin !== origin) return route.fulfill({ status: 200, body: '' });
    if (req.method() !== 'GET' || (!url.pathname.startsWith('/assets/') && url.pathname !== fixturePath)) {
      return route.fulfill({ status: 404, body: 'No application endpoints in mobile layout fixtures' });
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000); page.setDefaultNavigationTimeout(20000);
  const response = await page.goto(origin + fixturePath, { waitUntil: 'load' });
  assert.equal(response.status(), 200, await response.text());
  await page.evaluate(() => document.fonts.ready);
  const sheets = await page.evaluate(() => {
    const paths = [];
    function visit(sheet) {
      if (sheet.href && new URL(sheet.href).origin !== location.origin) return;
      if (sheet.href) paths.push(new URL(sheet.href).pathname);
      for (const rule of sheet.cssRules) if (rule.styleSheet) visit(rule.styleSheet);
    }
    [...document.styleSheets].forEach(visit);
    return paths;
  });
  for (const file of ['vendor/bootstrap/css/bootstrap.min.css', 'css/style.css', 'css/core/synapse-theme.css']) {
    assert.ok(sheets.includes('/assets/' + file), `Production stylesheet must load: ${file}`);
  }
  assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
  return page;
}

async function assertFits(page, selector) {
  const result = await page.locator(selector).evaluateAll(elements => {
    const viewport = document.documentElement.clientWidth;
    const visible = elements.filter(el => {
      const style = getComputedStyle(el), rect = el.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    });
    return {
      count: visible.length,
      overflow: visible.flatMap(el => {
        const rect = el.getBoundingClientRect();
        return rect.left < -1 || rect.right > viewport + 1
          ? [{ element: el.tagName + '.' + el.className, left: rect.left, right: rect.right, viewport }] : [];
      })
    };
  });
  assert.ok(result.count > 0, `Expected visible elements matching ${selector}`);
  assert.deepEqual(result.overflow, [], `${selector} must fit horizontally in the viewport`);
}

async function assertNoOverflow(page) {
  const size = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth
  }));
  assert.ok(Math.max(size.document, size.body) <= size.viewport + 1,
    `Document must not overflow horizontally: ${JSON.stringify(size)}`);
}

for (const theme of ['light', 'dark']) {
  test(`Tables become labeled cards on mobile and remain tables on desktop (${theme})`, async t => {
    const page = await pageFor(t, 390, theme);
    await page.locator('#main .section').evaluate(section => {
      section.innerHTML = `<div class="table-responsive"><table class="table" id="card-test">
        <thead><tr><th>Name</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody><tr><th scope="row">Patient name</th><td>Active</td><td><button type="button">Review</button></td></tr></tbody>
      </table></div>`;
    });
    await page.waitForSelector('#card-test.table-mobile-cards');
    assert.deepEqual(await page.locator('#card-test tbody tr > *').evaluateAll(cells =>
      cells.map(cell => cell.dataset.label)), ['Name', 'Status', 'Actions']);
    assert.equal(await page.locator('#card-test tbody tr').evaluate(el => getComputedStyle(el).display), 'block');
    assert.equal(await page.locator('#card-test tbody td').first().evaluate(el => getComputedStyle(el, '::before').content), '"Status"');
    await page.getByRole('button', { name: 'Review', exact: true }).click();
    await assertNoOverflow(page);

    // Live-result replacement and empty colspan rows also receive the card treatment.
    await page.locator('#card-test tbody').evaluate(body => {
      body.innerHTML = '<tr><td colspan="3">No matching records.</td></tr>';
    });
    assert.equal(await page.locator('#card-test tbody td').getAttribute('data-label'), null);
    await page.locator('#card-test tbody').evaluate(body => {
      body.innerHTML = '<tr><td>New patient</td><td>Pending</td><td><button>Open record</button></td></tr>';
    });
    await page.waitForFunction(() => document.querySelector('#card-test tbody td')?.dataset.label === 'Name');
    await page.setViewportSize({ width: 1440, height: 1000 });
    assert.equal(await page.locator('#card-test').evaluate(el => getComputedStyle(el).display), 'table');
    assert.equal(await page.locator('#card-test thead').evaluate(el => getComputedStyle(el).position), 'static');
  });
}

for (const theme of ['light', 'dark']) {
  for (const width of [320, 768, 992, 1366, 1440]) {
    test(`page-header actions share one size across modules: ${width}px ${theme}`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, width, theme);
      const rem = await page.locator('html').evaluate(el => parseFloat(getComputedStyle(el).fontSize));
      const views = fs.readdirSync(path.join(root, 'app/Views'), { recursive: true })
        .filter(name => name.endsWith('.php'))
        .filter(name => fs.readFileSync(path.join(root, 'app/Views', name), 'utf8').includes("section('page_actions')"));
      for (const view of views) {
        const source = fs.readFileSync(path.join(root, 'app/Views', view), 'utf8');
        const section = source.match(/section\('page_actions'\)[^]*?\?>([^]*?)<\?= \$this->endSection\(\)/);
        assert.ok(section, `${view} must provide the production page-action markup`);
        const markup = section[1].replace(/<\?[^]*?\?>/g, '');
        await page.locator('.page-actions').evaluate((el, html) => { el.innerHTML = html; }, markup);
        const buttons = await page.locator('.page-actions .btn').evaluateAll(els => els.map(el => {
          const css = getComputedStyle(el), rect = el.getBoundingClientRect();
          return { label: el.textContent.trim(), font: css.fontSize, paddingX: css.paddingLeft, paddingY: css.paddingTop,
            minHeight: css.minHeight, height: rect.height, disabled: el.disabled };
        }));
        assert.ok(buttons.length > 0, `${view} must exercise at least one action`);
        for (const button of buttons) {
          assert.ok(Math.abs(parseFloat(button.font) - rem * 0.9375) < 0.01, `${view}: ${button.label} font size`);
          assert.ok(Math.abs(parseFloat(button.paddingX) - rem * 0.95) < 0.01, `${view}: horizontal padding`);
          assert.ok(Math.abs(parseFloat(button.paddingY) - rem * 0.45) < 0.01, `${view}: vertical padding`);
          assert.equal(button.minHeight, width < 768 ? '44px' : '38px', `${view}: minimum action height`);
          if (width >= 768) assert.equal(button.height, 38, `${view}: ${button.label} must have the exact header height`);
          else assert.ok(button.height >= 44);
        }
        if (view === 'announcements/index.php') {
          const draft = page.locator('.page-actions a[data-app-modal-open]');
          assert.equal(await draft.locator('i.bi-plus-lg[aria-hidden="true"]').count(), 1);
          assert.equal(await draft.textContent(), 'New draft');
        }

        await assertFits(page, '.page-actions .btn');
        await assertNoOverflow(page);
      }
      await page.locator('.page-actions').evaluate(el => {
        el.innerHTML = '<button class="btn btn-primary btn-sm" disabled>Small</button><button class="btn btn-primary">Normal</button><button class="btn btn-primary btn-lg"><i class="bi bi-plus-lg fs-3" aria-hidden="true"></i>Large</button>';
      });
      const heights = await page.locator('.page-actions .btn').evaluateAll(els => els.map(el => el.getBoundingClientRect().height));
      assert.deepEqual(heights, Array(3).fill(width < 768 ? 44 : 38), 'Legacy size classes must not change header action height');
      assert.equal(await page.locator('.page-actions .btn').first().isDisabled(), true);
      const outside = await page.locator('#main .section').evaluate(el => {
        el.innerHTML = '<button class="btn btn-primary btn-sm">Row action</button><button class="btn btn-primary">Normal action</button>';
        return [...el.querySelectorAll('.btn')].map(button => getComputedStyle(button).minHeight);
      });
      assert.deepEqual(outside, ['32px', '38px'], 'Non-header sizes stay independent');
    });
  }
}

test('Tooltips are suppressed on mobile, including new content and viewport changes', async t => {
  const page = await pageFor(t, 390, 'light');
  await page.locator('#main .section').evaluate(section => {
    section.innerHTML = '<button id="tooltip-test" data-bs-toggle="tooltip" title="Tooltip example">Action</button>';
    window.SynapsePageLifecycle.init(section);
    bootstrap.Tooltip.getInstance(document.querySelector('#tooltip-test')).show();
  });
  assert.equal(await page.locator('.tooltip').count(), 0);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.locator('#tooltip-test').evaluate(el => bootstrap.Tooltip.getInstance(el).show());
  await page.waitForSelector('.tooltip.show');
  await page.setViewportSize({ width: 390, height: 1000 });
  await page.waitForSelector('.tooltip', { state: 'detached' });
  await page.locator('#tooltip-test').evaluate(el => bootstrap.Tooltip.getInstance(el).show());
  assert.equal(await page.locator('.tooltip').count(), 0);
});

async function sidebarState(page, expanded) {
  // Wait for geometry as well as ARIA so transitions cannot make assertions race.
  await page.waitForFunction(expanded => {
    const sidebar = document.querySelector('#app-sidebar');
    const toggle = document.querySelector('.sidebar-toggle');
    if (!sidebar || !toggle || toggle.getAttribute('aria-expanded') !== String(expanded)) return false;
    const rect = sidebar.getBoundingClientRect(), style = getComputedStyle(sidebar);
    const shown = style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity) > 0;
    if (innerWidth >= 992) {
      return shown && rect.left >= -1 && Math.abs(rect.width - (expanded ? 280 : 72)) <= 1
        && !sidebar.inert && sidebar.getAttribute('aria-hidden') === 'false';
    }
    return expanded
      ? shown && rect.width > 0 && rect.left >= -1 && rect.right <= innerWidth + 1
      : (!shown || rect.right <= 1 || rect.left >= innerWidth - 1)
        && sidebar.inert && sidebar.getAttribute('aria-hidden') === 'true';
  }, expanded);
}

for (const theme of ['light', 'dark']) {
  for (const width of widths) {
    test(`shared shell: ${width}px ${theme}`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, width, theme);
      assert.equal(await page.locator('body.app-shell').count(), 1, 'Shared layout must opt into app-shell');
      assert.equal(await page.locator('#app-sidebar').count(), 1, 'Shared sidebar must have a stable ID');
      const toggle = page.locator('.sidebar-toggle');
      assert.equal(await toggle.count(), 1, 'Expected one shared sidebar toggle');
      assert.equal(await toggle.getAttribute('aria-controls'), 'app-sidebar');
      await assertNoOverflow(page);
      await assertFits(page, '.header > *');
      await assertFits(page, '.header .header-action, .header .user-trigger');
      const account = page.locator('.header .user-trigger');
      const accountName = (await account.locator('.user-name').textContent()).trim();
      assert.ok(accountName.length > 0, 'Account pill must show a display name');
      assert.equal(accountName, (await page.locator('.user-menu-name').textContent()).trim());
      assert.equal(await account.locator('.user-email').count(), 0, 'Email belongs in the dropdown, not the pill');
      assert.equal(await account.locator('.user-avatar').count(), 1);
      assert.equal(await account.locator('.user-role').count(), 1);
      const pill = await account.evaluate(el => {
        const style = getComputedStyle(el);
        return { height: el.getBoundingClientRect().height, radius: parseFloat(style.borderTopLeftRadius), border: parseFloat(style.borderTopWidth) };
      });
      assert.ok(pill.radius >= pill.height / 2, 'Account holder must have pill-shaped corners');
      assert.ok(pill.border >= 1, 'Account holder must have a visible boundary');
      const themeControl = await page.locator('.header .header-action.theme-toggle').boundingBox();
      assert.ok(Math.abs(pill.height - themeControl.height) <= 1, 'Account pill must match the header control height');
      if (width < 768) {
        const alignment = await account.evaluate(el => {
          const holder = el.getBoundingClientRect();
          const avatar = el.querySelector('.user-avatar').getBoundingClientRect();
          return {
            size: avatar.width,
            x: Math.abs(avatar.left + avatar.width / 2 - holder.left - holder.width / 2),
            y: Math.abs(avatar.top + avatar.height / 2 - holder.top - holder.height / 2)
          };
        });
        assert.equal(alignment.size, 32, 'Mobile avatar must fill the holder proportionately');
        assert.ok(alignment.x <= 1 && alignment.y <= 1, 'Mobile avatar must be centered in both axes');
      }
      await sidebarState(page, width >= 992);
      if (width < 992) {
        const overlay = page.locator('.sidebar-overlay');
        await toggle.click();
        await sidebarState(page, true);
        await overlay.waitFor({ state: 'visible' });
        await assertNoOverflow(page);
        // Click an exposed backdrop point, not the portion behind the sidebar.
        const point = await page.locator('#app-sidebar').evaluate(el => ({
          x: (el.getBoundingClientRect().right + innerWidth) / 2,
          y: innerHeight - 20
        }));
        await page.mouse.click(point.x, point.y);
        await sidebarState(page, false);
        await overlay.waitFor({ state: 'hidden' });
        await toggle.click();
        await sidebarState(page, true);
        await overlay.waitFor({ state: 'visible' });
        await page.keyboard.press('Escape');
        await sidebarState(page, false);
        await overlay.waitFor({ state: 'hidden' });
      } else {
        const margin = await page.locator('#main').evaluate(el => parseFloat(getComputedStyle(el).marginLeft));
        assert.ok(margin > 0, `Expanded desktop sidebar must reserve main margin, got ${margin}`);
        await toggle.click();
        await sidebarState(page, false);
        await page.waitForFunction(() => Math.abs(parseFloat(getComputedStyle(document.querySelector('#main')).marginLeft) - 72) <= 1);
        await assertNoOverflow(page);
        await toggle.click();
        await sidebarState(page, true);
        await page.waitForFunction(() => Math.abs(parseFloat(getComputedStyle(document.querySelector('#main')).marginLeft) - 280) <= 1);
      }
      await assertNoOverflow(page);
      await assertFits(page, '.header > *');
    });

    test(`long page title and actions: ${width}px ${theme}`, { timeout: 30000 }, async t => {
      const page = await pageFor(t, width, theme);
      assert.equal(await page.locator('.page-title-copy').count(), 1, 'Expected shared page title copy container');
      assert.equal(await page.locator('.page-actions').count(), 1, 'Expected shared page action container');
      await page.locator('.page-title-copy h1').evaluate(el => {
        el.textContent = 'Mechanized composting operations, maintenance schedules, and facility performance reporting';
      });
      await page.locator('.page-actions').evaluate(el => {
        el.replaceChildren(...[
          'Download detailed facility performance report',
          'Schedule preventive maintenance for this facility',
          'Review all pending composting batch approvals',
          'Export historical operations and monitoring records'
        ].map(label => {
          const button = document.createElement('button');
          button.type = 'button'; button.className = 'btn btn-primary'; button.textContent = label;
          return button;
        }));
      });
      await assertNoOverflow(page);
      await assertFits(page, '.page-title-copy, .page-actions, .page-actions .btn');
      const actions = await page.locator('.page-actions').evaluate(el => {
        const area = el.getBoundingClientRect();
        return {
          clientWidth: el.clientWidth, scrollWidth: el.scrollWidth,
          buttons: [...el.querySelectorAll('button')].map(button => {
            const rect = button.getBoundingClientRect();
            return { left: rect.left - area.left, right: rect.right - area.right, height: rect.height };
          })
        };
      });
      assert.ok(actions.scrollWidth <= actions.clientWidth + 1, `Action area must not overflow: ${JSON.stringify(actions)}`);
      assert.equal(actions.buttons.length, 4);
      for (const button of actions.buttons) {
        assert.ok(button.left >= -1 && button.right <= 1, `Button must fit inside action area: ${JSON.stringify(button)}`);
        if (width <= 767) assert.ok(button.height >= 44, `Mobile action target must be at least 44px tall: ${JSON.stringify(button)}`);
      }
    });
  }
}
