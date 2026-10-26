#!/usr/bin/env python3
"""Bounded vulnerability reporting and a managed GitHub issue, using stdlib only."""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
from pathlib import Path
import re
import sys
import time
from datetime import datetime, timezone
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qsl, urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


CVE = re.compile(r"^CVE-[0-9]{4}-[0-9]{4,19}$")
GHSA = re.compile(r"^GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$")
REPOSITORY = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
COMPONENTS = ("runtime", "verification", "prometheus", "grafana", "npm")
SEVERITIES = {"CRITICAL": 0, "HIGH": 1, "MODERATE": 2, "MEDIUM": 2, "LOW": 3,
              "INFO": 4, "UNKNOWN": 5}
MARKER = "<!-- support-bridge-cve-tracker:v1 -->"
START = "<!-- support-bridge-cve-managed:start -->"
END = "<!-- support-bridge-cve-managed:end -->"
EVIDENCE_START = "<!-- support-bridge-cve-evidence:start -->"
EVIDENCE_END = "<!-- support-bridge-cve-evidence:end -->"
FINGERPRINT = "<!-- support-bridge-cve-fingerprint:"
NOTICE = "This product uses data from the NVD API but is not endorsed or certified by the NVD."
MAX_RESPONSE = 2 * 1024 * 1024
MAX_FINDINGS = 20000


class CollectionError(Exception):
    """A bounded, safe diagnostic; external response bodies are never included."""


class FetchError(CollectionError):
    def __init__(self, message, status=None):
        super().__init__(message)
        self.status = status


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class JsonClient:
    def __init__(self):
        self.opener = build_opener(NoRedirect())
        self.next_nvd = 0.0
        self.deadline = time.monotonic() + 180

    def get_page(self, url, token=""):
        """Return JSON plus only the Link header needed by cursor pagination."""
        return self.request("GET", url, token=token, include_link=True)

    def request(self, method, url, token="", body=None, nvd_key="", include_link=False):
        parsed = urlsplit(url)
        if parsed.scheme != "https" or parsed.netloc not in {
            "api.github.com", "raw.githubusercontent.com", "services.nvd.nist.gov"
        } or parsed.username or parsed.password or parsed.fragment:
            raise FetchError("unapproved source URL")
        if include_link and (method != "GET" or parsed.netloc != "api.github.com" or body is not None):
            raise FetchError("invalid paginated request")
        headers = {"Accept": "application/json", "User-Agent": "Support-Bridge-CVE-Tracker"}
        if token:
            if parsed.netloc != "api.github.com" or "\n" in token or "\r" in token:
                raise FetchError("invalid credential destination")
            headers["Authorization"] = "Bearer " + token
            headers["X-GitHub-Api-Version"] = "2022-11-28"
        if nvd_key:
            if parsed.netloc != "services.nvd.nist.gov" or "\n" in nvd_key or "\r" in nvd_key:
                raise FetchError("invalid NVD credential destination")
            headers["apiKey"] = nvd_key
        data = None if body is None else json.dumps(body).encode("utf-8")
        if data is not None:
            headers["Content-Type"] = "application/json"
        attempts = 2 if method == "GET" else 1
        for attempt in range(attempts):
            remaining = self.deadline - time.monotonic()
            if remaining <= 0:
                raise FetchError("source request time budget exhausted")
            if parsed.netloc == "services.nvd.nist.gov":
                delay = max(0, self.next_nvd - time.monotonic())
                if delay >= remaining:
                    raise FetchError("source request time budget exhausted")
                time.sleep(delay)
                self.next_nvd = time.monotonic() + 6
            try:
                with self.opener.open(Request(url, data=data, headers=headers, method=method), timeout=min(15, remaining)) as response:
                    raw = response.read(MAX_RESPONSE + 1)
                    link = response.headers.get("Link", "") if include_link else ""
                if len(raw) > MAX_RESPONSE:
                    raise FetchError("source response exceeds size limit")
                if len(link) > 16384:
                    raise FetchError("source pagination header exceeds size limit")
                result = json.loads(raw)
                return (result, link) if include_link else result
            except HTTPError as error:
                retryable = error.code in (403, 429, 500, 502, 503, 504)
                if retryable and attempt + 1 < attempts:
                    time.sleep(6)
                    continue
                raise FetchError("source HTTP " + str(error.code), error.code) from None
            except (URLError, TimeoutError, OSError):
                if attempt + 1 < attempts:
                    time.sleep(2)
                    continue
                raise FetchError("source connection failed") from None
            except (ValueError, UnicodeError):
                raise FetchError("source returned invalid JSON") from None


