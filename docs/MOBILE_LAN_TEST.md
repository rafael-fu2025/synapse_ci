# Synapse LAN Test build and two-server setup

This guide covers the **Synapse LAN Test** Android APK: a separately installed
testing app that can switch between two approved servers, plus the backend
settings each server needs. The normal production app stays HTTPS-only.

## What this build is

- Package `com.humus.synapse.lantest`, label **Synapse LAN Test**. It installs
  beside the normal app and debug build without replacing either.
- A real release build: bundled JavaScript, debugging disabled, normal release
  minification. It starts without Metro.
- Exactly two fixed server choices, selectable at first launch, on the sign-in
  screen, on recovery screens, and in Settings. No arbitrary URL entry.
- Unencrypted HTTP is permitted **only** to these two exact hosts, enforced by
  the Android network policy file of this variant; every other host stays on
  normal HTTPS rules. A native transport rule additionally rejects redirects
  from either API address so credentials never follow a redirect.
- Both choices are remembered. Switching servers signs the account out, clears
  the previous server's local data, live connections and notification binding,
  and always requires signing in again on the selected server.

## Building the APK

From `mobile/`, check the prerequisites before building:

```sh
node scripts/android-release-preflight.cjs --profile lanTestRelease
cd android
./gradlew :app:assembleLanTestRelease
```

Do not use `bundle...` (AAB) or `run-android` for this artifact. Return to
`mobile/` with `cd ..`, then run the release artifact check:

```sh
node scripts/check-android-apk.cjs android/app/build/outputs/apk/lanTestRelease/app-lanTestRelease.apk --profile lanTestRelease
```

The checker verifies the compiled manifest and network policy (only the two
approved hosts allow cleartext), the native build markers, the bundled code,
required class definitions after minification, and the signing certificate.

Before building a successor over a known-good APK, verify the old artifact and
copy it outside `outputs/apk/lanTestRelease/` without overwriting an existing
backup. Local build output is not source control. The initially verified APK
for this device pass is preserved under
`android/app/build/outputs/apk/verified-backups/synapse-lan-test-before-refresh-chat-fixes.apk`.
Its file SHA-256 is
`0bd4f9418d05d9aa580e8cbc6de59c34f9c991f656750a5c2c3b4fa03d208b83`.
This file digest identifies that old artifact; it is **not** the signing
certificate fingerprint used by the checker. A rebuilt successor needs its own
full verification and subsequent device acceptance.

Before the opaque-navigation/loading correction, the existing output APK was also
reverified and preserved without overwriting the first backup:
`android/app/build/outputs/apk/verified-backups/synapse-lan-test-before-opaque-loading-fixes-736013815f38.apk`.
That predecessor is **83,821,495 bytes**, file SHA-256
`736013815f381f7c6d51ee24423c782b4722bd097c8435a554f6fdbba4eb62dd`,
package `com.humus.synapse.lantest`, version `1.0.0-lan-test` / code `1`.
Its signature and packaging passed; its source-to-visual revision was not
established. Neither preserved APK contains proof of the current visual fix.

### Release signing (required)

Provide the existing signing material as Gradle properties or environment
variables, as for the normal release:

```
SYNAPSE_UPLOAD_STORE_FILE, SYNAPSE_UPLOAD_STORE_PASSWORD,
SYNAPSE_UPLOAD_KEY_ALIAS, SYNAPSE_UPLOAD_KEY_PASSWORD
```

The build refuses to produce a release APK with the debug key, including
through aggregate `assemble`/`build` invocations. The artifact check also
verifies the signature against the expected public certificate SHA-256; a
debug-signed or unexpected certificate is rejected even if a local smoke
waiver was used. Never commit keystore files or passwords.

The artifact checker reads the expected **public certificate SHA-256** from
`SYNAPSE_EXPECTED_CERT_SHA256` in the shell environment. Both the preflight and
artifact checker also accept `--expected-cert-sha256 <public SHA-256>`.

Keep the signing properties in your existing private signing setup, such as
`~/.gradle/gradle.properties`, rather than a tracked project file. Set the
expected public fingerprint separately in the environment used for the checks.
Never share keystore files or passwords in chat.

### Rebuild from a fresh PowerShell terminal

Use the **same release key**, not a newly generated one. The following local
PowerShell block uses the signer path/alias and trusted public fingerprint from
this session. It prompts for passwords without displaying them, performs the
preflight/build/full APK verification, and clears temporary passwords in
`finally`. It does not install the app or perform server/account operations.
The known-good APK backup described above is preserved. Keep a secure backup of
the signing key outside the project.

