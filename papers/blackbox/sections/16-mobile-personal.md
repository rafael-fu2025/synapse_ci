# 16 — Mobile personal application

## MB-001 — Student sign-in bootstraps authoritative profile
- Lifecycle: `READY`
- Legacy IDs: `MB-001`
- Changed by: `bea71cf`, `39f7339`
- Requirement: Mobile sign-in must share the web credential/rate policy, receive opaque one-time tokens, store only refresh credential in platform keychain binding, fetch `/me` as authoritative identity/entitlements, and restore across restart without retaining password.
- Surface: React Native sign-in/splash/home.
- Route/API: `POST /api/v1/auth/login`; `GET /api/v1/me`.
- Actor/grants: Active student account with linked person.
- Preconditions: Disposable student account, clean app storage, known server profile; no production credentials.

**Steps**
1. Enter valid synthetic credentials and sign in; observe loading then personal navigation and `/me` identity/entitlements.
2. Force-close/restart the app and verify silent restore using keychain refresh plus authoritative `/me`.
3. Inspect app storage/network and try invalid credentials/rate-bound attempts.

- Expected: Valid sign-in opens the correct account, restore works, password is not stored, errors/rate limits are bounded, and login response does not substitute for `/me`.
- Negative/non-disclosure: Tokens/password are absent from logs/screenshots/cache; hidden server control remains unavailable through ordinary login interaction; no staff workspace appears without entitlement.
- Cleanup: Sign out, clear keychain/application data, and retire account.
- Source: `app/Controllers/Api/V1/MobileAuthController.php:16-107`; `mobile/src/store/auth.tsx:108-197`
- Evidence: Redacted screen recording, endpoint sequence, keychain/storage property check, and restart proof.
- Verdict: `NOT_RUN`

## MB-002 — Concurrent 401s share one silent refresh
- Lifecycle: `READY`
- Legacy IDs: `MB-002`
- Changed by: `bea71cf`, `73b8a11`
- Requirement: On expired access token, authenticated requests in one active runtime must share a single refresh, rotate credentials atomically, retry each request at most once, and avoid user prompt when successful.
- Surface: Mobile API client and any personal screen.
- Route/API: `POST /api/v1/auth/refresh` plus original resource GETs.
- Actor/grants: Signed-in mobile user.
- Preconditions: Valid refresh token, deliberately expired access token, multiple simultaneous screen queries.

**Steps**
1. Trigger three authorized requests concurrently after access expiry and capture network chronology.
2. Verify one refresh request, one successor credential save, and one retry per original request.
3. Repeat with refresh network failure and then invalid refresh.

- Expected: Successful refresh is single-flight and transparent; transient failure surfaces recoverable network state without duplicate rotation; invalid refresh expires/clears session and requires sign-in.
- Negative/non-disclosure: Old/new token values are not logged; requests do not cross runtime/account; no infinite retry or duplicate mutation occurs.
- Cleanup: Sign out/clear keychain and retire token family.
- Source: `mobile/src/api/client.ts:270-333`; `app/Services/Api/TokenService.php:123-204`
- Evidence: Redacted network request count/timeline and session UI outcomes.
- Verdict: `NOT_RUN`

## MB-003 — Refresh replay revokes the entire token family
- Lifecycle: `READY`
- Legacy IDs: `MB-003`
- Changed by: `bea71cf`
- Requirement: Refresh credentials are single-use; replay, expiry, or a losing concurrent rotation must revoke every successor in the same family so no family token can serve data.
- Surface: Mobile token lifecycle.
- Route/API: `POST /api/v1/auth/refresh`; protected API GET.
- Actor/grants: Synthetic mobile account with captured test refresh token.
- Preconditions: Disposable token family; capture occurs only in secure test harness and never in artifacts.

**Steps**
1. Exchange the refresh token once and verify the successor works.
2. Replay the original token, then try the successor access and refresh tokens against protected endpoints.
3. Run a concurrent double-refresh variant and test role/auth-version change before refresh.

- Expected: Replay/losing concurrent request returns `401`, family is revoked, successors cannot serve/refresh, and auth-version change revokes all user families.
- Negative/non-disclosure: Responses do not reveal family ID/hash, token existence, role details, or data; raw tokens never enter evidence.
- Cleanup: Revoke all synthetic user tokens and destroy harness secrets.
- Source: `app/Services/Api/TokenService.php:19-23`; `app/Services/Api/TokenService.php:127-209`; `app/Services/Api/TokenService.php:212-249`
- Evidence: Run-local token aliases (never hashes or raw tokens), result timeline, and protected-resource denial matrix.
- Verdict: `NOT_RUN`

