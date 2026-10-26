#!/usr/bin/env python3
"""Exercise incomplete scans and the collection trust boundaries without Docker."""

import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

import scan


NOW = dt.datetime(2026, 10, 25, 12, 0, tzinfo=dt.timezone.utc)
SCANNER_IMAGE = "ghcr.io/aquasecurity/trivy:0.76.1@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c"


def trivy_report():
    return {
        "SchemaVersion": 2, "ArtifactType": "container_image",
        "Metadata": {"ImageID": "sha256:" + "a" * 64},
        "Results": [{"Target": "fixture (debian 12)", "Class": "os-pkgs", "Type": "debian",
                     "Packages": [{"Name": "fixture", "Version": "1"}], "Vulnerabilities": []}],
    }


def npm_report():
    return {"auditReportVersion": 2, "vulnerabilities": {}, "metadata": {"vulnerabilities": {"total": 0}}}


def metadata():
    return {"Version": 2, "UpdatedAt": scan.timestamp(NOW), "DownloadedAt": scan.timestamp(NOW)}


class FakeRunner:
    def __init__(self):
        self.commands = []
        self.db = metadata()
        self.db_exit = 0
        self.missing_db = False
        self.build_failure = None
        self.scan_failure = None
        self.missing_report = None
        self.changed_report = None
        self.npm = npm_report()
        self.npm_exit = 0
        self.npm_bytes = None

    @staticmethod
    def mount(command, target):
        for index, argument in enumerate(command):
            if argument == "--mount":
                values = dict(item.split("=", 1) for item in command[index + 1].split(",") if "=" in item)
                if values.get("target") == target:
                    return Path(values["source"])
        raise AssertionError("Expected mount: " + target)

    def __call__(self, command, *, cwd, timeout, **kwargs):
        self.commands.append((command, cwd, timeout, kwargs))
        if command[:2] == ["docker", "run"] and "--download-db-only" in command:
            if not self.missing_db:
                directory = self.mount(command, "/cache") / "db"
                directory.mkdir()
                (directory / "metadata.json").write_text(json.dumps(self.db))
            return scan.CommandResult(self.db_exit)
        if command[:2] == ["docker", "build"]:
            tag = command[command.index("--tag") + 1]
            return scan.CommandResult(1 if self.build_failure and (":" + self.build_failure + "-") in tag else 0)
        if command[:3] == ["docker", "image", "save"]:
            Path(command[command.index("--output") + 1]).write_bytes(b"synthetic image archive")
            return scan.CommandResult(0)
        if command[:2] == ["docker", "run"]:
            directory = self.mount(command, "/reports")
            name = directory.parent.name
            if name != self.missing_report:
                data = self.changed_report if self.changed_report is not None else trivy_report()
                (directory / "report.json").write_text(json.dumps(data))
            return scan.CommandResult(2 if name == self.scan_failure else 0)
        if command[:2] == ["npm", "audit"]:
            raw = self.npm_bytes if self.npm_bytes is not None else json.dumps(self.npm).encode()
            return scan.CommandResult(self.npm_exit, raw)
        if command[:3] == ["docker", "image", "rm"]:
            return scan.CommandResult(0)
        raise AssertionError("Unexpected command: " + repr(command))


class ScanTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="bridge-scan-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / "source"
        tools = self.root / "tools" / "api-docs"
        tools.mkdir(parents=True)
        (self.root / "tools" / "security").mkdir()
        (self.root / scan.TRIVY_PIN).write_text("FROM " + SCANNER_IMAGE + "\n")
        self.lock = json.dumps({"lockfileVersion": 3, "packages": {"node_modules/example": {"version": "1.2.3"}}}).encode()
        (tools / "package-lock.json").write_bytes(self.lock)
        self.output = Path(self.temporary.name) / "output"
        self.output.mkdir()

    def collect(self, runner):
        result = scan.collect(self.root, self.output, runner=runner, now=lambda: NOW)
        return result, json.loads((self.output / "manifest.json").read_text())

    def test_complete_inventory_uses_archive_mounts_and_only_owned_tags(self):
        runner = FakeRunner()
        result, manifest = self.collect(runner)
        self.assertEqual(0, result)
        self.assertEqual([], manifest["errors"])
        self.assertTrue(all(component["complete"] for component in manifest["components"]))
        self.assertEqual(metadata(), manifest["scanner"]["database"])
        self.assertEqual(SCANNER_IMAGE, manifest["scanner"]["image"])
        self.assertEqual("0.76.1", manifest["scanner"]["version"])
        npm = manifest["components"][-1]
        self.assertEqual("npm-lock.json", npm["inventory"])
        self.assertEqual(hashlib.sha256(self.lock).hexdigest(), npm["inventory_sha256"])
        self.assertEqual(self.lock, (self.output / npm["inventory"]).read_bytes())
        builds = [command for command, *_ in runner.commands if command[:2] == ["docker", "build"]]
        self.assertEqual(4, len(builds))
        self.assertTrue(all("--pull" in command and "--no-cache" in command for command in builds))
        container_scans = [command for command, *_ in runner.commands if "--input" in command]
        self.assertEqual(4, len(container_scans))
        for command in container_scans:
            self.assertIn("vuln", command)
            self.assertIn("os,library", command)
            self.assertNotIn("--ignore-unfixed", command)
            self.assertTrue(any("target=/input,readonly" in arg for arg in command))
            self.assertFalse(any("docker.sock" in arg or str(self.root) in arg for arg in command))
        cleanup = next(command for command, *_ in runner.commands if command[:3] == ["docker", "image", "rm"])
        self.assertEqual(4, len(cleanup[3:]))
        self.assertTrue(all(tag.startswith("support-bridge-security:") for tag in cleanup[3:]))
        self.assertFalse(any("compose" in command for command, *_ in runner.commands))

    def test_build_failure_preserves_successful_components_and_inventory(self):
        runner = FakeRunner()
        runner.build_failure = "verification"
        result, manifest = self.collect(runner)
        components = {component["name"]: component for component in manifest["components"]}
        self.assertEqual(1, result)
        self.assertFalse(components["verification"]["complete"])
        self.assertIn("build failed", components["verification"]["error"])
        for name in ("runtime", "prometheus", "grafana", "npm"):
            self.assertTrue(components[name]["complete"])
            self.assertTrue((self.output / components[name]["report"]).is_file())

    def test_failed_scanner_cannot_turn_a_valid_report_into_a_clean_scan(self):
        runner = FakeRunner()
        runner.scan_failure = "runtime"
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertFalse(manifest["components"][0]["complete"])
        self.assertTrue((self.output / "runtime.json").is_file())

    def test_missing_scan_report_is_incomplete(self):
        runner = FakeRunner()
        runner.missing_report = "grafana"
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertFalse(manifest["components"][3]["complete"])
        self.assertFalse((self.output / "grafana.json").exists())

    def test_reports_retain_low_and_unfixed_findings_without_a_threshold(self):
        runner = FakeRunner()
        report = trivy_report()
        report["Results"][0]["Vulnerabilities"] = [
            {"VulnerabilityID": "CVE-2026-12345", "PkgName": "fixture", "InstalledVersion": "1", "FixedVersion": "", "Severity": "LOW"},
            {"VulnerabilityID": "GHSA-1234-5678-9abc", "PkgName": "another", "InstalledVersion": "2", "FixedVersion": "3", "Severity": "CRITICAL"},
        ]
        runner.changed_report = report
        result, _ = self.collect(runner)
        self.assertEqual(0, result)
        self.assertEqual(report, json.loads((self.output / "runtime.json").read_text()))

    def test_stale_database_prevents_container_scans_but_preserves_npm(self):
        runner = FakeRunner()
        runner.db["UpdatedAt"] = scan.timestamp(NOW - dt.timedelta(hours=49))
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertIsNone(manifest["scanner"]["database"])
        self.assertTrue(all(not component["complete"] for component in manifest["components"][:4]))
        self.assertTrue(manifest["components"][-1]["complete"])
        self.assertFalse(any(command[:2] == ["docker", "build"] for command, *_ in runner.commands))

    def test_missing_database_metadata_is_not_a_clean_inventory(self):
        runner = FakeRunner()
        runner.missing_db = True
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertTrue(any(error.startswith("database:") for error in manifest["errors"]))

    def test_npm_exit_one_is_a_complete_inventory_only_with_advisory_evidence(self):
        runner = FakeRunner()
        runner.npm_exit = 1
        runner.npm["metadata"]["vulnerabilities"]["total"] = 1
        runner.npm["vulnerabilities"] = {"example": {"severity": "high", "via": [{"url": "https://github.com/advisories/GHSA-1234-5678-9abc"}], "nodes": ["node_modules/example"]}}
        result, manifest = self.collect(runner)
        self.assertEqual(0, result)
        self.assertTrue(manifest["components"][-1]["complete"])
        self.assertEqual(runner.npm, json.loads((self.output / "npm.json").read_text()))

    def test_npm_failure_retains_raw_error_response_and_incomplete_manifest(self):
        runner = FakeRunner()
        runner.npm = {"error": {"code": "EAI_AGAIN", "summary": "registry unavailable"}}
        runner.npm_exit = 1
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertFalse(manifest["components"][-1]["complete"])
        self.assertEqual(runner.npm, json.loads((self.output / "npm.json").read_text()))

    def test_unknown_npm_exit_and_empty_exit_one_fail(self):
        for code in (1, 2):
            with self.subTest(code=code):
                with self.assertRaises(scan.ScanError):
                    scan.validate_npm(npm_report(), code)

    def test_invalid_json_is_retained_for_an_incomplete_npm_scan(self):
        runner = FakeRunner()
        runner.npm_bytes = b"incomplete registry response"
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertFalse(manifest["components"][-1]["complete"])
        self.assertEqual(runner.npm_bytes, (self.output / "npm.json").read_bytes())

    def test_collection_exception_still_writes_an_incomplete_manifest(self):
        def fail(*args, **kwargs):
            raise RuntimeError("unexpected local tool failure")
        result, manifest = self.collect(fail)
        self.assertEqual(1, result)
        self.assertTrue(manifest["finished_at"])
        self.assertTrue(all(not component["complete"] for component in manifest["components"]))
        self.assertTrue(manifest["errors"])

    def test_invalid_scanner_pin_keeps_npm_evidence_and_does_not_run_containers(self):
        (self.root / scan.TRIVY_PIN).write_text("FROM untrusted.example/scanner:latest\n")
        runner = FakeRunner()
        result, manifest = self.collect(runner)
        self.assertEqual(1, result)
        self.assertIsNone(manifest["scanner"]["image"])
        self.assertTrue(all(not component["complete"] for component in manifest["components"][:4]))
        self.assertTrue(manifest["components"][-1]["complete"])
        self.assertFalse(any(command[0] == "docker" for command, *_ in runner.commands))


