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
  (auth, dashboard, appointments, queue, announcements, surveys, notifications, profile, staff-queue).
- **`src/navigation/`** — root gate (splash/sign-in/app by auth status) + 4 bottom tabs
  (Home, Visits, Queue, Profile); engagement surfaces push onto the root stack above the tabs.
- **`src/store/auth.tsx`** — the only client session state (status + user). Access token lives in
  memory; the refresh token lives in the device keychain (`react-native-keychain`).
- **`src/theme/`** — design tokens translated 1:1 from the reference CSS, light + dark, with a
  system/light/dark preference persisted in AsyncStorage.
- **`src/components/`** — design-system primitives (Screen, PageHeader, StatusPill, AppCard, TextField,
  DetailRow, EmptyState, Action, Avatar, Typography, OfflineBanner).

**Server state is React Query only** — no other caching layer; mutations never auto-retry and
invalidate their queries on success. The backend remains the single source of truth; the app hides
UI by `/me` entitlements but always honors 401/403.

### Navigation, freshness, and session boundaries

- Tabs are mounted once and frozen while hidden. Cross-tab actions use explicit nested routes;
  Home’s “Book a visit” opens the Visits booking flow, not an unregistered Home route.
- Queries use a 30-second freshness window and an in-memory cache only. Queue polling (15s) and
  notification polling (30s) run only for focused screens, and pause when the app backgrounds.
  Native connectivity events resume queries; public Internet reachability is not required for a LAN API.
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
- Private photo URLs are separated by owner and preview session; bearer tokens never appear in URLs.

These safeguards are covered by regression tests, but they do not replace release-device profiling,
accessibility review, or an independent security audit. A debug build running through Metro is not a
representative production-performance benchmark.

## Scope (v1)

Personal portal (students & employees): appointments, live queue tickets, announcements, identified
surveys, notifications, health summary, profile photo, records timeline, settings/dark mode — plus a
**read-only** staff queue monitor for users holding `clinic.queue.read` / `guidance.queue.read`.
All staff actions remain on the web workstation by design.

The public login carousel uses the existing WebSocket transport for change hints, with 120-second
HTTP reconciliation while visible/online. Authenticated resources still use their documented polling
cadences; this change does not add private mobile subscriptions.

Deliberately out of scope for v1: FCM push, offline write queue, biometric unlock, i18n, tablet layouts.

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

## Quality gates

```bash
cd mobile
npx tsc --noEmit   # types
npm run lint       # eslint (zero warnings)
npm test -- --runInBand  # jest
```

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
