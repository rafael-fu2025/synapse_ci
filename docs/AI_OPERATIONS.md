# AI insights: implementation and operator runbook

Status (2026-10-29): **Broader six-analysis feature implemented; local schema through batch 34 installed, runtime disabled. Main final tests pending.**

This runbook describes the actual code, not every future feature in [the integration plan](AI_INTEGRATION_PLAN.md). Installing the code does not approve an endpoint, disclose a statistic, grant access, start a worker, or contact a model.

## Quick setup — use the UI

1. Sign in as a platform administrator and open **Administration → AI Settings** (`/admin/ai`). Existing `superadmin` plus `rbac.manage` authority is sufficient for configuration. The analysis-scope migration and compatible audit ledger are required for access changes; configuration access alone supplies no insight access.
2. Enter **Base URL** and **API key**, then click **Fetch models**. Preview/search the list, **Import selected** or **Import all**, then choose an **Imported model** to fill its exact ID and recommended thinking/token/options. Values remain editable. You can still enter a **manual exact model ID** if discovery is unsupported. Keep provider default reasoning unless your model supports the selected level. Under **Approved departments**, select at least one of Clinic, Guidance, or Facilities (BMG). These checkboxes authorize provider scope, not users or access to source records.
3. Click **Save settings**, then **Test connection**. The test sends a fixed harmless message, not departmental data, and can incur a small provider charge. It tests the saved configuration; unsaved edits cannot be tested accidentally. Department selection changes also require save, retest, and explicit re-enablement.
4. In **Department AI access**, explicitly grant or revoke Read, Generate, Review, and Approve for the intended role in the current tenant and department. Each change and its durable audit event commit in one transaction. Generate, Review, and Approve also require Read. Generation independently requires the source permission in Section 2; this panel never grants source-data authority.
5. Only after provider/data-processing authorization and operational-template review, explicitly confirm the authorization and click **Enable aggregate AI**. The UI handles the storage key, exact-origin policy, tested revision, approval bookkeeping, and pause/resume manifest automatically. Access grants and provider activation are separate actions.
6. In an authorized workstation, select a fixed analysis and use **Prepare local summary** or **Request AI draft**. Preparing performs local aggregate/disclosure/storage checks and freezes the approved snapshot; it still requires approved analytics scope/revision, ready encrypted storage, and source authority. Requesting only queues work. **A queued UI request does not execute or contact the endpoint without the separate worker.** See Section 5 for the finite worker and scheduling requirements.

**No SQL, manual `.env` edits, revision copying, or key-generation commands are necessary for UI provider setup or scoped AI role grants.** Optional commented deployment defaults are in the tracked `env` template; no private configuration is documented here. Saved UI settings override deployment provider defaults. Advanced settings support output-token field selection, JSON-object mode, bearer/API-key-header/no-auth, and an explicit trusted-private-server exception. Unsafe metadata destinations and redirects remain blocked.

Keys are never returned to the browser. Leaving the key field blank preserves it; removing it is an explicit action. Saving any configuration change pauses analytics and invalidates the previous connection test. Reload the saved configuration after an uncertain response rather than replaying the POST.

UI-managed settings are encrypted under `writable/ai-settings/provider.enc`, protected by the independently generated `settings.key`. The retained AI payload key is inside that encrypted profile and stays stable across provider changes. Back up the **entire private `writable/ai-settings/` directory** securely along with the database; losing either file can make settings or retained AI payloads unreadable. Never commit this directory or expose it over HTTP. These filesystem secrets do not protect against an attacker with full server filesystem access. Missing/corrupt saved material fails closed; it is not silently replaced with a new storage key.

The settings screen uses GET `/admin/ai` and `/admin/ai/status`; its protected actions are POST `/admin/ai/save`, `/admin/ai/test`, `/admin/ai/enable`, `/admin/ai/disable`, `/admin/ai/access`, `/admin/ai/models/preview`, and `/admin/ai/models/import`. Each department workspace supports GET its base path and `/panel`, and POST `/prepare`, `/generate`, `/{id}/review`, and `/jobs/{id}/cancel` beneath that base path. Review accepts only versioned `accept`, `reject`, or `approve` decisions. The workstation path determines the unit, never a submitted tenant/unit override.

Settings pages and department insight routes bypass debug-toolbar collection, including rejected requests, so request bodies containing API keys are not persisted in debugbar artifacts. Save/test/enable/pause are CSRF-protected POSTs with fresh platform-administration checks, version checks, secret-safe failures, and encrypted change metadata. The UI never logs or stores keys in browser storage.

Input budget uses a conservative UTF-8 byte-plus-framing allowance, not an exact tokenizer or a provider `max_input_tokens` field. Output is sent as `max_tokens` or `max_completion_tokens`. Explicit reasoning is sent as `reasoning_effort`; provider-default omits it. **Advanced connection options → Thinking mode** independently sends `thinking: {"type":"disabled"}` or `thinking: {"type":"enabled"}`; provider default omits that field. MiniMax M3 requires the explicit disabled thinking mode to turn thinking off; reasoning effort `none` alone is not equivalent. Changing thinking mode requires save and retest. Unsupported models/options fail visibly without automatic fallback or retry.