class ValidationTests(unittest.TestCase):
    def test_scanner_pin_rejects_other_registries_mutable_tags_and_instructions(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            pin = root / scan.TRIVY_PIN
            pin.parent.mkdir(parents=True)
            pin.write_text("# tooling\nFROM " + SCANNER_IMAGE + "\n")
            self.assertEqual(("0.76.1", SCANNER_IMAGE), scan.load_scanner_pin(root))
            cases = [
                SCANNER_IMAGE.replace("ghcr.io/", "ghcr.io.untrusted.example/"),
                SCANNER_IMAGE.replace("aquasecurity/", "another/"),
                SCANNER_IMAGE.split("@")[0],
                SCANNER_IMAGE.replace("0.76.1", "latest"),
                SCANNER_IMAGE[:-1],
                SCANNER_IMAGE + "\nRUN echo unsafe",
            ]
            for image in cases:
                pin.write_text("FROM " + image + "\n")
                with self.subTest(image=image), self.assertRaises(scan.ScanError):
                    scan.load_scanner_pin(root)

    def test_database_rejects_old_schema_bad_timezone_and_future_clock(self):
        cases = [
            {**metadata(), "Version": 1},
            {**metadata(), "Version": True},
            {**metadata(), "UpdatedAt": "not a timestamp"},
            {**metadata(), "UpdatedAt": "2026-10-25T12:00:00"},
            {**metadata(), "DownloadedAt": scan.timestamp(NOW + dt.timedelta(minutes=11))},
            {**metadata(), "UpdatedAt": scan.timestamp(NOW - dt.timedelta(hours=49))},
        ]
        for case in cases:
            with self.subTest(case=case), self.assertRaises(scan.ScanError):
                scan.validate_database(case, NOW)

    def test_malformed_trivy_reports_are_rejected(self):
        cases = [
            {}, {**trivy_report(), "SchemaVersion": 3},
            {**trivy_report(), "Results": []},
            {**trivy_report(), "Results": None},
            {**trivy_report(), "Metadata": None},
            {**trivy_report(), "Metadata": {}},
            {**trivy_report(), "Results": [{"Target": "x", "Vulnerabilities": {}}]},
            {**trivy_report(), "Results": [{"Target": "x", "Vulnerabilities": [{"VulnerabilityID": "CVE-2026-12345"}]}]},
        ]
        for case in cases:
            with self.subTest(case=case), self.assertRaises(scan.ScanError):
                scan.validate_trivy(case)

    def test_npm_count_cannot_hide_missing_findings(self):
        report = npm_report()
        report["metadata"]["vulnerabilities"]["total"] = 1
        with self.assertRaisesRegex(scan.ScanError, "contradicts"):
            scan.validate_npm(report, 0)

    def test_output_does_not_overwrite_previous_evidence_or_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            root.mkdir()
            output = Path(temporary) / "report"
            output.mkdir()
            (output / "manifest.json").write_text("prior evidence")
            for target in (root, root.parent, output):
                with self.subTest(target=target), self.assertRaises(scan.ScanError):
                    scan.prepare_output(target, root)
            self.assertEqual("prior evidence", (output / "manifest.json").read_text())
            if os.name == "posix":
                alias = Path(temporary) / "alias"
                alias.symlink_to(output, target_is_directory=True)
                with self.assertRaises(scan.ScanError):
                    scan.prepare_output(alias, root)

    def test_report_size_is_bounded(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "report.json"
            source.write_bytes(b"x" * 11)
            with self.assertRaises(scan.ScanError):
                scan.read_bytes(source, 10)

    def test_real_subprocess_output_and_time_are_bounded(self):
        with self.assertRaisesRegex(scan.ScanError, "output exceeded"):
            scan.run_command([sys.executable, "-c", "print('x' * 1000000)"], cwd=Path.cwd(), timeout=5, stdout_limit=1024)
        with self.assertRaisesRegex(scan.ScanError, "timed out"):
            scan.run_command([sys.executable, "-c", "import time; time.sleep(5)"], cwd=Path.cwd(), timeout=1)


if __name__ == "__main__":
    unittest.main()
