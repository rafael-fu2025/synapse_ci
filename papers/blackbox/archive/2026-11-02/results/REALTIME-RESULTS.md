# Black-box test results — Real-Time (RT-001 … RT-009, web scope)

- **Module:** Real-Time (realtime grants, websocket admission, live queue updates)
- **Run date:** 2026-11-02 (Asia/Manila)
- **App under test:** `http://localhost:8080/index.php` (local dev worktree, post-remediation build)
- **Realtime transport:** `spark realtime:serve` (websocket on `0.0.0.0:8081`), configured in `.env`
  with `realtime.enabled = true`, `realtime.ticketTtl = 60`, `realtime.allowedOrigins` listing
  `http://localhost:8080` and the dedicated `*.localhost:8080` hosts.
- **Browser:** in-app Browser, **single tab** throughout (`navigate` + `replaceCurrentTab: true`).
- **Server-side probes:** a Node script using the project's own `ws` client
  (`mobile/node_modules/ws`) and a PHP/curl helper under `writable/`, used for the crafted-ticket
  cases the case list explicitly asks for, and for the second session of the two-session cases.

> Scope note: **RT-010 (mobile realtime grant) is excluded** from this run — the mobile app is out of
> scope for the web suite (as with MB-001 … MB-013).

---

### RT-001 — WebSocket connect with valid grant

**Verdict:** PASS

**Steps observed:** Signed in as `[REDACTED: synthetic account identifier]`, requested a realtime ticket from the page's own
endpoint (`POST /index.php/realtime/ticket`, same-origin, with the CSRF header the client sends), then
opened the socket at the URL the ticket response names and sent the `authenticate` frame the client
sends.

**Evidence:**
- `POST /realtime/ticket` → **HTTP 200** with
  `url = ws://localhost:8081`, `expires_at` 60 s ahead, ticket claims
  `v=1, exp=<now+60>, origin=http://localhost:8080`.
- **Channels granted: `["[REDACTED: private user channel identifier]"]`** — the signed-in user's own private channel
  (`user [REDACTED: account record identifier]` = `[REDACTED: synthetic account identifier]`, tenant 1).
- Socket response: **`{"type":"ready"}`** → the connection is **admitted**.

**Notes:** The ticket is issued only for the caller's own channel; no role wildcard or
client-selected channel is present in the payload.

---

### RT-002 — Expired ticket rejected

**Verdict:** PASS

**Steps observed:** Captured a ticket, waited out its TTL (63 s), then connected with the expired
ticket.

**Evidence:** The socket was **rejected** — closed with code **1006** and **no `ready` frame**
(`frames = []`). The ticket's `exp` had passed, so the signature check cannot rescue it.

**Notes:** Ticket TTL is 60 s (`realtime.ticketTtl`); the signer refuses a claim whose `exp` is not in
the future.

---

### RT-003 — Tampered ticket rejected

**Verdict:** PASS

**Steps observed:** Took a captured, valid ticket and altered the signature tail
(`…[REDACTED: realtime ticket signature fragment]` → `…[REDACTED: tampered realtime ticket signature fragment]`), then connected with it.

**Evidence:** The socket was **rejected** — closed with **1006**, **no `ready` frame**.

**Notes:** The payload/signature pair is HMAC-SHA256 over the base64url payload; altering either part
invalidates the ticket.

---

### RT-004 — Cross-origin ticket rejected

**Verdict:** PASS

**Steps observed:** (a) Requested a ticket while sending `Origin: http://evil.example.com`;
(b) took a ticket legitimately issued for `http://localhost:8080` and presented it from
`http://evil.example.com`.

**Evidence:**
- (a) `POST /realtime/ticket` with the foreign Origin → **HTTP 403**
  `{"message":"Realtime origin not allowed."}` — no ticket is issued.
- (b) Connecting with the valid ticket but the foreign Origin → **rejected**, the websocket
  handshake failed with **"Unexpected server response: 403"**, no `ready` frame.

**Notes:** The ticket embeds the issuing origin and the hub refuses a handshake whose `Origin` is not
in the allow-list, so a ticket cannot be replayed from another origin.

---

### RT-005 — Live queue board update

**Verdict:** PASS

**Steps observed:**
1. Browser session A: signed in as `[REDACTED: synthetic account identifier]` and opened `…/index.php/clinic/queue`
   ("University Clinic Queue Board — Daily queue · Refreshes automatically").
2. Session B (separate HTTP session for the same role): advanced the clinic queue
   (`POST /clinic/queue/call-next`) while session A stayed open.

**Evidence:** The open board changed **without any manual refresh and without a navigation**
(no page load occurred; the URL stayed `…/clinic/queue`):

