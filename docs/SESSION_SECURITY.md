# Authentication session security

## Deployment and migration

Run `php spark migrate --all` from the Humus directory before serving the updated
application. `2026-10-18-000001_AddUserAuthVersion` adds a non-null integer
`users.auth_version`, defaulting existing and new users to 1. Back up the database
and stop authentication traffic during deployment; do not mix old and new workers.
No new packages or background jobs are required.

Existing sessions deliberately fail closed: identities without the new `_auth`
metadata must sign in again, including authenticated predecessor files left by the
old implementation. Do not upgrade those sessions in place. Rolling back the
column requires rolling back the application too and purging session storage;
otherwise version resets could make old credentials valid again. No migration
was applied to the application's default database as part of this change.

## Expiry configuration

These CodeIgniter config/environment settings are seconds:

| Setting | Default | Meaning |
| --- | --- | --- |
| `session.authIdleTimeout` | `1800` | 30 minutes since the last successful identity check |
| `session.authAbsoluteTimeout` | `43200` | 12 hours since sign-in, regardless of activity or rotation |
| `session.timeToUpdate` | `300` | Framework automatic ID rotation interval |

For example, set `session.authIdleTimeout = 1800` and
`session.authAbsoluteTimeout = 43200` in the deployment environment. Non-positive
limits deny authentication rather than disable expiry. Malformed, missing,
future-dated, or incorrectly session-ID-bound metadata is denied too. Expiry is
checked at every `AuthenticatedUser::current()` call; successful checks update
only the idle timestamp. The absolute timestamp survives rotation unchanged.
Session storage/cookie expiration and garbage collection can end a session sooner
(the existing `session.expiration` default is 7200); they cannot extend these
authentication limits. Polling authenticated endpoints counts as activity.

## Rotation and logout

`Config\Services::session()` creates `AuthenticatedSession` using the configured
CodeIgniter handler. The framework's automatic rotation dispatches through the
same regeneration override used at login and logout.

Before rotation, authentication keys are removed while the predecessor is locked.
PHP regenerates **without deleting** the predecessor, writing an anonymous record
before releasing its lock; only the successor receives the saved authentication,
rebound to its new ID. There is no grace period, successor redirect, or automatic
credential transfer for a request presenting the old cookie. This intentionally
fails closed for concurrent requests: an old in-flight request may need a reload
or sign-in. Theme and other non-authentication preferences survive.

`session.regenerateDestroy` defaults to true, but the application override always
uses anonymous predecessor records, even if an existing environment sets the flag
to false. They are garbage-collected normally. Do not bypass the service override
or call native `session_regenerate_id()` from application code. Use the default
FileHandler or a CodeIgniter handler with equivalent exclusive locking and atomic
session writes. The file handler is covered by a real two-process lock regression;
alternative storage backends require their own integration validation. The lock
regression explicitly enables `session.use_strict_mode` in both PHP processes.
The installed CI FileHandler does not implement PHP's `validateId` callback, so
PHP strict mode alone is not an unknown-ID validation guarantee with this handler.
Authentication safety depends on the locked anonymous predecessor, ID binding,
and database/expiry checks, not on PHP rejecting a supplied old ID.

Login clears the previous identity and rotates **before** assigning new auth.
Logout clears the current identity/metadata and rotates; predecessors in that
rotation chain are already anonymous. This is **not logout-all-devices**. Independent
sessions on other devices remain valid. Already-authorized work cannot be recalled,
and restoring a backup of current authenticated session storage is not supported.

## Password replacement and version revocation

Every identity check reads the current user, role, permissions, linked person and
tenant from the database, and compares `_auth.version` with `users.auth_version`.
Cached role/permission data never becomes authoritative. Sign-in also rejects a
version change between credential verification and session establishment.

`UserModel` increments `auth_version` in the **same SQL UPDATE** as any
`password_hash` replacement (including setting it to null). This avoids a separate
read/modify/write race. Existing production password writers were inspected:
`PromoteSuperadmin` inserts or updates through `UserModel`; MIS provisioning does
not persist passwords. Inserts start at version 1 and need no revocation.

Use normal `UserModel::update()`/`save()` with callbacks enabled for password writes.
Raw SQL/query-builder writes, `updateBatch()`, and disabled model callbacks bypass
this hook: any future such password writer must explicitly increment
`auth_version` atomically. Do not expose `auth_version` as a mass-assignable field.
A trusted administrative SQL update incrementing that column can revoke all of a
user's sessions. Password replacement likewise revokes all existing sessions on
their next identity check, including copied sessions; it does not revoke MIS-issued
remote credentials or tokens. External MIS password changes are not observable
locally and are bounded here by session expiry unless explicitly revoked.

## Production HTTPS and cookies

When `ENVIRONMENT` is `production`, `Cookie.secure` and
`App.forceGlobalSecureRequests` now default to true. The existing required
`forcehttps` filter enforces HTTPS and HSTS **only when the effective**
`app.forceGlobalSecureRequests` **value is true**. Development and testing keep
HTTP-compatible defaults. Normal `cookie.secure` and `app.forceGlobalSecureRequests`
environment overrides still take precedence: an explicitly configured false is
not silently changed. **Warning:** production defaults are not unconditional
enforcement. Explicit `cookie.secure = false` permits session cookies over HTTP;
`app.forceGlobalSecureRequests = false` disables the required filter's HTTPS/HSTS
behavior. Audit deployment overrides before release; neither false value is
automatically corrected or rejected.
Set the production base URL to HTTPS and configure trusted proxy IPs correctly
when TLS terminates at a proxy. Do not trust arbitrary forwarded HTTPS headers.

## Regression tests

Run from Humus:

```sh
php vendor/bin/phpunit --no-coverage --filter 'AuthSessionSecurityTest|SessionRotationSecurityTest|ProductionSessionConfigTest'
php vendor/bin/phpunit --no-coverage
```

The new focused tests use an isolated in-memory SQLite authentication schema and
temporary file sessions. They do not migrate/repair the existing MySQL test data.
SQLite here tests the version migration's up/down/backfill and auth behavior, not
all Synapse migrations or MySQL-specific DDL. The concurrency test requires
`proc_open`; the database tests require the existing PHP SQLite3 extension.
Legacy test fixtures injecting `user`/`tenant_id` directly must now also provide
valid `_auth` metadata (or sign in through `AuthService`); do not add a production
bypass to accommodate synthetic sessions. The focused existing session-hardening
and authorization fixtures have been updated to the new contract.

### Validation in this workspace

The full suite currently stops 409 database-backed cases at the existing
`AddDepartmentStaffAssignments` preflight: tenant 1 provider user 34 lacks an
existing same-tenant employee person link in `humus_db_test`. The test data was not
repaired. Five inventory route-contract assertions also use legacy malformed
identities and expect downstream responses rather than authentication rejection.
Specifically, `actorWithoutId()` lacks both `user.id` and `user.tenant_id`; the
student fixture supplies `user.id` but lacks `user.tenant_id`. Both shapes fail
the original structural identity guard before the new `_auth` check, so simply
adding `_auth` cannot make them valid. They were not previously valid fixtures
under that guard; no policy bypass or unrelated inventory assertion change was made. These are not a passing end-to-end validation.
The isolated focused suite avoids that shared schema and its auth/BMG cases pass.

A targeted inventory comparison also reproduced exactly the same five failures
(26 tests, 314 assertions) with the pre-`_auth` `current()` behavior reconstructed
in memory in a separate PHP process. Application files were not changed for that
comparison. This is evidence about these fixture shapes, not a complete Git
baseline (Synapse is untracked in the profile-level repository).
