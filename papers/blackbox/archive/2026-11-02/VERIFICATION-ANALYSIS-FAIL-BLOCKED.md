# Verification and Classification of Failed & Blocked Black-Box Tests

**Project:** SYNAPSE (Campus Health, Guidance & Composting Platform)
**Repository:** `[REDACTED: local user path]\humus`
**Base Test Suite:** `papers/blackbox/SYNAPSE-BlackBox-Testing.docx` / `SYNAPSE-BlackBox-Testing.md`
**Date of Audit:** 2026-11-02
**Evaluation Scope:** All 3 FAIL cases and 14 BLOCKED cases (17 total) from the web black-box run.

---

## Executive Summary

Out of 105 executable web test cases in the SYNAPSE suite, 88 initially passed, 3 failed, and 14 were blocked. Each of the 17 non-passing cases was subjected to independent code-level verification, architecture analysis, and live runtime testing.

### Key Findings

1. **Two Genuine Bugs Identified and Remediated (CONFIRMED):**
   - **RF-010 (Referral list filtering):** Filtering referrals by status (`?status=...`) threw `HTTP 422 Invalid medicine status.` because the shared pagination helper `TablePage::fromQuery()` hardcoded the medicine inventory status vocabulary. In addition, the status and receiving-unit dropdown filter controls were missing from the view. **Remediated, verified live, and backed by a regression test.**
   - **AD-009 (Role and permission catalog):** The catalog on `/admin/access` claims to present all database permission codes and their platform-wide role assignments, but it only listed 11 employee roles and omitted `student`, despite the `student` role holding 3 active permissions in `role_permissions`. **Remediated, verified live, and backed by a regression test.**

2. **Eight Cases are By Design (CONFIRMED):**
   - **BM-013 (BMG analytics):** The test plan expected time-in-status, unit availability, cancellation rates, and rotation reliability. The product documentation (`README.md:480, 484`) and reporting service (`OperationalReportPdf.php:16-18`) explicitly scope BMG operational reporting to lifecycle bookkeeping only (`bmg_batches_started`, `bmg_batches_released`, `bmg_batches_voided`) and disclaim sensor, cohort, or availability claims.
   - **AU-009 & AD-008 (User lifecycle & password management):** The test cases expected within-app password replacement, account creation, and deactivation. SYNAPSE explicitly delegates user identity, authentication credentials, and account activation to the university's central MIS directory (`AuthController.php:90`, `employee_access.php:3`). Session revocation upon identity change (`auth_version` invalidation) is fully functional and verified via role updates.
   - **RF-003 (Employee-initiated referral):** The test plan assumed teaching employees could create referrals to the clinic. SYNAPSE's data model (`referrals.source_module` and `target_module` are enum `('clinic','guidance')`) and authorization service (`ReferralService::ownModule()`) strictly restrict referrals to inter-department clinical and counselling handoffs between staff.
   - **BM-005 (Batch process log):** The retired `/facilities/batch/{id}/log` route is explicitly documented as removed in `README.md:480`. Batch observations are captured exclusively as audit notes on state transitions (create, release, cancel).
   - **BM-010 & BM-011 (Hardware commands & rotation schedule):** Motor actuation is protected by a two-factor safety gate: `facilities.hardware.manage` (ungranted to any role by default, even superadmin) and `BMG_HARDWARE_ENABLED=false` in `.env` (`README.md:479`). The IoT Device API (device registration, heartbeat, run reporting) was independently verified as fully functional over HTTP.
   - **AD-004 (Audit ledger export):** The immutable cryptographic audit ledger (`/admin/audit`) is designed for on-screen inspection and hash-chain verification (`/admin/audit/verify`), not raw CSV extraction. All exported files across the platform (operational reports) sanitize formula characters with leading apostrophes (`ExportCsv.php:40-42`).

3. **Seven Cases are Blocked by Environmental / Data Prerequisites (CONFIRMED):**
   - **AD-005 (MIS reference synchronization):** The external sandbox upstream `https://mis.foundationu.com/sandbox` is unreachable from this local test environment (timeout). The system fail-closed behavior correctly prevents incomplete synchronization.
   - **BM-012 (USB firmware compile timeout):** The ESP32 toolchain (`arduino-cli`, ESP32 core 3.3.3, ArduinoJson 7.4.2) is installed and functional, but cold compilation of the 16MB ESP32-S3 firmware on this Windows machine takes ~270s, which exceeds the strict 180s process timeout in `BmgBuildService.php:81, 295`.
   - **AI-006, AI-007, AI-008, AI-009, AI-010 (AI insights pipeline):** The local summary analysis operates on the last two completed calendar months (September vs October 2026). `DisclosurePolicy.php` strictly requires ≥ 10 distinct contributors in both months to protect patient privacy (k-anonymity). The seed database lacks historical clinic visits for those specific months, and timestamps cannot be back-dated through the UI.

