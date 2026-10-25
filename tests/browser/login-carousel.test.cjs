'use strict';

// Tests the actual login photo markup and auth CSS with Bootstrap's real runtime.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { spawnSync } = require('node:child_process');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const layout = fs.readFileSync(path.join(root, 'app/Views/layouts/auth.php'), 'utf8');
const css = layout.match(/<style>([\s\S]*?)<\/style>/)[1];
const login = fs.readFileSync(path.join(root, 'app/Views/auth/login.php'), 'utf8');
const themeToggle = login.slice(login.indexOf('<div class="position-fixed'), login.indexOf('</div>') + '</div>'.length);
const aside = login.slice(login.indexOf('<aside '), login.indexOf('</aside>') + '</aside>'.length);
const slides = ['red', 'blue', 'green'].map((color, i) => ({
  title: 'Slide ' + i, subtitle: '', fit_mode: i === 0 ? 'cover' : 'contain_blur',
  light_image: 'fixture/' + color + '.svg', dark_image: 'fixture/' + color + '-dark.svg'
}));
const php = `<?php
function base_url($path) { return '/' . $path; }
function esc($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$slides = json_decode(base64_decode('${Buffer.from(JSON.stringify(slides)).toString('base64')}'), true);
?>` + aside;
const rendered = spawnSync('php', [], { input: php, encoding: 'utf8', cwd: root });
assert.equal(rendered.status, 0, rendered.stderr);
assert.doesNotMatch(rendered.stdout, /Fatal error|Warning:/);
let browser, server, origin;

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(cache).filter(n => /^chromium-\d+$/.test(n))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(n => path.join(cache, n, 'chrome-win64/chrome.exe')).find(p => fs.existsSync(p));
  }
  assert.ok(executablePath, 'Install Playwright Chromium before running this test');
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/fixture') {
      const theme = url.searchParams.get('theme') === 'dark' ? 'dark' : 'light';
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(`<!doctype html><html data-theme="${theme}"><head><meta charset="utf-8"><link rel="icon" href="data:,">
        <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
        <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
        <link rel="stylesheet" href="/assets/css/style.css"><style>${css}</style></head>
        <body>${themeToggle}<main class="container-fluid p-0 auth-main-wrapper min-vh-100"><div class="row g-0 min-vh-100">${rendered.stdout}
          ${url.searchParams.has('tall') ? '<section class="col-12 col-lg-5 auth-pane" style="min-height:850px">Enlarged sign-in content fixture</section>' : ''}
        </div></main>
        <script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script>window.addEventListener('load',()=>{const el=document.getElementById('loginPhotoCarousel');
          bootstrap.Carousel.getInstance(el)?.dispose();
          window.carousel=new bootstrap.Carousel(el,{interval:false,ride:false});});</script></body></html>`);
      return;
    }
    if (url.pathname.startsWith('/fixture/')) {
      const color = url.pathname.includes('red') ? '#e03232' : url.pathname.includes('blue') ? '#3266e0' : '#32b866';
      res.writeHead(200, { 'Content-Type': 'image/svg+xml' });
      // Wide artwork leaves visible side bands in contain mode, testing the backdrop.
      res.end(`<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="300"><rect width="1200" height="300" fill="${color}"/></svg>`);
      return;
    }
    const publicRoot = path.join(root, 'public');
    const file = path.resolve(publicRoot, '.' + url.pathname);
    if (!file.startsWith(publicRoot + path.sep)) { res.writeHead(403).end(); return; }
    fs.readFile(file, (error, content) => {
      if (error) { res.writeHead(404).end(); return; }
      res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : 'text/javascript' });
      res.end(content);
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, executablePath });
});

