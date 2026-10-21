# Login limits and browser security headers

## Login attempt budgets

`POST /auth/login` runs `web_csrf` first, then `login_throttle`, before the
credential handler. Automatic routing remains disabled. Invalid CSRF requests do
not reach the limiter or authentication provider. GET login does not consume a
budget.

Default budgets, shared across portals and PHP workers using the same database:

| Budget | Attempts | Window |
|---|---:|---:|
| Client IP | 60 | 60 seconds |
| Normalized identifier + client IP | 5 | 300 seconds |

These count **all submissions**, not only incorrect passwords: successful logins
and MIS outages also consume attempts. Success does not reset counters. This is
not a permanent account lockout, and one attacker cannot impose a global
identifier-only lockout on users connecting from other addresses. Distributed
attacks across many IPs/identifiers still need gateway/WAF controls and monitoring.
Tune the broad IP budget for legitimate campus NAT traffic.

The windows begin when a bucket is first used or expires. Rejected requests do
not extend expiry. HTTP 429 includes `Retry-After` for the currently blocking
bucket; another budget can still block a later retry. AJAX/HTMX/JSON receive a
safe JSON message and current CSRF token; ordinary browser submissions receive an
HTTP 429 page with a link back to login, not a misleading success redirect.
Credential input is not copied into flashdata or limiter state.

### Atomic storage and privacy

Migration `2026-10-19-000005_CreateLoginAttemptBuckets` adds an InnoDB table with
only `bucket_hash`, `attempts`, and `expires_at`. A transaction locks the IP bucket
before the IP/identifier bucket. A blocked IP cannot create unlimited identifier
rows. Storage failure, missing schema, invalid configuration, or an unsupported
backend returns generic HTTP 503 and **does not proceed to authentication**.
This deliberately does not use the cache's dummy fallback or non-atomic file
read/modify/write operations.

The hashes are SHA-256 pseudonyms over normalized identifiers and canonical
binary IP addresses. Raw IPs, identifiers and passwords are not stored in the
counter table. These are **not anonymized data**: low-entropy values can be
guessed by an operator with database access. Treat the table as sensitive metadata.
Equivalent IPv6 spellings and IPv4-mapped IPv6 share counters; identifier case and
surrounding whitespace are normalized, while leading-zero IDs are preserved.

Each request considers at most 100 buckets that expired more than a day ago for
opportunistic cleanup. Candidate selection is nonlocking, and individual deletes
recheck expiry, avoiding an active-counter range lock. Cleanup is traffic-driven,
not a guaranteed retention deadline. Inactive deployments may retain expired
hashes until traffic resumes or an administrator schedules reviewed cleanup.
Keep application-server clocks synchronized when multiple servers share counters.

### Configuration and client address

`Config\LoginSecurity` supports these environment overrides:

```ini
loginsecurity.ipAttempts = 60
loginsecurity.ipWindowSeconds = 60
loginsecurity.identityAttempts = 5
loginsecurity.identityWindowSeconds = 300
```

Limits must be 1–10,000; windows must be 1–86,400 seconds. Zero is not an off switch:
invalid configuration fails closed. The limiter uses CodeIgniter's
`getIPAddress()`, with `App.proxyIPs` defining the exact trusted proxy/header
mapping. No arbitrary forwarded header is read directly. Behind a gateway, set
that whitelist correctly and ensure it strips client-supplied forwarding headers;
otherwise every visitor can appear to have the proxy's address. Do not configure
wildcard trust for untrusted clients. No proxy-trust settings were changed here.

## Security headers

`WebSecurityHeaders` is a **required after-filter**, including early filter
rejections and unknown routes. Dynamic PHP responses receive:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN` (an existing `DENY` is retained)
- `Referrer-Policy: same-origin`
- `X-Download-Options: noopen`
- `X-Permitted-Cross-Domain-Policies: none`
- `X-XSS-Protection: 0` (do not rely on the legacy browser XSS filter)
- `Permissions-Policy: camera=(), microphone=(), geolocation=(), serial=(self)`
- `Cache-Control: private, no-store`

Same-origin Web Serial remains available for the existing BMG workflow. These
headers do not enable hardware control or change its permission/environment gates.
Resources served directly by Apache/nginx bypass PHP filters; set corresponding
headers and correct MIME types at that boundary too. Production HTTPS enforcement
and HSTS retain their existing framework configuration; this change does not
configure TLS or reverse-proxy transport trust.

## CSP rollout: enforced baseline, strict candidate report-only

The required filter enforces:

```text
base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'
```

An already supplied enforcing CSP header is preserved. Forms must submit to the
serving origin; configure the canonical `app.baseURL` and allowed portal hostnames
correctly rather than weakening this to arbitrary HTTPS targets.

`App.CSPEnabled` now defaults to true. The framework's stricter script/style CSP
is emitted as **Content-Security-Policy-Report-Only** by default. It permits local
assets, Google Fonts stylesheet/font hosts, and the required image/worker data or
blob schemes, but does not add `unsafe-inline` or `unsafe-eval` allowances. No
report endpoint is configured, so violations are visible locally in browser
DevTools rather than being sent to a new reporting service.

**Strict script/style enforcement is not complete.** The current views use inline
scripts/styles/event handlers, Alpine expressions, and dynamic modal/navigation
scripts. Report-only violations are expected. This baseline does not prevent
inline-script XSS; escaping, authorization, CSRF, and validation remain necessary.
Do not flip `contentsecuritypolicy.reportOnly` to false blindly. First migrate
inline scripts to safe external modules/nonces, address dynamic script handling,
use a CSP-compatible Alpine strategy, and verify every portal, modal, chart,
upload, and firmware workflow in a real browser. Review privacy before enabling
CSP report collection, especially URLs containing clinical identifiers.

## Deployment

**No operational database migration was run.** Stop/drain login requests and apply
`2026-10-19-000005_CreateLoginAttemptBuckets` through the reviewed deployment
migration process before enabling this code. Review all other pending migrations
rather than running them against live data blindly. Missing counter storage
intentionally makes POST login return 503; GET login remains accessible.

The migration is additive, validates an existing counter schema on retry, and
never modifies users, roles, passwords, or clinical records. Downgrade removes
counter state; stop login traffic first and review the matching code rollback.

## Validation and boundaries

```sh
php vendor/bin/phpunit -c phpunit.ci.xml --no-coverage
python tests/_support/run-fresh-database-tests.py tests/unit/LoginAttemptStoreTest.php tests/unit/QueueServiceTest.php tests/unit/AppointmentServiceTest.php tests/unit/AuditServiceTest.php tests/unit/GuidanceCaseServiceTest.php
```

The tests cover normalization, untrusted forwarding headers, no-store/CSRF error
responses, fail-closed storage errors, actual filter ordering and handler rejection,
CSP report-only output, schema retries, expiry/cleanup, rollback, and two real
worker connections competing for the last attempt. The counter concurrency test
uses only a fresh guarded schema and synthetic hashes.

These checks are not a production load test, a full-portal browser acceptance run,
a WAF replacement, or a claim that the entire historical suite is green. Strict
CSP enforcement and the separate encryption-key/AAD migration remain pending.
