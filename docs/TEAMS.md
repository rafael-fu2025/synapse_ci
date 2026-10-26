# Workstation Teams — tasks, calendar and department chat

## Entry points

- Clinic: `/clinic/teams`
- Guidance: `/guidance/teams`
- Facilities: `/facilities/teams`

Each workstation sidebar includes its own Teams link. The Team board and My tasks use the same task records; My tasks filters by the signed-in assignee. Board/list display modes, search, status, priority and pagination are server-rendered. The board shows at most 100 matching tasks per page; column counts describe that page, not an unbounded backlog.

## Available workflows

- Create tasks with a title, operational description, status, priority, optional assignee and due date.
- Track To do, In progress, Blocked and Done.
- Add up to 20 checklist items, mark completion and remove items through Edit / move.
- Open a task for discussion and activity history.
- Move editable cards between status columns by desktop drag, by dragging the handle on touch/pen devices, or with the handle's keyboard controls (Space to pick up, arrows to choose a column, Enter to save, Escape to cancel). Edit / move remains the form alternative. This changes status only: custom ordering within a column is not stored.
- Task creation, editing and moves from the board/list refresh only its live-filter regions, preserving the surrounding workspace, selected filters, page and scroll. The server still checks current permissions and task versions. Conflicting or uncertain moves are not automatically replayed; refresh before retrying.
- Show overdue dates against the current Asia/Manila date. Date-only deadlines do not reserve appointment slots.
- Persist shared work to the database; no task content is stored in browser localStorage.

## Access rules

Membership is derived from active workstation roles, not a separate editable team roster:

| Workstation | Roles |
| --- | --- |
| Clinic | clinic_admin, clinic_staff, superadmin |
| Guidance | guidance_admin, guidance_supervisor, counsellor, superadmin |
| Facilities | bmg_admin, facilities_op, superadmin |

Members may view, create and comment within their tenant/workstation. Creators, assignees, workstation administrators/supervisors and superadmin may edit or change status. A task cannot be assigned to an inactive, foreign-tenant or foreign-department account. Existing tasks retain a former assignee reference for history; edit and reassign/unassign before further mutations if the assignee is no longer eligible.

Every service operation refreshes authentication. The server owns tenant, department, author and version fields. Version checks reject stale edits with HTTP 409 instead of silently replacing newer work. Writes and corresponding activity entries share one transaction. All writes are POST and CSRF-protected. Responses are private/no-store.

## Data handling

This workspace is **not a clinical record store**. Do not enter names of patients, diagnoses, confidential counseling notes, credentials or tokens in tasks/checklists/comments. All workstation members can read these operational records; task assignment grants no access to clinical records. No patient-record linking or file attachments are offered.

User-entered text is escaped on output. Activity records store the action, actor, timestamp and optional status transition, not copies of task descriptions or comment bodies. Task comments/activity display the latest 50 entries, with totals and a visible limit notice. Timestamps are stored in UTC and displayed in Asia/Manila. No delete function is provided in this release, so comments and activity remain in history.

There is no external messaging provider and no new package dependency. Backup/access controls for the application database apply to Teams data as well. Configure an institutional retention policy before storing long-lived operational discussions.

## Deployment

Run `php spark migrate` to create `team_tasks`, `team_task_comments` and `team_task_activity` using `2026-10-18-000002_CreateTeamTasks`. The migration is additive and was applied to the local database during implementation. Back up deployment databases and run the normal migration workflow for other environments. Do not roll it back once Teams contains work unless the records have been preserved; rollback drops the three tables.

## Team calendar (Phase 2)

Open Team calendar from the board, or use `/clinic/teams/calendar`, `/guidance/teams/calendar`, or `/facilities/teams/calendar`.

