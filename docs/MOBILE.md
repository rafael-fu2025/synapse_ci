# SYNAPSE Mobile (React Native)

Bare React Native 0.88 app in `mobile/` (no Expo). Android-first; iOS needs a macOS build host.
UI follows `mobile/mobileScreenReference/` (Figma Make prototype), adapted to native safe areas and
real API data. The student/employee ID card mirrors the web portal’s profile card. Prototype-only
controls, sample data, and unsupported service promises are not shipped as working features.

## Repeatable local Android setup (Windows-friendly)

Prerequisites: Node >=22.11, the React Native Android JDK/SDK toolchain, and Android SDK
`platform-tools` on PATH (`adb version` must work; Windows resolves `adb.exe`). Enable USB debugging,
unlock the device, and accept its RSA authorization prompt. An Android emulator also supports ADB
reverse. Run `npm ci` once from `mobile/` after installing prerequisites.

| Purpose | Host listener | Notes |
| --- | --- | --- |
| Backend API | `127.0.0.1:8080` | PHP, not Metro |
| Realtime socket (optional) | `127.0.0.1:8081` | Forward this to USB devices; not Metro |
| Internal realtime publisher | `127.0.0.1:8082` | Loopback-only; never forward to devices |
| Mobile Metro | `127.0.0.1:8083` | Must belong to this checkout's `mobile/` |

Start services yourself in separate terminals; the tooling never starts or stops Metro/PHP:

```sh
# Terminal 1, repository root (backend already configured locally)
php spark serve --host 127.0.0.1 --port 8080
```

```sh
# Terminal 2, mobile/
npm start                    # react-native start --port 8083; keep running
```

```sh
# Terminal 3, mobile/: exactly one authorized device or emulator
npm run android:usb          # prepare reverse mappings, then build/install
npm run doctor -- --adb       # verify host services + reverse mappings
```

`npm run android` is `react-native run-android --port 8083 --no-packager`; it deliberately cannot
spawn an accidental second packager. `android:usb` runs the Node preparation script first, and only
builds if it succeeds. It requires exactly one connected target in ADB's authorized `device` state;
zero devices, unauthorized/offline devices, or multiple targets produce actionable errors. Disconnect
extra devices/close extra emulators, authorize or reconnect the remaining target, and retry.

The preparation script uses bounded `spawnSync('adb', args)` calls without a shell, selects the
serial explicitly, and installs the API, WebSocket, and Metro reverse mappings before the build:

```sh
adb -s <serial> reverse tcp:8080 tcp:8080
adb -s <serial> reverse tcp:8081 tcp:8081
adb -s <serial> reverse tcp:8083 tcp:8083
```

These route device loopback **back to the host** (`adb reverse`, not `adb forward`). Repeating setup
is safe. USB reconnects, ADB/device restarts, and emulator restarts can discard mappings: rerun
`node scripts/android-usb.js` from `mobile/` for setup only, or `npm run android:usb` to also rebuild.

### API URL and native configuration

For USB or an emulator using reverse, set this in your own local `mobile/.env`:

```dotenv
API_BASE_URL=http://127.0.0.1:8080
```

This is the deterministic **development fallback** too, rather than deriving an API
address from whichever Metro host answered. Set it explicitly for repeatable USB work. Release
builds require an explicit **HTTPS** API URL; HTTP loopback/LAN is development-only, not a production
fallback. Do not ship secrets in native configuration.

Android's `react-native-config` **dotenv Gradle integration is now wired**. `.env` values are native
build inputs: after any URL change, rebuild/reinstall with `npm run android:usb` (or `npm run android`
with existing routing). Fast Refresh, a JS reload, or restarting Metro alone does not update them.
The tooling does not read or modify env files, infer credentials, or rewrite native/client code.

### Safe diagnostics

From `mobile/`, `npm run doctor` checks both host services independently with 4-second HTTP timeouts:

- `GET http://127.0.0.1:8083/status` must return Metro's running marker and an
  `X-React-Native-Project-Root` header matching this checkout's `mobile/` directory. Windows paths
  are compared after case, slash, trailing separator, and URL-escape normalization. A running Metro
  from a different checkout is a failure, not success. Inspect the listener's owner and deliberately
  restart the correct Metro yourself; the doctor kills nothing.
- Unauthenticated `GET http://127.0.0.1:8080/api/v1/me` must return **401 with a JSON
  `{error: {code, message}}` body**, not HTML, a redirect, or an authenticated 200. This proves the
  expected host API route is responding, not that a device can reach it or credentials are valid.
