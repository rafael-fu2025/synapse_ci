/* eslint-env node, es2022 */
/* global Response */
'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  parseDevices,
  runAdb,
  requireSingleDevice,
  prepareUsb,
} = require('./android-usb');
const {
  normalizeProjectRoot,
  checkMetro,
  checkBackend,
  checkReverse,
  runDoctor,
  main,
} = require('./doctor');

const DEVICE = 'List of devices attached\r\nusb-test\tdevice product:test\r\n';
const ROOT = 'C:\\work\\humus\\mobile';

function adbMock(devices = DEVICE, mappings = '') {
  const calls = [];
  const spawn = (command, args, options) => {
    calls.push({ command, args, options });
    return {
      status: 0,
      stdout: args[0] === 'devices' ? devices : mappings,
      stderr: '',
    };
  };
  return { calls, spawn };
}

function metro(root = ROOT, body = 'packager-status:running') {
  return new Response(body, {
    headers: root ? { 'X-React-Native-Project-Root': root } : {},
  });
}

function backend(
  status = 401,
  body = { error: { code: 'unauthenticated', message: 'Sign-in required.' } },
) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json; charset=UTF-8' },
  });
}

test('parses Windows adb output and ignores daemon notices', () => {
  assert.deepEqual(
    parseDevices('* daemon started successfully *\r\n' + DEVICE),
    [{ serial: 'usb-test', state: 'device' }],
  );
});

test('setup reverses API, WebSocket and Metro but never the private publisher', () => {
  const { calls, spawn } = adbMock();
  assert.equal(prepareUsb(spawn), 'usb-test');
  assert.deepEqual(
    calls.map(call => call.args),
    [
      ['devices', '-l'],
      ['-s', 'usb-test', 'reverse', 'tcp:8080', 'tcp:8080'],
      ['-s', 'usb-test', 'reverse', 'tcp:8081', 'tcp:8081'],
      ['-s', 'usb-test', 'reverse', 'tcp:8083', 'tcp:8083'],
    ],
  );
  for (const { command, options } of calls) {
    assert.equal(command, 'adb');
    assert.equal(options.shell, false);
    assert.equal(options.timeout, 10000);
    assert.equal(options.windowsHide, true);
  }
});

for (const [name, devices] of [
  ['no devices', 'List of devices attached\n'],
  ['unauthorized', 'usb-test\tunauthorized\n'],
  ['offline', 'usb-test\toffline\n'],
  ['multiple authorized', 'usb-test\tdevice\nemulator-5554\tdevice\n'],
  ['authorized plus unauthorized', 'usb-test\tdevice\nother\tunauthorized\n'],
]) {
  test(`rejects ${name} without changing mappings`, () => {
    const { calls, spawn } = adbMock(devices);
    assert.throws(() => prepareUsb(spawn), /Exactly one authorized.*RSA/);
    assert.equal(calls.length, 1);
  });
}

test('one authorized emulator is accepted', () => {
  const { spawn } = adbMock('emulator-5554 device product:sdk\n');
  assert.equal(requireSingleDevice(spawn), 'emulator-5554');
});

test('missing adb and bounded spawn failures are actionable', () => {
  assert.throws(
    () =>
      runAdb(['devices'], () => ({
        error: Object.assign(new Error('missing'), { code: 'ENOENT' }),
      })),
    /platform-tools.*adb.exe/,
  );
  assert.throws(
    () =>
      runAdb(['devices'], () => ({
        error: Object.assign(new Error('timed out'), { code: 'ETIMEDOUT' }),
      })),
    /timed out/,
  );
});

test('a failed reverse prevents subsequent setup commands', () => {
  const { calls, spawn } = adbMock();
  const failingSpawn = (...args) => {
    const result = spawn(...args);
    return calls.length === 2
      ? { ...result, status: 1, stderr: 'device disconnected' }
      : result;
  };
  assert.throws(() => prepareUsb(failingSpawn), /device disconnected/);
  assert.equal(calls.length, 2);
});

test('Windows project roots normalize case, slashes, escaping and trailing separators', () => {
  assert.equal(
    normalizeProjectRoot('c:/Work%20Tree/Humus/mobile/', 'win32'),
    normalizeProjectRoot('C:\\WORK TREE\\humus\\mobile', 'win32'),
  );
  assert.notEqual(
    normalizeProjectRoot('/Work/mobile', 'linux'),
    normalizeProjectRoot('/work/mobile', 'linux'),
  );
  assert.equal(
    normalizeProjectRoot('C:/100%/mobile', 'win32'),
    'c:\\100%\\mobile',
  );
});

test('Metro requires both the running marker and this checkout root', async () => {
  const options = { projectRoot: ROOT, platform: 'win32' };
  await checkMetro({
    ...options,
    fetchImpl: async () => metro('c:/WORK/humus/mobile/'),
  });
  for (const root of [null, 'C:/other/mobile', 'C:/work/humus/mobile-extra']) {
    await assert.rejects(
      checkMetro({ ...options, fetchImpl: async () => metro(root) }),
      /project-root mismatch/,
    );
  }
  await assert.rejects(
    checkMetro({ ...options, fetchImpl: async () => metro(ROOT, 'PHP page') }),
    /Expected Metro status/,
  );
  await assert.rejects(
    checkMetro({
      ...options,
      fetchImpl: async () => new Response('unavailable', { status: 503 }),
    }),
    /HTTP 503/,
  );
});

