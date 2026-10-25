# Authorization and record-isolation audit

## Scope and interpretation

Audited the current working tree's twelve seeded roles, route/filter boundaries,
record ownership and tenant predicates. The initial audit was read-only. The
subsequently approved remediation fixes AUTH-01, AUTH-02, AUTH-03 and AUTH-04 as
described below. No permission grants, deployment configuration, operational data
or hardware were changed. This is not a production penetration-test certificate.

The expected matrix below comes from `PermissionsAndRolesSeeder`, fresh identity
resolution and explicit service restrictions. It is not a substitute for an
organizational access policy approved by the university. Live role grants were
not queried and may differ from seeded defaults.

Runtime evidence uses private SQLite records, real seeded grants and database-
backed identities. The new audit fixtures explicitly invoke services/controllers
and relevant filters; they do not execute the complete web-server/router stack.
The operations/device assessment is source review only unless stated otherwise.

## Findings and priorities

| ID | Priority | Finding | Evidence status |
| --- | --- | --- | --- |
| AUTH-01 | High — fixed | Installation-base-path handling skipped guidance assignment guards | Original synthetic reproduction now asserts denial; routed deployment and independent service guards are in CI |
| AUTH-02 | Medium — fixed | Slots API did not enforce scheduling-grant revocation | Endpoint now requires the fresh unit-specific read grant; revoked-grant regressions are in CI |
| AUTH-03 | Medium, workflow integrity — fixed | Survey publish/close was not bound to the reviewed version | Publish and close now validate version under the record lock and increment on success; stale approvals return 409 |
| AUTH-04 | Low / policy decision — fixed | Referral source-encounter GET and POST had different permission requirements | Referral creation with an attached encounter now requires fresh `clinic.encounters.read` before the transaction and after the tenant mutex |

## Approved remediation: AUTH-01, AUTH-02, AUTH-03 and AUTH-04

- `WebAccessFilter` now matches the framework's normalized `getRoutePath()` rather
  than stripping a hardcoded front-controller name from the full installed path.
  Root, subdirectory, nested base path and configured front-controller cases retain
  the same record, role and grant checks for reads and writes.
- Guidance case packages and current notes now independently require fresh
  `guidance.cases.read` authority, same-tenant/person linkage, and ownership or
  `guidance.cases.read_any` oversight. Unauthorized note requests fail before any
  ciphertext query, including when no note exists. Historical-note authorization
  is unchanged. Private package assembly preserves originating create/write
  responses without adding an unrelated read requirement to those operations.
- Slots now use the existing scheduling guard with `write: false`, requiring
  `clinic.appointments.read` or `guidance.schedule.read`. Write/manage alone does
  not substitute for read. Existing write/queue callers retain their original
  argument meanings and requirements. Seeded booking roles have the read grant.
- Survey publish and close now require the reviewed positive integer version
  under the record lock before state changes. Stale or missing approvals return
  `409` without side effects, audit writes or publication fanout. Successful
  transitions increment the version and require exactly one affected row. Forms
  in `surveys/index.php` carry escaped hidden versions.
- Creating a referral with `source_encounter_id` now enforces fresh
  `clinic.encounters.read` authorization and clinic department membership both
  upfront and again after the tenant mutex wait, before referral lookup/read or
  idempotent replay. Plain referrals without encounters are untouched.

The following original finding descriptions retain the historical evidence and
impact limits; their insecure behavior is no longer expected.

### AUTH-01: installation path bypasses record-level guards (fixed)

**Cause at audit time:** `app/Filters/WebAccessFilter.php` derives its permission/record-routing
path from `getUri()->getPath()` and removes only a leading `index.php`. A deployment
under `/campus/` retains that prefix, so expressions expecting `guidance/...`,
`clinic/...`, `facilities/...` and `portal/...` do not match. The framework already
provides the normalized `getRoutePath()`.

Example installed URL:

```text
/campus/index.php/guidance/cases/12/photo
```

The genuine `SiteURI` identifies its route as `guidance/cases/12/photo`, while the
filter sees the installation prefix. `PortalAccessFilter` admits a counsellor to
the Guidance department but does not perform the missing case-assignment check.

