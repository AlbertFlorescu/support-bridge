# Operations

Use Ubuntu WSL at `/home/aflorescu/adobe`, Linux executables and the existing `support-bridge-live-demo` Compose project. Retain its eight volumes, accounts and private settings.

## Access and sign-in

| Surface | Address | Access |
| --- | --- | --- |
| Grafana | [1990](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) | Persisted Grafana account |
| Browser review | [1988/review](http://127.0.0.1:1988/review) | Allowlisted Grafana server administrator |
| Swagger | [1988/docs](http://127.0.0.1:1988/docs) | Read-only reference |
| Prometheus | [1989](http://127.0.0.1:1989/) | No additional loopback login |

Use `127.0.0.1` consistently so the selected project cookie works across ports. Grafana bootstrap files initialize new databases; they do not prove a rotated password. Preserve accounts and verify actual login rather than resetting a volume.

## Run the deployment

Use the same prefix for every live command:

~~~bash
docker compose --env-file /absolute/private/live-demo.env \
  -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml \
  up --build -d --wait
~~~

Use it with `ps`, `logs --tail=100 api worker` or `stop --timeout 120 worker`. Stop/down retains data; never delete normal or historical volumes. A controlled pause must exit with code 0 before staging source changes; inspect recovery if it was killed, then restore the previously running worker.

[Environment inputs](../.env.example) describe settings. New deployments leave effects disabled; bootstrap creates no vendor records. Import authorized credentials, then deliberately enable the required channels/profiles:

~~~dotenv
COMPOSE_PROFILES=oauth-renewal,live-worker
BRIDGE_ZENDESK_INTAKE_ENABLED=1
BRIDGE_GITHUB_COMMENTS_ENABLED=1
BRIDGE_ZENDESK_NOTES_ENABLED=1
BRIDGE_REVIEW_ENABLED=1
BRIDGE_REVIEW_LOGINS=approved-admin-login
~~~

Keep existing profiles when editing settings and recreate affected readers. Starting the worker initiates work. Services are non-root/read-only with loopback ports; production ingress/TLS is separate work.

## Live demonstrations

Follow the [Zendesk-first walkthrough](SCENARIO.md). Wait for the reverse stream's `initialized_at` before a new internal answer: its first complete scan excludes history. The worker orders intake, fields, GitHub comments and new Zendesk notes, then waits 60 seconds. There is no traffic generator.

## Inspect and review

In Grafana choose a row's workflow link or **Review pending changes**.

- **Conflict:** compare the baseline and both observations; open the linked source records. Confirm under **Use Zendesk** or **Use GitHub**. The choice queues that side's title and canonical state. It never approves closing/reopening.
- **Awaiting approval:** inspect every proposed update, enter your reason, confirm and choose **Approve this exact change**.
- Refresh after a worker pass. Only verified agreement becomes **Synced** and releases eligible messages.

Neither submission writes vendors. Forms expire after ten minutes and bind the session/action/pair/evidence; conflicts also bind the selected side. Changed source or saved evidence is rejected. Return to the pair and review fresh evidence; do not replay an old form after a timeout. Editing both sources to agree does not itself release an existing conflict hold.

The selected login must be allowlisted and a Grafana server administrator. Exact Host/Origin, same-origin referrer policy and null-origin rejection remain required. [Authority and limitations](ASSUMPTIONS.md#authority) explain shared identity and separate state approval.

## API and CLI

[OpenAPI](api/openapi.json) owns request schemas. JSON API requests use the broad operator bearer, not Grafana cookies. Swagger submission is disabled.

~~~bash
docker compose --env-file /absolute/private/live-demo.env \
  -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml \
  run --rm --no-deps cli inspect PAIR_ID
~~~

| Command | Effect |
| --- | --- |
| `status`, `inspect PAIR_ID`, `comments`, `notes` | Stored evidence; delivery lists default to 50 rows |
| `resolve PAIR_ID github\|zendesk` | Fresh whole-source choice, no approval |
| `approve PAIR_ID HASH REASON` | Exact-plan approval, no immediate vendor write |
| `retry PAIR_ID` | Reset failed work's budget, no added authority |
| `sync` | Process **all eligible pairs**; inspect scope first |

Use API pagination when enumerating more delivery rows; list pages are at most 100. HTTP 200 means an operation ran, not that every pair succeeded. CLI exit 2 means attention and 3 retry work. Inspect 409 categories for busy/stale/invalid operations.

## Credentials and renewal

Keep credentials outside source, arguments, env metadata, logs and recordings. GitHub needs repository-scoped Issues read/write and Metadata read; Zendesk needs ticket read/write.

Networkless importers accept bounded JSON stdin from a protected private file. Select the same Compose prefix with `run --rm --no-deps -T credentials` for exactly `github-token`/`zendesk-token`, or `oauth-credentials` for exactly `subdomain`/`client_id`/`client_secret`. Importing manual files does not replace a managed bundle. Recreate API/worker after manual or GitHub startup-token rotation.

For managed renewal, select `BRIDGE_ZENDESK_AUTH=oauth`, clear manual token sources and set `BRIDGE_ZENDESK_OAUTH_BUNDLE_FILE=/run/secrets/vendor/zendesk-oauth.json`. Only the importer writes issuer configuration; only the renewer reads it.

~~~mermaid
flowchart LR
    Import["Networkless client importer"] --> Issuer[("Issuer files")]
    Issuer --> Renewer["Isolated renewer"]
    Renewer <-->|"Four-hour ticket grants"| Zendesk["Zendesk OAuth"]
    Renewer -->|"Atomic bundle / separate lock"| Tokens[("Vendor token files")]
    Tokens -->|"Per-request managed read"| Readers["API, worker and CLI"]
~~~

Routine renewal needs no reader restart and never replays a vendor write. The shared vendor volume also exposes the unused GitHub token to the renewer. GitHub credentials do not auto-renew. For a client-identity migration, pause renewal and retire only the old bundle/matching heartbeat under its credential lock after importing the authorized replacement. Never erase business work to repair access.

## Monitoring

The current table uses protected `/monitoring/pairs` through the pinned Infinity backend; seven other panels use Prometheus history from `/metrics`. Their read-only token cannot operate the workflow. The allowed backend host is only `http://api:8080`.

All mappings appear automatically. Subjects/resolution are the **last verified baseline**, not pending edits. Source links require vendor login. Delivered/waiting counts are independent; waiting includes pending/uncertain/blocked work. Unknown/never-verified remains explicit. Worker observation, five-second scraping and refresh are separate clocks; refreshing does not contact vendors.

## Recovery and evidence

Correct the cause before retrying. Fields read before recovery; an already achieved target needs no resend. Attempted unknown creation/appends require unique marked read-back, never blind POST repetition.

Back up SQLite consistently with WAL or stop writers. Preserve all live/historical volumes. Private capture holds the canonical lock without effects; provider and monitoring reads remain sequential, not atomic. [Playback](../tools/demo-viewer/README.md) cannot select, approve or synchronize.

## Verification

Run inside WSL with locked development tools:

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

Native form checks need Node 26/Linux Chromium (`BRIDGE_TEST_BROWSER` can select it). They use local providers and an injected Grafana verifier; lifecycle checks separately use real Grafana sessions. Neither approves live records. Render/inspect Mermaid separately from Markdown link/fence checks.

For a **disposable** project, select separate `COMPOSE_PROJECT_NAME` and ports, then run the verification image, stack smoke, `docker/check-alerts.sh`, `check-lifecycle.sh`, `check-live-demo.sh` and `check-oauth-renewal.sh`. These own isolated fixtures. Remove only disposable volumes. Retained-stack checks must use current Grafana credentials without resetting accounts.

## Publishing and protection

[Verify](../.github/workflows/ci.yml) runs on PR/main/merge-group/manual events without path filters. **Required checks** requires successful **PHP checks** and **Container checks** for the current revision; failed/skipped/cancelled jobs cannot pass.

Use maintainer branches under `feature/`, `fix/`, `chore/`, `docs/` or `ci/`, normal PRs and the expected merge head. General source-bot publishing is disabled; Actions tokens default to read-only. CI does not deploy or approve runtime effects.

Main's active [ruleset](../.github/rulesets/main.json) requires a PR, resolved conversations, an up-to-date branch and Required checks; force pushes/deletion are blocked with no bypass actors. Zero peer approvals does not establish independent review. Recheck server enforcement after administrative changes. [Security](../SECURITY.md) owns visible dependency proposals and vulnerability findings.

Tests, hosted checks, actual rendering and fictional vendor observations are distinct evidence. Raw reports stay private; Git history retains superseded guides. None establishes production capacity, customer acceptance or compliance.
