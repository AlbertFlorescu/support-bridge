# Prometheus and Grafana

The normal deployment is the existing `support-bridge-live-demo` project: one API, one Prometheus and one Grafana for our three linked fictional source cases. Its background worker stays disabled, so monitoring a case does not itself advance reconciliation.

| Service | Normal host address |
| --- | --- |
| API and Swagger | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) |
| Prometheus | [127.0.0.1:9090](http://127.0.0.1:9090/) |
| Grafana dashboard | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) |

Start or inspect it from the repository root with its private env file and both Compose files:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml up --build -d --wait
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml ps
```

Keep the private file's live host-port values at 8080/9090/3000 and check actual bindings for shell overrides. [Docker operation](../docs/DOCKER.md#choose-a-stack-and-its-ports) is the connection reference; [LIVE_DEMO.md](../docs/LIVE_DEMO.md) covers token import and deliberate checkpoints. Startup neither creates source records nor links them, and authorizes no synchronization or approval. Former local and traffic projects stay stopped with volumes parked; their state is never merged into this project.

Follow [Grafana sign-in](../docs/DOCKER.md#sign-in-to-grafana) for the persisted account. Only a fresh database uses `admin` and a generated password. After rotation, the bootstrap file does not prove the current login. Use `127.0.0.1` consistently. Compose assigns a project-specific login cookie; applying that setting requires Grafana recreation and one fresh sign-in while retaining accounts and data. Independent sessions help diagnose older shared-cookie deployments. Actual browser login, health and bootstrap smoke checks establish different facts.

Prometheus scrapes authenticated `api:8080`; Grafana queries `prometheus:9090`. Internal addresses do not change with host bindings. Neither monitoring service receives vendor tokens, and scraping makes no vendor call. Smoke checks actual connections, rules and provisioning but assumes the unchanged Grafana bootstrap account; do not reset a rotated account to satisfy it.

The remainder describes an **optional isolated native verification tool** using pinned standalone Linux x86_64 binaries, including Ubuntu WSL. It is not another normal dashboard. Its state is separate from Compose. Do not start it while the real demo occupies its default ports; prefer [disposable container checks](../docs/DOCKER.md#verification). Docker, sudo, a cloud account and a PHP monitoring extension are not needed for this explicit alternative.

## Start and verify the native stack

Only in an explicitly isolated verification environment with the required ports free, from the repository root in Ubuntu/WSL:

```bash
bash monitoring/install.sh
python3 monitoring/stack.py up --demo
python3 monitoring/stack.py verify
```

The installer downloads and verifies pinned archives before extracting them. It changes no global PHP or service configuration and starts nothing. The launcher starts only loopback processes:

| Component | Address | Purpose |
| --- | --- | --- |
| Synthetic PHP app | `127.0.0.1:8080` | Isolated local fixtures and an authenticated exporter |
| Prometheus | `127.0.0.1:9090` | Scrape every five seconds, retain up to seven days locally, evaluate example rules |
| Grafana OSS | `127.0.0.1:3000` | Provisioned Prometheus datasource and eight-panel dashboard |

The helper refuses to replace an existing service on these ports. `--demo` explicitly forces local mode and uses separate synthetic application state; it does not call GitHub or Zendesk. It initializes the fixture and runs two sweeps. Those initial events populate persisted counters, not a production benchmark.

For this isolated native check, the dashboard is served at its configured Grafana address. Its initial login is `admin`; its generated password is in the private file printed by `status`, rather than in this repository or terminal logs. Read that file locally for the initial login. After account rotation, use the credentials saved in that native Grafana database. Anonymous access and signup are disabled. Initial p95/rate panels need observations after at least two scrapes; a quiet new fixture may legitimately show an empty duration rate.

```bash
python3 monitoring/stack.py status
python3 monitoring/stack.py down
```

`down` terminates only processes recorded by this launcher whose process-start identity still matches. State and time series remain available for the next run. This helper is a development launcher with no restart supervision; it is not a system service manager.

## Private state and existing applications

The default state directory is `~/.local/share/supportbridge/monitoring` (respecting `XDG_DATA_HOME`). Override it with `BRIDGE_MONITORING_HOME`. Keep it on a local filesystem. It contains private metrics/operator tokens, the Grafana admin password and secret key, generated configuration, logs, PIDs, synthetic app state, Grafana's database and Prometheus data. Directory permissions are `0700`; generated credential files are `0600`.

The Prometheus configuration references `metrics-token` through `authorization.credentials_file`; no token is committed. The metrics credential is distinct from the application's operator token and is only allowed to read `/metrics`. Rotation requires coordinating the private file and the application's environment/restart. Do not print the token into documentation, a dashboard or a command-line argument.

To monitor an already managed application instead, supply its matching metrics token in the private `metrics-token` file and run `up` without `--demo`. That application must listen on `127.0.0.1:8080` and expose the documented exporter. The helper does not change its environment or restart it. `verify` checks the actual target and fails if the credential does not match.

Grafana's generated password initializes a new database. Editing that file alone does not rotate an existing Grafana account; use Grafana's supported administration workflow. Keep the private state directory out of source control and backups appropriately protected.

## Dashboard and alerts

The datasource and dashboard are provisioned from `monitoring/grafana/`. The dashboard displays scrape health, total pairs, conflict/dead/awaiting-approval pairs, pending plans, outcome rates, histogram p95, pair-state history and seconds since the last observed convergence. It uses bounded outcome/status labels and contains no per-record titles, IDs or credentials.

`alerts.yml` supplies four examples: failed scrape, operator attention, pending recovery and no recent observed convergence. The thresholds are demonstration choices, not agreed customer service objectives. `promtool check config` validates the rendered configuration and all four rules before startup. Rules are visible in Prometheus; no Alertmanager, contact point, receiver or notification destination is configured. Grafana unified alerting is disabled in this local setup.

Counters/histograms are best-effort application observations stored separately from business state. A process can commit business work and die before recording telemetry. Snapshot gauges come from durable pair state. A globally recent success does not prove every pair is healthy; inspect operator state for individual work. A zero last-success timestamp means unknown/no observed success. Avoid interpreting empty histogram-rate results as zero latency.

## Verified versions and provenance

| Binary | Official source | SHA-256 |
| --- | --- | --- |
| Prometheus **3.13.5 LTS** | [Official downloads](https://prometheus.io/download/), [release archive](https://github.com/prometheus/prometheus/releases/download/v3.13.5/prometheus-3.13.5.linux-amd64.tar.gz) | `0388822ace519c5a3a8ef556a541ce1b536e21d872f82deded465bd27a5a0699` |
| Grafana OSS **13.2.4** | [Official version download](https://grafana.com/grafana/download/13.2.4?edition=oss&platform=linux) | `c48765988fd88572607e4ea670251af8a8e64ab3e360d0f3cf67a6f3587b92ab` |

The pinned installer supports Linux x86_64 only and requires curl, tar, sha256sum, Python 3 and sufficient disk space for the archives/extracted assets. Upgrades require reviewing a supported release and updating its version/URL/checksum together. The provisioning follows [Grafana's file provisioning](https://grafana.com/docs/grafana/latest/administration/provisioning/) and [Prometheus's configuration contract](https://prometheus.io/docs/prometheus/latest/configuration/configuration/).

On 2026-10-23, both archives were installed with their checksums verified, both real servers started, and the synthetic application was scraped successfully. The verification checked unauthenticated `/metrics` rejection, an authenticated read, target `up`, a real PromQL query, Grafana's datasource health query, the provisioned dashboard and four loaded rules. The secret-free result is retained in the private state's `verification.json`.

All listeners bind to loopback. Prometheus itself has no additional authentication in this local setup; other processes on the host can query its aggregate data. Grafana analytics, update checks and plugin preinstallation are disabled. No remote-write destination or cloud telemetry account is configured. Production use needs appropriate authentication/authorization, TLS, access controls, retention/capacity planning, backups and supervision; these checks do not validate that deployment or customer impact.