**Executed evidence:**

- Root installs, with and without `index.php`: an ordinary counsellor is denied
  another counsellor's case with 403.
- `/campus/` installs, with and without `index.php`: the same record filter allows
  the request despite no `guidance.cases.read_any` grant.
- With explicitly invoked department and record filters, the actual
  `GuidanceController::casePhoto()` returned 200 and the unassigned synthetic
  person's fallback SVG. No stored operational photo was accessed.
- Revoking `clinic.patients.read` produces a root filter denial but the subdirectory
  filter admits the request. The patient directory service independently checks
  this grant, so a complete patient-chart disclosure was not established.

**Impact limits:** same-tenant guidance assignment enforcement is bypassed at its
HTTP boundary. `caseView()` fetches the package and current session note, and the
current-note service checks tenant relationships but not assignment independently.
This makes the missing boundary security-sensitive; a decrypted-note HTTP response
and unauthorized clinical write were **not** executed. Tenant-scoped models remain
an independent boundary; this is not a confirmed cross-tenant disclosure.

**Remediation:** normalize the route once from the framework's installation-aware
route path, apply every guard against it, and test root/index/subdirectory/combined
installations. Add independent guidance read authorization in the domain service
so the sole record boundary is not a URL-pattern filter. Preserve legitimate
`read_any` oversight. Convert the diagnostic reproductions to deny/read-ownership
regressions after fixing; do not simply change their expected status to green.

Sources: `WebAccessFilter::before()/records()/caseAccess()`,
`PortalAccessFilter::before()`, `GuidanceController::caseView()/casePhoto()`,
`Guidance/GuidanceCaseService::getDecryptedSessionNote()/getSession()`.

### AUTH-02: slot visibility survives grant revocation (fixed)

At audit time, `AppointmentController::slots()` refreshed the actor, but called
`AppointmentService::assertStaff()` rather than a scheduling-permission check.
That guard validates tenant/account/department role, not the scheduling grants.
The slots API path is also outside the calendar permission branch in
`WebAccessFilter`.

**Executed for clinic staff and counsellors:** remove both corresponding scheduling
read and write/manage grants. The calendar request receives 403; the slots endpoint
still returns 200, slot timestamps and synthetic occupied/free flags.

No patient identities, appointment reasons or notes were returned. Wrong roles,
opposite-department staff and foreign provider assignments retain separate denials.

**Remediation:** require an explicit appropriate scheduling capability at the slots
endpoint. Decide whether read or write/manage is sufficient for its booking use,
then test normal roles, revoked grants, wrong unit and foreign assignment IDs.
Do not weaken the role or tenant checks to make the booking UI work.

Sources: `AppointmentController::slots()`,
`Scheduling/AppointmentService::assertStaff()/getAvailableSlots()`,
`Routes.php` API registration, `WebAccessFilter` scheduling branch.

### AUTH-03: stale survey approval can target a newer version (fixed)

At audit time, draft updates used optimistic versions, but survey publish/close forms and service
transitions do not compare the version reviewed by the approver. Publishing locks
and uses the current draft, which may have changed since approval was initiated.

A stale `version=1` direct close request closed a synthetic version-7 survey.
Successful publication/fanout was not executed because that path uses MySQL-specific
SQL; the absence of a version comparison is established by the source path.
This is workflow integrity, **not** a demonstrated role or tenant escalation.

**Remediation:** submit the reviewed version, compare it under the row lock,
reject stale approval with 409, and increment versions consistently. Test concurrent
edit/publication on disposable MySQL data. Treat immutable-answer and close policies
separately from draft editing.

Sources: `app/Views/surveys/index.php`, `SurveyController::publish()/close()`,
`Guidance/SurveyService::update()/publish()/close()`.

### AUTH-04: source-encounter association needs a policy decision (fixed)

At audit time, referral GET prefill explicitly required `clinic.encounters.read`; direct referral
creation POST requires `referrals.create` and validates the source encounter's
same-tenant/same-person relationship, but does not require encounter-read authority.
The normal clinic seed grants both, so this distinction appears after restriction.

