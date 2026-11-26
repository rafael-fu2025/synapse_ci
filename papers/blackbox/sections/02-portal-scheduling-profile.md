# Personal portal, scheduling, and profile

## PT-001 — Personal portal home presents owned summaries
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-001`
- **Changed by:** 884b9f0
- **Requirement:** `PORTAL.HOME.OWNED_SUMMARY`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-01`, `UC-PT-02`
- **Surface:** Personal portal
- **Route/API:** GET /portal
- **Actor/grants:** Student or employee with portal records and appointments read grants
- **Preconditions:** Synthetic person with one own appointment, one queue ticket, one announcement, and one available survey; another person's records also exist.
**Steps**
1. Sign in as the synthetic person and open /portal.
2. Open each summary link and return to the portal using normal browser navigation.
- **Expected:** Owned appointment, queue, announcement, survey, profile, and emergency-contact summaries render and navigation remains usable.
- **Negative/non-disclosure:** No other person's identifiers or confidential clinical/counselling text appears in cards or links.
- **Cleanup:** Remove the synthetic announcement/survey/ticket/appointment fixtures or reset the disposable database.
- **Source:** app/Controllers/PortalController.php:24-125; app/Views/portal/index.php:1-240
- **Evidence:** Desktop and 360px portal screenshots plus owned-record identifiers and sanitized request trace.
- **Verdict:** `NOT_RUN`

## PT-002 — Personal appointment submission creates a request, not a reservation
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-002`
- **Changed by:** 73a2e4d
- **Requirement:** `PORTAL.APPOINTMENT.REQUEST`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-01`
- **Surface:** Personal appointment form
- **Route/API:** GET/POST /portal/appointments/{clinic|guidance}/create|store
- **Actor/grants:** Student/employee with portal.appointments.create
- **Preconditions:** Synthetic person; future Manila date; service unit accepting requests; preferred 30-minute interval; no matching active request for cleanup ambiguity.
**Steps**
1. Submit a preferred date/time interval and reason through the personal appointment form.
2. Open My Appointments and the receiving staff appointment list before any staff confirmation.
- **Expected:** A status=requested item appears for the requester and staff, with no provider assignment and messaging that staff will confirm the time.
- **Negative/non-disclosure:** The preference is not called booked/confirmed, does not reserve provider capacity, and does not disclose other requesters or provider private schedules.
- **Cleanup:** Cancel the synthetic request through the personal cancellation workflow.
- **Source:** app/Services/Scheduling/AppointmentService.php:114-166; app/Controllers/AppointmentController.php:113-139
- **Evidence:** Submitted form, requested-status detail, and staff-list receipt showing no assignment/reservation.
- **Verdict:** `NOT_RUN`

## PT-003 — Staff confirmation assigns a provider and detects stale or occupied slots
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-003`
- **Changed by:** 73a2e4d
- **Requirement:** `PORTAL.APPOINTMENT.CONFIRM`
- **Role:** `workstation_member`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-08`, `UC-GD-11`
- **Surface:** Staff appointment confirmation
- **Route/API:** GET/POST /{unit}/appointments/{id}/confirm
- **Actor/grants:** Clinic/Guidance scheduler with unit appointment write/manage grant
- **Preconditions:** Two synthetic requested appointments; active department_staff assignment with availability; one future free slot; two browser sessions holding the same request version.
**Steps**
1. Confirm the first request with the eligible staff assignment and available interval; observe its status and version.
2. From the stale second browser or a competing request, attempt confirmation using the old version or now-occupied interval.
- **Expected:** The first request becomes confirmed with provider/confirmation metadata; the competing stale or conflicting confirmation is rejected without double-booking.
- **Negative/non-disclosure:** A requested preference alone never blocks the slot; foreign-unit/inactive assignments and client-supplied provider_user_id are not accepted.
- **Cleanup:** Cancel confirmed synthetic appointments and remove temporary availability fixtures.
- **Source:** app/Services/Scheduling/AppointmentService.php:431-461; app/Config/Routes.php:532-548
- **Evidence:** Before/after request detail, provider assignment, version/conflict response, and availability proof.
- **Verdict:** `NOT_RUN`

