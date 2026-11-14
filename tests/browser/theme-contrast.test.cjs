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
  const portal = fs.readFileSync(path.join(root, 'app/Views/portal/index.php'), 'utf8');
  const staffBanner = portal.match(/<div class="alert alert-info[^]*?(?=    <div class="d-flex flex-wrap gap-2 mt-2 mt-md-0">)/);
  assert.ok(staffBanner, 'Use the production staff-session banner markup');
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Theme contrast matrix</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/bmg.css">
    </head><body><main class="container-fluid p-3">
    <h1 class="h4">Production theme contrast matrix</h1>
    <section id="staff-session-fixture">${staffBanner[0]}</div></section>
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
      <div class="p-2 rounded border bg-body-tertiary mt-2" data-surface-box><strong class="small" data-surface-text>Tertiary Header</strong> <span class="small text-muted" data-surface-text>Muted guidance copy</span> <code data-surface-text>gate_key</code> <span class="badge bg-body-tertiary text-body border" data-surface-text>Scope</span></div>
      <div class="p-2 rounded border bg-body-secondary mt-2" data-surface-box><span class="small" data-surface-text>Secondary surface copy</span></div>
      <div class="p-2 rounded border bg-body mt-2" data-surface-box><span class="small" data-surface-text>Body surface copy</span></div>
      <div class="p-2 rounded border bg-light-subtle mt-2" data-surface-box><span class="small" data-surface-text>Light subtle copy</span></div>
      <div class="p-2 rounded border bg-secondary-subtle mt-2" data-surface-box><span class="small" data-surface-text>Secondary subtle copy</span></div>
      <div class="p-2 rounded border bg-dark-subtle mt-2" data-surface-box><span class="small" data-surface-text>Dark subtle copy</span></div>
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

test('staff personal-session banner stays readable across theme changes', { timeout: 30000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });
  for (const theme of ['light', 'dark', 'light', 'legacy-dark']) {
    await page.evaluate(theme => {
      if (theme === 'legacy-dark') document.documentElement.removeAttribute('data-theme');
      else document.documentElement.setAttribute('data-theme', theme);
      document.body.classList.toggle('dark-mode', theme === 'legacy-dark');
    }, theme);
    const contrasts = await page.locator('#staff-session-fixture .alert').evaluate(banner => {
      const luminance = color => color.match(/[\d.]+/g).slice(0, 3).map(Number)
        .map(v => v / 255).map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
        .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
      const background = luminance(getComputedStyle(banner).backgroundColor);
      return [...banner.querySelectorAll('strong, span, i')].map(el => {
        const foreground = luminance(getComputedStyle(el).color);
        return (Math.max(background, foreground) + 0.05) / (Math.min(background, foreground) + 0.05);
      });
    });
    assert.equal(contrasts.length, 3, 'Check heading, description, and icon');
    for (const ratio of contrasts) assert.ok(ratio >= 4.5, `${theme}: banner contrast ${ratio.toFixed(2)} must meet 4.5:1`);
  }
});

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

