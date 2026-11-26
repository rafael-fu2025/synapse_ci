# SYNAPSE Black-Box Testing

## Document status

> **Document status: DRAFT.** This file is generated from ordered section sources and grouped by **User Role -> Module -> Functionality** to match the capstone Use Case model (Figure 5). Do not edit generated output by hand.
>
> **Execution state:** NOT_RUN=266, PASS=0, FAIL=0, BLOCKED=0, NOT_IN_RUN=0. A non-NOT_RUN value is accepted only through a supplied sanitized results file whose artifacts are internally hash-consistent; hashes alone are not trusted execution attestation.

## Metadata

| Property | Value |
| --- | --- |
| Title | SYNAPSE Black\-Box Testing |
| Version | 2026\.11\-draft\.2 |
| Status | DRAFT |
| Organization | User Role -> Module -> Functionality (Option A) |
| Target commit | `e7d5572fd117c7f556393894798ec408204cd4ca` |
| Expected cases | 266 |
| Result overlay | None — source verdicts are constrained to NOT_RUN |
| Archive | `papers/blackbox/archive/2026-11-02` (verified) |
| Use\-case catalog | 70 use cases across 21 actor groups (`papers/blackbox/use-cases.json`) |

## Warnings

- Suite metadata status is **DRAFT**, not READY.
- **28** cases have DRAFT lifecycle.
- **13** cases are CONDITIONAL and require their stated preconditions.
- **266** cases are NOT_RUN; expectations are specifications, not observed outcomes.

## Computed summary

### Lifecycle

| Lifecycle | Cases |
| --- | ---: |
| READY | 225 |
| DRAFT | 28 |
| CONDITIONAL | 13 |
| **Total** | **266** |

### Verdict

| Verdict | Cases |
| --- | ---: |
| NOT_RUN | 266 |
| PASS | 0 |
| FAIL | 0 |
| BLOCKED | 0 |
| NOT_IN_RUN | 0 |
| **Total** | **266** |

### Legacy reconciliation

| Disposition | Legacy cases |
| --- | ---: |
| RETAIN | 72 |
| REWRITE | 42 |
| RETIRED | 5 |
| **Total** | **119** |

### Role groups and inheritance coverage

| # | Role / Actor Group | Kind | Inherits From | Cases | READY | DRAFT | CONDITIONAL | NOT_RUN | PASS | FAIL | BLOCKED | NOT_IN_RUN |
| ---: | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | Public / Unauthenticated User | public | — | 5 | 4 | 1 | 0 | 5 | 0 | 0 | 0 | 0 |
| 2 | All Authenticated Roles \(Shared\) | shared\_group | — | 30 | 29 | 1 | 0 | 30 | 0 | 0 | 0 | 0 |
| 3 | Student | rbac | All Authenticated Roles \(Shared\) | 45 | 27 | 13 | 5 | 45 | 0 | 0 | 0 | 0 |
| 4 | Employee | rbac | Student | 5 | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| 5 | Department Workstation Member \(Clinic, Guidance & BMG\) | shared\_group | All Authenticated Roles \(Shared\) | 39 | 37 | 2 | 0 | 39 | 0 | 0 | 0 | 0 |
| 6 | Clinic Intern | rbac | Department Workstation Member \(Clinic, Guidance & BMG\) | 9 | 9 | 0 | 0 | 9 | 0 | 0 | 0 | 0 |
| 7 | Clinic Staff | rbac | Clinic Intern | 19 | 19 | 0 | 0 | 19 | 0 | 0 | 0 | 0 |
| 8 | Clinic Physician | rbac | Clinic Staff | 1 | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 |
| 9 | Clinic Administrator | rbac | Clinic Staff | 3 | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| 10 | Guidance Intern | rbac | Department Workstation Member \(Clinic, Guidance & BMG\) | 5 | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| 11 | Counsellor | rbac | Guidance Intern | 34 | 31 | 3 | 0 | 34 | 0 | 0 | 0 | 0 |
| 12 | Guidance Supervisor | rbac | Counsellor | 16 | 12 | 4 | 0 | 16 | 0 | 0 | 0 | 0 |
| 13 | Guidance Administrator | rbac | Guidance Supervisor | 2 | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| 14 | BMG Intern | rbac | Department Workstation Member \(Clinic, Guidance & BMG\) | 2 | 1 | 1 | 0 | 2 | 0 | 0 | 0 | 0 |
| 15 | Facilities Operator | rbac | BMG Intern | 6 | 5 | 1 | 0 | 6 | 0 | 0 | 0 | 0 |
| 16 | BMG Administrator | rbac | Facilities Operator | 4 | 4 | 0 | 0 | 4 | 0 | 0 | 0 | 0 |
| 17 | Report Viewer | rbac | All Authenticated Roles \(Shared\) | 3 | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| 18 | Audit Reader | rbac | Report Viewer | 4 | 4 | 0 | 0 | 4 | 0 | 0 | 0 | 0 |
| 19 | Platform Owner \(Superadmin\) | rbac | Clinic Administrator, Guidance Administrator, Facilities Operator, Audit Reader | 16 | 14 | 2 | 0 | 16 | 0 | 0 | 0 | 0 |
| 20 | Commissioned Hardware Operator \(Explicit facilities\.hardware\.manage Grant\) | explicit\_grant | — | 8 | 0 | 0 | 8 | 8 | 0 | 0 | 0 | 0 |
| 21 | Unit Staff under Explicit AI Grant \(ai\.insights\.\*\) | explicit\_grant | — | 10 | 10 | 0 | 0 | 10 | 0 | 0 | 0 | 0 |

### Canonical source sections

| # | Section | Source | Cases | READY | DRAFT | CONDITIONAL | NOT_RUN | PASS | FAIL | BLOCKED | NOT_IN_RUN |
| ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | Authentication and workstation hosts | `papers/blackbox/sections/01-authentication-hosts.md` | 13 | 13 | 0 | 0 | 13 | 0 | 0 | 0 | 0 |
| 2 | Personal portal, scheduling, and profile | `papers/blackbox/sections/02-portal-scheduling-profile.md` | 17 | 16 | 1 | 0 | 17 | 0 | 0 | 0 | 0 |
| 3 | Clinic core workflows | `papers/blackbox/sections/03-clinic-core.md` | 15 | 15 | 0 | 0 | 15 | 0 | 0 | 0 | 0 |
| 4 | Clinic extensions, inventory, and role boundaries | `papers/blackbox/sections/04-clinic-extensions.md` | 13 | 13 | 0 | 0 | 13 | 0 | 0 | 0 | 0 |
| 5 | Guidance core workflows | `papers/blackbox/sections/05-guidance-core.md` | 12 | 12 | 0 | 0 | 12 | 0 | 0 | 0 | 0 |
| 6 | Guidance intake, governance, and practitioner controls | `papers/blackbox/sections/06-guidance-intake-governance.md` | 14 | 12 | 2 | 0 | 14 | 0 | 0 | 0 | 0 |
| 7 | Guidance records, forms, episodes, call slips, and clinical workflow | `papers/blackbox/sections/07-guidance-forms-episodes.md` | 24 | 22 | 2 | 0 | 24 | 0 | 0 | 0 | 0 |
| 8 | Guidance assessments and service evaluations | `papers/blackbox/sections/08-guidance-assessments-evaluations.md` | 12 | 1 | 11 | 0 | 12 | 0 | 0 | 0 | 0 |
| 9 | Interdepartmental referrals | `papers/blackbox/sections/09-referrals.md` | 9 | 9 | 0 | 0 | 9 | 0 | 0 | 0 | 0 |
| 10 | Team Workspace on the web | `papers/blackbox/sections/10-team-workspace-web.md` | 12 | 12 | 0 | 0 | 12 | 0 | 0 | 0 | 0 |
| 11 | BMG bookkeeping | `papers/blackbox/sections/11-bmg-bookkeeping.md` | 9 | 7 | 2 | 0 | 9 | 0 | 0 | 0 | 0 |
| 12 | BMG hardware conditional annex | `papers/blackbox/sections/12-bmg-hardware.md` | 8 | 0 | 0 | 8 | 8 | 0 | 0 | 0 | 0 |
| 13 | Administration and platform | `papers/blackbox/sections/13-administration.md` | 11 | 9 | 2 | 0 | 11 | 0 | 0 | 0 | 0 |
| 14 | Realtime and notifications | `papers/blackbox/sections/14-realtime-notifications.md` | 16 | 15 | 1 | 0 | 16 | 0 | 0 | 0 | 0 |
| 15 | AI insights | `papers/blackbox/sections/15-ai-insights.md` | 15 | 15 | 0 | 0 | 15 | 0 | 0 | 0 | 0 |
| 16 | Mobile personal application | `papers/blackbox/sections/16-mobile-personal.md` | 14 | 12 | 2 | 0 | 14 | 0 | 0 | 0 | 0 |
| 17 | Mobile Team Workspace | `papers/blackbox/sections/17-mobile-team-workspace.md` | 16 | 15 | 1 | 0 | 16 | 0 | 0 | 0 | 0 |
| 18 | Mobile platform | `papers/blackbox/sections/18-mobile-platform.md` | 8 | 4 | 4 | 0 | 8 | 0 | 0 | 0 | 0 |
| 19 | Responsive web contracts | `papers/blackbox/sections/19-responsive-web.md` | 8 | 8 | 0 | 0 | 8 | 0 | 0 | 0 | 0 |
| 20 | Guidance mobile API conditional annex | `papers/blackbox/sections/20-guidance-mobile-api.md` | 6 | 1 | 0 | 5 | 6 | 0 | 0 | 0 | 0 |
| 21 | Archive governance | `papers/blackbox/sections/21-archive-governance.md` | 8 | 8 | 0 | 0 | 8 | 0 | 0 | 0 | 0 |
| 22 | Student intern governance | `papers/blackbox/sections/22-student-intern-governance.md` | 6 | 6 | 0 | 0 | 6 | 0 | 0 | 0 | 0 |

## Use case traceability matrix

| UC ID | Figure | Primary Actor Role | Module | Use Case | Black-Box Test Cases |
| --- | --- | --- | --- | --- | --- |
| `UC-SH-01` | Figure 5a | Public / Unauthenticated User | Authentication, Session & Privacy | View Foundation University Data Privacy Policy | [AU\-013](#case-au-013), [AU\-014](#case-au-014) |
| `UC-SH-02` | Figure 5a | Public / Unauthenticated User | Authentication, Session & Privacy | Authenticate via host\-scoped login with rate limiting | [AU\-001](#case-au-001), [AU\-002](#case-au-002), [AU\-003](#case-au-003), [AU\-004](#case-au-004), [AU\-005](#case-au-005), [AU\-010](#case-au-010) |
| `UC-SH-03` | Figure 5a | Public / Unauthenticated User | Guidance & Counseling | Verify controlled document authenticity | [GD\-FRM\-007](#case-gd-frm-007) |
| `UC-SH-04` | Figure 5a | All Authenticated Roles \(Shared\) | Authentication, Session & Privacy | Log out of current host and enforce session/CSRF security | [AU\-006](#case-au-006), [AU\-007](#case-au-007), [AU\-008](#case-au-008), [AU\-012](#case-au-012) |
| `UC-SH-05` | Figure 5a | All Authenticated Roles \(Shared\) | Personal Portal & Self\-Service | Manage own profile, photo, contact details and emergency contact | [PT\-007](#case-pt-007), [PT\-008](#case-pt-008), [PT\-PRF\-001](#case-pt-prf-001), [PT\-PRF\-002](#case-pt-prf-002), [PT\-PRF\-003](#case-pt-prf-003), [PT\-PRF\-004](#case-pt-prf-004) |
| `UC-SH-06` | Figure 5a | All Authenticated Roles \(Shared\) | Realtime & Notifications | Receive personal notifications, realtime updates and responsive UI | [PT\-011](#case-pt-011), [RT\-001](#case-rt-001), [RT\-002](#case-rt-002), [RT\-003](#case-rt-003), [RT\-004](#case-rt-004), [RT\-008](#case-rt-008), [RT\-009](#case-rt-009), [RT\-010](#case-rt-010), [RT\-NT\-001](#case-rt-nt-001), [RT\-NT\-002](#case-rt-nt-002), [RT\-NT\-003](#case-rt-nt-003), [RT\-NT\-004](#case-rt-nt-004), [RT\-NT\-005](#case-rt-nt-005), [RT\-NT\-006](#case-rt-nt-006), [UX\-WEB\-001](#case-ux-web-001), [UX\-WEB\-002](#case-ux-web-002), [UX\-WEB\-003](#case-ux-web-003), [UX\-WEB\-007](#case-ux-web-007), [UX\-WEB\-008](#case-ux-web-008) |
| `UC-CL-01` | Figure 5b | Clinic Intern | Clinic Operations & Extensions | Review patient records, isolation and clearances \(read\-only\) | [CL\-001](#case-cl-001), [CL\-002](#case-cl-002), [CL\-RBAC\-002](#case-cl-rbac-002) |
| `UC-CL-02` | Figure 5b | Clinic Intern | Clinic Operations & Extensions | Operate Clinic queue and check in appointments | [CL\-001](#case-cl-001), [CL\-005](#case-cl-005), [CL\-011](#case-cl-011), [CL\-012](#case-cl-012), [RT\-005](#case-rt-005), [MB\-011](#case-mb-011) |
| `UC-CL-03` | Figure 5b | Clinic Intern | Clinic Operations & Extensions | Open clinical encounter and draft vitals or clinical notes | [CL\-005](#case-cl-005), [CL\-006](#case-cl-006), [CL\-007](#case-cl-007), [CL\-CORE\-001](#case-cl-core-001), [CL\-RBAC\-002](#case-cl-rbac-002) |
| `UC-CL-04` | Figure 5b | Clinic Intern | Clinic Operations & Extensions | Review medicine inventory and stock ledgers \(read\-only\) | [CL\-RBAC\-002](#case-cl-rbac-002) |
| `UC-CL-05` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Update patient health profiles and record delayed lab results | [CL\-003](#case-cl-003), [CL\-004](#case-cl-004), [CL\-EXT\-002](#case-cl-ext-002) |
| `UC-CL-06` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Dispense medicine from batches and seal clinical encounters | [CL\-008](#case-cl-008), [CL\-009](#case-cl-009), [CL\-010](#case-cl-010) |
| `UC-CL-07` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Append signed post\-visit encounter addenda | [CL\-EXT\-001](#case-cl-ext-001), [CL\-EXT\-002](#case-cl-ext-002) |
| `UC-CL-08` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Confirm, reschedule and check in Clinic appointments | [PT\-003](#case-pt-003), [PT\-SCH\-001](#case-pt-sch-001), [CL\-013](#case-cl-013), [UX\-WEB\-005](#case-ux-web-005) |
| `UC-CL-09` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Receive, adjust, quarantine and reverse medicine stock batches | [CL\-014](#case-cl-014), [CL\-INV\-001](#case-cl-inv-001), [CL\-INV\-002](#case-cl-inv-002), [CL\-INV\-003](#case-cl-inv-003) |
| `UC-CL-10` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Record and clear communicable disease isolation cases | [CL\-ISO\-001](#case-cl-iso-001), [CL\-ISO\-002](#case-cl-iso-002) |
| `UC-CL-11` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Issue enrollment health clearances and certificates | [CL\-EXT\-003](#case-cl-ext-003) |
| `UC-CL-12` | Figure 5b | Clinic Staff | Clinic Operations & Extensions | Generate Clinic summary and PDF reports | [CL\-RPT\-001](#case-cl-rpt-001), [CL\-RPT\-002](#case-cl-rpt-002) |
| `UC-CL-13` | Figure 5b | Clinic Physician | Clinic Operations & Extensions | Exercise grant\-driven physician clinical sealing authority | [CL\-RBAC\-001](#case-cl-rbac-001) |
| `UC-CL-14` | Figure 5b | Clinic Administrator | Clinic Operations & Extensions | Publish Clinic notices and manage provider availability | [CL\-ANN\-001](#case-cl-ann-001) |
| `UC-GD-01` | Figure 5c | Guidance Intern | Guidance & Counseling | Operate Guidance queue and review schedules | [GD\-001](#case-gd-001), [GD\-008](#case-gd-008), [RT\-005](#case-rt-005), [MB\-011](#case-mb-011), [INT\-005](#case-int-005) |
| `UC-GD-02` | Figure 5c | Guidance Intern | Guidance & Counseling | Process Guidance intakes and safety triage | [GD\-INT\-003](#case-gd-int-003), [GD\-INT\-004](#case-gd-int-004), [GD\-INT\-006](#case-gd-int-006), [GD\-INT\-007](#case-gd-int-007), [INT\-005](#case-int-005) |
| `UC-GD-03` | Figure 5c | Guidance Intern | Guidance & Counseling | Browse Clients Directory and assigned cases | [GD\-001](#case-gd-001), [GD\-REC\-001](#case-gd-rec-001), [GD\-REC\-002](#case-gd-rec-002), [GD\-REC\-003](#case-gd-rec-003), [INT\-005](#case-int-005) |
| `UC-GD-04` | Figure 5c | Guidance Intern | Guidance & Counseling | Draft psychosocial and clinical forms for supervisor co\-sign | [INT\-005](#case-int-005) |
| `UC-GD-05` | Figure 5c | Counsellor | Guidance & Counseling | Open counselling cases, record encrypted sessions and follow\-ups | [GD\-002](#case-gd-002), [GD\-003](#case-gd-003), [GD\-006](#case-gd-006), [GD\-007](#case-gd-007), [GD\-008](#case-gd-008), [GD\-INT\-005](#case-gd-int-005), [GD\-INT\-006](#case-gd-int-006), [GD\-REC\-002](#case-gd-rec-002), [UX\-WEB\-004](#case-ux-web-004) |
| `UC-GD-06` | Figure 5c | Counsellor | Guidance & Counseling | Manage care episodes, consultations and case transfers | [GD\-004](#case-gd-004), [GD\-REC\-003](#case-gd-rec-003), [GD\-EPI\-001](#case-gd-epi-001), [GD\-EPI\-002](#case-gd-epi-002), [GD\-EPI\-003](#case-gd-epi-003), [GD\-CLN\-003](#case-gd-cln-003) |
| `UC-GD-07` | Figure 5c | Counsellor | Guidance & Counseling | Author, finalize and print official OGC controlled forms | [GD\-PRC\-003](#case-gd-prc-003), [GD\-REC\-004](#case-gd-rec-004), [GD\-FRM\-002](#case-gd-frm-002), [GD\-FRM\-003](#case-gd-frm-003), [GD\-FRM\-004](#case-gd-frm-004), [GD\-FRM\-006](#case-gd-frm-006), [GD\-FRM\-008](#case-gd-frm-008), [GD\-CLN\-001](#case-gd-cln-001), [GD\-OGC\-001](#case-gd-ogc-001), [GD\-OGC\-002](#case-gd-ogc-002) |
| `UC-GD-08` | Figure 5c | Counsellor | Guidance & Counseling | Issue controlled call slips \(CSP\-007\) and appointment forms \(GAP\-006\) | [GD\-CALL\-001](#case-gd-call-001), [GD\-OGC\-003](#case-gd-ogc-003) |
| `UC-GD-09` | Figure 5c | Counsellor | Guidance & Counseling | Assign and interpret Multiple Intelligences assessments | [GD\-MI\-001](#case-gd-mi-001), [GD\-MI\-004](#case-gd-mi-004), [GD\-MI\-005](#case-gd-mi-005) |
| `UC-GD-10` | Figure 5c | Counsellor | Guidance & Counseling | Issue evaluation tokens and view own threshold\-safe aggregates | [GD\-OGC\-004](#case-gd-ogc-004), [GD\-EVL\-001](#case-gd-evl-001), [GD\-EVL\-005](#case-gd-evl-005), [GD\-EVL\-006](#case-gd-evl-006) |
| `UC-GD-11` | Figure 5c | Counsellor | Guidance & Counseling | Confirm, book and manage Guidance appointments | [PT\-003](#case-pt-003), [PT\-SCH\-001](#case-pt-sch-001), [GD\-OGC\-003](#case-gd-ogc-003), [UX\-WEB\-005](#case-ux-web-005) |
| `UC-GD-12` | Figure 5c | Guidance Supervisor | Guidance & Counseling | Review any tenant case and reassign caseloads | [GD\-005](#case-gd-005), [GD\-CLN\-003](#case-gd-cln-003) |
| `UC-GD-13` | Figure 5c | Guidance Supervisor | Guidance & Counseling | Co\-sign associate and intern clinical forms | [GD\-PRC\-004](#case-gd-prc-004), [GD\-FRM\-005](#case-gd-frm-005), [GD\-CLN\-002](#case-gd-cln-002) |
| `UC-GD-14` | Figure 5c | Guidance Supervisor | Guidance & Counseling | Publish announcements, question bank revisions and surveys | [GD\-009](#case-gd-009), [GD\-010](#case-gd-010), [GD\-012](#case-gd-012) |
| `UC-GD-15` | Figure 5c | Guidance Supervisor | Guidance & Counseling | Review survey responses and moderate evaluation comments | [GD\-011](#case-gd-011), [GD\-EVL\-004](#case-gd-evl-004) |
| `UC-GD-16` | Figure 5c | Guidance Supervisor | Guidance & Counseling | Govern form definitions, practitioner profiles, activation gates and availability | [GD\-GOV\-001](#case-gd-gov-001), [GD\-GOV\-002](#case-gd-gov-002), [GD\-GOV\-003](#case-gd-gov-003), [GD\-PRC\-001](#case-gd-prc-001), [GD\-PRC\-002](#case-gd-prc-002), [GD\-FRM\-001](#case-gd-frm-001) |
| `UC-GD-17` | Figure 5c | Guidance Administrator | Archive Governance | Place and lift Guidance archive legal holds | [ARV\-003](#case-arv-003) |
| `UC-BM-01` | Figure 5d | BMG Intern | Facilities \(BMG\) & Hardware | Review composting units, batches and recorded metrics | [BM\-001](#case-bm-001), [BM\-013](#case-bm-013), [INT\-006](#case-int-006) |
| `UC-BM-02` | Figure 5d | Facilities Operator | Facilities \(BMG\) & Hardware | Start, release and cancel bookkeeping batches | [BM\-003](#case-bm-003), [BM\-004](#case-bm-004), [BM\-006](#case-bm-006), [BM\-007](#case-bm-007), [BM\-008](#case-bm-008), [BM\-013](#case-bm-013) |
| `UC-BM-03` | Figure 5d | BMG Administrator | Facilities \(BMG\) & Hardware | Register, update and archive composting units | [BM\-002](#case-bm-002), [BM\-009](#case-bm-009), [BM\-HW\-005](#case-bm-hw-005) |
| `UC-BM-04` | Figure 5d | BMG Administrator | Facilities \(BMG\) & Hardware | Configure and trigger rotation schedules | [BM\-009](#case-bm-009), [BM\-011](#case-bm-011) |
| `UC-BM-05` | Figure 5d | Commissioned Hardware Operator \(Explicit facilities\.hardware\.manage Grant\) | Facilities \(BMG\) & Hardware | Commission hardware controllers and issue device commands | [BM\-010](#case-bm-010), [BM\-011](#case-bm-011), [BM\-HW\-001](#case-bm-hw-001), [BM\-HW\-002](#case-bm-hw-002), [BM\-HW\-003](#case-bm-hw-003), [BM\-HW\-004](#case-bm-hw-004), [BM\-HW\-005](#case-bm-hw-005) |
| `UC-BM-06` | Figure 5d | Commissioned Hardware Operator \(Explicit facilities\.hardware\.manage Grant\) | Facilities \(BMG\) & Hardware | Build and authorize USB firmware provisioning | [BM\-012](#case-bm-012) |
| `UC-TW-01` | Figure 5d | Department Workstation Member \(Clinic, Guidance & BMG\) | Team Workspace \(Web & Mobile\) | Manage operational Team tasks and checklists \(web and mobile\) | [TW\-WEB\-001](#case-tw-web-001), [TW\-WEB\-002](#case-tw-web-002), [TW\-WEB\-003](#case-tw-web-003), [TW\-WEB\-004](#case-tw-web-004), [TW\-WEB\-005](#case-tw-web-005), [TW\-MOB\-001](#case-tw-mob-001), [TW\-MOB\-002](#case-tw-mob-002), [TW\-MOB\-003](#case-tw-mob-003), [TW\-MOB\-004](#case-tw-mob-004), [TW\-MOB\-005](#case-tw-mob-005), [TW\-MOB\-006](#case-tw-mob-006), [TW\-MOB\-007](#case-tw-mob-007), [TW\-MOB\-008](#case-tw-mob-008), [UX\-WEB\-006](#case-ux-web-006) |
| `UC-TW-02` | Figure 5d | Department Workstation Member \(Clinic, Guidance & BMG\) | Team Workspace \(Web & Mobile\) | Schedule and cancel Team calendar events \(web and mobile\) | [TW\-WEB\-006](#case-tw-web-006), [TW\-WEB\-007](#case-tw-web-007), [TW\-WEB\-008](#case-tw-web-008), [TW\-MOB\-009](#case-tw-mob-009), [TW\-MOB\-010](#case-tw-mob-010), [TW\-MOB\-011](#case-tw-mob-011), [UX\-WEB\-006](#case-ux-web-006) |
| `UC-TW-03` | Figure 5d | Department Workstation Member \(Clinic, Guidance & BMG\) | Team Workspace \(Web & Mobile\) | Send Team chat messages with mentions and backread \(web and mobile\) | [TW\-WEB\-009](#case-tw-web-009), [TW\-WEB\-010](#case-tw-web-010), [TW\-WEB\-011](#case-tw-web-011), [TW\-WEB\-012](#case-tw-web-012), [RT\-007](#case-rt-007), [TW\-MOB\-012](#case-tw-mob-012), [TW\-MOB\-013](#case-tw-mob-013), [TW\-MOB\-014](#case-tw-mob-014), [TW\-MOB\-015](#case-tw-mob-015), [TW\-MOB\-016](#case-tw-mob-016), [UX\-WEB\-006](#case-ux-web-006) |
| `UC-AD-01` | Figure 5e | Report Viewer | Reporting & Audit Trail | View operational dashboards and aggregate reports | [AD\-001](#case-ad-001), [AD\-012](#case-ad-012) |
| `UC-AD-02` | Figure 5e | Report Viewer | Reporting & Audit Trail | Export bounded operational CSV and PDF reports | [AD\-010](#case-ad-010), [AD\-011](#case-ad-011), [AD\-012](#case-ad-012) |
| `UC-AD-03` | Figure 5e | Audit Reader | Reporting & Audit Trail | Filter audit explorer and verify hash\-chain integrity | [AD\-001](#case-ad-001), [AD\-002](#case-ad-002), [AD\-003](#case-ad-003) |
| `UC-AD-04` | Figure 5e | Audit Reader | Archive Governance | Inspect Archive Center, retention policies and legal holds \(read\-only\) | [ARV\-001](#case-arv-001) |
| `UC-AD-05` | Figure 5e | Clinic Administrator | Archive Governance | Archive and restore scoped department and Team records | [ARV\-002](#case-arv-002), [ARV\-003](#case-arv-003), [ARV\-004](#case-arv-004), [ARV\-007](#case-arv-007) |
| `UC-AD-06` | Figure 5e | Clinic Administrator | Student Intern Governance | Manage scoped student intern placements, supervision and shift clocks | [INT\-001](#case-int-001), [INT\-002](#case-int-002), [INT\-003](#case-int-003), [INT\-004](#case-int-004) |
| `UC-AD-07` | Figure 5e | Clinic Administrator | Administration, Access & Platform | Manage scoped employee access and MIS directory sync | [AD\-005](#case-ad-005), [AD\-006](#case-ad-006) |
| `UC-AD-08` | Figure 5e | Platform Owner \(Superadmin\) | Administration, Access & Platform | Manage platform\-wide roles, sign\-in carousel and break\-glass login | [AU\-011](#case-au-011), [AD\-006](#case-ad-006), [AD\-007](#case-ad-007), [AD\-009](#case-ad-009), [AD\-OPS\-001](#case-ad-ops-001) |
| `UC-AD-09` | Figure 5e | Platform Owner \(Superadmin\) | Archive Governance | Configure institutional archive retention policies and global legal holds | [ARV\-005](#case-arv-005), [ARV\-006](#case-arv-006), [ARV\-007](#case-arv-007), [ARV\-008](#case-arv-008) |
| `UC-AD-10` | Figure 5e | Platform Owner \(Superadmin\) | AI Insights | Configure AI providers and department AI grants | [AI\-001](#case-ai-001), [AI\-002](#case-ai-002), [AI\-003](#case-ai-003), [AI\-004](#case-ai-004), [AI\-011](#case-ai-011) |
| `UC-AI-01` | Figure 5e | Unit Staff under Explicit AI Grant \(ai\.insights\.\*\) | AI Insights | Prepare, generate and recover aggregate AI insights | [AI\-005](#case-ai-005), [AI\-006](#case-ai-006), [AI\-007](#case-ai-007), [AI\-008](#case-ai-008), [AI\-REC\-001](#case-ai-rec-001), [AI\-REC\-002](#case-ai-rec-002), [AI\-REC\-003](#case-ai-rec-003) |
| `UC-AI-02` | Figure 5e | Unit Staff under Explicit AI Grant \(ai\.insights\.\*\) | AI Insights | Review, approve and read approved AI insights | [AI\-009](#case-ai-009), [AI\-010](#case-ai-010), [AI\-REC\-004](#case-ai-rec-004) |
| `UC-PT-01` | Figure 5f | Student | Personal Portal & Self\-Service | Request and cancel personal appointments | [PT\-001](#case-pt-001), [PT\-002](#case-pt-002), [PT\-004](#case-pt-004), [PT\-012](#case-pt-012), [MB\-005](#case-mb-005) |
| `UC-PT-02` | Figure 5f | Student | Personal Portal & Self\-Service | View personal queue position and visit history | [PT\-001](#case-pt-001), [PT\-005](#case-pt-005), [PT\-006](#case-pt-006), [RT\-006](#case-rt-006), [MB\-006](#case-mb-006), [GD\-API\-001](#case-gd-api-001) |
| `UC-PT-03` | Figure 5f | Student | Personal Portal & Self\-Service | Read announcements and answer Guidance surveys | [PT\-009](#case-pt-009), [PT\-010](#case-pt-010), [MB\-008](#case-mb-008), [MB\-009](#case-mb-009) |
| `UC-PT-04` | Figure 5f | Student | Personal Portal & Self\-Service | Acknowledge Guidance call slips \(web and mobile API\) | [GD\-CALL\-002](#case-gd-call-002), [GD\-API\-002](#case-gd-api-002) |
| `UC-PT-05` | Figure 5f | Student | Personal Portal & Self\-Service | Complete Multiple Intelligences assessment and download PDF | [GD\-MI\-002](#case-gd-mi-002), [GD\-MI\-003](#case-gd-mi-003), [GD\-MI\-004](#case-gd-mi-004), [GD\-MI\-005](#case-gd-mi-005), [GD\-MI\-006](#case-gd-mi-006), [GD\-API\-004](#case-gd-api-004) |
| `UC-PT-06` | Figure 5f | Student | Personal Portal & Self\-Service | Redeem and submit pseudonymous service evaluation \(PEF\-008 / CEF\-009\) | [GD\-OGC\-004](#case-gd-ogc-004), [GD\-EVL\-002](#case-gd-evl-002), [GD\-EVL\-003](#case-gd-evl-003), [GD\-API\-005](#case-gd-api-005) |
| `UC-PT-07` | Figure 5f | Student | Mobile Application & Platform | Use mobile personal application, server binding and push notifications | [MB\-001](#case-mb-001), [MB\-002](#case-mb-002), [MB\-003](#case-mb-003), [MB\-004](#case-mb-004), [MB\-005](#case-mb-005), [MB\-006](#case-mb-006), [MB\-007](#case-mb-007), [MB\-008](#case-mb-008), [MB\-009](#case-mb-009), [MB\-010](#case-mb-010), [MB\-012](#case-mb-012), [MB\-013](#case-mb-013), [MB\-PER\-001](#case-mb-per-001), [MB\-PLAT\-001](#case-mb-plat-001), [MB\-PLAT\-002](#case-mb-plat-002), [MB\-PLAT\-003](#case-mb-plat-003), [MB\-PLAT\-004](#case-mb-plat-004), [MB\-PLAT\-005](#case-mb-plat-005), [MB\-PLAT\-006](#case-mb-plat-006), [MB\-PLAT\-007](#case-mb-plat-007), [MB\-PLAT\-008](#case-mb-plat-008), [GD\-API\-001](#case-gd-api-001), [GD\-API\-006](#case-gd-api-006) |
| `UC-PT-08` | Figure 5f | Employee | Personal Portal & Self\-Service | Submit and withdraw multi\-student Guidance General Referral \(GGR\-005\) | [GD\-INT\-001](#case-gd-int-001), [GD\-INT\-002](#case-gd-int-002), [GD\-OGC\-002](#case-gd-ogc-002), [RF\-004](#case-rf-004), [GD\-API\-003](#case-gd-api-003) |
| `UC-RF-01` | Figure 5f | Clinic Staff | Interdepartmental Referrals | Create an interdepartmental referral \(Clinic \<\-\> Guidance\) | [RF\-001](#case-rf-001), [RF\-002](#case-rf-002) |
| `UC-RF-02` | Figure 5f | Department Workstation Member \(Clinic, Guidance & BMG\) | Interdepartmental Referrals | Acknowledge and review a received referral | [RF\-005](#case-rf-005), [RF\-006](#case-rf-006), [RF\-010](#case-rf-010) |
| `UC-RF-03` | Figure 5f | Department Workstation Member \(Clinic, Guidance & BMG\) | Interdepartmental Referrals | Hand off a referral to a Clinic encounter or Guidance case/intake | [GD\-INT\-007](#case-gd-int-007), [RF\-007](#case-rf-007), [RF\-008](#case-rf-008) |
| `UC-RF-04` | Figure 5f | Department Workstation Member \(Clinic, Guidance & BMG\) | Interdepartmental Referrals | Close a reviewed referral into history | [RF\-009](#case-rf-009) |

## Table of contents

1. [Public / Unauthenticated User (5)](#1-public-unauthenticated-user)
   - [Authentication, Session & Privacy \(4 cases\)](#authentication-session-privacy-4-cases)
     - [AU\-003 — Invalid credentials establish no session](#case-au-003)
     - [AU\-005 — Login throttling is a temporary rate limit](#case-au-005)
     - [AU\-013 — Public Data Privacy Policy route serves institutional notice without login](#case-au-013)
     - [AU\-014 — Sign\-in Data Privacy Policy modal and clinical RA 10173 consent links stay accessible](#case-au-014)
   - [Guidance & Counseling \(1 case\)](#guidance-counseling-1-case)
     - [GD\-FRM\-007 — Public verification reveals only non\-PII authenticity](#case-gd-frm-007)
2. [All Authenticated Roles \(Shared\) (30)](#2-all-authenticated-roles-shared)
   - [Authentication, Session & Privacy \(5 cases\)](#authentication-session-privacy-5-cases)
     - [AU\-004 — Wrong workstation host clears a valid login](#case-au-004)
     - [AU\-006 — Confirmed logout anonymizes the current host session](#case-au-006)
     - [AU\-007 — Authentication\-version change revokes open sessions](#case-au-007)
     - [AU\-008 — CSRF rejects a forged web mutation](#case-au-008)
     - [AU\-012 — Host\-only cookies prevent cross\-workstation session reuse](#case-au-012)
   - [Personal Portal & Self\-Service \(6 cases\)](#personal-portal-self-service-6-cases)
     - [PT\-007 — Profile photo upload and removal affect only the caller](#case-pt-007)
     - [PT\-008 — Emergency contact update persists for the caller](#case-pt-008)
     - [PT\-PRF\-001 — Current contact and residence update without changing MIS identity](#case-pt-prf-001)
     - [PT\-PRF\-002 — Profile photo validation preserves the prior image on failure](#case-pt-prf-002)
     - [PT\-PRF\-003 — Official university profile fields remain read\-only](#case-pt-prf-003)
     - [PT\-PRF\-004 — No password\-management UI is exposed](#case-pt-prf-004)
   - [Realtime & Notifications \(14 cases\)](#realtime-notifications-14-cases)
     - [PT\-011 — Notification inbox read state is account\-owned](#case-pt-011)
     - [RT\-001 — Web realtime ticket grants only server\-derived channels](#case-rt-001)
     - [RT\-002 — Expired realtime ticket and grant disconnect cleanly](#case-rt-002)
     - [RT\-003 — Tampered or malformed realtime tickets fail identically](#case-rt-003)
     - [RT\-004 — Origin binding rejects cross\-origin use](#case-rt-004)
     - [RT\-008 — Reconciliation repairs disconnect gaps](#case-rt-008)
     - [RT\-009 — Private channel isolation survives selector and publisher abuse](#case-rt-009)
     - [RT\-010 — Mobile grant is own\-channel only and rate bounded](#case-rt-010)
     - [RT\-NT\-001 — Inbox and history share current recipient authority](#case-rt-nt-001)
     - [RT\-NT\-002 — Mark\-read operations cannot cross recipient scope](#case-rt-nt-002)
     - [RT\-NT\-003 — Notification targets are an allowlisted projection](#case-rt-nt-003)
     - [RT\-NT\-004 — Notification invalidation is post\-commit, authorized, and bounded](#case-rt-nt-004)
     - [RT\-NT\-005 — Named\-region refresh preserves edits or fails safely](#case-rt-nt-005)
     - [RT\-NT\-006 — Realtime lifecycle pauses, backs off, and clears private state](#case-rt-nt-006)
   - [Responsive Web Contracts \(5 cases\)](#responsive-web-contracts-5-cases)
     - [UX\-WEB\-001 — Application shell has one accessible drawer breakpoint](#case-ux-web-001)
     - [UX\-WEB\-002 — Header, title, breadcrumbs, actions, and dropdowns reflow safely](#case-ux-web-002)
     - [UX\-WEB\-003 — Dense tables become labelled mobile cards without losing controls](#case-ux-web-003)
     - [UX\-WEB\-007 — Personal portal shell avoids stale\-page flash and preserves navigation](#case-ux-web-007)
     - [UX\-WEB\-008 — Responsive behavior preserves accessibility, theme, and security boundaries](#case-ux-web-008)
3. [Student (45)](#3-student)
   - [Authentication, Session & Privacy \(2 cases\)](#authentication-session-privacy-2-cases)
     - [AU\-001 — Student signs in to the personal portal](#case-au-001)
     - [AU\-010 — MIS outage fails closed without local fallback](#case-au-010)
   - [Personal Portal & Self\-Service \(20 cases\)](#personal-portal-self-service-20-cases)
     - [PT\-001 — Personal portal home presents owned summaries](#case-pt-001)
     - [PT\-002 — Personal appointment submission creates a request, not a reservation](#case-pt-002)
     - [PT\-004 — Requester cancels an eligible own appointment with a reason](#case-pt-004)
     - [PT\-005 — Personal queue card reports current position](#case-pt-005)
     - [PT\-006 — Visit history is nonclinical attendance metadata](#case-pt-006)
     - [PT\-009 — Announcement read tracking is per account](#case-pt-009)
     - [PT\-010 — Published survey accepts one owned response](#case-pt-010)
     - [PT\-012 — Personal portal identifiers cannot select another person's records](#case-pt-012)
     - [GD\-CALL\-002 — Target student can acknowledge only their own call slip](#case-gd-call-002)
     - [GD\-MI\-002 — Assigned person saves an encrypted partial draft](#case-gd-mi-002)
     - [GD\-MI\-003 — Finalization requires all 80 answers and account attestation](#case-gd-mi-003)
     - [GD\-MI\-004 — Results show all eight domains without diagnosis or top\-three truncation](#case-gd-mi-004)
     - [GD\-MI\-005 — MI PDF matches authorized result data](#case-gd-mi-005)
     - [GD\-MI\-006 — Assessment ownership and tenant boundaries fail closed](#case-gd-mi-006)
     - [GD\-EVL\-002 — One\-time token submits an unlinkable pseudonymous evaluation](#case-gd-evl-002)
     - [GD\-EVL\-003 — Invalid, expired, and malformed evaluation submissions fail unchanged](#case-gd-evl-003)
     - [GD\-API\-001 — Student dashboard is own\-scope and clinically redacted](#case-gd-api-001)
     - [GD\-API\-002 — Call\-slip acknowledgement is owner\-bound and transition\-safe](#case-gd-api-002)
     - [GD\-API\-004 — MI draft, finalization, and results are own\-assignment contracts](#case-gd-api-004)
     - [GD\-API\-005 — Evaluation submission requires eligible one\-time redemption token](#case-gd-api-005)
   - [Realtime & Notifications \(1 case\)](#realtime-notifications-1-case)
     - [RT\-006 — Personal queue projection repairs without exposing others](#case-rt-006)
   - [Mobile Application & Platform \(22 cases\)](#mobile-application-platform-22-cases)
     - [MB\-001 — Student sign\-in bootstraps authoritative profile](#case-mb-001)
     - [MB\-002 — Concurrent 401s share one silent refresh](#case-mb-002)
     - [MB\-003 — Refresh replay revokes the entire token family](#case-mb-003)
     - [MB\-004 — Home dashboard shows only authorized personal summaries](#case-mb-004)
     - [MB\-005 — Appointment request and cancellation preserve request semantics](#case-mb-005)
     - [MB\-006 — Queue board is an own\-ticket projection](#case-mb-006)
     - [MB\-007 — Mobile records are a minimal health profile, not visit history](#case-mb-007)
     - [MB\-008 — Announcement targeting and read state match web authority](#case-mb-008)
     - [MB\-009 — Survey submission is identified, single, and audience\-scoped](#case-mb-009)
     - [MB\-010 — Notification read state and deep links reconcile with server](#case-mb-010)
     - [MB\-012 — Realtime hints invalidate only active account caches](#case-mb-012)
     - [MB\-013 — Sign\-out revokes server family and clears local private state](#case-mb-013)
     - [MB\-PER\-001 — Personal entitlement and account/server isolation are authoritative](#case-mb-per-001)
     - [MB\-PLAT\-001 — API runtime binds every request to one server and account generation](#case-mb-plat-001)
     - [MB\-PLAT\-002 — Server switching closes the old environment before mounting new data](#case-mb-plat-002)
     - [MB\-PLAT\-003 — Android notification onboarding prompts once at the correct lifecycle](#case-mb-plat-003)
     - [MB\-PLAT\-004 — LAN push capability handshake precedes token disclosure](#case-mb-plat-004)
     - [MB\-PLAT\-005 — Push activation is bound to deployment, installation, API family, and account](#case-mb-plat-005)
     - [MB\-PLAT\-006 — Push revocation is exact, idempotent, and race\-safe](#case-mb-plat-006)
     - [MB\-PLAT\-007 — Push target handling is typed, current\-owner, and fail\-safe](#case-mb-plat-007)
     - [MB\-PLAT\-008 — Android system insets, keyboard, and reduced motion preserve operability](#case-mb-plat-008)
     - [GD\-API\-006 — Workstation\-only Guidance actions always return 403 on mobile API](#case-gd-api-006)
4. [Employee (5)](#4-employee)
   - [Authentication, Session & Privacy \(1 case\)](#authentication-session-privacy-1-case)
     - [AU\-002 — Employee signs in to the personal portal](#case-au-002)
   - [Personal Portal & Self\-Service \(4 cases\)](#personal-portal-self-service-4-cases)
     - [GD\-INT\-001 — Employee portal resolves only exact active students; Guidance resolver remains staff\-scoped](#case-gd-int-001)
     - [GD\-INT\-002 — Employee multi\-student referral fans out encrypted intake records atomically](#case-gd-int-002)
     - [RF\-004 — General referrer gains no clinical\-record access](#case-rf-004)
     - [GD\-API\-003 — Retired mobile self\-referral endpoint remains unavailable](#case-gd-api-003)
5. [Department Workstation Member \(Clinic, Guidance & BMG\) (39)](#5-department-workstation-member-clinic-guidance-bmg)
   - [Clinic Operations & Extensions \(2 cases\)](#clinic-operations-extensions-2-cases)
     - [PT\-003 — Staff confirmation assigns a provider and detects stale or occupied slots](#case-pt-003)
     - [PT\-SCH\-001 — Scheduling uses staff assignments and Manila wall time](#case-pt-sch-001)
   - [Interdepartmental Referrals \(4 cases\)](#interdepartmental-referrals-4-cases)
     - [RF\-005 — Receiving unit acknowledges a submitted referral](#case-rf-005)
     - [RF\-006 — Receiving unit starts review without creating a target record](#case-rf-006)
     - [RF\-009 — Reviewed or linked referral closes into history](#case-rf-009)
     - [RF\-010 — Referral filters use current referral statuses and units](#case-rf-010)
   - [Team Workspace \(Web & Mobile\) \(28 cases\)](#team-workspace-web-mobile-28-cases)
     - [TW\-WEB\-001 — Workstation membership isolates Team Workspace by tenant and unit](#case-tw-web-001)
     - [TW\-WEB\-002 — Member creates an operational task with bounded checklist](#case-tw-web-002)
     - [TW\-WEB\-003 — Task editing and moves enforce editor policy and optimistic version](#case-tw-web-003)
     - [TW\-WEB\-004 — Task comments persist with bounded privacy\-safe activity](#case-tw-web-004)
     - [TW\-WEB\-005 — API idempotency produces one web\-visible task or comment](#case-tw-web-005)
     - [TW\-WEB\-006 — Calendar views preserve bounded filters and task deadlines](#case-tw-web-006)
     - [TW\-WEB\-007 — Event creation validates participants and Manila interval](#case-tw-web-007)
     - [TW\-WEB\-008 — Event edit and terminal cancellation enforce organizer/manager and version](#case-tw-web-008)
     - [TW\-WEB\-009 — Chat sends immutable message with task links and reply](#case-tw-web-009)
     - [TW\-WEB\-010 — Chat nonce replay is idempotent and changed reuse conflicts](#case-tw-web-010)
     - [TW\-WEB\-011 — Validated mention delivers one generic account\-owned notification](#case-tw-web-011)
     - [TW\-WEB\-012 — Chat history pagination, auto\-backread, and read cursor reconcile](#case-tw-web-012)
     - [TW\-MOB\-001 — \`/me\` derives workspace units and features from fresh authority](#case-tw-mob-001)
     - [TW\-MOB\-002 — Unit path is the immutable Team scope](#case-tw-mob-002)
     - [TW\-MOB\-003 — Context switching protects dirty drafts and pending submissions](#case-tw-mob-003)
     - [TW\-MOB\-004 — Revoked context disposes only its own drafts and locks](#case-tw-mob-004)
     - [TW\-MOB\-005 — Task list is bounded, filtered, and unit\-scoped](#case-tw-mob-005)
     - [TW\-MOB\-006 — Task creation is idempotent per actor/unit/identical request](#case-tw-mob-006)
     - [TW\-MOB\-007 — Task updates and status moves use optimistic version](#case-tw-mob-007)
     - [TW\-MOB\-008 — Task comments are idempotent and bounded](#case-tw-mob-008)
     - [TW\-MOB\-009 — Calendar combines events and live task deadlines in Manila views](#case-tw-mob-009)
     - [TW\-MOB\-010 — Event create uses Manila wall time, eligible participants, and nonce](#case-tw-mob-010)
     - [TW\-MOB\-011 — Event update/cancel enforce edit authority and version](#case-tw-mob-011)
     - [TW\-MOB\-012 — Chat history cursors are bounded and mutually exclusive](#case-tw-mob-012)
     - [TW\-MOB\-013 — Chat send is nonce\-idempotent with validated references](#case-tw-mob-013)
     - [TW\-MOB\-014 — Chat read marker is monotonic and account\-scoped](#case-tw-mob-014)
     - [TW\-MOB\-015 — Realtime invalidation refetches the exact Team unit resources](#case-tw-mob-015)
     - [TW\-MOB\-016 — Notification intent rechecks feature and identity after navigation commits](#case-tw-mob-016)
   - [Realtime & Notifications \(2 cases\)](#realtime-notifications-2-cases)
     - [RT\-005 — Queue board refreshes from authoritative GET after a hint](#case-rt-005)
     - [RT\-007 — Team mention notification targets exact current member](#case-rt-007)
   - [Mobile Application & Platform \(1 case\)](#mobile-application-platform-1-case)
     - [MB\-011 — Staff queue monitor is entitlement\-gated and read\-only](#case-mb-011)
   - [Responsive Web Contracts \(2 cases\)](#responsive-web-contracts-2-cases)
     - [UX\-WEB\-005 — Appointment and availability workspaces preserve complete form operation](#case-ux-web-005)
     - [UX\-WEB\-006 — Teams board, chat, and calendar adapt without semantic drift](#case-ux-web-006)
6. [Clinic Intern (9)](#6-clinic-intern)
   - [Clinic Operations & Extensions \(9 cases\)](#clinic-operations-extensions-9-cases)
     - [CL\-001 — Clinic dashboard uses the current Manila business day](#case-cl-001)
     - [CL\-002 — Patient registry search is bounded and tenant\-scoped](#case-cl-002)
     - [CL\-005 — Encounter start requires a called Clinic ticket](#case-cl-005)
     - [CL\-006 — Vitals save advances an open encounter](#case-cl-006)
     - [CL\-007 — Clinical draft survives reload until sealing](#case-cl-007)
     - [CL\-011 — Walk\-in queue follows called\-to\-consultation lifecycle](#case-cl-011)
     - [CL\-012 — Skip, recall, and no\-show obey queue transition rules](#case-cl-012)
     - [CL\-CORE\-001 — One patient cannot acquire concurrent open Clinic visits](#case-cl-core-001)
     - [CL\-RBAC\-002 — Intern cannot seal, add addenda, or write inventory](#case-cl-rbac-002)
7. [Clinic Staff (19)](#7-clinic-staff)
   - [Clinic Operations & Extensions \(17 cases\)](#clinic-operations-extensions-17-cases)
     - [CL\-003 — Patient chart separates authorized clinical histories](#case-cl-003)
     - [CL\-004 — Health profile update is permission\-scoped](#case-cl-004)
     - [CL\-008 — Dispensation deducts usable stock once with ledger allocation](#case-cl-008)
     - [CL\-009 — Insufficient usable stock rejects the entire dispensation](#case-cl-009)
     - [CL\-010 — Encounter completion seals clinical state](#case-cl-010)
     - [CL\-013 — Confirmed same\-day appointment checks in to one queue ticket](#case-cl-013)
     - [CL\-014 — Inventory receipt and adjustment are append\-only operations](#case-cl-014)
     - [CL\-EXT\-001 — Signed addendum appends to a sealed encounter](#case-cl-ext-001)
     - [CL\-EXT\-002 — Delayed lab result is linked as post\-visit evidence](#case-cl-ext-002)
     - [CL\-EXT\-003 — Enrollment clearance records and certificate stay patient\-scoped](#case-cl-ext-003)
     - [CL\-ISO\-001 — Isolation tracker records an authorized case](#case-cl-iso-001)
     - [CL\-ISO\-002 — Isolation clearance preserves history](#case-cl-iso-002)
     - [CL\-RPT\-001 — Clinic summary report obeys period and tenant scope](#case-cl-rpt-001)
     - [CL\-RPT\-002 — Clinic PDF report matches the on\-screen summary](#case-cl-rpt-002)
     - [CL\-INV\-001 — Inventory idempotency replays identical operations only](#case-cl-inv-001)
     - [CL\-INV\-002 — Quarantine and expiry remove batches from usable stock](#case-cl-inv-002)
     - [CL\-INV\-003 — Dispensation correction requires manager authority and explicit custody mode](#case-cl-inv-003)
   - [Interdepartmental Referrals \(2 cases\)](#interdepartmental-referrals-2-cases)
     - [RF\-001 — Clinic explicitly creates a referral to Guidance](#case-rf-001)
     - [RF\-007 — Clinic target handoff explicitly creates or links an open encounter](#case-rf-007)
8. [Clinic Physician (1)](#8-clinic-physician)
   - [Clinic Operations & Extensions \(1 case\)](#clinic-operations-extensions-1-case)
     - [CL\-RBAC\-001 — Physician authority includes sealing but remains grant\-driven](#case-cl-rbac-001)
9. [Clinic Administrator (3)](#9-clinic-administrator)
   - [Clinic Operations & Extensions \(1 case\)](#clinic-operations-extensions-1-case-2)
     - [CL\-ANN\-001 — Clinic administrator publishes and manages scoped Clinic announcements](#case-cl-ann-001)
   - [Archive Governance \(1 case\)](#archive-governance-1-case)
     - [ARV\-002 — Clinic administrator archives and restores only Clinic and Clinic Team records](#case-arv-002)
   - [Student Intern Governance \(1 case\)](#student-intern-governance-1-case)
     - [INT\-001 — Clinic administrator manages only Clinic student intern placements](#case-int-001)
10. [Guidance Intern (5)](#10-guidance-intern)
   - [Guidance & Counseling \(5 cases\)](#guidance-counseling-5-cases)
     - [GD\-001 — Guidance dashboard respects caseload and current\-day scope](#case-gd-001)
     - [GD\-INT\-003 — Staff creates intake on behalf of an identified subject](#case-gd-int-003)
     - [GD\-INT\-004 — Intake receive and triage preserve hint\-versus\-risk distinction](#case-gd-int-004)
     - [GD\-REC\-001 — Clients directory lists existing same\-tenant people only](#case-gd-rec-001)
     - [INT\-005 — Guidance intern operates queue and co\-sign drafts without final clinical authority](#case-int-005)
11. [Counsellor (34)](#11-counsellor)
   - [Guidance & Counseling \(31 cases\)](#guidance-counseling-31-cases)
     - [GD\-002 — New counselling case is owned by the assigned counsellor](#case-gd-002)
     - [GD\-003 — Session draft finalizes to an encrypted immutable note](#case-gd-003)
     - [GD\-004 — Case access distinguishes owner, accepted consultant, and unrelated counsellor](#case-gd-004)
     - [GD\-006 — Follow\-up completion stores encrypted completion notes](#case-gd-006)
     - [GD\-007 — Case closure requires and encrypts a disposition](#case-gd-007)
     - [GD\-008 — Guidance queue starts sessions only from called tickets](#case-gd-008)
     - [GD\-INT\-005 — Accepted intake links to a matching case explicitly](#case-gd-int-005)
     - [GD\-INT\-006 — Decline, withdraw, and external handoff are terminal controlled paths](#case-gd-int-006)
     - [GD\-INT\-007 — Clinic referral bridge creates one Guidance intake link](#case-gd-int-007)
     - [GD\-PRC\-003 — Direct finalization requires active licensed or supervisor standing](#case-gd-prc-003)
     - [GD\-REC\-002 — Cumulative record loads independently paginated authorized histories](#case-gd-rec-002)
     - [GD\-REC\-003 — Record histories honor ownership, consultation, read\-any, and section grants](#case-gd-rec-003)
     - [GD\-REC\-004 — Confidential record read fails closed when audit cannot append](#case-gd-rec-004)
     - [GD\-FRM\-002 — Controlled form creation validates the complete context graph](#case-gd-frm-002)
     - [GD\-FRM\-003 — Encrypted draft autosave replaces one mutable draft](#case-gd-frm-003)
     - [GD\-FRM\-004 — Finalization freezes an immutable revision and attestation](#case-gd-frm-004)
     - [GD\-FRM\-006 — Issued controlled document has authorized preview and print boundary](#case-gd-frm-006)
     - [GD\-FRM\-008 — Form read audit contains metadata but no plaintext](#case-gd-frm-008)
     - [GD\-EPI\-001 — Episode opens with explicit matching links and qualified primary counsellor](#case-gd-epi-001)
     - [GD\-EPI\-002 — Episode detail exposes only authorized exact\-linked records](#case-gd-epi-002)
     - [GD\-EPI\-003 — Episode closure encrypts notes and blocks later transfer](#case-gd-epi-003)
     - [GD\-CALL\-001 — Qualified practitioner issues a numbered controlled call slip](#case-gd-call-001)
     - [GD\-CLN\-001 — Clinical form authoring validates practitioner, permission, and context](#case-gd-cln-001)
     - [GD\-OGC\-001 — PIF\-008, PCF\-008, and CCF\-009 conform verbatim to official OGC clinical schemas](#case-gd-ogc-001)
     - [GD\-OGC\-002 — General Referral GGR\-005 matches all 21 official referral reasons and OGC actions](#case-gd-ogc-002)
     - [GD\-OGC\-003 — Appointment GAP\-006 and Call Slip CSP\-007 match official schedules, 6 purposes, and layout](#case-gd-ogc-003)
     - [GD\-OGC\-004 — Evaluation forms PEF\-008 and CEF\-009 match verbatim 1–5 items, visit types, and future\-visit reason](#case-gd-ogc-004)
     - [GD\-MI\-001 — Counsellor assigns one active Multiple Intelligence assessment](#case-gd-mi-001)
     - [GD\-EVL\-001 — Qualified Guidance practitioner issues a one\-time evaluation token through production workflow](#case-gd-evl-001)
     - [GD\-EVL\-005 — Aggregate reporting enforces cohort threshold and provider scope](#case-gd-evl-005)
     - [GD\-EVL\-006 — Aggregate PDF preserves threshold\-safe output](#case-gd-evl-006)
   - [Interdepartmental Referrals \(2 cases\)](#interdepartmental-referrals-2-cases-2)
     - [RF\-002 — Guidance explicitly creates a referral to Clinic](#case-rf-002)
     - [RF\-008 — Guidance target handoff explicitly creates or links a case](#case-rf-008)
   - [Responsive Web Contracts \(1 case\)](#responsive-web-contracts-1-case)
     - [UX\-WEB\-004 — Guidance case and operations workspaces stack without losing state](#case-ux-web-004)
12. [Guidance Supervisor (16)](#12-guidance-supervisor)
   - [Guidance & Counseling \(16 cases\)](#guidance-counseling-16-cases)
     - [GD\-005 — Read\-any oversight is audited and does not grant ownership](#case-gd-005)
     - [GD\-009 — Question bank revision can be previewed and archived](#case-gd-009)
     - [GD\-010 — Survey copies bank revisions and publishes to its audience](#case-gd-010)
     - [GD\-011 — Survey response review and export remain bounded](#case-gd-011)
     - [GD\-012 — Guidance announcement publishing reaches only its audience](#case-gd-012)
     - [GD\-GOV\-001 — Activation gate remains disabled until every required authority signs](#case-gd-gov-001)
     - [GD\-GOV\-002 — Unmet governance gates block gated production workflows](#case-gd-gov-002)
     - [GD\-GOV\-003 — Guardian digital attestation remains blocked in v1](#case-gd-gov-003)
     - [GD\-PRC\-001 — Practitioner profile records tier, scope, and standing](#case-gd-prc-001)
     - [GD\-PRC\-002 — Associate supervision is current and self\-supervision is forbidden](#case-gd-prc-002)
     - [GD\-PRC\-004 — Associate\-authored record requires current supervisor co\-sign](#case-gd-prc-004)
     - [GD\-FRM\-001 — Canonical form manifest sync exposes reviewed active definitions](#case-gd-frm-001)
     - [GD\-FRM\-005 — Associate form follows pending co\-sign state](#case-gd-frm-005)
     - [GD\-CLN\-002 — Clinical co\-sign queue uses current supervision and immutable finalization](#case-gd-cln-002)
     - [GD\-CLN\-003 — Consultation, full transfer, and manager reassignment retain distinct authority](#case-gd-cln-003)
     - [GD\-EVL\-004 — Moderation approves or redacts qualitative comments](#case-gd-evl-004)
13. [Guidance Administrator (2)](#13-guidance-administrator)
   - [Archive Governance \(1 case\)](#archive-governance-1-case-2)
     - [ARV\-003 — Guidance administrator manages Guidance archives and legal holds without crossing units](#case-arv-003)
   - [Student Intern Governance \(1 case\)](#student-intern-governance-1-case-2)
     - [INT\-002 — Guidance administrator assigns a Guidance intern while preserving student identity](#case-int-002)
14. [BMG Intern (2)](#14-bmg-intern)
   - [Facilities \(BMG\) & Hardware \(2 cases\)](#facilities-bmg-hardware-2-cases)
     - [BM\-001 — Facilities dashboard reports recorded state, not physical state](#case-bm-001)
     - [INT\-006 — BMG intern logs supervised batch work with live shift clock and hardware boundaries](#case-int-006)
15. [Facilities Operator (6)](#15-facilities-operator)
   - [Facilities \(BMG\) & Hardware \(6 cases\)](#facilities-bmg-hardware-6-cases)
     - [BM\-003 — Start an active batch as bookkeeping only](#case-bm-003)
     - [BM\-004 — Reject a second active batch under contention](#case-bm-004)
     - [BM\-006 — Release a batch without certifying compost or motor state](#case-bm-006)
     - [BM\-007 — Reject terminal or stale batch transitions](#case-bm-007)
     - [BM\-008 — Cancel a batch with a required reason](#case-bm-008)
     - [BM\-013 — BMG analytics expose bounded recorded metrics and limitations](#case-bm-013)
16. [BMG Administrator (4)](#16-bmg-administrator)
   - [Facilities \(BMG\) & Hardware \(2 cases\)](#facilities-bmg-hardware-2-cases-2)
     - [BM\-002 — Register a unit with explicit capacity and disabled automation](#case-bm-002)
     - [BM\-009 — Maintenance status disables intent but is not a motor assertion](#case-bm-009)
   - [Archive Governance \(1 case\)](#archive-governance-1-case-3)
     - [ARV\-004 — BMG administrator archives and restores BMG units and Team items within unit scope](#case-arv-004)
   - [Student Intern Governance \(1 case\)](#student-intern-governance-1-case-3)
     - [INT\-003 — BMG intern placement auto\-activates, expires, and displays live shift clocks](#case-int-003)
17. [Report Viewer (3)](#17-report-viewer)
   - [Reporting & Audit Trail \(3 cases\)](#reporting-audit-trail-3-cases)
     - [AD\-010 — Operational CSV export is aggregate, bounded, and injection\-safe](#case-ad-010)
     - [AD\-011 — Operational PDF matches authorized aggregate source](#case-ad-011)
     - [AD\-012 — Report viewer cannot cross into audit authority](#case-ad-012)
18. [Audit Reader (4)](#18-audit-reader)
   - [Archive Governance \(1 case\)](#archive-governance-1-case-4)
     - [ARV\-001 — Audit reader inspects cross\-module Archive Center in strict read\-only mode](#case-arv-001)
   - [Reporting & Audit Trail \(3 cases\)](#reporting-audit-trail-3-cases-2)
     - [AD\-001 — Administrative dashboard enforces permission\-shaped data](#case-ad-001)
     - [AD\-002 — Audit explorer filters and cursor bounds](#case-ad-002)
     - [AD\-003 — Audit hash\-chain verification is scoped and qualified](#case-ad-003)
19. [Platform Owner \(Superadmin\) (16)](#19-platform-owner-superadmin)
   - [Archive Governance \(4 cases\)](#archive-governance-4-cases)
     - [ARV\-005 — Platform owner configures non\-purgeable institutional retention policies](#case-arv-005)
     - [ARV\-006 — Cross\-module legal holds block premature archive and sync with Guidance holds](#case-arv-006)
     - [ARV\-007 — Inline reversible archive and silent undo UX mask confidential details centrally](#case-arv-007)
     - [ARV\-008 — Immutable compliance and clinical ledgers reject archival and deletion](#case-arv-008)
   - [Student Intern Governance \(1 case\)](#student-intern-governance-1-case-4)
     - [INT\-004 — Superadmin revokes any unit placement and terminates active sessions immediately](#case-int-004)
   - [Administration, Access & Platform \(6 cases\)](#administration-access-platform-6-cases)
     - [AU\-011 — Provisioned break\-glass administrator can sign in locally](#case-au-011)
     - [AD\-005 — MIS synchronization is transactional and bounded](#case-ad-005)
     - [AD\-006 — Employee role assignment is scoped, versioned, and session\-revoking](#case-ad-006)
     - [AD\-007 — Sign\-in carousel changes are ordered, scoped, and safely published](#case-ad-007)
     - [AD\-009 — Role and permission catalogue reflects all 16 grants without implying assignability](#case-ad-009)
     - [AD\-OPS\-001 — Administrative mutations revalidate fresh authority and version](#case-ad-ops-001)
   - [AI Insights \(5 cases\)](#ai-insights-5-cases)
     - [AI\-001 — Provider discovery stages models without changing production settings](#case-ai-001)
     - [AI\-002 — Saving or testing settings pauses dispatch until explicit enable](#case-ai-002)
     - [AI\-003 — API key is write\-only and encrypted at rest](#case-ai-003)
     - [AI\-004 — Department AI grants are tenant/unit/role/permission scoped](#case-ai-004)
     - [AI\-011 — Disable stops new analytics while preserving truthful limits](#case-ai-011)
20. [Commissioned Hardware Operator \(Explicit facilities\.hardware\.manage Grant\) (8)](#20-commissioned-hardware-operator-explicit-facilities-hardware-manage-grant)
   - [Facilities \(BMG\) & Hardware \(8 cases\)](#facilities-bmg-hardware-8-cases)
     - [BM\-010 — Provision, command, acknowledge, and report a simulated controller](#case-bm-010)
     - [BM\-011 — Publish a Manila rotation schedule with effective gate separation](#case-bm-011)
     - [BM\-012 — Build and authorize private USB commissioning artifacts](#case-bm-012)
     - [BM\-HW\-001 — Device authentication, throttling, and maintenance fail closed](#case-bm-hw-001)
     - [BM\-HW\-002 — Heartbeat keeps omitted telemetry unknown](#case-bm-hw-002)
     - [BM\-HW\-003 — Run attribution and durable \`run\_uid\` deduplication](#case-bm-hw-003)
     - [BM\-HW\-004 — Pending commands cancel on authority or binding change](#case-bm-hw-004)
     - [BM\-HW\-005 — Archive and restore retire all operational bindings](#case-bm-hw-005)
21. [Unit Staff under Explicit AI Grant \(ai\.insights\.\*\) (10)](#21-unit-staff-under-explicit-ai-grant-ai-insights)
   - [AI Insights \(10 cases\)](#ai-insights-10-cases)
     - [AI\-005 — Generate requires explicit AI and source\-data authority](#case-ai-005)
     - [AI\-006 — Local summary is aggregate, privacy\-checked, and encrypted](#case-ai-006)
     - [AI\-007 — HTTP generation only queues a deduplicated job](#case-ai-007)
     - [AI\-008 — Worker dispatch is single\-attempt, leased, and provenance\-bound](#case-ai-008)
     - [AI\-009 — Review transitions are versioned and policy\-bound](#case-ai-009)
     - [AI\-010 — Read surface withholds unavailable and unapproved content](#case-ai-010)
     - [AI\-REC\-001 — Expired evidence recovers only if bitwise\-policy identical](#case-ai-rec-001)
     - [AI\-REC\-002 — Safe retry requires proof no outbound dispatch occurred](#case-ai-rec-002)
     - [AI\-REC\-003 — Ambiguous dispatch becomes terminal \`outcome\_unknown\`](#case-ai-rec-003)
     - [AI\-REC\-004 — Pipeline provenance blocks historical acceptance and completion](#case-ai-rec-004)

## Provenance

- The ordered section list, use-case catalog, case-count contract, target commit, legacy map, archive path, and output paths come from `papers/blackbox/suite.json` and `papers/blackbox/use-cases.json`.
- Validation requires traceability across all 23 declared patch commits and 100% bidirectional coverage across all 70 Use Cases (`UC-*`).
- Each test case appears exactly once under its primary **User Role -> Module -> Functionality** heading; UML role-inheritance notes explain inherited capabilities without duplicating cases.
- Generated by `tools/blackbox/build.py` after `tools/blackbox/validate.py` completed successfully.
- No wall-clock timestamp is emitted; identical validated inputs produce identical UTF-8 bytes.
- Historical outcomes are not copied into this document. Current non-default verdicts come only from the result overlay named in Metadata.
- Hash and size checks demonstrate internal consistency of supplied files; they do not independently prove execution identity, trust, or correctness.
- NOT_IN_RUN means excluded from this run and is never accepted execution evidence.

## 1. Public / Unauthenticated User

> **Role ID:** `public` · **Classification:** `public` · **Cases:** **5**  
> Base actor band (no parent role inheritance).  
> **Scope:** Anonymous visitor interacting with host login screens, the public Data Privacy Policy, and controlled document verification.

### Authentication, Session & Privacy \(4 cases\)

#### AU-003 — Invalid credentials establish no session
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-003`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.REJECT_INVALID`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login
- **Actor/grants:** Anonymous user
- **Preconditions:** A known synthetic identifier; an intentionally incorrect password; protected /portal URL recorded before the attempt.
**Steps**
1. Submit the known identifier with the wrong password.
2. After the error appears, request /portal in the same browser.
- **Expected:** The form reports an invalid ID/password and the protected request returns the user to sign-in without an authenticated identity.
- **Negative/non-disclosure:** The response does not reveal whether the identifier exists, which MIS namespace matched, a stack trace, or credential material.
- **Cleanup:** Wait for or reset only the synthetic login-attempt bucket before other authentication cases.
- **Source:** app/Controllers/AuthController.php:75-102; app/Services/AuthService.php:60-97
- **Evidence:** Error-state screenshot, sanitized POST/redirect trace, and protected-route result showing no session.
- **Verdict:** `NOT_RUN`

#### AU-005 — Login throttling is a temporary rate limit
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-005`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.THROTTLE_WINDOW`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login
- **Actor/grants:** Anonymous user
- **Preconditions:** Dedicated synthetic identifier; login-attempt store available; test clock/window known; no other runner using the same identifier/IP bucket.
**Steps**
1. Submit incorrect credentials repeatedly until the configured bucket limit is reached.
2. Attempt another sign-in during the window, record HTTP status/Retry-After, then retry valid credentials after the window expires.
- **Expected:** Requests in the exhausted window receive HTTP 429 with a bounded retry indication; valid credentials work again after expiry.
- **Negative/non-disclosure:** The behavior is not described as a persistent account lock, does not reveal account existence, and does not authenticate a throttled request.
- **Cleanup:** Let the bucket expire or delete only the isolated synthetic bucket through approved test teardown.
- **Source:** app/Filters/LoginThrottleFilter.php:14-55; app/Services/LoginRateLimiter.php:10-31
- **Evidence:** Attempt sequence, 429 response and Retry-After, followed by a successful post-window login; redact credentials.
- **Verdict:** `NOT_RUN`

#### AU-013 — Public Data Privacy Policy route serves institutional notice without login
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `AUTH.PRIVACY.PUBLIC_ROUTE`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-01`
- **Surface:** Public Data Privacy Policy page (uncommitted `feat/archive-management` Data Privacy Policy update)
- **Route/API:** GET /privacy-policy
- **Actor/grants:** Unauthenticated visitor and authenticated portal user
- **Preconditions:** Clean anonymous browser session; optional authenticated browser session on main/workstation host.
**Steps**
1. Open `/privacy-policy` in an unauthenticated browser session and verify the complete Foundation University Data Privacy Policy sections render under RA 10173.
2. Inspect the back-link target when logged out (`Back to Sign In` → `/auth/login`) vs logged in (`Back to Portal` → `/`) and check Cache-Control headers.
- **Expected:** `/privacy-policy` renders with HTTP 200 and `Cache-Control: private, no-store` without redirecting to sign-in, and adapts its back-navigation link safely to session state.
- **Negative/non-disclosure:** Page emits zero PII, user identifiers, session tokens, or stack traces regardless of authentication state.
- **Cleanup:** Clear browser session if authenticated.
- **Source:** app/Config/Routes.php:15-16; app/Controllers/AuthController.php:151-164; app/Views/auth/privacy_policy.php:1-40; app/Views/partials/privacy_policy_content.php:1-80
- **Evidence:** Anonymous and authenticated `/privacy-policy` screenshots, response headers, and `tests/unit/PrivacyPolicyRouteTest.php` corroboration.
- **Verdict:** `NOT_RUN`

#### AU-014 — Sign-in Data Privacy Policy modal and clinical RA 10173 consent links stay accessible
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `AUTH.PRIVACY.LOGIN_MODAL_AND_INAPP_LINKS`
- **Role:** `public`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-01`
- **Surface:** Login screen modal and Guidance RA 10173 consent banners (uncommitted `feat/archive-management` Data Privacy Policy update)
- **Route/API:** GET /auth/login; GET /portal/evaluations/redeem/{token}; GET /guidance/clinical/forms/{id}
- **Actor/grants:** Public login visitor; token-holding student; authorized counsellor
- **Preconditions:** Login screen accessible; synthetic evaluation token and clinical form draft available for in-app consent-link check.
**Steps**
1. On `/auth/login`, click the compact `Data Privacy Policy` trigger (`.auth-privacy-trigger`) and verify `#dataPrivacyPolicyModal` opens, scrolls, and closes cleanly while allowing full-page `/privacy-policy` navigation.
2. Open the evaluation submission screen and clinical form editor and follow the inline Foundation University Data Privacy Policy (RA 10173) link.
- **Expected:** Login modal renders identical shared policy markup (`privacy_policy_content.php`) without trapping keyboard focus or breaking credential submission; in-app consent notices link directly to `/privacy-policy`.
- **Negative/non-disclosure:** Modal and login page do not cache user inputs, leak prior login identifiers, or alter authentication enforcement.
- **Cleanup:** Close sessions and reset disposable evaluation/form fixtures.
- **Source:** app/Views/auth/login.php:1-120; app/Views/layouts/auth.php:1-210; app/Views/portal/evaluations/submit.php:1-80; app/Views/guidance/clinical/form_editor.php:1-80
- **Evidence:** Login modal open/scroll screenshot, mobile/desktop fit check, and in-app link navigation captures.
- **Verdict:** `NOT_RUN`

### Guidance & Counseling \(1 case\)

#### GD-FRM-007 — Public verification reveals only non-PII authenticity
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

## 2. All Authenticated Roles \(Shared\)

> **Role ID:** `all_authenticated` · **Classification:** `shared_group` · **Cases:** **30**  
> Base actor band (no parent role inheritance).  
> **Scope:** Capabilities shared by all 16 authenticated RBAC roles: host-scoped logout, CSRF/session security, own profile/photo/contacts, notification inbox, realtime tickets, and responsive web shell.

### Authentication, Session & Privacy \(5 cases\)

#### AU-004 — Wrong workstation host clears a valid login
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-004`
- **Changed by:** baseline
- **Requirement:** `AUTH.HOST.WRONG_WORKSPACE`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Dedicated workstation sign-in
- **Route/API:** POST /auth/login on clinic/guidance/bmg host
- **Actor/grants:** Valid actor whose role is incompatible with the selected host
- **Preconditions:** Mapped dedicated hosts resolve locally; synthetic clinic staff and Guidance host (or inverse) are available; host-only cookies enabled.
**Steps**
1. Open an incompatible dedicated workstation host and submit otherwise valid credentials.
2. Follow the access-notice link to the correct host and inspect both hosts' authenticated state.
- **Expected:** The incompatible host returns an access notice, clears the newly established authentication there, and offers configured navigation without forwarding credentials.
- **Negative/non-disclosure:** No password/session token appears in a URL, no department access is granted by hostname, and no cross-origin mutation is replayed.
- **Cleanup:** Sign out on any host where a session was deliberately established and clear all test-host cookies.
- **Source:** docs/PORTAL_HOSTS.md:24-49; app/Controllers/AuthController.php:93-102
- **Evidence:** Wrong-host notice and correct-host navigation screenshots plus sanitized per-host cookie and redirect evidence.
- **Verdict:** `NOT_RUN`

#### AU-006 — Confirmed logout anonymizes the current host session
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-006`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGOUT.CURRENT_HOST`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Account menu and confirmation
- **Route/API:** GET /auth/logout/confirm; POST /auth/logout
- **Actor/grants:** Any authenticated web actor
- **Preconditions:** Synthetic account signed in on the main host; optional independent session on a second host for scope proof.
**Steps**
1. Choose Sign out, verify the confirmation view, and submit its CSRF-protected POST.
2. Use browser Back and request a protected page; if a second-host session exists, check it separately.
- **Expected:** The current host rotates to an anonymous session and protected content cannot be restored; another host's independent session is unchanged.
- **Negative/non-disclosure:** GET /auth/logout does not mutate state, logout is not represented as global logout, and cached protected content is not served.
- **Cleanup:** End the optional second-host session and clear browser data.
- **Source:** app/Config/Routes.php:7-14; docs/SESSION_SECURITY.md:38-69; docs/PORTAL_HOSTS.md:51-57
- **Evidence:** Confirmation, signed-out result, back-button/protected-GET evidence, and optional second-host scope proof.
- **Verdict:** `NOT_RUN`

#### AU-007 — Authentication-version change revokes open sessions
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-007`
- **Changed by:** baseline
- **Requirement:** `AUTH.SESSION.AUTH_VERSION_REVOKE`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Authenticated web session
- **Route/API:** Any protected GET after trusted auth_version update
- **Actor/grants:** Synthetic active account; two signed-in browsers; approved fixture/admin operation that atomically increments users.auth_version
- **Preconditions:** Record the account's initial auth_version and establish independent sessions in two browsers without exposing cookies.
**Steps**
1. Through the approved synthetic-data operation, change an authority-bearing account attribute or replace a local break-glass password so auth_version increments atomically.
2. In each already-open browser, request a protected page and then try to establish a session with stale state.
- **Expected:** Every old session fails its next identity check and requires fresh authentication; role and permissions are reloaded from the database.
- **Negative/non-disclosure:** Do not claim Synapse observes an external MIS password change; do not expose auth_version as an editable UI field or preserve cached grants.
- **Cleanup:** Restore the synthetic role/password through the same approved path, incrementing version as designed, and terminate fresh sessions.
- **Source:** docs/SESSION_SECURITY.md:71-92; app/Services/AuthService.php:99-103,319-346
- **Evidence:** Before/after version record, both browsers' revocation notices, and sanitized protected-request statuses.
- **Verdict:** `NOT_RUN`

#### AU-008 — CSRF rejects a forged web mutation
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-008`
- **Changed by:** baseline
- **Requirement:** `AUTH.WEB.CSRF`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Protected web form
- **Route/API:** Representative protected POST, such as /portal/settings/emergency-contact
- **Actor/grants:** Authenticated synthetic portal actor
- **Preconditions:** Known current value in a synthetic record; same-origin valid form available; crafted request can omit or alter the token.
**Steps**
1. Capture the current visible value, then submit the mutation without a valid CSRF token.
2. Reload the target page and submit the same intended change through the legitimate form with its current token.
- **Expected:** The forged request is rejected and leaves state unchanged; the legitimate request succeeds once.
- **Negative/non-disclosure:** The rejection contains no stack trace/token value and does not rotate into an authenticated state for another identity.
- **Cleanup:** Restore the original synthetic field through the valid form.
- **Source:** app/Config/Routes.php:113-115,175-180; app/Config/Routes.php:7-14
- **Evidence:** Forged response/status, unchanged-state screenshot, and successful same-origin control mutation.
- **Verdict:** `NOT_RUN`

#### AU-012 — Host-only cookies prevent cross-workstation session reuse
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-012`
- **Changed by:** baseline
- **Requirement:** `AUTH.HOST.SESSION_PINNING`
- **Role:** `all_authenticated`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-04`
- **Surface:** Dedicated hosts
- **Route/API:** GET protected routes on synapse, clinic, guidance, and bmg hosts
- **Actor/grants:** Authorized multi-workspace synthetic actor
- **Preconditions:** At least two mapped hosts served over the same scheme; browser cookie inspector available; no shared Domain cookie configured.
**Steps**
1. Sign in on one host and inspect the session cookie's host scope without recording its value.
2. Navigate to another workstation host and request a protected route before and after a separate sign-in there.
- **Expected:** The first host's session is not sent as authentication to the second host; each host requires and maintains its own session.
- **Negative/non-disclosure:** No parent-domain SSO cookie, credential-bearing redirect, or cross-origin POST replay is used.
- **Cleanup:** Sign out independently on every host and clear each host's browser storage.
- **Source:** docs/PORTAL_HOSTS.md:51-57; app/Config/PortalSites.php:1-26
- **Evidence:** Cookie attribute screenshots with values redacted and before/after protected-route observations on both hosts.
- **Verdict:** `NOT_RUN`

### Personal Portal & Self\-Service \(6 cases\)

#### PT-007 — Profile photo upload and removal affect only the caller
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

#### PT-008 — Emergency contact update persists for the caller
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

#### PT-PRF-001 — Current contact and residence update without changing MIS identity
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

#### PT-PRF-002 — Profile photo validation preserves the prior image on failure
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

#### PT-PRF-003 — Official university profile fields remain read-only
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

#### PT-PRF-004 — No password-management UI is exposed
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

### Realtime & Notifications \(14 cases\)

#### PT-011 — Notification inbox read state is account-owned
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

#### RT-001 — Web realtime ticket grants only server-derived channels
- Lifecycle: `READY`
- Legacy IDs: `RT-001`
- Changed by: `bea71cf`, `c91477a`
- Requirement: An authenticated web POST from an exact allowed origin must receive a short-lived no-store ticket containing only the caller’s private user channel plus currently authorized AI topics; no client selector may expand it.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Authenticated web realtime bootstrap.
- Route/API: `POST /realtime/ticket`, then WebSocket `/` authenticate frame.
- Actor/grants: Fresh DB-valid web identity; AI topics only if separately authorized.
- Preconditions: Isolated Workerman process, exact allowed origin, realtime gate/key configured, two users in one tenant and another tenant.

**Steps**
1. Request a ticket while including forged user, tenant, channel, role, and origin form/query selectors, then authenticate the socket from the actual allowed origin.
2. Record the `ready` frame and publish a harmless hint to the caller’s user channel and to forged channels.
3. Repeat after revoking an AI grant and compare authorized channels/events.

- Expected: Ticket issuance ignores selectors; socket becomes ready; caller receives only authorized-channel events; revoked topics disappear on a fresh ticket.
- Negative/non-disclosure: Response/event data exposes no signing/publish key, other user/tenant channel, role wildcard, ticket claims beyond what the client must use, or domain record data.
- Cleanup: Stop sockets and isolated Workerman; delete synthetic hints.
- Source: `app/Controllers/RealtimeController.php:15-36`; `app/Services/Realtime/TicketIssuer.php:17-43`
- Evidence: Redacted ticket metadata, channel delivery matrix, and grant-before/after trace.
- Verdict: `NOT_RUN`

#### RT-002 — Expired realtime ticket and grant disconnect cleanly
- Lifecycle: `READY`
- Legacy IDs: `RT-002`
- Changed by: `bea71cf`
- Requirement: Tickets expire within configured 1–300 seconds; authentication at/after expiry fails, and an authenticated connection is destroyed when its claim deadline expires so queued data is discarded.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: WebSocket server.
- Route/API: WebSocket `/` authenticate/publish flow.
- Actor/grants: Valid ticket holder.
- Preconditions: Isolated short-TTL realtime configuration and controllable test clock or bounded wait.

**Steps**
1. Issue a ticket, wait until its exact expiry, then attempt socket authentication.
2. Authenticate a second ticket before expiry, observe `ready`, wait past expiry, and publish a harmless authorized hint.
3. Observe connection state and frames after both attempts.

- Expected: Late authentication never receives `ready`; existing client is destroyed at deadline and receives no post-expiry event.
- Negative/non-disclosure: Close/failure behavior does not reveal claims, signature details, keys, or whether another channel has subscribers.
- Cleanup: Close test connections and stop Workerman.
- Source: `app/Services/Realtime/TicketSigner.php:33-61`; `app/Services/Realtime/Hub.php:45-83`; `app/Services/Realtime/Hub.php:85-123`
- Evidence: Timestamped redacted issue/auth/publish trace and frame log.
- Verdict: `NOT_RUN`

#### RT-003 — Tampered or malformed realtime tickets fail identically
- Lifecycle: `READY`
- Legacy IDs: `RT-003`
- Changed by: `bea71cf`
- Requirement: Signature, encoding, version, channel-list, expiry, and shape validation must fail closed with no parsing detail returned to the peer.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: WebSocket authentication.
- Route/API: WebSocket `/` authenticate frame.
- Actor/grants: Test client with one valid ticket used only to derive tampered variants.
- Preconditions: Isolated Workerman and valid allowed origin.

**Steps**
1. Create variants with changed payload byte, signature byte, extra/missing segment, invalid base64url, oversized ticket, bad version, and malformed channel list.
2. Authenticate a fresh socket with each variant from the same origin.
3. Compare frames/closures with a random invalid ticket and with the valid control.

- Expected: Every invalid variant is disconnected without `ready`; only the untouched ticket succeeds.
- Negative/non-disclosure: No failure distinguishes signature, expiry, channel, JSON, or key condition; logs/evidence contain no complete valid ticket.
- Cleanup: Close all sockets and discard ticket material.
- Source: `app/Services/Realtime/TicketSigner.php:33-80`; `app/Services/Realtime/Hub.php:45-83`
- Evidence: Variant/result matrix with ticket hashes only and valid-control frame.
- Verdict: `NOT_RUN`

#### RT-004 — Origin binding rejects cross-origin use
- Lifecycle: `READY`
- Legacy IDs: `RT-004`
- Changed by: `bea71cf`, `73b8a11`
- Requirement: Ticket issuance and socket handshake must require an exact configured HTTP origin; ticket claims bind that same origin and HTTPS pages require WSS.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web ticket endpoint and WebSocket handshake.
- Route/API: `POST /realtime/ticket`; WebSocket `/`.
- Actor/grants: Authenticated web identity.
- Preconditions: At least two distinct test origins/hosts and isolated TLS variant where applicable.

**Steps**
1. Request from an unallowed/near-match origin and record the bounded denial.
2. Obtain a valid ticket from allowed origin A, then present it on a socket handshake from origin B and from missing origin.
3. In the HTTPS variant, configure/attempt an insecure `ws://` public URL.

- Expected: Near-match issuance and mismatched/missing handshake fail; exact A succeeds; insecure transport on HTTPS is refused/unavailable.
- Negative/non-disclosure: Client-supplied origin fields cannot override the header, and denials reveal no allowed-origin list or key.
- Cleanup: Close sockets and restore isolated origin configuration.
- Source: `app/Services/Realtime/TicketIssuer.php:24-43`; `app/Services/Realtime/TicketSigner.php:20-58`; `app/Services/Realtime/Hub.php:34-42`
- Evidence: Origin/result matrix and sanitized handshake trace.
- Verdict: `NOT_RUN`

#### RT-008 — Reconciliation repairs disconnect gaps
- Lifecycle: `READY`
- Legacy IDs: `RT-008`
- Changed by: `bea71cf`, `c91477a`, `73b8a11`
- Requirement: Realtime is an optimization only; disconnect, reconnect, visibility, and page-cache gaps must be repaired by authorized polling/refetch without replaying stale event payloads.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Queue board, personal queue, notifications, and active Team resources.
- Route/API: Relevant authoritative GET endpoints plus realtime status events.
- Actor/grants: Authorized web or mobile user for the resource under test.
- Preconditions: Workerman can be stopped/restarted in isolation; deterministic state-changing second session.

**Steps**
1. Open a live resource, stop realtime, change server state from a second session, and keep the first view open.
2. Wait the documented disconnected polling interval or background/foreground the client; verify it converges to server state.
3. Restart realtime, change state during reconnect, and confirm the first-ready invalidation closes the gap once.

- Expected: Views converge through poll/resume/reconnect GETs; backoff is bounded; no manual refresh or duplicate mutation is needed.
- Negative/non-disclosure: Repair requests retain current authority and clear/deny content if access changed; stale hints never overwrite authoritative state.
- Cleanup: Restart standard isolated Workerman, restore fixture, and close clients.
- Source: `public/assets/js/realtime.js:45-184`; `public/assets/js/queue-realtime.js:146-204`; `app/Services/NotificationInvalidation.php:56-100`
- Evidence: Outage timeline, polling/reconnect requests, and server/UI convergence capture.
- Verdict: `NOT_RUN`

#### RT-009 — Private channel isolation survives selector and publisher abuse
- Lifecycle: `READY`
- Legacy IDs: `RT-009`
- Changed by: `bea71cf`
- Requirement: Clients cannot subscribe after authentication or choose channels; publisher access requires the separate bearer key, empty Origin, exact `/publish`, bounded three-field body, and events fan out only to ticket-granted channels.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: WebSocket and internal publish listener.
- Route/API: WebSocket `/`; publisher `POST /publish`.
- Actor/grants: Two user clients; isolated internal publisher harness.
- Preconditions: Workerman configured with synthetic keys and three channel fixtures.

**Steps**
1. Authenticate users A and B, then send subscribe/channel-change/second-auth/unknown client messages from A.
2. Attempt publisher calls with browser Origin, wrong/missing key, wrong path/method, extra fields, invalid channel/event, and oversized body.
3. Publish valid distinct hints to A, B, and another tenant channel and record recipients.

- Expected: Unsupported client messages disconnect rather than alter grants; invalid publisher calls fail; each valid event reaches only clients whose signed ticket grants that channel.
- Negative/non-disclosure: Subscriber counts, channel existence, keys, tickets, and other-user event data are not disclosed.
- Cleanup: Close connections and destroy synthetic keys/config.
- Source: `app/Services/Realtime/Hub.php:45-101`; `app/Services/Realtime/PublishEndpoint.php:17-39`; `app/Services/Realtime/EventMessage.php:9-22`
- Evidence: Redacted abuse matrix and per-channel recipient log.
- Verdict: `NOT_RUN`

#### RT-010 — Mobile grant is own-channel only and rate bounded
- Lifecycle: `READY`
- Legacy IDs: `RT-010`
- Changed by: `bea71cf`, `c91477a`
- Requirement: Fresh bearer identity must receive at most its exact private user channel, no department/AI topics, from an allowed origin; forged selectors are ignored and per-user grant requests are throttled.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Mobile app realtime client.
- Route/API: `GET /api/v1/realtime/grant`.
- Actor/grants: Active mobile bearer user.
- Preconditions: Mobile client/simulator, allowed API origin, isolated Workerman, two accounts.

**Steps**
1. Request a grant with forged tenant/user/channel/role selectors and compare returned channel with authenticated identity.
2. Connect and publish user-specific hints for both accounts plus a department/AI channel.
3. Exceed the documented 60-per-60-second user budget in a bounded harness and test invalid origin.

- Expected: Grant names only `private.tenant.{ownTenant}.user.{ownUser}`; only own hints arrive; rate limit returns `429`; invalid origin returns `403`.
- Negative/non-disclosure: No AI/department wildcard, other-account channel, grant key, or data payload is exposed.
- Cleanup: Close sockets, revoke token family, and reset throttler fixture.
- Source: `app/Controllers/Api/V1/RealtimeGrantController.php:12-34`; `app/Services/Realtime/UserTicketIssuer.php:11-54`
- Evidence: Redacted grant metadata, delivery matrix, and throttle response.
- Verdict: `NOT_RUN`

#### RT-NT-001 — Inbox and history share current recipient authority
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `e08f383`, `c179273`
- Requirement: Web bell, web history, and mobile inbox must use the same current person/user/resource authority; history is paginated current-resource visibility, not a raw immutable notification dump.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web/mobile notifications.
- Route/API: `GET /notifications`; `GET /notifications/history`; `GET /api/v1/notifications`; `GET /api/v1/notifications/history`.
- Actor/grants: Active linked user with personal and/or department notices.
- Preconditions: Fixtures for appointment, referral, announcement, survey, team, chat, and workflow notices; one source resource later becomes unauthorized.

**Steps**
1. Compare web bell, web page 1/all, mobile inbox, and mobile page 1/all for IDs, titles, read state, and targets.
2. Revoke audience/member/resource authority for selected source notices and refresh all surfaces.
3. Exercise unread filter and additional pages around the changed rows.

- Expected: All surfaces expose the same currently authorized IDs; inaccessible source-backed notices disappear from list/count/read authority; pagination remains 20 rows and stable.
- Negative/non-disclosure: Historical notification rows do not bypass current grants; null-person identities receive only explicitly addressed eligible notices.
- Cleanup: Restore role only if needed, then retire DB.
- Source: `app/Services/NotificationService.php:192-248`; `app/Services/NotificationService.php:471-536`
- Evidence: Cross-surface ID matrix and before/after authority screenshots.
- Verdict: `NOT_RUN`

#### RT-NT-002 — Mark-read operations cannot cross recipient scope
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Individual and read-all operations must update only currently visible notices belonging to the caller, use POST, return no content or refreshed own inbox, and publish optional post-commit invalidation.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web/mobile notification read controls.
- Route/API: `POST /notifications/{id}/read`; `POST /notifications/read-all`; mobile equivalents.
- Actor/grants: Two active users with separate notices.
- Preconditions: Each user has unread notices; one notice later loses current authority.

**Steps**
1. Mark one own notification read on web and verify mobile reconciliation/read timestamp.
2. Attempt to mark another user’s ID and an authority-revoked own row, then compare database rows.
3. Run read-all and verify only caller’s current inbox rows change and unread count reaches zero.

- Expected: Own current row/read-all succeeds idempotently; foreign/revoked ID returns not found; other/revoked rows remain unchanged; optional hint follows commit only.
- Negative/non-disclosure: Response cannot distinguish foreign from nonexistent ID or expose recipient/source data; GET mutation is refused.
- Cleanup: Retire notification fixture DB.
- Source: `app/Controllers/NotificationController.php:29-75`; `app/Services/NotificationService.php:317-368`
- Evidence: HTTP matrix, before/after read timestamps, and realtime/poll reconciliation trace.
- Verdict: `NOT_RUN`

#### RT-NT-003 — Notification targets are an allowlisted projection
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `c179273`
- Requirement: Notification clients must receive only typed target descriptors and server-allowlisted same-application URLs derived from valid source columns; arbitrary stored URLs or malformed IDs must not become navigation.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Web notification links and mobile notification targets.
- Route/API: Notification inbox endpoints and client router.
- Actor/grants: Authorized recipient.
- Preconditions: Synthetic notices for each supported target plus malformed/unsupported source variants in disposable DB.

**Steps**
1. Fetch supported appointment, announcement, survey, team task/event/chat notices and exercise each web/mobile target.
2. Inspect returned target shape and actual destination against current authority.
3. Test null/negative IDs, unsupported type, cross-unit target, and injected URL-like text in source content.

- Expected: Only supported typed IDs/unit values create targets; routes resolve through fixed destinations; invalid/unsupported notices have no actionable target.
- Negative/non-disclosure: No `javascript:`, external origin, arbitrary DB URL, hidden message body, or unauthorized resource opens.
- Cleanup: Retire DB and clear mobile navigation intents.
- Source: `app/Services/NotificationService.php:26-54`; `app/Services/NotificationService.php:420-469`
- Evidence: Target/destination matrix and blocked-variant captures.
- Verdict: `NOT_RUN`

#### RT-NT-004 — Notification invalidation is post-commit, authorized, and bounded
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `73b8a11`
- Requirement: `notifications.changed` is an empty hint sent only after outer commit, only to reauthorized exact recipients, within one second/four attempts; storage or transport failure must not roll back the owning write.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Server notification workflow and clients.
- Route/API: Internal publisher plus authoritative inbox GETs.
- Actor/grants: Synthetic transaction writers and recipients.
- Preconditions: Disposable DB, injectable publisher failure, multiple recipients including revoked authority.

**Steps**
1. Create notices inside a nested/open transaction and call invalidation before commit; verify no hint.
2. Commit, invoke with committed IDs, and record eligible recipients and empty event data under the attempt/time budget.
3. Force one permission lookup and publisher failure, then verify the notification write remains committed and clients repair by polling.

- Expected: Only post-commit current recipients get an empty hint; duplicate IDs are normalized; bounds stop work; failure is swallowed for reconciliation.
- Negative/non-disclosure: Hint contains no notification IDs/body/count or audience list and never targets foreign tenant/user.
- Cleanup: Stop injected publisher and retire DB.
- Source: `app/Services/NotificationInvalidation.php:15-100`
- Evidence: Transaction/publish timeline, recipient list by synthetic IDs, failure trace, and committed-row proof.
- Verdict: `NOT_RUN`

#### RT-NT-005 — Named-region refresh preserves edits or fails safely
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `c91477a`, `c179273`, `884b9f0`
- Requirement: When explicitly rollout-enabled per owner, region refresh must validate unique owner/regions, same-origin safe HTML and navigation scope, preserve tabs/scroll/focus, block on edits, ignore stale responses, and escalate security ambiguity to full navigation; rollout policy remains unresolved.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Rollout-enabled web workspaces.
- Route/API: Authorized GET for each named owner/region following mutation or realtime hint.
- Actor/grants: Authorized user for selected owner.
- Preconditions: `refreshRegions.enabled` and only the test owner allowlisted; page has deterministic tabs, scroll, focus, and dirty form.

**Steps**
1. Trigger a region refresh with clean state and verify only named content changes while tab, scroll, focus, CSRF, lifecycle, and history behavior are preserved.
2. Edit within the region, trigger refresh, and verify it blocks with recoverable warning rather than overwriting.
3. Race two responses and test duplicate/missing region, unsafe markup, auth redirect, and changed navigation scope.

- Expected: Clean current response swaps atomically; dirty/stale/invalid content is blocked/ignored; security cases force safe full-document handling; owner-disabled state uses legacy fallback.
- Negative/non-disclosure: No scripts/iframes/event handlers/`javascript:` content execute, no cross-origin fetch occurs, and unrelated regions/forms are untouched.
- Cleanup: Disable owner rollout and clear synthetic edits.
- Source: `app/Config/RefreshRegions.php:9-43`; `public/assets/js/refresh-regions.js:14-125`; `public/assets/js/refresh-regions.js:274-415`
- Evidence: Screen recording, refresh telemetry, DOM-region diff, and negative-case matrix.
- Verdict: `NOT_RUN`

#### RT-NT-006 — Realtime lifecycle pauses, backs off, and clears private state
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `c91477a`, `73b8a11`
- Requirement: Browser/mobile realtime clients must use no-store grants, authenticate within deadline, pause when hidden/backgrounded, reconnect with bounded jittered exponential backoff, invalidate after reconnect gaps, and clear private state on unauthorized/session/account change.
- Role: `all_authenticated`
- Module: Realtime & Notifications
- Use Cases: `UC-SH-06`
- Surface: Browser and React Native realtime lifecycle.
- Route/API: Ticket/grant endpoints, WebSocket, authoritative resource endpoints.
- Actor/grants: Authenticated user with one live resource.
- Preconditions: Controllable visibility/app state, network, Workerman, and role/token changes.

**Steps**
1. Observe initial connect/ready, then background/hide the client and confirm socket/timers pause; change server state while paused.
2. Foreground/show with server unavailable, observe bounded retries, restore server, and verify immediate authoritative invalidation repairs the gap.
3. Revoke the role/token or switch account/server during retry and inspect sockets, timers, cache, and private UI.

- Expected: No active work while suspended; retries cap around 30 seconds with jitter; resume/reconnect repairs once; unauthorized/account change stops old generation and clears private content.
- Negative/non-disclosure: Credentials never appear in socket URLs/logs, old-account events/cache cannot reach the new account/server, and stale callbacks have no side effects.
- Cleanup: Restore network/role in disposable DB, sign out, and close Workerman.
- Source: `public/assets/js/realtime.js:1-185`; `mobile/src/features/realtime/useUserRealtime.ts:1-32`
- Evidence: Lifecycle timeline, connection/status logs without tickets, cache/UI captures, and account-isolation proof.
- Verdict: `NOT_RUN`

### Responsive Web Contracts \(5 cases\)

#### UX-WEB-001 — Application shell has one accessible drawer breakpoint
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `884b9f0`
- Requirement: At widths below 992 px the sidebar is an overlay drawer with 44 px controls, body scroll lock, close/overlay behavior, and no main-content offset; at 992 px and above it is a persistent collapsible rail with synchronized header/main offsets.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Authenticated web shell on representative portal/workstation pages.
- Route/API: Any authorized page using `layouts/app`.
- Actor/grants: Representative personal, clinic, guidance, BMG, and admin users.
- Preconditions: Pages with long navigation and content; JS enabled and disabled fallback noted.

**Steps**
1. At 991 and 992 px open/close/collapse sidebar via pointer and keyboard; inspect header/main position, focus, overlay, body scrolling, and link reachability.
2. Navigate while drawer is open and use Escape/close/overlay as supported; resize across breakpoint repeatedly.
3. Repeat at 200% zoom and reduced motion.

- Expected: Exactly one correct drawer/rail mode exists; no hidden content, stuck overlay, horizontal page scroll, focus loss, or duplicate control; reduced motion removes transitions.
- Negative/non-disclosure: Drawer never reveals unauthorized links/content and background cannot be accidentally activated while modal drawer is open.
- Cleanup: Reset viewport/sidebar state and sign out.
- Source: `public/assets/css/core/layout.css:2493-2668`; `public/assets/css/core/layout.css:2730-2735`
- Evidence: Breakpoint screen recording, keyboard focus sequence, and overflow measurements.
- Verdict: `NOT_RUN`

#### UX-WEB-002 — Header, title, breadcrumbs, actions, and dropdowns reflow safely
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `884b9f0`
- Requirement: On narrow screens the header hides nonessential context, preserves 44 px action targets, constrains dropdowns to viewport/dynamic height, stacks page title/actions, wraps long text, and applies safe-area padding without clipping.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Shared app header/page title/profile/notifications.
- Route/API: Representative pages with multiple page actions and long breadcrumb/name.
- Actor/grants: Authorized users with short/long names and varied roles.
- Preconditions: Synthetic long localized-looking titles/names; notification dropdown populated.

**Steps**
1. At 320/375 px open profile/notification dropdowns and inspect bounds, scrolling, layering, and content reachability.
2. Open pages with long title/breadcrumb and several actions; rotate/zoom and trigger validation/toast.
3. Use keyboard and screen-reader names through all header/page actions.

- Expected: Header/main remain within viewport; title/breadcrumb/actions wrap/stack, dropdown scrolls below header, targets are at least 44 px, and focus/labels are usable.
- Negative/non-disclosure: Truncation/wrapping does not expose hidden data, overlap sign-out, obscure alerts, or permit background clicks.
- Cleanup: Close overlays/reset viewport.
- Source: `public/assets/css/core/layout.css:2670-2728`; `app/Views/layouts/app.php:149-180`
- Evidence: Width/zoom screenshots, bounding-box/overflow report, and keyboard sequence.
- Verdict: `NOT_RUN`

#### UX-WEB-003 — Dense tables become labelled mobile cards without losing controls
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `884b9f0`
- Requirement: Opted-in tables under 768 px must become readable row cards with assistive headings retained, `data-label` associations, wrapping cells, touch-sized actions, and visible neutral select-all controls; desktop retains semantic table layout.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Audit, announcements, appointments, inventories, guidance directories/forms, reports, and similar opted-in tables.
- Route/API: Representative GET pages and harmless selection controls.
- Actor/grants: Authorized readers/operators for sampled tables.
- Preconditions: Rows include long unbroken text, empty values, badges, forms, checkbox/select-all, and pagination.

**Steps**
1. At 767 and 768 px inspect visual layout, DOM/accessibility table semantics/headings, labels, row boundaries, long text, and actions.
2. Operate row actions and select-all via keyboard/touch; navigate pages/filters and resize without reloading.
3. Check tables not opted into card mode for deliberate bounded horizontal scrolling rather than page overflow.

- Expected: Mobile cards preserve field identity/order/action scope and 40–44 px controls; desktop table remains intact; only intended container scrolls horizontally.
- Negative/non-disclosure: Hidden headings remain available to assistive technology; responsive transform does not reveal hidden columns/records or submit wrong row.
- Cleanup: Undo harmless selections/mutations.
- Source: `public/assets/css/responsive-tables.css:1-140`; `app/Views/admin/audit.php:44-102`
- Evidence: 767/768 visual/accessibility captures and row-action identity trace.
- Verdict: `NOT_RUN`

#### UX-WEB-007 — Personal portal shell avoids stale-page flash and preserves navigation
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Direct/reloaded personal subroutes must pre-paint suppress legacy subview only for eligible same-origin portal shell hydration, use no-store navigation prefetch, reveal/fallback within timeout, preserve browser history/focus/scroll, and never suppress unauthorized or PDF/full-document routes.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Portal home, visits, settings, announcements, surveys, referrals, MI, evaluations, call slips.
- Route/API: Portal GET routes and navigation fetches.
- Actor/grants: Linked user with portal records/appointments grants; unlinked/revoked variants.
- Preconditions: Direct deep links, slow/offline responses, back/forward cache, distinct canary content.

**Steps**
1. Direct-load/reload each eligible subroute and record first paint through hydrated shell; navigate back/forward and preserve scroll/focus.
2. Throttle/fail prefetch and wait beyond fallback; test PDF and unauthorized/unlinked variants.
3. Change account/session/navigation scope during fetch and inspect incoming document handling.

- Expected: No legacy shell flash for eligible routes; content becomes visible or safe full-page fallback within bound; history/presentation works; auth/scope changes do not swap private HTML.
- Negative/non-disclosure: Suppression never leaves a blank trapped page, leaks previous account content, or intercepts PDF/external/unauthorized navigation.
- Cleanup: Clear navigation/cache/session fixtures.
- Source: `app/Views/layouts/app.php:80-137`; `app/Views/layouts/app.php:141-180`; `public/assets/js/navigation.js:1-659`
- Evidence: First-paint recordings, network/fallback timeline, and history/focus/scroll checks.
- Verdict: `NOT_RUN`

#### UX-WEB-008 — Responsive behavior preserves accessibility, theme, and security boundaries
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e5b7eba`, `884b9f0`
- Requirement: Across sampled current pages and breakpoints, zoom/reflow, keyboard order, visible focus, labels/status announcements, contrast in light/dark, reduced motion, modal focus/scroll lock, and safe overflow must meet the same authorization/security contract as desktop.
- Role: `all_authenticated`
- Module: Responsive Web Contracts
- Use Cases: `UC-SH-06`
- Surface: Cross-cutting web shell and all major module workspaces.
- Route/API: Representative pages from authentication, portal, clinic, guidance, BMG, admin, AI, Teams.
- Actor/grants: Least-privilege representative roles.
- Preconditions: Accessibility tooling plus manual keyboard/screen-reader checks; synthetic long/error/loading content.

**Steps**
1. Run keyboard, 200% zoom, reflow, light/dark contrast, reduced-motion, and automated accessibility scans at desktop/mobile sizes.
2. Manually test menus, tabs, forms, tables/cards, dialogs/offcanvas, toasts/live regions, loading/error/empty states and destructive confirmations.
3. At each responsive state directly test one denied route/action and inspect DOM/network for hidden restricted content.

- Expected: No critical/serious accessibility issue in owned behavior; all functionality reachable with meaningful names/focus/status; layout/theme/motion changes preserve authorization and safe confirmation.
- Negative/non-disclosure: CSS hiding is never the sole access control; restricted data/actions are not present for denied roles; focus/backdrops never enable unintended background mutation.
- Cleanup: Close dialogs, undo synthetic mutations, sign out, retain sanitized report only.
- Source: `app/Views/layouts/app.php:184-239`; `public/assets/css/core/layout.css:2558-2728`; `public/assets/css/responsive-tables.css:14-140`
- Evidence: Accessibility report summary, manual checklist, screenshots, and denied-route DOM/network result.
- Verdict: `NOT_RUN`

## 3. Student

> **Role ID:** `student` · **Classification:** `rbac` · **Cases:** **45**  
> UML Inheritance: extends **All Authenticated Roles (Shared)** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Enrolled student using web self-service portal and mobile personal app (appointments, queue, visit history, announcements, surveys, call slips, MI assessments, service evaluations).

### Authentication, Session & Privacy \(2 cases\)

#### AU-001 — Student signs in to the personal portal
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-001`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.STUDENT`
- **Role:** `student`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** GET/POST /auth/login; GET /portal
- **Actor/grants:** Active student with portal.appointments.read and portal.records.read
- **Preconditions:** A synthetic student linked to an active same-tenant person; MIS test authentication available; no pre-existing browser session.
**Steps**
1. Open the main-host sign-in page, enter the synthetic student's valid university ID and password, and submit.
2. Observe the same-origin landing, then open /portal and reload it once.
- **Expected:** The session is established, the user lands in the personal portal, and the reload still identifies only the synthetic student.
- **Negative/non-disclosure:** No staff workstation link, another person's data, password, or MIS response payload is disclosed.
- **Cleanup:** Sign out through the confirmation page and remove the browser's host-scoped cookies.
- **Source:** app/Controllers/AuthController.php:70-102; app/Services/AuthService.php:60-103
- **Evidence:** Screenshots of the branded sign-in and identified portal landing plus sanitized redirect/status and cookie-scope evidence.
- **Verdict:** `NOT_RUN`

#### AU-010 — MIS outage fails closed without local fallback
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-010`
- **Changed by:** baseline
- **Requirement:** `AUTH.MIS.UNAVAILABLE`
- **Role:** `student`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login
- **Actor/grants:** University student or employee identity (not a local break-glass account)
- **Preconditions:** Isolated environment with MIS integration intentionally unavailable; a synthetic university identifier; local account table contains no deliberate break-glass credential for it.
**Steps**
1. Disable or disconnect the MIS test endpoint and submit valid-format university credentials.
2. Request a protected page and inspect the sign-in response.
- **Expected:** The user receives an explicit MIS-unavailable message and no authenticated session is established.
- **Negative/non-disclosure:** The system does not silently test a local password for the university identity, mislabel transport failure as bad credentials, or expose upstream details.
- **Cleanup:** Restore the test MIS endpoint and verify health without logging operational secrets.
- **Source:** app/Controllers/AuthController.php:84-90; app/Services/AuthService.php:67-97
- **Evidence:** Unavailable-state UI, sanitized network error classification, and protected-route denial.
- **Verdict:** `NOT_RUN`

### Personal Portal & Self\-Service \(20 cases\)

#### PT-001 — Personal portal home presents owned summaries
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

#### PT-002 — Personal appointment submission creates a request, not a reservation
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

#### PT-004 — Requester cancels an eligible own appointment with a reason
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

#### PT-005 — Personal queue card reports current position
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

#### PT-006 — Visit history is nonclinical attendance metadata
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

#### PT-009 — Announcement read tracking is per account
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

#### PT-010 — Published survey accepts one owned response
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

#### PT-012 — Personal portal identifiers cannot select another person's records
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

#### GD-CALL-002 — Target student can acknowledge only their own call slip
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

#### GD-MI-002 — Assigned person saves an encrypted partial draft
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.DRAFT`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`
- **Surface:** Portal MI assessment
- **Route/API:** POST /portal/assessments/mi/{id}/draft
- **Actor/grants:** Assigned person with guidance.assessments.take
- **Preconditions:** MI policy unresolved; synthetic assignment to person A; person B control; partial valid 0–4 responses; encryption configured.
**Steps**
1. As A, save a partial response set, leave, and reopen the assessment.
2. As B, try to save to A's assignment; inspect stored draft for plaintext.
- **Expected:** After policy approval, A's partial answers resume from encrypted draft and assignment becomes in_progress; B is denied.
- **Negative/non-disclosure:** Plain responses do not appear in database/log/audit output and no result is computed before finalization.
- **Cleanup:** Delete/reset draft and assignment.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:359-438,644-690; app/Config/Routes.php:428-433
- **Evidence:** Draft resume, ownership denial, and cipher-vs-response check.
- **Verdict:** `NOT_RUN`

#### GD-MI-003 — Finalization requires all 80 answers and account attestation
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.FINALIZE`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`
- **Surface:** Portal MI assessment
- **Route/API:** POST /portal/assessments/mi/{id}/finalize
- **Actor/grants:** Assigned person with guidance.assessments.take
- **Preconditions:** MI policy unresolved; in-progress synthetic assignment; complete reviewed 80-response set; required attestation statement.
**Steps**
1. Attempt finalization with one missing/invalid item or absent attestation.
2. Submit all 80 valid responses with the required account attestation and reload assignment.
- **Expected:** After policy approval, incomplete attempts fail unchanged; complete submission becomes finalized/completed with bound attestation and no draft.
- **Negative/non-disclosure:** No client-selected person, partial scoring, or guardian signer capacity is accepted.
- **Cleanup:** Delete/reset submission/assignment/attestation.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:440-560; app/Config/Routes.php:428-433
- **Evidence:** Validation failures, finalized state, 80-item completeness, attestation/hash, and removed draft.
- **Verdict:** `NOT_RUN`

#### GD-MI-004 — Results show all eight domains without diagnosis or top-three truncation
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.RESULTS`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`, `UC-GD-09`
- **Surface:** Portal/counsellor MI results
- **Route/API:** GET /portal/assessments/mi/results/{id}; GET /guidance/assessments/mi/results/{id}
- **Actor/grants:** Owning person and authorized Guidance interpreter/viewer
- **Preconditions:** MI interpretation/release policy unresolved; finalized synthetic submission with known scores in all eight domains.
**Steps**
1. Open results as the owning person and record all domains/descriptions/suggestions.
2. Open as authorized Guidance actor and as unrelated person; compare output/access.
- **Expected:** After policy approval, owner/authorized actor see all eight 0–40 domain scores and educational suggestions; unrelated person is denied.
- **Negative/non-disclosure:** Results contain no diagnostic label, hidden top-three-only representation, or another person's identity/results.
- **Cleanup:** Delete/reset finalized submission.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:564-642,694-790; app/Config/Routes.php:407-433
- **Evidence:** Owner/counsellor result screens, eight-domain reconciliation, and unrelated-person denial.
- **Verdict:** `NOT_RUN`

#### GD-MI-005 — MI PDF matches authorized result data
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.PDF`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`, `UC-GD-09`
- **Surface:** MI result PDF
- **Route/API:** GET /portal/assessments/mi/results/{id}/pdf; GET /guidance/assessments/mi/results/{id}/pdf
- **Actor/grants:** Owning person or authorized Guidance result viewer
- **Preconditions:** MI release policy unresolved; finalized synthetic result; PDF compiler available.
**Steps**
1. Download the PDF as the owner and compare all eight scores and identifying header to the web result.
2. Attempt the PDF as an unrelated actor or with a foreign submission ID.
- **Expected:** After policy approval, a readable PDF matches the authorized web result and unauthorized download fails.
- **Negative/non-disclosure:** PDF includes no draft/cipher/key, diagnosis, unrelated person, or server path/error detail.
- **Cleanup:** Securely delete download and reset synthetic result.
- **Source:** app/Config/Routes.php:407-433; app/Controllers/GuidanceMultipleIntelligenceController.php:317-429; app/Services/Reporting/GuidanceMultipleIntelligenceLatex.php:630-638
- **Evidence:** Download headers/hash, rendered pages, eight-score comparison, and denied request.
- **Verdict:** `NOT_RUN`

#### GD-MI-006 — Assessment ownership and tenant boundaries fail closed
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.ISOLATION`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-05`
- **Surface:** MI portal and console
- **Route/API:** MI draft/final/results routes
- **Actor/grants:** Assigned person, authorized counsellor, foreign/unrelated actors
- **Preconditions:** MI policy unresolved; same-tenant A/B and foreign-tenant assignments/results with unique sentinels.
**Steps**
1. As A, use A's draft/final/result routes, then substitute B/foreign IDs.
2. As counsellor without required result permission or case scope, attempt the same direct IDs.
- **Expected:** After policy approval, only self-taking and explicitly authorized result access succeeds; foreign/unrelated identifiers return no data.
- **Negative/non-disclosure:** Error shape does not disclose result existence, responses, domain scores, or tenant/person linkage.
- **Cleanup:** Delete/reset all synthetic MI records.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:694-790; app/Services/Guidance/GuidanceAccessPolicy.php:73-140
- **Evidence:** Actor/ID access matrix and negative sentinel search in denied responses.
- **Verdict:** `NOT_RUN`

#### GD-EVL-002 — One-time token submits an unlinkable pseudonymous evaluation
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.SUBMIT`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-06`
- **Surface:** Portal evaluation redemption
- **Route/API:** GET /portal/evaluations/redeem/{token}; POST /portal/evaluations/submit
- **Actor/grants:** Token holder with guidance.evaluations.submit as applicable
- **Preconditions:** Evaluation policy unresolved; issued unexpired token; complete 1–5/boolean ratings and synthetic qualitative comment.
**Steps**
1. Redeem the token and submit valid ratings/comment.
2. Inspect token state/evaluation row and try to redeem the same token again.
- **Expected:** After policy approval, token becomes redeemed once and one evaluation row stores ratings plus encrypted comment without direct person/session/episode/token linkage.
- **Negative/non-disclosure:** Reuse fails; plaintext comment and raw token do not enter row/log/audit; identity is not reconstructable from the response row.
- **Cleanup:** Delete/reset synthetic evaluation/token.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:270-383; app/Config/Routes.php:435-437
- **Evidence:** Redemption/submission UI, one-time conflict, schema-field inspection, and cipher-vs-comment search.
- **Verdict:** `NOT_RUN`

#### GD-EVL-003 — Invalid, expired, and malformed evaluation submissions fail unchanged
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.VALIDATION`
- **Role:** `student`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-06`
- **Surface:** Portal evaluation redemption
- **Route/API:** POST /portal/evaluations/submit
- **Actor/grants:** Token holder
- **Preconditions:** Evaluation policy unresolved; expired token, unknown token, issued token with missing/out-of-range fields/oversized comment.
**Steps**
1. Submit the unknown/expired tokens and record safe error states.
2. Submit malformed ratings/booleans/comment with a valid issued token, then verify it remains usable for a valid correction.
- **Expected:** After policy approval, each invalid request is rejected without an evaluation; validation failure does not consume the otherwise valid token.
- **Negative/non-disclosure:** Errors reveal no linked person/provider/service context and do not echo raw token or qualitative text.
- **Cleanup:** Delete/reset all synthetic tokens.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:284-383,604-685; app/Config/Routes.php:435-437
- **Evidence:** Error matrix, token/evaluation row counts before/after, and successful corrected control if allowed.
- **Verdict:** `NOT_RUN`

#### GD-API-001 — Student dashboard is own-scope and clinically redacted
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Authenticated identified student dashboard may return only own noncancelled Guidance appointments, noncancelled call slips, active MI assignments, and unexpired HMAC-eligible evaluation token metadata; it must exclude clinical notes, safety/triage, forms, and raw evaluation token.
- Role: `student`
- Module: Personal Portal & Self-Service
- Use Cases: `UC-PT-02`, `UC-PT-07`
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

#### GD-API-002 — Call-slip acknowledgement is owner-bound and transition-safe
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: A student may acknowledge only an own tenant-scoped call slip through the allowed mobile action; server resolves live actor, records `acknowledged` via the domain service, and returns only mobile-safe slip fields.
- Role: `student`
- Module: Personal Portal & Self-Service
- Use Cases: `UC-PT-04`
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

#### GD-API-004 — MI draft, finalization, and results are own-assignment contracts
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Student may list only own assignments, save own item-score drafts, and—only when reproduction gate is enabled—finalize all 80 scores 0–4 with student capacity and explicit attestation; own results include immutable score/snapshot metadata without counselor-only data.
- Role: `student`
- Module: Personal Portal & Self-Service
- Use Cases: `UC-PT-05`
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

#### GD-API-005 — Evaluation submission requires eligible one-time redemption token
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: An identified student may submit a service evaluation only with an own eligible issued/unexpired raw redemption token and valid form payload; successful redemption is single-use and response exposes only evaluation ID/type/form/date.
- Role: `student`
- Module: Personal Portal & Self-Service
- Use Cases: `UC-PT-06`
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

### Realtime & Notifications \(1 case\)

#### RT-006 — Personal queue projection repairs without exposing others
- Lifecycle: `READY`
- Legacy IDs: `RT-006`
- Changed by: `bea71cf`, `c91477a`
- Requirement: Personal queue events must trigger a no-store authorized projection of at most the caller’s two unit tickets, with internally consistent position/ahead/wait fields and periodic reconciliation.
- Role: `student`
- Module: Realtime & Notifications
- Use Cases: `UC-PT-02`
- Surface: Personal portal queue card.
- Route/API: `GET /portal/queue` plus `portal.queue.changed` hint.
- Actor/grants: Linked student/employee viewing own portal.
- Preconditions: Caller has a waiting ticket and another person ahead; second staff session can advance queue.

**Steps**
1. Keep the portal open while staff advances or skips tickets; observe the personal view update without page reload.
2. Compare status, position, people ahead, and estimate with the authoritative endpoint for waiting/called/in-consultation/skipped variants.
3. Stop realtime or hide/restore the page, change the queue, and wait for repair/resume refresh.

- Expected: Own ticket projection repairs via hint or 15-second fallback/resume; consistency rules hold and access loss clears private content.
- Negative/non-disclosure: Other patients’ names, ticket codes, identities, priorities, or records never appear; hints contain no private queue data.
- Cleanup: Restore queue fixture and close sessions.
- Source: `public/assets/js/queue-live.js:16-78`; `public/assets/js/queue-live.js:131-190`
- Evidence: Before/after portal captures, endpoint comparison, and network/socket timeline.
- Verdict: `NOT_RUN`

### Mobile Application & Platform \(22 cases\)

#### MB-001 — Student sign-in bootstraps authoritative profile
- Lifecycle: `READY`
- Legacy IDs: `MB-001`
- Changed by: `bea71cf`, `39f7339`
- Requirement: Mobile sign-in must share the web credential/rate policy, receive opaque one-time tokens, store only refresh credential in platform keychain binding, fetch `/me` as authoritative identity/entitlements, and restore across restart without retaining password.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: React Native sign-in/splash/home.
- Route/API: `POST /api/v1/auth/login`; `GET /api/v1/me`.
- Actor/grants: Active student account with linked person.
- Preconditions: Disposable student account, clean app storage, known server profile; no production credentials.

**Steps**
1. Enter valid synthetic credentials and sign in; observe loading then personal navigation and `/me` identity/entitlements.
2. Force-close/restart the app and verify silent restore using keychain refresh plus authoritative `/me`.
3. Inspect app storage/network and try invalid credentials/rate-bound attempts.

- Expected: Valid sign-in opens the correct account, restore works, password is not stored, errors/rate limits are bounded, and login response does not substitute for `/me`.
- Negative/non-disclosure: Tokens/password are absent from logs/screenshots/cache; hidden server control remains unavailable through ordinary login interaction; no staff workspace appears without entitlement.
- Cleanup: Sign out, clear keychain/application data, and retire account.
- Source: `app/Controllers/Api/V1/MobileAuthController.php:16-107`; `mobile/src/store/auth.tsx:108-197`
- Evidence: Redacted screen recording, endpoint sequence, keychain/storage property check, and restart proof.
- Verdict: `NOT_RUN`

#### MB-002 — Concurrent 401s share one silent refresh
- Lifecycle: `READY`
- Legacy IDs: `MB-002`
- Changed by: `bea71cf`, `73b8a11`
- Requirement: On expired access token, authenticated requests in one active runtime must share a single refresh, rotate credentials atomically, retry each request at most once, and avoid user prompt when successful.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Mobile API client and any personal screen.
- Route/API: `POST /api/v1/auth/refresh` plus original resource GETs.
- Actor/grants: Signed-in mobile user.
- Preconditions: Valid refresh token, deliberately expired access token, multiple simultaneous screen queries.

**Steps**
1. Trigger three authorized requests concurrently after access expiry and capture network chronology.
2. Verify one refresh request, one successor credential save, and one retry per original request.
3. Repeat with refresh network failure and then invalid refresh.

- Expected: Successful refresh is single-flight and transparent; transient failure surfaces recoverable network state without duplicate rotation; invalid refresh expires/clears session and requires sign-in.
- Negative/non-disclosure: Old/new token values are not logged; requests do not cross runtime/account; no infinite retry or duplicate mutation occurs.
- Cleanup: Sign out/clear keychain and retire token family.
- Source: `mobile/src/api/client.ts:270-333`; `app/Services/Api/TokenService.php:123-204`
- Evidence: Redacted network request count/timeline and session UI outcomes.
- Verdict: `NOT_RUN`

#### MB-003 — Refresh replay revokes the entire token family
- Lifecycle: `READY`
- Legacy IDs: `MB-003`
- Changed by: `bea71cf`
- Requirement: Refresh credentials are single-use; replay, expiry, or a losing concurrent rotation must revoke every successor in the same family so no family token can serve data.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Mobile token lifecycle.
- Route/API: `POST /api/v1/auth/refresh`; protected API GET.
- Actor/grants: Synthetic mobile account with captured test refresh token.
- Preconditions: Disposable token family; capture occurs only in secure test harness and never in artifacts.

**Steps**
1. Exchange the refresh token once and verify the successor works.
2. Replay the original token, then try the successor access and refresh tokens against protected endpoints.
3. Run a concurrent double-refresh variant and test role/auth-version change before refresh.

- Expected: Replay/losing concurrent request returns `401`, family is revoked, successors cannot serve/refresh, and auth-version change revokes all user families.
- Negative/non-disclosure: Responses do not reveal family ID/hash, token existence, role details, or data; raw tokens never enter evidence.
- Cleanup: Revoke all synthetic user tokens and destroy harness secrets.
- Source: `app/Services/Api/TokenService.php:19-23`; `app/Services/Api/TokenService.php:127-209`; `app/Services/Api/TokenService.php:212-249`
- Evidence: Run-local token aliases (never hashes or raw tokens), result timeline, and protected-resource denial matrix.
- Verdict: `NOT_RUN`

#### MB-004 — Home dashboard shows only authorized personal summaries
- Lifecycle: `READY`
- Legacy IDs: `MB-004`
- Changed by: `bea71cf`, `e5b7eba`
- Requirement: Mobile home must compose the signed-in person’s appointments, queue, announcements, and other entitled summaries using account-bound queries, explicit loading/empty/error states, and no staff or foreign data.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Mobile Home tab.
- Route/API: `GET /api/v1/me`, `/appointments`, `/queue`, `/announcements`, `/notifications` as used by the screen.
- Actor/grants: Linked student/employee with personal entitlement.
- Preconditions: Own fixture includes upcoming/past appointment, targeted announcement, queue ticket; second person has distinguishable records.

**Steps**
1. Open Home online and reconcile each card/count with own API results.
2. Exercise empty, slow, partial failure, background/foreground, and refresh states.
3. Switch account without reinstall and search screen/cache/network for the first account’s canaries.

- Expected: Home is responsive and converges to current own records; failures are bounded per screen; account switch clears old cache before new content.
- Negative/non-disclosure: No other person/tenant records, staff queues, hidden announcement, clinical notes, or old-account flash appears.
- Cleanup: Sign out both accounts and retire fixtures.
- Source: `mobile/src/features/dashboard/screens/DashboardScreen.tsx:1-339`; `mobile/src/store/auth.tsx:79-101`
- Evidence: State screenshots, own/foreign canary check, and API-to-card reconciliation.
- Verdict: `NOT_RUN`

#### MB-005 — Appointment request and cancellation preserve request semantics
- Lifecycle: `DRAFT`
- Legacy IDs: `MB-005`
- Changed by: `bea71cf`, `b868a49`, `884b9f0`
- Requirement: Mobile self-service creates a `requested` appointment for the authenticated person using unit-specific type/date/Manila time validation, then may cancel only an own cancellable appointment with a reason; dual Guidance appointment interpretation remains unresolved.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-01`, `UC-PT-07`
- Surface: Mobile Appointments list/detail/booking/cancel.
- Route/API: `POST /api/v1/appointments`; `POST /api/v1/appointments/{id}/cancel`.
- Actor/grants: Linked user with personal appointment authority.
- Preconditions: Available synthetic service/date/time; second person’s appointment; decision record consulted for Guidance variant.

**Steps**
1. Book clinic and, if policy resolved, Guidance appointments; inspect returned/list/detail status, owner, type, and explicit Manila offsets.
2. Cancel the own request with a reason and verify list/detail/slot behavior.
3. Attempt invalid unit/type/date/time, occupied slot, another person’s ID, terminal appointment, and blank reason.

- Expected: Valid create returns `201` and truthful request state; valid cancel returns no content and updates only own row; all invalid/foreign variants reject atomically.
- Negative/non-disclosure: App does not claim immediate confirmation, accept provider/owner selectors, expose another person, or conflate two Guidance appointment systems.
- Cleanup: Cancel remaining synthetic requests and retire DB.
- Source: `app/Controllers/Api/V1/AppointmentsApiController.php:14-115`; `app/Controllers/Api/V1/AppointmentsApiController.php:123-151`
- Evidence: Request/list/detail/cancel progression, negative response matrix, and slot/row comparison.
- Verdict: `NOT_RUN`

#### MB-006 — Queue board is an own-ticket projection
- Lifecycle: `READY`
- Legacy IDs: `MB-006`
- Changed by: `bea71cf`, `c91477a`
- Requirement: Queue tab must show only today’s active personal clinic/guidance tickets from server-derived bearer identity, with consistent status/position/wait data and realtime/poll repair.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-02`, `UC-PT-07`
- Surface: Mobile Queue tab.
- Route/API: `GET /api/v1/queue`; mobile realtime grant.
- Actor/grants: Linked user with own active ticket.
- Preconditions: Own ticket and distinguishable tickets for other people; staff session can advance queue.

**Steps**
1. Open Queue and reconcile own ticket fields with API snapshot.
2. Advance/skip/recall through staff web and observe mobile update without pull-to-refresh; repeat during socket outage.
3. Attempt identity/query selectors and switch to another account.

- Expected: Only own active tickets render; hint/poll updates converge; forged selectors do not alter owner; account switch clears old ticket immediately.
- Negative/non-disclosure: Other ticket codes, names, identities, priorities, or staff controls never appear.
- Cleanup: Restore/remove test tickets and sign out.
- Source: `app/Controllers/Api/V1/QueueApiController.php:11-20`; `mobile/src/features/queue/screens/QueueBoardScreen.tsx:1-159`
- Evidence: API/UI comparison, staff-action/mobile-update recording, outage repair trace, and account switch capture.
- Verdict: `NOT_RUN`

#### MB-007 — Mobile records are a minimal health profile, not visit history
- Lifecycle: `DRAFT`
- Legacy IDs: `MB-007`
- Changed by: `bea71cf`, `b868a49`, `884b9f0`
- Requirement: Current mobile Records must expose only the authenticated linked person’s identity/profile and minimal health summary under `portal.records.read`; encounter/treatment/inventory detail stays workstation/web-only. Legacy expectation of visit history conflicts and awaits policy decision.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Mobile Profile → Records/Health Profile.
- Route/API: `GET /api/v1/me/health-summary`.
- Actor/grants: Linked student/employee with `portal.records.read`; negative role without it.
- Preconditions: Own health profile and distinguishable own/foreign encounter records.

**Steps**
1. Open mobile Records and inventory fields returned/displayed for student and employee variants.
2. Compare with own health-summary API and search response/UI/cache for encounter, treatment, inventory, and foreign canaries.
3. Remove records permission, use an unlinked identity, and attempt ID/query manipulation.

- Expected: Only documented minimal own profile/health fields appear; no visit-history contract is implied; permission/unlinked variants return `403` and clear/withhold content.
- Negative/non-disclosure: No encounter notes, diagnoses, treatments, inventory, case records, other person/tenant, or arbitrary identifier lookup is exposed.
- Cleanup: Restore permission only if needed, sign out, retire DB.
- Source: `app/Controllers/Api/V1/MeController.php:51-103`; `mobile/src/features/profile/screens/RecordsScreen.tsx:1-161`
- Evidence: Field inventory, canary search, permission/identity response matrix, and policy-decision reference.
- Verdict: `NOT_RUN`

#### MB-008 — Announcement targeting and read state match web authority
- Lifecycle: `READY`
- Legacy IDs: `MB-008`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Mobile announcement list/detail/read must use current targeted audience authority and same source/read marker as web, with bounded filters/paging and no hidden/draft/other-audience content.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-03`, `UC-PT-07`
- Surface: Mobile Announcements.
- Route/API: `GET /api/v1/announcements`; `GET /api/v1/announcements/{id}`; `POST /api/v1/announcements/{id}/read`.
- Actor/grants: Linked user in a known audience.
- Preconditions: Targeted/unrelated, read/unread, published/hidden fixtures.

**Steps**
1. Compare mobile list/counts/detail with the same user’s web portal and exercise filters/search/paging.
2. Mark one item read on mobile and verify web/mobile read state converges.
3. Directly request hidden/unrelated/other-tenant IDs and manipulate filters/page.

- Expected: Same authorized published items/read markers appear across surfaces; read is idempotent; unauthorized IDs are not disclosed; invalid query falls to bounded safe defaults.
- Negative/non-disclosure: Draft/archived audience internals, other recipients/readers, raw targeting data, and other tenant content are absent.
- Cleanup: Retire announcement/read fixtures.
- Source: `app/Controllers/Api/V1/AnnouncementsApiController.php:10-63`; `app/Services/AnnouncementService.php:250-346`
- Evidence: Web/mobile ID/read matrix, filter screenshots, and direct-ID denial responses.
- Verdict: `NOT_RUN`

#### MB-009 — Survey submission is identified, single, and audience-scoped
- Lifecycle: `READY`
- Legacy IDs: `MB-009`
- Changed by: `bea71cf`, `b868a49`
- Requirement: Mobile surveys must list only currently available targeted forms, show server-defined questions, validate answers, create one identified response for the authenticated person, and then show submitted state consistent with web.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-03`, `UC-PT-07`
- Surface: Mobile Surveys list/form.
- Route/API: `GET /api/v1/surveys`; `GET /api/v1/surveys/{id}`; `POST /api/v1/surveys/{id}/submit`.
- Actor/grants: Linked eligible user.
- Preconditions: Published targeted survey with required/typed questions; closed/unrelated variants.

**Steps**
1. Open list/form and compare question IDs/types/options with server/web.
2. Submit valid answers once and verify returned response ID and submitted status across mobile/web.
3. Repeat submit and test missing/invalid answers, closed/unrelated survey, and forged person/respondent fields.

- Expected: Exactly one own response is recorded; validation is field-safe; repeat/closed/foreign requests reject; status/counts reconcile.
- Negative/non-disclosure: Another respondent’s answers/identity, aggregate moderation data, hidden questions, or caller-selected owner cannot be accessed.
- Cleanup: Retire survey response fixture DB.
- Source: `app/Controllers/Api/V1/SurveysApiController.php:10-55`; `mobile/src/features/surveys/screens/SurveyFormScreen.tsx:1-282`
- Evidence: Question/answer validation capture, row count/response ID, and web/mobile status comparison.
- Verdict: `NOT_RUN`

#### MB-010 — Notification read state and deep links reconcile with server
- Lifecycle: `READY`
- Legacy IDs: `MB-010`
- Changed by: `bea71cf`, `c179273`
- Requirement: Mobile notifications must display the current own inbox/history, mark one/all read on server, reconcile via push/realtime/poll, and navigate only through authorized typed targets.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Mobile notification banner/inbox.
- Route/API: `/api/v1/notifications*` and target resource APIs.
- Actor/grants: Signed-in user with synthetic notices.
- Preconditions: Read/unread supported target fixtures and one later-revoked target.

**Steps**
1. Open inbox, mark one read and then all read; compare server/web unread counts and timestamps.
2. Trigger a new notice with app foreground/background and exercise its deep link.
3. Revoke target authority, attempt foreign ID read, malformed target, and old-account push after switch.

- Expected: Read state is server-authoritative and converges; valid target opens exact authorized record; revoked/malformed/foreign targets show bounded unavailable state and are consumed.
- Negative/non-disclosure: Push/banner contains no sensitive body or arbitrary URL; old/foreign account notice/cache cannot render.
- Cleanup: Sign out, clear push registration, retire DB.
- Source: `app/Controllers/Api/V1/NotificationsApiController.php:11-51`; `mobile/src/features/notifications/screens/NotificationsScreen.tsx:1-232`; `mobile/src/features/workspace/NavigationIntentGate.tsx:62-95`
- Evidence: Read-state matrix, foreground/background trace, and valid/invalid deep-link recording.
- Verdict: `NOT_RUN`

#### MB-012 — Realtime hints invalidate only active account caches
- Lifecycle: `READY`
- Legacy IDs: `MB-012`
- Changed by: `c91477a`, `73b8a11`, `c179273`
- Requirement: Mobile user-channel hints must invalidate mapped current-account queries, retain authoritative GET as source, fall back to focus polling, and have no effect after account/server/runtime retirement.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Open mobile queue/appointments/notifications/Team screens.
- Route/API: `GET /api/v1/realtime/grant` plus resource APIs.
- Actor/grants: Signed-in user; second staff/account changes source state.
- Preconditions: Workerman and controllable outage; two accounts with canary data.

**Steps**
1. Open each affected screen, trigger matching hints, and verify a refetch/update without pull-to-refresh.
2. Send unknown/malformed/mismatched hints and verify no unsafe cache mutation.
3. Stop realtime and use focus polling; then switch account/server while old hints/requests are in flight.

- Expected: Valid hints cause only mapped current-runtime refetches; fallback repairs; retired requests/hints abort or fail with session-changed and old cache is cleared.
- Negative/non-disclosure: Hint data is never rendered as record truth and cannot cross account, tenant, unit, or server profile.
- Cleanup: Restore server, sign out, and clear connection profiles/test registrations.
- Source: `mobile/src/features/realtime/useUserRealtime.ts:1-32`; `mobile/src/api/client.ts:99-173`; `mobile/src/api/client.ts:195-227`
- Evidence: Hint/refetch timeline, unknown-event result, outage repair, and account/server isolation capture.
- Verdict: `NOT_RUN`

#### MB-013 — Sign-out revokes server family and clears local private state
- Lifecycle: `READY`
- Legacy IDs: `MB-013`
- Changed by: `bea71cf`, `73b8a11`, `c179273`
- Requirement: Sign-out must immediately clear local token/profile/query state, best-effort revoke push activation and exact API token family without refresh, return to sign-in, and prevent cached/private data after restart even if cleanup network calls fail.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Mobile account/settings sign-out.
- Route/API: `POST /api/v1/auth/logout`; push revoke; local runtime/keychain/cache cleanup.
- Actor/grants: Signed-in mobile user.
- Preconditions: Cached personal/work data, active push registration, valid token family; controllable offline mode.

**Steps**
1. Sign out online and immediately inspect UI/cache/keychain; try old access/refresh/push and restart app.
2. Sign in again, go offline, sign out, restart, and reconnect.
3. Race sign-out with a new sign-in/account or server switch and delayed old requests.

- Expected: Private UI/cache/token disappear immediately; online family/push are revoked; offline local session cannot restore and later old callbacks cannot erase or contaminate new credentials/account.
- Negative/non-disclosure: No old personal/work data flashes after restart; cleanup never silently refreshes or targets new runtime.
- Cleanup: Revoke any surviving disposable token/push rows and clear app data.
- Source: `app/Controllers/Api/V1/MobileAuthController.php:72-83`; `mobile/src/store/auth.tsx:243-257`; `mobile/src/api/client.ts:403-437`
- Evidence: Online/offline/race recordings, old-token denial matrix, and storage/cache property checks.
- Verdict: `NOT_RUN`

#### MB-PER-001 — Personal entitlement and account/server isolation are authoritative
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `39f7339`
- Requirement: Personal navigation exists only when authoritative `/me` reports a live linked personal entitlement; every async request, credential, cache, draft, push scope, and navigation intent is bound to immutable runtime plus account session and discarded on entitlement/account/server change.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Entire React Native personal context.
- Route/API: `GET /api/v1/me` and all personal endpoints.
- Actor/grants: Linked personal user, unlinked/break-glass user, and two accounts/server profiles.
- Preconditions: LAN test profiles are explicitly approved; canary records/drafts for each account; no production server.

**Steps**
1. Compare linked versus unlinked identities’ `/me`, root navigation, and direct personal API access.
2. Create an unsaved personal draft and in-flight request, then remove entitlement/role or switch account; inspect navigation, draft, cache, and callback behavior.
3. Switch server profile via the hidden approved LAN control and attempt delayed old-server responses/push/intents.

- Expected: Only linked entitlement enables personal context; revocation disposes only revoked-scope drafts/submissions and leaves no data; runtime retirement aborts/invalidates all old work and credentials are profile-bound.
- Negative/non-disclosure: UI flags never override server checks; no old account/server token, data, draft, push, or navigation target appears in the new context.
- Cleanup: Remove test server profile, clear app/keychain/push data, restore role, retire DBs.
- Source: `app/Controllers/Api/V1/MeController.php:19-48`; `mobile/src/features/workspace/WorkspaceContext.tsx:48-156`; `mobile/src/api/client.ts:99-173`
- Evidence: Entitlement matrix, draft/request transition recording, old/new canary search, and server-profile isolation trace.
- Verdict: `NOT_RUN`

#### MB-PLAT-001 — API runtime binds every request to one server and account generation
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `73b8a11`, `39f7339`
- Requirement: Each immutable API runtime owns one base URL, credential binding, access token, query client, connection owner, and monotonic session version; retiring it aborts requests and makes all captured callbacks fail as session-changed.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Entire React Native API layer.
- Route/API: All mobile APIs.
- Actor/grants: Any mobile account; two test server profiles/accounts.
- Preconditions: Approved LAN test build or normal single-server build; delayed request harness.

**Steps**
1. Start a request/decoder/mutation under runtime A, then switch server/account to runtime B before it settles.
2. Allow A’s transport to return despite abort and inspect UI/cache/credentials/navigation.
3. Invoke API clients and callbacks captured from A after retirement.

- Expected: A is retired, token/cache cleared, requests aborted or rejected with `SessionChangedError`, and only B can update state.
- Negative/non-disclosure: No A response, credential, cache row, error, push, or intent reaches B; base URL cannot mutate under a live runtime.
- Cleanup: Clear both profiles/accounts/application data.
- Source: `mobile/src/api/client.ts:33-173`; `mobile/src/api/client.ts:195-227`; `mobile/src/features/connection/ConnectionProvider.tsx:51-79`
- Evidence: Runtime/request timeline and A/B canary search.
- Verdict: `NOT_RUN`

#### MB-PLAT-002 — Server switching closes the old environment before mounting new data
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `39f7339`
- Requirement: In an approved LAN test build only, server switching must durably mark the new profile signed-out/pending, retire old runtime/cache/navigation, queue bounded old-origin session/push cleanup without refresh, clear old credentials/scope, then mount new runtime; rollout of test profiles remains policy-gated.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Hidden sign-in/settings server selector.
- Route/API: Old `POST /api/v1/auth/logout`, push revoke, then selected server APIs.
- Actor/grants: Test operator using LAN build.
- Preconditions: Two dedicated nonoperational hosts with distinguishable canaries; old host can be offline.

**Steps**
1. Switch online from A to B and record durable storage, cleanup targets, navigation unmount/remount, and initial B signed-out state.
2. Repeat with A offline/slow and force-close during pending switch; restart app.
3. Race repeated selector taps and delayed A responses.

- Expected: No consumers mount between owners; restart resolves to signed-out selected profile or selection screen; cleanup never targets B or refreshes A; only one switch job runs.
- Negative/non-disclosure: Server control is absent in production/ordinary login, HTTP is visibly test-only, and no A credential/data survives.
- Cleanup: Remove saved LAN profile/keychain/push scope and reinstall/clear app.
- Source: `mobile/src/features/connection/ConnectionProvider.tsx:82-155`; `mobile/src/features/connection/ConnectionProvider.tsx:199-229`
- Evidence: Switch/restart/race recordings and old/new host request trace.
- Verdict: `NOT_RUN`

#### MB-PLAT-003 — Android notification onboarding prompts once at the correct lifecycle
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `c179273`
- Requirement: Android 13+ first launch may request notification permission once while an active caller exists, independent of authentication/Firebase/LAN capability; a check-only race must not consume onboarding, background/unmount before native request must not mark attempted, and later automatic foreground checks never reprompt.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Android native permission lifecycle.
- Route/API: Native permission API; no server route required.
- Actor/grants: Fresh app installation, signed out or signed in.
- Preconditions: Android 13+ emulator/device with permission denied/not yet asked; cleared app storage.

**Steps**
1. Launch fresh and observe one native request; deny it, background/foreground and restart.
2. Repeat fresh with early background/unmount before native request begins, then return active.
3. Race permission check/automatic callers and exercise explicit Enable action after denial.

- Expected: First active launch prompts once and records attempt as native request starts; interrupted pre-request launch may prompt later; automatic checks never reopen; explicit action may request/open settings per platform.
- Negative/non-disclosure: Prompt does not depend on credentials, device token, Firebase, or server and marker is device-local—not account/server permission.
- Cleanup: Reset emulator app/permission state.
- Source: `mobile/src/lib/push/permission.ts:6-98`
- Evidence: Native screen recording and local attempted-marker/lifecycle trace without identifiers.
- Verdict: `NOT_RUN`

#### MB-PLAT-004 — LAN push capability handshake precedes token disclosure
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `c179273`
- Requirement: A LAN client must authenticate and verify scope version, deployment ID, and `lan-test` audience before submitting any routable device token; unsupported/failed capability check suspends setup without unsafe legacy fallback. Push rollout remains gated.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Phone notification setup.
- Route/API: `GET /api/v1/push/capabilities`; `POST /api/v1/push/token`.
- Actor/grants: Signed-in LAN-test account.
- Preconditions: Dedicated servers representing supported, unsupported, partial-schema, wrong-deployment, and failure variants; synthetic push token.

**Steps**
1. Enable/setup against supported server and record capability response before token registration.
2. Repeat against unsupported/partial/failing servers and inspect all requests/storage/status.
3. Return malformed/wrong deployment capability/registration acknowledgment.

- Expected: Token is sent only after valid capability/scope preparation; unsupported/failure shows warning and retains in-app inbox; mismatched acknowledgment withdraws/suspends registration.
- Negative/non-disclosure: Device token is never sent to unverified server, returned by API, logged, or downgraded to unscoped registration.
- Cleanup: Revoke synthetic registration and clear secure scope/installation data.
- Source: `app/Controllers/Api/V1/PushController.php:13-85`; `mobile/src/lib/push/registration.ts:167-231`; `mobile/src/lib/push/registration.ts:288-309`
- Evidence: Ordered capability/register traces with token hash only and setup-state screenshots.
- Verdict: `NOT_RUN`

#### MB-PLAT-005 — Push activation is bound to deployment, installation, API family, and account
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `c179273`
- Requirement: Scoped LAN registration must atomically bind exact tenant/user, deployment, stable installation, bearer-derived API family, audience, registration UUID, and capability; replacement retires the prior activation and context reuse conflicts. Push rollout remains gated.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Push registry/server and mobile secure scope.
- Route/API: `POST /api/v1/push/token`.
- Actor/grants: Signed-in LAN-test user.
- Preconditions: Disposable push table with unique active installation index; two accounts/families/servers; token refresh supported.

**Steps**
1. Register valid scope, refresh token/replace device token, and inspect active rows plus response metadata.
2. Reuse registration ID or device token under another account/family/installation/deployment and run concurrent first registration.
3. Attempt partial/null/unknown scope and unscoped overwrite of scoped token.

- Expected: One current activation per installation; same context refreshes/replaces atomically; cross-context reuse conflicts; malformed/partial/downgrade attempts fail.
- Negative/non-disclosure: Device token and family are never returned/logged; errors do not disclose owner/context or foreign token existence.
- Cleanup: Revoke all synthetic activations and retire DB.
- Source: `app/Controllers/Api/V1/PushController.php:41-133`; `app/Services/Push/PushTokenService.php:61-220`
- Evidence: Redacted binding/row lifecycle matrix and concurrency count.
- Verdict: `NOT_RUN`

#### MB-PLAT-006 — Push revocation is exact, idempotent, and race-safe
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `73b8a11`, `c179273`
- Requirement: Scoped revoke must match current tenant/user/token/audience/registration/API family; obsolete revoke is no-op, legacy revoke cannot strip scoped activation, logout revokes the family, and late registration after owner retirement is withdrawn from the old origin. Push rollout remains gated.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Sign-out, account/server switch, token refresh cleanup.
- Route/API: `DELETE /api/v1/push/token`; `POST /api/v1/auth/logout`.
- Actor/grants: Signed-in user with scoped activation.
- Preconditions: Current and obsolete registrations, delayed register response, two runtimes/accounts.

**Steps**
1. Revoke current activation twice and verify exact row retirement/idempotency.
2. Replace activation, send obsolete scoped and unscoped revoke, and verify replacement remains.
3. Retire/switch owner during delayed registration and sign out token family; observe old-origin withdrawal.

- Expected: Exact current activation retires; obsolete/legacy race cannot revoke replacement or downgrade scope; family logout retires all bound activations; late old registration is withdrawn.
- Negative/non-disclosure: Cleanup never refreshes, targets new account/server, returns token, or blocks local sign-out indefinitely.
- Cleanup: Revoke any surviving synthetic rows and clear app secure scope.
- Source: `app/Services/Push/PushTokenService.php:223-262`; `app/Services/Push/PushTokenService.php:324-330`; `mobile/src/lib/push/registration.ts:61-117`
- Evidence: Row lifecycle/race timeline and old/new host cleanup trace.
- Verdict: `NOT_RUN`

#### MB-PLAT-007 — Push target handling is typed, current-owner, and fail-safe
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `c179273`, `a06b70b`
- Requirement: Foreground/background/cold-start push must parse only supported type, positive numeric ID, valid Team unit, and current push/connection owner; navigation rechecks entitlement and invalid/revoked targets are consumed once with in-app fallback.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: Native push router/banner and navigation gate.
- Route/API: Push payload handling followed by relevant API detail.
- Actor/grants: Current account with personal/Team targets; second account/server.
- Preconditions: Valid, malformed, unauthorized, and old-owner synthetic push payloads.

**Steps**
1. Deliver each supported personal/Team push in foreground, background, and cold start; open exact authorized record.
2. Deliver malformed type/ID/unit, revoked entitlement, nonexistent record, and duplicate delivery.
3. Switch account/server before tapping a queued old push.

- Expected: Valid current target opens after authoritative check; invalid/revoked target shows bounded unavailable/inbox fallback and does not loop; old-owner payload is dropped.
- Negative/non-disclosure: Arbitrary URL/action/data cannot navigate; payload does not reveal sensitive body or cross account/server/unit.
- Cleanup: Clear push queue/intents, sign out, and remove synthetic messages.
- Source: `mobile/src/features/workspace/navigationIntent.ts:22-75`; `mobile/src/features/workspace/NavigationIntentGate.tsx:62-95`
- Evidence: Delivery-state/target outcome matrix and recordings.
- Verdict: `NOT_RUN`

#### MB-PLAT-008 — Android system insets, keyboard, and reduced motion preserve operability
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e5b7eba`, `a06b70b`, `c179273`
- Requirement: Core screens, fixed tab/header controls, forms, dialogs, and chat composer must remain reachable above status/navigation/gesture bars and keyboard across supported Android sizes/orientations; motion respects reduced-motion preference without changing state semantics.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
- Surface: React Native layout/navigation/transitions.
- Route/API: Client UI; ordinary APIs for loaded screens.
- Actor/grants: Personal-only and Team-entitled accounts.
- Preconditions: Small/tall Android emulators, gesture/three-button navigation, portrait/landscape, keyboard and reduced-motion variants.

**Steps**
1. Traverse sign-in, personal tabs, workspace tabs, forms, dialogs, bottom sheets, and chat composer under each inset/navigation configuration.
2. Open keyboard, rotate, change font/display scale, and perform submit/back/tab/context actions.
3. Enable reduced motion and repeat transitions plus interrupted/back navigation.

- Expected: Content/controls are visible, scrollable, tappable, and not obscured; keyboard dismissal/navigation is deterministic; reduced motion removes/minimizes animation but preserves results.
- Negative/non-disclosure: No accidental underlying tap/double submit, clipped error, stuck backdrop, hidden sign-out, or content flash from another context.
- Cleanup: Reset device accessibility/navigation settings and sign out.
- Source: `mobile/src/components/ScreenInsets.tsx:1-4`; `mobile/src/components/SystemNavigationBar.tsx:1-82`; `mobile/src/navigation/ContextTabs.tsx:1-82`
- Evidence: Device/viewport matrix screenshots and interaction recording.
- Verdict: `NOT_RUN`

#### GD-API-006 — Workstation-only Guidance actions always return 403 on mobile API
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Clinical authoring, clinical co-sign, safety triage/escalation, and case transfer/reassignment have no mobile authority; all methods/paths under explicit mobile boundaries and blocked `action` payloads must return `403` even for privileged staff bearers.
- Role: `student`
- Module: Mobile Application & Platform
- Use Cases: `UC-PT-07`
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

## 4. Employee

> **Role ID:** `employee` · **Classification:** `rbac` · **Cases:** **5**  
> UML Inheritance: extends **Student** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** University employee holding all Student self-service capabilities plus exclusive access to submit and withdraw multi-student Guidance General Referrals (GGR-005).

### Authentication, Session & Privacy \(1 case\)

#### AU-002 — Employee signs in to the personal portal
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-002`
- **Changed by:** baseline
- **Requirement:** `AUTH.LOGIN.EMPLOYEE`
- **Role:** `employee`
- **Module:** Authentication, Session & Privacy
- **Use Cases:** `UC-SH-02`
- **Surface:** Web sign-in
- **Route/API:** GET/POST /auth/login; GET /portal
- **Actor/grants:** Active employee with personal-portal grants
- **Preconditions:** A synthetic active employee with an MIS identity and linked employee person; main host available; clean browser profile.
**Steps**
1. Submit the employee's valid university credentials on the main-host sign-in form.
2. Open /portal and the employee's own appointments/records tabs.
- **Expected:** The employee reaches the personal portal and can view only self-service resources granted to the employee role.
- **Negative/non-disclosure:** Clinic, Guidance, Facilities, and another person's records are not exposed merely because the actor is an employee.
- **Cleanup:** Sign out and clear the test browser's main-host data.
- **Source:** app/Services/AuthService.php:80-97; app/Services/PortalContext.php:138-179
- **Evidence:** Landing and own-resource screenshots, sanitized network redirects, and a denied workstation navigation observation.
- **Verdict:** `NOT_RUN`

### Personal Portal & Self\-Service \(4 cases\)

#### GD-INT-001 — Employee portal resolves only exact active students; Guidance resolver remains staff-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.EXACT_SUBJECT`
- **Role:** `employee`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-08`
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

#### GD-INT-002 — Employee multi-student referral fans out encrypted intake records atomically
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.PORTAL_SUBMIT`
- **Role:** `employee`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-08`
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

#### RF-004 — General referrer gains no clinical-record access
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-004`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.NONDISCLOSURE.REFERRER`
- **Role:** `employee`
- **Module:** Personal Portal & Self-Service
- **Use Cases:** `UC-PT-08`
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

#### GD-API-003 — Retired mobile self-referral endpoint remains unavailable
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Student self-referral is retired; the V1 API must not expose a route or controller action that creates `self` intake referrals.
- Role: `employee`
- Module: Personal Portal & Self-Service
- Use Cases: `UC-PT-08`
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

## 5. Department Workstation Member \(Clinic, Guidance & BMG\)

> **Role ID:** `workstation_member` · **Classification:** `shared_group` · **Cases:** **39**  
> UML Inheritance: extends **All Authenticated Roles (Shared)** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Capabilities shared across Clinic, Guidance, and BMG department workstation members and interns: Team Workspace (Tasks, Calendar, Chat web & mobile), shared scheduling slots, interdepartmental referral lifecycle, and mobile read-only queue summary.

### Clinic Operations & Extensions \(2 cases\)

#### PT-003 — Staff confirmation assigns a provider and detects stale or occupied slots
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

#### PT-SCH-001 — Scheduling uses staff assignments and Manila wall time
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

### Interdepartmental Referrals \(4 cases\)

#### RF-005 — Receiving unit acknowledges a submitted referral
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-005`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.ACKNOWLEDGE`
- **Role:** `workstation_member`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-02`
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

#### RF-006 — Receiving unit starts review without creating a target record
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-006`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.REVIEW`
- **Role:** `workstation_member`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-02`
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

#### RF-009 — Reviewed or linked referral closes into history
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-009`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.CLOSE`
- **Role:** `workstation_member`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-04`
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

#### RF-010 — Referral filters use current referral statuses and units
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-010`
- **Changed by:** baseline
- **Requirement:** `REFERRAL.LIST.FILTERS`
- **Role:** `workstation_member`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-02`
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

### Team Workspace \(Web & Mobile\) \(28 cases\)

#### TW-WEB-001 — Workstation membership isolates Team Workspace by tenant and unit
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e08f383`, `884b9f0`, `62c790b`
- **Requirement:** `TEAM.WEB.MEMBERSHIP`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Web Team Workspace
- **Route/API:** GET /{clinic|guidance|facilities}/teams
- **Actor/grants:** Active workstation member (including `clinic_intern`, `guidance_intern`, `bmg_intern`); nonmember/other-unit controls
- **Preconditions:** Canonical 16-role catalog reconciled; synthetic members across Clinic, Guidance, and BMG units/tenants; same IDs/content sentinels across units.
**Steps**
1. Open each authorized Team board with department staff, interns, and multi-unit `superadmin` actors and inspect member/task scope.
2. As student/employee nonmember or different-unit/foreign-tenant actor, request the direct Team URLs.
- **Expected:** Membership comes from current active workstation roles (including active supervised interns) and every board is tenant/unit isolated; nonmembers and cross-unit actors receive 403.
- **Negative/non-disclosure:** Team membership grants no clinical-record access and no cross-unit task/member sentinel appears.
- **Cleanup:** Delete/reset Team fixtures and sign out all actors.
- **Source:** app/Services/TeamAccess.php:14-116; app/Services/PortalContext.php:144-186
- **Evidence:** Role/unit access matrix, member lists, direct-URL denials, and negative sentinel search.
- **Verdict:** `NOT_RUN`

#### TW-WEB-002 — Member creates an operational task with bounded checklist
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.TASK_CREATE`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Team board/list
- **Route/API:** GET/POST /{unit}/teams/create|store
- **Actor/grants:** Active workstation member
- **Preconditions:** Synthetic operational title/description with no patient data; optional eligible assignee/due date; 1–20 checklist items.
**Steps**
1. Create the task with assignee, priority, due date, and checklist; inspect board/list/detail.
2. Try 21 items, ineligible assignee, invalid date, and a patient-data sentinel only in a disposable rejected-input check.
- **Expected:** Valid task appears once with version 1 and decoded checklist; invalid inputs create no task.
- **Negative/non-disclosure:** No patient linkage/file attachment exists, input is escaped, and task assignment confers no clinical access.
- **Cleanup:** Remove/reset task/comments/activity in disposable database (no production delete UI exists).
- **Source:** docs/TEAMS.md:11-40; app/Services/TeamTaskService.php:126-169,298-370
- **Evidence:** Create form/result, board/list/detail, checklist/version, and invalid-input row-count proof.
- **Verdict:** `NOT_RUN`

#### TW-WEB-003 — Task editing and moves enforce editor policy and optimistic version
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.TASK_VERSION`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Team board/task detail
- **Route/API:** POST /{unit}/teams/{id}/update|move
- **Actor/grants:** Creator, assignee, manager, ordinary member
- **Preconditions:** Synthetic task version 1; two browser sessions; each actor role present.
**Steps**
1. Edit/move as creator or assignee using version 1 and observe version 2/activity.
2. From stale session submit version 1, then attempt edit as an ordinary non-editor member.
- **Expected:** Authorized current-version change succeeds transactionally; stale change returns 409; non-editor receives 403 without state change.
- **Negative/non-disclosure:** No silent last-write-wins, custom within-column order claim, or client override of tenant/author/version occurs.
- **Cleanup:** Reset synthetic task/activity.
- **Source:** docs/TEAMS.md:17-34; app/Services/TeamTaskService.php:170-268
- **Evidence:** Two-session version/conflict trace, actor access matrix, final task/activity state.
- **Verdict:** `NOT_RUN`

#### TW-WEB-004 — Task comments persist with bounded privacy-safe activity
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.TASK_COMMENT`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Task detail
- **Route/API:** POST /{unit}/teams/{id}/comments
- **Actor/grants:** Any active workstation member
- **Preconditions:** Synthetic task; 51 synthetic comments/activity events; creator/assignee recipients; no sensitive text.
**Steps**
1. Post a comment and reload detail/activity as another unit member.
2. Inspect limits/totals and notification preview; submit empty/oversized text.
- **Expected:** Comment persists once, activity records metadata, latest 50 entries and totals/limit notice render, and intended generic notifications commit with the comment.
- **Negative/non-disclosure:** Comment body is absent from activity/notification previews/logs and other units cannot read/post.
- **Cleanup:** Reset comments/activity/notifications/task.
- **Source:** docs/TEAMS.md:36-42,98-118; app/Services/TeamTaskService.php:182-228
- **Evidence:** Comment/detail/history limits, notification preview, invalid input, and cross-unit denial.
- **Verdict:** `NOT_RUN`

#### TW-WEB-005 — API idempotency produces one web-visible task or comment
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** e08f383
- **Requirement:** `TEAM.API.WEB_PROJECTION_IDEMPOTENCY`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-01`
- **Surface:** Bearer Team API mutation projected into the web Team board
- **Route/API:** POST /api/v1/team/{unit}/tasks or /tasks/{id}/comments; GET /{unit}/teams[/tasks/{id}]
- **Actor/grants:** Active workstation member with both an authorized bearer identity and web workstation session
- **Preconditions:** Synthetic task/comment payload; valid UUID-v4 nonce; Team API migration present; web row/activity/notification counts recorded.
**Steps**
1. Submit a task or comment once through the bearer Team API, replay the identical nonce/payload through that same API route, and then open the web board/detail.
2. Reuse the nonce with changed content through the API and reload web board/detail/notifications.
- **Expected:** Exact API retry resolves to the original task/comment with one side effect visible on the web surface; changed payload conflicts and web state remains singular.
- **Negative/non-disclosure:** The web controller is not claimed to originate client nonces; nonce is not shown to users, and conflict does not append activity/notification or overwrite content.
- **Cleanup:** Reset task/comment/notifications.
- **Source:** app/Controllers/Api/V1/Team/TasksController.php:45-95; app/Services/TeamTaskService.php:126-228,413-503; docs/TEAMS.md:125-132
- **Evidence:** Original/replay/conflict API responses and web-visible row/activity/notification counts.
- **Verdict:** `NOT_RUN`

#### TW-WEB-006 — Calendar views preserve bounded filters and task deadlines
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CALENDAR_VIEWS`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-02`
- **Surface:** Team calendar
- **Route/API:** GET /{unit}/teams/calendar
- **Actor/grants:** Active workstation member
- **Preconditions:** Synthetic events/tasks spanning month/week/agenda boundaries, multi-day/midnight event, >limit optional truncation fixture.
**Steps**
1. Switch month, week, and 30-day agenda while preserving date/mine/status filters.
2. Compare event overlap and task due-date placement, including midnight end and completed marker.
- **Expected:** Views use Manila input/display, UTC storage, correct overlap rules, live task due dates, and bounded results/truncation notice.
- **Negative/non-disclosure:** Calendar does not reserve appointments, detect clinical conflicts, or reveal other units/tenants.
- **Cleanup:** Reset calendar/task fixtures.
- **Source:** docs/TEAMS.md:48-61; app/Services/TeamCalendarService.php:67-104,306-342
- **Evidence:** Three view screenshots, filter URLs, boundary-event placement, and truncation/task reconciliation.
- **Verdict:** `NOT_RUN`

#### TW-WEB-007 — Event creation validates participants and Manila interval
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.EVENT_CREATE`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-02`
- **Surface:** Team calendar event form
- **Route/API:** POST /{unit}/teams/calendar/events/store
- **Actor/grants:** Active workstation member
- **Preconditions:** Synthetic title/location; future Manila start/end under 31 days; eligible participant and ineligible/foreign controls.
**Steps**
1. Create the event with eligible participants and inspect detail/calendar/invitations.
2. Attempt end-before-start, >31-day duration, duplicate/>100/ineligible participant cases.
- **Expected:** Valid event stores UTC timestamps/version 1 and current eligible participants; invalid cases create no event.
- **Negative/non-disclosure:** Participation grants no edit or clinical-record access; event does not send email/SMS or include sensitive text in notifications.
- **Cleanup:** Cancel/reset synthetic event/notifications.
- **Source:** docs/TEAMS.md:52-60; app/Services/TeamCalendarService.php:115-158,261-301
- **Evidence:** Form/detail/calendar, stored-vs-Manila timestamps, invitations, and invalid row-count proof.
- **Verdict:** `NOT_RUN`

#### TW-WEB-008 — Event edit and terminal cancellation enforce organizer/manager and version
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.EVENT_AUTH_VERSION`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-02`
- **Surface:** Team calendar event
- **Route/API:** POST /{unit}/teams/calendar/events/{id}/update|cancel
- **Actor/grants:** Organizer, participant, manager/supervisor
- **Preconditions:** Synthetic event in two sessions with organizer and participant; current version; inactive former participant optional.
**Steps**
1. Attempt edit as participant, then edit as organizer or manager with current version.
2. Submit stale version, cancel with current version, then attempt another edit/cancel.
- **Expected:** Participant cannot edit; authorized current update succeeds; stale fails 409; cancellation is terminal and retained, with idempotent current-repeat behavior.
- **Negative/non-disclosure:** Cancellation does not delete history/participants or require former participant eligibility; no clinical schedule changes.
- **Cleanup:** Reset event/notifications.
- **Source:** docs/TEAMS.md:52-59; app/Services/TeamCalendarService.php:160-203
- **Evidence:** Actor/version matrix, updated detail, cancelled history/filter, and terminal edit denial.
- **Verdict:** `NOT_RUN`

#### TW-WEB-009 — Chat sends immutable message with task links and reply
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CHAT_SEND`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Department Team chat
- **Route/API:** POST /{unit}/teams/chat/send
- **Actor/grants:** Active workstation member
- **Preconditions:** Same-unit active tasks, existing reply target, valid UUID-v4 nonce, <=2000-character synthetic message; foreign task/message controls.
**Steps**
1. Send a message with multiple task references and a reply target; inspect rendered message/link/quote.
2. Try foreign/inactive task, cross-unit reply, empty/oversized body, then look for edit/delete actions.
- **Expected:** Valid immutable message appears chronologically with authorized links/reply; invalid references/input fail.
- **Negative/non-disclosure:** No edit/delete/attachment/DM exists, no clinical data is copied, and other units/tenants cannot resolve references.
- **Cleanup:** Reset synthetic chat/message/task fixtures in disposable database.
- **Source:** docs/TEAMS.md:63-80; app/Services/TeamChatService.php:72-158,291-339
- **Evidence:** Composer/result, linked tasks/reply rendering, invalid matrix, and absence of edit/delete controls.
- **Verdict:** `NOT_RUN`

#### TW-WEB-010 — Chat nonce replay is idempotent and changed reuse conflicts
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CHAT.NONCE`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Department Team chat
- **Route/API:** POST /{unit}/teams/chat/send
- **Actor/grants:** Message sender
- **Preconditions:** Synthetic message/nonce; optional task/reply/mention metadata; message and notification counts captured.
**Steps**
1. Send once, simulate lost response, and retry identical nonce/payload.
2. Reuse the nonce with changed body/reference/mention/reply and reload history/inbox.
- **Expected:** Identical retry returns the original message and notifications once; changed reuse returns 409 without a new message.
- **Negative/non-disclosure:** Nonce is visible only to its sender and no automatic replay changes locked draft content.
- **Cleanup:** Reset message/notifications.
- **Source:** docs/TEAMS.md:78-80; app/Services/TeamChatService.php:72-158,273-289
- **Evidence:** Original/retry/conflict responses and singular message/notification counts.
- **Verdict:** `NOT_RUN`

#### TW-WEB-011 — Validated mention delivers one generic account-owned notification
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** baseline
- **Requirement:** `TEAM.WEB.CHAT.MENTION`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Team chat and notification inbox
- **Route/API:** POST /{unit}/teams/chat/send; GET /notifications
- **Actor/grants:** Active sender and current same-unit mentioned member
- **Preconditions:** Synthetic Unicode/emoji message with exact @name UTF-16 range; duplicate/self/foreign mention controls; mention migration present.
**Steps**
1. Send a message mentioning the same other member twice and self once; inspect recipient/sender inboxes and exact-message link.
2. Submit invalid range/text/foreign member mention and inspect message/notification transaction.
- **Expected:** One generic recipient notification commits and links to authorized exact message; self/repeated mention adds none; invalid mention rolls back send.
- **Negative/non-disclosure:** Notification preview contains no sender/body/task/quote text and inaccessible recipient/message targets are not exposed.
- **Cleanup:** Reset message/notifications/read state.
- **Source:** docs/TEAMS.md:69-80,92-96; app/Services/TeamChatService.php:313-377
- **Evidence:** Rendered mention, recipient-only generic notification/link, sender absence, and invalid transaction rollback.
- **Verdict:** `NOT_RUN`

#### TW-WEB-012 — Chat history pagination, auto-backread, and read cursor reconcile
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `d43923d`
- **Requirement:** `TEAM.WEB.CHAT.HISTORY_READ`
- **Role:** `workstation_member`
- **Module:** Team Workspace (Web & Mobile)
- **Use Cases:** `UC-TW-03`
- **Surface:** Team chat (auto-backread pagination and circular scroll-to-latest button)
- **Route/API:** GET /{unit}/teams/chat/history; POST /{unit}/teams/chat/read
- **Actor/grants:** Active workstation member
- **Preconditions:** At least 45 synthetic messages with own/other senders; unread cursor before page; around-message deep link; optional realtime disabled for poll fallback.
**Steps**
1. Open chat, scroll upward to trigger automatic backread pagination (and open an around-message link), observing fixed 40-page/window behavior and circular scroll-to-latest control visibility.
2. Click scroll-to-latest or acknowledge the last fully visible message, then add messages and observe unread count/reconciliation.
- **Expected:** History backreads seamlessly at the top viewport edge, jump-to-latest appears cleanly when scrolled back, read cursor increases monotonically only on visible acknowledgement, and own messages exclude from unread.
- **Negative/non-disclosure:** Browsing older/around history does not mark unseen messages read, no public receipts appear, and foreign target IDs fail.
- **Cleanup:** Reset messages/read cursor and realtime test state.
- **Source:** docs/TEAMS.md:73-79; app/Services/TeamChatService.php:42-71,178-241; public/assets/js/team-chat.js:1-120
- **Evidence:** Backread scroll/window captures, scroll-to-latest interaction, cursor/unread reconciliation, and foreign-target denial.
- **Verdict:** `NOT_RUN`

#### TW-MOB-001 — `/me` derives workspace units and features from fresh authority
- Lifecycle: `DRAFT`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`, `884b9f0`
- Requirement: Workspace entitlement must be server-derived from current bearer identity, feature rollout, live department membership, and available Team schema; only entitled units/features appear. Final rollout policy remains unresolved.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile context switcher and workspace tabs.
- Route/API: `GET /api/v1/me`.
- Actor/grants: Student, single-unit staff, multi-unit/superadmin, revoked-role variants.
- Preconditions: Team Workspace rollout explicitly set for disposable test; deterministic roles/schema.

**Steps**
1. Sign in as each role variant and compare `/me.entitlements.work.units/features/can_manage` with visible context/unit/tab choices.
2. Change role or disable rollout/schema support and refresh `/me` without restarting.
3. Directly request a previously visible unit API after revocation.

- Expected: UI mirrors current authorized features; revocation removes unit and server returns denial/not-found as appropriate; no stale client flag grants access.
- Negative/non-disclosure: Unauthorized unit/member/task/event/chat data and rollout internals are absent.
- Cleanup: Restore rollout/roles and retire DB.
- Source: `app/Controllers/Api/V1/MeController.php:24-47`; `app/Services/TeamEntitlements.php:20-70`
- Evidence: Role/entitlement/navigation/API matrix.
- Verdict: `NOT_RUN`

#### TW-MOB-002 — Unit path is the immutable Team scope
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`
- Requirement: Team unit is one of exact server route segments `clinic|guidance|facilities`; tenant, actor, role, and unit selectors in body/query cannot broaden scope, and every service reloads current bearer identity/membership.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: All Team Workspace APIs.
- Route/API: `/api/v1/team/{unit}/*`.
- Actor/grants: Member of exactly one unit; second-tenant fixture.
- Preconditions: Distinct records with overlapping numeric IDs in each unit/tenant.

**Steps**
1. Read/create in the authorized unit while adding forged tenant/actor/role/unit parameters.
2. Substitute another unit route and use IDs copied from the authorized unit.
3. Change the actor role after token issuance and repeat.

- Expected: Forged selectors are rejected/ignored; route scope and fresh membership decide; foreign unit/tenant IDs return denial/not-found without data.
- Negative/non-disclosure: Errors do not reveal whether a foreign ID exists or disclose members/content.
- Cleanup: Remove synthetic authorized-unit rows and restore role.
- Source: `app/Config/Routes.php:46-66`; `app/Services/TeamAccess.php:14-66`; `app/Services/TeamAccess.php:119-144`
- Evidence: Scope-tampering response matrix and row comparison.
- Verdict: `NOT_RUN`

#### TW-MOB-003 — Context switching protects dirty drafts and pending submissions
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Switching personal/work/unit contexts must prompt before discarding any retained draft, block while a submission is pending, run discard callbacks once after dialog dismissal, and never navigate beneath a native modal.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile context tab bar, unit picker, account sheet.
- Route/API: Client navigation only until a form submits.
- Actor/grants: User with personal plus at least two workspace units.
- Preconditions: Dirty forms in personal and workspace contexts; controllable slow submission.

**Steps**
1. Enter a draft, request context/unit switch, cancel the warning, and verify draft/navigation remain.
2. Request again, confirm, wait for dialog exit, and verify one discard then destination navigation.
3. Start a submission and attempt context/account/unit switches repeatedly.

- Expected: Cancel preserves draft; confirm discards selected draft exactly once after modal closes; pending work blocks switching with notice and no duplicate submit.
- Negative/non-disclosure: Draft text is not persisted, copied between units, logged, or visible behind the dialog.
- Cleanup: Discard all synthetic drafts and finish/cancel controlled requests.
- Source: `mobile/src/features/workspace/WorkspaceContext.tsx:158-235`; `mobile/src/features/workspace/WorkspaceContext.tsx:248-300`
- Evidence: Screen recording and request/discard callback count.
- Verdict: `NOT_RUN`

#### TW-MOB-004 — Revoked context disposes only its own drafts and locks
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Entitlement refresh must dispose drafts/submission registrations scoped to a revoked personal/unit context, retain still-authorized context work, close pending discard/destination state, and move to a valid context.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile WorkspaceProvider.
- Route/API: `GET /api/v1/me` refresh.
- Actor/grants: Multi-context user whose one unit or personal entitlement is revoked.
- Preconditions: Separate dirty drafts/slow operations in two authorized contexts.

**Steps**
1. Register distinguishable drafts in two units (and personal if available), then revoke only one unit and refresh profile.
2. Inspect active context, picker, drafts, notices, pending destination, and submissions.
3. Attempt delayed callback/navigation from revoked scope and continue editing retained scope.

- Expected: Only revoked-scope work is disposed/aborted; retained scope remains; app selects a remaining valid unit/personal context and rejects stale callback.
- Negative/non-disclosure: Revoked draft/data never appears in retained unit or after regrant; one context’s revocation does not erase unrelated authorized work.
- Cleanup: Restore role and discard retained synthetic drafts.
- Source: `mobile/src/features/workspace/WorkspaceContext.tsx:89-156`
- Evidence: Before/after scoped draft inventory and screen recording.
- Verdict: `NOT_RUN`

#### TW-MOB-005 — Task list is bounded, filtered, and unit-scoped
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Task list must support team/mine, text, status, priority, and page filters, return bounded counts/pagination, and compute editability from current actor/unit policy.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile Team Board/My Tasks.
- Route/API: `GET /api/v1/team/{unit}/tasks`.
- Actor/grants: Unit member and manager variants.
- Preconditions: Tasks across statuses/priorities/assignees/units/tenants and multiple pages.

**Steps**
1. Exercise team/mine, each status/priority, search, and paging; reconcile IDs/counts/meta.
2. Open as manager, creator, assignee, and ordinary member; compare `can_edit`/controls.
3. Tamper filters/page and unit/tenant IDs.

- Expected: Deterministic authorized subset/counts and edit controls; invalid inputs return bounded errors; foreign records never appear.
- Negative/non-disclosure: Descriptions/comments/activity from list projection and unauthorized member/task data are withheld.
- Cleanup: Retire task fixture DB.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:14-32`; `mobile/src/api/endpoints/teamTasks.ts:119-150`
- Evidence: Filter/result matrix and editability screenshots.
- Verdict: `NOT_RUN`

#### TW-MOB-006 — Task creation is idempotent per actor/unit/identical request
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Create requires valid task fields and UUIDv4 `client_nonce`; identical retry must return the same task, while nonce reuse with different content or scope conflicts and concurrent retries create one row/notification set.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile task form.
- Route/API: `POST /api/v1/team/{unit}/tasks`.
- Actor/grants: Current unit member.
- Preconditions: Valid assignee/member and deterministic nonce held only in memory for retry.

**Steps**
1. Submit a valid task, simulate an ambiguous response, and retry identical payload/nonce sequentially and concurrently.
2. Reuse nonce with changed title/checklist, another unit, and another actor.
3. Inspect task, activity, notification, and realtime invalidation counts.

- Expected: Identical same-scope retries resolve to one task; conflicting reuse is `409`; scope isolation applies; one create side-effect set exists.
- Negative/non-disclosure: Nonce is not a global resource key and does not expose another actor/unit’s result; invalid assignee/fields create nothing.
- Cleanup: Remove/retire synthetic task rows.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:45-58`; `app/Controllers/Api/V1/Team/TeamApiController.php:97-104`; `mobile/src/api/endpoints/teamTasks.ts:87-109`
- Evidence: Retry/concurrency HTTP trace and row/side-effect counts.
- Verdict: `NOT_RUN`

#### TW-MOB-007 — Task updates and status moves use optimistic version
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Full task update and status-only move must require current positive version, current edit authority, valid transition/input, and atomically increment version/activity/notifications; stale writers must refresh rather than overwrite.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile task detail/edit/board move.
- Route/API: `POST /api/v1/team/{unit}/tasks/{id}/update`; `/status`.
- Actor/grants: Authorized editor plus unauthorized member.
- Preconditions: One versioned task opened on two clients.

**Steps**
1. Update fields from client A and verify version increment/activity.
2. Submit client B’s stale full update and stale status move, then refresh and apply a valid move.
3. Attempt unauthorized edit, invalid checklist/status/assignee, terminal/no-op variants as supported.

- Expected: Current authorized mutation wins and increments once; stale/unauthorized/invalid variants do not overwrite or create side effects; refresh exposes winner.
- Negative/non-disclosure: Conflict does not return foreign hidden content, and mobile does not silently merge drafts.
- Cleanup: Retire task fixture DB.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:61-83`; `app/Controllers/Api/V1/Team/TasksController.php:100-121`
- Evidence: Version/activity timeline, conflict responses, and final task state.
- Verdict: `NOT_RUN`

#### TW-MOB-008 — Task comments are idempotent and bounded
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`
- Requirement: Comment creation must validate plain bounded text and UUIDv4 nonce, use current unit membership, create one comment/activity/notification set for identical retries, and return a bounded detail projection.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-01`
- Surface: Mobile task comments.
- Route/API: `POST /api/v1/team/{unit}/tasks/{id}/comments`; task detail GET.
- Actor/grants: Current unit member.
- Preconditions: Existing task, ambiguous-response harness, recipients.

**Steps**
1. Submit a valid comment and retry identical nonce/body after simulated response loss.
2. Reuse nonce with changed body and attempt blank/oversized/rich-script content plus foreign unit/task.
3. Reopen detail and reconcile comments/activity totals and notifications.

- Expected: One escaped plain-text comment and side-effect set; conflict/validation/foreign attempts create nothing; detail bounds are truthful.
- Negative/non-disclosure: HTML/script never executes; nonce does not retrieve other scope; notification omits sensitive comment text.
- Cleanup: Retire fixture DB.
- Source: `app/Controllers/Api/V1/Team/TasksController.php:86-97`; `mobile/src/api/endpoints/teamTasks.ts:174-175`
- Evidence: HTTP/row/notification counts and rendered escaping capture.
- Verdict: `NOT_RUN`

#### TW-MOB-009 — Calendar combines events and live task deadlines in Manila views
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`, `884b9f0`
- Requirement: Agenda/week/month listing must use Manila date boundaries, independently bound events/task deadlines to 250 each, sort combined items, support team/mine/status, and identify truncation without treating tasks as appointments.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-02`
- Surface: Mobile Team Calendar.
- Route/API: `GET /api/v1/team/{unit}/calendar`.
- Actor/grants: Unit member.
- Preconditions: Events spanning midnight/month, task due dates, mine/team/cancelled variants, boundary fixture.

**Steps**
1. Exercise each view/date/scope/status and reconcile start/end-exclusive Manila ranges, ordering, totals, and item types.
2. Test event overlapping range and task date at boundaries; force truncation fixture.
3. Compare calendar items with appointments/availability surfaces and foreign unit data.

- Expected: Correct Manila window and combined typed list; live task versions/statuses reflected; truncation flag truthful; no appointment semantics.
- Negative/non-disclosure: Other units/tenants and appointment/patient data are absent; timestamps are not misread as UTC wall time.
- Cleanup: Retire fixture DB.
- Source: `app/Services/TeamCalendarService.php:15-41`; `app/Services/TeamCalendarService.php:67-103`; `mobile/src/api/endpoints/teamCalendar.ts:5-82`
- Evidence: Boundary reconciliation worksheet and view screenshots.
- Verdict: `NOT_RUN`

#### TW-MOB-010 — Event create uses Manila wall time, eligible participants, and nonce
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Event create requires valid title and real Manila `YYYY-MM-DDTHH:mm` start/end with duration `(0,31d]`, up to 100 unique active same-unit participants, and UUIDv4 nonce idempotency.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-02`
- Surface: Mobile calendar event form.
- Route/API: `POST /api/v1/team/{unit}/calendar/events`.
- Actor/grants: Unit member.
- Preconditions: Eligible/ineligible members and ambiguous-response harness.

**Steps**
1. Create a valid event including participants and inspect returned explicit-offset instants/detail.
2. Retry identical nonce/payload sequentially/concurrently; reuse nonce with changed time/participants.
3. Test nonexistent/local-gap times if applicable, end-before/equal, >31 days, duplicates, >100, inactive/cross-unit participant.

- Expected: One event/participant/notification set for identical retries; valid Manila conversion is stable; conflicting/invalid variants reject atomically.
- Negative/non-disclosure: Event is not an appointment/availability block, and participant picker exposes only current eligible unit members.
- Cleanup: Cancel/retire synthetic event and DB.
- Source: `app/Controllers/Api/V1/Team/CalendarController.php:53-63`; `app/Services/TeamCalendarService.php:115-149`; `mobile/src/api/endpoints/teamCalendar.ts:64-82`
- Evidence: Time conversion, retry counts, participant/notification matrix.
- Verdict: `NOT_RUN`

#### TW-MOB-011 — Event update/cancel enforce edit authority and version
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `902fdd6`
- Requirement: Only manager or creator may update/cancel; update replaces all editable fields with current version, cancel is terminal and current-version repeat is no-op, while stale versions always conflict.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-02`
- Surface: Mobile event detail/edit/cancel.
- Route/API: `POST /api/v1/team/{unit}/calendar/events/{id}/update`; `/cancel`.
- Actor/grants: Creator, unit manager, participant/noneditor.
- Preconditions: Scheduled versioned event on two clients.

**Steps**
1. Update as creator/manager and verify full replacement, version, participants, notifications.
2. Submit stale update/cancel from second client; then cancel with fresh version and repeat current-version cancel.
3. Attempt update after cancellation and mutation as participant/noneditor/foreign unit.

- Expected: Authorized current writes succeed; stale/unauthorized/terminal update fail; cancel is terminal and documented repeat produces no duplicate side effects.
- Negative/non-disclosure: Removed participant notice remains readable only within current unit authority; event text is not copied into notification/audit logs.
- Cleanup: Retire event fixture DB.
- Source: `app/Controllers/Api/V1/Team/CalendarController.php:66-85`; `app/Services/TeamCalendarService.php:29-41`
- Evidence: Version/status timeline, side-effect counts, and actor matrix.
- Verdict: `NOT_RUN`

#### TW-MOB-012 — Chat history cursors are bounded and mutually exclusive
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `a06b70b`
- Requirement: Chat history must return fixed 40-message latest/before/after/around windows with deterministic cursors/read summary; cursor modes are mutually exclusive and current unit membership is rechecked.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-03`
- Surface: Mobile Team Chat history/deep link.
- Route/API: `GET /api/v1/team/{unit}/chat/messages`.
- Actor/grants: Current unit member.
- Preconditions: More than 80 messages, known read marker, target message, foreign-unit canaries.

**Steps**
1. Load latest then older/newer windows; verify order, `has_more`, `next_before`, latest/unread/read IDs and no duplicates/gaps.
2. Open around-target deep link and verify target is included/scrolled without changing unit.
3. Submit multiple/invalid cursors and revoke membership mid-pagination.

- Expected: Bounded deterministic windows and summaries; invalid modes reject; revocation stops/clears history; target opens only in authorized unit.
- Negative/non-disclosure: Other unit/tenant messages, other senders’ nonces, and unbounded history never appear.
- Cleanup: Retire chat fixture DB.
- Source: `app/Controllers/Api/V1/Team/ChatController.php:14-26`; `mobile/src/api/endpoints/teamChat.ts:45-94`
- Evidence: Cursor ID sequences, target recording, and revocation response.
- Verdict: `NOT_RUN`

#### TW-MOB-013 — Chat send is nonce-idempotent with validated references
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`, `a06b70b`
- Requirement: Sending requires bounded plain body, UUIDv4 nonce, active same-unit task/reply references, and structurally valid member mentions using UTF-16 offsets; identical ambiguous retry creates one message/mention/notification set.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-03`
- Surface: Mobile Team Chat composer.
- Route/API: `POST /api/v1/team/{unit}/chat/messages`.
- Actor/grants: Current unit member.
- Preconditions: Active task, reply message, eligible mention target, ambiguous-response harness.

**Steps**
1. Send a message with task/reply/mention and retry identical nonce sequentially/concurrently.
2. Reuse nonce with changed content; test foreign/inactive task, reply, member, overlapping/wrong mention offsets, and oversized/blank body.
3. Inspect message projection for nonce visibility by sender versus another member and count notifications.

- Expected: One valid message/reference/mention/notification set; conflicting/invalid requests reject; nonce returns only on current actor’s own message.
- Negative/non-disclosure: HTML/script does not execute; foreign references/members/nonces and notification body text are withheld.
- Cleanup: Retire chat fixture DB.
- Source: `app/Controllers/Api/V1/Team/ChatController.php:39-62`; `mobile/src/api/endpoints/teamChat.ts:15-67`; `mobile/src/api/endpoints/teamChat.ts:131-147`
- Evidence: Retry/concurrency row counts, validation matrix, and two-user projections.
- Verdict: `NOT_RUN`

#### TW-MOB-014 — Chat read marker is monotonic and account-scoped
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `73b8a11`
- Requirement: Mark-read must accept a valid last message in the current unit, never move the marker backward, return authoritative last/unread/latest summary, and not alter another user/unit/account.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-03`
- Surface: Mobile Team Chat read behavior.
- Route/API: `POST /api/v1/team/{unit}/chat/read`.
- Actor/grants: Two current unit members.
- Preconditions: Messages before/after existing marker and foreign-unit ID.

**Steps**
1. Open chat/mark latest visible read and reconcile summary with history.
2. Submit an older ID, repeated ID, future/nonexistent ID, and foreign-unit message ID.
3. Compare member B’s marker and switch account during delayed mark request.

- Expected: Marker advances monotonically/idempotently only for current actor/unit; invalid/foreign requests reject; retired request has no new-account effect.
- Negative/non-disclosure: No other member’s marker/unread details or message existence leaks.
- Cleanup: Retire fixture DB and clear app state.
- Source: `app/Controllers/Api/V1/Team/ChatController.php:65-76`; `mobile/src/api/endpoints/teamChat.ts:139-147`
- Evidence: Marker/summary timeline and cross-user/unit/account matrix.
- Verdict: `NOT_RUN`

#### TW-MOB-015 — Realtime invalidation refetches the exact Team unit resources
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `c91477a`, `73b8a11`
- Requirement: Team task/calendar/chat hints must invalidate mapped query keys for the current entitled unit and active account, with authoritative refetch, bounded polling fallback, and no cross-unit cache overwrite.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-03`
- Surface: Mobile Team Board/Calendar/Chat.
- Route/API: User realtime grant plus Team GET APIs.
- Actor/grants: Unit member; second member mutates resources.
- Preconditions: Workerman on/off variants, two units with same IDs, account switch fixture.

**Steps**
1. Keep each Team screen open and mutate its resource from second member; verify hint-driven exact-unit refetch and final state.
2. Send mismatched unit/type/ID hints and compare query counters/cache.
3. Stop realtime for fallback, then switch account/unit while refetch is in flight.

- Expected: Current exact-unit resources converge; fallback repairs; mismatched/retired work cannot update cache or navigate.
- Negative/non-disclosure: Hint is not record truth and cannot expose/copy foreign unit/account data.
- Cleanup: Restore Workerman, sign out, retire DB.
- Source: `mobile/src/features/workspace/invalidateTeamResources.ts:1-20`; `mobile/src/features/realtime/useUserRealtime.ts:1-32`
- Evidence: Hint/query timeline and unit/account cache canary comparison.
- Verdict: `NOT_RUN`

#### TW-MOB-016 — Notification intent rechecks feature and identity after navigation commits
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `c179273`, `a06b70b`
- Requirement: Team push/inbox targets must parse only supported kind/unit/positive ID, bind to active connection owner/account, recheck `/me` feature entitlement before and after unit tree commit, respect draft/submission guards, and consume rejected targets without replay loops.
- Role: `workstation_member`
- Module: Team Workspace (Web & Mobile)
- Use Cases: `UC-TW-03`
- Surface: Mobile notification → Team navigation.
- Route/API: Notification target projection, `/me`, target Team detail/history.
- Actor/grants: Entitled and later-revoked Team user.
- Preconditions: Valid/invalid task/event/chat targets; dirty draft and pending submission variants; two server-profile owners.

**Steps**
1. Open valid target from foreground/background notification while in another context; verify guarded unit switch then exact record.
2. Revoke feature/unit during transition and attempt malformed/unsupported/foreign targets.
3. Hold dirty draft or pending submission, then switch connection owner/account with a queued old intent.

- Expected: Valid current target opens after authorized unit commit; draft prompt/submission block applies; revoked/malformed/old-owner target shows one unavailable notice and is consumed.
- Negative/non-disclosure: No target crosses unit/account/server or bypasses feature gates; no repeated replay and no draft loss without confirmation.
- Cleanup: Clear intents/push, restore role, sign out, retire DB.
- Source: `mobile/src/features/workspace/navigationIntent.ts:7-75`; `mobile/src/features/workspace/NavigationIntentGate.tsx:16-95`
- Evidence: Navigation recordings and target/entitlement/owner outcome matrix.
- Verdict: `NOT_RUN`

### Realtime & Notifications \(2 cases\)

#### RT-005 — Queue board refreshes from authoritative GET after a hint
- Lifecycle: `READY`
- Legacy IDs: `RT-005`
- Changed by: `c91477a`, `73b8a11`, `c179273`
- Requirement: A matching `queue.invalidated` event is only a hint; the visible board must refetch its authorized GET, serialize refreshes, and update controls/history without accepting patient data from the event.
- Role: `workstation_member`
- Module: Realtime & Notifications
- Use Cases: `UC-CL-02`, `UC-GD-01`
- Surface: Clinic/guidance staff queue board.
- Route/API: `GET /{unit}/queue/board` plus private realtime event.
- Actor/grants: Staff member authorized for that unit’s queue.
- Preconditions: Two staff sessions, active queue fixture, Workerman running; named-region rollout state recorded.

**Steps**
1. Open the board in session A and mutate the same date/unit queue from session B.
2. Observe the event and resulting GET in A without manual refresh; verify rows, waiting count, call-next state, and no-show drawer against the server.
3. Publish mismatched unit/date and payload-with-fake-patient variants, then issue rapid matching hints during an in-flight GET.

- Expected: Only matching hints trigger a debounced serialized authoritative GET; fake event data never renders; final board equals server state with no duplicate request storm.
- Negative/non-disclosure: Events carry no patient record; unauthorized unit/date content is neither fetched nor shown; region rollout disabled falls back safely.
- Cleanup: Restore queue fixture and close sessions.
- Source: `public/assets/js/queue-realtime.js:1-81`; `public/assets/js/queue-realtime.js:129-205`
- Evidence: Screen recording, socket hint plus GET trace, and final server/UI comparison.
- Verdict: `NOT_RUN`

#### RT-007 — Team mention notification targets exact current member
- Lifecycle: `READY`
- Legacy IDs: `RT-007`
- Changed by: `e08f383`, `73b8a11`, `c179273`
- Requirement: A valid immutable team-chat mention must create an exact user-addressed notice linked to its source message and authorized unit; current membership is rechecked for inbox display and deep link.
- Role: `workstation_member`
- Module: Realtime & Notifications
- Use Cases: `UC-TW-03`
- Surface: Team chat and web/mobile notification inbox.
- Route/API: `POST /api/v1/team/{unit}/chat/messages`; notification endpoints.
- Actor/grants: Sender and mentioned active members of the same tenant/unit.
- Preconditions: Two members, a message with validated UTF-16 mention offsets, realtime/poll fallback available.

**Steps**
1. Send a message mentioning member B and inspect B’s unread inbox title/target and deep link to the exact message.
2. Verify a nonmentioned member and member in another unit/tenant receive no notice.
3. Remove B’s unit access and refresh inbox/deep link; attempt malformed offsets and a nonmember ID.

- Expected: One notification is bound to B/source unit/message; authorized deep link opens it; current access revocation withholds it; malformed/nonmember mentions reject or create no notice.
- Negative/non-disclosure: Message body is not copied into the notification projection, recipient lists do not leak, and sender cannot forge another unit/tenant target.
- Cleanup: Delete synthetic message/notification through DB retirement; restore role if changed.
- Source: `app/Services/NotificationService.php:261-314`; `app/Services/NotificationService.php:559-574`
- Evidence: Redacted sender/recipient inbox matrix and deep-link target trace.
- Verdict: `NOT_RUN`

### Mobile Application & Platform \(1 case\)

#### MB-011 — Staff queue monitor is entitlement-gated and read-only
- Lifecycle: `READY`
- Legacy IDs: `MB-011`
- Changed by: `bea71cf`, `902fdd6`
- Requirement: `/me` must derive staff queue unit entitlements from current permissions; mobile monitor is absent for personal-only users, returns only authorized aggregate summary for staff, and exposes no mobile queue mutation endpoint.
- Role: `workstation_member`
- Module: Mobile Application & Platform
- Use Cases: `UC-CL-02`, `UC-GD-01`
- Surface: Mobile navigation and staff queue monitor.
- Route/API: `GET /api/v1/me`; `GET /api/v1/staff/queue-summary`.
- Actor/grants: Student, clinic-only staff, guidance-only staff, dual-grant/superadmin variants.
- Preconditions: Deterministic queue summaries for both units.

**Steps**
1. Inspect navigation and `/me` entitlement for each role variant; open monitor where offered.
2. Request `all`, authorized unit, unauthorized unit, and invalid unit directly.
3. Search mobile routes/API for call/start/skip/complete actions and attempt representative nonexistent mutations.

- Expected: Personal user sees no monitor/gets `403`; staff sees only granted unit aggregates; invalid unit is `404`; no action endpoint/control exists.
- Negative/non-disclosure: Summary contains no patient identity/notes and read authority does not imply management authority.
- Cleanup: Restore grants and retire DB.
- Source: `app/Controllers/Api/V1/MeController.php:19-47`; `app/Controllers/Api/V1/StaffQueueApiController.php:13-55`; `app/Config/Routes.php:91-92`
- Evidence: Entitlement/navigation/API matrix and nonexistence/action denial proof.
- Verdict: `NOT_RUN`

### Responsive Web Contracts \(2 cases\)

#### UX-WEB-005 — Appointment and availability workspaces preserve complete form operation
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e5b7eba`, `884b9f0`
- Requirement: Booking/review modals and availability section navigation, filters, staff/windows tables, exceptions, date/time controls, and footers must remain operable on small screens; wide tables scroll in their wrapper, narrow controls stack, and date/time semantics remain Manila-local.
- Role: `workstation_member`
- Module: Responsive Web Contracts
- Use Cases: `UC-CL-08`, `UC-GD-11`
- Surface: Personal/staff appointments and staff availability.
- Route/API: `/portal/appointments/{unit}/create`; `/{unit}/appointments`; `/{unit}/appointments/availability`.
- Actor/grants: Personal user and authorized scheduling staff.
- Preconditions: Providers/windows/exceptions and validation conflicts; viewport/keyboard variants.

**Steps**
1. Complete booking and availability create/edit/filter flows at mobile widths with keyboard open; inspect modal/body/footer scroll and action reachability.
2. Exercise staff/windows tables and exception rows at 575/576/767/768, rotate, and resize with form values present.
3. Trigger validation/conflict and verify message/control focus remains visible.

- Expected: No control is obscured/clipped; section links/actions become full-width where defined; tables scroll locally; form values/state and Manila date/time meanings persist.
- Negative/non-disclosure: Layout does not switch personal/staff actor, expose unavailable providers/other records, or double-submit.
- Cleanup: Cancel/remove synthetic appointments/windows/exceptions.
- Source: `public/assets/css/pages/availability-workspace.css:1-42`; `app/Views/appointments/availability.php:1-122`
- Evidence: End-to-end mobile recording, overflow measurements, and validation focus captures.
- Verdict: `NOT_RUN`

#### UX-WEB-006 — Teams board, chat, and calendar adapt without semantic drift
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `e08f383`, `e5b7eba`, `a06b70b`, `884b9f0`
- Requirement: Team board becomes one column below 576 px; chat/calendar sidebars collapse below 1200 px; composer, messages, picker/dialog, calendar toolbar, and intentional calendar grid scroll remain reachable and scoped to the same unit/draft/version.
- Role: `workstation_member`
- Module: Responsive Web Contracts
- Use Cases: `UC-TW-01`, `UC-TW-02`, `UC-TW-03`
- Surface: Clinic/guidance/facilities Teams web.
- Route/API: `/{unit}/teams`, `/chat`, `/calendar`.
- Actor/grants: Unit member and manager variants.
- Preconditions: Four task columns, long messages/replies/mentions/tasks, populated calendar, unsaved composer/form.

**Steps**
1. At breakpoint boundaries traverse board/status actions, chat log/composer/pickers/replies, and calendar filters/grid/event forms.
2. Resize/rotate/zoom while composer/event/task draft is nonempty; inspect scroll anchors, selected unit, IDs, nonce/version, and action target.
3. Test keyboard, reduced motion, dark mode, and long unbroken text.

- Expected: Board stacks, chat/calendar rails collapse, intended inner regions scroll, composer/actions stay visible, draft/scope/version remain correct, and no page-level overflow or wrong-record submit occurs.
- Negative/non-disclosure: Responsive changes cannot switch unit, reveal foreign content, lose discard protection, or render raw HTML.
- Cleanup: Discard drafts and remove synthetic Team rows.
- Source: `public/assets/css/teams.css:1-104`; `public/assets/css/teams.css:106-168`; `public/assets/css/teams.css:191-237`
- Evidence: Breakpoint recordings, draft/scope assertions, and overflow report.
- Verdict: `NOT_RUN`

## 6. Clinic Intern

> **Role ID:** `clinic_intern` · **Classification:** `rbac` · **Cases:** **9**  
> UML Inheritance: extends **Department Workstation Member (Clinic, Guidance & BMG)** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Supervised student nurse/intern (`clinic_intern`): read-only patient/inventory/isolation/clearance views, Clinic queue operation, and open encounter vitals/notes drafting (cannot seal, add addenda, or dispense).

### Clinic Operations & Extensions \(9 cases\)

#### CL-001 — Clinic dashboard uses the current Manila business day
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-001`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.DASHBOARD.CURRENT_DAY`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-01`, `UC-CL-02`
- **Surface:** Clinic dashboard
- **Route/API:** GET /clinic
- **Actor/grants:** Clinic member with dashboard/queue/appointment read grants
- **Preconditions:** Synthetic clinic staff; controlled current Manila day with known appointments, tickets, and completed encounters in and outside the day window.
**Steps**
1. Sign in on the Clinic workstation and open /clinic.
2. Compare displayed queue, appointment, and completed-visit counts with the seeded current-day records.
- **Expected:** The dashboard renders current-day operational counts and recent activity for the tenant using the Manila day boundary.
- **Negative/non-disclosure:** No foreign tenant, Guidance case, confidential note, or stale cached role scope appears.
- **Cleanup:** Remove seeded dashboard records/reset clock and clear dashboard cache.
- **Source:** docs/SCHEDULING.md:71-102; app/Controllers/ClinicController.php:1-95
- **Evidence:** Dashboard screenshot with clock, sanitized fixture ledger, and count reconciliation.
- **Verdict:** `NOT_RUN`

#### CL-002 — Patient registry search is bounded and tenant-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-002`
- **Changed by:** 884b9f0
- **Requirement:** `CLINIC.PATIENT.SEARCH`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-01`
- **Surface:** Patient registry
- **Route/API:** GET /clinic/patients
- **Actor/grants:** Clinic actor with clinic.patients.read
- **Preconditions:** Same-tenant synthetic patients with unique exact ID/name fragments; foreign-tenant sentinel person; pagination threshold exceeded.
**Steps**
1. Search by exact synthetic MIS ID and by a bounded name fragment, then change page.
2. Search for the foreign-tenant sentinel and an invalid/unbounded query.
- **Expected:** Only matching same-tenant patients appear with bounded pagination and supported chart/start actions.
- **Negative/non-disclosure:** No foreign tenant, Guidance notes, password data, or unbounded directory dump is returned.
- **Cleanup:** Delete the synthetic directory fixtures or reset the database.
- **Source:** app/Config/Routes.php:500-505; app/Services/Clinic/PatientDirectoryService.php:1-115
- **Evidence:** Search/result/pagination screenshots and negative foreign sentinel query.
- **Verdict:** `NOT_RUN`

#### CL-005 — Encounter start requires a called Clinic ticket
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-005`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.CALLED_TICKET`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-02`, `UC-CL-03`
- **Surface:** Clinic queue to encounter
- **Route/API:** POST /clinic/encounters/start-for-ticket/{queueEntryId}
- **Actor/grants:** Clinic actor with clinic.encounters.create and queue.manage
- **Preconditions:** Synthetic same-day waiting ticket linked to the correct patient; optional checked-in appointment; no open encounter for the patient.
**Steps**
1. Attempt Start Visit while the ticket is still waiting.
2. Call the ticket, then start the visit and follow the returned encounter link.
- **Expected:** Waiting start is rejected; a called ticket starts exactly one in-progress encounter linked to the patient/ticket (and checked-in appointment when present).
- **Negative/non-disclosure:** A different patient's ticket, non-checked-in linked appointment, direct GET, or duplicate open encounter cannot be used to bypass the called-ticket rule.
- **Cleanup:** Seal/cancel the synthetic visit through supported workflow and resolve its ticket/appointment.
- **Source:** app/Services/Clinic/EncounterService.php:112-203; app/Config/Routes.php:477-481
- **Evidence:** Waiting-state rejection, called queue card, created encounter, and linkage/state evidence.
- **Verdict:** `NOT_RUN`

#### CL-006 — Vitals save advances an open encounter
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-006`
- **Changed by:** baseline
- **Requirement:** `CLINIC.ENCOUNTER.VITALS`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-03`
- **Surface:** Encounter stepper
- **Route/API:** POST /clinic/encounters/{id}/vitals
- **Actor/grants:** Clinic actor with clinic.encounters.write
- **Preconditions:** Synthetic in-progress encounter; valid boundary-safe BP, pulse, temperature, SpO2, weight, and height values.
**Steps**
1. Enter valid synthetic vitals and save.
2. Reload the encounter and patient vitals history, then attempt an out-of-range value.
- **Expected:** Valid vitals persist with derived BMI/display and the workflow advances; invalid values are rejected without replacing the saved set.
- **Negative/non-disclosure:** No sealed encounter is modified and no unsupported free-form field is accepted.
- **Cleanup:** Complete the synthetic encounter or delete it in disposable teardown.
- **Source:** app/Services/Clinic/EncounterService.php:234-269,446-520; app/Config/Routes.php:468-475
- **Evidence:** Stepper before/after, persisted vitals, calculated display, and validation error evidence.
- **Verdict:** `NOT_RUN`

#### CL-007 — Clinical draft survives reload until sealing
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-007`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.DRAFT`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-03`
- **Surface:** Encounter stepper
- **Route/API:** POST /clinic/encounters/{id}/draft; POST /clinic/encounters/{id}/assessment
- **Actor/grants:** Clinic actor with clinic.encounters.write
- **Preconditions:** Synthetic open encounter with no final disposition; recognizable but non-sensitive draft complaint/assessment/treatment text.
**Steps**
1. Save the partial complaint/assessment as a draft, leave the page, and reopen the encounter.
2. Update one draft field and verify the newest value without completing the visit.
- **Expected:** The open encounter resumes with the saved draft and preserves the latest valid values.
- **Negative/non-disclosure:** Draft failure does not invent success, duplicate treatment rows, expose other patients, or permit edits after sealing.
- **Cleanup:** Seal the encounter or remove all synthetic draft data in teardown.
- **Source:** app/Services/Clinic/EncounterService.php:446-531; app/Config/Routes.php:470-474
- **Evidence:** Draft save/reopen screenshots and underlying open-state comparison.
- **Verdict:** `NOT_RUN`

#### CL-011 — Walk-in queue follows called-to-consultation lifecycle
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-011`
- **Changed by:** baseline
- **Requirement:** `CLINIC.QUEUE.WALKIN_LIFECYCLE`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-02`
- **Surface:** Clinic queue board
- **Route/API:** POST /clinic/queue/walkin|call/{id}|start/{id}|complete/{id}
- **Actor/grants:** Clinic actor with clinic.queue.manage
- **Preconditions:** Synthetic patient without active/skipped ticket; current Manila day selected; second board session open.
**Steps**
1. Issue a routine walk-in and observe Waiting; call it and observe Called & Arriving.
2. Start consultation, then complete it, checking both board sessions after each transition.
- **Expected:** Ticket transitions waiting → called → in_consultation → completed with original timestamps preserved on idempotent repeats.
- **Negative/non-disclosure:** A patient cannot receive a second active/skipped ticket and later transitions cannot be applied out of order.
- **Cleanup:** Complete and delete/reset the synthetic queue records.
- **Source:** docs/SCHEDULING.md:104-124; app/Config/Routes.php:514-523
- **Evidence:** Board screenshots at each state, ticket timestamp/status ledger, and second-session reconciliation.
- **Verdict:** `NOT_RUN`

#### CL-012 — Skip, recall, and no-show obey queue transition rules
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-012`
- **Changed by:** baseline
- **Requirement:** `CLINIC.QUEUE.EXCEPTION_TRANSITIONS`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-02`
- **Surface:** Clinic queue board
- **Route/API:** POST /clinic/queue/skip|recall|no-show/{id}
- **Actor/grants:** Clinic actor with clinic.queue.manage
- **Preconditions:** Two called synthetic tickets; fixed clock; one recall within 60 minutes and one after deadline/no-show path.
**Steps**
1. Skip the first called ticket, recall it before skip_until, and verify it returns to waiting.
2. Skip or call the second ticket, advance beyond the allowed window as applicable, and mark no-show; attempt an invalid recall.
- **Expected:** Allowed transitions update the correct columns/timestamps; expired recall is refused and no-show is terminal.
- **Negative/non-disclosure:** skip_until is a recall deadline rather than a minimum wait; completed/no-show tickets cannot be recalled or silently reissued.
- **Cleanup:** Resolve/remove both synthetic tickets and restore the test clock.
- **Source:** docs/SCHEDULING.md:104-124; app/Config/Routes.php:517-523
- **Evidence:** State sequence, controlled-clock timestamps, valid recall, and invalid/terminal transition response.
- **Verdict:** `NOT_RUN`

#### CL-CORE-001 — One patient cannot acquire concurrent open Clinic visits
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.SINGLE_OPEN`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-03`
- **Surface:** Queue and encounter start
- **Route/API:** POST /clinic/encounters/start-for-ticket/{id}; POST /clinic/encounters/start-direct/{personId}
- **Actor/grants:** Clinic actor with clinic.encounters.create
- **Preconditions:** Synthetic patient has one open encounter; a second called ticket or direct-start attempt is prepared.
**Steps**
1. Open/resume the existing encounter and record its ID.
2. Attempt to start a second encounter for the same person through the other supported entry path.
- **Expected:** The second start is refused or resolves only to the matching existing visit; no duplicate in-progress encounter is created.
- **Negative/non-disclosure:** A ticket with different linkage cannot be attached to the existing visit and another patient's encounter ID is not leaked.
- **Cleanup:** Seal the original encounter and resolve all synthetic tickets.
- **Source:** app/Services/Clinic/EncounterService.php:112-203; app/Config/Routes.php:477-481
- **Evidence:** Existing encounter, second-start conflict, and database/UI count showing exactly one open visit.
- **Verdict:** `NOT_RUN`

#### CL-RBAC-002 — Intern cannot seal, add addenda, or write inventory
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49, 62c790b
- **Requirement:** `CLINIC.RBAC.INTERN_LIMITS`
- **Role:** `clinic_intern`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-01`, `UC-CL-03`, `UC-CL-04`
- **Surface:** Clinic workstation
- **Route/API:** Representative Clinic POST routes
- **Actor/grants:** Synthetic clinic_intern with current seeded read/create/write subset
- **Preconditions:** Canonical 16-role catalog and `StudentInternService` reconciled; intern fixture, open encounter, sealed encounter, medicine stock.
**Steps**
1. As clinic_intern, record an allowed open-encounter value, check the live shift clock, and inspect read-only inventory/isolation/clearance views.
2. Attempt encounter completion, post-visit addendum, inventory receipt/dispense, isolation write, and clearance write.
- **Expected:** Allowed intern work succeeds while every ungranted high-authority mutation is absent or returns 403 without state change.
- **Negative/non-disclosure:** No superadmin/host fallback or client-supplied permission converts the intern into a sealer/manager.
- **Cleanup:** Reset synthetic clinical records and remove the temporary intern fixture.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:173-181; app/Services/StudentInternService.php:37-54
- **Evidence:** Allowed action, hidden controls/403 responses for each denied mutation, and before/after no-change proof.
- **Verdict:** `NOT_RUN`

## 7. Clinic Staff

> **Role ID:** `clinic_staff` · **Classification:** `rbac` · **Cases:** **19**  
> UML Inheritance: extends **Clinic Intern** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Clinic Nurse/Staff (`clinic_staff`): full Clinic clinical encounters, dispensing, batch inventory & reversals, health profiles, labs, addenda, isolation tracker, clearances, Clinic reports/PDF, and Clinic↔Guidance referrals.

### Clinic Operations & Extensions \(17 cases\)

#### CL-003 — Patient chart separates authorized clinical histories
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-003`
- **Changed by:** 884b9f0
- **Requirement:** `CLINIC.PATIENT.CHART`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-05`
- **Surface:** Patient chart
- **Route/API:** GET /clinic/patients/{personId}
- **Actor/grants:** Clinic actor with clinic.patients.read and encounter read
- **Preconditions:** Synthetic patient with health profile, completed/open encounters, vitals, labs, and another patient's sentinel data.
**Steps**
1. Open the synthetic patient's chart and inspect each available history/profile section.
2. Attempt direct access with the other patient's foreign-tenant or unauthorized identifier under a reduced-grant actor.
- **Expected:** Authorized same-tenant chart sections render for the selected patient with correct histories.
- **Negative/non-disclosure:** Cross-tenant/unauthorized records and Guidance counselling content are absent; chart read does not mutate care state.
- **Cleanup:** Remove synthetic chart records and sign out reduced-grant actors.
- **Source:** app/Config/Routes.php:500-505; app/Controllers/ClinicController.php:472-565
- **Evidence:** Chart section screenshots, source-record reconciliation, and denied access proof.
- **Verdict:** `NOT_RUN`

#### CL-004 — Health profile update is permission-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-004`
- **Changed by:** baseline
- **Requirement:** `CLINIC.PATIENT.HEALTH_UPDATE`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-05`
- **Surface:** Patient chart
- **Route/API:** POST /clinic/patients/{personId}/health
- **Actor/grants:** Clinic actor with clinic.patients.write
- **Preconditions:** Synthetic patient with original blood type/allergies/conditions/emergency contact captured; read-only clinic intern control actor.
**Steps**
1. Update supported health fields using non-sensitive synthetic values and reload the chart.
2. Submit the same mutation as an actor lacking clinic.patients.write.
- **Expected:** Authorized values persist and the unauthorized mutation is rejected without change.
- **Negative/non-disclosure:** No unsupported field is mass-assigned and no other patient's profile is altered or disclosed.
- **Cleanup:** Restore original health values and clear sessions.
- **Source:** app/Config/Routes.php:500-503; app/Database/Seeds/PermissionsAndRolesSeeder.php:22-23,139-175
- **Evidence:** Before/after chart, permission-denial response, and scoped state proof.
- **Verdict:** `NOT_RUN`

#### CL-008 — Dispensation deducts usable stock once with ledger allocation
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-008`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.DISPENSE.USABLE_STOCK`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-06`
- **Surface:** Encounter medicine step
- **Route/API:** POST /clinic/encounters/{id}/dispense
- **Actor/grants:** Clinic actor with encounter write and clinic.inventory.write
- **Preconditions:** Open synthetic encounter; active medicine; two active unexpired batches with known expiries/stock; unique idempotency key.
**Steps**
1. Dispense a quantity spanning or selecting the earliest-expiring usable batch and confirm the action.
2. Reload encounter and inventory detail, recording dispensation, operation, movements, allocations, and totals.
- **Expected:** The patient dispensation is recorded once, usable batch stock decreases according to allocation order, and the append-only operation/movement ledger reconciles.
- **Negative/non-disclosure:** Expired/quarantined stock is not consumed; direct total_stock editing and duplicate side effects are impossible.
- **Cleanup:** Use the supported correction flow if needed, then remove/reset synthetic medicine and encounter fixtures.
- **Source:** app/Services/Clinic/InventoryService.php:620-655,728-799; app/Services/Clinic/EncounterService.php:323-342
- **Evidence:** Before/after stock, encounter dispensation, operation ID, allocation/movement rows, and idempotency key redacted.
- **Verdict:** `NOT_RUN`

#### CL-009 — Insufficient usable stock rejects the entire dispensation
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-009`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.DISPENSE.INSUFFICIENT`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-06`
- **Surface:** Encounter medicine step
- **Route/API:** POST /clinic/encounters/{id}/dispense
- **Actor/grants:** Clinic actor with encounter write and inventory write
- **Preconditions:** Open synthetic encounter; medicine with less usable stock than requested, plus expired/quarantined quantities that make physical stock appear larger.
**Steps**
1. Record all batch and ledger totals, then request more than usable stock.
2. Reload the encounter, medicine detail, batches, and operation ledger.
- **Expected:** The request receives a conflict/insufficient-stock result and creates no dispensation, stock movement, or quantity change.
- **Negative/non-disclosure:** Expired/quarantined physical stock is not treated as usable and partial allocation is not committed.
- **Cleanup:** Remove/reset the synthetic medicine and encounter fixtures.
- **Source:** app/Services/Clinic/InventoryService.php:620-655,845-919
- **Evidence:** Error UI/status and before/after zero-delta evidence across dispensation, batches, and ledger.
- **Verdict:** `NOT_RUN`

#### CL-010 — Encounter completion seals clinical state
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-010`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ENCOUNTER.SEAL`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-06`
- **Surface:** Encounter completion
- **Route/API:** POST /clinic/encounters/{id}/complete
- **Actor/grants:** Clinic physician/staff/admin with clinic.encounters.seal
- **Preconditions:** In-progress synthetic encounter with required complaint, assessment/disposition, any pending medicine resolved, and optional linked in-consultation ticket.
**Steps**
1. Complete the encounter with an allowed disposition and confirmation.
2. Reopen its detail and attempt a vitals/assessment/draft/completion mutation; inspect linked ticket/appointment.
- **Expected:** The encounter becomes completed or referred with completed_at, linked queue/appointment complete atomically, and mutable clinical steps reject later edits.
- **Negative/non-disclosure:** Completion does not overwrite sealed content, double-increment linked versions, or permit an actor lacking seal authority.
- **Cleanup:** Retain terminal fixture for addendum tests or delete it in disposable teardown.
- **Source:** app/Services/Clinic/EncounterService.php:347-445,568-577; app/Controllers/ClinicController.php:387-401
- **Evidence:** Completion confirmation, sealed detail/status/timestamps, linked states, and post-seal mutation denial.
- **Verdict:** `NOT_RUN`

#### CL-013 — Confirmed same-day appointment checks in to one queue ticket
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-013`
- **Changed by:** b868a49
- **Requirement:** `CLINIC.APPOINTMENT.CHECK_IN`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-08`
- **Surface:** Clinic appointments
- **Route/API:** POST /clinic/appointments/{id}/confirm; POST /clinic/appointments/check-in/{id}
- **Actor/grants:** Clinic scheduler with appointment write and queue authority
- **Preconditions:** Synthetic requested appointment for today in Manila; eligible staff assignment/slot; no active patient ticket.
**Steps**
1. Confirm the request with provider and exact future interval.
2. Use Check in to Queue and inspect appointment detail and Clinic board; repeat check-in once.
- **Expected:** The appointment moves confirmed → checked_in and exactly one linked waiting ticket is issued; duplicate/stale check-in is rejected or idempotently returns the existing link.
- **Negative/non-disclosure:** An unconfirmed, wrong-day, foreign-patient, or conflicting active-ticket appointment cannot create a queue entry.
- **Cleanup:** Call/start/complete or otherwise resolve the ticket and remove the synthetic appointment/availability.
- **Source:** app/Config/Routes.php:532-548; app/Services/Scheduling/QueueService.php:44-100
- **Evidence:** Request/confirmed/checked-in states, queue ticket linkage, and duplicate-attempt result.
- **Verdict:** `NOT_RUN`

#### CL-014 — Inventory receipt and adjustment are append-only operations
- **Lifecycle:** `READY`
- **Legacy IDs:** `CL-014`
- **Changed by:** b868a49, 884b9f0
- **Requirement:** `CLINIC.INVENTORY.RECEIVE_ADJUST`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-09`
- **Surface:** Clinic inventory
- **Route/API:** POST /clinic/inventory/{id}/receive; POST /clinic/inventory/batches/{id}/adjust
- **Actor/grants:** Clinic actor with clinic.inventory.write
- **Preconditions:** Active synthetic medicine; unexpired unique lot; known initial stock; unique request keys; supported adjustment reason.
**Steps**
1. Receive a quantity into the lot and inspect medicine detail, batch, operation, and movement rows.
2. Apply a supported positive/negative adjustment with reason and inspect the same ledger projections.
- **Expected:** Each action creates one immutable operation plus movements, updates batch remaining quantity, and reconciles physical/summary totals.
- **Negative/non-disclosure:** Stock is never edited directly; expired receipts, unsupported reasons, stale identity changes, and payload-changing key reuse are rejected.
- **Cleanup:** Reverse/offset approved test operations or reset the disposable inventory fixtures.
- **Source:** app/Services/Clinic/InventoryService.php:586-619,656-692,728-799; app/Config/Routes.php:483-498
- **Evidence:** Before/after totals, lot, operation/movement IDs, actor/reason/timestamps, and reconciliation calculation.
- **Verdict:** `NOT_RUN`

#### CL-EXT-001 — Signed addendum appends to a sealed encounter
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ADDENDUM.SEALED_ONLY`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-07`
- **Surface:** Sealed encounter
- **Route/API:** POST /clinic/encounters/{id}/addendum
- **Actor/grants:** Actor with clinic.encounters.addendum
- **Preconditions:** Synthetic completed encounter; author identity; one open encounter as negative control; non-sensitive addendum text.
**Steps**
1. Append a clinical-note addendum to the completed encounter and reload its transcript.
2. Attempt the same operation against the open encounter and attempt to edit the original sealed assessment.
- **Expected:** A new author/timestamp/type addendum appears without changing original sealed fields; the open-encounter addendum path is rejected.
- **Negative/non-disclosure:** No addendum is backdated into original content, deleted, or accepted from an actor lacking the grant.
- **Cleanup:** Remove/reset synthetic encounters and addenda in disposable teardown.
- **Source:** app/Services/Clinic/EncounterService.php:610-649; app/Controllers/ClinicController.php:403-432
- **Evidence:** Sealed transcript before/after, appended author/type/time, and open-encounter rejection.
- **Verdict:** `NOT_RUN`

#### CL-EXT-002 — Delayed lab result is linked as post-visit evidence
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.LAB.POST_VISIT`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-05`, `UC-CL-07`
- **Surface:** Patient chart and sealed encounter
- **Route/API:** POST /clinic/patients/{personId}/labs; POST /clinic/encounters/{id}/addendum
- **Actor/grants:** Actor with clinic.encounters.addendum
- **Preconditions:** Synthetic patient with sealed encounter; supported lab name/result/unit/reference/collected time; no real health data.
**Steps**
1. Record the synthetic lab result from the patient chart and link/append it to the sealed visit as supported.
2. Reload chart and encounter transcript and compare identifiers/timestamps.
- **Expected:** The lab appears once in patient history and as an append-only delayed result/addendum without reopening the encounter.
- **Negative/non-disclosure:** Future/invalid timestamps, foreign-patient links, and edits to original clinical assessment are rejected.
- **Cleanup:** Delete/reset synthetic lab/addendum fixtures.
- **Source:** app/Config/Routes.php:500-505; app/Controllers/ClinicController.php:566-575
- **Evidence:** Lab form/result and sealed transcript linkage with synthetic identifiers.
- **Verdict:** `NOT_RUN`

#### CL-EXT-003 — Enrollment clearance records and certificate stay patient-scoped
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.CLEARANCE.WORKFLOW`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-11`
- **Surface:** Patient chart and certificate
- **Route/API:** POST /clinic/patients/{personId}/clearances; GET /clinic/clearances/{id}/certificate
- **Actor/grants:** Actor with clinic.clearances.write/read
- **Preconditions:** Synthetic student, required exam/lab fields, clearance outcome, and second patient's clearance sentinel.
**Steps**
1. Record a synthetic enrollment clearance and reload the patient chart.
2. Download the certificate, then request the second patient's certificate under a reduced/foreign context.
- **Expected:** The clearance and certificate reflect the selected synthetic student and authorized outcome.
- **Negative/non-disclosure:** The certificate does not expose another patient, unsupported clinical fields, or a clearance not authorized by current grants.
- **Cleanup:** Remove generated artifact from evidence workspace after hashing/redaction and reset clearance fixture.
- **Source:** app/Config/Routes.php:503-505; app/Database/Seeds/PermissionsAndRolesSeeder.php:30-31
- **Evidence:** Chart row, certificate rendering/hash, and denied foreign/reduced-grant request.
- **Verdict:** `NOT_RUN`

#### CL-ISO-001 — Isolation tracker records an authorized case
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ISOLATION.CREATE`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-10`
- **Surface:** Clinic isolation tracker
- **Route/API:** GET /clinic/isolation; POST /clinic/isolation/store
- **Actor/grants:** Actor with clinic.isolation.write
- **Preconditions:** Synthetic patient and non-sensitive isolation category/date/status; read-only intern control actor.
**Steps**
1. Create the isolation entry and reload the tracker.
2. Attempt to create another entry as the read-only actor.
- **Expected:** Authorized entry appears tenant-scoped with actor/time; read-only mutation is refused.
- **Negative/non-disclosure:** No foreign tenant or unrelated chart detail is disclosed and no grant is inferred from host access.
- **Cleanup:** Clear/delete the synthetic isolation record using supported workflow/teardown.
- **Source:** app/Config/Routes.php:507-509; app/Controllers/ClinicIsolationController.php:1-88
- **Evidence:** Tracker before/after and read-only actor denial.
- **Verdict:** `NOT_RUN`

#### CL-ISO-002 — Isolation clearance preserves history
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.ISOLATION.CLEAR`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-10`
- **Surface:** Clinic isolation tracker
- **Route/API:** POST /clinic/isolation/{id}/clear
- **Actor/grants:** Actor with clinic.isolation.write
- **Preconditions:** Open synthetic isolation record with original start/actor metadata.
**Steps**
1. Clear the record with the supported action and reason/date.
2. Reload active and history views and attempt a repeated clear.
- **Expected:** The record becomes cleared with closure metadata while original history remains; repeated/stale clear does not duplicate the transition.
- **Negative/non-disclosure:** Clearance does not delete the row, alter another patient's status, or expose private details to read-only actors.
- **Cleanup:** Remove/reset the synthetic isolation record.
- **Source:** app/Config/Routes.php:507-509; app/Controllers/ClinicIsolationController.php:40-88
- **Evidence:** Open/cleared views, transition metadata, and repeated-action response.
- **Verdict:** `NOT_RUN`

#### CL-RPT-001 — Clinic summary report obeys period and tenant scope
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.REPORT.SUMMARY`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-12`
- **Surface:** Clinic reports
- **Route/API:** GET /clinic/reports
- **Actor/grants:** Actor with clinic.reports.export
- **Preconditions:** Synthetic encounters/dispensations/clearances distributed inside and outside a chosen period; foreign-tenant sentinels.
**Steps**
1. Open the report for the bounded synthetic period and reconcile displayed aggregates.
2. Change the period and submit invalid/foreign selector inputs.
- **Expected:** Counts and categories match same-tenant records in the period and filters remain bounded.
- **Negative/non-disclosure:** No patient-level confidential notes, foreign tenant rows, or unrestricted raw export appears.
- **Cleanup:** Remove report fixtures/reset database.
- **Source:** app/Config/Routes.php:511-512; app/Services/Reporting/ClinicSummaryReportService.php:1-257
- **Evidence:** Report screenshots, fixture-to-aggregate reconciliation, and invalid filter result.
- **Verdict:** `NOT_RUN`

#### CL-RPT-002 — Clinic PDF report matches the on-screen summary
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49, 62c790b
- **Requirement:** `CLINIC.REPORT.PDF`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-12`
- **Surface:** Clinic report export
- **Route/API:** GET /clinic/reports/pdf
- **Actor/grants:** Actor with clinic.reports.export
- **Preconditions:** Same bounded synthetic report period as CL-RPT-001; PDF compiler available in approved test environment; institutional header seal/logo assets present.
**Steps**
1. Generate the PDF from the selected report period and record filename/content type/page count.
2. Compare the unified institutional header (Foundation University Health Clinic seal, unit title, period metadata) and headline aggregates against the on-screen report.
- **Expected:** A readable PDF renders with the shared institutional header/footer and exact matching period and aggregate values.
- **Negative/non-disclosure:** The PDF contains no patient-level note text, foreign tenant data, formula payload, or server path/error output.
- **Cleanup:** Delete the downloaded PDF after retaining sanitized hash/render evidence.
- **Source:** app/Config/Routes.php:559-561; app/Services/Reporting/ClinicSummaryReportLatex.php:1-179; app/Services/Reporting/DocumentHeaderLatex.php:1-76
- **Evidence:** Download headers/hash, rendered PDF pages, and value comparison to the web summary.
- **Verdict:** `NOT_RUN`

#### CL-INV-001 — Inventory idempotency replays identical operations only
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.INVENTORY.IDEMPOTENCY`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-09`
- **Surface:** Inventory mutations
- **Route/API:** POST inventory receive/adjust/dispense with request key
- **Actor/grants:** Actor with clinic.inventory.write
- **Preconditions:** Synthetic medicine/batch/encounter; fixed valid key; before-counts for operations, movements, dispensations, and stock.
**Steps**
1. Submit a receipt or dispensation, then retry the identical request with the same key.
2. Reuse the key with a changed quantity or actor and reload all ledgers/totals.
- **Expected:** The identical retry returns the original operation without new side effects; changed payload/actor reuse receives a conflict.
- **Negative/non-disclosure:** No duplicate stock movement/dispensation or key value is exposed in UI/log evidence.
- **Cleanup:** Reverse/reset only the single original synthetic operation.
- **Source:** app/Services/Clinic/InventoryService.php:728-799,933-960
- **Evidence:** Original/replay responses, conflict response, row counts, and unchanged totals after replay.
- **Verdict:** `NOT_RUN`

#### CL-INV-002 — Quarantine and expiry remove batches from usable stock
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49, 884b9f0
- **Requirement:** `CLINIC.INVENTORY.USABLE_PROJECTION`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-09`
- **Surface:** Inventory detail/catalog
- **Route/API:** POST /clinic/inventory/batches/{id}/status; GET /clinic/inventory
- **Actor/grants:** Reader plus manager with clinic.inventory.write
- **Preconditions:** Synthetic medicine with active unexpired, quarantined, near-expiry, and expired batches; fixed current date.
**Steps**
1. Open catalog/detail and record physical, usable, quarantined, expired, near-expiry, and next-expiration values.
2. Quarantine the active batch, then attempt to reactivate an expired batch and dispense from the medicine.
- **Expected:** Projections update from batch state; quarantined/expired stock is not usable, and expired reactivation/dispensing is refused.
- **Negative/non-disclosure:** Physical stock is not silently destroyed and direct cache/total edits cannot override ledger invariants.
- **Cleanup:** Restore eligible test batch status or reset synthetic inventory.
- **Source:** app/Services/Clinic/InventoryService.php:161-228,672-692,845-919
- **Evidence:** Catalog/detail projections before/after, status operation, and denied expired release/dispense.
- **Verdict:** `NOT_RUN`

#### CL-INV-003 — Dispensation correction requires manager authority and explicit custody mode
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `CLINIC.INVENTORY.REVERSAL`
- **Role:** `clinic_staff`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-09`
- **Surface:** Inventory operation detail
- **Route/API:** POST /clinic/inventory/operations/{id}/reverse
- **Actor/grants:** Clinic inventory manager/write actor; non-manager control actor
- **Preconditions:** Complete synthetic dispensation with intact allocation ledger; one record_only path and one approved restore_unissued fixture; unique keys.
**Steps**
1. As the non-manager, attempt correction; then as the manager record a correction with reason and selected mode.
2. Inspect reversal linkage/movements and attempt a second correction of the same operation.
- **Expected:** Unauthorized correction fails; one append-only reversal links to the original, and stock is restored only when restore_unissued explicitly affirms clinic custody and batches remain active/unexpired.
- **Negative/non-disclosure:** Patient returns are not represented as restore_unissued, original ledger rows are not deleted, and double correction fails.
- **Cleanup:** Reset synthetic operation/stock or retain a balanced correction chain in disposable teardown.
- **Source:** app/Services/Clinic/InventoryService.php:693-727,728-799; app/Config/Routes.php:495-496
- **Evidence:** Authority denial, correction form/result, original/reversal linkage, and stock delta reconciliation.
- **Verdict:** `NOT_RUN`

### Interdepartmental Referrals \(2 cases\)

#### RF-001 — Clinic explicitly creates a referral to Guidance
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-001`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.CREATE.CLINIC_GUIDANCE`
- **Role:** `clinic_staff`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-01`
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

#### RF-007 — Clinic target handoff explicitly creates or links an open encounter
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-007`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.HANDOFF.CLINIC_CREATE_LINK`
- **Role:** `clinic_staff`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-03`
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

## 8. Clinic Physician

> **Role ID:** `clinic_physician` · **Classification:** `rbac` · **Cases:** **1**  
> UML Inheritance: extends **Clinic Staff** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Clinic Physician (`clinic_physician`): holds identical seeded permission grants to Clinic Staff with explicit grant-driven physician clinical sealing, addendum, and dispensing verification.

### Clinic Operations & Extensions \(1 case\)

#### CL-RBAC-001 — Physician authority includes sealing but remains grant-driven
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49, 62c790b
- **Requirement:** `CLINIC.RBAC.PHYSICIAN`
- **Role:** `clinic_physician`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-13`
- **Surface:** Clinic encounter/inventory
- **Route/API:** POST encounter complete/addendum/dispense
- **Actor/grants:** Synthetic clinic_physician with current seeded grants
- **Preconditions:** Canonical 16-role catalog reconciled (`PermissionsAndRolesSeeder.php`); physician fixture with open encounter and stock.
**Steps**
1. Sign in as clinic_physician and perform vitals/assessment, dispensation, and encounter sealing using granted actions.
2. Remove one grant through an approved isolated role fixture and repeat the affected action.
- **Expected:** Physician actions follow fresh database grants across the 16-role catalog, and removing a grant immediately removes the action/authorization.
- **Negative/non-disclosure:** Role name alone does not bypass a missing grant, tenant scope, inventory idempotency, or sealed-state rules.
- **Cleanup:** Restore the isolated role grants and reset encounter/inventory fixtures.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:145-157; app/Controllers/ClinicController.php:619-645
- **Evidence:** Role/grant snapshot, authorized workflow, revoked-grant denial, and restored fixture record.
- **Verdict:** `NOT_RUN`

## 9. Clinic Administrator

> **Role ID:** `clinic_admin` · **Classification:** `rbac` · **Cases:** **3**  
> UML Inheritance: extends **Clinic Staff** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Clinic Administrator (`clinic_admin`): extends Clinic Staff with Clinic announcements, Clinic employee access management, Clinic student intern placements, MIS sync, operational export, and Clinic/Team Archive & Restore.

### Clinic Operations & Extensions \(1 case\)

#### CL-ANN-001 — Clinic administrator publishes and manages scoped Clinic announcements
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `CLINIC.ANNOUNCEMENT.PUBLISH`
- **Role:** `clinic_admin`
- **Module:** Clinic Operations & Extensions
- **Use Cases:** `UC-CL-14`
- **Surface:** Clinic announcements console
- **Route/API:** GET /clinic/announcements; POST /clinic/announcements/store|{id}/update|{id}/publish|{id}/archive|{id}/restore
- **Actor/grants:** Active `clinic_admin` with `clinic.announcements.manage`; negative controls `clinic_staff` and `guidance_admin`
- **Preconditions:** Clean tenant with target portal users; negative control actors lacking `clinic.announcements.manage` or in a different unit.
**Steps**
1. As `clinic_admin`, create a draft Clinic announcement, edit it, publish it with optimistic version, verify target portal feed visibility, then archive and restore it cleanly.
2. Attempt the same create, publish, and cross-unit routes as `clinic_staff` (without `clinic.announcements.manage`) and `guidance_admin`.
- **Expected:** Only `clinic_admin` (and `superadmin`) manages the Clinic announcement lifecycle; published notices become immutable until archived/restored and unauthorized actors receive 403.
- **Negative/non-disclosure:** Draft/archived Clinic notices stay hidden from portal feeds and cross-unit managers cannot alter Clinic notices.
- **Cleanup:** Archive/remove the disposable announcement fixture.
- **Source:** app/Controllers/AnnouncementController.php:20-86; app/Config/Routes.php:221-232; app/Database/Seeds/PermissionsAndRolesSeeder.php:129-143
- **Evidence:** Create/publish/archive/restore screenshots, portal visibility proof, and 403 denial matrix.
- **Verdict:** `NOT_RUN`

### Archive Governance \(1 case\)

#### ARV-002 — Clinic administrator archives and restores only Clinic and Clinic Team records
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.SCOPE.CLINIC_ADMIN`
- **Role:** `clinic_admin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-05`
- **Surface:** Clinic inline archive controls and `/admin/archive`
- **Route/API:** GET /admin/archive; POST /clinic/encounters/{id}/archive|restore; POST /clinic/inventory/{id}/archive|restore; POST /clinic/isolation/{id}/archive|restore; POST /clinic/teams/{id}/archive|restore; cross-unit POST /admin/archive/records/archive
- **Actor/grants:** Active `clinic_admin` with `archive.read` and `archive.manage`
- **Preconditions:** Disposable Clinic sealed encounter/isolation/medicine and Clinic Team task/event plus Guidance case and BMG drum sentinels in the same tenant.
**Steps**
1. Archive and then restore eligible Clinic and Clinic Team records with a valid reason (>=5 chars) and observe the central archive ledger and audit events.
2. Search `/admin/archive` for Guidance and BMG sentinels, then submit a direct POST targeting a Guidance `counselling_case` or `bmg_unit`.
- **Expected:** Only Clinic (`clinic_encounter`, `clinic_isolation`, `clinic_clearance`, `clinic_medicine`, `announcement[clinic]`) and `teams[clinic]` records archive and restore reversibly; Guidance/BMG targets fail with 403 and remain absent from queries.
- **Negative/non-disclosure:** Active/open encounters cannot be archived prematurely, cross-unit identifiers disclose zero metadata, and audit payloads omit PHI.
- **Cleanup:** Restore or remove disposable fixtures in the test database.
- **Source:** app/Services/Archive/ArchiveAccessPolicy.php:69-161; app/Services/Archive/ArchiveService.php:56-119; app/Config/Routes.php:310-319
- **Evidence:** Clinic/Team before-and-after state, archive ledger rows, audit entries, and 403 cross-department rejection.
- **Verdict:** `NOT_RUN`

### Student Intern Governance \(1 case\)

#### INT-001 — Clinic administrator manages only Clinic student intern placements
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.SCOPE.CLINIC_ADMIN`
- **Role:** `clinic_admin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Student Interns & Supervision console (`/admin/interns`)
- **Route/API:** GET /admin/interns; GET /admin/interns/create; GET /admin/interns/students; POST /admin/interns/store|/{id}/update|/{id}/revoke
- **Actor/grants:** Active `clinic_admin` with `interns.manage`
- **Preconditions:** Disposable Clinic intern placement, Guidance and BMG intern placements, eligible enrolled student, and active Clinic supervisor (`clinic_staff`/`clinic_physician`/`clinic_admin`).
**Steps**
1. Open `/admin/interns` as `clinic_admin`, inspect status counts/filters, and search eligible students via `/admin/interns/students`.
2. Attempt to view, create, update, or revoke a `guidance` or `bmg` unit placement via direct route/POST parameters.
- **Expected:** Only `clinic` unit placements and Clinic supervisors appear; all cross-unit reads/writes (`guidance`/`bmg`) return HTTP 403 without modifying placements or user roles.
- **Negative/non-disclosure:** Self-supervision, already-placed students, non-student employees, and foreign-tenant students are excluded from selection.
- **Cleanup:** Reset disposable placements in the test database.
- **Source:** app/Services/StudentInternService.php:75-264; app/Controllers/StudentInternController.php:16-98; app/Config/Routes.php:293-302
- **Evidence:** Clinic-scoped listing screenshot, student search response, and 403 cross-unit matrix.
- **Verdict:** `NOT_RUN`

## 10. Guidance Intern

> **Role ID:** `guidance_intern` · **Classification:** `rbac` · **Cases:** **5**  
> UML Inheritance: extends **Department Workstation Member (Clinic, Guidance & BMG)** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Supervised Guidance intern (`guidance_intern`): Guidance queue operation, intake processing, assigned case & Clients directory browsing, and psychosocial/clinical form drafting for supervisor co-sign.

### Guidance & Counseling \(5 cases\)

#### GD-001 — Guidance dashboard respects caseload and current-day scope
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-001`
- **Changed by:** 884b9f0
- **Requirement:** `GUIDANCE.DASHBOARD.SCOPED`
- **Role:** `guidance_intern`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-01`, `UC-GD-03`
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

#### GD-INT-003 — Staff creates intake on behalf of an identified subject
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.STAFF_CREATE`
- **Role:** `guidance_intern`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-02`
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

#### GD-INT-004 — Intake receive and triage preserve hint-versus-risk distinction
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.RECEIVE_TRIAGE`
- **Role:** `guidance_intern`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-02`
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

#### GD-REC-001 — Clients directory lists existing same-tenant people only
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

#### INT-005 — Guidance intern operates queue and co-sign drafts without final clinical authority
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.BOUNDARY.GUIDANCE_INTERN`
- **Role:** `guidance_intern`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-01`, `UC-GD-02`, `UC-GD-03`, `UC-GD-04`
- **Surface:** Guidance Counselor Workspace (intern view)
- **Route/API:** GET /guidance; GET /guidance/clients; POST /guidance/queue/*; POST /guidance/intake/*; POST /guidance/clinical/forms/*
- **Actor/grants:** Active `guidance_intern` on dedicated Guidance host
- **Preconditions:** Active Guidance intern placement; waiting Guidance ticket; synthetic intake; assigned open case; canonical clinical form definition.
**Steps**
1. As `guidance_intern`, operate Guidance queue, process an intake, browse Clients Directory, and author a psychosocial/clinical draft form submitted for supervisor co-sign.
2. Attempt direct clinical finalization (`.../finalize`), co-signing (`.../cosign`), case closure/transfer/reassign, call slip issue, survey/announcement publish, and cross-case oversight (`read_any`).
- **Expected:** Allowed queue, intake, read, and draft-authoring workflows succeed cleanly; every ungranted finalization, co-sign, closure, transfer, publish, and oversight route returns 403 without state mutation.
- **Negative/non-disclosure:** `guidance_intern` cannot finalize independent clinical records, view unassigned counselling notes, or access Clinic/BMG consoles.
- **Cleanup:** Delete/reset disposable intake, draft, and placement fixtures.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:244-252; app/Services/PortalContext.php:154-160; app/Services/Guidance/GuidanceClinicalFormService.php:309-428
- **Evidence:** Allowed workflow captures, pending-cosign draft state, and 403 denial matrix for ungranted Guidance routes.
- **Verdict:** `NOT_RUN`

## 11. Counsellor

> **Role ID:** `counsellor` · **Classification:** `rbac` · **Cases:** **34**  
> UML Inheritance: extends **Guidance Intern** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Counsellor (`counsellor`): full owned caseload management, encrypted sessions & follow-ups, safety triage, episodes, case transfers, official OGC forms (PIF/PCF/CCF/GGR/GAP/CSP/PEF/CEF), call slips, MI assessments, and evaluation token issuance.

### Guidance & Counseling \(31 cases\)

#### GD-002 — New counselling case is owned by the assigned counsellor
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-002`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CASE.CREATE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-05`
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

#### GD-003 — Session draft finalizes to an encrypted immutable note
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-003`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.SESSION.NOTE_LIFECYCLE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-05`
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

#### GD-004 — Case access distinguishes owner, accepted consultant, and unrelated counsellor
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-004`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CASE.CONSULTATION_ACCESS`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-06`
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

#### GD-006 — Follow-up completion stores encrypted completion notes
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-006`
- **Changed by:** bea71cf
- **Requirement:** `GUIDANCE.FOLLOWUP.ENCRYPTED_OUTCOME`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-05`
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

#### GD-007 — Case closure requires and encrypts a disposition
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-007`
- **Changed by:** bea71cf
- **Requirement:** `GUIDANCE.CASE.ENCRYPTED_CLOSURE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-05`
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

#### GD-008 — Guidance queue starts sessions only from called tickets
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-008`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.QUEUE.CALLED_TICKET_SESSION`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-01`, `UC-GD-05`
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

#### GD-INT-005 — Accepted intake links to a matching case explicitly
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.ACCEPT_LINK_CASE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-05`
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

#### GD-INT-006 — Decline, withdraw, and external handoff are terminal controlled paths
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.TERMINAL_PATHS`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-02`, `UC-GD-05`
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

#### GD-INT-007 — Clinic referral bridge creates one Guidance intake link
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.INTAKE.CLINIC_BRIDGE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-02`, `UC-RF-03`
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

#### GD-PRC-003 — Direct finalization requires active licensed or supervisor standing
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.DIRECT_FINALIZE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-07`
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

#### GD-REC-002 — Cumulative record loads independently paginated authorized histories
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

#### GD-REC-003 — Record histories honor ownership, consultation, read-any, and section grants
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

#### GD-REC-004 — Confidential record read fails closed when audit cannot append
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

#### GD-FRM-002 — Controlled form creation validates the complete context graph
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

#### GD-FRM-003 — Encrypted draft autosave replaces one mutable draft
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

#### GD-FRM-004 — Finalization freezes an immutable revision and attestation
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

#### GD-FRM-006 — Issued controlled document has authorized preview and print boundary
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

#### GD-FRM-008 — Form read audit contains metadata but no plaintext
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

#### GD-EPI-001 — Episode opens with explicit matching links and qualified primary counsellor
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

#### GD-EPI-002 — Episode detail exposes only authorized exact-linked records
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

#### GD-EPI-003 — Episode closure encrypts notes and blocks later transfer
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

#### GD-CALL-001 — Qualified practitioner issues a numbered controlled call slip
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

#### GD-CLN-001 — Clinical form authoring validates practitioner, permission, and context
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

#### GD-OGC-001 — PIF-008, PCF-008, and CCF-009 conform verbatim to official OGC clinical schemas
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

#### GD-OGC-002 — General Referral GGR-005 matches all 21 official referral reasons and OGC actions
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

#### GD-OGC-003 — Appointment GAP-006 and Call Slip CSP-007 match official schedules, 6 purposes, and layout
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

#### GD-OGC-004 — Evaluation forms PEF-008 and CEF-009 match verbatim 1–5 items, visit types, and future-visit reason
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

#### GD-MI-001 — Counsellor assigns one active Multiple Intelligence assessment
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.MI.ASSIGN`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-09`
- **Surface:** MI assessment console
- **Route/API:** POST /guidance/assessments/mi/assign
- **Actor/grants:** Qualified Guidance actor with guidance.assessments.assign
- **Preconditions:** MI reproduction/signoff policy unresolved; synthetic person; active reviewed 80-item catalog; no active assignment.
**Steps**
1. Assign the MI assessment and inspect the counsellor list and target portal list.
2. Attempt a second active assignment for the same person and assignment by an actor without the grant.
- **Expected:** After policy approval, one assigned item appears for the target and duplicate active assignment/grantless action fails.
- **Negative/non-disclosure:** Assignment does not disclose answers/results to the assigner prematurely or create a diagnostic label.
- **Cleanup:** Cancel/delete the synthetic assignment/draft.
- **Source:** app/Services/Guidance/GuidanceMultipleIntelligenceService.php:265-355; app/Config/Routes.php:407-411
- **Evidence:** Counsellor/portal assignment state, duplicate and grant denial, and catalog revision evidence.
- **Verdict:** `NOT_RUN`

#### GD-EVL-001 — Qualified Guidance practitioner issues a one-time evaluation token through production workflow
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `e7d5572`
- **Requirement:** `GUIDANCE.EVALUATION.TOKEN_ISSUE`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`
- **Surface:** Guidance service evaluations console
- **Route/API:** GET /guidance/evaluations/dashboard; POST /guidance/evaluations/issue
- **Actor/grants:** Authorized Guidance `counsellor`, `guidance_supervisor`, or `guidance_admin` (`guidance.evaluations.view_aggregates` / supervisor any-provider scope)
- **Preconditions:** Eligible same-tenant client with optional matching episode or counselling session; active provider user ID; manifest synced.
**Steps**
1. Open `/guidance/evaluations/dashboard` and submit `POST /guidance/evaluations/issue` for `PEF-008` or `CEF-009` linked to the synthetic client/context.
2. Attempt duplicate issuance for the same person/context/type, cross-client session/episode mismatch, and unauthorized issuance by an unrelated role or ordinary counsellor specifying another provider.
- **Expected:** One raw token and main-portal redeem URL (`/portal/evaluations/redeem/{token}`) return once (`201`/redirect flash), only SHA-256 hash/HMAC eligibility are persisted, and duplicate/mismatched/unauthorized requests fail closed.
- **Negative/non-disclosure:** Raw token is never stored in plaintext or copied into `guidance_service_evaluations` or audit logs; `session_id`/`episode_id` belong strictly to the selected client.
- **Cleanup:** Expire/delete the synthetic token and context in disposable teardown.
- **Source:** app/Config/Routes.php:453-459; app/Controllers/GuidanceEvaluationController.php:500-596; app/Services/Guidance/GuidanceServiceEvaluationService.php:200-270
- **Evidence:** Token issuance form/JSON output (raw token redacted), stored hash/HMAC check, and duplicate/scope denial matrix.
- **Verdict:** `NOT_RUN`

#### GD-EVL-005 — Aggregate reporting enforces cohort threshold and provider scope
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.AGGREGATE_PRIVACY`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`
- **Surface:** Evaluation dashboard
- **Route/API:** GET /guidance/evaluations/dashboard
- **Actor/grants:** Counsellor viewing own aggregate; supervisor/admin viewing authorized providers
- **Preconditions:** Cohort threshold policy unresolved; below-threshold and at-threshold synthetic cohorts for two providers; moderated comments.
**Steps**
1. Open a below-threshold provider aggregate and then the at-threshold provider aggregate as that provider.
2. Attempt another provider as ordinary counsellor, then as authorized supervisor.
- **Expected:** After threshold policy approval, small cohorts are withheld; eligible own aggregates render; ordinary counsellor cannot view another provider; supervisor can as granted.
- **Negative/non-disclosure:** No respondent-level row, identity, token/context linkage, pending/redacted comment, or small-cell inference appears.
- **Cleanup:** Delete/reset synthetic cohorts.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:446-604,868-903; app/Config/Routes.php:413-417
- **Evidence:** Below/at-threshold screens, actor scope matrix, and aggregate-to-fixture reconciliation.
- **Verdict:** `NOT_RUN`

#### GD-EVL-006 — Aggregate PDF preserves threshold-safe output
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.AGGREGATE_PDF`
- **Role:** `counsellor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-10`
- **Surface:** Evaluation aggregate export
- **Route/API:** GET /guidance/evaluations/aggregate-pdf
- **Actor/grants:** Authorized aggregate viewer
- **Preconditions:** Cohort policy unresolved; eligible and suppressed synthetic provider cohorts; PDF compiler available.
**Steps**
1. Generate PDF for an eligible aggregate and compare ratings/comment inclusion to dashboard.
2. Request a below-threshold/unauthorized provider PDF.
- **Expected:** After policy approval, eligible PDF matches threshold-safe dashboard; suppressed/unauthorized export fails or contains only approved suppression messaging.
- **Negative/non-disclosure:** No respondent IDs, raw comments outside approved set, small cells, tokens, or server paths are present.
- **Cleanup:** Securely delete PDF and reset evaluation fixtures.
- **Source:** app/Config/Routes.php:413-417; app/Controllers/GuidanceEvaluationController.php:344-392; app/Services/Reporting/GuidanceFormLatex.php:1614-1646
- **Evidence:** PDF headers/hash/render, dashboard comparison, and suppressed/unauthorized result.
- **Verdict:** `NOT_RUN`

### Interdepartmental Referrals \(2 cases\)

#### RF-002 — Guidance explicitly creates a referral to Clinic
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-002`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.CREATE.GUIDANCE_CLINIC`
- **Role:** `counsellor`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-01`
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

#### RF-008 — Guidance target handoff explicitly creates or links a case
- **Lifecycle:** `READY`
- **Legacy IDs:** `RF-008`
- **Changed by:** b868a49
- **Requirement:** `REFERRAL.HANDOFF.GUIDANCE_CREATE_LINK`
- **Role:** `counsellor`
- **Module:** Interdepartmental Referrals
- **Use Cases:** `UC-RF-03`
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

### Responsive Web Contracts \(1 case\)

#### UX-WEB-004 — Guidance case and operations workspaces stack without losing state
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `b868a49`, `884b9f0`
- Requirement: Guidance case identity/actions/session/status/history/follow-up/tabs, operations filters/cards, MI/evaluation/governance forms must wrap/stack at mobile widths with readable badges, full-width critical controls, ≥40 px inputs/buttons, and no state/permission change caused by layout.
- Role: `counsellor`
- Module: Responsive Web Contracts
- Use Cases: `UC-GD-05`
- Surface: Guidance dashboard, case, intake, episode, clients, forms, assessments, evaluations, governance.
- Route/API: Relevant guidance web routes.
- Actor/grants: Counselor, supervisor/admin, and restricted practitioner variants.
- Preconditions: Long names/text, multiple tabs/actions/statuses, validation errors; domain fixtures disposable.

**Steps**
1. Traverse representative pages at 320/375/767/768 px, open tabs/details/modals, fill forms, and inspect wrapping/scroll/focus/error association.
2. Resize while tabs/forms/drafts are active and compare selected state/values/permissions.
3. Repeat light/dark, keyboard-only, and 200% zoom.

- Expected: Content/actions remain readable/reachable; status rows stack, tabs wrap, forms/buttons meet target sizes, state/authorization remains unchanged, and no page-level horizontal overflow occurs.
- Negative/non-disclosure: Responsive layout never reveals restricted clinical/cosign/safety content or places destructive action under unrelated control.
- Cleanup: Discard drafts/undo synthetic mutations.
- Source: `public/assets/css/pages/guidance-case.css:1-54`; `public/assets/css/pages/guidance-case.css:57-165`; `public/assets/css/pages/guidance-case.css:167-301`
- Evidence: Page/role/viewport matrix and keyboard/state-preservation recording.
- Verdict: `NOT_RUN`

## 12. Guidance Supervisor

> **Role ID:** `guidance_supervisor` · **Classification:** `rbac` · **Cases:** **16**  
> UML Inheritance: extends **Counsellor** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Guidance Supervisor (`guidance_supervisor`): extends Counsellor with cross-case oversight (`read_any`), caseload reassignment, associate/intern co-signing, announcements, question bank, surveys, evaluation moderation, activation gates, and Guidance Archive & Restore.

### Guidance & Counseling \(16 cases\)

#### GD-005 — Read-any oversight is audited and does not grant ownership
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-005`
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.CASE.READ_ANY_AUDIT`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-12`
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

#### GD-009 — Question bank revision can be previewed and archived
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-009`
- **Changed by:** `6deffdf`
- **Requirement:** `GUIDANCE.QUESTION_BANK.LIFECYCLE`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-14`
- **Surface:** Guidance question bank (including linear-scale items)
- **Route/API:** POST /guidance/question-bank/store|{id}/update|{id}/archive
- **Actor/grants:** Guidance manager with applicable survey/question-bank authority
- **Preconditions:** Unique synthetic question (including linear-scale min/max configuration), audience/type/category values; no published survey depends on cleanup version.
**Steps**
1. Create a question (including linear-scale rating bounds), update it to a new revision, and open preview.
2. Archive it and inspect active catalog/search results.
- **Expected:** Revision metadata increments, preview reflects the selected immutable revision and scale endpoints, and archived item leaves the active set.
- **Negative/non-disclosure:** Archive does not rewrite survey copies or disclose responses; unauthorized actors cannot author.
- **Cleanup:** Remove/reset synthetic bank records after archival evidence.
- **Source:** app/Config/Routes.php:233-243; docs/QUESTION_BANK.md:1-92
- **Evidence:** Create/edit/preview/archive screenshots and revision/catalog state.
- **Verdict:** `NOT_RUN`

#### GD-010 — Survey copies bank revisions and publishes to its audience
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-010`
- **Changed by:** b868a49, 6deffdf
- **Requirement:** `GUIDANCE.SURVEY.PUBLISH`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-14`
- **Surface:** Guidance survey builder
- **Route/API:** POST /guidance/surveys/store|{id}/publish
- **Actor/grants:** Guidance actor with guidance.surveys.manage
- **Preconditions:** Active synthetic bank question (including linear scale); valid survey window/audience; target and non-target synthetic portal actors.
**Steps**
1. Create a survey by copying the bank revision, review the draft, and publish it.
2. Open portal survey lists as target and non-target actors, then archive/update the bank source separately.
- **Expected:** Published survey is available only to the target audience and retains its copied bank ID/revision and scale bounds independently of later source changes.
- **Negative/non-disclosure:** Draft/closed surveys and confidential response data are not exposed; publishing does not grant broad Guidance record access.
- **Cleanup:** Close/delete the survey, question copy, and responses.
- **Source:** app/Config/Routes.php:244-259; app/Services/Guidance/SurveyService.php:1-420
- **Evidence:** Builder/publish states, copied revision metadata, and two-audience portal comparison.
- **Verdict:** `NOT_RUN`

#### GD-011 — Survey response review and export remain bounded
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-011`
- **Changed by:** baseline
- **Requirement:** `GUIDANCE.SURVEY.RESPONSE_REVIEW`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-15`
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

#### GD-012 — Guidance announcement publishing reaches only its audience
- **Lifecycle:** `READY`
- **Legacy IDs:** `GD-012`
- **Changed by:** baseline
- **Requirement:** `GUIDANCE.ANNOUNCEMENT.PUBLISH`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-14`
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

#### GD-GOV-001 — Activation gate remains disabled until every required authority signs
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.GOVERNANCE.MULTI_AUTHORITY_GATE`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-16`
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

#### GD-GOV-002 — Unmet governance gates block gated production workflows
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.GOVERNANCE.FAIL_CLOSED`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-16`
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

#### GD-GOV-003 — Guardian digital attestation remains blocked in v1
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.GOVERNANCE.GUARDIAN_CAPACITY`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-16`
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

#### GD-PRC-001 — Practitioner profile records tier, scope, and standing
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.REGISTER`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-16`
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

#### GD-PRC-002 — Associate supervision is current and self-supervision is forbidden
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.SUPERVISION`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-16`
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

#### GD-PRC-004 — Associate-authored record requires current supervisor co-sign
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.PRACTITIONER.COSIGN`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-13`
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

#### GD-FRM-001 — Canonical form manifest sync exposes reviewed active definitions
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

#### GD-FRM-005 — Associate form follows pending co-sign state
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

#### GD-CLN-002 — Clinical co-sign queue uses current supervision and immutable finalization
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

#### GD-CLN-003 — Consultation, full transfer, and manager reassignment retain distinct authority
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

#### GD-EVL-004 — Moderation approves or redacts qualitative comments
- **Lifecycle:** `DRAFT`
- **Legacy IDs:** None
- **Changed by:** b868a49
- **Requirement:** `GUIDANCE.EVALUATION.MODERATION`
- **Role:** `guidance_supervisor`
- **Module:** Guidance & Counseling
- **Use Cases:** `UC-GD-15`
- **Surface:** Evaluation moderation console
- **Route/API:** GET/POST /guidance/evaluations/moderate/{id}
- **Actor/grants:** Guidance supervisor/admin with guidance.evaluations.moderate
- **Preconditions:** Evaluation policy unresolved; two synthetic encrypted comments pending review; ordinary counsellor control actor.
**Steps**
1. Attempt moderation as ordinary counsellor, then approve one and redact the other as moderator.
2. Reload moderation and aggregate views and inspect audit/state.
- **Expected:** After policy approval, only the moderator changes pending_review to approved/redacted with actor/time; aggregates show only approved comment text as allowed.
- **Negative/non-disclosure:** Moderator does not learn respondent identity/service token linkage, and redacted/pending plaintext is not published.
- **Cleanup:** Delete/reset synthetic evaluations/moderation records.
- **Source:** app/Services/Guidance/GuidanceServiceEvaluationService.php:385-444; app/Config/Routes.php:413-417
- **Evidence:** Permission denial, approved/redacted states, actor/timestamps, and aggregate visibility comparison.
- **Verdict:** `NOT_RUN`

## 13. Guidance Administrator

> **Role ID:** `guidance_admin` · **Classification:** `rbac` · **Cases:** **2**  
> UML Inheritance: extends **Guidance Supervisor** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Guidance Administrator (`guidance_admin`): extends Guidance Supervisor with Guidance employee access management, Guidance student intern placements, Guidance legal holds, MIS sync, and operational export.

### Archive Governance \(1 case\)

#### ARV-003 — Guidance administrator manages Guidance archives and legal holds without crossing units
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.SCOPE.GUIDANCE_ADMIN_HOLDS`
- **Role:** `guidance_admin`
- **Module:** Archive Governance
- **Use Cases:** `UC-GD-17`, `UC-AD-05`
- **Surface:** Guidance inline archive controls and `/admin/archive`
- **Route/API:** POST /guidance/cases/{id}/archive|restore; POST /guidance/episodes/{id}/archive|restore; POST /admin/archive/holds/place; POST /admin/archive/holds/{id}/lift
- **Actor/grants:** Active `guidance_admin` with `archive.read`, `archive.manage`, and `archive.legal_hold.manage`
- **Preconditions:** Disposable closed Guidance case, episode, survey, question bank item, and Guidance Team task plus a Clinic encounter sentinel.
**Steps**
1. Place a legal hold on a closed Guidance case, attempt to archive that held case, then lift the hold with a lift reason and archive/restore it cleanly.
2. Attempt to place a legal hold or archive a Clinic `clinic_encounter` or `bmg_unit` as `guidance_admin`.
- **Expected:** Active legal hold blocks archival with HTTP 409; lifting the hold permits reversible Guidance archival/restoration; cross-unit holds/archives fail with 403.
- **Negative/non-disclosure:** Held case content is untouched, central hold listings show only non-PHI reference codes, and retention/policy configuration stays denied without `archive.policies.manage`.
- **Cleanup:** Lift disposable holds, restore fixtures, and retire test DB.
- **Source:** app/Services/Archive/LegalHoldService.php:36-80; app/Services/Archive/ArchiveAccessPolicy.php:91-129; app/Config/Routes.php:310-319,357-358,423-424
- **Evidence:** Hold 409 block screenshot/status, lift-then-archive sequence, audit chain entries, and 403 cross-unit hold rejection.
- **Verdict:** `NOT_RUN`

### Student Intern Governance \(1 case\)

#### INT-002 — Guidance administrator assigns a Guidance intern while preserving student identity
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.PLACEMENT.CREATE_IDENTITY_PRESERVED`
- **Role:** `guidance_admin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Assign Student Intern modal (`/admin/interns/create`)
- **Route/API:** POST /admin/interns/store; GET /admin/interns/{id}/edit
- **Actor/grants:** Active `guidance_admin` with `interns.manage`; negative control `counsellor` without `interns.manage`
- **Preconditions:** Active enrolled `student` account (`people.kind = 'student'`) with existing student session/mobile token and no open placement; active Guidance supervisor (`counsellor`/`guidance_supervisor`/`guidance_admin`).
**Steps**
1. Create an immediately active Guidance intern placement with reason, academic term, supervisor, and `starts_at <= now` window (max 366 days).
2. Inspect `people.kind`, `users.role`, prior student session validity, and attempt placement creation as `counsellor` or with invalid window (>366 days) / self-supervision.
- **Expected:** User role updates to `guidance_intern`, `people.kind` strictly remains `student`, prior student session/auth_version is rotated, and unauthorized/invalid requests fail without partial writes.
- **Negative/non-disclosure:** No duplicate `people` row or `employee_profiles` row is created, and overlapping open placements for the same student are rejected.
- **Cleanup:** Revoke/delete disposable placement and test sessions.
- **Source:** app/Services/StudentInternService.php:267-350; app/Controllers/StudentInternController.php:54-65; app/Services/EmployeeAccessService.php:37-41
- **Evidence:** Created placement detail, `people.kind = 'student'` DB check, rotated session proof, and rejection matrix.
- **Verdict:** `NOT_RUN`

## 14. BMG Intern

> **Role ID:** `bmg_intern` · **Classification:** `rbac` · **Cases:** **2**  
> UML Inheritance: extends **Department Workstation Member (Clinic, Guidance & BMG)** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Supervised Facilities intern (`bmg_intern`): unit-scoped composting drum monitoring and supervised bookkeeping batch logging under BMG Team membership.

### Facilities \(BMG\) & Hardware \(2 cases\)

#### BM-001 — Facilities dashboard reports recorded state, not physical state
- Lifecycle: `DRAFT`
- Legacy IDs: `BM-001`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The dashboard must show tenant-scoped unit, device-binding, batch, treated-weight, and seven-day Manila-bucket counts while describing them as recorded application facts; final dashboard wording awaits the wording decision.
- Role: `bmg_intern`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-01`
- Surface: Facilities web dashboard.
- Route/API: `GET /facilities/dashboard`.
- Actor/grants: Active BMG member with `facilities.read`.
- Preconditions: A disposable tenant has active, maintenance, and offline units, at least one bound device, released and active batches, and records on two Manila business days.

**Steps**
1. Sign in on the dedicated BMG host and open `/facilities/dashboard`; record every displayed counter and the seven-day labels.
2. Compare the display with the disposable database fixture, then change only a recorded unit status or batch row and reload without touching hardware.
3. Confirm the corresponding recorded-state metric changes and that the page makes no claim that a motor is running, stopped, safe, or physically available.

- Expected: Counts are tenant-scoped and match stored rows; active batches include `processing`, `awaiting_output`, and `curing`; treated weight uses released input weight; day labels use Manila business-day buckets.
- Negative/non-disclosure: Another tenant’s rows, device token hashes, patient data, and assertions of physical motor state are absent.
- Cleanup: Restore the changed fixture row or retire the disposable database.
- Source: `app/Controllers/FacilitiesController.php:27-86`; `app/Services/Facilities/BmgRotationService.php:67-88`
- Evidence: Full-page screenshot, fixture-to-counter comparison, and sanitized request/response log.
- Verdict: `NOT_RUN`

#### INT-006 — BMG intern logs supervised batch work with live shift clock and hardware boundaries
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.BOUNDARY.BMG_INTERN`
- **Role:** `bmg_intern`
- **Module:** Facilities (BMG) & Hardware
- **Use Cases:** `UC-BM-01`
- **Surface:** Facilities Operations (BMG) workspace
- **Route/API:** GET /facilities/dashboard; GET /facilities/unit/{id}; POST /facilities/unit/{id}/batch; POST /facilities/units/store; POST /facilities/unit/{id}/schedule|device|usb/build
- **Actor/grants:** Active `bmg_intern` on dedicated BMG host
- **Preconditions:** Active BMG intern placement; active test drum unit; no active batch.
**Steps**
1. Open `/facilities/dashboard` and `/facilities/unit/{id}`, verify the live intern shift countdown timer, and perform allowed supervised batch logging (`facilities.read`/`facilities.manage`).
2. Attempt `POST /facilities/units/store`, unit configuration/maintenance edits, rotation schedule save, device registration, and USB commissioning routes.
- **Expected:** Allowed dashboard monitoring and supervised bookkeeping work succeed while unit registration (`facilities.configure`), hardware commissioning (`facilities.hardware.manage`), archive, and intern-admin routes fail with 403.
- **Negative/non-disclosure:** `bmg_intern` cannot access Clinic/Guidance consoles or alter hardware schedules/tokens.
- **Cleanup:** Cancel/reset disposable test batch and end session.
- **Source:** app/Database/Seeds/PermissionsAndRolesSeeder.php:240-242; app/Services/PortalContext.php:161-166; app/Config/Routes.php:261-284
- **Evidence:** Live shift clock screenshot, allowed batch log, and 403 denial matrix for config/hardware routes.
- **Verdict:** `NOT_RUN`

## 15. Facilities Operator

> **Role ID:** `facilities_op` · **Classification:** `rbac` · **Cases:** **6**  
> UML Inheritance: extends **BMG Intern** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Facilities Operator (`facilities_op`): BMG dashboard analytics and full bookkeeping batch lifecycle (start, contention guard, release, cancel).

### Facilities \(BMG\) & Hardware \(6 cases\)

#### BM-003 — Start an active batch as bookkeeping only
- Lifecycle: `READY`
- Legacy IDs: `BM-003`
- Changed by: `bea71cf`
- Requirement: Starting a batch on an active unit must store an exact positive input weight not exceeding capacity, assign a generated batch code and `processing` state, and explicitly not actuate a motor.
- Role: `facilities_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-02`
- Surface: BMG unit detail batch form.
- Route/API: `POST /facilities/unit/{unit}/batch`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: Active test unit with no active batch; known capacity; hardware may remain disabled.

**Steps**
1. Record the unit’s schedule, command count, and run count; submit an input weight within capacity and optional notes.
2. Reopen the unit detail and inspect the new active batch, generated code, exact weight, status, and success message.
3. Confirm schedule, commands, and device-reported runs did not change; try zero, excessive, and over-precision weights on clean fixtures.

- Expected: One `processing` batch is created and audited; invalid weights are rejected; the response says recording a batch does not start the motor.
- Negative/non-disclosure: No command is queued, no motor execution is claimed, no quality grade beyond `unclassified` is inferred, and no cross-tenant unit can be used.
- Cleanup: Cancel the test batch with a reason or release it with controlled values.
- Source: `app/Controllers/FacilitiesController.php:181-186`; `app/Services/Facilities/BmgService.php:17-71`
- Evidence: Batch form/result screenshots, before/after command and run counts, and audit event excerpt.
- Verdict: `NOT_RUN`

#### BM-004 — Reject a second active batch under contention
- Lifecycle: `READY`
- Legacy IDs: `BM-004`
- Changed by: `bea71cf`
- Requirement: A unit may have at most one active batch across `processing`, `awaiting_output`, and `curing`, including concurrent submissions.
- Role: `facilities_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-02`
- Surface: BMG unit detail and mutation response.
- Route/API: `POST /facilities/unit/{unit}/batch`.
- Actor/grants: Two active BMG sessions with `facilities.manage` in the same tenant.
- Preconditions: Active unit without an active batch; two synchronized clients using the disposable database.

**Steps**
1. From both clients submit valid batch-start requests for the same unit as nearly simultaneously as the harness permits.
2. Reload the unit and count active batches; retain both HTTP outcomes.
3. Attempt a third start while the winner remains active.

- Expected: Exactly one batch exists in an active status; competing/subsequent requests return deterministic conflict text that the unit is already running an active batch.
- Negative/non-disclosure: No duplicate active row, partial audit event, command, or cross-tenant disclosure is produced.
- Cleanup: Cancel or release the single winning batch.
- Source: `app/Services/Facilities/BmgService.php:38-69`; `app/Services/Facilities/BmgService.php:196-222`
- Evidence: Timestamped parallel request log, active-row count, and audit-row count.
- Verdict: `NOT_RUN`

#### BM-006 — Release a batch without certifying compost or motor state
- Lifecycle: `READY`
- Legacy IDs: `BM-006`
- Changed by: `bea71cf`
- Requirement: Completing an active batch must move it to `released`, record an optional exact output weight not greater than input, retain `unclassified` quality, and leave the unit available for another bookkeeping batch.
- Role: `facilities_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-02`
- Surface: BMG unit detail completion form.
- Route/API: `POST /facilities/batch/{batch}/release`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: Active test batch with known input weight.

**Steps**
1. Submit a valid output weight and release notes; reopen the unit detail.
2. Verify the batch appears in past batches as `released`, has completion time and `unclassified` grade, and no longer occupies the active slot.
3. On a separate active fixture, submit output greater than input and confirm the batch remains active.

- Expected: Valid release is atomic and audited; invalid output returns validation failure; a new batch can be recorded after valid release.
- Negative/non-disclosure: The response does not certify sanitation, compost quality, regulatory release, physical availability, or motor stop.
- Cleanup: Remove any follow-on test batch and retain only sanitized evidence.
- Source: `app/Controllers/FacilitiesController.php:189-195`; `app/Services/Facilities/BmgService.php:73-87`; `app/Services/Facilities/BmgService.php:126-151`
- Evidence: Before/after batch screenshots, validation response, and sanitized audit excerpt.
- Verdict: `NOT_RUN`

#### BM-007 — Reject terminal or stale batch transitions
- Lifecycle: `READY`
- Legacy IDs: `BM-007`
- Changed by: `bea71cf`
- Requirement: Release and cancel operations must accept only a currently active batch and must reject stale, repeated, wrong-unit, or terminal transitions without changing the first terminal result.
- Role: `facilities_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-02`
- Surface: BMG batch mutation endpoints.
- Route/API: `POST /facilities/batch/{batch}/release`; `POST /facilities/batch/{batch}/cancel`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: One batch that can be completed and a second client holding the same initial page state.

**Steps**
1. Complete the batch from client A and record its terminal values and audit event.
2. From client B submit cancel and release requests against that same batch ID.
3. Reload the batch and compare state, completion metadata, and audit count with the values after step 1.

- Expected: Later transitions return `409` with “Only an active batch can be completed or cancelled”; first terminal values and one terminal audit event remain authoritative.
- Negative/non-disclosure: A stale request cannot overwrite release notes, output weight, completion time, status, or another tenant’s batch.
- Cleanup: None beyond retiring the disposable database.
- Source: `app/Services/Facilities/BmgService.php:126-151`; `app/Controllers/FacilitiesController.php:261-273`
- Evidence: Ordered HTTP transcripts and before/after terminal-row plus audit comparison.
- Verdict: `NOT_RUN`

#### BM-008 — Cancel a batch with a required reason
- Lifecycle: `READY`
- Legacy IDs: `BM-008`
- Changed by: `bea71cf`
- Requirement: Cancelling an active batch must require a nonblank bounded reason, transition to `voided`, and free the bookkeeping slot without implying that the drum is physically safe.
- Role: `facilities_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-02`
- Surface: BMG unit detail cancellation form.
- Route/API: `POST /facilities/batch/{batch}/cancel`.
- Actor/grants: BMG member with `facilities.manage`.
- Preconditions: Active batch on a test unit.

**Steps**
1. Submit blank and over-limit cancellation reasons and verify the active batch remains unchanged.
2. Submit a valid reason, then reopen the unit and inspect the past-batch record and availability of the start form.
3. Compare device heartbeat, command, and run data before and after cancellation.

- Expected: Only the valid request sets `voided`, records the reason as release notes, timestamps completion, appends an audit event, and frees the active slot.
- Negative/non-disclosure: Cancellation does not edit input weight, classify quality, issue a stop command, or prove the motor stopped; the response tells the operator to check the controller separately.
- Cleanup: Archive the disposable unit if it was created solely for this case.
- Source: `app/Controllers/FacilitiesController.php:197-202`; `app/Services/Facilities/BmgService.php:89-97`; `app/Services/Facilities/BmgService.php:126-151`
- Evidence: Validation responses, terminal batch screenshot, and unchanged hardware-fact comparison.
- Verdict: `NOT_RUN`

#### BM-013 — BMG analytics expose bounded recorded metrics and limitations
- Lifecycle: `DRAFT`
- Legacy IDs: `BM-013`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The current BMG dashboard must report only the implemented tenant-scoped recorded metrics and Manila-bucket batch counts; acceptance wording for availability and dashboard labels remains unresolved.
- Role: `facilities_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-01`, `UC-BM-02`
- Surface: Facilities dashboard analytics cards and chart.
- Route/API: `GET /facilities/dashboard`.
- Actor/grants: BMG member with `facilities.read`.
- Preconditions: Deterministic fixture spanning seven Manila days with released, voided, and active batches plus device-reported runs.

**Steps**
1. Open the dashboard at a fixed Manila clock and record total/active batch, released-input weight, unit-status, bound-device, and daily run-of-batch values.
2. Reconcile each displayed value against the defined query populations, including boundary records around midnight Manila.
3. Search the page for unsupported cancellation-rate, time-in-status, compost-quality, motor-uptime, and rotation-reliability claims.

- Expected: Implemented counts reconcile exactly; missing metrics remain absent rather than being invented or represented as zero; displayed dates align with Manila business days.
- Negative/non-disclosure: Device acknowledgements/runs are not promoted to independent physical reliability proof, and no sanitation, quality, uptime, or certification conclusion is disclosed.
- Cleanup: Retire the deterministic analytics database.
- Source: `app/Controllers/FacilitiesController.php:33-73`; `app/Services/Facilities/BmgRotationService.php:73-88`
- Evidence: Dashboard capture, reproducible metric worksheet, boundary-row extract, and wording review note.
- Verdict: `NOT_RUN`

## 16. BMG Administrator

> **Role ID:** `bmg_admin` · **Classification:** `rbac` · **Cases:** **4**  
> UML Inheritance: extends **Facilities Operator** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** BMG Administrator (`bmg_admin`): extends Facilities Operator with drum unit configuration, maintenance state, rotation schedules, BMG student intern placements, operational export, and BMG/Team Archive & Restore.

### Facilities \(BMG\) & Hardware \(2 cases\)

#### BM-002 — Register a unit with explicit capacity and disabled automation
- Lifecycle: `READY`
- Legacy IDs: `BM-002`
- Changed by: `bea71cf`
- Requirement: Unit creation must require a unique safe code, name, valid explicit drum capacity, and allowed status; it must create a disabled schedule and must not infer capacity or enable automation.
- Role: `bmg_admin`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-03`
- Surface: Facilities unit registry.
- Route/API: `POST /facilities/units/store` followed by `GET /facilities/unit/{id}`.
- Actor/grants: BMG administrator with `facilities.configure` and `facilities.manage`.
- Preconditions: Disposable tenant; unique test code; no similarly named unit needed.

**Steps**
1. Submit a new unit with a unique code, name, `offline` status, and an exact two-decimal capacity, then open its detail page.
2. Verify the unit is present with the submitted capacity and its schedule is disabled with no bound controller.
3. Submit another create using the same code and one omitting/invalidating capacity.

- Expected: The first request creates one tenant-owned unit and disabled schedule; duplicate code conflicts and invalid/missing capacity is rejected without another row.
- Negative/non-disclosure: Creation does not start a motor, bind a device, invent a capacity, expose credentials, or confirm physical installation.
- Cleanup: Archive the created unit with the required retirement acknowledgement, or retire the disposable database.
- Source: `app/Controllers/FacilitiesController.php:90-115`; `app/Services/Facilities/BmgRotationService.php:97-141`
- Evidence: Before/after registry screenshots, response status log, and sanitized unit/schedule row extract.
- Verdict: `NOT_RUN`

#### BM-009 — Maintenance status disables intent but is not a motor assertion
- Lifecycle: `READY`
- Legacy IDs: `BM-009`
- Changed by: `bea71cf`
- Requirement: Changing a unit away from `active` must persist the configured status, disable saved schedule intent, cancel pending commands, increment version, and state that application availability does not confirm physical motor state.
- Role: `bmg_admin`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-03`, `UC-BM-04`
- Surface: BMG unit settings.
- Route/API: `POST /facilities/unit/{unit}/update`.
- Actor/grants: BMG administrator with `facilities.configure` and `facilities.manage`; hardware grant is not required for safe shutdown intent.
- Preconditions: Active unit with an enabled or configured schedule; use only a simulated device in a gated test campaign.

**Steps**
1. Record unit status, schedule version/intent, and any pending command; submit `maintenance` with the current expected version.
2. Reload the unit and confirm maintenance status, disabled schedule, cleared PWM, incremented version, and terminalized pending command.
3. Submit an edit with the stale version, then return the unit to `active` using the fresh version without enabling its schedule.

- Expected: Maintenance transition and later active transition are audited; stale update conflicts; returning to active does not re-enable automation.
- Negative/non-disclosure: Neither status label confirms a motor stop/start, device liveness, lockout/tagout, or physical safety.
- Cleanup: Leave the simulated unit offline/maintenance with schedule disabled, then retire the disposable database.
- Source: `app/Controllers/FacilitiesController.php:118-123`; `app/Services/Facilities/BmgRotationService.php:97-141`; `app/Services/Facilities/BmgRotationService.php:595-617`
- Evidence: Versioned before/after screenshots, command-state extract, stale-conflict response, and audit excerpt.
- Verdict: `NOT_RUN`

### Archive Governance \(1 case\)

#### ARV-004 — BMG administrator archives and restores BMG units and Team items within unit scope
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.SCOPE.BMG_ADMIN`
- **Role:** `bmg_admin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-05`
- **Surface:** Facilities unit detail and `/admin/archive`
- **Route/API:** POST /facilities/unit/{id}/archive|restore; POST /facilities/teams/{id}/archive|restore; GET /admin/archive
- **Actor/grants:** Active `bmg_admin` with `archive.read` and `archive.manage`
- **Preconditions:** Two disposable BMG units (one idle/offline with no active batch, one with an active `processing` batch), a BMG Team calendar event, and Clinic/Guidance sentinels.
**Steps**
1. Archive and restore the idle BMG drum unit and BMG Team event via `/facilities/unit/{id}/archive` and `/admin/archive`.
2. Attempt to archive the drum unit carrying an active `processing` batch and attempt to query or archive a Clinic/Guidance record.
- **Expected:** Idle BMG units and BMG Team items archive/restore reversibly with retired device bindings on archive; active-batch units fail closed, and Clinic/Guidance records are denied with 403.
- **Negative/non-disclosure:** Archiving does not destroy batch history or actuate hardware, and restoration never re-enables hardware automation implicitly.
- **Cleanup:** Restore or retire disposable BMG fixtures.
- **Source:** app/Controllers/FacilitiesController.php:1-160; app/Services/Archive/ArchiveAccessPolicy.php:96-129; app/Config/Routes.php:200-209,275-276
- **Evidence:** Idle archive/restore ledger rows, active-batch rejection, and cross-unit 403 proof.
- **Verdict:** `NOT_RUN`

### Student Intern Governance \(1 case\)

#### INT-003 — BMG intern placement auto-activates, expires, and displays live shift clocks
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.LIFECYCLE.SCHEDULED_EXPIRED_CLOCK`
- **Role:** `bmg_admin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Student Interns console & live shift clock banner
- **Route/API:** POST /admin/interns/store; GET /admin/interns; GET /facilities/dashboard
- **Actor/grants:** Active `bmg_admin` with `interns.manage`; placed `bmg_intern`
- **Preconditions:** Eligible student; active BMG supervisor (`facilities_op`/`bmg_admin`); controlled Manila scheduling clock.
**Steps**
1. Schedule a `bmg_intern` placement with future `starts_at` and inspect status=`scheduled` while user remains `student`.
2. Advance controlled clock past `starts_at`, verify reconciliation promotes status to `active` / role `bmg_intern` and renders live shift countdown clock, then advance past `ends_at`.
- **Expected:** Reconciliation deterministically transitions `scheduled -> active -> expired`, updates `users.role` (`student -> bmg_intern -> student`), and removes workstation access upon expiry.
- **Negative/non-disclosure:** Scheduled interns gain zero premature BMG access and expired interns cannot bypass time bounds using retained sessions/tokens.
- **Cleanup:** Restore test clock and clean disposable placement.
- **Source:** app/Services/StudentInternService.php:108-226,248-264; app/Controllers/StudentInternController.php:16-26
- **Evidence:** Scheduled, active (with live shift clock), and expired reconciliation captures plus denied expired request.
- **Verdict:** `NOT_RUN`

## 17. Report Viewer

> **Role ID:** `report_viewer` · **Classification:** `rbac` · **Cases:** **3**  
> UML Inheritance: extends **All Authenticated Roles (Shared)** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Report Viewer (`report_viewer`): read/export tenant-scoped operational aggregate CSV and PDF reports while strictly isolated from audit ledger and admin configuration routes.

### Reporting & Audit Trail \(3 cases\)

#### AD-010 — Operational CSV export is aggregate, bounded, and injection-safe
- Lifecycle: `READY`
- Legacy IDs: `AD-010`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: CSV export must contain only tenant-scoped daily aggregate counts over the authorized units/Manila date range, neutralize formula-leading cells, stay under 8 MiB, remain memory-backed, and audit metadata before release.
- Role: `report_viewer`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-02`
- Surface: Operational report export.
- Route/API: `POST /reports/export/operational` with `format=csv`.
- Actor/grants: Actor authorized by report export policy for selected units.
- Preconditions: Disposable deterministic daily fixtures, including Manila-boundary records; an isolated unit test may supply formula-leading synthetic cells.

**Steps**
1. Export a bounded multi-unit range and reconcile every CSV row and generated filename with source aggregate counts.
2. Inspect headers, encoding, line endings, and formula-neutralization behavior using synthetic export cells.
3. Attempt an unauthorized unit/range and an export exceeding the byte/range bound; inspect audit events before accepting the download.

- Expected: Only aggregate `date_manila,unit,metric,count` rows are downloaded; unsafe cells are prefixed, bounds reject safely, and audit records units/range/row count/format without content.
- Negative/non-disclosure: No names, IDs, notes, diagnoses, counseling text, export plaintext files, cross-tenant rows, or formula execution is present.
- Cleanup: Securely delete local downloaded test files and retire DB.
- Source: `app/Services/Reporting/OperationalExportService.php:23-77`; `app/Services/Reporting/ExportCsv.php:10-58`
- Evidence: Sanitized CSV hash/row reconciliation, headers, injection test, and audit metadata.
- Verdict: `NOT_RUN`

#### AD-011 — Operational PDF matches authorized aggregate source
- Lifecycle: `READY`
- Legacy IDs: `AD-011`
- Changed by: `bea71cf`, `884b9f0`, `62c790b`
- Requirement: PDF export must render the same authorized aggregate period/unit dataset as CSV under the unified institutional PDF header (`DocumentHeaderLatex`), start with a valid PDF signature, use a safe deterministic filename, no-store headers, and audit before body release.
- Role: `report_viewer`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-02`
- Surface: Operational report export.
- Route/API: `POST /reports/export/operational` with `format=pdf`.
- Actor/grants: Authorized report actor for selected units.
- Preconditions: Same deterministic fixture and selection used for AD-010; required PDF renderer available in the disposable environment.

**Steps**
1. Export CSV and PDF for the identical selection; retain hashes and extract only sanitized aggregate text from the PDF.
2. Compare institutional header/seal layout, dates, units, metrics, counts, and any clinic aggregate insight panels against the server’s authorized dataset.
3. Inspect signature, filename, content type, no-store/nosniff headers, and export audit row; simulate renderer failure.

- Expected: PDF is a valid `%PDF-1.4` document whose aggregate figures match its source dataset; failure returns no download; metadata-only audit precedes release.
- Negative/non-disclosure: No patient/person-level rows, hidden notes, renderer diagnostics, temp plaintext artifact, or unauthorized unit appears.
- Cleanup: Delete downloaded PDF/CSV and renderer temporary output; retire DB.
- Source: `app/Controllers/ReportExportController.php:32-53`; `app/Services/Reporting/OperationalExportService.php:23-43`; `app/Services/Reporting/DocumentHeaderLatex.php:1-76`
- Evidence: File hashes, sanitized text comparison, response headers, failure response, and audit row.
- Verdict: `NOT_RUN`

#### AD-012 — Report viewer cannot cross into audit authority
- Lifecycle: `READY`
- Legacy IDs: `AD-012`
- Changed by: `bea71cf`
- Requirement: A report viewer may use operational dashboards/exports allowed by `reports.read` but must be denied audit ledger and unrelated administration routes requiring separate grants.
- Role: `report_viewer`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-01`, `UC-AD-02`
- Surface: Main dashboard, report export, administrative audit/access/AI pages.
- Route/API: `GET /dashboard`; `/reports/export`; `/admin/audit`; `/admin/access`; `/admin/ai`.
- Actor/grants: Active `report_viewer` with reports grant and no audit/RBAC/AI configuration grants.
- Preconditions: Disposable report-viewer account and tenant aggregate fixture.

**Steps**
1. Sign in as report viewer, open dashboard/export, and perform one authorized bounded export.
2. Directly request audit ledger, audit verify, employee access, carousel, MIS, and AI configuration routes.
3. Inspect navigation and response bodies for hidden links or leaked data from denied surfaces.

- Expected: Report surfaces work within unit scope; each separately governed admin surface is absent and returns denial/redirect without data.
- Negative/non-disclosure: The role does not acquire audit events, employee directory, provider settings, keys, or RBAC controls through cache/direct URL.
- Cleanup: Sign out and retire test account/database.
- Source: `app/Config/Routes.php:118-148`; `app/Config/Routes.php:276-300`; `app/Controllers/DashboardController.php:15-44`
- Evidence: Allowed/denied route matrix, navigation screenshot, and sanitized export proof.
- Verdict: `NOT_RUN`

## 18. Audit Reader

> **Role ID:** `audit_reader` · **Classification:** `rbac` · **Cases:** **4**  
> UML Inheritance: extends **Report Viewer** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Audit Reader (`audit_reader`): extends Report Viewer with administrative dashboard counters, Audit Explorer cursor queries, SHA-256 hash-chain verification, and read-only institutional Archive Center inspection.

### Archive Governance \(1 case\)

#### ARV-001 — Audit reader inspects cross-module Archive Center in strict read-only mode
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.ACCESS.AUDIT_READONLY`
- **Role:** `audit_reader`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-04`
- **Surface:** System Archive & Retention Center
- **Route/API:** GET /admin/archive; POST /admin/archive/records/archive|restore; POST /admin/archive/holds/place; POST /admin/archive/policies/save
- **Actor/grants:** Active `audit_reader` with `archive.read` and no `archive.manage`, `archive.legal_hold.manage`, or `archive.policies.manage`
- **Preconditions:** Disposable tenant containing archived records across Clinic, Guidance, Facilities, Teams, and Administration plus at least one active legal hold and retention policy.
**Steps**
1. Sign in as `audit_reader` and open `/admin/archive` across Records, Legal Holds, Retention Policies, and Eligible Records tabs.
2. Inspect action controls and submit direct CSRF-valid POST attempts to archive, restore, place hold, and save policy routes.
- **Expected:** Read-only archive, hold, and policy tabs load cross-module metadata with restore/archive/manage controls absent; every direct mutation POST returns HTTP 403 without modifying state.
- **Negative/non-disclosure:** Clinical notes, counselling text, and other confidential entity payloads are masked to non-PHI references (`safeRecordRef` / `safeMetadata`), and no foreign tenant record appears.
- **Cleanup:** Remove disposable archive fixtures and sign out.
- **Source:** app/Controllers/ArchiveController.php:42-200; app/Services/Archive/ArchiveAccessPolicy.php:40-129; app/Services/Archive/ArchiveEntityRegistry.php:205-303
- **Evidence:** Read-only Archive Center screenshots, 403 mutation response log, and masked non-PHI inspection.
- **Verdict:** `NOT_RUN`

### Reporting & Audit Trail \(3 cases\)

#### AD-001 — Administrative dashboard enforces permission-shaped data
- Lifecycle: `READY`
- Legacy IDs: `AD-001`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The administrative dashboard must derive tenant-scoped operational counters and must never serve cached audit data to a reports-only identity.
- Role: `audit_reader`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-01`, `UC-AD-03`
- Surface: Platform web dashboard.
- Route/API: `GET /admin`; `GET /dashboard`.
- Actor/grants: Superadmin/audit reader with `audit.read`; report viewer with `reports.read` for the negative variant.
- Preconditions: Disposable tenant with known people, operations, and audit fixtures; separate browser sessions.

**Steps**
1. Open `/admin` as an audit-authorized actor and reconcile visible counters and recent audit entries with the fixture.
2. Prime dashboard caches, then open `/dashboard` as a reports-only viewer.
3. Inspect rendered content and responses for audit rows, event payloads, or controls unavailable to the second actor.

- Expected: Authorized counters are tenant-scoped; audit sections appear only with `audit.read`; reports-only view does not inherit cached audit data.
- Negative/non-disclosure: No other tenant, audit payload, clinical note, credential, or authorization decision leaks through shared cache entries.
- Cleanup: End both sessions and retire the DB.
- Source: `app/Controllers/DashboardController.php:37-143`
- Evidence: Role-separated screenshots, fixture reconciliation, and sanitized cache/request trace.
- Verdict: `NOT_RUN`

#### AD-002 — Audit explorer filters and cursor bounds
- Lifecycle: `READY`
- Legacy IDs: `AD-002`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: The audit explorer must validate scalar filters, exact event/action/entity/actor values, date order, 25/50/100 page sizes, and BIGINT-safe older/newer cursors while remaining tenant-scoped.
- Role: `audit_reader`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-03`
- Surface: Administrative audit ledger.
- Route/API: `GET /admin/audit`.
- Actor/grants: Actor with `audit.read`.
- Preconditions: Disposable tenant with deterministic audit events covering two actors/types/entities/dates and enough rows for two pages.

**Steps**
1. Apply each filter independently and together; compare returned event IDs with the fixture.
2. Traverse older then newer cursor links at page sizes 25, 50, and 100, verifying no gaps or duplicates.
3. Submit invalid arrays, overlong values, reversed dates, size 500, newer-without-cursor, and out-of-range BIGINT cursor.

- Expected: Valid filters return only matching tenant rows in deterministic order; cursor navigation is bounded; invalid state returns page errors and no query results.
- Negative/non-disclosure: Invalid input does not coerce, overflow, execute SQL patterns, or reveal another tenant’s row/count.
- Cleanup: Retire the audit fixture database.
- Source: `app/Services/Audit/AuditExplorerQuery.php:9-96`; `app/Services/Audit/AuditExplorer.php:22-74`
- Evidence: Filter/cursor matrix, ordered ID lists, and invalid-input screenshots.
- Verdict: `NOT_RUN`

#### AD-003 — Audit hash-chain verification is scoped and qualified
- Lifecycle: `READY`
- Legacy IDs: `AD-003`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Verification must inspect the current tenant ledger from genesis to head, return pass only for an intact chain, identify a broken event on failure, and state that no external checkpoint was checked.
- Role: `audit_reader`
- Module: Reporting & Audit Trail
- Use Cases: `UC-AD-03`
- Surface: Audit ledger verification action.
- Route/API: `POST /admin/audit/verify`.
- Actor/grants: Actor with `audit.read`.
- Preconditions: Disposable database with one intact tenant ledger; a cloned disposable variant may be deliberately tampered offline.

**Steps**
1. Run verification on the intact ledger and record verified count and qualification text.
2. In the disposable clone only, alter one stored event field without recomputing hashes and rerun verification.
3. Confirm response status, message, CSRF refresh, and that verification performed no repair.

- Expected: Intact ledger returns success; tampered ledger returns `422` integrity alert at the broken ID; both results are tenant-scoped and no external-anchor claim is made.
- Negative/non-disclosure: Never tamper with or run repair against operational data; error output does not expose secret payload fields or another tenant.
- Cleanup: Destroy the tampered clone immediately.
- Source: `app/Controllers/AuditController.php:54-84`; `app/Services/Audit/AuditService.php:102-151`
- Evidence: Intact/tampered responses, ledger fixture hash manifest, and disposable-DB identifier.
- Verdict: `NOT_RUN`

## 19. Platform Owner \(Superadmin\)

> **Role ID:** `superadmin` · **Classification:** `rbac` · **Cases:** **16**  
> UML Inheritance: extends **Clinic Administrator, Guidance Administrator, Facilities Operator, Audit Reader** (inherits all prerequisite test cases from parent actor bands).  
> **Scope:** Platform Owner (`superadmin`): break-glass local login, global RBAC assignment & catalogue, sign-in carousel, MIS sync, global intern governance, archive retention policies & global legal holds, and AI provider configuration & grants (withholds `facilities.hardware.manage` and `ai.insights.*` unless explicitly granted).

### Archive Governance \(4 cases\)

#### ARV-005 — Platform owner configures non-purgeable institutional retention policies
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.RETENTION.NON_PURGEABLE`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`
- **Surface:** Archive Center Policies tab
- **Route/API:** GET /admin/archive?tab=policies; POST /admin/archive/policies/save; POST /admin/archive/policies/{id}/toggle
- **Actor/grants:** Active `superadmin` with institutional `archive.policies.manage` authority; `clinic_admin` and `audit_reader` negative controls
- **Preconditions:** Disposable tenant without conflicting test policy code; target archivable record matching the policy module/entity type.
**Steps**
1. Create an active retention policy (`policy_code`, `module`, `entity_type`, `duration_months`, `legal_basis`), archive a matching record, and verify `retention_policy_id` and `retention_due_at` calculation.
2. Toggle the policy inactive/active, inspect for any destructive purge trigger/flag, and attempt `policies/save` and `policies/{id}/toggle` as `clinic_admin` and `audit_reader`.
- **Expected:** Policy resolution selects the most specific active rule, computes reversible `retention_due_at` with `purge_enabled=0` unconditionally, and denies unauthorized roles with 403.
- **Negative/non-disclosure:** No permanent hard-delete or auto-purge executes regardless of client payload fields, and invalid policy codes/durations reject cleanly.
- **Cleanup:** Deactivate/remove disposable policy and archive record.
- **Source:** app/Services/Archive/RetentionPolicyService.php:35-120; app/Controllers/ArchiveController.php:164-200
- **Evidence:** Policy configuration form/list, computed `retention_due_at`, `purge_enabled=0` verification, and 403 unauthorized responses.
- **Verdict:** `NOT_RUN`

#### ARV-006 — Cross-module legal holds block premature archive and sync with Guidance holds
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.LEGAL_HOLD.CROSS_MODULE`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`
- **Surface:** Archive Center Holds tab and module workspaces
- **Route/API:** GET /admin/archive?tab=holds; POST /admin/archive/holds/place; POST /admin/archive/holds/{id}/lift; POST /admin/archive/records/archive
- **Actor/grants:** Active `superadmin` with `archive.legal_hold.manage`
- **Preconditions:** Eligible records across Clinic (`clinic_encounter`), Guidance (`guidance_form_instance` including one legacy `guidance_legal_holds` row), and Facilities (`bmg_unit`).
**Steps**
1. Place holds on the Clinic encounter and BMG unit; verify `activeHoldKeys` badges render and archive attempts on both plus the legacy-held Guidance form return HTTP 409.
2. Lift the hold on the Clinic encounter with a required lift reason and verify archival now succeeds while the BMG hold stays enforced.
- **Expected:** Active holds in either `archive_legal_holds` or `guidance_legal_holds` block archival atomically with HTTP 409 and audit both placement and lift with actor/reason metadata.
- **Negative/non-disclosure:** Holds never leak protected record contents in central views, blank lift reasons fail validation, and lifted holds retain historical audit trail.
- **Cleanup:** Lift remaining test holds and retire disposable fixtures.
- **Source:** app/Services/Archive/LegalHoldService.php:36-120; app/Controllers/ArchiveController.php:128-162
- **Evidence:** Hold badge/409 matrix across modules, lift transition, and immutable hold audit rows.
- **Verdict:** `NOT_RUN`

#### ARV-007 — Inline reversible archive and silent undo UX mask confidential details centrally
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.UX.UNDO_AND_CONFIDENTIAL_MASK`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`, `UC-AD-05`
- **Surface:** Inline module archive buttons, silent undo toast, and `/admin/archive`
- **Route/API:** POST /admin/carousel/{id}/archive|restore; POST /{unit}/announcements/{id}/archive|restore; GET /admin/archive
- **Actor/grants:** Active `superadmin`
- **Preconditions:** Disposable confidential clinical/counselling records with recognizable plaintext sentinels and non-confidential carousel/announcement items.
**Steps**
1. Trigger an inline archive on a Team task or announcement, observe the silent undo banner/action, and immediately execute restore via the undo action.
2. Archive a confidential `clinic_encounter` and `counselling_case` containing sentinel text, then inspect `/admin/archive?tab=records` HTML/JSON responses.
- **Expected:** Inline archive moves the item cleanly and silent undo restores it without page corruption; central Archive views display non-PHI reference labels and `confidential: true` metadata only.
- **Negative/non-disclosure:** Zero complaint, diagnosis, session note, or ciphertext sentinels appear in central `archive_records` listings or audit logs.
- **Cleanup:** Restore/delete disposable records and retire database.
- **Source:** app/Services/Archive/ArchiveEntityRegistry.php:205-303; app/Controllers/AdminCarouselController.php:1-140; app/Views/admin/archive/index.php:1-129
- **Evidence:** Inline undo sequence capture, masked `/admin/archive` screenshot, and negative sentinel search result.
- **Verdict:** `NOT_RUN`

#### ARV-008 — Immutable compliance and clinical ledgers reject archival and deletion
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `731507c`
- **Requirement:** `ARCHIVE.IMMUTABLE_LEDGERS_PROTECTED`
- **Role:** `superadmin`
- **Module:** Archive Governance
- **Use Cases:** `UC-AD-09`
- **Surface:** Archive API / POST endpoint validation
- **Route/API:** POST /admin/archive/records/archive; POST /admin/archive/holds/place
- **Actor/grants:** Active `superadmin`
- **Preconditions:** Existing rows in `audit_events`, `inventory_operations`, `stock_movements`, `guidance_form_revisions`, `guidance_form_attestations`, `guidance_consent_receipts`, and `guidance_document_issuances`.
**Steps**
1. Open `/admin/archive` and confirm the UI lists immutable ledgers as protected and omits them from archivable type selectors.
2. Submit direct CSRF-valid POST requests targeting each `IMMUTABLE_ENTITIES` key to `/admin/archive/records/archive`.
- **Expected:** Every request targeting an immutable compliance/clinical ledger is rejected with HTTP 409 ("Immutable compliance ledger records cannot be archived or deleted.") and zero rows are marked archived or deleted.
- **Negative/non-disclosure:** No database trigger bypass, unknown-entity fallback, or audit-chain alteration occurs.
- **Cleanup:** None beyond disposable DB teardown.
- **Source:** app/Services/Archive/ArchiveEntityRegistry.php:140-164; app/Controllers/ArchiveController.php:96-110
- **Evidence:** Protected-ledger UI capture, HTTP 409 matrix across all immutable keys, and unchanged row counts.
- **Verdict:** `NOT_RUN`

### Student Intern Governance \(1 case\)

#### INT-004 — Superadmin revokes any unit placement and terminates active sessions immediately
- **Lifecycle:** `READY`
- **Legacy IDs:** None
- **Changed by:** `62c790b`
- **Requirement:** `INTERN.REVOKE.IMMEDIATE_TERMINATION`
- **Role:** `superadmin`
- **Module:** Student Intern Governance
- **Use Cases:** `UC-AD-06`
- **Surface:** Student Interns management console
- **Route/API:** GET /admin/interns; POST /admin/interns/{id}/revoke; protected workstation and `/api/v1/*` routes
- **Actor/grants:** Active `superadmin`
- **Preconditions:** Active Clinic, Guidance, or BMG intern signed in on both web workstation browser and mobile bearer client.
**Steps**
1. Submit `/admin/interns/{id}/revoke` as `superadmin` with a valid revocation reason and optimistic version.
2. Immediately attempt protected Clinic/Guidance/BMG web actions and mobile API requests using the intern's open browser session and bearer token.
- **Expected:** Placement status transitions to `revoked`, `users.role` reverts atomically to `student`, `auth_version` increments, bearer tokens are revoked, and next protected requests fail immediately with audit `intern.revoked`.
- **Negative/non-disclosure:** Stale version returns 409, blank revocation reason fails 422, and historical student portal records remain intact.
- **Cleanup:** Reset disposable placement/user fixtures.
- **Source:** app/Services/StudentInternService.php:75-105; app/Controllers/StudentInternController.php:89-98
- **Evidence:** Revocation confirmation, 409/401/403 open-session/token denial, restored `student` role check, and audit row.
- **Verdict:** `NOT_RUN`

### Administration, Access & Platform \(6 cases\)

#### AU-011 — Provisioned break-glass administrator can sign in locally
- **Lifecycle:** `READY`
- **Legacy IDs:** `AU-011`
- **Changed by:** baseline
- **Requirement:** `AUTH.BREAK_GLASS.LOCAL`
- **Role:** `superadmin`
- **Module:** Administration, Access & Platform
- **Use Cases:** `UC-AD-08`
- **Surface:** Web sign-in
- **Route/API:** POST /auth/login; GET /admin
- **Actor/grants:** Deliberately provisioned active local superadmin with applicable grants
- **Preconditions:** Isolated synthetic break-glass account created by the supported command; password held outside evidence; main host; MIS may remain unavailable.
**Steps**
1. Submit the break-glass username and valid local password on the main host.
2. Open the authorized administrative landing and one grant-protected page.
- **Expected:** The local password is verified, a version-bound session is created, and authorized administration pages load.
- **Negative/non-disclosure:** Ordinary MIS identities do not gain local fallback; the stored hash, password, and unrelated tenant data never appear in output.
- **Cleanup:** Sign out, revoke/delete the temporary break-glass fixture through its supported teardown, and purge captured credentials.
- **Source:** app/Services/AuthService.php:67-77,319-346; docs/SESSION_SECURITY.md:78-92
- **Evidence:** Administrative landing and protected-page screenshots plus redacted local-auth request/status evidence.
- **Verdict:** `NOT_RUN`

#### AD-005 — MIS synchronization is transactional and bounded
- Lifecycle: `DRAFT`
- Legacy IDs: `AD-005`
- Changed by: `bea71cf`
- Requirement: An authorized manual reference or people sync must publish optional user-scoped progress, commit only a complete validated result, and report incomplete upstream failure without exposing payloads; live MIS execution requires an approved campaign.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-07`
- Surface: MIS synchronization administration.
- Route/API: `GET /admin/mis-sync`; `POST /admin/mis-sync/run`; `POST /admin/mis-sync/people`.
- Actor/grants: Actor with `mis.sync`; fake MIS client by default, approved upstream operator only in a gated campaign.
- Preconditions: Disposable DB; deterministic fake client with success and midstream-failure variants; unique progress ID.

**Steps**
1. Record catalog/people counts, trigger a fake successful sync with progress, and compare committed results and status timestamp.
2. Reset, run a fake failure after partial progress, and compare every affected table with the pre-run snapshot.
3. Inspect messages and user-channel progress events; confirm a people sync changes no accounts, roles, passwords, or clinical records.

- Expected: Success commits complete scoped data and optional progress; failure reports unavailable/incomplete and commits nothing; progress failure itself does not abort an otherwise valid sync.
- Negative/non-disclosure: Raw MIS payloads, keys, upstream exception text, identities not required by evidence, and live personal data are never captured.
- Cleanup: Retire DB and disconnect fake/upstream client credentials.
- Source: `app/Controllers/MisSyncController.php:18-94`; `app/Controllers/MisSyncController.php:108-126`
- Evidence: Before/after table digests, progress event summary, response messages, and campaign authorization if applicable.
- Verdict: `NOT_RUN`

#### AD-006 — Employee role assignment is scoped, versioned, and session-revoking
- Lifecycle: `DRAFT`
- Legacy IDs: `AD-006`
- Changed by: `bea71cf`
- Requirement: An authorized administrator may assign only policy-permitted roles to an active employee, with nonblank reason and current `auth_version`; the write increments that version, invalidates mobile/browser authority, and records one audit event. Final employee assignment policy remains unresolved.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-07`, `UC-AD-08`
- Surface: Employee access administration.
- Route/API: `GET /admin/access/{user}/edit`; `POST /admin/access/{user}/update`.
- Actor/grants: Superadmin or department administrator allowed by current policy and `rbac.manage`.
- Preconditions: Disposable active employee target, two target sessions, current version, at least two active superadmins for demotion variant.

**Steps**
1. Open the target edit page, assign a permitted different role with a reason/current version, and inspect response and audit ledger.
2. Reuse the old target browser and bearer token on their next requests, then reload the access page.
3. Submit the stale version, a disallowed cross-department role, self-change, inactive employee promotion, and last-active-superadmin removal variants.

- Expected: One valid update increments `auth_version`, changes role, audits previous/new role and reason, and makes stale authority fail; all protected variants reject without write.
- Negative/non-disclosure: The screen does not provision identity/password/provider appointment; admins cannot escape department scope or change themselves.
- Cleanup: Restore target role through a fresh versioned request and end sessions.
- Source: `app/Services/EmployeeAccessService.php:13-60`; `app/Services/EmployeeAccessService.php:129-183`
- Evidence: Redacted before/after versions, old-session failures, policy rejection matrix, and audit event.
- Verdict: `NOT_RUN`

#### AD-007 — Sign-in carousel changes are ordered, scoped, and safely published
- Lifecycle: `READY`
- Legacy IDs: `AD-007`
- Changed by: `bea71cf`, `c179273`
- Requirement: Authorized carousel create/edit/reorder/toggle/delete actions must be tenant-safe, validate managed assets, and become visible to web/mobile login surfaces without exposing the private store.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-08`
- Surface: Carousel administration, web login, and mobile login carousel.
- Route/API: `/admin/carousel/*`; `GET /auth/login`; `GET /api/v1/auth/carousel`.
- Actor/grants: RBAC manager; platform owner for protected default deletion where required.
- Preconditions: Disposable test artwork containing no personal data; two slides with known order.

**Steps**
1. Add the test slide, reorder it, toggle it hidden, and compare admin state with the unauthenticated web/mobile carousel after each action.
2. Re-enable and edit the slide, then request its managed asset URL and verify content/security headers.
3. Attempt cross-tenant ID use, unsafe upload, duplicate order payload, and unauthorized delete; finally delete the test slide.

- Expected: Order/visibility propagate consistently; assets are served only through the managed controller; invalid/unauthorized actions fail without corrupting order.
- Negative/non-disclosure: Private storage paths, arbitrary files, hidden slides, credentials, and historical personal artwork are not exposed.
- Cleanup: Delete the synthetic slide and test asset.
- Source: `app/Config/Routes.php:16-20`; `app/Config/Routes.php:291-301`; `app/Controllers/AdminCarouselController.php:41-223`
- Evidence: Admin/web/mobile captures, sanitized asset headers, and cleanup proof.
- Verdict: `NOT_RUN`

#### AD-009 — Role and permission catalogue reflects all 16 grants without implying assignability
- **Lifecycle:** `READY`
- **Legacy IDs:** `AD-009`
- **Changed by:** `bea71cf`, `62c790b`
- **Requirement:** The catalogue must list all 16 database-granted roles (13 assignable employee roles plus non-employee catalogue roles `student`, `guidance_intern`, and `bmg_intern`), while the assignment selector remains actor-policy-specific.
- **Role:** `superadmin`
- **Module:** Administration, Access & Platform
- **Use Cases:** `UC-AD-08`
- Surface: Employee access detail/catalogue.
- Route/API: `GET /admin/access/{user}/edit`.
- Actor/grants: Authorized employee-access administrator.
- Preconditions: Seeded permission/role grant tables (`PermissionsAndRolesSeeder`) and an employee target.

**Steps**
1. Open access detail and inventory every role/permission combination shown in the catalogue.
2. Compare it with distinct `role_permissions` roles (16 total: 13 in `EmployeeAccessService::ROLES` plus 3 in `CATALOGUE_LABELS`: `student`, `guidance_intern`, `bmg_intern`).
3. Compare the role assignment selector as `superadmin`, `clinic_admin`, and `guidance_admin`.

- Expected: All 16 granted roles and codes are visible as catalogue facts; selector choices follow current scoped assignment policy (excluding `student` and student interns governed under `/admin/interns`).
- Negative/non-disclosure: A displayed role does not imply it is assignable on `/admin/access`; absent grants are not invented; no clinical data appears.
- Cleanup: None.
- Source: `app/Services/EmployeeAccessService.php:16-63`; `app/Services/EmployeeAccessService.php:114-129`; `app/Database/Seeds/PermissionsAndRolesSeeder.php:122-277`
- Evidence: 16-role/grant reconciliation table and role-specific selector screenshots.
- Verdict: `NOT_RUN`

#### AD-OPS-001 — Administrative mutations revalidate fresh authority and version
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `884b9f0`
- Requirement: High-impact administrative writes must use POST/CSRF where web-based, refresh actor authority at mutation time, require optimistic version where defined, and fail without partial state after role/session changes.
- Role: `superadmin`
- Module: Administration, Access & Platform
- Use Cases: `UC-AD-08`
- Surface: Employee access, carousel, AI configuration, MIS sync, and audit verification controls.
- Route/API: Relevant `/admin/*` POST routes.
- Actor/grants: Initially authorized administrator whose grant/session is revoked in a second session.
- Preconditions: Disposable fixtures and two sessions for the same administrator; safe no-secret test payloads.

**Steps**
1. Open each target form and retain its CSRF/version, then revoke or change the actor’s role/auth version from a separate authorized session.
2. Submit the retained forms and direct GET/malformed requests to each mutation route.
3. Compare target rows, audit counts, downstream jobs/progress, and session behavior with the pre-submit snapshot.

- Expected: Stale authority, CSRF, method, or version is rejected; no partial mutation, job, or misleading success occurs; fresh sign-in/version is required.
- Negative/non-disclosure: Denials do not echo secrets, submitted provider keys, MIS payloads, SQL, or another tenant’s object existence.
- Cleanup: Restore administrator policy only through a fresh authorized session; remove all synthetic rows.
- Source: `app/Services/EmployeeAccessService.php:148-183`; `app/Controllers/MisSyncController.php:48-60`; `app/Controllers/AiConfigurationController.php:246-296`
- Evidence: Stale-request matrix, before/after table digests, and response headers/messages.
- Verdict: `NOT_RUN`

### AI Insights \(5 cases\)

#### AI-001 — Provider discovery stages models without changing production settings
- Lifecycle: `READY`
- Legacy IDs: `AI-001`
- Changed by: `bea71cf`, `8674603`
- Requirement: An authorized model discovery must use an approved endpoint profile, bounded/rate-limited response, release the session during network work, revalidate actor and settings revision afterward, and stage a private preview before selective import.
- Role: `superadmin`
- Module: AI Insights
- Use Cases: `UC-AD-10`
- Surface: AI configuration web console.
- Route/API: `POST /admin/ai/models/preview`; `POST /admin/ai/models/import`.
- Actor/grants: Platform administrator with `ai.configuration.write`.
- Preconditions: Disposable tenant; synthetic local provider with deterministic valid/invalid model lists; no patient data.

**Steps**
1. Fetch models from the synthetic provider and inspect preview count/token while separately recording saved settings, active model, and analytics state.
2. Import a selected subset, then inspect the catalogue and settings again.
3. During another discovery change the actor role or saved settings; also return duplicate, oversized, and malformed catalogues.

- Expected: Discovery/import affects only staged/imported catalogue entries; current settings/model/analytics remain unchanged; actor/settings race and invalid catalogues fail closed.
- Negative/non-disclosure: Raw API key, preview secret, provider response beyond selected metadata, patient data, session internals, and private-network access without opt-in are absent.
- Cleanup: Remove synthetic imported models and provider credentials; retire DB.
- Source: `app/Controllers/AiConfigurationController.php:62-102`; `app/Controllers/AiConfigurationController.php:271-299`
- Evidence: Sanitized before/after settings/catalogue, provider request summary, and failure matrix.
- Verdict: `NOT_RUN`

#### AI-002 — Saving or testing settings pauses dispatch until explicit enable
- Lifecycle: `READY`
- Legacy IDs: `AI-002`
- Changed by: `bea71cf`, `8674603`
- Requirement: Every settings save pauses dispatch; testing an enabled profile requires pause confirmation and leaves analytics disabled; only a successfully tested compatible saved revision plus explicit privacy confirmation can be enabled.
- Role: `superadmin`
- Module: AI Insights
- Use Cases: `UC-AD-10`
- Surface: AI configuration controls.
- Route/API: `POST /admin/ai/save`; `/test`; `/enable`.
- Actor/grants: Administrator with configuration write/test authority.
- Preconditions: Disposable tenant with a previously enabled synthetic-provider profile and current version.

**Steps**
1. Change one setting and save; inspect settings status and department Generate controls before testing.
2. Run the synthetic test (including enabled-without-confirmation negative variant) and verify analytics stays paused afterward.
3. Enable with the current tested version and privacy confirmation; repeat with stale/untested version.

- Expected: Save and test pause dispatch; no generation is available until explicit valid enable; stale/untested/unconfirmed enable fails and rollback keeps deployment paused.
- Negative/non-disclosure: Test sends no patient data, already-sent provider calls are not claimed recalled, and failure does not reveal key/config internals.
- Cleanup: Disable analytics, remove synthetic key, and retire DB.
- Source: `app/Controllers/AiConfigurationController.php:50-59`; `app/Controllers/AiConfigurationController.php:121-149`; `app/Controllers/AiConfigurationController.php:152-198`
- Evidence: Status progression screenshots, synthetic provider request record, and negative responses.
- Verdict: `NOT_RUN`

#### AI-003 — API key is write-only and encrypted at rest
- Lifecycle: `READY`
- Legacy IDs: `AI-003`
- Changed by: `bea71cf`
- Requirement: A saved provider key must be encrypted in managed storage, represented only by a boolean/status after save, never returned in HTML/JSON/logs, and blank input must retain it unless explicit removal/no-auth mode is chosen.
- Role: `superadmin`
- Module: AI Insights
- Use Cases: `UC-AD-10`
- Surface: AI settings page/status endpoint.
- Route/API: `POST /admin/ai/save`; `GET /admin/ai`; `GET /admin/ai/status`.
- Actor/grants: AI configuration administrator.
- Preconditions: Unique canary key in disposable tenant; log capture configured to search the canary safely.

**Steps**
1. Save the canary key, reload page/status, and search response bodies/client state/logs for the exact canary.
2. Save an unrelated setting with blank key and run a synthetic connection test to confirm retained credential use.
3. Explicitly remove/change to allowed no-auth configuration, then verify the old key is unusable and still never disclosed.

- Expected: Key field remains blank, status says a key exists without value, ciphertext is not plaintext, blank preserves it, explicit removal clears it.
- Negative/non-disclosure: Canary never enters screenshots, committed artifacts, URLs, errors, audit payloads, model previews, or logs.
- Cleanup: Explicitly remove key, purge transient logs containing only hashes, and retire DB.
- Source: `app/Controllers/AiConfigurationController.php:23-47`; `app/Services/Ai/SettingsStore.php:92-213`
- Evidence: Canary-hash search report, redacted UI/status, encrypted-row property check, and removal proof.
- Verdict: `NOT_RUN`

#### AI-004 — Department AI grants are tenant/unit/role/permission scoped
- Lifecycle: `READY`
- Legacy IDs: `AI-004`
- Changed by: `bea71cf`
- Requirement: Read/generate/review/approve grants must be explicit tenant/unit/role permissions, independently audited, and must not grant underlying source-data authority.
- Role: `superadmin`
- Module: AI Insights
- Use Cases: `UC-AD-10`
- Surface: AI access matrix and department insight panels.
- Route/API: `POST /admin/ai/access`; `GET /{unit}/insights`.
- Actor/grants: AI configuration administrator; target staff roles in clinic/guidance/BMG.
- Preconditions: Disposable tenant with source grants varied independently from AI grants.

**Steps**
1. Grant each AI permission one at a time to one role/unit and compare controls/API outcomes after every change.
2. Revoke each permission and verify current session’s next request uses fresh authority.
3. Hold AI generate while removing the unit’s source-data permission, and try cross-tenant/unit/role inputs.

- Expected: Each matrix change is scoped and audited separately; actions reflect current grant; generation still requires analysis source permission; invalid scope fails atomically.
- Negative/non-disclosure: AI grants never expose patient charts, counseling notes, other units/tenants, provider key, or unrelated RBAC permissions.
- Cleanup: Revoke synthetic grants and retire DB.
- Source: `app/Controllers/AiConfigurationController.php:105-118`; `app/Services/Ai/InsightApplication.php:151-168`
- Evidence: Grant/action matrix and audit event set.
- Verdict: `NOT_RUN`

#### AI-011 — Disable stops new analytics while preserving truthful limits
- Lifecycle: `READY`
- Legacy IDs: `AI-011`
- Changed by: `bea71cf`
- Requirement: Disable must pause deployment and mark settings disabled with current version while preserving provider configuration/key; new analytics actions refuse, queued/running handling remains explicit, and already-sent requests are not claimed recalled.
- Role: `superadmin`
- Module: AI Insights
- Use Cases: `UC-AD-10`
- Surface: AI settings and department panels.
- Route/API: `POST /admin/ai/disable`; insight endpoints; worker status.
- Actor/grants: Configuration administrator and department staff.
- Preconditions: Enabled synthetic profile, one queued job and optionally one controlled in-flight request variant.

**Steps**
1. Disable using current version and inspect settings/panel controls/status.
2. Attempt prepare/generate/recover/retry and run worker against the queued job.
3. For the controlled in-flight variant, disable after outbound reservation and observe final truthful outcome handling.

- Expected: New work is unavailable and queued job does not dispatch under revoked deployment; key/config remain; in-flight provider work is not represented as recalled and ambiguity is retained safely.
- Negative/non-disclosure: Disable does not delete audit/review/budget records, expose secret configuration, fabricate cancellation, or auto-retry.
- Cleanup: Stop worker/provider, cancel only safely queued work, remove key, retire DB.
- Source: `app/Controllers/AiConfigurationController.php:141-149`; `app/Services/Ai/InsightApplication.php:160-168`; `app/Views/ai/panel.php:11-47`
- Evidence: Settings/panel progression, worker/provider count, and in-flight outcome trace.
- Verdict: `NOT_RUN`

## 20. Commissioned Hardware Operator \(Explicit facilities\.hardware\.manage Grant\)

> **Role ID:** `commissioned_hardware_op` · **Classification:** `explicit_grant` · **Cases:** **8**  
> Base actor band (no parent role inheritance).  
> **Scope:** BMG administrator or operator explicitly granted `facilities.hardware.manage` to commission ESP32-S3 hardware, authorize USB firmware builds, publish hardware schedules, and execute simulated/live device commands.

### Facilities \(BMG\) & Hardware \(8 cases\)

#### BM-010 — Provision, command, acknowledge, and report a simulated controller
- Lifecycle: `CONDITIONAL`
- Legacy IDs: `BM-010`
- Changed by: `bea71cf`
- Requirement: Registration must rotate a one-time credential and disable automation; an authorized gated command must bind to exactly one active device, expire safely, be acknowledged as a device claim, and remain distinct from a separately reported run.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: BMG unit detail plus device API.
- Route/API: `POST /facilities/unit/{unit}/device`; `POST /facilities/unit/{unit}/command`; `GET /api/v1/devices/bmg/commands`; `POST /api/v1/devices/bmg/commands/ack`; `POST /api/v1/devices/bmg/runs`.
- Actor/grants: Human BMG administrator with configure/manage/hardware grants; approved simulator using the one-time device token.
- Preconditions: Hardware gate enabled only in the isolated campaign; active unit; no pending command; simulated controller; current schedule version.

**Steps**
1. Register the simulator MAC, capture the credential once, confirm the schedule was disabled, and verify the credential does not reappear after leaving the page.
2. Queue `run_now`, poll commands with the device token, acknowledge it `completed`, then post a distinct valid `run_uid` after the binding timestamp.
3. Inspect command and run histories and repeat the exact acknowledgement and run submission.

- Expected: One active binding exists; command is pending then acknowledged; acknowledgement retry is idempotent; one run fact is stored and duplicate identical run returns acknowledged without another row.
- Negative/non-disclosure: The UI never labels acknowledgement as verified motion; token/hash is not in evidence; conflicting duplicate facts, wrong token, and cross-binding IDs are rejected.
- Cleanup: Disable schedule, queue/confirm stop only under the campaign procedure, revoke the credential, place unit in maintenance, and retire the DB.
- Source: `app/Services/Facilities/BmgRotationService.php:263-310`; `app/Services/Facilities/BmgRotationService.php:332-368`; `app/Services/Facilities/BmgRotationService.php:414-495`
- Evidence: Redacted one-time credential ceremony, command/ack/run statuses, row counts, and safety checklist.
- Verdict: `NOT_RUN`

#### BM-011 — Publish a Manila rotation schedule with effective gate separation
- Lifecycle: `CONDITIONAL`
- Legacy IDs: `BM-011`
- Changed by: `bea71cf`
- Requirement: A schedule is versioned intent in Asia/Manila, limited to eight distinct non-overlapping `HH:MM` windows, duration 1–600 seconds, optional PWM 1–255, forward direction, and effective only when all hardware/unit/binding gates hold.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-04`, `UC-BM-05`
- Surface: BMG schedule editor and device schedule API.
- Route/API: `POST /facilities/unit/{unit}/schedule`; `GET /api/v1/devices/bmg/schedule`.
- Actor/grants: BMG administrator with configure/manage/hardware grants; bound simulator token.
- Preconditions: Isolated hardware campaign; active unit; exactly one bound simulator; current schedule version.

**Steps**
1. Save a valid enabled schedule containing two non-overlapping Manila times, duration, and PWM; fetch it through the bound device API.
2. Confirm published and effective fields, version, timezone, sorted times, and forward direction.
3. Turn the hardware gate off or unit to maintenance and refetch, then try duplicate/overlapping times and a stale expected version.

- Expected: Valid intent increments version and becomes effective only while every gate holds; gate loss returns `enabled=false` and no PWM; invalid/stale schedules are rejected.
- Negative/non-disclosure: Saving or fetching a schedule does not claim dispatch, execution, RTC accuracy, or physical safety; another device/tenant receives no schedule.
- Cleanup: Save disabled schedule, cancel pending commands, revoke simulator, and retire the disposable DB.
- Source: `app/Services/Facilities/BmgRotationService.php:212-260`; `app/Services/Facilities/BmgRotationService.php:371-386`; `app/Services/Facilities/BmgRotationService.php:567-600`
- Evidence: Versioned request/response pairs, gate-change comparison, invalid-case responses, and safety checklist.
- Verdict: `NOT_RUN`

#### BM-012 — Build and authorize private USB commissioning artifacts
- Lifecycle: `CONDITIONAL`
- Legacy IDs: `BM-012`
- Changed by: `bea71cf`
- Requirement: Commissioning may build only reviewed sources with pinned toolchain/dependencies, motor outputs disabled, private actor/tenant/unit binding, 30-minute retention, and explicit physical-isolation consent; build artifacts do not authorize or perform flashing.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-06`
- Surface: BMG USB workspace.
- Route/API: `GET /facilities/unit/{unit}/usb/setup-status`; `POST /facilities/unit/{unit}/usb/build`; manifest/part routes; `POST /facilities/unit/{unit}/usb/authorize-flash`.
- Actor/grants: Human BMG administrator with `facilities.read`, `facilities.manage`, and `facilities.configure`; separate flash authorization policy applies.
- Preconditions: Isolated commissioning workstation; pinned `arduino-cli`, ESP32 core 3.3.3 and ArduinoJson 7.4.2; maintenance unit, disabled schedule, one binding, no pending command, current credential/version.

**Steps**
1. Verify setup status and reviewed sketch metadata, then submit valid network settings, matching device token, expected version, and explicit physical-isolation/HTTP-or-CA consent.
2. Inspect the private manifest and exactly four sparse parts, hashes, addresses, expiry, and “motor outputs disabled” messaging without executing an upload command.
3. Attempt access as another actor/unit, after expiry, after rebinding/version change, and without consent; then perform only the authorization-record step if the campaign explicitly permits it.

- Expected: A valid private build is actor/tenant/unit-bound and available for 30 minutes; changed conditions fail closed; no source upload, merged image, global catalog, serial port action, or automatic flash occurs.
- Negative/non-disclosure: Wi-Fi password, device token/hash, compiler paths/output, private headers, binaries, and personal data are not captured in committed evidence.
- Cleanup: Delete/expire private build directory, revoke credential, keep schedule disabled, remove campaign flags, and retire DB.
- Source: `app/Controllers/BmgUsbController.php:32-132`; `app/Services/Facilities/BmgBuildService.php:19-119`; `app/Services/Facilities/BmgBuildService.php:184-258`
- Evidence: Sanitized setup/manifest metadata, hash-and-part checklist, negative response log, and campaign authorization record.
- Verdict: `NOT_RUN`

#### BM-HW-001 — Device authentication, throttling, and maintenance fail closed
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Every device endpoint must require `X-Device-Token`, use no-store responses, bound IP/token throttles, bounded object input, and a maintenance-mode `503` that performs no device operation.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: BMG device API.
- Route/API: `/api/v1/devices/bmg/*`.
- Actor/grants: Approved simulator; no human browser session authority.
- Preconditions: Isolated device campaign and disposable DB; maintenance flag can be controlled safely.

**Steps**
1. Call schedule, heartbeat, runs, commands, and acknowledgement without a token, with a revoked token, and with an oversized/non-object JSON body.
2. Trigger the documented rate budget with the simulator and record `Retry-After` without stressing any shared service.
3. Enable the disposable maintenance flag and repeat a valid call, then compare all relevant row counts.

- Expected: Missing/revoked credentials return `401`, malformed/oversized payloads return bounded errors, rate excess returns `429`, and maintenance returns `503` plus `Retry-After: 60` with no write.
- Negative/non-disclosure: Error responses do not expose token hashes, SQL, paths, exception chains, another tenant, or whether a guessed device exists.
- Cleanup: Remove the maintenance flag, revoke simulator credentials, and retire DB.
- Source: `app/Controllers/Api/BmgDeviceApiController.php:42-87`; `app/Services/Facilities/BmgRotationService.php:649-663`
- Evidence: Sanitized response matrix, headers, and before/after row counts.
- Verdict: `NOT_RUN`

#### BM-HW-002 — Heartbeat keeps omitted telemetry unknown
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Heartbeat must update liveness and only explicitly supplied firmware, RTC, and applied-schedule fields with independent receipt timestamps; omission must retain prior/unknown values and an applied version cannot exceed the published version.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: Device heartbeat and BMG unit detail.
- Route/API: `POST /api/v1/devices/bmg/heartbeat`.
- Actor/grants: Bound approved simulator token.
- Preconditions: Isolated campaign; known published schedule version; telemetry fields initially null or known.

**Steps**
1. Post only `firmware_version`, then inspect the device detail and per-field receipt timestamps.
2. Post only `rtc_valid`, then an empty object, and confirm omitted fields/timestamps remain unchanged while `last_seen_at` advances.
3. Post an applied schedule version greater than the published version.

- Expected: Only supplied fields receive new values/timestamps; empty heartbeat does not infer telemetry; future applied version is rejected without corrupting stored values.
- Negative/non-disclosure: Liveness is not represented as RTC validity, current firmware, applied schedule, motor state, or safety proof.
- Cleanup: Revoke simulator and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:389-411`; `app/Services/Facilities/BmgRotationService.php:547-564`
- Evidence: Redacted heartbeat payloads and chronological device-row snapshots.
- Verdict: `NOT_RUN`

#### BM-HW-003 — Run attribution and durable `run_uid` deduplication
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Device run facts must use a bounded unique `run_uid`, allowed reported state, 0–86400 duration, valid nonfuture Manila start time strictly after current binding, and must never be reassigned across bindings.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: Device run-ingest API and unit run history.
- Route/API: `POST /api/v1/devices/bmg/runs`.
- Actor/grants: Bound approved simulator token.
- Preconditions: Isolated campaign; recorded binding timestamp; no real motor required.

**Steps**
1. Submit a valid post-binding reported run and repeat it identically; verify one stored row and `created` then `acknowledged` outcomes.
2. Reuse the same UID with changed facts, a future time, and a timestamp at/before binding.
3. Rebind/re-register the simulator and attempt to reuse a UID from the previous binding.

- Expected: Only the first valid fact creates a row; identical retry is idempotent; conflicting, future, prebinding, and previous-binding submissions return bounded rejection.
- Negative/non-disclosure: Server does not infer authorization or physical truth from the report, and never silently assigns unknown legacy runs to a unit.
- Cleanup: Revoke device and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:414-453`
- Evidence: Sanitized response sequence and unit/device/run row comparison.
- Verdict: `NOT_RUN`

#### BM-HW-004 — Pending commands cancel on authority or binding change
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Command delivery and acknowledgement must revalidate hardware gate, active unit, exact current binding, binding cutoff, and unit identity; invalid pending commands must become terminal rather than be delivered later.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-05`
- Surface: BMG command form and device commands API.
- Route/API: `POST /facilities/unit/{unit}/command`; `GET /api/v1/devices/bmg/commands`; `POST /api/v1/devices/bmg/commands/ack`.
- Actor/grants: Hardware-authorized human plus bound simulator.
- Preconditions: Isolated campaign; one pending `run_now` command.

**Steps**
1. Queue a valid command and verify it is pending for the current device and unit.
2. Before polling, turn hardware gate off, place unit in maintenance, or rotate the device binding in separate fixture variants.
3. Poll and attempt acknowledgement with old/current credentials; inspect command status and notification event.

- Expected: Revalidation cancels the unsafe pending command; it is not returned for execution and cannot be acknowledged as pending; a terminal workflow event is recorded where supported.
- Negative/non-disclosure: No stale command crosses a binding, gate, unit, actor, or tenant boundary; cancellation still is not proof a physical motor stopped.
- Cleanup: Confirm no pending commands, revoke credentials, keep unit maintenance/offline, and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:456-495`; `app/Services/Facilities/BmgRotationService.php:620-646`
- Evidence: Command lifecycle extract, poll/ack responses, and gate/binding timeline.
- Verdict: `NOT_RUN`

#### BM-HW-005 — Archive and restore retire all operational bindings
- Lifecycle: `CONDITIONAL`
- Legacy IDs: None
- Changed by: `bea71cf`
- Requirement: Archiving with explicit physical-safety acknowledgement must be refused while a batch is active, then disable schedule intent, cancel commands, revoke/unbind every controller, retain history, and restore only as offline with fresh commissioning required.
- Role: `commissioned_hardware_op`
- Module: Facilities (BMG) & Hardware
- Use Cases: `UC-BM-03`, `UC-BM-05`
- Surface: BMG archived-unit workflow.
- Route/API: `POST /facilities/unit/{unit}/archive`; `POST /facilities/unit/{unit}/restore`.
- Actor/grants: BMG administrator with configure/manage; hardware campaign controls for any prior device setup.
- Preconditions: Disposable unit with past history, bound simulator, saved schedule, and no active batch for the successful variant.

**Steps**
1. With an active batch or without `retirement_confirmed`, attempt archive and verify rejection.
2. Close the batch, explicitly acknowledge checked physical safety, archive using current version, then try all old device credentials.
3. Open archived view, restore using fresh version, and inspect status, schedule, command, device, and history state.

- Expected: Successful archive retains history but leaves unit offline/deleted, schedule disabled, commands terminal, and devices revoked/unbound; restore is offline and requires a new controller before operation.
- Negative/non-disclosure: The checkbox does not replace lockout/tagout or prove motor state; old credentials and intent never reactivate; another tenant cannot see/restore the unit.
- Cleanup: Re-archive the test unit, remove campaign gates, and retire DB.
- Source: `app/Services/Facilities/BmgRotationService.php:144-209`; `app/Controllers/FacilitiesController.php:126-139`
- Evidence: Rejection/success transcripts, archived/restored state matrix, old-token failures, and safety acknowledgement record.
- Verdict: `NOT_RUN`

## 21. Unit Staff under Explicit AI Grant \(ai\.insights\.\*\)

> **Role ID:** `ai_granted_staff` · **Classification:** `explicit_grant` · **Cases:** **10**  
> Base actor band (no parent role inheritance).  
> **Scope:** Department staff granted explicit tenant/unit/role-scoped `ai.insights.*` permissions outside default role seeding to prepare, generate, recover, review, approve, and read aggregate AI insights.

### AI Insights \(10 cases\)

#### AI-005 — Generate requires explicit AI and source-data authority
- Lifecycle: `READY`
- Legacy IDs: `AI-005`
- Changed by: `bea71cf`
- Requirement: Generate/prepare/recover/retry must revalidate current unit-specific AI generate and source permission; hidden controls are convenience only and direct POST must fail without queuing or contacting a provider.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: Department insights panel/endpoints.
- Route/API: `POST /{unit}/insights/prepare`; `/generate`; `/recover`; `/jobs/{id}/retry`.
- Actor/grants: Staff role variants lacking AI generate or source-data permission.
- Preconditions: Eligible aggregate fixture and synthetic provider counter; analytics otherwise ready.

**Steps**
1. Open panel under each missing-grant variant and record available controls.
2. Directly POST valid-looking requests despite hidden controls and inspect jobs/snapshots/provider counter.
3. Grant both authorities, repeat one valid prepare/generate, then revoke one before worker dispatch.

- Expected: Missing authority returns denial and creates/sends nothing; both grants permit enqueue; revocation before dispatch prevents provider work.
- Negative/non-disclosure: Denial does not reveal eligibility counts, stored snapshot, provider setup, or another unit’s analyses.
- Cleanup: Cancel safe queued job, revoke test grants, disable analytics, retire DB.
- Source: `app/Controllers/AiInsightsController.php:127-191`; `app/Services/Ai/InsightApplication.php:70-93`; `app/Services/Ai/InsightApplication.php:151-157`
- Evidence: Control/direct-route matrix, jobs/snapshots row counts, and provider counter.
- Verdict: `NOT_RUN`

#### AI-006 — Local summary is aggregate, privacy-checked, and encrypted
- Lifecycle: `READY`
- Legacy IDs: `AI-006`
- Changed by: `bea71cf`, `884b9f0`
- Requirement: Prepare must build only an approved fixed analysis over completed Manila months, apply contributor/small-cell disclosure rules, freeze released values/masks, encrypt the snapshot, and contact no AI provider.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: Department “Preview data”.
- Route/API: `POST /{unit}/insights/prepare`.
- Actor/grants: Authorized generator with unit source permission.
- Preconditions: Deterministic eligible and below-threshold fixtures in disposable DB; provider request counter zero.

**Steps**
1. Prepare each approved unit analysis at a fixed Manila clock and reconcile disclosed/withheld metrics and month labels.
2. Inspect storage properties and rendered evidence; repeat identical prepare and then alter a previously released monthly value/category mask.
3. Run below-threshold and ineligible fixtures and inspect message/provider counter.

- Expected: Eligible aggregate evidence is encrypted/reused consistently; small cells are withheld, unavailable is not zero; changed frozen release requires review; no provider call occurs.
- Negative/non-disclosure: Names, IDs, charts/notes, free prompts/selectors, small counts, plaintext snapshot, and provider credentials are absent.
- Cleanup: Purge/retire AI fixture DB and confirm provider counter remains zero.
- Source: `app/Services/Ai/InsightApplication.php:64-81`; `app/Services/Ai/InsightStore.php:231-283`; `app/Services/Ai/InsightStore.php:718-798`
- Evidence: Aggregate reconciliation, withheld-cell capture, encrypted-storage property check, and zero-call log.
- Verdict: `NOT_RUN`

#### AI-007 — HTTP generation only queues a deduplicated job
- Lifecycle: `READY`
- Legacy IDs: `AI-007`
- Changed by: `bea71cf`
- Requirement: Generate must prepare current evidence and enqueue one deduplicated, budget-reserved, provenance-stamped waiting request; the HTTP action itself must never call the provider.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: Department Generate action.
- Route/API: `POST /{unit}/insights/generate`.
- Actor/grants: Authorized generator.
- Preconditions: Eligible fixture, approved/tested profile, worker stopped, synthetic provider counter.

**Steps**
1. Submit generate once and inspect HTTP `202`, job status, snapshot ID/generation, profile revision, model, root ID, and pipeline digest.
2. Repeat identical requests sequentially and concurrently.
3. Inspect provider counter and daily admission behavior at the configured limit.

- Expected: One queued job is created and repeats report reuse/latest attempt; provenance fields and expiry are present; no provider call occurs without worker; exhausted budget rejects.
- Negative/non-disclosure: Response contains no encrypted evidence, prompt, key, dispatch lease/token, budget key, or patient data.
- Cleanup: Cancel queued job before starting any worker and retire DB.
- Source: `app/Controllers/AiInsightsController.php:42-50`; `app/Services/Ai/InsightStore.php:286-334`
- Evidence: HTTP/job metadata, concurrent row count, and zero provider-call log.
- Verdict: `NOT_RUN`

#### AI-008 — Worker dispatch is single-attempt, leased, and provenance-bound
- Lifecycle: `READY`
- Legacy IDs: `AI-008`
- Changed by: `bea71cf`, `8674603`
- Requirement: A separately launched worker may claim an eligible queued job, recheck authorization/profile/snapshot generation/pipeline provenance, reserve one outbound daily slot immediately before one provider POST, validate output, and store an encrypted draft.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: `ai:dispatch` worker plus insights panel.
- Route/API: CLI worker and `GET /{unit}/insights/panel`.
- Actor/grants: Approved test operator; queued job’s actor still authorized.
- Preconditions: Disposable AI campaign, synthetic provider, one valid queued job, worker lease test hooks.

**Steps**
1. Start one worker for one job and capture claim/reservation/provider-call/completion chronology.
2. Start competing workers and verify only one outbound request and one draft.
3. Before dispatch, vary actor authorization, profile revision, snapshot generation/expiry, or pipeline digest; test malformed provider output.

- Expected: Only fully current job dispatches once; output is validated/encrypted as draft; stale/policy/malformed variants fail without draft or unsafe replay.
- Negative/non-disclosure: Provider receives only approved aggregate payload; logs/evidence omit key, plaintext snapshot/draft, prompt body, lease token, and personal data.
- Cleanup: Stop worker/provider, purge encrypted test payloads through supported command, disable analytics, retire DB.
- Source: `app/Services/Ai/InsightWorker.php:36-166`; `app/Services/Ai/InsightStore.php:580-638`; `app/Services/Ai/InsightStore.php:861-889`
- Evidence: Sanitized chronology, provider call count/hash, job/draft status, and stale-variant matrix.
- Verdict: `NOT_RUN`

#### AI-009 — Review transitions are versioned and policy-bound
- Lifecycle: `READY`
- Legacy IDs: `AI-009`
- Changed by: `bea71cf`
- Requirement: Draft may transition `draft→accepted→approved` or `draft|accepted→rejected`, with current version and separate review/approve grants; nonreject decisions require current profile and pipeline provenance, and approval requires at least one program.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-02`
- Surface: AI draft review dialog.
- Route/API: `POST /{unit}/insights/{id}/review`.
- Actor/grants: Reviewer with `ai.insights.review`; approver with `ai.insights.approve`.
- Preconditions: Current encrypted drafts with and without programs; two reviewers sharing an initial version; historical-policy draft.

**Steps**
1. Accept then approve a current program draft using successive versions; inspect review rows and panel state.
2. Race two decisions at one version and attempt approve-before-accept, repeat accept, and approve a no-program draft.
3. Attempt accept/approve/reject on a profile- or pipeline-historical draft.

- Expected: Valid transitions increment version and append reviewer decision; one race wins; illegal/stale transitions conflict; historical draft is reject-only.
- Negative/non-disclosure: Approval does not publish, fund, schedule, notify, or prove correctness; unauthorized actors do not see plaintext draft.
- Cleanup: Retire the disposable drafts/database.
- Source: `app/Services/Ai/InsightApplication.php:95-113`; `app/Services/Ai/InsightStore.php:641-674`; `app/Views/ai/panel.php:199-259`
- Evidence: Version/decision timeline, conflict responses, review rows, and no-side-effect checks.
- Verdict: `NOT_RUN`

#### AI-010 — Read surface withholds unavailable and unapproved content
- Lifecycle: `READY`
- Legacy IDs: `AI-010`
- Changed by: `bea71cf`
- Requirement: Insight panel must expose only the caller’s authorized unit/tenant, unexpired encrypted-store projections and mark review state/policy accurately; read-only users must not gain review/generate controls or historical plaintext.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-02`
- Surface: Department insights panel.
- Route/API: `GET /{unit}/insights`; `GET /{unit}/insights/panel`.
- Actor/grants: Role with `ai.insights.read` only.
- Preconditions: Draft, accepted, approved, rejected, expired/purged, other-unit, and other-tenant fixtures.

**Steps**
1. Open the panel as read-only and inventory visible statuses/content and absent controls.
2. Directly request review/generate routes and manipulate unit path/IDs.
3. Expire/purge one payload and refresh; compare other tenant/unit records.

- Expected: Only authorized unexpired unit records appear; state labels are truthful; controls/direct writes deny; purged/expired/foreign data is absent.
- Negative/non-disclosure: No ciphertext, plaintext expired payload, other scope, provider key, internal prompt, or unsupported causal/clinical claim is disclosed.
- Cleanup: Retire fixture DB.
- Source: `app/Services/Ai/InsightApplication.php:27-61`; `app/Services/Ai/InsightStore.php:428-469`; `app/Views/ai/panel.php:190-246`
- Evidence: Visibility/status matrix and denied direct-route responses.
- Verdict: `NOT_RUN`

#### AI-REC-001 — Expired evidence recovers only if bitwise-policy identical
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Explicit confirmed recovery may rematerialize only current-period evidence whose schema, disclosure version, canonical fingerprint, analysis, tenant, and unit exactly match the expired row; it increments generation, encrypts a new retention payload, expires queued old jobs, and renews no approval.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: “Needs attention” recovery control.
- Route/API: `POST /{unit}/insights/recover`.
- Actor/grants: Authorized generator.
- Preconditions: Expired/purged current-period snapshot and unchanged source fixture; second variant changes a released metric/category.

**Steps**
1. Attempt recovery without confirmation and with an active/nonexpired snapshot.
2. Recover the unchanged expired snapshot and inspect ID, generation, expiry, storage audit, old jobs, and prior reviews/insights.
3. Change one source value/category mask and retry recovery.

- Expected: Only confirmed unchanged evidence rematerializes in the same snapshot identity with incremented generation; old jobs/approvals are not renewed; changed evidence returns review-required conflict.
- Negative/non-disclosure: Recovery contacts no provider, returns no plaintext persisted payload beyond authorized rendered aggregate, and never resurrects jobs/drafts/reviews.
- Cleanup: Purge recovered payload via supported tooling and retire DB.
- Source: `app/Controllers/AiInsightsController.php:53-63`; `app/Services/Ai/InsightStore.php:164-219`
- Evidence: Generation/expiry/audit comparison, old-job/review state, zero-call provider log, and changed-evidence response.
- Verdict: `NOT_RUN`

#### AI-REC-002 — Safe retry requires proof no outbound dispatch occurred
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Manual retry is allowed only for a valid failed/cancelled/expired request family with current intact snapshot/profile/model/provenance and zero dispatch-attempt reservations across the chain; it creates one deduplicated successor under the normal budget.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: “Queue safe new attempt”.
- Route/API: `POST /{unit}/insights/jobs/{id}/retry`.
- Actor/grants: Authorized generator.
- Preconditions: Synthetic job families covering pre-dispatch failure, cancelled, expired, provider-attempted, outcome-unknown, lease-lost, stale generation, and changed pipeline.

**Steps**
1. Retry a pre-dispatch safe failure with confirmation and repeat the retry concurrently.
2. Inspect successor `retry_of_id`, root, snapshot generation, pipeline digest, budget, and provider counter before worker.
3. Attempt retry for every unsafe/stale/ambiguous family variant.

- Expected: One safe successor is queued/reused and consumes normal admission budget; every family with possible dispatch or provenance/generation mismatch is ineligible.
- Negative/non-disclosure: Provider error labels alone never prove retry safety; no automatic replay, key, lease, or payload is exposed.
- Cleanup: Cancel safe successor before dispatch and retire DB.
- Source: `app/Services/Ai/InsightStore.php:337-425`; `app/Controllers/AiInsightsController.php:66-75`
- Evidence: Family eligibility matrix, successor metadata, concurrent row count, and zero-call log.
- Verdict: `NOT_RUN`

#### AI-REC-003 — Ambiguous dispatch becomes terminal `outcome_unknown`
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Timeout/lease loss or any failure after a possible provider dispatch must become `outcome_unknown`, clear active lease fields, warn that processing/charging may have occurred, and never become retry eligible.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-01`
- Surface: Worker and request history.
- Route/API: Worker failure path; `GET /{unit}/insights/panel`.
- Actor/grants: Approved AI test operator and authorized panel reader.
- Preconditions: Synthetic provider accepts request then withholds/interrupts response; controllable lease expiry.

**Steps**
1. Dispatch one aggregate request and induce response ambiguity after reservation/provider acceptance.
2. Let lease expire or invoke ambiguous failure, then refresh request history.
3. Attempt manual/automatic retry and run another dispatcher cycle.

- Expected: Job is `outcome_unknown` with cleared lease/active key and truthful warning; no draft is shown; retry control absent and no second provider call occurs.
- Negative/non-disclosure: System does not claim failure/success/refund, does not reveal provider body or aggregate payload, and does not replay.
- Cleanup: Stop provider/worker, disable analytics, retain only sanitized trace, retire DB.
- Source: `app/Services/Ai/InsightStore.php:628-638`; `app/Services/Ai/InsightStore.php:704-715`; `app/Views/ai/panel.php:27-47`; `app/Views/ai/panel.php:100-111`
- Evidence: Reservation/provider/job timeline, panel capture, and provider call count.
- Verdict: `NOT_RUN`

#### AI-REC-004 — Pipeline provenance blocks historical acceptance and completion
- Lifecycle: `READY`
- Legacy IDs: None
- Changed by: `bea71cf`, `8674603`
- Requirement: Jobs and drafts must carry the deterministic public-code pipeline digest; dispatch completion and nonreject review require a digest matching current policy files and current snapshot generation, while historical drafts remain reject-only.
- Role: `ai_granted_staff`
- Module: AI Insights
- Use Cases: `UC-AI-02`
- Surface: Worker completion and draft review.
- Route/API: Worker/store operations; `POST /{unit}/insights/{id}/review`.
- Actor/grants: Authorized worker/reviewer/approver.
- Preconditions: Current job/draft and cloned variants with old/malformed/missing digest or changed policy file in isolated checkout.

**Steps**
1. Record digest for current job/draft and complete/review the valid control.
2. Try worker completion after changing snapshot generation or pipeline code/digest.
3. Open historical draft and attempt accept, approve, and reject.

- Expected: Valid current provenance succeeds; stale completion fails; historical/malformed provenance cannot be accepted/approved or dispatched but an authorized rejection remains available.
- Negative/non-disclosure: Digest contains only public code identity—not settings, endpoint, credentials, tenant data, prompt payload, or secrets.
- Cleanup: Restore isolated code checkout, delete synthetic variants, and retire DB.
- Source: `app/Services/Ai/PipelineProvenance.php:7-31`; `app/Services/Ai/InsightApplication.php:95-113`; `app/Services/Ai/InsightStore.php:861-889`
- Evidence: Digest/status matrix, stale completion response, and review-control/response captures.
- Verdict: `NOT_RUN`
