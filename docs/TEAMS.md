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
- Change status through an accessible form, not drag-and-drop.
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
- Events do not reserve patient appointments, block provider availability, send invitations, or trigger notifications/reminders. There is no conflict detection against clinical schedules. Avoid clinical/patient information in event text.

Run `php spark migrate` to add `team_events` and `team_event_participants` from `2026-10-19-000001_CreateTeamEvents`. This additive migration was applied locally. It does not change scheduling tables. Rolling it back deletes stored calendar events and participation records.

## Department chat (Phase 3)

Open Team chat from the board/calendar or visit `/clinic/teams/chat`, `/guidance/teams/chat`, or `/facilities/teams/chat`.

- One implicit channel per tenant/workstation, using active workstation membership. There are no developer-access exceptions, direct messages, attachments, or online-status indicators.
- Immutable text messages up to 2,000 Unicode characters. Existing messages cannot be edited/deleted in this release. Never enter patient details, diagnoses, confidential counseling notes, credentials or tokens. Use established urgent clinical communication channels instead.
- Optional task IDs must reference an active task in the same tenant/workstation. References link to the existing task; no task data is copied. Task title visibility follows workstation membership.
- The server stores UTC timestamps and the UI shows Asia/Manila day separators/times. Sender names resolve only through current eligible members; former senders are labeled Former member.
- History is cursor-paginated at 40 messages per response. The browser keeps at most 400 messages in its current window. Loading older history pauses updates; Show latest resumes them. It does not load the whole channel into memory.
- Read cursors are per-user and monotonic. Unread counts exclude your own messages. Opening chat starts near the unread position when necessary; messages below the browser viewport are not acknowledged. A tall message becomes read once its end is visible. Show latest intentionally jumps past earlier history; there are no public per-message read receipts.
- Updates poll every five seconds while the tab is visible. Hidden-tab polling stops and progressive navigation tears down requests/listeners/timers. This is polling, not WebSocket delivery; failed updates retry with a delay. The channel unread badge is refreshed within chat, not globally on the sidebar/header.
- A client UUID send token is unique per sender/tenant/workstation. Identical retries return the original message, including when the original response was lost; altered contents using that token fail with HTTP 409. An unconfirmed send locks its text/task fields until Retry send resolves. Validation errors retain the draft and unlock corrections with a fresh token.
- Drafts, messages and send tokens are not persisted in browser storage. Leaving/reloading loses an unsent draft; navigation warns about unsaved input. A full reload after an ambiguous response cannot recover that in-memory token, so check channel history before retyping. Server-delivered history remains in the database.
- No chat text is added to notification previews, bell notifications, application logs or activity records by the chat module. Deployment-level request/SQL/body logging must also be configured not to capture these endpoints. Database backup/access/retention policy applies to all messages; agree on an institutional retention period before using chat for long-lived discussions.

Run `php spark migrate` to add `team_chat_messages` and `team_chat_reads` from `2026-10-19-000002_CreateTeamChat`. The additive migration was applied locally. It does not alter existing care, task or calendar tables. Rolling it back deletes messages and read positions.

## Deferred features

WebSocket delivery, recurring events/tasks, attachments, direct messages, custom boards, a support center, global unread badges, bell notifications and scheduled reminders are deferred. Department members discover assignments in My tasks and participation through My schedule. Developer support remains separate from workstation chat.

## Focused validation

```sh
php vendor/bin/phpunit --no-coverage --do-not-cache-result --no-logging tests/unit/TeamTaskServiceTest.php tests/database/TeamTaskServiceIntegrationTest.php tests/feature/TeamsHttpTest.php tests/unit/AuthSessionSecurityTest.php
node --test tests/browser/teams.test.cjs
php vendor/bin/phpunit --no-coverage --do-not-cache-result --no-logging tests/unit/TeamCalendarServiceTest.php tests/database/TeamCalendarServiceIntegrationTest.php tests/feature/TeamCalendarHttpTest.php
node --test tests/browser/team-calendar.test.cjs
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/TeamChatServiceTest.php tests/database/TeamChatServiceIntegrationTest.php tests/feature/TeamChatHttpTest.php
node --test tests/browser/team-chat.test.cjs
```

Database/HTTP tests run against isolated in-memory SQLite rather than changing real users or operational records. Browser tests render production views and scripts with fixture API responses. These checks do not simulate simultaneous multi-connection MySQL races or assert live patient workflows.
