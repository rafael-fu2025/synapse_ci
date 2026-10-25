# Realtime foundation (Workerman)

Workerman is an optional, self-hosted PHP WebSocket transport alongside CodeIgniter.
It is **disabled by default**. MIS synchronization progress, staff queue boards,
personal ticket cards, dashboard queue counts, notification invalidation, and Teams chat invalidation use WebSocket
hints when available. HTTP remains authoritative; notification reconciliation runs
every 30s disconnected / 60s connected, queue every 15s disconnected / 60s
connected, and chat every 5s disconnected / 60s connected. The 60s checks recover
from dropped events; Searches, filters, and live tables remain HTTP-driven.

## Included now

- `php spark realtime:serve start`: foreground server, one worker/event loop.
- A loopback-only HTTP publisher for trusted application processes.
- `POST /realtime/ticket`: authenticated, CSRF-protected, no-store ticket issuance.
  Identity is refreshed against the database using the existing authentication
  and portal-host filters. No client-supplied identity/channel grants are accepted.
- Short-lived, HMAC-signed grants bound to an exact browser origin. Tickets travel
  in the first WebSocket message, never a query string or browser storage.
- Each browser receives only `private.tenant.{tenantId}.user.{userId}`. Even
  superadmin receives no wildcard. No BMG device or department subscriptions yet.
- Browser reconnect with capped jitter backoff, timeout/abort handling, CSRF
  rotation, and visibility/BFCache lifecycle handling. No hidden-tab sockets.
- Connection, message, authentication-timeout, and slow-reader limits. Tickets
  expire after 60 seconds by default; the socket is disconnected at expiry and
  the browser obtains a fresh grant through CodeIgniter.
- The configured socket origin is added to the framework's CSP `connect-src`
  only when realtime configuration validates successfully.

## Application events

- **MIS synchronization:** `mis.sync.progress` hints are sent to the requesting
  tenant/user during the operation. Final success still requires the HTTP response.
- **Queue:** `queue.invalidated` sends only `{unit, date}` to users currently
  authorized to read that department queue. The browser refreshes its selected date
  via the existing authorized board endpoint; hidden tabs do not poll. Clinic and
  Guidance dashboard queue counters consume the same event, refreshing only their
  count elements through `GET /{unit}/dashboard/queue-counts`. These endpoints recheck
  all existing dashboard permissions, not just queue-read access. Existing triage
  and activity charts, appointment statistics, and case/encounter data are unchanged.
- **Personal ticket cards:** `portal.queue.changed` is a payload-free hint sent only
  to active same-tenant accounts freshly linked to the changed ticket's person and
  authorized for the combined personal portal. It never broadcasts patient IDs,
  ticket codes, statuses, dates, or department names. `GET /portal/queue` derives
  person and tenant from the fresh session identity (never request selectors), and
  returns only that person's active tickets today, including skipped tickets. A
  completed/no-show ticket disappears; an empty view can show a newly issued ticket.
  Both projections use 15s disconnected / 60s connected reconciliation and refetch
  on reconnect, visibility restoration, and initial load. Hints are captured inside
  the queue transaction and delivered only after its owner commits.
- **Appointment list:** `portal.appointments.changed` is a payload-free hint sent
  to the affected person's own accounts (same authorization as the personal ticket
  hint) after an appointment is requested, booked, confirmed or cancelled. The
  portal's *My Appointments* region (`data-live-events` on a `data-live-filters`
  section) then refetches itself over HTTP; it also refreshes on `portal.queue.changed`,
  which covers check-in, completion and no-show. The staff appointments view
  (`/{unit}/appointments`) also opts into `data-live-events="notifications.changed queue.invalidated"`,
  silently refreshing pending requests and the scheduled calendar when students submit
  requests, cancel, or when appointments are confirmed/checked in. Refreshes are silent
  and are skipped while a filter is being edited, a dialog is open, or unsaved edits exist.
  A visible-tab poll reconciles missed hints (30s disconnected / 120s connected).
- **Engagement feeds (Announcements & Surveys):** Student feeds on `/portal/announcements`
  and `/portal/surveys` subscribe to `data-live-events="notifications.changed"`. When
  an announcement or survey is published, or an announcement is marked read, the feed
  silently updates in real time with the same visible-tab polling fallback.
- **Notifications:** `notifications.changed` has an empty payload and is sent only
  to current recipients who may read their inbox. The notification list remains an
  authorized HTTP fetch.
- **Teams chat:** `team.chat.changed` sends only `{unit}` to current department
  portal members. Message contents and read state remain HTTP-only.