The separate bounded background worker still executes queued analytics; connection configuration and a successful harmless probe do not by themselves start a worker or generate a clinical report. The rest of this runbook covers operations and the optional deployment-managed alternative.

### Model discovery and import

**Fetch models** uses the currently entered endpoint/authentication/private-network controls, even before a model or provider profile has been saved. It performs one server-side authenticated GET to the fixed `/models` path under the API base, with the same exact-origin, all-answer DNS validation, connection pinning, TLS, no-proxy, no-redirect, bounded-size, and timeout protections as completions. No completion request, patient data, or departmental aggregate is sent. Discovery is limited to ten attempts per minute per tenant, including failed requests.

For the unchanged saved endpoint and authentication mode, a blank key reuses the encrypted key without returning it to the browser. **Changing the API base or authentication mode requires entering a key again**; discovery never forwards a saved key to a newly typed endpoint. A trusted private HTTP endpoint still requires the explicit private-network checkbox.

Preview/import supports up to **200 models** and OpenAI-compatible `{ "data": [...] }` responses. Unsupported `/models`, authentication errors, rate limits, malformed lists, oversized responses, or excessive catalogs leave manual ID entry available. A provider may list embeddings or other non-chat models; discovery is not a chat-compatibility certificate. Entries with an explicit context smaller than the minimum application budget are omitted rather than preventing discovery of usable models. Model IDs remain opaque and case-sensitive, including namespaced IDs, local tags, and Unicode.

Capacity facts are taken only from explicit provider metadata (`context_window`, `context_length`, `max_model_len`, `max_output_tokens`, `max_completion_tokens`, supported nested provider fields). Thinking support/options come from supported-parameter/capability metadata when supplied. Unreported capacities remain **Unknown**. App budgets stay within local safety limits, preserve the current budget conservatively, and reserve output/overhead when context is known; they are not exact provider-tokenizer limits. Reviewed MiniMax-M3 recommendations disable thinking without inventing a context size. The preview labels provider facts, reviewed presets, local recommendations, and unknowns separately. It does not infer arbitrary model capabilities from names or perform per-model completion probes.

**Import selected** adds models to the existing catalog for the same API base. **Import all** replaces that catalog with the fresh preview, removing retired entries; it imports the entire preview even when a search filter is active. Importing from another base replaces the old-base catalog rather than mixing providers. Previews are server-staged, expire after ten minutes, and are bound to tenant and administrator with single-use tokens. Submitted IDs can only select staged records; client-submitted defaults are not accepted.

Imported metadata persists encrypted under `writable/ai-settings/model-catalog/` with an independent key and tenant-bound records. Include it in the existing private settings-directory backup. Catalog import is not model deployment, downloading weights, granting access, or enabling analytics. Selecting an imported model explicitly fills **unsaved** values; only **Save → Test connection → Enable** changes the approved active configuration. Endpoint mismatches disable the old-base picker. Editing connection details invalidates the preview, and neither discovery nor import replays a failed POST automatically.

Local verification fetched and imported **173 models** from the configured loopback endpoint using fresh database-backed administrator authority. All 173 included explicit context/output capacities; thinking metadata was present for 30 and unknown for 143. The selected MiniMax-M3 row recommended disabled thinking. A read-only render confirmed the persisted 173-row catalog, bounded status payload, and absence of the raw API key from HTML/summary. Provider settings, active model, tested status, permissions, and analytics state were unchanged. No database migration is needed for catalog storage.

After model-discovery integration, the isolated AI plus actual realtime-transport suite passed **2,024 tests, 19,269 assertions, two platform skips**. Settings/discovery/department-insights/auth browser suites passed **128 tests** after the header/toast presentation change (settings HTTP/model-connection suites: **347 tests, 3,891 assertions**). Browser discovery coverage uses synthetic HTTP fixtures, while the 173-model provider fetch/import and live-view render are separate live checks; no end-to-end authenticated live-browser discovery claim is made.

## 1. Implemented scope

Staff workspaces are `/clinic/insights`, `/guidance/insights`, and `/facilities/insights` (internal unit `bmg`). All use the last complete Asia/Manila calendar month and its immediate predecessor, with no arbitrary dates, cohorts, patient selectors, SQL, or prompts.

| Fixed analysis code | Evidence and limits |
| --- | --- |
| `clinic.activity` | Completed encounters by `started_at`; counts are encounters, not unique people. |
| `clinic.complaints` | Fixed recorded complaint-category counts and shares of all completed encounters; exact matching after case/edge-space normalization, not narrative analysis or diagnosis. |
| `guidance.activity` | Completed counselling sessions by `started_at`; counts are sessions, not unique people or cases. |
| `guidance.themes` | Eligible fixed recorded case categories: academic, career, behavioral, family. Shares use all completed sessions. Personal/crisis, missing, and unmapped categories stay internal; no themes are inferred from notes. |
| `bmg.batches` | Starts by `started_at`; released/voided terminal events by `completed_at`, in separate monthly cohorts. Not completion rates, durations, or output quality. |
| `bmg.rotations` | Accepted reports by `occurred_at` stored in Manila time; reported failure/interruption shares of all accepted reports. Not physical rotation, uptime, reliability, schedule compliance, compost quality, or sanitation verification. |

