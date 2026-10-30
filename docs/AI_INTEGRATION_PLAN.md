# Privacy-preserving AI insights and program planning

Status (2026-10-29): **Six fixed analyses implemented; local AI schema through batch 34 installed; runtime disabled. Final application test results pending. The full roadmap is not complete.**
Prepared: 2026-10-28; implementation checkpoint updated: 2026-10-29
Product: SYNAPSE / Foundation University
Feature: **Department Insights & Program Planning**

## Implementation checkpoint

The code now supports the legacy Clinic activity analysis plus recorded Clinic complaints, Guidance activity and eligible recorded categories, and BMG batch/rotation-report activity. It includes protected monthly evidence, custom OpenAI-compatible endpoint configuration, encrypted jobs/drafts, bounded transport, explicit department/provider approval, tenant/department/role AI grants, independent source-data authority, staff review/program approval, and finite CLI dispatch/purge commands. Implemented does not mean enabled, department-approved, live-provider-tested, or production-accepted.

Provider setup is **Administration → AI Settings** (`/admin/ai`): choose approved departments, then explicitly **Save settings → Test connection → Enable aggregate AI**. The separate **Department AI access** panel grants/revokes Read, Generate, Review, and Approve for a role in the current tenant and department. Neither provider approval nor these grants supplies source-data access. UI setup manages encryption keys, tested revisions, and the dispatch manifest without manual environment edits. **The UI does not start or schedule the worker.**

The supplied local installation record is `CreateAiInsights` in `humus_db` batch **33**, followed by `AddAiAnalysisScope` in batch **34**, preserving all **65 unrelated table definitions** and earlier migration history. The six original AI tables remained empty; the new access table has no grants. No AI enablement, worker scheduling, or live provider configuration/call is recorded. A disposable MariaDB migration rehearsal was completed. The pre-scope backup's SHA-256 was verified, but **no full backup restore test was performed**. These supplied outcomes were not rerun during documentation editing; see [AI operations and deployment](AI_OPERATIONS.md) for the exact backup path and installation procedure.

This plan separates current implementation from remaining governance, validation, and future capabilities. The operations runbook is the operational reference; no final application pass counts are asserted here.

## 1. Decision summary

Provide staff-only assistance explaining server-verified departmental statistics and selecting reviewable operational program templates. A model receives only a separately constructed, disclosure-reviewed aggregate snapshot and a small set of fixed eligible template descriptors. Source records remain inside their existing authorized boundaries.

**Custom endpoints are implemented, not deferred:** one deployment profile supports configurable OpenAI-compatible, non-streaming chat completions with explicit base URL, model, authentication, and capabilities. Self-hosted or contracted external endpoints must satisfy the same approval and transport gates. A different native protocol requires a separately implemented/tested adapter; changing a URL does not establish compatibility.

AI remains optional and independently pauseable. Clinic, counseling, scheduling, inventory, and hardware operations must not depend on inference availability or billing. No live provider compatibility or production-readiness claim follows from installing the feature.

## 2. Outcomes and prohibited uses

### Current intended outcomes

- Show comparable completed-month operational counts and eligible recorded-category shares with source limitations.
- Calculate official numbers and descriptive directions locally before inference.
- Separate observations, possible interpretations, alternative explanations, and limitations.
- Select evidence-linked, server-owned operational options for qualified staff review.
- Preserve snapshot/profile/library lineage, review decisions, and explicit program approval.
- Evaluate usefulness against ordinary protected analytics and templates, rather than assuming the model adds value.

### Prohibited or unimplemented