Business-write events are best-effort, emitted after successful transaction commits,
and fanout is deliberately bounded. There is no durable outbox, so a missing hint is
repaired by the slower periodic HTTP reconciliation. Realtime never replaces
server-side authorization or transports sensitive clinical/counseling data.

## MIS synchronization progress

The MIS reference synchronization page uses `mis.sync.progress` events on the
requesting actor's tenant/user channel. A validated `progress_id` correlates each
POST with its own events; browsers cannot choose the target channel. Progress
reports completed stages (catalog fetches, validation, saving, commit), not elapsed
time. Observers run outside database transactions, and 100% is emitted only after
commit. Publishing is best-effort with a short timeout and stops after its first
failure, so realtime availability cannot fail a synchronization.

The browser waits for the POST response before announcing final success and then
refreshes the local status table. A missing/disconnected realtime transport falls
back to a waiting indicator, not invented percentages. A lost mutation response
is treated as an unconfirmed outcome and is never automatically retried. This is
not a background job: a server restart or HTTP request termination can still
interrupt it. Reconnection cannot replay missed progress, and long requests on
a single-worker PHP development server may delay ticket renewal.

## Local setup

Install the lockfile dependencies with `composer install`. Workerman is already
in `composer.json` and `composer.lock`.

Generate two independent keys by running this command **twice**:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Put the generated values in private `.env` configuration (never commit them):

```ini
realtime.enabled = true
realtime.signingKey = 'YOUR_FIRST_GENERATED_SECRET'
realtime.publishKey = 'YOUR_SECOND_GENERATED_SECRET'
realtime.allowedOrigins = 'http://localhost:8080'
realtime.listen = 'websocket://127.0.0.1:8081'
realtime.publishListen = 'http://127.0.0.1:8082'
realtime.publicUrl = ''
```

### Other devices on the LAN

`publicUrl` is the address the *browser* connects to. `127.0.0.1` there would point
each visitor at their own machine, so websockets silently fail and the UI falls back to
polling. Leave `realtime.publicUrl` empty (the default) and the server derives
`ws://{origin host}:{listen port}` for each allowed `http://` origin; the CSP
`connect-src` list is derived the same way. For a LAN test:

```ini
realtime.allowedOrigins = 'http://192.168.1.8:8080,http://localhost:8080'
realtime.listen = 'websocket://0.0.0.0:8081'
```

- Open the site from other devices via the host's address (`http://192.168.1.8:8080`),
  and list every such origin exactly. The origin check is not relaxed.
- Allow inbound TCP 8081 for `php.exe` in Windows Firewall (private network only). The
  publisher on 8082 must stay on loopback.
- A DHCP address change requires updating `allowedOrigins`; a stable hostname avoids this.
- HTTPS portals have no derivation: set an explicit `wss://` `publicUrl` (reverse proxy).
  Configuration with an HTTPS origin and an empty `publicUrl` is rejected.

Use the **actual browser origin**, including port, with no trailing slash. For
multiple portal hosts, list their exact origins separated by commas. `localhost`
and `127.0.0.1` are different origins. HTTPS portals require a `wss://` endpoint.
The signing and publishing keys must differ and each have at least 32 bytes.

Start the server in a separate terminal:

```sh
php spark realtime:serve start
```

Keep the normal CodeIgniter web server running independently. Stop Workerman
with Ctrl+C. This command intentionally supports foreground/start only; use a
process supervisor for production rather than daemon flags.

A native Windows transport smoke test passes with PHP 8.3 and Workerman 5.2.3.
The single-worker implementation also accommodates Windows' runtime limitations;
Linux/WSL2/Docker remains the recommended deployment-like development environment.

Sign in, open the browser Network WebSocket panel, and look for the socket and a
`{"type":"ready"}` response. In the console, `SynapseRealtime.status` reports
`ready` once authenticated. No opt-in meta tag is emitted while disabled, so no
ticket request or WebSocket is attempted. Config changes require restarting the
Workerman process and reloading the browser page.

## Publishing and consuming events

Only trusted PHP code can publish. For example, **after the outermost database
transaction commits successfully**, use the actual recipient's IDs:

```php
use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;

$accepted = (new Publisher())->publish(
    Channel::user($tenantId, $recipientUserId),
    'notifications.changed',
    []
);
```

`true` means the server accepted the event (or realtime was disabled), **not**
that a browser received or processed it. Failures return `false` and log a generic
error without breaking business operations. The shared HTTP publisher defaults to
a 2-second timeout; these feature invalidators use private shorter timeouts and
strictly bounded synchronous fanout, accepting that some recipients may miss a hint.
Add an outbox/background worker if reliable or large-volume delivery becomes
necessary. Never publish before commit or from a transaction callback that might
still roll back.

