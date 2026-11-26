# 14 — Realtime and notifications

## RT-001 — Web realtime ticket grants only server-derived channels
- Lifecycle: `READY`
- Legacy IDs: `RT-001`
- Changed by: `bea71cf`, `c91477a`
- Requirement: An authenticated web POST from an exact allowed origin must receive a short-lived no-store ticket containing only the caller’s private user channel plus currently authorized AI topics; no client selector may expand it.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Authenticated web realtime bootstrap.
- Route/API: `POST /realtime/ticket`, then WebSocket `/` authenticate frame.
- Actor/grants: Fresh DB-valid web identity; AI topics only if separately authorized.
- Preconditions: Isolated Workerman process, exact allowed origin, realtime gate/key configured, two users in one tenant and another tenant.

**Steps**
1. Request a ticket while including forged user, tenant, channel, role, and origin form/query selectors, then authenticate the socket from the actual allowed origin.
2. Record the `ready` frame and publish a harmless hint to the caller’s user channel and to forged channels.
3. Repeat after revoking an AI grant and compare authorized channels/events.

- Expected: Ticket issuance ignores selectors; socket becomes ready; caller receives only authorized-channel events; revoked topics disappear on a fresh ticket.
- Negative/non-disclosure: Response/event data exposes no signing/publish key, other user/tenant channel, role wildcard, ticket claims beyond what the client must use, or domain record data.
- Cleanup: Stop sockets and isolated Workerman; delete synthetic hints.
- Source: `app/Controllers/RealtimeController.php:15-36`; `app/Services/Realtime/TicketIssuer.php:17-43`
- Evidence: Redacted ticket metadata, channel delivery matrix, and grant-before/after trace.
- Verdict: `NOT_RUN`

## RT-002 — Expired realtime ticket and grant disconnect cleanly
- Lifecycle: `READY`
- Legacy IDs: `RT-002`
- Changed by: `bea71cf`
- Requirement: Tickets expire within configured 1–300 seconds; authentication at/after expiry fails, and an authenticated connection is destroyed when its claim deadline expires so queued data is discarded.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: WebSocket server.
- Route/API: WebSocket `/` authenticate/publish flow.
- Actor/grants: Valid ticket holder.
- Preconditions: Isolated short-TTL realtime configuration and controllable test clock or bounded wait.

**Steps**
1. Issue a ticket, wait until its exact expiry, then attempt socket authentication.
2. Authenticate a second ticket before expiry, observe `ready`, wait past expiry, and publish a harmless authorized hint.
3. Observe connection state and frames after both attempts.

- Expected: Late authentication never receives `ready`; existing client is destroyed at deadline and receives no post-expiry event.
- Negative/non-disclosure: Close/failure behavior does not reveal claims, signature details, keys, or whether another channel has subscribers.
- Cleanup: Close test connections and stop Workerman.
- Source: `app/Services/Realtime/TicketSigner.php:33-61`; `app/Services/Realtime/Hub.php:45-83`; `app/Services/Realtime/Hub.php:85-123`
- Evidence: Timestamped redacted issue/auth/publish trace and frame log.
- Verdict: `NOT_RUN`

## RT-003 — Tampered or malformed realtime tickets fail identically
- Lifecycle: `READY`
- Legacy IDs: `RT-003`
- Changed by: `bea71cf`
- Requirement: Signature, encoding, version, channel-list, expiry, and shape validation must fail closed with no parsing detail returned to the peer.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: WebSocket authentication.
- Route/API: WebSocket `/` authenticate frame.
- Actor/grants: Test client with one valid ticket used only to derive tampered variants.
- Preconditions: Isolated Workerman and valid allowed origin.

**Steps**
1. Create variants with changed payload byte, signature byte, extra/missing segment, invalid base64url, oversized ticket, bad version, and malformed channel list.
2. Authenticate a fresh socket with each variant from the same origin.
3. Compare frames/closures with a random invalid ticket and with the valid control.

