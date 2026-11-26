'use strict';

const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '../../..');
const { chromium } = require(path.join(ROOT, 'writable/widget-test-tools/node_modules/playwright'));

const BASE_URL = (process.env.SYNAPSE_LIVE_BASE_URL || 'http://localhost:8080/index.php').replace(/\/+$/, '');
const FFMPEG = fs.existsSync('C:/ffmpeg/bin/ffmpeg.exe') ? 'C:/ffmpeg/bin/ffmpeg.exe' : 'ffmpeg';

const EVIDENCE_ROOT = path.join(ROOT, 'papers/blackbox/evidence/dryrun-2026-11-26');
const VIDEOS_DIR = path.join(EVIDENCE_ROOT, 'videos');
const SCREENSHOTS_DIR = path.join(EVIDENCE_ROOT, 'screenshots');
const TMP_WEBM_DIR = path.join(EVIDENCE_ROOT, 'tmp-webm');

for (const dir of [EVIDENCE_ROOT, VIDEOS_DIR, SCREENSHOTS_DIR, TMP_WEBM_DIR]) {
  fs.mkdirSync(dir, { recursive: true });
}

function findChromiumExecutable() {
  if (fs.existsSync(chromium.executablePath())) {
    return chromium.executablePath();
  }
  const dir = path.join(os.homedir(), 'AppData/Local/ms-playwright');
  const found = fs.readdirSync(dir)
    .filter((n) => /^chromium-\d+$/.test(n))
    .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
    .map((n) => path.join(dir, n, 'chrome-win64/chrome.exe'))
    .find((p) => fs.existsSync(p));
  if (!found) {
    throw new Error('Playwright Chromium executable not found.');
  }
  return found;
}

async function launchBrowser() {
  return chromium.launch({
    executablePath: findChromiumExecutable(),
    headless: true,
  });
}

function loadDefenseCatalog() {
  return JSON.parse(
    fs.readFileSync(path.join(ROOT, 'papers/blackbox/defense-sheet.json'), 'utf8')
  );
}

async function pause(page, ms = 420) {
  await page.waitForTimeout(ms);
}

async function loginAs(page, username, password = 'password123', baseUrl = BASE_URL) {
  const res = await page.goto(`${baseUrl}/auth/login`, { waitUntil: 'domcontentloaded' });
  assert.equal(res.status(), 200, `Login page HTTP ${res.status()} for ${username}`);
  await page.locator('#identifier').fill(username);
  await page.locator('#password').fill(password);
  await pause(page, 250);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    page.getByRole('button', { name: 'Sign in', exact: true }).click(),
  ]);
  await pause(page, 400);
  assert.ok(!page.url().includes('/auth/login'), `Login stayed on /auth/login for ${username} (at ${page.url()})`);
}

async function confirmModalIfVisible(page) {
  const modal = page.locator('#synapseConfirmModal');
  const visible = await modal.waitFor({ state: 'visible', timeout: 1400 }).then(() => true).catch(() => false);
  if (visible) {
    const reasonBox = modal.locator('textarea, input[type="text"]').first();
    if (await reasonBox.count() > 0 && await reasonBox.isVisible()) {
      await reasonBox.fill('Schedule conflict during dry run');
    }
    await pause(page, 250);
    await modal.locator('[data-confirm-accept]').click();
  }
}

async function submitAndExpect(page, triggerLocator, urlSubstr) {
  const [res] = await Promise.all([
    page.waitForResponse(
      (r) => r.request().method() === 'POST' && r.url().includes(urlSubstr),
      { timeout: 20000 }
    ),
    (async () => {
      await triggerLocator.click();
      await confirmModalIfVisible(page);
    })(),
  ]);
  const ct = (res.headers()['content-type'] || '').toLowerCase();
  const text = await res.text().catch(() => '');
  assert.ok(res.status() < 400, `POST ${urlSubstr} returned HTTP ${res.status()}: ${text.slice(0, 250)}`);
  if (ct.includes('application/json') && text.trim()) {
    const data = JSON.parse(text);
    if (Object.prototype.hasOwnProperty.call(data, 'success')) {
      assert.equal(data.success, true, `POST ${urlSubstr} JSON failed: ${text.slice(0, 250)}`);
    }
  }
  await pause(page, 400);
  return { res, text };
}

async function assertCleanPage(page, urlPath, expectedText = null, baseUrl = BASE_URL) {
  const target = urlPath.startsWith('http') ? urlPath : `${baseUrl}${urlPath}`;
  const res = await page.goto(target, { waitUntil: 'load', timeout: 20000 });
  assert.equal(res.status(), 200, `Expected HTTP 200 on ${target}, got ${res.status()}`);
  if (expectedText) {
    const body = await page.locator('body').innerText();
    assert.ok(
      body.toLowerCase().includes(expectedText.toLowerCase()),
      `Expected text '${expectedText}' on ${target}`
    );
  }
  await pause(page, 380);
  return res;
}

