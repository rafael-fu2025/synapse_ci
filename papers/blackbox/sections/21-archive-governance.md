# Archive governance

## ARV-001 — Audit reader inspects cross-module Archive Center in strict read-only mode
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.ACCESS.AUDIT_READONLY`
- **Role:** `audit_reader`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-04`
- **Surface:** System Archive & Retention Center
- **Route/API:** GET /admin/archive; POST /admin/archive/records/archive|restore; POST /admin/archive/holds/place; POST /admin/archive/policies/save
- **Actor/grants:** Active `audit_reader` with `archive.read` and no `archive.manage`, `archive.legal_hold.manage`, or `archive.policies.manage`
- **Preconditions:** Disposable tenant containing archived records across Clinic, Guidance, Facilities, Teams, and Administration plus at least one active legal hold and retention policy.
**Steps**
1. Sign in as `audit_reader` and open `/admin/archive` across Records, Legal Holds, Retention Policies, and Eligible Records tabs.
2. Inspect action controls and submit direct CSRF-valid POST attempts to archive, restore, place hold, and save policy routes.
- **Expected:** Read-only archive, hold, and policy tabs load cross-module metadata with restore/archive/manage controls absent; every direct mutation POST returns HTTP 403 without modifying state.
- **Negative/non-disclosure:** Clinical notes, counselling text, and other confidential entity payloads are masked to non-PHI references (`safeRecordRef` / `safeMetadata`), and no foreign tenant record appears.
- **Cleanup:** Remove disposable archive fixtures and sign out.
- **Source:** app/Controllers/ArchiveController.php:42-200; app/Services/Archive/ArchiveAccessPolicy.php:40-129; app/Services/Archive/ArchiveEntityRegistry.php:205-303
- **Evidence:** Read-only Archive Center screenshots, 403 mutation response log, and masked non-PHI inspection.
- **Verdict:** `NOT_RUN`

## ARV-002 — Clinic administrator archives and restores only Clinic and Clinic Team records
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.SCOPE.CLINIC_ADMIN`
- **Role:** `clinic_admin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-05`
- **Surface:** Clinic inline archive controls and `/admin/archive`
- **Route/API:** GET /admin/archive; POST /clinic/encounters/{id}/archive|restore; POST /clinic/inventory/{id}/archive|restore; POST /clinic/isolation/{id}/archive|restore; POST /clinic/teams/{id}/archive|restore; cross-unit POST /admin/archive/records/archive
- **Actor/grants:** Active `clinic_admin` with `archive.read` and `archive.manage`
- **Preconditions:** Disposable Clinic sealed encounter/isolation/medicine and Clinic Team task/event plus Guidance case and BMG drum sentinels in the same tenant.
**Steps**
1. Archive and then restore eligible Clinic and Clinic Team records with a valid reason (>=5 chars) and observe the central archive ledger and audit events.
2. Search `/admin/archive` for Guidance and BMG sentinels, then submit a direct POST targeting a Guidance `counselling_case` or `bmg_unit`.
- **Expected:** Only Clinic (`clinic_encounter`, `clinic_isolation`, `clinic_clearance`, `clinic_medicine`, `announcement[clinic]`) and `teams[clinic]` records archive and restore reversibly; Guidance/BMG targets fail with 403 and remain absent from queries.
- **Negative/non-disclosure:** Active/open encounters cannot be archived prematurely, cross-unit identifiers disclose zero metadata, and audit payloads omit PHI.
- **Cleanup:** Restore or remove disposable fixtures in the test database.
- **Source:** app/Services/Archive/ArchiveAccessPolicy.php:69-161; app/Services/Archive/ArchiveService.php:56-119; app/Config/Routes.php:310-319
- **Evidence:** Clinic/Team before-and-after state, archive ledger rows, audit entries, and 403 cross-department rejection.
- **Verdict:** `NOT_RUN`

## ARV-003 — Guidance administrator manages Guidance archives and legal holds without crossing units
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.SCOPE.GUIDANCE_ADMIN_HOLDS`
- **Role:** `guidance_admin`
- **Module:** Archive Governance
- **Use Cases:** `UC-GD-17`, `UC-AD-05`
- **Surface:** Guidance inline archive controls and `/admin/archive`
- **Route/API:** POST /guidance/cases/{id}/archive|restore; POST /guidance/episodes/{id}/archive|restore; POST /admin/archive/holds/place; POST /admin/archive/holds/{id}/lift
- **Actor/grants:** Active `guidance_admin` with `archive.read`, `archive.manage`, and `archive.legal_hold.manage`
- **Preconditions:** Disposable closed Guidance case, episode, survey, question bank item, and Guidance Team task plus a Clinic encounter sentinel.
**Steps**
1. Place a legal hold on a closed Guidance case, attempt to archive that held case, then lift the hold with a lift reason and archive/restore it cleanly.
2. Attempt to place a legal hold or archive a Clinic `clinic_encounter` or `bmg_unit` as `guidance_admin`.
- **Expected:** Active legal hold blocks archival with HTTP 409; lifting the hold permits reversible Guidance archival/restoration; cross-unit holds/archives fail with 403.
- **Negative/non-disclosure:** Held case content is untouched, central hold listings show only non-PHI reference codes, and retention/policy configuration stays denied without `archive.policies.manage`.
- **Cleanup:** Lift disposable holds, restore fixtures, and retire test DB.
- **Source:** app/Services/Archive/LegalHoldService.php:36-80; app/Services/Archive/ArchiveAccessPolicy.php:91-129; app/Config/Routes.php:310-319,357-358,423-424
- **Evidence:** Hold 409 block screenshot/status, lift-then-archive sequence, audit chain entries, and 403 cross-unit hold rejection.
- **Verdict:** `NOT_RUN`

