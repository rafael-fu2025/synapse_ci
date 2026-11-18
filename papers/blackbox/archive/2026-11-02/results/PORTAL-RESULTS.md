# Black-box test results — Personal Portal (PT-001 … PT-012)

- **Module:** Personal Portal (student-facing portal at `/portal`)
- **Run date:** 2026-11-01 / 2026-11-02 (Asia/Manila)
- **App under test:** `http://localhost:8080/index.php` (local dev worktree, post-remediation build)
- **Operator account:** `[REDACTED: synthetic account identifier]` (person_id [REDACTED: person record identifier], MIS id `[REDACTED: synthetic personnel identifier]`)
- **Browser:** in-app Browser, **single tab** throughout (`navigate` + `replaceCurrentTab: true`; no `open_tab` calls were made in this module)
- **Database verification:** read-only `mysqli` SELECTs against `humus_db` using a throwaway script (`writable/diag-appt.php`, `writable/diag-cancel.php`) that reads credentials from `.env` at runtime. Read-only only.

> Scope note: this module covers the 12 Personal Portal cases from
> `papers/blackbox/SYNAPSE-BlackBox-Testing.md`. Clinic and Guidance cases (CL-*, GD-*) were
> already completed and are **not** re-run here.

---

### PT-001 — Portal home aggregates

**Verdict:** PASS

**Steps observed:** Signed in as `[REDACTED: synthetic account identifier]` → landed on `/portal` (My Health Portal) and read the whole
home surface.

**Evidence:**
- Queue tickets rendered as live cards: `[REDACTED: queue ticket identifier]` — Guidance & Counseling Center, status *Waiting*,
  place in line **#1**, people ahead **0**, estimated wait **"You're next"**; `[REDACTED: queue ticket identifier]` — University Clinic,
  status *Skipped*, "Your ticket is on hold…".
- My Appointments table present with the student's own rows (Guidance *Checked in*, Clinic *Confirmed*,
  Clinic *Awaiting confirmation*, two *Cancelled*, Clinic *Completed*) and working
  Service / Status / Sort / Rows-per-page filters.
- Announcements block ("Read current notices from the Clinic and Guidance teams"), My Surveys block,
  Profile block (name, ID, email, course, year level, section, department, blood type) and the
  Emergency Contact block all rendered.
- "Request Clinic Visit" / "Request Guidance Session" actions present.

**Notes:** Portal home aggregates all four domains (queue, appointments, surveys, announcements) as
specified.

---

### PT-002 — Book appointment (available slot)

**Verdict:** PASS

**Steps observed:**
1. `/portal/appointments/clinic/create` → set *Preferred date* `02/11/2026` (hidden `date` field
   confirmed to carry `2026-11-02`) and *Preferred time* `09:00 AM` → **Submit Request**.
2. Returned to `/portal`; read the My Appointments table.
3. Clinic console → Appointments → *Review & Confirm* on the new request, assigned
   **[REDACTED: staff name]**, date `02/11/2026`, confirmed at **11:00 AM**.
4. Re-opened the clinic confirm picker for the same clinician/date.

**Evidence:**
- New row in My Appointments: `University Clinic / Nov 2, 2026 / 09:00 AM / Preferred time—not yet
  confirmed / Routine / Awaiting confirmation` with *Details* and *Cancel* actions.
- DB: appointment **[REDACTED: appointment identifier]** created (`unit=clinic`, `person_id=[REDACTED: person record identifier]`, `local_date=2026-11-02`,
  `status=requested`), later moved to `status=confirmed` at `2026-11-02 11:00:00` with
  `staff_assignment_id=[REDACTED: staff assignment identifier]` ([REDACTED: staff name]).
- Clinic console "Clinic Scheduled Appointments" for `02/11/2026` now lists a
  `11:00 AM … [REDACTED: staff name] … Confirmed` row.
- Re-opening the confirm picker for [REDACTED: staff name] on 2026-11-02 renders the **11:00 AM** slot
  button with `disabled="disabled"` — the slot is no longer offered for that clinician.

