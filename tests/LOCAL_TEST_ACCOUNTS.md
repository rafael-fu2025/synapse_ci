# Complete local test accounts

Implementation is opt-in and development-only. No production models, authentication,
seeders or shared configuration are changed. Do not run a seeder to use these fixtures.

## Validate without the configured database

From the repository root:

```sh
php vendor/bin/phpunit -c tests/_support/local-test-accounts-phpunit.xml
```

Requires PHP 8.2+, SQLite3 and the installed development dependencies. This suite's
bootstrap explicitly disables dotenv loading. Service tests use in-memory SQLite;
CLI subprocesses use disposable SQLite files under `tests/_support`, deleted on exit.
No configured MySQL server, migrations, seeders, real identities or MIS network
requests are used. The login tests exercise the existing `AuthService` with a real
SQLite identity schema, a session test double, and a MIS mock that forbids every call.

## CLI (run manually after reviewing the preview)

The application must already be configured with `ENVIRONMENT === 'development'`.
The command cannot override the runtime environment. Normal Spark boot uses normal
application configuration; the isolated test bootstrap is not part of Spark boot.

```sh
# Default: read-only preflight for CurrentTenant::id()
php spark synapse:test-accounts

# Read-only preflight for a specific active tenant
php spark synapse:test-accounts --tenant 1

# Explicit write; only run after reviewing the preview
php spark synapse:test-accounts --create --tenant 1

# Explicit reset of the complete existing synthetic set (development only)
printf '%s\n' 'password123' | php spark synapse:test-accounts --reset-password --tenant 1
```

Use space-separated options as shown. There are no password arguments, environment
options or interactive prompts. Errors return a nonzero exit code and sanitized
messages. Drivers other than MySQLi/SQLite3 are refused. MySQLi requires the literal
hostname `localhost`, `127.0.0.1` or `::1`, and transactional InnoDB identity tables.
All DSNs and all failover lists are refused, even if they appear local. The tenant
must exist, be active and not deleted.

The matrix reports `would_create`, `created` or `skipped`. Creation emits one shared
cryptographically random 32-character password line **only if accounts were created**.
That password works only for those newly created accounts, not previously existing
ones. Keep terminal output private and do not redirect it into logs. Plaintext is
never stored by the implementation; each new account receives a `PASSWORD_DEFAULT`
hash. Ordinary preview/create reruns preserve every existing hash, auth version, role,
status and grant, and neither reveal nor rotate existing passwords.

`--reset-password` is mutually exclusive with `--create` and reads 8–72 password
bytes from piped STDIN, never command-line arguments. All 15 exact fixtures must
already exist and pass collision checks. The reset commits all hashes together,
increments each account's `auth_version` to revoke sessions, and reports
`password_reset` without printing the password. Nonfixture accounts and all role
grants remain untouched. The local tenant-1 set was explicitly reset to
`password123`; this is only for isolated local testing, never production.

Service API for an explicit reset: `$service->run(false, 1, $newPassword)`.
The result contains no plaintext `password` key for resets.

## Identities

| Username | Role | Employee department / student profile |
| --- | --- | --- |
| test.superadmin | superadmin | Test Administration |
| test.clinic.admin | clinic_admin | Health Services |
| test.clinic.staff | clinic_staff | Health Services |
| test.clinic.staff2 | clinic_staff | Health Services |
| test.guidance.admin | guidance_admin | Guidance |
| test.guidance.supervisor | guidance_supervisor | Guidance |
| test.counsellor | counsellor | Guidance |
| test.counsellor2 | counsellor | Guidance |
| test.bmg.admin | bmg_admin | Facilities |
| test.facilities.op | facilities_op | Facilities |
| test.facilities.op2 | facilities_op | Facilities |
| test.audit.reader | audit_reader | Test Administration |
| test.report.viewer | report_viewer | Test Administration |
| test.student | student | BSIT / year 3 / TEST / CCS |
| test.employee | employee | Test Employee |

Every account, including superadmin, has a linked synthetic person and exactly one
profile. Names are `Test <role label>`; emails are `<username>@synapse.invalid` in both
users and people. MIS IDs are `SYNAPSE-TEST-` plus the uppercase username suffix with
periods replaced by hyphens (for example, `SYNAPSE-TEST-CLINIC-ADMIN`). Optional student
enrollment attributes remain at their schema defaults; no real enrollment is claimed.

Preflight considers all 15 before inserting anything. Identifier collisions include
deleted/suspended records, case-insensitive and cross-field identifier matches, and
other tenants. Only a complete matching identity with the expected role, active status,
synthetic person, valid local hash and correct profile/linkage can be skipped. Partial
or modified fixtures cause the whole operation to fail, without repair. Inserts into
people, profiles and users commit together or roll back together. Nothing creates
clinical data, provider assignments, schedules, messages, permissions or grants.
Clinic employee departments are exactly `Health Services`; guidance admins,
supervisors and counsellors use exactly `Guidance`. These normalize to the exact
`health services` / `guidance` values accepted by the existing
`AppointmentService::roster()` and `staffAssignment()` checks. Regression tests
check these exact labels and confirm provisioning leaves provider assignments empty.
Provider enrollment and availability are intentionally not created and still
require explicit workflows. Existing fixtures with other department values are
refused as incompatible, not automatically updated.

## Service API

```php
use App\Services\LocalTestAccounts;

$accounts = new LocalTestAccounts(); // Enforces actual ENVIRONMENT by default
$preview = $accounts->run();        // Dry-run; tenant defaults to CurrentTenant::id()
$preview = $accounts->run(false, 1);
$result = $accounts->run(true, 1);   // Explicit write
```

The result contains `tenant_id` and `accounts`. Each matrix row includes `username`,
`email`, `mis_id`, `role`, `kind`, nullable `user_id`/`person_id`, `status`, and boolean
`newly_created`. The top-level `password` key exists only after successfully committed
new accounts. Do not log or persist that result. Guard/collision failures throw
`DomainException`; database/internal failures throw a sanitized `RuntimeException`
without the original exception chain.

Only isolated tests should use trusted dependency injection:

```php
$service = new LocalTestAccounts(db: $isolatedSqliteConnection, environment: 'development');
$preview = $service->run();
```

The CLI always supplies the actual runtime constant, never a user-provided value.
