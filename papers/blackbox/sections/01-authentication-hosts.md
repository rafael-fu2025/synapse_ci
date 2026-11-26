# Authentication and workstation hosts

## AU-001 — Student signs in to the personal portal
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-001`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.STUDENT`
- **Role:** `student`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** GET/POST /auth/login; GET /portal
- **Actor/grants:** Active student with portal.appointments.read and portal.records.read
- **Preconditions:** A synthetic student linked to an active same-tenant person; MIS test authentication available; no pre-existing browser session.
**Steps**
1. Open the main-host sign-in page, enter the synthetic student's valid university ID and password, and submit.
2. Observe the same-origin landing, then open /portal and reload it once.
- **Expected:** The session is established, the user lands in the personal portal, and the reload still identifies only the synthetic student.
- **Negative/non-disclosure:** No staff workstation link, another person's data, password, or MIS response payload is disclosed.
- **Cleanup:** Sign out through the confirmation page and remove the browser's host-scoped cookies.
- **Source:** app/Controllers/AuthController.php:70-102; app/Services/AuthService.php:60-103
- **Evidence:** Screenshots of the branded sign-in and identified portal landing plus sanitized redirect/status and cookie-scope evidence.
- **Verdict:** `NOT_RUN`

## AU-002 — Employee signs in to the personal portal
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-002`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.EMPLOYEE`
- **Role:** `employee`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** GET/POST /auth/login; GET /portal
- **Actor/grants:** Active employee with personal-portal grants
- **Preconditions:** A synthetic active employee with an MIS identity and linked employee person; main host available; clean browser profile.
**Steps**
1. Submit the employee's valid university credentials on the main-host sign-in form.
2. Open /portal and the employee's own appointments/records tabs.
- **Expected:** The employee reaches the personal portal and can view only self-service resources granted to the employee role.
- **Negative/non-disclosure:** Clinic, Guidance, Facilities, and another person's records are not exposed merely because the actor is an employee.
- **Cleanup:** Sign out and clear the test browser's main-host data.
- **Source:** app/Services/AuthService.php:80-97; app/Services/PortalContext.php:138-179
- **Evidence:** Landing and own-resource screenshots, sanitized network redirects, and a denied workstation navigation observation.
- **Verdict:** `NOT_RUN`

## AU-003 — Invalid credentials establish no session
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-003`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.REJECT_INVALID`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login
- **Actor/grants:** Anonymous user
- **Preconditions:** A known synthetic identifier; an intentionally incorrect password; protected /portal URL recorded before the attempt.
**Steps**
1. Submit the known identifier with the wrong password.
2. After the error appears, request /portal in the same browser.
- **Expected:** The form reports an invalid ID/password and the protected request returns the user to sign-in without an authenticated identity.
- **Negative/non-disclosure:** The response does not reveal whether the identifier exists, which MIS namespace matched, a stack trace, or credential material.
- **Cleanup:** Wait for or reset only the synthetic login-attempt bucket before other authentication cases.
- **Source:** app/Controllers/AuthController.php:75-102; app/Services/AuthService.php:60-97
- **Evidence:** Error-state screenshot, sanitized POST/redirect trace, and protected-route result showing no session.
- **Verdict:** `NOT_RUN`

