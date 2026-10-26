#!/usr/bin/env python3
"""Prepare and publish a bounded Swagger asset repair without executing PR code."""

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlsplit
from urllib.request import Request, build_opener, HTTPRedirectHandler


ASSET_DIRECTORY = "public/assets/swagger-ui/"
ASSET_NAMES = ("swagger-ui-bundle.js", "swagger-ui.css", "LICENSE", "NOTICE")
ASSET_PATHS = frozenset(ASSET_DIRECTORY + name for name in ASSET_NAMES)
PACKAGE_PATHS = frozenset(("tools/api-docs/package.json", "tools/api-docs/package-lock.json"))
ALLOWED_PATHS = ASSET_PATHS | PACKAGE_PATHS
MAX_ASSET_BYTES = 8 * 1024 * 1024
MAX_PACKAGE_BYTES = 1024 * 1024
MAX_MANIFEST_BYTES = 48 * 1024 * 1024
MAX_API_BYTES = 20 * 1024 * 1024
CONFIRMATION_ATTEMPTS = 5
CONFIRMATION_DELAY = 1
SHA = re.compile(r"[0-9a-f]{40}\Z")
SEMVER = re.compile(r"[~^]?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\Z")
PACKAGE_NAME = re.compile(r"(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*\Z")
CREATE_COMMIT = """mutation RefreshVendorAssets($input: CreateCommitOnBranchInput!) {
  createCommitOnBranch(input: $input) { commit { oid } }
}"""


class RepairError(Exception):
    pass


class Ineligible(RepairError):
    pass


def require(condition, message):
    if not condition:
        raise RepairError(message)


def eligible(condition, message):
    if not condition:
        raise Ineligible(message)


def strict_json(raw):
    def object_pairs(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, "Duplicate JSON key.")
            result[key] = value
        return result
    try:
        return json.loads(raw, object_pairs_hook=object_pairs)
    except (ValueError, UnicodeError) as error:
        raise RepairError("Invalid JSON.") from error


def repository_name(value):
    require(isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*", value), "Invalid repository.")
    return value


def commit_sha(value):
    require(isinstance(value, str) and SHA.fullmatch(value), "Invalid commit identifier.")
    return value


def branch_name(value):
    require(isinstance(value, str) and 0 < len(value) <= 250, "Invalid branch name.")
    require(not re.search(r"[\x00-\x20\x7f~^:?*\[\\]", value) and not any(part in value for part in ("..", "@{", "//")), "Invalid branch name.")
    require(not value.startswith(("/", "-", ".")) and not value.endswith(("/", ".", ".lock")), "Invalid branch name.")
    require(all(not part.startswith(".") and not part.endswith(".lock") for part in value.split("/")), "Invalid branch name.")
    return value


def regular_bytes(path, maximum, root=None):
    path = Path(path)
    if root is not None:
        root = Path(root)
        require(path.is_relative_to(root), "File is outside its isolated directory.")
        current = root
        for component in path.relative_to(root).parts:
            current = current / component
            require(not current.is_symlink(), "Symbolic links are not permitted.")
    require(not path.is_symlink(), "Symbolic links are not permitted.")
    try:
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        with os.fdopen(descriptor, "rb") as stream:
            require(stat.S_ISREG(os.fstat(stream.fileno()).st_mode), "Expected a regular file.")
            data = stream.read(maximum + 1)
    except OSError as error:
        raise RepairError("A required regular file is unavailable.") from error
    require(len(data) <= maximum, "File exceeds its size limit.")
    return data


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, file_pointer, code, message, headers, new_url):
        return None


