# Workflow bell alerts and scheduled delivery

Implemented 2026-10-23. **Local deployment activated with operator approval on 2026-10-23.** Other installations still require the migration and scheduling procedure below.

## Verified local activation

- Target: local `humus_db`, verified active tenant **1**.
- Applied only migrations `2026-10-23-000003` and `2026-10-23-000004`, both in batch **30**. Unrelated older pending migrations were not applied.
- Native backup: `writable/backups/humus_db_before_workflow_notifications_20261023_130843_17cf1e.sql`.
- Preservation evidence: `writable/backups/workflow_deployment_20261023_130842_3d250f95.json`. All **5,950** original non-history rows across **56** tables were preserved at migration verification. Maintenance was cleared.
- Windows task: **`Synapse-Workflow-Notifications-Tenant-1`**, enabled, repeats every minute under the current local user's interactive token, least/default privilege. It does **not run while that user is signed out**; no password or SYSTEM service was configured. Database availability is required.
- Action: verified PHP 8.3 `C:\Users\udtoh_lmtzs7k\php83\php-win.exe` running `writable/workflow-notifications-run.php`, with the project root as working directory. This windowless executable uses the same `php.ini` as `php.exe`. The wrapper also launches the sibling **`php-win.exe`**, with `bypass_shell`, retaining redirected CLI logs and exit-code checks. Both parent and child must be windowless: the earlier `php-win.exe` parent still flashed when it launched a console `php.exe` child; the attempted `create_no_window` proc_open option did not prevent that. The task's Hidden checkbox alone would not suppress a console window. The wrapper pins tenant 1, checks the local target/schema, skips maintenance, prevents overlap with a lock, and writes dated count-only logs. Task Scheduler also uses `IgnoreNew`; wrapper timeout is five minutes and task timeout is six. No web-server or WebSocket settings are changed.
- First controlled pass: **24 scanned**, **2 record calls**, **2 events processed**, **0 failures**, creating **10 authorized notices** for one current low-stock and one near-expiry condition. No clinical/hardware test operation was performed.
- An on-demand Task Scheduler run and an actual subsequent timed run completed with **Last Result 0**. Rechecks retained exactly **2 events / 10 notices**, with no pending/failed events or duplicate delivery. All **50 original notifications**, including read state, were fingerprint-verified unchanged after activation.
- Windowless correction verified with `python writable/verify-windowless-workflow.py`: direct, on-demand and subsequent timed runs completed with exit 0 and zero failures. Windows visible-console enumeration at 10 ms intervals detected no new console windows during direct execution or the 80-second scheduled observation. The task was left enabled. Existing notification/read-state fingerprints remained unchanged, with no pending/failed events or duplicate notices. The local verification script disables the task if its scheduled checks fail.
- Worker logs: `writable/logs/workflow-notifications-tenant-1-YYYY-MM-DD.log`. Read-only local verification: `php writable/workflow-development-status.php`.

Local operator artifacts under `writable` may be excluded from version control. Back up the task XML, wrapper and restricted deployment evidence separately if moving this development installation. Refresh already-open application pages once to load the expanded bell destination configuration.

## What is implemented

The existing personal notification bell now supports 24 additional workflow event kinds, through one `workflow_alert` storage type. Existing appointment-confirmation, referral-received, publication and Teams notices are retained.

