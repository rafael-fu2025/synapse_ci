# Background mutations: current implementation and extension contract

This is a map of the current implementation, not a claim that every POST is
background-enabled. Background mutations save through an explicit JavaScript
owner, then reconcile with server-rendered state without replacing the whole
application shell when safe. Native forms, deliberate destination changes, and
security recovery remain supported.

## Shared infrastructure and function map

Paths below are relative to the repository root.

| Responsibility | Implementation | Contract |
| --- | --- | --- |
| Shared feedback | `public/assets/js/toast.js`, `public/assets/css/toast.css` | `SynapseToast.show(message, options)` provides keyed success/error/info/warning feedback, dismiss and optional action. Messages are text, not server-supplied HTML. `duration: 0` makes a warning sticky. |
| Layout loading | `app/Views/layouts/app.php`, `public/assets/css/style.css` | Bootstrap and CSRF load before toast and mutation owners; `form-modal.js` installs the shared helper, navigation loads before `background-actions.js`. `style.css` imports `toast.css`. These are shared assets, not scripts to inject on every refresh. |
| Explicit small forms | `public/assets/js/background-actions.js` | Delegated submit handling applies only to `form[data-background-submit]`, when fetch, toast and the mutation helper are available. It does not intercept every POST. |
| Confirmed-save reconciliation | `public/assets/js/form-modal.js` | `SynapseMutations.refresh(url, message, { form, scroll })` locks the committed form, calls `SynapseNavigation.markSaved(form)` once, validates the same-origin return URL, checks other drafts, then requests a GET navigation. `canRefresh(form)` checks whether that read is safe; it is not a pre-save permission gate. |
| Draft tracking and document navigation | `public/assets/js/navigation.js` | `hasUnsavedChanges(exceptForm)` checks connected dirty forms other than the supplied form. `markSaved(form)` removes only that form from dirty tracking. `navigate()` reconciles the main content, styles, scripts, CSRF, history and sidebar within a compatible shell. |
| Generic modal owner | `public/assets/js/form-modal.js` | Handles `data-modal-submit` forms in the active modal, except the separately owned carousel upload. `closeSaved()` waits for modal teardown before the GET. `synapse:modal-saved` lets the upload owner use the same teardown. |
| Confirmation and request support | `public/assets/js/confirm.js`, `public/assets/js/csrf.js`, `public/assets/js/loading.js` | Preserve explicit consent, submitter intent, rotating CSRF and busy feedback. Confirmation is not replaced by a toast. |

## Save and refresh flow

1. **Validate and confirm the action.** Keep the real form action, method, CSRF,
   authorization and any version/idempotency fields. The small-form owner honors
   the chosen submitter and rejects unsafe method/target/origin overrides.
2. **Send one POST.** The owner prevents duplicate submission and navigation while
   its write is pending. For small forms, controls are disabled and the main area
   is temporarily inert. Capture the form data before disabling its controls.
3. **Require confirmed success.** A successful JSON contract is not interchangeable
   with an HTML login response or an interrupted request. Synchronize returned
   CSRF. A timeout or network interruption can mean the server committed even
   though the client did not receive confirmation; ask the user to inspect the
   record before trying another write.
4. **Commit only this form locally.** Freeze its controls and mark only its own
   draft saved. Unrelated forms do not become clean. Generic modals close and
   finish removing their backdrop/focus trap before reconciliation starts.
5. **Check other drafts after the POST.** If another editor is dirty, defer the
   GET and leave that editor intact and editable. Show the sticky saved-but-not-
   refreshed warning. There is deliberately **no pre-POST veto based on other
   dirty forms**: otherwise two dirty editors could each prevent the other from
   saving. Saving A can defer its read; saving B can then make a read safe.
6. **Fetch authoritative state when safe.** The helper calls
   `SynapseNavigation.navigate(target, { history: 'replace', scroll, fallback: false })`.
   Ordinary GET failures stay on the existing screen rather than silently
   discarding drafts with a full-page reload. A successful refresh shows the
   success toast.
7. **Retry only the read.** A failed/deferred refresh leaves the saved form locked
   and shows a sticky warning with **Retry refresh** when the return URL is safe.
   That action rechecks drafts and repeats only the GET. It never repeats the
   POST. Invalid/external URLs do not receive an unsafe retry action.

