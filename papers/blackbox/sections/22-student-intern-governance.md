# Student intern governance

## INT-001 — Clinic administrator manages only Clinic student intern placements
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.SCOPE.CLINIC_ADMIN`
- **Role:** `clinic_admin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Student Interns & Supervision console (`/admin/interns`)
- **Route/API:** GET /admin/interns; GET /admin/interns/create; GET /admin/interns/students; POST /admin/interns/store|/{id}/update|/{id}/revoke
- **Actor/grants:** Active `clinic_admin` with `interns.manage`
- **Preconditions:** Disposable Clinic intern placement, Guidance and BMG intern placements, eligible enrolled student, and active Clinic supervisor (`clinic_staff`/`clinic_physician`/`clinic_admin`).
**Steps**
1. Open `/admin/interns` as `clinic_admin`, inspect status counts/filters, and search eligible students via `/admin/interns/students`.
2. Attempt to view, create, update, or revoke a `guidance` or `bmg` unit placement via direct route/POST parameters.
- **Expected:** Only `clinic` unit placements and Clinic supervisors appear; all cross-unit reads/writes (`guidance`/`bmg`) return HTTP 403 without modifying placements or user roles.
- **Negative/non-disclosure:** Self-supervision, already-placed students, non-student employees, and foreign-tenant students are excluded from selection.
- **Cleanup:** Reset disposable placements in the test database.
- **Source:** app/Services/StudentInternService.php:75-264; app/Controllers/StudentInternController.php:16-98; app/Config/Routes.php:293-302
- **Evidence:** Clinic-scoped listing screenshot, student search response, and 403 cross-unit matrix.
- **Verdict:** `NOT_RUN`

## INT-002 — Guidance administrator assigns a Guidance intern while preserving student identity
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.PLACEMENT.CREATE_IDENTITY_PRESERVED`
- **Role:** `guidance_admin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Assign Student Intern modal (`/admin/interns/create`)
- **Route/API:** POST /admin/interns/store; GET /admin/interns/{id}/edit
- **Actor/grants:** Active `guidance_admin` with `interns.manage`; negative control `counsellor` without `interns.manage`
- **Preconditions:** Active enrolled `student` account (`people.kind = 'student'`) with existing student session/mobile token and no open placement; active Guidance supervisor (`counsellor`/`guidance_supervisor`/`guidance_admin`).
**Steps**
1. Create an immediately active Guidance intern placement with reason, academic term, supervisor, and `starts_at <= now` window (max 366 days).
2. Inspect `people.kind`, `users.role`, prior student session validity, and attempt placement creation as `counsellor` or with invalid window (>366 days) / self-supervision.
- **Expected:** User role updates to `guidance_intern`, `people.kind` strictly remains `student`, prior student session/auth_version is rotated, and unauthorized/invalid requests fail without partial writes.
- **Negative/non-disclosure:** No duplicate `people` row or `employee_profiles` row is created, and overlapping open placements for the same student are rejected.
- **Cleanup:** Revoke/delete disposable placement and test sessions.
- **Source:** app/Services/StudentInternService.php:267-350; app/Controllers/StudentInternController.php:54-65; app/Services/EmployeeAccessService.php:37-41
- **Evidence:** Created placement detail, `people.kind = 'student'` DB check, rotated session proof, and rejection matrix.
- **Verdict:** `NOT_RUN`

## INT-003 — BMG intern placement auto-activates, expires, and displays live shift clocks
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.LIFECYCLE.SCHEDULED_EXPIRED_CLOCK`
- **Role:** `bmg_admin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Student Interns console & live shift clock banner
- **Route/API:** POST /admin/interns/store; GET /admin/interns; GET /facilities/dashboard
- **Actor/grants:** Active `bmg_admin` with `interns.manage`; placed `bmg_intern`
- **Preconditions:** Eligible student; active BMG supervisor (`facilities_op`/`bmg_admin`); controlled Manila scheduling clock.
**Steps**
1. Schedule a `bmg_intern` placement with future `starts_at` and inspect status=`scheduled` while user remains `student`.
2. Advance controlled clock past `starts_at`, verify reconciliation promotes status to `active` / role `bmg_intern` and renders live shift countdown clock, then advance past `ends_at`.
- **Expected:** Reconciliation deterministically transitions `scheduled -> active -> expired`, updates `users.role` (`student -> bmg_intern -> student`), and removes workstation access upon expiry.
- **Negative/non-disclosure:** Scheduled interns gain zero premature BMG access and expired interns cannot bypass time bounds using retained sessions/tokens.
- **Cleanup:** Restore test clock and clean disposable placement.
- **Source:** app/Services/StudentInternService.php:108-226,248-264; app/Controllers/StudentInternController.php:16-26
- **Evidence:** Scheduled, active (with live shift clock), and expired reconciliation captures plus denied expired request.
- **Verdict:** `NOT_RUN`

