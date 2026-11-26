# SYNAPSE current black-box catalog

This directory contains the current, source-traceable black-box specification aligned with application commit `e7d5572fd117c7f556393894798ec408204cd4ca` (plus uncommitted `feat/archive-management` official OGC 8-Form alignment and public Data Privacy Policy updates) and synchronized with **Figure 5 (1–6 of 6)** of the capstone Use Case model.

## Authority model

1. `sections/*.md` are the canonical current cases. Each case ID appears exactly once there and declares its primary `Role`, `Module`, and `Use Cases` (`UC-*`).
2. `use-cases.json` defines the canonical 16 RBAC roles (plus Public, Shared Authenticated, Department Workstation Member, and Explicit Grant bands), 15 modules, and 72 Use Cases (`UC-*`) matching **Figure 5**.
3. `suite.json` fixes section order, expected counts, commits, and allowed states.
4. `SYNAPSE-BlackBox-Testing.md` and `SYNAPSE-BlackBox-Testing.html` at this directory root are generated views organized under **Option A: User Role → Module → Functionality**, with an auto-generated **Use Case ↔ Black-Box Traceability Matrix**. Do not hand-edit generated outputs.
5. `TRACEABILITY.md`, `CONTRACT-DECISIONS.md`, `RUNBOOK.md`, and `EVIDENCE.md` govern scope, unresolved policy, execution, and proof.

## Current versus archive

The current catalog is the section set named by `suite.json`. Frozen historical run material belongs under `archive/2026-11-02/` and retains its own historical provenance and verdicts. Historical PASS/FAIL/BLOCKED values are evidence about that old run only. They are never copied into the current cases, never count as a current execution, and never certify the target commit.

Do not copy raw historical personal, clinical, counseling, credential, cookie, token, or device data into current cases or evidence.

## Lifecycle and verdict states

### Lifecycle

- `READY`: the contract is sufficiently resolved and its prerequisites can be assembled under the runbook.
- `DRAFT`: an explicit contract/policy decision or rollout decision remains open; see `CONTRACT-DECISIONS.md`. A draft may be rehearsed, but it cannot support release acceptance until resolved and updated.
- `CONDITIONAL`: an annex case may run only when its stated external capability, safety gate, device, native surface, or campaign approval exists. If the approved run scope excludes that campaign, record `NOT_IN_RUN`; if execution is attempted but a required condition is unavailable or unsafe, record `BLOCKED`. Conditions are never silently waived.

### Verdict

- `NOT_RUN`: no qualifying current execution evidence has been attached. This is the truthful default for every current case.
- `PASS`: all observable steps and expected/non-disclosure assertions passed in one identified current run with minimum evidence from `EVIDENCE.md`.
- `FAIL`: at least one requirement or non-disclosure assertion was contradicted by observation.
- `BLOCKED`: execution could not reach an assertion because a prerequisite was unavailable or unsafe. BLOCKED is not PASS.
- `NOT_IN_RUN`: the case belongs to the suite but is excluded from the identified run because its conditional campaign or approved run scope does not apply. This is not PASS or BLOCKED.

## Expected suite

The suite contains **266 current cases** across **22 ordered section source files**, compiled into **Role → Module → Functionality** chapters covering all **16 RBAC roles**, **72 Use Cases (`UC-*`)**, and **23 post-baseline patches**. `suite.json` and `use-cases.json` are the machine-readable authorities.

## Tooling

Install the pinned local HTML renderer once:

```sh
npm ci --prefix tools/blackbox --ignore-scripts
```

Validate, build, render, or verify generated outputs from the repository root:

```sh
python tools/blackbox/validate.py --root .
python tools/blackbox/build.py --root .
node tools/blackbox/render-html.mjs --root .
python tools/blackbox/build.py --root . --check
node tools/blackbox/render-html.mjs --root . --check
python -m unittest discover -s tools/blackbox/tests -p test_blackbox_tools.py -v
```

The generated root Markdown and HTML must never be edited manually. Optional result overlays require sanitized, schema-valid evidence contained beneath one non-symlink run root; see `tools/blackbox/README.md`. Optional DOCX/PDF publication is handled by `tools/blackbox/publish.py`, fails without supported external prerequisites, and must be run in an OS-enforced no-network sandbox.

There is no current certification claim: all catalog verdicts begin `NOT_RUN`, and no run summary in this change overrides that fact.
