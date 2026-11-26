# Post-baseline traceability

## Scope and method

Baseline/current comparison is from historical source commit `0383939de24119936dee372f86fb4565d12c42d7` through target application commit `e7d5572fd117c7f556393894798ec408204cd4ca` (plus uncommitted `feat/archive-management` OGC 8-Form Alignment and Data Privacy Policy updates). The table maps each of the 23 distinct non-merge post-baseline implementation patches to representative current black-box cases. “Representative” is not exhaustive: one patch can affect many cases and a case can cover behavior assembled across patches.

First-parent merge commits between these patches are merge wrappers. They duplicate the already mapped feature-patch content and are not additional distinct implementation changes; do not double-count them in coverage.

| Patch | Subject / coverage classification | Representative current cases |
| --- | --- | --- |
| `bea71cf` | AI recovery/provenance, personal/mobile workflows, scoped push/realtime, portal/clinic/Guidance/BMG hardening | AI-001, AI-REC-001, AI-REC-002, AI-REC-003, AI-REC-004; MB-001, MB-002, MB-003, MB-013; RT-001, RT-010; BM-001, AD-005 |
| `e08f383` | Bearer Team Workspace APIs, unit authority, idempotency, entitlements, mobile dual context and push capability | TW-MOB-001 through TW-MOB-016; MB-PER-001; MB-PLAT-005 |
| `3dfc393` | Infrastructure-only test runner pinning and database-free browser test isolation; no new user-facing contract | Infrastructure-only. Corroborates AI/browser execution readiness; it does not add a case or current verdict. |
| `8674603` | Infrastructure-only CI browser assets and patched PHP runtime enforcement; AI hardening tests | Infrastructure-only for catalog counting. It corroborates AI-008/AI-REC-* but cannot establish PASS. |
| `902fdd6` | Mobile Team Workspace interaction parity, drafts, navigation, shared screens | TW-MOB-001, TW-MOB-003, TW-MOB-004, TW-MOB-005, TW-MOB-007, TW-MOB-009, TW-MOB-010, TW-MOB-011; MB-011 |
| `c91477a` | Mobile high-refresh/realtime-first behavior, floating context navigation and repair polling | RT-006, RT-008, RT-010, RT-NT-006; MB-006, MB-012; TW-MOB-015; MB-PLAT-008 |
| `73b8a11` | Scoped LAN push releases, runtime/account/server isolation, live chat refresh | MB-PLAT-001, MB-PLAT-002, MB-PLAT-004, MB-PLAT-005, MB-PLAT-006; RT-007, RT-NT-004; TW-MOB-012 through TW-MOB-016; MB-002, MB-013, MB-PER-001 |
| `d5454a0` | Infrastructure-only Android SDK CI correction for retired tools package | Infrastructure-only. Supports reproducible Android build prerequisites in RUNBOOK; no user-facing case or verdict. |
| `e5b7eba` | Mobile composer/layout/transitions, personal screens, web profile settings and runtime assets | MB-004, MB-PLAT-008; UX-WEB-005, UX-WEB-006, UX-WEB-008; PT-PRF-001 through PT-PRF-004 |
| `a06b70b` | Team Chat visibility/composer clearance and Android navigation insets | TW-MOB-012, TW-MOB-013, TW-MOB-016; MB-PLAT-007, MB-PLAT-008; UX-WEB-006 |
| `39f7339` | Hidden LAN server control and preserved ordinary login behavior | MB-001, MB-PER-001, MB-PLAT-001, MB-PLAT-002 |
| `c179273` | Android notification onboarding, system notifications, named-region refresh rollout | RT-NT-003, RT-NT-005, RT-NT-006; MB-010, MB-012; MB-PLAT-003 through MB-PLAT-007; AD-007 |
| `b868a49` | Clinic/Guidance controlled workflows, intake/governance/forms/episodes/MI/evaluations, Guidance mobile API, AI survey prefill and student-view fixes | GD-API-001 through GD-API-006; UX-WEB-004; MB-005, MB-007, MB-009; CL-005, CL-007, CL-008, CL-010 |
| `884b9f0` | Guidance cumulative records/context corrections, clinic inventory/history updates, broad responsive workspace pass | UX-WEB-001 through UX-WEB-008; GD-API-001 through GD-API-006; MB-005, MB-007; AD-001, AD-002; BM-001, BM-013 |
| `c755cb0` | MIS login compatibility and employee identifier normalization hardening | AU-001, AU-002, AU-010 |
| `292bd11` | Hybrid batched operational aggregate reporting and Clinic PDF export updates | CL-RPT-001, CL-RPT-002, AD-010, AD-011 |
| `6deffdf` | Unified login hardening, survey linear-scale rating question support, and personal portal shell updates | PT-001, PT-010, GD-009, GD-010, UX-WEB-007 |
| `4526ee3` | Workstation UI redesigns, Teams Figma parity, and Guidance Clients & Records workspace | GD-REC-001, GD-REC-002, GD-REC-003, TW-WEB-001 through TW-WEB-012 |
| `e452387` | Scoped pagination across workspaces and BMG rotation schedule updates | BM-001, BM-009, BM-013, GD-REC-001, GD-REC-002 |
| `d43923d` | Team Chat automatic backread pagination on upward scroll, clean header, and circular scroll-to-latest control | TW-WEB-012, UX-WEB-006 |
| `731507c` | System-wide reversible Archive Governance Center, retention policies, legal holds, and silent undo UX | ARV-001 through ARV-008; CL-ANN-001 |
| `62c790b` | Student Intern Governance (`clinic_intern`, `guidance_intern`, `bmg_intern`), live shift clocks, unified institutional PDF headers, and drawer UX | INT-001 through INT-006; CL-RBAC-001, CL-RBAC-002, CL-RPT-002, AD-009, AD-011, TW-WEB-001 |
| `e7d5572` | Infrastructure-only CI tenant-scope lint, PHPUnit baseline, and AI/browser regression fixes; anchors pending `feat/archive-management` OGC 8-Form Alignment & Data Privacy Policy cases | Infrastructure-only; working-tree delta on `feat/archive-management` covered by AU-013, AU-014, GD-EVL-001, GD-OGC-001 through GD-OGC-004 |

## Uncommitted `feat/archive-management` Additions (Relative to `e7d5572`)
- **Official OGC 8-Form Verbatim Alignment** (`PIF-008`, `PCF-008`, `CCF-009`, `GGR-005`, `PEF-008`, `CEF-009`, `GAP-006`, `CSP-007`, migration `2026-11-26-000001_AlignGuidanceAppointmentAndCallSlipPurposes.php`, and live `POST /guidance/evaluations/issue`): covered by `GD-OGC-001` through `GD-OGC-004` and updated `GD-EVL-001`.
- **Public Foundation University Data Privacy Policy** (`GET /privacy-policy`, sign-in modal `#dataPrivacyPolicyModal`, and in-app RA 10173 consent notices): covered by `AU-013` and `AU-014`.

## Coverage rules

- A patch mapping proves that a current case considered the changed surface; it does not prove the case was executed.
- Infrastructure-only patches are explicitly retained so the 23-patch inventory is complete without inventing product cases.
- Automated tests changed in these patches are corroboration only under `EVIDENCE.md`.
- If a later patch changes an unresolved contract, update `CONTRACT-DECISIONS.md`, affected case lifecycle/steps, `use-cases.json`, and this map together.
- Historical results are not a substitute for this target-commit map.
