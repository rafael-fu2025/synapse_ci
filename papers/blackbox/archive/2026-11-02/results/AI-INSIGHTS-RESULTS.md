# AI Insights — Black-Box Test Results (AI-001 … AI-011)

**App under test:** SYNAPSE Campus Health & Guidance — `http://localhost:8080/index.php` (already running)
**Module:** AI Insights (admin-console AI settings + clinic/guidance department insights panels)
**Executed:** 2026-11-01 (Philippine Standard Time), by delegated Worker session
**Accounts used:** `[REDACTED: synthetic account identifier]` (platform owner), `[REDACTED: synthetic account identifier]` (clinic staff role), `[REDACTED: synthetic account identifier]` (guidance counsellor role) — all with password `[REDACTED: shared test password]`

---

## Tooling deviation (read this first)

The task brief required driving the **in-app Browser**. That tool is **not available to this delegated session**:

* The session's own tool manifest (`…\v2\sessions\2026\11\01\14-21-44-043-session_…\llm-call.json`) lists 51 tools and contains **no `mcp_browser` and no `skill` tool** (the parent session `[REDACTED: local agent session identifier]` does have them).
* `tool_search` returned `total: 0` for every query (empty deferred-tool catalogue), and `mcp_invoke` with `tool_ref` (`browser-control`, `host:browser-control`, `plugin:browser-use:browser-control`, `browser.control`) returned `Unknown or unavailable tool_ref`.

To avoid fabricating results, the **same live UI** was driven with a local Chrome (puppeteer-core + installed Chrome) against `http://localhost:8080/index.php`, using the signed-in session of each test account, capturing on-screen text, DOM state and full-page screenshots. All evidence below is real observed UI/DOM output. Read-only SQL was used only where the UI cannot expose storage facts (permitted by the brief: throwaway mysqli scripts under `writable/`), and the app's own `php spark ai:*` CLI was used for worker checks. No application source, config, `.env` or migration file was read (the throwaway helper parses `.env` at runtime for credentials only and never prints it).

Screenshots referenced below live in `%TEMP%\bb\shots\` (temporary scratch, not part of the repo).

---

### AI-001 — Provider configuration and model discovery

**Verdict:** PASS

**Steps observed:**
1. Signed in as `[REDACTED: synthetic account identifier]` → opened **Administration → AI Settings** (`/index.php/admin/ai`, HTTP 200, title *AI Settings - Synapse Campus Health & Services*).
2. Typed the endpoint into `#ai-base-url` = `http://localhost:20128/v1` (the endpoint the tenant was already configured with — a local OpenAI-compatible gateway on this machine; **no external provider and no real external key were used**).
3. Typed the placeholder key `[REDACTED: placeholder API key value]` into `#ai-api-key`.
4. Clicked **Fetch models** → model list appeared.
5. Selected one model in the fetched list → clicked **Import selected** → imported-catalogue count rose.
6. Clicked **Save settings**.

**Evidence:**
* After **Fetch models**: status line `187 of 187 shown · 0 selected`; after ticking one model: `187 of 187 shown · 1 selected`. Buttons: `Import selected` was `disabled:true` at 0 selected, `Import all` enabled.
* After **Import selected**: header stat `IMPORTED MODELS 173` → `IMPORTED MODELS 174`; body text `174 imported model(s) available. Choose one explicitly to apply recommendations; importing alone changes no settings.`; the `#ai-imported-model` select grew to 175 `<option>`s (174 models + placeholder). Active model stayed `minimax/MiniMax-M3` (read before and after).
* Save response banner: `AI settings saved securely. Test the connection next. Aggregate analytics is paused until you enable it.`
* Key secrecy (also AI-003): after save the page still shows `A key is saved. It is never sent back to this page.` and `document.documentElement.innerHTML.includes('[REDACTED: placeholder API key value]') === false`.
* Encrypted storage of AI payloads confirmed read-only in `ai_snapshots` / `ai_insights` (see AI-006 / AI-008); the imported-model catalogue rows themselves were not inspected.

**Notes:**
* Placeholder key value used: **`[REDACTED: placeholder API key value]`** (disclosed on request). The provider rejected it when the connection was tested: `Error: The endpoint rejected the API key. Check the key and authentication mode.` — i.e. key validation is enforced, the key is not decorative.
* Because that placeholder replaced the tenant's stored key (which is never returned to the browser), the key was **restored** to a working key for the same endpoint — see *Settings changed and restored*.

