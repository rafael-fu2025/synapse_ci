"""Static contract guards plus optional native execution of actual C++ policy.

These tests do not simulate Arduino hardware or claim full firmware compilation.
Never print/read legacy configuration as text or generate a real private header.
"""
from contextlib import redirect_stdout
import hashlib
import io
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

from compile_smoke import reviewed_fqbn, summarize_exports

ROOT = Path(__file__).resolve().parents[2]
FIRMWARE = ROOT / "firmware" / "bmg" / "compost"
TESTS = Path(__file__).resolve().parent
SKETCH = (FIRMWARE / "compost.ino").read_text(encoding="utf-8")
POLICY = (FIRMWARE / "commissioning_policy.h").read_text(encoding="utf-8")
PROVENANCE = json.loads((FIRMWARE / "provenance.json").read_text(encoding="utf-8"))


class CommissioningContracts(unittest.TestCase):
    def test_motor_writes_are_low_only(self):
        writes = re.findall(r"gpio_set_level\(static_cast<gpio_num_t>\((\w+)\),\s*(\w+)\)", SKETCH)
        self.assertEqual(set(writes), {
            ("MOTOR_ENABLE", "LOW"), ("MOTOR_IN1", "LOW"), ("MOTOR_IN2", "LOW")})
        self.assertEqual(len(writes), 3)
        self.assertEqual(SKETCH.count("gpio_set_level("), 3)
        self.assertNotRegex(SKETCH, r"\b(HIGH|analogWrite|ledcWrite|ledcAttach|startMotor|beginRun)\b")
        self.assertNotRegex(SKETCH, r"\bdigitalWrite\s*\(")
        self.assertIn("MOTOR_IN1 = 4, MOTOR_IN2 = 5, MOTOR_ENABLE = 6", SKETCH)

    def test_safe_setup_precedes_serial_and_wifi(self):
        setup = SKETCH.split("void setup() {", 1)[1].split("void loop()", 1)[0]
        self.assertLess(setup.index("outputsOff();"), setup.index("pinMode("))
        self.assertLess(setup.index("pinMode(MOTOR_ENABLE, OUTPUT)"), setup.index("Serial.begin"))
        self.assertLess(setup.index("pinMode(MOTOR_ENABLE, OUTPUT)"), setup.index("WiFi."))
        self.assertIn("void loop() {\n  outputsOff();", SKETCH)

    def test_serial_is_diagnostic_only(self):
        self.assertIn("if (Serial.read() == '?') diagnostic();", SKETCH)
        self.assertNotRegex(SKETCH, r"Serial\.(parseInt|parseFloat|readString|readBytes)")
        self.assertNotIn("Wire.begin", SKETCH)
        self.assertNotIn("RTClib", SKETCH)

    def test_mandatory_private_config_and_no_builtin_credentials(self):
        self.assertIn('#include "device_config.h"', SKETCH)
        for name in ("WIFI_SSID", "WIFI_PASS", "API_BASE_URL", "DEVICE_TOKEN"):
            self.assertNotRegex(SKETCH, rf"(?:#define|const\s+char).*\b{name}\b.*=")
        example = (FIRMWARE / "device_config.h.example").read_text(encoding="utf-8")
        self.assertIn("REPLACE_WITH_WIFI_NAME", example)
        self.assertIn("REPLACE_WITH_WIFI_PASSWORD", example)
        self.assertIn("REPLACE_WITH_NEW_DEVICE_TOKEN", example)
        ignore = (FIRMWARE / ".gitignore").read_text(encoding="utf-8")
        self.assertIn("/device_config.h", ignore)
        self.assertNotIn("#if USE_HTTPS", SKETCH)

    def test_no_credentials_in_serial_expressions(self):
        # Include continuations until semicolon, not only the first printf line.
        expressions = re.findall(r"Serial\.[\s\S]*?;", SKETCH)
        for expression in expressions:
            self.assertNotRegex(expression, r"\b(WIFI_SSID|WIFI_PASS|DEVICE_TOKEN|API_BASE_URL|payload|response)\b")

    def test_tls_and_http_consent_boundaries(self):
        self.assertNotIn("setInsecure", SKETCH)
        self.assertIn("tls.setCACert(BMG_ROOT_CA_PEM)", SKETCH)
        self.assertIn("#ifdef BMG_ROOT_CA_PEM", SKETCH)
        self.assertIn("#ifdef BMG_ALLOW_HTTP", SKETCH)
        self.assertIn("if (BMG_ALLOW_HTTP == true) begun = http.begin(plain, url);", SKETCH)
        self.assertIn("HTTPC_DISABLE_FOLLOW_REDIRECTS", SKETCH)
        self.assertIn("time(nullptr) < 1700000000", SKETCH)
        self.assertNotRegex(SKETCH, r"#define\s+BMG_ALLOW_HTTP")

    def test_bounded_response_and_timeouts(self):
        self.assertIn("MAX_BODY = 16384", SKETCH)
        self.assertIn("size > MAX_BODY - text.length()", SKETCH)
        self.assertIn("http.writeToStream(&body)", SKETCH)
        self.assertNotIn("http.getString()", SKETCH)
        self.assertIn("http.setConnectTimeout(HTTP_TIMEOUT_MS)", SKETCH)
        self.assertIn("http.setTimeout(HTTP_TIMEOUT_MS)", SKETCH)
        self.assertIn("tls.setHandshakeTimeout(5)", SKETCH)
        self.assertIn("DeserializationOption::NestingLimit(12)", SKETCH)
        self.assertIn("commissioning::success(status)", SKETCH)

    def test_actual_api_shapes_and_no_run_reports(self):
        self.assertIn('"/api/v1/devices/bmg"', SKETCH)
        self.assertIn('http.addHeader("X-Device-Token", DEVICE_TOKEN)', SKETCH)
        for endpoint in ("/heartbeat", "/schedule", "/commands", "/commands/ack"):
            self.assertIn(f'"{endpoint}"', SKETCH)
        self.assertNotIn('"/runs"', SKETCH)
        self.assertNotIn('["data"]', SKETCH)
        self.assertIn('response["commands"].is<JsonArray>()', SKETCH)
        self.assertIn('body["command_id"] = journal.id', SKETCH)
        self.assertIn('body["status"] = journal.stop ? "completed" : "rejected"', SKETCH)
        self.assertIn('Commissioning firmware: motor outputs disabled', POLICY)
        self.assertIn('physical stop not verified', POLICY)

    def test_heartbeat_does_not_fabricate_rtc_or_enabled_schedule(self):
        self.assertIn('body["firmware_version"] = commissioning::VERSION', SKETCH)
        self.assertNotIn('["rtc_valid"]', SKETCH)
        self.assertIn('if (disabledScheduleApplied) body["applied_schedule_version"]', SKETCH)
        function = SKETCH.split("void pollSchedule() {", 1)[1].split("bool acknowledgePending", 1)[0]
        self.assertLess(function.index("disabledScheduleApplied = false"), function.index('request("/schedule"'))
        self.assertIn("acceptDisabledSchedule", function)
        self.assertNotIn('["pwm"]', function)  # Disabled null PWM is not coerced/validated as an integer.

    def test_durable_token_bound_journal_and_ordering(self):
        self.assertIn('preferences.begin("bmgcommission", false)', SKETCH)
        self.assertIn('String(API_BASE_URL) + "\\n" + DEVICE_TOKEN', SKETCH)
        self.assertIn("mbedtls_sha256", SKETCH)
        self.assertIn('preferences.putBytes("ack", &journal, sizeof(journal))', SKETCH)
        self.assertIn("memcmp(binding, journal.binding, sizeof(binding))", SKETCH)
        self.assertIn("if (id <= journal.id) continue;", SKETCH)
        self.assertIn("id <= previous", SKETCH)
        self.assertIn("if (!saveJournal() || !acknowledgePending()) return;", SKETCH)
        self.assertIn("journalReady = false;", SKETCH)
        self.assertNotIn("preferences.clear", SKETCH)
        self.assertIn("if (status != 404 && status != 409) return false;", SKETCH)
        self.assertIn('response["command_id"].as<uint64_t>() != journal.id', SKETCH)

    def test_dependency_and_provenance_contract(self):
        self.assertEqual(PROVENANCE["dependencies"], {"esp32:esp32": "3.3.3", "ArduinoJson": "7.4.2"})
        self.assertIsNone(PROVENANCE["release_fqbn"])
        self.assertEqual(PROVENANCE["compile_fqbn"],
                         "esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled")
        self.assertFalse(PROVENANCE["compiler_smoke"]["hardware_uploaded"])
        self.assertEqual(PROVENANCE["source_lines"], 1273)
        self.assertEqual(PROVENANCE["source_config_lines_excluded"], [33, 34, 35, 36])
        self.assertEqual(PROVENANCE["source_hardware"]["i2c_sda_gpio"], 8)
        self.assertEqual(PROVENANCE["source_hardware"]["i2c_scl_gpio"], 9)
        self.assertIn("ARDUINOJSON_VERSION_REVISION != 2", SKETCH)

    def test_exact_original_hash_when_available_without_reading_config_text(self):
        original = Path(PROVENANCE["source_path"])
        if not original.is_file():
            self.skipTest("Fixed external original not available on this worker")
        digest = hashlib.sha256(original.read_bytes()).hexdigest()
        self.assertEqual(digest, PROVENANCE["source_sha256"])

    def test_smoke_worker_requires_explicit_board_profile(self):
        import argparse
        for value in (
            "esp32:esp32:esp32s3",
            "esp32:esp32:esp32",
            "esp32:esp32:esp32s3:FlashSize=4M",
            "esp32:esp32:esp32s3:FlashSize=16M,FlashMode=qio",
            "esp32:esp32:esp32s3:FlashSize=16M,FlashMode=qio,PSRAM=enabled,PartitionScheme=fatflash",
            "esp32:esp32:esp32s3:FlashSize=16M,FlashMode=qio,PSRAM=opi,PartitionScheme=default",
        ):
            with self.subTest(profile=value), self.assertRaises(argparse.ArgumentTypeError):
                reviewed_fqbn(value)
        # Parser tests only: these are NOT declarations of board acceptance.
        profile = "esp32:esp32:esp32s3:FlashMode=qio,FlashSize=16M,PartitionScheme=app3M_fat9M_16MB,PSRAM=disabled"
        self.assertEqual(reviewed_fqbn(profile), profile)

    def test_export_inventory_distinguishes_build_metadata_from_flash_recipes(self):
        with tempfile.TemporaryDirectory(prefix="compile-smoke-", dir=TESTS) as directory:
            root = Path(directory)
            build = root / "build"
            output = root / "output-http"
            build.mkdir()
            output.mkdir()
            (build / "build.options.json").write_text('{"fqbn": "synthetic", "private": "DO_NOT_PRINT_VALUE"}', encoding="utf-8")
            (output / "compost.ino.merged.bin").write_bytes(b"synthetic")
            captured = io.StringIO()
            with redirect_stdout(captured):
                summarize_exports(root, "http")
            self.assertIn("Flash recipe files: NONE", captured.getvalue())
            self.assertIn("build.options.json", captured.getvalue())
            self.assertIn("compost.ino.merged.bin", captured.getvalue())
            self.assertNotIn("DO_NOT_PRINT_VALUE", captured.getvalue())
            (build / "flash_args").write_text("0x0 synthetic.bin", encoding="utf-8")
            captured = io.StringIO()
            with redirect_stdout(captured):
                summarize_exports(root, "http")
            self.assertIn("Flash recipe files: flash_args", captured.getvalue())
            self.assertIn("Flash recipe: 0x0 synthetic.bin", captured.getvalue())

    def test_actual_cpp_policy_when_host_compiler_available(self):
        compiler = shutil.which("g++") or shutil.which("clang++")
        if not compiler:
            self.skipTest("No host C++ compiler; policy_test.cpp requires a compiler worker")
        with tempfile.TemporaryDirectory(prefix="policy-test-", dir=TESTS) as directory:
            executable = Path(directory) / "policy-test.exe"
            subprocess.run([compiler, "-std=c++11", "-Wall", "-Wextra", "-Werror",
                            str(TESTS / "policy_test.cpp"), "-o", str(executable)],
                           check=True, timeout=60)
            subprocess.run([str(executable)], check=True, timeout=10)


if __name__ == "__main__":
    unittest.main()