---

## Detailed Classification Matrix

| Test ID | Module | Initial Result | Verified Classification | Finding Type | Root Cause / Rationale | Remediated? |
|---|---|---|---|---|---|---|
| **RF-010** | Referrals | FAIL | **PASS (Fixed)** | **BUG** | `ReferralService::listing()` validated status using `TablePage::MEDICINE_STATUSES`, throwing 422 on valid referral statuses; view lacked dropdown filter controls. | **Yes** (Code + View + JS + Test) |
| **AD-009** | Admin | FAIL | **PASS (Fixed)** | **BUG** | Role catalog on `/admin/access` derived roles only from employee access options, omitting the `student` role despite active `role_permissions` grants. | **Yes** (Service + View + Test) |
| **BM-013** | Facilities | FAIL | **PASS (By Design)** | **BY DESIGN** | Report explicitly disclaims sensor/availability/cohort metrics; `README.md:480` and `OperationalReportPdf.php` confirm BMG is scoped to lifecycle bookkeeping counters only. | n/a (Test plan scope mismatch) |
| **AU-009** | Auth | BLOCKED | **BY DESIGN** | **BY DESIGN** | Identity and passwords are owned by external MIS (`AuthController.php:90`). Session revocation via `auth_version` invalidation is fully implemented and verified. | n/a (Delegated to MIS) |
| **RF-003** | Referrals | BLOCKED | **BY DESIGN** | **BY DESIGN** | Referrals are strictly inter-department staff handoffs (`enum('clinic','guidance')`). Employees are scoped to personal portal only (`EmployeeAccessService::ROLES`). | n/a (Architecture boundary) |
| **BM-005** | Facilities | BLOCKED | **BY DESIGN** | **BY DESIGN** | The `/facilities/batch/{id}/log` route was intentionally retired per `README.md:480`. Batch observations are recorded on state transitions (create, release, cancel). | n/a (Retired feature) |
| **BM-010** | Facilities | BLOCKED | **BY DESIGN** | **BY DESIGN** | Physical hardware actuation safety gate (`facilities.hardware.manage` + `BMG_HARDWARE_ENABLED=false`). Device API (token, heartbeat, runs) verified working. | n/a (Physical safety gate) |
| **BM-011** | Facilities | BLOCKED | **BY DESIGN** | **BY DESIGN** | Same hardware safety gate as BM-010. Server also does not push scheduled rotations; devices pull schedule via `/api/v1/devices/bmg/schedule` per `README.md:484`. | n/a (Physical safety gate) |
| **BM-012** | Facilities | BLOCKED | **BLOCKED (Timeout)** | **ENV LIMIT** | Toolchain is valid (`arduino-cli` present), but Windows ESP32 compilation takes ~270s, exceeding the 180s hard cap in `BmgBuildService.php:295`. | No (Host compile speed) |
| **AD-004** | Admin | BLOCKED | **BY DESIGN** | **BY DESIGN** | Cryptographic ledger is designed for on-screen verification (`/admin/audit/verify`), not CSV dump. All system exports sanitize formulas (`ExportCsv.php`). | n/a (Design decision) |
| **AD-005** | Admin | BLOCKED | **BLOCKED (Upstream)** | **ENV LIMIT** | Upstream MIS sandbox `https://mis.foundationu.com/sandbox` timed out. System correctly failed closed without corrupting local reference tables. | No (External network) |
| **AD-008** | Admin | BLOCKED | **BY DESIGN** | **BY DESIGN** | User creation, deactivation, and password resets are managed centrally by MIS. Synapse only administers workstation access for existing accounts. | n/a (Delegated to MIS) |
| **AI-006** | AI Insights | BLOCKED | **BLOCKED (Data)** | **SEED GAP** | Comparison window (Sept vs Oct 2026) requires ≥ 10 distinct contributors per month for k-anonymity privacy (`DisclosurePolicy.php:36-39`). Seed data lacks past visits. | No (Temporal/Privacy constraint) |
| **AI-007** | AI Insights | BLOCKED | **BLOCKED (Data)** | **CASCADING** | Cascading from AI-006: generation jobs cannot be enqueued without a prepared summary snapshot. | No (Blocked by AI-006) |
| **AI-008** | AI Insights | BLOCKED | **BLOCKED (Data)** | **CASCADING** | Cascading from AI-006: worker CLI (`php spark ai:dispatch`) runs cleanly but has no queued jobs to execute. | No (Blocked by AI-006) |
| **AI-009** | AI Insights | BLOCKED | **BLOCKED (Data)** | **CASCADING** | Decision controls (Accept, Reject, Approve) exist in `app/Views/ai/panel.php:167-169`, but the seeded draft is tagged "Older configuration" and hidden from review. | No (Blocked by AI-006) |
| **AI-010** | AI Insights | BLOCKED | **BLOCKED (Data)** | **CASCADING** | Read-only withholding was verified PASS; viewing approved releases requires an approved release in the live window, which requires AI-006. | No (Blocked by AI-006) |