`fallback: false` is not a promise that hard navigation can never happen.
`navigation.js` can still replace the document for authentication/session status
(`401`, `403`, `419`, or an auth destination), identity/permission scope or shell
link changes, and recovery after destructive/partial script installation has
started. Retaining a privileged stale shell or half-initialized page is unsafe.
Normal link navigation also retains its native/full-document fallback where
progressive navigation cannot handle the document.

## Screen / action / owner map

### Explicit row and workspace forms

These forms use `data-background-submit` and the shared POST → safe GET contract.
The small-form owner refreshes **`location.href`**, not the controller's generic
list redirect: folder, selected date, filters, pagination and current URL context
are retained. The server still owns permissions, validation and resulting state.

| Screen | Implemented actions | View and server ownership |
| --- | --- | --- |
| Staff announcements | Publish draft; archive | `app/Views/announcements/index.php`; `AnnouncementController.php` |
| Portal announcement detail | Mark as read | `app/Views/announcements/show.php`; `AnnouncementController.php`. Read is not an assertion of comprehension. |
| Staff surveys | Publish; close | `app/Views/surveys/index.php`; `SurveyController.php` |
| Question bank | Archive active question | `app/Views/question_bank/index.php`; `QuestionBankController.php`. Historical copies/revisions remain server-owned. |
| Audit ledger | Verify | `app/Views/admin/audit.php`; `AuditController.php`. The JSON validity result must not be treated as unconditional success. |
| Staff appointments | Cancel pending request/appointment; explicitly mark no-show | `app/Views/appointments/index.php`; `AppointmentController.php` (`cancel()`, `noShow()`, `respondToForm()`) |
| Personal appointments | Cancel | `app/Views/portal/index.php`; `AppointmentController.php` personal cancellation path |
| Referral folders | Acknowledge; start review; close handoff | `app/Views/referrals/_results.php`; `ReferralController.php`. Refresh the current folder/filter/page, not an assumed default folder. Closing a handoff does not close a linked clinical record. |
| Admin login carousel | Toggle visibility; delete | `app/Views/admin/carousel/index.php`; `AdminCarouselController.php` |
| Clinic patient chart | Update health profile | `app/Views/clinic/patients/chart.php`; `ClinicController::updateHealth()` and its JSON/native response helper |
| Guidance case workspace | Save/finalize notes; create/complete/mark missed follow-up; open/close session; monitor/close case | `app/Views/guidance/cases/view.php`; `GuidanceController.php`. Explicit status consent and server rules remain intact. |

Controller paths in this table are under `app/Controllers/`.

Carousel **reorder** is already background-owned by its dedicated script in
`app/Views/admin/carousel/index.php`, not by the small-form handler. It uses the
shared toast (`carousel-reorder`) while retaining its own ordering/request logic.
Do not attach a second POST owner to it.

### Modal saves and dedicated owners

| Screen / flow | Owner and behavior |
| --- | --- |
| Generic modal forms: teams/tasks/comments/moves, team calendar/events, inventory, employee access, content drafts, appointments and referrals | `form-modal.js` handles active-modal `data-modal-submit` saves. Use the **server-selected `payload.redirect`**, which can point to a newly created record, calendar date, correct folder or other canonical destination. Do not replace it with the current URL or a hardcoded list. Successful saves close the modal before calling the shared safe GET helper. |
| Carousel upload/edit | `app/Views/admin/carousel/_form_scripts.php` retains its XHR upload/progress owner. It requests toast-owned feedback when the helper exists, uses shared modal teardown, and then refreshes the server-selected URL. It is explicitly excluded from the generic modal submit handler. |
| Availability workspace | `public/assets/js/availability-workspace.js` owns `data-availability-save`: context/template/enrollment/exception saves, workspace action/return URL validation, modal dismissal and shared refresh. Preserve the server-selected workspace query context. |
| Facilities BMG records | `public/assets/js/bmg.js` owns `data-bmg-submit`: unit, batch, schedule, command, archive/restore and credential-revoke forms. It validates facilities-scoped action/return URLs, releases its pending-write veto, closes its modal, then refreshes through the helper. Saving intent does not prove a motor action occurred. |
| One-time device credential issuance | **Excluded** from generic background refresh. `app/Views/facilities/_operation_modals.php` submits the credential-issue form without `data-bmg-submit`; the secret-bearing result must not be lost to a generic GET. USB/setup owners retain their own secret and device lifecycle rules. |

