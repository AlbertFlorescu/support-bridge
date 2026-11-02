# Support Bridge

Support Bridge keeps a Zendesk escalation and its GitHub engineering issue connected. Support tags a ticket; the bridge creates the issue, reconciles their summaries and resolution state, and carries engineering comments and new internal support replies between the teams.

Our assumed customer is a B2B software team using both systems. The expected value is less copying and fewer stale handoffs. Customer discovery, time savings and production capacity remain unmeasured.

## Use the demonstration

1. Create an owned fictional Zendesk ticket, submit it Open and add `support_bridge_escalate`.
2. Wait for the enabled worker. It creates and links an open GitHub issue containing the initial description and Zendesk link.
3. Exchange a GitHub comment and a new internal Zendesk note. Enabled channels copy each once with source attribution. Reverse delivery must complete its initial scan first.
4. Close or reopen one source record. The bridge prepares the corresponding state-change plan and waits for approval.
5. In Grafana, choose **Review pending changes**, inspect the complete proposal, enter a reason, confirm review and click **Approve this exact change**. A later worker pass checks freshness, applies it and verifies agreement.

One-sided summary edits propagate automatically. Divergent edits to the same field stop for an operator source decision. The worker normally waits 60 seconds after each pass; provider calls, review and retries add delay.

| Surface | Local address | Access |
| --- | --- | --- |
| Grafana | [127.0.0.1:1990](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) | Persisted Grafana account |
| Browser review | [127.0.0.1:1988/review](http://127.0.0.1:1988/review) | Explicitly enabled Grafana administrator |
| Swagger | [127.0.0.1:1988/docs](http://127.0.0.1:1988/docs) | Public reference; submission disabled |
| Prometheus | [127.0.0.1:1989](http://127.0.0.1:1989/) | No additional login on loopback |

See [the CSV walkthrough and case catalogue](docs/SCENARIO.md) and [operation instructions](docs/OPERATIONS.md).

## Run

Use Ubuntu WSL at `/home/aflorescu/adobe` and Linux tools. Retain the existing `support-bridge-live-demo` project, both Compose files, private settings and volumes:

~~~bash
docker compose --env-file /absolute/private/live-demo.env \
  -p support-bridge-live-demo -f compose.yaml -f compose.live-demo.yaml \
  up --build -d --wait
~~~

New configurations leave effectful scheduling and browser review disabled. [Operations](docs/OPERATIONS.md) owns opt-ins, credentials, renewal, ports and verification. Restarting containers preserves accounts and state.

For isolated development checks:

~~~bash
composer install --no-interaction --no-plugins --no-scripts
composer autoload:check
composer style
php bin/check.php
~~~

The [CI guide](docs/CI.md) lists the complete local and GitHub Actions checks.

## Architecture overview

~~~mermaid
flowchart TB
    ZD["Zendesk"] <--> Workflow["Intake, reconciliation and conversation delivery"]
    Workflow <--> GH["GitHub"]
    Workflow <--> Store[("Business state and audit")]
    Store --> Read["Read-only monitoring API"]
    Read -->|"Current subjects and links"| Grafana["Grafana"]
    Read -->|"Numeric samples"| Prometheus["Prometheus"]
    Prometheus --> Grafana
    Grafana -->|"Open review"| Review["Operator review page"]
    Review -->|"Explicit exact-plan approval"| Workflow
~~~

Plain PHP, constructor dependencies, PSR-4 and PSR-12 keep adapters and policies explicit. CLI, HTTP, browser review and the worker share typed commands and queries. One SQLite store and canonical lock coordinate business work; telemetry uses separate storage. There is no framework, queue, runtime LLM or process-global database Singleton.

[Architecture](docs/ARCHITECTURE.md) explains durable plans, unknown outcomes, conversation ledgers and the authentication boundaries. The optional [C4 pack](docs/c4/README.md) adds four zoom levels and complete state lifecycles.

## Assumptions and trade-offs

- We support one repository, one Zendesk tenant, one host and a small explicitly opted-in link set.
- Summaries and binary open/closed state are shared. Zendesk Pending/Hold remain distinct native states when no status patch is needed.
- Every outbound resolution or reopening requires exact-plan approval. This is our chosen business rule: engineering completion can precede customer verification. A different agreed ownership model could permit automatic resolution.
- Two conversation directions reduce copying but transfer potentially sensitive text. Approved destination audiences and retention rules are deployment responsibilities.
- A synchronous worker and SQLite simplify recovery at the cost of serial work and unmeasured scale.
- Grafana's current table reads stored context directly; Prometheus supplies numeric history. Refreshing either initiates no vendor effect.

[Assumptions](docs/ASSUMPTIONS.md) records the operating limits. [Governance](docs/GOVERNANCE.md) defines authority.

## Known limitations

GitHub has a read/PATCH race; no distributed exactly-once guarantee is claimed. Description transfer is creation-only. Public Zendesk replies, attachment bytes, assignees, priority and later comment edits/deletions do not synchronize. Unknown creation/append outcomes can require investigation rather than a resend.

The operator principal is shared. Browser allowlisting does not establish an independent human reviewer or per-pair RBAC. Approval expiry/revocation, tamper-evident audit, tested disaster recovery and production ingress/capacity are absent. Monitoring shows stored observations, not continuously fresh vendor truth.

## AI use

AI assisted design exploration, implementation, test cases, review, documentation and fictional demo dialogue. We validate generated work against source, tests and observed behavior and retain responsibility for it.

The service calls no LLM. We deliberately use deterministic policies and explicit operator decisions for eligibility, merge, conflict selection, retry and approval. **Never rely on AI in compliance:** accountable people own obligations, data permissions and control acceptance.

[Documentation index](docs/README.md) · [Security maintenance](SECURITY.md)
