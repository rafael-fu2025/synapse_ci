# Guidance intake, governance, and practitioner controls

## GD-INT-001 — Employee portal resolves only exact active students; Guidance resolver remains staff-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.EXACT_SUBJECT`
- **Surface:** Employee Portal Guidance referral and Guidance intake workstation
- **Route/API:** POST /portal/referrals/resolve-student; POST /guidance/intake/resolve-subject
- **Actor/grants:** Active employee for the portal route; Guidance actor with guidance.intake.process for the workstation route
- **Preconditions:** Known same-tenant student MIS ID; employee MIS ID; wildcard/name fragments; foreign-tenant sentinel ID.
**Steps**
1. As an active employee, resolve the exact synthetic student MIS ID and inspect the minimal student confirmation.
2. Attempt the employee portal route as a student, with an employee MIS ID, blank, wildcard, name-fragment, unknown, and foreign-tenant values.
3. As authorized Guidance staff, use the workstation resolver for its broader intake subject lookup; deny an actor without guidance.intake.process.
- **Expected:** Employee lookup returns only an active same-tenant student; student actors and directory-like/nonstudent queries fail. Guidance lookup remains permission-gated and may resolve the person kinds supported by intake.
- **Negative/non-disclosure:** No broad patient directory, case count/title, note, safety plan, contact details, employee record through the portal resolver, or foreign tenant person is disclosed.
- **Cleanup:** No state change; clear session and captured synthetic identifiers.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:112-184; app/Config/Routes.php:420-424
- **Evidence:** Exact confirmation and negative query/status matrix with minimal response fields.
- **Verdict:** `NOT_RUN`

## GD-INT-002 — Employee multi-student referral fans out encrypted intake records atomically
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.PORTAL_SUBMIT`
- **Surface:** Employee Portal Guidance referral
- **Route/API:** GET /portal/referrals/create; POST /portal/referrals/store
- **Actor/grants:** Active employee-backed referrer; student actors denied
- **Preconditions:** Two or more same-tenant synthetic students; reviewed concern catalog; non-sensitive shared narrative/prior-action sentinels; activation prerequisites satisfied for live submission.
**Steps**
1. Add multiple students by exact MIS ID, select shared concerns, narrative, prior actions, and priority hint, then submit.
2. Verify one independently numbered intake row per selected student and inspect encrypted/audit representations.
3. Attempt the form as a student, include a nonstudent/foreign subject, exceed the batch cap, and force a later-child failure to prove full rollback.
- **Expected:** One atomic form submission creates a separate encrypted referral lifecycle per selected student; duplicates are normalized and any invalid/failing child rolls back the whole batch.
- **Negative/non-disclosure:** Students and inactive employees cannot use the surface; priority is only a referrer attention hint; plaintext and other referrals are absent from logs/audit/list summaries.
- **Cleanup:** Withdraw/close and delete the synthetic intake via disposable teardown.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:298-520; app/Config/Routes.php:420-424
- **Evidence:** Portal submission/status, Guidance receipt row, cipher-vs-sentinel check, and metadata-only audit evidence.
- **Verdict:** `NOT_RUN`

## GD-INT-003 — Staff creates intake on behalf of an identified subject
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.STAFF_CREATE`
- **Surface:** Guidance intake workspace
- **Route/API:** POST /guidance/intake/staff-create
- **Actor/grants:** Guidance actor with guidance.intake.process
- **Preconditions:** Exact synthetic subject; active qualified staff actor; source type/reason/concerns; no real counselling content.
**Steps**
1. Create and submit a staff-on-behalf intake for the exact subject.
2. Open the intake detail and attempt creation as an actor without intake.process.
- **Expected:** Authorized intake records staff/referrer snapshots and encrypted content; unauthorized creation fails without a row.
- **Negative/non-disclosure:** Staff creation does not create a duplicate person, import MIS data, or confer case access to an unrelated actor.
- **Cleanup:** Close/delete the synthetic intake.
- **Source:** app/Config/Routes.php:365-375; app/Services/Guidance/GuidanceIntakeService.php:298-520,1215-1257
- **Evidence:** Create/detail state, actor/source snapshot, encrypted storage proof, and denied control actor.
- **Verdict:** `NOT_RUN`

