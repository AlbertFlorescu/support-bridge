# Prometheus and Grafana

The existing `support-bridge-live-demo` project runs one API, one Prometheus and one Grafana. It monitors three explicitly linked cases against real GitHub and Zendesk records. Its worker stays disabled by default; the dashboard observes stored results between deliberate manual sweeps.

| Service | Host address |
| --- | --- |
| API and Swagger | [127.0.0.1:8080/docs](http://127.0.0.1:8080/docs) |
| Prometheus | [127.0.0.1:9090](http://127.0.0.1:9090/) |
| Grafana | [127.0.0.1:3000](http://127.0.0.1:3000/d/supportbridge-overview) |

## Start and sign in

From the repository root, retain the private env file, existing project name and both Compose files:

```bash
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml up --build -d --wait
docker compose --env-file /absolute/private/live-demo.env -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml ps
```

Keep the private file's live host ports at 8080/9090/3000; shell overrides can take precedence, so confirm actual bindings. [Docker operation](../docs/DOCKER.md#choose-a-stack-and-its-ports) covers connection, credential and volume ownership. [LIVE_DEMO.md](../docs/LIVE_DEMO.md) covers private token import and deliberate checkpoints. Startup creates no source records or mappings and grants no synchronization or approval authority.

Use the retained Grafana account. Only a fresh database initializes `admin` with the generated bootstrap password. Recreating containers preserves the current account; the bootstrap file may no longer match it after rotation. Follow [Grafana sign-in](../docs/DOCKER.md#sign-in-to-grafana) and verify actual login separately from health or provisioning. Use `127.0.0.1` consistently. Project-specific cookies avoid session collisions; applying a changed cookie name requires a fresh login without resetting data.

## Five dashboard panels

| Panel | What it establishes |
| --- | --- |
| Metrics connection | Prometheus can read the exporter, not that vendors or all cases are healthy. |
| Actual linked pairs | All stored mappings across the deployment, including any outside the fixed three-case table. This is not a constant or an allowlist count. |
| Operator attention | Stored approval waits, conflicts and dead work across all mappings. Inspect the evidence before deciding. |
| The three demonstration cases | Stored status, last verified baseline resolution, pending-plan flag, attempts and last success for each fixed case. |
| Observed case workflow history | Scrape-sampled case status changes; it can miss transitions between samples and does not reconstruct a complete audit. |

| Dashboard case | Application pair ID |
| --- | --- |
| CSV export | `csv-export` |
| Webhook delivery | `webhook-retry` |
| SSO sign-in | `sso-conflict` |

Case names are fixed safe labels. The identifiers select stored links and do not predict their current status. Arbitrary pair/vendor IDs, titles, raw errors, rationales and credentials are excluded from labels. Other records never create dynamic case labels.

Displayed status values are `0 Not linked`, `1 Ready for sweep`, `2 Synced`, `3 Awaiting approval`, `4 Conflict`, `5 Retry scheduled`, `6 Needs intervention` and `7 Unknown state`. Baseline resolution is `0 No verified baseline`, `1 Open` or `2 Resolved` (canonical closed). Missing or unknown evidence remains visible; it must not be displayed as successful convergence. A resolved baseline was verified at that recorded stage; it does not establish current vendor state or customer confirmation.

Prometheus scrapes every five seconds; Grafana refreshes independently. Scrapes read local stored state and make no vendor calls. A recent sample can therefore contain an older business observation. Manual sweeps, current authenticated inspection and authorized provider reads supply different evidence. Monitoring cannot choose a source, approve a plan or establish compliance.

## Metric and credential boundaries

Prometheus scrapes `api:8080` with a dedicated read-only credential; Grafana queries `prometheus:9090`. Neither receives vendor credentials. Diagnostic aggregate counters and histograms remain queryable in Prometheus, although the operating dashboard focuses on the three cases. Business correctness and audit do not depend on telemetry delivery. See [metric contracts and failure semantics](../docs/OBSERVABILITY.md).

Named volumes preserve metric history, Grafana data and credentials. Ordinary stop/down retains them. Keep old local-deployment volumes separate; never merge or remove them as routine cleanup. Plaintext credential files rely on ownership, mounts and Docker access controls rather than an encrypted secret manager.

## Rules and verification

Three example rules cover failed scraping, operator attention and pending recovery plans. Quiet time between manual sweeps is expected, so it is not an alert condition. No Alertmanager, recipient or external notification delivery is configured. Thresholds are examples rather than agreed service objectives; Grafana alerting is disabled.

Use [Docker verification](../docs/DOCKER.md#verification) for isolated application/lifecycle checks and read-only service smoke. Smoke assumes the unchanged Grafana bootstrap account; it does not validate a rotated account, and must not reset one to pass. Healthy containers alone prove neither an authenticated scrape nor a datasource query. Record executed results in [REVIEW.md](../docs/REVIEW.md), separately from customer or vendor validation. No native monitoring installation or continuous load generator is maintained.