`clinic.activity` retains the legacy schema-v1 collector/payload and maps old snapshots to that analysis. The other five analyses use schema v2 with an explicit `analysis_code`; the upgrade does not rewrite legacy ciphertext. Clinic complaints use exactly: headache, fever, dizziness, abdominal pain, cough, colds, toothache, injury, nausea, vomiting, menstrual cramps, and checkup. Guidance categories are exact stored values, not text normalization or note-derived topics. Personal/crisis category labels and counts are never emitted as evidence; their events still contribute to the full denominator and internal suppression checks.

- Health queries include retained soft-deleted completed records and linked people. Source timestamps use the configured application storage timezone, except rotation reports' explicit Manila wall time. Review source conventions/coverage before activation.
- SQL returns only fixed aggregate aliases and contributor/complement counts. Person IDs are used inside tenant-safe SQL for distinct-contributor checks, never returned as person rows or sent to the model. Clinic complaint text is matched inside SQL to fixed constants; raw complaint text, narratives, counselling notes, identifiers, and free text do not leave the collector.
- Both health months require at least the configured minimum distinct contributors (default/minimum configuration 10) and no unlinked contributors. Category release also checks each month's category and distinct-person complement independently. The complement is distinct people with events in other buckets, not total people minus category people, because people can occur in multiple buckets. Monthly complementary suppression protects the hidden pool before intersecting eligible categories across the pair and checking both hidden pools again. Suppressed values/labels are omitted, not represented as zero or inferred by the model. If no category is releasable, the categorical comparison is blocked.
- Shares retain the full source-event denominator, including unreleased categories. BMG does not use a clinical-contributor threshold: its inputs are operational event counts. Invalid source statuses block release; no recorded events across both BMG months produces insufficient data. A zero recorded denominator makes a share undefined, not zero.
- Immutable snapshots and durable keyed release fingerprints freeze **all released monthly metric values, their denominators, and category publication masks** across overlapping comparisons. Shared totals also bind activity and category analyses. A changed value, denominator, or published category subset rejects the overlapping release with `snapshot_changed_requires_review`; fingerprints survive payload expiry. Category history without the required publication-mask guard is also rejected rather than retroactively approved. These are conservative controls, **not a privacy certification or guarantee of anonymity**.
- Deterministic arithmetic and descriptive signals precede bounded AI explanation. Server-owned eligible operational templates, accept/reject/program-approval transitions, optimistic version checks, and review history keep approval separate from generation.
- Encrypted snapshot/draft storage, tenant/unit/analysis scope, deduplication, one-shot transport reservations, bounded processing, ambiguous-outcome handling, and cross-process pause/resume controls are implemented.
- Scoped browser actions do not generate on page reads, refresh, or polling, and do not automatically replay mutations.

The current library/prompt capability is **`department-programs-v2:prompt-v2`**. Fixed options cover clinic capacity/access and general awareness review, guidance capacity/access and broad voluntary support review, and BMG workload, batch-record, and qualified maintenance-inspection review. Eligibility binds analysis, metric, and evidence; the model cannot invent program templates. References provide background/oversight, not proof of local causes or template efficacy. Department owners must review the exact library before activation; model prose remains fallible review material.

**Out of scope:** diagnosis, treatment recommendations, outbreak detection, structured-survey analysis, patient prediction, counselling-note access, cross-department person profiling, multiple selectable provider profiles, native non-compatible provider protocols, native `json_schema` mode, free-form chat, staff text editing of model drafts, announcement/Teams integration, AI report exports, and AI notifications. Program approval alone performs no publication, spending, scheduling, outreach, or hardware action.

## 2. Access and installation boundaries

No **department insight** permission is automatically granted, including to superadmin. Provider configuration is available to a `superadmin` with existing `rbac.manage` authority, or the appropriate explicit configuration grant. Configuration access does not confer insight or source-data access. Normal department/host checks still apply.

| Permission | Actual use |
| --- | --- |
| `ai.insights.read` | Read the authorized department insight workspace/results |
| `ai.insights.generate` | Prepare a protected local summary, queue an AI draft, or cancel queued work; also requires Read and the independent source permission below |
| `ai.insights.review` | Accept or reject a draft; also requires read |
| `ai.insights.approve` | Approve an already accepted operational program draft; also requires read |
| `ai.configuration.read` | Provider settings without saved secrets; superadmin with this grant or `rbac.manage` |
| `ai.configuration.write` | Encrypted provider save and aggregate activation/pause; superadmin with this grant or `rbac.manage` |
| `ai.configuration.test` | Harmless saved-profile probe; superadmin with this grant or `rbac.manage`; managed profile updates also require write authority |