test('backend requires unauthenticated 401 JSON error, not HTML, success or malformed JSON', async () => {
  await checkBackend({ fetchImpl: async () => backend() });
  await assert.rejects(
    checkBackend({ fetchImpl: async () => backend(200) }),
    /received 200/,
  );
  await assert.rejects(
    checkBackend({
      fetchImpl: async () =>
        new Response('<html>login</html>', {
          status: 401,
          headers: { 'content-type': 'text/html' },
        }),
    }),
    /Expected a JSON error/,
  );
  await assert.rejects(
    checkBackend({ fetchImpl: async () => backend(401, {}) }),
    /Expected \{error/,
  );
  await assert.rejects(
    checkBackend({
      fetchImpl: async () => backend(401, { error: { code: '', message: '' } }),
    }),
    /Expected \{error/,
  );
  await assert.rejects(
    checkBackend({
      fetchImpl: async () =>
        new Response('not json', {
          status: 401,
          headers: { 'content-type': 'application/json' },
        }),
    }),
    SyntaxError,
  );
});

test('429 is reported as reachable/rate-limited and preserves Retry-After guidance', async () => {
  await assert.rejects(
    checkBackend({
      fetchImpl: async () =>
        new Response('', { status: 429, headers: { 'Retry-After': '300' } }),
    }),
    /reachable.*429.*Retry-After: 300.*Do not reset credentials/,
  );
});

test('doctor makes only bounded credential-free GETs and skips adb by default', async () => {
  const urls = [];
  const messages = [];
  const code = await runDoctor({
    projectRoot: ROOT,
    platform: 'win32',
    spawn: () => assert.fail('ADB should be opt-in'),
    log: message => messages.push(message),
    fetchImpl: async (url, options) => {
      urls.push(url);
      assert.equal(options.method, 'GET');
      assert.equal(options.credentials, 'omit');
      assert.equal(options.redirect, 'error');
      assert.deepEqual(options.headers, { Accept: 'application/json' });
      assert.equal(options.signal.aborted, false);
      assert.equal(typeof options.signal.throwIfAborted, 'function');
      return url.endsWith('/status') ? metro() : backend();
    },
  });
  assert.equal(code, 0);
  assert.deepEqual(urls, [
    'http://127.0.0.1:8083/status',
    'http://127.0.0.1:8080/api/v1/me',
  ]);
  assert.equal(messages.length, 2);
  assert.ok(messages.every(message => message.startsWith('OK')));
});

test('doctor reports all checks and returns failure after unavailable/timed-out HTTP', async () => {
  const messages = [];
  let requests = 0;
  const code = await runDoctor({
    fetchImpl: async () => {
      requests += 1;
      throw new Error('request timed out');
    },
    log: message => messages.push(message),
  });
  assert.equal(code, 1);
  assert.equal(requests, 2);
  assert.equal(messages.length, 2);
  assert.ok(
    messages.every(
      message => message.startsWith('FAIL') && message.includes('timed out'),
    ),
  );
});

test('reverse diagnostic reads mappings without installing or removing any', () => {
  const { calls, spawn } = adbMock(
    DEVICE,
    'UsbFfs tcp:8080 tcp:8080\r\nUsbFfs tcp:8081 tcp:8081\r\nUsbFfs tcp:8083 tcp:8083\r\n',
  );
  assert.match(checkReverse({ spawn }), /ports 8080, 8081, 8083/);
  assert.deepEqual(
    calls.map(call => call.args),
    [
      ['devices', '-l'],
      ['-s', 'usb-test', 'reverse', '--list'],
    ],
  );
  const wrongPort = adbMock(
    DEVICE,
    'UsbFfs tcp:8080 tcp:8080\nUsbFfs tcp:8081 tcp:8081\nUsbFfs tcp:8083 tcp:8081\n',
  );
  assert.throws(
    () => checkReverse({ spawn: wrongPort.spawn }),
    /Missing.*8083.*android-usb.js/,
  );
});

test('the old API/Metro-only mappings are reported as missing the WebSocket port', () => {
  const { spawn } = adbMock(DEVICE, 'UsbFfs tcp:8080 tcp:8080\nUsbFfs tcp:8083 tcp:8083\n');
  assert.throws(() => checkReverse({ spawn }), /Missing.*8081.*android-usb.js/);
});

test('optional ADB failures affect doctor exit status without hiding HTTP results', async () => {
  const messages = [];
  const { spawn } = adbMock('List of devices attached\n');
  const code = await runDoctor({
    adb: true,
    projectRoot: ROOT,
    platform: 'win32',
    spawn,
    fetchImpl: async url => (url.endsWith('/status') ? metro() : backend()),
    log: message => messages.push(message),
  });
  assert.equal(code, 1);
  assert.equal(messages.filter(message => message.startsWith('OK')).length, 2);
  assert.match(messages[2], /FAIL ADB reverse/);
});

test('unknown diagnostic flags fail before doing any checks', async () => {
  const original = console.error;
  const messages = [];
  console.error = message => messages.push(message);
  try {
    assert.equal(await main(['--login']), 1);
    assert.match(messages[0], /Usage:/);
  } finally {
    console.error = original;
  }
});
