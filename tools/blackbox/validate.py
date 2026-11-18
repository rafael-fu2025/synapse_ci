#!/usr/bin/env python3
"""Validate the deterministic SYNAPSE black-box document sources.

Only the Python standard library is used.  The module also exposes the parser
and validation result used by build.py and by the isolated unit tests.
"""

from __future__ import annotations

import argparse
import collections
import dataclasses
import datetime as _datetime
import hashlib
import json
import math
import os
import re
import stat
import subprocess
import sys
import unicodedata
from pathlib import Path, PurePosixPath
from typing import Any, Iterable, Mapping, Sequence

import secure_io

EXPECTED_CASE_COUNT = 245
EXPECTED_LEGACY_COUNT = 119
EXPECTED_LEGACY_DISPOSITIONS = {"RETAIN": 72, "REWRITE": 42, "RETIRED": 5}
EXPECTED_PATCH_COMMIT_COUNT = 14
MAX_JSON_BYTES = 4 * 1024 * 1024
MAX_JSON_DEPTH = 64
MAX_EVIDENCE_BYTES = 64 * 1024 * 1024
MAX_ARCHIVE_FILE_BYTES = 256 * 1024 * 1024
LIFECYCLES = ("READY", "DRAFT", "CONDITIONAL")
VERDICTS = ("NOT_RUN", "PASS", "FAIL", "BLOCKED", "NOT_IN_RUN")
LEGACY_DISPOSITIONS = tuple(EXPECTED_LEGACY_DISPOSITIONS)
ARCHIVE_ROLES = {"protocol", "generated", "source", "evidence", "process", "analysis"}
ARCHIVE_REDACTION_STATUSES = {"not_required", "redacted"}
KNOWN_ARCHIVE_EXPECTATIONS: dict[str, dict[str, Any]] = {
    "papers/blackbox/archive/2026-11-02": {
        "canonical_file_count": 14,
        "payload_paths": {
            "SYNAPSE-BlackBox-Testing.docx",
            "SYNAPSE-BlackBox-Testing.html",
            "SYNAPSE-BlackBox-Testing.md",
            "TEST-RESULTS-CL-GD.md",
            "TEST-RUN-PROMPT.md",
            "VERIFICATION-ANALYSIS-FAIL-BLOCKED.md",
            "results/ADMIN-RESULTS.md",
            "results/AI-INSIGHTS-RESULTS.md",
            "results/AUTH-RESULTS.md",
            "results/FACILITIES-RESULTS.md",
            "results/PORTAL-RESULTS.md",
            "results/REALTIME-RESULTS.md",
            "results/REFERRALS-RESULTS.md",
            "sample/sample-BlackBox-Testing.docx",
        },
        "run_range": {"start": "2026-11-01", "end": "2026-11-02"},
        "final_totals": {
            "total": 119,
            "pass": 90,
            "fail": 1,
            "blocked": 14,
            "not_run": 0,
            "out_of_scope": 14,
            "executed_web": 105,
        },
    }
}
WINDOWS_DEVICE_NAMES = {
    "CON",
    "PRN",
    "AUX",
    "NUL",
    *(f"COM{index}" for index in range(1, 10)),
    *(f"LPT{index}" for index in range(1, 10)),
}
CASE_ID_RE = re.compile(r"^[A-Z][A-Z0-9]*(?:-[A-Z][A-Z0-9]*)*-\d{3}$")
COMMIT_RE = re.compile(r"(?<![0-9A-Fa-f])([0-9A-Fa-f]{7,40})(?![0-9A-Fa-f])")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
HEADING_RE = re.compile(
    r"^## (?P<id>[A-Z][A-Z0-9]*(?:-[A-Z][A-Z0-9]*)*-\d{3}) — (?P<title>\S(?:.*\S)?)$"
)
LEVEL_TWO_HEADING_RE = re.compile(r"^##\s+")
BOLD_BULLET_RE = re.compile(
    r"^\s*-\s+\*\*(?P<label>[^*]+?)\*\*(?::)?\s*(?P<value>.*)$"
)
PLAIN_BULLET_RE = re.compile(
    r"^\s*-\s+(?P<label>[^:*][^:]*?):\s*(?P<value>.*)$"
)
STEP_RE = re.compile(r"^\s*(?P<number>\d+)\.\s+(?P<value>\S.*)$")
SOURCE_REFERENCE_RE = re.compile(
    r"(?<![A-Za-z0-9_./-])"
    r"(?P<path>(?:[A-Za-z0-9_.@()+-]+/)*[A-Za-z0-9_.@()+-]+\.[A-Za-z0-9]+)"
    r"(?:(?::(?P<colon_start>\d+)(?:-(?P<colon_end>\d+))?)|"
    r"(?:#L(?P<hash_start>\d+)(?:-L?(?P<hash_end>\d+))?))?"
)

PRE_STEP_FIELDS = (
    "Lifecycle",
    "Legacy IDs",
    "Changed by",
    "Requirement",
    "Surface",
    "Route/API",
    "Actor/grants",
    "Preconditions",
)
POST_STEP_FIELDS = (
    "Expected",
    "Negative/non-disclosure",
    "Cleanup",
    "Source",
    "Evidence",
    "Verdict",
)
ALL_FIELDS = PRE_STEP_FIELDS + POST_STEP_FIELDS

_NONE_LEGACY_VALUES = {
    "",
    "-",
    "—",
    "none",
    "n/a",
    "na",
    "not applicable",
    "new",
    "no legacy id",
    "no legacy ids",
}


@dataclasses.dataclass
class ValidationReport:
    errors: list[str] = dataclasses.field(default_factory=list)
    warnings: list[str] = dataclasses.field(default_factory=list)
    notes: list[str] = dataclasses.field(default_factory=list)

    def error(self, message: str) -> None:
        self.errors.append(message)

    def warning(self, message: str) -> None:
        self.warnings.append(message)

    def note(self, message: str) -> None:
        self.notes.append(message)

    @property
    def ok(self) -> bool:
        return not self.errors


@dataclasses.dataclass(frozen=True)
class SourceReference:
    path: str
    start_line: int | None = None
    end_line: int | None = None


@dataclasses.dataclass
class Case:
    case_id: str
    title: str
    fields: dict[str, str]
    steps: list[str]
    section_path: str
    section_title: str
    raw: str
    start_line: int
    legacy_ids: list[str] = dataclasses.field(default_factory=list)
    changed_by: list[str] = dataclasses.field(default_factory=list)
    source_references: list[SourceReference] = dataclasses.field(default_factory=list)
    effective_verdict: str = "NOT_RUN"
    effective_evidence: list[dict[str, Any]] = dataclasses.field(default_factory=list)
    effective_actual: str = ""

    @property
    def lifecycle(self) -> str:
        return _normalise_code_value(self.fields.get("Lifecycle", ""))

    @property
    def source_verdict(self) -> str:
        return _normalise_code_value(self.fields.get("Verdict", ""))


@dataclasses.dataclass
class Section:
    path: str
    title: str
    expected_count: int | None
    content: str
    cases: list[Case]


@dataclasses.dataclass(frozen=True)
class LegacyRow:
    legacy_id: str
    disposition: str
    current_ids: tuple[str, ...]
    reason: str = ""


@dataclasses.dataclass
class ResultEntry:
    case_id: str
    verdict: str
    actual: str
    evidence: list[dict[str, Any]]
    cleanup_status: str
    cleanup_actions: list[str]
    cleanup_notes: str


@dataclasses.dataclass
class SuiteData:
    root: Path
    suite_path: Path
    manifest: dict[str, Any]
    metadata: dict[str, Any]
    target_commit: str
    patch_commits: list[str]
    sections: list[Section]
    cases: list[Case]
    legacy_rows: list[LegacyRow]
    archive_path: str
    outputs: dict[str, str]
    results_path: Path | None = None
    result_entries: dict[str, ResultEntry] = dataclasses.field(default_factory=dict)
    archive_state: str = "not present"
    archive_verified_files: int = 0


class _Missing:
    pass


MISSING = _Missing()
_NO_DEFAULT = _Missing()


def _pick(mapping: Mapping[str, Any] | None, *names: str, default: Any = _NO_DEFAULT) -> Any:
    if mapping:
        for name in names:
            if name in mapping:
                return mapping[name]
    if default is _NO_DEFAULT:
        raise KeyError(names[0] if names else "value")
    return default


def _read_text(path: Path, report: ValidationReport, label: str) -> str | None:
    try:
        payload = secure_io.read_regular_bytes(
            path,
            root=path.parent,
            max_bytes=MAX_JSON_BYTES if path.suffix.lower() == ".json" else 16 * 1024 * 1024,
            require_nonempty=True,
        )
        return payload.decode("utf-8")
    except UnicodeDecodeError as exc:
        report.error(f"{label} is not valid UTF-8: {path}: {exc}")
    except (OSError, secure_io.FileSafetyError) as exc:
        report.error(f"could not safely read {label} {path}: {exc}")
    return None


def _load_json(path: Path, report: ValidationReport, label: str) -> Any:
    try:
        return secure_io.load_strict_json_file(
            path,
            root=path.parent,
            label=label,
            max_bytes=MAX_JSON_BYTES,
            max_depth=MAX_JSON_DEPTH,
        )
    except (OSError, secure_io.FileSafetyError, secure_io.StrictJSONError) as exc:
        report.error(f"{label} is not valid strict JSON: {path}: {exc}")
        return None


def _has_forbidden_path_characters(value: str) -> bool:
    return any(unicodedata.category(character).startswith("C") for character in value)


def _normalise_relative_path(raw: Any, report: ValidationReport, context: str) -> str | None:
    if not isinstance(raw, str) or not raw or raw != raw.strip():
        report.error(f"{context} must be a non-empty repository-relative path without outer whitespace")
        return None
    if "\\" in raw:
        report.error(f"{context} must use forward slashes only: {raw!r}")
        return None
    value = raw
    if (
        value.startswith("/")
        or value.startswith("//")
        or re.match(r"^[A-Za-z]:", value)
        or ":" in value
        or _has_forbidden_path_characters(value)
    ):
        report.error(f"{context} contains absolute, drive, ADS, or control syntax: {raw!r}")
        return None
    raw_parts = value.split("/")
    if not raw_parts or any(part in ("", ".", "..") for part in raw_parts):
        report.error(f"{context} contains an unsafe path component: {raw!r}")
        return None
    for part in raw_parts:
        if part[-1:] in (" ", "."):
            report.error(f"{context} contains a component ending in a dot or space: {raw!r}")
            return None
        stem = part.split(".", 1)[0].upper()
        if stem in WINDOWS_DEVICE_NAMES:
            report.error(f"{context} contains a reserved device component: {raw!r}")
            return None
    return PurePosixPath(*raw_parts).as_posix()


def _lexical_child(base: Path, normalised: str) -> Path:
    return secure_io.absolute_lexical(base / Path(*PurePosixPath(normalised).parts))


def _resolve_declared_path(
    root: Path,
    raw: Any,
    report: ValidationReport,
    context: str,
    *,
    suite_dir: Path | None = None,
    allow_suite_relative: bool = False,
) -> tuple[str, Path] | None:
    normalised = _normalise_relative_path(raw, report, context)
    if normalised is None:
        return None
    root = secure_io.absolute_lexical(root)
    candidate = _lexical_child(root, normalised)
    if allow_suite_relative and suite_dir is not None and not candidate.exists():
        alternate = _lexical_child(suite_dir, normalised)
        try:
            alternate.relative_to(root)
        except ValueError:
            pass
        else:
            if alternate.exists():
                candidate = alternate
                normalised = alternate.relative_to(root).as_posix()
    try:
        candidate.relative_to(root)
    except ValueError:
        report.error(f"{context} escapes the repository root: {raw!r}")
        return None
    return normalised, candidate


def _normalise_field_label(label: str) -> str:
    label = label.strip()
    if label.endswith(":"):
        label = label[:-1].rstrip()
    return label


def _normalise_code_value(value: str) -> str:
    """Remove one Markdown code span used for compact enum/commit values."""

    value = value.strip()
    if len(value) >= 2 and value.startswith("`") and value.endswith("`"):
        value = value[1:-1].strip()
    return value


def _line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def _parse_legacy_ids(value: str, report: ValidationReport, context: str) -> list[str]:
    cleaned = value.strip().replace("`", "")
    if cleaned.lower() in _NONE_LEGACY_VALUES:
        return []
    tokens = CASE_ID_RE.findall(cleaned)
    # CASE_ID_RE is anchored; use a separate scanner for a list.
    tokens = re.findall(r"(?<![A-Z0-9-])([A-Z][A-Z0-9]*(?:-[A-Z][A-Z0-9]*)*-\d{3})(?![A-Z0-9-])", cleaned)
    if not tokens:
        report.error(f"{context}: Legacy IDs must be case IDs separated by commas, or 'None'")
        return []
    remainder = cleaned
    for token in tokens:
        remainder = remainder.replace(f"`{token}`", "", 1)
        remainder = remainder.replace(token, "", 1)
    remainder = re.sub(r"[\s,;/+&()\[\]]+|\band\b", "", remainder, flags=re.IGNORECASE)
    if remainder:
        report.error(f"{context}: could not parse Legacy IDs fragment {remainder!r}")
    duplicates = [item for item, count in collections.Counter(tokens).items() if count > 1]
    if duplicates:
        report.error(f"{context}: duplicate Legacy IDs: {', '.join(sorted(duplicates))}")
    return list(dict.fromkeys(tokens))


