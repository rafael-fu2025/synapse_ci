/* eslint-env node, es2022 */
'use strict';

const { readFileSync, existsSync, mkdtempSync, writeFileSync, rmSync } = require('node:fs');
const { spawnSync } = require('node:child_process');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { inflateRawSync } = require('node:zlib');
const { normalizeFingerprint } = require('./android-release-preflight.cjs');

const REQUIRED_CLASSES = [
  'Lcom/humus/synapse/MainActivity;',
  'Lcom/humus/synapse/MainApplication;',
  'Lcom/humus/synapse/WindowRefreshRatePolicy;',
  'Lcom/humus/synapse/SupportedRefreshRatePolicy;',
  'Lcom/humus/synapse/SupportedRefreshRatePolicy$Mode;',
];
const NAMESPACE = 'com.humus.synapse';
const LAN_ORIGINS = ['http://172.16.153.237:8080', 'http://192.168.1.11:8080'];
const MARKER_NAMES = ['SYNAPSE_BUILD_PROFILE', 'SYNAPSE_ALLOW_LAN_HTTP'];
const MAX_DEX_BYTES = 64 * 1024 * 1024;

function range(buffer, offset, size) {
  if (!Number.isSafeInteger(offset) || !Number.isSafeInteger(size)
      || offset < 0 || size < 0 || offset + size > buffer.length) {
    throw new Error('Invalid APK/DEX data range');
  }
}

/** Read selected bounded entries; never extract or execute APK content. */
function apkEntries(apk, select) {
  let end = -1;
  for (let offset = apk.length - 22; offset >= Math.max(0, apk.length - 65557); offset--) {
    if (apk.readUInt32LE(offset) === 0x06054b50
        && offset + 22 + apk.readUInt16LE(offset + 20) === apk.length) {
      end = offset;
      break;
    }
  }
  if (end === -1) { throw new Error('APK ZIP directory is missing'); }
  const entries = apk.readUInt16LE(end + 10);
  if (apk.readUInt16LE(end + 4) !== 0 || apk.readUInt16LE(end + 6) !== 0
      || apk.readUInt16LE(end + 8) !== entries || entries === 0xffff) {
    throw new Error('Split/ZIP64 APK directories are not supported');
  }
  const directorySize = apk.readUInt32LE(end + 12);
  const directory = apk.readUInt32LE(end + 16);
  range(apk, directory, directorySize);
  const files = [];
  let offset = directory;
  for (let index = 0; index < entries; index++) {
    range(apk, offset, 46);
    if (apk.readUInt32LE(offset) !== 0x02014b50) { throw new Error('Invalid APK directory entry'); }
    const nameSize = apk.readUInt16LE(offset + 28);
    const entrySize = 46 + nameSize + apk.readUInt16LE(offset + 30) + apk.readUInt16LE(offset + 32);
    range(apk, offset, entrySize);
    const name = apk.toString('utf8', offset + 46, offset + 46 + nameSize);
    if (select(name)) {
      const method = apk.readUInt16LE(offset + 10);
      const compressedSize = apk.readUInt32LE(offset + 20);
      const size = apk.readUInt32LE(offset + 24);
      const local = apk.readUInt32LE(offset + 42);
      if ((apk.readUInt16LE(offset + 8) & 1) !== 0 || size > MAX_DEX_BYTES || compressedSize > MAX_DEX_BYTES) {
        throw new Error(`Unsupported DEX entry: ${name}`);
      }
      range(apk, local, 30);
      if (apk.readUInt32LE(local) !== 0x04034b50) { throw new Error('Invalid APK local entry'); }
      const data = local + 30 + apk.readUInt16LE(local + 26) + apk.readUInt16LE(local + 28);
      range(apk, data, compressedSize);
      const compressed = apk.subarray(data, data + compressedSize);
      const bytes = method === 0 ? compressed : method === 8
        ? inflateRawSync(compressed, { maxOutputLength: MAX_DEX_BYTES }) : null;
      if (!bytes || bytes.length !== size) { throw new Error(`Invalid APK compression/size: ${name}`); }
      if (files.some(file => file.name === name)) { throw new Error(`Duplicate APK entry: ${name}`); }
      files.push({ name, bytes });
    }
    offset += entrySize;
  }
  if (offset !== directory + directorySize) { throw new Error('Invalid APK ZIP directory size'); }
  return files;
}

