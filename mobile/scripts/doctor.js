/* global AbortSignal */
'use strict';

const path = require('node:path');
const { PORTS, runAdb, requireSingleDevice } = require('./android-usb');

const MOBILE_ROOT = path.resolve(__dirname, '..');
const METRO_URL = 'http://127.0.0.1:8083/status';
const BACKEND_URL = 'http://127.0.0.1:8080/api/v1/me';
const TIMEOUT_MS = 4000;

function normalizeProjectRoot(root, platform = process.platform) {
  // The CLI's header can contain URL-escaped spaces and forward slashes on Windows.
  let decoded = root;
  try {
    decoded = decodeURIComponent(root);
  } catch {
    // A literal percent in a filesystem path need not be URL-encoded.
  }
  const normalized =
    platform === 'win32'
      ? path.win32.normalize(decoded.replace(/\//g, '\\')).toLowerCase()
      : path.posix.normalize(decoded);
  return normalized.replace(/[\\/]+$/, '');
}

function get(url, fetchImpl) {
  return fetchImpl(url, {
    method: 'GET',
    headers: { Accept: 'application/json' },
    credentials: 'omit',
    redirect: 'error',
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });
}

async function checkMetro({
  fetchImpl = fetch,
  projectRoot = MOBILE_ROOT,
  platform = process.platform,
} = {}) {
  const response = await get(METRO_URL, fetchImpl);
  if (
    response.status !== 200 ||
    (await response.text()).trim() !== 'packager-status:running'
  ) {
    throw new Error(
      `Expected Metro status at ${METRO_URL}; HTTP ${response.status}. Start npm start from mobile in a separate terminal.`,
    );
  }
  const actualRoot = response.headers.get('X-React-Native-Project-Root');
  if (
    !actualRoot ||
    normalizeProjectRoot(actualRoot, platform) !==
      normalizeProjectRoot(projectRoot, platform)
  ) {
    throw new Error(
      `Metro project-root mismatch: expected ${projectRoot}, received ${
        actualRoot || '(missing header)'
      }. ` +
        'Inspect the process on 8083 and start Metro from this checkout; no process was stopped.',
    );
  }
  return `Metro belongs to ${projectRoot} (${METRO_URL}).`;
}

async function checkBackend({ fetchImpl = fetch } = {}) {
  const response = await get(BACKEND_URL, fetchImpl);
  if (response.status === 429) {
    throw new Error(
      `Backend is reachable but rate limited (429); honor Retry-After: ${
        response.headers.get('Retry-After') || '(not supplied)'
      }. Do not reset credentials or retry sign-in probes.`,
    );
  }
  if (response.status !== 401) {
    throw new Error(
      `Expected unauthenticated HTTP 401 from ${BACKEND_URL}, received ${response.status}. Check the PHP API listener/routes, not credentials.`,
    );
  }
  const contentType = response.headers.get('content-type') || '';
  if (!/^application\/(?:[\w.+-]+\+)?json\s*(?:;|$)/i.test(contentType)) {
    throw new Error(
      `Expected a JSON error from ${BACKEND_URL}, received ${
        contentType || '(no content-type)'
      }. Check the API route, not a web login page.`,
    );
  }
  const body = await response.json();
  if (
    !body?.error ||
    typeof body.error.code !== 'string' ||
    !body.error.code ||
    typeof body.error.message !== 'string' ||
    !body.error.message
  ) {
    throw new Error(`Expected {error: {code, message}} from ${BACKEND_URL}.`);
  }
  return `Backend returned the expected unauthenticated 401 JSON error (${BACKEND_URL}).`;
}

function checkReverse({ spawn } = {}) {
  const serial = requireSingleDevice(spawn);
  const output = runAdb(['-s', serial, 'reverse', '--list'], spawn);
  const mappings = output.split(/\r?\n/).map(line => line.trim().split(/\s+/));
  const missing = PORTS.filter(
    port =>
      !mappings.some(
        fields =>
          fields.length >= 3 &&
          fields[fields.length - 2] === `tcp:${port}` &&
          fields[fields.length - 1] === `tcp:${port}`,
      ),
  );
  if (missing.length) {
    throw new Error(
      `Missing adb reverse mappings on ${serial}: ${missing.join(
        ', ',
      )}. Run node scripts/android-usb.js (also after USB reconnect).`,
    );
  }
  return `ADB ${serial}: ports ${PORTS.join(', ')} are reversed (mappings only; device connectivity is not probed).`;
}

async function runDoctor({ adb = false, log = console.log, ...options } = {}) {
  const checks = [
    ['Metro', () => checkMetro(options)],
    ['Backend', () => checkBackend(options)],
  ];
  if (adb) {
    checks.push(['ADB reverse', () => checkReverse(options)]);
  }
  const results = await Promise.all(
    checks.map(async ([name, check]) => {
      try {
        return { ok: true, message: `OK ${name}: ${await check()}` };
      } catch (error) {
        return {
          ok: false,
          message: `FAIL ${name}: ${error.message}. Check the listener/connection if unavailable; this tool starts or stops no Metro/PHP servers.`,
        };
      }
    }),
  );
  for (const result of results) {
    log(result.message);
  }
  return results.every(result => result.ok) ? 0 : 1;
}

async function main(args = process.argv.slice(2)) {
  if (args.some(arg => arg !== '--adb')) {
    console.error('Usage: npm run doctor [-- --adb]');
    return 1;
  }
  return runDoctor({ adb: args.includes('--adb') });
}

if (require.main === module) {
  main().then(
    code => {
      process.exitCode = code;
    },
    error => {
      console.error(`Doctor failed: ${error.message}`);
      process.exitCode = 1;
    },
  );
}

module.exports = {
  normalizeProjectRoot,
  checkMetro,
  checkBackend,
  checkReverse,
  runDoctor,
  main,
};
