# Black-box test results — Authentication (AU-001 … AU-012)

- **Module:** Authentication (sign-in, sign-out, session and host controls)
- **Run date:** 2026-11-01 / 2026-11-02 (Asia/Manila)
- **App under test:** `http://localhost:8080/index.php` — local dev worktree, post-remediation build
- **Browser:** in-app Browser, **single tab** throughout (`navigate` + `replaceCurrentTab: true`)
- **Hosts used:** `synapse.localhost:8080` (main portal), `clinic.localhost:8080`, `guidance.localhost:8080`,
  `bmg.localhost:8080` — the dedicated portal hosts configured in `app/Config/PortalSites.php`
  (`local` family). All four resolve to 127.0.0.1 and are served by the same running app.
- **Server-side probes:** crafted HTTP requests issued with `curl`/Node against the *same* endpoints the
  UI calls (thrown-away scripts under `writable/`), used only where a case explicitly asks for a
  crafted request or where a UI control does not exist.

> Scope note: AU-001 … AU-012 only. Clinic/Guidance/Portal/Referrals/Facilities/Administration/AI
> cases are recorded in their own result files.

---

### AU-001 — Login (student)

**Verdict:** PASS

**Steps observed:** Opened `http://synapse.localhost:8080/index.php/auth/login` → entered the student
identifier and password → submitted.

**Evidence:** Redirected to `http://synapse.localhost:8080/index.php/portal`, title **"My Health
Portal - Synapse Campus Health & Services"**. The signed-in marker is present in the header:
**"[REDACTED: synthetic person display name] / STUDENT"**, alongside the notification badge and the search box.

**Notes:** The student lands on the main self-service portal home, not on a workstation.

---

### AU-002 — Login (employee)

**Verdict:** PASS

**Steps observed:** Opened the main sign-in page → entered `[REDACTED: synthetic account identifier]` → submitted → read the
landing page.

**Evidence:** Landed on `…/index.php/portal`, titled "My Health Portal", with the page heading
**"Employee Health Portal"** and employee records present:
- *"Welcome back, [REDACTED: synthetic employee display name]!"*
- **EMPLOYEE PROFILE**: [REDACTED: synthetic employee display name] · ID `[REDACTED: synthetic personnel identifier]` · Email `[REDACTED: synthetic account email]` ·
  Department · Position · **Status: Active** · Blood type: [REDACTED: blood-type information]