## MB-004 — Home dashboard shows only authorized personal summaries
- Lifecycle: `READY`
- Legacy IDs: `MB-004`
- Changed by: `bea71cf`, `e5b7eba`
- Requirement: Mobile home must compose the signed-in person’s appointments, queue, announcements, and other entitled summaries using account-bound queries, explicit loading/empty/error states, and no staff or foreign data.
- Surface: Mobile Home tab.
- Route/API: `GET /api/v1/me`, `/appointments`, `/queue`, `/announcements`, `/notifications` as used by the screen.
- Actor/grants: Linked student/employee with personal entitlement.
- Preconditions: Own fixture includes upcoming/past appointment, targeted announcement, queue ticket; second person has distinguishable records.

**Steps**
1. Open Home online and reconcile each card/count with own API results.
2. Exercise empty, slow, partial failure, background/foreground, and refresh states.
3. Switch account without reinstall and search screen/cache/network for the first account’s canaries.

- Expected: Home is responsive and converges to current own records; failures are bounded per screen; account switch clears old cache before new content.
- Negative/non-disclosure: No other person/tenant records, staff queues, hidden announcement, clinical notes, or old-account flash appears.
- Cleanup: Sign out both accounts and retire fixtures.
- Source: `mobile/src/features/dashboard/screens/DashboardScreen.tsx:1-339`; `mobile/src/store/auth.tsx:79-101`
- Evidence: State screenshots, own/foreign canary check, and API-to-card reconciliation.
- Verdict: `NOT_RUN`

## MB-005 — Appointment request and cancellation preserve request semantics
- Lifecycle: `DRAFT`
- Legacy IDs: `MB-005`
- Changed by: `bea71cf`, `b868a49`, `884b9f0`
- Requirement: Mobile self-service creates a `requested` appointment for the authenticated person using unit-specific type/date/Manila time validation, then may cancel only an own cancellable appointment with a reason; dual Guidance appointment interpretation remains unresolved.
- Surface: Mobile Appointments list/detail/booking/cancel.
- Route/API: `POST /api/v1/appointments`; `POST /api/v1/appointments/{id}/cancel`.
- Actor/grants: Linked user with personal appointment authority.
- Preconditions: Available synthetic service/date/time; second person’s appointment; decision record consulted for Guidance variant.

**Steps**
1. Book clinic and, if policy resolved, Guidance appointments; inspect returned/list/detail status, owner, type, and explicit Manila offsets.
2. Cancel the own request with a reason and verify list/detail/slot behavior.
3. Attempt invalid unit/type/date/time, occupied slot, another person’s ID, terminal appointment, and blank reason.

- Expected: Valid create returns `201` and truthful request state; valid cancel returns no content and updates only own row; all invalid/foreign variants reject atomically.
- Negative/non-disclosure: App does not claim immediate confirmation, accept provider/owner selectors, expose another person, or conflate two Guidance appointment systems.
- Cleanup: Cancel remaining synthetic requests and retire DB.
- Source: `app/Controllers/Api/V1/AppointmentsApiController.php:14-115`; `app/Controllers/Api/V1/AppointmentsApiController.php:123-151`
- Evidence: Request/list/detail/cancel progression, negative response matrix, and slot/row comparison.
- Verdict: `NOT_RUN`

## MB-006 — Queue board is an own-ticket projection
- Lifecycle: `READY`
- Legacy IDs: `MB-006`
- Changed by: `bea71cf`, `c91477a`
- Requirement: Queue tab must show only today’s active personal clinic/guidance tickets from server-derived bearer identity, with consistent status/position/wait data and realtime/poll repair.
- Surface: Mobile Queue tab.
- Route/API: `GET /api/v1/queue`; mobile realtime grant.
- Actor/grants: Linked user with own active ticket.
- Preconditions: Own ticket and distinguishable tickets for other people; staff session can advance queue.

**Steps**
1. Open Queue and reconcile own ticket fields with API snapshot.
2. Advance/skip/recall through staff web and observe mobile update without pull-to-refresh; repeat during socket outage.
3. Attempt identity/query selectors and switch to another account.