function transcodeWebmToMp4(webmPath, mp4Path) {
  execFileSync(
    FFMPEG,
    [
      '-y',
      '-i', webmPath,
      '-c:v', 'libx264',
      '-preset', 'veryfast',
      '-crf', '24',
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      mp4Path,
    ],
    { stdio: 'pipe' }
  );
  const stat = fs.statSync(mp4Path);
  if (stat.size < 1024) {
    throw new Error(`Transcoded MP4 too small (${stat.size} bytes): ${mp4Path}`);
  }
  fs.unlinkSync(webmPath);
}

const sessionCache = new Map();

async function getStorageState(browser, username, password = 'password123', baseUrl = BASE_URL) {
  const key = `${baseUrl}::${username}`;
  if (sessionCache.has(key)) {
    return sessionCache.get(key);
  }
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 } });
  const page = await ctx.newPage();
  try {
    await loginAs(page, username, password, baseUrl);
    const state = await ctx.storageState();
    sessionCache.set(key, state);
    return state;
  } finally {
    await ctx.close();
  }
}

/**
 * Execute one black-box case inside a fresh isolated BrowserContext with 1280x720 MP4 recording.
 */
async function runCase(browser, trackName, meta, fn, { allowHttpError = false, authUser = null, baseUrl = BASE_URL } = {}) {
  const onlyRaw = (process.env.DRYRUN_ONLY_IDS || '').trim();
  if (onlyRaw) {
    const onlySet = new Set(onlyRaw.split(',').map((s) => s.trim()).filter(Boolean));
    if (!onlySet.has(meta.id)) {
      return null;
    }
  }
  const start = Date.now();
  const contextOptions = {
    viewport: { width: 1280, height: 720 },
    recordVideo: { dir: TMP_WEBM_DIR, size: { width: 1280, height: 720 } },
  };
  let preLoginError = null;
  if (authUser) {
    try {
      contextOptions.storageState = await getStorageState(browser, authUser, 'password123', baseUrl);
    } catch (err) {
      preLoginError = err;
    }
  }
  const context = await browser.newContext(contextOptions);
  const page = await context.newPage();
  const videoObj = page.video();

  const consoleErrors = [];
  const pageErrors = [];
  const http5xx = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const txt = msg.text();
      if (allowHttpError && /Failed to load resource: the server responded with a status of (401|403|404|422|429)/.test(txt)) {
        return;
      }
      consoleErrors.push(txt);
    }
  });
  page.on('pageerror', (err) => {
    pageErrors.push(`[${page.url()}] ${err?.message || String(err)}`);
  });
  page.on('response', (res) => {
    if (res.status() >= 500) {
      http5xx.push(`${res.status()} ${res.url()}`);
    }
  });

  let status = 'PASS';
  let observed = '';
  let errorMsg = '';
  let screenshotRel = null;

  try {
    if (preLoginError) {
      throw preLoginError;
    }
    observed = (await fn(page)) || 'Verified cleanly in browser.';
    if (http5xx.length > 0) {
      throw new Error(`HTTP 5xx observed: ${http5xx.join('; ')}`);
    }
    if (pageErrors.length > 0) {
      throw new Error(`Uncaught JS pageerror: ${pageErrors.join('; ')}`);
    }
    if (!allowHttpError && consoleErrors.length > 0) {
      throw new Error(`Browser console errors: ${consoleErrors.join('; ')}`);
    }
  } catch (err) {
    status = 'FAIL';
    errorMsg = err?.stack ? err.message : String(err);
    const shotPath = path.join(SCREENSHOTS_DIR, `${meta.id}-fail.png`);
    await page.screenshot({ path: shotPath, fullPage: true }).catch(() => {});
    if (fs.existsSync(shotPath)) {
      screenshotRel = `papers/blackbox/evidence/dryrun-2026-11-26/screenshots/${meta.id}-fail.png`;
    }
  } finally {
    await page.waitForTimeout(300).catch(() => {});
    await page.close().catch(() => {});
    await context.close().catch(() => {});
  }

  let videoRel = null;
  if (videoObj) {
    const rawWebm = await videoObj.path().catch(() => null);
    if (rawWebm && fs.existsSync(rawWebm)) {
      const mp4Path = path.join(VIDEOS_DIR, `${meta.id}.mp4`);
      transcodeWebmToMp4(rawWebm, mp4Path);
      videoRel = `papers/blackbox/evidence/dryrun-2026-11-26/videos/${meta.id}.mp4`;
    }
  }

  const record = {
    id: meta.id,
    track: trackName,
    role: meta.role,
    module: meta.module,
    use_cases: meta.use_cases,
    scenario: meta.scenario,
    status,
    duration_ms: Date.now() - start,
    observed,
    error: errorMsg,
    console_errors: consoleErrors,
    page_errors: pageErrors,
    http_5xx: http5xx,
    video: videoRel,
    screenshot: screenshotRel,
  };
  const tag = status === 'PASS' ? 'PASS' : 'FAIL';
  console.log(`[${trackName}] ${meta.id} (${meta.role}) -> ${tag} (${record.duration_ms}ms)${errorMsg ? ' :: ' + errorMsg.slice(0, 140) : ''}`);
  return record;
}

module.exports = {
  ROOT,
  BASE_URL,
  EVIDENCE_ROOT,
  launchBrowser,
  loadDefenseCatalog,
  runCase,
  loginAs,
  confirmModalIfVisible,
  submitAndExpect,
  assertCleanPage,
  pause,
};