- Month (Monday–Sunday), week, and 30-day agenda views share server-validated ranges. Previous/Today/Next and date navigation preserve view/scope/status filters.
- Create one-time timed events with title, description, location, and up to 100 active workstation participants. Use the existing custom combined date/time picker. All inputs and calendar dates are interpreted in Asia/Manila; timestamps are stored in UTC.
- End must be after start, with duration at most 31 days. Multi-day events appear on every overlapping day. An event ending at midnight is excluded from the following day.
- Existing task due dates are read directly, never copied into event rows. Completed tasks are marked Done. Editing a task deadline updates its calendar placement on the next load.
- My schedule shows events created by or including the signed-in user and task deadlines assigned to that user. It is a filter, not a privacy boundary; authorized workstation members can view the workstation calendar.
- Only the organizer or workstation manager/supervisor/superadmin may edit/cancel an event. Participants do not gain edit privileges merely by attending. Stale versions fail with HTTP 409. Cancellation is terminal, retained in history, and visible using the Cancelled/All events filter. It remains possible if a participant was deactivated or changed roles.
- Results are capped at 250 events and 250 tasks per range, with a visible truncation notice; use Week/My schedule to narrow crowded ranges.
- Event participants receive persistent in-app bell invitations and notices for material changes/cancellation as described below. Events do not reserve patient appointments, block provider availability, send email/SMS/device push, or schedule reminders. There is no conflict detection against clinical schedules. Avoid clinical/patient information in event text.

Run `php spark migrate` to add `team_events` and `team_event_participants` from `2026-10-19-000001_CreateTeamEvents`. This additive migration was applied locally. It does not change scheduling tables. Rolling it back deletes stored calendar events and participation records.

## Department chat (Phase 3)

Open Team chat from the board/calendar or visit `/clinic/teams/chat`, `/guidance/teams/chat`, or `/facilities/teams/chat`.

- One implicit channel per tenant/workstation, using active workstation membership. There are no developer-access exceptions, direct messages, attachments, or online-status indicators.
- Immutable text messages up to 2,000 Unicode characters. Existing messages cannot be edited/deleted in this release. Never enter patient details, diagnoses, confidential counseling notes, credentials or tokens. Use established urgent clinical communication channels instead.
- The composer uses a `+ | message | send icon` layout. The message field grows from one to four lines, then scrolls. Enter sends; Shift+Enter inserts a newline. IME composition does not submit prematurely.
- Type `@` to search current channel members. Choose with the mouse, or Up/Down then Enter/Tab; Escape dismisses the suggestions. Selected mentions are highlighted in sent messages. Editing through a mention removes its identity binding; edits before it shift its range. The server validates member identity and exact text using UTF-16 offsets, including emoji. Validated mentions generate a targeted in-app bell notification after the mention-notification migration is deployed. Self-mentions do not notify, and repeated mentions of the same person in one message create only one notice. There is no email, SMS, or device-push delivery.
- Choose Reply on a message to quote its sender and text in your next message; cancel the preview to send normally. Reply targets must belong to the same tenant/workstation. Replies remain chronological channel messages, not separate threads.
- The `+` opens a searchable task picker with multiple selection (up to 20 references). Remove a selected task using its chip. Search returns up to 50 active tasks; narrow the search for additional results. References must belong to active tasks in the same tenant/workstation when sent. They link to existing tasks; no task data is copied. Task title visibility follows workstation membership.
- The server stores UTC timestamps and the UI shows Asia/Manila day separators/times. Sender names resolve only through current eligible members; former senders are labeled Former member.
- Mention notifications open the exact authorized message using `?message=<id>#chat-message-<id>`, even if it is older than the latest page. The target is focused and highlighted; browsing this historical page does not advance the chat read cursor. Show latest resumes live chat. Invalid, foreign-tenant, or other-department targets are rejected.
- History is cursor-paginated at 40 messages per response. The browser keeps at most 400 messages in its current window. Loading older history pauses updates; Show latest resumes them. It does not load the whole channel into memory.
- Read cursors are per-user and monotonic. Unread counts exclude your own messages. Opening chat starts near the unread position when necessary; messages below the browser viewport are not acknowledged. A tall message becomes read once its end is visible. Show latest intentionally jumps past earlier history; there are no public per-message read receipts.
- Updates reconcile every five seconds while visible without ready realtime, or every 60 seconds when the optional realtime transport is ready. Private `team.chat.changed` hints prompt an authorized refetch; message content is never sent in the hint. Hidden-tab polling stops and progressive navigation tears down requests/listeners/timers. Failed updates retry with a delay. The channel unread badge is refreshed within chat, not globally on the sidebar/header.
- A client UUID send token is unique per sender/tenant/workstation. Identical retries return the original message, including when the original response was lost; altered contents using that token fail with HTTP 409. An unconfirmed send locks its text/task fields until Retry send resolves. Validation errors retain the draft and unlock corrections with a fresh token.
- Drafts, messages and send tokens are not persisted in browser storage. Leaving/reloading loses an unsent draft; navigation warns about unsaved input. A full reload after an ambiguous response cannot recover that in-memory token, so check channel history before retyping. Server-delivered history remains in the database.
- No chat text is added to notification previews, bell notifications, application logs or activity records by the chat module. Deployment-level request/SQL/body logging must also be configured not to capture these endpoints. Database backup/access/retention policy applies to all messages; agree on an institutional retention period before using chat for long-lived discussions.

