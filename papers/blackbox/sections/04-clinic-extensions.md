# Clinic extensions, inventory, and role boundaries

## CL-EXT-001 — Signed addendum appends to a sealed encounter
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ADDENDUM.SEALED_ONLY`
- **Surface:** Sealed encounter
- **Route/API:** POST /clinic/encounters/{id}/addendum
- **Actor/grants:** Actor with clinic.encounters.addendum
- **Preconditions:** Synthetic completed encounter; author identity; one open encounter as negative control; non-sensitive addendum text.
**Steps**
1. Append a clinical-note addendum to the completed encounter and reload its transcript.
2. Attempt the same operation against the open encounter and attempt to edit the original sealed assessment.
- **Expected:** A new author/timestamp/type addendum appears without changing original sealed fields; the open-encounter addendum path is rejected.
- **Negative/non-disclosure:** No addendum is backdated into original content, deleted, or accepted from an actor lacking the grant.
- **Cleanup:** Remove/reset synthetic encounters and addenda in disposable teardown.
- **Source:** app/Services/Clinic/EncounterService.php:610-649; app/Controllers/ClinicController.php:403-432
- **Evidence:** Sealed transcript before/after, appended author/type/time, and open-encounter rejection.
- **Verdict:** `NOT_RUN`

## CL-EXT-002 — Delayed lab result is linked as post-visit evidence
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.LAB.POST_VISIT`
- **Surface:** Patient chart and sealed encounter
- **Route/API:** POST /clinic/patients/{personId}/labs; POST /clinic/encounters/{id}/addendum
- **Actor/grants:** Actor with clinic.encounters.addendum
- **Preconditions:** Synthetic patient with sealed encounter; supported lab name/result/unit/reference/collected time; no real health data.
**Steps**
1. Record the synthetic lab result from the patient chart and link/append it to the sealed visit as supported.
2. Reload chart and encounter transcript and compare identifiers/timestamps.
- **Expected:** The lab appears once in patient history and as an append-only delayed result/addendum without reopening the encounter.
- **Negative/non-disclosure:** Future/invalid timestamps, foreign-patient links, and edits to original clinical assessment are rejected.
- **Cleanup:** Delete/reset synthetic lab/addendum fixtures.
- **Source:** app/Config/Routes.php:500-505; app/Controllers/ClinicController.php:566-575
- **Evidence:** Lab form/result and sealed transcript linkage with synthetic identifiers.
- **Verdict:** `NOT_RUN`

## CL-EXT-003 — Enrollment clearance records and certificate stay patient-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.CLEARANCE.WORKFLOW`
- **Surface:** Patient chart and certificate
- **Route/API:** POST /clinic/patients/{personId}/clearances; GET /clinic/clearances/{id}/certificate
- **Actor/grants:** Actor with clinic.clearances.write/read
- **Preconditions:** Synthetic student, required exam/lab fields, clearance outcome, and second patient's clearance sentinel.
**Steps**
1. Record a synthetic enrollment clearance and reload the patient chart.
2. Download the certificate, then request the second patient's certificate under a reduced/foreign context.
- **Expected:** The clearance and certificate reflect the selected synthetic student and authorized outcome.
- **Negative/non-disclosure:** The certificate does not expose another patient, unsupported clinical fields, or a clearance not authorized by current grants.
- **Cleanup:** Remove generated artifact from evidence workspace after hashing/redaction and reset clearance fixture.
- **Source:** app/Config/Routes.php:503-505; app/Database/Seeds/PermissionsAndRolesSeeder.php:30-31
- **Evidence:** Chart row, certificate rendering/hash, and denied foreign/reduced-grant request.
- **Verdict:** `NOT_RUN`

