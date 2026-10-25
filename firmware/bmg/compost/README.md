# BMG temporary commissioning firmware

**Network commissioning only. Not a motor controller release or proof of a physical stop.**
`bmg-commissioning-0.1.0` brings a device online with the actual Humus API. No compile flag, serial input, command, RTC event, schedule or offline path can enable motor output. Keep motor power physically isolated during setup and flashing. Firmware cannot control the pins during reset/bootloader operation, detect stuck driver outputs, or establish electrical safety.

## Private configuration / simplest editor integration

The build/editor integration must generate **private `device_config.h` beside the sketch** from Wi-Fi name, Wi-Fi password, server origin and the newly issued device token. These four declarations are mandatory:

```cpp
static const char WIFI_SSID[] = "...";
static const char WIFI_PASS[] = "...";
static const char API_BASE_URL[] = "https://your-humus-host";
static const char DEVICE_TOKEN[] = "...";
```

`API_BASE_URL` is the origin plus an optional deployment subdirectory, **without a trailing slash or `/api/v1/devices/bmg`**. The sketch appends that API path. The example header is credential-free; missing private configuration deliberately prevents compilation. Generate properly escaped C++ string literals (including quotes, backslashes and non-ASCII Wi-Fi names); reject NUL/control characters rather than interpolating raw form text into C++. Never prefill from the original sketch.

For HTTPS the private header must additionally define `BMG_ROOT_CA_PEM` as a NUL-terminated PEM string containing a reviewed CA for the server. Certificate/hostname checks remain enabled; redirects are disabled. No `setInsecure` or HTTP fallback exists. System SNTP time is requested for TLS certificate dates; HTTPS waits for a plausible system clock. This is **not** DS3231 verification. Restrictive networks need working DNS/NTP or a separately reviewed clock provision; do not bypass TLS validation.

For a trusted local HTTP test only, an explicit user-consent checkbox may inject `#define BMG_ALLOW_HTTP true` **only when the URL scheme is `http://`**. Otherwise omit the define. HTTP exposes Wi-Fi-network traffic, including the device token, to interception; do not use on public/untrusted networks. Header defaults do not authorize HTTP. Invalid URL/TLS policy keeps networking disabled, with outputs LOW.

`device_config.h` and build outputs are locally ignored. Build in an isolated private job directory, not a public/reusable checkout; restrict permissions, keep secrets out of command arguments/logs, and delete the generated header and artifacts after use. The binary embeds Wi-Fi credentials and token: it too is private. NVS is not encrypted by this sketch. Never publish a per-device binary as a shared release.

The USB workspace integrates these reviewed sources through `BmgBuildService`: its guided form generates the private header, compiles the canonical template, and makes a signed, short-lived per-device build available to the existing protected installer. The advanced browser editor exports custom edits locally only; it never sends arbitrary source to the server compiler. See `docs/BMG_USB.md` for setup, deployment and security boundaries.

## Dependencies and board profile

- Espressif Arduino ESP32 core **3.3.3** (`esp32:esp32`).
- **ArduinoJson 7.4.2**, pinned (the sketch rejects other versions).
- WiFi, HTTPClient, WiFiClientSecure, Preferences and mbedTLS come with that core.
- Original RTClib 2.1.4 / Adafruit BusIO 1.17.4 are **not required** here: no RTC/I²C operations are performed.

A build worker with Arduino CLI available can install the pinned dependencies using:

```text
arduino-cli core install esp32:esp32@3.3.3
arduino-cli lib install ArduinoJson@7.4.2
```

Configure the Espressif package index in the worker first if absent. No library or compiler is vendored here. Arduino CLI **1.5.1** was found at `C:/Users/udtoh_lmtzs7k/AppData/Local/Programs/Arduino IDE/resources/app/lib/backend/resources/arduino-cli.exe` (not on PATH). Both synthetic HTTP and HTTPS configurations compiled successfully with the pinned core/library. **No physical-board, live TLS, flash or motor acceptance is claimed.**

The documented board is ESP32-S3 revision 0.2, **16 MB quad flash, 8 MB PSRAM**. Bare `esp32:esp32:esp32s3` defaults to 4 MB flash and is **not an approved release profile**. Core 3.3.3's installed `boards.txt` confirms these available option keys:

- `FlashSize=16M`
- `FlashMode=qio` (QIO 80 MHz; board timing still needs review)
- `PartitionScheme=app3M_fat9M_16MB` or another explicitly reviewed 16 MB layout
- `PSRAM=disabled`: this commissioning-only firmware does not use PSRAM, so deliberately leave it disabled instead of inferring an unknown quad/octal bus from the documented 8 MB capacity.

The exact commissioning **compile profile**, also selected for the parent backend, is:

```text
esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled
```

This profile compiled both synthetic configurations, but is **not physical-board certification**. It deliberately skips unknown PSRAM rather than testing or enabling it. USB/CDC mode, upload method, flash timing and remaining board assumptions still need isolated-board review. `provenance.json` records `compile_fqbn` separately and intentionally leaves `release_fqbn` null. Do not infer PSRAM type, flash hardware to discover it, or present a compile-only artifact as board-accepted. Do not run upload as a smoke test.

