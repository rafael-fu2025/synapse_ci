# 17 — Mobile Team Workspace

## TW-MOB-001 — `/me` derives workspace units and features from fresh authority
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`, `884b9f0`
- Requirement: Workspace entitlement must be server-derived from current bearer identity, feature rollout, live department membership, and available Team schema; only entitled units/features appear. Final rollout policy remains unresolved.
- Surface: Mobile context switcher and workspace tabs.
- Route/API: `GET /api/v1/me`.
- Actor/grants: Student, single-unit staff, multi-unit/superadmin, revoked-role variants.
- Preconditions: Team Workspace rollout explicitly set for disposable test; deterministic roles/schema.

**Steps**
1. Sign in as each role variant and compare `/me.entitlements.work.units/features/can_manage` with visible context/unit/tab choices.
2. Change role or disable rollout/schema support and refresh `/me` without restarting.
3. Directly request a previously visible unit API after revocation.

- Expected: UI mirrors current authorized features; revocation removes unit and server returns denial/not-found as appropriate; no stale client flag grants access.
- Negative/non-disclosure: Unauthorized unit/member/task/event/chat data and rollout internals are absent.
- Cleanup: Restore rollout/roles and retire DB.
- Source: `app/Controllers/Api/V1/MeController.php:24-47`; `app/Services/TeamEntitlements.php:20-70`
- Evidence: Role/entitlement/navigation/API matrix.
- Verdict: `NOT_RUN`

## TW-MOB-002 — Unit path is the immutable Team scope
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`
- Requirement: Team unit is one of exact server route segments `clinic|guidance|facilities`; tenant, actor, role, and unit selectors in body/query cannot broaden scope, and every service reloads current bearer identity/membership.
- Surface: All Team Workspace APIs.
- Route/API: `/api/v1/team/{unit}/*`.
- Actor/grants: Member of exactly one unit; second-tenant fixture.
- Preconditions: Distinct records with overlapping numeric IDs in each unit/tenant.

**Steps**
1. Read/create in the authorized unit while adding forged tenant/actor/role/unit parameters.
2. Substitute another unit route and use IDs copied from the authorized unit.
3. Change the actor role after token issuance and repeat.

- Expected: Forged selectors are rejected/ignored; route scope and fresh membership decide; foreign unit/tenant IDs return denial/not-found without data.
- Negative/non-disclosure: Errors do not reveal whether a foreign ID exists or disclose members/content.
- Cleanup: Remove synthetic authorized-unit rows and restore role.
- Source: `app/Config/Routes.php:46-66`; `app/Services/TeamAccess.php:14-66`; `app/Services/TeamAccess.php:119-144`
- Evidence: Scope-tampering response matrix and row comparison.
- Verdict: `NOT_RUN`

## TW-MOB-003 — Context switching protects dirty drafts and pending submissions
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Switching personal/work/unit contexts must prompt before discarding any retained draft, block while a submission is pending, run discard callbacks once after dialog dismissal, and never navigate beneath a native modal.
- Surface: Mobile context tab bar, unit picker, account sheet.
- Route/API: Client navigation only until a form submits.
- Actor/grants: User with personal plus at least two workspace units.
- Preconditions: Dirty forms in personal and workspace contexts; controllable slow submission.

**Steps**
1. Enter a draft, request context/unit switch, cancel the warning, and verify draft/navigation remain.
2. Request again, confirm, wait for dialog exit, and verify one discard then destination navigation.
3. Start a submission and attempt context/account/unit switches repeatedly.

- Expected: Cancel preserves draft; confirm discards selected draft exactly once after modal closes; pending work blocks switching with notice and no duplicate submit.
- Negative/non-disclosure: Draft text is not persisted, copied between units, logged, or visible behind the dialog.
- Cleanup: Discard all synthetic drafts and finish/cancel controlled requests.
- Source: `mobile/src/features/workspace/WorkspaceContext.tsx:158-235`; `mobile/src/features/workspace/WorkspaceContext.tsx:248-300`
- Evidence: Screen recording and request/discard callback count.
- Verdict: `NOT_RUN`