- Expected: Every invalid variant is disconnected without `ready`; only the untouched ticket succeeds.
- Negative/non-disclosure: No failure distinguishes signature, expiry, channel, JSON, or key condition; logs/evidence contain no complete valid ticket.
- Cleanup: Close all sockets and discard ticket material.
- Source: `app/Services/Realtime/TicketSigner.php:33-80`; `app/Services/Realtime/Hub.php:45-83`
- Evidence: Variant/result matrix with ticket hashes only and valid-control frame.
- Verdict: `NOT_RUN`

## RT-004 — Origin binding rejects cross-origin use
- Lifecycle: `READY`
- Legacy IDs: `RT-004`
- Changed by: `bea71cf`, `73b8a11`
- Requirement: Ticket issuance and socket handshake must require an exact configured HTTP origin; ticket claims bind that same origin and HTTPS pages require WSS.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web ticket endpoint and WebSocket handshake.
- Route/API: `POST /realtime/ticket`; WebSocket `/`.
- Actor/grants: Authenticated web identity.
- Preconditions: At least two distinct test origins/hosts and isolated TLS variant where applicable.

**Steps**
1. Request from an unallowed/near-match origin and record the bounded denial.
2. Obtain a valid ticket from allowed origin A, then present it on a socket handshake from origin B and from missing origin.
3. In the HTTPS variant, configure/attempt an insecure `ws://` public URL.

- Expected: Near-match issuance and mismatched/missing handshake fail; exact A succeeds; insecure transport on HTTPS is refused/unavailable.
- Negative/non-disclosure: Client-supplied origin fields cannot override the header, and denials reveal no allowed-origin list or key.
- Cleanup: Close sockets and restore isolated origin configuration.
- Source: `app/Services/Realtime/TicketIssuer.php:24-43`; `app/Services/Realtime/TicketSigner.php:20-58`; `app/Services/Realtime/Hub.php:34-42`
- Evidence: Origin/result matrix and sanitized handshake trace.
- Verdict: `NOT_RUN`

## RT-005 — Queue board refreshes from authoritative GET after a hint
- Lifecycle: `READY`
- Legacy IDs: `RT-005`
- Changed by: `c91477a`, `73b8a11`, `c179273`
- Requirement: A matching `queue.invalidated` event is only a hint; the visible board must refetch its authorized GET, serialize refreshes, and update controls/history without accepting patient data from the event.
- Role: `workstation_member`
- Module: Realtime & Notifications
- Use Cases: `UC-CL-02`, `UC-GD-01`
- Surface: Clinic/guidance staff queue board.
- Route/API: `GET /{unit}/queue/board` plus private realtime event.
- Actor/grants: Staff member authorized for that unit’s queue.
- Preconditions: Two staff sessions, active queue fixture, Workerman running; named-region rollout state recorded.

**Steps**
1. Open the board in session A and mutate the same date/unit queue from session B.
2. Observe the event and resulting GET in A without manual refresh; verify rows, waiting count, call-next state, and no-show drawer against the server.
3. Publish mismatched unit/date and payload-with-fake-patient variants, then issue rapid matching hints during an in-flight GET.

- Expected: Only matching hints trigger a debounced serialized authoritative GET; fake event data never renders; final board equals server state with no duplicate request storm.
- Negative/non-disclosure: Events carry no patient record; unauthorized unit/date content is neither fetched nor shown; region rollout disabled falls back safely.
- Cleanup: Restore queue fixture and close sessions.
- Source: `public/assets/js/queue-realtime.js:1-81`; `public/assets/js/queue-realtime.js:129-205`
- Evidence: Screen recording, socket hint plus GET trace, and final server/UI comparison.
- Verdict: `NOT_RUN`

## RT-006 — Personal queue projection repairs without exposing others
- Lifecycle: `READY`
- Legacy IDs: `RT-006`
- Changed by: `bea71cf`, `c91477a`
- Requirement: Personal queue events must trigger a no-store authorized projection of at most the caller’s two unit tickets, with internally consistent position/ahead/wait fields and periodic reconciliation.
- Role: `student`
- Module: Realtime & Notifications
- Use Cases: `UC-PT-02`
- Surface: Personal portal queue card.
- Route/API: `GET /portal/queue` plus `portal.queue.changed` hint.
- Actor/grants: Linked student/employee viewing own portal.
- Preconditions: Caller has a waiting ticket and another person ahead; second staff session can advance queue.

