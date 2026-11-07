# Mobile Team Workspace implementation tracker

Last updated: 2026-11-07

This is the durable implementation and release checklist for the dual-context Synapse mobile experience.

## Product contract

- [x] Personal navigation is the default after normal sign-in.
- [x] Authorized layout: `Home | Visits | Work | Queue | Profile`.
- [x] Workspace layout: `Team Board | My Tasks | Personal | Team Chat | Team Calendar`.
- [x] The center control is a context-switch button, not a route.
- [x] Users without Team access retain the existing four personal tabs.
- [x] Multi-unit users choose Clinic, Guidance, or BMG Facilities before first Workspace entry and may switch units later.
- [x] Workspace unit/drafts remain in session memory only.
- [x] Team Board uses a grouped native list rather than drag-and-drop Kanban.
- [x] Full authorized task, calendar, and chat collaboration is in scope.
- [x] Attachments, direct messages, presence, clinical links, recurring events, and offline mutation queues are out of scope.

## Phase 0 — security and transport foundation

- [x] Reset process-static tenant context before and after every request, including rejected/public requests.
- [x] Make refresh-token rotation transactionally single-use under concurrent requests.
- [x] Align API/push token user identifiers and tenant-safe references through a forward migration where required.
- [x] Add transport-neutral Team actor/membership authority for bearer and web requests.
- [x] Revalidate and lock the actor account inside Team write transactions.
- [ ] Add bounded idempotency receipts for mobile task creation, event creation, and task comments.
- [ ] Normalize nested JSON DTOs explicitly and reject unknown API fields.
- [x] Remove `auth_version` from mobile identity projections.
- [ ] Add per-user/unit write and search budgets.

## Phase 1 — entitlements and bearer Teams API

- [x] Add additive Personal/Work entitlements to `GET /api/v1/me`.
- [x] Add server-side Workspace rollout gating.
- [x] Make fresh sign-in and cold restore use the same authoritative `/me` bootstrap.
- [x] Add Team context/member endpoints.
- [x] Add task list/detail/create/update/status/comment endpoints and sanitized presenters.
- [x] Add calendar range/detail/create/update/cancel endpoints and sanitized presenters.
- [x] Add chat cursor/task-search/send/read endpoints and sanitized presenters.
- [x] Keep all responses bearer-only, tenant/unit scoped, and `private, no-store`.

## Phase 2 — mobile context shell

- [x] Add in-memory Workspace context keyed by tenant/user.
- [x] Add independent Personal and Workspace navigator trees.
- [x] Add the shared custom tab bar and authorized center CTA.
- [x] Add single-unit direct entry and accessible multi-unit picker.
- [x] Add active-unit switcher and actual dirty-draft reset/submission guards across real Workspace forms.
- [x] Implement Android Back, Reduce Motion, safe-area, and accessibility behavior.
- [x] Purge denied record/query data and reset genuinely revoked context/unit scopes without discarding still-authorized drafts.

## Phase 3 — Team Board and My Tasks

- [x] Add unit/user-scoped query keys, API client, and focused polling.
- [x] Add grouped task lists, filters, counts, and empty/stale/forbidden states.
- [x] Add task detail, checklist, comments, and activity presentation.
- [x] Add authorized create/edit/reassign/status/comment flows.
- [x] Add pessimistic mutation and stale-version review behavior.

## Phase 4 — Team Calendar

- [x] Add Agenda, Week, and compact Month views.
- [x] Add event/task-deadline details and task navigation.
- [x] Add event create/edit/cancel and participant search.
- [x] Add Manila-safe native date/time selection and serialization.
- [x] Add truncation, stale, forbidden, and conflict states.

## Phase 5 — Team Chat

- [x] Add secure UUID-v4 client tokens.
- [x] Add cursor history, historical target mode, and Show Latest.
- [x] Add send/retry, reply, mentions, and task references.
- [x] Advance the monotonic read cursor only for fully visible messages.
- [x] Keep drafts and unresolved nonce state in memory only.
- [x] Add keyboard, safe-area, accessibility, and long-list behavior.

