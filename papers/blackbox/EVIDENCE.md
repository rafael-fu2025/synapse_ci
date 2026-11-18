# Evidence protocol

## Principles

Raw black-box evidence is private, writable, access-controlled run data. It is not source documentation and must not be committed. Evidence must be sufficient to reproduce the observation while minimizing identity, clinical/counseling content, credentials, tokens, device addresses and infrastructure secrets.

Artifact, manifest, and result hashes establish internal byte consistency only. Because the same mutable run producer can rewrite a file and its declared hash, these self-declarations are not independent trust or execution attestation. Record `executed_by` with a pseudonymous tester ID, role, and human/human-with-automation kind. A signed attestation is optional and useful only when its signature, signer identity, and named trust policy are independently verified outside the validator.

A historical result, automated test, code citation, screenshot of prose, or generated tracker is not current execution evidence. Automated tests may corroborate a black-box result but never establish PASS alone.

## Private writable layout

For run ID `<run-id>` and case `<CASE-ID>`, use this exact layout outside `papers/blackbox/`:

```text
writable/blackbox/runs/<run-id>/
  manifest.json
  environment/
    versions.txt
    config-status.json
    schema-migrations.txt
    fixture-manifest.json
  cases/
    <CASE-ID>/
      case.json
      artifacts/
      notes.txt
  summary/
    run-summary.json
    run-summary.md
```

The root must be writable by the tester, private to the run, ignored by Git, and protected by local access controls. Do not use `papers/blackbox/runs/` for raw material.

## Exact artifact naming

Every raw artifact filename under `cases/<CASE-ID>/artifacts/` must be:

```text
<CASE-ID>__s<step-2digits>__<artifact-2digits>__<kind>__<utc-basic>.<ext>
```

Where:

- `<CASE-ID>` is the exact catalog ID (for example `RT-005` or `TW-MOB-006`);
- `<step-2digits>` is `01`, `02`, ... and maps to the numbered case step;
- `<artifact-2digits>` starts at `01` within that step;
- `<kind>` is one of `screenshot`, `screenrecord`, `http`, `websocket`, `db`, `cli`, `log`, `filemeta`, `accessibility`, `test`, `note`;
- `<utc-basic>` is `YYYYMMDDTHHMMSSZ` in UTC;
- `<ext>` is the true format: `png`, `webm`, `json`, `txt`, `csv`, `pdf`, etc.

Example:

```text
RT-005__s02__01__screenrecord__20261118T031522Z.webm
RT-005__s02__02__http__20261118T031528Z.json
```

Never put a person name, username, email, MIS ID, token, server address, diagnosis, counseling topic, device token/MAC, or result value in a filename.

## Run manifest metadata

`manifest.json` must be deterministic UTF-8 JSON and include at least:

- `run_id`, `suite_version`, catalog source/ref and expected count;
- full application commit and dirty-worktree status; a dirty run with any non-NOT_RUN verdict must include the nonempty `dirty.patch` artifact and matching `dirty_patch_sha256`;
- `executed_by` pseudonymous tester ID, tester role, and `HUMAN` or `HUMAN_WITH_AUTOMATION` kind;
- run start/end in RFC3339 UTC and `business_timezone: "Asia/Manila"`;
- environment identifier, OS/runtime/browser/mobile/device versions;
- database alias/fingerprint proving it is disposable (never DSN/password/dump);
- migration/schema identifiers and synthetic fixture manifest hash;
- hosts/origins as sanitized aliases where addresses are sensitive;
- enabled/disabled feature/campaign flags by **name and state only**;
- external simulator/fake service identities and approvals, never credentials;
- per case: ID, lifecycle, start/end, actor alias/role, host/surface, fixture aliases, prerequisites, step outcomes, artifact relative paths plus SHA-256/size/MIME, cleanup result, verdict and reason;
- automated corroboration command name/sanitized arguments, commit, exit code and artifact, if used;
- redaction review status, reviewer, retention expiry, and sanitized-summary path/hash.

Each `case.json` should mirror the per-case entry and may add detailed observations. Do not embed binary artifacts or secrets in JSON.

## Minimum evidence for PASS

A current case may be marked PASS only when all of the following exist in one identified run:

1. **Environment proof:** target commit, suite version, disposable DB/fixture aliases, actor/grants, host/surface, timezone and applicable gate states.
2. **Step proof:** at least one artifact or precise timestamped observation for every numbered step, with request/action and visible/status outcome correlated.
3. **Expected proof:** evidence that every Expected clause was observed, including persisted state or authoritative response where the case requires it.
4. **Negative/non-disclosure proof:** the specified denial/isolation/redaction/fail-safe checks were actually attempted and observed. A screenshot of absence alone is insufficient when a direct request or second identity is required.
5. **Cleanup proof:** cleanup succeeded and no test artifact/credential/job/command remained; whole disposable DB retirement may be final run-level proof.
6. **Integrity:** artifact SHA-256, byte size, MIME/extension, step mapping and capture time are recorded; edits/redactions produce a derived sanitized artifact with its own hash while raw original remains private until retention expiry.
7. **Human observation:** a tester records the black-box outcome. Automated tests may corroborate but cannot replace items 2–5.