**Steps**
1. Keep the portal open while staff advances or skips tickets; observe the personal view update without page reload.
2. Compare status, position, people ahead, and estimate with the authoritative endpoint for waiting/called/in-consultation/skipped variants.
3. Stop realtime or hide/restore the page, change the queue, and wait for repair/resume refresh.

- Expected: Own ticket projection repairs via hint or 15-second fallback/resume; consistency rules hold and access loss clears private content.
- Negative/non-disclosure: Other patients’ names, ticket codes, identities, priorities, or records never appear; hints contain no private queue data.
- Cleanup: Restore queue fixture and close sessions.
- Source: `public/assets/js/queue-live.js:16-78`; `public/assets/js/queue-live.js:131-190`
- Evidence: Before/after portal captures, endpoint comparison, and network/socket timeline.
- Verdict: `NOT_RUN`

## RT-007 — Team mention notification targets exact current member
- Lifecycle: `READY`
- Legacy IDs: `RT-007`
- Changed by: `e08f383`, `73b8a11`, `c179273`
- Requirement: A valid immutable team-chat mention must create an exact user-addressed notice linked to its source message and authorized unit; current membership is rechecked for inbox display and deep link.
- Role: `workstation_member`
- Module: Realtime & Notifications
- Use Cases: `UC-TW-03`
- Surface: Team chat and web/mobile notification inbox.
- Route/API: `POST /api/v1/team/{unit}/chat/messages`; notification endpoints.
- Actor/grants: Sender and mentioned active members of the same tenant/unit.
- Preconditions: Two members, a message with validated UTF-16 mention offsets, realtime/poll fallback available.

**Steps**
1. Send a message mentioning member B and inspect B’s unread inbox title/target and deep link to the exact message.
2. Verify a nonmentioned member and member in another unit/tenant receive no notice.
3. Remove B’s unit access and refresh inbox/deep link; attempt malformed offsets and a nonmember ID.

- Expected: One notification is bound to B/source unit/message; authorized deep link opens it; current access revocation withholds it; malformed/nonmember mentions reject or create no notice.
- Negative/non-disclosure: Message body is not copied into the notification projection, recipient lists do not leak, and sender cannot forge another unit/tenant target.
- Cleanup: Delete synthetic message/notification through DB retirement; restore role if changed.
- Source: `app/Services/NotificationService.php:261-314`; `app/Services/NotificationService.php:559-574`
- Evidence: Redacted sender/recipient inbox matrix and deep-link target trace.
- Verdict: `NOT_RUN`

## RT-008 — Reconciliation repairs disconnect gaps
- Lifecycle: `READY`
- Legacy IDs: `RT-008`
- Changed by: `bea71cf`, `c91477a`, `73b8a11`
- Requirement: Realtime is an optimization only; disconnect, reconnect, visibility, and page-cache gaps must be repaired by authorized polling/refetch without replaying stale event payloads.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Queue board, personal queue, notifications, and active Team resources.
- Route/API: Relevant authoritative GET endpoints plus realtime status events.
- Actor/grants: Authorized web or mobile user for the resource under test.
- Preconditions: Workerman can be stopped/restarted in isolation; deterministic state-changing second session.

**Steps**
1. Open a live resource, stop realtime, change server state from a second session, and keep the first view open.
2. Wait the documented disconnected polling interval or background/foreground the client; verify it converges to server state.
3. Restart realtime, change state during reconnect, and confirm the first-ready invalidation closes the gap once.

- Expected: Views converge through poll/resume/reconnect GETs; backoff is bounded; no manual refresh or duplicate mutation is needed.
- Negative/non-disclosure: Repair requests retain current authority and clear/deny content if access changed; stale hints never overwrite authoritative state.
- Cleanup: Restart standard isolated Workerman, restore fixture, and close clients.
- Source: `public/assets/js/realtime.js:45-184`; `public/assets/js/queue-realtime.js:146-204`; `app/Services/NotificationInvalidation.php:56-100`
- Evidence: Outage timeline, polling/reconnect requests, and server/UI convergence capture.
- Verdict: `NOT_RUN`