Generation requires independent source authority: Clinic **`clinic.encounters.read`**, Guidance **`guidance.cases.read_any`** (own-cases permission is insufficient), BMG **`facilities.read`**. The worker rechecks the requester's fresh account, tenant/unit AI grants, and source permission before dispatch and result finalization. Neither a department checkbox nor an AI grant supplies source authority.

The UI access panel stores grants in `ai_department_access` by **tenant + department + role + permission**, not per user and not globally across tenants. Eligible roles are `clinic_admin`, `clinic_staff`, `superadmin` for Clinic; `guidance_admin`, `guidance_supervisor`, `counsellor`, `superadmin` for Guidance; `bmg_admin`, `facilities_op`, `superadmin` for BMG. Changes are explicitly audited and take effect on the next authorization check. Unavailable access/audit storage blocks changes.

The panel manages its scoped grants only. Existing explicit RBAC permissions remain effective; revoking a panel grant does not negate a separate RBAC grant. Remove obsolete explicit RBAC permissions through existing access administration too. There is no blanket report-viewer, audit-reader, or platform-owner bypass. Approval uses `ai.insights.approve`, not `ai.programs.approve`.

`AiPermissionsSeeder` adds catalog entries only; it does not grant roles. **Do not run the general `PermissionsAndRolesSeeder` against an operational installation to enable AI:** that existing seeder replaces the complete role-permission map. No SQL or seeder run is needed for normal UI-managed AI grants once the required schema/audit ledger is installed.

The original migration is `app/Database/Migrations/2026-10-28-000001_CreateAiInsights.php`, class `CreateAiInsights`. It creates:

- `ai_releases`: persistent monthly disclosure fingerprints.
- `ai_snapshots`: encrypted frozen approved aggregates.
- `ai_jobs`: queue, deduplication, lease and enqueue-budget metadata.
- `ai_dispatch_attempts`: actual dispatch-day budget reservations, tenant-wide across revisions.
- `ai_insights`: encrypted validated drafts and review state.
- `ai_reviews`: versioned review decisions.

The broader feature adds `app/Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php`, class `AddAiAnalysisScope`. It adds `ai_snapshots.analysis_code` (legacy default `clinic.activity`), replaces snapshot uniqueness with tenant/unit/analysis/period, and creates `ai_department_access` with no default grants. It preserves existing encrypted payloads and release history. Storage checks reject an incomplete unique-key transition.

**Recorded local installation:** `CreateAiInsights` remains recorded in local `humus_db` as batch **33**. `AddAiAnalysisScope` was subsequently applied as batch **34**. The scope deployment preserved all **65 unrelated table definitions** and the earlier batch-33 history. The six original AI tables remained empty, and `ai_department_access` was created without grants. This is a local schema installation, not production validation or AI activation. No live provider configuration/call, role grant, worker scheduling, or AI enablement was performed.

Pre-scope private backup: `writable/backups/humus_db_before_ai_analysis_scope_20261029_062414_a7e975.sql`. Its SHA-256 was verified; **no full backup restore test was performed**. A separate disposable MariaDB migration rehearsal was completed; it is not a restore test of this dump. This records the supplied local deployment outcome, not a migration/backup verification rerun during documentation editing. The earlier pre-batch-33 backup is `writable/backups/humus_db_before_ai_insights_20261028_210024_ae9b71.sql` (also recorded as SHA-256 verified, not restore-tested). Backup/dump/evidence files are private operator artifacts; never expose them over HTTP or commit their contents.

For other installations, identify the intended database/tenant, take and verify a backup, inspect migration history, and use a reviewed deployment procedure targeting these additive migrations in order. Do not run an unrestricted migration command that also applies unrelated pending migrations. MySQL/MariaDB DDL can auto-commit; partial installation must remain disabled until checked. `InsightStore::ready()` verifies required columns and uniqueness before using storage.

Do not execute migration `down()` as a feature-disable procedure: the original migration drops AI records including disclosure/review history, and `AddAiAnalysisScope::down()` deliberately refuses automatic rollback because several analyses can share a month. Disable/pause instead and retain evidence under institutional policy.

## 3. Custom endpoint configuration

For normal setup, use the UI described above. Defaults live in `app/Config/Ai.php`; optional non-secret examples are in the tracked `env` template. UI-managed credentials are saved encrypted, not written into `.env`. The following is the alternative deployment-managed contract for installations that do not use a saved UI profile. Use private `.env` or the deployment secret manager for real deployment-managed credentials. Never place secrets in browser assets, URLs, logs, commits, job payloads, or documentation.

Example **shape only**, with no real provider or credential:

