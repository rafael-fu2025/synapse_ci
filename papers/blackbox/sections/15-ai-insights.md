# 15 — AI insights

AI/provider execution is allowed only in the gated disposable campaign described by the RUNBOOK. Cases remain `NOT_RUN`; no prior tracker verdict is inherited.

## AI-001 — Provider discovery stages models without changing production settings
- Lifecycle: `READY`
- Legacy IDs: `AI-001`
- Changed by: `bea71cf`, `8674603`
- Requirement: An authorized model discovery must use an approved endpoint profile, bounded/rate-limited response, release the session during network work, revalidate actor and settings revision afterward, and stage a private preview before selective import.
- Surface: AI configuration web console.
- Route/API: `POST /admin/ai/models/preview`; `POST /admin/ai/models/import`.
- Actor/grants: Platform administrator with `ai.configuration.write`.
- Preconditions: Disposable tenant; synthetic local provider with deterministic valid/invalid model lists; no patient data.

**Steps**
1. Fetch models from the synthetic provider and inspect preview count/token while separately recording saved settings, active model, and analytics state.
2. Import a selected subset, then inspect the catalogue and settings again.
3. During another discovery change the actor role or saved settings; also return duplicate, oversized, and malformed catalogues.

- Expected: Discovery/import affects only staged/imported catalogue entries; current settings/model/analytics remain unchanged; actor/settings race and invalid catalogues fail closed.
- Negative/non-disclosure: Raw API key, preview secret, provider response beyond selected metadata, patient data, session internals, and private-network access without opt-in are absent.
- Cleanup: Remove synthetic imported models and provider credentials; retire DB.
- Source: `app/Controllers/AiConfigurationController.php:62-102`; `app/Controllers/AiConfigurationController.php:271-299`
- Evidence: Sanitized before/after settings/catalogue, provider request summary, and failure matrix.
- Verdict: `NOT_RUN`

## AI-002 — Saving or testing settings pauses dispatch until explicit enable
- Lifecycle: `READY`
- Legacy IDs: `AI-002`
- Changed by: `bea71cf`, `8674603`
- Requirement: Every settings save pauses dispatch; testing an enabled profile requires pause confirmation and leaves analytics disabled; only a successfully tested compatible saved revision plus explicit privacy confirmation can be enabled.
- Surface: AI configuration controls.
- Route/API: `POST /admin/ai/save`; `/test`; `/enable`.
- Actor/grants: Administrator with configuration write/test authority.
- Preconditions: Disposable tenant with a previously enabled synthetic-provider profile and current version.

**Steps**
1. Change one setting and save; inspect settings status and department Generate controls before testing.
2. Run the synthetic test (including enabled-without-confirmation negative variant) and verify analytics stays paused afterward.
3. Enable with the current tested version and privacy confirmation; repeat with stale/untested version.

- Expected: Save and test pause dispatch; no generation is available until explicit valid enable; stale/untested/unconfirmed enable fails and rollback keeps deployment paused.
- Negative/non-disclosure: Test sends no patient data, already-sent provider calls are not claimed recalled, and failure does not reveal key/config internals.
- Cleanup: Disable analytics, remove synthetic key, and retire DB.
- Source: `app/Controllers/AiConfigurationController.php:50-59`; `app/Controllers/AiConfigurationController.php:121-149`; `app/Controllers/AiConfigurationController.php:152-198`
- Evidence: Status progression screenshots, synthetic provider request record, and negative responses.
- Verdict: `NOT_RUN`

## AI-003 — API key is write-only and encrypted at rest
- Lifecycle: `READY`
- Legacy IDs: `AI-003`
- Changed by: `bea71cf`
- Requirement: A saved provider key must be encrypted in managed storage, represented only by a boolean/status after save, never returned in HTML/JSON/logs, and blank input must retain it unless explicit removal/no-auth mode is chosen.
- Surface: AI settings page/status endpoint.
- Route/API: `POST /admin/ai/save`; `GET /admin/ai`; `GET /admin/ai/status`.
- Actor/grants: AI configuration administrator.
- Preconditions: Unique canary key in disposable tenant; log capture configured to search the canary safely.