## TW-MOB-004 — Revoked context disposes only its own drafts and locks
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Entitlement refresh must dispose drafts/submission registrations scoped to a revoked personal/unit context, retain still-authorized context work, close pending discard/destination state, and move to a valid context.
- Surface: Mobile WorkspaceProvider.
- Route/API: `GET /api/v1/me` refresh.
- Actor/grants: Multi-context user whose one unit or personal entitlement is revoked.
- Preconditions: Separate dirty drafts/slow operations in two authorized contexts.

**Steps**
1. Register distinguishable drafts in two units (and personal if available), then revoke only one unit and refresh profile.
2. Inspect active context, picker, drafts, notices, pending destination, and submissions.
3. Attempt delayed callback/navigation from revoked scope and continue editing retained scope.

- Expected: Only revoked-scope work is disposed/aborted; retained scope remains; app selects a remaining valid unit/personal context and rejects stale callback.
- Negative/non-disclosure: Revoked draft/data never appears in retained unit or after regrant; one context’s revocation does not erase unrelated authorized work.
- Cleanup: Restore role and discard retained synthetic drafts.
- Source: `mobile/src/features/workspace/WorkspaceContext.tsx:89-156`
- Evidence: Before/after scoped draft inventory and screen recording.
- Verdict: `NOT_RUN`

## TW-MOB-005 — Task list is bounded, filtered, and unit-scoped
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Task list must support team/mine, text, status, priority, and page filters, return bounded counts/pagination, and compute editability from current actor/unit policy.
- Surface: Mobile Team Board/My Tasks.
- Route/API: `GET /api/v1/team/{unit}/tasks`.
- Actor/grants: Unit member and manager variants.
- Preconditions: Tasks across statuses/priorities/assignees/units/tenants and multiple pages.

**Steps**
1. Exercise team/mine, each status/priority, search, and paging; reconcile IDs/counts/meta.
2. Open as manager, creator, assignee, and ordinary member; compare `can_edit`/controls.
3. Tamper filters/page and unit/tenant IDs.

- Expected: Deterministic authorized subset/counts and edit controls; invalid inputs return bounded errors; foreign records never appear.
- Negative/non-disclosure: Descriptions/comments/activity from list projection and unauthorized member/task data are withheld.
- Cleanup: Retire task fixture DB.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:14-32`; `mobile/src/api/endpoints/teamTasks.ts:119-150`
- Evidence: Filter/result matrix and editability screenshots.
- Verdict: `NOT_RUN`

## TW-MOB-006 — Task creation is idempotent per actor/unit/identical request
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Create requires valid task fields and UUIDv4 `client_nonce`; identical retry must return the same task, while nonce reuse with different content or scope conflicts and concurrent retries create one row/notification set.
- Surface: Mobile task form.
- Route/API: `POST /api/v1/team/{unit}/tasks`.
- Actor/grants: Current unit member.
- Preconditions: Valid assignee/member and deterministic nonce held only in memory for retry.

**Steps**
1. Submit a valid task, simulate an ambiguous response, and retry identical payload/nonce sequentially and concurrently.
2. Reuse nonce with changed title/checklist, another unit, and another actor.
3. Inspect task, activity, notification, and realtime invalidation counts.

- Expected: Identical same-scope retries resolve to one task; conflicting reuse is `409`; scope isolation applies; one create side-effect set exists.
- Negative/non-disclosure: Nonce is not a global resource key and does not expose another actor/unit’s result; invalid assignee/fields create nothing.
- Cleanup: Remove/retire synthetic task rows.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:45-58`; `app/Controllers/Api/V1/Team/TeamApiController.php:97-104`; `mobile/src/api/endpoints/teamTasks.ts:87-109`
- Evidence: Retry/concurrency HTTP trace and row/side-effect counts.
- Verdict: `NOT_RUN`

## TW-MOB-007 — Task updates and status moves use optimistic version
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Full task update and status-only move must require current positive version, current edit authority, valid transition/input, and atomically increment version/activity/notifications; stale writers must refresh rather than overwrite.
- Surface: Mobile task detail/edit/board move.
- Route/API: `POST /api/v1/team/{unit}/tasks/{id}/update`; `/status`.
- Actor/grants: Authorized editor plus unauthorized member.
- Preconditions: One versioned task opened on two clients.

**Steps**
1. Update fields from client A and verify version increment/activity.
2. Submit client B’s stale full update and stale status move, then refresh and apply a valid move.
3. Attempt unauthorized edit, invalid checklist/status/assignee, terminal/no-op variants as supported.

