/* eslint-env node, es2022 */
'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { deflateRawSync } = require('node:zlib');
const { REQUIRED_CLASSES, classDefinitions, verifyApk } = require('./check-android-apk.cjs');

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
