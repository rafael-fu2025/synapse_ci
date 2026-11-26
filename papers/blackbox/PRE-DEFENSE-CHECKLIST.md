# Pre-Defense Live Evaluation Checklist (SYNAPSE)

Run this preparation checklist on the evaluation host **before** the two Technical Advisers and Capstone Adviser begin the 2-hour black-box test session.

---

## 1. One-Command Database & Demo Account Preparation

Run from the repository root:

```powershell
php tools/blackbox/prepare_defense_env.php
```

This idempotent script prepares `humus_db`:
1. Signs off and enables all **6 Guidance activation gates** (`canonical_forms_signoff`, etc.) in `guidance_activation_gates` so **EMP-01** (`GGR-005` Live Referral submission) works immediately even if Track A runs ahead of Track B.
2. Seeds the **4 role demo accounts** not included in the default DB seed (all use password `password123`) alongside active shifts / `department_staff` rows:
   - `test.clinic.physician` (`clinic_physician`)
   - `test.clinic.intern` (`clinic_intern`)
   - `test.guidance.intern` (`guidance_intern`)
   - `test.bmg.intern` (`bmg_intern`)
3. Ensures active `department_staff` records for Team Workspace and at least one active medicine + batch (`Paracetamol 500mg`).
4. Clears `login_attempt_buckets` so no residual IP rate-limit lockouts block the start of testing.

---

## 2. Evaluator Credentials by Track (Password: `password123`)

### Track A — Public, Personal Self-Service & Clinic (~18 cases + Mobile)
- **Public (`PUB-01..PUB-05`):** No sign-in required (`/privacy-policy`, `/auth/login`, `/guidance/verify/{code}`)
- **Student (`STU-01..STU-08`, `SH-*`):** `test.student`
- **Employee (`EMP-01..EMP-02`):** `test.employee`
- **Clinic Intern (`CLI-01..CLI-04`):** `test.clinic.intern`
- **Clinic Staff (`CLS-01..CLS-09`):** `test.clinic.staff`
- **Clinic Physician (`CLP-01`):** `test.clinic.physician`
- **Clinic Administrator (`CLA-01..CLA-04`):** `test.clinic.admin`

### Track B — Guidance, Counseling & Team Collaboration (~19 cases)
- **Workstation / Team Collaboration (`TW-01..TW-06`):** `test.clinic.staff` (sender) & `test.counsellor` (receiver)
- **Guidance Intern (`GDI-01..GDI-04`):** `test.guidance.intern`
- **Counsellor (`CNS-01..CNS-09`):** `test.counsellor` (and `test.counsellor2` for `CNS-09` cross-counsellor isolation)
- **Guidance Supervisor (`GDS-01..GDS-05`):** `test.guidance.supervisor`
- **Guidance Administrator (`GDA-01..GDA-02`):** `test.guidance.admin`

### Track C — BMG Composting, Hardware, Reports, Audit & Platform (~19 cases)
- **BMG Intern (`BMI-01..BMI-02`):** `test.bmg.intern`
- **Facilities Operator (`FOP-01..FOP-02`):** `test.facilities.op`
- **BMG Administrator (`BMA-01..BMA-03`):** `test.bmg.admin`
- **Report Viewer (`RPT-01..RPT-02`):** `test.report.viewer`
- **Audit Reader (`AUD-01..AUD-02`):** `test.audit.reader`
- **Platform Superadmin (`ADM-01..ADM-04`):** `admin@foundationu.com` (or `test.superadmin`)
- **Hardware Provisioning (`HW-01..HW-02`):** `facilities.hardware.manage` permission grant + `BMG_HARDWARE_ENABLED=true` in `.env` (see Section 3 below)
- **AI Insights Granted Staff (`AI-01..AI-02`):** `test.clinic.admin` (or any role/unit granted in `ai_department_access` via `ADM-04`)

---

## 3. Important Operational Notes During Live Testing

1. **Avoid IP Login Throttling (`login_throttle` on `POST /auth/login`):**
   - Signing in and out repeatedly from the same browser/IP triggers temporary cooldowns after ~5 rapid login attempts.
   - **Recommended:** Have each evaluator keep separate browser profiles or incognito windows open for distinct roles (or re-run `php tools/blackbox/prepare_defense_env.php` / `DELETE FROM login_attempt_buckets;` between tracks if switching accounts on one machine).
2. **Hardware Provisioning (`HW-01`, `HW-02`):**
   - By default, `.env` sets `BMG_HARDWARE_ENABLED=false` (safety gate so `/facilities/unit/{id}/usb` returns `403` when hardware hardware mode is disabled).
   - To demonstrate commissioned USB firmware flashing live during Track C, set `BMG_HARDWARE_ENABLED=true` in `.env` and ensure the operator account holds `facilities.hardware.manage` (not granted by default, even to `superadmin`, until explicitly assigned).
3. **Guidance New Case Trigger (`CNS-01`):**
   - Open the **New Case** modal by clicking the **Create** button on `/guidance/cases` (visiting `/guidance/cases/create` directly renders `"Complete the form in the dialog."`).
4. **Controlled Document Verification (`PUB-05`):**
   - `/guidance/verify/{verification_code}` works completely unauthenticated (outside the session filter) and renders strictly non-PII form metadata.
