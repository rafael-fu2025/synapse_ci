from __future__ import annotations

import contextlib
import hashlib
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

TOOLS_DIR = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = TOOLS_DIR.parents[1]
SCHEMA_SOURCE = REPOSITORY_ROOT / "papers" / "blackbox" / "schema"
if str(TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(TOOLS_DIR))

import build  # noqa: E402
import publish  # noqa: E402
import secure_io  # noqa: E402
import validate  # noqa: E402


TARGET_COMMIT = "a" * 40
PATCH_COMMITS = tuple(f"{0x1000001 + index:07x}" for index in range(23))


def case_markdown(
    number: int,
    *,
    case_id: str | None = None,
    lifecycle: str = "READY",
    verdict: str = "NOT_RUN",
    legacy_id: str | None | object = ...,  # Ellipsis selects the normal fixture mapping.
    changed_by: str | None = None,
    role: str = "student",
    module: str = "Fixture Module",
    use_cases: str = "`UC-FX-01`",
    steps: int = 2,
) -> str:
    case_id = case_id or f"TST-{number:03d}"
    if legacy_id is ...:
        legacy_value = f"`LEG-{number:03d}`" if number <= 114 else "None"
    elif legacy_id is None:
        legacy_value = "None"
    else:
        legacy_value = f"`{legacy_id}`"
    changed_value = changed_by or (
        f"`{PATCH_COMMITS[number - 1]}`" if number <= len(PATCH_COMMITS) else "baseline"
    )
    numbered_steps = "\n".join(
        f"{index}. Perform deterministic fixture action {index}." for index in range(1, steps + 1)
    )
    return f"""## {case_id} — Fixture case {number}
- **Lifecycle:** `{lifecycle}`
- **Legacy IDs:** {legacy_value}
- **Changed by:** {changed_value}
- **Requirement:** FIXTURE.REQUIREMENT.{number:03d}
- **Role:** `{role}`
- **Module:** {module}
- **Use Cases:** {use_cases}
- **Surface:** Isolated fixture surface
- **Route/API:** GET /fixture/{number}
- **Actor/grants:** Synthetic actor with fixture.read
- **Preconditions:** Temporary fixture repository exists.
**Steps**
{numbered_steps}
- **Expected:** The deterministic fixture observation is available.
- **Negative/non-disclosure:** No product database, credential, or network is used.
- **Cleanup:** Remove the temporary directory.
- **Source:** app/source.py:1-2
- **Evidence:** A sanitized local fixture artifact would be recorded for an executed run.
- **Verdict:** `{verdict}`
"""


def legacy_markdown() -> str:
    rows = [
        "# Fixture legacy map",
        "",
        "| Legacy ID | Disposition | Current ID(s) | Reason |",
        "| --- | --- | --- | --- |",
    ]
    for number in range(1, 120):
        if number <= 72:
            disposition = "RETAIN"
            current = f"TST-{number:03d}"
        elif number <= 114:
            disposition = "REWRITE"
            current = f"TST-{number:03d}"
        else:
            disposition = "RETIRED"
            current = "None"
        rows.append(
            f"| LEG-{number:03d} | {disposition} | {current} | Deterministic fixture row. |"
        )
    return "\n".join(rows) + "\n"


def use_cases_manifest() -> dict[str, object]:
    return {
        "schema_version": 1,
        "roles": [
            {
                "id": "student",
                "label": "Student",
                "kind": "rbac",
                "inherits": None,
                "summary": "Fixture student actor.",
            }
        ],
        "modules": ["Fixture Module"],
        "use_cases": [
            {
                "id": "UC-FX-01",
                "figure": "Figure 5a",
                "role": "student",
                "module": "Fixture Module",
                "title": "Fixture use case 1",
            }
        ],
    }


def suite_manifest() -> dict[str, object]:
    return {
        "metadata": {
            "title": "Fixture Black-Box Suite",
            "version": "fixture-1",
            "status": "DRAFT",
        },
        "target_commit": TARGET_COMMIT,
        "use_cases_path": "papers/blackbox/use-cases.json",
        "expected_case_count": 266,
        "patch_commits": list(PATCH_COMMITS),
        "sections": [
            {
                "file": "papers/blackbox/sections/01-fixture.md",
                "title": "Fixture section",
                "expected_count": 266,
            }
        ],
        "legacy": {
            "file": "papers/blackbox/legacy-map.md",
            "expected_count": 119,
            "disposition_counts": {"RETAIN": 72, "REWRITE": 42, "RETIRED": 5},
        },
        "archive_path": "papers/blackbox/archive/frozen",
        "outputs": {
            "markdown": "papers/blackbox/SYNAPSE-BlackBox-Testing.md",
            "html": "papers/blackbox/SYNAPSE-BlackBox-Testing.html",
            "docx": "papers/blackbox/SYNAPSE-BlackBox-Testing.docx",
            "pdf": "papers/blackbox/SYNAPSE-BlackBox-Testing.pdf",
        },
    }