**Steps**
1. Save the canary key, reload page/status, and search response bodies/client state/logs for the exact canary.
2. Save an unrelated setting with blank key and run a synthetic connection test to confirm retained credential use.
3. Explicitly remove/change to allowed no-auth configuration, then verify the old key is unusable and still never disclosed.

- Expected: Key field remains blank, status says a key exists without value, ciphertext is not plaintext, blank preserves it, explicit removal clears it.
- Negative/non-disclosure: Canary never enters screenshots, committed artifacts, URLs, errors, audit payloads, model previews, or logs.
- Cleanup: Explicitly remove key, purge transient logs containing only hashes, and retire DB.
- Source: `app/Controllers/AiConfigurationController.php:23-47`; `app/Services/Ai/SettingsStore.php:92-213`
- Evidence: Canary-hash search report, redacted UI/status, encrypted-row property check, and removal proof.
- Verdict: `NOT_RUN`

## AI-004 — Department AI grants are tenant/unit/role/permission scoped
- Lifecycle: `READY`
- Legacy IDs: `AI-004`
- Changed by: `bea71cf`
- Requirement: Read/generate/review/approve grants must be explicit tenant/unit/role permissions, independently audited, and must not grant underlying source-data authority.
- Surface: AI access matrix and department insight panels.
- Route/API: `POST /admin/ai/access`; `GET /{unit}/insights`.
- Actor/grants: AI configuration administrator; target staff roles in clinic/guidance/BMG.
- Preconditions: Disposable tenant with source grants varied independently from AI grants.

**Steps**
1. Grant each AI permission one at a time to one role/unit and compare controls/API outcomes after every change.
2. Revoke each permission and verify current session’s next request uses fresh authority.
3. Hold AI generate while removing the unit’s source-data permission, and try cross-tenant/unit/role inputs.

- Expected: Each matrix change is scoped and audited separately; actions reflect current grant; generation still requires analysis source permission; invalid scope fails atomically.
- Negative/non-disclosure: AI grants never expose patient charts, counseling notes, other units/tenants, provider key, or unrelated RBAC permissions.
- Cleanup: Revoke synthetic grants and retire DB.
- Source: `app/Controllers/AiConfigurationController.php:105-118`; `app/Services/Ai/InsightApplication.php:151-168`
- Evidence: Grant/action matrix and audit event set.
- Verdict: `NOT_RUN`

## AI-005 — Generate requires explicit AI and source-data authority
- Lifecycle: `READY`
- Legacy IDs: `AI-005`
- Changed by: `bea71cf`
- Requirement: Generate/prepare/recover/retry must revalidate current unit-specific AI generate and source permission; hidden controls are convenience only and direct POST must fail without queuing or contacting a provider.
- Surface: Department insights panel/endpoints.
- Route/API: `POST /{unit}/insights/prepare`; `/generate`; `/recover`; `/jobs/{id}/retry`.
- Actor/grants: Staff role variants lacking AI generate or source-data permission.
- Preconditions: Eligible aggregate fixture and synthetic provider counter; analytics otherwise ready.

**Steps**
1. Open panel under each missing-grant variant and record available controls.
2. Directly POST valid-looking requests despite hidden controls and inspect jobs/snapshots/provider counter.
3. Grant both authorities, repeat one valid prepare/generate, then revoke one before worker dispatch.

- Expected: Missing authority returns denial and creates/sends nothing; both grants permit enqueue; revocation before dispatch prevents provider work.
- Negative/non-disclosure: Denial does not reveal eligibility counts, stored snapshot, provider setup, or another unit’s analyses.
- Cleanup: Cancel safe queued job, revoke test grants, disable analytics, retire DB.
- Source: `app/Controllers/AiInsightsController.php:127-191`; `app/Services/Ai/InsightApplication.php:70-93`; `app/Services/Ai/InsightApplication.php:151-157`
- Evidence: Control/direct-route matrix, jobs/snapshots row counts, and provider counter.
- Verdict: `NOT_RUN`