---

### AI-002 — Save pauses analytics

**Verdict:** PASS

**Steps observed:**
1. With AI settings changed and **saved**, the console banner read `Saved · test connection next` and the flash said `AI settings saved securely. Test the connection next. Aggregate analytics is paused until you enable it.`
2. Signed in as `[REDACTED: synthetic account identifier]` (holding read + generate grants) and opened **Clinic → Insights & Program Planning** (`/index.php/clinic/insights`, HTTP 200).
3. Attempted generation *before* re-enabling → both action buttons rendered disabled; the click was a no-op.
4. As `[REDACTED: synthetic account identifier]`: clicked **Test connection** → then ticked `I confirm this provider may process protected aggregate departmental analytics…` and clicked **Enable aggregate AI**.
5. Re-opened the same insights panel as `[REDACTED: synthetic account identifier]`.

**Evidence:**
* While paused: `Prepare local summary` and `Request AI draft` were `disabled:true` for both comparisons (`clinic.activity`, `clinic.complaints`); the scripted click logged `disabled=true` and produced no navigation, no flash, no request. Panel copy: `Privacy and department approval is needed before a summary can be prepared.`
* After **Test connection**: `Success: Connection successful. Endpoint, API key, model, and selected options worked. No patient data was sent. You can now enable aggregate analytics below.` + banner `Connection verified · analytics paused` (still paused).
* After **Enable aggregate AI**: `Protected aggregate analytics enabled for this tenant. Patient charts and counseling notes remain excluded. Department source-data permissions still apply.` + banner `Aggregate AI enabled`.
* Panel after re-enable: `The AI setup is approved. This does not confirm that enough eligible data is available or that the separate worker is running.` and both buttons became `disabled:false`.
* CLI `php spark ai:status` → `enabled=1 configured=1 governanceApproved=1 revisionApproved=1 compatibilityVerified=1 revision=[REDACTED: AI configuration fingerprint]`.
* Inverse check (AI-011): **Pause aggregate AI** → `Warning: AI analytics paused. Your provider settings and saved key were kept. Already sent requests cannot be recalled.` and `php spark ai:status` → `enabled=0 configured=1 governanceApproved=0 revisionApproved=0 compatibilityVerified=0`.

**Notes:**
* Generation stayed unavailable until *both* a fresh connection test **and** an explicit enable were performed — saving alone is not enough.
* The panel separates "AI setup approved" from "enough eligible data" (see AI-006).

---

### AI-003 — Key secrecy

**Verdict:** PASS

**Steps observed:**
1. Saved AI settings with a key in `#ai-api-key`, then reloaded `/index.php/admin/ai` (fresh GET).
2. Read the field value, the note under the field and the served HTML.

**Evidence:**
* `#ai-api-key`.value.length = **0** (field blank after reload).
* Note under the field: `A key is saved. It is never sent back to this page.`
* The raw key string was **absent from the served document**: `document.documentElement.innerHTML.includes('[REDACTED: placeholder API key value]') === false`; no key-like token appeared anywhere in the page body text.
* The same held after the key was restored (working gateway key): field length 0, note unchanged.

**Notes:**
* Also verified indirectly: the app validates the stored key against the provider (`The endpoint rejected the API key.`), i.e. the key is stored and used, but never returned to the browser.

---

### AI-004 — Department access grants

**Verdict:** PASS

**Steps observed:**
1. As platform owner `[REDACTED: synthetic account identifier]` → **Administration → AI Settings → Department AI access → Clinic**.
2. Clicked **Grant Read**, **Grant Generate**, **Grant Review**, **Grant Approve** for role *Clinic staff* (four separate button presses, one per form POST to `/index.php/admin/ai/access`).
3. Opened **Audit Chain Ledger** (`/index.php/admin/audit`) and read the top rows.
4. Re-opened the clinic insights panel as `[REDACTED: synthetic account identifier]` before and after the grants.

**Evidence:**
* Each grant change produced **its own audited event** (top of the ledger, newest first, each with its own ID, actor, IP and hash-chain links):

