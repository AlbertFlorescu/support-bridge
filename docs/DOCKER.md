# Docker operation

We run the application, its reconciliation worker, Prometheus and Grafana with Docker Compose. The default stack uses synthetic local records and makes no GitHub or Zendesk requests. It needs Docker Engine with Linux containers and the Compose plugin. The runtime image contains PHP and application classes; the verification image adds Composer development tools and Node for style and API checks. Monitoring runs in its own images.

## Start the stack

Run from the repository root:

```bash
docker compose up --build -d --wait
docker compose ps
```

The one-shot initializer creates the local fixture and generates credentials only when they are absent. Successful initialization precedes API/worker startup. Health dependencies wait for the services required by monitoring. Later starts retain the existing data and credentials.

| Service | Host address | Role |
| --- | --- | --- |
| API and Swagger | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) | Operator HTTP surface and separate authenticated exporter |
| Prometheus | [127.0.0.1:9090](http://127.0.0.1:9090/) | Scrapes `api:8080` using its metrics credential; retains local time series |
| Grafana | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) | Queries `prometheus:9090`; provisions the existing eight-panel dashboard |
| Worker | No published port | Runs an immediate bounded sweep, then repeats at the configured interval |

Published ports bind to host loopback. Services use Compose DNS names inside the network; `localhost` inside Grafana is not the Prometheus container. API service health is liveness, not proof of vendor compatibility or readiness of every pair. The PHP development server listens on `0.0.0.0` inside its container, with only the documented loopback port exposed on the host. It remains a development server.

The stack uses a standard bridge network, which permits outbound connections. Local mode and the absence of provider credentials prevent default vendor operations; the network is not an egress firewall. An internal-only Docker network can prevent host port publishing on Docker Desktop, so we enforce the host access boundary with explicit loopback port bindings.

Worker health checks a recent loop heartbeat, including an iteration that encountered lock contention. Conflicts, approval waits and dead work remain business outcomes to inspect; a healthy worker does not mean every pair converged. `BRIDGE_SWEEP_INTERVAL` defaults to 60 seconds and accepts 1–3600. Set it before startup if a different local polling cadence is needed.

Do not run another application or the native monitoring helper on these ports. Stop only an owned native stack with `python3 monitoring/stack.py down`, or choose other host ports. In PowerShell:

```powershell
$env:BRIDGE_HTTP_PORT = '8081'
$env:BRIDGE_PROMETHEUS_PORT = '9091'
$env:BRIDGE_GRAFANA_PORT = '3001'
docker compose up --build -d --wait
```

In bash, export the same variables before running Compose. They change published host ports, not internal service addresses. Docker Desktop must use its Linux-container engine. On Windows, run these commands from a shell where `docker version` and `docker compose version` can contact that engine; a WSL distro also needs Docker Desktop integration if it is to use the Linux `docker` command directly.

## Operate the workflow

The CLI service uses the same application image, named state volume and canonical lock as the API and scheduled worker:

```bash
docker compose run --rm cli status
docker compose run --rm cli inspect escalation-1
docker compose run --rm cli local-edit zendesk 501 title 'Export fails above 100k rows'
docker compose run --rm cli sync
```

The background worker discovers edits on its next sweep. A manual sweep can return worker contention if another operation holds the lock; inspect status after it completes. Never scale the worker or API against this SQLite volume across hosts. The worker interval is a scheduler delay, not a convergence service-level objective.

State changes keep the same approval boundary:

