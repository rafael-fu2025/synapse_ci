'use strict';

// Read-only local fixtures: production CSS/JS and CLI-rendered PHP views; no DB or hardware.
// Run with: node --test tests/browser/theme-contrast.test.cjs
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
const variants = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
const buttonClasses = ['btn', ...variants.flatMap(v => [`btn btn-${v}`, `btn btn-outline-${v}`]), 'btn btn-link'];
let server, browser, origin;
const rendered = new Map();

function matrix() {
  const buttons = buttonClasses.map((cls, i) => `<div class="d-flex flex-wrap gap-2 mb-2">
    <button id="button-${i}" type="button" class="${cls}" data-enabled>${cls}</button>
    <button type="button" class="${cls}" disabled data-disabled>Disabled ${cls}</button>
    <a class="${cls} disabled" href="#disabled" aria-disabled="true" tabindex="-1" data-disabled>Disabled link</a>
    <fieldset disabled><button type="button" class="${cls}" data-disabled>Disabled fieldset</button></fieldset>
  </div>`).join('');
  const controls = ['checkbox', 'radio'].flatMap(type => ['', 'form-check-input'].flatMap((cls, i) =>
    ['unchecked', 'checked', ...(type === 'checkbox' ? ['indeterminate'] : [])].flatMap(state => [false, true].map(disabled => {
      const id = `${type}-${i}-${state}-${disabled ? 'disabled' : 'enabled'}`;
      return `<div class="form-check mb-2"><input id="${id}" type="${type}" class="${cls}" name="${id}"
        ${state === 'checked' ? 'checked' : ''} ${disabled ? 'disabled' : ''} data-state="${state}" data-control>
        <label class="form-check-label" for="${id}">${cls || 'Native'} ${type} ${state}${disabled ? ' disabled' : ''}</label></div>`;
    })))).join('');
  const rows = variants.map(v => `<tr><td><span class="badge badge-soft-${v}" data-table-text>Soft ${v}</span></td>
    <td><span class="badge bg-${v}-subtle text-${v}" data-table-text>Subtle ${v}</span></td>
    <td><a href="#table" class="text-${v}" data-table-text>${v} link</a></td>
    <td><button type="button" class="btn btn-sm btn-outline-${v}" data-enabled>Table ${v}</button></td></tr>`).join('');
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Theme contrast matrix</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/bmg.css">
    </head><body><main class="container-fluid p-3">
    <h1 class="h4">Production theme contrast matrix</h1>
    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="#home">Home</a></li>
      <li class="breadcrumb-item active" aria-current="page">Contrast matrix</li></ol></nav>
    <section class="card"><div class="card-body p-3">${buttons}
          <button class="dp-foot-btn" type="button" data-enabled>Today</button>
          <button class="dtp-foot-btn" type="button" data-enabled>Clear time</button>
          <button class="dtp-foot-btn dtp-btn-apply" type="button" data-enabled>Apply time</button>
          <button class="dp-nav" type="button" data-enabled aria-label="Previous month">‹</button>
          <button class="dtp-nav" type="button" data-enabled aria-label="Next month">›</button>
        </div></section>
    <section class="card"><div class="card-body p-3">
      <button class="btn btn-primary" data-brand-control><i class="bi bi-calendar text-primary" data-brand-content>Icon</i><span class="text-muted" data-brand-content>Muted label</span><strong class="text-dark" data-brand-content>Dark label</strong></button>
      <nav class="nav nav-pills"><a class="nav-link active" href="#pills" data-brand-control><i class="bi bi-calendar text-primary" data-brand-content>Icon</i><span class="text-primary" data-brand-content>Selected pill</span></a></nav>
      <div class="availability-workspace"><nav class="av-section-nav"><a href="#weekly" class="av-section-link is-active" data-brand-control>Weekly schedules</a></nav></div>
      <div class="portal-engagement"><nav class="pe-tabs"><a href="#feed" class="pe-tab is-active" data-brand-control>Feed<span class="pe-tab-count" data-brand-content>2</span></a></nav></div>
      <div class="portal-engagement"><div class="pe-header-icon" data-accent-icon><i class="bi bi-calendar"></i></div><div class="pe-department"><i class="bi bi-calendar" data-accent-icon>Department</i></div><div class="pe-privacy"><i class="bi bi-calendar" data-accent-icon>Privacy</i></div></div>
      <div class="widget-stat-icon primary" data-accent-icon>Stat</div><div class="activity-icon primary" data-accent-icon>Activity</div>
      <div class="nd-kpi-icon" data-accent-icon>KPI</div><div class="metric-card"><div class="metric-icon bg-primary-soft" data-accent-icon>Metric</div></div>
      <div class="quickaccess-item"><i class="bi bi-calendar" data-accent-icon>Quick access</i></div><i class="bi bi-calendar text-primary" data-accent-icon>Primary icon</i>
            <div class="guidance-case-workspace"><div class="gc-person-icon" data-accent-icon>Person</div></div>
            <div class="team-chat-channel"><i class="bi bi-calendar" data-accent-icon>Channel</i></div>
            <div class="dp-wrap dp-open"><i class="dp-icon bi bi-calendar" data-accent-icon>Open date</i></div>
            <div class="dtp-wrap dtp-open"><i class="dtp-icon bi bi-clock" data-accent-icon>Open time</i></div>
    </div></section>
    <section class="card"><div class="card-body p-3">
      <span class="teams-eyebrow" data-dark-accent-text>Work together · Guidance</span>
      <span class="teams-members" data-dark-accent-text>Members</span>
      <span class="teams-priority" data-dark-accent-text>Normal</span>
      <h3 class="teams-task-title"><a href="#task" data-dark-accent-text>Task title</a></h3>
      <a class="team-chat-channel" href="#chat" data-dark-accent-text>Guidance operations</a>
      <span class="team-chat-mention" data-dark-accent-text>@Member</span>
      <span class="team-chat-unread-divider" data-dark-accent-text>Unread</span>
      <div class="team-calendar-day-heading"><time aria-current="date" data-dark-accent-text>26</time></div>
      <a class="team-calendar-item" href="#event" data-dark-accent-text>9:00 AM Guidance meeting</a>
      <a class="team-calendar-item is-task is-complete" href="#due" data-dark-accent-text>Due · Done</a>
      <a class="nd-inline-link" href="#dashboard" data-dark-accent-text>Dashboard link</a>
      <table><tbody><tr><td class="dp-cell is-today"><span class="dp-day" data-dark-accent-text>26</span></td>
      <td class="dtp-cell is-today"><span class="dtp-day" data-dark-accent-text>26</span></td></tr></tbody></table>
      <button class="dtp-wheel-item" data-dark-accent-text>09</button>
    </div></section>
    <section class="card"><div class="card-body p-3">${controls}
      <label for="autofill">Email address</label><input id="autofill" class="form-control" name="email" autocomplete="email" value="fixture@example.test">
      <label for="neutral-text">Text</label><input id="neutral-text" class="form-control" value="Neutral text">
      <label for="neutral-select">Select</label><select id="neutral-select" class="form-select"><option>Neutral select</option></select>
      <label for="neutral-area">Notes</label><textarea id="neutral-area" class="form-control">Neutral notes</textarea>
    </div></section>
    <div class="table-responsive"><table class="table table-striped table-hover"><caption>Theme tags and actions</caption>
      <thead><tr><th>Soft tag</th><th>Subtle tag</th><th>Link</th><th>Action</th></tr></thead><tbody>${rows}
      <tr><td><span class="text-primary" data-table-text>Primary text</span></td><td><a href="#plain" data-table-text>Plain table link</a></td>
      <td><button class="btn btn-link" type="button" data-enabled>Link action</button></td><td><button class="btn btn-primary btn-sm" type="button" data-enabled>Primary action</button></td></tr>
    </tbody></table></div></main></body></html>`;
}

function realLayout() {
  if (!rendered.has('bmg')) {
    const result = spawnSync('php', ['tests/_support/render-bmg-fixture.php'], {
      cwd: root, input: JSON.stringify({ origin, path: '/facilities/units/11', kind: 'unit_detail' }),
      encoding: 'utf8', timeout: 20000, maxBuffer: 8 * 1024 * 1024
    });
    assert.equal(result.status, 0, `Real BMG layout fixture failed: ${result.error || result.stderr || result.stdout}`);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:|Parse error|"code"\s*:\s*500/);
    assert.match(result.stdout, /<!DOCTYPE html>/i);
    rendered.set('bmg', result.stdout);
  }
  return rendered.get('bmg');
}

before(async () => {
  fs.mkdirSync(path.join(root, 'writable/theme-contrast-preview'), { recursive: true });
  server = http.createServer((req, res) => {
    try {
      if (req.method !== 'GET') { res.writeHead(405); res.end('Read-only contrast fixture'); return; }
      const url = new URL(req.url, origin);
      if (url.pathname === '/contrast' || url.pathname === '/facilities/units/11') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(url.pathname === '/contrast' ? matrix() : realLayout()); return;
      }
      if (!url.pathname.startsWith('/assets/')) { res.writeHead(404); res.end(); return; }
      const file = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
      const assets = path.join(root, 'public', 'assets') + path.sep;
      if (!file.startsWith(assets) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        res.writeHead(404); res.end(); return;
      }
      const types = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.woff2': 'font/woff2' };
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
  assert.ok(executablePath, 'Install Chromium in writable/widget-test-tools before running contrast tests');
  browser = await chromium.launch({ headless: true, executablePath, timeout: 20000 });
}, { timeout: 30000 });
after(async () => {
  await browser?.close();
  if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

async function pageFor(t, theme, mobile, real = false) {
  const context = await browser.newContext({
    viewport: { width: mobile ? 390 : 1440, height: mobile ? 844 : 1000 },
    colorScheme: theme, reducedMotion: 'reduce', serviceWorkers: 'block'
  });
  t.after(() => context.close());
  await context.addInitScript(theme => {
    localStorage.setItem('na-dark-mode', theme === 'dark' ? '1' : '0');
    document.documentElement?.setAttribute('data-theme', theme);
    document.addEventListener('DOMContentLoaded', () => {
      document.documentElement.setAttribute('data-theme', theme);
      document.body.classList.toggle('dark-mode', theme === 'dark');
      document.querySelectorAll('[data-state="indeterminate"]').forEach(el => { el.indeterminate = true; });
    });
  }, theme);
  // Never contact app endpoints, external fonts, a database, or a device service.
  await context.route('**/*', route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin !== origin) return route.fulfill({ status: 200, body: '' });
    if (req.method() !== 'GET' || (!url.pathname.startsWith('/assets/') && !['/contrast', '/facilities/units/11'].includes(url.pathname))) {
      return route.fulfill({ status: 404, body: 'No application endpoints in contrast fixtures' });
    }
    return route.continue();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(5000); page.setDefaultNavigationTimeout(20000);
  const response = await page.goto(origin + (real ? '/facilities/units/11' : '/contrast'), { waitUntil: 'load' });
  assert.equal(response.status(), 200, await response.text());
  await page.evaluate(() => document.fonts.ready);
  const css = await page.evaluate(() => {
    const paths = [];
    function visit(sheet) {
      if (sheet.href && new URL(sheet.href).origin !== location.origin) return;
      if (sheet.href) paths.push(new URL(sheet.href).pathname);
      for (const rule of sheet.cssRules) if (rule.styleSheet) visit(rule.styleSheet);
    }
    [...document.styleSheets].forEach(visit);
    return paths;
  });
  for (const file of ['vendor/bootstrap/css/bootstrap.min.css', 'css/style.css', 'css/core/synapse-theme.css', 'css/teams.css', 'css/bmg.css']) {
    assert.ok(css.includes('/assets/' + file), `Production stylesheet must load: ${file}`);
  }
  await installMetrics(page);
  return page;
}

test('all dashboard donut centers remain readable across live and legacy theme changes', { timeout: 30000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });
  await page.addScriptTag({ url: origin + '/assets/vendor/apexcharts/apexcharts.min.js' });
  const chartIds = ['clinicTriageChart', 'adminPopulationChart', 'guidanceCategoryChart', 'bmgStatusChart', 'futureDashboardChart'];
  await page.evaluate(async ids => {
    await Promise.all(ids.map(async id => {
      const card = document.createElement('section');
      card.className = 'card';
      card.style.color = 'var(--default-color)';
      const target = document.createElement('div');
      target.id = id;
      card.append(target);
      document.querySelector('main').prepend(card);
      await new ApexCharts(target, {
        chart: { type: 'donut', height: 230, animations: { enabled: false } },
        series: [2, 1], labels: ['First', 'Second'],
        dataLabels: { enabled: false }, legend: { show: false },
        plotOptions: { pie: { donut: { size: '72%', labels: {
          show: true, name: { show: true, fontSize: '12px' },
          value: { show: true, fontSize: '18px', fontWeight: 700 },
          total: { show: true, label: 'Total' }
        } } } }
      }).render();
    }));
  }, chartIds);

  for (const theme of ['light', 'dark', 'light', 'legacy-dark']) {
    await page.evaluate(theme => {
      if (theme === 'legacy-dark') document.documentElement.removeAttribute('data-theme');
      else document.documentElement.setAttribute('data-theme', theme);
      document.body.classList.toggle('dark-mode', theme === 'legacy-dark');
    }, theme);
    const centers = await page.evaluate(ids => {
      const rgb = color => color.match(/[\d.]+/g).slice(0, 3).map(Number);
      const luminance = color => rgb(color).map(v => v / 255)
        .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
        .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
      return ids.flatMap(id => [...document.querySelectorAll(`#${id} .apexcharts-datalabel-label, #${id} .apexcharts-datalabel-value`)].map(el => {
        const card = el.closest('.card');
        const fill = getComputedStyle(el).fill;
        const background = getComputedStyle(card).backgroundColor;
        const a = luminance(fill), b = luminance(background);
        return { id, fill, expected: getComputedStyle(card).color, ratio: (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05) };
      }));
    }, chartIds);
    assert.equal(centers.length, chartIds.length * 2, `Both center texts must render in ${theme}`);
    for (const center of centers) {
      assert.equal(center.fill, center.expected, `${center.id} center must follow ${theme} theme tokens`);
      assert.ok(center.ratio >= 4.5, `${center.id} center contrast in ${theme}: ${center.ratio.toFixed(2)}:1`);
    }
  }
});