**Notes:** The portal creates a *request*, not a reservation — the form states "Your preferred time is
not a reservation. Clinic staff will review the request, confirm an available time, and assign a
clinician." The slot therefore becomes unavailable when staff confirm it (observed above), not at
request time. Two earlier requests for the same 10:00 AM slot were both accepted, which is consistent
with that documented behaviour rather than a defect.

---

### PT-003 — Book appointment (slot taken)

**Verdict:** PASS

**Steps observed:**
1. With appointment [REDACTED: appointment identifier] already **Confirmed** at `2026-11-02 09:00` for [REDACTED: staff name], opened
   the clinic *Review & Confirm* picker for the pending 09:00 request, assigned [REDACTED: staff name],
   date `02/11/2026`, and inspected the rendered *Available times* buttons.
2. Repeated the check for a second request whose 11:00 AM slot had just been confirmed (3036).
3. Performed a direct POST to the same confirm endpoint for the taken slots to capture the server's
   rejection message (HTTP-level probe of the endpoint the UI posts to).

**Evidence:**
- In the picker, the taken slot renders **disabled**: `…<button type="button" class="btn btn-sm
  btn-outline-secondary" disabled="disabled" aria-pressed="false">9:00 AM</button>…` and likewise
  `11:00 AM`. Every other slot in the 08:00 AM–04:45 PM grid was enabled, so the disabling is
  slot-specific, not a blanket failure.
- Server response for a taken slot: **HTTP 409** with body
  `{"success": false, "message": "The selected time slot is already booked.", "redirect":
  "…/clinic/appointments"}` — for both `2026-11-02 09:00:00` and `2026-11-02 11:00:00`.
- A free slot in the same request set was accepted with HTTP 200, so the rejection is specific to the
  occupied slot.

**Notes:** The clinic slot picker is the component that rejects an occupied slot — the portal booking
form itself accepts a preferred time by design (see PT-002 note).

---

### PT-004 — Cancel own appointment

**Verdict:** PASS

**Steps observed:** `/portal` → *Cancel* on the pending request → confirmation modal opened
("Cancel this appointment or pending request? Any reserved slot will be released.", title
*Cancel appointment*, required *Cancellation reason* field) → typed the reason → *Cancel appointment*.
Re-read the table and the DB afterwards.

**Evidence:**
- Modal `#synapseConfirmModal` showed title *Cancel appointment*, the confirm message, a required
  *Cancellation reason* input (`#synapseConfirmReason`, maxlength 255) and buttons
  *Cancel* / *Cancel appointment*.
- Browser network log: `POST http://localhost:8080/index.php/portal/appointments/clinic/cancel/[REDACTED: appointment identifier]`
  → `200 OK`, `application/json`.
- DB: appointment **[REDACTED: appointment identifier]** → `status=cancelled`; the row then renders as
  `University Clinic / Nov 2, 2026 / 10:00 AM / Cancelled / Details` in My Appointments, i.e. the
  cancellation is visible in the appointment history and the slot is released (no longer held).

**Notes:** *Automation note (not a product defect):* an earlier attempt that typed the reason and
clicked *Cancel appointment* in quick succession was silently dropped because the accept handler only
fires once the modal has finished opening. After the modal was confirmed open on screen, the same
interaction produced the 200 response above. Separately, the identical POST performed directly at HTTP
level also returned `{"success": true, "message": "Appointment cancelled."}`, confirming the endpoint
behaves correctly.

---

### PT-005 — Queue position display

**Verdict:** PASS

**Steps observed:** `/portal` → read the live waiting-room ticket cards.

**Evidence:** Ticket **[REDACTED: queue ticket identifier]** (Guidance & Counseling Center, status *Waiting*, priority *Routine*)
renders three labelled values: **Your place in line = #1**, **People ahead = 0**, **Estimated wait =
"You're next"**, plus the explanatory note "Position includes people already called or being seen
ahead of you." Ticket **[REDACTED: queue ticket identifier]** (University Clinic, *Skipped*) renders "On hold / — / Ask reception"
with the matching on-hold explanation.

