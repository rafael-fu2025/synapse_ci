# BMG USB commissioning

## Open the workspace

From **Facilities → Drum Rotation → Manage drum → USB installer & serial monitor**.
The protected page is `/facilities/unit/{id}/usb`. Access requires a fresh active
BMG-role account with `facilities.read`, `facilities.manage` and
`facilities.configure`; a cached superadmin role does not replace these grants.
Identification and monitoring work independently of the motor/flash gates.

This is the first-stage USB installer and monitor, **not a code editor or compiler**.
It does not automatically configure Wi-Fi, provision credentials, register a device,
start the motor, or import old firmware. No device was connected or flashed during
implementation. Real hardware acceptance remains required.

## Browser and safety requirements

- Use desktop Chrome or Edge with Web Serial, over HTTPS or localhost. An insecure
  LAN hostname/IP may not qualify as a secure context. Browser/OS policy can also
  disable Web Serial.
- Use a USB data cable. Some boards need a CH343/CP210x/CH340 driver. Close Arduino
  IDE and other terminals holding the same port. Firmware/bootloader access may
  require the board's documented BOOT/RESET procedure.
- **Physically isolate motor power before opening the serial port.** Opening,
  closing, or resetting the controller can change board signals or run its existing
  firmware. Server gates, maintenance, a web stop, and a checkbox do not isolate
  power or independently verify a safe motor state.
- Keep motor power isolated until installation/diagnosis and recovery checks finish.
  Never assume a failed or disconnected flash left bootable firmware.

## Connection and identification

1. Click **Select USB port** and select the intended physical controller. Selection
   requests browser permission only; it does not open/reset the port. Adapter USB
   vendor/product IDs are not an ESP32 identity. There is no background scan or
   automatic reconnection to remembered ports.
2. Confirm physical motor isolation, then click **Identify ESP32** and review the
   separate reset-safety confirmation. Identification enters the bootloader, reads
   chip identity/MAC, and attempts to determine flash capacity.
3. Identification does not upload a flasher stub or write firmware. The port closes
   afterward, without an explicit application reboot request. Physical USB/driver
   behavior can still affect reset signals.
4. The detected MAC is displayed for comparison with device registration. Selecting
   a port does not automatically bind it to the drum. Do not confuse a firmware
   Wi-Fi station MAC with the chip's ROM MAC without verification.

Only an identified **ESP32-S3 with proven 16 MB flash** is accepted by the current
installer profile. Unsupported/unknown chips or flash sizes may be displayed, but
installation remains blocked. The legacy repository documents one such board; that
is not evidence that every connected board has the same wiring or capacity.

## Receive-only serial monitor

Confirm isolation and explicitly start the monitor. It opens at **115200 baud**;
it never opens automatically. Stop monitoring before identification/flashing.
There is no input box, serial writer, command transmission, or reset control.
Legacy firmware's `s`/`r` serial commands can actuate a motor, so they are not exposed.

Output is plain text, bounded to the latest 65,536 characters, in page memory only.
No log upload, storage, automatic download, ANSI/HTML execution, or server logging
of serial output occurs. Output may contain credentials: clear it before sharing.
Page navigation clears the log and releases readers/ports. Unexpected disconnects
require explicit user reconnection.

## Approving firmware

**The catalog is empty intentionally.** No `.ino` is treated as a flashable binary,
no legacy credentials are copied, and there is no arbitrary binary upload endpoint.
The backend never compiles source or invokes a shell/compiler.

An administrator/developer must first build and review firmware that implements
Synapse's actual rotation API and safe boot behavior. Validate the board model,
GPIO/polarity, motor isolation/interlocks, RTC behavior, offline cached schedule,
command replay/expiry, and pin state during boot/reset. Test disconnected motor
hardware before connecting the drum.

Store only reviewed binaries under private deployment storage:
`writable/firmware/bmg/{release-id}/`. This directory is outside the public document
root and excluded from Git. Publish metadata through `app/Config/BmgFirmware.php`.
The app must be served from `public/`; do not expose `writable/` as web content.

Catalog entries:

