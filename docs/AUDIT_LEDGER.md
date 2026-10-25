# Audit ledger hardening

## Scope and limits

The hash-chained ledger records announcement, survey, and BMG events emitted by
those services, plus employee role changes emitted by `EmployeeAccessService`. It does **not** currently record every login, clinic encounter,
counseling-note edit, referral, appointment, or queue mutation. Inventory has its
own append-only operation/movement ledger.

`audit.read` is required for audit counts, recent-event metadata, verification,
and direct admin dashboard access. A `reports.read` user may keep the aggregate
reports dashboard but its audit queries and sections are omitted. Both dashboard
entry points refresh authority through `AuthenticatedUser`, rather than trusting
cached session grants. Superadmin retains the existing permission bypass.

This is a tamper-evident **unkeyed SHA-256 chain**, not an externally authenticated
ledger or a production-security certification:

- A privileged database operator can remove triggers, truncate/drop tables, or
  recompute a replacement chain. Restrict application DDL privileges and protect
  backups/credentials separately. Use a migration identity with TRIGGER/ALTER
  privileges; the runtime identity should not have those privileges.
- There is no independent trusted head/count/checkpoint. Removing a suffix, or
  replacing all history with an empty ledger, cannot be distinguished from a
  legitimate shorter history by this verifier alone.
- The insert trigger enforces the new format version, not insert provenance,
  hash correctness, or predecessor linkage. Direct SQL INSERT can poison history;
  all cooperating application writers must use `AuditService`.
- Appending validates the current head's hash, not the entire preceding chain.
  Run full verification separately. Existing forks or corruption are not repaired.
- Legacy v1 events retain their original hash omissions and delimiter ambiguity.
  Introducing v2 does not retroactively strengthen or certify those fields.

## Audit Explorer

`GET /admin/audit` remains protected by `audit.read`. The reader supports exact
`event_type`, `action`, `entity_type`, `entity_id`, and `actor_user_id` filters,
plus inclusive `from`/`to` calendar dates (`YYYY-MM-DD`). Dates use the existing
server ledger timezone; this change does not convert historical timestamps.
`entity_id` remains text, while actor IDs and navigation cursors must be positive
BIGINT UNSIGNED decimals. Invalid filters return HTTP 400 without querying events.

`per_page` accepts 25, 50 (default), or 100. Events are displayed in descending ID
order. `cursor=<event-id>&direction=older|newer` uses keyset navigation, not offsets
or total-count scans. Both directions and filters are shareable in the URL; applying
filters resets navigation. New appends can appear when navigating newer or returning
to latest matching events: this is a live reader, not a frozen export snapshot.
An out-of-range cursor has a latest-matching recovery link.

Both event queries and the current-username join are tenant scoped. Existing
role-change details remain available; there is no general payload search, raw
payload inspector, or audit CSV export in this milestone. Verification still checks
the whole tenant chain from genesis, not the visible page or filtered subset.

Migration `2026-10-23-000001_AddAuditExplorerIndexes` adds nonunique tenant/ID,
tenant/event-type/ID, and tenant/actor/ID indexes. Existing time and entity indexes
are retained. The migration does not modify ledger rows, hashes, or triggers.
Review pending migrations and schedule index creation for the deployment database;
large-table DDL may block traffic. The application reader works without these new
indexes, but large ledgers should install them before broad use. No live database
migration is automatically performed by adding this code.

Database-free query/view tests and disposable SQLite reader/migration tests:

```sh
php vendor/bin/phpunit --no-coverage tests/unit/AuditExplorerQueryTest.php tests/unit/AuditExplorerTest.php tests/unit/AuditExplorerViewTest.php tests/unit/AuditExplorerDatabaseTest.php
```

These tests do not replace MySQL/MariaDB deployment verification or actual query-plan
review on a representative ledger.

## Format compatibility

