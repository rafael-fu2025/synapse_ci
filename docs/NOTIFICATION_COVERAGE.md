# Notification coverage — Clinic, Guidance and BMG

Reviewed 2026-10-23. This is an end-to-end source audit plus focused regression validation, **not a claim that every module action creates a bell notification**.

## Implementation update

The gaps approved after this audit now have an implementation: appointment request/cancellation alerts, referral progress/closure, Guidance follow-up due/overdue, stock/expiry alerts, and BMG requester outcomes/fault/offline/recovery alerts. A tenant-scoped transactional outbox, scheduled detector and finite dispatch command connect these to the existing bell with current-resource authorization, deduplication, retry/backoff and generic content.

**Local deployment activated:** migrations `2026-10-23-000003_AddWorkflowNotifications` and `2026-10-23-000004_CreateWorkflowAlertStates` were applied with operator approval to local `humus_db` in batch 30. The tenant-1 Windows worker is enabled every minute while the current user is logged in; controlled and timed execution returned zero failures. Other environments still require these migrations and recurring worker setup. Coding tests and this initial audit did not perform that separately approved activation. See [Workflow notifications](WORKFLOW_NOTIFICATIONS.md) for the implemented recipient matrix, timing defaults, initial scan behavior, safety limits and deployment/validation steps.

The sections below preserve the **pre-extension audit baseline**. Their “No”/missing-feature entries describe what was audited, not a claim that the subsequent implementation remains absent. Deliberate clinical/privacy exclusions and deferred features still apply.

## Notification timestamp contract

Notification `created_at` and `read_at` storage uses UTC `Y-m-d H:i:s`, including
confirmation/referral writes and explicit read actions regardless of PHP's default
timezone. Inbox and history JSON convert these values to ISO 8601 Asia/Manila
(`+08:00`) through `NotificationTime`. Both interfaces show that Philippine wall
clock with an explicit timezone tooltip and machine-readable `<time datetime>`;
the browser's timezone does not reinterpret it. No historical rows are rewritten.
Workflow notification creation time is delivery time, not necessarily the original
event time (the scheduled dispatcher may deliver later).

## Original audit summary

The bell at the audit baseline supported **13 event types**:

- Appointment confirmed.
- Referral received.
- Announcement published; survey published.
- Team event added, removed, updated, cancelled.
- Team task assigned, unassigned, updated, status changed, commented.

Teams coverage is shared across Clinic, Guidance and Facilities/BMG. Campus publication eligibility depends on the person's student/employee profiles, not workstation role: eligible BMG staff are not excluded.

**Missing capabilities:** appointment-request/cancellation alerts; referral acknowledgment/review/handoff/closure alerts; guidance follow-up reminders; inventory low-stock/expiry alerts; and BMG operational/device fault alerts. These have no implemented bell producer and are not enabled by the existing bell polling or the Teams migration. Some require new event/recipient policy; time-based alerts also require a scheduler and durable deduplication.

This audit fixes existing delivery defects without inventing those new policies or broadcasting confidential records.

## Audit scope and terminology

Covered all user-facing mutation families and relevant device paths in:

- Clinic encounters, health profiles/patient charts, medicine inventory.
- Shared Clinic/Guidance appointments, staff availability, queue and personal portal.
- Guidance cases, sessions, notes, follow-ups and surveys/question-bank support.
- Clinic↔Guidance referrals in both directions.
- BMG drums, schedules, batches, device binding/credentials, commands, telemetry/runs, USB/build/flash authorization.
- Teams tasks, calendar and chat in all three workstations.
- Announcements, surveys, bell storage/inbox/read permissions, exact destination allowlist and realtime/poll reconciliation.
- Shared configuration/admin/reporting actions insofar as they affect those modules; these are not treated as implicit notification subscriptions.

**Bell** means a persistent authorized row with unread/read state. **Live UI** means a realtime hint or polling refresh. **Local feedback** means the initiating actor's flash/inline result. **Audit/ledger** means a durable governance/business record. These are not interchangeable.

The audit did not create live appointments, referrals, device operations or notices, did not alter operational records, and required no new migration. Database validation used guarded disposable schemas or private SQLite fixtures. It does not certify deployed realtime, hardware behavior, real-device browsers or emergency delivery.

## Repairs made during this audit

### 1. Closed referrals open the correct folder

Original `referral_received` notices remain historical records. Once the referral is closed, clicking the notice now opens the receiving workstation's **History** rather than Incoming, which excludes closed records.