The generic modal behavior applies to forms owned by the active modal, not every
form in every view. Existing direct-route and no-helper destination fallbacks
remain; in particular direct-route Cancel can still return to its declared URL.

### Clinical draft ownership and presentation state

- **Patient chart:** the health-profile form receives JSON for background requests
  and retains native response behavior. The chart's `synapse:before-swap` listener
  preserves a valid selected tab for the same patient. Other dirty forms defer
  the refresh rather than being overwritten.
- **Guidance:** case-workspace saves use the same dirty-preserving helper. The
  view preserves a still-valid selected tab for the same case. It copies tab
  presentation into the detached server view, **not unsaved note contents**.
  Hidden-tab drafts count as drafts; a follow-up action must not erase notes.
- **Encounter draft/dispense:** `public/assets/js/encounter-stepper.js` owns this
  workspace. Before dispensing it saves the clinical draft. After a confirmed
  draft save, it marks only clinical forms whose owned fields were saved and
  still match the saved snapshot. Dispense retains explicit consent and the
  existing idempotency key, then releases its private refresh guards only after
  confirmed stock deduction. The medicine form is frozen and the shared helper
  refreshes the current encounter, preserving the step. Another unrelated draft
  or a GET failure must not cause stock deduction to be replayed.
- **Encounter completion:** remains an intentional exit via the server-selected
  redirect. It is not a same-screen mutation conversion. Unfinished medicine
  entries and completion validation still belong to the stepper.

### Background work that already has a dedicated flow

These are not missing conversions merely because they do not use
`data-background-submit` or the shared refresh helper:

| Area | Existing implementation / distinction |
| --- | --- |
| MIS synchronization | `public/assets/js/mis-sync.js`: background submit and status reads, with its own progress/result UI. |
| Team chat | `public/assets/js/team-chat.js`: dedicated message/read/update requests and workspace lifecycle. |
| Notifications | `public/assets/js/notifications.js`, `notification-history.js`: background state reads/updates. Opening an authorized destination uses progressive navigation when available, with a location fallback. |
| Profile photo | `public/assets/js/profile-settings.js`: upload/removal requests and targeted photo updates, with its own validation/lifecycle. |
| Filters and paginated results | `public/assets/js/live-filters.js`, `live-table.js`, `availability-filters.js`: GET-only reads/history/context handling, not mutations. |
| Queue | Board call/start/skip/complete/recall retain their existing HTMX/live owners. Walk-in issuance now has a dedicated single-POST background save with toast, modal teardown and GET refresh; explicit no-show forms use `data-background-submit`. `QueueController` preserves HTMX response priority and native fallback contracts. |

## Remaining, deferred and intentional navigation

The map above is bounded by the actual opt-ins and dedicated owners. It is not a
blanket migration of all native POSTs.


- **Destination handoffs:** appointment check-in in
  `app/Views/appointments/index.php` remains a native submit whose server response
  leads to the queue. `app/Views/queue/board_partial.php` starts a clinic encounter
  through a native POST. These create/enter another workflow; a generic current-
  page refresh would hide the destination rather than improve it.
- **New records:** use the server-selected record/workspace destination. A modal
  save may reach it with a progressive GET; preserving that destination does not
  require a hard reload. Do not force every create back to the source list.
- **Login/logout and cross-host handoffs:** retain authentication-owned document
  transitions. They are not ordinary same-shell background mutations.
- **Identified survey completion:** `app/Views/surveys/take.php` already submits
  through its dedicated confirmed request, then intentionally leaves the answer
  form. It resets on pagehide and reloads on persisted `pageshow` (BFCache) to
  avoid reviving a stale sensitive response form. Do not convert that privacy
  behavior into a generic same-screen refresh.
- **Device secrets, commissioning and hardware:** keep one-time credential
  delivery and specialized setup/USB lifecycle outside generic interception.
  Background server intent is not physical device acknowledgement or safety
  certification.