Migration `2026-10-19-000003_HardenAuditLedger` adds
`hash_version SMALLINT UNSIGNED NOT NULL DEFAULT 1`. Existing rows receive version
metadata only: payload bytes, existing hashes, timestamps, and actor fields are
not rewritten. New `AuditService::log()` calls always write version 2.

`AuditHash` verifies mixed v1-then-v2 chains. It rejects unknown versions and a
return to v1 after v2. The existing verification result shape remains:

```php
['valid' => bool, 'verified_count' => int, 'broken_id' => int|string|null]
```

Normal-sized broken IDs remain integers. IDs above `PHP_INT_MAX` are returned as
canonical decimal strings, preserving the database's BIGINT UNSIGNED range.
Malformed IDs fail verification with `broken_id = null`.

### V1

The pre-cutover algorithm is retained byte-for-byte:

```text
SHA-256(prev_hash|event_type|entity_type|entity_id|actor_user_id-or-system|occurred_at|payload_json-or-empty)
```

### V2

SHA-256 covers a fixed-order JSON object encoded with `JSON_UNESCAPED_UNICODE`,
`JSON_UNESCAPED_SLASHES`, and `JSON_THROW_ON_ERROR`. Fields, in order:

1. `format`: `humus.audit.v2`
2. `hash_version`: integer `2`
3. `tenant_id`: integer
4. `prev_hash`
5. `event_type`
6. `entity_type`
7. `entity_id`: string
8. `actor_user_id`: null or canonical unsigned decimal string
9. `actor_role`: null or string
10. `actor_ip`: null or string
11. `action`
12. `occurred_at`
13. `payload_json`: null or the exact stored JSON text

Field boundaries and nulls are preserved. Actor IDs are not converted through
signed integers or floats. Invalid JSON/UTF-8 fails rather than being replaced
with empty data. Read-back checks detect database changes to the hashed fields or
the stored digest before commit, including silent truncation.

The physical auto-increment `id` is **not hash-bound**; the verifier requires
strictly increasing IDs without lossy integer casts. Timestamp conventions are
unchanged by this work.

## Transactions and lock ordering

Every append acquires an exclusive tenant-row mutex, then reads the tenant's
latest audit row using a locking/current read before inserting. This serializes
both the first event and subsequent appends, including callers with an earlier
REPEATABLE READ snapshot. Different tenants have separate mutex rows.

**An audited business transaction must lock its tenant first**, before business
inserts, foreign-key checks, or other locking reads. Otherwise shared tenant FK
locks can deadlock when upgraded during the eventual audit append.

- BMG already uses this tenant-first order.
- Announcement and survey transaction wrappers now obtain fresh authority, begin
  their transaction, and call `AuditService::lockTenant()` before their callback.
- Standalone `log()` calls own and commit/roll back their transaction.
- Inside a healthy existing transaction, `log()` participates without committing,
  rolling back, or resetting the caller's state. The caller must propagate errors
  and roll back **all** business and audit work if append fails.
- The audit model and the audit service transaction must use the same connection.

SQLite support is for existing disposable service fixtures: it takes a tenant
write lock before reading the head. Production migration protections require
MySQL/MariaDB with InnoDB and SIGNAL support.

## Database enforcement

The migration installs these prefix-aware triggers on `audit_events`:

- `{DBPrefix}audit_audit_events_update`: rejects UPDATE.
- `{DBPrefix}audit_audit_events_delete`: rejects DELETE.
- `{DBPrefix}audit_audit_events_insert`: rejects new rows unless `hash_version = 2`.

Retries validate existing column/trigger definitions and install missing pieces;
unexpected definitions fail closed. No unique predecessor constraint is added:
historical forks require investigation, not an automatic destructive repair.

## Deployment: maintenance cutover required

**This is not a rolling-compatible schema/code change.** New code requires the
version column; after trigger installation, old writers without version 2 fail.
No operational database migration was performed while implementing this change.

1. Rehearse against a protected, isolated copy and verify a recoverable backup.
2. Stop/drain all audit-writing requests and workers, including BMG and survey
   result access. Do not leave old processes serving writes during cutover.
