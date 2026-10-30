#!/usr/bin/env python3
"""Offline tests for exact-revision publication and interrupted operations."""

import copy
import importlib.util
import io
from pathlib import Path
import sys
import unittest
from urllib import parse
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("publication", Path(__file__).with_name("publish.py"))
publication = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = publication
spec.loader.exec_module(publication)

SHA, MAIN, OTHER = "a" * 40, "b" * 40, "c" * 40
CONTEXT = publication.Context("maintainer/support-bridge", "chore/staging/escalation-workflows", SHA, "123")


class FakeAPI:
    def __init__(self):
        self.refs = {"main": MAIN, CONTEXT.source: SHA}
        self.calls = []
        self.pulls = []
        self.runs = []
        self.comparison = {"status": "ahead", "merge_base_commit": {"sha": MAIN}}
        self.before = None
        self.after_write = None

    def request(self, method, path, payload=None):
        self.calls.append((method, path, copy.deepcopy(payload)))
        if self.before:
            self.before(method, path, payload)
        if method == "GET" and path.startswith("git/ref/heads/"):
            branch = parse.unquote(path.removeprefix("git/ref/heads/"))
            if branch not in self.refs:
                raise publication.APIError(404, method, path)
            return {"object": {"sha": self.refs[branch]}}
        if method == "GET" and path.startswith("compare/"):
            return copy.deepcopy(self.comparison)
        if method == "GET" and path.startswith("pulls?"):
            return copy.deepcopy(self.pulls)
        if method == "GET" and path.startswith("actions/workflows/ci.yml/runs?"):
            return {"workflow_runs": copy.deepcopy(self.runs)}
        if method == "POST" and path == "git/refs":
            branch = payload["ref"].removeprefix("refs/heads/")
            if branch in self.refs:
                raise publication.APIError(422, method, path)
            self.refs[branch] = payload["sha"]
            result = {"object": {"sha": payload["sha"]}}
        elif method == "POST" and path == "pulls":
            result = {
                "number": 42,
                "head": {"sha": SHA, "ref": CONTEXT.destination,
                         "repo": {"full_name": CONTEXT.repository}},
                "base": {"ref": "main", "repo": {"full_name": CONTEXT.repository}},
            }
            self.pulls.append(result)
        elif method == "POST" and path == "actions/workflows/ci.yml/dispatches":
            self.runs.append({"head_sha": SHA, "head_branch": CONTEXT.destination,
                              "event": "workflow_dispatch", "status": "queued"})
            result = None
        else:
            raise AssertionError(f"Unexpected API call: {method} {path}")
        if self.after_write:
            self.after_write(method, path, payload)
        return copy.deepcopy(result)

    def writes(self):
        return [call for call in self.calls if call[0] != "GET"]