| Event | Audience | Destination |
| --- | --- | --- |
| Appointment requested | Current same-department schedulers with read and scheduling-management grants, excluding actor | Clinic/Guidance appointments |
| Staff-console cancellation | Affected patient's person-owned inbox; suppress actor's own patient notice | Personal appointments |
| Self-service cancellation (including staff's own appointments) | Current department schedulers, excluding actor | Department appointments |
| Appointment approaching | Patient, within the configured lead window while scheduled/confirmed | Personal appointments |
| Referral acknowledged / review started / receiving care linked | Original source issuer, still authorized in the source workstation, excluding actor | Source Sent referrals |
| Referral closed | Original source issuer, still authorized, excluding actor | Source History |
| Guidance follow-up due / overdue | Current assigned case counsellor with case-read authority | Guidance cases |
| Active medicine low in usable stock | Current Clinic inventory readers/writers | Medicine inventory |
| Stock nearing expiry / expired stock still on hand | Same inventory audience, active medicine/stock conditions | Medicine inventory |
| BMG command completed / failed / rejected / cancelled / expired | Original command requester with current Facilities read/manage authority | Facilities operations index |
| BMG failed / interrupted rotation report accepted | Current BMG members with Facilities read/manage authority | Facilities operations index |
| Active bound controller contact overdue / restored | Same BMG operational audience | Facilities operations index |
| Fresh explicit RTC-invalid / recovered report | Same BMG operational audience | Facilities operations index |

Clinic scheduling recipients require `clinic.appointments.read` and `clinic.appointments.write`; Guidance uses `guidance.schedule.read` and `guidance.schedule.manage`. Workstation role membership is required alongside grants; superadmin's existing authority exception is retained. A provider enrollment is not assumed to be a login user. Inventory requires both `clinic.inventory.read` and `clinic.inventory.write`; BMG requires read/manage, not merely Teams membership. No hardware-control permission is granted by an alert.

Cancellation event suffixes identify the recipient: `appointment_cancelled_patient` targets the affected person, while `appointment_cancelled_staff` targets department schedulers. They are not actor-role labels. Both display **Appointment cancelled** without claiming who cancelled; staff accounts may also use self-service for their own appointments. The validated actor ID remains in the outbox. Presentation is resolved when reading an inbox, so this wording correction also applies to existing workflow notices without changing events, recipients, or read states.

Internal follow-ups are **not patient appointments**. Patients do not receive internal case titles, notes or action plans. Generic header messages contain no patient, medicine, case, event, device-location or comment text, cancellation reason, token or ACK payload. BMG wording says device-reported outcome/contact, never verified safe physical movement or emergency stop.

## Personal Notifications page

The bell footer now opens **View all notifications** at `/notifications/inbox` on the current workstation host, instead of linking every account to My appointments. The page uses `/notifications/history?filter=all|unread&page=1`, with 20 items per page, an exact authorized total, All/Unread filters, explicit individual and mark-all read controls, and Previous/Next navigation. The existing `/notifications` bell JSON contract stays unchanged (10 items, unread count saturated at 100 for the 99+ badge).

Both lists use the same current recipient, tenant and resource-authorization predicates and generic presentation. The full page is a **current authorized inbox, not an immutable audit history**: resolved, inaccessible or superseded notices may no longer appear. Opening the page or its destination links does not mark an item read; use its explicit read controls. Read mutations reuse existing CSRF-protected POST endpoints, never GET or automatic POST retries. The page refreshes on realtime hints, visibility return and fallback polling, with bounded request/body timeouts and SPA cleanup. No migration or worker configuration change is required.

Focused validation: 68 fresh-schema inbox/delivery tests (3,156 assertions), 5 private SQLite isolation tests (120 assertions), and 97 bell/history browser tests passed. Pagination tests cover all three departments, cross-account/tenant isolation, read filters, stale role revocation, empty/out-of-range pages and invalid query values. Browser coverage includes desktop/dark mobile, exact destinations, read reconciliation, timeouts and navigation lifecycle.

## Architecture and consistency

### Producer → outbox

`app/Services/WorkflowNotifications.php::record()` records identifiers only inside a healthy business transaction. Appointment request/cancellation, referral progress/closure, and accepted BMG outcomes/cancellations/expiry call it from their existing transaction owners.

- The source table is selected from a fixed server-owned event registry, not user input.
- Tenant is derived from the persisted source. Device requests do not inherit an unrelated human session tenant.
- Source unit, actor's tenant membership, identifier shapes and event kinds are checked.
- Stable occurrence/version keys and a tenant/event hash prevent repeated producers from delivering again.
- No fanout, HTTP, realtime publication or external provider is invoked in those business transactions.
- An outbox storage failure rolls back its owning mutation. **This is deliberate durability, not a claim that storage failure cannot affect device ingestion or a safety-reducing operation.** Recipient fanout is moved off those paths; outbox recording is still a required database write.

### Scanner → outbox

`WorkflowNotificationScanner` is finite, current-tenant and clock-injectable. It uses Manila date/time for existing scheduling/BMG source timestamps and UTC for new event/episode timestamps. It records alerts; it does not cancel appointments, mark follow-ups missed, issue/execute commands, change stock or mark a controller physically offline.

- Due follow-ups: one due event and one overdue event per due date; not one overdue alert every day.
- Appointment reminder: one per appointment version/start time, only during the lead window and before start. Confirmation cancellation/completion and superseded starts suppress delivery/visibility.
- Expiry: one per batch/expiration date/event kind, with positive on-hand active stock and active medicine.
- Low stock: one per persisted threshold episode; recovery observed by scanning resolves/rearms the episode. Archived medicines are excluded. Usable stock excludes expired/quarantined stock.
- Offline: active, uniquely bound, unrevoked controller on a live active drum, with first-contact grace. Revoked/unbound/archived/unavailable units are suppressed.
- Recovery: only an explicit fresh contact/report after an existing episode; rebind is not recovery.
- RTC: only fresh, nonfuture, current-binding explicit reports backed by contact. Omitted/unknown/stale values do not manufacture faults or recovery/rearming.
- Command deadline: one terminal expiry identity whether detected by the scanner or device polling. Scanner observes elapsed deadlines without changing command status. An actual device outcome is separately guarded by the BMG service.

Each source captures an ID ceiling and iterates in cursor chunks, so newly inserted rows cannot extend the invocation indefinitely. **`batchLimit` bounds each query, not total scan work.** It is a finite full sweep of the selected tenant; size the schedule for database volume and monitor runtime. It does not silently process only the first 100 subjects forever.

### Worker → existing bell

`WorkflowNotifications::dispatch()` processes a bounded pending-event batch:

- Rechecks current active tenant, source, recipient account/person, unit roles and grants.
- Uses a row lock and transactional fanout; notification inserts and processed marker commit together.
- At most one delivery per event/user or event/person; retry never resets `read_at`.
- Zero eligible recipients is a processed event, not a future late-joiner subscription.
- Failures roll back fanout, increment attempts and retain a fixed error code with exponential minute backoff capped at 60 minutes. Default maximum attempts is five.
- Exhausted events remain stored for operator investigation; they are not deleted. Correct the underlying failure and use a reviewed recovery procedure rather than resetting all events/read state.
- Only after commit are optional empty private-user realtime hints published. Bounded polling remains the fallback.

Recipient candidates are narrowed by workstation/owner before full current-identity checks. Notification list/count/read/read-all/realtime authorization reuse the same resource/audience predicate. Direct writes to the event table are not an authorization API.

Read, completed/resolved, superseded, revoked or inaccessible conditions can hide notices without deleting their read history. In particular, active referral progress notices disappear once the referral closes; the closure notice links to History. A resolved stock/device episode does not remain an actionable fault; recovery has its own generic notice when supported.

## Deployment — required before use

1. Back up the intended database and schedule a short maintenance window. Inspect migration status; do not automatically apply unrelated older pending changes.
2. Apply these two **new** additive migrations, in order, together with the application code:
   - `2026-10-23-000003_AddWorkflowNotifications`
   - `2026-10-23-000004_CreateWorkflowAlertStates`
3. Verify `workflow_events`, `workflow_alert_states`, `notifications.workflow_event_id`, the workflow enum value, tenant-safe FKs, uniqueness and shape checks. Both migrations are forward-only; do not drop delivery/episode history as rollback.
4. Set the intended tenant context for the CLI task (`SYNAPSE_TENANT_ID`). The command otherwise follows the existing `CurrentTenant` default of tenant 1. Configure one job per intended tenant; a tenant's worker never dispatches another tenant's events.
5. Configure Windows Task Scheduler or cron to invoke this finite command, normally once per minute from the project root:

   ```sh
   php spark notifications:dispatch --limit 100
   ```

   Windows Task Scheduler: program is the actual installed `php.exe`, arguments `spark notifications:dispatch --limit 100`, and **Start in** is the project directory. Set tenant environment for that task. Do not configure overlapping copies for the same tenant; row locks protect duplicates, but unnecessary overlap wastes work.
6. For additional outbox draining without another scan:

   ```sh
   php spark notifications:dispatch --limit 100 --scan 0
   ```

7. Check the counts/exit code and initial recipient volumes before enabling regular operation. Review failures and exhausted attempts using restricted operator access. Output contains counts only, not domain records or exception bodies.

**Coding tests did not perform operational deployment.** The separately approved local activation is recorded above; this procedure remains necessary for other environments. New notification-producing mutations require the outbox schema: deploying only code without both migrations can reject those writes rather than silently lose alerts. Deploy as one change.

The first scan evaluates **existing current conditions**: overdue internal follow-ups, current low/expired stock, overdue device contact and pending command deadlines can create initial alerts. It does not reconstruct historical request/cancellation/referral transitions before deployment. Plan the initial backlog/thresholds; do not call it “no backfill” for current-condition scans.

## Configuration

`app/Config/WorkflowAlerts.php` owns defaults:

| Setting | Default | Meaning |
| --- | --- | --- |
| `enabled` | true | Worker/scanner enabled; disabling delivery does not remove required transactional outbox writes. |
| `kinds` | All 11 scanner kinds enabled | Enable/disable timed detectors; transaction-driven outcomes are not controlled by these scanner flags. |
| `appointmentLeadHours` | 24 | Upcoming appointment reminder window. |
| `nearExpiryDays` | 90 | Stock expiry warning window; today remains unexpired under current inventory semantics. |
| `deviceOfflineMinutes` | 15 | Stale contact threshold; not proof of physical stop or failure. |
| `deviceFirstContactGraceMinutes` | 15 | Grace after registration/binding for first contact. |
| `rtcReportMaxAgeMinutes` | 15 | Maximum age of explicit RTC/contact facts for fault/recovery evaluation. |
| `batchLimit` | 100 | Source records per scan query, not total scanned records. |
| `dispatchLimit` | 100 | Events per worker batch; CLI can override. |
| `maximumDispatchLimit` | 1,000 | Maximum accepted CLI dispatch batch. |
| `maxAttempts` | 5 | Fanout attempts before operator intervention. |

An outage in the scheduled task means no scan/fanout; the browser cannot manufacture absent notification rows. Delivery latency is task interval + backlog/fanout time + bell refresh/reconciliation. This is **in-app only**, not email/SMS/OS push or emergency paging.

## Scope deliberately unchanged

- No bell notice for clinical note/vital/diagnosis/draft reads or saves, routine telemetry, validation failures or every stock decrement.
- Chat retains its own unread channel behavior; no department-wide bell message broadcast.
- Existing Teams task/event notices remain on their current producer path; Teams approaching/overdue reminders remain deferred.
- No automatic appointment from a recommended clinical follow-up date, patient-facing internal action plan, no-show decision, BMG motor/RPM/material quality inference or USB-success report.
- BMG delayed/conflicting ACK/run validation and hardware gates remain intact. The existing late-rejected-ACK housekeeping rollback semantics are not changed into an accepted command.
- No user notification-preference/unsubscribe UI, on-call roster, external provider or new dependency.

## Verification

All checks use synthetic records, private memory or runner-created schemas. Do not run database suites on operational data.

```sh
python tests/_support/run-fresh-database-tests.py tests/database/WorkflowNotificationMigrationTest.php tests/database/WorkflowAlertStatesMigrationTest.php tests/database/WorkflowNotificationDeliveryTest.php tests/feature/AppointmentReferralProducerTest.php tests/database/TeamsNotificationInboxTest.php tests/feature/ReferralNotificationTest.php
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/WorkflowNotificationScannerTest.php tests/unit/DispatchWorkflowNotificationsTest.php tests/database/TeamsNotificationInboxIsolationTest.php tests/unit/NotificationInvalidationTest.php tests/unit/NotificationParentTransactionTest.php
BMG_ISOLATED_TESTS=1 php vendor/bin/phpunit -c tests/_support/bmg-isolated-phpunit.xml --no-coverage --no-logging --do-not-cache-result
node --test tests/browser/notifications.test.cjs
```

Final focused results: **232 PHP tests / 5,041 assertions passed** (141 fresh-schema + 46 scanner/shared-invalidation + 45 guarded BMG tests), plus **86 browser tests passed**. PHP/JavaScript syntax and scoped whitespace checks passed. Coverage includes all 24 workflow kinds, migration restart/preservation, producer transactions, worker rollback/backoff/max attempts, recipient revocation, scanner episode rearming, stock replenishment/threshold suppression and device rebinding suppression. Command help was checked without running an operational dispatch.

The old `AppointmentNotificationTest` uses a different `_test`-database guard and is not compatible with the fresh-schema runner; an attempted broader run was blocked by that guard, not interpreted as a pass. Existing confirmation/Teams/referral coexistence is checked by the applicable shared-inbox suites. These are focused regressions, not full-suite certification.
