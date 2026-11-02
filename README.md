# Support Bridge

Support Bridge connects Zendesk support with GitHub engineering. Tag a ticket to create its issue, keep their summaries and resolution aligned, and exchange GitHub comments with new internal Zendesk notes.

We assume a B2B software team working across both systems. The intended value is less copying and fewer stale handoffs; customer savings and production capacity remain unmeasured.

## Try it

1. Create an owned fictional Zendesk ticket as **Open**. Add `support_bridge_escalate`.
2. Wait for the worker to create and link its GitHub issue, including the initial description.
3. Add a GitHub question, then answer with a **new internal Zendesk note** after reverse initialization. Public replies do not sync.
4. Open [Grafana](http://127.0.0.1:1990/d/supportbridge-overview?refresh=5s) to see the pair, clickable source links and message counts.
5. Use its workflow link to compare conflicts with **Use Zendesk / Use GitHub**, or review a close/reopen with **Approve this exact change**. Source selection and state approval are separate.

[The walkthrough and case catalogue](docs/SCENARIO.md) explain the examples. The worker waits 60 seconds after each pass; vendor calls, pagination and review add delay.

| Surface | Address | Access |
| --- | --- | --- |
| Grafana | [1990](http://127.0.0.1:1990/) | Persisted Grafana account |
| Review | [1988/review](http://127.0.0.1:1988/review) | Enabled Grafana administrator |
| Swagger | [1988/docs](http://127.0.0.1:1988/docs) | Read-only endpoint reference |
| Prometheus | [1989](http://127.0.0.1:1989/) | Loopback, no additional login |

Use Ubuntu WSL and the existing Compose project. [Operations](docs/OPERATIONS.md) owns startup, credentials and checks.

## Architecture overview

~~~mermaid
flowchart TB
    Zendesk <--> Bridge["Intake, fields and conversations"]
    Bridge <--> GitHub
    Bridge <--> State[("SQLite state and audit")]
    State --> Read["Monitoring queries"]
    Read -->|"Current table"| Grafana
    Read -->|"Numeric samples"| Prometheus
    Prometheus --> Grafana
    Grafana -->|"Open review"| Review
    Review -->|"Explicit source choice or approval"| Bridge
~~~

Framework-free PHP uses PSR-4/PSR-12, injected dependencies and typed synchronous commands/queries. One scoped business connection and canonical lock coordinate recovery. Monitoring queries construct no providers. See [architecture](docs/ARCHITECTURE.md) and the optional [C4/state pack](docs/c4/README.md).

## Trade-offs

One host, SQLite and a serial worker simplify durable recovery at the cost of unmeasured scale. Binary resolution loses native workflow detail. Conservative whole-plan approval can delay titles and messages; it lets support confirm resolution independently of engineering.

## Known limitations

Description transfer is creation-only. Attachments, public replies, assignees, priority and later message edits/deletions do not synchronize. Unknown creates/appends can require investigation. GitHub retains a read/PATCH race.

The operator identity is shared; there is no per-pair RBAC, independent approver, tested disaster recovery or production ingress. [Assumptions and authority](docs/ASSUMPTIONS.md) records the complete limits; [security maintenance](SECURITY.md) covers dependencies.

## AI use

AI assisted design, implementation, tests, review, documentation and fictional dialogue. We validate the work against code, tests and observations and retain responsibility.

There is no runtime LLM. Eligibility, merge and recovery use deterministic code; conflict choices and state approval remain explicit operator decisions. **Never rely on AI in compliance:** accountable owners decide obligations, disclosure and control acceptance.
