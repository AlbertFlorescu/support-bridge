#!/usr/bin/env python3
"""Collect bounded, raw dependency scan evidence without changing the local stack."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
import threading
import uuid
from dataclasses import dataclass


TRIVY_PIN = Path("tools/security/trivy.Dockerfile")
MAX_REPORT_BYTES = 64 * 1024 * 1024
MAX_COMMAND_BYTES = 2 * 1024 * 1024
MAX_ARCHIVE_BYTES = 3 * 1024 * 1024 * 1024
MAX_DB_AGE = dt.timedelta(hours=48)
MAX_FUTURE_SKEW = dt.timedelta(minutes=10)
COMPONENT_BUILDS = (
    ("runtime", "Dockerfile", "app"),
    ("verification", "Dockerfile", "verification"),
    ("prometheus", "docker/prometheus.Dockerfile", None),
    ("grafana", "docker/grafana.Dockerfile", None),
)
SEVERITIES = {"UNKNOWN", "LOW", "MEDIUM", "HIGH", "CRITICAL"}


class ScanError(Exception):
    """An incomplete scan, distinct from a successfully reported vulnerability."""


@dataclass
class CommandResult:
    returncode: int
    stdout: bytes = b""
    stderr: bytes = b""


def _kill_process(process: subprocess.Popen) -> None:
    try:
        if os.name == "posix":
            os.killpg(process.pid, signal.SIGKILL)
        else:
            process.kill()
    except ProcessLookupError:
        pass


def run_command(
    argv: list[str], *, cwd: Path, timeout: int,
    stdout_limit: int = MAX_COMMAND_BYTES,
) -> CommandResult:
    """Drain both pipes with hard memory bounds; never invoke a shell."""
    try:
        process = subprocess.Popen(
            argv, cwd=cwd, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            start_new_session=os.name == "posix",
        )
    except OSError as exc:
        raise ScanError("command could not be started") from exc

    exceeded = threading.Event()
    buffers = [bytearray(), bytearray()]

    def drain(stream, buffer: bytearray, limit: int) -> None:
        try:
            while chunk := stream.read(65536):
                remaining = limit - len(buffer)
                if len(chunk) > remaining:
                    buffer.extend(chunk[:max(remaining, 0)])
                    exceeded.set()
                    _kill_process(process)
                    return
                buffer.extend(chunk)
        finally:
            stream.close()

    threads = [
        threading.Thread(target=drain, args=(process.stdout, buffers[0], stdout_limit), daemon=True),
        threading.Thread(target=drain, args=(process.stderr, buffers[1], MAX_COMMAND_BYTES), daemon=True),
    ]
    for thread in threads:
        thread.start()
    timed_out = False
    try:
        process.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        timed_out = True
        _kill_process(process)
        process.wait(timeout=10)
    finally:
        for thread in threads:
            thread.join(timeout=10)
    if timed_out:
        raise ScanError("command timed out")
    if exceeded.is_set() or any(thread.is_alive() for thread in threads):
        raise ScanError("command output exceeded its limit")
    return CommandResult(process.returncode, bytes(buffers[0]), bytes(buffers[1]))


def timestamp(value: dt.datetime) -> str:
    return value.astimezone(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_timestamp(value: object) -> dt.datetime:
    if not isinstance(value, str) or len(value) > 64:
        raise ScanError("database timestamp is missing or malformed")
    try:
        parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ScanError("database timestamp is missing or malformed") from exc
    if parsed.tzinfo is None:
        raise ScanError("database timestamp lacks a timezone")
    return parsed.astimezone(dt.timezone.utc)


def validate_database(data: object, now: dt.datetime) -> dict:
    if not isinstance(data, dict) or type(data.get("Version")) is not int or data["Version"] != 2:
        raise ScanError("unsupported vulnerability database schema")
    for field in ("UpdatedAt", "DownloadedAt"):
        observed = parse_timestamp(data.get(field))
        if observed > now + MAX_FUTURE_SKEW:
            raise ScanError("vulnerability database timestamp is in the future")
        if now - observed > MAX_DB_AGE:
            raise ScanError("vulnerability database is older than 48 hours")
    return {field: data[field] for field in ("Version", "UpdatedAt", "DownloadedAt")}


def read_bytes(path: Path, limit: int = MAX_REPORT_BYTES) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise ScanError("expected report file is missing")
    if path.stat().st_size > limit:
        raise ScanError("report exceeded its size limit")
    with path.open("rb") as source:
        data = source.read(limit + 1)
    if len(data) > limit:
        raise ScanError("report exceeded its size limit")
    return data


def decode_json(data: bytes) -> object:
    try:
        return json.loads(data)
    except (UnicodeDecodeError, json.JSONDecodeError, RecursionError) as exc:
        raise ScanError("report is not valid JSON") from exc


def load_scanner_pin(root: Path) -> tuple[str, str]:
    try:
        text = read_bytes(root / TRIVY_PIN, 4096).decode("ascii")
    except (UnicodeDecodeError, OSError) as exc:
        raise ScanError("scanner image pin could not be read") from exc
    declarations = [line.strip() for line in text.splitlines() if line.strip() and not line.lstrip().startswith("#")]
    if len(declarations) != 1:
        raise ScanError("scanner image pin must contain one FROM declaration")
    match = re.fullmatch(
        r"FROM (ghcr\.io/aquasecurity/trivy:(\d+\.\d+\.\d+)@sha256:[a-f0-9]{64})",
        declarations[0],
    )
    if match is None:
        raise ScanError("scanner image pin must use the approved registry and an immutable digest")
    return match.group(2), match.group(1)


def validate_trivy(data: object) -> None:
    if not isinstance(data, dict) or type(data.get("SchemaVersion")) is not int or data["SchemaVersion"] != 2:
        raise ScanError("unsupported Trivy report schema")
    if not isinstance(data.get("Results"), list) or not data["Results"]:
        raise ScanError("Trivy report has no results inventory")
    if data.get("ArtifactType") != "container_image" or not isinstance(data.get("Metadata"), dict):
        raise ScanError("Trivy report has no image metadata")
    image_id = data["Metadata"].get("ImageID")
    if not isinstance(image_id, str) or re.fullmatch(r"sha256:[a-f0-9]{64}", image_id) is None:
        raise ScanError("Trivy report has no immutable image identity")
    for result in data["Results"]:
        if not isinstance(result, dict) or not isinstance(result.get("Target"), str):
            raise ScanError("Trivy result is malformed")
        if "Packages" in result and not isinstance(result["Packages"], list):
            raise ScanError("Trivy package inventory is malformed")
        findings = result.get("Vulnerabilities", [])
        if not isinstance(findings, list):
            raise ScanError("Trivy vulnerability list is malformed")
        for finding in findings:
            if not isinstance(finding, dict):
                raise ScanError("Trivy vulnerability is malformed")
            if not all(isinstance(finding.get(field), str) and finding[field]
                       for field in ("VulnerabilityID", "PkgName", "InstalledVersion")):
                raise ScanError("Trivy vulnerability identity is missing")
            if not isinstance(finding.get("Severity"), str) or finding["Severity"] not in SEVERITIES:
                raise ScanError("Trivy vulnerability severity is unsupported")
            if "FixedVersion" in finding and not isinstance(finding["FixedVersion"], str):
                raise ScanError("Trivy fixed version is malformed")


def validate_npm(data: object, returncode: int) -> None:
    if not isinstance(data, dict) or "error" in data:
        raise ScanError("npm audit reported an error")
    if type(data.get("auditReportVersion")) is not int or data["auditReportVersion"] != 2:
        raise ScanError("unsupported npm audit report schema")
    vulnerabilities = data.get("vulnerabilities")
    metadata = data.get("metadata")
    if not isinstance(vulnerabilities, dict) or not isinstance(metadata, dict):
        raise ScanError("npm audit report has no dependency inventory")
    counts = metadata.get("vulnerabilities")
    if not isinstance(counts, dict) or type(counts.get("total")) is not int or counts["total"] < 0:
        raise ScanError("npm audit vulnerability count is malformed")
    if bool(vulnerabilities) != (counts["total"] > 0):
        raise ScanError("npm audit vulnerability count contradicts its evidence")
    for name, finding in vulnerabilities.items():
        if not isinstance(name, str) or not isinstance(finding, dict):
            raise ScanError("npm audit vulnerability is malformed")
        if not isinstance(finding.get("via"), list) or not isinstance(finding.get("nodes"), list):
            raise ScanError("npm audit advisory evidence is malformed")
        if not isinstance(finding.get("severity"), str) or finding["severity"] not in {"info", "low", "moderate", "high", "critical"}:
            raise ScanError("npm audit severity is unsupported")
    if returncode not in (0, 1):
        raise ScanError("npm audit command failed")
    if returncode == 1 and (counts["total"] == 0 or not vulnerabilities):
        raise ScanError("npm audit failed without vulnerability evidence")


def prepare_output(path: Path, root: Path) -> Path:
    absolute = path.absolute()
    if any(part in str(absolute) for part in ("\x00", "\n", "\r", ",")):
        raise ScanError("output path contains unsupported characters")
    if absolute.is_symlink():
        raise ScanError("output directory must not be a symlink")
    resolved = absolute.resolve()
    if resolved == root or resolved in root.parents:
        raise ScanError("output directory must not contain the source checkout")
    if resolved.exists() and (not resolved.is_dir() or any(resolved.iterdir())):
        raise ScanError("output directory must be new or empty")
    resolved.mkdir(parents=True, exist_ok=True)
    return resolved


def write_file(path: Path, data: bytes) -> None:
    with path.open("xb") as destination:
        destination.write(data)


def scanner_command(cache: Path, image: str, *mounts: tuple[Path, str, bool]) -> list[str]:
    identity = f"{os.getuid()}:{os.getgid()}" if hasattr(os, "getuid") else "1000:1000"
    command = [
        "docker", "run", "--rm", "--read-only", "--cap-drop=ALL",
        "--security-opt=no-new-privileges", "--user", identity,
        "--tmpfs", "/tmp:rw,nosuid,nodev,size=1g",
        "--mount", f"type=bind,source={cache},target=/cache",
    ]
    for source, target, readonly in mounts:
        command.extend(["--mount", f"type=bind,source={source},target={target}" + (",readonly" if readonly else "")])
    command.extend([image, "image", "--cache-dir", "/cache", "--disable-telemetry", "--skip-version-check"])
    return command


def collect(root: Path, output: Path, runner=run_command, now=None) -> int:
    clock = now or (lambda: dt.datetime.now(dt.timezone.utc))
    started = clock()
    manifest = {
        "schema_version": 1, "started_at": timestamp(started), "finished_at": None,
        "scanner": {"name": "Trivy", "version": None, "image": None, "database": None},
        "components": [{"name": name, "report": name + ".json", "complete": False}
                       for name in ("runtime", "verification", "prometheus", "grafana", "npm")],
        "errors": [],
    }
    components = {component["name"]: component for component in manifest["components"]}
    owned_images: list[str] = []

    def failed(component: dict, message: str) -> None:
        bounded = message.replace("\r", " ").replace("\n", " ")[:240]
        component["error"] = bounded
        manifest["errors"].append(component["name"] + ": " + bounded)

    def execute(command: list[str], timeout: int, **kwargs) -> CommandResult:
        return runner(command, cwd=root, timeout=timeout, **kwargs)

    try:
        with tempfile.TemporaryDirectory(prefix="support-bridge-security-") as temporary:
            work = Path(temporary)
            if "," in str(work) or "," in str(root):
                raise ScanError("working path contains unsupported characters")
            cache = work / "cache"
            cache.mkdir()
            try:
                version, image = load_scanner_pin(root)
                manifest["scanner"].update({"version": version, "image": image})
                downloaded = execute(scanner_command(cache, image) + ["--download-db-only"], 600)
                if downloaded.returncode != 0:
                    raise ScanError("vulnerability database download failed")
                metadata = decode_json(read_bytes(cache / "db" / "metadata.json", 16384))
                manifest["scanner"]["database"] = validate_database(metadata, clock())
            except ScanError as exc:
                manifest["errors"].append("database: " + str(exc)[:240])
            except (OSError, ValueError) as exc:
                manifest["errors"].append("database: metadata could not be read")

            if manifest["scanner"]["database"] is not None:
                for name, dockerfile, target in COMPONENT_BUILDS:
                    component = components[name]
                    print("Collecting security inventory: " + name, flush=True)
                    image = "support-bridge-security:" + name + "-" + uuid.uuid4().hex
                    owned_images.append(image)
                    directory = work / name
                    directory.mkdir()
                    inputs, reports = directory / "input", directory / "reports"
                    inputs.mkdir()
                    reports.mkdir()
                    try:
                        build = ["docker", "build", "--pull", "--no-cache", "--file", dockerfile, "--tag", image]
                        if target:
                            build.extend(["--target", target])
                        if execute(build + ["."], 1200).returncode != 0:
                            raise ScanError("image build failed")
                        archive = inputs / "image.tar"
                        if execute(["docker", "image", "save", "--output", str(archive), image], 300).returncode != 0:
                            raise ScanError("image archive creation failed")
                        if archive.is_symlink() or not archive.is_file() or not 0 < archive.stat().st_size <= MAX_ARCHIVE_BYTES:
                            raise ScanError("image archive is missing or exceeds its size limit")
                        result = execute(scanner_command(cache, manifest["scanner"]["image"], (inputs, "/input", True), (reports, "/reports", False)) + [
                            "--input", "/input/image.tar", "--scanners", "vuln", "--pkg-types", "os,library",
                            "--format", "json", "--output", "/reports/report.json", "--exit-code", "0", "--list-all-pkgs",
                            "--skip-db-update", "--offline-scan", "--timeout", "10m", "--parallel", "2",
                        ], 660)
                        source = reports / "report.json"
                        raw = read_bytes(source)
                        write_file(output / component["report"], raw)
                        if result.returncode != 0:
                            raise ScanError("image vulnerability scan failed")
                        validate_trivy(decode_json(raw))
                        component["complete"] = True
                    except ScanError as exc:
                        failed(component, str(exc))
                    except (OSError, ValueError):
                        failed(component, "image scan evidence could not be read or written")
            else:
                for name, _, _ in COMPONENT_BUILDS:
                    failed(components[name], "vulnerability database is unavailable")

            component = components["npm"]
            try:
                lock = read_bytes(root / "tools" / "api-docs" / "package-lock.json", 16 * 1024 * 1024)
                inventory = decode_json(lock)
                if not isinstance(inventory, dict) or type(inventory.get("lockfileVersion")) is not int or inventory["lockfileVersion"] not in (2, 3) or not isinstance(inventory.get("packages"), dict):
                    raise ScanError("npm lockfile inventory is unsupported")
                write_file(output / "npm-lock.json", lock)
                component["inventory"] = "npm-lock.json"
                component["inventory_sha256"] = hashlib.sha256(lock).hexdigest()
                npm = runner([
                    "npm", "audit", "--package-lock-only", "--include=dev", "--ignore-scripts", "--json",
                ], cwd=root / "tools" / "api-docs", timeout=180, stdout_limit=MAX_REPORT_BYTES)
                write_file(output / component["report"], npm.stdout)
                validate_npm(decode_json(npm.stdout), npm.returncode)
                component["complete"] = True
            except ScanError as exc:
                failed(component, str(exc))
            except (OSError, ValueError):
                failed(component, "npm audit evidence could not be read or written")
    except ScanError as exc:
        manifest["errors"].append("collection: " + str(exc)[:240])
    except (OSError, ValueError):
        manifest["errors"].append("collection: temporary scan workspace is unavailable")
    except Exception as exc:
        manifest["errors"].append("collection: unexpected " + type(exc).__name__[:80])
    finally:
        if owned_images:
            try:
                cleaned = execute(["docker", "image", "rm", *owned_images], 120)
                if cleaned.returncode != 0:
                    manifest["errors"].append("cleanup: one or more temporary image tags could not be removed")
            except ScanError:
                manifest["errors"].append("cleanup: temporary image cleanup could not complete")
        manifest["finished_at"] = timestamp(clock())
        write_file(output / "manifest.json", (json.dumps(manifest, indent=2) + "\n").encode())
    return 1 if manifest["errors"] or not all(c["complete"] for c in manifest["components"]) else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path, help="new or empty report directory")
    args = parser.parse_args(argv)
    root = Path(__file__).resolve().parents[2]
    try:
        output = prepare_output(args.output, root)
        return collect(root, output)
    except (ScanError, OSError):
        print("Security collection could not write to a safe, empty output directory.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
