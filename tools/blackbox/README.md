# Deterministic black-box document tools

This directory owns validation and generated-document tooling for the SYNAPSE black-box suite. It does not own the case prose in `papers/blackbox/sections/` or the suite declaration in `papers/blackbox/suite.json`.

## Source and generated boundaries

Sources of truth:

- `papers/blackbox/suite.json` — metadata, ordered sections, counts, commits, legacy map/archive declarations, and output paths.
- `papers/blackbox/sections/*.md` — executable case specifications.
- `papers/blackbox/legacy-0383939-map.md` (or the map named by `suite.json`) — historical ID reconciliation only.
- `papers/blackbox/schema/*.schema.json` — strict run/result interchange contracts.
- A file passed with `--results` — optional sanitized execution overlay backed by a hashed run manifest and artifacts.

Generated files (never edit by hand):

- `papers/blackbox/SYNAPSE-BlackBox-Testing.md`
- `papers/blackbox/SYNAPSE-BlackBox-Testing.html`
- Optional DOCX/PDF paths declared by `suite.json`

Historical archive content is immutable. The publisher refuses destinations inside or aliasing the declared archive.

## Install the local renderer dependency

From `tools/blackbox`:

```sh
npm ci --ignore-scripts
```

`marked` is the only Node dependency and is pinned exactly in both `package.json` and `package-lock.json`. Python validation, building, publishing, and tests use only the standard library.

## Commands

Run from `tools/blackbox`:

```sh
npm run validate
npm run build
npm run render
npm run check
npm test
```

Equivalent direct commands, usable from any directory:

```sh
python tools/blackbox/validate.py --root .
python tools/blackbox/build.py --root .
node tools/blackbox/render-html.mjs --root .
python tools/blackbox/build.py --root . --check
node tools/blackbox/render-html.mjs --root . --check
```

For a current execution overlay, pass the same sanitized results file to validation/build:

```sh
python tools/blackbox/validate.py --root . --results papers/blackbox/results/run-001/results.json
python tools/blackbox/build.py --root . --results papers/blackbox/results/run-001/results.json
```

Without `--results`, every case source must be `NOT_RUN`. Historical PASS/FAIL/BLOCKED outcomes are never copied into the current suite. With `--results`, the results file, run manifest, and every referenced artifact must live beneath the results file’s single run directory; there is no repository-root fallback.

Overlay strings are bounded one-line plain text. Controls, raw HTML, Markdown images/links/autolinks/resources, and dangerous URL schemes are rejected before Markdown interpolation, and overlay values are Markdown-escaped. The HTML renderer escapes arbitrary raw HTML and only emits renderer-generated heading IDs and same-document fragment links.

`--check` compares expected bytes and never writes. HTML rendering first runs the full validator and Markdown freshness check; it cannot render stale or invalid Markdown. The generated Markdown and HTML contain no wall-clock timestamp, so unchanged validated inputs produce identical output. The HTML banner reports every verdict count and says “acceptance-ready” only when suite metadata is READY with zero NOT_RUN, FAIL, and BLOCKED cases; NOT_IN_RUN is always presented as excluded scope rather than accepted evidence.

## Validation contract

The validator checks, among other invariants:

- exact case heading, field order, required values (`Role`, `Module`, `Use Cases`), and at least two consecutively numbered steps;
- bidirectional Use Case synchronization against `papers/blackbox/use-cases.json` (every declared `UC-*` covered by at least one case);
- ID syntax/uniqueness and lifecycle/verdict enums;
- exactly 266 current cases and any declared per-section counts;
- exactly 119 unique legacy rows with 72 `RETAIN`, 42 `REWRITE`, and 5 `RETIRED` dispositions;
- bidirectional mapping for surviving legacy IDs and absence of retired IDs from current cases;
- case and authoritative traceability coverage for all 23 patch commits declared by `suite.json`/`TRACEABILITY.md` (`baseline` is accepted for unchanged cases);
- repository source-path existence and syntactically valid positive line ranges;
- strict JSON syntax/schema structure, including duplicate-key rejection, finite numbers only, and bounded bytes/depth;
- one-run-root evidence containment with no absolute/drive/ADS/device/dot/control components, no symlinked components, and only nonempty single-link regular files;
- internal artifact hash/size consistency for any claimed non-default verdict (not independent execution attestation);
- explicit `executed_by`, step/assertion/cleanup evidence coverage, optional signed-attestation artifact integrity, and dirty-run `dirty.patch` binding;
- the archive’s exact canonical/support payload set, strict manifest fields/totals/flags, source-commit SHA-256 when locally available, support-file hashes, and absence of symlinks/extras.

A validation error yields a nonzero exit code and prevents build output.

## Optional DOCX/PDF publication

Check external programs without converting:

```sh
python tools/blackbox/publish.py --check-prerequisites
```

Publish after Markdown is validated and current:

```sh
python tools/blackbox/publish.py --root .
```

Pandoc performs Markdown to DOCX; LibreOffice performs DOCX to PDF. The tool requires tested converter major versions, invokes argument arrays with `shell=False`, disables raw HTML in Pandoc, uses Pandoc sandbox mode plus an empty isolated resource/work/home directory, rejects DOCX macros/external relationships and malformed/active PDF structures, and publishes through randomized exclusive no-follow atomic writes. It preserves or restores previous outputs on failure and reports rollback failures explicitly; it does not leave a known stale partial output.

**No-network requirement:** Pandoc/LibreOffice flags cannot prove network denial. Run publication only inside an OS-enforced no-network sandbox (or equivalent denied network namespace/firewall policy) with no credential-bearing environment. The isolated resource path limits ordinary resource lookup but is defense in depth, not a network boundary.

## Tests

```sh
python -m unittest discover -s tools/blackbox/tests -p test_blackbox_tools.py -v
```

Tests build isolated temporary repositories and do not contact a product database or network.
