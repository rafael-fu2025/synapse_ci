# SYNAPSE current black-box catalog

This directory contains the current, source-traceable black-box specification for application commit `884b9f0d647b9b7deb0085ac6af8d4520a269c53`. It is a draft test catalog, not an execution report, release sign-off, safety case, privacy certification, or current certification claim.

## Authority model

1. `sections/*.md` are the canonical current cases. A case ID appears exactly once there.
2. `suite.json` fixes section order, expected counts, commits, and allowed states.
3. `SYNAPSE-BlackBox-Testing.md` and `SYNAPSE-BlackBox-Testing.html` at this directory root are generated views only. Do not hand-edit them and do not treat a stale/missing generated view as authority.
4. `TRACEABILITY.md`, `CONTRACT-DECISIONS.md`, `RUNBOOK.md`, and `EVIDENCE.md` govern scope, unresolved policy, execution, and proof.
5. Source code at the target commit defines implemented behavior; a case describes how that behavior must be observed. Where policy is unresolved, the case is `DRAFT` rather than inventing a decision.

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

A verdict may change from `NOT_RUN` only through a governed run. Automated tests, code inspection, old reports, or a generated document alone cannot set PASS. Editing prose must not imply execution. A hash match proves only that the files supplied to the validator are internally consistent; it does not independently attest who executed the run, whether that identity is trusted, or whether the observation is correct. A separately verifiable signed attestation may add provenance, but its signer and trust policy must still be evaluated outside these tools.

For PASS or FAIL, the overlay must map verified evidence to every numbered step and to expected, negative/non-disclosure, and cleanup assertions. BLOCKED must identify blocker evidence; NOT_IN_RUN must identify scope evidence and is never accepted execution evidence. Dirty runs cannot carry any non-NOT_RUN verdict unless a nonempty `dirty.patch` artifact is declared and its verified digest equals `dirty_patch_sha256`.

## Expected suite

The suite contains 245 current cases across 20 ordered section files. Sections 11–20 contain 111 cases. `suite.json` is the machine-readable count and order authority.

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

## Running cases

Use only the disposable procedure in `RUNBOOK.md`. Store raw evidence under the private writable layout in `EVIDENCE.md`; commit at most a sanitized summary under `runs/`. Resolve applicable entries in `CONTRACT-DECISIONS.md` before claiming acceptance. Hardware, MIS, AI, push, device, and native Guidance campaigns require their explicit gates.

There is no current certification claim: all catalog verdicts begin `NOT_RUN`, and no run summary in this change overrides that fact.
