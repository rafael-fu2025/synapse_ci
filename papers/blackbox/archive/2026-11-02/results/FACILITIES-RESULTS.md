# Facilities / BMG Console — Black-box results (BM-001 … BM-013)

**Target:** `http://localhost:8080/index.php` (local CodeIgniter app, already running)
**Module:** Facilities / BMG Console (Bioreactor Mechanized Gearing)
**Executed:** 2026-11-01 (Asia/Manila)

## Run context and deviations

- **Browser harness:** the in-app Browser (`mcp_browser`) is a Host-bound capability that the
  runtime did **not** admit to this delegated branch session (the turn's `tools[]` contained no
  `mcp_browser`, and `mcp_invoke`/`tool_search` could not resolve it — the Plugin Skill states the
  capability must appear as `mcp_browser` in the current turn's `tools[]`). Every case below was
  therefore executed against the **same running app through a real browser** (Chromium 1248 driven
  by Playwright 1.64.1 already present in `humus/writable/widget-test-tools/node_modules`), using a
  single tab, real form fills, real clicks and real downloads. No application source, config, `.env`
  or schema was read, no migration was run, nothing was installed.
- **Endpoint probes:** where the UI withholds a control (e.g. a disabled "Request run now" button)
  the same-origin endpoint was exercised from inside the already-signed-in page using the page's own
  CSRF token, so the server's real response could be recorded. These probes are called out
  explicitly in the individual cases.
- **Accounts:** no account was created. The seeded accounts `[REDACTED: synthetic account identifier]`
  ("Facilities operator — BMG operations"), `[REDACTED: synthetic account identifier]` and `[REDACTED: synthetic account identifier]`
  ("BMG administrator — operations, configuration and reporting") all sign in successfully and land
  on `/index.php/facilities/dashboard`; they are listed in the admin console under
  **Employees & Access**. `[REDACTED: synthetic account identifier]` was used only for admin-console/report surfaces.
- **Seed gap:** the BMG console had **no drum units, batches or devices** at the start of the run
  (`ACTIVE DRUM UNITS 0 / 0`, "No drum units registered yet.").

---

### BM-001 — Facilities dashboard

**Verdict:** PASS

**Steps observed:**
1. Signed in as `[REDACTED: synthetic account identifier]` (Facilities operator) → redirected to
   `/index.php/facilities/dashboard`, title "Facilities Dashboard - Synapse Campus Health & Services".
2. Read the dashboard body, the "Drum Units Status & Telemetry" table and the
   "Drum Operational Status" counters.

**Evidence:**
- KPI tiles: `ACTIVE DRUM UNITS 2 / 3` with `1 in maintenance`; `BATCHES PROCESSED 3` with
  `1 active now`; `BOUND IOT CONTROLLERS 2` (`ESP32-S3 hardware`); `ORGANIC WASTE TREATED 120.0 kg`.
- Chart block: `Composting & Batch Processing Activity / Last 7 Days` → `ACTIVE DRUMS 2`,
  `CURRENT BATCHES 1`, `TREATED WASTE 120.0 kg` (bars Oct 26 → Nov 1: `3 2 1 0`).
- Unit states table (`UNIT CODE / DRUM NAME / CAPACITY / SCHEDULE / HARDWARE DEVICE / STATUS`):
  `BB-BM-001 | BB-BM Unit A | 250.00 kg | Disabled | Unbound | Active`,
  `BB-BM-002 | BB-BM Unit B | 180.00 kg | Disabled | [REDACTED: device hardware identifier] | Maintenance`,
  `BB-BM-003 | BB-BM Unit C | 100.00 kg | Disabled | [REDACTED: device hardware identifier] | Active`.
- Rotation status per unit is the `SCHEDULE` column (`Disabled`) and the unit page fact
  `Saved schedule intent / Disabled / 0 daily times · 60 s per run`.
- `Drum Operational Status`: `Total Units 3`, `Active Operational 2`, `Under Maintenance 1`,
  `Idle / Unconfigured 0`.

**Notes:** All three expected elements (unit states, active batches, rotation status) are rendered
on one dashboard. No separate analytics screen exists inside the facilities console — see BM-013.

---

### BM-002 — Register composting unit

**Verdict:** PASS

**Steps observed:**
1. Opened the unit registry (FACILITIES → **Drum Rotation**, `/index.php/facilities`) and clicked
   **Add drum** (the `unitModal`, form `POST /index.php/facilities/units/store`).
2. Filled `Unit code` = `BB-BM-001`, `Drum name` = `BB-BM Unit A`, `Capacity (kg)` = `250`,
   `Availability` = `Active — available` (bd-select), then **Save drum**.

**Evidence:**
- Toast after submit: `Success: Drum created. Automated operation remains disabled.`
- Landed on `/index.php/facilities/unit/3` — `BB-BM-001 · BB-BM Unit A`, facts `Active`,
  `Capacity 250.00 kg`, `Current batch / No active batch`.
- Registry list (`/facilities` → "Current drums") shows the unit as a card:
  `BB-BM-001 / BB-BM Unit A / Active / Capacity 250.00 kg / Device binding: No active device bound /
  Last contact: Not reported / Daily schedule: Saved disabled — No times saved` with a
  `Manage drum` action linking to `/index.php/facilities/unit/3`.
- Dashboard table row `BB-BM-001 | BB-BM Unit A | 250.00 kg | Disabled | Unbound | Active` —
  i.e. the unit is in the registry with an **active service state**.
- Audit ledger: `#145 bmg_rotation.save / bmg_unit #3 / [REDACTED: synthetic account identifier] / Nov 1, 2026 02:49:21 PM`.

**Notes:** The registry is the "Current drums" list on the Drum Rotation workspace
(`/index.php/facilities`); the dashboard's "Drum Units Status & Telemetry" table is the same data.
Two further test drums were created the same way (`BB-BM-002 / BB-BM Unit B`,
`BB-BM-003 / BB-BM Unit C`).

---

### BM-003 — Start batch

**Verdict:** PASS

**Steps observed:**
1. On unit `BB-BM-001` (idle: `Current batch / No active batch`) clicked **Start batch**
   (modal `startBatchModal`, form `POST /index.php/facilities/unit/3/batch`).
2. Entered `Input weight (kg)` = `120`, `Notes (optional)` = `BB-BM test batch start - food waste load`,
   then **Start batch record**.

**Evidence:**
- Batch created: `BATCH-2026-b4888edf609a8a985943`, `Status processing`, `Started 2026-11-01 14:58:18`,
  `Input weight 120.00 kg`, `Creation notes: BB-BM test batch start - food waste load`.
- Unit header fact became `Current batch / BATCH-2026-b4888edf609a8a985943 / 120.00 kg input · processing`.
- Dashboard (as facilities operator) later showed `BATCHES PROCESSED 3 / 1 active now` and
  `CURRENT BATCHES 1`.
- Audit ledger: `#152 bmg.batch.created / bmg_batch #1 / [REDACTED: synthetic account identifier]`.

**Notes:** **Deviation — no waste-category field exists.** The start-batch form exposes exactly two
inputs (`Input weight (kg)`, `Notes (optional)`); a full-text scan of the unit page for
`waste`/`categor` returned 0 matches, so the scenario's "waste category" input is not offered
anywhere in the BMG console. The expected outcome (batch created active on that unit; the unit shows
an active batch) was fully observed.

---

### BM-004 — Second active batch blocked

**Verdict:** PASS

**Steps observed:**
1. With `BATCH-2026-b4888edf609a8a985943` active on unit 3, re-opened the unit page and checked
   the start-batch affordances.
2. Because the UI withheld the control, the same endpoint was submitted from the signed-in page
   using the page's own CSRF token.

**Evidence:**
- UI guard: `#startBatchModal` count = `0`; "Start batch" buttons = `0`; the panel instead shows
  `Complete batch` and `Cancel batch` only.
- Server guard (`POST /index.php/facilities/unit/3/batch` with `input_weight_kg=50`):
  **HTTP 409** → `{"success": false, "message": "Unit is already running an active batch."}`

**Notes:** The rejection is deterministic and matches the expectation — a unit holds at most one
active batch.

---

### BM-005 — Update batch (process log)

**Verdict:** BLOCKED

**Steps observed:**
1. Opened the active batch panel on unit 5 (`BATCH-2026-a7640be887686e81e040`, Status `processing`)
   and dumped every button/input inside the batch card.
2. Scanned the whole unit page for log/update affordances.
3. Probed the plausible batch endpoints for a log entry, and read the audit ledger for batch events.

**Evidence:**
- Active batch card HTML contains **only** two controls:
  `<button … data-bs-target="#completeBatchModal">Complete batch</button>` and
  `<button … data-bs-target="#cancelBatchModal">Cancel batch</button>` — no update, note, log or
  observation entry control; card inputs = none.
- Full-page scan for `observation` / `Add note` / `log entry` / `batchNote` / `process log` /
  `Update batch` → **0 matches** each.
- Endpoint probes (from the signed-in page, app CSRF):
  `POST /index.php/facilities/batch/2/update` → **404** `Can't find a route for 'POST: facilities/batch/2/update'`;
  `POST /index.php/facilities/batch/2/log` → **404** (same shape).
  There is also no batch detail page: `GET /facilities/batch/1` → 404.
- Audit ledger for `entity_type=bmg_batch` lists only lifecycle events:
  `bmg.batch.created`, `bmg.batch.released`, `bmg.batch.cancelled` — **no** update/log event type.

**Notes:** Blocked because the feature is absent: the BMG console has no batch process log and no
way to append an update entry to an active batch (no UI control, no route, no audit event type).
The only free-text on a batch is the creation note and the completion/cancellation note.

---

### BM-006 — Finish batch

**Verdict:** PASS

**Steps observed:**
1. On unit 3 clicked **Complete batch** (modal `completeBatchModal`, form
   `POST /index.php/facilities/batch/1/release`), entered `Output weight (kg)` = `95` and
   `Completion notes (optional)` = `BB-BM compost output collected and weighed`.
2. Confirmed through the app's confirmation modal (`#synapseConfirmModal`,
   `data-confirm-title="Complete batch"`) — the reason input is disabled for this action.

