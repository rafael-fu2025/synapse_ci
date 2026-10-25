'use strict';
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');
const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
let server, browser, origin;
const requests = [];
const label = 'Illustration only — motor state and RPM are not measured.';
function gear(seed, extra = '') {
  return `<div data-bmg-gear-host ${extra}><div class="bmg-gear" data-bmg-gear data-gear-seed="${seed}" aria-hidden="true">
    <img class="bmg-drum-art-light" src="/sub/drum-maroon.png" alt="">
    <img class="bmg-drum-art-dark" src="/sub/drum-whte.png" alt="">
    <canvas data-bmg-gear-canvas width="192" height="192" hidden></canvas></div></div>`;
}
function fixture(url) {
  return `<!doctype html><html data-theme="light"><head><style>
    .bmg-gear { width:96px;height:96px;position:relative; }
    .bmg-gear canvas,.bmg-gear img { width:100%;height:100%; }
    .bmg-gear canvas[hidden] { display:none; }
    .bmg-drum-art-dark { display:none; }
    html[data-theme="dark"] .bmg-drum-art-light { display:none; }
    html[data-theme="dark"] .bmg-drum-art-dark { display:block; }
    .bmg-gear[data-gear-ready] img { display:none; }
  </style></head><body><main>${gear(1)}${gear(2)}${gear(3, 'style="margin-top:1800px"')}</main>
    <script src="/sub/assets/js/bmg-gear.js" ${url.searchParams.has('missing') ? 'data-renderer-url="/sub/assets/js/missing-renderer.js"' : ''}></script></body></html>`;
}
before(async () => {
  server = http.createServer((req, res) => {
    requests.push(req.url);
    const url = new URL(req.url, origin);
    const pathname = url.pathname.replace(/^\/sub/, '');
    if (pathname.startsWith('/assets/') || /^\/drum-(maroon|whte)\.png$/.test(pathname)) {
      const file = path.resolve(root, 'public', '.' + pathname);
      if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
      res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'image/png');
      res.end(fs.readFileSync(file)); return;
    }
    res.setHeader('Content-Type', 'text/html'); res.end(fixture(url));
  });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.readdirSync(dir).filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(dir, name, 'chrome-win64/chrome.exe')).find(file => fs.existsSync(file));
  }
  browser = await chromium.launch({ executablePath, headless: true, timeout: 20000,
    args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });
async function open(t, { blocked = false, missing = false, reducedMotion = 'no-preference', empty = false } = {}) {
  const context = await browser.newContext({ reducedMotion });
  t.after(() => context.close());
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(({ blocked, empty }) => {
    window.stats = { contexts: [], frames: 0, blits: 0, losses: 0, io: 0, mo: 0 };
    const original = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function (type, ...args) {
      if (/^webgl/.test(type) && blocked) return null;
      const ctx = original.call(this, type, ...args);
      if (/^webgl/.test(type) && ctx && !stats.contexts.includes(ctx)) {
        stats.contexts.push(ctx);
        this.addEventListener('webglcontextlost', () => stats.losses++);
      }
      return ctx;
    };
    const raf = window.requestAnimationFrame;
    window.requestAnimationFrame = callback => { stats.frames++; return raf.call(window, callback); };
    const draw = CanvasRenderingContext2D.prototype.drawImage;
    CanvasRenderingContext2D.prototype.drawImage = function (...args) { stats.blits++; return draw.apply(this, args); };
    for (const [name, key] of [['IntersectionObserver', 'io'], ['MutationObserver', 'mo']]) {
      const Native = window[name];
      window[name] = class extends Native {
        constructor(...args) {
                  super(...args);
                  // Do not count Playwright's own injected DOM observer.
                  this.counted = new Error().stack.includes('bmg-gear');
                  if (this.counted) stats[key]++;
                }
        disconnect() { if (this.counted) { stats[key]--; this.counted = false; } return super.disconnect(); }
      };
    }
    if (empty) document.addEventListener('DOMContentLoaded', () => {
      document.querySelector('main').replaceChildren();
    }, { once: true });
  }, { blocked, empty });
  await page.goto(origin + '/sub/fixture' + (missing ? '?missing' : ''));
  await page.waitForFunction(() => !!window.SynapseBmgGear, { }, { polling: 100, timeout: 10000 });
  return { page, errors };
}
async function ready(page) {
  await page.waitForFunction(() => document.querySelectorAll('[data-gear-ready]').length >= 2,
    {}, { polling: 100, timeout: 15000 });
}
const sleep = page => page.waitForTimeout(250);

