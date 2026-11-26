# Guidance assessments and service evaluations

## GD-MI-001 — Counsellor assigns one active Multiple Intelligence assessment
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.ASSIGN`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-09`
- **Surface:** MI assessment console
- **Route/API:** POST /guidance/assessments/mi/assign
- **Actor/grants:** Qualified Guidance actor with guidance.assessments.assign
- **Preconditions:** MI reproduction/signoff policy unresolved; synthetic person; active reviewed 80-item catalog; no active assignment.
**Steps**
1. Assign the MI assessment and inspect the counsellor list and target portal list.
2. Attempt a second active assignment for the same person and assignment by an actor without the grant.
- **Expected:** After policy approval, one assigned item appears for the target and duplicate active assignment/grantless action fails.
- **Negative/non-disclosure:** Assignment does not disclose answers/results to the assigner prematurely or create a diagnostic label.
- **Cleanup:** Cancel/delete the synthetic assignment/draft.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:265-355; app/Config/Routes.php:407-411
- **Evidence:** Counsellor/portal assignment state, duplicate and grant denial, and catalog revision evidence.
- **Verdict:** `NOT_RUN`

## GD-MI-002 — Assigned person saves an encrypted partial draft
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.DRAFT`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`
- **Surface:** Portal MI assessment
- **Route/API:** POST /portal/assessments/mi/{id}/draft
- **Actor/grants:** Assigned person with guidance.assessments.take
- **Preconditions:** MI policy unresolved; synthetic assignment to person A; person B control; partial valid 0–4 responses; encryption configured.
**Steps**
1. As A, save a partial response set, leave, and reopen the assessment.
2. As B, try to save to A's assignment; inspect stored draft for plaintext.
- **Expected:** After policy approval, A's partial answers resume from encrypted draft and assignment becomes in_progress; B is denied.
- **Negative/non-disclosure:** Plain responses do not appear in database/log/audit output and no result is computed before finalization.
- **Cleanup:** Delete/reset draft and assignment.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:359-438,644-690; app/Config/Routes.php:428-433
- **Evidence:** Draft resume, ownership denial, and cipher-vs-response check.
- **Verdict:** `NOT_RUN`

## GD-MI-003 — Finalization requires all 80 answers and account attestation
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.FINALIZE`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`
- **Surface:** Portal MI assessment
- **Route/API:** POST /portal/assessments/mi/{id}/finalize
- **Actor/grants:** Assigned person with guidance.assessments.take
- **Preconditions:** MI policy unresolved; in-progress synthetic assignment; complete reviewed 80-response set; required attestation statement.
**Steps**
1. Attempt finalization with one missing/invalid item or absent attestation.
2. Submit all 80 valid responses with the required account attestation and reload assignment.
- **Expected:** After policy approval, incomplete attempts fail unchanged; complete submission becomes finalized/completed with bound attestation and no draft.
- **Negative/non-disclosure:** No client-selected person, partial scoring, or guardian signer capacity is accepted.
- **Cleanup:** Delete/reset submission/assignment/attestation.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:440-560; app/Config/Routes.php:428-433
- **Evidence:** Validation failures, finalized state, 80-item completeness, attestation/hash, and removed draft.
- **Verdict:** `NOT_RUN`

## GD-MI-004 — Results show all eight domains without diagnosis or top-three truncation
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.RESULTS`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`, `UC-GD-09`
- **Surface:** Portal/counsellor MI results
- **Route/API:** GET /portal/assessments/mi/results/{id}; GET /guidance/assessments/mi/results/{id}
- **Actor/grants:** Owning person and authorized Guidance interpreter/viewer
- **Preconditions:** MI interpretation/release policy unresolved; finalized synthetic submission with known scores in all eight domains.
**Steps**
1. Open results as the owning person and record all domains/descriptions/suggestions.
2. Open as authorized Guidance actor and as unrelated person; compare output/access.
- **Expected:** After policy approval, owner/authorized actor see all eight 0–40 domain scores and educational suggestions; unrelated person is denied.
- **Negative/non-disclosure:** Results contain no diagnostic label, hidden top-three-only representation, or another person's identity/results.
- **Cleanup:** Delete/reset finalized submission.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:564-642,694-790; app/Config/Routes.php:407-433
- **Evidence:** Owner/counsellor result screens, eight-domain reconciliation, and unrelated-person denial.
- **Verdict:** `NOT_RUN`