// Compute WCAG contrast from resolved styles, not tokens or snapshots. Composite alpha,
// ancestor opacity, and Bootstrap's enormous inset table-cell shadow before measuring.
async function installMetrics(page) {
  await page.evaluate(() => {
    const rgba = value => {
      if (value === 'transparent') return [0, 0, 0, 0];
      const srgb = value.match(/^color\(srgb\s+([^)]+)\)/);
      if (srgb) {
        const values = srgb[1].split(/[\s/]+/).map(Number);
        return [values[0] * 255, values[1] * 255, values[2] * 255, values[3] ?? 1];
      }
      const m = value.match(/^rgba?\(([^)]+)\)/);
      if (!m) throw new Error(`Unsupported resolved color: ${value}`);
      const values = m[1].split(/[\s,/]+/).map(Number);
      return [values[0], values[1], values[2], values[3] ?? 1];
    };
    const over = (a, b) => {
      const alpha = a[3] + b[3] * (1 - a[3]);
      return alpha ? [...a.slice(0, 3).map((v, i) => (v * a[3] + b[i] * b[3] * (1 - a[3])) / alpha), alpha] : [0, 0, 0, 0];
    };
    const luminance = color => color.slice(0, 3).map(v => v / 255).map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
      .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);
    function layer(el) {
      const style = getComputedStyle(el);
      let bg = rgba(style.backgroundColor);
      // Table tint and autofill use an inset spread, not background-color.
      for (const shadow of style.boxShadow.match(/rgba?\([^)]+\)[^,]*/g) || []) {
        if (shadow.includes('inset') && /(?:1000|9999)px/.test(shadow)) bg = over(rgba(shadow), bg);
      }
      return bg;
    }
    function pixel(el, color, includeSelf = true) {
      let result = color;
      for (let node = includeSelf ? el : el.parentElement; node; node = node.parentElement) {
        result = over(result, layer(node));
        result[3] *= Number(getComputedStyle(node).opacity);
      }
      return over(result, [255, 255, 255, 1]);
    }
    function measure(el, pseudo) {
      const style = getComputedStyle(el, pseudo || null);
      const fg = rgba(style.webkitTextFillColor && style.webkitTextFillColor !== 'currentcolor' ? style.webkitTextFillColor : style.color);
      // Pseudo-elements inherit opacity from their originating element.
      if (pseudo) fg[3] *= Number(style.opacity);
      const background = pixel(el, [0, 0, 0, 0]);
      const foreground = pixel(el, fg);
      const rect = el.getBoundingClientRect();
      return {
        element: el.id || `${el.tagName.toLowerCase()}.${String(el.className).trim().replace(/\s+/g, '.')}`,
        text: el.textContent.trim().slice(0, 70), ratio: ratio(foreground, background),
        foreground: foreground.slice(0, 3).map(Math.round), background: background.slice(0, 3).map(Math.round),
        color: style.color, opacity: Number(style.opacity), display: style.display, visibility: style.visibility,
        width: rect.width, height: rect.height, content: style.content,
        outline: style.outlineStyle, outlineWidth: parseFloat(style.outlineWidth),
        borderWidth: parseFloat(style.borderTopWidth), borderColor: style.borderTopColor,
        borderRatio: ratio(pixel(el, rgba(style.borderTopColor)), pixel(el, [0, 0, 0, 0], false)),
        fill: style.backgroundColor, image: style.backgroundImage, appearance: style.appearance,
        shadow: style.boxShadow, pointerEvents: style.pointerEvents,
        disabled: el.matches(':disabled') || el.getAttribute('aria-disabled') === 'true',
        checked: el.checked, indeterminate: el.indeterminate, expectedState: el.dataset.state,
        indicatorRatio: ratio(pixel(el, [255, 255, 255, 1]), background)
      };
    }
    window.contrastMetrics = { measure, rgba, ratio, pixel };
  });
}