class PublicationTests(unittest.TestCase):
    def test_publishes_exact_commit_without_rewriting_authors_or_main(self):
        api = FakeAPI()
        result = publication.publish(api, CONTEXT)
        self.assertEqual(api.refs[CONTEXT.destination], SHA)
        self.assertEqual(api.refs["main"], MAIN)
        self.assertEqual(result["pull_request"], "https://github.com/maintainer/support-bridge/pull/42")
        self.assertEqual([(m, p) for m, p, _ in api.writes()], [
            ("POST", "git/refs"), ("POST", "pulls"),
            ("POST", "actions/workflows/ci.yml/dispatches")])
        self.assertEqual(api.writes()[0][2], {"ref": "refs/heads/" + CONTEXT.destination, "sha": SHA})
        self.assertFalse(api.writes()[1][2]["draft"])

    def test_rerun_observes_existing_branch_pr_and_ci_without_new_writes(self):
        api = FakeAPI()
        publication.publish(api, CONTEXT)
        api.calls.clear()
        result = publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])
        self.assertEqual(result["verification"], "existing run retained")

    def test_existing_failed_verification_is_not_blindly_retried(self):
        api = FakeAPI()
        publication.publish(api, CONTEXT)
        api.runs[0].update(status="completed", conclusion="failure")
        api.calls.clear()
        publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])

    def test_staging_moved_before_publication_has_no_effects(self):
        api = FakeAPI()
        api.refs[CONTEXT.source] = OTHER
        with self.assertRaisesRegex(publication.PublicationError, "staging branch moved"):
            publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])

    def test_current_main_must_be_an_ancestor(self):
        for comparison in [
            {"status": "diverged", "merge_base_commit": {"sha": OTHER}},
            {"status": "identical", "merge_base_commit": {"sha": MAIN}},
            {"status": "ahead", "merge_base_commit": {"sha": OTHER}}]:
            with self.subTest(comparison=comparison):
                api = FakeAPI()
                api.comparison = comparison
                with self.assertRaises(publication.PublicationError):
                    publication.publish(api, CONTEXT)
                self.assertEqual(api.writes(), [])

    def test_existing_branch_at_another_sha_is_never_overwritten(self):
        api = FakeAPI()
        api.refs[CONTEXT.destination] = OTHER
        with self.assertRaisesRegex(publication.PublicationError, "not be overwritten"):
            publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])

    def test_main_moving_before_branch_creation_stops_without_writes(self):
        api = FakeAPI()
        def move(method, path, payload):
            if path.startswith("compare/"):
                api.refs["main"] = OTHER
        api.before = move
        with self.assertRaisesRegex(publication.PublicationError, "Main moved"):
            publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])

    def test_staging_moving_after_branch_creation_prevents_pr_and_dispatch(self):
        api = FakeAPI()
        def move(method, path, payload):
            if path == "git/refs":
                api.refs[CONTEXT.source] = OTHER
        api.after_write = move
        with self.assertRaisesRegex(publication.PublicationError, "staging branch moved"):
            publication.publish(api, CONTEXT)
        self.assertEqual(len(api.writes()), 1)

    def test_destination_moving_after_creation_prevents_pr_and_dispatch(self):
        api = FakeAPI()
        def move(method, path, payload):
            if path == "git/refs":
                api.refs[CONTEXT.destination] = OTHER
        api.after_write = move
        with self.assertRaisesRegex(publication.PublicationError, "no longer"):
            publication.publish(api, CONTEXT)
        self.assertEqual(len(api.writes()), 1)

    def test_wrong_pr_identity_blocks_dispatch(self):
        api = FakeAPI()
        publication.publish(api, CONTEXT)
        api.pulls[0]["head"]["repo"]["full_name"] = "other/support-bridge"
        api.calls.clear()
        with self.assertRaisesRegex(publication.PublicationError, "does not match"):
            publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])

    def test_ambiguous_prs_block_dispatch(self):
        api = FakeAPI()
        publication.publish(api, CONTEXT)
        api.pulls.append(copy.deepcopy(api.pulls[0]))
        api.calls.clear()
        with self.assertRaisesRegex(publication.PublicationError, "at most one"):
            publication.publish(api, CONTEXT)
        self.assertEqual(api.writes(), [])

    def test_unknown_outcomes_are_observed_without_repeating_completed_writes(self):
        for uncertain_path in ["git/refs", "pulls", "actions/workflows/ci.yml/dispatches"]:
            with self.subTest(path=uncertain_path):
                api = FakeAPI()
                def lose_response(method, path, payload):
                    if path == uncertain_path:
                        raise publication.PublicationError("Response lost after accepted operation.")
                api.after_write = lose_response
                with self.assertRaises(publication.PublicationError):
                    publication.publish(api, CONTEXT)
                api.after_write = None
                publication.publish(api, CONTEXT)
                writes = [path for _, path, _ in api.writes()]
                self.assertEqual(writes.count(uncertain_path), 1)
                self.assertEqual(len(api.pulls), 1)
                self.assertEqual(len(api.runs), 1)


