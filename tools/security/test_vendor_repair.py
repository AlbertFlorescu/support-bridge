#!/usr/bin/env python3
"""Offline regression tests for the privileged vendor-repair boundary."""

import base64
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("vendor_repair", Path(__file__).with_name("vendor_repair.py"))
repair = importlib.util.module_from_spec(spec)
spec.loader.exec_module(repair)
REPOSITORY = "maintainer/support-bridge"
HEAD, NEW_HEAD, TREE = "a" * 40, "c" * 40, "b" * 40


def encoded(value):
    return json.dumps(value).encode()


def git_hash(content):
    return hashlib.sha1(b"blob " + str(len(content)).encode() + b"\0" + content).hexdigest()


def package_files():
    package = {"name": "support-bridge-api-tooling", "private": True,
               "scripts": {"postinstall": "never execute PR scripts"},
               "devDependencies": {"swagger-ui-dist": "5.34.0"}}
    lock = {"lockfileVersion": 3, "packages": {"": {}, "node_modules/swagger-ui-dist": {
        "version": "5.34.0", "resolved": "https://registry.npmjs.org/swagger-ui-dist/-/swagger-ui-dist-5.34.0.tgz",
        "integrity": "sha512-" + base64.b64encode(b"x" * 64).decode()}}}
    return package, lock


def fresh_assets(unused_directory=None, unused_version=None):
    return {repair.ASSET_DIRECTORY + name: b"fresh-" + name.encode() for name in repair.ASSET_NAMES}


class FakeAPI:
    def __init__(self):
        package, lock = package_files()
        self.contents = {"tools/api-docs/package.json": encoded(package), "tools/api-docs/package-lock.json": encoded(lock)}
        self.contents.update({path: b"old-" + content for path, content in fresh_assets().items()})
        self.pull = {"number": 12, "state": "open", "changed_files": 2,
                     "user": {"login": "dependabot[bot]", "type": "Bot"},
                     "base": {"ref": "main", "repo": {"full_name": REPOSITORY}},
                     "head": {"ref": "dependabot/npm/api-tooling", "sha": HEAD, "repo": {"full_name": REPOSITORY}}}
        self.changed = [{"filename": name, "status": "modified"} for name in sorted(repair.PACKAGE_PATHS)]
        self.calls = []
        self.reads = 0
        self.move_on_read = None
        self.race_before_commit = None
        self.graphql_error = False
        self.truncated = False
        self.symlink_path = None
        self.permission = "write"

    def request(self, method, path, payload=None):
        self.calls.append((method, path, copy.deepcopy(payload)))
        suffix = path.removeprefix("repos/" + REPOSITORY + "/")
        if method == "GET" and suffix == "pulls/12":
            self.reads += 1
            if self.move_on_read == self.reads:
                self.pull["head"]["sha"] = "e" * 40
            return copy.deepcopy(self.pull)
        if method == "GET" and suffix.startswith("pulls/12/files?"):
            return copy.deepcopy(self.changed)
        if method == "GET" and suffix.startswith("pulls?state=open"):
            return [copy.deepcopy(self.pull)]
        if method == "GET" and suffix.startswith("collaborators/"):
            return {"permission": self.permission}
        if method == "GET" and suffix.startswith("git/commits/"):
            return {"tree": {"sha": TREE}}
        if method == "GET" and suffix.startswith("git/trees/"):
            return {"truncated": self.truncated, "tree": [
                {"path": name, "mode": "120000" if name == self.symlink_path else "100644", "type": "blob", "sha": git_hash(content)}
                for name, content in self.contents.items()]}
        if method == "GET" and suffix.startswith("git/blobs/"):
            blob = suffix.rsplit("/", 1)[1]
            for content in self.contents.values():
                if git_hash(content) == blob:
                    return {"encoding": "base64", "size": len(content), "content": base64.b64encode(content).decode()}
        if method == "POST" and path == "graphql":
            branch = payload["variables"]["input"]["branch"]
            if set(branch) != {"repositoryNameWithOwner", "branchName"}:
                return {"errors": [{"message": "Unsupported CommittableBranch input"}]}
            if self.race_before_commit is not None:
                self.pull["head"]["sha"] = self.race_before_commit
            if self.graphql_error or payload["variables"]["input"]["expectedHeadOid"] != self.pull["head"]["sha"]:
                return {"data": {"createCommitOnBranch": None}, "errors": [{"message": "Head does not match expectedHeadOid"}]}
            self.pull["head"]["sha"] = NEW_HEAD
            self.changed = [{"filename": name, "status": "modified"} for name in sorted(repair.ALLOWED_PATHS)]
            self.pull["changed_files"] = len(self.changed)
            return {"data": {"createCommitOnBranch": {"commit": {"oid": NEW_HEAD}}}}
        if method == "POST" and suffix == "actions/workflows/ci.yml/dispatches":
            return None
        raise AssertionError(f"Unexpected API call: {method} {suffix}")