test('dashboard welcome banner and ApexCharts follow real theme tokens', { timeout: 40000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });
  await page.addScriptTag({ url: origin + '/assets/vendor/apexcharts/apexcharts.min.js' });
  // Exercise the real module, not a hand-written chart config.
  await page.addScriptTag({ url: origin + '/assets/js/chart-theme.js' });

  await page.evaluate(() => {
    const banner = document.createElement('div');
    banner.id = 'banner-fixture';
    banner.className = 'card nd-welcome-banner nd-welcome-banner--admin';
    banner.innerHTML = '<h2 class="h4">Welcome back</h2><p>Subtitle</p><span class="badge bg-white text-danger">Superadmin</span>';
    document.querySelector('main').prepend(banner);

    const card = document.createElement('section');
    card.className = 'card';
    const line = document.createElement('div');
    line.id = 'lineFixture';
    const donut = document.createElement('div');
    donut.id = 'donutFixture';
    card.append(line, donut);
    document.querySelector('main').prepend(card);
  });

  // The banner gradient must live in CSS, never an inline !important, or it
  // outranks every stylesheet rule and dark mode cannot retheme it.
  const inlineImportant = await page.locator('#banner-fixture').evaluate(el => {
    const style = el.getAttribute('style') || '';
    return /background[^;]*!important/.test(style);
  });
  assert.equal(inlineImportant, false, 'The banner must not hard-code its gradient with inline !important');

  await page.evaluate(() => {
    window.__fixtureSeries = [
      { name: 'Clinic Visits', data: [0, 0, 0, 1, 3, 0, 0] },
      { name: 'Guidance Sessions', data: [0, 0, 0, 1, 2, 0, 0] }
    ];
    window.__fixturePop = [2008, 619];

    ChartTheme.create(document.querySelector('#lineFixture'), function (tokens) {
      return ChartTheme.base(tokens, {
        chart: { type: 'area', height: 200, animations: { enabled: false } },
        series: window.__fixtureSeries,
        colors: [tokens['series-1'], tokens['series-2']],
        stroke: { width: [2.5, 2.5] },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] } },
        xaxis: { categories: ['a', 'b', 'c', 'd', 'e', 'f', 'g'] },
        legend: { position: 'top', horizontalAlign: 'left' }
      });
    });

    ChartTheme.create(document.querySelector('#donutFixture'), function (tokens) {
      return ChartTheme.donut(tokens, {
        series: window.__fixturePop,
        labels: ['Students', 'Employees'],
        colors: [tokens['series-1'], tokens['series-2']],
        plotOptions: {
          pie: {
            donut: {
              labels: {
                total: { show: true, label: 'Population', formatter: function () { return 2627; } }
              }
            }
          }
        }
      });
    });
  });

  const read = () => page.evaluate(() => {
    const banner = document.querySelector('#banner-fixture');
    const cs = getComputedStyle(banner);
    const line = document.querySelector('#lineFixture');
    const donut = document.querySelector('#donutFixture');
    const gridline = line.querySelector('.apexcharts-gridline');
    const xLabel = line.querySelector('.apexcharts-xaxis-label');
    const legendText = line.querySelector('.apexcharts-legend-text');
    const seriesPaths = [...line.querySelectorAll('.apexcharts-series .apexcharts-area')]
      .filter(p => p.getAttribute('fill') === 'none');
    const slice0 = donut.querySelector('.apexcharts-donut-slice-0');
    const slice1 = donut.querySelector('.apexcharts-donut-slice-1');
    const centerLabel = donut.querySelector('.apexcharts-datalabel-label');
    const centerValue = donut.querySelector('.apexcharts-datalabel-value');
    const svg = line.querySelector('.apexcharts-canvas svg');
    return {
      // Read from body: the legacy body.dark-mode path declares the dark
      // palette on the body selector, not on <html>.
      tokens: (function () {
        var ts = getComputedStyle(document.body);
        return {
          series1: ts.getPropertyValue('--chart-series-1').trim(),
          series2: ts.getPropertyValue('--chart-series-2').trim(),
          grid: ts.getPropertyValue('--chart-grid').trim(),
          axis: ts.getPropertyValue('--chart-axis-label').trim(),
          tooltipBg: ts.getPropertyValue('--chart-tooltip-bg').trim(),
          donutGap: ts.getPropertyValue('--chart-donut-gap').trim()
        };
      })(),
      bannerBg: cs.backgroundImage,
      bannerColor: cs.color,
      badgeBg: getComputedStyle(banner.querySelector('.badge')).backgroundColor,
      gridStroke: gridline ? getComputedStyle(gridline).stroke : 'MISSING',
      axisFill: xLabel ? getComputedStyle(xLabel).fill : 'MISSING',
      legendColor: legendText ? getComputedStyle(legendText).color : 'MISSING',
      seriesStrokes: seriesPaths.map(p => getComputedStyle(p).stroke),
      slice0: slice0 ? getComputedStyle(slice0).fill : 'MISSING',
      slice1: slice1 ? getComputedStyle(slice1).fill : 'MISSING',
      // ApexCharts applies stroke-width only after the slice animation ends,
      // so assert the attribute ApexCharts paints rather than racing it.
      sliceStroke: slice0 ? getComputedStyle(slice0).stroke : 'MISSING',
      // Regression guard: ApexCharts' own theme.mode forces #424242 here.
      svgBackground: svg ? (svg.style.background || 'none') : 'MISSING',
      centerLabel: centerLabel ? centerLabel.textContent.trim() : 'MISSING',
      centerValue: centerValue ? centerValue.textContent.trim() : 'MISSING',
      centerLabelFill: centerLabel ? getComputedStyle(centerLabel).fill : 'MISSING'
    };
  });

  // ApexCharts draws pie slices with stroke-width 0 and only applies the
  // real width when the entry animation ends, so wait for the stroke
  // attribute instead of guessing a delay.
  await page.waitForSelector('#donutFixture .apexcharts-donut-slice-0', { state: 'attached' });
  await page.waitForSelector('#lineFixture .apexcharts-gridline', { state: 'attached' });
  await page.waitForFunction(() => {
    const s = document.querySelector('#donutFixture .apexcharts-donut-slice-0');
    return s && s.getAttribute('stroke') && s.getAttribute('stroke') !== 'none';
  }, { timeout: 8000 });

  const light = await read();
  assert.match(light.bannerBg, /rgb\(128, 0, 0\)/, 'Light mode keeps the maroon welcome banner');
  assert.equal(light.tokens.series1, '#800000', 'Light chart series 1 must come from the maroon token');
  assert.equal(light.slice0, 'rgb(128, 0, 0)', 'Light mode keeps maroon donut slices');
  assert.equal(light.sliceStroke, 'rgb(255, 255, 255)', 'Light slice gap must use the white surface token');
  assert.equal(light.centerLabel, 'Population', 'Donut center label must render (deep merge must not drop it)');
  assert.equal(light.centerValue, '2627', 'Donut center value must render');

  // --chart-donut-gap resolves to --surface-color (#1c1c1c) in dark mode.
  const darkSliceGap = 'rgb(28, 28, 28)';
  for (const theme of ['dark', 'legacy-dark']) {
    await page.evaluate(t => {
      if (t === 'legacy-dark') {
        document.documentElement.removeAttribute('data-theme');
        document.body.classList.add('dark-mode');
      } else {
        document.documentElement.setAttribute('data-theme', t);
        document.body.classList.remove('dark-mode');
      }
    }, theme);
    // Let the MutationObserver / theme-change listener re-render the charts,
    // then wait for the slice animation to re-apply the stroke width.
    await page.waitForFunction(expected => {
      const s = document.querySelector('#donutFixture .apexcharts-donut-slice-0');
      if (!s) return false;
      const stroke = getComputedStyle(s).stroke;
      return stroke === expected;
    }, darkSliceGap, { timeout: 8000 }).catch(async () => {
      const diag = await page.evaluate(() => {
        const s = document.querySelector('#donutFixture .apexcharts-donut-slice-0');
        return {
          exists: !!s,
          stroke: s ? getComputedStyle(s).stroke : null,
          strokeAttr: s ? s.getAttribute('stroke') : null,
          sliceCount: document.querySelectorAll('#donutFixture .apexcharts-donut-slice-0').length,
          theme: document.documentElement.getAttribute('data-theme'),
          bodyDark: document.body.classList.contains('dark-mode'),
          gapToken: getComputedStyle(document.documentElement).getPropertyValue('--chart-donut-gap').trim(),
          svgHtml: s ? s.outerHTML.slice(0, 200) : null
        };
      });
      throw new Error('dark slice gap never applied: ' + JSON.stringify(diag));
    });
    const dark = await read();

    assert.match(dark.bannerBg, /rgb\(31, 31, 31\)/, `${theme}: welcome banner must use the neutral black surface`);
    assert.equal(dark.bannerColor, 'rgb(240, 240, 240)', `${theme}: banner text must stay light`);
    assert.equal(dark.badgeBg, 'rgb(46, 46, 46)', `${theme}: the role pill must stop being a white chip`);

    // Charts must re-read the dark tokens, not keep light values.
    assert.equal(dark.tokens.series1, '#e5e5e5', `${theme}: dark series-1 token must be light grey`);
    assert.equal(dark.tokens.series2, '#8f8f8f', `${theme}: dark series-2 token must be mid grey`);
    assert.equal(dark.tokens.grid, '#383838', `${theme}: dark grid token must come from the border palette`);
    assert.equal(dark.tokens.tooltipBg, '#1c1c1c', `${theme}: tooltip must use the dark surface token`);
    assert.equal(dark.tokens.donutGap, '#1c1c1c', `${theme}: slice gap must use the dark surface token`);

    assert.equal(dark.gridStroke, 'rgb(56, 56, 56)', `${theme}: gridlines must use the dark grid token`);
    assert.equal(dark.axisFill, 'rgb(163, 163, 163)', `${theme}: axis labels must use the muted token`);
    assert.equal(dark.legendColor, 'rgb(163, 163, 163)', `${theme}: legend text must use the muted token`);

    assert.deepEqual(dark.seriesStrokes, ['rgb(229, 229, 229)', 'rgb(143, 143, 143)'],
      `${theme}: both series must use the dark chart tokens, not maroon`);
    assert.equal(dark.slice0, 'rgb(229, 229, 229)', `${theme}: donut slice 0 must use series-1`);
    assert.equal(dark.slice1, 'rgb(143, 143, 143)', `${theme}: donut slice 1 must use series-2`);
    assert.equal(dark.sliceStroke, 'rgb(28, 28, 28)', `${theme}: slice gap must be the dark surface`);

    // ApexCharts' theme.mode paints #424242 over the card; we must avoid it.
    assert.notEqual(dark.svgBackground, 'rgb(66, 66, 66)',
      `${theme}: the chart must not inherit ApexCharts' hard-coded dark canvas background`);
    assert.equal(dark.centerLabel, 'Population', `${theme}: donut center label must survive re-render`);
    assert.equal(dark.centerValue, '2627', `${theme}: donut center value must survive re-render`);

    const lum = c => c.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255)
      .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
      .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    const a = lum(dark.slice0), b = lum(dark.slice1);
    const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    assert.ok(ratio >= 2.5, `${theme}: donut slices must stay distinguishable (${ratio.toFixed(2)}:1)`);
  }
});