In a committed result overlay, each evidence reference must carry an explicit `covers` array. PASS and FAIL require `step:1` through the case’s final numbered step plus `assertion:expected`, `assertion:negative`, and `assertion:cleanup`. BLOCKED requires `blocker` plus cleanup coverage. NOT_IN_RUN requires `scope:not-in-run` plus cleanup coverage and is never evidence of acceptance. Run-level cleanup marked COMPLETED must itself name at least one verified cleanup artifact; NOT_REQUIRED must explain why.

If any required assertion cannot be reached, use BLOCKED. If contradicted, use FAIL. If no qualifying execution exists, keep NOT_RUN.

## Redaction and non-disclosure

### Never capture when avoidable

- passwords; session cookies; CSRF values beyond a statement that rotation occurred;
- access/refresh/realtime/device/push/evaluation tokens, token hashes/families, API/encryption/signing/publish keys;
- Wi-Fi credentials, private certificates, provider/MIS payloads/keys, database DSNs/passwords;
- real names/contact/MIS identifiers, patient/student IDs, diagnoses, prescriptions, clinical/counseling notes, safety assessments, survey/MI/evaluation answers;
- browser profiles, full database dumps, raw firmware binaries/private build headers, installed-device identifiers;
- another tenant/account’s content even when testing isolation—use synthetic canary labels and record “not disclosed.”

Use synthetic `.invalid` identities and aliases. Prefer counts, hashes, row IDs scoped to disposable fixtures, status codes, field-name inventories, and before/after digests.

### When an artifact contains sensitive fields

1. Keep the raw original only in the private writable run root with restricted permissions and retention date.
2. Create a derived sanitized copy; never overwrite the raw original and call it raw.
3. Replace sensitive values with explicit labels such as `[REDACTED: access token]`, `[REDACTED: synthetic clinical text]`, or blur irreversibly in image/video.
4. Record raw and sanitized hashes, transformation tool/method/version, categories redacted, reviewer and date without reproducing values.
5. Commit only the summary-level conclusions allowed by `runs/README.md`; do not commit raw or “sanitized” evidence that still contains linkable personal/clinical/device data.

For negative tests, report the category and outcome (for example “foreign record canary absent”) rather than the attempted secret/identifier.

## Artifact-specific guidance

- **Screenshots/video:** crop to the relevant surface; hide OS notifications, address bars containing sensitive hosts, other tabs, profile/avatar/name, and unrelated records. Preserve viewport/device metadata separately.
- **HTTP/WebSocket:** store a structured sanitized transcript with method, route template, status, safe headers, payload field names and synthetic aliases. Remove Authorization, Cookie, Set-Cookie, CSRF, tickets, device/push tokens, bodies containing protected text and raw host/IP where restricted.
- **Database:** use narrowly scoped SELECT output or counts/digests from the disposable DB. Never store full table/database dumps. Exclude encrypted/ciphertext and credential columns unless the assertion is a nonplaintext property, in which case record type/length/hash-safe fact only.
- **Logs/CLI:** enable a run-specific log; include only relevant bounded lines after secret/PII review. Do not use historical `writable/blackbox` logs.
- **Downloaded files:** raw exports/firmware artifacts remain private; evidence records safe metadata/hash and sanitized content comparison. Delete downloads during cleanup.
- **Accessibility:** store sanitized report plus viewport/theme/zoom/tool version and manual findings; DOM snapshots must be scrubbed like HTTP bodies.

## Automated tests are corroboration only

Record an automated test only after confirming it ran at the exact target commit/config against an allowed isolated DB or database-free harness. Preserve exact command name and safe arguments, tool/runtime version, start/end, exit code, stdout/stderr artifact hash and test selection. Do not run product tests against operational data.

A passing automated test can corroborate a manual observation; it cannot independently set PASS, prove presentation/accessibility, demonstrate a real external integration, or replace negative/non-disclosure and cleanup evidence. A failing test is a finding that must be investigated; do not conceal it because manual steps passed.

## Sanitized committed summaries

A committed `papers/blackbox/runs/<run-id>.md` (if approved) may contain only:

- run ID/date, suite/application commit, environment class, tester role;
- case IDs and verdicts/reasons at a non-sensitive level;
- prerequisite/gate/cleanup summary;
- counts and redaction declaration;
- hashes of the private manifest/sanitized summary where useful, not links to raw local paths that expose identities.

It must state that raw evidence is private, where the governing retention process lives, and whether the disposable DB was retired. It must make no certification claim beyond the executed case set.