function dexFiles(apk) {
  const files = apkEntries(apk, name => /^classes(?:[2-9]|[1-9]\d+)?\.dex$/.test(name))
    .map(({ name, bytes }) => ({ name, dex: bytes }));
  if (files.length === 0) { throw new Error('APK has no valid DEX directory'); }
  return files;
}

/** Follow class_defs -> type_ids -> string_ids, not references in the string table. */
function classDefinitions(dex) {
  range(dex, 0, 112);
  if (!/^dex\n0(?:3[5-9]|40)\0$/.test(dex.toString('ascii', 0, 8))
      || dex.readUInt32LE(32) !== dex.length || dex.readUInt32LE(36) !== 112
      || dex.readUInt32LE(40) !== 0x12345678) {
    throw new Error('Unsupported or invalid DEX header');
  }
  const strings = dex.readUInt32LE(56);
  const stringOffset = dex.readUInt32LE(60);
  const types = dex.readUInt32LE(64);
  const typeOffset = dex.readUInt32LE(68);
  const classes = dex.readUInt32LE(96);
  const classOffset = dex.readUInt32LE(100);
  range(dex, stringOffset, strings * 4);
  range(dex, typeOffset, types * 4);
  range(dex, classOffset, classes * 32);
  const result = new Set();
  for (let index = 0; index < classes; index++) {
    const type = dex.readUInt32LE(classOffset + index * 32);
    if (type >= types) { throw new Error('Invalid DEX class type'); }
    const string = dex.readUInt32LE(typeOffset + type * 4);
    if (string >= strings) { throw new Error('Invalid DEX type descriptor'); }
    let start = dex.readUInt32LE(stringOffset + string * 4);
    let byte;
    let count = 0;
    do {
      range(dex, start, 1);
      byte = dex[start++];
      if (++count > 5) { throw new Error('Invalid DEX string length'); }
    } while ((byte & 0x80) !== 0);
    const end = dex.indexOf(0, start);
    if (end === -1) { throw new Error('Unterminated DEX descriptor'); }
    result.add(dex.toString('utf8', start, end));
  }
  return result;
}

function verifyApk(apk, required = REQUIRED_CLASSES) {
  const files = dexFiles(apk);
  const definitions = new Set(files.flatMap(({ dex }) => [...classDefinitions(dex)]));
  const missing = required.filter(name => !definitions.has(name));
  if (missing.length > 0) { throw new Error(`APK is missing native class definitions:\n${missing.join('\n')}`); }
  return { dexCount: files.length, classes: required.length };
}

function uleb(dex, cursor) {
  let result = 0;
  for (let count = 0; count < 5; count++) {
    range(dex, cursor.offset, 1);
    const byte = dex[cursor.offset++];
    result += (byte & 0x7f) * 2 ** (count * 7);
    if ((byte & 0x80) === 0) {
      if (result > 0xffffffff) { throw new Error('Invalid DEX ULEB128'); }
      return result;
    }
  }
  throw new Error('Invalid DEX ULEB128');
}

function dexString(dex, index) {
  const count = dex.readUInt32LE(56);
  const offset = dex.readUInt32LE(60);
  if (index >= count) { throw new Error('Invalid DEX string index'); }
  range(dex, offset + index * 4, 4);
  const cursor = { offset: dex.readUInt32LE(offset + index * 4) };
  uleb(dex, cursor);
  const end = dex.indexOf(0, cursor.offset);
  if (end < 0) { throw new Error('Unterminated DEX string'); }
  return dex.toString('utf8', cursor.offset, end);
}

