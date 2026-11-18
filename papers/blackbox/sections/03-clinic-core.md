# Clinic core workflows

## CL-001 — Clinic dashboard uses the current Manila business day
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-001`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.DASHBOARD.CURRENT_DAY`
- **Surface:** Clinic dashboard
- **Route/API:** GET /clinic
- **Actor/grants:** Clinic member with dashboard/queue/appointment read grants
- **Preconditions:** Synthetic clinic staff; controlled current Manila day with known appointments, tickets, and completed encounters in and outside the day window.
**Steps**
1. Sign in on the Clinic workstation and open /clinic.
2. Compare displayed queue, appointment, and completed-visit counts with the seeded current-day records.
- **Expected:** The dashboard renders current-day operational counts and recent activity for the tenant using the Manila day boundary.
- **Negative/non-disclosure:** No foreign tenant, Guidance case, confidential note, or stale cached role scope appears.
- **Cleanup:** Remove seeded dashboard records/reset clock and clear dashboard cache.
- **Source:** docs/SCHEDULING.md:71-102; app/Controllers/ClinicController.php:1-95
- **Evidence:** Dashboard screenshot with clock, sanitized fixture ledger, and count reconciliation.
- **Verdict:** `NOT_RUN`

## CL-002 — Patient registry search is bounded and tenant-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-002`
- **Changed by:** 884b9f0
- **Requirement:** `CLINIC.PATIENT.SEARCH`
- **Surface:** Patient registry
- **Route/API:** GET /clinic/patients
- **Actor/grants:** Clinic actor with clinic.patients.read
- **Preconditions:** Same-tenant synthetic patients with unique exact ID/name fragments; foreign-tenant sentinel person; pagination threshold exceeded.
**Steps**
1. Search by exact synthetic MIS ID and by a bounded name fragment, then change page.
2. Search for the foreign-tenant sentinel and an invalid/unbounded query.
- **Expected:** Only matching same-tenant patients appear with bounded pagination and supported chart/start actions.
- **Negative/non-disclosure:** No foreign tenant, Guidance notes, password data, or unbounded directory dump is returned.
- **Cleanup:** Delete the synthetic directory fixtures or reset the database.
- **Source:** app/Config/Routes.php:500-505; app/Services/Clinic/PatientDirectoryService.php:1-115
- **Evidence:** Search/result/pagination screenshots and negative foreign sentinel query.
- **Verdict:** `NOT_RUN`

## CL-003 — Patient chart separates authorized clinical histories
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-003`
- **Changed by:** 884b9f0
- **Requirement:** `CLINIC.PATIENT.CHART`
- **Surface:** Patient chart
- **Route/API:** GET /clinic/patients/{personId}
- **Actor/grants:** Clinic actor with clinic.patients.read and encounter read
- **Preconditions:** Synthetic patient with health profile, completed/open encounters, vitals, labs, and another patient's sentinel data.
**Steps**
1. Open the synthetic patient's chart and inspect each available history/profile section.
2. Attempt direct access with the other patient's foreign-tenant or unauthorized identifier under a reduced-grant actor.
- **Expected:** Authorized same-tenant chart sections render for the selected patient with correct histories.
- **Negative/non-disclosure:** Cross-tenant/unauthorized records and Guidance counselling content are absent; chart read does not mutate care state.
- **Cleanup:** Remove synthetic chart records and sign out reduced-grant actors.
- **Source:** app/Config/Routes.php:500-505; app/Controllers/ClinicController.php:472-565
- **Evidence:** Chart section screenshots, source-record reconciliation, and denied access proof.
- **Verdict:** `NOT_RUN`

## CL-004 — Health profile update is permission-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-004`
- **Changed by:** baseline
- **Requirement:** `CLINIC.PATIENT.HEALTH_UPDATE`
- **Surface:** Patient chart
- **Route/API:** POST /clinic/patients/{personId}/health
- **Actor/grants:** Clinic actor with clinic.patients.write
- **Preconditions:** Synthetic patient with original blood type/allergies/conditions/emergency contact captured; read-only clinic intern control actor.
**Steps**
1. Update supported health fields using non-sensitive synthetic values and reload the chart.
2. Submit the same mutation as an actor lacking clinic.patients.write.
- **Expected:** Authorized values persist and the unauthorized mutation is rejected without change.
- **Negative/non-disclosure:** No unsupported field is mass-assigned and no other patient's profile is altered or disclosed.
- **Cleanup:** Restore original health values and clear sessions.
- **Source:** app/Config/Routes.php:500-503; app/Database/Seeds/PermissionsAndRolesSeeder.php:22-23,139-175
- **Evidence:** Before/after chart, permission-denial response, and scoped state proof.
- **Verdict:** `NOT_RUN`

