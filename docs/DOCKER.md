# Docker operation

We run the application, its reconciliation worker, Prometheus and Grafana with Docker Compose. The default stack uses synthetic local records and makes no GitHub or Zendesk requests. It needs Docker Engine with Linux containers and the Compose plugin. The runtime image contains PHP and application classes; the verification image adds Composer development tools and Node for style and API checks. Monitoring runs in its own images.

## Choose a stack and its ports

The traffic stack generates continuous synthetic activity. The default stack runs the API, worker and monitoring without a generator. The explicitly configured live demo uses actual vendor accounts, with its background worker disabled by default. These are separate Compose projects with separate data, credentials and monitoring history; use only the intended project's command prefix.

| Service | Traffic stack (`support-bridge-traffic`) | Default stack (`support-bridge`) | Explicit live demo (`support-bridge-live-demo`) | Inside each project's network |
| --- | --- | --- | --- | --- |
| API and Swagger | [127.0.0.1:8081/docs](http://127.0.0.1:8081/docs) | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) | [127.0.0.1:8082/docs](http://127.0.0.1:8082/docs) | `api:8080` |
| Prometheus | [127.0.0.1:9091](http://127.0.0.1:9091/) | [127.0.0.1:9090](http://127.0.0.1:9090/) | [127.0.0.1:9092](http://127.0.0.1:9092/) | `prometheus:9090` |
| Grafana | [127.0.0.1:3001](http://127.0.0.1:3001/d/supportbridge-overview) | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) | [127.0.0.1:3002](http://127.0.0.1:3002/d/supportbridge-overview) | `grafana:3000` |
| Worker | Immediate sweep, then every five seconds | Immediate sweep, then every minute | Disabled unless `live-worker` is selected | No published port |
| Traffic generator | Every five seconds by default | Absent | Absent | No published port |

These are the checked-in host-port defaults, not interchangeable addresses for one deployment. Use links and credentials from the same project. Grafana on port 3001 belongs to traffic, 3000 to the default project and 3002 to the live demo. Container port 3000 remains correct inside each Grafana container. Prometheus scrapes `api:8080`, and Grafana queries `prometheus:9090` through their own project's network.

### Start the traffic stack

From the repository root, with Compose 2.24.4 or later:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml ps
```

Keep this full prefix for traffic commands. [Traffic operation](TRAFFIC.md) covers cadence, scripted/manual review and stopping the generator.

### Start the default stack

```bash
docker compose -p support-bridge -f compose.yaml up --build -d --wait
docker compose -p support-bridge -f compose.yaml ps
```

The one-shot initializer creates the local fixture and generates credentials only when they are absent. Successful initialization precedes API/worker startup. Health dependencies wait for the services required by monitoring. Later starts retain existing data and credentials.

### Explicit live demonstration

Follow [LIVE_DEMO.md](LIVE_DEMO.md) for the private account configuration, OAuth access-token issuance and exact command prefix using `-p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml`. Do not add the traffic overlay. The guarded `live-init` role creates bound schemas and bootstrap credentials without fetching, creating or linking remote records. The live worker requires the `live-worker` profile, and source-record setup is separate from reconciliation.

The live API, explicit CLI and optional worker alone read the separate `vendor-secret` named volume at `/run/secrets/vendor`. An explicit `credentials` service under the `tools` profile imports only the two access tokens through JSON stdin. It runs as UID10001 with no network and mounts only that credential volume read/write at `/var/lib/support-bridge`; that mount is not the business `app-data` volume. The importer protects its directory as `0700` and token files as `0400`. The OAuth identifier and client secret remain on the private issuance host and never enter runtime mounts.

These files are plaintext protected by ownership, reader mounts and Docker access controls, not encrypted Docker Secrets or a secret manager. Existing processes load token values into their environment at startup. Reimport rotated tokens and recreate credential-reading containers before resuming their use. The [live guide](LIVE_DEMO.md) supplies the private import procedure and separates local checks, read-only smoke and actual vendor effects.

The commands below use the default project unless a traffic prefix is shown. Do not omit `-p` and rely on a shell's `COMPOSE_PROJECT_NAME` when selecting an existing deployment. The native PHP CLI and [native monitoring helper](../monitoring/README.md) use filesystem state outside these Compose volumes.

Published ports bind to host loopback. `localhost` inside Grafana is not the Prometheus container. API service health is liveness, not proof of vendor compatibility or readiness of every pair. The PHP development server listens on `0.0.0.0:8080` inside its container, with only the selected loopback host port published. It remains a development server.

The stack uses a standard bridge network, which permits outbound connections. Local mode and the absence of provider credentials prevent default vendor operations; the network is not an egress firewall. An internal-only Docker network can prevent host port publishing on Docker Desktop, so we enforce the host access boundary with explicit loopback port bindings.

Worker health checks a recent loop heartbeat, including an iteration that encountered lock contention. Conflicts, approval waits and dead work remain business outcomes to inspect; a healthy worker does not mean every pair converged. `BRIDGE_SWEEP_INTERVAL` defaults to 60 seconds in the default stack and accepts 1–3600. The traffic overlay fixes its worker interval at five seconds. Set `BRIDGE_SWEEP_INTERVAL` before starting the default project if a different local polling cadence is needed; it does not change the traffic overlay's worker interval.

Do not run another application or the native monitoring helper on these ports. Stop only an owned native stack with `python3 monitoring/stack.py down`, or choose other host ports. In PowerShell:

```powershell
$env:BRIDGE_HTTP_PORT = '8083'
$env:BRIDGE_PROMETHEUS_PORT = '9093'
$env:BRIDGE_GRAFANA_PORT = '3003'
docker compose -p support-bridge -f compose.yaml up --build -d --wait
```

This example changes only the default project. Traffic uses `BRIDGE_TRAFFIC_HTTP_PORT`, `BRIDGE_TRAFFIC_PROMETHEUS_PORT` and `BRIDGE_TRAFFIC_GRAFANA_PORT` instead; live uses the corresponding `BRIDGE_LIVE_*` names. Choose unused ports: traffic defaults occupy 8081/9091/3001 and live defaults occupy 8082/9092/3002 when those projects run. Confirm an actual binding with `docker compose -p support-bridge -f compose.yaml port grafana 3000`, or the complete prefix for the intended other project.

In bash, export the same variables before running Compose. They change published host ports, not internal service addresses. Docker Desktop must use its Linux-container engine. On Windows, run these commands from a shell where `docker version` and `docker compose version` can contact that engine; a WSL distro also needs Docker Desktop integration if it is to use the Linux `docker` command directly.

## Operate the workflow

The CLI service uses the same application image, named state volume and canonical lock as the API and scheduled worker:

```bash
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli status
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli inspect escalation-1
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli local-edit zendesk 501 title 'Export fails above 100k rows'
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli sync
```

The background worker discovers edits on its next sweep. A manual sweep can return worker contention if another operation holds the lock; inspect status after it completes. Never scale the worker or API against this SQLite volume across hosts. The worker interval is a scheduler delay, not a convergence service-level objective.

State changes keep the same approval boundary:

```bash
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli local-edit zendesk 501 state closed
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli sync
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli inspect escalation-1
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli approve escalation-1 PLAN_HASH 'Reviewed the escalation and agreed this resolution'
docker compose -p support-bridge -f compose.yaml run --rm --no-deps cli sync
```

Replace `PLAN_HASH` with the inspected hash after reviewing the entire plan. An approval wait is an operator-attention result; CLI exit code 2 is expected. The next eligible sweep applies an approved plan only if its evidence remains valid. Neither startup nor the worker grants approval. Never rely on AI in compliance; containerization does not expand the workflow's authority or establish verified human approval.

## Continuous synthetic traffic

The opt-in [traffic stack](TRAFFIC.md) adds a generator to a separate Compose project, with its own volumes and loopback ports. Start it with both files:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
```

It needs Compose 2.24.4 or later for the overlay's `!override` port replacement. The default cadence is five seconds; one-second and random delays are available. Keep the explicit `-p support-bridge-traffic` flag and both `-f` arguments for every traffic command. The project flag overrides an ambient `COMPOSE_PROJECT_NAME`, keeping traffic volumes and its network separate from the ordinary stack.

## Credentials and isolation

The initializer generates separate operator and metrics tokens, a Grafana admin password and Grafana secret key. They are persisted in dedicated named volumes rather than copied into images, source, Compose environment values or build arguments.

| Credential volume | Readers |
| --- | --- |
| `operator-secret` | API and explicit operator tooling; the opt-in synthetic traffic generator |
| `metrics-secret` | API, Prometheus and explicit operator tooling |
| `grafana-secret` | Grafana |
| `vendor-secret` (explicit live project only) | API, explicit CLI and opt-in live worker; the explicit network-isolated importer writes it |

The initializer writes the three bootstrap credential volumes; the separate explicit importer writes the live vendor volume. Smoke reads the operator, metrics and Grafana bootstrap credentials to exercise their authorized paths, without receiving vendor tokens. The worker needs no operator, scrape or Grafana credential. Long-running consumers mount their required credential volumes read-only. Sharing separate volumes constrains which containers receive which files; it is not encrypted Docker Secrets or a secret-management service. Docker administrators can inspect volumes and container processes. Protect access to the Docker engine.

The traffic generator receives only its isolated project's operator credential. Its scripted mode calls the normal review endpoints for its synthetic pairs; it does not provide human review. It refuses live mode. This is a deterministic simulation, not an AI agent or permission to share a live operator credential.

The live overlay adds `github-token` and `zendesk-token` inside its separate `vendor-secret` volume to API, CLI and the opt-in worker through read-only mounts. The explicit importer receives those values through bounded JSON stdin, mounts only this volume read/write and cannot reach vendors or business state. Initialization, smoke, Prometheus and Grafana receive no vendor mounts; no source setup actor receives a bridge approval capability. The ordinary worker still needs no operator, scrape or Grafana credential; a live worker additionally needs the two vendor access tokens. [Live credential operation](LIVE_DEMO.md#credentials-and-manual-oauth-issuance) covers issuance, import, expiry and recreation of readers after rotation. Do not put token values in Compose environment values, command arguments, source or logs.

A fresh Grafana database starts with username `admin`. Read the bootstrap password from the project matching the browser URL. For the default stack on port 3000:

```bash
docker compose -p support-bridge -f compose.yaml exec grafana cat /run/secrets/grafana/password
```

For the traffic stack on port 3001:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml exec grafana cat /run/secrets/grafana/password
```

Do not paste that value into logs, screenshots or source. The password file initializes a new Grafana database; changing a file alone does not rotate an existing account. Operator and scrape credentials must remain different. An agent must not receive the broad operator credential.

Account changes persist in Grafana's database and can differ from the bootstrap username/password file. Verify login in the browser against the intended stack and port after rotation. The standard smoke verifier uses `admin` and the bootstrap password, so it does not validate rotated accounts. Preserve the existing account and data when adapting deployment checks.

Runtime containers use non-root users, read-only root filesystems where supported, dropped capabilities and no-new-privileges. Writable paths are named data volumes or temporary filesystems. The stack mounts neither the Docker socket nor the host source tree. Prometheus has no separate user login in this local configuration, so host users able to reach its published port can query aggregate metrics. Grafana signup, anonymous access, analytics and automatic update/plugin checks are disabled.

## Data and lifecycle

The projects are named `support-bridge`, `support-bridge-traffic` and, when explicitly selected, `support-bridge-live-demo`. Each project's named volumes retain application state, separate aggregate metrics, bootstrap credentials, Prometheus time series and Grafana state. The live project additionally retains its imported access tokens in `vendor-secret`; OAuth issuance inputs remain private host files. These are distinct from the native helper's directory and the bare PHP CLI's `var/local`; startup does not import either automatically.

```bash
docker compose -p support-bridge -f compose.yaml logs --tail=100 api worker prometheus grafana
docker compose -p support-bridge -f compose.yaml stop
docker compose -p support-bridge -f compose.yaml start
docker compose -p support-bridge -f compose.yaml down
```

`stop`, `start` and ordinary `down` preserve named volumes. Do not request volume removal as a routine stop: it discards baselines, pending plans, approvals, audit history, metrics, credentials and monitoring data. Rebuilding an image also does not reset the state. Keep the same project name when reusing volumes.

Back up SQLite consistently with its WAL, or stop all application writers before taking a volume snapshot. Protect credential and data backups. Restoring a mixture of unrelated snapshots is not a tested recovery procedure. A single local volume does not provide high availability or a distributed lock.

## Verification

Run the complete PHP, OpenAPI and offline replay gate inside the verification image:

```bash
docker compose -p support-bridge -f compose.yaml --profile test run --build --rm verify
```

This uses isolated temporary synthetic state, not the running application's volume. The verification image contains Composer, locked PHP_CodeSniffer dependencies, the style ruleset, PHP tests, locked Node/OpenAPI tooling and dependency-free viewer checks. Composer installs development dependencies with scripts and plugins disabled. The runtime image contains none of that development `vendor/` tree, Composer or Node/npm; application autoloading remains independent of it. Offline OAuth/bootstrap/replay checks use controlled local inputs and do not contact vendors.

Entry scripts delegate to CLI, HTTP or runtime objects. Those objects use the same typed command/query handlers and per-context injected repository connection. The existing shared application volume and file lock still coordinate API, CLI and worker; object-level connection reuse does not add support for multiple hosts.

With the default stack already running, exercise its actual service network:

```bash
docker compose -p support-bridge -f compose.yaml --profile test run --rm --no-deps smoke
```

For an already-running traffic stack, use:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml --profile test run --rm --no-deps smoke
```

`--no-deps` checks existing services without starting or recreating their dependencies. Both commands assume the bootstrap Grafana account; a renamed account or changed password needs a check using the current credentials, without resetting its database.

The smoke verifier has a shared 45-second deadline. Its nine read-only check groups cover API liveness, local Swagger assets/OpenAPI, operator-versus-scrape authentication, inspection, private-path denial, exporter histogram/label consistency, real Prometheus scrapes/queries, four loaded rules and Grafana datasource/dashboard provisioning. It performs no operator or provider writes and prints no credential values or record snapshots. A successful HTTP sweep alone would not establish that every pair converged; inspect the business outcomes separately.

Use the selected project's `ps` command and service logs to diagnose failed health dependencies. Smoke requires at least one explicit linked pair: ordinary local startup supplies the sample, while live bootstrap deliberately supplies none. After a verified live mapping exists, use [the live smoke command](LIVE_DEMO.md#monitoring-and-verification); it does not start the worker or contact providers. Legitimate pair statuses such as `awaiting_approval` remain visible rather than being treated as an infrastructure failure. Passing container checks does not validate vendor accounts, customer policies, production throughput, notification delivery or backup recovery.

On a shell with `sh` and the Docker CLI, `sh docker/check-lifecycle.sh` checks graceful worker shutdown and pending-plan/credential persistence through complete container recreation. It creates a separate, uniquely named synthetic project on ports 18080/19090/13000 and removes only that project's disposable volumes when finished. It never approves the pending plan or touches the normal stack's data. Override `BRIDGE_LIFECYCLE_HTTP_PORT`, `BRIDGE_LIFECYCLE_PROMETHEUS_PORT` and `BRIDGE_LIFECYCLE_GRAFANA_PORT` if those test ports are occupied. CI runs this check as well as the full suite and read-only smoke checks.

For live-overlay changes, also run `sh docker/check-live-demo.sh`. This disposable no-vendor check exercises credential import and guarded bootstrap with controlled test credentials, separate from actual account authentication or reconciliation. It must use its own project/volumes and leave all normal projects intact. Record its executed result separately; a checked-in script or passing local fixture does not prove live synchronization.

## Scope and trade-offs

We package the same deterministic engine behind the API, CLI and worker; Docker does not introduce a second merge or approval implementation. Separate services keep monitoring and scheduling independent, at the cost of more images and persistent volumes.

The default Compose file is a local deployment with synthetic providers. It explicitly selects local mode, so an ambient `.env` cannot silently enable live synchronization. The separate live overlay makes account identity, credentials and scheduling an explicit choice while retaining the same engine and approval gates. Broader live deployment still needs reviewed data permissions, an appropriate PHP server, TLS/ingress, credential rotation, operational ownership and sandbox evidence. Those controls are not supplied merely by running a container.

Our [governance model](GOVERNANCE.md), [assumptions](ASSUMPTIONS.md) and [observability semantics](OBSERVABILITY.md) remain authoritative. The [native monitoring helper](../monitoring/README.md) is an optional alternative for environments that deliberately run standalone Linux binaries.