- No names, official numbers, person/chart/case IDs, contacts, record URLs, or patient-level rows in model inputs.
- No clinical narratives, raw complaint text, counseling notes, referral reasons, messages, attachments, or survey free text in prompts; no counseling-note decryption, patient embeddings, or fine-tuning.
- No personal/crisis category evidence, individual risk/diagnosis, treatment/medication recommendations, patient prediction, outbreak detection, discipline/eligibility decisions, or cross-department person profiling.
- No free chat, arbitrary prompts, SQL, user-selected database queries, model tools, browser access, or arbitrary context fields.
- No structured-survey analysis, staff editing of model prose, AI report exports, AI notifications/realtime delivery, Teams integration, or program publication workflow.
- No automatic announcements, outreach, scheduling, tasks, spending, treatment, or hardware actions. Program approval only records approval of the reviewed draft.
- No claims that encounter counts measure campus prevalence, recorded themes measure individual need, or BMG reports prove physical operation, mechanical reliability, compost quality, or sanitation.

## 3. Implemented analyses and source contracts

Every analysis uses the **last complete Asia/Manila calendar month and its immediate predecessor**, without arbitrary dates, cohorts, patient selectors, or narrow demographic filters.

| Analysis | Collector and metric contract | Important limits |
| --- | --- | --- |
| `clinic.activity` | Legacy `ClinicSnapshotBuilder` / schema-v1 payload; completed `clinic_encounters` by `started_at`, metric `completed_encounters` | Encounters, not unique people; existing encrypted payloads are not rewritten |
| `clinic.complaints` | Schema-v2 aggregate collector; fixed exact complaint categories and shares of all completed encounters | SQL matches `LOWER(TRIM(chief_complaint))` against fixed constants; no narrative interpretation or diagnosis |
| `guidance.activity` | Completed `counselling_sessions` by `started_at`, metric `completed_sessions` | Sessions, not unique people or cases |
| `guidance.themes` | Eligible exact recorded case categories: `academic`, `career`, `behavioral`, `family`; counts/shares use completed sessions | Not topics inferred from notes; personal/crisis, missing, and unmapped buckets stay internal |
| `bmg.batches` | `batches_started` by `started_at`; `batches_released` and `batches_voided` by `completed_at` | Starts and terminal events are separate cohorts, not completion rates, processing durations, or output quality |
| `bmg.rotations` | Accepted reports by `occurred_at`; `rotation_reports`, `rotation_failed`, `rotation_interrupted`; failure/interruption shares use all accepted reports | Reports, not verified movement, uptime, schedule compliance, unreported events, or reliability |

Clinic's exact fixed vocabulary is headache, fever, dizziness, abdominal pain, cough, colds, toothache, injury, nausea, vomiting, menstrual cramps, and checkup. It is not a dynamic top-five list, fuzzy/substring match, accent-folded vocabulary, or a clinical classifier. Guidance matches exact stored category values without Clinic's case/space normalization. Existing report/export behavior is not changed by the AI collector.

Health queries include retained soft-deleted completed records and linked people, sessions, and cases as applicable. Tenant-safe joins detect unlinked/orphaned/cross-tenant contributors. Person IDs are used only inside SQL to count distinct contributors and complements; SQL returns fixed aggregate aliases, not person rows or raw text. Counseling notes are not read. Source timestamps use the configured application storage timezone except rotation `occurred_at`, which explicitly stores Manila wall time. Collection coverage and historical timestamp conventions still require deployment review.

BMG uses operational event counts, not the clinical-contributor threshold. Invalid source statuses block release; no recorded events across both months is insufficient data. Zero means no recorded events in that cohort, not proof nothing happened.

Survey analysis, appointment/no-show/waiting-time metrics, medicine forecasting, seasonal comparisons, and additional domains require their own validated definitions and approvals. Existing source reports or dashboards are not automatically safe AI inputs; never send whole analytics objects, view payloads, HTML, screenshots, or survey `text_samples`.

## 4. Architecture and trust boundaries

```text
Fresh tenant/unit AI authority + independent source permission
  -> fixed read-only SQL aggregates
  -> comparable-month and contributor/disclosure checks
  -> atomic immutable snapshot + monthly release fingerprints/masks
  -> deterministic arithmetic and descriptive signals
  -> explicit deduplicated job enqueue (no provider call)
  -> separately invoked finite worker
  -> fresh requester/profile/manifest/budget/lease checks
  -> bounded endpoint validation, DNS pinning, one provider attempt
  -> strict output/evidence/template validation
  -> encrypted restricted draft
  -> qualified staff accept/reject
  -> separately authorized program draft approval (no publication)
```

