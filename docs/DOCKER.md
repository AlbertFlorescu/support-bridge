# Docker operation

We run one normal demonstration deployment: the existing `support-bridge-live-demo` Compose project, every linked fictional escalation, one API, one Prometheus and one Grafana. We retain that project's application state, credentials and monitoring history. Fresh deployments keep its worker disabled until explicitly selected. The Zendesk-first flow enables that worker and tagged intake together; all reconciliation retains the normal authority and review gates. The optional `oauth-renewal` profile adds an isolated Zendesk credential service without another dashboard or ticket generator.

Use Docker Engine with Linux containers and Compose 2.24.4 or later. The runtime image contains PHP and application classes; the separate verification image adds Composer development tools and Node. Docker Desktop must use its Linux engine; WSL commands also require Docker Desktop integration.

## Choose a stack and its ports

The normal deployment uses both Compose files and its private env file. Keep this full prefix for every operation so an ambient `COMPOSE_PROJECT_NAME` or `.env` cannot select another project's data:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml up --build -d --wait
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml ps
```

| Service | Normal host address | Inside the project's network |
| --- | --- | --- |
| API and Swagger | [127.0.0.1:1988/docs](http://127.0.0.1:1988/docs) | `api:8080` |
| Prometheus | [127.0.0.1:1989](http://127.0.0.1:1989/) | `prometheus:9090` |
| Grafana | [127.0.0.1:1990](http://127.0.0.1:1990/d/supportbridge-overview) | `grafana:3000` |

Keep these explicit values in the private deployment env file alongside the provider account identities described in [LIVE_DEMO.md](LIVE_DEMO.md#select-the-isolated-live-project):

```dotenv
BRIDGE_LIVE_HTTP_PORT=1988
BRIDGE_LIVE_PROMETHEUS_PORT=1989
BRIDGE_LIVE_GRAFANA_PORT=1990
```

A shell override can take precedence over the env file. Check actual bindings rather than inferring them from a browser bookmark:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml port grafana 3000
```

The guarded `live-init` role creates schemas and bootstrap credentials without provider access, fixtures, links or reconciliation. Later starts preserve existing state. No traffic generator is present. The worker requires the explicit `live-worker` profile; enabling it starts an immediate sweep and is an effectful action, not part of ordinary startup. `BRIDGE_LIVE_SWEEP_INTERVAL` then sets its delay, defaulting to 60 seconds. Do not enable it merely to move a demonstration past a review wait.

Published ports bind to loopback. Prometheus scrapes `api:8080/metrics`; Grafana queries `prometheus:9090` for six panels and `api:8080/monitoring/pairs` through its Infinity backend for the current table. `localhost` inside a container is not another service. The API uses PHP's development server internally on `0.0.0.0:8080`; it remains a development server. The standard Docker bridge permits outbound vendor requests and is not an egress firewall. API health establishes liveness, not vendor compatibility or convergence.

Retained volumes from former local deployments stay parked and are never imported or merged into live state. Do not rename the live project or use historical volumes for disposable tests. Local providers remain available only for meaningful isolated verification.

## Enable Zendesk-first operation

Set the following in the existing private deployment file after checking all current pairs and tagged tickets. These flags authorize effectful polling; ordinary health and dashboard checks do not.

```dotenv
COMPOSE_PROFILES=oauth-renewal,live-worker
BRIDGE_ZENDESK_INTAKE_ENABLED=1
BRIDGE_ZENDESK_ESCALATION_TAG=support_bridge_escalate
BRIDGE_LIVE_SWEEP_INTERVAL=60
```

Retain `oauth-renewal` when using managed credentials. Recreate API and worker with the same project, both Compose files and private env file after changing settings; retain every named volume. The existing worker runs intake, reconciliation and explicitly enabled comment delivery, so no additional workflow container is needed. To pause effects, stop `worker`; the API, monitoring and credential renewer remain available. `POST /api/intake` is still an explicit effectful command while intake is configured, even if the worker is stopped. See [intake operation](INTAKE.md) before enabling.

To copy GitHub issue comments as internal Zendesk notes, inspect the linked histories, add `BRIDGE_GITHUB_COMMENTS_ENABLED=1` to the same private file, and rebuild/recreate API and worker. Existing comments are included once. This adds no volume or service; new appends retain the review boundary. See [comment operation](COMMENTS.md).

## Operate the workflow

