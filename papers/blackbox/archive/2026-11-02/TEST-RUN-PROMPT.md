# TASK: Execute black-box tests — SYNAPSE Clinic (CL) and Guidance (GD) groups

You are a computer-use test agent. Execute the 26 black-box test cases below against a RUNNING web application using your browser automation, record the ACTUAL behavior for each, and write a results report. This validates test cases documented in a capstone paper, so honest results matter more than green checkmarks: if a step fails or behaves differently, that IS the result — record it.

## 1. Environment

- Application: SYNAPSE, a CodeIgniter 4 campus health & guidance portal. **Already running** (do not start it): `http://localhost:8080`
- Login page: `http://localhost:8080/index.php/auth/login`
- Single-host development mode: all workstations are paths on the same host:
  - Clinic console: `http://localhost:8080/clinic`
  - Guidance console: `http://localhost:8080/guidance`
  - Main/personal portal: `http://localhost:8080/` (used for booking appointments as a student, and for surveys/announcements)
- Test accounts (password for ALL: `[REDACTED: shared test password]`):

| Username | Role | Used for |
| --- | --- | --- |
| `[REDACTED: synthetic account identifier]` | clinic_staff | Most Clinic cases |
| `[REDACTED: synthetic account identifier]` | clinic_admin | Clinic configuration if needed |
| `[REDACTED: synthetic account identifier]` | counsellor | Guidance cases (owns the case you create) |
| `[REDACTED: synthetic account identifier]` | counsellor | GD-004 note-isolation check |
| `[REDACTED: synthetic account identifier]` | guidance_admin | Question bank, surveys, announcements |
| `[REDACTED: synthetic account identifier]` | guidance_supervisor | GD-005 oversight |
| `[REDACTED: synthetic account identifier]` | student | Booking appointments, answering surveys (main portal) |

- Notes:
  - The login field is labeled **ID Number** and accepts the username (e.g., `[REDACTED: synthetic account identifier]`).
  - Staff sign-in on the main host lands on the personal portal; navigate to the workstation via the sidebar link or direct URL (`/clinic`, `/guidance`).
  - Sessions are per-workspace; signing into `/guidance` after `/clinic` may require a second login. That is expected behavior (GD-004 depends on separate sessions).
  - A student test fixture exists (`[REDACTED: synthetic account identifier]`, BSIT year 3). Provider availability and schedules are intentionally NOT seeded; you may need to create an appointment through the staff UI.

## 2. Execution protocol

1. Work in the recommended order in section 4 (it respects data dependencies).
2. For each case: perform the test steps exactly, then **observe the result in the UI** before recording.
3. Record one line per case in the results file (format in section 5) with:
   - Result: `PASS` / `FAIL` / `BLOCKED` (blocked = a precondition could not be established)
   - **Actual Output**: what you actually observed — exact page/message/URL, the data values shown, button labels. Be specific ("Queue board showed ticket CLN-0004, state 'Called'") not generic ("it worked").
   - If FAIL: what differed from the expected result, exact error text if any.
4. If a case needs test data (appointment, batch, case, survey), create it through the UI as part of setup and note what you created.
5. Sign out between role switches (account menu → sign out → confirm).
6. Do not modify application code, database, or config to make a test pass. Creating data through the UI is allowed and expected.

## 3. Recommended order and setup notes

**Clinic session (sign in as `[REDACTED: synthetic account identifier]`, workstation `/clinic`):**
1. CL-001 (dashboard) — first thing after login.
2. CL-014 (inventory: receive batch, adjust stock) — do early; CL-008/009 depend on stock on hand. If the medicine list is empty, create a medicine first via the inventory UI.
3. CL-002, CL-003, CL-004 (registry search, chart, health profile update) — pick any patient (e.g., the person linked to `[REDACTED: synthetic account identifier]`).
4. CL-013 (confirm + check-in an appointment) — SETUP: create an appointment for `[REDACTED: synthetic account identifier]` via the Appointments module first if none exists; then confirm it; then check it in. This produces the queue ticket used by CL-005..010.
5. CL-005 → CL-006 → CL-007 → CL-008 → CL-009 → CL-010 (encounter stepper flow on the checked-in ticket; CL-009 tries dispensing more than the batch stock you received in CL-014).
6. CL-011 (walk-in: add walk-in to clinic queue, call next, start, complete).
7. CL-012 (skip and no-show transitions — needs a second ticket; add another walk-in, call it, apply skip; apply no-show to another).