---

## Detailed Audit per Case

### 1. RF-010 — Referral List Filtering
- **Classification:** **GENUINE BUG (Remediated)**
- **Authoritative Evidence:**
  - `app/Services/Referrals/ReferralService.php:271` called `TablePage::fromQuery($query)`.
  - `app/Libraries/TablePage.php:26` hardcoded validation against `['all', 'active', 'archived', 'low_stock']` (clinic medicine statuses) and threw `InvalidArgumentException('Invalid medicine status.', 422)`.
  - `app/Views/referrals/index.php` had only search box `q` and page size `per_page`; missing `status` and `unit` dropdowns.
  - `public/assets/js/live-table.js:35` did not include `unit` in its URL parameter whitelist.
- **Remediation Implemented:**
  - `app/Libraries/TablePage.php`: Decoupled `fromQuery($query, $statusValues, $statusLabel)` to allow domain-specific status lists.
  - `app/Services/Referrals/ReferralService.php`: Added `LIST_STATUSES = ['all', 'submitted', 'acknowledged', 'under_review', 'converted_to_case', 'closed']` and `LIST_UNITS = ['all', 'clinic', 'guidance']`, with filtering on `r.status` and `r.target_module`.
  - `app/Views/referrals/index.php` and `_results.php`: Added Status and Receiving Unit filter selects matching server badge labels.
  - `public/assets/js/live-table.js`: Whitelisted `unit`.
  - `tests/feature/ReferralRoutesTest.php`: Added `testListFiltersByReferralStatusAndReceivingUnit()`.
  - Also resolved pre-existing foreign key teardown order in `ReferralRoutesTest.php`.
- **Validation:** All 88 tests in `ReferralRoutesTest`, `TablePageTest`, and `DirectoryPaginationHttpTest` pass (898 assertions). Live HTTP request `GET /clinic/referrals?tab=sent&status=closed` returns HTTP 200.

### 2. AD-009 — Role & Permission Catalog
- **Classification:** **GENUINE BUG (Remediated)**
- **Authoritative Evidence:**
  - `app/Database/Seeds/PermissionsAndRolesSeeder.php:87-167` defines permissions for **12 distinct roles**.
  - `app/Services/EmployeeAccessService.php:14` defined `ROLES` with only 11 roles (excluding `student` because students cannot be assigned as staff in employee management).
  - In `EmployeeAccessService::form()`, the catalog section looped over assignable employee roles, causing the student role to be completely omitted from the permissions catalog.
- **Remediation Implemented:**
  - Added `CATALOGUE_LABELS = ['student' => 'Student — personal portal only']` to `EmployeeAccessService`.
  - Updated `EmployeeAccessService::form()` to query all distinct roles present in `role_permissions`.
  - Updated `app/Views/admin/employee_access_form.php` to resolve labels from `ROLES` or `CATALOGUE_LABELS`.
  - Added regression test `testRoleCatalogueListsEveryGrantedRoleIncludingStudent()` in `tests/feature/EmployeeAccessHttpTest.php`.
- **Validation:** 17 tests, 192 assertions pass in `EmployeeAccessHttpTest`. Live catalog on `/admin/access` now renders all 12 role sections.

### 3. BM-013 — BMG Period Analytics
- **Classification:** **BY DESIGN (Scope Mismatch)**
- **Authoritative Evidence:**
  - `app/Services/Reporting/OperationalReportPdf.php:16-18` registers exactly 3 BMG metrics: `bmg_batches_started`, `bmg_batches_released`, and `bmg_batches_voided`.
  - `OperationalReportPdf.php:57` throws an exception if the BMG group count is not exactly 3: `if (count($groups['BMG']) !== 3) throw new RuntimeException('Incomplete BMG report data.', 503);`.
  - `app/Views/reports/export.php:118-119`: "**Lifecycle bookkeeping only: no sensor, sanitation, or quality claims.**"
  - `app/Services/Reporting/OperationalReportLatex.php:126-127`: "These are separate lifecycle events, not a cohort or a release-success rate."
  - `README.md:480, 484` confirms that advanced sensor metrics, cohort analysis, and reliability shares are excluded from this phase.
