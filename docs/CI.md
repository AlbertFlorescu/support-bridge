# GitHub verification and merge controls

We use GitHub Actions for native PHP checks, container verification and the merge-status gate. The repository workflow is [`.github/workflows/ci.yml`](../.github/workflows/ci.yml); [hosted runs](https://github.com/AlbertFlorescu/support-bridge/actions) provide the result for each published revision.

## Required status check

The stable check to require on `main` is **`Required checks`**. It runs after both verification jobs and succeeds only when both report success. Failed, cancelled and skipped dependencies fail the aggregate gate. We keep this name unique across workflows so branch rules can select it unambiguously.

| Check | Evidence |
| --- | --- |
| `PHP checks` | PHP syntax, domain/reconciliation/governance/provider/API/observability tests, the synthetic demo, locked OpenAPI parsing and actual loopback response-fixture validation |
| `Container checks` | Image builds, isolated PHP/OpenAPI verification, complete stack startup, actual service-network smoke checks, graceful worker shutdown and pending-plan/credential persistence through recreation |
| `Required checks` | Both preceding jobs completed successfully for the revision being evaluated |

The workflow runs for pull requests targeting `main`, pushes to `main`, manual dispatch and merge-group checks. It does not filter paths, so documentation-only changes still produce the required status. Newer runs cancel outdated work for the same pull request or ref. Merge-group support supplies checks if a merge queue is enabled later; it does not enable that feature itself. [GitHub concurrency](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency) controls duplicate work.

## Branch protection is a separate setting

Actions report test results. An active GitHub branch rule must enforce them before merging. Configure `main` to require pull requests and `Required checks`, require the branch to be up to date, prevent force pushes and deletion, and apply the restrictions to administrators without a bypass. Select GitHub Actions as the expected source of the status check. Do not require another person's approval until a separate reviewer is available; a sole maintainer cannot approve their own pull request.

Private-repository branch protection requires a GitHub plan that includes that capability. On a plan without it, CI still runs but cannot prevent a direct or forced push. We do not treat a workflow file or a passing check as proof that the branch is protected. [GitHub's protected-branch availability and settings](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches) define that boundary.

The importable [main ruleset](../.github/rulesets/main.json) records this configuration with no bypass actors. Its expected status-check source is the GitHub Actions app, verified from our check-run metadata. Import it through **Settings → Rules → Rulesets → New ruleset → Import a ruleset**, once the account supports private-repository rules. The JSON requests active enforcement, but storing this file does not create or activate a GitHub rule. Verify the server-side rule after import. A classic branch rule with equivalent settings is an alternative; do not maintain two conflicting configurations.

## Permissions, dependencies and artifacts

Workflow tokens have read-only repository contents access. Checkout does not retain credentials. Official GitHub actions are pinned to verified commit IDs, and Dependabot opens reviewed updates rather than silently moving those pins. Node dependencies use the lockfile, lifecycle scripts are disabled, and GitHub's package-manager cache reduces repeat downloads. Container builds use the checked-in digest pins and reuse local layers within a job; we do not publish images or deploy services from this workflow.

We retain only the synthetic API response-fixture JSON as an Actions artifact for seven days. It contains controlled test responses, not vendor records, bearer tokens or deployment configuration. Failed container jobs print bounded service diagnostics; CI credentials and state stay in the disposable runner's volumes, which are removed after the job. We never upload credential volumes, Compose environment dumps or a whole state directory as artifacts.

[Dependabot configuration](../.github/dependabot.yml) checks GitHub actions, npm API tooling and the four upstream Docker images weekly. The Docker allowlist excludes our locally built image names. Updates still need review and passing checks; dependency automation does not establish compatibility or compliance. [Dependabot's configuration reference](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference) describes the supported options.

## Local reproduction and limits

Use `php bin/check.php` and the locked validator for native checks, or the commands in [Docker operation](DOCKER.md) for the same container paths. The lifecycle script always creates its own uniquely named synthetic project; it must not remove the normal stack's volumes.

CI verifies deterministic behavior with synthetic data. It does not approve a customer state change, verify human identity, determine compliance, exercise live provider accounts, prove production capacity or establish a restore procedure. Never rely on AI in compliance; merge checks and runtime approval remain distinct controls.