## AI-006 — Local summary is aggregate, privacy-checked, and encrypted
- Lifecycle: `READY`
- Legacy IDs: `AI-006`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Prepare must build only an approved fixed analysis over completed Manila months, apply contributor/small-cell disclosure rules, freeze released values/masks, encrypt the snapshot, and contact no AI provider.
- Surface: Department “Preview data”.
- Route/API: `POST /{unit}/insights/prepare`.
- Actor/grants: Authorized generator with unit source permission.
- Preconditions: Deterministic eligible and below-threshold fixtures in disposable DB; provider request counter zero.

**Steps**
1. Prepare each approved unit analysis at a fixed Manila clock and reconcile disclosed/withheld metrics and month labels.
2. Inspect storage properties and rendered evidence; repeat identical prepare and then alter a previously released monthly value/category mask.
3. Run below-threshold and ineligible fixtures and inspect message/provider counter.

- Expected: Eligible aggregate evidence is encrypted/reused consistently; small cells are withheld, unavailable is not zero; changed frozen release requires review; no provider call occurs.
- Negative/non-disclosure: Names, IDs, charts/notes, free prompts/selectors, small counts, plaintext snapshot, and provider credentials are absent.
- Cleanup: Purge/retire AI fixture DB and confirm provider counter remains zero.
- Source: `app/Services/Ai/InsightApplication.php:64-81`; `app/Services/Ai/InsightStore.php:231-283`; `app/Services/Ai/InsightStore.php:718-798`
- Evidence: Aggregate reconciliation, withheld-cell capture, encrypted-storage property check, and zero-call log.
- Verdict: `NOT_RUN`

## AI-007 — HTTP generation only queues a deduplicated job
- Lifecycle: `READY`
- Legacy IDs: `AI-007`
- Changed by: `bea71cf`
- Requirement: Generate must prepare current evidence and enqueue one deduplicated, budget-reserved, provenance-stamped waiting request; the HTTP action itself must never call the provider.
- Surface: Department Generate action.
- Route/API: `POST /{unit}/insights/generate`.
- Actor/grants: Authorized generator.
- Preconditions: Eligible fixture, approved/tested profile, worker stopped, synthetic provider counter.

**Steps**
1. Submit generate once and inspect HTTP `202`, job status, snapshot ID/generation, profile revision, model, root ID, and pipeline digest.
2. Repeat identical requests sequentially and concurrently.
3. Inspect provider counter and daily admission behavior at the configured limit.

- Expected: One queued job is created and repeats report reuse/latest attempt; provenance fields and expiry are present; no provider call occurs without worker; exhausted budget rejects.
- Negative/non-disclosure: Response contains no encrypted evidence, prompt, key, dispatch lease/token, budget key, or patient data.
- Cleanup: Cancel queued job before starting any worker and retire DB.
- Source: `app/Controllers/AiInsightsController.php:42-50`; `app/Services/Ai/InsightStore.php:286-334`
- Evidence: HTTP/job metadata, concurrent row count, and zero provider-call log.
- Verdict: `NOT_RUN`

## AI-008 — Worker dispatch is single-attempt, leased, and provenance-bound
- Lifecycle: `READY`
- Legacy IDs: `AI-008`
- Changed by: `bea71cf`, `8674603`
- Requirement: A separately launched worker may claim an eligible queued job, recheck authorization/profile/snapshot generation/pipeline provenance, reserve one outbound daily slot immediately before one provider POST, validate output, and store an encrypted draft.
- Surface: `ai:dispatch` worker plus insights panel.
- Route/API: CLI worker and `GET /{unit}/insights/panel`.
- Actor/grants: Approved test operator; queued job’s actor still authorized.
- Preconditions: Disposable AI campaign, synthetic provider, one valid queued job, worker lease test hooks.

