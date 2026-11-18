# Sanitized run summaries only

This directory is reserved for reviewed, sanitized summaries of governed current black-box runs.

Do **not** commit screenshots, videos, HTTP/WebSocket captures, logs, CLI output, database extracts/dumps, downloaded reports, firmware artifacts, browser/mobile profiles, credentials, tokens, cookies, personal data, clinical/counseling content, device addresses, raw manifests, or other evidence here.

Raw evidence belongs under the ignored, private writable path:

```text
writable/blackbox/runs/<run-id>/
```

Follow `../EVIDENCE.md` for the exact layout, artifact naming, manifest, minimum PASS evidence, redaction and retention rules. Follow `../RUNBOOK.md` for disposable environment and cleanup requirements.

A committed summary must:

- identify suite version and full target application commit;
- include only case IDs, truthful verdicts and non-sensitive reasons;
- say which conditional campaigns/rollout flags were or were not approved;
- state cleanup and disposable-database retirement status;
- declare that raw evidence remains private and was reviewed for redaction;
- avoid any current certification claim beyond the specifically executed cases.

Historical reports belong only in the frozen archive, not here. An automated test report alone is not a run summary and cannot convert `NOT_RUN` to PASS.