## CL-ISO-001 — Isolation tracker records an authorized case
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ISOLATION.CREATE`
- **Surface:** Clinic isolation tracker
- **Route/API:** GET /clinic/isolation; POST /clinic/isolation/store
- **Actor/grants:** Actor with clinic.isolation.write
- **Preconditions:** Synthetic patient and non-sensitive isolation category/date/status; read-only intern control actor.
**Steps**
1. Create the isolation entry and reload the tracker.
2. Attempt to create another entry as the read-only actor.
- **Expected:** Authorized entry appears tenant-scoped with actor/time; read-only mutation is refused.
- **Negative/non-disclosure:** No foreign tenant or unrelated chart detail is disclosed and no grant is inferred from host access.
- **Cleanup:** Clear/delete the synthetic isolation record using supported workflow/teardown.
- **Source:** app/Config/Routes.php:507-509; app/Controllers/ClinicIsolationController.php:1-88
- **Evidence:** Tracker before/after and read-only actor denial.
- **Verdict:** `NOT_RUN`

## CL-ISO-002 — Isolation clearance preserves history
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ISOLATION.CLEAR`
- **Surface:** Clinic isolation tracker
- **Route/API:** POST /clinic/isolation/{id}/clear
- **Actor/grants:** Actor with clinic.isolation.write
- **Preconditions:** Open synthetic isolation record with original start/actor metadata.
**Steps**
1. Clear the record with the supported action and reason/date.
2. Reload active and history views and attempt a repeated clear.
- **Expected:** The record becomes cleared with closure metadata while original history remains; repeated/stale clear does not duplicate the transition.
- **Negative/non-disclosure:** Clearance does not delete the row, alter another patient's status, or expose private details to read-only actors.
- **Cleanup:** Remove/reset the synthetic isolation record.
- **Source:** app/Config/Routes.php:507-509; app/Controllers/ClinicIsolationController.php:40-88
- **Evidence:** Open/cleared views, transition metadata, and repeated-action response.
- **Verdict:** `NOT_RUN`

## CL-RPT-001 — Clinic summary report obeys period and tenant scope
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.REPORT.SUMMARY`
- **Surface:** Clinic reports
- **Route/API:** GET /clinic/reports
- **Actor/grants:** Actor with clinic.reports.export
- **Preconditions:** Synthetic encounters/dispensations/clearances distributed inside and outside a chosen period; foreign-tenant sentinels.
**Steps**
1. Open the report for the bounded synthetic period and reconcile displayed aggregates.
2. Change the period and submit invalid/foreign selector inputs.
- **Expected:** Counts and categories match same-tenant records in the period and filters remain bounded.
- **Negative/non-disclosure:** No patient-level confidential notes, foreign tenant rows, or unrestricted raw export appears.
- **Cleanup:** Remove report fixtures/reset database.
- **Source:** app/Config/Routes.php:511-512; app/Services/Reporting/ClinicSummaryReportService.php:1-257
- **Evidence:** Report screenshots, fixture-to-aggregate reconciliation, and invalid filter result.
- **Verdict:** `NOT_RUN`

## CL-RPT-002 — Clinic PDF report matches the on-screen summary
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.REPORT.PDF`
- **Surface:** Clinic report export
- **Route/API:** GET /clinic/reports/pdf
- **Actor/grants:** Actor with clinic.reports.export
- **Preconditions:** Same bounded synthetic report period as CL-RPT-001; PDF compiler available in approved test environment.
**Steps**
1. Generate the PDF from the selected report period and record filename/content type/page count.
2. Compare headline aggregates and period labels against the on-screen report.
- **Expected:** A readable PDF is produced with matching period and aggregate values.
- **Negative/non-disclosure:** The PDF contains no patient-level note text, foreign tenant data, formula payload, or server path/error output.
- **Cleanup:** Delete the downloaded PDF after retaining sanitized hash/render evidence.
- **Source:** app/Config/Routes.php:511-512; app/Services/Reporting/ClinicSummaryReportLatex.php:1-179
- **Evidence:** Download headers/hash, rendered PDF pages, and value comparison to the web summary.
- **Verdict:** `NOT_RUN`