- Optional `npm run doctor -- --adb` requires one authorized target and reads `adb reverse --list`
  for all three matching ports. It does not create mappings or probe the API/socket from the device.

A failed check yields a nonzero exit status. There are no login/refresh probes, credentials, env-file
reads, automatic server startup, or process termination. Windows listener inspection can be done
with PowerShell `Get-NetTCPConnection -LocalPort 8080,8083 -State Listen` or `netstat -ano`; do not
stop unrelated PHP servers on 8081/8082. Offline unit tests use mocks only:

```sh
node --test scripts/dev-tooling.test.cjs
```

### Wi-Fi / LAN (opt-in)

USB/reverse is the default: it needs no inbound LAN firewall opening. For a physical device without
USB, deliberately switch **both** Metro and the API to LAN access:

1. Put the computer and device on a trusted network that permits client-to-client traffic.
2. Use `ipconfig` on Windows to find the **physical Wi-Fi/Ethernet adapter's IPv4 address**, not a
   VPN, WSL, Hyper-V, Docker, or other virtual adapter address. Do not use device `127.0.0.1` without
   reverse; it means the device itself.
3. Manually bind PHP to the network, e.g. `php spark serve --host 0.0.0.0 --port 8080` from the root.
   If necessary, start Metro with `npm start -- --host 0.0.0.0`, then point Android's dev-server setting
   at the computer's physical IPv4 address plus `:8083`.
4. Set the local native `API_BASE_URL` to `http://<physical-ipv4>:8080` and rebuild/reinstall. Configure
   Windows Firewall to allow inbound TCP 8080 (PHP) and 8083 (Node) **only on the trusted/private
   network**. Never open these development listeners to the public internet.
5. Update the URL and rebuild again if DHCP changes the address. To return to USB, restore the
   loopback API URL, rerun reverse setup, and restore Android's Metro setting to `127.0.0.1:8083`.

The doctor intentionally stays on host loopback; a pass does not validate LAN/firewall/device
routing. Emulator users can use the same reverse workflow as USB instead of a separate `10.0.2.2`
configuration.

### Sign-in limits are not networking failures

**HTTP 429 means the server was reached.** Default login budgets are **5 attempts per 300 seconds
per IP + identity**, and **60 attempts per 60 seconds per IP**. Web and mobile share these budgets;
switching transport or repeatedly testing credentials is not a workaround. The backend returns the
actual remaining wait in `Retry-After`; the sign-in screen displays a deadline-based countdown and
disables submissions until it expires (300-second fallback if the header is missing/invalid). It also
blocks duplicate in-flight taps and never retries credentials automatically. Wait for the window,
and do not reset credentials or clear limiter state as a connectivity fix.
Use only local synthetic test accounts following [LOCAL_TEST_ACCOUNTS.md](../tests/LOCAL_TEST_ACCOUNTS.md).
Do not put passwords, real user credentials, bearer tokens, or refresh tokens into docs/diagnostics.

## Architecture

- **`src/api/`** — `client.ts` (fetch wrapper: bearer injection, 10s/45s timeouts, single-flight
  refresh-on-401, one retry, session-expired signal), `endpoints/*` (one module per resource),
  `types.ts` (wire contract of `docs/MOBILE_API.md`), `queryKeys.ts`, `poll.ts` (intervals mirror
  the web client: queue 15s, notifications 30s).
- **`src/features/<domain>/`** — screens, components, React Query hooks per feature
  (auth, dashboard, appointments, queue, announcements, surveys, notifications, profile, staff-queue,
  workspace, team-tasks, team-calendar, team-chat).
- **`src/navigation/`** — root auth gate + production `ContextTabs`, with independently retained
  Personal tabs (Home, Visits, Queue, Profile) and authorized Workspace tabs (Team Board, My Tasks,
  Team Chat, Team Calendar). The center control switches context; it is not a fifth route.
  Notifications and engagement surfaces push onto the root stack. A separate Account stack reuses
  settings, appearance, and privacy screens without exposing Personal records to Work-only accounts.
- **`src/store/auth.tsx`** — the only client session state (status + user). Access token lives in
  memory; the refresh token lives in the device keychain (`react-native-keychain`).
- **`src/theme/`** — design tokens translated 1:1 from the reference CSS, light + dark, with a
  system/light/dark preference persisted in AsyncStorage.