def read_json(path):
    if path.stat().st_size > 32 * 1024 * 1024:
        raise CollectionError("input exceeds size limit")
    with path.open(encoding="utf-8") as stream:
        return json.load(stream)


def text(value, default="", limit=512):
    if value is None:
        return default
    if not isinstance(value, str) or len(value) > limit:
        raise CollectionError("invalid or oversized text field")
    return value


def safe(value, limit=180):
    """One Markdown table cell: no HTML, mentions, command lines or new rows."""
    value = " ".join("".join(char if ord(char) >= 32 and not 127 <= ord(char) <= 159 else " " for char in str(value)).split())[:limit]
    value = html.escape(value, quote=True)
    for original, escaped in (("@", "&#64;"), ("|", "&#124;"), ("`", "&#96;"),
                              ("[", "&#91;"), ("]", "&#93;"), ("\\", "&#92;")):
        value = value.replace(original, escaped)
    return value.replace("::", "&#58;&#58;")


def cve_url(identifier):
    if not CVE.fullmatch(identifier):
        raise CollectionError("invalid CVE identifier")
    _, year, sequence = identifier.split("-")
    return ("https://raw.githubusercontent.com/CVEProject/cvelistV5/main/cves/"
            f"{year}/{sequence[:-3]}xxx/{identifier}.json")


def valid_aliases(values):
    return sorted({v for v in values if isinstance(v, str) and (CVE.fullmatch(v) or GHSA.fullmatch(v))})


def finding(component, package, installed, fixed, advisory, severity, target, aliases=(), source="trivy"):
    advisory = text(advisory, limit=128)
    if not advisory or not text(package):
        raise CollectionError("finding missing package or advisory")
    aliases = valid_aliases([advisory, *aliases])
    primary = next((a for a in aliases if CVE.fullmatch(a)), advisory)
    severity = text(severity, default="UNKNOWN").upper() or "UNKNOWN"
    if severity not in SEVERITIES:
        severity = "UNKNOWN"
    return {"component": component, "package": package, "installed_version": text(installed, default="unknown"),
            "fixed_version": text(fixed), "advisory_id": primary, "aliases": aliases,
            "severity": severity, "targets": [text(target, limit=2048)], "sources": [source]}


def normalize_trivy(data, component):
    if not isinstance(data, dict) or data.get("SchemaVersion") != 2 or not isinstance(data.get("Results"), list):
        raise CollectionError("unsupported Trivy report")
    rows = []
    for result in data.get("Results", []):
        if not isinstance(result, dict) or not isinstance(result.get("Vulnerabilities", []), (list, type(None))):
            raise CollectionError("invalid Trivy results")
        for vuln in result.get("Vulnerabilities") or []:
            if not isinstance(vuln, dict):
                raise CollectionError("invalid Trivy finding")
            rows.append(finding(component, text(vuln.get("PkgName")), text(vuln.get("InstalledVersion"), "unknown"),
                                text(vuln.get("FixedVersion")), text(vuln.get("VulnerabilityID")),
                                text(vuln.get("Severity"), "UNKNOWN"), text(result.get("Target"))))
    return rows


def lock_versions(lock, package, nodes=None):
    packages = lock.get("packages", {}) if isinstance(lock, dict) else {}
    if not isinstance(packages, dict):
        return ["unknown"]
    if nodes is not None:
        entries = [packages.get(node, {}) for node in nodes if isinstance(node, str)]
    else:
        entries = [entry for path, entry in packages.items()
                   if path.rsplit("node_modules/", 1)[-1] == package and path]
    versions = {text(entry.get("version")) for entry in entries if isinstance(entry, dict) and entry.get("version")}
    return sorted(versions) or ["unknown"]


