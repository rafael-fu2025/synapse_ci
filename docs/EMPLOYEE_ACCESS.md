# Employees & Access

Open **Administration → Employees & Access** (`/admin/access`) on the main Synapse host. On dedicated department hosts the sidebar link points to `synapse.localhost` / the configured main university host. Sessions remain host-only: an administrator may need to sign in again on main. Cross-host writes are rejected, not forwarded.

## Real employee onboarding

1. Have the employee sign in through MIS on the main portal with their official employee ID and existing MIS password.
2. Synapse provisions or links the same-tenant employee identity and creates a basic `employee` account. Imported directory people without a linked login do not appear in access management until this first login.
3. An authorized administrator searches by name, official ID or username, then opens **Manage access**.
4. Review the employee, department, employment/account status, current role, and the actual permission grants. Choose a new role, provide a reason (no clinical information), and confirm.
5. The employee signs in again. Their role determines their workstation destination. Subsequent MIS logins preserve the assigned role.

This screen never creates identities, changes MIS credentials, stores new local passwords, edits employment profiles, activates suspended accounts, enrolls appointment providers, or configures availability. Missing or inactive employment information must be resolved through the authoritative employee data workflow before granting privileged access.

## Assignment policy

| Manager | Assignment scope | Assignable roles |
| --- | --- | --- |
| Superadmin | Same-tenant employee accounts | Employee; Clinic staff/admin; counsellor; Guidance supervisor/admin; Facilities operator/BMG admin; audit reader; report viewer; superadmin |
| Clinic admin with `rbac.manage` | Employees whose normalized department is `Health Services`, currently Employee or Clinic staff | Employee, Clinic staff |
| Guidance admin with `rbac.manage` | Employees whose normalized department is `Guidance`, currently Employee or counsellor | Employee, counsellor |
| Other roles | None | None |

Department administrators cannot promote another administrator, grant oversight/supervisor access, change another department's accounts, or revoke higher-privilege users. BMG administrators do not currently have `rbac.manage`; superadmins handle Facilities assignments. Department matching is deliberately limited to the existing Clinic/Guidance department names (case-insensitive, trimmed), not fuzzy matching or inferred access from job titles.

Students, deleted identities, missing/mismatched employee profiles, and other tenants are excluded. Person-less break-glass superadmins are not edited through this employee-only screen. No one can change their own role. The service also rejects removal of the last active superadmin. Administrative roles should be granted only after deliberate review: they expose confidential data and/or administrative functions.

## Publishing responsibilities

Both role and permission are required:

| Content | Roles | Permission |
| --- | --- | --- |
| Clinic announcements | Superadmin, Clinic admin | `clinic.announcements.manage` |
| Guidance announcements | Superadmin, Guidance admin/supervisor | `guidance.announcements.manage` |
| Guidance surveys | Superadmin, Guidance admin/supervisor | `guidance.surveys.manage` |
| Confidential survey responses | Superadmin, Guidance admin/supervisor | `guidance.responses.read` |

The permissions panel reads current database grants, not hardcoded seeder expectations. Assignment does not seed, reset or add grants. Superadmin has special authority beyond the displayed grant list; hardware commissioning remains separately controlled. BMG publishing and Clinic survey management are not implemented.

## Revocation, transactions and audit

- Synapse currently stores **one role per account**. Selecting a new role replaces the old role; it does not add a second workstation membership.
- Choose **Employee** to revoke workstation/administrative membership while retaining personal portal access, historical records and identity links. Provider assignments and owned tasks/cases are not automatically transferred; review ongoing responsibilities separately.
- New privileged roles require both an active account and active employee status. Revocation to Employee remains possible for inactive employees.
- Each change requires a reason, checks the submitted `auth_version`, increments it atomically, and appends `access.role_changed` to the existing hash-chained audit ledger in the same transaction. Audit failure rolls back the role and version.
- The shared tenant audit mutex serializes assignment checks and audit append. Stale forms receive HTTP 409 rather than overwriting another change.
- The actor is refreshed from the database before access and again inside the transaction. Cached session roles/permissions are not trusted.
- Existing sessions on all hosts fail their next protected request because their authentication version no longer matches. This does not push a logout into an idle tab or revoke an already-running request.
- No clinical notes, passwords or entire employee profiles are copied into audit payloads. The audit records target user ID, actor, previous/new role, reason and authentication versions.
- Existing authentication, CSRF, no-store responses and canonical host policies apply.

## Validation and deployment

No new migrations or role-grant reseeding are required. Existing `users.auth_version` and hardened audit-ledger migrations must already be installed. Assignment fails closed if the audit infrastructure is unavailable. This implementation does not change real employee assignments or local test credentials.

Run isolated HTTP/database tests:

```sh
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/feature/EmployeeAccessHttpTest.php
```

Tests cover real routes and filters with a private in-memory SQLite database: scoped directory/modal rendering, actual grants, department and tenant boundaries, forged cached authority, promotion restrictions, all supported roles, stale edits, validation, CSRF, main-host writes, audit integrity/rollback, session revocation and identity preservation. They do not prove live MIS connectivity or MySQL concurrent scheduling.

The local audit prerequisite was installed through the guarded audit-only upgrade on 2026-10-19. The real-browser revoke/restore flow subsequently passed, including cross-host session revocation and live chain verification. See [employee access QA](EMPLOYEE_ACCESS_QA.md) for the exact scope, retained backup, opt-in browser command and remaining limits. Main-host staff sign-in now finishes on the personal portal before an explicit workstation switch, preserving same-origin form security and host-only sessions.
