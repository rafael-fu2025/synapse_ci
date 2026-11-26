#!/usr/bin/env python3
"""Build the deterministic SYNAPSE black-box Markdown document (Option A: Role -> Module)."""

from __future__ import annotations

import argparse
import collections
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any, Sequence

import secure_io
import validate

OUTPUT_PATH = "papers/blackbox/SYNAPSE-BlackBox-Testing.md"


def _escape_markdown_text(value: Any) -> str:
    """Escape validated plain text before Markdown interpolation."""

    text = "" if value is None else str(value)
    text = text.replace("\r", "\\r").replace("\n", "\\n")
    return re.sub(r"([\\`*_{}\[\]()<>#+.!|~-])", r"\\\1", text)


def _escape_table(value: Any) -> str:
    if isinstance(value, (dict, list)):
        text = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    elif value is None:
        text = ""
    else:
        text = str(value)
    return _escape_markdown_text(text)


def _anchor_fragment(value: str, used_anchors: set[str] | None = None) -> str:
    base = re.sub(r"[^a-z0-9]+", "-", value.casefold()).strip("-") or "section"
    if used_anchors is None:
        return base
    anchor = base
    suffix = 2
    while anchor in used_anchors:
        anchor = f"{base}-{suffix}"
        suffix += 1
    used_anchors.add(anchor)
    return anchor


def _results_label(suite: validate.SuiteData) -> str:
    if suite.results_path is None:
        return "None — source verdicts are constrained to NOT_RUN"
    try:
        relative = suite.results_path.relative_to(suite.root).as_posix()
    except ValueError:
        return "External sanitized results (validated beneath its own run root; path and digest withheld)"
    return f"`{relative}`"


def _metadata_rows(suite: validate.SuiteData) -> list[tuple[str, str]]:
    preferred = ("title", "version", "status", "owner", "audience", "scope")
    ignored = {"target_commit", "expected_case_count", "patch_commits"}
    rows: list[tuple[str, str]] = []
    consumed: set[str] = set()
    for key in preferred:
        if key in suite.metadata:
            rows.append((key.replace("_", " ").title(), _escape_table(suite.metadata[key])))
            consumed.add(key)
    for key in sorted(set(suite.metadata) - consumed - ignored):
        rows.append((key.replace("_", " ").title(), _escape_table(suite.metadata[key])))
    rows.extend(
        [
            ("Organization", "User Role -> Module -> Functionality (Option A)"),
            ("Target commit", f"`{suite.target_commit}`"),
            ("Expected cases", str(validate.EXPECTED_CASE_COUNT)),
            ("Result overlay", _results_label(suite)),
            ("Archive", f"`{suite.archive_path}` ({suite.archive_state})"),
        ]
    )
    if suite.use_case_catalog is not None:
        rows.append(("Use-case catalog", f"{len(suite.use_case_catalog.get('use_cases', []))} use cases across {len(suite.use_case_catalog.get('roles', []))} actor groups (`papers/blackbox/use-cases.json`)"))
    return rows


def _replace_case_result(case: validate.Case, heading_prefix: str = "#### ") -> str:
    """Render one case while keeping source prose and overlaying only verified results."""

    raw = case.raw.rstrip()
    raw = re.sub(r"^## ", heading_prefix, raw, count=1, flags=re.MULTILINE)
    if case.effective_verdict != case.source_verdict:
        raw = re.sub(
            r"^(\s*-\s+(?:\*\*Verdict:?\*\*:?\s*|Verdict:\s*)).*$",
            lambda match: match.group(1) + f"`{case.effective_verdict}`",
            raw,
            count=1,
            flags=re.MULTILINE,
        )
    if case.effective_evidence:
        rendered_evidence = "; ".join(
            f"`{_escape_markdown_text(entry['artifact'])}` (SHA-256 `{entry['sha256']}`) — "
            f"{_escape_markdown_text(entry['description'])}"
            for entry in case.effective_evidence
        )
        if case.effective_actual:
            rendered_evidence += f"; observed: {_escape_markdown_text(case.effective_actual)}"
        raw = re.sub(
            r"^(\s*-\s+(?:\*\*Evidence:?\*\*:?\s*|Evidence:\s*)).*$",
            lambda match: match.group(1) + rendered_evidence,
            raw,
            count=1,
            flags=re.MULTILINE,
        )
    return raw