**Guidance session:**
8. Sign in as `[REDACTED: synthetic account identifier]`, workstation `/guidance`: GD-001 (dashboard).
9. GD-002 (create case for `[REDACTED: synthetic account identifier]`), GD-003 (start session, save note), GD-006 (create two follow-ups on the case; complete one, miss one), GD-007 (close the case after sessions).
10. GD-008 (guidance queue): SETUP — book a counselling appointment for `[REDACTED: synthetic account identifier]` (book via main portal as the student, or via the guidance Appointments UI), then check it in from the guidance console, then call next → start → complete.
11. Sign in as `[REDACTED: synthetic account identifier]`: GD-009 (question bank: create → preview → archive), GD-010 (build + publish survey to students), GD-012 (publish announcement to students).
12. As `[REDACTED: synthetic account identifier]` on the main portal: answer the published survey (setup for GD-011).
13. Sign back in as `[REDACTED: synthetic account identifier]` (or the counsellor if permitted): GD-011 (view response detail, export CSV).
14. GD-004: while signed in as `[REDACTED: synthetic account identifier]` (open the guidance console), attempt to open the session note created in GD-003.
15. GD-005: sign in as `[REDACTED: synthetic account identifier]`, open the counsellor's case via oversight; then check the audit trail (Administration → Audit; if the supervisor lacks audit access, note that and verify the audit entry via `[REDACTED: synthetic account identifier]` if permitted).

## 4. Test cases

### Clinic group (workstation `/clinic`, account `[REDACTED: synthetic account identifier]` unless noted)

**CL-001 — Clinic dashboard**
Steps: 1. Sign in as clinic staff. 2. Open the clinic console dashboard.
Expected: The dashboard renders with today's queue counters and appointment counts for the clinic unit.

**CL-002 — Patient registry search**
Steps: 1. Open the patient registry. 2. Search by name fragment and by identification number.
Expected: Matching patients are listed with their registry details.

**CL-003 — Patient chart**
Steps: 1. Open a patient from the registry. 2. View the chart.
Expected: The chart shows encounter history, vitals history, and the health profile.

**CL-004 — Health profile update**
Steps: 1. Open a patient chart. 2. Update health profile fields and save.
Expected: The health profile is updated and the change is visible on the chart.

**CL-005 — Start encounter from ticket**
Steps: 1. Check in an appointment. 2. Open the queue and start the encounter for that ticket.
Expected: The encounter opens linked to the ticket with the encounter stepper active.

**CL-006 — Record vitals**
Steps: 1. In an open encounter. 2. Enter blood pressure, pulse, temperature, and other vitals. 3. Save.
Expected: Vitals are recorded and displayed in the encounter and in the patient's vitals history.

**CL-007 — Assessment draft save**
Steps: 1. In an open encounter. 2. Record the presenting complaint and assessment. 3. Save as draft. 4. Reopen the encounter.
Expected: The draft is retained and the encounter resumes with the draft contents.

**CL-008 — Medicine dispensation (stock deducted)**
Steps: 1. In an open encounter. 2. Dispense a quantity of a medicine with stock on hand. 3. Complete the step.
Expected: The dispensation is recorded and the medicine's batch stock decreases accordingly.

**CL-009 — Dispensation beyond stock**
Steps: 1. In an open encounter. 2. Dispense a quantity larger than the available batch stock.
Expected: The system rejects the dispensation and reports insufficient stock.

**CL-010 — Complete encounter**
Steps: 1. Complete an encounter that has vitals and assessment recorded.
Expected: The encounter is marked complete and leaves the active workflow.

**CL-011 — Queue lifecycle (walk-in)**
Steps: 1. Add a walk-in to the clinic queue. 2. Call next, start, and complete the entry.
Expected: The ticket advances through called, started, and completed states with the board updating.

**CL-012 — Queue transitions (skip and no-show)**
Steps: 1. For a called ticket, apply skip. 2. For another ticket apply no-show.
Expected: Both transitions are recorded and the tickets leave the serving position without blocking the queue.