**Steps**
1. Start one worker for one job and capture claim/reservation/provider-call/completion chronology.
2. Start competing workers and verify only one outbound request and one draft.
3. Before dispatch, vary actor authorization, profile revision, snapshot generation/expiry, or pipeline digest; test malformed provider output.

- Expected: Only fully current job dispatches once; output is validated/encrypted as draft; stale/policy/malformed variants fail without draft or unsafe replay.
- Negative/non-disclosure: Provider receives only approved aggregate payload; logs/evidence omit key, plaintext snapshot/draft, prompt body, lease token, and personal data.
- Cleanup: Stop worker/provider, purge encrypted test payloads through supported command, disable analytics, retire DB.
- Source: `app/Services/Ai/InsightWorker.php:36-166`; `app/Services/Ai/InsightStore.php:580-638`; `app/Services/Ai/InsightStore.php:861-889`
- Evidence: Sanitized chronology, provider call count/hash, job/draft status, and stale-variant matrix.
- Verdict: `NOT_RUN`

## AI-009 — Review transitions are versioned and policy-bound
- Lifecycle: `READY`
- Legacy IDs: `AI-009`
- Changed by: `bea71cf`
- Requirement: Draft may transition `draft→accepted→approved` or `draft|accepted→rejected`, with current version and separate review/approve grants; nonreject decisions require current profile and pipeline provenance, and approval requires at least one program.
- Surface: AI draft review dialog.
- Route/API: `POST /{unit}/insights/{id}/review`.
- Actor/grants: Reviewer with `ai.insights.review`; approver with `ai.insights.approve`.
- Preconditions: Current encrypted drafts with and without programs; two reviewers sharing an initial version; historical-policy draft.

**Steps**
1. Accept then approve a current program draft using successive versions; inspect review rows and panel state.
2. Race two decisions at one version and attempt approve-before-accept, repeat accept, and approve a no-program draft.
3. Attempt accept/approve/reject on a profile- or pipeline-historical draft.

- Expected: Valid transitions increment version and append reviewer decision; one race wins; illegal/stale transitions conflict; historical draft is reject-only.
- Negative/non-disclosure: Approval does not publish, fund, schedule, notify, or prove correctness; unauthorized actors do not see plaintext draft.
- Cleanup: Retire the disposable drafts/database.
- Source: `app/Services/Ai/InsightApplication.php:95-113`; `app/Services/Ai/InsightStore.php:641-674`; `app/Views/ai/panel.php:199-259`
- Evidence: Version/decision timeline, conflict responses, review rows, and no-side-effect checks.
- Verdict: `NOT_RUN`

## AI-010 — Read surface withholds unavailable and unapproved content
- Lifecycle: `READY`
- Legacy IDs: `AI-010`
- Changed by: `bea71cf`
- Requirement: Insight panel must expose only the caller’s authorized unit/tenant, unexpired encrypted-store projections and mark review state/policy accurately; read-only users must not gain review/generate controls or historical plaintext.
- Surface: Department insights panel.
- Route/API: `GET /{unit}/insights`; `GET /{unit}/insights/panel`.
- Actor/grants: Role with `ai.insights.read` only.
- Preconditions: Draft, accepted, approved, rejected, expired/purged, other-unit, and other-tenant fixtures.

**Steps**
1. Open the panel as read-only and inventory visible statuses/content and absent controls.
2. Directly request review/generate routes and manipulate unit path/IDs.
3. Expire/purge one payload and refresh; compare other tenant/unit records.

