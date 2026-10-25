# BMG USB commissioning

## Open the workspace

From **Facilities → Drum Rotation → Manage drum → USB installer & serial monitor**.
The protected page is `/facilities/unit/{id}/usb`. Access requires a fresh active
BMG-role account with `facilities.read`, `facilities.manage` and
`facilities.configure`; a cached superadmin role does not replace these grants.
Identification and monitoring work independently of the motor/flash gates.

The workspace now provides a guided **Connect & identify → Configure & build →
Install → Verify network** workflow. Enter Wi-Fi settings and a newly issued device
token in the browser; an enabled local Arduino CLI builds the reviewed, credential-free
network-commissioning adaptation of `zynapse/bmg_iot/compost/compost.ino`.

**This release is network commissioning only: motor outputs are hard-disabled.**
It does not execute rotation schedules, accept motor-run requests, or verify an RTC.
The original sketch uses an older API and unsafe-to-assume boot/serial behavior;
it is not copied unchanged or automatically flashed. No device was connected or
flashed during implementation. Physical board and motor acceptance remain required.

## Simplest setup procedure

1. In **Manage drum**, set the drum to **maintenance**, keep its schedule disabled,
   and register its controller using the detected ROM MAC. Copy the one-time token
   from registration. The server stores only its hash and cannot show it again.
2. Physically isolate motor power. Open the USB workspace and select/identify the
   controller in Step 1; review the separate reset-safety confirmation.
3. In Step 2 enter SSID, Wi-Fi password, a backend address reachable from the ESP32,
   and the current device token. The ESP32 uses 2.4 GHz Wi-Fi. The guided build requires
   an 8–63-byte Wi-Fi password or a 64-character hexadecimal PSK; open Wi-Fi is not
   supported by this form. SSIDs are limited to 32 bytes.
4. Use the server's reachable LAN address, **not `localhost` or `127.0.0.1`**. A
   subdirectory and `index.php` base path are supported, without the appended device
   API path. Plain HTTP requires explicit trusted-LAN acknowledgement; the token is
   unencrypted on that network. HTTPS requires a valid reviewed root CA PEM; no
   insecure certificate bypass is supplied.
5. Click **Compile reviewed network firmware**. It can take nearly three minutes.
   Compilation does not open USB or install firmware. The successful private build
   is selected for Step 3; entered password/token fields are cleared.
6. Explicitly install in Step 3 once existing commissioning grants and gates permit
   it. The same chip/MAC, version, integrity, readback and isolation checks still apply.
   No automatic reboot, erase-all or motor enabling is performed.
7. Review local reset safety while motor power remains isolated. After an explicit
   physical reset, use Step 4 to inspect backend heartbeat/firmware receipt timestamps.
   Historical status is not proof of a newly received heartbeat or motor safety.

The PHP development server normally listens only on localhost. An ESP cannot reach
that binding over Wi-Fi. For a deliberately trusted LAN test, the operator can start
`php spark serve --host 0.0.0.0 --port 8080` and configure Windows Firewall to permit
only the intended private network. **This exposes the authenticated web application
to that network**; do not use it on an untrusted/public network. No listener or firewall
rule is changed automatically. Use a properly configured HTTPS deployment instead
for production.

## Local compiler configuration

The reviewed profile uses ESP32 core **3.3.3**, ArduinoJson **7.4.2**, explicit
ESP32-S3 QIO / 16 MB flash / 16 MB partition layout, and **PSRAM disabled** because
this commissioning image does not need it. It does not infer the actual PSRAM bus.
`app/Config/BmgBuild.php` pins the profile and credential-free source hashes.

```ini
bmgbuild.enabled = true
bmgbuild.cli = 'ABSOLUTE_PATH_TO_ARDUINO_CLI_EXECUTABLE'
bmgbuild.signingKey = 'INDEPENDENT_PRIVATE_RANDOM_SECRET_OF_AT_LEAST_32_BYTES'
bmgbuild.timeoutSeconds = 180
```

Use lowercase **`bmgbuild`** in `.env`. Generate the signing secret using
`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`; never reuse other application
keys. The current local checkout has a private compiler path and generated key
configured. This does not enable `BMG_USB_FLASH_ENABLED`, assign a commissioning
grant, or change `BMG_HARDWARE_ENABLED`.

Only canonical server-side sources are compiled. Requests cannot select a command,
file path, build profile or arbitrary source. The CLI runs without a shell, has a
180-second compile deadline, a disk limit and a single-build lock, and never runs
an upload command. Network/toolchain dependency installation is an administrator
operation, never part of a web request.

Build inputs generate a private configuration header. Temporary sources, headers,
logs and intermediates are removed; the binaries **still contain the credentials**.
At most two current builds per user/unit are retained. Signed private artifacts
expire after 30 minutes and require the same current owner, tenant, unit, bound
device credential, disabled schedule version and commissioning state. They are not
added to the shared release catalog. Cleanup occurs during subsequent builds;
expiry denies access but is not a background secure-erasure guarantee.

The backend derives sparse flash parts from the actual expanded pinned-core upload
recipe, verifies their trusted locations and reuses catalog integrity/range checks.
It never guesses offsets, executes the upload recipe or uses the full merged image,
which could erase NVS. Passwords/tokens are not sent in compiler arguments or logged;
BMG credential/build/download/API requests skip debug-toolbar collection. Restrict
private filesystem access and do not enable upstream request-body logging.

## Advanced local source editor

The collapsed source editor can load reviewed sanitized source, open a local `.ino`,
and export an Arduino project ZIP in the browser. The ZIP includes the edited sketch,
reviewed policy helper, placeholder `device_config.h.example` and instructions.
**Form credentials are not inserted into the editor or ZIP.** The ZIP does contain
the exact editor contents: a local sketch you open or edit may already contain
embedded secrets, so protect and inspect exports before sharing. Copy the example
to a private header in Arduino IDE and configure it yourself for a custom local build.

Editing/uploading source disables guided compilation until reviewed source is reset.
Custom source is **local/export-only**, not compiled on the web server or automatically
approved for installation. Arbitrary C++ can change safety behavior and read build-host
files; exposing a general web compiler would need an independently isolated sandbox
and review flow. Successful compilation is not board or motor safety acceptance.

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

**The shared catalog is empty until an administrator approves a release.** Private
per-device builds are supplied separately by the trusted-template builder above.
No `.ino` is treated as a flashable binary, no legacy credentials are copied, and
there is no arbitrary binary upload endpoint. The builder invokes only its fixed
local compile command without a shell; it never accepts arbitrary source.

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
