/* eslint-env node, es2022 */
'use strict';

const assert = require('node:assert/strict');
const { readFileSync, existsSync, readdirSync, mkdtempSync, writeFileSync, rmSync } = require('node:fs');
const { spawnSync } = require('node:child_process');
const { homedir, tmpdir } = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { SIGNING_KEYS, propertyShape, firebaseShape, preflight, normalizeFingerprint } = require('./android-release-preflight.cjs');

const mobile = path.resolve(__dirname, '..');
const app = path.join(mobile, 'android', 'app');
const gradle = readFileSync(path.join(app, 'build.gradle'), 'utf8');

function block(source, marker) {
  const start = source.indexOf(marker);
  assert.ok(start >= 0, `Missing ${marker}`);
  const opening = source.indexOf('{', start + marker.length);
  let depth = 1;
  for (let index = opening + 1; index < source.length; index++) {
    if (source[index] === '{') { depth++; }
    if (source[index] === '}' && --depth === 0) { return source.slice(opening + 1, index); }
  }
  assert.fail(`Unclosed ${marker}`);
}

test('Android packaging CI explicitly avoids the retired SDK tools package', () => {
  const workflow = readFileSync(path.join(mobile, '..', '.github/workflows/mobile.yml'), 'utf8');
  const packaging = workflow.slice(workflow.indexOf('  android-packaging:'));
  assert.match(packaging, /android-actions\/setup-android@v3\r?\n {8}with:\r?\n {10}packages: 'platform-tools'/);
});