## Safety and protocol behavior

- GPIO **6 enable**, **4 IN1**, **5 IN2**: LOW latches loaded before output mode, Serial, delay or Wi-Fi; reasserted throughout the loop and HTTP operations. No PWM, HIGH writes, motor recipe or countdown exists. This removes the unsigned countdown-underflow path instead of preserving it.
- Serial 115200: commissioning warning, chip MAC, concise Wi-Fi/API outcomes, and `?` for read-only status. All other input (including `s`/`r`) is ignored. No Wi-Fi SSID/password, URL, token, headers or response bodies are logged. Keep ESP core debug logging disabled in the release profile.
- All requests use `X-Device-Token`. Successful transport means **200–299 plus bounded valid JSON object**, not a redirect or arbitrary positive status. Responses are capped at 16 KiB, including chunked bodies, with JSON nesting limit 12. Connect/read timeouts are 5 s, TLS handshake timeout 5 s; these are per-phase/inactivity limits, **not an absolute wall-clock budget against a slow-drip hostile server**. Use a trusted endpoint.
- Original-derived nonblocking 15 s Wi-Fi attempts / 30 s reconnect backoff. HTTP failures impose a 30 s global backoff; normal cadences are heartbeat 5 min, schedule 10 min, commands 3.5 s. Unsigned elapsed-time subtraction handles `millis()` rollover. Heartbeat/schedule failures retry on their normal polling cadence, not immediately when the global backoff clears.
- `POST /heartbeat`: only `firmware_version` and, after a disabled schedule is applied as OFF, `applied_schedule_version`. **`rtc_valid` is omitted**, not guessed from network/system time. Humus retains prior RTC telemetry when omitted; consult this firmware version and the RTC report timestamp rather than interpreting stale telemetry as fresh verification.
- `GET /schedule`: direct schedule object (`version`, `enabled`, etc.), not `data`. A disabled schedule with `pwm: null` is accepted as OFF. Enabled/malformed/error schedules clear local schedule state and are never applied; there is no offline scheduling or persisted enabled intent. An omitted applied version on subsequent heartbeats does not clear historical backend telemetry; it must not be treated as current motor authorization.
- `GET /commands`: reads `commands[]` and `command_id`, accepting positive integral JSON IDs or strictly decimal SQL-driver string IDs. The entire batch must be ascending and unique (as returned by Humus); malformed/unordered/oversized batches are ignored.
- `run_now` (and every non-`stop` command) gets `POST /commands/ack` with `status: rejected`, message **`Commissioning firmware: motor outputs disabled`**. No run is executed.
- `stop` holds software outputs LOW and acknowledges `status: completed`, message **`Software outputs held LOW; physical stop not verified`**. Completion means only that software action, not measured motor position, electrical isolation or a physical safety result.
- **No `/runs` reports**: there are no motor execution facts to report.

### Durable acknowledgement deduplication

Preferences namespace **`bmgcommission`**, key `ack`, stores one versioned atomic blob: SHA-256 binding of server origin + newline + token, command high-water ID, pending flag, and stop/rejection decision. No plaintext token/server is stored in this journal. Humus IDs are globally monotonically increasing and its queue is ordered; therefore confirmed older IDs never need eviction or re-reporting, including after reboot. Changing origin or token resets the journal binding; do not cycle tokens back to an older deployment identity or erase NVS expecting deduplication to survive.

The pending decision is persisted **before** sending; the high-water mark is retained after a confirmed `acknowledged` reply with the matching ID. A pending/unconfirmed ACK is retried verbatim after reboot (Humus makes identical ACKs idempotent). This is at-least-once delivery for uncertain results, **not impossible exactly-once HTTP**. Confirmed completed/rejected IDs are not re-reported after reboot. Terminal HTTP 404/409 responses retire a pending ID without claiming that its ACK was accepted, avoiding permanent blockage on expired/cancelled/rebound commands. Other failures retain it. Storage open/format/write failures disable further command acknowledgement; they can never enable outputs. There is no automatic journal erase on corruption.

## Provenance and deferred acceptance

`provenance.json` records the exact fixed original path, raw-byte SHA-256 and inspected hardware constants. Original: **1273 lines**, SHA-256 **`23e65287b0979cb0756a42908c0d8257a7841c43060f0633fe943484ff8dcfb2`**. Configuration lines 33–36 were not printed or copied. This is a new credential-free adaptation, **not a full copy or motor-compatible port**. Attribution covers reusable transport/configuration topology and timings; safety and API changes are explicit above. Original example/source configuration may differ; neither supplies build credentials.

A parent backend must hash the exact fixed source path before trusting this provenance; do not accept a client-supplied path/hash or hash a redacted reconstruction. Do not log source contents. This metadata alone does not implement backend trust enforcement.

I²C source wiring is SDA **8**, SCL **9**, **100 kHz**, DS3231. It is reference information only; this sketch deliberately leaves I²C untouched. Motor rotation, PWM/electrical polarity under fault/reset, RTC validity, rotation feedback, offline execution, full stop semantics and board flash acceptance require a separate reviewed hardware test plan. Successful heartbeat, a visible MAC or a completed software stop is **not motor acceptance**.