| Field | Requirement |
| --- | --- |
| `id` | Unique lowercase slug, maximum 80 characters |
| `name` | Human-readable release name, maximum 100 characters |
| `chip_family` | Exactly `ESP32-S3` for the current reviewed profile |
| `flash_size` | Exactly `16MB` |
| `parts` | Between 1 and 32 reviewed parts |
| `parts[].name` | Unique readable part name |
| `parts[].filename` | Relative path inside that release's private directory |
| `parts[].address` | Integer byte offset from the reviewed build's flash layout, aligned to 4096 bytes |
| `parts[].sha256` | Exact lowercase SHA-256 of the deployed binary bytes |
| `parts[].size` | Optional integer size; when provided it must match the file |

Use the build tool's actual flash arguments/partition layout. **Do not guess offsets,
reuse another board's offsets, or publish Wi-Fi passwords/device tokens in shared
firmware.** Filenames, real paths, symlinks/junctions, size bounds, duplicate parts,
hashes, and overlapping sector-erasure ranges are validated. All parts must fit in
16 MiB. Manifest/download requests are authenticated, same-tenant and no-store;
private filesystem paths are never returned.

## Installing an approved release

Installation is deliberately distinct from ordinary device registration. It needs:

- The same configuration access plus an explicit `facilities.hardware.manage` grant.
  The app does not grant this automatically, even to superadmin.
- Private configuration `BMG_USB_FLASH_ENABLED=true`, with
  `BMG_HARDWARE_ENABLED=false`. Both remain false by default.
- A unit in **maintenance**, a saved disabled schedule, current version, one active
  registered controller whose ROM MAC matches, and no unexpired pending commands.
- Physical motor isolation confirmation, selected approved release, successful
  chip/flash identification, and final confirmation.

The browser downloads bounded, protected parts and checks SHA-256 **before any
flash writes**. A CSRF-protected POST then rechecks current authorization/unit state
and records only `bmg_usb.flash_requested`. Returned metadata must match the fetched
manifest. Server authorization is not proof of board compatibility or physical
isolation; the browser performs local USB operations after that point, and an
already-running write cannot be remotely revoked by changing a server grant.

The supported chip post-connect hook configures native USB-OTG RAM transfer limits
before the flasher stub is uploaded. Firmware writes use the approved offsets,
keep flash settings, and never request erase-all. Each part is read back and its
SHA-256 compared before success is displayed. The UI does not claim a physical
rotation test, successful Wi-Fi setup, application boot, or firmware health.

No automatic reboot is requested afterward. Reset/power procedures are explicit
physical operator actions performed only after reviewing isolation. Receive-only
monitoring can inspect the later boot output; server heartbeat verification stays
on the drum page. A serial report alone is not proof of physical rotation.

## Failure and recovery

- Canceling the port picker makes no connection. Unsupported browsers receive clear
  guidance rather than a nonfunctional flashing control.
- Wrong MAC/chip, missing release, stale version, changed permissions, mismatched
  downloads, and authorization failures block writing.
- During operations, duplicate controls/navigation are blocked; page exit warns.
  Browser crashes, forced exits, unplugging and power loss cannot be prevented.
- A write/readback failure can leave partial firmware. Keep motor power isolated,
  inspect the error, and use the board's documented bootloader recovery procedure.
  No automatic reboot, erase-all, or retry is initiated by Synapse.
- Do not resubmit motor commands as a recovery strategy.

## Dependencies and validation

`public/assets/vendor/esptool-js/bundle.js` is the unmodified, integrity-checked
Espressif `esptool-js` **0.7.0** browser module. It is served locally, not from a
CDN. Its provenance, SHA-256 and dependency licenses are in the same directory.
There is no new Composer package or production npm build requirement.

Validation uses disposable SQLite service fixtures, a guarded disposable MariaDB
route suite, and browser mocks plus an actual local ESM-import smoke test. Tests do
not open host serial ports or prove physical hardware behavior. The Windows
symlink test is skipped where Windows denies symlink creation. No database schema
change, main-data write, commissioning grant, or gate activation is needed for the
workspace itself.