## GD-INT-004 — Intake receive and triage preserve hint-versus-risk distinction
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.RECEIVE_TRIAGE`
- **Surface:** Guidance intake workspace
- **Route/API:** POST /guidance/intake/{id}/receive|triage
- **Actor/grants:** Guidance actor with guidance.intake.process
- **Preconditions:** Submitted synthetic intake with routine hint; qualified triage actor; no authoritative safety assessment yet.
**Steps**
1. Receive the submitted intake and record timestamps/state.
2. Triage it with an updated attention hint and inspect intake detail and any safety section.
- **Expected:** State advances submitted → received → triaged and the attention hint is retained as intake routing data only.
- **Negative/non-disclosure:** The triage action does not invent an authoritative clinical risk assessment or expose encrypted narrative to unauthorized actors.
- **Cleanup:** Close/delete the synthetic intake.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:529-614; app/Config/Routes.php:368-371
- **Evidence:** State/timestamp sequence and evidence that no safety-assessment row was created.
- **Verdict:** `NOT_RUN`

## GD-INT-005 — Accepted intake links to a matching case explicitly
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.ACCEPT_LINK_CASE`
- **Surface:** Guidance intake workspace
- **Route/API:** POST /guidance/intake/{id}/accept|link-case
- **Actor/grants:** Qualified Guidance actor with intake.process and cases.write/create
- **Preconditions:** Triaged synthetic intake; existing open case for the same subject plus no-case variant; active eligible counsellor.
**Steps**
1. Accept the intake, then choose link to the existing matching case and inspect both records.
2. For a second accepted intake choose create-new, and attempt to link a different subject's case.
- **Expected:** Existing link sets linked_case_id explicitly; create-new makes a new owned case; mismatched subject link fails.
- **Negative/non-disclosure:** The system never selects a person's latest unrelated case implicitly and never copies confidential content into an unauthorized summary.
- **Cleanup:** Close/delete created case(s) and intake fixtures.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:616-787; app/Config/Routes.php:371-374
- **Evidence:** Accepted/link UI, exact case IDs, create-vs-link outcomes, and mismatched-case denial.
- **Verdict:** `NOT_RUN`

## GD-INT-006 — Decline, withdraw, and external handoff are terminal controlled paths
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.TERMINAL_PATHS`
- **Surface:** Portal and Guidance intake
- **Route/API:** POST /guidance/intake/{id}/decline|withdraw|external-handoff
- **Actor/grants:** Original referrer for withdraw; Guidance processor for decline/handoff
- **Preconditions:** Three synthetic intake records in eligible states; decline reason/external facility values; original and unrelated referrer actors.
**Steps**
1. Withdraw the first as its original referrer and attempt withdrawal as another actor or after triage.
2. Decline the second with reason and externally hand off the third with facility details; try a further transition on each.
- **Expected:** Only the original referrer can withdraw before triage; decline and external handoff record terminal metadata; later invalid transitions fail.
- **Negative/non-disclosure:** Terminal actions do not erase encrypted source content, expose it in audit logs, or create an internal case silently.
- **Cleanup:** Delete/reset all synthetic terminal intake fixtures.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:669-924; app/Config/Routes.php:372-375,424-424
- **Evidence:** Three terminal states, reasons/facility metadata, unauthorized/late-withdrawal and post-terminal conflicts.
- **Verdict:** `NOT_RUN`

## GD-INT-007 — Clinic referral bridge creates one Guidance intake link
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.CLINIC_BRIDGE`
- **Surface:** Guidance intake bridge
- **Route/API:** POST /guidance/intake/bridge-clinic/{referralId}
- **Actor/grants:** Guidance actor with intake.process and referral read
- **Preconditions:** Synthetic Clinic→Guidance interdepartmental referral for one person; no existing bridge; source referral content uses synthetic sentinels.
**Steps**
1. Bridge the Clinic referral from the Guidance intake workspace.
2. Open both records, then repeat the bridge request or try a referral with mismatched/foreign linkage.
- **Expected:** Exactly one submitted Guidance intake links to the source Clinic referral and same person; repeat/mismatch is rejected or returns the established link without duplication.
- **Negative/non-disclosure:** Bridge does not automatically create a counselling case or disclose source encounter content beyond authorized referral data.
- **Cleanup:** Close/delete intake and referral fixtures.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:938-1025; app/Config/Routes.php:373-375
- **Evidence:** Source/bridged IDs, person/link reconciliation, and duplicate/mismatch response.
- **Verdict:** `NOT_RUN`

