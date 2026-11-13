# SYNAPSE mobile

Bare React Native 0.88, Android-first. Run commands below from `mobile/` on Windows, macOS, or Linux. Use Node >=22.11, the Android SDK/platform-tools (`adb` on PATH), a compatible JDK, and an authorized USB-debugging device or Android emulator. First install: `npm ci`.

## Local Android workflow

| Service | Host address |
| --- | --- |
| PHP API | `http://127.0.0.1:8080` |
| Realtime WebSocket (optional) | `ws://127.0.0.1:8081` |
| Metro for this checkout | `http://127.0.0.1:8083` |

Ports 8081/8082 are **not Metro**: by default they are the realtime socket and its internal publisher. If other PHP listeners already use those ports, resolve the conflict deliberately rather than killing unrelated processes. Start MySQL, then start the backend in its own terminal:

```sh
# Repository root: start the configured backend manually
php spark serve --host 127.0.0.1 --port 8080
```

### Quick start: Metro + debug build

With one authorized USB device/emulator connected, run these from `mobile/` in another terminal:

```sh
node scripts/android-usb.js
npx react-native run-android --port 8083
```

Once USB setup reports `Ready`, the second command builds, installs, and launches the **debug app**, and starts Metro if it is not already running. You do **not** need to run `npm start` separately for this workflow. Keep the Metro terminal/window and the backend terminal running.

**Always include `--port 8083`.** Without it, React Native defaults to 8081, which may conflict with the PHP listeners. Do not add `--no-packager` when you want the CLI to start Metro.

The USB script does not build the app or start servers. It checks the connected target and forwards device ports **8080 (API)**, **8081 (WebSocket)**, and **8083 (Metro)** to this computer. It never forwards the internal publisher on 8082. Rerun it after USB reconnects or device/emulator/ADB restarts, since those mappings can be lost; it is not needed before every build if the mappings are still present.

### Alternative: start Metro separately

Use this **instead of** the quick-start workflow if you prefer a dedicated Metro terminal:

```sh
# mobile/: start Metro manually, leave running
npm start
```

```sh
# mobile/: one authorized device/emulator, then reverse ports and build
npm run android:usb
npm run doctor -- --adb
```

In your local `mobile/.env`, use `API_BASE_URL=http://127.0.0.1:8080` for USB/reverse. Native dotenv Gradle integration is wired: **rebuild/reinstall after `.env` changes**; Fast Refresh or restarting Metro is not enough. The deterministic development fallback is the same loopback URL; release builds require an explicit HTTPS API URL. Never commit credentials or treat bundled configuration as secret.

`android:usb` checks ADB, requires exactly one connected authorized target, and runs `adb -s <serial> reverse tcp:8080 tcp:8080` plus `reverse tcp:8081 tcp:8081` and `reverse tcp:8083 tcp:8083` **before** building. It fails rather than choosing between devices. Accept the device's RSA prompt, disconnect extras, and retry. This also works with an emulator. After USB reconnect or emulator restart, rerun `node scripts/android-usb.js` (setup only), or `npm run android:usb` (setup + build).

- `npm run android`: build/install with `--port 8083 --no-packager`; assumes routing is already prepared.
- `npm run doctor`: read-only, bounded unauthenticated checks of Metro's `/status` **and checkout root header**, and the backend's expected `/api/v1/me` 401 JSON error. Add `-- --adb` to inspect reverse mappings; it does not create them. No login/refresh requests or credentials.
- No helper starts/stops Metro or PHP. If 8083 belongs to another checkout, inspect its owner; do not kill unrelated processes blindly.

### Live login-carousel updates

The login carousel listens for WebSocket change hints from the existing Workerman server. After an admin adds, updates, deletes, toggles, or reorders a slide, mobile refetches the public carousel without resetting the login form. It also reconciles on reconnect/foreground and every 120 seconds to repair missed events; this is not 30-second polling.