| Board region | Before (session B's call) | After |
| --- | --- | --- |
| Waiting | **1** — [REDACTED: queue ticket identifier] [REDACTED: synthetic person display name] (`[REDACTED: synthetic personnel identifier]`) | **0** — "No patients waiting" |
| Called & Arriving | **0** — "No active calls" | **1** — [REDACTED: queue ticket identifier], *"Called: 02:37 AM"* |
| Waiting Line | 1 | 0 |

The board's own refresh mechanism was visible in the network log: repeated
`GET /index.php/clinic/queue/board` (XHR, `text/html`) requests, e.g. sequences 2317 and 2320,
after the queue changed.

**Notes:** The advancing session was a different session from the observing one, exactly as the case
requires ("advance the queue from the first session").

---

### RT-006 — Personal queue hint

**Verdict:** PASS

**Steps observed:**
1. Browser (signed in as `[REDACTED: synthetic account identifier]`): opened `…/index.php/portal` and read the
   "LIVE WAITING ROOM TICKET" card for the clinic ticket **[REDACTED: queue ticket identifier]**.
2. A separate clinic-staff session changed the queue (called the ticket ahead of the student, then
   skipped it so it left the line ahead).
3. The browser was **not** reloaded — it was only waited on — and the card was read again.

**Evidence:**
- Before: [REDACTED: queue ticket identifier] — *"University Clinic · Status: Waiting"*, **Your place in line #2**, **People ahead 1**,
  estimated wait *"Not enough recent data"*.
- After (no navigation, no reload): [REDACTED: queue ticket identifier] — **Your place in line #1**, **People ahead 0**, estimated wait
  **"You're next"**.
- The network log shows the personal view re-fetching its feed on a ~15 s cycle:
  `GET /index.php/portal/queue` (fetch, `application/json`, 200) at sequences 2019, 2020, 2024, 2025,
  2026, 2027, 2031, 2032 — i.e. the card was repaired by a background re-fetch, not a page load.
- The feed itself returned the new position:
  `{"unit":"clinic","ticket_code":"[REDACTED: queue ticket identifier]","status":"waiting","position":2,"people_ahead":1,…}` before
  and `position:1, people_ahead:0` afterwards.

**Notes:** The first queue change (calling the ticket ahead) deliberately did **not** move the
student, because the portal documents that *"Position includes people already called or being seen
ahead of you."* The student's position therefore moved once the ticket ahead left the line — the
behaviour the case describes.

---

### RT-007 — Mention notification

**Verdict:** PASS

**Steps observed:**
1. Browser (signed in as `[REDACTED: synthetic account identifier]`) opened `…/index.php/clinic/teams/chat`
   ("Clinic Team Chat → # Clinic operations"), typed `@` in the composer so the mention picker opened,
   selected **@[REDACTED: synthetic staff display name]** from the picker, typed the rest of the message and pressed **Send**.
2. The recipient's notification feed was then read for `[REDACTED: synthetic account identifier]`.

**Evidence:**
- The message was stored as **message id [REDACTED: message identifier]**, unit `clinic`, sender `user [REDACTED: account record identifier]` ([REDACTED: synthetic account identifier]),
  body *"@[REDACTED: synthetic staff display name]  black-box RT-007: please confirm the mention notification reaches you."*,
  `mentions = [{"user_id":3,"start":0,"length":18}]` — a UTF-16 range bound to the mentioned member.
- A notification was created: **id 316**, `type = team_chat_mentioned`, `recipient_user_id = [REDACTED: account record identifier]`
  ([REDACTED: synthetic account identifier]), `chat_unit = clinic`, `chat_message_id = 13`, **`read_at = null` (unread)**.
- The recipient's feed (`GET /notifications`, the same feed the notification inbox renders) contains:
  `{"id":"[REDACTED: notification identifier]","title":"Team chat mention","message":"You were mentioned in your team chat. Open the
  conversation to view the message.","created_at":"2026-11-02T02:41:27+08:00","read_at":null,
  "url":"…/clinic/teams/chat?message=[REDACTED: message identifier]#chat-message-13"}`
  — the mention is named, it links to the exact message, and it is unread.

**Notes:** The unread *indicator* (the "Unread" badge plus the *Mark read* control) is rendered by the
inbox from this same feed; that rendering was observed directly in PT-011 (eight unread notifications
each showing "Unread" and a *Mark read* button).

---

### RT-008 — Reconciliation after disconnect

**Verdict:** PASS

**Steps observed:**
1. The clinic queue board was left open in the browser (session A).
2. The **realtime push server** was stopped (`php spark realtime:serve start`, pid 17336) so the push
   channel dropped — `http://localhost:8081/` stopped serving.
3. From a separate session, the queue was changed (`POST /clinic/queue/recall/38`, recalling the
   skipped ticket [REDACTED: queue ticket identifier] back into the waiting line).
4. The browser was only waited on (22 s) and the board was read again — no reload.
5. The realtime push server was restarted afterwards.

**Evidence:** The board repaired itself to the current state without a manual refresh:

| Board region | While the push channel was down, before | After the queue change |
| --- | --- | --- |
| Waiting | 0 | **1** — [REDACTED: queue ticket identifier] with a *Call Patient* action |
| Waiting Line | 0 — "No patients waiting" | **1** — [REDACTED: queue ticket identifier] |
| Skipped | 1 — [REDACTED: queue ticket identifier] | **0** — "No skipped patients" |
| Called & Arriving | 1 — [REDACTED: queue ticket identifier] | 1 — [REDACTED: queue ticket identifier] (unchanged) |

The push server was confirmed reachable again after the restart (`http://localhost:8081/` answering),
and the realtime client is designed to reconnect with exponential backoff.

**Notes:** The environment was left as found — the realtime push server was restarted with the same
command (`php spark realtime:serve start`, new pid) after the check. The isolation used for AU-010
(a temporary instance on port 8081 with the MIS flag disabled) was also stopped.

---

### RT-009 — Channel isolation

**Verdict:** PASS

**Steps observed:** Requested a realtime ticket while crafting request parameters that name somebody
else's channel (`channel=tenant:1:user:[REDACTED: crafted account identifier]`, `user_id=[REDACTED: crafted account identifier]`, `channels=tenant:1:user:[REDACTED: crafted account identifier]`,
`subscribe=tenant:1:user:[REDACTED: crafted account identifier]`), then connected with the issued ticket.