```dotenv
ai.enabled = false
ai.profileId = 'clinic-reviewed'
ai.adapter = 'openai_chat_completions'
ai.baseUrl = 'https://ai-gateway.example.org/team-a/v1'
ai.allowedOrigins = 'https://ai-gateway.example.org'
ai.model = 'organization/model@revision'
ai.reasoningEffort = ''
ai.maxInputTokens = 8192
ai.maxOutputTokens = 2000
ai.authMode = 'bearer'
ai.apiKey = ''
ai.credentialVersion = '1'
ai.maxOutputTokensField = 'max_tokens'
ai.structuredOutputMode = 'none'
ai.resolverPhpBinary = ''
ai.minimumContributors = 10
ai.retentionDays = 30
ai.approvedTenants = ''
# Reviewed subset of clinic,guidance,bmg; selecting units alone does not enable AI.
ai.approvedUnits = 'clinic'
ai.encryptionKey = ''
ai.governanceApproved = false
ai.programLibraryApproved = false
ai.capabilityRevision = 'department-programs-v2:prompt-v2'
ai.approvedRevision = ''
ai.compatibilityApprovedRevision = ''
```

`approvedUnits` is optional deployment configuration and defaults to `clinic`; use only an explicitly reviewed subset of `clinic,guidance,bmg`. Legacy saved UI profiles without a department list also remain Clinic-only. Selecting departments never supplies role/source permissions or enables dispatch by itself.

Base URL semantics are exact: the example sends to `https://ai-gateway.example.org/team-a/v1/chat/completions`. The adapter preserves `/team-a/v1`, does not add another `/v1`, and rejects a base already ending in `/chat/completions`. URLs cannot contain userinfo, query credentials, fragments, encoded separators, or traversal segments.

- Model identifiers are opaque nonempty UTF-8, maximum **191 bytes**, with no whitespace/control characters; `/`, `@`, and `+` can be used if the endpoint supports them.
- `authMode`: `bearer`, `api_key_header`, or explicitly approved `none`. Empty credentials otherwise fail closed. Header mode uses `apiKeyHeader` and its deployment-owned header allowlist; no arbitrary browser headers/body overrides are accepted.
- `maxOutputTokensField`: `max_tokens` or `max_completion_tokens`, verified for this endpoint/model.
- `structuredOutputMode`: `none` or `json_object`. Both still require strict local output-schema validation. `json_schema`, unsupported options, and native unrelated APIs are rejected, not silently downgraded.
- `connectTimeoutSeconds`: 1–30; `requestTimeoutSeconds`: 1–90, not less than connect timeout. DNS resolution has its own bounded preflight within the connect limit; transport has its configured overall limit, and the worker rechecks its remaining lease/deadline after DNS.
- Defaults: request 65,536 bytes; response 262,144 bytes, capped while receiving; output 2,000 tokens; 50 tenant-wide daily enqueue slots and 50 actual UTC dispatch-day slots; at most five jobs per finite worker run.
- The current worker supports **one concurrent request and one attempt**. `maxConcurrentRequests` and `maxAttempts` must both be `1`. Failed or ambiguous requests are not automatically repaired or retried.

### DNS, TLS, and self-hosted endpoints

Every completion validates all resolved IPv4/IPv6 answers and pins an approved address using cURL while preserving hostname/SNI verification. Redirects, inherited proxies, browser cookies, and shared connection state are disabled. Metadata/link-local/special-use addresses are denied. Private exceptions are exact origins/ports and must also appear in `allowedOrigins`; there is no global private-network bypass.

DNS runs in a secret-scrubbed, bounded child PHP CLI process. `resolverPhpBinary` should point to a trusted absolute CLI executable where the build-time `PHP_BINDIR/php(.exe)` default is absent. Discover the installed CLI path locally with `php -r "echo PHP_BINARY, PHP_EOL;"`; verify it independently before configuration. Do not use PHP-FPM, a shell wrapper, or PATH lookup. The child loads no framework or dotenv. `proc_open`, termination/status functions, cURL, and OpenSSL must be available for their respective operations. A missing capability fails closed.

For internal HTTPS, set `caBundle` to a managed CA file if needed; certificate and hostname validation cannot be disabled. Example private-origin shape: `https://ai.internal.example:8443`, explicitly present in both origin lists. Review DNS ownership, telemetry, upstream forwarding, logs, and administrative access too.

Plain HTTP is an exceptional explicit local mode. `allowLocalHttp=true` plus a private-origin exception permits synthetic testing only; real dispatch also requires `allowPrivateHttpDispatch=true`, all normal approvals, and a documented isolated-network risk acceptance. Prefer HTTPS for production. No local-to-cloud, model, protocol, or endpoint fallback is implemented.

## 4. Encryption, retention, and release corrections

`encryptionKey` is base64 of exactly 32 random bytes, distinct from provider/MIS/counseling/survey secrets. UI setup generates and preserves it automatically inside the encrypted profile. For the deployment-managed alternative only, generate and install it through your secret-management procedure. The v1 envelope uses authenticated AES-256-GCM with randomized nonces and tenant/unit/entity binding; stored release fingerprints use a separate derived HMAC key.

**The implementation has one storage key, not an online multi-version rotation mechanism.** Do not replace it after data exists: older payloads become unreadable and release fingerprints cease to match. Back up and retain the key securely. Key rotation requires an explicitly designed, verified re-encryption/fingerprint migration preserving release history; no automatic rotation is supplied. Bump `credentialVersion` for provider-secret rotation; storage-key changes also require that version bump but it is not a substitute for migrating encrypted history.

