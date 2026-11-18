#!/usr/bin/env python3
"""Build the deterministic SYNAPSE black-box Markdown document."""

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


def _anchor_fragment(value: str) -> str:
    value = value.casefold()
    value = re.sub(r"[^a-z0-9]+", "-", value).strip("-")
    return value or "section"


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
            ("Target commit", f"`{suite.target_commit}`"),
            ("Expected cases", str(validate.EXPECTED_CASE_COUNT)),
            ("Result overlay", _results_label(suite)),
            ("Archive", f"`{suite.archive_path}` ({suite.archive_state})"),
        ]
    )
    return rows


def _replace_case_result(case: validate.Case) -> str:
    """Render one case while keeping source prose and overlaying only verified results."""

    raw = case.raw.rstrip()
    raw = re.sub(r"^## ", "### ", raw, count=1, flags=re.MULTILINE)
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
        f"> **Document status: {status}.** This file is generated from ordered section sources. "
        "Do not edit generated output by hand.",
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
    chunks.extend(
        [
            f"| **Total** | **{len(suite.legacy_rows)}** |",
            "",
            "### Ordered sections",
            "",
            "| # | Section | Source | Cases | READY | DRAFT | CONDITIONAL | NOT_RUN | PASS | FAIL | BLOCKED | NOT_IN_RUN |",
            "| ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |",
        ]
    )
    for index, section in enumerate(suite.sections, start=1):
        section_lifecycle = collections.Counter(case.lifecycle for case in section.cases)
        section_verdict = collections.Counter(case.effective_verdict for case in section.cases)
        chunks.append(
            "| "
            + " | ".join(
                [
                    str(index),
                    _escape_table(section.title),
                    f"`{section.path}`",
                    str(len(section.cases)),
                    str(section_lifecycle.get("READY", 0)),
                    str(section_lifecycle.get("DRAFT", 0)),
                    str(section_lifecycle.get("CONDITIONAL", 0)),
                    str(section_verdict.get("NOT_RUN", 0)),
                    str(section_verdict.get("PASS", 0)),
                    str(section_verdict.get("FAIL", 0)),
                    str(section_verdict.get("BLOCKED", 0)),
                    str(section_verdict.get("NOT_IN_RUN", 0)),
                ]
            )
            + " |"
        )

    chunks.extend(["", "## Table of contents", ""])
    section_anchors: list[str] = []
    used_anchors: set[str] = set()
    for index, section in enumerate(suite.sections, start=1):
        base = _anchor_fragment(f"{index}-{section.title}")
        anchor = base
        suffix = 2
        while anchor in used_anchors:
            anchor = f"{base}-{suffix}"
            suffix += 1
        used_anchors.add(anchor)
        section_anchors.append(anchor)
        chunks.append(f"{index}. [{_escape_markdown_text(section.title)}](#{anchor})")
        for case in section.cases:
            chunks.append(
                f"   - [{_escape_markdown_text(case.case_id)} — "
                f"{_escape_markdown_text(case.title)}](#case-{case.case_id.casefold()})"
            )

    chunks.extend(
        [
            "",
            "## Provenance",
            "",
            "- The ordered section list, case-count contract, target commit, legacy map, archive path, and output paths come from `papers/blackbox/suite.json`.",
            f"- Validation requires traceability across exactly {len(suite.patch_commits)} declared patch commits.",
            "- Generated by `tools/blackbox/build.py` after `tools/blackbox/validate.py` completed successfully.",
            "- No wall-clock timestamp is emitted; identical validated inputs produce identical UTF-8 bytes.",
            "- Historical outcomes are not copied into this document. Current non-default verdicts come only from the result overlay named in Metadata.",
            "- Hash and size checks demonstrate internal consistency of supplied files; they do not independently prove execution identity, trust, or correctness.",
            "- NOT_IN_RUN means excluded from this run and is never accepted execution evidence.",
            "",
        ]
    )

    for index, (section, anchor) in enumerate(zip(suite.sections, section_anchors), start=1):
        chunks.extend(
            [
                f"## {index}. {_escape_markdown_text(section.title)}",
                "",
                f"Source: `{section.path}` · Cases: **{len(section.cases)}**",
                "",
            ]
        )
        for case in section.cases:
            chunks.extend([_replace_case_result(case), ""])

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