test('LAN is a separate bundled nondebuggable release with normal optimization defaults', () => {
  const buildTypes = block(gradle, 'buildTypes');
  const release = block(buildTypes, 'release');
  const lan = block(buildTypes, 'lanTestRelease');
  assert.match(release, /debuggable false/);
  assert.match(release, /minifyEnabled enableProguardInReleaseBuilds/);
  assert.match(release, /shrinkResources enableProguardInReleaseBuilds/);
  assert.match(gradle, /SYNAPSE_ENABLE_MINIFICATION[^\n]+\?: 'true'/);
  assert.match(release, /proguard-android-optimize\.txt/);
  assert.match(lan, /initWith release/);
  assert.match(lan, /debuggable false/);
  assert.match(lan, /matchingFallbacks = \["release"\]/);
  assert.match(lan, /applicationIdSuffix "\.lantest"/);
  assert.match(lan, /versionNameSuffix "-lan-test"/);
  assert.doesNotMatch(lan, /minifyEnabled false|shrinkResources false|signingConfigs\.debug/);
  const uncommentedReact = block(gradle, 'react').split(/\r?\n/).filter(line => !/^\s*\/\//.test(line)).join('\n');
  assert.doesNotMatch(uncommentedReact, /debuggableVariants[^\n]*lanTestRelease/);
});

test('native markers sanitize the mutable DSL before variant provider queries', () => {
  const components = block(gradle, 'androidComponents');
  assert.ok(components.indexOf('finalizeDsl') < components.indexOf('onVariants'));
  assert.doesNotMatch(components, /variant\.(?:buildConfigFields|resValues)\.(?:get|set)\(/);
  assert.match(gradle, /variant\.buildType == "release" \|\| variant\.buildType == "lanTestRelease"/);
  assert.match(gradle, /SYNAPSE_BUILD_PROFILE: isLanTest \? "lan-test" : "production"/);
  assert.match(gradle, /SYNAPSE_ALLOW_LAN_HTTP: isLanTest \? "true" : "false"/);
  assert.match(gradle, /variant\.buildConfigFields\.put\(key,\s*new BuildConfigField\("String"/);
  assert.match(gradle, /variant\.resValues\.put\(variant\.makeResValueKey\("string", key\),\s*new ResValue\(value/);
  assert.match(gradle, /markerNames = \["SYNAPSE_BUILD_PROFILE", "SYNAPSE_ALLOW_LAN_HTTP"\]/);
  assert.match(gradle, /markerNames\.each \{ dimension\.buildConfigFields\.remove\(it\) \}/);
  assert.match(gradle, /dimension\.resValues\.entrySet\(\)\.removeAll \{ it\.value\.type == "string" && markerNames\.contains\(it\.value\.name\) \}/);
  assert.doesNotMatch(gradle, /project\.env\.remove|env\[["']SYNAPSE_ALLOW_LAN_HTTP/);
  assert.match(gradle, /variant\.resValues\.put\(variant\.makeResValueKey\("string", "build_config_package"\)/);
  assert.match(gradle, /buildType\.resValues\.entrySet\(\)\.removeAll \{ it\.value\.type == "string" && it\.value\.name == "app_name" \}/);
  assert.match(gradle, /inheritedNativeFields\.each \{ name, field -> buildType\.buildConfigFields\.putIfAbsent\(name, field\) \}/);
  assert.match(gradle, /inheritedLabels\.each \{ key, field -> buildType\.resValues\.putIfAbsent\(key, field\) \}/);
  const proguard = readFileSync(path.join(app, 'proguard-rules.pro'), 'utf8');
  assert.match(proguard, /-keep class com\.humus\.synapse\.BuildConfig \{ public static <fields>; \}/);
  for (const name of ['WindowRefreshRatePolicy', 'SupportedRefreshRatePolicy', 'SupportedRefreshRatePolicy$Mode']) {
    assert.ok(proguard.includes(`-keep,allowoptimization class com.humus.synapse.${name} { *; }`));
  }
  assert.doesNotMatch(proguard, /-keep class com\.humus\.synapse\.\*\*/);
  const keep = readFileSync(path.join(app, 'src/main/res/raw/synapse_config_keep.xml'), 'utf8');
  for (const name of ['build_config_package', 'SYNAPSE_BUILD_PROFILE', 'SYNAPSE_ALLOW_LAN_HTTP']) { assert.ok(keep.includes(`@string/${name}`)); }
});

test('debug remains unmodified and both release overlays default deny with exact LAN IPs only', () => {
  const debug = block(block(gradle, 'buildTypes'), 'debug');
  assert.match(debug, /signingConfig signingConfigs\.debug/);
  assert.match(debug, /usesCleartextTraffic: "true"/);
  assert.doesNotMatch(debug, /SYNAPSE_BUILD_PROFILE|SYNAPSE_ALLOW_LAN_HTTP|networkSecurityConfig/);
  const mainManifest = readFileSync(path.join(app, 'src/main/AndroidManifest.xml'), 'utf8');
  assert.doesNotMatch(mainManifest, /networkSecurityConfig/);
  const production = readFileSync(path.join(app, 'src/release/res/xml/network_security_config.xml'), 'utf8');
  const lan = readFileSync(path.join(app, 'src/lanTestRelease/res/xml/network_security_config.xml'), 'utf8');
  for (const profile of ['release', 'lanTestRelease']) {
    assert.match(readFileSync(path.join(app, `src/${profile}/AndroidManifest.xml`), 'utf8'), /android:networkSecurityConfig="@xml\/network_security_config"/);
  }
  for (const config of [production, lan]) {
    assert.match(config, /<base-config cleartextTrafficPermitted="false">/);
    assert.match(config, /<certificates src="system"\s*\/>/);
    assert.doesNotMatch(config, /debug-overrides|src="user"|overridePins/);
  }
  assert.doesNotMatch(production, /domain-config|cleartextTrafficPermitted="true"/);
  const hosts = [...lan.matchAll(/<domain includeSubdomains="false">([^<]+)<\/domain>/g)].map(match => match[1]).sort();
  assert.deepEqual(hosts, ['172.16.153.237', '192.168.1.11']);
  assert.equal((lan.match(/<domain\s/g) || []).length, 2);
  assert.match(readFileSync(path.join(app, 'src/lanTestRelease/res/values/strings.xml'), 'utf8'), />Synapse LAN Test</);
});

test('signing gates actual release artifact tasks without a silent debug or unsigned fallback', () => {
  assert.doesNotMatch(gradle.split(/\r?\n/).filter(line => !/^\s*\/\//.test(line)).join('\n'), /gradle\.startParameter\.taskNames/);
  assert.match(gradle, /signingConfig hasReleaseSigning \? signingConfigs\.release : \(allowDebugSigning \? signingConfigs\.debug : null\)/);
  assert.match(gradle, /if \(!hasReleaseSigning && !allowDebugSigning\)\s*\{\s*throw new GradleException/);
  assert.match(gradle, /tasks\.configureEach \{ task ->/);
  for (const task of ['assemble', 'bundle', 'package', 'sign', 'validateSigning']) {
    assert.ok(gradle.includes(`"${task}\${capitalized}`), `Actual ${task} task gated`);
  }
  assert.match(gradle, /\]\.collect \{ it\.toString\(\) \}/);
  assert.match(gradle, /artifactTaskNames\.contains\(task\.name\)/);
  assert.match(gradle, /task\.dependsOn\(prerequisites\)/);
  assert.match(gradle, /\["release", "lanTestRelease"\]\.each/);
  assert.doesNotMatch(gradle, /"pre\$\{capitalized\}Build"/);
});

function findJar(root, pattern) {
  if (!existsSync(root)) { return null; }
  for (const entry of readdirSync(root, { withFileTypes: true })) {
    const filename = path.join(root, entry.name);
    if (entry.isDirectory()) { const match = findJar(filename, pattern); if (match) { return match; } }
    else if (pattern.test(entry.name)) { return filename; }
  }
  return null;
}
const gradleHome = process.env.GRADLE_USER_HOME || path.join(homedir(), '.gradle');
const groovy = findJar(path.join(gradleHome, 'wrapper', 'dists'), /^groovy-4\.\d+\.\d+\.jar$/);
const agp = findJar(path.join(gradleHome, 'caches', 'modules-2', 'files-2.1', 'com.android.tools.build', 'gradle-api', '9.2.1'), /^gradle-api-9\.2\.1\.jar$/);
const kotlin = findJar(path.join(gradleHome, 'caches', 'modules-2', 'files-2.1', 'org.jetbrains.kotlin', 'kotlin-stdlib'), /^kotlin-stdlib-2\.2\.(?:0|10)\.jar$/);

test('real Groovy executes extracted marker and signer gate code with eager dotenv collisions and aggregate package tasks',
  { skip: (!groovy || !agp || !kotlin) && 'Cached Groovy/AGP APIs absent; static build contracts still run; no dependency install.' }, () => {
    const root = mkdtempSync(path.join(tmpdir(), 'synapse-gradle-contract-unit-'));
    const markerBlock = block(gradle, 'androidComponents');
    const gatingStart = gradle.indexOf('def releasePrerequisiteTasks = []');
    const gatingEnd = gradle.indexOf('// Keep the existing optional debug bootstrap', gatingStart);
    assert.ok(gatingStart >= 0 && gatingEnd > gatingStart);
    const source = `
import com.android.build.api.variant.BuildConfigField
import com.android.build.api.variant.ResValue
class GradleException extends RuntimeException { GradleException(String text) { super(text) } }
class FakeKey { String type; String name }
class FakeProperty {
  Map value = [:]
  Map phase
  void put(Object key, Object item) { value[key] = item }
  Map get() {
    assert phase.complete : 'Variant providers cannot be queried during onVariants'
    value
  }
}
class FakeDimension {
  String name
  Map buildConfigFields = [:]
  Map resValues = [:]
}
class FakeDsl {
  FakeDimension defaultConfig
  List buildTypes
}
class FakeVariant {
  String buildType
  FakeProperty buildConfigFields
  FakeProperty resValues
  Map keys = [:]
  FakeKey makeResValueKey(String type, String name) {
    keys.computeIfAbsent(type + '/' + name) { new FakeKey(type: type, name: name) }
  }
}
class FakeComponents {
  FakeDsl dsl
  List variants = []
  Map phase = [complete: false]
  FakeComponents selector() { this }
  FakeComponents all() { this }
  void finalizeDsl(Closure body) { body(dsl) }
  void onVariants(FakeComponents ignored, Closure body) {
    dsl.buildTypes.each { buildType ->
      def variant = new FakeVariant(buildType: buildType.name,
        buildConfigFields: new FakeProperty(phase: phase), resValues: new FakeProperty(phase: phase))
      variant.buildConfigFields.value.putAll(dsl.defaultConfig.buildConfigFields + buildType.buildConfigFields)
      (dsl.defaultConfig.resValues + buildType.resValues).values().each { field ->
        variant.resValues.put(variant.makeResValueKey(field.type, field.name), new ResValue(field.value, 'fixture'))
      }
      variants.add(variant)
      body(variant)
    }
    phase.complete = true
  }
}
def spoofNames = ['SYNAPSE_BUILD_PROFILE', 'SYNAPSE_ALLOW_LAN_HTTP', 'build_config_package', 'app_name', 'API_BASE_URL',
  'APPLICATION_ID', 'BUILD_TYPE', 'DEBUG', 'VERSION_CODE', 'VERSION_NAME']
def spoofDimension = { String name ->
  def dimension = new FakeDimension(name: name)
  spoofNames.each { key ->
    def field = [type: 'String', name: key, value: '"dotenv-spoof"']
    dimension.buildConfigFields[key] = field
    dimension.resValues['string/' + key] = [type: 'string', name: key, value: 'dotenv-spoof']
  }
  dimension
}
def fixtureDsl = new FakeDsl(defaultConfig: spoofDimension('main'),
  buildTypes: ['debug', 'release', 'lanTestRelease'].collect { new FakeDimension(name: it) })
// A build-type override must not bypass the reserved marker or native identity cleanup.
fixtureDsl.buildTypes.find { it.name == 'lanTestRelease' }.with {
  buildConfigFields.putAll(spoofDimension('override').buildConfigFields)
  resValues.putAll(spoofDimension('override').resValues)
}
def components = new FakeComponents(dsl: fixtureDsl)
def markerClosure = { ${markerBlock} }
markerClosure.delegate = components
markerClosure.resolveStrategy = Closure.DELEGATE_FIRST
markerClosure()
components.variants.each { variant ->
  def readResource = { name -> variant.resValues.get()[variant.makeResValueKey('string', name)]?.value }
  assert readResource('build_config_package') == 'com.humus.synapse'
  assert variant.buildConfigFields.get().API_BASE_URL.value == '"dotenv-spoof"'
  if (variant.buildType == 'debug') {
    assert !variant.buildConfigFields.get().containsKey('SYNAPSE_BUILD_PROFILE')
    assert !variant.buildConfigFields.get().containsKey('SYNAPSE_ALLOW_LAN_HTTP')
    assert readResource('SYNAPSE_BUILD_PROFILE') == null
    assert readResource('SYNAPSE_ALLOW_LAN_HTTP') == null
    ['APPLICATION_ID', 'BUILD_TYPE', 'DEBUG', 'VERSION_CODE', 'VERSION_NAME'].each {
      assert variant.buildConfigFields.get()[it].value == '"dotenv-spoof"'
    }
    assert readResource('app_name') == 'dotenv-spoof'
  } else {
    ['APPLICATION_ID', 'BUILD_TYPE', 'DEBUG', 'VERSION_CODE', 'VERSION_NAME'].each { assert !variant.buildConfigFields.get().containsKey(it) }
    def expected = variant.buildType == 'lanTestRelease' ? ['lan-test', 'true'] : ['production', 'false']
    ['SYNAPSE_BUILD_PROFILE', 'SYNAPSE_ALLOW_LAN_HTTP'].eachWithIndex { name, index ->
      assert variant.buildConfigFields.get()[name].type == 'String'
      assert variant.buildConfigFields.get()[name].value instanceof String
      assert variant.buildConfigFields.get()[name].value == '"' + expected[index] + '"'
      assert readResource(name) == expected[index]
    }
    assert readResource('app_name') == (variant.buildType == 'lanTestRelease' ? null : 'dotenv-spoof')
  }
}
class FakeTask {
  String name
  String group
  String description
  List dependencies = []
  List actions = []
  void dependsOn(Object item) { dependencies.add(item) }
  void doLast(Closure item) { actions.add(item) }
}
class FakeTasks {
  Map items = [:]
  List callbacks = []
  FakeTask register(Object name, Closure body) {
    def task = new FakeTask(name: name.toString())
    items[task.name] = task
    body.delegate = task
    body.resolveStrategy = Closure.DELEGATE_FIRST
    body()
    callbacks.each { it(task) }
    task
  }
  void configureEach(Closure callback) { callbacks.add(callback); items.values().each { callback(it) } }
}
def tasks = new FakeTasks()
def hasReleaseSigning = false
def allowDebugSigning = false
def uploadStoreFile = 'unit-fixture.jks'
def file = { String filename -> new File('does-not-exist-unit-fixture', filename) }
def logger = new Expando(warn: { String ignored -> })
${gradle.slice(gatingStart, gatingEnd)}
['Release', 'LanTestRelease'].each { capitalized ->
  ['assemble', 'bundle', 'package', 'validateSigning'].each { prefix ->
    def task = tasks.register(prefix + capitalized) { }
    assert task.dependencies.any { it.name == 'verify' + capitalized + 'ArtifactPrerequisites' }
  }
  ['package', 'sign'].each { prefix ->
    def task = tasks.register(prefix + capitalized + 'Bundle') { }
    assert task.dependencies.any { it.name == 'verify' + capitalized + 'ArtifactPrerequisites' }
  }
  def gate = tasks.items['verify' + capitalized + 'ArtifactPrerequisites']
  boolean rejected = false
  try { gate.actions.each { it() } } catch (GradleException expected) { rejected = expected.message.contains('Release signing is not configured') }
  assert rejected
}
def aggregate = tasks.items.verifyReleasePrerequisites
assert aggregate.dependencies.flatten().size() == 2
println 'Executed native markers and artifact signer gates with spoofed dotenv and aggregate dependencies'
`;
    try {
      const filename = path.join(root, 'contract.groovy'); writeFileSync(filename, source);
      const java = process.env.JAVA_HOME ? path.join(process.env.JAVA_HOME, 'bin', process.platform === 'win32' ? 'java.exe' : 'java') : 'java';
      const result = spawnSync(java, ['-cp', [groovy, agp, kotlin].join(path.delimiter), 'groovy.ui.GroovyMain', filename],
        { cwd: root, shell: false, windowsHide: true, encoding: 'utf8', timeout: 60000 });
      assert.equal(result.error, undefined, result.error?.message);
      assert.equal(result.status, 0, `Isolated Groovy contracts failed\n${result.stdout}\n${result.stderr}`);
      assert.match(result.stdout, /Executed native markers and artifact signer gates/);
    } finally { rmSync(root, { recursive: true, force: true }); }
  });

test('Firebase plugin supports variant files and release preflight never disables or fabricates Firebase', () => {
  assert.match(gradle, /file\("src\/lanTestRelease\/google-services\.json"\)/);
  assert.match(gradle, /file\("src\/release\/google-services\.json"\)/);
  assert.match(gradle, /apply plugin: "com\.google\.gms\.google-services"/);
  assert.match(gradle, /if \(!firebaseCandidates\.any \{ it\.isFile\(\) \}\)\s*\{\s*throw new GradleException/);
  assert.match(gradle, /com\.humus\.synapse\.lantest/);
  assert.doesNotMatch(gradle, /missingGoogleServicesStrategy|googleServices\s*\{[^}]*IGNORE|process.*GoogleServices.*enabled\s*=\s*false/);
});

test('safe property shape never retains passwords and public fingerprint validation is strict', () => {
  const shape = propertyShape('SYNAPSE_UPLOAD_STORE_FILE=C\\:\\keys\\\\release.jks\nSYNAPSE_UPLOAD_STORE_PASSWORD=sensitive-password\nSYNAPSE_UPLOAD_KEY_ALIAS=sensitive-alias\nSYNAPSE_UPLOAD_KEY_PASSWORD=sensitive-key-password\n');
  assert.deepEqual(Object.keys(shape.present).sort(), [...SIGNING_KEYS].sort());
  assert.equal(shape.storeFile, 'C:\\keys\\release.jks');
  assert.doesNotMatch(JSON.stringify(shape), /sensitive/);
  assert.equal(normalizeFingerprint('AA:'.repeat(31) + 'AA'), 'aa'.repeat(32));
  for (const value of ['', 'sha256-test', 'aa'.repeat(31), 'aa'.repeat(33), ' '.repeat(64)]) { assert.equal(normalizeFingerprint(value), null); }
});

test('Firebase shape validates only the exact suffixed client and does not expose config values', () => {
  const config = { project_info: { project_id: 'private-project', project_number: 'private-number' },
    client: [{ client_info: { mobilesdk_app_id: 'private-app-id', android_client_info: { package_name: 'com.humus.synapse' } }, api_key: [{ current_key: 'private-api-key' }] }] };
  assert.deepEqual(firebaseShape(config, 'com.humus.synapse.lantest'), { projectShape: true, matchingClient: false, matchingClientShape: false });
  config.client[0].client_info.android_client_info.package_name = 'com.humus.synapse.lantest';
  const shape = firebaseShape(config, 'com.humus.synapse.lantest');
  assert.deepEqual(shape, { projectShape: true, matchingClient: true, matchingClientShape: true });
  assert.doesNotMatch(JSON.stringify(shape), /private-/);
});

test('preflight selects variant config first, never reads env/keystore, and returns only boolean checks', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'synapse-release-shape-'));
  const files = new Map();
  const filenames = [];
  const add = (name, content = '') => {
    const filename = path.join(root, name); files.set(filename, content); return filename;
  };
  const property = SIGNING_KEYS.map(key => `${key}=${key === SIGNING_KEYS[0] ? 'signer.jks' : 'private-unit-test-value'}`).join('\n');
  add('android/gradle.properties', property);
  add('android/build.gradle', 'compileSdkVersion = 37\nbuildToolsVersion = "37.0.0"\nndkVersion = "27.1.12297006"');
  add('android/app/signer.jks');
  const base = { project_info: { project_id: 'private-id', project_number: 'private-number' },
    client: [{ client_info: { mobilesdk_app_id: 'private-app', android_client_info: { package_name: 'com.humus.synapse' } }, api_key: [{ current_key: 'private-api' }] }] };
  add('android/app/google-services.json', JSON.stringify(base));
  const variant = structuredClone(base);
  variant.client[0].client_info.android_client_info.package_name = 'com.humus.synapse.lantest';
  add('android/app/src/lanTestRelease/google-services.json', JSON.stringify(variant));
  for (const filename of ['sdk/platforms/android-37.0/android.jar', 'sdk/build-tools/37.0.0/aapt2' + (process.platform === 'win32' ? '.exe' : ''),
    'sdk/build-tools/37.0.0/lib/apksigner.jar', 'sdk/ndk/27.1.12297006/source.properties', 'node_modules/react-native/package.json',
    `node_modules/hermes-compiler/hermesc/${process.platform === 'win32' ? 'win64-bin/hermesc.exe' : process.platform === 'darwin' ? 'osx-bin/hermesc' : 'linux64-bin/hermesc'}`]) { add(filename); }
  const io = {
    readFileSync(filename) { filenames.push(filename); if (!files.has(filename)) { throw new Error('missing'); } return files.get(filename); },
    statSync(filename) { if (!files.has(filename)) { throw new Error('missing'); } return { isFile: () => true }; },
  };
  try {
    const result = preflight({ mobileRoot: root, home: root, env: { ANDROID_HOME: path.join(root, 'sdk') }, expectedFingerprint: 'a1'.repeat(32), io });
    assert.equal(result.ready, true);
    assert.equal(result.checks.firebaseVariantFileSelected, true);
    assert.ok(Object.values(result.checks).every(value => typeof value === 'boolean'));
    assert.doesNotMatch(JSON.stringify(result), /private-|signer\.jks/);
    assert.ok(filenames.every(filename => !/\.env|\.jks$|\.keystore$/.test(filename)));
    files.set(path.join(root, '.gradle/gradle.properties'), 'SYNAPSE_UPLOAD_STORE_PASSWORD=\n');
    const shadowed = preflight({ mobileRoot: root, home: root, env: { ANDROID_HOME: path.join(root, 'sdk'), SYNAPSE_UPLOAD_STORE_PASSWORD: 'private-env' }, expectedFingerprint: 'a1'.repeat(32), io });
    assert.equal(shadowed.ready, false);
    assert.equal(shadowed.checks.signingConfigured, false);
    files.delete(path.join(root, '.gradle/gradle.properties'));
    files.set(path.join(root, 'android/app/src/lanTestRelease/google-services.json'), '{"invalid-private-json');
    const invalid = preflight({ mobileRoot: root, home: root, env: { ANDROID_HOME: path.join(root, 'sdk') }, expectedFingerprint: 'a1'.repeat(32), io });
    assert.equal(invalid.ready, false);
    assert.equal(invalid.checks.firebaseJsonValid, false);
    assert.doesNotMatch(JSON.stringify(invalid), /private-json/);
  } finally { rmSync(root, { recursive: true, force: true }); }
});
