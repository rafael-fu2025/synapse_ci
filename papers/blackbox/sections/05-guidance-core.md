# Guidance core workflows

## GD-001 — Guidance dashboard respects caseload and current-day scope
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-001`
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.DASHBOARD.SCOPED`
- **Surface:** Guidance dashboard
- **Route/API:** GET /guidance
- **Actor/grants:** Guidance actor with queue/case/report grants appropriate to role
- **Preconditions:** Synthetic assigned counsellor and supervisor; current-day queue/session/referral fixtures; another counsellor's restricted case.
**Steps**
1. Open the dashboard as the assigned counsellor and record queue, session, referral, and caseload summaries.
2. Open it as the supervisor and compare only the counters their current grants authorize.
- **Expected:** Counters use the Manila business day and role/caseload discriminator; authorized supervisor scope differs from an owner's scope as configured.
- **Negative/non-disclosure:** The assigned counsellor does not see another owner's case title, note, safety content, or form data in dashboard summaries.
- **Cleanup:** Remove fixtures/reset cache and sign out both actors.
- **Source:** docs/SCHEDULING.md:71-102; app/Controllers/GuidanceController.php:1-140
- **Evidence:** Dashboard screenshots for both actors and fixture-to-count reconciliation with restricted values redacted.
- **Verdict:** `NOT_RUN`

## GD-002 — New counselling case is owned by the assigned counsellor
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-002`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CASE.CREATE`
- **Surface:** Guidance cases
- **Route/API:** GET /guidance/cases/create; POST /guidance/cases/store
- **Actor/grants:** Qualified Guidance actor with guidance.cases.create
- **Preconditions:** Synthetic same-tenant client; active eligible counsellor; unique case title/category; no duplicate open fixture ambiguity.
**Steps**
1. Create a case for the synthetic client with category and intake summary.
2. Open the caseload and case workspace as the assigned counsellor, then attempt the direct URL as another ordinary counsellor.
- **Expected:** A uniquely numbered open case appears for the assigned counsellor; the unrelated counsellor is denied absent consultation/read_any.
- **Negative/non-disclosure:** No broad patient search, other owner's note, cross-tenant person, or client-supplied owner bypass is exposed.
- **Cleanup:** Close/delete the synthetic case in disposable teardown.
- **Source:** app/Config/Routes.php:311-325; app/Services/Guidance/GuidanceCaseService.php:169-220,763-805
- **Evidence:** Create result, case number/workspace, owner identity, and denied second-counsellor request.
- **Verdict:** `NOT_RUN`

## GD-003 — Session draft finalizes to an encrypted immutable note
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-003`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.SESSION.NOTE_LIFECYCLE`
- **Surface:** Guidance case session
- **Route/API:** POST /guidance/cases/{id}/session; POST /guidance/sessions/{id}/notes; GET /guidance/sessions/{id}/read
- **Actor/grants:** Assigned qualified counsellor with guidance.cases.write/counseling.author
- **Preconditions:** Owned synthetic open case; active practitioner standing; non-sensitive sentinel note; encryption key configured; no existing draft for new session.
**Steps**
1. Start a session, save the sentinel as a draft, leave/reopen, then finalize the note.
2. Read the finalized note through the audited reader and attempt to mutate the finalized version.
- **Expected:** One version-0 draft is replaced during autosave; finalization creates an encrypted immutable version, removes the draft, and audited read returns plaintext to the authorized actor.
- **Negative/non-disclosure:** Plaintext is absent from stored cipher/audit metadata, immutable final content cannot be overwritten, and another counsellor receives no plaintext.
- **Cleanup:** Close the session/case and remove synthetic encrypted records through disposable reset.
- **Source:** docs/GUIDANCE.md:38-46,69-90; app/Services/Guidance/GuidanceCaseService.php:681-760
- **Evidence:** Draft reopen, finalized/read UI, database cipher-vs-sentinel check, version history, and audit event.
- **Verdict:** `NOT_RUN`

## GD-004 — Case access distinguishes owner, accepted consultant, and unrelated counsellor
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-004`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CASE.CONSULTATION_ACCESS`
- **Surface:** Guidance case workspace
- **Route/API:** GET /guidance/cases/{id}; transfer consultation routes
- **Actor/grants:** Owner counsellor, accepted consultant, unrelated counsellor
- **Preconditions:** Synthetic case owned by counsellor A; counsellor B has accepted consultation transfer; counsellor C has neither consultation nor read_any.
**Steps**
1. Open the case as A, B, and C and compare case metadata/session access.
2. As B, attempt a case/session/follow-up mutation reserved to the owner or active supervisor.
- **Expected:** A has owner read/write; B receives accepted consultation read-only access; C is denied; B's write is rejected.
- **Negative/non-disclosure:** Consultation is not equivalent to ownership or guidance.cases.read_any and never discloses content to C.
- **Cleanup:** Close consultation fixture/transfer and remove the synthetic case.
- **Source:** app/Services/Guidance/GuidanceAccessPolicy.php:145-218; app/Services/Guidance/GuidanceTransferService.php:53-142
- **Evidence:** Three-actor access matrix, accepted consultation record, and consultant write denial.
- **Verdict:** `NOT_RUN`