## GD-MI-005 — MI PDF matches authorized result data
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.PDF`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`, `UC-GD-09`
- **Surface:** MI result PDF
- **Route/API:** GET /portal/assessments/mi/results/{id}/pdf; GET /guidance/assessments/mi/results/{id}/pdf
- **Actor/grants:** Owning person or authorized Guidance result viewer
- **Preconditions:** MI release policy unresolved; finalized synthetic result; PDF compiler available.
**Steps**
1. Download the PDF as the owner and compare all eight scores and identifying header to the web result.
2. Attempt the PDF as an unrelated actor or with a foreign submission ID.
- **Expected:** After policy approval, a readable PDF matches the authorized web result and unauthorized download fails.
- **Negative/non-disclosure:** PDF includes no draft/cipher/key, diagnosis, unrelated person, or server path/error detail.
- **Cleanup:** Securely delete download and reset synthetic result.
- **Source:** app/Config/Routes.php:407-433; app/Controllers/GuidanceMultipleIntelligenceController.php:317-429; app/Services/Reporting/GuidanceMultipleIntelligenceLatex.php:630-638
- **Evidence:** Download headers/hash, rendered pages, eight-score comparison, and denied request.
- **Verdict:** `NOT_RUN`

## GD-MI-006 — Assessment ownership and tenant boundaries fail closed
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.ISOLATION`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`
- **Surface:** MI portal and console
- **Route/API:** MI draft/final/results routes
- **Actor/grants:** Assigned person, authorized counsellor, foreign/unrelated actors
- **Preconditions:** MI policy unresolved; same-tenant A/B and foreign-tenant assignments/results with unique sentinels.
**Steps**
1. As A, use A's draft/final/result routes, then substitute B/foreign IDs.
2. As counsellor without required result permission or case scope, attempt the same direct IDs.
- **Expected:** After policy approval, only self-taking and explicitly authorized result access succeeds; foreign/unrelated identifiers return no data.
- **Negative/non-disclosure:** Error shape does not disclose result existence, responses, domain scores, or tenant/person linkage.
- **Cleanup:** Delete/reset all synthetic MI records.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:694-790; app/Services/Guidance/GuidanceAccessPolicy.php:73-140
- **Evidence:** Actor/ID access matrix and negative sentinel search in denied responses.
- **Verdict:** `NOT_RUN`

## GD-EVL-001 — Qualified Guidance practitioner issues a one-time evaluation token through production workflow
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `GUIDANCE.EVALUATION.TOKEN_ISSUE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`
- **Surface:** Guidance service evaluations console
- **Route/API:** GET /guidance/evaluations/dashboard; POST /guidance/evaluations/issue
- **Actor/grants:** Authorized Guidance `counsellor`, `guidance_supervisor`, or `guidance_admin` (`guidance.evaluations.view_aggregates` / supervisor any-provider scope)
- **Preconditions:** Eligible same-tenant client with optional matching episode or counselling session; active provider user ID; manifest synced.
**Steps**
1. Open `/guidance/evaluations/dashboard` and submit `POST /guidance/evaluations/issue` for `PEF-008` or `CEF-009` linked to the synthetic client/context.
2. Attempt duplicate issuance for the same person/context/type, cross-client session/episode mismatch, and unauthorized issuance by an unrelated role or ordinary counsellor specifying another provider.
- **Expected:** One raw token and main-portal redeem URL (`/portal/evaluations/redeem/{token}`) return once (`201`/redirect flash), only SHA-256 hash/HMAC eligibility are persisted, and duplicate/mismatched/unauthorized requests fail closed.
- **Negative/non-disclosure:** Raw token is never stored in plaintext or copied into `guidance_service_evaluations` or audit logs; `session_id`/`episode_id` belong strictly to the selected client.
- **Cleanup:** Expire/delete the synthetic token and context in disposable teardown.
- **Source:** app/Config/Routes.php:453-459; app/Controllers/GuidanceEvaluationController.php:500-596; app/Services/Guidance/GuidanceServiceEvaluationService.php:200-270
- **Evidence:** Token issuance form/JSON output (raw token redacted), stored hash/HMAC check, and duplicate/scope denial matrix.
- **Verdict:** `NOT_RUN`

## GD-EVL-002 — One-time token submits an unlinkable pseudonymous evaluation
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.SUBMIT`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-06`
- **Surface:** Portal evaluation redemption
- **Route/API:** GET /portal/evaluations/redeem/{token}; POST /portal/evaluations/submit
- **Actor/grants:** Token holder with guidance.evaluations.submit as applicable
- **Preconditions:** Evaluation policy unresolved; issued unexpired token; complete 1–5/boolean ratings and synthetic qualitative comment.
**Steps**
1. Redeem the token and submit valid ratings/comment.
2. Inspect token state/evaluation row and try to redeem the same token again.
- **Expected:** After policy approval, token becomes redeemed once and one evaluation row stores ratings plus encrypted comment without direct person/session/episode/token linkage.
- **Negative/non-disclosure:** Reuse fails; plaintext comment and raw token do not enter row/log/audit; identity is not reconstructable from the response row.
- **Cleanup:** Delete/reset synthetic evaluation/token.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:270-383; app/Config/Routes.php:435-437
- **Evidence:** Redemption/submission UI, one-time conflict, schema-field inspection, and cipher-vs-comment search.
- **Verdict:** `NOT_RUN`