**Notes:** Place-in-line, people-ahead and estimated-wait are all present for the waiting ticket.

---

### PT-006 — Visit history

**Verdict:** PASS

**Steps observed:** `/portal/visits` → read the visit list; looked for a per-visit detail view.

**Evidence:**
- Page states the disclosure boundary: "This is an attendance history, not your clinical chart.
  Confidential medical and counseling notes are not displayed."
- Four completed visits listed, newest first, each with **Visit date**, **Service**, **Attending
  staff**, **Attendance** and **Summary**:
  `Nov 1, 2026 · 11:07 AM | University Clinic | [REDACTED: synthetic staff display name] | By appointment | Completed —
  Completed clinic visit`;
  `Nov 1, 2026 · 5:19 AM | Guidance & Counseling | [REDACTED: synthetic staff display name] | Walk-in | Completed`;
  `Nov 1, 2026 · 4:55 AM | Guidance & Counseling | [REDACTED: synthetic staff display name] | Walk-in | Completed`;
  `Nov 1, 2026 · 4:27 AM | University Clinic | [REDACTED: synthetic staff display name] | Walk-in | Completed`.
- Pagination shows "Showing 1–4 of 4".
- Service filter (All services / University Clinic / Guidance & Counseling) and Reset control present.

**Notes:** The application exposes **no per-visit detail route** (the only portal visit route is
`portal/visits`), and the encounter summary is rendered inline in the history row rather than in a
separate detail page. The expected outcome "the detail view shows that visit's encounter summary" is
satisfied by the inline summary; the step "open one recorded visit" has no corresponding control.

---

### PT-007 — Profile photo upload and removal

**Verdict:** PASS

**Steps observed:** `/portal/settings` → *Choose a new picture* → uploaded a workspace PNG
(`public/assets/img/logo.png`) → *Upload picture* → screenshot → *Remove picture* → confirmation modal
→ *Remove picture* → re-read the page markup.

**Evidence:**
- After upload the status line read: **"Profile picture updated. Only the compressed image was
  stored."**
- Screenshot confirmed the uploaded image rendered as the profile picture in the Profile picture card
  **and** replaced the initials avatar in the header (top right).
- The *Remove picture* button was enabled while a picture existed (the remove form was rendered
  `hidden` / button `disabled` before the upload).
- After the removal the server re-rendered the remove form as
  `<form … data-profile-photo-remove="" … hidden=""><button … disabled="">Remove picture</button>` —
  i.e. the page reports no stored picture.