**Evidence:**
- Batch moved to a terminal state. Past-batches row:
  `Status released | Started 2026-11-01 14:58:18 | Completed 2026-11-01 15:13:28 | Input 120.00 kg | Output 95.00 kg | Notes "Creation: BB-BM test batch start - food waste load … Completion / cancellation: BB-BM compost output collected and weighed"`.
- The unit's **Current batch** card is empty again and the **Start batch** button reappears
  (count = 1) — the unit is available again. Dashboard `ACTIVE DRUM UNITS` back to `1 / 1`.
- Audit ledger: `#160 bmg.batch.released / bmg_batch #1 / [REDACTED: synthetic account identifier] / Nov 1, 2026 03:13:28 PM`.

**Notes:** Deviation in wording only — the app's terminal state label is **`released`** (its "Complete
batch" action), not the literal word "finished"; the transition itself is `active → released` and
the unit became available as expected. The app also enforces `Output cannot exceed input`
(field `max=120.00`, helper text "Entered output cannot exceed input weight.").

---

### BM-007 — Invalid status transition

**Verdict:** PASS

**Steps observed:**
1. With batch #2 active on unit 4, tried every archive-style transition on the batch.
2. Cancelled batch #2 through the UI, then re-submitted both terminal transitions on the now-closed batch.