def _summary_row(cells: Sequence[str], cases: Sequence[validate.Case]) -> str:
    lc = collections.Counter(case.lifecycle for case in cases)
    vc = collections.Counter(case.effective_verdict for case in cases)
    return (
        "| "
        + " | ".join(
            list(cells)
            + [
                str(len(cases)),
                str(lc.get("READY", 0)),
                str(lc.get("DRAFT", 0)),
                str(lc.get("CONDITIONAL", 0)),
                str(vc.get("NOT_RUN", 0)),
                str(vc.get("PASS", 0)),
                str(vc.get("FAIL", 0)),
                str(vc.get("BLOCKED", 0)),
                str(vc.get("NOT_IN_RUN", 0)),
            ]
        )
        + " |"
    )


def render_document(suite: validate.SuiteData, report: validate.ValidationReport) -> bytes:
    lifecycle_counts = collections.Counter(case.lifecycle for case in suite.cases)
    verdict_counts = collections.Counter(case.effective_verdict for case in suite.cases)
    legacy_counts = collections.Counter(row.disposition for row in suite.legacy_rows)
    title = str(suite.metadata.get("title") or "SYNAPSE Black-Box Testing")
    status = str(suite.metadata.get("status") or "DRAFT").upper()

    chunks: list[str] = [
        f"# {title}",
        "",
        "## Document status",
        "",
        f"> **Document status: {status}.** This file is generated from ordered section sources and grouped by **User Role -> Module -> Functionality** to match the capstone Use Case model (Figure 5). Do not edit generated output by hand.",
        ">",
        "> **Execution state:** "
        + ", ".join(f"{name}={verdict_counts.get(name, 0)}" for name in validate.VERDICTS)
        + ". A non-NOT_RUN value is accepted only through a supplied sanitized results file "
        "whose artifacts are internally hash-consistent; hashes alone are not trusted execution attestation.",
        "",
        "## Metadata",
        "",
        "| Property | Value |",
        "| --- | --- |",
    ]
    chunks.extend(f"| {_escape_table(key)} | {value} |" for key, value in _metadata_rows(suite))

    warnings: list[str] = []
    if status != "READY":
        warnings.append(f"Suite metadata status is **{status}**, not READY.")
    if lifecycle_counts.get("DRAFT", 0):
        warnings.append(f"**{lifecycle_counts['DRAFT']}** cases have DRAFT lifecycle.")
    if lifecycle_counts.get("CONDITIONAL", 0):
        warnings.append(
            f"**{lifecycle_counts['CONDITIONAL']}** cases are CONDITIONAL and require their stated preconditions."
        )
    if verdict_counts.get("NOT_RUN", 0):
        warnings.append(
            f"**{verdict_counts['NOT_RUN']}** cases are NOT_RUN; expectations are specifications, not observed outcomes."
        )
    if verdict_counts.get("NOT_IN_RUN", 0):
        warnings.append(
            f"**{verdict_counts['NOT_IN_RUN']}** cases are NOT_IN_RUN; they are scoped out, not accepted evidence."
        )
    if suite.archive_state != "verified":
        warnings.append(
            f"The declared historical archive validation state is **{suite.archive_state}**; the build cannot claim archive integrity."
        )
    warnings.extend(report.warnings)
    chunks.extend(["", "## Warnings", ""])
    if warnings:
        chunks.extend(f"- {warning}" for warning in warnings)
    else:
        chunks.append("- No build warnings.")

    chunks.extend(
        [
            "",
            "## Computed summary",
            "",
            "### Lifecycle",
            "",
            "| Lifecycle | Cases |",
            "| --- | ---: |",
        ]
    )
    chunks.extend(f"| {name} | {lifecycle_counts.get(name, 0)} |" for name in validate.LIFECYCLES)
    chunks.extend(
        [
            f"| **Total** | **{len(suite.cases)}** |",
            "",
            "### Verdict",
            "",
            "| Verdict | Cases |",
            "| --- | ---: |",
        ]
    )
    chunks.extend(f"| {name} | {verdict_counts.get(name, 0)} |" for name in validate.VERDICTS)
    chunks.extend(
        [
            f"| **Total** | **{len(suite.cases)}** |",
            "",
            "### Legacy reconciliation",
            "",
            "| Disposition | Legacy cases |",
            "| --- | ---: |",
        ]
    )
    chunks.extend(
        f"| {name} | {legacy_counts.get(name, 0)} |" for name in validate.LEGACY_DISPOSITIONS
    )
    chunks.extend([f"| **Total** | **{len(suite.legacy_rows)}** |"])

    catalog = suite.use_case_catalog
    assert catalog is not None, "use-cases.json catalog is required for Role -> Module build"

    roles_meta = list(catalog["roles"])
    role_by_id = {r["id"]: r for r in roles_meta}
    module_order = list(catalog["modules"])
    module_rank = {m: idx for idx, m in enumerate(module_order)}

    cases_by_role: dict[str, list[validate.Case]] = collections.defaultdict(list)
    cases_by_uc: dict[str, list[validate.Case]] = collections.defaultdict(list)
    for case in suite.cases:
        cases_by_role[case.role].append(case)
        for uid in case.use_cases:
            cases_by_uc[uid].append(case)

    active_roles = [r for r in roles_meta if cases_by_role.get(r["id"])]

    chunks.extend(
        [
            "",
            "### Role groups and inheritance coverage",
            "",
            "| # | Role / Actor Group | Kind | Inherits From | Cases | READY | DRAFT | CONDITIONAL | NOT_RUN | PASS | FAIL | BLOCKED | NOT_IN_RUN |",
            "| ---: | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |",
        ]
    )
    for index, role in enumerate(active_roles, start=1):
        inherits_label = role.get("inherits") or "—"
        if inherits_label != "—":
            inherits_parts = [
                role_by_id.get(part.strip(), {}).get("label", part.strip())
                for part in str(inherits_label).split(",")
            ]
            inherits_label = ", ".join(inherits_parts)
        chunks.append(
            _summary_row(
                [
                    str(index),
                    _escape_table(role["label"]),
                    _escape_table(role.get("kind", "rbac")),
                    _escape_table(inherits_label),
                ],
                cases_by_role[role["id"]],
            )
        )

    chunks.extend(
        [
            "",
            "### Canonical source sections",
            "",
            "| # | Section | Source | Cases | READY | DRAFT | CONDITIONAL | NOT_RUN | PASS | FAIL | BLOCKED | NOT_IN_RUN |",
            "| ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |",
        ]
    )
    for index, section in enumerate(suite.sections, start=1):
        chunks.append(
            _summary_row(
                [str(index), _escape_table(section.title), f"`{section.path}`"],
                section.cases,
            )
        )

    # Track heading anchors across the full rendered document so TOC links match render-html.mjs exactly.
    used_anchors: set[str] = {
        "synapse-black-box-testing",
        "document-status",
        "metadata",
        "warnings",
        "computed-summary",
        "lifecycle",
        "verdict",
        "legacy-reconciliation",
        "role-groups-and-inheritance-coverage",
        "canonical-source-sections",
    }

    matrix_heading = "Use case traceability matrix"
    _anchor_fragment(matrix_heading, used_anchors)
    chunks.extend(
        [
            "",
            f"## {matrix_heading}",
            "",
            "| UC ID | Figure | Primary Actor Role | Module | Use Case | Black-Box Test Cases |",
            "| --- | --- | --- | --- | --- | --- |",
        ]
    )
    for uc in catalog["use_cases"]:
        uid = uc["id"]
        rlabel = role_by_id.get(uc["role"], {}).get("label", uc["role"])
        linked_cases = ", ".join(
            f"[{_escape_markdown_text(c.case_id)}](#case-{c.case_id.casefold()})"
            for c in cases_by_uc.get(uid, [])
        ) or "—"
        chunks.append(
            f"| `{uid}` | {_escape_table(uc.get('figure', ''))} | {_escape_table(rlabel)} | "
            f"{_escape_table(uc['module'])} | {_escape_table(uc['title'])} | {linked_cases} |"
        )

    toc_heading = "Table of contents"
    _anchor_fragment(toc_heading, used_anchors)
    provenance_heading = "Provenance"
    _anchor_fragment(provenance_heading, used_anchors)

    toc_lines: list[str] = ["", f"## {toc_heading}", ""]
    body_lines: list[str] = [
        "",
        f"## {provenance_heading}",
        "",
        "- The ordered section list, use-case catalog, case-count contract, target commit, legacy map, archive path, and output paths come from `papers/blackbox/suite.json` and `papers/blackbox/use-cases.json`.",
        f"- Validation requires traceability across all {len(suite.patch_commits)} declared patch commits and 100% bidirectional coverage across all {len(catalog['use_cases'])} Use Cases (`UC-*`).",
        "- Each test case appears exactly once under its primary **User Role -> Module -> Functionality** heading; UML role-inheritance notes explain inherited capabilities without duplicating cases.",
        "- Generated by `tools/blackbox/build.py` after `tools/blackbox/validate.py` completed successfully.",
        "- No wall-clock timestamp is emitted; identical validated inputs produce identical UTF-8 bytes.",
        "- Historical outcomes are not copied into this document. Current non-default verdicts come only from the result overlay named in Metadata.",
        "- Hash and size checks demonstrate internal consistency of supplied files; they do not independently prove execution identity, trust, or correctness.",
        "- NOT_IN_RUN means excluded from this run and is never accepted execution evidence.",
        "",
    ]

    for role_index, role in enumerate(active_roles, start=1):
        rid = role["id"]
        role_cases = cases_by_role[rid]
        role_heading_plain = f"{role_index}. {role['label']}"
        role_heading_md = f"{role_index}. {_escape_markdown_text(role['label'])}"
        role_anchor = _anchor_fragment(role_heading_plain, used_anchors)

        toc_lines.append(
            f"{role_index}. [{_escape_markdown_text(role['label'])} ({len(role_cases)})](#{role_anchor})"
        )

        inherits_raw = role.get("inherits")
        if inherits_raw:
            inh_names = ", ".join(
                role_by_id.get(part.strip(), {}).get("label", part.strip())
                for part in str(inherits_raw).split(",")
            )
            inherit_note = f"UML Inheritance: extends **{inh_names}** (inherits all prerequisite test cases from parent actor bands)."
        else:
            inherit_note = "Base actor band (no parent role inheritance)."

        body_lines.extend(
            [
                f"## {role_heading_md}",
                "",
                f"> **Role ID:** `{rid}` · **Classification:** `{role.get('kind', 'rbac')}` · **Cases:** **{len(role_cases)}**  ",
                f"> {inherit_note}  ",
                f"> **Scope:** {role.get('summary', '')}",
                "",
            ]
        )

        grouped_by_mod: dict[str, list[validate.Case]] = collections.defaultdict(list)
        for case in role_cases:
            grouped_by_mod[case.module].append(case)
        ordered_mods = sorted(
            grouped_by_mod,
            key=lambda m: (module_rank.get(m, 999), m),
        )

        for mod_name in ordered_mods:
            mod_cases = grouped_by_mod[mod_name]
            mod_heading_plain = f"{mod_name} ({len(mod_cases)} {'case' if len(mod_cases) == 1 else 'cases'})"
            mod_heading_md = _escape_markdown_text(mod_heading_plain)
            mod_anchor = _anchor_fragment(mod_heading_plain, used_anchors)

            toc_lines.append(f"   - [{mod_heading_md}](#{mod_anchor})")
            body_lines.extend([f"### {mod_heading_md}", ""])

            for case in mod_cases:
                toc_lines.append(
                    f"     - [{_escape_markdown_text(case.case_id)} — {_escape_markdown_text(case.title)}](#case-{case.case_id.casefold()})"
                )
                body_lines.extend([_replace_case_result(case, heading_prefix="#### "), ""])

    chunks.extend(toc_lines)
    chunks.extend(body_lines)

    text = "\n".join(chunks).rstrip() + "\n"
    return text.encode("utf-8")