| Implemented component | Responsibility |
| --- | --- |
| `AiAccess` / `AiGrantStore` | Fresh account/tenant/unit/role/permission checks; scoped AI grants with transactional audit |
| `AnalysisCatalog` / snapshot builders | Fixed definitions, labels, source authority, periods, and aggregate-only queries |
| `DisclosurePolicy` / `BroadDisclosurePolicy` | Legacy/v2 evidence validation, contributor checks, category/complement/hidden-pool suppression, deterministic arithmetic |
| `InsightStore` / `PayloadCipher` | Immutable encrypted snapshots/drafts, durable monthly fingerprints and publication masks, scoped jobs, leases, budget reservations, review transitions, bounded payload erasure |
| `SignalDetector` / `ProgramLibrary` | Descriptive signals and fixed evidence-eligible operational templates |
| `SettingsStore` / `Profile` / `DeploymentControl` | Encrypted UI profile or deployment defaults, exact revision approvals, independent cross-process pause/resume manifest |
| `EndpointPolicy` / `BoundedResolver` / `CurlTransport` / `ChatClient` | Destination/TLS validation, bounded DNS and response handling, pinned OpenAI-compatible transport |
| `InsightApplication` / `InsightGenerator` / `InsightWorker` | HTTP use cases without inference, strict draft validation, separate finite dispatch with fresh authority |

The worker consumes saved protected snapshots and rechecks requester authority; it does not recollect patient records for inference. This is an application-level separation, not proof of a separately restricted database credential or OS sandbox. Deploy a restricted process identity and network policy as defense in depth. Prompts are not the access-control, disclosure-control, or network-security boundary.

## 5. Disclosure and immutable release policy

1. Both health months must meet the configured distinct-contributor threshold (`minimumContributors`, default and minimum deployment value **10**) and contain no unlinked contributors. Repeated events do not count as distinct people.
2. Each candidate category must independently meet category and distinct-person-complement thresholds in each month. Complements count people with events in other buckets; they are **not** total contributors minus category contributors, because people can contribute to multiple buckets.
3. Monthly category selection applies complementary suppression before comparison. A remaining positive hidden pool needs at least two positive hidden buckets and at least one bucket meeting the contributor threshold. Fixed catalog-order categories are withheld as needed; the month selections are intersected and both hidden pools checked again. If no category survives, the categorical comparison is blocked.
4. Personal/crisis, missing, and unmapped Guidance buckets remain internal. Personal/crisis labels and counts are never emitted as evidence. Hidden categories are not emitted as zero or as a residual “other” bucket. Their events remain in the full completed-session/encounter denominator.
5. Released category counts, full denominators, shares, and percentage-point changes are server-calculated. A zero recorded BMG denominator makes a share undefined, not zero. With an undefined share change, a `stable` signal means no directional comparison is available, not evidence of stable operation.
6. `InsightStore` atomically freezes **all released monthly metric values, category denominators, and ordered categorical publication masks** in durable keyed release history. Shared totals bind activity and categorical analyses. Adjacent comparisons cannot accumulate different category subsets for the same month.
7. A changed count, denominator, or published subset rejects the overlapping release with `snapshot_changed_requires_review`. Old category history without the publication-mask guard is also conservatively rejected, not retroactively approved. Legacy monthly fingerprint encoding and encrypted history are preserved.
8. Fingerprints/masks survive payload expiry. No automatic correction, fingerprint deletion, self-service override, or approval bypass is provided. Escalate corrections, policy/catalog/threshold changes, and old unguarded history for DPO/department publication-history review.
9. Unknown payload fields, unapproved labels, malformed arithmetic, and invalid provenance fail closed. The model must not infer suppressed values. “Insufficient privacy-safe data” is an acceptable result; weakening suppression to obtain an answer is not.