test('real local model: one shared WebGL context, static idle contract, themes, offscreen and cleanup', { timeout: 30000 }, async t => {
  const { page, errors } = await open(t);
  await ready(page);
  const snapshot = await page.evaluate(async () => {
    const module = await import('/sub/assets/js/bmg-gear-renderer.js');
    const canvas = document.querySelector('canvas');
    const pixels = canvas.getContext('2d').getImageData(0, 0, 192, 192).data;
    return { contexts: stats.contexts.length, visual: module.ILLUSTRATION,
      alpha: Array.from(pixels).filter((v, i) => i % 4 === 3 && v > 0).length,
      titles: [...document.querySelectorAll('[data-bmg-gear]')].map(el => el.title),
      initial: canvas.toDataURL(), blits: stats.blits };
  });
  assert.equal(snapshot.contexts, 1);
  assert.deepEqual(snapshot.visual, { mode: 'idle', speed: 0, progress: null, label });
  assert(snapshot.alpha > 1000, 'actual geometry must paint nontransparent pixels');
  assert(snapshot.titles.every(value => value === label), JSON.stringify(snapshot.titles));
  assert.equal(snapshot.blits, 2, 'offscreen third gear must not render');
  await page.evaluate(() => {
    const wrapper = document.querySelector('[data-bmg-gear]');
    wrapper.dataset.status = 'running'; wrapper.dataset.progress = '80'; wrapper.dataset.speed = '999';
    wrapper.dispatchEvent(new PointerEvent('pointerenter')); wrapper.dispatchEvent(new FocusEvent('focusin'));
    SynapseBmgGear.init();
  });
  await sleep(page);
  assert.equal(await page.locator('canvas').first().evaluate(el => el.toDataURL()), snapshot.initial);
  const frames = await page.evaluate(() => stats.frames);
  await sleep(page);
  assert.equal(await page.evaluate(() => stats.frames), frames, 'no continuous animation');
  await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
  await page.waitForFunction(initial => document.querySelector('canvas').toDataURL() !== initial,
    snapshot.initial, { polling: 100 });
  await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
  await page.waitForFunction(initial => document.querySelector('canvas').toDataURL() === initial,
    snapshot.initial, { polling: 100 });
  const beforeHidden = await page.evaluate(() => stats.blits);
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    document.dispatchEvent(new Event('visibilitychange'));
    document.documentElement.dataset.theme = 'dark';
  });
  await sleep(page);
  assert.equal(await page.evaluate(() => stats.blits), beforeHidden, 'hidden document must not render');
  await page.evaluate(() => {
    delete document.hidden;
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.waitForFunction(initial => document.querySelector('canvas').toDataURL() !== initial,
    snapshot.initial, { polling: 100 });
  await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
  await page.waitForFunction(initial => document.querySelector('canvas').toDataURL() === initial,
    snapshot.initial, { polling: 100 });
  await page.evaluate(() => document.querySelectorAll('[data-bmg-gear-host]')[2].scrollIntoView());
  await page.waitForFunction(() => document.querySelectorAll('[data-gear-ready]').length === 3, {}, { polling: 100 });
  const old = await page.locator('canvas').first().evaluate(el => el.toDataURL());
  await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
  await sleep(page);
  assert.equal(await page.locator('canvas').first().evaluate(el => el.toDataURL()), old, 'offscreen gear is paused');
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('main') } }));
  });
  const disposed = await page.evaluate(() => ({ io: stats.io, mo: stats.mo,
    ready: document.querySelectorAll('[data-gear-ready]').length,
    hidden: [...document.querySelectorAll('canvas')].every(el => el.hidden) }));
  assert.deepEqual(disposed, { io: 0, mo: 0, ready: 0, hidden: true });
  assert.equal(errors.length, 0, errors.join('\n'));
});