/** Resolve actual BuildConfig class/static-field encoded values, not string references. */
function buildConfigStrings(dex) {
  classDefinitions(dex); // validates header and class/type/string table bounds
  const types = dex.readUInt32LE(64);
  const typeOffset = dex.readUInt32LE(68);
  const fields = dex.readUInt32LE(80);
  const fieldOffset = dex.readUInt32LE(84);
  const classes = dex.readUInt32LE(96);
  const classOffset = dex.readUInt32LE(100);
  range(dex, fieldOffset, fields * 8);
  const descriptor = type => {
    if (type >= types) { throw new Error('Invalid DEX field type'); }
    return dexString(dex, dex.readUInt32LE(typeOffset + type * 4));
  };
  for (let index = 0; index < classes; index++) {
    const entry = classOffset + index * 32;
    const classType = dex.readUInt32LE(entry);
    if (descriptor(classType) !== 'Lcom/humus/synapse/BuildConfig;') { continue; }
    const cursor = { offset: dex.readUInt32LE(entry + 24) };
    const valuesOffset = dex.readUInt32LE(entry + 28);
    if (!cursor.offset || !valuesOffset) { throw new Error('BuildConfig static values are missing'); }
    const staticCount = uleb(dex, cursor);
    uleb(dex, cursor); uleb(dex, cursor); uleb(dex, cursor);
    if (staticCount > fields) { throw new Error('Invalid BuildConfig static field count'); }
    const staticFields = [];
    let field = 0;
    for (let fieldIndex = 0; fieldIndex < staticCount; fieldIndex++) {
      field += uleb(dex, cursor);
      const flags = uleb(dex, cursor);
      if (field >= fields) { throw new Error('Invalid BuildConfig field index'); }
      const record = fieldOffset + field * 8;
      if (dex.readUInt16LE(record) !== classType) { throw new Error('Invalid BuildConfig field owner'); }
      staticFields.push({ name: dexString(dex, dex.readUInt32LE(record + 4)),
        type: descriptor(dex.readUInt16LE(record + 2)), flags });
    }
    const values = { offset: valuesOffset };
    const count = uleb(dex, values);
    if (count > staticCount) { throw new Error('Invalid BuildConfig value count'); }
    const result = {};
    for (let valueIndex = 0; valueIndex < count; valueIndex++) {
      range(dex, values.offset, 1);
      const header = dex[values.offset++];
      const kind = header & 0x1f;
      const size = (header >>> 5) + 1;
      if (kind === 0x1c || kind === 0x1d) { throw new Error('Unsupported BuildConfig compound value'); }
      let value = null;
      if (kind !== 0x1e && kind !== 0x1f) {
        range(dex, values.offset, size);
        for (let byte = 0; byte < size; byte++) {
          if (kind === 0x17) { value = (value || 0) + dex[values.offset + byte] * 2 ** (byte * 8); }
        }
        values.offset += size;
      }
      const info = staticFields[valueIndex];
      if (kind === 0x17 && size > 4) { throw new Error('Invalid BuildConfig encoded string'); }
      if ((kind === 0x1e && size !== 1) || (kind === 0x1f && size > 2)) { throw new Error('Invalid BuildConfig null/boolean value'); }
      if (kind === 0x17 && info.type === 'Ljava/lang/String;' && (info.flags & 0x19) === 0x19) {
        if (Object.hasOwn(result, info.name)) { throw new Error('Duplicate BuildConfig field'); }
        result[info.name] = dexString(dex, value);
      }
    }
    return result;
  }
  return null;
}

function quoted(value) {
  try { return JSON.parse(value); } catch { throw new Error('Invalid compiled XML string'); }
}
function xmlValue(text) {
  if (text.startsWith('"')) {
    const match = /^"(?:[^"\\]|\\.)*"/.exec(text);
    if (!match) { throw new Error('Invalid compiled XML attribute'); }
    return quoted(match[0]);
  }
  if (/^\(type\s+0x12\)0x(?:0|ffffffff)\b/i.test(text)) { return /0xffffffff\b/i.test(text); }
  if (/^\(type\s+0x1[01]\)0x[a-f0-9]+\b/i.test(text)) { return Number.parseInt(text.split(')0x')[1], 16); }
  if (/^true(?:\s|$)/.test(text)) { return true; }
  if (/^false(?:\s|$)/.test(text)) { return false; }
  const reference = /^@0x([a-f0-9]{8})(?:\s|$)/i.exec(text);
  if (reference) { return `@${reference[1].toLowerCase()}`; }
  if (/^-?\d+(?:\s|$)/.test(text)) { return Number.parseInt(text, 10); }
  if (/^0x[a-f0-9]+(?:\s|$)/i.test(text)) { return Number.parseInt(text.slice(2), 16); }
  if (typeof text !== 'string' || text.trim().length === 0) { throw new Error('Missing compiled XML text'); }
  return text;
}