## Phase 6 — realtime, navigation intents, and push

- [x] Publish payload-minimal task and calendar invalidation hints after commit.
- [x] Enable unit-scoped task/calendar/chat invalidation on mobile.
- [x] Add Team task/event/chat-message notification targets.
- [x] Add one entitlement-aware navigation-intent coordinator for inbox and push taps.
- [x] Queue cold-start intents until auth, `/me`, context, and navigation are ready.
- [x] Correct push recipient resolution and production producer wiring.
- [x] Add generic Team task/event/mention push templates only.
- [x] Capability-gate Team push for compatible app builds.
- [x] Bind push registrations to installation/session-family logout.

## Documentation and CI

- [x] Update `docs/MOBILE_API.md` with all Team DTOs, errors, timezones, and idempotency.
- [x] Update `docs/MOBILE.md` with the dual-context production scope.
- [x] Correct Team chat search-limit and notification wording in `docs/TEAMS.md`.
- [x] Add bearer Team API, migration, and concurrency suites to backend CI.
- [x] Keep the complete mobile type/lint/Jest gates in CI.

## Verification evidence

### Backend

- [x] TeamAccess unit tests.
- [x] Clinic, Guidance, and Facilities bearer authorization tests.
- [x] Cross-tenant/unit non-disclosure tests.
- [x] Cookie/bearer separation and no-CSRF bearer-write tests.
- [x] Disposable-schema migration/idempotency/notification tests.
- [x] Multi-connection refresh, version, nonce, and revocation race tests.
- [x] Existing web task/calendar/chat PHP regressions.
- [ ] Existing web Team browser regressions (not run in this pass; PHP/HTTP regressions passed).

### Mobile

- [x] TypeScript passes.
- [x] ESLint passes.
- [x] Focused Workspace Jest suites pass.
- [x] Full current-pass serialized Jest passes: **55 suites / 957 tests**, including `--detectOpenHandles`, with no reported open handles or test warnings. Historical pre-parity gate: 43 suites / 711 tests.
- [x] Android debug build passes; current-bundle/native evidence is recorded separately below.
- [ ] iOS/Pods build passes where the host permits (not run on Windows).
- [x] No Team content is persisted in AsyncStorage.
- [x] Named-unit realtime does not refetch unrelated units.
- [x] Revoked and malformed notification/push targets fail closed.

### Staging/device acceptance

Verified on the connected Android device (2026-11-07, Test Superadmin, debug build via Metro):

- [x] Personal-first session with `Home | Visits | Work | Queue | Profile` and “Switch to Team Workspace”.
- [x] Workspace bar `Team Board | My Tasks | Personal | Team Chat | Team Calendar` with “Switch to Personal Portal”.
- [x] Multi-unit picker (Clinic, Guidance, BMG Facilities with Manager access labels).
- [x] Both switch directions; context and state retention across switches and theme changes.
- [x] Team Board grouped list/filters/counts and task card rendering (read-only; no server mutation).
- [x] My Tasks scope, Team Chat history/day separators/mentions/task references, Team Calendar Agenda/Week/Month with scopes and empty state.
- [x] Dark and light mode for Workspace and Personal surfaces; preference restored afterward.
- [x] Fail-closed no-switcher state while rollout disabled (verified before enabling the local flag).
- [x] Additional named accounts (Clinic/Guidance/BMG-only), font scaling, live revocation, and real push delivery were identified for full staging sign-off; see the historical Task 13 record below.

### Task 13 — full staging sign-off (2026-11-07, connected device + local development stack)