## RT-009 — Private channel isolation survives selector and publisher abuse
- Lifecycle: `READY`
- Legacy IDs: `RT-009`
- Changed by: `bea71cf`
- Requirement: Clients cannot subscribe after authentication or choose channels; publisher access requires the separate bearer key, empty Origin, exact `/publish`, bounded three-field body, and events fan out only to ticket-granted channels.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: WebSocket and internal publish listener.
- Route/API: WebSocket `/`; publisher `POST /publish`.
- Actor/grants: Two user clients; isolated internal publisher harness.
- Preconditions: Workerman configured with synthetic keys and three channel fixtures.

**Steps**
1. Authenticate users A and B, then send subscribe/channel-change/second-auth/unknown client messages from A.
2. Attempt publisher calls with browser Origin, wrong/missing key, wrong path/method, extra fields, invalid channel/event, and oversized body.
3. Publish valid distinct hints to A, B, and another tenant channel and record recipients.

- Expected: Unsupported client messages disconnect rather than alter grants; invalid publisher calls fail; each valid event reaches only clients whose signed ticket grants that channel.
- Negative/non-disclosure: Subscriber counts, channel existence, keys, tickets, and other-user event data are not disclosed.
- Cleanup: Close connections and destroy synthetic keys/config.
- Source: `app/Services/Realtime/Hub.php:45-101`; `app/Services/Realtime/PublishEndpoint.php:17-39`; `app/Services/Realtime/EventMessage.php:9-22`
- Evidence: Redacted abuse matrix and per-channel recipient log.
- Verdict: `NOT_RUN`

## RT-010 — Mobile grant is own-channel only and rate bounded
- Lifecycle: `READY`
- Legacy IDs: `RT-010`
- Changed by: `bea71cf`, `c91477a`
- Requirement: Fresh bearer identity must receive at most its exact private user channel, no department/AI topics, from an allowed origin; forged selectors are ignored and per-user grant requests are throttled.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Mobile app realtime client.
- Route/API: `GET /api/v1/realtime/grant`.
- Actor/grants: Active mobile bearer user.
- Preconditions: Mobile client/simulator, allowed API origin, isolated Workerman, two accounts.

**Steps**
1. Request a grant with forged tenant/user/channel/role selectors and compare returned channel with authenticated identity.
2. Connect and publish user-specific hints for both accounts plus a department/AI channel.
3. Exceed the documented 60-per-60-second user budget in a bounded harness and test invalid origin.

- Expected: Grant names only `private.tenant.{ownTenant}.user.{ownUser}`; only own hints arrive; rate limit returns `429`; invalid origin returns `403`.
- Negative/non-disclosure: No AI/department wildcard, other-account channel, grant key, or data payload is exposed.
- Cleanup: Close sockets, revoke token family, and reset throttler fixture.
- Source: `app/Controllers/Api/V1/RealtimeGrantController.php:12-34`; `app/Services/Realtime/UserTicketIssuer.php:11-54`
- Evidence: Redacted grant metadata, delivery matrix, and throttle response.
- Verdict: `NOT_RUN`

## RT-NT-001 — Inbox and history share current recipient authority
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `e08f383`, `c179273`
- Requirement: Web bell, web history, and mobile inbox must use the same current person/user/resource authority; history is paginated current-resource visibility, not a raw immutable notification dump.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web/mobile notifications.
- Route/API: `GET /notifications`; `GET /notifications/history`; `GET /api/v1/notifications`; `GET /api/v1/notifications/history`.
- Actor/grants: Active linked user with personal and/or department notices.
- Preconditions: Fixtures for appointment, referral, announcement, survey, team, chat, and workflow notices; one source resource later becomes unauthorized.

