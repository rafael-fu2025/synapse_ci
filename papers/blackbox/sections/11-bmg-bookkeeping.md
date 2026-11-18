# 11 — BMG bookkeeping

## BM-001 — Facilities dashboard reports recorded state, not physical state
- Lifecycle: `DRAFT`
- Legacy IDs: `BM-001`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The dashboard must show tenant-scoped unit, device-binding, batch, treated-weight, and seven-day Manila-bucket counts while describing them as recorded application facts; final dashboard wording awaits the wording decision.
- Surface: Facilities web dashboard.
- Route/API: `GET /facilities/dashboard`.
- Actor/grants: Active BMG member with `facilities.read`.
- Preconditions: A disposable tenant has active, maintenance, and offline units, at least one bound device, released and active batches, and records on two Manila business days.

**Steps**
1. Sign in on the dedicated BMG host and open `/facilities/dashboard`; record every displayed counter and the seven-day labels.
2. Compare the display with the disposable database fixture, then change only a recorded unit status or batch row and reload without touching hardware.
3. Confirm the corresponding recorded-state metric changes and that the page makes no claim that a motor is running, stopped, safe, or physically available.

- Expected: Counts are tenant-scoped and match stored rows; active batches include `processing`, `awaiting_output`, and `curing`; treated weight uses released input weight; day labels use Manila business-day buckets.
- Negative/non-disclosure: Another tenant’s rows, device token hashes, patient data, and assertions of physical motor state are absent.
- Cleanup: Restore the changed fixture row or retire the disposable database.
- Source: `app/Controllers/FacilitiesController.php:27-86`; `app/Services/Facilities/BmgRotationService.php:67-88`
- Evidence: Full-page screenshot, fixture-to-counter comparison, and sanitized request/response log.
- Verdict: `NOT_RUN`

## BM-002 — Register a unit with explicit capacity and disabled automation
- Lifecycle: `READY`
- Legacy IDs: `BM-002`
- Changed by: `bea71cf`
- Requirement: Unit creation must require a unique safe code, name, valid explicit drum capacity, and allowed status; it must create a disabled schedule and must not infer capacity or enable automation.
- Surface: Facilities unit registry.
- Route/API: `POST /facilities/units/store` followed by `GET /facilities/unit/{id}`.
- Actor/grants: BMG administrator with `facilities.configure` and `facilities.manage`.
- Preconditions: Disposable tenant; unique test code; no similarly named unit needed.

**Steps**
1. Submit a new unit with a unique code, name, `offline` status, and an exact two-decimal capacity, then open its detail page.
2. Verify the unit is present with the submitted capacity and its schedule is disabled with no bound controller.
3. Submit another create using the same code and one omitting/invalidating capacity.

- Expected: The first request creates one tenant-owned unit and disabled schedule; duplicate code conflicts and invalid/missing capacity is rejected without another row.
- Negative/non-disclosure: Creation does not start a motor, bind a device, invent a capacity, expose credentials, or confirm physical installation.
- Cleanup: Archive the created unit with the required retirement acknowledgement, or retire the disposable database.
- Source: `app/Controllers/FacilitiesController.php:90-115`; `app/Services/Facilities/BmgRotationService.php:97-141`
- Evidence: Before/after registry screenshots, response status log, and sanitized unit/schedule row extract.
- Verdict: `NOT_RUN`

## BM-003 — Start an active batch as bookkeeping only
- Lifecycle: `READY`
- Legacy IDs: `BM-003`
- Changed by: `bea71cf`
- Requirement: Starting a batch on an active unit must store an exact positive input weight not exceeding capacity, assign a generated batch code and `processing` state, and explicitly not actuate a motor.
- Surface: BMG unit detail batch form.
- Route/API: `POST /facilities/unit/{unit}/batch`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: Active test unit with no active batch; known capacity; hardware may remain disabled.

**Steps**
1. Record the unit’s schedule, command count, and run count; submit an input weight within capacity and optional notes.
2. Reopen the unit detail and inspect the new active batch, generated code, exact weight, status, and success message.
3. Confirm schedule, commands, and device-reported runs did not change; try zero, excessive, and over-precision weights on clean fixtures.

- Expected: One `processing` batch is created and audited; invalid weights are rejected; the response says recording a batch does not start the motor.
- Negative/non-disclosure: No command is queued, no motor execution is claimed, no quality grade beyond `unclassified` is inferred, and no cross-tenant unit can be used.
- Cleanup: Cancel the test batch with a reason or release it with controlled values.
- Source: `app/Controllers/FacilitiesController.php:181-186`; `app/Services/Facilities/BmgService.php:17-71`
- Evidence: Batch form/result screenshots, before/after command and run counts, and audit event excerpt.
- Verdict: `NOT_RUN`

