# Guidance records, forms, episodes, call slips, and clinical workflow

## GD-REC-001 — Clients directory lists existing same-tenant people only
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.RECORDS.DIRECTORY`
- **Role:** `guidance_intern`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-03`
- **Surface:** Guidance Clients & Records
- **Route/API:** GET /guidance/clients
- **Actor/grants:** Guidance actor with guidance.cases.read
- **Preconditions:** Same-tenant student/employee with no Guidance history/login and foreign-tenant sentinel person; enough rows for pagination.
**Steps**
1. Search by exact MIS ID/name and page through the Guidance directory.
2. Search the no-history person and foreign sentinel; inspect result fields.
- **Expected:** Existing same-tenant student/employee can be found without creating/importing identity or requiring prior Guidance history.
- **Negative/non-disclosure:** No case counts/titles, notes, safety plans, form contents, contact secrets, or foreign tenant identities appear in directory results.
- **Cleanup:** Delete synthetic directory-only person if created/reset database.
- **Source:** docs/GUIDANCE.md:3-29; app/Config/Routes.php:311-314
- **Evidence:** Search/pagination and no-history result, negative sentinel/data-field inspection.
- **Verdict:** `NOT_RUN`

## GD-REC-002 — Cumulative record loads independently paginated authorized histories
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.RECORDS.CUMULATIVE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-03`, `UC-GD-05`
- **Surface:** Guidance client record
- **Route/API:** GET /guidance/clients/{personId}
- **Actor/grants:** Guidance actor with base read plus section-specific grants
- **Preconditions:** Synthetic client with cases/episodes/sessions/follow-ups/MI/surveys/referrals/forms/appointments/call slips; enough rows in two sections to paginate.
**Steps**
1. Open the client record and switch/page each permitted section independently.
2. Compare total counts to all permitted rows while the displayed page remains bounded.
- **Expected:** Each history loads and paginates independently, totals cover permitted records, and the legacy cumulative URL remains compatible.
- **Negative/non-disclosure:** Loading one section does not disclose restricted sections or fetch all confidential content into the page.
- **Cleanup:** Reset synthetic histories.
- **Source:** docs/GUIDANCE.md:10-24; app/Config/Routes.php:312-314
- **Evidence:** Section screenshots, independent query/pagination evidence, and total reconciliation.
- **Verdict:** `NOT_RUN`

## GD-REC-003 — Record histories honor ownership, consultation, read-any, and section grants
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.RECORDS.AUTHORIZATION`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-03`, `UC-GD-06`
- **Surface:** Guidance client record
- **Route/API:** GET /guidance/clients/{personId}
- **Actor/grants:** Owner, accepted consultant, read_any supervisor, base-read-only counsellor
- **Preconditions:** Synthetic client with cases belonging to multiple counsellors and section-specific safety/form/assessment data; consultation and read_any fixtures.
**Steps**
1. Open the same record as each actor and inventory visible counts/sections.
2. Attempt direct links to a hidden case/form/safety record from the base-read-only actor.
- **Expected:** Case history follows owner/accepted consultation/read_any while each specialist section additionally requires its own permission/context.
- **Negative/non-disclosure:** Basic identity browsing never discloses restricted counts, titles, note text, safety plan, or form content.
- **Cleanup:** Remove consultation/case/history fixtures.
- **Source:** docs/GUIDANCE.md:17-36; app/Services/Guidance/GuidanceAccessPolicy.php:145-218
- **Evidence:** Four-actor visibility matrix and direct-link denials with hidden sentinels searched.
- **Verdict:** `NOT_RUN`

## GD-REC-004 — Confidential record read fails closed when audit cannot append
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.RECORDS.READ_AUDIT_FAIL_CLOSED`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Guidance form/note/episode reader
- **Route/API:** Representative GET for draft/final/legacy note/episode closure
- **Actor/grants:** Otherwise authorized owner/supervisor
- **Preconditions:** Synthetic encrypted confidential record; healthy audit control; isolated mode that makes ledger missing/invalid/append fail without touching operational audit data.
**Steps**
1. Read the record with a healthy ledger and confirm the metadata-only audit append precedes release.
2. Induce the isolated audit failure and repeat the read.
- **Expected:** Healthy read returns authorized content and audit metadata; failed audit append prevents confidential content from being returned.
- **Negative/non-disclosure:** No plaintext is emitted in the failure body/log/audit payload and no fallback reader bypasses the boundary.
- **Cleanup:** Restore isolated audit fixture and delete synthetic record.
- **Source:** docs/GUIDANCE.md:38-41; app/Services/Guidance/GuidanceFormService.php:263-316,486-547
- **Evidence:** Healthy audit/read sequence, failed response with no sentinel, and ledger state.
- **Verdict:** `NOT_RUN`