Browser consumers subscribe to the local event bus, not arbitrary socket channels:

```js
document.addEventListener('synapse:realtime-event', ({ detail }) => {
  if (detail.event === 'notifications.changed') {
    // Refetch authorized notification JSON using the existing HTTP endpoint.
  }
});

document.addEventListener('synapse:realtime-status', ({ detail }) => {
  // detail.status: connecting, ready, retrying, paused, disabled, unauthorized, stopped.
});
```

The MIS, queue, notification, and Teams chat consumers use this event bus. Hints
only prompt authorized HTTP refreshes; no patient, counseling, notification, or
chat content is transported. Initial loads, reconnects, and bounded reconciliation
polls recover missed invalidations. The public API is `SynapseRealtime.status`,
`.reconnect()`, and `.stop()`; browsers cannot publish application events or choose
subscriptions. A 401/403 ticket response stops automatic retries until reload or
explicit `.reconnect()`. Navigation within the persistent shell does not add socket
owners.

## Production and security

- Run under a Linux service/container supervisor with automatic restart and logs.
- Terminate TLS at a reverse proxy; set e.g.
  `realtime.publicUrl = 'wss://portal.example.com/realtime'`. Forward that path to
  the WebSocket listener's `/` endpoint with WebSocket upgrade headers, the
  original `Origin`, and an idle timeout longer than the configured ticket TTL.
  Public URL paths are supported; internal listeners use explicit ports and no path.
- Keep the WebSocket listener private to the proxy where possible. Never expose
  port 8082 or proxy `/publish` publicly. The authenticated publisher rejects
  browser-origin requests and does not enable CORS.
- Both processes need the same private configuration. The publisher currently
  assumes they share a network namespace/loopback; separate containers need an
  explicitly designed private transport rather than opening this endpoint publicly.
- Use **one worker/replica only**. In-memory subscriptions are not shared across
  processes. Multi-worker/multi-node fanout needs a shared broker first.
- Default caps: 512 WebSocket connections and 32 concurrent publisher connections.
  These are resource bounds, not a substitute for reverse-proxy per-IP connection
  and request limits. Enforce those limits before exposing the listener.
- Revocation/logout is bounded by the remaining ticket lifetime, not immediate.
  Existing sockets do not query the database per event. Keep TTL short and send
  only minimal invalidation hints; sensitive clinical/counseling data must be
  fetched through the ordinary authorization-checked HTTP endpoints. Already
  granted channels remain usable for the token lifetime, so future confidential
  or hardware features may require immediate revocation support first.
- Tickets are bearer credentials until expiry. Do not log WebSocket frames or
  response bodies, persist tickets, reuse application keys, or expose publish keys
  to JavaScript. Exact origins are an additional check, not authentication alone.
- There is no durable event history, acknowledgement, or guaranteed delivery in
  this transport. Queue, notifications, and chat retain slower reconciliation
  polling while connected and switch to their faster existing intervals when the
  socket is unavailable. Hidden tabs do not run these background polls.

## Future BMG RPM gauge integration — not implemented

WebSockets are suitable for pushing live RPM measurements to a gauge. The future
path should be **device telemetry → authenticated backend ingestion → validated
measurement → authorized device channel → browser gauge**. MQTT can be used for
the device-to-backend leg without changing this browser transport.

Before adding it:

1. Define a tenant/device-scoped channel and authorize it using current BMG role,
   permission, tenant, and device access checks. Do not grant a global BMG feed.
2. Add authenticated ingestion, measured RPM, device identity, monotonic sequence
   or ordering information, and a measurement timestamp. A commanded speed is
   not proof of measured RPM.
3. Validate and rate-limit ingestion. Coalesce measurements to a bounded UI rate
   (e.g. 5–10 updates/second) rather than forwarding every sensor sample.
4. Add the gauge with reconnect resync and an explicit stale/offline indication.
   If no fresh measurement arrives, do not keep displaying an old RPM as live.
5. Keep emergency stops, interlocks, and safe motor operation in the hardware/
   firmware safety path. Browser connectivity must never be a safety mechanism.

No RPM ingestion, gauge, hardware command path, device grants, or changes to the
existing BMG feature gates are included here.

## Validation

```sh
php vendor/bin/phpunit tests/unit --filter Realtime --no-coverage
node --test tests/browser/realtime.test.cjs
```

The PHP suite includes a bounded real Workerman subprocess test that covers
origin rejection, first-message authentication, channel isolation, publisher
authentication, expiry, message/connection limits, and cleanup. It stops its
subprocess and checks that both listeners close. Browser tests use dependency-free
Node mocks for lifecycle, CSRF, retry, timeout, and stale-operation behavior.