```powershell
$env:JAVA_HOME = "C:\Program Files\Java\jdk-17"
$env:Path = "$env:JAVA_HOME\bin;$env:Path"
$env:SYNAPSE_UPLOAD_STORE_FILE = "$env:USERPROFILE\synapse-signing\synapse-release.jks"
$env:SYNAPSE_UPLOAD_KEY_ALIAS = "synapse-release"
$env:SYNAPSE_EXPECTED_CERT_SHA256 = "90:B3:26:96:64:B9:D6:E4:B2:1C:0E:A6:16:65:5E:0D:69:35:31:16:6A:16:AD:32:91:4B:DB:15:74:E5:5B:F5"
# The inspected predecessor is version code 1. Use a higher code if already built later.
$env:SYNAPSE_VERSION_CODE = "2"
$env:SYNAPSE_VERSION_NAME = "1.0.1"

try {
    $env:SYNAPSE_UPLOAD_STORE_PASSWORD = [System.Net.NetworkCredential]::new(
        "", (Read-Host "Keystore password" -AsSecureString)
    ).Password
    $env:SYNAPSE_UPLOAD_KEY_PASSWORD = [System.Net.NetworkCredential]::new(
        "", (Read-Host "Key password for synapse-release" -AsSecureString)
    ).Password

    Set-Location "$env:USERPROFILE\humus\mobile\android"
    node ..\scripts\android-release-preflight.cjs --profile lanTestRelease
    if ($LASTEXITCODE -ne 0) { throw "Release prerequisite check failed." }

    $apk = ".\app\build\outputs\apk\lanTestRelease\app-lanTestRelease.apk"
    # Verify and preserve existing output before Gradle can replace it.
    if (Test-Path -LiteralPath $apk) {
        node ..\scripts\check-android-apk.cjs $apk --profile lanTestRelease
        if ($LASTEXITCODE -ne 0) { throw "Previous APK verification failed; no rebuild started." }
        $oldHash = (Get-FileHash -LiteralPath $apk -Algorithm SHA256).Hash.ToLowerInvariant()
        $backupDir = ".\app\build\outputs\apk\verified-backups"
        [System.IO.Directory]::CreateDirectory((Join-Path $PWD $backupDir)) | Out-Null
        $backup = Join-Path $backupDir "synapse-lan-test-$oldHash.apk"
        if (Test-Path -LiteralPath $backup) {
            if ((Get-FileHash -LiteralPath $backup -Algorithm SHA256).Hash.ToLowerInvariant() -ne $oldHash) {
                throw "Existing backup differs; it was not overwritten."
            }
        } else {
            [System.IO.File]::Copy((Resolve-Path $apk).Path, (Join-Path $PWD $backup), $false)
        }
    }

    .\gradlew.bat :app:assembleLanTestRelease --console=plain
    if ($LASTEXITCODE -ne 0) { throw "APK build failed." }

    node ..\scripts\check-android-apk.cjs $apk --profile lanTestRelease
    if ($LASTEXITCODE -ne 0) { throw "APK verification failed." }
    Write-Host "APK built and verified: $((Resolve-Path $apk).Path)"
    Get-FileHash -LiteralPath $apk -Algorithm SHA256 | Format-List Path, Hash
    git rev-parse HEAD
    git status --short -- ../src ../__tests__
    Get-Content .\app\build\outputs\apk\lanTestRelease\output-metadata.json
}
finally {
    Remove-Item Env:SYNAPSE_UPLOAD_STORE_PASSWORD, Env:SYNAPSE_UPLOAD_KEY_PASSWORD -ErrorAction SilentlyContinue
}
```

The source HEAD alone is not provenance while mobile changes are uncommitted.
Record that dirty state with the APK hash, package/version and build output; do not
label it as a clean build of the old commit. Commit/push is a separately authorized
step, not an effect of this build block. The metadata file contains package/version
only, never the signing passwords or Firebase credential values.

The Gradle invocation can download a missing locked build dependency if it is
not cached; have Internet available for that. Build and APK verification must
both succeed before treating the new output as a verified release. Install the
result over the existing **Synapse LAN Test** app using the same key, rather than
uninstalling/clearing its data, then follow the update/layout acceptance checks.
The backend source changes must also be deployed through the normal authorized
procedure to each LAN instance; rebuilding an APK does not deploy PHP code.

### Firebase push (required for notifications)

Because the testing package has a different application ID, Firebase needs a
client entry matching `com.humus.synapse.lantest`:

- Add a matching client to `android/app/src/lanTestRelease/google-services.json`
  (project-level file stays for the normal app), or point the variant at its
  own file.
- The build preflight reports the mismatch instead of silently disabling push.