**Evidence:**
- There is **no archive route for batches**: `POST /index.php/facilities/batch/2/archive` → **404**
  `Can't find a route for 'POST: facilities/batch/2/archive'` (no archive control in the UI either;
  only `Archive drum` exists, for the *unit*).
- Invalid transition on the closed batch is rejected with a validation message:
  `POST /index.php/facilities/batch/2/release` → **409**
  `{"success": false, "message": "Only an active batch can be completed or cancelled."}`;
  `POST /index.php/facilities/batch/2/cancel` → **409**, same message.

**Notes:** The expected rule ("only active→finished or active→cancelled accepted") is enforced and
reported by the server. The scenario's literal "archive an active batch" verb does not exist in this
app (it 404s instead of returning a validation message) — the equivalent invalid-transition attempt
(re-completing / re-cancelling) is rejected with the validation message above.

---

### BM-008 — Cancel batch and weight guard

**Verdict:** PASS

**Steps observed:**
1. On unit 4, cancelled the active batch via **Cancel batch**
   (`POST /index.php/facilities/batch/2/cancel`, reason `BB-BM cancellation test - contaminated load`),
   confirmed through `#synapseConfirmModal`.
2. Then attempted to edit the closed batch's weight / waste category.

**Evidence:**
- Toast: `Success: Batch cancelled. Check the controller separately before working on the drum.`
- Past-batches row: `Status voided | Started 2026-11-01 15:46:16 | Completed 2026-11-01 15:46:31 |
  Input 60.00 kg | Output Not recorded | Notes "Creation: BB-BM transition-test batch (food waste) …
  Completion / cancellation: BB-BM cancellation test - contaminated load"`.