Read the current stored state before proposing effects. The CLI shares the API's application image, live state and canonical lock:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml run --rm --no-deps cli status
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml run --rm --no-deps cli inspect PAIR_ID
```

Replace `PAIR_ID` with an existing ID from `status`. Actual vendor mappings are private. A manual `sync` processes all eligible linked pairs, so inspect the entire link set before an authorized sweep. Port changes, container recreation and monitoring access do not authorize synchronization, source edits, new links or approval.

Any outbound state change holds the whole durable plan for explicit approval. Review the exact inspected hash and fresh evidence; neither retry nor choosing a conflict source grants approval. The next eligible sweep applies an approved plan only while its evidence remains valid. An approval wait returns CLI exit code 2 as an operator-attention result. [The live checkpoints](LIVE_DEMO.md#run-the-customer-checkpoints) describe deliberate operations. Never rely on AI in compliance.

Keep one host and the canonical single-worker lock. Do not scale API or workers across hosts against this SQLite volume. Worker health, when enabled, measures loop heartbeat, not healthy business outcomes or a convergence objective.

## Credentials and isolation

The initializer generates separate operator and metrics tokens, a Grafana bootstrap password and a Grafana secret key only when absent. They persist in dedicated volumes, never images or build arguments.

| Credential volume | Readers |
| --- | --- |
| `operator-secret` | API and explicit operator tooling |
| `metrics-secret` | API, Prometheus, Grafana (read-only records datasource) and explicit operator tooling |
| `grafana-secret` | Grafana |
| `vendor-secret` | API, explicit CLI and opt-in live worker read-only; access-token importer and optional renewer write |
| `issuer-secret` | Networkless `oauth-credentials` importer writes; optional `zendesk-token-renewer` reads only |

The explicit `credentials` service under the `tools` profile imports only `github-token` and `zendesk-token` through bounded JSON stdin. It runs as UID10001 with no network and mounts only `vendor-secret` read/write at `/var/lib/support-bridge`; this is not the business state volume. Its directory is `0700` and token files are `0400`. Application readers mount it at `/run/secrets/vendor`. The separate `oauth-credentials` service imports the confidential client into `issuer-secret` for [automatic Zendesk renewal](OAUTH.md). API, CLI, worker and monitoring never receive that volume. The renewal service has read-only issuer credentials and write access to the shared vendor volume, which also gives it filesystem access to the unused GitHub token. [The manual procedure](LIVE_DEMO.md#credentials-and-manual-oauth-issuance) remains available for explicit compatibility mode.

These are plaintext files protected by ownership, reader mounts and Docker access controls, not encrypted Docker Secrets or a secret manager. Docker administrators can inspect them. Managed Zendesk credentials are read from one atomic token/expiry bundle before each provider request and rotate without a reader restart. Manual/GitHub token values loaded at startup still require reimport and reader recreation after replacement. Changing between managed and manual modes also requires recreation to apply the new configuration. Keep secret values out of command arguments, Compose environment values, source, logs and recordings.

Prometheus, Grafana, initializer and smoke receive no vendor credential volume. Scraping makes no vendor calls. Smoke reads operator, metrics and Grafana bootstrap files but performs no business writes. The optional worker needs vendor tokens but no operator, monitoring or Grafana credentials. The broad shared operator token does not establish verified human identity or scoped agent authority.

### Sign in to Grafana

Open the normal project's [Grafana login](http://127.0.0.1:1990/login) and use its persisted account. Only a **new Grafana database** starts with `admin` and a generated bootstrap password. Restarting or rebuilding containers retains the current account and password. Operator and read-only monitoring bearer tokens are separate credentials. Grafana now reads `metrics-secret` at startup for its fixed-host Infinity records datasource; that read-only token also permits baseline subjects/source URLs, never operator commands. Recreate Grafana after monitoring-token rotation. The pinned plugin lives in the read-only image at `/opt/grafana/plugins`, outside the retained data volume; no startup download or extra service is needed.

For an unchanged bootstrap account only, read the generated password locally:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml exec grafana cat /run/secrets/grafana/password
```

Keep the displayed value out of shared logs and recordings. The file initializes a new database; it does not prove the current password after account rotation. Editing the file does not rotate an existing account. Preserve the Grafana database and use authorized Grafana administration for account changes.

Use `127.0.0.1` consistently. Compose derives `GF_AUTH_LOGIN_COOKIE_NAME` from the project name. Applying that setting to an older deployment requires recreating Grafana with the full live prefix and `up -d --no-deps --force-recreate grafana`, then one fresh login; accounts and data remain. Browser cookies are shared across ports, so independent browser sessions help diagnose older shared-cookie deployments or explicit test environments. Mixing `localhost` and `127.0.0.1` can leave separate sessions.

Verify an actual browser login after an account change. The standard smoke verifier uses `admin` and the bootstrap password; it cannot validate rotated accounts. A successful health endpoint or dashboard provisioning check also does not establish that the intended account can sign in. Do not reset accounts or volumes to make that verifier pass.

