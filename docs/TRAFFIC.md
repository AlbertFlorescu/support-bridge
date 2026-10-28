# Synthetic traffic verification

The traffic configuration is an optional isolated test tool for support/engineering workflows on local ticket/issue pairs. It is not part of the one normal [real demonstration](LIVE_DEMO.md), which uses `support-bridge-live-demo` on API 8080, Prometheus 9090 and Grafana 3000 with manual sweeps and no generator. Former local/traffic deployments remain stopped with their volumes parked; test runs never import their state or live mappings.

The [primary CSV-export scenario](SCENARIO.md) explains the customer flow. This guide describes bounded synthetic verification of the same engine and API without vendor accounts. Temporary monitoring services belong only to a disposable check, not additional operating dashboards.

## Start

From a shell with Docker Engine, Compose 2.24.4 or later and `sh`, run:

```bash
sh docker/check-traffic.sh
```

The check creates a uniquely named `support-bridge-traffic-check-<process-id>` project with both `compose.yaml` and `compose.traffic.yaml`. It runs a finite 50-tick workload at one-second intervals in scripted mode, checks application/audit evidence and monitoring, exercises restart/termination, then removes only its own disposable volumes. It refuses to adopt an existing test project. Never substitute a parked or live project name.

Temporary test ports default to API 18081, Prometheus 19091 and Grafana 13001. If occupied, set `BRIDGE_TRAFFIC_CHECK_HTTP_PORT`, `BRIDGE_TRAFFIC_CHECK_PROMETHEUS_PORT` and `BRIDGE_TRAFFIC_CHECK_GRAFANA_PORT` before running the script. Internal service addresses stay `api:8080`, `prometheus:9090` and `grafana:3000`. Host listeners bind to loopback. The script does not change normal demo bindings or credentials.

The older traffic overlay's published defaults remain available for explicit test development, but starting the parked `support-bridge-traffic` project is not normal operation. Keep it stopped. For routine work, use the finite disposable check rather than resuming continuous generation.

## Choose timing and review mode

The generator supports these settings for explicitly isolated test development. The disposable check deliberately fixes one-second scripted activity and 50 ticks rather than inheriting an unbounded shell configuration.

| Setting | Generator default | Accepted values |
| --- | --- | --- |
| `BRIDGE_TRAFFIC_INTERVAL` | `5` | `1`, `5` or `random` |
| `BRIDGE_TRAFFIC_RANDOM_MIN` | `1` | Whole seconds, 1–60 |
| `BRIDGE_TRAFFIC_RANDOM_MAX` | `5` | Whole seconds, minimum through 60 |
| `BRIDGE_TRAFFIC_REVIEW` | `scripted` | `scripted` or `manual` |

The interval is a delay after each tick completes, not a fixed request rate. A tick can issue several requests or wait on existing work. Lock contention, API duration and persisted retry due times affect progress; the driver respects `next_run` rather than bypassing backoff. One-second, five-second and random delays simulate different arrival patterns, not measured customer traffic distributions.

Scripted mode leaves a conflict or state-change review visible for at least ten seconds after observing it, then submits a synthetic source choice or exact-plan approval through the normal API. Manual mode leaves those decisions pending for explicit test review. A conflict source choice never supplies state-change approval. The script does not edit pending plans, approvals or retry schedules to force progress.

## Scenarios and observation

The generator owns eight reusable pairs, `traffic-1` through `traffic-8`, with local GitHub IDs 91001–91008 and Zendesk IDs 95001–95008. The initializer's sample pair remains, giving nine linked pairs. Setup creates only marked synthetic records/links; restarts within a test preserve edits, baselines, plans and approvals. The generator refuses arbitrary existing records and live mode.

| Scenario | Workflow exercised |
| --- | --- |
| Support or engineering changes a summary | One-sided title propagation |
| Independent title and state edits | Field merge followed by whole-plan state review |
| Close or reopen an escalation | Exact-plan approval and freshness checks |
| Both sides change a summary differently | Conflict quarantine and explicit source choice |
| Injected write fails before committing | Persisted retry and due-time scheduling |
| Write commits but loses its response | Read-before-retry without blind resend |
| No new edit | Convergence checks without remote writes |

The check inspects these outcomes, audit evidence and the untouched sample. Test logs report structured progress without titles, tokens or raw vendor responses. Container health indicates loop progress, not convergence, verified human review or policy satisfaction.

Prometheus scrapes and Grafana refreshes every five seconds. Multiple one-second events can fall between samples. Outcome counters measure reconciliation observations, including no-op polls, rather than source edits or guaranteed effects. Use pair-state gauges and authenticated inspection together. Rate and histogram panels need multiple samples. [Observability semantics](OBSERVABILITY.md) explains these limits.

Fresh disposable Grafana uses `admin` and its generated test password, which smoke reads privately. This account is separate from the normal demo's persisted account. The [sign-in guide](DOCKER.md#sign-in-to-grafana) distinguishes bootstrap inputs from stored credentials. Tests must not reset a normal account or share its operator token to satisfy smoke.

## Architecture overview

The source driver edits only owned local records and installs record-targeted one-shot faults while holding the canonical application lock, then releases it before HTTP calls. A separate generator lock rejects duplicate drivers. The worker independently sweeps every five seconds. Local providers never call GitHub or Zendesk; explicit enablement and local-mode guards reject live configuration.

The driver receives only its disposable project's operator credential read-only. It calls ordinary sweep, inspection, conflict-resolution and exact-hash approval endpoints, with the normal governance and retry policies. The independent worker has no operator credential. Separate disposable volumes prevent simulated edits, approvals and faults from affecting parked or live state.

## Trade-offs

- Local providers exercise the real engine and HTTP boundary without accounts, but do not reproduce vendor permissions, triggers, latency or rate limits.
- Eight reusable pairs bound source data and repeat recovery cases. Audit and monitoring data still grow during a run, so routine checks use finite ticks.
- Scripted review keeps test progress moving but supplies no human decision. Manual mode supports deliberate evidence review and can leave much of the pool waiting.
- The driver and worker share one lock. Expected contention tests serialization; it does not demonstrate parallel business workers or production capacity.

## Known limitations

This is synthetic workflow verification, not a live integration or load benchmark. Fault injection covers particular before-write and lost-response cases, not arbitrary Docker, storage or vendor failures. The deterministic driver has broad **test** operator authority. Scripted attestations verify neither a human nor separation of duties or compliance. Never rely on AI in compliance. The [governance model](GOVERNANCE.md) and its missing identity, retention and approval-lifetime controls still apply.

Container tests, actual vendor runs and customer validation are separate evidence. Record executed results, not success inferred from configuration. [Docker operation](DOCKER.md#verification) documents the companion offline, lifecycle and no-vendor live-bootstrap checks.

## Verify the scenario

```bash
sh docker/check-traffic.sh
```

The finite check verifies all action markers, stored pair/audit state, actual monitoring connections, persistence through recreation, generator restart, duplicate-driver rejection and graceful termination. Its cleanup addresses only its own unique project. These results establish the tested local workload, not real-provider convergence or customer acceptance.

## Stop and resume

The normal workflow is a finite check that exits and cleans up its disposable resources. Do not resume the old parked traffic deployment for routine demonstration. Its volumes remain retained and separate; no automatic migration or merge occurs.

For a deliberately managed test development run, always retain that run's explicit unique `-p` value and both Compose files for every operation. Stop the generator before investigating pending work. Ordinary stop/down preserves that run's state. Volume removal is appropriate only for a confirmed disposable project owned by the check, never the live deployment or parked historical volumes.