1. Configure realtime using [REALTIME.md](../docs/REALTIME.md). Keep signing/publisher keys server-side; never put them in `mobile/.env`.
2. Include the API’s exact origin in the backend’s `realtime.allowedOrigins`. For USB, add `http://127.0.0.1:8080` alongside existing origins. `localhost` is a different origin.
3. From the repository root, run this in a **separate terminal** and leave it running:
   ```sh
   php spark realtime:serve start
   ```
   After changing realtime PHP code or configuration, stop the existing worker with Ctrl+C and start it again.
4. Rerun `node scripts/android-usb.js` from `mobile/` to include the WebSocket mapping. If you configure a different socket port, reverse that port explicitly instead.

The public grant permits only carousel invalidations for the server-selected tenant—never private user/queue/chat channels. Connections pause while the carousel is keyboard-hidden, backgrounded, or offline, and close when login unmounts. Release HTTPS APIs require a `wss://` public socket endpoint. Without a usable socket, login remains functional and the 120-second reconciliation continues while visible/online.

Wi-Fi/LAN is opt-in; use the physical Wi-Fi/Ethernet IPv4 address, explicit listeners/firewall rules, and a rebuilt API URL. See [mobile operations](../docs/MOBILE.md#wi-fi-lan-opt-in), not a VPN/WSL/virtual adapter address.

### Signed-in realtime hints

While signed in, foreground, and online, the app holds one extra socket on the signed-in user's
private channel via the bearer-authenticated `GET api/v1/realtime/grant` (same Workerman worker,
same first-frame ticket rule; see [MOBILE_API.md](../docs/MOBILE_API.md#private-mobile-realtime-grant)).
Payload-free hints refresh the React Query caches: `notifications.changed` (bell inbox),
`portal.queue.changed` (personal queue card **and linked appointment lists/detail**),
`portal.appointments.changed` (visits lists/detail), and `queue.invalidated` (staff queue board for
the named unit). Hints never carry content — the socket says "stale", the HTTP refetch says "what".
Independent query reads do not wait behind a slow inbox or another unrelated query; overlapping
hints coalesce per concrete query, with one trailing read if a hint overlaps an older HTTP read.

**Polling is the default; a healthy authenticated WebSocket takes priority.** Queue, appointments
and staff queue (15s), notifications (30s), Tasks/Calendar (30s), and latest Chat (5s) stop their short
fallback timers only after the server's authenticated `ready` frame. One narrow exception is a
visible **waiting** personal ticket: its position/ETA still reconciles every 15s, because another
patient moving through the queue does not send this user a personal hint. This is not a department
subscription and never exposes peer records. Queue and appointment observers pause when their
screen is covered, blurred, backgrounded, or offline.

An enabled grant or an open-but-not-authenticated socket is not sufficient. Disconnect, heartbeat
timeout, ticket renewal, offline/background and sign-out release readiness; eligible fallback timers
resume without an automatic mutation replay. Query eligibility remains separate, so socket hints,
manual refresh, and initial HTTP reads keep working while polling is paused. A 2–5-second visible
delay is not a configured queue timer: the authoritative HTTP read and server work still take time.

The 120-second HTTP reconciliation stays active even on a healthy connection, including staff queue
snapshots: server fanout is bounded, so a connected socket can still miss a hint. Chat keeps its
serialized read queue and historical windows. Dashboard `/me` refresh retains its own polling because
this private channel has no corresponding profile event. Public login-carousel readiness never
suppresses signed-in polling. The socket closes on sign-out/expiry, backgrounding, and offline.

**HTTP 429 is not a connectivity failure.** Default login budgets are 5 attempts/300 seconds per IP + identity and 60/60 seconds per IP, shared by web/mobile. Honor `Retry-After`; do not reset credentials or keep probing sign-in. Use only local synthetic accounts described in [LOCAL_TEST_ACCOUNTS.md](../tests/LOCAL_TEST_ACCOUNTS.md); no passwords belong in docs or diagnostics.

## Display refresh rates (60/90/120 Hz and adaptive panels)

Display Hz and data polling are independent. The app does not render frames from a JavaScript timer,
force every display to 120 Hz, change resolution, or override user/power/thermal limits.

- **Android 16 / API 36+:** a HIGH frame-rate category propagates through each active app window's
  native view hierarchy, including React Native Modal windows. The OS can downshift on idle and
  keeps control of the physical refresh rate.
- **Android API 24–35:** active windows prefer the highest valid advertised rate at the current
  resolution. On API 24–33, multi-resolution displays conservatively retain the OS default because
  the legacy hint may choose the default resolution. Explicit display-mode choices are not replaced.
- Window preferences are released on pause/destroy, modal closure and display removal; re-evaluated
  on resume, attachment, configuration/display changes and React context changes.
- **iOS:** `CADisableMinimumFrameDurationOnPhone` is already enabled for ProMotion-capable iPhones.
  The system still applies the panel, Low Power Mode, accessibility and thermal constraints.

Native changes require a **new Android build/install**; Fast Refresh alone cannot apply them. The
policy is a request, not a measured sustained-FPS guarantee. Validate scrolling, context transitions,
sheets/dialogs and keyboard transitions on the connected device at its selectable refresh rates,
then repeat in battery-saving mode and after background/resume. Use a release/profile build for
frame-time measurements; Metro debug performance and a display-Hz overlay alone do not prove
rendered-frame delivery. See [the display-rate test checklist](../docs/MOBILE.md#display-refresh-rate-acceptance).

## Theme contrast

Use `theme.brandText` and `theme.brandIcon` for brand-colored text, icons, and loading indicators on themed surfaces: maroon in light mode, white in dark mode. Keep `theme.brand` and `palette.brand*` for maroon fills/gradients with white content; do not use these fixed colors for foregrounds. Semantic guidance, warning, and error colors remain distinct.

## Release builds

Android release artifacts are signed from the environment, never from the repository. A release task
fails fast when signing is not configured, so a debug-signed APK can never reach a store by accident.

| Variable | Purpose |
|---|---|
| `SYNAPSE_VERSION_CODE` / `SYNAPSE_VERSION_NAME` | The version the store receives |
| `SYNAPSE_UPLOAD_STORE_FILE` | Path to the upload keystore (keep it out of git) |
| `SYNAPSE_UPLOAD_STORE_PASSWORD` | Keystore password |
| `SYNAPSE_UPLOAD_KEY_ALIAS` / `SYNAPSE_UPLOAD_KEY_PASSWORD` | Key alias and key password |
| `SYNAPSE_ENABLE_MINIFICATION` | R8 minification and resource shrinking (default `true`) |
| `SYNAPSE_ALLOW_DEBUG_SIGNING` | Escape hatch for a local smoke build that will never be distributed |

The same names work as Gradle properties in `android/gradle.properties` (never commit real values).
`./gradlew :app:assembleRelease` stops with an actionable message when the signing properties are
missing. Release builds enable R8; the keep rules live in `android/app/proguard-rules.pro`.

There is no automated distribution pipeline yet: builds are produced locally and uploaded by hand.

## Team Workspace interaction parity

Production uses `ContextTabs`, with independent Personal and Team navigation histories. Context
changes retain 240 ms opaque cubic-out **scene** transitions. Ordinary Android peer tabs switch
immediately with no scene opacity; iOS peers keep their 160 ms fade. Both mount on first visit and
retain visited state. Android detail stacks use translation-only `slide_from_right`; iOS keeps its
native default. Live Reduce Motion disables transitions. A single stationary navigation bar sits outside those
scenes, with a raised, shadow-free circular center CTA and a coordinated 400 ms maroon reveal for
Work/reverse reveal for Personal. The guarded action is not a fake tab; disabled Work features are
omitted and changing units clears the old unit's navigator.

Tasks, Calendar, Chat and their pickers reuse shared controls and retained serialized native sheets
and dialogs. Drafts stay in memory, Keep editing preserves them, and accepted discard resets them
before post-dismiss navigation. Creates/sends/comments retain exact nonce payloads for explicit
ambiguous retries; updates/cancellation retain the reviewed version. Mutations never automatically
retry or enter an offline write queue. Team task changes also refresh same-unit Calendar deadlines
and Chat task-picker results without replacing browsed Chat history.

The detailed screen/action checklist and **current-pass** evidence/remaining native gates live in
[MOBILE_TEAM_WORKSPACE_IMPLEMENTATION.md](../docs/MOBILE_TEAM_WORKSPACE_IMPLEMENTATION.md#screenshot-confirmed-rendering-and-loading-correction--2026-11-09).
Passing Jest or `assembleDebug` is not proof of Android keyboard geometry, iOS modal dismissal,
screen-reader focus, or production animation frame rate. Use an explicitly selected native target;
do not replace an installed app/account or perform server writes merely for visual testing.

## Motion and performance acceptance

`src/lib/motion.ts` holds the product presets: sheets enter/exit at 240/180 ms, dialogs
at 180/140 ms with a 0.97-to-1 scale, action content scales to 0.98 at 80/120 ms, and the
chat down-arrow fades over 120 ms. All decorative motion uses native-driver transforms/opacity;
button hit targets stay stationary and activation never waits for an animation.

The appointment-request stepper uses a local **240 ms opaque horizontal slide** between accepted
steps: forward from the right and Back from the left. Only the step bodies move; header, progress,
pinned actions and the Work switcher stay stationary. Live Reduce Motion settles immediately.
Typing, selections, validation, and keyboard-height changes do not replay it. Rapid reversals use
the current native progress; rotation, blur, background, accepted discard and unmount cancel old
motion. “Request received” slides in only after success, remains outside the four-step count, and
never unlocks or repeats submission. Drafts and raised-switcher clearance are retained.

Sheets measure their outer surface before entry, keep travel stable during entry/exit, and
continue dismissal from a dragged offset. Rejected closes settle back and unlock another attempt.
The shared queue still retains each outgoing native Modal until actual dismissal. Handle movement
uses PanResponder on the JS thread; this pass does not claim UI-thread gesture independence.

Task cards retain stable render props during draft search changes, and queue/appointment reads
respect focus, foreground/connectivity and overlay eligibility. Lists do not replay entrance
animations on polling/refresh. Ordinary Chat entry and navigation re-entry request the latest
window at the bottom; explicit message targets still take priority. The loaded list stays mounted
behind an opaque themed preparation cover until the current viewport and actual newest marker/tail
(or target) confirm its position. Issuing a scroll command is not readiness. Empty, short and
already-bottomed windows can finish without a new scroll event. Warm ordinary returns reveal a
measured retained latest window while the fresh latest read runs in the background, rather than
hiding cached messages until HTTP finishes. An event-driven next-frame correction completes a
no-op/inexact initial scroll estimate; it does not wait for a timer. First loads, new scopes and
unloaded explicit/history targets still need their own data. Blur immediately covers retained
native pixels before frozen-route thaw. Failed/offline re-entry shows the last good window with
recovery feedback instead of waiting indefinitely. While staying in chat, a reader who scrolls
upward keeps their position. Bottom-follow intent keeps the newest message above keyboard/composer
resizes and incoming messages only while already following latest. Covered time cannot count toward
the 500 ms read-marker dwell. Input/list identity, grouped messages, drafts and explicit return-to-latest
remain intact. Appointment Details and pinned booking steps share chat's raised-CTA clearance
without changing ordinary accounts, keyboard spacing or service-selection/success screens.

Chat shows one centered timestamp above each connected sender group, using the first message:
`1:47 PM` today, `Wed 9:09 AM` for the previous six Manila days, and
`Nov 1, 2026, 9:05 AM` for older dates. Bubble-end times and duplicate date headings are removed;
individual message accessibility retains its own time. Sender/day/five-minute grouping and separate
read markers remain. One foreground/uncovered midnight clock updates only headings, never the
message window, input, preparation or network requests.

The screenshot-confirmed ghosting is addressed centrally by removing Android whole-scene alpha,
not by changing maroon colors, normal borders/shadows or the independent context/CTA animation.
**Android visual acceptance remains pending on a matching rebuilt release APK.** Compare Home,
Visits, Queue, Profile ID, all Team peers and detail pushes/pops, including rapid reversals and
Reduce Motion. Passing mocked navigation tests is not native GPU proof.

Native acceptance must use a release/profileable build on physical hardware. Compare cold and warm
tab/context transitions, rapid reversals, sheet dragging and rejected dismissal, keyboard-to-sheet
handoffs, and populated lists during updates. Trace frame deadlines at the active display rate
(about 16.7/11.1/8.3 ms at 60/90/120 Hz), p95/p99 work, missed-frame clusters, input latency and
retained memory. Use Android Studio System Trace/Perfetto and iOS Instruments. Debug FPS counters
and passing Jest/bundle compilation do not establish sustained FPS or zero jank.

## Mobile loading presentation

`Skeleton` supplies bounded, themed card/list/detail/form/queue/calendar/chat geometry. It is
noninteractive and shadow-free, with one loading announcement per region and inaccessible
shapes. The calm native-driver pulse stops on blur, background, overlay, inactive state or
unmount, and is static under Reduce Motion. No extra dependency, forced minimum duration or
JavaScript frame-production loop is used.

`loadingState` prefers valid current-query data, including successful empty responses, over
refresh/loading/error flags. Skeletons appear only for genuine no-data reads. Disabled or paused
no-data states say they are waiting; cached populated and empty states stay visible on transient
refresh failures. Authoritative access/removal responses hide protected content. Home sections
load independently while greeting/Quick access remain usable. Profile identity/initials, static
settings and new-form shells stay visible. Survey receipts and existing edit drafts survive
background work; action/send/upload/save spinners remain local. Calendar does not borrow data
from a previous range/filter.

Research: [RN View alpha/layout](https://reactnative.dev/docs/view),
[React Navigation tab animation](https://reactnavigation.org/docs/bottom-tab-navigator/#animation),
[FlatList variable-size scrolling](https://reactnative.dev/docs/flatlist#scrolltoend), and
[NN/G skeleton guidance](https://www.nngroup.com/articles/skeleton-screens/).
Skeletons improve presentation; they do not make a slow HTTP request faster.

## Screen polish

New Event and Edit Task keep their pinned action surfaces continuous through the
opted-in bottom safe-area padding. The footer owns that padding instead of leaving
a separate canvas-colored strip; action positions, keyboard avoidance and Chat's
explicit no-bottom-inset footer are unchanged. Task Details separates its comment
field and Add comment action by 12 points.

Calendar view and filter choices use subtle native-driven selection feedback with
immediate radio selection and stationary touch targets. Only the Calendar filter
sheet uses 360 ms entry / 240 ms exit. The existing default sheets and dialogs keep
their timings and native dismissal/drag/focus lifecycle. Newly mounted presenters
reuse the already-observed Reduce Motion preference from concurrent live consumers;
unknown preferences stay conservative and stale native work is rejected.

Unread notifications are white with dark content in dark mode, maroon with white
content in light mode; read rows keep their ordinary appearance. Booking step two's
Selected service summary is charcoal in dark mode and maroon in light mode. Its
review/receipt banners are unchanged. Appearance omits the explanatory sentence
about the primary action color.

## Checks and references

```sh
node --test scripts/dev-tooling.test.cjs scripts/check-android-apk.test.cjs  # no network/device
node --test android/app/src/test/refresh-rate-policy.test.cjs  # local JDK; no device
npx tsc --noEmit
npm run lint
npm test -- --runInBand --detectOpenHandles
```

The screenshot/loading correction passed **70 suites / 1,422 tests**, TypeScript,
ESLint (only four existing `secureNonce` warnings), **62 Android/native tooling
contracts**, and offline LAN release bundle/resource/Kotlin compilation. These
checks did not build/sign/install a successor APK or establish physical visual acceptance.
The subsequent Chat waiting correction passed **97 focused tests**, TypeScript and scoped lint.
After booking-clearance and stepper-slide work, the full serialized run passed
**71 suites / 1,442 tests** with no reported open handles; TypeScript and full lint passed
with the same four existing warnings. After group timestamps, the full suite passed
**72 suites / 1,463 tests**, TypeScript and full lint passed, and no open handles were reported.
The subsequent screen-polish pass passed **73 suites / 1,535 tests** with no skipped
tests or reported open handles. TypeScript, changed-file lint and whitespace passed;
full lint had only the same four existing warnings. The later changes were not
rebuilt into a signed APK.

`.github/workflows/mobile.yml` runs these gates on every push and pull request, with a JDK for the
native selector tests and a separate Android build/class-definition packaging gate. After a local
debug build, verify the actual APK too:

```sh
node scripts/check-android-apk.cjs android/app/build/outputs/apk/debug/app-debug.apk
```

`npm run coverage` is available locally but must not be combined with `--runInBand`: coverage
instrumentation slows the fake-timer suites enough to time them out.

`npm run ios` also uses Metro on 8083 without starting a second packager. iOS requires macOS/Xcode and CocoaPods (`bundle install`, then `bundle exec pod install` from `ios/`). The Android USB routing helpers do not configure physical iOS devices; select a reachable API URL for your iOS setup.

Device push uses machine-local Firebase project files, which are never committed. Android **debug**
builds can run without `android/app/google-services.json` and report push as unavailable; Android
**release** and **lanTestRelease** builds require Firebase configuration matching the variant's
application ID. A configuration for another package does not satisfy that requirement. On iOS,
`ios/SynapseMobile/GoogleService-Info.plist` needs Target Membership in `SynapseMobile` so Xcode copies
it into the app bundle; push testing also needs a physical device and an APNs key configured in Firebase.
**Rebuild and reinstall the native app** after changing Firebase files or notification native code,
channels, icons, or manifest entries. Restarting Metro alone does not install those changes.

Android 13+ requests permission once on the first active launch, independently of sign-in, Firebase,
or LAN readiness. A denial is not automatically prompted again on resume. The **Phone notifications**
control in Notifications and Settings supports light/dark mode and offers an explicit Enable action,
check-only setup retry, or phone notification settings as appropriate. Signed-in Android token
registration checks permission without prompting, remains bound to the captured account/server, and
recovers on resume/reconnect. iOS keeps its existing signed-in permission flow.

Accepted foreground pushes use the Android notification bridge, with a React banner fallback if native
posting is unavailable. Background `notification` + `data` messages are displayed by Firebase/OS,
without a duplicate app poster. Supported server notices, including survey publication, use the
existing best-effort push path only after commit. **Ready confirms registration, not device receipt**:
server setup, transport, connectivity, permission/channel settings, and OS policy still affect delivery.
The in-app inbox remains available. See [Device push](../docs/MOBILE.md#device-push) for the full behavior.

See [architecture and troubleshooting](../docs/MOBILE.md), [API contract](../docs/MOBILE_API.md), the [LAN Test build and two-server setup](../docs/MOBILE_LAN_TEST.md), and the [React Native environment setup](https://reactnative.dev/docs/set-up-your-environment). `mobileScreenReference/` is design reference only, excluded from Metro, TypeScript, and ESLint.