**Steps**
1. Compare web bell, web page 1/all, mobile inbox, and mobile page 1/all for IDs, titles, read state, and targets.
2. Revoke audience/member/resource authority for selected source notices and refresh all surfaces.
3. Exercise unread filter and additional pages around the changed rows.

- Expected: All surfaces expose the same currently authorized IDs; inaccessible source-backed notices disappear from list/count/read authority; pagination remains 20 rows and stable.
- Negative/non-disclosure: Historical notification rows do not bypass current grants; null-person identities receive only explicitly addressed eligible notices.
- Cleanup: Restore role only if needed, then retire DB.
- Source: `app/Services/NotificationService.php:192-248`; `app/Services/NotificationService.php:471-536`
- Evidence: Cross-surface ID matrix and before/after authority screenshots.
- Verdict: `NOT_RUN`

## RT-NT-002 — Mark-read operations cannot cross recipient scope
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Individual and read-all operations must update only currently visible notices belonging to the caller, use POST, return no content or refreshed own inbox, and publish optional post-commit invalidation.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web/mobile notification read controls.
- Route/API: `POST /notifications/{id}/read`; `POST /notifications/read-all`; mobile equivalents.
- Actor/grants: Two active users with separate notices.
- Preconditions: Each user has unread notices; one notice later loses current authority.

**Steps**
1. Mark one own notification read on web and verify mobile reconciliation/read timestamp.
2. Attempt to mark another user’s ID and an authority-revoked own row, then compare database rows.
3. Run read-all and verify only caller’s current inbox rows change and unread count reaches zero.

- Expected: Own current row/read-all succeeds idempotently; foreign/revoked ID returns not found; other/revoked rows remain unchanged; optional hint follows commit only.
- Negative/non-disclosure: Response cannot distinguish foreign from nonexistent ID or expose recipient/source data; GET mutation is refused.
- Cleanup: Retire notification fixture DB.
- Source: `app/Controllers/NotificationController.php:29-75`; `app/Services/NotificationService.php:317-368`
- Evidence: HTTP matrix, before/after read timestamps, and realtime/poll reconciliation trace.
- Verdict: `NOT_RUN`

## RT-NT-003 — Notification targets are an allowlisted projection
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `c179273`
- Requirement: Notification clients must receive only typed target descriptors and server-allowlisted same-application URLs derived from valid source columns; arbitrary stored URLs or malformed IDs must not become navigation.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web notification links and mobile notification targets.
- Route/API: Notification inbox endpoints and client router.
- Actor/grants: Authorized recipient.
- Preconditions: Synthetic notices for each supported target plus malformed/unsupported source variants in disposable DB.

**Steps**
1. Fetch supported appointment, announcement, survey, team task/event/chat notices and exercise each web/mobile target.
2. Inspect returned target shape and actual destination against current authority.
3. Test null/negative IDs, unsupported type, cross-unit target, and injected URL-like text in source content.

- Expected: Only supported typed IDs/unit values create targets; routes resolve through fixed destinations; invalid/unsupported notices have no actionable target.
- Negative/non-disclosure: No `javascript:`, external origin, arbitrary DB URL, hidden message body, or unauthorized resource opens.
- Cleanup: Retire DB and clear mobile navigation intents.
- Source: `app/Services/NotificationService.php:26-54`; `app/Services/NotificationService.php:420-469`
- Evidence: Target/destination matrix and blocked-variant captures.
- Verdict: `NOT_RUN`

## RT-NT-004 — Notification invalidation is post-commit, authorized, and bounded
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `73b8a11`
- Requirement: `notifications.changed` is an empty hint sent only after outer commit, only to reauthorized exact recipients, within one second/four attempts; storage or transport failure must not roll back the owning write.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Server notification workflow and clients.
- Route/API: Internal publisher plus authoritative inbox GETs.
- Actor/grants: Synthetic transaction writers and recipients.
- Preconditions: Disposable DB, injectable publisher failure, multiple recipients including revoked authority.

**Steps**
1. Create notices inside a nested/open transaction and call invalidation before commit; verify no hint.
2. Commit, invoke with committed IDs, and record eligible recipients and empty event data under the attempt/time budget.
3. Force one permission lookup and publisher failure, then verify the notification write remains committed and clients repair by polling.