Notifications require Internet connectivity to Firebase even though ordinary
API reads work on a LAN without public Internet.

### Current build prerequisites

Before the first distributable APK, provision:

1. The release signer and its **public certificate SHA-256** for the artifact check.
2. A Firebase Android client matching `com.humus.synapse.lantest`.

`node scripts/android-release-preflight.cjs --profile lanTestRelease` reports
these as booleans without exposing secret values.

## Backend settings per server

Each LAN backend (university and home) needs, in its own isolated
configuration—never by editing production defaults or a shared `.env`:

- **HTTP serving:** either a development-style local configuration, or a
  production-like instance with `forceGlobalSecureRequests = false` explicitly
  set only in that instance. Do not use `CI_ENVIRONMENT=testing` to serve the
  app; that environment is reserved for PHPUnit and forces the test database.
- **Realtime:** allowed origins exactly `http://172.16.153.237:8080` and/or
  `http://192.168.1.11:8080` (no trailing slashes), listener
  `websocket://0.0.0.0:8081`, empty `publicUrl` so each API origin derives its
  own `ws://<host>:8081`, and the publisher left on the private loopback
  address. The phone must be able to reach the API on 8080 **and** the socket
  on 8081 of the same host.
- **Push scope:** a stable `push.deployment_id` (8–128 chars, alphanumeric,
  `.`, `_`, `-`), and the additive migration
  `2026-11-09-000001_AddLanPushRegistrationScope` applied through the normal
  deployment procedure before accepting LAN registrations.

Do not relax the MIS HTTPS requirement, server-side outbound endpoint policy,
or any production transport setting for this feature.

## Notification behavior in the testing app

- Registration checks the server's capability endpoint first and binds the
  device to that server's deployment and a fresh registration ID per account
  activation. An explicitly acknowledged binding survives a normal cold restart
  of the same selection. Provisional/rejected bindings, including older saved
  entries without the acknowledgment marker, are never trusted during restore;
  they require a successful registration before in-app notices/taps are accepted.
- Phone-visible notification text for LAN registrations is forced to the
  generic **"Synapse LAN Test — Open the app to check for updates"**, so an old
  server's queued notice cannot expose record details on the lock screen.
- In-app banners, detail fetches and notification taps verify the deployment
  and registration against the active server/account. A mismatched or missing
  scope is rejected: an old University notice cannot open a Home record, even
  if its revocation failed while offline.
- Limitation: a queued notice from the previously selected server may still
  appear as a generic banner after an offline switch. It carries no details and
  cannot navigate.

## Acceptance checklist (both networks)

1. Cold start without Metro; first-run chooser; remembered choice on restart.
2. Sign in on University over Wi-Fi; load lists, images and realtime.
3. Open Settings → Server → change to Home; confirm; verify sign-out, cleared
   data, fresh sign-in and working polling/realtime on the home network.
4. Cancel a switch with unsaved drafts and an active submission; drafts survive.
5. Sign in on both servers with the same test account; verify identical IDs do
   not mix cached data, and University → Home → University stays isolated.
6. With the app closed, receive a notice; verify a valid tap opens the right
   record, and a stale old-server tap (if any) opens nothing.
7. Unavailable-server recovery: connection error and expired screens still
   offer the server control offline.

## Mobile update and layout acceptance

Queue and appointment WebSocket messages are **change hints**, not record data.
The phone still fetches the authoritative snapshot over authorized HTTP. A
2–5-second update alone does not identify polling versus WebSocket delivery:
a slow HTTP request or a single-worker development server can add delay after
a hint arrives.

- Independent hint-driven queries must not wait for an unrelated inbox read.
  Repeated hints for the same query coalesce, and an overlapping older read gets
  one trailing refresh. Server/account retirement cancels future refresh work.
- Personal queue hints also refresh appointment lists/details, covering check-in,
  completion and linked no-show. A no-show without a ticket needs the backend's
  post-commit appointment hint too.
- Queue/appointment fallback reads run every 15 seconds only on eligible visible
  screens when authenticated realtime is unavailable. A visible **waiting**
  ticket additionally reconciles position/ETA every 15 seconds while realtime is
  healthy: movement of other patients does not send a personal hint to this user.
  This exception does not subscribe the phone to other patients' records.
- On a normal Team Chat entry or return, an opaque preparation cover hides the
  mounted list until current native viewport and newest marker/tail measurements
  confirm the bottom. There must be no visible top-to-bottom correction. Empty,
  short and already-bottomed windows must not wait for a scroll event that never
  fires. Returning to an already-loaded latest window must reveal after native
  position confirmation even while the fresh latest request is pending; refreshing
  is not a reason to keep cached messages covered. There is no minimum skeleton
  countdown. A fresh explicit target still opens that message, including a target
  received while the tab was blurred. Failed/offline returns show the last loaded
  window and recovery rather than a permanent loader. Incoming messages while
  reading older content during the same visit must not pull the list down.
