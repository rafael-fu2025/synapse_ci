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

**HTTP 429 is not a connectivity failure.** Default login budgets are 5 attempts/300 seconds per IP + identity and 60/60 seconds per IP, shared by web/mobile. Honor `Retry-After`; do not reset credentials or keep probing sign-in. Use only local synthetic accounts described in [LOCAL_TEST_ACCOUNTS.md](../tests/LOCAL_TEST_ACCOUNTS.md); no passwords belong in docs or diagnostics.

## Theme contrast

Use `theme.brandText` and `theme.brandIcon` for brand-colored text, icons, and loading indicators on themed surfaces: maroon in light mode, white in dark mode. Keep `theme.brand` and `palette.brand*` for maroon fills/gradients with white content; do not use these fixed colors for foregrounds. Semantic guidance, warning, and error colors remain distinct.

## Checks and references

```sh
node --test scripts/dev-tooling.test.cjs  # isolated tooling tests; no network/device
npx tsc --noEmit
npm run lint
npm test -- --runInBand
```

`npm run ios` also uses Metro on 8083 without starting a second packager. iOS requires macOS/Xcode and CocoaPods (`bundle install`, then `bundle exec pod install` from `ios/`). The Android USB routing helpers do not configure physical iOS devices; select a reachable API URL for your iOS setup.

See [architecture and troubleshooting](../docs/MOBILE.md), [API contract](../docs/MOBILE_API.md), and the [React Native environment setup](https://reactnative.dev/docs/set-up-your-environment). `mobileScreenReference/` is design reference only, excluded from Metro, TypeScript, and ESLint.
