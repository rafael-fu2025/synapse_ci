# Interdepartmental referrals

## RF-001 — Clinic explicitly creates a referral to Guidance
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-001`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.CREATE.CLINIC_GUIDANCE`
- **Surface:** Clinic referrals
- **Route/API:** GET/POST /clinic/referrals/create|store
- **Actor/grants:** Clinic actor with referrals.create; source encounter read when linked
- **Preconditions:** Synthetic patient; optional owned Clinic encounter; allowed reason/priority; unique 64-hex request key; Guidance recipient staff.
**Steps**
1. Create Clinic→Guidance referral, explicitly selecting target and optional source encounter.
2. Open Clinic outgoing and Guidance incoming lists and inspect linkage/status.
- **Expected:** Exactly one submitted referral is created with source=clinic, target=guidance, same patient, optional validated encounter, and appears in both correct lists.
- **Negative/non-disclosure:** Client-forged source/target, wrong-patient encounter, duplicate key side effects, or clinical note content is rejected/not disclosed.
- **Cleanup:** Close/delete the synthetic referral and related encounter fixture.
- **Source:** app/Services/Referrals/ReferralService.php:152-256; app/Config/Routes.php:442-462
- **Evidence:** Create form/result, outgoing/incoming rows, source/target/patient/link IDs, and request-key replay proof.
- **Verdict:** `NOT_RUN`

## RF-002 — Guidance explicitly creates a referral to Clinic
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-002`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.CREATE.GUIDANCE_CLINIC`
- **Surface:** Guidance referrals
- **Route/API:** GET/POST /guidance/referrals/create|store
- **Actor/grants:** Guidance actor with referrals.create
- **Preconditions:** Synthetic patient; Guidance source context; allowed Clinic reason/priority; unique request key.
**Steps**
1. Create Guidance→Clinic referral with explicit target and reason.
2. Open Guidance outgoing and Clinic incoming lists and inspect routing/status.
- **Expected:** Exactly one submitted source=guidance,target=clinic referral appears for the same patient in the appropriate workstations.
- **Negative/non-disclosure:** A Guidance referral cannot attach a source Clinic encounter, route to the same module, or expose counselling notes.
- **Cleanup:** Close/delete the synthetic referral.
- **Source:** app/Services/Referrals/ReferralService.php:152-256; app/Config/Routes.php:442-462
- **Evidence:** Create/result and both lists with explicit routing and no confidential source content.
- **Verdict:** `NOT_RUN`

## RF-004 — General referrer gains no clinical-record access
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-004`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.NONDISCLOSURE.REFERRER`
- **Surface:** Portal Guidance intake and department records
- **Route/API:** POST /portal/referrals/store; direct Clinic/Guidance GET attempts
- **Actor/grants:** Employee/teacher or other supported general referrer without clinical grants
- **Preconditions:** Synthetic referrer submits a Guidance intake about a synthetic subject; target has Clinic chart and Guidance case sentinels.
**Steps**
1. Submit through the supported general Guidance intake workflow and record only the permitted receipt/status.
2. Attempt Clinic chart, encounter, Guidance case/session, interdepartmental referral inbox, and patient lookup URLs.
- **Expected:** The intake receipt exists, but every clinical/counselling/referral-staff resource is forbidden or absent to the referrer.
- **Negative/non-disclosure:** Referring never grants chart/case/note/diagnosis/safety/referral inbox access or exposes whether protected records exist.
- **Cleanup:** Withdraw/close the synthetic intake and clear session.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:112-184,298-520; app/Services/Referrals/ReferralService.php:87-135
- **Evidence:** Submission receipt and denied route matrix with negative sentinel search.
- **Verdict:** `NOT_RUN`

## RF-005 — Receiving unit acknowledges a submitted referral
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-005`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.ACKNOWLEDGE`
- **Surface:** Referral workstation
- **Route/API:** POST /{target}/referrals/{id}/acknowledge
- **Actor/grants:** Active receiving-unit actor with referrals.acknowledge
- **Preconditions:** Submitted synthetic referral assigned to target unit; sending-unit and wrong-target actors available.
**Steps**
1. Acknowledge from the receiving workstation and reload incoming/outgoing views.
2. Attempt acknowledgement from sender/wrong unit or repeat from stale state.
- **Expected:** Status becomes acknowledged with assigned receiving actor and timestamp/workflow notification; invalid-unit/stale transition fails.
- **Negative/non-disclosure:** Acknowledgement grants no automatic target clinical record and does not expose hidden source record IDs to unauthorized viewers.
- **Cleanup:** Continue to close or delete the referral fixture.
- **Source:** app/Services/Referrals/ReferralService.php:258-270,531-607; app/Config/Routes.php:442-452
- **Evidence:** Before/after status/actor/time, both workstation views, and wrong-unit/stale denial.
- **Verdict:** `NOT_RUN`

## RF-006 — Receiving unit starts review without creating a target record
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-006`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.REVIEW`
- **Surface:** Referral workstation
- **Route/API:** POST /{target}/referrals/{id}/review
- **Actor/grants:** Assigned receiving actor with referrals.review
- **Preconditions:** Acknowledged synthetic referral; no target case/encounter linked.
**Steps**
1. Start review and inspect status/event/actions.
2. Verify no target encounter/case exists yet and attempt review from invalid state/actor.
- **Expected:** Status becomes under_review and records reviewer/event while explicit create/link actions remain separate.
- **Negative/non-disclosure:** Review does not silently create a case/encounter, disclose confidential records, or permit the sender to act as receiver.
- **Cleanup:** Proceed to explicit handoff/close and reset fixture.
- **Source:** app/Services/Referrals/ReferralService.php:265-270,531-607; app/Config/Routes.php:447-451
- **Evidence:** Review state/event, zero target link proof, and invalid actor/state response.
- **Verdict:** `NOT_RUN`