Snapshots expire after `retentionDays` (default 30). Draft payloads expire after 90 days, including approved program drafts. `ai:purge --tenant N --unit clinic` erases up to 100 expired payloads per table per invocation and expires stale jobs in that unit. Repeat explicitly for `guidance` and `bmg`, including units later revoked from provider approval; omitting `--unit` purges Clinic only. `N` denotes the reviewed positive tenant ID, not a literal argument. Freeze fingerprints, dispatch-budget reservations, job metadata, and review decisions remain. These are not an automatic 90-day purge of all metadata or an institution-approved indefinite-retention policy. Approve metadata/audit/backup retention, legal holds, and any longer approved-program retention before production; those broader policies are not implemented by this command.

A corrected source count, denominator, or changed category publication mask can block a new overlapping release, even after the earlier payload was erased. This is intentional protection against reconstructing values from adjacent reports. There is no self-service override, blanket deletion of fingerprints, or automatic corrected release. Escalate to the DPO/department owner. Never delete release history merely to make generation succeed.

## 5. Activation, CLI worker, and scheduling

For UI-managed setup, follow **Save settings → Test connection → Enable aggregate AI** above, then arrange the separate worker/scheduler described below. There is currently **no UI control to start, schedule, or supervise the worker**. The numbered sequence is the alternative for deployment-managed provider configuration; do not manually copy revision acknowledgments over a saved UI profile.

This sequence requires deployment, privacy, department-operations, endpoint contract, and billing authorization. A developer's approval of the implementation plan alone is not a provider data-processing approval.

1. Keep AI paused/disabled while installing and verifying the isolated schema, encryption, catalog, and deliberate role grants. Verify database backup/restore evidence and all source metric/timezone definitions. Use the UI access panel for scoped AI grants; source permissions remain separate.
2. Review the approved tenants and `approvedUnits` subset (`clinic`, `guidance`, `bmg`), contributor/suppression policy, exact `department-programs-v2:prompt-v2` library/prompt capability, provider retention/training/subprocessors/location/incident terms, transport network/CA policy, and budgets. No-training settings do not imply zero retention.
3. Set endpoint/model/authentication, resolver binary, and network policy privately. `ai.enabled=false` and governance may remain false for the first synthetic compatibility test. `approvedTenants` must explicitly include the test tenant, because even a synthetic request can cost money.
4. Run `php spark ai:status`. It prints redacted flags and the effective public configuration revision, without SQL/network requests or secrets.
5. With explicit billing/network permission, run `php spark ai:probe --tenant 1` **only if tenant 1 is the reviewed target**. This sends a fixed harmless JSON fixture. Never substitute clinical data into the probe. Browser testing uses the same fixture at the configured endpoint, is POST/CSRF protected, checks fresh authority, and is limited to one active test and ten attempts per minute per tenant across sessions/CLI.
6. Once the final settings are reviewed, set `enabled`, `governanceApproved`, and `programLibraryApproved` to their approved values. Run `ai:status` again: flags and other non-secret settings participate in the revision. Then rerun the synthetic probe for the exact final revision. Set both `approvedRevision` and `compatibilityApprovedRevision` to that verified revision through deployment configuration. Neither acknowledgment participates in the hash, avoiding a circular revision.
7. Run `php spark ai:resume --tenant 1` with the reviewed actual tenant. This writes `writable/ai-dispatch-control.json`, binding the revision to the current private configuration/code source stamp. It does not start a worker, create a report, or call the provider. A missing/unreadable/paused/mismatched manifest denies dispatch.
8. In an explicitly granted department account, open `/clinic/insights`, `/guidance/insights`, or `/facilities/insights` and select a supported analysis. **Prepare local summary** performs local aggregate/disclosure checks only. **Request AI draft** queues a deduplicated job only. Both require eligible completed-month evidence; imported directory size does not establish clinical contributor coverage.
9. Only after approving real protected-analytics transmission, run `php spark ai:dispatch --tenant 1` if tenant 1 is the reviewed target, and inspect count-only output and the restricted draft UI. This processes all approved units under one shared budget. To restrict it, append `--unit clinic`, `--unit guidance`, or `--unit bmg`. Do not use dispatch as a harmless connectivity check: it can send approved statistics and incur charges.
10. Review output and record acceptance/rejection, then separately approve eligible program drafts. No publication, patient action, or hardware control occurs. Evaluate usefulness and errors against the ordinary analytics/template baseline before activation/expansion.

**There is no automatic worker scheduling. UI queueing does not execute jobs without a separately invoked worker.** For deployment, configure a finite `php spark ai:dispatch --tenant N` invocation under a restricted account, normally once per minute without overlapping instances, using the real PHP executable and project working directory. Replace `N` with the reviewed positive tenant ID; no tenant defaults to 1.