def _resolve_output(suite: validate.SuiteData, report: validate.ValidationReport) -> Path | None:
    declared = suite.outputs.get("markdown")
    if declared != OUTPUT_PATH:
        report.error(
            f"suite.json outputs.markdown must be {OUTPUT_PATH!r}; found {declared!r}"
        )
        return None
    output = secure_io.absolute_lexical(suite.root / Path(*declared.split("/")))
    try:
        output.relative_to(suite.root)
        secure_io.verify_directory(suite.root, output.parent)
    except (ValueError, OSError, secure_io.FileSafetyError) as exc:
        report.error(f"Markdown output path is unsafe: {exc}")
        return None
    return output


def _print_validation(report: validate.ValidationReport) -> None:
    for message in report.notes:
        print(f"NOTE: {message}")
    for message in report.warnings:
        print(f"WARNING: {message}")
    for message in report.errors:
        print(f"ERROR: {message}", file=sys.stderr)


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=validate._default_root(), help="repository root")
    parser.add_argument("--results", type=Path, help="optional sanitized results JSON")
    parser.add_argument(
        "--check",
        action="store_true",
        help="compare generated bytes with the declared output without writing",
    )
    args = parser.parse_args(argv)
    suite, report = validate.validate_project(args.root, args.results)
    if suite is None or not report.ok:
        _print_validation(report)
        print(f"Build aborted: validation failed with {len(report.errors)} error(s).", file=sys.stderr)
        return 1
    output = _resolve_output(suite, report)
    if output is None or not report.ok:
        _print_validation(report)
        return 1
    expected = render_document(suite, report)
    if args.check:
        try:
            actual = secure_io.read_regular_bytes(
                output,
                root=suite.root,
                max_bytes=max(16 * 1024 * 1024, len(expected) + 1),
                require_nonempty=True,
                require_single_link=True,
            )
        except (OSError, secure_io.FileSafetyError) as exc:
            print(f"CHECK FAILED: could not safely read {output}: {exc}", file=sys.stderr)
            return 1
        if actual != expected:
            print(
                "CHECK FAILED: generated Markdown differs from validated sources "
                f"(expected sha256={hashlib.sha256(expected).hexdigest()}, "
                f"actual sha256={hashlib.sha256(actual).hexdigest()}).",
                file=sys.stderr,
            )
            return 1
        print(f"CHECK OK: {output} is deterministic and current ({len(expected)} bytes).")
        return 0
    try:
        secure_io.atomic_write_bytes(output, expected, root=suite.root)
    except (OSError, secure_io.FileSafetyError) as exc:
        print(f"Build failed while safely writing {output}: {exc}", file=sys.stderr)
        return 1
    print(
        f"Built {output} ({len(suite.cases)} cases, {len(expected)} bytes, "
        f"sha256={hashlib.sha256(expected).hexdigest()})."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