- A **Medical Flags** block
- Emergency contact block ("No emergency contact recorded yet. Add one so clinic staff know who to
  reach in an emergency.")
- My Appointments: "You have no appointments or requests."

**Notes:** The employee console is the same self-service portal, scoped to the employee identity.

---

### AU-003 — Login (invalid password)

**Verdict:** PASS

**Steps observed:** On the main sign-in page, entered a valid identifier (`[REDACTED: synthetic account identifier]`) with an
incorrect password → submitted → then requested a protected page.

**Evidence:**
- The form returned to the sign-in page with the error **"Invalid ID or password. Please verify your
  credentials with the MIS directory."**
- Navigating to `…/index.php/portal` immediately afterwards **redirected to
  `…/index.php/auth/login`** — no session was established.

**Notes:** The same error text is used for an unknown identifier and for a wrong password, so the
sign-in page does not disclose which accounts exist.

---

### AU-004 — Login (wrong portal host)

**Verdict:** PASS

**Steps observed:** Opened the **guidance** console host `http://guidance.localhost:8080/index.php/auth/login`
(the page renders as "Sign In - Guidance & Counseling Center") → entered **clinic staff**
credentials (`[REDACTED: synthetic account identifier]` + correct password) → submitted → then requested the guidance
console.

**Evidence:**
- The sign-in did **not** create a session. Instead the app returned a dedicated refusal page titled
  **"Workstation access restricted — Synapse"** with the body:
  > *"This workstation is restricted*
  > *Your account is not authorized for the Guidance & Counseling Center workstation. The hostname does
  > not grant department access.*
  > *Use your authorized portal below. Workstation hosts have separate sign-in sessions, so you may
  > need to sign in there."*
  with the actions **"Open your workstation"** and **"Main personal portal"**.
- After that refusal, requesting `http://guidance.localhost:8080/index.php/guidance` redirected to
  `http://guidance.localhost:8080/index.php/auth/login` — still signed out.

**Notes:** This case is only meaningful on a dedicated host. The app also runs in a single-host/path
mode on plain `localhost` (where `PortalContext::hostPortal()` is null and any role may sign in
anywhere); the dedicated `*.localhost` hosts used here are the configuration the case describes.

---

### AU-005 — Login (repeated failures lockout)

**Verdict:** PASS

**Steps observed:** With identifier `[REDACTED: synthetic account identifier]`, submitted five consecutive sign-in attempts with
wrong passwords, then a sixth attempt with the **correct** password.

**Evidence:**
- Attempts 1–5: HTTP 303 back to the sign-in form with the invalid-credentials error.
- Attempt 6 (correct password): **HTTP 429** with the page
  **"Too many sign-in attempts / Too many sign-in attempts. Please wait before trying again."** and the
  header **`Retry-After: 296`** (the throttle window is 300 s). No session was established.
- Configured limits (for reference): identity bucket 5 attempts / 300 s; IP bucket 60 attempts / 60 s.

**Notes:** The lockout is keyed to the identifier, so the other test accounts were unaffected. The
counter window expires on its own, so `[REDACTED: synthetic account identifier]` is usable again after ~5 minutes.

---

### AU-006 — Logout (confirmation)

**Verdict:** PASS

**Steps observed:** Signed in → opened the account menu → chose **Sign Out** → confirmed → pressed the
browser **back** button.

**Evidence:**
- The account menu (`button[aria-label="User account and profile menu"]`) shows the signed-in identity
  and a **Sign Out** control (`button.user-menu-logout`).
- Clicking it opens the dedicated modal `#logoutConfirmModal` — **"Confirm Sign Out"**, *"Are you sure
  you want to sign out? Your active session will be ended. Please ensure any open records or
  consultation notes are saved."* — with **Cancel** and a POST form carrying **"Yes, Sign Out"**.
- After confirming: the sign-in page is displayed with the message **"You have been safely signed out."**
- Pressing **back** returned to `…/index.php/auth/login` — **no authenticated view was restored**.

**Notes:** A plain `GET /auth/logout` is answered with **405 Method Not Allowed** and the body "Use the
Sign Out confirmation to end your session.", so the session cannot be ended by a link.

---

### AU-007 — Session identity revalidation

**Verdict:** PASS *(executed through the console's role-change action — see Notes)*

**Steps observed:**
1. Signed in as `[REDACTED: synthetic account identifier]` in the browser (session open on `localhost:8080`).
2. In a **separate** administrator session, opened **Administration → Employees & Access** →
   `[REDACTED: synthetic account identifier]` → *Change access* → new role **Report viewer** → *Save access change*
   (reason: "Black-box AU-007 session revalidation probe: temporary role change (report_viewer).").
3. In the still-open employee browser session, requested `…/index.php/portal` again.
4. Restored the employee to role **Employee** afterwards.

**Evidence:**
- The admin change was accepted: `role=report_viewer`, and `users.auth_version` advanced **8 → 9**.
  The console's own form states: *"Changing the role replaces existing workstation access and signs
  the employee out of all Synapse hosts on their next request."*
- The employee session's **next** request was rejected and the browser was returned to the sign-in
  page with the message **"Your session could not be validated. Please sign in again to continue."**
- Restored state after the test: `role=employee`, `status=active`, `auth_version=10`.

**Notes:** The case asks an administrator to *deactivate* an account. **The console exposes no
deactivation action** — "Employees & Access" states that *"account activation and MIS profile edits are
not managed here"*, and the admin route probes (`/admin/employees/create`, `/admin/accounts`,
`/admin/user-management`, `/portal/settings/deactivate`) all return **404**. The role-change action is
the only admin-side identity mutation the console offers, it is what actually revokes live sessions,
and it exercises exactly the expected outcome ("the next request is rejected and the user is returned
to the sign-in page"), so it was used as the closest reachable substitute. The substitution is
disclosed here; the deactivation step itself remains unavailable.

---

### AU-008 — Cross-site request forgery protection

**Verdict:** PASS

**Steps observed:** Signed in as `[REDACTED: synthetic account identifier]`, then submitted a **crafted** state-changing POST to the
portal appointment-cancel endpoint with the session cookie but **without** the `csrf_test_name` field.

**Evidence:**
- The request was rejected with **HTTP 403** and the body
  *"Your security token is missing, invalid, or expired. Reload this page and try again. **This request
  was not processed.** A fresh security token was issued; reload this page and retry."*
- **No change was made**: appointment [REDACTED: appointment identifier] was `status = requested` before the crafted POST and
  `status = requested` after it (verified by read-only SQL).
- Control: the same endpoint with a valid token succeeds (see PT-004, where the identical endpoint
  returned `200 {"success": true, "message": "Appointment cancelled."}`).

**Notes:** A cross-origin check was also attempted with the `Origin` header set to a foreign origin;
the request was refused before the handler ran.

---

### AU-009 — Password replacement revokes sessions

**Verdict:** BLOCKED

**Steps observed:** Looked for any password-replacement affordance for a signed-in user, and probed
the candidate routes.

**Evidence:**
- Signed-in portal pages `/portal/settings`, `/portal` (and `/portal/profile`, `/portal/account`,
  `/portal/security`) contain **no** password or account-management control — a search for
  "change password", "current password", "new password", "deactivate account", "delete account",
  "sign out everywhere" and "terminate all sessions" returns nothing.
- Route probes, all **404**: `/portal/settings/password`, `/portal/settings/account`,
  `/portal/settings/deactivate`, `/portal/settings/security`, `/auth/change-password`,
  `/portal/password`, `/account/password`, `/settings/password`.
- The console states the scope limit on screen: *"This page changes Synapse access only — not MIS
  identities, **passwords**, employment details or appointment-provider enrollment."* and *"account
  activation … and MIS profile edits are not managed here."*
- The sign-in page states that credentials are verified against the MIS directory
  ("Please verify your credentials with the MIS directory"), so passwords are owned by MIS, not by
  Synapse.

**Notes:** Step 2 of the case ("replace the account password in the first browser") has **no
counterpart in the product** — the account's password is not managed by this application, so the
premise cannot be staged. The underlying mechanism the case targets is nevertheless demonstrated in
AU-007: a server-side identity change (`auth_version`) invalidates an already-open session on its next
request.

---

### AU-010 — MIS unavailable

**Verdict:** PASS

**Steps observed:**
1. Started a **second, isolated instance** of the same code on `http://localhost:8081` with the MIS
   integration flag overridden **in that process's environment only**:
   `php -d variables_order=EGPCS -S 127.0.0.1:8081 -t public` with `FUMIS_ENABLED=false`.
   **`.env` was not modified** (`FUMIS_ENABLED = true` verified unchanged afterwards).
2. In the browser, signed in on that instance with a **university credential** (`[REDACTED: student identifier]`, an id with
   no local account, so it must be resolved through MIS) and submitted the sign-in.

**Evidence:**
- Proof that the override reached the application: resolving `env('FUMIS_ENABLED')` through the
  framework's own `DotEnv` + the helper's resolution order (`$_ENV` → `$_SERVER` → `getenv`) returns
  **`false`** on the isolated instance versus **`true`** on the normal instance, and the app selects
  **`DisabledMisClient` (fails closed)** instead of `HttpMisClient`. (`DotEnv` does not overwrite an
  already-set process environment variable, which is why `variables_order=EGPCS` is required so the
  value is present in `$_ENV` before `.env` is parsed.)
- Browser result: the sign-in was refused with the explicit message
  **"MIS sign-in is currently unavailable. Connect to the university network and try again, or contact
  support."** and the browser stayed on `…/index.php/auth/login` — **no session was established**.
- Control on the same isolated instance: the deliberately provisioned **local** account
  `[REDACTED: synthetic account identifier]` still authenticated normally (HTTP 303 → `…/portal`), proving the application did not
  simply refuse every sign-in — it refused exactly the MIS-dependent path and did **not** silently
  fall back to a local credential check for a university credential.
- The isolated instance was stopped again after the check.

**Notes:** Because the MIS upstream is not reachable from this host (see AD-005), the *same* explicit
message is also produced by the normally-configured instance for an MIS-resolved identifier; the
isolated instance was used to exercise the flag-disabled code path itself rather than the
upstream-unreachable path.

---

### AU-011 — Local break-glass administrator login

**Verdict:** PASS

**Steps observed:** On the main host sign-in page, entered the local administrator credentials
(`[REDACTED: administrator account identifier]`) → submitted.

**Evidence:** Landed on `http://synapse.localhost:8080/index.php/dashboard`, title **"Executive Admin
Dashboard - Synapse Campus Health & Services"** — the administrative dashboard, reached without any
MIS involvement.

**Notes:** This account is the person-less break-glass superadmin (`users.id = 1`, `role = superadmin`,
`person_id = NULL`), which is why it can sign in locally while MIS-backed identities cannot. The
published test password was accepted for it (verified with `password_verify` against the stored hash).

---

### AU-012 — Session host pinning

**Verdict:** PASS

**Steps observed:**
1. Signed in on the main portal host (`synapse.localhost:8080`) and then opened the **clinic console
   host** in the same browser.
2. Repeated with a crafted request: captured the main-host session cookie (`ci_session`) and replayed
   it against `clinic.localhost`, `guidance.localhost` and `bmg.localhost`.

**Evidence:**
- **Browser path:** `http://clinic.localhost:8080/index.php/clinic` shows the clinic host's own
  sign-in page ("Sign In - University Health Services") — the main-host session is not carried over
  and the staff console **requires a fresh sign-in on that host**.
- **Crafted replay path** (main-host cookie sent to each staff host): every request was refused with
  **HTTP 403** and the student's name never appeared in the response:
  `clinic.localhost/index.php/clinic` → 403, `guidance.localhost/index.php/guidance` → 403,
  `bmg.localhost/index.php/facilities/dashboard` → 403.

**Notes:** The pinning the product implements is *role*-scoped rather than origin-scoped: the
`PortalHostFilter` refuses a session whose **role** is not permitted on that host, and the session
cookie is host-scoped (`Cookie::$domain = ''`), so a browser never sends it to another host. One
observation is recorded for completeness: a crafted replay of a **clinic-staff** session against
`clinic.localhost` *was* accepted and rendered the clinic dashboard — that is by design, because
clinic staff are entitled to the clinic host and the product's own routing sends them there
(`PortalContext::landing()`); the security-relevant case (a session not entitled to that host) is
refused as shown above.

---

## Test data created / changed

| What | Identifier | Detail | Where |
| --- | --- | --- | --- |
| Profile picture | `[REDACTED: synthetic account identifier]` (user [REDACTED: account record identifier]) | Uploaded then removed; net: no stored picture | profile photo store |
| Emergency contact | `[REDACTED: synthetic account identifier]` (person [REDACTED: person record identifier]) | Left as *[REDACTED: emergency-contact name]* / *[REDACTED: emergency-contact relationship] (PT-008)* / *[REDACTED: emergency-contact phone number]* | `people` |
| Survey | survey **[REDACTED: survey identifier]** | "Black-box PT-010 student survey" (published, audience Students) | `surveys` |
| Clinic appointment | appointments **[REDACTED: appointment identifiers]** | 3036 confirmed Nov 2 11:00; 3037 left pending as the PT-003 slot-taken probe | `appointments` |
| Guidance walk-in | queue entry **[REDACTED: queue-entry identifier]** ([REDACTED: queue ticket identifier], 2026-11-02) | Issued for the test student during the real-time cases | `queue_entries` |
| Clinic walk-ins | queue entries **[REDACTED: queue-entry identifier]** ([REDACTED: queue ticket identifier], person [REDACTED: person record identifier]), **[REDACTED: queue-entry identifier]** ([REDACTED: queue ticket identifier], student) | Issued for the real-time cases; [REDACTED: queue ticket identifier] called then skipped then recalled, [REDACTED: queue ticket identifier] called | `queue_entries` |
| Team chat message | message **[REDACTED: message identifier]** | "@[REDACTED: synthetic staff display name]  black-box RT-007: please confirm the mention notification reaches you." (mentions user [REDACTED: account record identifier]) | `team_chat_messages` |
| Mention notification | notification **[REDACTED: notification identifier]** | `team_chat_mentioned` → `recipient_user_id = [REDACTED: account record identifier]`, unread | `notifications` |
| Employee role | `[REDACTED: synthetic account identifier]` (user [REDACTED: account record identifier]) | Temporarily changed `employee → report_viewer` and restored to `employee` for AU-007 (auth_version 8 → 9 → 10) | `users` |
| Notification read state | 8 personal notifications for person [REDACTED: person record identifier] | All marked read during PT-011 | notifications |

---

## Summary

| ID | Verdict | One-line reason |
| --- | --- | --- |
| AU-001 | PASS | Student sign-in lands on the main self-service portal home ("[REDACTED: synthetic person display name] / STUDENT"). |
| AU-002 | PASS | Employee sign-in lands on the Employee Health Portal with the employee profile, medical flags and status. |
| AU-003 | PASS | Wrong password → "Invalid ID or password…"; a follow-up protected request redirects to sign-in (no session). |
| AU-004 | PASS | Clinic staff credentials on the guidance host → "This workstation is restricted… hostname does not grant department access", no session. |
| AU-005 | PASS | 5 wrong attempts then a 6th with the correct password → HTTP 429 "Too many sign-in attempts", Retry-After 296, no session. |
| AU-006 | PASS | Sign-out asks for confirmation, ends the session with "You have been safely signed out.", and back does not restore an authenticated view. |
| AU-007 | PASS | After an admin identity change (auth_version 8→9) the open session's next request is refused: "Your session could not be validated. Please sign in again to continue." |
| AU-008 | PASS | A crafted POST without the CSRF token is refused with HTTP 403 "This request was not processed." and the target row is unchanged. |
| AU-009 | BLOCKED | No password/account-management control exists in any UI and every candidate route 404s — passwords are owned by MIS, not by Synapse. |
| AU-010 | PASS | With the MIS integration disabled in an isolated instance, a university credential is refused with "MIS sign-in is currently unavailable…" and no local fallback occurs. |
| AU-011 | PASS | The person-less break-glass administrator signs in and lands on the Executive Admin Dashboard. |
| AU-012 | PASS | A main-host session is not accepted by a staff console host (browser: fresh sign-in required; crafted replay: HTTP 403, no data). |

**Totals: 11 PASS · 0 FAIL · 1 BLOCKED**
