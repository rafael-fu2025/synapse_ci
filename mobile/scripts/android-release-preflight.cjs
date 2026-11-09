/* eslint-env node, es2022 */
'use strict';

// Local-only, read-only prerequisite shape checks. Never prints configuration,
// credentials, aliases, signer paths, Firebase identifiers, or dotenv contents.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const SIGNING_KEYS = [
  'SYNAPSE_UPLOAD_STORE_FILE',
  'SYNAPSE_UPLOAD_STORE_PASSWORD',
  'SYNAPSE_UPLOAD_KEY_ALIAS',
  'SYNAPSE_UPLOAD_KEY_PASSWORD',
];
const PROFILES = { release: 'com.humus.synapse', lanTestRelease: 'com.humus.synapse.lantest' };

function nonempty(value) { return typeof value === 'string' && value.trim().length > 0; }
function normalizeFingerprint(value) {
  const normalized = String(value || '').replace(/:/g, '').toLowerCase();
  return /^[a-f0-9]{64}$/.test(normalized) ? normalized : null;
}

/** Only capture non-secret paths; password/alias entries become presence booleans. */
function propertyShape(text) {
  const present = {};
  let storeFile = null;
  let sdkDir = null;
  for (const line of text.split(/\r?\n/)) {
    for (const key of SIGNING_KEYS) {
      if (new RegExp(`^\\s*${key}\\s*[=:]`).test(line)) {
        present[key] = new RegExp(`^\\s*${key}\\s*[=:]\\s*\\S`).test(line);
      }
    }
    const store = /^\s*SYNAPSE_UPLOAD_STORE_FILE\s*[=:]\s*(.*?)\s*$/.exec(line);
    if (store) { storeFile = decodePropertyPath(store[1]); }
    const sdk = /^\s*sdk\.dir\s*[=:]\s*(.+?)\s*$/.exec(line);
    if (sdk) { sdkDir = decodePropertyPath(sdk[1]); }
  }
  return { present, storeFile, sdkDir };
}
function decodePropertyPath(value) {
  return value.replace(/\\u([a-fA-F0-9]{4})/g, (_, code) => String.fromCharCode(parseInt(code, 16)))
    .replace(/\\([\\: =])/g, '$1');
}

function firebaseShape(config, packageName) {
  const project = config?.project_info;
  const projectShape = nonempty(project?.project_id) && nonempty(project?.project_number);
  const clients = Array.isArray(config?.client) ? config.client : [];
  const matchingClient = clients.find(client => client?.client_info?.android_client_info?.package_name === packageName);
  const matchingClientShape = !!matchingClient && nonempty(matchingClient.client_info?.mobilesdk_app_id)
    && Array.isArray(matchingClient.api_key) && matchingClient.api_key.some(key => nonempty(key?.current_key));
  return { projectShape, matchingClient: !!matchingClient, matchingClientShape };
}

