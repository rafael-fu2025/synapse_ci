# Team Workspace on the web

## TW-WEB-001 — Workstation membership isolates Team Workspace by tenant and unit
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e08f383`, `884b9f0`, `62c790b`
- **Requirement:** `TEAM.WEB.MEMBERSHIP`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Web Team Workspace
- **Route/API:** GET /{clinic|guidance|facilities}/teams
- **Actor/grants:** Active workstation member (including `clinic_intern`, `guidance_intern`, `bmg_intern`); nonmember/other-unit controls
- **Preconditions:** Canonical 16-role catalog reconciled; synthetic members across Clinic, Guidance, and BMG units/tenants; same IDs/content sentinels across units.
**Steps**
1. Open each authorized Team board with department staff, interns, and multi-unit `superadmin` actors and inspect member/task scope.
2. As student/employee nonmember or different-unit/foreign-tenant actor, request the direct Team URLs.
- **Expected:** Membership comes from current active workstation roles (including active supervised interns) and every board is tenant/unit isolated; nonmembers and cross-unit actors receive 403.
- **Negative/non-disclosure:** Team membership grants no clinical-record access and no cross-unit task/member sentinel appears.
- **Cleanup:** Delete/reset Team fixtures and sign out all actors.
- **Source:** app/Services/TeamAccess.php:14-116; app/Services/PortalContext.php:144-186
- **Evidence:** Role/unit access matrix, member lists, direct-URL denials, and negative sentinel search.
- **Verdict:** `NOT_RUN`

## TW-WEB-002 — Member creates an operational task with bounded checklist
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.TASK_CREATE`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Team board/list
- **Route/API:** GET/POST /{unit}/teams/create|store
- **Actor/grants:** Active workstation member
- **Preconditions:** Synthetic operational title/description with no patient data; optional eligible assignee/due date; 1–20 checklist items.
**Steps**
1. Create the task with assignee, priority, due date, and checklist; inspect board/list/detail.
2. Try 21 items, ineligible assignee, invalid date, and a patient-data sentinel only in a disposable rejected-input check.
- **Expected:** Valid task appears once with version 1 and decoded checklist; invalid inputs create no task.
- **Negative/non-disclosure:** No patient linkage/file attachment exists, input is escaped, and task assignment confers no clinical access.
- **Cleanup:** Remove/reset task/comments/activity in disposable database (no production delete UI exists).
- **Source:** docs/TEAMS.md:11-40; app/Services/TeamTaskService.php:126-169,298-370
- **Evidence:** Create form/result, board/list/detail, checklist/version, and invalid-input row-count proof.
- **Verdict:** `NOT_RUN`

## TW-WEB-003 — Task editing and moves enforce editor policy and optimistic version
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.TASK_VERSION`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Team board/task detail
- **Route/API:** POST /{unit}/teams/{id}/update|move
- **Actor/grants:** Creator, assignee, manager, ordinary member
- **Preconditions:** Synthetic task version 1; two browser sessions; each actor role present.
**Steps**
1. Edit/move as creator or assignee using version 1 and observe version 2/activity.
2. From stale session submit version 1, then attempt edit as an ordinary non-editor member.
- **Expected:** Authorized current-version change succeeds transactionally; stale change returns 409; non-editor receives 403 without state change.
- **Negative/non-disclosure:** No silent last-write-wins, custom within-column order claim, or client override of tenant/author/version occurs.
- **Cleanup:** Reset synthetic task/activity.
- **Source:** docs/TEAMS.md:17-34; app/Services/TeamTaskService.php:170-268
- **Evidence:** Two-session version/conflict trace, actor access matrix, final task/activity state.
- **Verdict:** `NOT_RUN`

## TW-WEB-004 — Task comments persist with bounded privacy-safe activity
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.TASK_COMMENT`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Task detail
- **Route/API:** POST /{unit}/teams/{id}/comments
- **Actor/grants:** Any active workstation member
- **Preconditions:** Synthetic task; 51 synthetic comments/activity events; creator/assignee recipients; no sensitive text.
**Steps**
1. Post a comment and reload detail/activity as another unit member.
2. Inspect limits/totals and notification preview; submit empty/oversized text.
- **Expected:** Comment persists once, activity records metadata, latest 50 entries and totals/limit notice render, and intended generic notifications commit with the comment.
- **Negative/non-disclosure:** Comment body is absent from activity/notification previews/logs and other units cannot read/post.
- **Cleanup:** Reset comments/activity/notifications/task.
- **Source:** docs/TEAMS.md:36-42,98-118; app/Services/TeamTaskService.php:182-228
- **Evidence:** Comment/detail/history limits, notification preview, invalid input, and cross-unit denial.
- **Verdict:** `NOT_RUN`