/** Parse SDK aapt2 dump xmltree, which reads the APK's binary XML. */
function parseXmlTree(text) {
  const roots = [];
  const stack = [];
  for (const line of text.split(/\r?\n/)) {
    const element = /^(\s*)E: ([\w-]+)(?:\s|$)/.exec(line);
    if (element) {
      const indent = element[1].length;
      while (stack.length && stack[stack.length - 1].indent >= indent) { stack.pop(); }
      const node = { name: element[2], attributes: {}, children: [], text: '', indent };
      (stack.length ? stack[stack.length - 1].children : roots).push(node);
      stack.push(node);
      continue;
    }
    const attribute = /^\s*A: (?:http:\/\/schemas\.android\.com\/apk\/res\/android:)?([\w.-]+)(?:\(0x[a-f0-9]+\))?=(.+)$/i.exec(line);
    if (attribute && stack.length) {
      const node = stack[stack.length - 1];
      if (Object.hasOwn(node.attributes, attribute[1])) { throw new Error('Duplicate compiled XML attribute'); }
      node.attributes[attribute[1]] = xmlValue(attribute[2]);
    }
    const content = /^\s*T: (.+)$/.exec(line);
    const cdata = /^\s*C: (.+)$/.exec(line);
    if ((content || cdata) && stack.length) {
      const value = (content || cdata)[1].replace(/^CDATA\s+/, '');
      stack[stack.length - 1].text += value.startsWith('"') ? xmlValue(value)
        : value.startsWith("'") && value.endsWith("'") ? value.slice(1, -1) : value;
    }
  }
  if (roots.length !== 1) { throw new Error('Compiled XML must have one root'); }
  return roots[0];
}

/** Keep only the resource values used by the gate; never return Firebase credentials. */
function parseResourceTable(text) {
  const allowed = new Set(['app_name', 'build_config_package', ...MARKER_NAMES, 'network_security_config']);
  const resources = new Map();
  let selected = null;
  let config = null;
  let firebaseAppIdPresent = false;
  let firebaseApiKeyPresent = false;
  let firebaseKey = null;
  for (const line of text.split(/\r?\n/)) {
    const resource = /^\s*resource 0x([a-f0-9]{8}) (?:[\w.]+:)?([\w-]+)\/([^\s:]+)(?:\s|$)/i.exec(line);
    if (resource) {
      const [, id, type, name] = resource;
      selected = allowed.has(name) && ['string', 'xml'].includes(type) ? { id: id.toLowerCase(), type, name, values: [] } : null;
      if (selected) { resources.set(selected.id, selected); }
      firebaseKey = name === 'google_app_id' ? 'app' : name === 'google_api_key' ? 'key' : null;
      config = null;
      continue;
    }
    const inline = /^\s*\(([^)]*)\)\s+(.*)$/.exec(line);
    if (inline) { config = inline[1]; }
    const payload = inline ? inline[2] : line.trim();
    if (firebaseKey && /^(?:"[^"\r\n]+"|\(string\)\s+"[^"\r\n]+")/.test(payload)) {
      if (firebaseKey === 'app') { firebaseAppIdPresent = true; }
      else { firebaseApiKeyPresent = true; }
    }
    if (!selected || config === null) { continue; }
    const string = /^(?:\(string\)\s*)?("(?:[^"\\]|\\.)*")/.exec(payload);
    const file = /^\(file\)\s+([^\s]+)(?:\s|$)/.exec(payload);
    if (string) { selected.values.push({ config, value: quoted(string[1]) }); }
    else if (file) { selected.values.push({ config, value: file[1] }); }
  }
  return { resources, firebaseAppIdPresent, firebaseApiKeyPresent };
}

