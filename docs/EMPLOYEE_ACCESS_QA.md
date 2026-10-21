# Employee access flow quality check — 2026-10-19

## Verdict: local end-to-end flow passes after remediation

The initial live check failed even though isolated tests passed. Those failures were corrected and the real browser flow was rerun successfully against the local application and its MySQL database. This is local validation, not certification of university deployment, live MIS authentication, or production concurrency.

## Findings and resolutions

| Initial finding | Resolution | Verification |
| --- | --- | --- |
| Assignment returned 503 because `audit_events.hash_version` was absent | Applied only `2026-10-19-000003_HardenAuditLedger` using the guarded local upgrade command. Audit logging was not bypassed. | Backup restore, migration retry, legacy-byte comparison and v2 append rehearsed before cutover; live role changes and chain verification passed afterward. |
| Staff sign-in on main authenticated but CSP blocked the cross-host form redirect | Successful sign-in finishes on the current origin. Main-host workstation staff land on their personal portal and use explicit workstation-switch links. Department-host and plain-localhost logins retain their own appropriate landing. | Real main-host Clinic-admin login completed without the blocked redirect; nine isolated landing cases include the Facilities alias, base paths and ports. CSP and host-only cookie policy are unchanged. |
| Full-name search returned no employee | Split the query into name/identifier terms, requiring each term to match within the existing tenant/department scope. | Live complete-name search passed. Isolated tests cover reversed names, whitespace, Unicode, apostrophes and literal LIKE metacharacters. |
| Role-change details were recorded but not reviewable from the ledger | Added escaped, expandable previous-role/new-role/reason details for access events only. Arbitrary clinical audit payloads are not rendered. | Live ledger details and escaping regression test passed. |

## Local audit cutover

Command:

```sh
php spark synapse:audit-upgrade
php spark synapse:audit-upgrade --apply
```

- Read-only preflight verified all eight pre-cutover events.
- Created a private ledger backup, read it back, restored it into a randomly prefixed disposable table in the same local database, rehearsed the migration twice, and verified a new v2 append there.
- Briefly blocked all application HTTP routes, including device APIs, and paused cooperating audit appends. Drained tenant-locked writers and checked that the source history still matched the backup before DDL.
- Applied only audit hardening and recorded its migration history. The unrelated pending Guidance-numbering migration was not applied.
- Confirmed that all existing event bytes remained unchanged apart from the new legacy-version metadata, verified the chains, dropped the rehearsal table and resumed HTTP traffic.
- Private verified backup: `writable/audit-upgrade-20261019-070634-152955ad/ledger.json` with its adjacent SHA-256 digest. This is a ledger-scoped backup, not a full-database backup; keep it private.
- A separate live maintenance probe verified HTTP 503, `Retry-After: 60`, and no-store headers for login, employee administration and a device POST. Its temporary flag was removed afterward.

The development-only command is pinned to local MySQL `humus_db`. It defaults to read-only, never downgrades the ledger, does not reseed grants, and keeps maintenance active if live DDL cannot be verified. See `docs/AUDIT_LEDGER.md` for the production cutover requirements and limits.

## Passed against the actual local application

The opt-in browser test uses `test.superadmin`, `test.clinic.admin`, `test.clinic.staff2`, and `test.student` on the real `synapse.localhost` and `clinic.localhost` hosts:

1. Main-host login, exact fixture lookup, complete-name search and canonical access modal.
2. Clinic administrator role choices exclude administrator and cross-department roles.
3. Cancelling confirmation sends no assignment POST and retains the reason.
4. Cross-department employee-edit URL returns 404; student administration and Clinic Teams requests return 403.
5. Existing synthetic Clinic staff can access Clinic Teams.
6. Clinic admin successfully changes that synthetic account to Employee.
7. The old Clinic-host session fails with 401; signing in on main grants the personal portal without staff modules or administration access.
8. A previously opened stale modal is rejected with 409 and retains its entered reason.
9. Clinic admin restores Clinic staff from a fresh form.
10. The previously active main-host employee session fails with 401; a fresh Clinic login regains Clinic Teams but not Guidance or Facilities access.
11. Both persisted changes appear in the ledger with reviewable details, and the live cryptographic chain verifies.
12. Cleanup reloads the target and confirms its original `clinic_staff` role. There are no browser script errors.

The synthetic account's authentication version increases and valid audit events remain intentionally. No real employee roles, passwords, identities, employment details, grants or clinical records were changed.

## Automated results

```sh
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/feature/EmployeeAccessHttpTest.php tests/feature/PortalHostPolicyTest.php tests/feature/SidebarRoleVisibilityTest.php tests/feature/BackendRoleAuthorizationTest.php tests/feature/GuidanceDashboardAuthorizationTest.php tests/unit/AuditHashTest.php tests/unit/DashboardAuditAccessTest.php
```

**PASS: 231 tests, 2,151 assertions.** Private in-memory fixtures or database-free tests; no operational grant reseeding.

```sh
SYNAPSE_LIVE_ACCESS_TEST=1 node --test tests/browser/employee-access-live.test.cjs
```

**PASS: one complete live browser scenario**, covering the stages above. The test is skipped by default, is pinned to local hosts on port 8080, and restores the synthetic target through authenticated application HTTP in `finally`. Cleanup failures are explicit, not ignored. It never reduces authentication versions or erases audit history.

PHP lint passed for all ten changed/new PHP files; Node syntax validation passed for the browser test.

## Remaining boundaries

- Real-employee JIT provisioning and role preservation through actual campus MIS require university-network credentials and were not exercised here.
- Only one role is stored per account; multi-workstation membership is not implemented.
- Host-only sessions deliberately require another sign-in when switching hosts. This is not SSO.
- Provider enrollment/availability and transfer of owned tasks or cases remain separate from role assignment.
- The live scenario exercises Clinic assignment/revocation; Guidance and all supported role assignments have isolated coverage, not a separate live scenario for every role.
- No unrelated pending migration or broad application suite is claimed as validated by this task.