## AU-004 — Wrong workstation host clears a valid login
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-004`
- **Changed by:** baseline
- **Requirement:** `AUTH.HOST.WRONG_WORKSPACE`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Dedicated workstation sign-in
- **Route/API:** POST /auth/login on clinic/guidance/bmg host
- **Actor/grants:** Valid actor whose role is incompatible with the selected host
- **Preconditions:** Mapped dedicated hosts resolve locally; synthetic clinic staff and Guidance host (or inverse) are available; host-only cookies enabled.
**Steps**
1. Open an incompatible dedicated workstation host and submit otherwise valid credentials.
2. Follow the access-notice link to the correct host and inspect both hosts' authenticated state.
- **Expected:** The incompatible host returns an access notice, clears the newly established authentication there, and offers configured navigation without forwarding credentials.
- **Negative/non-disclosure:** No password/session token appears in a URL, no department access is granted by hostname, and no cross-origin mutation is replayed.
- **Cleanup:** Sign out on any host where a session was deliberately established and clear all test-host cookies.
- **Source:** docs/PORTAL_HOSTS.md:24-49; app/Controllers/AuthController.php:93-102
- **Evidence:** Wrong-host notice and correct-host navigation screenshots plus sanitized per-host cookie and redirect evidence.
- **Verdict:** `NOT_RUN`

## AU-005 — Login throttling is a temporary rate limit
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-005`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.THROTTLE_WINDOW`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login
- **Actor/grants:** Anonymous user
- **Preconditions:** Dedicated synthetic identifier; login-attempt store available; test clock/window known; no other runner using the same identifier/IP bucket.
**Steps**
1. Submit incorrect credentials repeatedly until the configured bucket limit is reached.
2. Attempt another sign-in during the window, record HTTP status/Retry-After, then retry valid credentials after the window expires.
- **Expected:** Requests in the exhausted window receive HTTP 429 with a bounded retry indication; valid credentials work again after expiry.
- **Negative/non-disclosure:** The behavior is not described as a persistent account lock, does not reveal account existence, and does not authenticate a throttled request.
- **Cleanup:** Let the bucket expire or delete only the isolated synthetic bucket through approved test teardown.
- **Source:** app/Filters/LoginThrottleFilter.php:14-55; app/Services/LoginRateLimiter.php:10-31
- **Evidence:** Attempt sequence, 429 response and Retry-After, followed by a successful post-window login; redact credentials.
- **Verdict:** `NOT_RUN`

## AU-006 — Confirmed logout anonymizes the current host session
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-006`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGOUT.CURRENT_HOST`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Account menu and confirmation
- **Route/API:** GET /auth/logout/confirm; POST /auth/logout
- **Actor/grants:** Any authenticated web actor
- **Preconditions:** Synthetic account signed in on the main host; optional independent session on a second host for scope proof.
**Steps**
1. Choose Sign out, verify the confirmation view, and submit its CSRF-protected POST.
2. Use browser Back and request a protected page; if a second-host session exists, check it separately.
- **Expected:** The current host rotates to an anonymous session and protected content cannot be restored; another host's independent session is unchanged.
- **Negative/non-disclosure:** GET /auth/logout does not mutate state, logout is not represented as global logout, and cached protected content is not served.
- **Cleanup:** End the optional second-host session and clear browser data.
- **Source:** app/Config/Routes.php:7-14; docs/SESSION_SECURITY.md:38-69; docs/PORTAL_HOSTS.md:51-57
- **Evidence:** Confirmation, signed-out result, back-button/protected-GET evidence, and optional second-host scope proof.
- **Verdict:** `NOT_RUN`

## AU-007 — Authentication-version change revokes open sessions
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-007`
- **Changed by:** baseline
- **Requirement:** `AUTH.SESSION.AUTH_VERSION_REVOKE`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Authenticated web session
- **Route/API:** Any protected GET after trusted auth_version update
- **Actor/grants:** Synthetic active account; two signed-in browsers; approved fixture/admin operation that atomically increments users.auth_version
- **Preconditions:** Record the account's initial auth_version and establish independent sessions in two browsers without exposing cookies.
**Steps**
1. Through the approved synthetic-data operation, change an authority-bearing account attribute or replace a local break-glass password so auth_version increments atomically.
2. In each already-open browser, request a protected page and then try to establish a session with stale state.
- **Expected:** Every old session fails its next identity check and requires fresh authentication; role and permissions are reloaded from the database.
- **Negative/non-disclosure:** Do not claim Synapse observes an external MIS password change; do not expose auth_version as an editable UI field or preserve cached grants.
- **Cleanup:** Restore the synthetic role/password through the same approved path, incrementing version as designed, and terminate fresh sessions.
- **Source:** docs/SESSION_SECURITY.md:71-92; app/Services/AuthService.php:99-103,319-346
- **Evidence:** Before/after version record, both browsers' revocation notices, and sanitized protected-request statuses.
- **Verdict:** `NOT_RUN`

