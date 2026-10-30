# Prepared source publication

[Publish prepared changes](../.github/workflows/publish.yml) uses GitHub Actions to publish a verified source revision on a review branch and open a pull request. Commit authorship stays unchanged. The workflow run records who initiated it; publication does not claim that automation designed or independently generated the changes.

## Architecture overview

1. A maintainer reviews source, excludes private files and creates a normal commit on `chore/staging/<change-name>`.
2. Pushing that branch starts the publisher. Manual dispatch is also accepted on a staging branch; main, tags and PR events cannot publish.
3. A read-only job calls the same [Verify workflow](../.github/workflows/ci.yml). PHP, API, recovery, style, documentation and container checks must all pass.
4. A separate job receives a short-lived repository token with `contents: write`, `pull-requests: write` and `actions: write`. It creates `feature/published-<full-commit-sha>` pointing to the exact verified commit, opens a PR targeting main and explicitly requests standalone Verify on that branch.
5. Review and merge remain separate maintainer actions. There is no approval, merge, deployment or vendor operation in this publisher.

The publisher does not create a replacement commit or change author/committer metadata. The source commit is visible on the staging branch before the bot publishes the review branch. The bot's PR and run provide the publication record. A first installation therefore needs an ordinary authorized staging push; automation cannot run against unpublished local files.

Verification uses no personal token or application credential. Checkout removes Git credentials. The write job runs only the publication helper, not PHP, dependency installation or staged application commands. Source and workflow code remain trusted maintainer inputs: a staging-name convention is not a permission boundary against someone who can edit workflows.

## Use from Ubuntu WSL

Perform all commands inside the repository's Ubuntu WSL checkout. Authenticate Git with a maintainer credential permitted to write source and workflow files; never store it in source or reuse live provider tokens.

Review both tracked changes and new files before staging. For example:

```bash
git status --short
git diff --check
git switch -c chore/staging/escalation-updates
git add -- path/to/reviewed-file
git diff --cached
git commit -m "Improve escalation workflows and monitoring"
git push -u origin HEAD
```

Replace the branch suffix, file path and commit message with the actual change. Include each reviewed new file explicitly; do not add private deployment files, runtime data, captured observations or credential stores. The repository is public, so staged source is public as soon as it is pushed.

Open **Actions → Publish prepared changes** for the staging SHA. After verification succeeds, follow its PR link and confirm:

- PR author is the real `github-actions[bot]` account.
- Head SHA is the original maintainer-authored staging SHA.
- Standalone **Required checks** succeeds for the published revision. Nested staging checks do not replace this named PR gate.
- The diff contains only the reviewed product changes.

No scheduled publishing is enabled. A new staging push is an explicit publishing action. Manual dispatch requires selecting the staging branch; dispatch on main skips publication.

## Freshness and interrupted operations

The helper requires the verified SHA to contain current main and new changes. It rechecks staging/main before each write and checks the published branch after creation. A moved staging branch, changed main, mismatched PR or existing destination at another SHA stops publication. It never force-pushes, updates an existing ref or writes main.

After an interruption, inspect branches, the PR and run logs before rerunning. A rerun observes the deterministic branch and matching open PR instead of duplicating them. It reuses an existing standalone Verify run for that branch/SHA, including failed runs; it does not blindly restart CI. Review a failed run before deliberately rerunning it. API list visibility can lag, so interrupted PR creation or dispatch cannot be promised exactly once. Inspect remote evidence before retrying an unknown outcome.

There is no transaction covering main, staging, PR creation and dispatch. Main can still change after the last check. The PR's normal freshness/review gate remains necessary.

## Permissions and assumptions

Repository settings must allow GitHub Actions to create pull requests. GitHub combines that setting with permission to submit approving reviews. Enabling it does not require changing the default token permissions from read-only. This workflow never calls a review or merge endpoint, but its PR-write token has broader API capability than the operations we choose to perform. Workflow permissions are not separation of duties.

Only maintainers with source-write authority may prepare staging revisions. GitHub-hosted runners, repository configuration and the reviewed workflow/helper are trusted. No repository PAT is added as a secret; the job uses its own expiring `GITHUB_TOKEN`. Local maintainer authentication is needed only for source preparation and the initial push.

[Branch enforcement](CI.md#branch-protection-is-a-separate-setting) is independent. Publishing a PR and passing CI do not enable server-side protection. The publisher does not change repository visibility, branch rules, runtime credentials or deployment.

## Trade-offs

- Reusing Verify avoids a weaker publication-specific test list, but staging and standalone PR verification run twice.
- Publishing the original SHA retains accurate authorship and avoids generated replacement commits. It requires a visible staging revision first.
- SHA-based branches avoid overwrite races and support observation after interruptions. They accumulate until reviewed and deliberately cleaned up.
- A small privileged job limits executed code but still trusts maintainer-controlled source. Someone who can edit workflows can alter that boundary.
- Explicit Verify dispatch handles [GitHub's bot-event restrictions](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/trigger-a-workflow). PR-triggered checks may also appear with GitHub's approval requirement; the publisher does not approve them.

## Known limitations

Publication does not deploy the app, validate live provider behavior, approve customer state changes, certify compliance or independently review the implementation. API/network failures can leave a branch or PR published without a new CI run. Closed PRs, branch removal, force-pushed staging history and repository policy changes need maintainer investigation. No automatic merge, rollback, release or cleanup is included.

## Offline verification

```bash
python3 -m unittest discover -s tools/publishing -p 'test_*.py' -v
```

Controlled tests cover exact-SHA publication, stale inputs, diverged main, branch collision, PR identity, interrupted writes and rerun behavior. They perform no GitHub writes. Actual bot publication and hosted check results must be observed separately.