Runtime services use non-root users, read-only root filesystems where supported, dropped capabilities and no-new-privileges. Writable paths are named data volumes or temporary filesystems. The stack mounts neither the Docker socket nor the host source tree. Prometheus has no separate user login in this local setup; host users able to reach it can query aggregate data. Grafana signup, anonymous access, analytics and automatic plugin/update checks are disabled.

## Data and lifecycle

Keep the `support-bridge-live-demo` project identity. Its named volumes retain business state, aggregate metrics, credentials, Prometheus time series and Grafana data. Enabling OAuth renewal preserves the seven existing volumes and adds only `issuer-secret`; never recreate the live project under another name to enable it. The bare PHP CLI uses separate filesystem state such as `var/local`; it is never automatically imported.

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml logs --tail=100 api prometheus grafana
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml stop
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml up -d --wait
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml down
```

Ordinary `stop` and `down` preserve named volumes; `up` reuses them. Never add volume removal as routine cleanup: it discards baselines, plans, approvals, audit, metrics, accounts and credentials. Back up SQLite consistently with its WAL, or stop all application writers before taking a snapshot. Protect backups. Mixing unrelated snapshots is not a tested recovery procedure; one local volume supplies neither high availability nor a distributed lock.

## Verification

The safe `local` base supports isolated merge, approval, API and recovery checks. It is not a second operating deployment or an additional dashboard. Run the offline gate with its own project:

```bash
docker compose -p support-bridge-verification -f compose.yaml --profile test run --build --rm --no-deps verify
```

This uses temporary synthetic state and no vendor network. The verification image contains locked Composer style tooling, PHP tests, Node/OpenAPI tools and offline viewer checks. The runtime image contains none of that development dependency tree, Composer or Node/npm. Shared typed handlers, injected connections and the canonical lock remain unchanged.

Run the bounded disposable lifecycle checks from a shell with `sh` and Docker:

```bash
sh docker/check-alerts.sh
sh docker/check-lifecycle.sh
sh docker/check-live-demo.sh
```

The alert check builds the selected Prometheus image and evaluates its actual rule file with controlled labelled series in a networkless container, without application credentials or state. Lifecycle/bootstrap checks each use a unique test project and remove only their own disposable volumes. The lifecycle check uses temporary ports 18080/19090/13000 and checks shutdown plus pending-plan/credential persistence; `BRIDGE_LIFECYCLE_HTTP_PORT`, `BRIDGE_LIFECYCLE_PROMETHEUS_PORT` and `BRIDGE_LIFECYCLE_GRAFANA_PORT` select unused alternatives. The live check uses controlled fake tokens with network disabled to check import, guarded bootstrap, read-only readers and rotation; it makes no vendor calls, links or synchronization. Neither adopts parked or live state. The additional `docker/check-oauth-renewal.sh` checks isolated issuer access, managed credential publication and hot rotation with controlled credentials and no live vendor requests. Run it for renewal or credential-reader changes as well. Record results separately from vendor validation.

For an already-running live project with at least one explicit link **and an unchanged Grafana bootstrap account**, the read-only service smoke command is:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml --profile test run --rm --no-deps smoke
```

The smoke verifier has a shared 45-second deadline and nine groups: API liveness, Swagger/OpenAPI assets, authentication, inspection, private-path denial, exporter consistency, real Prometheus scrapes/queries, loaded rules and Grafana datasource/dashboard provisioning and an actual protected records-backend query. The current table reads subjects/links from the API; it does not wait for a Prometheus scrape. It performs no business writes or vendor calls. `--no-deps` avoids recreating dependencies. For a rotated Grafana account, check that account separately and report the standard verifier's bootstrap limitation without resetting data.

Use the full live `ps` prefix and service logs to diagnose health dependencies. Pending reviews are valid business states, not infrastructure failures. Passing checks does not establish customer policies, vendor behavior, production throughput, notification delivery or backup recovery.

## Scope and trade-offs

One operating project makes record ownership, credentials and dashboard interpretation unambiguous. Managed Zendesk renewal adds one isolated service and issuer volume to avoid repeated manual token replacement. It depends on host/vendor availability and does not change business retry or review policy. Separate API, monitoring and optional scheduling services still preserve process boundaries. The safe local base prevents an ambient `.env` from silently enabling vendor access; explicit live configuration selects the real account binding and manual operation. Local provider fixtures and the reliability demo retain reproducible failure checks without a continuous traffic generator.

Broader deployment still needs reviewed data permissions, a suitable PHP server, TLS/ingress, credential lifecycle, operational ownership and customer acceptance. Our [governance model](GOVERNANCE.md), [assumptions](ASSUMPTIONS.md) and [observability semantics](OBSERVABILITY.md) remain authoritative. The [monitoring guide](../monitoring/README.md) describes the automatically populated all-pairs dashboard, numeric-ID exposure and observation limits.
