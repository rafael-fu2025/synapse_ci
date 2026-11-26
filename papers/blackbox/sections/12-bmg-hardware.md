# 12 — BMG hardware conditional annex

Every case in this annex is conditional on an approved disposable hardware campaign, `BMG_HARDWARE_ENABLED`, explicit `facilities.hardware.manage` where required, electrical isolation, and the RUNBOOK controls. Device reports and acknowledgements remain claims, not independent proof.

## BM-010 — Provision, command, acknowledge, and report a simulated controller
- Lifecycle: `CONDITIONAL`
- Legacy IDs: `BM-010`
- Changed by: `bea71cf`
- Requirement: Registration must rotate a one-time credential and disable automation; an authorized gated command must bind to exactly one active device, expire safely, be acknowledged as a device claim, and remain distinct from a separately reported run.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: BMG unit detail plus device API.
- Route/API: `POST /facilities/unit/{unit}/device`; `POST /facilities/unit/{unit}/command`; `GET /api/v1/devices/bmg/commands`; `POST /api/v1/devices/bmg/commands/ack`; `POST /api/v1/devices/bmg/runs`.
- Actor/grants: Human BMG administrator with configure/manage/hardware grants; approved simulator using the one-time device token.
- Preconditions: Hardware gate enabled only in the isolated campaign; active unit; no pending command; simulated controller; current schedule version.

**Steps**
1. Register the simulator MAC, capture the credential once, confirm the schedule was disabled, and verify the credential does not reappear after leaving the page.
2. Queue `run_now`, poll commands with the device token, acknowledge it `completed`, then post a distinct valid `run_uid` after the binding timestamp.
3. Inspect command and run histories and repeat the exact acknowledgement and run submission.

- Expected: One active binding exists; command is pending then acknowledged; acknowledgement retry is idempotent; one run fact is stored and duplicate identical run returns acknowledged without another row.
- Negative/non-disclosure: The UI never labels acknowledgement as verified motion; token/hash is not in evidence; conflicting duplicate facts, wrong token, and cross-binding IDs are rejected.
- Cleanup: Disable schedule, queue/confirm stop only under the campaign procedure, revoke the credential, place unit in maintenance, and retire the DB.
- Source: `app/Services/Facilities/BmgRotationService.php:263-310`; `app/Services/Facilities/BmgRotationService.php:332-368`; `app/Services/Facilities/BmgRotationService.php:414-495`
- Evidence: Redacted one-time credential ceremony, command/ack/run statuses, row counts, and safety checklist.
- Verdict: `NOT_RUN`

## BM-011 — Publish a Manila rotation schedule with effective gate separation
- Lifecycle: `CONDITIONAL`
- Legacy IDs: `BM-011`
- Changed by: `bea71cf`
- Requirement: A schedule is versioned intent in Asia/Manila, limited to eight distinct non-overlapping `HH:MM` windows, duration 1–600 seconds, optional PWM 1–255, forward direction, and effective only when all hardware/unit/binding gates hold.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-04`, `UC-BM-05`
- Surface: BMG schedule editor and device schedule API.
- Route/API: `POST /facilities/unit/{unit}/schedule`; `GET /api/v1/devices/bmg/schedule`.
- Actor/grants: BMG administrator with configure/manage/hardware grants; bound simulator token.
- Preconditions: Isolated hardware campaign; active unit; exactly one bound simulator; current schedule version.

**Steps**
1. Save a valid enabled schedule containing two non-overlapping Manila times, duration, and PWM; fetch it through the bound device API.
2. Confirm published and effective fields, version, timezone, sorted times, and forward direction.
3. Turn the hardware gate off or unit to maintenance and refetch, then try duplicate/overlapping times and a stale expected version.

- Expected: Valid intent increments version and becomes effective only while every gate holds; gate loss returns `enabled=false` and no PWM; invalid/stale schedules are rejected.
- Negative/non-disclosure: Saving or fetching a schedule does not claim dispatch, execution, RTC accuracy, or physical safety; another device/tenant receives no schedule.
- Cleanup: Save disabled schedule, cancel pending commands, revoke simulator, and retire the disposable DB.
- Source: `app/Services/Facilities/BmgRotationService.php:212-260`; `app/Services/Facilities/BmgRotationService.php:371-386`; `app/Services/Facilities/BmgRotationService.php:567-600`
- Evidence: Versioned request/response pairs, gate-change comparison, invalid-case responses, and safety checklist.
- Verdict: `NOT_RUN`

## BM-012 — Build and authorize private USB commissioning artifacts
- Lifecycle: `CONDITIONAL`
- Legacy IDs: `BM-012`
- Changed by: `bea71cf`
- Requirement: Commissioning may build only reviewed sources with pinned toolchain/dependencies, motor outputs disabled, private actor/tenant/unit binding, 30-minute retention, and explicit physical-isolation consent; build artifacts do not authorize or perform flashing.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-06`
- Surface: BMG USB workspace.
- Route/API: `GET /facilities/unit/{unit}/usb/setup-status`; `POST /facilities/unit/{unit}/usb/build`; manifest/part routes; `POST /facilities/unit/{unit}/usb/authorize-flash`.
- Actor/grants: Human BMG administrator with `facilities.read`, `facilities.manage`, and `facilities.configure`; separate flash authorization policy applies.
- Preconditions: Isolated commissioning workstation; pinned `arduino-cli`, ESP32 core 3.3.3 and ArduinoJson 7.4.2; maintenance unit, disabled schedule, one binding, no pending command, current credential/version.