## GD-FRM-001 — Canonical form manifest sync exposes reviewed active definitions
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.FORMS.CANONICAL_DEFINITIONS`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-16`
- **Surface:** Controlled Forms definitions
- **Route/API:** GET /guidance/forms/definitions; POST /guidance/forms/sync-manifest
- **Actor/grants:** Guidance supervisor/admin with guidance.forms.manage
- **Preconditions:** Canonical form-signoff gate/policy unresolved; repository manifest available; isolated empty/older definitions database.
**Steps**
1. Review definition/revision list, sync the checked-in manifest, and reload.
2. Attempt to supply a client-authored code/title/revision outside the manifest.
- **Expected:** After canonical-code policy approval, reviewed definitions/revisions synchronize deterministically and unsupported client definitions are rejected.
- **Negative/non-disclosure:** Sync does not silently resolve known code/title policy contradictions or expose encrypted form instances.
- **Cleanup:** Reset isolated definition rows.
- **Source:** app/Config/Routes.php:336-345; app/Services/Guidance/GuidanceFormDefinitionService.php:1-239
- **Evidence:** Before/after definition list, manifest hash/revisions, and unsupported-input response.
- **Verdict:** `NOT_RUN`

## GD-FRM-002 — Controlled form creation validates the complete context graph
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.FORMS.CONTEXT_GRAPH`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Controlled Forms
- **Route/API:** POST /guidance/forms/create
- **Actor/grants:** Authorized Guidance form author with case access
- **Preconditions:** Synthetic person/case/session/episode/appointment/referral/intake graph plus deliberately mismatched links.
**Steps**
1. Create a form instance with a fully consistent explicit context and inspect its metadata.
2. Repeat with a mismatched person/case, unrelated latest case, and foreign-tenant link.
- **Expected:** Consistent context creates a draft; every mismatch fails rather than repairing/selecting an unrelated record.
- **Negative/non-disclosure:** No person's latest unrelated case/intake is inferred, and failed context does not disclose target metadata.
- **Cleanup:** Delete/reset synthetic form and graph records.
- **Source:** docs/GUIDANCE.md:31-36; app/Services/Guidance/GuidanceFormContextPolicy.php:1-353
- **Evidence:** Created context IDs and rejection matrix for mismatched/foreign links.
- **Verdict:** `NOT_RUN`

## GD-FRM-003 — Encrypted draft autosave replaces one mutable draft
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.FORMS.DRAFT_AUTOSAVE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Controlled Form editor
- **Route/API:** POST/GET /guidance/forms/{id}/draft
- **Actor/grants:** Authorized creator/current supervisor with context access
- **Preconditions:** Synthetic draft instance; non-sensitive field sentinel; encryption configured.
**Steps**
1. Save draft version A, then save version B and reopen the draft.
2. Inspect storage count/cipher and try to read as an unauthorized form manager without context authority.
- **Expected:** Exactly one mutable encrypted draft exists and reopens with version B; unauthorized read fails.
- **Negative/non-disclosure:** Plaintext does not appear in storage/audit, and guidance.forms.manage alone does not grant standalone confidential form access.
- **Cleanup:** Delete/reset draft/instance.
- **Source:** app/Services/Guidance/GuidanceFormService.php:178-316; docs/GUIDANCE.md:31-41
- **Evidence:** Autosave/reopen UI, one-row/cipher check, audit event, and unauthorized denial.
- **Verdict:** `NOT_RUN`

## GD-FRM-004 — Finalization freezes an immutable revision and attestation
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.FORMS.FINALIZE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Controlled Form editor
- **Route/API:** POST /guidance/forms/{id}/finalize; GET .../revision/{n}
- **Actor/grants:** Eligible author or current supervisor with required grants
- **Preconditions:** Valid saved draft, active practitioner/context, unique idempotency key, account attestation statement.
**Steps**
1. Finalize the draft and open revision 1 through the audited reader.
2. Attempt to save the old draft, finalize again, or alter finalized content.
- **Expected:** Finalization encrypts an immutable revision, records snapshot hash/attestation, deletes mutable draft, and changes status to finalized.
- **Negative/non-disclosure:** No finalized plaintext can be overwritten; duplicate/stale finalization does not create divergent revisions/attestations.
- **Cleanup:** Delete/reset synthetic form/revision/attestation.
- **Source:** app/Services/Guidance/GuidanceFormService.php:318-547; app/Services/Guidance/FormAttestationService.php:37-154
- **Evidence:** Draft-to-final state, revision/attestation/hash, audited read, and rejected mutation/repeat.
- **Verdict:** `NOT_RUN`

## GD-FRM-005 — Associate form follows pending co-sign state
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.FORMS.PENDING_COSIGN`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-13`
- **Surface:** Controlled Form editor/co-sign queue
- **Route/API:** POST /guidance/forms/{id}/finalize|submit-cosign|cosign
- **Actor/grants:** Associate author and current eligible supervisor
- **Preconditions:** Associate active profile/supervision; valid encrypted draft; cosign queue visible.
**Steps**
1. Attempt associate finalization and submit the draft for co-sign.
2. Open co-sign queue as current supervisor, finalize, and inspect resulting status/revision.
- **Expected:** Associate action transitions draft → pending_cosign without final revision; current supervisor creates the one finalized revision.
- **Negative/non-disclosure:** Associate cannot self-cosign and historical/unrelated supervisors cannot finalize.
- **Cleanup:** Delete/reset form/supervision fixture.
- **Source:** app/Services/Guidance/GuidanceFormService.php:318-481,550-581; app/Services/Guidance/GuidancePractitionerService.php:290-335
- **Evidence:** Pending state/queue, denial, current-supervisor finalization, revision count.
- **Verdict:** `NOT_RUN`

## GD-FRM-006 — Issued controlled document has authorized preview and print boundary
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.FORMS.ISSUE_DOCUMENT`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Controlled document
- **Route/API:** GET /guidance/forms/{id}/preview; POST /guidance/forms/{id}/issue
- **Actor/grants:** Authorized Guidance actor with context read and guidance.documents.print
- **Preconditions:** Finalized synthetic form; printable document compiler; actor without print grant as control.
**Steps**
1. Preview the finalized form and issue/print its controlled document.
2. Try preview/issue as an unauthorized or unrelated actor.
- **Expected:** Authorized output identifies the correct form code/revision/instance and matches immutable data; unauthorized action fails.
- **Negative/non-disclosure:** Document does not include unrelated client records, mutable drafts, server paths, or hidden plaintext beyond the authorized form.
- **Cleanup:** Delete downloaded artifact after sanitized hash/render and reset fixture.
- **Source:** app/Config/Routes.php:342-345; app/Services/Guidance/GuidanceDocumentService.php:1-240
- **Evidence:** Preview, issued file headers/hash/render, immutable value comparison, and denied control actor.
- **Verdict:** `NOT_RUN`