def normalize_npm(data, lock):
    if not isinstance(data, dict) or data.get("auditReportVersion") != 2 or data.get("error") or not isinstance(data.get("vulnerabilities"), dict):
        raise CollectionError("unsupported npm audit report")
    rows = []
    for package, entry in data["vulnerabilities"].items():
        if not isinstance(entry, dict) or not isinstance(entry.get("via"), list) or not isinstance(entry.get("nodes", []), list):
            raise CollectionError("invalid npm audit finding")
        fix = entry.get("fixAvailable")
        fixed = text(fix.get("version")) if isinstance(fix, dict) else ""
        for advisory in entry["via"]:
            if isinstance(advisory, str):
                continue  # A transitive-effect reference, not another advisory.
            if not isinstance(advisory, dict):
                raise CollectionError("invalid npm advisory")
            url = urlsplit(text(advisory.get("url"), limit=2048))
            identifier = url.path.rsplit("/", 1)[-1] if url.hostname == "github.com" else ""
            if not GHSA.fullmatch(identifier):
                source = advisory.get("source")
                if not isinstance(source, int) or isinstance(source, bool) or source < 0:
                    raise CollectionError("npm advisory missing identity")
                identifier = "NPM-" + str(source)
            aliases = valid_aliases(advisory.get("cves", []) if isinstance(advisory.get("cves", []), list) else [])
            for installed in lock_versions(lock, package, entry.get("nodes", [])):
                row = finding("npm", text(package), installed, fixed, identifier,
                              text(advisory.get("severity"), "UNKNOWN"), "tools/api-docs/package-lock.json", aliases, "npm-audit")
                row["affected_range"] = text(advisory.get("range"), limit=2048)
                row["fix_available"] = bool(fix)
                row["fix_is_major"] = fix.get("isSemVerMajor") is True if isinstance(fix, dict) else False
                rows.append(row)
    return rows


def github_pages(client, path, token, page_limit=5):
    rows = []
    separator = "&" if "?" in path else "?"
    for page in range(1, page_limit + 1):
        data = client.request("GET", "https://api.github.com" + path + separator + urlencode({"per_page": 100, "page": page}), token=token)
        if not isinstance(data, list):
            raise FetchError("invalid GitHub collection")
        rows.extend(data)
        if len(data) < 100:
            return rows
    raise FetchError("GitHub pagination limit reached")


def native_next_url(link, expected_path, canonical_path=None):
    """Accept only a next cursor on this exact native-alert collection."""
    if not isinstance(link, str) or len(link) > 16384:
        raise FetchError("invalid native alert pagination header")
    candidate = None
    if not link:
        return None
    for entry in re.split(r",\s*(?=<)", link):
        match = re.fullmatch(r'\s*<([^<>]+)>\s*;\s*rel=(?:"([A-Za-z ]+)"|([A-Za-z]+))\s*', entry)
        if match is None:
            raise FetchError("invalid native alert pagination header")
        if "next" not in (match.group(2) or match.group(3)).split():
            continue
        if candidate is not None:
            raise FetchError("ambiguous native alert next link")
        parsed = urlsplit(match.group(1))
        if parsed.scheme != "https" or parsed.netloc != "api.github.com" or parsed.fragment:
            raise FetchError("invalid native alert next destination")
        try:
            pairs = parse_qsl(parsed.query, keep_blank_values=True, strict_parsing=True)
        except ValueError:
            raise FetchError("invalid native alert next query") from None
        query = dict(pairs)
        if len(query) != len(pairs) or set(query) != {"state", "per_page", "after"} or query["state"] != "open" or query["per_page"] != "100":
            raise FetchError("invalid native alert next query")
        cursor = query["after"]
        if not cursor or len(cursor) > 1024 or any(ord(char) < 33 or ord(char) > 126 for char in cursor):
            raise FetchError("invalid native alert cursor")
        if parsed.path != expected_path:
            if not re.fullmatch(r"/repositories/[1-9][0-9]*/dependabot/alerts", parsed.path) or canonical_path is None or parsed.path != canonical_path():
                raise FetchError("invalid native alert next destination")
        candidate = "https://api.github.com" + parsed.path + "?" + urlencode({"state": "open", "per_page": 100, "after": cursor})
    return candidate