## PT-004 — Requester cancels an eligible own appointment with a reason
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-004`
- **Changed by:** 73a2e4d
- **Requirement:** `PORTAL.APPOINTMENT.CANCEL_OWN`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-01`
- **Surface:** My Appointments
- **Route/API:** POST /portal/appointments/{unit}/cancel/{id}
- **Actor/grants:** Owning student/employee with portal appointment grants
- **Preconditions:** Synthetic own requested and confirmed future appointments plus another person's appointment; cancellation reason prepared.
**Steps**
1. Cancel the own requested or confirmed appointment and confirm the resulting history/detail state.
2. Craft the same route with another person's appointment ID and then attempt to cancel an already terminal appointment.
- **Expected:** The own eligible appointment becomes cancelled, records the reason/version change, and releases confirmed capacity; invalid ownership/state attempts fail.
- **Negative/non-disclosure:** No other person's appointment details are returned, and cancellation does not erase history or masquerade as a completed visit.
- **Cleanup:** Delete/reset synthetic appointment fixtures after recording the terminal state.
- **Source:** app/Services/Scheduling/AppointmentService.php:495-537; app/Config/Routes.php:526-548
- **Evidence:** Cancellation dialog/result, history status, released-slot evidence, and denied ID-tampering response.
- **Verdict:** `NOT_RUN`

## PT-005 — Personal queue card reports current position
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-005`
- **Changed by:** baseline
- **Requirement:** `PORTAL.QUEUE.POSITION`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-02`
- **Surface:** Personal queue
- **Route/API:** GET /portal/queue
- **Actor/grants:** Owning person with personal portal access
- **Preconditions:** Synthetic person has an active clinic or Guidance ticket; at least one ordered ticket may be ahead; staff board is open separately.
**Steps**
1. Open the personal queue view and record ticket code, position, people ahead, and wait wording.
2. Have authorized staff call/advance a ticket, then wait for the personal view's authorized refresh or reload it.
- **Expected:** Only the caller's active ticket is shown and its position/progress reconciles to the current queue state.
- **Negative/non-disclosure:** The portal does not expose other patients' names, reasons, clinical data, or staff-only queue actions.
- **Cleanup:** Complete/no-show the synthetic ticket and remove any companion tickets.
- **Source:** app/Config/Routes.php:165-169; app/Services/PortalQueueService.php:1-115
- **Evidence:** Personal card before/after and matching staff-board state with other identities redacted.
- **Verdict:** `NOT_RUN`

## PT-006 — Visit history is nonclinical attendance metadata
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-006`
- **Changed by:** 73a2e4d
- **Requirement:** `PORTAL.VISITS.NONCLINICAL`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-02`
- **Surface:** My Visit History
- **Route/API:** GET /portal/visits
- **Actor/grants:** Student/employee with portal.records.read
- **Preconditions:** Synthetic person has completed clinic appointment, clinic walk-in, Guidance appointment, and Guidance walk-in; seeded notes contain recognizable secret sentinels.
**Steps**
1. Open /portal/visits, filter All, Clinic, and Guidance, and inspect pagination/attendance labels.
2. Search rendered HTML and network payloads for the seeded complaint, diagnosis, case title, note, risk, and ciphertext sentinels.
- **Expected:** The history shows completed visit date, service, attending staff, appointment/walk-in, and generic completion summary only.
- **Negative/non-disclosure:** Requests, cancellations, in-progress visits, clinical chart details, counseling notes, case numbers, risk data, and ciphertext are absent.
- **Cleanup:** Delete the synthetic encounters/sessions and sentinel data or reset the disposable database.
- **Source:** app/Services/PortalVisitHistoryService.php:13-130; app/Views/portal/visit_history.php:13-56
- **Evidence:** Filter screenshots, pagination totals, and negative sentinel search across DOM/network response.
- **Verdict:** `NOT_RUN`