- **Downloads and read-only UI:** exports/download owners, filters, queue polling
  and navigation GETs are not mutation-success-banner work. Keep GET submit
  buttons, real form actions and native fallbacks; do not remove them in the name
  of background mutation coverage.
- **Fallback and recovery:** no-JavaScript/native submissions retain their server
  redirects, validation and flash behavior. Specialized no-helper fallbacks also
  remain. Auth/scope and partial-script recovery may still require hard navigation.

No inference that an unlisted native POST is safe to intercept should be made
from this document. Further conversions require a screen-specific ownership and
response audit; they are deferred, not silently implemented here.

## Server feedback contract

For participating response helpers, suppress duplicate success flash **only**
when the request has the exact header value:

```http
X-Synapse-Feedback: toast
```

AJAX detection or `Accept: application/json` alone does not transfer feedback
ownership. A different header value does not qualify. Do not globally clear flash
storage: unrelated earlier feedback may still be valid. Preserve the ordinary
redirect/flash response for native submissions, plus appropriate JSON failure
status, safe message and current CSRF for background owners. Audit verification
also has an explicit validity/error feedback path.

The header changes feedback ownership, not authorization, CSRF enforcement,
optimistic locking or business semantics. A failed refresh after a confirmed
write must say **saved but not refreshed**, not imply the mutation failed.

## Extension checklist

1. Choose one owner: explicit small-form opt-in, active generic modal, or a
   dedicated controller. Never add blanket form interception or competing submit
   handlers to an existing specialized flow.
2. Verify the endpoint's JSON/native response contract and server-selected
   destination. Keep CSRF rotation, validation status, authorization, versions,
   idempotency and explicit confirmation.
3. Send the toast feedback header only when the client actually owns that
   feedback; preserve native/no-helper behavior.
4. Guard duplicate writes and in-flight navigation. Do not interpret abort as
   rollback or automatically replay an uncertain POST.
5. After confirmed success, freeze the saved form and mark only its owned draft
   saved. Close/tear down the modal and release private navigation guards before
   requesting the GET. Do not clear unrelated drafts or pre-veto a POST merely
   because another form is dirty.
6. Use `SynapseMutations.refresh()` for safe reconciliation. Small row actions
   stay in their current URL context; create/modal owners respect the validated
   server-selected URL. Keep lifecycle cleanup and widget reinitialization.
7. Test two dirty forms saving sequentially, hidden-tab drafts, selected submitter,
   validation errors/CSRF rotation, canceled confirmation, duplicate submission,
   saved-write/failed-read, GET-only retry and auth/scope fallback. Assert that no
   retry duplicates a business write.

## Focused tests and validation limits

Run commands from the repository root. Browser fixtures require Node and the
Playwright/browser installation used by these tests (the modal and clinical
fixtures load `writable/widget-test-tools/node_modules/playwright`). They use
synthetic local HTTP fixtures, not operational patient/device data.

```sh
node --test tests/browser/background-actions.test.cjs tests/browser/modal-background.test.cjs tests/browser/clinical-background.test.cjs
node --test tests/browser/toast.test.cjs tests/browser/navigation.test.cjs
```

- `tests/browser/background-actions.test.cjs` exercises the real production
  mutation, confirmation and navigation scripts together: current URL/scroll,
  authoritative GET, duplicate protection, two-form deadlock regression,
  unsafe destinations, sticky GET retry and security-scope hard fallback.
- `tests/browser/modal-background.test.cjs` covers generic, carousel, availability
  and BMG save owners, modal teardown, frozen controls, unrelated edits,
  server-selected destinations and no-helper fallback. Its navigation API is a
  fixture stub, not the real navigation implementation.
- `tests/browser/clinical-background.test.cjs` covers chart/guidance state and
  dispense draft ownership/idempotency-field preservation with isolated fixtures
  and a navigation stub. It does not prove real stock transactions or persistence.
- `tests/browser/toast.test.cjs` and `tests/browser/navigation.test.cjs` provide
  adjacent shared-feedback/navigation coverage; they are separate from the three
  mutation-focused suites.

Response-only PHP contracts can be checked individually without claiming to run
business mutations:

