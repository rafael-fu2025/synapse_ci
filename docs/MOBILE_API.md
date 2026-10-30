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
- Every response carries `Cache-Control: private, no-store`. Timestamps: stored UTC, served ISO-8601 Asia/Manila.

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
| `GET staff/queue-summary?unit` | Read-only board counts + active buckets; gated on `{unit}.queue.read`. **No action endpoints exist on mobile.** |
| `GET announcements?unit&status&page` | Targeted portal listing |
| `GET announcements/{id}` / `POST announcements/{id}/read` | Detail / mark read |
| `GET surveys?status` | Availability, counts, submitted state |
| `GET surveys/{id}` | Question form (`text`/`rating`/`single`/`multiple`) |
| `POST surveys/{id}/submit` | `{answers}` indexed by question position → 201; 409 on duplicates |
| `GET notifications` | Bell inbox (10 items, unread count ≤100) |
| `GET notifications/history?page&filter` | Paginated inbox |
| `POST notifications/{id}/read`, `POST notifications/read-all` | Mark read → 204 |

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

`tests/feature/MobileAuthApiTest.php`, `tests/feature/MobilePortalApiTest.php` — run with
`php vendor/bin/phpunit -c phpunit.ci.xml tests/feature/Mobile...Test.php` (MySQL `humus_db_test`).