## PT-007 — Profile photo upload and removal affect only the caller
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-007`
- **Changed by:** baseline
- **Requirement:** `PORTAL.PROFILE.PHOTO_LIFECYCLE`
- **Role:** `all_authenticated`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-SH-05`
- **Surface:** Profile settings
- **Route/API:** GET/POST /portal/settings/photo; POST /portal/settings/photo/remove
- **Actor/grants:** Linked student/employee person
- **Preconditions:** Synthetic account; valid small JPEG/PNG/WebP with no personal image content; second synthetic account for isolation check.
**Steps**
1. Upload the synthetic image, reload settings and the account avatar, then sign in as the second account and inspect its avatar.
2. Return as the owner, remove the image, and reload the avatar endpoint.
- **Expected:** The owner's processed image persists then returns to the generated avatar after removal; the second account never changes.
- **Negative/non-disclosure:** The original file path, EXIF/private metadata, another user's photo, and unsupported content are not served.
- **Cleanup:** Remove uploaded media and clear the temporary local image.
- **Source:** app/Controllers/ProfileSettingsController.php:24-101; app/Views/portal/settings.php:7-34
- **Evidence:** Owner before/upload/remove screenshots, second-account isolation screenshot, and sanitized content-type/status evidence.
- **Verdict:** `NOT_RUN`

## PT-008 — Emergency contact update persists for the caller
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-008`
- **Changed by:** baseline
- **Requirement:** `PORTAL.PROFILE.EMERGENCY_CONTACT`
- **Role:** `all_authenticated`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-SH-05`
- **Surface:** Profile settings
- **Route/API:** POST /portal/settings/emergency-contact
- **Actor/grants:** Linked student/employee person
- **Preconditions:** Synthetic person with reversible emergency-contact values; valid CSRF token; another person's profile exists.
**Steps**
1. Enter synthetic contact name, relationship, and phone and save.
2. Reload settings, then attempt an ID parameter injection or inspect another account separately.
- **Expected:** The three fields persist only on the signed-in person's health profile and a success state is shown.
- **Negative/non-disclosure:** The route ignores client-selected person identity, exposes no other contact, and does not alter MIS-owned identity fields.
- **Cleanup:** Restore the original synthetic contact values through the same form.
- **Source:** app/Controllers/ProfileSettingsController.php:103-121,179-213; app/Views/portal/settings.php:47-94
- **Evidence:** Saved/reloaded field screenshots and database/API state proof scoped to the synthetic person.
- **Verdict:** `NOT_RUN`

## PT-009 — Announcement read tracking is per account
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-009`
- **Changed by:** baseline
- **Requirement:** `PORTAL.ANNOUNCEMENT.READ`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-03`
- **Surface:** Portal announcements
- **Route/API:** GET /portal/announcements/{id}; POST /portal/announcements/{id}/read
- **Actor/grants:** Targeted student or employee
- **Preconditions:** Published synthetic announcement targeted to the actor; second eligible actor remains unread.
**Steps**
1. Open the announcement as the first actor and mark it read.
2. Return to the list and compare unread state using the second actor.
- **Expected:** The first actor's item/count changes to read while the second actor's state remains unread.
- **Negative/non-disclosure:** Draft/archived/out-of-audience content and another user's read receipt are not disclosed.
- **Cleanup:** Archive/delete the synthetic announcement and associated receipts in disposable teardown.
- **Source:** app/Config/Routes.php:208-214; app/Views/announcements/portal.php:1-60
- **Evidence:** Announcement detail/list counters for both synthetic accounts and read mutation response.
- **Verdict:** `NOT_RUN`

## PT-010 — Published survey accepts one owned response
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-010`
- **Changed by:** `6deffdf`
- **Requirement:** `PORTAL.SURVEY.SUBMIT`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-03`
- **Surface:** Portal surveys (including linear scale rating items)
- **Route/API:** GET /portal/surveys/{id}; POST /portal/surveys/{id}/submit
- **Actor/grants:** In-audience student/employee
- **Preconditions:** Published synthetic identified survey with text, choice, and linear-scale questions; actor has not responded; out-of-audience account available.
**Steps**
1. Open the survey, answer every required question including linear-scale rating bounds, and submit.
2. Reload the survey list, attempt a duplicate or out-of-bounds scale submission, and check the out-of-audience account.
- **Expected:** One response is recorded and the survey moves from available to submitted for the actor; linear-scale min/max/step constraints enforce valid selection.
- **Negative/non-disclosure:** Duplicate/out-of-audience submissions are rejected and other respondents' answers are never shown.
- **Cleanup:** Close and remove the synthetic survey/response in disposable teardown.
- **Source:** app/Config/Routes.php:218-220; app/Controllers/PortalSurveyController.php:1-60
- **Evidence:** Completed form with linear scale, submission acknowledgement, list-state change, and duplicate/audience denial.
- **Verdict:** `NOT_RUN`