These controls are conservative internal-release protections, **not a privacy certification, legal safe harbor, or guarantee of anonymity**. Contributor/suppression inputs and source coverage still require governance review. Differential privacy and broader repeated/public-release accounting are possible future designs, not implemented features.

Legacy `clinic.activity` evidence stays schema v1. The other five analyses use schema v2 with explicit `analysis_code`, fixed metric/evidence IDs, month labels, denominators/shares where applicable, deterministic signals, and fixed limitations. Internal tenant/actor/source IDs, contributor-check details, credentials, and audit metadata are not model context. Evidence IDs identify approved aggregates, not individual records.

## 6. Signals, programs, and review

Current signals are descriptive increase/decrease/stable cues from counts or category-share changes. Two months do not establish significance, causation, seasonality, disease prevalence, or unmet need. Comparability depends on recording practices, operating days, import/cutover, coverage, and missing categories; staff must review these limitations.

The current library/prompt capability is **`department-programs-v2:prompt-v2`**. Fixed options include Clinic capacity/access and general awareness review; Guidance capacity/access, study/career support, and broad voluntary wellbeing review; and BMG workload, batch-record, and qualified maintenance-inspection review. Eligibility binds department, analysis, metric, signal, and evidence on the same record. References are governance/background material, not proof of local causes, endorsement, or program efficacy. Exact templates require department-owner review before activation.

The server supplies at most three eligible template descriptors; validated output may select at most two IDs. Program objectives, activities, resource assumptions, evaluation measures, limitations, reviewers, and references come from server-owned templates, **not model-authored program prose**. An empty selection/abstention is valid.

The strict response contains schema version, supplied evidence IDs, summary, interpretation, alternative explanations, eligible program IDs, and an abstention flag. Unknown/duplicate fields, invented IDs, invalid structures, numbers/URLs/HTML/control characters in prose, unsupported envelopes/tool calls, and truncated/oversized output are rejected. Official numbers and references are rendered separately from trusted evidence/templates. Structural validation does not prove narrative truth or rule out every unsupported claim; qualified review remains mandatory.

Review records accept/reject decisions with optimistic version checks. `ai.insights.approve` separately approves an already accepted draft with eligible programs. There is no draft text editor, automatic publication, patient action, spending, scheduling, or hardware control.

## 7. Provider configuration and activation

### UI-managed setup

A `superadmin` with `rbac.manage`, or the corresponding explicit configuration permission, uses `/admin/ai`. Ordinary department users cannot edit providers. One encrypted profile belongs to its saved tenant; multiple selectable profiles are not implemented.

1. Set API base URL, key, model, optional reasoning effort, conservative input budget, and output limit. Select at least one approved department: `clinic`, `guidance`, or `bmg`.
2. **Save settings** pauses analytics and invalidates the prior test. Blank key preserves it; removing it is explicit. Saved keys are never returned to the browser.
3. **Test connection** sends a fixed harmless fixture to the saved configuration, not departmental data. It can incur costs and does not approve aggregate dispatch. Unsaved edits are not the tested profile.
4. Separately grant intended tenant/department/role AI capabilities in **Department AI access**; existing source permissions still apply.
5. After privacy/provider/template authorization, explicitly confirm and **Enable aggregate AI**. The UI checks storage and manages revision acknowledgments and the dispatch manifest. Changing departments/settings requires saving, retesting, and explicit re-enablement.
6. Arrange a separate supervised CLI worker/scheduler. Enabling the provider or queuing a UI draft does not start a process or execute a job; there is no UI worker activation.

`SettingsStore` encrypts `writable/ai-settings/provider.enc` using the independent `settings.key`; the retained AI payload key stays stable inside that encrypted profile. Securely back up the whole private directory with the database. Missing/corrupt saved material fails closed, not by silently replacing the key. No secrets belong in browser assets, logs, commits, jobs, URLs, or documentation.

### Optional deployment-managed configuration