- [x] **Per-unit account:** `test.guidance.admin` signed in on the device → single-unit direct entry (no picker), Team Board/Team Chat scoped to Guidance only. The documented local credential convention was verified against existing account hashes without exposing or resetting them.
- [x] **Font scaling:** device font size raised to Large (1.35) through the phone's own Settings UI (ADB write was permission-blocked, so no permission grants were made). Two real large-text defects were found and fixed in code: Calendar status-filter truncation (filters now wrap to full-width rows) and Workspace tab-label truncation (labels now wrap to two centered lines); the Board search field was widened with a visible placeholder. Full mobile suite re-passed (43 suites / 711 tests). Device font restored to its original Small setting afterward.
- [x] **Live revocation:** the Guidance administrator role was revoked through the authorized web workflow (superadmin → Employees & Access, reason recorded in the audit ledger). The signed-in device showed "Your session has expired" within seconds with no cached Workspace data rendered. The role was then restored through the same workflow and re-sign-in on the device regained the Guidance Workspace. Only the synthetic fixture account was touched; the batch reset command was not used.
- [x] **Real push delivery:** the staging smoke test caught and fixed a real regression (`PushTransport::sendToDevices` was missing its `$tenant` parameter after the per-device capability refactor; push suites re-passed 14/57). With `push.enabled` temporarily true, `php spark push:test 1 6` returned `delivered=true` and the OS notification shade displayed "SYNAPSE staging check / Live push verification to the connected device." `push.enabled` was restored to false immediately after.
- [ ] **Team push enablement remains a deployment gate:** the local development database has not had `2026-11-06-001100_AddPushInstallationCapabilities` applied, so `push_tokens` still lacks `installation_id` / `api_family_id` / `team_workspace_capable` and Team-namespace push correctly fails closed. Personal push (the path verified above) works on the current schema. Applying that migration to any shared environment still requires explicit deployment approval, backup, and preflight.

## Interaction parity pass — 2026-11-07

This pass targets the **native production `ContextTabs` tree**, not the `mobileScreenReference`
prototype or legacy `AppTabs.tsx`. The historical staging records below are retained; they are not
fresh proof of every changed screen. No operational migrations, rollout changes, credential resets,
server-side test messages, event/task writes, deployments, commits, or pushes were made in this pass.

### Screen-by-screen implementation and action checklist

| Surface | Implemented behavior |
|---|---|
| Shared controls | Stable loading slots, disabled/missing-handler states, 44 px targets, theme-safe foregrounds, accessible field labels/errors and fixed headers. |
| Context/tab navigation | Shared 240 ms cubic-out opaque, current-width motion; live Reduce Motion; center press/accepted-switch feedback; duplicate-tap and overlay/submission locks; keyboard-hidden bar; independent same-unit history; unit-change remount; disabled feature tabs omitted. |
| Unit/account overlays | Retained serialized native presenters, coordinated entry/exit, cancellation/Back/backdrop handling, handle-only sheet drag, bounded body and safe areas. Required selection is non-dismissible. Account/Notifications are available without Personal routes; single-unit accounts have no meaningless switch action. |
| Board / My Tasks | Real search submit/clear, status/priority selection, reset, pagination and refreshable empty states. Distinct manual and quiet refresh feedback, identity/unit-safe previous rows, existing assignment semantics preserved. |
| Task details | `can_edit`-gated versioned status/checklist actions; comments, immutable ambiguous retry and conflict review; actual draft reset and post-dismiss continuation. Inline comment visibility with the keyboard is a device acceptance gate, not inferred from JSX. |
| Task create/edit | Stable client-only checklist IDs stripped from wire payloads; unadded text included in draft state; pinned actions; native optional Manila due date; scoped paged member search; stable initial snapshot/version and comprehensive pending locks. |
| Calendar home | Agenda/Week/Month, Team/Mine/status/range controls, deadline task navigation with My Tasks fallback, honest capped-result recovery, rolling Manila midnight/foreground date and preserved browsed period. |
| Event details | Organizer/manager edit/cancel authority unchanged; shared cancellation dialog, exact reviewed-version retry, conflict review and exit-cycle locks. |
| Event create/edit | **All authorized Team members can create**, matching the existing API/web contract. Stable snapshot/version, participant paging/selection, native Manila date/time, full field/header locks and explicit immutable nonce retry. |
| Chat | Serialized physical request queue; latest/history/around navigation without replacing browsed history; real task/reply links; selected mention ranges preserved correctly; stable rows and isolated freshness clock; actual visible-bottom read eligibility. |
| Chat task picker/composer | Shared searchable sheet; scoped/refinable result limits, clear/retry and selected state; multiline Enter remains newline; exact-payload send retry, no automatic resend; safe same-unit task pushes retain composer. |
| Notifications / account | Shared Workspace entry points, current-access delayed target checks and visible unavailable feedback, account settings/appearance/privacy without Personal records access, retained sign-out confirmation. |
| Directly affected Personal flows | Booking/cancellation join context draft/submission guards; Settings/Privacy use the shared sign-out dialog. This is **not** a Personal redesign: the existing Survey native discard alert and notification empty-state/background-spinner polish remain outside this pass. |