function resourceFor(table, reference, type, name) {
  if (typeof reference !== 'string' || !/^@[a-f0-9]{8}$/.test(reference)) { throw new Error(`Compiled ${name} resource reference is missing`); }
  const resource = table.resources.get(reference.slice(1));
  if (!resource || resource.type !== type || resource.name !== name || resource.values.length === 0) {
    throw new Error(`Compiled ${name} resource is missing`);
  }
  return resource;
}
function namedResource(table, name) {
  const matches = [...table.resources.values()].filter(resource => resource.type === 'string' && resource.name === name);
  if (matches.length !== 1 || !matches[0].values.length) { throw new Error(`Compiled ${name} string is missing or ambiguous`); }
  return matches[0];
}
function requireValues(resource, expected) {
  if (!resource.values.some(value => value.config === '') || resource.values.some(value => value.value !== expected)) {
    throw new Error(`Compiled ${resource.name} string does not match the native profile`);
  }
}

function profileContract(profile) {
  if (!['release', 'lanTestRelease'].includes(profile)) { throw new Error('Release profile must be release or lanTestRelease'); }
  const lan = profile === 'lanTestRelease';
  return { lan, packageName: NAMESPACE + (lan ? '.lantest' : ''),
    markers: { SYNAPSE_BUILD_PROFILE: lan ? 'lan-test' : 'production', SYNAPSE_ALLOW_LAN_HTTP: lan ? 'true' : 'false' } };
}
function descendants(node) { return [node, ...node.children.flatMap(descendants)]; }
function verifyCompiledManifest(tree, table, profile) {
  const contract = profileContract(profile);
  if (tree.name !== 'manifest' || tree.attributes.package !== contract.packageName) { throw new Error('Compiled manifest package does not match release profile'); }
  const apps = tree.children.filter(node => node.name === 'application');
  if (apps.length !== 1) { throw new Error('Compiled manifest application is missing or ambiguous'); }
  const app = apps[0];
  if (app.attributes.debuggable !== undefined && app.attributes.debuggable !== false && app.attributes.debuggable !== 0) {
    throw new Error('Release APK is debuggable');
  }
  if (app.attributes.usesCleartextTraffic !== false && app.attributes.usesCleartextTraffic !== 0) { throw new Error('Release manifest must default-deny cleartext'); }
  if (app.attributes.name !== `${NAMESPACE}.MainApplication`) { throw new Error('Native application entry point is missing'); }
  if (contract.lan) {
    if (typeof tree.attributes.versionName !== 'string' || !tree.attributes.versionName.endsWith('-lan-test')) { throw new Error('LAN versionName suffix is missing'); }
    requireValues(resourceFor(table, app.attributes.label, 'string', 'app_name'), 'Synapse LAN Test');
  } else if (typeof tree.attributes.versionName === 'string' && tree.attributes.versionName.endsWith('-lan-test')) {
    throw new Error('Production APK carries a LAN version suffix');
  }
  for (const [name, value] of Object.entries(contract.markers)) { requireValues(namedResource(table, name), value); }
  requireValues(namedResource(table, 'build_config_package'), NAMESPACE);
  const config = resourceFor(table, app.attributes.networkSecurityConfig, 'xml', 'network_security_config');
  if (config.values.some(value => value.value !== config.values[0].value)) { throw new Error('Qualified network security configs are not supported by the release gate'); }
  const filename = config.values[0].value;
  if (!/^res\/[^\s.][\w./-]*\.xml$/.test(filename) || filename.split('/').includes('..')) { throw new Error('Invalid compiled network security XML path'); }
  const nodes = descendants(app);
  if (!nodes.some(node => node.name === 'service' && node.attributes.name === 'io.invertase.firebase.messaging.ReactNativeFirebaseMessagingService')
      || !nodes.some(node => node.name === 'provider' && node.attributes.name === 'com.google.firebase.provider.FirebaseInitProvider')
      || !table.firebaseAppIdPresent || !table.firebaseApiKeyPresent) {
    throw new Error('Compiled Firebase initialization or messaging configuration is missing');
  }
  return { networkXml: filename, packageName: contract.packageName };
}