Defaults are in `app/Config/Ai.php`; commented, non-secret examples are in tracked `env`. Saved UI provider settings take precedence. The alternative private deployment contract includes `approvedTenants`, optional `approvedUnits` (default `clinic`, reviewed subset of `clinic,guidance,bmg`), `capabilityRevision = 'department-programs-v2:prompt-v2'`, encryption, governance/library approval, and exact effective/compatibility revision acknowledgments. Unit selection alone grants no access or activation. See the runbook for the full sequence; do not mix manual acknowledgment copying with UI-managed setup.

### Implemented endpoint contract and limits

- Preserve the API base's complete path prefix and append `/chat/completions` once: `https://ai-gateway.example.org/team-a/v1` becomes `https://ai-gateway.example.org/team-a/v1/chat/completions`. Reject an already complete operation URL, userinfo, queries/fragments, traversal, and ambiguous encoded separators.
- Model IDs are explicit, opaque nonempty UTF-8, at most 191 bytes without whitespace/control characters. No provider/model/protocol fallback occurs.
- Authentication is bearer, deployment-allowlisted API-key header, or explicitly approved none. Never forward browser/session/MIS credentials. Output token field is `max_tokens` or `max_completion_tokens`; optional reasoning must be supported by the actual endpoint/model.
- Output modes are `none` or `json_object`, both locally validated. Native `json_schema` and unrelated native APIs are unsupported. Unsupported options fail without silent downgrade.
- Input budget is conservative UTF-8 bytes plus framing, not an exact tokenizer or provider input-limit field. Oversized requests are rejected rather than truncated.
- Validate every resolved IPv4/IPv6 address, deny metadata/link-local/special-use destinations, and pin an approved address while retaining TLS hostname/SNI verification. Redirects, inherited proxies, browser cookies, and shared connection state are disabled.
- Private exceptions are exact approved origins/ports. External production uses HTTPS; managed private CAs are supported, not disabled certificate validation. Plain HTTP requires explicit isolated-network risk acceptance; deployment `allowLocalHttp` alone permits synthetic testing, while real dispatch additionally needs `allowPrivateHttpDispatch` and normal approvals.
- DNS runs in a bounded secret-scrubbed child PHP CLI process, with a trusted CLI binary and required process functions. Requests/responses, connect/request timeouts, output, and daily budgets are bounded. Defaults: 65,536 request bytes, 262,144 response bytes, 8,192 input allowance, 2,000 output tokens, and 50 daily enqueue/dispatch slots tenant-wide across departments.
- **One concurrent request and one attempt** are supported. The finite worker defaults to at most five jobs and 120 seconds shared across selected units; failures and ambiguous outcomes are not automatically retried.

Endpoint/model compatibility, TLS/CA/DNS, training/retention/abuse monitoring, subprocessors, processing location/transfers, billing, incidents, and privacy approvals are deployment gates. A self-hosted URL is not proof of local-only processing; “no training” is not “no retention.” No live provider was tested as part of the recorded installation or documentation update.

## 8. Authorization, persistence, and jobs

### Independent access gates

| Authority | Current meaning |
| --- | --- |
| `ai.configuration.read`, `.write`, `.test` | Platform provider administration; `superadmin` plus the corresponding grant or existing `rbac.manage`; managed testing also requires write |
| `ai.insights.read` | Authorized department workspace/results |
| `ai.insights.generate` | Prepare local evidence, queue a draft, or cancel queued work; also requires Read and source authority |
| `ai.insights.review` | Accept/reject; also requires Read |
| `ai.insights.approve` | Approve an accepted eligible program draft, not publish; also requires Read |
| Source-data permission | Clinic `clinic.encounters.read`; Guidance **`guidance.cases.read_any`**, not own-cases-only; BMG `facilities.read` |

Scoped grants are stored by **tenant + department + role + permission**, not per user or globally. Eligible roles are Clinic `clinic_admin`, `clinic_staff`, `superadmin`; Guidance `guidance_admin`, `guidance_supervisor`, `counsellor`, `superadmin`; BMG `bmg_admin`, `facilities_op`, `superadmin`. Grant/revoke and its durable audit event commit in one transaction; missing/incompatible access/audit storage blocks changes. Existing explicit RBAC grants remain effective independently: revoking a panel grant does not negate them.