- `app/Services/NotificationService.php`: internal status lookup and exact `clinicReferralHistory` / `guidanceReferralHistory` destinations.
- `app/Views/partials/notifications.php`, `public/assets/js/notifications.js`: exact server-configured history allowlist entries, including indexed/base-path/cross-host deployments.
- Both directions retain their original read/unread state. No new closure event or clinical deep-link permission is introduced.

### 2. Removed referral patients no longer leave unusable bell items

Referral inbox eligibility now requires the referred patient to be live in the same tenant, matching the referral listing. The same predicate controls list, unread count, individual read, read-all and invalidation authorization. Suppressed history is not deleted or silently marked read.

- `app/Services/NotificationService.php`
- `tests/feature/ReferralNotificationTest.php`

### 3. A stalled read request cannot lock the bell indefinitely

Previously GET refresh had a deadline but POST read/read-all did not. A hung fetch or JSON body left `writing=true` and disabled reconciliation indefinitely.

- `public/assets/js/notifications.js` now bounds both phases to ten seconds, even if abort is ignored.
- Timeout restores controls, does not navigate or optimistically clear unread state, and reports that the result could not be confirmed.
- A bounded GET reconciles possible server success; **no automatic POST replay** occurs.
- Late responses cannot override newer state or navigate after timeout. Current CSRF handling and private exact destinations are preserved.
- `tests/browser/notifications.test.cjs` covers item/mark-all, hung fetch/body, successful/failed reconciliation and history routing/spoof rejection.

### 4. Publication verification now exercises real current authentication

The older announcement/survey fixtures supplied cached user data without the session-bound `_auth` metadata now required by `AuthenticatedUser`.

- Updated `tests/feature/AnnouncementWorkflowTest.php`, `tests/feature/SurveyWorkflowTest.php` and `tests/_support/PublicationRecipientFixtures.php`.
- Both suites guard against operational schemas before migrations.
- Added producer→inbox→read/privacy coverage for every supported Clinic, Guidance and BMG role with employee/student/both/no profiles, personless superadmin, inactive/deleted accounts.
- Reduced SQLite referral fixtures and schema-probe isolation were aligned with the actual queried columns and combined-suite execution. Production schema/auth rules were not weakened.

## Coverage matrix

### Shared appointments and queue — Clinic and Guidance

These scheduling services do not serve BMG operations. A BMG employee can independently receive a personal appointment notice as a patient.

| Event/action | Bell today | Other behavior / decision |
| --- | --- | --- |
| Patient requests appointment | No | Requested worklist and local acknowledgement. Staff-recipient policy is needed for a new request notice; no provider is yet assigned. |
| Staff requests appointment for a patient | No | Same requested state; not a confirmation/reservation. Decide whether a separate patient acknowledgement is useful. |
| Appointment confirmed / final time and provider allocated | **Yes, patient person-owned** | Persistent generic notice; exact personal appointments destination; transaction and version dedupe. Provider may be an enrolled employee without an account. |
| Legacy direct booking into `scheduled` | No | Different API/state from confirmation; a booking notice would be a new explicit contract. |
| Staff cancels appointment | No | High-value gap: generic patient cancellation communication is recommended. Do not include cancellation reason in header. |
| Patient cancels appointment | No | Candidate staff worklist/bell alert, especially for released allocated slots; suppress redundant patient self-notice. |
| Upcoming appointment / recommended follow-up due | No | Requires lead time, Asia/Manila semantics, scheduler, current-state checks, completion/cancellation suppression and dedupe. |
| Check in / issue walk-in ticket | No | **Live UI**: department board/dashboard and patient's own active ticket refresh after commit. |
| Call next / call specific | No | **Live UI** displays called state. Not a guaranteed sound/push/emergency page. |
| Start consultation | No | **Live UI** and clinical workflow; quiet in bell. |
| Skip ticket | No | **Live UI**, including recall deadline. |
| Recall ticket | No | Moves skipped→waiting, **not directly called**. Do not send misleading “called now” wording. |
| Complete consultation/linked appointment | No | **Live UI**; terminal active-ticket card disappears. Routine completion stays quiet. |
| Explicit no-show | No | Staff decision and live queue changes where applicable. Patient rebooking/tone/correction policy is needed before a patient-facing notice. |
| Recall deadline passes/day changes | No | No independent timeout/no-show notifier. Polling is not a scheduler. |
| Enroll staff / recurring availability/template/exception edit | No | Administrative configuration, not notification subscription. Exceptions do not automatically reschedule existing reservations. |