function verifyNetworkConfig(tree, profile) {
  const { lan } = profileContract(profile);
  if (tree.name !== 'network-security-config' || Object.keys(tree.attributes).length || tree.text.trim()) { throw new Error('Invalid compiled network security root'); }
  const bases = tree.children.filter(node => node.name === 'base-config');
  const domains = tree.children.filter(node => node.name === 'domain-config');
  if (bases.length !== 1 || tree.children.length !== 1 + domains.length) { throw new Error('Unexpected compiled network security blocks'); }
  const base = bases[0];
  if (base.attributes.cleartextTrafficPermitted !== false || Object.keys(base.attributes).length !== 1 || base.children.length !== 1
      || base.children[0].name !== 'trust-anchors' || base.children[0].children.length !== 1) { throw new Error('Network security base must deny cleartext and retain system trust'); }
  const anchors = base.children[0];
  const cert = anchors.children[0];
  if (Object.keys(anchors.attributes).length || cert.name !== 'certificates' || cert.attributes.src !== 'system'
      || Object.keys(cert.attributes).length !== 1 || cert.children.length) { throw new Error('Only system HTTPS trust anchors are allowed'); }
  if (!lan) {
    if (domains.length) { throw new Error('Production network config must not permit LAN cleartext'); }
    return { cleartextHosts: [] };
  }
  if (domains.length !== 1) { throw new Error('LAN must contain one exact-IP domain config'); }
  const domainConfig = domains[0];
  if (domainConfig.attributes.cleartextTrafficPermitted !== true || Object.keys(domainConfig.attributes).length !== 1
      || domainConfig.children.length !== 2) { throw new Error('LAN cleartext exceptions must be the two exact IPs'); }
  const hosts = domainConfig.children.map(node => {
    if (node.name !== 'domain' || node.attributes.includeSubdomains !== false || Object.keys(node.attributes).length !== 1
        || node.children.length) { throw new Error('LAN cleartext exception is not an exact domain'); }
    return node.text.trim();
  }).sort();
  if (hosts.join(',') !== '172.16.153.237,192.168.1.11') { throw new Error('LAN cleartext exceptions must be the two exact IPs'); }
  return { cleartextHosts: hosts };
}

