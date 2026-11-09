/* eslint-env node, es2022 */
'use strict';

// Unit fixtures only: exercise provisioned native tools without Gradle, signing,
// Firebase input, dependencies, a device or any network. Generated APK is unsigned
// and contains test resources only; it is never a Synapse application artifact.
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } = require('node:fs');
const { homedir, tmpdir } = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { buildConfigStrings, parseResourceTable, parseXmlTree, verifyCompiledManifest, verifyNetworkConfig,
  verifyBundle, verifyBundleBytecode } = require('./check-android-apk.cjs');

const sdk = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT
  || path.join(process.env.LOCALAPPDATA || path.join(homedir(), 'AppData', 'Local'), 'Android', 'Sdk');
const suffix = process.platform === 'win32' ? '.exe' : '';
const tools = path.join(sdk, 'build-tools', '37.0.0');
const aapt2 = path.join(tools, 'aapt2' + suffix);
const d8 = path.join(tools, 'lib', 'd8.jar');
const android = ['android-37.0', 'android-37', 'android-36'].map(version => path.join(sdk, 'platforms', version, 'android.jar')).find(existsSync);
const hermesc = path.resolve(__dirname, '..', 'node_modules', 'hermes-compiler', 'hermesc',
  process.platform === 'win32' ? 'win64-bin' : process.platform === 'darwin' ? 'osx-bin' : 'linux64-bin', 'hermesc' + suffix);
const available = existsSync(aapt2) && existsSync(d8) && !!android && existsSync(hermesc);
const app = path.resolve(__dirname, '..', 'android', 'app');

function run(command, args, cwd) {
  const result = spawnSync(command, args, { cwd, shell: false, windowsHide: true, encoding: 'utf8', timeout: 60000, maxBuffer: 64 * 1024 * 1024 });
  assert.equal(result.error, undefined, result.error?.message);
  assert.equal(result.status, 0, `${path.basename(command)} fixture failed\n${result.stdout}\n${result.stderr}`);
  return result.stdout;
}
function jdk(tool) {
  return process.env.JAVA_HOME ? path.join(process.env.JAVA_HOME, 'bin', tool + suffix) : tool;
}
function fixtureFile(root, filename, content) {
  const target = path.join(root, filename);
  mkdirSync(path.dirname(target), { recursive: true }); writeFileSync(target, content);
  return target;
}