### Freshness and delivery safeguards

- Keep 30-second Tasks/Calendar and 5-second latest Chat fallbacks plus existing realtime hints and
  120-second reconciliation. Team automatic reads require eligible focus, foreground and connectivity,
  and pause beneath overlays; LAN operation does not require public Internet reachability.
- Task writes/hints invalidate same-unit Tasks, Calendar deadlines and Chat task-picker prefixes,
  never the entire browsed Chat window merely for task metadata.
- Background reads cannot overwrite form drafts or silently replace their reviewed version.
  Acknowledged writes remain acknowledged if later cache reconciliation fails.
- Mutations are not retried automatically or parked for reconnect. Explicit ambiguous retries retain
  the original payload and nonce/version; discarding a local request cannot undo server delivery.
- Read-scope denial and record-edit denial remain distinct. Same-identity `/me` refresh retains the
  signed-in tree; late unit/session responses and deferred targets must not resurrect old access.
  Registrations are stamped with identity and originating Personal/Work-unit scope; revoking an
  inactive unit cannot reset unrelated authorized drafts or unlock their pending submissions.
- Real-cache regression caught a same-turn sign-out event race; `/me` cache reconciliation now
  captures the session-operation version, so an old event cannot restore the cleared profile before
  React commits sign-out. Personal root route availability follows the current entitlement.

### Current-pass verification

**Final complete gate:** `npm test -- --runInBand --detectOpenHandles` passed **55 suites / 957 tests**
in 77.37 seconds, exit **0**, with no reported open handles, `act` warnings or skipped tests. This run
included the real root/cache tests and all final keyboard, scope, navigation and Chat styling changes;
no bundle compilation or other agent test run competed with it. TypeScript, ESLint (zero errors,
four existing `secureNonce` warnings), tooling **20/20**, and whitespace checks also passed.

- Completed focused gates before the complete final run: Tasks **73**, Calendar **69**, Chat **43**,
  shared overlay/lifecycle/keyboard **46**, Workspace scope lifecycle **21**, delayed navigation
  intents **14**, real auth-cache **8**, and actual mounted root-access **6** tests. These are subsets,
  not extra counts to add to the complete Jest total. Root access tests keep the real production root,
  container/native-stack/router and minimal nested Work draft probes; they verify removal of already-
  open Personal routes without remounting the retained Work state.
- An intermediate complete run passed **52 suites / 908 tests**. A later handle-diagnostic run under
  concurrent bundle compilation timed out in one Calendar test and cascaded to subsequent renderer
  assertions (**51 suites passed, 1 failed; 898 passed / 15 failed**). The isolated Calendar diagnostic
  passed **69/69** at the unchanged 5-second timeout; its test wait helper now commits promise-driven
  renders before advancing native dismissal frames. The subsequent saved-regression run excluding
  only the then-active root test harness passed **54 suites / 951 tests**; root/cache focused tests
  separately passed **14/14**. Final complete diagnostic results supersede these intermediate runs.
- TypeScript passes and ESLint reports only the four existing `secureNonce.ts` no-bitwise warnings;
  no lint errors. Developer-tooling tests pass **20/20**. Whitespace checks pass.
- Android `assembleDebug` succeeded earlier in the pass, **without installation**. Current Android
  JavaScript also compiled successfully through a separate loopback-only Metro preview. These prove
  build/bundle validity, not production frame rate or current iOS behavior.
