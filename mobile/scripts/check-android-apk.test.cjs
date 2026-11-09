/* eslint-env node, es2022 */
'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { deflateRawSync } = require('node:zlib');
const { REQUIRED_CLASSES, LAN_ORIGINS, buildConfigStrings, classDefinitions, verifyApk, parseXmlTree,
  parseResourceTable, verifyCompiledManifest, verifyNetworkConfig, verifyBundle, verifyBundleBytecode, verifySignatureOutput, inspectReleaseApk } = require('./check-android-apk.cjs');
const { mkdtempSync, writeFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');

function dexFixture(definitions, references = []) {
  const names = [...definitions, ...references];
  const stringOffset = 112;
  const typeOffset = stringOffset + names.length * 4;
  const classOffset = typeOffset + names.length * 4;
  const dataOffset = classOffset + definitions.length * 32;
  const strings = names.map(name => Buffer.concat([Buffer.from([name.length]), Buffer.from(name), Buffer.from([0])]));
  const dex = Buffer.alloc(dataOffset + strings.reduce((total, string) => total + string.length, 0));
  dex.write('dex\n035\0', 0, 'ascii');
  dex.writeUInt32LE(dex.length, 32);
  dex.writeUInt32LE(112, 36);
  dex.writeUInt32LE(0x12345678, 40);
  dex.writeUInt32LE(names.length, 56);
  dex.writeUInt32LE(stringOffset, 60);
  dex.writeUInt32LE(names.length, 64);
  dex.writeUInt32LE(typeOffset, 68);
  dex.writeUInt32LE(definitions.length, 96);
  dex.writeUInt32LE(classOffset, 100);
  let offset = dataOffset;
  strings.forEach((string, index) => {
    dex.writeUInt32LE(offset, stringOffset + index * 4);
    dex.writeUInt32LE(index, typeOffset + index * 4);
    string.copy(dex, offset);
    offset += string.length;
  });
  definitions.forEach((_, index) => dex.writeUInt32LE(index, classOffset + index * 32));
  return dex;
}

function apkFixture(entries, deflated = false) {
  const locals = [];
  const directory = [];
  let offset = 0;
  for (const [name, bytes] of entries) {
    const filename = Buffer.from(name);
    const payload = deflated ? deflateRawSync(bytes) : bytes;
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(deflated ? 8 : 0, 8);
    local.writeUInt32LE(payload.length, 18);
    local.writeUInt32LE(bytes.length, 22);
    local.writeUInt16LE(filename.length, 26);
    locals.push(local, filename, payload);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(deflated ? 8 : 0, 10);
    central.writeUInt32LE(payload.length, 20);
    central.writeUInt32LE(bytes.length, 24);
    central.writeUInt16LE(filename.length, 28);
    central.writeUInt32LE(offset, 42);
    directory.push(central, filename);
    offset += local.length + filename.length + payload.length;
  }
  const central = Buffer.concat(directory);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(central.length, 12);
  end.writeUInt32LE(offset, 16);
  return Buffer.concat([...locals, central, end]);
}

test('rejects the startup failure when selector appears only as a referenced type', () => {
  const dex = dexFixture(REQUIRED_CLASSES.slice(0, 3), REQUIRED_CLASSES.slice(3));
  assert.equal(classDefinitions(dex).size, 3);
  assert.throws(() => verifyApk(apkFixture([['classes.dex', dex]])), /missing native class definitions/);
});

test('rejects a missing nested Mode even when the selector class exists', () => {
  const dex = dexFixture(REQUIRED_CLASSES.slice(0, 4), REQUIRED_CLASSES.slice(4));
  assert.throws(() => verifyApk(apkFixture([['classes.dex', dex]])), /SupportedRefreshRatePolicy\$Mode/);
});

test('requires definitions across all root multidex entries, including classes10', () => {
  const apk = apkFixture([
    ['classes.dex', dexFixture(REQUIRED_CLASSES.slice(0, 3))],
    ['classes2.dex', dexFixture(REQUIRED_CLASSES.slice(3, 4))],
    ['classes10.dex', dexFixture(REQUIRED_CLASSES.slice(4))],
  ]);
  assert.deepEqual(verifyApk(apk), { dexCount: 3, classes: 5 });
});

test('accepts deflated DEX entries and ignores non-DEX assets', () => {
  const apk = apkFixture([
    ['assets/notes.txt', Buffer.from('not executable')],
    ['classes.dex', dexFixture(REQUIRED_CLASSES)],
  ], true);
  assert.deepEqual(verifyApk(apk), { dexCount: 1, classes: 5 });
});

test('does not accept a DEX hidden in an asset directory', () => {
  const apk = apkFixture([
    ['classes.dex', dexFixture([])],
    ['assets/classes2.dex', dexFixture(REQUIRED_CLASSES)],
  ]);
  assert.throws(() => verifyApk(apk), /missing native class definitions/);
});

test('fails closed for truncated ZIP and out-of-bounds DEX tables', () => {
  assert.throws(() => verifyApk(Buffer.from('invalid')), /ZIP directory/);
  const dex = dexFixture(REQUIRED_CLASSES);
  dex.writeUInt32LE(dex.length + 1, 100);
  assert.throws(() => verifyApk(apkFixture([['classes.dex', dex]])), /data range/);
});

test('rejects corrupted class type indices instead of searching descriptor strings', () => {
  const dex = dexFixture(REQUIRED_CLASSES);
  dex.writeUInt32LE(REQUIRED_CLASSES.length, dex.readUInt32LE(100));
  assert.throws(() => verifyApk(apkFixture([['classes.dex', dex]])), /Invalid DEX class type/);
});

const PUBLIC_CERT = 'a1'.repeat(32);
const LAN_CONFIG = {
  APPLICATION_ID: 'com.humus.synapse.lantest', BUILD_TYPE: 'lanTestRelease',
  SYNAPSE_ALLOW_LAN_HTTP: 'true', SYNAPSE_BUILD_PROFILE: 'lan-test',
};

function ulebFixture(value) {
  const bytes = [];
  do { const byte = value & 0x7f; value = Math.floor(value / 128); bytes.push(byte | (value ? 0x80 : 0)); } while (value);
  return Buffer.from(bytes);
}

function buildConfigDexFixture(values = LAN_CONFIG) {
  const names = Object.keys(values).sort();
  const strings = ['Lcom/humus/synapse/BuildConfig;', 'Ljava/lang/String;', ...names, ...names.map(name => values[name])];
  const stringOffset = 112;
  const typeOffset = stringOffset + strings.length * 4;
  const fieldOffset = typeOffset + 8;
  const classOffset = fieldOffset + names.length * 8;
  const stringsOffset = classOffset + 32;
  const payloads = strings.map(value => Buffer.concat([ulebFixture(value.length), Buffer.from(value), Buffer.from([0])]));
  const dataOffset = stringsOffset + payloads.reduce((total, value) => total + value.length, 0);
  const classData = Buffer.concat([ulebFixture(names.length), Buffer.from([0, 0, 0]),
    ...names.flatMap((_, index) => [ulebFixture(index ? 1 : 0), ulebFixture(0x19)])]);
  const arrayOffset = dataOffset + classData.length;
  const array = Buffer.concat([ulebFixture(names.length), ...names.map((_, index) => Buffer.from([0x17, 2 + names.length + index]))]);
  const dex = Buffer.alloc(arrayOffset + array.length);
  dex.write('dex\n035\0', 0, 'ascii');
  dex.writeUInt32LE(dex.length, 32); dex.writeUInt32LE(112, 36); dex.writeUInt32LE(0x12345678, 40);
  dex.writeUInt32LE(strings.length, 56); dex.writeUInt32LE(stringOffset, 60);
  dex.writeUInt32LE(2, 64); dex.writeUInt32LE(typeOffset, 68);
  dex.writeUInt32LE(names.length, 80); dex.writeUInt32LE(fieldOffset, 84);
  dex.writeUInt32LE(1, 96); dex.writeUInt32LE(classOffset, 100);
  dex.writeUInt32LE(0, typeOffset); dex.writeUInt32LE(1, typeOffset + 4);
  names.forEach((_, index) => {
    dex.writeUInt16LE(0, fieldOffset + index * 8); dex.writeUInt16LE(1, fieldOffset + index * 8 + 2);
    dex.writeUInt32LE(2 + index, fieldOffset + index * 8 + 4);
  });
  dex.writeUInt32LE(0, classOffset);
  dex.writeUInt32LE(dataOffset, classOffset + 24); dex.writeUInt32LE(arrayOffset, classOffset + 28);
  let cursor = stringsOffset;
  payloads.forEach((payload, index) => { dex.writeUInt32LE(cursor, stringOffset + index * 4); payload.copy(dex, cursor); cursor += payload.length; });
  classData.copy(dex, dataOffset); array.copy(dex, arrayOffset);
  return dex;
}

function manifestDump(profile = 'lanTestRelease') {
  const lan = profile === 'lanTestRelease';
  return `N: android=http://schemas.android.com/apk/res/android (line=2)
  E: manifest (line=2)
    A: package="com.humus.synapse${lan ? '.lantest' : ''}" (Raw: "package")
    A: http://schemas.android.com/apk/res/android:versionName(0x0101021c)="1.0.0${lan ? '-lan-test' : ''}"
      E: application (line=6)
        A: http://schemas.android.com/apk/res/android:name(0x01010003)="com.humus.synapse.MainApplication"
        A: http://schemas.android.com/apk/res/android:debuggable(0x0101000f)=false
        A: http://schemas.android.com/apk/res/android:usesCleartextTraffic(0x010104ec)=false
        A: http://schemas.android.com/apk/res/android:label(0x01010001)=@0x7f110001
        A: http://schemas.android.com/apk/res/android:networkSecurityConfig(0x01010527)=@0x7f140001
          E: service (line=12)
            A: http://schemas.android.com/apk/res/android:name(0x01010003)="io.invertase.firebase.messaging.ReactNativeFirebaseMessagingService"
          E: provider (line=15)
            A: http://schemas.android.com/apk/res/android:name(0x01010003)="com.google.firebase.provider.FirebaseInitProvider"`;
}

function resourceDump(profile = 'lanTestRelease') {
  const lan = profile === 'lanTestRelease';
  return `Package name=com.humus.synapse id=7f
    resource 0x7f110001 string/app_name
      () "${lan ? 'Synapse LAN Test' : 'Synapse'}"
    resource 0x7f110002 string/build_config_package
      () "com.humus.synapse"
    resource 0x7f110003 string/SYNAPSE_BUILD_PROFILE
      () "${lan ? 'lan-test' : 'production'}"
    resource 0x7f110004 string/SYNAPSE_ALLOW_LAN_HTTP
      () "${lan ? 'true' : 'false'}"
    resource 0x7f140001 xml/network_security_config
      () (file) res/xml/network_security_config.xml type=XML
    resource 0x7f110005 string/google_app_id
      () "private-fixture-app-id"
    resource 0x7f110006 string/google_api_key
      () "private-fixture-api-key"`;
}

function networkDump(profile = 'lanTestRelease') {
  return `E: network-security-config (line=2)
  E: base-config (line=3)
    A: cleartextTrafficPermitted=false
    E: trust-anchors (line=4)
      E: certificates (line=5)
        A: src="system"${profile === 'lanTestRelease' ? `
  E: domain-config (line=8)
    A: cleartextTrafficPermitted=true
    E: domain (line=9)
      A: includeSubdomains=false
      T: "172.16.153.237"
    E: domain (line=10)
      A: includeSubdomains=false
      T: "192.168.1.11"` : ''}`;
}

function bundleFixture() {
  return Buffer.from(`var __BUNDLE_START_TIME__=0;__d(function(){return ["SYNAPSE_BUILD_PROFILE","SYNAPSE_ALLOW_LAN_HTTP",${LAN_ORIGINS.map(JSON.stringify).join(',')}];});${' '.repeat(512)}`);
}
function signerDump(digest = PUBLIC_CERT, subject = 'CN=Synapse Upload,O=Synapse') {
  return `Verifies\nVerified using v2 scheme (APK Signature Scheme v2): true\nSigner #1 certificate DN: ${subject}\nSigner #1 certificate SHA-256 digest: ${digest}\n`;
}
function schemeSignerDump(digest = PUBLIC_CERT, subject = 'CN=Synapse Upload,O=Synapse') {
  return `Verifies\nVerified using v2 scheme (APK Signature Scheme v2): true\nNumber of signers: 1\nV2 Signer: certificate DN: ${subject}\nV2 Signer: certificate SHA-256 digest: ${digest}\nV2 Signer: public key SHA-256 digest: ${'b2'.repeat(32)}\n`;
}

test('native marker gate decodes BuildConfig static String values, not unrelated strings', () => {
  const dex = buildConfigDexFixture();
  assert.deepEqual(buildConfigStrings(dex), LAN_CONFIG);
  assert.equal(buildConfigStrings(dexFixture(REQUIRED_CLASSES, Object.values(LAN_CONFIG))), null);
  dex.writeUInt32LE(dex.length + 1, dex.readUInt32LE(100) + 28);
  assert.throws(() => buildConfigStrings(dex), /data range/);
});

test('compiled XML preserves unquoted text and rejects empty attribute values', () => {
  const tree = parseXmlTree('E: fixture (line=1)\n  A: value=unquoted-text');
  assert.equal(tree.attributes.value, 'unquoted-text');
  assert.throws(() => parseXmlTree('E: fixture (line=1)\n  A: value=   '), /Missing compiled XML text/);
});

test('compiled manifest gate checks suffix, nondebuggable, exact native strings and Firebase configuration', () => {
  const table = parseResourceTable(resourceDump());
  assert.deepEqual(verifyCompiledManifest(parseXmlTree(manifestDump()), table, 'lanTestRelease'), {
    networkXml: 'res/xml/network_security_config.xml', packageName: 'com.humus.synapse.lantest',
  });
  assert.throws(() => verifyCompiledManifest(parseXmlTree(manifestDump().replace('debuggable(0x0101000f)=false', 'debuggable(0x0101000f)=true')), table, 'lanTestRelease'), /debuggable/);
  assert.throws(() => verifyCompiledManifest(parseXmlTree(manifestDump().replace('.lantest', '')), table, 'lanTestRelease'), /package/);
  assert.throws(() => verifyCompiledManifest(parseXmlTree(manifestDump().replace('-lan-test', '')), table, 'lanTestRelease'), /suffix/);
  assert.throws(() => verifyCompiledManifest(parseXmlTree(manifestDump()), parseResourceTable(resourceDump().replace('() "true"', '() "false"')), 'lanTestRelease'), /native profile/);
  assert.throws(() => verifyCompiledManifest(parseXmlTree(manifestDump()), parseResourceTable(resourceDump().replace('() "private-fixture-api-key"', '() ""')), 'lanTestRelease'), /Firebase/);
  assert.doesNotMatch(JSON.stringify([...table.resources]), /private-fixture/);
});

test('compiled network XML permits only exact LAN hosts with system HTTPS trust and default deny', () => {
  assert.deepEqual(verifyNetworkConfig(parseXmlTree(networkDump()), 'lanTestRelease'), { cleartextHosts: ['172.16.153.237', '192.168.1.11'] });
  for (const malformed of [networkDump().replace('172.16.153.237', '172.16.153.237.example.invalid'),
    networkDump().replace('includeSubdomains=false', 'includeSubdomains=true'),
    networkDump().replace('src="system"', 'src="user"'),
    networkDump().replace('cleartextTrafficPermitted=false', 'cleartextTrafficPermitted=true'),
    networkDump() + '\n  E: debug-overrides (line=15)']) {
    assert.throws(() => verifyNetworkConfig(parseXmlTree(malformed), 'lanTestRelease'), /network|Network|cleartext|LAN|trust|system/);
  }
  assert.deepEqual(verifyNetworkConfig(parseXmlTree(networkDump('release')), 'release'), { cleartextHosts: [] });
  assert.throws(() => verifyNetworkConfig(parseXmlTree(networkDump()), 'release'), /Production/);
});

test('bundled code gate is existence/format only and never authorizes LAN without native fields', () => {
  assert.equal(verifyBundle(bundleFixture(), 'lanTestRelease').format, 'metro-js');
  assert.throws(() => verifyBundle(Buffer.from(''), 'lanTestRelease'), /real bundled/);
  assert.throws(() => verifyBundle(Buffer.from('not-a-bundle'.repeat(256)), 'lanTestRelease'), /recognized/);
  assert.throws(() => verifyBundle(Buffer.from(bundleFixture().toString().replace(LAN_ORIGINS[0], 'http://127.0.0.1:8080')), 'lanTestRelease'), /fixed LAN API/);
  const hermes = Buffer.concat([Buffer.alloc(16), bundleFixture()]);
  hermes.writeBigUInt64LE(0x1f1903c103bc1fc6n, 0);
  assert.equal(verifyBundle(hermes, 'lanTestRelease').format, 'hermes');
});

test('bundled asset bytecode validator never evaluates JS and fails on invalid/truncated data', () => {
  const calls = [];
  const spawn = (command, args) => { calls.push({ command, args }); return { status: 0, stdout: '' }; };
  verifyBundleBytecode(bundleFixture(), 'hermesc-mock', spawn);
  assert.ok(calls[0].args.includes('-emit-binary'));
  const bytecode = Buffer.concat([Buffer.alloc(16), bundleFixture()]);
  bytecode.writeBigUInt64LE(0x1f1903c103bc1fc6n);
  verifyBundleBytecode(bytecode, 'hermesc-mock', spawn);
  assert.ok(calls[1].args.includes('-dump-bytecode'));
  assert.throws(() => verifyBundleBytecode(bytecode, 'hermesc-mock', () => ({ status: 1, stdout: '', stderr: 'do not show asset' })), /inspection tool failed/);
});

test('release signature gate requires expected public SHA-256 and rejects debug even if expected', () => {
  assert.deepEqual(verifySignatureOutput(signerDump(), PUBLIC_CERT), { sha256: PUBLIC_CERT });
  assert.throws(() => verifySignatureOutput(signerDump(), undefined), /expected public/);
  assert.throws(() => verifySignatureOutput(signerDump().replace('v2): true', 'v2): false'), PUBLIC_CERT), /modern APK signing/);
  assert.throws(() => verifySignatureOutput(signerDump(), 'b2'.repeat(32)), /does not match/);
  assert.throws(() => verifySignatureOutput(signerDump(PUBLIC_CERT, 'CN=Android Debug,O=Android,C=US'), PUBLIC_CERT), /Debug signer/);
  assert.throws(() => verifySignatureOutput(signerDump(), PUBLIC_CERT, [PUBLIC_CERT]), /Debug signer/);
  assert.throws(() => verifySignatureOutput(signerDump() + signerDump().replace(/Signer #1/g, 'Signer #2'), PUBLIC_CERT), /exactly one/);
});

test('SDK 37 scheme-qualified signer output verifies the certificate, not its public key digest', () => {
  const dump = schemeSignerDump();
  assert.deepEqual(verifySignatureOutput(dump, PUBLIC_CERT), { sha256: PUBLIC_CERT });
  assert.deepEqual(verifySignatureOutput(dump.replace(/\n/g, '\r\n'), PUBLIC_CERT), { sha256: PUBLIC_CERT });
  for (const label of ['V3.0 Signer:', 'V3.1 Signer:', 'V2 Signer #1:']) {
    assert.deepEqual(verifySignatureOutput(dump.replace(/V2 Signer:/g, label), PUBLIC_CERT), { sha256: PUBLIC_CERT });
  }
  assert.throws(() => verifySignatureOutput(dump, 'b2'.repeat(32)), /does not match/);
  assert.throws(() => verifySignatureOutput(schemeSignerDump(PUBLIC_CERT, 'CN=Android Debug,O=Android,C=US'), PUBLIC_CERT), /Debug signer/);
  assert.throws(() => verifySignatureOutput(dump, PUBLIC_CERT, [PUBLIC_CERT]), /Debug signer/);
  assert.throws(() => verifySignatureOutput(dump.replace('v2): true', 'v2): false'), PUBLIC_CERT), /modern APK signing/);
});

test('scheme-qualified signature output rejects missing, malformed, multiple or ambiguous signer certificates', () => {
  const dump = schemeSignerDump();
  for (const invalid of [dump.replace('Number of signers: 1', 'Number of signers: 2'),
    dump.replace('Number of signers: 1\n', ''),
    dump + 'Number of signers: 1\n',
    dump + `V2 Signer #2: certificate SHA-256 digest: ${PUBLIC_CERT}\n`,
    dump.replace(`V2 Signer: certificate SHA-256 digest: ${PUBLIC_CERT}\n`, ''),
    dump.replace(`V2 Signer: certificate SHA-256 digest: ${PUBLIC_CERT}`, 'V2 Signer: certificate SHA-256 digest: abc'),
    dump.replace(/V2 Signer:/g, 'Source Stamp Signer:')]) {
    assert.throws(() => verifySignatureOutput(invalid, PUBLIC_CERT), /exactly one/);
  }
  assert.throws(() => verifySignatureOutput(dump.replace(/^V2 Signer: certificate DN:.*\n/m, ''), PUBLIC_CERT), /subject/);
  assert.throws(() => verifySignatureOutput(dump.replace('V2 Signer: certificate DN:', 'V3.0 Signer: certificate DN:'), PUBLIC_CERT), /subject/);
  assert.throws(() => verifySignatureOutput(dump + 'V2 Signer: certificate DN: CN=Other\n', PUBLIC_CERT), /subject/);
});

test('reusable release inspector validates DEX, actual SDK dumps, bundle and cryptographic verifier invocation', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'synapse-apk-gate-'));
  const apk = path.join(root, 'app.apk');
  const signer = path.join(root, 'apksigner.jar');
  const hermesc = path.join(root, 'hermesc-gate');
  const calls = [];
  let badSignature = false;
  const definitions = [...REQUIRED_CLASSES, 'Lcom/humus/synapse/LanTestOkHttpClientFactory;', 'Lcom/humus/synapse/LanApiRedirectInterceptor;'];
  const payload = apkFixture([['classes.dex', dexFixture(definitions)], ['classes2.dex', buildConfigDexFixture()],
    ['assets/index.android.bundle', bundleFixture()]]);
  const spawn = (command, args, options) => {
    calls.push({ command, args, options });
    let stdout;
    if (command === 'java-gate') { stdout = signerDump(); }
    else if (command === hermesc) { assert.ok(args.includes('-emit-binary')); stdout = ''; }
    else if (args.includes('resources')) { stdout = resourceDump(); }
    else if (args.includes('AndroidManifest.xml')) { stdout = manifestDump(); }
    else if (args.includes('res/xml/network_security_config.xml')) { stdout = networkDump(); }
    else { assert.fail('Unexpected tool invocation'); }
    return { status: badSignature && command === 'java-gate' ? 1 : 0, stdout, stderr: 'must never print configuration' };
  };
  try {
    writeFileSync(apk, payload); writeFileSync(signer, 'not executed by mock'); writeFileSync(hermesc, 'not executed by mock');
    const options = { profile: 'lanTestRelease', expectedFingerprint: PUBLIC_CERT, aapt2: 'aapt2-gate', apksignerJar: signer, hermesc, java: 'java-gate', spawn };
    const result = inspectReleaseApk(apk, options);
    assert.equal(result.debuggable, false); assert.equal(result.classes, 8); assert.equal(result.dexCount, 2);
    assert.equal(result.packageName, 'com.humus.synapse.lantest');
    assert.deepEqual(calls[0].args, ['-jar', signer, 'verify', '--verbose', '--print-certs', apk]);
    assert.ok(calls.every(call => call.options.shell === false && call.options.timeout === 60000));
    badSignature = true;
    assert.throws(() => inspectReleaseApk(apk, options), /inspection tool failed/);
    badSignature = false;
    writeFileSync(apk, apkFixture([['classes.dex', dexFixture(definitions)], ['classes2.dex', buildConfigDexFixture({ ...LAN_CONFIG, SYNAPSE_ALLOW_LAN_HTTP: 'false' })],
      ['assets/index.android.bundle', bundleFixture()]]));
    assert.throws(() => inspectReleaseApk(apk, options), /authoritative native profile/);
    assert.throws(() => inspectReleaseApk(apk, { ...options, expectedFingerprint: undefined }), /expected public/);
  } finally { rmSync(root, { recursive: true, force: true }); }
});

test('production compiled marker and network contracts cannot inherit LAN allowance', () => {
  const table = parseResourceTable(resourceDump('release'));
  assert.equal(verifyCompiledManifest(parseXmlTree(manifestDump('release')), table, 'release').packageName, 'com.humus.synapse');
  assert.throws(() => verifyCompiledManifest(parseXmlTree(manifestDump('release')), parseResourceTable(resourceDump()), 'release'), /native profile/);
});