async function measurements(page, selector, pseudo) {
  const rows = await page.locator(selector).evaluateAll((els, pseudo) => els.map(el => window.contrastMetrics.measure(el, pseudo)), pseudo);
  assert.ok(rows.length, `Fixture must contain ${selector}`);
  return rows;
}
function contrast(rows, threshold, state, property = 'ratio') {
  const failures = rows.filter(row => !Number.isFinite(row[property]) || row[property] < threshold);
  assert.equal(failures.length, 0, `${state}: expected ${property} >= ${threshold}:1\n` + failures.map(row =>
    `${row.element} (${row.text}): ${row[property].toFixed(2)}:1; fg=${row.foreground}; bg=${row.background}; border=${row.borderColor}; opacity=${row.opacity}`).join('\n'));
}

async function forceState(page, selector, state) {
  const session = await page.context().newCDPSession(page);
  await session.send('DOM.enable'); await session.send('CSS.enable');
  const { root: doc } = await session.send('DOM.getDocument');
  const { nodeIds } = await session.send('DOM.querySelectorAll', { nodeId: doc.nodeId, selector });
  assert.ok(nodeIds.length, `Pseudo-state targets must exist: ${selector}`);
  const forcedPseudoClasses = { rest: [], hover: ['hover'], focus: ['focus', 'focus-visible'], active: ['hover', 'active'], autofill: ['autofill'] }[state];
  for (const nodeId of nodeIds) await session.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses });
  // CSS transitions still run with reduced motion when a rule does not honor it.
  await page.evaluate(async () => {
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    for (const animation of document.getAnimations()) {
      if (animation.effect?.getTiming().iterations !== Infinity) { try { animation.finish(); } catch {} }
    }
  });
  return async () => {
    for (const nodeId of nodeIds) await session.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] });
    await session.detach();
  };
}

