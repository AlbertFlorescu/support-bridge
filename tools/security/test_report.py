#!/usr/bin/env python3
"""Offline safety/identity tests for vulnerability collection and publication."""

import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlsplit

import report


REPO = "AlbertFlorescu/support-bridge"
SHA = "a" * 40
RUN = f"https://github.com/{REPO}/actions/runs/123"
CVE = "CVE-2024-0001"
GHSA = "GHSA-aaaa-bbbb-cccc"


class Sources:
    def __init__(self):
        self.calls = []
        self.cve_state = "PUBLISHED"
        self.nvd_state = "Analyzed"
        self.nvd_scores = {}
        self.nvd_wrong_id = False
        self.cve_wrong_id = False
        self.fail_nvd = False
        self.fail_alerts = False
        self.alerts = []

    def request(self, method, url, **kwargs):
        self.calls.append((method, url, kwargs))
        if "/dependabot/alerts" in url:
            if self.fail_alerts:
                raise report.FetchError("source HTTP 403", 403)
            return self.alerts
        if url.startswith("https://raw.githubusercontent.com/"):
            identifier = url.rsplit("/", 1)[-1][:-5]
            return {"dataVersion": "5.2", "cveMetadata": {"cveId": "CVE-2000-9999" if self.cve_wrong_id else identifier,
                    "state": self.cve_state, "dateUpdated": "2026-10-25T00:00:00Z"}}
        if url.startswith("https://services.nvd.nist.gov/"):
            if self.fail_nvd:
                raise report.FetchError("source HTTP 429", 429)
            ids = parse_qs(urlsplit(url).query)["cveIds"][0].split(",")
            return {"format": "NVD_CVE", "version": "2.0", "vulnerabilities": [{"cve": {
                "id": "CVE-2000-9999" if self.nvd_wrong_id else identifier,
                "vulnStatus": self.nvd_state, "lastModified": "2026-10-25T00:00:00Z",
                "metrics": self.nvd_scores}} for identifier in ids]}
        raise AssertionError("unexpected source URL")


class Issues:
    def __init__(self):
        self.issues = []
        self.posts = 0
        self.patches = 0
        self.lose_create_response = False

    def request(self, method, url, **kwargs):
        if method == "GET":
            return copy.deepcopy(self.issues)
        if method == "POST":
            self.posts += 1
            issue = {"number": len(self.issues) + 1, "state": "open", "body": kwargs["body"]["body"],
                     "user": {"login": "github-actions[bot]", "type": "Bot"}}
            self.issues.append(issue)
            if self.lose_create_response:
                self.lose_create_response = False
                raise report.FetchError("source connection failed")
            return copy.deepcopy(issue)
        if method == "PATCH":
            self.patches += 1
            number = int(url.rsplit("/", 1)[-1])
            issue = next(i for i in self.issues if i["number"] == number)
            self.assert_body_only(kwargs["body"])
            issue["body"] = kwargs["body"]["body"]
            return copy.deepcopy(issue)
        raise AssertionError("unexpected mutation")

    @staticmethod
    def assert_body_only(body):
        if set(body) != {"body"}:
            raise AssertionError("publisher changed issue state or other authority")


class ReportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="bridge-cve-test-")
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.sources = Sources()
        self.manifest = {"schema_version": 1, "started_at": "2026-10-25T00:00:00Z", "finished_at": "2026-10-25T00:01:00Z",
                         "scanner": {"name": "Trivy", "version": "0.76.1", "image": "test-image-sha", "database": {}},
                         "components": [{"name": name, "report": name + ".json", "complete": True} for name in report.COMPONENTS], "errors": []}
        for name in report.COMPONENTS[:-1]:
            self.write(name + ".json", {"SchemaVersion": 2, "Results": []})
        self.write("npm.json", {"auditReportVersion": 2, "vulnerabilities": {}})
        self.save_manifest()

    def write(self, name, value):
        (self.path / name).write_text(json.dumps(value), encoding="utf-8")

    def save_manifest(self):
        self.write("manifest.json", self.manifest)

    def add_vulnerability(self, identifier=CVE, fixed="", package="libexample", component="runtime", severity="HIGH"):
        self.write(component + ".json", {"SchemaVersion": 2, "Results": [{"Target": "example image sha256:" + "f" * 64,
            "Vulnerabilities": [{"VulnerabilityID": identifier, "PkgName": package, "InstalledVersion": "1.0", "FixedVersion": fixed, "Severity": severity}]}]})

    def build(self, **kwargs):
        return report.build_report(self.path, REPO, SHA, RUN, client=self.sources, **kwargs)

    def test_complete_empty_is_observation_not_security_claim(self):
        result = self.build()
        self.assertEqual(result["status"], "no_reported_findings")
        self.assertTrue(result["inventory_complete"])
        self.assertEqual(result["findings"], [])
        self.assertEqual(self.sources.calls, [])
        self.assertIn(report.NOTICE, report.render_summary(result))

    def test_missing_component_and_missing_manifest_are_incomplete(self):
        (self.path / "runtime.json").unlink()
        result = self.build()
        self.assertEqual(result["status"], "incomplete")
        self.assertIn("runtime: report unavailable or invalid", result["errors"])
        (self.path / "manifest.json").unlink()
        self.assertFalse(self.build()["inventory_complete"])

    def test_unfixed_and_ghsa_only_findings_survive(self):
        self.add_vulnerability(GHSA)
        result = self.build()
        self.assertEqual(result["status"], "attention_required")
        self.assertEqual(result["findings"][0]["advisory_id"], GHSA)
        self.assertEqual(result["findings"][0]["fixed_version"], "")
        self.assertEqual(result["enrichment"]["requested"], 0)

    def test_duplicates_merge_sources_targets_and_stronger_severity(self):
        one = report.finding("runtime", "foo", "1", "2", CVE, "MEDIUM", "first")
        two = report.finding("runtime", "foo", "1", "2", CVE, "HIGH", "second")
        merged = report.merge_findings([one, two])
        self.assertEqual(len(merged), 1)
        self.assertEqual(merged[0]["targets"], ["first", "second"])
        self.assertEqual(merged[0]["severity"], "HIGH")

    def test_advisory_aliases_do_not_collapse_different_branch_scopes(self):
        audit = report.finding("npm", "foo", "unknown", "2", GHSA, "HIGH", "lock", source="npm-audit")
        native = report.finding("default_branch_dependabot", "foo", "unknown", "2", GHSA, "HIGH", "manifest", aliases=[CVE], source="dependabot-default-branch")
        rows = report.merge_findings([audit, native])
        self.assertEqual(len(rows), 2)
        self.assertEqual({row["component"] for row in rows}, {"npm", "default_branch_dependabot"})
        self.assertEqual({row["advisory_id"] for row in rows}, {CVE})
        self.assertEqual({tuple(row["sources"]) for row in rows}, {("dependabot-default-branch",), ("npm-audit",)})
        self.assertTrue(all(row["aliases"] == [CVE, GHSA] for row in rows))

    def test_empty_looking_invalid_report_is_not_a_completed_scan(self):
        self.write("runtime.json", {"SchemaVersion": 2})
        self.assertEqual(self.build()["status"], "incomplete")

    def test_npm_uses_snapshot_version_not_affected_range(self):
        lock = {"lockfileVersion": 3, "packages": {"node_modules/foo": {"version": "1.2.3"}}}
        self.write("npm-lock.json", lock)
        self.manifest["components"][-1].update(inventory="npm-lock.json", inventory_sha256=hashlib.sha256((self.path / "npm-lock.json").read_bytes()).hexdigest())
        self.save_manifest()
        self.write("npm.json", {"auditReportVersion": 2, "vulnerabilities": {"foo": {
            "via": ["bar", {"source": 123, "url": "https://github.com/advisories/" + GHSA, "severity": "high", "range": "<2"}],
            "nodes": ["node_modules/foo"], "fixAvailable": {"name": "foo", "version": "2.0", "isSemVerMajor": True}}}})
        result = self.build()
        self.assertEqual(len(result["findings"]), 1)
        finding = result["findings"][0]
        self.assertEqual(finding["installed_version"], "1.2.3")
        self.assertEqual(finding["affected_range"], "<2")
        self.assertTrue(finding["fix_is_major"])

    def test_default_branch_alert_never_borrows_patched_pr_lock_version(self):
        self.write("npm-lock.json", {"lockfileVersion": 3, "packages": {"node_modules/foo": {"version": "3.0.0"}}})
        self.manifest["components"][-1].update(inventory="npm-lock.json")
        self.save_manifest()
        self.sources.alerts = [{
            "dependency": {"package": {"ecosystem": "npm", "name": "foo"}, "manifest_path": "tools/api-docs/package-lock.json"},
            "security_advisory": {"ghsa_id": GHSA, "identifiers": [{"type": "CVE", "value": CVE}]},
            "security_vulnerability": {"severity": "high", "vulnerable_version_range": "<2.0.0", "first_patched_version": {"identifier": "2.0.0"}},
        }]
        result = self.build(github_token="test-only-token")
        self.assertEqual(len(result["findings"]), 1)
        finding = result["findings"][0]
        self.assertEqual(finding["component"], "default_branch_dependabot")
        self.assertEqual(finding["observation_scope"], "default_branch")
        self.assertEqual(finding["installed_version"], "unknown")
        self.assertNotEqual(finding["installed_version"], "3.0.0")
        self.assertEqual(finding["affected_range"], "<2.0.0")
        self.assertEqual(finding["sources"], ["dependabot-default-branch"])

    def test_cve_path_keeps_leading_digits_and_bounds_identity(self):
        self.assertIn("/2024/0xxx/", report.cve_url("CVE-2024-0001"))
        self.assertIn("/2024/12xxx/", report.cve_url("CVE-2024-12345"))
        self.assertIn("/2024/1234567890123456xxx/", report.cve_url("CVE-2024-1234567890123456789"))
        for invalid in ("CVE-2024-123", "CVE-2024-" + "1" * 20, "../CVE-2024-0001"):
            with self.assertRaises(report.CollectionError):
                report.cve_url(invalid)

    def test_rejected_and_unscored_metadata_does_not_erase_finding(self):
        self.add_vulnerability()
        self.sources.cve_state = "REJECTED"
        self.sources.nvd_state = "Rejected"
        result = self.build()
        self.assertEqual(len(result["findings"]), 1)
        self.assertEqual(result["enrichment"]["records"][CVE]["cve_list"]["status"], "rejected")
        self.assertEqual(result["enrichment"]["records"][CVE]["nvd"]["metrics"], [])
        self.assertIn("Unknown / not supplied", report.render_summary(result))

    def test_mismatched_metadata_and_nvd_outage_are_partial_not_clean(self):
        self.add_vulnerability()
        self.sources.nvd_wrong_id = True
        result = self.build()
        self.assertEqual(result["status"], "enrichment_partial")
        self.assertTrue(result["inventory_complete"])
        self.assertTrue(result["attention_required"])
        self.sources.nvd_wrong_id = False
        self.sources.cve_wrong_id = True
        self.sources.fail_nvd = True
        self.assertEqual(self.build()["status"], "enrichment_partial")

    def test_unpublished_or_missing_sources_do_not_remove_observed_cves(self):
        self.add_vulnerability()

        class MissingSources(Sources):
            def request(self, method, url, **kwargs):
                if "raw.githubusercontent.com" in url:
                    raise report.FetchError("source HTTP 404", 404)
                if "services.nvd.nist.gov" in url:
                    return {"format": "NVD_CVE", "version": "2.0", "vulnerabilities": []}
                return super().request(method, url, **kwargs)

        self.sources = MissingSources()
        result = self.build()
        self.assertEqual(result["status"], "enrichment_partial")
        self.assertEqual(result["findings"][0]["advisory_id"], CVE)
        self.assertEqual(result["enrichment"]["records"][CVE]["cve_list"]["status"], "unavailable")

    def test_http_rate_limit_retries_are_finite_and_credentials_are_destination_bound(self):
        client = report.JsonClient()

        class RateLimited:
            calls = 0

            def open(self, request, timeout):
                self.calls += 1
                raise HTTPError(request.full_url, 429, "external arbitrary body", {}, None)

        client.opener = RateLimited()
        with patch.object(report.time, "sleep"):
            with self.assertRaisesRegex(report.FetchError, "source HTTP 429"):
                client.request("GET", "https://services.nvd.nist.gov/rest/json/cves/2.0?cveIds=" + CVE)
        self.assertEqual(client.opener.calls, 2)
        with self.assertRaises(report.FetchError):
            client.request("GET", report.cve_url(CVE), token="test-only-token")
        self.assertEqual(client.opener.calls, 2)

    def test_metrics_preserve_source_scores_without_invented_priority(self):
        value = {"metrics": {"cvssMetricV31": [
            {"source": "one", "type": "Primary", "cvssData": {"baseScore": 8.1, "baseSeverity": "HIGH"}},
            {"source": "two", "type": "Secondary", "cvssData": {"baseScore": 9.8, "baseSeverity": "CRITICAL"}}]}}
        self.assertEqual(len(report.nvd_metrics(value)), 2)
        value["metrics"]["cvssMetricV31"][0]["cvssData"]["baseScore"] = 11
        with self.assertRaises(report.CollectionError):
            report.nvd_metrics(value)

    def test_enrichment_bound_is_explicit(self):
        rows = [report.finding("runtime", "foo", "1", "", f"CVE-2024-{n:04}", "HIGH", "image") for n in range(1, 22)]
        result = report.enrich(rows, self.sources)
        self.assertEqual(result["requested"], 20)
        self.assertEqual(result["omitted"], 1)
        self.assertFalse(result["complete"])

    def test_failed_native_alert_collection_makes_inventory_incomplete(self):
        self.sources.fail_alerts = True
        result = self.build(github_token="test-only-token")
        self.assertEqual(result["status"], "incomplete")
        self.assertEqual(result["dependabot"]["status"], "incomplete")

    def test_fingerprint_ignores_run_image_and_scan_timestamps(self):
        self.add_vulnerability()
        original = self.build()
        self.manifest["scanner"]["image"] = "different-image"
        self.manifest["started_at"] = "2026-10-26T00:00:00Z"
        self.save_manifest()
        other = report.build_report(self.path, REPO, "b" * 40, RUN + "4", client=self.sources)
        self.assertEqual(original["fingerprint"], other["fingerprint"])

    def test_hostile_text_cannot_create_mentions_html_or_commands(self):
        self.add_vulnerability(GHSA, package="@everyone\n::error:: [link](javascript:bad) | <script>\x00 `")
        summary = report.render_summary(self.build())
        for hostile in ("@everyone", "::error::", "<script>", "\x00", "[link](javascript"):
            self.assertNotIn(hostile, summary)

    def test_report_path_cannot_escape_input_directory(self):
        self.manifest["components"][0]["report"] = "../runtime.json"
        self.save_manifest()
        self.assertEqual(self.build()["status"], "incomplete")

    def test_publisher_recovers_unknown_create_and_skips_unchanged(self):
        result = self.build()
        issues = Issues()
        issues.lose_create_response = True
        first = report.publish_report(result, REPO, issues, "test-only-token")
        second = report.publish_report(result, REPO, issues, "test-only-token")
        self.assertEqual(first["number"], second["number"])
        self.assertEqual(issues.posts, 1)
        self.assertFalse(second["changed"])
        self.assertEqual(issues.patches, 0)

    def test_incomplete_runs_retain_earlier_findings_and_human_notes(self):
        self.add_vulnerability()
        issues = Issues()
        report.publish_report(self.build(), REPO, issues, "test-only-token")
        issues.issues[0]["body"] += "\nKeep this human review note.\n"
        (self.path / "runtime.json").unlink()
        incomplete = self.build()
        report.publish_report(incomplete, REPO, issues, "test-only-token")
        self.assertIn(CVE, issues.issues[0]["body"])
        self.assertIn("Keep this human review note.", issues.issues[0]["body"])
        self.assertIn("Collection is incomplete", issues.issues[0]["body"])
        self.assertEqual(issues.issues[0]["state"], "open")
        self.add_vulnerability(GHSA)
        (self.path / "grafana.json").unlink()
        report.publish_report(self.build(), REPO, issues, "test-only-token")
        (self.path / "runtime.json").unlink()
        report.publish_report(self.build(), REPO, issues, "test-only-token")
        self.assertIn(CVE, issues.issues[0]["body"])
        self.assertIn(GHSA, issues.issues[0]["body"])

    def test_unrelated_issue_is_never_overwritten_and_closed_owned_issue_stays_closed(self):
        issues = Issues()
        issues.issues.append({"number": 1, "state": "open", "body": report.MARKER + "\nHuman issue", "user": {"login": "maintainer", "type": "User"}})
        result = self.build()
        report.publish_report(result, REPO, issues, "test-only-token")
        self.assertEqual(issues.issues[0]["body"], report.MARKER + "\nHuman issue")
        self.assertEqual(len(issues.issues), 2)
        issues.issues[1]["state"] = "closed"
        self.add_vulnerability(GHSA)
        report.publish_report(self.build(), REPO, issues, "test-only-token")
        self.assertEqual(issues.issues[1]["state"], "closed")


if __name__ == "__main__":
    unittest.main()
