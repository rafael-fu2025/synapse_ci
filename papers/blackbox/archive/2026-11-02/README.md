# Frozen historical black-box archive — 2026-11-01–02

This directory is the frozen archive of the SYNAPSE black-box run conducted from **2026-11-01 through 2026-11-02**. Its sole source is Git commit `0383939de24119936dee372f86fb4565d12c42d7`. It is historical evidence, not a current test plan and not permission to rerun completed cases.

## Frozen outcome

| Total cases | PASS | FAIL | BLOCKED | Not run | Out of scope |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 119 | 90 | 1 | 14 | 0 | 14 |

The executable web scope was 105 cases (90 PASS, 1 FAIL, 14 BLOCKED). The 14 out-of-scope cases are the mobile application cases plus the mobile realtime case.

## Canonical payload

The payload contains exactly 14 canonical files: the tracker in Markdown, generated HTML, the blank SYNAPSE DOCX protocol, the clinic/guidance evidence report, the run prompt, the verification analysis, seven module result reports, and the historical sample DOCX protocol. `MANIFEST.json` binds every payload to its original Git path, source-commit SHA-256, archived SHA-256, role, preservation state, and redaction status. It also integrity-lists this README and `REDACTIONS.md`; it intentionally publishes no raw Git blob locator.

The manifest and working-tree copies provide an internally consistent, reviewable sanitized archive. They are not independent attestation that the historical run occurred or that its operators were trusted.

No file from `papers/blackbox/writable/` or any other part of the source commit was restored into this archive. No new branch or tag was created.

## Local Git object warning

Sanitizing working-tree copies does **not** erase the original sensitive blobs from existing local clones, object databases, refs, reflogs, backups, forks, or other replicas. This archive intentionally avoids publishing direct blob locators, but the original source commit and paths remain provenance and may still make the original objects reachable to someone who already possesses the repository. This hardening did not rewrite history, delete refs/reflogs, or run garbage collection. Treat every credential, token, cookie, key, ticket, or device secret found in the originals as compromised and rotate or revoke it through the owning system. Any destructive repository cleanup must be a separately approved, coordinated incident-response operation.

## Blank DOCX protocol notice

`SYNAPSE-BlackBox-Testing.docx` is the **blank 119-case execution protocol**: its result cells remain unfilled placeholders. It is not the frozen result ledger. The final verdicts are in `SYNAPSE-BlackBox-Testing.md`, its generated HTML counterpart, and the evidence reports.

`sample/sample-BlackBox-Testing.docx` is a historical blank sample protocol for a different system. It is included only because it is one of the 14 canonical source files; it is not SYNAPSE result evidence.

Both DOCX packages were scanned across their XML and relationship parts and were safe to preserve byte-for-byte.

## Privacy and secret handling

Before retention, all text payloads and all DOCX XML/relationship parts were scanned for credentials, tokens, cookies, secrets, real identities/contact data, and person-linked clinical or counselling content. Unsafe text values were replaced in place with explicit `[REDACTED: ...]` labels while headings, tables, case ordering, verdicts, and historical totals were retained. Cookie names may remain where they describe a test, but no cookie value was found or retained.

See `REDACTIONS.md` for changed files and categories. It intentionally does not reproduce removed values.