class VendorRepairTests(unittest.TestCase):
    def prepare(self, api, **kwargs):
        return repair.prepare(api, REPOSITORY, 12, installer=fresh_assets, **kwargs)

    def test_prepare_and_publish_only_static_assets(self):
        api = FakeAPI()
        manifest = self.prepare(api, expected_head=HEAD)
        self.assertTrue(manifest["changed"])
        self.assertEqual({file["path"] for file in manifest["files"]}, repair.ASSET_PATHS)
        self.assertFalse(any(method != "GET" for method, unused_path, unused_payload in api.calls))
        result = repair.publish(api, REPOSITORY, manifest)
        self.assertEqual(result, {"changed": True, "head_sha": NEW_HEAD, "ci_dispatched": True})
        mutation = next(payload for method, path, payload in api.calls if method == "POST" and path == "graphql")
        self.assertEqual(mutation["query"], repair.CREATE_COMMIT)
        commit = mutation["variables"]["input"]
        self.assertEqual(commit["expectedHeadOid"], HEAD)
        self.assertEqual(commit["branch"], {"repositoryNameWithOwner": REPOSITORY, "branchName": manifest["head_ref"]})
        self.assertEqual(set(commit["fileChanges"]), {"additions"})
        self.assertEqual({item["path"] for item in commit["fileChanges"]["additions"]}, repair.ASSET_PATHS)
        self.assertFalse(any(method == "PATCH" or "/git/blobs" in path and method == "POST" for method, path, unused_payload in api.calls))
        self.assertTrue(api.calls[-1][1].endswith("ci.yml/dispatches"))

    def test_idempotent_when_assets_match(self):
        api = FakeAPI()
        api.contents.update(fresh_assets())
        manifest = self.prepare(api)
        self.assertFalse(manifest["changed"])
        self.assertEqual(repair.publish(api, REPOSITORY, manifest), {"changed": False})
        self.assertTrue(all(method == "GET" for method, unused_path, unused_payload in api.calls))

    def test_fork_nonbot_wrong_base_and_source_changes_rejected(self):
        for modification in ("fork", "author", "base", "script", "rename", "removed"):
            with self.subTest(modification=modification):
                api = FakeAPI()
                if modification == "fork":
                    api.pull["head"]["repo"]["full_name"] = "attacker/fork"
                elif modification == "author":
                    api.pull["user"] = {"login": "unknown-user", "type": "User"}
                elif modification == "base":
                    api.pull["base"]["ref"] = "release"
                elif modification == "script":
                    api.changed[0]["filename"] = "tools/api-docs/vendor.cjs"
                elif modification == "rename":
                    api.changed[0]["previous_filename"] = "unreviewed.js"
                else:
                    api.changed[0]["status"] = "removed"
                with self.assertRaises(repair.Ineligible):
                    self.prepare(api)

    def test_manual_author_needs_repository_write_access(self):
        api = FakeAPI()
        api.pull["user"] = {"login": "maintainer", "type": "User"}
        self.assertTrue(self.prepare(api, manual=True)["changed"])
        api.permission = "read"
        with self.assertRaises(repair.Ineligible):
            self.prepare(api, manual=True)

    def test_manual_dispatch_also_accepts_native_bot(self):
        self.assertTrue(self.prepare(FakeAPI(), manual=True)["changed"])

    def test_changed_file_count_and_tree_truncation_fail_closed(self):
        api = FakeAPI()
        api.changed.pop()
        with self.assertRaisesRegex(repair.RepairError, "incomplete"):
            self.prepare(api)
        api = FakeAPI()
        api.truncated = True
        with self.assertRaisesRegex(repair.RepairError, "incomplete"):
            self.prepare(api)

    def test_repository_symlink_rejected_before_blob_read(self):
        api = FakeAPI()
        api.symlink_path = "tools/api-docs/package.json"
        with self.assertRaisesRegex(repair.RepairError, "ordinary"):
            self.prepare(api)
        self.assertFalse(any("git/blobs/" in path for unused_method, path, unused_payload in api.calls))

    def test_stale_preparation_and_publication_do_not_update_ref(self):
        api = FakeAPI()
        with self.assertRaisesRegex(repair.Ineligible, "moved"):
            self.prepare(api, expected_head="f" * 40)
        api = FakeAPI()
        api.move_on_read = 2
        with self.assertRaisesRegex(repair.Ineligible, "moved"):
            self.prepare(api)
        api = FakeAPI()
        manifest = self.prepare(api)
        api.pull["head"]["sha"] = "e" * 40
        with self.assertRaisesRegex(repair.Ineligible, "moved"):
            repair.publish(api, REPOSITORY, manifest)
        self.assertFalse(any(method != "GET" for method, unused_path, unused_payload in api.calls))

    def test_forward_and_ancestor_reset_races_fail_atomic_comparison(self):
        for raced_head in ("e" * 40, "0" * 40):
            with self.subTest(raced_head=raced_head):
                api = FakeAPI()
                manifest = self.prepare(api)
                api.race_before_commit = raced_head
                with self.assertRaisesRegex(repair.RepairError, "rejected"):
                    repair.publish(api, REPOSITORY, manifest)
                self.assertEqual(api.pull["head"]["sha"], raced_head)
                self.assertFalse(any(path.endswith("dispatches") for unused_method, path, unused_payload in api.calls))
                self.assertFalse(any(method == "PATCH" for method, unused_path, unused_payload in api.calls))

    def test_graphql_errors_do_not_dispatch_or_claim_success(self):
        api = FakeAPI()
        manifest = self.prepare(api)
        api.graphql_error = True
        with self.assertRaisesRegex(repair.RepairError, "rejected"):
            repair.publish(api, REPOSITORY, manifest)
        self.assertEqual(api.pull["head"]["sha"], HEAD)
        self.assertFalse(any(path.endswith("dispatches") for unused_method, path, unused_payload in api.calls))

    def test_tampered_manifest_rejected_without_writes(self):
        original = self.prepare(FakeAPI())
        for alteration in ("path", "hash", "repository", "duplicate", "manual", "extra"):
            with self.subTest(alteration=alteration):
                manifest = copy.deepcopy(original)
                if alteration == "path":
                    manifest["files"][0]["path"] = ".github/workflows/ci.yml"
                elif alteration == "hash":
                    manifest["files"][0]["sha256"] = "0" * 64
                elif alteration == "repository":
                    manifest["repository"] = "attacker/fork"
                elif alteration == "duplicate":
                    manifest["files"] = [manifest["files"][0]] * 2
                elif alteration == "manual":
                    manifest["manual"] = "true"
                else:
                    manifest["command"] = "arbitrary input"
                api = FakeAPI()
                with self.assertRaises(repair.RepairError):
                    repair.publish(api, REPOSITORY, manifest)
                self.assertEqual(api.calls, [])

    def test_lockfile_external_sources_links_and_missing_integrity_rejected(self):
        package, original = package_files()
        for change in ("host", "git", "link", "integrity", "workspace"):
            with self.subTest(change=change):
                lock = copy.deepcopy(original)
                entry = lock["packages"]["node_modules/swagger-ui-dist"]
                if change == "host":
                    entry["resolved"] = "https://attacker.invalid/package.tgz"
                elif change == "git":
                    entry["resolved"] = "git+https://github.com/attacker/package.git"
                elif change == "link":
                    entry["link"] = True
                elif change == "integrity":
                    del entry["integrity"]
                else:
                    package = copy.deepcopy(package)
                    package["workspaces"] = ["../external"]
                with self.assertRaises(repair.RepairError):
                    repair.validate_packages(encoded(package), encoded(lock))

    def test_npm_install_scrubs_credentials_and_disables_scripts(self):
        with tempfile.TemporaryDirectory() as isolated:
            directory = Path(isolated)
            def fake_run(command, **kwargs):
                self.assertIn("--ignore-scripts", command)
                self.assertIn("https://registry.npmjs.org/", command)
                self.assertNotIn("GITHUB_TOKEN", kwargs["env"])
                self.assertNotIn("NPM_TOKEN", kwargs["env"])
                source = directory / "node_modules/swagger-ui-dist"
                source.mkdir(parents=True)
                (source / "package.json").write_text('{"version":"5.34.0"}')
                for name in repair.ASSET_NAMES:
                    (source / name).write_bytes(name.encode())
                return subprocess.CompletedProcess(command, 0)
            with patch.dict("os.environ", {"GITHUB_TOKEN": "private-test-token", "NPM_TOKEN": "private-test-token"}), patch.object(repair.shutil, "which", return_value="/trusted/npm"), patch.object(repair.subprocess, "run", side_effect=fake_run):
                self.assertEqual(set(repair.install_assets(directory, "5.34.0")), repair.ASSET_PATHS)

    def test_local_asset_symlinks_and_oversized_files_rejected(self):
        with tempfile.TemporaryDirectory() as isolated:
            directory = Path(isolated)
            real = directory / "real"
            real.write_bytes(b"data")
            link = directory / "link"
            link.symlink_to(real)
            with self.assertRaisesRegex(repair.RepairError, "Symbolic"):
                repair.regular_bytes(link, 10, directory)
            with self.assertRaisesRegex(repair.RepairError, "size"):
                repair.regular_bytes(real, 1, directory)

    def test_selector_binds_run_repository_event_and_exact_head(self):
        event = {"workflow_run": {"name": "Verify", "event": "pull_request", "status": "completed", "head_sha": HEAD,
                                  "repository": {"full_name": REPOSITORY}, "head_repository": {"full_name": REPOSITORY}, "pull_requests": [{"number": 12}]}}
        result = repair.select(FakeAPI(), REPOSITORY, "workflow_run", event)
        self.assertEqual(result, {"eligible": "true", "pr": "12", "manual": "false", "head_sha": HEAD})
        event["workflow_run"]["head_repository"]["full_name"] = "attacker/fork"
        api = FakeAPI()
        self.assertEqual(repair.select(api, REPOSITORY, "workflow_run", event)["eligible"], "false")
        self.assertEqual(api.calls, [])

    def test_selector_open_pr_fallback_and_manual_dispatch(self):
        event = {"workflow_run": {"name": "Verify", "event": "pull_request", "status": "completed", "head_sha": HEAD,
                                  "repository": {"full_name": REPOSITORY}, "head_repository": {"full_name": REPOSITORY}, "pull_requests": []}}
        self.assertEqual(repair.select(FakeAPI(), REPOSITORY, "workflow_run", event)["pr"], "12")
        api = FakeAPI()
        api.pull["user"] = {"login": "maintainer", "type": "User"}
        self.assertEqual(repair.select(api, REPOSITORY, "workflow_dispatch", {"inputs": {"pr": "12"}})["manual"], "true")
        with self.assertRaises(repair.RepairError):
            repair.select(api, REPOSITORY, "workflow_dispatch", {"inputs": {"pr": "12\nmanual=true"}})


