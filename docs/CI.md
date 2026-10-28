# GitHub verification and merge controls

Our [Verify workflow](../.github/workflows/ci.yml) runs PHP/API tests and container checks. [GitHub Actions](https://github.com/AlbertFlorescu/support-bridge/actions) records each published revision's results.

## Required status check

Require **`Required checks`** on `main`. This unique aggregate check passes only when both verification jobs succeed. Failed, cancelled or skipped dependencies fail the gate.

| Check | Coverage |
| --- | --- |
| `PHP checks` | Locked Composer development install and audit, strict PSR-4 autoload verification, configured PSR-12 style check, PHP syntax/application tests, synthetic demo, OAuth/bootstrap/checkpoint contracts, dependency-free replay checks, vulnerability-tool tests, Swagger asset freshness, OpenAPI parsing and real loopback response-fixture validation |
| `Container checks` | Image builds, isolated PHP/style/OpenAPI/replay verification, local stack startup, service-network smoke checks, graceful shutdown, pending-plan/credential persistence through recreation and a disposable live-overlay import/bootstrap check without vendors |
| `Required checks` | Both jobs succeeded for the revision under review |

Verify runs for PRs targeting `main`, pushes to `main`, manual dispatch and merge groups. It has no path filters, so documentation changes also produce the required status. [Concurrency settings](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency) cancel outdated runs for the same PR or ref. Merge-group checks support a future merge queue; they do not enable one.

## Branch protection is a separate setting

As checked on 2026-10-26, this source repository is public, `main` is unprotected, and no repository ruleset is installed. PRs with passing checks are maintainer practice rather than a server-side restriction. The checked-in JSON does not enforce that practice. [GitHub's availability and settings](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches) describe the separate repository controls.

The prepared [main ruleset](../.github/rulesets/main.json) requires:

- A PR, resolved review conversations and an up-to-date branch.
- `Required checks`, with GitHub Actions as the expected source.
- No force pushes, deletion or administrator bypass.

It has no bypass actors and requires zero peer approvals while a separate reviewer is unavailable; a maintainer cannot approve their own PR. To enable the prepared rule, import it through **Settings → Rules → Rulesets → New ruleset → Import a ruleset** and verify enforcement. The JSON requests active enforcement but has no effect until imported. An equivalent classic rule is an alternative; avoid conflicting duplicate rules.

## Permissions, dependencies and artifacts

Verify has read-only repository contents access, and checkout removes its credentials. Official Actions use verified commit pins. Composer installs the root lockfile with plugins and scripts disabled, then verifies optimized PSR-4 autoloading, runs the configured style gate and a locked package audit including development dependencies. npm uses its lockfile, disabled lifecycle scripts and GitHub's package cache. Container builds use the checked-in digest pins and local layers within a job. Verify neither publishes images nor deploys services. Separate security publishers have the issue/content/Actions permissions listed in [SECURITY.md](../SECURITY.md).

The verification image pins Composer 2.10.3 and installs PHP_CodeSniffer 4.0.4 from the root lockfile. Native CI installs Composer from the runner's package repository. These are development tools: the runtime image contains neither Composer nor the development `vendor/` tree. [The PSR-12 ruleset](../phpcs.xml.dist) defines checked paths and standalone-harness exceptions, while `composer.json` records PSR-4 namespace mapping and style commands. A passing style check is separate from behavior, recovery and governance evidence.

[Git attributes](../.gitattributes) keep detected text files on LF line endings in both Windows and Linux checkouts. This prevents host Git settings from making a local Docker build fail the PSR-12 line-ending rule while a Linux CI checkout passes. Existing working files with CRLF need normalization before rebuilding; changing attributes does not rewrite already modified files.

| Artifact | Retention |
| --- | ---: |
| Synthetic API response fixtures | 7 days |
| Vulnerability reports | 14 days |
| Prepared asset repair | 1 day |

API fixtures contain controlled test responses, not vendor records or deployment configuration. Failed container jobs print bounded diagnostics. Disposable runner volumes hold CI credentials and state and are removed after the job. Credential volumes, Compose environment dumps and application state directories are never uploaded.

OAuth issuance tests inject a controlled transport; live-bootstrap tests verify empty bound initialization without provider effects. `docker/check-live-demo.sh` exercises the live overlay's credential import/bootstrap boundaries in a disposable project with controlled tokens and no vendor access. It must leave normal project volumes intact. Checkpoint and viewer tests use controlled evidence and check bounds, exclusions and read-only navigation. CI does not issue real OAuth credentials, create vendor records, enable the live worker or publish private demonstration JSON/HTML/video. A green replay or import check is separate from evidence that a vendor workflow was actually executed.

[Dependabot](../.github/dependabot.yml) schedules daily version checks at 08:00 UTC for GitHub Actions, npm API tooling, root Composer development packages, PHP/Node/Composer/monitoring images and the Trivy scanner. Each ecosystem entry allows ten version-update PRs. A wildcard cooldown exclusion removes release-age delay for tracked dependencies; locally built image names are excluded by the Docker allowlist. See [open proposals](https://github.com/AlbertFlorescu/support-bridge/pulls?q=is%3Apr+is%3Aopen+author%3Aapp%2Fdependabot) and [configuration options](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference).

Native alerts and automatic security updates are enabled for supported manifests, including the Composer development lockfile. Security updates include development dependencies and respond to alerts independently of the version schedule. npm security PRs are grouped separately from routine npm updates. Our [security workflow](../SECURITY.md) adds image scanning, CVE List/NVD enrichment, one tracker and Swagger asset repair. The locked Composer audit is a Verify check; it is not another custom tracker feed. `Required checks` tests the reporting/repair code and rejects stale Swagger files; vulnerability inventory runs retain their own statuses and evidence.

## Dependency maintenance policy

PHP 8.3, Node 24 and Prometheus 3.13 are our selected runtime lines, not proposal filters. Dependabot may show patch, minor and major updates for every allowlisted image. npm minor/patch updates are grouped; majors can appear separately. Image digests and Actions pins change only through reviewed updates.

Our 2026-10-25 review recorded PHP 8.3 security support through 2027-12-31, Node 24 LTS and Prometheus 3.13 LTS. See [PHP support](https://www.php.net/supported-versions.php), the [Node release schedule](https://github.com/nodejs/Release/blob/main/schedule.json) and [Prometheus release policy](https://prometheus.io/docs/introduction/release-cycle/).

A new runtime line needs a reason, migration review, updated documentation and relevant tests. Revisit the selection before support ends or when a required fix is unavailable. Updates are not merged or deployed automatically. Dependabot does not monitor every support deadline. [Earlier review evidence](REVIEW.md#dependency-review-2026-10-25) records candidate decisions and remaining migration work.

The tracker's **Vendor fixed version** is a package-level fix, not an available parent-image tag. Dependabot can edit our manifests and image pins, but not a Go module or OS package inside an upstream image. Those findings need an upstream release, image refresh or reviewed rebuild and a new scan. Cases without an available image stay tracked.

## Local reproduction and limits

Use `php bin/check.php`, `composer autoload:check`, `composer style`, `composer audit --locked --no-plugins` and the locked OpenAPI validator for native checks, or follow [Docker operation](DOCKER.md). Install locked Composer development packages first with `composer install --no-interaction --no-plugins --no-scripts`. The lifecycle script creates a uniquely named synthetic project and must never remove the normal stack's volumes.

CI uses synthetic data. Live provider behavior, production capacity and restore procedures need separate validation. Passing checks do not verify human identity, approve customer state changes or establish compliance. Never rely on AI in compliance; runtime approval remains a separate control.