- **`src/components/`** — shared primitives (Screen, PageHeader, StatusPill, AppCard, TextField,
  DetailRow, EmptyState, Action, IconAction, Avatar, Typography, OfflineBanner) and retained,
  serialized BottomSheet/ConfirmDialog presenters under OverlayProvider.

**Server state is React Query only** — no other caching layer; mutations never auto-retry and
invalidate their queries on success. The backend remains the single source of truth; the app hides
UI by `/me` entitlements but always honors 401/403.

### Navigation, freshness, and session boundaries

- Tabs remain mounted and freeze while hidden; same-unit context switches retain history. Changing
  the active Workspace unit remounts its navigator so old records, versions, and forms cannot carry
  over. Disabled Work features are omitted. Cross-tab actions use explicit nested routes;
  Home’s “Book a visit” opens the Visits booking flow, not an unregistered Home route.
- Personal, Workspace, and context **scenes** share 240 ms cubic-out, opaque current-width motion.
  One persistent navigation bar stays outside those scenes: its raised circular CTA remains anchored
  while a native-driver, center-out reveal transforms the full bar to maroon for Work and reverses
  to the themed Personal surface. Tab foregrounds follow in a coordinated 400 ms sequence; live
  Reduce Motion settles immediately. Accepted context changes—not picker opening or a cancelled
  discard prompt—drive this animation. Duplicate taps and overlay/submission locks remain guarded;
  the complete floating bar hides for the keyboard without leaving a bottom gap.
- Queries use a 30-second freshness window and an in-memory cache only. Polling is the default:
  queue/staff queue 15s, notifications 30s, Tasks/Calendar 30s and latest Chat 5s. A healthy authenticated
  private WebSocket takes priority and pauses these short timers; an enabled grant or socket open
  without server `ready` does not. Hints and manual/initial reads remain enabled. Connection loss,
  heartbeat timeout, renewal or lifetime cleanup restores eligible fallback polling. Team automatic
  reads pause in the background, offline, and beneath shared overlays. Native connectivity resumes
  reads; public Internet reachability is not required for a LAN API.
- Both connected and fallback modes retain 120-second repair snapshots for hint-covered resources,
  now including the staff queue: publisher fanout is bounded. Readiness is QueryClient/identity/lease
  scoped, not persisted, and old connection callbacks cannot suppress a new account's polls.
  Dashboard `/me` has no matching hint and keeps its independent 30-second refresh. Public carousel
  socket health is separate from the authenticated transport.
- Team task changes invalidate same-unit Tasks, Calendar deadlines, and Chat task-picker results,
  not browsed Chat history. Chat serializes physical reads, retains its bounded history window, and
  advances read cursors only for actually visible message bottoms. Pull-refresh feedback is separate
  from quiet Team updates; refresh failure keeps authorized cached data and reports the failure.
- Cached data stays visible during refresh. Home always retains queue, announcement, and survey
  slots; loading, failed requests, and genuinely empty responses have different messages.
- Sign-out/expiry immediately clears the access token, user, and query cache. Every authenticated
  JSON response is checked against its originating session; late responses/refreshes cannot restore
  the old account. Native credential writes/deletions are serialized. Sign-out makes one bounded,
  best-effort server revocation attempt without refreshing a signed-out session.
- A temporary refresh outage does not expire a signed-in session. Cold-start outages show sign-in
  without deleting the encrypted refresh token; a later app launch can attempt restoration again.
- Booking/survey submissions block duplicate taps. Survey drafts stay in memory only and leaving
  asks before discarding them. Preferred appointment times are **requests**, not guaranteed slots.
- Workspace forms/composers and directly affected booking/cancellation flows register actual draft
  reset callbacks and submission locks. Keep editing preserves values; discard resets memory before
  guarded navigation continues after native dialog dismissal. Same-unit safe record pushes preserve
  retained composers. Updates use reviewed optimistic versions; explicit ambiguous retries preserve
  the original payload and nonce/version. No offline mutation is queued or automatically replayed.
- Authoritative same-identity `/me` access refresh does not replace the signed-in navigation tree.
  Record-edit 403 is not by itself proof of lost Workspace membership. Unit/feature changes and
  external destinations are revalidated, including after delayed confirmation. Revoked Personal root
  routes are removed; registration scopes preserve still-authorized drafts and submission locks.
  Cache reconciliation is session-operation fenced against same-turn sign-out events, and unavailable
  targets produce visible feedback.
