#!/usr/bin/env python3
"""Publish a verified staging commit without changing its authorship."""

from dataclasses import dataclass
import json
import os
from pathlib import Path
import re
import sys
import time
from urllib import error, parse, request


class PublicationError(Exception):
    """A publication stopped; inspect remote state before retrying."""


class APIError(PublicationError):
    def __init__(self, status, method, path):
        self.status = status
        super().__init__(f"GitHub returned HTTP {status} for {method} {path}.")


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class GitHub:
    def __init__(self, repository, token):
        if not token or any(char.isspace() for char in token):
            raise PublicationError("A valid workflow token is required.")
        self.base = f"https://api.github.com/repos/{repository}/"
        self.token = token
        self.opener = request.build_opener(NoRedirect())

    def request(self, method, path, payload=None):
        if path.startswith("/") or "://" in path or ".." in path.split("/"):
            raise PublicationError("Invalid repository API path.")
        if method == "GET":
            separator = "&" if "?" in path else "?"
            path += separator + "_observation=" + str(time.time_ns())
        data = None if payload is None else json.dumps(payload).encode()
        req = request.Request(self.base + path, data=data, method=method, headers={
            "Authorization": "Bearer " + self.token,
            "Accept": "application/vnd.github+json",
            "Content-Type": "application/json",
            "Cache-Control": "no-cache",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "support-bridge-publication",
        })
        try:
            with self.opener.open(req, timeout=30) as response:
                raw = response.read(2 * 1024 * 1024 + 1)
        except error.HTTPError as exc:
            raise APIError(exc.code, method, path) from None
        except (error.URLError, OSError):
            raise PublicationError("GitHub request failed; inspect remote state before retrying.") from None
        if len(raw) > 2 * 1024 * 1024:
            raise PublicationError("GitHub response exceeded the publication limit.")
        try:
            return json.loads(raw) if raw else None
        except (ValueError, UnicodeError):
            raise PublicationError("GitHub returned an unreadable response.") from None


@dataclass(frozen=True)
class Context:
    repository: str
    source: str
    sha: str
    run_id: str

    @property
    def destination(self):
        return "feature/published-" + self.sha

    @property
    def run_url(self):
        return f"https://github.com/{self.repository}/actions/runs/{self.run_id}"


def context_from_event(env, event):
    repository = env.get("GITHUB_REPOSITORY", "")
    ref = env.get("GITHUB_REF", "")
    sha = env.get("GITHUB_SHA", "")
    run_id = env.get("GITHUB_RUN_ID", "")
    if (env.get("GITHUB_ACTIONS") != "true"
            or env.get("GITHUB_SERVER_URL") != "https://github.com"
            or env.get("GITHUB_API_URL") != "https://api.github.com"):
        raise PublicationError("Publication requires the GitHub.com Actions environment.")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9][A-Za-z0-9_.-]*", repository):
        raise PublicationError("Invalid repository identity.")
    if not re.fullmatch(r"refs/heads/chore/staging/[a-z0-9]+(?:[/-][a-z0-9]+)*", ref):
        raise PublicationError("Only a product staging branch can be published.")
    if not re.fullmatch(r"[0-9a-f]{40}", sha) or not re.fullmatch(r"[1-9][0-9]*", run_id):
        raise PublicationError("Invalid revision or workflow identity.")
    if not isinstance(event, dict) or not isinstance(event.get("repository"), dict):
        raise PublicationError("Invalid event repository evidence.")
    if event["repository"].get("full_name") != repository:
        raise PublicationError("Event repository does not match the workflow repository.")
    event_name = env.get("GITHUB_EVENT_NAME")
    if event_name == "push":
        if (event.get("ref") != ref or event.get("after") != sha
                or event.get("deleted") is not False or event.get("forced") is not False):
            raise PublicationError("Push evidence is stale, forced or incomplete.")
    elif event_name != "workflow_dispatch":
        raise PublicationError("This event cannot publish source.")
    return Context(repository, ref.removeprefix("refs/heads/"), sha, run_id)


def ref_path(branch):
    return "git/ref/heads/" + parse.quote(branch, safe="/")


def head(api, branch):
    return api.request("GET", ref_path(branch))["object"]["sha"]