**CL-013 — Appointment confirm and check-in**
Steps: 1. Confirm a booked appointment. 2. Check the appointment in at the front desk.
Expected: The appointment transitions to checked-in and a queue entry is created for today's clinic queue.

**CL-014 — Inventory batch receipt and adjustment**
Steps: 1. Receive a medicine batch with quantity and expiry. 2. Adjust stock with a reason. 3. Open the operation ledger.
Expected: The receipt and adjustment are recorded with actor and timestamp in the append-only operation ledger.

### Guidance group (workstation `/guidance`)

**GD-001 — Guidance dashboard** (as `[REDACTED: synthetic account identifier]`)
Steps: 1. Sign in as a counsellor. 2. Open the guidance console dashboard.
Expected: The dashboard renders with the guidance queue counters and caseload summary.

**GD-002 — Create counselling case**
Steps: 1. Open cases. 2. Create a case for a student with category and intake details.
Expected: The case is created with a case number and appears in the caseload.

**GD-003 — Start session with encrypted note**
Steps: 1. Open a case. 2. Start a session. 3. Record a session note and save.
Expected: The session is recorded and the note is stored encrypted.

**GD-004 — Note isolation** (as `[REDACTED: synthetic account identifier]`)
Steps: 1. Sign in as a different counsellor. 2. Attempt to open the first counsellor's session note.
Expected: Access is refused; the note is readable only by the owning counsellor.

**GD-005 — Supervisor oversight (audited)** (as `[REDACTED: synthetic account identifier]`)
Steps: 1. Sign in as guidance supervisor. 2. Open a counsellor's case with the oversight permission. 3. Inspect the audit trail.
Expected: The supervisor can read the case with the oversight permission, and the access is recorded as an audited exception.

**GD-006 — Follow-up completion and misses**
Steps: 1. Open a case follow-up. 2. Mark one follow-up completed with notes; mark another missed.
Expected: Both dispositions are recorded on the case timeline.

**GD-007 — Close case**
Steps: 1. Open a case with completed sessions. 2. Close the case with a disposition.
Expected: The case is closed and appears in the closed-cases view.

**GD-008 — Guidance queue operations**
Steps: 1. Check in a counselling appointment. 2. Call next, start, and complete the entry in the guidance queue.
Expected: The guidance queue advances independently of the clinic queue.

**GD-009 — Question bank authoring** (as `[REDACTED: synthetic account identifier]`)
Steps: 1. Open the question bank. 2. Create a question, preview it, then archive it.
Expected: The question is created with a version, previews correctly, and archived questions no longer appear in the active set.

**GD-010 — Survey build and publish** (as `[REDACTED: synthetic account identifier]`)
Steps: 1. Create a survey from bank questions. 2. Publish it to the student audience.
Expected: The survey appears for the targeted audience and accepts responses.

**GD-011 — Survey response review and export**
Steps: 1. Open survey responses. 2. Open one response detail. 3. Export the response set.
Expected: Responses are listed with per-response detail, and the export downloads with bounded rows.
Setup: as `[REDACTED: synthetic account identifier]` on the main portal, answer the published survey first.

**GD-012 — Announcement publishing (guidance)** (as `[REDACTED: synthetic account identifier]`)
Steps: 1. Create and publish a guidance announcement to students.
Expected: The announcement appears in student announcement feeds and read receipts are recorded.

## 5. Required output

Write the results to `[REDACTED: local user path]\humus\papers\blackbox\TEST-RESULTS-CL-GD.md` using exactly this shape:

```markdown
# Black-box execution results — Clinic (CL) + Guidance (GD)
Date: <date> | Tester: <agent/browser> | Server: http://localhost:8080

## Summary
- PASS: n / 26, FAIL: n, BLOCKED: n
- Notable defects or deviations: <short list or "none">

## Results
| ID | Result | Actual Output |
| --- | --- | --- |
| CL-001 | PASS | <specific observation> |
| ... | ... | ... |

## Defect / deviation details
<for each FAIL or deviation: case ID, what happened, exact error text, screenshot reference if any>
```

Rules: never mark PASS without observing the expected result in the UI. Record exact labels/messages you saw. If the application is unreachable, stop after diagnosing (report the URL and response) rather than guessing.