## CL-005 — Encounter start requires a called Clinic ticket
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-005`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.CALLED_TICKET`
- **Surface:** Clinic queue to encounter
- **Route/API:** POST /clinic/encounters/start-for-ticket/{queueEntryId}
- **Actor/grants:** Clinic actor with clinic.encounters.create and queue.manage
- **Preconditions:** Synthetic same-day waiting ticket linked to the correct patient; optional checked-in appointment; no open encounter for the patient.
**Steps**
1. Attempt Start Visit while the ticket is still waiting.
2. Call the ticket, then start the visit and follow the returned encounter link.
- **Expected:** Waiting start is rejected; a called ticket starts exactly one in-progress encounter linked to the patient/ticket (and checked-in appointment when present).
- **Negative/non-disclosure:** A different patient's ticket, non-checked-in linked appointment, direct GET, or duplicate open encounter cannot be used to bypass the called-ticket rule.
- **Cleanup:** Seal/cancel the synthetic visit through supported workflow and resolve its ticket/appointment.
- **Source:** app/Services/Clinic/EncounterService.php:112-203; app/Config/Routes.php:477-481
- **Evidence:** Waiting-state rejection, called queue card, created encounter, and linkage/state evidence.
- **Verdict:** `NOT_RUN`

## CL-006 — Vitals save advances an open encounter
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-006`
- **Changed by:** baseline
- **Requirement:** `CLINIC.ENCOUNTER.VITALS`
- **Surface:** Encounter stepper
- **Route/API:** POST /clinic/encounters/{id}/vitals
- **Actor/grants:** Clinic actor with clinic.encounters.write
- **Preconditions:** Synthetic in-progress encounter; valid boundary-safe BP, pulse, temperature, SpO2, weight, and height values.
**Steps**
1. Enter valid synthetic vitals and save.
2. Reload the encounter and patient vitals history, then attempt an out-of-range value.
- **Expected:** Valid vitals persist with derived BMI/display and the workflow advances; invalid values are rejected without replacing the saved set.
- **Negative/non-disclosure:** No sealed encounter is modified and no unsupported free-form field is accepted.
- **Cleanup:** Complete the synthetic encounter or delete it in disposable teardown.
- **Source:** app/Services/Clinic/EncounterService.php:234-269,446-520; app/Config/Routes.php:468-475
- **Evidence:** Stepper before/after, persisted vitals, calculated display, and validation error evidence.
- **Verdict:** `NOT_RUN`

## CL-007 — Clinical draft survives reload until sealing
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-007`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.DRAFT`
- **Surface:** Encounter stepper
- **Route/API:** POST /clinic/encounters/{id}/draft; POST /clinic/encounters/{id}/assessment
- **Actor/grants:** Clinic actor with clinic.encounters.write
- **Preconditions:** Synthetic open encounter with no final disposition; recognizable but non-sensitive draft complaint/assessment/treatment text.
**Steps**
1. Save the partial complaint/assessment as a draft, leave the page, and reopen the encounter.
2. Update one draft field and verify the newest value without completing the visit.
- **Expected:** The open encounter resumes with the saved draft and preserves the latest valid values.
- **Negative/non-disclosure:** Draft failure does not invent success, duplicate treatment rows, expose other patients, or permit edits after sealing.
- **Cleanup:** Seal the encounter or remove all synthetic draft data in teardown.
- **Source:** app/Services/Clinic/EncounterService.php:446-531; app/Config/Routes.php:470-474
- **Evidence:** Draft save/reopen screenshots and underlying open-state comparison.
- **Verdict:** `NOT_RUN`

## CL-008 — Dispensation deducts usable stock once with ledger allocation
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-008`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.DISPENSE.USABLE_STOCK`
- **Surface:** Encounter medicine step
- **Route/API:** POST /clinic/encounters/{id}/dispense
- **Actor/grants:** Clinic actor with encounter write and clinic.inventory.write
- **Preconditions:** Open synthetic encounter; active medicine; two active unexpired batches with known expiries/stock; unique idempotency key.
**Steps**
1. Dispense a quantity spanning or selecting the earliest-expiring usable batch and confirm the action.
2. Reload encounter and inventory detail, recording dispensation, operation, movements, allocations, and totals.
- **Expected:** The patient dispensation is recorded once, usable batch stock decreases according to allocation order, and the append-only operation/movement ledger reconciles.
- **Negative/non-disclosure:** Expired/quarantined stock is not consumed; direct total_stock editing and duplicate side effects are impossible.
- **Cleanup:** Use the supported correction flow if needed, then remove/reset synthetic medicine and encounter fixtures.
- **Source:** app/Services/Clinic/InventoryService.php:620-655,728-799; app/Services/Clinic/EncounterService.php:323-342
- **Evidence:** Before/after stock, encounter dispensation, operation ID, allocation/movement rows, and idempotency key redacted.
- **Verdict:** `NOT_RUN`

## CL-009 — Insufficient usable stock rejects the entire dispensation
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-009`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.DISPENSE.INSUFFICIENT`
- **Surface:** Encounter medicine step
- **Route/API:** POST /clinic/encounters/{id}/dispense
- **Actor/grants:** Clinic actor with encounter write and inventory write
- **Preconditions:** Open synthetic encounter; medicine with less usable stock than requested, plus expired/quarantined quantities that make physical stock appear larger.
**Steps**
1. Record all batch and ledger totals, then request more than usable stock.
2. Reload the encounter, medicine detail, batches, and operation ledger.
- **Expected:** The request receives a conflict/insufficient-stock result and creates no dispensation, stock movement, or quantity change.
- **Negative/non-disclosure:** Expired/quarantined physical stock is not treated as usable and partial allocation is not committed.
- **Cleanup:** Remove/reset the synthetic medicine and encounter fixtures.
- **Source:** app/Services/Clinic/InventoryService.php:620-655,845-919
- **Evidence:** Error UI/status and before/after zero-delta evidence across dispensation, batches, and ledger.
- **Verdict:** `NOT_RUN`

## CL-010 — Encounter completion seals clinical state
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-010`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.SEAL`
- **Surface:** Encounter completion
- **Route/API:** POST /clinic/encounters/{id}/complete
- **Actor/grants:** Clinic physician/staff/admin with clinic.encounters.seal
- **Preconditions:** In-progress synthetic encounter with required complaint, assessment/disposition, any pending medicine resolved, and optional linked in-consultation ticket.
**Steps**
1. Complete the encounter with an allowed disposition and confirmation.
2. Reopen its detail and attempt a vitals/assessment/draft/completion mutation; inspect linked ticket/appointment.
- **Expected:** The encounter becomes completed or referred with completed_at, linked queue/appointment complete atomically, and mutable clinical steps reject later edits.
- **Negative/non-disclosure:** Completion does not overwrite sealed content, double-increment linked versions, or permit an actor lacking seal authority.
- **Cleanup:** Retain terminal fixture for addendum tests or delete it in disposable teardown.
- **Source:** app/Services/Clinic/EncounterService.php:347-445,568-577; app/Controllers/ClinicController.php:387-401
- **Evidence:** Completion confirmation, sealed detail/status/timestamps, linked states, and post-seal mutation denial.
- **Verdict:** `NOT_RUN`

## CL-011 — Walk-in queue follows called-to-consultation lifecycle
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-011`
- **Changed by:** baseline
- **Requirement:** `CLINIC.QUEUE.WALKIN_LIFECYCLE`
- **Surface:** Clinic queue board
- **Route/API:** POST /clinic/queue/walkin|call/{id}|start/{id}|complete/{id}
- **Actor/grants:** Clinic actor with clinic.queue.manage
- **Preconditions:** Synthetic patient without active/skipped ticket; current Manila day selected; second board session open.
**Steps**
1. Issue a routine walk-in and observe Waiting; call it and observe Called & Arriving.
2. Start consultation, then complete it, checking both board sessions after each transition.
- **Expected:** Ticket transitions waiting → called → in_consultation → completed with original timestamps preserved on idempotent repeats.
- **Negative/non-disclosure:** A patient cannot receive a second active/skipped ticket and later transitions cannot be applied out of order.
- **Cleanup:** Complete and delete/reset the synthetic queue records.
- **Source:** docs/SCHEDULING.md:104-124; app/Config/Routes.php:514-523
- **Evidence:** Board screenshots at each state, ticket timestamp/status ledger, and second-session reconciliation.
- **Verdict:** `NOT_RUN`