test('provisioned native tools parse actual compiled XML/resource tables and DEX static strings from unit fixtures',
  { skip: !available && 'Provisioned aapt2/d8/android.jar/Hermes absent; no dependency or SDK installation.' }, () => {
    const root = mkdtempSync(path.join(tmpdir(), 'synapse-native-binary-unit-'));
    try {
      for (const profile of ['release', 'lanTestRelease']) {
        const lan = profile === 'lanTestRelease';
        const base = path.join(root, profile);
        const resources = path.join(base, 'res');
        const policy = readFileSync(path.join(app, 'src', profile, 'res', 'xml', 'network_security_config.xml'), 'utf8');
        fixtureFile(base, 'res/xml/network_security_config.xml', policy);
        fixtureFile(base, 'res/values/strings.xml', `<resources>
          <string name="app_name">${lan ? 'Synapse LAN Test' : 'Synapse'}</string>
          <string name="build_config_package">com.humus.synapse</string>
          <string name="SYNAPSE_BUILD_PROFILE">${lan ? 'lan-test' : 'production'}</string>
          <string name="SYNAPSE_ALLOW_LAN_HTTP">${lan ? 'true' : 'false'}</string>
          <string name="google_app_id">unit-fixture-not-firebase-app</string>
          <string name="google_api_key">unit-fixture-not-firebase-key</string>
          <color name="unrelated">#001122</color>
        </resources>`);
        const manifest = fixtureFile(base, 'AndroidManifest.xml', `<manifest xmlns:android="http://schemas.android.com/apk/res/android"
            package="com.humus.synapse${lan ? '.lantest' : ''}" android:versionName="1.0.0${lan ? '-lan-test' : ''}" android:versionCode="1">
          <uses-sdk android:minSdkVersion="24" android:targetSdkVersion="36" />
          <application android:name="com.humus.synapse.MainApplication" android:label="@string/app_name"
              android:debuggable="false" android:usesCleartextTraffic="false" android:networkSecurityConfig="@xml/network_security_config">
            <service android:name="io.invertase.firebase.messaging.ReactNativeFirebaseMessagingService" android:exported="false" />
            <provider android:name="com.google.firebase.provider.FirebaseInitProvider" android:exported="false" android:authorities="unit.fixture.${profile}" />
          </application>
        </manifest>`);
        const compiled = path.join(base, 'compiled.zip');
        run(aapt2, ['compile', '--dir', resources, '-o', compiled], root);
        const fixture = path.join(base, 'unsigned-unit-fixture.apk');
        run(aapt2, ['link', '-o', fixture, '--manifest', manifest, '-I', android, compiled], root);
        const table = parseResourceTable(run(aapt2, ['dump', 'resources', fixture], root));
        const tree = parseXmlTree(run(aapt2, ['dump', 'xmltree', fixture, '--file', 'AndroidManifest.xml'], root));
        const checked = verifyCompiledManifest(tree, table, profile);
        const networkText = run(aapt2, ['dump', 'xmltree', fixture, '--file', checked.networkXml], root);
        let network;
        try { network = verifyNetworkConfig(parseXmlTree(networkText), profile); }
        catch (error) { assert.fail(`${error.message}\nSynthetic fixture XML dump\n${networkText}`); }
        assert.deepEqual(network, { cleartextHosts: lan ? ['172.16.153.237', '192.168.1.11'] : [] });
        assert.equal(table.firebaseAppIdPresent, true); assert.equal(table.firebaseApiKeyPresent, true);
        assert.doesNotMatch(JSON.stringify([...table.resources]), /unit-fixture-not-firebase/);
      }

      const source = fixtureFile(root, 'java/com/humus/synapse/BuildConfig.java', `package com.humus.synapse;
        public final class BuildConfig {
          public static final boolean DEBUG = false;
          public static final String APPLICATION_ID = "com.humus.synapse.lantest";
          public static final String BUILD_TYPE = "lanTestRelease";
          public static final int VERSION_CODE = 1;
          public static final String VERSION_NAME = "1.0.0-lan-test";
          public static final String SYNAPSE_BUILD_PROFILE = "lan-test";
          public static final String SYNAPSE_ALLOW_LAN_HTTP = "true";
        }`);
      const classes = path.join(root, 'classes'); mkdirSync(classes);
      run(jdk('javac'), ['-d', classes, source], root);
      const dexRoot = path.join(root, 'dex'); mkdirSync(dexRoot);
      run(jdk('java'), ['-cp', d8, 'com.android.tools.r8.D8', '--output', dexRoot,
        path.join(classes, 'com/humus/synapse/BuildConfig.class')], root);
      const fields = buildConfigStrings(readFileSync(path.join(dexRoot, 'classes.dex')));
      assert.equal(fields.BUILD_TYPE, 'lanTestRelease'); assert.equal(fields.APPLICATION_ID, 'com.humus.synapse.lantest');
      assert.equal(fields.SYNAPSE_BUILD_PROFILE, 'lan-test'); assert.equal(fields.SYNAPSE_ALLOW_LAN_HTTP, 'true');

      const bundleSource = fixtureFile(root, 'bundle.js', `var __BUNDLE_START_TIME__ = 0;
        function __d(fn) { fn(); }
        __d(function() { print("SYNAPSE_BUILD_PROFILE", "SYNAPSE_ALLOW_LAN_HTTP", "http://172.16.153.237:8080", "http://192.168.1.11:8080"); });
        ${' '.repeat(512)}`);
      const bytecode = path.join(root, 'fixture.hbc');
      run(hermesc, ['-O', '-emit-binary', '-out', bytecode, bundleSource], root);
      const compiledBundle = readFileSync(bytecode);
      assert.equal(verifyBundle(compiledBundle, 'lanTestRelease').format, 'hermes');
      verifyBundleBytecode(compiledBundle, hermesc);
      verifyBundleBytecode(readFileSync(bundleSource), hermesc);
      assert.throws(() => verifyBundleBytecode(compiledBundle.subarray(0, 16), hermesc), /inspection tool failed/);
    } finally { rmSync(root, { recursive: true, force: true }); }
  });