- Expected: Only own active tickets render; hint/poll updates converge; forged selectors do not alter owner; account switch clears old ticket immediately.
- Negative/non-disclosure: Other ticket codes, names, identities, priorities, or staff controls never appear.
- Cleanup: Restore/remove test tickets and sign out.
- Source: `app/Controllers/Api/V1/QueueApiController.php:11-20`; `mobile/src/features/queue/screens/QueueBoardScreen.tsx:1-159`
- Evidence: API/UI comparison, staff-action/mobile-update recording, outage repair trace, and account switch capture.
- Verdict: `NOT_RUN`

## MB-007 — Mobile records are a minimal health profile, not visit history
- Lifecycle: `DRAFT`
- Legacy IDs: `MB-007`
- Changed by: `bea71cf`, `b868a49`, `884b9f0`
- Requirement: Current mobile Records must expose only the authenticated linked person’s identity/profile and minimal health summary under `portal.records.read`; encounter/treatment/inventory detail stays workstation/web-only. Legacy expectation of visit history conflicts and awaits policy decision.
- Surface: Mobile Profile → Records/Health Profile.
- Route/API: `GET /api/v1/me/health-summary`.
- Actor/grants: Linked student/employee with `portal.records.read`; negative role without it.
- Preconditions: Own health profile and distinguishable own/foreign encounter records.

**Steps**
1. Open mobile Records and inventory fields returned/displayed for student and employee variants.
2. Compare with own health-summary API and search response/UI/cache for encounter, treatment, inventory, and foreign canaries.
3. Remove records permission, use an unlinked identity, and attempt ID/query manipulation.

- Expected: Only documented minimal own profile/health fields appear; no visit-history contract is implied; permission/unlinked variants return `403` and clear/withhold content.
- Negative/non-disclosure: No encounter notes, diagnoses, treatments, inventory, case records, other person/tenant, or arbitrary identifier lookup is exposed.
- Cleanup: Restore permission only if needed, sign out, retire DB.
- Source: `app/Controllers/Api/V1/MeController.php:51-103`; `mobile/src/features/profile/screens/RecordsScreen.tsx:1-161`
- Evidence: Field inventory, canary search, permission/identity response matrix, and policy-decision reference.
- Verdict: `NOT_RUN`

## MB-008 — Announcement targeting and read state match web authority
- Lifecycle: `READY`
- Legacy IDs: `MB-008`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Mobile announcement list/detail/read must use current targeted audience authority and same source/read marker as web, with bounded filters/paging and no hidden/draft/other-audience content.
- Surface: Mobile Announcements.
- Route/API: `GET /api/v1/announcements`; `GET /api/v1/announcements/{id}`; `POST /api/v1/announcements/{id}/read`.
- Actor/grants: Linked user in a known audience.
- Preconditions: Targeted/unrelated, read/unread, published/hidden fixtures.

**Steps**
1. Compare mobile list/counts/detail with the same user’s web portal and exercise filters/search/paging.
2. Mark one item read on mobile and verify web/mobile read state converges.
3. Directly request hidden/unrelated/other-tenant IDs and manipulate filters/page.

- Expected: Same authorized published items/read markers appear across surfaces; read is idempotent; unauthorized IDs are not disclosed; invalid query falls to bounded safe defaults.
- Negative/non-disclosure: Draft/archived audience internals, other recipients/readers, raw targeting data, and other tenant content are absent.
- Cleanup: Retire announcement/read fixtures.
- Source: `app/Controllers/Api/V1/AnnouncementsApiController.php:10-63`; `app/Services/AnnouncementService.php:250-346`
- Evidence: Web/mobile ID/read matrix, filter screenshots, and direct-ID denial responses.
- Verdict: `NOT_RUN`

## MB-009 — Survey submission is identified, single, and audience-scoped
- Lifecycle: `READY`
- Legacy IDs: `MB-009`
- Changed by: `bea71cf`, `b868a49`
- Requirement: Mobile surveys must list only currently available targeted forms, show server-defined questions, validate answers, create one identified response for the authenticated person, and then show submitted state consistent with web.
- Surface: Mobile Surveys list/form.
- Route/API: `GET /api/v1/surveys`; `GET /api/v1/surveys/{id}`; `POST /api/v1/surveys/{id}/submit`.
- Actor/grants: Linked eligible user.
- Preconditions: Published targeted survey with required/typed questions; closed/unrelated variants.