- Edit attempt on the closed batch: `POST /index.php/facilities/batch/2/edit`
  (`input_weight_kg=999`, `waste_category=food`) → **404**
  `Can't find a route for 'POST: facilities/batch/2/edit'`.

**Notes:** The batch is cancelled and its category/weight can no longer be edited. Important
deviation: the refusal is **vacuous** — the app exposes no batch-edit route or control at all (the
same 404 occurs for an *active* batch), and there is no `waste_category` field anywhere in the
console, so the scenario's "edit waste category" step is not exercisable in this build.

---

### BM-009 — Maintenance toggle

**Verdict:** PASS

**Steps observed:**
1. On unit 4 (`BB-BM Unit B`) opened **Edit drum** (`#unitModal`, form
   `POST /index.php/facilities/unit/4/update`), set `Availability` = **Maintenance**, **Save drum**.
2. Read the unit page and the dashboard availability counters.
3. Repeated with `Availability` = **Active — available**.

**Evidence:**
- Unit header fact changed `Active` → `Maintenance` → `Active` across the two saves.
- Dashboard `Drum Operational Status` during the interval: `Total Units 2`,
  `Active Operational 1`, **`Under Maintenance 1`**, `Idle / Unconfigured 0`; unit table row
  `BB-BM-002 | BB-BM Unit B | 180.00 kg | Disabled | [REDACTED: device hardware identifier] | Maintenance`.
  After returning it to service the row reads `… | Active` again (later
  `Total Units 3 / Active Operational 2 / Under Maintenance 1` with unit 4 still in maintenance for
  the USB work).
- Both transitions are recorded in the audit ledger with actor and timestamp:
  `#178 bmg_rotation.save / bmg_unit #4 / [REDACTED: synthetic account identifier] / Nov 1, 2026 03:36:54 PM` (→ maintenance)
  and `#179 bmg_rotation.save / bmg_unit #4 / [REDACTED: synthetic account identifier] / Nov 1, 2026 03:38:16 PM` (→ active).

**Notes:** Both transitions are recorded and unit-availability analytics track the interval.

---

### BM-010 — IoT device registration and commands

**Verdict:** BLOCKED

**Steps observed:**
1. As `[REDACTED: synthetic account identifier]` opened **Register device** on unit 4 (form
   `POST /index.php/facilities/unit/4/device`, field `Chip MAC address`,
   placeholder `AA:BB:CC:DD:EE:FF`), entered `AA:BB:CC:DD:EE:FF`, **Issue credential**, confirmed
   via `#synapseConfirmModal` ("Issue device credential").
2. Repeated registration/rotation on unit 5 to capture the issued credential.
3. Tried to issue a rotation command via the UI and via the command endpoint, as BMG admin **and**
   as superadmin.