GET denial and the authorized POST path were reproduced. A completed referral
creation transaction was not executed. No encounter contents were disclosed, and
foreign-tenant/wrong-patient/deleted relationships remain rejected.

**Decision:** should creating a referral with `source_encounter_id` require the same
encounter-read grant as the prefill screen? If yes, enforce it in the creation
service, not just GET/UI logic, and add transaction-level denial tests.

Sources: `ReferralController::create()/store()`,
`Referrals/ReferralService::authorize()/create()`.

## Seeded role matrix

“Personal” means the actor's own linked person, not arbitrary submitted person IDs.
Staff inherit the three employee self-service grants in `UserModel`; revoking one
staff-role grant does not revoke an independently inherited employee baseline.

| Role | Clinical console | Guidance case access | Facilities | Access administration | Audit | Export | Personal |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `superadmin` | Clinic | All same-tenant cases | Manage/configure; hardware needs explicit grant | Platform roles | Yes | All operational units and identified surveys | Yes, subject to person-dependent operations |
| `clinic_admin` | Clinic | No | No | Restricted Clinic employee/staff assignments | No | Clinic operational | Yes |
| `clinic_staff` | Clinic; inventory manager-only operations excluded | No | No | No | No | No seeded export grant | Yes |
| `guidance_admin` | Directory lookup, not Clinic console | Same-tenant oversight (`read_any`) | No | Restricted Guidance employee/counsellor assignments | No | Guidance operational and identified surveys | Yes |
| `guidance_supervisor` | Directory lookup, not Clinic console | Same-tenant oversight (`read_any`) | No | No | No | No seeded export grant | Yes |
| `counsellor` | Directory lookup, not Clinic console | Assigned cases | No | No | No | No seeded export grant | Yes |
| `bmg_admin` | No | No | Manage/configure; no seeded hardware grant | No | No | BMG operational | Yes |
| `facilities_op` | No | No | Manage; not configure/hardware | No | No | No | Yes |
| `audit_reader` | No | No | No | No | Yes | Cross-unit aggregate operational | Yes |
| `report_viewer` | No | No | No | No | No | Cross-unit aggregate operational | Yes |
| `student` | No | No | No | No | No | No | Yes |
| `employee` | No | No | No | No | No | No | Yes |

Additional restrictions:

- Clinic/Guidance admins manage their own department announcements; Guidance
  admins/supervisors manage surveys/bank and read identified responses. Ordinary
  counsellors and Clinic staff do not receive those content-management grants.
- The identified-export role allowlist includes Guidance supervisors, but their
  default role lacks `reports.export`; the effective default is denied.
- Carousel routes require `rbac.manage`; Clinic and Guidance admins therefore
  intentionally have carousel access under the checked-in policy. A product rule
  that carousel management is superadmin-only is not currently encoded.
- Hardware authority is deliberately absent from every seeded role, including
  superadmin. Facilities services explicitly enforce this exception.
- Teams membership is role/department-derived. Same-team task editing requires
  creator, assignee or manager; calendar edits require creator/manager. `mine` is a
  view filter, not a privacy boundary. These operations were source-reviewed.

## Tested protections and reviewed boundaries

Runtime checks exercised fresh authority, inactive/deleted users/tenants, changed
session versions, forged cached privileges, revoked grants, malformed/foreign
person IDs, other-counsellor records, cross-tenant case/session IDs, provider-unit
boundaries, published/audience content visibility, identified-response ownership
joins, recipient-owned notification reads and self-owned photo resolution.

No cross-tenant or foreign-owner disclosure was confirmed in those runtime paths.
This does not negate AUTH-01's same-tenant assignment bypass or certify every query.

Source review found additional tenant/role guards for inventory, employee access,
carousel, audit, team/calendar and device-token routes. Those findings are not
presented as executed HTTP tests. In particular:

- Department admins cannot grant superadmin or modify themselves through the access
  service; target identities are tenant/department scoped and versions rotate.
- Inventory catalog/adjustment/status/reversal operations require manager roles,
  even when ordinary staff have the generic inventory-write grant.