## RF-007 — Clinic target handoff explicitly creates or links an open encounter
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-007`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.HANDOFF.CLINIC_CREATE_LINK`
- **Surface:** Clinic incoming referral
- **Route/API:** GET/POST /clinic/referrals/{id}/handoff
- **Actor/grants:** Clinic receiver with referrals.review plus clinic.encounters.read/create
- **Preconditions:** Acknowledged/under-review Guidance→Clinic referral; one existing open encounter for link scenario and no encounter for create scenario; same patient.
**Steps**
1. Choose Link for the existing same-patient open encounter and inspect target ID/URL.
2. On a second referral choose Create; attempt link to sealed/different-patient/foreign encounter.
- **Expected:** Link uses the selected accessible open encounter; Create opens a new referral encounter; referral records exact target without duplicating on repeat.
- **Negative/non-disclosure:** No implicit latest encounter is selected, invalid target details are not leaked, and create/link choice is explicit.
- **Cleanup:** Seal/delete created encounter and close/delete referrals.
- **Source:** app/Services/Referrals/ReferralService.php:415-528; app/Services/Clinic/EncounterService.php:104-213
- **Evidence:** Handoff choice, linked/created flags and target IDs, invalid-target denial, and repeat behavior.
- **Verdict:** `NOT_RUN`

## RF-008 — Guidance target handoff explicitly creates or links a case
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-008`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.HANDOFF.GUIDANCE_CREATE_LINK`
- **Surface:** Guidance incoming referral
- **Route/API:** GET/POST /guidance/referrals/{id}/handoff; compatibility /referrals/{id}/convert
- **Actor/grants:** Guidance receiver with referrals.review plus guidance.cases.read/create
- **Preconditions:** Acknowledged/under-review Clinic→Guidance referral; one existing accessible open case for same patient and separate create scenario.
**Steps**
1. Choose Link to the selected existing case and inspect referral/target.
2. Choose Create on a second referral with explicit title/category; attempt a different patient's or unowned inaccessible case.
- **Expected:** Link attaches exactly the selected accessible case; Create makes a new owned case carrying the referral summary as intake context and sets converted_to_case.
- **Negative/non-disclosure:** No latest-case inference, unrelated owner bypass, duplicate case creation, or confidential source encounter note copying occurs.
- **Cleanup:** Close/delete created case and referral fixtures.
- **Source:** app/Services/Referrals/ReferralService.php:272-288,415-528; app/Config/Routes.php:442-462
- **Evidence:** Create/link choices, target case IDs/owner/status, mismatch/ownership denial, and duplicate-attempt result.
- **Verdict:** `NOT_RUN`

## RF-009 — Reviewed or linked referral closes into history
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-009`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.CLOSE`
- **Surface:** Referral workstation
- **Route/API:** POST /{unit}/referrals/{id}/close
- **Actor/grants:** Authorized receiving actor with referrals.close
- **Preconditions:** Acknowledged, under-review, or converted synthetic referral; incoming/outgoing/history tabs available.
**Steps**
1. Close the referral and inspect active and closed/history tabs on both relevant workstations.
2. Attempt close from submitted/wrong-unit/already-closed state.
- **Expected:** Eligible referral becomes closed with timestamp, leaves active inbox, and remains in scoped history; invalid transitions fail.
- **Negative/non-disclosure:** Closure does not delete audit/linkage or grant target-record access to a previously unauthorized sender.
- **Cleanup:** Delete/reset referral/target fixtures after evidence.
- **Source:** app/Services/Referrals/ReferralService.php:290-298,400-413,531-607; app/Config/Routes.php:442-462
- **Evidence:** Active-to-history movement, closed timestamp/link retention, and invalid state/unit responses.
- **Verdict:** `NOT_RUN`

## RF-010 — Referral filters use current referral statuses and units
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-010`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.LIST.FILTERS`
- **Surface:** Referral lists
- **Route/API:** GET /clinic/referrals; GET /guidance/referrals
- **Actor/grants:** Unit actor with referrals.read
- **Preconditions:** Synthetic referrals across submitted/acknowledged/under_review/converted_to_case/closed and clinic/guidance targets; foreign tenant sentinels.
**Steps**
1. Filter incoming/outgoing/history by every current status and explicit target unit; page results.
2. Submit invalid medicine-like status, invalid unit, and foreign identifiers.
- **Expected:** Each supported filter returns only matching same-tenant rows with bounded pagination; invalid values receive validation errors.
- **Negative/non-disclosure:** No foreign tenant, hidden clinical target ID, or unrelated unit's active inbox is exposed.
- **Cleanup:** Delete/reset synthetic referrals.
- **Source:** app/Services/Referrals/ReferralService.php:26-43,299-413; app/Config/Routes.php:442-462
- **Evidence:** Filter/pagination matrix, expected IDs/counts, and invalid/foreign results.
- **Verdict:** `NOT_RUN`
