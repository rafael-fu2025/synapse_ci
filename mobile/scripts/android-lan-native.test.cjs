/* eslint-env node, es2022 */
'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } = require('node:fs');
const { homedir, tmpdir } = require('node:os');
const path = require('node:path');
const test = require('node:test');

const mobile = path.resolve(__dirname, '..');
const app = path.join(mobile, 'android', 'app');
const native = path.join(app, 'src', 'main', 'java', 'com', 'humus', 'synapse');

function firstJar(root, name) {
  if (!existsSync(root)) { return null; }
  for (const entry of readdirSync(root, { withFileTypes: true })) {
    const filename = path.join(root, entry.name);
    if (entry.isDirectory()) { const found = firstJar(filename, name); if (found) { return found; } }
    else if (entry.name === name) { return filename; }
  }
  return null;
}

function cachedClasspath() {
  if (process.env.SYNAPSE_NATIVE_TEST_CLASSPATH) { return process.env.SYNAPSE_NATIVE_TEST_CLASSPATH; }
  const cache = path.join(process.env.GRADLE_USER_HOME || path.join(homedir(), '.gradle'), 'caches');
  const artifacts = path.join(cache, 'modules-2', 'files-2.1');
  const okhttp = firstJar(path.join(artifacts, 'com.squareup.okhttp3', 'okhttp', '4.9.2'), 'okhttp-4.9.2.jar');
  const okio = firstJar(path.join(artifacts, 'com.squareup.okio', 'okio', '2.9.0'), 'okio-jvm-2.9.0.jar');
  const kotlin = firstJar(path.join(artifacts, 'org.jetbrains.kotlin', 'kotlin-stdlib', '2.2.0'), 'kotlin-stdlib-2.2.0.jar')
    || firstJar(path.join(artifacts, 'org.jetbrains.kotlin', 'kotlin-stdlib', '2.2.10'), 'kotlin-stdlib-2.2.10.jar');
  let react = null;
  for (const version of existsSync(cache) ? readdirSync(cache) : []) {
    if (/^\d+\./.test(version)) {
      react = firstJar(path.join(cache, version, 'transforms'), 'react-android-0.88.0-debug-runtime.jar') || react;
    }
  }
  const sdk = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT
    || path.join(process.env.LOCALAPPDATA || path.join(homedir(), 'AppData', 'Local'), 'Android', 'Sdk');
  const android = ['android-37.0', 'android-37', 'android-36']
    .map(version => path.join(sdk, 'platforms', version, 'android.jar')).find(existsSync);
  return [okhttp, okio, kotlin, react, android].every(Boolean)
    ? [okhttp, okio, kotlin, react, android].join(path.delimiter) : null;
}

function run(tool, args) {
  const command = process.env.JAVA_HOME
    ? path.join(process.env.JAVA_HOME, 'bin', tool + (process.platform === 'win32' ? '.exe' : '')) : tool;
  const result = spawnSync(command, args, { encoding: 'utf8', shell: false, windowsHide: true, timeout: 60000 });
  assert.equal(result.error, undefined, result.error?.message);
  assert.equal(result.status, 0, `${tool} failed\n${result.stdout}\n${result.stderr}`);
  return result.stdout;
}

const classpath = cachedClasspath();
test('real installed RN/OkHttp factory and follow-up loop reject LAN credential redirects without network',
  { skip: !classpath && 'Cached RN/OkHttp classpath absent; set SYNAPSE_NATIVE_TEST_CLASSPATH (no dependency installation).' }, () => {
    const output = mkdtempSync(path.join(tmpdir(), 'synapse-lan-native-'));
    try {
      run('javac', ['-cp', classpath, '-d', output, path.join(native, 'LanApiRedirectInterceptor.java'),
        path.join(native, 'LanTestOkHttpClientFactory.java'), path.join(app, 'src', 'test', 'java', 'com', 'humus', 'synapse', 'LanApiRedirectInterceptorTest.java')]);
      assert.match(run('java', ['-cp', output + path.delimiter + classpath, 'com.humus.synapse.LanApiRedirectInterceptorTest']),
        /\d+ LAN native transport checks passed \(in-memory; no network\)/);
    } finally { rmSync(output, { recursive: true, force: true }); }
  });

test('registration is native LAN-only and precedes React initialization', () => {
  const application = readFileSync(path.join(native, 'MainApplication.kt'), 'utf8');
  assert.match(application, /if \(packageName == "com\.humus\.synapse\.lantest" && BuildConfig\.BUILD_TYPE == "lanTestRelease"\)\s*\{\s*OkHttpClientProvider\.setOkHttpClientFactory\(LanTestOkHttpClientFactory\(\)\)/);
  assert.ok(application.indexOf('setOkHttpClientFactory') < application.indexOf('loadReactNative(this)'));
  const factory = readFileSync(path.join(native, 'LanTestOkHttpClientFactory.java'), 'utf8');
  assert.match(factory, /OkHttpClientProvider\.createClientBuilder\(\)/);
  assert.match(factory, /\.cache\(null\)/);
  assert.match(factory, /\.addNetworkInterceptor\(new LanApiRedirectInterceptor\(\)\)/);
  assert.doesNotMatch(factory, /followRedirects\(false\)|followSslRedirects\(false\)|sslSocketFactory\(|hostnameVerifier\(/);
  const interceptor = readFileSync(path.join(native, 'LanApiRedirectInterceptor.java'), 'utf8');
  assert.match(interceptor, /response\.close\(\);\s*throw new ProtocolException/);
  assert.doesNotMatch(interceptor, /Log\.|System\.out|\.header\("Location"\)|\.header\("Authorization"\)/);
});
