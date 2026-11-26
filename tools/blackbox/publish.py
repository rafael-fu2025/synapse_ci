#!/usr/bin/env python3
"""Publish current black-box Markdown as hardened DOCX and PDF outputs.

The caller must provide an OS-level no-network sandbox for Pandoc and
LibreOffice.  This tool additionally disables raw HTML, uses an empty isolated
working/resource directory, validates generated container structure, and only
publishes files through no-follow randomized atomic replacement.  No process
flag can prove network isolation, so publication refuses to describe itself as
network-safe without that external control.
"""

from __future__ import annotations

import argparse
import io
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import unicodedata
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path, PurePosixPath
from typing import Any, Sequence

import secure_io

REQUIRED_OUTPUTS = {
    "markdown": "papers/blackbox/SYNAPSE-BlackBox-Testing.md",
    "docx": "papers/blackbox/SYNAPSE-BlackBox-Testing.docx",
    "pdf": "papers/blackbox/SYNAPSE-BlackBox-Testing.pdf",
}
WINDOWS_DEVICE_NAMES = {
    "CON",
    "PRN",
    "AUX",
    "NUL",
    *(f"COM{index}" for index in range(1, 10)),
    *(f"LPT{index}" for index in range(1, 10)),
}
MAX_SUITE_BYTES = 4 * 1024 * 1024
MAX_MARKDOWN_BYTES = 32 * 1024 * 1024
MAX_PUBLISHED_BYTES = 256 * 1024 * 1024
MIN_PDF_BYTES = 128
MAX_ZIP_MEMBERS = 20_000
MAX_ZIP_UNCOMPRESSED = 512 * 1024 * 1024
MAX_ZIP_RATIO = 200
REQUIRED_DOCX_PARTS = {
    "[Content_Types].xml",
    "_rels/.rels",
    "word/document.xml",
    "word/_rels/document.xml.rels",
}
RELATIONSHIPS_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
SUPPORTED_PANDOC_MAJOR = {3}
SUPPORTED_LIBREOFFICE_MAJOR = {24, 25, 26}


class PublishError(RuntimeError):
    """A clear, expected publication failure."""


def _default_root() -> Path:
    return Path(__file__).resolve().parents[2]


def _normalise_relative_path(value: Any, label: str) -> str:
    if not isinstance(value, str) or not value or value != value.strip():
        raise PublishError(
            f"{label} must be a non-empty repository-relative path without outer whitespace"
        )
    if "\\" in value:
        raise PublishError(f"{label} must use forward slashes only")
    if (
        value.startswith("/")
        or re.match(r"^[A-Za-z]:", value)
        or ":" in value
        or any(unicodedata.category(character).startswith("C") for character in value)
    ):
        raise PublishError(f"{label} contains absolute, drive, ADS, or control syntax")
    parts = value.split("/")
    if not parts or any(part in ("", ".", "..") for part in parts):
        raise PublishError(f"{label} contains an unsafe path component")
    for part in parts:
        if part[-1:] in (" ", "."):
            raise PublishError(f"{label} contains a component ending in a dot or space")
        if part.split(".", 1)[0].upper() in WINDOWS_DEVICE_NAMES:
            raise PublishError(f"{label} contains a reserved device component")
    return PurePosixPath(*parts).as_posix()


def _resolve(root: Path, value: Any, label: str) -> tuple[str, Path]:
    normalised = _normalise_relative_path(value, label)
    candidate = secure_io.absolute_lexical(
        root / Path(*PurePosixPath(normalised).parts)
    )
    try:
        candidate.relative_to(root)
    except ValueError as exc:
        raise PublishError(f"{label} escapes the repository root") from exc
    return normalised, candidate