test('categorical and status donut palettes stay legible in dark mode', { timeout: 30000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });
  await page.addScriptTag({ url: origin + '/assets/vendor/apexcharts/apexcharts.min.js' });
  await page.addScriptTag({ url: origin + '/assets/js/chart-theme.js' });

  await page.evaluate(() => {
    const host = document.createElement('section');
    host.className = 'card';
    host.id = 'palette-host';
    const cat = document.createElement('div');
    cat.id = 'catDonut';
    const status = document.createElement('div');
    status.id = 'statusDonut';
    host.append(cat, status);
    document.querySelector('main').prepend(host);

    ChartTheme.create(cat, function (t) {
      return ChartTheme.donut(t, {
        series: [4, 3, 2, 1, 2, 1],
        labels: ['A', 'B', 'C', 'D', 'E', 'F'],
        colors: ChartTheme.categorical(t)
      });
    });
    ChartTheme.create(status, function (t) {
      return ChartTheme.donut(t, {
        series: [2, 3, 5],
        labels: ['Danger', 'Warning', 'Success'],
        colors: ChartTheme.statusPalette(t).slice(0, 3)
      });
    });
  });

  await page.waitForSelector('#catDonut .apexcharts-donut-slice-0', { state: 'attached' });
  await page.waitForSelector('#statusDonut .apexcharts-donut-slice-0', { state: 'attached' });

  const read = () => page.evaluate(() => {
    const lum = c => c.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255)
      .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
      .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    const card = document.querySelector('#palette-host');
    const bg = getComputedStyle(card).backgroundColor;
    const bgLum = lum(bg);
    const grab = id => [...document.querySelectorAll(`#${id} .apexcharts-pie-area`)]
      .map(p => getComputedStyle(p).fill);
    const contrast = fill => {
      const l = lum(fill);
      return (Math.max(l, bgLum) + 0.05) / (Math.min(l, bgLum) + 0.05);
    };
    const catFills = grab('catDonut');
    const statusFills = grab('statusDonut');
    return {
      bg,
      catFills,
      catRatios: catFills.map(f => +contrast(f).toFixed(2)),
      statusFills,
      statusRatios: statusFills.map(f => +contrast(f).toFixed(2)),
      catTokens: [1, 2, 3, 4, 5, 6].map(i => getComputedStyle(document.body).getPropertyValue('--chart-cat-' + i).trim())
    };
  });

  const light = await read();
  assert.equal(light.catFills.length, 6, 'Light mode must render all six categorical slices');
  assert.equal(light.statusFills.length, 3, 'Light mode must render all three status slices');

  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await page.waitForFunction(() => {
    const s = document.querySelector('#catDonut .apexcharts-donut-slice-0');
    return s && getComputedStyle(s).stroke === 'rgb(28, 28, 28)';
  }, { timeout: 8000 });

  const dark = await read();
  assert.equal(dark.catFills.length, 6);
  assert.equal(dark.statusFills.length, 3);

  // The maroon categorical hue is ~1.7:1 on the dark card, which made its
  // slice disappear; the dark token must lift it into a legible range.
  for (let i = 0; i < dark.catFills.length; i++) {
    assert.ok(dark.catRatios[i] >= 3,
      `categorical slice ${i + 1} (${dark.catFills[i]}) must reach 3:1 on the dark card, got ${dark.catRatios[i]}:1`);
  }
  for (let i = 0; i < dark.statusFills.length; i++) {
    assert.ok(dark.statusRatios[i] >= 3,
      `status slice ${i + 1} (${dark.statusFills[i]}) must reach 3:1 on the dark card, got ${dark.statusRatios[i]}:1`);
  }

  // Hues must remain distinct, or a categorical donut loses its meaning.
  assert.equal(new Set(dark.catFills).size, 6, 'all six categorical slices must stay distinct hues');
  assert.equal(new Set(dark.statusFills).size, 3, 'all three status slices must stay distinct hues');

  // The dark palette must differ from light where lightness was the problem.
  assert.notEqual(dark.catFills[0], light.catFills[0],
    'the maroon categorical hue must be lifted for dark mode');
});