## PT-011 — Notification inbox read state is account-owned
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-011`
- **Changed by:** baseline
- **Requirement:** `PORTAL.NOTIFICATION.READ`
- **Role:** `all_authenticated`
- **Module:** Realtime & Notifications
- **Use Cases:** `UC-SH-06`
- **Surface:** Notification inbox
- **Route/API:** GET /notifications; POST /notifications/{id}/read; POST /notifications/read-all
- **Actor/grants:** Authenticated notification recipient
- **Preconditions:** Synthetic account with at least two personal notifications and a second account with its own item.
**Steps**
1. Open the first account's inbox, mark one item read, and observe the unread count.
2. Use Mark all read, then craft a read request for the second account's notification ID.
- **Expected:** The first account's unread count reaches zero; the foreign notification remains inaccessible and unchanged.
- **Negative/non-disclosure:** Notification previews do not reveal confidential clinical/chat content or null-recipient/other-account items.
- **Cleanup:** Delete/reset synthetic notification rows after evidence capture.
- **Source:** app/Config/Routes.php:153-160; docs/TEAMS.md:98-123
- **Evidence:** Inbox/count screenshots, individual/read-all responses, and foreign-ID denial proof.
- **Verdict:** `NOT_RUN`

## PT-012 — Personal portal identifiers cannot select another person's records
- **Lifecycle:** `READY`
- **Legacy IDs:** `PT-012`
- **Changed by:** baseline
- **Requirement:** `PORTAL.RECORDS.OWNERSHIP`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-01`
- **Surface:** Portal routes
- **Route/API:** GET /portal, /portal/appointments/{id}, /portal/visits, /portal/settings
- **Actor/grants:** Student/employee with self-service grants
- **Preconditions:** Two same-tenant synthetic people each have distinct appointments, visits, profile data, and notifications.
**Steps**
1. As person A, open all own personal-portal resources and note the URL shapes.
2. Replace path/query/form identifiers with person B's known synthetic IDs and submit direct requests.
- **Expected:** Server-derived identity keeps every resource scoped to person A; foreign records return not found/forbidden or are ignored without leakage.
- **Negative/non-disclosure:** No existence oracle, B's name/contact/visit metadata, or staff route access is exposed.
- **Cleanup:** Clear sessions and reset both synthetic fixture sets.
- **Source:** app/Controllers/PortalController.php:135-164; app/Services/PortalVisitHistoryService.php:22-46
- **Evidence:** Own-resource controls, tampered requests/statuses, and negative search for person B sentinels.
- **Verdict:** `NOT_RUN`

## PT-SCH-001 — Scheduling uses staff assignments and Manila wall time
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** 73a2e4d, b868a49
- **Requirement:** `PORTAL.SCHEDULING.MANILA_ASSIGNMENT`
- **Role:** `workstation_member`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-08`, `UC-GD-11`
- **Surface:** Appointment request/confirmation
- **Route/API:** GET /api/appointments/slots; POST /{unit}/appointments/{id}/confirm
- **Actor/grants:** Requester plus unit scheduler
- **Preconditions:** Decision required for staff_assignment_id contract versus stale provider_user_id expectations; synthetic active department_staff record; boundary-time fixture in Asia/Manila.
**Steps**
1. At a controlled Manila boundary, request slots using the staff_assignment_id and submit a preferred 30-minute interval.
2. Confirm the request across a UTC/Manila date boundary and inspect stored/displayed local_date and assignment.
- **Expected:** Once the contract decision is approved, slot, past-time, and local-date behavior consistently use Manila wall time and the active staff assignment.
- **Negative/non-disclosure:** No client-supplied provider_user_id, timezone-suffixed local input, inactive assignment, or cross-unit assignment is accepted.
- **Cleanup:** Cancel the request and remove temporary assignment/availability data.
- **Source:** docs/SCHEDULING.md:1-19,52-69; app/Services/Scheduling/AppointmentService.php:114-166,431-461
- **Evidence:** Controlled clock, slot payload, confirmation detail, and stored/displayed date/assignment proof.
- **Verdict:** `NOT_RUN`

## PT-PRF-001 — Current contact and residence update without changing MIS identity
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `PORTAL.PROFILE.CONTACT_INFO`
- **Role:** `all_authenticated`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-SH-05`
- **Surface:** Profile settings
- **Route/API:** POST /portal/settings/contact-info
- **Actor/grants:** Linked student/employee person
- **Preconditions:** Synthetic person with original phone/address captured; official MIS name/ID/program fields populated.
**Steps**
1. Update current phone and residence with synthetic values and save.
2. Reload settings and compare both editable and MIS-owned profile fields.
- **Expected:** Phone/address persist for the caller while official name, ID, program, and enrollment fields remain unchanged.
- **Negative/non-disclosure:** No other person is selectable and no official MIS field becomes editable through the request.
- **Cleanup:** Restore the original phone/address.
- **Source:** app/Config/Routes.php:175-180; app/Views/portal/settings.php:98-123
- **Evidence:** Before/after settings screenshots and scoped state comparison.
- **Verdict:** `NOT_RUN`