def _load_suite(root: Path) -> tuple[dict[str, Any], dict[str, Path], Path]:
    suite_path = root / "papers" / "blackbox" / "suite.json"
    try:
        suite = secure_io.load_strict_json_file(
            suite_path,
            root=root,
            label="suite manifest",
            max_bytes=MAX_SUITE_BYTES,
            max_depth=64,
        )
    except (OSError, secure_io.FileSafetyError, secure_io.StrictJSONError) as exc:
        raise PublishError(f"suite manifest is not safe strict JSON: {exc}") from exc
    if not isinstance(suite, dict):
        raise PublishError("suite manifest must contain a JSON object")
    outputs = suite.get("outputs")
    resolved_outputs: dict[str, Path] = {}
    if isinstance(outputs, dict):
        declared_outputs = dict(outputs)
    else:
        generated = suite.get("generated_root_outputs")
        if not isinstance(generated, list):
            raise PublishError("suite.json must declare outputs or generated_root_outputs")
        declared_outputs = {}
        for item in generated:
            if isinstance(item, str):
                suffix = PurePosixPath(item).suffix.lower()
                kind = {".md": "markdown", ".html": "html"}.get(suffix)
                if kind:
                    declared_outputs[kind] = item
        declared_outputs.setdefault("docx", REQUIRED_OUTPUTS["docx"])
        declared_outputs.setdefault("pdf", REQUIRED_OUTPUTS["pdf"])
    for kind, required in REQUIRED_OUTPUTS.items():
        declared = declared_outputs.get(kind)
        if declared != required:
            raise PublishError(
                f"suite.json outputs.{kind} must be {required!r}; found {declared!r}"
            )
        _, resolved_outputs[kind] = _resolve(root, declared, f"outputs.{kind}")
    if len(set(resolved_outputs.values())) != len(resolved_outputs):
        raise PublishError("published output paths must be distinct")
    archive = suite.get("archive_path", suite.get("archive"))
    if isinstance(archive, dict):
        archive = archive.get("path", archive.get("directory"))
    _, archive_path = _resolve(root, archive, "archive_path")
    return suite, resolved_outputs, archive_path


def _within(candidate: Path, parent: Path) -> bool:
    try:
        secure_io.absolute_lexical(candidate).relative_to(secure_io.absolute_lexical(parent))
        return True
    except ValueError:
        return False


def _protect_archive(destinations: Sequence[Path], archive_path: Path, root: Path) -> None:
    try:
        secure_io.verify_directory(root, archive_path)
    except (OSError, secure_io.FileSafetyError) as exc:
        raise PublishError(f"declared archive is missing or unsafe: {exc}") from exc
    for destination in destinations:
        if destination == archive_path or _within(destination, archive_path):
            raise PublishError(
                f"refusing to overwrite archive content: {destination} conflicts with {archive_path}"
            )
        try:
            destination_state = secure_io.inspect_regular_file(
                destination, root=root, allow_missing=True, require_single_link=True
            )
        except (OSError, secure_io.FileSafetyError) as exc:
            raise PublishError(f"published destination is unsafe: {destination}: {exc}") from exc
        if destination_state is not None:
            for archive_file in archive_path.rglob("*"):
                if not archive_file.is_file() or archive_file.is_symlink():
                    continue
                try:
                    archive_state = os.lstat(archive_file)
                except OSError as exc:
                    raise PublishError(
                        f"could not verify archive alias safety for {archive_file}: {exc}"
                    ) from exc
                if (
                    destination_state.st_dev == archive_state.st_dev
                    and destination_state.st_ino == archive_state.st_ino
                ):
                    raise PublishError(
                        f"refusing destination that hard-links archive content: {destination}"
                    )


def _find_program(names: Sequence[str], label: str) -> str:
    for name in names:
        found = shutil.which(name)
        if found:
            return found
    raise PublishError(
        f"missing prerequisite: {label} ({', '.join(names)}) was not found on PATH"
    )


def _safe_subprocess_environment(home: Path | None = None) -> dict[str, str]:
    environment: dict[str, str] = {}
    for name in ("PATH", "PATHEXT", "SYSTEMROOT", "SystemRoot", "WINDIR", "COMSPEC"):
        value = os.environ.get(name)
        if value:
            environment[name] = value
    if home is not None:
        environment.update(
            {
                "HOME": str(home),
                "USERPROFILE": str(home),
                "TMP": str(home),
                "TEMP": str(home),
                "XDG_CACHE_HOME": str(home / "cache"),
                "XDG_CONFIG_HOME": str(home / "config"),
                "XDG_DATA_HOME": str(home / "data"),
            }
        )
    return environment