## TW-WEB-005 — API idempotency produces one web-visible task or comment
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** e08f383
- **Requirement:** `TEAM.API.WEB_PROJECTION_IDEMPOTENCY`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Bearer Team API mutation projected into the web Team board
- **Route/API:** POST /api/v1/team/{unit}/tasks or /tasks/{id}/comments; GET /{unit}/teams[/tasks/{id}]
- **Actor/grants:** Active workstation member with both an authorized bearer identity and web workstation session
- **Preconditions:** Synthetic task/comment payload; valid UUID-v4 nonce; Team API migration present; web row/activity/notification counts recorded.
**Steps**
1. Submit a task or comment once through the bearer Team API, replay the identical nonce/payload through that same API route, and then open the web board/detail.
2. Reuse the nonce with changed content through the API and reload web board/detail/notifications.
- **Expected:** Exact API retry resolves to the original task/comment with one side effect visible on the web surface; changed payload conflicts and web state remains singular.
- **Negative/non-disclosure:** The web controller is not claimed to originate client nonces; nonce is not shown to users, and conflict does not append activity/notification or overwrite content.
- **Cleanup:** Reset task/comment/notifications.
- **Source:** app/Controllers/Api/V1/Team/TasksController.php:45-95; app/Services/TeamTaskService.php:126-228,413-503; docs/TEAMS.md:125-132
- **Evidence:** Original/replay/conflict API responses and web-visible row/activity/notification counts.
- **Verdict:** `NOT_RUN`

## TW-WEB-006 — Calendar views preserve bounded filters and task deadlines
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CALENDAR_VIEWS`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-02`
- **Surface:** Team calendar
- **Route/API:** GET /{unit}/teams/calendar
- **Actor/grants:** Active workstation member
- **Preconditions:** Synthetic events/tasks spanning month/week/agenda boundaries, multi-day/midnight event, >limit optional truncation fixture.
**Steps**
1. Switch month, week, and 30-day agenda while preserving date/mine/status filters.
2. Compare event overlap and task due-date placement, including midnight end and completed marker.
- **Expected:** Views use Manila input/display, UTC storage, correct overlap rules, live task due dates, and bounded results/truncation notice.
- **Negative/non-disclosure:** Calendar does not reserve appointments, detect clinical conflicts, or reveal other units/tenants.
- **Cleanup:** Reset calendar/task fixtures.
- **Source:** docs/TEAMS.md:48-61; app/Services/TeamCalendarService.php:67-104,306-342
- **Evidence:** Three view screenshots, filter URLs, boundary-event placement, and truncation/task reconciliation.
- **Verdict:** `NOT_RUN`

## TW-WEB-007 — Event creation validates participants and Manila interval
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.EVENT_CREATE`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-02`
- **Surface:** Team calendar event form
- **Route/API:** POST /{unit}/teams/calendar/events/store
- **Actor/grants:** Active workstation member
- **Preconditions:** Synthetic title/location; future Manila start/end under 31 days; eligible participant and ineligible/foreign controls.
**Steps**
1. Create the event with eligible participants and inspect detail/calendar/invitations.
2. Attempt end-before-start, >31-day duration, duplicate/>100/ineligible participant cases.
- **Expected:** Valid event stores UTC timestamps/version 1 and current eligible participants; invalid cases create no event.
- **Negative/non-disclosure:** Participation grants no edit or clinical-record access; event does not send email/SMS or include sensitive text in notifications.
- **Cleanup:** Cancel/reset synthetic event/notifications.
- **Source:** docs/TEAMS.md:52-60; app/Services/TeamCalendarService.php:115-158,261-301
- **Evidence:** Form/detail/calendar, stored-vs-Manila timestamps, invitations, and invalid row-count proof.
- **Verdict:** `NOT_RUN`

## TW-WEB-008 — Event edit and terminal cancellation enforce organizer/manager and version
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.EVENT_AUTH_VERSION`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-02`
- **Surface:** Team calendar event
- **Route/API:** POST /{unit}/teams/calendar/events/{id}/update|cancel
- **Actor/grants:** Organizer, participant, manager/supervisor
- **Preconditions:** Synthetic event in two sessions with organizer and participant; current version; inactive former participant optional.
**Steps**
1. Attempt edit as participant, then edit as organizer or manager with current version.
2. Submit stale version, cancel with current version, then attempt another edit/cancel.
- **Expected:** Participant cannot edit; authorized current update succeeds; stale fails 409; cancellation is terminal and retained, with idempotent current-repeat behavior.
- **Negative/non-disclosure:** Cancellation does not delete history/participants or require former participant eligibility; no clinical schedule changes.
- **Cleanup:** Reset event/notifications.
- **Source:** docs/TEAMS.md:52-59; app/Services/TeamCalendarService.php:160-203
- **Evidence:** Actor/version matrix, updated detail, cancelled history/filter, and terminal edit denial.
- **Verdict:** `NOT_RUN`