def _parse_changed_by(value: str, report: ValidationReport, context: str) -> list[str]:
    cleaned = value.strip()
    if cleaned.casefold() == "baseline":
        return []
    commits = [match.group(1).lower() for match in COMMIT_RE.finditer(cleaned)]
    if not commits:
        report.error(
            f"{context}: Changed by must be 'baseline' or cite at least one "
            "7-40 digit hexadecimal patch commit"
        )
        return []
    remainder = cleaned
    for commit in commits:
        remainder = re.sub(
            rf"`?{re.escape(commit)}`?",
            "",
            remainder,
            count=1,
            flags=re.IGNORECASE,
        )
    remainder = re.sub(r"[\s,;/+&()\[\]]+|\band\b", "", remainder, flags=re.IGNORECASE)
    if remainder:
        report.error(f"{context}: could not parse Changed by fragment {remainder!r}")
    return list(dict.fromkeys(commits))


def _extract_source_references(value: str, report: ValidationReport, context: str) -> list[SourceReference]:
    references: list[SourceReference] = []
    seen: set[tuple[str, int | None, int | None]] = set()
    for match in SOURCE_REFERENCE_RE.finditer(value.replace("\\", "/")):
        path = match.group("path")
        # Avoid interpreting the tail of a URL or an e-mail address as a source path.
        prefix = value[max(0, match.start() - 10) : match.start()].lower()
        if "://" in prefix or path.startswith(("www.", "localhost.")):
            continue
        start_text = match.group("colon_start") or match.group("hash_start")
        end_text = match.group("colon_end") or match.group("hash_end")
        start = int(start_text) if start_text else None
        end = int(end_text) if end_text else start
        if start is not None and start < 1:
            report.error(f"{context}: source line numbers must be positive: {match.group(0)!r}")
            continue
        if end is not None and start is not None and end < start:
            report.error(f"{context}: source line range ends before it starts: {match.group(0)!r}")
            continue
        key = (path, start, end)
        if key not in seen:
            references.append(SourceReference(path, start, end))
            seen.add(key)
    if not references:
        report.error(f"{context}: Source must contain at least one repository file reference")
    return references


def parse_section(
    content: str,
    path: str,
    title: str,
    report: ValidationReport,
) -> list[Case]:
    """Parse and structurally validate one ordered section Markdown source."""

    content = content.replace("\r\n", "\n").replace("\r", "\n")
    lines = content.splitlines()
    heading_indexes: list[int] = []
    heading_matches: dict[int, re.Match[str]] = {}
    for index, line in enumerate(lines):
        match = HEADING_RE.fullmatch(line)
        if match:
            heading_indexes.append(index)
            heading_matches[index] = match
        elif LEVEL_TWO_HEADING_RE.match(line):
            report.error(
                f"{path}:{index + 1}: malformed case heading; expected '## ID — Title' with an em dash"
            )
    if not heading_indexes:
        report.error(f"{path}: no case headings were found")
        return []

    cases: list[Case] = []
    for position, start in enumerate(heading_indexes):
        end = heading_indexes[position + 1] if position + 1 < len(heading_indexes) else len(lines)
        match = heading_matches[start]
        case_id = match.group("id")
        case_title = match.group("title")
        context = f"{path}:{start + 1} ({case_id})"
        segment = lines[start + 1 : end]

        events: list[tuple[str, int, str]] = []
        fields: dict[str, str] = {}
        field_lines: dict[str, int] = {}
        steps_marker_line: int | None = None
        steps: list[str] = []
        step_numbers: list[int] = []

        for rel_index, line in enumerate(segment, start=start + 2):
            stripped = line.strip()
            if stripped == "**Steps**":
                if steps_marker_line is not None:
                    report.error(f"{path}:{rel_index}: duplicate **Steps** marker in {case_id}")
                steps_marker_line = rel_index
                events.append(("__STEPS__", rel_index, ""))
                continue
            bullet = BOLD_BULLET_RE.match(line) or PLAIN_BULLET_RE.match(line)
            if bullet:
                label = _normalise_field_label(bullet.group("label"))
                value = bullet.group("value").strip()
                if label not in ALL_FIELDS:
                    report.error(f"{path}:{rel_index}: unexpected case field {label!r} in {case_id}")
                    continue
                if label in fields:
                    report.error(f"{path}:{rel_index}: duplicate {label} field in {case_id}")
                else:
                    fields[label] = value
                    field_lines[label] = rel_index
                    if not value:
                        report.error(f"{path}:{rel_index}: {label} must have a value in {case_id}")
                events.append((label, rel_index, value))
                continue
            step = STEP_RE.match(line)
            if step:
                if steps_marker_line is None:
                    report.error(f"{path}:{rel_index}: numbered step appears before **Steps** in {case_id}")
                elif "Expected" in fields:
                    report.error(f"{path}:{rel_index}: numbered step appears after Expected in {case_id}")
                steps.append(step.group("value").strip())
                step_numbers.append(int(step.group("number")))
                continue
            if stripped.startswith("**Steps"):
                report.error(f"{path}:{rel_index}: Steps marker must be exactly '**Steps**' in {case_id}")
            elif re.match(r"^\s*-\s+\*\*", line):
                report.error(f"{path}:{rel_index}: malformed bold field bullet in {case_id}")

        expected_events = list(PRE_STEP_FIELDS) + ["__STEPS__"] + list(POST_STEP_FIELDS)
        actual_events = [event[0] for event in events]
        if actual_events != expected_events:
            expected_text = " -> ".join(expected_events).replace("__STEPS__", "Steps")
            actual_text = " -> ".join(actual_events).replace("__STEPS__", "Steps") or "(none)"
            report.error(
                f"{context}: field/order mismatch; expected {expected_text}; found {actual_text}"
            )
        for required in ALL_FIELDS:
            if required not in fields:
                report.error(f"{context}: missing required field {required}")
        if steps_marker_line is None:
            report.error(f"{context}: missing **Steps** marker")
        if len(steps) < 2:
            report.error(f"{context}: requires at least 2 numbered steps; found {len(steps)}")
        if step_numbers and step_numbers != list(range(1, len(step_numbers) + 1)):
            report.error(
                f"{context}: steps must be numbered consecutively from 1; found {step_numbers}"
            )

        lifecycle = _normalise_code_value(fields.get("Lifecycle", ""))
        if lifecycle and lifecycle not in LIFECYCLES:
            report.error(
                f"{path}:{field_lines.get('Lifecycle', start + 1)}: invalid Lifecycle {lifecycle!r}; "
                f"expected one of {', '.join(LIFECYCLES)}"
            )
        verdict = _normalise_code_value(fields.get("Verdict", ""))
        if verdict and verdict not in VERDICTS:
            report.error(
                f"{path}:{field_lines.get('Verdict', start + 1)}: invalid Verdict {verdict!r}; "
                f"expected one of {', '.join(VERDICTS)}"
            )

        legacy_ids = _parse_legacy_ids(fields.get("Legacy IDs", ""), report, context)
        changed_by = _parse_changed_by(fields.get("Changed by", ""), report, context)
        source_refs = _extract_source_references(fields.get("Source", ""), report, context)
        raw = "\n".join(lines[start:end]).rstrip() + "\n"
        cases.append(
            Case(
                case_id=case_id,
                title=case_title,
                fields=fields,
                steps=steps,
                section_path=path,
                section_title=title,
                raw=raw,
                start_line=start + 1,
                legacy_ids=legacy_ids,
                changed_by=changed_by,
                source_references=source_refs,
                effective_verdict=verdict if verdict in VERDICTS else "NOT_RUN",
            )
        )
    return cases


def _validate_schema_document(schema: Any, path: Path, report: ValidationReport) -> None:
    label = path.as_posix()
    if not isinstance(schema, dict):
        report.error(f"schema must be a JSON object: {label}")
        return
    if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        report.error(f"schema must declare JSON Schema draft 2020-12: {label}")
    if schema.get("type") != "object":
        report.error(f"schema root must have type 'object': {label}")
    if not isinstance(schema.get("properties"), dict):
        report.error(f"schema root must declare properties: {label}")
    required = schema.get("required")
    if not isinstance(required, list) or not required:
        report.error(f"schema root must declare a non-empty required array: {label}")
    elif len(required) != len(set(required)):
        report.error(f"schema required array contains duplicates: {label}")

    def walk(node: Any, pointer: str) -> None:
        if isinstance(node, dict):
            pattern = node.get("pattern")
            if pattern is not None:
                if not isinstance(pattern, str):
                    report.error(f"{label}{pointer}: pattern must be a string")
                else:
                    try:
                        re.compile(pattern)
                    except re.error as exc:
                        report.error(f"{label}{pointer}: invalid regular expression: {exc}")
            req = node.get("required")
            props = node.get("properties")
            if isinstance(req, list) and isinstance(props, dict):
                unknown = [item for item in req if item not in props]
                if unknown:
                    report.error(
                        f"{label}{pointer}: required names absent from properties: {', '.join(unknown)}"
                    )
            for key, value in node.items():
                walk(value, f"{pointer}/{key}")
        elif isinstance(node, list):
            for index, value in enumerate(node):
                walk(value, f"{pointer}/{index}")

    walk(schema, "#")


def _json_type_matches(instance: Any, expected: str) -> bool:
    return {
        "object": isinstance(instance, dict),
        "array": isinstance(instance, list),
        "string": isinstance(instance, str),
        "integer": isinstance(instance, int) and not isinstance(instance, bool),
        "number": (
            isinstance(instance, (int, float))
            and not isinstance(instance, bool)
            and (not isinstance(instance, float) or math.isfinite(instance))
        ),
        "boolean": isinstance(instance, bool),
        "null": instance is None,
    }.get(expected, True)


def _resolve_local_ref(schema_root: dict[str, Any], reference: str) -> Any:
    if not reference.startswith("#/"):
        raise ValueError(f"only local JSON Schema references are supported: {reference}")
    node: Any = schema_root
    for part in reference[2:].split("/"):
        part = part.replace("~1", "/").replace("~0", "~")
        node = node[part]
    return node


def _schema_errors(
    instance: Any,
    schema: Any,
    schema_root: dict[str, Any],
    pointer: str = "$",
) -> list[str]:
    """Validate the deliberately small JSON-Schema subset used by our schemas."""

    errors: list[str] = []
    if not isinstance(schema, dict):
        return errors
    if "$ref" in schema:
        try:
            target = _resolve_local_ref(schema_root, schema["$ref"])
        except (KeyError, TypeError, ValueError) as exc:
            return [f"{pointer}: unresolved schema reference {schema['$ref']!r}: {exc}"]
        return _schema_errors(instance, target, schema_root, pointer)
    if "allOf" in schema:
        for part in schema["allOf"]:
            errors.extend(_schema_errors(instance, part, schema_root, pointer))
    if "oneOf" in schema:
        branch_errors = [_schema_errors(instance, part, schema_root, pointer) for part in schema["oneOf"]]
        matches = sum(not branch for branch in branch_errors)
        if matches != 1:
            errors.append(f"{pointer}: must match exactly one allowed schema alternative")
            return errors
    if "anyOf" in schema:
        branch_errors = [_schema_errors(instance, part, schema_root, pointer) for part in schema["anyOf"]]
        if not any(not branch for branch in branch_errors):
            errors.append(f"{pointer}: does not match any allowed schema alternative")
            return errors

    expected_type = schema.get("type")
    if expected_type is not None:
        allowed_types = [expected_type] if isinstance(expected_type, str) else expected_type
        if not isinstance(allowed_types, list) or not any(
            isinstance(item, str) and _json_type_matches(instance, item) for item in allowed_types
        ):
            errors.append(f"{pointer}: expected type {expected_type!r}")
            return errors
    if "const" in schema and instance != schema["const"]:
        errors.append(f"{pointer}: expected constant value {schema['const']!r}")
    if "enum" in schema and instance not in schema["enum"]:
        errors.append(f"{pointer}: value {instance!r} is not in the allowed enum")

    if isinstance(instance, dict):
        required = schema.get("required", [])
        if isinstance(required, list):
            for name in required:
                if name not in instance:
                    errors.append(f"{pointer}: missing required property {name!r}")
        properties = schema.get("properties", {})
        if isinstance(properties, dict):
            for name, value in instance.items():
                if name in properties:
                    errors.extend(_schema_errors(value, properties[name], schema_root, f"{pointer}.{name}"))
                else:
                    additional = schema.get("additionalProperties", True)
                    if additional is False:
                        errors.append(f"{pointer}: unexpected property {name!r}")
                    elif isinstance(additional, dict):
                        errors.extend(_schema_errors(value, additional, schema_root, f"{pointer}.{name}"))
        min_properties = schema.get("minProperties")
        max_properties = schema.get("maxProperties")
        if isinstance(min_properties, int) and len(instance) < min_properties:
            errors.append(f"{pointer}: requires at least {min_properties} properties")
        if isinstance(max_properties, int) and len(instance) > max_properties:
            errors.append(f"{pointer}: permits at most {max_properties} properties")
        property_names = schema.get("propertyNames")
        if isinstance(property_names, dict):
            for name in instance:
                errors.extend(_schema_errors(name, property_names, schema_root, f"{pointer}.<property-name>"))
    elif isinstance(instance, list):
        min_items = schema.get("minItems")
        max_items = schema.get("maxItems")
        if isinstance(min_items, int) and len(instance) < min_items:
            errors.append(f"{pointer}: requires at least {min_items} items")
        if isinstance(max_items, int) and len(instance) > max_items:
            errors.append(f"{pointer}: permits at most {max_items} items")
        if schema.get("uniqueItems"):
            encoded = [json.dumps(item, sort_keys=True, separators=(",", ":")) for item in instance]
            if len(encoded) != len(set(encoded)):
                errors.append(f"{pointer}: array items must be unique")
        item_schema = schema.get("items")
        if isinstance(item_schema, dict):
            for index, value in enumerate(instance):
                errors.extend(_schema_errors(value, item_schema, schema_root, f"{pointer}[{index}]"))
    elif isinstance(instance, str):
        min_length = schema.get("minLength")
        max_length = schema.get("maxLength")
        if isinstance(min_length, int) and len(instance) < min_length:
            errors.append(f"{pointer}: string is shorter than {min_length} characters")
        if isinstance(max_length, int) and len(instance) > max_length:
            errors.append(f"{pointer}: string is longer than {max_length} characters")
        pattern = schema.get("pattern")
        if isinstance(pattern, str) and re.search(pattern, instance) is None:
            errors.append(f"{pointer}: string does not match required pattern {pattern!r}")
        fmt = schema.get("format")
        if fmt == "date-time":
            try:
                parsed = _datetime.datetime.fromisoformat(instance.replace("Z", "+00:00"))
                if parsed.tzinfo is None:
                    raise ValueError("timezone offset is required")
            except ValueError:
                errors.append(f"{pointer}: invalid RFC 3339 date-time")
        elif fmt == "uri":
            if not re.match(r"^[A-Za-z][A-Za-z0-9+.-]*://\S+$", instance):
                errors.append(f"{pointer}: invalid absolute URI")
    elif isinstance(instance, (int, float)) and not isinstance(instance, bool):
        minimum = schema.get("minimum")
        maximum = schema.get("maximum")
        if isinstance(minimum, (int, float)) and instance < minimum:
            errors.append(f"{pointer}: must be at least {minimum}")
        if isinstance(maximum, (int, float)) and instance > maximum:
            errors.append(f"{pointer}: must be at most {maximum}")

    condition = schema.get("if")
    if isinstance(condition, dict) and not _schema_errors(instance, condition, schema_root, pointer):
        then = schema.get("then")
        if isinstance(then, dict):
            errors.extend(_schema_errors(instance, then, schema_root, pointer))
    elif isinstance(condition, dict):
        otherwise = schema.get("else")
        if isinstance(otherwise, dict):
            errors.extend(_schema_errors(instance, otherwise, schema_root, pointer))
    return errors