**Steps**
1. Verify setup status and reviewed sketch metadata, then submit valid network settings, matching device token, expected version, and explicit physical-isolation/HTTP-or-CA consent.
2. Inspect the private manifest and exactly four sparse parts, hashes, addresses, expiry, and “motor outputs disabled” messaging without executing an upload command.
3. Attempt access as another actor/unit, after expiry, after rebinding/version change, and without consent; then perform only the authorization-record step if the campaign explicitly permits it.

- Expected: A valid private build is actor/tenant/unit-bound and available for 30 minutes; changed conditions fail closed; no source upload, merged image, global catalog, serial port action, or automatic flash occurs.
- Negative/non-disclosure: Wi-Fi password, device token/hash, compiler paths/output, private headers, binaries, and personal data are not captured in committed evidence.
- Cleanup: Delete/expire private build directory, revoke credential, keep schedule disabled, remove campaign flags, and retire DB.
- Source: `app/Controllers/BmgUsbController.php:32-132`; `app/Services/Facilities/BmgBuildService.php:19-119`; `app/Services/Facilities/BmgBuildService.php:184-258`
- Evidence: Sanitized setup/manifest metadata, hash-and-part checklist, negative response log, and campaign authorization record.
- Verdict: `NOT_RUN`

## BM-HW-001 — Device authentication, throttling, and maintenance fail closed
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Every device endpoint must require `X-Device-Token`, use no-store responses, bound IP/token throttles, bounded object input, and a maintenance-mode `503` that performs no device operation.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: BMG device API.
- Route/API: `/api/v1/devices/bmg/*`.
- Actor/grants: Approved simulator; no human browser session authority.
- Preconditions: Isolated device campaign and disposable DB; maintenance flag can be controlled safely.

**Steps**
1. Call schedule, heartbeat, runs, commands, and acknowledgement without a token, with a revoked token, and with an oversized/non-object JSON body.
2. Trigger the documented rate budget with the simulator and record `Retry-After` without stressing any shared service.
3. Enable the disposable maintenance flag and repeat a valid call, then compare all relevant row counts.

- Expected: Missing/revoked credentials return `401`, malformed/oversized payloads return bounded errors, rate excess returns `429`, and maintenance returns `503` plus `Retry-After: 60` with no write.
- Negative/non-disclosure: Error responses do not expose token hashes, SQL, paths, exception chains, another tenant, or whether a guessed device exists.
- Cleanup: Remove the maintenance flag, revoke simulator credentials, and retire DB.
- Source: `app/Controllers/Api/BmgDeviceApiController.php:42-87`; `app/Services/Facilities/BmgRotationService.php:649-663`
- Evidence: Sanitized response matrix, headers, and before/after row counts.
- Verdict: `NOT_RUN`

## BM-HW-002 — Heartbeat keeps omitted telemetry unknown
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Heartbeat must update liveness and only explicitly supplied firmware, RTC, and applied-schedule fields with independent receipt timestamps; omission must retain prior/unknown values and an applied version cannot exceed the published version.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: Device heartbeat and BMG unit detail.
- Route/API: `POST /api/v1/devices/bmg/heartbeat`.
- Actor/grants: Bound approved simulator token.
- Preconditions: Isolated campaign; known published schedule version; telemetry fields initially null or known.

**Steps**
1. Post only `firmware_version`, then inspect the device detail and per-field receipt timestamps.
2. Post only `rtc_valid`, then an empty object, and confirm omitted fields/timestamps remain unchanged while `last_seen_at` advances.
3. Post an applied schedule version greater than the published version.

