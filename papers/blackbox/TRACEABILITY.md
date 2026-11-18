# Post-baseline traceability

## Scope and method

Baseline/current comparison is from historical source commit `0383939de24119936dee372f86fb4565d12c42d7` to target application commit `884b9f0d647b9b7deb0085ac6af8d4520a269c53`. The table maps each of the 14 distinct post-baseline implementation patches requested for review to representative current black-box cases. “Representative” is not exhaustive: one patch can affect many cases and a case can cover behavior assembled across patches.

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
| `e5b7eba` | Mobile composer/layout/transitions, personal screens, web profile settings and runtime assets | MB-004, MB-PLAT-008; UX-WEB-005, UX-WEB-006, UX-WEB-008; PT/profile cases in the first-half sections |
| `a06b70b` | Team Chat visibility/composer clearance and Android navigation insets | TW-MOB-012, TW-MOB-013, TW-MOB-016; MB-PLAT-007, MB-PLAT-008; UX-WEB-006 |
| `39f7339` | Hidden LAN server control and preserved ordinary login behavior | MB-001, MB-PER-001, MB-PLAT-001, MB-PLAT-002 |
| `c179273` | Android notification onboarding, system notifications, named-region refresh rollout | RT-NT-003, RT-NT-005, RT-NT-006; MB-010, MB-012; MB-PLAT-003 through MB-PLAT-007; AD-007 |
| `b868a49` | Clinic/Guidance controlled workflows, intake/governance/forms/episodes/MI/evaluations, Guidance mobile API, AI survey prefill and student-view fixes | GD-API-001 through GD-API-006; UX-WEB-004; MB-005, MB-007, MB-009; current Guidance/clinic cases in sections 03–08 |
| `884b9f0` | Guidance cumulative records/context corrections, clinic inventory/history updates, broad responsive workspace pass | UX-WEB-001 through UX-WEB-008; GD-API-001 through GD-API-006; MB-005, MB-007; AD-001, AD-002; BM-001, BM-013; current clinic/Guidance cases in sections 03–08 |

## Coverage rules

- A patch mapping proves that a current case considered the changed surface; it does not prove the case was executed.
- Infrastructure-only patches are explicitly retained so the 14-patch inventory is complete without inventing product cases.
- Automated tests changed in these patches are corroboration only under `EVIDENCE.md`.
- If a later patch changes an unresolved contract, update `CONTRACT-DECISIONS.md`, affected case lifecycle/steps, and this map together.
- Historical results are not a substitute for this target-commit map.