test('reduced motion stays still; legacy theme and context-loss fallback', { timeout: 25000 }, async t => {
  const { page, errors } = await open(t, { reducedMotion: 'reduce' });
  await ready(page);
  const initial = await page.locator('canvas').first().evaluate(el => el.toDataURL());
  await page.evaluate(() => { document.documentElement.removeAttribute('data-theme'); document.body.classList.add('dark-mode'); });
  await page.waitForFunction(initial => document.querySelector('canvas').toDataURL() !== initial, initial, { polling: 100 });
  const frames = await page.evaluate(() => stats.frames);
  await sleep(page);
  assert.equal(await page.evaluate(() => stats.frames), frames);
  await page.evaluate(() => {
    document.documentElement.dataset.theme = 'dark';
    stats.contexts[0].canvas.dispatchEvent(new Event('webglcontextlost', { cancelable: true }));
  });
  assert.equal(await page.locator('[data-gear-ready]').count(), 0);
  assert.equal(await page.locator('canvas').first().evaluate(el => el.hidden), true);
  assert.equal(await page.locator('.bmg-drum-art-dark').first().isVisible(), true);
  await page.evaluate(() => SynapseBmgGear.destroy());
  assert.deepEqual(await page.evaluate(() => [stats.io, stats.mo]), [0, 0]);
  assert.deepEqual(errors, []);
});

for (const missing of [false, true]) test(`${missing ? 'module failure' : 'WebGL unavailable'} preserves both themed artwork fallbacks without retries`, { timeout: 20000 }, async t => {
  const start = requests.length;
  const { page, errors } = await open(t, { blocked: true, missing });
  await sleep(page);
  assert.equal(await page.locator('[data-gear-ready]').count(), 0);
  assert.equal(await page.locator('.bmg-drum-art-light').first().isVisible(), true);
  assert.equal(await page.locator('.bmg-drum-art-dark').first().isVisible(), false);
  await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; SynapseBmgGear.init(); });
  assert.equal(await page.locator('.bmg-drum-art-dark').first().isVisible(), true);
  assert.equal(await page.locator('canvas').first().evaluate(el => el.hidden), true);
  const count = requests.length;
  await sleep(page);
  assert.equal(requests.length, count);
  assert(requests.slice(start).every(url => /^\/sub\/(fixture|assets\/js\/|drum-)/.test(url) || url === '/favicon.ico'), 'no API or hardware requests');
  assert.deepEqual(errors, []);
});

test('SPA remount, pagehide/pageshow and direct removal release observers and contexts', { timeout: 30000 }, async t => {
  const { page, errors } = await open(t);
  await ready(page);
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('main') } }));
    document.dispatchEvent(new CustomEvent('synapse:page-load', { detail: { root: document.querySelector('main') } }));
  });
  await ready(page);
  assert.equal(await page.evaluate(() => stats.contexts.length), 2);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
  assert.deepEqual(await page.evaluate(() => [stats.io, stats.mo]), [0, 0]);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await ready(page);
  assert.equal(await page.evaluate(() => stats.contexts.length), 3);
  await page.evaluate(() => document.querySelector('main').replaceChildren());
  await page.waitForFunction(() => stats.io === 0 && stats.mo === 0, {}, { polling: 100 });
  assert.equal(await page.evaluate(() => stats.losses), 3);
  assert.deepEqual(errors, []);
});

test('cancel lazy attachment during navigation and avoid loading on pages without gears', { timeout: 20000 }, async t => {
  const { page } = await open(t, { empty: true });
  await sleep(page);
  assert.equal(await page.evaluate(() => stats.contexts.length), 0);
  await page.route('**/bmg-gear-renderer.js', async route => {
    await new Promise(resolve => setTimeout(resolve, 150));
    await route.continue();
  });
  await page.evaluate(markup => {
    const main = document.querySelector('main'); main.innerHTML = markup;
    SynapseBmgGear.init(main); SynapseBmgGear.destroy(main); main.replaceChildren();
  }, gear(4));
  await page.waitForTimeout(500);
  assert.equal(await page.evaluate(() => stats.contexts.length), 0);
  assert.deepEqual(await page.evaluate(() => [stats.io, stats.mo]), [0, 0]);
});