## GD-FRM-007 — Public verification reveals only non-PII authenticity
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.FORMS.PUBLIC_VERIFY`
- **Role:** `public`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-SH-03`
- **Surface:** Document verification
- **Route/API:** GET /guidance/verify/{token}
- **Actor/grants:** Anonymous verifier
- **Preconditions:** Routing decision unresolved because the route is described public but currently nested under protected application group; finalized issued synthetic document/token.
**Steps**
1. Without a session, open the exact verification token and record routing/response.
2. Try malformed, unknown, and another document's altered token.
- **Expected:** After routing and disclosure policy resolution, a valid token reveals only authenticity/status and an approved metadata allowlist; invalid tokens reveal no record details. A free-text void reason must be omitted or replaced by a controlled non-sensitive reason code before the route can be approved as public.
- **Negative/non-disclosure:** No client identity, form answers, free-text void reason, notes, tenant secrets, or existence oracle beyond the approved verification contract is exposed.
- **Cleanup:** Revoke/delete synthetic document/token and clear browser state.
- **Source:** app/Config/Routes.php:113-115,439-440; app/Services/Guidance/GuidanceFormService.php:486-547
- **Evidence:** Anonymous route/status matrix and response-field inspection with token redacted.
- **Verdict:** `NOT_RUN`

