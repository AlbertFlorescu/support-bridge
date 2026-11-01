# Verification and maintenance

[Verify](../.github/workflows/ci.yml) runs on every PR to main, main push, manual dispatch and merge group. It has no path filters. **Required checks** passes only when both jobs succeed; failed, skipped or cancelled dependencies fail the gate.

| Job | Coverage |
| --- | --- |
| PHP checks | Locked Composer install/audit, strict PSR-4 and PSR-12, PHP behavior/recovery tests, API/OpenAPI fixtures, Swagger freshness, security-tool tests, replay, Markdown checks and native-browser approval submission |
| Container checks | Isolated verification image, real local stack smoke, labelled Prometheus rule cases, persistence/recreation, real Grafana session/form checks, live-overlay bootstrap and controlled no-vendor OAuth renewal checks |
| Required checks | Both jobs succeeded for the revision under review |

## Publishing and protection

Use a maintainer branch under `feature/`, `fix/`, `chore/`, `docs/` or `ci/`. Review the diff, push, open a PR and merge only its current tested revision. Verify does not publish images or deploy services. General source-bot publishing remains disabled; repository Actions tokens default to read-only and cannot create/approve PRs.

On 2026-11-02 the [main ruleset](../.github/rulesets/main.json) was activated and read back from GitHub: main is protected. It requires a PR, resolved conversations, an up-to-date branch and **Required checks** from GitHub Actions; force pushes and deletion are blocked. There are no bypass actors. Zero peer approvals are required while a separate reviewer is unavailable. This does not establish independent review. Check actual settings after administrative changes; checked-in JSON alone enforces nothing.

Checkout removes credentials. Actions and container bases use reviewed pins. Composer/npm development installs disable plugins/lifecycle scripts. Runtime images contain neither Composer nor browser/development dependencies. Synthetic API/native-browser artifacts last seven days; security reports last fourteen and prepared asset repair one. Private credentials, vendor responses and application volumes are never uploaded.

## Reproduce checks

Run inside Ubuntu WSL, with locked development tools installed:

~~~bash
composer install --no-interaction --no-plugins --no-scripts
composer validate --strict
composer autoload:check
composer style
composer audit --locked --no-plugins
php bin/check.php
node --test tools/docs/check.test.cjs tools/demo-viewer/viewer.test.cjs
node tools/docs/check.cjs
node tools/browser/check.mjs
(cd tools/api-docs && npm ci --ignore-scripts --no-audit --no-fund && node check-vendor.cjs && node validate.cjs)
python3 -m unittest discover -s tools/security -p 'test_*.py'
~~~

The browser check uses Node 24 and a Linux Chrome/Chromium binary, optionally selected with `BRIDGE_TEST_BROWSER`. GitHub's [Ubuntu runner image](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md) supplies a browser. It submits the actual native approval form against isolated local providers with a synthetic Grafana-session verifier, then checks that approval alone caused no destination write. Container lifecycle checks separately verify actual Grafana authentication. Neither contacts vendors or approves live records.

For container changes use a **disposable project**, never the retained live project:

~~~bash
export COMPOSE_PROJECT_NAME=support-bridge-verification
export BRIDGE_HTTP_PORT=18081 BRIDGE_PROMETHEUS_PORT=19091 BRIDGE_GRAFANA_PORT=13001
docker compose --profile test run --build --rm verify
docker compose up --build -d --wait --wait-timeout 120
sh docker/check-alerts.sh
docker compose --profile test run --rm smoke
sh docker/check-lifecycle.sh
sh docker/check-live-demo.sh
sh docker/check-oauth-renewal.sh
docker compose down --volumes --remove-orphans
~~~

The final command deletes only that disposable project's volumes. Lifecycle/bootstrap scripts create their own uniquely named fixtures. Never substitute the live project or delete its volumes.

Markdown checks cover local files, anchors and fences. Parse/render changed Mermaid separately and inspect the output. Native/browser, controlled container, hosted CI and selected live-provider observations are different evidence.

## Dependencies and security

[Dependabot](../.github/dependabot.yml) proposes daily updates for Actions, npm, Composer and allowlisted images, including newer major runtime lines. Selected PHP 8.3, Node 24 and Prometheus 3.13 lines are deployment choices, not filters hiding newer releases. Review support deadlines and migration needs before adoption; updates are not automatically merged or deployed.

[Security maintenance](../SECURITY.md) owns alerts, image scanning, CVE List/NVD enrichment and bounded Swagger asset repair. A vendor's fixed package version is not necessarily an available parent image. Retain unresolved findings until an available image or reviewed rebuild is scanned. Scanners, feeds and passing CI do not certify compliance or complete vulnerability coverage.

## Verification evidence

The 2026-11-02 revision adds a native-form regression check for the browser approval adapter. A deliberate temporary `no-referrer` regression failed that check; restoring `same-origin` passed while opaque/null origins remained rejected. The fixture is local and automatic approval is confined to isolated tests.

Local PHP/style/audit, API/OpenAPI, security-tool, replay and documentation gates passed. Disposable container verification, rule cases, lifecycle/Grafana-session checks, live bootstrap and OAuth renewal passed; the retained stack passed nine read-only smoke checks without resetting accounts or volumes. All five Mermaid diagrams were rendered and visually inspected.

The ten [demonstration cases](SCENARIO.md#demonstration-cases) are bounded fictional vendor observations, not customer acceptance. Actual Grafana rendering verified ten rows, twenty source links, twelve columns, automatic refresh and both pending review forms without approval submission. Raw responses, screenshots, recordings and previous detailed review logs remain private; Git history retains earlier guides. [Actions](https://github.com/AlbertFlorescu/support-bridge/actions) records hosted results for each published revision. Local success does not establish a hosted result before publication.

Tests do not demonstrate production throughput, high availability, tested restore/rollback, independent human identity or policy satisfaction. **Never rely on AI in compliance.**