- **Conclusion:** The application is behaving exactly as designed and documented. The black-box test plan expected features from a future development phase.

### 4. AU-009 & AD-008 — Password Management and User Lifecycle
- **Classification:** **BY DESIGN (Centralized Identity Architecture)**
- **Authoritative Evidence:**
  - `app/Controllers/AuthController.php:90`: *"Please verify your credentials with the MIS directory."*
  - `app/Views/admin/employee_access.php:3`: *"This page changes Synapse access only—not MIS identities, passwords, employment details or appointment-provider enrollment."*
  - `app/Services/EmployeeAccessService.php:12`: *"Multi-workstation roles, account activation and MIS profile edits are not managed here."*
  - `app/Services/AuthenticatedUser.php:68-70` implements session revocation via `_auth.version !== users.auth_version`. When an administrator changes a role on `/admin/access`, `auth_version` increments (`EmployeeAccessService.php:160-161`), immediately revoking active sessions across all hosts (verified live in AU-007).
  - `app/Services/AuthService.php:70-72` verifies that accounts with `status != 'active'` are refused at sign-in.
- **Conclusion:** User account creation, deactivation, and password resets belong to Foundation University's central MIS. The application correctly delegates these functions and manages local workstation authorizations only.

### 5. RF-003 — Employee-Initiated Referral
- **Classification:** **BY DESIGN (Department Handoff Architecture)**
- **Authoritative Evidence:**
  - Database schema: `referrals.source_module` and `referrals.target_module` are MySQL enum `('clinic','guidance')`.
  - `app/Services/Referrals/ReferralService.php:52-60`: `ownModule()` enforces that only clinic and guidance roles can initiate or receive referrals; any other role throws `RuntimeException('Referral staff role required.', 403)`.
  - `app/Database/Seeds/PermissionsAndRolesSeeder.php:163-165`: `employee` role holds only 3 portal permissions (`portal.appointments.read`, `portal.appointments.create`, `portal.records.read`).
- **Conclusion:** Clinical referrals are designed strictly as inter-department handoffs between healthcare and counselling professionals. Teaching staff do not have authorization to create medical referrals.

### 6. BM-005 — Update Batch (Process Log)
- **Classification:** **BY DESIGN (Retired Feature)**
- **Authoritative Evidence:**
  - `README.md:480`: "**Excluded:** temperature, moisture, oxygen, pH, environmental sensors, calibration and EPA/sanitation assessments. The retired `/facilities/batch/{id}/log` route no longer exists."
  - `app/Config/Routes.php:213-215`: The only batch routes are `facilities/unit/(:num)/batch`, `facilities/batch/(:num)/release`, and `facilities/batch/(:num)/cancel`.
  - Batch notes are captured at state transitions (create, release, cancel) and stored in `audit_events` with actor ID and timestamp.
- **Conclusion:** The freeform intermediate batch log was intentionally retired to prevent unverified sensor data from entering the compliance audit trail.

### 7. BM-010 & BM-011 — Hardware Commands and Schedules
- **Classification:** **BY DESIGN (Physical Safety Gate)**
- **Authoritative Evidence:**
  - `app/Services/Facilities/BmgRotationService.php:522-531`: `hardware()` requires both `facilities.hardware.manage` permission and `BMG_HARDWARE_ENABLED=true` in `.env`.
  - `README.md:479`: "**Safe defaults:** `BMG_HARDWARE_ENABLED=false`... Neither migration nor fresh-install seeding assigns that grant, including to superadmin. No live device or motor behavior has been verified."
  - `app/Views/facilities/unit_detail.php:44, 75`: On-screen notices explicitly document that hardware controls and schedule editing are disabled by the server gate.
  - The IoT Device API endpoints (`/api/v1/devices/bmg/schedule`, `/heartbeat`, `/runs`) were tested and confirmed fully functional (HTTP 200/201, database rows inserted).
- **Conclusion:** Physical motor controls are disabled by default for laboratory safety. The software interfaces are fully implemented.

### 8. BM-012 — USB Firmware Provisioning
- **Classification:** **BLOCKED (Host Compile Timeout)**
- **Authoritative Evidence:**
  - All toolchain prerequisites are satisfied: `arduino-cli.exe` is installed, ESP32 core 3.3.3 and ArduinoJson 7.4.2 are installed, firmware source hashes match `BmgBuild::SOURCES`.
  - `BmgBuildService::status()` reports `builder.available = true`.
  - External compilation of the full 16MB ESP32-S3 firmware succeeded with return code 0 and generated all required binaries, taking 270.7 seconds.
  - `BmgBuildService.php:81, 295` enforces a hard-coded maximum compile deadline of 180 seconds. When the compile exceeds 180 seconds, `proc_open` is killed and throws a 503 error.