- Targeted backend contracts passed on PHP **8.3.36** / PHPUnit **10.5.66**: **11 tests / 240 assertions**
  across `TeamApiHttpTest` and `TeamAccessTest`, using the existing no-dotenv bootstrap and the tests'
  private SQLite `:memory:` fixtures. No unfiltered operational database suite ran.

### Native checks and remaining limits

The existing **scrcpy** session on the connected **RMX3286** phone is the native target. No emulator
is used. The installed app/account is preserved, and task/comment/event/message submissions,
status/checklist changes, cancellation and sign-out are not performed for this pass's visual smoke
checks. Viewing Chat can still trigger its normal read acknowledgements; those are not message sends.

- [x] Current-code Team Board root header, filters/counts and card; My Tasks empty scope with real
  Create/Refresh actions; context switch visible on the connected device.
- [x] Search keyboard hides the tab bar; Android Back closes it **without returning to Personal**.
- [x] Existing Task detail/status/checklist/comment presentation; unsent local comment preserved by
  Keep editing, reset by Discard and continue; navigation waits until the dialog actually closes.
- [x] Task creation opens without any server write; pinned actions present with the keyboard closed.
- [x] Device-confirmed low-page comment and fixed Task footer defects corrected: after a full
  current-bundle reload, the focused comment/inline Add comment action and New Task Description/
  Create Task/Cancel actions remain above the native keyboard. Shared geometry compensates only
  actual overlap; no comment/task/event submission was performed.
- [x] Current-code Chat composer and task-reference button remain visible above the keyboard after
  a full reload; tab bar hides and the composer stays unsent. Chat task-sheet/Calendar checks follow.
- [ ] Full native sheet/Calendar smoke, long member lists, native date/time picker transitions and
  cancellation-only dialogs. The Chat task-sheet open attempt was not conclusively captured; screen
  capture timed out and the phone later displayed its app drawer, so native input was stopped rather
  than relaunching or changing the user's device state.
- [ ] iOS native build/dismissal/safe-area behavior, TalkBack/VoiceOver focus, maximum font scale,
  live Reduce Motion and measured release-device animation performance.
- [ ] Complete role/unit/revocation/ambiguous-response matrix and external push on disposable native
  data. Socket-free synthetic fixture tests do not prove native acceptance or real backend behavior.

The original Metro process on 8083 was inspected but not killed/restarted. A separate loopback
preview on **18083** and an additive USB reverse mapping were used for the earlier inspection.
That preview was later stopped; this paragraph is historical evidence, not the current setup.
The subsequent crash investigation verified the installed APK's Metro default is **8083**, no saved
`debug_http_host` override file was present, and USB mappings include 8080/8081/8083. Keep 8083
canonical; the stopped preview is not required. No user-owned server was restarted for the repair.

## Display refresh and transport-priority correction — 2026-11-07

The earlier parity pass interpreted “screen refresh rates” as data polling. The clarified requirement
is **native display refresh support** (60/90/120 Hz and adaptive panels), separately from data delivery.
No connected device or emulator is used for this correction; physical acceptance remains pending.

- Android foreground main and RN Modal windows request high refresh through supported native APIs,
  without hard-coded 120 Hz, display-mode IDs, resolution changes or a JavaScript frame loop. The
  system remains authoritative over panel capabilities, battery saver, thermal and user limits.
- iOS already contains the ProMotion opt-in. This Windows implementation does not claim a fresh iOS
  build or sustained-FPS verification. Native Android changes require rebuilding/installing the app.
- Polling remains the default when realtime is disabled or not healthy. Only the authenticated
  private socket's server `ready` state pauses short Tasks/Calendar/Chat/notification/queue polling.
  Initial/manual HTTP reads and hint invalidation remain active. Close, heartbeat timeout, renewal
  and lifetime cleanup restore eligible fallback timers; no mutation is automatically replayed.
- Connection readiness is QueryClient/identity/lifetime scoped. Public carousel readiness is separate.
  Slow 120-second repair stays in both modes, now including staff queue snapshots because fanout is
  bounded. Dashboard `/me` has no profile hint and keeps its independent polling.
