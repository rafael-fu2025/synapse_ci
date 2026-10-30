'use strict';

const { spawnSync } = require('node:child_process');

// The internal publisher (8082) must never be exposed to a device.
const PORTS = [8080, 8081, 8083];

function runAdb(args, spawn = spawnSync) {
  const result = spawn('adb', args, {
    encoding: 'utf8',
    timeout: 10000,
    windowsHide: true,
    shell: false,
  });
  if (result.error) {
    if (result.error.code === 'ENOENT') {
      throw new Error(
        'adb was not found. Add Android SDK platform-tools to PATH, then open a new terminal (adb.exe on Windows).',
      );
    }
    throw new Error(`adb ${args.join(' ')} failed: ${result.error.message}`);
  }
  if (result.status !== 0) {
    const detail = (result.stderr || result.stdout || '').trim();
    throw new Error(
      `adb ${args.join(' ')} failed (exit ${result.status}): ${
        detail || 'check the USB connection and Android SDK installation.'
      }`,
    );
  }
  return result.stdout || '';
}

function parseDevices(output) {
  return output
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(
      line =>
        line &&
        !line.startsWith('List of devices attached') &&
        !line.startsWith('*'),
    )
    .map(line => {
      const [serial, state] = line.split(/\s+/);
      return { serial, state };
    });
}

function requireSingleDevice(spawn = spawnSync) {
  const devices = parseDevices(runAdb(['devices', '-l'], spawn));
  if (devices.length !== 1 || devices[0].state !== 'device') {
    const found = devices.map(({ serial, state }) => `${serial} (${state})`);
    throw new Error(
      'Exactly one authorized Android device/emulator is required. ' +
        `Found: ${found.join(', ') || 'none'}. ` +
        'Enable USB debugging, unlock the device and accept its RSA authorization prompt. ' +
        'Reconnect offline devices; disconnect extra devices or close extra emulators, then retry. ' +
        'Inspect with adb devices -l.',
    );
  }
  return devices[0].serial;
}

function prepareUsb(spawn = spawnSync) {
  const serial = requireSingleDevice(spawn);
  for (const port of PORTS) {
    // Reverse, not forward: Android loopback must reach services on the host.
    runAdb(['-s', serial, 'reverse', `tcp:${port}`, `tcp:${port}`], spawn);
  }
  return serial;
}

function main() {
  try {
    const serial = prepareUsb();
    console.log(
      `Ready: ${serial}; Android localhost ports ${PORTS.join(', ')} reversed to this computer.`,
    );
    return 0;
  } catch (error) {
    console.error(`USB setup failed: ${error.message}`);
    return 1;
  }
}

if (require.main === module) {
  process.exitCode = main();
}

module.exports = {
  PORTS,
  runAdb,
  parseDevices,
  requireSingleDevice,
  prepareUsb,
  main,
};