for (const mobile of [false, true]) for (const theme of ['light', 'dark']) {
  const label = `${theme} ${mobile ? 'mobile' : 'desktop'}`;
  test(`theme contrast matrix: ${label}`, { timeout: 60000 }, async t => {
    const page = await pageFor(t, theme, mobile);
    await t.test('table headers are maroon with white text only in light mode', async () => {
      const headers = await page.locator('.table > thead > tr > th').evaluateAll(els => els.map(el => ({
        color: getComputedStyle(el).color,
        background: getComputedStyle(el).backgroundColor,
      })));
      assert.ok(headers.length > 0);
      for (const header of headers) {
        if (theme === 'light') {
          assert.equal(header.background, 'rgb(128, 0, 0)');
          assert.equal(header.color, 'rgb(255, 255, 255)');
        } else {
          assert.notEqual(header.background, 'rgb(128, 0, 0)');
        }
      }
      contrast(await measurements(page, '.table > thead > tr > th'), 4.5, `${label} table headers`);
    });
    await t.test('all Bootstrap buttons and table actions: rest/hover/focus/active', async () => {
      for (const state of ['rest', 'hover', 'focus', 'active']) {
        const clear = await forceState(page, '[data-enabled]', state);
        try {
          const rows = await measurements(page, '[data-enabled]');
          contrast(rows, 4.5, `${label} buttons ${state}`);
          if (state === 'focus') for (const row of rows) {
            assert.ok(row.outline !== 'none' && row.outlineWidth >= 1, `${label} ${row.element}: visible keyboard focus outline required`);
          }
        } finally { await clear(); }
      }
      // Exercise an actual keyboard focus as well as forced CSS focus states.
      await page.locator('#button-0').focus();
      await page.keyboard.press('Tab');
      assert.equal(await page.locator('#button-1').evaluate(el => el === document.activeElement && el.matches(':focus-visible')), true,
        'Tab skips disabled buttons/anchors/fieldsets and shows keyboard focus');
    });
    await t.test('maroon buttons and tabs stay white; accent icons become white on dark surfaces', async () => {
      for (const state of ['rest', 'hover', 'focus', 'active']) {
        const clear = await forceState(page, '[data-brand-control]', state);
        try {
          const rows = await measurements(page, '[data-brand-control], [data-brand-content]');
          contrast(rows, 4.5, `${label} brand controls ${state}`);
          for (const row of rows) assert.deepEqual(row.foreground, [255, 255, 255], `${label} ${row.element}: maroon controls and their contents must stay white in ${state}`);
        } finally { await clear(); }
      }
      for (const row of await measurements(page, '[data-accent-icon]')) {
        assert.deepEqual(row.foreground, theme === 'dark' ? [255, 255, 255] : [128, 0, 0], `${label} ${row.element}: accent icon must be white on dark surfaces and maroon on light surfaces`);
      }
    });
    if (theme === 'dark') await t.test('Teams, calendar and dashboard accent text stays readable at rest and hover', async () => {
      for (const state of ['rest', 'hover']) {
        const clear = await forceState(page, '[data-dark-accent-text]', state);
        try {
          contrast(await measurements(page, '[data-dark-accent-text]'), 4.5, `${label} accent text ${state}`);
        } finally { await clear(); }
      }
    });
    await t.test('disabled actions remain recognizable and noninteractive (contrast exempt)', async () => {
      for (const row of await measurements(page, '[data-disabled]')) {
        assert.ok(row.disabled, `${row.element}: native disabled or aria-disabled required`);
        assert.equal(row.pointerEvents, 'none', `${row.element}: disabled action must not respond to pointer clicks`);
        assert.ok(row.opacity >= 0.25 && row.width > 0 && row.height > 0 && row.visibility === 'visible', `${row.element}: disabled action must remain visible`);
        assert.ok(row.ratio > 1.1, `${row.element}: disabled text is invisible (${row.ratio.toFixed(2)}:1); no 4.5:1 requirement for disabled controls`);
      }
    });
    await t.test('checkboxes/radios, including unclassed native inputs, have discernible borders and states', async () => {
      for (const state of ['rest', 'focus']) {
        const clear = await forceState(page, '[data-control]:not(:disabled)', state);
        try {
          const rows = await measurements(page, '[data-control]:not(:disabled)');
          contrast(rows, 3, `${label} ${state} control border against surrounding surface`, 'borderRatio');
          for (const row of rows) {
            assert.ok(row.borderWidth >= 1 && row.width >= 12 && row.height >= 12, `${row.element}: measurable visible themed control border required (appearance=${row.appearance})`);
            assert.equal(row.checked, row.expectedState === 'checked', `${row.element}: checked semantics must be preserved`);
            assert.equal(row.indeterminate, row.expectedState === 'indeterminate', `${row.element}: indeterminate semantics must be preserved`);
            if (row.checked || row.indeterminate) {
              assert.notEqual(row.image, 'none', `${row.element}: selected state needs a check/dot/dash, not color alone`);
              // Bootstrap's actual SVG is white; verify that assumption, not just a hypothetical white pixel.
              assert.match(decodeURIComponent(row.image), /(?:%23|#)(?:fff(?:fff)?)(?:['"\s;)]|$)|white/i, `${row.element}: inspect selected SVG indicator color`);
              assert.ok(row.indicatorRatio >= 3, `${row.element}: white state indicator/fill ${row.indicatorRatio.toFixed(2)}:1 < 3:1 (fill=${row.fill})`);
            }
            if (state === 'focus') assert.ok(row.outline !== 'none' && row.outlineWidth >= 1, `${row.element}: keyboard focus must be visible`);
          }
        } finally { await clear(); }
      }
      contrast(await measurements(page, '[data-control]:not(:disabled) + .form-check-label'), 4.5, `${label} enabled control labels`);
      for (const row of await measurements(page, '[data-control]:disabled')) {
        assert.ok(row.disabled && row.opacity >= 0.25 && row.width >= 12 && row.height >= 12, `${row.element}: disabled control must remain recognizable`);
        if (row.checked || row.indeterminate) assert.notEqual(row.image, 'none', `${row.element}: disabled control must retain its selected indicator`);
      }
    });
    await t.test('welcome alert close icon stays dark without changing modal close icons', async () => {
      await page.evaluate(() => {
        const fixture = document.createElement('div');
        fixture.id = 'welcome-alert-fixture';
        fixture.innerHTML = '<div class="alert alert-success alert-dismissible" role="alert">Welcome back, Fixture User!<button type="button" class="btn-close" aria-label="Close"></button></div>' +
          '<div class="modal"><button type="button" class="btn-close" aria-label="Close modal"></button></div>';
        document.body.append(fixture);
      });
      const filters = await page.locator('#welcome-alert-fixture .btn-close').evaluateAll(elements => elements.map(el => getComputedStyle(el).filter));
      assert.equal(filters[0], 'none', 'The welcome alert must use the dark close glyph');
      if (theme === 'dark') assert.match(filters[1], /invert\(1\)/, 'Dark modal close icons must stay light');
      await page.locator('#welcome-alert-fixture').evaluate(el => el.remove());
    });
    await t.test('breadcrumb separators, table tags, text utilities and links', async () => {
      const separators = await measurements(page, '.breadcrumb-item + .breadcrumb-item', '::before');
      for (const row of separators) assert.ok(row.content && !['none', 'normal', '""'].includes(row.content) && row.visibility === 'visible' && row.opacity > 0,
        `${row.element}::before: breadcrumb separator must be visible`);
      contrast(separators, 3, `${label} breadcrumb ::before`);
      contrast(await measurements(page, '.breadcrumb-item, .breadcrumb-item a'), 4.5, `${label} breadcrumb text`);
      for (const state of ['rest', 'hover', 'focus', 'active']) {
        const clear = await forceState(page, 'tbody a', state);
        try { contrast(await measurements(page, '[data-table-text]'), 4.5, `${label} table tags/text/links ${state}`); }
        finally { await clear(); }
      }
      // Force the striped table's hover layer too: Bootstrap paints it using inset shadows.
      const clear = await forceState(page, 'tbody tr', 'hover');
      try { contrast(await measurements(page, '[data-table-text], tbody [data-enabled]'), 4.5, `${label} hovered table rows`); }
      finally { await clear(); }
    });
    await t.test('neutral body/form surfaces and Chromium autofill', async () => {
      if (theme === 'dark') {
        const rows = await measurements(page, 'body, #neutral-text, #neutral-select, #neutral-area');
        for (const row of rows) {
          assert.ok(Math.max(...row.background) - Math.min(...row.background) <= 3,
            `${label} ${row.element}: dark surface must be achromatic, not blue; actual RGB=${row.background}`);
          assert.ok(Math.max(...row.background) < 100, `${row.element}: dark surface must stay dark, actual RGB=${row.background}`);
        }
      }
      contrast(await measurements(page, '#neutral-text, #neutral-select, #neutral-area'), 4.5, `${label} form text`);
      const clear = await forceState(page, '#autofill', 'autofill');
      try {
        const rows = await measurements(page, '#autofill');
        const forced = await page.locator('#autofill').evaluate(el => el.matches(':-webkit-autofill'));
        for (const row of rows) {
          assert.match(row.shadow, /inset/, `${label}: ${forced ? 'forced autofill' : 'CDP autofill fallback'} must apply a neutral inset fill; actual shadow=${row.shadow}`);
          assert.ok(Math.max(...row.background) - Math.min(...row.background) <= 3,
            `${label}: autofill must not turn blue/yellow; actual RGB=${row.background}; shadow=${row.shadow}`);
          if (theme === 'dark') assert.ok(Math.max(...row.background) < 100, `${label}: autofill must remain dark; RGB=${row.background}`);
        }
        contrast(rows, 4.5, `${label} autofilled text`);
      } finally { await clear(); }
    });
  });

  test(`real layout logout and dynamic theme toggle: ${label}`, { timeout: 60000 }, async t => {
    const page = await pageFor(t, theme, mobile, true);
    await page.waitForFunction(() => window.bootstrap);
    async function logoutChecks(expectedTheme) {
      await page.locator('.user-trigger').click();
      await page.locator('.user-menu-logout').waitFor({ state: 'visible' });
      for (const state of ['rest', 'hover', 'focus', 'active']) {
        const clear = await forceState(page, '.user-menu-logout', state);
        try {
          const rows = await measurements(page, '.user-menu-logout, .user-menu-logout span');
          contrast(rows, 4.5, `${expectedTheme} real header logout ${state}`);
          for (const row of rows) {
            assert.deepEqual(row.foreground, [255, 255, 255], `${expectedTheme} logout ${state}: white text required`);
            if (state === 'rest') assert.deepEqual(row.background, [128, 0, 0], `${expectedTheme}: header logout must use maroon #800000`);
          }
        } finally { await clear(); }
      }
      await page.locator('.user-menu-logout').click();
      await page.locator('#logoutConfirmModal').waitFor({ state: 'visible' });
      const submit = '#logoutConfirmModal form button[type="submit"]';
      assert.equal(await page.locator(submit).count(), 1, 'Inspect actual logout POST submit, not a synthetic copy');
      assert.equal(await page.locator('#logoutConfirmModal form').getAttribute('method').then(s => s.toLowerCase()), 'post');
      for (const state of ['rest', 'hover', 'focus', 'active']) {
        const clear = await forceState(page, submit, state);
        try {
          const rows = await measurements(page, submit);
          contrast(rows, 4.5, `${expectedTheme} real logout submit ${state}`);
          for (const row of rows) {
            assert.deepEqual(row.foreground, [255, 255, 255], `${expectedTheme} logout submit ${state}: white text required`);
            if (state === 'rest') assert.deepEqual(row.background, [128, 0, 0], `${expectedTheme}: logout submit must use maroon #800000`);
          }
        } finally { await clear(); }
      }
      contrast(await measurements(page, '#logoutConfirmModal .modal-title, #logoutConfirmModal .modal-body, #logoutConfirmModal [data-bs-dismiss="modal"].btn'), 4.5,
        `${expectedTheme} logout dialog text and Cancel`);
      await page.locator('#logoutConfirmModal .modal-footer [data-bs-dismiss="modal"]').click();
      await page.locator('#logoutConfirmModal').waitFor({ state: 'hidden' });
    }
    await logoutChecks(theme);
    await page.screenshot({ path: path.join(root, 'writable/theme-contrast-preview', `${theme}-${mobile ? 'mobile' : 'desktop'}.png`), fullPage: true, animations: 'disabled', timeout: 15000 });
    const nextTheme = theme === 'light' ? 'dark' : 'light';
    await page.locator('.theme-toggle').click();
    await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, nextTheme);
    assert.equal(await page.evaluate(() => localStorage.getItem('na-dark-mode')), nextTheme === 'dark' ? '1' : '0', 'Real toggle persists the new theme');
    await logoutChecks(nextTheme);
    await page.locator('.theme-toggle').click();
    await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, theme);
    assert.equal(await page.evaluate(() => localStorage.getItem('na-dark-mode')), theme === 'dark' ? '1' : '0', 'Real toggle returns to the original theme');
  });
}