## AU-008 — CSRF rejects a forged web mutation
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-008`
- **Changed by:** baseline
- **Requirement:** `AUTH.WEB.CSRF`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Protected web form
- **Route/API:** Representative protected POST, such as /portal/settings/emergency-contact
- **Actor/grants:** Authenticated synthetic portal actor
- **Preconditions:** Known current value in a synthetic record; same-origin valid form available; crafted request can omit or alter the token.
**Steps**
1. Capture the current visible value, then submit the mutation without a valid CSRF token.
2. Reload the target page and submit the same intended change through the legitimate form with its current token.
- **Expected:** The forged request is rejected and leaves state unchanged; the legitimate request succeeds once.
- **Negative/non-disclosure:** The rejection contains no stack trace/token value and does not rotate into an authenticated state for another identity.
- **Cleanup:** Restore the original synthetic field through the valid form.
- **Source:** app/Config/Routes.php:113-115,175-180; app/Config/Routes.php:7-14
- **Evidence:** Forged response/status, unchanged-state screenshot, and successful same-origin control mutation.
- **Verdict:** `NOT_RUN`

## AU-010 — MIS outage fails closed without local fallback
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-010`
- **Changed by:** baseline
- **Requirement:** `AUTH.MIS.UNAVAILABLE`
- **Role:** `student`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login
- **Actor/grants:** University student or employee identity (not a local break-glass account)
- **Preconditions:** Isolated environment with MIS integration intentionally unavailable; a synthetic university identifier; local account table contains no deliberate break-glass credential for it.
**Steps**
1. Disable or disconnect the MIS test endpoint and submit valid-format university credentials.
2. Request a protected page and inspect the sign-in response.
- **Expected:** The user receives an explicit MIS-unavailable message and no authenticated session is established.
- **Negative/non-disclosure:** The system does not silently test a local password for the university identity, mislabel transport failure as bad credentials, or expose upstream details.
- **Cleanup:** Restore the test MIS endpoint and verify health without logging operational secrets.
- **Source:** app/Controllers/AuthController.php:84-90; app/Services/AuthService.php:67-97
- **Evidence:** Unavailable-state UI, sanitized network error classification, and protected-route denial.
- **Verdict:** `NOT_RUN`

## AU-011 — Provisioned break-glass administrator can sign in locally
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-011`
- **Changed by:** baseline
- **Requirement:** `AUTH.BREAK_GLASS.LOCAL`
- **Role:** `superadmin`
- **Module:** Administration, Access & Platform
- **Use Cases:** `UC-AD-08`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login; GET /admin
- **Actor/grants:** Deliberately provisioned active local superadmin with applicable grants
- **Preconditions:** Isolated synthetic break-glass account created by the supported command; password held outside evidence; main host; MIS may remain unavailable.
**Steps**
1. Submit the break-glass username and valid local password on the main host.
2. Open the authorized administrative landing and one grant-protected page.
- **Expected:** The local password is verified, a version-bound session is created, and authorized administration pages load.
- **Negative/non-disclosure:** Ordinary MIS identities do not gain local fallback; the stored hash, password, and unrelated tenant data never appear in output.
- **Cleanup:** Sign out, revoke/delete the temporary break-glass fixture through its supported teardown, and purge captured credentials.
- **Source:** app/Services/AuthService.php:67-77,319-346; docs/SESSION_SECURITY.md:78-92
- **Evidence:** Administrative landing and protected-page screenshots plus redacted local-auth request/status evidence.
- **Verdict:** `NOT_RUN`

## AU-012 — Host-only cookies prevent cross-workstation session reuse
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-012`
- **Changed by:** baseline
- **Requirement:** `AUTH.HOST.SESSION_PINNING`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Dedicated hosts
- **Route/API:** GET protected routes on synapse, clinic, guidance, and bmg hosts
- **Actor/grants:** Authorized multi-workspace synthetic actor
- **Preconditions:** At least two mapped hosts served over the same scheme; browser cookie inspector available; no shared Domain cookie configured.
**Steps**
1. Sign in on one host and inspect the session cookie's host scope without recording its value.
2. Navigate to another workstation host and request a protected route before and after a separate sign-in there.
- **Expected:** The first host's session is not sent as authentication to the second host; each host requires and maintains its own session.
- **Negative/non-disclosure:** No parent-domain SSO cookie, credential-bearing redirect, or cross-origin POST replay is used.
- **Cleanup:** Sign out independently on every host and clear each host's browser storage.
- **Source:** docs/PORTAL_HOSTS.md:51-57; app/Config/PortalSites.php:1-26
- **Evidence:** Cookie attribute screenshots with values redacted and before/after protected-route observations on both hosts.
- **Verdict:** `NOT_RUN`

