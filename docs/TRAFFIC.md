# Continuous synthetic traffic

The traffic stack simulates support and engineering work on local ticket/issue pairs. A separate container edits synthetic source records and sends authenticated HTTP requests to the application. The normal engine reconciles them; Prometheus and Grafana show the resulting outcomes and pending work.

## Start

From the repository root, with Docker Engine and Compose 2.24.4 or later:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
```

Both files and the explicit `-p support-bridge-traffic` flag are required. The flag takes precedence over `COMPOSE_PROJECT_NAME` in the shell or `.env`, keeping the traffic project's volumes and network separate from the ordinary stack. The overlay uses `!override` to replace host ports. Keep this command prefix for every traffic operation; changing or omitting it can address another project's data.

| Service | Host address or role |
| --- | --- |
| API and Swagger | [127.0.0.1:8081/docs](http://127.0.0.1:8081/docs) |
| Traffic | Generates source edits and API requests; no published port |
| Worker | Reconciles independently every five seconds |
| Prometheus | [127.0.0.1:9091](http://127.0.0.1:9091/) |
| Grafana | [127.0.0.1:3001](http://127.0.0.1:3001/d/supportbridge-overview) |

The project has its own application data, credentials, monitoring data and network. It can run beside the ordinary stack. Host ports remain on loopback; `BRIDGE_TRAFFIC_HTTP_PORT`, `BRIDGE_TRAFFIC_PROMETHEUS_PORT` and `BRIDGE_TRAFFIC_GRAFANA_PORT` select alternatives if needed.

Grafana login is `admin`. Read its generated password locally with the command below; keep the value out of logs and source:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml exec grafana cat /run/secrets/grafana/password
```

## Choose timing and review mode

Set variables in the shell that runs Compose, then run the start command again to recreate the traffic container with those settings.

| Setting | Default | Accepted values |
| --- | --- | --- |
| `BRIDGE_TRAFFIC_INTERVAL` | `5` | `1`, `5` or `random` |
| `BRIDGE_TRAFFIC_RANDOM_MIN` | `1` | Whole seconds, 1–60 |
| `BRIDGE_TRAFFIC_RANDOM_MAX` | `5` | Whole seconds, minimum through 60 |
| `BRIDGE_TRAFFIC_REVIEW` | `scripted` | `scripted` or `manual` |

For one-second activity in bash:

```bash
export BRIDGE_TRAFFIC_INTERVAL=1
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
```

For random delays in PowerShell:

```powershell
$env:BRIDGE_TRAFFIC_INTERVAL = 'random'
$env:BRIDGE_TRAFFIC_RANDOM_MIN = '1'
$env:BRIDGE_TRAFFIC_RANDOM_MAX = '5'
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml up --build -d --wait
```

Use `5` for fixed five-second activity. The interval is a delay after each tick completes, not a fixed request rate. A tick can issue several HTTP requests or wait on existing work. Lock contention, API duration and persisted retry times also affect progress; the generator respects `next_run` rather than bypassing backoff.

In `scripted` mode, pending conflicts and state-change reviews remain visible for at least ten seconds before the driver submits a synthetic source choice or exact-plan approval. This keeps the scenario cycling through the normal API. In `manual` mode, the driver leaves those decisions pending. Change the variable to `manual` and recreate the traffic container to practice reviewing them yourself.

## Scenarios and observation

The generator uses eight reusable pairs, `traffic-1` through `traffic-8`, with local GitHub IDs 91001–91008 and Zendesk IDs 95001–95008. The initializer's sample pair remains present. First setup creates the owned records and links; later starts preserve their edits, baselines, pending plans and approvals. The generator will not adopt arbitrary existing records.

| Scenario | Workflow exercised |
| --- | --- |
| Support or engineering changes a summary | One-sided title propagation |
| Independent title and state edits | Field merge followed by whole-plan state review |
| Close or reopen an escalation | Exact-plan approval and freshness checks |
| Both sides change a summary differently | Conflict quarantine and explicit source choice |
| An injected write fails before committing | Persisted retry and due-time scheduling |
| A write commits but loses its response | Read-before-retry recovery without blind resend |
| No new edit | Convergence checks without remote writes |