3. Verify existing per-tenant chains with the old compatible verifier or with
   `AuditHash` over exported rows. Investigate failures before resuming traffic;
   the migration does not repair history or provide an integrity certificate.
4. Using the migration identity, apply the normal project migration process after
   reviewing pending migrations. Confirm the version column and all three triggers
   exist. MySQL/MariaDB DDL is nontransactional: on partial failure keep writers
   stopped, resolve the failure, and retry `up()` rather than removing protection.
5. Deploy the matching v2 writer/verifier code, verify chain results and authorized
   dashboard behavior, then resume traffic. Confirm the first new v2 event links
   to the unchanged legacy head on the isolated rehearsal before live cutover.
6. Do not roll back to an old writer after v2 events exist. `down()` deliberately
   refuses to discard version metadata when any non-v1 history exists. Recovery
   requires a separately reviewed plan and a verified backup; do not delete v2
   events or alter hashes just to make downgrade succeed.

## Guarded local audit-only upgrade

`php spark synapse:audit-upgrade` is a read-only integrity/history check. Adding
`--apply` explicitly authorizes a development-only upgrade against local MySQL
`humus_db`; it refuses other environments and remote/database targets.

The command writes a private ledger-scoped JSON backup (schema, trigger metadata,
migration history and exact rows) plus a checksum under `writable/audit-upgrade-*`.
It reads the file back, restores rows into a randomly prefixed disposable table in
the same private database, rehearses the migration/retry and a v2 append, then
verifies the copied chains and unchanged legacy bytes. This is not a full-database
backup and does not replace the production runbook above.

For cutover it exclusively creates `writable/audit-maintenance.flag`. A required
filter rejects all application HTTP requests, including device APIs, with 503;
`AuditService` also checks the flag before appending and again after obtaining the
tenant mutex so in-flight/cooperating CLI writers cannot cross the drain barrier.
The command drains tenant-locked transactions, rechecks the source rows/history,
and uses a locked, narrowly filtered migration runner to apply only audit hardening.
It never invokes a downgrade or runs unrelated pending migrations. Existing
version/trigger definitions are validated by the original migration.

Only successful verification removes the flag and resumes traffic. Partial or
unverified live DDL leaves maintenance active; inspect the retained backup and
installation before manually removing the flag or retrying. Never remove audit
protection or rewrite hashes to make a retry pass. Direct SQL writers and external
migration tools must still be stopped operationally; the application flag cannot
control privileged out-of-process database activity.

The local cutover was applied on 2026-10-19 after backup-restore rehearsal; eight
preexisting events were preserved. The live employee revoke/restore scenario then
appended valid v2 events and verified the mixed chain. See
[employee access QA](EMPLOYEE_ACCESS_QA.md) for results and remaining limits.

## Validation

Database-free hashing and dashboard tests:

```sh
php vendor/bin/phpunit --no-coverage tests/unit/AuditHashTest.php tests/unit/DashboardAuditAccessTest.php
```

Disposable MariaDB validation on the configured Windows workstation:

```sh
python tests/_support/queue-isolated-harness.py --suite audit --dry-run
python tests/_support/queue-isolated-harness.py --suite audit --run
```

The runner verifies a fresh server's datadir, port, and schema identity before
writes. It creates synthetic tables and applies only the audit hardening migration
there; it does not restore backups or run the full application migration chain.
The test suite skips before connecting outside the isolated bootstrap. Artifacts
are retained under ignored `writable/queue-isolated-*` directories, and owned
processes are terminated on exit/timeout. The default runner suite remains queue.

Coverage includes legacy-byte preservation, migration retries, trigger enforcement,
rollback and caller ownership, tenant-first announcement/survey wrapper races,
concurrent first-event and subsequent appends, stale-snapshot current reads,
unsigned-BIGINT identifiers, and rejection of a trigger-altered stored digest.
These tests do not establish that the full database suite or historical production
chains are healthy.