## BM-004 — Reject a second active batch under contention
- Lifecycle: `READY`
- Legacy IDs: `BM-004`
- Changed by: `bea71cf`
- Requirement: A unit may have at most one active batch across `processing`, `awaiting_output`, and `curing`, including concurrent submissions.
- Surface: BMG unit detail and mutation response.
- Route/API: `POST /facilities/unit/{unit}/batch`.
- Actor/grants: Two active BMG sessions with `facilities.manage` in the same tenant.
- Preconditions: Active unit without an active batch; two synchronized clients using the disposable database.

**Steps**
1. From both clients submit valid batch-start requests for the same unit as nearly simultaneously as the harness permits.
2. Reload the unit and count active batches; retain both HTTP outcomes.
3. Attempt a third start while the winner remains active.

- Expected: Exactly one batch exists in an active status; competing/subsequent requests return deterministic conflict text that the unit is already running an active batch.
- Negative/non-disclosure: No duplicate active row, partial audit event, command, or cross-tenant disclosure is produced.
- Cleanup: Cancel or release the single winning batch.
- Source: `app/Services/Facilities/BmgService.php:38-69`; `app/Services/Facilities/BmgService.php:196-222`
- Evidence: Timestamped parallel request log, active-row count, and audit-row count.
- Verdict: `NOT_RUN`

## BM-006 — Release a batch without certifying compost or motor state
- Lifecycle: `READY`
- Legacy IDs: `BM-006`
- Changed by: `bea71cf`
- Requirement: Completing an active batch must move it to `released`, record an optional exact output weight not greater than input, retain `unclassified` quality, and leave the unit available for another bookkeeping batch.
- Surface: BMG unit detail completion form.
- Route/API: `POST /facilities/batch/{batch}/release`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: Active test batch with known input weight.

**Steps**
1. Submit a valid output weight and release notes; reopen the unit detail.
2. Verify the batch appears in past batches as `released`, has completion time and `unclassified` grade, and no longer occupies the active slot.
3. On a separate active fixture, submit output greater than input and confirm the batch remains active.

- Expected: Valid release is atomic and audited; invalid output returns validation failure; a new batch can be recorded after valid release.
- Negative/non-disclosure: The response does not certify sanitation, compost quality, regulatory release, physical availability, or motor stop.
- Cleanup: Remove any follow-on test batch and retain only sanitized evidence.
- Source: `app/Controllers/FacilitiesController.php:189-195`; `app/Services/Facilities/BmgService.php:73-87`; `app/Services/Facilities/BmgService.php:126-151`
- Evidence: Before/after batch screenshots, validation response, and sanitized audit excerpt.
- Verdict: `NOT_RUN`

## BM-007 — Reject terminal or stale batch transitions
- Lifecycle: `READY`
- Legacy IDs: `BM-007`
- Changed by: `bea71cf`
- Requirement: Release and cancel operations must accept only a currently active batch and must reject stale, repeated, wrong-unit, or terminal transitions without changing the first terminal result.
- Surface: BMG batch mutation endpoints.
- Route/API: `POST /facilities/batch/{batch}/release`; `POST /facilities/batch/{batch}/cancel`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: One batch that can be completed and a second client holding the same initial page state.

**Steps**
1. Complete the batch from client A and record its terminal values and audit event.
2. From client B submit cancel and release requests against that same batch ID.
3. Reload the batch and compare state, completion metadata, and audit count with the values after step 1.

- Expected: Later transitions return `409` with “Only an active batch can be completed or cancelled”; first terminal values and one terminal audit event remain authoritative.
- Negative/non-disclosure: A stale request cannot overwrite release notes, output weight, completion time, status, or another tenant’s batch.
- Cleanup: None beyond retiring the disposable database.
- Source: `app/Services/Facilities/BmgService.php:126-151`; `app/Controllers/FacilitiesController.php:261-273`
- Evidence: Ordered HTTP transcripts and before/after terminal-row plus audit comparison.
- Verdict: `NOT_RUN`