Follow traffic logs and inspect stored work:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml logs --follow --tail=50 traffic
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml run --rm cli status
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml run --rm cli inspect traffic-1
```

For manual review, inspect the pending pair and its current evidence before selecting a source or approving its current hash:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml run --rm cli resolve traffic-1 github
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml run --rm cli inspect traffic-1
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml run --rm cli approve traffic-1 PLAN_HASH 'Reviewed this synthetic plan'
```

Run only the command appropriate to the stored status; source selection does not grant approval. Replace `PLAN_HASH` with the inspected `governance.plan_hash`. The independent worker applies eligible work on a later sweep.

Prometheus scrapes every five seconds and the dashboard refreshes every five seconds, with a fifteen-minute default view. Several one-second events can fall between scrapes. Outcome counters measure reconciliation observations, including no-op polls; they do not count source edits or prove every pair is healthy. Use pair-state gauges, logs and inspection together. Rate and histogram panels need multiple samples.

Traffic logs report structured progress without titles, tokens or raw vendor responses. Container health reports loop progress, not convergence, human review or policy satisfaction.

## Architecture overview

The driver edits owned local provider records and installs one-shot faults while holding the canonical application lock. It releases that lock before HTTP calls. A separate generator lock prevents duplicate traffic drivers. Requests go only to the internal application API; the local providers make no GitHub or Zendesk requests. Explicit enablement and a local-mode guard prevent the driver from running against live providers.

The traffic container receives its project's operator credential read-only. It uses the ordinary API for sweeps, inspection, conflict resolution and exact-hash approval. It does not bypass governance or change retry policy. The worker remains a separate process with no operator credential. Injected faults target the selected synthetic record. Separate application volumes keep simulated edits, approvals and faults away from the ordinary stack.

## Trade-offs

- Reusing the local providers exercises the real engine and HTTP surface without accounts. It does not reproduce vendor network latency, permissions, triggers or rate limits.
- A fixed eight-pair pool keeps source data bounded and produces repeated recovery cases. Audit history and monitoring storage still grow during long runs.
- Scripted review keeps activity moving but supplies no human decision. Manual mode is useful for reviewing evidence and can leave much of the pool waiting.
- The driver and independent worker share one lock. Contention is expected; this setup does not add parallel business workers or measure production capacity.

## Known limitations

These scenarios are synthetic workflow traffic, not a live-provider integration or load benchmark. Their cadence and distribution are demonstration choices. Fault injection covers specific before-write and lost-response cases, not arbitrary failures of Docker, storage or a vendor.

The deterministic driver has broad local operator authority. Its scripted attestations do not verify a human, separation of duties or compliance. **Never rely on AI in compliance.** The [governance model](GOVERNANCE.md) and its missing identity, retention and approval-lifetime controls still apply.

The container and workflow checks described in [Docker operation](DOCKER.md) are separate from live-account, customer and capacity validation. Record actual test and stack results when verifying a change; configuration alone is not evidence that the workload or monitoring works.

## Verify the scenario

With a shell that can run Docker, execute the disposable traffic check:

```bash
sh docker/check-traffic.sh
```

It creates a uniquely named project, generates a bounded workload through the API, checks scenario and audit evidence, runs the monitoring smoke check, and checks restart and graceful termination. Its cleanup removes only that disposable project's volumes. It uses host ports 18081, 19091 and 13001 by default; `BRIDGE_TRAFFIC_CHECK_HTTP_PORT`, `BRIDGE_TRAFFIC_CHECK_PROMETHEUS_PORT` and `BRIDGE_TRAFFIC_CHECK_GRAFANA_PORT` select alternatives.

## Stop and resume

Stop only generation while leaving API, worker and monitoring available:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml stop traffic
```

Stop the whole traffic stack while keeping its data and credentials:

```bash
docker compose -p support-bridge-traffic -f compose.yaml -f compose.traffic.yaml down
```

Run the start command to resume. Do not add `--volumes` as routine cleanup: existing pending work and credentials must survive. Keep the explicit project flag and both files when stopping or resuming this stack.