**Verified CLI contract:** `ai:dispatch --tenant N [--unit clinic|guidance|bmg]`. Omitting `--unit` covers all approved departments with one shared job/time budget; **`--unit all` is not supported and is rejected**. Defaults remain at most five jobs per run, 120 seconds total, one concurrent request, and one attempt; failures/ambiguous outcomes are not automatically retried. Daily enqueue and dispatch limits are tenant-wide across departments. OS/database stalls still require an external task timeout and supervision.

Run separate finite cleanup tasks with `ai:purge --tenant N --unit clinic`, `--unit guidance`, and `--unit bmg` under the approved retention schedule. `ai:resume --tenant N` defaults to validating the first approved unit; optional `--unit` accepts only those three unit names. Resume authorizes the reviewed manifest, not a worker schedule. `ai:pause` takes no tenant/unit options and pauses the shared manifest.

Do not assume the development notification task's interactive-user schedule is a production AI service. Test worker restart, permissions, overlap, failure monitoring, backup access, and availability while administrators are signed out.

### Understanding the department Insights page

The page displays the exact two completed comparison months in Philippine time; the current partial month is excluded. **Prepare local summary** checks data eligibility and prepares approved figures locally without calling the AI service. **Request AI draft** checks eligibility and queues a request; the separate worker must run before a draft can appear. Approved configuration does not establish eligible source coverage or a running worker.

Action results use shared success/info/error toasts, with a sticky warning for unavailable comparisons. Confirmed insufficient-data responses explain the department's completed-record, contributor/link, and privacy requirements and explicitly distinguish them from AI connection errors. They do not display small contributor counts or infer unavailable values as zero. Confirmed refusals do not receive an ambiguous-network retry warning. An unconfirmed request instead advises checking status before resubmitting; no mutation is replayed automatically. A styled alert is the fallback when the toast service is unavailable. Realtime/poll reads remain quiet and do not generate repeated notification toasts.

Expandable privacy and eligibility guidance replaces the long introductory warning wall. Request states use plain language (for example, **Waiting for a worker**, **Processing**, and **Draft ready**) rather than raw internal status/reason codes. The local-summary view retains all approved figures and privacy limitations, with simpler labels and expandable details. This is a presentation change, not a relaxation of privacy, approval, or validation rules.

Validation after this clarity update: isolated AI plus real realtime transport **2,034 tests, 19,378 assertions, two skips**; combined Insights/settings/discovery/auth/toast browser suites **144 passed**. These used synthetic test data, not actual patient records.

### Realtime results

AI workspaces use the shared WebSocket connection. Tickets grant `private.tenant.N.ai.insights.UNIT` only after fresh department AI-read and source-data authorization. Committed snapshot, queue, worker, review, and retention changes publish `ai.insights.changed` with only the department unit. No model tokens, drafts, statistics, credentials, or failure details are broadcast. Other tenants and departments cannot subscribe through ordinary tickets.

The page debounces notifications and refetches its authenticated panel, so validated results and queue states appear without manual refresh. Reconnection, visibility/resume handling, and bounded fallback polling reconcile missed messages. Delivery is best-effort, not durable replay. Existing ticket grants last until their expiry; each panel read independently rechecks access.

Each recent request now shows persisted-state progress: **Queued → Generating and checking → Ready for review**. Queued requests show a waiting indicator; running requests show an accessible indeterminate spinner; completed requests show a checkmark. Failed, cancelled, expired, or unconfirmed requests show stopped-state guidance, not a fabricated completion percentage. WebSocket notifications refetch these states without exposing event-supplied drafts or tokens.

**This is live validated-result refresh, not token-by-token model streaming.** Unvalidated model content must not bypass output checks. The worker and existing realtime hub/publisher must be running; WebSockets do not execute queued generation jobs.

During the queued-clinic investigation, the existing scheduled task was confirmed to handle workflow notifications, not AI dispatch. Spark also passed identical parsed options in both `CLI::getOptions()` and command parameters; the earlier duplicate-key guard prevented command dispatch. The AI command adapters now accept identical overlap and reject conflicting values, with regression tests. One authorized direct worker run finalized the two existing clinic requests: one succeeded and one failed output validation; neither terminal request was retried. A subsequent fixed `ai:dispatch --tenant 1 --unit clinic` invocation completed successfully with no further work (`processed=0 failed=0 outcome_unknown=0`). No automatic scheduler or approval change was installed. The final isolated AI/realtime suite passed 2,042 tests with 19,558 assertions and two skips; all 148 combined browser tests passed. An initial combined PHP run hit a transport timeout; its standalone transport rerun and the subsequent full serial run passed.

## 6. Pause, change, and incident procedures

`php spark ai:pause` revokes the manifest independently of a valid provider profile or encryption key. Stop/drain workers as part of any code, secret, endpoint, model, policy, or deployment environment change. Already transmitted requests cannot be recalled by a feature flag.

Workers check the manifest before claiming, after DNS immediately before transport, and before finalization. The private on-disk stamp detects `.env` and selected pipeline code changes even in an existing process; its HMAC is neither displayed nor sent. Reopening `Config\Ai` does **not** reload dotenv into a running process. OS-provided environment/secret-manager changes likewise require explicit pause, process restart, current-revision verification, and resume. On multiple application hosts, share an appropriately secured authoritative control location or coordinate pause/stop across every host; the default local manifest is not a cluster-wide control service.