**Sources:** `app/Services/Scheduling/AppointmentService.php` (`requestAppointment`, confirmation, direct booking, cancellation and availability); `app/Services/Scheduling/QueueService.php`; `QueueInvalidation.php`; `PersonalQueueInvalidation.php`; `app/Services/PortalQueueService.php`; `public/assets/js/queue-realtime.js`, `queue-live.js`.

Confirmation notices describe a historical event: an old confirmation can remain readable after cancellation. Because cancellation currently has no notice, that can be confusing. Prefer an explicit cancellation event/wording policy over silently deleting notice/read history.

### Clinic encounters, patient charts and inventory

| Event/action | Bell today | Recommendation / boundary |
| --- | --- | --- |
| Start/resume direct or referred encounter | No | Keep clinical chart opening quiet; linked ticket changes use live queue hints. |
| Save vitals/BMI, assessment, diagnosis/treatment | No | Correct privacy/noise exclusion. Numeric warnings are not a clinical escalation service. |
| Save draft/clear optional fields | No | Keep autosaves/drafts quiet; retain actor-local save status. |
| Complete/seal/disposition | No | Stored clinical record and queue completion. A patient summary-release workflow would need separate authorization/design. |
| Store recommended follow-up date | No | Date alone creates neither an appointment nor a reminder. |
| Update allergies/conditions/emergency contact | No | Keep confidential data out of shared header. A care-team review task would require explicit recipients. |
| Read directory/chart/history | No | Reads are not bell events; auditing is a separate concern. |
| Create/edit/archive/reactivate medicine | No | Configuration/ledger; no blanket department alerts. |
| Receive stock | No | Ledger/local result; may resolve a future low-stock episode. |
| Dispense FEFO | No | Ledger and clinical snapshot. Avoid one alert per decrement. |
| Remove damaged/expired stock/count correction | No | Restricted ledger operation; notify only an actionable condition if a policy is added. |
| Quarantine/release lot | No | Candidate restricted inventory-staff alert, not automatic patient recall. |
| Reverse dispensation / restore never-issued stock | No | Correction history. Replays must never redeliver future notices. |
| Low/out-of-usable stock | **UI only** | Valuable gap: use active-medicine threshold transitions or a digest, with recipient/recovery/rearming rules. Archived medicines can also meet today's UI low-stock predicate; do not blindly notify them. |
| Near expiry / expiry | **UI only** | Current near-expiry summary uses 90 days. Timed scans needed even if nobody dispenses; dedupe per lot/window and suppress zero quantity. |
| Validation/stale/setup/integrity errors | No | Keep ordinary errors local; repeated integrity failures belong in restricted operational monitoring. |

**Sources:** `app/Services/Clinic/EncounterService.php`, `PatientHistoryService.php`, `PatientDirectoryService.php`, `InventoryService.php`; `app/Views/inventory/_results.php`; `tests/unit/InventorySummaryTest.php`.

### Guidance cases, sessions, notes and follow-ups

| Event/action | Bell today | Recommendation / boundary |
| --- | --- | --- |
| Create case / queue intake creates case | No | Optional generic assigned-counselor alert when another actor creates work for them; not automatic patient/source disclosure. |
| Open/resume session; case becomes active | No | Internal clinical state, normally quiet. Resume/retry is not a new event. |
| Save draft note / finalize / supersede version | No | **Must remain excluded.** No note excerpts, ciphertext, version/history information in bell payloads. |
| Read/decrypt note/history | No | Confidential access/audit concern, not bell. |
| Complete session | No | Clinical record/queue change; optional assigned-counselor operational policy only. |
| Move case to monitoring / close case | No | Optional assigned-counselor notice when another actor changes work. Never include summary notes. Does not automatically close referral. |
| Add internal follow-up/action item | No | No patient-facing recipient model. Assigned counselor is a candidate; do not mirror the action plan. |
| Follow-up becomes due/overdue | No | Needs a clock worker and episode/delivery identity. An old date does not automatically mark missed. |
| Explicit complete/missed follow-up | No | Candidate generic counselor notice if another actor acts; retries must stay quiet. |
| Case reassignment/reopening/deletion; follow-up reschedule/reassign | No | No corresponding complete mutation workflow in the audited service; domain feature first. |