class GitHubAPI:
    def __init__(self, token):
        require(bool(token), "GITHUB_TOKEN is required.")
        self.token = token
        self.opener = build_opener(NoRedirect())

    def request(self, method, path, payload=None):
        if path == "graphql":
            require(method == "POST" and isinstance(payload, dict) and set(payload) == {"query", "variables"} and payload["query"] == CREATE_COMMIT, "Only the fixed asset-commit mutation is permitted.")
        else:
            require(path.startswith("repos/") and not path.startswith("repos//"), "Invalid API path.")
        data = None if payload is None else json.dumps(payload).encode()
        request = Request("https://api.github.com/" + path, data=data, method=method, headers={
            "Authorization": "Bearer " + self.token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "Content-Type": "application/json",
            "User-Agent": "Support-Bridge-vendor-repair",
        })
        try:
            with self.opener.open(request, timeout=30) as response:
                raw = response.read(MAX_API_BYTES + 1)
        except HTTPError as error:
            raise RepairError(f"GitHub API request failed (HTTP {error.code}).") from error
        except (URLError, TimeoutError, OSError) as error:
            raise RepairError("GitHub API request did not complete; inspect current branch state before retrying publication.") from error
        require(len(raw) <= MAX_API_BYTES, "GitHub API response exceeded its limit.")
        return strict_json(raw) if raw else None


def _checked_pr_identity(api, repository, number, manual=False, expected_head=None, observed=None):
    require(type(number) is int and 0 < number < 1000000000, "Invalid pull request number.")
    pull = observed if observed is not None else api.request("GET", f"repos/{repository}/pulls/{number}")
    require(isinstance(pull, dict) and pull.get("number") == number, "Invalid pull request response.")
    eligible(pull.get("state") == "open", "Pull request is not open.")
    eligible(pull.get("base", {}).get("ref") == "main" and pull.get("base", {}).get("repo", {}).get("full_name") == repository, "Pull request must target this repository's main branch.")
    eligible(pull.get("head", {}).get("repo", {}).get("full_name") == repository, "Fork pull requests are not eligible.")
    head = commit_sha(pull.get("head", {}).get("sha"))
    ref = branch_name(pull.get("head", {}).get("ref"))
    eligible(ref != "main", "Main cannot be a repair branch.")
    if expected_head is not None:
        eligible(head == commit_sha(expected_head), "Pull request head moved; prepare a fresh repair.")
    user = pull.get("user", {})
    native_bot = user.get("login") == "dependabot[bot]" and user.get("type") == "Bot"
    if manual and not native_bot:
        login = user.get("login")
        eligible(isinstance(login, str) and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9-]*", login), "Manual repair requires a maintainer-authored pull request.")
        permission = api.request("GET", f"repos/{repository}/collaborators/{quote(login, safe='')}/permission")
        eligible(isinstance(permission, dict) and permission.get("permission") in ("admin", "maintain", "write"), "Manual repair requires a maintainer-authored pull request.")
    else:
        eligible(native_bot, "Only native dependency-update pull requests are eligible.")
    return pull


def checked_pr(api, repository, number, manual=False, expected_head=None, allow_empty=False, *, observed=None):
    pull = _checked_pr_identity(api, repository, number, manual, expected_head, observed)
    changed = pull.get("changed_files")
    eligible(type(changed) is int and (0 if allow_empty else 1) <= changed <= len(ALLOWED_PATHS), "Pull request file count is outside the repair boundary.")
    files = api.request("GET", f"repos/{repository}/pulls/{number}/files?per_page=100&page=1")
    require(isinstance(files, list) and len(files) == changed, "Changed-file response is incomplete.")
    names = set()
    for item in files:
        require(isinstance(item, dict), "Invalid changed-file response.")
        name = item.get("filename")
        eligible(name in ALLOWED_PATHS and name not in names, "Pull request changes files outside the repair boundary.")
        eligible(item.get("status") in ("modified", "added") and "previous_filename" not in item, "File removals and renames require manual review.")
        names.add(name)
    return pull