def native_alert_pages(client, repository, token, page_limit=10):
    path = f"/repos/{repository}/dependabot/alerts"
    url = "https://api.github.com" + path + "?" + urlencode({"state": "open", "per_page": 100})
    visited, rows = set(), []
    verified_path = None

    def canonical_path():
        nonlocal verified_path
        if verified_path is None:
            metadata = client.request("GET", "https://api.github.com/repos/" + repository, token=token)
            if not isinstance(metadata, dict) or metadata.get("full_name") != repository or type(metadata.get("id")) is not int or metadata["id"] <= 0:
                raise FetchError("native alert repository identity mismatch")
            verified_path = f"/repositories/{metadata['id']}/dependabot/alerts"
        return verified_path

    for _ in range(page_limit):
        if url in visited:
            raise FetchError("native alert pagination cycle")
        visited.add(url)
        data, link = client.get_page(url, token=token)
        if not isinstance(data, list) or len(data) > 100:
            raise FetchError("invalid native alert collection")
        rows.extend(data)
        next_url = native_next_url(link, path, canonical_path)
        if next_url is None:
            return rows
        url = next_url
    raise FetchError("native alert pagination limit reached")


def native_alerts(client, repository, token):
    data = native_alert_pages(client, repository, token)
    rows = []
    for alert in data:
        dependency = alert.get("dependency", {})
        vulnerability = alert.get("security_vulnerability", {})
        advisory = alert.get("security_advisory", {})
        package = dependency.get("package", {})
        if not all(isinstance(v, dict) for v in (dependency, vulnerability, advisory, package)):
            raise CollectionError("invalid Dependabot alert")
        aliases = valid_aliases([i.get("value") for i in advisory.get("identifiers", []) if isinstance(i, dict)])
        identifier = text(advisory.get("ghsa_id"), limit=128)
        fixed = vulnerability.get("first_patched_version") or {}
        manifest = text(dependency.get("manifest_path"), limit=2048)
        # Native alerts describe GitHub's default-branch dependency graph. They do
        # not establish that a version in this run's PR/merge lockfile is affected.
        row = finding("default_branch_dependabot", text(package.get("name")), "unknown",
                      text(fixed.get("identifier")), identifier, text(vulnerability.get("severity"), "UNKNOWN"),
                      manifest, aliases, "dependabot-default-branch")
        row["observation_scope"] = "default_branch"
        row["affected_range"] = text(vulnerability.get("vulnerable_version_range"), limit=2048)
        rows.append(row)
    return rows


def merge_findings(rows):
    # Join advisory identities before deduplication. Component remains part of
    # the observation key so default-branch alerts cannot merge into a PR scan.
    parents = {}

    def root(value):
        parents.setdefault(value, value)
        while parents[value] != value:
            parents[value] = parents[parents[value]]
            value = parents[value]
        return value

    for row in rows:
        for alias in row["aliases"]:
            parents[root(alias)] = root(row["advisory_id"])
    groups = {}
    for alias in parents:
        groups.setdefault(root(alias), []).append(alias)
    merged = {}
    for original in rows:
        row = original.copy()
        aliases = valid_aliases(groups.get(root(row["advisory_id"]), row["aliases"]))
        row["aliases"] = aliases
        row["advisory_id"] = next((a for a in aliases if CVE.fullmatch(a)), aliases[0] if aliases else row["advisory_id"])
        key = tuple(row[field] for field in ("component", "package", "advisory_id", "installed_version", "fixed_version"))
        if key not in merged:
            merged[key] = row.copy()
            continue
        existing = merged[key]
        for field in ("aliases", "targets", "sources"):
            existing[field] = sorted(set(existing[field] + row[field]))
        if SEVERITIES[row["severity"]] < SEVERITIES[existing["severity"]]:
            existing["severity"] = row["severity"]
    return sorted(merged.values(), key=lambda r: (SEVERITIES[r["severity"]], r["component"], r["package"], r["advisory_id"], r["installed_version"], r["fixed_version"]))


