# Prometheus and Grafana observability

Support Bridge exposes Prometheus text metrics from framework-free PHP. Prometheus pulls the authenticated `/metrics` endpoint, and Grafana queries Prometheus. Normal reconciliation/exporting requires no PHP extension agent, Pushgateway, remote write or outbound telemetry request. The optional demonstration capture tool can query local Prometheus to attach observed aggregate values to a checkpoint.

## Open the right monitoring stack

Use the traffic stack to watch continuous synthetic activity. Its Grafana is on **3001**. The default Compose project uses **3000**; the explicitly configured live demo uses **3002**. Each has its own records, time series and login database.

| Service | Traffic project: `support-bridge-traffic` | Default project: `support-bridge` | Explicit live demo: `support-bridge-live-demo` |
| --- | --- | --- | --- |
| API and Swagger | [127.0.0.1:8081/docs](http://127.0.0.1:8081/docs) | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) | [127.0.0.1:8082/docs](http://127.0.0.1:8082/docs) |
| Prometheus | [127.0.0.1:9091](http://127.0.0.1:9091/) | [127.0.0.1:9090](http://127.0.0.1:9090/) | [127.0.0.1:9092](http://127.0.0.1:9092/) |
| Grafana dashboard | [127.0.0.1:3001](http://127.0.0.1:3001/d/supportbridge-overview) | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) | [127.0.0.1:3002](http://127.0.0.1:3002/d/supportbridge-overview) |

These are default published host ports; an environment override can change them. Confirm the selected project's `ps` output and use its matching credentials. See the [canonical stack/port guide](DOCKER.md#choose-a-stack-and-its-ports), [traffic operation](TRAFFIC.md), [live demonstration](LIVE_DEMO.md) and [Docker credentials](DOCKER.md#credentials-and-isolation). The optional native helper below is another deployment with separate private state and its own default ports.

## Metrics and meaning

`Bridge\Observability\Prometheus` consumes the existing reconciliation logger's `event`, `status` and `duration_ms` fields. Unknown events/outcomes and invalid durations are ignored. It never exports titles, IDs, request IDs, tokens, headers, exception messages or arbitrary log fields. The only labels are fixed outcome/status values and histogram boundaries.

| Metric | Type and meaning |
| --- | --- |
| `support_bridge_reconciliations_total{outcome}` | Cumulative observed reconciliations, initialized to zero for `synced`, `retry`, `conflict`, `dead`, `skipped`, `awaiting_approval` |
| `support_bridge_reconciliation_duration_seconds` | Aggregate classic histogram of per-pair reconciliation duration, converted from milliseconds to seconds |
| `support_bridge_pairs{status}` | Current stored pair counts for `new`, `synced`, `retry`, `conflict`, `dead`, `awaiting_approval` |
| `support_bridge_pending_plans` | Current durable plans awaiting convergence |
| `support_bridge_last_success_timestamp_seconds` | Latest observed convergence as Unix seconds; zero when none exists |
| `support_bridge_telemetry_storage_available` | One when this successful scrape read valid durable metric storage |

The histogram exports cumulative `_bucket` samples with inclusive boundaries `0.005`, `0.01`, `0.025`, `0.05`, `0.1`, `0.25`, `0.5`, `1`, `2.5`, `5`, `10`, `+Inf`, plus `_sum` and `_count`. The `+Inf` bucket equals `_count`. This follows the [Prometheus exposition format](https://prometheus.io/docs/instrumenting/exposition_formats/) and [metric type conventions](https://prometheus.io/docs/concepts/metric_types/). We use classic buckets as a small, inspectable implementation; their boundaries are not customer service objectives.

An observation is an attempted reconciliation, including successful polls that require no writes. These counters do not count business edits or prove remote effects. Ineligible pairs skipped by the engine appear in sweep counts; they do not currently emit individual reconciliation observations, so the initialized `skipped` telemetry series normally stays zero. The input's current `attempts` field is not accumulated as a misleading retry total; retry outcomes have their own counter.

`last_success` is the latest success across the configured link set, not the age of every pair. A recent success can coexist with failed work, so inspect status gauges and authenticated operator state together.

`awaiting_approval` records entry into the deterministic state-change gate. Subsequent scheduled sweeps skip pending reviews, so the counter is not approval wait time or an approval count. Its state gauge contributes to the Grafana operator-attention panel and example rule. Inspect the plan and independently review it; no metric, AI explanation or alert can grant approval or establish compliance. Approval rationale/hash and shared-operator audit evidence remain in authenticated local state, never metric labels.

## Persistence and failure isolation

Telemetry schema v2 adds the fixed `awaiting_approval` outcome. Upgrade first validates every historical v1 counter/histogram row, then adds only the genuinely new zero series and updates the schema version in one transaction. It neither resets prior observations nor repairs missing historical rows. A damaged v1 registry or missing row in v2 fails scraping. Back up consistently before upgrading; rollback to older code against the upgraded registry is unsupported.

The observer stores aggregate values in `metrics.sqlite` inside the configured `BRIDGE_HOME`, separate from the business database. Counters and histogram values survive CLI exit, HTTP process restarts and Prometheus restarts. The two databases should be backed up and reset deliberately; deleting the metrics database resets its counters. Use a dedicated state directory for each deployment/account configuration.

Each accepted observation increments its outcome, all matching buckets, histogram count and sum in one SQLite transaction. WAL, full synchronization, and a bounded one-second SQLite lock wait serialize writers. A failed update rolls back the whole observation and marks that observer instance unavailable; it does not interrupt reconciliation or modify its business result. A new process/request can reconnect after the cause is fixed. This does not add support for concurrent business workers, which still use their existing single-worker lock.

Metric delivery is best effort. The business update and telemetry update are in separate databases and are not one atomic transaction. A process crash between them can omit an observation; replayed application callbacks can count twice. These metrics are operational signals, not an exactly-once audit or billing record. The business store and its audit remain authoritative.

The exporter reads all aggregate values in one SQLite snapshot and checks histogram/counter consistency. Only first creation initializes zero series. An existing registry with a missing row or incompatible schema fails visibly instead of silently rebuilding values; restore it from a consistent backup or deliberately reset it after reviewing the loss. If metric storage cannot be opened or read, `/metrics` returns a sanitized failure rather than fabricated zero counters. Prometheus then reports its own scrape metric `up{job="support_bridge"}=0`. The storage gauge is not a provider-health check. Business gauges and telemetry totals come from separate local snapshots, and no vendor is contacted during a scrape.

## Docker Compose stack

Our local stacks package the API, reconciliation worker, Prometheus and Grafana in containers. For continuous activity on ports 8081/9091/3001:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml ps
```

For the default stack on ports 8080/9090/3000:

```bash
docker compose -p support-bridge -f compose.yaml up --build -d --wait
docker compose -p support-bridge -f compose.yaml ps
```

See [Docker operation](DOCKER.md) for credentials, volumes and the complete container verification gate. Use the same project and Compose files for every operation. Against an already running default stack, `docker compose -p support-bridge -f compose.yaml --profile test run --rm --no-deps smoke` checks actual service connections without recreating dependencies. For traffic, use the traffic prefix shown above. The verifier assumes Grafana's bootstrap account; it cannot validate a renamed account or rotated password, as explained in the [traffic login guide](TRAFFIC.md).

Prometheus scrapes `api:8080` through Compose DNS with its own read-only credential file; Grafana queries `prometheus:9090`. Host ports are bound to loopback. In local projects, the initializer creates synthetic application state and distinct credentials in dedicated named volumes, while API and worker share the same state directory and canonical database lock. The worker does not receive monitoring or operator credentials and never grants approval.

The explicit [live overlay](LIVE_DEMO.md) retains this monitoring topology on host ports 9092/3002. Its initializer creates no fixture or mapping, and its worker is disabled by default. Prometheus, Grafana and smoke receive no `vendor-secret` volume or vendor token values; scraping never calls GitHub or Zendesk. The separate network-isolated importer writes only that credential volume, while application readers mount it read-only. Read-only smoke requires an explicit existing link and still assumes Grafana's bootstrap account. Successful scrapes prove exporter access, not a completed vendor integration.

The [traffic stack](TRAFFIC.md) produces ongoing synthetic activity in its own project. Its one-second, five-second or random tick delay is independent of the five-second Prometheus scrape and Grafana refresh; multiple edits can contribute to one scrape. Scripted review is synthetic operator activity, not a metric-derived or human approval.

Container restart preserves the business database, aggregate metrics, monitoring data and credentials. An ordinary `docker compose down` retains the volumes. Credentials are not baked into images or included in committed configuration. The volume-backed files are not encrypted Docker Secrets; Docker administrators can read them. The isolated smoke verifier reads the credentials needed to check actual authentication, scrapes, queries, rules and Grafana provisioning, and makes no business writes.

## Recorded checkpoint observations

`bin/capture-demo.php` can attach four fixed aggregate observations to a private checkpoint: exporter `up`, pair-state counts, pending-plan count and cumulative reconciliations. It makes bounded GET queries to the selected Prometheus origin without operator/vendor headers or redirects. The browser-facing monitoring links require loopback HTTP; the exact Compose host `prometheus` is allowed only for the internal query address.

An unavailable or malformed query causes telemetry to be omitted, not replaced with zeros. The [offline viewer](../tools/demo-viewer/README.md) displays absent samples as not captured. Capture does not infer metrics from the narrative, change business state or supply review decisions. The provider snapshot and local inspection are collected separately from Prometheus samples, with no shared atomic timestamp or guaranteed scrape alignment.

Replay controls navigate embedded observations only. Their Grafana/Prometheus links open the current deployment, which may differ from the recorded stage. Next or Play does not make dashboards replay historical samples or execute a synchronization. Keep detailed record evidence in private artifacts, because it can contain titles and tenant links even though those values are excluded from aggregate metric labels. [LIVE_DEMO.md](LIVE_DEMO.md#capture-and-render-actual-observations) supplies the capture and render commands.

## Optional native stack

The repository includes pinned, checksum-verified user-local binaries, provisioning, a dashboard and example alert rules under [`monitoring/`](../monitoring/). From the repository root in Ubuntu/WSL:

```bash
bash monitoring/install.sh
python3 monitoring/stack.py up --demo
python3 monitoring/stack.py verify
python3 monitoring/stack.py status
```

`up --demo` starts an isolated local PHP application with synthetic records and private credentials, Prometheus on `127.0.0.1:9090`, and Grafana on `127.0.0.1:3000`. It does not use live provider credentials. The helper refuses to take over occupied service ports. Default private state is `~/.local/share/supportbridge/monitoring/`; `BRIDGE_MONITORING_HOME` can select another directory. `status` reports file locations without printing credential values. Read the Grafana password from its private file when signing in locally; do not paste it into logs or documentation.

For this native helper, open [native Prometheus](http://127.0.0.1:9090/) and the provisioned [native Grafana overview](http://127.0.0.1:3000/d/supportbridge-overview). The local verification checks real scrape/query behavior, Grafana datasource health and dashboard provisioning. Installation or PHP tests alone do not prove those connections are working. Record what `verify` actually reports rather than claiming success from configuration files alone.

Stop only the processes owned by the helper:

```bash
python3 monitoring/stack.py down
```

The stack retains its private data and credentials across restarts. It provisions local example alert evaluation; it configures no Alertmanager, recipients or external notification delivery. Thresholds are examples for a pilot, not agreed SLAs. Prometheus retains its scraped time series independently of the PHP aggregate database.

## Scrape authentication and deployment

The operator API uses `BRIDGE_API_TOKEN`. The read-only `/metrics` endpoint uses a different, strong `BRIDGE_METRICS_TOKEN`; neither token grants the other's access. Swagger serves reference documentation without embedding either credential. Keep the scrape token in protected deployment configuration and configure Prometheus with an authorization credentials file, as in [`monitoring/prometheus.yml.in`](../monitoring/prometheus.yml.in). Prometheus supports [file-based bearer authorization](https://prometheus.io/docs/prometheus/latest/configuration/configuration/); do not place the token in a URL or committed configuration.

The demonstration binds to loopback. Production deployment needs a suitable PHP server, TLS/reverse-proxy protection, monitoring access restrictions and a deliberate credential lifecycle. Prometheus and Grafana are additional privileged readers of operational state: protect their data directories and network access. The exporter has no automatic request-header, SQL, exception-body or application-log forwarding. Local structured logs and authenticated inspection remain available for detailed diagnosis.

## PromQL examples

Observed outcomes per second, accounting for counter resets:

```promql
sum by (outcome) (rate(support_bridge_reconciliations_total[5m]))
```

Approximate 95th-percentile reconciliation duration in seconds:

```promql
histogram_quantile(0.95,
  sum by (le) (rate(support_bridge_reconciliation_duration_seconds_bucket[5m])))
```

Average observed duration in seconds:

```promql
sum(rate(support_bridge_reconciliation_duration_seconds_sum[5m]))
/
sum(rate(support_bridge_reconciliation_duration_seconds_count[5m]))
```

Current work requiring attention and scrape health:

```promql
sum(support_bridge_pairs{status=~"conflict|dead|awaiting_approval"})
up{job="support_bridge"}
```

Rates require several scrapes and observations; a fresh or inactive demo may have no rate/quantile data. Quantiles are bucket estimates, and no observations can produce `NaN`; do not replace that with an invented latency. Use the raw counters and gauges to inspect a newly started demo. See the official [query functions](https://prometheus.io/docs/prometheus/latest/querying/functions/) and [histogram guidance](https://prometheus.io/docs/practices/histograms/) for interpretation.

## Verification and review

```bash
php tests/observability.php
```

The offline suite checks initialized series, real cross-process durability, concurrent updates, inclusive bucket units, atomic rollback, bounded lock failure, corruption handling, fixed cardinality and text formatting. API/server checks cover authentication, response media type and scrape failures. The Compose smoke verifier and optional native monitoring helper verify actual Prometheus/Grafana connections separately. Neither passing PHP checks nor healthy containers alone prove an authenticated scrape and datasource query.

We use backend and security review guidance to challenge metric meaning, trust boundaries, cardinality and failure isolation. Reconciliation observations are operational counts, not measured customer impact. Review profiles establish no scale, availability, compliance or delivery guarantee. We need to revisit the decisions and limitations with actual customer traffic before a pilot.
