# 13 — Administration and platform

## AD-001 — Administrative dashboard enforces permission-shaped data
- Lifecycle: `READY`
- Legacy IDs: `AD-001`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The administrative dashboard must derive tenant-scoped operational counters and must never serve cached audit data to a reports-only identity.
- Role: `audit_reader`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-01`, `UC-AD-03`
- Surface: Platform web dashboard.
- Route/API: `GET /admin`; `GET /dashboard`.
- Actor/grants: Superadmin/audit reader with `audit.read`; report viewer with `reports.read` for the negative variant.
- Preconditions: Disposable tenant with known people, operations, and audit fixtures; separate browser sessions.

**Steps**
1. Open `/admin` as an audit-authorized actor and reconcile visible counters and recent audit entries with the fixture.
2. Prime dashboard caches, then open `/dashboard` as a reports-only viewer.
3. Inspect rendered content and responses for audit rows, event payloads, or controls unavailable to the second actor.

- Expected: Authorized counters are tenant-scoped; audit sections appear only with `audit.read`; reports-only view does not inherit cached audit data.
- Negative/non-disclosure: No other tenant, audit payload, clinical note, credential, or authorization decision leaks through shared cache entries.
- Cleanup: End both sessions and retire the DB.
- Source: `app/Controllers/DashboardController.php:37-143`
- Evidence: Role-separated screenshots, fixture reconciliation, and sanitized cache/request trace.
- Verdict: `NOT_RUN`

## AD-002 — Audit explorer filters and cursor bounds
- Lifecycle: `READY`
- Legacy IDs: `AD-002`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The audit explorer must validate scalar filters, exact event/action/entity/actor values, date order, 25/50/100 page sizes, and BIGINT-safe older/newer cursors while remaining tenant-scoped.
- Role: `audit_reader`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-03`
- Surface: Administrative audit ledger.
- Route/API: `GET /admin/audit`.
- Actor/grants: Actor with `audit.read`.
- Preconditions: Disposable tenant with deterministic audit events covering two actors/types/entities/dates and enough rows for two pages.

**Steps**
1. Apply each filter independently and together; compare returned event IDs with the fixture.
2. Traverse older then newer cursor links at page sizes 25, 50, and 100, verifying no gaps or duplicates.
3. Submit invalid arrays, overlong values, reversed dates, size 500, newer-without-cursor, and out-of-range BIGINT cursor.

- Expected: Valid filters return only matching tenant rows in deterministic order; cursor navigation is bounded; invalid state returns page errors and no query results.
- Negative/non-disclosure: Invalid input does not coerce, overflow, execute SQL patterns, or reveal another tenant’s row/count.
- Cleanup: Retire the audit fixture database.
- Source: `app/Services/Audit/AuditExplorerQuery.php:9-96`; `app/Services/Audit/AuditExplorer.php:22-74`
- Evidence: Filter/cursor matrix, ordered ID lists, and invalid-input screenshots.
- Verdict: `NOT_RUN`

## AD-003 — Audit hash-chain verification is scoped and qualified
- Lifecycle: `READY`
- Legacy IDs: `AD-003`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Verification must inspect the current tenant ledger from genesis to head, return pass only for an intact chain, identify a broken event on failure, and state that no external checkpoint was checked.
- Role: `audit_reader`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-03`
- Surface: Audit ledger verification action.
- Route/API: `POST /admin/audit/verify`.
- Actor/grants: Actor with `audit.read`.
- Preconditions: Disposable database with one intact tenant ledger; a cloned disposable variant may be deliberately tampered offline.

**Steps**
1. Run verification on the intact ledger and record verified count and qualification text.
2. In the disposable clone only, alter one stored event field without recomputing hashes and rerun verification.
3. Confirm response status, message, CSRF refresh, and that verification performed no repair.

- Expected: Intact ledger returns success; tampered ledger returns `422` integrity alert at the broken ID; both results are tenant-scoped and no external-anchor claim is made.
- Negative/non-disclosure: Never tamper with or run repair against operational data; error output does not expose secret payload fields or another tenant.
- Cleanup: Destroy the tampered clone immediately.
- Source: `app/Controllers/AuditController.php:54-84`; `app/Services/Audit/AuditService.php:102-151`
- Evidence: Intact/tampered responses, ledger fixture hash manifest, and disposable-DB identifier.
- Verdict: `NOT_RUN`

## AD-005 — MIS synchronization is transactional and bounded
- Lifecycle: `DRAFT`
- Legacy IDs: `AD-005`
- Changed by: `bea71cf`
- Requirement: An authorized manual reference or people sync must publish optional user-scoped progress, commit only a complete validated result, and report incomplete upstream failure without exposing payloads; live MIS execution requires an approved campaign.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-07`
- Surface: MIS synchronization administration.
- Route/API: `GET /admin/mis-sync`; `POST /admin/mis-sync/run`; `POST /admin/mis-sync/people`.
- Actor/grants: Actor with `mis.sync`; fake MIS client by default, approved upstream operator only in a gated campaign.
- Preconditions: Disposable DB; deterministic fake client with success and midstream-failure variants; unique progress ID.

