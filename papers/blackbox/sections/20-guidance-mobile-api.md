# 20 — Guidance mobile API conditional annex

All cases are conditional because native Guidance UI is not yet part of the mobile app even though several V1 routes exist. Execute only after product/security approve the remaining API campaign and resolve the appointment/student-identity policy decisions. GD-API-003 is the exception: it verifies that the retired self-referral route remains unavailable.

## GD-API-001 — Student dashboard is own-scope and clinically redacted
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Authenticated identified student dashboard may return only own noncancelled Guidance appointments, noncancelled call slips, active MI assignments, and unexpired HMAC-eligible evaluation token metadata; it must exclude clinical notes, safety/triage, forms, and raw evaluation token.
- Surface: Guidance Mobile API V1 (no native UI yet).
- Route/API: `GET /api/v1/guidance/dashboard`.
- Actor/grants: Active bearer identity linked to a person; student-only eligibility is unresolved under CD-006.
- Preconditions: Disposable tenant with own/foreign appointments, slips, MI, evaluations, confidential notes, cancelled/expired variants.

**Steps**
1. Call dashboard as the fixture student and reconcile allowed arrays/fields with own source rows.
2. Search JSON for confidential/cipher/internal-note/safety/triage/form/raw-token canaries.
3. Call as another student, cross-tenant token, and with forged person/tenant selectors; separately record the current employee/staff behavior as an unresolved contract observation rather than assuming denial.

- Expected: Only the authenticated person’s eligible mobile-safe projection appears; identifiers/selectors cannot change subject. Cross-person and cross-tenant access denies. Employee/staff eligibility remains DRAFT under CD-006 until the API either enforces an active student identity or is approved as person-owned.
- Negative/non-disclosure: Counselor clinical notes, safety assessments, internal triage/delivery data, form instances, eligibility HMAC/raw redemption token, and other people/tenants are absent; this case must not falsely certify a student-only role check that current code does not perform.
- Cleanup: Revoke API token and retire DB.
- Source: `app/Controllers/Api/V1/GuidanceMobileController.php:44-60`; `app/Services/Guidance/GuidanceMobileContractService.php:98-250`
- Evidence: Redacted field/row reconciliation and identity/scope response matrix.
- Verdict: `NOT_RUN`

## GD-API-002 — Call-slip acknowledgement is owner-bound and transition-safe
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: A student may acknowledge only an own tenant-scoped call slip through the allowed mobile action; server resolves live actor, records `acknowledged` via the domain service, and returns only mobile-safe slip fields.
- Surface: Guidance Mobile API V1.
- Route/API: `POST /api/v1/guidance/call-slips/{id}/acknowledge`.
- Actor/grants: Active bearer student who owns the slip.
- Preconditions: Own pending slip, another student’s slip, cancelled/attended/acknowledged variants.

**Steps**
1. Acknowledge own pending slip and inspect status/timestamp, audit/domain side effects, and response fields.
2. Repeat acknowledgement and test terminal slips according to domain transition rules.
3. Attempt another person/tenant ID, forged actor/person fields, and inactive/revoked bearer.

- Expected: Valid own transition is recorded once/idempotently or deterministically rejected on repeat per service; foreign/invalid actor is denied without modifying row.
- Negative/non-disclosure: Response excludes internal delivery notes/form/clinical data and does not reveal foreign slip existence/details beyond bounded denial.
- Cleanup: Retire slip fixture DB and revoke token.
- Source: `app/Controllers/Api/V1/GuidanceMobileController.php:63-80`; `app/Services/Guidance/GuidanceMobileContractService.php:253-322`
- Evidence: Before/after slip/audit state and negative response matrix.
- Verdict: `NOT_RUN`

## GD-API-003 — Retired mobile self-referral endpoint remains unavailable
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Student self-referral is retired; the V1 API must not expose a route or controller action that creates `self` intake referrals.
- Surface: Guidance Mobile API V1 (no native UI).
- Route/API: legacy `POST /api/v1/guidance/self-referral` (retired).
- Actor/grants: Student, employee, counselor, and superadmin bearer variants.
- Preconditions: Disposable API tokens and a before-state digest of intake/audit tables.

**Steps**
1. Attempt the retired route with every bearer role and a formerly valid self-referral payload.
2. Verify route discovery/source does not advertise a handler and inspect the HTTP routing response according to the framework's ordinary missing-route behavior.
3. Reconcile intake and audit tables before and after.

- Expected: The retired endpoint is unavailable for every role and creates no intake or audit record; no replacement student self-referral route exists.
- Negative/non-disclosure: Response does not reveal person, intake, safety, or internal workflow details and cannot be converted into another-subject referral through payload fields.
- Cleanup: Revoke tokens and retire the disposable DB.
- Source: `app/Config/Routes.php:94-101`; `app/Controllers/Api/V1/GuidanceMobileController.php`
- Evidence: Route table/source assertion, role/status matrix, and unchanged table digests.
- Verdict: `NOT_RUN`

