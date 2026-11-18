# Guidance records, forms, episodes, call slips, and clinical workflow

## GD-REC-001 — Clients directory lists existing same-tenant people only
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.RECORDS.DIRECTORY`
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