```bash
docker compose run --rm cli local-edit zendesk 501 state closed
docker compose run --rm cli sync
docker compose run --rm cli inspect escalation-1
docker compose run --rm cli approve escalation-1 PLAN_HASH 'Reviewed the escalation and agreed this resolution'
docker compose run --rm cli sync
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

The initializer writes each volume, and the explicit smoke verifier reads all three to exercise their authorized paths. The worker needs no operator, scrape or Grafana credential. Long-running consumers mount their required credential volumes read-only. Sharing separate volumes constrains which containers receive which files; it is not encrypted Docker Secrets or a secret-management service. Docker administrators can inspect volumes and container processes. Protect access to the Docker engine.

The traffic generator receives only its isolated project's operator credential. Its scripted mode calls the normal review endpoints for its synthetic pairs; it does not provide human review. It refuses live mode. This is a deterministic simulation, not an AI agent or permission to share a live operator credential.

Grafana login is `admin`. View its generated password locally when signing in:

```bash
docker compose exec grafana cat /run/secrets/grafana/password
```

Do not paste that value into logs, screenshots or source. The password file initializes a new Grafana database; changing a file alone does not rotate an existing account. Operator and scrape credentials must remain different. An agent must not receive the broad operator credential.

Account changes persist in Grafana's database and can differ from the bootstrap username/password file. Verify login in the browser against the intended stack and port after rotation. The standard smoke verifier uses `admin` and the bootstrap password, so it does not validate rotated accounts. Preserve the existing account and data when adapting deployment checks.

Runtime containers use non-root users, read-only root filesystems where supported, dropped capabilities and no-new-privileges. Writable paths are named data volumes or temporary filesystems. The stack mounts neither the Docker socket nor the host source tree. Prometheus has no separate user login in this local configuration, so host users able to reach its published port can query aggregate metrics. Grafana signup, anonymous access, analytics and automatic update/plugin checks are disabled.

## Data and lifecycle

The Compose project is named `support-bridge`. Its named volumes retain application state, separate aggregate metrics, credentials, Prometheus time series and Grafana state. These are distinct from the native helper's directory and the bare PHP CLI's `var/local`; startup does not import either automatically.

```bash
docker compose logs --tail=100 api worker prometheus grafana
docker compose stop
docker compose start
docker compose down
```

`stop`, `start` and ordinary `down` preserve named volumes. Do not request volume removal as a routine stop: it discards baselines, pending plans, approvals, audit history, metrics, credentials and monitoring data. Rebuilding an image also does not reset the state. Keep the same project name when reusing volumes.

Back up SQLite consistently with its WAL, or stop all application writers before taking a volume snapshot. Protect credential and data backups. Restoring a mixture of unrelated snapshots is not a tested recovery procedure. A single local volume does not provide high availability or a distributed lock.

## Verification

Run the complete PHP and OpenAPI gate inside the verification image:

```bash
docker compose --profile test run --build --rm verify
```

This uses isolated temporary synthetic state, not the running application's volume. The verification image contains Composer, locked PHP_CodeSniffer dependencies, the style ruleset, PHP tests and the locked Node/OpenAPI tooling. Composer installs development dependencies with scripts and plugins disabled. The runtime image contains none of that development `vendor/` tree, Composer or Node/npm; application autoloading remains independent of it.

Entry scripts delegate to CLI, HTTP or runtime objects. Those objects use the same typed command/query handlers and per-context injected repository connection. The existing shared application volume and file lock still coordinate API, CLI and worker; object-level connection reuse does not add support for multiple hosts.

With the stack running, exercise the actual service network:

```bash
docker compose --profile test run --build --rm smoke
```

The smoke verifier has a shared 45-second deadline. Its nine read-only check groups cover API liveness, local Swagger assets/OpenAPI, operator-versus-scrape authentication, inspection, private-path denial, exporter histogram/label consistency, real Prometheus scrapes/queries, four loaded rules and Grafana datasource/dashboard provisioning. It performs no operator or provider writes and prints no credential values or record snapshots. A successful HTTP sweep alone would not establish that every pair converged; inspect the business outcomes separately.

Use `docker compose ps` and service logs to diagnose failed health dependencies. The smoke command requires the initialized local fixture, while legitimate pair statuses such as `awaiting_approval` remain visible rather than being treated as an infrastructure failure. Passing container checks does not validate vendor accounts, customer policies, production throughput, notification delivery or backup recovery.

On a shell with `sh` and the Docker CLI, `sh docker/check-lifecycle.sh` checks graceful worker shutdown and pending-plan/credential persistence through complete container recreation. It creates a separate, uniquely named synthetic project on ports 18080/19090/13000 and removes only that project's disposable volumes when finished. It never approves the pending plan or touches the normal stack's data. Override `BRIDGE_LIFECYCLE_HTTP_PORT`, `BRIDGE_LIFECYCLE_PROMETHEUS_PORT` and `BRIDGE_LIFECYCLE_GRAFANA_PORT` if those test ports are occupied. CI runs this check as well as the full suite and read-only smoke checks.

## Scope and trade-offs

We package the same deterministic engine behind the API, CLI and worker; Docker does not introduce a second merge or approval implementation. Separate services keep monitoring and scheduling independent, at the cost of more images and persistent volumes.

The default Compose file is a local deployment with synthetic providers. It explicitly selects local mode, so an ambient `.env` cannot silently enable live synchronization. Live deployment needs reviewed provider identities/credentials, data permissions, an appropriate PHP server, TLS/ingress, credential rotation, operational ownership and sandbox evidence. Those controls are not supplied merely by running a container.

Our [governance model](GOVERNANCE.md), [assumptions](ASSUMPTIONS.md) and [observability semantics](OBSERVABILITY.md) remain authoritative. The [native monitoring helper](../monitoring/README.md) is an optional alternative for environments that deliberately run standalone Linux binaries.