**Steps**
1. Record catalog/people counts, trigger a fake successful sync with progress, and compare committed results and status timestamp.
2. Reset, run a fake failure after partial progress, and compare every affected table with the pre-run snapshot.
3. Inspect messages and user-channel progress events; confirm a people sync changes no accounts, roles, passwords, or clinical records.

- Expected: Success commits complete scoped data and optional progress; failure reports unavailable/incomplete and commits nothing; progress failure itself does not abort an otherwise valid sync.
- Negative/non-disclosure: Raw MIS payloads, keys, upstream exception text, identities not required by evidence, and live personal data are never captured.
- Cleanup: Retire DB and disconnect fake/upstream client credentials.
- Source: `app/Controllers/MisSyncController.php:18-94`; `app/Controllers/MisSyncController.php:108-126`
- Evidence: Before/after table digests, progress event summary, response messages, and campaign authorization if applicable.
- Verdict: `NOT_RUN`

## AD-006 — Employee role assignment is scoped, versioned, and session-revoking
- Lifecycle: `DRAFT`
- Legacy IDs: `AD-006`
- Changed by: `bea71cf`
- Requirement: An authorized administrator may assign only policy-permitted roles to an active employee, with nonblank reason and current `auth_version`; the write increments that version, invalidates mobile/browser authority, and records one audit event. Final employee assignment policy remains unresolved.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-07`, `UC-AD-08`
- Surface: Employee access administration.
- Route/API: `GET /admin/access/{user}/edit`; `POST /admin/access/{user}/update`.
- Actor/grants: Superadmin or department administrator allowed by current policy and `rbac.manage`.
- Preconditions: Disposable active employee target, two target sessions, current version, at least two active superadmins for demotion variant.

**Steps**
1. Open the target edit page, assign a permitted different role with a reason/current version, and inspect response and audit ledger.
2. Reuse the old target browser and bearer token on their next requests, then reload the access page.
3. Submit the stale version, a disallowed cross-department role, self-change, inactive employee promotion, and last-active-superadmin removal variants.

- Expected: One valid update increments `auth_version`, changes role, audits previous/new role and reason, and makes stale authority fail; all protected variants reject without write.
- Negative/non-disclosure: The screen does not provision identity/password/provider appointment; admins cannot escape department scope or change themselves.
- Cleanup: Restore target role through a fresh versioned request and end sessions.
- Source: `app/Services/EmployeeAccessService.php:13-60`; `app/Services/EmployeeAccessService.php:129-183`
- Evidence: Redacted before/after versions, old-session failures, policy rejection matrix, and audit event.
- Verdict: `NOT_RUN`

## AD-007 — Sign-in carousel changes are ordered, scoped, and safely published
- Lifecycle: `READY`
- Legacy IDs: `AD-007`
- Changed by: `bea71cf`, `c179273`
- Requirement: Authorized carousel create/edit/reorder/toggle/delete actions must be tenant-safe, validate managed assets, and become visible to web/mobile login surfaces without exposing the private store.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-08`
- Surface: Carousel administration, web login, and mobile login carousel.
- Route/API: `/admin/carousel/*`; `GET /auth/login`; `GET /api/v1/auth/carousel`.
- Actor/grants: RBAC manager; platform owner for protected default deletion where required.
- Preconditions: Disposable test artwork containing no personal data; two slides with known order.

**Steps**
1. Add the test slide, reorder it, toggle it hidden, and compare admin state with the unauthenticated web/mobile carousel after each action.
2. Re-enable and edit the slide, then request its managed asset URL and verify content/security headers.
3. Attempt cross-tenant ID use, unsafe upload, duplicate order payload, and unauthorized delete; finally delete the test slide.