## BM-008 — Cancel a batch with a required reason
- Lifecycle: `READY`
- Legacy IDs: `BM-008`
- Changed by: `bea71cf`
- Requirement: Cancelling an active batch must require a nonblank bounded reason, transition to `voided`, and free the bookkeeping slot without implying that the drum is physically safe.
- Surface: BMG unit detail cancellation form.
- Route/API: `POST /facilities/batch/{batch}/cancel`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: Active batch on a test unit.

**Steps**
1. Submit blank and over-limit cancellation reasons and verify the active batch remains unchanged.
2. Submit a valid reason, then reopen the unit and inspect the past-batch record and availability of the start form.
3. Compare device heartbeat, command, and run data before and after cancellation.

- Expected: Only the valid request sets `voided`, records the reason as release notes, timestamps completion, appends an audit event, and frees the active slot.
- Negative/non-disclosure: Cancellation does not edit input weight, classify quality, issue a stop command, or prove the motor stopped; the response tells the operator to check the controller separately.
- Cleanup: Archive the disposable unit if it was created solely for this case.
- Source: `app/Controllers/FacilitiesController.php:197-202`; `app/Services/Facilities/BmgService.php:89-97`; `app/Services/Facilities/BmgService.php:126-151`
- Evidence: Validation responses, terminal batch screenshot, and unchanged hardware-fact comparison.
- Verdict: `NOT_RUN`

## BM-009 — Maintenance status disables intent but is not a motor assertion
- Lifecycle: `READY`
- Legacy IDs: `BM-009`
- Changed by: `bea71cf`
- Requirement: Changing a unit away from `active` must persist the configured status, disable saved schedule intent, cancel pending commands, increment version, and state that application availability does not confirm physical motor state.
- Surface: BMG unit settings.
- Route/API: `POST /facilities/unit/{unit}/update`.
- Actor/grants: BMG administrator with `facilities.configure` and `facilities.manage`; hardware grant is not required for safe shutdown intent.
- Preconditions: Active unit with an enabled or configured schedule; use only a simulated device in a gated test campaign.

**Steps**
1. Record unit status, schedule version/intent, and any pending command; submit `maintenance` with the current expected version.
2. Reload the unit and confirm maintenance status, disabled schedule, cleared PWM, incremented version, and terminalized pending command.
3. Submit an edit with the stale version, then return the unit to `active` using the fresh version without enabling its schedule.

- Expected: Maintenance transition and later active transition are audited; stale update conflicts; returning to active does not re-enable automation.
- Negative/non-disclosure: Neither status label confirms a motor stop/start, device liveness, lockout/tagout, or physical safety.
- Cleanup: Leave the simulated unit offline/maintenance with schedule disabled, then retire the disposable database.
- Source: `app/Controllers/FacilitiesController.php:118-123`; `app/Services/Facilities/BmgRotationService.php:97-141`; `app/Services/Facilities/BmgRotationService.php:595-617`
- Evidence: Versioned before/after screenshots, command-state extract, stale-conflict response, and audit excerpt.
- Verdict: `NOT_RUN`

## BM-013 — BMG analytics expose bounded recorded metrics and limitations
- Lifecycle: `DRAFT`
- Legacy IDs: `BM-013`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The current BMG dashboard must report only the implemented tenant-scoped recorded metrics and Manila-bucket batch counts; acceptance wording for availability and dashboard labels remains unresolved.
- Surface: Facilities dashboard analytics cards and chart.
- Route/API: `GET /facilities/dashboard`.
- Actor/grants: BMG member with `facilities.read`.
- Preconditions: Deterministic fixture spanning seven Manila days with released, voided, and active batches plus device-reported runs.

**Steps**
1. Open the dashboard at a fixed Manila clock and record total/active batch, released-input weight, unit-status, bound-device, and daily run-of-batch values.
2. Reconcile each displayed value against the defined query populations, including boundary records around midnight Manila.
3. Search the page for unsupported cancellation-rate, time-in-status, compost-quality, motor-uptime, and rotation-reliability claims.

- Expected: Implemented counts reconcile exactly; missing metrics remain absent rather than being invented or represented as zero; displayed dates align with Manila business days.
- Negative/non-disclosure: Device acknowledgements/runs are not promoted to independent physical reliability proof, and no sanitation, quality, uptime, or certification conclusion is disclosed.
- Cleanup: Retire the deterministic analytics database.
- Source: `app/Controllers/FacilitiesController.php:33-73`; `app/Services/Facilities/BmgRotationService.php:73-88`
- Evidence: Dashboard capture, reproducible metric worksheet, boundary-row extract, and wording review note.
- Verdict: `NOT_RUN`