## Tests

From the repository root:

```text
python -m unittest discover -s tests/firmware -p "test_*.py" -v
```

Tests enforce credential-free configuration, LOW-only motor writes, API shapes, durable-journal ordering, dependency/provenance and TLS boundaries. `policy_test.cpp` exercises the actual portable policy helpers, including `millis()` rollover and ID boundaries; Python compiles/runs it if a host C++ compiler is available (otherwise explicitly skips it). These are regression guards, not Arduino compiler verification, live API integration or physical safety certification. Both Arduino branches were additionally compiled with core 3.3.3 / ArduinoJson 7.4.2. `tests/firmware/compile_smoke.py --cli <cli-executable> --fqbn <explicit-commissioning-fqbn>` compiles both HTTP and HTTPS branches with `--export-binaries` in temporary directories using synthetic credentials only, requires unused PSRAM to be disabled, rejects generic/4 MB board defaults, and never uploads or reads an existing private header. It reuses the core/library build cache between branches, inventories binaries and public metadata before cleanup, and removes the temporary job on normal exit or handled failure. An externally killed process may leave its ignored synthetic-only job directory for explicit cleanup. Its synthetic CA is intentionally unusable: this checks compilation, not TLS acceptance. A missing CLI/dependency or compiler failure fails that explicit smoke command. The sketch also rejects other ESP core versions at compile time. After compilation, use a reviewed real CA for controlled transport/reboot/NVS fault tests on an isolated board.

### Observed compiler exports / parent packaging work

Actual CLI 1.5.1 / ESP core 3.3.3 `compile --export-binaries --output-dir ...` exports these files, **not** a flash manifest:

| File | HTTP bytes | HTTPS bytes | Format |
| --- | ---: | ---: | --- |
| `compost.ino.bin` | 1008368 | 1008432 | ESP application image |
| `compost.ino.bootloader.bin` | 20224 | 20224 | ESP bootloader image |
| `compost.ino.partitions.bin` | 3072 | 3072 | Binary partition table |
| `compost.ino.merged.bin` | 16777216 | 16777216 | Full 16 MB padded raw merged image |
| `compost.ino.elf`, `compost.ino.map` | varies | varies | Debug/linker artifacts, not flash parts |

Sketch storage usage was 1008215 bytes for HTTP and 1008291 for HTTPS; globals were 44824 bytes for each. These sizes describe the synthetic inputs, not future device builds. The initial combined smoke invocation hit its 240 s outer timeout after HTTP passed; HTTPS was then successfully compiled using the interrupted build cache. Neither branch needed a firmware source fix.

Neither build nor output directory contained `flash_args`, `*.flash_args` or `flasher_args.json`. Build-only `build.options.json` is a JSON object with FQBN, paths and build-option keys, **not offsets/flash files**. `compile_commands.json` is a JSON compilation database array. `partitions.csv` is the partition-layout CSV; `sdkconfig` is ESP-IDF text configuration. None is a standalone flashing recipe.

The installed core's `platform.txt` exposes the recipe as `recipe.hooks.objcopy.postobjcopy.3.pattern_args`; `arduino-cli compile --show-properties=expanded --fqbn ... <synthetic-sketch>` resolves it to esptool `merge-bin` arguments. Its `tools.esptool_py.upload.pattern_args` uses the same four image offsets with `write-flash` (inspected only, **not executed**):

| Offset | Input |
| --- | --- |
| `0x0` | `compost.ino.bootloader.bin` (`build.bootloader_addr`) |
| `0x8000` | `compost.ino.partitions.bin` |
| `0xe000` | Installed core `tools/partitions/boot_app0.bin` (**not exported** with the sketch) |
| `0x10000` | `compost.ino.bin` |

Expanded properties report `build.flash_size=16MB`, `build.flash_freq=80m`, `build.partitions=app3M_fat9M_16MB`, and `build.flash_mode=dio` even though the selected menu is `FlashMode=qio` (the core handles QIO boot configuration separately). Preserve the core's `--flash-mode keep --flash-freq keep --flash-size keep` behavior; do not guess different write flags from the menu name. Internal `build.psram_type=qspi` / `build.memory_type=qio_qspi` defaults are **not physical PSRAM evidence** and do not override explicit `PSRAM=disabled`.

The parent backend's `flash_args`-only parser therefore needs additional support before packaging these exports. Validate a pinned-core recipe/expanded-property schema and all expected files/offsets/sizes/hashes; source `boot_app0.bin` only from the trusted pinned package. Do not execute a recipe via a shell, trust arbitrary emitted paths, or guess offsets from file order. Alternatively, explicitly support and validate the merged binary at offset `0x0`; it covers the entire flash including blank NVS regions, so flashing it can erase the acknowledgement journal and other persistent data. Treat that as a distinct, destructive full-image policy, not as interchangeable with the four sparse parts. No backend parser/service changes are made here.