/** Existence/format only: native compiled fields, not these strings, authorize LAN. */
function verifyBundle(bundle, profile) {
  if (!bundle || bundle.length < 256) { throw new Error('Release APK is missing a real bundled JS/Hermes asset'); }
  const hermes = bundle.length >= 16 && bundle.readBigUInt64LE(0) === 0x1f1903c103bc1fc6n;
  const text = bundle.toString('utf8');
  if (!hermes && !/(?:__d\(|__r\(|__BUNDLE_START_TIME__|__METRO_GLOBAL_PREFIX__)/.test(text)) { throw new Error('Asset is not a recognized Metro JS or Hermes bundle'); }
  const required = [...MARKER_NAMES, ...(profileContract(profile).lan ? LAN_ORIGINS : [])];
  if (required.some(value => !bundle.includes(Buffer.from(value)))) { throw new Error('Bundled runtime is missing native-marker references or fixed LAN API origins'); }
  return { format: hermes ? 'hermes' : 'metro-js', bytes: bundle.length };
}

function verifyBundleBytecode(bundle, hermesc, spawn = spawnSync) {
  const root = mkdtempSync(path.join(tmpdir(), 'synapse-hermes-inspect-'));
  try {
    const filename = path.join(root, 'index.android.bundle');
    writeFileSync(filename, bundle);
    // Hermes disassembles trusted build tooling's bytecode; it does not run the JS.
    // Compiling text-only Metro code validates syntax without evaluating app code.
    const hermes = bundle.length >= 8 && bundle.readBigUInt64LE(0) === 0x1f1903c103bc1fc6n;
    const args = hermes ? ['-b', '-dump-bytecode', filename]
      : ['-emit-binary', '-out', path.join(root, 'validated.hbc'), filename];
    runInspection(hermesc, args, spawn);
  } finally { rmSync(root, { recursive: true, force: true }); }
}

function verifySignatureOutput(text, expectedFingerprint, rejectedFingerprints = []) {
  const expected = normalizeFingerprint(expectedFingerprint);
  if (!expected) { throw new Error('An expected public signer SHA-256 fingerprint is required'); }
  if (!/^Verified using v[23](?:\.\d+)? scheme[^\r\n]*:\s*true\s*$/im.test(text)) {
    throw new Error('APK must verify with a modern APK signing scheme');
  }
  // SDK 37 uses scheme-qualified labels such as "V2 Signer:"; older tools
  // use "Signer #1". Both formats must identify exactly one APK certificate.
  const signerLabel = '(?:Signer #[1-9]\\d*|V[23](?:\\.\\d+)? Signer(?: #[1-9]\\d*)?):?';
  const counts = [...text.matchAll(/^Number of signers:[ \t]*(\d+)[ \t]*\r?$/gmi)];
  const certificates = [...text.matchAll(new RegExp(`^(${signerLabel}) certificate SHA-256 digest:[ \\t]*([a-f0-9:]+)[ \\t]*\\r?$`, 'gmi'))];
  const digests = certificates.map(match => normalizeFingerprint(match[2]));
  if (digests.length !== 1 || !digests[0] || counts.length > 1
      || counts.some(match => match[1] !== '1')
      || (certificates.some(match => /^V/i.test(match[1])) && counts.length !== 1)) {
    throw new Error('APK must have exactly one verified signer SHA-256');
  }
  const subjects = [...text.matchAll(new RegExp(`^(${signerLabel}) certificate DN:[ \\t]*([^\\r\\n]+)\\r?$`, 'gmi'))];
  if (subjects.length !== 1 || subjects[0][1] !== certificates[0][1]) {
    throw new Error('APK signer certificate subject is missing or ambiguous');
  }
  // Android debug certificates can vary per workstation; reject the subject as
  // well as caller-supplied known debug fingerprints, even if it was expected.
  if (/(?:\bCN\s*=\s*Android Debug\b|\bO\s*=\s*Android\b)/i.test(subjects[0][2])
      || rejectedFingerprints.map(normalizeFingerprint).filter(Boolean).includes(digests[0])) {
    throw new Error('Debug signer is forbidden for release artifacts');
  }
  if (digests[0] !== expected) { throw new Error('APK signer does not match the expected public SHA-256'); }
  return { sha256: digests[0] };
}

function runInspection(command, args, spawn = spawnSync) {
  const result = spawn(command, args, { shell: false, windowsHide: true, encoding: 'utf8', timeout: 60000, maxBuffer: 64 * 1024 * 1024 });
  if (result.error || result.status !== 0) { throw new Error('APK inspection tool failed or is unavailable; install nothing automatically and check the existing SDK/JDK'); }
  return result.stdout;
}

function inspectReleaseApk(apkPath, { profile, expectedFingerprint, aapt2, apksignerJar, hermesc, java, spawn = spawnSync } = {}) {
  const contract = profileContract(profile);
  if (!normalizeFingerprint(expectedFingerprint)) { throw new Error('An expected public signer SHA-256 fingerprint is required'); }
  const sdk = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT
    || (process.platform === 'win32' ? path.join(process.env.LOCALAPPDATA || '', 'Android', 'Sdk') : '');
  const tools = process.env.SYNAPSE_ANDROID_BUILD_TOOLS || '37.0.0';
  aapt2 ||= sdk ? path.join(sdk, 'build-tools', tools, process.platform === 'win32' ? 'aapt2.exe' : 'aapt2') : 'aapt2';
  apksignerJar ||= sdk ? path.join(sdk, 'build-tools', tools, 'lib', 'apksigner.jar') : null;
  java ||= process.env.JAVA_HOME ? path.join(process.env.JAVA_HOME, 'bin', process.platform === 'win32' ? 'java.exe' : 'java') : 'java';
  hermesc ||= path.resolve(__dirname, '..', 'node_modules', 'hermes-compiler', 'hermesc',
    process.platform === 'win32' ? 'win64-bin' : process.platform === 'darwin' ? 'osx-bin' : 'linux64-bin',
    process.platform === 'win32' ? 'hermesc.exe' : 'hermesc');
  if (!existsSync(hermesc)) { throw new Error('A provisioned Hermes compiler is required to validate the bundled asset without executing it'); }
  if (!apksignerJar || !existsSync(apksignerJar)) { throw new Error('A provisioned SDK apksigner.jar is required for cryptographic verification'); }
  const filename = path.resolve(apkPath);
  const bytes = readFileSync(filename);
  const packaging = verifyApk(bytes, [...REQUIRED_CLASSES, 'Lcom/humus/synapse/BuildConfig;',
    ...(contract.lan ? ['Lcom/humus/synapse/LanTestOkHttpClientFactory;', 'Lcom/humus/synapse/LanApiRedirectInterceptor;'] : [])]);
  const configs = dexFiles(bytes).map(({ dex }) => buildConfigStrings(dex)).filter(Boolean);
  if (configs.length !== 1) { throw new Error('APK BuildConfig class is missing or ambiguous'); }
  if (configs[0].BUILD_TYPE !== profile || configs[0].APPLICATION_ID !== contract.packageName
      || Object.entries(contract.markers).some(([name, value]) => configs[0][name] !== value)) {
    throw new Error('Compiled BuildConfig string fields do not match the authoritative native profile');
  }
  const signature = verifySignatureOutput(runInspection(java, ['-jar', apksignerJar, 'verify', '--verbose', '--print-certs', filename], spawn), expectedFingerprint);
  const table = parseResourceTable(runInspection(aapt2, ['dump', 'resources', filename], spawn));
  const manifest = verifyCompiledManifest(parseXmlTree(runInspection(aapt2, ['dump', 'xmltree', filename, '--file', 'AndroidManifest.xml'], spawn)), table, profile);
  const network = verifyNetworkConfig(parseXmlTree(runInspection(aapt2, ['dump', 'xmltree', filename, '--file', manifest.networkXml], spawn)), profile);
  const bundles = apkEntries(bytes, name => name === 'assets/index.android.bundle');
  if (bundles.length !== 1) { throw new Error('Release APK must ship assets/index.android.bundle'); }
  const bundle = verifyBundle(bundles[0].bytes, profile);
  verifyBundleBytecode(bundles[0].bytes, hermesc, spawn);
  return { profile, ...packaging, packageName: manifest.packageName, debuggable: false, ...network, bundle, signature };
}

function main(args = process.argv.slice(2)) {
  try {
    if (!args.length) { throw new Error('Usage: node scripts/check-android-apk.cjs <apk> [--profile release|lanTestRelease --expected-cert-sha256 <public SHA-256>]'); }
    const apk = args[0];
    const options = { expectedFingerprint: process.env.SYNAPSE_EXPECTED_CERT_SHA256 };
    const flags = { '--profile': 'profile', '--expected-cert-sha256': 'expectedFingerprint', '--aapt2': 'aapt2', '--apksigner-jar': 'apksignerJar', '--hermesc': 'hermesc' };
    for (let index = 1; index < args.length; index++) {
      if (!flags[args[index]] || !args[index + 1]) { throw new Error('Invalid APK checker options'); }
      options[flags[args[index]]] = args[++index];
    }
    if (args.length > 1 && !options.profile) { throw new Error('Release-only checker options require --profile release or --profile lanTestRelease'); }
    if (options.profile) {
      const result = inspectReleaseApk(apk, options);
      console.log(`Release APK verified: ${result.profile}, ${result.packageName}, bundled ${result.bundle.format}, nondebuggable, ${result.classes} native classes, expected non-debug signer SHA-256 ${result.signature.sha256}.`);
    } else {
      const result = verifyApk(readFileSync(apk));
      console.log(`APK native packaging verified only: ${result.classes} required classes in ${result.dexCount} DEX files. Not a release validation.`);
    }
    return 0;
  } catch (error) {
    console.error(error.message);
    return 1;
  }
}

if (require.main === module) { process.exitCode = main(); }
module.exports = { REQUIRED_CLASSES, LAN_ORIGINS, MARKER_NAMES, apkEntries, classDefinitions, dexFiles, verifyApk,
  buildConfigStrings, parseXmlTree, parseResourceTable, verifyCompiledManifest, verifyNetworkConfig, verifyBundle,
  verifySignatureOutput, verifyBundleBytecode, inspectReleaseApk, main };
