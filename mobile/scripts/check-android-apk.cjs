/* eslint-env node, es2022 */
'use strict';

const { readFileSync } = require('node:fs');
const { inflateRawSync } = require('node:zlib');

const REQUIRED_CLASSES = [
  'Lcom/humus/synapse/MainActivity;',
  'Lcom/humus/synapse/MainApplication;',
  'Lcom/humus/synapse/WindowRefreshRatePolicy;',
  'Lcom/humus/synapse/SupportedRefreshRatePolicy;',
  'Lcom/humus/synapse/SupportedRefreshRatePolicy$Mode;',
];
const MAX_DEX_BYTES = 64 * 1024 * 1024;

function range(buffer, offset, size) {
  if (!Number.isSafeInteger(offset) || !Number.isSafeInteger(size)
      || offset < 0 || size < 0 || offset + size > buffer.length) {
    throw new Error('Invalid APK/DEX data range');
  }
}

/** Read only root DEX entries; never extract or execute APK content. */
function dexFiles(apk) {
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
    if (/^classes(?:[2-9]|[1-9]\d+)?\.dex$/.test(name)) {
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
      const dex = method === 0 ? compressed : method === 8
        ? inflateRawSync(compressed, { maxOutputLength: MAX_DEX_BYTES }) : null;
      if (!dex || dex.length !== size) { throw new Error(`Invalid DEX compression/size: ${name}`); }
      files.push({ name, dex });
    }
    offset += entrySize;
  }
  if (offset !== directory + directorySize || files.length === 0) { throw new Error('APK has no valid DEX directory'); }
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

if (require.main === module) {
  try {
    if (process.argv.length !== 3) { throw new Error('Usage: node scripts/check-android-apk.cjs <debug.apk>'); }
    const result = verifyApk(readFileSync(process.argv[2]));
    console.log(`APK native packaging verified: ${result.classes} required classes in ${result.dexCount} DEX files.`);
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}

module.exports = { REQUIRED_CLASSES, classDefinitions, dexFiles, verifyApk };