## GD-GOV-001 — Activation gate remains disabled until every required authority signs
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.GOVERNANCE.MULTI_AUTHORITY_GATE`
- **Surface:** Guidance governance gates
- **Route/API:** GET /guidance/governance/gates; POST /guidance/governance/gates/{key}/signoff
- **Actor/grants:** Distinct Guidance Head, QA Office, and DPO authority accounts
- **Preconditions:** Policy decision required on whether institutional signoffs require independent accounts; isolated gate with required roles and three approved synthetic authorities.
**Steps**
1. Record one and then two distinct authority signoffs, checking gate state after each.
2. Record the final required signoff and attempt an invalid authority role.
- **Expected:** Once authority-account policy is approved, the gate stays disabled until all required roles are recorded, then enables with approved_at; invalid authority is rejected.
- **Negative/non-disclosure:** A general administrator/counsellor label does not silently impersonate an institutional authority and signoff notes do not expose protected data.
- **Cleanup:** Reset isolated gate/signoffs.
- **Source:** app/Services/Guidance/GuidanceActivationGateService.php:17-96,159-257; app/Config/Routes.php:358-363
- **Evidence:** Gate state/signoff matrix, actor identities/roles, approved timestamp, and invalid-role response.
- **Verdict:** `NOT_RUN`

## GD-GOV-002 — Unmet governance gates block gated production workflows
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.GOVERNANCE.FAIL_CLOSED`
- **Surface:** Gated Guidance workflows
- **Route/API:** Representative controlled-form/safety/MI/evaluation action
- **Actor/grants:** Otherwise authorized Guidance practitioner/manager
- **Preconditions:** Applicable gate explicitly disabled; corresponding workflow fixture prepared; institutional policy mapping unresolved.
**Steps**
1. Attempt the gated workflow while one required signoff is missing.
2. Complete approved synthetic signoffs and repeat the same workflow.
- **Expected:** After policy mapping is confirmed, the first attempt fails closed and the second becomes available without bypassing ordinary role/practitioner checks.
- **Negative/non-disclosure:** Gate enablement does not itself grant permissions, professional standing, case ownership, or cross-tenant access.
- **Cleanup:** Undo test signoffs/gate state and delete workflow fixture.
- **Source:** app/Services/Guidance/GuidanceActivationGateService.php:145-157,159-257; app/Services/Guidance/GuidanceActivationGateService.php:65-96
- **Evidence:** Disabled denial, gate state/signoffs, enabled control attempt, and unchanged grants.
- **Verdict:** `NOT_RUN`

## GD-GOV-003 — Guardian digital attestation remains blocked in v1
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.GOVERNANCE.GUARDIAN_CAPACITY`
- **Surface:** Controlled-form attestation
- **Route/API:** Representative form finalize/attestation route
- **Actor/grants:** Authenticated form actor
- **Preconditions:** Controlled form draft; signer capacity set to guardian/parent variants; guardian identity workflow not approved.
**Steps**
1. Attempt to finalize/attest using each unsupported guardian capacity.
2. Finalize a separate eligible synthetic form using an allowed current-account capacity as control.
- **Expected:** Guardian/parent capacities are rejected while allowed account capacities follow normal authorization and attestation rules.
- **Negative/non-disclosure:** No simulated guardian identity, external signature, or unverified authority is stored as a valid digital attestation.
- **Cleanup:** Delete/reset synthetic drafts/revisions/attestations.
- **Source:** app/Services/Guidance/GuidanceActivationGateService.php:34-63; app/Services/Guidance/FormAttestationService.php:37-154
- **Evidence:** Rejected capacities, successful allowed control, and attestation row comparison.
- **Verdict:** `NOT_RUN`

## GD-PRC-001 — Practitioner profile records tier, scope, and standing
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.REGISTER`
- **Surface:** Guidance practitioner governance
- **Route/API:** POST /guidance/governance/practitioners/register
- **Actor/grants:** Guidance manager with governance authority
- **Preconditions:** Active same-tenant Guidance user; synthetic tier/license/expiry/service scopes; foreign-tenant user control.
**Steps**
1. Register/update the practitioner's profile and inspect the governance list.
2. Try an invalid tier, foreign user, and expired credential scenario.
- **Expected:** Valid profile persists tier, scope, expiry, active standing; invalid/foreign input fails and expiry affects eligibility.
- **Negative/non-disclosure:** Role/superadmin status alone does not create professional eligibility and license values are not broadly exposed.
- **Cleanup:** Delete/reset synthetic practitioner profile.
- **Source:** app/Services/Guidance/GuidancePractitionerService.php:58-133,264-288; app/Config/Routes.php:361-363
- **Evidence:** Profile list/detail, valid/invalid responses, and eligibility result.
- **Verdict:** `NOT_RUN`