after(async () => {
  try { if (browser) await browser.close(); }
  finally { if (server?.listening) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); } }
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: forward, reverse and wrap fades never expose the photo-container backdrop`, { timeout: 20_000 }, async t => {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'no-preference' });
    t.after(() => context.close());
    const page = await context.newPage();
    await page.goto(`${origin}/fixture?theme=${theme}`);
    await page.waitForFunction(() => window.carousel && [...document.images].every(image => image.complete));
    const geometry = await page.locator('.auth-photo-container').evaluate(el => ({
      height: el.getBoundingClientRect().height, background: getComputedStyle(el).backgroundColor,
      expected: window.innerHeight - parseFloat(getComputedStyle(document.documentElement).fontSize)
    }));
    const indicators = await page.locator('.auth-carousel-indicators button').evaluateAll(buttons => buttons.map(el => {
      const style = getComputedStyle(el);
      return { height: el.getBoundingClientRect().height, width: el.getBoundingClientRect().width,
        radius: parseFloat(style.borderTopLeftRadius), border: parseFloat(style.borderTopWidth), active: el.classList.contains('active') };
    }));
    indicators.forEach(indicator => {
      assert.equal(indicator.height, 6);
      assert.equal(indicator.width, indicator.active ? 40 : 24);
      assert.ok(indicator.radius >= indicator.height / 2, 'Carousel indicators must have fully rounded ends');
      assert.equal(indicator.border, 0, 'Bootstrap indicator borders must not flatten the pill');
    });
    assert.equal(geometry.height, geometry.expected);
    assert.notEqual(geometry.background, 'rgb(172, 30, 55)');
    assert.equal(await page.locator('.carousel-item').first().locator('.auth-slide-img-bg').count(), 0);
    for (const direction of ['next', 'next', 'next', 'prev']) {
      const frames = await page.evaluate(direction => new Promise(resolve => {
        const el = document.querySelector('#loginPhotoCarousel');
        const samples = [];
        let finished = false;
        el.addEventListener('slid.bs.carousel', () => { finished = true; }, { once: true });
        function frame() {
          const visible = [...el.querySelectorAll('.carousel-item')].filter(item => getComputedStyle(item).display !== 'none');
          const opacity = visible.map(item => Number(getComputedStyle(item).opacity));
          samples.push({
            opacity,
            coverage: 1 - opacity.reduce((remaining, value) => remaining * (1 - value), 1),
            partial: opacity.some(value => value > .05 && value < .95),
            heights: visible.map(item => item.getBoundingClientRect().height)
          });
          if (finished) resolve(samples); else requestAnimationFrame(frame);
        }
        window.carousel[direction]();
        requestAnimationFrame(frame);
      }), direction);
      assert.ok(frames.length > 2, 'Sample the fade itself, not just its endpoint');
      assert.ok(frames.some(frame => frame.partial), `${theme} ${direction}: incoming fade missing; ${frames.length} samples, opacities ${JSON.stringify([...new Set(frames.flatMap(frame => frame.opacity))])}`);
      frames.forEach(frame => {
        assert.ok(frame.coverage >= .999, `Backdrop exposed: ${JSON.stringify(frame)}`);
        frame.heights.forEach(height => assert.equal(height, geometry.height));
      });
    }
    const blur = await page.locator('.carousel-item').nth(1).locator('.auth-slide-img-bg').evaluateAll(images =>
      images.filter(image => getComputedStyle(image).display !== 'none').map(image => getComputedStyle(image).filter));
    assert.equal(blur.length, 1);
    assert.ok(blur[0].includes('blur(22px)'));
  });
}

for (const theme of ['light', 'dark']) {
  test(`${theme}: login theme icon is centered in its circular button`, async t => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    t.after(() => context.close());
    const page = await context.newPage();
    await page.goto(`${origin}/fixture?theme=${theme}`);
    await page.evaluate(() => document.fonts.ready);
    const alignment = await page.locator('#authThemeToggle').evaluate(button => {
      const icons = [...button.querySelectorAll('.bi')].filter(el => getComputedStyle(el).display !== 'none');
      const holder = button.getBoundingClientRect();
      const icon = icons[0].getBoundingClientRect();
      return { visible: icons.length, x: Math.abs(icon.left + icon.width / 2 - holder.left - holder.width / 2),
        y: Math.abs(icon.top + icon.height / 2 - holder.top - holder.height / 2),
        pseudoDisplay: getComputedStyle(icons[0], '::before').display };
    });
    assert.equal(alignment.visible, 1);
    assert.ok(alignment.x <= 1 && alignment.y <= 1, 'Theme icon must be centered on both axes');
    assert.equal(alignment.pseudoDisplay, 'block', 'Icon must not use an inline baseline offset');
  });
  test(`${theme}: login banner close icons stay dark on light alert fills and dismiss correctly`, async t => {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
    t.after(() => context.close());
    const page = await context.newPage();
    await page.goto(`${origin}/fixture?theme=${theme}&tall=1`);
    await page.waitForFunction(() => window.carousel);
    for (const variant of ['info', 'warning', 'danger', 'success']) {
      await page.locator('.auth-pane').evaluate((pane, variant) => {
        pane.innerHTML = `<div class="alert alert-${variant} alert-dismissible show" role="alert">Message<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
      }, variant);
      const close = page.locator('.auth-pane .btn-close');
      const style = await close.evaluate(el => {
        const css = getComputedStyle(el);
        return { filter: css.filter, opacity: Number(css.opacity), size: parseFloat(css.backgroundSize), top: parseFloat(css.top), right: parseFloat(css.right), rem: parseFloat(getComputedStyle(document.documentElement).fontSize) };
      });
      assert.equal(style.filter, 'none', 'Dark-mode inversion must not whiten the glyph on a pale banner');
      assert.ok(style.opacity >= 0.75);
      assert.equal(style.size, 0.625 * style.rem);
      assert.deepEqual([style.top, style.right], [0.25 * style.rem, 0.25 * style.rem]);
      await close.click();
      assert.equal(await page.locator('.auth-pane .alert').count(), 0);
    }
  });
  test(`${theme}: carousel fills the column when zoom-equivalent sign-in content exceeds the viewport`, async t => {
    const context = await browser.newContext({ viewport: { width: 1024, height: 600 }, reducedMotion: 'reduce' });
    t.after(() => context.close());
    const page = await context.newPage();
    await page.goto(`${origin}/fixture?theme=${theme}&tall=1`);
    await page.waitForFunction(() => window.carousel);
    const geometry = await page.evaluate(() => {
      const photo = document.querySelector('.auth-photo-container').getBoundingClientRect();
      const aside = document.querySelector('.auth-photo-aside').getBoundingClientRect();
      const pane = document.querySelector('.auth-pane').getBoundingClientRect();
      const carousel = document.querySelector('#loginPhotoCarousel').getBoundingClientRect();
      return { photoHeight: photo.height, carouselHeight: carousel.height, gap: pane.bottom - photo.bottom,
        inset: photo.top - aside.top, asideBottom: aside.bottom, paneBottom: pane.bottom,
        pageHeight: document.documentElement.scrollHeight, width: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth };
    });
    assert.ok(geometry.photoHeight > 600, 'Photo must grow with the content rather than stop at the viewport');
    assert.equal(geometry.carouselHeight, geometry.photoHeight);
    assert.equal(geometry.asideBottom, geometry.paneBottom);
    assert.equal(geometry.gap, geometry.inset, 'Keep only the intended inset below the photo');
    assert.ok(geometry.scrollWidth <= geometry.width, 'No horizontal overflow at enlarged zoom');
  });
}

test('reduced motion disables both incoming animation and outgoing delay', async t => {
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
  t.after(() => context.close());
  const page = await context.newPage();
  await page.goto(`${origin}/fixture`);
  await page.waitForFunction(() => window.carousel);
  const transitions = await page.locator('.carousel-item').evaluateAll(items => items.map(item => ({
    duration: getComputedStyle(item).transitionDuration, delay: getComputedStyle(item).transitionDelay
  })));
  transitions.forEach(value => assert.deepEqual(value, { duration: '0s', delay: '0s' }));
  await page.evaluate(() => window.carousel.next());
  await page.waitForFunction(() => document.querySelectorAll('.carousel-item')[1].classList.contains('active'));
});