4. Looked for heartbeat and run reporting.

**Evidence:**
- Device bound: unit header `Device binding / Registered`; device card
  `Chip MAC [REDACTED: device hardware identifier]`, `Last contact / Not reported`, `Firmware / Unknown — no timestamped report`,
  `Device clock / Unknown — no timestamped report`, `Applied schedule version / Unknown — no timestamped report`.
- Credential issued and shown once: `Controller Credential` →
  `Controller provisioning / Device credential issued / Shown once / Device #3 · Drum #5`,
  `Device token` with a 64-hex token rendered on the page (e.g.
  `[REDACTED: one-time device token]`), plus the warning
  "This page does not save the token in browser storage. Navigating away clears it from the page."
  (re-opening `/index.php/facilities/unit/5/device` afterwards correctly returns 404 — one-time display).
- Rotation command: the UI button is **disabled** — `Request run now` and `Request web stop` both
  render as `<button disabled …>` for BMG admin *and* for superadmin; the page shows
  `Hardware control is disabled by the server gate. Run, stop and schedule editing/enabling are unavailable.`
- Command endpoint (signed-in page, app CSRF, `command=run`, `duration_seconds=60`):
  `POST /index.php/facilities/unit/4/command` → **403**
  `{"success": false, "message": "Hardware permission required."}` (identical for superadmin).
- No command row was ever created: `Recent command requests` → `0 shown` /
  `No commands requested yet.`; `Device-reported motor events` → `0 shown` /
  `No motor events reported yet.`; admin console carries a notification
  "BMG contact overdue — An active controller has not co…".
- The USB workspace's own heartbeat endpoint `GET /index.php/facilities/unit/4/usb/setup-status`
  reports the device side as empty: `"device": {"last_seen_at": null, "firmware_version": null,
  "firmware_reported_at": null}` — no heartbeat and no firmware/telemetry reports exist.

**Notes:** Blocked on the command half of the case: device registration and token issuance work, but
the server hardware gate (`BMG_HARDWARE_ENABLED` off, per the on-screen banner) denies motor
commands to **every** seeded role including superadmin, so no command can be queued, acknowledged, or
run-reported. There is also no heartbeat/run-report endpoint
(`POST /facilities/unit/4/heartbeat` and `…/report` → 404); heartbeat is only observable through the
USB workspace's **Check backend heartbeat now** button.

---

### BM-011 — Rotation schedule

**Verdict:** BLOCKED

**Steps observed:**
1. Opened the `Rotation schedule` section of the unit workspace (`#bmg-schedule`) for units 3 and 4.
2. Tried to save a schedule with daily times, through the UI and (since no editor is rendered)
   through the schedule endpoint using the page's own CSRF and `expected_version`.
3. Tried to enable the saved intent and to issue the resulting commands.

**Evidence:**
- The schedule section renders **read-only**: `Daily rotation schedule / UTC+08:00`,
  `Asia/Manila (UTC+08:00). Up to eight distinct daily times; runs must not overlap, including across
  midnight. Saving replaces the schedule and cancels pending commands.`,
  `Saved version: 10 · Saved intent: Disabled`, `No daily times saved. · 60 seconds per run · PWM: Not set`,
  `Editing times, duration, PWM or enabling requires configuration and hardware-control permission with
  the server hardware gate enabled.`
- No schedule form is emitted: the unit page's forms are only
  `…/unit/4/command` (×2), `…/unit/4/update`, `…/unit/4/archive`, `…/unit/4/device`, `…/unit/4/batch`,
  `…/batch/…/release|cancel`, `…/auth/logout`; a "Save schedule" button count = 0.
- Endpoint probes (page CSRF + current `expected_version`):
  `POST /index.php/facilities/unit/3/schedule` with `times[]=07:00` → **403**
  `{"success": false, "message": "Hardware permission required."}` (a control payload with an unknown
  array key is accepted with `200 "Schedule saved."`, proving `times[]` is the recognised daily-times
  field and that the 403 is the permission gate, not a validation error).
  `enabled=1` → **422** `{"success": false, "message": "Enabled schedules need distinct times."}`.