There are no automatic insight grants, including to superadmin, report viewers, or audit readers. Provider configuration is not insight access. `AiPermissionsSeeder` catalogs permissions only; do not run the general `PermissionsAndRolesSeeder` to enable AI because it replaces the complete role-permission map. Normal workstation/host and fresh identity checks still apply. The worker rechecks the requesting account, tenant/unit AI grants, and source rights before transport and result finalization, without a browser session.

### Additive storage and retention

`CreateAiInsights` creates `ai_releases`, `ai_snapshots`, `ai_jobs`, `ai_dispatch_attempts`, `ai_insights`, and `ai_reviews`. `AddAiAnalysisScope` adds `ai_snapshots.analysis_code` with legacy default `clinic.activity`, replaces snapshot uniqueness with tenant/unit/analysis/period, and creates the initially ungranted `ai_department_access` table. Existing IDs, ciphertext, monthly releases, and earlier migration history are preserved. Incomplete key transitions fail storage readiness checks.

Snapshots/drafts use authenticated AES-256-GCM with tenant/unit/entity binding; monthly fingerprints use a separately derived HMAC key. There is one storage key, **not an online key-rotation facility**. Replacing it can make retained evidence unreadable and fingerprints incompatible; rotation requires a deliberately verified history-preserving migration.

Snapshots default to 30-day expiry; draft payloads expire after 90 days, including approved program drafts. Bounded purge erases expired payloads, not monthly fingerprints/masks, dispatch reservations, job metadata, or review decisions. Institutional holds, broader metadata/audit/backup retention, and longer approved-program retention are not automatically implemented by that command. See the runbook for cleanup details.

### Execution lifecycle and actual CLI

Generation authorizes a fixed analysis, prepares/reuses a protected immutable snapshot, and queues deduplicated work; no endpoint call occurs in the generation HTTP request. States include queued/running/succeeded/failed/ambiguous/cancelled/expired as recorded by the store. A finite leased worker commits before network calls and rechecks snapshot freshness, authority, profile/manifest, budget, and lease immediately before the one POST and finalization. Daily admission and actual UTC dispatch-day limits are separate and tenant-wide.

The inspected command contract is:

```text
php spark ai:status
php spark ai:probe --tenant <positive-id>
php spark ai:dispatch --tenant <positive-id> [--unit <clinic|guidance|bmg>]
php spark ai:resume --tenant <positive-id> [--unit <clinic|guidance|bmg>]
php spark ai:purge --tenant <positive-id> [--unit <clinic|guidance|bmg>]
php spark ai:pause
```

Angle-bracketed arguments denote values, not literal shell syntax. No tenant defaults to 1. Dispatch without `--unit` shares one job/time budget across all approved units; **`--unit all` is rejected**. Resume defaults to validating the first approved unit and authorizes the reviewed shared manifest, not a scheduler. Purge defaults to Clinic only and can explicitly clean Guidance/BMG even after provider approval is revoked. Pause accepts no options and pauses the shared manifest. Probe can contact/bill the provider using harmless synthetic content; dispatch can send approved departmental statistics. Syntax here was inspected in source, not executed against private configuration.

Deploy separately supervised, non-overlapping finite dispatch and cleanup tasks under a restricted account; there is no automatic scheduler or UI activation. An existing notification task is not an AI-worker service guarantee. External timeouts/supervision are still necessary for OS/database stalls.

`outcome_unknown` means processing/billing may have occurred. Do not reset/requeue/replay ambiguous jobs automatically. Cancellation cannot recall transmitted data. Pause, stop/drain, review changes, restart, retest/acknowledge the effective revision, and resume after code/secret/model/policy/environment changes. The local manifest is not a distributed cluster control service. Preserve release/budget/audit evidence during incidents; never log raw prompts, responses, provider errors, credentials, or source records.

## 9. Current interface and API boundaries