- Expected: Order/visibility propagate consistently; assets are served only through the managed controller; invalid/unauthorized actions fail without corrupting order.
- Negative/non-disclosure: Private storage paths, arbitrary files, hidden slides, credentials, and historical personal artwork are not exposed.
- Cleanup: Delete the synthetic slide and test asset.
- Source: `app/Config/Routes.php:16-20`; `app/Config/Routes.php:291-301`; `app/Controllers/AdminCarouselController.php:41-223`
- Evidence: Admin/web/mobile captures, sanitized asset headers, and cleanup proof.
- Verdict: `NOT_RUN`

## AD-009 — Role and permission catalogue reflects all 16 grants without implying assignability
- **Lifecycle:** `READY`
- **Legacy IDs:** `AD-009`
- **Changed by:** `bea71cf`, `62c790b`
- **Requirement:** The catalogue must list all 16 database-granted roles (13 assignable employee roles plus non-employee catalogue roles `student`, `guidance_intern`, and `bmg_intern`), while the assignment selector remains actor-policy-specific.
- **Role:** `superadmin`
- **Module:** Administration, Access & Platform
- **Use Cases:** `UC-AD-08`
- Surface: Employee access detail/catalogue.
- Route/API: `GET /admin/access/{user}/edit`.
- Actor/grants: Authorized employee-access administrator.
- Preconditions: Seeded permission/role grant tables (`PermissionsAndRolesSeeder`) and an employee target.

**Steps**
1. Open access detail and inventory every role/permission combination shown in the catalogue.
2. Compare it with distinct `role_permissions` roles (16 total: 13 in `EmployeeAccessService::ROLES` plus 3 in `CATALOGUE_LABELS`: `student`, `guidance_intern`, `bmg_intern`).
3. Compare the role assignment selector as `superadmin`, `clinic_admin`, and `guidance_admin`.

- Expected: All 16 granted roles and codes are visible as catalogue facts; selector choices follow current scoped assignment policy (excluding `student` and student interns governed under `/admin/interns`).
- Negative/non-disclosure: A displayed role does not imply it is assignable on `/admin/access`; absent grants are not invented; no clinical data appears.
- Cleanup: None.
- Source: `app/Services/EmployeeAccessService.php:16-63`; `app/Services/EmployeeAccessService.php:114-129`; `app/Database/Seeds/PermissionsAndRolesSeeder.php:122-277`
- Evidence: 16-role/grant reconciliation table and role-specific selector screenshots.
- Verdict: `NOT_RUN`

## AD-010 — Operational CSV export is aggregate, bounded, and injection-safe
- Lifecycle: `READY`
- Legacy IDs: `AD-010`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: CSV export must contain only tenant-scoped daily aggregate counts over the authorized units/Manila date range, neutralize formula-leading cells, stay under 8 MiB, remain memory-backed, and audit metadata before release.
- Role: `report_viewer`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-02`
- Surface: Operational report export.
- Route/API: `POST /reports/export/operational` with `format=csv`.
- Actor/grants: Actor authorized by report export policy for selected units.
- Preconditions: Disposable deterministic daily fixtures, including Manila-boundary records; an isolated unit test may supply formula-leading synthetic cells.

**Steps**
1. Export a bounded multi-unit range and reconcile every CSV row and generated filename with source aggregate counts.
2. Inspect headers, encoding, line endings, and formula-neutralization behavior using synthetic export cells.
3. Attempt an unauthorized unit/range and an export exceeding the byte/range bound; inspect audit events before accepting the download.

- Expected: Only aggregate `date_manila,unit,metric,count` rows are downloaded; unsafe cells are prefixed, bounds reject safely, and audit records units/range/row count/format without content.
- Negative/non-disclosure: No names, IDs, notes, diagnoses, counseling text, export plaintext files, cross-tenant rows, or formula execution is present.
- Cleanup: Securely delete local downloaded test files and retire DB.
- Source: `app/Services/Reporting/OperationalExportService.php:23-77`; `app/Services/Reporting/ExportCsv.php:10-58`
- Evidence: Sanitized CSV hash/row reconciliation, headers, injection test, and audit metadata.
- Verdict: `NOT_RUN`

## AD-011 — Operational PDF matches authorized aggregate source
- Lifecycle: `READY`
- Legacy IDs: `AD-011`
- Changed by: `bea71cf`, `884b9f0`, `62c790b`
- Requirement: PDF export must render the same authorized aggregate period/unit dataset as CSV under the unified institutional PDF header (`DocumentHeaderLatex`), start with a valid PDF signature, use a safe deterministic filename, no-store headers, and audit before body release.
- Role: `report_viewer`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-02`
- Surface: Operational report export.
- Route/API: `POST /reports/export/operational` with `format=pdf`.
- Actor/grants: Authorized report actor for selected units.
- Preconditions: Same deterministic fixture and selection used for AD-010; required PDF renderer available in the disposable environment.