## GD-FRM-008 — Form read audit contains metadata but no plaintext
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.FORMS.READ_AUDIT`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Controlled form readers
- **Route/API:** GET draft/final/preview routes
- **Actor/grants:** Authorized creator/current supervisor/read oversight
- **Preconditions:** Synthetic form containing unique sentinel; healthy audit ledger; each authorized read mode available.
**Steps**
1. Read draft, final revision, and preview through supported authorized paths.
2. Inspect all newly appended audit rows and application logs for the sentinel.
- **Expected:** Each confidential read appends a metadata-only audit event before content release.
- **Negative/non-disclosure:** No sentinel/form answer, nonce/tag/key, or submission note appears in audit payload/log; unauthorized reads append no misleading success event.
- **Cleanup:** Delete/reset form/audit fixture in disposable environment.
- **Source:** docs/GUIDANCE.md:38-41; app/Services/Guidance/GuidanceFormService.php:263-316,486-547
- **Evidence:** Read responses, audit rows, and negative sentinel search across audit/log output.
- **Verdict:** `NOT_RUN`

## GD-EPI-001 — Episode opens with explicit matching links and qualified primary counsellor
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EPISODE.OPEN`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-06`
- **Surface:** Guidance episodes
- **Route/API:** POST /guidance/episodes/open
- **Actor/grants:** Guidance actor with cases.create or intake.manage/process
- **Preconditions:** Synthetic person, optional matching intake/case, active qualified primary counsellor; mismatched link controls.
**Steps**
1. Open an episode with explicit person/intake/case/counsellor links and inspect detail.
2. Attempt mismatched subject links, unqualified counsellor, and foreign-tenant record.
- **Expected:** One open episode stores exact matching links and qualified primary counsellor; invalid combinations fail.
- **Negative/non-disclosure:** No unrelated latest case/intake is inferred and role alone does not substitute for practitioner qualification.
- **Cleanup:** Close/delete the synthetic episode and links.
- **Source:** app/Services/Guidance/GuidanceEpisodeService.php:118-215; app/Config/Routes.php:380-385
- **Evidence:** Episode detail/IDs and invalid link/qualification responses.
- **Verdict:** `NOT_RUN`

## GD-EPI-002 — Episode detail exposes only authorized exact-linked records
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.EPISODE.AUTHORIZED_DETAIL`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-06`
- **Surface:** Guidance episode detail
- **Route/API:** GET /guidance/episodes/{id}
- **Actor/grants:** Primary counsellor, consultant/read_any actor, unauthorized counsellor
- **Preconditions:** Synthetic episode with explicit intake/case/sessions/forms/appointments/call slips; unrelated same-person records with sentinels.
**Steps**
1. Open detail as each authorized/unauthorized actor and inspect linked sections.
2. Search response/DOM for unrelated same-person sentinels and inspect the read audit.
- **Expected:** Authorized actors see only exact linked records allowed by section grants/context; unauthorized actor is denied; read is audited before closure content release.
- **Negative/non-disclosure:** No latest-unrelated record, hidden section, anonymous evaluation identity, or closure plaintext enters an unauthorized response.
- **Cleanup:** Delete/reset episode graph and audit fixtures.
- **Source:** app/Services/Guidance/GuidanceEpisodeService.php:298-435; docs/GUIDANCE.md:31-41
- **Evidence:** Actor visibility matrix, exact link IDs, negative sentinel search, and metadata-only audit.
- **Verdict:** `NOT_RUN`

## GD-EPI-003 — Episode closure encrypts notes and blocks later transfer
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EPISODE.CLOSE_TRANSFER`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-06`
- **Surface:** Guidance episode
- **Route/API:** POST /guidance/episodes/{id}/close|transfer
- **Actor/grants:** Assigned primary counsellor or authorized supervisor
- **Preconditions:** Open synthetic episode; valid closure reason and unique closure-note sentinel; second counsellor; encryption configured.
**Steps**
1. Close the episode with reason and notes, then read its authorized detail.
2. Attempt to transfer the closed episode and inspect storage/audit for the note sentinel.
- **Expected:** Episode becomes closed with encrypted notes readable only through authorized audited detail; transfer of closed episode fails.
- **Negative/non-disclosure:** Closure note is absent from audit payload/ordinary metadata and unauthorized users; duplicate closure does not rewrite it.
- **Cleanup:** Delete/reset episode and encrypted notes.
- **Source:** app/Services/Guidance/GuidanceEpisodeService.php:469-633; app/Config/Routes.php:381-385
- **Evidence:** Closed detail, cipher-vs-sentinel check, audit row, and rejected transfer/reclose.
- **Verdict:** `NOT_RUN`

## GD-CALL-001 — Qualified practitioner issues a numbered controlled call slip
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CALL_SLIP.ISSUE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-08`
- **Surface:** Guidance call slips
- **Route/API:** POST /guidance/call-slips/issue; GET /guidance/call-slips/{id}/pdf
- **Actor/grants:** Qualified actor with guidance.call_slips.issue
- **Preconditions:** Synthetic student, valid date/time/purpose, active practitioner profile, optional episode, controlled-form definition.
**Steps**
1. Issue the call slip and open its list/detail/PDF.
2. Attempt issue with unqualified/expired actor, invalid purpose/date, or foreign student.
- **Expected:** A unique CS-YYYYMMDD-XXXX slip links to the student/form/episode as supplied and renders a controlled PDF; invalid attempts fail.
- **Negative/non-disclosure:** No case note/safety data or broad student directory is included, and role alone cannot bypass practitioner eligibility.
- **Cleanup:** Delete downloaded PDF and reset slip/form fixture.
- **Source:** app/Services/Guidance/GuidanceCallSlipService.php:76-229,408-475; app/Config/Routes.php:387-391
- **Evidence:** Issued number/detail, PDF hash/render, linkage, and invalid actor/input responses.
- **Verdict:** `NOT_RUN`