- Added regression coverage exercises authenticated readiness, timeout/renewal/close fallback,
  stale leases, actual hook fetch counts, notification eligibility and retained Chat windows.

### Correction-pass verification

- `npm test -- --runInBand --detectOpenHandles`: **56 suites / 974 tests passed**, with no reported
  open handles. The staff queue hook test now mocks the realtime-aware polling boundary and verifies
  that pausing its interval does not disable the query.
- `npx tsc --noEmit`: passed. `npm run lint`: **0 errors**, with the four existing `secureNonce`
  `no-bitwise` warnings unchanged.
- Developer-tooling tests: **20/20 passed**. Native refresh-rate policy tests: **9/9 passed**, including
  compilation and execution of the production Java selector.
- Android `:app:assembleDebug`: passed as a build-only check. No installation, device/emulator
  interaction, server writes, commits or pushes were performed for this correction.

See [display-rate acceptance](MOBILE.md#display-refresh-rate-acceptance) for the later device checklist.
A passing build, selector test or display-Hz overlay is not evidence of sustained rendered FPS.

## Floating context CTA redesign — 2026-11-07

The prior switcher was a 40×32 pill in each context's tab row, so both its shape and screen-like
sliding behavior were incorrect. The production bar now lives once at the outer `ContextTabs`
level, outside animated context scenes; inner navigator bindings retain real tab events, selection
and independent same-unit histories.

- The CTA is a fixed 56×56 circle in a 64×64 separation ring with native elevation/shadow, raised
  22px above the bar. The caption is outside the disc; safe-area and larger-text layout cannot
  stretch it. The raised hit area stays inside an unclipped host, separate from the clipped reveal.
- A native-driver center-out maroon reveal transforms the full bar for Work. Tab icons/labels and
  contrasting CTA content follow in a coordinated 400 ms sequence; returning to Personal reverses
  it. Only screen content uses the existing 240 ms slide—never the shared bar or center button.
- Accepted context changes drive the reveal. Picker opening, cancelled discard, pending submissions
  and overlay exit cannot bypass existing guards or prematurely animate a switch. Obsolete route
  handlers and binding cleanup cannot activate the wrong context; rapid reversal starts at current
  animation progress, and live Reduce Motion settles immediately.
- The keyboard hides the entire floating bar without a leftover gap. Work-only and Personal-only
  accounts have no CTA or center slot, and disabled feature routes remain omitted.
- The high-refresh and realtime-first correction is unchanged. No device/emulator, server mutation,
  app installation, user-owned process termination, commit or push was used for this redesign.

### CTA redesign verification

- Complete serialized Jest gate: **58 suites / 1,010 tests passed** with `--detectOpenHandles`, no
  reported open handles and no skipped tests. Focused bar/navigation coverage passed **3 suites /
  51 tests**: actual Animated.Value progress, circle/anchor geometry, reveal/caption sequencing,
  reversal, reduced motion, keyboard/stale handlers, scoped bindings and real nested router history.
- TypeScript passed. ESLint reported **0 errors**, with the four existing `secureNonce` warnings.
  Developer-tooling tests passed **20/20**, native selector tests passed **9/9**, and whitespace
  checks passed.
- Android `:app:assembleDebug` passed (**2m 15s**, build only). Current Android JavaScript bundle
  compilation also passed using standalone output outside the repository, without starting or
  changing a Metro server. These checks are not frame-performance measurements.

See [floating switcher acceptance](MOBILE.md#floating-context-switcher-acceptance) for native visual,
touch, safe-area, font-scale, accessibility and smoothness checks. Those checks remain pending;
unit tests and a debug build alone cannot establish native visual acceptance.

## Native startup repair and device confirmation — 2026-11-07

The connected API 33 Android phone repeatedly crashed before rendering React Native. Crash logs
identified `NoClassDefFoundError: SupportedRefreshRatePolicy$Mode` from the legacy window-rate
path in `WindowRefreshRatePolicy.update()`. The installed APK matched the local build, but Android
Java compilation had emitted only generated classes; neither selector class was defined in DEX.
Standalone JVM selector tests explicitly compile the source and therefore missed this packaging gap.
The noncanonical `com/com.humus.synapse` directory was replaced with `com/humus/synapse` for all
production native sources and the Java test, without changing package names or app identity.

- The corrected build ran Java compilation and produced both selector classes. Android
  `:app:assembleDebug -PreactNativeDevServerPort=8083` passed; the APK class-definition gate verified
  all five required application/policy classes across 19 DEX files. The same gate rejects the old
  broken APK, including the case where the missing class exists only as a referenced descriptor.
- Added seven packaging-parser regression tests and a canonical native package-path contract.
  Final tooling/parser checks passed **27/27**; native policy checks passed **10/10**. The mobile CI
  now includes a separate device-free debug build and required APK class-definition verification.
- With explicit approval, the debug app was updated **in place** on the connected phone. No
  uninstall, data clear, credential edit, login submission, account change or server mutation was
  performed. Installed/corrected APK hashes matched, the process stayed resumed without a new
  crash, and the React Native sign-in screen was observed. Metro 8083 and the backend 8080 passed
  bounded unauthenticated diagnostics; no user-owned server was stopped or restarted.
- The user subsequently confirmed the app is running correctly and the navigation/switcher match
  the intended design. This is physical-device startup and visual-design confirmation, not the full
  accessibility, multi-device/modal/display matrix, iOS acceptance or sustained-FPS profiling.

## Pre-merge review corrections — 2026-11-08

The final review found and corrected three freshness/navigation defects and one native ordering
edge case before committing:

- Socket hints overlapping an active HTTP read now preserve their targets for one trailing read.
  Stopping the socket lifetime prevents further drains; disabled observers do not acquire automatic
  reads. A lone-event regression verifies the older response cannot silently consume the hint.
- Chat re-arms only its disabled invalidation signal on consumption. Failed reads keep the displayed
  messages, error and freshness timestamp unchanged; a later hint or the actual 120-second repair
  can retry. The recovery test keeps heartbeat pongs active to prove polling fallback is not masking
  the repair. Healthy steady-state short polling remains off, and no mutation replay was added.
- The Work route now has an active-unit-scoped navigation key. Same-feature unit switches and unit
  revocation reset the parent-owned Work stack rather than rehydrate an old unit's detail ID. The
  new regressions failed before the fix and verify Personal nested history/local state and the
  persistent shared bar remain intact afterward.
- Configuration changes perform one coalesced native UI-queue recheck after ViewRoot updates its
  display. Pause/detach/destroy cancel pending work; no frame-production loop or power override was
  introduced. Full external-display moves, including same-configuration moves, remain a native
  acceptance limit.

- The pre-commit hook flagged fixed synthetic token strings in the existing mocked session-client
  test. Those fixtures now use per-test generated values; all 36 session race/revocation cases
  remain intact. No real credential or client authorization behavior changed.

Targeted realtime/Chat gates passed **123 tests** and navigation gates passed **53 tests**. Native
policy checks passed **11/11**, tooling/parser checks passed **27/27**, TypeScript passed and ESLint
retained only the four existing nonce warnings. The new hosted Android packaging CI job has not yet
run; local build/class-definition checks are separate evidence, not a claim of hosted CI success.

**Final post-review gate:** the complete serialized Jest run with `--detectOpenHandles` passed
**58 suites / 1,018 tests**, with no reported open handles or skipped tests. The final reviewed
Android debug build passed and the APK gate verified all five required class definitions in 19 DEX
files. This build was not reinstalled during the review/commit step; the earlier repaired build is
the user-confirmed device evidence. No device/account/server actions were performed in this review.

The ignore review found existing rules already exclude local APK/build/cache output, environment
files, keystores, Firebase configuration and crash logs; no ignore-rule change was necessary.

## Deployment record

- Workspace rollout state: **disabled until implementation and acceptance gates pass**.
- Team OS push state: **disabled until compatible native destinations are deployed and staging-verified**.
- Schema migrations applied to operational environments: **none as part of implementation work**.
- Rollback rule: disabling Workspace must never delete Team tasks, comments, activity, events, participants, messages, read cursors, or notification history.