## INT-004 — Superadmin revokes any unit placement and terminates active sessions immediately
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.REVOKE.IMMEDIATE_TERMINATION`
- **Role:** `superadmin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Student Interns management console
- **Route/API:** GET /admin/interns; POST /admin/interns/{id}/revoke; protected workstation and `/api/v1/*` routes
- **Actor/grants:** Active `superadmin`
- **Preconditions:** Active Clinic, Guidance, or BMG intern signed in on both web workstation browser and mobile bearer client.
**Steps**
1. Submit `/admin/interns/{id}/revoke` as `superadmin` with a valid revocation reason and optimistic version.
2. Immediately attempt protected Clinic/Guidance/BMG web actions and mobile API requests using the intern's open browser session and bearer token.
- **Expected:** Placement status transitions to `revoked`, `users.role` reverts atomically to `student`, `auth_version` increments, bearer tokens are revoked, and next protected requests fail immediately with audit `intern.revoked`.
- **Negative/non-disclosure:** Stale version returns 409, blank revocation reason fails 422, and historical student portal records remain intact.
- **Cleanup:** Reset disposable placement/user fixtures.
- **Source:** app/Services/StudentInternService.php:75-105; app/Controllers/StudentInternController.php:89-98
- **Evidence:** Revocation confirmation, 409/401/403 open-session/token denial, restored `student` role check, and audit row.
- **Verdict:** `NOT_RUN`

## INT-005 — Guidance intern operates queue and co-sign drafts without final clinical authority
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.BOUNDARY.GUIDANCE_INTERN`
- **Role:** `guidance_intern`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-01`, `UC-GD-02`, `UC-GD-03`, `UC-GD-04`
- **Surface:** Guidance Counselor Workspace (intern view)
- **Route/API:** GET /guidance; GET /guidance/clients; POST /guidance/queue/*; POST /guidance/intake/*; POST /guidance/clinical/forms/*
- **Actor/grants:** Active `guidance_intern` on dedicated Guidance host
- **Preconditions:** Active Guidance intern placement; waiting Guidance ticket; synthetic intake; assigned open case; canonical clinical form definition.
**Steps**
1. As `guidance_intern`, operate Guidance queue, process an intake, browse Clients Directory, and author a psychosocial/clinical draft form submitted for supervisor co-sign.
2. Attempt direct clinical finalization (`.../finalize`), co-signing (`.../cosign`), case closure/transfer/reassign, call slip issue, survey/announcement publish, and cross-case oversight (`read_any`).
- **Expected:** Allowed queue, intake, read, and draft-authoring workflows succeed cleanly; every ungranted finalization, co-sign, closure, transfer, publish, and oversight route returns 403 without state mutation.
- **Negative/non-disclosure:** `guidance_intern` cannot finalize independent clinical records, view unassigned counselling notes, or access Clinic/BMG consoles.
- **Cleanup:** Delete/reset disposable intake, draft, and placement fixtures.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:244-252; app/Services/PortalContext.php:154-160; app/Services/Guidance/GuidanceClinicalFormService.php:309-428
- **Evidence:** Allowed workflow captures, pending-cosign draft state, and 403 denial matrix for ungranted Guidance routes.
- **Verdict:** `NOT_RUN`

## INT-006 — BMG intern logs supervised batch work with live shift clock and hardware boundaries
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.BOUNDARY.BMG_INTERN`
- **Role:** `bmg_intern`
- **Module:** Facilities (BMG) & Hardware
- **Use Cases:** `UC-BM-01`
- **Surface:** Facilities Operations (BMG) workspace
- **Route/API:** GET /facilities/dashboard; GET /facilities/unit/{id}; POST /facilities/unit/{id}/batch; POST /facilities/units/store; POST /facilities/unit/{id}/schedule|device|usb/build
- **Actor/grants:** Active `bmg_intern` on dedicated BMG host
- **Preconditions:** Active BMG intern placement; active test drum unit; no active batch.
**Steps**
1. Open `/facilities/dashboard` and `/facilities/unit/{id}`, verify the live intern shift countdown timer, and perform allowed supervised batch logging (`facilities.read`/`facilities.manage`).
2. Attempt `POST /facilities/units/store`, unit configuration/maintenance edits, rotation schedule save, device registration, and USB commissioning routes.
- **Expected:** Allowed dashboard monitoring and supervised bookkeeping work succeed while unit registration (`facilities.configure`), hardware commissioning (`facilities.hardware.manage`), archive, and intern-admin routes fail with 403.
- **Negative/non-disclosure:** `bmg_intern` cannot access Clinic/Guidance consoles or alter hardware schedules/tokens.
- **Cleanup:** Cancel/reset disposable test batch and end session.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:240-242; app/Services/PortalContext.php:161-166; app/Config/Routes.php:261-284
- **Evidence:** Live shift clock screenshot, allowed batch log, and 403 denial matrix for config/hardware routes.
- **Verdict:** `NOT_RUN`