**Steps**
1. Open list/form and compare question IDs/types/options with server/web.
2. Submit valid answers once and verify returned response ID and submitted status across mobile/web.
3. Repeat submit and test missing/invalid answers, closed/unrelated survey, and forged person/respondent fields.

- Expected: Exactly one own response is recorded; validation is field-safe; repeat/closed/foreign requests reject; status/counts reconcile.
- Negative/non-disclosure: Another respondent’s answers/identity, aggregate moderation data, hidden questions, or caller-selected owner cannot be accessed.
- Cleanup: Retire survey response fixture DB.
- Source: `app/Controllers/Api/V1/SurveysApiController.php:10-55`; `mobile/src/features/surveys/screens/SurveyFormScreen.tsx:1-282`
- Evidence: Question/answer validation capture, row count/response ID, and web/mobile status comparison.
- Verdict: `NOT_RUN`

## MB-010 — Notification read state and deep links reconcile with server
- Lifecycle: `READY`
- Legacy IDs: `MB-010`
- Changed by: `bea71cf`, `c179273`
- Requirement: Mobile notifications must display the current own inbox/history, mark one/all read on server, reconcile via push/realtime/poll, and navigate only through authorized typed targets.
- Surface: Mobile notification banner/inbox.
- Route/API: `/api/v1/notifications*` and target resource APIs.
- Actor/grants: Signed-in user with synthetic notices.
- Preconditions: Read/unread supported target fixtures and one later-revoked target.

**Steps**
1. Open inbox, mark one read and then all read; compare server/web unread counts and timestamps.
2. Trigger a new notice with app foreground/background and exercise its deep link.
3. Revoke target authority, attempt foreign ID read, malformed target, and old-account push after switch.

- Expected: Read state is server-authoritative and converges; valid target opens exact authorized record; revoked/malformed/foreign targets show bounded unavailable state and are consumed.
- Negative/non-disclosure: Push/banner contains no sensitive body or arbitrary URL; old/foreign account notice/cache cannot render.
- Cleanup: Sign out, clear push registration, retire DB.
- Source: `app/Controllers/Api/V1/NotificationsApiController.php:11-51`; `mobile/src/features/notifications/screens/NotificationsScreen.tsx:1-232`; `mobile/src/features/workspace/NavigationIntentGate.tsx:62-95`
- Evidence: Read-state matrix, foreground/background trace, and valid/invalid deep-link recording.
- Verdict: `NOT_RUN`

## MB-011 — Staff queue monitor is entitlement-gated and read-only
- Lifecycle: `READY`
- Legacy IDs: `MB-011`
- Changed by: `bea71cf`, `902fdd6`
- Requirement: `/me` must derive staff queue unit entitlements from current permissions; mobile monitor is absent for personal-only users, returns only authorized aggregate summary for staff, and exposes no mobile queue mutation endpoint.
- Surface: Mobile navigation and staff queue monitor.
- Route/API: `GET /api/v1/me`; `GET /api/v1/staff/queue-summary`.
- Actor/grants: Student, clinic-only staff, guidance-only staff, dual-grant/superadmin variants.
- Preconditions: Deterministic queue summaries for both units.

**Steps**
1. Inspect navigation and `/me` entitlement for each role variant; open monitor where offered.
2. Request `all`, authorized unit, unauthorized unit, and invalid unit directly.
3. Search mobile routes/API for call/start/skip/complete actions and attempt representative nonexistent mutations.

- Expected: Personal user sees no monitor/gets `403`; staff sees only granted unit aggregates; invalid unit is `404`; no action endpoint/control exists.
- Negative/non-disclosure: Summary contains no patient identity/notes and read authority does not imply management authority.
- Cleanup: Restore grants and retire DB.
- Source: `app/Controllers/Api/V1/MeController.php:19-47`; `app/Controllers/Api/V1/StaffQueueApiController.php:13-55`; `app/Config/Routes.php:91-92`
- Evidence: Entitlement/navigation/API matrix and nonexistence/action denial proof.
- Verdict: `NOT_RUN`

## MB-012 — Realtime hints invalidate only active account caches
- Lifecycle: `READY`
- Legacy IDs: `MB-012`
- Changed by: `c91477a`, `73b8a11`, `c179273`
- Requirement: Mobile user-channel hints must invalidate mapped current-account queries, retain authoritative GET as source, fall back to focus polling, and have no effect after account/server/runtime retirement.
- Surface: Open mobile queue/appointments/notifications/Team screens.
- Route/API: `GET /api/v1/realtime/grant` plus resource APIs.
- Actor/grants: Signed-in user; second staff/account changes source state.
- Preconditions: Workerman and controllable outage; two accounts with canary data.