## GD-EVL-003 — Invalid, expired, and malformed evaluation submissions fail unchanged
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.VALIDATION`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-06`
- **Surface:** Portal evaluation redemption
- **Route/API:** POST /portal/evaluations/submit
- **Actor/grants:** Token holder
- **Preconditions:** Evaluation policy unresolved; expired token, unknown token, issued token with missing/out-of-range fields/oversized comment.
**Steps**
1. Submit the unknown/expired tokens and record safe error states.
2. Submit malformed ratings/booleans/comment with a valid issued token, then verify it remains usable for a valid correction.
- **Expected:** After policy approval, each invalid request is rejected without an evaluation; validation failure does not consume the otherwise valid token.
- **Negative/non-disclosure:** Errors reveal no linked person/provider/service context and do not echo raw token or qualitative text.
- **Cleanup:** Delete/reset all synthetic tokens.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:284-383,604-685; app/Config/Routes.php:435-437
- **Evidence:** Error matrix, token/evaluation row counts before/after, and successful corrected control if allowed.
- **Verdict:** `NOT_RUN`

## GD-EVL-004 — Moderation approves or redacts qualitative comments
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.MODERATION`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-15`
- **Surface:** Evaluation moderation console
- **Route/API:** GET/POST /guidance/evaluations/moderate/{id}
- **Actor/grants:** Guidance supervisor/admin with guidance.evaluations.moderate
- **Preconditions:** Evaluation policy unresolved; two synthetic encrypted comments pending review; ordinary counsellor control actor.
**Steps**
1. Attempt moderation as ordinary counsellor, then approve one and redact the other as moderator.
2. Reload moderation and aggregate views and inspect audit/state.
- **Expected:** After policy approval, only the moderator changes pending_review to approved/redacted with actor/time; aggregates show only approved comment text as allowed.
- **Negative/non-disclosure:** Moderator does not learn respondent identity/service token linkage, and redacted/pending plaintext is not published.
- **Cleanup:** Delete/reset synthetic evaluations/moderation records.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:385-444; app/Config/Routes.php:413-417
- **Evidence:** Permission denial, approved/redacted states, actor/timestamps, and aggregate visibility comparison.
- **Verdict:** `NOT_RUN`

## GD-EVL-005 — Aggregate reporting enforces cohort threshold and provider scope
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.AGGREGATE_PRIVACY`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`
- **Surface:** Evaluation dashboard
- **Route/API:** GET /guidance/evaluations/dashboard
- **Actor/grants:** Counsellor viewing own aggregate; supervisor/admin viewing authorized providers
- **Preconditions:** Cohort threshold policy unresolved; below-threshold and at-threshold synthetic cohorts for two providers; moderated comments.
**Steps**
1. Open a below-threshold provider aggregate and then the at-threshold provider aggregate as that provider.
2. Attempt another provider as ordinary counsellor, then as authorized supervisor.
- **Expected:** After threshold policy approval, small cohorts are withheld; eligible own aggregates render; ordinary counsellor cannot view another provider; supervisor can as granted.
- **Negative/non-disclosure:** No respondent-level row, identity, token/context linkage, pending/redacted comment, or small-cell inference appears.
- **Cleanup:** Delete/reset synthetic cohorts.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:446-604,868-903; app/Config/Routes.php:413-417
- **Evidence:** Below/at-threshold screens, actor scope matrix, and aggregate-to-fixture reconciliation.
- **Verdict:** `NOT_RUN`

## GD-EVL-006 — Aggregate PDF preserves threshold-safe output
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.AGGREGATE_PDF`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`
- **Surface:** Evaluation aggregate export
- **Route/API:** GET /guidance/evaluations/aggregate-pdf
- **Actor/grants:** Authorized aggregate viewer
- **Preconditions:** Cohort policy unresolved; eligible and suppressed synthetic provider cohorts; PDF compiler available.
**Steps**
1. Generate PDF for an eligible aggregate and compare ratings/comment inclusion to dashboard.
2. Request a below-threshold/unauthorized provider PDF.
- **Expected:** After policy approval, eligible PDF matches threshold-safe dashboard; suppressed/unauthorized export fails or contains only approved suppression messaging.
- **Negative/non-disclosure:** No respondent IDs, raw comments outside approved set, small cells, tokens, or server paths are present.
- **Cleanup:** Securely delete PDF and reset evaluation fixtures.
- **Source:** app/Config/Routes.php:413-417; app/Controllers/GuidanceEvaluationController.php:344-392; app/Services/Reporting/GuidanceFormLatex.php:1614-1646
- **Evidence:** PDF headers/hash/render, dashboard comparison, and suppressed/unauthorized result.
- **Verdict:** `NOT_RUN`