- Administration: GET `/admin/ai` and `/admin/ai/status`; CSRF-protected POST `/admin/ai/save`, `/test`, `/enable`, `/disable`, and `/access` under that prefix.
- Workspaces: `/clinic/insights`, `/guidance/insights`, `/facilities/insights` (internal unit `bmg`). Each supports GET workspace and `/panel`; POST `/prepare`, `/generate`, `/{id}/review`, and `/jobs/{id}/cancel` under its workspace prefix. Review decisions are fixed `accept`, `reject`, or `approve` with version checks.
- Workspace path fixes the unit; only a catalog analysis for that unit is accepted. No arbitrary dates, prompts, cohorts, profile/model/endpoint overrides, or patient links are allowed.
- **Prepare local summary** runs local aggregate/disclosure/storage checks only. It still requires approved analytics scope/revision, source authority, and ready encrypted storage; it is not an unapproved raw-data preview.
- **Request AI draft** queues work, returning/reusing a job; panel refresh/polling never generates. Scoped browser actions do not automatically replay uncertain POSTs.
- Responses are private/no-store. Provider/configuration errors are not browser-session expiry; only shared authentication handling redirects to sign-in. Suppression, revision conflict, expiry, quota, and ambiguous-outcome states remain explicit.
- Evidence/numbers and fixed program content render separately from escaped, fallible model prose. Approval is visibly distinct from publication, which is absent.

## 10. Validation and acceptance gates

**Final broader-feature application test outcomes are pending the main validation pass.** Test files and the completed disposable MariaDB migration rehearsal do not establish that all tests passed, that the backup can be fully restored, or that a live provider works. The operations runbook lists isolated validation commands; no final counts are recorded here.

Acceptance must cover synthetic/disposable tests for:

- Fixed vocabulary, exact category matching, tenant-safe aggregate-only SQL, month/timezone boundaries, soft deletion, repeated events versus people, missing/unlinked contributors, valid statuses, zero denominators, and source rights.
- Category/complement/hidden-pool suppression, non-emission of personal/crisis metrics, immutable monthly values/denominators/masks across adjacent releases, old unguarded history, and conservative rejection of changes.
- Legacy schema-v1 compatibility and migration preservation; independent analysis uniqueness; no seeded grants/enabling; transactional grant/audit failure and revocation behavior.
- Encrypted UI settings, approved departments, explicit save/test/enable, exact revisions, no browser-secret return, CSRF, fresh authority, and source/AI permission separation.
- Endpoint/adapter SSRF, IPv4/IPv6/mixed DNS, pinning, TLS/CA, private exceptions, redirects/proxies, bounded subprocesses/bodies/timeouts, authentication modes, token/reasoning options, malformed responses, and strict output/evidence/template binding.
- Deduplication, concurrent leases, shared department budgets, one-shot reservations, crashes/ambiguous outcomes, no retries, cancellation, expiry/purge, pause/resume, CLI option rejection, and finite scheduling behavior.
- Review/version transitions, escaping, no unwanted source writes/network side effects, shared auth/session behavior, scoped browser updates, duplicate-click/uncertain-response behavior, accessibility, mobile layout, and dark mode.

Use synthetic fixtures, mocked HTTP/DNS, and disposable SQLite/MariaDB schemas, never test patients or migrations in the operational database. A loopback transport fixture is not a deployment provider. Live synthetic probes require explicit network/billing authorization; real protected-statistics dispatch additionally requires privacy and department authorization.

Before activation: verify backup/restore evidence, actual data quality/timezones, source definitions, privacy/provider/network/billing terms, exact template/capability approval, deliberate grant cutover, worker supervision while administrators are signed out, non-overlap/restart/failure monitoring, retention, and incident response. Compare factual errors, unsupported claims, inappropriate suggestions, staff usefulness/review time, latency, and cost against a no-LLM baseline using criteria approved before evaluation. Passing tests is not privacy/compliance certification.

## 11. Remaining roadmap, not a completion claim

