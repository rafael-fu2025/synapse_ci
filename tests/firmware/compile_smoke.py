"""Compile-only Arduino smoke test, using synthetic credentials in both branches.

Requires a separately installed CLI, pinned dependencies, and reviewed FQBN.
Never opens a serial port, uploads, reads private headers, or copies legacy code.
Temporary synthetic firmware/artifacts are removed on success or failure.
"""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

TESTS = Path(__file__).resolve().parent
SOURCE = TESTS.parents[1] / "firmware" / "bmg" / "compost"


def reviewed_fqbn(value):
    prefix = "esp32:esp32:esp32s3:"
    if not value.startswith(prefix):
        raise argparse.ArgumentTypeError("An explicit ESP32-S3 option profile is required")
    try:
        options = dict(item.split("=", 1) for item in value[len(prefix):].split(","))
    except ValueError as error:
        raise argparse.ArgumentTypeError("Malformed FQBN options") from error
    if options.get("FlashSize") != "16M" or options.get("FlashMode") != "qio":
        raise argparse.ArgumentTypeError("Explicit FlashSize=16M and FlashMode=qio required")
    if options.get("PSRAM") != "disabled":
        raise argparse.ArgumentTypeError("Commissioning uses no PSRAM; explicit PSRAM=disabled required")
    if options.get("PartitionScheme") not in ("fatflash", "app3M_fat9M_16MB"):
        raise argparse.ArgumentTypeError("Explicit reviewed 16MB partition layout required")
    return value


def summarize_exports(root, scheme):
    """Report public synthetic build metadata before the temporary job is removed."""
    for directory in (root / "build", root / f"output-{scheme}"):
        print(f"Export inventory ({scheme}): {directory.name}", flush=True)
        recipes = sorted(path.relative_to(directory).as_posix() for path in directory.rglob("*")
                         if path.is_file() and (path.name in ("flash_args", "flasher_args.json", "flash_project_args")
                                                or path.suffix == ".flash_args"))
        print("  Flash recipe files: " + (", ".join(recipes) if recipes else "NONE"), flush=True)
        for path in sorted(directory.iterdir()):
            if not path.is_file():
                continue
            print(f"  {path.name} ({path.stat().st_size} bytes)", flush=True)
            if path.suffix == ".json":
                data = json.loads(path.read_text(encoding="utf-8"))
                print(f"    JSON keys: {sorted(data) if isinstance(data, dict) else type(data).__name__}", flush=True)
                if isinstance(data, dict):
                    public = {key: data[key] for key in ("flash_files", "flash_settings", "write_flash_args",
                              "extra_esptool_args") if key in data}
                    if public:
                        print("    Flash metadata: " + json.dumps(public, sort_keys=True), flush=True)
            if path.name == "flash_args" or path.suffix == ".flash_args":
                print("    Flash recipe: " + path.read_text(encoding="utf-8")[:16384], flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cli", default="arduino-cli", help="CLI executable path; no shell expansion")
    parser.add_argument("--fqbn", required=True, type=reviewed_fqbn,
                        help="Explicit commissioning compile profile; PSRAM disabled, not physical certification")
    args = parser.parse_args()
    # This worker deliberately leaves unused PSRAM disabled; no bus inference.
    # Reuse the build path between branches so the core/libraries are not rebuilt.
    with tempfile.TemporaryDirectory(prefix="compile-smoke-", dir=TESTS) as temporary:
        root = Path(temporary)
        sketch = root / "compost"
        sketch.mkdir()
        for name in ("compost.ino", "commissioning_policy.h"):
            shutil.copy2(SOURCE / name, sketch / name)
        for scheme in ("http", "https"):
            configuration = (
                '#pragma once\n'
                'static const char WIFI_SSID[] = "SYNTHETIC_COMPILE_ONLY";\n'
                'static const char WIFI_PASS[] = "SYNTHETIC_COMPILE_ONLY";\n'
                f'static const char API_BASE_URL[] = "{scheme}://compile-only.invalid";\n'
                'static const char DEVICE_TOKEN[] = "SYNTHETIC_COMPILE_ONLY";\n'
            )
            if scheme == "http":
                configuration += '#define BMG_ALLOW_HTTP true\n'
            else:
                # Deliberately not a usable CA: compile coverage only, no TLS test.
                configuration += '#define BMG_ROOT_CA_PEM "SYNTHETIC_CA_COMPILE_ONLY"\n'
            (sketch / "device_config.h").write_text(configuration, encoding="utf-8")
            print(f"Compiling synthetic {scheme.upper()} configuration (no upload)", flush=True)
            subprocess.run([args.cli, "compile", "--export-binaries", "--fqbn", args.fqbn,
                            "--build-path", str(root / "build"),
                            "--output-dir", str(root / f"output-{scheme}"), str(sketch)],
                           check=True, timeout=600)
            summarize_exports(root, scheme)
    print("Both synthetic configurations compiled; no board/physical/TLS acceptance implied")


if __name__ == "__main__":
    main()
