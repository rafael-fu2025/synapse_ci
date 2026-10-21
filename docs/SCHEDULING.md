# Scheduling timezone

Scheduling uses **Asia/Manila** explicitly. It does not change the application's
configured timezone, PHP's default timezone, or the database session timezone.

## Storage assumption

Existing appointment dates and timestamps are assumed to be **Manila wall-clock
values**, not UTC instants. New scheduling operations use that same interpretation.
There is **no data migration**, timezone conversion of stored strings, or schema
change. If historical records were actually written in another timezone, they
require a separate audit; this change does not reinterpret or repair those records.

The formats remain `Y-m-d` for `local_date` and `Y-m-d H:i:s` for appointment and
explicit confirmation/assignment timestamps. HTML `datetime-local` input continues
to normalize `T` to a space and adds seconds when omitted. Invalid calendar dates,
times, timezone suffixes, and non-increasing intervals remain invalid. Appointment
requests retain their 30-minute preference interval; availability grids, conflict
checks, and past-time checks retain their existing constraints.

## Clock API

`App\Services\Scheduling\SchedulingClock` exposes these static methods:

```php
public static function timezone(): \DateTimeZone;
public static function now(?\DateTimeImmutable $instant = null): \DateTimeImmutable;
public static function today(?\DateTimeImmutable $instant = null): string;
public static function timestamp(?\DateTimeImmutable $instant = null): string;
```

- `timezone()` returns `Asia/Manila` for parsing wall-clock strings explicitly.
- `now()` returns the current instant expressed in Manila. An optional immutable
  instant is converted to Manila without changing the instant or its input object.
- `today()` formats that instant as `Y-m-d` in Manila.
- `timestamp()` formats it as `Y-m-d H:i:s` in Manila (not a Unix timestamp).

For example, `2026-10-19T16:00:00Z` is `2026-10-20 00:00:00` in Manila.
Use the same captured `now()` object for related values within a request when they
must agree across midnight. Do not pass stored wall-clock strings as UTC instants.

## Current integration

`AppointmentService` explicitly uses Manila when validating/parsing appointment
dates and intervals, generating slots, deriving availability weekdays, comparing
slots with now, rejecting past appointments, and recording confirmation/assignment
timestamps. `AppointmentController` uses `today()` for calendar and slot-query
fallback dates. Stored strings and model-managed audit timestamps are not rewritten.

Queue defaults, appointment same-day check-in, lifecycle timestamps, and recall
windows also use the clock explicitly. Queue board queries and their rendered date
share one captured date. Polling and actions retain the selected date filter.
Clinic/guidance daily queue counts and the clinic appointment count use the Manila
day; appointments are selected using their stored `local_date`.

Encounter/session timestamps and their historical activity series retain their
existing convention. This change does not convert those modules to Manila or
rewrite their history. Do not assume that their timestamps are interchangeable
with explicit queue lifecycle timestamps.

## Queue transition contract

- Waiting tickets may be called; called tickets may start consultation or be skipped.
- In-consultation tickets may complete. Completed tickets cannot be called, skipped,
  or recalled. Repeated start/completion requests are idempotent and preserve the
  original timestamps, supporting chart-open and chart-completion integrations.
- Skipped tickets may return to waiting only within their recall window (60 minutes
  by default). `skip_until` is the **recall deadline**, not a minimum waiting period.
  Expired tickets remain skipped; staff must issue a new ticket.
- Selection and mutation use InnoDB row locks and tenant predicates. Controller
  mutations also supply their route's unit. “Call next” selects and claims a waiting
  ticket in one transaction, retaining emergency/urgent/routine and ticket order.
- Queue completion and linked appointment completion commit together; appointment
  completion increments its version once. Appointment locks precede queue locks to
  match check-in's lock order.
- A standalone mutation owns its transaction. When joining encounter completion's
  healthy transaction, it does not commit or roll back the caller's work. Callers
  must roll back their transaction if an operation throws.
- Expected stale-state failures return HTTP 409 JSON to HTMX; the page displays the
  error without replacing the current board. Ordinary form requests redirect with
  a flash error. Missing actor IDs never default to a real user's ID.

## Tests

Run the database-free tests with:

```sh
php vendor/bin/phpunit --no-coverage tests/unit/SchedulingClockTest.php
```

Tests cover the UTC-to-Manila midnight/year boundary, unchanged default timezone,
immutable input instants, real-time defaults, appointment parsing and slot grids
under different PHP timezones (including Apia's skipped calendar date), preserved
interval validation, and the Manila past-time guard. No database or migrations are
used by these tests.

Additional focused tests:

```sh
php vendor/bin/phpunit --no-coverage tests/unit/QueueControllerSafetyTest.php tests/unit/DashboardStatusContractTest.php
```

For real locking, rollback, and two-process concurrency tests on this Windows
workstation:

```sh
python tests/_support/queue-isolated-harness.py --dry-run
python tests/_support/queue-isolated-harness.py --run
```

The runner requires the local XAMPP MariaDB/PHP executables described in its source.
It creates a fresh disposable datadir and synthetic schema, verifies server identity
before writes, pins both application DB groups to it, and terminates owned processes
on exit or timeout. It does not restore backups or run application migrations.
Artifacts are retained under ignored `writable/queue-isolated-*` directories. The
`QueueTransitionTest` suite skips before connecting when run outside this harness.
Passing it verifies the queue contract against synthetic tables, not the full
application migrations or the previously reported fixture-preflight issue.