## GD-005 — Read-any oversight is audited and does not grant ownership
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-005`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CASE.READ_ANY_AUDIT`
- **Surface:** Guidance case workspace
- **Route/API:** GET /guidance/cases/{id}
- **Actor/grants:** Guidance supervisor/admin with guidance.cases.read_any but not assigned owner
- **Preconditions:** Synthetic case owned by counsellor; oversight actor has fresh read_any grant; audit ledger healthy.
**Steps**
1. Open the case as the oversight actor and inspect the oversight notice.
2. Check the metadata-only audit event, then attempt an owner-only write without the required ownership/supervisory write path.
- **Expected:** Authorized oversight read succeeds and appends an audit event; ordinary read_any alone is not treated as primary ownership for mutations.
- **Negative/non-disclosure:** Audit payload does not include note/closure plaintext and a failed audit append must prevent confidential content release.
- **Cleanup:** Remove synthetic case/audit fixtures via disposable reset.
- **Source:** app/Services/Guidance/GuidanceAccessPolicy.php:145-218; app/Services/Guidance/GuidanceCaseService.php:597-638
- **Evidence:** Oversight notice, audit row with metadata only, and denied write/no-change proof.
- **Verdict:** `NOT_RUN`

## GD-006 — Follow-up completion stores encrypted completion notes
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-006`
- **Changed by:** bea71cf
- **Requirement:** `GUIDANCE.FOLLOWUP.ENCRYPTED_OUTCOME`
- **Surface:** Guidance case follow-ups
- **Route/API:** POST /guidance/followups/{id}/complete|missed
- **Actor/grants:** Assigned owner or active supervisor with guidance.cases.write
- **Preconditions:** Owned open case with two pending follow-ups; non-sensitive completion sentinel; encryption key configured.
**Steps**
1. Attempt completion without required notes, then complete one follow-up with the sentinel; mark the other missed.
2. Reload the authorized case outcome view and inspect storage/audit representations.
- **Expected:** Empty completion is blocked; completed/missed states are recorded and completion notes decrypt only in the authorized outcome reader.
- **Negative/non-disclosure:** Completion plaintext never appears in ordinary case packages or audit payloads, and a resolved follow-up cannot be resolved again.
- **Cleanup:** Remove/reset the synthetic follow-ups and case.
- **Source:** app/Services/Guidance/GuidanceCaseService.php:423-477,532-638
- **Evidence:** Validation error, completed/missed timeline, cipher-vs-plaintext storage check, and authorized outcome read.
- **Verdict:** `NOT_RUN`

## GD-007 — Case closure requires and encrypts a disposition
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-007`
- **Changed by:** bea71cf
- **Requirement:** `GUIDANCE.CASE.ENCRYPTED_CLOSURE`
- **Surface:** Guidance case workspace
- **Route/API:** POST /guidance/cases/{id}/close
- **Actor/grants:** Assigned owner or active supervisor with guidance.cases.write
- **Preconditions:** Owned active synthetic case with closed sessions; non-sensitive closure-disposition sentinel; encryption configured.
**Steps**
1. Attempt closure without confirmation/disposition, then close with the sentinel.
2. Open closed case/outcome view and inspect ordinary package, audit event, and stored columns.
- **Expected:** The invalid request leaves the case active; valid closure makes it closed and decrypts disposition only through the authorized outcome path.
- **Negative/non-disclosure:** Closure plaintext is absent from summary/audit payloads and another counsellor cannot read it without owner/consultation/read_any authorization.
- **Cleanup:** Delete/reset the synthetic closed case and encrypted outcome fields.
- **Source:** app/Services/Guidance/GuidanceCaseService.php:350-477,630-638
- **Evidence:** Invalid and valid closure states, encrypted storage check, authorized read, and unauthorized denial.
- **Verdict:** `NOT_RUN`

## GD-008 — Guidance queue starts sessions only from called tickets
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-008`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.QUEUE.CALLED_TICKET_SESSION`
- **Surface:** Guidance queue and case chooser
- **Route/API:** POST /guidance/queue/call/{id}; POST /guidance/cases/start-for-ticket/{id}
- **Actor/grants:** Guidance queue manager and qualified case actor
- **Preconditions:** Synthetic waiting Guidance ticket for client with an owned open case; no open session for ticket; optional checked-in appointment.
**Steps**
1. Attempt to start a case session while the ticket is waiting.
2. Call the ticket, choose the authorized case, start the session, and inspect queue/case linkage.
- **Expected:** Waiting start fails; a called/in-consultation ticket creates or reuses exactly one linked session and advances the queue consistently.
- **Negative/non-disclosure:** A ticket for another client/case, unrelated counsellor, GET start, or uncalled ticket cannot open a session.
- **Cleanup:** Close the session and complete/resolve the synthetic ticket and appointment.
- **Source:** app/Services/Guidance/GuidanceCaseService.php:169-220; app/Config/Routes.php:324-356
- **Evidence:** Waiting rejection, called card, chooser, session/ticket linkage, and resulting queue state.
- **Verdict:** `NOT_RUN`