Run `php spark migrate` to add `team_chat_messages` and `team_chat_reads` from `2026-10-19-000002_CreateTeamChat`. The additive migration was applied locally. It does not alter existing care, task or calendar tables. Rolling it back deletes messages and read positions.

### Chat composer deployment

Back up the database and apply `2026-10-25-000001_AddTeamChatReferences` through the normal reviewed migration workflow (`php spark migrate`) before enabling this composer. Review other pending migrations before running that command. The additive migration adds nullable task-reference, mention, and reply metadata to `team_chat_messages`, preserving existing messages and legacy single-task references. It was tested on disposable fixtures and then applied to local development `humus_db` with operator approval on 2026-10-25 (batch 31). Chat fails closed if the new columns are missing. Rolling this migration back removes the new metadata, so preserve it before any downgrade.

Local deployment used the CLI-only `writable/chat-development-migrate.php --apply`, which restricts execution to this local development database and this exact migration. It established maintenance, excluded the scheduled notification worker, created a native backup, verified its size/completion marker/SHA-256, and verified preservation of all 6,070 pre-existing non-history rows across 58 tables plus existing migration history. No unrelated pending migration was applied. Maintenance was cleared after verification. Backup: `writable/backups/humus_db_before_chat_references_20261025_125757_5d6c12.sql` (852,173 bytes; SHA-256 `4ee3a7d55aa9f528ffc76432b37b6e9147332ecc89e38f8165ad12321dcc3e8a`). Evidence: `writable/backups/chat_deployment_20261025_125756_a5ad61a8.json`. This particular backup has not been restore-tested. Read-only smoke checks (`php writable/chat-development-smoke.php`) passed history reads, task-picker reads, and composer rendering for all three workstations without sending test messages or changing database records. No active tasks were available in the checked channels at deployment time.

Validation: `php vendor/bin/phpunit -c phpunit.ci.xml tests/unit/TeamChatServiceTest.php`, `php vendor/bin/phpunit -c phpunit.ci.xml tests/database/TeamChatServiceIntegrationTest.php`, `php vendor/bin/phpunit -c phpunit.ci.xml tests/feature/TeamChatHttpTest.php`, and `node --test tests/browser/team-chat.test.cjs`.

### Mention notification deployment

Back up the database and apply `2026-10-26-000002_AddTeamChatMentionNotifications` before enabling other-user mention sends. Review pending migrations; do not accidentally apply unrelated migrations. The new migration adds nullable `chat_unit`/`chat_message_id` references, appends `team_chat_mentioned` to the existing notification types, and adds tenant/unit-safe source validation plus a unique message/recipient delivery key. It preserves existing notification and read history, does not backfill old messages, and refuses destructive rollback. It has been tested on isolated SQLite fixtures and a disposable MySQL/MariaDB schema; it has **not** been applied to the operational database.

Mention notification inserts share the chat send transaction. Missing/incompatible notification schema or insertion failure rolls back the new message instead of silently dropping the alert. Plain messages and self-only mentions do not acquire this schema dependency. Exact nonce retries return the original message without creating new notices or backfilling old ones. Realtime bell invalidation occurs only after commit; normal bell polling remains the fallback. Inbox, unread counts, mark-read, read-all, and realtime authorization all require the recipient's current channel membership. Previews contain generic text only—not the sender name, message body, task titles, or quoted content. Bell links are limited to the server-configured department chat URLs and an exact positive message target, not an arbitrary redirect.

## Teams bell notifications

Clinic, Guidance and Facilities share account-addressed, tenant/workstation-scoped in-app notifications. The sender is excluded; recipients must still be active eligible workstation members. A person-less superadmin can receive an explicitly addressed Teams notice, but gains no access to other users' notices or null-recipient personal notifications.

