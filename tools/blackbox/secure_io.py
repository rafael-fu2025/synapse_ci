#!/usr/bin/env python3
"""Shared filesystem and strict-JSON primitives for black-box tooling.

The helpers fail closed when a path component is a symlink/reparse point, a
file is not regular, or a file has more than one hard link.  Atomic writes use
an unpredictable O_EXCL temporary file in the already-verified destination
parent, fsync the payload, re-check identities immediately before replacement,
and fsync the directory where the platform permits it.
"""

from __future__ import annotations

import argparse
import decimal
import hashlib
import json
import math
import os
import secrets
import stat
import sys
import tempfile
from pathlib import Path
from typing import Any, BinaryIO, Sequence

DEFAULT_JSON_BYTE_LIMIT = 4 * 1024 * 1024
DEFAULT_JSON_DEPTH_LIMIT = 64
DEFAULT_STREAM_BYTE_LIMIT = 64 * 1024 * 1024
_FILE_ATTRIBUTE_REPARSE_POINT = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400)


class FileSafetyError(OSError):
    """A path or filesystem object violates the black-box safety contract."""


class StrictJSONError(ValueError):
    """JSON is malformed, ambiguous, non-finite, or exceeds configured limits."""


def absolute_lexical(path: Path | str) -> Path:
    """Return an absolute path without resolving links."""

    return Path(os.path.abspath(os.fspath(path)))


def _identity(value: os.stat_result) -> tuple[int, int, int, int]:
    return (value.st_dev, value.st_ino, value.st_mode, value.st_nlink)


def _same_file_identity(left: os.stat_result, right: os.stat_result) -> bool:
    return left.st_dev == right.st_dev and left.st_ino == right.st_ino


def _is_link_or_reparse(value: os.stat_result) -> bool:
    return stat.S_ISLNK(value.st_mode) or bool(
        getattr(value, "st_file_attributes", 0) & _FILE_ATTRIBUTE_REPARSE_POINT
    )


def _contained(root: Path, candidate: Path) -> bool:
    try:
        candidate.relative_to(root)
        return True
    except ValueError:
        return False


def verify_absolute_directory(directory: Path | str) -> os.stat_result:
    """Verify every existing component from the filesystem anchor to a directory."""

    directory_path = absolute_lexical(directory)
    anchor_text = directory_path.anchor
    if not anchor_text:
        raise FileSafetyError(f"directory has no absolute filesystem anchor: {directory_path}")
    anchor = Path(anchor_text)
    return verify_directory(anchor, directory_path)


def verify_directory(root: Path | str, directory: Path | str) -> os.stat_result:
    """Verify every component from root through directory without following links."""

    root_path = absolute_lexical(root)
    directory_path = absolute_lexical(directory)
    if not _contained(root_path, directory_path):
        raise FileSafetyError(f"path escapes the trusted root: {directory_path}")

    current = root_path
    components = [current]
    components.extend(current / Path(*directory_path.relative_to(root_path).parts[:index])
                      for index in range(1, len(directory_path.relative_to(root_path).parts) + 1))
    last: os.stat_result | None = None
    for component in components:
        try:
            value = os.lstat(component)
        except FileNotFoundError as exc:
            raise FileSafetyError(f"required directory does not exist: {component}") from exc
        if _is_link_or_reparse(value):
            raise FileSafetyError(f"directory component is a symlink or reparse point: {component}")
        if not stat.S_ISDIR(value.st_mode):
            raise FileSafetyError(f"path component is not a directory: {component}")
        last = value
    assert last is not None
    return last


def inspect_regular_file(
    path: Path | str,
    *,
    root: Path | str,
    allow_missing: bool = False,
    require_nonempty: bool = False,
    require_single_link: bool = True,
) -> os.stat_result | None:
    """lstat a contained regular file after verifying every parent directory."""

    root_path = absolute_lexical(root)
    file_path = absolute_lexical(path)
    if not _contained(root_path, file_path):
        raise FileSafetyError(f"file escapes the trusted root: {file_path}")
    verify_absolute_directory(root_path)
    verify_directory(root_path, file_path.parent)
    try:
        value = os.lstat(file_path)
    except FileNotFoundError:
        if allow_missing:
            return None
        raise FileSafetyError(f"required file does not exist: {file_path}") from None
    if _is_link_or_reparse(value):
        raise FileSafetyError(f"file is a symlink or reparse point: {file_path}")
    if not stat.S_ISREG(value.st_mode):
        raise FileSafetyError(f"path is not a regular file: {file_path}")
    if require_single_link and value.st_nlink != 1:
        raise FileSafetyError(f"regular file must have exactly one hard link: {file_path}")
    if require_nonempty and value.st_size <= 0:
        raise FileSafetyError(f"regular file must be nonempty: {file_path}")
    return value