def _sha256_file(path: Path) -> str:
    digest, _ = secure_io.hash_regular_file(
        path,
        root=path.parent,
        max_bytes=MAX_ARCHIVE_FILE_BYTES,
        require_nonempty=True,
    )
    return digest


def _schema_paths(root: Path) -> dict[str, Path]:
    base = root / "papers" / "blackbox" / "schema"
    return {
        "run manifest": base / "run-manifest.schema.json",
        "results": base / "results.schema.json",
    }


def _load_and_validate_schemas(root: Path, report: ValidationReport) -> dict[str, dict[str, Any]]:
    schemas: dict[str, dict[str, Any]] = {}
    for label, path in _schema_paths(root).items():
        loaded = _load_json(path, report, f"{label} schema")
        if loaded is not None:
            _validate_schema_document(loaded, path, report)
            if isinstance(loaded, dict):
                schemas[label] = loaded
    return schemas


def _section_declarations(manifest: dict[str, Any], report: ValidationReport) -> list[dict[str, Any]]:
    raw_sections = _pick(manifest, "sections", "section_files", default=None)
    if not isinstance(raw_sections, list) or not raw_sections:
        report.error("suite.json section_files/sections must be a non-empty ordered array")
        return []
    declarations: list[dict[str, Any]] = []
    seen_paths: set[str] = set()
    for index, entry in enumerate(raw_sections):
        context = f"suite.json sections[{index}]"
        if isinstance(entry, str):
            declaration = {
                "file": entry,
                "title": Path(entry).stem,
                "expected_count": None,
                "title_is_default": True,
            }
        elif isinstance(entry, dict):
            raw_file = _pick(entry, "file", "path", default=None)
            raw_title = _pick(entry, "title", "name", default=None)
            raw_count = _pick(entry, "expected_count", "count", "case_count", default=None)
            declaration = {
                "file": raw_file,
                "title": raw_title,
                "expected_count": raw_count,
                "title_is_default": False,
            }
        else:
            report.error(f"{context} must be a path string or object")
            continue
        if not isinstance(declaration["file"], str) or not declaration["file"].strip():
            report.error(f"{context} must declare file/path")
            continue
        if not isinstance(declaration["title"], str) or not declaration["title"].strip():
            report.error(f"{context} must declare a non-empty title/name")
        count = declaration["expected_count"]
        if count is not None and (not isinstance(count, int) or isinstance(count, bool) or count < 1):
            report.error(f"{context} expected_count/count must be a positive integer when present")
        path_key = declaration["file"].replace("\\", "/")
        if path_key in seen_paths:
            report.error(f"{context} duplicates section path {declaration['file']!r}")
        seen_paths.add(path_key)
        declarations.append(declaration)
    return declarations


def _commits_from_traceability(root: Path, report: ValidationReport) -> list[str]:
    path = root / "papers" / "blackbox" / "TRACEABILITY.md"
    if not path.is_file():
        return []
    text = _read_text(path, report, "traceability map")
    if text is None:
        return []
    commits: list[str] = []
    for line in text.splitlines():
        match = re.match(r"^\|\s*`([0-9A-Fa-f]{7,40})`\s*\|", line)
        if match:
            commits.append(match.group(1).lower())
    return commits


def _normalise_commit_list(
    root: Path, manifest: dict[str, Any], report: ValidationReport
) -> list[str]:
    metadata = manifest.get("metadata") if isinstance(manifest.get("metadata"), dict) else {}
    traceability = manifest.get("traceability") if isinstance(manifest.get("traceability"), dict) else {}
    raw = _pick(manifest, "patch_commits", default=MISSING)
    if raw is MISSING:
        raw = _pick(traceability, "patch_commits", "commits", default=MISSING)
    if raw is MISSING:
        raw = _pick(metadata, "patch_commits", default=MISSING)
    if raw is MISSING:
        mapped = _commits_from_traceability(root, report)
        if mapped:
            raw = mapped
    if raw is MISSING:
        raw = None
    if not isinstance(raw, list):
        report.error(
            "suite.json patch_commits or papers/blackbox/TRACEABILITY.md must declare "
            "the ordered patch inventory"
        )
        return []
    commits: list[str] = []
    for index, entry in enumerate(raw):
        if isinstance(entry, str):
            value = entry
        elif isinstance(entry, dict):
            value = _pick(entry, "commit", "sha", "hash", default=None)
        else:
            value = None
        if not isinstance(value, str) or not re.fullmatch(r"[0-9A-Fa-f]{7,40}", value.strip()):
            report.error(f"suite.json patch_commits[{index}] must contain a 7-40 digit hexadecimal commit")
            continue
        commits.append(value.strip().lower())
    duplicates = [value for value, count in collections.Counter(commits).items() if count > 1]
    if duplicates:
        report.error(f"suite.json patch_commits contains duplicates: {', '.join(sorted(duplicates))}")
    if len(commits) != EXPECTED_PATCH_COMMIT_COUNT:
        report.error(
            f"suite.json must declare exactly {EXPECTED_PATCH_COMMIT_COUNT} patch commits; found {len(commits)}"
        )
    # Reject ambiguous abbreviations because Changed by resolution would not be deterministic.
    for index, left in enumerate(commits):
        for right in commits[index + 1 :]:
            if left.startswith(right) or right.startswith(left):
                report.error(f"suite.json patch commit prefixes are ambiguous: {left} and {right}")
    return commits


def _expand_id_expression(value: str) -> list[str]:
    """Expand IDs and compact same-prefix ranges such as GD-INT-001..GD-INT-003."""

    expression = value.strip().replace("`", "")
    token_re = re.compile(
        r"(?<![A-Z0-9-])"
        r"(?P<start>[A-Z][A-Z0-9]*(?:-[A-Z][A-Z0-9]*)*-(?P<number>\d{3}))"
        r"(?:\.\.(?:(?P<prefix>[A-Z][A-Z0-9]*(?:-[A-Z][A-Z0-9]*)*-)?(?P<end>\d{3}))?)?"
        r"(?![A-Z0-9-])"
    )
    values: list[str] = []
    for match in token_re.finditer(expression):
        start = match.group("start")
        end_text = match.group("end")
        if end_text is None:
            values.append(start)
            continue
        start_prefix, start_number_text = start.rsplit("-", 1)
        end_prefix = (match.group("prefix") or (start_prefix + "-")).rstrip("-")
        if end_prefix != start_prefix:
            values.extend((start, f"{end_prefix}-{end_text}"))
            continue
        start_number = int(start_number_text)
        end_number = int(end_text)
        if end_number < start_number or end_number - start_number > 999:
            values.extend((start, f"{end_prefix}-{end_text}"))
            continue
        values.extend(f"{start_prefix}-{number:03d}" for number in range(start_number, end_number + 1))
    return values


def _normalise_current_ids(value: Any, report: ValidationReport, context: str) -> tuple[str, ...]:
    if value is None or value == "":
        return ()
    if isinstance(value, str):
        if value.strip().lower() in _NONE_LEGACY_VALUES:
            return ()
        values = _expand_id_expression(value)
    elif isinstance(value, list):
        values = value
    else:
        report.error(f"{context} current_ids must be an array, one ID string, or null")
        return ()
    normalised: list[str] = []
    for item in values:
        if not isinstance(item, str) or not CASE_ID_RE.fullmatch(item):
            report.error(f"{context} has invalid current case ID {item!r}")
        else:
            normalised.append(item)
    duplicates = [item for item, count in collections.Counter(normalised).items() if count > 1]
    if duplicates:
        report.error(f"{context} has duplicate current IDs: {', '.join(sorted(duplicates))}")
    return tuple(dict.fromkeys(normalised))


def _legacy_rows_from_json(value: Any, report: ValidationReport, context: str) -> list[LegacyRow]:
    if isinstance(value, dict):
        value = _pick(value, "cases", "rows", "entries", "map", default=None)
    if not isinstance(value, list):
        report.error(f"{context} must be an array of legacy map rows")
        return []
    rows: list[LegacyRow] = []
    for index, entry in enumerate(value):
        row_context = f"{context}[{index}]"
        if not isinstance(entry, dict):
            report.error(f"{row_context} must be an object")
            continue
        legacy_id = _pick(entry, "legacy_id", "id", "old_id", default=None)
        disposition = entry.get("disposition")
        current_value = _pick(
            entry,
            "current_ids",
            "current_id",
            "replacement_ids",
            "new_ids",
            default=None,
        )
        reason = _pick(entry, "reason", "notes", "note", default="")
        if not isinstance(legacy_id, str) or not CASE_ID_RE.fullmatch(legacy_id):
            report.error(f"{row_context} has invalid legacy ID {legacy_id!r}")
            continue
        if disposition not in LEGACY_DISPOSITIONS:
            report.error(
                f"{row_context} has invalid disposition {disposition!r}; expected "
                f"{', '.join(LEGACY_DISPOSITIONS)}"
            )
        current_ids = _normalise_current_ids(current_value, report, row_context)
        if reason is not None and not isinstance(reason, str):
            report.error(f"{row_context} reason/notes must be a string when present")
            reason = ""
        rows.append(LegacyRow(legacy_id, str(disposition), current_ids, reason or ""))
    return rows


def _parse_legacy_markdown(text: str, report: ValidationReport, context: str) -> list[LegacyRow]:
    rows: list[LegacyRow] = []
    for line_number, line in enumerate(text.splitlines(), start=1):
        stripped = line.strip()
        if not stripped.startswith("|") or stripped.count("|") < 4:
            continue
        cells = [cell.strip().strip("`") for cell in stripped.strip("|").split("|")]
        if not cells or not CASE_ID_RE.fullmatch(cells[0]):
            continue
        disposition = cells[1] if len(cells) > 1 else ""
        current = cells[2] if len(cells) > 2 else ""
        reason = cells[3] if len(cells) > 3 else ""
        current_ids = _normalise_current_ids(current, report, f"{context}:{line_number}")
        rows.append(LegacyRow(cells[0], disposition, current_ids, reason))
    if not rows:
        report.error(f"{context} contains no parseable legacy Markdown table rows")
    return rows