After a change: pause, stop/drain, update/review configuration, restart processes, recalculate status, repeat synthetic compatibility testing as needed, acknowledge the new revision, then resume. Any `.env` edit invalidates the source stamp even if unrelated to AI; an authorized operator must resume after verifying the change. Do not automatically rewrite approvals on an error.

`outcome_unknown` means the provider may have processed/billed the request or the worker lost its lease after possible dispatch. Do not reset it to queued or replay its POST. Preserve budget/release metadata, investigate provider-side request evidence without collecting patient data, and make a reviewed decision. Existing jobs deduplicate across authorized requesters for the same department/snapshot/profile/model; failed/ambiguous job IDs are terminal in this release.

Provider errors are application availability/configuration errors, not browser session expiry. Only the shared authenticated-user contract may redirect to login. Do not log raw provider errors, prompts/responses, headers, credentials, session tokens, database exceptions, or record details to troubleshoot.

## 7. Validation and limitations

The AI suite passed locally after the thinking-mode, localhost-resolution, and realtime changes: **1,808 tests, 17,589 assertions, one expected platform skip**, including the actual WebSocket transport test. The AI/settings/auth browser run passed **108 tests**. Separate realtime coverage exercised actual HTTP publishing and WebSocket frames with disposable SQLite authorization/state, including tenant/department isolation and rollback behavior. These are not production-readiness certification. The local schema installation and supplied backup-verification record in Section 2 are separate from application acceptance; SHA verification is not a restore test.

The separate disposable MariaDB migration rehearsal is recorded as completed, not as full application acceptance, a full dump restore, or live-provider validation. The checked-in tests cover synthetic aggregates, mocked HTTP/DNS, bounded synthetic subprocesses, disposable SQLite storage, migration preservation, analysis isolation, source/AI permission separation, scoped grant/audit transactions, department settings, program eligibility, worker commands, and browser fixtures. The checked-in loopback endpoint test is a synthetic transport test. Separately, the user-supplied local endpoint at `http://localhost:20128/v1` and `minimax/MiniMax-M3` passed the fixed probe and all six synthetic departmental analyses through the production client and output validator with thinking disabled. Initial synthetic output rejections did not recur; no automatic retry or relaxed validator was introduced. No patient records or actual departmental aggregates were sent. The encrypted saved profile is marked tested but analytics remains disabled and dispatch paused.

Suggested isolated validation commands (not results; the AI runtime bootstrap isolates writable storage and avoids dotenv):

```sh
php vendor/bin/phpunit --no-configuration --bootstrap tests/_support/ai-runtime-bootstrap.php --no-coverage --no-logging --do-not-cache-result tests/unit/AiDepartmentAnalyticsTest.php tests/unit/AiAnalysisScopeMigrationTest.php tests/unit/AiAnalysisScopeStoreTest.php tests/unit/AiGrantStoreTest.php tests/unit/AiDepartmentSettingsTest.php tests/unit/AiDepartmentProgramsTest.php tests/unit/AiDepartmentRuntimeCommandsTest.php tests/unit/AiDepartmentRuntimeTest.php tests/unit/AiDepartmentInsightsHttpTest.php
node --test tests/browser/ai-department-insights.test.cjs tests/browser/ai-settings.test.cjs tests/browser/ai-insights.test.cjs
```

Also run the remaining AI, shared authorization/session, and application regression suites before acceptance. Validate concurrent workers on a **fresh disposable MariaDB schema**, not operational records. Backup restore, live endpoint compatibility, TLS/CA/DNS, model/protocol/options, deployment secrets, data quality/timezones, deliberate access-grant cutover, worker supervision, and privacy/network/billing acceptance remain deployment-specific gates. MySQL was unavailable during the initial provider verification. A subsequent live recheck after the user restored MySQL confirmed connectivity, both exact migration history entries (batches 33 and 34), all seven AI tables, `InsightStore::ready()`, analysis-scope support, cipher readiness, and access/audit storage readiness. The current tenant administrator passed fresh DB-backed configuration authorization. The real settings view rendered the saved model, disabled thinking mode, verified connection status, available Enable control, and ready department-access panel without exposing the key. The production synthetic probe passed again, and both configured realtime listeners accepted bounded TCP connections. The seven AI tables remained empty and no department AI grants exist, so department insight access remains correctly denied and analytics remains paused. No database repair, new role grants, real-aggregate dispatch, or AI enablement was performed. The focused settings/department HTTP and realtime regression run passed 186 tests with 2,793 assertions. Authenticated browser login and a single live-MySQL queue-to-provider-to-browser chain were not exercised; a real WebSocket transport test and browser refetch tests passed separately.

Monthly suppression, frozen values/denominators/publication masks, and passing synthetic tests do not certify privacy/compliance. Fixed complaint categories are not disease detection; guidance categories are not individual risk or diagnoses; BMG reports do not verify safe physical operation. Additional domains and integrations require separate review.