class EventTests(unittest.TestCase):
    def setUp(self):
        self.env = {
            "GITHUB_ACTIONS": "true", "GITHUB_SERVER_URL": "https://github.com",
            "GITHUB_API_URL": "https://api.github.com",
            "GITHUB_REPOSITORY": CONTEXT.repository, "GITHUB_SHA": SHA,
            "GITHUB_REF": "refs/heads/" + CONTEXT.source,
            "GITHUB_RUN_ID": "123", "GITHUB_EVENT_NAME": "push",
        }
        self.event = {"repository": {"full_name": CONTEXT.repository},
                      "ref": self.env["GITHUB_REF"], "after": SHA, "deleted": False, "forced": False}

    def test_genuine_staging_push_and_manual_staging_dispatch(self):
        self.assertEqual(publication.context_from_event(self.env, self.event), CONTEXT)
        self.env["GITHUB_EVENT_NAME"] = "workflow_dispatch"
        self.assertEqual(publication.context_from_event(self.env, self.event), CONTEXT)

    def test_unrelated_fork_main_tag_and_injected_ref_cannot_publish(self):
        for key, value in [
            ("GITHUB_REF", "refs/heads/main"),
            ("GITHUB_REF", "refs/tags/chore/staging/test"),
            ("GITHUB_REF", "refs/heads/chore/staging/a;bad"),
            ("GITHUB_REF", "refs/heads/chore/staging/a/../b"),
            ("GITHUB_EVENT_NAME", "pull_request_target"),
            ("GITHUB_API_URL", "https://example.com"),
            ("GITHUB_ACTIONS", "false"), ("GITHUB_SHA", "main"),
            ("GITHUB_RUN_ID", "0"), ("GITHUB_REPOSITORY", "../other")]:
            with self.subTest(key=key, value=value):
                env = dict(self.env, **{key: value})
                with self.assertRaises(publication.PublicationError):
                    publication.context_from_event(env, self.event)

    def test_forced_deleted_stale_or_mismatched_push_is_rejected(self):
        for key, value in [
            ("forced", True), ("deleted", True), ("after", OTHER),
            ("ref", "refs/heads/main"), ("repository", {"full_name": "other/project"})]:
            with self.subTest(key=key):
                with self.assertRaises(publication.PublicationError):
                    publication.context_from_event(self.env, dict(self.event, **{key: value}))

    def test_malformed_event_evidence_fails_before_any_effect(self):
        for event in [None, [], {}, {"repository": None}, {"repository": []}]:
            with self.subTest(event=event):
                with self.assertRaises(publication.PublicationError):
                    publication.context_from_event(self.env, event)

    def test_read_requests_bypass_cached_ref_observations(self):
        api = publication.GitHub(CONTEXT.repository, "controlled-test-token")
        requests = []
        class Opener:
            def open(self, req, timeout):
                requests.append(req)
                return io.BytesIO(b'{"object":{"sha":"observed"}}')
        api.opener = Opener()
        with patch.object(publication.time, "time_ns", side_effect=[101, 102]):
            api.request("GET", "git/ref/heads/main")
            api.request("GET", "git/ref/heads/main")
        self.assertNotEqual(requests[0].full_url, requests[1].full_url)
        self.assertEqual(requests[0].get_header("Cache-control"), "no-cache")
        self.assertEqual(parse.parse_qs(parse.urlsplit(requests[0].full_url).query), {"_observation": ["101"]})

    def test_api_rejects_missing_tokens_external_paths_and_redirects(self):
        with self.assertRaises(publication.PublicationError):
            publication.GitHub(CONTEXT.repository, "")
        api = publication.GitHub(CONTEXT.repository, "controlled-test-token")
        for path in ["https://example.com", "/outside", "../outside"]:
            with self.subTest(path=path):
                with self.assertRaises(publication.PublicationError):
                    api.request("GET", path)
        self.assertIsNone(publication.NoRedirect().redirect_request(
            None, None, 302, None, None, "https://example.com"))


if __name__ == "__main__":
    unittest.main()