- After every attempt the unit still reports `Saved intent: Disabled` and `No daily times saved.`
- Commands remain impossible (`403 Hardware permission required.`), so no schedule can ever dispatch
  a command and no run can be recorded.

**Notes:** Blocked by the server hardware gate: the schedule editor is not rendered, saving actual
daily times is refused (`Hardware permission required.`) for every role including superadmin, and the
saved intent can never be enabled or dispatched.

---

### BM-012 — USB firmware provisioning

**Verdict:** BLOCKED

**Steps observed:**
1. Opened the provisioning workspace **WebUSB Firmware Commissioning**
   (`/index.php/facilities/unit/4/usb`, title "BMG USB Workspace - Synapse Campus Health & Services"),
   which renders Steps 1–4 (Connect & identify / Configure & build / Install / Verify network).
2. Clicked **Check builder prerequisites** → the page reported
   `Network commissioning compiler ready. Motor outputs are disabled; physical isolation is required.`
   with the reviewed board profile
   `esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled.
   Firmware: bmg-commissioning-0.1.0`.
3. Put unit 4 in maintenance, rotated its controller credential to obtain the current 64-hex device
   token, ticked `Motor power is physically isolated and the work area is safe`, and filled
   SSID `BB-BM-TestNet`, password, device API base URL `[REDACTED: private network endpoint]`, the current device
   token, and `Allow plain HTTP on my trusted LAN`.
4. Clicked **Compile reviewed network firmware** (button became enabled) and waited for the build.
5. Inspected Step 3 (release manifest) and the install authorization step.

**Evidence:**
- App's own status probe `GET /index.php/facilities/unit/4/usb/setup-status` → **200**:
  `{"builder": {"available": true, "message": "Network commissioning compiler ready. Motor outputs are
  disabled; physical isolation is required.", "board_profile": "esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled", "firmware_version": "bmg-commissioning-0.1.0", "motor_outputs_enabled": false}, "device": {"last_seen_at": null, "firmware_version": null, "firmware_reported_at": null}}`
  — i.e. the probe claims the builder is available, while the actual build below does not run.
- Build request `POST /index.php/facilities/unit/4/usb/build` → **HTTP 503**:
  `{"success": false, "message": "Firmware build or USB authorization is unavailable. Check the local compiler prerequisites and reload the workspace."}`
- Build result shown in Step 2:
  `Build rejected: Firmware build or USB authorization is unavailable. Check the local compiler
  prerequisites and reload the workspace. Correct the configuration, then explicitly choose Compile
  to retry. No automatic retry or installation.`
- **No build artifacts and no release manifest were produced**: Step 3 shows
  `Approved firmware release` → `No approved firmware available` (the release `<select>` contains
  exactly one option: `|No approved firmware available`), `Installation progress / No installation started.`
- Authorization step blocked with the explicit prerequisites list:
  `Installation requires explicit commissioning permission and server authorization, independently of
  configuration access.` / `BMG_USB_FLASH_ENABLED must be enabled separately.` /
  `BMG_HARDWARE_ENABLED must be false.` / `The unit must be in maintenance with its saved schedule
  disabled.` / `A bound device is required, and its chip identity must match the identified ESP32.`
  → `Installation unavailable` / `Server USB flashing is disabled. Ask an administrator to review
  BMG_USB_FLASH_ENABLED.` / `Your account requires the explicit facilities.hardware.manage permission
  before flashing.`; the **Install approved firmware** button stays disabled.
- Environment check (read-only, no installs performed): `arduino-cli` is **not on PATH**; the binary
  exists only bundled inside the Arduino IDE at
  `[REDACTED: local user path]\AppData\Local\Programs\Arduino IDE\resources\app\lib\backend\resources\arduino-cli.exe`,
  and the `esp32` board package is present under `%LOCALAPPDATA%\Arduino15\packages`.