- Expected: Current authorized mutation wins and increments once; stale/unauthorized/invalid variants do not overwrite or create side effects; refresh exposes winner.
- Negative/non-disclosure: Conflict does not return foreign hidden content, and mobile does not silently merge drafts.
- Cleanup: Retire task fixture DB.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:61-83`; `app/Controllers/Api/V1/Team/TasksController.php:100-121`
- Evidence: Version/activity timeline, conflict responses, and final task state.
- Verdict: `NOT_RUN`

## TW-MOB-008 — Task comments are idempotent and bounded
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`
- Requirement: Comment creation must validate plain bounded text and UUIDv4 nonce, use current unit membership, create one comment/activity/notification set for identical retries, and return a bounded detail projection.
- Surface: Mobile task comments.
- Route/API: `POST /api/v1/team/{unit}/tasks/{id}/comments`; task detail GET.
- Actor/grants: Current unit member.
- Preconditions: Existing task, ambiguous-response harness, recipients.

**Steps**
1. Submit a valid comment and retry identical nonce/body after simulated response loss.
2. Reuse nonce with changed body and attempt blank/oversized/rich-script content plus foreign unit/task.
3. Reopen detail and reconcile comments/activity totals and notifications.

- Expected: One escaped plain-text comment and side-effect set; conflict/validation/foreign attempts create nothing; detail bounds are truthful.
- Negative/non-disclosure: HTML/script never executes; nonce does not retrieve other scope; notification omits sensitive comment text.
- Cleanup: Retire fixture DB.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:86-97`; `mobile/src/api/endpoints/teamTasks.ts:174-175`
- Evidence: HTTP/row/notification counts and rendered escaping capture.
- Verdict: `NOT_RUN`

## TW-MOB-009 — Calendar combines events and live task deadlines in Manila views
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`, `884b9f0`
- Requirement: Agenda/week/month listing must use Manila date boundaries, independently bound events/task deadlines to 250 each, sort combined items, support team/mine/status, and identify truncation without treating tasks as appointments.
- Surface: Mobile Team Calendar.
- Route/API: `GET /api/v1/team/{unit}/calendar`.
- Actor/grants: Unit member.
- Preconditions: Events spanning midnight/month, task due dates, mine/team/cancelled variants, boundary fixture.

**Steps**
1. Exercise each view/date/scope/status and reconcile start/end-exclusive Manila ranges, ordering, totals, and item types.
2. Test event overlapping range and task date at boundaries; force truncation fixture.
3. Compare calendar items with appointments/availability surfaces and foreign unit data.

- Expected: Correct Manila window and combined typed list; live task versions/statuses reflected; truncation flag truthful; no appointment semantics.
- Negative/non-disclosure: Other units/tenants and appointment/patient data are absent; timestamps are not misread as UTC wall time.
- Cleanup: Retire fixture DB.
- Source: `app/Services/TeamCalendarService.php:15-41`; `app/Services/TeamCalendarService.php:67-103`; `mobile/src/api/endpoints/teamCalendar.ts:5-82`
- Evidence: Boundary reconciliation worksheet and view screenshots.
- Verdict: `NOT_RUN`

## TW-MOB-010 — Event create uses Manila wall time, eligible participants, and nonce
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Event create requires valid title and real Manila `YYYY-MM-DDTHH:mm` start/end with duration `(0,31d]`, up to 100 unique active same-unit participants, and UUIDv4 nonce idempotency.
- Surface: Mobile calendar event form.
- Route/API: `POST /api/v1/team/{unit}/calendar/events`.
- Actor/grants: Unit member.
- Preconditions: Eligible/ineligible members and ambiguous-response harness.

**Steps**
1. Create a valid event including participants and inspect returned explicit-offset instants/detail.
2. Retry identical nonce/payload sequentially/concurrently; reuse nonce with changed time/participants.
3. Test nonexistent/local-gap times if applicable, end-before/equal, >31 days, duplicates, >100, inactive/cross-unit participant.

- Expected: One event/participant/notification set for identical retries; valid Manila conversion is stable; conflicting/invalid variants reject atomically.
- Negative/non-disclosure: Event is not an appointment/availability block, and participant picker exposes only current eligible unit members.
- Cleanup: Cancel/retire synthetic event and DB.
- Source: `app/Controllers/Api/V1/Team/CalendarController.php:53-63`; `app/Services/TeamCalendarService.php:115-149`; `mobile/src/api/endpoints/teamCalendar.ts:64-82`
- Evidence: Time conversion, retry counts, participant/notification matrix.
- Verdict: `NOT_RUN`