def _open_readonly_no_follow(path: Path) -> int:
    flags = os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_CLOEXEC", 0)
    flags |= getattr(os, "O_NOFOLLOW", 0)
    return os.open(path, flags)


def _verify_open_regular(
    descriptor: int,
    path: Path,
    *,
    require_nonempty: bool,
    require_single_link: bool,
) -> os.stat_result:
    value = os.fstat(descriptor)
    if not stat.S_ISREG(value.st_mode):
        raise FileSafetyError(f"opened object is not a regular file: {path}")
    if require_single_link and value.st_nlink != 1:
        raise FileSafetyError(f"opened file must have exactly one hard link: {path}")
    if require_nonempty and value.st_size <= 0:
        raise FileSafetyError(f"opened file must be nonempty: {path}")
    return value


def read_regular_bytes(
    path: Path | str,
    *,
    root: Path | str,
    max_bytes: int,
    require_nonempty: bool = False,
    require_single_link: bool = True,
) -> bytes:
    """Read a stable regular file through one descriptor with bounded size."""

    file_path = absolute_lexical(path)
    initial = inspect_regular_file(
        file_path,
        root=root,
        require_nonempty=require_nonempty,
        require_single_link=require_single_link,
    )
    assert initial is not None
    if initial.st_size > max_bytes:
        raise FileSafetyError(f"file exceeds the {max_bytes}-byte limit: {file_path}")
    descriptor = _open_readonly_no_follow(file_path)
    try:
        opened = _verify_open_regular(
            descriptor,
            file_path,
            require_nonempty=require_nonempty,
            require_single_link=require_single_link,
        )
        if not _same_file_identity(initial, opened):
            raise FileSafetyError(f"file identity changed while opening: {file_path}")
        if opened.st_size > max_bytes:
            raise FileSafetyError(f"file exceeds the {max_bytes}-byte limit: {file_path}")
        chunks: list[bytes] = []
        remaining = opened.st_size
        while remaining:
            chunk = os.read(descriptor, min(1024 * 1024, remaining))
            if not chunk:
                raise FileSafetyError(f"file changed or ended while being read: {file_path}")
            chunks.append(chunk)
            remaining -= len(chunk)
        if os.read(descriptor, 1):
            raise FileSafetyError(f"file grew while being read: {file_path}")
        final = os.fstat(descriptor)
        current = os.lstat(file_path)
        if (
            not _same_file_identity(opened, final)
            or not _same_file_identity(opened, current)
            or final.st_size != opened.st_size
            or current.st_size != opened.st_size
            or _is_link_or_reparse(current)
        ):
            raise FileSafetyError(f"file identity or size changed while being read: {file_path}")
        return b"".join(chunks)
    finally:
        os.close(descriptor)


def hash_regular_file(
    path: Path | str,
    *,
    root: Path | str,
    max_bytes: int = DEFAULT_STREAM_BYTE_LIMIT,
    require_nonempty: bool = True,
    require_single_link: bool = True,
) -> tuple[str, int]:
    payload = read_regular_bytes(
        path,
        root=root,
        max_bytes=max_bytes,
        require_nonempty=require_nonempty,
        require_single_link=require_single_link,
    )
    return hashlib.sha256(payload).hexdigest(), len(payload)


def _reject_duplicate_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise StrictJSONError(f"duplicate object key {key!r}")
        result[key] = value
    return result


def _reject_constant(value: str) -> Any:
    raise StrictJSONError(f"non-finite JSON number {value!r} is forbidden")


def _finite_float(value: str) -> float:
    try:
        exact = decimal.Decimal(value)
    except decimal.InvalidOperation as exc:
        raise StrictJSONError(f"invalid JSON number {value!r}") from exc
    if not exact.is_finite():
        raise StrictJSONError(f"non-finite JSON number {value!r} is forbidden")
    converted = float(exact)
    if not math.isfinite(converted):
        raise StrictJSONError(f"JSON number is outside the supported finite range: {value!r}")
    return converted