test('status donuts switch to a neutral ramp in dark mode only', { timeout: 30000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });
  await page.addScriptTag({ url: origin + '/assets/vendor/apexcharts/apexcharts.min.js' });
  await page.addScriptTag({ url: origin + '/assets/js/chart-theme.js' });

  await page.evaluate(() => {
    const host = document.createElement('section');
    host.className = 'card';
    host.id = 'neutral-host';
    const three = document.createElement('div');
    three.id = 'threeDonut';
    const six = document.createElement('div');
    six.id = 'sixDonut';
    host.append(three, six);
    document.querySelector('main').prepend(host);

    // Mirrors the clinic/BMG (3 slices) and guidance (6 slices) dashboards.
    ChartTheme.create(three, function (t) {
      return ChartTheme.donut(t, {
        series: [2, 3, 5],
        labels: ['A', 'B', 'C'],
        colors: ChartTheme.themedSlices(t, ChartTheme.statusPalette(t).slice(0, 3))
      });
    });
    ChartTheme.create(six, function (t) {
      return ChartTheme.donut(t, {
        series: [4, 3, 2, 1, 2, 1],
        labels: ['A', 'B', 'C', 'D', 'E', 'F'],
        colors: ChartTheme.themedSlices(t, ChartTheme.categorical(t))
      });
    });
  });

  await page.waitForSelector('#threeDonut .apexcharts-donut-slice-0', { state: 'attached' });
  await page.waitForSelector('#sixDonut .apexcharts-donut-slice-0', { state: 'attached' });

  const read = () => page.evaluate(() => {
    const grab = id => [...document.querySelectorAll(`#${id} .apexcharts-pie-area`)]
      .map(p => getComputedStyle(p).fill);
    return {
      three: grab('threeDonut'),
      six: grab('sixDonut'),
      cardBg: getComputedStyle(document.querySelector('#neutral-host')).backgroundColor
    };
  });

  const lum = c => c.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255)
    .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const contrast = (a, b) => {
    const la = lum(a), lb = lum(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };
  const isGrey = fill => {
    const v = fill.match(/[\d.]+/g).slice(0, 3).map(Number);
    return Math.max(...v) - Math.min(...v) <= 2;
  };

  // Light mode must keep the semantic hues.
  const light = await read();
  assert.equal(light.three.length, 3);
  assert.equal(light.six.length, 6);
  assert.ok(light.three.some(f => !isGrey(f)), 'light mode must keep semantic status hues');
  assert.ok(light.six.some(f => !isGrey(f)), 'light mode must keep categorical hues');

  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await page.waitForFunction(() => {
    const s = document.querySelector('#threeDonut .apexcharts-donut-slice-0');
    return s && getComputedStyle(s).stroke === 'rgb(28, 28, 28)';
  }, { timeout: 8000 });

  const dark = await read();
  assert.equal(dark.three.length, 3);
  assert.equal(dark.six.length, 6);

  // Dark mode must be fully neutral, matching the admin population donut.
  for (const fill of dark.three.concat(dark.six)) {
    assert.ok(isGrey(fill), `dark slice ${fill} must be neutral grey, not a hue`);
  }

  for (const set of [dark.three, dark.six]) {
    const label = set === dark.three ? '3-slice' : '6-slice';
    assert.equal(new Set(set).size, set.length, `${label}: slices must all be distinct steps`);
    for (const fill of set) {
      const r = contrast(fill, dark.cardBg);
      assert.ok(r >= 3, `${label}: slice ${fill} must reach 3:1 on the dark card, got ${r.toFixed(2)}:1`);
    }
    // Adjacent slices must be far enough apart to read as separate wedges.
    for (let i = 0; i < set.length - 1; i++) {
      const r = contrast(set[i], set[i + 1]);
      assert.ok(r >= 1.25,
        `${label}: adjacent slices ${set[i]} / ${set[i + 1]} must be distinguishable, got ${r.toFixed(2)}:1`);
    }
  }
});
test('sidebar hover state is visible in both themes', { timeout: 30000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });

  // Build inside the real app shell so the sidebar is positioned on-screen;
  // a bare <aside> sits off-canvas and can never be hovered.
  await page.evaluate(() => {
    const shell = document.createElement('div');
    shell.className = 'app-shell';
    shell.id = 'sb-fixture';
    shell.style.position = 'fixed';
    shell.style.inset = '0';
    shell.style.zIndex = '9999';
    shell.innerHTML =
      '<aside class="sidebar"><div class="sidebar-shell"><nav class="sidebar-nav"><ul class="nav-menu">' +
      '<li class="nav-item"><a class="nav-link" href="#"><span class="nav-icon"><i class="bi bi-grid"></i></span><span class="nav-text">Dashboard</span></a></li>' +
      '<li class="nav-item"><a class="nav-link" href="#"><span class="nav-icon"><i class="bi bi-list"></i></span><span class="nav-text">Queue</span></a></li>' +
      '</ul></nav></div></aside>';
    document.body.append(shell);
  });

  const link = page.locator('#sb-fixture .sidebar-nav .nav-item > .nav-link').first();
  await link.waitFor({ state: 'visible' });

  const read = () => page.evaluate(() => {
    const side = document.querySelector('#sb-fixture .sidebar');
    const a = side.querySelector('.sidebar-nav .nav-item > .nav-link');
    return {
      sidebarBg: getComputedStyle(side).backgroundColor,
      linkBg: getComputedStyle(a).backgroundColor,
      hoverToken: getComputedStyle(side).getPropertyValue('--sidebar-hover-bg').trim()
    };
  });

  // Composite a possibly-translucent colour over the sidebar so the measured
  // value reflects what the eye actually sees.
  const flatten = (fg, bg) => {
    const parse = c => {
      const m = c.match(/[\d.]+/g);
      if (!m) return null;
      const v = m.slice(0, 3).map(Number);
      return { v, a: m.length > 3 ? Number(m[3]) : 1 };
    };
    const f = parse(fg), b = parse(bg);
    if (!f || !b) return null;
    return f.v.map((c, i) => c * f.a + b.v[i] * (1 - f.a));
  };
  const lum = v => v.map(c => c / 255)
    .map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
    .reduce((s, c, i) => s + c * [0.2126, 0.7152, 0.0722][i], 0);
  const ratio = (a, b) => {
    const la = lum(a), lb = lum(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };

  for (const theme of ['light', 'dark']) {
    if (theme === 'dark') {
      await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
      await page.waitForTimeout(250);
    }
    // Park the cursor away from the link first, otherwise the previous
    // iteration's hover is still applied and "rest" reads as hovered.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(250);
    const rest = await read();
    await link.hover();
    await page.waitForTimeout(250);
    const hover = await read();

    assert.ok(hover.hoverToken.length > 0, `${theme}: --sidebar-hover-bg must resolve`);
    assert.notEqual(hover.linkBg, rest.linkBg,
      `${theme}: hover must change the link background (rest=${rest.linkBg} hover=${hover.linkBg} token=${hover.hoverToken})`);

    const sideFlat = flatten(rest.sidebarBg, 'rgb(255, 255, 255)');
    const hoverFlat = flatten(hover.linkBg, rest.sidebarBg);
    assert.ok(sideFlat && hoverFlat, `${theme}: colours must be parseable`);

    // A 5% accent tint over the dark sidebar measured 1.003:1 — invisible.
    const r = ratio(hoverFlat, sideFlat);
    assert.ok(r >= 1.15,
      `${theme}: hover background must be distinguishable from the sidebar, got ${r.toFixed(3)}:1`);

    // The hover must use the themed token, not a hardcoded accent tint.
    // Compare resolved RGB: the token is a hex string while the computed
    // background is rgb(), so a string compare would always fail.
    const toRgb = c => {
      const hex = c.trim().match(/^#([0-9a-f]{6})$/i);
      if (hex) {
        const n = hex[1];
        return [0, 2, 4].map(i => parseInt(n.slice(i, i + 2), 16)).join(',');
      }
      const m = c.match(/[\d.]+/g);
      if (!m) return c;
      return m.slice(0, 3).map(Number).join(',');
    };
    assert.equal(toRgb(hover.linkBg), toRgb(hover.hoverToken),
      `${theme}: hover must consume --sidebar-hover-bg (${hover.hoverToken}), got ${hover.linkBg}`);
  }
});
test('donut tooltip text stays readable in both themes', { timeout: 40000 }, async t => {
  const page = await pageFor(t, 'light', false);
  await page.addStyleTag({ content: '* { transition: none !important; }' });
  await page.addScriptTag({ url: origin + '/assets/vendor/apexcharts/apexcharts.min.js' });
  await page.addScriptTag({ url: origin + '/assets/js/chart-theme.js' });

  await page.evaluate(() => {
    const host = document.createElement('section');
    host.className = 'card';
    host.id = 'tip-host';
    const target = document.createElement('div');
    target.id = 'tipDonut';
    host.append(target);
    document.querySelector('main').prepend(host);

    ChartTheme.create(target, function (t) {
      return ChartTheme.donut(t, {
        series: [2008, 619],
        labels: ['Students', 'Employees'],
        colors: [t['series-1'], t['series-2']]
      });
    });
  });

  await page.waitForSelector('#tipDonut .apexcharts-donut-slice-0', { state: 'attached' });
  await page.waitForTimeout(1500);

  // Hover the ring itself; the donut centre is a hole with no slice under it.
  const hit = await page.evaluate(() => {
    const svg = document.querySelector('#tipDonut .apexcharts-canvas svg');
    if (!svg) return null;
    const r = svg.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    for (const dx of [50, 70, 30, 90, -50, -70]) {
      const el = document.elementFromPoint(cx + dx, cy);
      if (el && (el.getAttribute('class') || '').includes('apexcharts-pie-area')) {
        return { x: cx + dx, y: cy };
      }
    }
    return null;
  });
  assert.ok(hit, 'the donut ring must be hoverable to produce a tooltip');

  const readTip = () => page.evaluate(() => {
    const tip = document.querySelector('#tipDonut .apexcharts-tooltip');
    if (!tip) return { missing: true };
    const cs = getComputedStyle(tip);
    const row = tip.querySelector('.apexcharts-tooltip-series-group');
    const label = tip.querySelector('.apexcharts-tooltip-text-y-label');
    const value = tip.querySelector('.apexcharts-tooltip-text-y-value');
    return {
      tipBg: cs.backgroundColor,
      rowBg: row ? getComputedStyle(row).backgroundColor : null,
      labelText: label ? label.textContent.trim() : '',
      labelColor: label ? getComputedStyle(label).color : null,
      valueText: value ? value.textContent.trim() : '',
      valueColor: value ? getComputedStyle(value).color : null
    };
  });

  const lum = c => c.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255)
    .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const contrast = (a, b) => {
    const la = lum(a), lb = lum(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };

  for (const theme of ['light', 'dark']) {
    if (theme === 'dark') {
      await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
      await page.waitForTimeout(600);
    }
    await page.mouse.move(hit.x, hit.y);
    await page.waitForTimeout(600);
    const tip = await readTip();

    assert.ok(!tip.missing, `${theme}: donut tooltip must render`);
    assert.ok(tip.labelText.length > 0, `${theme}: tooltip label must have text`);
    assert.ok(tip.valueText.length > 0, `${theme}: tooltip value must have text`);

    // ApexCharts forces fillSeriesColor on donuts, which paints the row in the
    // slice colour. On a light slice that made the text white-on-white.
    assert.equal(tip.rowBg, 'rgba(0, 0, 0, 0)',
      `${theme}: the tooltip row must not be filled with the series colour`);

    const labelRatio = contrast(tip.labelColor, tip.tipBg);
    const valueRatio = contrast(tip.valueColor, tip.tipBg);
    assert.ok(labelRatio >= 4.5,
      `${theme}: tooltip label contrast ${labelRatio.toFixed(2)}:1 must reach 4.5:1`);
    assert.ok(valueRatio >= 4.5,
      `${theme}: tooltip value contrast ${valueRatio.toFixed(2)}:1 must reach 4.5:1`);
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
    await t.test('KPI rail cards go neutral black and grey in dark mode', async () => {
      await page.evaluate(() => {
        const fixture = document.createElement('div');
        fixture.id = 'kpi-rail-fixture';
        fixture.innerHTML = ['nd-kpi-leads', 'nd-kpi-revenue', 'nd-kpi-retention', 'nd-kpi-cycle'].map(cls =>
          `<article class="nd-kpi-card ${cls}"><span class="nd-kpi-icon"><i class="bi bi-people"></i></span>` +
          '<span class="nd-kpi-label">Label</span><strong class="nd-kpi-value">1</strong>' +
          '<span class="nd-kpi-trend up">Trend</span></article>').join('');
        document.body.append(fixture);
      });
      const cards = await page.locator('#kpi-rail-fixture .nd-kpi-card').evaluateAll(elements => elements.map(el => {
        const icon = el.querySelector('.nd-kpi-icon');
        const cardCss = getComputedStyle(el);
        const iconCss = getComputedStyle(icon);
        const box = icon.getBoundingClientRect();
        return {
          cls: el.className,
          background: cardCss.backgroundColor,
          border: cardCss.borderColor,
          iconBackground: iconCss.backgroundColor,
          iconColor: iconCss.color,
          iconBox: [Math.round(box.width), Math.round(box.height)]
        };
      }));
      assert.equal(cards.length, 4);
      for (const card of cards) {
        assert.deepEqual(card.iconBox, [44, 44], `${card.cls}: icon tile geometry must not shift`);
        if (theme === 'dark') {
          assert.equal(card.background, 'rgb(22, 22, 22)', `${card.cls}: dark KPI cards must use the neutral black surface, not a hue tint`);
          assert.equal(card.iconBackground, 'rgb(38, 38, 38)', `${card.cls}: dark KPI icon tiles must be grey`);
          assert.equal(card.iconColor, 'rgb(229, 229, 229)', `${card.cls}: dark KPI glyphs must be light grey`);
        } else {
          // Light mode keeps the tinted status surfaces untouched.
          assert.notEqual(card.background, 'rgb(22, 22, 22)', `${card.cls}: light KPI cards keep their tinted fill`);
        }
      }
      await page.locator('#kpi-rail-fixture').evaluate(el => el.remove());
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
    await t.test('staff-session and welcome banners follow the dark surface palette', async () => {
      await page.evaluate(() => {
        const fixture = document.createElement('div');
        fixture.id = 'welcome-alert-fixture';
        fixture.innerHTML = '<div class="alert alert-info login-banner border-0" role="alert">Staff Member Personal Health Session' +
          '<a href="#console" class="btn btn-outline-secondary">Clinic Staff Console</a>' +
          '<a href="#console" class="text-decoration-none">Plain banner link</a>' +
          '<button type="button" class="btn-close" aria-label="Close"></button></div>' +
          '<div class="alert alert-success alert-dismissible" role="alert">Welcome back, Fixture User!<button type="button" class="btn-close" aria-label="Close"></button></div>' +
          '<div class="modal"><button type="button" class="btn-close" aria-label="Close modal"></button></div>';
        document.body.append(fixture);
      });
      const banners = await page.locator('#welcome-alert-fixture .alert').evaluateAll(elements => elements.map(el => {
        const css = getComputedStyle(el);
        return { background: css.backgroundColor, color: css.color, borderLeftWidth: css.borderLeftWidth, borderLeftStyle: css.borderLeftStyle, borderLeftColor: css.borderLeftColor };
      }));
      const filters = await page.locator('#welcome-alert-fixture .btn-close').evaluateAll(elements => elements.map(el => getComputedStyle(el).filter));
      const bannerButton = await page.locator('#welcome-alert-fixture .alert .btn').evaluate(el => {
        const css = getComputedStyle(el);
        return { color: css.color, background: css.backgroundColor };
      });
      const bannerLink = await page.locator('#welcome-alert-fixture .alert a:not(.btn)').evaluate(el => getComputedStyle(el).color);
      if (theme === 'dark') {
        assert.equal(banners[0].background, 'rgb(22, 22, 22)', 'The staff-session banner must use the dark banner surface');
        assert.equal(banners[1].background, 'rgb(22, 22, 22)', 'Other dark-mode banners must use the dark banner surface');
        assert.equal(banners[0].color, 'rgb(240, 240, 240)', 'The staff-session banner text must stay readable on the dark surface');
        assert.equal(banners[0].borderLeftStyle, 'solid', 'border-0 must not erase the status edge');
        assert.equal(banners[0].borderLeftWidth, '4px', 'The status edge must stay visible on the dark banner');
        assert.equal(banners[0].borderLeftColor, 'rgb(158, 204, 255)', 'The info status edge must survive the dark surface');
        assert.equal(bannerButton.color, 'rgb(255, 255, 255)', 'Banner buttons must keep their own action color, not the alert link color');
        assert.equal(bannerButton.background, 'rgb(89, 89, 89)', 'Banner buttons must keep their own action fill');
        assert.equal(bannerLink, 'rgb(158, 204, 255)', 'Plain banner links still use the status link color');
        assert.match(filters[0], /invert\(1\)/, 'The dark staff-session banner needs the light close glyph');
        assert.match(filters[1], /invert\(1\)/, 'Dark-mode black banners must use the light close glyph');
        assert.match(filters[2], /invert\(1\)/, 'Dark modal close icons must stay light');
      } else {
        assert.equal(filters[0], 'none', 'Light-mode banners must use the dark close glyph');
        assert.equal(filters[1], 'none', 'Light-mode banners must use the dark close glyph');
      }
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
        const rows = await measurements(page, 'body, #neutral-text, #neutral-select, #neutral-area, [data-surface-box]');
        for (const row of rows) {
          assert.ok(Math.max(...row.background) - Math.min(...row.background) <= 3,
            `${label} ${row.element}: dark surface must be achromatic, not blue; actual RGB=${row.background}`);
          assert.ok(Math.max(...row.background) < 100, `${row.element}: dark surface must stay dark, actual RGB=${row.background}`);
        }
      }
      contrast(await measurements(page, '#neutral-text, #neutral-select, #neutral-area, [data-surface-text]'), 4.5, `${label} form and surface container text`);
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