## TW-MOB-011 — Event update/cancel enforce edit authority and version
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Only manager or creator may update/cancel; update replaces all editable fields with current version, cancel is terminal and current-version repeat is no-op, while stale versions always conflict.
- Surface: Mobile event detail/edit/cancel.
- Route/API: `POST /api/v1/team/{unit}/calendar/events/{id}/update`; `/cancel`.
- Actor/grants: Creator, unit manager, participant/noneditor.
- Preconditions: Scheduled versioned event on two clients.

**Steps**
1. Update as creator/manager and verify full replacement, version, participants, notifications.
2. Submit stale update/cancel from second client; then cancel with fresh version and repeat current-version cancel.
3. Attempt update after cancellation and mutation as participant/noneditor/foreign unit.

- Expected: Authorized current writes succeed; stale/unauthorized/terminal update fail; cancel is terminal and documented repeat produces no duplicate side effects.
- Negative/non-disclosure: Removed participant notice remains readable only within current unit authority; event text is not copied into notification/audit logs.
- Cleanup: Retire event fixture DB.
- Source: `app/Controllers/Api/V1/Team/CalendarController.php:66-85`; `app/Services/TeamCalendarService.php:29-41`
- Evidence: Version/status timeline, side-effect counts, and actor matrix.
- Verdict: `NOT_RUN`

## TW-MOB-012 — Chat history cursors are bounded and mutually exclusive
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `a06b70b`
- Requirement: Chat history must return fixed 40-message latest/before/after/around windows with deterministic cursors/read summary; cursor modes are mutually exclusive and current unit membership is rechecked.
- Surface: Mobile Team Chat history/deep link.
- Route/API: `GET /api/v1/team/{unit}/chat/messages`.
- Actor/grants: Current unit member.
- Preconditions: More than 80 messages, known read marker, target message, foreign-unit canaries.

**Steps**
1. Load latest then older/newer windows; verify order, `has_more`, `next_before`, latest/unread/read IDs and no duplicates/gaps.
2. Open around-target deep link and verify target is included/scrolled without changing unit.
3. Submit multiple/invalid cursors and revoke membership mid-pagination.

- Expected: Bounded deterministic windows and summaries; invalid modes reject; revocation stops/clears history; target opens only in authorized unit.
- Negative/non-disclosure: Other unit/tenant messages, other senders’ nonces, and unbounded history never appear.
- Cleanup: Retire chat fixture DB.
- Source: `app/Controllers/Api/V1/Team/ChatController.php:14-26`; `mobile/src/api/endpoints/teamChat.ts:45-94`
- Evidence: Cursor ID sequences, target recording, and revocation response.
- Verdict: `NOT_RUN`

## TW-MOB-013 — Chat send is nonce-idempotent with validated references
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `a06b70b`
- Requirement: Sending requires bounded plain body, UUIDv4 nonce, active same-unit task/reply references, and structurally valid member mentions using UTF-16 offsets; identical ambiguous retry creates one message/mention/notification set.
- Surface: Mobile Team Chat composer.
- Route/API: `POST /api/v1/team/{unit}/chat/messages`.
- Actor/grants: Current unit member.
- Preconditions: Active task, reply message, eligible mention target, ambiguous-response harness.

**Steps**
1. Send a message with task/reply/mention and retry identical nonce sequentially/concurrently.
2. Reuse nonce with changed content; test foreign/inactive task, reply, member, overlapping/wrong mention offsets, and oversized/blank body.
3. Inspect message projection for nonce visibility by sender versus another member and count notifications.

- Expected: One valid message/reference/mention/notification set; conflicting/invalid requests reject; nonce returns only on current actor’s own message.
- Negative/non-disclosure: HTML/script does not execute; foreign references/members/nonces and notification body text are withheld.
- Cleanup: Retire chat fixture DB.
- Source: `app/Controllers/Api/V1/Team/ChatController.php:39-62`; `mobile/src/api/endpoints/teamChat.ts:15-67`; `mobile/src/api/endpoints/teamChat.ts:131-147`
- Evidence: Retry/concurrency row counts, validation matrix, and two-user projections.
- Verdict: `NOT_RUN`

