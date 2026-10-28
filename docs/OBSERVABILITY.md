# Demo observability

One Prometheus and one Grafana monitor the three linked cases in `support-bridge-live-demo`. The dashboard reads actual stored workflow evidence. There is no traffic generator, automatic ticket creation or native monitoring alternative.

| Service | Address |
| --- | --- |
| Grafana | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) |
| Prometheus | [127.0.0.1:9090](http://127.0.0.1:9090/) |
| API and Swagger | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) |

Use the full project/env-file prefix in [Docker operation](DOCKER.md). Grafana uses its persisted account; its bootstrap file may not represent a rotated password. [Sign-in guidance](DOCKER.md#sign-in-to-grafana) separates account authentication from service health.

## Read the dashboard

| Panel | Meaning |
| --- | --- |
| Metrics connection | Prometheus can scrape the bridge. It does not check vendor credentials or current vendor state. |
| Actual linked pairs | Total stored mappings, calculated from the database rather than fixed at three. |
| Operator attention | Stored conflicts, dead work and approval waits requiring inspection. |
| Demo cases | CSV export, Webhook delivery and SSO sign-in: workflow state, last verified resolution, pending plan, failed attempts and last convergence. |
| Sampled case-state history | States sampled after these series were introduced. Missing history is not backfilled or inferred. |

The worker is disabled by default. A quiet graph is expected between manual sweeps. **Synced** describes the last observed bridge result, not a continuous vendor-health check. The convergence timestamp shows when that observation occurred. Vendor edits made later may remain undiscovered until an authorized sweep.

**Last verified resolution** comes from the common baseline, never an unapproved target. **Resolved** means canonical closed; it does not prove customer confirmation or Zendesk's immutable closed status. A pending plan is unfinished work, not approval. No panel supplies a review decision or establishes compliance.

## Fixed case projection

The exporter recognizes only these public demonstration aliases:

| Stored pair key | Exported `case` label |
| --- | --- |
| `csv-export` | `CSV export` |
| `webhook-retry` | `Webhook delivery` |
| `sso-conflict` | `SSO sign-in` |

`DemoCaseMetrics` projects decoded business-store rows without provider access, reconciliation or persistence. Each family has exactly three series. This bounded allowlist adds case-level visibility without exporting arbitrary pair keys, vendor IDs, titles, errors, credentials or approval evidence. Other mappings still contribute to aggregate totals; expanding the case table requires a reviewed allowlist change.

| Gauge, prefixed `support_bridge_demo_case_` | Meaning |
| --- | --- |
| `present` | One when the named case has a stored mapping; otherwise zero. |
| `status` | Stored workflow code, defined below. |
| `resolution` | Last verified baseline: zero unverified, one open, two canonical closed. |
| `pending_plan` | One when a durable plan exists. |
| `attempts` | Persisted failed attempts. |
| `last_success_timestamp_seconds` | Last observed convergence, or zero when never observed. |

| Status code | Display |
| ---: | --- |
| 0 | Not linked |
| 1 | Ready for sweep |
| 2 | Synced |
| 3 | Awaiting approval |
| 4 | Conflict |
| 5 | Retry scheduled |
| 6 | Needs intervention |
| 7 | Unknown state |

A missing mapping remains an explicit missing row; its zero-valued fields do not establish successful work. Unknown stored states remain unknown. Invalid numeric or baseline evidence fails the scrape rather than fabricating healthy values. Zero convergence timestamps display **Never verified**. The dashboard converts Unix seconds to milliseconds for date formatting.

## Retained diagnostic metrics

| Metric | Meaning |
| --- | --- |
| `support_bridge_pairs{status}` | Current stored counts by workflow state. |
| `support_bridge_pending_plans` | Durable plans awaiting convergence. |
| `support_bridge_last_success_timestamp_seconds` | Most recent convergence across all pairs; it can hide an older stalled pair. |
| `support_bridge_reconciliations_total{outcome}` | Observed reconciliations, including no-op polls; not a count of customer edits or remote writes. |
| `support_bridge_reconciliation_duration_seconds` | Classic duration histogram, converted from milliseconds to seconds. |
| `support_bridge_telemetry_storage_available` | This successful scrape read valid durable metric storage. |

Counters and histogram values persist in `metrics.sqlite`, separately from business state. Their update is atomic within that database, but not atomic with a business commit. A crash can omit an observation. Metrics are operational signals, not an exactly-once audit, billing record or compliance result.

Only new metric storage initializes zero series. Existing missing or corrupt rows fail visibly; schema upgrades validate and preserve prior observations. The histogram's cumulative buckets end in `+Inf`, which equals its count. Case gauges are derived at scrape time and require no metric-schema migration or historical backfill.

Business aggregates, per-case rows and telemetry totals are separate local snapshots. Scrapes make no vendor requests. A storage failure returns sanitized HTTP 503 and Prometheus reports `up=0`; the exporter does not replace failed storage with zero success counts.

Example queries:

```promql
support_bridge_demo_case_status
support_bridge_demo_case_pending_plan
sum(support_bridge_pairs)
sum by (outcome) (rate(support_bridge_reconciliations_total[5m]))
```

Rates and histogram estimates require observations; missing data is not zero latency. See the [Prometheus metric types](https://prometheus.io/docs/concepts/metric_types/) and [histogram guidance](https://prometheus.io/docs/practices/histograms/) for interpretation.

## Alerts and access

Three local rules cover an unavailable scrape, operator attention and pending durable plans. The former elapsed-convergence warning was removed because quiet time is normal for a manual demonstration. Thresholds are operating examples, not agreed service objectives. No Alertmanager or notification recipient is configured.

Prometheus reads a protected metrics token through [its Docker configuration](../docker/prometheus.yml). This token must differ from the broad operator token; neither authorizes the other's endpoint. Grafana, Prometheus and smoke receive no vendor credentials. Host ports bind to loopback, with no remote write, Pushgateway or telemetry agent.

## Verification and recorded evidence

`php tests/observability.php` checks durable counters, histogram consistency, failure isolation and case projections against actual test-store rows. API/wire checks verify authentication and endpoint behavior. [Container verification](DOCKER.md#verification) separately checks real scrapes, rules, datasource queries and dashboard provisioning. These isolated fixtures never populate the operating demo's database.

Actual deployment checks must confirm presence of the three named cases, reconcile the aggregate total with the entire link set, and verify eighteen case samples, datasource dataframes and the five-panel dashboard. An additional mapping changes the total without creating another allowed case label; inspect it rather than deleting data to fit an expected count. Check current Grafana credentials without resetting its account to satisfy the bootstrap smoke test. Report API/data validation separately from rendered-table verification.

Checkpoint capture can attach four aggregate observations to private evidence. Missing monitoring samples remain absent. [Replay controls](../tools/demo-viewer/README.md) only navigate recorded observations; playback neither feeds metrics nor edits records. The optional replay server is not part of normal operation. [LIVE_DEMO.md](LIVE_DEMO.md#capture-and-render-actual-observations) documents private capture and rendering.