def _run(
    command: Sequence[str],
    label: str,
    *,
    timeout: int = 300,
    cwd: Path | None = None,
    environment: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    try:
        completed = subprocess.run(
            list(command),
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
            shell=False,
            cwd=cwd,
            env=environment or _safe_subprocess_environment(),
        )
    except subprocess.TimeoutExpired as exc:
        raise PublishError(f"{label} timed out after {timeout} seconds") from exc
    except OSError as exc:
        raise PublishError(f"could not start {label}: {exc}") from exc
    if completed.returncode != 0:
        detail = "\n".join(
            value.strip()
            for value in (completed.stdout, completed.stderr)
            if value.strip()
        )
        raise PublishError(
            f"{label} failed with exit {completed.returncode}"
            + (f":\n{detail}" if detail else " without diagnostic output")
        )
    return completed


def _program_version(executable: str, label: str) -> tuple[str, int]:
    completed = _run([executable, "--version"], f"{label} version check", timeout=30)
    lines = (completed.stdout or completed.stderr).splitlines()
    first = lines[0].strip() if lines else f"{label} (version output unavailable)"
    match = re.search(r"(?<!\d)(\d+)(?:\.\d+)+", first)
    if not match:
        raise PublishError(f"could not parse {label} version from: {first!r}")
    return first, int(match.group(1))


def check_prerequisites(*, require_pandoc: bool = True) -> tuple[str | None, str]:
    pandoc: str | None = None
    if require_pandoc:
        pandoc = _find_program(("pandoc",), "Pandoc")
        pandoc_version, pandoc_major = _program_version(pandoc, "Pandoc")
        if pandoc_major not in SUPPORTED_PANDOC_MAJOR:
            raise PublishError(
                f"unsupported Pandoc major version {pandoc_major}; tested majors: {sorted(SUPPORTED_PANDOC_MAJOR)}"
            )
        print(f"Pandoc: {pandoc_version} [{pandoc}]")
    libreoffice = _find_program(("libreoffice", "soffice"), "LibreOffice")
    libreoffice_version, libreoffice_major = _program_version(
        libreoffice, "LibreOffice"
    )
    if libreoffice_major not in SUPPORTED_LIBREOFFICE_MAJOR:
        raise PublishError(
            "unsupported LibreOffice major version "
            f"{libreoffice_major}; tested majors: {sorted(SUPPORTED_LIBREOFFICE_MAJOR)}"
        )
    print(f"LibreOffice: {libreoffice_version} [{libreoffice}]")
    return pandoc, libreoffice


def _check_markdown_current(root: Path, results: Path | None) -> None:
    validator = [
        sys.executable,
        str(Path(__file__).with_name("validate.py")),
        "--root",
        str(root),
    ]
    builder = [
        sys.executable,
        str(Path(__file__).with_name("build.py")),
        "--root",
        str(root),
    ]
    if results is not None:
        validator.extend(["--results", str(results)])
        builder.extend(["--results", str(results)])
    _run(validator, "black-box validation", timeout=180, cwd=Path(__file__).parent)
    builder.append("--check")
    _run(
        builder,
        "deterministic Markdown freshness check",
        timeout=180,
        cwd=Path(__file__).parent,
    )


def _safe_staged_bytes(path: Path, stage: Path, label: str) -> bytes:
    try:
        return secure_io.read_regular_bytes(
            path,
            root=stage,
            max_bytes=MAX_PUBLISHED_BYTES,
            require_nonempty=True,
            require_single_link=True,
        )
    except (OSError, secure_io.FileSafetyError) as exc:
        raise PublishError(f"staged {label} is unsafe: {exc}") from exc


def _validate_docx(path: Path, stage: Path) -> bytes:
    payload = _safe_staged_bytes(path, stage, "DOCX")
    try:
        with zipfile.ZipFile(io.BytesIO(payload), "r") as archive:
            members = archive.infolist()
            names = {member.filename for member in members}
            folded_names = [name.casefold() for name in names]
            if len(folded_names) != len(set(folded_names)):
                raise PublishError("DOCX contains case-colliding ZIP member names")
            if len(members) > MAX_ZIP_MEMBERS:
                raise PublishError("DOCX contains too many ZIP members")
            if len(names) != len(members):
                raise PublishError("DOCX contains duplicate ZIP member names")
            if not REQUIRED_DOCX_PARTS.issubset(names):
                missing = sorted(REQUIRED_DOCX_PARTS - names)
                raise PublishError(f"DOCX is missing required OOXML parts: {', '.join(missing)}")
            if any(
                name.casefold().endswith(("vbaproject.bin", ".xlsm", ".docm"))
                or "activex" in name.casefold()
                or "embeddings/" in name.casefold()
                for name in names
            ):
                raise PublishError("DOCX contains macro, ActiveX, or embedded-object content")
            total_uncompressed = 0
            for member in members:
                raw_name = member.filename.replace("\\", "/")
                pure = PurePosixPath(raw_name)
                if (
                    pure.is_absolute()
                    or ".." in pure.parts
                    or ":" in raw_name
                    or any(part in ("", ".") for part in pure.parts)
                    or member.is_dir()
                    or (member.external_attr >> 16) & 0o170000 == stat.S_IFLNK
                ):
                    raise PublishError(f"DOCX contains an unsafe ZIP member name: {raw_name}")
                total_uncompressed += member.file_size
                if member.file_size > 0 and member.compress_size == 0:
                    raise PublishError(f"DOCX member has an impossible compression size: {raw_name}")
                if (
                    member.compress_size > 0
                    and member.file_size / member.compress_size > MAX_ZIP_RATIO
                ):
                    raise PublishError(f"DOCX member exceeds the compression-ratio limit: {raw_name}")
                if total_uncompressed > MAX_ZIP_UNCOMPRESSED:
                    raise PublishError("DOCX uncompressed content exceeds the safety limit")
            for name in names:
                if not name.endswith(".rels"):
                    continue
                try:
                    root = ET.fromstring(archive.read(name))
                except (ET.ParseError, KeyError) as exc:
                    raise PublishError(f"DOCX relationship part is invalid XML: {name}") from exc
                for relationship in root.findall(f"{{{RELATIONSHIPS_NS}}}Relationship"):
                    if relationship.attrib.get("TargetMode", "Internal").casefold() == "external":
                        raise PublishError(f"DOCX contains an external relationship in {name}")
                    target = relationship.attrib.get("Target", "")
                    if re.match(r"^[A-Za-z][A-Za-z0-9+.-]*:", target):
                        raise PublishError(f"DOCX contains a URI relationship target in {name}")
            bad = archive.testzip()
            if bad is not None:
                raise PublishError(f"DOCX ZIP member failed CRC validation: {bad}")
    except zipfile.BadZipFile as exc:
        raise PublishError("staged DOCX is not a valid ZIP container") from exc
    return payload


def _validate_pdf(path: Path, stage: Path) -> bytes:
    payload = _safe_staged_bytes(path, stage, "PDF")
    if len(payload) < MIN_PDF_BYTES or not payload.startswith(b"%PDF-"):
        raise PublishError("staged PDF is too small or lacks a PDF header")
    if re.match(rb"%PDF-(?:1\.[0-7]|2\.0)[\r\n]", payload[:16]) is None:
        raise PublishError("staged PDF declares an unsupported or malformed version")
    if payload.rstrip().endswith(b"%%EOF") is False:
        raise PublishError("staged PDF lacks a terminal %%EOF marker")
    startxref = payload.rfind(b"startxref")
    if startxref < 0:
        raise PublishError("staged PDF lacks startxref")
    match = re.search(rb"startxref\s+(\d+)\s+%%EOF\s*$", payload[startxref:])
    if match is None:
        raise PublishError("staged PDF trailer is malformed")
    offset = int(match.group(1))
    if offset <= 0 or offset >= startxref:
        raise PublishError("staged PDF startxref offset is outside the document")
    xref_prefix = payload[offset : offset + 16].lstrip()
    if not (xref_prefix.startswith(b"xref") or re.match(rb"\d+\s+\d+\s+obj\b", xref_prefix)):
        raise PublishError("staged PDF startxref does not identify an xref table or stream")
    if re.search(rb"/(?:OpenAction|AA|Launch|JavaScript|JS|RichMedia|EmbeddedFile|XFA)\b", payload):
        raise PublishError("staged PDF contains active or embedded-content markers")
    return payload


def _safe_remove_if_identity(path: Path, expected: os.stat_result) -> None:
    try:
        current = os.lstat(path)
    except FileNotFoundError:
        return
    if (
        current.st_dev == expected.st_dev
        and current.st_ino == expected.st_ino
        and stat.S_ISREG(current.st_mode)
        and not stat.S_ISLNK(current.st_mode)
    ):
        path.unlink()


def _publish_transaction(root: Path, payloads: dict[Path, bytes]) -> None:
    """Publish each verified payload and restore every prior destination on failure."""

    try:
        identities = [
            secure_io.inspect_regular_file(
                destination, root=root, allow_missing=True, require_single_link=True
            )
            for destination in payloads
        ]
    except (OSError, secure_io.FileSafetyError) as exc:
        raise PublishError(f"published destination is unsafe: {exc}") from exc
    present_identities = [
        (value.st_dev, value.st_ino) for value in identities if value is not None
    ]
    if len(present_identities) != len(set(present_identities)):
        raise PublishError("published destinations must not alias the same existing file")

    prior: dict[Path, bytes | None] = {}
    before: dict[Path, os.stat_result | None] = {}
    for destination in payloads:
        try:
            state = secure_io.inspect_regular_file(
                destination, root=root, allow_missing=True, require_single_link=True
            )
        except (OSError, secure_io.FileSafetyError) as exc:
            raise PublishError(f"destination is unsafe before publication: {destination}: {exc}") from exc
        before[destination] = state
        if state is None:
            prior[destination] = None
        else:
            prior[destination] = secure_io.read_regular_bytes(
                destination,
                root=root,
                max_bytes=MAX_PUBLISHED_BYTES,
                require_nonempty=True,
                require_single_link=True,
            )

    published: list[Path] = []
    try:
        for destination, payload in payloads.items():
            current = secure_io.inspect_regular_file(
                destination, root=root, allow_missing=True, require_single_link=True
            )
            expected = before[destination]
            if (expected is None) != (current is None) or (
                expected is not None
                and current is not None
                and (expected.st_dev, expected.st_ino) != (current.st_dev, current.st_ino)
            ):
                raise PublishError(f"destination changed before publication: {destination}")
            secure_io.atomic_write_bytes(destination, payload, root=root)
            published.append(destination)
    except BaseException as publication_error:
        rollback_failures: list[str] = []
        for destination in reversed(published):
            original = prior[destination]
            try:
                if original is None:
                    installed = secure_io.inspect_regular_file(
                        destination, root=root, allow_missing=False, require_single_link=True
                    )
                    assert installed is not None
                    _safe_remove_if_identity(destination, installed)
                else:
                    secure_io.atomic_write_bytes(destination, original, root=root)
            except BaseException as rollback_error:
                rollback_failures.append(f"{destination}: {rollback_error}")
        if rollback_failures:
            raise PublishError(
                f"publication failed ({publication_error}); rollback also failed: "
                + "; ".join(rollback_failures)
            ) from publication_error
        raise


def publish(root: Path, results: Path | None = None, *, layout: str = "template") -> None:
    if layout not in ("template", "pandoc"):
        raise PublishError(f"unsupported publication layout: {layout!r}")
    root = secure_io.absolute_lexical(root)
    try:
        secure_io.verify_absolute_directory(root)
    except (OSError, secure_io.FileSafetyError) as exc:
        raise PublishError(f"repository root is unsafe: {exc}") from exc
    suite, outputs, archive_path = _load_suite(root)
    _protect_archive((outputs["docx"], outputs["pdf"]), archive_path, root)
    pandoc, libreoffice = check_prerequisites(require_pandoc=(layout == "pandoc"))
    _check_markdown_current(root, results)
    markdown = outputs["markdown"]
    try:
        markdown_payload = secure_io.read_regular_bytes(
            markdown,
            root=root,
            max_bytes=MAX_MARKDOWN_BYTES,
            require_nonempty=True,
            require_single_link=True,
        )
    except (OSError, secure_io.FileSafetyError) as exc:
        raise PublishError(f"generated Markdown is missing or unsafe: {exc}") from exc

    stage_parent = outputs["docx"].parent
    try:
        secure_io.verify_directory(root, stage_parent)
    except (OSError, secure_io.FileSafetyError) as exc:
        raise PublishError(f"publication parent is unsafe: {exc}") from exc
    if _within(stage_parent, archive_path):
        raise PublishError(
            f"temporary publication directory would be inside the archive: {stage_parent}"
        )
    title = "SYNAPSE Black-Box Testing"
    metadata = suite.get("metadata")
    if isinstance(suite.get("title"), str):
        title = suite["title"]
    if isinstance(metadata, dict) and isinstance(metadata.get("title"), str):
        title = metadata["title"]
    if (
        not title
        or title != title.strip()
        or len(title) > 256
        or any(unicodedata.category(character).startswith("C") for character in title)
        or re.search(r"[<>]", title)
    ):
        raise PublishError("publication title must be bounded single-line plain text")

    with tempfile.TemporaryDirectory(prefix=".blackbox-publish-", dir=stage_parent) as temporary:
        stage = Path(temporary)
        secure_io.verify_directory(stage_parent, stage)
        home = stage / "home"
        resource = stage / "resource-empty"
        home.mkdir(mode=0o700)
        resource.mkdir(mode=0o700)
        staged_markdown = stage / "input.md"
        secure_io.atomic_write_bytes(staged_markdown, markdown_payload, root=stage)
        staged_docx = stage / outputs["docx"].name
        staged_pdf = stage / outputs["pdf"].name
        environment = _safe_subprocess_environment(home)
        user_installation = (home / "libreoffice-profile").as_uri()
        if layout == "template":
            tools_dir = str(Path(__file__).resolve().parent)
            if tools_dir not in sys.path:
                sys.path.insert(0, tools_dir)
            import docx_template
            import validate

            suite_data, report = validate.validate_project(root, results)
            if not report.ok or suite_data is None:
                raise PublishError("black-box suite failed validation before template DOCX build")
            docx_template.build_template_docx(suite_data, staged_docx, title=title)
        else:
            assert pandoc is not None
            _run(
                [
                    pandoc,
                    str(staged_markdown),
                    "--from=gfm-raw_html-raw_attribute",
                    "--to=docx",
                    "--standalone",
                    "--sandbox",
                    "--resource-path",
                    str(resource),
                    "--metadata",
                    f"title={title}",
                    "--output",
                    str(staged_docx),
                ],
                "Pandoc Markdown-to-DOCX conversion",
                cwd=resource,
                environment=environment,
            )
        docx_payload = _validate_docx(staged_docx, stage)
        _run(
            [
                libreoffice,
                f"-env:UserInstallation={user_installation}",
                "--headless",
                "--nologo",
                "--nodefault",
                "--nolockcheck",
                "--norestore",
                "--convert-to",
                "pdf:writer_pdf_Export",
                "--outdir",
                str(stage),
                str(staged_docx),
            ],
            "LibreOffice DOCX-to-PDF conversion",
            cwd=resource,
            environment=environment,
        )
        libreoffice_pdf = stage / f"{staged_docx.stem}.pdf"
        if libreoffice_pdf != staged_pdf:
            raise PublishError(
                f"LibreOffice output name {libreoffice_pdf.name!r} does not match declared PDF"
            )
        pdf_payload = _validate_pdf(staged_pdf, stage)
        _protect_archive((outputs["docx"], outputs["pdf"]), archive_path, root)
        _publish_transaction(
            root,
            {outputs["docx"]: docx_payload, outputs["pdf"]: pdf_payload},
        )
    print(f"Published DOCX: {outputs['docx']}")
    print(f"Published PDF: {outputs['pdf']}")
    print(
        "Network assurance: converter processes must have been run inside an externally enforced no-network sandbox."
    )


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=_default_root(), help="repository root")
    parser.add_argument("--results", type=Path, help="sanitized result overlay used by build.py")
    parser.add_argument(
        "--layout",
        choices=("template", "pandoc"),
        default="template",
        help="DOCX generation layout: template (default, matches sample DOCX with narrow margins) or pandoc",
    )
    parser.add_argument(
        "--check-prerequisites",
        action="store_true",
        help="check supported Pandoc and LibreOffice versions and do not convert",
    )
    args = parser.parse_args(argv)
    try:
        if args.check_prerequisites:
            check_prerequisites()
            print("Supported publication prerequisites are available.")
        else:
            publish(args.root, args.results, layout=args.layout)
    except PublishError as exc:
        print(f"Publication failed: {exc}", file=sys.stderr)
        return 1
    except (OSError, secure_io.FileSafetyError) as exc:
        print(f"Publication failed with a filesystem safety error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