## CL-012 — Skip, recall, and no-show obey queue transition rules
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-012`
- **Changed by:** baseline
- **Requirement:** `CLINIC.QUEUE.EXCEPTION_TRANSITIONS`
- **Surface:** Clinic queue board
- **Route/API:** POST /clinic/queue/skip|recall|no-show/{id}
- **Actor/grants:** Clinic actor with clinic.queue.manage
- **Preconditions:** Two called synthetic tickets; fixed clock; one recall within 60 minutes and one after deadline/no-show path.
**Steps**
1. Skip the first called ticket, recall it before skip_until, and verify it returns to waiting.
2. Skip or call the second ticket, advance beyond the allowed window as applicable, and mark no-show; attempt an invalid recall.
- **Expected:** Allowed transitions update the correct columns/timestamps; expired recall is refused and no-show is terminal.
- **Negative/non-disclosure:** skip_until is a recall deadline rather than a minimum wait; completed/no-show tickets cannot be recalled or silently reissued.
- **Cleanup:** Resolve/remove both synthetic tickets and restore the test clock.
- **Source:** docs/SCHEDULING.md:104-124; app/Config/Routes.php:517-523
- **Evidence:** State sequence, controlled-clock timestamps, valid recall, and invalid/terminal transition response.
- **Verdict:** `NOT_RUN`

## CL-013 — Confirmed same-day appointment checks in to one queue ticket
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-013`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.APPOINTMENT.CHECK_IN`
- **Surface:** Clinic appointments
- **Route/API:** POST /clinic/appointments/{id}/confirm; POST /clinic/appointments/check-in/{id}
- **Actor/grants:** Clinic scheduler with appointment write and queue authority
- **Preconditions:** Synthetic requested appointment for today in Manila; eligible staff assignment/slot; no active patient ticket.
**Steps**
1. Confirm the request with provider and exact future interval.
2. Use Check in to Queue and inspect appointment detail and Clinic board; repeat check-in once.
- **Expected:** The appointment moves confirmed → checked_in and exactly one linked waiting ticket is issued; duplicate/stale check-in is rejected or idempotently returns the existing link.
- **Negative/non-disclosure:** An unconfirmed, wrong-day, foreign-patient, or conflicting active-ticket appointment cannot create a queue entry.
- **Cleanup:** Call/start/complete or otherwise resolve the ticket and remove the synthetic appointment/availability.
- **Source:** app/Config/Routes.php:532-548; app/Services/Scheduling/QueueService.php:44-100
- **Evidence:** Request/confirmed/checked-in states, queue ticket linkage, and duplicate-attempt result.
- **Verdict:** `NOT_RUN`

## CL-014 — Inventory receipt and adjustment are append-only operations
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-014`
- **Changed by:** b868a49, 884b9f0
- **Requirement:** `CLINIC.INVENTORY.RECEIVE_ADJUST`
- **Surface:** Clinic inventory
- **Route/API:** POST /clinic/inventory/{id}/receive; POST /clinic/inventory/batches/{id}/adjust
- **Actor/grants:** Clinic actor with clinic.inventory.write
- **Preconditions:** Active synthetic medicine; unexpired unique lot; known initial stock; unique request keys; supported adjustment reason.
**Steps**
1. Receive a quantity into the lot and inspect medicine detail, batch, operation, and movement rows.
2. Apply a supported positive/negative adjustment with reason and inspect the same ledger projections.
- **Expected:** Each action creates one immutable operation plus movements, updates batch remaining quantity, and reconciles physical/summary totals.
- **Negative/non-disclosure:** Stock is never edited directly; expired receipts, unsupported reasons, stale identity changes, and payload-changing key reuse are rejected.
- **Cleanup:** Reverse/offset approved test operations or reset the disposable inventory fixtures.
- **Source:** app/Services/Clinic/InventoryService.php:586-619,656-692,728-799; app/Config/Routes.php:483-498
- **Evidence:** Before/after totals, lot, operation/movement IDs, actor/reason/timestamps, and reconciliation calculation.
- **Verdict:** `NOT_RUN`

## CL-CORE-001 — One patient cannot acquire concurrent open Clinic visits
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.SINGLE_OPEN`
- **Surface:** Queue and encounter start
- **Route/API:** POST /clinic/encounters/start-for-ticket/{id}; POST /clinic/encounters/start-direct/{personId}
- **Actor/grants:** Clinic actor with clinic.encounters.create
- **Preconditions:** Synthetic patient has one open encounter; a second called ticket or direct-start attempt is prepared.
**Steps**
1. Open/resume the existing encounter and record its ID.
2. Attempt to start a second encounter for the same person through the other supported entry path.
- **Expected:** The second start is refused or resolves only to the matching existing visit; no duplicate in-progress encounter is created.
- **Negative/non-disclosure:** A ticket with different linkage cannot be attached to the existing visit and another patient's encounter ID is not leaked.
- **Cleanup:** Seal the original encounter and resolve all synthetic tickets.
- **Source:** app/Services/Clinic/EncounterService.php:112-203; app/Config/Routes.php:477-481
- **Evidence:** Existing encounter, second-start conflict, and database/UI count showing exactly one open visit.
- **Verdict:** `NOT_RUN`