def _load_legacy_rows(
    root: Path,
    suite_dir: Path,
    manifest: dict[str, Any],
    report: ValidationReport,
) -> tuple[list[LegacyRow], dict[str, Any]]:
    legacy_config = manifest.get("legacy") if isinstance(manifest.get("legacy"), dict) else {}
    raw_map: Any = _pick(legacy_config, "cases", "rows", "entries", "map", "file", default=MISSING)
    if raw_map is MISSING:
        raw_map = _pick(manifest, "legacy_map", "legacy_map_path", default=MISSING)
    if raw_map is MISSING:
        canonical = suite_dir / "legacy-0383939-map.md"
        if canonical.is_file():
            raw_map = canonical.relative_to(root).as_posix()
    if raw_map is MISSING or raw_map is None:
        report.error("suite.json must declare the complete legacy map")
        return [], legacy_config
    if isinstance(raw_map, str):
        resolved = _resolve_declared_path(
            root,
            raw_map,
            report,
            "legacy map path",
            suite_dir=suite_dir,
            allow_suite_relative=True,
        )
        if resolved is None:
            return [], legacy_config
        normalised, path = resolved
        if path.suffix.lower() == ".json":
            loaded = _load_json(path, report, "legacy map")
            rows = _legacy_rows_from_json(loaded, report, normalised) if loaded is not None else []
        elif path.suffix.lower() in (".md", ".markdown"):
            text = _read_text(path, report, "legacy map")
            rows = _parse_legacy_markdown(text or "", report, normalised) if text is not None else []
        else:
            report.error(f"legacy map must be inline JSON, a .json file, or a Markdown table: {normalised}")
            rows = []
    else:
        rows = _legacy_rows_from_json(raw_map, report, "suite.json legacy map")
    return rows, legacy_config


def _declared_legacy_counts(
    manifest: dict[str, Any], legacy_config: dict[str, Any], report: ValidationReport
) -> tuple[int | None, dict[str, int] | None]:
    total = _pick(legacy_config, "expected_count", "count", "total", default=MISSING)
    counts = _pick(legacy_config, "disposition_counts", "counts", default=MISSING)
    top_level_counts = manifest.get("legacy_counts")
    if total is MISSING:
        total = _pick(manifest, "legacy_expected_count", default=MISSING)
    if total is MISSING and isinstance(top_level_counts, dict):
        total = _pick(top_level_counts, "historical_total", "total", default=MISSING)
    if counts is MISSING:
        if isinstance(top_level_counts, dict) and all(
            disposition in top_level_counts for disposition in LEGACY_DISPOSITIONS
        ):
            counts = top_level_counts
        elif isinstance(top_level_counts, dict):
            # The suite's source-centric aliases state the same reconciliation:
            # surviving RETAIN + REWRITE rows and historical retired rows.
            counts = {
                "RETAIN": top_level_counts.get("current_with_legacy_id"),
                "REWRITE": top_level_counts.get("current_without_legacy_id"),
                "RETIRED": top_level_counts.get("historical_retired"),
            }
        else:
            counts = None
    if total is MISSING:
        total = None
    if total is None and isinstance(counts, dict):
        total_candidate = counts.get("total")
        if isinstance(total_candidate, int):
            total = total_candidate
    if total is None:
        report.error("suite.json must declare legacy expected_count/total")
    elif not isinstance(total, int) or isinstance(total, bool):
        report.error("suite.json legacy expected_count/total must be an integer")
        total = None
    if not isinstance(counts, dict):
        report.error("suite.json must declare legacy disposition counts")
        normalised_counts = None
    else:
        normalised_counts = {}
        for disposition in LEGACY_DISPOSITIONS:
            value = counts.get(disposition)
            if not isinstance(value, int) or isinstance(value, bool):
                report.error(f"suite.json legacy count for {disposition} must be an integer")
            else:
                normalised_counts[disposition] = value
    return total, normalised_counts


def _resolve_commit(reference: str, declared: Sequence[str]) -> str | None:
    matches = [commit for commit in declared if commit.startswith(reference) or reference.startswith(commit)]
    return matches[0] if len(matches) == 1 else None


def _git_commit_exists(root: Path, reference: str) -> bool:
    if not (root / ".git").exists():
        return False
    try:
        completed = subprocess.run(
            ["git", "-C", str(root), "cat-file", "-e", f"{reference}^{{commit}}"],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=15,
        )
    except (OSError, subprocess.SubprocessError):
        return False
    return completed.returncode == 0


def _validate_traceability_map(
    root: Path,
    declared: Sequence[str],
    known_case_ids: set[str],
    report: ValidationReport,
) -> set[str]:
    """Validate optional authoritative TRACEABILITY.md rows and case references."""

    path = root / "papers" / "blackbox" / "TRACEABILITY.md"
    if not path.is_file():
        return set()
    text = _read_text(path, report, "traceability map")
    if text is None:
        return set()
    rows: list[tuple[str, str, int]] = []
    for line_number, line in enumerate(text.splitlines(), start=1):
        match = re.match(
            r"^\|\s*`(?P<commit>[0-9A-Fa-f]{7,40})`\s*\|(?P<classification>.*?)\|(?P<cases>.*?)\|\s*$",
            line,
        )
        if match:
            rows.append((match.group("commit").lower(), match.group("cases").strip(), line_number))
    row_commits = [row[0] for row in rows]
    duplicates = [commit for commit, count in collections.Counter(row_commits).items() if count > 1]
    if duplicates:
        report.error(f"TRACEABILITY.md has duplicate patch rows: {', '.join(sorted(duplicates))}")
    if len(rows) != EXPECTED_PATCH_COMMIT_COUNT:
        report.error(
            f"TRACEABILITY.md must contain exactly {EXPECTED_PATCH_COMMIT_COUNT} patch rows; "
            f"found {len(rows)}"
        )
    resolved_rows: set[str] = set()
    for commit, cases_cell, line_number in rows:
        resolved = _resolve_commit(commit, declared)
        if resolved is None:
            report.error(
                f"TRACEABILITY.md:{line_number}: patch {commit} is not an unambiguous declared patch commit"
            )
            continue
        resolved_rows.add(resolved)
        if cases_cell.casefold().startswith("infrastructure-only"):
            continue
        cited = _expand_id_expression(cases_cell.replace(" through ", ".."))
        known_cited = [case_id for case_id in cited if case_id in known_case_ids]
        unknown_cited = sorted(set(cited) - known_case_ids)
        if unknown_cited:
            report.error(
                f"TRACEABILITY.md:{line_number}: references unknown current case IDs: "
                f"{', '.join(unknown_cited)}"
            )
        if not known_cited:
            report.error(
                f"TRACEABILITY.md:{line_number}: non-infrastructure patch {commit} must cite "
                "at least one current case ID"
            )
    missing_rows = [commit for commit in declared if commit not in resolved_rows]
    if missing_rows:
        report.error(f"patch commits missing from TRACEABILITY.md: {', '.join(missing_rows)}")
    return resolved_rows


def _validate_git_commits(root: Path, commits: Iterable[str], report: ValidationReport) -> None:
    git_marker = root / ".git"
    if not git_marker.exists():
        report.warning("repository root is not a Git worktree; commit object existence was not checked")
        return
    for commit in commits:
        try:
            completed = subprocess.run(
                ["git", "-C", str(root), "cat-file", "-e", f"{commit}^{{commit}}"],
                check=False,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                text=True,
                timeout=15,
            )
        except (OSError, subprocess.SubprocessError) as exc:
            report.error(f"could not validate Git commit {commit}: {exc}")
            return
        if completed.returncode != 0:
            message = completed.stderr.strip() or "object does not exist"
            report.error(f"declared Git commit {commit} is unavailable: {message}")


def _validate_source_references(root: Path, cases: Sequence[Case], report: ValidationReport) -> None:
    for case in cases:
        for reference in case.source_references:
            resolved = _resolve_declared_path(
                root,
                reference.path,
                report,
                f"{case.section_path}:{case.start_line} ({case.case_id}) Source",
            )
            if resolved is None:
                continue
            normalised, path = resolved
            if not path.is_file():
                report.error(
                    f"{case.section_path}:{case.start_line} ({case.case_id}): "
                    f"Source path does not exist as a file: {normalised}"
                )


def _forbidden_secret_keys(value: Any, pointer: str = "$") -> list[str]:
    forbidden = {
        "password",
        "passwd",
        "secret",
        "cookie",
        "authorization",
        "credential",
        "credentials",
        "access_token",
        "refresh_token",
        "api_key",
        "private_key",
    }
    findings: list[str] = []
    if isinstance(value, dict):
        for key, child in value.items():
            normalised = str(key).strip().lower().replace("-", "_").replace(" ", "_")
            if normalised in forbidden:
                findings.append(f"{pointer}.{key}")
            findings.extend(_forbidden_secret_keys(child, f"{pointer}.{key}"))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            findings.extend(_forbidden_secret_keys(child, f"{pointer}[{index}]"))
    return findings


_MARKDOWN_RESOURCE_RE = re.compile(
    r"(?:!\[[^\]\r\n]*\]\s*\(|\[[^\]\r\n]+\]\s*\(|<\s*(?:https?|ftp|file|data|javascript|vbscript|mailto):|"
    r"\b(?:https?|ftp|file|data|javascript|vbscript):\s*//|"
    r"\b(?:javascript|vbscript|data|file):)",
    flags=re.IGNORECASE,
)
_RAW_HTML_RE = re.compile(r"<\s*/?\s*[A-Za-z!][^>]*>")


def _validate_plain_text(
    value: Any,
    report: ValidationReport,
    context: str,
    *,
    minimum: int = 0,
    maximum: int = 4096,
) -> str | None:
    if not isinstance(value, str):
        report.error(f"{context} must be plain text")
        return None
    if len(value) < minimum or len(value) > maximum:
        report.error(f"{context} must be between {minimum} and {maximum} characters")
    if value != value.strip() and value:
        report.error(f"{context} must not have leading or trailing whitespace")
    if any(unicodedata.category(character).startswith("C") for character in value):
        report.error(f"{context} must be one line and contain no control or formatting characters")
    if _RAW_HTML_RE.search(value):
        report.error(f"{context} must not contain raw HTML")
    if _MARKDOWN_RESOURCE_RE.search(value):
        report.error(f"{context} must not contain Markdown resources, autolinks, or URL schemes")
    return value


def _results_component_path(raw: Any, report: ValidationReport, context: str) -> str | None:
    return _normalise_relative_path(raw, report, context)


def _safe_run_file(
    run_root: Path,
    raw: Any,
    report: ValidationReport,
    context: str,
    *,
    max_bytes: int,
    require_nonempty: bool = True,
) -> tuple[str, Path, os.stat_result] | None:
    normalised = _results_component_path(raw, report, context)
    if normalised is None:
        return None
    candidate = _lexical_child(run_root, normalised)
    try:
        value = secure_io.inspect_regular_file(
            candidate,
            root=run_root,
            require_nonempty=require_nonempty,
            require_single_link=True,
        )
    except (OSError, secure_io.FileSafetyError) as exc:
        report.error(f"{context} is not a safe contained regular file: {normalised!r}: {exc}")
        return None
    assert value is not None
    if value.st_size > max_bytes:
        report.error(f"{context} exceeds the {max_bytes}-byte limit: {normalised!r}")
        return None
    return normalised, candidate, value


def _safe_hash_run_file(
    run_root: Path,
    raw: Any,
    report: ValidationReport,
    context: str,
    *,
    max_bytes: int = MAX_EVIDENCE_BYTES,
) -> tuple[str, Path, str, int] | None:
    resolved = _safe_run_file(
        run_root,
        raw,
        report,
        context,
        max_bytes=max_bytes,
        require_nonempty=True,
    )
    if resolved is None:
        return None
    normalised, candidate, _ = resolved
    try:
        digest, size = secure_io.hash_regular_file(
            candidate,
            root=run_root,
            max_bytes=max_bytes,
            require_nonempty=True,
            require_single_link=True,
        )
    except (OSError, secure_io.FileSafetyError) as exc:
        report.error(f"{context} could not be safely hashed: {normalised!r}: {exc}")
        return None
    return normalised, candidate, digest, size


def _load_run_json(
    run_root: Path,
    raw: Any,
    report: ValidationReport,
    label: str,
) -> tuple[str, Path, dict[str, Any] | None]:
    resolved = _safe_run_file(
        run_root,
        raw,
        report,
        label,
        max_bytes=MAX_JSON_BYTES,
        require_nonempty=True,
    )
    if resolved is None:
        return "", run_root, None
    normalised, path, _ = resolved
    try:
        payload = secure_io.read_regular_bytes(
            path,
            root=run_root,
            max_bytes=MAX_JSON_BYTES,
            require_nonempty=True,
            require_single_link=True,
        )
        loaded = secure_io.strict_json_loads(
            payload,
            label=label,
            max_bytes=MAX_JSON_BYTES,
            max_depth=MAX_JSON_DEPTH,
        )
    except (OSError, secure_io.FileSafetyError, secure_io.StrictJSONError) as exc:
        report.error(f"{label} is not valid strict JSON: {normalised}: {exc}")
        return normalised, path, None
    if not isinstance(loaded, dict):
        report.error(f"{label} must contain a JSON object")
        return normalised, path, None
    return normalised, path, loaded