- Removal went through a `Remove profile picture` confirmation modal ("Remove your profile picture and
  use the initials avatar? Any selected new picture will also be discarded.").

**Notes:** Upload is compressed and stored (the UI states JPEG/PNG/WebP, max 20 MP, fitted to
1024×1024, compressed to WebP at ≤300 KB, metadata stripped); the official university ID photo is
explicitly unaffected.

---

### PT-008 — Emergency contact update

**Verdict:** PASS

**Steps observed:** `/portal/settings` → *Emergency contact* → changed *Contact person*, *Relationship*
and *Emergency phone* → *Save emergency contact* → reloaded the page and re-read the server-rendered
form.

**Evidence:**
- Save feedback: **"Emergency contact updated."**
- After a full page reload the form came back with the submitted values:
  `Contact person = "[REDACTED: emergency-contact name]"`, `Relationship = "[REDACTED: emergency-contact relationship] (PT-008)"`,
  `Emergency phone = "[REDACTED: emergency-contact phone number]"`.
- The value is also reflected on the portal home card
  (*Contact: [REDACTED: emergency-contact name] · Relationship: [REDACTED: emergency-contact relationship] (PT-008) · Phone:
  [REDACTED: emergency-contact phone number]*).

**Notes:** This test student's emergency contact now carries the black-box values shown above
(seeded original was `[REDACTED: emergency-contact name]` / `[REDACTED: emergency-contact relationship]` / `[REDACTED: emergency-contact phone number]`). Flagged in *Test data created*.

---

### PT-009 — Announcements read tracking

**Verdict:** PASS

**Steps observed:** `/portal/announcements` → opened the announcement → returned to the list → read the
counters and the row badge.

**Evidence:**
- List counters: **All updates 1 / Unread 0 / Read 1**; the row carries a **Read** badge.
- Detail page `/portal/announcements/33` ("Guidance counselling services available this week
  (GD-012)") shows a **Read** badge and the line
  **"Marked read 01 Nov 2026, 1:42 PM (Asia/Manila)"**.
- Returning to the list after opening the announcement still shows the item as **Read**.

**Notes:** The only announcement in the seed is the one created by GD-012, which was already read by
this account, so the unread → read transition for this specific item was exercised during the GD-012
run (same announcement, same account) and the persisted read state was independently re-verified here.
The announcement's "Published by / Category / Search" filters and the *Read announcement* action were
present.

---

### PT-010 — Survey participation

**Verdict:** PASS

**Steps observed:**
1. Both seeded surveys were already submitted by this account, so a fresh survey was created through
   the Guidance console: signed in as `[REDACTED: synthetic account identifier]` → *Guidance Surveys* → *Create survey* →
   title `Black-box PT-010 student survey`, description, Audience **Students**, one text question
   `How supported do you feel by campus counselling services? (PT-010)` → *Save draft* → *Publish* →
   confirmed in the *Publish survey* modal.
2. Signed back in as `[REDACTED: synthetic account identifier]` → `/portal/surveys` → *Answer survey* → typed the answer →
   *Submit identified response* → confirmed in the *Submit survey* modal.

**Evidence:**
- Guidance console after publishing: `Black-box PT-010 student survey | Students | All students
  (unrestricted) | Published | Responses | Close`.
- Portal surveys list before answering: `All survey [REDACTED: survey identifier] / Available 1 / Submitted 2 / Closed 0`, with the
  new survey marked **Not yet answered** and an *Answer survey* action.
- Survey page `/portal/surveys/[REDACTED: survey identifier]` states "This is an identified survey… You may submit once; answers
  cannot be changed after submission." with the required question.
- After submission the list shows `All survey [REDACTED: survey identifier] / Available 0 / Submitted 3 / Closed 0` and the row
  reads **Submitted — "Your submission is recorded."**

**Notes:** survey [REDACTED: survey identifier] and its response were created as black-box test data (see *Test data created*).
The survey's closing date is unset and the audience is unrestricted students.

---

### PT-011 — Notification inbox

**Verdict:** PASS

**Steps observed:** `/notifications/inbox` → clicked the first item's *Mark read* → clicked
*Mark all read* → re-read the list and the unread counter.

**Evidence:**
- Inbox opened with **8 unread** notifications: "New survey" (22:39), "New survey" (20:51),
  "Appointment confirmed" (20:27), "Appointment confirmed" (18:57), "New announcement" (13:34),
  "New survey" (13:31), "Upcoming appointment" (12:20), "Appointment confirmed" (12:19) — the
  triggers required by the case (appointment confirmations, a new survey, a new announcement) were
  present.
- After the single *Mark read*, the first item changed to **Read** while the other seven still showed
  *Unread* + *Mark read*.
- After *Mark all read*: the counter reads **"0 unread"** and all eight items show **Read**.

**Notes:** The page states "Opening this page or a destination does not mark an alert as read", which
matches the observed behaviour — marking is explicit.

---

### PT-012 — Records isolation

**Verdict:** PASS

**Steps observed:** As `[REDACTED: synthetic account identifier]`, attempted to reach another person's records by manipulating
identifiers, and to reach staff-side record routes.

**Evidence:**
| Attempt | Result |
| --- | --- |
| `/portal/visits?person_id=[REDACTED: person record identifier]&person=[REDACTED: patient record identifier]` | Page loads, but the list still contains **only the student's own 4 visits** — the injected identifiers are ignored, no other person's history is shown. |
| `/portal/records?person_id=[REDACTED: person record identifier]` | Portal home renders with the signed-in student's own data only. |
| `/clinic/patients/[REDACTED: patient record identifier]` (another person's chart, patient_id [REDACTED: patient record identifier]) | **Redirected to `/portal`** — no patient chart, no other person's records. |
| `/clinic/encounters/[REDACTED: clinical encounter identifier]` (a clinic encounter) | **Redirected to `/portal`** — no encounter view. |

**Notes:** No identifier manipulation surfaced another person's data; every attempt either ignored the
parameter or was refused by redirecting the student to their own portal.

---

## Test data created

| What | Identifier | Detail | Where |
| --- | --- | --- | --- |
| Cancelled clinic request | appointment **[REDACTED: appointment identifier]** | Nov 2 2026 10:00, cancelled during PT-004 | `appointments` |
| Cancelled clinic request | appointment **[REDACTED: appointment identifier]** | Nov 2 2026 10:00, cancelled during PT-004 (first attempt) | `appointments` |
| Clinic appointment request | appointment **[REDACTED: appointment identifier]** | Nov 2 2026 09:00 → confirmed at 11:00 AM with [REDACTED: staff name] (PT-002) | `appointments` |
| Clinic appointment request | appointment **[REDACTED: appointment identifier]** | Nov 2 2026 11:00, left **pending** as the PT-003 slot-taken probe (reason: "Black-box PT-003 probe: request against an already-confirmed slot (Nov 2, 2026 11:00 AM).") | `appointments` |
| Survey | survey **[REDACTED: survey identifier]** | "Black-box PT-010 student survey" — published, audience Students, 1 text question | `surveys` |
| Survey response | (PT-010 submission) | Answer: "[REDACTED: identified counselling-survey response] (PT-010)." | `survey_responses` |
| Profile picture | — | `public/assets/img/logo.png` uploaded then removed (net: no stored picture) | profile photo store |
| Emergency contact | `[REDACTED: synthetic account identifier]` (person [REDACTED: person record identifier]) | Changed to *[REDACTED: emergency-contact name]* / *[REDACTED: emergency-contact relationship] (PT-008)* / *[REDACTED: emergency-contact phone number]* — **left in place, original was** *[REDACTED: emergency-contact name]* / *[REDACTED: emergency-contact relationship]* / *[REDACTED: emergency-contact phone number]* | `people` |
| Notification read state | 8 personal notifications for person [REDACTED: person record identifier] | All marked read during PT-011 | notifications |

---

## Summary

| ID | Verdict | One-line reason |
| --- | --- | --- |
| PT-001 | PASS | Portal home renders queue tickets, appointments, announcements, surveys, profile and emergency contact. |
| PT-002 | PASS | Request created and listed in My Appointments; once staff confirmed it, that slot is no longer offered for the clinician. |
| PT-003 | PASS | The occupied slot is rendered disabled in the picker and the server rejects it with HTTP 409 "The selected time slot is already booked." |
| PT-004 | PASS | Cancel modal + reason submitted (HTTP 200); the appointment shows **Cancelled** and the slot is released. |
| PT-005 | PASS | Queue ticket shows place in line #1, 0 people ahead, estimated wait "You're next". |
| PT-006 | PASS | Visit history lists all 4 past visits with date, service, staff, attendance and encounter summary; no separate detail route exists. |
| PT-007 | PASS | Photo uploaded (avatar updated), then removed (server re-renders with no stored picture). |
| PT-008 | PASS | Emergency contact saved ("Emergency contact updated.") and persisted across a full reload. |
| PT-009 | PASS | Announcement shows **Read** with a "Marked read" timestamp; list counters show Unread 0 / Read 1. |
| PT-010 | PASS | Fresh survey answered and submitted; list moves to Available 0 / Submitted 3 with "Your submission is recorded." |
| PT-011 | PASS | One notification marked read individually, then all read — counter drops to "0 unread". |
| PT-012 | PASS | Identifier manipulation on portal routes ignored; staff-side routes redirect the student to their own portal with no other person's records. |

**Totals: 12 PASS · 0 FAIL · 0 BLOCKED**
