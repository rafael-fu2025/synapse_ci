# Verified test baseline

The entire historical PHPUnit suite is **not** certified green. Several older
HTTP fixtures still need to match fresh DB-backed authentication and current
workflow contracts. The CI workflow deliberately names the verified subset;
it does not silently exclude failures from a purported full-suite run.

## Requirements

- PHP 8.2+ and Composer dependencies installed from `composer.lock`.
- `intl`, `mbstring`, `mysqli`, and OpenSSL; SQLite extensions for the separate
  disposable SQLite service fixtures.
- Python 3.10+ for the fresh-schema runner; Node 22+ for queue JS tests.
- For the fresh-schema tests: a local MySQL/MariaDB server on TCP
  `127.0.0.1:3306`, with the **test-only** root account and an empty password.
  The runner is intentionally restricted to that local test configuration;
  do not enable empty-password root access for a production deployment.
- The `mysql` client on PATH, or the configured Windows XAMPP client.

Do not point application configuration or `.env` at an operational database to
run tests. Do not run the unfiltered historical `composer test` command on a
shared development/operational schema.

## DB-free baseline

```sh
composer install --no-interaction --prefer-dist
php vendor/bin/phpunit --configuration phpunit.ci.xml --no-coverage
python tests/_support/fresh-database-runner-test.py
node --test tests/browser/queue.test.cjs
```

`phpunit.ci.xml` explicitly selects security, session, crypto, scheduling,
dashboard-permission, queue/guidance controller/model contracts, login filter ordering,
throttling errors, and browser-header/CSP output. The Python
runner tests mock subprocess/database access and verify refusal and cleanup
behavior without connecting. The Node tests exercise queue JavaScript handlers;
they are not full-browser HTTP tests.

## Fresh migrations and core service tests

Run from the project root:

```sh
python tests/_support/run-fresh-database-tests.py tests/unit/QueueServiceTest.php tests/unit/AppointmentServiceTest.php tests/unit/AuditServiceTest.php tests/unit/GuidanceCaseServiceTest.php tests/unit/LoginAttemptStoreTest.php
```

The runner:

1. Creates a randomly named `humus_fresh_<16 hex>` schema; never restores data.
2. Requires an empty schema and verifies its name before framework boot.
3. Uses a dedicated bootstrap that pins **both** default/tests connection groups,
   including DSN, driver, prefix, port, and disabled failover/persistent settings.
   Values are reapplied after framework boot because dotenv can otherwise shadow
   process environment variables.
4. Verifies both actual connections before migrations. A test base class checks
   the verified bootstrap marker and configuration before DatabaseTestTrait runs.
5. Runs the selected tests against the full migration chain with strict SQL mode.
6. Drops only the schema it successfully created, after checking identity again.
   Failure to create a schema never triggers a DROP of that name.

The five guarded service test classes **skip before migration** outside this
bootstrap. This safeguard does not extend to every old database test in the
repository. The runner bounds PHPUnit runtime and cleans up its own schema on
normal completion/test failure; abrupt process termination may leave an orphaned
fresh schema that requires explicit review and cleanup.

Synthetic encryption keys are supplied only inside the verified fresh-schema
bootstrap. Guidance tests also inject an explicit synthetic encryption service.
No deployment encryption keys or real encrypted records are required.

### Fixture alignment

- Queue calls create an actual staff user instead of assuming user ID 1 exists.
- Queue appointment promotion uses an employee profile, enrolled department
  assignment, model-managed timestamps, and the Manila appointment date.
- Appointment slots/booking use `staff_assignment_id`, not the retired assumption
  that a provider login ID is an enrollment ID. The fixture demonstrates that an
  enrolled practitioner does not need a login account.
- Booking tests use future Manila dates and valid availability windows rather than
  hardcoded dates that eventually become past appointments.
- Audit tests assert database UPDATE/DELETE rejection without corrupting the ledger.
- Login-counter tests check expiry, rollback/recovery, bounded cleanup, schema retries,
  and two workers competing for one remaining identity-budget attempt.

The `AddDepartmentStaffAssignments` preflight still rejects invalid provider-person
links in reused databases. A successful fresh migration is not a repair of that
existing data. Do not invent identities or weaken migration checks just to make
an old test database migrate.

## GitHub Actions

`.github/workflows/tests.yml` runs on push, pull request, or manual dispatch with
PHP 8.2 and 8.3, a disposable MariaDB 10.11 service, locked Composer dependencies,
PHP lint, runner safety tests, the DB-free baseline, the five fresh-migration
service files, and queue JS tests. It uses read-only repository permissions.

The workflow has been authored and its commands validated locally, but has not
been executed on GitHub from this workspace. GitHub execution requires the project
to be tracked and pushed as a repository with the workflow at its root. No Git
repository was initialized, no files staged, and no commit/push performed here.

## Separate concurrency validation

The Windows-only bounded runner remains available for real multi-process races
on a separately owned MariaDB instance:

```sh
python tests/_support/queue-isolated-harness.py --run
python tests/_support/queue-isolated-harness.py --suite audit --run
python tests/_support/queue-isolated-harness.py --suite guidance --run
```

Those suites use synthetic focused schemas, whereas the fresh-schema runner above
checks the full application migration chain. They complement rather than replace
one another. The Windows concurrency harness is not part of the Linux workflow.

## Audit blocker regressions (2026-10-19)

The first three core-flow audit findings have focused regression coverage:

```sh
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/SchedulingClockTest.php tests/feature/BackendRoleAuthorizationTest.php tests/feature/GuidanceDashboardAuthorizationTest.php tests/unit/GuidanceControllerSafetyTest.php tests/unit/QueueControllerSafetyTest.php
node --test tests/browser/bmg.test.cjs
node --test tests/browser/guidance-new-case.test.cjs
```

Verified: **139 PHP tests / 1,250 assertions**, **32 BMG browser tests**, and
**5 Guidance New Case browser tests** passed. Browser tests use production
views/scripts with synthetic HTTP responses, not real device operations or
clinical writes; the authorization fixtures use private in-memory SQLite.

- BMG form actions and success redirects are checked against a server-generated
  Facilities base URL, supporting root, `index.php`, subdirectory and combined
  deployments without accepting other origins, application prefixes or adjacent
  non-Facilities paths.
- Queue-to-chart admission, portal active-ticket selection and appointment
  check-in controls use the Manila scheduling day. Explicit clock-boundary tests
  and different-server-day fixtures prevent a UTC/local-date mismatch from being
  reintroduced. The global app timezone is deliberately unchanged.
- The New Case factory is in globally loaded `care-forms.js`, not an inline
  fragment script. Cold-load tests cover student/employee search and selection,
  hidden person binding, POST/422 retry, close/reopen cleanup, and indexed/base-path
  URLs. The modal loader still strips fetched scripts.

These are selected regressions, not a green full-suite claim. The earlier audit
also reproduced two stale `DashboardStatusContractTest` source assertions in the
DB-free baseline (session aliases and date-query structure). Those unrelated
assertions have not been changed by this fix. Case closure/no-show policy,
encounter-creation transactionality, permission/UI mismatches and other deferred
findings remain separate work. No migration, grant, private key, operational
record or hardware gate was changed by this patch.

## Validation boundary

Passing this baseline does not establish that every browser route, historical
fixture, deployment migration, or live MIS/hardware integration works. Expand CI
coverage only after the additional tests have been repaired and independently
verified on disposable data; do not relabel a selected subset as the full suite.