def tree_files(api, repository, head):
    commit = api.request("GET", f"repos/{repository}/git/commits/{head}")
    tree_sha = commit_sha(commit.get("tree", {}).get("sha"))
    tree = api.request("GET", f"repos/{repository}/git/trees/{tree_sha}?recursive=1")
    require(isinstance(tree, dict) and tree.get("truncated") is False and isinstance(tree.get("tree"), list) and len(tree["tree"]) <= 10000, "Repository tree is incomplete or exceeds its limit.")
    result = {}
    for entry in tree["tree"]:
        if any(path.startswith(str(entry.get("path")) + "/") for path in ALLOWED_PATHS):
            require(entry.get("mode") == "040000" and entry.get("type") == "tree", "Repair directories must not be symbolic links or submodules.")
        if entry.get("path") in ALLOWED_PATHS:
            require(entry.get("mode") == "100644" and entry.get("type") == "blob", "Repair files must be ordinary non-executable files.")
            require(entry["path"] not in result, "Duplicate repository tree entry.")
            result[entry["path"]] = commit_sha(entry.get("sha"))
    require(PACKAGE_PATHS <= result.keys(), "Both dependency manifest files are required.")
    return result


def blob_bytes(api, repository, blob_sha, maximum):
    blob = api.request("GET", f"repos/{repository}/git/blobs/{blob_sha}")
    require(isinstance(blob, dict) and blob.get("encoding") == "base64" and type(blob.get("size")) is int and 0 <= blob["size"] <= maximum, "Invalid or oversized repository blob.")
    try:
        content = base64.b64decode("".join(blob["content"].split()), validate=True)
    except (KeyError, ValueError, TypeError) as error:
        raise RepairError("Invalid repository blob encoding.") from error
    require(len(content) == blob["size"], "Repository blob size does not match.")
    actual = hashlib.sha1(b"blob " + str(len(content)).encode() + b"\0" + content).hexdigest()
    require(actual == blob_sha, "Repository blob hash does not match.")
    return content


def validate_packages(package_raw, lock_raw):
    package, lock = strict_json(package_raw), strict_json(lock_raw)
    require(isinstance(package, dict) and package.get("private") is True and package.get("name") == "support-bridge-api-tooling", "Unexpected package manifest.")
    allowed = {"name", "private", "version", "description", "scripts", "dependencies", "devDependencies", "engines"}
    require(set(package) <= allowed, "Package configuration needs manual review.")
    for kind in ("dependencies", "devDependencies"):
        dependencies = package.get(kind, {})
        require(isinstance(dependencies, dict), "Invalid dependency declaration.")
        for name, version in dependencies.items():
            require(PACKAGE_NAME.fullmatch(name) and isinstance(version, str) and SEMVER.fullmatch(version), "Only registry semantic-version dependencies are permitted.")
    require(isinstance(lock, dict) and lock.get("lockfileVersion") == 3 and isinstance(lock.get("packages"), dict), "A version-three npm lockfile is required.")
    require(0 < len(lock["packages"]) <= 2000, "Lockfile package count exceeded its limit.")
    for name, entry in lock["packages"].items():
        require(isinstance(entry, dict), "Invalid locked package.")
        if name == "":
            continue
        require(isinstance(name, str) and name.startswith("node_modules/") and ".." not in PurePosixPath(name).parts and "\\" not in name, "Invalid locked package path.")
        require(not entry.get("link"), "Linked packages are not permitted.")
        url = entry.get("resolved")
        require(isinstance(url, str), "Every locked package needs a registry archive.")
        parsed = urlsplit(url)
        require(parsed.scheme == "https" and parsed.netloc == "registry.npmjs.org" and not parsed.query and not parsed.fragment, "Package archives must use the public npm registry.")
        integrity = entry.get("integrity")
        require(isinstance(integrity, str) and re.fullmatch(r"sha512-[A-Za-z0-9+/]+={0,2}", integrity), "Every locked package needs SHA-512 integrity.")
        try:
            require(len(base64.b64decode(integrity[7:], validate=True)) == 64, "Invalid SHA-512 integrity.")
        except ValueError as error:
            raise RepairError("Invalid SHA-512 integrity.") from error
    swagger = lock["packages"].get("node_modules/swagger-ui-dist", {})
    version = swagger.get("version")
    require(isinstance(version, str) and SEMVER.fullmatch(version) and version[0].isdigit(), "Swagger UI must have an exact locked version.")
    return version