def _validate_run_user_text(run_manifest: dict[str, Any], report: ValidationReport) -> None:
    executed_by = run_manifest.get("executed_by")
    if isinstance(executed_by, dict):
        _validate_plain_text(
            executed_by.get("id"), report, "run manifest executed_by.id", minimum=1, maximum=128
        )
        _validate_plain_text(
            executed_by.get("role"), report, "run manifest executed_by.role", minimum=1, maximum=128
        )
    for index, migration in enumerate(run_manifest.get("migrations", [])):
        if isinstance(migration, dict):
            _validate_plain_text(
                migration.get("name"),
                report,
                f"run manifest migrations[{index}].name",
                minimum=1,
                maximum=256,
            )
            _validate_plain_text(
                migration.get("version"),
                report,
                f"run manifest migrations[{index}].version",
                minimum=1,
                maximum=128,
            )
    flags = run_manifest.get("flags")
    if isinstance(flags, dict):
        for name, value in flags.items():
            if isinstance(value, str):
                _validate_plain_text(value, report, f"run manifest flags.{name}", maximum=512)
    environment = run_manifest.get("environment")
    if isinstance(environment, dict):
        for name, maximum in (
            ("app_environment", 128),
            ("database_engine", 128),
            ("server_os", 256),
        ):
            _validate_plain_text(
                environment.get(name),
                report,
                f"run manifest environment.{name}",
                minimum=1,
                maximum=maximum,
            )
        versions = environment.get("runtime_versions")
        if isinstance(versions, dict):
            for name, value in versions.items():
                _validate_plain_text(
                    value,
                    report,
                    f"run manifest environment.runtime_versions.{name}",
                    minimum=1,
                    maximum=128,
                )
        base_url = environment.get("base_url")
        if isinstance(base_url, str):
            from urllib.parse import urlsplit

            try:
                parsed = urlsplit(base_url)
                if (
                    parsed.scheme not in ("http", "https")
                    or not parsed.hostname
                    or parsed.username
                    or parsed.password
                ):
                    raise ValueError
            except ValueError:
                report.error(
                    "run manifest environment.base_url must be an HTTP(S) URL without user information"
                )
    browser = run_manifest.get("browser_device")
    if isinstance(browser, dict):
        for name, maximum in (
            ("browser", 128),
            ("browser_version", 128),
            ("device", 256),
            ("automation", 128),
        ):
            _validate_plain_text(
                browser.get(name),
                report,
                f"run manifest browser_device.{name}",
                minimum=1,
                maximum=maximum,
            )
    cleanup = run_manifest.get("cleanup")
    if isinstance(cleanup, dict):
        for index, action in enumerate(cleanup.get("actions", [])):
            _validate_plain_text(
                action,
                report,
                f"run manifest cleanup.actions[{index}]",
                minimum=1,
                maximum=512,
            )
        _validate_plain_text(
            cleanup.get("notes"), report, "run manifest cleanup.notes", maximum=2048
        )
    attestation = run_manifest.get("attestation")
    if isinstance(attestation, dict):
        _validate_plain_text(
            attestation.get("signer"),
            report,
            "run manifest attestation.signer",
            minimum=1,
            maximum=256,
        )
        _validate_plain_text(
            attestation.get("trust_policy"),
            report,
            "run manifest attestation.trust_policy",
            minimum=1,
            maximum=512,
        )


def _load_results(
    root: Path,
    results_path: Path,
    suite: SuiteData,
    schemas: dict[str, dict[str, Any]],
    report: ValidationReport,
) -> dict[str, ResultEntry]:
    del root  # Evidence is intentionally resolved only beneath this one run root.
    results_path = secure_io.absolute_lexical(results_path)
    run_root = results_path.parent
    try:
        secure_io.verify_absolute_directory(run_root)
        secure_io.verify_directory(run_root, run_root)
        secure_io.inspect_regular_file(
            results_path,
            root=run_root,
            require_nonempty=True,
            require_single_link=True,
        )
        payload = secure_io.read_regular_bytes(
            results_path,
            root=run_root,
            max_bytes=MAX_JSON_BYTES,
            require_nonempty=True,
            require_single_link=True,
        )
        loaded = secure_io.strict_json_loads(
            payload,
            label="sanitized results file",
            max_bytes=MAX_JSON_BYTES,
            max_depth=MAX_JSON_DEPTH,
        )
    except (OSError, secure_io.FileSafetyError, secure_io.StrictJSONError) as exc:
        report.error(f"sanitized results file is not a safe strict-JSON file: {exc}")
        return {}
    if not isinstance(loaded, dict):
        report.error("sanitized results file must contain a JSON object")
        return {}

    schema = schemas.get("results")
    if schema:
        for message in _schema_errors(loaded, schema, schema):
            report.error(f"results schema: {message}")
    if loaded.get("sanitized") is not True:
        report.error("results file must explicitly declare sanitized: true")
    for pointer in _forbidden_secret_keys(loaded):
        report.error(f"results file contains a forbidden secret-bearing key: {pointer}")

    target = loaded.get("suite_target_commit")
    if isinstance(target, str) and target.lower() != suite.target_commit.lower():
        report.error(
            f"results suite_target_commit {target} does not match suite target_commit {suite.target_commit}"
        )

    manifest_raw = loaded.get("run_manifest")
    manifest_name = _results_component_path(
        manifest_raw, report, "results run_manifest path"
    )
    if manifest_name is not None and manifest_name.casefold() == results_path.name.casefold():
        report.error("results run_manifest must not alias the results file")
    manifest_path: Path | None = None
    run_manifest: dict[str, Any] | None = None
    if manifest_name is not None:
        _, manifest_path, run_manifest = _load_run_json(
            run_root, manifest_name, report, "run manifest"
        )
        declared_hash = loaded.get("run_manifest_sha256")
        if run_manifest is not None and isinstance(declared_hash, str):
            hashed = _safe_hash_run_file(
                run_root,
                manifest_name,
                report,
                "run manifest",
                max_bytes=MAX_JSON_BYTES,
            )
            if hashed is not None and declared_hash != hashed[2]:
                report.error(
                    "run manifest hash mismatch; the declared digest does not match the contained file"
                )
    else:
        report.error(
            "results file must declare run_manifest as a safe run-root-relative JSON path"
        )

    artifacts: dict[str, dict[str, Any]] = {}
    artifact_actual: dict[str, tuple[str, int]] = {}
    run_dirty = False
    if run_manifest is not None:
        schema = schemas.get("run manifest")
        if schema:
            for message in _schema_errors(run_manifest, schema, schema):
                report.error(f"run manifest schema: {message}")
        if run_manifest.get("sanitized") is not True:
            report.error("run manifest must explicitly declare sanitized: true")
        for pointer in _forbidden_secret_keys(run_manifest):
            report.error(f"run manifest contains a forbidden secret-bearing key: {pointer}")
        if str(run_manifest.get("suite_target_commit", "")).lower() != suite.target_commit.lower():
            report.error("run manifest suite_target_commit does not match suite target_commit")
        if str(run_manifest.get("tested_commit", "")).lower() != suite.target_commit.lower():
            report.error("run manifest tested_commit must match the suite target_commit")
        if run_manifest.get("run_id") != loaded.get("run_id"):
            report.error("results run_id does not match run manifest run_id")
        _validate_run_user_text(run_manifest, report)
        run_dirty = run_manifest.get("dirty") is True

        cleanup = run_manifest.get("cleanup")
        if isinstance(cleanup, dict):
            cleanup_status = cleanup.get("status")
            actions = cleanup.get("actions")
            notes = cleanup.get("notes")
            if cleanup_status not in ("COMPLETED", "NOT_REQUIRED"):
                report.error("run manifest cleanup status must be COMPLETED or NOT_REQUIRED")
            if cleanup_status == "COMPLETED" and (
                not isinstance(actions, list) or not actions
            ):
                report.error(
                    "run manifest cleanup COMPLETED requires at least one action"
                )
            if cleanup_status == "NOT_REQUIRED" and (
                not isinstance(notes, str) or not notes
            ):
                report.error(
                    "run manifest cleanup NOT_REQUIRED requires an explanatory note"
                )

        artifact_keys: set[str] = set()
        for index, artifact in enumerate(run_manifest.get("artifacts", [])):
            if not isinstance(artifact, dict):
                continue
            context = f"run manifest artifacts[{index}]"
            artifact_path = _results_component_path(
                artifact.get("path"), report, f"{context}.path"
            )
            artifact_hash = artifact.get("sha256")
            if artifact_path is None:
                continue
            folded = artifact_path.casefold()
            if folded in artifact_keys:
                report.error(
                    f"run manifest has duplicate or case-alias artifact path {artifact_path!r}"
                )
                continue
            artifact_keys.add(folded)
            if artifact_path.casefold() in {
                results_path.name.casefold(),
                (manifest_name or "").casefold(),
            }:
                report.error(
                    f"{context} must not treat mutable results or manifest metadata as evidence"
                )
                continue
            artifacts[artifact_path] = artifact
            hashed = _safe_hash_run_file(run_root, artifact_path, report, context)
            if hashed is None:
                continue
            _, _, actual_hash, actual_size = hashed
            artifact_actual[artifact_path] = (actual_hash, actual_size)
            if isinstance(artifact_hash, str) and actual_hash != artifact_hash:
                report.error(
                    f"run artifact hash mismatch for contained artifact {artifact_path}"
                )
            size = artifact.get("size_bytes")
            if (
                isinstance(size, int)
                and not isinstance(size, bool)
                and actual_size != size
            ):
                report.error(
                    f"run artifact size mismatch for {artifact_path}: declared {size}, actual {actual_size}"
                )

        cleanup = run_manifest.get("cleanup")
        if isinstance(cleanup, dict):
            for index, cleanup_path in enumerate(cleanup.get("evidence", [])):
                normalised_cleanup = _results_component_path(
                    cleanup_path,
                    report,
                    f"run manifest cleanup.evidence[{index}]",
                )
                if (
                    normalised_cleanup is not None
                    and normalised_cleanup not in artifact_actual
                ):
                    report.error(
                        "run manifest cleanup evidence is not a verified declared artifact: "
                        f"{normalised_cleanup}"
                    )
            if cleanup.get("status") == "COMPLETED" and not cleanup.get("evidence"):
                report.error(
                    "run manifest cleanup COMPLETED requires at least one verified cleanup evidence artifact"
                )

        dirty_hash = run_manifest.get("dirty_patch_sha256")
        dirty_artifact = artifacts.get("dirty.patch")
        if run_dirty:
            if dirty_artifact is None or "dirty.patch" not in artifact_actual:
                report.error(
                    "dirty run must include a nonempty verified dirty.patch artifact"
                )
            elif (
                dirty_artifact.get("sha256") != dirty_hash
                or artifact_actual["dirty.patch"][0] != dirty_hash
            ):
                report.error("dirty.patch digest must match dirty_patch_sha256")
        elif dirty_artifact is not None or dirty_hash is not None:
            report.error("clean run must not declare dirty.patch or dirty_patch_sha256")

        attestation = run_manifest.get("attestation")
        if isinstance(attestation, dict):
            attestation_path = _results_component_path(
                attestation.get("artifact"),
                report,
                "run manifest attestation.artifact",
            )
            if attestation_path is not None:
                declared = artifacts.get(attestation_path)
                actual = artifact_actual.get(attestation_path)
                if declared is None or actual is None:
                    report.error(
                        "attestation must reference a verified artifact declared by the run manifest"
                    )
                elif (
                    declared.get("sha256") != attestation.get("sha256")
                    or actual[0] != attestation.get("sha256")
                ):
                    report.error(
                        "attestation digest does not match its verified artifact"
                    )
                else:
                    report.note(
                        "attestation artifact integrity is internally consistent; signature identity and trust-policy evaluation remain external"
                    )
        else:
            report.note(
                "no signed attestation supplied; hashes establish internal file consistency, not trusted execution identity"
            )

    known_cases = {case.case_id: case for case in suite.cases}
    entries: dict[str, ResultEntry] = {}
    raw_cases = loaded.get("cases")
    if not isinstance(raw_cases, list):
        return entries
    has_non_not_run = False
    for index, item in enumerate(raw_cases):
        if not isinstance(item, dict):
            continue
        case_id = item.get("id")
        context = f"results cases[{index}]"
        if not isinstance(case_id, str):
            continue
        if case_id in entries:
            report.error(f"{context}: duplicate result for case {case_id}")
            continue
        case = known_cases.get(case_id)
        if case is None:
            report.error(f"{context}: result references unknown case {case_id}")
        verdict = item.get("verdict")
        actual = item.get("actual")
        raw_evidence = item.get("evidence")
        cleanup = item.get("cleanup")
        cleanup_status = cleanup.get("status", "") if isinstance(cleanup, dict) else ""
        cleanup_actions = cleanup.get("actions", []) if isinstance(cleanup, dict) else []
        cleanup_notes = cleanup.get("notes", "") if isinstance(cleanup, dict) else ""
        if isinstance(actual, str):
            _validate_plain_text(
                actual,
                report,
                f"{context}.actual",
                minimum=0 if verdict == "NOT_RUN" else 1,
                maximum=4096,
            )
        if isinstance(cleanup, dict):
            if isinstance(cleanup_actions, list):
                for action_index, action in enumerate(cleanup_actions):
                    _validate_plain_text(
                        action,
                        report,
                        f"{context}.cleanup.actions[{action_index}]",
                        minimum=1,
                        maximum=512,
                    )
            _validate_plain_text(
                cleanup_notes,
                report,
                f"{context}.cleanup.notes",
                maximum=2048,
            )
            if cleanup_status == "COMPLETED" and not cleanup_actions:
                report.error(
                    f"{context}: cleanup COMPLETED requires at least one action"
                )
            if cleanup_status == "NOT_REQUIRED" and not cleanup_notes:
                report.error(
                    f"{context}: cleanup NOT_REQUIRED requires an explanatory note"
                )

        evidence: list[dict[str, Any]] = []
        coverage: set[str] = set()
        if isinstance(raw_evidence, list):
            for evidence_index, evidence_item in enumerate(raw_evidence):
                if not isinstance(evidence_item, dict):
                    continue
                evidence_context = f"{context} evidence[{evidence_index}]"
                artifact_path = _results_component_path(
                    evidence_item.get("artifact"),
                    report,
                    f"{evidence_context}.artifact",
                )
                artifact_hash = evidence_item.get("sha256")
                description = evidence_item.get("description")
                covers = evidence_item.get("covers")
                if isinstance(description, str):
                    _validate_plain_text(
                        description,
                        report,
                        f"{evidence_context}.description",
                        minimum=1,
                        maximum=1024,
                    )
                if not isinstance(covers, list) or not all(
                    isinstance(value, str) for value in covers
                ):
                    continue
                coverage.update(covers)
                if (
                    artifact_path is None
                    or not isinstance(artifact_hash, str)
                    or not isinstance(description, str)
                ):
                    continue
                evidence.append(
                    {
                        "artifact": artifact_path,
                        "sha256": artifact_hash,
                        "description": description,
                        "covers": list(covers),
                    }
                )
                declared = artifacts.get(artifact_path)
                actual_artifact = artifact_actual.get(artifact_path)
                if declared is None or actual_artifact is None:
                    report.error(
                        f"{evidence_context} references an absent or unverified run artifact: {artifact_path}"
                    )
                elif (
                    declared.get("sha256") != artifact_hash
                    or actual_artifact[0] != artifact_hash
                ):
                    report.error(
                        f"{evidence_context} digest does not match the verified run artifact"
                    )
                case_ids = (
                    declared.get("case_ids") if isinstance(declared, dict) else None
                )
                if isinstance(case_ids, list) and case_id not in case_ids:
                    report.error(
                        f"{evidence_context} artifact {artifact_path} does not declare case ID {case_id}"
                    )

        if verdict in ("PASS", "FAIL", "BLOCKED", "NOT_IN_RUN"):
            has_non_not_run = True
            if not evidence:
                report.error(
                    f"{context}: {verdict} requires at least one verified evidence reference"
                )
            if cleanup_status not in ("COMPLETED", "NOT_REQUIRED"):
                report.error(
                    f"{context}: non-NOT_RUN verdict requires cleanup COMPLETED or NOT_REQUIRED"
                )
            if "assertion:cleanup" not in coverage:
                report.error(
                    f"{context}: non-NOT_RUN verdict requires evidence covering assertion:cleanup"
                )
        if verdict in ("PASS", "FAIL") and case is not None:
            required = {
                f"step:{number}" for number in range(1, len(case.steps) + 1)
            }
            required.update(
                ("assertion:expected", "assertion:negative", "assertion:cleanup")
            )
            missing = sorted(required - coverage)
            if missing:
                report.error(
                    f"{context}: {verdict} evidence does not cover every step/assertion: "
                    + ", ".join(missing)
                )
        elif verdict == "BLOCKED" and "blocker" not in coverage:
            report.error(f"{context}: BLOCKED evidence must cover blocker")
        elif verdict == "NOT_IN_RUN" and "scope:not-in-run" not in coverage:
            report.error(
                f"{context}: NOT_IN_RUN evidence must cover scope:not-in-run"
            )
        if verdict == "NOT_RUN":
            if evidence:
                report.error(f"{context}: NOT_RUN must not claim evidence")
            if actual not in ("", None):
                report.error(f"{context}: NOT_RUN actual must be empty")
        if (
            isinstance(verdict, str)
            and verdict in VERDICTS
            and isinstance(actual, str)
        ):
            entries[case_id] = ResultEntry(
                case_id,
                verdict,
                actual,
                evidence,
                cleanup_status,
                list(cleanup_actions) if isinstance(cleanup_actions, list) else [],
                cleanup_notes if isinstance(cleanup_notes, str) else "",
            )

    if has_non_not_run and run_dirty:
        if run_manifest is None or "dirty.patch" not in artifact_actual:
            report.error(
                "non-NOT_RUN verdicts from a dirty run are prohibited without a verified nonempty dirty.patch artifact"
            )
    report.note(
        "result and artifact hashes were checked for internal consistency; they are not independent execution attestation"
    )
    return entries