```sh
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/ContentAdminBackgroundResponseTest.php
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/AppointmentReferralBackgroundResponseTest.php
php vendor/bin/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/ClinicalBackgroundTest.php
```

These PHP fixtures isolate response helpers/controller construction; they are
not end-to-end authorization, database persistence or flash-storage integration
coverage. Consult [TESTING.md](TESTING.md) before any database-backed validation;
do not run an unfiltered historical suite against a shared/operational schema.

## Validation recorded for this rollout

Focused suites passed on their latest runs:

| Suite | Tests |
| --- | ---: |
| `toast.test.cjs` | 7 |
| `background-actions.test.cjs` (real navigation integration) | 16 |
| `modal-background.test.cjs` (including teardown reentry and frozen-input regressions) | 22 |
| `clinical-background.test.cjs` | 11 |
| `carousel-mobile.test.cjs` | 28 |
| `queue-background.test.cjs` | 10 |
| Existing `queue-walkin.test.cjs` | 11 |

Final validation ran all six focused browser suites together, sequentially:
**94 tests passed** in approximately 179 seconds. The existing queue-walkin suite
also passed separately (11 tests).

PHP contract suites `ContentAdminBackgroundResponseTest`,
`AppointmentReferralBackgroundResponseTest`, `ClinicalBackgroundTest`,
`CarouselReorderTest`, and `QueueBackgroundTest` passed together:
**181 tests, 1,518 assertions**. JavaScript syntax checks, the checked PHP layouts,
and scoped whitespace checks also passed.

### Follow-up regression closure

The previously reported failures were reproduced and resolved without relaxing
production permissions, replay protection, migration preflights or test assertions:

- Appointment slot fixtures supplied the correct API schema; stale locators looked
  for `09:00` while the accessible button now displays `9:00 AM`.
- Realtime ticket renewal was wrongly counted as a form save. The isolated modal
  fixture now disables this unrelated backend, rather than tolerating extra saves.
- Refresh checks now wait for replacement of the actual main content, not just an
  already-matching URL. Native enrollment still asserts exactly one non-XHR POST.
- The cold guidance registry fixture now supplies its required real CI pager;
  HTTP/full-document assertions expose rendering errors before Alpine boot waits.
- Invalid BMG redirects are tested on independently GET-loaded editors. Confirmed
  forms remain locked, and late submissions explicitly cannot replay a POST.
- Carousel upload tests render the actual PHP partial and assert the current
  committed/inert/locked state after POST completion, including no late replay.
- The carousel CSS probe uses its real scoped container, and the navigation timeout
  fixture injects its delay once so GET-only Retry is tested independently.
- Guidance JSON safety assertions include the required current CSRF metadata.
- The guarded guidance service fixture creates a linked synthetic counsellor,
  authenticates through current DB-backed authority and grants only the permission
  required by the tested operation. An unauthenticated denial regression remains.

Completed browser validation now covers **494 distinct tests across 25 suites**:

| Group | Distinct tests |
| --- | ---: |
| Six focused background/feedback suites above | 94 |
| Modal forms | 87 |
| Navigation and availability exceptions | 23 |
| Search, sidebar, mobile shell, confirmation, CSRF and loading | 137 |
| BMG CRUD/operations, encounter stepper, New Case and queue walk-in | 89 |
| Availability filters, carousel uploads, live filters/tables and profile settings | 64 |

The expensive modal and BMG files passed in completed, explicit-name batches
covering every test in those files; this is not a claim that their earlier timed-out
whole-file runs passed. No individual test timeouts were increased and no failure
assertions were removed.

The expanded safe PHP baseline is green: **583 tests, 3,265 assertions**; the
181 background contracts are included automatically in `phpunit.ci.xml`.
The documented guarded fresh-schema service command is also green: **25 tests,
147 assertions**, plus **4 Python runner-safety tests** and **4 Node queue tests**.
The database runner used only its own random disposable schema, verified both
connections, completed migrations, and left no `humus_fresh_` schemas behind.

This certifies the named regression and CI validation scope, not the unfiltered
historical suite or every live integration. No operational database, real clinical
or stock records, deployment keys, or hardware operations were used.