- Expected: Only supplied fields receive new values/timestamps; empty heartbeat does not infer telemetry; future applied version is rejected without corrupting stored values.
- Negative/non-disclosure: Liveness is not represented as RTC validity, current firmware, applied schedule, motor state, or safety proof.
- Cleanup: Revoke simulator and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:389-411`; `app/Services/Facilities/BmgRotationService.php:547-564`
- Evidence: Redacted heartbeat payloads and chronological device-row snapshots.
- Verdict: `NOT_RUN`

## BM-HW-003 — Run attribution and durable `run_uid` deduplication
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Device run facts must use a bounded unique `run_uid`, allowed reported state, 0–86400 duration, valid nonfuture Manila start time strictly after current binding, and must never be reassigned across bindings.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: Device run-ingest API and unit run history.
- Route/API: `POST /api/v1/devices/bmg/runs`.
- Actor/grants: Bound approved simulator token.
- Preconditions: Isolated campaign; recorded binding timestamp; no real motor required.

**Steps**
1. Submit a valid post-binding reported run and repeat it identically; verify one stored row and `created` then `acknowledged` outcomes.
2. Reuse the same UID with changed facts, a future time, and a timestamp at/before binding.
3. Rebind/re-register the simulator and attempt to reuse a UID from the previous binding.

- Expected: Only the first valid fact creates a row; identical retry is idempotent; conflicting, future, prebinding, and previous-binding submissions return bounded rejection.
- Negative/non-disclosure: Server does not infer authorization or physical truth from the report, and never silently assigns unknown legacy runs to a unit.
- Cleanup: Revoke device and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:414-453`
- Evidence: Sanitized response sequence and unit/device/run row comparison.
- Verdict: `NOT_RUN`

## BM-HW-004 — Pending commands cancel on authority or binding change
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Command delivery and acknowledgement must revalidate hardware gate, active unit, exact current binding, binding cutoff, and unit identity; invalid pending commands must become terminal rather than be delivered later.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: BMG command form and device commands API.
- Route/API: `POST /facilities/unit/{unit}/command`; `GET /api/v1/devices/bmg/commands`; `POST /api/v1/devices/bmg/commands/ack`.
- Actor/grants: Hardware-authorized human plus bound simulator.
- Preconditions: Isolated campaign; one pending `run_now` command.

**Steps**
1. Queue a valid command and verify it is pending for the current device and unit.
2. Before polling, turn hardware gate off, place unit in maintenance, or rotate the device binding in separate fixture variants.
3. Poll and attempt acknowledgement with old/current credentials; inspect command status and notification event.

- Expected: Revalidation cancels the unsafe pending command; it is not returned for execution and cannot be acknowledged as pending; a terminal workflow event is recorded where supported.
- Negative/non-disclosure: No stale command crosses a binding, gate, unit, actor, or tenant boundary; cancellation still is not proof a physical motor stopped.
- Cleanup: Confirm no pending commands, revoke credentials, keep unit maintenance/offline, and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:456-495`; `app/Services/Facilities/BmgRotationService.php:620-646`
- Evidence: Command lifecycle extract, poll/ack responses, and gate/binding timeline.
- Verdict: `NOT_RUN`

## BM-HW-005 — Archive and restore retire all operational bindings
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Archiving with explicit physical-safety acknowledgement must be refused while a batch is active, then disable schedule intent, cancel commands, revoke/unbind every controller, retain history, and restore only as offline with fresh commissioning required.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-03`, `UC-BM-05`
- Surface: BMG archived-unit workflow.
- Route/API: `POST /facilities/unit/{unit}/archive`; `POST /facilities/unit/{unit}/restore`.
- Actor/grants: BMG administrator with configure/manage; hardware campaign controls for any prior device setup.
- Preconditions: Disposable unit with past history, bound simulator, saved schedule, and no active batch for the successful variant.

**Steps**
1. With an active batch or without `retirement_confirmed`, attempt archive and verify rejection.
2. Close the batch, explicitly acknowledge checked physical safety, archive using current version, then try all old device credentials.
3. Open archived view, restore using fresh version, and inspect status, schedule, command, device, and history state.

- Expected: Successful archive retains history but leaves unit offline/deleted, schedule disabled, commands terminal, and devices revoked/unbound; restore is offline and requires a new controller before operation.
- Negative/non-disclosure: The checkbox does not replace lockout/tagout or prove motor state; old credentials and intent never reactivate; another tenant cannot see/restore the unit.
- Cleanup: Re-archive the test unit, remove campaign gates, and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:144-209`; `app/Controllers/FacilitiesController.php:126-139`
- Evidence: Rejection/success transcripts, archived/restored state matrix, old-token failures, and safety acknowledgement record.
- Verdict: `NOT_RUN`