**Sources:** `app/Services/Guidance/GuidanceCaseService.php`; `app/Models/GuidanceFollowupModel.php`; `app/Config/Routes.php` Guidance lifecycle routes. Distinguish internal follow-up rows, a session type `follow_up`, appointment type `follow_up`, immediate appointment confirmation and scheduled reminders: they are not interchangeable.

### Referrals — both directions

| Event/action | Bell today | Audience / decision |
| --- | --- | --- |
| Clinic→Guidance submitted | **Yes** | Explicit active receiving Guidance accounts with current `referrals.read` and department roles. |
| Guidance→Clinic submitted | **Yes** | Same policy for receiving Clinic staff. |
| Exact create retry | No new notice | Correct request-key and delivery dedupe; no late-joiner backfill. |
| Acknowledge | No | Recommended new progress notice to source issuer, not everyone/patient. |
| Start review / receiving assignment changes | No | Optional source progress/former-owner notice with current rights, suppress self-noise. |
| Create/link receiving case or encounter | No | Recommended source handoff progress, linking to their referral folder—not privileged receiving clinical records. |
| Handoff retry/resume existing link | No | Future notices must only emit on the first meaningful transition. |
| Close referral | No new notice | Original received notice now opens History. A new source closure notice needs a new event/identity. |
| Downstream clinical work completed | No inferred referral notice | Referral closure is separate; do not infer it from an encounter/session completion. |
| Patient/account/role no longer eligible | Existing notice hidden | Shared list/count/read/invalidation now follows live destination authority; history is retained. |

**Sources:** `app/Services/Referrals/ReferralService.php`; `app/Services/NotificationService.php`; `tests/feature/ReferralNotificationTest.php`. Creation excludes superadmin wildcard/source/patient fanout by current policy. New source progress notices require source-side permission checks and Sent/History destinations, not case access.

### Teams — same behavior in Clinic, Guidance and BMG

| Event/action | Bell today |
| --- | --- |
| Event created/participant added | **Yes**, new participants, actor excluded. |
| Participant removed | **Yes**, removed participant if still eligible; removal notice remains readable without current participation. |
| Material title/start/end/location update | **Yes**, retained participants and organizer when relevant. |
| Membership-only update | **Yes**, added/removed participants and organizer; retained attendees quiet. |
| Event cancelled | **Yes**, participants/organizer, current eligible recipients only. |
| Description-only edit / unchanged save | No, deliberate policy. |
| Task assignment/reassignment/unassignment | **Yes**, new/former assignee, with one notice per recipient/save and creator updates as applicable. |
| Task material fields/status/checklist change | **Yes**, creator/current assignee. |
| Task comment | **Yes**, creator/current assignee; no text preview. |
| Unassigned task created / unrelated members / actor's own action | No blanket broadcast. |
| Event approaching/task overdue | No scheduled reminders. |
| Chat message/read | Chat's **own unread state/live updates**, not a bell broadcast/global unread badge. |

