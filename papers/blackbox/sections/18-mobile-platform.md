# 18 — Mobile platform

## MB-PLAT-001 — API runtime binds every request to one server and account generation
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `73b8a11`, `39f7339`
- Requirement: Each immutable API runtime owns one base URL, credential binding, access token, query client, connection owner, and monotonic session version; retiring it aborts requests and makes all captured callbacks fail as session-changed.
- Surface: Entire React Native API layer.
- Route/API: All mobile APIs.
- Actor/grants: Any mobile account; two test server profiles/accounts.
- Preconditions: Approved LAN test build or normal single-server build; delayed request harness.

**Steps**
1. Start a request/decoder/mutation under runtime A, then switch server/account to runtime B before it settles.
2. Allow A’s transport to return despite abort and inspect UI/cache/credentials/navigation.
3. Invoke API clients and callbacks captured from A after retirement.

- Expected: A is retired, token/cache cleared, requests aborted or rejected with `SessionChangedError`, and only B can update state.
- Negative/non-disclosure: No A response, credential, cache row, error, push, or intent reaches B; base URL cannot mutate under a live runtime.
- Cleanup: Clear both profiles/accounts/application data.
- Source: `mobile/src/api/client.ts:33-173`; `mobile/src/api/client.ts:195-227`; `mobile/src/features/connection/ConnectionProvider.tsx:51-79`
- Evidence: Runtime/request timeline and A/B canary search.
- Verdict: `NOT_RUN`

## MB-PLAT-002 — Server switching closes the old environment before mounting new data
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `39f7339`
- Requirement: In an approved LAN test build only, server switching must durably mark the new profile signed-out/pending, retire old runtime/cache/navigation, queue bounded old-origin session/push cleanup without refresh, clear old credentials/scope, then mount new runtime; rollout of test profiles remains policy-gated.
- Surface: Hidden sign-in/settings server selector.
- Route/API: Old `POST /api/v1/auth/logout`, push revoke, then selected server APIs.
- Actor/grants: Test operator using LAN build.
- Preconditions: Two dedicated nonoperational hosts with distinguishable canaries; old host can be offline.

**Steps**
1. Switch online from A to B and record durable storage, cleanup targets, navigation unmount/remount, and initial B signed-out state.
2. Repeat with A offline/slow and force-close during pending switch; restart app.
3. Race repeated selector taps and delayed A responses.

- Expected: No consumers mount between owners; restart resolves to signed-out selected profile or selection screen; cleanup never targets B or refreshes A; only one switch job runs.
- Negative/non-disclosure: Server control is absent in production/ordinary login, HTTP is visibly test-only, and no A credential/data survives.
- Cleanup: Remove saved LAN profile/keychain/push scope and reinstall/clear app.
- Source: `mobile/src/features/connection/ConnectionProvider.tsx:82-155`; `mobile/src/features/connection/ConnectionProvider.tsx:199-229`
- Evidence: Switch/restart/race recordings and old/new host request trace.
- Verdict: `NOT_RUN`

## MB-PLAT-003 — Android notification onboarding prompts once at the correct lifecycle
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `c179273`
- Requirement: Android 13+ first launch may request notification permission once while an active caller exists, independent of authentication/Firebase/LAN capability; a check-only race must not consume onboarding, background/unmount before native request must not mark attempted, and later automatic foreground checks never reprompt.
- Surface: Android native permission lifecycle.
- Route/API: Native permission API; no server route required.
- Actor/grants: Fresh app installation, signed out or signed in.
- Preconditions: Android 13+ emulator/device with permission denied/not yet asked; cleared app storage.

**Steps**
1. Launch fresh and observe one native request; deny it, background/foreground and restart.
2. Repeat fresh with early background/unmount before native request begins, then return active.
3. Race permission check/automatic callers and exercise explicit Enable action after denial.