## GD-009 — Question bank revision can be previewed and archived
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-009`
- **Changed by:** baseline
- **Requirement:** `GUIDANCE.QUESTION_BANK.LIFECYCLE`
- **Surface:** Guidance question bank
- **Route/API:** POST /guidance/question-bank/store|{id}/update|{id}/archive
- **Actor/grants:** Guidance manager with applicable survey/question-bank authority
- **Preconditions:** Unique synthetic question, audience/type/category values; no published survey depends on cleanup version.
**Steps**
1. Create the question, update it to a new revision, and open preview.
2. Archive it and inspect active catalog/search results.
- **Expected:** Revision metadata increments, preview reflects the selected immutable revision, and archived item leaves the active set.
- **Negative/non-disclosure:** Archive does not rewrite survey copies or disclose responses; unauthorized actors cannot author.
- **Cleanup:** Remove/reset synthetic bank records after archival evidence.
- **Source:** app/Config/Routes.php:226-235; docs/QUESTION_BANK.md:1-92
- **Evidence:** Create/edit/preview/archive screenshots and revision/catalog state.
- **Verdict:** `NOT_RUN`

## GD-010 — Survey copies bank revisions and publishes to its audience
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-010`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.SURVEY.PUBLISH`
- **Surface:** Guidance survey builder
- **Route/API:** POST /guidance/surveys/store|{id}/publish
- **Actor/grants:** Guidance actor with guidance.surveys.manage
- **Preconditions:** Active synthetic bank question; valid survey window/audience; target and non-target synthetic portal actors.
**Steps**
1. Create a survey by copying the bank revision, review the draft, and publish it.
2. Open portal survey lists as target and non-target actors, then archive/update the bank source separately.
- **Expected:** Published survey is available only to the target audience and retains its copied bank ID/revision independently of later source changes.
- **Negative/non-disclosure:** Draft/closed surveys and confidential response data are not exposed; publishing does not grant broad Guidance record access.
- **Cleanup:** Close/delete the survey, question copy, and responses.
- **Source:** app/Config/Routes.php:236-249; app/Services/Guidance/SurveyService.php:1-420
- **Evidence:** Builder/publish states, copied revision metadata, and two-audience portal comparison.
- **Verdict:** `NOT_RUN`

## GD-011 — Survey response review and export remain bounded
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-011`
- **Changed by:** baseline
- **Requirement:** `GUIDANCE.SURVEY.RESPONSE_REVIEW`
- **Surface:** Guidance survey responses
- **Route/API:** GET /guidance/surveys/{id}/responses/{responseId}; POST .../export
- **Actor/grants:** Guidance actor with guidance.responses.read
- **Preconditions:** Published identified synthetic survey with several responses including formula-leading sentinel text; actor has response-read grant.
**Steps**
1. Open the response list/detail and verify pagination/ownership fields.
2. Export the bounded response set and inspect headers, row count, and formula-leading cell handling.
- **Expected:** Authorized detail displays the selected response and export contains bounded expected rows/columns with safe cell encoding.
- **Negative/non-disclosure:** Actors without response-read see no answers; export does not include unrelated surveys, secrets, or unbounded tenant data.
- **Cleanup:** Securely delete the downloaded export and reset synthetic responses/survey.
- **Source:** app/Config/Routes.php:245-249; app/Services/Guidance/SurveyService.php:1-420
- **Evidence:** Response list/detail, export hash/content sample with synthetic values, row-count reconciliation, and denied actor result.
- **Verdict:** `NOT_RUN`

## GD-012 — Guidance announcement publishing reaches only its audience
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-012`
- **Changed by:** baseline
- **Requirement:** `GUIDANCE.ANNOUNCEMENT.PUBLISH`
- **Surface:** Guidance announcements
- **Route/API:** POST /guidance/announcements/store|{id}/publish
- **Actor/grants:** Guidance admin/supervisor with guidance.announcements.manage
- **Preconditions:** Synthetic draft announcement; student audience actor and non-target actor; publication window current.
**Steps**
1. Create and publish the announcement, then open target and non-target portal feeds.
2. Mark it read as the target and inspect publisher read history/state if available.
- **Expected:** Published content appears only for the configured audience and read state is recorded per recipient.
- **Negative/non-disclosure:** Draft/expired/archived content and recipient-private read state are not exposed to non-target users.
- **Cleanup:** Archive/delete the announcement and receipts.
- **Source:** app/Config/Routes.php:208-224; app/Database/Seeds/PermissionsAndRolesSeeder.php:69-73
- **Evidence:** Publisher lifecycle, two audience feeds, and target read-state evidence.
- **Verdict:** `NOT_RUN`