## TW-WEB-009 — Chat sends immutable message with task links and reply
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CHAT_SEND`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Department Team chat
- **Route/API:** POST /{unit}/teams/chat/send
- **Actor/grants:** Active workstation member
- **Preconditions:** Same-unit active tasks, existing reply target, valid UUID-v4 nonce, <=2000-character synthetic message; foreign task/message controls.
**Steps**
1. Send a message with multiple task references and a reply target; inspect rendered message/link/quote.
2. Try foreign/inactive task, cross-unit reply, empty/oversized body, then look for edit/delete actions.
- **Expected:** Valid immutable message appears chronologically with authorized links/reply; invalid references/input fail.
- **Negative/non-disclosure:** No edit/delete/attachment/DM exists, no clinical data is copied, and other units/tenants cannot resolve references.
- **Cleanup:** Reset synthetic chat/message/task fixtures in disposable database.
- **Source:** docs/TEAMS.md:63-80; app/Services/TeamChatService.php:72-158,291-339
- **Evidence:** Composer/result, linked tasks/reply rendering, invalid matrix, and absence of edit/delete controls.
- **Verdict:** `NOT_RUN`

## TW-WEB-010 — Chat nonce replay is idempotent and changed reuse conflicts
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CHAT.NONCE`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Department Team chat
- **Route/API:** POST /{unit}/teams/chat/send
- **Actor/grants:** Message sender
- **Preconditions:** Synthetic message/nonce; optional task/reply/mention metadata; message and notification counts captured.
**Steps**
1. Send once, simulate lost response, and retry identical nonce/payload.
2. Reuse the nonce with changed body/reference/mention/reply and reload history/inbox.
- **Expected:** Identical retry returns the original message and notifications once; changed reuse returns 409 without a new message.
- **Negative/non-disclosure:** Nonce is visible only to its sender and no automatic replay changes locked draft content.
- **Cleanup:** Reset message/notifications.
- **Source:** docs/TEAMS.md:78-80; app/Services/TeamChatService.php:72-158,273-289
- **Evidence:** Original/retry/conflict responses and singular message/notification counts.
- **Verdict:** `NOT_RUN`

## TW-WEB-011 — Validated mention delivers one generic account-owned notification
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CHAT.MENTION`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Team chat and notification inbox
- **Route/API:** POST /{unit}/teams/chat/send; GET /notifications
- **Actor/grants:** Active sender and current same-unit mentioned member
- **Preconditions:** Synthetic Unicode/emoji message with exact @name UTF-16 range; duplicate/self/foreign mention controls; mention migration present.
**Steps**
1. Send a message mentioning the same other member twice and self once; inspect recipient/sender inboxes and exact-message link.
2. Submit invalid range/text/foreign member mention and inspect message/notification transaction.
- **Expected:** One generic recipient notification commits and links to authorized exact message; self/repeated mention adds none; invalid mention rolls back send.
- **Negative/non-disclosure:** Notification preview contains no sender/body/task/quote text and inaccessible recipient/message targets are not exposed.
- **Cleanup:** Reset message/notifications/read state.
- **Source:** docs/TEAMS.md:69-80,92-96; app/Services/TeamChatService.php:313-377
- **Evidence:** Rendered mention, recipient-only generic notification/link, sender absence, and invalid transaction rollback.
- **Verdict:** `NOT_RUN`

## TW-WEB-012 — Chat history pagination, auto-backread, and read cursor reconcile
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `d43923d`
- **Requirement:** `TEAM.WEB.CHAT.HISTORY_READ`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Team chat (auto-backread pagination and circular scroll-to-latest button)
- **Route/API:** GET /{unit}/teams/chat/history; POST /{unit}/teams/chat/read
- **Actor/grants:** Active workstation member
- **Preconditions:** At least 45 synthetic messages with own/other senders; unread cursor before page; around-message deep link; optional realtime disabled for poll fallback.
**Steps**
1. Open chat, scroll upward to trigger automatic backread pagination (and open an around-message link), observing fixed 40-page/window behavior and circular scroll-to-latest control visibility.
2. Click scroll-to-latest or acknowledge the last fully visible message, then add messages and observe unread count/reconciliation.
- **Expected:** History backreads seamlessly at the top viewport edge, jump-to-latest appears cleanly when scrolled back, read cursor increases monotonically only on visible acknowledgement, and own messages exclude from unread.
- **Negative/non-disclosure:** Browsing older/around history does not mark unseen messages read, no public receipts appear, and foreign target IDs fail.
- **Cleanup:** Reset messages/read cursor and realtime test state.
- **Source:** docs/TEAMS.md:73-79; app/Services/TeamChatService.php:42-71,178-241; public/assets/js/team-chat.js:1-120
- **Evidence:** Backread scroll/window captures, scroll-to-latest interaction, cursor/unread reconciliation, and foreign-target denial.
- **Verdict:** `NOT_RUN`