class FixtureRepository:
    def __init__(self, root: Path) -> None:
        self.root = root
        self.blackbox = root / "papers" / "blackbox"
        self.section = self.blackbox / "sections" / "01-fixture.md"
        self.legacy = self.blackbox / "legacy-map.md"
        self.suite = self.blackbox / "suite.json"
        self.use_cases = self.blackbox / "use-cases.json"

    def create(self) -> "FixtureRepository":
        (self.blackbox / "sections").mkdir(parents=True)
        (self.blackbox / "schema").mkdir(parents=True)
        (self.root / "app").mkdir(parents=True)
        (self.root / "app" / "source.py").write_text(
            "first = True\nsecond = True\n", encoding="utf-8"
        )
        shutil.copy2(
            SCHEMA_SOURCE / "run-manifest.schema.json",
            self.blackbox / "schema" / "run-manifest.schema.json",
        )
        shutil.copy2(
            SCHEMA_SOURCE / "results.schema.json",
            self.blackbox / "schema" / "results.schema.json",
        )
        self.section.write_text(
            "# Fixture section\n\n"
            + "\n".join(case_markdown(number) for number in range(1, 267)),
            encoding="utf-8",
        )
        self.legacy.write_text(legacy_markdown(), encoding="utf-8")
        self.write_use_cases(use_cases_manifest())
        self.write_suite(suite_manifest())
        self.create_valid_archive()
        return self

    def write_use_cases(self, value: dict[str, object]) -> None:
        self.use_cases.write_text(
            json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )

    def read_use_cases(self) -> dict[str, object]:
        return json.loads(self.use_cases.read_text(encoding="utf-8"))

    def write_suite(self, value: dict[str, object]) -> None:
        self.suite.write_text(
            json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )

    def read_suite(self) -> dict[str, object]:
        return json.loads(self.suite.read_text(encoding="utf-8"))

    @property
    def archive(self) -> Path:
        return self.blackbox / "archive" / "frozen"

    def create_valid_archive(self) -> Path:
        archive = self.archive
        archive.mkdir(parents=True, exist_ok=True)
        payload = b"immutable fixture history\n"
        (archive / "history.txt").write_bytes(payload)
        (archive / "README.md").write_text("# Fixture archive\n", encoding="utf-8")
        (archive / "REDACTIONS.md").write_text("# Fixture redactions\n", encoding="utf-8")
        manifest = {
            "schema_version": 2,
            "source_commit": "c" * 40,
            "run_range": {"start": "2026-11-01", "end": "2026-11-02"},
            "frozen": True,
            "final_totals": {
                "total": 1,
                "pass": 1,
                "fail": 0,
                "blocked": 0,
                "not_run": 0,
                "out_of_scope": 0,
                "executed_web": 1,
            },
            "canonical_file_count": 1,
            "files": [
                {
                    "archive_path": "papers/blackbox/archive/frozen/history.txt",
                    "archived_sha256": hashlib.sha256(payload).hexdigest(),
                    "byte_preserved": True,
                    "original_git_path": "history.txt",
                    "original_sha256": hashlib.sha256(payload).hexdigest(),
                    "redaction_status": "not_required",
                    "role": "evidence",
                }
            ],
            "supporting_files": [
                {
                    "path": "README.md",
                    "sha256": hashlib.sha256((archive / "README.md").read_bytes()).hexdigest(),
                    "role": "archive_notice",
                },
                {
                    "path": "REDACTIONS.md",
                    "sha256": hashlib.sha256((archive / "REDACTIONS.md").read_bytes()).hexdigest(),
                    "role": "redaction_log",
                },
            ],
        }
        (archive / "MANIFEST.json").write_text(
            json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )
        return archive

    def read_archive_manifest(self) -> dict[str, object]:
        return json.loads((self.archive / "MANIFEST.json").read_text(encoding="utf-8"))

    def write_archive_manifest(self, value: dict[str, object]) -> None:
        (self.archive / "MANIFEST.json").write_text(
            json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )

    def replace_once(self, old: str, new: str, *, path: Path | None = None) -> None:
        target = path or self.section
        content = target.read_text(encoding="utf-8")
        if old not in content:
            raise AssertionError(f"fixture mutation found no occurrence of {old!r}")
        target.write_text(content.replace(old, new, 1), encoding="utf-8")

    def validate(self, results: Path | None = None) -> tuple[validate.SuiteData | None, validate.ValidationReport]:
        return validate.validate_project(self.root, results)

    def write_result_json(self, path: Path, value: dict[str, object]) -> None:
        path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    def read_result_json(self, path: Path) -> dict[str, object]:
        return json.loads(path.read_text(encoding="utf-8"))

    def refresh_result_manifest_hash(self, results_path: Path) -> None:
        results = self.read_result_json(results_path)
        manifest = results_path.parent / str(results["run_manifest"])
        results["run_manifest_sha256"] = hashlib.sha256(manifest.read_bytes()).hexdigest()
        self.write_result_json(results_path, results)

    def write_run_manifest(self, results_path: Path, value: dict[str, object]) -> None:
        manifest_path = results_path.parent / "run-manifest.json"
        self.write_result_json(manifest_path, value)
        self.refresh_result_manifest_hash(results_path)

    def read_run_manifest(self, results_path: Path) -> dict[str, object]:
        return self.read_result_json(results_path.parent / "run-manifest.json")

    def create_valid_result_overlay(self) -> Path:
        run_dir = self.blackbox / "results" / "run-001"
        evidence_dir = run_dir / "evidence"
        evidence_dir.mkdir(parents=True)
        evidence_bytes = b"sanitized deterministic observation\n"
        evidence_path = evidence_dir / "TST-001.txt"
        evidence_path.write_bytes(evidence_bytes)
        cleanup_bytes = b"fixture cleanup verified\n"
        cleanup_path = evidence_dir / "cleanup.txt"
        cleanup_path.write_bytes(cleanup_bytes)
        evidence_hash = hashlib.sha256(evidence_bytes).hexdigest()
        cleanup_hash = hashlib.sha256(cleanup_bytes).hexdigest()
        run_manifest = {
            "schema_version": 2,
            "run_id": "run-001",
            "sanitized": True,
            "suite_target_commit": TARGET_COMMIT,
            "tested_commit": TARGET_COMMIT,
            "dirty": False,
            "dirty_patch_sha256": None,
            "executed_by": {
                "id": "fixture-tester",
                "role": "black-box tester",
                "kind": "HUMAN_WITH_AUTOMATION",
            },
            "migrations": [],
            "flags": {},
            "environment": {
                "base_url": "http://localhost:8080",
                "app_environment": "test",
                "database_engine": "sqlite",
                "server_os": "fixture",
                "runtime_versions": {"python": "3.12"},
            },
            "browser_device": {
                "browser": "Fixture Browser",
                "browser_version": "1",
                "device": "Desktop fixture",
                "viewport": {
                    "width": 1280,
                    "height": 720,
                    "device_scale_factor": 1,
                },
                "user_agent_sha256": "b" * 64,
                "automation": "unittest",
            },
            "timezone": "UTC",
            "started_at": "2026-11-18T00:00:00Z",
            "finished_at": "2026-11-18T00:01:00Z",
            "artifacts": [
                {
                    "path": "evidence/TST-001.txt",
                    "sha256": evidence_hash,
                    "size_bytes": len(evidence_bytes),
                    "media_type": "text/plain",
                    "case_ids": ["TST-001"],
                },
                {
                    "path": "evidence/cleanup.txt",
                    "sha256": cleanup_hash,
                    "size_bytes": len(cleanup_bytes),
                    "media_type": "text/plain",
                    "case_ids": ["TST-001"],
                },
            ],
            "cleanup": {
                "status": "COMPLETED",
                "actions": ["Removed synthetic fixture"],
                "notes": "No product data used.",
                "evidence": ["evidence/cleanup.txt"],
            },
        }
        manifest_path = run_dir / "run-manifest.json"
        manifest_path.write_text(
            json.dumps(run_manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )
        manifest_hash = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
        results = {
            "schema_version": 2,
            "run_id": "run-001",
            "sanitized": True,
            "suite_target_commit": TARGET_COMMIT,
            "run_manifest": "run-manifest.json",
            "run_manifest_sha256": manifest_hash,
            "cases": [
                {
                    "id": "TST-001",
                    "verdict": "PASS",
                    "actual": "The deterministic fixture observation was present.",
                    "evidence": [
                        {
                            "artifact": "evidence/TST-001.txt",
                            "sha256": evidence_hash,
                            "description": "Sanitized fixture observation.",
                            "covers": [
                                "step:1",
                                "step:2",
                                "assertion:expected",
                                "assertion:negative",
                                "assertion:cleanup",
                            ],
                        }
                    ],
                    "cleanup": {
                        "status": "COMPLETED",
                        "actions": ["Removed synthetic fixture"],
                        "notes": "No product data used.",
                    },
                }
            ],
        }
        results_path = run_dir / "results.json"
        results_path.write_text(
            json.dumps(results, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )
        return results_path


class BlackBoxToolTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="blackbox-tools-test-")
        self.addCleanup(self.temporary.cleanup)
        self.fixture = FixtureRepository(Path(self.temporary.name)).create()

    def assert_symlink(self, link: Path, target: Path, *, target_is_directory: bool = False) -> None:
        try:
            link.symlink_to(target, target_is_directory=target_is_directory)
        except (OSError, NotImplementedError) as exc:
            self.skipTest(f"symbolic links are unavailable for this test account: {exc}")

    def assert_error_contains(self, report: validate.ValidationReport, text: str) -> None:
        self.assertTrue(
            any(text in error for error in report.errors),
            f"expected an error containing {text!r}; got:\n" + "\n".join(report.errors),
        )

    def test_complete_fixture_is_valid(self) -> None:
        suite, report = self.fixture.validate()
        self.assertIsNotNone(suite)
        self.assertEqual([], report.errors)
        assert suite is not None
        self.assertEqual(266, len(suite.cases))
        self.assertEqual(119, len(suite.legacy_rows))
        self.assertEqual({"NOT_RUN"}, {case.effective_verdict for case in suite.cases})

    def test_uncovered_use_case_and_unknown_role_are_rejected(self) -> None:
        uc_data = self.fixture.read_use_cases()
        assert isinstance(uc_data["use_cases"], list)
        uc_data["use_cases"].append(
            {
                "id": "UC-FX-99",
                "figure": "Figure 5a",
                "role": "student",
                "module": "Fixture Module",
                "title": "Uncovered fixture use case",
            }
        )
        self.fixture.write_use_cases(uc_data)
        self.fixture.replace_once("- **Role:** `student`", "- **Role:** `ghost_role`")
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "Role 'ghost_role' is not declared in use-cases.json")
        self.assert_error_contains(report, "use-cases.json declares use case(s) with zero covering black-box tests: UC-FX-99")

    def test_duplicate_id_is_rejected(self) -> None:
        self.fixture.replace_once(
            "## TST-002 — Fixture case 2",
            "## TST-001 — Fixture case 2",
        )
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "duplicate case ID TST-001")

    def test_missing_field_and_wrong_order_are_rejected(self) -> None:
        original = self.fixture.section.read_text(encoding="utf-8")
        with self.subTest("missing field"):
            self.fixture.replace_once("- **Surface:** Isolated fixture surface\n", "")
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "missing required field Surface")
            self.assert_error_contains(report, "field/order mismatch")
        self.fixture.section.write_text(original, encoding="utf-8")
        with self.subTest("wrong order"):
            self.fixture.replace_once(
                "- **Requirement:** FIXTURE.REQUIREMENT.001\n- **Role:** `student`\n",
                "- **Role:** `student`\n- **Requirement:** FIXTURE.REQUIREMENT.001\n",
            )
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "field/order mismatch")

    def test_invalid_lifecycle_and_verdict_are_rejected(self) -> None:
        self.fixture.replace_once("- **Lifecycle:** `READY`", "- **Lifecycle:** `COMPLETE`")
        self.fixture.replace_once("- **Verdict:** `NOT_RUN`", "- **Verdict:** `SUCCESS`")
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "invalid Lifecycle 'COMPLETE'")
        self.assert_error_contains(report, "invalid Verdict 'SUCCESS'")

    def test_fewer_than_two_steps_is_rejected(self) -> None:
        self.fixture.replace_once(
            "1. Perform deterministic fixture action 1.\n2. Perform deterministic fixture action 2.",
            "1. Perform deterministic fixture action 1.",
        )
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "requires at least 2 numbered steps; found 1")

    def test_case_count_drift_is_rejected(self) -> None:
        content = self.fixture.section.read_text(encoding="utf-8")
        marker = "## TST-266 — Fixture case 266"
        self.assertIn(marker, content)
        self.fixture.section.write_text(content[: content.index(marker)].rstrip() + "\n", encoding="utf-8")
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "section count drift")
        self.assert_error_contains(report, "case count drift: expected 266, parsed 265")

    def test_missing_legacy_id_is_rejected(self) -> None:
        lines = self.fixture.legacy.read_text(encoding="utf-8").splitlines()
        lines = [line for line in lines if not line.startswith("| LEG-001 |")]
        self.fixture.legacy.write_text("\n".join(lines) + "\n", encoding="utf-8")
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "legacy map must contain exactly 119 rows; found 118")
        self.assert_error_contains(report, "Legacy ID LEG-001 is missing from the legacy map")

    def test_legacy_disposition_drift_is_rejected(self) -> None:
        self.fixture.replace_once(
            "| LEG-001 | RETAIN | TST-001 |",
            "| LEG-001 | REWRITE | TST-001 |",
            path=self.fixture.legacy,
        )
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "legacy disposition drift for RETAIN")
        self.assert_error_contains(report, "legacy disposition drift for REWRITE")

    def test_archive_hash_mismatch_is_rejected(self) -> None:
        (self.fixture.archive / "history.txt").write_bytes(b"tampered history\n")
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "archive payload digest mismatch for history.txt")

    def test_archive_json_manifest_shape_is_verified(self) -> None:
        suite, report = self.fixture.validate()
        self.assertEqual([], report.errors)
        assert suite is not None
        self.assertEqual("verified", suite.archive_state)

    def test_non_default_source_verdict_requires_results(self) -> None:
        self.fixture.replace_once("- **Verdict:** `NOT_RUN`", "- **Verdict:** `PASS`")
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "Verdict must default to NOT_RUN")

    def test_hash_backed_sanitized_result_overlay_is_accepted(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        suite, report = self.fixture.validate(results_path)
        self.assertEqual([], report.errors)
        assert suite is not None
        verdicts = {case.case_id: case.effective_verdict for case in suite.cases}
        self.assertEqual("PASS", verdicts["TST-001"])
        self.assertEqual("NOT_RUN", verdicts["TST-002"])

    def test_missing_result_artifact_is_rejected(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        (results_path.parent / "evidence" / "TST-001.txt").unlink()
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "not a safe contained regular file")

    def test_renderer_scopes_dangerous_scheme_checks_to_url_attributes(self) -> None:
        renderer = (TOOLS_DIR / "render-html.mjs").read_text(encoding="utf-8")
        self.assertIn("dangerousUrlAttribute", renderer)
        self.assertIn("href|action|cite|poster|data|formaction|xlink:href", renderer)
        self.assertNotIn("const dangerousUrl = /(?:javascript|vbscript)", renderer)

    def test_build_is_deterministic(self) -> None:
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            first_status = build.main(["--root", str(self.fixture.root)])
        self.assertEqual(0, first_status)
        output = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.md"
        first = output.read_bytes()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            second_status = build.main(["--root", str(self.fixture.root)])
        self.assertEqual(0, second_status)
        second = output.read_bytes()
        self.assertEqual(first, second)
        self.assertIn(b"## Computed summary", first)
        self.assertIn(b"## Table of contents", first)
        self.assertNotIn(b"Generated at", first)

    def test_check_mode_compares_without_writing(self) -> None:
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(0, build.main(["--root", str(self.fixture.root)]))
            self.assertEqual(0, build.main(["--root", str(self.fixture.root), "--check"]))
        output = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.md"
        stale = output.read_bytes() + b"stale\n"
        output.write_bytes(stale)
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            status = build.main(["--root", str(self.fixture.root), "--check"])
        self.assertEqual(1, status)
        self.assertEqual(stale, output.read_bytes(), "--check must never rewrite output")

    def test_strict_json_rejects_duplicate_nonfinite_oversize_and_deep_values(self) -> None:
        cases = [
            (b'{"value":1,"value":2}', "duplicate object key"),
            (b'{"value":NaN}', "non-finite JSON number"),
            (b'{"value":Infinity}', "non-finite JSON number"),
        ]
        for payload, expected in cases:
            with self.subTest(payload=payload):
                with self.assertRaisesRegex(secure_io.StrictJSONError, expected):
                    secure_io.strict_json_loads(payload)
        with self.assertRaisesRegex(secure_io.StrictJSONError, "byte limit"):
            secure_io.strict_json_loads(b'{"value":"12345"}', max_bytes=8)
        with self.assertRaisesRegex(secure_io.StrictJSONError, "depth limit"):
            secure_io.strict_json_loads(b'[[[[0]]]]', max_depth=3)

    def test_duplicate_key_in_results_is_rejected(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        text = results_path.read_text(encoding="utf-8")
        text = text.replace('  "run_id": "run-001",', '  "run_id": "run-001",\n  "run_id": "shadow",', 1)
        results_path.write_text(text, encoding="utf-8")
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "duplicate object key 'run_id'")

    def test_result_plain_text_injection_is_rejected_and_never_built(self) -> None:
        attacks = [
            '<meta http-equiv="refresh" content="0;url=https://evil.invalid">',
            '<style>body{display:none}</style>',
            '![beacon](https://evil.invalid/pixel)',
            '[click](javascript:alert(1))',
            '<https://evil.invalid/resource>',
            'line one\nline two',
            'javascript:alert(1)',
        ]
        for attack in attacks:
            with self.subTest(attack=attack):
                results_path = self.fixture.create_valid_result_overlay()
                results = self.fixture.read_result_json(results_path)
                results["cases"][0]["actual"] = attack
                self.fixture.write_result_json(results_path, results)
                _, report = self.fixture.validate(results_path)
                self.assertTrue(report.errors, "malicious overlay text must be rejected")
                output = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.md"
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(
                        1,
                        build.main(["--root", str(self.fixture.root), "--results", str(results_path)]),
                    )
                self.assertFalse(output.exists())
                shutil.rmtree(results_path.parent)

    def test_valid_overlay_markdown_escapes_user_punctuation(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        results = self.fixture.read_result_json(results_path)
        results["cases"][0]["actual"] = "Observed *literal* [brackets] and # marker."
        self.fixture.write_result_json(results_path, results)
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            status = build.main(["--root", str(self.fixture.root), "--results", str(results_path)])
        self.assertEqual(0, status)
        markdown = (self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.md").read_text(encoding="utf-8")
        self.assertIn(r"Observed \*literal\* \[brackets\] and \# marker\.", markdown)

    def test_evidence_path_has_no_repository_fallback(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        evidence_path = results_path.parent / "evidence" / "TST-001.txt"
        repository_fallback = self.fixture.root / "evidence" / "TST-001.txt"
        repository_fallback.parent.mkdir(parents=True)
        repository_fallback.write_bytes(evidence_path.read_bytes())
        evidence_path.unlink()
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "not a safe contained regular file")

    def test_evidence_paths_reject_unsafe_component_forms(self) -> None:
        attacks = [
            "../outside.txt",
            "/absolute.txt",
            "C:/outside.txt",
            "evidence/file.txt:stream",
            "evidence/./file.txt",
            "evidence/CON.txt",
            "evidence/control\u0001.txt",
        ]
        for attack in attacks:
            with self.subTest(attack=attack):
                results_path = self.fixture.create_valid_result_overlay()
                manifest = self.fixture.read_run_manifest(results_path)
                manifest["artifacts"][0]["path"] = attack
                self.fixture.write_run_manifest(results_path, manifest)
                _, report = self.fixture.validate(results_path)
                self.assertTrue(
                    any("unsafe path component" in error or "absolute, drive, ADS" in error or "reserved device" in error for error in report.errors),
                    "unsafe evidence path must be rejected: " + "\n".join(report.errors),
                )
                shutil.rmtree(results_path.parent)

    def test_evidence_symlink_leaf_and_parent_are_rejected_without_hash_disclosure(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        run_root = results_path.parent
        sensitive = self.fixture.root / "sensitive-host-file.txt"
        sensitive.write_bytes(b"never hash or disclose this host file\n")
        sensitive_digest = hashlib.sha256(sensitive.read_bytes()).hexdigest()
        leaf = run_root / "evidence" / "TST-001.txt"
        leaf.unlink()
        self.assert_symlink(leaf, sensitive)
        _, report = self.fixture.validate(results_path)
        rendered = "\n".join(report.errors)
        self.assertIn("symlink or reparse point", rendered)
        self.assertNotIn(sensitive_digest, rendered)

        leaf.unlink()
        (run_root / "evidence").rename(run_root / "real-evidence")
        self.assert_symlink(run_root / "evidence", run_root / "real-evidence", target_is_directory=True)
        _, report = self.fixture.validate(results_path)
        rendered = "\n".join(report.errors)
        self.assertIn("directory component is a symlink or reparse point", rendered)
        self.assertNotIn(sensitive_digest, rendered)

    def test_empty_and_hard_linked_evidence_are_rejected(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        evidence = results_path.parent / "evidence" / "TST-001.txt"
        evidence.write_bytes(b"")
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "must be nonempty")

        evidence.write_bytes(b"linked evidence\n")
        alias = results_path.parent / "evidence" / "alias.txt"
        try:
            os.link(evidence, alias)
        except OSError as exc:
            self.skipTest(f"hard links unavailable: {exc}")
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "exactly one hard link")

    def test_dirty_result_requires_matching_nonempty_dirty_patch(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        manifest = self.fixture.read_run_manifest(results_path)
        manifest["dirty"] = True
        manifest["dirty_patch_sha256"] = "d" * 64
        self.fixture.write_run_manifest(results_path, manifest)
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "dirty run must include a nonempty verified dirty.patch")

        patch = results_path.parent / "dirty.patch"
        patch.write_bytes(b"diff --git a/file b/file\n")
        patch_hash = hashlib.sha256(patch.read_bytes()).hexdigest()
        manifest = self.fixture.read_run_manifest(results_path)
        manifest["dirty_patch_sha256"] = patch_hash
        manifest["artifacts"].append(
            {
                "path": "dirty.patch",
                "sha256": patch_hash,
                "size_bytes": patch.stat().st_size,
                "media_type": "text/plain",
                "case_ids": ["TST-001"],
            }
        )
        self.fixture.write_run_manifest(results_path, manifest)
        _, report = self.fixture.validate(results_path)
        self.assertEqual([], report.errors)

    def test_pass_requires_every_step_and_assertion_coverage(self) -> None:
        results_path = self.fixture.create_valid_result_overlay()
        results = self.fixture.read_result_json(results_path)
        results["cases"][0]["evidence"][0]["covers"] = [
            "step:1",
            "assertion:cleanup",
        ]
        self.fixture.write_result_json(results_path, results)
        _, report = self.fixture.validate(results_path)
        self.assert_error_contains(report, "does not cover every step/assertion")

    def test_archive_missing_competing_manifest_false_flags_extras_and_metadata_drift(self) -> None:
        with self.subTest("missing archive"):
            shutil.rmtree(self.fixture.archive)
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "declared archive does not exist")
        self.fixture.create_valid_archive()

        with self.subTest("competing manifest"):
            (self.fixture.archive / "MANIFEST.sha256").write_text("unused\n", encoding="utf-8")
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "exactly one JSON manifest")
            (self.fixture.archive / "MANIFEST.sha256").unlink()

        with self.subTest("false frozen"):
            manifest = self.fixture.read_archive_manifest()
            manifest["frozen"] = False
            self.fixture.write_archive_manifest(manifest)
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "frozen must be true")
        self.fixture.create_valid_archive()

        with self.subTest("extra file"):
            (self.fixture.archive / "extra.txt").write_text("extra\n", encoding="utf-8")
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "outside its exact integrity list")
            (self.fixture.archive / "extra.txt").unlink()

        with self.subTest("support metadata drift"):
            (self.fixture.archive / "README.md").write_text("drift\n", encoding="utf-8")
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "supporting-file digest mismatch")
        self.fixture.create_valid_archive()

        with self.subTest("declared total drift"):
            manifest = self.fixture.read_archive_manifest()
            manifest["final_totals"]["pass"] = 2
            self.fixture.write_archive_manifest(manifest)
            _, report = self.fixture.validate()
            self.assert_error_contains(report, "verdict totals are inconsistent")

    def test_actual_archive_contract_rejects_canonical_count_and_total_drift(self) -> None:
        suite, report = self.fixture.validate()
        self.assertEqual([], report.errors)
        assert suite is not None
        original_path = suite.archive_path
        original_manifest = self.fixture.read_archive_manifest()
        validate.KNOWN_ARCHIVE_EXPECTATIONS[original_path] = {
            "canonical_file_count": 2,
            "payload_paths": {"history.txt", "required-second.txt"},
            "run_range": {"start": "2026-11-01", "end": "2026-11-02"},
            "final_totals": original_manifest["final_totals"],
        }
        self.addCleanup(validate.KNOWN_ARCHIVE_EXPECTATIONS.pop, original_path, None)
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "canonical_file_count must be 2")
        self.assert_error_contains(report, "canonical payload set is missing")

    def test_archive_byte_preserved_and_redacted_flags_are_consistent(self) -> None:
        manifest = self.fixture.read_archive_manifest()
        entry = manifest["files"][0]
        entry["byte_preserved"] = False
        entry["redaction_status"] = "redacted"
        self.fixture.write_archive_manifest(manifest)
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "redacted payload must differ from the original")

    def test_archive_symlink_is_rejected(self) -> None:
        outside = self.fixture.root / "outside-archive.txt"
        outside.write_text("outside\n", encoding="utf-8")
        link = self.fixture.archive / "linked.txt"
        self.assert_symlink(link, outside)
        _, report = self.fixture.validate()
        self.assert_error_contains(report, "archive must not contain symlinks or reparse points")

    def test_build_rejects_destination_symlink_and_preserves_target(self) -> None:
        output = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.md"
        sensitive = self.fixture.root / "sensitive-target.txt"
        sensitive.write_bytes(b"do not overwrite\n")
        self.assert_symlink(output, sensitive)
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            status = build.main(["--root", str(self.fixture.root)])
        self.assertEqual(1, status)
        self.assertEqual(b"do not overwrite\n", sensitive.read_bytes())
        self.assertTrue(output.is_symlink())

    def test_atomic_write_uses_random_exclusive_temp_and_rejects_predictable_symlink(self) -> None:
        output = self.fixture.blackbox / "safe-output.txt"
        victim = self.fixture.root / "victim.txt"
        victim.write_bytes(b"victim\n")
        predictable = output.with_name(output.name + ".tmp")
        self.assert_symlink(predictable, victim)
        secure_io.atomic_write_bytes(output, b"new value\n", root=self.fixture.root)
        self.assertEqual(b"new value\n", output.read_bytes())
        self.assertEqual(b"victim\n", victim.read_bytes())
        self.assertTrue(predictable.is_symlink())
        self.assertEqual([], list(output.parent.glob(f".{output.name}.*.tmp")))

    def test_atomic_write_rejects_existing_hard_link_destination(self) -> None:
        output = self.fixture.blackbox / "hard-linked-output.txt"
        alias = self.fixture.root / "hard-link-alias.txt"
        output.write_bytes(b"original\n")
        try:
            os.link(output, alias)
        except OSError as exc:
            self.skipTest(f"hard links unavailable: {exc}")
        with self.assertRaisesRegex(secure_io.FileSafetyError, "exactly one hard link"):
            secure_io.atomic_write_bytes(output, b"replacement\n", root=self.fixture.root)
        self.assertEqual(b"original\n", output.read_bytes())
        self.assertEqual(b"original\n", alias.read_bytes())

    def test_atomic_write_rejects_symlink_parent(self) -> None:
        real_parent = self.fixture.root / "real-parent"
        real_parent.mkdir()
        linked_parent = self.fixture.root / "linked-parent"
        self.assert_symlink(linked_parent, real_parent, target_is_directory=True)
        with self.assertRaisesRegex(secure_io.FileSafetyError, "symlink or reparse point"):
            secure_io.atomic_write_bytes(linked_parent / "output.txt", b"no\n", root=self.fixture.root)
        self.assertFalse((real_parent / "output.txt").exists())

    def test_renderer_requires_validation_and_freshness_and_escapes_raw_html(self) -> None:
        renderer = (TOOLS_DIR / "render-html.mjs").read_text(encoding="utf-8")
        self.assertIn("await requireFreshValidatedMarkdown(options)", renderer)
        self.assertIn('renderer.html = ({ text }) => `<p>${escapeHtml(text)}</p>', renderer)
        self.assertIn("renderer.image", renderer)
        self.assertIn("strict-json-normalize", renderer)
        self.assertNotIn("Validated execution document", renderer)
        self.assertIn("PASS", renderer)
        self.assertIn("NOT_IN_RUN", renderer)
        self.assertIn("not accepted execution evidence", renderer)

    def test_publisher_pandoc_disables_raw_html_and_uses_isolated_resources(self) -> None:
        source = (TOOLS_DIR / "publish.py").read_text(encoding="utf-8")
        self.assertIn('"--from=gfm-raw_html-raw_attribute"', source)
        self.assertIn('"--sandbox"', source)
        self.assertIn('"--resource-path"', source)
        self.assertIn("externally enforced no-network sandbox", source)

    def _write_docx_fixture(self, path: Path, *, external: bool = False, macro: bool = False) -> None:
        relationship = (
            '<Relationship Id="rId1" Type="urn:test" Target="https://evil.invalid" TargetMode="External"/>'
            if external
            else '<Relationship Id="rId1" Type="urn:test" Target="document.xml"/>'
        )
        with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("[Content_Types].xml", "<Types/>")
            archive.writestr(
                "_rels/.rels",
                f'<Relationships xmlns="{publish.RELATIONSHIPS_NS}">{relationship}</Relationships>',
            )
            archive.writestr("word/document.xml", "<document/>")
            archive.writestr(
                "word/_rels/document.xml.rels",
                f'<Relationships xmlns="{publish.RELATIONSHIPS_NS}"></Relationships>',
            )
            if macro:
                archive.writestr("word/vbaProject.bin", b"macro")

    def _write_pdf_fixture(self, path: Path, *, active: bool = False) -> None:
        prefix = b"%PDF-1.7\n1 0 obj\n<< /Type /Catalog"
        if active:
            prefix += b" /OpenAction 2 0 R"
        prefix += b" >>\nendobj\n" + (b"% deterministic padding\n" * 4)
        xref_offset = len(prefix)
        payload = prefix + b"xref\n0 1\n0000000000 65535 f \ntrailer\n<< /Size 1 >>\nstartxref\n" + str(xref_offset).encode("ascii") + b"\n%%EOF\n"
        path.write_bytes(payload)

    def test_publisher_rejects_docx_macros_external_relationships_and_bad_pdf(self) -> None:
        stage = self.fixture.root / "stage"
        stage.mkdir()
        external = stage / "external.docx"
        self._write_docx_fixture(external, external=True)
        with self.assertRaisesRegex(publish.PublishError, "external relationship"):
            publish._validate_docx(external, stage)
        macro = stage / "macro.docx"
        self._write_docx_fixture(macro, macro=True)
        with self.assertRaisesRegex(publish.PublishError, "macro"):
            publish._validate_docx(macro, stage)
        active_pdf = stage / "active.pdf"
        self._write_pdf_fixture(active_pdf, active=True)
        with self.assertRaisesRegex(publish.PublishError, "active or embedded-content"):
            publish._validate_pdf(active_pdf, stage)

    def test_publisher_accepts_minimal_structural_docx_and_pdf(self) -> None:
        stage = self.fixture.root / "stage-ok"
        stage.mkdir()
        docx = stage / "ok.docx"
        pdf = stage / "ok.pdf"
        self._write_docx_fixture(docx)
        self._write_pdf_fixture(pdf)
        self.assertEqual(docx.read_bytes(), publish._validate_docx(docx, stage))
        self.assertEqual(pdf.read_bytes(), publish._validate_pdf(pdf, stage))

    def test_publisher_rejects_destination_symlink(self) -> None:
        destination = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.docx"
        sensitive = self.fixture.root / "sensitive-docx.bin"
        sensitive.write_bytes(b"unchanged\n")
        self.assert_symlink(destination, sensitive)
        with self.assertRaisesRegex(publish.PublishError, "destination is unsafe"):
            publish._publish_transaction(self.fixture.root, {destination: b"new\n"})
        self.assertEqual(b"unchanged\n", sensitive.read_bytes())

    def test_publisher_rejects_hard_link_destination_aliases(self) -> None:
        first = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.docx"
        second = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.pdf"
        first.write_bytes(b"shared destination\n")
        try:
            os.link(first, second)
        except OSError as exc:
            self.skipTest(f"hard links unavailable: {exc}")
        with self.assertRaisesRegex(
            (publish.PublishError, secure_io.FileSafetyError),
            "hard link|alias",
        ):
            publish._publish_transaction(
                self.fixture.root,
                {first: b"docx\n", second: b"pdf\n"},
            )

    def test_publisher_surfaces_rollback_failure(self) -> None:
        first = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.docx"
        second = self.fixture.blackbox / "SYNAPSE-BlackBox-Testing.pdf"
        first.write_bytes(b"old-docx\n")
        second.write_bytes(b"old-pdf\n")
        original_atomic = secure_io.atomic_write_bytes
        calls = 0

        def failing_atomic(destination: Path, payload: bytes, *, root: Path | str, mode: int = 0o600) -> None:
            nonlocal calls
            calls += 1
            if calls == 2:
                raise secure_io.FileSafetyError("publication fault")
            if calls == 3:
                raise secure_io.FileSafetyError("rollback fault")
            original_atomic(destination, payload, root=root, mode=mode)

        with mock.patch.object(secure_io, "atomic_write_bytes", side_effect=failing_atomic):
            with self.assertRaisesRegex(publish.PublishError, "rollback also failed"):
                publish._publish_transaction(
                    self.fixture.root,
                    {first: b"new-docx\n", second: b"new-pdf\n"},
                )

    def test_supported_converter_versions_are_enforced(self) -> None:
        with mock.patch.object(publish, "_find_program", side_effect=["pandoc", "soffice"]), mock.patch.object(
            publish,
            "_program_version",
            side_effect=[("pandoc 2.19", 2), ("LibreOffice 24.2", 24)],
        ):
            with self.assertRaisesRegex(publish.PublishError, "unsupported Pandoc"):
                publish.check_prerequisites()

    def test_template_docx_builder_uses_narrow_margins_and_passes_docx_validation(self) -> None:
        tools_dir = str(TOOLS_DIR)
        if tools_dir not in sys.path:
            sys.path.insert(0, tools_dir)
        import docx_template
        import xml.etree.ElementTree as ET

        suite_data, report = validate.validate_project(self.fixture.root)
        self.assertEqual([], report.errors)
        assert suite_data is not None

        stage = self.fixture.root / "stage-template"
        stage.mkdir()
        staged_docx = stage / "SYNAPSE-BlackBox-Testing.docx"
        docx_template.build_template_docx(suite_data, staged_docx, title="Black Box Testing")

        payload = publish._validate_docx(staged_docx, stage)
        with zipfile.ZipFile(io.BytesIO(payload), "r") as archive:
            doc_xml = ET.fromstring(archive.read("word/document.xml"))

        ns = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
        w_ns = f"{{{ns['w']}}}"
        pg_sz = doc_xml.find(".//w:sectPr/w:pgSz", ns)
        pg_mar = doc_xml.find(".//w:sectPr/w:pgMar", ns)
        self.assertIsNotNone(pg_sz)
        self.assertIsNotNone(pg_mar)
        assert pg_sz is not None and pg_mar is not None
        self.assertEqual("12240", pg_sz.attrib[f"{w_ns}w"])
        self.assertEqual("15840", pg_sz.attrib[f"{w_ns}h"])
        for edge in ("top", "bottom", "left", "right"):
            self.assertEqual("720", pg_mar.attrib[f"{w_ns}{edge}"])

        expected_headers = ["Test Case ID", "Module / Function", "Test Scenario", "Expected Results", "Pass", "Remarks"]
        matched_case_table = False
        for tbl in doc_xml.findall(".//w:tbl", ns):
            for row in tbl.findall("w:tr", ns):
                if row.find("w:trPr/w:tblHeader", ns) is None:
                    continue
                cells = row.findall("w:tc", ns)
                texts = ["".join(t.text or "" for t in tc.findall(".//w:t", ns)) for tc in cells]
                if texts == expected_headers:
                    matched_case_table = True
                    for tc in cells:
                        shd = tc.find("w:tcPr/w:shd", ns)
                        self.assertIsNotNone(shd)
                        assert shd is not None
                        self.assertEqual("000000", shd.attrib[f"{w_ns}fill"])
        self.assertTrue(matched_case_table, "expected template 6-column test-case header table")

        sizes = {sz.attrib.get(f"{w_ns}val") for sz in doc_xml.findall(".//w:sz", ns)}
        self.assertIn("21", sizes)
        self.assertIn("22", sizes)
        fonts = {rf.attrib.get(f"{w_ns}ascii") for rf in doc_xml.findall(".//w:rFonts", ns)}
        self.assertIn("Times New Roman", fonts)

    def test_defense_sheet_covers_all_70_use_cases_and_builds_valid_docx(self) -> None:
        tools_dir = str(TOOLS_DIR)
        if tools_dir not in sys.path:
            sys.path.insert(0, tools_dir)
        import build_defense_sheet
        import xml.etree.ElementTree as ET

        repo_root = TOOLS_DIR.parents[1]
        sheet = build_defense_sheet.validate_defense_sheet(repo_root)
        catalog = sheet.suite_data.use_case_catalog
        assert isinstance(catalog, dict)
        self.assertEqual(70, len(catalog["use_cases"]))
        covered_ucs = {uc for case in sheet.cases for uc in case["use_cases"]}
        self.assertEqual({uc["id"] for uc in catalog["use_cases"]}, covered_ucs)

        stage = self.fixture.root / "stage-defense"
        stage.mkdir()
        staged_docx = stage / "SYNAPSE-BlackBox-Testing-Defense.docx"
        build_defense_sheet.build_defense_docx(sheet, staged_docx)
        payload = publish._validate_docx(staged_docx, stage)

        with zipfile.ZipFile(io.BytesIO(payload), "r") as archive:
            doc_xml = ET.fromstring(archive.read("word/document.xml"))

        ns = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
        w_ns = f"{{{ns['w']}}}"
        pg_sz = doc_xml.find(".//w:sectPr/w:pgSz", ns)
        pg_mar = doc_xml.find(".//w:sectPr/w:pgMar", ns)
        assert pg_sz is not None and pg_mar is not None
        self.assertEqual("12240", pg_sz.attrib[f"{w_ns}w"])
        self.assertEqual("15840", pg_sz.attrib[f"{w_ns}h"])
        for edge in ("top", "bottom", "left", "right"):
            self.assertEqual("720", pg_mar.attrib[f"{w_ns}{edge}"])

        expected_headers = ["Test Case ID", "Module / Function", "Test Scenario", "Expected Results", "Pass", "Remarks"]
        matched_case_table = False
        for tbl in doc_xml.findall(".//w:tbl", ns):
            for row in tbl.findall("w:tr", ns):
                if row.find("w:trPr/w:tblHeader", ns) is None:
                    continue
                texts = ["".join(t.text or "" for t in tc.findall(".//w:t", ns)) for tc in row.findall("w:tc", ns)]
                if texts == expected_headers:
                    matched_case_table = True
        self.assertTrue(matched_case_table, "expected 6-column black-box table header in defense DOCX")
        sizes = {sz.attrib.get(f"{w_ns}val") for sz in doc_xml.findall(".//w:sz", ns)}
        self.assertIn("21", sizes)
        self.assertIn("22", sizes)
        fonts = {rf.attrib.get(f"{w_ns}ascii") for rf in doc_xml.findall(".//w:rFonts", ns)}
        self.assertIn("Times New Roman", fonts)


if __name__ == "__main__":
    unittest.main()