def _archive_relative_path(
    raw: Any,
    archive_relative: str,
    report: ValidationReport,
    context: str,
) -> str | None:
    normalised = _normalise_relative_path(raw, report, context)
    if normalised is None:
        return None
    declared = PurePosixPath(normalised)
    base = PurePosixPath(archive_relative)
    if declared.parts[: len(base.parts)] != base.parts:
        report.error(
            f"{context} must be beneath declared archive {archive_relative!r}: {normalised!r}"
        )
        return None
    remainder = declared.parts[len(base.parts) :]
    if not remainder:
        report.error(f"{context} must name a file inside the archive")
        return None
    return PurePosixPath(*remainder).as_posix()


def _enumerate_archive_files(
    archive: Path, report: ValidationReport
) -> dict[str, Path]:
    files: dict[str, Path] = {}
    stack = [archive]
    while stack:
        directory = stack.pop()
        try:
            secure_io.verify_directory(archive, directory)
            entries = list(os.scandir(directory))
        except (OSError, secure_io.FileSafetyError) as exc:
            report.error(f"archive directory is unsafe or unreadable: {directory}: {exc}")
            continue
        for entry in entries:
            item = Path(entry.path)
            relative = item.relative_to(archive).as_posix()
            try:
                value = os.lstat(item)
            except OSError as exc:
                report.error(f"could not inspect archive entry {relative}: {exc}")
                continue
            is_reparse = bool(
                getattr(value, "st_file_attributes", 0)
                & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400)
            )
            if entry.is_symlink() or stat.S_ISLNK(value.st_mode) or is_reparse:
                report.error(f"archive must not contain symlinks or reparse points: {relative}")
            elif stat.S_ISDIR(value.st_mode):
                stack.append(item)
            elif stat.S_ISREG(value.st_mode):
                if value.st_nlink != 1:
                    report.error(
                        f"archive regular files must have exactly one hard link: {relative}"
                    )
                files[relative] = item
            else:
                report.error(f"archive contains a non-regular filesystem object: {relative}")
    return files


