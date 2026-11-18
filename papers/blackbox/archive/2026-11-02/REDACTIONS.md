# Redactions

The 14 canonical source files were reviewed before retention. This log names every changed file and the categories changed, but deliberately does not reproduce any removed value.

## Findings summary

- Credential/secret findings: a shared test password, credential-like/API-key material, a CSRF token value, a one-time device token, realtime ticket signature fragments, encrypted protected-payload excerpts, and environment-specific configuration fingerprints.
- Identity/contact findings: real or contact-form names, account/email identifiers, telephone numbers, institutional identifiers, academic-profile attributes, synthetic account/display identities used as stable record-linkage keys, local workstation user paths, and hardware identifiers.
- Sensitive-care findings: person-linked clinical profile values, vital signs, complaints, assessment/outcome text, medication details, counselling/referral narratives, identified survey responses, and care-record identifiers.
- Cookie findings: cookie names appeared as process evidence; no cookie value was found or retained.

## Changed canonical files

| Archive path | Redaction categories |
| --- | --- |
| `SYNAPSE-BlackBox-Testing.html` | real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier; sensitive clinical data; sensitive counselling or referral data |
| `SYNAPSE-BlackBox-Testing.md` | real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier; sensitive clinical data; sensitive counselling or referral data |
| `TEST-RESULTS-CL-GD.md` | real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier; sensitive clinical data; sensitive counselling or referral data |
| `TEST-RUN-PROMPT.md` | credential or token value; synthetic account or identity-linkage data; local workstation identity/path |
| `VERIFICATION-ANALYSIS-FAIL-BLOCKED.md` | local workstation identity/path |
| `results/ADMIN-RESULTS.md` | credential or token value; real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier |
| `results/AI-INSIGHTS-RESULTS.md` | credential or token value; encrypted protected payload excerpt; synthetic account or identity-linkage data; environment configuration fingerprint; sensitive care-record identifier |
| `results/AUTH-RESULTS.md` | real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier; sensitive clinical data |
| `results/FACILITIES-RESULTS.md` | credential or token value; synthetic account or identity-linkage data; local workstation identity/path; device identifier |
| `results/PORTAL-RESULTS.md` | real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier; sensitive counselling or referral data |
| `results/REALTIME-RESULTS.md` | credential or token value; synthetic account or identity-linkage data; sensitive care-record identifier |
| `results/REFERRALS-RESULTS.md` | real identity or contact data; synthetic account or identity-linkage data; sensitive care-record identifier; sensitive counselling or referral data |

## Byte-preserved canonical files

| Archive path | Review result |
| --- | --- |
| `SYNAPSE-BlackBox-Testing.docx` | XML/relationship scan found no retained credential, real identity/contact value, or person-specific care content; preserved byte-for-byte as the blank SYNAPSE protocol. |
| `sample/sample-BlackBox-Testing.docx` | XML/relationship scan found no retained credential, real identity/contact value, or person-specific care content; preserved byte-for-byte as the historical blank sample protocol. |

Redaction labels are descriptive categories only. They are not reversible mappings.

## Residual repository exposure

These sanitized files do not remove their originals from pre-existing Git object stores, refs, reflogs, clones, backups, or replicas. No history rewrite, ref/reflog deletion, or garbage collection was performed. Direct blob locators are intentionally omitted from the public manifest, but the source commit and original paths remain for restricted provenance. Credentials, tokens, cookies, keys, tickets, and device secrets found during redaction must be treated as compromised and rotated or revoked; sanitization is not secret revocation.