**Notes:** Blocked. The compiler prerequisite probe says "ready" but the actual compile is rejected
server-side with "Build rejected: Firmware build or USB authorization is unavailable." — the
`arduino-cli` executable is not reachable from the PHP server process's PATH, and no package was
installed because the task forbids it. Flashing is separately blocked by
`BMG_USB_FLASH_ENABLED`/`facilities.hardware.manage`, so the "authorize flash" step could not be
reached even with a successful build. Steps 1 and 4 are also unusable here: `Identify ESP32`,
`Release USB port`, `Start serial monitor` are all disabled (no WebUSB serial device in this
headless environment) and `Check backend heartbeat now` returns
`No backend status received in this session.`

---

### BM-013 — Batch and rotation analytics

**Verdict:** FAIL

**Steps observed:**
1. Opened **Operational reports** (`/index.php/reports/export`) and selected the **BMG** department in
   the `Department` filter; the date range defaulted to `26/10/2026 – 01/11/2026`
   (`from=2026-10-26`, `to=2026-11-01`, "Up to 31 days").
2. Downloaded **Download CSV data** → `operational_bmg_2026-10-26_2026-11-01.csv` (829 bytes).
3. Downloaded **Download PDF report** → `operational_bmg_2026-10-26_2026-11-01.pdf` (146,853 bytes)
   and read both pages.

**Evidence:**
- CSV content (metrics are per-day, unit column `bmg`):
  `2026-11-01,bmg,bmg_batches_started,1` / `2026-11-01,bmg,bmg_batches_released,1` /
  `2026-11-01,bmg,bmg_batches_voided,0` (all other days 0).
- PDF page 1 — "BMG operational results", `Reporting period: 26 Oct 2026 - 01 Nov 2026`:
  `01 Performance summary` → `Batches started 1 (Average/day: 0.1)`, `Batches released 1
  (Average/day: 0.1)`, `Batches voided 0 (Average/day: 0.0)`; `02 Daily activity` charts for
  batches started / released / voided.
  PDF page 2 — "BMG daily register": `01 Nov 2026 1 1 0`, `Period total 1 1 0`; daily statistics
  `Average/day 0.1`, `Peak/day 1`, `Active days 1 / 7`.
- **The other four expected metric families are absent, and the report explicitly disclaims them:**
  - time in status — not present; only start/completion dates and daily counts.
  - unit availability — not present; the report states
    `A zero count means no matching records; it does not establish service availability.`
    (the dashboard's `Drum Operational Status` counts are a current snapshot, not period analytics).
  - cancellation rate — not present; the report states
    `These are separate lifecycle events, not a cohort or a release-success rate.`
  - rotation reliability shares — not present; the report scope is
    `Batches started by start date; batches released or voided by completion date.` /
    `Lifecycle bookkeeping only: no sensor, sanitation, or quality claims.`
  - There is no other BMG analytics view: the facilities console exposes only
    `/index.php/facilities`, `/index.php/facilities/dashboard`, `/index.php/facilities/teams`,
    `/index.php/facilities/unit/{id}` and `/index.php/facilities/unit/{id}/usb`; the app's global
    search for "bmg" returns only Facilities Dashboard / Drum Rotation / Facilities Teams.

**Notes:** The BMG analytics view exists and is period-aware, but it presents batch counts only.
Because the observed output contradicts the expected content (time in status, unit availability,
cancellation rate and rotation reliability shares are not presented, and the report's own counting
basis rules them out), this case is recorded as FAIL rather than PASS.

---

## Test data created

All records were created through the app's own UI (or its own form endpoints with the page's CSRF
token); no seed script, SQL write, migration or package install was used.

**Drum units** (`/index.php/facilities` → Add drum)
| Unit code | Drum name | Capacity | Status | Device |
| --- | --- | --- | --- | --- |
| BB-BM-001 | BB-BM Unit A | 250.00 kg | Active (unit id 3) | none |
| BB-BM-002 | BB-BM Unit B | 180.00 kg | Maintenance (unit id 4) | [REDACTED: device hardware identifier] |
| BB-BM-003 | BB-BM Unit C | 100.00 kg | Active (unit id 5) | [REDACTED: device hardware identifier] / [REDACTED: device hardware identifier] |