- Chat has one centered first-message timestamp above each connected group:
  time today, abbreviated weekday/time for the previous six Manila days, and
  abbreviated month/day/year/time for older dates. No time remains in the last
  bubble. Verify append/prepend and midnight/background return without extra
  message loading, scrolling, lost draft or false read acknowledgements.
- If already at the latest message, opening/closing the keyboard, expanding a
  multiline composer, or receiving a message must keep the newest content above
  the composer. Read acknowledgements still depend on actual marker visibility.
- Appointment Details' bottom action and the pinned booking-step buttons should
  clear the raised context CTA where present, without adding excess padding to
  accounts with ordinary navigation or while the keyboard is visible. Check
  Continue to date & time, Review appointment and Submit appointment request;
  service selection and request-success screens need no raised action clearance.
- Booking's accepted step changes slide horizontally without fading: forward from
  the right, all Back paths from the left. Header/progress/footer/switcher remain
  stationary. Confirm that typing/errors do not replay motion, rapid back/forward
  and rotation settle safely, and Reduce Motion disables the slide. Success is a
  receipt after the server responds, not a fifth form step or another submission.
- New Event and Edit Task footers must paint continuously into their bottom safe
  padding, without the separate canvas-colored strip shown in the form screenshot.
  Create/Save and Cancel remain stationary above the raised switcher. Check both
  themes, small/large bottom insets, and keyboard show/hide without losing drafts.
- Task Details must separate the comment field from Add comment, including while
  the keyboard is open; uncertain-comment retries must keep their original text.
- Agenda/Week/Month and both Calendar filter groups have subtle selection feedback
  with immediate selection and stationary touch targets. Calendar filters enter in
  360 ms and exit in 240 ms; other sheets retain their existing timings. Check the
  first opening, rapid selection/close/reopen, sheet dragging, and Reduce Motion.
- Unread notifications use white with dark text/icons in dark mode, and maroon
  with white text/icons in light mode. Read rows retain their ordinary appearance.
  Booking step two's Selected service summary uses charcoal in dark mode and
  maroon with white text in light mode; review and success banners are unchanged.
  Appearance must no longer show the explanatory primary-action-color sentence.

These scenarios require an updated APK and the matching backend code. Run them
on both networks using user-owned/disposable test accounts. Opening Team Chat
can issue read acknowledgements; automated UI acceptance must not silently use
operational accounts.

The supplied screenshot confirmed simultaneous page ghosting and a transient
heavy frame. Ordinary Android Personal/Team peers now use no scene alpha;
Android detail stacks use translation-only `slide_from_right`. iOS keeps its
peer fade/native stack default. The independent opaque Personal/Work slide and
raised CTA reveal, normal card shadows and borders remain unchanged. This changes
the shared rendering path, not maroon-themed surfaces individually.

**A matching new release/device recording is still required to accept the visual
fix.** Compare Home↔Visits↔Queue↔Profile and Team Board↔My Tasks↔Chat↔Calendar,
including Profile ID, maroon buttons, detail pushes/pops, rapid reversals, cold/warm
tabs, light/dark and Reduce Motion. Pass only if there is no ghosted underlying
page or transient heavy frame, the CTA remains intact, and Chat's first exposed
frame is already positioned. If a frame survives these opacity-free paths,
inspect that specific surface from the new recording rather than removing shadows
globally. Jest cannot certify native GPU rendering or measured update latency.

Initial no-data reads now use region-shaped skeletons with a static Reduce Motion
fallback. A disabled/paused read says it is waiting; successful empty and populated
cached results stay visible while refreshing or after a transient failure. Greeting,
Quick access, signed-in Profile identity/initials, static settings, drafts and survey
receipts remain usable. Test fast/cached loads as well as slow/failed reads; no
artificial minimum-duration flash or new dependency was added. Skeletons do not
promise shorter network response times.

Research: [React Native View](https://reactnative.dev/docs/view),
[React Navigation tabs](https://reactnavigation.org/docs/bottom-tab-navigator/#animation),
[FlatList](https://reactnative.dev/docs/flatlist#scrolltoend),
[NN/G skeleton guidance](https://www.nngroup.com/articles/skeleton-screens/).

## Related docs

- Normal mobile setup and HTTPS release policy: `mobile/README.md`, `docs/MOBILE.md`.
- Realtime topology details: `docs/REALTIME.md`.