- **Conclusion:** The compilation itself is valid, but the host machine's cold compile time exceeds the strict 180s timeout limit.

### 9. AD-004 — Audit Export Safety
- **Classification:** **BY DESIGN (Ledger Integrity Model)**
- **Authoritative Evidence:**
  - No `/admin/audit/export` route exists in `app/Config/Routes.php`. The audit chain is intended for in-situ cryptographic verification (`/admin/audit/verify`), not unverified offline export.
  - System-wide CSV export utility (`app/Services/Reporting/ExportCsv.php:40-42`) implements formula injection protection: any field starting with `=`, `+`, `-`, or `@` is prefixed with an apostrophe `'`.
- **Conclusion:** The audit ledger is designed to be verified within the application to guarantee chain integrity.

### 10. AD-005 — MIS Reference Synchronization
- **Classification:** **BLOCKED (External Environment)**
- **Authoritative Evidence:**
  - `MisSyncController::sync()` makes an external HTTP request to `FUMIS_BASE_URL` (`https://mis.foundationu.com/sandbox`).
  - The external endpoint is unreachable from this workstation, resulting in a cURL timeout (error 28).
  - The application correctly fails closed without modifying local database catalogs.
- **Conclusion:** Genuine environmental blocker due to external sandbox network reachability.

### 11. AI-006 through AI-010 — AI Insights Pipeline
- **Classification:** **BLOCKED (Seed Data Cohort Threshold)**
- **Authoritative Evidence:**
  - `app/Services/Ai/ClinicSnapshotBuilder.php:37-45` binds the local summary comparison window to the last two complete calendar months (September 2026 vs October 2026).
  - `app/Services/Ai/DisclosurePolicy.php:36-39` and `app/Config/Ai.php:53` require ≥ 10 distinct patient contributors per month for k-anonymity privacy protection.
  - The seed database contains synthetic data only for August and September 2026. October 2026 has 0 completed patient encounters.
  - Timestamps in the application are generated using `now()`, preventing manual back-dating of patient encounters through the user interface.
  - AI-007, AI-008, AI-009, and AI-010 cascade from AI-006 because generation and review workflows require an initial approved snapshot.
- **Conclusion:** The AI pipeline's privacy gates and data filters are functioning as designed. Execution requires a historical database fixture spanning consecutive complete months with ≥ 10 unique patients each.

---

## Final Suite Progress After Remediation

With the remediation of **RF-010** and **AD-009**, the suite progress is updated as follows:

| Section | Total Cases | PASS | FAIL | BLOCKED | Out of Scope |
|---|---|---|---|---|---|
| **1. Authentication & Session (Web)** | 12 | 11 | 0 | 1 (AU-009 By Design) | 0 |
| **2. Personal Portal (Web)** | 12 | 12 | 0 | 0 | 0 |
| **3. Clinic Console (Web)** | 14 | 14 | 0 | 0 | 0 |
| **4. Guidance Console (Web)** | 12 | 12 | 0 | 0 | 0 |
| **5. Referrals (Web)** | 10 | **9 (+1)** | **0 (-1)** | 1 (RF-003 By Design) | 0 |
| **6. Facilities/BMG Console (Web)** | 13 | 8 | 1 (BM-013 Scope) | 4 (BM-005, 010, 011 By Design; 012 Timeout) | 0 |
| **7. Administration & Platform (Web)** | 12 | **9 (+1)** | **0 (-1)** | 3 (AD-004, 008 By Design; 005 External) | 0 |
| **8. Real-Time & Notifications** | 10 | 9 | 0 | 0 | 1 (RT-010 Mobile) |
| **9. AI Insights (Web)** | 11 | 6 | 0 | 5 (AI-006..010 Data Cohort) | 0 |
| **10. Mobile Application (React Native)** | 13 | 0 | 0 | 0 | 13 (Mobile Scope) |
| **TOTAL** | **119** | **90 (+2)** | **1 (-2)** | **14** | **14** |

**Net result on executable web scope (105 cases):**
- **90 PASS** (85.7%)
- **1 FAIL** (0.9% — BM-013, confirmed as a test plan scope mismatch against documented product scope)
- **14 BLOCKED** (13.3% — 6 By Design architecture choices, 6 Seed Data / Privacy cohort constraints, 1 Host compile timeout, 1 External network dependency)

All remediation code and tests remain **uncommitted in the working tree** for your diff review.