- Expected: Only authorized unexpired unit records appear; state labels are truthful; controls/direct writes deny; purged/expired/foreign data is absent.
- Negative/non-disclosure: No ciphertext, plaintext expired payload, other scope, provider key, internal prompt, or unsupported causal/clinical claim is disclosed.
- Cleanup: Retire fixture DB.
- Source: `app/Services/Ai/InsightApplication.php:27-61`; `app/Services/Ai/InsightStore.php:428-469`; `app/Views/ai/panel.php:190-246`
- Evidence: Visibility/status matrix and denied direct-route responses.
- Verdict: `NOT_RUN`

## AI-011 — Disable stops new analytics while preserving truthful limits
- Lifecycle: `READY`
- Legacy IDs: `AI-011`
- Changed by: `bea71cf`
- Requirement: Disable must pause deployment and mark settings disabled with current version while preserving provider configuration/key; new analytics actions refuse, queued/running handling remains explicit, and already-sent requests are not claimed recalled.
- Surface: AI settings and department panels.
- Route/API: `POST /admin/ai/disable`; insight endpoints; worker status.
- Actor/grants: Configuration administrator and department staff.
- Preconditions: Enabled synthetic profile, one queued job and optionally one controlled in-flight request variant.

**Steps**
1. Disable using current version and inspect settings/panel controls/status.
2. Attempt prepare/generate/recover/retry and run worker against the queued job.
3. For the controlled in-flight variant, disable after outbound reservation and observe final truthful outcome handling.

- Expected: New work is unavailable and queued job does not dispatch under revoked deployment; key/config remain; in-flight provider work is not represented as recalled and ambiguity is retained safely.
- Negative/non-disclosure: Disable does not delete audit/review/budget records, expose secret configuration, fabricate cancellation, or auto-retry.
- Cleanup: Stop worker/provider, cancel only safely queued work, remove key, retire DB.
- Source: `app/Controllers/AiConfigurationController.php:141-149`; `app/Services/Ai/InsightApplication.php:160-168`; `app/Views/ai/panel.php:11-47`
- Evidence: Settings/panel progression, worker/provider count, and in-flight outcome trace.
- Verdict: `NOT_RUN`

## AI-REC-001 — Expired evidence recovers only if bitwise-policy identical
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Explicit confirmed recovery may rematerialize only current-period evidence whose schema, disclosure version, canonical fingerprint, analysis, tenant, and unit exactly match the expired row; it increments generation, encrypts a new retention payload, expires queued old jobs, and renews no approval.
- Surface: “Needs attention” recovery control.
- Route/API: `POST /{unit}/insights/recover`.
- Actor/grants: Authorized generator.
- Preconditions: Expired/purged current-period snapshot and unchanged source fixture; second variant changes a released metric/category.

**Steps**
1. Attempt recovery without confirmation and with an active/nonexpired snapshot.
2. Recover the unchanged expired snapshot and inspect ID, generation, expiry, storage audit, old jobs, and prior reviews/insights.
3. Change one source value/category mask and retry recovery.

- Expected: Only confirmed unchanged evidence rematerializes in the same snapshot identity with incremented generation; old jobs/approvals are not renewed; changed evidence returns review-required conflict.
- Negative/non-disclosure: Recovery contacts no provider, returns no plaintext persisted payload beyond authorized rendered aggregate, and never resurrects jobs/drafts/reviews.
- Cleanup: Purge recovered payload via supported tooling and retire DB.
- Source: `app/Controllers/AiInsightsController.php:53-63`; `app/Services/Ai/InsightStore.php:164-219`
- Evidence: Generation/expiry/audit comparison, old-job/review state, zero-call provider log, and changed-evidence response.
- Verdict: `NOT_RUN`

## AI-REC-002 — Safe retry requires proof no outbound dispatch occurred
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Manual retry is allowed only for a valid failed/cancelled/expired request family with current intact snapshot/profile/model/provenance and zero dispatch-attempt reservations across the chain; it creates one deduplicated successor under the normal budget.
- Surface: “Queue safe new attempt”.
- Route/API: `POST /{unit}/insights/jobs/{id}/retry`.
- Actor/grants: Authorized generator.
- Preconditions: Synthetic job families covering pre-dispatch failure, cancelled, expired, provider-attempted, outcome-unknown, lease-lost, stale generation, and changed pipeline.