- Expected: Only post-commit current recipients get an empty hint; duplicate IDs are normalized; bounds stop work; failure is swallowed for reconciliation.
- Negative/non-disclosure: Hint contains no notification IDs/body/count or audience list and never targets foreign tenant/user.
- Cleanup: Stop injected publisher and retire DB.
- Source: `app/Services/NotificationInvalidation.php:15-100`
- Evidence: Transaction/publish timeline, recipient list by synthetic IDs, failure trace, and committed-row proof.
- Verdict: `NOT_RUN`

## RT-NT-005 — Named-region refresh preserves edits or fails safely
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `c91477a`, `c179273`, `884b9f0`
- Requirement: When explicitly rollout-enabled per owner, region refresh must validate unique owner/regions, same-origin safe HTML and navigation scope, preserve tabs/scroll/focus, block on edits, ignore stale responses, and escalate security ambiguity to full navigation; rollout policy remains unresolved.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Rollout-enabled web workspaces.
- Route/API: Authorized GET for each named owner/region following mutation or realtime hint.
- Actor/grants: Authorized user for selected owner.
- Preconditions: `refreshRegions.enabled` and only the test owner allowlisted; page has deterministic tabs, scroll, focus, and dirty form.

**Steps**
1. Trigger a region refresh with clean state and verify only named content changes while tab, scroll, focus, CSRF, lifecycle, and history behavior are preserved.
2. Edit within the region, trigger refresh, and verify it blocks with recoverable warning rather than overwriting.
3. Race two responses and test duplicate/missing region, unsafe markup, auth redirect, and changed navigation scope.

- Expected: Clean current response swaps atomically; dirty/stale/invalid content is blocked/ignored; security cases force safe full-document handling; owner-disabled state uses legacy fallback.
- Negative/non-disclosure: No scripts/iframes/event handlers/`javascript:` content execute, no cross-origin fetch occurs, and unrelated regions/forms are untouched.
- Cleanup: Disable owner rollout and clear synthetic edits.
- Source: `app/Config/RefreshRegions.php:9-43`; `public/assets/js/refresh-regions.js:14-125`; `public/assets/js/refresh-regions.js:274-415`
- Evidence: Screen recording, refresh telemetry, DOM-region diff, and negative-case matrix.
- Verdict: `NOT_RUN`

## RT-NT-006 — Realtime lifecycle pauses, backs off, and clears private state
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `c91477a`, `73b8a11`
- Requirement: Browser/mobile realtime clients must use no-store grants, authenticate within deadline, pause when hidden/backgrounded, reconnect with bounded jittered exponential backoff, invalidate after reconnect gaps, and clear private state on unauthorized/session/account change.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Browser and React Native realtime lifecycle.
- Route/API: Ticket/grant endpoints, WebSocket, authoritative resource endpoints.
- Actor/grants: Authenticated user with one live resource.
- Preconditions: Controllable visibility/app state, network, Workerman, and role/token changes.

**Steps**
1. Observe initial connect/ready, then background/hide the client and confirm socket/timers pause; change server state while paused.
2. Foreground/show with server unavailable, observe bounded retries, restore server, and verify immediate authoritative invalidation repairs the gap.
3. Revoke the role/token or switch account/server during retry and inspect sockets, timers, cache, and private UI.

- Expected: No active work while suspended; retries cap around 30 seconds with jitter; resume/reconnect repairs once; unauthorized/account change stops old generation and clears private content.
- Negative/non-disclosure: Credentials never appear in socket URLs/logs, old-account events/cache cannot reach the new account/server, and stale callbacks have no side effects.
- Cleanup: Restore network/role in disposable DB, sign out, and close Workerman.
- Source: `public/assets/js/realtime.js:1-185`; `mobile/src/features/realtime/useUserRealtime.ts:1-32`
- Evidence: Lifecycle timeline, connection/status logs without tickets, cache/UI captures, and account-isolation proof.
- Verdict: `NOT_RUN`
