# Mobile API (`/api/v1`)

JSON API for the SYNAPSE mobile client. Bearer-token authenticated, stateless per request:
every request revalidates the identity from the database, exactly like the web session.

## Authentication

Opaque random tokens — no JWTs, nothing derived from user data. Only SHA-256 hashes are stored
in `api_tokens`; a token value is shown to the client exactly once.

| Endpoint | Body | Response |
|---|---|---|
| `POST api/v1/auth/login` | `{identifier, password}` | `{user, token}` |
| `POST api/v1/auth/refresh` | `{refresh_token}` | `{user, token}` (rotated family) |
| `POST api/v1/auth/logout` *(bearer)* | — | `204` |

- **Access tokens**: 15 min TTL (`api_tokens.access_ttl`). Sent as `Authorization: Bearer <64 hex>`.
- **Refresh tokens**: 60 days, single-use. Each refresh retires its row and issues a successor in
  the same *family*. Replaying or expiring a refresh token **revokes the entire family** (compromise signal).
- Login reuses `AuthService::authenticate()` (local break-glass accounts, then the MIS directory) and
  the same IP + identity throttle budgets as the web form. MIS unavailability returns a fixed 503 message.
- Throttled login/refresh responses return `429 rate_limited` with `Retry-After` set to the
  limiter's actual remaining wait in seconds. Clients must wait before a new user-initiated attempt;
  do not automatically retry credentials. This is an HTTP response, not a connection failure.
- `auth_version` is pinned at issue; a credential/role change revokes every token for that user.
- Session cookies are **rejected** on API routes (mixed-auth guard in `ApiAuthFilter`).

### Public sign-in carousel

`GET api/v1/auth/carousel` is unauthenticated; no credentials or tenant selector are required or
accepted. It uses the same `CarouselService::getSlidesForLogin()` source and default
`CurrentTenant` context as web login (`SYNAPSE_TENANT_ID`, otherwise tenant 1), retaining active-only
`sort_order ASC, id ASC` ordering and the service's Foundation University fallback when no active
slides exist.

```json
{
  "data": {
    "slides": [
      {
        "id": 1,
        "title": "Foundation University",
        "subtitle": "Health & Guidance Services Portal",
        "light_image": "assets/img/brand/fuel-bg.jpg",
        "dark_image": "assets/img/brand/FoundationU-Dark.png",
        "fit_mode": "cover"
      }
    ]
  }
}
```

Each slide has only `id` (number), nullable `title`/`subtitle`, required `light_image`, nullable
`dark_image`, and `fit_mode` (`cover` or `contain_blur`). Image values remain relative local raster
paths under `assets/img/brand/` or `uploads/carousel/`, never absolute paths or remote URLs.
Unsafe paths (including traversal, query strings and fragments) omit the slide if used for its
light image, or become `null` for its dark image. Filtering may produce an empty `slides` list.
No tenant, administrative flags, sorting metadata, or timestamps are exposed. Responses use
`Cache-Control: private, no-store`; database failure returns the ordinary `503 service_unavailable`
error, not a successful fallback response. The mobile client may use its local fallback on failure
or an empty list.

### Public carousel realtime grant

`GET api/v1/auth/carousel/realtime` is unauthenticated and no-store. Send an exact allowlisted
`Origin` header (native clients derive it from the API base URL). Request tenant/channel/user/origin
selectors are ignored. A hashed-IP budget allows 120 requests per minute; throttling returns 429
with `Retry-After: 60`.

- Disabled: `{data: {enabled: false}}`.
- Enabled: `{data: {enabled: true, url, origin, channel, ticket, expires_at}}`; expiry is Unix seconds.
- The signed grant contains only `public.tenant.{serverTenantId}.login-carousel`. It cannot grant
  private user channels. Origin/configuration errors produce ordinary fixed 403/503 API errors.
- Connect to `url` using the same `Origin`, then send `{type: "authenticate", ticket}` as the first
  message. Await `{type: "ready"}`. Tickets never belong in URLs or logs; clients cannot publish
  or choose subscriptions.
- `login.carousel.changed` carries `data: []`. Refetch the public carousel snapshot over HTTP;
  the socket does not carry images, credentials, slide titles, or administrative data.
- Reconnect and expiry renewal require fresh grants. Poll/reconcile slowly when unavailable and
  resync after reconnect so a missed hint never requires restarting the app.

## Response contract

- Success: `200/201` with the resource body. Collections: `{data: [...], meta: {page, pages, total}}`.
- Errors: `{error: {code, message, fields?}}` — `fields` maps field → list of messages (422 only).
- Statuses: `400 bad_request`, `401 unauthenticated/invalid_token/invalid_credentials/invalid_refresh`,
  `403 forbidden`, `404 not_found`, `409 conflict`, `413 payload_too_large`, `422 validation_failed`,
  `429 rate_limited`, `503 service_unavailable`.