def nvd_metrics(cve):
    rows = []
    metrics = cve.get("metrics", {})
    if not isinstance(metrics, dict):
        raise CollectionError("invalid NVD metrics")
    for version, key in (("4.0", "cvssMetricV40"), ("3.1", "cvssMetricV31"), ("3.0", "cvssMetricV30"), ("2.0", "cvssMetricV2")):
        values = metrics.get(key, [])
        if not isinstance(values, list):
            raise CollectionError("invalid NVD score list")
        for item in values:
            cvss = item.get("cvssData", {}) if isinstance(item, dict) else {}
            score = cvss.get("baseScore")
            if not isinstance(score, (int, float)) or isinstance(score, bool) or not 0 <= score <= 10:
                raise CollectionError("invalid NVD score")
            rows.append({"version": version, "score": score, "severity": text(cvss.get("baseSeverity"), "UNKNOWN"),
                         "source": text(item.get("source")), "type": text(item.get("type"))})
    return sorted(rows, key=lambda r: (r["version"], r["source"], r["type"], r["score"]))


def enrich(rows, client, nvd_key="", limit=20):
    identifiers = []
    for row in rows:  # Findings are already ordered by scanner severity.
        for identifier in valid_aliases([row["advisory_id"], *row["aliases"]]):
            if CVE.fullmatch(identifier) and identifier not in identifiers:
                identifiers.append(identifier)
    selected = identifiers[:limit]
    result = {"requested": len(selected), "omitted": len(identifiers) - len(selected), "records": {}, "complete": len(selected) == len(identifiers)}
    for identifier in selected:
        record = {"cve_list": {"status": "unavailable"}, "nvd": {"status": "unavailable", "metrics": []}}
        try:
            data = client.request("GET", cve_url(identifier))
            metadata = data.get("cveMetadata", {}) if isinstance(data, dict) else {}
            if not isinstance(metadata, dict) or metadata.get("cveId") != identifier or metadata.get("state") not in ("PUBLISHED", "REJECTED") or not str(data.get("dataVersion", "")).startswith("5."):
                raise CollectionError("invalid CVE List record")
            record["cve_list"] = {"status": metadata["state"].lower(), "data_version": data["dataVersion"],
                                  "updated_at": text(metadata.get("dateUpdated"))}
        except (FetchError, CollectionError):
            result["complete"] = False
        result["records"][identifier] = record
    if selected:
        try:
            url = "https://services.nvd.nist.gov/rest/json/cves/2.0?" + urlencode({"cveIds": ",".join(selected)})
            data = client.request("GET", url, nvd_key=nvd_key)
            values = data.get("vulnerabilities") if isinstance(data, dict) else None
            if data.get("format") != "NVD_CVE" or not isinstance(values, list):
                raise CollectionError("invalid NVD response")
            seen = set()
            for item in values:
                cve = item.get("cve", {}) if isinstance(item, dict) else {}
                identifier = cve.get("id")
                if identifier not in selected or identifier in seen:
                    raise CollectionError("NVD response identity mismatch")
                seen.add(identifier)
                result["records"][identifier]["nvd"] = {"status": text(cve.get("vulnStatus"), "unknown"),
                    "updated_at": text(cve.get("lastModified")), "metrics": nvd_metrics(cve)}
            if len(seen) != len(selected):
                result["complete"] = False
        except (FetchError, CollectionError, AttributeError):
            result["complete"] = False
    return result


def validate_context(repository, sha, run_url):
    if not REPOSITORY.fullmatch(repository) or any(part in (".", "..") for part in repository.split("/")) or not re.fullmatch(r"[0-9a-f]{40,64}", sha):
        raise CollectionError("invalid repository or revision")
    if not re.fullmatch(r"https://github\.com/" + re.escape(repository) + r"/actions/runs/[0-9]+", run_url):
        raise CollectionError("invalid workflow URL")