## ARV-004 — BMG administrator archives and restores BMG units and Team items within unit scope
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.SCOPE.BMG_ADMIN`
- **Role:** `bmg_admin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-05`
- **Surface:** Facilities unit detail and `/admin/archive`
- **Route/API:** POST /facilities/unit/{id}/archive|restore; POST /facilities/teams/{id}/archive|restore; GET /admin/archive
- **Actor/grants:** Active `bmg_admin` with `archive.read` and `archive.manage`
- **Preconditions:** Two disposable BMG units (one idle/offline with no active batch, one with an active `processing` batch), a BMG Team calendar event, and Clinic/Guidance sentinels.
**Steps**
1. Archive and restore the idle BMG drum unit and BMG Team event via `/facilities/unit/{id}/archive` and `/admin/archive`.
2. Attempt to archive the drum unit carrying an active `processing` batch and attempt to query or archive a Clinic/Guidance record.
- **Expected:** Idle BMG units and BMG Team items archive/restore reversibly with retired device bindings on archive; active-batch units fail closed, and Clinic/Guidance records are denied with 403.
- **Negative/non-disclosure:** Archiving does not destroy batch history or actuate hardware, and restoration never re-enables hardware automation implicitly.
- **Cleanup:** Restore or retire disposable BMG fixtures.
- **Source:** app/Controllers/FacilitiesController.php:1-160; app/Services/Archive/ArchiveAccessPolicy.php:96-129; app/Config/Routes.php:200-209,275-276
- **Evidence:** Idle archive/restore ledger rows, active-batch rejection, and cross-unit 403 proof.
- **Verdict:** `NOT_RUN`

## ARV-005 — Platform owner configures non-purgeable institutional retention policies
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.RETENTION.NON_PURGEABLE`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`
- **Surface:** Archive Center Policies tab
- **Route/API:** GET /admin/archive?tab=policies; POST /admin/archive/policies/save; POST /admin/archive/policies/{id}/toggle
- **Actor/grants:** Active `superadmin` with institutional `archive.policies.manage` authority; `clinic_admin` and `audit_reader` negative controls
- **Preconditions:** Disposable tenant without conflicting test policy code; target archivable record matching the policy module/entity type.
**Steps**
1. Create an active retention policy (`policy_code`, `module`, `entity_type`, `duration_months`, `legal_basis`), archive a matching record, and verify `retention_policy_id` and `retention_due_at` calculation.
2. Toggle the policy inactive/active, inspect for any destructive purge trigger/flag, and attempt `policies/save` and `policies/{id}/toggle` as `clinic_admin` and `audit_reader`.
- **Expected:** Policy resolution selects the most specific active rule, computes reversible `retention_due_at` with `purge_enabled=0` unconditionally, and denies unauthorized roles with 403.
- **Negative/non-disclosure:** No permanent hard-delete or auto-purge executes regardless of client payload fields, and invalid policy codes/durations reject cleanly.
- **Cleanup:** Deactivate/remove disposable policy and archive record.
- **Source:** app/Services/Archive/RetentionPolicyService.php:35-120; app/Controllers/ArchiveController.php:164-200
- **Evidence:** Policy configuration form/list, computed `retention_due_at`, `purge_enabled=0` verification, and 403 unauthorized responses.
- **Verdict:** `NOT_RUN`

## ARV-006 — Cross-module legal holds block premature archive and sync with Guidance holds
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.LEGAL_HOLD.CROSS_MODULE`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`
- **Surface:** Archive Center Holds tab and module workspaces
- **Route/API:** GET /admin/archive?tab=holds; POST /admin/archive/holds/place; POST /admin/archive/holds/{id}/lift; POST /admin/archive/records/archive
- **Actor/grants:** Active `superadmin` with `archive.legal_hold.manage`
- **Preconditions:** Eligible records across Clinic (`clinic_encounter`), Guidance (`guidance_form_instance` including one legacy `guidance_legal_holds` row), and Facilities (`bmg_unit`).
**Steps**
1. Place holds on the Clinic encounter and BMG unit; verify `activeHoldKeys` badges render and archive attempts on both plus the legacy-held Guidance form return HTTP 409.
2. Lift the hold on the Clinic encounter with a required lift reason and verify archival now succeeds while the BMG hold stays enforced.
- **Expected:** Active holds in either `archive_legal_holds` or `guidance_legal_holds` block archival atomically with HTTP 409 and audit both placement and lift with actor/reason metadata.
- **Negative/non-disclosure:** Holds never leak protected record contents in central views, blank lift reasons fail validation, and lifted holds retain historical audit trail.
- **Cleanup:** Lift remaining test holds and retire disposable fixtures.
- **Source:** app/Services/Archive/LegalHoldService.php:36-120; app/Controllers/ArchiveController.php:128-162
- **Evidence:** Hold badge/409 matrix across modules, lift transition, and immutable hold audit rows.
- **Verdict:** `NOT_RUN`

## ARV-007 — Inline reversible archive and silent undo UX mask confidential details centrally
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.UX.UNDO_AND_CONFIDENTIAL_MASK`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`, `UC-AD-05`
- **Surface:** Inline module archive buttons, silent undo toast, and `/admin/archive`
- **Route/API:** POST /admin/carousel/{id}/archive|restore; POST /{unit}/announcements/{id}/archive|restore; GET /admin/archive
- **Actor/grants:** Active `superadmin`
- **Preconditions:** Disposable confidential clinical/counselling records with recognizable plaintext sentinels and non-confidential carousel/announcement items.
**Steps**
1. Trigger an inline archive on a Team task or announcement, observe the silent undo banner/action, and immediately execute restore via the undo action.
2. Archive a confidential `clinic_encounter` and `counselling_case` containing sentinel text, then inspect `/admin/archive?tab=records` HTML/JSON responses.
- **Expected:** Inline archive moves the item cleanly and silent undo restores it without page corruption; central Archive views display non-PHI reference labels and `confidential: true` metadata only.
- **Negative/non-disclosure:** Zero complaint, diagnosis, session note, or ciphertext sentinels appear in central `archive_records` listings or audit logs.
- **Cleanup:** Restore/delete disposable records and retire database.
- **Source:** app/Services/Archive/ArchiveEntityRegistry.php:205-303; app/Controllers/AdminCarouselController.php:1-140; app/Views/admin/archive/index.php:1-129
- **Evidence:** Inline undo sequence capture, masked `/admin/archive` screenshot, and negative sentinel search result.
- **Verdict:** `NOT_RUN`

## ARV-008 — Immutable compliance and clinical ledgers reject archival and deletion
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.IMMUTABLE_LEDGERS_PROTECTED`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`
- **Surface:** Archive API / POST endpoint validation
- **Route/API:** POST /admin/archive/records/archive; POST /admin/archive/holds/place
- **Actor/grants:** Active `superadmin`
- **Preconditions:** Existing rows in `audit_events`, `inventory_operations`, `stock_movements`, `guidance_form_revisions`, `guidance_form_attestations`, `guidance_consent_receipts`, and `guidance_document_issuances`.
**Steps**
1. Open `/admin/archive` and confirm the UI lists immutable ledgers as protected and omits them from archivable type selectors.
2. Submit direct CSRF-valid POST requests targeting each `IMMUTABLE_ENTITIES` key to `/admin/archive/records/archive`.
- **Expected:** Every request targeting an immutable compliance/clinical ledger is rejected with HTTP 409 ("Immutable compliance ledger records cannot be archived or deleted.") and zero rows are marked archived or deleted.
- **Negative/non-disclosure:** No database trigger bypass, unknown-entity fallback, or audit-chain alteration occurs.
- **Cleanup:** None beyond disposable DB teardown.
- **Source:** app/Services/Archive/ArchiveEntityRegistry.php:140-164; app/Controllers/ArchiveController.php:96-110
- **Evidence:** Protected-ledger UI capture, HTTP 409 matrix across all immutable keys, and unchanged row counts.
- **Verdict:** `NOT_RUN`