## PT-PRF-002 — Profile photo validation preserves the prior image on failure
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `PORTAL.PROFILE.PHOTO_VALIDATION`
- **Role:** `all_authenticated`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-SH-05`
- **Surface:** Profile settings
- **Route/API:** POST /portal/settings/photo
- **Actor/grants:** Linked student/employee person
- **Preconditions:** Owner already has a valid synthetic photo; invalid oversized or unsupported test file prepared outside uploads.
**Steps**
1. Record the current avatar, then submit the invalid file.
2. Reload settings and the account photo endpoint.
- **Expected:** The upload is rejected with a safe message and the previous image remains byte-for-byte/currently visible.
- **Negative/non-disclosure:** No temporary file path, decoder error, server stack, or invalid bytes are published.
- **Cleanup:** Delete the local invalid file and retain/remove the owner photo according to the parent fixture cleanup.
- **Source:** app/Controllers/ProfileSettingsController.php:57-71; app/Services/ProfilePhotoService.php:1-197
- **Evidence:** Validation error, unchanged avatar, and sanitized response/content-type evidence.
- **Verdict:** `NOT_RUN`

## PT-PRF-003 — Official university profile fields remain read-only
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** 884b9f0
- **Requirement:** `PORTAL.PROFILE.MIS_READ_ONLY`
- **Role:** `all_authenticated`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-SH-05`
- **Surface:** Profile settings
- **Route/API:** GET /portal/settings
- **Actor/grants:** Linked student/employee person
- **Preconditions:** Synthetic MIS-backed person with known official name, ID, program/department, and status.
**Steps**
1. Open profile settings and inspect controls for official identity fields.
2. Submit extra form fields attempting to change official name/MIS ID/program alongside a valid contact update.
- **Expected:** Official fields render as read-only context and remain unchanged; only supported self-service contact fields change.
- **Negative/non-disclosure:** The page offers no client-controlled identity link or hidden mass-assignment path.
- **Cleanup:** Restore the contact field used for the control mutation.
- **Source:** app/Views/portal/settings.php:35-45,98-123; app/Controllers/ProfileSettingsController.php:103-121
- **Evidence:** DOM/control inspection and before/after authoritative profile comparison.
- **Verdict:** `NOT_RUN`

## PT-PRF-004 — No password-management UI is exposed
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `PORTAL.PROFILE.NO_PASSWORD_UI`
- **Role:** `all_authenticated`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-SH-05`
- **Surface:** Profile settings and navigation
- **Route/API:** N/A
- **Actor/grants:** Any student/employee
- **Preconditions:** Synthetic signed-in account; route list and profile UI available; university credential ownership documented for the run.
**Steps**
1. Inspect profile settings, account menus, and personal navigation for password create/change/reset controls.
2. Request plausible password-management URLs with safe GETs and observe routing without submitting credentials.
- **Expected:** No Synapse password-management control or supported portal route exists; university credentials remain MIS-owned and local break-glass passwords are operator-managed.
- **Negative/non-disclosure:** Do not infer that external MIS password changes instantly revoke Synapse sessions; no password/hash/version value is displayed.
- **Cleanup:** No state change; clear the synthetic session.
- **Source:** app/Config/Routes.php:7-14,165-180; docs/SESSION_SECURITY.md:71-92
- **Evidence:** UI/DOM search, route-status observations, and evidence that no credential was entered into unsupported URLs.
- **Verdict:** `NOT_RUN`