def build_report(input_dir, repository, sha, run_url, client=None, github_token="", nvd_key="", enrichment_limit=20):
    validate_context(repository, sha, run_url)
    client = client or JsonClient()
    errors, rows, components = [], [], []
    lock = {}
    try:
        manifest = read_json(input_dir / "manifest.json")
        if not isinstance(manifest, dict) or manifest.get("schema_version") != 1 or not isinstance(manifest.get("components"), list):
            raise CollectionError("invalid scan manifest")
    except (OSError, ValueError, CollectionError):
        manifest = {}
        errors.append("scan manifest unavailable or invalid")
    if manifest.get("errors"):
        errors.append("scan manifest reports collection errors")
    seen = set()
    for component in manifest.get("components", []):
        if not isinstance(component, dict) or component.get("name") not in COMPONENTS or component.get("name") in seen:
            errors.append("invalid or duplicate scan component")
            continue
        name = component["name"]
        seen.add(name)
        complete = component.get("complete") is True
        if not complete:
            errors.append(name + ": scanner marked collection incomplete")
        try:
            if component.get("report") != name + ".json":
                raise CollectionError("unexpected report path")
            data = read_json(input_dir / component["report"])
            if name == "npm":
                if component.get("inventory") == "npm-lock.json":
                    lock = read_json(input_dir / "npm-lock.json")
                    expected = component.get("inventory_sha256")
                    if expected and hashlib.sha256((input_dir / "npm-lock.json").read_bytes()).hexdigest() != expected:
                        raise CollectionError("npm inventory digest mismatch")
                rows.extend(normalize_npm(data, lock))
            else:
                rows.extend(normalize_trivy(data, name))
        except (OSError, ValueError, TypeError, CollectionError):
            complete = False
            errors.append(name + ": report unavailable or invalid")
        components.append({"name": name, "complete": complete})
    for missing in sorted(set(COMPONENTS) - seen):
        errors.append(missing + ": scan component absent")
    native = {"status": "not_requested"}
    if github_token:
        try:
            extra = native_alerts(client, repository, github_token)
            rows.extend(extra)
            native = {"status": "complete", "observations": len(extra)}
        except FetchError as error:
            known_causes = {"source connection failed", "source returned invalid JSON", "source request time budget exhausted",
                            "source response exceeds size limit", "source pagination header exceeds size limit",
                            "invalid native alert pagination header", "ambiguous native alert next link",
                            "invalid native alert next destination", "invalid native alert next query",
                            "invalid native alert cursor", "native alert pagination cycle",
                            "invalid native alert collection", "native alert pagination limit reached",
                            "native alert repository identity mismatch"}
            cause = ("HTTP " + str(error.status)) if type(error.status) is int and 100 <= error.status <= 599 else (
                str(error) if str(error) in known_causes else "collection or transport failure")
            native = {"status": "incomplete", "error": cause}
            errors.append("Dependabot alerts could not be collected completely (" + cause + ")")
        except (CollectionError, ValueError, TypeError, AttributeError):
            native = {"status": "incomplete"}
            errors.append("Dependabot alerts could not be collected completely (invalid alert response)")
    rows = merge_findings(rows)
    if len(rows) > MAX_FINDINGS:
        errors.append("finding limit reached; output is truncated")
        rows = rows[:MAX_FINDINGS]
    enrichment = enrich(rows, client, nvd_key, enrichment_limit)
    complete = not errors
    status = "incomplete" if not complete else ("enrichment_partial" if not enrichment["complete"] else ("attention_required" if rows else "no_reported_findings"))
    report = {"schema_version": 1, "repository": repository, "sha": sha, "run_url": run_url,
              "generated_at": datetime.now(timezone.utc).isoformat(), "status": status,
              "inventory_complete": complete, "attention_required": bool(rows), "findings": rows,
              "errors": sorted(set(errors)), "components": sorted(components, key=lambda r: r["name"]),
              "dependabot": native, "enrichment": enrichment,
              "scan": {key: manifest.get(key) for key in ("started_at", "finished_at", "scanner")}, "nvd_notice": NOTICE}
    identity_rows = [{key: row[key] for key in ("component", "package", "installed_version", "fixed_version", "advisory_id", "severity", "aliases", "sources")} for row in rows]
    identity_enrichment = {identifier: {source: {key: value for key, value in details.items() if key != "updated_at"}
                                      for source, details in record.items()} for identifier, record in enrichment["records"].items()}
    identity = {"status": status, "findings": identity_rows, "errors": report["errors"], "components": report["components"],
                "dependabot": native, "enrichment": identity_enrichment, "omitted": enrichment["omitted"]}
    report["fingerprint"] = hashlib.sha256(json.dumps(identity, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    return report


def render_summary(report):
    lines = ["## Vulnerability inventory", "", f"Status: **{safe(report['status'])}**. Observations: **{len(report['findings'])}**.", "",
             f"[Workflow evidence]({report['run_url']}) · revision `{report['sha']}`", "",
             "A scan reports known advisories for its observed inventory; it does not establish compliance or prove absence of vulnerabilities."]
    lines += ["", "Components: " + ", ".join(safe(c["name"]) + "=" + ("complete" if c["complete"] else "incomplete") for c in report["components"]) +
              "; native Dependabot alerts=" + safe(report["dependabot"]["status"]) + "."]
    if not report["inventory_complete"]:
        lines += ["", "**Collection is incomplete. Empty or reduced results must not clear earlier findings.**"]
    if report["errors"]:
        lines += ["", *["- " + safe(error) for error in report["errors"][:20]]]
    lines += ["", "| Component | Package | Installed | Advisory | Severity | Fix version/proposal |",
              "| --- | --- | --- | --- | --- | --- |"]
    for row in report["findings"][:60]:
        identifier = row["advisory_id"]
        advisory = safe(identifier, 60)
        if CVE.fullmatch(identifier):
            advisory = f"[{identifier}](https://nvd.nist.gov/vuln/detail/{identifier})"
        elif GHSA.fullmatch(identifier):
            advisory = f"[{identifier}](https://github.com/advisories/{identifier})"
        fields = [safe(row["component"], 32), safe(row["package"], 96), safe(row["installed_version"], 48), advisory,
                  safe(row["severity"], 16), safe(row["fixed_version"] or "No fix version reported", 96)]
        lines.append("| " + " | ".join(fields) + " |")
    if len(report["findings"]) > 60:
        lines += ["", f"Showing 60 of {len(report['findings'])} observations; the workflow's report.json artifact contains the retained inventory."]
    enrichment = report["enrichment"]
    lines += ["", "### CVE source enrichment", "", f"Exact IDs requested: {enrichment['requested']}; omitted by bound: {enrichment['omitted']}.", "",
              "| CVE | CVE List state | NVD state | Available CVSS scores |", "| --- | --- | --- | --- |"]
    for identifier, record in sorted(enrichment["records"].items()):
        scores = "; ".join(f"{score['version']}: {score['score']} ({score['type'] or 'unspecified source type'})" for score in record["nvd"]["metrics"])
        lines.append(f"| [{identifier}](https://www.cve.org/CVERecord?id={identifier}) | {safe(record['cve_list']['status'])} | {safe(record['nvd']['status'])} | {safe(scores or 'Unknown / not supplied', 180)} |")
    lines += ["", "Missing metadata or scores do not erase scanner findings. Rejected identifiers require review of the original advisory and aliases.", "", NOTICE]
    return "\n".join(lines) + "\n"


def between(value, start, end):
    if value.count(start) != 1 or value.count(end) != 1:
        raise CollectionError("tracker managed section is missing or ambiguous")
    before, rest = value.split(start, 1)
    middle, after = rest.split(end, 1)
    return before, middle, after


def managed_body(report, previous=""):
    summary = render_summary(report)
    if not report["inventory_complete"] and previous:
        _, retained, _ = between(previous, EVIDENCE_START, EVIDENCE_END)
        # Retain evidence through partial collections, including first-ever partial scans.
        if report["findings"]:
            retained = summary + "\n---\n" + retained
        evidence = retained
        heading = summary + "\n### Retained earlier evidence\n\n"
    else:
        evidence = summary
        heading = ""
    result = (heading + EVIDENCE_START + "\n" + evidence.strip() + "\n" + EVIDENCE_END + "\n" +
              FINGERPRINT + report["fingerprint"] + " -->")
    if len(result) > 50000:
        raise CollectionError("tracker body limit reached; previous evidence is retained")
    return result


def find_tracker(client, repository, token):
    issues = github_pages(client, f"/repos/{repository}/issues?state=all", token)
    matches = [issue for issue in issues if isinstance(issue, dict) and isinstance(issue.get("user"), dict) and not issue.get("pull_request") and
               issue.get("user", {}).get("login") == "github-actions[bot]" and
               issue.get("user", {}).get("type") == "Bot" and str(issue.get("body", "")).startswith(MARKER + "\n")]
    if len(matches) > 1:
        raise CollectionError("multiple managed trackers require maintainer review")
    return matches[0] if matches else None


def publish_report(report, repository, client, token):
    if not token or report.get("schema_version") != 1 or report.get("repository") != repository or not REPOSITORY.fullmatch(repository):
        raise CollectionError("invalid publisher context or missing token")
    validate_context(repository, report["sha"], report["run_url"])
    if not re.fullmatch(r"[0-9a-f]{64}", report.get("fingerprint", "")):
        raise CollectionError("invalid report fingerprint")
    issue = find_tracker(client, repository, token)
    if issue:
        before, previous, after = between(issue.get("body", ""), START, END)
        if FINGERPRINT + report["fingerprint"] + " -->" in previous:
            return {"number": issue["number"], "changed": False, "state": issue["state"]}
        body = before + START + "\n" + managed_body(report, previous) + "\n" + END + after
        if len(body) > 60000:
            raise CollectionError("tracker body limit reached; previous evidence is retained")
        client.request("PATCH", f"https://api.github.com/repos/{repository}/issues/{int(issue['number'])}", token=token, body={"body": body})
        return {"number": issue["number"], "changed": True, "state": issue["state"]}
    body = MARKER + "\n" + START + "\n" + managed_body(report) + "\n" + END + "\n\n## Maintainer notes\n\nAdd review notes below this heading; automation preserves this section.\n"
    for attempt in range(2):
        try:
            created = client.request("POST", f"https://api.github.com/repos/{repository}/issues", token=token,
                                     body={"title": "Vulnerability tracker", "body": body})
            return {"number": created["number"], "changed": True, "state": created["state"]}
        except FetchError:
            # A successful create can lose its response. Read before considering another create.
            recovered = find_tracker(client, repository, token)
            if recovered:
                return {"number": recovered["number"], "changed": True, "state": recovered["state"]}
            if attempt:
                raise
    raise CollectionError("tracker publication did not complete")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    build = commands.add_parser("build")
    build.add_argument("--input", type=Path, required=True)
    build.add_argument("--output", type=Path, required=True)
    build.add_argument("--repository", required=True)
    build.add_argument("--sha", required=True)
    build.add_argument("--run-url", required=True)
    publish = commands.add_parser("publish")
    publish.add_argument("--report", type=Path, required=True)
    publish.add_argument("--repository", required=True)
    args = parser.parse_args()
    try:
        if args.command == "build":
            report = build_report(args.input, args.repository, args.sha, args.run_url,
                                  github_token=os.environ.get("GITHUB_TOKEN", ""), nvd_key=os.environ.get("NVD_API_KEY", ""))
            args.output.mkdir(parents=True, exist_ok=True)
            (args.output / "report.json").write_text(json.dumps(report, sort_keys=True, indent=2) + "\n", encoding="utf-8")
            (args.output / "summary.md").write_text(render_summary(report), encoding="utf-8")
            print("Vulnerability report: " + report["status"] + "; observations=" + str(len(report["findings"])))
            return 0 if report["inventory_complete"] else 2
        report = read_json(args.report)
        result = publish_report(report, args.repository, JsonClient(), os.environ.get("GITHUB_TOKEN", ""))
        print("Tracker issue #" + str(result["number"]) + ": " + ("updated" if result["changed"] else "unchanged") + " (" + result["state"] + ")")
        return 0
    except (CollectionError, OSError, ValueError, KeyError, TypeError):
        print("Vulnerability reporting failed; inspect the workflow inputs and retained evidence.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