def _check_depth(value: Any, max_depth: int) -> None:
    stack: list[tuple[Any, int]] = [(value, 1)]
    while stack:
        node, depth = stack.pop()
        if depth > max_depth:
            raise StrictJSONError(f"JSON nesting exceeds the depth limit of {max_depth}")
        if isinstance(node, dict):
            stack.extend((child, depth + 1) for child in node.values())
        elif isinstance(node, list):
            stack.extend((child, depth + 1) for child in node)


def strict_json_loads(
    payload: bytes | str,
    *,
    label: str = "JSON",
    max_bytes: int = DEFAULT_JSON_BYTE_LIMIT,
    max_depth: int = DEFAULT_JSON_DEPTH_LIMIT,
) -> Any:
    if isinstance(payload, str):
        encoded = payload.encode("utf-8")
        text = payload
    else:
        encoded = payload
        try:
            text = payload.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise StrictJSONError(f"{label} is not valid UTF-8: {exc}") from exc
    if len(encoded) > max_bytes:
        raise StrictJSONError(f"{label} exceeds the {max_bytes}-byte limit")
    if text.startswith("\ufeff"):
        raise StrictJSONError(f"{label} must not contain a UTF-8 byte-order mark")
    try:
        value = json.loads(
            text,
            object_pairs_hook=_reject_duplicate_pairs,
            parse_constant=_reject_constant,
            parse_float=_finite_float,
        )
    except StrictJSONError:
        raise
    except json.JSONDecodeError as exc:
        raise StrictJSONError(
            f"{label} is not valid JSON at line {exc.lineno}, column {exc.colno}: {exc.msg}"
        ) from exc
    _check_depth(value, max_depth)
    return value


def load_strict_json_file(
    path: Path | str,
    *,
    root: Path | str,
    label: str,
    max_bytes: int = DEFAULT_JSON_BYTE_LIMIT,
    max_depth: int = DEFAULT_JSON_DEPTH_LIMIT,
    require_single_link: bool = True,
) -> Any:
    payload = read_regular_bytes(
        path,
        root=root,
        max_bytes=max_bytes,
        require_nonempty=True,
        require_single_link=require_single_link,
    )
    return strict_json_loads(payload, label=label, max_bytes=max_bytes, max_depth=max_depth)


def _write_all(descriptor: int, payload: bytes) -> None:
    view = memoryview(payload)
    while view:
        written = os.write(descriptor, view)
        if written <= 0:
            raise FileSafetyError("short write to temporary file")
        view = view[written:]


def _fsync_directory(directory: Path) -> None:
    if os.name == "nt":
        return
    flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_CLOEXEC", 0)
    flags |= getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(directory, flags)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _safe_remove_temp(path: Path, expected: os.stat_result | None) -> None:
    if expected is None:
        return
    try:
        current = os.lstat(path)
    except FileNotFoundError:
        return
    if _is_link_or_reparse(current) or not _same_file_identity(expected, current):
        return
    try:
        os.unlink(path)
    except FileNotFoundError:
        pass