**Steps**
1. Open each affected screen, trigger matching hints, and verify a refetch/update without pull-to-refresh.
2. Send unknown/malformed/mismatched hints and verify no unsafe cache mutation.
3. Stop realtime and use focus polling; then switch account/server while old hints/requests are in flight.

- Expected: Valid hints cause only mapped current-runtime refetches; fallback repairs; retired requests/hints abort or fail with session-changed and old cache is cleared.
- Negative/non-disclosure: Hint data is never rendered as record truth and cannot cross account, tenant, unit, or server profile.
- Cleanup: Restore server, sign out, and clear connection profiles/test registrations.
- Source: `mobile/src/features/realtime/useUserRealtime.ts:1-32`; `mobile/src/api/client.ts:99-173`; `mobile/src/api/client.ts:195-227`
- Evidence: Hint/refetch timeline, unknown-event result, outage repair, and account/server isolation capture.
- Verdict: `NOT_RUN`

## MB-013 — Sign-out revokes server family and clears local private state
- Lifecycle: `READY`
- Legacy IDs: `MB-013`
- Changed by: `bea71cf`, `73b8a11`, `c179273`
- Requirement: Sign-out must immediately clear local token/profile/query state, best-effort revoke push activation and exact API token family without refresh, return to sign-in, and prevent cached/private data after restart even if cleanup network calls fail.
- Surface: Mobile account/settings sign-out.
- Route/API: `POST /api/v1/auth/logout`; push revoke; local runtime/keychain/cache cleanup.
- Actor/grants: Signed-in mobile user.
- Preconditions: Cached personal/work data, active push registration, valid token family; controllable offline mode.

**Steps**
1. Sign out online and immediately inspect UI/cache/keychain; try old access/refresh/push and restart app.
2. Sign in again, go offline, sign out, restart, and reconnect.
3. Race sign-out with a new sign-in/account or server switch and delayed old requests.

- Expected: Private UI/cache/token disappear immediately; online family/push are revoked; offline local session cannot restore and later old callbacks cannot erase or contaminate new credentials/account.
- Negative/non-disclosure: No old personal/work data flashes after restart; cleanup never silently refreshes or targets new runtime.
- Cleanup: Revoke any surviving disposable token/push rows and clear app data.
- Source: `app/Controllers/Api/V1/MobileAuthController.php:72-83`; `mobile/src/store/auth.tsx:243-257`; `mobile/src/api/client.ts:403-437`
- Evidence: Online/offline/race recordings, old-token denial matrix, and storage/cache property checks.
- Verdict: `NOT_RUN`

## MB-PER-001 — Personal entitlement and account/server isolation are authoritative
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `39f7339`
- Requirement: Personal navigation exists only when authoritative `/me` reports a live linked personal entitlement; every async request, credential, cache, draft, push scope, and navigation intent is bound to immutable runtime plus account session and discarded on entitlement/account/server change.
- Surface: Entire React Native personal context.
- Route/API: `GET /api/v1/me` and all personal endpoints.
- Actor/grants: Linked personal user, unlinked/break-glass user, and two accounts/server profiles.
- Preconditions: LAN test profiles are explicitly approved; canary records/drafts for each account; no production server.

**Steps**
1. Compare linked versus unlinked identities’ `/me`, root navigation, and direct personal API access.
2. Create an unsaved personal draft and in-flight request, then remove entitlement/role or switch account; inspect navigation, draft, cache, and callback behavior.
3. Switch server profile via the hidden approved LAN control and attempt delayed old-server responses/push/intents.

- Expected: Only linked entitlement enables personal context; revocation disposes only revoked-scope drafts/submissions and leaves no data; runtime retirement aborts/invalidates all old work and credentials are profile-bound.
- Negative/non-disclosure: UI flags never override server checks; no old account/server token, data, draft, push, or navigation target appears in the new context.
- Cleanup: Remove test server profile, clear app/keychain/push data, restore role, retire DBs.
- Source: `app/Controllers/Api/V1/MeController.php:19-48`; `mobile/src/features/workspace/WorkspaceContext.tsx:48-156`; `mobile/src/api/client.ts:99-173`
- Evidence: Entitlement matrix, draft/request transition recording, old/new canary search, and server-profile isolation trace.
- Verdict: `NOT_RUN`