## GD-API-004 — MI draft, finalization, and results are own-assignment contracts
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Student may list only own assignments, save own item-score drafts, and—only when reproduction gate is enabled—finalize all 80 scores 0–4 with student capacity and explicit attestation; own results include immutable score/snapshot metadata without counselor-only data.
- Surface: Guidance Mobile API V1.
- Route/API: `GET /api/v1/guidance/mi`; `POST /mi/{assignment}/draft`; `POST /mi/{assignment}/finalize`; `GET /mi/results/{submission}`.
- Actor/grants: Active identified student owning assignment.
- Preconditions: Approved MI reproduction campaign; gate-off/on variants; own/foreign assignments/submissions; 80-item catalog.

**Steps**
1. List assignments/catalog, save a partial draft, then confirm it through the next supported assignment index response (there is no dedicated GET-draft route) before finalizing all 80 valid responses with student attestation while the gate is enabled.
2. Fetch results and reconcile scores, status, attested time, and snapshot hash.
3. Test gate off, incomplete/out-of-range answers, nonstudent signer capacity, repeat finalize, and foreign assignment/submission IDs.

- Expected: Draft/final/result remain owner-scoped; gate and complete validation enforced; finalization is immutable/idempotent per domain; foreign access denied.
- Negative/non-disclosure: Client cannot attest as guardian/counselor, view other results, alter catalog/snapshot, or receive counselor notes/internal interpretation.
- Cleanup: Retire MI fixture DB and disable reproduction gate.
- Source: `app/Controllers/Api/V1/GuidanceMobileController.php:152-229`; `app/Controllers/Api/V1/GuidanceMobileController.php:231-308`
- Evidence: Draft/final/result progression, 80-item validation, gate/identity matrix, and hash reconciliation.
- Verdict: `NOT_RUN`

## GD-API-005 — Evaluation submission requires eligible one-time redemption token
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: An identified student may submit a service evaluation only with an own eligible issued/unexpired raw redemption token and valid form payload; successful redemption is single-use and response exposes only evaluation ID/type/form/date.
- Surface: Guidance Mobile API V1.
- Route/API: `POST /api/v1/guidance/evaluations/submit`.
- Actor/grants: Active identified student eligible for the evaluation.
- Preconditions: Own/foreign/expired/redeemed/tampered tokens held only in secure harness; evaluation forms.

**Steps**
1. Submit a valid eligible token/form and reconcile one evaluation plus consumed token state and minimal response.
2. Replay the token and test expired, tampered, foreign-eligibility, wrong form/type, and invalid answers.
3. Search response/log/evidence/inbox for raw token, HMAC, identity linkage, and confidential session data.

- Expected: Valid submission returns `201` once; every invalid/replay variant fails without a second evaluation or partial redemption.
- Negative/non-disclosure: Raw token/HMAC is never returned/logged/committed; pseudonymous evaluation projection does not expose student, counselor notes, episode/session details, or other respondents.
- Cleanup: Destroy raw test tokens and retire DB.
- Source: `app/Controllers/Api/V1/GuidanceMobileController.php:311-345`; `app/Services/Guidance/GuidanceMobileContractService.php:206-250`
- Evidence: Run-local token aliases (never token hashes or raw tokens), outcome matrix, and evaluation/token row counts.
- Verdict: `NOT_RUN`

## GD-API-006 — Workstation-only Guidance actions always return 403 on mobile API
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Clinical authoring, clinical co-sign, safety triage/escalation, and case transfer/reassignment have no mobile authority; all methods/paths under explicit mobile boundaries and blocked `action` payloads must return `403` even for privileged staff bearers.
- Surface: Guidance Mobile API boundary.
- Route/API: `/api/v1/guidance/clinical/*`; `/safety/*`; `/cosign/*`; `/cases` and `/cases/*`; allowed endpoints carrying blocked `action`.
- Actor/grants: Student, counselor, supervisor/admin, superadmin bearer variants.
- Preconditions: Disposable clinical/case/safety fixtures with canary IDs; no native Guidance UI.

**Steps**
1. Send every method that is explicitly routed at each wildcard boundary and GET at the `/guidance/cases` root using each role variant and valid-looking payloads/IDs; record unrouted root methods separately as 404/405 routing behavior, not as promised 403 denials.
2. Send blocked action names inside MI draft/final and evaluation payloads.
3. Compare every clinical/case/safety/form/transfer/audit row before and after and inspect responses.

- Expected: Every explicitly routed workstation-only attempt is `403` with a generic boundary message and zero writes; unrouted root methods remain ordinary 404/405 routing outcomes; allowed student-safe actions remain separately routable under their rules.
- Negative/non-disclosure: No role—including superadmin—gets mobile clinical authority; responses reveal no record existence/content, safety status, note, signer, or transfer target.
- Cleanup: Revoke tokens and retire untouched fixture DB.
- Source: `app/Config/Routes.php:94-109`; `app/Controllers/Api/V1/GuidanceMobileController.php:347-365`; `app/Services/Guidance/GuidanceMobileContractService.php:75-96`
- Evidence: Role/method/path response matrix and before/after table digests.
- Verdict: `NOT_RUN`