## GD-PRC-002 — Associate supervision is current and self-supervision is forbidden
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.SUPERVISION`
- **Surface:** Guidance practitioner governance
- **Route/API:** POST /guidance/governance/practitioners/supervision
- **Actor/grants:** Guidance manager/supervisor
- **Preconditions:** Synthetic associate and two eligible supervisors in same tenant; prior active supervision record optional.
**Steps**
1. Assign supervisor A with co-sign required, then assign supervisor B and inspect history/current assignment.
2. Attempt to assign the associate to supervise themselves or use a foreign/inactive user.
- **Expected:** New assignment retires the prior active one; exactly one current supervision remains; invalid/self assignments fail.
- **Negative/non-disclosure:** A saved historical supervisor snapshot does not remain current authority after reassignment.
- **Cleanup:** End/delete synthetic supervision assignments/profiles.
- **Source:** app/Services/Guidance/GuidancePractitionerService.php:135-227; app/Config/Routes.php:361-363
- **Evidence:** Assignment history/current record and invalid/self-supervision responses.
- **Verdict:** `NOT_RUN`

## GD-PRC-003 — Direct finalization requires active licensed or supervisor standing
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.DIRECT_FINALIZE`
- **Surface:** Guidance form/session finalization
- **Route/API:** Representative finalize route
- **Actor/grants:** Licensed counsellor/supervisor versus associate/lapsed/expired actors
- **Preconditions:** Identical synthetic drafts assigned to actors with each standing/tier; required author permission present.
**Steps**
1. Attempt direct finalization as active licensed counsellor and active Guidance supervisor.
2. Attempt it as associate, suspended/lapsed actor, expired actor, and general admin without qualifying profile.
- **Expected:** Only active unexpired licensed counsellor or Guidance supervisor can finalize directly, subject to ordinary grants and ownership.
- **Negative/non-disclosure:** Superadmin/admin role never bypasses practitioner standing; failed attempts leave drafts mutable and unfinalized.
- **Cleanup:** Delete/reset synthetic drafts/profiles.
- **Source:** app/Services/Guidance/GuidancePractitionerService.php:229-262,290-359
- **Evidence:** Actor profile matrix, finalize outcomes, and unchanged failed draft states.
- **Verdict:** `NOT_RUN`

## GD-PRC-004 — Associate-authored record requires current supervisor co-sign
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.COSIGN`
- **Surface:** Clinical controlled form
- **Route/API:** POST /guidance/clinical/forms/{id}/submit-cosign|cosign
- **Actor/grants:** Associate author and currently assigned eligible supervisor
- **Preconditions:** Associate profile/active supervision; clinical form draft; former supervisor and unrelated supervisor controls; co-sign grants configured.
**Steps**
1. Have the associate submit the draft for co-sign and attempt self-finalization.
2. Try former/unrelated supervisors, then have the current assigned supervisor co-sign and inspect final revision/attestation.
- **Expected:** Self/former/unrelated finalization fails; current eligible supervisor creates one immutable finalized revision and cosigner attestation.
- **Negative/non-disclosure:** Stored assigned_supervisor snapshot alone is not authority, plaintext is not logged, and failed signers cannot read/finalize outside case access.
- **Cleanup:** Delete/reset form, revision, attestation, and supervision fixtures.
- **Source:** app/Services/Guidance/GuidanceClinicalFormService.php:309-428,430-520; app/Services/Guidance/GuidancePractitionerService.php:290-335
- **Evidence:** Pending-cosign state, denial matrix, finalized revision/attestation, and current supervision proof.
- **Verdict:** `NOT_RUN`