**Sources:** `TeamsNotifications.php`, `TeamTaskService.php`, `TeamCalendarService.php`, `TeamChatService.php`, `TeamChatInvalidation.php`; full matrix/deployment at [TEAMS.md](TEAMS.md#teams-bell-notifications).

Bell links open the correct exact board/calendar route, not a task/event-specific deep link. Cancelled events may require the calendar's Cancelled/All filter; adding filtered deep links is an ergonomics improvement, not proof of lost delivery. Older pre-deployment events/tasks are not backfilled by an unchanged save.

### Announcements, surveys and question-bank support

| Event/action | Bell today | Audience / boundary |
| --- | --- | --- |
| Clinic or Guidance announcement published | **Yes** | Current eligible student/employee profiles, same tenant, publication audience/targeting. |
| Guidance survey published | **Yes** | Same person/profile-based policy; generic identified-survey notice. |
| BMG account receives publication | **Yes if profile eligible** | No BMG-role exclusion. `all` means student-profile ∪ employee-profile, not every operational account. |
| Dual student/employee profile | One person notice | No duplicate delivery. |
| No eligible profile/personless superadmin | No publication notice | Deliberate audience boundary; do not add operational roles as a workaround. |
| Draft create/edit | No | Quiet. |
| Archive announcement/close survey/expiration | No new notice | Existing notice becomes invisible under current publication-state eligibility. |
| Announcement read / survey submitted | No new notice | Content-read and bell-read are separate states. |
| Future publication starts | Existing row becomes visible at start | Polling reveals it; not a scheduled reminder. |
| New account/profile after publication | No historical fanout | Person-owned notice may predate account login, but eligibility acquired after publication does not trigger automatic fanout. |
| Question-bank create/edit/review/archive/reuse | No | Authoring/reference configuration; not a publication event. |
| BMG publishing / Clinic survey management | No such workflow | Not an accidental BMG notification exclusion. |

**Sources:** `AnnouncementService.php`, `Guidance/SurveyService.php`, `EngagementNotifications.php`, `EngagementAudience.php`; `docs/EMPLOYEE_ACCESS.md`. Regression fixtures explicitly cover every operational role/profile combination through actual publication, inbox and mark-read.

### BMG operations and device API

**None of the following currently creates a BMG operational bell row.** Facilities Teams coverage does not imply machinery monitoring.

| Event/action | Other behavior | Candidate policy / constraints |
| --- | --- | --- |
| Drum create/name/code/capacity edit | Saved configuration/audit/local result | Usually quiet metadata; optional operational handoff only. |
| Manual active/maintenance/offline status | Saved state; may disable intent/cancel commands | Candidate Ops/Config notice for meaningful availability change. Manual offline is not detected loss of contact. |
| Archive/restore | Audit; credentials retired, schedules disabled; restore starts offline | Optional authorized Config handoff; avoid multiple alerts for side effects. Archived destinations need special care. |
| Schedule edit/enable/disable | Audit, saved intent/version; pending commands may cancel | Optional meaningful Ops/Config change. Saved intent is not proof motor runs. |
| Device register/rebind/credential rotate/revoke | Audit, binding reset, intent disable/cancellations | Useful security/operational candidate for configured recipients. Never disclose token. |
| Command queued (`run_now`/`stop`) | Pending request, actor response/audit | Avoid redundant self-notice; asynchronous outcome is more useful. Web stop is not emergency stop. |
| Command ACK completed/failed/rejected | Stored `acknowledged` plus separate `ack_status` | High-value requester notice; failed/rejected share outer status `acknowledged`, so inspect outcome. |
| Command cancelled by safety/config/binding change | Stored transition when parent transaction commits | Candidate affected-requester notice, ideally combined with parent event. |
| Command deadline elapsed | Lazy housekeeping during other operations | Independent prompt alerts need periodic evaluation; offline devices may never poll. |
| Heartbeat/contact/normal telemetry | Snapshot/timestamps | No alert per heartbeat; absence of requests cannot itself execute a hook. |
| Device contact overdue/recovered | No incident episode detector | Scheduler, grace/hysteresis, recovery and suppression after revoke/archive required. |
| RTC invalid / sustained unapplied schedule | Stored explicit reports | Candidate fault episodes; omitted telemetry does not mean recovery. Allow propagation grace. |
| Normal/stopped rotation report | Received fact/history | Usually quiet; not independently verified physical movement. |
| Failed/interrupted rotation report | Received fact/history, retry dedupe | High-value Ops/Config candidate on accepted commit. Delayed offline uploads must not spam/contradict incidents. |
| Missing expected rotation | No slot-attributed detector | Domain reconciliation/grace policy before alerts. |
| Batch start/release/cancel | Human bookkeeping/audit | Optional shared Ops handoff. Release/cancel can happen while device unavailable; not hardware certification. |
| Automatic batch ready/overdue | No readiness policy/automatic producer | Do not invent compost quality/duration thresholds. |
| USB read/status/sketch/manifest/download | Authorized response | Quiet reads. |
| Build success/failure/private artifact expiry | Local/private artifact result | Current build is synchronous; commissioner feedback is local. Timed expiry notice needs explicit policy. |
| Flash authorization | Audit of authorization only | Must not announce successful flash/commissioning. |
| Browser USB flash/readback/disconnect outcome | Browser-local log | No trusted server completion-report endpoint; cannot notify server-side about unreported outcomes. |
| Bad token/validation/throttling/maintenance | API/UI error | No per-request department bell spam; aggregate monitoring separately. |
| RPM/temperature/moisture/quality thresholds | Not current verified signals | No invented sensors or safe-running claims. |

**Sources:** `app/Services/Facilities/BmgRotationService.php`, `BmgService.php`, `BmgFirmwareService.php`, `BmgBuildService.php`; `app/Controllers/Api/BmgDeviceApiController.php`; `public/assets/js/bmg-usb.js`, `bmg-setup.js`; `firmware/bmg/compost/compost.ino`.

Recipient candidates are **not implemented subscriptions**: active same-tenant BMG members with operational read/manage authority, additional configure authority where appropriate, or the command's currently authorized requesting account. Teams membership does not imply machinery grants; hardware authority remains deliberately opt-in. No on-call/unit-watcher roster exists.

### Shared administration and supporting screens

Reports/export completion, audit verification, MIS synchronization, RBAC/access edits, profile-photo changes and login-carousel administration use their own local/progress/audit surfaces, not general workstation bell broadcasts. Routine reads/filtering, failed validation, access denials and idempotent retries should stay quiet. Security escalation, report-ready background jobs or administrative assignment notices would need a separate recipient/privacy contract; do not infer them from a permission grant or audit row.

## Other source risks recorded, not changed

- BMG ACK revalidation can first mark a command cancelled/expired, then reject its ACK with409; that request's transaction rolls the housekeeping back. A later successful poll can commit it. The invalid ACK is not accepted, but stored pending state can remain stale. This is a command-state issue, **not a missing bell hook**; avoid changing safety transaction semantics under a notification audit. Source: `BmgRotationService.php` acknowledge/revalidation/expiry/transaction methods.
- Person-owned prefixed publication queries are not certified by the personless Teams prefix test. This audit's guarded full-schema runner uses an empty prefix; no claim of complete nonempty-prefix publication coverage.
- Realtime hints are bounded/best effort. Bell row persistence, not a successful hint, is durable delivery. Hidden tabs do not poll. No bell workflow is emergency paging.

## Extension priorities

1. **Patient cancellation communication**, then actionable scheduling requests/cancellations for staff with explicit audience policy.
2. **Referral source progress/closure**, preserving source-vs-receiving clinical boundaries.
3. **BMG asynchronous failures/requester outcomes**, using committed command/run identities and accurate device-reported wording.
4. **Clinic active-medicine low-stock episodes** and an expiry digest; decide recipient group, recovery/rearm and archived/empty-lot suppression.
5. **Guidance follow-up/appointment reminders and BMG offline/deadline monitoring**, only with a periodic worker, explicit timezone/grace policy and durable once-per-window/episode keys.

Do not mirror every audit event, note save, heartbeat or stock decrement into the bell. For safety-reducing BMG operations/device-fact ingestion, consider a durable event/outbox: making revoke/disable or hardware fact acceptance depend on successful bell fanout could add operational risk.

Every approved new alert must include all layers:

1. Defined domain event, truthful generic copy, intended recipient and actor/self-noise policy.
2. Durable schema/type/reference and retry/concurrent delivery identity.
3. Business transaction/event-outbox atomicity; no precommit publication.
4. Fresh tenant/account/role/resource eligibility shared by list/count/read/invalidation.
5. Exact portal-aware destination registered server-side and in browser allowlist.
6. Bounded POST/GET recovery, late-response safety and retained read history.
7. Tests for actual producer→inbox→read, rollback, duplicates, revocation, all supported workstations and no confidential content.
8. For time-driven alerts: clock tests, cancellation/completion suppression, repeated/concurrent sweeps and offline cases with no incoming requests.

## Validation

Focused tests cover the existing events and the three repairs, **not the unimplemented candidate alerts**.

```sh
python tests/_support/run-fresh-database-tests.py tests/feature/ReferralNotificationTest.php tests/database/TeamsNotificationInboxTest.php tests/feature/AnnouncementWorkflowTest.php tests/feature/SurveyWorkflowTest.php
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/database/TeamsNotificationInboxIsolationTest.php tests/unit/NotificationInvalidationTest.php tests/unit/NotificationParentTransactionTest.php
node --test tests/browser/notifications.test.cjs
```

**Results:** guarded MySQL/MariaDB suite **82 tests / 5,957 assertions passed**; private SQLite/mocked invalidation suite **28 tests / 301 assertions passed**; bell browser suite **61 tests passed**. PHP/JavaScript syntax and scoped whitespace checks passed. The separate in-memory queue-realtime harness also passed 11 tests during the audit. No live delivery claim is inferred from these fixtures.

The fresh runner creates/destroys a guarded local disposable schema; SQLite tests use private memory databases. Never point these suites at the operational database. Refer to [TESTING.md](TESTING.md) for safety boundaries. Initial combined runs identified reduced-fixture columns/schema-probe isolation issues; those fixtures were corrected rather than weakening production authorization or schema validation.

**Bottom line:** all three workstation/module families were checked. Existing bell behavior is now more reliable, but missing operational/reminder event types remain explicitly listed above rather than being mislabeled as supported notifications.