def assert_fresh(api, context, main_sha, destination=False):
    if head(api, context.source) != context.sha:
        raise PublicationError("The staging branch moved after verification.")
    if head(api, "main") != main_sha:
        raise PublicationError("Main moved during publication; inspect and refresh the staging revision.")
    if destination and head(api, context.destination) != context.sha:
        raise PublicationError("The publication branch no longer contains the verified revision.")


def publish(api, context):
    main_sha = head(api, "main")
    assert_fresh(api, context, main_sha)
    comparison = api.request("GET", f"compare/{main_sha}...{context.sha}")
    if (comparison.get("status") != "ahead"
            or comparison.get("merge_base_commit", {}).get("sha") != main_sha):
        raise PublicationError("The verified revision must contain current main and new changes.")

    try:
        existing = head(api, context.destination)
    except APIError as exc:
        if exc.status != 404:
            raise
        existing = None
    if existing is not None and existing != context.sha:
        raise PublicationError("The publication branch exists at another revision; it will not be overwritten.")
    if existing is None:
        assert_fresh(api, context, main_sha)
        api.request("POST", "git/refs", {
            "ref": "refs/heads/" + context.destination, "sha": context.sha,
        })
    assert_fresh(api, context, main_sha, destination=True)

    owner = context.repository.split("/")[0]
    query = parse.urlencode({
        "state": "open", "base": "main", "head": owner + ":" + context.destination,
        "per_page": "100",
    })
    pulls = api.request("GET", "pulls?" + query)
    if not isinstance(pulls, list) or len(pulls) > 1:
        raise PublicationError("Expected at most one matching open publication PR.")
    if pulls:
        pull = pulls[0]
    else:
        assert_fresh(api, context, main_sha, destination=True)
        pull = api.request("POST", "pulls", {
            "title": "Publish prepared source updates",
            "head": context.destination,
            "base": "main",
            "body": (
                f"Publishes prepared revision `{context.sha}` from `{context.source}`. "
                f"[Verification and publication run]({context.run_url}).\n\n"
                "The published branch points to the exact verified commit. Original commit "
                "authorship is preserved. This workflow opens the review PR and requests Verify; "
                "it does not approve, merge, deploy or change vendor records."
            ),
            "draft": False,
        })
    if (pull.get("head", {}).get("sha") != context.sha
            or pull.get("head", {}).get("ref") != context.destination
            or pull.get("head", {}).get("repo", {}).get("full_name") != context.repository
            or pull.get("base", {}).get("ref") != "main"
            or pull.get("base", {}).get("repo", {}).get("full_name") != context.repository
            or type(pull.get("number")) is not int or pull["number"] < 1):
        raise PublicationError("The publication PR does not match the verified repository and revision.")

    assert_fresh(api, context, main_sha, destination=True)
    runs_query = parse.urlencode({
        "event": "workflow_dispatch", "branch": context.destination,
        "head_sha": context.sha, "per_page": "100",
    })
    runs = api.request("GET", "actions/workflows/ci.yml/runs?" + runs_query).get("workflow_runs", [])
    matching = [run for run in runs if run.get("head_sha") == context.sha
                and run.get("head_branch") == context.destination
                and run.get("event") == "workflow_dispatch"]
    if not matching:
        assert_fresh(api, context, main_sha, destination=True)
        api.request("POST", "actions/workflows/ci.yml/dispatches", {"ref": context.destination})
    return {
        "sha": context.sha,
        "branch": context.destination,
        "pull_request": f"https://github.com/{context.repository}/pull/{pull['number']}",
        "verification": "existing run retained" if matching else "Verify requested",
    }


def main():
    try:
        event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
        context = context_from_event(os.environ, event)
        result = publish(GitHub(context.repository, os.environ.get("GITHUB_TOKEN", "")), context)
        print(json.dumps(result))
        if os.environ.get("GITHUB_STEP_SUMMARY"):
            with Path(os.environ["GITHUB_STEP_SUMMARY"]).open("a") as summary:
                summary.write(
                    f"Published `{result['sha']}` on `{result['branch']}`.\n\n"
                    f"[Review pull request]({result['pull_request']}). {result['verification']}.\n"
                )
        return 0
    except (PublicationError, KeyError, ValueError, TypeError, OSError) as exc:
        if isinstance(exc, PublicationError):
            print(str(exc), file=sys.stderr)
        else:
            print("Publication stopped because required local or API evidence is invalid.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