def atomic_write_bytes(
    destination: Path | str,
    payload: bytes,
    *,
    root: Path | str,
    mode: int = 0o600,
) -> None:
    """Safely and atomically replace one destination with exact bytes."""

    root_path = absolute_lexical(root)
    output = absolute_lexical(destination)
    if not _contained(root_path, output):
        raise FileSafetyError(f"destination escapes the trusted root: {output}")
    verify_absolute_directory(root_path)
    parent_before = verify_directory(root_path, output.parent)
    destination_before = inspect_regular_file(output, root=root_path, allow_missing=True)

    descriptor = -1
    temporary: Path | None = None
    temporary_identity: os.stat_result | None = None
    try:
        descriptor, raw_name = tempfile.mkstemp(
            prefix=f".{output.name}.", suffix=f".{secrets.token_hex(4)}.tmp", dir=output.parent
        )
        temporary = Path(raw_name)
        os.chmod(temporary, mode)
        temporary_identity = _verify_open_regular(
            descriptor, temporary, require_nonempty=False, require_single_link=True
        )
        _write_all(descriptor, payload)
        os.fsync(descriptor)
        written = os.fstat(descriptor)
        if written.st_size != len(payload) or not _same_file_identity(temporary_identity, written):
            raise FileSafetyError(f"temporary file changed while writing: {temporary}")
        path_state = os.lstat(temporary)
        if (
            _is_link_or_reparse(path_state)
            or not stat.S_ISREG(path_state.st_mode)
            or path_state.st_nlink != 1
            or not _same_file_identity(written, path_state)
        ):
            raise FileSafetyError(f"temporary file path was replaced or aliased: {temporary}")
        os.close(descriptor)
        descriptor = -1

        parent_now = verify_directory(root_path, output.parent)
        if not _same_file_identity(parent_before, parent_now):
            raise FileSafetyError(f"destination parent changed before replacement: {output.parent}")
        destination_now = inspect_regular_file(output, root=root_path, allow_missing=True)
        if destination_before is None:
            if destination_now is not None:
                raise FileSafetyError(f"destination appeared during atomic write: {output}")
        elif destination_now is None or not _same_file_identity(destination_before, destination_now):
            raise FileSafetyError(f"destination changed during atomic write: {output}")

        temp_now = inspect_regular_file(temporary, root=root_path)
        assert temp_now is not None
        if not _same_file_identity(temporary_identity, temp_now) or temp_now.st_size != len(payload):
            raise FileSafetyError(f"temporary file changed before replacement: {temporary}")
        os.replace(temporary, output)
        temporary = None
        try:
            final = inspect_regular_file(output, root=root_path)
            assert final is not None
            if not _same_file_identity(temp_now, final) or final.st_size != len(payload):
                raise FileSafetyError(f"atomic replacement did not install the verified file: {output}")
            _fsync_directory(output.parent)
        except BaseException:
            # Do not leave a destination whose post-replace identity could not be verified.
            try:
                current = os.lstat(output)
                if _same_file_identity(temp_now, current):
                    os.unlink(output)
                    _fsync_directory(output.parent)
            except OSError:
                pass
            raise
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if temporary is not None:
            _safe_remove_temp(temporary, temporary_identity)


def _cli_atomic_write(args: argparse.Namespace) -> int:
    payload = sys.stdin.buffer.read(args.max_bytes + 1)
    if len(payload) > args.max_bytes:
        raise FileSafetyError(f"standard input exceeds the {args.max_bytes}-byte limit")
    digest = hashlib.sha256(payload).hexdigest()
    if args.sha256 and digest != args.sha256:
        raise FileSafetyError("standard-input SHA-256 does not match the caller declaration")
    atomic_write_bytes(args.destination, payload, root=args.root)
    return 0


def _cli_strict_json_normalize(args: argparse.Namespace) -> int:
    payload = sys.stdin.buffer.read(args.max_bytes + 1)
    if len(payload) > args.max_bytes:
        raise StrictJSONError(f"standard input exceeds the {args.max_bytes}-byte limit")
    value = strict_json_loads(
        payload,
        label=args.label,
        max_bytes=args.max_bytes,
        max_depth=args.max_depth,
    )
    normalized = json.dumps(
        value,
        ensure_ascii=False,
        allow_nan=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    sys.stdout.buffer.write(normalized)
    return 0


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    atomic = subparsers.add_parser("atomic-write", help="atomically write stdin to a destination")
    atomic.add_argument("--root", type=Path, required=True)
    atomic.add_argument("--destination", type=Path, required=True)
    atomic.add_argument("--sha256")
    atomic.add_argument("--max-bytes", type=int, default=16 * 1024 * 1024)
    strict_json = subparsers.add_parser(
        "strict-json-normalize",
        help="strictly parse stdin and emit canonical duplicate-free JSON",
    )
    strict_json.add_argument("--label", default="JSON")
    strict_json.add_argument("--max-bytes", type=int, default=DEFAULT_JSON_BYTE_LIMIT)
    strict_json.add_argument("--max-depth", type=int, default=DEFAULT_JSON_DEPTH_LIMIT)
    args = parser.parse_args(argv)
    try:
        if args.command == "atomic-write":
            return _cli_atomic_write(args)
        if args.command == "strict-json-normalize":
            return _cli_strict_json_normalize(args)
    except (FileSafetyError, StrictJSONError, OSError) as exc:
        print(f"secure write failed: {exc}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