- Private photo URLs are separated by owner and preview session; bearer tokens never appear in URLs.

These safeguards are covered by regression tests, but they do not replace release-device profiling,
accessibility review, or an independent security audit. A debug build running through Metro is not a
representative production-performance benchmark.

## Scope (v1)

Personal portal (students & employees): appointments, live queue tickets, announcements, identified
surveys, notifications, health summary, profile photo, records timeline, settings/dark mode — plus a
**read-only** staff queue monitor for authorized staff. Users with server-derived Clinic, Guidance, or
BMG Facilities membership also receive a separate Team Workspace with Team Board, My Tasks, Team Chat,
and Team Calendar. The raised center navigation action switches contexts without changing identity;
normal dual-context sessions always begin in Personal. Bulk administration, attachments, direct
messages, patient/clinical links, recurring events, and offline mutation queues remain web/deferred.

The public login carousel uses the existing WebSocket transport for change hints, with 120-second
HTTP reconciliation while visible/online. Authenticated Personal and Workspace resources share one private user WebSocket. It carries only
content-free invalidation hints; authorized HTTP snapshots remain the source of truth and focused polling
repairs missed/bounded fanout.

Deliberately out of scope for v1: offline write queue, biometric unlock, i18n, tablet layouts.

### Device push

Device permission, server registration, and device receipt are separate states:

- **Android permission onboarding:** on the first active launch on Android 13+ (API 33+), the app
  can request notification permission once. This device-local prompt does not wait for sign-in,
  Firebase initialization, or a LAN server capability check. Denial does not cause repeated automatic
  prompts on later launches/resumes. The **Phone notifications** control in Notifications and Settings
  is available in both light and dark themes: **Enable notifications** is a deliberate permission
  request; blocked permission or a disabled notification channel leads to Android notification settings.
  A fresh denial can also report system notifications as disabled, so it still offers Enable rather
  than incorrectly treating the first denial as permanently blocked.
- **Signed-in registration:** the client registers with `POST api/v1/push/token` under the captured
  account and API runtime, and withdraws the old registration at sign-out/server change. Android
  registration is check-only for permission; it never triggers another automatic permission prompt.
  Foreground resume and connectivity recovery recheck permission and retry setup for the current
  signed-in session. Delayed results from a retired account/server are ignored. The existing iOS
  permission request remains part of signed-in activation; Android's first-launch policy does not
  replace the iOS flow.
- **Foreground display:** an accepted foreground push attempts to post a phone notification through
  the Android native bridge and refreshes the in-app data. The React banner remains a fallback when
  the bridge is absent or system notifications are not known to be enabled. When the bridge and
  channel are enabled, the in-app banner is suppressed to avoid a duplicate alert. Native posting is
  still best-effort; permission changes and OS notification policy can prevent an alert.
- **Background/closed display:** supported FCM messages use `notification` plus `data`. The Firebase
  SDK/OS owns their background notification display; the app does not add a duplicate background
  poster. Force-stop, Do Not Disturb, battery restrictions, connectivity, and device policy can affect
  whether or when a notification is visible.
- **Committed server notices:** supported notification IDs are sent to the existing best-effort push
  publisher only after the owning transaction commits. Survey publication now follows this path;
  rollback/no-op operations do not send survey push, and realtime failure does not prevent the
  independent push attempt. Existing recipient authorization, generic payloads, and delivery budgets
  are unchanged. The durable in-app inbox, realtime invalidation, and polling remain the recovery path.

Firebase project files stay machine-local and uncommitted. Android debug builds can run without
Firebase configuration and report push as unavailable. Android `release` and `lanTestRelease` builds
require matching Firebase configuration for the variant's application ID; a file for another package
is not a substitute. iOS needs the matching `GoogleService-Info.plist` in its app target, plus physical
hardware and configured APNs for push testing. Rebuild and install the native app after adding/changing
Firebase files or the notification bridge, channel, icon, or manifest; a Metro reload alone cannot
install those native changes.

**Ready means registered, not delivered.** Receipt also depends on the server's enabled/configured
push transport, FCM/APNs, the connection, and phone/channel settings. Unit tests and a successful build
are not proof that a device received a notice. See `docs/MOBILE_PUSH_OPTIONS.md` for the original
transport evaluation; the behavior above describes the current implementation.

### Public carousel realtime