**Steps**
1. Retry a pre-dispatch safe failure with confirmation and repeat the retry concurrently.
2. Inspect successor `retry_of_id`, root, snapshot generation, pipeline digest, budget, and provider counter before worker.
3. Attempt retry for every unsafe/stale/ambiguous family variant.

- Expected: One safe successor is queued/reused and consumes normal admission budget; every family with possible dispatch or provenance/generation mismatch is ineligible.
- Negative/non-disclosure: Provider error labels alone never prove retry safety; no automatic replay, key, lease, or payload is exposed.
- Cleanup: Cancel safe successor before dispatch and retire DB.
- Source: `app/Services/Ai/InsightStore.php:337-425`; `app/Controllers/AiInsightsController.php:66-75`
- Evidence: Family eligibility matrix, successor metadata, concurrent row count, and zero-call log.
- Verdict: `NOT_RUN`

## AI-REC-003 — Ambiguous dispatch becomes terminal `outcome_unknown`
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Timeout/lease loss or any failure after a possible provider dispatch must become `outcome_unknown`, clear active lease fields, warn that processing/charging may have occurred, and never become retry eligible.
- Surface: Worker and request history.
- Route/API: Worker failure path; `GET /{unit}/insights/panel`.
- Actor/grants: Approved AI test operator and authorized panel reader.
- Preconditions: Synthetic provider accepts request then withholds/interrupts response; controllable lease expiry.

**Steps**
1. Dispatch one aggregate request and induce response ambiguity after reservation/provider acceptance.
2. Let lease expire or invoke ambiguous failure, then refresh request history.
3. Attempt manual/automatic retry and run another dispatcher cycle.

- Expected: Job is `outcome_unknown` with cleared lease/active key and truthful warning; no draft is shown; retry control absent and no second provider call occurs.
- Negative/non-disclosure: System does not claim failure/success/refund, does not reveal provider body or aggregate payload, and does not replay.
- Cleanup: Stop provider/worker, disable analytics, retain only sanitized trace, retire DB.
- Source: `app/Services/Ai/InsightStore.php:628-638`; `app/Services/Ai/InsightStore.php:704-715`; `app/Views/ai/panel.php:27-47`; `app/Views/ai/panel.php:100-111`
- Evidence: Reservation/provider/job timeline, panel capture, and provider call count.
- Verdict: `NOT_RUN`

## AI-REC-004 — Pipeline provenance blocks historical acceptance and completion
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Jobs and drafts must carry the deterministic public-code pipeline digest; dispatch completion and nonreject review require a digest matching current policy files and current snapshot generation, while historical drafts remain reject-only.
- Surface: Worker completion and draft review.
- Route/API: Worker/store operations; `POST /{unit}/insights/{id}/review`.
- Actor/grants: Authorized worker/reviewer/approver.
- Preconditions: Current job/draft and cloned variants with old/malformed/missing digest or changed policy file in isolated checkout.

**Steps**
1. Record digest for current job/draft and complete/review the valid control.
2. Try worker completion after changing snapshot generation or pipeline code/digest.
3. Open historical draft and attempt accept, approve, and reject.

- Expected: Valid current provenance succeeds; stale completion fails; historical/malformed provenance cannot be accepted/approved or dispatched but an authorized rejection remains available.
- Negative/non-disclosure: Digest contains only public code identity—not settings, endpoint, credentials, tenant data, prompt payload, or secrets.
- Cleanup: Restore isolated code checkout, delete synthetic variants, and retire DB.
- Source: `app/Services/Ai/PipelineProvenance.php:7-31`; `app/Services/Ai/InsightApplication.php:95-113`; `app/Services/Ai/InsightStore.php:861-889`
- Evidence: Digest/status matrix, stale completion response, and review-control/response captures.
- Verdict: `NOT_RUN`