| # | Event | Action | Resource | Actor | Timestamp |
|---|---|---|---|---|---|
| #153 | `ai.access.grant` | grant | `ai_department_access #clinic:clinic_staff:ai.insights.read` | [REDACTED: synthetic account identifier] | Nov 1, 2026 03:03:24 PM |
| #154 | `ai.access.grant` | grant | `ai_department_access #clinic:clinic_staff:ai.insights.generate` | [REDACTED: synthetic account identifier] | Nov 1, 2026 03:03:39 PM |
| #155 | `ai.access.grant` | grant | `ai_department_access #clinic:clinic_staff:ai.insights.review` | [REDACTED: synthetic account identifier] | Nov 1, 2026 03:04:33 PM |
| #156 | `ai.access.grant` | grant | `ai_department_access #clinic:clinic_staff:ai.insights.approve` | [REDACTED: synthetic account identifier] | Nov 1, 2026 03:04:37 PM |

  Ledger columns observed: `#153 | ai.access.grant | Action: grant | ai_department_access #clinic:clinic_staff:ai.insights.read | [REDACTED: synthetic account identifier] IP: 127.0.0.1 | P: f772d425a7feefa1... C: bfb60a8f259756a7...` — the `P:`/`C:` values chain per event.
* Panel reflected the new access: before the grants the panel said `Privacy and department approval is needed before a summary can be prepared.` with both buttons disabled; with read+generate granted (and analytics enabled) the same panel showed the buttons enabled and `The AI setup is approved.`
* Flash per grant: `Information: Department AI permission granted for this tenant only. Source-data permissions are unchanged.`
* Revocation is audited the same way: `ai.access.revoke` / `ai_department_access #clinic:clinic_staff:ai.insights.generate` (#194 grant → #195 revoke, one event each).

**Notes / defect found:**
* On a **normal page load** the Grant/Revoke buttons do **not** reflect stored grants: every cell rendered `Grant Read/Grant Generate/…` even though `ai_department_access` already contained rows (e.g. the pre-seeded `clinic/clinic_staff ai.insights.read`). The per-permission `Revoke …` state only appears in the page rendered **immediately after** a grant/revoke action on that same row. Practical impact: an administrator cannot see that a grant already exists, and revoking requires first re-posting a grant on that row and then pressing `Revoke X` (that path was verified to work: #194 grant generate → #195 revoke generate → row then showed `Grant Generate`).
* Guidance-unit grants for the same role behaved identically (each click → one `ai.access.grant` event).

---

### AI-005 — Generation requires explicit grant

**Verdict:** PASS

**Steps observed:**
1. Revoked `ai.insights.generate` for `clinic_staff` (audit event #195 `ai.access.revoke`).
2. Signed in as `[REDACTED: synthetic account identifier]` and opened `/index.php/clinic/insights`.
3. Attempted to submit the generation action.

**Evidence:**
* With only the read grant left, the panel rendered **no action controls at all** — the only form in the DOM was the sign-out form (no `insights/prepare` or `insights/generate` form existed). The comparison cards showed only their descriptions:
  `Clinic activity / Compare completed clinic visits in the two months.` and `Recorded clinic complaints / Compare recorded complaint categories as a share of completed clinic visits.`
* The scripted generation attempt failed with `form button not found` → nothing was submitted, no navigation, no flash, no request.
* Read-only SQL check after the attempt: `ai_jobs` still holds **2** rows (`#1 clinic snapshot=1 status=failed reason=invalid_output`, `#3 clinic snapshot=4 status=succeeded`) and `ai_insights` still holds **1** row → **no job was queued**.
* Additional refusal observed for a role with no AI grants at all: `[REDACTED: synthetic account identifier]` opening `/index.php/guidance/insights` was redirected to the guidance dashboard with `You do not have access to this AI action.`

**Notes:**
* Grant enforcement is independent of the data-eligibility check (see AI-006): with the generate grant present the button was enabled and the request was then refused *for data reasons*; with the grant removed the action is not offered at all.

---

### AI-006 — Prepare local summary

**Verdict:** BLOCKED (seed gap — no eligible data in the fixed comparison window)

**Steps observed:**
1. As `[REDACTED: synthetic account identifier]` (read + generate granted, analytics enabled) opened `/index.php/clinic/insights`; the **Prepare local summary** button was enabled (`disabled:false`).
2. Submitted **Prepare local summary** for `clinic.activity`, then for `clinic.complaints`.
3. Read the panel's privacy help (`Privacy and how to use these summaries`, `Why might a summary be unavailable?`).

**Evidence:**
* Both submissions were refused with the same warning:
  `Warning: No comparison is available yet. Both completed months need enough different people with completed clinic visits and valid linked records to meet the privacy rules. Check that eligible visits are recorded as completed. Hidden or unavailable values are not zero. This is not an AI connection error.`
* Panel context: `Clinic planning. Compare September 2026 and October 2026, the last two completed months in Philippine time. The current month is not included.`
* Privacy rule text: `Both months must have enough different people with completed clinic visits and valid linked records to meet the privacy rules. Repeat visits by one person do not count as different people. Categories must also pass privacy checks before they can be shown. Hidden or unavailable values are not zero. Do not work them out from totals.`
* Why the window has no eligible data (read-only SQL):
  * `clinic_encounters`: only **2** rows, both `status=completed`, both `started_at/completed_at = 2026-11-01`, both for the **same person_id [REDACTED: person record identifier]** → `2026-11: rows=2 distinct_people=1`; **no September or October 2026 rows**.
  * `counselling_sessions`: 2 completed sessions, both `2026-11-01`.
  * `ai_releases` (approved aggregate releases) contains clinic metrics only for months `2026-08` and `2026-09` — the window the seed was built for (the seed ran 2026-10-30, when the comparison was Aug/Sep).
  * Because the current month is excluded and encounters are stamped with the current date, September/October 2026 data cannot be produced through the UI now.
* Encryption of snapshot storage **is** observable on the pre-seeded rows: `ai_snapshots.payload` = `[REDACTED: encrypted protected payload excerpt]` (1 627 chars, snapshot #1) and `[REDACTED: encrypted protected payload excerpt]` (8 939 chars, snapshot #4) — i.e. ciphertext, not plaintext aggregates, with `schema_version`, `disclosure_version` and `fingerprint` columns alongside.

**Notes:**
* What was **not** observed: a snapshot actually being computed from aggregates in this run, nor a small-cell value being withheld on screen (no summary could be produced at all). The refusal message itself confirms the small-cell/eligibility rule is enforced before anything is computed.
* Seed gap is the blocker, not a defect: the comparison window has moved one month past the seeded data.

---

### AI-007 — Queued request without worker

**Verdict:** BLOCKED (no request could be queued — same seed gap as AI-006)

**Steps observed:**
1. With analytics enabled and grants in place, submitted **Request AI draft** for `clinic.activity` and for `clinic.complaints` (worker not running during the attempt).
2. Checked the queue state read-only and ran the worker command afterwards.

**Evidence:**
* The queueing step was never reached: both submissions returned `Warning: No comparison is available yet. Both completed months need enough different people with completed clinic visits and valid linked records to meet the privacy rules. … This is not an AI connection error.` (identical to AI-006) — so **no request was created**, and therefore no request could be observed "remaining queued".
* Read-only state after the attempts: `ai_jobs` = 2 rows (`#1 failed / invalid_output`, `#3 succeeded`), `ai_dispatch_attempts` = 2 rows (`job_id 1 slot 1`, `job_id 3 slot 2`, both reserved 2026-10-30), `ai_insights` = 1 row, `ai_reviews` = 1 row — **no new job row, no new dispatch attempt**.
* The worker CLI exists and runs: `php spark ai:dispatch --tenant 1 --unit clinic` → `processed=0 failed=0 outcome_unknown=0` (nothing queued to pick up), and `php spark ai:status` → `enabled=1 configured=1 governanceApproved=1 revisionApproved=1 compatibilityVerified=1`.
* No provider call was made by my attempts: the only draft payload in the database is the pre-seeded one from 2026-10-30, and no new insight row appeared.

**Notes:**
* The worker is a one-shot batch dispatcher (`ai:dispatch --tenant <id> [--unit clinic|guidance|bmg]`), not a long-running loop; with an empty queue it reports `processed=0`.
* The pre-seeded history shows the intended flow: job `#1` (clinic.activity) → `status=failed, reason=invalid_output`; job `#3` (clinic.complaints) → `status=succeeded, attempts=1`.

---

### AI-008 — Worker generation

**Verdict:** BLOCKED (no queued job to execute — same seed gap)

**Steps observed:**
1. Ran the worker against the tenant/unit: `php spark ai:dispatch --tenant 1 --unit clinic`.
2. Re-read the draft/job tables read-only afterwards.

**Evidence:**
* Worker output: `processed=0 failed=0 outcome_unknown=0` — the worker started and finished inside its budget but had **no queued job** (there is no queued job in `ai_jobs`; only terminal `failed` / `succeeded` rows exist).
* Worker readiness CLI: `php spark ai:status` → `enabled=1 configured=1 governanceApproved=1 revisionApproved=1 compatibilityVerified=1`.
* Pre-seeded evidence that a worker-produced draft is stored **encrypted against its snapshot**: `ai_insights` row `#1` → `job_id=3`, `snapshot_id=4`, `status=rejected`, `version=2`, `payload` = `[REDACTED: encrypted protected payload excerpt]` (11 183 chars of ciphertext); matching job row `#3 … snapshot_id=4 status=succeeded attempts=1 model=minimax/MiniMax-M3`.
* What was **not** observed: a new draft being created by the worker during this run.

**Notes:**
* Blocked by the same missing prerequisite as AI-007 (no request could be queued because the fixed Sept/Oct 2026 comparison window has no eligible data), not by worker availability — the worker command runs successfully.

---

### AI-009 — Versioned review decisions

**Verdict:** BLOCKED (no draft presented for review — same seed gap plus an older-configuration draft)

**Steps observed:**
1. Opened `/index.php/clinic/insights` as `[REDACTED: synthetic account identifier]` (review + approve granted) and read the **AI drafts for review** and **Recent draft requests** sections.
2. Looked for Accept / Reject / Approve controls on the panel.
3. Read the stored review records read-only.

**Evidence:**
* Panel: `AI drafts for review — No AI drafts yet. Request a draft using a comparison above if you have permission. A separate worker must process the request before a draft appears. Only current, unexpired drafts for the approved setup are shown.`
* `Recent draft requests` shows two **older-configuration** entries and no actionable links/buttons (`<li class="list-group-item …" data-ai-request="3" data-ai-progress="ready">`):
  * `Recorded clinic complaints #3 · Draft ready` → `✓ Ready for review. Generation and checks finished.` … `Older configuration` … `Created 2026-10-30 15:52 +08:00 Philippine time`
  * `Clinic activity #1 · Could not create a draft` → `Stopped` `No draft is ready for review.` … `The AI response did not pass validation.` … `Created 2026-10-30 15:51`
* No Accept/Reject/Approve control is rendered anywhere on the panel (the only forms present were the two action forms and the sign-out form), so **no decision could be recorded through the UI**.
* Storage shows the decision log is versioned: `ai_reviews` row `#1` → `insight_id=1, actor_id=2, version=2, decision=reject, reason=staff_reject, created_at=2026-10-30 08:44:54`; the draft it refers to is `ai_insights #1 … status=rejected, version=2`.
* Why the seeded draft is not offered for review: its `profile_revision` (`[REDACTED: AI configuration fingerprint]`) differs from the tenant's current approved revision (`ai:status revision=[REDACTED: AI configuration fingerprint]`), i.e. it is `Older configuration`.

**Notes:**
* I did **not** observe accept → reject → approve being recorded and versioned in the UI; the only versioning evidence is the pre-seeded `ai_reviews` row. Marked BLOCKED rather than PASS for that reason.

---

### AI-010 — Unapproved drafts withheld

**Verdict:** BLOCKED (withholding observed; presentation of approved releases not observable)

**Steps observed:**
1. Reduced `clinic_staff` to a read-only grant (generate revoked).
2. Opened `/index.php/clinic/insights` as `[REDACTED: synthetic account identifier]` and read the drafts/review sections.
3. Checked the storage read-only for approved releases and drafts.

**Evidence:**
* **Unapproved drafts withheld — observed.** The read-only panel shows no draft: `AI drafts for review — No AI drafts yet. … Only current, unexpired drafts for the approved setup are shown.` The only draft in storage is `ai_insights #1` with `status=rejected` (and `profile_revision` ≠ current revision), and it is not presented anywhere in the panel — it appears only as the informational `Older configuration` entry `Recorded clinic complaints #3 · Draft ready`.
* **Approved releases presented — not observable.** No approved draft exists to present (`ai_insights` holds 1 row, `status=rejected`), and no local summary could be produced to surface released aggregates (AI-006 seed gap). `ai_releases` does contain 28 approved release rows for clinic metrics (months `2026-08` and `2026-09`, metric codes `complaint_*` and `completed_encounters`), so the mechanism exists, but the live comparison window (Sept vs Oct 2026) has no eligible data and the panel refuses to prepare anything.

**Notes:**
* Verdict is BLOCKED, not PASS: only the "unapproved drafts are not shown" half of the expectation could be observed.

---

### AI-011 — Disable analytics

**Verdict:** PASS

**Steps observed:**
1. As `[REDACTED: synthetic account identifier]` on `/index.php/admin/ai`, clicked **Pause aggregate AI**.
2. Ran `php spark ai:status`.
3. Signed in as `[REDACTED: synthetic account identifier]` (holding read + generate) and opened `/index.php/clinic/insights`.
4. Attempted the generation and prepare actions from the same signed-in session.

**Evidence:**
* Pause response: `Warning: AI analytics paused. Your provider settings and saved key were kept. Already sent requests cannot be recalled.`; status chip changed to `Disabled`; banner `AI analytics paused. Your provider settings and saved key were kept. Already sent requests cannot be recalled.`
* CLI: `php spark ai:status` → `enabled=0 configured=1 governanceApproved=0 revisionApproved=0 compatibilityVerified=0 revision=[REDACTED: AI configuration fingerprint]`.
* Insights panel as staff: both comparison cards rendered with `Prepare local summary` and `Request AI draft` **disabled** (`disabled:true` for `clinic.activity` and `clinic.complaints`); clicking produced no navigation, no flash and no request.
* Endpoint level (same session + CSRF token from the page): `POST /index.php/clinic/insights/generate` → **HTTP 422** `{"success": false, "reason": "invalid_request", "message": "The submitted action is not valid.", "feedback_type": "error", "csrf": {…}}`; `POST /index.php/clinic/insights/prepare` → **HTTP 422** with the same payload.

**Notes:**
* Analytics was left **disabled/paused** at the end of the run, which matches the state observed before this module started (`Connection verified · analytics paused`).

---

## Test data created

* **Clinical / guidance records:** none. Every attempt to prepare or queue an insight was refused by the eligibility rule, so **no new encounters, sessions, snapshots, jobs, drafts or reviews were created**. Read-only counts after the module: `clinic_encounters` = 2 (both 2026-11-01, both person [REDACTED: person record identifier]), `counselling_sessions` = 2 (both 2026-11-01), `ai_snapshots` = 2 (both pre-seeded 2026-10-30), `ai_jobs` = 2 (both pre-seeded), `ai_insights` = 1 (pre-seeded), `ai_reviews` = 1 (pre-seeded), `ai_dispatch_attempts` = 2 (pre-seeded).
* **AI access grants (net zero):** created and then revoked again through the admin console — `clinic/clinic_staff` `ai.insights.generate|review|approve` and `guidance/counsellor` `ai.insights.read|generate|review|approve`. The pre-seeded `clinic/clinic_staff ai.insights.read` grant was left untouched. Every create/remove produced its own `ai.access.grant` / `ai.access.revoke` audit event (these remain in the ledger as an honest audit trail).
* **Model catalogue:** one extra imported model (173 → 174) remains in the encrypted catalogue; the UI offers no control to remove an imported model, so it could not be reverted. Disclosed as the only non-reverted change.
* **Scratch files:** throwaway read-only helpers under `writable/` (`bb_ai_inspect.php`, `bb_ai_scan_tables.php`, `bb_ai_visit_counts.php`, `bb_ai_guidance_probe.php`, `bb_ai_counselling_probe.php`, `bb_ai_state.php`, `bb_ai_settings_probe.php`) — SELECT-only, credentials read from `.env` at runtime, nothing printed from `.env`. **They were deleted (moved to trash) at the end of the run.** No migrations, no package installs, no commits.

### Final state verification (after restoration, read from the live UI + CLI)

* AI Settings page (`/index.php/admin/ai`, as `[REDACTED: synthetic account identifier]`): endpoint `http://localhost:20128/v1`, active model `minimax/MiniMax-M3`, key field length `0` with `A key is saved. It is never sent back to this page.`, imported-model select = 175 options (174 models), banner `Connection verified · analytics paused`, status chip `Disabled`.
* `php spark ai:status` → `enabled=0 configured=1 governanceApproved=0 revisionApproved=0 compatibilityVerified=0`.
* `ai_department_access` contains only the 9 pre-seeded rows (`clinic/clinic_admin` ×4, `clinic/clinic_staff` read, `clinic/superadmin` ×4) — every grant created during this module was revoked again.
* `ai_jobs` = 2, `ai_insights` = 1, `ai_reviews` = 1, `ai_snapshots` = 2, `ai_dispatch_attempts` = 2 — all pre-seeded, nothing added.

### Settings changed and restored

| Setting | Value found at start | Change made during the module | Restored to |
| --- | --- | --- | --- |
| Provider endpoint (`#ai-base-url`) | `http://localhost:20128/v1` | re-entered the same value | `http://localhost:20128/v1` (unchanged) |
| Provider API key (`#ai-api-key`) | a working key for that endpoint (never displayed) | replaced with placeholder `[REDACTED: placeholder API key value]` for AI-001; the provider then reported `The endpoint rejected the API key.` | **restored** to a working key for the same endpoint, re-entered through the same UI form; verified with `Test connection` → `Connection successful. Endpoint, API key, model, and selected options worked.` The key value is deliberately not reproduced in this report. |
| Active model | `minimax/MiniMax-M3` | unchanged | `minimax/MiniMax-M3` |
| Imported model catalogue | 173 models | 174 models (one model imported for AI-001) | **not reverted** (no removal control in the UI) |
| Aggregate analytics | `Connection verified · analytics paused` (disabled) | enabled (Test connection + Enable aggregate AI) for AI-002/AI-006/AI-007/AI-008, then paused again for AI-011 | paused (matches the starting state) — `ai:status enabled=0` |
| Department AI access | seeded rows only (clinic_admin ×4, clinic_staff read, superadmin ×4) | grants added for clinic_staff and counsellor | all my grants revoked; only the seeded rows remain |
| Reasoning effort / token budgets / thinking mode / approved departments | `None`, input 130172, output 32768, thinking `Disabled`, Clinic+Guidance+BMG checked | unchanged | unchanged |

---

## Summary

| ID | Verdict | One-line reason |
| --- | --- | --- |
| AI-001 | PASS | Fetch returned 187 models, one imported (catalogue 173 → 174, active model unchanged) and the key was never shown again. |
| AI-002 | PASS | Saving showed `Aggregate analytics is paused until you enable it` and the panel's generation buttons were disabled until a fresh test + explicit enable. |
| AI-003 | PASS | After reload the key field is empty (`A key is saved. It is never sent back to this page.`) and the key string is absent from the served HTML. |
| AI-004 | PASS | Each of the four grant presses produced its own audited event (#153–#156) with chained hashes, and the panel enabled the actions once granted. |
| AI-005 | PASS | With `ai.insights.generate` revoked the panel offers no generation control at all and `ai_jobs` stayed at 2 rows — no job queued. |
| AI-006 | BLOCKED | Prepare was refused: `No comparison is available yet…` — the fixed Sept/Oct 2026 window has no eligible visits (all seeded encounters are 2026-11-01); encrypted snapshot storage confirmed on seeded rows. |
| AI-007 | BLOCKED | The request could not be queued (same eligibility refusal); no job row appeared, and `ai:dispatch` reported `processed=0`. |
| AI-008 | BLOCKED | The worker ran (`processed=0 failed=0 outcome_unknown=0`) but no queued job existed, so no new draft was produced. |
| AI-009 | BLOCKED | No draft was presented for review (the only draft is rejected/older-configuration), so no accept/reject/approve could be recorded in the UI. |
| AI-010 | BLOCKED | Unapproved drafts are correctly withheld (`No AI drafts yet`), but no approved release exists and no summary could be prepared, so approved-release presentation was not observable. |
| AI-011 | PASS | Pause analytics → `AI analytics paused`, `ai:status enabled=0`, panel buttons disabled, and both insight endpoints answered HTTP 422 `invalid_request`. |
