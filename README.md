# SYNAPSE

Campus Clinic, Guidance & Counseling, Care Referrals, Facilities (BMG), and Governance — One Traceable University Platform.

SYNAPSE is the rebuilt university healthcare, counseling, and environmental operations platform for Foundation University, running on **CodeIgniter 4.7 / PHP 8.2+**, powered by server-rendered PHP views with the NiceAdmin design system, HTMX live polling, and Alpine.js micro-interactions. Sensitive counseling records are encrypted with **AES-256-GCM**, clinical and counseling domains maintain strict architectural separation, selected announcement, survey, and BMG events are recorded into a **SHA-256 hash-chained audit ledger**, and user identities are delegated directly to the campus **MIS API Gateway**.

---

## Table of Contents

- [Architectural Overview](#architectural-overview)
- [Multi-Portal & Subdomain Separation](#multi-portal--subdomain-separation)
- [MIS API Integration](#mis-api-integration)
- [Database Schema & Optimizations](#database-schema--optimizations)
- [Module Capabilities](#module-capabilities)
- [Hardware IoT Integration (ESP32-S3)](#hardware-iot-integration-esp32-s3)
- [Quick Start & Setup Runbook](#quick-start--setup-runbook)
- [Demo Credentials Matrix](#demo-credentials-matrix)
- [Automated Testing & CI](#automated-testing--ci)

---

## Architectural Overview

```
                     ┌─────────────────────────────────────────────────────────────┐
                     │                  Campus MIS API Gateway                     │
                     │   Tokens (/api/tokens/*)  ·  v1 (/api/v1/students, etc.)   │
                     └──────────────────────────────▲──────────────────────────────┘
                                                    │ Server-side only (cURL)
                                                    │ API-Key + Bearer Token
┌───────────────────────────────────────────────────▼───────────────────────────────────────────────────┐
│                                       SYNAPSE PLATFORM (CI 4.7)                                        │
│                                                                                                       │
│   ┌───────────────────────────────────────────────────────────────────────────────────────────────┐   │
│   │                                NiceAdmin UI Shell (Bootstrap 5.3)                             │   │
│   │   Views & View Cells  ·  htmx (queue live-updates, autosave)  ·  Alpine.js (modals, steppers)  │   │
│   └───────────────────────────────────────────────▲───────────────────────────────────────────────┘   │
│                                                   │                                                   │
│   ┌───────────────────────────────────────────────┴───────────────────────────────────────────────┐   │
│   │                             App Modules (Clean Modular Monolith)                              │   │
│   │   Identity & Auth  ·  Scheduling & Queue  ·  Clinic  ·  Guidance  ·  Referrals  ·  Facilities │   │
│   └───────────────────────────────────────────────▲───────────────────────────────────────────────┘   │
│                                                   │                                                   │
│   ┌───────────────────────────────────────────────┴───────────────────────────────────────────────┐   │
│   │                                       Data Persistence                                        │   │
│   │      MariaDB / MySQL  ·  Tenant-scoped BaseModel  ·  AES-256-GCM encrypted notes              │   │
│   │                     Append-only SHA-256 hash-chained audit ledger                             │   │
│   └───────────────────────────────────────────────────────────────────────────────────────────────┘   │
└───────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## Multi-Portal & Subdomain Separation

SYNAPSE implements a multi-portal architecture that separates consumer self-service health from internal departmental consoles:

| Access Point / Subdomain | Target Users | Permitted Roles | Purpose & Features |
|---|---|---|---|
| **`synapse.foundationu.com`**<br>(or `/portal`) | **Students & General Employees** | `student`, `employee`, and all staff in patient mode | **Personal Health Portal:** View personal health summary (blood type, allergies, conditions), check live waiting queue tickets, view and cancel upcoming appointments, book new clinic/guidance appointments. Staff members see a console-switcher banner. |
| **`clinic.foundationu.com`**<br>(or `/clinic/*`) | **Clinic Healthcare Staff** | `clinic_admin`, `clinic_staff`, `superadmin` | **Clinic Workstation:** Live Kanban Queue Board (Waiting $\rightarrow$ Called $\rightarrow$ In Consultation $\rightarrow$ Completed / Skipped), 4-step Consultation Stepper (Triage/BMI, Assessment, FEFO Dispensing, Disposition), Patient Registry & Charts, and Medicines Inventory. |
| **`guidance.foundationu.com`**<br>(or `/guidance/*`) | **Guidance Counselors & Supervisors** | `guidance_admin`, `guidance_supervisor`, `counsellor`, `superadmin` | **Counselor Workspace:** Guidance Waiting Queue, Student Case Containers (`GC-YYYY-XXX`), AES-256-GCM encrypted session notes with draft autosave and version history, student follow-up commitments, and counselor availability schedules. |
| **`bmg.foundationu.com`**<br>(or `/facilities/*`) | **BMG Operators & Facilities Admin** | `bmg_admin`, `facilities_op`, `superadmin` | **Drum Rotation Workspace (Biomechanical Goat):** Drum configuration, versioned daily rotation schedules, controller-reported events, command requests, and basic batch records. Hardware operation is disabled until deliberate commissioning. No environmental sensor monitoring or sanitation certification. |

### Workstation navigation

Dedicated hosts now use an explicit workspace map: `clinic.localhost`, `guidance.localhost`, `bmg.localhost` and `synapse.localhost` (plus configured university hosts). Sidebars show only role-authorized modules within the selected host, and the header provides an authorized workspace switcher on dedicated hosts. Plain `localhost` preserves path-based development access and already lists all authorized workstations, so that redundant switcher is hidden there. Wrong-host writes/AJAX are rejected, never forwarded; authorized cross-workspace GET navigation uses canonical hosts. Cookies remain host-only, so switching hosts can require another sign-in. See [workstation host routing, session behavior and test coverage](docs/PORTAL_HOSTS.md).

`partials/sidebar.php` is the sole owner of active navigation state. It matches full department paths on segment boundaries, so clinic and guidance appointments cannot activate each other's links. Referral links now use explicit `/clinic/referrals` and `/guidance/referrals` paths; neither activates the other. Legacy referral bookmarks redirect to an authorized workstation, while a platform owner without explicit context must choose a workstation. Shared shell JavaScript only expands and scrolls navigation; it must not infer active links from a filename or the last URL segment. Browser regression coverage exercises both department links as a superadmin in light and dark modes.

Shared scheduling views do not imply shared records: appointment lists filter by tenant, department (`unit`), and date. Navigation highlighting is not an authorization boundary; record access must be enforced independently on the server.

### Assigning real employees to workstations

Use **Administration → Employees & Access** on the main portal (`/admin/access`). Employees first sign in through MIS to create/link their Synapse login. Superadmins can then assign a supported employee role; Clinic and Guidance administrators with `rbac.manage` can assign/revoke only their own department's ordinary staff role. Each change requires a reason, is audited transactionally, and invalidates the employee's existing sessions. One account holds one role; choosing Employee removes workstation access without deleting records. No MIS password changes, grant reseeding or provider enrollment occur here. See [employee onboarding, assignment scope and publishing responsibilities](docs/EMPLOYEE_ACCESS.md).

### The "Dual-Identity" Solution for Department Staff
A clinic nurse or guidance counselor is also a university employee. SYNAPSE resolves this dual role cleanly:
- When signing in at `clinic.foundationu.com`, the staff member enters their operational console.
- When visiting `synapse.foundationu.com` (or `/portal`), they enter their **individual employee patient account**, allowing them to book medical checkups or counseling for themselves without polluting or confusing clinical records.
- If a regular student or faculty member attempts to access an operational console (`/clinic/queue`, `/guidance/cases`), the `PortalAccessFilter` redirects them to `/portal` with a clear restriction notice.

---

## MIS API Integration

Authentication is delegated server-side to Foundation University's campus MIS Gateway (`https://mis.foundationu.com/sandbox`):

```
Client (Browser) ──POST /auth/login──▶ Synapse Backend (cURL) ──POST /api/v1/{students|employees}/login──▶ MIS Gateway
                                               │
                                      JIT Provisioning
                                               ▼
                                      `people` + Profiles
                                               │
                                      Creates PHP Session
```

### Key Integration Points
1. **Delegated Authentication:** Students and employees sign in using their official Student ID or Employee ID and MIS password. Passwords are never stored locally (`users.password_hash` is `NULL`).
2. **Just-In-Time (JIT) Linking:** On successful MIS authentication, Synapse reuses the same-tenant `people` record identified by kind and official number—including imported directory records—and creates its local `users` link if absent. No separate registration or local password is needed. New people/profiles are created only when missing. Existing staff roles are preserved; new accounts receive only `student` or `employee`. Missing/blank optional profile fields do not erase known values.
3. **Explicit Authentication Modes:**
   - `FUMIS_ENABLED=true`: authenticates against the configured campus API using the server-side `FUMIS_API_KEY`. Login calls require no bearer-token preflight. Live verification requires the university network.
   - `FUMIS_ENABLED=false` and `FUMIS_FAKE_ENABLED=false` (default): MIS login is disabled and fails closed with an unavailable message; deliberate local break-glass accounts still work.
   - `FUMIS_ENABLED=false` and `FUMIS_FAKE_ENABLED=true`: explicitly enables `FakeMisClient` only in **development/testing**. This fixture client accepts arbitrary passwords for its known mock identifiers; never use it for real authentication. Production ignores the fake flag. Disabling MIS no longer silently enables mock sign-in.
4. **Authoritative Reference Data Mirrors:**
   - Academic Programs: `mis_programs` (mirrored from `/api/v1/students/programs`)
   - Year Levels: `mis_year_levels` (mirrored from `/api/v1/students/levels`)
   - University Departments: `mis_departments` (mirrored from `/api/v1/employees/departments`)

### Login compatibility port from the legacy MIS integration

The rebuild retains PHP sessions and the existing login UI; it does **not** copy legacy React/JWT authentication or its database design. The legacy `backend/app/Services/FuMis/` source was used to verify the request contract and supported profile aliases/envelopes:

- Student: `POST /api/v1/students/login`, JSON `student_id` and `password`.
- Employee: `POST /api/v1/employees/login`, JSON `employee_id` and `password`.
- Both send `API-Key` server-side. Student authentication is tried first; employee fallback occurs only after a credential rejection—not a timeout, certificate failure, malformed success response, or other upstream failure.
- Profiles at `data` and `data.data` are supported, including employee `departmentName` and `employmentStatus` aliases. Returned official numbers must match the submitted identifier. Incomplete new identities fail without provisioning.
- Invalid credentials and MIS unavailability have different safe messages. Raw upstream bodies, API keys, passwords, and MIS tokens are not exposed in session/flashdata. MIS passwords are never copied into local password hashes.
- Disabled/deleted accounts and people are not revived by MIS sign-in. Employee department/position is profile information, not an automatic grant of access to confidential workstation records.
- Imported people already represent university identities. A missing local user link means **not yet linked in Synapse**, not “no university account.” Scheduling enrollment now uses a dedicated person-linked assignment, independently of this login link. Successful login does not automatically enroll staff or grant privileged roles.

Configure `FUMIS_BASE_URL`, `FUMIS_API_KEY`, `FUMIS_ENABLED`, and optional `FUMIS_TIMEOUT_SECONDS` (bounded to 2–60 seconds) in private `.env`/deployment secrets. The example `env` contains no key. Login requires HTTPS, verifies TLS certificates/hostnames, and does not follow redirects. Keep the key out of browser assets, source control, and logs. No existing secrets were copied from the old repository or changed in local `.env`.

**Validation boundary:** the port is covered by mocked transport and isolated database/HTTP tests. No live MIS request was made; actual campus connectivity, credentials, payloads, and certificate-chain behavior remain pending. The legacy directory token refresh/cache/retry lifecycle has not been fully ported in this login-first slice.

### Local authorization, session, and CSRF hardening

Implemented locally; **live MIS verification remains pending campus-network access**.

- Protected requests reload the active account, tenant, linked person, role, and grants from the database. Stale session privileges are replaced; disabled/deleted identities lose access on their next protected request. Only the break-glass superadmin may lack a linked person.
- Browser mutations require CSRF validation, including login, logout, shared modal submissions, and carousel uploads. Shared JavaScript synchronizes refreshed tokens for forms, HTMX, and participating fetch/XHR clients. Authenticated responses handled by the CSRF filter are marked private/no-store.
- Login POSTs apply CSRF validation, then atomic database-backed attempt budgets before credential checks: 60 attempts/IP/minute and 5/identifier+IP per five minutes by default. Rejection returns HTTP 429 with `Retry-After`; missing/failed limiter storage fails closed with 503. This requires migration `2026-10-19-000005_CreateLoginAttemptBuckets`, not yet applied to the operational database.
- Required response headers cover early errors as well as normal pages. Low-risk CSP is enforced; the stricter script/style policy is **report-only**, because the current UI uses inline scripts/styles and Alpine expressions. See [Web security](docs/WEB_SECURITY.md) for proxy trust, privacy, configuration, limitations, and deployment.
- Logout and guidance queue “Open Chart” are POST actions; their former GET mutation endpoints return **405**. The logout confirmation and theme preference remain supported.
- Department permissions and tenant-scoped record checks protect clinic/guidance HTTP workflows. Ordinary counselors cannot list, open, or modify another counselor's cases without `guidance.cases.read_any`.
- Patient lookup searches the **local directory only**, returns limited identity fields, and requires clinic/guidance staff permissions. It does not call MIS or provision accounts through a GET request.
- Referrals derive routing from fresh staff authority and explicit workstation routes, validate tenant/patient/clinical links, and return senders to their **Sent** folder after creation. Ordinary staff do not choose source or destination.
- `/api/v1/devices/bmg/*` retains device authentication rather than browser-session CSRF. This is not a claim that hardware compatibility or compliance has been fully validated.
- MIS cURL requests now require TLS peer and hostname verification. Campus connectivity, actual student/employee payloads, and certificate-chain compatibility are **not yet verified**. Keep `FUMIS_ENABLED=false` for off-campus development; fake-client tests do not prove live integration.

Review the slice-specific deployment requirements before enabling changed code: [session security](docs/SESSION_SECURITY.md), [web security and login counter migration](docs/WEB_SECURITY.md), and [scheduling](docs/SCHEDULING.md). No operational migration, account seeding, or clinical-data rewrite was performed by these hardening stages. Legacy internal authorization, strict script/style CSP enforcement, and broader security review remain separate work. Do not treat this as production-security certification.

### Legacy directory-only import

A local development import copied **2,003 students and 602 employees** from confirmed source **`synapse_zcode`** into `humus_db`, tenant 1 (Foundation University). The initially requested `zynapse_zcode` name was absent; the corrected source was explicitly confirmed before use.

`app/Services/Directory/LegacyDirectoryImporter.php` reads an explicit whitelist from the old `users` directory. It maps names, gender, birth date and address into `people`; student number/course/year/section/department into student identity/profile fields; and employee number/department/position/employment status/teaching flag into employee identity/profile fields. Official IDs remain strings, including leading zeros. Legacy primary keys are not reused. Source email/phone fields were unavailable, so these remain NULL; QR codes, passwords, accounts, roles, emergency contacts, blood type and clinical/guidance information are excluded. Unsupported fields such as hire date are not added to the target schema. Imported rows are not marked as freshly MIS-synced.

The importer is insert-only: it preserves existing people and profiles, including soft-deleted identities. One existing ID had different name information and was skipped without overwriting it. One archived source row and 15 technical/non-directory rows were excluded. No duplicate official IDs or invalid required fields were found. For 600 employees with unknown source teaching flags, the target's non-null false default was used; this is not verified non-teaching status and should be refreshed from authoritative MIS/HR data.

The source uses a separate read-only session. Apply revalidates the source/schema fingerprint, acquires an import lock, and inserts people plus linked profiles in one transaction. Each generated ID is captured before any subsequent database statement. A post-import check confirmed all pre-existing directory rows and **34 non-directory target tables** were unchanged; a repeat dry run planned **zero** additional inserts. Target directory totals immediately after import were **2,006 students and 605 employees**. This does not create login accounts; authentication and later authoritative refreshes remain MIS-owned.

The pre-import backup and aggregate-only import report are stored privately under `writable/backups/`:
- `humus_db_before_directory_import_20261015_225220_c1231f.sql` (with checksum manifest; dump completed, not separately restore-tested)
- `directory_import_applied_20261015_225415.json`

The local CLI runner `php writable/directory-import-run.php` defaults to a dry run and prints only aggregate counts/hashes, never individual directory records. An apply additionally requires `--apply`, an unchanged reviewed source fingerprint, and a fresh checksummed pre-import SQL backup. Keep dumps and raw plans out of source control; never serialize the import plan containing personal information.

---

## Database Schema & Optimizations

SYNAPSE uses normalized, tenant-scoped application tables in `humus_db`. The inventory ledger migration adds `inventory_operations` and `stock_movements` to the existing schema:

```
├── Identity & Core
│   ├── tenants                     (Multi-tenant root container; default ID 1 = Foundation University)
│   ├── people                      (Canonical person: student, employee, or guest)
│   ├── student_profiles            (FK to people: program, year level, section, department)
│   ├── employee_profiles           (FK to people: department, position, status, is_teaching flag)
│   ├── health_profiles             (Clinic-owned: blood type, allergies, conditions, emergency contacts)
│   ├── users                       (Authentication accounts, FK to people.id, role, status)
│   ├── permissions                 (33 granular permission codes)
│   └── role_permissions           (Role-to-permission mapping for 11 system roles)
│
├── MIS Reference Mirrors
│   ├── mis_departments             (Authoritative department registry)
│   ├── mis_programs                (Academic degree programs)
│   └── mis_year_levels             (Year level codes)
│
├── Unified Scheduling & Daily Queue Kernel
│   ├── department_staff            (Person-linked department enrollment; no login account required)
│   ├── provider_availability       (Recurring weekly shifts and slot durations)
│   ├── availability_exceptions     (Staff leaves, holidays, custom hours)
│   ├── appointments                (Manila wall-clock visits + local date index; see scheduling contract)
│   ├── daily_ticket_counters       (Atomic sequence counter per unit/day preventing queue race conditions)
│   └── queue_entries               (Daily tickets: C-001, G-001 with priority, status, and 60m skip timer)
│
├── Clinic Care Module
│   ├── clinic_encounters           (Visit container: in_progress -> completed / referred)
│   ├── clinic_vitals               (BP, pulse, temp, SpO2, weight, height, auto-computed BMI)
│   ├── clinic_treatments           (Diagnosis, treatment plan, procedures rendered)
│   ├── clinic_medicines            (Medicines catalog with stock tracking)
│   ├── clinic_medicine_batches     (Batches with expiration dates for FEFO allocation)
│   ├── clinic_dispensations        (Per-batch allocations with historical medicine/lot snapshots)
│   ├── inventory_operations        (Idempotent receipts, dispensing, removals, quarantine and corrections)
│   └── stock_movements             (Append-only signed batch deltas, including identified cutover balances)
│
├── Guidance & Counseling Module
│   ├── counselling_cases           (Longitudinal student case containers: GC-YYYY-XXX)
│   ├── counselling_sessions        (Session consultation tracking)
│   ├── counselling_notes           (AES-256-GCM encrypted notes with drafts and version history)
│   └── guidance_followups          (Student action commitments and due dates)
│
├── Care Coordination Bridge
│   └── referrals                   (Bidirectional bridge between Clinic and Guidance)
│
└── Facilities (BMG) & Governance
    ├── bmg_units                   (Mechanized tumbler drums)
    ├── bmg_batches                 (Compost batch processing cycles with mass invariant check)
    ├── bmg_process_logs            (Temperature, moisture, oxygen, pH observations)
    ├── bmg_devices                 (ESP32-S3 IoT microcontroller hardware identities)
    ├── bmg_device_runs             (Idempotent motor turning run sessions from hardware)
    └── audit_events                (Append-only SHA-256 hash-chained immutable audit ledger)
```

### Core Architectural Invariants
1. **Zero Cross-Module SQL JOINs:** `clinic_*` and `counselling_*` tables never join directly. The only bridge is `referrals` using `patient_person_id`.
2. **AES-256-GCM Note Encryption:** Counseling notes are stored as binary ciphertext with an authentication tag and versioned keys (`COUNSELLING_KEY`). Any tampering with ciphertext or nonce causes tag verification failure.
3. **FEFO Inventory Allocation:** Medicines dispensed during clinical encounters are automatically deducted from the earliest-expiring batch first.
4. **Compost Mass Conservation:** Composting batch completion asserts `output_weight_kg <= total_input_weight_kg`.
5. **Cryptographic Audit Ledger:** Legacy v1 hashes remain verifiable; new v2 hashes cover a fixed-order JSON representation including tenant, action, actor role/IP, payload, time, and predecessor. Tenant-first locking serializes cooperating writers. After the hardening migration, triggers reject row updates/deletes and new legacy-format inserts. Inventory has a separate append-only operation/movement ledger; not every application mutation is hash-chained. See the [audit format, limits, and maintenance-cutover runbook](docs/AUDIT_LEDGER.md).

---

## Module Capabilities

### Department-managed appointment requests

Clinic and guidance use distinct request wording and service types, with a shared scheduling backend. A request is **not** a confirmed reservation:

`requested → confirmed + staff assigned → checked_in → completed`

Existing `scheduled` appointments are retained. Requests and accepted appointments can be cancelled before check-in. Pending requests appear in a department inbox independently of its calendar date filter; personal portals display all actionable requests/bookings plus the ten most recent historical appointments.

- **Clinic form:** patient, preferred date/time, general visit / follow-up / referred visit, optional brief reason. No provider selection.
- **Guidance form:** student/employee, preferred date/time, first session / follow-up / referred session, optional brief concern with a privacy reminder. Staff review counselor continuity for follow-ups; there is no automatic counselor assignment.
- **Personal booking:** the signed-in account's linked MIS person is fixed server-side; no person search or provider selection. Missing/invalid account linkage is rejected.
- **Review & Confirm:** department staff choose an eligible clinician/counselor and a currently available slot. Only confirmation reserves staff capacity. The confirmer and assigner are recorded separately from who later calls a queue ticket. Calling a ticket does not assign an appointment.
- **Automatic staff discovery:** Staff Availability lists local employees by exact department after trimming/case normalization: **`Health Services` → Clinic Workstation**, **`Guidance` → Guidance Workstation**. Stored positions are shown for a manager to review when choosing appointment staff. Active employees can be enrolled for scheduling even without a local login account. Discovery and enrollment never create accounts or grant permissions. No live MIS call is made.
- **Department hours template:** managers choose actual weekdays, start/end times, and 15/30/45/60-minute slots once, then apply them only to explicitly selected staff using row checkboxes or **Select all eligible staff**. A live selected count is shown; selection starts empty and submission is disabled until someone is selected. Only active, explicitly enrolled department employees can be selected for hours. Login accounts and local roles are not eligibility requirements for the employee being scheduled. The server requires a nonempty selection of `staff_assignment_ids[]` and rejects inactive, deleted, foreign-tenant, or other-department assignments; omission never falls back to the whole department. The action adds only missing staff/weekday windows, preserving inactive and split-shift schedules. It is transactional, locks providers consistently with individual edits, and is safe to repeat. Reapply after new eligible staff join; it is not a background schedule generator or a saved organization-wide template. Leave/exception records continue to apply. The person-based assignment migration described below is required.
- **Staff Availability workspace:** opens as a dedicated page at `/clinic/appointments/availability` or `/guidance/appointments/availability`, not the generic form modal. Department Setup, Weekly Schedules, and Leave & Exceptions are separate views with staff-name/provider filters. Authorized managers can configure recurring weekday windows and explicitly add/remove future leave, closed-day, or custom-hour exceptions. Changes are tenant/unit scoped, use a Manila date, require an explicit manager role and scheduling grant, and use a snapshot token for updates/removals. They do not silently cancel booked appointments. The employee must have an active scheduling assignment and profile in the matching department; no login role is required for the employee being scheduled. Focused Bootstrap dialogs handle only one weekly adjustment or one exception. The custom datepicker and time controls keep ISO/24-hour POST values. Provider/search/day/section context is validated separately from mutation authority and carried back to the fixed same-department workspace URL after saves. Name search updates after a 300 ms debounce, provider changes apply immediately, and Reset updates the results automatically. Filtering uses GET requests, keeps the search field/focus mounted, cancels superseded requests, and updates the URL without a full page reload. Errors retain the previous results and offer Retry. Automatic filtering pauses for unsaved availability edits, selected staff, or an open editor; Apply filters is then shown so an explicit discard confirmation can protect those drafts. Returned results must match the current department and navigation identity scope before replacing the section. Errors retain form values and refresh CSRF tokens; saves are not automatically retried. Past exceptions and inactive enrollments remain read-only, updates/removals retain snapshot tokens, and removal requires confirmation. Select-all applies only to the displayed eligible rows, never an implicit entire department. A successful save reloads the workspace to refresh roster options, schedules and exception tokens; unsaved drafts in other forms are not persisted. Existing appointments are never automatically cancelled or rescheduled.

| Surface | Routes |
|---|---|
| Department request/list | `/{clinic\|guidance}/appointments`, `/create`, `/store` |
| Staff confirmation | `/{unit}/appointments/{id}/confirm` (GET dialog, POST confirmation) |
| Availability management | `/{unit}/appointments/availability` (GET full-page workspace, POST individual save) |
| Scheduling enrollment | `/{unit}/appointments/availability/enroll` (`person_ids[]`, CSRF-protected POST, managers only) |
| Department hours template | `/{unit}/appointments/availability/template` (`staff_assignment_ids[]`, CSRF-protected POST, managers only) |
| Personal request | `/portal/appointments/{unit}/create`, `/store` |
| Personal cancellation | `/portal/appointments/{unit}/cancel/{id}` |

**Server guarantees:** department lists/actions require an active same-tenant staff account; personal cancellation requires ownership. Appointment mutations validate the tenant and route department. Confirmation locks the underlying employee person, department assignment, and request; rechecks the request version, exact current slot, enrollment/employee eligibility, and overlap; then assigns atomically. The person mutex and conflict query prevent overlapping bookings across department assignments for the same employee. Concurrent double confirmation/booking is rejected; repeated check-in produces one appointment-linked ticket. Appointment POST routes enforce CSRF validation and return refreshed tokens with JSON responses. These protections are specific to appointments, not a claim that every legacy endpoint has been audited.

**Migration:** `2026-10-15-000001_AddAppointmentRequests` makes the appointment provider nullable, adds the `requested` state, confirmation/assignment audit fields and version, and a unique appointment-linked queue ticket constraint. Run `php spark migrate --all` on each deployment after a backup. Duplicate legacy tickets cause an explicit preflight failure; history is not deleted automatically. The migration was applied to the local development `humus_db` during implementation.

### Person-based scheduling enrollment

In **Appointments → Staff Availability**:

1. Select employees in the **Enroll** column (or select all unenrolled employees), then choose **Enroll Selected Employees**. A manager reviews department/position and decides who actually provides consultations. Enrollment does not create login credentials, local users, or workstation grants.
2. The availability dialog reloads. Enrolled employees appear as **Ready to schedule**. Select them in the **Schedule** column, choose working days/hours/duration, and apply. Existing windows are preserved; use individual adjustments to change an existing single-window weekday.
3. Configured staff show **Schedule configured**. They can be assigned to confirmed appointments and checked in without first logging into Synapse. Their eventual MIS login reuses the same person, so assignments/schedules remain attached without duplicates.

The statuses **Not enrolled for scheduling**, **Ready to schedule**, **Schedule configured**, **Inactive employee**, and **Inactive assignment** describe scheduling state—not whether a university MIS account exists. Inactive/deleted employees and inactive assignments cannot receive new allocations. Staff assignment status management and leave/multi-window editors remain separate follow-ups; enrollment does not reactivate an inactive assignment.

**Migration:** `2026-10-16-000001_AddDepartmentStaffAssignments` adds unique `(tenant_id, person_id, unit)` enrollment, manager provenance, and `staff_assignment_id` references on appointments, availability, and exceptions. Legacy `provider_user_id` values and actor audit references are retained. Existing referenced employee identities are backfilled; shared legacy exceptions are replicated for their department assignments without removing the original row. Ambiguous or invalid legacy links stop preflight instead of creating dummy people/accounts. Composite FKs enforce assignment tenant/unit consistency. The migration is forward-only; do not use rollback or automatic table recreation.

The migration was applied to the local development `humus_db` after a native backup and an isolated restore rehearsal of **37 tables / 5,441 rows**. Original row fingerprints, two appointments, 2,611 people, one user, profiles, role grants, and audit history were verified unchanged. The maintenance flag is cleared. No actual employees were automatically enrolled and no working hours were invented: read-only checks found **8 Clinic employees and 6 Guidance employees eligible for enrollment**.

Development deployment helpers live under `writable/`: `staff-assignment-development-migrate.php` requires a verified backup, restore-rehearsal and stopped-write acknowledgements, applies only this migration, and verifies original row fingerprints. `staff-assignment-development-smoke.php` is read-only. The bounded isolated harness supports `--validate-staff --backup-stem=<literal backup stem under writable/backups>` for current-data rehearsal. Stop all writers before DDL; the browser maintenance flag blocks scheduling requests but is not a substitute for stopping independent CLI/background writers. On failure, the flag remains in place—verify/repair explicitly, never auto-rollback. If DDL succeeded but verification stopped, `--verify-only --evidence=<original fingerprint JSON>` rechecks against the saved pre-DDL evidence without reapplying the migration.

**Scheduling API compatibility:** new mutations and slot requests use the explicit `staff_assignment_id`; bulk hours use `staff_assignment_ids[]`. Enrollment uses employee `person_ids[]`. Old `provider_user_id`/`provider_id` request fields are not reinterpreted as assignment IDs. Update any external callers before deployment; the mobile rebuild remains deferred.

**Time compatibility:** existing application-timezone wall-clock timestamps are preserved; this change does not reinterpret old records as UTC. Date pickers receive the server's explicit minimum ISO date rather than browser-local `today`. Confirm the intended application timezone before deployment. Request times are preferences with a provisional 30-minute interval; the final duration comes from the selected staff schedule.

Status updates and confirmed date/time ranges are visible in the personal portal, with the assigned staff name when available. External email/SMS/device-push notifications, automated continuity assignment, multi-window/leave editing, and a separate reassignment/rescheduling workflow are not introduced here. Staff can choose a different available time while confirming a request.

### Personal appointment notifications

New appointment confirmations now create a persistent **in-app notification** for the patient. The header uses the existing **NiceAdmin v3** `notification-dropdown` / `notification-menu` component, with a Bootstrap bell icon, unread badge, recent items, and **Mark all read**. It contains no demo counts or unrelated summary cards.

- **What the patient sees:** **My Appointments** shows the service, final date, start/end time, assigned staff (when present), and **Confirmed** status. Pending requests retain “Preferred time—not yet confirmed.” Clicking a notification opens `/portal#my-appointments`; the portal table is refreshed through navigation, not independently live-polled.
- **Delivery:** the bell refreshes at page load, when opened, on returning to a visible tab, and every **30 seconds while visible**. Polling pauses in hidden tabs and stops on navigation. This is not WebSocket instant delivery, email, SMS, or OS/device push. Notifications remain available for the next sign-in if the person is offline.
- **Identity and confidentiality:** ownership is `(tenant_id, recipient_person_id)`, not a login user or department role. Patients without a local user link can receive a notice and see it after their first successful MIS login. Appointment notices remain personal; receiving-team referral notices are a separate account-owned, currently authorized channel described below. Superadmin does not bypass notification ownership. A person-less break-glass account has an empty inbox. Notification content is generic and excludes service-specific concerns, appointment reasons, diagnoses, and counseling notes; appointment details remain in the authenticated personal portal.
- **Atomic delivery:** confirmation and notification insert share one database transaction. A notification insert failure rolls back confirmation. A unique appointment/type/version key prevents duplicate notices for a repeated event. Merely requesting or checking in an appointment does not create a confirmation notice; cancellation notifications are not part of this slice.
- **Read state:** opening the dropdown does not mark anything read. Item clicks and **Mark all read** use CSRF-protected POSTs, with shared token synchronization. A failed read request shows an error and does not navigate or optimistically clear unread items. Read state persists across sessions/devices. The menu shows the latest ten notices and `99+` when the unread count reaches its capped query limit of 100; Mark all read applies to the full personal inbox.
- **UI safety:** light/dark and mobile layouts reuse the new NiceAdmin styles. The bell tooltip is disabled while its menu is open so it cannot cover actions. JSON content is rendered as text, navigation is restricted to the exact configured personal-portal or clinic/guidance incoming-inbox URLs, and notice content is not stored in local/session storage. Private API responses use `Cache-Control: private, no-store`.

| Notification route | Behavior |
|---|---|
| `GET /notifications` | Latest personal notices and unread count; no write side effects |
| `POST /notifications/{id}/read` | Mark an owned notice read; foreign/missing IDs return 404 |
| `POST /notifications/read-all` | Mark the current person's personal inbox read |
| GET on either read action | 405, no state changes |

**Migration:** `2026-10-16-000002_CreateNotifications` adds the notification table, same-tenant foreign keys, inbox index, and unique confirmation-event key. It was applied to local `humus_db` after a fresh native backup and an isolated restore rehearsal of **38 tables / 5,443 rows**. Existing data was fingerprint-verified unchanged and maintenance was cleared. The notification table starts empty: old confirmations are **not** retroactively backfilled. No live MIS calls, mail/SMS providers, or external messaging credentials are required for these in-app notices.

Development helpers: `notification-development-migrate.php` (guarded exact migration), `notification-development-smoke.php` (read-only), and `python writable/isolated-inventory-harness.py --validate-notifications --backup-stem=<literal verified backup stem>` (bounded restore rehearsal). Browser validation: `node --test tests/browser/notifications.test.cjs`.

### Student and employee profile settings

`/portal/settings` is personal self-service for an active account linked to a same-tenant student or employee person, including staff acting as employees. The sidebar and account dropdown open it as a medium, centered, scrollable shared Bootstrap modal over the current screen. `?fragment=1` returns only modal markup; direct `/portal/settings` links open the same initialized dialog with a fixed return to `/portal`. University name, identifier, email, and profile type are read-only; uploading a photo never edits MIS identity, roles, grants, or clinical records. Person-less, guest, inactive, deleted, and unauthenticated identities cannot use the photo endpoints.

- **Upload:** POST `/portal/settings/photo`, multipart field `photo`, accepts actual JPEG/PNG/WebP images up to **10,485,760 bytes (10 MB)**, inclusive. Both original file size and actual MIME/image type are checked on the server. SVG, GIF, HEIC, corrupt/partial uploads, images above 20 megapixels/10,000 pixels per side, and unsafe memory estimates are rejected before persistence. Client checks and preview are convenience only.
- **Processing:** GD resamples without upscaling to fit within 1024 × 1024. WebP compression starts at quality 82; complex images can drop to 768 or 512 pixels to guarantee a stored size of at most **307,200 bytes (300 KB)**. JPEG EXIF orientation is applied; re-encoding into a fresh canvas strips metadata, including location data. Originals are not retained by the application.
- **Storage/ownership:** only the compressed image lives in `writable/profile-photos/<tenant>/<person>.webp`, outside `public/`. Identity is refreshed from the database, and all owner IDs come from that identity, never query or POST values. GET `/portal/settings/photo` serves only the signed-in person's image (or the default avatar) with private/no-store, an explicit WebP (uploaded photo) or SVG (generated default) MIME, and nosniff headers. There is no public or arbitrary-person photo URL. The header uses the personal photo when present through the same-origin protected `account/photo` GET alias, so workstation avatars do not depend on transferring a session cookie to the main portal. The same-filesystem temporary file is renamed over the prior photo only after successful processing, so validation/encoding/storage failures do not delete the old picture.
- **Default avatar:** a locally generated circular SVG uses solid maroon `#800000` with white first/last initials in both themes. Initials come from the actual first and last name fields, not middle names or the final word of a compound surname. Fresh authentication supplies the same initials to the header; settings and the owned-photo endpoint derive them from the same person record. The header, account menu, and settings use this fallback when no photo is saved or an image cannot load. No external avatar service or new files are needed, and uploaded photos still take precedence. Removing a picture restores the initials avatar.
- **Modal editing:** `public/assets/js/profile-settings.js` is loaded once by the shared layout and initialized/disposed by the modal manager. Selecting an unsaved file makes X/Close ask for discard consent; Cancel keeps the file and preview. Failed uploads retain the selection and preview and synchronize refreshed CSRF tokens for an explicit retry. In-flight saves block duplicate writes and dismissal. AJAX upload/removal results update the dialog and both header avatars in place with an owned, cache-busted photo URL or the existing initials fallback; neither the underlying page nor its URL is replaced. Native multipart POST remains available as a fallback and redirects only to the fixed settings route.
- **Removal:** POST `/portal/settings/photo/remove`, CSRF-protected with the shared confirmation dialog. Only the current person's photo is removed. There is no destructive GET; confirming removal also explicitly discards any selected new picture.
- **Deployment:** GD with JPEG, PNG, and WebP support is required. Confirm the **web SAPI** allows `upload_max_filesize >= 10M`, `post_max_size >= 12M`, and a compatible proxy request-body limit (allow multipart overhead); CLI settings are not proof of web-server settings. The application still enforces its own 10 MB limit. Global upload limits were not changed because other modules have different requirements. Make the private photo directory writable only by the application and include it in backups. Multiple app instances need a shared private volume. No database migration or permission reseed is needed.

Validation commands: `php vendor/bin/phpunit --configuration phpunit.ci.xml tests/unit/ProfilePhotoServiceTest.php`; `python tests/_support/run-fresh-database-tests.py tests/unit/ProfileSettingsAccessTest.php`; `node --test --test-concurrency=1 tests/browser/profile-settings.test.cjs`. The database runner uses a disposable schema; presentation fixtures never write real accounts or photos.

### Live list and portal filters

`public/assets/js/live-filters.js` progressively enhances personal Announcements and My Surveys, Teams task boards/lists and calendar filters, and the Guidance question-bank library. Text/category searches debounce for 300 ms; dropdowns and selected calendar dates apply immediately. Reset, status/view tabs, and pagination update without a full reload. Search/Apply buttons remain optional explicit-submit and no-JavaScript fallbacks.

The GET response remains server-authorized. Only uniquely keyed `data-filter-region` sections are imported; persistent filter fields keep their DOM identity, focus, and caret. Responses must match the endpoint, filter key, navigation identity scope, and region names. Script/embedded content and redirects are rejected. Superseded reads are aborted and ignored, errors keep old results with Retry, and open editors or unsaved result-form changes pause replacement. Approved writes still use the existing forms and CSRF handling; filtering never POSTs. Calendar New event links stay aligned with the selected date. URL history is shared with `SynapseNavigation`; Back/Forward restores the canonical page.

The survey builder's bank picker separately debounces query/category input, applies audience changes immediately, resets to page one, and preserves selected question snapshots across searches/pages. It handles IME, stale responses, timeout, close, and Alpine teardown without changing the survey draft.

Audit: patient, medicine inventory, referrals, employee access, and availability already have live search. Management announcement/survey tables retain their existing client-side live table search. Appointment-date and encounter/case status controls already auto-submit; they are not submit-only search bars.

Run browser suites serially: `live-filters.test.cjs`, `survey-bank-live.test.cjs`, and `survey-builder-state.test.cjs`; real-template live-filter checks are in `engagement.test.cjs`, `teams.test.cjs`, and `team-calendar.test.cjs`.

### 1. Clinic Care Module

#### Server-side patient and medicine tables

`/clinic/patients` and `/clinic/inventory` now use server-side pagination and 300 ms debounced live search while retaining the existing Bootstrap/NiceAdmin table styling. These two tables do not rely on Simple-DataTables client-side filtering: only the requested page is sent to the browser, and searches/counts cover the full authorized dataset.

- **Page sizes:** 25 (default), 50, or 100. First/Previous/windowed page numbers/Next/Last preserve search, type/status and page size. Out-of-range pages clamp to the last matching page; empty results use page 1 and a 0–0 range.
- **Patient search:** official ID or name tokens, with all/student/employee/guest filtering. Results are ordered by surname, first name and ID for stable paging. Same-tenant profile joins and current account/permission checks prevent extra or unauthorized rows; Start Visit is shown only with the current create-encounter grant.
- **Medicine search:** name/generic name plus active/archived/low-stock filtering, ordered by name and ID. Stock alert cards count all filtered medicines across all pages, not just the displayed page. Counts do not add quantities across different stock units.
- **Interaction:** filter/page-size changes and new searches start at page 1. Pending requests are canceled immediately when newer text is typed; stale results cannot replace newer ones, including during the debounce or IME composition. A page click during pending text searches the new term instead of discarding it. Retry preserves the original history behavior; Back/Forward restores rows and custom-select labels, including equivalent `index.php` path aliases.
- **Fallbacks and errors:** Search/Filter buttons and page links remain ordinary GET navigation without JavaScript. Live loading uses `aria-busy` and a status announcement; failures retain prior results with a clear error and Retry. Redirected sign-in/denied responses and malformed HTML are not injected into the table. Canonical browser URLs omit the internal `fragment=1` flag.

`app/Libraries/TablePage.php` strictly validates `q`, `page`, `per_page`, `kind`, `status` and `fragment`. Existing authorized GET routes return either full pages or a results-only `?fragment=1` response, with private/no-store caching. The shared `public/assets/js/live-table.js` is loaded only on these pages. Partial templates use `partials/table_pagination.php`; no additional table dependency or database migration was introduced.

Validation: the isolated inventory/pagination suite passed **306 tests / 2,948 assertions**, and combined live-table/inventory browser coverage passed **50 tests**. Read-only checks against development `humus_db` verified the **2,611-person** directory (105 pages at 25 per page), all three page sizes, finding a record beyond the old 50-row cutoff, and full/fragment rendering for both tables. No development records were modified by those checks.

- **Walk-in Patient Flow:** Fast type-ahead search across local database and MIS directory with automatic ticket issuance.
- **Kanban Queue Board:** Real-time waiting room columns (`Waiting`, `Called & Arriving`, `In Consultation`, `Completed`, `Skipped`). Auto-refreshes every 15 seconds via HTMX.
- **4-Step Consultation Stepper (`/clinic/encounters/{id}`):**
  - *Step 1: Triage & Vitals:* Blood pressure with high-BP warnings, temperature with fever indicators, and auto-calculated BMI.
  - *Step 2: Assessment:* Chief complaint, physical observations, clinical diagnosis, and treatment plan.
  - *Step 3: FEFO Dispensing:* Real-time stock availability with automatic expiration-based batch deduction.
  - *Step 4: Outcome & Referral:* Final disposition (`discharged`, `sent_home`, `admitted`, `referred_to_guidance`) with automated queue completion.
- **Patient Health Charts:** Comprehensive history of past visits, vital trends over time, chronic conditions, and emergency contacts.

#### Medicine inventory (`/clinic/inventory`)

Medicine-only inventory is separate from general medical supplies. The catalog records generic name, strength, dosage form, explicit whole-stock unit, reorder threshold, and active/archived status. A stock unit is a tablet/capsule/bottle/etc.; no fractional dosing or implicit pack conversion is supported. Generic identity, strength, dosage form and unit freeze after a batch exists. Catalog updates use optimistic versions; names, categories, thresholds and archival status remain editable.

**Workflow:** add a catalog item → receive a lot with full expiry date, quantity, supplier/reference → inspect batches and usable-stock alerts → dispense during an open clinic encounter. Inventory list aggregation uses two queries rather than one query per medicine; ledger movements are paged in groups of 100. Latest operations are explicitly limited to 100 in the item detail view.

- **Stock figures:** physical on-hand includes expired/quarantined quantities. Usable stock excludes archived medicines and expired/quarantined batches; near-expiry means usable stock expiring within 90 days. Expired and quarantined subsets may overlap and must not be added together. The retained expiry policy permits a lot on its printed expiry date and blocks it on following dates, using the existing application timezone.
- **Receiving:** recorded through a stock operation, never by editing a balance. Additional receipts for an existing lot require the same expiry date and active batch status. Expired receipts and archived medicines are rejected. `MedicinesSeeder` is now catalog-only; it does not create or replenish demo stock.
- **Dispensing:** requires an active database account, current inventory read/write grants, and a same-tenant open encounter/patient. One transaction locks the encounter and medicine, allocates batches by expiry then ID, records historical descriptors, appends negative movements, and updates the physical cache. Completion locks the same encounter. Existing history is displayed using allocation snapshots; legacy reconstructed descriptions are labelled as such.
- **Corrections:** managers may append one full correction per ledger dispensation. `record_only` leaves stock unchanged. `restore_unissued` explicitly affirms that every unit was never issued and remained in clinic custody; the server requires active, unexpired lots and an active medicine. Patient returns must not be restocked. Partial corrections and legacy allocations without operation IDs are not supported.
- **Removal/quarantine:** managers may remove a positive quantity for damage, expiry or a documented count discrepancy, and quarantine/release a batch with a reason. Adjustments are removal-only; unexplained positive stock is not added as a count correction. Quarantining does not change physical quantity; expired lots cannot be released.

**Access:** inventory readers/writers must have an active same-tenant `clinic_staff` or `clinic_admin` account and fresh `clinic.inventory.read` / `clinic.inventory.write` role grants. Managers are `clinic_admin`; active platform superadmins bypass role grants. Catalog changes, removals, batch status and corrections require manager access; receiving/dispensing require write access. Encounter chart read/completion permissions remain separate from inventory grants. UI action hiding is not an authorization boundary.

**Integrity:** stock operations and new catalog creation use tenant-wide unique retry keys and canonical payload hashes. Matching retries do not create another operation; conflicting payloads or actors are rejected. Catalog edits additionally reject stale versions. All inventory POSTs and encounter dispensing/completion/ticket-start POSTs validate CSRF. Ticket-based clinic encounter creation now uses POST; its former GET returns 405 without mutation. Ledger and allocation UPDATE/DELETE triggers, restrictive tenant-consistent foreign keys, and quantity constraints preserve history. Each stock writer checks the cached total against physical batches and checks each batch against its movement sum; discrepancies stop the operation instead of being clamped away.

**Local deployment complete:** `2026-10-15-000002_AddClinicInventoryLedger` was applied to development `humus_db` after an isolated backup-restore/migration rehearsal and passing inventory tests. A fresh pre-cutover backup is retained at `writable/backups/humus_db_before_inventory_20261015_221324_5d9476.sql` with a SHA-256 manifest. The earlier 21:42 backup was independently restored and its 35 table/221 row counts verified; the fresh dump completed successfully but was not separately restore-tested. Local read-only checks confirm the list, catalog form, item details and receiving form render successfully, all six append-only triggers exist, and per-batch ledger balances agree. This is not a production deployment.

**Deployment requirements:** It requires InnoDB plus MySQL 8.0.16+ or MariaDB 10.2.1+ with enforced CHECK constraints and trigger privileges. Before DDL, it rejects duplicate lots, invalid tenant/medicine/encounter/actor relationships, invalid quantities, and cache/batch discrepancies. It never silently repairs or deletes data. Existing balances become explicitly labelled `legacy_cutover_opening_balance` movements with no invented receipt or actor. Existing allocation snapshots are marked reconstructed. MySQL DDL auto-commits: back up first, use a maintenance window with stock writers stopped, run the database/concurrency tests in an isolated `*_test` schema, then run `php spark migrate --all`. Do not use the new screens against an unmigrated schema. Downgrade refuses to remove populated history; use a verified pre-cutover backup for recovery.

Validation commands:

```bash
# Database-free contracts, parsing, strict payload validation and list aggregation
php vendor/bin/phpunit --no-coverage --filter 'InventoryValidationTest|InventoryMigrationVersionTest|InventorySummaryTest|InventoryRouteContractTest'
# Live isolated MySQL is required; the safety gate refuses the default database
php vendor/bin/phpunit --no-coverage --filter 'InventoryServiceTest|InventoryConcurrencyTest|InventoryRoutesTest'
# Real templates/runtime, fixture-controlled responses; no database required
node --test tests/browser/inventory.test.cjs
```

The database-backed suite includes FEFO/expiry, cross-tenant and stale-account rejection, rollback fault injection, concurrent competing dispense/receipt/correction requests, idempotent replay, history/constraint protection and migration-gate checks. The combined inventory suite passed **224 tests / 1,949 assertions** on a fresh schema in a separate, bounded MariaDB instance on port 33307; it did not run against development stock. The instance was shut down after testing. Browser fixtures separately verify presentation and interactions, not stock persistence or SQL locking.

**Partial deployments:** inventory routes return a theme-aware 503 setup-pending screen, and stock POSTs fail closed, until required schema and the completed migration marker exist. Readiness refreshes shared connection metadata before caching its per-service result, so a connection used during migrations cannot incorrectly retain the old schema. Existing encounter history remains readable while inventory setup is pending. No web request automatically runs a migration.

### 2. Guidance & Counseling Module
- **Case-Centric Care:** Longitudinal student cases (`GC-YYYY-XXX`) grouping multiple sessions under an ongoing counseling engagement.
- **Confidential Note Workspace:** Active session editor with autosaving drafts and immutable finalized version history.
- **Follow-up Commitments:** Student action items with target due dates and completion checkboxes.
- **Availability Templates:** Manager-only weekly schedule configurator; existing leave exceptions are respected by appointment slots.

**Concurrency and numbering:** guidance mutations use short tenant-first transactions. Case numbers are allocated under the tenant mutex from a current read including soft-deleted cases; session numbers use `MAX + 1` including soft-deleted sessions; finalized note versions use `MAX + 1` under a session lock, and each session has at most one version-0 draft. Queue-linked intake validates the ticket, patient, appointment, and case as one transaction; closing a session completes its linked queue ticket and appointment atomically. Unique indexes on session number and note version are the database backstop. See `docs/GUIDANCE.md` for the lock order, migration preflight, and maintenance cutover. Migration `2026-10-19-000004_HardenGuidanceNumbering` is validated on disposable MariaDB only and has **not** been applied to the operational database; existing duplicate or inconsistent numbering stops the migration instead of being rewritten.

### 3. Workstation Referrals

**Entry points:** `/clinic/referrals` and `/guidance/referrals`, each available from its own sidebar. Both use one shared referral engine with department-specific routing and reasons.

- **Automatic routing:** Clinic staff send **Clinic → Guidance**; guidance staff send **Guidance → Clinic**. The form shows a read-only route, not source/target dropdowns. The server derives routing from fresh identity/permissions and the authorized workstation, rejecting contradictory submitted routing. A platform owner must explicitly choose a workstation; `/referrals` no longer silently assumes a source. Legacy `?target=clinic|guidance` bookmarks redirect to the matching authorized workstation.
- **Simple form:** student/employee, destination-appropriate reason, priority, and a required brief handoff summary (1–2,000 characters). Guidance reasons describe support; clinic reasons describe medical assessment/follow-up/health advice. No full case notes or consultation records are copied into the form. Routine/Urgent/Crisis are priorities, **not enforced response-time guarantees**. Emergencies require direct contact and university emergency procedures, not reliance on this form.
- **Patient context:** Clinic charts/visits offer **Refer to Guidance** with a same-patient prefill; visits retain their source encounter link. Guidance case pages offer **Refer to Clinic** with the person prefilled. Submitted IDs are revalidated; a source encounter must belong to the same tenant/person and clinic source. No raw diagnosis or counseling note is automatically transferred.
- **Folders:** **Incoming** contains nonclosed referrals addressed to the workstation; **Sent** contains nonclosed referrals sent by it; **Closed / History** contains closed referrals involving it. Creation returns to **Sent**, so the new referral remains visible. Lists have server-side pagination (25/50/100), debounced live search, count summaries, and tab-preserving history/retry behavior. Search covers reference/person/sender identity, not clinical content.
- **Status-aware receiving actions:** Submitted → **Acknowledge**; acknowledged → **Start review**, handoff, or close; under review → handoff or close. Sent rows have no receiving actions. Closing a referral does **not** close its linked clinical visit/case. History is read-only apart from authorized links to existing clinical records.
- **Clinic handoff:** after acknowledgment, **Start / Link Visit** opens a protected modal. Staff may link a same-person, same-tenant open clinic visit, or explicitly start one when ready to begin care. No appointment or queue ticket is automatically created.
- **Guidance handoff:** after acknowledgment, **Open / Link Case** opens a protected modal. Staff may select an accessible active case or enter a title/category for a new case. Current case assignment/read-any permission controls access; referral assignment alone does not grant case access. Source-side responses and unauthorized receiving responses omit private clinical record IDs. Legacy converted referrals with no saved target link require explicit reconciliation, not another automatic case creation.
- **Retries and concurrency:** HTTP creation requires an opaque request key. An identical retry returns the original referral; changed data with the same key conflicts. Tenant mutexes/current reads serialize referral and guidance case numbering. Referral/target locks prevent duplicate downstream handoffs. Ordinary guidance case creation shares the numbering mutex. Failed SQL transactions reset only their owned transaction state, permitting safe same-connection retries; nested referral transactions reject without disturbing their caller.
- **Receiving-team bell notices:** New referral creation atomically inserts a generic notice for each active, same-tenant receiving staff account with the appropriate department role and `referrals.read` grant. No patient name, reason, medical details, or counseling notes appear in the notice. The bell combines these with personal appointment notices and routes referral clicks to that department's Incoming folder after a successful CSRF-protected read POST. Current role, grant, account/person status, tenant, and recipient ownership are checked again when viewing or marking notices. Revocation or department changes hide stale notices. Platform-owner/BMG/student/ordinary-employee roles are not automatically receiving-team subscribers. Newly granted staff still see authorized referrals in Incoming, but notices are not retroactively fanned out. These are in-app notices, not email/SMS/push.

| Route | Purpose |
|---|---|
| `GET /{unit}/referrals?tab=incoming\|sent\|history` | Authorized folder, search and pagination |
| `GET /{unit}/referrals/create` | Fixed-route referral modal |
| `POST /{unit}/referrals/store` | Idempotent, CSRF-protected send |
| `POST /{unit}/referrals/{id}/acknowledge` | Receiving-team acknowledgment |
| `POST /{unit}/referrals/{id}/review` | Begin review |
| `GET/POST /{unit}/referrals/{id}/handoff` | Receiving-team clinical handoff modal/save |
| `POST /{unit}/referrals/{id}/close` | Close referral coordination only |

**Migration:** `2026-10-16-000003_RebuildReferralWorkflow` adds nullable handoff target IDs and creation request keys, list indexes, and a separate account-recipient referral channel in the existing notifications table. Clinical references are validated in services without SQL joins between clinic and counseling tables. Existing referral fields, clinical records, notification content/read state, accounts, role grants, and audit rows are preserved. The migration is additive/restartable and forward-only; old referrals are not automatically converted or sent again.

Applied to local `humus_db` after a verified native backup and isolated restore rehearsal of **39 tables / 5,445 rows**. Original row fingerprints were verified unchanged, with one migration-history entry added; maintenance is cleared. Read-only development checks render Incoming/Sent/History/Create for both workstations without creating referrals, clinical records, accounts, or notices. No live MIS request was made.

Development helpers: `writable/referral-development-migrate.php` (guarded exact apply/verification-only recovery), `writable/referral-development-smoke.php` (read-only), and `python writable/isolated-inventory-harness.py --validate-referrals --backup-stem=<literal verified referrals backup stem>` (bounded current-data rehearsal). Stop independent writers before applying DDL; the temporary browser maintenance guard also covers referrals and notifications.

### 4. Facilities (BMG) — Mechanized Drum Rotation

**Entry point:** `/facilities` → **Drum Rotation**. This initial operational phase replaces the old sensor/compliance screens; existing historical tables remain untouched.

- **Drum configuration:** explicit code/name, required capacity in kg, and availability. Unit availability is not controller connectivity or motor state. Configuration needs fresh `facilities.configure` and `facilities.manage` grants; operators retain batch operations but cannot reconfigure drums.
- **Schedules:** up to eight distinct daily Manila times, duration 1–600 seconds, no overlapping windows including midnight. New schedules are disabled. Writes require the current `expected_version`; stale forms return 409. Saved intent and timestamped device-applied versions are shown separately.
- **Controller management:** hashed credentials, direct one-time/no-store token response, explicit binding cutoff, credential rotation/revocation. Tokens cannot act as human users. Old bindings are not automatically trusted or upgraded.
- **Manual controls:** queued run/stop requests with expiry, device-owned acknowledgement and history. An acknowledgement is a device claim, not independent evidence of movement. Web stop, revocation, maintenance, and the server gate are **not physical emergency stops** and cannot revoke instructions already cached by offline firmware.
- **Batch records:** exact decimal input bounded by capacity, optional measured output not exceeding input, complete/cancel with retained history. Batch creation/completion does not start/stop the motor. No automatic grade, maturity or sanitation claim is made. Creation notes are retained in audit metadata; completion/cancellation notes are shown in history. Past-batch history is bounded to 50; device reports and command history to 20 each.
- **Safe defaults:** `BMG_HARDWARE_ENABLED=false`; enabling schedules and issuing commands additionally require an explicit `facilities.hardware.manage` grant. Registration/credential rotation require configuration access only and remain available with hardware disabled; they disable the saved schedule and cancel pending commands without starting the motor. Neither migration nor fresh-install seeding assigns that grant, including to superadmin. Database-backed permissions, tenant locks, audit transactions, CSRF and inline form errors protect human operations.
- **Excluded:** temperature, moisture, oxygen, pH, environmental sensors, calibration and EPA/sanitation assessments. The retired `/facilities/batch/{id}/log` route no longer exists.

**USB workspace:** configuration admins can open **USB installer & serial monitor** from a drum's device section. Explicit Web Serial port selection, confirmed ESP32 identification and receive-only monitoring are available independently of motor control. A separate approved-release installer verifies downloads and flash readback; it requires explicit commissioning permission, `BMG_USB_FLASH_ENABLED=true`, motor control off, maintenance, disabled schedule, matching registered chip and fresh authorization. Both gates remain off and the approved catalog is empty until actual compatible firmware is reviewed. There is no editor/compiler, arbitrary upload, automatic registration or reboot. See [BMG USB commissioning](docs/BMG_USB.md) for browser support, private release configuration, physical safety and recovery.

**Remaining phases:** scheduled offline/missed-run alert processing and BMG bell notifications, broader reports/exports, team-assignment administration, and hardware/firmware acceptance. The initial phase has no background watchdog or BMG notification fanout; existing appointment/content/referral notices are unchanged. No live device or motor behavior has been verified.

**Local development deployment complete:** additive migrations `2026-10-17-000005_CreateBmgRotationOperations` and `2026-10-17-000006_AddBmgRotationPermissions` were applied after restoring the exact native backup to an isolated MariaDB instance. All original 43 tables/5,476 rows and column metadata were preserved; only two history entries, two permission entries and two configure grants were added, alongside empty rotation storage and nullable device fields. No hardware grants, device credentials, commands or notices were created. Both real overview/detail pages passed read-only full-layout checks; the hardware gate remains false. Backup: `writable/backups/humus_db_before_bmg_20261017_034029_63e3ad.sql`, SHA-256 `809e6688ba2e5bed9e39fb1060ba1b93f106d4d91121b303bd6e2df785ebb926`. Preservation evidence: `writable/backups/bmg_fingerprints_20261017_035928_c4c58f34.json`. Never rerun the role seeder against existing data. The guarded `writable/bmg-development-migrate.php` requires a SHA-bound native-backup restore rehearsal and preserves original rows/schema. Facilities and device endpoints return 503 while `writable/bmg-maintenance.flag` exists. Do not remove a failure-retained flag until verification succeeds.

Validation: disposable SQLite service tests (requires `sqlite3`), disposable MariaDB migration/route tests through `python writable/bmg-isolated-validation.py`, and real-template browser interactions through `node --test --test-concurrency=1 tests/browser/bmg.test.cjs`. No destructive suite should run against `humus_db`.

### 5. Cryptographic Audit Ledger
- **Current coverage:** announcement, survey, and BMG audit events. Ordinary logins, encounter creation, counseling-note edits, referrals, appointments, and queue mutations are not comprehensively recorded here.
- **Versioned integrity:** v2 hashes bind the previously omitted tenant/action/actor metadata; legacy hashes and payload bytes are preserved. All audited transaction wrappers acquire the shared tenant mutex before business writes.
- **Database protections:** migration `2026-10-19-000003_HardenAuditLedger` adds hash-version metadata and UPDATE/DELETE/version-enforcing INSERT triggers. **A maintenance cutover is required; the operational database has not been migrated by this change.**
- **Permission boundary:** `audit.read` gates audit queries, counts, recent metadata, and direct admin access. `reports.read` retains aggregate reporting without audit visibility.
- **On-Demand Chain Verification:** authorized users can recalculate the chain from genesis. An unkeyed chain without an external checkpoint does not protect against every privileged rewrite or tail deletion. See [Audit ledger hardening](docs/AUDIT_LEDGER.md) for deployment, recovery, and validation details.

### 6. Dynamic Login Carousel Management
- **Admin-Controlled Branding:** Authorized admins (`superadmin`, `clinic_admin`, `guidance_admin`, `bmg_admin`) can upload, edit, reorder, activate/hide, and delete carousel slides via `/admin/carousel`.
- **Dual-Theme Support:** Each slide accepts a light-mode image (required) and optional dark-mode variant.
- **Fit Modes:**
  - **Full Cover (No Blur):** Crisp, full-bleed display (ideal for campus photos).
  - **Contained with Blurred Background:** Letterboxed foreground over a blurred backdrop (ideal for logos or images that must not crop).
- **Live on Login Screen:** The login carousel at `auth/login` renders slides from the database (falls back to seeded defaults if empty).
- **Upload Feedback:** Drag-and-drop image previews appear immediately. A real transfer progress bar shows upload percentage, followed by a separate saving state. Saving returns to the manager, where the refreshed list and its thumbnails confirm the stored image. Add/Edit opens in a dialog, and the next Add dialog starts empty; a failed save keeps the dialog and selections intact.
- **Editing:** Use the pencil/Edit action to change captions and fit mode, replace either image, or remove the optional dark variant. Existing images are retained when no replacement is selected.
- **Upload Storage:** Dedicated `public/uploads/carousel/` storage, PNG/JPEG/WEBP/GIF content validation, and no application file-size cap. PHP (`upload_max_filesize`, `post_max_size`) and web-server request limits still apply.
- **Multi-Tenant Scoped:** Each tenant's slides remain isolated.

---

## Custom Form Widgets (Dropdown & Date Picker)

Native browser controls are enhanced by dependency-free dropdown, date, and date/time widgets, ported from the BalayWorks CI codebase and rebranded to SYNAPSE maroon. None requires jQuery or any new vendor bundle.

### Custom Dropdown — "BalaySelect" (`public/assets/js/custom-dropdown.js`)

Progressive enhancement of native `<select class="form-select">`: the original `<select>` stays in the DOM, visually hidden but still form-bound, so POST values, CI4 validation, `old()` repopulation and `required` keep working unchanged. A visible button plus `.dropdown-menu` is layered on top.

- Applies automatically to **every** `select.form-select` in the app — no per-view markup.
- Opt out per field with `data-native-select`.
- `multiple` selects are intentionally skipped and stay native.
- Two-way sync: item clicks dispatch real `input` + `change` events on the original select, so Alpine `x-model`, `@change`, and inline `onchange` handlers all still fire.
- Optgroups become group headers. Disabled options and groups remain unselectable, including when changed dynamically.
- Field sizing, inline width/flex styles, required state, and error-description links are mirrored to the visible trigger. Invalid required selections show an inline message and focus that trigger.
- Form reset and option mutations are synchronized without emitting selection changes. `htmx:load` upgrades new controls once, and removed controls are cleaned up.
- After directly assigning `select.value` in JavaScript, dispatch `change` or `bd-select:sync` to update the visible control.
- Styles live in `public/assets/css/core/synapse-widgets.css` and follow the `[data-theme="dark"]` tokens automatically.

### Custom Date Picker — "BalayDatepicker" (`public/assets/js/datepicker.js`)

Implements the WAI-ARIA APG Date Picker Dialog pattern: roving-tabindex grid, arrow/Home/End/PageUp/PageDown navigation, `aria-selected`, `aria-current="date"`, `aria-disabled` for out-of-range days, and an Escape-to-close live region.

- Activated by adding `data-datepicker` to a visible `type="text"` input.
- Uses a **two-input contract** so the server contract never changes:

  ```html
  <!-- Custom date picker: visible trigger + ISO hidden value -->
  <input type="text" id="follow_up_date" class="form-control"
         data-datepicker data-target="#follow_up_date_iso" data-min="today" value="">
  <input type="hidden" name="follow_up_date" id="follow_up_date_iso" value="2026-10-14">
  ```

- Supported options: `data-target`, `data-min` / `data-max` (`today` or `YYYY-MM-DD`), `data-week-start`, `data-order` (`dmy` default / `mdy`), `data-locale`, `data-year-span`.
- **SYNAPSE defaults:** Monday-first weeks and numeric `DD/MM/YYYY` display and entry (`MM/DD/YYYY` with `data-order="mdy"`). The hidden value stays `YYYY-MM-DD`.
- Typed slash-separated dates, eight-digit dates, and pasted ISO dates are accepted. Invalid, partial, or out-of-range text remains visible with a validation error; the ISO mirror is cleared so an old date cannot be submitted. Required and min/max constraints are enforced through browser form validity; server-side validation is still required.
- The hidden ISO input/model is synchronized **before** visible change handlers run. Calendar picks and committed typed edits each notify the hidden change trigger once; blur does not duplicate requests.
- Month/year changes clamp the day (January 31 → February 28/29). Unbounded year menus include the next ten years and extend as navigation requires; explicit date bounds take precedence.
- Form reset restores the initial date and sends one hidden `input` event to synchronize Alpine, without `change` events or availability requests. Dynamic HTMX fragments initialize once and dispose their widgets when removed.
- Inside Bootstrap modals, Escape dismisses the nested month/year menu first, then the calendar, without closing the modal. Programmatic ISO changes can be reflected by dispatching `dp:sync` on the hidden input.

### Shared time picker (`public/assets/js/datetimepicker.js`)

Availability start/end times, BMG daily rotation times (including dynamically added rows), and Clinic/Guidance appointment-request preferred times use the shared date/time picker in time-only mode, not separate hour, minute, and AM/PM dropdowns. `partials/schedule_time.php` retains each field's visible ID and pairs it with a named hidden `HH:mm` value:

```html
<input type="text" id="availabilityStart" class="form-control"
       data-timepicker data-target="#availabilityStartValue" value="08:00 AM" required>
<input type="hidden" id="availabilityStartValue" name="start_time" value="08:00">
```

The `.dtp-popup.dtp-time-only` dialog uses hour, minute, and AM/PM wheels (`.dtp-hours-wheel`, `.dtp-minutes-wheel`, `.dtp-ampm-wheel`). Wheel buttons use unpadded numeric `data-val` values and `AM`/`PM`. Apply closes the picker, displays `hh:mm AM/PM`, and preserves the server's 24-hour `HH:mm` contract for department templates, weekly adjustments, custom-hour exceptions, appointment requests, and BMG's repeated `times[]` fields. The partial accepts an optional `describedBy` help-text ID. BMG still enforces eight distinct daily times, no overlapping runs across midnight, version checks, shared confirmation, and existing hardware/permission gates; changing the picker does not enable hardware or send commands. Announcements, surveys, and team events already use the combined date/time mode.

### Shared confirmation dialogs

`public/assets/js/confirm.js` and the single `synapseConfirmModal` in the app layout replace browser prompts for carousel deletion, appointment cancellation (staff and personal portals), counseling-session completion, and referral closure. The existing logout dialog remains separate. Both follow the SYNAPSE theme.

Forms declare `data-confirm`, `data-confirm-title`, `data-confirm-label`, and `data-confirm-tone` (`danger`, `warning`, or `primary`). Prompts use plain text, Cancel receives initial focus, and Cancel/Close/Escape/backdrop decline the action. `SynapseConfirm.confirm(options)` provides the same UI for JavaScript callers and resolves only after the modal finishes closing. A newer unanswered question replaces an older one; requests during closing wait for that transition to finish.

Approval applies only during one immediate `requestSubmit()` attempt and preserves the clicked button, native validation, and hidden CSRF fields. If validation blocks submission, approval expires and correcting the form requires confirmation again. Event delegation also covers dynamic fragments; form handlers do not run before approval. Missing modal/Bootstrap uses native confirmation; without JavaScript the ordinary form remains usable. Confirmation is a UX guard, not a substitute for server authorization, validation, or CSRF protection.

### Modal data-entry forms

Clinic/Guidance requests, staff confirmation/availability, inter-module referral, and carousel Add/Edit open as real Bootstrap dialogs **over the current list or personal portal**. Links retain their direct-route URLs as a fallback, but `data-app-modal-open` loads a server-rendered `?fragment=1` form without navigating away. Direct URLs also open an initialized dialog with the correct return destination.

The shared `layouts/modal_form.php` wrapper and `assets/js/form-modal.js` use `{ backdrop: 'static', keyboard: false }`: only X or Cancel dismiss unsaved work. Focus is contained and restored, and the original page, filters, and scroll state remain in place. During an in-flight save/upload, dismissal is temporarily blocked so a committed write cannot be mistaken for a cancellation. Successful saves navigate to the existing list destination.

`care-forms.js` registers Alpine components once. Dynamically inserted forms initialize Alpine, dropdowns, date pickers, and carousel previews without evaluating fetched scripts. Closing disposes component state and preview URLs. Appointment/referral validation returns JSON errors inside the same dialog, preserves entries, and updates CSRF fields for retry; direct-route flash errors render inside the dialog too. Department appointment management and personal request endpoints are separated; appointment POSTs enforce CSRF and owner/department checks.

### Load order (enforced by `CustomWidgetsTest`)

`bootstrap.bundle.min.js` → `custom-dropdown.js` → `datepicker.js` → `datetimepicker.js` → `core/main.js`. The date picker upgrades its month/year controls through the dropdown widget, so the dropdown must load before the picker. The shared app layout also loads `datetimepicker.js` for the real-view browser fixtures.

### Browser regression tests

The PHP suite verifies server rendering and asset wiring, not JavaScript interactions. Run the separate Chromium suite against local fixtures using the actual Bootstrap, Alpine, HTMX, and widget assets:

```bash
npm install --prefix writable/widget-test-tools --no-save --package-lock=false playwright@1.64.1
node --test --test-concurrency=1 tests/browser/widgets.test.cjs tests/browser/carousel.test.cjs tests/browser/confirm.test.cjs tests/browser/login-carousel.test.cjs tests/browser/modal-forms.test.cjs tests/browser/availability-exceptions.test.cjs tests/browser/availability-filters.test.cjs tests/browser/inventory.test.cjs tests/browser/live-table.test.cjs
```

The runner starts and closes its own loopback test server and browser; no application server, MIS access, credentials, or production data are involved. Playwright is test-only and installed under `writable`, not shipped to the browser. The runner uses a cached Chromium when available and may download Chromium when none is installed.

Widget coverage includes selection/typing event order, actual Alpine model updates and HTMX requests, invalid dates, clearing/resetting required fields, month/year clamping, disabled options/groups, dynamic fragments and cleanup, keyboard/modal dismissal, narrow-viewport positioning, and sampled light/dark text contrast. These tests do not substitute for a full screen-reader or cross-browser audit.

`carousel.test.cjs` executes the actual upload script with file previews and multipart XHR against mocked save/manager responses. It verifies that successful saves remain locked during delayed navigation, duplicate submissions are blocked, failures preserve selections and permit retries, and refreshed CSRF tokens are used. The separate PHP `CarouselAdminRoutesTest` follows successful create/update requests to the real manager view to check flash messages, persisted slide thumbnails, and an empty Add form. Browser fixtures do not validate database or PHP session behavior.

`modal-forms.test.cjs` renders the actual application templates through CodeIgniter's view engine using the CLI-only `tests/_support/render-modal-fixture.php`. It verifies real Bootstrap instances, X/Cancel dismissal without list navigation, focus containment, both themes, widget interactions, preview cleanup, retained validation entries, fresh-CSRF retries, loading cancellation, and in-flight save locks. API and save responses are fixture-controlled; PHP feature/service tests separately cover backend validation, persistence, and access filters.

Availability regressions in `modal-forms.test.cjs`, `availability-exceptions.test.cjs`, and `availability-filters.test.cjs` exercise time-only wheel selection, padded 12-hour display, hidden 24-hour values, Apply dismissal, dynamically inserted/replaced controls, and retained scheduling values on failed saves. Run these serially when no other browser test run is active:

```bash
node --test --test-concurrency=1 tests/browser/modal-forms.test.cjs tests/browser/availability-exceptions.test.cjs tests/browser/availability-filters.test.cjs
php vendor/bin/phpunit --no-coverage --filter ScheduleTimeViewTest
```

`login-carousel.test.cjs` renders the actual login photo markup with the auth CSS and Bootstrap runtime. It samples forward/reverse/wrap transitions in light and dark modes to check opaque slide coverage and stable pane height, preserves contained-image blur and crisp cover slides, and verifies reduced-motion behavior.

`confirm.test.cjs` uses the actual modal markup, Bootstrap, SYNAPSE CSS, and confirmation script. It checks cancellation paths, one-shot submission, submitter overrides, expired approval after validation failure, concurrent/transition-time requests, focus restoration, listener registration, and light/dark text contrast. Its polling helpers have finite deadlines so failed tests exit rather than leaving timer loops running.

---

## Hardware IoT Integration (ESP32-S3)

The mechanized compost tumbler uses an ESP32-S3 controller. The new rotation API authenticates only `X-Device-Token` (SHA-256 stored), with aggregate IP/token throttling. This contract **is not a verified drop-in replacement for the old firmware**. Keep the hardware gate off until actual firmware, physical interlocks, local emergency stop, offline/reboot behavior, command deduplication, clock handling and safe motor settings are verified. No request directly operates hardware; controllers poll persisted intent.

Rotation schedule times and the rotation API's `Y-m-d H:i:s` timestamps use **Asia/Manila**. This is an explicit module contract; do not send UTC wall times without conversion. A run's `occurred_at` is its start time and must be later than the current credential binding. Queued pre-binding reports are rejected rather than attributed to a new drum.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/v1/devices/bmg/schedule` | Flat `times`, `duration_seconds`, `version`, effective `enabled`, `published_enabled`, `timezone`, optional PWM; disabled/unbound safe defaults |
| `POST` | `/api/v1/devices/bmg/heartbeat` | Optional `firmware_version`, boolean `rtc_valid`, integer `applied_schedule_version`; omitted values retain their original receipt timestamps |
| `POST` | `/api/v1/devices/bmg/runs` | `run_uid`, `motor_state` (`turned_normal`, `stopped`, `failed`, `interrupted`), `duration_seconds`, Manila `occurred_at`; device-scoped idempotency, no guessed batch link |
| `GET` | `/api/v1/devices/bmg/commands` | Current unexpired commands for this credential/binding; firmware must durably deduplicate command IDs |
| `POST` | `/api/v1/devices/bmg/commands/ack` | `command_id`, reported `status` (`completed`, `failed`, `rejected`), optional `message`; expired/cancelled/conflicting reports rejected |

---

## Operational report exports

Open **Operational reports** from the dashboard or sidebar. The filters retain the shared custom datepicker and accept up to 31 inclusive Asia/Manila calendar days. Existing report permissions and department/tenant scope apply to both formats; export auditing must succeed before download.

- **PDF:** Locally compiled LaTeX with embedded fonts, Foundation University seal, booktabs tables, and PGFPlots vector charts in a structured A4 report. Clinic produces four pages: activity analysis, visit profile, medicine insights, and daily register. Guidance and BMG each retain two pages. **All supported units** produces an executive summary plus independent department sections (nine pages), not one mixed chart or combined activity total. Charts, per-metric totals, daily averages, active-day/peak statistics where applicable, and dated registers describe the existing aggregate metrics only.
- **Clinic insights (PDF only):** chief-complaint and disposition donuts include exact counts/shares and explicit Not recorded buckets. Because complaints are free text, only case/edge-space-normalized exact matches to the fixed vocabulary in `ClinicReportInsights` are classified (headache, fever, dizziness, abdominal pain, cough, colds, toothache, injury, nausea, vomiting, menstrual cramps, checkup). The five most frequent matched categories are shown; unmatched text and remaining matched categories are combined as Other recorded. Raw narratives never leave SQL; there is no fuzzy matching or diagnosis inference.
- The medicine bar chart/ranking uses distinct completed encounters receiving each medicine, not mixed units/quantities. Multiple FEFO allocations count once per encounter/medicine. Only tenant-consistent ledger-backed dispensing is eligible; operations with a recorded reversal are excluded regardless of reversal mode/date. Legacy unlinked allocations are excluded and their encounter coverage is disclosed separately (it may overlap with qualifying ledger coverage). Shares use all encounters receiving any eligible medicine, so different medicine shares may total over 100%. Catalog labels use current name/strength/dosage form, not historical snapshot names. Missing ledger setup is explicitly unavailable, not zero dispensing. CSV retains its original daily-count schema.
- All Clinic charts use the same completed-encounter/start-date cohort as the existing daily metric. Referred or still-open encounters are not included. This is the current corrected view, not an immutable as-of historical snapshot; later completion/reversal can change a report. Inconsistent aggregate totals are rejected rather than rendered with misleading percentages.
- **CSV:** unchanged machine-readable daily rows with explicit `unit` and `metric` columns. CSV is data-only, not a formatted or multi-sheet workbook.
- **Download lifecycle:** operational PDF/CSV and identified survey CSV forms receive the attachment into a bounded browser-memory buffer, with a bottom-right progress panel. Server generation and transfers without a known length are indeterminate. The bar reaches 100% only after the complete response is received, then the browser save starts. This is transfer progress, not a claim that a file has been saved to disk. Errors, cancellation, timeout, and page swaps release loading controls; no partial file is offered. Both formats retain CSRF synchronization and existing authorization/auditing.
- Clinic/guidance count completed care by start date, not unique people. BMG starts use start date; released/voided use completion date. These are not a cohort, success rate, sensor history, or quality certification.
- **LaTeX runtime:** PDF exports require `pdflatex` (MiKTeX or TeX Live) and preinstalled `geometry`, `graphicx`, `xcolor`, `booktabs`, `tabularx`, `pgfplots` (compatibility 1.18), `microtype`, their dependencies, and standard Computer Modern fonts. The PHP service account must be able to execute `proc_open` and access the compiler, packages, and fonts. CSV remains independent of LaTeX. No GD dependency remains for these PDFs.
- Configure `Config\\ReportExport` or CI4 environment overrides `reportExport.pdflatex` (executable path), `reportExport.miktex` (true for MiKTeX, false for TeX Live), and `reportExport.timeoutSeconds` (default 30; capped at 45). Do not enable package installation during requests. Validate the installed fonts/packages under the actual service account before deploying.
- The fixed, server-owned template accepts only validated aggregate dates/counts, fixed category labels, and length-bounded escaped medicine catalog labels, never request-supplied TeX. Shell escape and MiKTeX automatic installation are disabled. Compilation uses random workspaces under `writable/report-temp` with source/log/PDF size bounds and cleanup on success/failure/timeout; source and compiler diagnostics are not returned to the browser. Nothing is sent to an external rendering service. Unlike CSV, PDF compilation necessarily uses transient local files, not memory-only rendering. Protect the directory with service-account-only OS permissions/Windows ACLs, and exclude it from archives/backups. Cleanup is verified and a failure returns a controlled 503, but process termination, an OS crash, or a MiKTeX helper that outlives its parent may still require administrator cleanup of abandoned jobs. The compiler terminates its direct child, not an OS-contained process tree; preinstall and preflight all fonts/packages rather than relying on runtime font generation.
- The original local `public/assets/img/brand/FoundationU.png` seal is copied into each isolated job. Missing compiler/packages/assets produce a controlled 503 rather than silently reverting to a different PDF style. The existing progress panel shows preparation while LaTeX runs, then receives the completed attachment before triggering save.
- DB-free template/compiler tests: `php vendor/bin/phpunit --configuration phpunit.dist.xml --no-coverage --no-logging --do-not-cache-result tests/unit/OperationalReportPdfTest.php tests/unit/LatexReportCompilerTest.php`. To explicitly exercise real compilation with synthetic data in the local shell, prefix this command with `SYNAPSE_TEST_LATEX=1`; the normal test path does not require a LaTeX installation.

## Quick Start & Setup Runbook

### Prerequisites
- **PHP 8.2+** with `mysqli`, `curl`, `mbstring`, `intl`, `openssl`; `proc_open` and a configured local LaTeX installation for PDF reports
- **Composer 2+**
- **MariaDB 10.4+** or **MySQL 8.0+**

### 1. Database Initialization
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS humus_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE IF NOT EXISTS humus_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Application Setup
```bash
cd humus
composer install
cp env .env
```

Ensure `.env` contains your database connection and crypto keys:
```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'

database.default.hostname = 127.0.0.1
database.default.database = humus_db
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.charset  = utf8mb4
database.default.DBCollat = utf8mb4_unicode_ci

database.tests.hostname = 127.0.0.1
database.tests.database = humus_db_test
database.tests.username = root
database.tests.password =
database.tests.DBDriver = MySQLi
database.tests.charset  = utf8mb4
database.tests.DBCollat = utf8mb4_unicode_ci

# MIS Gateway
FUMIS_BASE_URL = 'https://mis.foundationu.com/sandbox'
FUMIS_API_KEY  = ''
FUMIS_ENABLED  = false   # true = live campus MIS; false = disabled by default
FUMIS_FAKE_ENABLED = false # explicit development/testing fixtures only
FUMIS_TIMEOUT_SECONDS = 10

# Counseling keys: configure privately before use; never commit actual keys.
# COUNSELLING_KEY = ''
# COUNSELLING_KEY_VERSION = 1
# COUNSELLING_KEY_V2 = ''
```

#### Counseling key deployment contract

- The key for the active version is required and must be exactly **64 hexadecimal characters** (32 bytes). `COUNSELLING_KEY` is the version-1 key and must also be retained whenever existing version-1 notes need to be read. Store actual keys only in private server configuration, never in documentation, source control, logs, or public assets.
- `COUNSELLING_KEY_VERSION` must be a **positive integer**. Version **1** uses `COUNSELLING_KEY`; each version **n > 1** uses its explicitly configured `COUNSELLING_KEY_Vn` (for example, version 2 uses `COUNSELLING_KEY_V2`). Every configured key must meet the same 64-hex requirement. **V2+ has no fallback** to `COUNSELLING_KEY`, another version, or a built-in/derived key.
- Missing or malformed active keys and invalid default versions **fail closed at service construction**. Missing historical keys and invalid requested versions fail when used. No substitute or derived key is accepted. Decryption also requires the existing 12-byte nonce and 16-byte authentication tag.
- **Before upgrading an installation with existing fallback-encrypted notes, securely preserve the old actual encryption key as `COUNSELLING_KEY`.** Recover and verify that actual key through a secure operational process; do not copy a public example or blindly generate a replacement. Existing notes still require the key that encrypted them. Retain all historical versioned keys and verify recoverability before any separately planned key change.
- Only for a **fresh installation with no existing encrypted notes**, generate a private key locally with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and set it in private configuration. The `env` template deliberately contains commented blank key entries.

Legacy compatibility requires the actual key bytes to match each note's recorded version. If an old malformed key was silently SHA-256-derived, securely recover that derived 32-byte key and configure its 64-character hexadecimal encoding; do not keep the malformed input. If an old versioned note used the version-1 fallback, its corresponding `COUNSELLING_KEY_Vn` must explicitly contain the actual key used. Do not assign a new key to that version before verifying historical notes on a protected copy. Mixed-key records sharing one version require a separately planned recovery migration.

This stage enforces strict key configuration without changing the ciphertext format. No key rotation, AAD changes, or data migration is performed. Verify historical-note recovery before deploying; missing key configuration can also block guidance operations that construct the encryption service.

### 3. Run Migrations & Seeders
```bash
php spark migrate --all
php spark db:seed App\\Database\\Seeds\\PermissionsAndRolesSeeder
php spark db:seed App\\Database\\Seeds\\MedicinesSeeder
```

### 4. Mint Platform Owner (Break-Glass Superadmin)
```bash
php spark synapse:superadmin admin@foundationu.com password123
```

### 5. Start the Development Server
```bash
php spark serve --port 8080
```

### 6. Local URL Equivalents
You can access all portals in development either via **Zero-Config Local Paths** (works immediately) or via **Local Subdomains**:

#### Option A: Zero-Config Local Paths (Immediate Access)
| Production Domain | Local Development URL | Destination |
|---|---|---|
| `synapse.foundationu.com` | `http://localhost:8080/portal` | Personal Health Portal (Students & Employees) |
| `clinic.foundationu.com` | `http://localhost:8080/clinic/queue` | Clinic Staff Queue & Medical Workstation |
| `guidance.foundationu.com` | `http://localhost:8080/guidance/cases` | Guidance Counselor Cases & Sessions Workspace |
| `bmg.foundationu.com` | `http://localhost:8080/facilities` | Facilities (BMG) Tumbler Drums & Batches |
| *(Admin: Carousel Manager)* | `http://localhost:8080/admin/carousel` | Login Carousel Slide Management (Admins Only) |

#### Option B: Local Subdomains (Subdomain Emulation)
Add this line to your hosts file (`C:\Windows\System32\drivers\etc\hosts` on Windows):
```text
127.0.0.1  synapse.localhost  clinic.localhost  guidance.localhost  bmg.localhost
```
Then you can open the exact local subdomain equivalents directly:
- `http://synapse.localhost:8080` $\rightarrow$ Campus Health & Guidance Portal
- `http://clinic.localhost:8080` $\rightarrow$ Clinic Staff Console
- `http://guidance.localhost:8080` $\rightarrow$ Guidance Counselor Console
- `http://bmg.localhost:8080` $\rightarrow$ Facilities Operations Console

---

## Announcements and identified surveys

These modules are newly implemented against the current PHP-view app; legacy code was used only to understand requirements. Announcements, questionnaires, clearance, external assessment links, screenshot proofs, and clinical triage are not combined into one workflow.

### Announcements

- Clinic and Guidance administrators manage their own department's notices at `/clinic/announcements` and `/guidance/announcements`. Guidance supervisors can also manage Guidance notices. Ordinary staff do not acquire publishing authority merely by having an employee account.
- Draft → published → archived. Drafts use version checks to prevent stale overwrites. Published text, audience and dates are immutable; archive and create a replacement for corrections.
- Plain-text title/body, actual-profile audience (`all`, `students`, `employees`), optional opening/expiry, 25-row pagination, department provenance, and explicit POST read markers.
- `/portal/announcements` is a student/employee news feed with department badges, plain-text excerpts, publication/expiry dates, read states, search, department filters, and All/Unread/Read tabs. Pagination preserves the current filters. It shows only currently published, audience-eligible notices. Opening a page does not create a read marker. Marked-read history is restricted to the publishing department's authorized administrators and is not proof of comprehension.

### Surveys

- Guidance administrators/supervisors manage `/guidance/surveys`; students and employees use `/portal/surveys`. The personal portal and sidebar link to both new modules. Portal surveys use responsive action cards (two columns on desktop, one on mobile), search, and All/Available/Submitted/Closed tabs with query-wide counts. Counts reflect the search and Submitted can overlap Closed. Available means open and not yet submitted. Closing soon means within 48 hours of the real deadline; timestamps are displayed in Manila time. Submitted cards show confirmation, with a status link only while open; closed cards never offer participation. Closed/expired history remains constrained to the current tenant/profile targeting and excludes drafts, unpublished records, and future starts. The existing submit/form eligibility checks still refuse closed surveys. No answers are fetched for the feed, and the identified-response privacy notice remains visible. Staff management tables are unchanged; no pin/image/time-estimate features or schema migrations are introduced.
- Draft → published → closed. Publishing freezes questions, audience and availability. Draft edits use optimistic versions. Questions support text, single choice, multiple choice and 1–5 ratings through a visual builder: 1–20 questions, 2–10 distinct choice options, and text answers up to 2,000 characters.
- **Question bank:** authorized Guidance survey managers can create, categorize, revise and archive reusable questions at `/guidance/question-bank`. The draft editor's **Add from question bank** picker copies a verified revision snapshot; bank changes never rewrite saved surveys or responses. Editing a copied question detaches it into a custom question. The additive local migration is installed; no role grants or encryption settings changed. See [question bank workflow, installation and tests](docs/QUESTION_BANK.md).
- **Explicit absence lifecycle:** staff can resolve a due appointment or unstarted queue ticket as `no_show`; linked queue entries move together. This never means a visit/session was completed, never closes clinical work, and refuses started/complete records. Queue statuses support this state only after migration `2026-10-19-000008_AddQueueNoShowStatus` is applied and verified.
- **Employee referral authority:** the employee grant is retired because there is no safe self-service referral workflow; employees must contact clinic/guidance staff. Existing referral creation remains a department staff action. Migration `2026-10-19-000009_RetireEmployeeReferralGrant` removes only the employee-to-`referrals.create` role mapping.
- These are **identified**, not anonymous, surveys. One submission per tenant/survey/person is enforced under a row lock and unique database constraint, including concurrent requests. Missing/unknown/malformed answers are rejected. Completion is not a clearance decision or assessment verification.
- Responses are AES-256-GCM encrypted with authenticated tenant/survey/person context; there is no duplicate plaintext answer table. Result lists/details require fresh Guidance administrator/supervisor authority plus `guidance.responses.read`; ordinary counselors cannot browse all responses. Audit records contain action/record metadata, not answers.
- Form availability dates are labeled **Asia/Manila** and stored/compared as UTC. Published and closed responses are retained; destructive migration rollback is refused.

**Required private configuration:** configure `SURVEY_KEY` as 64 random hexadecimal characters before publishing or submitting surveys. Generate locally with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and put it in private server configuration—not source control or public assets. There is no default, derived, or counseling-key fallback. Rotation uses `SURVEY_KEY_VERSION=2` with `SURVEY_KEY_V2`; retain old versioned keys for historical responses. The checked development environment currently has no survey key, so publication fails closed; drafts and announcement workflows remain available. See `env` for commented configuration guidance. No private `.env` was modified.

### Student year-level and category targeting

Choose **Students** in either announcement or survey drafts to enable optional multi-select filters:

- **Year levels:** Years 1–6, with any selected year matching.
- **Student categories:** New, Continuing, Transferee, Graduating, with any selected category matching. Graduating is a separate verified flag and can overlap an enrollment type.
- Both groups selected means **year AND category**; leaving a group empty removes that restriction. Example: Years 2 or 3 **AND** Continuing or Transferee. Filters are rejected for the mixed/all or employees audience rather than accidentally narrowing or widening those audiences.
- Edit dialogs restore saved selections and management lists show readable targeting summaries. Published targeting remains immutable.
- The same fresh, tenant-scoped membership check governs portal lists, direct links, announcement read markers, survey submissions, notification fanout, and bell read permissions. A changed/unknown profile cannot retain access through an old notification. Invalid/noncanonical stored filters fail closed.

Profile fields are nullable `student_profiles.enrollment_type` (`new`, `continuing`, `transferee`) and independent `is_graduating`. The MIS mapper accepts explicit `student_type`/`enrollment_type` and `is_graduating` contract fields only. Omitted fields preserve prior values for partial-login compatibility; explicitly unknown, conflicting, or null values clear the respective classification. No account-age, student-number or final-year heuristic is used, and transferee is not silently treated as new.

**Current data limitation:** main read-only verification found **2,006 student profiles**, all with year levels, but **zero known enrollment types/graduating flags**. Year-level targeting is immediately usable. Category-restricted content excludes these unknown records until verified MIS data supplies the classification. Real campus field compatibility still needs confirmation when MIS is reachable; synthetic fixtures validate the explicit contract, not a claimed live response.

Applied additive migration `2026-10-17-000004_AddStudentAudienceTargeting` after exact-backup restore rehearsal: six nullable/unbackfilled columns, existing publication audiences unchanged, no notifications resent. Backup stem `writable/backups/humus_db_before_audience_20261016_201958_258b4d`; SHA-256 `e3816b49a1204638864accb3374eb4dab09b3ea62d7bb877a74329cbd5302be7`. Preservation evidence: `writable/backups/audience_fingerprints_20261016_203858_0505fb67.json`.

```sh
python writable/isolated-inventory-harness.py --validate-audience-tests
php writable/audience-development-smoke.php
```

### Publication notifications

First publication creates person-owned bell notices for currently active account holders with matching same-tenant student/employee profiles. Notice text is generic and contains no announcement body, survey title, questions, or answers. Retries cannot duplicate fanout. Future-window notices become visible at their start; archived/closed/expired or no-longer-eligible notices cannot be seen or marked read. Bell read state and announcement read markers are separate. Newly created accounts do not receive retroactive fanout, but can browse current eligible portal content. No email/SMS/push or automatic clinical follow-up is sent.

### Deployment and validation

Applied the three additive `2026-10-17-000001` through `000003` migrations to local `humus_db` after an isolated restore rehearsal. Original records, account roles, existing grants, and notification read state were preserved. Only four empty module tables, nullable notification references/indexes, four permission entries and eleven default grants were added; no demo content, responses or notifications were generated.

Backup: `writable/backups/humus_db_before_engagement_20261016_193239_82b0a9.sql` with matching JSON; SHA-256 `3f75402633155c8c5927b759ce97ee3f6c170c23bc9f28b533fa1f4a6c0fbbe4`. Verification evidence: `writable/backups/engagement_fingerprints_20261016_200900_3ba6ecf6.json`. Do not rerun the apply helper on an already migrated database.

Selected isolated regressions: **387 tests / 4,780 assertions**. Engagement browser tests: **25 passed**; notification browser tests: **17 passed**, including exact allowed publication destinations, light/dark/mobile forms, shared confirmations, retries, and soft navigation. Main read-only smoke: **6 staff pages + portal announcements passed**; portal-survey student checks explicitly skipped because no local student user exists. Isolated tests cover real persisted student/employee identities. No live MIS requests were made.

```sh
python writable/isolated-inventory-harness.py --validate-engagement-tests
node --test tests/browser/engagement.test.cjs tests/browser/notifications.test.cjs
php writable/engagement-development-smoke.php
```

## Demo Credentials Matrix

For complete local workflow coverage across all 12 roles, use the development-only synthetic account command:

```sh
php spark synapse:test-accounts --tenant 1
php spark synapse:test-accounts --create --tenant 1
```

Preview first. Creation adds 15 clearly marked `test.*` identities (including second Clinic staff, counselor, and Facilities operator accounts), each linked to a synthetic person/profile. Creation prints a generated password once for new accounts. Ordinary preview/create reruns never overwrite existing accounts, passwords, grants, or operational records. An explicit guarded password reset is available separately for this complete synthetic account set. These local logins do not require fake MIS mode. Do not deploy a database containing these test identities to production. See [the full account matrix, guardrails, and isolated tests](tests/LOCAL_TEST_ACCOUNTS.md).

### Complete local test accounts

These 15 accounts were created locally for tenant `1` and explicitly reset to the shared **local-test password `password123`**. Sign in using any username below and `password123`. This reset did not change the older ID-number accounts. Passwords are stored as hashes; ordinary preview/create reruns neither reveal nor reset them.

| Username | Role | Testing purpose |
|---|---|---|
| `test.superadmin` | `superadmin` | Cross-workstation administration and privileged access |
| `test.clinic.admin` | `clinic_admin` | Clinic management, announcements, inventory, and Teams administration |
| `test.clinic.staff` | `clinic_staff` | Clinic queue, appointments, encounters, and Teams |
| `test.clinic.staff2` | `clinic_staff` | Second Clinic member for chat, unread counts, task ownership, and event participation |
| `test.guidance.admin` | `guidance_admin` | Guidance administration, announcements, surveys, responses, and Teams |
| `test.guidance.supervisor` | `guidance_supervisor` | Guidance oversight, survey access, and Teams management |
| `test.counsellor` | `counsellor` | Assigned counseling cases, sessions, scheduling, and Teams |
| `test.counsellor2` | `counsellor` | Second counselor for case isolation and Teams collaboration |
| `test.bmg.admin` | `bmg_admin` | Facilities configuration, operational records, and Teams management |
| `test.facilities.op` | `facilities_op` | Facilities operator workflows and Teams |
| `test.facilities.op2` | `facilities_op` | Second Facilities member for chat, task ownership, and event participation |
| `test.audit.reader` | `audit_reader` | Audit access and restricted-role checks |
| `test.report.viewer` | `report_viewer` | Report-role authorization and denied workstation access checks |
| `test.student` | `student` | Student portal, appointment requests, and eligible surveys |
| `test.employee` | `employee` | Employee portal, appointment requests, and eligible surveys |

- All identities are synthetic, with `Test` names, `SYNAPSE-TEST-*` identifiers, and reserved `@synapse.invalid` email addresses. The addresses are not mailboxes.
- These are local password accounts; fake MIS mode is not required. Available features still depend on the existing role grants and implemented routes—creating an account does not add either.
- Use separate browser profiles for paired accounts when testing chat, unread tracking, task ownership, and calendar participation.
- Provider enrollment and availability must be configured explicitly before testing scheduled appointments. Account creation does not create clinical data, schedules, or hardware access grants.
- Ordinary preview/create reruns preserve existing credentials. Other development databases receive generated passwords on creation; the local reset described here does not automatically apply elsewhere.
- To explicitly reset all 15 matching synthetic accounts, pipe the new password over STDIN: `printf '%s\n' 'password123' | php spark synapse:test-accounts --reset-password --tenant 1`. The command refuses partial/incompatible sets, accepts no password argument, and invalidates existing sessions. It never resets older ID-number accounts.
- `password123` is intentionally weak and for isolated local testing only. Never deploy these test identities or this password to production.

### Older demo identifiers

The older mock identifiers below are for explicit offline fixture mode only (`FUMIS_ENABLED=false`, `FUMIS_FAKE_ENABLED=true`, development/testing). Local break-glass accounts remain independent of MIS mode:

| Role / Console | Identifier | Password | Access Scope |
|---|---|---|---|
| **Platform Owner** | `admin@foundationu.com` | `password123` | Full access across all departmental consoles, audit verification, and user management. |
| **Clinic Staff (Nurse)** | `20269002` | `password123` | Elena Bautista, RN: Access to Clinic Queue, Encounters Stepper, Patient Charts, and FEFO Dispensing. Can access `/portal` for personal medical checkups. |
| **Guidance Counselor** | `20269003` | `password123` | Gabriel Reyes: Access to Guidance Cases, AES-256-GCM Encrypted Notes, Follow-ups, and Referral Intake. |
| **Faculty / Employee** | `20269001` | `password123` | Dr. Roberto Hernandez (College of Nursing): Access to Personal Health Portal (`/portal`) and self-service appointment booking. Workstation referrals require an authorized Clinic or Guidance role. |
| **Student** | `20230001` | `password123` | Juan Dela Cruz (BSIT-3): Access to Personal Health Portal (`/portal`), health history, and appointment booking. |
| **Student (Nursing)** | `20240102` | `password123` | Maria Clara Santos (BSN-2): Access to Personal Health Portal (`/portal`). |

---

## Automated Testing & CI

SYNAPSE includes unit and HTTP feature tests. Run database-backed tests only against a disposable schema, never `humus_db` or a reused shared test database. The verified baseline now has a GitHub Actions workflow for PHP 8.2/8.3 with MariaDB 10.11; the full historical suite is still not certified green. See [Testing](docs/TESTING.md) for safety guards, prerequisites, and the exact CI scope.

```bash
# Explicit DB-free baseline
php vendor/bin/phpunit --configuration phpunit.ci.xml --no-coverage
# Full migrations + five service files on a randomly named fresh schema
python tests/_support/run-fresh-database-tests.py tests/unit/QueueServiceTest.php tests/unit/AppointmentServiceTest.php tests/unit/AuditServiceTest.php tests/unit/GuidanceCaseServiceTest.php tests/unit/LoginAttemptStoreTest.php
```

The guarded core service classes skip before migrations outside the fresh runner. Local validation passed **399 DB-free tests / 1,717 assertions** and **24 migration-backed tests / 135 assertions**, including real concurrent login-counter contention. Workflow execution on GitHub remains pending tracking/pushing this project; no repository was initialized or committed by this change. Existing dirty test data is not repaired automatically, and strict migration preflights remain intact.

### Test Coverage Highlights
- **`MisProfileMapperTest`:** Validates student and employee mapping against local fixtures; actual campus payload confirmation remains pending.
- **`AuthServiceTest`:** Validates student JIT provisioning, employee JIT provisioning, and local break-glass password logins.
- **`QueueServiceTest`:** Verifies atomic sequencing (`C-001`), emergency priority preemption, skip timers, and appointment promotion.
- **`AppointmentServiceTest`:** Tests slot availability calculation, provider leave exceptions, conflict detection, and cancellation.
- **`EncounterServiceTest`:** Tests encounter startup, BMI calculation, multi-batch FEFO medicine dispensing, and disposition closure.
- **`EncryptionServiceTest`:** Validates AES-256-GCM roundtrip encryption/decryption, ciphertext tampering detection, and forged tag rejection.
- **`GuidanceCaseServiceTest`:** Tests sequential case code generation (`GC-YYYY-XXX`), draft vs. finalized note versioning, and follow-ups.
- **`ReferralServiceTest`:** Tests referral creation, acknowledgment, case conversion, and **asserts zero cross-module SQL JOINs via schema inspection**.
- **`BmgServiceTest`:** Validates batch lifecycles, EPA 503 sanitation criteria, and **mass conservation invariant enforcement** (`output <= input`).
- **`AuditServiceTest`:** Tests SHA-256 chaining and database rejection of audit UPDATE/DELETE; separate `AuditHashTest` fixtures verify hash tampering without corrupting a database ledger.
- **`PortalSeparationTest`:** Asserts that students cannot access operational consoles, staff can access their operational console, and staff can access their personal health portal as employees.

### Local security regression validation

On the configured Windows development machine, the bounded harness starts its own disposable MariaDB on port `33307`, verifies the restored fixture, runs security tests with MIS disabled, and shuts down its owned instance:

```bash
python writable/isolated-inventory-harness.py --validate-security
```

Latest isolated result, including MIS login compatibility, person-based scheduling enrollment, private appointment notifications, workstation referral workflows and receiving-team notifications, migration preservation, and bulk availability: **387 tests, 4,780 assertions passed**. Referral browser tests: **13 passed**; engagement browser tests: **25 passed**; notification browser tests: **17 passed**; shared live-table browser regressions: **23 passed**. Focused enrollment/availability/confirmation browser tests: **20 passed**, including light/dark mode, retries, explicit selections, and CSRF. Coverage includes session/grant refresh, disabled identities/tenants, department and cross-tenant access, counselor confidentiality, local lookup, referrals, browser CSRF, POST-only logout/chart opening, and separation of device authentication from browser CSRF. Logs are written under `writable/isolated-inventory-db/`.

Focused browser regression command:

```bash
node --test --test-concurrency=1 tests/browser/csrf.test.cjs tests/browser/inventory.test.cjs
```

Read-only development smoke checks also verified inventory page rendering, ledger consistency, directory pagination/search, and full-page/fragment responses. These rendering checks do not replace HTTP authorization tests. No live MIS request or main-database business-data mutation was performed.

## Authentication session security

See [session security](docs/SESSION_SECURITY.md) for the required auth-version
migration, idle/absolute expiry settings, production HTTPS defaults, and
logout/password-replacement revocation boundaries.