## TW-MOB-014 — Chat read marker is monotonic and account-scoped
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`
- Requirement: Mark-read must accept a valid last message in the current unit, never move the marker backward, return authoritative last/unread/latest summary, and not alter another user/unit/account.
- Surface: Mobile Team Chat read behavior.
- Route/API: `POST /api/v1/team/{unit}/chat/read`.
- Actor/grants: Two current unit members.
- Preconditions: Messages before/after existing marker and foreign-unit ID.

**Steps**
1. Open chat/mark latest visible read and reconcile summary with history.
2. Submit an older ID, repeated ID, future/nonexistent ID, and foreign-unit message ID.
3. Compare member B’s marker and switch account during delayed mark request.

- Expected: Marker advances monotonically/idempotently only for current actor/unit; invalid/foreign requests reject; retired request has no new-account effect.
- Negative/non-disclosure: No other member’s marker/unread details or message existence leaks.
- Cleanup: Retire fixture DB and clear app state.
- Source: `app/Controllers/Api/V1/Team/ChatController.php:65-76`; `mobile/src/api/endpoints/teamChat.ts:139-147`
- Evidence: Marker/summary timeline and cross-user/unit/account matrix.
- Verdict: `NOT_RUN`

## TW-MOB-015 — Realtime invalidation refetches the exact Team unit resources
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `c91477a`, `73b8a11`
- Requirement: Team task/calendar/chat hints must invalidate mapped query keys for the current entitled unit and active account, with authoritative refetch, bounded polling fallback, and no cross-unit cache overwrite.
- Surface: Mobile Team Board/Calendar/Chat.
- Route/API: User realtime grant plus Team GET APIs.
- Actor/grants: Unit member; second member mutates resources.
- Preconditions: Workerman on/off variants, two units with same IDs, account switch fixture.

**Steps**
1. Keep each Team screen open and mutate its resource from second member; verify hint-driven exact-unit refetch and final state.
2. Send mismatched unit/type/ID hints and compare query counters/cache.
3. Stop realtime for fallback, then switch account/unit while refetch is in flight.

- Expected: Current exact-unit resources converge; fallback repairs; mismatched/retired work cannot update cache or navigate.
- Negative/non-disclosure: Hint is not record truth and cannot expose/copy foreign unit/account data.
- Cleanup: Restore Workerman, sign out, retire DB.
- Source: `mobile/src/features/workspace/invalidateTeamResources.ts:1-20`; `mobile/src/features/realtime/useUserRealtime.ts:1-32`
- Evidence: Hint/query timeline and unit/account cache canary comparison.
- Verdict: `NOT_RUN`

## TW-MOB-016 — Notification intent rechecks feature and identity after navigation commits
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `c179273`, `a06b70b`
- Requirement: Team push/inbox targets must parse only supported kind/unit/positive ID, bind to active connection owner/account, recheck `/me` feature entitlement before and after unit tree commit, respect draft/submission guards, and consume rejected targets without replay loops.
- Surface: Mobile notification → Team navigation.
- Route/API: Notification target projection, `/me`, target Team detail/history.
- Actor/grants: Entitled and later-revoked Team user.
- Preconditions: Valid/invalid task/event/chat targets; dirty draft and pending submission variants; two server-profile owners.

**Steps**
1. Open valid target from foreground/background notification while in another context; verify guarded unit switch then exact record.
2. Revoke feature/unit during transition and attempt malformed/unsupported/foreign targets.
3. Hold dirty draft or pending submission, then switch connection owner/account with a queued old intent.

- Expected: Valid current target opens after authorized unit commit; draft prompt/submission block applies; revoked/malformed/old-owner target shows one unavailable notice and is consumed.
- Negative/non-disclosure: No target crosses unit/account/server or bypasses feature gates; no repeated replay and no draft loss without confirmation.
- Cleanup: Clear intents/push, restore role, sign out, retire DB.
- Source: `mobile/src/features/workspace/navigationIntent.ts:7-75`; `mobile/src/features/workspace/NavigationIntentGate.tsx:16-95`
- Evidence: Navigation recordings and target/entitlement/owner outcome matrix.
- Verdict: `NOT_RUN`