- Every response carries `Cache-Control: private, no-store`. Timestamps are served as ISO-8601 with the
  explicit Asia/Manila offset (notifications, appointments) or as stored-UTC wall-clock strings
  (announcement timestamps), never as ambiguous local time.

## Endpoints (all bearer-authenticated unless noted)

| Endpoint | Purpose |
|---|---|
| `GET me` | Identity, permissions, entitlement flags (`staff_queue_units`) |
| `GET me/health-summary` | Lean health card: person + blood type/allergies/conditions/emergency contact. **No clinical encounter data.** |
| `GET me/photo` | WebP bytes or initials SVG fallback |
| `POST me/photo` | Multipart `photo` (JPEG/PNG/WebP ≤10 MB) |
| `DELETE me/photo` | Remove photo |
| `GET appointments?service&status&sort` | Personal list (person-scoped) |
| `POST appointments` | `{unit, date, preferred_time, type, reason}` → 201; field-level 422 |
| `GET appointments/{id}` | Personal detail (404 for other persons' rows) |
| `POST appointments/{id}/cancel` | `{reason}` (1–255 chars, required) → 204 |
| `GET queue` | Today's active personal tickets `{date, tickets[]}` |
| `GET staff/queue-summary?unit` | Read-only, PII-free board counts, active/held buckets and metrics; existing queue read/manage grants or superadmin. **No action endpoints exist on mobile.** |
| `GET announcements?unit&status&page` | Targeted portal listing |
| `GET announcements/{id}` / `POST announcements/{id}/read` | Detail / mark read |
| `GET surveys?status` | Availability, counts, submitted state |
| `GET surveys/{id}` | Question form (`text`/`rating`/`single`/`multiple`) |
| `POST surveys/{id}/submit` | `{answers}` indexed by question position → 201; 409 on duplicates |
| `GET notifications` | Bell inbox (10 items, unread count ≤100) |
| `GET notifications/history?page&filter` | Paginated inbox |
| `POST notifications/{id}/read`, `POST notifications/read-all` | Mark read → 204 |
| `GET realtime/grant` | Bearer-authenticated WebSocket grant for the caller's own private channel |
| `POST push/token` | Register the device's push token `{token, platform, device_label?}` |
| `DELETE push/token` | Withdraw a device push token `{token}` → 204 |

### Staff queue snapshot

`GET staff/queue-summary?unit=all` preserves the `data` array, containing only authorized units.
`unit` accepts `all` (default), `clinic`, or `guidance`; invalid units return 404 and requests
with no authorized units return 403. Per-unit access remains `{unit}.queue.read`,
`{unit}.queue.manage`, or superadmin. Tenant and date selectors cannot override the bearer tenant
or today's Manila business date. One captured server instant supplies both units and metadata.

```json
{
  "data": [{
    "unit": "clinic",
    "counts": {"total": 1, "no_show": 0, "waiting": 1, "called": 0, "in_consultation": 0, "done": 0, "skipped": 0},
    "tickets": {
      "waiting": [{"id": 1, "ticket_code": "C-001", "status": "waiting", "priority": "routine", "created_at": "2026-11-05T00:00:00+00:00", "called_at": null, "started_at": null, "skip_until": null}],
      "called": [], "in_consultation": [], "skipped": []
    },
    "metrics": {
      "active": 1,
      "priority_mix": {"routine": 1, "urgent": 0, "emergency": 0},
      "waiting_age": {"sample_count": 1, "total_seconds": 60, "oldest_seconds": 60, "average_seconds": 60}
    }
  }],
  "meta": {"date": "2026-11-05", "generated_at": "2026-11-05T08:01:00+08:00"}
}
```

Ticket fields are exactly `id`, `ticket_code`, `status`, `priority`, `created_at`, `called_at`,
`started_at`, `skip_until`. No patient name/kind, person identifier, notes, or completion timestamp
is exposed. `skipped` means **On hold** and is visible but excluded from `active` and `priority_mix`.
Those metrics cover only `waiting`, `called`, and `in_consultation`; priority counts have exactly
`routine`, `urgent`, and `emergency` numeric keys. Existing daily `counts` include terminal statuses,
but terminal tickets are not returned.

`waiting_age` covers only waiting tickets with valid, nonfuture creation timestamps, measured in
seconds against `meta.generated_at`. Its exact fields are numeric `sample_count`, numeric
`total_seconds`, nullable numeric `oldest_seconds`, and nullable numeric `average_seconds` (unrounded).
With no valid samples, count/total are zero and oldest/average are null, not zero.

Timestamp source conventions are deliberate: queue insertion uses model timestamps with the app's
UTC timezone (`created_at` becomes ISO-8601 `+00:00`); queue call/start/skip transitions use
`SchedulingClock` Manila wall-clock (`called_at`, `started_at`, `skip_until` become ISO-8601 `+08:00`).
Missing or invalid SQL datetimes become null. Metadata uses Manila `+08:00`. No storage or schema
changes are required; naive timestamps must never be interpreted using a device timezone.

### Notification items and deep links

Every notification item carries `id`, `title`, `message`, `created_at`, `read_at`, the web `url`, and a
`target` that names the record the notice is about:

```json
{
  "id": 81,
  "title": "Appointment confirmed",
  "message": "Your appointment has been confirmed. Open your portal to see details.",
  "created_at": "2026-10-26T08:30:00+08:00",
  "read_at": null,
  "url": "https://portal.example.edu/portal#my-appointments",
  "target": { "kind": "appointment", "id": 42 }
}
```

`target.kind` is one of `appointment`, `announcement`, `survey`, and `target.id` is the numeric record
identifier. Notices that have no mobile surface — incoming referrals, team events, team tasks, chat
mentions, workflow alerts — report `target: null`; the client marks them read and opens nothing rather
than navigating to a record it cannot show. `url` remains the **web** workstation destination.

### Device push tokens

`POST api/v1/push/token` accepts `{token, platform, device_label?}` where `platform` is `android` or
`ios`. The token is a routable address, not a credential: it is stored against the signed-in identity,
is never returned by any endpoint, and is never logged. A device token follows the account that last
used the installation, so a shared phone cannot keep delivering another account's notices.
`DELETE api/v1/push/token` accepts `{token}` and revokes it → 204. Both endpoints reuse the bearer
identity; a client cannot register a token for another account.

Delivery is opt-in on the server (`push.enabled`) and additionally requires a Firebase project and a
service account key. While push is disabled, registering a token succeeds but nothing is delivered and
the in-app inbox remains the only channel.

### Private mobile realtime grant

`GET api/v1/realtime/grant` issues a short-lived signed grant for exactly
`private.tenant.{tenantId}.user.{userId}`, derived from the freshly revalidated bearer identity —
request selectors for tenant/user/channel/origin are never accepted. It exists because the browser
`POST /realtime/ticket` contract requires a session, CSRF, and a browser `Origin`; the mobile
client has none of those. Send an exact allowlisted `Origin` header (the native client derives it
from the API base URL). A per-user budget allows 60 requests per minute; throttling returns 429
with `Retry-After: 60`.

- Disabled: `{data: {enabled: false}}`.
- Enabled: `{data: {enabled: true, url, origin, channel, ticket, expires_at}}`; expiry is Unix seconds.
- The grant carries no AI-insight or department topics — only the caller's own user channel. Clients
  cannot publish or choose subscriptions. Misconfiguration returns a generic 503; non-allowlisted
  origins return 403. Release HTTPS APIs require a `wss://` socket URL.
- Connect to `url` with the same `Origin`, then send `{type: "authenticate", ticket}` as the first
  message and await `{type: "ready"}`. Tickets never belong in URLs or logs.
- The channel delivers payload-free change hints only: `notifications.changed`,
  `portal.queue.changed`, `portal.appointments.changed` (all with empty payloads) and
  `queue.invalidated` (`{unit, date}`, only for users authorized to read that unit). The client
  refetches the authoritative snapshot over HTTP; the socket never transports notification bodies,
  ticket codes, or other content. Unknown events must be ignored.

### Profile-card projection

`GET me/health-summary` returns `{data: {person, health}}`. `person` includes `name` (including
middle name when recorded), `kind`, `identifier`, `email`, `program`, `year_level`, `section`,
`department`, `position`, and `employment_status`. Unavailable values are nullable; clients must
also accept numeric year levels. The projection follows the signed-in person and retains the
`portal.records.read` permission check. It contains no encounter notes or internal clinical records.

Personal queue ticket objects include `unit`, `ticket_code`, `status`, `priority`, and nullable
`position`, `people_ahead`, `estimated_wait_minutes`. Zero wait means next; null means unknown,
not zero. A `skipped` ticket remains visible as **On hold**, with instructions to ask reception.

## Security posture

- Per-request identity revalidation via `AuthenticatedUser::forIdentity` (role, status, tenant, person link).
- Rate bounds: 120 req/min per IP and 240 req/min per token (throttler), plus login throttles.
- Services own the authorization (`AppointmentService`, `SurveyService`, ...); controllers are thin adapters.
- The mobile API never widens the web permission model — every endpoint reuses the same service-level gates.

## Tests

Use the disposable-schema runner for database-backed mobile API tests; never migrate a development
or pre-existing test schema. From the repository root:

```sh
python -I tests/_support/run-fresh-database-tests.py tests/unit/StaffQueuePresenterTest.php tests/feature/MobilePortalApiTest.php tests/feature/MobileAuthApiTest.php
```

The runner creates, verifies, and drops a unique `humus_fresh_*` MySQL schema. Presenter tests cover
exact keys, held exclusion, empty samples, invalid/future creation times, and mixed timestamp sources;
portal endpoint tests cover bearer/entitlement gates, tenant/unit/day isolation, privacy, metadata,
and actual queue timestamp write paths.
