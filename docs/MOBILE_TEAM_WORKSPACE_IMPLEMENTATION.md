# Mobile Team Workspace implementation tracker

Last updated: 2026-11-06

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
- [ ] Add active-unit switcher and dirty-draft guards across real Workspace forms.
- [x] Implement Android Back, Reduce Motion, safe-area, and accessibility behavior.
- [ ] Purge protected Workspace query data on 401/403 or entitlement loss.

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
- [x] Full serialized Jest suite passes (43 suites / 711 tests).
- [x] Android build passes.
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
- [x] Additional named accounts (Clinic/Guidance/BMG-only), font scaling, live revocation, and real push delivery remain for full staging sign-off.

### Task 13 — full staging sign-off (2026-11-07, connected device + local development stack)

- [x] **Per-unit account:** `test.guidance.admin` signed in on the device → single-unit direct entry (no picker), Team Board/Team Chat scoped to Guidance only. The documented local credential convention was verified against existing account hashes without exposing or resetting them.
- [x] **Font scaling:** device font size raised to Large (1.35) through the phone's own Settings UI (ADB write was permission-blocked, so no permission grants were made). Two real large-text defects were found and fixed in code: Calendar status-filter truncation (filters now wrap to full-width rows) and Workspace tab-label truncation (labels now wrap to two centered lines); the Board search field was widened with a visible placeholder. Full mobile suite re-passed (43 suites / 711 tests). Device font restored to its original Small setting afterward.
- [x] **Live revocation:** the Guidance administrator role was revoked through the authorized web workflow (superadmin → Employees & Access, reason recorded in the audit ledger). The signed-in device showed "Your session has expired" within seconds with no cached Workspace data rendered. The role was then restored through the same workflow and re-sign-in on the device regained the Guidance Workspace. Only the synthetic fixture account was touched; the batch reset command was not used.
- [x] **Real push delivery:** the staging smoke test caught and fixed a real regression (`PushTransport::sendToDevices` was missing its `$tenant` parameter after the per-device capability refactor; push suites re-passed 14/57). With `push.enabled` temporarily true, `php spark push:test 1 6` returned `delivered=true` and the OS notification shade displayed "SYNAPSE staging check / Live push verification to the connected device." `push.enabled` was restored to false immediately after.
- [ ] **Team push enablement remains a deployment gate:** the local development database has not had `2026-11-06-001100_AddPushInstallationCapabilities` applied, so `push_tokens` still lacks `installation_id` / `api_family_id` / `team_workspace_capable` and Team-namespace push correctly fails closed. Personal push (the path verified above) works on the current schema. Applying that migration to any shared environment still requires explicit deployment approval, backup, and preflight.

## Deployment record

- Workspace rollout state: **disabled until implementation and acceptance gates pass**.
- Team OS push state: **disabled until compatible native destinations are deployed and staging-verified**.
- Schema migrations applied to operational environments: **none as part of implementation work**.
- Rollback rule: disabling Workspace must never delete Team tasks, comments, activity, events, participants, messages, read cursors, or notification history.