**Evidence:**
- The ticket endpoint **ignored every client-supplied selector** and granted only
  **`channels = ["[REDACTED: private user channel identifier]"]`** — the caller's own private channel (user [REDACTED: account record identifier] =
  [REDACTED: synthetic account identifier]). The crafted values never appear in the issued claims.
- The socket was admitted (`{"type":"ready"}`) and subscribed to that single own-channel only.
- Corroborating behaviour from the same run: a ticket request with **no session** at all is refused
  with **HTTP 401 `{"message":"Please sign in to continue."}`**.

**Notes:** The grant is derived server-side from the validated identity (`Channel::user($tenant, $user)`
plus any AI-insight topics the actor is entitled to); the request can never influence the channel set.

---

## Test data created / changed

| What | Identifier | Detail | Where |
| --- | --- | --- | --- |
| Guidance walk-in | queue entry **[REDACTED: queue-entry identifier]** ([REDACTED: queue ticket identifier]) | Issued for the test student on 2026-11-02 | `queue_entries` |
| Clinic walk-ins | queue entries **[REDACTED: queue-entry identifier]** ([REDACTED: queue ticket identifier], person [REDACTED: person record identifier]), **[REDACTED: queue-entry identifier]** ([REDACTED: queue ticket identifier], student) | Issued for the live-update cases; [REDACTED: queue ticket identifier] called → skipped → recalled, [REDACTED: queue ticket identifier] called | `queue_entries` |
| Team chat message | message **[REDACTED: message identifier]** | "@[REDACTED: synthetic staff display name]  black-box RT-007: please confirm the mention notification reaches you." (mentions user [REDACTED: account record identifier]) | `team_chat_messages` |
| Mention notification | notification **[REDACTED: notification identifier]** | `team_chat_mentioned` → `recipient_user_id = [REDACTED: account record identifier]`, unread | `notifications` |
| Realtime push server | process | stopped and restarted for RT-008 (same command; new pid) | process, not data |

---

## Summary

| ID | Verdict | One-line reason |
| --- | --- | --- |
| RT-001 | PASS | Valid ticket → HTTP 200, `ws://localhost:8081`, grants `[REDACTED: private user channel identifier]`, socket returns `{"type":"ready"}`. |
| RT-002 | PASS | Ticket used after its 60 s TTL → connection closed (1006) with no `ready` frame. |
| RT-003 | PASS | Signature altered → connection closed (1006) with no `ready` frame. |
| RT-004 | PASS | Foreign `Origin` → ticket refused with HTTP 403 "Realtime origin not allowed."; a valid ticket replayed from another origin is rejected at the handshake. |
| RT-005 | PASS | Queue advanced from a second session; the open board moved [REDACTED: queue ticket identifier] from Waiting to "Called & Arriving" with no refresh (board re-fetched via `GET /clinic/queue/board`). |
| RT-006 | PASS | The personal card moved from #2 / 1 ahead to #1 / 0 ahead ("You're next") with no reload, via the ~15 s `GET /portal/queue` re-fetch. |
| RT-007 | PASS | [REDACTED: communication record identifier] mentions user [REDACTED: account record identifier]; notification [REDACTED: notification identifier] "Team chat mention" is delivered to that user, unread, linking to the message. |
| RT-008 | PASS | With the push server stopped and the queue changed from another session, the open board repaired itself (Waiting 0→1, Skipped 1→0) via the scheduled poll. |
| RT-009 | PASS | Crafted channel/user selectors in the grant request are ignored; the ticket names only the caller's own channel. |

**Totals: 9 PASS · 0 FAIL · 0 BLOCKED** (RT-010 excluded — mobile scope)