`GET /api/v1/auth/carousel/realtime` issues a short-lived, no-store grant for exactly
`public.tenant.{serverTenantId}.login-carousel`. No login bearer token is attached. Both the HTTP
grant request and the native socket send the API URL’s exact `Origin`, which must be in
`realtime.allowedOrigins`; USB normally requires `http://127.0.0.1:8080` in addition to any web origins.
The ticket travels in the first socket frame, never a URL. Private grants, handshakes, and publish
credentials are unchanged. Admin writes emit a payload-free `login.carousel.changed` hint only
after successful durable writes; caller-owned open transactions suppress hints and reconcile later.

Run `php spark realtime:serve start` in a separate terminal from the root, alongside the API and
Metro. Restart that worker after changing realtime PHP code/configuration. The USB helper now
also reverses 8081; **8082 stays private to the host**. Custom WebSocket ports need explicit reverse
mappings. HTTPS deployments must provide a `wss://` public URL.

The listener pauses for the keyboard, background, offline state, and unmount. It renews expiring
grants and reconnects with capped jittered backoff. Initial/foreground/reconnect snapshots and
120-second reconciliation repair dropped hints. The six-second visual rotation is independent;
invalidation keeps the selected slide by ID when it still exists and does not reset form fields.

### Floating context-switcher acceptance

The shared bar is mounted once at the outer `ContextTabs` level, not inside a sliding context scene.
Its 56×56 CTA disc sits in a 64×64 separation ring, raised 22px above the surface; the destination
caption is outside the disc. A 76px central slot keeps real tabs clear of the button. The reveal is
clipped to the bar, but the raised hit area and shadow are not clipped. Safe-area padding, larger
text and keyboard visibility must not stretch the circle or leave an empty keyboard gap.

Implementation was completed without a device/emulator. After the native packaging repair, the
user confirmed the navigation and switcher work on the connected Android phone and match the
intended design. The remaining cross-device/accessibility/performance matrix is still pending:

1. Verify the button is visibly raised, perfectly circular and touchable at its upper edge. Check
   narrow/wide layouts, portrait/landscape, both themes, larger text and device bottom insets.
2. Switch Personal → Work: the bar/button stay anchored while maroon spreads from the center across
   the full bar, including the safe-bottom area. Work tabs follow in sequence with readable white
   foregrounds. Switch back: the reveal reverses to the active Personal theme without a moving bar.
3. Reverse an accepted switch mid-animation. Verify no snapping, duplicate context action or stale
   tab destination; same-unit tab selections, nested routes and local state stay retained.
4. Open/cancel unit selection and Keep editing on a disposable local draft. Neither should animate
   an unaccepted context change. Pending submission and overlay-exit locks must still prevent taps.
5. Show/hide the keyboard while in each context and while switching. The entire floating bar hides
   without a bottom gap and restores the correct context. Live Reduce Motion settles immediately.
6. Verify Personal-only/Work-only accounts have no context action or reserved center slot, and
   disabled Work features do not leave phantom tabs. Check TalkBack/VoiceOver sees one actionable
   tab set, not the decorative crossfade layers.

Jest geometry/animation assertions and a debug build are not proof of native shadow rendering,
touch dispatch or frame-time smoothness. Profile the release build before claiming sustained FPS.

### Display refresh-rate acceptance

Display refresh support is a native window policy, **not** a polling interval or an animation duration.
Android 16+ propagates the HIGH requested frame-rate category to the main and RN Modal view trees;
earlier releases prefer an advertised same-resolution rate where safe. API 24–33 defers to the OS
on multi-resolution displays rather than risk a resolution switch. Foreground preferences are
released/restored across app, modal, display and React-context lifecycles. No global display settings,
battery saver, thermal policies or user-selected mode is changed. iOS already opts into ProMotion
with `CADisableMinimumFrameDurationOnPhone`; no iOS build was performed on Windows.

Implementation was completed without a device/emulator. The later repaired APK passed startup
on the physical API 33 phone; sustained frame-rate and full display-lifecycle acceptance remain
pending. After rebuilding the native app:

1. With the device set to each supported normal/high refresh option, inspect Personal and Work
   scrolling, the center context switch, tab transitions, sheets/confirmation dialogs and keyboard
   entry/exit. Do not expect a 60 Hz panel to acquire a 90/120 Hz mode.
2. Repeat after background/resume, rotation, opening/closing sheets and moving to another supported
   display if available. Native windows must not retain stale preferences after closure.