@unittest.skipUnless(shutil.which("node"), "Node is needed for the vendor freshness fixture.")
class VendorFreshnessTests(unittest.TestCase):
    def test_fresh_corrupt_and_symlinked_assets(self):
        script = Path(__file__).parents[1] / "api-docs/check-vendor.cjs"
        with tempfile.TemporaryDirectory() as isolated:
            root = Path(isolated)
            tool = root / "tools/api-docs"
            source = tool / "node_modules/swagger-ui-dist"
            target = root / "public/assets/swagger-ui"
            source.mkdir(parents=True)
            target.mkdir(parents=True)
            checker = tool / "check-vendor.cjs"
            checker.write_bytes(script.read_bytes())
            for name in repair.ASSET_NAMES:
                (source / name).write_bytes(name.encode())
                (target / name).write_bytes(name.encode())
            run = lambda: subprocess.run([shutil.which("node"), str(checker)], capture_output=True)
            self.assertEqual(run().returncode, 0)
            damaged = target / repair.ASSET_NAMES[0]
            damaged.write_bytes(b"stale version")
            self.assertNotEqual(run().returncode, 0)
            damaged.unlink()
            damaged.symlink_to(source / repair.ASSET_NAMES[0])
            self.assertNotEqual(run().returncode, 0)


if __name__ == "__main__":
    unittest.main()
