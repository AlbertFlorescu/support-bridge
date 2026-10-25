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

As verified on 2026-10-25, our current private-repository plan does not support branch-rule enforcement, and `main` has no enforced rule. We retain this plan and follow the PR/check workflow as maintainer practice. The prepared configuration below is not an active server-side control.

The importable [main ruleset](../.github/rulesets/main.json) records this configuration with no bypass actors. Its expected status-check source is the GitHub Actions app, verified from our check-run metadata. Import it through **Settings → Rules → Rulesets → New ruleset → Import a ruleset**, once the account supports private-repository rules. The JSON requests active enforcement, but storing this file does not create or activate a GitHub rule. Verify the server-side rule after import. A classic branch rule with equivalent settings is an alternative; do not maintain two conflicting configurations.

## Permissions, dependencies and artifacts

Workflow tokens have read-only repository contents access. Checkout does not retain credentials. Official GitHub actions are pinned to verified commit IDs, and Dependabot opens reviewed updates rather than silently moving those pins. Node dependencies use the lockfile, lifecycle scripts are disabled, and GitHub's package-manager cache reduces repeat downloads. Container builds use the checked-in digest pins and reuse local layers within a job; we do not publish images or deploy services from this workflow.

We retain only the synthetic API response-fixture JSON as an Actions artifact for seven days. It contains controlled test responses, not vendor records, bearer tokens or deployment configuration. Failed container jobs print bounded service diagnostics; CI credentials and state stay in the disposable runner's volumes, which are removed after the job. We never upload credential volumes, Compose environment dumps or a whole state directory as artifacts.

[Dependabot configuration](../.github/dependabot.yml) checks GitHub actions, npm API tooling and the four upstream Docker images weekly. The Docker allowlist excludes our locally built image names. Updates still need review and passing checks; dependency automation does not establish compatibility or compliance. [Dependabot's configuration reference](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference) describes the supported options.

## Dependency maintenance policy

We retain the PHP 8.3, Node 24 and Prometheus 3.13 lines while they meet our needs and receive upstream support. Dependabot may propose patch and digest refreshes within those lines. Its Docker ignore rules suppress PHP and Prometheus minor/major version updates and Node major version updates; image digests remain pinned and changes still require review. Grafana's existing update policy is unchanged. Official GitHub Actions also continue to receive reviewed updates, including major releases.

As reviewed on 2026-10-25, PHP 8.3 has security support through 2027-12-31, Node 24 remains LTS and keeps native/container API validation aligned, and Prometheus 3.13 is our maintained LTS line. [PHP support](https://www.php.net/supported-versions.php), the [Node release schedule](https://github.com/nodejs/Release/blob/main/schedule.json) and [Prometheus release policy](https://prometheus.io/docs/introduction/release-cycle/) provide the upstream support context.

We trade immediate access to new version features for a smaller migration scope. A change of line needs an explicit reason, upstream migration review, documentation and the appropriate CI evidence. We must revisit this choice before support ends or when a required fix is unavailable in the selected line. The ignore rules filter update proposals; they do not monitor support deadlines or prove that an image is free of vulnerabilities. [Dependency review evidence](REVIEW.md#dependency-review-2026-10-25) records the current decisions and remaining migration work.

## Local reproduction and limits

Use `php bin/check.php` and the locked validator for native checks, or the commands in [Docker operation](DOCKER.md) for the same container paths. The lifecycle script always creates its own uniquely named synthetic project; it must not remove the normal stack's volumes.

CI verifies deterministic behavior with synthetic data. It does not approve a customer state change, verify human identity, determine compliance, exercise live provider accounts, prove production capacity or establish a restore procedure. Never rely on AI in compliance; merge checks and runtime approval remain distinct controls.
