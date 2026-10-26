# SYNAPSE Mobile (React Native)

Bare React Native 0.88 app in `mobile/` (no Expo). Android-first; iOS needs a macOS build host.
UI is pixel-faithful to `mobile/mobileScreenReference/` (38-screen Figma Make prototype).

## Running

```bash
cd mobile
npm install                 # first time only
npm run start               # Metro (keep running)
# Android emulator or device connected:
npm run android
```

The backend base URL comes from `mobile/.env` (`API_BASE_URL`; default `http://10.0.2.2:8080` for the
Android emulator's loopback to your dev machine). Copy `.env.example` and adjust for real devices.

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

## Scope (v1)

Personal portal (students & employees): appointments, live queue tickets, announcements, identified
surveys, notifications, health summary, profile photo, records timeline, settings/dark mode — plus a
**read-only** staff queue monitor for users holding `clinic.queue.read` / `guidance.queue.read`.
All staff actions remain on the web workstation by design.

Deliberately out of scope for v1: WebSocket realtime (HTTP polling is the backend's sanctioned
fallback), FCM push, offline write queue, biometric unlock, i18n, tablet layouts.

## Quality gates

```bash
cd mobile
npx tsc --noEmit   # types
npm run lint       # eslint (zero warnings)
npm test           # jest
```

`mobileScreenReference/` is excluded from Metro, TypeScript, and ESLint — it is design reference only.
