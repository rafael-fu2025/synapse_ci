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
- `auth_version` is pinned at issue; a credential/role change revokes every token for that user.
- Session cookies are **rejected** on API routes (mixed-auth guard in `ApiAuthFilter`).

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

## Security posture

- Per-request identity revalidation via `AuthenticatedUser::forIdentity` (role, status, tenant, person link).
- Rate bounds: 120 req/min per IP and 240 req/min per token (throttler), plus login throttles.
- Services own the authorization (`AppointmentService`, `SurveyService`, ...); controllers are thin adapters.
- The mobile API never widens the web permission model — every endpoint reuses the same service-level gates.

## Tests

`tests/feature/MobileAuthApiTest.php`, `tests/feature/MobilePortalApiTest.php` — run with
`php vendor/bin/phpunit -c phpunit.ci.xml tests/feature/Mobile...Test.php` (MySQL `humus_db_test`).