## GD-CALL-002 — Target student can acknowledge only their own call slip
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CALL_SLIP.ACKNOWLEDGE`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-04`
- **Surface:** Portal call slips
- **Route/API:** GET /portal/call-slips; POST /portal/guidance/call-slips/{id}/acknowledge
- **Actor/grants:** Target student; other student control; Guidance issuer
- **Preconditions:** Pending synthetic slip for student A and another for student B.
**Steps**
1. As student A, list and acknowledge A's slip; reload status/timestamp.
2. As A, attempt to acknowledge B's slip; then as issuer mark A's slip attended.
- **Expected:** A can acknowledge only A's pending slip; issuer can record attended and timestamps remain coherent.
- **Negative/non-disclosure:** Student never gains staff status transitions, B's slip content, case access, or arbitrary status selection.
- **Cleanup:** Delete/reset both call slips/forms.
- **Source:** app/Services/Guidance/GuidanceCallSlipService.php:232-301,311-405; app/Config/Routes.php:425-426
- **Evidence:** Portal list/acknowledgement, foreign denial, and issuer attended state/timestamps.
- **Verdict:** `NOT_RUN`

## GD-CLN-001 — Clinical form authoring validates practitioner, permission, and context
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CLINICAL.CREATE_DRAFT`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Guidance clinical forms
- **Route/API:** POST /guidance/clinical/forms/create
- **Actor/grants:** Qualified actor with guidance.clinical.author and case access
- **Preconditions:** Canonical clinical definition; synthetic consistent person/case/session/episode; active scoped practitioner; unqualified/context-mismatch controls.
**Steps**
1. Create a valid clinical draft and reopen it.
2. Attempt creation as unqualified/grantless actor and with mismatched context links.
- **Expected:** Valid form stores one encrypted draft bound to definition revision/context; invalid authority/context attempts fail.
- **Negative/non-disclosure:** No plaintext storage, cross-case linkage, inferred unrelated context, or admin-role clinical bypass occurs.
- **Cleanup:** Delete/reset clinical form and graph fixture.
- **Source:** app/Services/Guidance/GuidanceClinicalFormService.php:164-307; app/Config/Routes.php:393-405
- **Evidence:** Created draft/context, cipher check, and authority/context rejection matrix.
- **Verdict:** `NOT_RUN`

## GD-CLN-002 — Clinical co-sign queue uses current supervision and immutable finalization
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CLINICAL.COSIGN`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-13`
- **Surface:** Clinical co-sign queue
- **Route/API:** GET /guidance/clinical/cosign-queue; POST .../{id}/submit-cosign|cosign
- **Actor/grants:** Associate author and current eligible supervisor with clinical.cosign
- **Preconditions:** Associate-authored draft, active current supervision, former supervisor/unrelated supervisor controls.
**Steps**
1. Submit the form for co-sign and open queue as current and former supervisors.
2. Attempt invalid signers, then finalize as current supervisor and inspect revision/attestation.
- **Expected:** Only current eligible supervisor can co-sign; one immutable encrypted revision and cosigner attestation result.
- **Negative/non-disclosure:** Historical assigned-supervisor metadata, superadmin role, or read_any alone cannot authorize co-sign; no plaintext enters the queue.
- **Cleanup:** Delete/reset form/revision/attestation/supervision.
- **Source:** app/Services/Guidance/GuidanceClinicalFormService.php:309-520; app/Config/Routes.php:394-402
- **Evidence:** Queue visibility, denial matrix, current supervision, final revision/attestation.
- **Verdict:** `NOT_RUN`

## GD-CLN-003 — Consultation, full transfer, and manager reassignment retain distinct authority
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CLINICAL.TRANSFER_MODES`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-12`, `UC-GD-06`
- **Surface:** Guidance case transfer
- **Route/API:** POST /guidance/clinical/cases/{id}/transfer/request; POST .../transfers/{id}/respond; POST .../cases/{id}/reassign
- **Actor/grants:** Current owner, designated receiver, and actor with guidance.cases.reassign
- **Preconditions:** Synthetic owned case; active counsellors B/C; consultation and full-transfer requests; manager; assignment history.
**Steps**
1. Request/accept consultation to B and verify owner remains A with B read-only consultation access.
2. Request/accept full transfer to B, then manager-reassign to C with reason; inspect assignment/transfer history and stale offers.
- **Expected:** Consultation preserves ownership; full transfer changes owner atomically; manager reassignment requires grant/reason, closes prior assignment, and cancels stale offers.
- **Negative/non-disclosure:** Only designated receiver responds; old owner/consultant cannot keep write access; no note plaintext is copied to transfer/audit metadata.
- **Cleanup:** Close/delete case and transfer/assignment fixtures.
- **Source:** app/Services/Guidance/GuidanceTransferService.php:53-355; app/Services/Guidance/GuidanceAccessPolicy.php:159-218
- **Evidence:** Ownership/access at each phase, assignment history, canceled stale offers, and audit metadata.
- **Verdict:** `NOT_RUN`

## GD-OGC-001 — PIF-008, PCF-008, and CCF-009 conform verbatim to official OGC clinical schemas
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `GUIDANCE.OGC.CLINICAL_CONTACT_SCHEMAS`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
- **Surface:** Guidance clinical forms (`F-OGC-GPS-PIF-008-00`, `F-OGC-GPS-PCF-008-00`, `F-OGC-GCS-CCF-009-00`) (uncommitted `feat/archive-management` OGC alignment)
- **Route/API:** GET /guidance/clinical/forms/new/{type}; POST /guidance/clinical/forms/create; GET /guidance/clinical/forms/{id}/pdf
- **Actor/grants:** Qualified `counsellor` with `guidance.clinical.author` and case access
- **Preconditions:** Manifest synced (`docs/guidance/forms/manifest.json`); active open case and episode; PDF compiler enabled (`DocumentHeaderLatex`).
**Steps**
1. Author, save draft, finalize, and export PDFs for `PIF-008`, `PCF-008` (structured contact workspace & 5-P formulation), and `CCF-009`.
2. Inspect web editor fields and generated PDFs for fabricated unapproved psychometric scales (`PHQ-9`, `GAD-7`, synthetic screening risk levels).
- **Expected:** All three forms render only official OGC fields verbatim with the unified Foundation University institutional header (`DocumentHeaderLatex`) and zero fabricated clinical scales.
- **Negative/non-disclosure:** Unapproved PHQ-9/GAD-7/screening risk-level fields are absent from manifest, UI, and PDF; unauthorized/unlinked clinicians fail closed.
- **Cleanup:** Delete/reset disposable clinical form drafts and PDF artifacts.
- **Source:** docs/guidance/forms/manifest.json:1-200; app/Services/Guidance/GuidanceClinicalFormService.php:1-160; app/Services/Reporting/GuidanceFormLatex.php:1-200; app/Services/Reporting/DocumentHeaderLatex.php:1-76
- **Evidence:** Form editor screenshots, rendered PDFs, and `GuidanceClinicalPdfTest` corroboration.
- **Verdict:** `NOT_RUN`

## GD-OGC-002 — General Referral GGR-005 matches all 21 official referral reasons and OGC actions
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `GUIDANCE.OGC.GENERAL_REFERRAL_SCHEMA`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`, `UC-PT-08`
- **Surface:** Portal Referral (`F-OGC-GRE-GGR-005-00`) & Guidance Intake detail/PDF (uncommitted `feat/archive-management` OGC alignment)
- **Route/API:** GET /portal/referrals/create; POST /portal/referrals/store; GET /guidance/intake/{id}; GET /guidance/forms/{id}/preview
- **Actor/grants:** Active `employee` referrer; receiving `counsellor`
- **Preconditions:** Active same-tenant student identifier; clean tenant with synced `GGR-005` manifest revision.
**Steps**
1. Open `/portal/referrals/create` as `employee`, inspect the referral concern/reason checklist, verify all 21 official OGC referral reasons plus remarks/prior actions, and submit for a student.
2. Process receipt/action in `/guidance/intake/{id}` and render controlled `F-OGC-GRE-GGR-005-00` form preview/PDF.
- **Expected:** Both web form and LaTeX PDF preserve the 21 official OGC reasons, referrer remarks, and `OGC Action Taken` verbatim with zero retired 12-reason mismatch.
- **Negative/non-disclosure:** Student accounts remain forbidden from `/portal/referrals/create`; deprecated/fabricated reason keys are rejected cleanly.
- **Cleanup:** Withdraw/delete disposable intake and generated PDF.
- **Source:** app/Services/Guidance/GuidanceIntakeService.php:1-120; app/Views/portal/referrals/create.php:1-100; app/Views/guidance/intake/view.php:1-80; docs/guidance/forms/manifest.json:1-200
- **Evidence:** 21-reason UI capture, intake receipt/action state, and rendered `GGR-005` PDF.
- **Verdict:** `NOT_RUN`