| Workstream | Implemented checkpoint | Remaining gate or future work |
| --- | --- | --- |
| Governance/specification | Fixed catalog, limitations, disclosure policy, approval controls | Institutional DPO/department/security/provider approvals; real source coverage; pilot evaluation criteria |
| Protected analytics | Six fixed analyses; suppression; immutable release history/masks; local evidence UI | Final adversarial/regression validation and department acceptance; reviewed corrections/history policy |
| Provider layer | One encrypted UI/deployment profile, custom endpoint, bounded transport, synthetic test/status | Actual deployment endpoint/model/TLS/CA validation and approved processing terms; extra adapters/profiles are future |
| Jobs/review | Encrypted queue/drafts, finite worker/purge, budgets, pause/resume, accept/reject/program approval | Final tests, production supervision/schedules, recovery drills, retention acceptance; no online key rotation |
| Clinic activity/complaints | Legacy activity plus fixed protected complaint categories and eligible templates | Clinician/DPO approval, categorization quality, safe usable data, shadow evaluation; no disease detection |
| Guidance/BMG expansion | Activity/eligible recorded Guidance categories and recorded BMG event analyses | Independent domain approvals, coverage/meaning review, final validation; no notes/risk/physical safety inference |
| Further integrations | None implied by the six-analysis implementation | Structured surveys, notifications/realtime, publication/Teams, exports, text editing, and additional domains need separate design/authorization |

No patient chatbot, diagnosis, patient prediction, unrestricted chat, or autonomous clinical/hardware actions are on this delivery path. Do not bypass privacy work to demonstrate a live call, or describe implemented controls as completed institutional governance.

## 12. Deployment and rollback decisions

Keep AI disabled while reviewing the target tenant/database, verified backup and restore evidence, ordered additive migrations, encryption/CA installation, endpoint revision, explicit grants/source rights, worker identity/schedule, cleanup, and egress policy. Inspect pending migrations; never apply unrelated work automatically. The recorded local schema change did not enable runtime or validate a production deployment.

Disable/pause and stop the worker to roll back operation. Preserve review/disclosure/audit history; do not drop tables or delete fingerprints to silence a conflict. `AddAiAnalysisScope::down()` deliberately refuses automatic rollback because multiple analyses can share a period; the original migration's `down()` would destroy AI history. Use a reviewed compatible code/schema recovery procedure, not migration rollback as a feature toggle. Already transmitted payloads cannot be recalled by a flag.

Open activation decisions remain: approved departmental decisions/use cases; qualified reviewers; exact endpoint/protocol/network/secret mechanism and costs; contributor thresholds and publication-history handling; institutional retention/backup/audit/hold policies; production supervision; and measurable benefit over ordinary analytics/templates. Implemented UI acknowledgments do not substitute for those decisions.

## 13. Guidance and evidence

The following sources inform the design. Check current applicable requirements before activation; this document is not legal advice or a compliance certificate.

- Philippine National Privacy Commission, [Data Privacy Act of 2012](https://privacy.gov.ph/data-privacy-act/): sensitive health/education information; legitimate purpose, proportionality, security, processing/transfer accountability, especially Sections 3, 11, 13, 14, 20, and 21. Assess aggregation processing too; treatment consent is not assumed to authorize AI use.
- WHO, [Ethics and governance of artificial intelligence for health](https://www.who.int/publications/i/item/9789240029200): oversight, accountability, ethics, and human rights.
- WHO, [AI ethics and governance guidance for large multi-modal models](https://www.who.int/news/item/18-01-2024-who-releases-ai-ethics-and-governance-guidance-for-large-multi-modal-models): inaccurate outputs, bias, automation bias, cybersecurity, and well-defined use cases.
- NIST SP 800-188, [De-Identifying Government Datasets: Techniques and Governance](https://csrc.nist.gov/pubs/sp/800/188/final): disclosure-risk assessment and limitations of masking/de-identification; technical guidance, not Philippine law.

**Final principle:** the model helps staff explain approved evidence and select reviewable options. Convincing text never gives it an entitlement to patient records, arbitrary network access, or authority to act.