- Expected: First active launch prompts once and records attempt as native request starts; interrupted pre-request launch may prompt later; automatic checks never reopen; explicit action may request/open settings per platform.
- Negative/non-disclosure: Prompt does not depend on credentials, device token, Firebase, or server and marker is device-local—not account/server permission.
- Cleanup: Reset emulator app/permission state.
- Source: `mobile/src/lib/push/permission.ts:6-98`
- Evidence: Native screen recording and local attempted-marker/lifecycle trace without identifiers.
- Verdict: `NOT_RUN`

## MB-PLAT-004 — LAN push capability handshake precedes token disclosure
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `c179273`
- Requirement: A LAN client must authenticate and verify scope version, deployment ID, and `lan-test` audience before submitting any routable device token; unsupported/failed capability check suspends setup without unsafe legacy fallback. Push rollout remains gated.
- Surface: Phone notification setup.
- Route/API: `GET /api/v1/push/capabilities`; `POST /api/v1/push/token`.
- Actor/grants: Signed-in LAN-test account.
- Preconditions: Dedicated servers representing supported, unsupported, partial-schema, wrong-deployment, and failure variants; synthetic push token.

**Steps**
1. Enable/setup against supported server and record capability response before token registration.
2. Repeat against unsupported/partial/failing servers and inspect all requests/storage/status.
3. Return malformed/wrong deployment capability/registration acknowledgment.

- Expected: Token is sent only after valid capability/scope preparation; unsupported/failure shows warning and retains in-app inbox; mismatched acknowledgment withdraws/suspends registration.
- Negative/non-disclosure: Device token is never sent to unverified server, returned by API, logged, or downgraded to unscoped registration.
- Cleanup: Revoke synthetic registration and clear secure scope/installation data.
- Source: `app/Controllers/Api/V1/PushController.php:13-85`; `mobile/src/lib/push/registration.ts:167-231`; `mobile/src/lib/push/registration.ts:288-309`
- Evidence: Ordered capability/register traces with token hash only and setup-state screenshots.
- Verdict: `NOT_RUN`

## MB-PLAT-005 — Push activation is bound to deployment, installation, API family, and account
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `c179273`
- Requirement: Scoped LAN registration must atomically bind exact tenant/user, deployment, stable installation, bearer-derived API family, audience, registration UUID, and capability; replacement retires the prior activation and context reuse conflicts. Push rollout remains gated.
- Surface: Push registry/server and mobile secure scope.
- Route/API: `POST /api/v1/push/token`.
- Actor/grants: Signed-in LAN-test user.
- Preconditions: Disposable push table with unique active installation index; two accounts/families/servers; token refresh supported.

**Steps**
1. Register valid scope, refresh token/replace device token, and inspect active rows plus response metadata.
2. Reuse registration ID or device token under another account/family/installation/deployment and run concurrent first registration.
3. Attempt partial/null/unknown scope and unscoped overwrite of scoped token.

- Expected: One current activation per installation; same context refreshes/replaces atomically; cross-context reuse conflicts; malformed/partial/downgrade attempts fail.
- Negative/non-disclosure: Device token and family are never returned/logged; errors do not disclose owner/context or foreign token existence.
- Cleanup: Revoke all synthetic activations and retire DB.
- Source: `app/Controllers/Api/V1/PushController.php:41-133`; `app/Services/Push/PushTokenService.php:61-220`
- Evidence: Redacted binding/row lifecycle matrix and concurrency count.
- Verdict: `NOT_RUN`

## MB-PLAT-006 — Push revocation is exact, idempotent, and race-safe
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `c179273`
- Requirement: Scoped revoke must match current tenant/user/token/audience/registration/API family; obsolete revoke is no-op, legacy revoke cannot strip scoped activation, logout revokes the family, and late registration after owner retirement is withdrawn from the old origin. Push rollout remains gated.
- Surface: Sign-out, account/server switch, token refresh cleanup.
- Route/API: `DELETE /api/v1/push/token`; `POST /api/v1/auth/logout`.
- Actor/grants: Signed-in user with scoped activation.
- Preconditions: Current and obsolete registrations, delayed register response, two runtimes/accounts.