function preflight({
  profile = 'lanTestRelease',
  expectedFingerprint = process.env.SYNAPSE_EXPECTED_CERT_SHA256,
  mobileRoot = path.resolve(__dirname, '..'),
  env = process.env,
  home = os.homedir(),
  io = fs,
} = {}) {
  if (!Object.hasOwn(PROFILES, profile)) { throw new Error('Profile must be release or lanTestRelease'); }
  const androidRoot = path.join(mobileRoot, 'android');
  const appRoot = path.join(androidRoot, 'app');
  const isFile = filename => {
    try { return io.statSync(filename).isFile(); } catch { return false; }
  };
  const shapes = [
    path.join(env.GRADLE_USER_HOME || path.join(home, '.gradle'), 'gradle.properties'),
    path.join(androidRoot, 'gradle.properties'),
  ].map(filename => {
    try { return propertyShape(io.readFileSync(filename, 'utf8')); } catch { return propertyShape(''); }
  });
  const userProperties = shapes[0];
  const projectProperties = shapes[1];
  const signing = Object.fromEntries(SIGNING_KEYS.map(key => {
    const projectEnv = env[`ORG_GRADLE_PROJECT_${key}`];
    // A defined (even blank) higher-priority Gradle property blocks lower sources.
    const value = Object.hasOwn(userProperties.present, key) ? userProperties.present[key]
      : Object.hasOwn(projectProperties.present, key) ? projectProperties.present[key]
        : projectEnv !== undefined ? nonempty(projectEnv) : nonempty(env[key]);
    return [key, value];
  }));
  // Gradle user properties outrank project properties, which outrank env project properties.
  // Only the store path is captured; never read the keystore or retain passwords.
  const storeFile = userProperties.storeFile !== null ? userProperties.storeFile
    : projectProperties.storeFile !== null ? projectProperties.storeFile
      : env.ORG_GRADLE_PROJECT_SYNAPSE_UPLOAD_STORE_FILE !== undefined ? env.ORG_GRADLE_PROJECT_SYNAPSE_UPLOAD_STORE_FILE
        : env.SYNAPSE_UPLOAD_STORE_FILE;
  const signerFilePresent = nonempty(storeFile) && isFile(path.resolve(appRoot, storeFile.trim()));
  const signingConfigured = Object.values(signing).every(Boolean);

  const candidates = [path.join(appRoot, 'src', profile, 'google-services.json'), path.join(appRoot, 'google-services.json')];
  const selected = candidates.find(isFile);
  let firebase = { projectShape: false, matchingClient: false, matchingClientShape: false };
  let firebaseJsonValid = false;
  if (selected) {
    try {
      firebase = firebaseShape(JSON.parse(io.readFileSync(selected, 'utf8')), PROFILES[profile]);
      firebaseJsonValid = true;
    } catch { /* Never expose JSON parser messages that can quote input. */ }
  }

  let sdkRoot = env.ANDROID_HOME || env.ANDROID_SDK_ROOT;
  if (!sdkRoot) {
    try { sdkRoot = propertyShape(io.readFileSync(path.join(androidRoot, 'local.properties'), 'utf8')).sdkDir; } catch { /* absent */ }
  }
  sdkRoot ||= process.platform === 'win32' ? path.join(env.LOCALAPPDATA || path.join(home, 'AppData', 'Local'), 'Android', 'Sdk')
    : path.join(home, 'Android', 'Sdk');
  const gradle = io.readFileSync(path.join(androidRoot, 'build.gradle'), 'utf8');
  const compileSdk = /compileSdkVersion\s*=\s*(\d+)/.exec(gradle)?.[1];
  const buildTools = /buildToolsVersion\s*=\s*"([\d.]+)"/.exec(gradle)?.[1];
  const ndk = /ndkVersion\s*=\s*"([\d.]+)"/.exec(gradle)?.[1];
  const executable = process.platform === 'win32' ? '.exe' : '';
  const checks = {
    signingConfigured,
    signerFilePresent,
    expectedPublicFingerprintValid: !!normalizeFingerprint(expectedFingerprint),
    firebaseFilePresent: !!selected,
    firebaseVariantFileSelected: selected === candidates[0],
    firebaseJsonValid,
    firebaseProjectShape: firebase.projectShape,
    firebaseMatchingClient: firebase.matchingClient,
    firebaseMatchingClientShape: firebase.matchingClientShape,
    compileSdkPresent: !!compileSdk && [compileSdk, `${compileSdk}.0`]
      .some(version => isFile(path.join(sdkRoot, 'platforms', `android-${version}`, 'android.jar'))),
    buildToolsPresent: !!buildTools && isFile(path.join(sdkRoot, 'build-tools', buildTools, `aapt2${executable}`)),
    apksignerPresent: !!buildTools && isFile(path.join(sdkRoot, 'build-tools', buildTools, 'lib', 'apksigner.jar')),
    ndkPresent: !!ndk && isFile(path.join(sdkRoot, 'ndk', ndk, 'source.properties')),
    nodeModulesPresent: isFile(path.join(mobileRoot, 'node_modules', 'react-native', 'package.json')),
    hermesCompilerPresent: isFile(path.join(mobileRoot, 'node_modules', 'hermes-compiler', 'hermesc',
      process.platform === 'win32' ? 'win64-bin' : process.platform === 'darwin' ? 'osx-bin' : 'linux64-bin',
      process.platform === 'win32' ? 'hermesc.exe' : 'hermesc')),
  };
  const required = Object.keys(checks).filter(key => key !== 'firebaseVariantFileSelected');
  return { profile, checks, ready: required.every(key => checks[key]) };
}

function main(args = process.argv.slice(2)) {
  try {
    let profile = 'lanTestRelease';
    let expectedFingerprint = process.env.SYNAPSE_EXPECTED_CERT_SHA256;
    for (let index = 0; index < args.length; index++) {
      if (args[index] === '--profile' && args[index + 1]) { profile = args[++index]; }
      else if (args[index] === '--expected-cert-sha256' && args[index + 1]) { expectedFingerprint = args[++index]; }
      else { throw new Error('Usage: node scripts/android-release-preflight.cjs [--profile lanTestRelease|release] [--expected-cert-sha256 <public SHA-256>]'); }
    }
    const result = preflight({ profile, expectedFingerprint });
    console.log(JSON.stringify(result, null, 2));
    if (!result.ready) {
      console.error('Release prerequisites are missing. Supply only the inputs whose checks are false: explicit private signer/public SHA-256, matching Firebase client, or existing native tools. No build, install, key generation, or debug waiver was attempted.');
    }
    return result.ready ? 0 : 1;
  } catch {
    console.error('Preflight could not complete. Check the profile, public fingerprint format, and readable local build configuration; no configuration values were printed.');
    return 1;
  }
}

if (require.main === module) { process.exitCode = main(); }
module.exports = { SIGNING_KEYS, PROFILES, normalizeFingerprint, propertyShape, firebaseShape, preflight, main };