## AU-013 — Public Data Privacy Policy route serves institutional notice without login
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `AUTH.PRIVACY.PUBLIC_ROUTE`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-01`
- **Surface:** Public Data Privacy Policy page (uncommitted `feat/archive-management` Data Privacy Policy update)
- **Route/API:** GET /privacy-policy
- **Actor/grants:** Unauthenticated visitor and authenticated portal user
- **Preconditions:** Clean anonymous browser session; optional authenticated browser session on main/workstation host.
**Steps**
1. Open `/privacy-policy` in an unauthenticated browser session and verify the complete Foundation University Data Privacy Policy sections render under RA 10173.
2. Inspect the back-link target when logged out (`Back to Sign In` → `/auth/login`) vs logged in (`Back to Portal` → `/`) and check Cache-Control headers.
- **Expected:** `/privacy-policy` renders with HTTP 200 and `Cache-Control: private, no-store` without redirecting to sign-in, and adapts its back-navigation link safely to session state.
- **Negative/non-disclosure:** Page emits zero PII, user identifiers, session tokens, or stack traces regardless of authentication state.
- **Cleanup:** Clear browser session if authenticated.
- **Source:** app/Config/Routes.php:15-16; app/Controllers/AuthController.php:151-164; app/Views/auth/privacy_policy.php:1-40; app/Views/partials/privacy_policy_content.php:1-80
- **Evidence:** Anonymous and authenticated `/privacy-policy` screenshots, response headers, and `tests/unit/PrivacyPolicyRouteTest.php` corroboration.
- **Verdict:** `NOT_RUN`

## AU-014 — Sign-in Data Privacy Policy modal and clinical RA 10173 consent links stay accessible
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `AUTH.PRIVACY.LOGIN_MODAL_AND_INAPP_LINKS`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-01`
- **Surface:** Login screen modal and Guidance RA 10173 consent banners (uncommitted `feat/archive-management` Data Privacy Policy update)
- **Route/API:** GET /auth/login; GET /portal/evaluations/redeem/{token}; GET /guidance/clinical/forms/{id}
- **Actor/grants:** Public login visitor; token-holding student; authorized counsellor
- **Preconditions:** Login screen accessible; synthetic evaluation token and clinical form draft available for in-app consent-link check.
**Steps**
1. On `/auth/login`, click the compact `Data Privacy Policy` trigger (`.auth-privacy-trigger`) and verify `#dataPrivacyPolicyModal` opens, scrolls, and closes cleanly while allowing full-page `/privacy-policy` navigation.
2. Open the evaluation submission screen and clinical form editor and follow the inline Foundation University Data Privacy Policy (RA 10173) link.
- **Expected:** Login modal renders identical shared policy markup (`privacy_policy_content.php`) without trapping keyboard focus or breaking credential submission; in-app consent notices link directly to `/privacy-policy`.
- **Negative/non-disclosure:** Modal and login page do not cache user inputs, leak prior login identifiers, or alter authentication enforcement.
- **Cleanup:** Close sessions and reset disposable evaluation/form fixtures.
- **Source:** app/Views/auth/login.php:1-120; app/Views/layouts/auth.php:1-210; app/Views/portal/evaluations/submit.php:1-80; app/Views/guidance/clinical/form_editor.php:1-80
- **Evidence:** Login modal open/scroll screenshot, mobile/desktop fit check, and in-app link navigation captures.
- **Verdict:** `NOT_RUN`