def install_assets(directory, version):
    """Install data-only manifests with scripts disabled and no inherited credentials."""
    npm = shutil.which("npm")
    require(npm is not None, "A trusted Node/npm installation is required.")
    home = directory / "isolated-home"
    home.mkdir()
    user_config, global_config = home / "user.npmrc", home / "global.npmrc"
    user_config.write_text("")
    global_config.write_text("")
    env = {"PATH": os.environ.get("PATH", ""), "HOME": str(home), "LANG": "C.UTF-8",
           "NPM_CONFIG_USERCONFIG": str(user_config), "NPM_CONFIG_GLOBALCONFIG": str(global_config),
           "NPM_CONFIG_CACHE": str(home / "cache"), "NPM_CONFIG_REGISTRY": "https://registry.npmjs.org/",
           "NPM_CONFIG_IGNORE_SCRIPTS": "true", "NPM_CONFIG_AUDIT": "false", "NPM_CONFIG_FUND": "false"}
    try:
        result = subprocess.run([npm, "ci", "--ignore-scripts", "--no-audit", "--no-fund", "--registry", "https://registry.npmjs.org/"], cwd=directory, env=env, capture_output=True, timeout=180, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise RepairError("Isolated npm installation did not complete.") from error
    require(result.returncode == 0, "Isolated npm installation failed; no repair was prepared.")
    source = directory / "node_modules" / "swagger-ui-dist"
    installed = strict_json(regular_bytes(source / "package.json", MAX_PACKAGE_BYTES, directory))
    require(installed.get("version") == version, "Installed Swagger version differs from its lockfile.")
    return {ASSET_DIRECTORY + name: regular_bytes(source / name, MAX_ASSET_BYTES, directory) for name in ASSET_NAMES}


def prepare(api, repository, number, manual=False, expected_head=None, installer=install_assets):
    pull = checked_pr(api, repository, number, manual, expected_head)
    head, ref = pull["head"]["sha"], pull["head"]["ref"]
    files = tree_files(api, repository, head)
    manifests = {path: blob_bytes(api, repository, files[path], MAX_PACKAGE_BYTES) for path in PACKAGE_PATHS}
    version = validate_packages(manifests["tools/api-docs/package.json"], manifests["tools/api-docs/package-lock.json"])
    with tempfile.TemporaryDirectory(prefix="bridge-vendor-repair-") as isolated:
        directory = Path(isolated)
        for path, content in manifests.items():
            (directory / PurePosixPath(path).name).write_bytes(content)
        assets = installer(directory, version)
    require(set(assets) == ASSET_PATHS, "Installer returned an incomplete asset set.")
    replacements = []
    for path in sorted(assets):
        content = assets[path]
        require(isinstance(content, bytes) and 0 < len(content) <= MAX_ASSET_BYTES, "Generated asset is empty or oversized.")
        current = blob_bytes(api, repository, files[path], MAX_ASSET_BYTES) if path in files else None
        if current != content:
            replacements.append({"path": path, "sha256": hashlib.sha256(content).hexdigest(), "content": base64.b64encode(content).decode()})
    checked_pr(api, repository, number, manual, head)
    return {"version": 1, "repository": repository, "pr": number, "head_sha": head, "head_ref": ref,
            "manual": manual, "changed": bool(replacements), "files": replacements}


def validate_manifest(manifest, repository):
    expected = {"version", "repository", "pr", "head_sha", "head_ref", "manual", "changed", "files"}
    require(isinstance(manifest, dict) and set(manifest) == expected and type(manifest["version"]) is int and manifest["version"] == 1, "Invalid repair manifest.")
    require(manifest["repository"] == repository and type(manifest["manual"]) is bool and type(manifest["changed"]) is bool, "Invalid repair identity.")
    commit_sha(manifest["head_sha"])
    branch_name(manifest["head_ref"])
    require(type(manifest["pr"]) is int and 0 < manifest["pr"] < 1000000000, "Invalid repair pull request.")
    require(isinstance(manifest["files"], list) and len(manifest["files"]) <= len(ASSET_NAMES) and manifest["changed"] == bool(manifest["files"]), "Invalid repair changes.")
    names, decoded = set(), []
    for file in manifest["files"]:
        require(isinstance(file, dict) and set(file) == {"path", "sha256", "content"}, "Invalid prepared asset.")
        path = file["path"]
        require(path in ASSET_PATHS and path not in names, "Prepared asset is outside the allowlist.")
        require(isinstance(file["content"], str) and len(file["content"]) <= 4 * ((MAX_ASSET_BYTES + 2) // 3), "Prepared asset exceeds its limit.")
        try:
            content = base64.b64decode(file["content"], validate=True)
        except ValueError as error:
            raise RepairError("Invalid prepared asset encoding.") from error
        require(0 < len(content) <= MAX_ASSET_BYTES and hashlib.sha256(content).hexdigest() == file["sha256"], "Prepared asset hash does not match.")
        decoded.append((path, content))
        names.add(path)
    return decoded


def confirm_commit(api, repository, manifest, new_head, sleeper=time.sleep):
    """Retry only an otherwise eligible cached pre-commit observation."""
    for attempt in range(CONFIRMATION_ATTEMPTS):
        observed = _checked_pr_identity(api, repository, manifest["pr"], manifest["manual"])
        require(observed["head"]["ref"] == manifest["head_ref"], "Updated branch identity could not be verified.")
        head = observed["head"]["sha"]
        if head == new_head:
            return checked_pr(api, repository, manifest["pr"], manifest["manual"], new_head,
                              allow_empty=True, observed=observed)
        require(head == manifest["head_sha"], "Pull request changed again after the atomic commit; inspect its current head.")
        if attempt < CONFIRMATION_ATTEMPTS - 1:
            sleeper(CONFIRMATION_DELAY)
    raise RepairError("Atomic commit succeeded but its new pull request head was not confirmed within the read-only retry limit; inspect the branch and run Verify manually.")


def publish(api, repository, manifest, sleeper=time.sleep):
    files = validate_manifest(manifest, repository)
    pull = checked_pr(api, repository, manifest["pr"], manifest["manual"], manifest["head_sha"])
    require(pull["head"]["ref"] == manifest["head_ref"], "Pull request branch changed.")
    if not files:
        return {"changed": False}
    tree_files(api, repository, manifest["head_sha"])
    checked_pr(api, repository, manifest["pr"], manifest["manual"], manifest["head_sha"])
    result = api.request("POST", "graphql", {"query": CREATE_COMMIT, "variables": {"input": {
        "branch": {"repositoryNameWithOwner": repository, "branchName": manifest["head_ref"]},
        "expectedHeadOid": manifest["head_sha"],
        "message": {"headline": "Refresh vendored Swagger UI assets from the dependency lockfile"},
        "fileChanges": {"additions": [{"path": path, "contents": base64.b64encode(content).decode()} for path, content in files]},
    }}})
    require(isinstance(result, dict) and not result.get("errors"), "Atomic asset commit was rejected; prepare again from the current branch head.")
    data = result.get("data")
    require(isinstance(data, dict) and isinstance(data.get("createCommitOnBranch"), dict), "Atomic asset commit response is incomplete; inspect the current branch head.")
    commit = data["createCommitOnBranch"].get("commit")
    require(isinstance(commit, dict), "Atomic asset commit response is incomplete; inspect the current branch head.")
    new_head = commit_sha(commit.get("oid"))
    confirm_commit(api, repository, manifest, new_head, sleeper)
    api.request("POST", f"repos/{repository}/actions/workflows/ci.yml/dispatches", {"ref": manifest["head_ref"]})
    return {"changed": True, "head_sha": new_head, "ci_dispatched": True}


def select(api, repository, event_name, event):
    if event_name == "workflow_dispatch":
        raw = event.get("inputs", {}).get("pr")
        require(isinstance(raw, str) and re.fullmatch(r"[1-9][0-9]{0,8}", raw), "A numeric pull request input is required.")
        number = int(raw)
        pull = checked_pr(api, repository, number, manual=True)
        return {"eligible": "true", "pr": str(number), "manual": "true", "head_sha": pull["head"]["sha"]}
    require(event_name == "workflow_run", "Unsupported workflow event.")
    run = event.get("workflow_run", {})
    if run.get("name") != "Verify" or run.get("event") != "pull_request" or run.get("status") != "completed" or run.get("repository", {}).get("full_name") != repository or run.get("head_repository", {}).get("full_name") != repository:
        return {"eligible": "false", "pr": "", "manual": "false", "head_sha": ""}
    head = commit_sha(run.get("head_sha"))
    associated = run.get("pull_requests", [])
    require(isinstance(associated, list) and len(associated) <= 10, "Workflow pull request associations exceed their limit.")
    numbers = {item.get("number") for item in associated if isinstance(item, dict)}
    if not numbers:
        for page in range(1, 4):
            pulls = api.request("GET", f"repos/{repository}/pulls?state=open&per_page=100&page={page}")
            require(isinstance(pulls, list) and len(pulls) <= 100, "Invalid pull request listing.")
            numbers.update(pull["number"] for pull in pulls if pull.get("head", {}).get("sha") == head)
            if len(pulls) < 100:
                break
        else:
            raise RepairError("Open pull request listing exceeds its limit.")
    eligible = []
    for number in numbers:
        if type(number) is not int or not 0 < number < 1000000000:
            continue
        try:
            pull = checked_pr(api, repository, number, expected_head=head)
        except Ineligible:
            continue
        eligible.append(pull)
    require(len(eligible) <= 1, "Workflow matches multiple eligible pull requests.")
    return {"eligible": "true" if eligible else "false", "pr": str(eligible[0]["number"]) if eligible else "", "manual": "false", "head_sha": head if eligible else ""}


def write_manifest(path, manifest):
    path = Path(path)
    require(not path.is_symlink() and not path.parent.is_symlink(), "Output must not be a symbolic link.")
    content = json.dumps(manifest, separators=(",", ":")).encode()
    require(len(content) <= MAX_MANIFEST_BYTES, "Repair manifest exceeds its limit.")
    path.write_bytes(content)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("prepare", "publish", "select"):
        command = commands.add_parser(name)
        command.add_argument("--repository", required=True, type=repository_name)
        if name == "prepare":
            command.add_argument("--pr", required=True, type=int)
            command.add_argument("--manual", action="store_true")
            command.add_argument("--expected-head")
            command.add_argument("--output", required=True)
        elif name == "publish":
            command.add_argument("--input", required=True)
        else:
            command.add_argument("--event-name", choices=("workflow_dispatch", "workflow_run"), required=True)
            command.add_argument("--event-file", required=True)
            command.add_argument("--output", required=True)
    args = parser.parse_args()
    api = GitHubAPI(os.environ.get("GITHUB_TOKEN"))
    if args.command == "prepare":
        manifest = prepare(api, args.repository, args.pr, args.manual, args.expected_head)
        write_manifest(args.output, manifest)
        print("Vendor repair prepared." if manifest["changed"] else "Vendored assets already match the dependency lockfile.")
    elif args.command == "publish":
        manifest = strict_json(regular_bytes(Path(args.input), MAX_MANIFEST_BYTES))
        result = publish(api, args.repository, manifest)
        print(json.dumps(result))
    else:
        event = strict_json(regular_bytes(Path(args.event_file), MAX_PACKAGE_BYTES))
        require(isinstance(event, dict), "Invalid event payload.")
        result = select(api, args.repository, args.event_name, event)
        output = Path(args.output)
        require(not output.is_symlink(), "Workflow output must not be a symbolic link.")
        with output.open("a", encoding="utf8") as stream:
            stream.write("".join(key + "=" + value + "\n" for key, value in result.items()))


if __name__ == "__main__":
    try:
        main()
    except (RepairError, KeyError, TypeError, ValueError) as error:
        print("Vendor repair stopped: " + (str(error) if isinstance(error, RepairError) else "Unexpected response shape."), file=sys.stderr)
        sys.exit(1)