## CL-INV-001 — Inventory idempotency replays identical operations only
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.INVENTORY.IDEMPOTENCY`
- **Surface:** Inventory mutations
- **Route/API:** POST inventory receive/adjust/dispense with request key
- **Actor/grants:** Actor with clinic.inventory.write
- **Preconditions:** Synthetic medicine/batch/encounter; fixed valid key; before-counts for operations, movements, dispensations, and stock.
**Steps**
1. Submit a receipt or dispensation, then retry the identical request with the same key.
2. Reuse the key with a changed quantity or actor and reload all ledgers/totals.
- **Expected:** The identical retry returns the original operation without new side effects; changed payload/actor reuse receives a conflict.
- **Negative/non-disclosure:** No duplicate stock movement/dispensation or key value is exposed in UI/log evidence.
- **Cleanup:** Reverse/reset only the single original synthetic operation.
- **Source:** app/Services/Clinic/InventoryService.php:728-799,933-960
- **Evidence:** Original/replay responses, conflict response, row counts, and unchanged totals after replay.
- **Verdict:** `NOT_RUN`

## CL-INV-002 — Quarantine and expiry remove batches from usable stock
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49, 884b9f0
- **Requirement:** `CLINIC.INVENTORY.USABLE_PROJECTION`
- **Surface:** Inventory detail/catalog
- **Route/API:** POST /clinic/inventory/batches/{id}/status; GET /clinic/inventory
- **Actor/grants:** Reader plus manager with clinic.inventory.write
- **Preconditions:** Synthetic medicine with active unexpired, quarantined, near-expiry, and expired batches; fixed current date.
**Steps**
1. Open catalog/detail and record physical, usable, quarantined, expired, near-expiry, and next-expiration values.
2. Quarantine the active batch, then attempt to reactivate an expired batch and dispense from the medicine.
- **Expected:** Projections update from batch state; quarantined/expired stock is not usable, and expired reactivation/dispensing is refused.
- **Negative/non-disclosure:** Physical stock is not silently destroyed and direct cache/total edits cannot override ledger invariants.
- **Cleanup:** Restore eligible test batch status or reset synthetic inventory.
- **Source:** app/Services/Clinic/InventoryService.php:161-228,672-692,845-919
- **Evidence:** Catalog/detail projections before/after, status operation, and denied expired release/dispense.
- **Verdict:** `NOT_RUN`

## CL-INV-003 — Dispensation correction requires manager authority and explicit custody mode
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.INVENTORY.REVERSAL`
- **Surface:** Inventory operation detail
- **Route/API:** POST /clinic/inventory/operations/{id}/reverse
- **Actor/grants:** Clinic inventory manager/write actor; non-manager control actor
- **Preconditions:** Complete synthetic dispensation with intact allocation ledger; one record_only path and one approved restore_unissued fixture; unique keys.
**Steps**
1. As the non-manager, attempt correction; then as the manager record a correction with reason and selected mode.
2. Inspect reversal linkage/movements and attempt a second correction of the same operation.
- **Expected:** Unauthorized correction fails; one append-only reversal links to the original, and stock is restored only when restore_unissued explicitly affirms clinic custody and batches remain active/unexpired.
- **Negative/non-disclosure:** Patient returns are not represented as restore_unissued, original ledger rows are not deleted, and double correction fails.
- **Cleanup:** Reset synthetic operation/stock or retain a balanced correction chain in disposable teardown.
- **Source:** app/Services/Clinic/InventoryService.php:693-727,728-799; app/Config/Routes.php:495-496
- **Evidence:** Authority denial, correction form/result, original/reversal linkage, and stock delta reconciliation.
- **Verdict:** `NOT_RUN`

## CL-RBAC-001 — Physician authority includes sealing but remains grant-driven
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.RBAC.PHYSICIAN`
- **Surface:** Clinic encounter/inventory
- **Route/API:** POST encounter complete/addendum/dispense
- **Actor/grants:** Synthetic clinic_physician with current seeded grants
- **Preconditions:** Decision required to reconcile the 14-role code catalog with older 12-role documentation; physician fixture with open encounter and stock.
**Steps**
1. Sign in as clinic_physician and perform vitals/assessment, dispensation, and encounter sealing using granted actions.
2. Remove one grant through an approved isolated role fixture and repeat the affected action.
- **Expected:** After policy approval, physician actions follow fresh database grants, and removing a grant immediately removes the action/authorization.
- **Negative/non-disclosure:** Role name alone does not bypass a missing grant, tenant scope, inventory idempotency, or sealed-state rules.
- **Cleanup:** Restore the isolated role grants and reset encounter/inventory fixtures.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:139-151; app/Controllers/ClinicController.php:619-645
- **Evidence:** Role/grant snapshot, authorized workflow, revoked-grant denial, and restored fixture record.
- **Verdict:** `NOT_RUN`

## CL-RBAC-002 — Intern cannot seal, add addenda, or write inventory
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.RBAC.INTERN_LIMITS`
- **Surface:** Clinic workstation
- **Route/API:** Representative Clinic POST routes
- **Actor/grants:** Synthetic clinic_intern with current seeded read/create/write subset
- **Preconditions:** Decision required to reconcile role catalog documentation; intern fixture, open encounter, sealed encounter, medicine stock.
**Steps**
1. As clinic_intern, record an allowed open-encounter value and inspect read-only inventory/isolation/clearance views.
2. Attempt encounter completion, post-visit addendum, inventory receipt/dispense, isolation write, and clearance write.
- **Expected:** After policy approval, allowed intern work succeeds while every ungranted high-authority mutation is absent or returns 403 without state change.
- **Negative/non-disclosure:** No superadmin/host fallback or client-supplied permission converts the intern into a sealer/manager.
- **Cleanup:** Reset synthetic clinical records and remove the temporary intern fixture.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:167-175; app/Controllers/ClinicController.php:619-645
- **Evidence:** Allowed action, hidden controls/403 responses for each denied mutation, and before/after no-change proof.
- **Verdict:** `NOT_RUN`