def _git_source_file_sha256(
    root: Path,
    source_commit: str,
    source_path: str,
    report: ValidationReport,
    context: str,
) -> str | None:
    if not (root / ".git").exists():
        return None
    object_spec = f"{source_commit}:{source_path}"
    try:
        size_result = subprocess.run(
            ["git", "-C", str(root), "cat-file", "-s", object_spec],
            check=False,
            capture_output=True,
            text=True,
            timeout=20,
            shell=False,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        report.error(f"could not verify {context} against source commit: {exc}")
        return None
    if size_result.returncode != 0:
        report.error(f"{context} does not exist as a Git file at the declared source commit")
        return None
    try:
        size = int(size_result.stdout.strip())
    except ValueError:
        report.error(f"could not determine source size for {context}")
        return None
    if size < 0 or size > MAX_ARCHIVE_FILE_BYTES:
        report.error(
            f"source object for {context} exceeds the {MAX_ARCHIVE_FILE_BYTES}-byte verification limit"
        )
        return None
    try:
        completed = subprocess.run(
            ["git", "-C", str(root), "cat-file", "blob", object_spec],
            check=False,
            capture_output=True,
            timeout=30,
            shell=False,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        report.error(f"could not read {context} from source commit: {exc}")
        return None
    if completed.returncode != 0 or len(completed.stdout) != size:
        report.error(f"could not read the complete original bytes for {context}")
        return None
    return hashlib.sha256(completed.stdout).hexdigest()


def _validate_archive_totals(value: Any, report: ValidationReport) -> None:
    required = {
        "total",
        "pass",
        "fail",
        "blocked",
        "not_run",
        "out_of_scope",
        "executed_web",
    }
    if not isinstance(value, dict):
        report.error("archive MANIFEST final_totals must be an object")
        return
    if set(value) != required:
        report.error(
            "archive MANIFEST final_totals must contain exactly: "
            + ", ".join(sorted(required))
        )
        return
    if any(
        not isinstance(value[name], int)
        or isinstance(value[name], bool)
        or value[name] < 0
        for name in required
    ):
        report.error("archive MANIFEST final_totals values must be nonnegative integers")
        return
    if value["pass"] + value["fail"] + value["blocked"] != value["executed_web"]:
        report.error("archive MANIFEST executed_web total is inconsistent")
    if (
        value["pass"]
        + value["fail"]
        + value["blocked"]
        + value["not_run"]
        + value["out_of_scope"]
        != value["total"]
    ):
        report.error("archive MANIFEST verdict totals are inconsistent")
    if value["executed_web"] + value["not_run"] + value["out_of_scope"] != value["total"]:
        report.error("archive MANIFEST run-scope totals are inconsistent")


def _validate_archive(root: Path, suite: SuiteData, report: ValidationReport) -> None:
    archive_error_start = len(report.errors)
    resolved = _resolve_declared_path(root, suite.archive_path, report, "archive_path")
    if resolved is None:
        return
    normalised, archive = resolved
    if not archive.exists():
        suite.archive_state = "missing"
        report.error(f"declared archive does not exist: {normalised}")
        return
    try:
        secure_io.verify_directory(root, archive)
    except (OSError, secure_io.FileSafetyError) as exc:
        suite.archive_state = "invalid"
        report.error(f"declared archive must be a real non-symlink directory: {normalised}: {exc}")
        return

    actual_files = _enumerate_archive_files(archive, report)
    manifest_like = sorted(
        relative
        for relative in actual_files
        if PurePosixPath(relative).name.casefold().startswith("manifest")
    )
    if manifest_like != ["MANIFEST.json"]:
        report.error(
            "archive must contain exactly one JSON manifest named MANIFEST.json; found "
            + (", ".join(manifest_like) if manifest_like else "none")
        )
    manifest_path = archive / "MANIFEST.json"
    if "MANIFEST.json" not in actual_files:
        suite.archive_state = "invalid"
        return
    manifest = _load_json(manifest_path, report, "archive MANIFEST")
    if not isinstance(manifest, dict):
        suite.archive_state = "invalid"
        return

    required_root = {
        "schema_version",
        "source_commit",
        "run_range",
        "frozen",
        "final_totals",
        "canonical_file_count",
        "files",
        "supporting_files",
    }
    if set(manifest) != required_root:
        missing = sorted(required_root - set(manifest))
        extra = sorted(set(manifest) - required_root)
        if missing:
            report.error(f"archive MANIFEST is missing required fields: {', '.join(missing)}")
        if extra:
            report.error(f"archive MANIFEST has unexpected fields: {', '.join(extra)}")
    if manifest.get("schema_version") != 2:
        report.error("archive MANIFEST schema_version must be 2")
    if manifest.get("frozen") is not True:
        report.error("archive MANIFEST frozen must be true")

    source_commit = manifest.get("source_commit")
    if not isinstance(source_commit, str) or not re.fullmatch(r"[0-9a-f]{40}", source_commit):
        report.error("archive MANIFEST source_commit must be a full lowercase Git commit")
        source_commit = ""
    historical_commit = suite.manifest.get("historical_source_commit")
    if isinstance(historical_commit, str) and source_commit != historical_commit.lower():
        report.error("archive MANIFEST source_commit does not match suite historical_source_commit")
    if source_commit and (root / ".git").exists() and not _git_commit_exists(root, source_commit):
        report.error("archive MANIFEST source_commit is unavailable in the local Git object store")

    run_range = manifest.get("run_range")
    if not isinstance(run_range, dict) or set(run_range) != {"start", "end"}:
        report.error("archive MANIFEST run_range must contain exactly start and end")
    else:
        try:
            start = _datetime.date.fromisoformat(run_range["start"])
            end = _datetime.date.fromisoformat(run_range["end"])
            if end < start:
                raise ValueError
        except (TypeError, ValueError):
            report.error("archive MANIFEST run_range must contain ordered ISO dates")
    _validate_archive_totals(manifest.get("final_totals"), report)

    raw_entries = manifest.get("files")
    if not isinstance(raw_entries, list):
        report.error("archive MANIFEST files must be an array")
        raw_entries = []
    canonical_count = manifest.get("canonical_file_count")
    if (
        not isinstance(canonical_count, int)
        or isinstance(canonical_count, bool)
        or canonical_count < 1
    ):
        report.error("archive MANIFEST canonical_file_count must be a positive integer")
    elif canonical_count != len(raw_entries):
        report.error(
            f"archive MANIFEST canonical_file_count is {canonical_count}, but files has {len(raw_entries)} entries"
        )

    payload_paths: set[str] = set()
    archive_paths_folded: set[str] = set()
    source_paths_folded: set[str] = set()
    required_entry = {
        "archive_path",
        "archived_sha256",
        "byte_preserved",
        "original_git_path",
        "original_sha256",
        "redaction_status",
        "role",
    }
    for index, entry in enumerate(raw_entries):
        context = f"archive MANIFEST files[{index}]"
        if not isinstance(entry, dict):
            report.error(f"{context} must be an object")
            continue
        if set(entry) != required_entry:
            missing = sorted(required_entry - set(entry))
            extra = sorted(set(entry) - required_entry)
            if missing:
                report.error(f"{context} is missing fields: {', '.join(missing)}")
            if extra:
                report.error(f"{context} has unexpected fields: {', '.join(extra)}")
        archive_relative = _archive_relative_path(
            entry.get("archive_path"), normalised, report, f"{context}.archive_path"
        )
        source_path = _normalise_relative_path(
            entry.get("original_git_path"), report, f"{context}.original_git_path"
        )
        archived_digest = entry.get("archived_sha256")
        original_digest = entry.get("original_sha256")
        byte_preserved = entry.get("byte_preserved")
        redaction_status = entry.get("redaction_status")
        role = entry.get("role")
        if not isinstance(archived_digest, str) or not SHA256_RE.fullmatch(archived_digest):
            report.error(f"{context}.archived_sha256 must be a lowercase SHA-256 digest")
        if not isinstance(original_digest, str) or not SHA256_RE.fullmatch(original_digest):
            report.error(f"{context}.original_sha256 must be a lowercase SHA-256 digest")
        if not isinstance(byte_preserved, bool):
            report.error(f"{context}.byte_preserved must be boolean")
        if redaction_status not in ARCHIVE_REDACTION_STATUSES:
            report.error(
                f"{context}.redaction_status must be one of {', '.join(sorted(ARCHIVE_REDACTION_STATUSES))}"
            )
        if role not in ARCHIVE_ROLES:
            report.error(f"{context}.role must be one of {', '.join(sorted(ARCHIVE_ROLES))}")
        if archive_relative is not None:
            folded = archive_relative.casefold()
            if folded in archive_paths_folded:
                report.error(f"{context} duplicates or case-aliases archive path {archive_relative}")
            archive_paths_folded.add(folded)
            payload_paths.add(archive_relative)
            physical = actual_files.get(archive_relative)
            if physical is None:
                report.error(f"{context} references missing canonical payload {archive_relative}")
            else:
                try:
                    actual_digest, _ = secure_io.hash_regular_file(
                        physical,
                        root=archive,
                        max_bytes=MAX_ARCHIVE_FILE_BYTES,
                        require_nonempty=True,
                        require_single_link=True,
                    )
                except (OSError, secure_io.FileSafetyError) as exc:
                    report.error(f"could not safely hash archive payload {archive_relative}: {exc}")
                else:
                    if actual_digest != archived_digest:
                        report.error(
                            f"archive payload digest mismatch for {archive_relative}; actual digest suppressed"
                        )
        if source_path is not None:
            folded = source_path.casefold()
            if folded in source_paths_folded:
                report.error(f"{context} duplicates or case-aliases original Git path {source_path}")
            source_paths_folded.add(folded)
            if source_commit:
                git_digest = _git_source_file_sha256(
                    root, source_commit, source_path, report, context
                )
                if git_digest is not None and git_digest != original_digest:
                    report.error(
                        f"{context}.original_sha256 does not match source_commit bytes; actual digest suppressed"
                    )
        if isinstance(archived_digest, str) and isinstance(original_digest, str):
            if byte_preserved is True and archived_digest != original_digest:
                report.error(f"{context} claims byte_preserved but original and archived hashes differ")
            if redaction_status == "redacted":
                if byte_preserved is not False:
                    report.error(f"{context} redacted payload must set byte_preserved false")
                if archived_digest == original_digest:
                    report.error(f"{context} redacted payload must differ from the original")
            if redaction_status == "not_required":
                if byte_preserved is not True:
                    report.error(f"{context} not_required payload must be byte-preserved")
                if archived_digest != original_digest:
                    report.error(f"{context} not_required payload must equal the original")

    supporting = manifest.get("supporting_files")
    support_paths: set[str] = set()
    if not isinstance(supporting, list):
        report.error("archive MANIFEST supporting_files must be an array")
        supporting = []
    for index, entry in enumerate(supporting):
        context = f"archive MANIFEST supporting_files[{index}]"
        if not isinstance(entry, dict) or set(entry) != {"path", "sha256", "role"}:
            report.error(f"{context} must contain exactly path, sha256, and role")
            continue
        relative = _normalise_relative_path(entry.get("path"), report, f"{context}.path")
        digest = entry.get("sha256")
        role = entry.get("role")
        if role not in ("archive_notice", "redaction_log"):
            report.error(f"{context}.role must be archive_notice or redaction_log")
        if not isinstance(digest, str) or not SHA256_RE.fullmatch(digest):
            report.error(f"{context}.sha256 must be a lowercase SHA-256 digest")
        if relative is None:
            continue
        if relative in support_paths:
            report.error(f"{context} duplicates supporting file {relative}")
        support_paths.add(relative)
        physical = actual_files.get(relative)
        if physical is None:
            report.error(f"{context} references missing supporting file {relative}")
            continue
        try:
            actual_digest, _ = secure_io.hash_regular_file(
                physical,
                root=archive,
                max_bytes=MAX_ARCHIVE_FILE_BYTES,
                require_nonempty=True,
                require_single_link=True,
            )
        except (OSError, secure_io.FileSafetyError) as exc:
            report.error(f"could not safely hash archive supporting file {relative}: {exc}")
        else:
            if actual_digest != digest:
                report.error(
                    f"archive supporting-file digest mismatch for {relative}; actual digest suppressed"
                )
    if support_paths != {"README.md", "REDACTIONS.md"}:
        report.error(
            "archive MANIFEST supporting_files must list exactly README.md and REDACTIONS.md"
        )

    allowed_files = payload_paths | support_paths | {"MANIFEST.json"}
    actual_set = set(actual_files)
    extras = sorted(actual_set - allowed_files)
    omissions = sorted(allowed_files - actual_set)
    if extras:
        report.error(f"archive contains files outside its exact integrity list: {', '.join(extras)}")
    if omissions:
        report.error(f"archive integrity list references missing files: {', '.join(omissions)}")
    if canonical_count != len(payload_paths):
        report.error(
            f"archive canonical payload set contains {len(payload_paths)} unique paths, not {canonical_count}"
        )

    expected_archive = KNOWN_ARCHIVE_EXPECTATIONS.get(normalised)
    if expected_archive is not None:
        if canonical_count != expected_archive["canonical_file_count"]:
            report.error(
                f"archive canonical_file_count must be {expected_archive['canonical_file_count']} for {normalised}"
            )
        if payload_paths != expected_archive["payload_paths"]:
            missing = sorted(expected_archive["payload_paths"] - payload_paths)
            unexpected = sorted(payload_paths - expected_archive["payload_paths"])
            if missing:
                report.error(f"archive canonical payload set is missing: {', '.join(missing)}")
            if unexpected:
                report.error(f"archive canonical payload set has unexpected paths: {', '.join(unexpected)}")
        if run_range != expected_archive["run_range"]:
            report.error(
                f"archive run_range must be exactly {expected_archive['run_range']} for {normalised}"
            )
        if manifest.get("final_totals") != expected_archive["final_totals"]:
            report.error(
                f"archive final_totals must be exactly {expected_archive['final_totals']} for {normalised}"
            )

    suite.archive_state = "verified" if len(report.errors) == archive_error_start else "invalid"
    suite.archive_verified_files = len(payload_paths) + len(support_paths)


def _metadata_and_paths(
    root: Path, manifest: dict[str, Any], report: ValidationReport
) -> tuple[dict[str, Any], str, str, dict[str, str]]:
    metadata = manifest.get("metadata")
    if not isinstance(metadata, dict):
        metadata = {}
    top_level_metadata = {
        "title": _pick(manifest, "title", default="SYNAPSE Black-Box Testing"),
        "version": _pick(manifest, "suite_version", default=""),
        "status": _pick(manifest, "status", default="DRAFT"),
    }
    metadata = {**top_level_metadata, **metadata}
    for key in ("title", "version", "status"):
        if not isinstance(metadata.get(key), str) or not metadata.get(key, "").strip():
            report.error(f"suite.json metadata.{key} (or top-level equivalent) must be a non-empty string")
    target = _pick(
        manifest,
        "target_commit",
        "target_application_commit",
        default=MISSING,
    )
    if target is MISSING:
        target = metadata.get("target_commit")
    if not isinstance(target, str) or not re.fullmatch(r"[0-9A-Fa-f]{7,40}", target.strip() if isinstance(target, str) else ""):
        report.error("suite.json target_commit must be a 7-40 digit hexadecimal Git commit")
        target_commit = ""
    else:
        target_commit = target.strip().lower()
    archive_raw = _pick(manifest, "archive_path", "archive", default=None)
    if isinstance(archive_raw, dict):
        archive_raw = _pick(archive_raw, "path", "directory", default=None)
    archive = _normalise_relative_path(archive_raw, report, "suite.json archive_path") or ""
    raw_outputs = manifest.get("outputs")
    outputs: dict[str, str] = {}
    if isinstance(raw_outputs, dict):
        for kind in ("markdown", "html", "docx", "pdf"):
            raw = raw_outputs.get(kind)
            value = _normalise_relative_path(raw, report, f"suite.json outputs.{kind}")
            if value is not None:
                outputs[kind] = value
    else:
        generated = manifest.get("generated_root_outputs")
        if not isinstance(generated, list) or not generated:
            report.error("suite.json must declare outputs or generated_root_outputs")
        else:
            for index, raw in enumerate(generated):
                value = _normalise_relative_path(
                    raw, report, f"suite.json generated_root_outputs[{index}]"
                )
                if value is None:
                    continue
                suffix = PurePosixPath(value).suffix.lower()
                kind = {".md": "markdown", ".html": "html", ".docx": "docx", ".pdf": "pdf"}.get(suffix)
                if kind is None:
                    report.error(
                        f"suite.json generated_root_outputs[{index}] has an unsupported extension: {value}"
                    )
                elif kind in outputs:
                    report.error(f"suite.json declares more than one {kind} output")
                else:
                    outputs[kind] = value
            # DOCX/PDF are optional publication products when omitted from the
            # generated-root declaration; use the canonical sibling names.
            output_parent = PurePosixPath(outputs.get("markdown", "papers/blackbox/output.md")).parent
            outputs.setdefault("docx", (output_parent / "SYNAPSE-BlackBox-Testing.docx").as_posix())
            outputs.setdefault("pdf", (output_parent / "SYNAPSE-BlackBox-Testing.pdf").as_posix())
    for required_kind in ("markdown", "html"):
        if required_kind not in outputs:
            report.error(f"suite.json must declare a generated {required_kind} output")
    duplicate_paths = [path for path, count in collections.Counter(outputs.values()).items() if count > 1]
    if duplicate_paths:
        report.error(f"suite.json output paths must be distinct: {', '.join(duplicate_paths)}")
    return metadata, target_commit, archive, outputs


def validate_project(
    root: Path | str,
    results: Path | str | None = None,
    *,
    emit_summary: bool = False,
) -> tuple[SuiteData | None, ValidationReport]:
    root = secure_io.absolute_lexical(root)
    report = ValidationReport()
    try:
        secure_io.verify_absolute_directory(root)
    except (OSError, secure_io.FileSafetyError) as exc:
        report.error(f"repository root has a symlink/reparse or non-directory component: {exc}")
        if emit_summary:
            print_summary(None, report, root)
        return None, report
    suite_path = root / "papers" / "blackbox" / "suite.json"
    manifest_loaded = _load_json(suite_path, report, "suite manifest")
    schemas = _load_and_validate_schemas(root, report)
    if not isinstance(manifest_loaded, dict):
        if manifest_loaded is not None:
            report.error("suite manifest must contain a JSON object")
        if emit_summary:
            print_summary(None, report, root)
        return None, report
    manifest = manifest_loaded
    metadata, target_commit, archive_path, outputs = _metadata_and_paths(root, manifest, report)

    declared_lifecycles = manifest.get("allowed_lifecycles")
    if declared_lifecycles is not None:
        if not isinstance(declared_lifecycles, list) or any(
            not isinstance(value, str) for value in declared_lifecycles
        ):
            report.error("suite.json allowed_lifecycles must be an array of strings")
        else:
            duplicates = [
                value
                for value, count in collections.Counter(declared_lifecycles).items()
                if count > 1
            ]
            if duplicates:
                report.error(
                    f"suite.json allowed_lifecycles contains duplicates: {', '.join(duplicates)}"
                )
            unknown = sorted(set(declared_lifecycles) - set(LIFECYCLES))
            if unknown:
                report.error(
                    f"suite.json allowed_lifecycles contains unknown values: {', '.join(unknown)}"
                )
    declared_verdicts = manifest.get("allowed_verdicts")
    if declared_verdicts is not None:
        if not isinstance(declared_verdicts, list) or any(
            not isinstance(value, str) for value in declared_verdicts
        ):
            report.error("suite.json allowed_verdicts must be an array of strings")
        else:
            duplicates = [
                value
                for value, count in collections.Counter(declared_verdicts).items()
                if count > 1
            ]
            if duplicates:
                report.error(
                    f"suite.json allowed_verdicts contains duplicates: {', '.join(duplicates)}"
                )
            unknown = sorted(set(declared_verdicts) - set(VERDICTS))
            if unknown:
                report.error(
                    f"suite.json allowed_verdicts contains unknown values: {', '.join(unknown)}"
                )
    if "default_verdict" in manifest and manifest.get("default_verdict") != "NOT_RUN":
        report.error("suite.json default_verdict must be NOT_RUN")
    historical_commit = manifest.get("historical_source_commit")
    if historical_commit is not None and (
        not isinstance(historical_commit, str)
        or not re.fullmatch(r"[0-9A-Fa-f]{7,40}", historical_commit)
    ):
        report.error("suite.json historical_source_commit must be a 7-40 digit hexadecimal commit")

    expected_count = _pick(manifest, "expected_case_count", "expected_count", default=MISSING)
    if expected_count is MISSING:
        expected_count = metadata.get("expected_case_count")
    if expected_count != EXPECTED_CASE_COUNT:
        report.error(
            f"suite.json expected case count must be exactly {EXPECTED_CASE_COUNT}; found {expected_count!r}"
        )

    section_declarations = _section_declarations(manifest, report)
    sections: list[Section] = []
    all_cases: list[Case] = []
    for index, declaration in enumerate(section_declarations):
        resolved = _resolve_declared_path(
            root,
            declaration["file"],
            report,
            f"suite.json sections[{index}].file",
            suite_dir=suite_path.parent,
            allow_suite_relative=True,
        )
        if resolved is None:
            continue
        section_path, physical_path = resolved
        if physical_path.suffix.lower() not in (".md", ".markdown"):
            report.error(f"ordered section must be a Markdown file: {section_path}")
        content = _read_text(physical_path, report, "section file")
        if content is None:
            continue
        title = declaration["title"] if isinstance(declaration["title"], str) else physical_path.stem
        if declaration.get("title_is_default"):
            first_heading = next(
                (line[2:].strip() for line in content.splitlines() if line.startswith("# ")),
                "",
            )
            if first_heading:
                title = re.sub(r"^\d+\s+[—-]\s+", "", first_heading).strip()
        cases = parse_section(content, section_path, title, report)
        declared_count = declaration["expected_count"]
        if isinstance(declared_count, int) and len(cases) != declared_count:
            report.error(
                f"section count drift for {section_path}: declared {declared_count}, parsed {len(cases)}"
            )
        sections.append(Section(section_path, title, declared_count, content, cases))
        all_cases.extend(cases)

    # Optional central section_counts declarations are checked too.
    section_counts = manifest.get("section_counts")
    if section_counts is not None:
        if not isinstance(section_counts, dict):
            report.error("suite.json section_counts must be an object when present")
        else:
            by_path = {section.path: len(section.cases) for section in sections}
            by_title = {section.title: len(section.cases) for section in sections}
            for key, value in section_counts.items():
                if not isinstance(value, int) or isinstance(value, bool):
                    report.error(f"suite.json section_counts[{key!r}] must be an integer")
                    continue
                actual = by_path.get(key, by_title.get(key))
                if actual is None:
                    report.error(f"suite.json section_counts names an unknown section: {key}")
                elif actual != value:
                    report.error(f"section count drift for {key}: declared {value}, parsed {actual}")
    if len(all_cases) != EXPECTED_CASE_COUNT:
        report.error(f"case count drift: expected {EXPECTED_CASE_COUNT}, parsed {len(all_cases)}")
    id_locations: dict[str, list[str]] = collections.defaultdict(list)
    for case in all_cases:
        id_locations[case.case_id].append(f"{case.section_path}:{case.start_line}")
    for case_id, locations in sorted(id_locations.items()):
        if len(locations) > 1:
            report.error(f"duplicate case ID {case_id}: {', '.join(locations)}")

    patch_commits = _normalise_commit_list(root, manifest, report)
    legacy_rows, legacy_config = _load_legacy_rows(root, suite_path.parent, manifest, report)
    declared_legacy_total, declared_legacy_counts = _declared_legacy_counts(
        manifest, legacy_config, report
    )
    if declared_legacy_total != EXPECTED_LEGACY_COUNT:
        report.error(
            f"suite.json legacy expected count must be {EXPECTED_LEGACY_COUNT}; "
            f"found {declared_legacy_total!r}"
        )
    if declared_legacy_counts is not None and declared_legacy_counts != EXPECTED_LEGACY_DISPOSITIONS:
        report.error(
            "suite.json legacy disposition declarations must be exactly "
            f"{EXPECTED_LEGACY_DISPOSITIONS}; found {declared_legacy_counts}"
        )
    if len(legacy_rows) != EXPECTED_LEGACY_COUNT:
        report.error(
            f"legacy map must contain exactly {EXPECTED_LEGACY_COUNT} rows; found {len(legacy_rows)}"
        )
    row_ids = [row.legacy_id for row in legacy_rows]
    duplicates = [value for value, count in collections.Counter(row_ids).items() if count > 1]
    if duplicates:
        report.error(f"legacy map contains duplicate legacy IDs: {', '.join(sorted(duplicates))}")
    actual_dispositions = collections.Counter(row.disposition for row in legacy_rows)
    for disposition, expected in EXPECTED_LEGACY_DISPOSITIONS.items():
        actual = actual_dispositions.get(disposition, 0)
        if actual != expected:
            report.error(
                f"legacy disposition drift for {disposition}: expected {expected}, found {actual}"
            )
    unexpected_dispositions = sorted(set(actual_dispositions) - set(LEGACY_DISPOSITIONS))
    if unexpected_dispositions:
        report.error(f"legacy map has unknown dispositions: {', '.join(unexpected_dispositions)}")

    suite = SuiteData(
        root=root,
        suite_path=suite_path,
        manifest=manifest,
        metadata=metadata,
        target_commit=target_commit,
        patch_commits=patch_commits,
        sections=sections,
        cases=all_cases,
        legacy_rows=legacy_rows,
        archive_path=archive_path,
        outputs=outputs,
    )

    known_current_ids = set(id_locations)
    row_by_id = {row.legacy_id: row for row in legacy_rows}
    mentioned_legacy: dict[str, set[str]] = collections.defaultdict(set)
    for case in all_cases:
        for legacy_id in case.legacy_ids:
            mentioned_legacy[legacy_id].add(case.case_id)
            row = row_by_id.get(legacy_id)
            if row is None:
                report.error(
                    f"{case.section_path}:{case.start_line} ({case.case_id}): "
                    f"Legacy ID {legacy_id} is missing from the legacy map"
                )
            elif row.disposition == "RETIRED":
                report.error(
                    f"{case.section_path}:{case.start_line} ({case.case_id}): "
                    f"retired legacy ID {legacy_id} must live only in the legacy map"
                )
            elif case.case_id not in row.current_ids:
                report.error(
                    f"legacy map row {legacy_id} does not map back to current case {case.case_id}"
                )
    for row in legacy_rows:
        if row.disposition == "RETIRED":
            if row.legacy_id in known_current_ids:
                report.error(f"retired legacy ID {row.legacy_id} appears as a current case ID")
            if row.legacy_id in mentioned_legacy:
                report.error(f"retired legacy ID {row.legacy_id} is referenced by a current case")
            # Replacement IDs may be documented in the legacy-map reason/current
            # column, but RETIRED IDs themselves never appear in executable cases.
            for replacement_id in row.current_ids:
                if replacement_id not in known_current_ids:
                    report.error(
                        f"retired legacy map row {row.legacy_id} references unknown replacement case "
                        f"{replacement_id}"
                    )
        else:
            if not row.current_ids:
                report.error(f"{row.disposition} legacy ID {row.legacy_id} must map to a current case")
            for current_id in row.current_ids:
                if current_id not in known_current_ids:
                    report.error(
                        f"legacy map row {row.legacy_id} references unknown current case {current_id}"
                    )
                elif row.legacy_id not in next(
                    (case.legacy_ids for case in all_cases if case.case_id == current_id), []
                ):
                    report.error(
                        f"current case {current_id} does not declare mapped Legacy ID {row.legacy_id}"
                    )

    covered_commits: set[str] = set()
    for case in all_cases:
        for reference in case.changed_by:
            resolved_commit = _resolve_commit(reference, patch_commits)
            if resolved_commit is None:
                if _git_commit_exists(root, reference):
                    # A case may also cite an older implementation baseline.
                    # The strict 14-patch completeness check remains governed by
                    # TRACEABILITY.md / patch_commits below.
                    continue
                report.error(
                    f"{case.section_path}:{case.start_line} ({case.case_id}): "
                    f"Changed by commit {reference} is neither a declared patch nor an available Git commit"
                )
            else:
                covered_commits.add(resolved_commit)
    traceability_rows = _validate_traceability_map(root, patch_commits, known_current_ids, report)
    uncovered = [
        commit
        for commit in patch_commits
        if commit not in covered_commits and commit not in traceability_rows
    ]
    if uncovered:
        report.error(f"patch commits without case/traceability coverage: {', '.join(uncovered)}")

    _validate_source_references(root, all_cases, report)
    commits_to_check = list(
        dict.fromkeys(
            ([target_commit] if target_commit else [])
            + ([historical_commit.lower()] if isinstance(historical_commit, str) else [])
            + patch_commits
        )
    )
    _validate_git_commits(root, commits_to_check, report)

    results_path: Path | None = None
    if results is not None:
        raw_results_path = Path(results)
        if not raw_results_path.is_absolute():
            results_path = secure_io.absolute_lexical(root / raw_results_path)
        else:
            results_path = secure_io.absolute_lexical(raw_results_path)
        suite.results_path = results_path
        entries = _load_results(root, results_path, suite, schemas, report)
        suite.result_entries = entries
        for case in all_cases:
            entry = entries.get(case.case_id)
            if entry is not None:
                case.effective_verdict = entry.verdict
                case.effective_evidence = entry.evidence
                case.effective_actual = entry.actual
            else:
                case.effective_verdict = "NOT_RUN"
            if case.source_verdict != "NOT_RUN":
                if entry is None:
                    report.error(
                        f"{case.section_path}:{case.start_line} ({case.case_id}): source Verdict "
                        f"{case.source_verdict} requires a matching sanitized result"
                    )
                elif case.source_verdict != entry.verdict:
                    report.error(
                        f"{case.section_path}:{case.start_line} ({case.case_id}): source Verdict "
                        f"{case.source_verdict} does not match result Verdict {entry.verdict}"
                    )
                else:
                    evidence_text = case.fields.get("Evidence", "")
                    for evidence in entry.evidence:
                        if evidence["artifact"] not in evidence_text:
                            report.error(
                                f"{case.section_path}:{case.start_line} ({case.case_id}): source Evidence "
                                f"does not reference result artifact {evidence['artifact']}"
                            )
    else:
        for case in all_cases:
            case.effective_verdict = "NOT_RUN"
            if case.source_verdict and case.source_verdict != "NOT_RUN":
                report.error(
                    f"{case.section_path}:{case.start_line} ({case.case_id}): Verdict must default to "
                    "NOT_RUN unless --results supplies hash-backed sanitized evidence"
                )

    if archive_path:
        _validate_archive(root, suite, report)
    if emit_summary:
        print_summary(suite, report, root)
    return suite, report


def print_summary(suite: SuiteData | None, report: ValidationReport, root: Path | str) -> None:
    root = secure_io.absolute_lexical(root)
    status = "PASS" if report.ok else "FAIL"
    print(f"Black-box validation: {status}")
    print(f"Root: {root}")
    if suite is not None:
        lifecycle_counts = collections.Counter(case.lifecycle for case in suite.cases)
        verdict_counts = collections.Counter(case.effective_verdict for case in suite.cases)
        disposition_counts = collections.Counter(row.disposition for row in suite.legacy_rows)
        print(f"Sections: {len(suite.sections)}")
        print(
            f"Cases: {len(suite.cases)}/{EXPECTED_CASE_COUNT} "
            + " ".join(f"{name}={lifecycle_counts.get(name, 0)}" for name in LIFECYCLES)
        )
        print(
            f"Verdicts: "
            + " ".join(f"{name}={verdict_counts.get(name, 0)}" for name in VERDICTS)
        )
        print(
            f"Legacy: {len(suite.legacy_rows)}/{EXPECTED_LEGACY_COUNT} "
            + " ".join(
                f"{name}={disposition_counts.get(name, 0)}" for name in LEGACY_DISPOSITIONS
            )
        )
        case_covered: set[str] = set()
        for case in suite.cases:
            for reference in case.changed_by:
                resolved = _resolve_commit(reference, suite.patch_commits)
                if resolved:
                    case_covered.add(resolved)
        mapped_report = ValidationReport()
        mapped = {
            resolved
            for reference in _commits_from_traceability(suite.root, mapped_report)
            if (resolved := _resolve_commit(reference, suite.patch_commits)) is not None
        }
        total_covered = case_covered | mapped
        print(
            f"Patch traceability: {len(total_covered)}/{EXPECTED_PATCH_COMMIT_COUNT} "
            f"({len(case_covered)} case-cited)"
        )
        print(
            "Results: "
            + (str(suite.results_path) if suite.results_path is not None else "none (source defaults enforced)")
        )
        print(
            f"Archive: {suite.archive_state}"
            + (f" ({suite.archive_verified_files} files)" if suite.archive_verified_files else "")
        )
    for message in report.notes:
        print(f"NOTE: {message}")
    for message in report.warnings:
        print(f"WARNING: {message}")
    for message in report.errors:
        print(f"ERROR: {message}", file=sys.stderr)
    print(
        f"Summary: {len(report.errors)} error(s), {len(report.warnings)} warning(s), "
        f"{len(report.notes)} note(s)"
    )


def _default_root() -> Path:
    return Path(__file__).resolve().parents[2]


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=_default_root(), help="repository root")
    parser.add_argument(
        "--results",
        type=Path,
        help="optional sanitized results JSON; omitted means every source verdict must be NOT_RUN",
    )
    args = parser.parse_args(argv)
    _, report = validate_project(args.root, args.results, emit_summary=True)
    return 0 if report.ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