**Steps**
1. Export CSV and PDF for the identical selection; retain hashes and extract only sanitized aggregate text from the PDF.
2. Compare institutional header/seal layout, dates, units, metrics, counts, and any clinic aggregate insight panels against the server’s authorized dataset.
3. Inspect signature, filename, content type, no-store/nosniff headers, and export audit row; simulate renderer failure.

- Expected: PDF is a valid `%PDF-1.4` document whose aggregate figures match its source dataset; failure returns no download; metadata-only audit precedes release.
- Negative/non-disclosure: No patient/person-level rows, hidden notes, renderer diagnostics, temp plaintext artifact, or unauthorized unit appears.
- Cleanup: Delete downloaded PDF/CSV and renderer temporary output; retire DB.
- Source: `app/Controllers/ReportExportController.php:32-53`; `app/Services/Reporting/OperationalExportService.php:23-43`; `app/Services/Reporting/DocumentHeaderLatex.php:1-76`
- Evidence: File hashes, sanitized text comparison, response headers, failure response, and audit row.
- Verdict: `NOT_RUN`

## AD-012 — Report viewer cannot cross into audit authority
- Lifecycle: `READY`
- Legacy IDs: `AD-012`
- Changed by: `bea71cf`
- Requirement: A report viewer may use operational dashboards/exports allowed by `reports.read` but must be denied audit ledger and unrelated administration routes requiring separate grants.
- Role: `report_viewer`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-01`, `UC-AD-02`
- Surface: Main dashboard, report export, administrative audit/access/AI pages.
- Route/API: `GET /dashboard`; `/reports/export`; `/admin/audit`; `/admin/access`; `/admin/ai`.
- Actor/grants: Active `report_viewer` with reports grant and no audit/RBAC/AI configuration grants.
- Preconditions: Disposable report-viewer account and tenant aggregate fixture.

**Steps**
1. Sign in as report viewer, open dashboard/export, and perform one authorized bounded export.
2. Directly request audit ledger, audit verify, employee access, carousel, MIS, and AI configuration routes.
3. Inspect navigation and response bodies for hidden links or leaked data from denied surfaces.

- Expected: Report surfaces work within unit scope; each separately governed admin surface is absent and returns denial/redirect without data.
- Negative/non-disclosure: The role does not acquire audit events, employee directory, provider settings, keys, or RBAC controls through cache/direct URL.
- Cleanup: Sign out and retire test account/database.
- Source: `app/Config/Routes.php:118-148`; `app/Config/Routes.php:276-300`; `app/Controllers/DashboardController.php:15-44`
- Evidence: Allowed/denied route matrix, navigation screenshot, and sanitized export proof.
- Verdict: `NOT_RUN`

## AD-OPS-001 — Administrative mutations revalidate fresh authority and version
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `884b9f0`
- Requirement: High-impact administrative writes must use POST/CSRF where web-based, refresh actor authority at mutation time, require optimistic version where defined, and fail without partial state after role/session changes.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-08`
- Surface: Employee access, carousel, AI configuration, MIS sync, and audit verification controls.
- Route/API: Relevant `/admin/*` POST routes.
- Actor/grants: Initially authorized administrator whose grant/session is revoked in a second session.
- Preconditions: Disposable fixtures and two sessions for the same administrator; safe no-secret test payloads.

**Steps**
1. Open each target form and retain its CSRF/version, then revoke or change the actor’s role/auth version from a separate authorized session.
2. Submit the retained forms and direct GET/malformed requests to each mutation route.
3. Compare target rows, audit counts, downstream jobs/progress, and session behavior with the pre-submit snapshot.

- Expected: Stale authority, CSRF, method, or version is rejected; no partial mutation, job, or misleading success occurs; fresh sign-in/version is required.
- Negative/non-disclosure: Denials do not echo secrets, submitted provider keys, MIS payloads, SQL, or another tenant’s object existence.
- Cleanup: Restore administrator policy only through a fresh authorized session; remove all synthetic rows.
- Source: `app/Services/EmployeeAccessService.php:148-183`; `app/Controllers/MisSyncController.php:48-60`; `app/Controllers/AiConfigurationController.php:246-296`
- Evidence: Stale-request matrix, before/after table digests, and response headers/messages.
- Verdict: `NOT_RUN`
