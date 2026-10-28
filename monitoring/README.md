# Local Prometheus and Grafana

We run the application and monitoring together with Docker Compose; scheduled workers run where enabled. Choose one deployment's links and credentials:

| Service | Continuous traffic: `support-bridge-traffic` | Default Compose: `support-bridge` | Explicit live demo: `support-bridge-live-demo` |
| --- | --- | --- | --- |
| API and Swagger | [127.0.0.1:8081/docs](http://127.0.0.1:8081/docs) | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) | [127.0.0.1:8082/docs](http://127.0.0.1:8082/docs) |
| Prometheus | [127.0.0.1:9091](http://127.0.0.1:9091/) | [127.0.0.1:9090](http://127.0.0.1:9090/) | [127.0.0.1:9092](http://127.0.0.1:9092/) |
| Grafana dashboard | [127.0.0.1:3001](http://127.0.0.1:3001/d/supportbridge-overview) | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) | [127.0.0.1:3002](http://127.0.0.1:3002/d/supportbridge-overview) |

The traffic project's Grafana is on **3001**. Start it from the repository root with both Compose files and the explicit project:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml ps
```

For the default stack, use `docker compose -p support-bridge -f compose.yaml up --build -d --wait`. The [live demo](../docs/LIVE_DEMO.md) requires its explicit deployment configuration and keeps its worker disabled by default. Environment overrides can change published ports; check the selected project's `ps` output before opening a URL. See the [canonical stack/port guide](../docs/DOCKER.md#choose-a-stack-and-its-ports) and [traffic operation](../docs/TRAFFIC.md) for verification commands.

Follow [Grafana sign-in](../docs/DOCKER.md#sign-in-to-grafana) for the matching project's bootstrap command or persisted account. Fresh databases use `admin` and a generated password; changed accounts retain their current credentials. Compose gives each project a distinct login cookie name. Apply the updated configuration by recreating its Grafana service and signing in once again; accounts and data remain intact. Keep `127.0.0.1` consistent. When diagnosing older deployments that still share a cookie name, use independent browser sessions because cookies can cross ports. The generated password file alone does not prove the current login.

The three container projects use separate persistent data/credential volumes, an authenticated `api:8080` scrape and a provisioned datasource at `prometheus:9090`. These internal service addresses stay the same in each project's network; host-port overrides do not change them. Host ports remain loopback-only. The smoke verifier checks actual scrapes, queries, dashboard provisioning and loaded rules, assuming Grafana's bootstrap account; configuration alone is not verification.

The rest of this guide describes our optional native alternative. It is a reproducible local monitoring stack for the PHP application's authenticated `/metrics` exporter, using official standalone Linux x86_64 binaries, including on Ubuntu WSL. Docker, sudo, a cloud account and a PHP monitoring extension are not needed for that alternative. Its state and credentials are separate from Compose volumes; do not start it on ports occupied by any Compose project.

## Start and verify the native stack

From the repository root in Ubuntu/WSL:

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

For this native helper, open [the native dashboard](http://127.0.0.1:3000/d/supportbridge-overview). Its initial login is `admin`; its generated password is in the private file printed by `status`, rather than in this repository or terminal logs. Read that file locally for the initial login. After account rotation, use the credentials saved in that native Grafana database. Anonymous access and signup are disabled. Initial p95/rate panels need observations after at least two scrapes; a quiet new fixture may legitimately show an empty duration rate.

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
