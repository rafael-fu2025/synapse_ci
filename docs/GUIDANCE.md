# Guidance concurrency and numbering

Guidance writes use short, tenant-first transactions. They serialize case
numbering, session numbering, note versions, queue-linked session intake, and
follow-up mutations. This does not change the note ciphertext format, historical
timestamps, or existing encrypted rows.

## Lock order

Every `GuidanceCaseService` mutation acquires the tenant row first, then only the
resources it needs in this order:

1. queue-linked appointment (when present)
2. queue ticket
3. counseling case
4. counseling session
5. notes or follow-up row

`QueueService` uses the same appointment-before-queue order for check-in and
completion. Guidance writes join an existing caller transaction without
committing, rolling back, or resetting it; the caller remains responsible for the
entire transaction.

This is not a claim of a repository-wide deadlock-free lock graph. Referral
handoff and scheduling can hold other rows before entering guidance. A deadlock or
lock-wait failure rolls back the owned transaction; the operation must be retried
as a whole, never statement by statement.

## Numbering

- **Case numbers:** `GC-YYYY-NNN`, allocated under the tenant mutex using the
  current read of the latest case number, including soft-deleted cases. The year
  is the Asia/Manila calendar year.
- **Session numbers:** `MAX(session_number) + 1` for the case under a current
  read, including soft-deleted sessions. Deleted sessions retain their number.
- **Note versions:** finalized notes use `MAX(version) + 1` for the session under
  a session lock. `supersedes_note_id` points to the previous finalized note.
- **Drafts:** exactly one version-0 draft per session. Draft autosaves replace
  that row; finalization inserts the immutable version and deletes the draft in
  the same transaction.

The database backstop is migration
`2026-10-19-000004_HardenGuidanceNumbering`:

- `uniq_guidance_session_number` on
  `counselling_sessions(tenant_id, case_id, session_number)`
- `uniq_guidance_note_version` on
  `counselling_notes(tenant_id, session_id, version)`

The note index also enforces one version-0 draft per session.

## Tenant and relationship validation

Every public service read and mutation scopes the case, session, patient, actor,
queue ticket, appointment, note, and follow-up to the active tenant. Related
records are validated as a group: a ticket must match the case patient and
appointment; a follow-up session must belong to its case; a counselor must be an
active same-tenant guidance account. HTTP authorization still enforces counselor
ownership and `guidance.cases.read_any`; the service enforces tenant isolation and
record consistency.

## Migration cutover

The migration is additive but requires a maintenance cutover:

1. Stop guidance writers and take a verified backup.
2. Run the preflight. It rejects duplicate session numbers or note versions,
   inconsistent draft/version flags, invalid numbering, missing parents, and
   parent/child tenant mismatches across all rows, including soft-deleted history.
3. Apply the migration. It never renumbers, deletes, rehashes, or rewrites
   encrypted notes.
4. Deploy matching code before resuming writers.

MySQL/MariaDB DDL auto-commits. If installation is interrupted, keep writers
stopped and retry after review. `down()` only removes the two named indexes; it
does not repair or delete data. Recovery from a populated downgrade should use a
verified pre-cutover backup.

The migration was validated only against fresh, disposable MariaDB schemas. It has
not been applied to the operational database.

## Validation

```sh
python tests/_support/queue-isolated-harness.py --suite guidance --run
```

The isolated suite covers real InnoDB rollback, cross-tenant rejection, deleted
session numbering, unique-index enforcement, queue intake retry, appointment
completion, caller-owned transactions, migration preflight, and concurrent
session-open/final-note/draft operations. It uses synthetic records and a public
test encryption key, never operational counseling data.