**Steps**
1. Revoke current activation twice and verify exact row retirement/idempotency.
2. Replace activation, send obsolete scoped and unscoped revoke, and verify replacement remains.
3. Retire/switch owner during delayed registration and sign out token family; observe old-origin withdrawal.

- Expected: Exact current activation retires; obsolete/legacy race cannot revoke replacement or downgrade scope; family logout retires all bound activations; late old registration is withdrawn.
- Negative/non-disclosure: Cleanup never refreshes, targets new account/server, returns token, or blocks local sign-out indefinitely.
- Cleanup: Revoke any surviving synthetic rows and clear app secure scope.
- Source: `app/Services/Push/PushTokenService.php:223-262`; `app/Services/Push/PushTokenService.php:324-330`; `mobile/src/lib/push/registration.ts:61-117`
- Evidence: Row lifecycle/race timeline and old/new host cleanup trace.
- Verdict: `NOT_RUN`

## MB-PLAT-007 — Push target handling is typed, current-owner, and fail-safe
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `c179273`, `a06b70b`
- Requirement: Foreground/background/cold-start push must parse only supported type, positive numeric ID, valid Team unit, and current push/connection owner; navigation rechecks entitlement and invalid/revoked targets are consumed once with in-app fallback.
- Surface: Native push router/banner and navigation gate.
- Route/API: Push payload handling followed by relevant API detail.
- Actor/grants: Current account with personal/Team targets; second account/server.
- Preconditions: Valid, malformed, unauthorized, and old-owner synthetic push payloads.

**Steps**
1. Deliver each supported personal/Team push in foreground, background, and cold start; open exact authorized record.
2. Deliver malformed type/ID/unit, revoked entitlement, nonexistent record, and duplicate delivery.
3. Switch account/server before tapping a queued old push.

- Expected: Valid current target opens after authoritative check; invalid/revoked target shows bounded unavailable/inbox fallback and does not loop; old-owner payload is dropped.
- Negative/non-disclosure: Arbitrary URL/action/data cannot navigate; payload does not reveal sensitive body or cross account/server/unit.
- Cleanup: Clear push queue/intents, sign out, and remove synthetic messages.
- Source: `mobile/src/features/workspace/navigationIntent.ts:22-75`; `mobile/src/features/workspace/NavigationIntentGate.tsx:62-95`
- Evidence: Delivery-state/target outcome matrix and recordings.
- Verdict: `NOT_RUN`

## MB-PLAT-008 — Android system insets, keyboard, and reduced motion preserve operability
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e5b7eba`, `a06b70b`, `c179273`
- Requirement: Core screens, fixed tab/header controls, forms, dialogs, and chat composer must remain reachable above status/navigation/gesture bars and keyboard across supported Android sizes/orientations; motion respects reduced-motion preference without changing state semantics.
- Surface: React Native layout/navigation/transitions.
- Route/API: Client UI; ordinary APIs for loaded screens.
- Actor/grants: Personal-only and Team-entitled accounts.
- Preconditions: Small/tall Android emulators, gesture/three-button navigation, portrait/landscape, keyboard and reduced-motion variants.

**Steps**
1. Traverse sign-in, personal tabs, workspace tabs, forms, dialogs, bottom sheets, and chat composer under each inset/navigation configuration.
2. Open keyboard, rotate, change font/display scale, and perform submit/back/tab/context actions.
3. Enable reduced motion and repeat transitions plus interrupted/back navigation.

- Expected: Content/controls are visible, scrollable, tappable, and not obscured; keyboard dismissal/navigation is deterministic; reduced motion removes/minimizes animation but preserves results.
- Negative/non-disclosure: No accidental underlying tap/double submit, clipped error, stuck backdrop, hidden sign-out, or content flash from another context.
- Cleanup: Reset device accessibility/navigation settings and sign out.
- Source: `mobile/src/components/ScreenInsets.tsx:1-4`; `mobile/src/components/SystemNavigationBar.tsx:1-82`; `mobile/src/navigation/ContextTabs.tsx:1-82`
- Evidence: Device/viewport matrix screenshots and interaction recording.
- Verdict: `NOT_RUN`