| Change | Recipients |
| --- | --- |
| Event created / participant added | Newly included participants |
| Participant removed | Removed participant, if still eligible in the workstation |
| Event title, start/end time or location changed | Retained participants; organizer when another editor makes the change |
| Event participant list changed | Organizer when another editor makes the change |
| Event cancelled | Participants and organizer |
| Task assigned / reassigned / unassigned | New assignee and former assignee, as applicable |
| Task status or other material fields changed | Creator and current assignee |
| Task comment | Creator and current assignee; no comment preview |
| Unchanged save / event description-only edit | No bell notice |
| Chat @mention | Each unique current same-department mentioned account, excluding the sender |
| Chat message without mentions / read cursor | Existing chat unread behavior; no department-wide bell broadcast |

At most one notice is delivered to each recipient for an event/task save. Comments have separate delivery identities. Notification rows and the owning business change commit together; storage failure rolls back the change rather than silently dropping its alert. Optional realtime invalidation is published only after commit; the normal bell reconciliation remains the fallback. Notices contain generic text and identifiers, not event/task titles, descriptions, comments, participant names or clinical content. Clicking opens the exact configured workstation calendar, board, or mentioned chat message. Removed participants/former assignees can still read their removal notice while they retain workstation access; role revocation or account/tenant deactivation removes access.

**Deployment required:** back up the database and apply `2026-10-23-000002_AddTeamsNotifications` through the normal migration workflow (`php spark migrate`) before enabling these producers. It adds nullable Teams references, tenant/unit-safe foreign keys, shape validation and per-recipient delivery uniqueness while preserving existing notices/read state. It is forward-only and refuses destructive rollback. It was validated on disposable schemas, then applied with explicit operator approval to local `humus_db` on 2026-10-23 (batch 29). Only this migration was applied; older pending migrations were left untouched. A fresh native SQL backup was retained under `writable/backups/humus_db_before_teams_notifications_20261023_070734_516ede.sql`. Verification preserved all 5,953 original non-history rows across 56 tables, including notification read state, and confirmed the new columns, constraints and migration record. Evidence: `writable/backups/teams_deployment_20261023_070734_243e301d.json`. Maintenance was cleared after verification. Deploy application and schema together: a notification-bearing mutation without the required schema fails/rolls back. Events/tasks created before deployment are not automatically backfilled; an unchanged save does not invent a new invitation.

Notifications are in-app, not email/SMS/OS push. Opening the bell refreshes immediately; visible-page fallback polling normally reconciles within 30 seconds, or within 60 seconds when realtime is ready. Do not use Teams as an emergency clinical communication channel.

## Deferred features

Recurring events/tasks, attachments, direct messages, custom boards, a support center, a global chat unread badge, email/SMS/device-push delivery and scheduled reminders remain deferred. My tasks and My schedule remain available alongside event/task bell notices. Developer support remains separate from workstation chat.

## Focused validation

```sh
php vendor/bin/phpunit --no-coverage --do-not-cache-result --no-logging tests/unit/TeamTaskServiceTest.php tests/database/TeamTaskServiceIntegrationTest.php tests/feature/TeamsHttpTest.php tests/unit/AuthSessionSecurityTest.php
node --test tests/browser/teams.test.cjs
php vendor/bin/phpunit --no-coverage --do-not-cache-result --no-logging tests/unit/TeamCalendarServiceTest.php tests/database/TeamCalendarServiceIntegrationTest.php tests/feature/TeamCalendarHttpTest.php
node --test tests/browser/team-calendar.test.cjs
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/TeamChatServiceTest.php tests/database/TeamChatServiceIntegrationTest.php tests/feature/TeamChatHttpTest.php
node --test tests/browser/team-chat.test.cjs
```

The existing Teams database/HTTP tests use isolated in-memory SQLite rather than changing real users or operational records. The reduced SQLite notification fixture covers producer behavior; the guarded MySQL/MariaDB suite below covers the actual migration constraints and shared inbox. Browser tests render production views and scripts with fixture API responses.

```sh
python tests/_support/run-fresh-database-tests.py tests/database/TeamsNotificationMigrationTest.php tests/database/TeamsNotificationInboxTest.php
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/database/TeamsNotificationInboxIsolationTest.php tests/unit/NotificationInvalidationTest.php tests/unit/NotificationParentTransactionTest.php
node --test tests/browser/notifications.test.cjs
```

The guarded runner creates and destroys its own disposable schemas. Never run migration/service integration tests against an operational/shared schema. Passing these fixtures does not assert live notification delivery or real patient workflows.