- Device tokens select their own tenant/device binding; revoked/replaced bindings
  fail authentication, and acknowledgement IDs remain bound to the issuing device.
  Device credentials do not establish a human browser identity.
- Identified survey CSV uses constrained range/size, formula escaping and
  audit-before-release. Aggregate operational reports do not grant raw clinical
  note/identified-answer access.

## Revocation and unresolved policy questions

- Realtime grants are intentionally short lived: default 60 seconds, maximum 300.
  A pre-issued ticket remains valid until expiry after account revocation. A real
  signed synthetic-ticket test confirmed this documented window and channel
  isolation; no confidential payload disclosure was demonstrated. Confidential
  transport would require stronger revocation semantics.
- Queued motor commands do not recheck the creator's current human authority at
  delivery. Delivery still checks binding/hardware/state/expiry. Whether commands
  should be cancelled on authority revocation is a policy decision; no race or
  hardware execution was tested.
- Some operations authorize before waiting for a tenant mutex. Mid-request
  revocation behavior needs a separate concurrency test; subsequent requests use
  refreshed authority. No race exploit was demonstrated.

## Reproduction and validation

Audit evidence files:

- `tests/unit/ClinicalAccessAuditTest.php`: the AUTH-01, AUTH-02 and AUTH-04
  reproductions now assert expected denial, alongside assigned/wrong-role/foreign-ID
  controls.
- `tests/unit/ContentOwnershipAuditTest.php`: the AUTH-03 reproduction asserts
  409 rejection of stale approval and proper version increment on valid approval.

All four findings now have dedicated expected-denial and legitimate-access
regressions included in CI: `SlotsAuthorizationTest`,
`GuidanceReadAuthorizationTest`, `DeploymentAuthorizationTest`,
`SurveyTransitionVersionTest`, `ReferralEncounterAuthorizationTest`, and the
expanded `BackendRoleAuthorizationTest`. Actual MySQL transaction, fanout and
idempotency behavior is exercised by `ApprovalAttachmentServiceTest` via the
guarded fresh-database runner.

Run only through the isolated bootstrap, which skips dotenv and pins both database
groups to private SQLite memory connections:

```sh
BMG_ISOLATED_TESTS=1 php vendor/bin/phpunit --no-configuration --bootstrap tests/_support/bmg-isolated-bootstrap.php --no-coverage --do-not-cache-result tests/unit/ClinicalAccessAuditTest.php tests/unit/ContentOwnershipAuditTest.php
```

After remediation, the isolated command with both audit files plus
`SlotsAuthorizationTest` and `GuidanceReadAuthorizationTest` passed:
**48 tests, 1,257 assertions**. The clinical and content fixtures supply and restore
synthetic counselling/survey keys; no deployment keys or environment files are
required.

The expanded `phpunit.ci.xml` baseline passed: **683 tests, 8,397 assertions**,
including **100 authorization remediation tests** across all four addressed
findings. The initial audit-stage baseline attempt timed out; this completed
remediation-stage run supersedes that incomplete result, not any unfiltered
historical-suite certification.

The guarded fresh-schema service run passed: **31 tests, 707 assertions**,
including the new `ApprovalAttachmentServiceTest` exercising real MySQL
transactions, publish fanout and referral creation/replay under tenant locks.
Fresh schema cleanup was verified. PHP syntax and scoped whitespace checks passed.

No application migrations, operational database inspection, actual photos, real
private notes, deployment encryption keys or device operations were used for this
audit.

## Recommended order of work

1. **Done:** installation-aware route matching, independent Guidance read guards,
   routed deployment denials, and legitimate owner/oversight controls.
2. **Done:** explicit slots read capability and immediate revoked-grant regressions.
3. **Done:** survey publication and close bound to reviewed version with optimistic lock.
4. **Done:** source-encounter association requires encounter read authority at both prefill and store.
5. **Open policy review:** device/realtime revocation semantics (short-lived tickets vs revocation pushes).
6. Validate the actual deployment's proxy/rewrite configuration and live integration
   boundaries separately; isolated tests do not certify an installed environment.