## GD-OGC-003 — Appointment GAP-006 and Call Slip CSP-007 match official schedules, 6 purposes, and layout
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `GUIDANCE.OGC.APPOINTMENT_AND_CALL_SLIP_LAYOUT`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-08`, `UC-GD-11`
- **Surface:** Guidance Appointments (`F-OGC-GRE-GAP-006-00`) & Call Slips (`F-OGC-GSP-CSP-007-00`) (uncommitted `feat/archive-management` OGC alignment)
- **Route/API:** POST /guidance/call-slips/issue; GET /guidance/call-slips/{id}/pdf; GET /guidance/appointments/{id}/pdf
- **Actor/grants:** Qualified `counsellor` with `guidance.call_slips.issue` / `guidance.appointments.manage`
- **Preconditions:** Migration `2026-11-26-000001_AlignGuidanceAppointmentAndCallSlipPurposes` applied; manifest synced; synthetic student.
**Steps**
1. Issue a controlled `CSP-007` call slip selecting `Testing/ Test Interpretation` (`testing_interpretation`) or another official purpose from the 6-purpose set, then render single and 4-up PDFs.
2. Render `GAP-006` appointment PDF verifying `A. Counseling / Psychosocial Support` + `B. Consultation`, the 4 `Preferred Sched` slots (`9:00-10:40AM`, `10:40-11:40AM`, `2:00-2:40PM`, `3:00-3:40PM`), and the official 2-day note.
- **Expected:** Both forms match OGC DOCX layout verbatim with `Note:` and `Guidance Personnel` on `CSP-007`, unified `DocumentHeaderLatex` header, and zero fabricated Copy 1–4 labels, tear-off return slips, or 5-minute reminders.
- **Negative/non-disclosure:** Unlisted legacy purpose codes fail validation and single/4-up LaTeX builds compile cleanly without `mdframed` overflows.
- **Cleanup:** Remove disposable call slip, appointment, and PDFs.
- **Source:** app/Services/Guidance/GuidanceAppointmentService.php:21-72; app/Services/Guidance/GuidanceCallSlipService.php:21-40; app/Views/guidance/call_slips/index.php:1-80; app/Services/Reporting/GuidanceFormLatex.php:1-200
- **Evidence:** `GAP-006` and `CSP-007` rendered PNG/PDF inspections and `GuidanceSlipPdfTest` corroboration.
- **Verdict:** `NOT_RUN`

## GD-OGC-004 — Evaluation forms PEF-008 and CEF-009 match verbatim 1–5 items, visit types, and future-visit reason
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `GUIDANCE.OGC.EVALUATION_FORMS_SCHEMA`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`, `UC-PT-06`
- **Surface:** Evaluation Redemption & Aggregate PDF (`F-OGC-GPS-PEF-008-00`, `F-OGC-GCS-CEF-009-00`) (uncommitted `feat/archive-management` OGC alignment)
- **Route/API:** GET /portal/evaluations/redeem/{token}; POST /portal/evaluations/submit; GET /guidance/evaluations/aggregate-pdf
- **Actor/grants:** Token-holding `student` (`guidance.evaluations.submit`); `counsellor` / `guidance_supervisor` for aggregate PDF
- **Preconditions:** Issued one-time `PEF-008` and `CEF-009` tokens; manifest synced (`revisions=2`); threshold-eligible cohort.
**Steps**
1. Open `/portal/evaluations/redeem/{token}` for both `PEF-008` and `CEF-009`; confirm items 1–5 verbatim text, `visit_type` selector enabled on both forms, `Willingness to Visit Again`, and optional `Reason/s why:` (`future_visit_reason`).
2. Submit responses for both forms and inspect `/guidance/evaluations/dashboard` and `/guidance/evaluations/aggregate-pdf`.
- **Expected:** Both forms collect exact verbatim items 1–5 and optional `Reason/s why:` without fabricated `Service Recommendation Rate`; aggregates and PDF reflect the aligned dimension wording.
- **Negative/non-disclosure:** Fabricated recommendation-rate fields are absent and anonymous/threshold guarantees remain intact.
- **Cleanup:** Reset disposable tokens, evaluations, and PDFs.
- **Source:** app/Controllers/GuidanceEvaluationController.php:21-65; app/Services/Guidance/GuidanceServiceEvaluationService.php:20-76; app/Views/portal/evaluations/submit.php:1-120; docs/guidance/forms/manifest.json:1-200
- **Evidence:** `PEF-008`/`CEF-009` form screenshots, aggregate PDF inspection, and `GuidanceServiceEvaluationPdfTest` corroboration.
- **Verdict:** `NOT_RUN`