**Batches**
| Batch | Unit | Input | State |
| --- | --- | --- | --- |
| BATCH-2026-b4888edf609a8a985943 (batch #1) | BB-BM-001 | 120.00 kg | `released` (finished, output 95.00 kg) |
| batch #2 | BB-BM-002 | 60.00 kg | `voided` (cancelled) |
| BATCH-2026-a7640be887686e81e040 (batch #3) | BB-BM-003 | 45.00 kg | `processing` (left active) |

**Devices** — `[REDACTED: device hardware identifier]` (unit 4), `[REDACTED: device hardware identifier]` and `[REDACTED: device hardware identifier]` (unit 5);
each registration/rotation issued a new one-time 64-hex device token.

**Schedule** — unit 3's schedule was written several times through
`POST /index.php/facilities/unit/3/schedule` (saved version now 10); it holds no daily times and
`saved intent: Disabled`.

**Downloads** — `operational_bmg_2026-10-26_2026-11-01.csv` and
`operational_bmg_2026-10-26_2026-11-01.pdf` (BMG export for 26 Oct – 01 Nov 2026).

**Accounts** — none created; only the seeded `[REDACTED: synthetic account identifier]`, `[REDACTED: synthetic account identifier]` and
`[REDACTED: synthetic account identifier]` were used.

**Artifacts left in the workspace** (throwaway, outside application source): `humus/writable/bb_*.mjs`
(drivers), `humus/writable/.bb-shots/*.png` (screenshots), `humus/writable/.bb-downloads/*`
(BMG report exports). The device-token file, the browser session profiles and the exported session
state were deleted after the run so no live credential or session cookie remains on disk.

## Summary

| ID | Verdict | One-line reason |
| --- | --- | --- |
| BM-001 | PASS | Facilities dashboard rendered unit states, active-batch counts and per-unit schedule/rotation status for the operator role. |
| BM-002 | PASS | `BB-BM-001 / BB-BM Unit A` created via Add drum and appears in the registry with status `Active`. |
| BM-003 | PASS | Batch `BATCH-2026-b4888edf609a8a985943` created active (`processing`, 120.00 kg) and shown as the unit's current batch — though no waste-category field exists. |
| BM-004 | PASS | Second batch rejected deterministically: HTTP 409 `Unit is already running an active batch.` and the UI withholds the Start batch control. |
| BM-005 | BLOCKED | No batch process-log/update affordance exists (no control, `POST /facilities/batch/{id}/update` → 404, no such audit event). |
| BM-006 | PASS | Batch transitioned to the terminal `released` state with output 95.00 kg and the unit became available again (Start batch reappeared). |
| BM-007 | PASS | Invalid transitions rejected with 409 `Only an active batch can be completed or cancelled.` (a batch archive route does not exist → 404). |
| BM-008 | PASS | Batch cancelled to `voided` and its weight/category can no longer be edited — the edit route 404s (though batch editing is absent entirely). |
| BM-009 | PASS | Active→Maintenance→Active recorded in the audit ledger (#178/#179) and reflected in the dashboard's availability counters. |
| BM-010 | BLOCKED | Device registered with a one-time 64-hex token, but rotation commands return 403 `Hardware permission required.` for every role, so no command/heartbeat/run report exists. |
| BM-011 | BLOCKED | Schedule editor is not rendered and saving daily times returns 403 `Hardware permission required.`; intent stays `Disabled` so no rotation command or run can be dispatched. |
| BM-012 | BLOCKED | Build returned `Build rejected: Firmware build or USB authorization is unavailable.` with no artifacts/manifest, and flashing is gated by `BMG_USB_FLASH_ENABLED` + `facilities.hardware.manage`; `arduino-cli` is not on the server's PATH. |
| BM-013 | FAIL | The BMG period analytics exist but present only batch counts — time in status, unit availability, cancellation rate and rotation reliability shares are absent and explicitly disclaimed by the report. |