3. Repeat with battery saver, Reduce Motion, and any available thermal/user refresh limits. The OS
   remains authoritative; an observed cap is not permission to disable those limits.
4. Profile a release/profile build's frame times, not just display Hz: 60/90/120 Hz frame budgets are
   approximately 16.67/11.11/8.33 ms. Record jank/dropped-frame evidence before claiming sustained FPS.
5. Check freshness separately: with realtime disabled, fallback polling runs at its resource cadence;
   with authenticated realtime ready, hints refresh without parallel short polling. Stop/restore the
   socket and verify fallback/reconnect without losing drafts or replacing browsed Chat history.
   Leave the socket quiet long enough to verify the 120-second repair still runs.

The dependency-free native selector tests compile the actual pure Java selector with local `javac`
and run it with `java`; bounded source contracts check native lifecycle/API wiring. These tests do
not replace Android WindowManager or real-panel acceptance:

```sh
node --test android/app/src/test/refresh-rate-policy.test.cjs
```

## Quality gates

```bash
cd mobile
npx tsc --noEmit   # types
npm run lint       # eslint (four existing secureNonce no-bitwise warnings; no errors)
npm test -- --runInBand  # jest
node --test scripts/dev-tooling.test.cjs scripts/check-android-apk.test.cjs  # no device/network
node --test android/app/src/test/refresh-rate-policy.test.cjs  # local JDK, no device
```

`.github/workflows/mobile.yml` runs these gates on every push and pull request, including the
native selector tests under a configured JDK and a separate Android debug build/packaging job.
Standalone JVM tests do not prove Android emitted the Java implementation: the APK checker follows
actual DEX class definitions and requires both the selector and its nested `Mode` class. From
`mobile/android`, build with the canonical Metro port, then check the artifact from `mobile/`:

```sh
bash gradlew :app:assembleDebug -PreactNativeDevServerPort=8083 --console=plain
# Return to mobile/ after the build:
node scripts/check-android-apk.cjs android/app/build/outputs/apk/debug/app-debug.apk
```

These commands do not install an app or start Metro. `npm run coverage` is available locally but
must **not** be combined with `--runInBand`: coverage instrumentation slows the fake-timer suites
enough to time them out.

`mobileScreenReference/` is excluded from Metro, TypeScript, and ESLint — it is design reference only.

Device smoke checklist (use synthetic accounts only):

1. Sign in, switch Home/Visits/Queue/Profile, and revisit each tab. Confirm no navigation warnings.
2. Home: no SYNAPSE brand footer; queue ticket, announcements, and surveys retain empty slots.
3. Profile: check student and employee fields against the web card, including long names and dark mode.
4. Booking: step back with both header and Android Back; verify AM/PM and review confirmation.
5. Survey: required blank answers are rejected, Back preserves answers, discard confirmation works,
   and successful submission remains visible after refetch.
6. Disconnect the API/network: errors must not claim that tickets, appointments, or updates are empty.
7. Sign out and use a different account: no former account’s cached profile, records, or photo is shown.
8. Work: exercise Board/My Tasks search, clear/reset, empty refresh, pagination and task details. Use
   only disposable fixtures for status/checklist/comments/create/edit writes; inspect pending and
   permission states before accepting any mutation.
9. Keep/discard an unsent comment, task/event form and Chat composer through header Back, Android
   Back, context/unit switch and delayed notification entry. Confirm navigation waits for native close.
10. Open low-page multiline/member/checklist inputs and sheet search with the keyboard visible.
    Confirm the focused field and actions are reachable, no duplicate Android avoidance appears,
    keyboard Back does not leave Work, and closing/reopening preserves values and scroll.
11. Check Calendar Agenda/Week/Month and Chat history/latest/source/task links. Validate native picker,
    sheet drag, capped-list feedback, long labels, large text, both themes and live Reduce Motion.
12. Revoke only a disposable fixture unit/feature/Personal entitlement. Unrelated authorized drafts
    and pending guards must remain, while revoked screens and delayed targets close or fail closed.

Record current-code outcomes and blocked cases in
[MOBILE_TEAM_WORKSPACE_IMPLEMENTATION.md](MOBILE_TEAM_WORKSPACE_IMPLEMENTATION.md#interaction-parity-pass--2026-11-07).
Never infer iOS or release frame-rate acceptance from these Android debug checks.
