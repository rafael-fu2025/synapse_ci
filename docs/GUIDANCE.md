# Guidance records, concurrency and numbering

## Clients & Records

The Guidance workstation has its own **Clients & Records** directory at
`/guidance/clients`. It lists existing, same-tenant students and employees by MIS
ID or name; no prior Guidance visit or login account is required. It does not
import people from MIS, register a second identity, or use Clinic health tables.

Open a client at `/guidance/clients/{personId}`. Existing
`/guidance/students/{personId}/cumulative` links remain compatible. The read-only
record organizes authorized cases/episodes, sessions/follow-ups, MI assessments
and surveys, referrals/coordination, forms, and appointments/call slips. Each
history is separately paginated; only the selected section's rows are loaded.
Counts cover all permitted records rather than only the displayed page.

Directory and record entry require a freshly authorized `guidance.cases.read`
account. Case history follows primary ownership, accepted consultation, or
`guidance.cases.read_any`; section-specific permissions still gate assessments,
survey responses, intake, safety, forms, and scheduling. Basic identity browsing
does not disclose restricted case counts, titles, notes, safety plans, or form
contents. Notes and clinical forms remain in their existing audited readers and
qualified author/co-sign workflows. Anonymous service evaluation answers are not
linked back to individuals.

Record GETs append the existing cumulative-view audit event but do not create or
update Guidance business records. Responses are private/no-store. Guest/external
client onboarding and a printable combined clinical chart are not introduced.
No schema migration or role-grant change is required for this directory.

Controlled-form metadata and preview links validate the full stored person,
case/session, episode, appointment, referral, and intake graph before inclusion in
counts or histories. Standalone confidential forms require their creator, a
current qualified supervisor, or explicit read oversight; a form-management grant
or saved supervisor snapshot alone does not grant access. Episode details use
only their explicit links, never a person's latest unrelated case or intake.

Generic and clinical draft/final readers append metadata-only read audits on the
same database connection before returning decrypted data. Legacy-note clinical
history reads and episode closure reads have the same fail-closed audit boundary:
a missing ledger, invalid audit head, or failed append prevents content release.

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

Directory, metadata, form-context, and read-audit checks use a private in-memory
SQLite connection with no dotenv or migrations:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap tests/_support/guidance-read-bootstrap.php --no-coverage --do-not-cache-result --fail-on-skipped tests/unit/GuidanceClientDirectoryTest.php tests/unit/GuidanceClientRecordTest.php tests/unit/GuidanceEpisodeSummaryTest.php tests/unit/GuidanceReadAuthorizationTest.php tests/unit/GuidanceFormContextPolicyTest.php
php vendor/bin/phpunit --no-configuration --bootstrap tests/_support/guidance-read-bootstrap.php --no-coverage --do-not-cache-result --fail-on-skipped tests/unit/GuidanceEpisodeDetailsAuthorizationTest.php
```

Migration-backed workflow tests require the guarded disposable database runner,
not an ambient test connection:

```sh
python -I tests/_support/run-fresh-database-tests.py tests/unit/GuidanceFormServiceTest.php tests/unit/GuidanceClinicalFormServiceTest.php tests/unit/GuidanceEpisodeServiceTest.php tests/unit/GuidanceAppointmentCallSlipTest.php
```

The existing concurrency suite runs separately:

```sh
python tests/_support/queue-isolated-harness.py --suite guidance --run
```

The isolated suite covers real InnoDB rollback, cross-tenant rejection, deleted
session numbering, unique-index enforcement, queue intake retry, appointment
completion, caller-owned transactions, migration preflight, and concurrent
session-open/final-note/draft operations. It uses synthetic records and a public
test encryption key, never operational counseling data.
